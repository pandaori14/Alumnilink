<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once __DIR__ . '/../includes/auth_guard.php';
// Penjagaan berbasis kapabilitas menggantikan pola blacklist lama.
// Selama settings.rbac_enforce masih '0', pelanggaran hanya DICATAT ke
// Audit Trail sebagai 'RBAC_AUDIT' dan akses tetap diizinkan.
require_capability('legalisir.kelola');
require_once '../includes/csrf.php';
require_once '../includes/mailer.php';
require_once __DIR__ . '/../includes/legalisir_lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic Admin Check
    if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'alumni') {
        error_forbidden();
    }

    // Token disisipkan pada ketiga formulir pemanggil di
    // pages/admin_legalisir.php (ubah status inline, panel mobile, dan modal
    // nomor resi). Handler ini mengubah status pengajuan serta mengirim
    // e-mail ke alumni, sehingga wajib terlindung dari pemicuan lintas situs.
    validate_csrf();

    $id = $_POST['id'];

    try {
        if (isset($_POST['status'])) {
            // Seluruh rangkaian efek (token verifikasi sekali-pakai,
            // notifikasi, alasan penolakan, penyusunan e-mail) berada di
            // includes/legalisir_lib.php dan dipakai bersama handler massal,
            // supaya kedua jalur tidak pernah menyimpang.
            $hasil = apply_legalisir_status($pdo, $id, $_POST['status'], $_POST['rejection_reason'] ?? '');

            if (!$hasil['ok']) {
                header("Location: ../index.php?page=admin_legalisir&error=" .
                    ($_POST['status'] === 'rejected' ? 'alasan_wajib' : 'status'));
                exit();
            }

            log_activity('UPDATE_LEGALISIR',
                "Pengajuan $id: {$hasil['status_lama']} -> {$_POST['status']}");

            // E-mail dikirim setelah perubahan basis data selesai.
            send_legalisir_emails([$hasil['email']]);
        }

        if (isset($_POST['tracking_number'])) {
            $tracking = $_POST['tracking_number'];
            $u = $pdo->prepare("SELECT user_id FROM legalisir_requests WHERE id = ?");
            $u->execute([$id]);
            $user_id = $u->fetchColumn();
            $stmt = $pdo->prepare("UPDATE legalisir_requests SET tracking_number = ? WHERE id = ?");
            $stmt->execute([$tracking, $id]);

            // Notify alumnus about tracking number
            if ($user_id && !empty($tracking)) {
                add_notification(
                    $user_id,
                    'Nomor Resi Pengiriman Ditambahkan',
                    'Dokumen legalisir Anda (' . $id . ') telah diserahkan ke kurir dengan nomor resi: ' . htmlspecialchars($tracking) . '.',
                    'success',
                    'index.php?page=legalisir_detail&id=' . $id
                );
            }
        }

        header("Location: ../index.php?page=admin_legalisir&success=updated");
        exit();
    } catch (PDOException $e) {
        error_log("Admin Update Legalisir Error: " . $e->getMessage());
        error_system('Terjadi kesalahan sistem saat memperbarui legalisir. Silakan hubungi administrator.');
    }
} else {
    header("Location: ../index.php?page=admin_legalisir");
    exit();
}
?>
