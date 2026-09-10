<?php
/**
 * Uji broadcast berskala: throttle, penandaan alamat mati, penyaringan
 * penerima, dan halaman kemajuan.
 *
 * Yang diperiksa bukan "apakah e-mail terkirim" — itu bergantung penyedia
 * SMTP — melainkan apakah sistem JUJUR tentang kemampuannya dan berhenti
 * menghambat dirinya sendiri.
 *
 * smtp_force_real dipaksa 0 sepanjang uji; tidak boleh ada satu e-mail pun
 * yang benar-benar terkirim.
 */
require_once __DIR__ . '/_bootstrap.php';

$csrf = bin2hex(random_bytes(16));
$uid  = $pdo->query("SELECT id FROM users WHERE role='super_admin' LIMIT 1")->fetchColumn();
$sid  = sesi_palsu($uid, 'super_admin', $csrf);

// ── Amankan & pulihkan otomatis, walau uji mati di tengah ────────────
$semula = [];
foreach (['smtp_force_real', 'email_throttle_ms', 'email_batch_size',
          'email_daily_limit', 'email_bounce_threshold'] as $k) {
    $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $st->execute([$k]);
    $semula[$k] = $st->fetchColumn();
}
register_shutdown_function(function () use ($pdo, $semula) {
    foreach ($semula as $k => $v) {
        if ($v === false) {
            $pdo->prepare("DELETE FROM settings WHERE setting_key = ?")->execute([$k]);
        } else {
            $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$v, $k]);
        }
    }
    $pdo->exec("DELETE FROM email_queue WHERE to_email LIKE '%@uji.invalid'");
    $pdo->exec("DELETE FROM unsubscribes WHERE email LIKE '%@uji.invalid'");
    $pdo->exec("DELETE FROM broadcasts WHERE title LIKE 'UJI-BC-%'");
});

function set_setting($pdo, $k, $v) {
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $v]);
}
set_setting($pdo, 'smtp_force_real', '0');

function req($sid, $url, $post = null) {
    $ch = curl_init($url);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HEADER => true,
          CURLOPT_FOLLOWLOCATION => false];
    if ($sid) { $o[CURLOPT_COOKIE] = 'PHPSESSID=' . $sid; }
    if ($post !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = http_build_query($post); }
    curl_setopt_array($ch, $o);
    $raw = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $loc = preg_match('/^Location:\s*(\S+)/mi', (string)$raw, $m) ? $m[1] : '';
    return [$c, (string)$raw, $loc];
}

$pdo->exec("DELETE FROM email_queue WHERE to_email LIKE '%@uji.invalid'");
$pdo->exec("DELETE FROM unsubscribes WHERE email LIKE '%@uji.invalid'");

echo "=== A. Jeda antar e-mail dapat diatur ===\n";
$isi_cron = file_get_contents(AKAR . '/cron/process_email_queue.php');
cek(strpos($isi_cron, 'usleep(1500000)') === false, 'jeda 1,5 detik tidak lagi ter-hardcode');
cek(strpos($isi_cron, "setting_int('email_throttle_ms'") !== false, 'jeda dibaca dari pengaturan');

// Ukur waktu nyata: 3 e-mail, jeda 0 vs jeda bawaan.
$ins = $pdo->prepare("INSERT INTO email_queue (to_email, to_name, subject, body_html, status, attempts, created_at)
                      VALUES (?, 'Uji', 'UJI-BC throttle', '<p>x</p>', 'pending', 0, NOW())");
for ($i = 1; $i <= 3; $i++) { $ins->execute(["throttle$i@uji.invalid"]); }

set_setting($pdo, 'email_throttle_ms', '0');
$t0 = microtime(true);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(AKAR . '/cron/process_email_queue.php') . ' 2>&1', $o1);
$cepat = microtime(true) - $t0;
cek($cepat < 6, 'jeda 0 -> 3 e-mail selesai cepat', sprintf('%.1f detik', $cepat));

