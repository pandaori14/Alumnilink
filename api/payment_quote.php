<?php
/**
 * Pratinjau rincian biaya — dihitung SERVER, bukan JavaScript.
 *
 * ── Mengapa endpoint ini ada ───────────────────────────────────────────
 * Pratinjau di pages/legalisir.php dulu menghitung sendiri dengan salinan
 * rumus di JavaScript, sementara handler memakai salinan lain yang berbeda
 * satuan dan nama kunci. Alumni melihat Rp 68.344 lalu ditagih Rp 60.000.
 *
 * Sekarang halaman meminta angka dari sini, dan handler memanggil fungsi
 * yang sama (payment_quote). Yang ditampilkan PASTI sama dengan yang
 * ditagih.
 *
 * POST, wajib login, token CSRF lewat header X-CSRF-Token.
 */

require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/payment/service.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Hanya POST.']);
    exit;
}
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesi berakhir. Silakan masuk kembali.']);
    exit;
}
validate_csrf_request(true);

// Pratinjau dipanggil setiap kali centang dokumen atau provinsi berubah,
// jadi batasnya longgar — cukup untuk mencegah pemakaian sebagai alat
// penghitung massal.
check_rate_limit('PAYMENT_QUOTE', 120, 5);

$purpose = (string)($_POST['purpose'] ?? '');
if (!in_array($purpose, ['legalisir', 'donasi'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Jenis pembayaran tidak dikenal.']);
    exit;
}

$input = $purpose === 'legalisir'
    ? [
        'doc_count'       => filter_var($_POST['doc_count'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['default' => 0]]),
        'delivery_method' => (string)($_POST['delivery_method'] ?? ''),
        'province'        => substr((string)($_POST['province'] ?? ''), 0, 80),
      ]
    : ['amount' => filter_var($_POST['amount'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['default' => 0]])];

$q = payment_quote($purpose, $input);
if (!$q['ok']) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $q['error']]);
    exit;
}

echo json_encode([
    'ok'            => true,
    'gateway'       => $q['gateway'],
    'gateway_label' => payment_gateway_label($q['gateway']),
    'doc_count'     => $q['doc_count'],
    'documents'     => $q['documents'],
    'shipping'      => $q['shipping'],
    'admin_total'   => $q['admin_total'],
    'total'         => $q['total'],
]);
