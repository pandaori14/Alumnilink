<?php
/**
 * CRON: rekonsiliasi pembayaran.
 *
 * Mengambil ulang status tagihan yang masih 'pending' langsung dari gateway.
 *
 * ── Mengapa perlu, padahal ada callback ────────────────────────────────
 * Callback bisa terlambat, tertahan firewall atau Cloudflare, atau hilang
 * sama sekali — produksi tidak pernah menerima satu pun notifikasi Midtrans.
 * Flip juga tidak menjamin callback untuk setiap perubahan status. Tanpa
 * pekerjaan ini, alumni yang sudah membayar tetap melihat "Menunggu
 * Pembayaran" sampai ada yang menekan "Cek status" di panel.
 *
 * Yang diperiksa: tagihan pending berumur lebih dari 20 menit atau sudah
 * lewat masa berlakunya, dan belum dicek dalam 10 menit terakhir. Paling
 * banyak 50 per jalan, dan berhenti setelah ~90 detik supaya tidak
 * melampaui batas waktu permintaan HTTP.
 *
 * Tagihan yang tidak pernah dibayar dan sudah lewat masa berlakunya lebih
 * dari 30 menit ditandai 'expired' (lihat payment_recheck). Bila uangnya
 * ternyata masuk belakangan, status 'paid' tetap menang.
 *
 * Jalankan:
 *   php cron/payment_reconcile.php
 * atau lewat HTTP dengan token dari Pengaturan Sistem, setiap 30 menit:
 *   https://.../alumnilink/cron/payment_reconcile.php?token=...
 */

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/cron_auth.php';
require_once dirname(__DIR__) . '/includes/payment/service.php';

cron_require_auth($pdo);

@set_time_limit(150);
$mulai = microtime(true);
$log = function ($p) { echo '[' . date('Y-m-d H:i:s') . '] ' . $p . PHP_EOL; };

$calon = $pdo->query("SELECT * FROM payment_transactions
                       WHERE status = 'pending'
                         AND gateway <> 'cash'
                         AND provider_ref IS NOT NULL AND provider_ref <> ''
                         AND (created_at < DATE_SUB(NOW(), INTERVAL 20 MINUTE)
                              OR (expires_at IS NOT NULL AND expires_at < NOW()))
                         AND (last_checked_at IS NULL OR last_checked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
                       ORDER BY last_checked_at IS NOT NULL, last_checked_at, id
                       LIMIT 50")->fetchAll(PDO::FETCH_OBJ);

$log('Tagihan pending untuk diperiksa: ' . count($calon));

$hitung = ['applied' => 0, 'no_change' => 0, 'error' => 0, 'lain' => 0];
foreach ($calon as $t) {
    if (microtime(true) - $mulai > 90) {
        $log('Anggaran waktu habis; sisanya diperiksa pada jalan berikutnya.');
        break;
    }
    $h = payment_recheck($t, 'cron');
    $sesudah = payment_txn_get($t->id);
    $kunci = isset($hitung[$h['outcome']]) ? $h['outcome'] : 'lain';
    $hitung[$kunci]++;
    $log(sprintf('%-32s %-8s %s -> %s%s', $t->merchant_ref, $t->gateway, $t->status, $sesudah->status,
        !empty($h['error']) ? '  (' . $h['error'] . ')' : ''));
}

$log(sprintf('Selesai dalam %.1f detik: %d berubah, %d tetap, %d galat, %d lainnya.',
    microtime(true) - $mulai, $hitung['applied'], $hitung['no_change'], $hitung['error'], $hitung['lain']));

// Jejak di Audit Trail hanya bila ada yang berubah, supaya log tidak
// dibanjiri 48 baris sehari yang isinya "tidak ada apa-apa".
if ($hitung['applied'] > 0 && function_exists('log_activity')) {
    log_activity('PAYMENT_RECONCILE', "Rekonsiliasi pembayaran: {$hitung['applied']} tagihan berubah status.");
}
