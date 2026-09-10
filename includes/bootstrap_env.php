<?php
/**
 * Prolog yang harus berjalan SEBELUM apa pun — tanpa menyentuh basis data.
 *
 * ── Mengapa terpisah dari config/db.php ────────────────────────────────
 * Isi berkas ini dulu berada di dalam config/db.php. Masalahnya, db.php
 * memanggil die() bila koneksi gagal:
 *
 *     die("Koneksi database gagal. Silakan hubungi administrator sistem.");
 *
 * Artinya berkas itu tidak dapat dipakai oleh api/bridge.php — endpoint
 * yang justru bertugas MELAPORKAN keadaan server, dan karenanya harus
 * tetap menjawab ketika basis data sedang mati.
 *
 * Akibatnya bridge.php dulu tidak memuat apa pun, sehingga ia melewatkan
 * penyetelan zona waktu dan melaporkan server_time dalam zona yang berbeda
 * dari seluruh sistem lainnya.
 *
 * Memisahkannya ke sini menyelesaikan keduanya: satu sumber kebenaran,
 * nol ketergantungan pada basis data. Pola yang sama dipakai
 * includes/menu.php, dengan alasan yang sama — nilai yang digandakan
 * cepat atau lambat menyimpang.
 */
// Environment Loader
function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $name = trim($parts[0]);
            $value = trim($parts[1], " \"'");
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
        }
    }
}
loadEnv(dirname(__DIR__) . '/.env');

/**
 * Penimpaan khusus mesin pengembang.
 *
 * ── Mengapa ini ada ────────────────────────────────────────────────────
 * Selama ini folder kerja lokal memakai .env yang SAMA dengan server:
 * DB_HOST menunjuk <host-basis-data> dan APP_URL menunjuk alamat produksi.
 * Akibatnya setiap uji, setiap migrasi, dan setiap skrip yang dijalankan
 * di komputer pengembang menulis LANGSUNG ke basis data produksi —
 * tanpa satu pun tanda di layar bahwa itu yang sedang terjadi.
 *
 * .env.local dimuat SESUDAH .env sehingga nilainya menang. Berkas itu
 * ada di .gitignore dan TIDAK boleh diunggah ke server; bila kebetulan
 * tidak ada — seperti di server — perilaku kembali persis seperti semula.
 *
 * Konvensinya sengaja meniru Laravel/Symfony agar tidak perlu dijelaskan
 * kepada pengembang berikutnya.
 */
/**
 * .env.local dimuat DI MANA SAJA, KECUALI di host produksi.
 *
 * ── Mengapa penjagaan ini ada ──────────────────────────────────────────
 * Berkas ini sudah DUA KALI ikut terunggah ke server produksi, dan
 * keduanya melumpuhkan seluruh situs:
 *
 *   DB_HOST=localhost    server mencari MySQL di dirinya sendiri; setiap
 *                        halaman menjawab "Koneksi database gagal".
 *   APP_ENV=local        kotak-pasir e-mail menyala: SELURUH e-mail
 *                        berhenti terkirim, tanpa satu pun pesan galat.
 *   APP_URL=localhost    setiap tautan di e-mail menunjuk ke komputer
 *                        penerima.
 *
 * Ia ada di .gitignore, tetapi FileZilla tidak membaca .gitignore — dan
 * namanya diawali titik sehingga mudah luput dari pandangan. Mengandalkan
 * orang untuk selalu ingat bukan penjagaan.
 *
 * ── Mengapa arah logikanya begini, bukan sebaliknya ────────────────────
 * Percobaan pertama memakai daftar putih "yang dianggap lokal": localhost,
 * 127.0.0.1, *.local, *.test. Arah itu SALAH, dan salahnya berbahaya.
 * Membuka situs pengembangan lewat alamat LAN — misalnya dari ponsel ke
 * 192.168.1.5/alumnilink — tidak cocok dengan daftar itu, sehingga
 * .env.local diabaikan dan koneksinya jatuh ke BASIS DATA PRODUKSI tanpa
 * ada tanda apa pun.
 *
 * Arah yang benar adalah mengenali satu hal yang memang diketahui pasti:
 * alamat produksi, yang tertulis di APP_URL pada .env. Host mana pun yang
 * bukan itu diperlakukan sebagai pengembangan. Kesalahan penilaian
 * karenanya jatuh ke sisi yang aman: memakai basis data lokal.
 */
