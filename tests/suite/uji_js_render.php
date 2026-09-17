<?php
/**
 * Sintaks JavaScript pada halaman HASIL RENDER, bukan pada sumbernya.
 *
 * ── Mengapa tests/lint_inline_js.php tidak cukup ───────────────────────
 * Pemeriksa statis mengganti setiap sisipan PHP dengan nilai contoh (0)
 * sebelum memeriksa skrip. Kerangkanya memang terperiksa, tetapi NILAI
 * yang sesungguhnya dicetak tidak pernah dilihat. Pola ini lolos di sana:
 *
 *     const zona = <?php echo e($zona_json); ?>;
 *
 * e() mengubah " menjadi &quot;, isi <script> tidak didekode sebagai HTML,
 * dan hasil render-nya adalah
 *
 *     const zona = [{&quot;label&quot;:"..."}];   -> SyntaxError
 *
 * Peramban membuang seluruh blok itu tanpa pesan. Empat halaman mati karena
 * ini — Pengajuan Legalisir (pratinjau biaya, pilihan kurir, validasi
 * berkas), Broadcast, Laporan Tracer, dan Konfigurasi Tracer — sementara
 * kedua linter melaporkan nol temuan.
 *
 * Suite ini merender setiap halaman di pages/ sebagai super admin, alumni,
 * dan tamu, lalu mengurai setiap skrip inline dengan mesin JavaScript yang
 * sama dengan peramban (V8, lewat Node). Satu proses Node untuk seluruh
 * skrip, supaya tidak memperlambat rangkaian uji.
 *
 * Halaman juga diperiksa bebas dari galat dan peringatan PHP.
 *
 * Membutuhkan Node.js. Bila tidak ada, suite ini melapor dan lulus kosong,
 * sama seperti tests/lint_inline_js.php.
 */
require_once __DIR__ . '/_bootstrap.php';

$BASE = uji_base_url();

