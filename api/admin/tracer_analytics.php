<?php
/**
 * api/admin/tracer_analytics.php
 * Endpoint for compiling aggregate Tracer Study metrics for charting (ApexCharts).
 */
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../../includes/session_boot.php';
}
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once __DIR__ . '/../../includes/auth_guard.php';
// Penjagaan berbasis kapabilitas menggantikan pola blacklist lama.
// Selama settings.rbac_enforce masih '0', pelanggaran hanya DICATAT ke
// Audit Trail sebagai 'RBAC_AUDIT' dan akses tetap diizinkan.
require_capability('analitik.lihat');

header('Content-Type: application/json');

// 1. Auth check (Only admins/super admins can see analytics)
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'alumni') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// ─────────────────────────────────────────────────────────
// Penyaringan kohort. Sebelumnya endpoint ini tidak menerima parameter apa
// pun sehingga seluruh angka bersifat global -- padahal indikator akreditasi
// selalu dilaporkan per angkatan dan per program studi.
// ─────────────────────────────────────────────────────────
$filters = [
    'graduation_year' => $_GET['graduation_year'] ?? null,
    'major'           => $_GET['major'] ?? null,
    'start_date'      => $_GET['start_date'] ?? null,
    'end_date'        => $_GET['end_date'] ?? null,
];

