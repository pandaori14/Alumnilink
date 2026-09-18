<?php
/**
 * Aksi panel gateway pembayaran. Khusus super admin (pengaturan.kelola).
 *
 * ── Mengapa bukan handlers/admin_settings_handler.php ───────────────────
 * Handler Pengaturan menyimpan SETIAP kunci yang di-POST. Formulir itu
 * selalu mengirim ulang tarif Midtrans, sehingga menyimpan pengaturan apa
 * pun — logo, SMTP, teks beranda — ikut menulis ulang tarif pembayaran.
 * Di sini setiap aksi punya daftar kunci yang tertutup dan tervalidasi,
 * dan kunci pembayaran ditolak oleh handler Pengaturan.
 *
 * Aksi (POST 'aksi'):
 *   kredensial  mode + kunci. Rahasia hanya-tulis: kosong = tidak diubah.
 *   biaya       profil biaya satu gateway
 *   umum        biaya tambahan per layanan dan masa berlaku tagihan
 *   tes         tes koneksi ke API gateway
 *   aktifkan    pindahkan gateway aktif (dengan penjaga)
 *   uji         transaksi uji Rp 10.000 (khusus sandbox)
 *   cek         ambil ulang status satu transaksi dari gateway
 */
require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/payment/panel.php';

// require_role, bukan require_capability: kapabilitas mengikuti sakelar
// rbac_enforce, dan selama mode audit pelanggarannya hanya DICATAT. Kunci
// gateway dan sakelar uang tidak boleh ikut terbuka dalam mode itu — sama
// dengan handlers/admin_settings_handler.php.
require_role(['super_admin']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ../index.php?page=admin_payment_gateway');
    exit();
}
validate_csrf();

$oleh = (string)($_SESSION['user_name'] ?? $_SESSION['user_id']);
$aksi = (string)($_POST['aksi'] ?? '');
$gateway = (string)($_POST['gateway'] ?? '');
$gw = in_array($gateway, payment_gateway_codes(), true) ? payment_gateway($gateway) : null;

/** Kembali ke panel membawa pesan. */
function panel_kembali($ok, $pesan, $jangkar = '')
{
    $_SESSION['panel_bayar_flash'] = ['ok' => (bool)$ok, 'pesan' => (string)$pesan];
    header('Location: ../index.php?page=admin_payment_gateway' . ($jangkar ? '#' . $jangkar : ''));
    exit();
}

/** Bilangan tak-negatif dari POST; null bila bukan angka. */
function panel_angka($kunci, $desimal)
{
    $v = str_replace(',', '.', trim((string)($_POST[$kunci] ?? '')));
    if ($v === '' || !is_numeric($v) || (float)$v < 0) {
        return null;
    }
    return $desimal ? round((float)$v, 2) : (int)round((float)$v);
}

if (in_array($aksi, ['kredensial', 'biaya', 'tes', 'aktifkan', 'uji'], true) && !$gw) {
    panel_kembali(false, 'Gateway tidak dikenal.');
}

