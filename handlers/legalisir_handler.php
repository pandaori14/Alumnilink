<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once '../includes/csrf.php';
require_once '../includes/rate_limit.php';
require_once '../includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../index.php?page=login");
        exit();
    }

    $user_id = $_SESSION['user_id'];
    
    // Check rate limit (max 10 legalisir requests per 15 minutes)
    check_rate_limit('REQUEST_LEGALISIR', 10, 15);
    
    // Check Verification Status and Tracer Alumni Freshness
    $stmt = $pdo->prepare("SELECT email, is_verified, last_tracer_update, graduation_year FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch();
    
    if (!$user_data || !$user_data->is_verified) {
        header("Location: ../index.php?page=legalisir&error=unverified");
        exit();
    }

    $tracer_date = $user_data->last_tracer_update ?? null;
    $six_months_ago = tracer_validity_threshold(); // dari settings.tracer_validity_months
    if (!$tracer_date || $tracer_date < $six_months_ago) {
        header("Location: ../index.php?page=legalisir&error=needs_tracer");
        exit();
    }

    $docs_type = $_POST['docs_type'] ?? [];
    $delivery_method = $_POST['delivery_method'];
    
    // Fetch Settings for Validation & Calculation
    $stmt = $pdo->query("SELECT * FROM settings");
    $raw_settings = $stmt->fetchAll();
    $settings = [];
    foreach ($raw_settings as $s) {
        $settings[$s->setting_key] = $s->setting_value;
    }

    $allowed_exts = explode(',', strtolower($settings['allowed_file_types'] ?? 'pdf,jpg,jpeg,png'));
    $max_size_kb = (int)($settings['max_file_size'] ?? 2048);

    $doc_types_json = $settings['legalisir_document_types'] ?? '[]';
    $doc_types = json_decode($doc_types_json, true) ?: [];
    $akreditasi_ids = [];
    foreach ($doc_types as $dt) {
        if (!empty($dt['is_akreditasi']) || stripos($dt['id'], 'akreditasi') !== false || stripos($dt['name'], 'akreditasi') !== false) {
            $akreditasi_ids[] = str_replace(' ', '_', $dt['id']);
        }
    }

    // Handle File Uploads
    $uploaded_files = [];
    $upload_dir = '../uploads/legalisir/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

    if (empty($docs_type)) {
        header("Location: ../index.php?page=legalisir&error=no_docs_selected");
        exit();
    }

    foreach ($docs_type as $type) {
        $safe_type  = str_replace(' ', '_', $type);
        if (in_array($safe_type, $akreditasi_ids)) {
            $uploaded_files[] = [
                'type' => $type,
                'file' => 'Otomatis terlampir dari Sistem (Tahun Lulus: ' . htmlspecialchars($user_data->graduation_year ?? '') . ')'
            ];
            continue;
        }

        // PHP replaces spaces with underscores in $_FILES keys
        $input_name = 'file_' . $safe_type;
        
        $file_error = $_FILES[$input_name]['error'] ?? UPLOAD_ERR_NO_FILE;
        
        if ($file_error === UPLOAD_ERR_OK) {
            $file_info  = $_FILES[$input_name];
            $ext        = strtolower(pathinfo($file_info['name'], PATHINFO_EXTENSION));
            $size_kb    = $file_info['size'] / 1024;

            // Verify MIME type using finfo (Security Point #1 & #6)
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file_info['tmp_name']);
            finfo_close($finfo);
            
            $allowed_mimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];

            // 1. Validate Extension & MIME
            if (!in_array($ext, $allowed_exts) || !in_array($mime_type, $allowed_mimes)) {
                header("Location: ../index.php?page=legalisir&error=invalid_format&ext=" . urlencode($ext));
                exit();
            }

            // 2. Validate Size
            if ($size_kb > $max_size_kb) {
                header("Location: ../index.php?page=legalisir&error=file_too_large");
                exit();
            }

            // 3. Write to disk
            if (!is_writable($upload_dir)) {
                header("Location: ../index.php?page=legalisir&error=upload_failed");
                exit();
            }

            $filename = $type . '_' . $user_id . '_' . time() . '.' . $ext;
            $target   = $upload_dir . $filename;

            if (move_uploaded_file($file_info['tmp_name'], $target)) {
                $uploaded_files[] = [
                    'type' => $type,
                    'file' => 'uploads/legalisir/' . $filename
                ];
            } else {
                header("Location: ../index.php?page=legalisir&error=upload_failed");
                exit();
            }

        } elseif ($file_error === UPLOAD_ERR_INI_SIZE || $file_error === UPLOAD_ERR_FORM_SIZE) {
            header("Location: ../index.php?page=legalisir&error=file_too_large");
            exit();
        } else {
            // UPLOAD_ERR_NO_FILE or other — doc was selected but no file attached
            header("Location: ../index.php?page=legalisir&error=missing_file&type=" . urlencode($type));
            exit();
        }
    }

    if (empty($uploaded_files)) {
        header("Location: ../index.php?page=legalisir&error=upload_failed");
        exit();
    }

    // Validate & collect shipping address for courier
    $shipping_address = null;
    $shipping_fee     = 0;

    if ($delivery_method === 'kurir') {
        $addr_name     = trim($_POST['addr_name']     ?? '');
        $addr_phone    = trim($_POST['addr_phone']    ?? '');
        $addr_street   = trim($_POST['addr_street']   ?? '');
        $addr_district = trim($_POST['addr_district'] ?? '');
        $addr_city     = trim($_POST['addr_city']     ?? '');
        $addr_province = trim($_POST['addr_province'] ?? '');
        $addr_postal   = trim($_POST['addr_postal']   ?? '');

        if (!$addr_name || !$addr_phone || !$addr_street || !$addr_city || !$addr_province) {
            header("Location: ../index.php?page=legalisir&error=missing_address");
            exit();
        }

        $shipping_address = [
            'name'     => $addr_name,
            'phone'    => $addr_phone,
            'street'   => $addr_street,
            'district' => $addr_district,
            'city'     => $addr_city,
            'province' => $addr_province,
            'postal'   => $addr_postal,
        ];

        // Server-side zone lookup (do not trust client-side cost)
        $zones_data = json_decode($settings['shipping_zones'] ?? '[]', true) ?: [];
        $matched_zone = null;
        foreach ($zones_data as $zone) {
            if (in_array($addr_province, $zone['provinces'] ?? [])) {
                $matched_zone = $zone;
                break;
            }
        }
        // Fallback to default zone
        if (!$matched_zone) {
            foreach ($zones_data as $zone) {
                if (!empty($zone['is_default'])) { $matched_zone = $zone; break; }
            }
        }
        $shipping_fee = (int)($matched_zone['cost'] ?? $settings['shipping_fee'] ?? 15000);
    }

    $price_per_doc = (int)($settings['price_per_doc'] ?? 10000);
    
    $doc_count = count($uploaded_files);
    $docs_total = $doc_count * $price_per_doc;
    $shipping_cost = ($delivery_method == 'kurir' ? $shipping_fee : 0);
    
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
    $biaya_dokumen    = $docs_total;
    $biaya_pengiriman = $shipping_cost;
    $tagihan_pokok    = $biaya_dokumen + $biaya_pengiriman;

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
    $grand_total = $biaya_dokumen + $biaya_pengiriman + $biaya_admin_dan_layanan;

    // Generate Order ID
    $order_id = 'LEG-' . strtoupper(uniqid());
    $documents_json = json_encode($uploaded_files);

    try {
        // 1. Prepare Midtrans Request
        $is_production = (bool)($settings['midtrans_is_production'] ?? false);
        $expiry_minutes = (int)($settings['payment_expiry'] ?? 1440);

        // Build dynamic base URL (works on localhost & production)
        $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host     = $_SERVER['HTTP_HOST'];
        $base_path = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
        $base_url = $scheme . '://' . $host . $base_path;
        $finish_url = $base_url . '/index.php?page=legalisir_detail&id=' . $order_id;
        
        $payload = [
            'transaction_details' => [
                'order_id' => $order_id,
                'gross_amount' => $grand_total,
            ],
            'item_details' => [
                [
                    'id' => 'DOCS',
                    'price' => $price_per_doc,
                    'quantity' => $doc_count,
                    'name' => 'Biaya Dokumen'
                ],
                [
                    'id' => 'FEE',
                    'price' => $biaya_admin_dan_layanan,
                    'quantity' => 1,
                    'name' => 'Biaya Admin & Layanan'
                ],
                [
                    'id' => 'SHIPPING',
                    'price' => $biaya_pengiriman,
                    'quantity' => 1,
                    'name' => 'Biaya Pengiriman'
                ]
            ],
            'customer_details' => [
                'first_name' => $_SESSION['user_name'],
                'email' => $user_data->email ?? 'alumni@example.com',
            ],
            'expiry' => [
                'start_time' => date("Y-m-d H:i:s O"),
                'unit' => 'minute',
                'duration' => $expiry_minutes
            ],
            'callbacks' => [
                'finish' => $finish_url
            ]
        ];

        $json_payload = json_encode($payload);
        $server_key = $settings['midtrans_server_key'];
        $api_url = $is_production 
            ? "https://app.midtrans.com/snap/v1/transactions" 
            : "https://app.sandbox.midtrans.com/snap/v1/transactions";

        $auth_key = base64_encode($server_key . ':');
        
        // 2. Call Midtrans API via cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . $auth_key
        ));

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $snap_token = null;
        if ($http_code == 201) {
            $res_data = json_decode($response);
            $snap_token = $res_data->token;
        } else {
            // Log error for debugging
            $log_msg = "[" . date('Y-m-d H:i:s') . "] Midtrans Error ($http_code): " . $response . PHP_EOL;
            file_put_contents('../midtrans_error.log', $log_msg, FILE_APPEND);
        }

        // 3. Save to Database
        $shipping_address_json = $shipping_address ? json_encode($shipping_address, JSON_UNESCAPED_UNICODE) : null;
        $insert = $pdo->prepare("INSERT INTO legalisir_requests 
            (id, user_id, documents, delivery_method, shipping_address, amount, status, payment_method, midtrans_order_id, midtrans_snap_token)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)");
        $insert->execute([$order_id, $user_id, $documents_json, $delivery_method, $shipping_address_json, $grand_total, 'midtrans', $order_id, $snap_token]);

        log_activity('REQUEST_LEGALISIR', "User requested legalisir ($order_id) with total amount: Rp " . number_format($grand_total, 0, ',', '.'));
        reset_rate_limit('REQUEST_LEGALISIR');

        // Notify admins about the new legalisir request
        $alumni_name = $_SESSION['user_name'] ?? 'Alumni';
        notify_roles(['super_admin', 'admin_legalisir'], 'Pengajuan Legalisir Baru', 'Alumni ' . htmlspecialchars($alumni_name) . ' telah mengajukan legalisir baru (' . $order_id . ') senilai Rp ' . number_format($grand_total, 0, ',', '.') . '.', 'info', 'index.php?page=admin_legalisir');

        // Send Email Invoice
        if (!empty($user_data->email)) {
            $docs_desc = $doc_count > 1 ? "$doc_count Berkas" : "1 Berkas";
            send_invoice_email($user_data->email, $alumni_name, $order_id, $grand_total, $docs_desc, 'Midtrans / Online Payment');
        }

        // 4. Redirect to Detail Page
        header("Location: ../index.php?page=legalisir_detail&id=" . $order_id);
        exit();

    } catch (Exception $e) {
        error_log("Legalisir Request Error: " . $e->getMessage());
        error_system('Terjadi kesalahan sistem saat memproses pengajuan legalisir. Silakan hubungi administrator.');
    }
} else {
    header("Location: ../index.php?page=legalisir");
    exit();
}
?>
