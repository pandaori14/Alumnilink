<?php
/**
 * includes/session_boot.php
 * ─────────────────────────────────────────────────────────
 * Titik tunggal untuk memulai sesi dengan parameter cookie yang diperketat.
 *
 * MASALAH YANG DIPERBAIKI
 * Sebelumnya setiap berkas memanggil session_start() langsung, tanpa satu pun
 * memanggil session_set_cookie_params(). Akibatnya cookie sesi terbit tanpa:
 *   - HttpOnly  -> cookie sesi dapat dibaca JavaScript, sehingga satu celah
 *                  XSS langsung berubah menjadi pengambilalihan akun.
 *   - SameSite  -> cookie ikut terkirim pada permintaan lintas situs.
 *   - Secure    -> cookie dapat terkirim lewat HTTP polos.
 *
 * CARA PAKAI
 * Ganti setiap `session_start();` dengan:
 *     require_once __DIR__ . '/../includes/session_boot.php';
 * (sesuaikan kedalaman path). Berkas ini aman dipanggil berulang kali maupun
 * setelah sesi telanjur dimulai -- keduanya menjadi operasi tanpa efek.
 *
 * CATATAN DESAIN
 * - `path` dan `domain` sengaja MEMPERTAHANKAN nilai yang sudah berlaku.
 *   Aplikasi berjalan di subfolder /alumnilink/ pada domain yang mungkin juga
 *   menampung aplikasi lain; memaksa path menjadi '/' akan membagikan cookie
 *   sesi ke aplikasi tetangga.
 * - `secure` dideteksi otomatis, tidak dipaksa true, agar pengembangan lokal
 *   di http://localhost tidak ikut rusak.
 * - `SameSite=Lax` dipilih, bukan `Strict`, karena alur balik Google OAuth
 *   kembali ke situs ini lewat pengalihan lintas situs; `Strict` akan
 *   membuang cookie pada langkah tersebut dan memutus proses masuk.
 */

if (session_status() === PHP_SESSION_NONE) {

    // Deteksi HTTPS, termasuk saat berada di belakang reverse proxy / CDN.
    $is_https =
        (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ||
        (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ||
        (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on') ||
        ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    $existing = session_get_cookie_params();

    session_set_cookie_params([
        'lifetime' => $existing['lifetime'],
        'path'     => $existing['path'],
        'domain'   => $existing['domain'],
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/**
 * Terbitkan ulang ID sesi sambil mempertahankan isinya.
 *
 * WAJIB dipanggil tepat setelah autentikasi berhasil. Tanpa ini, ID sesi yang
 * sudah dipegang pengunjung sebelum masuk tetap berlaku sesudah masuk --
 * inilah celah session fixation: penyerang yang berhasil menanamkan sebuah
 * PHPSESSID di peramban korban akan ikut memegang sesi yang sudah
 * terautentikasi begitu korban berhasil masuk.
 */
function session_boot_regenerate()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        // Argumen true menghapus berkas sesi lama, sehingga ID lama benar-benar
        // tidak dapat dipakai lagi.
        session_regenerate_id(true);
    }

    // Titik awal penghitungan tenggang tidak aktif.
    $_SESSION['last_activity'] = time();
}

/**
 * Keluarkan pengguna secara otomatis setelah sekian menit tidak beraktivitas.
 *
 * Dikendalikan settings.session_timeout_minutes; nilai 0 (bawaan) mematikan
 * fitur ini sehingga perilaku lama dipertahankan.
 *
 * WAJIB dipanggil SETELAH config/db.php dimuat, karena membaca pengaturan
 * dari basis data. Dipanggil dari index.php sehingga berlaku pada setiap
 * pemuatan halaman.
 */
function session_enforce_timeout()
{
    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION['user_id'])) {
        return;
    }
    if (!function_exists('setting_int')) {
        return;
    }

    $minutes = setting_int('session_timeout_minutes', 0, 0);
    if ($minutes <= 0) {
        return; // fitur dimatikan
    }

    $last = $_SESSION['last_activity'] ?? time();

    if ((time() - $last) > ($minutes * 60)) {
        if (function_exists('log_activity')) {
            log_activity('SESSION_TIMEOUT', "Sesi berakhir otomatis setelah {$minutes} menit tanpa aktivitas.");
        }
        $_SESSION = [];
        session_destroy();

        $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
        header('Location: ' . $base . '/index.php?page=login&error=session_timeout');
        exit;
    }

    $_SESSION['last_activity'] = time();
}
