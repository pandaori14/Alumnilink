<?php
require_once __DIR__ . '/includes/session_boot.php';
require_once 'config/db.php';
require_once 'includes/csrf.php';

// Keluarkan pengguna yang terlalu lama tidak beraktivitas.
// Dipanggil di sini karena config/db.php sudah dimuat di atas, sehingga
// pengaturan session_timeout_minutes dapat dibaca.
session_enforce_timeout();

// Auth Check (Basic Placeholder)
$is_logged_in = isset($_SESSION['user_id']);

// Auto-Migration: Ensure notifications table exists
// Digerbangi oleh penanda versi di config/db.php sehingga hanya berjalan
// sekali setelah berkas baru di-upload, bukan pada setiap permintaan.
try {
    if (!alumnilink_schema_is_current($pdo)) {

    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(128) NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        type VARCHAR(50) DEFAULT 'info',
        is_read TINYINT(1) DEFAULT 0,
        link VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Ensure activity_logs table has location column
    $log_cols = $pdo->query("SHOW COLUMNS FROM activity_logs LIKE 'location'")->fetch();
    if (!$log_cols) {
        $pdo->exec("ALTER TABLE activity_logs ADD COLUMN location VARCHAR(255) DEFAULT NULL AFTER ip_address");
    }
    // Update existing null locations for local IPs to 'Localhost'
    $pdo->exec("UPDATE activity_logs SET location = 'Localhost' WHERE ip_address IN ('::1', '127.0.0.1') AND location IS NULL");

    // Fix existing notifications table if user_id is still INT
    $notif_col = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'user_id'")->fetch();
    if ($notif_col && strpos(strtolower($notif_col->Type), 'int') !== false) {
        $pdo->exec("ALTER TABLE notifications MODIFY COLUMN user_id VARCHAR(128) NOT NULL");
    }

    // Ensure tracer_questions table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS tracer_questions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        question_text TEXT NOT NULL,
        question_type ENUM('text', 'number', 'radio', 'select', 'textarea', 'checkbox', 'date', 'rating') DEFAULT 'text',
        options TEXT NULL, -- JSON format for radio/select/checkbox
        is_required TINYINT(1) DEFAULT 1,
        order_no INT DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        mapping_key VARCHAR(50) DEFAULT NULL
    )");

    // Modify ENUM to support new types if table already existed
    $pdo->exec("ALTER TABLE tracer_questions MODIFY COLUMN question_type ENUM('text', 'number', 'radio', 'select', 'textarea', 'checkbox', 'date', 'rating') DEFAULT 'text'");

    // Ensure is_active column exists
    $active_col = $pdo->query("SHOW COLUMNS FROM tracer_questions LIKE 'is_active'")->fetch();
    if (!$active_col) {
        $pdo->exec("ALTER TABLE tracer_questions ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER order_no");
    }

    // Ensure mapping_key column exists
    $map_col = $pdo->query("SHOW COLUMNS FROM tracer_questions LIKE 'mapping_key'")->fetch();
    if (!$map_col) {
        $pdo->exec("ALTER TABLE tracer_questions ADD COLUMN mapping_key VARCHAR(50) DEFAULT NULL AFTER is_active");
    }

    // Insert default questions if empty
    $q_count = $pdo->query("SELECT COUNT(*) FROM tracer_questions")->fetchColumn();
    if ($q_count == 0) {
        $default_qs = [
            ['Status Karir Saat Ini', 'radio', json_encode(['Bekerja Full-time', 'Wirausaha', 'Studi Lanjut (Spesialis/S2)', 'Mencari Kerja']), 1, 1, 1, 'work_status'],
            ['Nama Instansi / Perusahaan', 'text', null, 1, 2, 1, 'company_name'],
            ['Jabatan / Posisi', 'text', null, 1, 3, 1, 'job_title'],
            ['Rentang Gaji Bulanan', 'select', json_encode(['< Rp 5 Juta', 'Rp 5 - 10 Juta', 'Rp 10 - 20 Juta', '> Rp 20 Juta']), 1, 4, 1, 'salary_range'],
            ['Relevansi dengan Bidang Kedokteran', 'radio', json_encode(['Sangat Relevan', 'Relevan', 'Cukup Relevan', 'Tidak Relevan']), 1, 5, 1, 'field_relevance']
        ];
        $stmt_q = $pdo->prepare("INSERT INTO tracer_questions (question_text, question_type, options, is_required, order_no, is_active, mapping_key) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($default_qs as $dq) $stmt_q->execute($dq);
    } else {
        // If they already exist, heuristically seed mappings if none exist
        $mapped_count = $pdo->query("SELECT COUNT(*) FROM tracer_questions WHERE mapping_key IS NOT NULL")->fetchColumn();
        if ($mapped_count == 0) {
            $qs = $pdo->query("SELECT * FROM tracer_questions ORDER BY order_no ASC")->fetchAll();
            $keys = ['work_status', 'company_name', 'job_title', 'salary_range', 'field_relevance'];
            foreach ($qs as $idx => $q) {
                if ($idx < 5) {
                    $pdo->prepare("UPDATE tracer_questions SET mapping_key = ? WHERE id = ?")->execute([$keys[$idx], $q->id]);
                }
            }
        }
    }

    // Ensure responses column exists in tracer_submissions
    $cols = $pdo->query("SHOW COLUMNS FROM tracer_submissions LIKE 'responses'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE tracer_submissions ADD COLUMN responses JSON DEFAULT NULL");
    }

    // Add avatar column to users table if not exists
    $columns = $pdo->query("SHOW COLUMNS FROM users LIKE 'avatar'")->fetchAll();
    if (empty($columns)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN avatar VARCHAR(255) DEFAULT NULL AFTER email");
    }

    // Ensure news_posts table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS news_posts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        image VARCHAR(255) NULL,
        type ENUM('berita', 'event', 'kegiatan') DEFAULT 'berita',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Add event_date and registration_link if they don't exist
    $columns = $pdo->query("SHOW COLUMNS FROM news_posts LIKE 'event_date'")->fetchAll();
    if (empty($columns)) {
        $pdo->exec("ALTER TABLE news_posts ADD COLUMN event_date DATETIME NULL AFTER type");
        $pdo->exec("ALTER TABLE news_posts ADD COLUMN registration_link VARCHAR(255) NULL AFTER event_date");
    }

    // Add email_notifications preference column to users if not exists
    $columns = $pdo->query("SHOW COLUMNS FROM users LIKE 'email_notifications'")->fetchAll();
    if (empty($columns)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email_notifications TINYINT(1) DEFAULT 1 AFTER is_verified");
    }

    // Ensure email_blasts history table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_blasts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        subject VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        filter_role VARCHAR(50) DEFAULT NULL,
        filter_major VARCHAR(255) DEFAULT NULL,
        filter_graduation_year INT DEFAULT NULL,
        recipient_count INT DEFAULT 0,
        sent_by VARCHAR(128) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Auto-seed: contact_email & contact_address if not exist
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('contact_email', ''), ('contact_address', '')");

    // Seluruh migrasi berhasil -- tandai agar dilewati pada permintaan berikutnya.
    alumnilink_mark_schema_current($pdo);

    } // selesai: if (!alumnilink_schema_is_current($pdo))
} catch (PDOException $e) {
    // Sebelumnya galat di sini ditelan tanpa jejak, sehingga migrasi yang
    // gagal tidak pernah terlihat. Kini dicatat ke error log, dan skema
    // sengaja TIDAK ditandai mutakhir agar dicoba lagi pada permintaan
    // berikutnya. Halaman tetap dirender supaya situs tidak ikut tumbang.
    error_log('AlumniLink auto-migration gagal: ' . $e->getMessage());
}

