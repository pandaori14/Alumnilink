<?php
/**
 * Uji end-to-end alur pembayaran LEWAT HTTP: pengajuan legalisir, halaman
 * detail, buat ulang tagihan, halaman kembali, verifikasi tunai, donasi,
 * kampanye, callback, laporan, dan struktur formulir.
 *
 * tests/suite/uji_pembayaran.php menguji lapisannya dari CLI dengan
 * transport palsu. Suite ini menguji HANDLER dan HALAMAN yang memakainya —
 * bagian yang tidak dapat disentuh transport palsu, karena berjalan di
 * proses Apache.
 *
 * ── Tanpa satu pun panggilan ke gateway ────────────────────────────────
 * Server key Midtrans dan kredensial Flip DIKOSONGKAN selama uji. Adaptor
 * menolak membuat tagihan sebelum membuka koneksi, sehingga setiap
 * pengajuan berakhir 'create_failed' — jalur yang memang harus teruji:
 * permohonan tetap tersimpan, alumni dapat meminta tagihan ulang.
 * Status 'pending' dan tautan bayar disimulasikan langsung di basis data.
 *
 * Seluruh setting dikembalikan dan data uji dihapus lewat shutdown handler,
 * apa pun yang terjadi di tengah.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once AKAR . '/includes/payment/service.php';
require_once AKAR . '/includes/payment/report.php';

$BASE = uji_base_url();

// ── Setting yang diubah, dikembalikan di akhir ───────────────────────
$KUNCI = ['midtrans_server_key', 'midtrans_client_key', 'midtrans_is_production',
          'flip_secret_key', 'flip_validation_token', 'flip_is_production',
          'payment_gateway_active', 'smtp_force_real', 'payment_last_test_midtrans', 'payment_last_test_flip',
          'fee_flip_percent', 'fee_flip_vat_percent', 'fee_flip_flat', 'fee_flip_min', 'fee_flip_reviewed',
          'payment_margin_legalisir', 'payment_margin_donasi',
          'payment_custom_charge_legalisir', 'payment_custom_charge_donasi', 'payment_expiry',
          // Ditulis ulang oleh setiap POST ke handler Pengaturan (bagian K)
          'google_oauth_auto_verify', 'dashboard_bg_animation', 'email_send_direct', 'audit_log_auto_erase',
          'rbac_enforce', 'cron_token', 'ujialur_kunci_bebas', 'legalisir_require_paid',
          'payment_fallback_enabled', 'fee_midtrans_reviewed',
          'payment_gateway_enabled_midtrans', 'payment_gateway_enabled_flip'];
$SEMULA = [];
foreach ($KUNCI as $k) {
    $q = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $q->execute([$k]);
    $v = $q->fetchColumn();
    $SEMULA[$k] = $v === false ? null : $v;
}

$uid_sa = $pdo->query("SELECT id FROM users WHERE role = 'super_admin' LIMIT 1")->fetchColumn();
$alumni = $pdo->query("SELECT id, is_verified, last_tracer_update FROM users
                        WHERE role = 'alumni' AND email IS NOT NULL AND email <> '' ORDER BY id LIMIT 2")->fetchAll();
$al  = $alumni[0];
$al2 = $alumni[1] ?? null;

$AWAL = [
    'callback' => (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM payment_callbacks")->fetchColumn(),
    'log'      => (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM activity_logs")->fetchColumn(),
    'notif'    => (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM notifications")->fetchColumn(),
];
$DIBUAT = ['legalisir' => [], 'donasi' => [], 'kampanye' => []];
$SESI = [];

function bersihkan_batas()
{
    global $pdo;
    try {
        $pdo->exec("DELETE FROM rate_limits WHERE action IN ('REQUEST_LEGALISIR', 'DONATION', 'PAYMENT_QUOTE')");
    } catch (PDOException $e) {
        // Tabel belum pernah dibuat: tidak ada yang perlu dibersihkan.
    }
}

register_shutdown_function(function () use ($SEMULA, $al, $AWAL) {
    global $pdo, $DIBUAT, $SESI;
    foreach ($SEMULA as $k => $v) {
        if ($v === null) {
            $pdo->prepare("DELETE FROM settings WHERE setting_key = ?")->execute([$k]);
        } else {
            setting_save($k, $v);
        }
    }
    $pdo->prepare("UPDATE users SET is_verified = ?, last_tracer_update = ? WHERE id = ?")
        ->execute([$al->is_verified, $al->last_tracer_update, $al->id]);

    foreach ($DIBUAT['legalisir'] as $id) {
        $q = $pdo->prepare("SELECT documents FROM legalisir_requests WHERE id = ?");
        $q->execute([$id]);
        foreach (json_decode((string)$q->fetchColumn(), true) ?: [] as $d) {
            $f = (string)($d['file'] ?? '');
            if (strpos($f, 'uploads/legalisir/') === 0 && strpos($f, '..') === false) {
                @unlink(AKAR . '/' . $f);
            }
        }
        $pdo->prepare("DELETE FROM payment_transactions WHERE purpose = 'legalisir' AND subject_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM legalisir_requests WHERE id = ?")->execute([$id]);
    }
    foreach ($DIBUAT['donasi'] as $id) {
        $pdo->prepare("DELETE FROM payment_transactions WHERE purpose = 'donasi' AND subject_id = ?")->execute([(string)$id]);
        $pdo->prepare("DELETE FROM donations WHERE id = ?")->execute([$id]);
    }
    foreach ($DIBUAT['kampanye'] as $id) {
        $pdo->prepare("DELETE FROM donations WHERE campaign_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM donation_campaigns WHERE id = ?")->execute([$id]);
    }
    $pdo->prepare("DELETE FROM payment_callbacks WHERE id > ?")->execute([$AWAL['callback']]);
    $pdo->prepare("DELETE FROM activity_logs WHERE id > ?")->execute([$AWAL['log']]);
    $pdo->prepare("DELETE FROM notifications WHERE id > ?")->execute([$AWAL['notif']]);
    bersihkan_batas();
    sesi_hapus(...array_values($SESI));
});

// ── Kondisi uji ──────────────────────────────────────────────────────
setting_save('smtp_force_real', '0');           // e-mail ke log, bukan ke alumni
setting_save('midtrans_server_key', '');        // tagihan gagal SEBELUM koneksi dibuka
setting_save('flip_secret_key', '');
setting_save('flip_validation_token', '');
setting_save('payment_gateway_active', 'midtrans');   // pilihan dikunci: yang diuji alurnya, bukan pemilihannya
setting_save('fee_midtrans_reviewed', '1');
setting_save('payment_fallback_enabled', '0');        // kegagalan gateway harus tetap terlihat sebagai kegagalan
$pdo->prepare("UPDATE users SET is_verified = 1, last_tracer_update = NOW() WHERE id = ?")->execute([$al->id]);
bersihkan_batas();

$CSRF = bin2hex(random_bytes(16));
$SESI = [
    'alumni'  => sesi_palsu($al->id, 'alumni', $CSRF),
    'alumni2' => $al2 ? sesi_palsu($al2->id, 'alumni', $CSRF) : '',
    'sa'      => sesi_palsu($uid_sa, 'super_admin', $CSRF),
    'donasi'  => sesi_palsu($uid_sa, 'admin_donasi', $CSRF),
];

/**
 * @return array [kode HTTP, body, Location]
 */
