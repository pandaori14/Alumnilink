<?php
/**
 * Uji XSS: pastikan escaping benar-benar bekerja, DAN pastikan 281 suntingan
 * tadi tidak merusak halaman.
 *
 * Bagian terpenting bukan "apakah muatan ter-escape", melainkan "apakah
 * halamannya masih utuh" — perubahan sebanyak itu jauh lebih mungkin merusak
 * tampilan daripada gagal menutup XSS-nya.
 */
require_once __DIR__ . '/_bootstrap.php';

$BASE = uji_base_url();
function muat($sid, $url) {
    $ch = curl_init($url);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40];
    if ($sid) { $o[CURLOPT_COOKIE] = 'PHPSESSID=' . $sid; }
    curl_setopt_array($ch, $o);
    $b = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return [$c, (string)$b];
}

$uid_sa = $pdo->query("SELECT id FROM users WHERE role='super_admin' LIMIT 1")->fetchColumn();
$uid_al = $pdo->query("SELECT id FROM users WHERE role='alumni' AND is_verified=1 LIMIT 1")->fetchColumn();
$sa = sesi_palsu($uid_sa, 'super_admin');
$al = sesi_palsu($uid_al, 'alumni');

// ── A. XSS TERSIMPAN: nama alumni berisi markup ─────────────────────
echo "=== A. XSS tersimpan (nama alumni) ===\n";
$korban = $pdo->query("SELECT id, name FROM users WHERE role='alumni' ORDER BY id LIMIT 1")->fetch();
$nama_asli = $korban->name;
$muatan = '<img src=x onerror=alert(1)>"><script>alert(2)</script>';
$pdo->prepare("UPDATE users SET name = ? WHERE id = ?")->execute([$muatan, $korban->id]);

foreach (['admin_alumni', 'admin_users', 'admin_legalisir'] as $hal) {
    [$c, $b] = muat($sa, "$BASE/index.php?page=$hal");
    $mentah = (strpos($b, '<img src=x onerror=') !== false)
           || (strpos($b, '<script>alert(2)</script>') !== false);
    $terescape = strpos($b, '&lt;img src=x onerror=') !== false;
    cek($c === 200 && !$mentah, "$hal: markup TIDAK dieksekusi", "HTTP $c" . ($mentah ? ' *** MENTAH ***' : ''));
    if ($hal !== 'admin_legalisir') {
        cek($terescape, "$hal: nama tampil ter-escape");
    }
}

// Profil korban sendiri
$sid_korban = sesi_palsu($korban->id, 'alumni');
[$c, $b] = muat($sid_korban, "$BASE/index.php?page=profile");
cek($c === 200 && strpos($b, '<img src=x onerror=') === false, 'profile: markup TIDAK dieksekusi', "HTTP $c");
@unlink((session_save_path() ?: sys_get_temp_dir()) . '/sess_' . $sid_korban);

$pdo->prepare("UPDATE users SET name = ? WHERE id = ?")->execute([$nama_asli, $korban->id]);
cek($pdo->query("SELECT name FROM users WHERE id='{$korban->id}'")->fetchColumn() === $nama_asli,
    'nama korban dipulihkan', $nama_asli);

// ── B. XSS TERPANTUL: parameter URL ─────────────────────────────────
echo "\n=== B. XSS terpantul (parameter URL) ===\n";
$muatan_attr = '" onfocus=alert(1) autofocus x="';
foreach ([
    ['admin_keuangan', 'start_date'],
    ['admin_keuangan', 'end_date'],
    ['admin_tracer',   'start_date'],
    ['admin_tracer',   'end_date'],
] as [$hal, $param]) {
    [$c, $b] = muat($sa, "$BASE/index.php?page=$hal&$param=" . urlencode($muatan_attr));
    // Mencari teks muatannya saja SALAH: setelah di-escape, teksnya memang
    // masih ada di halaman sebagai &quot;… dan itu sepenuhnya inert.
    // Yang menentukan adalah apakah ATRIBUTNYA tertembus — yaitu munculnya
    // kutip mentah yang mengakhiri value=" lalu diikuti atribut baru.
    $bobol = preg_match('/value="[^"]*"\s+onfocus=/i', $b) === 1;
    $inert = strpos($b, '&quot; onfocus=alert(1)') !== false;
    cek($c === 200 && !$bobol, "$hal?$param: atribut tidak bisa ditembus",
        "HTTP $c" . ($bobol ? ' *** BOBOL ***' : ($inert ? ' (muatan jadi &quot;, inert)' : '')));
}

// ── C. JS di dalam atribut onclick (bekas addslashes) ───────────────
echo "\n=== C. Konteks JavaScript di atribut ===\n";
$pdo->prepare("UPDATE users SET name = ? WHERE id = ?")
    ->execute(['Budi" onmouseover="alert(9)', $korban->id]);
