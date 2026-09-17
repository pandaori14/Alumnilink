<?php
/**
 * Layanan pembayaran: satu-satunya jalur yang MENGUBAH status pembayaran.
 *
 * ── Aturan yang dipegang berkas ini ────────────────────────────────────
 * 1. Semua perubahan status melewati payment_apply_status(): callback
 *    gateway, halaman kembali, cek ulang manual, cron rekonsiliasi, dan
 *    verifikasi tunai. Tidak ada jalur lain yang menulis status.
 *
 * 2. Status hanya MAJU.
 *      pending|create_failed -> paid | failed | expired | cancelled
 *      failed|expired|cancelled -> paid      (uang yang masuk selalu menang)
 *      paid -> refunded
 *    Status 'paid' tidak pernah kembali ke pending. Webhook lama menulis
 *    status='pending' untuk setiap notifikasi non-settlement, sehingga
 *    notifikasi 'expire' dari token yang ditinggalkan memundurkan
 *    permohonan yang sudah dibayar TUNAI.
 *
 * 3. Idempotensi lewat kunci baris (SELECT ... FOR UPDATE) dan transisi maju,
 *    BUKAN lewat tabel penanda. Webhook lama menulis penanda SEBELUM
 *    pemrosesan: bila pemrosesan gagal, retry dari gateway dibalas "sudah
 *    diproses" dan pembayarannya tidak pernah terkonfirmasi.
 *
 * 4. Efek samping (notifikasi, e-mail) terjadi tepat sekali — saat transisi
 *    ke 'paid' — dan SETELAH commit.
 *
 * 5. Kolom lama (legalisir_requests.payment_status/payment_method/midtrans_*,
 *    donations.status/midtrans_order_id) tetap disinkronkan, sehingga setiap
 *    halaman dan laporan yang membacanya tetap benar.
 */

require_once __DIR__ . '/gateway.php';
require_once __DIR__ . '/fee.php';
require_once __DIR__ . '/backfill.php';

const PAYMENT_NONPAID_FINAL = ['failed', 'expired', 'cancelled'];

// ─────────────────────────────────────────────────────────────────────
// Pembacaan
// ─────────────────────────────────────────────────────────────────────

function payment_txn_get($id)
{
    global $pdo;
    $q = $pdo->prepare("SELECT * FROM payment_transactions WHERE id = ?");
    $q->execute([(int)$id]);
    return $q->fetch(PDO::FETCH_OBJ) ?: null;
}

function payment_txn_by_merchant_ref($ref)
{
    global $pdo;
    if ((string)$ref === '') {
        return null;
    }
    $q = $pdo->prepare("SELECT * FROM payment_transactions WHERE merchant_ref = ?");
    $q->execute([(string)$ref]);
    return $q->fetch(PDO::FETCH_OBJ) ?: null;
}