// Global Settings
$stmt = $pdo->query("SELECT * FROM settings");
$raw_settings = $stmt->fetchAll();
$sys_settings = [];
foreach ($raw_settings as $s) {
    $sys_settings[$s->setting_key] = $s->setting_value;
}
$system_logo = !empty($sys_settings['system_logo']) ? $sys_settings['system_logo'] : 'uploads/system/logo_1778236863.png';

// Maintenance Mode Logic
$is_maintenance = ($sys_settings['maintenance_mode'] ?? '0') == '1';
$requested_page = $_GET['page'] ?? '';

if ($is_maintenance && $is_logged_in && $_SESSION['user_role'] === 'alumni') {
    // Jika alumni mencoba akses selain landing, arahkan ke maintenance
    if ($requested_page !== 'landing') {
        $page = 'maintenance';
    }
}

// Routing Logic dengan Strict Validation (Security Point #5)
$page = (isset($page) && $page === 'maintenance') ? 'maintenance' : (isset($_GET['page']) ? $_GET['page'] : '');

// Jika tidak ada halaman yang diminta, arahkan ke landing jika belum login, atau dashboard jika sudah
if (empty($page)) {
    $page = $is_logged_in ? 'dashboard' : 'landing';
}

// Jika maintenance aktif dan user belum login mencoba masuk ke halaman selain landing/login
if ($is_maintenance && !in_array($page, ['landing', 'login', 'maintenance'])) {
    // Only allow admins
    if (!$is_logged_in || $_SESSION['user_role'] === 'alumni') {
        $page = 'maintenance';
    }
}

