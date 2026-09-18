<?php
/**
 * Uji lapisan pembayaran: rumus biaya, mesin status, adaptor Midtrans dan
 * Flip, callback, backfill, dan endpoint pratinjau.
 *
 * TIDAK ADA panggilan jaringan ke gateway. Seluruh HTTP keluar diganti lewat
 * $GLOBALS['payment_http_override'], dan setiap panggilan dicatat supaya
 * uji dapat memastikan sebuah jalur memang TIDAK memanggil API.
 *
 * Data uji memakai awalan UJIBAYAR dan dibersihkan di akhir, apa pun yang
 * terjadi di tengah. Setting yang diubah dikembalikan ke nilai semula.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once AKAR . '/includes/payment/callback.php';
require_once AKAR . '/includes/payment/panel.php';

$BASE = uji_base_url();

// ── Penjaga: setting dan data dikembalikan apa pun yang terjadi ──────
$KUNCI = ['payment_gateway_active', 'midtrans_server_key', 'midtrans_client_key', 'midtrans_is_production',
    'flip_secret_key', 'flip_validation_token', 'flip_is_production',
    'fee_midtrans_percent', 'fee_midtrans_vat_percent', 'fee_midtrans_flat', 'fee_midtrans_app', 'fee_midtrans_min',
    'fee_flip_percent', 'fee_flip_vat_percent', 'fee_flip_flat', 'fee_flip_app', 'fee_flip_min',
    'payment_custom_charge_legalisir', 'payment_custom_charge_donasi', 'price_per_doc', 'shipping_zones',
    'payment_expiry', 'smtp_force_real',
    'fee_midtrans_reviewed', 'fee_flip_reviewed', 'payment_last_test_midtrans', 'payment_last_test_flip'];
$SEMULA = [];
foreach ($KUNCI as $k) {
    $q = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $q->execute([$k]);
    $v = $q->fetchColumn();
    $SEMULA[$k] = $v === false ? null : $v;
}

// Callback yang gagal autentikasi tercatat TANPA merchant_ref, jadi tidak
// tertangkap pola di bawah. Semua baris jurnal sejak uji dimulai dihapus.
$JURNAL_AWAL = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM payment_callbacks")->fetchColumn();

function bersihkan()
{
    global $pdo, $JURNAL_AWAL;
    $pdo->prepare("DELETE FROM payment_callbacks WHERE id > ?")->execute([$JURNAL_AWAL]);
    $pdo->exec("DELETE FROM payment_callbacks WHERE merchant_ref LIKE '%UJIBAYAR%' OR provider_ref LIKE '99001%'");
    $pdo->exec("DELETE FROM payment_transactions WHERE purpose = 'donasi' AND subject_id IN
                (SELECT CAST(id AS CHAR) FROM donations WHERE donor_name = 'UJIBAYAR')");
    $pdo->exec("DELETE FROM payment_transactions WHERE subject_id LIKE '%UJIBAYAR%' OR merchant_ref LIKE '%UJIBAYAR%'");
    $pdo->exec("DELETE FROM donations WHERE donor_name = 'UJIBAYAR'");
    $pdo->exec("DELETE FROM legalisir_requests WHERE id LIKE '%UJIBAYAR%'");
    $pdo->exec("DELETE FROM notifications WHERE message LIKE '%UJIBAYAR%'");
    $pdo->exec("DELETE FROM notifications WHERE title = 'Transaksi Uji Lunas' AND message LIKE '%UJI-%' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $pdo->exec("DELETE FROM payment_transactions WHERE purpose = 'uji' AND created_by = 'UJIBAYAR'");
    $pdo->exec("DELETE FROM activity_logs WHERE description LIKE '%oleh UJIBAYAR%'");
}

register_shutdown_function(function () use ($SEMULA) {
    global $pdo;
    unset($GLOBALS['payment_http_override']);
    foreach ($SEMULA as $k => $v) {
        if ($v === null) {
            $pdo->prepare("DELETE FROM settings WHERE setting_key = ?")->execute([$k]);
        } else {
            setting_save($k, $v);
        }
    }
    bersihkan();
});

bersihkan();

// ── Setting uji: nilai produksi yang diverifikasi 14 Sep 2026 ────────
foreach ([
    'payment_gateway_active' => 'midtrans',
    'midtrans_server_key' => 'SB-Mid-server-UJIBAYAR', 'midtrans_client_key' => 'SB-Mid-client-UJIBAYAR',
    'midtrans_is_production' => '0',
    'flip_secret_key' => 'rahasia-flip-uji', 'flip_validation_token' => 'token-flip-uji', 'flip_is_production' => '0',
    'fee_midtrans_percent' => '5.00', 'fee_midtrans_vat_percent' => '11.00',
    'fee_midtrans_flat' => '5550', 'fee_midtrans_app' => '2500', 'fee_midtrans_min' => '0',
    'fee_flip_percent' => '0', 'fee_flip_vat_percent' => '0', 'fee_flip_flat' => '4000', 'fee_flip_app' => '0', 'fee_flip_min' => '0',
    'payment_custom_charge_legalisir' => '6500', 'payment_custom_charge_donasi' => '0',
    'price_per_doc' => '50000', 'payment_expiry' => '1440', 'smtp_force_real' => '0',
    'shipping_zones' => json_encode([
        ['label' => 'Solo Raya', 'cost' => 15000, 'provinces' => ['Jawa Tengah', 'DKI Jakarta']],
        ['label' => 'Luar', 'cost' => 45000, 'provinces' => [], 'is_default' => true],
    ]),
] as $k => $v) {
    setting_save($k, $v);
}

// ── Transport palsu ──────────────────────────────────────────────────
$PANGGILAN = [];
function palsu(array $aturan)
{
    $GLOBALS['payment_http_override'] = function ($m, $u, $h, $b) use ($aturan) {
        $GLOBALS['PANGGILAN'][] = [$m, $u, $b];
        foreach ($aturan as [$metode, $pola, $balas]) {
            if (($metode === '*' || $metode === $m) && preg_match($pola, $u)) {
                return is_callable($balas) ? $balas($m, $u, $h, $b) : $balas;
            }
        }
        return ['status' => 599, 'body' => '', 'error' => "tidak ada aturan palsu: $m $u"];
    };
}
function mt_status($tx, $gross, array $x = [])
{
    return ['status' => 200, 'body' => json_encode(array_merge([
        'status_code' => $tx === 'pending' ? '201' : '200', 'transaction_status' => $tx,
        'gross_amount' => number_format($gross, 2, '.', ''), 'payment_type' => 'bank_transfer',
        'transaction_id' => 'trx-uji', 'transaction_time' => '2026-09-17 10:00:00',
    ], $x))];
}
function mt_token()
{
    return ['status' => 201, 'body' => json_encode(['token' => 'tok-' . bin2hex(random_bytes(4)), 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/x'])];
}
function mt_callback($order, $status, $gross)
{
    $code = $status === 'pending' ? '201' : '200';
    $g = number_format($gross, 2, '.', '');
    return json_encode(['order_id' => $order, 'status_code' => $code, 'gross_amount' => $g,
        'transaction_status' => $status, 'transaction_id' => 'trx-cb',
        'signature_key' => hash('sha512', $order . $code . $g . 'SB-Mid-server-UJIBAYAR')]);
}

$alum = $pdo->query("SELECT id, email FROM users WHERE role = 'alumni' LIMIT 1")->fetch();
function buat_legalisir($id, $amount, array $x = [])
{
    global $pdo, $alum;
    $pdo->prepare("INSERT INTO legalisir_requests
            (id, user_id, documents, delivery_method, amount, status, payment_status, payment_method,
             midtrans_order_id, midtrans_snap_token, created_at)
         VALUES (?, ?, ?, 'ambil_sendiri', ?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$id, $alum->id, json_encode([['type' => 'ijazah', 'file' => 'x']]), $amount,
            $x['status'] ?? 'pending', $x['payment_status'] ?? 'pending', $x['payment_method'] ?? 'midtrans',
            $x['order'] ?? null, $x['token'] ?? null]);
}
function notif($id)
{
    global $pdo;
    $q = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE message LIKE ?");
    $q->execute(['%' . $id . '%']);
    return (int)$q->fetchColumn();
}
function kolom_lama($id)
{
    global $pdo;
    $q = $pdo->prepare("SELECT status, payment_status, payment_method, midtrans_order_id, midtrans_snap_token FROM legalisir_requests WHERE id = ?");
    $q->execute([$id]);
    return $q->fetch();
}
$pelanggan = ['name' => 'Alumni Uji', 'email' => 'uji@example.com', 'phone' => '', 'address' => ''];

// ═════════════════════════════════════════════════════════════════════
echo "=== A. Rumus biaya — angka di rencana, dihitung ulang JS & PHP ===\n";
$kasus = [
    ['1 dokumen ambil sendiri', 'legalisir', ['doc_count' => 1, 'delivery_method' => 'ambil_sendiri'], 68344],
    ['2 dokumen ambil sendiri', 'legalisir', ['doc_count' => 2, 'delivery_method' => 'ambil_sendiri'], 121282],
    ['1 dokumen kurir Solo Raya', 'legalisir', ['doc_count' => 1, 'delivery_method' => 'kurir', 'province' => 'Jawa Tengah'], 84225],
    ['donasi Rp 50.000', 'donasi', ['amount' => 50000], 61462],
];
foreach ($kasus as [$label, $p, $in, $harap]) {
    $q = payment_quote($p, $in);
    cek($q['ok'] && $q['total'] === $harap, $label, 'Rp ' . number_format($q['total'] ?? 0, 0, ',', '.'));
}
$q = payment_quote('legalisir', ['doc_count' => 1, 'delivery_method' => 'kurir', 'province' => 'Dki Jakarta']);
cek($q['shipping'] === 15000, "'Dki Jakarta' cocok zona 'DKI Jakarta'", 'ongkir ' . $q['shipping']);
$q = payment_quote('legalisir', ['doc_count' => 1, 'delivery_method' => 'kurir', 'province' => 'Papua']);
cek($q['shipping'] === 45000, 'provinsi tanpa zona -> zona bawaan', 'ongkir ' . $q['shipping']);
$jumlah_item = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $q['items']));
cek($jumlah_item === $q['total'], 'item_details berjumlah sama dengan total (syarat Midtrans)', "$jumlah_item = {$q['total']}");
cek(!payment_quote('legalisir', ['doc_count' => 0])['ok'], 'nol dokumen ditolak');
cek(!payment_quote('donasi', ['amount' => 9000])['ok'], 'donasi < Rp 10.000 ditolak');
setting_save('fee_midtrans_percent', '-3');
cek(!payment_quote('donasi', ['amount' => 50000])['ok'], 'profil biaya negatif MENOLAK transaksi');
setting_save('fee_midtrans_percent', '5.00');
cek(payment_fee_compute(10000, 0, ['percent' => 0, 'vat_percent' => 0, 'flat' => 0, 'app' => 0, 'min' => 7000]) === 7000,
    'biaya minimum diterapkan');

// ═════════════════════════════════════════════════════════════════════
echo "\n=== B. Aturan transisi (murni) ===\n";
foreach ([
    ['pending', 'paid', 'applied'], ['pending', 'expired', 'applied'], ['pending', 'pending', 'no_change'],
    ['paid', 'pending', 'ignored_regression'], ['paid', 'expired', 'ignored_regression'],
    ['paid', 'failed', 'ignored_regression'], ['paid', 'refunded', 'applied'],
    ['expired', 'paid', 'applied'], ['cancelled', 'paid', 'applied'], ['failed', 'pending', 'ignored_regression'],
    ['refunded', 'paid', 'ignored_regression'], ['create_failed', 'expired', 'applied'],
] as [$lama, $baru, $harap]) {
    cek(payment_transition_outcome($lama, $baru) === $harap, "$lama -> $baru", $harap);
}

// ═════════════════════════════════════════════════════════════════════
echo "\n=== C. Mesin status di basis data ===\n";
$A = 'LEG-UJIBAYAR-A';
buat_legalisir($A, 68344);
palsu([['POST', '#/snap/v1/transactions#', fn() => mt_token()]]);
$q = payment_quote('legalisir', ['doc_count' => 1, 'delivery_method' => 'ambil_sendiri']);
$r = payment_create('legalisir', $A, $A, $q, $pelanggan);
cek($r['ok'] && $r['txn']->status === 'pending' && $r['txn']->snap_token, 'tagihan dibuat, token tersimpan');
cek((float)$r['txn']->amount_expected === 68344.0, 'amount_expected = total pratinjau', (string)$r['txn']->amount_expected);
$rinci = json_decode($r['txn']->fee_breakdown, true);
cek(($rinci['fee'] ?? null) === 11844 && ($rinci['custom'] ?? null) === 6500, 'rincian biaya tersimpan per transaksi');
$lama = kolom_lama($A);
cek($lama->midtrans_order_id === $A && $lama->midtrans_snap_token === $r['txn']->snap_token, 'kolom lama midtrans_* disinkronkan');
$tA = $r['txn'];

palsu([['GET', '#/v2/.+/status#', fn() => mt_status('settlement', 68344)]]);
$h = payment_recheck($tA, 'uji', true);
$lama = kolom_lama($A);
cek($h['outcome'] === 'applied' && $h['status'] === 'paid', 'settlement -> paid', $h['outcome']);
cek($lama->payment_status === 'settlement' && $lama->payment_method === 'midtrans', "kolom lama: settlement + 'midtrans'", "{$lama->payment_status}/{$lama->payment_method}");
cek($lama->status === 'processing', 'permohonan pending maju ke processing', $lama->status);
cek(payment_txn_get($tA->id)->channel === 'bank_transfer', "kanal disimpan terpisah, bukan menimpa payment_method");
$n1 = notif($A);
cek($n1 >= 1, 'notifikasi lunas terkirim', "$n1");

palsu([['GET', '#/v2/.+/status#', fn() => mt_status('expire', 68344)]]);
$h = payment_recheck(payment_txn_get($tA->id), 'uji', true);
cek($h['outcome'] === 'ignored_regression', "'expire' SETELAH lunas diabaikan", $h['outcome']);
cek(kolom_lama($A)->payment_status === 'settlement', 'permohonan tetap lunas');

palsu([['GET', '#/v2/.+/status#', fn() => mt_status('settlement', 68344)]]);
$h = payment_recheck(payment_txn_get($tA->id), 'uji', true);
cek($h['outcome'] === 'no_change' && notif($A) === $n1, 'settlement kedua: tanpa efek samping ulang', $h['outcome'] . ', notif ' . notif($A));

// Nominal tidak cocok -> tidak berubah, dan retry TETAP diproses
$B = 'LEG-UJIBAYAR-B';
buat_legalisir($B, 68344);
palsu([['POST', '#/snap/v1/transactions#', fn() => mt_token()]]);
$tB = payment_create('legalisir', $B, $B, $q, $pelanggan)['txn'];
palsu([['GET', '#/v2/.+/status#', fn() => mt_status('settlement', 1000)]]);
$h = payment_recheck($tB, 'uji', true);
cek($h['outcome'] === 'amount_mismatch' && payment_txn_get($tB->id)->status === 'pending', 'nominal tidak cocok -> tetap pending', $h['outcome']);
cek(payment_txn_get($tB->id)->flag === 'amount_mismatch', 'nominal tidak cocok -> ditandai di ledger', (string)payment_txn_get($tB->id)->flag);
$selisih = function () use ($B) {
    global $pdo;
    $q = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE title = 'Nominal pembayaran TIDAK COCOK' AND message LIKE ?");
    $q->execute(['%' . $B . '%']);
    return (int)$q->fetchColumn();
};
$n_selisih = $selisih();
$h = payment_recheck(payment_txn_get($tB->id), 'uji', true);
cek($n_selisih > 0 && $selisih() === $n_selisih, 'callback berulang: super admin diberi tahu sekali saja', "$n_selisih -> " . $selisih());
palsu([['GET', '#/v2/.+/status#', fn() => mt_status('settlement', 68344)]]);
$h = payment_recheck(payment_txn_get($tB->id), 'uji', true);
cek($h['outcome'] === 'applied', 'retry dengan nominal benar TETAP diproses', $h['outcome']);
cek(payment_txn_get($tB->id)->flag === null, 'tanda selisih gugur setelah lunas dengan nominal benar', (string)payment_txn_get($tB->id)->flag);

// Uang yang masuk selalu menang
$C = 'LEG-UJIBAYAR-C';
buat_legalisir($C, 68344);
palsu([['POST', '#/snap/v1/transactions#', fn() => mt_token()]]);
$tC = payment_create('legalisir', $C, $C, $q, $pelanggan)['txn'];
payment_apply_status($tC->id, 'expired', [], 'uji');
cek(kolom_lama($C)->payment_status === 'failed', 'kedaluwarsa -> kolom lama failed');
palsu([['GET', '#/v2/.+/status#', fn() => mt_status('settlement', 68344)]]);
$h = payment_recheck(payment_txn_get($tC->id), 'uji', true);
cek($h['outcome'] === 'applied' && kolom_lama($C)->payment_status === 'settlement', 'expired -> paid (uang masuk menang)', $h['outcome']);

// Gagal membuat tagihan
$D = 'LEG-UJIBAYAR-D';
buat_legalisir($D, 68344);
palsu([['POST', '#/snap/v1/transactions#', ['status' => 500, 'body' => '{"error_messages":["down"]}']]]);
$r = payment_create('legalisir', $D, $D, $q, $pelanggan);
cek(!$r['ok'] && $r['txn']->status === 'create_failed', 'gateway gagal -> create_failed, permohonan tetap tercatat', $r['txn']->status);
cek(kolom_lama($D)->payment_status === 'failed', 'kolom lama menunjukkan belum dapat dibayar');

// Regenerasi
palsu([['POST', '#/snap/v1/transactions#', fn() => mt_token()], ['POST', '#/expire#', ['status' => 200, 'body' => '{"status_code":"407"}']]]);
$r = payment_regenerate('legalisir', $A, $pelanggan);
cek(!$r['ok'] && strpos($r['error'], 'sudah lunas') !== false, 'regenerasi ditolak untuk yang sudah lunas', $r['error']);
$r = payment_regenerate('legalisir', $D, $pelanggan);
cek($r['ok'] && (float)$r['txn']->amount_expected === 68344.0 && $r['txn']->merchant_ref !== $D, 'regenerasi: ref baru, nominal SAMA', $r['txn']->merchant_ref ?? '-');
cek(payment_txn_by_merchant_ref($D)->status === 'cancelled', 'tagihan lama dibatalkan');
cek(strlen($r['txn']->merchant_ref) <= 50, 'merchant_ref <= 50 karakter (batas order_id Midtrans)', (string)strlen($r['txn']->merchant_ref));

// Tunai
$E = 'LEG-UJIBAYAR-E';
buat_legalisir($E, 68344);
palsu([['POST', '#/snap/v1/transactions#', fn() => mt_token()], ['POST', '#/expire#', ['status' => 200, 'body' => '{"status_code":"407"}']]]);
$tE = payment_create('legalisir', $E, $E, $q, $pelanggan)['txn'];
$h = payment_verify_cash($A, 'uji');
cek(!$h['ok'] && strpos($h['error'], 'sudah lunas') !== false, 'verifikasi tunai ditolak bila sudah lunas via gateway', $h['error'] ?? '');
cek(kolom_lama($A)->payment_method === 'midtrans', "metode tidak ditimpa menjadi 'cash'");
$h = payment_verify_cash($E, 'uji');
cek($h['ok'] && kolom_lama($E)->payment_method === 'cash', 'verifikasi tunai atas yang belum lunas berhasil');
cek(payment_txn_get($tE->id)->status === 'cancelled', 'tagihan gateway yang terbuka ditutup');

// Pembayaran ganda: tagihan gateway lama ternyata juga dibayar
palsu([['GET', '#/v2/.+/status#', fn() => mt_status('settlement', 68344)]]);
$h = payment_recheck(payment_txn_get($tE->id), 'uji', true);
$tE2 = payment_txn_get($tE->id);
cek($h['outcome'] === 'applied' && $tE2->flag === 'double_payment', 'bayar ganda terdeteksi dan ditandai', (string)$tE2->flag);

// ═════════════════════════════════════════════════════════════════════
echo "\n=== D. Adaptor ===\n";
foreach ([
    ['settlement', null, 'paid'], ['capture', 'accept', 'paid'], ['capture', 'challenge', 'pending'],
    ['capture', 'deny', 'failed'], ['pending', null, 'pending'], ['expire', null, 'expired'],
    ['cancel', null, 'failed'], ['failure', null, 'failed'], ['refund', null, 'refunded'], ['aneh', null, 'unknown'],
] as [$ts, $fs, $harap]) {
    cek(MidtransGateway::mapStatus($ts, $fs) === $harap, "Midtrans $ts" . ($fs ? "+$fs" : ''), $harap);
}
$mt = payment_gateway('midtrans');
palsu([['GET', '#/v2/.+/status#', ['status' => 404, 'body' => '{"status_code":"404","status_message":"Transaction doesn\'t exist."}']]]);
$s = $mt->fetchStatus('LEG-X');
cek($s['ok'] && $s['status'] === 'pending' && !empty($s['not_found']), 'Midtrans 404 = belum dipakai, bukan galat');
palsu([['GET', '#/v2/.+/status#', ['status' => 401, 'body' => '{"status_code":"401"}']]]);
cek(!$mt->fetchStatus('LEG-X')['ok'], 'Midtrans 401 = galat (bukan pending)');
$PANGGILAN = [];
palsu([['GET', '#.*#', ['status' => 200, 'body' => '{}']]]);
$mt->fetchStatus('LEG-X/../../v1/charge');
cek(strpos($PANGGILAN[0][1] ?? '', '/v2/LEG-X%2F..%2F..%2Fv1%2Fcharge/status') !== false, 'order_id di-rawurlencode di URL status');

$fl = payment_gateway('flip');
cek(FlipGateway::summarizePayments([['status' => 'PENDING'], ['status' => 'SUCCESSFUL', 'amount' => 70000, 'sender_bank' => 'bni']])['status'] === 'paid', 'Flip: satu SUCCESSFUL = lunas');
$s = FlipGateway::summarizePayments([]);
cek($s['status'] === 'pending' && $s['not_found'] === true, 'Flip: daftar kosong = pending, belum ada upaya bayar');
cek(FlipGateway::summarizePayments([['status' => 'CANCELLED']])['status'] === 'expired', 'Flip: CANCELLED = kedaluwarsa');
$PANGGILAN = [];
$s = $fl->fetchStatus('123/../../bill');
cek(!$s['ok'] && count($PANGGILAN) === 0, 'Flip: link_id bukan angka ditolak TANPA memanggil API (anti-SSRF)');

$tangkap = null;
palsu([['POST', '#/v2/pwf/bill$#', function ($m, $u, $h, $b) use (&$tangkap) {
    $tangkap = $b;
    return ['status' => 200, 'body' => json_encode(['link_id' => 99001, 'link_url' => 'flip.id/$uji/#/x', 'status' => 'ACTIVE'])];
}]]);
$c = $fl->createCharge(['merchant_ref' => 'LEG-UJIBAYAR-F', 'amount' => 70000, 'customer_name' => 'A', 'customer_email' => 'a@b.co', 'expiry_minutes' => 60, 'return_url' => 'https://x/y']);
parse_str((string)$tangkap, $form);
cek($c['ok'] && $c['provider_ref'] === '99001' && strpos($c['pay_url'], 'https://flip.id/') === 0, 'Flip: tagihan dibuat, link_url diberi https');
cek(($form['step'] ?? '') === '1' && !isset($form['sender_email']), 'Flip: data tidak lengkap -> step 1 (alumni isi di halaman Flip)');
$fl->createCharge(['merchant_ref' => 'LEG-UJIBAYAR-F', 'amount' => 70000, 'customer_name' => 'A', 'customer_email' => 'a@b.co', 'customer_phone' => '081234567890', 'customer_address' => 'Jl. X', 'return_url' => 'https://x/y']);
parse_str((string)$tangkap, $form);
cek(($form['step'] ?? '') === '2' && ($form['sender_phone_number'] ?? '') === '081234567890', 'Flip: data lengkap -> step 2');
cek(!$fl->createCharge(['merchant_ref' => 'X', 'amount' => 9000])['ok'], 'Flip: di bawah Rp 10.000 ditolak');

// ═════════════════════════════════════════════════════════════════════
echo "\n=== E. Callback ===\n";
$h = payment_process_callback('midtrans', '{"order_id":"x"}', []);
cek($h['http'] === 400, 'Midtrans: body tidak lengkap -> 400', (string)$h['http']);
$palsu_ttd = json_decode(mt_callback('LEG-UJIBAYAR-B', 'settlement', 68344), true);
$palsu_ttd['signature_key'] = str_repeat('0', 128);
$h = payment_process_callback('midtrans', json_encode($palsu_ttd), []);
cek($h['http'] === 403, 'Midtrans: tanda tangan salah -> 403', (string)$h['http']);

// Tanda tangan sah, body bilang 'settlement', API bilang 'pending' -> TIDAK lunas
$G = 'LEG-UJIBAYAR-G';
buat_legalisir($G, 68344);
palsu([['POST', '#/snap/v1/transactions#', fn() => mt_token()]]);
$tG = payment_create('legalisir', $G, $G, $q, $pelanggan)['txn'];
palsu([['GET', '#/v2/.+/status#', fn() => mt_status('pending', 68344)]]);
$h = payment_process_callback('midtrans', mt_callback($G, 'settlement', 68344), []);
cek(payment_txn_get($tG->id)->status === 'pending', "body 'settlement' TIDAK dipercaya: API bilang pending", $h['outcome']);

palsu([['GET', '#/v2/.+/status#', ['status' => 503, 'body' => '']]]);
$h = payment_process_callback('midtrans', mt_callback($G, 'settlement', 68344), []);
cek($h['http'] === 500, 'API status tidak terjangkau -> 500 supaya gateway mengulang', (string)$h['http']);
palsu([['GET', '#/v2/.+/status#', fn() => mt_status('settlement', 68344)]]);
$h = payment_process_callback('midtrans', mt_callback($G, 'settlement', 68344), []);
cek($h['http'] === 200 && payment_txn_get($tG->id)->status === 'paid', 'ulangan berikutnya diproses dan lunas', $h['outcome']);
$jurnal = $pdo->query("SELECT outcome FROM payment_callbacks WHERE merchant_ref = '$G' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
cek($jurnal === ['no_change', 'error', 'applied'], 'jurnal mencatat ketiganya', implode(',', $jurnal));

$h = payment_process_callback('midtrans', mt_callback('LEG-UJIBAYAR-TIDAKADA', 'settlement', 1000), []);
cek($h['http'] === 200 && $h['outcome'] === 'not_found', 'order tak dikenal -> 200 not_found (tidak diulang sia-sia)', $h['outcome']);

// Order dari kode LAMA tanpa transaksi di ledger -> dibuat secara malas
$L = 'LEG-UJIBAYAR-L';
buat_legalisir($L, 68344, ['order' => "$L-1789000000", 'token' => 'tok-lama']);
palsu([['GET', '#/v2/.+/status#', fn() => mt_status('settlement', 68344)]]);
$h = payment_process_callback('midtrans', mt_callback("$L-1789000000", 'settlement', 68344), []);
cek($h['outcome'] === 'applied' && kolom_lama($L)->payment_status === 'settlement', 'order lama tanpa ledger: dibuat malas lalu diproses', $h['outcome']);

// Flip
$h = payment_process_callback('flip', '', ['token' => 'salah', 'data' => '{}']);
cek($h['http'] === 403, 'Flip: token salah -> 403', (string)$h['http']);
$h = payment_process_callback('flip', '', ['token' => 'token-flip-uji', 'data' => 'bukan json']);
cek($h['http'] === 400, 'Flip: data bukan JSON -> 400', (string)$h['http']);
$h = payment_process_callback('flip', '', ['token' => 'token-flip-uji', 'data' => '{"bill_link_id":"1 OR 1=1"}']);
cek($h['http'] === 400, 'Flip: bill_link_id bukan angka -> 400', (string)$h['http']);

$F = 'LEG-UJIBAYAR-F';
buat_legalisir($F, 70000);
setting_save('payment_gateway_active', 'flip');
palsu([['POST', '#/v2/pwf/bill$#', ['status' => 200, 'body' => json_encode(['link_id' => 990012, 'link_url' => 'flip.id/$uji'])]]]);
$qf = payment_quote('legalisir', ['doc_count' => 1, 'delivery_method' => 'ambil_sendiri']);
$tF = payment_create('legalisir', $F, $F, $qf, $pelanggan)['txn'];
cek($tF->gateway === 'flip' && $tF->provider_ref === '990012' && $tF->pay_url, 'gateway aktif Flip: tagihan baru lewat Flip');
palsu([['GET', '#/v2/pwf/990012/payment#', ['status' => 200, 'body' => json_encode(['data' => [['id' => 'PGPWF1', 'status' => 'SUCCESSFUL', 'amount' => $qf['total'], 'sender_bank' => 'qris']]])]]]);
$h = payment_process_callback('flip', '', ['token' => 'token-flip-uji', 'data' => json_encode(['id' => 'PGPWF1', 'bill_link_id' => 990012, 'bill_title' => "AlumniLink $F", 'status' => 'SUCCESSFUL', 'amount' => $qf['total']])]);
cek($h['outcome'] === 'applied' && kolom_lama($F)->payment_method === 'flip', "Flip lunas -> payment_method 'flip'", $h['outcome']);
setting_save('payment_gateway_active', 'midtrans');

// Transaksi Midtrans lama tetap terkonfirmasi setelah sakelar dipindah
cek(payment_txn_get($tG->id)->gateway === 'midtrans', 'tagihan Midtrans lama tetap milik Midtrans setelah pindah gateway');

// ═════════════════════════════════════════════════════════════════════
echo "\n=== F. Backfill ===\n";
$H = 'LEG-UJIBAYAR-H';
buat_legalisir($H, 55000, ['payment_status' => 'settlement', 'payment_method' => 'cash', 'order' => "$H-1789000000", 'token' => 'tok-tinggal']);
payment_backfill_subject($pdo, 'legalisir', $H);
$tx = $pdo->query("SELECT gateway, status FROM payment_transactions WHERE subject_id = '$H' ORDER BY gateway")->fetchAll();
cek(count($tx) === 2 && $tx[0]->gateway === 'cash' && $tx[0]->status === 'paid' && $tx[1]->status === 'cancelled',
    'tunai lunas + token lama -> cash/paid + midtrans/cancelled', implode(',', array_map(fn($t) => "$t->gateway/$t->status", $tx)));
$sebelum = (int)$pdo->query("SELECT COUNT(*) FROM payment_transactions")->fetchColumn();
payment_backfill_all($pdo);
payment_backfill_subject($pdo, 'legalisir', $H);
cek((int)$pdo->query("SELECT COUNT(*) FROM payment_transactions")->fetchColumn() === $sebelum, 'backfill dijalankan ulang tidak menggandakan');
palsu([['GET', '#/v2/.+/status#', fn() => mt_status('expire', 55000)]]);
$h = payment_process_callback('midtrans', mt_callback("$H-1789000000", 'expire', 55000), []);
cek(kolom_lama($H)->payment_status === 'settlement', "token lama 'expire' tidak memundurkan permohonan TUNAI", $h['outcome']);

// ═════════════════════════════════════════════════════════════════════
echo "\n=== G. Endpoint pratinjau (HTTP) ===\n";
unset($GLOBALS['payment_http_override']);
$csrf = bin2hex(random_bytes(16));
$sid = sesi_palsu($alum->id, 'alumni', $csrf);
register_shutdown_function(fn() => sesi_hapus($sid));
function kutip($sid, $csrf, array $post)
{
    global $BASE;
    $ch = curl_init("$BASE/api/payment_quote.php");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => http_build_query($post),
        CURLOPT_HTTPHEADER => array_filter(['Accept: application/json', $csrf ? "X-CSRF-Token: $csrf" : null]),
        CURLOPT_COOKIE => $sid ? "PHPSESSID=$sid" : '']);
    $b = curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$c, json_decode((string)$b, true)];
}
[$c, $j] = kutip($sid, $csrf, ['purpose' => 'legalisir', 'doc_count' => 1, 'delivery_method' => 'ambil_sendiri']);
cek($c === 200 && ($j['total'] ?? 0) === 68344, 'pratinjau = angka yang akan ditagih', "HTTP $c, Rp " . ($j['total'] ?? '-'));
[$c] = kutip(null, $csrf, ['purpose' => 'legalisir', 'doc_count' => 1]);
cek($c === 401, 'tanpa login -> 401', "HTTP $c");
[$c] = kutip($sid, null, ['purpose' => 'legalisir', 'doc_count' => 1]);
cek($c === 403, 'tanpa CSRF -> 403', "HTTP $c");

// ═════════════════════════════════════════════════════════════════════
echo "\n=== H. Panel gateway, sakelar, transaksi uji, cron ===\n";

cek(payment_secret_hint('SB-Mid-server-abcdEFGH1234') === 'Terisi · ••••1234', 'petunjuk rahasia: hanya 4 karakter terakhir');
cek(payment_secret_hint('pendek') === 'Terisi · ••••', 'petunjuk rahasia: kunci pendek tidak dibocorkan sebagian');
cek(payment_secret_hint('') === 'Belum diisi', 'petunjuk rahasia: kosong');

setting_save('payment_gateway_active', 'midtrans');
setting_save('fee_flip_reviewed', '0');
payment_forget_test('flip');
payment_forget_test('midtrans');
$alasan = implode(' | ', payment_switch_blockers('flip'));
cek(strpos($alasan, 'tes koneksi') !== false && strpos($alasan, 'belum ditandai') !== false,
    'Flip tanpa tes & tarif belum ditinjau: dua penghalang', $alasan);
$h = payment_switch_gateway('flip', 'UJIBAYAR');
cek(!$h['ok'] && payment_active_gateway_code() === 'midtrans', 'sakelar menolak, gateway aktif tidak berubah');

palsu([['GET', '#/v2/pwf/bill$#', ['status' => 200, 'body' => '[]']]]);
payment_record_test('flip', payment_gateway('flip')->testConnection());
cek(payment_last_test('flip')['ok'] === true, 'tes koneksi Flip tercatat berhasil');
cek(payment_switch_blockers('flip') === ['Profil biaya belum ditandai sudah dicocokkan dengan tarif resmi Flip.'],
    'tinggal satu penghalang: tarif belum ditinjau');

setting_save('flip_is_production', '1');
$alasan = implode(' | ', payment_switch_blockers('flip'));
cek(strpos($alasan, 'mode sandbox') !== false, 'tes di sandbox tidak berlaku untuk mode produksi', $alasan);
setting_save('flip_is_production', '0');

$tes = payment_last_test('flip');
$tes['at'] = date('Y-m-d H:i:s', time() - 25 * 3600);
setting_save('payment_last_test_flip', json_encode($tes));
cek(strpos(implode(' ', payment_switch_blockers('flip')), '24 jam') !== false, 'tes berumur > 24 jam tidak berlaku');
payment_record_test('flip', ['ok' => true, 'message' => 'uji']);

setting_save('fee_flip_reviewed', '1');
$log_awal = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'PAYMENT_GATEWAY_SWITCH'")->fetchColumn();
$h = payment_switch_gateway('flip', 'UJIBAYAR');
cek($h['ok'] && payment_active_gateway_code() === 'flip', 'semua syarat terpenuhi: gateway aktif pindah ke Flip');
cek((int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'PAYMENT_GATEWAY_SWITCH'")->fetchColumn() === $log_awal + 1,
    'perpindahan tercatat di Audit Trail');
cek(!payment_switch_gateway('flip', 'UJIBAYAR')['ok'], 'memindahkan ke gateway yang sudah aktif ditolak');

$h = payment_switch_gateway('midtrans', 'UJIBAYAR');
cek(!$h['ok'] && payment_active_gateway_code() === 'flip', 'kembali ke Midtrans juga wajib tes koneksi', (string)$h['error']);
palsu([['GET', '#/v2/.+/status#', ['status' => 404, 'body' => json_encode(['status_code' => '404'])]]]);
payment_record_test('midtrans', payment_gateway('midtrans')->testConnection());
cek(payment_switch_gateway('midtrans', 'UJIBAYAR')['ok'] && payment_active_gateway_code() === 'midtrans', 'setelah tes: kembali ke Midtrans');

// Transaksi uji
setting_save('flip_is_production', '1');
$GLOBALS['PANGGILAN'] = [];
$h = payment_create_test('flip', $pelanggan, 'UJIBAYAR');
cek(!$h['ok'] && !$GLOBALS['PANGGILAN'], 'transaksi uji di mode produksi ditolak tanpa memanggil API', (string)$h['error']);
setting_save('flip_is_production', '0');
palsu([['POST', '#/v2/pwf/bill$#', ['status' => 200, 'body' => json_encode(['link_id' => 990077, 'link_url' => 'flip.id/$ujipanel'])]]]);
$h = payment_create_test('flip', $pelanggan, 'UJIBAYAR');
$tU = $h['txn'];
cek($h['ok'] && $tU->purpose === 'uji' && strpos($tU->merchant_ref, 'UJI-') === 0 && $tU->status === 'pending',
    'transaksi uji sandbox terbit (purpose uji)', $tU->merchant_ref ?? '-');
cek((float)$tU->amount_expected === (float)payment_quote('uji', ['amount' => 10000], 'flip')['total'], 'nominal uji = Rp 10.000 + biaya Flip');
palsu([['GET', '#/v2/pwf/990077/payment#', ['status' => 200, 'body' => json_encode(['data' => [['id' => 'PGPWF77', 'status' => 'SUCCESSFUL', 'amount' => (float)$tU->amount_expected, 'sender_bank' => 'qris']]])]]]);
$h = payment_recheck($tU, 'uji', true);
$q = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE title = 'Transaksi Uji Lunas' AND message LIKE ?");
$q->execute(['%' . $tU->merchant_ref . '%']);
cek($h['outcome'] === 'applied' && (int)$q->fetchColumn() > 0, 'uji lunas: super admin diberi tahu', $h['outcome']);

// Cron rekonsiliasi — tagihan lain di basis data TIDAK boleh tersentuh:
// ditandai "baru dicek" selama uji, lalu dikembalikan persis.
$J = 'LEG-UJIBAYAR-J';
buat_legalisir($J, 68344);
palsu([['POST', '#/snap/v1/transactions#', fn() => mt_token()]]);
$tJ = payment_create('legalisir', $J, $J, $q_cron = payment_quote('legalisir', ['doc_count' => 1, 'delivery_method' => 'ambil_sendiri']), $pelanggan)['txn'];
$K = 'LEG-UJIBAYAR-K';
buat_legalisir($K, 68344);
$tK = payment_create('legalisir', $K, $K, $q_cron, $pelanggan)['txn'];
$pdo->prepare("UPDATE payment_transactions SET created_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = ?")->execute([$tJ->id]);

$lain = $pdo->query("SELECT id, last_checked_at FROM payment_transactions
                      WHERE status = 'pending' AND merchant_ref NOT LIKE '%UJIBAYAR%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$pdo->exec("UPDATE payment_transactions SET last_checked_at = NOW()
             WHERE status = 'pending' AND merchant_ref NOT LIKE '%UJIBAYAR%'");
$GLOBALS['PANGGILAN'] = [];
palsu([['GET', '#/v2/LEG-UJIBAYAR-J/status#', fn() => mt_status('settlement', 68344)]]);
ob_start();
try {
    include AKAR . '/cron/payment_reconcile.php';
} finally {
    $keluaran_cron = ob_get_clean();
    $pulihkan = $pdo->prepare("UPDATE payment_transactions SET last_checked_at = ? WHERE id = ?");
    foreach ($lain as $id => $waktu) {
        $pulihkan->execute([$waktu, $id]);
    }
}
cek(payment_txn_get($tJ->id)->status === 'paid' && kolom_lama($J)->payment_status === 'settlement',
    'cron: tagihan > 20 menit yang sudah dibayar menjadi lunas', payment_txn_get($tJ->id)->status);
cek(payment_txn_get($tK->id)->status === 'pending' && payment_txn_get($tK->id)->last_checked_at === null,
    'cron: tagihan yang baru terbit tidak diperiksa');
$url_dipanggil = array_column($GLOBALS['PANGGILAN'], 1);
cek(count($url_dipanggil) === 1 && strpos($url_dipanggil[0], 'LEG-UJIBAYAR-J') !== false,
    'cron: hanya tagihan uji yang ditanyakan ke gateway', count($url_dipanggil) . ' panggilan');
cek(strpos($keluaran_cron, '1 berubah') !== false, 'cron: ringkasan jalan tercetak', trim(substr($keluaran_cron, -80)));
$masih = $pdo->query("SELECT id, last_checked_at FROM payment_transactions
                       WHERE status = 'pending' AND merchant_ref NOT LIKE '%UJIBAYAR%'")->fetchAll(PDO::FETCH_KEY_PAIR);
cek($masih == $lain, 'cron: tagihan lain dikembalikan persis', count($masih) . ' baris');
printf("\n────────────────────────────────\n  LULUS: %d   GAGAL: %d\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
