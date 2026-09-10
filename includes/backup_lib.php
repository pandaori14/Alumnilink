<?php
/**
 * Cadangan basis data — murni PHP.
 *
 * ── Mengapa tidak memakai mysqldump ────────────────────────────────────
 * Hosting bersama umumnya mematikan exec()/shell_exec(), dan sering tidak
 * menyediakan biner mysqldump sama sekali. Sistem ini juga di-deploy lewat
 * FTP tanpa akses shell. Cadangan yang bergantung pada perintah luar akan
 * bekerja di komputer lokal lalu diam-diam gagal di server — persis jenis
 * kegagalan yang paling berbahaya untuk sebuah cadangan.
 *
 * Karena itu dump disusun sendiri dari SHOW CREATE TABLE + SELECT.
 *
 * ── Yang dijaga di sini ────────────────────────────────────────────────
 * - Baris dialirkan satu per satu (kursor tanpa buffer), bukan dimuat
 *   seluruhnya ke memori. Ukuran basis data tidak memengaruhi pemakaian RAM.
 * - Nilai di-escape lewat PDO::quote(), termasuk NULL dan data biner.
 * - FOREIGN_KEY_CHECKS dimatikan selama restore, karena urutan tabel dalam
 *   dump tidak dijamin sesuai urutan ketergantungan foreign key.
 *
 * ── ISI DUMP ADALAH SELURUH DATA PRIBADI ───────────────────────────────
 * Hash kata sandi, e-mail, nomor telepon, dan alamat rumah SETIAP alumni
 * ada di dalamnya. Berkas ini setara dengan kunci induk: hanya super_admin
 * yang boleh membuatnya, tidak boleh disimpan di folder yang bisa dijangkau
 * web, dan setiap pembuatannya dicatat di Audit Trail.
 */

/** Tabel yang isinya tidak perlu ikut (strukturnya tetap ikut). */
function backup_tabel_tanpa_isi()
{
    return [
        'rate_limits',            // hanya penghitung sementara
        'midtrans_notifications', // log mentah dari pihak ketiga
    ];
}

/**
 * Tulis dump SQL ke sebuah stream.
 *
 * @param resource $out    Tujuan penulisan (php://output, berkas, dsb).
 * @param callable|null $tulis Penulis khusus (mis. untuk kompresi); bila null
 *                             memakai fwrite biasa.
 * @return array{tabel:int,baris:int,byte:int}
 */
function backup_write_sql($pdo, $out, $tulis = null)
{
    if ($tulis === null) {
        $tulis = function ($teks) use ($out) { return fwrite($out, $teks); };
    }

    $byte = 0;
    $w = function ($teks) use ($tulis, &$byte) {
        $byte += strlen($teks);
        $tulis($teks);
    };

    $nama_db = $pdo->query('SELECT DATABASE()')->fetchColumn();

    $w("-- AlumniLink — cadangan basis data\n");
    $w('-- Basis data : ' . $nama_db . "\n");
    $w('-- Dibuat     : ' . date('Y-m-d H:i:s') . "\n");
    $w('-- Versi skema: ' . (defined('ALUMNILINK_SCHEMA_VERSION') ? ALUMNILINK_SCHEMA_VERSION : '?') . "\n");
    $w("--\n");
    $w("-- Cara memulihkan (phpMyAdmin -> Import, atau baris perintah):\n");
    $w("--   mysql -u <user> -p <nama_db> < berkas_ini.sql\n");
    $w("--\n");
    $w("-- PERINGATAN: berkas ini memuat hash kata sandi dan data pribadi\n");
    $w("-- seluruh alumni. Simpan seperti Anda menyimpan kata sandi.\n\n");

    $w("SET NAMES utf8mb4;\n");
    $w("SET FOREIGN_KEY_CHECKS = 0;\n");
    $w("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

    $tabel = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $tanpa_isi = backup_tabel_tanpa_isi();
    $n_baris = 0;

    foreach ($tabel as $t) {
        $struktur = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $t) . '`')->fetch(PDO::FETCH_NUM);
        $w("\n--\n-- Struktur tabel `$t`\n--\n\n");
        $w("DROP TABLE IF EXISTS `$t`;\n");
        $w($struktur[1] . ";\n\n");

        if (in_array($t, $tanpa_isi, true)) {
            $w("-- (isi tabel `$t` sengaja tidak ikut dicadangkan)\n");
            continue;
        }

        // Kursor tanpa buffer: baris diambil satu per satu dari server,
        // sehingga pemakaian memori tetap datar berapa pun besar tabelnya.
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $stmt = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $t) . '`');

        $pertama = true;
        $dalam_baris = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($pertama) {
                $w("--\n-- Isi tabel `$t`\n--\n\n");
                $kolom = '`' . implode('`, `', array_map(fn($k) => str_replace('`', '``', $k), array_keys($row))) . '`';
                $pertama = false;
            }

            $nilai = [];
            foreach ($row as $v) {
                $nilai[] = ($v === null) ? 'NULL' : $pdo->quote((string)$v);
            }

            if ($dalam_baris === 0) {
                $w("INSERT INTO `$t` ($kolom) VALUES\n(" . implode(', ', $nilai) . ')');
            } else {
                $w(",\n(" . implode(', ', $nilai) . ')');
            }

            $dalam_baris++;
            $n_baris++;
            // Satu pernyataan INSERT per 100 baris: cukup ringkas untuk
            // dipulihkan cepat, tetapi tetap di bawah max_allowed_packet.
            if ($dalam_baris >= 100) {
                $w(";\n");
                $dalam_baris = 0;
            }
        }
        if ($dalam_baris > 0) {
            $w(";\n");
        }
        $stmt->closeCursor();
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }

    $w("\nSET FOREIGN_KEY_CHECKS = 1;\n");
    $w('-- Selesai: ' . count($tabel) . " tabel, $n_baris baris.\n");

    return ['tabel' => count($tabel), 'baris' => $n_baris, 'byte' => $byte];
}

