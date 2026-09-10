<?php
/**
 * Uji end-to-end Survei Kepuasan Pengguna Lulusan.
 * Membuat survei uji, mengisi lewat HTTP seperti atasan sungguhan, lalu
 * memeriksa penolakan token dipakai ulang / kedaluwarsa / ngawur.
 */

require_once __DIR__ . '/_bootstrap.php';

$BASE = uji_base_url();

function check($ok, $label, $detail = '') {
    global $pass, $fail;
    if ($ok) { $pass++; printf("  LULUS  %-44s %s\n", $label, $detail); }
    else     { $fail++; printf("  GAGAL  %-44s %s\n", $label, $detail); }
}

// Atasan adalah pengunjung anonim: cookie sesi HARUS dibawa antara pemuatan
// formulir (saat token CSRF dibuat) dan pengirimannya, persis seperti
// perilaku peramban sungguhan.
$COOKIE = sys_get_temp_dir() . '/uji_atasan_cookie.txt';
@unlink($COOKIE);

function req($url, $post = null) {
    global $COOKIE;
    $ch = curl_init($url);
    $o = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $COOKIE,
        CURLOPT_COOKIEFILE     => $COOKIE,
    ];
    if ($post !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = http_build_query($post); }
    curl_setopt_array($ch, $o);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $loc = preg_match('/^Location:\s*(\S+)/mi', (string)$raw, $m) ? $m[1] : '';
    return [$code, (string)$raw, $loc];
}

$alumni = $pdo->query("SELECT id, name FROM users WHERE role='alumni' LIMIT 1")->fetch();
if (!$alumni) { echo "Tidak ada alumni untuk diuji.\n"; exit(1); }

$aspek = tracer_competency_questions($pdo);
printf("Aspek kompetensi: %d\n", count($aspek));

$cleanup = function () use ($pdo) {
    $pdo->exec("DELETE a FROM employer_survey_answers a JOIN employer_surveys s ON a.survey_id=s.id WHERE s.employer_email LIKE 'uji_atasan%'");
    $pdo->exec("DELETE FROM employer_surveys WHERE employer_email LIKE 'uji_atasan%'");
};
$cleanup();

// ── Buat survei uji: satu sah, satu kedaluwarsa ──────────────────
$tokenOk   = bin2hex(random_bytes(32));
$tokenExp  = bin2hex(random_bytes(32));

$ins = $pdo->prepare(
    "INSERT INTO employer_surveys (alumni_user_id, employer_name, employer_position, employer_email, token, status, sent_at, expires_at)
     VALUES (?, 'Atasan Uji', 'Direktur', ?, ?, 'sent', NOW(), ?)"
);
$ins->execute([$alumni->id, 'uji_atasan1@local.invalid', $tokenOk,  date('Y-m-d H:i:s', strtotime('+30 days'))]);
$ins->execute([$alumni->id, 'uji_atasan2@local.invalid', $tokenExp, date('Y-m-d H:i:s', strtotime('-1 day'))]);

echo "Survei uji dibuat.\n\n";

// 1. Token sah membuka formulir
[$c, $body] = req("$BASE/employer_survey.php?token=$tokenOk");
check($c === 200 && strpos($body, 'Survei Kepuasan Pengguna Lulusan') !== false,
    'token sah membuka formulir', "HTTP $c");
check(strpos($body, htmlspecialchars($alumni->name)) !== false,
    'nama lulusan tampil', $alumni->name);

// Hitung aspek yang dirender
$jumlahAspek = substr_count($body, 'name="q_');
check($jumlahAspek > 0, 'aspek dirender di formulir', "$jumlahAspek input radio group");

// 2. Token ngawur ditolak
[$c] = req("$BASE/employer_survey.php?token=tidakadatokenini");
check($c === 404, 'token ngawur ditolak', "HTTP $c");

// 3. Token kedaluwarsa ditolak
[$c] = req("$BASE/employer_survey.php?token=$tokenExp");
check($c === 410, 'token kedaluwarsa ditolak', "HTTP $c");

// 4. Tanpa token ditolak
[$c] = req("$BASE/employer_survey.php");
check($c === 400, 'tanpa token ditolak', "HTTP $c");

// 5. Kirim jawaban lengkap
preg_match('/name="csrf_token" value="([^"]+)"/', $body, $m);
$csrf = $m[1] ?? '';
check($csrf !== '', 'token CSRF tersedia di formulir', substr($csrf, 0, 12) . '...');

$post = ['token' => $tokenOk, 'csrf_token' => $csrf];
foreach ($aspek as $q) {
    $opsi = json_decode((string)$q->options, true);
    $post['q_' . $q->id] = $opsi[0] ?? 'Menguasai';
}
[$c, $raw, $loc] = req("$BASE/handlers/employer_survey_handler.php", $post);
check(strpos($loc, 'success=1') !== false, 'jawaban tersimpan', "redirect: " . substr($loc, -40));

$st = $pdo->prepare("SELECT status, completed_at FROM employer_surveys WHERE token = ?");
$st->execute([$tokenOk]);
$row = $st->fetch();
check($row && $row->status === 'completed', 'status jadi completed', "status={$row->status}");

$n = $pdo->query("SELECT COUNT(*) FROM employer_survey_answers a JOIN employer_surveys s ON a.survey_id=s.id WHERE s.token='$tokenOk'")->fetchColumn();
check((int)$n === count($aspek), 'seluruh aspek tersimpan', "$n dari " . count($aspek));

// 6. Token dipakai ulang ditolak
[$c] = req("$BASE/employer_survey.php?token=$tokenOk");
check($c === 410, 'token dipakai ulang ditolak', "HTTP $c");

// 7. Rekap agregat terisi
$agg = employer_survey_aggregate($pdo);
$totalTerisi = 0;
foreach ($agg as $a) { $totalTerisi += $a['total']; }
check($totalTerisi === count($aspek), 'rekap agregat terisi', "total jawaban=$totalTerisi");

echo "\n────────────────────────────────\n";
echo "  LULUS: $pass   GAGAL: $fail\n";

$cleanup();
echo "  Data uji dibersihkan.\n";
