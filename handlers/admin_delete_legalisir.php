<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once __DIR__ . '/../includes/auth_guard.php';
// Penjagaan berbasis kapabilitas menggantikan pola blacklist lama.
// Selama settings.rbac_enforce masih '0', pelanggaran hanya DICATAT ke
// Audit Trail sebagai 'RBAC_AUDIT' dan akses tetap diizinkan.
require_capability('legalisir.hapus');
require_once '../includes/csrf.php';

// Admin check (Only allow administrators to delete requests)
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'alumni') {
    error_forbidden();
}

// Penghapusan bersifat permanen dan dipicu lewat tautan GET, sehingga tanpa
// token siapa pun dapat memancing admin membuka URL berisi ?id=... dan data
// pengajuan ikut terhapus. Token disisipkan pada tautan di
// pages/admin_legalisir.php (baris 183 dan 315).
validate_csrf_request();

$id = trim($_GET['id'] ?? '');

if ($id) {
    try {
        // Execute delete query
        $stmt = $pdo->prepare("DELETE FROM legalisir_requests WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            header("Location: ../index.php?page=admin_legalisir&success=deleted&t=" . time());
        } else {
            header("Location: ../index.php?page=admin_legalisir&error=delete_failed&t=" . time());
        }
        exit();
    } catch (PDOException $e) {
        error_log("Admin Delete Legalisir Error: " . $e->getMessage());
        error_system('Terjadi kesalahan sistem saat menghapus pengajuan legalisir. Silakan hubungi administrator.');
    }
} else {
    header("Location: ../index.php?page=admin_legalisir");
    exit();
}
?>
