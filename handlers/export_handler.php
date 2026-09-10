<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once __DIR__ . '/../includes/auth_guard.php';
// Penjagaan berbasis kapabilitas menggantikan pola blacklist lama.
// Selama settings.rbac_enforce masih '0', pelanggaran hanya DICATAT ke
// Audit Trail sebagai 'RBAC_AUDIT' dan akses tetap diizinkan.
require_capability('laporan.ekspor');

// Admin check
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'alumni') {
    error_forbidden();
}

$type = $_GET['type'] ?? '';
$filename = "export_" . $type . "_" . date('Ymd_His') . ".csv";

if ($type === 'alumni') {
    $stmt = $pdo->query("SELECT u.nim, u.name, u.email, u.graduation_year, COALESCE(m.major_name, u.major) AS major, u.ipk, u.phone, u.address FROM users u LEFT JOIN majors m ON u.major = m.major_code WHERE u.role = 'alumni' ORDER BY u.graduation_year DESC, u.name ASC");
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $header = ['NIM', 'Nama', 'Email', 'Tahun Lulus', 'Prodi', 'IPK', 'Telepon', 'Alamat'];
} 
elseif ($type === 'tracer') {
    $search = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? '';
    $relevance = $_GET['relevance'] ?? '';
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';

    // Penyaring kohort — tanpa ini CSV tidak dapat dipivot per angkatan.
    $graduation_year = $_GET['graduation_year'] ?? '';
    $major           = $_GET['major'] ?? '';

    // 1. Hanya pertanyaan AKTIF yang dijadikan kolom.
    //    Sebelumnya pertanyaan yang sudah dinonaktifkan tetap memunculkan
    //    kolom kosong di setiap ekspor.
    $stmt_q = $pdo->query("SELECT id, question_text FROM tracer_questions WHERE is_active = 1 ORDER BY order_no ASC");
    $questions = $stmt_q->fetchAll();

    // Kolom Tahun Lulus & Program Studi ditambahkan supaya hasil ekspor bisa
    // langsung dipivot per kohort di Excel tanpa VLOOKUP ke berkas lain.
    $header = ['Tanggal', 'Nama Alumni', 'NIM', 'Email', 'Tahun Lulus', 'Program Studi'];
    foreach($questions as $q) {
        $header[] = $q->question_text;
    }

    // 2. Build Filtered Query
    $query = "
        SELECT ts.created_at, u.name, u.nim, u.email,
               u.graduation_year, COALESCE(m.major_name, u.major) AS major_name,
               ts.responses
        FROM tracer_submissions ts
        JOIN users u ON ts.user_id = u.id
        LEFT JOIN majors m ON u.major = m.major_code
        WHERE 1=1
    ";
    $params = [];
    if ($search) {
        $query .= " AND (u.name LIKE ? OR u.nim LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($status) {
        $query .= " AND ts.work_status = ?";
        $params[] = $status;
    }
    if ($relevance) {
        $query .= " AND ts.field_relevance = ?";
        $params[] = $relevance;
    }
    if ($start_date) {
        $query .= " AND DATE(ts.created_at) >= ?";
        $params[] = $start_date;
    }
    if ($end_date) {
        $query .= " AND DATE(ts.created_at) <= ?";
        $params[] = $end_date;
    }
    if ($graduation_year) {
        $query .= " AND u.graduation_year = ?";
        $params[] = (int)$graduation_year;
    }
    if ($major) {
        $query .= " AND u.major = ?";
        $params[] = $major;
    }
    $query .= " ORDER BY ts.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $submissions = $stmt->fetchAll();

    $data = [];
    foreach($submissions as $s) {
        $row = [
            date('d/m/Y H:i', strtotime($s->created_at)),
            $s->name,
            $s->nim ?? '-',
            $s->email,
            $s->graduation_year ?? '-',
            $s->major_name ?? '-'
        ];
        $resp = json_decode($s->responses, true) ?? [];
        foreach($questions as $q) {
            $key = 'q_' . $q->id;
            $val = $resp[$key] ?? '-';

            // PERBAIKAN BUG: jawaban checkbox tersimpan sebagai larik JSON.
            // Sebelumnya larik itu masuk langsung ke fputcsv dan tercetak
            // sebagai teks "Array" disertai PHP warning.
            if (is_array($val)) {
                $val = implode('; ', $val);
            }

            $row[] = ($val === '' || $val === null) ? '-' : $val;
        }
        $data[] = $row;
    }
} 
elseif ($type === 'donations') {
    $stmt = $pdo->query("
        SELECT d.created_at, d.donor_name, c.title as campaign, d.amount, d.status, d.midtrans_order_id, d.message
        FROM donations d 
        JOIN donation_campaigns c ON d.campaign_id = c.id 
        ORDER BY d.created_at DESC
    ");
    $raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $data = [];
    foreach($raw_data as $row) {
        $data[] = [
            date('d/m/Y H:i', strtotime($row['created_at'])),
            $row['donor_name'],
            $row['campaign'],
            (int)$row['amount'],
            strtoupper($row['status']),
            $row['midtrans_order_id'],
            $row['message']
        ];
    }
    $header = ['Tanggal', 'Nama Donatur', 'Program Donasi', 'Nominal', 'Status', 'Order ID', 'Pesan/Doa'];
} 
else {
    render_error_page('Jenis Ekspor Tidak Dikenal', 'Permintaan ekspor tidak dikenali sistem. Silakan ulangi dari halaman terkait.', 400, 'file-x');
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel UTF-8

fputcsv($output, $header, ',', '"', '\\');
foreach ($data as $row) {
    fputcsv($output, array_values($row), ',', '"', '\\');
}

fclose($output);
exit();