function payment_txn_by_provider($gateway, $provider_ref)
{
    global $pdo;
    if ((string)$provider_ref === '') {
        return null;
    }
    $q = $pdo->prepare("SELECT * FROM payment_transactions WHERE gateway = ? AND provider_ref = ?
                         ORDER BY id DESC LIMIT 1");
    $q->execute([(string)$gateway, (string)$provider_ref]);
    return $q->fetch(PDO::FETCH_OBJ) ?: null;
}

/** Seluruh transaksi satu subjek, terbaru lebih dulu. */
function payment_txns_for_subject($purpose, $subject_id)
{
    global $pdo;
    payment_backfill_subject($pdo, $purpose, $subject_id);
    $q = $pdo->prepare("SELECT * FROM payment_transactions WHERE purpose = ? AND subject_id = ? ORDER BY id DESC");
    $q->execute([(string)$purpose, (string)$subject_id]);
    return $q->fetchAll(PDO::FETCH_OBJ);
}

/**
 * Transaksi terbaru satu subjek.
 *
 * Bila belum ada, dibuat secara malas dari kolom lama. Itu menutup celah
 * jendela upload FTP: permohonan yang dibuat kode LAMA setelah migrasi
 * berjalan tidak ikut ter-backfill.
 */
function payment_txn_latest($purpose, $subject_id)
{
    $semua = payment_txns_for_subject($purpose, $subject_id);
    return $semua[0] ?? null;
}

/** Transaksi lunas satu subjek (terbaru), atau null. */
function payment_txn_paid($purpose, $subject_id)
{
    foreach (payment_txns_for_subject($purpose, $subject_id) as $t) {
        if ($t->status === 'paid') {
            return $t;
        }
    }
    return null;
}

/** Transaksi yang masih dapat dibayar, atau null. */
function payment_txn_payable($purpose, $subject_id)
{
    $t = payment_txn_latest($purpose, $subject_id);
    return ($t && $t->status === 'pending' && $t->gateway !== 'cash') ? $t : null;
}

// ─────────────────────────────────────────────────────────────────────
// Pembuatan tagihan
// ─────────────────────────────────────────────────────────────────────

/** Alamat kembali setelah pembayaran di halaman gateway. */
function payment_return_url($merchant_ref)
{
    return rtrim(BASE_URL, '/') . '/handlers/payment_return.php?ref=' . rawurlencode($merchant_ref);
}

/**
 * Catat transaksi baru berstatus pending, lalu minta tagihan ke gateway.
 *
 * Urutan ini disengaja. Handler lama meminta token ke Midtrans LEBIH DULU,
 * baru menyimpan permohonan: bila INSERT gagal, transaksi gateway sudah
 * terbit tanpa catatan apa pun di sistem.
 *
 * @param array $quote    hasil payment_quote()
 * @param array $customer name, email, phone, address
 * @return array ok, error, txn
 */
function payment_create($purpose, $subject_id, $merchant_ref, array $quote, array $customer, $created_by = null)
{
    global $pdo;

    $gw = payment_gateway($quote['gateway']);
    if (!$gw) {
        return ['ok' => false, 'error' => 'Gateway tidak dikenal.', 'txn' => null];
    }

    $pdo->prepare("INSERT INTO payment_transactions
            (gateway, purpose, subject_id, merchant_ref, amount_expected, fee_breakdown, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)")
        ->execute([
            $gw->code(), $purpose, (string)$subject_id, $merchant_ref, $quote['total'],
            json_encode(payment_breakdown_snapshot($quote), JSON_UNESCAPED_UNICODE), $created_by,
        ]);
    $txn = payment_txn_get($pdo->lastInsertId());

    return payment_charge($txn, $quote['items'] ?? [], $customer);
}

/** Rincian yang disimpan per transaksi — tidak dihitung ulang dari setting saat ini. */
function payment_breakdown_snapshot(array $quote)
{
    return [
        'purpose'       => $quote['purpose'] ?? null,
        'gateway'       => $quote['gateway'] ?? null,
        'doc_count'     => $quote['doc_count'] ?? 0,
        'price_per_doc' => $quote['price_per_doc'] ?? 0,
        'documents'     => $quote['documents'] ?? 0,
        'shipping'      => $quote['shipping'] ?? 0,
        'base'          => $quote['base'] ?? 0,
        'custom'        => $quote['custom'] ?? 0,
        'fee'           => $quote['fee'] ?? 0,
        'admin_total'   => $quote['admin_total'] ?? 0,
        'total'         => $quote['total'] ?? 0,
        'items'         => $quote['items'] ?? [],
        'profile'       => $quote['profile'] ?? null,
    ];
}

/** Minta tagihan ke gateway untuk transaksi yang sudah tercatat. */
function payment_charge($txn, array $items, array $customer)
{
    global $pdo;

    $gw = payment_gateway($txn->gateway);
    $hasil = $gw->createCharge([
        'merchant_ref'     => $txn->merchant_ref,
        'amount'           => (int)round((float)$txn->amount_expected),
        'items'            => $items,
        'customer_name'    => $customer['name'] ?? '',
        'customer_email'   => $customer['email'] ?? '',
        'customer_phone'   => $customer['phone'] ?? '',
        'customer_address' => $customer['address'] ?? '',
        'expiry_minutes'   => setting_int('payment_expiry', 1440, 15),
        'return_url'       => payment_return_url($txn->merchant_ref),
    ]);

    if ($hasil['ok']) {
        $pdo->prepare("UPDATE payment_transactions
                          SET provider_ref = ?, snap_token = ?, pay_url = ?, expires_at = ?, last_error = NULL
                        WHERE id = ?")
            ->execute([$hasil['provider_ref'], $hasil['snap_token'] ?? null, $hasil['pay_url'] ?? null,
                       $hasil['expires_at'] ?? null, $txn->id]);
    } else {
        error_log("Pembayaran: gagal membuat tagihan {$txn->merchant_ref} di {$txn->gateway}: {$hasil['error']}");
        $pdo->prepare("UPDATE payment_transactions SET status = 'create_failed', last_error = ? WHERE id = ?")
            ->execute([substr((string)$hasil['error'], 0, 255), $txn->id]);
    }

    $txn = payment_txn_get($txn->id);
    payment_sync_legacy($txn->purpose, $txn->subject_id);
    return ['ok' => (bool)$hasil['ok'], 'error' => $hasil['error'] ?? null, 'txn' => $txn];
}

/**
 * Terbitkan ulang tagihan untuk subjek yang belum lunas.
 *
 * Memakai gateway AKTIF saat ini dengan nominal yang SAMA dengan tagihan
 * sebelumnya — alumni tidak boleh ditagih berbeda hanya karena gateway
 * dipindah atau profil biaya diubah setelah tagihannya terbit.
 */
function payment_regenerate($purpose, $subject_id, array $customer, $created_by = null)
{
    global $pdo;

    if ($lunas = payment_txn_paid($purpose, $subject_id)) {
        return ['ok' => false, 'error' => 'Tagihan ini sudah lunas via ' . payment_gateway_label($lunas->gateway) . '.', 'txn' => $lunas];
    }

    $lama = payment_txn_latest($purpose, $subject_id);
    $nominal = $lama ? (float)$lama->amount_expected : 0;
    $items = [];
    if ($lama && $lama->fee_breakdown) {
        $rincian = json_decode($lama->fee_breakdown, true);
        $items = $rincian['items'] ?? [];
    }
    if ($nominal <= 0 && $purpose === 'legalisir') {
        $q = $pdo->prepare("SELECT amount FROM legalisir_requests WHERE id = ?");
        $q->execute([(string)$subject_id]);
        $nominal = (float)$q->fetchColumn();
    }
    if ($nominal <= 0) {
        return ['ok' => false, 'error' => 'Nominal tagihan sebelumnya tidak diketahui.', 'txn' => $lama];
    }
    if (!$items) {
        $items = [['id' => 'TAGIHAN', 'name' => 'Tagihan', 'price' => (int)round($nominal), 'quantity' => 1]];
    }

    // Tagihan lama yang masih terbuka dinonaktifkan lebih dulu, supaya
    // alumni tidak dapat membayar dua tagihan untuk permohonan yang sama.
    foreach (payment_txns_for_subject($purpose, $subject_id) as $t) {
        if (in_array($t->status, ['pending', 'create_failed'], true)) {
            if ($t->provider_ref && ($g = payment_gateway($t->gateway))) {
                $g->deactivate($t->provider_ref);
            }
            payment_apply_status($t->id, 'cancelled', [], 'regenerate');
        }
    }

    $gw = payment_active_gateway();
    $ref = payment_unique_ref($subject_id . '-' . time());
    $snapshot = $lama && $lama->fee_breakdown ? $lama->fee_breakdown
              : json_encode(['total' => $nominal, 'items' => $items], JSON_UNESCAPED_UNICODE);

    $pdo->prepare("INSERT INTO payment_transactions
            (gateway, purpose, subject_id, merchant_ref, amount_expected, fee_breakdown, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)")
        ->execute([$gw->code(), $purpose, (string)$subject_id, $ref, $nominal, $snapshot, $created_by]);

    return payment_charge(payment_txn_get($pdo->lastInsertId()), $items, $customer);
}

/** merchant_ref yang belum terpakai (klik ganda dalam detik yang sama). */
function payment_unique_ref($ref)
{
    $calon = $ref;
    for ($i = 0; payment_txn_by_merchant_ref($calon) && $i < 20; $i++) {
        $calon = $ref . '-' . random_int(10, 99);
    }
    return substr($calon, 0, 50);
}

// ─────────────────────────────────────────────────────────────────────
// Status
// ─────────────────────────────────────────────────────────────────────

/**
 * Ambil ulang status dari gateway, lalu terapkan.
 *
 * @param bool $paksa false = lewati bila baru dicek < 15 detik lalu
 *                    (halaman kembali yang dimuat ulang berkali-kali tidak
 *                    boleh membanjiri API gateway)
 * @return array outcome, status, changed, error
 */
function payment_recheck($txn, $source, $paksa = false)
{
    global $pdo;

    if (!$txn) {
        return ['outcome' => 'not_found', 'status' => null, 'changed' => false, 'error' => 'Transaksi tidak ada.'];
    }
    // Tunai tidak punya gateway untuk ditanya. Transaksi yang sudah final
    // hanya dicek ulang bila dipaksa (callback), untuk menangkap refund.
    if ($txn->gateway === 'cash' || (in_array($txn->status, ['paid', 'refunded'], true) && !$paksa)) {
        return ['outcome' => 'no_change', 'status' => $txn->status, 'changed' => false, 'error' => null];
    }
    if (empty($txn->provider_ref)) {
        return ['outcome' => 'no_change', 'status' => $txn->status, 'changed' => false, 'error' => null];
    }
    if (!$paksa && $txn->last_checked_at && strtotime($txn->last_checked_at) > time() - 15) {
        return ['outcome' => 'throttled', 'status' => $txn->status, 'changed' => false, 'error' => null];
    }

    $gw = payment_gateway($txn->gateway);
    if (!$gw) {
        return ['outcome' => 'error', 'status' => $txn->status, 'changed' => false, 'error' => 'Gateway tidak dikenal.'];
    }

    $pdo->prepare("UPDATE payment_transactions SET last_checked_at = NOW() WHERE id = ?")->execute([$txn->id]);
    $r = $gw->fetchStatus($txn->provider_ref);
    if (!$r['ok'] || $r['status'] === 'unknown') {
        return ['outcome' => 'error', 'status' => $txn->status, 'changed' => false, 'error' => $r['error'] ?? 'Status tidak dikenal.'];
    }

    $baru = $r['status'];
    // Kedaluwarsa lokal: gateway belum pernah melihat pembayaran apa pun
    // untuk tagihan ini, dan masa berlakunya sudah lewat 30 menit. Aman,
    // karena bila uang ternyata masuk belakangan, 'paid' tetap menang.
    if ($baru === 'pending' && !empty($r['not_found']) && $txn->expires_at
        && strtotime($txn->expires_at) < time() - 1800) {
        $baru = 'expired';
    }

    return payment_apply_status($txn->id, $baru, $r, $source);
}

/**
 * Terapkan status baru pada satu transaksi. SATU-SATUNYA penulis status.
 *
 * @param array $info amount, channel, paid_at dari gateway
 * @return array outcome (applied|no_change|ignored_regression|amount_mismatch|error),
 *               status, changed, error
 */
function payment_apply_status($txn_id, $baru, array $info, $source)
{
    global $pdo;

    $dikenal = ['pending', 'paid', 'failed', 'expired', 'cancelled', 'refunded'];
    if (!in_array($baru, $dikenal, true)) {
        return ['outcome' => 'error', 'status' => null, 'changed' => false, 'error' => "Status '$baru' tidak dikenal."];
    }

    $flag_ganda = false;
    try {
        $pdo->beginTransaction();
        $q = $pdo->prepare("SELECT * FROM payment_transactions WHERE id = ? FOR UPDATE");
        $q->execute([(int)$txn_id]);
        $t = $q->fetch(PDO::FETCH_OBJ);
        if (!$t) {
            $pdo->rollBack();
            return ['outcome' => 'not_found', 'status' => null, 'changed' => false, 'error' => 'Transaksi tidak ada.'];
        }

        $lama = $t->status;
        $outcome = payment_transition_outcome($lama, $baru);

        if ($outcome === 'applied' && $baru === 'paid' && $t->amount_expected !== null
            && isset($info['amount']) && $info['amount'] !== null
            && abs((float)$info['amount'] - (float)$t->amount_expected) >= 0.01) {
            $outcome = 'amount_mismatch';
            error_log(sprintf('Pembayaran %s: nominal TIDAK COCOK, tercatat %s, gateway %s. Tidak ditandai lunas.',
                $t->merchant_ref, $t->amount_expected, $info['amount']));
        }

        if ($outcome !== 'applied') {
            $pdo->commit();
            return ['outcome' => $outcome, 'status' => $lama, 'changed' => false, 'error' => null];
        }

        $flag = $t->flag;
        if ($baru === 'paid') {
            $lain = $pdo->prepare("SELECT COUNT(*) FROM payment_transactions
                                    WHERE purpose = ? AND subject_id = ? AND status = 'paid' AND id <> ?");
            $lain->execute([$t->purpose, $t->subject_id, $t->id]);
            if ((int)$lain->fetchColumn() > 0) {
                $flag = 'double_payment';
                $flag_ganda = true;
            }
        }

        $pdo->prepare("UPDATE payment_transactions
                          SET status = ?,
                              channel = COALESCE(?, channel),
                              paid_at = CASE WHEN ? = 'paid' THEN COALESCE(paid_at, ?, NOW()) ELSE paid_at END,
                              flag = ?
                        WHERE id = ?")
            ->execute([$baru, $info['channel'] ?? null, $baru, payment_mysql_time($info['paid_at'] ?? null), $flag, $t->id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Pembayaran: gagal menerapkan status: ' . $e->getMessage());
        return ['outcome' => 'error', 'status' => null, 'changed' => false, 'error' => 'Galat basis data.'];
    }

    // ── Setelah commit ────────────────────────────────────────────────
    $t = payment_txn_get($txn_id);
    try {
        payment_sync_legacy($t->purpose, $t->subject_id);
        if ($baru === 'paid' && !$flag_ganda) {
            payment_after_paid($t, $source);
        }
        if ($flag_ganda) {
            notify_roles(['super_admin'], 'Pembayaran GANDA terdeteksi',
                sprintf('%s %s dibayar lebih dari sekali (terakhir via %s, Rp %s). Periksa dan lakukan refund manual.',
                    ucfirst($t->purpose), $t->subject_id, payment_gateway_label($t->gateway),
                    number_format((float)$t->amount_expected, 0, ',', '.')),
                'error', 'index.php?page=admin_payment_gateway');
        }
    } catch (Throwable $e) {
        // Status sudah tersimpan. Efek samping yang gagal dicatat, tetapi
        // tidak boleh membatalkan fakta bahwa uangnya sudah diterima.
        error_log('Pembayaran: efek samping gagal untuk ' . $t->merchant_ref . ': ' . $e->getMessage());
    }

    return ['outcome' => 'applied', 'status' => $baru, 'changed' => true, 'error' => null];
}

/** Aturan transisi. Murni — tanpa basis data — supaya mudah diuji. */
function payment_transition_outcome($lama, $baru)
{
    if ($lama === $baru) {
        return 'no_change';
    }
    if ($lama === 'refunded') {
        return 'ignored_regression';
    }
    if ($lama === 'paid') {
        return $baru === 'refunded' ? 'applied' : 'ignored_regression';
    }
    if ($baru === 'paid') {
        return 'applied';
    }
    if ($baru === 'pending' || $baru === 'refunded') {
        return 'ignored_regression';
    }
    // $baru adalah failed|expired|cancelled, $lama belum lunas.
    return 'applied';
}

/** Waktu dari gateway (mis. "2026-09-17 10:00:00" atau ISO) -> format MySQL. */
function payment_mysql_time($t)
{
    if (!$t) {
        return null;
    }
    $ts = strtotime((string)$t);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

// ─────────────────────────────────────────────────────────────────────
// Sinkronisasi kolom lama
// ─────────────────────────────────────────────────────────────────────

/**
 * Tulis ringkasan ledger ke kolom lama.
 *
 * payment_method HANYA berisi kode gateway (midtrans|flip|cash), bukan enum
 * kanal Midtrans. Webhook lama menulis 'bank_transfer' dan sejenisnya,
 * padahal laporan menjumlah payment_method = 'midtrans'.
 */
function payment_sync_legacy($purpose, $subject_id)
{
    global $pdo;

    $q = $pdo->prepare("SELECT * FROM payment_transactions WHERE purpose = ? AND subject_id = ? ORDER BY id DESC");
    $q->execute([(string)$purpose, (string)$subject_id]);
    $semua = $q->fetchAll(PDO::FETCH_OBJ);
    if (!$semua) {
        return;
    }

    $lunas = null;
    foreach ($semua as $t) {
        if ($t->status === 'paid') {
            $lunas = $t;
            break;
        }
    }
    $terbaru = $semua[0];

    if ($purpose === 'legalisir') {
        if ($lunas) {
            // Hanya 'pending' yang maju ke 'processing'. Webhook lama menimpa
            // status apa pun, termasuk 'completed' dan 'rejected'.
            $pdo->prepare("UPDATE legalisir_requests
                              SET payment_status = 'settlement', payment_method = ?,
                                  status = CASE WHEN status = 'pending' THEN 'processing' ELSE status END
                            WHERE id = ?")
                ->execute([$lunas->gateway, (string)$subject_id]);
        } else {
            $ps = $terbaru->status === 'pending' ? 'pending' : 'failed';
            $pdo->prepare("UPDATE legalisir_requests SET payment_status = ?, payment_method = ?
                            WHERE id = ? AND (payment_status IS NULL OR payment_status <> 'settlement')")
                ->execute([$ps, $terbaru->gateway, (string)$subject_id]);
        }
        if ($terbaru->gateway === 'midtrans') {
            $pdo->prepare("UPDATE legalisir_requests SET midtrans_order_id = ?, midtrans_snap_token = ? WHERE id = ?")
                ->execute([$terbaru->merchant_ref, $terbaru->snap_token, (string)$subject_id]);
        }
    } elseif ($purpose === 'donasi') {
        if ($lunas) {
            $pdo->prepare("UPDATE donations SET status = 'success', midtrans_order_id = ? WHERE id = ?")
                ->execute([$lunas->merchant_ref, (int)$subject_id]);
        } else {
            $st = $terbaru->status === 'pending' ? 'pending' : 'failed';
            $pdo->prepare("UPDATE donations SET status = ?, midtrans_order_id = ?
                            WHERE id = ? AND status NOT IN ('success', 'completed')")
                ->execute([$st, $terbaru->merchant_ref, (int)$subject_id]);
        }
    }
}

// ─────────────────────────────────────────────────────────────────────
// Efek samping lunas
// ─────────────────────────────────────────────────────────────────────

function payment_after_paid($t, $source)
{
    global $pdo;
    require_once __DIR__ . '/../mailer.php';

    $nominal = 'Rp ' . number_format((float)$t->amount_expected, 0, ',', '.');
    $via = $t->gateway === 'cash' ? 'tunai' : payment_gateway_label($t->gateway)
         . ($t->channel ? ' (' . payment_channel_label($t->channel) . ')' : '');

    if ($t->purpose === 'legalisir') {
        $q = $pdo->prepare("SELECT lr.user_id, lr.amount, lr.status, lr.documents, u.email, u.name
                              FROM legalisir_requests lr LEFT JOIN users u ON u.id = lr.user_id
                             WHERE lr.id = ?");
        $q->execute([$t->subject_id]);
        $r = $q->fetch(PDO::FETCH_OBJ);
        if (!$r) {
            return;
        }
        $docs = json_decode((string)$r->documents, true) ?: [];
        $jenis = !empty($docs[0]['type']) ? $docs[0]['type'] : 'Dokumen';
        $desk = $jenis . (count($docs) > 1 ? ' (' . count($docs) . ' berkas)' : '');
        if ($t->amount_expected === null) {
            $nominal = 'Rp ' . number_format((float)$r->amount, 0, ',', '.');
        }

        if ($t->gateway === 'cash') {
            add_notification($r->user_id, 'Pembayaran Tunai Terverifikasi',
                "Pembayaran tunai sebesar $nominal untuk pengajuan $desk ({$t->subject_id}) telah diverifikasi admin. Pengajuan Anda sedang diproses.",
                'success', 'index.php?page=legalisir_detail&id=' . rawurlencode($t->subject_id));
            notify_roles(['super_admin', 'admin_legalisir'], 'Pembayaran Tunai Terverifikasi',
                "Pembayaran tunai legalisir {$t->subject_id} sebesar $nominal telah diverifikasi.",
                'success', 'index.php?page=admin_legalisir');
            return;
        }

        add_notification($r->user_id, 'Pembayaran Legalisir Sukses',
            "Pembayaran sebesar $nominal untuk pengajuan $desk ({$t->subject_id}) telah lunas via $via. Dokumen Anda sedang diproses admin.",
            'success', 'index.php?page=legalisir_detail&id=' . rawurlencode($t->subject_id));

        $catatan = $r->status === 'rejected'
            ? ' PERHATIAN: pengajuan ini sudah DITOLAK sebelum pembayaran masuk — pertimbangkan refund.'
            : '';
        notify_roles(['super_admin', 'admin_legalisir'], 'Pembayaran Legalisir Sukses',
            "Pembayaran legalisir {$t->subject_id} sebesar $nominal telah lunas via $via.$catatan",
            $catatan ? 'error' : 'success', 'index.php?page=admin_legalisir');

        if (!empty($r->email)) {
            send_legalisir_payment_email($r->email, $r->name, $t->subject_id, (float)($t->amount_expected ?? $r->amount), $via);
        }
        return;
    }

    if ($t->purpose === 'donasi') {
        $q = $pdo->prepare("SELECT d.user_id, d.donor_name, d.amount, c.title, u.email
                              FROM donations d
                              LEFT JOIN donation_campaigns c ON c.id = d.campaign_id
                              LEFT JOIN users u ON u.id = d.user_id
                             WHERE d.id = ?");
        $q->execute([(int)$t->subject_id]);
        $d = $q->fetch(PDO::FETCH_OBJ);
        if (!$d) {
            return;
        }
        $pokok = 'Rp ' . number_format((float)$d->amount, 0, ',', '.');
        $judul = $d->title ?: 'Program Umum';
        if ($d->user_id) {
            add_notification($d->user_id, 'Donasi Berhasil Diterima',
                "Terima kasih! Donasi Anda sebesar $pokok untuk program \"$judul\" telah diterima.",
                'success', 'index.php?page=donasi');
        }
        notify_roles(['super_admin', 'keuangan'], 'Donasi Sukses Diterima',
            "Donasi dari {$d->donor_name} sebesar $pokok untuk program \"$judul\" telah diterima via $via.",
            'success', 'index.php?page=admin_donasi');
        if (!empty($d->email)) {
            send_donation_receipt($d->email, $d->donor_name, $t->merchant_ref, (float)$d->amount, $judul);
        }
    }
}

// ─────────────────────────────────────────────────────────────────────
// Tunai
// ─────────────────────────────────────────────────────────────────────

/**
 * Tandai legalisir lunas tunai.
 *
 * Menolak bila sudah lunas. Verifikasi tunai lama menimpa payment_method
 * menjadi 'cash' tanpa syarat, termasuk atas permohonan yang sudah dibayar
 * online — pendapatan gateway lalu berpindah ke kolom tunai di laporan.
 */
function payment_verify_cash($subject_id, $by)
{
    global $pdo;

    $q = $pdo->prepare("SELECT id, amount FROM legalisir_requests WHERE id = ?");
    $q->execute([(string)$subject_id]);
    $req = $q->fetch(PDO::FETCH_OBJ);
    if (!$req) {
        return ['ok' => false, 'error' => 'Pengajuan tidak ditemukan.'];
    }
    if ($lunas = payment_txn_paid('legalisir', $req->id)) {
        return ['ok' => false, 'error' => 'Pengajuan ini sudah lunas via ' . payment_gateway_label($lunas->gateway) . '. Verifikasi tunai tidak diperlukan.'];
    }

    // Tagihan online yang masih terbuka ditutup, supaya tidak ada yang
    // membayar lagi setelah uang tunai diterima.
    foreach (payment_txns_for_subject('legalisir', $req->id) as $t) {
        if (in_array($t->status, ['pending', 'create_failed'], true)) {
            if ($t->provider_ref && ($g = payment_gateway($t->gateway))) {
                $g->deactivate($t->provider_ref);
            }
            payment_apply_status($t->id, 'cancelled', [], 'cash');
        }
    }

    $pdo->prepare("INSERT INTO payment_transactions
            (gateway, purpose, subject_id, merchant_ref, amount_expected, status, channel, created_by)
         VALUES ('cash', 'legalisir', ?, ?, ?, 'pending', 'cash', ?)")
        ->execute([$req->id, payment_unique_ref('CASH-' . $req->id), $req->amount, $by]);
    $hasil = payment_apply_status($pdo->lastInsertId(), 'paid', ['amount' => (float)$req->amount, 'channel' => 'cash'], 'cash:' . $by);

    return ['ok' => $hasil['outcome'] === 'applied', 'error' => $hasil['error']];
}
