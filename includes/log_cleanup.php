<?php
/**
 * AlumniLink - Activity Log Cleanup Utility
 * This script is called periodically (e.g., in admin dashboard or settings handler)
 * to automatically purge old activity logs if configured by the Super Admin.
 */

if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
}

try {
    // Check if auto-erase is enabled
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'audit_log_auto_erase'");
    $auto_erase = $stmt->fetchColumn();

    if ($auto_erase == '1') {
        // Get retention days
        $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'audit_log_retention_days'");
        $retention_days = $stmt->fetchColumn();
        
        // Ensure retention days is a valid positive integer
        $days = intval($retention_days);
        if ($days > 0) {
            $deleteStmt = $pdo->prepare("DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
            $deleteStmt->execute([$days]);
            
            // Optional: log the cleanup action if any rows were deleted
            // $deletedCount = $deleteStmt->rowCount();
            // if ($deletedCount > 0) {
            //     error_log("AlumniLink Log Cleanup: Removed {$deletedCount} old activity logs.");
            // }
        }
    }
} catch (PDOException $e) {
    // Silently handle errors for background tasks, but log them
    error_log("Activity Log Cleanup Error: " . $e->getMessage());
}
?>
