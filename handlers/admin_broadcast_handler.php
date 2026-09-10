<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/auth_guard.php';
// Penjagaan berbasis kapabilitas menggantikan pola blacklist lama.
// Selama settings.rbac_enforce masih '0', pelanggaran hanya DICATAT ke
// Audit Trail sebagai 'RBAC_AUDIT' dan akses tetap diizinkan.
require_capability('broadcast.kirim');
require_once '../includes/csrf.php';
require_once '../includes/mailer.php';
require_once '../includes/logger.php';

// Auth check
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'alumni') {
    http_response_code(403);
    die('Unauthorized');
}

validate_csrf();

$action = $_GET['action'] ?? '';

// ══════════════════════════════════════════════════════════
//  ACTION: SEND BROADCAST
// ══════════════════════════════════════════════════════════
if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $title    = trim($_POST['title']    ?? '');
    $body_html= trim($_POST['body_html']?? '');  // Rich HTML content
    $priority = in_array($_POST['priority'] ?? '', ['normal','info','urgent']) ? $_POST['priority'] : 'normal';
    $ch_app   = !empty($_POST['ch_app']);
    $ch_email = !empty($_POST['ch_email']);

    // Target filter
    $target_type  = in_array($_POST['target_type'] ?? '', ['all','major','year','role','external']) ? $_POST['target_type'] : 'all';
    $target_value = trim($_POST['target_value'] ?? '');

    // Plain text fallback (strip HTML for in-app notification)
    $plain_text = strip_tags($body_html);
    $plain_text = preg_replace('/\s+/', ' ', $plain_text);
    $plain_text = mb_substr(trim($plain_text), 0, 500);

    // External recipients JSON (e.g. ['email1@example.com', 'email2@example.com'])
    $external_emails_raw = $_POST['external_emails'] ?? '[]';
    $external_emails = json_decode($external_emails_raw, true);
    if (!is_array($external_emails)) $external_emails = [];
    $valid_external_emails = [];
    foreach ($external_emails as $ext_email) {
        $ext_email = filter_var(trim($ext_email), FILTER_VALIDATE_EMAIL);
        if ($ext_email) {
            $valid_external_emails[] = $ext_email;
        }
    }
    $valid_external_emails = array_unique($valid_external_emails);
    $external_count = count($valid_external_emails);

    // Validation
    if (empty($title) || empty($body_html)) {
        header('Location: ../index.php?page=admin_broadcast&error=missing_fields');
        exit();
    }
    if (!$ch_app && !$ch_email && $external_count === 0) {
        header('Location: ../index.php?page=admin_broadcast&error=no_channel');
        exit();
    }
    if ($target_type === 'external' && $external_count === 0) {
        header('Location: ../index.php?page=admin_broadcast&error=no_external_emails');
        exit();
    }

    // Build target_filter string
    $target_filter = 'all';
    if ($target_type === 'major' && $target_value) {
        $target_filter = 'major:' . $target_value;
    } elseif ($target_type === 'year' && is_numeric($target_value)) {
        $target_filter = 'year:' . $target_value;
    } elseif ($target_type === 'role') {
        $target_filter = 'role:all_users';
    } elseif ($target_type === 'external') {
        $target_filter = 'external_only';
    }

    $channels_str = implode(',', array_filter(['app' => $ch_app ? 'app' : '', 'email' => $ch_email ? 'email' : '']));

    // ── Build recipient query ──────────────────────────────
    $where    = [];
    $params   = [];
    $recipients = [];
    if ($target_type !== 'external') {
        if ($target_type !== 'role') {
            // Default: alumni only
            $where[] = "role = 'alumni'";
        }
        if ($target_type === 'major' && $target_value) {
            $where[] = 'major = ?';
            $params[] = $target_value;
        } elseif ($target_type === 'year' && is_numeric($target_value)) {
            $where[] = 'graduation_year = ?';
            $params[] = (int)$target_value;
        }

        $where_sql  = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $rec_stmt   = $pdo->prepare("SELECT id, name, email, email_notifications FROM users $where_sql");
        $rec_stmt->execute($params);
        $recipients = $rec_stmt->fetchAll();
    }

    $app_count   = 0;
    $queued_count= 0;

    try {
        // 1. Save broadcast record
        $ins = $pdo->prepare(
            "INSERT INTO broadcasts (title, message, body_html, channels, target_filter, priority, sent_by, recipient_count, email_count, external_count, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?, NOW())"
        );
        $ins->execute([$title, $plain_text, $body_html, $channels_str, $target_filter, $priority, $_SESSION['user_id'], $external_count]);
        $broadcast_id = $pdo->lastInsertId();

        // 2. Handle uploaded attachments ────────────────────
        $attach_dir   = __DIR__ . '/../uploads/broadcast_attachments/';
        $attach_paths = [];  // array of ['original', 'stored', 'path', 'mime']

        if (!empty($_FILES['attachments']['name'][0])) {
            if (!is_dir($attach_dir)) {
                mkdir($attach_dir, 0755, true);
            }
            $att_count = count($_FILES['attachments']['name']);
            for ($i = 0; $i < $att_count; $i++) {
                $orig_name = $_FILES['attachments']['name'][$i];
                $tmp_path  = $_FILES['attachments']['tmp_name'][$i];
                $mime      = $_FILES['attachments']['type'][$i];
                $size      = $_FILES['attachments']['size'][$i];

                if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
                if ($size > 10 * 1024 * 1024) continue; // 10MB max per file

                // Sanitize filename
                $safe_ext  = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
                $allowed   = ['pdf','doc','docx','xls','xlsx','ppt','pptx','jpg','jpeg','png','gif','zip','txt','csv'];
                if (!in_array($safe_ext, $allowed)) continue;

                $stored_name = uniqid('bcast_', true) . '.' . $safe_ext;
                $dest        = $attach_dir . $stored_name;

                if (move_uploaded_file($tmp_path, $dest)) {
                    // Save to DB
                    $pdo->prepare("INSERT INTO broadcast_attachments (broadcast_id, original_name, stored_name, file_path, file_size, mime_type) VALUES (?,?,?,?,?,?)")
                        ->execute([$broadcast_id, $orig_name, $stored_name, $dest, $size, $mime]);

                    $attach_paths[] = ['original' => $orig_name, 'path' => $dest, 'mime' => $mime];
                }
            }
        }

        // 3. Send In-App Notifications ──────────────────────
        if ($ch_app) {
            $notif_icon = ['normal' => 'info', 'info' => 'info', 'urgent' => 'danger'];
            $type_str   = $notif_icon[$priority] ?? 'info';
            $prefix     = $priority === 'urgent' ? '🔴 ' : '';
            
            $log_app = $pdo->prepare("INSERT INTO email_delivery_log (broadcast_id, queue_id, to_email, to_name, is_external, channel, status) VALUES (?, NULL, ?, ?, 0, 'app', 'sent')");

            foreach ($recipients as $r) {
                add_notification(
                    $r->id,
                    $prefix . $title,
                    $plain_text,
                    $type_str,
                    'index.php?page=notifications'
                );
                $app_count++;
                
                $log_app->execute([$broadcast_id, $r->email ?? $r->id, $r->name]);
            }
        }

        // 4. Queue or Send Emails ───────────────────────────
        if ($ch_email || $external_count > 0) {
            // Fetch settings from DB
            $stmt_settings = $pdo->query("SELECT setting_key, setting_value FROM settings");
            $sys_settings = [];
            foreach ($stmt_settings->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sys_settings[$row['setting_key']] = $row['setting_value'];
            }
            $email_send_direct = ($sys_settings['email_send_direct'] ?? '0') === '1';

            // Build full HTML email (wrap body in a container if it's just inline content)
            $full_html = build_email_html($title, $body_html);

            // Re-format attachments for PHPMailer
            $att_files = [];
            foreach ($attach_paths as $a) {
                if (file_exists($a['path'])) {
                    $att_files[] = ['path' => $a['path'], 'name' => $a['original']];
                }
            }

            if ($email_send_direct) {
                // A. Process DB Recipients (Direct Send)
                if ($ch_email) {
                    foreach ($recipients as $r) {
                        if (empty($r->email) || !$r->email_notifications) continue;
                        
                        // Check if user has unsubscribed
                        $unsub_check = $pdo->prepare("SELECT id FROM unsubscribes WHERE email = ?");
                        $unsub_check->execute([$r->email]);
                        if ($unsub_check->rowCount() > 0) continue;

                        $sent_ok = send_html_email($r->email, $r->name, $title, $full_html, $att_files);
                        $status_str = $sent_ok ? 'sent' : 'failed';
                        $err_str = $sent_ok ? NULL : 'SMTP direct delivery failed';
                        if ($sent_ok) {
                            $queued_count++;
                        }
                        $log_ins = $pdo->prepare("INSERT INTO email_delivery_log (broadcast_id, queue_id, to_email, to_name, is_external, channel, status, error_message) VALUES (?, NULL, ?, ?, 0, 'email', ?, ?)");
                        $log_ins->execute([$broadcast_id, $r->email, $r->name, $status_str, $err_str]);
                    }
                }
                
                // B. Process External Recipients (Direct Send)
                foreach ($valid_external_emails as $ext_email) {
                    // Check if user has unsubscribed
                    $unsub_check = $pdo->prepare("SELECT id FROM unsubscribes WHERE email = ?");
                    $unsub_check->execute([$ext_email]);
                    if ($unsub_check->rowCount() > 0) continue;
                    
                    $sent_ok = send_html_email($ext_email, '', $title, $full_html, $att_files);
                    $status_str = $sent_ok ? 'sent' : 'failed';
                    $err_str = $sent_ok ? NULL : 'SMTP direct delivery failed';
                    if ($sent_ok) {
                        $queued_count++;
                    }
                    $log_ins = $pdo->prepare("INSERT INTO email_delivery_log (broadcast_id, queue_id, to_email, to_name, is_external, channel, status, error_message) VALUES (?, NULL, ?, '', 1, 'email', ?, ?)");
                    $log_ins->execute([$broadcast_id, $ext_email, $status_str, $err_str]);
                }
            } else {
                // Queue Emails (async — cron worker processes them)
                $q_ins = $pdo->prepare(
                    "INSERT INTO email_queue (to_email, to_name, subject, body_html, broadcast_id)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $log_ins = $pdo->prepare(
                    "INSERT INTO email_delivery_log (broadcast_id, queue_id, to_email, to_name, is_external, channel, status)
                     VALUES (?, ?, ?, ?, ?, 'email', 'queued')"
                );

                // A. Process DB Recipients (Queue)
                if ($ch_email) {
                    foreach ($recipients as $r) {
                        if (empty($r->email) || !$r->email_notifications) continue;
                        
                        // Check if user has unsubscribed
                        $unsub_check = $pdo->prepare("SELECT id FROM unsubscribes WHERE email = ?");
                        $unsub_check->execute([$r->email]);
                        if ($unsub_check->rowCount() > 0) continue;

                        $q_ins->execute([
                            $r->email,
                            $r->name,
                            $title,
                            $full_html,
                            $broadcast_id,
                        ]);
                        $queue_id = $pdo->lastInsertId();
                        
                        $log_ins->execute([$broadcast_id, $queue_id, $r->email, $r->name, 0]);
                        $queued_count++;
                    }
                }
                
                // B. Process External Recipients (Queue)
                foreach ($valid_external_emails as $ext_email) {
                    // Check if user has unsubscribed
                    $unsub_check = $pdo->prepare("SELECT id FROM unsubscribes WHERE email = ?");
                    $unsub_check->execute([$ext_email]);
                    if ($unsub_check->rowCount() > 0) continue;
                    
                    $q_ins->execute([
                        $ext_email,
                        '', // No name
                        $title,
                        $full_html,
                        $broadcast_id,
                    ]);
                    $queue_id = $pdo->lastInsertId();
                    
                    $log_ins->execute([$broadcast_id, $queue_id, $ext_email, '', 1]);
                    $queued_count++;
                }
            }
        }

        // 5. Update counts ──────────────────────────────────
        $pdo->prepare("UPDATE broadcasts SET recipient_count = ?, email_count = ? WHERE id = ?")
            ->execute([$app_count, $queued_count, $broadcast_id]);

        // 6. Audit log ──────────────────────────────────────
        log_activity('BROADCAST_SENT', "Broadcast \"$title\" | Channels: $channels_str | Target: $target_filter | App: $app_count | Email queued: $queued_count | Attachments: " . count($attach_paths));

        header("Location: ../index.php?page=admin_broadcast&success=sent&app=$app_count&queued=$queued_count");
        exit();

    } catch (PDOException $e) {
        error_log("Broadcast Error: " . $e->getMessage());
        header('Location: ../index.php?page=admin_broadcast&error=db_error');
        exit();
    }
}

