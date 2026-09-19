<?php
/**
 * Tombol yang diklik SUNGGUHAN di peramban — bukan hanya dirender.
 *
 * ── Mengapa ini perlu ada ──────────────────────────────────────────────
 * 18 September 2026 tombol lonceng notifikasi ditemukan mati total: blok
 * skripnya berada di dalam <head>, sedangkan tombol dan panelnya baru muncul
 * di <body> ratusan baris di bawahnya. Saat skrip dijalankan,
 * getElementById mengembalikan null, penjaga `if (!btn || !panel) return;`
 * keluar diam-diam, dan tidak ada satu pun pendengar yang terpasang.
 *
 * Tidak ada yang menangkapnya:
 *   - PHP tidak error, HTML-nya lengkap;
 *   - uji_render melihat tombolnya ada;
 *   - uji_js_render mengurai skripnya dan sintaksnya sah;
 *   - uji_responsif membuka halamannya tanpa exception;
 *   - konsol peramban bersih, karena memang tidak ada yang gagal —
 *     hanya tidak ada yang terjadi.
 *
 * Satu-satunya cara membuktikannya adalah menekan tombolnya dan melihat
 * apakah sesuatu berubah. Itulah yang dilakukan suite ini.
 *
 * Membutuhkan Chrome/Chromium dan Node. Bila tidak ada, suite ini melapor
 * dan lulus kosong — sama seperti uji_responsif.
 */
require_once __DIR__ . '/_bootstrap.php';

