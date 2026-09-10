<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once __DIR__ . '/../includes/auth_guard.php';
// Penjagaan berbasis kapabilitas menggantikan pola blacklist lama.
// Selama settings.rbac_enforce masih '0', pelanggaran hanya DICATAT ke
// Audit Trail sebagai 'RBAC_AUDIT' dan akses tetap diizinkan.
require_capability('keuangan.kelola');
require_once '../includes/csrf.php';

// Admin check
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'alumni') {
    error_forbidden();
}

validate_csrf();

$action = $_GET['action'] ?? '';
$id = $_GET['id'] ?? '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $_POST['id'] ?? '';
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $target_amount = (float)$_POST['target_amount'];
        $end_date = $_POST['end_date'];

        // Handle image upload with Strict MIME Validation (Security Point #1 & #6)
        $image_path = $_POST['existing_image'] ?? null; // keep existing if no new file
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['image'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            
            // Verify MIME type using finfo
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            
            if (in_array($ext, $allowed) && in_array($mime_type, $allowed_mimes) && $file['size'] <= 5 * 1024 * 1024) {
                $upload_dir = '../uploads/campaigns/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                $filename   = 'campaign_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
                    $image_path = 'uploads/campaigns/' . $filename;
                }
            } else {
                header("Location: ../index.php?page=admin_donasi&error=invalid_file_type");
                exit();
            }
        }

        if ($id) {
            // Update
            $stmt = $pdo->prepare("UPDATE donation_campaigns SET title = ?, description = ?, target_amount = ?, end_date = ?, image = ? WHERE id = ?");
            $stmt->execute([$title, $description, $target_amount, $end_date, $image_path, $id]);
        } else {
            // Create
            $stmt = $pdo->prepare("INSERT INTO donation_campaigns (title, description, target_amount, end_date, image) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$title, $description, $target_amount, $end_date, $image_path]);
        }
    } elseif ($action === 'toggle' && $id) {
        $stmt = $pdo->prepare("UPDATE donation_campaigns SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute([$id]);
    } elseif ($action === 'delete' && $id) {
        $stmt = $pdo->prepare("DELETE FROM donation_campaigns WHERE id = ?");
        $stmt->execute([$id]);
    }

    header("Location: ../index.php?page=admin_donasi");
    exit();
} catch (PDOException $e) {
    error_log("Admin Campaign Error: " . $e->getMessage());
    error_system('Terjadi kesalahan sistem saat memproses kampanye donasi. Silakan hubungi administrator.');
}
?>