/**
 * Nama berkas cadangan yang mengurut sendiri secara kronologis.
 */
function backup_nama_berkas($gz = true)
{
    return 'alumnilink_' . date('Ymd_His') . '.sql' . ($gz ? '.gz' : '');
}

/** Benar bila kompresi gzip beraliran tersedia. */
function backup_gzip_tersedia()
{
    return function_exists('deflate_init') && function_exists('deflate_add');
}

/**
 * Bungkus penulis dengan kompresi gzip beraliran.
 * Mengembalikan [penulis, penutup]; penutup WAJIB dipanggil di akhir.
 */
function backup_gzip_writer($out)
{
    $ctx = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
    $tulis = function ($teks) use ($ctx, $out) {
        fwrite($out, deflate_add($ctx, $teks, ZLIB_NO_FLUSH));
    };
    $tutup = function () use ($ctx, $out) {
        fwrite($out, deflate_add($ctx, '', ZLIB_FINISH));
    };
    return [$tulis, $tutup];
}

/**
 * Direktori penyimpanan cadangan terjadwal.
 * Dibuat bila belum ada, beserta .htaccess penolak — folder ini berada di
 * dalam webroot, jadi ia HARUS menolak permintaan HTTP dengan caranya
 * sendiri dan tidak boleh bergantung pada aturan di .htaccess akar saja.
 */
function backup_dir()
{
    $dir = dirname(__DIR__) . '/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $ht = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($ht)) {
        @file_put_contents($ht,
            "# Cadangan memuat hash kata sandi dan data pribadi seluruh alumni.\n" .
            "# Folder ini tidak boleh dapat dijangkau lewat HTTP.\n" .
            "<IfModule mod_rewrite.c>\n" .
            "    RewriteEngine On\n" .
            "    RewriteRule ^ - [F,L]\n" .
            "</IfModule>\n" .
            "<IfModule !mod_rewrite.c>\n" .
            "    Deny from all\n" .
            "</IfModule>\n");
    }
    if (is_dir($dir) && !is_file($dir . '/index.html')) {
        @file_put_contents($dir . '/index.html', '');   // cegah daftar isi folder
    }
    return $dir;
}

/**
 * Hapus cadangan lama, sisakan $simpan berkas terbaru.
 * @return int Jumlah berkas yang dihapus.
 */
function backup_rotate($simpan = 7)
{
    $dir = backup_dir();
    $berkas = glob($dir . '/alumnilink_*.sql*') ?: [];
    if (count($berkas) <= $simpan) {
        return 0;
    }
    // Nama berkas memuat stempel waktu Ymd_His, jadi urutan abjad = kronologis.
    sort($berkas);
    $hapus = array_slice($berkas, 0, count($berkas) - $simpan);
    $n = 0;
    foreach ($hapus as $f) {
        if (@unlink($f)) { $n++; }
    }
    return $n;
}

/** Daftar cadangan yang tersimpan, terbaru lebih dulu. */
function backup_daftar()
{
    $dir = backup_dir();
    $berkas = glob($dir . '/alumnilink_*.sql*') ?: [];
    rsort($berkas);
    $out = [];
    foreach ($berkas as $f) {
        $out[] = [
            'nama'  => basename($f),
            'byte'  => (int)@filesize($f),
            'waktu' => (int)@filemtime($f),
        ];
    }
    return $out;
}
