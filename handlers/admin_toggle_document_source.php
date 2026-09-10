<?php
require_once '../config/db.php';
require_once __DIR__ . '/../includes/auth_guard.php';
// Penjagaan berbasis kapabilitas menggantikan pola blacklist lama.
// Selama settings.rbac_enforce masih '0', pelanggaran hanya DICATAT ke
// Audit Trail sebagai 'RBAC_AUDIT' dan akses tetap diizinkan.
require_capability('legalisir.kelola');
require_once '../includes/logger.php';
require_once '../includes/csrf.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../includes/session_boot.php';
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'alumni') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

validate_csrf();

$req_id = $_POST['req_id'] ?? '';
$nim = trim(strtolower($_POST['nim'] ?? ''));
$doc_type = $_POST['doc_type'] ?? '';
$target_source = $_POST['target_source'] ?? ''; // 'alumni' or 'repository'

if (!$req_id || !$nim || !$doc_type || !in_array($target_source, ['alumni', 'repository'])) {
    echo json_encode(['success' => false, 'error' => 'Missing or invalid parameters']);
    exit;
}

// Fetch current request documents
$stmt = $pdo->prepare("SELECT documents FROM legalisir_requests WHERE id = ?");
$stmt->execute([$req_id]);
$req = $stmt->fetch();

if (!$req) {
    echo json_encode(['success' => false, 'error' => 'Request not found']);
    exit;
}

$docs = json_decode($req->documents, true) ?: [];
$updated = false;

// Normalize target doc type
$clean_target_type = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $doc_type)));

foreach ($docs as &$doc) {
    $type = is_array($doc) ? ($doc['type'] ?? ($doc['id'] ?? '')) : (is_object($doc) ? ($doc->type ?? ($doc->id ?? '')) : '');
    $clean_type = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $type)));
    
    if ($clean_type === $clean_target_type) {
        if (is_object($doc)) {
            $doc = json_decode(json_encode($doc), true); // convert object to array for easy manipulation
        } elseif (!is_array($doc)) {
            $doc = ['type' => $clean_type, 'file' => $doc, 'source' => 'alumni'];
        }

        // Preserve original alumni file if not already preserved
        if (!isset($doc['alumni_file'])) {
            $doc['alumni_file'] = $doc['file'] ?? '';
        }

        if ($target_source === 'repository') {
            // Fetch repo path with strict prioritization
            $stmt = $pdo->prepare("SELECT file_path FROM document_repository WHERE LOWER(nim) = ? AND (document_type = ? OR document_type LIKE ? OR ? LIKE CONCAT('%', document_type, '%')) ORDER BY CASE WHEN document_type = ? THEN 1 WHEN document_type LIKE ? THEN 2 ELSE 3 END ASC LIMIT 1");
            $stmt->execute([$nim, $clean_target_type, '%' . $clean_target_type . '%', $clean_target_type, $clean_target_type, '%' . $clean_target_type . '%']);
            $repo = $stmt->fetch();
            
            if ($repo) {
                $doc['file'] = $repo->file_path;
                $doc['source'] = 'repository';
                $updated = true;
            } else {
                echo json_encode(['success' => false, 'error' => 'Dokumen repositori tidak ditemukan untuk tipe ini']);
                exit;
            }
        } elseif ($target_source === 'alumni') {
            if (!empty($doc['alumni_file'])) {
                $doc['file'] = $doc['alumni_file'];
                $doc['source'] = 'alumni';
                $updated = true;
            } else {
                echo json_encode(['success' => false, 'error' => 'File asli alumni tidak ditemukan']);
                exit;
            }
        }
        break;
    }
}

if ($updated) {
    $new_docs_json = json_encode($docs);
    $stmt = $pdo->prepare("UPDATE legalisir_requests SET documents = ? WHERE id = ?");
    $stmt->execute([$new_docs_json, $req_id]);
    
    log_activity('Toggle Doc Source', "Switched doc $clean_target_type to $target_source for Request: $req_id");
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Tidak ada perubahan pada dokumen']);
}
?>
