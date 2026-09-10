<?php
/**
 * Penjalan seluruh pemeriksaan AlumniLink.
 *
 *     php tests/run_all.php [base-url]
 *     php tests/run_all.php https://apps-kedokteran.ums.ac.id/alumnilink
 *
 * Menjalankan lint statis, uji asap, dan seluruh suite di tests/suite/,
 * lalu mengembalikan kode keluar bukan-nol bila ada yang gagal — sehingga
 * bisa dipakai sebagai gerbang sebelum upload, bukan sekadar dibaca.
 *
 * ── Mengapa suite-nya ada di repo ──────────────────────────────────────
 * Kedua belas suite di tests/suite/ semula ditulis di direktori sementara
 * dan akan terhapus bersama sesinya. Di dalamnya tersimpan seluruh
 * pengetahuan tentang cara sistem ini pernah rusak: alamat rumah yang
 * bocor ke sesama alumni, KPI akreditasi yang permanen 0%, pekerja antrean
 * e-mail yang mati sebelum berjalan, tag PHP liar yang membocorkan kode
 * sumber, addslashes yang disangka proteksi XSS. Setiap suite adalah
 * catatan satu kesalahan yang tidak boleh terulang.
 *
 * PERINGATAN: uji ini MENULIS ke basis data yang ditunjuk (membuat lalu
 * menghapus data uji). Jangan pernah diarahkan ke basis data produksi.
 */

// Perlindungan berlapis: uji ini tidak boleh dijalankan lewat web.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("CLI only.\n");
}

$akar = dirname(__DIR__);
$base = $argv[1] ?? (getenv('ALUMNILINK_URL') ?: 'http://localhost/alumnilink');
$base = rtrim($base, '/');

// ── Pagar produksi ───────────────────────────────────────────────────
// Menjalankan ini terhadap server sungguhan akan membuat lalu menghapus
// pengguna, pengajuan legalisir, dan berita. Konfirmasi dituntut eksplisit.
if (!preg_match('#^https?://(localhost|127\.0\.0\.1|\[::1\])#i', $base)
    && !in_array('--saya-yakin-ini-bukan-produksi', $argv, true)) {
    fwrite(STDERR,
        "\n  BERHENTI: $base bukan alamat lokal.\n\n" .
        "  Uji ini MEMBUAT DAN MENGHAPUS data (pengguna, pengajuan legalisir,\n" .
        "  berita) pada basis data yang dipakai alamat itu.\n\n" .
        "  Bila Anda benar-benar bermaksud demikian, ulangi dengan:\n" .
        "      php tests/run_all.php $base --saya-yakin-ini-bukan-produksi\n\n");
    exit(2);
}

$mulai = microtime(true);
$php   = escapeshellarg(PHP_BINARY);

/** Satu langkah pemeriksaan. */
function langkah($label, $perintah, &$rekap)
{
    $keluaran = [];
    $kode = 0;
    exec($perintah . ' 2>&1', $keluaran, $kode);
    $teks = implode("\n", $keluaran);

    // Ambil angka LULUS/GAGAL bila formatnya dikenali.
    $lulus = $gagal = null;
    if (preg_match('/LULUS:\s*(\d+)\s+GAGAL:\s*(\d+)/', $teks, $m)) {
        $lulus = (int)$m[1];
        $gagal = (int)$m[2];
    }

    $ok = ($kode === 0) && ($gagal === null || $gagal === 0);
    $rekap[] = ['label' => $label, 'ok' => $ok, 'lulus' => $lulus,
                'gagal' => $gagal, 'kode' => $kode, 'teks' => $teks];

    printf("  %s  %-26s %s\n",
        $ok ? '  OK  ' : ' GAGAL',
        $label,
        $lulus !== null ? sprintf('%d lulus, %d gagal', $lulus, $gagal)
                        : ($ok ? 'bersih' : "exit=$kode"));

    // Hanya yang gagal yang dicetak isinya — keluaran lengkap seluruh suite
    // terlalu panjang untuk dibaca, dan yang penting justru yang merah.
    if (!$ok) {
        foreach ($keluaran as $baris) {
            if (stripos($baris, 'GAGAL') !== false || stripos($baris, '***') !== false
                || stripos($baris, 'error') !== false) {
                echo "          " . trim($baris) . "\n";
            }
        }
    }
    return $ok;
}