function alumnilink_host_produksi()
{
    // Skrip baris perintah tidak punya host. Uji dan alat pengembangan
    // berjalan lewat jalur ini, jadi tidak pernah dianggap produksi.
    if (PHP_SAPI === 'cli') {
        return false;
    }

    $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($host === '') {
        return false;
    }

    // APP_URL di .env adalah alamat pemasangan sungguhan. Nilai ini dibaca
    // SEBELUM .env.local dimuat, jadi ia belum sempat ditimpa.
    $app_url = (string)getenv('APP_URL');
    $host_prod = strtolower((string)parse_url($app_url, PHP_URL_HOST));

    if ($host_prod === '' || $host_prod === 'localhost' || $host_prod === '127.0.0.1') {
        // .env pun menunjuk ke lokal: tidak ada host produksi yang dikenali.
        return false;
    }

    return $host === $host_prod;
}

if (alumnilink_host_produksi()) {
    if (file_exists(dirname(__DIR__) . '/.env.local')) {
        // Bukan alasan untuk menghentikan situs — justru sebaliknya, seluruh
        // maksud penjagaan ini adalah agar situs tetap hidup. Tetapi berkas
        // itu tidak seharusnya ada di sana, jadi dicatat supaya ketahuan.
        error_log('PERINGATAN: .env.local ada di server produksi dan DIABAIKAN. '
            . 'Berkas itu hanya untuk mesin pengembang; hapus dari server.');
    }
} else {
    loadEnv(dirname(__DIR__) . '/.env.local');
}

/**
 * SATU zona waktu untuk PHP dan MySQL.
 *
 * ── Bug yang diperbaiki blok ini ───────────────────────────────────────
 * Sebelumnya aplikasi tidak menyatakan zona waktunya sama sekali. PHP
 * mengikuti php.ini, MySQL mengikuti jam sistemnya sendiri — dua mesin
 * berbeda, dua konfigurasi berbeda. Diukur pada 10 September 2026:
 *
 *     PHP produksi   : UTC   -> 06:24
 *     MySQL produksi : WIB   -> 13:24     selisih 7 jam
 *
 * Selisih itu merusak setiap perhitungan yang mencampur keduanya, yaitu
 * setiap kolom yang diisi MySQL (DEFAULT CURRENT_TIMESTAMP) lalu dihitung
 * di PHP dengan time():
 *
 *   handlers/reset_password.php   (time() - created_time) > 3600
 *       06:24 - 13:24 = -25200, tidak pernah lebih besar dari 3600.
 *       Tautan atur-ulang sandi berlaku 8 jam, bukan 1 jam.
 *
 *   includes/legalisir_lib.php    floor((time() - $t) / 86400)
 *       Umur SLA menjadi negatif; permohonan baru tampak berumur -1 hari.
 *
 *   api/notifications.php         time() - strtotime($timestamp)
 *       Label "x menit lalu" menjadi negatif.
 *
 * Memperbaikinya satu per satu di tiap pemanggil akan menyisakan pemanggil
 * berikutnya yang lupa. Karena itu diperbaiki di pangkalnya: kedua jam
 * disetel ke zona yang sama di sini, sekali, sebelum apa pun berjalan.
 *
 * Nilainya dapat ditimpa lewat APP_TIMEZONE di .env bila suatu saat
 * dipasang di zona lain.
 */
define('ALUMNILINK_TIMEZONE', getenv('APP_TIMEZONE') ?: 'Asia/Jakarta');
date_default_timezone_set(ALUMNILINK_TIMEZONE);

/**
 * Ke mana galat PHP diarahkan.
 *
 * ── Mengapa ini perlu diatur di sini ───────────────────────────────────
 * Sebelumnya tidak diatur di mana pun, jadi ia mengikuti php.ini server —
 * dan di server itu display_errors menyala. Akibatnya setiap pengunjung
 * dapat melihat peringatan PHP lengkap dengan jalur berkasnya:
 *
 *     Warning: Undefined variable $page in
 *     /var/www/html/alumnilink/includes/header.php on line 43
 *
 * Jalur itu memberi tahu tata letak server kepada siapa pun yang melihat,
 * dan pada kondisi galat yang lain pesannya dapat memuat potongan SQL
 * beserta nama kolomnya.
 *
 * Galat TIDAK disembunyikan — hanya dipindahkan dari layar pengunjung ke
 * log server. log_errors dinyalakan di baris yang sama, bukan sebagai
 * gantinya, supaya masalahnya tetap dapat didiagnosis.
 *
 * Diatur lewat ini_set(), BUKAN php_flag di .htaccess: direktif itu
 * menuntut AllowOverride Options dan memicu HTTP 500 pada hosting yang
 * membatasinya, sedangkan ini_set() bekerja di mana pun.
 */
if ((getenv('APP_ENV') ?: 'local') === 'local') {
    // Di mesin pengembang, galat justru harus terlihat seketika.
    ini_set('display_errors', '1');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    // E_DEPRECATED dan E_NOTICE dibiarkan tidak dicatat: keduanya berisik
    // pada kode selama ini dan akan menenggelamkan galat yang sungguhan
    // di dalam log.
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}
