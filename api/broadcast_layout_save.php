<?php
/**
 * api/broadcast_layout_save.php
 * Save a custom email layout to the broadcast_layouts table.
 */
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/auth_guard.php';
// Penjagaan berbasis kapabilitas menggantikan pola blacklist lama.
// Selama settings.rbac_enforce masih '0', pelanggaran hanya DICATAT ke
// Audit Trail sebagai 'RBAC_AUDIT' dan akses tetap diizinkan.
require_capability('broadcast.kirim');
require_once '../includes/csrf.php';

header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'alumni') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// CSRF
try {
    validate_csrf();
} catch (Exception $e) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$name         = trim($_POST['name']         ?? '');
$html_content = trim($_POST['html_content'] ?? '');
$thumb_type   = 'simple'; // Default thumbnail

if (empty($name)) {
    echo json_encode(['error' => 'Nama layout tidak boleh kosong.']);
    exit();
}
if (empty($html_content)) {
    echo json_encode(['error' => 'Konten layout tidak boleh kosong.']);
    exit();
}
if (mb_strlen($html_content) > 500000) {
    echo json_encode(['error' => 'Konten terlalu besar.']);
    exit();
}

// Check user's layout count (max 20)
try {
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM broadcast_layouts WHERE created_by = ?");
    $cnt->execute([$_SESSION['user_id']]);
    if ($cnt->fetchColumn() >= 20) {
        echo json_encode(['error' => 'Maks. 20 layout tersimpan per akun. Hapus beberapa layout lama.']);
        exit();
    }

    $ins = $pdo->prepare(
        "INSERT INTO broadcast_layouts (name, html_content, thumb_type, is_default, created_by) VALUES (?,?,?,0,?)"
    );
    $ins->execute([$name, $html_content, $thumb_type, $_SESSION['user_id']]);
    $new_id = $pdo->lastInsertId();

    echo json_encode(['success' => true, 'id' => $new_id, 'name' => $name]);

} catch (PDOException $e) {
    error_log("Layout save error: " . $e->getMessage());
    echo json_encode(['error' => 'Database error.']);
}
