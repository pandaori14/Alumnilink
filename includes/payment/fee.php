<?php
/**
 * Mesin biaya pembayaran — SATU-SATUNYA tempat rumus biaya ditulis.
 *
 * ── Masalah yang diperbaiki ────────────────────────────────────────────
 * Rumus gross-up sebelumnya ditulis TIGA kali, dan ketiganya berbeda:
 *
 *   pages/legalisir.php:758-766  (JS)   membagi tarif dengan 100,
 *                                       membaca midtrans_margin_admin dan
 *                                       custom_tax_value
 *   handlers/legalisir_handler.php      TIDAK membagi 100,
 *   handlers/donation_handler.php       membaca midtrans_admin_margin dan
 *                                       midtrans_custom_tax_value
 *
 * Dengan setting produksi (mdr 5.00, ppn 11.00) pembagi di handler menjadi
 * 1 - (5 + 5x11) = -59, biaya menjadi negatif, lalu diselamatkan batas
 * minimum 10.000. Alumni melihat Rp 68.344 di pratinjau dan ditagih
 * Rp 60.000 untuk satu dokumen.
 *
 * Sekarang pratinjau diambil dari api/payment_quote.php yang memanggil
 * payment_quote() di sini, dan handler memanggil fungsi yang sama. Keduanya
 * tidak mungkin berbeda lagi.
 *
 * ── Rumus ──────────────────────────────────────────────────────────────
 *   base   = dokumen x price_per_doc + ongkir     (legalisir)
 *          | nominal donasi                        (donasi)
 *   custom = payment_custom_charge_{purpose}      (Rp, flat)
 *   m      = persen/100 x (1 + ppn/100)
 *   fee    = ceil( ((base + custom) x m + flat + app) / (1 - m) )
 *   fee    = max(fee, min)
 *   total  = base + fee + custom
 *
 * Bentuknya sengaja SAMA dengan pratinjau yang dilihat alumni hari ini,
 * sehingga nominal baru = angka yang sudah mereka lihat selama ini.
 */

/** Profil biaya satu gateway dari settings. Persen tetap dalam PERSEN. */
function payment_fee_profile($gateway)
{
    $g = preg_replace('/[^a-z]/', '', (string)$gateway);
    return [
        'gateway'     => $g,
        'percent'     => (float)setting("fee_{$g}_percent", '0'),
        'vat_percent' => (float)setting("fee_{$g}_vat_percent", '0'),
        'flat'        => (int)setting("fee_{$g}_flat", '0'),
        'app'         => (int)setting("fee_{$g}_app", '0'),
        'min'         => (int)setting("fee_{$g}_min", '0'),
        'reviewed'    => setting("fee_{$g}_reviewed", '0') === '1',
    ];
}

/**
 * Galat pertama pada profil, atau null bila sah.
 *
 * Profil tidak sah MENOLAK transaksi. Alternatifnya — diam-diam menghitung
 * angka ganjil — adalah persis bagaimana biaya negatif lolos selama ini.
 */
function payment_fee_profile_error(array $p)
{
    foreach (['percent', 'vat_percent', 'flat', 'app', 'min'] as $k) {
        if (!is_finite((float)$p[$k]) || $p[$k] < 0) {
            return "Nilai biaya '$k' tidak boleh negatif.";
        }
    }
    if ($p['percent'] > 30) {
        return 'Persentase biaya gateway di atas 30% — kemungkinan salah ketik.';
    }
    if ($p['vat_percent'] > 50) {
        return 'PPN di atas 50% — kemungkinan salah ketik.';
    }
    if (payment_fee_multiplier($p) >= 0.5) {
        return 'Gabungan persen dan PPN terlalu besar untuk dihitung.';
    }
    return null;
}

/** m = persen/100 x (1 + ppn/100). Satu-satunya tempat pembagian 100. */
function payment_fee_multiplier(array $p)
{
    return ((float)$p['percent'] / 100.0) * (1.0 + (float)$p['vat_percent'] / 100.0);
}

/**
 * Biaya gateway (belum termasuk custom), dibulatkan ke atas.
 *
 * ceil() diterapkan pada round(x, 6): hasil perkalian float semacam
 * 11843,000000000002 tidak boleh menambah satu rupiah.
 */
function payment_fee_compute($base, $custom, array $p)
{
    $m = payment_fee_multiplier($p);
    $mentah = ((($base + $custom) * $m) + $p['flat'] + $p['app']) / (1.0 - $m);
    $fee = (int)ceil(round($mentah, 6));
    return max($fee, (int)$p['min'], 0);
}

/** Normalisasi nama provinsi untuk dicocokkan tanpa peduli huruf/spasi. */
function payment_normalize_province($nama)
{
    $n = strtolower(trim((string)$nama));
    $n = preg_replace('/\s+/', ' ', $n);
    return preg_replace('/^provinsi\s+/', '', $n);
}

