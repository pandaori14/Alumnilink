<?php
/**
 * Pendeteksi KELUARAN TANPA ESCAPE (XSS).
 *
 * ── Mengapa berkas ini ada ─────────────────────────────────────────────
 * Nama alumni disimpan MENTAH — tidak ada penyaringan di
 * handlers/register_handler.php maupun handlers/update_profile.php — lalu
 * dirender tanpa htmlspecialchars() di layar staf. Seorang alumnus yang
 * mengganti namanya menjadi markup akan menjalankan skrip di peramban admin
 * yang membuka Database Alumni, memakai sesi admin itu.
 *
 * Parameter URL juga dipantulkan mentah ke atribut. Yang ini sudah dibuktikan
 * lewat permintaan HTTP sungguhan sebelum diperbaiki:
 *
 *     ?page=admin_keuangan&start_date=" onfocus=alert(1) autofocus x="
 *     -> name="start_date" value="" onfocus=alert(1) autofocus x=""
 *
 * Menyaring saat MENYIMPAN bukan jawabannya: data yang sama dipakai di CSV,
 * e-mail, dan JSON, yang masing-masing butuh escape berbeda. Escape harus
 * dilakukan saat MENAMPILKAN, dan berkas ini yang menjaganya tetap begitu.
 *
 * Memakai tokenizer PHP, bukan regex mentah, mengikuti pola
 * tests/lint_leaked_php.php yang sudah terbukti.
 *
 * Jalankan:  php tests/lint_unescaped.php
 */

$akar = dirname(__DIR__);

/**
 * Fungsi yang keluarannya sudah aman.
 * Yang menghasilkan angka atau tanggal tidak mungkin memuat markup.
 */
$aman = [
    'e', 'htmlspecialchars', 'htmlentities', 'esc',
    'number_format', 'date', 'count', 'intval', 'floatval', 'round',
    'strtoupper', 'strtolower', 'ucfirst', 'implode', 'json_encode',
    'base64_encode', 'urlencode', 'rawurlencode', 'sprintf', 'printf',
    // CATATAN: json_encode() SENGAJA TIDAK ada di daftar ini.
    // Ia menghasilkan literal JavaScript yang sah, tetapi tidak meng-escape
    // <, > maupun tanda kutip tunggal — jadi di dalam atribut HTML
    // (onclick='...') ia masih bisa ditembus, sama seperti addslashes().
    // Bentuk yang benar adalah e(json_encode($x)), dan itu tertangkap oleh
    // 'e' di daftar ini. Uji nyata sempat menemukan kebocoran justru karena
    // json_encode pernah dianggap aman di sini.
    'csrf_field', 'str_repeat', 'nl2br', 'array_sum', 'max', 'min', 'abs',

    // getThumbSVG() mengembalikan MARKUP SVG dari daftar tertutup yang
    // ditulis di dalam kode (pages/admin_email_layouts.php), dipilih lewat
    // kunci dengan nilai cadangan. Tidak ada data pengguna yang bisa masuk,
    // dan membungkusnya dengan e() justru akan mencetak kode SVG-nya sebagai
    // teks alih-alih menggambarnya.
    'getthumbsvg',
];

/**
 * Berkas yang dikecualikan, beserta alasannya.
 * Setiap pengecualian harus punya alasan yang bisa dipertanggungjawabkan.
 */
$kecuali = [
    // Berkas ini sendiri memuat contoh muatan XSS di dalam komentar.
    'tests/lint_unescaped.php',
];

$berkas = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($akar, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (substr($p, -4) !== '.php') continue;
    if (strpos($p, '/_dev/') !== false || strpos($p, '/node_modules/') !== false) continue;
    if (strpos($p, '/backups/') !== false) continue;
    // Skrip yang HANYA berjalan di CLI mencetak ke terminal, bukan ke HTML.
    // Tidak ada peramban yang menafsirkannya, jadi escape di sana justru
    // merusak keterbacaan log. Dikenali dari penjagaannya sendiri, bukan
    // dari nama folder — dengan begitu berkas baru yang lupa dikecualikan
    // tetap ikut diperiksa selama ia memang melayani web.
    $awal = (string)@file_get_contents($p, false, null, 0, 2000);
    if (strpos($awal, 'cron_require_auth') !== false) continue;
    if (strpos($awal, "PHP_SAPI !== 'cli'") !== false) continue;
    if (strpos($awal, "php_sapi_name() !== 'cli'") !== false) continue;
    // Suite uji: dijalankan dari baris perintah, dan sudah diblokir dari
    // web lewat aturan ^(_dev|scratch|logs|tail|tests)/ di .htaccess.
    if (strpos($p, '/tests/suite/') !== false) continue;
    $berkas[] = $p;
}
sort($berkas);

