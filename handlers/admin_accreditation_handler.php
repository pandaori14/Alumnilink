<?php
require_once '../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once '../includes/logger.php';
require_once '../includes/csrf.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../includes/session_boot.php';
}

// Only allow admin_tracer and super_admin
$user_role = $_SESSION['user_role'] ?? '';
if (!in_array($user_role, ['admin_tracer', 'super_admin'])) {
    error_forbidden();
}

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

if ($action === 'upload') {
    validate_csrf();
    
    $major_code = trim($_POST['major_code'] ?? '');
    $certificate_name = trim($_POST['certificate_name'] ?? '');
    $start_year = (int)($_POST['start_year'] ?? 0);
    $end_year = (int)($_POST['end_year'] ?? 0);
    
    if (empty($major_code) || empty($certificate_name) || !$start_year || !$end_year || empty($_FILES['file']['name'])) {
        header("Location: ../index.php?page=admin_repository&error=missing_accred_data");
        exit;
    }

    if ($start_year > $end_year) {
        header("Location: ../index.php?page=admin_repository&error=invalid_year_range");
        exit;
    }
    
    $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    $filename = $_FILES['file']['name'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    // Verify MIME type using finfo (Security Point #1 & #6)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $_FILES['file']['tmp_name']);
    finfo_close($finfo);
    
    $allowed_mimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    
    if (!in_array($ext, $allowed_exts) || !in_array($mime_type, $allowed_mimes)) {
        header("Location: ../index.php?page=admin_repository&error=invalid_accred_format");
        exit;
    }
    
    $clean_major = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $major_code));
    $new_filename = 'akreditasi_' . $clean_major . '_' . $start_year . '_' . $end_year . '_' . time() . '.' . $ext;
    
    $upload_dir = '../uploads/accreditation/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $target_file = $upload_dir . $new_filename;
    
    try {
        if (move_uploaded_file($_FILES['file']['tmp_name'], $target_file)) {
            $relative_path = 'uploads/accreditation/' . $new_filename;
            
            $stmt = $pdo->prepare("INSERT INTO accreditation_certificates (major_code, certificate_name, start_year, end_year, file_path, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$major_code, $certificate_name, $start_year, $end_year, $relative_path, $_SESSION['user_id']]);
            
            log_activity('Upload Master Accreditation', "Uploaded $certificate_name ($start_year-$end_year) for Major: $major_code");
            header("Location: ../index.php?page=admin_repository&success=accred_uploaded");
        } else {
            header("Location: ../index.php?page=admin_repository&error=accred_upload_failed");
        }
        exit;
    } catch (PDOException $e) {
        error_log("Admin Accreditation Upload Error: " . $e->getMessage());
        header("Location: ../index.php?page=admin_repository&error=accred_upload_failed");
        exit;
    }
} elseif ($action === 'delete') {
    validate_csrf();
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) {
        header("Location: ../index.php?page=admin_repository&error=missing_accred_data");
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT major_code, certificate_name, file_path FROM accreditation_certificates WHERE id = ?");
        $stmt->execute([$id]);
        $doc = $stmt->fetch();
        
        if ($doc) {
            if (file_exists('../' . $doc->file_path)) {
                unlink('../' . $doc->file_path);
            }
            $stmt = $pdo->prepare("DELETE FROM accreditation_certificates WHERE id = ?");
            $stmt->execute([$id]);
            log_activity('Delete Master Accreditation', "Deleted {$doc->certificate_name} for Major: {$doc->major_code}");
        }
        header("Location: ../index.php?page=admin_repository&success=accred_deleted");
        exit;
    } catch (PDOException $e) {
        error_log("Admin Accreditation Delete Error: " . $e->getMessage());
        header("Location: ../index.php?page=admin_repository&error=accred_delete_failed");
        exit;
    }
} else {
    header("Location: ../index.php?page=admin_repository");
    exit;
}
?>
