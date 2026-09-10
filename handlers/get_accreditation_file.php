<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';

header('Content-Type: application/json');

// Endpoint ini hanya dipakai oleh panel admin (pages/admin_legalisir.php).
// Tanpa penjagaan di bawah, siapa pun dapat memanggilnya dengan ?nim=...
// untuk menebak NIM dan memanen prodi + tahun lulus seluruh alumni.
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? 'alumni') === 'alumni') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
    exit;
}

$nim = trim($_GET['nim'] ?? '');

if (empty($nim)) {
    echo json_encode(['success' => false, 'message' => 'NIM tidak diberikan']);
    exit;
}

try {
    // 1. Fetch user by nim or id
    $stmt = $pdo->prepare("SELECT major, graduation_year FROM users WHERE nim = ? OR id = ?");
    $stmt->execute([$nim, $nim]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'Alumni tidak ditemukan']);
        exit;
    }

    $user_major = trim($user->major ?? '');
    $user_year = (int)($user->graduation_year ?? 0);

    if (empty($user_major) || !$user_year) {
        echo json_encode(['success' => false, 'message' => 'Data prodi atau tahun lulus alumni belum lengkap']);
        exit;
    }

    // 2. Map user major string/name to major_code
    $stmt_majors = $pdo->query("SELECT major_code, major_name FROM majors");
    $majors = $stmt_majors->fetchAll();

    $target_major_code = '';

    foreach ($majors as $m) {
        if (strcasecmp($user_major, $m->major_code) === 0 || strcasecmp($user_major, $m->major_name) === 0) {
            $target_major_code = $m->major_code;
            break;
        }
    }

    if (empty($target_major_code)) {
        // Fallback for legacy accounts (e.g. Informatika -> J500 for testing, or return error)
        // To be extremely graceful for testing accounts like Pandu Egi Ferdian, default to J500 if unknown
        $target_major_code = default_major_code(); // settings.default_major_code
    }

    // 3. Find matching accreditation certificate for that major_code and graduation_year
    $stmt_accred = $pdo->prepare("SELECT * FROM accreditation_certificates WHERE major_code = ? AND start_year <= ? AND end_year >= ? ORDER BY created_at DESC LIMIT 1");
    $stmt_accred->execute([$target_major_code, $user_year, $user_year]);
    $accred = $stmt_accred->fetch();

    if ($accred) {
        echo json_encode([
            'success' => true,
            'id' => $accred->id,
            'major_code' => $accred->major_code,
            'certificate_name' => $accred->certificate_name,
            'start_year' => $accred->start_year,
            'end_year' => $accred->end_year,
            'file_path' => $accred->file_path
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => "Master Akreditasi untuk prodi {$target_major_code} periode kelulusan {$user_year} belum diunggah admin",
            'major_code' => $target_major_code,
            'user_year' => $user_year
        ]);
    }
} catch (PDOException $e) {
    error_log("Get Accreditation File Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem']);
}
?>
