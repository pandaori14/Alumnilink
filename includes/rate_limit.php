<?php
/**
 * Rate Limiting & Anti-Bruteforce Utility
 * Standardizing enterprise security for AlumniLink (Skill ID: SEC-001)
 */

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../includes/session_boot.php';
}

require_once __DIR__ . '/logger.php';

/**
 * Check and enforce rate limiting for a specific action
 * 
 * @param string $action Action identifier (e.g., 'LOGIN', 'REGISTER')
 * @param int $max_attempts Maximum allowed attempts within the window
 * @param int $lockout_minutes Lockout window in minutes
 */
function check_rate_limit($action, $max_attempts = 5, $lockout_minutes = 15) {
    global $pdo;

    if (!$pdo) return;

    // Ambang dapat diatur lewat Pengaturan Sistem.
    // Kunci: rate_limit_<aksi>_max dan rate_limit_<aksi>_window (aksi huruf kecil).
    // Nilai yang dikirim pemanggil menjadi nilai bawaan, sehingga perilaku
    // tidak berubah selama pengaturannya belum diisi.
    if (function_exists('setting_int')) {
        $slug            = strtolower($action);
        $max_attempts    = setting_int("rate_limit_{$slug}_max", $max_attempts, 1);
        $lockout_minutes = setting_int("rate_limit_{$slug}_window", $lockout_minutes, 1);
    }

    $ip = get_client_ip();

    try {
        // Ensure rate_limits table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            ip_address VARCHAR(45) NOT NULL,
            action VARCHAR(50) NOT NULL,
            attempts INT DEFAULT 1,
            last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (ip_address, action)
        )");

        // Clean up expired locks (synchronized with PHP timezone)
        $cleanup = $pdo->prepare("DELETE FROM rate_limits WHERE last_attempt < ?");
        $cutoff = date('Y-m-d H:i:s', time() - ($lockout_minutes * 60));
        $cleanup->execute([$cutoff]);

        // Check current attempts
        $stmt = $pdo->prepare("SELECT attempts, last_attempt FROM rate_limits WHERE ip_address = ? AND action = ?");
        $stmt->execute([$ip, $action]);
        $row = $stmt->fetch();

        if ($row) {
            if ($row->attempts >= $max_attempts) {
                $calc_diff = time() - strtotime($row->last_attempt);
                $remaining = max(1, ceil(($lockout_minutes * 60 - $calc_diff) / 60));
                
                // Log security event
                log_activity('RATE_LIMIT_BLOCKED', "IP $ip blocked for action $action. Exceeded $max_attempts attempts.");
                error_log("SECURITY ALERT: Rate limit exceeded for IP $ip on action $action.");

                // Detect AJAX / API / JSON request
                $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') || 
                           (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

                if (!$is_ajax) {
                    $referer = $_SERVER['HTTP_REFERER'] ?? '../index.php';
                    // Clean existing error/retry_after query params to prevent duplicate params
                    $referer = preg_replace('/([?&])error=[^&]*(&?)/', '$1', $referer);
                    $referer = preg_replace('/([?&])retry_after=[^&]*(&?)/', '$1', $referer);
                    $referer = rtrim($referer, '?&');
                    
                    $separator = (strpos($referer, '?') === false) ? '?' : '&';
                    
                    http_response_code(429);
                    header("Location: " . $referer . $separator . "error=rate_limit&retry_after=" . $remaining);
                    exit();
                }

                http_response_code(429);
                die(json_encode([
                    'status' => 'error',
                    'message' => "Terlalu banyak permintaan. Silakan coba lagi dalam $remaining menit."
                ]));
            } else {
                // Increment attempt
                $update = $pdo->prepare("UPDATE rate_limits SET attempts = attempts + 1, last_attempt = ? WHERE ip_address = ? AND action = ?");
                $update->execute([date('Y-m-d H:i:s'), $ip, $action]);
            }
        } else {
            // Insert first attempt
            $insert = $pdo->prepare("INSERT INTO rate_limits (ip_address, action, attempts, last_attempt) VALUES (?, ?, 1, ?)");
            $insert->execute([$ip, $action, date('Y-m-d H:i:s')]);
        }
    } catch (PDOException $e) {
        error_log("Rate Limit DB Error: " . $e->getMessage());
    }
}

/**
 * Reset rate limit attempts upon successful action
 * 
 * @param string $action Action identifier
 */
function reset_rate_limit($action) {
    global $pdo;
    if (!$pdo) return;

    $ip = get_client_ip();
    try {
        $stmt = $pdo->prepare("DELETE FROM rate_limits WHERE ip_address = ? AND action = ?");
        $stmt->execute([$ip, $action]);
    } catch (PDOException $e) {
        error_log("Rate Limit Reset DB Error: " . $e->getMessage());
    }
}
?>
