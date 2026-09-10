<?php
/**
 * Menjaga agar jam PHP dan jam MySQL tidak pernah lagi berbeda.
 *
 * ── Mengapa berkas ini ada ─────────────────────────────────────────────
 * Aplikasi ini dulu tidak menyatakan zona waktunya sama sekali: PHP
 * mengikuti php.ini, MySQL mengikuti jam sistemnya sendiri. Pada 10
 * September 2026 keduanya diukur di produksi dan terpaut TUJUH JAM —
 * PHP di UTC (06:24), basis data di WIB (13:24), pada dua mesin berbeda.
 *
 * Selisih itu tidak menimbulkan satu pun galat. Ia hanya membuat setiap
 * perhitungan yang mencampur keduanya menghasilkan angka yang salah:
 *
 *   - Tautan atur-ulang sandi berlaku 8 jam, bukan 1 jam, karena
 *     (time() - created_time) bernilai -25200 dan tidak pernah > 3600.
 *   - Umur SLA legalisir menjadi negatif.
 *   - Label "x menit lalu" pada notifikasi menjadi negatif.
 *
 * Diam-diam salah adalah alasan berkas ini ada. Sebuah kesalahan yang
 * tidak pernah melempar pengecualian hanya dapat dicegah oleh uji yang
 * memang mencarinya.
 *
 * Diperbaiki di config/db.php: ALUMNILINK_TIMEZONE menyetel PHP, dan
 * SET time_zone menyetel sesi MySQL ke selisih yang sama.
 */
require_once __DIR__ . '/_bootstrap.php';

function cek($ok, $label, $detail = '') {
    global $pass, $fail;
    if ($ok) { $pass++; printf("  LULUS  %-52s %s\n", $label, $detail); }
    else     { $fail++; printf("  GAGAL  %-52s %s\n", $label, $detail); }
}

global $pdo;

// ── 1. Zona PHP memang disetel, bukan warisan php.ini ────────────────
cek(defined('ALUMNILINK_TIMEZONE'), 'ALUMNILINK_TIMEZONE terdefinisi',
    defined('ALUMNILINK_TIMEZONE') ? ALUMNILINK_TIMEZONE : '-');

cek(date_default_timezone_get() === ALUMNILINK_TIMEZONE,
    'zona PHP sama dengan yang disetel', date_default_timezone_get());

// ── 2. Sesi MySQL memakai selisih numerik, bukan SYSTEM ──────────────
$tz = $pdo->query("SELECT @@session.time_zone AS tz")->fetch()->tz;
cek($tz !== 'SYSTEM', 'zona sesi MySQL disetel eksplisit', $tz);

$harap = (new DateTime('now', new DateTimeZone(ALUMNILINK_TIMEZONE)))->format('P');
cek($tz === $harap, 'selisih MySQL sama dengan selisih PHP', "$tz (harap $harap)");

// ── 3. Yang sebenarnya penting: kedua jam menunjuk saat yang sama ────
$now = $pdo->query("SELECT NOW() AS n")->fetch()->n;
$skew = strtotime($now) - time();
cek(abs($skew) <= 2, 'NOW() MySQL sama dengan time() PHP',
    "selisih {$skew} detik");

// ── 4. Uji sesungguhnya: kolom yang diisi MySQL, dihitung oleh PHP ───
// Inilah pola yang rusak di produksi. Memeriksa @@time_zone saja tidak
// cukup — yang menentukan adalah nilai yang benar-benar tersimpan.
$pdo->exec("CREATE TEMPORARY TABLE uji_zona (id INT, dibuat TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO uji_zona (id) VALUES (1)");
$dibuat = $pdo->query("SELECT dibuat FROM uji_zona")->fetch()->dibuat;
$umur = time() - strtotime($dibuat);

cek($umur >= 0, 'umur baris baru tidak negatif', "{$umur} detik");
cek($umur < 5,  'umur baris baru mendekati nol',  "{$umur} detik");

// ── 5. Tiga perhitungan hilir yang dulu salah ────────────────────────
// Ditulis sebagai angka yang berarti, bukan sekadar "tidak negatif",
// supaya kegagalannya terbaca sebagai gejala yang dikenali.
cek(!($umur > 3600), 'reset sandi: token baru belum kedaluwarsa',
    "umur {$umur}s, batas 3600s");

cek((int)floor($umur / 86400) === 0, 'SLA legalisir: permohonan baru berumur 0 hari',
    (int)floor($umur / 86400) . ' hari');

cek($umur >= 0, 'notifikasi: label "lalu" tidak negatif', "{$umur} detik lalu");

// ── 6. Kolom yang diisi PHP juga harus sejalan ───────────────────────
// Sebagian kode menulis waktu dengan date() alih-alih CURRENT_TIMESTAMP.
// Keduanya harus menghasilkan nilai yang sama, kalau tidak masalahnya
// hanya berpindah, bukan hilang.
$php_now   = date('Y-m-d H:i:s');
$mysql_now = $pdo->query("SELECT NOW() AS n")->fetch()->n;
cek(abs(strtotime($php_now) - strtotime($mysql_now)) <= 2,
    'date() PHP dan NOW() MySQL menghasilkan teks yang sama',
    "$php_now vs $mysql_now");

printf("\n────────────────────────────────\n  LULUS: %d   GAGAL: %d\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
