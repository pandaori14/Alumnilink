<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once '../includes/csrf.php';
require_once '../includes/rate_limit.php';
require_once '../includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../index.php?page=login");
        exit();
    }

    $user_id = $_SESSION['user_id'];
    
    // Check rate limit (max 10 tracer submissions per 15 minutes)
    check_rate_limit('SUBMIT_TRACER', 10, 15);
    
    // Check Verification Status
    $stmt = $pdo->prepare("SELECT email, is_verified FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch();
    if (!$user_data || !$user_data->is_verified) {
        header("Location: ../index.php?page=tracer&error=unverified");
        exit();
    }

    // Collect dynamic responses
    $responses = [];
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'q_') === 0) {
            $responses[$key] = $value;
        }
    }
    $responses_json = json_encode($responses);

    // Map legacy fields dynamically based on mapping_key in tracer_questions
    $work_status = 'unemployed';
    $company_name = null;
    $job_title = null;
    $salary_range = null;
    $field_relevance = null;

    $stmt_map = $pdo->query("SELECT id, mapping_key FROM tracer_questions WHERE mapping_key IS NOT NULL");
    $mappings = $stmt_map->fetchAll();
    foreach ($mappings as $m) {
        $post_key = 'q_' . $m->id;
        if (isset($_POST[$post_key])) {
            $val = $_POST[$post_key];
            if (is_array($val)) {
                $val = implode(', ', $val);
            } else {
                $val = trim($val);
            }
            
            // Normalisasi memakai fungsi bersama di includes/tracer_lib.php.
            // Sebelumnya logika ini ditulis ulang secara terpisah di sisi baca
            // (api/admin/tracer_analytics.php) dan keduanya menyimpang, membuat
            // KPI keselarasan bidang selalu 0%. Satu sumber kebenaran mencegah
            // penyimpangan itu terulang.
            if ($m->mapping_key === 'work_status') {
                $work_status = tracer_normalize_work_status($val) ?? $val;
            } elseif ($m->mapping_key === 'company_name') {
                $company_name = $val;
            } elseif ($m->mapping_key === 'job_title') {
                $job_title = $val;
            } elseif ($m->mapping_key === 'salary_range') {
                $salary_range = $val;
            } elseif ($m->mapping_key === 'field_relevance') {
                $field_relevance = tracer_normalize_relevance($val);
            }
        }
    }

    try {
        // Insert tracer submission with both legacy fields and new dynamic responses
        $stmt = $pdo->prepare("INSERT INTO tracer_submissions (user_id, work_status, company_name, job_title, salary_range, field_relevance, responses) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $work_status, $company_name, $job_title, $salary_range, $field_relevance, $responses_json]);

        $tracer_major   = trim($_POST['tracer_major'] ?? '');
        $tracer_year    = trim($_POST['tracer_graduation_year'] ?? '');
        $year_val       = $tracer_year !== '' ? (int)$tracer_year : null;
        $tracer_phone   = trim($_POST['tracer_phone'] ?? '');
        if ($tracer_phone !== '') {
            $tracer_phone = preg_replace('/[\s\-\(\)]+/', '', $tracer_phone);
            if (substr($tracer_phone, 0, 1) === '0') {
                $tracer_phone = '+62' . substr($tracer_phone, 1);
            } elseif (substr($tracer_phone, 0, 2) === '62') {
                $tracer_phone = '+' . $tracer_phone;
            } elseif (substr($tracer_phone, 0, 1) !== '+') {
                $tracer_phone = '+' . $tracer_phone;
            }
        } else {
            $tracer_phone = null;
        }
        $tracer_address = trim($_POST['tracer_address'] ?? '');

        // Update user's last_tracer_update, major, graduation_year, phone, and address
        $stmt = $pdo->prepare("UPDATE users SET last_tracer_update = CURRENT_TIMESTAMP, major = ?, graduation_year = ?, phone = ?, address = ? WHERE id = ?");
        $stmt->execute([$tracer_major, $year_val, $tracer_phone, $tracer_address, $user_id]);

        log_activity('SUBMIT_TRACER', "User $user_id submitted tracer study");
        reset_rate_limit('SUBMIT_TRACER');

        // Notify alumnus
        add_notification(
            $user_id,
            'Tracer Study Berhasil Diisi',
            'Terima kasih banyak telah melengkapi kuesioner Tracer Study. Partisipasi Anda sangat berharga untuk peningkatan mutu dan proses akreditasi universitas.',
            'success',
            'index.php?page=dashboard'
        );

        // Notify admins
        $alumni_name = $_SESSION['user_name'] ?? 'Alumni';
        notify_roles(
            ['super_admin', 'admin_tracer'],
            'Tracer Study Baru Masuk',
            'Alumni ' . htmlspecialchars($alumni_name) . ' telah berhasil mengirimkan kuesioner Tracer Study baru.',
            'info',
            'index.php?page=admin_tracer'
        );

        // --- EMAIL NOTIFICATION: Tracer Thank You ---
        if (!empty($user_data->email)) {
            send_tracer_thankyou_email($user_data->email, $alumni_name);
        }

        header("Location: ../index.php?page=dashboard&success=tracer");
        exit();
    } catch (PDOException $e) {
        error_log("Tracer Submit Error: " . $e->getMessage());
        error_system('Terjadi kesalahan sistem saat menyimpan data tracer. Silakan hubungi administrator.');
    }
} else {
    header("Location: ../index.php?page=tracer");
    exit();
}
?>
