<?php
/**
 * Activity Logger Utility
 * Standardizing audit trails for AlumniLink
 */

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../includes/session_boot.php';
}

/**
 * Log a user activity to the database
 * 
 * @param string $action The action name (e.g., 'LOGIN', 'UPDATE_PROFILE')
 * @param string|null $description Detailed information about the action
 */
function log_activity($action, $description = null) {
    global $pdo;
    
    // Get user info from session
    $user_id = $_SESSION['user_id'] ?? null;
    $name = $_SESSION['user_name'] ?? 'Guest';
    $role = $_SESSION['user_role'] ?? 'guest';
    
    // Technical details
    $ip = get_client_ip();
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $device = get_device_type($ua);
    $location = get_ip_location($ip);

    try {
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, name, role, action, description, ip_address, location, user_agent, device_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $name, $role, $action, $description, $ip, $location, $ua, $device]);
    } catch (PDOException $e) {
        // Log to PHP error log if database logging fails
        error_log("AlumniLink Audit Error: " . $e->getMessage());
    }

    // --- SECURITY CHECKS TRIGGER ---
    if ($action === 'LOGIN_FAILED') {
        try {
            // 1. IP Brute Force Check
            $stmt_ip = $pdo->prepare("SELECT COUNT(*) FROM activity_logs WHERE ip_address = ? AND action = 'LOGIN_FAILED' AND created_at >= NOW() - INTERVAL 15 MINUTE");
            $stmt_ip->execute([$ip]);
            $failed_ip_count = $stmt_ip->fetchColumn();
            
            // 2. Identity Brute Force Check
            $identity = '';
            if (preg_match('/identity: (.*)$/', $description, $matches)) {
                $identity = trim($matches[1]);
            }
            
            $failed_ident_count = 0;
            if ($identity) {
                $stmt_ident = $pdo->prepare("SELECT COUNT(*) FROM activity_logs WHERE action = 'LOGIN_FAILED' AND description LIKE ? AND created_at >= NOW() - INTERVAL 15 MINUTE");
                $stmt_ident->execute(['%identity: ' . $identity]);
                $failed_ident_count = $stmt_ident->fetchColumn();
            }

            if ($failed_ip_count >= 5 || $failed_ident_count >= 5) {
                $target_label = ($failed_ip_count >= 5) ? "IP $ip" : "identitas $identity";
                $failed_max = max($failed_ip_count, $failed_ident_count);
                
                // Avoid spamming security alerts by checking if we already alerted in the last 15 minutes for this target
                $stmt_alert = $pdo->prepare("SELECT COUNT(*) FROM activity_logs WHERE action = 'SECURITY_ALERT' AND description LIKE ? AND created_at >= NOW() - INTERVAL 15 MINUTE");
                $stmt_alert->execute(['%Brute Force Terdeteksi%' . $target_label . '%']);
                $already_alerted = $stmt_alert->fetchColumn();
                
                if ($already_alerted == 0) {
                    $alert_desc = "Brute Force Terdeteksi: Upaya masuk tidak sah berulang pada " . $target_label . " ($failed_max kali kegagalan dalam 15 menit terakhir).";
                    
                    // Insert security alert log
                    $stmt_ins = $pdo->prepare("INSERT INTO activity_logs (user_id, name, role, action, description, ip_address, location, user_agent, device_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt_ins->execute([null, 'Security Watchdog', 'system', 'SECURITY_ALERT', $alert_desc, $ip, $location, $ua, $device]);
                    
                    // Notify all super_admins
                    notify_roles(['super_admin'], 'Peringatan Keamanan: Upaya Brute Force!', $alert_desc, 'danger', 'index.php?page=admin_logs');
                }
            }
        } catch (PDOException $e) {
            error_log("Failed checking brute force security alert: " . $e->getMessage());
        }
    } elseif ($action === 'UNAUTHORIZED_ACCESS') {
        try {
            $alert_desc = "Akses Tanpa Izin Terdeteksi: Pengguna $name (Role: $role) mencoba mengakses halaman terbatas. IP: $ip.";
            
            // Insert security alert log
            $stmt_ins = $pdo->prepare("INSERT INTO activity_logs (user_id, name, role, action, description, ip_address, location, user_agent, device_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_ins->execute([$user_id, 'Security Watchdog', 'system', 'SECURITY_ALERT', $alert_desc, $ip, $location, $ua, $device]);
            
            // Notify all super_admins
            notify_roles(['super_admin'], 'Peringatan Keamanan: Akses Tanpa Izin!', $alert_desc, 'danger', 'index.php?page=admin_logs');
        } catch (PDOException $e) {
            error_log("Failed checking unauthorized access security alert: " . $e->getMessage());
        }
    }
}

/**
 * Resolve client IP to country, city, and ISP safely
 */
function get_ip_location($ip) {
    if ($ip === '::1' || $ip === '127.0.0.1') {
        return 'Localhost';
    }
    
    // Check if it is a valid IPv4 address before using ip2long
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $ip_long = ip2long($ip);
        if ($ip_long !== false) {
            $private_ranges = [
                ['10.0.0.0', '10.255.255.255'],
                ['172.16.0.0', '172.31.255.255'],
                ['192.168.0.0', '192.168.255.255']
            ];
            foreach ($private_ranges as $range) {
                $start = ip2long($range[0]);
                $end = ip2long($range[1]);
                if ($ip_long >= $start && $ip_long <= $end) {
                    return 'Jaringan Lokal';
                }
            }
        }
    } else {
        // Simple check for private IPv6 (like fe80::, fd00:: etc.)
        if (strpos($ip, 'fe80:') === 0 || strpos($ip, 'fd00:') === 0 || strpos($ip, 'fc00:') === 0) {
            return 'Jaringan Lokal (IPv6)';
        }
    }
    
    // Call ip-api.com with 1.5 second timeout
    $url = "http://ip-api.com/json/" . urlencode($ip) . "?fields=status,country,city,isp";
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 1.5, // strict timeout
            'header' => "User-Agent: AlumniLink-AuditTrail/1.0\r\n"
        ]
    ]);
    
    try {
        $response = @file_get_contents($url, false, $ctx);
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['status']) && $data['status'] === 'success') {
                $location_parts = [];
                if (!empty($data['city'])) $location_parts[] = $data['city'];
                if (!empty($data['country'])) $location_parts[] = $data['country'];
                if (!empty($data['isp'])) $location_parts[] = "(" . $data['isp'] . ")";
                return implode(', ', $location_parts) ?: 'Lokasi Tidak Diketahui';
            }
        }
    } catch (Exception $e) {
        // Fail silently
    }
    
    return 'Lokasi Tidak Diketahui';
}

