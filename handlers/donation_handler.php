<?php
/**
 * Pendaftaran donasi dan pembuatan tagihannya.
 *
 * Perbedaan dari versi sebelumnya:
 *   - Nominal, ID kampanye, dan nama divalidasi. `$amount < 10000` dulu
 *     membandingkan STRING di PHP 8, sehingga 'abc' lolos.
 *   - Kampanye harus aktif dan belum lewat tanggal akhirnya.
 *   - E-mail pelanggan diambil dari users.email. Dulu dari
 *     $_SESSION['user_email'] yang hanya diisi login Google, sehingga
 *     donatur lain terkirim ke Midtrans sebagai 'alumni@fkums.com'.
 *   - reset_rate_limit() tidak lagi dipanggil saat sukses — dulu batas
 *     10 per 15 menit hanya membatasi KEGAGALAN.
 *   - Biaya dihitung payment_quote(), sama dengan pratinjau.
 *   - Balasan menyebut cara membuka pembayaran: popup Snap (Midtrans) atau
 *     pengalihan ke halaman bayar (Flip). `snap_token` tetap dikirim untuk
 *     Midtrans supaya halaman versi lama tetap berfungsi selama upload.
 */
require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/payment/service.php';

header('Content-Type: application/json; charset=utf-8');

function donasi_gagal($pesan, $kode = 422)
{
    http_response_code($kode);
    echo json_encode(['error' => $pesan]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    donasi_gagal('Permintaan tidak sah.', 405);
}

validate_csrf();

if (empty($_SESSION['user_id'])) {
    donasi_gagal('Sesi berakhir. Silakan masuk kembali.', 401);
}

check_rate_limit('DONATION', 10, 15);

$user_id     = $_SESSION['user_id'];
$campaign_id = filter_var($_POST['campaign_id'] ?? null, FILTER_VALIDATE_INT);
$amount      = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_INT);
$donor_name  = trim((string)($_POST['donor_name'] ?? ''));
$message     = trim((string)($_POST['message'] ?? ''));

if (!$campaign_id) {
    donasi_gagal('Program donasi tidak dikenal.');
}
if ($amount === false || $amount < 10000) {
    donasi_gagal('Minimal donasi adalah Rp 10.000');
}
if ($amount > 1000000000) {
    donasi_gagal('Nominal donasi tidak wajar.');
}
$donor_name = mb_substr($donor_name !== '' ? $donor_name : ($_SESSION['user_name'] ?? 'Hamba Allah'), 0, 100);
$message = mb_substr($message, 0, 1000);

$camp = $pdo->prepare("SELECT id, title, is_active, end_date FROM donation_campaigns WHERE id = ?");
$camp->execute([$campaign_id]);
$campaign = $camp->fetch();
if (!$campaign || !$campaign->is_active || ($campaign->end_date && $campaign->end_date < date('Y-m-d'))) {
    donasi_gagal('Program donasi ini sudah tidak menerima donasi.');
}

$quote = payment_quote('donasi', ['amount' => $amount]);
if (!$quote['ok']) {
    error_log('Donasi: rincian biaya ditolak: ' . $quote['error']);
    donasi_gagal('Pengaturan biaya pembayaran sedang bermasalah. Silakan coba beberapa saat lagi.', 503);
}

try {
    $ref = payment_unique_ref('DON-' . strtoupper(uniqid()));
    $pdo->prepare("INSERT INTO donations (campaign_id, user_id, donor_name, amount, message, status, midtrans_order_id)
                   VALUES (?, ?, ?, ?, ?, 'pending', ?)")
        ->execute([$campaign_id, $user_id, $donor_name, $amount, $message, $ref]);
    $donation_id = (int)$pdo->lastInsertId();
} catch (PDOException $e) {
    error_log('Donation Handler Error: ' . $e->getMessage());
    donasi_gagal('Terjadi kesalahan sistem saat memproses donasi.', 500);
}

$pengguna = $pdo->prepare("SELECT email, phone, address FROM users WHERE id = ?");
$pengguna->execute([$user_id]);
$u = $pengguna->fetch();

$tagihan = payment_create('donasi', $donation_id, $ref, $quote, [
    'name'    => $donor_name,
    'email'   => $u->email ?? '',
    'phone'   => $u->phone ?? '',
    'address' => $u->address ?? '',
], $user_id);

if (!$tagihan['ok']) {
    donasi_gagal('Gagal membuat tagihan pembayaran. Silakan coba lagi beberapa saat lagi.', 502);
}

$campaign_title = $campaign->title ?: 'Program Umum';

add_notification($user_id, 'Donasi Terdaftar (Pending)',
    'Registrasi donasi Anda sebesar Rp ' . number_format($amount, 0, ',', '.') . ' untuk program "'
    . htmlspecialchars($campaign_title) . '" telah terdaftar. Silakan selesaikan pembayaran.',
    'info', 'index.php?page=donasi');

notify_roles(['super_admin', 'keuangan'], 'Pendaftaran Donasi Baru (Pending)',
    'Donatur ' . htmlspecialchars($donor_name) . ' mendaftarkan donasi sebesar Rp ' . number_format($amount, 0, ',', '.')
    . ' untuk program "' . htmlspecialchars($campaign_title) . '". Status saat ini: Menunggu Pembayaran.',
    'info', 'index.php?page=admin_donasi');

if (!empty($u->email)) {
    send_donation_invoice($u->email, $donor_name, $ref, $amount, $campaign_title,
        payment_gateway_label($tagihan['txn']->gateway) . ' / Pembayaran Online');
}

$aksi = payment_gateway($tagihan['txn']->gateway)->frontendAction($tagihan['txn']);
echo json_encode([
    'action'     => $aksi,
    'ref'        => $tagihan['txn']->merchant_ref,
    'snap_token' => $aksi['type'] === 'snap' ? $aksi['token'] : null,
    'total'      => $quote['total'],
]);
