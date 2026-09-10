<?php
/**
 * Uji kunci UNIQUE pada users.nim.
 *
 * Yang paling penting bukan "apakah basis data menolak duplikat" — itu
 * sudah pasti setelah constraint terpasang — melainkan apakah APLIKASI
 * menangkapnya lebih dulu dan memberi pesan yang bisa dimengerti, alih-alih
 * membiarkan PDOException mentah berakhir di halaman "Terjadi kesalahan
 * sistem". Itulah keadaan sebelum perbaikan.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once AKAR . '/includes/import_lib.php';

$BASE = uji_base_url();
function kirim($sid, $url, $post = null, $berkas = null) {
    $ch = curl_init($url);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HEADER => true,
          CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIE => 'PHPSESSID=' . $sid];
    if ($post !== null) {
        $o[CURLOPT_POST] = true;
        if ($berkas) { $post['csv'] = new CURLFile($berkas, 'text/csv', basename($berkas)); $o[CURLOPT_POSTFIELDS] = $post; }
        else { $o[CURLOPT_POSTFIELDS] = http_build_query($post); }
    }
    curl_setopt_array($ch, $o);
    $raw = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $loc = preg_match('/^Location:\s*(\S+)/mi', (string)$raw, $m) ? $m[1] : '';
    return [$c, (string)$raw, $loc];
}

echo "=== A. Keadaan skema ===\n";
$idx = $pdo->query("SHOW INDEX FROM users WHERE Key_name='nim'")->fetch();
cek($idx && (int)$idx->Non_unique === 0, 'users.nim berkunci UNIQUE');
cek((int)$pdo->query("SELECT COUNT(*) FROM users WHERE nim = ''")->fetchColumn() === 0,
    'nol NIM string kosong (yang akan merusak UNIQUE)');
$ngawur = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE nim IS NOT NULL AND nim <> '' AND nim NOT REGEXP '^[A-Za-z0-9]{6,20}$'")->fetchColumn();
cek($ngawur === 0, 'nol NIM tidak sah tersisa', (string)$ngawur);
$null = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE nim IS NULL")->fetchColumn();
cek($null > 1, 'banyak NULL tetap diizinkan berdampingan', "$null baris NULL");

echo "\n=== B. Basis data menolak duplikat ===\n";
$nim_ada = (string)$pdo->query("SELECT nim FROM users WHERE nim IS NOT NULL LIMIT 1")->fetchColumn();
$ditolak = false;
try {
    $pdo->prepare("INSERT INTO users (id, nim, name, email, password, role) VALUES (?,?,?,?,?,'alumni')")
        ->execute(['UJI_NIM_DUP', $nim_ada, 'Uji Duplikat', 'uji.nim.dup@contoh.invalid', 'x']);
} catch (PDOException $e) {
    $ditolak = (strpos($e->getMessage(), '1062') !== false) || stripos($e->getMessage(), 'duplicate') !== false;
}
cek($ditolak, 'INSERT dengan NIM yang sudah ada ditolak basis data', $nim_ada);
$pdo->exec("DELETE FROM users WHERE id = 'UJI_NIM_DUP'");

echo "\n=== C. Aplikasi menangkapnya LEBIH DULU, dengan pesan jelas ===\n";
$csrf = bin2hex(random_bytes(16));
$uid  = $pdo->query("SELECT id FROM users WHERE role='super_admin' LIMIT 1")->fetchColumn();
$sid  = sesi_palsu($uid, 'super_admin', $csrf);

[$c, $raw, $loc] = kirim($sid, "$BASE/handlers/admin_alumni_handler.php?action=save", [
    'csrf_token' => $csrf, 'nim' => $nim_ada, 'name' => 'Uji Bentrok NIM',
    'email' => 'uji.bentrok.nim@contoh.invalid', 'major' => 'J500',
    'graduation_year' => '2024', 'ipk' => '3.0', 'phone' => '0812', 'address' => 'x',
    'password' => 'rahasia123',
]);
cek(strpos($loc, 'error=nim_ganda') !== false, 'formulir manual: pesan "nim_ganda", bukan galat sistem', substr($loc, -46));
cek(strpos($raw, 'Terjadi kesalahan sistem') === false, 'TIDAK berakhir di halaman galat umum');
cek((int)$pdo->query("SELECT COUNT(*) FROM users WHERE email='uji.bentrok.nim@contoh.invalid'")->fetchColumn() === 0,
    'tidak ada baris yang tertulis');

// Halaman menampilkan pesannya
[$c, $raw] = kirim($sid, "$BASE/index.php?page=admin_alumni&error=nim_ganda&nama=" . urlencode('Pandu Egi Ferdian'));
cek(strpos($raw, 'sudah dipakai') !== false, 'halaman menampilkan penjelasan bentrok');

echo "\n=== D. Impor massal juga menangkapnya ===\n";
$dir = uji_fixture();
@mkdir($dir);
file_put_contents("$dir/nim_bentrok.csv",
    "NIM,Nama,Email,Tahun Lulus,Prodi,IPK,Telepon,Alamat\n" .
    "$nim_ada,Uji Bentrok Impor,uji.impor.bentrok@contoh.invalid,2024,J500,3.2,081200099,Jl. X\n");
kirim($sid, "$BASE/handlers/admin_alumni_import.php?action=preview", ['csrf_token' => $csrf], "$dir/nim_bentrok.csv");
[$c, $b] = kirim($sid, "$BASE/index.php?page=admin_alumni_import&preview=1");
cek(strpos($b, 'Bentrok NIM') !== false, 'pratinjau menandainya "Bentrok NIM"');
if (preg_match('/name="token" value="([a-f0-9]+)"/', $b, $m)) {
    [$c, , $loc] = kirim($sid, "$BASE/handlers/admin_alumni_import.php?action=commit",
        ['csrf_token' => $csrf, 'token' => $m[1]]);
    parse_str((string)parse_url($loc, PHP_URL_QUERY), $q);
    cek((int)($q['dibuat'] ?? -1) === 0, 'commit tidak membuat baris bentrok', 'dibuat=' . ($q['dibuat'] ?? '?'));
}
cek((int)$pdo->query("SELECT COUNT(*) FROM users WHERE email='uji.impor.bentrok@contoh.invalid'")->fetchColumn() === 0,
    'tidak ada baris bentrok yang masuk');

echo "\n=== E. Panel Kesehatan Data NIM ===\n";
[$c, $b] = kirim($sid, "$BASE/index.php?page=admin_alumni_import");
cek($c === 200 && strpos($b, 'Kesehatan Data NIM') !== false, 'panel tampil', "HTTP $c");
cek(strpos($b, 'Kunci unik pada kolom NIM sudah aman untuk dipasang') !== false
    || strpos($b, 'Tidak ada NIM ganda') !== false,
    'panel melaporkan data sudah bersih');

// ── Bersihkan ───────────────────────────────────────────────────────
$pdo->exec("DELETE FROM users WHERE email LIKE '%@contoh.invalid'");
@unlink("$dir/nim_bentrok.csv");
@unlink((session_save_path() ?: sys_get_temp_dir()) . '/sess_' . $sid);
cek((int)$pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE '%@contoh.invalid'")->fetchColumn() === 0,
    'data uji dibersihkan');

echo "\n────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
exit($fail ? 1 : 0);