// Hanya izinkan alfanumerik dan underscore, maks 50 karakter
if (!preg_match('/^[a-z0-9_]{1,50}$/', $page)) {
    $page = 'dashboard';
}

// Halaman yang boleh dibuka tanpa masuk.
//
// 'email_unsubscribe' WAJIB ada di sini. Setiap e-mail broadcast memuat
// tautan berhenti-berlangganan dan header List-Unsubscribe yang menunjuk
// halaman itu — dan penerimanya hampir selalu TIDAK sedang masuk. Selama
// halaman itu tidak terdaftar, tautannya mengalihkan orang ke halaman depan
// tanpa penjelasan apa pun.
//
// Akibatnya bukan sekadar tidak nyaman: penerima yang tidak menemukan cara
// berhenti akan menekan tombol "laporkan spam" di penyedia e-mailnya, dan
// itulah yang paling cepat menghancurkan reputasi pengirim. Halaman ini
// aman dibuka publik karena dijaga token bertanda tangan, bukan oleh sesi.
if (!$is_logged_in && !in_array($page, ['login', 'register', 'forgot_password', 'reset_password', 'landing', 'maintenance', 'news_detail', 'all_news', 'terms', 'email_unsubscribe'])) {
    header("Location: index.php?page=landing");
    exit();
}

// Role-based Access Control
if (strpos($page, 'admin_') === 0 && $_SESSION['user_role'] === 'alumni') {
    log_activity('UNAUTHORIZED_ACCESS', 'Percobaan akses tidak sah ke halaman admin: ' . $page);
    header("Location: index.php?page=dashboard&error=unauthorized");
    exit();
}

// Penjagaan halaman admin per peran.
//
// Menyamakan tiga lapisan yang sebelumnya tidak sinkron:
//   1. menu di sidebar  (includes/header.php)
//   2. halaman ini
//   3. handler          (require_capability)
// Sebelumnya sidebar menyembunyikan menu, tetapi halamannya tetap dapat
// dibuka lewat URL langsung oleh peran staf mana pun. Halaman terbuka penuh,
// lalu aksinya ditolak saat disimpan -- alur yang membingungkan.
//
// Mengikuti sakelar settings.rbac_enforce yang sama: selama masih mode audit,
// pelanggaran hanya dicatat dan halaman tetap dibuka.
if (strpos($page, 'admin_') === 0 && $is_logged_in) {
    require_once 'includes/auth_guard.php';

    $role_now = $_SESSION['user_role'] ?? '';
    if (!role_can_open_page($page, $role_now)) {
        if (rbac_is_enforced()) {
            log_activity('RBAC_DENIED', "Peran \"$role_now\" ditolak membuka halaman $page.");
            header("Location: index.php?page=dashboard&error=unauthorized");
            exit();
        }
        log_activity(
            'RBAC_AUDIT',
            "Peran \"$role_now\" membuka halaman $page yang di luar wewenangnya. " .
            "Mode audit -- akses TETAP diizinkan."
        );
    }
}

