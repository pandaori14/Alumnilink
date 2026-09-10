<?php
/**
 * Pemeriksa SINTAKS JavaScript yang ditulis langsung di dalam berkas PHP.
 *
 * ── Mengapa berkas ini ada ─────────────────────────────────────────────
 * Halaman-halaman di proyek ini menyimpan ribuan baris JavaScript inline:
 *
 *     pages/admin_broadcast.php    742 baris
 *     pages/legalisir.php          427 baris
 *     pages/admin_legalisir.php    392 baris
 *     pages/admin_settings.php     361 baris
 *     pages/admin_repository.php   335 baris
 *
 * Tidak satu pun penjaga yang ada melihatnya. `php -l` berhenti di batas
 * tag PHP. lint_unescaped.php memeriksa keluaran PHP, bukan isi skrip.
 * lint_leaked_php.php justru sengaja MELEWATI blok <script>.
 *
 * Padahal satu tanda kurung yang tidak tertutup di dalam blok itu membuat
 * peramban membuang SELURUH blok — setiap tombol, modal, dan pengiriman
 * formulir di halaman itu berhenti bekerja. Tanpa galat di layar, tanpa
 * catatan di log server. Halamannya tetap tampil sempurna.
 *
 * ── Bagaimana PHP di dalam JavaScript ditangani ────────────────────────
 * Blok skrip kerap menyisipkan nilai dari PHP. Sisipan itu diganti dengan
 * nilai contoh yang sah secara sintaks sebelum diperiksa:
 *
 *     var id = <?= $user->id ?>;        ->   var id = 0;
 *     fetch("<?= BASE_URL ?>/api");     ->   fetch("0/api");
 *
 * Yang diperiksa adalah KERANGKA skripnya — kurung, kurawal, tanda kutip,
 * kata kunci. Itu justru bagian yang rusak bila seseorang salah menyunting,
 * dan bagian yang tidak berubah oleh nilai apa pun yang disisipkan.
 *
 * Membutuhkan Node.js di PATH. Bila tidak ada, berkas ini melapor dan
 * keluar dengan kode 0 supaya tidak menggagalkan rangkaian uji di mesin
 * yang memang tidak memasangnya.
 *
 * Jalankan:  php tests/lint_inline_js.php
 */

$akar = dirname(__DIR__);

// ── Node.js tersedia? ────────────────────────────────────────────────
$node = null;
foreach (['node', 'nodejs'] as $kandidat) {
    $cek = @shell_exec(sprintf('%s --version 2>&1', escapeshellarg($kandidat)));
    if ($cek !== null && preg_match('/^v?\d+\./', trim((string)$cek))) {
        $node = $kandidat;
        break;
    }
}

if ($node === null) {
    echo "\n  Node.js tidak ditemukan di PATH — pemeriksaan JavaScript dilewati.\n";
    echo "  Ini bukan kegagalan: pemeriksa ini hanya berjalan di mesin yang\n";
    echo "  memasang Node. Pasang Node.js untuk mengaktifkannya.\n\n";
    exit(0);
}

// ── Kumpulkan berkas PHP ─────────────────────────────────────────────
$berkas = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($akar, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (substr($p, -4) !== '.php') {
        continue;
    }
    if (strpos($p, '/_dev/') !== false
        || strpos($p, '/node_modules/') !== false
        || strpos($p, '/tests/') !== false
        || strpos($p, '/backups/') !== false
        || strpos($p, '/PHPMailer/') !== false) {
        continue;
    }
    $berkas[] = $p;
}
sort($berkas);

$tmp = sys_get_temp_dir() . '/alumnilink_js_' . getmypid();
@mkdir($tmp, 0777, true);

$diperiksa = 0;
$blok_total = 0;
$masalah = 0;

