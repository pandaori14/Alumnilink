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
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Dipanggil lewat fetch() dengan Content-Type: application/json, sehingga
// body tidak pernah mengisi $_POST. Token dikirim pada header X-CSRF-Token
// oleh pages/admin_tracer_config.php.
validate_csrf_request(true);

// Get JSON input
// Catatan: versi sebelumnya membaca 'php://output' (aliran KELUARAN, selalu
// kosong untuk permintaan masuk) dan hanya berfungsi karena operator ??
// meneruskan ke pembacaan 'php://input' yang benar.
$input = json_decode(file_get_contents('php://input'), true);

if (isset($input['order']) && is_array($input['order'])) {
    try {
        $pdo->beginTransaction();
        
        $order = 1;
        foreach ($input['order'] as $id) {
            $stmt = $pdo->prepare("UPDATE tracer_questions SET order_no = ? WHERE id = ?");
            $stmt->execute([$order, $id]);
            $order++;
        }
        
        $pdo->commit();
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Admin Tracer Reorder Error: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem saat mengurutkan pertanyaan.']);
    }
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
}
exit();