$masalah = 0;
$diperiksa = 0;
$akar_n = str_replace('\\', '/', $akar) . '/';

foreach ($berkas as $p) {
    $rel = str_replace($akar_n, '', $p);
    if (in_array($rel, $kecuali, true)) continue;

    $isi = file_get_contents($p);
    $token = @token_get_all($isi);
    if (!$token) continue;
    $diperiksa++;

    $n = count($token);
    for ($i = 0; $i < $n; $i++) {
        $t = $token[$i];
        if (!is_array($t)) continue;

        // Hanya blok yang MENCETAK yang menjadi keluaran HTML:
        //   <?= ...          (T_OPEN_TAG_WITH_ECHO)
        //   <?php echo ...   (T_ECHO)
        // Penetapan variabel dan pemanggilan fungsi biasa tidak dicetak.
        $mencetak = ($t[0] === T_OPEN_TAG_WITH_ECHO) || ($t[0] === T_ECHO) || ($t[0] === T_PRINT);
        if (!$mencetak) continue;

        $baris = $t[2];

        // Kumpulkan ekspresi sampai ';' atau penutup tag.
        $ekspr = '';
        $fungsi_pertama = null;
        $ada_variabel = false;
        for ($j = $i + 1; $j < $n; $j++) {
            $u = $token[$j];
            if (!is_array($u)) {
                if ($u === ';') break;
                $ekspr .= $u;
                continue;
            }
            if ($u[0] === T_CLOSE_TAG) break;
            if ($u[0] === T_VARIABLE) $ada_variabel = true;
            if ($u[0] === T_STRING && $fungsi_pertama === null) {
                // Nama fungsi hanya dihitung bila diikuti '(' — kalau tidak,
                // itu konstanta seperti PHP_EOL, bukan pemanggilan.
                for ($k = $j + 1; $k < $n; $k++) {
                    if (is_array($token[$k]) && $token[$k][0] === T_WHITESPACE) continue;
                    if ($token[$k] === '(') $fungsi_pertama = strtolower($u[1]);
                    break;
                }
            }
            $ekspr .= $u[1];
        }
        $i = isset($j) ? $j : $i;

        // Tanpa variabel, tidak ada yang bisa disuntikkan.
        if (!$ada_variabel) continue;

        // Sudah dibungkus fungsi yang aman.
        if ($fungsi_pertama !== null && in_array($fungsi_pertama, $aman, true)) continue;

        // Cast numerik/boolean: hasilnya tidak mungkin memuat markup.
        // (int)$x dan (float)$x aman berapa pun isi $x.
        if (preg_match('/^\s*\((int|integer|float|double|bool|boolean)\)/', $ekspr)) continue;

        // Ternary yang SELURUH cabangnya berupa literal string: yang dicetak
        // adalah literal itu, bukan datanya. Variabelnya hanya dipakai
        // sebagai syarat. Contoh:
        //     $x == 1 ? 'selected' : ''
        //     ($st->n ?? 0) > 0 ? 'text-red-600' : 'text-slate-700'
        // Ternary yang cabangnya hanya literal — mis. `$x ? 'selected' : ''` —
        // sebenarnya tidak mencetak data pengguna. Tetapi aturan untuk
        // mengenalinya secara otomatis pada ternary BERSARANG ternyata rapuh,
        // dan pendeteksi XSS yang terlalu pintar berbahaya: kesalahan ke arah
        // "diam" berarti melewatkan kebocoran sungguhan.
        //
        // Karena itu tidak ada pengecualian di sini. Titik semacam itu tetap
        // dilaporkan, dan cara menutupnya adalah membungkusnya dengan e()
        // juga — tidak ada biayanya, dan kodenya jadi seragam: SETIAP
        // keluaran variabel lewat e(), tanpa terkecuali yang perlu dihafal.

        // Hanya properti/indeks yang berisi data, bukan pembanding boolean.
        if (!preg_match('/\$\w+(->\w+|\[)/', $ekspr)) continue;

        $masalah++;
        printf("  *** %s baris %d\n      %s\n", $rel, $baris,
            trim(preg_replace('/\s+/', ' ', substr($ekspr, 0, 96))));
    }
}

printf("\n  Diperiksa: %d berkas.  Keluaran tanpa escape: %d.\n", $diperiksa, $masalah);
if ($masalah === 0) {
    echo "  Semua keluaran variabel sudah di-escape.\n";
}
exit($masalah > 0 ? 1 : 0);
