<?php
/**
 * Autentikasi pemicu tugas terjadwal (cron).
 *
 * ── Keadaan sebelum berkas ini ada ─────────────────────────────────────
 * Ketiga skrip di cron/ memakai cara yang berbeda, dan ketiganya lemah:
 *
 *   geocoder.php            ?run  — TANPA kunci sama sekali. Siapa pun di
 *                           internet dapat memicunya; setiap pemicuan
 *                           mengirim alamat rumah alumni ke Nominatim dan
 *                           menghabiskan jatah permintaan.
 *   process_email_queue.php CRON_SECRET dari .env, cadangan 'changeme'.
 *                           .env tidak memuatnya, jadi kuncinya memang
 *                           benar-benar 'changeme'.
 *   tracer_reminder.php     token DITULIS DI KODE:
 *                           'tracer-cron-secure-token-123', dengan komentar
 *                           "Change this to a secure token in production".
 *                           Ini yang terparah: siapa pun yang tahu isinya
 *                           dapat mengirimi SELURUH alumni e-mail pengingat,
 *                           berulang kali.
 *
 * Ketiganya kini memakai satu token acak yang disimpan di tabel `settings`,
 * bukan di .env maupun di kode. Alasannya: sistem ini di-deploy lewat FTP
 * tanpa akses shell, sehingga menyunting .env di server bukan langkah yang
 * bisa diandalkan. Token dibuat sendiri pada migrasi pertama dan dapat
 * dibaca superadmin di Pengaturan Sistem untuk menyusun URL cron.
 */

require_once __DIR__ . '/settings.php';

/**
 * Token cron yang berlaku. Dibuat sekali lalu disimpan.
 *
 * @param PDO|null $pdo Dibutuhkan hanya saat token belum pernah dibuat.
 */
function cron_token($pdo = null)
{
    static $ingat = null;
    if ($ingat !== null) {
        return $ingat;
    }

    $token = setting('cron_token', '');
    if ($token !== '') {
        return $ingat = $token;
    }

    // Belum pernah dibuat: buat sekarang dan simpan.
    if (!($pdo instanceof PDO)) {
        $pdo = $GLOBALS['pdo'] ?? null;
    }
    if (!($pdo instanceof PDO)) {
        return '';
    }

    $token = bin2hex(random_bytes(24));
    try {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('cron_token', ?)
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([$token]);
    } catch (PDOException $e) {
        error_log('Gagal menyimpan cron_token: ' . $e->getMessage());
        return '';
    }
    // all_settings() menyimpan cache statis per permintaan, jadi nilai baru
    // ini diingat sendiri di sini alih-alih memaksa pembacaan ulang.
    return $ingat = $token;
}

/**
 * Hentikan permintaan yang tidak berhak.
 *
 * Dari CLI selalu diizinkan (itulah cara cron server memanggilnya).
 * Lewat HTTP wajib menyertakan ?token= yang cocok.
 */
function cron_require_auth($pdo = null)
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $sah = cron_token($pdo);
    $diberi = (string)($_GET['token'] ?? $_GET['_cron_key'] ?? '');

    // hash_equals: perbandingan berwaktu tetap, supaya token tidak dapat
    // ditebak sepotong demi sepotong lewat pengukuran waktu balasan.
    if ($sah === '' || !hash_equals($sah, $diberi)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        die("Forbidden.\n");
    }
}

/** URL lengkap untuk menjalankan satu skrip cron lewat HTTP. */
function cron_url($skrip, $pdo = null)
{
    return rtrim(BASE_URL, '/') . '/cron/' . $skrip . '?token=' . rawurlencode(cron_token($pdo));
}
