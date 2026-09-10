<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/auth_guard.php';
// Penjagaan berbasis kapabilitas menggantikan pola blacklist lama.
// Selama settings.rbac_enforce masih '0', pelanggaran hanya DICATAT ke
// Audit Trail sebagai 'RBAC_AUDIT' dan akses tetap diizinkan.
require_capability('tracer.kelola');
require_once '../includes/csrf.php';

// Admin check
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'alumni') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

// Dipanggil lewat fetch() ber-body JSON, sehingga token dikirim pada header
// X-CSRF-Token oleh pages/admin_tracer_config.php.
validate_csrf_request(true);

// Support JSON inputs or standard POST
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$id = isset($input['id']) ? (int)$input['id'] : 0;
$is_active = isset($input['is_active']) ? (int)$input['is_active'] : 0;

if ($id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'ID pertanyaan tidak valid.']);
    exit();
}

try {
    // Check if question exists
    $stmt_check = $pdo->prepare("SELECT question_text FROM tracer_questions WHERE id = ?");
    $stmt_check->execute([$id]);
    $q_text = $stmt_check->fetchColumn();
    
    if (!$q_text) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Pertanyaan tidak ditemukan.']);
        exit();
    }

    // Update status
    $stmt = $pdo->prepare("UPDATE tracer_questions SET is_active = ? WHERE id = ?");
    $stmt->execute([$is_active, $id]);

    $status_label = $is_active ? 'mengaktifkan' : 'menonaktifkan';
    log_activity('TOGGLE_TRACER_QUESTION', "User " . $_SESSION['user_id'] . " $status_label pertanyaan: \"$q_text\"");

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'is_active' => $is_active]);
    exit();
} catch (PDOException $e) {
    error_log("Admin Tracer Toggle Error: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan basis data saat memperbarui status.']);
    exit();
}
?>
