<?php
/**
 * handlers/employer_survey_handler.php
 * ─────────────────────────────────────────────────────────
 * Menerima kiriman Survei Kepuasan Pengguna Lulusan dari atasan alumni.
 *
 * Responden BUKAN pengguna sistem, sehingga otorisasi bersandar pada token
 * pada tautan — diverifikasi ulang di sini, tidak dipercaya dari formulir.
 */

require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/error_page.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit;
}

$token = trim($_POST['token'] ?? '');
$balik = '../employer_survey.php?token=' . urlencode($token);

if ($token === '') {
    render_error_page('Tautan Tidak Lengkap', 'Kode akses tidak ditemukan pada kiriman ini.', 400, 'link-2-off');
}

// Tanpa pembatasan, endpoint ini dapat dipakai membanjiri basis data.
check_rate_limit('EMPLOYER_SURVEY', 20, 15);

if (!verify_csrf_token(get_request_csrf_token())) {
    header('Location: ' . $balik . '&error=csrf');
    exit;
}

try {
    // Verifikasi ulang token: status, kepemilikan, dan masa berlaku.
    $stmt = $pdo->prepare("SELECT * FROM employer_surveys WHERE token = ?");
    $stmt->execute([$token]);
    $survey = $stmt->fetch();

    if (!$survey) {
        render_error_page('Tautan Tidak Dikenal', 'Kode akses tidak dikenali sistem.', 404, 'search-x');
    }
    if ($survey->status === 'completed') {
        render_error_page('Survei Sudah Terisi', 'Penilaian untuk lulusan ini sudah kami terima sebelumnya.', 410, 'check-circle-2');
    }
    if ($survey->expires_at !== null && strtotime($survey->expires_at) < time()) {
        render_error_page('Tautan Kedaluwarsa', 'Masa berlaku tautan survei ini sudah berakhir.', 410, 'clock-alert');
    }

    // Aspek diambil dari basis data, bukan dari formulir, sehingga kiriman
    // tidak dapat menyisipkan pertanyaan di luar kuesioner resmi.
    $aspek = tracer_competency_questions($pdo);
    if (!$aspek) {
        header('Location: ' . $balik . '&error=failed');
        exit;
    }

    $jawaban = [];
    foreach ($aspek as $q) {
        $val = trim($_POST['q_' . $q->id] ?? '');
        if ($val === '') {
            header('Location: ' . $balik . '&error=incomplete');
            exit;
        }

        // Hanya opsi resmi yang diterima.
        $opsi = json_decode((string)$q->options, true);
        if (is_array($opsi) && $opsi && !in_array($val, $opsi, true)) {
            header('Location: ' . $balik . '&error=incomplete');
            exit;
        }

        $jawaban[(int)$q->id] = $val;
    }

    $pdo->beginTransaction();

    $ins = $pdo->prepare(
        "INSERT INTO employer_survey_answers (survey_id, question_id, answer_value)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE answer_value = VALUES(answer_value)"
    );
    foreach ($jawaban as $qid => $val) {
        $ins->execute([$survey->id, $qid, $val]);
    }

    $pdo->prepare(
        "UPDATE employer_surveys SET status = 'completed', completed_at = NOW() WHERE id = ?"
    )->execute([$survey->id]);

    $pdo->commit();

    log_activity(
        'EMPLOYER_SURVEY_SUBMITTED',
        'Penilaian atasan diterima untuk alumni ' . $survey->alumni_user_id . ' (survei #' . $survey->id . ')'
    );

    header('Location: ' . $balik . '&success=1');
    exit;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Employer survey submit error: ' . $e->getMessage());
    header('Location: ' . $balik . '&error=failed');
    exit;
}
