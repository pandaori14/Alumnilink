<?php
/**
 * cron/process_email_queue.php
 * ─────────────────────────────────────────────────────────
 * Email Queue Worker — Process pending emails from email_queue table.
 * Run via cron job or Windows Task Scheduler:
 *   Schedule: Every 5 minutes
 *   Command: C:\xampp\php\php.exe C:\xampp\htdocs\alumnilink\cron\process_email_queue.php
 *
 * Each run processes a batch of BATCH_SIZE emails.
 * Implements retry logic (max 3 attempts) before marking as 'failed'.
 */

// PENTING: kedua define() di bawah memanggil setting_int(), yang baru ada
// SETELAH config/db.php dimuat. Sebelumnya urutannya terbalik, sehingga
// berkas ini selalu mati dengan
//   "Call to undefined function setting_int()"
// sebelum sempat mengerjakan apa pun. Akibatnya seluruh e-mail broadcast
// mengendap di antrean tanpa pernah dicoba sekali pun (attempts tetap 0)
// dan tidak ada satu pun tanda di layar bahwa itu terjadi.
//
// `php -l` tidak dapat menangkap ini: berkasnya sah secara sintaks; yang
// salah adalah urutan pemuatan saat dijalankan.
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/cron_auth.php';

// Kunci lama diambil dari .env dengan cadangan 'changeme'; .env tidak pernah
// memuatnya, jadi kuncinya memang 'changeme'. Kini memakai token acak
// bersama yang tersimpan di basis data.
cron_require_auth($pdo);

define('BATCH_SIZE', setting_int('email_batch_size', 20, 1));    // Emails per run (safer for free SMTP providers like Gmail)
define('MAX_ATTEMPTS', setting_int('email_max_attempts', 3, 1));   // Retry attempts before marking failed

$start = microtime(true);
$log   = function(string $msg) { echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL; };

$log("Email queue worker started. Batch size: " . BATCH_SIZE);

// 0. Pulihkan baris yang tersangkut di 'processing'.
//
// Batch ditandai 'processing' lebih dulu supaya dua proses tidak mengirim
// e-mail yang sama. Tetapi bila proses MATI di tengah jalan — batas waktu
// eksekusi habis, koneksi terputus, atau prosesnya dihentikan — barisnya
// tinggal berstatus 'processing' selamanya: pengambilan batch hanya
// mencari 'pending', jadi baris itu tidak pernah dicoba lagi dan tidak
// pernah dilaporkan gagal. Ia hanya lenyap.
//
// Ini bukan kemungkinan teoretis: satu putaran penuh memerlukan
// 1,5 detik x BATCH_SIZE, yang pada batch 20 sudah melampaui
// max_execution_time bawaan (30 detik) bila dipicu lewat HTTP.
$batas_macet = setting_int('email_stuck_minutes', 15, 1);
$q_macet = $pdo->prepare("SELECT COUNT(*) FROM email_queue
    WHERE status = 'processing' AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)");
$q_macet->execute([$batas_macet]);
$n_macet = (int)$q_macet->fetchColumn();

if ($n_macet > 0) {
    // attempts TIDAK dinaikkan lagi di sini — kenaikannya sudah terjadi
    // saat batch ditandai; menaikkannya dua kali akan membuat e-mail
    // menyerah lebih cepat dari MAX_ATTEMPTS yang sebenarnya.
    $pdo->prepare("UPDATE email_queue SET status = 'pending'
                   WHERE status = 'processing' AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)")
        ->execute([$batas_macet]);
    $log("Mengembalikan $n_macet e-mail yang tersangkut di 'processing' ke antrean.");
}

// Satu putaran penuh butuh 1,5 detik per e-mail. Tanpa ini, pemicuan lewat
// HTTP akan terpotong di tengah dan justru menciptakan baris tersangkut
// yang baru saja dipulihkan di atas.
@set_time_limit(0);

