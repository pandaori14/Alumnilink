<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once __DIR__ . '/../includes/auth_guard.php';
// Penjagaan berbasis kapabilitas menggantikan pola blacklist lama.
// Selama settings.rbac_enforce masih '0', pelanggaran hanya DICATAT ke
// Audit Trail sebagai 'RBAC_AUDIT' dan akses tetap diizinkan.
require_capability('tracer.kelola');
require_once '../includes/csrf.php';

// Admin check
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'alumni') {
    error_forbidden();
}

validate_csrf();

$action = $_GET['action'] ?? '';

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? null;
    $text = $_POST['question_text'];
    $type = $_POST['question_type'];
    $order = (int)$_POST['order_no'];
    $required = isset($_POST['is_required']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $mapping_key = !empty($_POST['mapping_key']) ? $_POST['mapping_key'] : null;
    
    // Dependencies
    $depends_on_id = !empty($_POST['depends_on_question_id']) ? (int)$_POST['depends_on_question_id'] : null;
    $depends_on_val = null;
    if (!empty($_POST['depends_on_option_value'])) {
        $depends_on_val = is_array($_POST['depends_on_option_value']) ? json_encode($_POST['depends_on_option_value']) : $_POST['depends_on_option_value'];
    }
    
    $options = null;
    if ($type === 'radio' || $type === 'select' || $type === 'checkbox') {
        $opt_array = explode(',', $_POST['options']);
        $opt_array = array_map('trim', $opt_array);
        $options = json_encode($opt_array);
    }

    try {
        $pdo->beginTransaction();

        // If mapping key is set, enforce uniqueness: clear it on any other question
        if ($mapping_key) {
            if ($id) {
                $pdo->prepare("UPDATE tracer_questions SET mapping_key = NULL WHERE mapping_key = ? AND id != ?")->execute([$mapping_key, $id]);
            } else {
                $pdo->prepare("UPDATE tracer_questions SET mapping_key = NULL WHERE mapping_key = ?")->execute([$mapping_key]);
            }
        }

        if ($id) {
            // Get old order
            $stmt = $pdo->prepare("SELECT order_no FROM tracer_questions WHERE id = ?");
            $stmt->execute([$id]);
            $old_order = $stmt->fetchColumn();

            if ($old_order != $order) {
                // If order changed, shift others
                if ($order < $old_order) {
                    $pdo->prepare("UPDATE tracer_questions SET order_no = order_no + 1 WHERE order_no >= ? AND order_no < ? AND id != ?")->execute([$order, $old_order, $id]);
                } else {
                    $pdo->prepare("UPDATE tracer_questions SET order_no = order_no - 1 WHERE order_no <= ? AND order_no > ? AND id != ?")->execute([$order, $old_order, $id]);
                }
            }

            // Update this question
            $stmt = $pdo->prepare("UPDATE tracer_questions SET question_text = ?, question_type = ?, options = ?, is_required = ?, order_no = ?, depends_on_question_id = ?, depends_on_option_value = ?, is_active = ?, mapping_key = ? WHERE id = ?");
            $stmt->execute([$text, $type, $options, $required, $order, $depends_on_id, $depends_on_val, $is_active, $mapping_key, $id]);
        } else {
            // New question: Shift others up if needed
            $pdo->prepare("UPDATE tracer_questions SET order_no = order_no + 1 WHERE order_no >= ?")->execute([$order]);
            
            // Insert
            $stmt = $pdo->prepare("INSERT INTO tracer_questions (question_text, question_type, options, is_required, order_no, depends_on_question_id, depends_on_option_value, is_active, mapping_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$text, $type, $options, $required, $order, $depends_on_id, $depends_on_val, $is_active, $mapping_key]);
        }

        $pdo->commit();
        header("Location: ../index.php?page=admin_tracer_config&success=1");
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Admin Tracer Save Error: " . $e->getMessage());
        error_system('Terjadi kesalahan sistem saat menyimpan konfigurasi tracer. Silakan hubungi administrator.');
    }
}

if ($action === 'delete') {
    $id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM tracer_questions WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: ../index.php?page=admin_tracer_config&success=deleted");
        exit();
    } catch (PDOException $e) {
        error_log("Admin Tracer Delete Error: " . $e->getMessage());
        error_system('Terjadi kesalahan sistem saat menghapus pertanyaan tracer. Silakan hubungi administrator.');
    }
}
?>