$rekap = [];

echo "\n";
echo "  AlumniLink — seluruh pemeriksaan\n";
echo "  Target: $base\n";
echo "  " . str_repeat('─', 62) . "\n\n";

// ── 1. Lint statis (tidak butuh server maupun basis data) ────────────
echo "  Lint statis\n";
langkah('sintaks seluruh berkas', $php . ' ' . escapeshellarg(__DIR__ . '/lint_syntax.php'), $rekap);
langkah('kode bocor sebagai teks', $php . ' ' . escapeshellarg(__DIR__ . '/lint_leaked_php.php'), $rekap);
langkah('keluaran tanpa escape',  $php . ' ' . escapeshellarg(__DIR__ . '/lint_unescaped.php'), $rekap);
// Membutuhkan Node.js. Bila tidak ada, pemeriksanya keluar dengan kode 0
// dan melapor bahwa ia dilewati, jadi rangkaian ini tidak ikut gagal.
langkah('sintaks JavaScript inline', $php . ' ' . escapeshellarg(__DIR__ . '/lint_inline_js.php'), $rekap);
langkah('versi dependensi',       $php . ' ' . escapeshellarg(__DIR__ . '/check_versions.php'), $rekap);

// ── 2. Uji asap ──────────────────────────────────────────────────────
echo "\n  Uji asap\n";
langkah('smoke (--with-db)', $php . ' ' . escapeshellarg(__DIR__ . '/smoke.php')
    . ' ' . escapeshellarg($base) . ' --with-db', $rekap);

// ── 3. Suite ─────────────────────────────────────────────────────────
echo "\n  Suite\n";
$suite = glob(__DIR__ . '/suite/*.php') ?: [];
sort($suite);
foreach ($suite as $s) {
    $nama = basename($s, '.php');
    if ($nama === '_bootstrap') {
        continue;
    }
    langkah($nama, $php . ' ' . escapeshellarg($s) . ' ' . escapeshellarg($base), $rekap);
}

// ── Ringkasan ────────────────────────────────────────────────────────
$total_lulus = 0;
$total_gagal = 0;
$langkah_gagal = [];
foreach ($rekap as $r) {
    $total_lulus += (int)$r['lulus'];
    $total_gagal += (int)$r['gagal'];
    if (!$r['ok']) { $langkah_gagal[] = $r['label']; }
}
$detik = round(microtime(true) - $mulai, 1);

echo "\n  " . str_repeat('─', 62) . "\n";
printf("  %d langkah · %d pemeriksaan lulus · %d gagal · %ss\n",
    count($rekap), $total_lulus, $total_gagal, $detik);

if ($langkah_gagal) {
    echo "\n  GAGAL: " . implode(', ', $langkah_gagal) . "\n";
    echo "  Jalankan langkah itu sendiri untuk melihat rinciannya.\n\n";
    exit(1);
}

echo "  Seluruh pemeriksaan lulus.\n\n";

// Berkas ini menguji SALINAN LOKAL. Ia tidak dapat mengatakan apa pun
// tentang apa yang sebenarnya berjalan di server — dan pada sistem yang
// di-deploy dengan menimpa berkas lewat FTP, keduanya kerap berbeda jauh
// lebih lama daripada yang disangka siapa pun.
echo "
  Untuk memeriksa SERVER (hanya GET, aman terhadap produksi):
";
echo "      php tests/verify_deploy.php <url-server>
";
exit(0);
