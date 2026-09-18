<?php
/**
 * Tata letak di berbagai ukuran layar — diperiksa di peramban sungguhan.
 *
 * ── Mengapa ini perlu diuji, bukan dipercaya ───────────────────────────
 * Kelas responsif Tailwind (md:, lg:) mudah ditulis dan mudah salah: satu
 * tabel tanpa pembungkus overflow, satu min-width, atau satu grid yang lupa
 * dipecah, dan halaman jadi dapat digeser ke samping di ponsel. Tidak ada
 * galat, tidak ada catatan di log — hanya pengalaman yang buruk, dan hanya
 * terlihat oleh yang membukanya dari ponsel.
 *
 * Yang dinilai adalah hal yang benar-benar dirasakan pengguna: halaman
 * TIDAK boleh dapat digeser mendatar. Daftar elemen yang melewati tepi
 * hanya dikumpulkan sebagai petunjuk bila itu terjadi. Tabel dan blok yang
 * memang dibungkus overflow-x auto dikecualikan — menggulir tabel lebar
 * secara sengaja itu benar.
 *
 * Sekaligus diperiksa: ruang bawah pada mobile, supaya isi halaman tidak
 * tertutup menu melayang di bawah; dan nol exception JavaScript.
 *
 * Membutuhkan Chrome/Chromium. Bila tidak ada, suite ini melapor dan lulus
 * kosong — sama seperti tests/lint_inline_js.php terhadap Node.
 *
 * Chrome dapat ditunjuk lewat variabel lingkungan ALUMNILINK_CHROME.
 */
require_once __DIR__ . '/_bootstrap.php';

$BASE = uji_base_url();
$MULAI = microtime(true);
$jeda = function ($apa) use (&$MULAI) { printf("  [%5.1fs] %s\n", microtime(true) - $MULAI, $apa); };

// ── Chrome dan Node tersedia? ────────────────────────────────────────
$node = null;
foreach (['node', 'nodejs'] as $kandidat) {
    $v = @shell_exec(sprintf('%s --version 2>&1', escapeshellarg($kandidat)));
    if ($v !== null && preg_match('/^v?\d+\./', trim((string)$v))) {
        $node = $kandidat;
        break;
    }
}

$chrome = null;
$kandidat_chrome = array_filter([
    getenv('ALUMNILINK_CHROME') ?: null,
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
]);
foreach ($kandidat_chrome as $c) {
    if (@is_file($c)) {
        $chrome = $c;
        break;
    }
}

if ($node === null || $chrome === null) {
    printf("  %s tidak ditemukan — pemeriksaan tata letak dilewati.\n",
        $node === null ? 'Node.js' : 'Chrome/Chromium');
    echo "  Ini bukan kegagalan: pemeriksa ini hanya berjalan di mesin yang\n";
    echo "  memasang keduanya. Tunjuk Chrome lewat ALUMNILINK_CHROME bila perlu.\n";
    echo "\n────────────────────────────────\n  LULUS: 0   GAGAL: 0\n";
    exit(0);
}

$jeda('Chrome dan Node ditemukan');