try {
    [$filterSql, $filterParams] = tracer_filter_sql($filters);
    $latest = tracer_latest_submission_subquery();

    // Responden UNIK vs total submission.
    // Seluruh metrik keadaan-kini di bawah dihitung atas submission TERBARU
    // milik tiap alumnus, sehingga alumnus yang mengisi berulang kali tidak
    // terhitung lebih dari sekali dan response rate tidak melampaui 100%.
    $counts                = tracer_response_counts($pdo, $filters);
    $total_respondents     = $counts['responden'];
    $total_submissions     = $counts['submission'];
    $total_verified_alumni = tracer_expected_respondents($pdo, $filters);

    /** Ambil satu kolom datar dari submission terbaru, sudah tersaring. */
    $ambilKolom = function ($kolom) use ($pdo, $latest, $filterSql, $filterParams) {
        $sql = "SELECT ts.$kolom AS nilai
                FROM tracer_submissions ts
                JOIN users u ON ts.user_id = u.id
                WHERE ts.id IN $latest $filterSql";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($filterParams);
        return $stmt->fetchAll();
    };

    // 3. Status pekerjaan — dinormalisasi saat dibaca agar nilai lama yang
    //    terlanjur tersimpan mentah ikut terhitung pada kelompok yang benar.
    $ws_counts = [];
    foreach ($ambilKolom('work_status') as $row) {
        $slug = tracer_normalize_work_status($row->nilai);
        if ($slug === null) { continue; }
        $ws_counts[$slug] = ($ws_counts[$slug] ?? 0) + 1;
    }
    arsort($ws_counts);
    $career_status = ['labels' => [], 'series' => []];
    foreach ($ws_counts as $slug => $n) {
        $career_status['labels'][] = tracer_work_status_label($slug);
        $career_status['series'][] = $n;
    }

    // 4. Relevansi bidang.
    //    PERBAIKAN BUG: sisi baca dulu membandingkan dengan label lama
    //    ('sangat relevan') sementara sisi tulis menyimpan slug
    //    ('high'|'medium'|'low'), sehingga KPI keselarasan permanen 0%.
    //    Kini keduanya memakai tracer_normalize_relevance() yang sama.
    $fr_counts = [];
    foreach ($ambilKolom('field_relevance') as $row) {
        $slug = tracer_normalize_relevance($row->nilai);
        if ($slug === null) { continue; }
        $fr_counts[$slug] = ($fr_counts[$slug] ?? 0) + 1;
    }

    $relevance = ['labels' => [], 'series' => []];
    foreach (['high', 'medium', 'low'] as $slug) {
        if (!isset($fr_counts[$slug])) { continue; }
        $relevance['labels'][] = tracer_relevance_label($slug);
        $relevance['series'][] = $fr_counts[$slug];
    }

    // Dianggap selaras bila relevansinya tinggi atau cukup.
    // Penyebutnya jumlah yang MENJAWAB, bukan seluruh responden, agar
    // pertanyaan yang dilewati tidak menekan angka secara keliru.
    $aligned_count  = ($fr_counts['high'] ?? 0) + ($fr_counts['medium'] ?? 0);
    $answered_count = array_sum($fr_counts);
    $alignment_rate = $answered_count > 0 ? (int)round(($aligned_count / $answered_count) * 100) : 0;

    // 5. Rentang pendapatan
    $sal_counts = [];
    foreach ($ambilKolom('salary_range') as $row) {
        $v = trim((string)$row->nilai);
        if ($v === '') { continue; }
        $sal_counts[$v] = ($sal_counts[$v] ?? 0) + 1;
    }
    arsort($sal_counts);
    $salary = ['labels' => array_keys($sal_counts), 'series' => array_values($sal_counts)];
    $max_salary_label = $sal_counts ? (string)array_key_first($sal_counts) : 'Tidak Ada Data';

    // 6. Sebaran per program studi.
    //    LEFT JOIN, bukan INNER JOIN: responden yang kolom major-nya berisi
    //    teks bebas (warisan form tracer yang belum tervalidasi) sebelumnya
    //    hilang diam-diam dari grafik ini.
    $sqlMajor = "SELECT COALESCE(m.major_name, 'Prodi Tidak Dikenal') AS major_name, COUNT(*) AS count
                 FROM tracer_submissions ts
                 JOIN users u ON ts.user_id = u.id
                 LEFT JOIN majors m ON u.major = m.major_code
                 WHERE ts.id IN $latest $filterSql
                 GROUP BY major_name
                 ORDER BY count DESC";
    $stmt = $pdo->prepare($sqlMajor);
    $stmt->execute($filterParams);
    $major_distribution = ['labels' => [], 'series' => []];
    foreach ($stmt->fetchAll() as $row) {
        $major_distribution['labels'][] = $row->major_name;
        $major_distribution['series'][] = (int)$row->count;
    }

    // 7. Tren pengisian 6 bulan terakhir.
    //    Bulan tanpa pengisian ikut ditampilkan bernilai 0 agar sumbu waktu
    //    tidak menyesatkan (sebelumnya bulan kosong hilang dari label).
    $sqlTrend = "SELECT DATE_FORMAT(ts.created_at, '%Y-%m') AS bulan, COUNT(*) AS count
                 FROM tracer_submissions ts
                 JOIN users u ON ts.user_id = u.id
                 WHERE ts.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) $filterSql
                 GROUP BY bulan";
    $stmt = $pdo->prepare($sqlTrend);
    $stmt->execute($filterParams);
    $trend_map = [];
    foreach ($stmt->fetchAll() as $row) {
        $trend_map[$row->bulan] = (int)$row->count;
    }

    $monthly_trend = ['labels' => [], 'series' => []];
    for ($i = 5; $i >= 0; $i--) {
        $kunci = date('Y-m', strtotime("-$i month"));
        $monthly_trend['labels'][] = date('M Y', strtotime($kunci . '-01'));
        $monthly_trend['series'][] = $trend_map[$kunci] ?? 0;
    }

    echo json_encode([
        'success'               => true,
        'total_respondents'     => $total_respondents,
        'total_submissions'     => $total_submissions,
        'total_verified_alumni' => $total_verified_alumni,
        'alignment_rate'        => $alignment_rate,
        'max_salary_range'      => $max_salary_label,
        'career_status'         => $career_status,
        'relevance'             => $relevance,
        'salary'                => $salary,
        'major_distribution'    => $major_distribution,
        'monthly_trend'         => $monthly_trend,
        'filters'               => array_filter($filters),
    ]);

} catch (PDOException $e) {
    error_log("Tracer Analytics compilation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error compiling analytics']);
}
