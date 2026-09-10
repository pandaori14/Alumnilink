<?php
/**
 * Uji cadangan: buat dump, PULIHKAN ke basis data terpisah, lalu bandingkan
 * jumlah tabel dan isi baris satu per satu.
 *
 * Cadangan yang tidak dapat dipulihkan lebih buruk daripada tidak punya
 * cadangan sama sekali — ia memberi rasa aman yang keliru. Karena itu uji
 * ini benar-benar menjalankan SQL-nya, bukan sekadar memeriksa berkasnya ada.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once AKAR . '/includes/backup_lib.php';

$BASE = uji_base_url();
function panggil($sid, $url, $post = null) {
    $ch = curl_init($url);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_HEADER => true,
          CURLOPT_FOLLOWLOCATION => false];
    if ($sid) { $o[CURLOPT_COOKIE] = 'PHPSESSID=' . $sid; }
    if ($post !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = http_build_query($post); }
    curl_setopt_array($ch, $o);
    $raw = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $pisah = strpos($raw, "\r\n\r\n");
    return [$c, substr($raw, 0, $pisah), substr($raw, $pisah + 4)];
}

$DB_UJI = 'alumnilink_uji_pulih';

echo "=== A. Membuat dump lewat cron ===\n";
$sebelum = count(backup_daftar());
exec(escapeshellarg(PHP_BINARY) . ' ' . AKAR . '/cron/backup.php 2>&1', $out, $rc);
cek($rc === 0, 'cron cadangan berjalan tanpa galat', "exit=$rc");
$daftar = backup_daftar();
cek(count($daftar) === $sebelum + 1 || $sebelum >= setting_int('backup_keep', 7, 1),
    'satu berkas cadangan baru dibuat', count($daftar) . ' berkas');
$terbaru = backup_dir() . '/' . $daftar[0]['nama'];
cek(is_file($terbaru) && filesize($terbaru) > 1000, 'berkas cadangan berisi',
    number_format($daftar[0]['byte'] / 1024, 1) . ' KB');

echo "\n=== B. Folder cadangan tidak dapat dijangkau web ===\n";
[$c] = panggil(null, "$BASE/backups/" . $daftar[0]['nama']);
cek($c === 403 || $c === 404, 'berkas cadangan ditolak lewat HTTP', "HTTP $c");
[$c] = panggil(null, "$BASE/backups/");
cek($c === 403 || $c === 404, 'daftar isi folder ditolak', "HTTP $c");

echo "\n=== C. PULIHKAN ke basis data terpisah ===\n";
$sql = gzdecode(file_get_contents($terbaru));
cek($sql !== false && strlen($sql) > 1000, 'gzip dapat dibuka', number_format(strlen($sql) / 1024, 1) . ' KB SQL');
cek(strpos($sql, 'SET FOREIGN_KEY_CHECKS = 0') !== false, 'FK check dimatikan saat restore');
cek(strpos($sql, 'CREATE TABLE') !== false, 'memuat struktur tabel');
cek(strpos($sql, 'INSERT INTO `users`') !== false, 'memuat isi tabel users');

$root = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec("DROP DATABASE IF EXISTS `$DB_UJI`");
$root->exec("CREATE DATABASE `$DB_UJI` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

$uji = new PDO("mysql:host=" . DB_HOST . ";dbname=$DB_UJI;charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ]);

// Jalankan dump apa adanya, persis seperti phpMyAdmin -> Import.
$galat = '';
try {
    $uji->exec($sql);
} catch (PDOException $e) {
    $galat = $e->getMessage();
}
cek($galat === '', 'seluruh SQL dijalankan tanpa galat', substr($galat, 0, 90));

echo "\n=== D. Bandingkan hasil pulihan dengan aslinya ===\n";
$tabel_asli = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$tabel_pulih = $uji->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
sort($tabel_asli); sort($tabel_pulih);
cek($tabel_asli === $tabel_pulih, 'jumlah & nama tabel identik',
    count($tabel_asli) . ' vs ' . count($tabel_pulih));

$tanpa_isi = backup_tabel_tanpa_isi();
$beda = [];
$total_baris = 0;
foreach ($tabel_asli as $t) {
    $a = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    $b = (int)$uji->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    $total_baris += $b;
    // Dump adalah potret pada satu titik waktu. activity_logs adalah
    // satu-satunya tabel yang ditulis oleh proses pencadangan itu sendiri
    // (cron mencatat hasilnya SETELAH dump selesai), jadi baris terbaru di
    // sana memang wajar belum ikut. Yang salah justru bila hasil pulihan
    // punya LEBIH banyak baris daripada aslinya.
    $harap = in_array($t, $tanpa_isi, true) ? 0 : $a;
    if ($t === 'activity_logs') {
        if ($b > $a || $b < $a - 3) { $beda[] = "$t: $a -> $b"; }
    } elseif ($b !== $harap) {
        $beda[] = "$t: $a -> $b";
    }
}
cek(empty($beda), 'jumlah baris setiap tabel cocok', $beda ? implode('; ', $beda) : "$total_baris baris dipulihkan");

// Bandingkan ISI tabel users kolom per kolom — di sinilah data paling penting.
$kol = 'id, nim, name, email, password, role, is_verified, major, graduation_year, ipk, phone, address';
$h_asli  = md5((string)$pdo->query("SELECT GROUP_CONCAT(CONCAT_WS('|', $kol) ORDER BY id SEPARATOR '#') FROM users")->fetchColumn());
$h_pulih = md5((string)$uji->query("SELECT GROUP_CONCAT(CONCAT_WS('|', $kol) ORDER BY id SEPARATOR '#') FROM users")->fetchColumn());
cek($h_asli === $h_pulih, 'isi tabel users identik byte-per-byte', substr($h_asli, 0, 12));

// Nilai NULL harus tetap NULL, bukan berubah jadi string kosong.
$null_asli  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE phone IS NULL")->fetchColumn();
$null_pulih = (int)$uji->query("SELECT COUNT(*) FROM users WHERE phone IS NULL")->fetchColumn();
cek($null_asli === $null_pulih, 'NULL tetap NULL setelah dipulihkan', "$null_asli vs $null_pulih");

// Teks panjang dan karakter khusus (badan e-mail HTML) harus utuh.
try {
    $ba = (string)$pdo->query("SELECT MD5(GROUP_CONCAT(body_html ORDER BY id SEPARATOR '#')) FROM email_queue")->fetchColumn();
    $bp = (string)$uji->query("SELECT MD5(GROUP_CONCAT(body_html ORDER BY id SEPARATOR '#')) FROM email_queue")->fetchColumn();
    cek($ba === $bp, 'HTML panjang & karakter khusus utuh', substr($ba, 0, 12));
} catch (PDOException $e) { cek(false, 'HTML panjang & karakter khusus utuh', $e->getMessage()); }

// Struktur: indeks dan kunci ikut terbawa.
$idx_asli  = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE()")->fetchColumn();
$idx_pulih = (int)$uji->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = '$DB_UJI'")->fetchColumn();
cek($idx_asli === $idx_pulih, 'seluruh indeks ikut terbawa', "$idx_asli vs $idx_pulih");

echo "\n=== E. Penjagaan unduhan ===\n";
$csrf = bin2hex(random_bytes(16));
$uid_sa = $pdo->query("SELECT id FROM users WHERE role='super_admin' LIMIT 1")->fetchColumn();
$uid_al = $pdo->query("SELECT id FROM users WHERE role='alumni' LIMIT 1")->fetchColumn();
$sa = sesi_palsu($uid_sa, 'super_admin', $csrf);
$al = sesi_palsu($uid_al, 'alumni', $csrf);
$st = sesi_palsu($uid_sa, 'admin_legalisir', $csrf);

[$c] = panggil(null, "$BASE/handlers/admin_backup.php", ['csrf_token' => $csrf]);
cek($c === 401 || $c === 403, 'tanpa sesi ditolak', "HTTP $c");
[$c] = panggil($al, "$BASE/handlers/admin_backup.php", ['csrf_token' => $csrf]);
cek($c === 403, 'alumni ditolak', "HTTP $c");
[$c] = panggil($st, "$BASE/handlers/admin_backup.php", ['csrf_token' => $csrf]);
cek($c === 403, 'staf non-super_admin ditolak', "HTTP $c");
[$c] = panggil($sa, "$BASE/handlers/admin_backup.php", ['x' => '1']);
cek($c === 403 || $c === 400, 'tanpa token CSRF ditolak', "HTTP $c");
[$c, $hdr] = panggil($sa, "$BASE/handlers/admin_backup.php");   // GET
cek($c === 302, 'GET dialihkan, tidak mengunduh', "HTTP $c");

[$c, $hdr, $body] = panggil($sa, "$BASE/handlers/admin_backup.php", ['csrf_token' => $csrf]);
cek($c === 200, 'super_admin dapat mengunduh', "HTTP $c");
cek(stripos($hdr, 'Content-Disposition: attachment') !== false, 'dikirim sebagai unduhan');
cek(preg_match('/filename="alumnilink_\d{8}_\d{6}\.sql(\.gz)?"/', $hdr) === 1, 'nama berkas berstempel waktu');
$isi = gzdecode($body);
cek($isi !== false && strpos($isi, 'CREATE TABLE') !== false, 'unduhan berisi dump yang sah',
    $isi !== false ? number_format(strlen($isi) / 1024, 1) . ' KB' : 'gagal dibuka');
$log = $pdo->query("SELECT description FROM activity_logs WHERE action='BACKUP_DB' ORDER BY id DESC LIMIT 1")->fetchColumn();
cek(strpos((string)$log, 'alumnilink_') !== false, 'unduhan tercatat di Audit Trail', (string)$log);

echo "\n=== F. Rotasi ===\n";
for ($i = 0; $i < 3; $i++) { exec(escapeshellarg(PHP_BINARY) . ' ' . AKAR . '/cron/backup.php 2>&1'); sleep(1); }
$pdo->exec("INSERT INTO settings (setting_key,setting_value) VALUES ('backup_keep','2')
            ON DUPLICATE KEY UPDATE setting_value='2'");
exec(escapeshellarg(PHP_BINARY) . ' ' . AKAR . '/cron/backup.php 2>&1');
cek(count(backup_daftar()) === 2, 'rotasi menyisakan tepat 2 berkas', count(backup_daftar()) . ' berkas');
$pdo->exec("DELETE FROM settings WHERE setting_key='backup_keep'");

// ── Bersihkan ───────────────────────────────────────────────────────
$root->exec("DROP DATABASE IF EXISTS `$DB_UJI`");
foreach (glob(backup_dir() . '/alumnilink_*.sql*') as $f) { @unlink($f); }
foreach ([$sa, $al, $st] as $x) { @unlink((session_save_path() ?: sys_get_temp_dir()) . '/sess_' . $x); }
$pdo->exec("DELETE FROM activity_logs WHERE action IN ('BACKUP_DB','BACKUP_DB_CRON')");
cek(count(backup_daftar()) === 0, 'berkas uji dibersihkan');

echo "\n────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
exit($fail ? 1 : 0);