foreach ($berkas as $p) {
    $rel = str_replace(str_replace('\\', '/', $akar) . '/', '', $p);
    $isi = file_get_contents($p);

    // Blok <script> tanpa atribut src. Yang punya src memuat berkas lain.
    if (!preg_match_all('~<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script\s*>~is',
            $isi, $m, PREG_OFFSET_CAPTURE)) {
        continue;
    }
    $diperiksa++;

    foreach ($m[1] as $idx => $blok) {
        $kode = $blok[0];
        if (trim($kode) === '') {
            continue;
        }

        // Lewati blok data, bukan skrip: <script type="application/json">
        $tag_pembuka = substr($isi, $m[0][$idx][1], 200);
        if (preg_match('~type\s*=\s*["\'](application/(ld\+)?json|text/template)~i', $tag_pembuka)) {
            continue;
        }

        $blok_total++;

        // Nomor baris tempat blok ini dimulai, untuk pesan galat.
        $baris_awal = substr_count(substr($isi, 0, $blok[1]), "\n") + 1;

        // ── Ganti sisipan PHP dengan nilai contoh yang sah ────────────
        // Tag echo pendek berisi NILAI, jadi diganti 0.
        $bersih = preg_replace('~<\?=.*?\?>~s', '0', $kode);

        // Blok tag PHP bentuk panjang: NILAI atau KENDALI?
        //
        // Bukan kata pertamanya yang menentukan, melainkan apakah blok
        // itu MENCETAK sesuatu. Dua bentuk ini sama-sama menghasilkan
        // nilai, tetapi hanya yang pertama diawali echo:
        //
        //     let a = [tag] echo json_encode($x); [tutup] ;
        //     let b = [tag] $t = $s ?? '[]'; echo $t; [tutup] ;
        //
        // Yang kedua sempat dibuang sebagai kendali, sehingga menjadi
        // `let b = ;` dan dilaporkan sebagai galat sintaks yang tidak
        // ada. Karena itu keputusannya diambil dari ISI blok.
        //
        // Blok kendali sejati (if/foreach/endif) tidak mencetak apa pun
        // dan memang harus dibuang, supaya JavaScript di dalamnya tetap
        // ikut diperiksa.
        //
        // Contoh di atas sengaja tidak menuliskan tanda tutup tag PHP:
        // menuliskannya di dalam komentar baris akan MENUTUP blok PHP
        // ini. Kesalahan itu sudah terjadi tiga kali di folder ini.
        $bersih = preg_replace_callback(
            '~<\?php.*?\?>~s',
            function ($m) {
                return preg_match('~(^|[;{}\s])(echo|print)([\s(]|$)~', $m[0])
                    ? '0'   // blok ini menghasilkan nilai
                    : '';   // blok ini hanya kendali
            },
            $bersih
        );

        // Tag yang tidak tertutup sampai akhir blok.
        $bersih = preg_replace('~<\?(php|=)?.*$~s', '', $bersih);

        $berkas_uji = sprintf('%s/blok_%d.js', $tmp, $blok_total);
        file_put_contents($berkas_uji, $bersih);

        $keluaran = @shell_exec(sprintf('%s --check %s 2>&1',
            escapeshellarg($node), escapeshellarg($berkas_uji)));
        $keluaran = trim((string)$keluaran);

        if ($keluaran === '') {
            continue;
        }

        // Node menyebut nomor baris relatif terhadap blok; ubah menjadi
        // nomor baris di dalam berkas PHP-nya supaya langsung bisa dibuka.
        $baris_blok = 0;
        if (preg_match('~blok_\d+\.js:(\d+)~', $keluaran, $mm)) {
            $baris_blok = (int)$mm[1];
        }
        $baris_nyata = $baris_awal + max(0, $baris_blok - 1);

        $pesan = '';
        foreach (explode("\n", $keluaran) as $l) {
            $l = trim($l);
            if ($l !== '' && preg_match('~Error|error~', $l)) {
                $pesan = $l;
                break;
            }
        }
        if ($pesan === '') {
            $pesan = explode("\n", $keluaran)[0];
        }

        $masalah++;
        printf("\n  *** %s baris %d\n", $rel, $baris_nyata);
        printf("      %s\n", $pesan);
    }
}

// Bersihkan berkas sementara.
foreach (glob($tmp . '/*.js') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmp);

printf("\n  Berkas dengan skrip inline: %d.  Blok diperiksa: %d.  Galat: %d.\n",
    $diperiksa, $blok_total, $masalah);
if ($masalah === 0) {
    echo "  Seluruh JavaScript inline sah secara sintaks.\n";
}
exit($masalah > 0 ? 1 : 0);
