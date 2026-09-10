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

/**
 * Mencari tanda tutup tag PHP di dalam komentar SATU BARIS.
 *
 * Di dalam komentar // atau #, tanda tutup tag TETAP menutup blok PHP.
 * Komentar blok tidak begitu, jadi hanya bentuk satu baris yang dicari.
 *
 * php -l memang menangkap akibatnya, tetapi pesannya menunjuk ke baris
 * yang salah dengan keluhan seperti "unexpected token" — jauh dari
 * penyebabnya. Kesalahan ini terjadi EMPAT KALI saat menulis pemeriksa
 * di folder ini, dan tiap kali butuh waktu untuk dilacak.
 *
 * Kalau blok setelahnya kebetulan tetap sah, berkasnya bahkan lolos
 * php -l dan diam-diam mencetak kode sumbernya sebagai HTML — persis
 * yang pernah terjadi pada includes/mailer.php.
 *
 * @return array<int,int> nomor baris yang bermasalah
 */
function komentar_baris_menutup_tag($isi)
{
    $temuan = [];
    $token = @token_get_all($isi) ?: [];
    $n = count($token);

    for ($i = 0; $i < $n; $i++) {
        $t = $token[$i];
        if (!is_array($t) || $t[0] !== T_COMMENT) {
            continue;
        }
        // Komentar blok tidak terpengaruh; hanya // dan # .
        if (substr(ltrim($t[1]), 0, 2) === '/*') {
            continue;
        }

        // PHP memotong token komentar TEPAT di tanda tutup tag, lalu
        // memancarkan T_CLOSE_TAG. Jadi gejalanya adalah komentar baris
        // yang diikuti langsung oleh T_CLOSE_TAG.
        $lanjut = $token[$i + 1] ?? null;
        $tutup = is_array($lanjut) && $lanjut[0] === T_CLOSE_TAG;
        if (!$tutup) {
            continue;
        }

        // Bedakan dari pola yang SAH:
        //
        //     // komentar biasa          <- token berakhir baris baru
        //     [tanda tutup tag]          <- di baris berikutnya
        //
        // Pada kasus yang salah, komentarnya terpotong di tengah baris
        // sehingga tokennya TIDAK berakhir dengan baris baru.
        $berakhir_baris = substr($t[1], -1) === "\n" || substr($t[1], -1) === "\r";
        if ($berakhir_baris) {
            continue;
        }

        $temuan[] = $t[2];
    }
    return $temuan;
}

foreach ($berkas as $p) {
    $keluaran = [];
    $kode = 0;
    exec($php . ' -l ' . escapeshellarg($p) . ' 2>&1', $keluaran, $kode);
    if ($kode === 0) {
        continue;
    }

    $rusak++;
    printf("  *** %s\n", str_replace($akar_n, '', $p));
    foreach (array_slice($keluaran, 0, 2) as $b) {
        echo '      ' . trim($b) . "\n";
    }

    // Petunjuk, BUKAN aturan tersendiri.
    //
    // Percobaan pertama menjadikan ini pemeriksaan mandiri, dan hasilnya
    // salah: idiom templating yang lazim dan benar
    //
    //     [tag php] // keterangan singkat [tanda tutup]
    //     [tag php] else: // teks [tanda tutup]
    //
    // ikut tertuduh, padahal tanda tutupnya memang dimaksudkan menutup
    // blok. Dari token saja, yang disengaja dan yang tidak terlihat
    // persis sama — tidak ada aturan sintaksis yang bisa membedakannya.
    //
    // Lagi pula ia tidak menambah cakupan: kasus yang merusak parse
    // sudah tertangkap php -l tepat di baris ini, dan kasus yang lolos
    // parse lalu mencetak kode sumber diam-diam sudah tertangkap
    // lint_leaked_php.php. Yang kurang hanyalah KECEPATAN DIAGNOSIS:
    // php -l melaporkan "unexpected token" di baris yang jauh dari
    // penyebabnya. Kesalahan ini terjadi empat kali saat menulis
    // pemeriksa di folder ini, dan tiap kali butuh waktu dilacak.
    //
    // Karena itu ia hanya muncul ketika php -l MEMANG gagal.
    $baris_curiga = komentar_baris_menutup_tag((string)@file_get_contents($p));
    if ($baris_curiga) {
        printf("      PETUNJUK: komentar satu baris menutup tag PHP di baris %s.\n",
            implode(', ', $baris_curiga));
        printf("      Tanda tutup tag MENUTUP blok PHP walau di dalam komentar,\n");
        printf("      sehingga sisanya diperlakukan sebagai HTML. Bila blok itu\n");
        printf("      seharusnya berlanjut, di situlah penyebabnya.\n");
    }
}

printf("\n  Diperiksa: %d berkas.  Galat sintaks: %d.\n", count($berkas), $rusak);
if ($rusak === 0) {
    echo "  Seluruh berkas PHP sah secara sintaks.\n";
}
exit($rusak > 0 ? 1 : 0);
