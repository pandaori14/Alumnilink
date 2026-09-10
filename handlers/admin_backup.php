<?php
/**
 * Unduh cadangan basis data.
 *
 * Berkas yang dihasilkan memuat hash kata sandi, e-mail, nomor telepon, dan
 * alamat rumah SETIAP alumni. Karena itu:
 *   - hanya super_admin (kapabilitas 'pengaturan.kelola' terkunci super_admin
 *     lewat capability_super_admin_only(), ditambah pemeriksaan peran eksplisit
 *     supaya tidak bergantung pada satu lapisan saja);
 *   - wajib POST + token CSRF, sehingga tidak dapat dipicu lewat tautan
 *     gambar atau kunjungan diam-diam;
 *   - dibatasi lajunya, karena dump memindai seluruh tabel;
 *   - SELALU dicatat di Audit Trail — cadangan tidak boleh pernah diam-diam.
 *
 * Dialirkan langsung ke peramban, tidak ditulis ke disk lebih dulu, supaya
 * tidak ada salinan data pribadi yang tertinggal di server.
 */

require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_capability('pengaturan.kelola');
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/backup_lib.php';

// Lapisan kedua: eksplisit, tidak bergantung pada turunan kapabilitas.
if (current_role() !== 'super_admin') {
    error_forbidden('Cadangan basis data hanya dapat diunduh oleh Super Admin.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php?page=admin_settings');
    exit;
}
validate_csrf();

check_rate_limit('BACKUP_DB',
    setting_int('rate_limit_backup_max', 5, 1),
    setting_int('rate_limit_backup_window', 30, 1));

$gz   = backup_gzip_tersedia();
$nama = backup_nama_berkas($gz);

// Catat SEBELUM mengalirkan: begitu keluaran dimulai, tidak ada lagi
// kesempatan menulis apa pun ke basis data dengan aman.
log_activity('BACKUP_DB', 'Mengunduh cadangan basis data: ' . $nama);

// Matikan buffer keluaran apa pun supaya dump tidak menumpuk di memori.
while (ob_get_level() > 0) {
    ob_end_clean();
}
@set_time_limit(0);

header('Content-Type: application/' . ($gz ? 'gzip' : 'sql'));
header('Content-Disposition: attachment; filename="' . $nama . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'wb');

if ($gz) {
    [$tulis, $tutup] = backup_gzip_writer($out);
    backup_write_sql($pdo, $out, $tulis);
    $tutup();
} else {
    backup_write_sql($pdo, $out);
}

fclose($out);
reset_rate_limit('BACKUP_DB');
exit;