function minta($sid, $url, $post = null, array $header = [])
{
    global $BASE;
    $ch = curl_init(strpos($url, 'http') === 0 ? $url : "$BASE/$url");
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HEADER => true,
          CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $header];
    if ($sid) {
        $o[CURLOPT_COOKIE] = "PHPSESSID=$sid";
    }
    if ($post !== null) {
        $o[CURLOPT_POST] = true;
        $o[CURLOPT_POSTFIELDS] = $post;
    }
    curl_setopt_array($ch, $o);
    $raw = (string)curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $kepala = substr($raw, 0, $hs);
    $loc = preg_match('/^Location:\s*(\S+)/mi', $kepala, $m) ? $m[1] : '';
    return [$c, substr($raw, $hs), $loc];
}

function txns($purpose, $subject)
{
    global $pdo;
    $q = $pdo->prepare("SELECT * FROM payment_transactions WHERE purpose = ? AND subject_id = ? ORDER BY id");
    $q->execute([$purpose, (string)$subject]);
    return $q->fetchAll(PDO::FETCH_OBJ);
}

function baris_legalisir($id)
{
    global $pdo;
    $q = $pdo->prepare("SELECT * FROM legalisir_requests WHERE id = ?");
    $q->execute([$id]);
    return $q->fetch(PDO::FETCH_OBJ);
}

function tanpa_galat_php($b)
{
    return !preg_match('/<b>(Fatal error|Warning|Notice|Deprecated)<\/b>:/', $b);
}

/** Buang isi <script> lalu ukur kedalaman <form> terdalam. */
function kedalaman_form($html)
{
    $html = preg_replace('#<script\b[^>]*>.*?</script\s*>#si', '', $html);
    preg_match_all('#<(/?)form\b[^>]*>#i', $html, $m);
    $d = 0;
    $maks = 0;
    foreach ($m[1] as $tutup) {
        $d += $tutup === '' ? 1 : -1;
        $maks = max($maks, $d);
    }
    return [$maks, $d];
}

$pdf = sys_get_temp_dir() . '/ujialur_' . bin2hex(random_bytes(4)) . '.pdf';
file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
register_shutdown_function(function () use ($pdf) { @unlink($pdf); });

$jenis = null;
foreach (json_decode((string)setting('legalisir_document_types', '[]'), true) ?: [] as $dt) {
    if (empty($dt['is_akreditasi']) && stripos($dt['id'], 'akreditasi') === false) {
        $jenis = str_replace(' ', '_', $dt['id']);
        break;
    }
}

function ajukan($sid, array $tambahan = [])
{
    global $pdf, $jenis, $CSRF, $DIBUAT;
    $post = array_merge([
        'csrf_token'      => $CSRF,
        'docs_type[0]'    => $jenis,
        'delivery_method' => 'ambil_sendiri',
        "file_$jenis"     => new CURLFile($pdf, 'application/pdf', 'uji.pdf'),
    ], $tambahan);
    $r = minta($sid, 'handlers/legalisir_handler.php', $post);
    if (preg_match('/page=legalisir_detail&id=([^&]+)/', $r[2], $m)) {
        $DIBUAT['legalisir'][] = rawurldecode($m[1]);
        $r[] = rawurldecode($m[1]);
    } else {
        $r[] = null;
    }
    return $r;
}

printf("alumni=%s  super_admin=%s  jenis dokumen=%s\n", $al->id, $uid_sa, $jenis);

// ═════════════════════════════════════════════════════════════════════
echo "\n=== A. Pengajuan legalisir: pratinjau = tagihan ===\n";

$q_api = minta($SESI['alumni'], 'api/payment_quote.php',
    http_build_query(['purpose' => 'legalisir', 'doc_count' => 1, 'delivery_method' => 'ambil_sendiri']),
    ["X-CSRF-Token: $CSRF"]);
$pratinjau = json_decode($q_api[1], true);
cek($q_api[0] === 200 && !empty($pratinjau['ok']), 'pratinjau 1 dokumen tersedia', 'Rp ' . ($pratinjau['total'] ?? '?'));

[$c, , $loc, $id_salah] = ajukan($SESI['alumni'], ['delivery_method' => 'terbang']);
cek(strpos($loc, 'error=invalid_delivery') !== false && $id_salah === null, 'metode kirim di luar daftar ditolak', $loc);

[$c, , $loc, $id1] = ajukan($SESI['alumni']);
cek($id1 !== null, 'pengajuan tersimpan dan dialihkan ke detail', $loc);
cek(strpos($loc, 'error=payment_create_failed') !== false, 'kegagalan gateway dilaporkan, bukan fatal');
$r1 = $id1 ? baris_legalisir($id1) : null;
$t1 = $id1 ? txns('legalisir', $id1) : [];
cek($r1 && (float)$r1->amount === (float)($pratinjau['total'] ?? -1), 'nominal tersimpan = pratinjau',
    ($r1->amount ?? '-') . ' vs ' . ($pratinjau['total'] ?? '-'));
cek(count($t1) === 1 && $t1[0]->status === 'create_failed', 'ledger: satu transaksi create_failed', $t1[0]->status ?? '-');
cek($t1 && (float)$t1[0]->amount_expected === (float)$r1->amount, 'ledger: amount_expected = nominal permohonan');
$rincian = $t1 ? json_decode((string)$t1[0]->fee_breakdown, true) : [];
cek(($rincian['total'] ?? null) == ($pratinjau['total'] ?? -1), 'rincian biaya tersimpan per transaksi');
cek($r1 && $r1->payment_method === 'midtrans' && $r1->payment_status === 'failed',
    'kolom lama tersinkron (midtrans / failed)', ($r1->payment_method ?? '-') . ' / ' . ($r1->payment_status ?? '-'));

