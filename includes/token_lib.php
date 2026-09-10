<?php
/**
 * Token bertanda-tangan untuk tautan yang dikirim lewat e-mail.
 *
 * ── Mengapa ada ────────────────────────────────────────────────────────
 * Tautan "berhenti berlangganan" sebelumnya memakai:
 *
 *     md5($email . 'alumnilink_salt')
 *
 * Tiga hal salah sekaligus di sana:
 *
 * 1. Salt-nya TERTULIS DI KODE SUMBER. Kode ini dilisensikan kepada
 *    pelanggan dan kini juga berada di repositori publik, sehingga setiap
 *    pemasangan memakai salt yang sama. Siapa pun yang membaca sumbernya
 *    dapat menghitung token untuk alamat mana pun, di pemasangan mana pun,
 *    lalu memberhentikan langganan orang lain.
 * 2. MD5 tidak layak lagi untuk tanda tangan.
 * 3. Perbandingannya memakai `!==` biasa, yang waktunya bergantung pada
 *    berapa karakter pertama yang cocok.
 *
 * Penggantinya memakai HMAC-SHA256 dengan rahasia yang DIBUAT ACAK saat
 * pemasangan pertama dan disimpan di basis data — pola yang sama persis
 * dengan cron_token di includes/cron_auth.php.
 */

require_once __DIR__ . '/settings.php';

/**
 * Rahasia penanda-tangan milik pemasangan ini.
 * Dibuat sekali, lalu disimpan. Tidak pernah ada di kode sumber.
 */
function app_signing_secret($pdo = null)
{
    static $ingat = null;
    if ($ingat !== null) {
        return $ingat;
    }

    $rahasia = setting('app_signing_secret', '');
    if ($rahasia !== '') {
        return $ingat = $rahasia;
    }

    if (!($pdo instanceof PDO)) {
        $pdo = $GLOBALS['pdo'] ?? null;
    }
    if (!($pdo instanceof PDO)) {
        return '';
    }

    $rahasia = bin2hex(random_bytes(32));
    try {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('app_signing_secret', ?)
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([$rahasia]);
    } catch (PDOException $e) {
        error_log('Gagal menyimpan app_signing_secret: ' . $e->getMessage());
        return '';
    }
    return $ingat = $rahasia;
}

/**
 * Tanda tangan untuk sebuah nilai, dibatasi pada satu keperluan.
 *
 * $tujuan memisahkan token antar-fitur: token berhenti-langganan tidak
 * dapat dipakai ulang sebagai token verifikasi, meski alamatnya sama.
 */
function sign_token($nilai, $tujuan = 'umum', $pdo = null)
{
    $rahasia = app_signing_secret($pdo);
    if ($rahasia === '') {
        return '';
    }
    return hash_hmac('sha256', $tujuan . '|' . $nilai, $rahasia);
}

/**
 * Benar bila token cocok.
 *
 * hash_equals dipakai agar perbandingannya berwaktu tetap — tanpa itu,
 * token dapat ditebak sepotong demi sepotong lewat pengukuran waktu balasan.
 */
function verify_token($nilai, $token, $tujuan = 'umum', $pdo = null)
{
    $sah = sign_token($nilai, $tujuan, $pdo);
    if ($sah === '' || !is_string($token) || $token === '') {
        return false;
    }

    if (hash_equals($sah, $token)) {
        return true;
    }

    // ── Masa peralihan ────────────────────────────────────────────────
    // Tautan lama yang sudah terlanjur ada di kotak masuk alumni memakai
    // md5($email . 'alumnilink_salt'). Menolaknya begitu saja akan membuat
    // tombol "berhenti berlangganan" di e-mail lama berhenti bekerja —
    // dan itu justru mendorong orang menandai e-mail kita sebagai spam,
    // yang jauh lebih merugikan daripada salt lama yang bocor.
    //
    // Diterima hanya untuk keperluan berhenti-langganan, dan hanya sampai
    // pengaturan legacy_unsubscribe_token dimatikan (bawaan: aktif).
    if ($tujuan === 'unsubscribe' && setting('legacy_unsubscribe_token', '1') === '1') {
        return hash_equals(md5($nilai . 'alumnilink_salt'), $token);
    }

    return false;
}