// ══════════════════════════════════════════════════════════
//  ACTION: DELETE BROADCAST
// ══════════════════════════════════════════════════════════
if ($action === 'delete') {
    if (!isset($_GET['csrf_token']) || !verify_csrf_token($_GET['csrf_token'])) {
        http_response_code(403);
        die('Forbidden (Invalid CSRF Token)');
    }
    $id = intval($_GET['id'] ?? 0);
    if ($id) {
        try {
            // Also delete attachments from disk
            $atts = $pdo->prepare("SELECT file_path FROM broadcast_attachments WHERE broadcast_id = ?");
            $atts->execute([$id]);
            foreach ($atts->fetchAll() as $a) {
                if (file_exists($a->file_path)) unlink($a->file_path);
            }
            $pdo->prepare("DELETE FROM broadcast_attachments WHERE broadcast_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM email_queue WHERE broadcast_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM broadcasts WHERE id = ?")->execute([$id]);
            log_activity('BROADCAST_DELETED', "Broadcast ID $id dihapus.");
        } catch (PDOException $e) {
            error_log("Delete broadcast error: " . $e->getMessage());
        }
        header('Location: ../index.php?page=admin_broadcast&success=deleted');
        exit();
    }
}

header('Location: ../index.php?page=admin_broadcast');
exit();

