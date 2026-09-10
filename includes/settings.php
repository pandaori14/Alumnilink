<?php
/**
 * includes/settings.php
 * ─────────────────────────────────────────────────────────
 * Titik akses tunggal untuk seluruh pengaturan sistem.
 *
 * MASALAH YANG DIPERBAIKI
 * Sejumlah aturan penting sebelumnya tertulis langsung di dalam kode, dan
 * beberapa di antaranya tersebar di banyak berkas sekaligus. Contoh terburuk:
 * masa berlaku tracer "6 bulan" muncul di EMPAT berkas terpisah, sehingga
 * mengubah kebijakan berarti menyunting empat tempat dan berisiko tidak
 * sinkron.
 *
 * Berkas ini memindahkan nilai-nilai tersebut ke tabel `settings` dengan
 * nilai bawaan yang sama seperti sebelumnya, sehingga:
 *   - perilaku sistem TIDAK berubah bila pengaturannya belum diisi;
 *   - superadmin dapat mengubahnya lewat menu Pengaturan Sistem;
 *   - hanya ada satu sumber kebenaran.
 *
 * PEMAKAIAN
 *     require_once __DIR__ . '/settings.php';
 *     $bulan = setting_int('tracer_validity_months', 6);
 *     $warna = setting('brand_primary_color', '#2563eb');
 */

/**
 * Escape keluaran HTML.
 *
 * ── Mengapa ada di sini ────────────────────────────────────────────────
 * Berkas ini sudah dimuat global lewat config/db.php, jadi e() tersedia di
 * setiap halaman dan handler tanpa require tambahan. Halaman yang lupa
 * me-require pustaka akan gagal saat DIJALANKAN padahal `php -l` bersih —
 * itu justru jenis kegagalan yang paling mudah lolos ke server.
 *
 * ENT_QUOTES wajib. Tanpanya tanda kutip ganda TIDAK ikut di-escape, dan
 * justru pola <input value="<?= ... ?>"> yang bisa dibobol:
 *
 *     ?start_date=" onfocus=alert(1) autofocus x="
 *     -> value="" onfocus=alert(1) autofocus x=""
 *
 * Nama sependek e() disengaja. Pemanggilannya muncul ratusan kali di markup;
 * <?= e($u->name) ?> masih terbaca, sedangkan bentuk panjangnya membuat baris
 * markup tidak terbaca lagi — dan yang tidak terbaca akan dilewati orang.
 */
function e($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/**
 * Seluruh pengaturan sebagai array asosiatif, dibaca sekali per permintaan.
 */
function all_settings()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    global $pdo;

    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            foreach ($pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll() as $row) {
                // PDO di proyek ini memakai mode objek secara bawaan.
                $key = is_object($row) ? $row->setting_key   : $row['setting_key'];
                $val = is_object($row) ? $row->setting_value : $row['setting_value'];
                $cache[$key] = $val;
            }
        } catch (PDOException $e) {
            error_log('Gagal membaca settings: ' . $e->getMessage());
        }
    }

    return $cache;
}

/**
 * Ambil satu pengaturan sebagai teks.
 * Nilai kosong dianggap "belum diisi" sehingga nilai bawaan yang dipakai.
 */
function setting($key, $default = '')
{
    $all = all_settings();
    if (!isset($all[$key]) || $all[$key] === '' || $all[$key] === null) {
        return $default;
    }
    return $all[$key];
}

/** Ambil pengaturan sebagai bilangan bulat, dengan batas minimum opsional. */
function setting_int($key, $default = 0, $min = null)
{
    $val = (int)setting($key, (string)$default);
    if ($min !== null && $val < $min) {
        return $default;
    }
    return $val;
}

/** Ambil pengaturan sebagai boolean ('1' = benar). */
function setting_bool($key, $default = false)
{
    return setting($key, $default ? '1' : '0') === '1';
}

// ─────────────────────────────────────────────────────────
// PEMBANTU KHUSUS
// ─────────────────────────────────────────────────────────