// Ongkir: provinsi dengan huruf berbeda tetap cocok dengan zonanya
$zona = json_decode((string)setting('shipping_zones', '[]'), true) ?: [];
$prov = null;
foreach ($zona as $z) {
    if (empty($z['is_default']) && !empty($z['provinces'][0])) {
        $prov = $z['provinces'][0];
        $ongkir_zona = (float)$z['cost'];
        break;
    }
}
if ($prov !== null) {
    [$c, , $loc, $id_k] = ajukan($SESI['alumni'], [
        'delivery_method' => 'kurir', 'addr_name' => 'Uji Alur', 'addr_phone' => '081234567890',
        'addr_street' => 'Jl. Uji 1', 'addr_city' => 'Kota Uji', 'addr_province' => strtolower($prov),
    ]);
    $tk = $id_k ? txns('legalisir', $id_k) : [];
    $rk = $tk ? json_decode((string)$tk[0]->fee_breakdown, true) : [];
    cek(isset($rk['shipping']) && (float)$rk['shipping'] === $ongkir_zona,
        "ongkir zona cocok walau provinsi huruf kecil", strtolower($prov) . ' -> ' . ($rk['shipping'] ?? '-'));
} else {
    cek(true, 'ongkir zona (dilewati: tidak ada zona berprovinsi)');
}

// ═════════════════════════════════════════════════════════════════════
echo "\n=== B. Halaman detail ===\n";

[$c, $b] = minta($SESI['alumni'], "index.php?page=legalisir_detail&id=" . rawurlencode($id1) . "&error=payment_create_failed");
cek($c === 200 && tanpa_galat_php($b), 'detail tampil tanpa galat PHP', "HTTP $c");
cek(strpos($b, 'Buat Tagihan Pembayaran') !== false, 'tagihan gagal: tombol "Buat Tagihan Pembayaran"');
cek(strpos($b, 'id="pay-button"') === false && strpos($b, 'snap.js') === false, 'tagihan gagal: tanpa tombol bayar & snap.js');

if ($al2) {
    [$c, $b] = minta($SESI['alumni2'], "index.php?page=legalisir_detail&id=" . rawurlencode($id1));
    cek(strpos($b, 'Data tidak ditemukan') !== false, 'alumni lain tidak dapat membuka detail');
}

