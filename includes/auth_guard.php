<?php
/**
 * includes/auth_guard.php
 * ─────────────────────────────────────────────────────────
 * Penjagaan otorisasi terpusat untuk AlumniLink.
 *
 * LATAR BELAKANG
 * Pola penjagaan lama yang tersebar di banyak handler berbentuk BLACKLIST:
 *
 *     if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'alumni') { ... }
 *
 * Pola itu berarti "tolak alumni, izinkan sisanya". Padahal kolom
 * users.role memiliki lima nilai:
 *     alumni, admin_tracer, admin_legalisir, keuangan, super_admin
 *
 * Akibatnya admin_tracer, admin_legalisir, dan keuangan otomatis lolos ke
 * SEMUA handler admin -- termasuk yang seharusnya khusus super_admin.
 * Pemisahan peran sudah ada di skema database tetapi tidak ditegakkan.
 *
 * Berkas ini menyediakan pola WHITELIST: sebutkan peran yang boleh, sisanya
 * ditolak. Gunakan untuk setiap handler baru.
 *
 * CONTOH PEMAKAIAN
 *     require_once '../includes/auth_guard.php';
 *     require_role(['super_admin']);                   // balasan HTML
 *     require_role(['super_admin', 'keuangan'], true); // balasan JSON
 */

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../includes/session_boot.php';
}

/** Seluruh peran yang dianggap staf (bukan alumni). */
function all_admin_roles()
{
    return ['admin_tracer', 'admin_legalisir', 'keuangan', 'super_admin'];
}

/** Peran pengguna yang sedang aktif, atau null bila belum masuk. */
function current_role()
{
    return $_SESSION['user_role'] ?? null;
}

function is_logged_in()
{
    return isset($_SESSION['user_id']);
}

/** Benar bila pengguna adalah staf mana pun (setara pengecekan blacklist lama). */
function is_admin()
{
    return is_logged_in() && current_role() !== 'alumni';
}

/**
 * Hentikan permintaan dengan kode status dan pesan tertentu.
 *
 * @param int    $code    Kode status HTTP.
 * @param string $message Pesan yang ditampilkan kepada pengguna.
 * @param bool   $as_json Kirim balasan JSON, bukan teks biasa.
 */
function deny_access($code, $message, $as_json = false)
{
    http_response_code($code);

    if ($as_json) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'status' => 'error', 'message' => $message]);
        exit;
    }

    // Tampilkan halaman galat berdesain, bukan teks telanjang di layar putih.
    // Berkas ini dimuat sesuai kebutuhan agar auth_guard tetap dapat dipakai
    // pada konteks yang tidak memerlukan tampilan.
    $errorPage = __DIR__ . '/error_page.php';
    if (is_file($errorPage)) {
        require_once $errorPage;
        if (function_exists('render_error_page')) {
            $title = ($code === 401) ? 'Sesi Berakhir' : 'Akses Ditolak';
            $icon  = ($code === 401) ? 'log-in' : 'shield-alert';
            render_error_page($title, $message, $code, $icon);
        }
    }

    // Cadangan bila halaman galat tidak tersedia.
    echo htmlspecialchars($message);
    exit;
}

/**
 * Wajibkan pengguna sudah masuk DAN memiliki salah satu peran yang diizinkan.
 *
 * @param array $allowed Daftar peran yang diizinkan, mis. ['super_admin'].
 * @param bool  $as_json Kirim balasan JSON, bukan teks biasa.
 */
function require_role(array $allowed, $as_json = false)
{
    if (!is_logged_in()) {
        deny_access(401, 'Sesi tidak ditemukan. Silakan masuk terlebih dahulu.', $as_json);
    }

    if (!in_array(current_role(), $allowed, true)) {
        // Dicatat agar percobaan akses lintas peran terlihat di Audit Trail.
        if (function_exists('log_activity')) {
            log_activity(
                'UNAUTHORIZED_ACCESS',
                'Peran "' . (current_role() ?? 'tidak diketahui') . '" mencoba mengakses ' .
                basename($_SERVER['SCRIPT_NAME'] ?? 'endpoint tidak diketahui')
            );
        }
        deny_access(403, 'Akses ditolak. Peran Anda tidak memiliki izin untuk tindakan ini.', $as_json);
    }
}

/** Wajibkan pengguna sudah masuk, tanpa mempedulikan peran. */
function require_login($as_json = false)
{
    if (!is_logged_in()) {
        deny_access(401, 'Sesi tidak ditemukan. Silakan masuk terlebih dahulu.', $as_json);
    }
}

// ─────────────────────────────────────────────────────────
// MATRIKS KAPABILITAS
// ─────────────────────────────────────────────────────────

/**
 * Kapabilitas -> halaman yang mewakilinya di sidebar.
 *
 * Dipakai untuk MENURUNKAN daftar peran secara otomatis dari izin sidebar
 * yang sedang berlaku, bukan menuliskannya dua kali.
 */
