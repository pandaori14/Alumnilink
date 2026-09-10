<?php
/**
 * CRON JOB: Tracer Study Reminder
 * Run this file periodically (e.g., monthly) to remind alumni to update their Tracer Study.
 */

// Adjust relative path to access config from the cron directory
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/cron_auth.php';

// Token lama DITULIS DI KODE ('tracer-cron-secure-token-123') dan berkas ini
// terbuka lewat web, sehingga siapa pun yang mengetahuinya dapat mengirimi
// SELURUH alumni e-mail pengingat, berulang kali. Kini memakai token acak
// yang dibuat sendiri dan disimpan di basis data.
cron_require_auth($pdo);
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/logger.php'; // Optional if you have logger

echo "[START] Tracer Study Reminder Cron Job - " . date('Y-m-d H:i:s') . "\n";

try {
    // Threshold: 3 years
    $threshold_date = date('Y-m-d H:i:s', strtotime('-' . setting_int('tracer_reminder_years', 3, 1) . ' years'));

    // Select alumni who have verified their accounts AND 
    // (last_tracer_update is NULL OR older than 3 years)
    $stmt = $pdo->prepare("
        SELECT id, name, email, last_tracer_update 
        FROM users 
        WHERE role = 'alumni' 
          AND is_verified = 1 
          AND (last_tracer_update IS NULL OR last_tracer_update < ?)
    ");
    $stmt->execute([$threshold_date]);
    $users = $stmt->fetchAll();

    $count = 0;
    foreach ($users as $user) {
        if (!empty($user->email)) {
            $success = send_tracer_reminder_email($user->email, $user->name, $user->last_tracer_update);
            if ($success) {
                $count++;
            }
        }
    }

    echo "[SUCCESS] Sent reminder to $count alumni.\n";
    // log_activity('CRON_TRACER_REMINDER', "Sent $count reminders."); // if you want to log to DB

} catch (PDOException $e) {
    echo "[ERROR] Database Error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "[ERROR] System Error: " . $e->getMessage() . "\n";
}

echo "[END] Tracer Study Reminder Cron Job\n";
?>
