<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once '../includes/csrf.php';
require_once '../includes/rate_limit.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    
    // Check rate limit (max 5 registration attempts per 15 minutes)
    check_rate_limit('REGISTER', 5, 15);

    $nim = trim($_POST['nim'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = password_hash($_POST['password'] ?? '', PASSWORD_DEFAULT);
    $id = 'usr_' . bin2hex(random_bytes(8)) . '_' . time();

    try {
        // Check if email or NIM already exists
        $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? OR nim = ?");
        $check->execute([$email, $nim]);
        if ($check->fetchColumn() > 0) {
            header("Location: ../index.php?page=register&error=exists");
            exit();
        }

        // Insert new user
        $stmt = $pdo->prepare("INSERT INTO users (id, nim, name, email, password, role) VALUES (?, ?, ?, ?, ?, 'alumni')");
        $stmt->execute([$id, $nim, $name, $email, $password]);

        // Notify super_admin about the new user needing validation
        notify_roles(['super_admin'], 'Pendaftaran Alumni Baru', 'Alumni baru ' . htmlspecialchars($name) . ' (NIM: ' . htmlspecialchars($nim) . ') telah mendaftar dan menunggu verifikasi akun.', 'warning', 'index.php?page=admin_users');

        // Auto login after registration
        // Sama seperti alur masuk biasa: terbitkan ID sesi baru lebih dulu.
        session_boot_regenerate();

        $_SESSION['user_id'] = $id;
        $_SESSION['user_name'] = $name;
        $_SESSION['user_role'] = 'alumni';

        log_activity('REGISTER', "New user registered: $name ($email)");
        reset_rate_limit('REGISTER');

        header("Location: ../index.php?page=dashboard&success=welcome");
        exit();
    } catch (PDOException $e) {
        error_log("Register Error: " . $e->getMessage());
        error_system('Terjadi kesalahan sistem saat pendaftaran. Silakan hubungi administrator.');
    }
} else {
    header("Location: ../index.php?page=register");
    exit();
}
?>