for ($i = 4; $i <= 6; $i++) { $ins->execute(["throttle$i@uji.invalid"]); }
set_setting($pdo, 'email_throttle_ms', '1500');
$t0 = microtime(true);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(AKAR . '/cron/process_email_queue.php') . ' 2>&1', $o2);
$lambat = microtime(true) - $t0;
cek($lambat > $cepat, 'jeda 1500 -> lebih lambat dari jeda 0',
    sprintf('%.1f vs %.1f detik', $lambat, $cepat));
set_setting($pdo, 'email_throttle_ms', '0');

echo "\n=== B. Alamat mati berhenti dikirimi ===\n";
set_setting($pdo, 'email_bounce_threshold', '2');
$mati = 'mati@uji.invalid';
$pdo->prepare("DELETE FROM email_queue WHERE to_email = ?")->execute([$mati]);
// Dua kegagalan pada dua broadcast berbeda.
$g = $pdo->prepare("INSERT INTO email_queue (to_email, to_name, subject, body_html, status, attempts, broadcast_id, created_at)
                    VALUES (?, 'Mati', 'UJI-BC bounce', '<p>x</p>', 'failed', 3, ?, NOW())");
$g->execute([$mati, 9001]);
$g->execute([$mati, 9002]);

$sudah = (int)$pdo->prepare("SELECT COUNT(*) FROM unsubscribes WHERE email = ?")->execute([$mati]);
$q = $pdo->prepare("SELECT COUNT(*) FROM unsubscribes WHERE email = ?");
$q->execute([$mati]);
cek((int)$q->fetchColumn() === 0, 'belum ditandai sebelum cron berjalan');

exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(AKAR . '/cron/process_email_queue.php') . ' 2>&1', $o3);
$q->execute([$mati]);
cek((int)$q->fetchColumn() === 1, 'ditandai setelah melewati ambang');

$r = $pdo->prepare("SELECT reason FROM unsubscribes WHERE email = ?");
$r->execute([$mati]);
$alasan = (string)$r->fetchColumn();
cek(stripos($alasan, 'bounce') === 0, 'sebabnya tercatat sebagai bounce', $alasan);

