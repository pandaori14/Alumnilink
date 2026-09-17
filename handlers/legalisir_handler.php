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
    $delivery_method = (string)($_POST['delivery_method'] ?? '');
    // Daftar tertutup. Nilai lain sebelumnya tersimpan apa adanya dan
    // diperlakukan sebagai "bukan kurir" tanpa pemberitahuan.
    if (!in_array($delivery_method, ['ambil_sendiri', 'kurir'], true)) {
        header("Location: ../index.php?page=legalisir&error=invalid_delivery");
        exit();
    }
    
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

    }

    // ── Tagihan ──────────────────────────────────────────────────────
    //
    // Dihitung oleh payment_quote(), fungsi yang SAMA dengan yang dipanggil
    // pratinjau di halaman lewat api/payment_quote.php. Sebelumnya handler
    // ini memakai salinan rumus sendiri yang tidak membagi persen dengan
    // 100 dan membaca nama kunci berbeda: alumni melihat Rp 68.344 lalu
    // ditagih Rp 60.000. Ongkir juga dicari ulang di sana, tanpa peka huruf.
    require_once __DIR__ . '/../includes/payment/service.php';

    $doc_count = count($uploaded_files);
    $quote = payment_quote('legalisir', [
        'doc_count'       => $doc_count,
        'delivery_method' => $delivery_method,
        'province'        => $shipping_address['province'] ?? '',
    ]);
    if (!$quote['ok']) {
        error_log('Legalisir: rincian biaya ditolak: ' . $quote['error']);
        header("Location: ../index.php?page=legalisir&error=fee_config");
        exit();
    }

    $order_id = 'LEG-' . strtoupper(uniqid());
    $documents_json = json_encode($uploaded_files);
    $shipping_address_json = $shipping_address ? json_encode($shipping_address, JSON_UNESCAPED_UNICODE) : null;

    try {
        // Permohonan disimpan LEBIH DULU, baru tagihan diminta ke gateway.
        // Urutan lama kebalikannya: bila INSERT gagal, transaksi gateway
        // sudah terbit tanpa catatan apa pun di sistem.
        $pdo->prepare("INSERT INTO legalisir_requests
                (id, user_id, documents, delivery_method, shipping_address, amount, status, payment_status, payment_method)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', 'pending', ?)")
            ->execute([$order_id, $user_id, $documents_json, $delivery_method,
                       $shipping_address_json, $quote['total'], $quote['gateway']]);
    } catch (PDOException $e) {
        error_log("Legalisir Request Error: " . $e->getMessage());
        error_system('Terjadi kesalahan sistem saat memproses pengajuan legalisir. Silakan hubungi administrator.');
    }

    $pengguna = $pdo->prepare("SELECT name, email, phone, address FROM users WHERE id = ?");
    $pengguna->execute([$user_id]);
    $u = $pengguna->fetch();
    $alumni_name = $_SESSION['user_name'] ?? ($u->name ?? 'Alumni');

    // Kegagalan gateway tidak membatalkan permohonan: berkasnya sudah
    // tersimpan, dan alumni dapat meminta tagihan ulang dari halaman detail.
    $tagihan = payment_create('legalisir', $order_id, $order_id, $quote, [
        'name'    => $alumni_name,
        'email'   => $u->email ?? '',
        'phone'   => $shipping_address['phone'] ?? ($u->phone ?? ''),
        'address' => $u->address ?? '',
    ], $user_id);

    log_activity('REQUEST_LEGALISIR', "User requested legalisir ($order_id) with total amount: Rp " . number_format($quote['total'], 0, ',', '.'));
    reset_rate_limit('REQUEST_LEGALISIR');

    notify_roles(['super_admin', 'admin_legalisir'], 'Pengajuan Legalisir Baru',
        'Alumni ' . htmlspecialchars($alumni_name) . ' telah mengajukan legalisir baru (' . $order_id . ') senilai Rp '
        . number_format($quote['total'], 0, ',', '.') . '.', 'info', 'index.php?page=admin_legalisir');

    if (!empty($u->email)) {
        $docs_desc = $doc_count > 1 ? "$doc_count Berkas" : "1 Berkas";
        send_invoice_email($u->email, $alumni_name, $order_id, $quote['total'], $docs_desc,
            payment_gateway_label($quote['gateway']) . ' / Pembayaran Online');
    }

    header("Location: ../index.php?page=legalisir_detail&id=" . rawurlencode($order_id)
        . ($tagihan['ok'] ? '' : '&error=payment_create_failed'));
    exit();
} else {
    header("Location: ../index.php?page=legalisir");
    exit();
}
?>
