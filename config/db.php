<?php
// Environment Loader
function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $name = trim($parts[0]);
            $value = trim($parts[1], " \"'");
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
        }
    }
}
loadEnv(dirname(__DIR__) . '/.env');

/**
 * Penimpaan khusus mesin pengembang.
 *
 * ── Mengapa ini ada ────────────────────────────────────────────────────
 * Selama ini folder kerja lokal memakai .env yang SAMA dengan server:
 * DB_HOST menunjuk <host-basis-data> dan APP_URL menunjuk alamat produksi.
 * Akibatnya setiap uji, setiap migrasi, dan setiap skrip yang dijalankan
 * di komputer pengembang menulis LANGSUNG ke basis data produksi —
 * tanpa satu pun tanda di layar bahwa itu yang sedang terjadi.
 *
 * .env.local dimuat SESUDAH .env sehingga nilainya menang. Berkas itu
 * ada di .gitignore dan TIDAK boleh diunggah ke server; bila kebetulan
 * tidak ada — seperti di server — perilaku kembali persis seperti semula.
 *
 * Konvensinya sengaja meniru Laravel/Symfony agar tidak perlu dijelaskan
 * kepada pengembang berikutnya.
 */
loadEnv(dirname(__DIR__) . '/.env.local');

// Database Configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'alumnilink');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// Base URL Detection
$default_url = (isset($_SERVER['HTTP_HOST']) ? (($_SERVER['HTTPS'] ?? 'off') !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/alumnilink' : 'http://localhost/alumnilink');
define('BASE_URL', getenv('APP_URL') ?: $default_url);

/**
 * ─────────────────────────────────────────────────────────
 * PENGGERBANGAN AUTO-MIGRATION
 * ─────────────────────────────────────────────────────────
 * Sebelumnya seluruh blok migrasi di berkas ini DAN di index.php dieksekusi
 * pada SETIAP permintaan HTTP. Artinya setiap kali halaman dibuka, server
 * menjalankan belasan perintah CREATE TABLE / SHOW COLUMNS, memindai seluruh
 * tabel users, lalu melakukan UPDATE baris per baris.
 *
 * Penanda versi di bawah membuat migrasi berjalan SEKALI saja: pada
 * permintaan pertama setelah berkas baru di-upload. Permintaan berikutnya
 * melewatinya sepenuhnya.
 *
 * Sifat "tinggal upload" tetap terjaga -- tidak ada langkah manual di server.
 *
 * Naikkan nomor versi di bawah setiap kali menambahkan migrasi baru, agar
 * migrasi tersebut ikut berjalan sekali di server setelah di-upload.
 */
define('ALUMNILINK_SCHEMA_VERSION', '2026.09.10.4');

/**
 * Benar bila skema database sudah sesuai versi yang diharapkan kode ini.
 * Mengembalikan false bila tabel settings belum ada (instalasi baru),
 * sehingga migrasi tetap dijalankan.
 */
function alumnilink_schema_is_current($pdo)
{
    try {
        $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'");
        return $stmt->fetchColumn() === ALUMNILINK_SCHEMA_VERSION;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Tandai skema sudah mutakhir. Hanya dipanggil setelah SELURUH blok migrasi
 * -- baik di config/db.php maupun di index.php -- selesai tanpa galat.
 */
function alumnilink_mark_schema_current($pdo)
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->execute([ALUMNILINK_SCHEMA_VERSION]);
    } catch (PDOException $e) {
        error_log('Gagal menandai schema_version: ' . $e->getMessage());
    }
}

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    // Set PDO to throw exceptions on error
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Set default fetch mode to object
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);

    // Seluruh blok migrasi di bawah hanya berjalan sekali per versi skema.
    if (!alumnilink_schema_is_current($pdo)) {

    // Auto-initialize majors table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `majors` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `major_code` varchar(20) NOT NULL UNIQUE,
        `major_name` varchar(150) NOT NULL,
        `faculty` varchar(150) NOT NULL,
        `accreditation` varchar(10) NOT NULL DEFAULT 'A',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Check if old non-FK majors exist (e.g. L200 Informatika), then reset table for FK UMS focus
    $stmt_check = $pdo->query("SELECT COUNT(*) FROM `majors` WHERE `major_code` = 'L200'");
    if ($stmt_check->fetchColumn() > 0) {
        $pdo->exec("TRUNCATE TABLE `majors`");
    }

    // Check if empty, then seed FK UMS majors
    $stmt = $pdo->query("SELECT COUNT(*) FROM `majors`");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO `majors` (`major_code`, `major_name`, `faculty`, `accreditation`) VALUES
            ('J500', 'S1 Kedokteran', 'Fakultas Kedokteran', 'Unggul'),
            ('J530', 'Profesi Dokter', 'Fakultas Kedokteran', 'Unggul'),
            ('M500', 'S2 Magister Administrasi Rumah Sakit (MARS)', 'Fakultas Kedokteran', 'Baik Sekali')
        ");
    }

    // Auto-migrate legalisir_document_types to include target_major_code and is_akreditasi if not present
    $stmt_dt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'legalisir_document_types'");
    $dt_val = $stmt_dt->fetchColumn();
    if ($dt_val && stripos($dt_val, 'target_major_code') === false) {
        $upgraded_dt = [
            ["id"=>"ijazah_s1", "name"=>"Ijazah S1 Kedokteran", "target_major_code"=>"J500", "is_akreditasi"=>false],
            ["id"=>"transkrip_s1", "name"=>"Transkrip Nilai S1 Kedokteran", "target_major_code"=>"J500", "is_akreditasi"=>false],
            ["id"=>"akreditasi_s1", "name"=>"Sertifikat Akreditasi S1", "target_major_code"=>"J500", "is_akreditasi"=>true],
            ["id"=>"ijazah_profesi", "name"=>"Ijazah Profesi", "target_major_code"=>"J530", "is_akreditasi"=>false],
            ["id"=>"transkrip_profesi", "name"=>"Transkrip Nilai Profesi Dokter", "target_major_code"=>"J530", "is_akreditasi"=>false],
            ["id"=>"akreditasi_profesi", "name"=>"Sertifikat Akreditasi Profesi Dokter", "target_major_code"=>"J530", "is_akreditasi"=>true],
            ["id"=>"lafal_sumpah", "name"=>"Lafal Sumpah Dokter", "target_major_code"=>"J530", "is_akreditasi"=>false],
            ["id"=>"ijazah_s2", "name"=>"Ijazah S2 MARS", "target_major_code"=>"M500", "is_akreditasi"=>false],
            ["id"=>"transkrip_s2", "name"=>"Transkrip S2 MARS", "target_major_code"=>"M500", "is_akreditasi"=>false],
            ["id"=>"akreditasi_s2", "name"=>"Sertifikat Akreditasi S2 MARS", "target_major_code"=>"M500", "is_akreditasi"=>true]
        ];
        $up_json = json_encode($upgraded_dt, JSON_UNESCAPED_UNICODE);
        $stmt_up = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'legalisir_document_types'");
        $stmt_up->execute([$up_json]);
    }

    // Auto-initialize accreditation_certificates table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `accreditation_certificates` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `major_code` varchar(50) NOT NULL,
        `certificate_name` varchar(255) NOT NULL,
        `start_year` int(11) NOT NULL,
        `end_year` int(11) NOT NULL,
        `file_path` varchar(255) NOT NULL,
        `uploaded_by` varchar(50) DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // ── Pengaturan yang sebelumnya ter-hardcode di dalam kode ──────────
    //
    // Seluruhnya disemai dengan nilai yang PERSIS SAMA seperti sebelumnya,
    // sehingga perilaku sistem tidak berubah sedikit pun setelah upload.
    // Superadmin kini dapat mengubahnya lewat menu Pengaturan Sistem.
    //
    // INSERT IGNORE dipakai agar nilai yang sudah disesuaikan tidak tertimpa
    // bila migrasi berjalan ulang.
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
        -- Aturan layanan
        ('tracer_validity_months',      '6'),
        ('tracer_reminder_years',       '3'),
        ('default_major_code',          'J500'),
        -- Keamanan
        ('password_min_length',         '6'),
        ('reset_token_expiry_minutes',  '60'),
        ('session_timeout_minutes',     '0'),
        -- Ambang rate limit (nilai bawaan sama dengan yang tertulis di kode)
        ('rate_limit_login_max',            '5'),  ('rate_limit_login_window',            '15'),
        ('rate_limit_register_max',         '5'),  ('rate_limit_register_window',         '15'),
        ('rate_limit_reset_password_max',   '5'),  ('rate_limit_reset_password_window',   '15'),
        ('rate_limit_forgot_password_max',  '3'),  ('rate_limit_forgot_password_window',  '15'),
        ('rate_limit_request_legalisir_max','10'), ('rate_limit_request_legalisir_window','15'),
        ('rate_limit_donation_max',        '10'),  ('rate_limit_donation_window',         '15'),
        ('rate_limit_submit_tracer_max',   '10'),  ('rate_limit_submit_tracer_window',    '15'),
        ('rate_limit_update_profile_max',  '10'),  ('rate_limit_update_profile_window',   '15'),
        ('rate_limit_email_blast_max',      '2'),  ('rate_limit_email_blast_window',      '30'),
        -- Operasional
        ('email_batch_size',            '20'),
        ('email_max_attempts',          '3'),
        ('geocoder_batch_size',         '10'),
        ('pagination_size',             '20'),
        -- Identitas visual
        ('brand_primary_color',         '#2563eb'),
        ('system_favicon',              '')
    ");

    // ── Pelaporan tracer: buka batas 5 pertanyaan ─────────────────────
    //
    // Sebelumnya hanya pertanyaan ber-mapping_key (maksimal 5) yang dapat
    // dilaporkan, padahal 16 dari 26 pertanyaan bertipe tertutup -- termasuk
    // matriks kompetensi 8 aspek yang justru diminta LAM-PTKes.
    //
    // Penanda ini disemai AKTIF untuk seluruh pertanyaan tertutup, sehingga
    // setelah upload seluruh data yang selama ini terkumpul langsung dapat
    // dilaporkan tanpa admin perlu mencentang satu per satu.
    $kolom_report = $pdo->query("SHOW COLUMNS FROM tracer_questions LIKE 'show_in_report'")->fetch();
    if (!$kolom_report) {
        $pdo->exec("ALTER TABLE tracer_questions ADD COLUMN show_in_report TINYINT(1) NOT NULL DEFAULT 1");
        $pdo->exec("UPDATE tracer_questions
                    SET show_in_report = CASE
                        WHEN question_type IN ('radio','select','checkbox','rating') THEN 1
                        ELSE 0 END");
    }

    // ── Selaraskan menu untuk halaman Laporan Akreditasi ──────────────
    //
    // Izin sidebar tersimpan di basis data dibuat sebelum halaman ini ada,
    // sehingga peran yang berhak membukanya tidak melihat menunya (menu dan
    // hak akses jadi tidak selaras). Halaman ini hanya menampilkan data
    // tracer yang sama, jadi peran yang sudah punya "Laporan Tracer"
    // otomatis mendapat menunya juga.
    $sp_row = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'sidebar_permissions'")->fetchColumn();
    if ($sp_row) {
        $sp = json_decode($sp_row, true);
        if (is_array($sp)) {
            $berubah = false;
            // Halaman baru diselaraskan dengan menu yang sudah dimiliki peran,
            // supaya tidak ada halaman yang bisa dibuka tanpa menunya tampil.
            $turunan = [
                'admin_tracer'        => 'admin_tracer_report',
                'admin_tracer_config' => 'admin_employer_survey',
                // Peta bukan halaman ber-awalan 'admin_', sehingga penjagaan
                // role_can_open_page() di index.php tidak pernah berlaku
                // padanya: SETIAP pengguna yang login bisa membukanya lewat
                // URL, sementara menunya tersembunyi. Menu kini disamakan
                // dengan kenyataan itu; pembatasan yang sesungguhnya
                // dilakukan di tingkat DATA (lihat api/alumni/geodistribution.php),
                // bukan dengan menyembunyikan tautan.
                'dashboard'           => 'alumni_map',
                // Peran yang sudah mengelola Database Alumni otomatis
                // mendapat menu Impor Massal — kapabilitasnya sama persis
                // (alumni.kelola), jadi tidak ada hak baru yang diberikan.
                'admin_alumni'        => 'admin_alumni_import',
                // Kemajuan Broadcast untuk peran yang sudah boleh mengirim.
                'admin_broadcast'     => 'admin_broadcast_status',
                // Audit Trail memang dirancang terbuka untuk seluruh staf —
                // page_capability_map() sengaja tidak mendaftarkannya dan
                // capability_super_admin_only() tidak menguncinya, sehingga
                // setiap peran staf sudah bisa membukanya lewat URL hari ini.
                // Yang tidak selaras justru menunya, yang terhapus oleh
                // daftar-putih usang. Menu disamakan dengan kenyataan itu.
                // Bila tidak dikehendaki, hilangkan centangnya di Pengaturan.
                'dashboard'           => 'admin_logs',
            ];
            foreach ($sp as $peran => $menus) {
                if (!is_array($menus)) {
                    continue;
                }
                foreach ($turunan as $induk => $baru) {
                    // Alumni diblokir keras dari seluruh halaman ber-awalan
                    // 'admin_' di index.php, jadi memberi mereka menunya
                    // hanya akan menghasilkan jalan buntu.
                    if ($peran === 'alumni' && strpos($baru, 'admin_') === 0) {
                        continue;
                    }
                    if (in_array($induk, $menus, true) && !in_array($baru, $sp[$peran], true)) {
                        $sp[$peran][] = $baru;
                        $berubah = true;
                    }
                }
            }
            // ── Pulihkan menu yang terhapus oleh daftar-putih usang ──
            //
            // handlers/admin_settings_handler.php memakai daftar-putih yang
            // tertinggal tujuh kunci menu, sehingga setiap penyimpanan
            // Pengaturan Sistem menghapus kunci-kunci itu dari sini. 'guide'
            // (Panduan Pengguna) termasuk yang hilang padahal ada di bawaan
            // seluruh peran. Sumbernya sudah diperbaiki lewat
            // includes/menu.php; baris berikut mengembalikan kerusakan yang
            // terlanjur terjadi. Hanya 'guide' yang dipulihkan — menu lain
            // dikembalikan lewat $turunan di atas, yang menghormati apa yang
            // memang dimiliki tiap peran.
            foreach ($sp as $peran => $menus) {
                if (is_array($menus) && !in_array('guide', $menus, true)) {
                    $sp[$peran][] = 'guide';
                    $berubah = true;
                }
            }

            // ── Buang menu yang tidak mungkin dibuka perannya ────────
            //
            // 'admin_users' tercentang untuk admin_tracer dan admin_legalisir,
            // tetapi kapabilitas 'pengguna.kelola' terkunci super_admin lewat
            // capability_super_admin_only(). Menunya tampil, halamannya
            // menolak: jalan buntu yang tidak bisa diperbaiki penggunanya
            // sendiri karena penguncian itu memang disengaja.
            foreach ($sp as $peran => $menus) {
                if ($peran !== 'super_admin' && is_array($menus)) {
                    $saring = array_values(array_diff($menus, ['admin_users']));
                    if (count($saring) !== count($menus)) {
                        $sp[$peran] = $saring;
                        $berubah = true;
                    }
                }
            }

            if ($berubah) {
                $stmt_sp = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'sidebar_permissions'");
                $stmt_sp->execute([json_encode($sp, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    // ── Survei Kepuasan Pengguna Lulusan (penilaian atasan) ───────────
    //
    // LAM-PTKes mensyaratkan penilaian atasan atas lulusan pada sejumlah
    // aspek kompetensi. Aspek tidak ditulis ulang di sini melainkan diambil
    // dari pertanyaan kompetensi tracer yang sudah ada (Q23-Q30), sehingga
    // penilaian atasan dan penilaian diri alumni dijamin memakai aspek dan
    // skala yang sama dan dapat disandingkan langsung.
    //
    // Atasan BUKAN pengguna sistem: mereka menerima tautan bertoken lewat
    // e-mail, pola URL kapabilitas yang sama dengan verify.php.
    $pdo->exec("CREATE TABLE IF NOT EXISTS `employer_surveys` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `alumni_user_id` varchar(128) NOT NULL,
        `submission_id` int(11) DEFAULT NULL,
        `employer_name` varchar(255) DEFAULT NULL,
        `employer_position` varchar(255) DEFAULT NULL,
        `employer_email` varchar(255) NOT NULL,
        `token` varchar(64) NOT NULL,
        `status` enum('pending','sent','completed','expired') NOT NULL DEFAULT 'pending',
        `sent_at` timestamp NULL DEFAULT NULL,
        `completed_at` timestamp NULL DEFAULT NULL,
        `expires_at` timestamp NULL DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `token` (`token`),
        KEY `alumni_user_id` (`alumni_user_id`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `employer_survey_answers` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `survey_id` int(11) NOT NULL,
        `question_id` int(11) NOT NULL,
        `answer_value` varchar(255) NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_survey_question` (`survey_id`, `question_id`),
        KEY `survey_id` (`survey_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Masa berlaku tautan survei atasan, dalam hari.
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('employer_survey_expiry_days', '30')");

    // ── Operasional legalisir ────────────────────────────────────────
    //
    // updated_at dibuat NULL DEFAULT NULL, BUKAN diisi dari created_at:
    // 16 baris yang sudah ada memang tidak punya jejak kapan terakhir
    // diubah, dan mengisinya dari created_at akan menyatakan lama proses
    // NOL untuk setiap pengajuan lama. Lebih baik ditampilkan "—" daripada
    // menghasilkan angka yang salah.
    $kolom_upd = $pdo->query("SHOW COLUMNS FROM legalisir_requests LIKE 'updated_at'")->fetch();
    if (!$kolom_upd) {
        $pdo->exec("ALTER TABLE legalisir_requests
                    ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL
                    ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
    }

    // Tidak berguna pada 16 baris, gratis, dan benar pada 16.000.
    $idx = $pdo->query("SHOW INDEX FROM legalisir_requests WHERE Key_name = 'idx_status_created'")->fetch();
    if (!$idx) {
        try {
            $pdo->exec("ALTER TABLE legalisir_requests ADD INDEX idx_status_created (status, created_at)");
        } catch (PDOException $e) {
            // Indeks bersifat penyempurnaan; kegagalannya tidak boleh
            // menghentikan seluruh migrasi (catch terluar akan mematikan situs).
            error_log('Gagal membuat idx_status_created: ' . $e->getMessage());
        }
    }

    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
        ('legalisir_sla_warn_days', '3'),
        ('legalisir_sla_breach_days', '7')");

    // ── Samakan collation dua tabel yang menyimpang ──────────────────
    //
    // 24 tabel memakai utf8mb4_general_ci; hanya `unsubscribes` dan
    // `email_delivery_log` yang utf8mb4_unicode_ci. Selisih itu tidak
    // pernah terasa sampai ada kueri yang MEMBANDINGKAN kolom antar-tabel:
    //
    //     WHERE unsubscribes.email = users.email
    //     -> SQLSTATE[HY000] 1267 Illegal mix of collations
    //
    // Penyaringan penerima broadcast dan penandaan alamat mati keduanya
    // membutuhkan perbandingan itu. Menaburkan COLLATE di setiap kueri
    // hanya menyembunyikan penyebabnya dan akan terlupakan pada kueri
    // berikutnya; yang benar adalah menyamakan tabelnya.
    //
    // Aman dilakukan: unsubscribes kosong, email_delivery_log kecil, dan
    // general_ci tidak lebih ketat daripada unicode_ci untuk alamat e-mail
    // (keduanya tidak peka huruf besar-kecil), jadi kunci UNIQUE pada
    // unsubscribes.email tidak akan bertabrakan.
    foreach (['unsubscribes', 'email_delivery_log'] as $tabel_col) {
        try {
            $col = $pdo->query(
                "SELECT table_collation FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = '$tabel_col'"
            )->fetchColumn();
            if ($col && $col !== 'utf8mb4_general_ci') {
                $pdo->exec("ALTER TABLE `$tabel_col`
                            CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
            }
        } catch (PDOException $e) {
            error_log("Gagal menyamakan collation $tabel_col: " . $e->getMessage());
        }
    }

    // Halaman kemajuan broadcast menyaring email_queue per broadcast_id.
    try {
        $idx_bc = $pdo->query("SHOW INDEX FROM email_queue WHERE Key_name = 'idx_broadcast'")->fetch();
        if (!$idx_bc) {
            $pdo->exec("ALTER TABLE email_queue ADD INDEX idx_broadcast (broadcast_id)");
        }
    } catch (PDOException $e) {
        error_log('Gagal membuat idx_broadcast: ' . $e->getMessage());
    }

    // ── Pengaturan broadcast berskala ────────────────────────────────
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
        ('email_throttle_ms', '1500'),
        ('email_daily_limit', '2000'),
        ('email_bounce_threshold', '3')");

    // ── Indeks preventif pada tabel yang tumbuh ──────────────────────
    //
    // Ketiganya belum punya indeks selain PRIMARY. Tidak terasa pada ratusan
    // baris, tetapi activity_logs bertambah pada SETIAP aksi pengguna dan
    // akan menjadi tabel terbesar lebih dulu daripada yang lain.
    //
    // Masing-masing dibungkus try/catch SENDIRI. Ini bukan kehati-hatian
    // berlebihan: catch terluar blok migrasi ini menutup seluruh situs dengan
    // die("Koneksi database gagal"). Satu ALTER yang gagal tanpa catch sendiri
    // berarti situs mati total pada permintaan pertama setelah upload.
    foreach ([
        ['activity_logs', 'idx_action_created', '(action, created_at)'],
        ['email_queue',   'idx_status_created', '(status, created_at)'],
        ['news_posts',    'idx_type_created',   '(type, created_at)'],
    ] as [$tabel, $nama_idx, $kolom]) {
        try {
            $ada = $pdo->query("SHOW INDEX FROM `$tabel` WHERE Key_name = '$nama_idx'")->fetch();
            if (!$ada) {
                $pdo->exec("ALTER TABLE `$tabel` ADD INDEX `$nama_idx` $kolom");
            }
        } catch (PDOException $e) {
            error_log("Gagal membuat indeks $nama_idx pada $tabel: " . $e->getMessage());
        }
    }

    // ── Bersihkan NIM pada akun yang tidak seharusnya punya NIM ───────
    //
    // NIM adalah identitas LULUSAN. Lima baris berikut adalah akun staf,
    // akun uji, atau akun IT fakultas yang memakai NIM milik orang lain.
    //
    // Yang paling penting dipahami: alumni_6a0d761f16121 ("Support IT FK UMS",
    // <email-pengelola>, MARS 2027) dan l200160042 ("Pandu Egi Ferdian",
    // <email-alumnus>, J500 2021) adalah DUA ORANG BERBEDA yang
    // kebetulan berbagi satu NIM — bukan akun ganda. Karena itu ini BUKAN
    // penggabungan akun: tidak ada riwayat yang dipindahkan, tidak ada baris
    // users yang dihapus, dan tidak ada users.id yang diubah. Hanya satu
    // kolom yang dikosongkan pada akun yang salah memakainya.
    //
    // Diarahkan per-id, bukan lewat "WHERE nim NOT REGEXP ...", supaya
    // migrasi ini tidak pernah menyentuh baris di luar kelima ini meskipun
    // data di server sedikit berbeda dari data lokal.
    //
    // NULL, bukan '': MariaDB mengizinkan banyak NULL pada kolom UNIQUE,
    // tetapi string kosong dianggap nilai yang sama dan akan menggagalkan
    // constraint di bawah.
    $nim_dibersihkan = [
        'alumni_6a0d761f16121'            => 'l200160042',   // akun IT, NIM milik orang lain
        'alumni_6a0c8751174e4'            => '1',            // super_admin
        'usr_afc1fe83b50fed40_1779595514' => 'Admin Tracer', // super_admin
        'usr_0b5f21e34f9e4d4e_1779680697' => 'dumy',         // akun uji Midtrans
        'usr_5d826a5be1e3dad0_1779085856' => 'a',            // akun uji
    ];
    $bersihkan = $pdo->prepare("UPDATE users SET nim = NULL WHERE id = ? AND nim = ?");
    foreach ($nim_dibersihkan as $id_akun => $nim_lama) {
        // Syarat nim = ? membuatnya tidak berbuat apa-apa bila NIM di server
        // ternyata sudah berbeda — lebih baik melewatkan daripada menimpa
        // data yang tidak dikenali.
        $bersihkan->execute([$id_akun, $nim_lama]);
    }

    // ── Jadikan NIM unik ─────────────────────────────────────────────
    //
    // Ini satu-satunya pernyataan di seluruh blok migrasi yang, bila gagal,
    // berpotensi mematikan situs. Karena itu berpagar tiga lapis:
    //   1. hanya dijalankan bila pra-pemeriksaan menemukan NOL duplikat dan
    //      NOL NIM tak sah — bila masih ada, dilewati dan dicatat;
    //   2. try/catch sendiri, tidak menyentuh catch terluar;
    //   3. dilewati bila indeksnya memang sudah unik.
    try {
        $idx_nim = $pdo->query("SHOW INDEX FROM users WHERE Key_name = 'nim'")->fetch();
        $sudah_unik = $idx_nim && (int)$idx_nim->Non_unique === 0;

        if (!$sudah_unik) {
            $ganda = (int)$pdo->query(
                "SELECT COUNT(*) FROM (
                    SELECT nim FROM users
                    WHERE nim IS NOT NULL AND nim <> ''
                    GROUP BY nim HAVING COUNT(*) > 1
                 ) x")->fetchColumn();
            $ngawur = (int)$pdo->query(
                "SELECT COUNT(*) FROM users
                 WHERE nim IS NOT NULL AND nim <> ''
                   AND nim NOT REGEXP '^[A-Za-z0-9]{6,20}$'")->fetchColumn();
            $kosong = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE nim = ''")->fetchColumn();

            if ($ganda === 0 && $ngawur === 0 && $kosong === 0) {
                // Indeks lama bernama 'nim' dan TIDAK unik, jadi harus
                // dibuang dulu sebelum yang unik dipasang.
                if ($idx_nim) {
                    $pdo->exec("ALTER TABLE users DROP INDEX nim, ADD UNIQUE KEY nim (nim)");
                } else {
                    $pdo->exec("ALTER TABLE users ADD UNIQUE KEY nim (nim)");
                }
            } else {
                error_log("UNIQUE pada users.nim dilewati: $ganda ganda, "
                        . "$ngawur tidak sah, $kosong kosong. "
                        . "Bersihkan lewat panel Kesehatan Data NIM lalu naikkan versi skema.");
            }
        }
    } catch (PDOException $e) {
        error_log('Gagal memasang UNIQUE pada users.nim: ' . $e->getMessage());
    }

    // ── Cadangan basis data ──────────────────────────────────────────
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
        ('backup_keep', '7'),
        ('rate_limit_backup_max', '5'),
        ('rate_limit_backup_window', '30'),
        ('email_stuck_minutes', '15')");

    // ── Aktifkan penegakan RBAC ──────────────────────────────────────
    //
    // rbac_enforce disemai '0' (MODE AUDIT) supaya pemasangan pertama di
    // server yang sedang berjalan tidak mengunci siapa pun mendadak.
    //
    // Sekarang saat yang paling aman untuk menegakkannya: belum ada satu pun
    // akun staf di basis data (hanya alumni dan super_admin), dan Audit Trail
    // tidak mencatat satu pun pelanggaran RBAC. Artinya tidak ada seorang pun
    // yang bisa terkunci oleh perubahan ini — sementara bila ditunda sampai
    // akun staf dibuat, menyalakannya baru berisiko.
    //
    // Perilaku sudah diuji per peran: admin_tracer 6 boleh/12 ditolak,
    // admin_legalisir 8/10, keuangan 7/11, alumni ditolak di semua endpoint,
    // super_admin tidak pernah ditolak.
    //
    // Dijalankan sekali pada versi skema ini saja. Superadmin tetap dapat
    // mematikannya kembali lewat Pengaturan Sistem bila perlu.
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('rbac_enforce', '1')
                ON DUPLICATE KEY UPDATE setting_value = '1'");

    // ── Token pemicu cron ────────────────────────────────────────────
    //
    // Dibuat acak sekali saat migrasi pertama, disimpan di basis data
    // (bukan .env) karena deploy lewat FTP tidak memberi cara andal untuk
    // menyunting .env di server. Superadmin membacanya di Pengaturan Sistem
    // untuk menyusun URL cron di cPanel.
    require_once dirname(__DIR__) . '/includes/cron_auth.php';
    cron_token($pdo);

    // ── Pengaturan impor massal alumni ───────────────────────────────
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
        ('import_max_rows', '2000'),
        ('import_max_bytes', '2097152'),
        ('rate_limit_import_alumni_max', '5'),
        ('rate_limit_import_alumni_window', '15')");

    // ── Persetujuan tampil di Peta Persebaran ─────────────────────────
    //
    // cron/geocoder.php sebelumnya mendaftarkan SETIAP alumnus beralamat ke
    // cache geocoding tanpa langkah persetujuan apa pun, dan endpoint peta
    // menyerahkan alamat rumah lengkap kepada siapa pun yang login.
    //
    // Bawaannya 0 (tetap tampil) agar perilaku tidak berubah mendadak bagi
    // yang sudah ada; alumni dapat menonaktifkan sendiri lewat halaman Profil.
    $kolom_optout = $pdo->query("SHOW COLUMNS FROM users LIKE 'map_opt_out'")->fetch();
    if (!$kolom_optout) {
        $pdo->exec("ALTER TABLE users ADD COLUMN map_opt_out TINYINT(1) NOT NULL DEFAULT 0 AFTER address");
    }

    // Sakelar penegakan RBAC. Sengaja disemai '0' (MODE AUDIT) supaya
    // pemasangan di server yang sedang berjalan tidak mengunci staf mana pun.
    // Selama bernilai '0', pelanggaran peran hanya dicatat ke Audit Trail
    // sebagai 'RBAC_AUDIT'. Ubah ke '1' lewat Pengaturan Sistem setelah
    // isi Audit Trail ditinjau.
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('rbac_enforce', '0')");

    // Penanda notifikasi Midtrans yang sudah diproses (idempotensi webhook).
    //
    // Kunci unik memakai PASANGAN order_id + transaction_status, bukan
    // order_id saja, supaya transisi wajar 'pending' -> 'settlement' tetap
    // diproses, sementara pengiriman ulang status yang SAMA diabaikan.
    // Midtrans mengirim ulang notifikasi bila tidak menerima balasan 200.
    $pdo->exec("CREATE TABLE IF NOT EXISTS `midtrans_notifications` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `order_id` varchar(120) NOT NULL,
        `transaction_status` varchar(50) NOT NULL,
        `transaction_id` varchar(120) DEFAULT NULL,
        `gross_amount` decimal(15,2) DEFAULT NULL,
        `processed_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_order_status` (`order_id`, `transaction_status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Auto-initialize password_resets table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `password_resets` (
        `email` varchar(255) NOT NULL,
        `token` varchar(255) NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Auto-migrate existing users.major strings to major_codes dynamically
    $all_m = $pdo->query("SELECT major_code, major_name FROM majors")->fetchAll();
    $m_codes = array_column($all_m, 'major_code');
    $stmt_up = $pdo->prepare("UPDATE users SET major = ? WHERE major = ?");
    foreach ($all_m as $m) {
        $stmt_up->execute([$m->major_code, $m->major_name]);
    }
    
    // Handle fallback for unrecognized majors to 'J500'
    $stmt_unrecognized = $pdo->query("SELECT id, major FROM users WHERE major IS NOT NULL AND major != ''");
    $stmt_up_unrecognized = $pdo->prepare("UPDATE users SET major = ? WHERE id = ?");
    foreach ($stmt_unrecognized->fetchAll() as $usr) {
        if (!in_array($usr->major, $m_codes)) {
            $matched = false;
            foreach ($all_m as $m) {
                if (strcasecmp(trim($usr->major), trim($m->major_name)) === 0) {
                    $stmt_up_unrecognized->execute([$m->major_code, $usr->id]);
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                // Unrecognized string (like 'Informatika'), map to 'J500' default
                $stmt_up_unrecognized->execute(['J500', $usr->id]);
            }
        }
    }

    } // selesai: if (!alumnilink_schema_is_current($pdo))
} catch (PDOException $e) {
    error_log("Database Connection Error: " . $e->getMessage());
    die("Koneksi database gagal. Silakan hubungi administrator sistem.");
}

// Global Logger
require_once dirname(__DIR__) . '/includes/logger.php';

// Akses pengaturan terpusat. Dimuat di sini agar fungsi setting() tersedia
// di seluruh halaman, handler, dan cron tanpa perlu di-require satu per satu.
require_once dirname(__DIR__) . '/includes/settings.php';

// Logika bersama Tracer Study (normalisasi jawaban, penyaringan kohort,
// deduplikasi responden, agregasi pertanyaan). Dimuat global dengan alasan
// yang sama: sisi tulis (handler) dan sisi baca (analitik, laporan, ekspor)
// WAJIB memakai normalisasi yang sama persis.
require_once dirname(__DIR__) . '/includes/tracer_lib.php';

// Paginasi bersama. Dimuat global karena tujuh halaman memakainya; bila
// di-require satu per satu, halaman yang lupa akan gagal saat dijalankan
// padahal `php -l` tetap bersih.
require_once dirname(__DIR__) . '/includes/pagination.php';
?>