// ── Node.js tersedia? ────────────────────────────────────────────────
$node = null;
foreach (['node', 'nodejs'] as $kandidat) {
    $v = @shell_exec(sprintf('%s --version 2>&1', escapeshellarg($kandidat)));
    if ($v !== null && preg_match('/^v?\d+\./', trim((string)$v))) {
        $node = $kandidat;
        break;
    }
}
if ($node === null) {
    echo "  Node.js tidak ditemukan di PATH — pemeriksaan dilewati.\n";
    echo "\n────────────────────────────────\n  LULUS: 0   GAGAL: 0\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/alumnilink_jsrender_' . bin2hex(random_bytes(4));
mkdir($tmp);

$uid_sa = $pdo->query("SELECT id FROM users WHERE role = 'super_admin' LIMIT 1")->fetchColumn();
$uid_al = $pdo->query("SELECT u.id FROM users u WHERE u.role = 'alumni' AND u.is_verified = 1
                        ORDER BY (SELECT COUNT(*) FROM legalisir_requests lr WHERE lr.user_id = u.id) DESC LIMIT 1")->fetchColumn();
$sesi = [
    'super_admin' => sesi_palsu($uid_sa, 'super_admin'),
    'alumni'      => sesi_palsu($uid_al, 'alumni'),
    'tamu'        => '',
];

register_shutdown_function(function () use ($sesi, $tmp) {
    sesi_hapus($sesi['super_admin'], $sesi['alumni']);
    foreach (glob("$tmp/*") ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmp);
});

// Halaman yang butuh parameter agar isinya benar-benar dirender.
$q = $pdo->prepare("SELECT id FROM legalisir_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
$q->execute([$uid_al]);
$param = [
    'legalisir_detail' => '&id=' . rawurlencode((string)$q->fetchColumn()),
    'donasi_detail'    => '&id=' . (int)$pdo->query("SELECT id FROM donation_campaigns ORDER BY id DESC LIMIT 1")->fetchColumn(),
];
try {
    $param['news_detail'] = '&id=' . (int)$pdo->query("SELECT id FROM news ORDER BY id DESC LIMIT 1")->fetchColumn();
} catch (PDOException $e) {
    // Tabel berita belum ada di basis data ini; halamannya tetap dirender.
}

$halaman = array_map(function ($f) { return basename($f, '.php'); }, glob(AKAR . '/pages/*.php'));
sort($halaman);

// ── 1. Render, simpan setiap skrip inline ke berkas ──────────────────
$daftar = [];   // berkas => label
$render = [];   // label halaman => [HTTP, jumlah skrip, peringatan PHP]
foreach ($sesi as $peran => $sid) {
    foreach ($halaman as $p) {
        $ch = curl_init("$BASE/index.php?page=$p" . ($param[$p] ?? ''));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_COOKIE => $sid ? "PHPSESSID=$sid" : '']);
        $b = (string)curl_exec($ch);
        $c = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($c !== 200) {
            continue;   // dialihkan atau ditolak: tidak ada yang dirender untuk peran ini
        }

        $php_galat = preg_match('/<b>(Fatal error|Parse error|Warning|Notice|Deprecated)<\/b>:|\b(Fatal error|Warning|Notice|Deprecated): .+ on line \d+/', $b, $mg)
            ? $mg[0] : '';

        preg_match_all('#<script\b([^>]*)>(.*?)</script\s*>#si', $b, $m);
        $n = 0;
        foreach ($m[2] as $i => $js) {
            $atribut = $m[1][$i];
            if (preg_match('#\bsrc\s*=#i', $atribut)) {
                continue;
            }
            // Hanya JavaScript klasik. JSON-LD, templat, dan modul punya aturan urai lain.
            if (preg_match('#\btype\s*=\s*["\']?([^"\'\s>]+)#i', $atribut, $tm)
                && !in_array(strtolower($tm[1]), ['text/javascript', 'application/javascript'], true)) {
                continue;
            }
            if (trim($js) === '') {
                continue;
            }
            $berkas = sprintf('%s/%s__%s__%d.js', $tmp, $peran, $p, $i);
            file_put_contents($berkas, $js);
            $daftar[$berkas] = "$peran/$p #$i";
            $n++;
        }
        $render["$peran/$p"] = [$c, $n, $php_galat];
    }
}

// ── 2. Urai seluruhnya dalam SATU proses Node ────────────────────────
$pemeriksa = "$tmp/_periksa.cjs";
file_put_contents($pemeriksa, <<<'JS'
const fs = require('fs');
const vm = require('vm');
const daftar = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const hasil = {};
for (const f of daftar) {
    try {
        new vm.Script(fs.readFileSync(f, 'utf8'), { filename: f });
        hasil[f] = null;
    } catch (e) {
        const baris = (e.stack || '').split('\n')[0].replace(/^.*:(\d+)$/, 'baris $1');
        hasil[f] = e.name + ': ' + e.message + ' (' + baris + ')';
    }
}
process.stdout.write(JSON.stringify(hasil));
JS
);
file_put_contents("$tmp/_daftar.json", json_encode(array_keys($daftar)));
$keluaran = shell_exec(sprintf('%s %s %s 2>&1', escapeshellarg($node),
    escapeshellarg($pemeriksa), escapeshellarg("$tmp/_daftar.json")));
$hasil = json_decode((string)$keluaran, true);
if (!is_array($hasil)) {
    cek(false, 'pemeriksa Node berjalan', substr((string)$keluaran, 0, 200));
    echo "\n────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
    exit(1);
}

// ── 3. Laporan per halaman ───────────────────────────────────────────
$galat_per_halaman = [];
foreach ($hasil as $berkas => $galat) {
    if ($galat !== null) {
        [$label] = explode(' #', $daftar[$berkas]);
        $galat_per_halaman[$label][] = substr($daftar[$berkas], strlen($label) + 1) . ' ' . $galat;
    }
}

$terakhir = '';
foreach ($render as $label => [$c, $n, $php_galat]) {
    [$peran] = explode('/', $label);
    if ($peran !== $terakhir) {
        echo "\n[$peran]\n";
        $terakhir = $peran;
    }
    $js_galat = $galat_per_halaman[$label] ?? [];
    cek(!$js_galat && $php_galat === '', $label,
        "$n skrip" . ($js_galat ? ' | ' . implode(' | ', $js_galat) : '')
        . ($php_galat !== '' ? ' | PHP: ' . substr(strip_tags($php_galat), 0, 80) : ''));
}

printf("\n  %d halaman dirender, %d skrip inline diurai.\n", count($render), count($daftar));
echo "\n────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
exit($fail ? 1 : 0);
