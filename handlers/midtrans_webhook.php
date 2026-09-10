<?php
require_once '../config/db.php';
require_once '../includes/mailer.php';

// Set response header to JSON
header('Content-Type: application/json');

// 1. Fetch Midtrans Server Key from database
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'midtrans_server_key'");
$stmt->execute();
$server_key = $stmt->fetchColumn();

if (!$server_key) {
    http_response_code(500);
    die(json_encode(['error' => 'Server key not configured']));
}

// 2. Read Notification body from Midtrans
$json = file_get_contents('php://input');
$notification = json_decode($json);

if (!$notification) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid notification data']));
}

// Extract relevant fields
$order_id           = $notification->order_id;
$status_code        = $notification->status_code;
$gross_amount       = $notification->gross_amount;
$transaction_status = $notification->transaction_status;
$signature_key      = $notification->signature_key;
$payment_type       = $notification->payment_type;

// 3. Verify Signature for security (Prevent spoofing)
$local_signature = hash("sha512", $order_id . $status_code . $gross_amount . $server_key);

if ($signature_key !== $local_signature) {
    http_response_code(403);
    die(json_encode(['error' => 'Invalid signature']));
}

// 3b. IDEMPOTENSI
//
// Midtrans mengirim ulang notifikasi bila tidak menerima balasan 200. Tanpa
// penanda, notifikasi sah yang terkirim ulang akan menjalankan ulang SELURUH
// efek samping: notifikasi in-app dan e-mail ke alumni maupun admin.
//
// Kunci unik (order_id, transaction_status) membuat transisi wajar
// 'pending' -> 'settlement' tetap diproses, sementara pengulangan status yang
// sama berhenti di sini dengan balasan 200 supaya Midtrans tidak mengulang.
try {
    $mark = $pdo->prepare(
        "INSERT INTO midtrans_notifications
            (order_id, transaction_status, transaction_id, gross_amount)
         VALUES (?, ?, ?, ?)"
    );
    $mark->execute([
        $order_id,
        $transaction_status,
        $notification->transaction_id ?? null,
        is_numeric($gross_amount) ? $gross_amount : null,
    ]);
} catch (PDOException $e) {
    // 23000 = pelanggaran integritas (duplikat kunci unik) -> sudah diproses.
    if ($e->getCode() === '23000') {
        error_log("Webhook diabaikan (duplikat): $order_id / $transaction_status");
        echo json_encode(['status' => 'success', 'message' => 'Notification already processed']);
        exit;
    }
    // Galat lain (mis. tabel penanda belum terbentuk) sengaja tidak
    // menggagalkan proses: webhook tetap berjalan seperti perilaku lama.
    error_log('Webhook idempotency guard error: ' . $e->getMessage());
}

/**
 * Cocokkan order_id Midtrans dengan id pengajuan legalisir.
 *
 * handlers/regenerate_payment.php membuat order_id baru berbentuk
 * "<id pengajuan>-<unix timestamp>" saat admin menekan "Buat Ulang Token
 * Pembayaran", tetapi TIDAK memperbarui kolom midtrans_order_id. Akibatnya
 * pencocokan lama `WHERE id = <order_id>` tidak menemukan baris apa pun dan
 * pembayaran hasil token yang diperbarui tidak pernah terkonfirmasi otomatis.
 *
 * Urutan pencarian: id persis -> kolom midtrans_order_id -> id setelah
 * akhiran "-<timestamp>" dibuang.
 */
function resolve_legalisir_id($pdo, $order_id)
{
    $stmt = $pdo->prepare("SELECT id FROM legalisir_requests WHERE id = ? LIMIT 1");
    $stmt->execute([$order_id]);
    if ($found = $stmt->fetchColumn()) {
        return $found;
    }

    $stmt = $pdo->prepare("SELECT id FROM legalisir_requests WHERE midtrans_order_id = ? LIMIT 1");
    $stmt->execute([$order_id]);
    if ($found = $stmt->fetchColumn()) {
        return $found;
    }

    if (preg_match('/^(LEG-.+?)-\d{9,}$/', $order_id, $m)) {
        $stmt = $pdo->prepare("SELECT id FROM legalisir_requests WHERE id = ? LIMIT 1");
        $stmt->execute([$m[1]]);
        if ($found = $stmt->fetchColumn()) {
            return $found;
        }
    }

    return null;
}

