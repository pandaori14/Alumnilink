<?php
/**
 * Uji end-to-end impor massal alumni.
 * Semuanya lewat HTTP seperti admin sungguhan: unggah -> baca layar
 * pratinjau -> ambil token dari formulir -> commit.
 */
require_once __DIR__ . '/_bootstrap.php';

$DIR  = uji_fixture();
$BASE = uji_base_url();



function kirim($sid, $url, $post = null, $berkas = null) {
    $ch = curl_init($url);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HEADER => true,
          CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIE => 'PHPSESSID=' . $sid];
    if ($post !== null) {
        $o[CURLOPT_POST] = true;
        if ($berkas) {
            $post['csv'] = new CURLFile($berkas, 'text/csv', basename($berkas));
            $o[CURLOPT_POSTFIELDS] = $post;
        } else {
            $o[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
    }
    curl_setopt_array($ch, $o);
    $raw = curl_exec($ch);
    $c   = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $loc = preg_match('/^Location:\s*(\S+)/mi', (string)$raw, $m) ? $m[1] : '';
    return [$c, (string)$raw, $loc];
}

/** Muat halaman pratinjau dan tarik angka ringkasan + token dari HTML. */
function baca_pratinjau($sid) {
    global $BASE;
    [$c, $body] = kirim($sid, "$BASE/index.php?page=admin_alumni_import&preview=1");
    $out = ['http' => $c, 'token' => '', 'ringkasan' => [], 'body' => $body];
    if (preg_match('/name="token" value="([a-f0-9]+)"/', $body, $m)) {
        $out['token'] = $m[1];
    }
    // Empat kartu ringkasan: angka besar lalu labelnya.
    if (preg_match_all('/outfit">(\d+)<\/div>\s*<div class="text-xs font-bold text-\w+-600 uppercase tracking-wider mt-1">([^<]+)</', $body, $mm, PREG_SET_ORDER)) {
        $peta = ['Akan dibuat' => 'create', 'Lengkapi data' => 'update',
                 'Bentrok NIM' => 'conflict', 'Tidak sah' => 'error'];
        foreach ($mm as $x) {
            $label = trim($x[2]);
            if (isset($peta[$label])) { $out['ringkasan'][$peta[$label]] = (int)$x[1]; }
        }
    }
    return $out;
}

$csrf = bin2hex(random_bytes(16));
$uid  = $pdo->query("SELECT id FROM users WHERE role='super_admin' LIMIT 1")->fetchColumn();
$sid  = sesi_palsu($uid, 'super_admin', $csrf);

$bersihkan = function () use ($pdo) {
    $pdo->exec("DELETE FROM users WHERE email LIKE '%@contoh.invalid'");
};
$bersihkan();

$sebelum = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$sidik = function () use ($pdo) {
    return md5((string)$pdo->query(
        "SELECT GROUP_CONCAT(CONCAT_WS('|', id, IFNULL(nim,''), email, name, IFNULL(phone,''), IFNULL(address,'')) ORDER BY id)
         FROM users WHERE email NOT LIKE '%@contoh.invalid'")->fetchColumn());
};
$sidik_sebelum = $sidik();

echo "=== A. Berkas NAKAL — pratinjau benar & tidak menulis ===\n";
[$c, , $loc] = kirim($sid, "$BASE/handlers/admin_alumni_import.php?action=preview",
    ['csrf_token' => $csrf], "$DIR/nakal.csv");
cek(strpos($loc, 'preview=1') !== false, 'pratinjau berhasil', "HTTP $c");

cek((int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() === $sebelum,
    'PRATINJAU TIDAK MENULIS APA PUN');

$pv = baca_pratinjau($sid);
cek($pv['http'] === 200, 'halaman pratinjau tampil', 'HTTP ' . $pv['http']);
cek($pv['token'] !== '', 'token pratinjau ada di formulir', substr($pv['token'], 0, 12) . '...');
cek(($pv['ringkasan']['create'] ?? -1) === 1,   'ringkasan: 1 akan dibuat',   'create='   . ($pv['ringkasan']['create']   ?? '?'));
cek(($pv['ringkasan']['update'] ?? -1) === 1,   'ringkasan: 1 lengkapi data', 'update='   . ($pv['ringkasan']['update']   ?? '?'));
cek(($pv['ringkasan']['conflict'] ?? -1) === 1, 'ringkasan: 1 bentrok NIM',   'conflict=' . ($pv['ringkasan']['conflict'] ?? '?'));
cek(($pv['ringkasan']['error'] ?? -1) === 9,    'ringkasan: 9 tidak sah',     'error='    . ($pv['ringkasan']['error']    ?? '?'));

foreach ([
    'tidak sah'          => 'NIM &quot;a&quot; tidak sah',
    'duplikat di berkas' => 'muncul lebih dari sekali di berkas ini',
    'prodi asing'        => 'tidak dikenali',
    'IPK di luar batas'  => 'di luar rentang 0,00-4,00',
    'tahun ngawur'       => 'di luar rentang 1950',
    'bentrok NIM'        => 'sudah dipakai',
] as $label => $jarum) {
    cek(strpos($pv['body'], $jarum) !== false, "pesan \"$label\" tampil di pratinjau");
}

echo "\n=== B. Commit tanpa izin perbarui ===\n";
[$c, , $loc] = kirim($sid, "$BASE/handlers/admin_alumni_import.php?action=commit",
    ['csrf_token' => $csrf, 'token' => $pv['token']]);
cek(strpos($loc, 'success=1') !== false, 'commit berhasil', substr($loc, -46));
parse_str((string)parse_url($loc, PHP_URL_QUERY), $q);
cek((int)($q['dibuat'] ?? -1) === 1,     'tepat 1 baris dibuat',  'dibuat='     . ($q['dibuat'] ?? '?'));
cek((int)($q['diperbarui'] ?? -1) === 0, 'nol baris diperbarui',  'diperbarui=' . ($q['diperbarui'] ?? '?'));
cek($sidik_sebelum === $sidik(), 'BARIS LAMA TIDAK BERUBAH SAMA SEKALI');

$baru = $pdo->query("SELECT id,nim,email,ipk,phone,major,graduation_year,password FROM users WHERE email='baris.sah@contoh.invalid'")->fetch();
cek($baru !== false, 'baris sah benar-benar dibuat');
if ($baru) {
    cek(preg_match('/^usr_[0-9a-f]{16}_\d+$/', $baru->id) === 1, 'id pakai pola usr_, bukan NIM', $baru->id);
    cek((float)$baru->ipk === 3.45,          'IPK koma "3,45" jadi 3.45', (string)$baru->ipk);
    cek($baru->phone === '+6281200001',      'telepon dinormalkan ke +62', (string)$baru->phone);
    cek($baru->major === 'J500',             'nama prodi diterjemahkan ke kode', (string)$baru->major);
    cek(strlen((string)$baru->password) > 50, 'kata sandi berupa hash acak, bukan NULL');
}

echo "\n=== C. Token pratinjau dipakai ulang ===\n";
[$c, , $loc] = kirim($sid, "$BASE/handlers/admin_alumni_import.php?action=commit",
    ['csrf_token' => $csrf, 'token' => $pv['token']]);
cek(strpos($loc, 'error=sesi_habis') !== false, 'token bekas ditolak', substr($loc, -32));

echo "\n=== D. Penjagaan ===\n";
[$c] = kirim($sid, "$BASE/handlers/admin_alumni_import.php?action=preview", ['x' => '1'], "$DIR/bersih.csv");
cek($c === 403 || $c === 400, 'tanpa token CSRF ditolak', "HTTP $c");
[$c] = kirim('tidakadasesi', "$BASE/handlers/admin_alumni_import.php?action=preview", ['csrf_token' => $csrf], "$DIR/bersih.csv");
cek($c === 401 || $c === 403, 'tanpa sesi ditolak', "HTTP $c");
[$c, , $loc] = kirim($sid, "$BASE/handlers/admin_alumni_import.php?action=preview", ['csrf_token' => $csrf]);
cek(strpos($loc, 'error=unggah') !== false, 'tanpa berkas ditolak', substr($loc, -24));

echo "\n=== E. Berkas bersih: 3 dibuat ===\n";
kirim($sid, "$BASE/handlers/admin_alumni_import.php?action=preview", ['csrf_token' => $csrf], "$DIR/bersih.csv");
$pv2 = baca_pratinjau($sid);
cek(($pv2['ringkasan']['create'] ?? -1) === 3, '3 baris siap dibuat', 'create=' . ($pv2['ringkasan']['create'] ?? '?'));
[$c, , $loc] = kirim($sid, "$BASE/handlers/admin_alumni_import.php?action=commit",
    ['csrf_token' => $csrf, 'token' => $pv2['token']]);
parse_str((string)parse_url($loc, PHP_URL_QUERY), $q);
cek((int)($q['dibuat'] ?? -1) === 3, '3 alumni dibuat', 'dibuat=' . ($q['dibuat'] ?? '?'));
$ids = $pdo->query("SELECT id FROM users WHERE email LIKE 'uji.impor.%@contoh.invalid'")->fetchAll(PDO::FETCH_COLUMN);
cek(count($ids) === 3 && count(array_unique($ids)) === 3, 'id unik semua', (string)count($ids));

echo "\n=== F. Impor ulang berkas yang sama ===\n";
kirim($sid, "$BASE/handlers/admin_alumni_import.php?action=preview", ['csrf_token' => $csrf], "$DIR/bersih.csv");
$pv3 = baca_pratinjau($sid);
cek(($pv3['ringkasan']['create'] ?? -1) === 0, 'nol baris baru pada impor kedua', 'create=' . ($pv3['ringkasan']['create'] ?? '?'));
cek(($pv3['ringkasan']['update'] ?? -1) === 3, 'ketiganya dikenali sudah ada',    'update=' . ($pv3['ringkasan']['update'] ?? '?'));

echo "\n=== G. Formulir manual ===\n";
[$c, , $loc] = kirim($sid, "$BASE/handlers/admin_alumni_handler.php?action=save",
    ['csrf_token' => $csrf, 'nim' => 'J509990099', 'name' => 'Duplikat Email', 'email' => 'baris.sah@contoh.invalid',
     'major' => 'J500', 'graduation_year' => '2024', 'ipk' => '3.0', 'phone' => '0812', 'address' => 'x', 'password' => 'rahasia123']);
cek(strpos($loc, 'error=email_ganda') !== false, 'e-mail ganda ditolak dengan pesan jelas', substr($loc, -44));

[$c, , $loc] = kirim($sid, "$BASE/handlers/admin_alumni_handler.php?action=save",
    ['csrf_token' => $csrf, 'nim' => 'a', 'name' => 'Nim Ngawur', 'email' => 'nim.baru@contoh.invalid',
     'major' => 'J500', 'graduation_year' => '2024', 'ipk' => '3.0', 'phone' => '0812', 'address' => 'x', 'password' => 'rahasia123']);
cek(strpos($loc, 'error=nim_tidak_sah') !== false, 'NIM ngawur ditolak', substr($loc, -32));

[$c, , $loc] = kirim($sid, "$BASE/handlers/admin_alumni_handler.php?action=save",
    ['csrf_token' => $csrf, 'nim' => 'l200160042', 'name' => 'Bentrok NIM Manual', 'email' => 'bentrok2@contoh.invalid',
     'major' => 'J500', 'graduation_year' => '2024', 'ipk' => '3.0', 'phone' => '0812', 'address' => 'x', 'password' => 'rahasia123']);
cek(strpos($loc, 'error=nim_ganda') !== false, 'NIM yang sudah dipakai ditolak', substr($loc, -40));

[$c, , $loc] = kirim($sid, "$BASE/handlers/admin_alumni_handler.php?action=save",
    ['csrf_token' => $csrf, 'nim' => 'J509990100', 'name' => 'Alumni Manual', 'email' => 'manual@contoh.invalid',
     'major' => 'J500', 'graduation_year' => '2024', 'ipk' => '3.0', 'phone' => '081299999', 'address' => 'x', 'password' => 'rahasia123']);
cek(strpos($loc, 'success=added') !== false, 'entri manual sah tetap berhasil', substr($loc, -30));
$m = (string)$pdo->query("SELECT id FROM users WHERE email='manual@contoh.invalid'")->fetchColumn();
cek(preg_match('/^usr_[0-9a-f]{16}_\d+$/', $m) === 1, 'entri manual juga pakai id usr_', $m);

echo "\n=== H. Berkas contoh & daftar galat ===\n";
[$c, $raw] = kirim($sid, "$BASE/handlers/admin_alumni_import.php?action=template");
cek($c === 200 && strpos($raw, 'NIM') !== false, 'berkas contoh dapat diunduh', "HTTP $c");

$bersihkan();
@unlink((session_save_path() ?: sys_get_temp_dir()) . '/sess_' . $sid);
cek((int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() === $sebelum, 'data uji dibersihkan tuntas');

echo "\n────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
exit($fail ? 1 : 0);
