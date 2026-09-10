<?php
/**
 * Avatar inisial, dirender lokal.
 *
 * ── Mengapa ada ────────────────────────────────────────────────────────
 * Sebelumnya avatar diambil dari https://ui-avatars.com/api/?name=...
 * Artinya, setiap kali halaman Profil, Data Alumni, atau Manajemen Pengguna
 * dibuka, peramban pengguna mengirimkan NAMA ASLI alumni ke server pihak
 * ketiga — lengkap dengan Referer yang menunjukkan halaman admin mana yang
 * sedang dibuka, dan alamat IP pembacanya. Tidak ada perjanjian pemrosesan
 * data dengan layanan itu, dan tidak ada alasan teknis untuk mengirimnya:
 * yang dihasilkan hanyalah dua huruf di atas lingkaran berwarna.
 *
 * Berkas ini menghasilkan gambar yang sama secara lokal. Tidak ada data
 * yang keluar dari server, dan peta tetap tampil bila internet mati.
 *
 * Pemakaian:  avatar.php?name=Budi%20Santoso&size=128
 *             avatar.php?name=...&bg=0066FF   (warna dipaksa)
 *             tanpa bg  -> warna diturunkan dari nama (stabil per orang)
 *
 * Tidak memerlukan sesi: keluarannya sepenuhnya ditentukan parameter yang
 * SUDAH dimiliki pemanggil, sehingga tidak ada yang bisa digali darinya.
 * Dibiarkan publik supaya bisa di-cache peramban dan tidak mengunci sesi.
 */

require_once __DIR__ . '/includes/settings.php';

/** Ambil maksimal dua huruf awal dari sebuah nama. */
function avatar_inisial($nama)
{
    // Gelar pada nama Indonesia hampir selalu mengandung titik
    // (dr., Sp.PD, S.Ked, M.Kes, Muh.) — itulah penandanya, bukan panjang
    // katanya. Menebak dari daftar huruf pendek justru memakan huruf awal
    // nama aslinya ("Santoso" ikut terpangkas oleh pola "s").
    $gelar_utuh = ['dr', 'drg', 'prof', 'ir', 'haji', 'hajjah'];

    $semua = preg_split('/[\s,]+/u', trim((string)$nama), -1, PREG_SPLIT_NO_EMPTY);
    $kata  = [];
    foreach ($semua as $k) {
        if (strpos($k, '.') !== false) {
            continue;                                   // singkatan/gelar
        }
        if (in_array(mb_strtolower($k, 'UTF-8'), $gelar_utuh, true)) {
            continue;
        }
        if (!preg_match('/\p{L}/u', $k)) {
            continue;                                   // angka/tanda baca
        }
        $kata[] = preg_replace('/[^\p{L}]/u', '', $k);
    }
    $kata = array_values(array_filter($kata, 'strlen'));

    if (!$kata) {
        // Seluruhnya gelar atau tanda baca: pakai apa pun yang berhuruf.
        foreach ($semua as $k) {
            $bersih = preg_replace('/[^\p{L}]/u', '', $k);
            if ($bersih !== '') {
                $kata[] = $bersih;
            }
        }
    }
    if (!$kata) {
        return '?';
    }

    $ambil = function ($k) {
        return function_exists('mb_substr')
            ? mb_strtoupper(mb_substr($k, 0, 1, 'UTF-8'), 'UTF-8')
            : strtoupper(substr($k, 0, 1));
    };

    if (count($kata) === 1) {
        return $ambil($kata[0]);
    }
    return $ambil($kata[0]) . $ambil($kata[count($kata) - 1]);
}

/**
 * Warna latar yang stabil untuk sebuah nama.
 * Orang yang sama selalu mendapat warna yang sama, sehingga daftar pengguna
 * tetap mudah dipindai mata — meniru perilaku "background=random" lama tanpa
 * betul-betul acak (yang akan berubah tiap muat ulang).
 */
function avatar_warna($nama)
{
    $palet = ['#2563eb', '#7c3aed', '#db2777', '#dc2626', '#ea580c',
              '#ca8a04', '#16a34a', '#0d9488', '#0284c7', '#4f46e5'];
    return $palet[hexdec(substr(md5((string)$nama), 0, 8)) % count($palet)];
}

$nama = (string)($_GET['name'] ?? '');
$size = (int)($_GET['size'] ?? 128);
if ($size < 16)  { $size = 16; }
if ($size > 512) { $size = 512; }

$bg = $_GET['bg'] ?? '';
if (is_string($bg) && preg_match('/^#?[0-9a-fA-F]{6}$/', $bg)) {
    $warna = '#' . ltrim($bg, '#');
} elseif ($bg === 'brand') {
    $warna = brand_primary_color();
} else {
    $warna = avatar_warna($nama);
}

$inisial = avatar_inisial($nama);
$fs      = round($size * 0.4, 2);

header('Content-Type: image/svg+xml; charset=utf-8');
// Isi hanya bergantung pada parameter URL, jadi aman di-cache lama.
header('Cache-Control: public, max-age=604800, immutable');
// SVG dapat memuat skrip. Berkas ini tidak membuatnya, tetapi header di bawah
// memastikan tidak ada yang berjalan seandainya markup berubah kelak.
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
header('X-Content-Type-Options: nosniff');

$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8'); };

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<svg xmlns="http://www.w3.org/2000/svg" width="<?= $size ?>" height="<?= $size ?>" viewBox="0 0 <?= $size ?> <?= $size ?>" role="img" aria-label="<?= $e($nama) ?>">
  <rect width="<?= $size ?>" height="<?= $size ?>" fill="<?= $e($warna) ?>"/>
  <text x="50%" y="50%" dy=".35em" text-anchor="middle"
        font-family="Segoe UI, Helvetica, Arial, sans-serif"
        font-size="<?= $fs ?>" font-weight="700" fill="#ffffff"><?= $e($inisial) ?></text>
</svg>
