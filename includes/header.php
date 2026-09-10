<?php
require_once 'csrf.php';
// Dynamic Title Mapping
$page_titles = [
    'dashboard'          => 'Beranda',
    'tracer'             => 'Tracer Alumni',
    'legalisir'          => 'Layanan Legalisir',
    'donasi'             => 'Donasi Alumni',
    'donasi_detail'      => 'Program Donasi',
    'profile'            => 'Profil Saya',
    'notifications'      => 'Pusat Notifikasi',
    'events'             => 'Event & Kegiatan',
    'admin_dashboard'    => 'Dashboard Admin',
    'admin_donasi'       => 'Kelola Donasi',
    'admin_legalisir'    => 'Kelola Legalisir',
    'admin_alumni'       => 'Database Alumni',
    'admin_keuangan'     => 'Laporan Keuangan',
    'admin_tracer'       => 'Data Tracer',
    'admin_tracer_report'=> 'Laporan Akreditasi',
    'admin_employer_survey'=> 'Survei Pengguna Lulusan',
    'admin_users'        => 'Kelola User',
    'admin_broadcast'    => 'Broadcast Pesan',
    'admin_tracer_config'=> 'Konfigurasi Tracer Alumni',
    'admin_settings'     => 'Konfigurasi Sistem',
    'admin_news'         => 'Kelola Berita & Event',
    'admin_logs'         => 'Audit Trail',
    'admin_majors'       => 'Kelola Program Studi',
    'admin_analytics'    => 'Analitik Tracer Alumni',
    'admin_email_layouts'=> 'Template Email (Layouts)',
    'alumni_map'         => 'Peta Persebaran Alumni',
    'guide'              => 'Panduan Pengguna',
];

$page_descriptions = [
    'dashboard'   => 'Selamat datang di AlumniLink. Portal pusat informasi dan layanan untuk alumni terintegrasi.',
    'tracer'      => 'Bantu kami meningkatkan kualitas pendidikan dengan mengisi Tracer Alumni. Data Anda sangat berharga bagi institusi.',
    'legalisir'   => 'Layanan legalisir ijazah dan transkrip nilai online. Praktis, cepat, dan dapat dipantau secara real-time.',
    'donasi'      => 'Wujudkan kepedulian Anda dengan berdonasi melalui program resmi AlumniLink untuk pengembangan kampus.',
    'profile'     => 'Kelola data diri, kontak, dan riwayat akademik Anda di portal AlumniLink.',
    'notifications'=> 'Pusat notifikasi dan informasi terbaru mengenai status layanan dan berita alumni.',
    'alumni_map'  => 'Eksplorasi peta persebaran letak geografis para alumni berdasarkan domisili dan lokasi kerja.',
    'guide'       => 'Pusat bantuan dan panduan penggunaan sistem AlumniLink secara menyeluruh.',
];

$current_title = $page_titles[$page] ?? 'AlumniLink';
$current_desc  = $page_descriptions[$page] ?? 'Platform manajemen alumni terintegrasi untuk Tracer Alumni, Legalisir, dan Donasi.';

// ── Master Menu & Izin Bawaan ─────────────────────────────────────────────
// Definisinya dipindah ke includes/menu.php supaya halaman Pengaturan dan
// handler penyimpanannya membaca daftar yang SAMA. Nama variabelnya
// dipertahankan karena includes/footer.php ikut membacanya.
require_once __DIR__ . '/menu.php';
$all_menus           = all_menu_definitions();
$default_permissions = default_sidebar_permissions();

// ── Load Sidebar Permissions from DB ──────────────────────────────────────
$sidebar_permissions = $default_permissions;
if (!empty($sys_settings['sidebar_permissions'])) {
    $db_perms = json_decode($sys_settings['sidebar_permissions'], true);
    if (is_array($db_perms)) {
        $sidebar_permissions = array_merge($default_permissions, $db_perms);
    }
}

// ── Helper: can this role see this menu? ──────────────────────────────────
require_once __DIR__ . '/auth_guard.php';