$BASE = uji_base_url();

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
foreach (array_filter([
    getenv('ALUMNILINK_CHROME') ?: null,
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
]) as $c) {
    if (@is_file($c)) {
        $chrome = $c;
        break;
    }
}
if ($node === null || $chrome === null) {
    printf("  %s tidak ditemukan — pemeriksaan interaksi dilewati.\n",
        $node === null ? 'Node.js' : 'Chrome/Chromium');
    echo "\n────────────────────────────────\n  LULUS: 0   GAGAL: 0\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/alumnilink_interaksi_' . getmypid();
$profil = $tmp . '/profil';
@mkdir($profil, 0777, true);

// ── Data dan sesi uji ────────────────────────────────────────────────
$uid_sa = $pdo->query("SELECT id FROM users WHERE role = 'super_admin' LIMIT 1")->fetchColumn();
$sesi = ['super_admin' => sesi_palsu($uid_sa, 'super_admin')];

$pdo->prepare("INSERT INTO notifications (user_id, title, message, type, link, is_read)
               VALUES (?, 'UJIINTERAKSI', 'Isi panel notifikasi', 'info', 'index.php?page=dashboard', 0)")
    ->execute([$uid_sa]);
$id_notif = (int)$pdo->lastInsertId();

register_shutdown_function(function () use ($pdo, $id_notif, $sesi, $tmp) {
    $pdo->prepare("DELETE FROM notifications WHERE id = ?")->execute([$id_notif]);
    sesi_hapus(...array_values($sesi));
    $buang = function ($d) use (&$buang) {
        foreach (glob($d . '/*') ?: [] as $f) { is_dir($f) ? $buang($f) : @unlink($f); }
        @rmdir($d);
    };
    $buang($tmp);
});

// ── Langkah: klik, lalu buktikan sesuatu berubah ─────────────────────
$langkah = [
    [
        'nama'   => 'lonceng notifikasi membuka panelnya',
        'peran'  => 'super_admin',
        'url'    => 'index.php?page=dashboard',
        'klik'   => '#notifBtn',
        'nilai'  => "(() => { const p = document.getElementById('notifPanel');"
                  . " const l = document.getElementById('notifPanelList');"
                  . " return { terbuka: p && !p.classList.contains('hidden'),"
                  . " tinggi: p ? Math.round(p.getBoundingClientRect().height) : 0,"
                  . " item: l ? l.querySelectorAll('.notif-item').length : 0,"
                  . " teks: l ? l.innerText.replace(/\\s+/g, ' ').slice(0, 80) : '' }; })()",
    ],
    [
        'nama'   => 'panel notifikasi memuat isinya dari API',
        'peran'  => 'super_admin',
        'url'    => 'index.php?page=admin_keuangan',
        'klik'   => '#notifBtn',
        'nilai'  => "(() => { const l = document.getElementById('notifPanelList');"
                  . " return { item: l ? l.querySelectorAll('.notif-item').length : 0,"
                  . " masihMemuat: l ? /Memuat/.test(l.innerText) : true }; })()",
    ],
    [
        'nama'   => 'pindah bagian di Konfigurasi Sistem',
        'peran'  => 'super_admin',
        'url'    => 'index.php?page=admin_settings',
        'klik'   => '#btn-tab-layanan',
        'nilai'  => "(() => { const b = document.querySelector('.tab-layanan');"
                  . " return { tampil: b ? b.getBoundingClientRect().height > 0 : false }; })()",
    ],
];

$konfig = $tmp . '/konfig.json';
file_put_contents($konfig, json_encode(['base' => $BASE, 'sesi' => $sesi, 'langkah' => $langkah]));

// ── Jalankan Chrome headless ─────────────────────────────────────────
// Ketiga deskriptor ke berkas: Chrome yang mewarisi pipa keluaran membuat
// tests/run_all.php menunggu sampai Chrome mati. Lihat uji_responsif.
$nul = stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null';
$proses = proc_open(sprintf('%s --headless=new --remote-debugging-port=0 --user-data-dir=%s'
    . ' --no-first-run --no-default-browser-check --disable-gpu --disable-extensions'
    . ' --disable-background-networking --disable-component-update --disable-sync about:blank',
    escapeshellarg($chrome), escapeshellarg($profil)),
    [0 => ['file', $nul, 'r'], 1 => ['file', $tmp . '/chrome.log', 'a'], 2 => ['file', $tmp . '/chrome.log', 'a']],
    $pipa);
if (!is_resource($proses)) {
    cek(false, 'Chrome dapat dijalankan');
    echo "\n────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
    exit(1);
}
register_shutdown_function(function () use ($proses) {
    $info = @proc_get_status($proses);
    if (!empty($info['running'])) {
        @proc_terminate($proses);
        if (!empty($info['pid']) && stripos(PHP_OS, 'WIN') === 0) {
            @exec('taskkill /T /F /PID ' . (int)$info['pid'] . ' 2>NUL');
        }
    }
    @proc_close($proses);
});

// Port dibaca dari berkas yang ditulis Chrome sendiri, bukan dengan
// mencoba menyambung berulang kali. Lihat catatan di uji_responsif.
$port = 0;
for ($i = 0; $i < 200; $i++) {
    if (@is_file($profil . '/DevToolsActivePort')) {
        $baris = explode("\n", trim((string)@file_get_contents($profil . '/DevToolsActivePort')));
        if (ctype_digit(trim($baris[0] ?? ''))) {
            $port = (int)trim($baris[0]);
            break;
        }
    }
    usleep(100000);
}
cek($port > 0, 'Chrome headless siap menerima perintah', "port $port");
if (!$port) {
    echo "\n  Keluaran Chrome:\n" . substr((string)@file_get_contents($tmp . '/chrome.log'), 0, 400) . "\n";
    echo "\n────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
    exit(1);
}

$keluaran = shell_exec(sprintf('%s %s %d %s 2>&1', escapeshellarg($node),
    escapeshellarg(AKAR . '/tests/interaksi_cdp.mjs'), $port, escapeshellarg($konfig)));
$teks = trim((string)$keluaran);
$hasil = json_decode(substr($teks, strpos($teks, '[') ?: 0), true);
if (!is_array($hasil) || !$hasil) {
    cek(false, 'pemeriksa interaksi berjalan', substr($teks, 0, 300));
    echo "\n────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
    exit(1);
}

// ── Penilaian ────────────────────────────────────────────────────────
$peta = [];
foreach ($hasil as $h) { $peta[$h['nama']] = $h; }

foreach ($hasil as $h) {
    $k = $h['klik'] ?? [];
    cek(!empty($k['ada']), 'ada elemennya: ' . $h['nama']);
    cek(empty($k['tertutup']), 'dapat ditekan pengguna (tidak tertutup elemen lain)', $h['nama']);
    cek(empty($h['galatJs']), 'tanpa exception JavaScript', implode(' | ', $h['galatJs'] ?? []));
}

$a = $peta['lonceng notifikasi membuka panelnya']['nilai'] ?? [];
cek(!empty($a['terbuka']), 'PANEL NOTIFIKASI TERBUKA setelah lonceng diklik',
    'tinggi ' . ($a['tinggi'] ?? '-') . 'px');
cek((int)($a['tinggi'] ?? 0) > 50, 'panelnya benar-benar terlihat, bukan hanya kehilangan kelas hidden',
    ($a['tinggi'] ?? '-') . 'px');

$b = $peta['panel notifikasi memuat isinya dari API']['nilai'] ?? [];
cek((int)($b['item'] ?? 0) > 0, 'isi panel diambil dari API, bukan teks "Memuat…" yang statis',
    ($b['item'] ?? 0) . ' item');
cek(empty($b['masihMemuat']), 'placeholder "Memuat…" sudah diganti isinya');

$c = $peta['pindah bagian di Konfigurasi Sistem']['nilai'] ?? [];
cek(!empty($c['tampil']), 'bagian Layanan & Biaya tampil setelah tombolnya diklik');

printf("\n  %d langkah interaksi dijalankan di Chrome.\n", count($hasil));
echo "\n────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
exit($fail ? 1 : 0);