/**
 * Ongkir untuk sebuah provinsi.
 *
 * Urutan cadangan sama dengan handler lama: zona yang memuat provinsi ->
 * zona bawaan -> settings.shipping_fee -> 15000. Satu-satunya perbedaan:
 * pencocokan tidak lagi peka huruf besar. Klien mengirim 'Dki Jakarta'
 * sementara zona tersimpan 'DKI Jakarta', sehingga dulu alumni ditagih
 * ongkir zona bawaan padahal pratinjau menampilkan ongkir zonanya.
 */
function payment_shipping_cost($province)
{
    $zones = json_decode(setting('shipping_zones', '[]'), true) ?: [];
    $cari = payment_normalize_province($province);

    $cocok = null;
    if ($cari !== '') {
        foreach ($zones as $z) {
            foreach (($z['provinces'] ?? []) as $p) {
                if (payment_normalize_province($p) === $cari) {
                    $cocok = $z;
                    break 2;
                }
            }
        }
    }
    if (!$cocok) {
        foreach ($zones as $z) {
            if (!empty($z['is_default'])) {
                $cocok = $z;
                break;
            }
        }
    }
    if ($cocok && isset($cocok['cost'])) {
        return (int)$cocok['cost'];
    }
    return (int)setting('shipping_fee', '15000');
}

/**
 * Rincian tagihan lengkap.
 *
 * @param string      $purpose 'legalisir' | 'donasi' | 'uji'
 * @param array       $input   legalisir: doc_count, delivery_method, province
 *                             donasi/uji: amount
 * @param string|null $gateway null = gateway aktif
 * @return array ok, error, gateway, base, documents, doc_count, price_per_doc,
 *               shipping, custom, fee, total, items, profile
 */
function payment_quote($purpose, array $input, $gateway = null)
{
    $gateway = $gateway ?: payment_active_gateway_code();
    $profil = payment_fee_profile($gateway);
    $galat = payment_fee_profile_error($profil);
    if ($galat !== null) {
        return ['ok' => false, 'error' => $galat];
    }

    $doc_count = 0;
    $price = 0;
    $documents = 0;
    $shipping = 0;

    if ($purpose === 'legalisir') {
        $doc_count = max(0, (int)($input['doc_count'] ?? 0));
        if ($doc_count < 1) {
            return ['ok' => false, 'error' => 'Pilih minimal satu dokumen.'];
        }
        if ($doc_count > 50) {
            return ['ok' => false, 'error' => 'Jumlah dokumen tidak wajar.'];
        }
        $price = (int)setting('price_per_doc', '10000');
        $documents = $doc_count * $price;
        if (($input['delivery_method'] ?? '') === 'kurir') {
            $shipping = payment_shipping_cost($input['province'] ?? '');
        }
        $base = $documents + $shipping;
        $custom = (int)setting('payment_custom_charge_legalisir', '0');
    } elseif ($purpose === 'donasi' || $purpose === 'uji') {
        $base = (int)($input['amount'] ?? 0);
        if ($base < 10000) {
            return ['ok' => false, 'error' => 'Nominal minimal Rp 10.000.'];
        }
        $custom = $purpose === 'donasi' ? (int)setting('payment_custom_charge_donasi', '0') : 0;
    } else {
        return ['ok' => false, 'error' => 'Jenis pembayaran tidak dikenal.'];
    }

    $fee = payment_fee_compute($base, $custom, $profil);
    $total = $base + $fee + $custom;

    // item_details Midtrans WAJIB berjumlah sama dengan gross_amount, dan
    // item berharga 0 tidak dikirim.
    $items = [];
    if ($purpose === 'legalisir') {
        $items[] = ['id' => 'DOCS', 'name' => 'Biaya Dokumen', 'price' => $price, 'quantity' => $doc_count];
        if ($shipping > 0) {
            $items[] = ['id' => 'SHIPPING', 'name' => 'Biaya Pengiriman', 'price' => $shipping, 'quantity' => 1];
        }
    } else {
        $items[] = ['id' => strtoupper($purpose), 'name' => $purpose === 'donasi' ? 'Donasi Pokok' : 'Transaksi Uji', 'price' => $base, 'quantity' => 1];
    }
    if ($fee + $custom > 0) {
        $items[] = ['id' => 'FEE', 'name' => 'Biaya Admin & Layanan', 'price' => $fee + $custom, 'quantity' => 1];
    }

    return [
        'ok'            => true,
        'error'         => null,
        'purpose'       => $purpose,
        'gateway'       => $gateway,
        'base'          => $base,
        'documents'     => $documents,
        'doc_count'     => $doc_count,
        'price_per_doc' => $price,
        'shipping'      => $shipping,
        'custom'        => $custom,
        'fee'           => $fee,
        'admin_total'   => $fee + $custom,
        'total'         => $total,
        'items'         => $items,
        'profile'       => $profil,
    ];
}