$current_role = $_SESSION['user_role'] ?? 'alumni';
function can_see_menu(string $menu_key, string $role, array $perms): bool {
    if ($role === 'super_admin') return true; // super_admin selalu tampil semua
    if ($menu_key === 'guide') return true; // guide selalu tampil untuk semua

    if (!in_array($menu_key, $perms[$role] ?? [])) {
        return false;
    }

    // Sembunyikan menu admin yang perannya memang tidak berwenang membukanya.
    //
    // Beberapa kapabilitas dikunci khusus super_admin apa pun isi pengaturan
    // sidebar -- terutama "Kelola User", karena halaman itu dapat mengubah
    // peran sehingga siapa pun yang mengaksesnya bisa mengangkat dirinya
    // sendiri menjadi super_admin.
    //
    // Tanpa penyaringan ini, menu tetap tampil lalu ditolak saat dibuka:
    // pengguna menemui jalan buntu.
    if (strpos($menu_key, 'admin_') === 0 && function_exists('role_can_open_page')) {
        return role_can_open_page($menu_key, $role);
    }

    return true;
}

// ── Fetch User Data for Profile & Notifications ───────────────────────────
$user_header = null;
if (isset($_SESSION['user_id'])) {
    $stmt_user = $pdo->prepare("SELECT avatar, name FROM users WHERE id = ?");
    $stmt_user->execute([$_SESSION['user_id']]);
    $user_header = $stmt_user->fetch();

    $stmt_notif = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt_notif->execute([$_SESSION['user_id']]);
    $unread_count = $stmt_notif->fetchColumn();
}

