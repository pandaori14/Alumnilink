<?php
/**
 * CRON: cadangan basis data terjadwal.
 *
 * Menulis satu berkas .sql.gz ke folder backups/ lalu membuang yang paling
 * lama sehingga hanya beberapa yang terbaru tersimpan.
 *
 * Jalankan:
 *   php cron/backup.php
 * atau lewat HTTP dengan token dari Pengaturan Sistem:
 *   https://.../alumnilink/cron/backup.php?token=...
 *
 * CATATAN PENTING soal keterbatasannya:
 * Cadangan ini tersimpan di server yang SAMA dengan basis datanya. Itu
 * melindungi dari kesalahan manusia — impor yang keliru, penghapusan tak
 * sengaja, migrasi yang meleset — dan itulah risiko yang paling sering
 * terjadi. Ia TIDAK melindungi dari kegagalan server atau akun hosting
 * hilang. Untuk itu, unduh berkasnya secara berkala ke komputer atau
 * penyimpanan awan.
 */

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/cron_auth.php';
require_once dirname(__DIR__) . '/includes/backup_lib.php';

cron_require_auth($pdo);

@set_time_limit(0);
$mulai = microtime(true);
$log = function ($p) { echo '[' . date('Y-m-d H:i:s') . '] ' . $p . PHP_EOL; };

$dir = backup_dir();
if (!is_dir($dir) || !is_writable($dir)) {
    $log("GAGAL: folder $dir tidak dapat ditulis.");
    // Kegagalan cadangan yang tidak terlihat lebih berbahaya daripada tidak
    // punya cadangan sama sekali — orang akan mengira dirinya terlindungi.
    if (function_exists('notify_roles')) {
        notify_roles(['super_admin'], 'Cadangan basis data GAGAL',
            'Folder backups/ tidak dapat ditulis di server. Cadangan otomatis tidak berjalan.',
            'error', 'index.php?page=admin_settings');
    }
    exit(1);
}

$gz   = backup_gzip_tersedia();
$nama = backup_nama_berkas($gz);
// Ditulis ke berkas sementara lebih dulu, baru diganti nama setelah selesai.
// Dengan begitu berkas separuh jadi tidak pernah tampak sebagai cadangan sah
// bila prosesnya mati di tengah.
$sementara = $dir . '/.tmp_' . $nama;
$tujuan    = $dir . '/' . $nama;

$out = @fopen($sementara, 'wb');
if (!$out) {
    $log("GAGAL: tidak dapat membuat $sementara");
    exit(1);
}

try {
    if ($gz) {
        [$tulis, $tutup] = backup_gzip_writer($out);
        $hasil = backup_write_sql($pdo, $out, $tulis);
        $tutup();
    } else {
        $hasil = backup_write_sql($pdo, $out);
    }
    fclose($out);
} catch (Throwable $e) {
    fclose($out);
    @unlink($sementara);
    $log('GAGAL: ' . $e->getMessage());
    error_log('Cadangan gagal: ' . $e->getMessage());
    exit(1);
}

if (!@rename($sementara, $tujuan)) {
    @unlink($sementara);
    $log('GAGAL: tidak dapat menyelesaikan penulisan berkas.');
    exit(1);
}

$ukuran = (int)filesize($tujuan);
$simpan = setting_int('backup_keep', 7, 1);
$dibuang = backup_rotate($simpan);
$detik = round(microtime(true) - $mulai, 2);

$log(sprintf('Selesai: %s (%s), %d tabel, %d baris, %ss. Dibuang: %d, tersimpan: %d.',
    $nama, number_format($ukuran / 1024, 1) . ' KB',
    $hasil['tabel'], $hasil['baris'], $detik, $dibuang, count(backup_daftar())));

log_activity('BACKUP_DB_CRON',
    sprintf('%s (%s KB), %d tabel, %d baris; %d berkas lama dibuang.',
        $nama, number_format($ukuran / 1024, 1), $hasil['tabel'], $hasil['baris'], $dibuang));