// Simulasi: tagihan Midtrans terbit. Token sengaja memuat karakter berbahaya.
$tok = 'uji-"</script>&\'';
$pdo->prepare("UPDATE payment_transactions SET status = 'pending', snap_token = ?, provider_ref = merchant_ref,
               expires_at = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE id = ?")->execute([$tok, $t1[0]->id]);
[$c, $b] = minta($SESI['alumni'], "index.php?page=legalisir_detail&id=" . rawurlencode($id1));
cek(strpos($b, 'id="pay-button"') !== false, 'Midtrans pending: tombol bayar Snap');
cek((bool)preg_match('#<script src="https://app\.(sandbox\.)?midtrans\.com/snap/snap\.js"#', $b), 'Midtrans pending: snap.js dimuat');
cek(strpos($b, 'window.snap.pay(' . js_json($tok) . ',') !== false, 'token dicetak sebagai literal JS yang aman (js_json)');
cek(strpos($b, 'snap.pay(&quot;') === false, 'token tidak di-escape HTML di dalam <script>');

// Simulasi: tagihan Flip terbit
$pdo->prepare("UPDATE payment_transactions SET gateway = 'flip', snap_token = NULL,
               pay_url = 'https://flip.id/pwf/uji?a=1&b=2' WHERE id = ?")->execute([$t1[0]->id]);
[$c, $b] = minta($SESI['alumni'], "index.php?page=legalisir_detail&id=" . rawurlencode($id1));
cek(strpos($b, 'href="https://flip.id/pwf/uji?a=1&amp;b=2"') !== false, 'Flip pending: tautan ke halaman bayar Flip');
cek(strpos($b, 'snap.js') === false && strpos($b, 'id="pay-button"') === false, 'Flip pending: snap.js tidak dimuat');
cek(strpos($b, 'Diproses aman oleh Flip') !== false, 'label gateway mengikuti transaksi, bukan setting');

// ═════════════════════════════════════════════════════════════════════
echo "\n=== C. Halaman kembali dari gateway ===\n";

$ref1 = $t1[0]->merchant_ref;
[$c, , $loc] = minta('', 'handlers/payment_return.php?ref=' . rawurlencode($ref1));
cek(strpos($loc, 'page=legalisir_detail&id=' . rawurlencode($id1) . '&payment=pending') !== false,
    'tanpa sesi: status diambil ulang, tetap pending', $loc);
[$c, , $loc] = minta('', 'handlers/payment_return.php?ref=TIDAK-ADA&order_id=' . rawurlencode($ref1) . '&transaction_status=settlement');
cek(strpos($loc, 'page=dashboard') !== false, 'ref tak dikenal: parameter gateway diabaikan', $loc);
cek(txns('legalisir', $id1)[0]->status === 'pending', 'transaction_status di URL tidak mengubah status');

// ═════════════════════════════════════════════════════════════════════
echo "\n=== D. Buat ulang tagihan ===\n";

[$c] = minta($SESI['alumni'], 'handlers/regenerate_payment.php', ['request_id' => $id1]);
cek($c === 403, 'tanpa token CSRF ditolak', "HTTP $c");

if ($al2) {
    [$c, , $loc] = minta($SESI['alumni2'], 'handlers/regenerate_payment.php', ['csrf_token' => $CSRF, 'request_id' => $id1]);
    cek(strpos($loc, 'error=not_found') !== false, 'alumni lain tidak dapat membuat ulang tagihan', $loc);
}

[$c, , $loc] = minta($SESI['donasi'], 'handlers/regenerate_payment.php', ['csrf_token' => $CSRF, 'request_id' => $id1]);
cek($c === 403, 'staf tanpa legalisir.kelola ditolak', "HTTP $c");

[$c, , $loc] = minta($SESI['alumni'], 'handlers/regenerate_payment.php', ['csrf_token' => $CSRF, 'request_id' => $id1]);
$t1 = txns('legalisir', $id1);
cek(strpos($loc, 'page=legalisir_detail') !== false && strpos($loc, 'error=payment_create_failed') !== false,
    'pemilik: tagihan baru diminta, kegagalan dilaporkan', $loc);
cek(count($t1) === 2 && $t1[0]->status === 'cancelled', 'tagihan lama dibatalkan', $t1[0]->status ?? '-');
cek(count($t1) === 2 && $t1[1]->gateway === 'midtrans' && (float)$t1[1]->amount_expected === (float)$t1[0]->amount_expected,
    'tagihan baru: gateway aktif, nominal sama', ($t1[1]->gateway ?? '-') . ' Rp ' . ($t1[1]->amount_expected ?? '-'));

// ═════════════════════════════════════════════════════════════════════
echo "\n=== E. Verifikasi tunai dan penjaga lunas ===\n";

[$c, , $loc] = minta($SESI['sa'], 'handlers/admin_verify_cash.php?id=' . rawurlencode($id1));
cek($c === 403, 'verifikasi tunai tanpa token ditolak', "HTTP $c");

[$c, , $loc] = minta($SESI['sa'], 'handlers/admin_verify_cash.php?id=' . rawurlencode($id1) . "&csrf_token=$CSRF");
$r1 = baris_legalisir($id1);
$t1 = txns('legalisir', $id1);
$tunai = array_values(array_filter($t1, function ($t) { return $t->gateway === 'cash'; }));
cek(strpos($loc, 'success=cash_verified') !== false, 'verifikasi tunai berhasil', $loc);
cek($r1->payment_status === 'settlement' && $r1->payment_method === 'cash' && $r1->status === 'processing',
    'permohonan lunas tunai dan maju ke diproses', "$r1->payment_status / $r1->payment_method / $r1->status");
cek(count($tunai) === 1 && $tunai[0]->status === 'paid', 'ledger: transaksi tunai paid');
cek(count(array_filter($t1, function ($t) { return in_array($t->status, ['pending', 'create_failed'], true); })) === 0,
    'tagihan online yang masih terbuka ditutup');

[$c, , $loc] = minta($SESI['sa'], 'handlers/admin_verify_cash.php?id=' . rawurlencode($id1) . "&csrf_token=$CSRF");
cek(strpos($loc, 'error=verify_failed') !== false && strpos($loc, 'reason=') !== false, 'verifikasi kedua ditolak dengan alasan', $loc);

[$c, , $loc] = minta($SESI['alumni'], 'handlers/regenerate_payment.php', ['csrf_token' => $CSRF, 'request_id' => $id1]);
cek(strpos($loc, 'error=already_paid') !== false, 'buat ulang tagihan atas permohonan lunas ditolak', $loc);
cek(count(txns('legalisir', $id1)) === count($t1), 'tidak ada transaksi baru terbit');

[$c, , $loc] = minta($SESI['sa'], 'handlers/admin_delete_legalisir.php?id=' . rawurlencode($id1) . "&csrf_token=$CSRF");
cek(strpos($loc, 'error=delete_paid') !== false && baris_legalisir($id1), 'permohonan lunas tidak dapat dihapus', $loc);

[$c, $b] = minta($SESI['alumni'], "index.php?page=legalisir_detail&id=" . rawurlencode($id1));
cek($c === 200 && tanpa_galat_php($b) && strpos($b, 'id="regen-form"') === false, 'detail lunas: tanpa area bayar');

// ═════════════════════════════════════════════════════════════════════
echo "\n=== F. Kelola Legalisir: struktur formulir dan aksi massal ===\n";

[$c, $b] = minta($SESI['sa'], 'index.php?page=admin_legalisir&cari=' . rawurlencode($id1));
[$maks, $sisa] = kedalaman_form($b);
cek($c === 200 && tanpa_galat_php($b), 'halaman tampil tanpa galat PHP', "HTTP $c");
cek($maks === 1 && $sisa === 0, 'tidak ada <form> bersarang', "kedalaman maks $maks, sisa $sisa");
$n_centang = preg_match_all('#<input[^>]*name="ids\[\]"[^>]*>#', $b, $mc);
$n_berpemilik = count(array_filter($mc[0], function ($x) { return strpos($x, 'form="form-bulk"') !== false; }));
cek($n_centang > 0 && $n_centang === $n_berpemilik, 'setiap kotak centang terikat ke form-bulk', "$n_berpemilik / $n_centang");
cek(preg_match('#<select[^>]*name="status"[^>]*form="form-bulk"#', $b) === 1, 'pilihan status massal terikat ke form-bulk');
cek(preg_match('#<form[^>]*id="form-bulk"[^>]*></form>#', $b) === 1, 'form-bulk berupa elemen kosong');

[$c, $b] = minta($SESI['sa'], 'index.php?page=admin_legalisir&bayar=settlement&cari=' . rawurlencode($id1));
cek(preg_match('/Menampilkan (\d+) dari (\d+)/', $b, $m) === 1 && (int)$m[2] === 1, 'saringan bayar "settlement" menemukan data', $m[0] ?? '-');

$ganda = [];
for ($i = 1; $i <= 60; $i++) {
    $ganda[] = 'ids[]=' . rawurlencode("UJIALUR-NA-$i");
    $ganda[] = 'ids[]=' . rawurlencode("UJIALUR-NA-$i");
}
[$c, , $loc] = minta($SESI['sa'], 'handlers/admin_legalisir_bulk.php',
    implode('&', $ganda) . "&status=processing&csrf_token=$CSRF");
cek(strpos($loc, 'bulk_terlalu_banyak') === false && strpos($loc, 'success=bulk') !== false,
    '60 pengajuan (120 nilai desktop+mobile) tidak dianggap > 100', $loc);
$banyak = [];
for ($i = 1; $i <= 101; $i++) {
    $banyak[] = 'ids[]=' . rawurlencode("UJIALUR-NA-$i");
}
[$c, , $loc] = minta($SESI['sa'], 'handlers/admin_legalisir_bulk.php',
    implode('&', $banyak) . "&status=processing&csrf_token=$CSRF");
cek(strpos($loc, 'bulk_terlalu_banyak') !== false, '101 pengajuan unik tetap ditolak', $loc);

[$c, $b] = minta($SESI['sa'], 'index.php?page=admin_settings');
[$maks, $sisa] = kedalaman_form($b);
cek($maks === 1 && $sisa === 0, 'Pengaturan: tidak ada <form> bersarang', "kedalaman maks $maks, sisa $sisa");
cek(preg_match('#<form[^>]*id="form-cadangan"#', $b) === 1 && preg_match('#<button[^>]*form="form-cadangan"#', $b) === 1,
    'tombol unduh cadangan terikat ke form-cadangan');

// ═════════════════════════════════════════════════════════════════════
echo "\n=== G. Donasi ===\n";

$pdo->exec("INSERT INTO donation_campaigns (title, description, target_amount, end_date, is_active)
            VALUES ('UJIALUR aktif', 'uji', 1000000, NULL, 1)");
$k_aktif = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO donation_campaigns (title, description, target_amount, end_date, is_active)
            VALUES ('UJIALUR nonaktif', 'uji', 1000000, NULL, 0)");
$k_mati = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO donation_campaigns (title, description, target_amount, end_date, is_active)
            VALUES ('UJIALUR lewat', 'uji', 1000000, '2020-01-01', 1)");
$k_lewat = (int)$pdo->lastInsertId();
$DIBUAT['kampanye'] = [$k_aktif, $k_mati, $k_lewat];

$donasi = function ($sid, array $x) use ($CSRF, $k_aktif) {
    return minta($sid, 'handlers/donation_handler.php', http_build_query(array_merge(
        ['csrf_token' => $CSRF, 'campaign_id' => $k_aktif, 'amount' => 50000, 'donor_name' => 'UJIALUR'], $x)));
};

[$c] = $donasi($SESI['alumni'], ['csrf_token' => '']);
cek($c === 403, 'tanpa token CSRF ditolak', "HTTP $c");
[$c, $b] = $donasi($SESI['alumni'], ['amount' => 'abc']);
cek($c === 422, 'nominal bukan angka ditolak', "HTTP $c " . substr($b, 0, 60));
[$c] = $donasi($SESI['alumni'], ['amount' => 9999]);
cek($c === 422, 'nominal di bawah Rp 10.000 ditolak', "HTTP $c");
[$c] = $donasi($SESI['alumni'], ['campaign_id' => $k_mati]);
cek($c === 422, 'kampanye nonaktif ditolak', "HTTP $c");
[$c] = $donasi($SESI['alumni'], ['campaign_id' => $k_lewat]);
cek($c === 422, 'kampanye lewat tanggal akhir ditolak', "HTTP $c");

$sebelum = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM donations")->fetchColumn();
[$c, $b] = $donasi($SESI['alumni'], []);
$don = $pdo->query("SELECT * FROM donations WHERE id > $sebelum AND donor_name = 'UJIALUR' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_OBJ);
if ($don) {
    $DIBUAT['donasi'][] = (int)$don->id;
}
$td = $don ? txns('donasi', $don->id) : [];
$kutipan = payment_quote('donasi', ['amount' => 50000]);
cek($c === 502 && isset(json_decode($b, true)['error']), 'gateway gagal: balasan 502 berisi pesan', "HTTP $c");
cek($don && $don->status === 'failed', 'donasi tercatat gagal, tidak menggantung pending', $don->status ?? '-');
cek($td && $td[0]->status === 'create_failed' && (float)$td[0]->amount_expected === (float)$kutipan['total'],
    'ledger: nominal = pratinjau donasi', ($td[0]->amount_expected ?? '-') . ' vs ' . $kutipan['total']);
cek($don && $don->midtrans_order_id === ($td[0]->merchant_ref ?? null), 'referensi donasi = merchant_ref ledger');

// Seluruh gateway dimatikan super admin: donasi tidak punya jalur tunai,
// jadi ditolak SEBELUM baris donasi dibuat — bukan dibiarkan menggantung
// sebagai pending yang tidak pernah dapat dibayar.
$donasi_awal = (int)$pdo->query("SELECT COUNT(*) FROM donations")->fetchColumn();
setting_save('payment_gateway_enabled_midtrans', '0');
setting_save('payment_gateway_enabled_flip', '0');
[$c, $b] = $donasi($SESI['alumni'], []);
cek($c === 503 && strpos($b, 'tidak tersedia') !== false, 'gateway dimatikan: donasi ditolak dengan alasan', "HTTP $c");
cek((int)$pdo->query("SELECT COUNT(*) FROM donations")->fetchColumn() === $donasi_awal,
    'tidak ada donasi menggantung saat pembayaran online tutup');
[$c, $b] = minta($SESI['alumni'], "index.php?page=donasi_detail&id=$k_aktif");
cek($c === 200 && strpos($b, 'Donasi sedang ditutup sementara') !== false && strpos($b, 'id="payButton"') === false,
    'halaman donasi menyembunyikan tombol dan menjelaskan sebabnya');
setting_save('payment_gateway_enabled_midtrans', '1');
setting_save('payment_gateway_enabled_flip', '1');

[$c, $b] = minta($SESI['alumni'], 'index.php?page=donasi&status=success');
cek(strpos($b, 'Terima kasih! Donasi Anda sudah kami terima.') !== false, 'halaman donasi membaca status kembali');
[$c, $b] = minta($SESI['alumni'], 'index.php?page=donasi&status=<b>x</b>');
cek($c === 200 && strpos($b, '<b>x</b>') === false && tanpa_galat_php($b), 'status tak dikenal diabaikan');
[$c, $b] = minta($SESI['alumni'], "index.php?page=donasi_detail&id=$k_aktif");
cek($c === 200 && strpos($b, 'id="rincianDonasi"') !== false && tanpa_galat_php($b), 'detail donasi menampilkan rincian biaya');

// ═════════════════════════════════════════════════════════════════════
echo "\n=== H. Kampanye ===\n";

$aktif = function ($id) use ($pdo) {
    return $pdo->query("SELECT is_active FROM donation_campaigns WHERE id = " . (int)$id)->fetchColumn();
};
[$c] = minta($SESI['sa'], "handlers/admin_campaign_handler.php?action=toggle&id=$k_aktif");
cek($c === 403 && (int)$aktif($k_aktif) === 1, 'toggle tanpa token ditolak', "HTTP $c");
minta($SESI['sa'], "handlers/admin_campaign_handler.php?action=toggle&id=$k_aktif&csrf_token=$CSRF");
cek((int)$aktif($k_aktif) === 0, 'toggle dengan token berjalan');
[$c] = minta($SESI['sa'], "handlers/admin_campaign_handler.php?action=delete&id=$k_aktif");
cek($c === 403 && $aktif($k_aktif) !== false, 'hapus tanpa token ditolak', "HTTP $c");
[$c, , $loc] = minta($SESI['sa'], "handlers/admin_campaign_handler.php?action=delete&id=$k_aktif&csrf_token=$CSRF");
cek(strpos($loc, 'error=campaign_has_donations') !== false && $aktif($k_aktif) !== false,
    'kampanye berdonasi tidak dapat dihapus', $loc);
minta($SESI['sa'], "handlers/admin_campaign_handler.php?action=delete&id=$k_mati&csrf_token=$CSRF");
cek($aktif($k_mati) === false, 'kampanye tanpa donasi dapat dihapus');
[$c, $b] = minta($SESI['sa'], 'index.php?page=admin_donasi&error=campaign_has_donations');
cek(strpos($b, 'tidak dapat dihapus') !== false && strpos($b, "action=toggle&id=$k_aktif&csrf_token=") !== false,
    'halaman admin: pesan galat dan tautan bertoken');

// ═════════════════════════════════════════════════════════════════════
echo "\n=== I. Callback ===\n";

foreach (['midtrans_webhook', 'flip_callback'] as $h) {
    [$c] = minta('', "handlers/$h.php");
    cek($c === 405, "$h: GET ditolak", "HTTP $c");
}
[$c] = minta('', 'handlers/midtrans_webhook.php', '{"order_id":"X"}', ['Content-Type: application/json']);
cek($c === 400, 'midtrans: notifikasi tidak lengkap -> 400', "HTTP $c");
$notif = json_encode(['order_id' => $ref1, 'status_code' => '200', 'gross_amount' => '1.00',
                      'signature_key' => str_repeat('0', 128), 'transaction_status' => 'settlement']);
[$c] = minta('', 'handlers/midtrans_webhook.php', $notif, ['Content-Type: application/json']);
cek($c === 503, 'midtrans: server key kosong -> 503 (gateway mengulang)', "HTTP $c");
setting_save('midtrans_server_key', 'SB-Mid-server-UJIALUR');
[$c] = minta('', 'handlers/midtrans_webhook.php', $notif, ['Content-Type: application/json']);
cek($c === 403, 'midtrans: tanda tangan salah -> 403', "HTTP $c");
setting_save('midtrans_server_key', '');

[$c] = minta('', 'handlers/flip_callback.php', http_build_query(['data' => '{}', 'token' => 'x']));
cek($c === 503, 'flip: validation token kosong -> 503', "HTTP $c");
setting_save('flip_validation_token', 'token-ujialur');
[$c] = minta('', 'handlers/flip_callback.php', http_build_query(['data' => '{}', 'token' => 'salah']));
cek($c === 403, 'flip: token salah -> 403', "HTTP $c");
[$c] = minta('', 'handlers/flip_callback.php', http_build_query(['data' => 'bukan json', 'token' => 'token-ujialur']));
cek($c === 400, 'flip: data bukan JSON -> 400', "HTTP $c");
setting_save('flip_validation_token', '');
$jurnal = (int)$pdo->query("SELECT COUNT(*) FROM payment_callbacks WHERE id > {$AWAL['callback']}")->fetchColumn();
cek($jurnal >= 5, 'setiap callback tercatat di jurnal', "$jurnal baris");
cek(baris_legalisir($id1)->payment_status === 'settlement', 'callback palsu tidak mengubah permohonan');

// ═════════════════════════════════════════════════════════════════════
echo "\n=== J. Laporan ===\n";

foreach (['admin_keuangan&method=flip', "admin_keuangan&method=x'+OR+'1", 'admin_dashboard', 'dashboard'] as $p) {
    [$c, $b] = minta($SESI['sa'], "index.php?page=$p");
    cek($c === 200 && tanpa_galat_php($b) && stripos($b, 'Flip') !== false, "$p tampil dengan kartu Flip", "HTTP $c");
}
$pendapatan = payment_revenue_by_method($pdo);
cek(abs($pendapatan['midtrans'] + $pendapatan['flip'] + $pendapatan['cash'] + $pendapatan['lainnya'] - $pendapatan['total']) < 0.01,
    'jumlah kartu per metode = total');
[$c, $b] = minta($SESI['sa'], 'handlers/export_keuangan.php?method=cash');
cek($c === 200 && strpos($b, 'Tunai') !== false, 'ekspor: label metode "Tunai"', "HTTP $c");

// Laporan tidak lagi mengurangi potongan tetap `admin_fee`. $id1 lunas
// TUNAI: uangnya sampai utuh ke loket, jadi yang diterima fakultas sama
// dengan yang dibayar alumni. Angka lama memotongnya seolah ada gateway.
$bruto1 = number_format((float)$r1->amount, 0, ',', '.');
[$c, $b] = minta($SESI['sa'], 'index.php?page=admin_keuangan&cari=' . rawurlencode($id1));
cek($c === 200 && tanpa_galat_php($b), 'Laporan Keuangan tampil', "HTTP $c");
cek(strpos($b, 'Dibayar Alumni') !== false && strpos($b, 'Diterima Fakultas') !== false,
    'laporan memisahkan yang dibayar alumni dari yang diterima fakultas');
cek(substr_count($b, $bruto1) >= 2, 'pembayaran tunai dilaporkan utuh, tanpa potongan karangan', "Rp $bruto1");
[$c, $b] = minta($SESI['sa'], 'handlers/export_keuangan.php?method=cash');
cek(strpos($b, 'Biaya Layanan (Rp)') !== false && strpos($b, 'Diterima Fakultas (Rp)') !== false,
    'ekspor membawa kolom biaya dan neto');
cek(strpos($b, $bruto1) !== false, 'ekspor memuat nominal yang benar-benar dibayar', "Rp $bruto1");

// ═════════════════════════════════════════════════════════════════════
echo "\n=== K. Panel gateway pembayaran ===\n";

$panel = function ($sid, array $post) use ($CSRF) {
    return minta($sid, 'handlers/admin_payment_gateway_handler.php', http_build_query(array_merge(['csrf_token' => $CSRF], $post)));
};
$flash = function ($sid) {
    [, $b] = minta($sid, 'index.php?page=admin_payment_gateway');
    return preg_match('#<span class="text-sm font-medium">([^<]*)</span>#', $b, $m) ? html_entity_decode($m[1]) : '';
};

$rahasia = 'SB-Mid-server-UJIALURRAHASIA9876';
setting_save('midtrans_server_key', $rahasia);
[$c, $b] = minta($SESI['sa'], 'index.php?page=admin_payment_gateway');
cek($c === 200 && tanpa_galat_php($b) && strpos($b, 'Gateway Pembayaran') !== false, 'panel tampil bagi super admin', "HTTP $c");
cek(strpos($b, $rahasia) === false && strpos($b, '••••9876') !== false, 'server key tidak pernah dicetak, hanya 4 karakter terakhir');
cek(strpos($b, 'handlers/midtrans_webhook.php') !== false && strpos($b, 'handlers/flip_callback.php') !== false, 'kedua URL callback ditampilkan');
cek(strpos($b, 'Belum dapat dijadikan pilihan utama') !== false, 'Flip tanpa tes: penghalang sakelar ditampilkan');
cek(strpos($b, 'Dipakai sekarang') !== false && strpos($b, 'Pilihan utama') !== false,
    'panel membedakan pilihan utama dan gateway yang dipakai');
setting_save('midtrans_server_key', '');

$SESI['legalisir'] = sesi_palsu($uid_sa, 'admin_legalisir', $CSRF);
[$c, $b] = minta($SESI['legalisir'], 'index.php?page=admin_payment_gateway');
cek($c === 302 || strpos($b, 'Akses Ditolak') !== false, 'admin legalisir tidak dapat membuka panel', "HTTP $c");
cek(strpos($b, 'handlers/flip_callback.php') === false, 'isi panel tidak bocor ke peran lain');
[$c] = $panel($SESI['legalisir'], ['aksi' => 'umum', 'payment_custom_charge_legalisir' => 0, 'payment_custom_charge_donasi' => 0, 'payment_expiry' => 60]);
cek($c === 403, 'handler menolak peran selain super admin', "HTTP $c");
[$c] = minta($SESI['sa'], 'handlers/admin_payment_gateway_handler.php', http_build_query(['aksi' => 'tes', 'gateway' => 'flip']));
cek($c === 403, 'handler tanpa token CSRF ditolak', "HTTP $c");

// Kredensial: rahasia hanya-tulis
$panel($SESI['sa'], ['aksi' => 'kredensial', 'gateway' => 'flip', 'mode' => '0', 'flip_secret_key' => 'rahasia-flip-ujialur', 'flip_validation_token' => 'token-ujialur']);
all_settings(true);
cek(setting('flip_secret_key', '') === 'rahasia-flip-ujialur' && setting('flip_validation_token', '') === 'token-ujialur', 'kredensial Flip tersimpan');
setting_save('payment_last_test_flip', json_encode(['at' => date('Y-m-d H:i:s'), 'ok' => true, 'message' => 'uji', 'production' => false]));
$panel($SESI['sa'], ['aksi' => 'kredensial', 'gateway' => 'flip', 'mode' => '0', 'flip_secret_key' => '', 'flip_validation_token' => '']);
all_settings(true);
cek(setting('flip_secret_key', '') === 'rahasia-flip-ujialur', 'kolom rahasia kosong = tidak diubah');
cek(setting('payment_last_test_flip', '') !== '', 'tanpa perubahan: tes koneksi terakhir tetap berlaku');
$panel($SESI['sa'], ['aksi' => 'kredensial', 'gateway' => 'flip', 'mode' => '0', 'flip_secret_key' => 'rahasia baru', 'flip_validation_token' => '']);
all_settings(true);
cek(setting('flip_secret_key', '') === 'rahasia-flip-ujialur', 'rahasia berisi spasi ditolak');
$panel($SESI['sa'], ['aksi' => 'kredensial', 'gateway' => 'flip', 'mode' => '0', 'flip_secret_key' => 'rahasia-flip-kedua', 'flip_validation_token' => '']);
all_settings(true);
cek(setting('flip_secret_key', '') === 'rahasia-flip-kedua' && setting('payment_last_test_flip', '') === '', 'kunci diganti: tes koneksi lama dibatalkan');
$panel($SESI['sa'], ['aksi' => 'kredensial', 'gateway' => 'flip', 'mode' => '0', 'hapus_flip_validation_token' => '1']);
all_settings(true);
cek(setting('flip_validation_token', 'KOSONG') === 'KOSONG','kotak "Kosongkan" menghapus rahasia');

// Profil biaya dan pengaturan umum
$profil_awal = payment_fee_profile('flip');
$panel($SESI['sa'], ['aksi' => 'biaya', 'gateway' => 'flip', 'percent' => '-1', 'vat_percent' => 0, 'flat' => 4321, 'min' => 0]);
$pesan = $flash($SESI['sa']);
all_settings(true);
cek(payment_fee_profile('flip') === $profil_awal, 'persen negatif ditolak, profil tidak berubah');
cek(strpos($pesan, 'tidak negatif') !== false, 'pesan penolakan ditampilkan di panel', $pesan);
$panel($SESI['sa'], ['aksi' => 'biaya', 'gateway' => 'flip', 'percent' => '40', 'vat_percent' => 0, 'flat' => 4321, 'app' => 0, 'min' => 0]);
$pesan = $flash($SESI['sa']);
all_settings(true);
cek(payment_fee_profile('flip') === $profil_awal && strpos($pesan, '30%') !== false, 'persen di atas batas wajar ditolak', $pesan);
$panel($SESI['sa'], ['aksi' => 'biaya', 'gateway' => 'flip', 'percent' => '0.7', 'vat_percent' => '11', 'flat' => 4000, 'app' => 0, 'min' => 0, 'reviewed' => '1']);
all_settings(true);
$pf = payment_fee_profile('flip');
cek($pf['percent'] == 0.7 && $pf['flat'] === 4000 && $pf['reviewed'], 'profil biaya Flip tersimpan dan ditandai ditinjau');
$panel($SESI['sa'], ['aksi' => 'biaya', 'gateway' => 'flip', 'percent' => '0.7', 'vat_percent' => '11', 'flat' => 4000, 'app' => 0, 'min' => 0]);
all_settings(true);
cek(!payment_fee_profile('flip')['reviewed'], 'disimpan tanpa centang: tanda ditinjau dilepas');
$panel($SESI['sa'], ['aksi' => 'umum', 'payment_custom_charge_legalisir' => 6500, 'payment_custom_charge_donasi' => 0, 'payment_expiry' => 5]);
all_settings(true);
cek(setting('payment_expiry', '') !== '5', 'masa berlaku di bawah 15 menit ditolak');

// Sakelar lewat HTTP
$panel($SESI['sa'], ['aksi' => 'aktifkan', 'gateway' => 'flip']);
$pesan = $flash($SESI['sa']);
all_settings(true);
cek(payment_active_gateway_code() === 'midtrans' && strpos($pesan, 'Gateway tidak dipindah') !== false,
    'aktifkan Flip tanpa syarat lengkap: ditolak dengan alasan', $pesan);

// Transaksi uji: kredensial Midtrans kosong -> ditolak, tanpa baris ledger
$sebelum_uji = (int)$pdo->query("SELECT COUNT(*) FROM payment_transactions WHERE purpose = 'uji'")->fetchColumn();
$panel($SESI['sa'], ['aksi' => 'uji', 'gateway' => 'midtrans']);
cek((int)$pdo->query("SELECT COUNT(*) FROM payment_transactions WHERE purpose = 'uji'")->fetchColumn() === $sebelum_uji,
    'transaksi uji tanpa kredensial: tidak ada transaksi terbit');

// Handler Pengaturan menolak kunci pembayaran
$centang = [];
foreach (['google_oauth_auto_verify', 'dashboard_bg_animation', 'smtp_force_real', 'email_send_direct', 'audit_log_auto_erase', 'rbac_enforce'] as $k) {
    if (setting($k, '0') === '1') {
        $centang[$k] = '1';     // dikirim apa adanya: tidak ada sakelar yang berubah
    }
}
$lindung = ['midtrans_server_key', 'fee_midtrans_percent', 'payment_gateway_active', 'flip_secret_key', 'cron_token'];
$sebelum_lindung = [];
foreach ($lindung as $k) {
    $sebelum_lindung[$k] = setting($k, null);
}
minta($SESI['sa'], 'handlers/admin_settings_handler.php', http_build_query(array_merge($centang, [
    'csrf_token' => $CSRF, 'midtrans_server_key' => 'DISUSUPKAN', 'fee_midtrans_percent' => '99',
    'payment_gateway_active' => 'flip', 'flip_secret_key' => 'DISUSUPKAN', 'cron_token' => 'DISUSUPKAN',
    'ujialur_kunci_bebas' => 'tersimpan',
])));
all_settings(true);
$sesudah_lindung = [];
foreach ($lindung as $k) {
    $sesudah_lindung[$k] = setting($k, null);
}
cek($sesudah_lindung === $sebelum_lindung, 'handler Pengaturan menolak kunci pembayaran & rahasia sistem');
cek(setting('ujialur_kunci_bebas', '') === 'tersimpan', 'kunci biasa tetap tersimpan lewat Pengaturan');
[$c, $b] = minta($SESI['sa'], 'index.php?page=admin_settings');
cek(strpos($b, 'name="midtrans_server_key"') === false && strpos($b, 'index.php?page=admin_payment_gateway') !== false,
    'Pengaturan: kolom Midtrans diganti tautan ke panel');
cek(strpos($b, 'payment_reconcile.php') !== false, 'Pengaturan: cron rekonsiliasi tercantum');
// ═════════════════════════════════════════════════════════════════════
echo "\n=== L. Legalisir wajib lunas sebelum diproses ===\n";

$ubah_status = function ($id, $status, $alasan = '') use ($SESI, $CSRF) {
    return minta($SESI['sa'], 'handlers/admin_update_legalisir.php', http_build_query(
        ['csrf_token' => $CSRF, 'id' => $id, 'status' => $status, 'rejection_reason' => $alasan]));
};
$status_leg = function ($id) {
    return baris_legalisir($id)->status;
};
$belum = $id_k ?? null;   // pengajuan kurir dari bagian A: tagihannya gagal, belum lunas
if ($belum === null) {
    cek(false, 'pengajuan belum lunas tersedia untuk uji');
} else {
    setting_save('legalisir_require_paid', '0');
    $log_awal = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'LEGALISIR_UNPAID_PROCESSED'")->fetchColumn();
    [, , $loc] = $ubah_status($belum, 'processing');
    cek(strpos($loc, 'success=updated') !== false && strpos($loc, 'peringatan=belum_lunas') !== false && $status_leg($belum) === 'processing',
        'mode peringatan: tetap diproses, admin diberi peringatan', $loc);
    cek((int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'LEGALISIR_UNPAID_PROCESSED'")->fetchColumn() === $log_awal + 1,
        'mode peringatan: tercatat di Audit Trail');
    [, $b] = minta($SESI['sa'], 'index.php?page=admin_legalisir&success=updated&peringatan=belum_lunas');
    cek(strpos($b, 'BELUM LUNAS') !== false, 'mode peringatan: pemberitahuan tampil di Kelola Legalisir');

    setting_save('legalisir_require_paid', '1');
    [, , $loc] = $ubah_status($belum, 'completed');
    cek(strpos($loc, 'error=belum_lunas') !== false && $status_leg($belum) === 'processing',
        'mode wajib lunas: diselesaikan ditolak', $loc);
    [, , $loc] = minta($SESI['sa'], 'handlers/admin_legalisir_bulk.php', http_build_query(
        ['csrf_token' => $CSRF, 'ids' => [$belum], 'status' => 'completed']));
    cek(strpos($loc, 'n=0') !== false && strpos($loc, 'gagal=1') !== false && $status_leg($belum) === 'processing',
        'mode wajib lunas: aksi massal juga ditolak', $loc);
    [, , $loc] = $ubah_status($belum, 'rejected', 'Pengajuan uji ditolak untuk memeriksa aturan wajib lunas.');
    cek(strpos($loc, 'success=updated') !== false && $status_leg($belum) === 'rejected', 'mode wajib lunas: penolakan tetap diizinkan', $loc);
    [, , $loc] = $ubah_status($id1, 'completed');
    cek(strpos($loc, 'success=updated') !== false && strpos($loc, 'peringatan') === false && $status_leg($id1) === 'completed',
        'mode wajib lunas: pengajuan LUNAS tidak terhalang', $loc);
    [, $b] = minta($SESI['sa'], 'index.php?page=admin_legalisir&error=belum_lunas');
    cek(strpos($b, 'wajib lunas') !== false, 'pesan penolakan tampil di Kelola Legalisir');
    setting_save('legalisir_require_paid', '0');
}
echo "\n────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
exit($fail ? 1 : 0);