$dashboard_bg_animation = ($sys_settings['dashboard_bg_animation'] ?? '1') == '1';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo e($current_title); ?> - AlumniLink</title>
    <meta name="description" content="<?php echo e($current_desc); ?>">
    
    <!-- OpenGraph Tags -->
    <meta property="og:title" content="<?php echo e($current_title); ?> - AlumniLink">
    <meta property="og:description" content="<?php echo e($current_desc); ?>">
    <meta property="og:type" content="website">
    <meta property="og:image" content="<?php echo e(!empty($sys_settings['system_logo']) ? $sys_settings['system_logo'] : 'uploads/system/logo_1778236863.png'); ?>">

    <?php
    // Favicon kini dapat diatur terpisah dari logo sistem, karena keduanya
    // punya kebutuhan bentuk yang berbeda (favicon perlu ikon persegi kecil).
    // Bila belum diisi, logo sistem tetap dipakai seperti sebelumnya.
    $favicon_src = setting('system_favicon', '');
    if ($favicon_src === '') {
        $favicon_src = !empty($sys_settings['system_logo'])
            ? $sys_settings['system_logo']
            : 'uploads/system/logo_1778236863.png';
    }
    ?>
    <link rel="icon" href="<?php echo htmlspecialchars($favicon_src); ?>">
    <?php
    // Calculate dynamic base href for assets and native routing
    $base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
    ?>
    <base href="<?php echo e($base_path); ?>">
    <?php
    // Aset dilayani secara lokal, tidak lagi dari CDN.
    //
    // Sebelumnya halaman ini memuat:
    //   cdn.tailwindcss.com        -> meng-compile CSS DI DALAM BROWSER pada
    //                                 setiap pemuatan halaman; secara resmi
    //                                 tidak diperuntukkan bagi produksi.
    //   unpkg.com/lucide@latest    -> versi tidak terkunci, dapat berubah
    //                                 sendiri sewaktu-waktu tanpa diketahui.
    //   cdn.jsdelivr.net/.../11    -> sama, rentang versi tidak terkunci.
    //   fonts.googleapis.com       -> font.
    //
    // Selain lebih cepat, versi lokal membuat aplikasi tetap tampil normal di
    // jaringan kampus yang memblokir atau membatasi domain luar.
    //
    /* app.css dihasilkan Tailwind CLI v3.4.19 dengan safelist untuk kelas yang
       dirakit lewat interpolasi PHP -- misalnya nama kelas berpola
       "bg-" + variabel warna + "-500" yang disusun di dalam atribut class.
       Bila menambah kelas warna dinamis baru, perbarui safelist di
       _dev/build/tailwind.config.js lalu jalankan build ulang.

       CATATAN: contoh di atas sengaja TIDAK ditulis sebagai kode PHP yang
       sebenarnya. Menuliskan tag penutup PHP di dalam komentar akan menutup
       blok PHP saat itu juga -- termasuk di dalam komentar // maupun # --
       sehingga sisa komentar ikut tercetak sebagai teks di halaman. */
    ?>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/fonts/fonts.css">
    <script src="assets/js/lucide.min.js"></script>
    <script src="assets/js/sweetalert2.min.js"></script>
    <script>
        // Premium Global SweetAlert2 Helpers for unified UI/UX
        function confirmDelete(e, url, message = 'Yakin ingin menghapus data ini?') {
            e.preventDefault();
            Swal.fire({
                title: 'Konfirmasi Hapus',
                text: message,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Ya, Hapus!',
                cancelButtonText: 'Batal',
                borderRadius: '1.5rem',
                customClass: {
                    popup: 'rounded-[2rem] border border-slate-100 shadow-2xl outfit',
                    title: 'text-xl font-black text-slate-800',
                    confirmButton: 'px-6 py-3 bg-red-600 text-white font-bold rounded-2xl shadow-lg shadow-red-200 hover:bg-red-700 transition-all',
                    cancelButton: 'px-6 py-3 bg-slate-500 text-white font-bold rounded-2xl shadow-lg shadow-slate-200 hover:bg-slate-600 transition-all'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = url;
                }
            });
        }

        function confirmAction(e, form, message = 'Yakin ingin melanjutkan?') {
            e.preventDefault();
            Swal.fire({
                title: 'Konfirmasi Tindakan',
                text: message,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#2563eb',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Ya, Lanjutkan!',
                cancelButtonText: 'Batal',
                borderRadius: '1.5rem',
                customClass: {
                    popup: 'rounded-[2rem] border border-slate-100 shadow-2xl outfit',
                    title: 'text-xl font-black text-slate-800',
                    confirmButton: 'px-6 py-3 bg-blue-600 text-white font-bold rounded-2xl shadow-lg shadow-blue-200 hover:bg-blue-700 transition-all',
                    cancelButton: 'px-6 py-3 bg-slate-500 text-white font-bold rounded-2xl shadow-lg shadow-slate-200 hover:bg-slate-600 transition-all'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    if (typeof form === 'string') window.location.href = form;
                    else form.submit();
                }
            });
        }

        /**
         * Dialog konfirmasi seragam.
         *
         * Menggantikan confirm() bawaan peramban yang tampilannya berbeda di
         * setiap sistem operasi dan tidak selaras dengan desain aplikasi.
         *
         * Bersifat asinkron, sehingga pemanggil pada penjaga submit form harus
         * selalu mengembalikan false lalu memanggil form.submit() di dalam
         * callback -- form.submit() tidak memicu ulang handler onsubmit.
         */
        function swalConfirm(title, text, onConfirm, confirmText = 'Ya, Lanjutkan') {
            Swal.fire({
                title: title,
                text: text,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: getComputedStyle(document.documentElement)
                    .getPropertyValue('--brand-primary').trim() || '#2563eb',
                cancelButtonColor: '#64748b',
                confirmButtonText: confirmText,
                cancelButtonText: 'Batal',
                reverseButtons: true,
                customClass: {
                    popup: 'rounded-[2rem] border border-slate-100 shadow-2xl outfit',
                    title: 'text-xl font-black text-slate-800'
                }
            }).then((result) => {
                if (result.isConfirmed && typeof onConfirm === 'function') onConfirm();
            });
            return false;
        }

        /**
         * Panel notifikasi ringkas di header.
         *
         * Tujuan pengalihan SELALU berasal dari basis data (dikembalikan API),
         * tidak pernah dari parameter URL -- versi lama memakai parameter yang
         * dapat disalahgunakan untuk mengarahkan pengguna ke situs luar.
         */
        (function () {
            const btn   = document.getElementById('notifBtn');
            const panel = document.getElementById('notifPanel');
            const listE = document.getElementById('notifPanelList');
            const badge = document.getElementById('notifBadge');
            if (!btn || !panel) return;

            const API  = 'api/notifications.php';
            const CSRF = <?php echo json_encode(get_csrf_token()); ?>;
            let loaded = false;

            const TONES = {
                emerald: 'bg-emerald-50 text-emerald-600',
                amber:   'bg-amber-50 text-amber-600',
                red:     'bg-red-50 text-red-600',
                blue:    'bg-blue-50 text-blue-600'
            };

            function esc(s) {
                return String(s == null ? '' : s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            // Dipakai juga oleh halaman Pusat Notifikasi agar lencana selaras.
            window.updateNotifBadge = function (n) {
                if (!badge) return;
                badge.textContent = n > 9 ? '9+' : n;
                badge.classList.toggle('hidden', n === 0);
            };

            function post(action, extra) {
                return fetch(API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
                    body: new URLSearchParams(Object.assign({ action: action, csrf_token: CSRF }, extra || {}))
                }).then(r => r.json());
            }

            function render(d) {
                window.updateNotifBadge(d.unread);
                document.getElementById('notifPanelReadAll').disabled = d.unread === 0;

                if (!d.items.length) {
                    listE.innerHTML = `<div class="px-5 py-12 text-center">
                        <i data-lucide="bell-off" class="w-9 h-9 text-slate-200 mx-auto mb-3" aria-hidden="true"></i>
                        <p class="text-sm font-bold text-slate-500">Tidak ada notifikasi baru</p>
                        <p class="text-xs text-slate-400 mt-1">Pembaruan layanan akan muncul di sini.</p>
                    </div>`;
                    lucide.createIcons();
                    return;
                }

                listE.innerHTML = d.items.map(n => `
                    <button type="button" data-id="${n.id}" data-link="${n.has_link ? '1' : '0'}"
                            class="notif-item w-full text-left px-5 py-4 flex gap-3.5 hover:bg-slate-50 transition-colors">
                        <span class="w-9 h-9 ${TONES[n.tone] || TONES.blue} rounded-xl flex items-center justify-center shrink-0">
                            <i data-lucide="${n.icon}" class="w-4 h-4" aria-hidden="true"></i>
                        </span>
                        <span class="flex-1 min-w-0">
                            <span class="flex items-center gap-2">
                                <span class="font-bold text-slate-800 text-[13px] leading-snug">${esc(n.title)}</span>
                                <span class="w-1.5 h-1.5 brand-bg rounded-full shrink-0"></span>
                            </span>
                            <span class="block text-slate-500 text-xs mt-1 leading-relaxed line-clamp-2">${esc(n.message)}</span>
                            <span class="block text-[10px] font-bold text-slate-400 mt-1.5">${esc(n.relative)}</span>
                        </span>
                    </button>`).join('');
                lucide.createIcons();
            }

            function load() {
                fetch(`${API}?action=list&filter=unread&limit=6`)
                    .then(r => r.json())
                    .then(d => { if (d.success) { render(d); loaded = true; } })
                    .catch(() => {
                        listE.innerHTML = '<p class="px-5 py-8 text-center text-sm text-slate-400">Gagal memuat notifikasi.</p>';
                    });
            }

            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                const buka = panel.classList.contains('hidden');
                panel.classList.toggle('hidden', !buka);
                btn.setAttribute('aria-expanded', buka ? 'true' : 'false');
                if (buka && !loaded) load();
            });

            listE.addEventListener('click', function (e) {
                const item = e.target.closest('.notif-item');
                if (!item) return;
                post('read', { id: item.dataset.id }).then(d => {
                    if (d.success && d.link) window.location.href = d.link;
                    else load();
                });
            });

            document.getElementById('notifPanelReadAll').addEventListener('click', function (e) {
                e.stopPropagation();
                post('read_all').then(load);
            });

            // Tutup saat klik di luar atau menekan Esc
            document.addEventListener('click', function (e) {
                if (!panel.classList.contains('hidden') && !panel.contains(e.target) && e.target !== btn) {
                    panel.classList.add('hidden');
                    btn.setAttribute('aria-expanded', 'false');
                }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !panel.classList.contains('hidden')) {
                    panel.classList.add('hidden');
                    btn.setAttribute('aria-expanded', 'false');
                    btn.focus();
                }
            });
        })();

        function showSwalAlert(title, text, icon = 'info') {
            Swal.fire({
                title: title,
                text: text,
                icon: icon,
                confirmButtonColor: '#2563eb',
                confirmButtonText: 'Mengerti',
                borderRadius: '1.5rem',
                customClass: {
                    popup: 'rounded-[2rem] border border-slate-100 shadow-2xl outfit',
                    title: 'text-xl font-black text-slate-800',
                    confirmButton: 'px-6 py-3 bg-blue-600 text-white font-bold rounded-2xl shadow-lg shadow-blue-200 hover:bg-blue-700 transition-all'
                }
            });
        }
    </script>
    <link rel="stylesheet" href="assets/fonts/fonts.css">
    <script>
        // Premium URL Masking: Fallback cleanup for address bar
        if (window.history && window.history.replaceState) {
            const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname.replace(/\/index\.php$/, '/');
            window.history.replaceState({}, document.title, cleanUrl);
        }
    </script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f8fafc; scroll-behavior: smooth; }
        .outfit { font-family: 'Outfit', sans-serif; }
        .glass { background: rgba(255, 255, 255, 0.75); backdrop-filter: blur(25px); -webkit-backdrop-filter: blur(25px); }
        .sidebar-link.active { background: #2563eb; color: white; box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.2); }
        .pill-nav { background: rgba(255, 255, 255, 0.8); backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px); border-radius: 2rem; margin: 1rem; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05); }
        
        /* Premium Custom Scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; border: 2px solid transparent; background-clip: content-box; }
        ::-webkit-scrollbar-thumb:hover { background: #cbd5e1; border: 2px solid transparent; background-clip: content-box; }

        /* Animation */
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade { animation: fadeIn 0.5s ease forwards; }

        /* ── Identitas visual institusi ─────────────────────────────────
           Warna utama dapat diatur lewat Pengaturan Sistem, sehingga sistem
           dapat menyesuaikan diri dengan identitas visual universitas tanpa
           menyunting kode. */
        :root { --brand-primary: <?php echo brand_primary_color(); ?>; }
        .brand-text { color: var(--brand-primary); }
        .brand-bg   { background-color: var(--brand-primary); }
        .brand-border { border-color: var(--brand-primary); }

        /* ── Aksesibilitas: fokus keyboard ──────────────────────────────
           Sebelumnya tidak ada indikator fokus sama sekali, sehingga pengguna
           yang bernavigasi dengan Tab tidak tahu posisi kursornya berada.
           (WCAG 2.1 kriteria 2.4.7 Focus Visible) */
        :focus-visible {
            outline: 3px solid var(--brand-primary);
            outline-offset: 2px;
            border-radius: 8px;
        }

        /* ── Aksesibilitas: lewati navigasi ─────────────────────────────
           Tautan pertama pada halaman, tersembunyi sampai difokuskan dengan
           Tab. Memungkinkan pengguna keyboard dan pembaca layar melompati
           menu yang panjang. (WCAG 2.1 kriteria 2.4.1 Bypass Blocks) */
        .skip-link {
            position: absolute;
            left: -9999px;
            top: 0;
            z-index: 100;
            padding: 1rem 1.75rem;
            background: var(--brand-primary);
            color: #fff;
            font-weight: 700;
            border-radius: 0 0 1rem 0;
            box-shadow: 0 10px 25px rgba(0,0,0,.15);
        }
        .skip-link:focus {
            left: 0;
        }

        /* Hormati preferensi pengguna yang membatasi animasi. */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: .001ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: .001ms !important;
            }
        }
    </style>
    <?php if ($dashboard_bg_animation): ?>
    <style>
        .bg-gradient-mesh {
            background-color: #f0f4f8;
            background-image: 
                radial-gradient(at 40% 20%, hsla(216,100%,94%,1) 0px, transparent 50%),
                radial-gradient(at 80% 0%, hsla(189,100%,92%,1) 0px, transparent 50%),
                radial-gradient(at 0% 50%, hsla(216,100%,94%,1) 0px, transparent 50%),
                radial-gradient(at 80% 50%, hsla(223,100%,93%,1) 0px, transparent 50%),
                radial-gradient(at 0% 100%, hsla(189,100%,92%,1) 0px, transparent 50%),
                radial-gradient(at 80% 100%, hsla(216,100%,94%,1) 0px, transparent 50%),
                radial-gradient(at 0% 0%, hsla(223,100%,93%,1) 0px, transparent 50%);
        }
        @keyframes blob {
            0% { transform: translate(0px, 0px) scale(1); }
            33% { transform: translate(30px, -50px) scale(1.1); }
            66% { transform: translate(-20px, 20px) scale(0.9); }
            100% { transform: translate(0px, 0px) scale(1); }
        }
        
        @keyframes float {
            0% { transform: translateY(0px) rotate(0deg); opacity: 0; }
            10% { opacity: 0.5; }
            90% { opacity: 0.5; }
            100% { transform: translateY(-100vh) rotate(360deg); opacity: 0; }
        }

        .animate-blob { animation: blob 10s infinite alternate; }
        .animation-delay-2000 { animation-delay: 2s; }
        .animation-delay-4000 { animation-delay: 4s; }

        .particles-container { position: fixed; top: 0; left: 0; width: 100%; height: 100%; overflow: hidden; z-index: -10; pointer-events: none; }
        .particle {
            position: absolute;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.4), rgba(147, 197, 253, 0.1));
            backdrop-filter: blur(2px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            animation: float linear infinite;
            bottom: -100px;
        }

        .bg-grid-pattern {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background-image: 
                linear-gradient(to right, rgba(99, 102, 241, 0.05) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(99, 102, 241, 0.05) 1px, transparent 1px);
            background-size: 50px 50px;
            z-index: -11;
            mask-image: radial-gradient(circle at center, black 40%, transparent 80%);
            -webkit-mask-image: radial-gradient(circle at center, black 40%, transparent 80%);
        }
    </style>
    <?php endif; ?>
