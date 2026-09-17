<?php
/**
 * Pemetaan data pembayaran LAMA ke ledger payment_transactions.
 *
 * ── Mengapa satu berkas yang hanya bergantung pada $pdo ─────────────────
 * Pemetaan ini dipakai dua jalur:
 *
 *   1. Migrasi di config/db.php — berjalan SEBELUM includes/settings.php
 *      dimuat, jadi tidak boleh memanggil setting().
 *   2. Pembuatan transaksi secara MALAS di includes/payment/service.php —
 *      untuk permohonan yang dibuat kode lama selama jendela upload FTP,
 *      setelah migrasi berjalan tetapi sebelum handler baru naik.
 *
 * Bila keduanya menulis pemetaannya sendiri, cepat atau lambat keduanya
 * berbeda. Karena itu keduanya memanggil fungsi yang sama di sini.
 *
 * Semua penulisan memakai INSERT IGNORE pada merchant_ref yang UNIQUE,
 * sehingga aman dijalankan berulang.
 */

function payment_backfill_expiry_minutes(PDO $pdo)
{
    try {
        $v = (int)$pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'payment_expiry'")->fetchColumn();
        return $v > 0 ? $v : 1440;
    } catch (PDOException $e) {
        return 1440;
    }
}

/**
 * Waktu terbit token dari order_id. Regenerasi lama memakai
 * "<id>-<unix time>", donasi "DON-<unix time>-<acak>".
 */
function payment_backfill_issued_at($order_id, $fallback)
{
    if (preg_match('/-(\d{9,11})(?:-\d+)?$/', (string)$order_id, $m)) {
        return (int)$m[1];
    }
    $t = strtotime((string)$fallback);
    return $t ?: time();
}

