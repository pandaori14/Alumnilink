<?php
/**
 * Landasan bersama seluruh suite uji.
 *
 * ── Mengapa berkas ini ada ─────────────────────────────────────────────
 * Kedua belas suite ini semula ditulis di direktori sementara, dengan
 * jalur `c:/xampp/htdocs/alumnilink/...` dan `http://localhost/alumnilink`
 * tertulis langsung di dalamnya. Selama masih begitu, ia hanya bisa
 * dijalankan di satu komputer oleh satu orang — dan uji yang tidak bisa
 * dijalankan orang lain sama tidak bergunanya dengan tidak ada uji.
 *
 * Berkas ini memindahkan kedua nilai itu ke satu tempat:
 *
 *   akar proyek : diturunkan dari lokasi berkas ini
 *   BASE URL    : dari argumen CLI, lalu variabel lingkungan, lalu bawaan
 *
 * Pemakaian di setiap suite:
 *     require_once __DIR__ . '/_bootstrap.php';
 */

// ── Akar proyek: dua tingkat di atas tests/suite/ ────────────────────
define('AKAR', dirname(__DIR__, 2));

require_once AKAR . '/config/db.php';

/**
 * Alamat dasar aplikasi yang diuji.
 *
 * Urutan: argumen pertama CLI -> ALUMNILINK_URL -> bawaan lokal.
 *
 * BASE_URL dari config SENGAJA TIDAK dipakai, meski tampak masuk akal.
 * BASE_URL diturunkan dari APP_URL di .env, dan .env di folder ini memuat
 * alamat PRODUKSI. Menjadikannya cadangan berarti `php tests/suite/x.php`
 * tanpa argumen akan menembakkan permintaan HTTP — termasuk POST ke handler
 * yang menulis data — ke server sungguhan. Bawaan harus selalu localhost;
 * menguji sasaran lain harus disebut secara sadar.
 */
function uji_base_url()
{
    static $url = null;
    if ($url !== null) {
        return $url;
    }
    global $argv;
    foreach ([
        $argv[1] ?? '',
        getenv('ALUMNILINK_URL') ?: '',
        'http://localhost/alumnilink',
    ] as $kandidat) {
        if (is_string($kandidat) && preg_match('#^https?://#', $kandidat)) {
            return $url = rtrim($kandidat, '/');
        }
    }
    return $url = 'http://localhost/alumnilink';
}

$BASE = uji_base_url();

/**
 * Pagar: uji tidak boleh dapat mengirim e-mail sungguhan.
 *
 * Ini bukan kehati-hatian teoretis. Kotak-pasir e-mail di
 * includes/mailer.php hanya aktif bila APP_ENV === 'local'; selama .env
 * menyetel APP_ENV="production", smtp_force_real = 0 sama sekali tidak
 * mencegah pengiriman. Sebelum pagar ini ada, 132 e-mail uji benar-benar
 * terkirim — tiga di antaranya ke alumni sungguhan.
 *
 * Berhenti di sini lebih baik daripada mengirimi orang surat palsu.
 */
if ((getenv('APP_ENV') ?: 'local') !== 'local'
    && !in_array('--izinkan-kirim-email', $argv ?? [], true)) {
    fwrite(STDERR,
        "
  BERHENTI: APP_ENV = '" . getenv('APP_ENV') . "', bukan 'local'.

" .
        "  Kotak-pasir e-mail di includes/mailer.php hanya aktif pada APP_ENV
" .
        "  'local'. Menjalankan uji sekarang akan MENGIRIM E-MAIL SUNGGUHAN
" .
        "  ke alamat yang ada di basis data.

" .
        "  Perbaiki dengan menambahkan APP_ENV=local ke .env.local

");
    exit(2);
}

// ── Penghitung hasil ─────────────────────────────────────────────────
$pass = 0;
$fail = 0;

if (!function_exists('cek')) {
    function cek($ok, $l, $d = '')
    {
        global $pass, $fail;
        if ($ok) { $pass++; printf("  LULUS  %-52s %s\n", $l, $d); }
        else     { $fail++; printf("  GAGAL  %-52s %s\n", $l, $d); }
    }
}

/**
 * Buat berkas sesi palsu berperan tertentu.
 *
 * Menulis langsung ke penyimpanan sesi PHP, bukan lewat proses masuk,
 * supaya uji tidak bergantung pada kata sandi akun mana pun dan dapat
 * memerankan peran yang belum punya akun sungguhan (admin_tracer,
 * admin_legalisir, keuangan saat ini belum ada satu pun akunnya).
 */
if (!function_exists('sesi_palsu')) {
    function sesi_palsu($uid, $role, $csrf = null)
    {
        $csrf = $csrf ?: str_repeat('a', 32);
        $sid  = bin2hex(random_bytes(16));
        $path = session_save_path() ?: sys_get_temp_dir();
        file_put_contents($path . '/sess_' . $sid,
            "user_id|s:" . strlen($uid) . ":\"$uid\";" .
            "user_role|s:" . strlen($role) . ":\"$role\";" .
            "user_name|s:3:\"Uji\";last_activity|i:" . time() . ";" .
            "csrf_token|s:" . strlen($csrf) . ":\"$csrf\";");
        return $sid;
    }
}

if (!function_exists('sesi_hapus')) {
    function sesi_hapus(...$sid)
    {
        $path = session_save_path() ?: sys_get_temp_dir();
        foreach ($sid as $s) {
            if ($s) { @unlink($path . '/sess_' . $s); }
        }
    }
}

/** Direktori fixture, relatif terhadap berkas suite. */
if (!function_exists('uji_fixture')) {
    function uji_fixture($nama = '')
    {
        return __DIR__ . '/fixtures' . ($nama ? '/' . $nama : '');
    }
}
