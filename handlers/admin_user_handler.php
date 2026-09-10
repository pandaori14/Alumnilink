<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once __DIR__ . '/../includes/auth_guard.php';
// Penjagaan berbasis kapabilitas menggantikan pola blacklist lama.
// Selama settings.rbac_enforce masih '0', pelanggaran hanya DICATAT ke
// Audit Trail sebagai 'RBAC_AUDIT' dan akses tetap diizinkan.
require_capability('pengguna.kelola');
require_once '../includes/csrf.php';

// Admin check
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'alumni') {
    error_forbidden();
}

validate_csrf();

$action = $_GET['action'] ?? '';

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $old_id = $_POST['old_id'] ?? '';
    $nim = $_POST['nim'];
    $name = $_POST['name'];
    $email = $_POST['email'];
    $role = $_POST['role'] ?? 'alumni';
    $is_verified = $_POST['is_verified'];
    $password = $_POST['password'] ?? '';

    try {
        // Security: Only super_admin can change role
        if ($_SESSION['user_role'] !== 'super_admin') {
            if ($old_id) {
                // Preserve existing role
                $existing_role = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                $existing_role->execute([$old_id]);
                $role = $existing_role->fetchColumn() ?: 'alumni';
            } else {
                // New user created by non-superadmin defaults to alumni
                $role = 'alumni';
            }
        }
        if ($old_id) {
            // Check previous status for notification
            $check_prev = $pdo->prepare("SELECT is_verified FROM users WHERE id = ?");
            $check_prev->execute([$old_id]);
            $was_verified = (int)$check_prev->fetchColumn();

            // Update Existing User
            if ($password) {
                $pass_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET nim = ?, name = ?, email = ?, password = ?, role = ?, is_verified = ? WHERE id = ?");
                $stmt->execute([$nim, $name, $email, $pass_hash, $role, $is_verified, $old_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET nim = ?, name = ?, email = ?, role = ?, is_verified = ? WHERE id = ?");
                $stmt->execute([$nim, $name, $email, $role, $is_verified, $old_id]);
            }

            // Send notification if newly verified
            if ($is_verified == 1 && !$was_verified) {
                add_notification(
                    $old_id,
                    'Akun Terverifikasi',
                    'Selamat! Akun alumni Anda telah berhasil diverifikasi oleh Admin. Anda kini dapat mengakses layanan penuh AlumniLink.',
                    'success',
                    'index.php?page=profile'
                );
            }

            $success = "updated";
        } else {
            // Insert New User
            $id = 'usr_' . bin2hex(random_bytes(8)) . '_' . time();
            $pass_hash = password_hash($password ?: '123456', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (id, nim, name, email, password, role, is_verified) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$id, $nim, $name, $email, $pass_hash, $role, $is_verified]);

            // Notify new user if they are verified immediately
            if ($is_verified == 1) {
                add_notification(
                    $id,
                    'Akun Terverifikasi',
                    'Selamat! Akun alumni Anda telah berhasil dibuat dan diverifikasi oleh Admin. Anda kini memiliki akses penuh ke seluruh layanan AlumniLink.',
                    'success',
                    'index.php?page=profile'
                );
            }

            $success = "added";
        }
        header("Location: ../index.php?page=admin_users&success=" . $success);
        exit();
    } catch (PDOException $e) {
        error_log("Admin User Save Error: " . $e->getMessage());
        error_system('Terjadi kesalahan sistem saat menyimpan data pengguna. Silakan hubungi administrator.');
    }
}

if ($action === 'delete') {
    if ($_SESSION['user_role'] !== 'super_admin') {
        header("Location: ../index.php?page=admin_users&error=unauthorized_delete");
        exit();
    }
    $id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: ../index.php?page=admin_users&success=deleted");
        exit();
    } catch (PDOException $e) {
        error_log("Admin User Delete Error: " . $e->getMessage());
        error_system('Terjadi kesalahan sistem saat menghapus data pengguna. Silakan hubungi administrator.');
    }
}
?>
