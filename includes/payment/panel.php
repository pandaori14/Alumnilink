<?php
/**
 * Logika panel gateway pembayaran (pages/admin_payment_gateway.php).
 *
 * Dipisah dari halaman dan handler supaya aturan yang menentukan "boleh
 * dipindah atau tidak" dapat diuji dari CLI tanpa HTTP, dan supaya halaman
 * dan handler tidak menilai syarat yang sama dengan dua cara berbeda.
 */

require_once __DIR__ . '/service.php';

/** Kolom kredensial per gateway: kunci setting => [label, rahasia?] */
function payment_panel_credential_fields($gateway)
{
    $daftar = [
        'midtrans' => [
            'midtrans_server_key' => ['Server Key', true],
            'midtrans_client_key' => ['Client Key', false],
        ],
        'flip' => [
            'flip_secret_key'       => ['Secret Key', true],
            'flip_validation_token' => ['Validation Token', true],
        ],
    ];
    return $daftar[$gateway] ?? [];
}

/** Kunci setting mode produksi per gateway. */
function payment_panel_mode_key($gateway)
{
    return $gateway . '_is_production';
}

/**
 * Hasil tes koneksi terakhir.
 *
 * @return array|null at (Y-m-d H:i:s), ok, message, production (bool)
 */
function payment_last_test($gateway)
{
    $d = json_decode((string)setting('payment_last_test_' . $gateway, ''), true);
    return is_array($d) && isset($d['at'], $d['ok']) ? $d : null;
}

function payment_record_test($gateway, array $hasil)
{
    setting_save('payment_last_test_' . $gateway, json_encode([
        'at'         => date('Y-m-d H:i:s'),
        'ok'         => (bool)$hasil['ok'],
        'message'    => substr((string)($hasil['message'] ?? ''), 0, 300),
        'production' => payment_gateway($gateway)->isProduction(),
    ], JSON_UNESCAPED_UNICODE));
}

/** Tes koneksi dianggap hilang begitu kredensial atau mode diubah. */
function payment_forget_test($gateway)
{
    setting_save('payment_last_test_' . $gateway, '');
}

/**
 * Alasan sebuah gateway BELUM boleh dijadikan aktif. Kosong = boleh.
 *
 * Syaratnya sengaja ketat, karena begitu sakelar dipindah, SETIAP tagihan
 * baru — legalisir dan donasi — terbit lewat gateway itu:
 *   1. kredensial lengkap;
 *   2. tes koneksi berhasil dalam 24 jam terakhir, pada MODE yang sama
 *      dengan sekarang (kunci sandbox yang lulus tidak membuktikan apa pun
 *      tentang kunci produksi);
 *   3. profil biaya sah;
 *   4. profil biaya sudah ditandai dicocokkan dengan tarif resmi.
 */
function payment_switch_blockers($gateway)
{
    $gw = payment_gateway($gateway);
    if (!$gw) {
        return ['Gateway tidak dikenal.'];
    }
    $alasan = [];

    if (!$gw->isConfigured()) {
        $alasan[] = 'Kredensial ' . $gw->label() . ' belum lengkap.';
    }

    $tes = payment_last_test($gateway);
    if (!$tes || !$tes['ok']) {
        $alasan[] = 'Belum ada tes koneksi yang berhasil.';
    } elseif (strtotime($tes['at']) < time() - 86400) {
        $alasan[] = 'Tes koneksi terakhir lebih dari 24 jam lalu. Ulangi tes.';
    } elseif ((bool)$tes['production'] !== $gw->isProduction()) {
        $alasan[] = 'Tes koneksi terakhir dilakukan pada mode ' . ($tes['production'] ? 'produksi' : 'sandbox')
                  . ', sedangkan mode sekarang ' . ($gw->isProduction() ? 'produksi' : 'sandbox') . '. Ulangi tes.';
    }

    $profil = payment_fee_profile($gateway);
    if ($galat = payment_fee_profile_error($profil)) {
        $alasan[] = 'Profil biaya tidak sah: ' . $galat;
    } elseif (!$profil['reviewed']) {
        $alasan[] = 'Profil biaya belum ditandai sudah dicocokkan dengan tarif resmi ' . $gw->label() . '.';
    }

    return $alasan;
}

