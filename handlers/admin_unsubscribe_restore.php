<?php
/**
 * Kembalikan alamat yang berhenti dikirimi.
 *
 * ── Mengapa harus bisa dibatalkan ──────────────────────────────────────
 * Penandaan bounce berjalan otomatis: alamat yang gagal beberapa kali
 * berturut-turut berhenti dikirimi. Itu perlu, karena rasio bounce yang
 * tinggi merusak reputasi pengirim sampai e-mail yang sah pun masuk folder
 * spam.
 *
 * Tetapi kegagalan pengiriman TIDAK selalu berarti alamatnya mati. Kotak
 * masuk yang penuh, server penerima yang sedang bermasalah, atau SMTP kita
 * sendiri yang tersendat menghasilkan kegagalan yang sama persis. Tanpa
 * cara mengembalikan, sistem akan diam-diam memutus hubungan dengan alumni
 * yang alamatnya sebenarnya baik-baik saja — dan tidak ada yang tahu.
 *
 * Hanya penandaan OTOMATIS yang dapat dikembalikan di sini. Alumni yang
 * memilih berhenti sendiri tidak boleh dikembalikan oleh admin.
 */

require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_capability('broadcast.kirim');
require_once __DIR__ . '/../includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php?page=admin_broadcast_status');
    exit;
}
validate_csrf();

$email = trim((string)($_POST['email'] ?? ''));
if ($email === '') {
    header('Location: ../index.php?page=admin_broadcast_status&error=kosong');
    exit;
}

// Hanya baris yang ditandai otomatis. Alumni yang menekan "berhenti
// berlangganan" sendiri sudah menyatakan kehendaknya, dan admin tidak
// berhak membatalkannya dari sini.
$cek = $pdo->prepare("SELECT reason FROM unsubscribes WHERE email = ?");
$cek->execute([$email]);
$reason = $cek->fetchColumn();

if ($reason === false) {
    header('Location: ../index.php?page=admin_broadcast_status&error=tidak_ada');
    exit;
}
if (stripos((string)$reason, 'bounce') !== 0) {
    header('Location: ../index.php?page=admin_broadcast_status&error=dipilih_sendiri');
    exit;
}

$pdo->prepare("DELETE FROM unsubscribes WHERE email = ?")->execute([$email]);

// Kegagalan lama dibersihkan juga. Tanpa ini, hitungan bounce yang sudah
// melewati ambang akan langsung menandai alamat itu lagi pada jalan cron
// berikutnya, dan tombol "Kembalikan" tampak tidak berfungsi.
$pdo->prepare("DELETE FROM email_queue WHERE to_email = ? AND status = 'failed'")
    ->execute([$email]);

log_activity('RESTORE_UNSUBSCRIBE', "Alamat $email dikembalikan (sebelumnya: $reason)");

header('Location: ../index.php?page=admin_broadcast_status&unsub=kembali');
exit;
