<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once __DIR__ . '/../includes/auth_guard.php';
// Penjagaan berbasis kapabilitas menggantikan pola blacklist lama.
// Selama settings.rbac_enforce masih '0', pelanggaran hanya DICATAT ke
// Audit Trail sebagai 'RBAC_AUDIT' dan akses tetap diizinkan.
require_capability('konten.kelola');
require_once '../includes/csrf.php';
require_once '../includes/mailer.php';

// Admin check
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'alumni') {
    error_forbidden();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    
    $action = $_POST['action'] ?? '';
    $id = $_POST['id'] ?? null;
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $type = $_POST['type'] ?? 'berita';
    
    try {
        if ($action === 'delete' && $id) {
            // Delete post
            $stmt = $pdo->prepare("SELECT image FROM news_posts WHERE id = ?");
            $stmt->execute([$id]);
            $post = $stmt->fetch();
            if ($post && $post->image && file_exists('../' . $post->image)) {
                unlink('../' . $post->image);
            }
            $stmt = $pdo->prepare("DELETE FROM news_posts WHERE id = ?");
            $stmt->execute([$id]);
            header("Location: ../index.php?page=admin_news&success=deleted");
            exit();
        }

        // Validation for create/update
        if (!$title || !$content) {
            header("Location: ../index.php?page=admin_news&error=missing_fields");
            exit();
        }

        $event_date = !empty($_POST['event_date']) ? $_POST['event_date'] : null;
        $registration_link = trim($_POST['registration_link'] ?? '');
        
        // Handle Image Upload with Strict MIME Validation (Security Point #1 & #6)
        $image_path = $_POST['existing_image'] ?? null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['image'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            
            // Verify MIME type using finfo
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];
            
            if (in_array($ext, $allowed) && in_array($mime_type, $allowed_mimes)) {
                $filename = 'news_' . time() . '.' . $ext;
                $target_dir = '../uploads/news/';
                if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
                
                if (move_uploaded_file($file['tmp_name'], $target_dir . $filename)) {
                    // Delete old image if exists
                    if ($image_path && file_exists('../' . $image_path)) {
                        unlink('../' . $image_path);
                    }
                    $image_path = 'uploads/news/' . $filename;
                }
            } else {
                header("Location: ../index.php?page=admin_news&error=invalid_file_type");
                exit();
            }
        }

        if ($id) {
            // Update
            $stmt = $pdo->prepare("UPDATE news_posts SET title = ?, content = ?, image = ?, type = ?, event_date = ?, registration_link = ? WHERE id = ?");
            $stmt->execute([$title, $content, $image_path, $type, $event_date, $registration_link, $id]);
            $success_msg = "updated";
        } else {
            // Create
            $stmt = $pdo->prepare("INSERT INTO news_posts (title, content, image, type, event_date, registration_link) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $content, $image_path, $type, $event_date, $registration_link]);
            $success_msg = "created";

            // Broadcast notification to all alumni
            $notif_title = ($type === 'event' || $type === 'kegiatan') ? 'Event & Kegiatan Baru' : 'Kabar Alumni Terbaru';
            $notif_message = 'Ada postingan baru: "' . $title . '". Silakan cek selengkapnya di portal.';
            notify_all_alumni($notif_title, $notif_message, 'info', 'index.php?page=events');

            // --- EMAIL NOTIFICATION (optional via checkbox) ---
            if (!empty($_POST['send_email_notif'])) {
                $news_id = $pdo->lastInsertId();
                $news_url = BASE_URL . '/index.php?page=news_detail&id=' . $news_id;
                $excerpt = substr(strip_tags($content), 0, 200);
                
                $alumni_list = $pdo->query("SELECT id, email, name FROM users WHERE role = 'alumni' AND email_notifications = 1")->fetchAll();
                foreach ($alumni_list as $a) {
                    if (!empty($a->email)) {
                        send_news_event_email($a->email, $a->name, $title, $type, $excerpt, $news_url);
                    }
                }
            }
        }

        header("Location: ../index.php?page=admin_news&success=" . $success_msg);
        exit();
    } catch (PDOException $e) {
        error_log("Admin News Error: " . $e->getMessage());
        error_system('Terjadi kesalahan sistem saat memproses berita/event. Silakan hubungi administrator.');
    }
}
?>