// ══════════════════════════════════════════════════════════
//  HELPER: Wrap user HTML in a proper email envelope
// ══════════════════════════════════════════════════════════
function build_email_html(string $subject, string $body_html): string {
    // If the body already looks like a full email template (has max-width div), return as-is
    if (stripos($body_html, 'max-width:600px') !== false) {
        return $body_html;
    }

    // Otherwise wrap in a clean envelope
    return '<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . htmlspecialchars($subject) . '</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;">
<div style="max-width:600px;margin:24px auto;background:white;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.07);">
<div style="background:linear-gradient(135deg,#6366f1 0%,#8b5cf6 100%);padding:20px 28px;">
<span style="color:white;font-size:18px;font-weight:800;letter-spacing:-0.3px;">Alumni<span style="opacity:.8;">Link</span></span>
</div>
<div style="padding:32px 28px;">' . $body_html . '</div>
<div style="border-top:1px solid #e2e8f0;padding:16px 28px;background:#f8fafc;text-align:center;">
<p style="font-size:12px;color:#94a3b8;margin:0;">AlumniLink &mdash; Portal Alumni Resmi &nbsp;&bull;&nbsp; <a href="#" style="color:#6366f1;text-decoration:none;">Kelola Preferensi Email</a></p>
</div>
</div>
</body></html>';
}
