<?php
/**
 * CSRF Protection Utility
 * Standardizing security for AlumniLink
 */

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../includes/session_boot.php';
}

/**
 * Generate a CSRF token and store it in the session if it doesn't exist.
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Get the current CSRF token.
 */
function get_csrf_token() {
    return generate_csrf_token();
}

/**
 * Verify if the provided CSRF token matches the session token.
 */
function verify_csrf_token($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Generate a hidden input field with the CSRF token.
 */
function csrf_field() {
    echo '<input type="hidden" name="csrf_token" value="' . get_csrf_token() . '">';
}

/**
 * Helper to validate CSRF in handlers
 */
function validate_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!verify_csrf_token($token)) {
            http_response_code(403);
            die(json_encode([
                'status' => 'error',
                'message' => 'CSRF Token Validation Failed. Please refresh the page.'
            ]));
        }
    }
}

/**
 * Ambil token CSRF dari permintaan, apa pun cara pengirimannya.
 *
 * validate_csrf() di atas hanya membaca $_POST, sehingga tidak dapat dipakai
 * untuk dua pola yang juga ada di aplikasi ini:
 *   1. Aksi berbasis GET (mis. tautan hapus pengajuan legalisir).
 *   2. fetch() dengan Content-Type: application/json -- body JSON tidak
 *      pernah mengisi $_POST, jadi token harus lewat header.
 *
 * Urutan pembacaan: body form -> query string -> header X-CSRF-Token.
 */
function get_request_csrf_token() {
    if (!empty($_POST['csrf_token'])) {
        return $_POST['csrf_token'];
    }
    if (!empty($_GET['csrf_token'])) {
        return $_GET['csrf_token'];
    }
    // Apache mengekspos header kustom sebagai HTTP_X_CSRF_TOKEN.
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        return $_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    return '';
}

/**
 * Validasi CSRF untuk permintaan GET, POST form, maupun POST ber-body JSON.
 *
 * @param bool $as_json Balas dengan JSON (untuk endpoint yang dipanggil fetch).
 */
function validate_csrf_request($as_json = false) {
    if (verify_csrf_token(get_request_csrf_token())) {
        return;
    }

    http_response_code(403);
    if ($as_json) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'status'  => 'error',
            'message' => 'Validasi CSRF gagal. Silakan muat ulang halaman.'
        ]);
    } else {
        echo 'Validasi CSRF gagal. Silakan muat ulang halaman lalu coba lagi.';
    }
    exit;
}
?>
