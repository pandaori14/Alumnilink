<?php
require_once '../config/db.php';
require_once __DIR__ . '/../includes/auth_guard.php';
// Penjagaan berbasis kapabilitas menggantikan pola blacklist lama.
// Selama settings.rbac_enforce masih '0', pelanggaran hanya DICATAT ke
// Audit Trail sebagai 'RBAC_AUDIT' dan akses tetap diizinkan.
require_capability('repositori.kelola');
require_once '../includes/logger.php';

require_once __DIR__ . '/../includes/session_boot.php';
require_once '../includes/csrf.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'alumni') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Handler ini menimpa berkas dokumen pada pengajuan legalisir mana pun.
// Pencarian menyeluruh menunjukkan TIDAK ADA satu pun halaman atau skrip yang
// memanggilnya -- kode ini sudah tidak terpakai, namun tetap dapat di-POST
// langsung dari luar selama berkasnya masih ada di server.
validate_csrf_request(true);

$req_id = $_POST['req_id'] ?? '';
$nim = $_POST['nim'] ?? '';

if (!$req_id || !$nim) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

// Get the existing request documents
$stmt = $pdo->prepare("SELECT documents FROM legalisir_requests WHERE id = ?");
$stmt->execute([$req_id]);
$req = $stmt->fetch();

if (!$req) {
    echo json_encode(['success' => false, 'error' => 'Request not found']);
    exit;
}

$docs = json_decode($req->documents, true) ?: [];

// Get repository documents for this NIM
$stmt = $pdo->prepare("SELECT document_type, file_path FROM document_repository WHERE nim = ?");
$stmt->execute([$nim]);
$repo_docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($repo_docs)) {
    echo json_encode(['success' => false, 'error' => 'No repository documents found for this NIM']);
    exit;
}

// Create a map of repo docs by type
$repo_map = [];
foreach ($repo_docs as $rd) {
    $repo_map[$rd['document_type']] = $rd['file_path'];
}

$updated = false;
// Loop through user docs and replace if repo doc exists for the same type
foreach ($docs as &$doc) {
    $type = is_array($doc) ? ($doc['type'] ?? $doc['id']) : (is_object($doc) ? ($doc->type ?? $doc->id) : null);
    if (!$type && is_string($doc)) {
        // Fallback for older string-based formats
        $type = (stripos($doc, 'ijazah') !== false) ? 'ijazah' : 'transkrip';
    }
    
    // Normalize type string
    $clean_type = strtolower(str_replace(' ', '_', $type));
    
    if (isset($repo_map[$clean_type])) {
        if (is_array($doc)) {
            $doc['file'] = $repo_map[$clean_type];
            $doc['source'] = 'repository';
        } else if (is_object($doc)) {
            $doc->file = $repo_map[$clean_type];
            $doc->source = 'repository';
        } else {
            // Convert string to array
            $doc = ['type' => $clean_type, 'file' => $repo_map[$clean_type], 'source' => 'repository'];
        }
        $updated = true;
    }
}

if ($updated) {
    $new_docs_json = json_encode($docs);
    $stmt = $pdo->prepare("UPDATE legalisir_requests SET documents = ? WHERE id = ?");
    $stmt->execute([$new_docs_json, $req_id]);
    
    log_activity('Update Source', "Replaced user docs with repository docs for Request: $req_id");
    
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'No matching document types found in repository']);
}
?>
