<?php
/**
 * Penerima callback bersama untuk seluruh gateway.
 *
 * Dipanggil oleh handlers/midtrans_webhook.php dan handlers/flip_callback.php.
 *
 * ── Kode balasan ───────────────────────────────────────────────────────
 * Gateway mengulang callback yang tidak dibalas 2xx. Karena itu:
 *
 *   403/400  autentikasi gagal atau body rusak — mengulang tidak akan
 *            membuatnya sah, tetapi juga tidak merugikan.
 *   200      sudah ditangani, ATAU tidak dikenal sama sekali (tidak ada
 *            yang bisa dilakukan dengan mengulang).
 *   500/503  gagal sementara (API status gateway tidak terjangkau, galat
 *            basis data, kunci belum dikonfigurasi). WAJIB bukan 200:
 *            webhook lama membalas 200 dalam keadaan ini, sehingga gateway
 *            berhenti mengulang dan pembayaran tidak pernah terkonfirmasi.
 *
 * Setiap callback dicatat ke payment_callbacks beserta hasilnya, untuk
 * ditampilkan di monitor panel gateway. Yang disimpan hanya SHA-256 dari
 * body, bukan isinya — body memuat nama, e-mail, dan nomor rekening.
 */

require_once __DIR__ . '/service.php';

/** Titik masuk HTTP: baca permintaan, proses, cetak balasan. */
function payment_handle_callback($code)
{
    header('Content-Type: application/json; charset=utf-8');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['status' => 'error', 'message' => 'Hanya POST.']);
        return;
    }

    $hasil = payment_process_callback($code, (string)file_get_contents('php://input'), $_POST);
    http_response_code($hasil['http']);
    echo json_encode($hasil['body']);
}

/**
 * Proses satu callback. Tidak mencetak apa pun — dapat diuji dari CLI.
 *
 * @return array http, body, outcome
 */
function payment_process_callback($code, $raw, array $post)
{
    $gw = payment_gateway($code);
    if (!$gw) {
        return ['http' => 404, 'outcome' => 'invalid', 'body' => ['status' => 'error', 'message' => 'Gateway tidak dikenal.']];
    }

    $c = $gw->parseCallback($raw, $post);
    $hash = hash('sha256', (string)($c['raw'] ?? ''));

    if (!$c['ok']) {
        $outcome = $c['http'] === 403 ? 'auth_failed' : 'invalid';
        payment_journal($code, null, $c, $outcome, $c['error'], $hash);
        return ['http' => $c['http'], 'outcome' => $outcome, 'body' => ['status' => 'error', 'message' => $c['error']]];
    }

    $txn = payment_callback_find_txn($code, $c);
    if (!$txn) {
        payment_journal($code, null, $c, 'not_found', 'Tidak ada transaksi yang cocok.', $hash);
        error_log("Callback $code: tidak ada transaksi untuk provider_ref={$c['provider_ref']} merchant_ref={$c['merchant_ref']}");
        return ['http' => 200, 'outcome' => 'not_found', 'body' => ['status' => 'ok', 'message' => 'Tidak dikenal, diterima.']];
    }

    $r = payment_recheck($txn, 'callback:' . $code, true);
    payment_journal($code, $txn, $c, $r['outcome'], $r['error'], $hash);

    if ($r['outcome'] === 'error') {
        return ['http' => 500, 'outcome' => 'error', 'body' => ['status' => 'error', 'message' => 'Gagal sementara, silakan ulangi.']];
    }
    return ['http' => 200, 'outcome' => $r['outcome'], 'body' => ['status' => 'ok', 'outcome' => $r['outcome']]];
}

/**
 * Cari transaksi dari data callback.
 *
 * provider_ref dan merchant_ref yang dipakai di sini berasal dari body yang
 * SUDAH diautentikasi, dan hanya dipakai untuk mencari baris di basis data.
 * Yang dikirim ke API gateway kemudian adalah provider_ref milik baris
 * tersimpan, bukan nilai dari body.
 */
function payment_callback_find_txn($code, array $c)
{
    global $pdo;

    $t = payment_txn_by_provider($code, $c['provider_ref'] ?? '');
    if (!$t && !empty($c['merchant_ref'])) {
        $t = payment_txn_by_merchant_ref($c['merchant_ref']);
    }
    if ($t && $t->gateway !== $code) {
        return null;
    }
    if ($t || $code !== 'midtrans') {
        return $t;
    }

    // Order Midtrans yang dibuat kode lama dan belum punya transaksi di
    // ledger: kenali subjeknya dengan aturan resolver webhook lama, buat
    // transaksinya secara malas, lalu cari ulang.
    $order = (string)($c['merchant_ref'] ?? '');
    if (strpos($order, 'LEG-') === 0) {
        $id = null;
        foreach ([
            ["SELECT id FROM legalisir_requests WHERE id = ?", $order],
            ["SELECT id FROM legalisir_requests WHERE midtrans_order_id = ?", $order],
        ] as [$sql, $v]) {
            $q = $pdo->prepare($sql);
            $q->execute([$v]);
            if ($id = $q->fetchColumn()) {
                break;
            }
        }
        if (!$id && preg_match('/^(LEG-.+?)-\d{9,}$/', $order, $m)) {
            $q = $pdo->prepare("SELECT id FROM legalisir_requests WHERE id = ?");
            $q->execute([$m[1]]);
            $id = $q->fetchColumn();
        }
        if ($id) {
            payment_backfill_subject($pdo, 'legalisir', $id);
        }
    } elseif (strpos($order, 'DON-') === 0) {
        $q = $pdo->prepare("SELECT id FROM donations WHERE midtrans_order_id = ?");
        $q->execute([$order]);
        if ($id = $q->fetchColumn()) {
            payment_backfill_subject($pdo, 'donasi', $id);
        }
    }

    $t = payment_txn_by_merchant_ref($order);
    return ($t && $t->gateway === $code) ? $t : null;
}

function payment_journal($code, $txn, array $c, $outcome, $detail, $hash)
{
    global $pdo;
    try {
        $pdo->prepare("INSERT INTO payment_callbacks
                (gateway, transaction_id, merchant_ref, provider_ref, provider_event, provider_status,
                 outcome, detail, payload_sha256, remote_ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $code,
                $txn ? $txn->id : null,
                substr((string)($txn->merchant_ref ?? ($c['merchant_ref'] ?? '')), 0, 64) ?: null,
                substr((string)($c['provider_ref'] ?? ''), 0, 120) ?: null,
                substr((string)($c['event'] ?? ''), 0, 120) ?: null,
                substr((string)($c['status_hint'] ?? ''), 0, 40) ?: null,
                $outcome,
                $detail !== null ? substr((string)$detail, 0, 255) : null,
                $hash,
                substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            ]);
    } catch (Throwable $e) {
        // Jurnal yang gagal ditulis tidak boleh menggagalkan pembayaran.
        error_log('Jurnal callback gagal: ' . $e->getMessage());
    }
}