/**
 * Pastikan nominal yang dilaporkan Midtrans sama dengan nominal di database.
 *
 * Signature sudah mengikat gross_amount sehingga tidak dapat dipalsukan pihak
 * luar, namun perbandingan ini tetap menangkap ketidaksesuaian akibat salah
 * konfigurasi atau perubahan tarif di tengah transaksi.
 */
function amount_matches($expected, $reported)
{
    if ($expected === null || !is_numeric($reported)) {
        return true; // tidak dapat dibandingkan -> jangan menghalangi
    }
    return abs((float)$expected - (float)$reported) < 0.01;
}

// 4. Map Midtrans Status to Local App Status
$payment_status = 'pending';
if ($transaction_status == 'settlement' || $transaction_status == 'capture') {
    $payment_status = 'settlement';
} else if ($transaction_status == 'pending') {
    $payment_status = 'pending';
} else if ($transaction_status == 'deny' || $transaction_status == 'expire' || $transaction_status == 'cancel') {
    $payment_status = 'failed';
}

// 5. Update Database based on Order ID Prefix
try {
    if (strpos($order_id, 'LEG-') === 0) {
        // --- HANDLER FOR LEGALISIR ---
        $final_status = ($payment_status == 'settlement') ? 'processing' : 'pending';

        // Cocokkan ke id pengajuan yang sebenarnya. Wajib memakai resolver ini
        // agar pembayaran lewat token yang diperbarui tetap terkonfirmasi.
        $leg_id = resolve_legalisir_id($pdo, $order_id);

        if ($leg_id === null) {
            error_log("Webhook: pengajuan legalisir tidak ditemukan untuk order_id $order_id");
            echo json_encode(['status' => 'success', 'message' => 'Order not found, acknowledged']);
            exit;
        }

        // Validasi nominal terhadap catatan di database.
        $exp_stmt = $pdo->prepare("SELECT amount FROM legalisir_requests WHERE id = ?");
        $exp_stmt->execute([$leg_id]);
        $expected_amount = $exp_stmt->fetchColumn();

        if (!amount_matches($expected_amount, $gross_amount)) {
            error_log(
                "Webhook: nominal TIDAK COCOK untuk $leg_id — " .
                "database=$expected_amount, midtrans=$gross_amount. Tidak diproses."
            );
            http_response_code(409);
            echo json_encode(['status' => 'error', 'message' => 'Amount mismatch']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE legalisir_requests SET payment_status = ?, payment_method = ?, status = ? WHERE id = ?");
        $stmt->execute([$payment_status, $payment_type, $final_status, $leg_id]);

        // Log activity
        error_log("Webhook Success: Legalisir $leg_id updated to $payment_status");

        if ($payment_status == 'settlement') {
            // Fetch request details
            $req_stmt = $pdo->prepare("SELECT user_id, amount, documents FROM legalisir_requests WHERE id = ?");
            $req_stmt->execute([$leg_id]);
            $req = $req_stmt->fetch();
            if ($req) {
                $user_id = $req->user_id;
                $amount = $req->amount;
                $docs = json_decode($req->documents, true) ?: [];
                $doc_type = !empty($docs[0]['type']) ? $docs[0]['type'] : 'Dokumen';
                $doc_desc = $doc_type . (count($docs) > 1 ? ' (' . count($docs) . ' berkas)' : '');

                // Notify alumnus
                add_notification(
                    $user_id,
                    'Pembayaran Legalisir Sukses',
                    'Pembayaran sebesar Rp ' . number_format($amount, 0, ',', '.') . ' untuk pengajuan ' . htmlspecialchars($doc_desc) . ' (' . $leg_id . ') telah lunas diverifikasi secara otomatis via ' . htmlspecialchars($payment_type) . '. Dokumen Anda sedang diproses oleh admin.',
                    'success',
                    // Memakai $leg_id, bukan $order_id mentah: pada pembayaran
                    // hasil regenerasi token keduanya berbeda, dan hanya
                    // $leg_id yang menghasilkan tautan detail yang valid.
                    'index.php?page=legalisir_detail&id=' . $leg_id
                );

                // Notify admins
                notify_roles(
                    ['super_admin', 'admin_legalisir'],
                    'Pembayaran Legalisir Sukses',
                    'Pembayaran legalisir ' . $leg_id . ' sebesar Rp ' . number_format($amount, 0, ',', '.') . ' telah lunas via ' . htmlspecialchars($payment_type) . '.',
                    'success',
                    'index.php?page=admin_legalisir'
                );

                // --- EMAIL NOTIFICATION: Payment success ---
                $user_email_stmt = $pdo->prepare("SELECT email, name FROM users WHERE id = ?");
                $user_email_stmt->execute([$user_id]);
                $alumni_info = $user_email_stmt->fetch();
                if ($alumni_info && !empty($alumni_info->email)) {
                    send_legalisir_payment_email($alumni_info->email, $alumni_info->name, $leg_id, $amount, $payment_type);
                }
            }
        }

    } else if (strpos($order_id, 'DON-') === 0) {
        // --- HANDLER FOR DONATIONS ---
        // Status mapping for donations: pending, success, failed
        $donation_status = ($payment_status == 'settlement') ? 'success' : $payment_status;
        
        $stmt = $pdo->prepare("UPDATE donations SET status = ? WHERE midtrans_order_id = ?");
        $stmt->execute([$donation_status, $order_id]);
        
        error_log("Webhook Success: Donation $order_id updated to $donation_status");

        if ($payment_status == 'settlement') {
            // Fetch donation details
            $don_stmt = $pdo->prepare("SELECT d.user_id, d.donor_name, d.amount, c.title FROM donations d JOIN donation_campaigns c ON d.campaign_id = c.id WHERE d.midtrans_order_id = ?");
            $don_stmt->execute([$order_id]);
            $don = $don_stmt->fetch();
            if ($don) {
                $user_id = $don->user_id;
                $donor_name = $don->donor_name;
                $amount = $don->amount;
                $campaign_title = $don->title;

                // Notify alumnus if logged in
                if ($user_id) {
                    add_notification(
                        $user_id,
                        'Donasi Berhasil Diterima',
                        'Terima kasih! Pembayaran donasi Anda sebesar Rp ' . number_format($amount, 0, ',', '.') . ' untuk program "' . htmlspecialchars($campaign_title) . '" telah berhasil diterima.',
                        'success',
                        'index.php?page=donasi'
                    );
                }

                // Notify admins
                notify_roles(
                    ['super_admin', 'keuangan'],
                    'Donasi Sukses Diterima',
                    'Donasi baru dari ' . htmlspecialchars($donor_name) . ' sebesar Rp ' . number_format($amount, 0, ',', '.') . ' untuk program "' . htmlspecialchars($campaign_title) . '" telah sukses diverifikasi.',
                    'success',
                    'index.php?page=admin_keuangan'
                );

                // --- EMAIL NOTIFICATION: Donation receipt ---
                if ($user_id) {
                    $user_email_stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
                    $user_email_stmt->execute([$user_id]);
                    $user_email = $user_email_stmt->fetchColumn();
                    if ($user_email) {
                        send_donation_receipt($user_email, $donor_name, $order_id, $amount, $campaign_title);
                    }
                }
            }
        }
    }

    echo json_encode(['status' => 'success', 'message' => 'Notification processed']);

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Webhook Error: " . $e->getMessage());
    echo json_encode(['error' => 'Database error', 'message' => 'Terjadi kesalahan sistem saat memproses notifikasi webhook.']);
}
?>