// 1. Fetch a batch of pending emails (lock them for processing)
try {
    $pdo->beginTransaction();

    $batch_stmt = $pdo->prepare(
        "SELECT id, to_email, to_name, subject, body_html, broadcast_id
         FROM email_queue
         WHERE status = 'pending' AND attempts < ?
         ORDER BY created_at ASC
         LIMIT " . BATCH_SIZE . "
         FOR UPDATE"
    );
    $batch_stmt->execute([MAX_ATTEMPTS]);
    $batch = $batch_stmt->fetchAll();

    if (empty($batch)) {
        $pdo->rollBack();
        $log("No pending emails in queue. Exiting.");
        exit(0);
    }

    // Mark as 'processing' to prevent duplicate processing
    $ids = array_column($batch, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("UPDATE email_queue SET status = 'processing', attempts = attempts + 1 WHERE id IN ($placeholders)")
        ->execute($ids);
    $pdo->prepare("UPDATE email_delivery_log SET status = 'processing' WHERE queue_id IN ($placeholders)")
        ->execute($ids);

    $pdo->commit();

} catch (PDOException $e) {
    $pdo->rollBack();
    $log("ERROR fetching batch: " . $e->getMessage());
    exit(1);
}

$log("Processing " . count($batch) . " emails...");

// 2. Load broadcast attachments (group by broadcast_id for efficiency)
$broadcast_ids = array_unique(array_filter(array_column($batch, 'broadcast_id')));
$attach_map    = [];

if (!empty($broadcast_ids)) {
    $ap = implode(',', array_fill(0, count($broadcast_ids), '?'));
    try {
        $att_stmt = $pdo->prepare("SELECT broadcast_id, original_name, file_path, mime_type FROM broadcast_attachments WHERE broadcast_id IN ($ap) AND ? = ?");
        // Simplified: just fetch all attachments for these broadcasts
        $att_stmt = $pdo->prepare("SELECT broadcast_id, original_name, file_path, mime_type FROM broadcast_attachments WHERE broadcast_id IN ($ap)");
        $att_stmt->execute($broadcast_ids);
        foreach ($att_stmt->fetchAll() as $a) {
            $attach_map[$a->broadcast_id][] = $a;
        }
    } catch (PDOException $e) {
        $log("WARN: Could not fetch attachments: " . $e->getMessage());
    }
}

// 3. Send each email
$sent   = 0;
$failed = 0;

foreach ($batch as $item) {
    $attachments = $attach_map[$item->broadcast_id] ?? [];

    try {
        // Build attachments array for mailer
        $att_files = [];
        foreach ($attachments as $a) {
            if (file_exists($a->file_path)) {
                $att_files[] = ['path' => $a->file_path, 'name' => $a->original_name];
            }
        }

        $result = send_html_email(
            $item->to_email,
            $item->to_name  ?: 'Alumni',
            $item->subject,
            $item->body_html,
            $att_files
        );

        if ($result) {
            $pdo->prepare("UPDATE email_queue SET status = 'sent', processed_at = NOW() WHERE id = ?")
                ->execute([$item->id]);
            $pdo->prepare("UPDATE email_delivery_log SET status = 'sent', updated_at = NOW() WHERE queue_id = ?")
                ->execute([$item->id]);
            $sent++;
        } else {
            throw new RuntimeException('send_html_email returned false');
        }

    } catch (Exception $e) {
        $err_msg = substr($e->getMessage(), 0, 500);
        $log("FAIL [#{$item->id}] {$item->to_email}: $err_msg");

        // If max attempts reached → mark failed, otherwise → back to pending
        $new_status = 'pending'; // Will be retried next run
        try {
            $att_check = $pdo->prepare("SELECT attempts FROM email_queue WHERE id = ?");
            $att_check->execute([$item->id]);
            $current_attempts = (int)($att_check->fetchColumn() ?? 0);
            if ($current_attempts >= MAX_ATTEMPTS) {
                $new_status = 'failed';
            }
        } catch (PDOException $de) {}

        $pdo->prepare("UPDATE email_queue SET status = ?, error_message = ?, processed_at = IF(status='failed', NOW(), processed_at) WHERE id = ?")
            ->execute([$new_status, $err_msg, $item->id]);
        $pdo->prepare("UPDATE email_delivery_log SET status = ?, error_message = ?, updated_at = NOW() WHERE queue_id = ?")
            ->execute([$new_status, $err_msg, $item->id]);
        $failed++;
    }

    // Delay between emails to prevent SMTP block / rate-limiting (1.5 seconds)
    usleep(1500000);
}

$elapsed = round(microtime(true) - $start, 2);
$log("Done. Sent: $sent | Failed: $failed | Time: {$elapsed}s");

// 4. Log summary to activity log (optional DB log)
try {
    $pdo->prepare("INSERT INTO activity_logs (user_id, action, description, created_at) VALUES (NULL, 'EMAIL_QUEUE_PROCESSED', ?, NOW())")
        ->execute(["Queue worker: Sent=$sent Failed=$failed Time={$elapsed}s"]);
} catch (PDOException $e) {
    // Table might have different structure, skip
}

exit(0);
