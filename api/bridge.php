<?php
/**
 * API Bridge - Protokol Handshake Awal untuk Mobile Flutter
 * Mengizinkan lintas domain (CORS) dan memeriksa status peladen secara mandiri.
 */

// 1. Tangani CORS (Cross-Origin Resource Sharing)
// Mengizinkan panggilan dari semua Origin (berguna untuk testing Flutter di device/emulator)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// 2. Tangani Preflight Request dari Http client Flutter
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 3. Bangun Payload JSON
$response = [
    'status' => 'success',
    'message' => 'AlumniLink API Bridge is running seamlessly.',
    'api_version' => '1.0.0',
    'timestamp' => time(),
    'server_time' => date('Y-m-d H:i:s'),
    'environment' => (getenv('APP_ENV') ?: 'production')
];

// 4. Return Output
http_response_code(200);
echo json_encode($response, JSON_PRETTY_PRINT);
exit();
?>
