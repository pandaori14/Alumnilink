<?php
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../../includes/session_boot.php';
}
require_once dirname(__DIR__, 2) . '/config/db.php';

header('Content-Type: application/json');

// Check authentication & Role (Super Admin or Admin Tracer only)
$user_role = $_SESSION['user_role'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($user_role, ['super_admin', 'admin_tracer'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access.']);
    exit;
}

// 1. Sync new addresses from `users` table to the geocoding cache
$syncUsersSql = "
    INSERT INTO alumni_geocoding_cache (user_id, raw_address, status)
    SELECT id, address, 'pending'
    FROM users 
    WHERE address IS NOT NULL AND address != '' 
    AND role = 'alumni'
    AND id NOT IN (SELECT user_id FROM alumni_geocoding_cache)
";
try {
    $pdo->exec($syncUsersSql);
} catch (PDOException $e) {
    // Ignore duplicate key errors if any, but catch exceptions
}

// 2. Fetch up to 15 pending addresses to process in this request
$stmt = $pdo->prepare("SELECT id, raw_address FROM alumni_geocoding_cache WHERE status = 'pending' LIMIT 15");
$stmt->execute();
$pendingRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($pendingRecords)) {
    echo json_encode([
        'success' => true,
        'message' => 'Semua alamat alumni sudah tersinkronisasi dengan koordinat peta.',
        'processed' => 0
    ]);
    exit;
}

$updateStmt = $pdo->prepare("UPDATE alumni_geocoding_cache SET latitude = ?, longitude = ?, status = ? WHERE id = ?");
$processedCount = 0;
$successCount = 0;

foreach ($pendingRecords as $record) {
    // Nominatim API endpoint
    $url = "https://nominatim.openstreetmap.org/search?q=" . urlencode($record['raw_address']) . "&format=json&limit=1";
    
    // Set up stream context for HTTP request to include User-Agent (Required by OSM Nominatim)
    $options = [
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: AlumniLink/1.0 (admin@alumnilink.local)\r\n",
            "timeout" => 5 // 5 seconds timeout
        ]
    ];
    $context = stream_context_create($options);
    
    // Fetch data
    $response = @file_get_contents($url, false, $context);
    
    if ($response !== false) {
        $data = json_decode($response, true);
        if (!empty($data) && isset($data[0]['lat']) && isset($data[0]['lon'])) {
            $updateStmt->execute([
                $data[0]['lat'],
                $data[0]['lon'],
                'success',
                $record['id']
            ]);
            $successCount++;
        } else {
            $updateStmt->execute([null, null, 'failed', $record['id']]);
        }
    } else {
        $updateStmt->execute([null, null, 'failed', $record['id']]);
    }
    
    $processedCount++;
    
    // Respect Nominatim Usage Policy (1 request per second)
    sleep(1);
}

echo json_encode([
    'success' => true,
    'message' => "Sinkronisasi selesai. Berhasil memproses {$processedCount} data alamat, {$successCount} di antaranya sukses dikonversi ke koordinat.",
    'processed' => $processedCount,
    'success_count' => $successCount
]);
