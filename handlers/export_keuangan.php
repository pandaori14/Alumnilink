<?php
/**
 * Ekspor Laporan Keuangan ke CSV.
 *
 * Penyaring dan baris dibangun oleh fungsi yang SAMA dengan halaman Laporan
 * Keuangan (includes/payment/report.php), sehingga berkas yang diunduh tidak
 * mungkin berbeda dari angka yang dilihat di layar. Dulu keduanya menulis
 * kueri sendiri-sendiri, dan keduanya hanya menghitung legalisir.
 */
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once __DIR__ . '/../includes/auth_guard.php';
// Penjagaan berbasis kapabilitas menggantikan pola blacklist lama.
// Selama settings.rbac_enforce masih '0', pelanggaran hanya DICATAT ke
// Audit Trail sebagai 'RBAC_AUDIT' dan akses tetap diizinkan.
require_capability('keuangan.lihat');
require_once __DIR__ . '/../includes/payment/report.php';

// Guard: Admin Only
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] === 'alumni') {
    error_forbidden();
}

$records = payment_finance_rows($pdo, [
    'jenis'      => $_GET['jenis'] ?? 'semua',
    'start_date' => $_GET['start_date'] ?? '',
    'end_date'   => $_GET['end_date'] ?? '',
    'status'     => in_array($_GET['status'] ?? '', ['settlement', 'pending', 'failed'], true) ? $_GET['status'] : '',
    'method'     => in_array($_GET['method'] ?? '', ['midtrans', 'flip', 'cash'], true) ? $_GET['method'] : '',
    'month'      => preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : '',
]);
$ringkas = payment_finance_summary($records);

// Output CSV Headers
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="Laporan_Keuangan_AlumniLink_' . date('Ymd_His') . '.csv"');

// Clean buffer to avoid unexpected output
if (ob_get_length()) ob_clean();

$out = fopen('php://output', 'w');
// BOM for Excel UTF-8
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($out, ['No', 'Jenis', 'ID', 'Tanggal', 'Nama', 'NIM/E-mail', 'Keterangan', 'Metode Bayar',
               'Status Bayar', 'Dibayar (Rp)', 'Biaya Layanan (Rp)', 'Diterima Fakultas (Rp)'], ',', '"', '\\');

$label_bayar = ['settlement' => 'LUNAS', 'pending' => 'PENDING', 'failed' => 'GAGAL'];
$no = 1;
foreach ($records as $r) {
    fputcsv($out, [
        $no++,
        ucfirst($r['jenis']),
        $r['id'],
        date('d/m/Y H:i', strtotime($r['created_at'])),
        $r['nama'],
        $r['identitas'],
        $r['keterangan'],
        payment_method_report_label($r['metode']),
        $label_bayar[$r['bayar']] ?? strtoupper((string)$r['bayar']),
        number_format($r['tagihan'], 0, ',', '.'),
        // Baris lama tanpa rincian tidak ditebak angkanya; ditulis apa adanya
        // supaya pembaca berkas tahu mana yang pasti dan mana yang batas atas.
        $r['lunas'] ? ($r['pasti'] ? number_format($r['biaya'], 0, ',', '.') : 'tanpa rincian') : '-',
        // Yang belum lunas belum menghasilkan apa pun. Menuliskan nominal
        // tagihannya di kolom "diterima" akan dibaca sebagai uang yang masuk.
        $r['lunas'] ? number_format($r['diterima'], 0, ',', '.') : '-'
    ], ',', '"', '\\');
}

// Baris total: hanya yang LUNAS, sama dengan kartu di halaman.
fputcsv($out, ['', '', '', '', '', '', 'TOTAL LUNAS', '', '',
               number_format($ringkas['bruto'], 0, ',', '.'),
               number_format($ringkas['biaya'], 0, ',', '.'),
               number_format($ringkas['neto'], 0, ',', '.')], ',', '"', '\\');

fclose($out);
exit();
