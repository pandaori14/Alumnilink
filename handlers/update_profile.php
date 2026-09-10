<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once '../includes/csrf.php';
require_once '../includes/rate_limit.php';
require_once __DIR__ . '/../includes/import_lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../index.php?page=login");
        exit();
    }

    $user_id = $_SESSION['user_id'];
    
    // Check rate limit (max 10 profile update attempts per 15 minutes)
    check_rate_limit('UPDATE_PROFILE', 10, 15);
    
    // Base user data
    $email = trim($_POST['email'] ?? '');
    
    // Alumni specific data
    $nim = trim($_POST['nim'] ?? '');
    $nim = $nim !== '' ? $nim : null; // Set to null if empty
    $major = trim($_POST['major'] ?? '');
    $major = $major !== '' ? $major : null;
    $graduation_year = trim($_POST['graduation_year'] ?? '');
    $graduation_year = $graduation_year !== '' ? $graduation_year : null;
    // Normalisasi nomor dipindah ke includes/import_lib.php dan dipakai
    // bersama impor massal; bila terpisah, nomor yang masuk lewat dua jalur
    // akan tersimpan dalam dua bentuk dan pencarian nomor tak pernah cocok.
    $phone = import_normalize_phone($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $address = $address !== '' ? $address : null;

    // Password fields
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Avatar Upload Handling with Strict MIME Validation (Security Point #1 & #6)
    $avatar_name = null;
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['avatar']['tmp_name'];
        $file_name = $_FILES['avatar']['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];

        // Verify MIME type using finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_tmp);
        finfo_close($finfo);
        
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif'];

        if (in_array($file_ext, $allowed_exts) && in_array($mime_type, $allowed_mimes)) {
            $new_name = 'avatar_' . preg_replace('/[^a-zA-Z0-9_]/', '', $user_id) . '_' . time() . '.' . $file_ext;
            $upload_dir = '../uploads/avatars/';
            
            if (move_uploaded_file($file_tmp, $upload_dir . $new_name)) {
                $avatar_name = $new_name;
                
                // Fetch old avatar to delete it
                $stmt_old = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
                $stmt_old->execute([$user_id]);
                $old_avatar = $stmt_old->fetchColumn();
                
                if ($old_avatar && file_exists($upload_dir . $old_avatar)) {
                    unlink($upload_dir . $old_avatar);
                }
            }
        } else {
            header("Location: ../index.php?page=profile&error=invalid_file_type");
            exit();
        }
    }

    try {
        // Begin transaction
        $pdo->beginTransaction();

        // 1. Update basic info (Email & Avatar if uploaded)
        if ($avatar_name) {
            $stmt = $pdo->prepare("UPDATE users SET email = ?, avatar = ? WHERE id = ?");
            $stmt->execute([$email, $avatar_name, $user_id]);
            $_SESSION['user_avatar'] = $avatar_name; // Update session if needed
        } else {
            $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
            $stmt->execute([$email, $user_id]);
        }

        // 2. Update Alumni specific fields if present
        if (isset($_POST['major'])) {
            // Kotak centang yang tidak dicentang TIDAK ikut terkirim, jadi
            // nilainya diturunkan dari ada/tidaknya field — bukan dari isinya.
            // Gerbang isset($_POST['major']) sengaja dipertahankan supaya
            // penyimpanan profil staf tidak menyentuh kolom ini.
            $map_opt_out = isset($_POST['map_participate']) ? 0 : 1;

            $stmt = $pdo->prepare("UPDATE users SET nim = ?, major = ?, graduation_year = ?, phone = ?, address = ?, map_opt_out = ? WHERE id = ? AND role = 'alumni'");
            $stmt->execute([$nim, $major, $graduation_year, $phone, $address, $map_opt_out, $user_id]);

            if ($map_opt_out) {
                // Menyaring saat pembacaan saja tidak cukup: koordinat hasil
                // geocoding tetap tersimpan di tabel lain. Barisnya dihapus
                // agar "keluar dari peta" benar-benar berarti datanya hilang.
                // Selalu dibatasi satu pengguna — jangan pernah jadi DELETE massal.
                $stmt_geo = $pdo->prepare("DELETE FROM alumni_geocoding_cache WHERE user_id = ?");
                $stmt_geo->execute([$user_id]);
            }
        }

        // 3. Update Password if provided (only for non-OAuth accounts)
        $stmt_check = $pdo->prepare("SELECT google_id FROM users WHERE id = ?");
        $stmt_check->execute([$user_id]);
        $google_id = $stmt_check->fetchColumn();

        if (!empty($new_password)) {
            if (!empty($google_id)) {
                // Silently skip or throw error. Skipping is cleaner.
            } else if ($new_password === $confirm_password) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed_password, $user_id]);
            } else {
                // Passwords do not match
                $pdo->rollBack();
                header("Location: ../index.php?page=profile&error=password_mismatch");
                exit();
            }
        }

        $pdo->commit();
        log_activity('UPDATE_PROFILE', "User $user_id updated profile");
        reset_rate_limit('UPDATE_PROFILE');

        header("Location: ../index.php?page=profile&success=updated");
        exit();

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Update Profile Error: " . $e->getMessage());
        error_system('Terjadi kesalahan sistem saat memperbarui profil. Silakan hubungi administrator.');
    }
} else {
    header("Location: ../index.php?page=profile");
    exit();
}
?>