switch ($aksi) {

    case 'kredensial':
        $berubah = [];
        $mode = ($_POST['mode'] ?? '0') === '1' ? '1' : '0';
        if ((string)setting(payment_panel_mode_key($gateway), '0') !== $mode) {
            setting_save(payment_panel_mode_key($gateway), $mode);
            $berubah[] = 'mode ' . ($mode === '1' ? 'PRODUKSI' : 'sandbox');
        }
        foreach (payment_panel_credential_fields($gateway) as $kunci => [$label, $rahasia]) {
            $baru = trim((string)($_POST[$kunci] ?? ''));
            if (!empty($_POST['hapus_' . $kunci])) {
                if ((string)setting($kunci, '') !== '') {
                    setting_save($kunci, '');
                    $berubah[] = "$label dikosongkan";
                }
                continue;
            }
            // Rahasia hanya-tulis: kolom kosong berarti "tidak diubah".
            if ($baru === '' && $rahasia) {
                continue;
            }
            if (strlen($baru) > 255 || preg_match('/\s/', $baru)) {
                panel_kembali(false, "$label tidak sah: tidak boleh mengandung spasi atau lebih dari 255 karakter.", $gateway);
            }
            if ($baru !== (string)setting($kunci, '')) {
                setting_save($kunci, $baru);
                $berubah[] = $label;
            }
        }
        if (!$berubah) {
            panel_kembali(true, 'Tidak ada perubahan kredensial ' . $gw->label() . '.', $gateway);
        }
        // Tes lama tidak lagi membuktikan apa pun tentang kunci yang baru.
        payment_forget_test($gateway);
        log_activity('PAYMENT_GATEWAY_CREDENTIALS', $gw->label() . ' diperbarui oleh ' . $oleh . ': ' . implode(', ', $berubah) . '.');
        $peringatan = $gateway === payment_active_gateway_code()
            ? ' PERHATIAN: ini gateway AKTIF — tagihan baru langsung memakai kredensial ini. Jalankan tes koneksi sekarang.'
            : ' Jalankan tes koneksi sebelum mengaktifkannya.';
        panel_kembali(true, $gw->label() . ' diperbarui (' . implode(', ', $berubah) . ').' . $peringatan, $gateway);

    case 'biaya':
        $profil = ['gateway' => $gateway];
        foreach (['percent' => true, 'vat_percent' => true, 'flat' => false, 'app' => false, 'min' => false] as $k => $desimal) {
            $v = panel_angka($k, $desimal);
            if ($v === null) {
                panel_kembali(false, "Profil biaya {$gw->label()}: kolom '$k' wajib berupa angka tidak negatif.", 'biaya-' . $gateway);
            }
            $profil[$k] = $v;
        }
        if ($galat = payment_fee_profile_error($profil)) {
            panel_kembali(false, "Profil biaya {$gw->label()} tidak disimpan: $galat", 'biaya-' . $gateway);
        }
        $ditinjau = !empty($_POST['reviewed']) ? '1' : '0';
        foreach (['percent', 'vat_percent', 'flat', 'app', 'min'] as $k) {
            setting_save("fee_{$gateway}_$k", (string)$profil[$k]);
        }
        setting_save("fee_{$gateway}_reviewed", $ditinjau);
        log_activity('PAYMENT_FEE_PROFILE', sprintf('Profil biaya %s oleh %s: %s%% + PPN %s%%, flat Rp %d, aplikasi Rp %d, minimum Rp %d, ditinjau=%s.',
            $gw->label(), $oleh, $profil['percent'], $profil['vat_percent'], $profil['flat'], $profil['app'], $profil['min'], $ditinjau));
        panel_kembali(true, 'Profil biaya ' . $gw->label() . ' disimpan.'
            . ($gateway === payment_active_gateway_code() ? ' Berlaku untuk tagihan BARU; tagihan yang sudah terbit tidak berubah.' : ''), 'biaya-' . $gateway);

    case 'umum':
        $leg = panel_angka('payment_custom_charge_legalisir', false);
        $don = panel_angka('payment_custom_charge_donasi', false);
        $exp = panel_angka('payment_expiry', false);
        if ($leg === null || $don === null || $exp === null) {
            panel_kembali(false, 'Semua kolom wajib berupa angka tidak negatif.', 'umum');
        }
        if ($leg > 1000000 || $don > 1000000) {
            panel_kembali(false, 'Biaya tambahan di atas Rp 1.000.000 — kemungkinan salah ketik.', 'umum');
        }
        if ($exp < 15 || $exp > 10080) {
            panel_kembali(false, 'Masa berlaku tagihan harus antara 15 menit dan 7 hari (10080 menit).', 'umum');
        }
        $wajib_lunas = !empty($_POST['legalisir_require_paid']) ? '1' : '0';
        $cadangan    = !empty($_POST['payment_fallback_enabled']) ? '1' : '0';
        setting_save('payment_custom_charge_legalisir', (string)$leg);
        setting_save('payment_custom_charge_donasi', (string)$don);
        setting_save('payment_expiry', (string)$exp);
        setting_save('legalisir_require_paid', $wajib_lunas);
        setting_save('payment_fallback_enabled', $cadangan);
        log_activity('PAYMENT_GENERAL_SETTINGS', "Pengaturan umum pembayaran oleh $oleh: biaya tambahan legalisir Rp $leg, donasi Rp $don, masa berlaku $exp menit, wajib lunas sebelum diproses = $wajib_lunas, gateway cadangan = $cadangan.");
        panel_kembali(true, 'Pengaturan umum pembayaran disimpan. Berlaku untuk tagihan baru.', 'umum');

    case 'tes':
        $hasil = $gw->testConnection();
        payment_record_test($gateway, $hasil);
        log_activity('PAYMENT_GATEWAY_TEST', $gw->label() . ' (' . ($gw->isProduction() ? 'produksi' : 'sandbox') . ') oleh ' . $oleh . ': '
            . ($hasil['ok'] ? 'berhasil' : 'GAGAL') . ' — ' . $hasil['message']);
        panel_kembali($hasil['ok'], $hasil['message'], $gateway);

    case 'aktifkan':
        $hasil = payment_switch_gateway($gateway, $oleh);
        if (!$hasil['ok']) {
            panel_kembali(false, 'Gateway tidak dipindah. ' . $hasil['error'], $gateway);
        }
        notify_roles(['super_admin'], 'Gateway Pembayaran Dipindah',
            'Tagihan baru kini terbit lewat ' . payment_gateway_label($hasil['ke']) . ' (sebelumnya '
            . payment_gateway_label($hasil['dari']) . '), dipindah oleh ' . $oleh . '. Tagihan lama tetap diproses gateway asalnya.',
            'warning', 'index.php?page=admin_payment_gateway');
        panel_kembali(true, 'Gateway aktif kini ' . payment_gateway_label($hasil['ke']) . '. Tagihan yang sudah terbit tetap diproses '
            . payment_gateway_label($hasil['dari']) . '.');

    case 'uji':
        $q = $pdo->prepare("SELECT name, email, phone, address FROM users WHERE id = ?");
        $q->execute([$_SESSION['user_id']]);
        $u = $q->fetch(PDO::FETCH_OBJ);
        $hasil = payment_create_test($gateway, [
            'name'    => $u->name ?? 'Super Admin',
            'email'   => $u->email ?? '',
            'phone'   => $u->phone ?? '',
            'address' => $u->address ?? '',
        ], $_SESSION['user_id']);
        if (!$hasil['txn'] && !$hasil['ok']) {
            panel_kembali(false, 'Transaksi uji tidak dibuat: ' . $hasil['error'], 'uji');
        }
        log_activity('PAYMENT_TEST_TRANSACTION', $gw->label() . ' oleh ' . $oleh . ': ' . $hasil['txn']->merchant_ref
            . ($hasil['ok'] ? ' diterbitkan.' : ' GAGAL: ' . $hasil['error']));
        if (!$hasil['ok']) {
            panel_kembali(false, 'Gateway menolak transaksi uji: ' . $hasil['error'], 'uji');
        }
        panel_kembali(true, 'Transaksi uji ' . $hasil['txn']->merchant_ref . ' diterbitkan. Tekan "Bayar" di daftar transaksi uji, selesaikan di simulator sandbox, lalu periksa monitor.', 'uji');

    case 'cek':
        $txn = payment_txn_get((int)($_POST['txn_id'] ?? 0));
        if (!$txn) {
            panel_kembali(false, 'Transaksi tidak ditemukan.', 'monitor');
        }
        $lama = $txn->status;
        $hasil = payment_recheck($txn, 'manual:' . $oleh, true);
        $baru = payment_txn_get($txn->id)->status;
        log_activity('PAYMENT_MANUAL_RECHECK', "Cek status {$txn->merchant_ref} oleh $oleh: $lama -> $baru" . (!empty($hasil['error']) ? ' (' . $hasil['error'] . ')' : ''));
        if (!empty($hasil['error'])) {
            panel_kembali(false, "{$txn->merchant_ref}: status tidak dapat diambil dari " . payment_gateway_label($txn->gateway) . ' — ' . $hasil['error'], 'monitor');
        }
        panel_kembali(true, "{$txn->merchant_ref}: " . ($lama === $baru ? "tetap $baru." : "$lama → $baru."), 'monitor');
}

panel_kembali(false, 'Aksi tidak dikenal.');
