<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once __DIR__ . '/../includes/auth_guard.php';
// Penjagaan berbasis kapabilitas menggantikan pola blacklist lama.
// Selama settings.rbac_enforce masih '0', pelanggaran hanya DICATAT ke
// Audit Trail sebagai 'RBAC_AUDIT' dan akses tetap diizinkan.
require_capability('pembayaran.verifikasi');
require_once '../includes/csrf.php';

// Admin check
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'alumni') {
    error_forbidden();
}

// Menandai pembayaran sebagai LUNAS adalah aksi keuangan, namun dipicu lewat
// formulir GET di pages/admin_legalisir.php:433. csrf_field() pada formulir
// tersebut ikut terserialisasi ke query string, lalu diperiksa di sini.
validate_csrf_request();

$id = trim($_GET['id'] ?? '');

if ($id) {
    try {
        // Fetch request info for notifications
        $req_stmt = $pdo->prepare("SELECT user_id, amount, documents FROM legalisir_requests WHERE id = ?");
        $req_stmt->execute([$id]);
        $req = $req_stmt->fetch();

        // Update payment status to settlement, method to cash, and status to processing
        $stmt = $pdo->prepare("UPDATE legalisir_requests SET payment_status = 'settlement', payment_method = 'cash', status = 'processing' WHERE id = ?");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0 && $req) {
            $user_id = $req->user_id;
            $amount = $req->amount;
            $docs = json_decode($req->documents, true) ?: [];
            $doc_type = !empty($docs[0]['type']) ? $docs[0]['type'] : 'Dokumen';
            $doc_desc = $doc_type . (count($docs) > 1 ? ' (' . count($docs) . ' berkas)' : '');

            // Notify alumnus
            add_notification(
                $user_id,
                'Pembayaran Tunai Terverifikasi',
                'Pembayaran tunai sebesar Rp ' . number_format($amount, 0, ',', '.') . ' untuk pengajuan ' . htmlspecialchars($doc_desc) . ' (' . $id . ') telah diverifikasi secara manual oleh admin. Pengajuan Anda sedang diproses.',
                'success',
                'index.php?page=legalisir_detail&id=' . $id
            );

            // Notify admins
            notify_roles(
                ['super_admin', 'admin_legalisir'],
                'Pembayaran Cash Terverifikasi',
                'Pembayaran tunai untuk legalisir ' . $id . ' sebesar Rp ' . number_format($amount, 0, ',', '.') . ' telah diverifikasi secara manual.',
                'success',
                'index.php?page=admin_legalisir'
            );

            header("Location: ../index.php?page=admin_legalisir&success=cash_verified&t=" . time());
        } else {
            header("Location: ../index.php?page=admin_legalisir&error=verify_failed&t=" . time());
        }
        exit();
    } catch (PDOException $e) {
        error_log("Admin Verify Cash Error: " . $e->getMessage());
        error_system('Terjadi kesalahan sistem saat verifikasi pembayaran tunai. Silakan hubungi administrator.');
    }
} else {
    header("Location: ../index.php?page=admin_legalisir");
    exit();
}
?>