/**
 * Pindahkan gateway aktif, dengan penjaga yang sama dengan tampilan panel.
 *
 * @return array ok, error, dari, ke
 */
function payment_switch_gateway($gateway, $oleh)
{
    $dari = payment_active_gateway_code();
    if ($gateway === $dari) {
        return ['ok' => false, 'error' => payment_gateway_label($gateway) . ' sudah menjadi gateway aktif.', 'dari' => $dari, 'ke' => $gateway];
    }
    if ($alasan = payment_switch_blockers($gateway)) {
        return ['ok' => false, 'error' => implode(' ', $alasan), 'dari' => $dari, 'ke' => $gateway];
    }
    setting_save('payment_gateway_active', $gateway);
    if (function_exists('log_activity')) {
        log_activity('PAYMENT_GATEWAY_SWITCH', sprintf('Gateway pembayaran dipindah %s -> %s oleh %s. Tagihan yang sudah terbit tetap diproses gateway asalnya.',
            payment_gateway_label($dari), payment_gateway_label($gateway), $oleh));
    }
    return ['ok' => true, 'error' => null, 'dari' => $dari, 'ke' => $gateway];
}

/** "Terisi · ••••A1B2" tanpa pernah mencetak rahasianya. */
function payment_secret_hint($nilai)
{
    $nilai = (string)$nilai;
    if ($nilai === '') {
        return 'Belum diisi';
    }
    return 'Terisi · ••••' . (strlen($nilai) >= 12 ? substr($nilai, -4) : '');
}

/** URL yang didaftarkan di dashboard gateway. */
function payment_callback_url($gateway)
{
    $berkas = ['midtrans' => 'midtrans_webhook.php', 'flip' => 'flip_callback.php'][$gateway] ?? '';
    return $berkas ? rtrim(BASE_URL, '/') . '/handlers/' . $berkas : '';
}

/** Contoh perhitungan untuk profil biaya sebuah gateway. */
function payment_panel_examples($gateway)
{
    $contoh = [
        'Legalisir 1 dokumen, ambil sendiri' => ['legalisir', ['doc_count' => 1, 'delivery_method' => 'ambil_sendiri']],
        'Legalisir 2 dokumen, ambil sendiri' => ['legalisir', ['doc_count' => 2, 'delivery_method' => 'ambil_sendiri']],
        'Donasi Rp 50.000'                   => ['donasi', ['amount' => 50000]],
    ];
    $hasil = [];
    foreach ($contoh as $label => [$purpose, $input]) {
        $hasil[$label] = payment_quote($purpose, $input, $gateway);
    }
    return $hasil;
}

/**
 * Terbitkan transaksi uji di mode SANDBOX.
 *
 * purpose 'uji' tidak menyentuh legalisir maupun donasi; lunasnya hanya
 * memberi tahu super admin. Tujuannya membuktikan jalur buat -> bayar ->
 * callback -> lunas sebelum gateway dipakai alumni.
 *
 * @return array ok, error, txn
 */
function payment_create_test($gateway, array $customer, $oleh)
{
    $gw = payment_gateway($gateway);
    if (!$gw) {
        return ['ok' => false, 'error' => 'Gateway tidak dikenal.', 'txn' => null];
    }
    if ($gw->isProduction()) {
        return ['ok' => false, 'error' => 'Transaksi uji hanya untuk mode sandbox. Di mode produksi uangnya sungguhan.', 'txn' => null];
    }
    if (!$gw->isConfigured()) {
        return ['ok' => false, 'error' => 'Kredensial ' . $gw->label() . ' belum lengkap.', 'txn' => null];
    }
    $quote = payment_quote('uji', ['amount' => 10000], $gateway);
    if (!$quote['ok']) {
        return ['ok' => false, 'error' => $quote['error'], 'txn' => null];
    }
    $ref = payment_unique_ref('UJI-' . strtoupper(uniqid()));
    return payment_create('uji', $ref, $ref, $quote, $customer, $oleh);
}
