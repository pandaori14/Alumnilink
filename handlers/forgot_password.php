<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/mailer.php';
require_once '../includes/rate_limit.php';

// Validate CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check rate limit (max 3 forgot password requests per 15 minutes)
    check_rate_limit('FORGOT_PASSWORD', 3, 15);

    // We can use validate_csrf() but let's handle redirects on failure nicely instead of die()
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        header("Location: ../index.php?page=forgot_password&error=csrf");
        exit();
    }
} else {
    header("Location: ../index.php?page=login");
    exit();
}

$email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);

if (!$email) {
    header("Location: ../index.php?page=forgot_password&error=email_not_found");
    exit();
}

try {
    // Check if user exists in database
    $stmt = $pdo->prepare("SELECT name, google_id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        // Redirection on email not found
        header("Location: ../index.php?page=forgot_password&error=email_not_found");
        exit();
    }

    if (!empty($user->google_id)) {
        // OAuth account - prevent email reset, guide to google/admin recovery
        header("Location: ../index.php?page=forgot_password&error=oauth_account");
        exit();
    }

    // Generate secure random token
    $token = bin2hex(random_bytes(32));

    // Store in password_resets
    $stmt_reset = $pdo->prepare("
        INSERT INTO password_resets (email, token, created_at) 
        VALUES (?, ?, CURRENT_TIMESTAMP) 
        ON DUPLICATE KEY UPDATE token = ?, created_at = CURRENT_TIMESTAMP
    ");
    $stmt_reset->execute([$email, $token, $token]);

    // Construct reset password link
    $reset_link = BASE_URL . "/index.php?page=reset_password&token=" . $token;

    // Send email
    if (send_reset_password_email($email, $reset_link)) {
        // Log action to audit trail
        log_activity('REQUEST_RESET_PASSWORD', 'Pengguna dengan email ' . $email . ' meminta tautan atur ulang kata sandi');
        
        reset_rate_limit('FORGOT_PASSWORD');
        
        header("Location: ../index.php?page=forgot_password&success=reset_requested");
        exit();
    } else {
        header("Location: ../index.php?page=forgot_password&error=mail_failed");
        exit();
    }

} catch (PDOException $e) {
    error_log("Forgot Password Handler Database Error: " . $e->getMessage());
    header("Location: ../index.php?page=forgot_password&error=system");
    exit();
}