function capability_source_pages()
{
    return [
        // Dijaga 1:1 dengan halaman bila memungkinkan. Satu kapabilitas yang
        // dipakai dua halaman membuat izin salah satu halaman ikut membuka
        // halaman lain -- izin bocor tanpa menu yang mendampinginya.
        'legalisir.kelola'      => ['admin_legalisir'],
        'pembayaran.verifikasi' => ['admin_legalisir'],
        'tracer.lihat'          => ['admin_tracer', 'admin_tracer_report'],
        'analitik.lihat'        => ['admin_analytics'],
        'tracer.kelola'         => ['admin_tracer_config', 'admin_employer_survey'],
        'repositori.kelola'     => ['admin_repository'],
        'alumni.kelola'         => ['admin_alumni'],
        'master.kelola'         => ['admin_majors'],
        'konten.kelola'         => ['admin_news'],
        'keuangan.lihat'        => ['admin_keuangan'],
        'keuangan.kelola'       => ['admin_donasi'],
        'broadcast.kirim'       => ['admin_broadcast', 'admin_email_layouts'],
        'pengguna.kelola'       => ['admin_users'],
        'pengaturan.kelola'     => ['admin_settings'],
        'laporan.ekspor'        => ['admin_alumni', 'admin_tracer', 'admin_keuangan'],
    ];
}

/**
 * Kapabilitas yang SELALU khusus super_admin, apa pun isi sidebar.
 *
 * Aksi merusak atau menyentuh kredensial tidak boleh ikut terbuka hanya
 * karena seorang superadmin menambahkan menunya untuk peran lain.
 */
function capability_super_admin_only()
{
    return ['legalisir.hapus', 'pengguna.kelola', 'pengaturan.kelola', 'broadcast.kirim'];
}

/**
 * Izin sidebar yang SEDANG BERLAKU: hasil pengaturan di basis data bila ada,
 * selain itu memakai nilai bawaan yang sama dengan includes/header.php.
 */
function sidebar_permissions_effective()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    // Bawaan diambil dari sumber tunggal includes/menu.php; sebelumnya
    // disalin manual dari header.php dan sudah mulai menyimpang.
    require_once __DIR__ . '/menu.php';
    $defaults = default_sidebar_permissions();

    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'sidebar_permissions'");
            $raw = $stmt->fetchColumn();
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $defaults = array_merge($defaults, $decoded);
                }
            }
        } catch (PDOException $e) {
            // Gagal membaca -> pakai bawaan.
        }
    }

    $cache = $defaults;
    return $cache;
}

/**
 * Kapabilitas -> daftar peran yang diizinkan.
 *
 * DITURUNKAN dari izin sidebar yang sedang berlaku, bukan ditulis manual.
 *
 * Alasannya: bila daftar ini ditulis terpisah, ia akan menyimpang dari menu
 * yang benar-benar tampil -- apalagi karena izin sidebar dapat diubah lewat
 * Pengaturan Sistem. Penyimpangan itu menghasilkan dua kerusakan pengalaman:
 *   - menu terlihat tetapi aksinya ditolak  -> pengguna menemui jalan buntu;
 *   - menu tersembunyi tetapi aksinya boleh -> izin bocor diam-diam.
 *
 * Dengan diturunkan otomatis, ketiga lapisan (menu, halaman, handler) selalu
 * bercerita hal yang sama.
 */
function capability_matrix()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $perms      = sidebar_permissions_effective();
    $sources    = capability_source_pages();
    $superOnly  = capability_super_admin_only();
    $staffRoles = ['admin_tracer', 'admin_legalisir', 'keuangan'];

    $matrix = [];
    foreach ($sources as $cap => $pages) {
        if (in_array($cap, $superOnly, true)) {
            $matrix[$cap] = ['super_admin'];
            continue;
        }

        $roles = [];
        foreach ($staffRoles as $role) {
            foreach ($pages as $page) {
                if (in_array($page, $perms[$role] ?? [], true)) {
                    $roles[] = $role;
                    break;
                }
            }
        }
        $roles[] = 'super_admin';
        $matrix[$cap] = array_values(array_unique($roles));
    }

    // Kapabilitas yang tidak punya halaman perwakilan.
    foreach ($superOnly as $cap) {
        $matrix[$cap] = ['super_admin'];
    }

    $cache = $matrix;
    return $cache;
}

/**
 * Peta HALAMAN admin -> kapabilitas yang dibutuhkan.
 *
 * Dipakai index.php agar penjagaan di tingkat halaman SAMA dengan penjagaan
 * di tingkat handler. Tanpa ini, seluruh peran staf dapat membuka setiap
 * halaman admin lewat URL langsung meski menunya disembunyikan sidebar --
 * halaman terbuka penuh, lalu aksinya ditolak saat disimpan.
 *
 * Halaman yang tidak terdaftar di sini tetap terbuka bagi seluruh peran staf
 * (mis. admin_dashboard dan admin_logs yang bersifat baca-saja).
 */
