<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/rate_limit.php';
require_once '../includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die(json_encode(['error' => 'Invalid request']));
}

validate_csrf();

// Check rate limit (max 10 donation attempts per 15 minutes)
check_rate_limit('DONATION', 10, 15);

$campaign_id = $_POST['campaign_id'];
$amount      = $_POST['amount'];
$donor_name  = $_POST['donor_name'];
$message     = $_POST['message'] ?? '';
$user_id     = $_SESSION['user_id'] ?? null;

if ($amount < 10000) {
    die(json_encode(['error' => 'Minimal donasi adalah Rp 10.000']));
}

try {
    // 1. Fetch Midtrans Settings
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'midtrans_%'");
    $settings = [];
    foreach ($stmt->fetchAll() as $s) {
        $settings[$s->setting_key] = $s->setting_value;
    }

    $server_key    = $settings['midtrans_server_key'];
    $is_production = $settings['midtrans_is_production'] == '1';
    $api_url       = $is_production 
        ? "https://app.midtrans.com/snap/v1/transactions" 
        : "https://app.sandbox.midtrans.com/snap/v1/transactions";

    // 2. Prepare Order ID
    $order_id = 'DON-' . time() . '-' . rand(100, 999);

    // --- MIDTRANS GROSS-UP & CUSTOM TAX CONFIGURATION ---
    $mdr_rate_max       = (float)($settings['midtrans_mdr_rate'] ?? 0.04);
    $ppn_midtrans_rate  = (float)($settings['midtrans_ppn_rate'] ?? 0.11);
    $biaya_payout       = (int)($settings['midtrans_payout_fee'] ?? 5550);
    $margin_admin       = (int)($settings['midtrans_admin_margin'] ?? 2000);
    $batas_minimum_fee  = (int)($settings['midtrans_min_fee'] ?? 10000);
    $custom_tax_type    = $settings['midtrans_custom_tax_type'] ?? 'flat';
    $custom_tax_value   = (float)($settings['midtrans_custom_tax_value'] ?? 0);

    // 1. Constant Derivation
    $total_mdr_multiplier = $mdr_rate_max + ($mdr_rate_max * $ppn_midtrans_rate);
    $gross_up_divider     = 1 - $total_mdr_multiplier;

    // 2. Base Identifier
    $tagihan_pokok    = (int)$amount;

    // 3. Custom Tax
    $nominal_custom_tax = 0;
    if ($custom_tax_type === 'percentage') {
        $nominal_custom_tax = $tagihan_pokok * $custom_tax_value;
    } else {
        $nominal_custom_tax = $custom_tax_value;
    }

    // 4. Tagihan Internal
    $tagihan_internal = $tagihan_pokok + $nominal_custom_tax;

    // 5. Kalkulasi Gateway (Gross-Up)
    $biaya_kotor_gateway = ($tagihan_internal * $total_mdr_multiplier) + $biaya_payout + $margin_admin;
    $biaya_gateway_sementara = $biaya_kotor_gateway / $gross_up_divider;

    // 6. Batas Minimum & Penggabungan UI
    $biaya_gateway_final = $biaya_gateway_sementara < $batas_minimum_fee 
        ? $batas_minimum_fee 
        : (int)ceil($biaya_gateway_sementara);

    $biaya_admin_dan_layanan = (int)ceil($biaya_gateway_final + $nominal_custom_tax);

    // 7. Final Grand Total
    $gross_amount = $tagihan_pokok + $biaya_admin_dan_layanan;

    // 3. Request Snap Token from Midtrans
    $payload = [
        'transaction_details' => [
            'order_id'     => $order_id,
            'gross_amount' => $gross_amount,
        ],
        'customer_details' => [
            'first_name' => $donor_name,
            'email'      => $_SESSION['user_email'] ?? 'alumni@fkums.com',
        ],
        'item_details' => [
            [
                'id'       => $campaign_id,
                'price'    => $tagihan_pokok,
                'quantity' => 1,
                'name'     => 'Donasi Pokok'
            ],
            [
                'id'       => 'FEE',
                'price'    => $biaya_admin_dan_layanan,
                'quantity' => 1,
                'name'     => 'Biaya Admin & Layanan'
            ]
        ]
    ];

    $auth = base64_encode($server_key . ':');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Basic ' . $auth
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response);

    if ($http_code === 201 && isset($result->token)) {
        $stmt = $pdo->prepare("INSERT INTO donations (campaign_id, user_id, donor_name, amount, message, status, midtrans_order_id) VALUES (?, ?, ?, ?, ?, 'pending', ?)");
        $stmt->execute([$campaign_id, $user_id, $donor_name, $amount, $message, $order_id]);

        // Fetch campaign details for rich notification description
        $camp_stmt = $pdo->prepare("SELECT title FROM donation_campaigns WHERE id = ?");
        $camp_stmt->execute([$campaign_id]);
        $campaign_title = $camp_stmt->fetchColumn() ?: 'Program Umum';

        // Notify alumnus if logged in
        if ($user_id) {
            add_notification(
                $user_id,
                'Donasi Terdaftar (Pending)',
                'Registrasi donasi Anda sebesar Rp ' . number_format($amount, 0, ',', '.') . ' untuk program "' . htmlspecialchars($campaign_title) . '" telah terdaftar. Silakan selesaikan pembayaran.',
                'info',
                'index.php?page=donasi'
            );
        }

        // Notify admins
        notify_roles(
            ['super_admin', 'keuangan'],
            'Pendaftaran Donasi Baru (Pending)',
            'Donatur ' . htmlspecialchars($donor_name) . ' mendaftarkan donasi sebesar Rp ' . number_format($amount, 0, ',', '.') . ' untuk program "' . htmlspecialchars($campaign_title) . '". Status saat ini: Menunggu Pembayaran.',
            'info',
            'index.php?page=admin_keuangan'
        );

        // Send Email Invoice if user_id is set and has email
        if ($user_id) {
            $user_stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
            $user_stmt->execute([$user_id]);
            $user_email = $user_stmt->fetchColumn();
            if ($user_email) {
                send_donation_invoice($user_email, $donor_name, $order_id, $amount, $campaign_title, 'Midtrans / Online Payment');
            }
        }

        reset_rate_limit('DONATION');
        echo json_encode(['snap_token' => $result->token]);
    } else {
        echo json_encode(['error' => 'Gagal membuat transaksi di Midtrans. Check Server Key.']);
    }
} catch (PDOException $e) {
    error_log("Donation Handler Error: " . $e->getMessage());
    echo json_encode(['error' => 'Terjadi kesalahan sistem saat memproses donasi.']);
}
?>
