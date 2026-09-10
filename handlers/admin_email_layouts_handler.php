<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/auth_guard.php';
// Penjagaan berbasis kapabilitas menggantikan pola blacklist lama.
// Selama settings.rbac_enforce masih '0', pelanggaran hanya DICATAT ke
// Audit Trail sebagai 'RBAC_AUDIT' dan akses tetap diizinkan.
require_capability('broadcast.kirim');
require_once '../includes/csrf.php';
require_once '../includes/logger.php';

// Cek autentikasi dan otorisasi
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['success' => false, 'error' => 'Unauthorized']));
}

// Handler request GET (Ambil data layout)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['action']) && $_GET['action'] === 'get') {
        $id = $_GET['id'] ?? '';
        $stmt = $pdo->prepare("SELECT * FROM broadcast_layouts WHERE id = ?");
        $stmt->execute([$id]);
        $layout = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($layout) {
            echo json_encode(['success' => true, 'layout' => $layout]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Layout tidak ditemukan']);
        }
        exit;
    }
}

// Handler request POST (Create/Update/Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    
    $action = $_POST['action'] ?? '';
    $user_id = $_SESSION['user_id'];
    
    try {
        if ($action === 'save') {
            $id = $_POST['id'] ?? '';
            $name = trim($_POST['name'] ?? '');
            $html_content = $_POST['html_content'] ?? '';
            $thumb_type = $_POST['thumb_type'] ?? 'simple';
            
            if (empty($name) || empty($html_content)) {
                echo json_encode(['success' => false, 'error' => 'Nama dan isi layout wajib diisi']);
                exit;
            }
            
            if (empty($id)) {
                // INSERT NEW
                $stmt = $pdo->prepare("INSERT INTO broadcast_layouts (name, html_content, thumb_type, is_default, created_by) VALUES (?, ?, ?, 0, ?)");
                $stmt->execute([$name, $html_content, $thumb_type, $user_id]);
                $new_id = $pdo->lastInsertId();
                log_activity('CREATE_EMAIL_LAYOUT', 'Membuat layout email baru: ' . $name);
                echo json_encode(['success' => true, 'id' => $new_id, 'message' => 'Layout berhasil dibuat']);
            } else {
                // UPDATE EXISTING
                // Cek kepemilikan atau role super_admin
                $check = $pdo->prepare("SELECT created_by, is_default FROM broadcast_layouts WHERE id = ?");
                $check->execute([$id]);
                $existing = $check->fetch();
                
                if (!$existing) {
                    echo json_encode(['success' => false, 'error' => 'Layout tidak ditemukan']);
                    exit;
                }
                
                if ($_SESSION['user_role'] !== 'super_admin' && $existing->created_by !== $user_id) {
                    echo json_encode(['success' => false, 'error' => 'Akses ditolak. Anda tidak berhak mengedit layout ini.']);
                    exit;
                }
                
                if ($existing->is_default == 1) {
                    echo json_encode(['success' => false, 'error' => 'Template default sistem tidak dapat diubah secara langsung. Silakan duplikasi terlebih dahulu.']);
                    exit;
                }
                
                $stmt = $pdo->prepare("UPDATE broadcast_layouts SET name = ?, html_content = ?, thumb_type = ? WHERE id = ?");
                $stmt->execute([$name, $html_content, $thumb_type, $id]);
                log_activity('UPDATE_EMAIL_LAYOUT', 'Memperbarui layout email: ' . $name);
                echo json_encode(['success' => true, 'message' => 'Layout berhasil diperbarui']);
            }
        } 
        elseif ($action === 'delete') {
            $id = $_POST['id'] ?? '';
            
            // Cek kepemilikan
            $check = $pdo->prepare("SELECT name, created_by, is_default FROM broadcast_layouts WHERE id = ?");
            $check->execute([$id]);
            $existing = $check->fetch();
            
            if (!$existing) {
                echo json_encode(['success' => false, 'error' => 'Layout tidak ditemukan']);
                exit;
            }
            
            if ($_SESSION['user_role'] !== 'super_admin' && $existing->created_by !== $user_id) {
                echo json_encode(['success' => false, 'error' => 'Akses ditolak']);
                exit;
            }
            
            if ($existing->is_default == 1) {
                echo json_encode(['success' => false, 'error' => 'Template default tidak dapat dihapus']);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM broadcast_layouts WHERE id = ?");
            $stmt->execute([$id]);
            log_activity('DELETE_EMAIL_LAYOUT', 'Menghapus layout email: ' . $existing->name);
            echo json_encode(['success' => true, 'message' => 'Layout berhasil dihapus']);
        }
        else {
            echo json_encode(['success' => false, 'error' => 'Aksi tidak valid']);
        }
    } catch (PDOException $e) {
        error_log("Email Layout Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Terjadi kesalahan sistem database']);
    }
}
?>