function page_capability_map()
{
    return [
        'admin_alumni'        => 'alumni.kelola',
        // Memakai ULANG alumni.kelola, dan sengaja TIDAK ditambahkan ke
        // capability_source_pages(): bila halaman impor ikut menjadi sumber
        // kapabilitas itu, peran yang hanya diberi menu impor akan otomatis
        // boleh membuka Database Alumni juga.
        'admin_alumni_import' => 'alumni.kelola',
        'admin_majors'        => 'master.kelola',
        'admin_news'          => 'konten.kelola',
        'admin_broadcast'     => 'broadcast.kirim',
        // Halaman kemajuan menampilkan alamat e-mail penerima, jadi
        // dijaga kapabilitas yang sama dengan yang boleh mengirim.
        'admin_broadcast_status' => 'broadcast.kirim',
        'admin_email_layouts' => 'broadcast.kirim',
        'admin_analytics'     => 'analitik.lihat',
        'admin_tracer'        => 'tracer.lihat',
        'admin_tracer_report' => 'tracer.lihat',
        'admin_tracer_config' => 'tracer.kelola',
        'admin_employer_survey' => 'tracer.kelola',
        'admin_legalisir'     => 'legalisir.kelola',
        'admin_repository'    => 'repositori.kelola',
        'admin_donasi'        => 'keuangan.kelola',
        'admin_keuangan'      => 'keuangan.lihat',
        'admin_users'         => 'pengguna.kelola',
    ];
}

/**
 * Benar bila peran boleh MEMBUKA halaman tertentu.
 * Mengembalikan true untuk halaman yang tidak diatur.
 */
function role_can_open_page($page, $role)
{
    $map = page_capability_map();
    if (!isset($map[$page])) {
        return true;
    }
    $allowed = capability_matrix()[$map[$page]] ?? ['super_admin'];
    return in_array($role, $allowed, true);
}

/**
 * Benar bila penegakan RBAC sudah diaktifkan.
 *
 * Dikendalikan lewat settings.rbac_enforce:
 *   '0' (bawaan) = MODE AUDIT — pelanggaran dicatat ke Audit Trail tetapi
 *                  tetap diizinkan. Dipakai saat pertama kali dipasang di
 *                  server yang sedang berjalan, supaya tidak ada staf yang
 *                  mendadak terkunci.
 *   '1'          = PENEGAKAN — pelanggaran ditolak.
 *
 * Setelah beberapa hari berjalan, periksa Audit Trail untuk aksi
 * 'RBAC_AUDIT'. Bila tidak ada yang mengejutkan, ubah nilainya menjadi '1'
 * lewat Pengaturan Sistem. Perubahan berlaku seketika tanpa upload ulang.
 */
function rbac_is_enforced()
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    global $pdo;
    $cached = false;

    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'rbac_enforce'");
            $cached = ((string)$stmt->fetchColumn() === '1');
        } catch (PDOException $e) {
            $cached = false; // gagal baca -> tetap mode audit, jangan mengunci
        }
    }

    return $cached;
}

/** Deteksi apakah pemanggil mengharapkan balasan JSON. */
function wants_json_response()
{
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        return true;
    }
    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        return true;
    }
    if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        return true;
    }
    foreach (headers_list() as $h) {
        if (stripos($h, 'content-type: application/json') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Wajibkan pengguna memiliki kapabilitas tertentu.
 *
 * Alumni dan pengunjung anonim SELALU ditolak, bahkan dalam mode audit.
 * Yang bersifat audit hanyalah pemisahan antar peran staf.
 *
 * @param string    $capability Kunci pada capability_matrix().
 * @param bool|null $as_json    Paksa bentuk balasan; null = deteksi otomatis.
 */
function require_capability($capability, $as_json = null)
{
    if ($as_json === null) {
        $as_json = wants_json_response();
    }

    if (!is_logged_in()) {
        deny_access(401, 'Sesi tidak ditemukan. Silakan masuk terlebih dahulu.', $as_json);
    }

    $role = current_role();

    // Alumni tidak pernah boleh menyentuh endpoint staf — ini batas keras.
    if ($role === 'alumni' || $role === null) {
        deny_access(403, 'Akses ditolak.', $as_json);
    }

    $matrix  = capability_matrix();
    $allowed = $matrix[$capability] ?? ['super_admin'];

    if (in_array($role, $allowed, true)) {
        return;
    }

    $endpoint = basename($_SERVER['SCRIPT_NAME'] ?? 'endpoint');

    if (!rbac_is_enforced()) {
        // MODE AUDIT: catat, lalu izinkan.
        if (function_exists('log_activity')) {
            log_activity(
                'RBAC_AUDIT',
                sprintf(
                    'Peran "%s" mengakses %s yang membutuhkan kapabilitas "%s" (diizinkan: %s). ' .
                    'Mode audit — akses TETAP diizinkan.',
                    $role,
                    $endpoint,
                    $capability,
                    implode(', ', $allowed)
                )
            );
        }
        return;
    }

    // MODE PENEGAKAN: tolak.
    if (function_exists('log_activity')) {
        log_activity(
            'RBAC_DENIED',
            sprintf('Peran "%s" ditolak pada %s (butuh "%s").', $role, $endpoint, $capability)
        );
    }
    deny_access(403, 'Akses ditolak. Peran Anda tidak memiliki izin untuk tindakan ini.', $as_json);
}