// ─────────────────────────────────────────────────────────
// Gate akun alumni yang belum diverifikasi
// ─────────────────────────────────────────────────────────
// handlers/auth.php meloloskan login tanpa memeriksa is_verified, dan
// penjagaannya selama ini tersebar di masing-masing halaman
// (pages/legalisir.php, pages/events.php, pages/dashboard.php). Halaman baru
// yang lupa memeriksanya otomatis terbuka bagi akun yang belum diverifikasi.
//
// Daftar putih di bawah sengaja dibuat LONGGAR supaya tidak ada alumni yang
// tiba-tiba terkunci; yang dibatasi hanyalah layanan yang memang mensyaratkan
// akun terverifikasi. Penjagaan lama di masing-masing halaman TIDAK dihapus
// dan tetap berlaku sebagai lapisan kedua.
if ($is_logged_in && ($_SESSION['user_role'] ?? '') === 'alumni') {

    $pages_open_to_unverified = [
        'dashboard', 'profile', 'notifications', 'tracer', 'guide', 'terms',
        'all_news', 'news_detail', 'events', 'donasi', 'donasi_detail',
        'landing', 'maintenance', 'email_unsubscribe',
    ];

    // Kueri hanya dijalankan saat halaman berada DI LUAR daftar putih,
    // sehingga mayoritas pemuatan halaman tidak menambah beban database.
    if (!in_array($page, $pages_open_to_unverified, true)) {
        try {
            $stmt_verify = $pdo->prepare("SELECT is_verified FROM users WHERE id = ?");
            $stmt_verify->execute([$_SESSION['user_id']]);
            $is_account_verified = (bool)$stmt_verify->fetchColumn();
        } catch (PDOException $e) {
            // Bila pemeriksaan gagal, jangan mengunci pengguna.
            error_log('Gate is_verified gagal: ' . $e->getMessage());
            $is_account_verified = true;
        }

        if (!$is_account_verified) {
            header("Location: index.php?page=dashboard&error=unverified");
            exit();
        }
    }
}

// Page Titles mapping
$titles = [
    'dashboard' => 'Ringkasan',
    'tracer'    => 'Tracer Alumni',
    'legalisir' => 'Layanan Legalisir',
    'profile'   => 'Profil Saya',
    'settings'  => 'Pengaturan',
    'admin_keuangan' => 'Laporan Keuangan',
    'admin_tracer' => 'Laporan Tracer Alumni',
    'admin_legalisir' => 'Kelola Legalisir',
    'admin_news' => 'Kelola Berita & Event',
    'admin_logs' => 'Audit Trail',
    'admin_majors' => 'Kelola Program Studi',
    'admin_email_blast' => 'Email Blast',
    'login'     => 'Masuk Ke Akun',
    'register'  => 'Daftar Akun',
    'forgot_password' => 'Lupa Kata Sandi',
    'reset_password' => 'Atur Ulang Kata Sandi',
    'news_detail' => 'Detail Berita',
    'all_news'    => 'Kabar Alumni',
    'events'      => 'Event & Kegiatan',
    'guide'       => 'Panduan Pengguna'
];

$page_title = isset($titles[$page]) ? $titles[$page] : 'AlumniLink';

// Load Page
if (in_array($page, ['login', 'register', 'forgot_password', 'reset_password', 'landing', 'maintenance', 'news_detail', 'all_news', 'terms'])) {
    include "pages/$page.php";
} else {
    $file = "pages/$page.php";
    
    // Custom logic for admin dashboard
    if ($page === 'dashboard' && $_SESSION['user_role'] !== 'alumni') {
        $file = "pages/admin_dashboard.php";
    }

    if (file_exists($file)) {
        include 'includes/header.php';
        include $file;
        include 'includes/footer.php';
    } else {
        include 'includes/header.php';
        echo "<div class='glass p-10 rounded-3xl text-center'><h2 class='text-2xl font-bold outfit'>Halaman Tidak Ditemukan</h2><p class='text-slate-500 mt-2'>Maaf, halaman yang Anda cari tidak tersedia.</p></div>";
        include 'includes/footer.php';
    }
}
?>
