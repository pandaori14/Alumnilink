<?php
/**
 * api/broadcast_detail.php
 * Fetch detailed content of a broadcast, including recipient status log for admins.
 */
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';

header('Content-Type: application/json');

// 1. Auth check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing ID']);
    exit();
}

try {
    // 2. Fetch broadcast detail
    $stmt = $pdo->prepare(
        "SELECT b.*, u.name AS sender_name FROM broadcasts b
         LEFT JOIN users u ON b.sent_by = u.id
         WHERE b.id = ?"
    );
    $stmt->execute([$id]);
    $broadcast = $stmt->fetch();

    if (!$broadcast) {
        http_response_code(404);
        echo json_encode(['error' => 'Broadcast not found']);
        exit();
    }

    // 3. Fetch attachments
    $att_stmt = $pdo->prepare("SELECT original_name, stored_name, file_size, mime_type FROM broadcast_attachments WHERE broadcast_id = ?");
    $att_stmt->execute([$id]);
    $attachments = $att_stmt->fetchAll();

    // Response structure
    $response = [
        'id'              => $broadcast->id,
        'title'           => $broadcast->title,
        'message'         => $broadcast->message,
        'body_html'       => $broadcast->body_html,
        'channels'        => $broadcast->channels,
        'target_filter'   => $broadcast->target_filter,
        'priority'        => $broadcast->priority,
        'sender_name'     => $broadcast->sender_name ?: 'Sistem',
        'created_at'      => $broadcast->created_at,
        'recipient_count' => $broadcast->recipient_count,
        'email_count'     => $broadcast->email_count,
        'external_count'  => $broadcast->external_count,
        'attachments'     => $attachments,
    ];

    // 4. Role-based check: If admin, fetch recipient statuses
    $is_admin = ($_SESSION['user_role'] ?? 'alumni') !== 'alumni';
    if ($is_admin) {
        // Fetch detailed logs from email_delivery_log
        $log_stmt = $pdo->prepare(
            "SELECT to_email, to_name, is_external, channel, status, error_message, updated_at 
             FROM email_delivery_log 
             WHERE broadcast_id = ?
             ORDER BY is_external ASC, to_name ASC, to_email ASC"
        );
        $log_stmt->execute([$id]);
        $logs = $log_stmt->fetchAll();
        $response['delivery_logs'] = $logs;
    }

    echo json_encode($response);

} catch (PDOException $e) {
    error_log("Fetch broadcast detail error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
