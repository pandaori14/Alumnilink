<?php
/**
 * Pendeteksi KODE PHP YANG BOCOR SEBAGAI TEKS.
 *
 * ── Mengapa berkas ini ada ─────────────────────────────────────────────
 * Kelas galat ini sudah tiga kali muncul di proyek ini:
 *
 *   1. `?>` di dalam komentar `//` pada includes/header.php — teks komentar
 *      ikut tercetak di atas <!DOCTYPE> pada SETIAP halaman.
 *   2. Skip-link tersisip di dalam atribut `<body class="...">`.
 *   3. `?>` tertinggal di tengah includes/mailer.php — 46 baris sesudahnya
 *      diperlakukan sebagai HTML, sehingga 2.183 byte kode sumber tercetak
 *      ke setiap halaman DAN fungsi send_employer_survey_invitation()
 *      tidak pernah terdefinisi. Undangan survei atasan pasti gagal.
 *
 * `php -l` TIDAK dapat menangkapnya: berkasnya sah secara sintaks. Yang
 * salah adalah PHP menganggap potongan itu sebagai keluaran, bukan kode.
 *
 * Pemeriksa ini memakai tokenizer PHP sendiri, lalu memeriksa setiap blok
 * T_INLINE_HTML (bagian yang akan dicetak apa adanya). Bila di dalamnya
 * ada pola yang jelas-jelas kode PHP, itu kebocoran.
 *
 * Menyisipkan HTML di antara blok PHP adalah hal biasa dan TIDAK dilaporkan.
 *
 * Jalankan:  php tests/lint_leaked_php.php
 */

$akar = dirname(__DIR__);

/** Pola yang tidak mungkin muncul sebagai HTML yang disengaja. */
$pola = [
    '/^\s*function\s+\w+\s*\(/m'                 => 'definisi function',
    '/^\s*(require|include)(_once)?\s*[\(\x27"]/m' => 'require/include',
    '/^\s*\$\w+\s*=\s*[^=]/m'                    => 'penetapan variabel',
    '/^\s*(public|private|protected)\s+function/m' => 'metode kelas',
    '/^\s*(foreach|if|while)\s*\(.*\)\s*\{\s*$/m'  => 'blok kendali',
    '/->\w+\(.*\);\s*$/m'                          => 'pemanggilan metode',
];

/** Berkas yang memang menampilkan kode sebagai contoh — dikecualikan. */
$kecuali = [
    'pages/guide.php',   // panduan pengguna memuat cuplikan
];

$berkas = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($akar, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (substr($p, -4) !== '.php') continue;
    if (strpos($p, '/_dev/') !== false || strpos($p, '/node_modules/') !== false) continue;
    $berkas[] = $p;
}
sort($berkas);

$masalah = 0;
$diperiksa = 0;

foreach ($berkas as $p) {
    $rel = str_replace(str_replace('\\', '/', $akar) . '/', '', $p);
    if (in_array($rel, $kecuali, true)) continue;
    $diperiksa++;

    $isi = file_get_contents($p);
    $token = @token_get_all($isi);
    if (!$token) continue;

    // Keadaan "di dalam <script>/<style>" harus dilacak LINTAS blok:
    // PHP memotong berkas di setiap <?php, sehingga tag <script> pembuka
    // kerap berada di blok HTML yang berbeda dari isinya. JavaScript juga
    // memakai kata 'function' dan tanda '$', jadi tanpa pelacakan ini
    // setiap skrip di halaman akan salah dilaporkan.
    $dlm_skrip = false;
    $dlm_style = false;

    foreach ($token as $t) {
        if (!is_array($t) || $t[0] !== T_INLINE_HTML) continue;

        $html  = $t[1];
        $baris = $t[2];
        $awal_skrip = $dlm_skrip;
        $awal_style = $dlm_style;

        // Perbarui keadaan untuk blok berikutnya.
        $l = strtolower($html);
        $dlm_skrip = (substr_count($l, '<script') - substr_count($l, '</script') + ($dlm_skrip ? 1 : 0)) > 0;
        $dlm_style = (substr_count($l, '<style')  - substr_count($l, '</style')  + ($dlm_style ? 1 : 0)) > 0;

        // Blok yang dimulai di dalam skrip/gaya dilewati seluruhnya.
        if ($awal_skrip || $awal_style) continue;

        // Buang seluruh ISI <script>/<style> yang terbuka DAN tertutup di
        // dalam blok ini juga — bukan hanya tag pembukanya. Tanpa langkah
        // ini, setiap fungsi JavaScript ikut terjaring.
        $bersih = preg_replace('#<script\b[^>]*>.*?</script\s*>#is', '', $html);
        $bersih = preg_replace('#<style\b[^>]*>.*?</style\s*>#is', '', $bersih);
        // Sisa <script> yang belum tertutup di blok ini: potong dari situ.
        $bersih = preg_replace('#<(script|style)\b[^>]*>.*$#is', '', $bersih);

        $tanpa_tag = preg_replace('/<[^>]*>/', '', $bersih);
        if (trim($tanpa_tag) === '') continue;

        foreach ($pola as $re => $label) {
            if (preg_match($re, $tanpa_tag, $m)) {
                $masalah++;
                printf("  *** %s baris %d: %s tercetak sebagai teks\n", $rel, $baris, $label);
                printf("      %s\n", trim(substr($m[0], 0, 72)));
                break;
            }
        }
    }
}

printf("\n  Diperiksa: %d berkas.  Kebocoran: %d.\n", $diperiksa, $masalah);
if ($masalah === 0) {
    echo "  Tidak ada kode PHP yang bocor sebagai teks.\n";
}
exit($masalah > 0 ? 1 : 0);
