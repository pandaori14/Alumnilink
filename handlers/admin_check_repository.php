<?php
require_once '../config/db.php';
require_once __DIR__ . '/../includes/auth_guard.php';
// Penjagaan berbasis kapabilitas menggantikan pola blacklist lama.
// Selama settings.rbac_enforce masih '0', pelanggaran hanya DICATAT ke
// Audit Trail sebagai 'RBAC_AUDIT' dan akses tetap diizinkan.
require_capability('repositori.kelola');
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../includes/session_boot.php';
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'alumni') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$nim = trim(strtolower($_GET['nim'] ?? ''));
if (!$nim) {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare("SELECT document_type, file_path FROM document_repository WHERE LOWER(nim) = ?");
$stmt->execute([$nim]);
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($docs);
?>
