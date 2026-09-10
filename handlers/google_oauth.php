<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';

// Retrieve Google OAuth credentials from database settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('google_client_id', 'google_client_secret')");
$settings = [];
foreach ($stmt->fetchAll() as $row) {
    $settings[$row->setting_key] = $row->setting_value;
}

$client_id = $settings['google_client_id'] ?? '';
$client_secret = $settings['google_client_secret'] ?? '';

// Dynamically generate the redirect URI (supporting SSL termination behind reverse proxies)
$protocol = "http";
if ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) {
    $protocol = "https";
}
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base_dir = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');

// Prioritize BASE_URL from configuration/dotenv if defined
$redirect_uri = rtrim(defined('BASE_URL') ? BASE_URL : ($protocol . "://" . $host . $base_dir), '/') . "/handlers/google_oauth.php";

// If credentials are not set, redirect to login with error
if (empty($client_id) || empty($client_secret)) {
    header('Location: ../login?error=google_not_configured');
    exit;
}

// --------------------------------------------------------------------------
// PHASE 1: Redirect to Google Authorization
// --------------------------------------------------------------------------
if (!isset($_GET['code'])) {
    // Generate a secure state token to prevent CSRF
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    
    // Check if the user initiated from login or register
    $action = $_GET['action'] ?? 'login';
    $_SESSION['oauth_action'] = $action;

    $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => $client_id,
        'redirect_uri'  => $redirect_uri,
        'response_type' => 'code',
        'scope'         => 'email profile',
        'state'         => $state,
        'prompt'        => 'select_account'
    ]);

    header('Location: ' . filter_var($auth_url, FILTER_SANITIZE_URL));
    exit;
}

// --------------------------------------------------------------------------
// PHASE 2: Handle Google Callback (Code Exchange)
// --------------------------------------------------------------------------
if (isset($_GET['code'])) {
    // Verify state to prevent CSRF
    if (empty($_GET['state']) || ($_GET['state'] !== ($_SESSION['oauth_state'] ?? ''))) {
        unset($_SESSION['oauth_state']);
        header('Location: ../login?error=invalid_state');
        exit;
    }
    unset($_SESSION['oauth_state']);

    $code = $_GET['code'];

    // Exchange code for access token using cURL
    $token_url = 'https://oauth2.googleapis.com/token';
    $post_data = [
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
        'code'          => $code,
        'redirect_uri'  => $redirect_uri,
        'grant_type'    => 'authorization_code'
    ];

    $ch = curl_init($token_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
    $token_response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$token_response) {
        error_log("Google OAuth Token Error: " . $token_response);
        header('Location: ../login?error=token_failed');
        exit;
    }

    $token_data = json_decode($token_response, true);
    $access_token = $token_data['access_token'] ?? '';

    if (empty($access_token)) {
        header('Location: ../login?error=no_access_token');
        exit;
    }

    // --------------------------------------------------------------------------
    // PHASE 3: Get User Information from Google
    // --------------------------------------------------------------------------
    $userinfo_url = 'https://www.googleapis.com/oauth2/v2/userinfo';
    $ch = curl_init($userinfo_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token
    ]);
    $userinfo_response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$userinfo_response) {
        error_log("Google OAuth UserInfo Error: " . $userinfo_response);
        header('Location: ../login?error=userinfo_failed');
        exit;
    }

    $google_user = json_decode($userinfo_response, true);
    $google_id = $google_user['id'] ?? '';
    $email = $google_user['email'] ?? '';
    $name = $google_user['name'] ?? '';
    $picture = $google_user['picture'] ?? '';

    if (empty($google_id) || empty($email)) {
        header('Location: ../login?error=invalid_google_profile');
        exit;
    }

    // --------------------------------------------------------------------------
    // PHASE 4: Database Provisioning & Authentication
    // --------------------------------------------------------------------------
    try {
        // 1. Check if user already exists by google_id
        $stmt = $pdo->prepare("SELECT * FROM users WHERE google_id = ?");
        $stmt->execute([$google_id]);
        $user = $stmt->fetch();

        if ($user) {
            // User exists via Google ID -> Proceed to login
            loginUser($user);
        } else {
            // 2. Check if user exists by email (but hasn't linked Google yet)
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // Link Google account to existing user
                $stmt = $pdo->prepare("UPDATE users SET google_id = ?, avatar = COALESCE(avatar, ?) WHERE id = ?");
                $stmt->execute([$google_id, $picture, $user->id]);
                
                // Fetch updated user data
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$user->id]);
                $user = $stmt->fetch();
                
                loginUser($user);
            } else {
                // 3. User does not exist -> Auto Register
                $new_id = uniqid('alumni_'); // Generate unique ID
                
                // Fetch google_oauth_auto_verify setting
                $auto_verify_stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'google_oauth_auto_verify'");
                $auto_verify = (int)($auto_verify_stmt->fetchColumn() ?: 0);

                // Note: 'password' can remain null for OAuth users.
                // 'nim' will be NULL initially. User can fill it later in their profile if required.
                $stmt = $pdo->prepare("
                    INSERT INTO users (id, google_id, name, email, avatar, role, is_verified) 
                    VALUES (?, ?, ?, ?, ?, 'alumni', ?)
                ");
                $stmt->execute([$new_id, $google_id, $name, $email, $picture, $auto_verify]);

                // Dynamic Notifications for new OAuth registration
                require_once '../includes/logger.php';
                if ($auto_verify === 0) {
                    // Send notification to super_admin that a new OAuth user needs verification
                    notify_roles(['super_admin'], 'Pendaftaran Alumni Baru (Google)', 'Alumni baru ' . htmlspecialchars($name) . ' (OAuth Google) telah mendaftar dan menunggu verifikasi akun.', 'warning', 'index.php?page=admin_users');
                } else {
                    // Send success verification notification directly to the new alumnus
                    add_notification(
                        $new_id,
                        'Akun Terverifikasi',
                        'Selamat! Akun alumni Anda telah berhasil dibuat dan otomatis terverifikasi menggunakan Google SSO. Anda kini memiliki akses penuh ke seluruh layanan AlumniLink.',
                        'success',
                        'index.php?page=profile'
                    );
                }

                // Fetch newly created user data
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$new_id]);
                $user = $stmt->fetch();
                
                loginUser($user);
            }
        }
    } catch (PDOException $e) {
        error_log("Google OAuth DB Error: " . $e->getMessage());
        header('Location: ../login?error=system_error');
        exit;
    }
}

/**
 * Helper function to set sessions and log activity.
 */
function loginUser($user) {
    global $pdo;

    // Terbitkan ID sesi baru sebelum identitas ditulis (anti session fixation).
    // Penting khususnya di alur OAuth, karena sesi sudah berjalan sepanjang
    // pengalihan bolak-balik ke Google sebelum sampai di titik ini.
    session_boot_regenerate();

    $_SESSION['user_id'] = $user->id;
    $_SESSION['user_name'] = $user->name;
    $_SESSION['user_email'] = $user->email;
    $_SESSION['user_role'] = $user->role;
    $_SESSION['user_avatar'] = $user->avatar;

    // Log Activity
    require_once '../includes/logger.php';
    log_activity('LOGIN_GOOGLE', 'User logged in successfully via Google SSO');

    // Redirect to dashboard
    header("Location: ../dashboard");
    exit();
}