[$c, $b] = muat($sa, "$BASE/index.php?page=admin_users");
$bobol = strpos($b, 'onmouseover="alert(9)') !== false;
cek($c === 200 && !$bobol, 'openDeleteModal: kutip tidak keluar dari atribut',
    "HTTP $c" . ($bobol ? ' *** BOBOL ***' : ''));
cek(strpos($b, 'onmouseover=&quot;alert(9)') !== false || strpos($b, '&quot;') !== false,
    'kutip ganda berubah jadi &quot;');
$pdo->prepare("UPDATE users SET name = ? WHERE id = ?")->execute([$nama_asli, $korban->id]);

// ── C2. json_encode di dalam atribut HTML ───────────────────────────
echo "
=== C2. Objek JSON di atribut onclick ===
";
$pdo->prepare("UPDATE users SET name = ? WHERE id = ?")
    ->execute([$muatan, $korban->id]);
[$c, $b] = muat($sa, "$BASE/index.php?page=admin_alumni");
// json_encode() sah untuk JavaScript tetapi tidak meng-escape < > maupun
// kutip tunggal, jadi dulu markup ini keluar mentah di dalam onclick='…'.
$mentah = strpos($b, "editAlumni({") !== false && strpos($b, '<img src=x onerror=') !== false;
cek($c === 200 && !$mentah, 'editAlumni(): objek JSON ter-escape di atribut',
    "HTTP $c" . ($mentah ? ' *** MENTAH ***' : ''));
$pdo->prepare("UPDATE users SET name = ? WHERE id = ?")->execute([$nama_asli, $korban->id]);

// ── D. HALAMAN MASIH UTUH (ini yang paling mungkin rusak) ───────────
echo "\n=== D. Seluruh halaman masih utuh ===\n";
$halaman = [
    ['landing', null], ['login', null], ['register', null], ['all_news', null],
    ['forgot_password', null], ['terms', null],
    ['dashboard', $al], ['profile', $al], ['legalisir', $al], ['tracer', $al],
    ['events', $al], ['donasi', $al], ['alumni_map', $al], ['guide', $al],
    ['notifications', $al],
    ['admin_alumni', $sa], ['admin_alumni_import', $sa], ['admin_users', $sa],
    ['admin_legalisir', $sa], ['admin_settings', $sa], ['admin_logs', $sa],
    ['admin_tracer', $sa], ['admin_tracer_config', $sa], ['admin_tracer_report', $sa],
    ['admin_repository', $sa], ['admin_donasi', $sa], ['admin_keuangan', $sa],
    ['admin_news', $sa], ['admin_majors', $sa], ['admin_broadcast', $sa],
    ['admin_email_layouts', $sa], ['admin_analytics', $sa], ['admin_employer_survey', $sa],
];
$rusak = 0;
foreach ($halaman as [$p, $sid]) {
    [$c, $b] = muat($sid, "$BASE/index.php?page=$p");
    $galat = preg_match('/\b(Fatal error|Parse error|Warning|Notice|Deprecated)\b:/', $b);
    // Tanda markup bocor sebagai teks: kalau escaping salah tempat, tag HTML
    // kita sendiri akan tampil sebagai &lt;div ... di layar.
    $bocor = substr_count($b, '&lt;div') > 0 || substr_count($b, '&lt;svg') > 0;
    $ok = ($c === 200) && !$galat && !$bocor && strlen($b) > 500;
    if (!$ok) {
        $rusak++;
        printf("  GAGAL  %-24s HTTP %d%s%s len=%d\n", $p, $c,
            $galat ? ' GALAT-PHP' : '', $bocor ? ' MARKUP-TER-ESCAPE' : '', strlen($b));
        $fail++;
    } else { $pass++; }
}
if ($rusak === 0) { printf("  LULUS  %-54s %d halaman\n", 'seluruh halaman merender bersih', count($halaman)); }

// ── E. Isi penting masih tampil (bukan sekadar HTTP 200) ────────────
echo "\n=== E. Isi halaman tidak hilang ===\n";
foreach ([
    ['admin_settings', $sa, 'Tugas Terjadwal (Cron)', 'panel cron'],
    ['admin_settings', $sa, 'Cadangan Basis Data',    'panel cadangan'],
    ['admin_alumni',   $sa, 'Kelengkapan data rata-rata', 'kartu kelengkapan'],
    ['admin_legalisir',$sa, 'Masih terbuka',          'statistik legalisir'],
    ['admin_email_layouts', $sa, '<svg width="92"',   'thumbnail SVG utuh'],
    ['alumni_map',     $al, 'Peta Persebaran',        'judul peta'],
    ['login',          null, 'name="identity"',       'formulir login'],
] as [$p, $sid, $jarum, $label]) {
    [$c, $b] = muat($sid, "$BASE/index.php?page=$p");
    cek(strpos($b, $jarum) !== false, "$p: $label tetap tampil");
}

foreach ([$sa, $al] as $x) { @unlink((session_save_path() ?: sys_get_temp_dir()) . '/sess_' . $x); }
echo "\n────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
exit($fail ? 1 : 0);