</head>
<body class="antialiased text-slate-800 <?php echo $dashboard_bg_animation ? 'bg-gradient-mesh relative overflow-x-hidden' : ''; ?>">
    <!-- Tautan lewati navigasi: tersembunyi hingga difokuskan dengan Tab (WCAG 2.4.1) -->
    <a href="#main-content" class="skip-link">Lewati ke konten utama</a>
    <?php if ($dashboard_bg_animation): ?>
    <!-- Antigravity Tech Grid -->
    <div class="bg-grid-pattern"></div>

    <!-- Antigravity Floating Particles -->
    <div class="particles-container" id="particles"></div>

    <!-- Decorative Blobs (Glows) -->
    <div class="fixed top-0 left-0 w-full h-full overflow-hidden -z-10 pointer-events-none">
        <div class="absolute top-10 left-10 w-96 h-96 bg-blue-400 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-blob"></div>
        <div class="absolute top-40 right-20 w-96 h-96 bg-indigo-400 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-blob animation-delay-2000"></div>
        <div class="absolute -bottom-20 left-1/3 w-96 h-96 bg-cyan-400 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-blob animation-delay-4000"></div>
    </div>
    <?php endif; ?>
    <div class="flex min-h-screen">
        <!-- Desktop Sidebar -->
        <aside class="hidden md:flex w-72 bg-white/40 backdrop-blur-md border-r border-slate-200 flex-col p-6 sticky top-0 h-screen overflow-y-auto">
            <div class="flex items-center gap-3 mb-10 px-2">
                <div class="w-10 h-10 bg-white rounded-2xl flex items-center justify-center shadow-md border border-slate-100 overflow-hidden">
                    <img src="<?php echo e(!empty($sys_settings['system_logo']) ? $sys_settings['system_logo'] : 'uploads/system/logo_1778236863.png'); ?>" class="w-8 h-8 object-contain" alt="Logo">
                </div>
                <h1 class="text-xl font-black outfit tracking-tighter text-slate-800">Alumni<span class="text-blue-600">Link</span></h1>
            </div>
            
            <nav class="flex-1 space-y-2">
                <!-- MAIN MENU -->
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4 ml-4">Main Menu</p>
                <?php foreach ($all_menus['main'] as $menu): ?>
                    <?php if (can_see_menu($menu['key'], $current_role, $sidebar_permissions)): ?>
                    <a href="<?php echo e($menu['key']); ?>"
                       class="sidebar-link flex items-center gap-3 px-4 py-3 rounded-xl transition-all <?php echo e($page == $menu['key'] ? 'active' : 'text-slate-500 hover:bg-white/60'); ?>">
                        <i data-lucide="<?php echo e($menu['icon']); ?>" class="w-5 h-5"></i>
                        <?php echo htmlspecialchars($menu['label']); ?>
                    </a>
                    <?php endif; ?>
                <?php endforeach; ?>

                <!-- ADMINISTRATOR -->
                <?php
                // Cek apakah ada minimal 1 menu admin yang visible untuk role ini
                $has_admin_menu = false;
                foreach ($all_menus['admin'] as $m) {
                    if (can_see_menu($m['key'], $current_role, $sidebar_permissions)) { $has_admin_menu = true; break; }
                }
                ?>
                <?php if ($has_admin_menu): ?>
                    <div class="pt-6 pb-2 border-b border-slate-100 mb-3 mx-4">
                        <p class="text-[10.5px] font-extrabold text-slate-700 uppercase tracking-wider">Panel Admin</p>
                    </div>
                    <?php 
                    $current_group = '';
                    foreach ($all_menus['admin'] as $menu): 
                        if (can_see_menu($menu['key'], $current_role, $sidebar_permissions)): 
                            $menu_group = $menu['group'] ?? 'Administrator';
                            if ($menu_group !== $current_group): 
                                $current_group = $menu_group;
                                ?>
                                <div class="pt-4 pb-1 ml-4">
                                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest opacity-80"><?php echo htmlspecialchars($current_group); ?></p>
                                </div>
                                <?php 
                            endif;
                            ?>
                            <a href="<?php echo e($menu['key']); ?>"
                               class="sidebar-link flex items-center gap-3 px-4 py-3 rounded-xl transition-all <?php echo e($page == $menu['key'] ? 'active' : 'text-slate-500 hover:bg-white/60'); ?>">
                                <i data-lucide="<?php echo e($menu['icon']); ?>" class="w-5 h-5"></i>
                                <?php echo htmlspecialchars($menu['label']); ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </nav>

            <div class="mt-auto pt-10 border-t border-slate-100">
                <a href="logout.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-red-500 hover:bg-red-50 transition-all font-bold text-sm">
                    <i data-lucide="log-out" class="w-5 h-5"></i> Keluar
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main id="main-content" tabindex="-1" class="flex-1 p-4 md:p-12 pb-32 md:pb-12 h-screen overflow-y-auto animate-fade">
            <!-- Header Bar (Mobile & Desktop) -->
            <header class="flex items-center justify-between mb-10 px-2 md:px-0">
                <div class="flex flex-col">
                    <h2 class="text-2xl md:text-3xl font-black outfit text-slate-800 tracking-tight leading-none">Alumni<span class="text-blue-600">Link</span></h2>
                    <p class="text-[9px] md:text-[11px] text-slate-400 font-bold uppercase tracking-[0.3em] mt-1.5 leading-none"><?php echo e($current_title); ?> &bull; <?php echo e($sys_settings['institution_name'] ?? 'FK UMS'); ?></p>
                </div>
                
                <div class="flex items-center gap-3 md:gap-5">
                    <!-- Notification Bell -->
                    <!-- Notifikasi: tombol + panel ringkas ala notifikasi ponsel -->
                    <div class="relative" id="notifWrap">
                        <button type="button" id="notifBtn" aria-haspopup="true" aria-expanded="false"
                                aria-label="Notifikasi"
                                class="relative w-10 h-10 md:w-12 md:h-12 flex items-center justify-center bg-white rounded-2xl shadow-sm border border-slate-100 hover:bg-slate-50 transition-all group">
                            <i data-lucide="bell" class="w-5 h-5 md:w-6 md:h-6 text-slate-400 group-hover:brand-text transition-colors" aria-hidden="true"></i>
                            <?php // Lencana berangka, bukan sekadar titik — jumlahnya langsung terbaca ?>
                            <span id="notifBadge"
                                  class="<?php echo $unread_count > 0 ? '' : 'hidden'; ?> absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 bg-red-500 text-white text-[10px] font-black rounded-full border-2 border-white flex items-center justify-center leading-none">
                                <?php echo $unread_count > 9 ? '9+' : (int)$unread_count; ?>
                            </span>
                        </button>

                        <!-- Panel -->
                        <div id="notifPanel"
                             class="hidden absolute right-0 mt-3 w-[min(92vw,25rem)] bg-white rounded-3xl shadow-2xl border border-slate-100 overflow-hidden z-50"
                             role="dialog" aria-label="Daftar notifikasi">

                            <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
                                <p class="font-black outfit text-slate-800 text-sm">Notifikasi</p>
                                <button type="button" id="notifPanelReadAll"
                                        class="text-[11px] font-bold brand-text hover:underline disabled:opacity-40 disabled:no-underline">
                                    Tandai semua dibaca
                                </button>
                            </div>

                            <div id="notifPanelList" class="max-h-[60vh] overflow-y-auto divide-y divide-slate-50">
                                <p class="px-5 py-10 text-center text-sm text-slate-400">Memuat…</p>
                            </div>

                            <a href="notifications"
                               class="block px-5 py-3.5 text-center text-xs font-bold brand-text hover:bg-slate-50 border-t border-slate-100 transition-colors">
                                Lihat semua notifikasi
                            </a>
                        </div>
                    </div>
                    
                    <a href="profile" class="flex items-center gap-3 bg-white p-1.5 md:p-2 rounded-2xl border border-slate-100 shadow-sm pr-4 md:pr-6 hover:border-blue-200 transition-all group">
                        <div class="w-8 h-8 md:w-10 md:h-10 bg-blue-600 rounded-xl flex items-center justify-center text-white font-black outfit text-xs md:text-sm uppercase shadow-lg shadow-blue-500/20 group-hover:scale-105 transition-transform overflow-hidden">
                            <?php if ($user_header && $user_header->avatar && file_exists('uploads/avatars/' . $user_header->avatar)): ?>
                                <img src="uploads/avatars/<?php echo e($user_header->avatar); ?>" alt="Avatar" class="w-full h-full object-cover">
                            <?php else: ?>
                                <?php echo e(substr($_SESSION['user_name'], 0, 2)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="hidden sm:block">
                            <p class="text-[10px] md:text-xs font-black text-slate-800 leading-none mb-1 group-hover:text-blue-600 transition-colors"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                            <p class="text-[8px] md:text-[9px] font-bold text-slate-400 uppercase tracking-widest"><?php echo htmlspecialchars($_SESSION['user_role']); ?></p>
                        </div>
                    </a>
                </div>
            </header>
