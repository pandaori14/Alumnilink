<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once '../includes/csrf.php';
require_once '../includes/auth_guard.php';

// Hanya super_admin.
//
// Halaman pemanggilnya (pages/admin_settings.php:3) memang sudah dikunci ke
// super_admin, tetapi penjagaan di halaman hanya menyembunyikan antarmuka.
// Handler ini dapat di-POST langsung tanpa pernah membuka halaman tersebut,
// sehingga penjagaan lama yang berpola blacklist ("tolak alumni saja")
// memungkinkan peran admin_tracer, admin_legalisir, dan keuangan menimpa
// kunci server Midtrans, kata sandi SMTP, secret Google OAuth, mode
// maintenance, serta seluruh tarif layanan.
//
// Memperketat ke super_admin di sini tidak mengunci siapa pun yang sah,
// karena satu-satunya jalur antarmuka menuju handler ini sudah super_admin.
require_role(['super_admin']);

validate_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Handle File Upload for Logo with Strict MIME Validation (Security Point #1 & #6)
        if (isset($_FILES['system_logo_file']) && $_FILES['system_logo_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['system_logo_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'svg', 'webp'];
            
            // Verify MIME type using finfo
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            $allowed_mimes = ['image/jpeg', 'image/png', 'image/svg+xml', 'image/webp'];
            
            if (in_array($ext, $allowed) && in_array($mime_type, $allowed_mimes)) {
                $filename = 'logo_' . time() . '.' . $ext;
                $target_dir = '../uploads/system/';
                
                // Create dir if not exists
                if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
                
                $target_path = $target_dir . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $target_path)) {
                    // Update DB with relative path (from root)
                    $db_path = 'uploads/system/' . $filename;
                    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('system_logo', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$db_path, $db_path]);
                }
            } else {
                header("Location: ../index.php?page=admin_settings&error=invalid_file_type");
                exit();
            }
        }

        // Handle Shipping Zones (convert array -> JSON)
        if (isset($_POST['zones']) && is_array($_POST['zones'])) {
            $zones_data = [];
            foreach ($_POST['zones'] as $zone) {
                $zones_data[] = [
                    'label'      => trim($zone['label']),
                    'cost'       => (int)$zone['cost'],
                    'provinces'  => $zone['provinces'] ?? [],
                    'is_default' => !empty($zone['is_default']) && $zone['is_default'] == '1',
                ];
            }
            $zones_json = json_encode($zones_data, JSON_UNESCAPED_UNICODE);
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('shipping_zones', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$zones_json, $zones_json]);
        }

        // Handle Sidebar Permissions
        if (isset($_POST['menus']) && is_array($_POST['menus'])) {
            $configurable_roles = ['alumni', 'admin_tracer', 'admin_legalisir', 'keuangan'];
            // Daftar-putih diturunkan dari definisi menu, bukan disalin.
            // Salinan manual sebelumnya sudah tertinggal tujuh kunci dan
            // MENGHAPUSNYA dari izin tersimpan setiap kali Simpan ditekan.
            require_once __DIR__ . '/../includes/menu.php';
            $valid_menu_keys = all_menu_keys();
            $permissions = [];
            foreach ($configurable_roles as $role) {
                $role_menus = $_POST['menus'][$role] ?? [];
                $permissions[$role] = array_values(
                    array_filter($role_menus, fn($k) => in_array($k, $valid_menu_keys))
                );
            }
            $json_value = json_encode($permissions, JSON_UNESCAPED_UNICODE);
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('sidebar_permissions', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$json_value, $json_value]);
        }

        // Handle Google OAuth Auto-Verify setting
        $auto_verify = isset($_POST['google_oauth_auto_verify']) ? '1' : '0';
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('google_oauth_auto_verify', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$auto_verify, $auto_verify]);

        // Handle Dashboard BG Animation setting
        $bg_animation = isset($_POST['dashboard_bg_animation']) ? '1' : '0';
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('dashboard_bg_animation', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$bg_animation, $bg_animation]);

        // Handle SMTP Force Real setting
        $smtp_force_real = isset($_POST['smtp_force_real']) ? '1' : '0';
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('smtp_force_real', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$smtp_force_real, $smtp_force_real]);

        // Handle Email Send Direct setting
        $email_send_direct = isset($_POST['email_send_direct']) ? '1' : '0';
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('email_send_direct', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$email_send_direct, $email_send_direct]);

        // Handle Audit Log Auto Erase setting
        $audit_log_auto_erase = isset($_POST['audit_log_auto_erase']) ? '1' : '0';
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('audit_log_auto_erase', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$audit_log_auto_erase, $audit_log_auto_erase]);

        // Sakelar penegakan RBAC (pemisahan izin antar peran staf).
        // Ditangani eksplisit karena checkbox yang tidak dicentang tidak ikut
        // terkirim pada POST.
        $rbac_enforce = isset($_POST['rbac_enforce']) ? '1' : '0';
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('rbac_enforce', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$rbac_enforce, $rbac_enforce]);

        // Handle Other Settings
        foreach ($_POST as $key => $value) {
            // Skip file input placeholders, CSRF, zones, menus, and our custom settings (already handled)
            if (in_array($key, ['system_logo_file', 'csrf_token', 'zones', 'menus', 'sidebar_action', 'google_oauth_auto_verify', 'dashboard_bg_animation', 'smtp_force_real', 'email_send_direct', 'audit_log_auto_erase', 'rbac_enforce'])) continue;
            
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }

        log_activity('UPDATE_SETTINGS', 'Administrator updated system settings');

        // Trigger log cleanup immediately after settings are updated
        require_once '../includes/log_cleanup.php';

        header("Location: ../index.php?page=admin_settings&success=saved");
        exit();
    } catch (PDOException $e) {
        error_log("Admin Settings Save Error: " . $e->getMessage());
        error_system('Terjadi kesalahan sistem saat menyimpan pengaturan. Silakan hubungi administrator.');
    }
}
?>