/**
 * Parse detailed OS and Browser from User Agent
 */
function get_ua_details($ua) {
    $os = 'Unknown OS';
    $browser = 'Unknown Browser';

    // Detect OS
    if (preg_match('/windows|win32/i', $ua)) {
        $os = 'Windows';
    } elseif (preg_match('/macintosh|mac os x/i', $ua)) {
        $os = 'macOS';
    } elseif (preg_match('/android/i', $ua)) {
        $os = 'Android';
    } elseif (preg_match('/iphone|ipad|ipod/i', $ua)) {
        $os = 'iOS';
    } elseif (preg_match('/linux/i', $ua)) {
        $os = 'Linux';
    }

    // Detect Browser
    if (preg_match('/edge|edg/i', $ua)) {
        $browser = 'Edge';
    } elseif (preg_match('/chrome|crios/i', $ua)) {
        $browser = 'Chrome';
    } elseif (preg_match('/firefox|fxios/i', $ua)) {
        $browser = 'Firefox';
    } elseif (preg_match('/safari/i', $ua)) {
        $browser = 'Safari';
    } elseif (preg_match('/msie|trident/i', $ua)) {
        $browser = 'Internet Explorer';
    } elseif (preg_match('/opera|opr/i', $ua)) {
        $browser = 'Opera';
    }

    return "$os - $browser";
}

/**
/**
 * Bulletproof helper to get clean client IP.
 *
 * Priority order (most trusted → least trusted):
 *   1. CF-Connecting-IP  (Cloudflare CDN)
 *   2. X-Real-IP         (Nginx reverse-proxy)
 *   3. X-Forwarded-For   (load balancers – only first PUBLIC IP is trusted)
 *   4. REMOTE_ADDR       (direct connection – always the final fallback)
 *
 * NOTE: HTTP_CLIENT_IP is intentionally omitted because it is trivially
 * spoofable by any client and offers no reliability on a shared host.
 *
 * @return string  Validated IPv4 or IPv6 address.
 */
function get_client_ip(): string {
    /**
     * Check whether an IP is in a private / reserved range.
     * Returns TRUE for loopback, link-local, ULA, and RFC-1918 addresses.
     */
    $is_private = function(string $ip): bool {
        return filter_var($ip, FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    };

    // ── 1. Cloudflare ────────────────────────────────────────────────────────
    // CF-Connecting-IP is set by Cloudflare's edge nodes; treat it as trusted
    // only when REMOTE_ADDR itself is a known Cloudflare IP. On a local/dev
    // server without Cloudflare this header does not exist, so it is safe.
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip; // CF always supplies the true client IP
        }
    }

    // ── 2. Nginx / Apache X-Real-IP ──────────────────────────────────────────
    // Set by a trusted upstream (e.g. `proxy_set_header X-Real-IP $remote_addr`
    // in nginx.conf). Like CF-Connecting-IP, it is a single IP, not a chain.
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = trim($_SERVER['HTTP_X_REAL_IP']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    // ── 3. X-Forwarded-For ───────────────────────────────────────────────────
    // Format: "client, proxy1, proxy2, ..."
    // The leftmost IP is the original client; however, anyone can forge it.
    // We iterate left-to-right and pick the FIRST non-private, valid IP.
    // Private/reserved IPs in the chain are internal proxies → skip them.
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $candidates = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            // Strip port if present (e.g. "1.2.3.4:12345")
            if (str_contains($candidate, ':') && !str_contains($candidate, '::')) {
                // IPv4 with port
                $candidate = explode(':', $candidate)[0];
            }
            if (filter_var($candidate, FILTER_VALIDATE_IP) && !$is_private($candidate)) {
                return $candidate;
            }
        }
    }

    // ── 4. Direct connection (always available, never forgeable) ─────────────
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // Normalize IPv6 loopback "::1" → "127.0.0.1" for display consistency
    if ($remote === '::1') {
        $remote = '127.0.0.1';
    }
    return $remote;
}

