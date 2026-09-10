<?php
/**
 * Sebaran geografis alumni (GeoJSON).
 *
 * ── Mengapa keluarannya dibedakan per peran ────────────────────────────
 * Sebelumnya endpoint ini hanya memeriksa `isset($_SESSION['user_id'])`,
 * lalu menyerahkan nama, angkatan, tempat kerja, dan ALAMAT RUMAH LENGKAP
 * setiap alumnus kepada siapa pun yang berhasil login — termasuk sesama
 * alumni. Satu permintaan HTTP cukup untuk mengunduh seluruh basis data
 * alamat.
 *
 * Peta tetap dapat dilihat alumni karena memang itu gunanya (melihat ke
 * mana angkatannya tersebar), tetapi pada tingkat kedetailan yang tidak
 * memungkinkan seseorang menemukan rumah orang lain:
 *
 *              | nama, angkatan, prodi | tempat kerja | alamat | koordinat
 *   -----------|-----------------------|--------------|--------|-----------
 *   alumni     |          ya           |    tidak     | tidak  | dibulatkan
 *   staf       |          ya           |     ya       |  ya    |   tepat
 *
 * Koordinat dibulatkan ke 1 desimal (± ~11 km — kira-kira setingkat kota)
 * alih-alih memakai nama kota, karena `alumni_geocoding_cache` tidak
 * menyimpan kota/provinsi sama sekali: hanya raw_address, latitude, dan
 * longitude. Menambahkan kolom kota berarti melakukan geocoding ulang
 * SELURUH data yang sudah ada, sedangkan pembulatan langsung berlaku pada
 * data yang terkumpul hari ini.
 */

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../../includes/session_boot.php';
}
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth_guard.php';

header('Content-Type: application/json');
// Isi balasan berbeda per peran; jangan sampai proxy/peramban menyajikan
// versi staf kepada alumni.
header('Cache-Control: private, no-store');

if (!is_logged_in()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$lihat_detail = is_admin();   // staf & super_admin; alumni tidak

// Submission tracer terbaru saja. LEFT JOIN polos ke tracer_submissions
// menggandakan baris bagi alumni yang mengisi lebih dari sekali, sehingga
// satu orang muncul sebagai beberapa titik di peta.
$sub_terbaru = tracer_latest_submission_subquery();

$sql = "
    SELECT
        u.id, u.name, u.major, u.graduation_year,
        c.latitude, c.longitude, c.raw_address,
        t.company_name, t.work_status
    FROM alumni_geocoding_cache c
    JOIN users u ON c.user_id = u.id
    LEFT JOIN tracer_submissions t
           ON t.user_id = u.id AND t.id IN $sub_terbaru
    WHERE c.status = 'success'
      AND c.latitude IS NOT NULL
      AND c.longitude IS NOT NULL
      AND COALESCE(u.map_opt_out, 0) = 0
";

$params = [];
$conditions = [];

if (!empty($_GET['major'])) {
    $conditions[] = "u.major = ?";
    $params[] = $_GET['major'];
}
if (!empty($_GET['batch'])) {
    $conditions[] = "u.graduation_year = ?";
    $params[] = $_GET['batch'];
}
if (!empty($_GET['work_status'])) {
    $conditions[] = "t.work_status = ?";
    $params[] = $_GET['work_status'];
}

if (!empty($conditions)) {
    $sql .= " AND " . implode(" AND ", $conditions);
}

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $features = [];
    foreach ($results as $row) {
        $lon = (float)$row['longitude'];
        $lat = (float)$row['latitude'];

        $properties = [
            "id"    => $row['id'],
            "name"  => $row['name'],
            "major" => $row['major'],
            "batch" => $row['graduation_year'],
            "work_status" => $row['work_status'] ?? 'Belum Diketahui',
        ];

        if ($lihat_detail) {
            $properties['company'] = $row['company_name'] ?? 'N/A';
            $properties['address'] = $row['raw_address'];
        } else {
            // Dibulatkan SEBELUM dikirim, bukan disembunyikan di sisi
            // peramban: apa pun yang dikirim ke klien harus dianggap
            // terbaca oleh penerimanya.
            $lon = round($lon, 1);
            $lat = round($lat, 1);
        }

        $features[] = [
            "type" => "Feature",
            "geometry" => [
                "type" => "Point",
                "coordinates" => [$lon, $lat]
            ],
            "properties" => $properties
        ];
    }

    echo json_encode([
        "type"     => "FeatureCollection",
        "detail"   => $lihat_detail ? 'full' : 'limited',
        "features" => $features
    ]);
} catch (Exception $e) {
    // Pesan galat PDO memuat potongan SQL dan nama kolom; itu untuk log,
    // bukan untuk klien.
    error_log('geodistribution: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Gagal memuat data peta.']);
}
