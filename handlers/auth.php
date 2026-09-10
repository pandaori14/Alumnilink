<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once '../includes/csrf.php';
require_once '../includes/rate_limit.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    
    // Check rate limit (max 5 attempts per 15 minutes)
    check_rate_limit('LOGIN', 5, 15);

    $identity = trim($_POST['identity'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? OR nim = ?");
        $stmt->execute([$identity, $identity]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user->password)) {
            // Login success

            // Terbitkan ID sesi baru sebelum identitas ditulis ke sesi.
            // Tanpa ini, ID sesi yang sudah dipegang pengunjung sebelum masuk
            // tetap berlaku setelah masuk (session fixation).
            session_boot_regenerate();

            $_SESSION['user_id'] = $user->id;
            $_SESSION['user_name'] = $user->name;
            $_SESSION['user_role'] = $user->role;
            
            log_activity('LOGIN_SUCCESS', 'User logged in successfully');
            reset_rate_limit('LOGIN');
            
            header("Location: ../index.php?page=dashboard");
            exit();
        } else {
            // Login failed
            log_activity('LOGIN_FAILED', 'Failed login attempt for identity: ' . $identity);
            header("Location: ../index.php?page=login&error=1");
            exit();
        }
    } catch (PDOException $e) {
        error_log("Auth Error: " . $e->getMessage());
        error_system('Terjadi kesalahan sistem saat proses otentikasi. Silakan hubungi administrator.');
    }
} else {
    header("Location: ../index.php?page=login");
    exit();
}
?>
