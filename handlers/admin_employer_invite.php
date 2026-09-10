<?php
/**
 * handlers/admin_employer_invite.php
 * ─────────────────────────────────────────────────────────
 * Membuat dan mengirim undangan Survei Kepuasan Pengguna Lulusan kepada
 * atasan alumni.
 *
 * Daftar penerima diturunkan dari jawaban tracer yang sudah ada, bukan dari
 * masukan formulir, sehingga kiriman tidak dapat menyuruh sistem mengirim
 * e-mail ke alamat sembarangan.
 */

require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/mailer.php';

require_capability('tracer.kelola');
validate_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php?page=admin_employer_survey');
    exit;
}

$balik = '../index.php?page=admin_employer_survey';

try {
    $kandidat = employer_survey_candidates($pdo);

    // Hanya alamat sah dan belum pernah diundang.
    $target = array_filter($kandidat, function ($k) {
        return $k->email_valid && !$k->sudah_diundang;
    });

    if (!$target) {
        header('Location: ' . $balik . '&error=no_valid');
        exit;
    }

    $hari    = setting_int('employer_survey_expiry_days', 30, 1);
    $expires = date('Y-m-d H:i:s', strtotime("+$hari days"));
    $base    = rtrim(BASE_URL, '/');

    $ins = $pdo->prepare(
        "INSERT INTO employer_surveys
            (alumni_user_id, submission_id, employer_name, employer_position,
             employer_email, token, status, sent_at, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, 'sent', NOW(), ?)"
    );

    $berhasil = 0;
    $gagal    = 0;

    foreach ($target as $k) {
        // Token acak kriptografis, pola sama dengan token verifikasi legalisir.
        $token = bin2hex(random_bytes(32));

        $ins->execute([
            $k->user_id,
            $k->submission_id,
            $k->employer_name ?: null,
            $k->employer_position ?: null,
            $k->employer_email,
            $token,
            $expires,
        ]);

        $tautan = $base . '/employer_survey.php?token=' . $token;
        $kirim  = send_employer_survey_invitation(
            $k->employer_email,
            $k->employer_name ?: 'Bapak/Ibu',
            $k->alumni_name,
            $tautan,
            $hari
        );

        if ($kirim) {
            $berhasil++;
        } else {
            $gagal++;
            // Tandai kembali sebagai pending agar dapat dicoba ulang.
            $pdo->prepare("UPDATE employer_surveys SET status = 'pending', sent_at = NULL WHERE token = ?")
                ->execute([$token]);
        }
    }

    log_activity(
        'EMPLOYER_SURVEY_INVITED',
        "Undangan survei atasan dikirim: $berhasil berhasil, $gagal gagal."
    );

    if ($gagal > 0 && $berhasil === 0) {
        header('Location: ' . $balik . '&error=send_fail');
        exit;
    }

    header('Location: ' . $balik . '&success=sent');
    exit;

} catch (PDOException $e) {
    error_log('Employer invite error: ' . $e->getMessage());
    error_system('Terjadi kesalahan sistem saat mengirim undangan survei. Silakan hubungi administrator.');
}