function payment_backfill_insert(PDO $pdo, array $f)
{
    $kolom = array_keys($f);
    $sql = 'INSERT IGNORE INTO payment_transactions (' . implode(', ', $kolom) . ') VALUES ('
         . implode(', ', array_fill(0, count($kolom), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($f));
}

/** Satu baris legalisir_requests -> satu atau dua transaksi. */
function payment_backfill_legalisir(PDO $pdo, $row, $expiry_minutes)
{
    $id      = (string)$row->id;
    $order   = trim((string)($row->midtrans_order_id ?? ''));
    $token   = trim((string)($row->midtrans_snap_token ?? ''));
    $metode  = (string)($row->payment_method ?? '');
    $amount  = $row->amount !== null ? (float)$row->amount : null;
    $dibuat  = (string)$row->created_at;

    if (($row->payment_status ?? '') === 'settlement') {
        if ($metode === 'cash') {
            payment_backfill_insert($pdo, [
                'gateway' => 'cash', 'purpose' => 'legalisir', 'subject_id' => $id,
                'merchant_ref' => 'CASH-' . $id, 'amount_expected' => $amount,
                'status' => 'paid', 'channel' => 'cash',
                'paid_at' => $row->updated_at ?: $dibuat, 'created_at' => $dibuat,
            ]);
            // Token Midtrans yang ditinggalkan dicatat sebagai 'cancelled',
            // supaya notifikasi yang terlambat untuk order_id itu DIKENALI
            // dan diabaikan — bukan memundurkan permohonan yang sudah lunas
            // tunai, seperti yang dilakukan webhook lama.
            if ($order !== '') {
                payment_backfill_insert($pdo, [
                    'gateway' => 'midtrans', 'purpose' => 'legalisir', 'subject_id' => $id,
                    'merchant_ref' => $order, 'provider_ref' => $order,
                    'snap_token' => $token !== '' ? $token : null,
                    'amount_expected' => $amount, 'status' => 'cancelled', 'created_at' => $dibuat,
                ]);
            }
            return;
        }
        $gw = in_array($metode, ['midtrans', 'flip'], true) ? $metode : 'midtrans';
        $ref = $order !== '' ? $order : $id;
        payment_backfill_insert($pdo, [
            'gateway' => $gw, 'purpose' => 'legalisir', 'subject_id' => $id,
            'merchant_ref' => $ref, 'provider_ref' => $ref,
            'snap_token' => $token !== '' ? $token : null,
            'amount_expected' => $amount, 'status' => 'paid',
            'channel' => in_array($metode, ['midtrans', 'flip', ''], true) ? null : $metode,
            'paid_at' => $row->updated_at ?: $dibuat, 'created_at' => $dibuat,
        ]);
        return;
    }

    // Belum lunas.
    $ref = $order !== '' ? $order : $id;
    if ($order === '' && $token === '') {
        $status = 'create_failed';
    } elseif (($row->payment_status ?? '') === 'failed') {
        $status = 'failed';
    } else {
        $status = 'pending';
    }
    $terbit = payment_backfill_issued_at($order, $dibuat);
    payment_backfill_insert($pdo, [
        'gateway' => 'midtrans', 'purpose' => 'legalisir', 'subject_id' => $id,
        'merchant_ref' => $ref, 'provider_ref' => $status === 'create_failed' ? null : $ref,
        'snap_token' => $token !== '' ? $token : null,
        'amount_expected' => $amount, 'status' => $status,
        'expires_at' => date('Y-m-d H:i:s', $terbit + $expiry_minutes * 60),
        'created_at' => $dibuat,
    ]);
}

/** Satu baris donations -> satu transaksi. */
function payment_backfill_donation(PDO $pdo, $row)
{
    $id    = (string)$row->id;
    $order = trim((string)($row->midtrans_order_id ?? ''));
    $st    = (string)($row->status ?? 'pending');
    $status = in_array($st, ['success', 'completed'], true) ? 'paid'
            : ($st === 'failed' ? 'failed' : 'pending');

    // donations.amount menyimpan nominal POKOK, sedangkan yang dibayar
    // adalah pokok + biaya. Nominal tagihan donasi lama tidak pernah
    // disimpan, jadi amount_expected dibiarkan NULL — pencocokan nominal
    // dilewati untuk baris lama, bukan diisi angka yang pasti salah.
    $terbit = payment_backfill_issued_at($order, $row->created_at);
    payment_backfill_insert($pdo, [
        'gateway' => 'midtrans', 'purpose' => 'donasi', 'subject_id' => $id,
        'merchant_ref' => $order !== '' ? $order : 'DON-LAMA-' . $id,
        'provider_ref' => $order !== '' ? $order : null,
        'amount_expected' => null,
        'status' => $order === '' && $status === 'pending' ? 'create_failed' : $status,
        'paid_at' => $status === 'paid' ? $row->created_at : null,
        'expires_at' => date('Y-m-d H:i:s', $terbit + 1440 * 60),
        'created_at' => $row->created_at,
    ]);
}

/** Petakan satu subjek bila BELUM punya transaksi apa pun. */
function payment_backfill_subject(PDO $pdo, $purpose, $subject_id)
{
    $ada = $pdo->prepare("SELECT 1 FROM payment_transactions WHERE purpose = ? AND subject_id = ? LIMIT 1");
    $ada->execute([$purpose, (string)$subject_id]);
    if ($ada->fetchColumn()) {
        return;
    }
    if ($purpose === 'legalisir') {
        $q = $pdo->prepare("SELECT id, amount, payment_status, payment_method, midtrans_order_id,
                                   midtrans_snap_token, created_at, updated_at
                              FROM legalisir_requests WHERE id = ?");
        $q->execute([(string)$subject_id]);
        if ($row = $q->fetch(PDO::FETCH_OBJ)) {
            payment_backfill_legalisir($pdo, $row, payment_backfill_expiry_minutes($pdo));
        }
    } elseif ($purpose === 'donasi') {
        $q = $pdo->prepare("SELECT id, amount, status, midtrans_order_id, created_at FROM donations WHERE id = ?");
        $q->execute([(int)$subject_id]);
        if ($row = $q->fetch(PDO::FETCH_OBJ)) {
            payment_backfill_donation($pdo, $row);
        }
    }
}

/**
 * Petakan seluruh data lama. Hanya subjek yang BELUM punya transaksi yang
 * disentuh, jadi menjalankannya dua kali tidak menggandakan apa pun.
 *
 * @return array{legalisir:int, donasi:int}
 */
function payment_backfill_all(PDO $pdo)
{
    $menit = payment_backfill_expiry_minutes($pdo);
    $n = ['legalisir' => 0, 'donasi' => 0];

    $rows = $pdo->query("SELECT lr.id, lr.amount, lr.payment_status, lr.payment_method, lr.midtrans_order_id,
                                lr.midtrans_snap_token, lr.created_at, lr.updated_at
                           FROM legalisir_requests lr
                          WHERE NOT EXISTS (SELECT 1 FROM payment_transactions pt
                                             WHERE pt.purpose = 'legalisir' AND pt.subject_id = lr.id)")
                ->fetchAll(PDO::FETCH_OBJ);
    foreach ($rows as $r) {
        payment_backfill_legalisir($pdo, $r, $menit);
        $n['legalisir']++;
    }

    $rows = $pdo->query("SELECT d.id, d.amount, d.status, d.midtrans_order_id, d.created_at
                           FROM donations d
                          WHERE NOT EXISTS (SELECT 1 FROM payment_transactions pt
                                             WHERE pt.purpose = 'donasi' AND pt.subject_id = CAST(d.id AS CHAR))")
                ->fetchAll(PDO::FETCH_OBJ);
    foreach ($rows as $r) {
        payment_backfill_donation($pdo, $r);
        $n['donasi']++;
    }
    return $n;
}
