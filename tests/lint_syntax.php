<?php
/**
 * Periksa sintaks seluruh berkas PHP.
 *
 * Setara `php -l` atas setiap berkas, tetapi sebagai satu perintah yang
 * bisa dipanggil run_all.php dan mengembalikan kode keluar.
 *
 * Ini pemeriksaan paling dasar dan paling murah — dan justru karena murah,
 * ia harus otomatis. Sesi-sesi sebelumnya beberapa kali menemukan berkas
 * rusak hanya karena kebetulan menjalankannya secara manual.
 *
 * Perlu ditegaskan soal batasnya: lulus di sini TIDAK berarti berkasnya
 * benar. Tiga cacat paling parah di proyek ini semuanya lolos `php -l`:
 *
 *   - setting_int() dipanggil sebelum config/db.php di-require
 *     (pekerja antrean e-mail mati total, 4 e-mail mengendap sejak Mei)
 *   - `?>` liar di tengah mailer.php
 *     (2.183 byte kode sumber tercetak ke setiap halaman)
 *   - addslashes() disangka proteksi XSS di dalam atribut HTML
 *
 * Untuk ketiganya ada pemeriksa tersendiri: lint_leaked_php.php,
 * lint_unescaped.php, dan suite di tests/suite/.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("CLI only.\n");
}

$akar = dirname(__DIR__);
$lewati = ['/_dev/', '/node_modules/', '/backups/', '/.git/'];

$berkas = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($akar, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (substr($p, -4) !== '.php') {
        continue;
    }
    foreach ($lewati as $l) {
        if (strpos($p, $l) !== false) { continue 2; }
    }
    $berkas[] = $p;
}
sort($berkas);

$php   = escapeshellarg(PHP_BINARY);
$rusak = 0;
$akar_n = str_replace('\\', '/', $akar) . '/';

foreach ($berkas as $p) {
    $keluaran = [];
    $kode = 0;
    exec($php . ' -l ' . escapeshellarg($p) . ' 2>&1', $keluaran, $kode);
    if ($kode !== 0) {
        $rusak++;
        printf("  *** %s\n", str_replace($akar_n, '', $p));
        foreach (array_slice($keluaran, 0, 2) as $b) {
            echo '      ' . trim($b) . "\n";
        }
    }
}

printf("\n  Diperiksa: %d berkas.  Galat sintaks: %d.\n", count($berkas), $rusak);
if ($rusak === 0) {
    echo "  Seluruh berkas PHP sah secara sintaks.\n";
}
exit($rusak > 0 ? 1 : 0);