// ── Sesi dan halaman yang diperiksa ──────────────────────────────────
$uid_sa = $pdo->query("SELECT id FROM users WHERE role = 'super_admin' LIMIT 1")->fetchColumn();
$uid_al = $pdo->query("SELECT u.id FROM users u WHERE u.role = 'alumni' AND u.is_verified = 1
                        ORDER BY (SELECT COUNT(*) FROM legalisir_requests lr WHERE lr.user_id = u.id) DESC LIMIT 1")->fetchColumn();
$sesi_sa = sesi_palsu($uid_sa, 'super_admin');
$sesi_al = sesi_palsu($uid_al, 'alumni');

$q = $pdo->prepare("SELECT id FROM legalisir_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
$q->execute([$uid_al]);
$id_leg = (string)$q->fetchColumn();
$id_kamp = (int)$pdo->query("SELECT id FROM donation_campaigns ORDER BY id DESC LIMIT 1")->fetchColumn();

$tmp = sys_get_temp_dir() . '/alumnilink_responsif_' . bin2hex(random_bytes(4));
mkdir($tmp);
$profil = $tmp . '/profil';

register_shutdown_function(function () use ($sesi_sa, $sesi_al, $tmp) {
    sesi_hapus($sesi_sa, $sesi_al);
    $it = @new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it ?: [] as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($tmp);
});

$halaman = [
    ['alumni', $sesi_al, 'index.php?page=dashboard'],
    ['alumni', $sesi_al, 'index.php?page=legalisir'],
    ['alumni', $sesi_al, 'index.php?page=legalisir_detail&id=' . rawurlencode($id_leg)],
    ['alumni', $sesi_al, 'index.php?page=donasi'],
    ['alumni', $sesi_al, 'index.php?page=donasi_detail&id=' . $id_kamp],
    ['alumni', $sesi_al, 'index.php?page=profile'],
    ['super_admin', $sesi_sa, 'index.php?page=admin_payment_gateway'],
    ['super_admin', $sesi_sa, 'index.php?page=admin_legalisir'],
    ['super_admin', $sesi_sa, 'index.php?page=admin_keuangan'],
    ['super_admin', $sesi_sa, 'index.php?page=admin_settings'],
    ['tamu', '', 'index.php?page=landing'],
    ['tamu', '', 'index.php?page=login'],
];

$layar = [
    ['nama' => 'ponsel', 'w' => 360, 'h' => 780, 'mobile' => true],
    ['nama' => 'tablet', 'w' => 768, 'h' => 1024, 'mobile' => true],
    ['nama' => 'laptop', 'w' => 1280, 'h' => 800, 'mobile' => false],
];

$konfig = $tmp . '/konfig.json';
file_put_contents($konfig, json_encode(['base' => $BASE, 'layar' => $layar, 'halaman' => $halaman]));

// ── Jalankan Chrome headless ─────────────────────────────────────────
// Port 0 = Chrome memilih port bebas sendiri dan menuliskannya ke
// DevToolsActivePort di dalam user-data-dir.
$perintah = sprintf('%s --headless=new --remote-debugging-port=0 --user-data-dir=%s'
    . ' --no-first-run --no-default-browser-check --disable-gpu --disable-extensions'
    . ' --disable-background-networking --disable-component-update --disable-sync about:blank',
    escapeshellarg($chrome), escapeshellarg($profil));

// Ketiga deskriptor diarahkan ke berkas, TERMASUK stdin. Bila Chrome
// mewarisi pipa keluaran milik pemanggil (tests/run_all.php menangkap
// keluaran tiap langkah), pemanggil menunggu sampai Chrome mati — dan
// seluruh rangkaian uji ikut menggantung.
$nul = stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null';
$deskriptor = [
    0 => ['file', $nul, 'r'],
    1 => ['file', $tmp . '/chrome.log', 'a'],
    2 => ['file', $tmp . '/chrome.log', 'a'],
];
$jeda('menjalankan Chrome');
$proses = proc_open($perintah, $deskriptor, $pipa);
if (!is_resource($proses)) {
    cek(false, 'Chrome dapat dijalankan', $perintah);
    echo "\n────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
    exit(1);
}
register_shutdown_function(function () use ($proses) {
    // Jalur normalnya: tests/responsif_cdp.mjs menutup peramban sendiri
    // lewat perintah Browser.close. Ini jaring pengaman bila pemeriksanya
    // gagal di tengah — Chrome yang tertinggal hidup akan menahan pipa
    // keluaran dan membuat tests/run_all.php menunggu selamanya.
    $info = @proc_get_status($proses);
    if (!empty($info['running'])) {
        @proc_terminate($proses);
        if (!empty($info['pid']) && stripos(PHP_OS, 'WIN') === 0) {
            @exec('taskkill /T /F /PID ' . (int)$info['pid'] . ' 2>NUL');
        }
    }
    @proc_close($proses);
});

// Kesiapan dibaca dari berkas DevToolsActivePort yang ditulis Chrome
// sendiri, BUKAN dengan mencoba menyambung ke port berulang kali.
//
// Menyambung ke port yang belum siap di Windows tidak selalu dibalas
// "connection refused": kadang paketnya hilang begitu saja, fsockopen
// mengabaikan timeout 0,2 detik yang diminta, dan satu percobaan menggantung
// sekitar dua menit (galat 10060). Seluruh waktu suite ini dulu habis di
// situ. Berkas itu juga menghapus kemungkinan bentrok port, karena Chrome
// dijalankan dengan port 0 dan menuliskan port yang benar-benar dipakainya.
$berkas_port = $profil . '/DevToolsActivePort';
$port = 0;
for ($i = 0; $i < 200; $i++) {
    if (@is_file($berkas_port)) {
        $isi = trim((string)@file_get_contents($berkas_port));
        $baris_port = explode("\n", $isi);
        if (ctype_digit(trim($baris_port[0] ?? ''))) {
            $port = (int)trim($baris_port[0]);
            break;
        }
    }
    usleep(100000);
}
$siap = $port > 0 && @file_get_contents("http://127.0.0.1:$port/json/version",
    false, stream_context_create(['http' => ['timeout' => 5]])) !== false;
$jeda('Chrome siap');
cek($siap, 'Chrome headless siap menerima perintah', "port $port");
if (!$siap) {
    echo "\n  Keluaran Chrome:\n" . substr((string)@file_get_contents($tmp . '/chrome.log'), 0, 500) . "\n";
    echo "\n────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
    exit(1);
}

$jeda('mulai memeriksa halaman');
$keluaran = shell_exec(sprintf('%s %s %d %s 2>&1', escapeshellarg($node),
    escapeshellarg(AKAR . '/tests/responsif_cdp.mjs'), $port, escapeshellarg($konfig)));
$jeda('selesai memeriksa halaman');
$baris = trim((string)$keluaran);
$hasil = json_decode(substr($baris, strpos($baris, '[') ?: 0), true);

if (!is_array($hasil) || !$hasil) {
    cek(false, 'pemeriksa tata letak berjalan', substr($baris, 0, 300));
    echo "\n────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
    exit(1);
}

// ── Laporan ──────────────────────────────────────────────────────────
$layar_kini = '';
foreach ($hasil as $h) {
    if ($h['layar'] !== $layar_kini) {
        printf("\n[%s — %dpx]\n", $h['layar'], $h['lebar']);
        $layar_kini = $h['layar'];
    }
    $geser = (int)($h['geser'] ?? 0);
    $galat = $h['galatJs'] ?? [];
    $ok = $geser <= 1 && !$galat && empty($h['galat']);
    cek($ok, $h['peran'] . '/' . $h['halaman'],
        $ok ? 'tidak dapat digeser ke samping'
            : trim(($geser > 1 ? "geser mendatar +{$geser}px " . implode(', ', $h['luber'] ?? []) : '')
                 . ' ' . implode(' | ', $galat) . ' ' . ($h['galat'] ?? '')));
}

// Isi halaman di mobile tidak boleh tertutup menu melayang di bawah.
$ruang = null;
foreach ($hasil as $h) {
    if (!empty($h['mobile']) && $h['peran'] !== 'tamu' && isset($h['ruangBawah'])) {
        $ruang = (int)$h['ruangBawah'];
        break;
    }
}
echo "\n[ruang bawah mobile]\n";
cek($ruang !== null && $ruang >= 80, 'isi halaman tidak tertutup menu bawah', $ruang . 'px padding-bottom');

printf("\n  %d kombinasi halaman x layar diperiksa di Chrome.\n", count($hasil));
echo "\n────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
exit($fail ? 1 : 0);
