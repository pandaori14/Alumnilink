<?php
/**
 * Definisi menu — SATU-SATUNYA sumber kebenaran.
 *
 * ── Mengapa berkas ini ada ─────────────────────────────────────────────
 * Daftar menu sebelumnya disalin di TIGA tempat yang saling tidak tahu:
 *
 *   includes/header.php            $all_menus        (yang benar-benar dirender)
 *   pages/admin_settings.php       $all_menus_admin  (yang dicentang superadmin)
 *   handlers/admin_settings_handler.php  $valid_menu_keys  (yang boleh disimpan)
 *
 * Ketiganya sudah menyimpang. Akibatnya nyata dan sedang berjalan hari ini:
 * halaman Pengaturan tidak pernah menampilkan kotak centang untuk
 * 'guide', 'alumni_map', 'admin_majors', 'admin_analytics',
 * 'admin_tracer_report', 'admin_employer_survey', dan 'admin_email_layouts',
 * lalu penyaring di handler membuang ketujuhnya dari data yang disimpan.
 *
 * Artinya: setiap kali superadmin membuka Pengaturan Sistem dan menekan
 * Simpan — meski tanpa mengubah apa pun — ketujuh menu itu TERHAPUS dari
 * settings.sidebar_permissions untuk semua peran. Menu "Laporan Akreditasi"
 * yang ditambahkan lewat migrasi pun ikut hilang pada penyimpanan berikutnya.
 *
 * Karena izin sidebar juga menjadi dasar capability_matrix() di auth_guard.php,
 * penyimpangan ini tidak berhenti pada tampilan menu: ia mencabut hak akses.
 *
 * Menambah halaman baru cukup di SINI.
 */

/**
 * Seluruh menu, dikelompokkan 'main' (semua peran) dan 'admin' (staf).
 * Setiap entri: key, label, icon; entri admin juga punya group.
 */
function all_menu_definitions()
{
    return [
        'main' => [
            ['key' => 'dashboard',  'label' => 'Beranda',           'icon' => 'layout-grid'],
            ['key' => 'tracer',     'label' => 'Tracer Alumni',      'icon' => 'file-search'],
            ['key' => 'legalisir',  'label' => 'Layanan Legalisir',  'icon' => 'award'],
            ['key' => 'donasi',     'label' => 'Donasi Alumni',      'icon' => 'heart'],
            ['key' => 'events',     'label' => 'Event & Kegiatan',   'icon' => 'calendar-check'],
            ['key' => 'alumni_map', 'label' => 'Peta Persebaran',    'icon' => 'map'],
            ['key' => 'guide',      'label' => 'Panduan Pengguna',   'icon' => 'book-open'],
        ],
        'admin' => [
            // Group 1: Informasi & Alumni (tiga teratas jadi pintasan cepat di mobile)
            ['key' => 'admin_alumni',         'label' => 'Database Alumni',        'icon' => 'users-2',         'group' => 'Informasi & Alumni'],
            ['key' => 'admin_alumni_import',  'label' => 'Impor Massal Alumni',    'icon' => 'upload-cloud',    'group' => 'Informasi & Alumni'],
            ['key' => 'admin_news',           'label' => 'Kelola Berita & Event',  'icon' => 'newspaper',       'group' => 'Informasi & Alumni'],
            ['key' => 'admin_broadcast',      'label' => 'Broadcast',              'icon' => 'megaphone',       'group' => 'Informasi & Alumni'],
            ['key' => 'admin_broadcast_status','label' => 'Kemajuan Broadcast',   'icon' => 'activity',        'group' => 'Informasi & Alumni'],
            ['key' => 'admin_email_layouts',  'label' => 'Template Email',         'icon' => 'layout-template', 'group' => 'Informasi & Alumni'],

            // Group 2: Tracer Study
            ['key' => 'admin_analytics',      'label' => 'Analitik Alumni',        'icon' => 'line-chart',      'group' => 'Tracer Study'],
            ['key' => 'admin_tracer',         'label' => 'Laporan Tracer',         'icon' => 'bar-chart-3',     'group' => 'Tracer Study'],
            ['key' => 'admin_tracer_report',  'label' => 'Laporan Akreditasi',     'icon' => 'file-badge',      'group' => 'Tracer Study'],
            ['key' => 'admin_tracer_config',  'label' => 'Konfigurasi Tracer',     'icon' => 'list-checks',     'group' => 'Tracer Study'],
            ['key' => 'admin_employer_survey','label' => 'Survei Pengguna Lulusan','icon' => 'clipboard-check', 'group' => 'Tracer Study'],

            // Group 3: Layanan Akademik
            ['key' => 'admin_legalisir',      'label' => 'Kelola Legalisir',       'icon' => 'award',           'group' => 'Layanan Akademik'],
            ['key' => 'admin_repository',     'label' => 'Repositori Dokumen',     'icon' => 'database',        'group' => 'Layanan Akademik'],

            // Group 4: Donasi & Keuangan
            ['key' => 'admin_donasi',         'label' => 'Kelola Donasi',          'icon' => 'heart',           'group' => 'Donasi & Keuangan'],
            ['key' => 'admin_keuangan',       'label' => 'Laporan Keuangan',       'icon' => 'receipt',         'group' => 'Donasi & Keuangan'],

            // Group 5: Pengaturan Sistem
            ['key' => 'admin_users',          'label' => 'Kelola User',            'icon' => 'user-cog',        'group' => 'Pengaturan Sistem'],
            ['key' => 'admin_majors',         'label' => 'Kelola Prodi',           'icon' => 'book-copy',       'group' => 'Pengaturan Sistem'],
            ['key' => 'admin_settings',       'label' => 'Konfigurasi Sistem',     'icon' => 'settings',        'group' => 'Pengaturan Sistem'],
            ['key' => 'admin_logs',           'label' => 'Audit Trail',            'icon' => 'shield-check',    'group' => 'Pengaturan Sistem'],
        ],
    ];
}

/**
 * Daftar datar seluruh kunci menu.
 * Dipakai handler Pengaturan sebagai daftar-putih penyimpanan, sehingga
 * daftar-putih itu tidak akan pernah lagi tertinggal dari menu yang ada.
 */
function all_menu_keys()
{
    $keys = [];
    foreach (all_menu_definitions() as $grup) {
        foreach ($grup as $m) {
            $keys[] = $m['key'];
        }
    }
    return $keys;
}

/** Izin sidebar bawaan bila belum pernah disimpan. */
function default_sidebar_permissions()
{
    return [
        'alumni'          => ['dashboard','tracer','legalisir','donasi','events','alumni_map','guide'],
        'admin_tracer'    => ['dashboard','tracer','legalisir','donasi','events','alumni_map','admin_tracer','admin_tracer_report','admin_analytics','admin_tracer_config','admin_employer_survey','admin_alumni','admin_alumni_import','admin_repository','admin_majors','guide'],
        'admin_legalisir' => ['dashboard','tracer','legalisir','donasi','events','alumni_map','admin_legalisir','admin_alumni','admin_alumni_import','admin_repository','admin_majors','guide'],
        'keuangan'        => ['dashboard','tracer','legalisir','donasi','events','admin_keuangan','guide'],
    ];
}
