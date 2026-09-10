<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once '../includes/auth_guard.php';

header('Content-Type: application/json');

// Hanya super_admin, disamakan dengan halaman pemanggilnya
// (pages/admin_ai_insights.php:3). Endpoint ini memakai kunci API Gemini
// berbayar dan mengirim agregat data tracer ke layanan pihak ketiga,
// sehingga pemanggilannya tidak boleh terbuka bagi seluruh peran staf.
require_role(['super_admin'], true);

// 1. Fetch aggregate data
$total_tracer = $pdo->query("SELECT COUNT(*) FROM tracer_submissions")->fetchColumn();
$work_status = $pdo->query("SELECT work_status, COUNT(*) as count FROM tracer_submissions GROUP BY work_status")->fetchAll(PDO::FETCH_ASSOC);
$relevance = $pdo->query("SELECT field_relevance, COUNT(*) as count FROM tracer_submissions GROUP BY field_relevance")->fetchAll(PDO::FETCH_ASSOC);
$salary = $pdo->query("SELECT salary_range, COUNT(*) as count FROM tracer_submissions GROUP BY salary_range")->fetchAll(PDO::FETCH_ASSOC);

// Format data for prompt
$ws_text = ""; foreach($work_status as $ws) $ws_text .= "- " . $ws['work_status'] . ": " . $ws['count'] . "\n";
$rel_text = ""; foreach($relevance as $r) $rel_text .= "- " . $r['field_relevance'] . ": " . $r['count'] . "\n";
$sal_text = ""; foreach($salary as $s) $sal_text .= "- " . $s['salary_range'] . ": " . $s['count'] . "\n";

// 2. Get API Key
$stmt_key = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'gemini_api_key'");
$api_key = $stmt_key->fetchColumn();

if (!$api_key) {
    echo json_encode(['success' => false, 'message' => 'Gemini API Key not found in settings.']);
    exit();
}

// 3. Construct Prompt
$prompt = "Anda adalah pakar akreditasi BAN-PT dan LAM-PTKes yang berpengalaman dalam menyusun laporan Tracer Alumni untuk institusi pendidikan tinggi kesehatan (khususnya FK UMS).

Task: Analisis data Tracer Alumni berikut dan buatlah narasi laporan profesional untuk bagian Akreditasi (Standar Kinerja Alumni). Narasi harus mencakup analisis daya saing lulusan, keselarasan kurikulum dengan kebutuhan industri (relevansi), dan profil pendapatan lulusan.

Data Statistik Terkini:
- Total Responden: $total_tracer Alumni
- Sebaran Status Kerja:
$ws_text
- Relevansi Bidang:
$rel_text
- Profil Rentang Gaji:
$sal_text

Format Laporan: 
1. Gunakan Bahasa Indonesia formal.
2. Buatlah analisis narasi yang mengalir (bukan hanya list angka).
3. Berikan evaluasi tentang sejauh mana lulusan FK UMS mampu bersaing di pasar kerja.
4. Berikan 3 rekomendasi strategis singkat untuk pengembangan kurikulum di akhir laporan.

Output: Langsung berikan isi laporan tanpa kata pengantar tambahan.";

// 4. Call Gemini API
$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $api_key;

$data = [
    "contents" => [
        [
            "parts" => [
                ["text" => $prompt]
            ]
        ]
    ]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code === 200) {
    $result = json_decode($response, true);
    $generated_text = $result['candidates'][0]['content']['parts'][0]['text'] ?? 'Gagal men-generate teks.';
    echo json_encode(['success' => true, 'content' => $generated_text]);
} else {
    $error_msg = json_decode($response, true)['error']['message'] ?? 'API Error (' . $http_code . ')';
    echo json_encode(['success' => false, 'message' => $error_msg]);
}
