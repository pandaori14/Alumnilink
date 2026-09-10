<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once '../includes/csrf.php';

// Seluruh formulir pemanggil sudah memuat csrf_field():
//   pages/admin_legalisir.php:125, pages/admin_legalisir.php:259,
//   pages/legalisir_detail.php:219
// sehingga validasi di sini tidak mengubah alur yang sudah berjalan.
validate_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    $request_id = $_POST['request_id'];
    $user_id = $_SESSION['user_id'];

    // Fetch Request & Alumnus Details (Allow admins to bypass user_id check)
    if ($_SESSION['user_role'] !== 'alumni') {
        $stmt = $pdo->prepare("SELECT lr.*, u.name as user_name, u.email as user_email FROM legalisir_requests lr JOIN users u ON lr.user_id = u.id WHERE lr.id = ?");
        $stmt->execute([$request_id]);
    } else {
        $stmt = $pdo->prepare("SELECT lr.*, u.name as user_name, u.email as user_email FROM legalisir_requests lr JOIN users u ON lr.user_id = u.id WHERE lr.id = ? AND lr.user_id = ?");
        $stmt->execute([$request_id, $user_id]);
    }
    $req = $stmt->fetch();

    if (!$req) {
        $redirect_page = ($_SESSION['user_role'] !== 'alumni') ? 'admin_legalisir' : 'legalisir';
        header("Location: ../index.php?page=" . $redirect_page . "&error=not_found");
        exit();
    }

    // Fetch Settings
    $settings = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Build dynamic base URL (works on localhost & production)
    $scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host      = $_SERVER['HTTP_HOST'];
    $base_path = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
    $base_url  = $scheme . '://' . $host . $base_path;
    $finish_url = $base_url . '/index.php?page=legalisir_detail&id=' . $request_id;

    // Prepare Midtrans Payload
    $is_production = (bool)($settings['midtrans_is_production'] ?? false);
    $server_key = $settings['midtrans_server_key'];
    $api_url = $is_production 
        ? "https://app.midtrans.com/snap/v1/transactions" 
        : "https://app.sandbox.midtrans.com/snap/v1/transactions";

    // Midtrans menolak order_id yang sudah pernah dipakai, sehingga token baru
    // wajib memakai order_id baru. Nilainya disimpan ke database di bawah agar
    // webhook tetap dapat mencocokkannya kembali ke pengajuan ini.
    $new_order_id = $req->id . '-' . time();

    // Re-calculate or use existing amount
    $payload = [
        'transaction_details' => [
            'order_id' => $new_order_id,
            'gross_amount' => (int)$req->amount,
        ],
        'customer_details' => [
            'first_name' => $req->user_name,
            'email' => $req->user_email ?: 'alumni@example.com',
        ],
        'expiry' => [
            'start_time' => date("Y-m-d H:i:s O"),
            'unit' => 'minute',
            'duration' => (int)($settings['payment_expiry'] ?? 1440)
        ],
        'callbacks' => [
            'finish' => $finish_url
        ]
    ];

    $auth_key = base64_encode($server_key . ':');
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Basic ' . $auth_key
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 201) {
        $res_data = json_decode($response);
        $new_token = $res_data->token;

        // Simpan token BESERTA order_id barunya.
        //
        // Sebelumnya hanya token yang disimpan, sehingga midtrans_order_id di
        // database tetap berisi nilai lama. Ketika alumni membayar memakai
        // token baru, Midtrans mengirim order_id baru yang tidak cocok dengan
        // baris mana pun, dan pembayaran tidak pernah terkonfirmasi otomatis.
        $update = $pdo->prepare("UPDATE legalisir_requests SET midtrans_snap_token = ?, midtrans_order_id = ? WHERE id = ?");
        $update->execute([$new_token, $new_order_id, $request_id]);

        $redirect_page = ($_SESSION['user_role'] !== 'alumni') ? 'admin_legalisir' : ('legalisir_detail&id=' . $request_id);
        header("Location: ../index.php?page=" . $redirect_page . "&success=token_regenerated");
    } else {
        $redirect_page = ($_SESSION['user_role'] !== 'alumni') ? 'admin_legalisir' : ('legalisir_detail&id=' . $request_id);
        header("Location: ../index.php?page=" . $redirect_page . "&error=midtrans_failed");
    }
    exit();
}
header("Location: ../index.php?page=legalisir");
exit();
