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
    
    $nim = trim($_POST['nim'] ?? '');
    $doc_type = trim($_POST['document_type'] ?? '');
    
    if (empty($nim) || empty($doc_type) || empty($_FILES['file']['name'])) {
        header("Location: ../index.php?page=admin_repository&error=missing_data");
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
        header("Location: ../index.php?page=admin_repository&error=invalid_format");
        exit;
    }
    
    $clean_nim = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $nim));
    $clean_type = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $doc_type)));
    $new_filename = $clean_type . '_' . $clean_nim . '.' . $ext;
    
    $upload_dir = '../uploads/repository/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $target_file = $upload_dir . $new_filename;
    
    try {
        if (move_uploaded_file($_FILES['file']['tmp_name'], $target_file)) {
            $stmt = $pdo->prepare("SELECT id, file_path FROM document_repository WHERE nim = ? AND document_type = ?");
            $stmt->execute([$clean_nim, $clean_type]);
            $existing = $stmt->fetch();
            
            $relative_path = 'uploads/repository/' . $new_filename;
            
            if ($existing) {
                // Delete old file
                if (file_exists('../' . $existing->file_path)) {
                    unlink('../' . $existing->file_path);
                }
                // Update
                $stmt = $pdo->prepare("UPDATE document_repository SET file_path = ?, uploaded_by = ? WHERE id = ?");
                $stmt->execute([$relative_path, $_SESSION['user_id'], $existing->id]);
            } else {
                // Insert
                $stmt = $pdo->prepare("INSERT INTO document_repository (nim, document_type, file_path, uploaded_by) VALUES (?, ?, ?, ?)");
                $stmt->execute([$clean_nim, $clean_type, $relative_path, $_SESSION['user_id']]);
            }
            
            log_activity('Upload Repository', "Uploaded $clean_type for NIM: $clean_nim");
            header("Location: ../index.php?page=admin_repository&success=uploaded");
        } else {
            header("Location: ../index.php?page=admin_repository&error=upload_failed");
        }
        exit;
    } catch (PDOException $e) {
        error_log("Admin Repository Upload Error: " . $e->getMessage());
        header("Location: ../index.php?page=admin_repository&error=upload_failed");
        exit;
    }
} elseif ($action === 'bulk_upload_single') {
    validate_csrf();
    
    if (empty($_FILES['file']['name'])) {
        echo json_encode(['success' => false, 'message' => 'Tidak ada file yang dikirim']);
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
        echo json_encode(['success' => false, 'message' => 'Format ekstensi atau tipe file tidak didukung']);
        exit;
    }
    
    $name_without_ext = pathinfo($filename, PATHINFO_FILENAME);
    $parts = explode('_', $name_without_ext);
    
    if (count($parts) < 2) {
        echo json_encode(['success' => false, 'message' => 'Format nama file harus jenisdokumen_nim (Contoh: ijazah_j500230013.pdf)']);
        exit;
    }
    
    $raw_nim = array_pop($parts);
    $raw_type = implode('_', $parts);
    
    $clean_nim = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $raw_nim));
    $clean_type = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $raw_type)));
    
    if (empty($clean_nim) || empty($clean_type)) {
        echo json_encode(['success' => false, 'message' => 'NIM atau Jenis Dokumen tidak valid']);
        exit;
    }
    
    $new_filename = $clean_type . '_' . $clean_nim . '.' . $ext;
    $upload_dir = '../uploads/repository/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $target_file = $upload_dir . $new_filename;
    
    try {
        if (move_uploaded_file($_FILES['file']['tmp_name'], $target_file)) {
            $stmt = $pdo->prepare("SELECT id, file_path FROM document_repository WHERE nim = ? AND document_type = ?");
            $stmt->execute([$clean_nim, $clean_type]);
            $existing = $stmt->fetch();
            
            $relative_path = 'uploads/repository/' . $new_filename;
            
            if ($existing) {
                if (file_exists('../' . $existing->file_path) && $existing->file_path !== $relative_path) {
                    unlink('../' . $existing->file_path);
                }
                $stmt = $pdo->prepare("UPDATE document_repository SET file_path = ?, uploaded_by = ? WHERE id = ?");
                $stmt->execute([$relative_path, $_SESSION['user_id'], $existing->id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO document_repository (nim, document_type, file_path, uploaded_by) VALUES (?, ?, ?, ?)");
                $stmt->execute([$clean_nim, $clean_type, $relative_path, $_SESSION['user_id']]);
            }
            
            log_activity('Bulk Upload Item', "Uploaded $clean_type for NIM: $clean_nim via Bulk");
            echo json_encode(['success' => true, 'nim' => $clean_nim, 'doc_type' => $clean_type]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal memindahkan file ke server']);
        }
        exit;
    } catch (PDOException $e) {
        error_log("Admin Repository Bulk Upload Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem saat menyimpan data repository.']);
        exit;
    }
} elseif ($action === 'delete') {
    validate_csrf();
    $id = $_POST['id'] ?? '';
    if (!$id) {
        header("Location: ../index.php?page=admin_repository&error=missing_data");
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT nim, document_type, file_path FROM document_repository WHERE id = ?");
        $stmt->execute([$id]);
        $doc = $stmt->fetch();
        
        if ($doc) {
            if (file_exists('../' . $doc->file_path)) {
                unlink('../' . $doc->file_path);
            }
            $stmt = $pdo->prepare("DELETE FROM document_repository WHERE id = ?");
            $stmt->execute([$id]);
            log_activity('Delete Repository', "Deleted {$doc->document_type} for NIM: {$doc->nim}");
        }
        header("Location: ../index.php?page=admin_repository&success=deleted");
        exit;
    } catch (PDOException $e) {
        error_log("Admin Repository Delete Error: " . $e->getMessage());
        header("Location: ../index.php?page=admin_repository&error=delete_failed");
        exit;
    }
} else {
    header("Location: ../index.php?page=admin_repository");
    exit;
}
?>
