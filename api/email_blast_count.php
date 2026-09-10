<?php
/**
 * api/email_blast_count.php
 * Counts eligible email blast recipients dynamically based on filters.
 */
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/auth_guard.php';
// Penjagaan berbasis kapabilitas menggantikan pola blacklist lama.
// Selama settings.rbac_enforce masih '0', pelanggaran hanya DICATAT ke
// Audit Trail sebagai 'RBAC_AUDIT' dan akses tetap diizinkan.
require_capability('broadcast.kirim');

header('Content-Type: application/json');

// Admin check
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'alumni') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$filter_role = $_GET['filter_role'] ?? 'alumni';
$filter_major = $_GET['filter_major'] ?? '';
$filter_graduation_year = $_GET['filter_graduation_year'] ?? '';

try {
    $where_conditions = [];
    $params = [];

    // Base criteria
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

    // Alamat yang berhenti berlangganan atau sudah ditandai mati tidak akan
    // dikirimi. Menghitungnya di sini membuat angka yang dilihat admin —
    // dan perkiraan waktu yang diturunkan darinya — sama dengan kenyataan.
    // Penyaring yang sama ada di handlers/admin_broadcast_handler.php.
    $where_conditions[] = "NOT EXISTS (SELECT 1 FROM unsubscribes s WHERE s.email = users.email)";

    $where_sql = implode(' AND ', $where_conditions);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $where_sql");
    $stmt->execute($params);
    $count = (int)$stmt->fetchColumn();

    echo json_encode(['success' => true, 'count' => $count]);

} catch (PDOException $e) {
    error_log("Email Blast Count API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error', 'count' => 0]);
}
?>
