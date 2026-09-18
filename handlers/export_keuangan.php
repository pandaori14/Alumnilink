<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once __DIR__ . '/../includes/auth_guard.php';
// Penjagaan berbasis kapabilitas menggantikan pola blacklist lama.
// Selama settings.rbac_enforce masih '0', pelanggaran hanya DICATAT ke
// Audit Trail sebagai 'RBAC_AUDIT' dan akses tetap diizinkan.
require_capability('keuangan.lihat');

// Guard: Admin Only
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] === 'alumni') {
    error_forbidden();
}

// Get Filters
$start_date = $_GET['start_date'] ?? '';
$end_date   = $_GET['end_date'] ?? '';
$status     = $_GET['status'] ?? '';
$method     = $_GET['method'] ?? '';

// Build WHERE clause
$where = ["1=1"];
$params = [];

if ($start_date) { 
    $where[] = "DATE(lr.created_at) >= ?"; 
    $params[] = $start_date; 
}
if ($end_date) { 
    $where[] = "DATE(lr.created_at) <= ?"; 
    $params[] = $end_date; 
}
if ($status) { 
    $where[] = "lr.payment_status = ?"; 
    $params[] = $status; 
}
// Daftar tertutup, sama dengan pilihan di halaman Laporan Keuangan.
if (in_array($method, ['midtrans', 'flip', 'cash'], true)) {
    $where[] = "lr.payment_method = ?";
    $params[] = $method;
}

$where_sql = implode(' AND ', $where);

require_once __DIR__ . '/../includes/payment/report.php';

// Fetch Data
$stmt = $pdo->prepare("
    SELECT lr.id, lr.created_at, lr.amount, lr.payment_status, lr.payment_method,
           lr.delivery_method, lr.status, lr.tracking_number, lr.documents,
           u.name as alumni_name, u.email as alumni_email, u.nim as alumni_nim
    FROM legalisir_requests lr
    JOIN users u ON lr.user_id = u.id
    WHERE {$where_sql}
    ORDER BY lr.created_at DESC
");
$stmt->execute($params);
$records = $stmt->fetchAll();

// Biaya layanan NYATA per permohonan, dari rincian yang tersimpan saat
// tagihan terbit. Ekspor ini dulu mengurangi potongan tetap `admin_fee` dari
// setiap baris, sehingga angka yang diunduh tidak pernah cocok dengan uang
// yang benar-benar diterima fakultas.
$biaya_baris = payment_fee_by_subject($pdo, 'legalisir', array_map(fn($r) => $r->id, $records));

// Output CSV Headers
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="Laporan_Keuangan_AlumniLink_' . date('Ymd_His') . '.csv"');

// Clean buffer to avoid unexpected output
if (ob_get_length()) ob_clean();

$out = fopen('php://output', 'w');
// BOM for Excel UTF-8
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

// Header Column
fputcsv($out, ['No','ID Pengajuan','Tanggal','Nama Alumni','NIM','Email','Metode Bayar','Status Bayar','Status Dokumen','Metode Pengiriman','No Resi','Dibayar Alumni (Rp)','Biaya Layanan (Rp)','Diterima Fakultas (Rp)'], ',', '"', '\\');

$no = 1;
foreach ($records as $r) {
    $lunas = $r->payment_status === 'settlement';
    $b = $biaya_baris[(string)$r->id] ?? null;
    $fee_baris  = $lunas && $b ? $b['fee'] : 0.0;
    $fee_pasti  = !$lunas || ($b && $b['pasti']);
    $net_amount = max(0, $r->amount - $fee_baris);
    fputcsv($out, [
        $no++,
        $r->id,
        date('d/m/Y H:i', strtotime($r->created_at)),
        $r->alumni_name,
        $r->alumni_nim ?? '-',
        $r->alumni_email,
        payment_method_report_label($r->payment_method),
        strtoupper($r->payment_status),
        ucfirst($r->status),
        ucwords(str_replace('_', ' ', $r->delivery_method)),
        $r->tracking_number ?? '-',
        number_format($r->amount, 0, ',', '.'),
        $fee_pasti ? number_format($fee_baris, 0, ',', '.') : 'tanpa rincian',
        number_format($net_amount, 0, ',', '.')
    ], ',', '"', '\\');
}

fclose($out);
exit();
