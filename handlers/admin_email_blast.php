<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once '../includes/csrf.php';
require_once '../includes/mailer.php';
require_once '../includes/rate_limit.php';

// Admin check — only super_admin can use email blast
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['super_admin'])) {
    error_forbidden('Hanya Super Admin yang dapat menggunakan fitur Email Blast.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../index.php?page=admin_email_blast");
    exit();
}

validate_csrf();

// Rate limit: max 2 blasts per 30 minutes
check_rate_limit('EMAIL_BLAST', 2, 30);

$subject = trim($_POST['subject'] ?? '');
$content = trim($_POST['content'] ?? '');
$filter_role = $_POST['filter_role'] ?? 'alumni';
$filter_major = $_POST['filter_major'] ?? '';
$filter_graduation_year = $_POST['filter_graduation_year'] ?? '';

// Validation
if (empty($subject) || empty($content)) {
    header("Location: ../index.php?page=admin_email_blast&error=missing_fields");
    exit();
}

// Sanitize content — allow basic HTML from textarea but escape dangerous tags
// @CRITICAL-PII-DATA: Content is user-supplied; strip script/iframe tags
$content = preg_replace('/<\s*(script|iframe|object|embed|form)[^>]*>.*?<\s*\/\s*\1\s*>/si', '', $content);
$content = strip_tags($content, '<p><br><strong><em><b><i><ul><ol><li><a><h2><h3><h4><blockquote>');

try {
    // Build dynamic query based on filters
    $where_conditions = [];
    $params = [];

    // Base: only users with email_notifications = 1
    $where_conditions[] = "email_notifications = 1";
    $where_conditions[] = "email IS NOT NULL";
    $where_conditions[] = "email != ''";

    if (!empty($filter_role) && $filter_role !== 'all') {
        $where_conditions[] = "role = ?";
        $params[] = $filter_role;
    }

    if (!empty($filter_major)) {
        $where_conditions[] = "major = ?";
        $params[] = $filter_major;
    }

    if (!empty($filter_graduation_year) && is_numeric($filter_graduation_year)) {
        $where_conditions[] = "graduation_year = ?";
        $params[] = (int)$filter_graduation_year;
    }

    $where_sql = implode(' AND ', $where_conditions);
    $stmt = $pdo->prepare("SELECT id, email, name FROM users WHERE $where_sql");
    $stmt->execute($params);
    $recipients = $stmt->fetchAll();

    $sent_count = 0;
    $fail_count = 0;

    foreach ($recipients as $r) {
        if (!empty($r->email)) {
            $result = send_blast_email($r->email, $r->name ?? 'Alumni', $subject, $content);
            if ($result) {
                $sent_count++;
            } else {
                $fail_count++;
            }
        }
    }

    // Log the blast to email_blasts history table
    $log_stmt = $pdo->prepare("INSERT INTO email_blasts (subject, content, filter_role, filter_major, filter_graduation_year, recipient_count, sent_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $log_stmt->execute([
        $subject,
        $content,
        $filter_role ?: null,
        $filter_major ?: null,
        $filter_graduation_year ?: null,
        $sent_count,
        $_SESSION['user_id']
    ]);

    // Audit log
    log_activity('EMAIL_BLAST', "Super Admin mengirim email blast \"$subject\" ke $sent_count penerima" . ($fail_count > 0 ? " ($fail_count gagal)" : ""));
    reset_rate_limit('EMAIL_BLAST');

    header("Location: ../index.php?page=admin_email_blast&success=sent&count=$sent_count&fail=$fail_count");
    exit();

} catch (PDOException $e) {
    error_log("Email Blast Handler Error: " . $e->getMessage());
    error_system('Terjadi kesalahan sistem saat mengirim email blast. Silakan hubungi administrator.');
}
?>
