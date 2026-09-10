<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/rate_limit.php';

// Validate CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    $token = $_POST['token'] ?? '';
    
    // Check rate limit (max 5 reset attempts per 15 minutes)
    check_rate_limit('RESET_PASSWORD', 5, 15);
    
    if (!verify_csrf_token($csrf_token)) {
        header("Location: ../index.php?page=reset_password&token=" . urlencode($token) . "&error=csrf");
        exit();
    }
} else {
    header("Location: ../index.php?page=login");
    exit();
}

$token = $_POST['token'] ?? '';
$password = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

if (empty($token)) {
    header("Location: ../index.php?page=forgot_password");
    exit();
}

// Validation rules
if (strlen($password) < password_min_length()) {
    header("Location: ../index.php?page=reset_password&token=" . urlencode($token) . "&error=too_short");
    exit();
}

if ($password !== $confirm_password) {
    header("Location: ../index.php?page=reset_password&token=" . urlencode($token) . "&error=mismatch");
    exit();
}

try {
    // Check if token is valid and not expired (1 hour)
    $stmt = $pdo->prepare("SELECT email, created_at FROM password_resets WHERE token = ?");
    $stmt->execute([$token]);
    $reset = $stmt->fetch();

    if (!$reset) {
        header("Location: ../index.php?page=reset_password&token=" . urlencode($token) . "&error=invalid");
        exit();
    }

    $created_time = strtotime($reset->created_at);
    $current_time = time();
    $expiry_time = reset_token_lifetime_seconds(); // dari settings.reset_token_expiry_minutes

    if (($current_time - $created_time) > $expiry_time) {
        // Expired token, delete it
        $stmt_del = $pdo->prepare("DELETE FROM password_resets WHERE token = ?");
        $stmt_del->execute([$token]);
        header("Location: ../index.php?page=reset_password&token=" . urlencode($token) . "&error=invalid");
        exit();
    }

    $email = $reset->email;
    $pass_hash = password_hash($password, PASSWORD_DEFAULT);

    // Fetch user details for audit trail logging before changing credentials
    $stmt_user = $pdo->prepare("SELECT id, name, role FROM users WHERE email = ?");
    $stmt_user->execute([$email]);
    $user_info = $stmt_user->fetch();

    $pdo->beginTransaction();

    // Update user's password
    $stmt_update = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
    $stmt_update->execute([$pass_hash, $email]);

    // Delete used token
    $stmt_delete_token = $pdo->prepare("DELETE FROM password_resets WHERE email = ?");
    $stmt_delete_token->execute([$email]);

    $pdo->commit();

    reset_rate_limit('RESET_PASSWORD');

    // Log the reset action using fetched user information
    if ($user_info) {
        $_SESSION['user_id'] = $user_info->id;
        $_SESSION['user_name'] = $user_info->name;
        $_SESSION['user_role'] = $user_info->role;
        log_activity('RESET_PASSWORD', 'Pengguna mereset password via verifikasi tautan email');
        
        // Clean up temporary session data used for logging
        unset($_SESSION['user_id']);
        unset($_SESSION['user_name']);
        unset($_SESSION['user_role']);
    }

    // Clean up local developer email sandbox sessions if active
    if (isset($_SESSION['dev_mail_sandbox'])) {
        unset($_SESSION['dev_mail_sandbox']);
    }

    header("Location: ../index.php?page=login&reset_success=1");
    exit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Reset Password Handler Error: " . $e->getMessage());
    header("Location: ../index.php?page=reset_password&token=" . urlencode($token) . "&error=system");
    exit();
}