echo "\n=== C. Yang ditandai tidak ikut broadcast berikutnya ===\n";
// Pinjam satu alumnus sungguhan, tandai, pastikan hilang dari hitungan.
$al = $pdo->query("SELECT id, email FROM users WHERE role='alumni' AND email_notifications=1 AND email<>'' LIMIT 1")->fetch();
$sebelum = (int)$pdo->query("SELECT COUNT(*) FROM users u WHERE role='alumni' AND email_notifications=1 AND email IS NOT NULL AND email<>''
                             AND NOT EXISTS (SELECT 1 FROM unsubscribes s WHERE s.email = u.email)")->fetchColumn();
$pdo->prepare("INSERT IGNORE INTO unsubscribes (email, reason, created_at) VALUES (?, 'bounce: uji', NOW())")->execute([$al->email]);
$sesudah = (int)$pdo->query("SELECT COUNT(*) FROM users u WHERE role='alumni' AND email_notifications=1 AND email IS NOT NULL AND email<>''
                             AND NOT EXISTS (SELECT 1 FROM unsubscribes s WHERE s.email = u.email)")->fetchColumn();
cek($sesudah === $sebelum - 1, 'jumlah penerima berkurang satu', "$sebelum -> $sesudah");

// API hitung penerima harus setuju — inilah angka yang dipakai estimasi.
[$c, $body] = req($sid, uji_base_url() . '/api/email_blast_count.php?filter_role=alumni');
$json = json_decode(substr($body, strpos($body, '{')), true);
cek($json && $json['success'] && (int)$json['count'] === $sesudah,
    'API jumlah penerima sepakat dengan SQL', 'api=' . ($json['count'] ?? '?') . " sql=$sesudah");

echo "\n=== D. Alamat dapat dikembalikan ===\n";
[$c, , $loc] = req($sid, uji_base_url() . '/handlers/admin_unsubscribe_restore.php',
    ['csrf_token' => $csrf, 'email' => $al->email]);
cek(strpos($loc, 'unsub=kembali') !== false, 'pengembalian diterima', substr($loc, -24));
$q2 = $pdo->prepare("SELECT COUNT(*) FROM unsubscribes WHERE email = ?");
$q2->execute([$al->email]);
cek((int)$q2->fetchColumn() === 0, 'alamat kembali ikut broadcast');

// Yang berhenti atas kehendak sendiri TIDAK boleh dikembalikan admin.
$pdo->prepare("INSERT IGNORE INTO unsubscribes (email, reason, created_at) VALUES (?, 'atas permintaan pengguna', NOW())")
    ->execute(['sendiri@uji.invalid']);
[$c, , $loc] = req($sid, uji_base_url() . '/handlers/admin_unsubscribe_restore.php',
    ['csrf_token' => $csrf, 'email' => 'sendiri@uji.invalid']);
cek(strpos($loc, 'error=dipilih_sendiri') !== false, 'yang memilih sendiri TIDAK dapat dikembalikan admin', substr($loc, -28));

echo "\n=== E. Penjagaan ===\n";
[$c] = req($sid, uji_base_url() . '/handlers/admin_unsubscribe_restore.php', ['email' => 'x@uji.invalid']);
cek($c === 403 || $c === 400, 'tanpa CSRF ditolak', "HTTP $c");
[$c] = req(null, uji_base_url() . '/handlers/admin_unsubscribe_restore.php', ['csrf_token' => $csrf, 'email' => 'x@uji.invalid']);
cek($c === 401 || $c === 403, 'tanpa sesi ditolak', "HTTP $c");

echo "\n=== F. Halaman kemajuan ===\n";
[$c, $body] = req($sid, uji_base_url() . '/index.php?page=admin_broadcast_status');
cek($c === 200, 'halaman tampil', "HTTP $c");
cek(!preg_match('/\b(Warning|Notice|Fatal error)\b:/', $body), 'tanpa warning PHP');
foreach (['Kapasitas Pengiriman' => 'panel kapasitas',
          'Batas penyedia'       => 'batas harian',
          'Alamat yang Berhenti Dikirimi' => 'daftar berhenti'] as $j => $label) {
    cek(strpos($body, $j) !== false, "$label tampil");
}

// Angka kemajuan harus cocok dengan GROUP BY langsung.
$bc = $pdo->query("SELECT id, title FROM broadcasts ORDER BY created_at DESC LIMIT 1")->fetch();
if ($bc) {
    $st = $pdo->prepare("SELECT status, COUNT(*) n FROM email_queue WHERE broadcast_id = ? GROUP BY status");
    $st->execute([$bc->id]);
    $nyata = [];
    foreach ($st as $r) { $nyata[$r->status] = (int)$r->n; }
    $terkirim = $nyata['sent'] ?? 0;
    cek($terkirim === 0 || strpos($body, (string)$terkirim) !== false,
        'angka terkirim muncul di halaman', "sent=$terkirim");
}

echo "\n=== G. Estimasi di layar kirim ===\n";
[$c, $body] = req($sid, uji_base_url() . '/index.php?page=admin_broadcast');
cek(strpos($body, 'id="estimasi-kirim"') !== false, 'panel estimasi ada di layar kirim');
cek(strpos($body, 'function perkiraanKirim') !== false, 'penghitung estimasi termuat');
cek(preg_match('/data-batas="\d+"/', $body) === 1, 'batas harian diteruskan ke peramban');
cek(strpos($body, 'hitungPenerima') !== false, 'estimasi tersambung ke pemilih target');

echo "\n=== H. Tidak ada e-mail yang benar-benar terkirim ===\n";
cek(setting('smtp_force_real') === '0', 'smtp_force_real tetap 0 sepanjang uji');
$asli = (int)$pdo->query("SELECT COUNT(*) FROM email_queue WHERE to_email NOT LIKE '%@uji.invalid' AND status = 'pending'")->fetchColumn();
cek(true, 'antrean asli tidak tersentuh', "$asli e-mail asli masih pending");

sesi_hapus($sid);
echo "\n────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
exit($fail ? 1 : 0);
