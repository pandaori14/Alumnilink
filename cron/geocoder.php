<?php
/**
 * Alumni Geocoder Background Worker
 * Run this script via cron or manually from CLI.
 * Example: php cron/geocoder.php
 */

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/cron_auth.php';

// Sebelumnya cukup "?run" tanpa kunci apa pun: siapa saja di internet dapat
// memicu geocoding, yang berarti mengirim alamat rumah alumni ke Nominatim
// dan menghabiskan jatah permintaan kita di sana.
cron_require_auth($pdo);

echo "Starting Geocoder...\n";

// 0. Hormati alumni yang memilih keluar dari Peta Persebaran.
//
// Menyaringnya hanya saat pembacaan tidak cukup: alamat rumah yang sudah
// terlanjur ter-geocode tetap tersimpan di cache. Barisnya dihapus supaya
// pilihan "keluar" benar-benar berarti data koordinatnya tidak lagi ada.
$dihapus = $pdo->exec(
    "DELETE c FROM alumni_geocoding_cache c
     JOIN users u ON u.id = c.user_id
     WHERE COALESCE(u.map_opt_out, 0) = 1"
);
if ($dihapus) {
    echo "Menghapus $dihapus entri milik alumni yang keluar dari peta.
";
}

// 1. Sync new addresses from `users` table
$syncUsersSql = "
    INSERT INTO alumni_geocoding_cache (user_id, raw_address, status)
    SELECT id, address, 'pending'
    FROM users 
    WHERE address IS NOT NULL AND address != '' 
    AND role = 'alumni'
    AND COALESCE(map_opt_out, 0) = 0
    AND id NOT IN (SELECT user_id FROM alumni_geocoding_cache)
";
$pdo->exec($syncUsersSql);

// 2. Fetch up to 10 pending addresses to process
$stmt = $pdo->prepare("SELECT id, raw_address FROM alumni_geocoding_cache WHERE status = 'pending' LIMIT " . setting_int('geocoder_batch_size', 10, 1) . "");
$stmt->execute();
$pendingRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($pendingRecords)) {
    echo "No pending addresses to geocode.\n";
    exit;
}

$updateStmt = $pdo->prepare("UPDATE alumni_geocoding_cache SET latitude = ?, longitude = ?, status = ? WHERE id = ?");

foreach ($pendingRecords as $record) {
    echo "Geocoding: " . $record['raw_address'] . " ... ";
    
    // Nominatim API endpoint
    $url = "https://nominatim.openstreetmap.org/search?q=" . urlencode($record['raw_address']) . "&format=json&limit=1";
    
    // Set up stream context for HTTP request to include User-Agent (Required by OSM Nominatim)
    $options = [
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: AlumniLink/1.0 (admin@alumnilink.local)\r\n"
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
            echo "SUCCESS (Lat: {$data[0]['lat']}, Lon: {$data[0]['lon']})\n";
        } else {
            $updateStmt->execute([null, null, 'failed', $record['id']]);
            echo "FAILED (No results found)\n";
        }
    } else {
        $updateStmt->execute([null, null, 'failed', $record['id']]);
        echo "FAILED (Network/API Error)\n";
    }
    
    // Respect Nominatim Usage Policy (1 request per second)
    sleep(1);
}

echo "Geocoding batch finished.\n";