/**
 * Basic device type detection from User Agent
 */
function get_device_type($ua) {
    $ua = strtolower($ua);
    if (preg_match('/(tablet|ipad|playbook)|(android(?!.*(mobi|opera mini)))/i', $ua)) {
        return 'Tablet';
    }
    if (preg_match('/(up.browser|up.link|mmp|symbian|smartphone|midp|wap|phone|android|iemobile|iphone)/i', $ua)) {
        return 'Mobile';
    }
    return 'Desktop';
}

/**
 * Create a single notification for a specific user
 */
function add_notification($user_id, $title, $message, $type = 'info', $link = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, is_read, link, created_at) VALUES (?, ?, ?, ?, 0, ?, CURRENT_TIMESTAMP)");
        return $stmt->execute([$user_id, $title, $message, $type, $link]);
    } catch (PDOException $e) {
        error_log("Failed to insert notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Broadcast notification to all users matching specific roles
 */
function notify_roles($roles, $title, $message, $type = 'info', $link = null) {
    global $pdo;
    if (empty($roles)) return false;
    
    // Convert string to array if a single role is provided
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    
    try {
        $in_clause = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $pdo->prepare("SELECT id FROM users WHERE role IN ($in_clause)");
        $stmt->execute($roles);
        $uids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($uids as $uid) {
            add_notification($uid, $title, $message, $type, $link);
        }
        return true;
    } catch (PDOException $e) {
        error_log("Failed to notify roles: " . $e->getMessage());
        return false;
    }
}

/**
 * Broadcast notification to all alumni
 */
function notify_all_alumni($title, $message, $type = 'info', $link = null) {
    return notify_roles(['alumni'], $title, $message, $type, $link);
}

/**
 * Security Watchdog: checks for session hijacking (IP or UA changes mid-session)
 */
function run_security_watchdog() {
    global $pdo;
    
    // Only run if session is active and user is logged in
    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION['user_id'])) {
        return;
    }

    $current_ip = get_client_ip();
    $current_ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $user_id = $_SESSION['user_id'];
    $name = $_SESSION['user_name'] ?? 'Unknown User';
    $role = $_SESSION['user_role'] ?? 'guest';

    // 1. Initialize session variables if not set
    if (!isset($_SESSION['secure_user_ip'])) {
        $_SESSION['secure_user_ip'] = $current_ip;
    }
    if (!isset($_SESSION['secure_user_ua'])) {
        $_SESSION['secure_user_ua'] = $current_ua;
    }

    // 2. Detect Mismatch
    $ip_mismatch = ($_SESSION['secure_user_ip'] !== $current_ip);
    $ua_mismatch = ($_SESSION['secure_user_ua'] !== $current_ua);

    if ($ip_mismatch || $ua_mismatch) {
        $old_ip = $_SESSION['secure_user_ip'];
        $old_ua = $_SESSION['secure_user_ua'];

        // Detail the suspicious activity
        $details = [];
        if ($ip_mismatch) {
            $details[] = "IP berubah dari $old_ip menjadi $current_ip";
        }
        if ($ua_mismatch) {
            $details[] = "User Agent berubah";
        }
        $desc = "Aktivitas mencurigakan (Potensi Pembajakan Sesi): " . implode(', ', $details) . " untuk pengguna $name (Role: $role, ID: $user_id).";

        // Log the security alert
        try {
            $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, name, role, action, description, ip_address, location, user_agent, device_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $user_id,
                'Security Watchdog',
                'system',
                'SECURITY_ALERT',
                $desc,
                $current_ip,
                get_ip_location($current_ip),
                $current_ua,
                get_device_type($current_ua)
            ]);

            // Notify superadmins
            notify_roles(
                ['super_admin'],
                'Peringatan Keamanan: Sesi Mencurigakan!',
                $desc,
                'danger',
                'index.php?page=admin_logs'
            );
        } catch (PDOException $e) {
            error_log("Security Watchdog logging failed: " . $e->getMessage());
        }

        // If it's an administrative role, or if UA mismatched, destroy session for protection
        if (in_array($role, ['super_admin', 'admin_legalisir', 'admin_tracer', 'keuangan']) || $ua_mismatch) {
            // Destroy session
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            session_destroy();

            // Redirect to login page with security error
            header("Location: index.php?page=login&error=security_violation");
            exit();
        } else {
            // Update session to the new details for regular users to prevent infinite alerts
            $_SESSION['secure_user_ip'] = $current_ip;
            $_SESSION['secure_user_ua'] = $current_ua;
        }
    }
}

// Run security watchdog automatically on every page load
run_security_watchdog();
?>