/**
 * Ambang waktu "tracer masih berlaku".
 *
 * Sebelumnya ditulis sebagai strtotime('-6 months') di empat berkas.
 * Sekarang seluruhnya memanggil fungsi ini.
 */
function tracer_validity_threshold()
{
    $months = setting_int('tracer_validity_months', 6, 1);
    return date('Y-m-d H:i:s', strtotime("-{$months} months"));
}

/** Benar bila alumni perlu memperbarui tracer sebelum memakai layanan. */
function tracer_needs_update($last_tracer_update)
{
    if (empty($last_tracer_update)) {
        return true;
    }
    return $last_tracer_update < tracer_validity_threshold();
}

/** Kode program studi yang dipakai bila prodi alumni tidak dikenali. */
function default_major_code()
{
    return setting('default_major_code', 'J500');
}

/** Panjang minimal kata sandi. */
function password_min_length()
{
    return setting_int('password_min_length', 6, 4);
}

/** Masa berlaku tautan atur-ulang kata sandi, dalam detik. */
function reset_token_lifetime_seconds()
{
    return setting_int('reset_token_expiry_minutes', 60, 1) * 60;
}

/** Warna utama identitas visual, divalidasi sebagai kode heksadesimal. */
function brand_primary_color()
{
    $c = setting('brand_primary_color', '#2563eb');
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : '#2563eb';
}

/**
 * Luminansi relatif sebuah warna heksadesimal (rumus WCAG 2.x).
 */
function color_relative_luminance($hex)
{
    $hex = ltrim((string)$hex, '#');
    if (strlen($hex) !== 6) {
        return 0.0;
    }

    $channels = [];
    foreach ([0, 2, 4] as $i) {
        $v = hexdec(substr($hex, $i, 2)) / 255;
        $channels[] = ($v <= 0.03928) ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
    }

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

/**
 * Rasio kontras antara dua warna, rentang 1:1 (identik) sampai 21:1.
 *
 * Ambang WCAG 2.1:
 *   4.5:1 -> teks berukuran normal (level AA)
 *   3.0:1 -> teks besar dan komponen antarmuka seperti indikator fokus
 */
function color_contrast_ratio($hex1, $hex2)
{
    $l1 = color_relative_luminance($hex1);
    $l2 = color_relative_luminance($hex2);
    $light = max($l1, $l2);
    $dark  = min($l1, $l2);
    return ($light + 0.05) / ($dark + 0.05);
}

/**
 * Nilai kontras warna merek terhadap latar putih, beserta kesimpulannya.
 *
 * Dipakai halaman Pengaturan Sistem untuk memperingatkan pengelola bila warna
 * pilihannya membuat teks putih di atas warna tersebut sulit dibaca. Tanpa
 * peringatan ini, seorang pengelola dapat memilih warna cerah yang membuat
 * tombol utama praktis tidak terbaca.
 */
function brand_contrast_report($hex = null)
{
    $hex   = $hex ?: brand_primary_color();
    $ratio = color_contrast_ratio($hex, '#ffffff');

    if ($ratio >= 4.5) {
        $level = 'AA';
        $note  = 'Teks putih di atas warna ini memenuhi standar WCAG AA.';
    } elseif ($ratio >= 3.0) {
        $level = 'AA-besar';
        $note  = 'Hanya memenuhi standar untuk teks berukuran besar dan indikator fokus. Teks kecil berwarna putih akan sulit dibaca.';
    } else {
        $level = 'GAGAL';
        $note  = 'Kontras terlalu rendah. Teks putih di atas warna ini sulit dibaca; pilih warna yang lebih gelap.';
    }

    return ['ratio' => round($ratio, 2), 'level' => $level, 'note' => $note];
}

/**
 * Jumlah baris per halaman untuk tabel admin.
 * Menerima nilai bawaan berbeda per konteks agar tata letak tiap halaman
 * tetap masuk akal.
 */
function pagination_size($default = 20)
{
    return setting_int('pagination_size', $default, 5);
}
