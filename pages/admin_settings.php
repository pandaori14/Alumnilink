<?php
// Guard: super_admin only
if (($_SESSION['user_role'] ?? '') !== 'super_admin') {
    ?>
    <div class="max-w-2xl mx-auto px-4 py-16 text-center">
        <div class="glass p-12 rounded-[3rem] border border-white shadow-xl relative overflow-hidden">
            <div class="absolute -right-10 -top-10 w-40 h-40 bg-red-50 rounded-full blur-2xl pointer-events-none"></div>
            <div class="w-24 h-24 bg-red-100 text-red-600 rounded-3xl flex items-center justify-center mx-auto mb-8 shadow-inner border border-red-200">
                <i data-lucide="shield-alert" class="w-12 h-12"></i>
            </div>
            <h2 class="text-3xl font-black outfit text-slate-800 mb-4 tracking-tight">Akses Ditolak</h2>
            <p class="text-slate-500 mb-8 max-w-md mx-auto leading-relaxed text-sm">Maaf, halaman ini berisi konfigurasi tingkat lanjut yang hanya dapat diakses oleh akun dengan peran <span class="font-bold text-slate-700">Super Administrator</span>.</p>
            <a href="index.php?page=dashboard" class="inline-flex items-center gap-3 px-8 py-4 bg-slate-800 text-white rounded-2xl font-bold text-sm shadow-lg hover:bg-slate-900 transition-all active:scale-95">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>
                Kembali ke Dashboard
            </a>
        </div>
    </div>
    <script>lucide.createIcons();</script>
    <?php
    exit();
}

// Fetch all settings
$stmt = $pdo->query("SELECT * FROM settings");
$raw_settings = $stmt->fetchAll();
$settings = [];
foreach ($raw_settings as $s) {
    $settings[$s->setting_key] = $s->setting_value;
}

// Fetch all majors for document target mapping
$stmt_majors = $pdo->query("SELECT major_code, major_name FROM majors ORDER BY major_name ASC");
$majors_list = $stmt_majors->fetchAll();

// Shipping Zones and Provinces
$zones_raw = $settings['shipping_zones'] ?? '[]';
$zones = json_decode($zones_raw, true) ?: [];
$all_provinces = ["Aceh","Bali","Banten","Bengkulu","DI Yogyakarta","DKI Jakarta","Gorontalo","Jambi","Jawa Barat","Jawa Tengah","Jawa Timur","Kalimantan Barat","Kalimantan Selatan","Kalimantan Tengah","Kalimantan Timur","Kalimantan Utara","Kepulauan Bangka Belitung","Kepulauan Riau","Lampung","Maluku","Maluku Utara","Nusa Tenggara Barat","Nusa Tenggara Timur","Papua","Papua Barat","Papua Barat Daya","Papua Pegunungan","Papua Selatan","Papua Tengah","Riau","Sulawesi Barat","Sulawesi Selatan","Sulawesi Tengah","Sulawesi Tenggara","Sulawesi Utara","Sumatera Barat","Sumatera Selatan","Sumatera Utara"];

// ── Master Menu & Izin Bawaan ─────────────────────────────────────────────
// Dulu disalin dari header.php dan tertinggal tujuh menu: kotak centangnya
// tidak pernah dirender, sehingga menekan Simpan mencabut menu-menu itu.
require_once __DIR__ . '/../includes/menu.php';
$all_menus_admin = all_menu_definitions();
$all_menus_flat  = array_merge($all_menus_admin['main'], $all_menus_admin['admin']);
$default_perms   = default_sidebar_permissions();

// Load saved permissions
$saved_perms = $default_perms;
if (!empty($settings['sidebar_permissions'])) {
    $decoded = json_decode($settings['sidebar_permissions'], true);
    if (is_array($decoded)) $saved_perms = array_merge($default_perms, $decoded);
}

// ── Role metadata ──────────────────────────────────────────────────────────
$roles_meta = [
    'alumni'          => ['label' => 'Alumni',           'color' => 'blue',   'icon' => 'graduation-cap'],
    'admin_tracer'    => ['label' => 'Admin Tracer',      'color' => 'violet', 'icon' => 'file-search'],
    'admin_legalisir' => ['label' => 'Admin Legalisir',   'color' => 'amber',  'icon' => 'award'],
    'keuangan'        => ['label' => 'Admin Keuangan',    'color' => 'emerald','icon' => 'receipt'],
];

$color_map = [
    'blue'    => ['bg' => 'bg-blue-100',    'text' => 'text-blue-600',    'border' => 'border-blue-200',    'check' => 'accent-blue-600'],
    'violet'  => ['bg' => 'bg-violet-100',  'text' => 'text-violet-600',  'border' => 'border-violet-200',  'check' => 'accent-violet-600'],
    'amber'   => ['bg' => 'bg-amber-100',   'text' => 'text-amber-600',   'border' => 'border-amber-200',   'check' => 'accent-amber-600'],
    'emerald' => ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-600', 'border' => 'border-emerald-200', 'check' => 'accent-emerald-600'],
];
?>

<div class="max-w-4xl mx-auto pb-20">
    <div class="mb-10 px-1">
        <h1 class="text-2xl md:text-3xl font-black outfit text-slate-800 tracking-tight">Pengaturan Sistem</h1>
        <p class="text-slate-400 text-sm mt-1 font-medium">Kelola seluruh konfigurasi platform AlumniLink dari satu tempat.</p>
    </div>

    <!-- Tab Navigation (Premium Responsive Slider) -->
    <div class="mb-8 border-b border-slate-200 flex flex-wrap gap-2 pb-2">
        <button type="button" onclick="switchSettingsTab('tab-identity')" id="btn-tab-identity" class="settings-tab-btn flex items-center justify-center gap-2 px-4 py-3 rounded-2xl text-sm font-bold transition-all bg-slate-900 text-white shadow-lg flex-1 min-w-[160px]">
            <i data-lucide="building-2" class="w-4 h-4"></i> Identitas & Konten
        </button>
        <button type="button" onclick="switchSettingsTab('tab-system')" id="btn-tab-system" class="settings-tab-btn flex items-center justify-center gap-2 px-4 py-3 rounded-2xl text-sm font-bold transition-all text-slate-500 hover:text-slate-800 hover:bg-slate-50 flex-1 min-w-[160px]">
            <i data-lucide="settings-2" class="w-4 h-4"></i> Sistem & Keamanan
        </button>
        <button type="button" onclick="switchSettingsTab('tab-payment')" id="btn-tab-payment" class="settings-tab-btn flex items-center justify-center gap-2 px-4 py-3 rounded-2xl text-sm font-bold transition-all text-slate-500 hover:text-slate-800 hover:bg-slate-50 flex-1 min-w-[160px]">
            <i data-lucide="credit-card" class="w-4 h-4"></i> Pembayaran & Kurir
        </button>
        <button type="button" onclick="switchSettingsTab('tab-integration')" id="btn-tab-integration" class="settings-tab-btn flex items-center justify-center gap-2 px-4 py-3 rounded-2xl text-sm font-bold transition-all text-slate-500 hover:text-slate-800 hover:bg-slate-50 flex-1 min-w-[160px]">
            <i data-lucide="key" class="w-4 h-4"></i> Integrasi API
        </button>
        <button type="button" onclick="switchSettingsTab('tab-documents')" id="btn-tab-documents" class="settings-tab-btn flex items-center justify-center gap-2 px-4 py-3 rounded-2xl text-sm font-bold transition-all text-slate-500 hover:text-slate-800 hover:bg-slate-50 flex-1 min-w-[160px]">
            <i data-lucide="files" class="w-4 h-4"></i> Dokumen & Akses
        </button>
        <button type="button" onclick="switchSettingsTab('tab-rules')" id="btn-tab-rules" class="settings-tab-btn flex items-center justify-center gap-2 px-4 py-3 rounded-2xl text-sm font-bold transition-all text-slate-500 hover:text-slate-800 hover:bg-slate-50 flex-1 min-w-[160px]">
            <i data-lucide="sliders-horizontal" class="w-4 h-4"></i> Aturan & Keamanan
        </button>
    </div>

    <form action="handlers/admin_settings_handler.php" method="POST" enctype="multipart/form-data" class="space-y-8" id="settings-form">
        <?php csrf_field(); ?>


        <div class="settings-tab-content tab-identity space-y-8">
            <!-- 1. Identitas Institusi -->
            <div class="glass p-10 rounded-[3rem] shadow-sm border border-white">
                <div class="flex items-center gap-4 mb-8">
                <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-2xl flex items-center justify-center">
                    <i data-lucide="building-2" class="w-6 h-6"></i>
                </div>
                <h2 class="text-xl font-bold outfit text-slate-800">Identitas Institusi</h2>
            </div>

            <div class="flex flex-col md:flex-row gap-10 items-start">
                <div class="shrink-0 text-center">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Logo Universitas</p>
                    <div class="w-32 h-32 bg-white rounded-3xl flex items-center justify-center shadow-inner border border-slate-100 overflow-hidden mx-auto">
                        <?php if(!empty($settings['system_logo'])): ?>
                            <img src="<?php echo e($settings['system_logo']); ?>" class="w-20 h-20 object-contain" alt="Current Logo">
                        <?php else: ?>
                            <i data-lucide="image" class="w-10 h-10 text-slate-200"></i>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="flex-1 space-y-6">
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_system_logo_file">Unggah Logo Baru</label>
                        <input id="f_system_logo_file" type="file" name="system_logo_file" accept="image/*" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm transition-all">
                        <p class="text-[10px] text-slate-400 mt-2 ml-1">Rekomendasi: PNG transparan, rasio 1:1, Maks 2MB.</p>
                    </div>
                </div>
            </div>
            </div>

            <!-- 3. KONTAK BANTUAN WHATSAPP (CARD TERPISAH) -->
            <div class="bg-gradient-to-br from-green-500 to-emerald-600 p-1 rounded-[3rem] shadow-xl shadow-green-100">
                <div class="bg-white p-10 rounded-[2.8rem] h-full">
                    <div class="flex items-center gap-4 mb-8">
                    <div class="w-14 h-14 bg-green-50 text-green-600 rounded-2xl flex items-center justify-center">
                        <i data-lucide="message-circle" class="w-8 h-8"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-black outfit text-slate-800 uppercase tracking-tight">Kontak Bantuan Alumni</h2>
                        <p class="text-xs text-slate-400 font-medium">Nomor WhatsApp yang akan muncul di dashboard alumni sebagai support center.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="p-6 bg-slate-50 rounded-3xl border border-slate-100 group hover:border-green-200 transition-all">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-8 h-8 bg-blue-500 text-white rounded-lg flex items-center justify-center">
                                <i data-lucide="bar-chart-3" class="w-4 h-4"></i>
                            </div>
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Admin Tracer</span>
                        </div>
                        <label class="block text-[11px] font-bold text-slate-500 mb-1.5 ml-1" for="f_wa_tracer">Nomor WhatsApp</label>
                        <input id="f_wa_tracer" type="text" name="wa_tracer" value="<?php echo e($settings['wa_tracer'] ?? ''); ?>" placeholder="6281xxxx"
                            class="w-full px-4 py-3 rounded-xl bg-white border border-slate-200 focus:border-green-500 outline-none text-sm font-bold transition-all">
                    </div>

                    <div class="p-6 bg-slate-50 rounded-3xl border border-slate-100 group hover:border-green-200 transition-all">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-8 h-8 bg-indigo-500 text-white rounded-lg flex items-center justify-center">
                                <i data-lucide="award" class="w-4 h-4"></i>
                            </div>
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Admin Legalisir</span>
                        </div>
                        <label class="block text-[11px] font-bold text-slate-500 mb-1.5 ml-1" for="f_wa_legalisir">Nomor WhatsApp</label>
                        <input id="f_wa_legalisir" type="text" name="wa_legalisir" value="<?php echo e($settings['wa_legalisir'] ?? ''); ?>" placeholder="6281xxxx"
                            class="w-full px-4 py-3 rounded-xl bg-white border border-slate-200 focus:border-green-500 outline-none text-sm font-bold transition-all">
                    </div>

                    <div class="p-6 bg-slate-50 rounded-3xl border border-slate-100 group hover:border-green-200 transition-all">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-8 h-8 bg-amber-500 text-white rounded-lg flex items-center justify-center">
                                <i data-lucide="cpu" class="w-4 h-4"></i>
                            </div>
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">IT Support</span>
                        </div>
                        <label class="block text-[11px] font-bold text-slate-500 mb-1.5 ml-1" for="f_wa_itsupport">Nomor WhatsApp</label>
                        <input id="f_wa_itsupport" type="text" name="wa_itsupport" value="<?php echo e($settings['wa_itsupport'] ?? ''); ?>" placeholder="6281xxxx"
                            class="w-full px-4 py-3 rounded-xl bg-white border border-slate-200 focus:border-green-500 outline-none text-sm font-bold transition-all">
                    </div>
                </div>

                <!-- Extra Contact Info: Email & Address -->
                <div class="mt-8 pt-8 border-t border-slate-100 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[11px] font-black text-slate-500 uppercase tracking-widest mb-3" for="f_contact_email">
                            <span class="flex items-center gap-2"><i data-lucide="mail" class="w-3.5 h-3.5 text-green-500"></i> Email Kontak Publik</span>
                        </label>
                        <p class="text-[10px] text-slate-400 mb-2 ml-1">Akan ditampilkan di seksi "Hubungi Kami" pada landing page.</p>
                        <input id="f_contact_email" type="email" name="contact_email" value="<?php echo htmlspecialchars($settings['contact_email'] ?? ''); ?>" placeholder="contoh@universitasmu.ac.id"
                            class="w-full px-4 py-3 rounded-xl bg-white border border-slate-200 focus:border-green-500 outline-none text-sm font-medium transition-all">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-500 uppercase tracking-widest mb-3" for="f_contact_address">
                            <span class="flex items-center gap-2"><i data-lucide="map-pin" class="w-3.5 h-3.5 text-green-500"></i> Alamat Lengkap Institusi</span>
                        </label>
                        <p class="text-[10px] text-slate-400 mb-2 ml-1">Akan ditampilkan di seksi "Hubungi Kami" pada landing page.</p>
                        <textarea id="f_contact_address" name="contact_address" rows="3" placeholder="Jl. A. Yani Tromol Pos 1, Pabelan, Kartasura..."
                            class="w-full px-4 py-3 rounded-xl bg-white border border-slate-200 focus:border-green-500 outline-none text-sm font-medium transition-all resize-none"><?php echo htmlspecialchars($settings['contact_address'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>
                </div>

            <!-- 9. Konfigurasi Landing Page (Super Admin Only) -->
            <?php if ($_SESSION['user_role'] === 'super_admin'): ?>
            <div class="glass p-10 rounded-[3rem] shadow-sm border border-white">
            <div class="flex items-center gap-4 mb-8">
                <div class="w-12 h-12 bg-indigo-100 text-indigo-600 rounded-2xl flex items-center justify-center">
                    <i data-lucide="layout-template" class="w-6 h-6"></i>
                </div>
                <div>
                    <h2 class="text-xl font-bold outfit text-slate-800">Landing Page Publik</h2>
                    <p class="text-xs text-slate-400">Konfigurasi tampilan halaman utama sebelum login.</p>
                </div>
            </div>

            <div class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_maintenance_mode">Maintenance Mode</label>
                        <select id="f_maintenance_mode" name="maintenance_mode" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-red-500 outline-none text-sm cursor-pointer font-bold <?php echo e(($settings['maintenance_mode'] ?? '0') == '1' ? 'text-red-600 border-red-200' : 'text-emerald-600'); ?>">
                            <option value="0" <?php echo e(($settings['maintenance_mode'] ?? '0') == '0' ? 'selected' : ''); ?>>OFF (Sistem Aktif)</option>
                            <option value="1" <?php echo e(($settings['maintenance_mode'] ?? '0') == '1' ? 'selected' : ''); ?>>ON (Mode Perbaikan)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_landing_hero_title">Judul Utama (Hero Title)</label>
                        <input id="f_landing_hero_title" type="text" name="landing_hero_title" value="<?php echo e($settings['landing_hero_title'] ?? 'Platform Manajemen Alumni Terintegrasi'); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-bold">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_institution_name">Nama Institusi</label>
                        <input id="f_institution_name" type="text" name="institution_name" value="<?php echo e($settings['institution_name'] ?? 'Universitas'); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-bold">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_landing_hero_subtitle">Sub-Judul (Hero Subtitle)</label>
                    <textarea id="f_landing_hero_subtitle" name="landing_hero_subtitle" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm h-24"><?php echo e($settings['landing_hero_subtitle'] ?? 'Portal resmi untuk layanan Tracer Study, Legalisir Dokumen, dan Penggalangan Dana Alumni secara digital, cepat, dan transparan.'); ?></textarea>
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_landing_about">Tentang Institusi</label>
                    <textarea id="f_landing_about" name="landing_about" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm h-32"><?php echo e($settings['landing_about'] ?? 'AlumniLink adalah platform inovatif yang dirancang khusus untuk mempererat tali silaturahmi dan mempermudah layanan administratif bagi seluruh alumni. Kami berkomitmen memberikan pengalaman terbaik melalui digitalisasi layanan.'); ?></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_landing_cta_text">Teks Tombol Aksi (CTA)</label>
                        <input id="f_landing_cta_text" type="text" name="landing_cta_text" value="<?php echo e($settings['landing_cta_text'] ?? 'Masuk ke Portal'); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-bold">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_landing_show_stats">Tampilkan Statistik?</label>
                        <select id="f_landing_show_stats" name="landing_show_stats" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm cursor-pointer">
                            <option value="1" <?php echo e(($settings['landing_show_stats'] ?? '1') == '1' ? 'selected' : ''); ?>>Ya, Tampilkan Angka Realtime</option>
                            <option value="0" <?php echo e(($settings['landing_show_stats'] ?? '1') == '0' ? 'selected' : ''); ?>>Tidak Disembunyikan</option>
                        </select>
                    </div>
                </div>

                <div class="flex items-center justify-between p-5 bg-slate-50/50 border border-slate-100 rounded-2xl mt-4">
                    <div class="flex flex-col pr-4">
                        <span class="text-sm font-bold text-slate-800">Animasi Background Dashboard</span>
                        <span class="text-[11px] text-slate-400 mt-1 leading-relaxed">Jika aktif, seluruh halaman dashboard internal akan menggunakan background mesh gradient dinamis, partikel melayang, dan stempel tech grid premium seperti pada landing page.</span>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer select-none shrink-0">
                        <input type="checkbox" name="dashboard_bg_animation" value="1" <?php echo e(($settings['dashboard_bg_animation'] ?? '1') == '1' ? 'checked' : ''); ?> class="sr-only peer">
                        <div class="w-14 h-8 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-6 after:content-[''] after:absolute after:top-[4px] after:left-[4px] after:bg-white after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-blue-600"></div>
                    </label>
                </div>
            </div>
            </div>
            <?php endif; ?>

        </div>

        <div class="settings-tab-content tab-system space-y-8 hidden">
            <!-- 2. Biaya & Sistem Dasar -->
            <div class="glass p-10 rounded-[3rem] shadow-sm border border-white">
                <div class="flex items-center gap-4 mb-8">
                <div class="w-12 h-12 bg-slate-900 text-white rounded-2xl flex items-center justify-center">
                    <i data-lucide="settings-2" class="w-6 h-6"></i>
                </div>
                <h2 class="text-xl font-bold outfit text-slate-800">Biaya & Sistem Dasar</h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="md:col-span-2">
                    <label class="block text-sm font-bold text-slate-700 mb-3 ml-1" for="f_price_per_doc">Biaya Per Lembar (IDR)</label>
                    <div class="relative">
                        <span class="absolute left-5 top-1/2 -translate-y-1/2 font-bold text-slate-400">Rp</span>
                        <input id="f_price_per_doc" type="number" name="price_per_doc" value="<?php echo e($settings['price_per_doc'] ?? 0); ?>" class="w-full pl-14 pr-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none font-bold text-slate-800" required>
                    </div>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_system_email">Email Notifikasi Sistem</label>
                    <input id="f_system_email" type="email" name="system_email" value="<?php echo e($settings['system_email'] ?? 'admin@alumnilink.com'); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-medium" required>
                </div>
            </div>
            </div>

            <!-- 7. Unggah Berkas -->
            <div class="glass p-10 rounded-[3rem] shadow-sm border border-white">
                <div class="flex items-center gap-4 mb-8">
                <div class="w-12 h-12 bg-amber-100 text-amber-600 rounded-2xl flex items-center justify-center">
                    <i data-lucide="upload-cloud" class="w-6 h-6"></i>
                </div>
                <h2 class="text-xl font-bold outfit text-slate-800">Batas Unggah Berkas</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_allowed_file_types">Format (Pisah Koma)</label>
                    <input id="f_allowed_file_types" type="text" name="allowed_file_types" value="<?php echo e($settings['allowed_file_types'] ?? 'pdf,jpg,png'); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm">
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_max_file_size">Maks Size (KB)</label>
                    <input id="f_max_file_size" type="number" name="max_file_size" value="<?php echo e($settings['max_file_size'] ?? 2048); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm">
                </div>
            </div>
            </div>

            <!-- 9. Utilitas & Sinkronisasi Peta -->
        <div class="glass p-10 rounded-[3rem] shadow-sm border border-white mt-8 animate-in fade-in duration-500">
            <div class="flex items-center gap-4 mb-8">
                <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-2xl flex items-center justify-center">
                    <i data-lucide="map-pin" class="w-6 h-6"></i>
                </div>
                <h2 class="text-xl font-bold outfit text-slate-800">Utilitas & Sinkronisasi Peta</h2>
            </div>
            
            <div class="p-6 bg-slate-50/50 border border-slate-100 rounded-2xl">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                    <div class="space-y-1">
                        <h4 class="text-sm font-bold text-slate-800">Sinkronisasi Koordinat Alumni (Geocoding)</h4>
                        <p class="text-xs text-slate-400 leading-relaxed max-w-xl">
                            Jika Anda tidak mengatur <b>Cron Job otomatis</b> di server SSH/Hosting, gunakan tombol ini untuk menerjemahkan data alamat baru alumni menjadi titik koordinat peta secara manual. Proses ini membutuhkan koneksi internet server ke OpenStreetMap.
                        </p>
                    </div>
                    <button type="button" id="btn-sync-geocoding" class="px-6 py-3.5 bg-blue-600 hover:bg-blue-700 text-white rounded-2xl font-bold text-xs shadow-md transition-all active:scale-95 flex items-center gap-2 shrink-0">
                        <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                        Sinkronkan Lokasi Peta
                    </button>
            </div>
        </div>
        </div>

            <!-- 10. Manajemen Audit Trail & Log Aktivitas -->
            <div class="glass p-10 rounded-[3rem] shadow-sm border border-white mt-8 animate-in fade-in duration-500">
                <div class="flex items-center gap-4 mb-8">
                    <div class="w-12 h-12 bg-red-100 text-red-600 rounded-2xl flex items-center justify-center">
                        <i data-lucide="shield-alert" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold outfit text-slate-800">Manajemen Audit Trail (Log Aktivitas)</h2>
                        <p class="text-xs text-slate-400 mt-1">Atur durasi penyimpanan log aktivitas admin untuk menjaga ukuran database dan performa sistem.</p>
                    </div>
                </div>
                
                <div class="space-y-6">
                    <div class="flex items-center justify-between p-5 bg-slate-50/50 border border-slate-100 rounded-2xl">
                        <div class="flex flex-col pr-4">
                            <span class="text-sm font-bold text-slate-800">Tegakkan Pemisahan Izin Peran (RBAC)</span>
                            <span class="text-[11px] text-slate-400 mt-1 leading-relaxed">
                                <strong>Nonaktif (mode audit):</strong> peran <em>admin_tracer</em>, <em>admin_legalisir</em>, dan <em>keuangan</em> tetap dapat mengakses seluruh fungsi admin, namun setiap akses lintas peran dicatat di Audit Trail sebagai <code class="font-mono text-[10px] bg-slate-200 px-1 rounded">RBAC_AUDIT</code>.<br>
                                <strong>Aktif:</strong> setiap peran hanya dapat mengakses fungsi miliknya sendiri.<br>
                                Biarkan nonaktif beberapa hari lebih dulu, tinjau Audit Trail, baru aktifkan — agar tidak ada staf yang terkunci mendadak.
                            </span>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer select-none shrink-0">
                            <input type="checkbox" name="rbac_enforce" value="1" <?php echo e(($settings['rbac_enforce'] ?? '0') == '1' ? 'checked' : ''); ?> class="sr-only peer">
                            <div class="w-14 h-8 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-6 after:content-[''] after:absolute after:top-[4px] after:left-[4px] after:bg-white after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-emerald-500"></div>
                        </label>
                    </div>

                    <div class="flex items-center justify-between p-5 bg-slate-50/50 border border-slate-100 rounded-2xl">
                        <div class="flex flex-col pr-4">
                            <span class="text-sm font-bold text-slate-800">Aktifkan Auto-Erase Log Lama</span>
                            <span class="text-[11px] text-slate-400 mt-1 leading-relaxed">Jika aktif, sistem secara otomatis akan menghapus riwayat log aktivitas yang usianya melebihi batas retensi yang ditentukan di bawah ini secara teratur di latar belakang.</span>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer select-none shrink-0">
                            <input type="checkbox" name="audit_log_auto_erase" value="1" <?php echo e(($settings['audit_log_auto_erase'] ?? '0') == '1' ? 'checked' : ''); ?> class="sr-only peer">
                            <div class="w-14 h-8 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-6 after:content-[''] after:absolute after:top-[4px] after:left-[4px] after:bg-white after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-red-500"></div>
                        </label>
                    </div>

                    <div class="p-5 bg-slate-50/50 border border-slate-100 rounded-2xl">
                        <label class="block text-sm font-bold text-slate-800 mb-2">Durasi Penyimpanan Log (Hari)</label>
                        <p class="text-xs text-slate-400 mb-4 leading-relaxed">Data log yang usianya lebih tua dari angka ini (dalam hari) akan dihapus secara permanen. Saran: 30 atau 90 hari.</p>
                        <div class="relative max-w-xs">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <i data-lucide="calendar-clock" class="w-4 h-4 text-slate-400"></i>
                            </div>
                            <input aria-label="Contoh: 30" type="number" min="1" max="365" name="audit_log_retention_days" value="<?php echo htmlspecialchars($settings['audit_log_retention_days'] ?? '30'); ?>" 
                                class="w-full pl-11 pr-4 py-3 rounded-xl bg-white border border-slate-200 focus:border-red-500 outline-none font-bold text-slate-700 transition-all shadow-sm" placeholder="Contoh: 30">
                        </div>
                    </div>
                </div>
            </div>


        </div>

        <div class="settings-tab-content tab-payment space-y-8 hidden">
            <!-- 5. Integrasi Midtrans -->
            <div class="glass p-10 rounded-[3rem] shadow-sm border border-white">
                <div class="flex items-center gap-4 mb-8">
                <div class="w-12 h-12 bg-purple-100 text-purple-600 rounded-2xl flex items-center justify-center">
                    <i data-lucide="credit-card" class="w-6 h-6"></i>
                </div>
                <h2 class="text-xl font-bold outfit text-slate-800">Integrasi Pembayaran</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="md:col-span-2">
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_midtrans_is_production">Environment</label>
                    <select id="f_midtrans_is_production" name="midtrans_is_production" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm cursor-pointer">
                        <option value="0" <?php echo e(($settings['midtrans_is_production'] ?? '0') == '0' ? 'selected' : ''); ?>>Sandbox (Testing)</option>
                        <option value="1" <?php echo e(($settings['midtrans_is_production'] ?? '0') == '1' ? 'selected' : ''); ?>>Production (Live)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_midtrans_server_key">Server Key</label>
                    <input id="f_midtrans_server_key" type="text" name="midtrans_server_key" value="<?php echo e($settings['midtrans_server_key'] ?? ''); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none font-mono text-xs">
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_midtrans_client_key">Client Key</label>
                    <input id="f_midtrans_client_key" type="text" name="midtrans_client_key" value="<?php echo e($settings['midtrans_client_key'] ?? ''); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none font-mono text-xs">
                </div>
                
                <!-- NEW GROSS-UP CONFIG FIELDS -->
                <div class="md:col-span-2 mt-4 pt-4 border-t border-slate-100">
                    <h3 class="text-sm font-bold text-slate-800 mb-4">Pengaturan Biaya & Pajak Midtrans</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-2 ml-1" for="f_midtrans_mdr_rate">MDR Rate (%)</label>
                            <input id="f_midtrans_mdr_rate" type="number" step="0.01" name="midtrans_mdr_rate" value="<?php echo e($settings['midtrans_mdr_rate'] ?? '4.00'); ?>" class="w-full px-5 py-3 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none font-medium text-xs">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-2 ml-1" for="f_midtrans_ppn_rate">PPN Midtrans (%)</label>
                            <input id="f_midtrans_ppn_rate" type="number" step="0.01" name="midtrans_ppn_rate" value="<?php echo e($settings['midtrans_ppn_rate'] ?? '11.00'); ?>" class="w-full px-5 py-3 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none font-medium text-xs">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-2 ml-1" for="f_midtrans_payout_fee">Biaya Payout (Rp)</label>
                            <input id="f_midtrans_payout_fee" type="number" name="midtrans_payout_fee" value="<?php echo e($settings['midtrans_payout_fee'] ?? '2500'); ?>" class="w-full px-5 py-3 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none font-medium text-xs">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-2 ml-1" for="f_midtrans_margin_admin">Application Fee (Rp)</label>
                            <input id="f_midtrans_margin_admin" type="number" name="midtrans_margin_admin" value="<?php echo e($settings['midtrans_margin_admin'] ?? '2500'); ?>" class="w-full px-5 py-3 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none font-medium text-xs">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-2 ml-1" for="f_custom_tax_value">Custom Tax Value (Rp)</label>
                            <input id="f_custom_tax_value" type="number" name="custom_tax_value" value="<?php echo e($settings['custom_tax_value'] ?? '0'); ?>" class="w-full px-5 py-3 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none font-medium text-xs">
                        </div>
                    </div>
                </div>

            </div>
            </div>

            <!-- 7. Zona Pengiriman -->
            <div class="glass p-10 rounded-[3rem] shadow-sm border border-white">
                <div class="flex items-center justify-between mb-8">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-red-100 text-red-600 rounded-2xl flex items-center justify-center">
                        <i data-lucide="map-pin" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold outfit text-slate-800">Tarif Pengiriman (Zona)</h2>
                        <p class="text-xs text-slate-400">Berdasarkan lokasi tujuan pengiriman.</p>
                    </div>
                </div>
                <button type="button" onclick="addZone()" class="px-4 py-2 bg-slate-800 text-white rounded-xl text-xs font-bold hover:bg-slate-900 transition-all flex items-center gap-2">
                    <i data-lucide="plus" class="w-4 h-4"></i> Tambah Zona
                </button>
            </div>

            <div id="zones-container" class="space-y-6">
                <?php foreach ($zones as $zi => $zone): ?>
                <div class="p-6 bg-slate-50/50 rounded-3xl border border-slate-100 space-y-6">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">ZONA #<?php echo $zi + 1; ?></span>
                        <button type="button" onclick="this.closest('.bg-slate-50\/50').remove()" class="text-red-400 hover:text-red-600 transition-colors">
                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                        </button>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 mb-2" for="f_zones_____________________label">Nama Label</label>
                            <input id="f_zones_____________________label" type="text" name="zones[<?php echo e($zi); ?>][label]" value="<?php echo htmlspecialchars($zone['label']); ?>" class="w-full px-4 py-3 rounded-xl bg-white border border-slate-200 outline-none text-sm font-bold">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 mb-2" for="f_zones_____________________cost">Biaya (IDR)</label>
                            <input id="f_zones_____________________cost" type="number" name="zones[<?php echo e($zi); ?>][cost]" value="<?php echo e($zone['cost']); ?>" class="w-full px-4 py-3 rounded-xl bg-white border border-slate-200 outline-none text-sm font-bold">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 mb-2" for="f_zones_____________________is_default">Is Default?</label>
                            <select id="f_zones_____________________is_default" name="zones[<?php echo e($zi); ?>][is_default]" class="w-full px-4 py-3 rounded-xl bg-white border border-slate-200 outline-none text-sm cursor-pointer font-medium">
                                <option value="0" <?php echo e(empty($zone['is_default']) ? 'selected' : ''); ?>>Tidak</option>
                                <option value="1" <?php echo e(!empty($zone['is_default']) ? 'selected' : ''); ?>>Ya</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-3 uppercase tracking-wider">Cakupan Provinsi</label>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-2 max-h-40 overflow-y-auto pr-1">
                            <?php foreach ($all_provinces as $prov): ?>
                            <label class="flex items-center gap-2 text-[10px] font-bold text-slate-600 cursor-pointer p-2 rounded-lg hover:bg-white border border-transparent hover:border-slate-100 transition-all">
                                <input type="checkbox" name="zones[<?php echo e($zi); ?>][provinces][]" value="<?php echo e($prov); ?>" <?php echo e(in_array($prov, $zone['provinces'] ?? []) ? 'checked' : ''); ?> class="accent-blue-600">
                                <?php echo e($prov); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        </div>

        <div class="settings-tab-content tab-integration space-y-8 hidden">
            <!-- Cadangan Basis Data -->
            <?php require_once __DIR__ . '/../includes/backup_lib.php'; $daftar_cadangan = backup_daftar(); ?>
            <div class="glass p-10 rounded-[3rem] shadow-sm border border-white">
                <div class="flex items-center gap-4 mb-8">
                    <div class="w-12 h-12 bg-emerald-100 text-emerald-600 rounded-2xl flex items-center justify-center">
                        <i data-lucide="database-backup" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold outfit text-slate-800">Cadangan Basis Data</h2>
                        <p class="text-xs text-slate-400 mt-1">Unduh salinan seluruh basis data, atau jadwalkan agar tersimpan otomatis di server.</p>
                    </div>
                </div>

                <div class="flex items-start gap-3 p-5 rounded-2xl bg-amber-50 border border-amber-200 mb-8">
                    <i data-lucide="shield-alert" class="w-5 h-5 text-amber-600 shrink-0 mt-0.5"></i>
                    <p class="text-xs text-amber-800 leading-relaxed">
                        <span class="font-bold block mb-1">Berkas cadangan setara kunci induk.</span>
                        Di dalamnya ada hash kata sandi, e-mail, nomor telepon, dan alamat rumah setiap alumni.
                        Simpan seperti Anda menyimpan kata sandi, dan jangan pernah menaruhnya di folder yang bisa dibuka publik.
                        Setiap pengunduhan tercatat di Audit Trail.
                    </p>
                </div>

                <!-- Formulir terpisah: unduhan mengalirkan berkas, jadi tidak
                     boleh menumpang formulir Simpan Pengaturan. -->
                <form action="handlers/admin_backup.php" method="POST" class="mb-8">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="inline-flex items-center gap-2 px-8 py-4 bg-slate-900 text-white rounded-2xl font-bold text-sm shadow-lg hover:bg-black transition-all active:scale-95">
                        <i data-lucide="download" class="w-4 h-4"></i> Unduh cadangan sekarang
                    </button>
                    <span class="text-xs text-slate-400 ml-3">Format .sql.gz &mdash; dapat diimpor lewat phpMyAdmin.</span>
                </form>

                <h3 class="text-sm font-black text-slate-700 uppercase tracking-wider mb-3">Cadangan otomatis tersimpan</h3>
                <?php if (empty($daftar_cadangan)): ?>
                    <div class="flex items-start gap-3 p-5 rounded-2xl bg-slate-50 border border-slate-200">
                        <i data-lucide="info" class="w-5 h-5 text-slate-400 shrink-0 mt-0.5"></i>
                        <p class="text-xs text-slate-500 leading-relaxed">
                            Belum ada cadangan otomatis. Jadwalkan pekerjaan
                            <span class="font-bold">Cadangan Basis Data</span> di bagian Tugas Terjadwal di bawah,
                            atau unduh manual dengan tombol di atas.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto rounded-2xl border border-slate-100">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
                                <tr>
                                    <th class="px-4 py-3 text-left font-bold">Berkas</th>
                                    <th class="px-4 py-3 text-left font-bold">Ukuran</th>
                                    <th class="px-4 py-3 text-left font-bold">Dibuat</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($daftar_cadangan as $bk): ?>
                                <tr>
                                    <td class="px-4 py-3 font-mono text-xs text-slate-600"><?php echo htmlspecialchars($bk['nama']); ?></td>
                                    <td class="px-4 py-3 text-slate-500"><?php echo number_format($bk['byte'] / 1024, 1); ?> KB</td>
                                    <td class="px-4 py-3 text-slate-500"><?php echo date('d M Y, H:i', $bk['waktu']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-xs text-slate-400 mt-3 leading-relaxed">
                        Disimpan di <span class="font-mono">backups/</span> pada server ini dan tidak dapat diunduh lewat peramban.
                        Ambil berkasnya lewat FTP. Menyimpan <?php echo setting_int('backup_keep', 7, 1); ?> berkas terbaru;
                        yang lebih lama dibuang otomatis.
                    </p>
                <?php endif; ?>

                <div class="flex items-start gap-3 p-5 rounded-2xl bg-blue-50 border border-blue-100 mt-6">
                    <i data-lucide="info" class="w-5 h-5 text-blue-500 shrink-0 mt-0.5"></i>
                    <p class="text-xs text-blue-700 leading-relaxed">
                        <span class="font-bold block mb-1">Cadangan di server hanya separuh perlindungan.</span>
                        Ia menyelamatkan Anda dari kesalahan manusia &mdash; impor yang keliru, penghapusan tak sengaja &mdash;
                        dan itulah yang paling sering terjadi. Tetapi ia tersimpan di server yang sama dengan basis datanya,
                        jadi tidak menolong bila servernya sendiri hilang. Unduh berkasnya secara berkala ke komputer Anda.
                    </p>
                </div>
            </div>

            <!-- Tugas Terjadwal (Cron) -->
            <div class="glass p-10 rounded-[3rem] shadow-sm border border-white">
                <div class="flex items-center gap-4 mb-8">
                    <div class="w-12 h-12 bg-indigo-100 text-indigo-600 rounded-2xl flex items-center justify-center">
                        <i data-lucide="clock" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold outfit text-slate-800">Tugas Terjadwal (Cron)</h2>
                        <p class="text-xs text-slate-400 mt-1">Tiga pekerjaan latar yang perlu dijalankan berkala. Salin URL di bawah ke menu Cron Job di cPanel.</p>
                    </div>
                </div>

                <?php
                    // Antrean e-mail yang tidak pernah diproses tidak memberi
                    // tanda apa pun di layar sebelumnya: broadcast sekadar
                    // tidak sampai, tanpa ada yang tahu.
                    $q_antrean = [];
                    try {
                        foreach ($pdo->query("SELECT status, COUNT(*) n FROM email_queue GROUP BY status") as $qr) {
                            $q_antrean[$qr->status] = (int)$qr->n;
                        }
                        $tertua = $pdo->query("SELECT MIN(created_at) FROM email_queue WHERE status IN ('pending','processing')")->fetchColumn();
                    } catch (PDOException $e) { $tertua = null; }
                    $menunggu = ($q_antrean['pending'] ?? 0) + ($q_antrean['processing'] ?? 0);
                    $umur_antrean = $tertua ? (int)floor((time() - strtotime($tertua)) / 86400) : 0;
                ?>

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                    <div class="p-5 rounded-2xl <?php echo $umur_antrean > 1 ? 'bg-red-50 border border-red-100' : 'bg-slate-50 border border-slate-100'; ?>">
                        <div class="text-2xl font-black outfit <?php echo $umur_antrean > 1 ? 'text-red-600' : 'text-slate-700'; ?>"><?php echo e($menunggu); ?></div>
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mt-1">E-mail menunggu</div>
                    </div>
                    <div class="p-5 rounded-2xl bg-slate-50 border border-slate-100">
                        <div class="text-2xl font-black outfit text-slate-700"><?php echo e($q_antrean['sent'] ?? 0); ?></div>
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mt-1">Terkirim</div>
                    </div>
                    <div class="p-5 rounded-2xl <?php echo e(($q_antrean['failed'] ?? 0) > 0 ? 'bg-amber-50 border border-amber-100' : 'bg-slate-50 border border-slate-100'); ?>">
                        <div class="text-2xl font-black outfit <?php echo e(($q_antrean['failed'] ?? 0) > 0 ? 'text-amber-600' : 'text-slate-700'); ?>"><?php echo e($q_antrean['failed'] ?? 0); ?></div>
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mt-1">Gagal</div>
                    </div>
                    <div class="p-5 rounded-2xl bg-slate-50 border border-slate-100">
                        <div class="text-2xl font-black outfit text-slate-700"><?php echo $tertua ? $umur_antrean . ' hr' : '&mdash;'; ?></div>
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mt-1">Antrean tertua</div>
                    </div>
                </div>

                <?php if ($umur_antrean > 1): ?>
                <div class="flex items-start gap-3 p-5 rounded-2xl bg-red-50 border border-red-200 mb-8">
                    <i data-lucide="alert-circle" class="w-5 h-5 text-red-600 shrink-0 mt-0.5"></i>
                    <p class="text-sm text-red-700 leading-relaxed">
                        Ada e-mail yang sudah menunggu <?php echo e($umur_antrean); ?> hari. Itu berarti pekerjaan
                        <span class="font-bold">Antrean E-mail</span> di bawah belum dijadwalkan di server, sehingga
                        broadcast tidak pernah sampai ke penerimanya.
                    </p>
                </div>
                <?php endif; ?>

                <?php
                    require_once __DIR__ . '/../includes/cron_auth.php';
                    $daftar_cron = [
                        ['process_email_queue.php', 'Antrean E-mail',    'Mengirim e-mail broadcast yang mengantre.',                'Setiap 5 menit'],
                        ['geocoder.php',            'Peta Persebaran',   'Menerjemahkan alamat alumni menjadi titik koordinat.',      'Setiap 30 menit'],
                        ['tracer_reminder.php',     'Pengingat Tracer',  'Mengingatkan alumni memperbarui data tracer study.',        'Sebulan sekali'],
                        ['backup.php',              'Cadangan Basis Data', 'Menyimpan dump ke folder backups/ dan membuang yang lama.', 'Sehari sekali'],
                    ];
                ?>
                <div class="space-y-4">
                    <?php foreach ($daftar_cron as [$berkas, $judul, $ket, $jadwal]): ?>
                    <div class="p-5 rounded-2xl bg-slate-50 border border-slate-100">
                        <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
                            <div>
                                <h3 class="text-sm font-black text-slate-700"><?php echo e($judul); ?></h3>
                                <p class="text-xs text-slate-400 mt-0.5"><?php echo e($ket); ?></p>
                            </div>
                            <span class="px-3 py-1 rounded-lg bg-white border border-slate-200 text-[10px] font-bold text-slate-500 uppercase tracking-wider shrink-0"><?php echo e($jadwal); ?></span>
                        </div>
                        <div class="flex gap-2">
                            <input type="text" readonly value="<?php echo htmlspecialchars(cron_url($berkas, $pdo)); ?>"
                                   aria-label="URL cron <?php echo e($judul); ?>"
                                   class="flex-1 px-4 py-2.5 rounded-xl bg-white border border-slate-200 text-xs font-mono text-slate-600 outline-none">
                            <button type="button" onclick="salinCron(this)" class="px-4 py-2.5 rounded-xl bg-slate-900 text-white text-xs font-bold hover:bg-black transition-all shrink-0">
                                Salin
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="flex items-start gap-3 p-5 rounded-2xl bg-amber-50 border border-amber-200 mt-6">
                    <i data-lucide="shield-alert" class="w-5 h-5 text-amber-600 shrink-0 mt-0.5"></i>
                    <p class="text-xs text-amber-800 leading-relaxed">
                        <span class="font-bold block mb-1">Perlakukan URL ini seperti kata sandi.</span>
                        Siapa pun yang memilikinya dapat memicu pengiriman e-mail ke seluruh alumni.
                        Token dibuat acak oleh sistem dan tersimpan di basis data &mdash; sebelumnya
                        <span class="font-semibold">Pengingat Tracer</span> memakai token yang tertulis di dalam kode
                        dan <span class="font-semibold">Peta Persebaran</span> sama sekali tidak berkunci.
                    </p>
                </div>
            </div>

            <!-- 6. Integrasi Google OAuth 2.0 (SSO) -->
            <div class="glass p-10 rounded-[3rem] shadow-sm border border-white">
                <div class="flex items-center gap-4 mb-8">
                <div class="w-12 h-12 bg-rose-100 text-rose-600 rounded-2xl flex items-center justify-center">
                    <i data-lucide="key" class="w-6 h-6"></i>
                </div>
                <div>
                    <h2 class="text-xl font-bold outfit text-slate-800">Otentikasi Google (SSO)</h2>
                    <p class="text-xs text-slate-400 mt-1">Konfigurasi untuk fitur "Masuk dengan Google". Dapatkan kredensial di Google Cloud Console.</p>
                </div>
            </div>
            <div class="grid grid-cols-1 gap-6">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_google_client_id">Client ID</label>
                    <input id="f_google_client_id" type="text" name="google_client_id" value="<?php echo e($settings['google_client_id'] ?? ''); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none font-mono text-xs" placeholder="xxx.apps.googleusercontent.com">
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_google_client_secret">Client Secret</label>
                    <input id="f_google_client_secret" type="password" name="google_client_secret" value="<?php echo e($settings['google_client_secret'] ?? ''); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none font-mono text-xs" placeholder="GOCSPX-xxx">
                </div>
                <div class="flex items-center justify-between p-5 bg-slate-50/50 border border-slate-100 rounded-2xl mt-2">
                    <div class="flex flex-col pr-4">
                        <span class="text-sm font-bold text-slate-800">Otomatis Verifikasi Alumni Baru (Google OAuth)</span>
                        <span class="text-[11px] text-slate-400 mt-1 leading-relaxed">Jika aktif, pendaftar baru yang masuk pertama kali menggunakan Google SSO akan langsung berstatus Terverifikasi secara otomatis. Akun lama yang masih tertunda tidak akan terpengaruh.</span>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer select-none shrink-0">
                        <input type="checkbox" name="google_oauth_auto_verify" value="1" <?php echo e(($settings['google_oauth_auto_verify'] ?? '0') == '1' ? 'checked' : ''); ?> class="sr-only peer">
                        <div class="w-14 h-8 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-6 after:content-[''] after:absolute after:top-[4px] after:left-[4px] after:bg-white after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-rose-500"></div>
                    </label>
                </div>
            </div>
            <div class="mt-4 p-4 bg-blue-50 border border-blue-100 rounded-2xl flex items-start gap-3">
                <i data-lucide="info" class="w-5 h-5 text-blue-500 shrink-0 mt-0.5"></i>
                <div class="text-xs text-blue-700">
                    <span class="font-bold block mb-1">Pengaturan URI Pengalihan (Redirect URI):</span>
                    Pastikan Anda mendaftarkan URL berikut ini pada setelan Google Cloud Console:<br>
                    <code class="bg-white px-3 py-2 rounded-lg font-mono text-[10px] mt-2 block w-full break-all border border-blue-100 shadow-sm text-blue-800"><?php echo rtrim(BASE_URL, '/') . '/handlers/google_oauth.php'; ?></code>
                </div>
            </div>
            </div>
            <!-- 6b. Server SMTP (Pengiriman Email) -->
            <div class="glass p-10 rounded-[3rem] shadow-sm border border-white">
                <div class="flex items-center gap-4 mb-8">
                <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-2xl flex items-center justify-center">
                    <i data-lucide="mail" class="w-6 h-6"></i>
                </div>
                <div>
                    <h2 class="text-xl font-bold outfit text-slate-800">Server SMTP (Pengiriman Email)</h2>
                    <p class="text-xs text-slate-400 mt-1">Konfigurasi server SMTP untuk pengiriman email transaksional seperti tautan lupa sandi.</p>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_smtp_host">SMTP Host</label>
                    <input id="f_smtp_host" type="text" name="smtp_host" value="<?php echo htmlspecialchars($settings['smtp_host'] ?? ''); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-medium" placeholder="smtp.domain.com">
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_smtp_port">SMTP Port</label>
                    <input id="f_smtp_port" type="number" name="smtp_port" value="<?php echo htmlspecialchars($settings['smtp_port'] ?? ''); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-medium" placeholder="587">
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_smtp_user">Username SMTP (Email)</label>
                    <input id="f_smtp_user" type="text" name="smtp_user" value="<?php echo htmlspecialchars($settings['smtp_user'] ?? ''); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-medium" placeholder="user@domain.com">
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_smtp_pass">Password SMTP</label>
                    <input id="f_smtp_pass" type="password" name="smtp_pass" value="<?php echo htmlspecialchars($settings['smtp_pass'] ?? ''); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-medium" placeholder="••••••••••••">
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_smtp_secure">Protokol Keamanan</label>
                    <select id="f_smtp_secure" name="smtp_secure" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm cursor-pointer">
                        <option value="tls" <?php echo e(($settings['smtp_secure'] ?? 'tls') === 'tls' ? 'selected' : ''); ?>>TLS (Rekomendasi - Port 587)</option>
                        <option value="ssl" <?php echo e(($settings['smtp_secure'] ?? 'tls') === 'ssl' ? 'selected' : ''); ?>>SSL (Port 465)</option>
                        <option value="none" <?php echo e(($settings['smtp_secure'] ?? 'tls') === 'none' ? 'selected' : ''); ?>>Tanpa Enkripsi (Tidak Direkomendasikan)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_smtp_from_name">Nama Pengirim (From Name)</label>
                    <input id="f_smtp_from_name" type="text" name="smtp_from_name" value="<?php echo htmlspecialchars($settings['smtp_from_name'] ?? ''); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-medium" placeholder="AlumniLink FK UMS">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_smtp_from_email">Email Pengirim (From Email)</label>
                    <input id="f_smtp_from_email" type="email" name="smtp_from_email" value="<?php echo htmlspecialchars($settings['smtp_from_email'] ?? ''); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-medium" placeholder="no-reply@domain.com">
                </div>

                <!-- Toggle Force Real SMTP on Local -->
                <div class="md:col-span-2 flex items-center justify-between p-5 bg-slate-50/50 border border-slate-100 rounded-2xl mt-2">
                    <div class="flex flex-col pr-4">
                        <span class="text-sm font-bold text-slate-800">Kirim Email Riil di Lokal (SMTP Force)</span>
                        <span class="text-[11px] text-slate-400 mt-1 leading-relaxed">Jika aktif, sistem akan mengirimkan email secara riil menggunakan SMTP meskipun Anda sedang menjalankan platform di lingkungan lokal (localhost). Jika nonaktif, email hanya akan dicatat di log simulasi lokal.</span>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer select-none shrink-0">
                        <input type="checkbox" name="smtp_force_real" value="1" <?php echo e(($settings['smtp_force_real'] ?? '0') == '1' ? 'checked' : ''); ?> class="sr-only peer">
                        <div class="w-14 h-8 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-6 after:content-[''] after:absolute after:top-[4px] after:left-[4px] after:bg-white after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-blue-600"></div>
                    </label>
                </div>

                <!-- Toggle Direct Send -->
                <div class="md:col-span-2 flex items-center justify-between p-5 bg-slate-50/50 border border-slate-100 rounded-2xl mt-2">
                    <div class="flex flex-col pr-4">
                        <span class="text-sm font-bold text-slate-800">Kirim Email Broadcast Langsung (Bypass Antrean)</span>
                        <span class="text-[11px] text-slate-400 mt-1 leading-relaxed">Jika aktif, sistem akan langsung mengirimkan email broadcast via SMTP pada saat tombol kirim ditekan (tanpa antrean cron). Berguna untuk pengujian langsung. Matikan kembali untuk performa produksi yang optimal.</span>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer select-none shrink-0">
                        <input type="checkbox" name="email_send_direct" value="1" <?php echo e(($settings['email_send_direct'] ?? '0') == '1' ? 'checked' : ''); ?> class="sr-only peer">
                        <div class="w-14 h-8 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-6 after:content-[''] after:absolute after:top-[4px] after:left-[4px] after:bg-white after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-blue-600"></div>
                    </label>
                </div>
            </div>
            </div>

        </div>

        <div class="settings-tab-content tab-documents space-y-8 hidden">
            <!-- 4. Konfigurasi Legalisir Digital -->
            <div class="glass p-10 rounded-[3rem] shadow-sm border border-white">
                <div class="flex items-center gap-4 mb-8">
                <div class="w-12 h-12 bg-emerald-100 text-emerald-600 rounded-2xl flex items-center justify-center">
                    <i data-lucide="shield-check" class="w-6 h-6"></i>
                </div>
                <h2 class="text-xl font-bold outfit text-slate-800">Legalisir Digital</h2>
            </div>
            <div class="space-y-6">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_digital_stamp_watermark">Teks Watermark</label>
                    <input id="f_digital_stamp_watermark" type="text" name="digital_stamp_watermark" value="<?php echo e($settings['digital_stamp_watermark'] ?? 'ALUMNILINK VERIFIED'); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-medium">
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1" for="f_digital_stamp_text">Teks Stempel</label>
                    <textarea id="f_digital_stamp_text" name="digital_stamp_text" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm h-24 font-medium"><?php echo e($settings['digital_stamp_text'] ?? ''); ?></textarea>
                </div>
            </div>
            </div>

            <!-- 8. Jenis Dokumen -->
            <div class="glass p-10 rounded-[3rem] shadow-sm border border-white">
            <div class="flex items-center justify-between mb-8">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-slate-100 text-slate-600 rounded-2xl flex items-center justify-center">
                        <i data-lucide="files" class="w-6 h-6"></i>
                    </div>
                    <h2 class="text-xl font-bold outfit text-slate-800">Jenis Dokumen</h2>
                </div>
                <button type="button" onclick="addDocType()" class="px-4 py-2 bg-slate-800 text-white rounded-xl text-xs font-bold hover:bg-slate-900 transition-all flex items-center gap-2">
                    <i data-lucide="plus" class="w-4 h-4"></i> Tambah Dokumen
                </button>
            </div>
            <div id="docTypesContainer" class="space-y-4"></div>
            <input type="hidden" name="legalisir_document_types" id="legalisir_document_types_input">
            </div>

            <!-- 10. MANAJEMEN SIDEBAR PER ROLE (Form Terpisah)                     -->
            <div class="mt-8">
                <div class="overflow-hidden rounded-[3rem] border-2 border-slate-900 shadow-2xl shadow-slate-900/10">
            <!-- Header Section -->
            <div class="bg-slate-900 px-10 py-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-white/10 rounded-2xl flex items-center justify-center">
                        <i data-lucide="layout-list" class="w-6 h-6 text-white"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-black outfit text-white">Manajemen Sidebar per Role</h2>
                        <p class="text-slate-400 text-xs mt-0.5">Atur menu yang terlihat untuk setiap role. <span class="text-blue-400 font-bold">Super Admin</span> selalu mendapat akses penuh.</p>
                    </div>
                </div>
            </div>

            <!-- Role Tabs -->
            <div class="bg-slate-800 px-10 py-4 flex gap-2 flex-wrap border-b border-slate-700">
                <?php foreach ($roles_meta as $role_key => $role_info):
                    $c = $color_map[$role_info['color']];
                    $active_count = count($saved_perms[$role_key] ?? []);
                    $total_count  = count($all_menus_flat);
                ?>
                <button type="button"
                    onclick="switchTab('<?php echo e($role_key); ?>')"
                    id="tab-<?php echo e($role_key); ?>"
                    class="sidebar-role-tab flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold transition-all <?php echo $role_key === 'alumni' ? 'bg-white text-slate-800 shadow-md' : 'text-slate-400 hover:text-white hover:bg-slate-700'; ?>">
                    <i data-lucide="<?php echo e($role_info['icon']); ?>" class="w-4 h-4"></i>
                    <?php echo e($role_info['label']); ?>
                    <span id="badge-<?php echo e($role_key); ?>" class="ml-1 bg-blue-600 text-white text-[10px] font-black px-2 py-0.5 rounded-full min-w-[20px] text-center">
                        <?php echo e($active_count); ?>
                    </span>
                </button>
                <?php endforeach; ?>
            </div>

            <!-- Panels per Role -->
            <div id="sidebar-panels">

                <?php foreach ($roles_meta as $role_key => $role_info):
                    $c       = $color_map[$role_info['color']];
                    $allowed = $saved_perms[$role_key] ?? [];
                ?>
                <div id="panel-<?php echo e($role_key); ?>"
                     class="sidebar-role-panel bg-white px-8 md:px-10 py-8 <?php echo $role_key !== 'alumni' ? 'hidden' : ''; ?>">

                    <!-- Panel Header -->
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 <?php echo e($c['bg']); ?> <?php echo e($c['text']); ?> rounded-xl flex items-center justify-center">
                                <i data-lucide="<?php echo e($role_info['icon']); ?>" class="w-5 h-5"></i>
                            </div>
                            <div>
                                <h3 class="font-black text-slate-800 outfit"><?php echo e($role_info['label']); ?></h3>
                                <p class="text-xs text-slate-400 font-medium">
                                    <span id="count-<?php echo e($role_key); ?>" class="font-black <?php echo e($c['text']); ?>"><?php echo count($allowed); ?></span>
                                    dari <?php echo count($all_menus_flat); ?> menu aktif
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <button type="button"
                                onclick="selectAll('<?php echo e($role_key); ?>')"
                                class="flex items-center gap-1.5 px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold transition-all">
                                <i data-lucide="check-square" class="w-3.5 h-3.5"></i> Pilih Semua
                            </button>
                            <button type="button"
                                onclick="resetDefault('<?php echo e($role_key); ?>', <?php echo htmlspecialchars(json_encode($default_perms[$role_key])); ?>)"
                                class="flex items-center gap-1.5 px-4 py-2 rounded-xl bg-amber-50 hover:bg-amber-100 text-amber-700 text-xs font-bold transition-all <?php echo e($c['border']); ?> border">
                                <i data-lucide="refresh-ccw" class="w-3.5 h-3.5"></i> Reset Default
                            </button>
                        </div>
                    </div>

                    <!-- Menu Grid: Main Menu -->
                    <div class="mb-6">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4 flex items-center gap-2">
                            <i data-lucide="layout-grid" class="w-3.5 h-3.5"></i> Main Menu
                        </p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            <?php foreach ($all_menus_admin['main'] as $menu): ?>
                            <label class="menu-toggle-card flex items-center gap-3 p-4 rounded-2xl border-2 cursor-pointer transition-all
                                <?php echo e(in_array($menu['key'], $allowed) ? 'border-blue-500 bg-blue-50' : 'border-slate-100 bg-slate-50 hover:border-slate-200'); ?>"
                                id="card-<?php echo e($role_key); ?>-<?php echo e($menu['key']); ?>">
                                <input type="checkbox"
                                    name="menus[<?php echo e($role_key); ?>][]"
                                    value="<?php echo e($menu['key']); ?>"
                                    class="<?php echo e($c['check']); ?> w-4.5 h-4.5 rounded-md cursor-pointer"
                                    <?php echo e(in_array($menu['key'], $allowed) ? 'checked' : ''); ?>
                                    onchange="updateCard(this, '<?php echo e($role_key); ?>')">
                                <div class="flex items-center gap-2.5 flex-1 min-w-0">
                                    <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center shadow-sm border border-slate-100 shrink-0">
                                        <i data-lucide="<?php echo e($menu['icon']); ?>" class="w-4 h-4 text-slate-500"></i>
                                    </div>
                                    <span class="text-sm font-bold text-slate-700 truncate"><?php echo htmlspecialchars($menu['label']); ?></span>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Menu Grid: Administrator -->
                    <div>
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4 flex items-center gap-2">
                            <i data-lucide="shield" class="w-3.5 h-3.5"></i> Fitur Administrator
                        </p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            <?php foreach ($all_menus_admin['admin'] as $menu): ?>
                            <label class="menu-toggle-card flex items-center gap-3 p-4 rounded-2xl border-2 cursor-pointer transition-all
                                <?php echo e(in_array($menu['key'], $allowed) ? 'border-blue-500 bg-blue-50' : 'border-slate-100 bg-slate-50 hover:border-slate-200'); ?>"
                                id="card-<?php echo e($role_key); ?>-<?php echo e($menu['key']); ?>">
                                <input type="checkbox"
                                    name="menus[<?php echo e($role_key); ?>][]"
                                    value="<?php echo e($menu['key']); ?>"
                                    class="<?php echo e($c['check']); ?> w-4.5 h-4.5 rounded-md cursor-pointer"
                                    <?php echo e(in_array($menu['key'], $allowed) ? 'checked' : ''); ?>
                                    onchange="updateCard(this, '<?php echo e($role_key); ?>')">
                                <div class="flex items-center gap-2.5 flex-1 min-w-0">
                                    <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center shadow-sm border border-slate-100 shrink-0">
                                        <i data-lucide="<?php echo e($menu['icon']); ?>" class="w-4 h-4 text-slate-500"></i>
                                    </div>
                                    <span class="text-sm font-bold text-slate-700 truncate"><?php echo htmlspecialchars($menu['label']); ?></span>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

            </div>
        </div>
        </div>

        </div>

        <!-- ══════════════════════════════════════════════════════════════
             TAB: ATURAN & KEAMANAN
             Berisi nilai-nilai yang sebelumnya ter-hardcode di dalam kode.
             Seluruh nilai bawaan sama persis dengan perilaku sebelumnya.
             ══════════════════════════════════════════════════════════════ -->
        <div class="settings-tab-content tab-rules space-y-8 hidden">

            <!-- ── Aturan Layanan ───────────────────────────────────── -->
            <div class="glass p-8 md:p-10 rounded-[2.5rem] border border-white shadow-sm">
                <div class="flex items-center gap-4 mb-8">
                    <div class="w-12 h-12 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center border border-blue-100">
                        <i data-lucide="scroll-text" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-black outfit text-slate-800">Aturan Layanan</h3>
                        <p class="text-xs text-slate-400 font-medium mt-0.5">Kebijakan institusi yang mengatur syarat akses layanan alumni.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="set_tracer_validity" class="block text-sm font-bold text-slate-700 mb-2">Masa Berlaku Tracer (bulan)</label>
                        <p class="text-xs text-slate-400 mb-3 leading-relaxed">Alumni wajib memperbarui tracer bila pengisian terakhirnya lebih lama dari ini, sebelum dapat mengajukan legalisir atau mendaftar event.</p>
                        <input id="set_tracer_validity" type="number" min="1" name="tracer_validity_months" value="<?php echo htmlspecialchars($settings['tracer_validity_months'] ?? '6'); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-medium">
                    </div>
                    <div>
                        <label for="set_tracer_reminder" class="block text-sm font-bold text-slate-700 mb-2">Ambang Pengingat Tracer (tahun)</label>
                        <p class="text-xs text-slate-400 mb-3 leading-relaxed">Alumni yang belum mengisi tracer melebihi durasi ini akan menerima e-mail pengingat otomatis dari penjadwal.</p>
                        <input id="set_tracer_reminder" type="number" min="1" name="tracer_reminder_years" value="<?php echo htmlspecialchars($settings['tracer_reminder_years'] ?? '3'); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-medium">
                    </div>
                    <div>
                        <label for="set_default_major" class="block text-sm font-bold text-slate-700 mb-2">Kode Prodi Cadangan</label>
                        <p class="text-xs text-slate-400 mb-3 leading-relaxed">Dipakai bila program studi seorang alumni tidak dapat dikenali sistem, misalnya pada data lama.</p>
                        <input id="set_default_major" type="text" name="default_major_code" value="<?php echo htmlspecialchars($settings['default_major_code'] ?? 'J500'); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-mono">
                    </div>
                    <div>
                        <label for="set_pagination" class="block text-sm font-bold text-slate-700 mb-2">Baris per Halaman</label>
                        <p class="text-xs text-slate-400 mb-3 leading-relaxed">Jumlah baris yang ditampilkan pada tabel-tabel admin seperti Audit Trail.</p>
                        <input id="set_pagination" type="number" min="5" name="pagination_size" value="<?php echo htmlspecialchars($settings['pagination_size'] ?? '20'); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-medium">
                    </div>
                </div>
            </div>

            <!-- ── Keamanan Akun ────────────────────────────────────── -->
            <div class="glass p-8 md:p-10 rounded-[2.5rem] border border-white shadow-sm">
                <div class="flex items-center gap-4 mb-8">
                    <div class="w-12 h-12 bg-red-50 text-red-600 rounded-2xl flex items-center justify-center border border-red-100">
                        <i data-lucide="shield-check" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-black outfit text-slate-800">Keamanan Akun</h3>
                        <p class="text-xs text-slate-400 font-medium mt-0.5">Kebijakan kata sandi, tautan pemulihan, dan masa aktif sesi.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label for="set_pwd_min" class="block text-sm font-bold text-slate-700 mb-2">Panjang Minimal Kata Sandi</label>
                        <p class="text-xs text-slate-400 mb-3 leading-relaxed">Standar keamanan menyarankan minimal 8 karakter.</p>
                        <input id="set_pwd_min" type="number" min="4" name="password_min_length" value="<?php echo htmlspecialchars($settings['password_min_length'] ?? '6'); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-medium">
                    </div>
                    <div>
                        <label for="set_reset_exp" class="block text-sm font-bold text-slate-700 mb-2">Masa Berlaku Tautan Reset (menit)</label>
                        <p class="text-xs text-slate-400 mb-3 leading-relaxed">Tautan atur ulang kata sandi kedaluwarsa setelah durasi ini.</p>
                        <input id="set_reset_exp" type="number" min="1" name="reset_token_expiry_minutes" value="<?php echo htmlspecialchars($settings['reset_token_expiry_minutes'] ?? '60'); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-medium">
                    </div>
                    <div>
                        <label for="set_session_timeout" class="block text-sm font-bold text-slate-700 mb-2">Keluar Otomatis (menit)</label>
                        <p class="text-xs text-slate-400 mb-3 leading-relaxed">Pengguna dikeluarkan setelah tidak beraktivitas selama ini. Isi <strong>0</strong> untuk mematikan.</p>
                        <input id="set_session_timeout" type="number" min="0" name="session_timeout_minutes" value="<?php echo htmlspecialchars($settings['session_timeout_minutes'] ?? '0'); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-medium">
                    </div>
                </div>
            </div>

            <!-- ── Pembatasan Laju (anti brute force) ───────────────── -->
            <div class="glass p-8 md:p-10 rounded-[2.5rem] border border-white shadow-sm">
                <div class="flex items-center gap-4 mb-8">
                    <div class="w-12 h-12 bg-amber-50 text-amber-600 rounded-2xl flex items-center justify-center border border-amber-100">
                        <i data-lucide="gauge" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-black outfit text-slate-800">Pembatasan Laju Permintaan</h3>
                        <p class="text-xs text-slate-400 font-medium mt-0.5">Berapa kali sebuah tindakan boleh dilakukan dari satu alamat IP dalam rentang waktu tertentu.</p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Ambang pembatasan laju per jenis tindakan</caption>
                        <thead>
                            <tr class="text-left border-b border-slate-100">
                                <th scope="col" class="pb-3 text-xs font-black uppercase tracking-widest text-slate-400">Tindakan</th>
                                <th scope="col" class="pb-3 text-xs font-black uppercase tracking-widest text-slate-400 w-40">Maks. Percobaan</th>
                                <th scope="col" class="pb-3 text-xs font-black uppercase tracking-widest text-slate-400 w-40">Rentang (menit)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                        <?php
                        $rate_actions = [
                            'login'             => ['Masuk (login)', 5, 15],
                            'register'          => ['Pendaftaran akun', 5, 15],
                            'forgot_password'   => ['Lupa kata sandi', 3, 15],
                            'reset_password'    => ['Atur ulang kata sandi', 5, 15],
                            'request_legalisir' => ['Pengajuan legalisir', 10, 15],
                            'donation'          => ['Donasi', 10, 15],
                            'submit_tracer'     => ['Pengisian tracer', 10, 15],
                            'update_profile'    => ['Perubahan profil', 10, 15],
                            'email_blast'       => ['Email blast', 2, 30],
                        ];
                        foreach ($rate_actions as $slug => $info):
                            [$label, $defMax, $defWin] = $info;
                        ?>
                            <tr>
                                <td class="py-3 pr-4">
                                    <label for="rl_<?php echo e($slug); ?>_max" class="font-bold text-slate-700"><?php echo e($label); ?></label>
                                </td>
                                <td class="py-3 pr-3">
                                    <input id="rl_<?php echo e($slug); ?>_max" type="number" min="1"
                                           name="rate_limit_<?php echo e($slug); ?>_max"
                                           value="<?php echo htmlspecialchars($settings['rate_limit_' . $slug . '_max'] ?? (string)$defMax); ?>"
                                           class="w-full px-4 py-2.5 rounded-xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm">
                                </td>
                                <td class="py-3">
                                    <label for="rl_<?php echo e($slug); ?>_window" class="sr-only">Rentang menit untuk <?php echo e($label); ?></label>
                                    <input id="rl_<?php echo e($slug); ?>_window" type="number" min="1"
                                           name="rate_limit_<?php echo e($slug); ?>_window"
                                           value="<?php echo htmlspecialchars($settings['rate_limit_' . $slug . '_window'] ?? (string)$defWin); ?>"
                                           class="w-full px-4 py-2.5 rounded-xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── Operasional & Identitas Visual ───────────────────── -->
            <div class="glass p-8 md:p-10 rounded-[2.5rem] border border-white shadow-sm">
                <div class="flex items-center gap-4 mb-8">
                    <div class="w-12 h-12 bg-emerald-50 text-emerald-600 rounded-2xl flex items-center justify-center border border-emerald-100">
                        <i data-lucide="palette" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-black outfit text-slate-800">Operasional &amp; Identitas Visual</h3>
                        <p class="text-xs text-slate-400 font-medium mt-0.5">Penjadwal latar belakang dan penyesuaian warna institusi.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="set_email_batch" class="block text-sm font-bold text-slate-700 mb-2">Batch Antrean E-mail</label>
                        <p class="text-xs text-slate-400 mb-3 leading-relaxed">Jumlah e-mail yang dikirim setiap kali penjadwal berjalan. Turunkan bila penyedia SMTP membatasi.</p>
                        <input id="set_email_batch" type="number" min="1" name="email_batch_size" value="<?php echo htmlspecialchars($settings['email_batch_size'] ?? '20'); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-medium">
                    </div>
                    <div>
                        <label for="set_email_retry" class="block text-sm font-bold text-slate-700 mb-2">Maksimal Percobaan Kirim</label>
                        <p class="text-xs text-slate-400 mb-3 leading-relaxed">Setelah gagal sebanyak ini, e-mail ditandai gagal permanen.</p>
                        <input id="set_email_retry" type="number" min="1" name="email_max_attempts" value="<?php echo htmlspecialchars($settings['email_max_attempts'] ?? '3'); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-medium">
                    </div>
                    <div>
                        <label for="set_geocoder_batch" class="block text-sm font-bold text-slate-700 mb-2">Batch Geocoding</label>
                        <p class="text-xs text-slate-400 mb-3 leading-relaxed">Jumlah alamat alumni yang dipetakan tiap kali penjadwal berjalan.</p>
                        <input id="set_geocoder_batch" type="number" min="1" name="geocoder_batch_size" value="<?php echo htmlspecialchars($settings['geocoder_batch_size'] ?? '10'); ?>" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-medium">
                    </div>
                    <div>
                        <label for="set_favicon" class="block text-sm font-bold text-slate-700 mb-2">Favicon (path berkas)</label>
                        <p class="text-xs text-slate-400 mb-3 leading-relaxed">Ikon pada tab peramban. Kosongkan untuk memakai logo sistem.</p>
                        <input id="set_favicon" type="text" name="system_favicon" value="<?php echo htmlspecialchars($settings['system_favicon'] ?? ''); ?>" placeholder="uploads/system/favicon.png" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-mono">
                    </div>
                    <div class="md:col-span-2">
                        <label for="set_brand_color" class="block text-sm font-bold text-slate-700 mb-2">Warna Utama Institusi</label>
                        <p class="text-xs text-slate-400 mb-3 leading-relaxed">Dipakai untuk indikator fokus keyboard, tombol utama pada halaman galat, dan dialog konfirmasi.</p>
                        <div class="flex items-center gap-4 flex-wrap">
                            <input id="set_brand_color" type="color" name="brand_primary_color" value="<?php echo htmlspecialchars($settings['brand_primary_color'] ?? '#2563eb'); ?>" class="w-20 h-14 rounded-2xl border border-slate-200 cursor-pointer bg-white p-1" oninput="updateContrast(this.value)">
                            <code id="brandHex" class="text-sm font-mono text-slate-500"><?php echo htmlspecialchars($settings['brand_primary_color'] ?? '#2563eb'); ?></code>

                            <?php
                            // Peringatan kontras: mencegah pemilihan warna yang membuat
                            // teks putih di atasnya tidak terbaca (WCAG 1.4.3).
                            $contrast = brand_contrast_report($settings['brand_primary_color'] ?? null);
                            ?>
                            <div id="contrastBadge" class="flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold border"
                                 role="status" aria-live="polite">
                                <span id="contrastText"></span>
                            </div>
                        </div>

                        <!-- Pratinjau keterbacaan -->
                        <div class="mt-4 flex items-center gap-3 flex-wrap">
                            <span id="contrastPreview" class="px-5 py-3 rounded-xl text-white text-sm font-bold"
                                  style="background: <?php echo htmlspecialchars($settings['brand_primary_color'] ?? '#2563eb'); ?>">
                                Contoh tombol utama
                            </span>
                            <p id="contrastNote" class="text-xs text-slate-400 leading-relaxed flex-1 min-w-[240px]"></p>
                        </div>

                        <script>
                            /**
                             * Hitung rasio kontras terhadap latar putih memakai rumus WCAG,
                             * lalu perbarui lencana dan pratinjau secara langsung.
                             * Perhitungan yang sama juga dilakukan di sisi PHP.
                             */
                            function luminance(hex) {
                                const c = hex.replace('#', '');
                                const ch = [0, 2, 4].map(i => {
                                    const v = parseInt(c.substr(i, 2), 16) / 255;
                                    return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
                                });
                                return 0.2126 * ch[0] + 0.7152 * ch[1] + 0.0722 * ch[2];
                            }

                            function updateContrast(hex) {
                                const ratio = (1.05) / (luminance(hex) + 0.05);
                                const r = Math.round(ratio * 100) / 100;

                                let level, note, cls;
                                if (r >= 4.5) {
                                    level = 'WCAG AA ✓';
                                    note  = 'Teks putih di atas warna ini memenuhi standar WCAG AA.';
                                    cls   = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                                } else if (r >= 3.0) {
                                    level = 'AA teks besar';
                                    note  = 'Hanya memenuhi standar untuk teks besar dan indikator fokus. Teks kecil berwarna putih akan sulit dibaca.';
                                    cls   = 'bg-amber-50 text-amber-700 border-amber-200';
                                } else {
                                    level = 'Kontras gagal';
                                    note  = 'Kontras terlalu rendah. Teks putih di atas warna ini sulit dibaca; pilih warna yang lebih gelap.';
                                    cls   = 'bg-red-50 text-red-700 border-red-200';
                                }

                                document.getElementById('contrastBadge').className =
                                    'flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold border ' + cls;
                                document.getElementById('contrastText').textContent = level + ' — rasio ' + r.toFixed(2) + ':1';
                                document.getElementById('contrastNote').textContent = note;
                                document.getElementById('contrastPreview').style.background = hex;
                                document.getElementById('brandHex').textContent = hex;
                            }

                            document.addEventListener('DOMContentLoaded', function () {
                                const el = document.getElementById('set_brand_color');
                                if (el) updateContrast(el.value);
                            });
                        </script>
                    </div>
                </div>
            </div>

        </div>

    <!-- Single Save Button -->
    <div class="flex justify-end pt-10 pb-4">
        <button type="submit" onclick="prepareDocTypes()" class="bg-blue-600 text-white px-12 py-6 rounded-[2.5rem] font-black outfit text-lg shadow-xl shadow-blue-200 hover:bg-blue-700 hover:scale-[1.02] transition-all active:scale-95 flex items-center gap-4">
            <i data-lucide="save" class="w-6 h-6"></i>
            Simpan Semua Perubahan
        </button>
    </div>
</form>
</div>

<script>
    // Document Types JS
    let docTypes = <?php 
        $dt = $settings['legalisir_document_types'] ?? '[{"id":"ijazah","name":"Ijazah Asli (Scan)"},{"id":"transkrip","name":"Transkrip Nilai (Scan)"}]';
        echo $dt;
    ?>;

    const majorsList = <?php echo json_encode($majors_list); ?>;

    function renderDocTypes() {
        const container = document.getElementById('docTypesContainer');
        container.innerHTML = '';
        docTypes.forEach((doc, index) => {
            const targetMajor = doc.target_major_code || '';
            const isAkred = doc.is_akreditasi ? 'checked' : '';

            let majorOptions = `<option value="">-- Semua Prodi / Umum --</option>`;
            majorsList.forEach(m => {
                const sel = (m.major_code === targetMajor) ? 'selected' : '';
                majorOptions += `<option value="${m.major_code}" ${sel}>${m.major_name}</option>`;
            });

            const row = document.createElement('div');
            row.className = 'flex gap-4 items-center group';
            row.innerHTML = `
                <div class="flex-1 grid grid-cols-1 md:grid-cols-12 gap-4 bg-slate-50 p-4 rounded-2xl border border-slate-100 group-hover:border-blue-200 transition-all items-center">
                    <div class="md:col-span-3">
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">ID / Slug</label>
                        <input aria-label="id-slug" type="text" value="${doc.id}" onchange="updateDoc(${index}, 'id', this.value)" class="w-full px-4 py-2 rounded-xl bg-white border border-slate-200 outline-none text-xs font-mono" placeholder="id-slug">
                    </div>
                    <div class="md:col-span-4">
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Nama Dokumen</label>
                        <input aria-label="Nama Dokumen" type="text" value="${doc.name}" onchange="updateDoc(${index}, 'name', this.value)" class="w-full px-4 py-2 rounded-xl bg-white border border-slate-200 outline-none text-xs font-bold" placeholder="Nama Dokumen">
                    </div>
                    <div class="md:col-span-3">
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Target Prodi</label>
                        <select onchange="updateDoc(${index}, 'target_major_code', this.value)" class="w-full px-3 py-2 rounded-xl bg-white border border-slate-200 outline-none text-xs font-bold text-slate-700 cursor-pointer truncate">
                            ${majorOptions}
                        </select>
                    </div>
                    <div class="md:col-span-2 flex items-center justify-center pt-5 md:pt-0">
                        <label class="flex items-center gap-2 text-[10px] font-bold text-emerald-600 cursor-pointer bg-emerald-50 px-3 py-2 rounded-xl border border-emerald-100 hover:bg-emerald-100 transition-all truncate">
                            <input type="checkbox" ${isAkred} onchange="updateDoc(${index}, 'is_akreditasi', this.checked)" class="accent-emerald-600 w-4 h-4 shrink-0">
                            Akreditasi
                        </label>
                    </div>
                </div>
                <button type="button" onclick="removeDoc(${index})" class="w-12 h-12 bg-red-50 text-red-500 rounded-xl flex items-center justify-center hover:bg-red-500 hover:text-white transition-all shrink-0">
                    <i data-lucide="trash-2" class="w-5 h-5"></i>
                </button>
            `;
            container.appendChild(row);
        });
        lucide.createIcons();
    }

    function addDocType() { docTypes.push({ id: '', name: '', target_major_code: '', is_akreditasi: false }); renderDocTypes(); }
    function removeDoc(index) { docTypes.splice(index, 1); renderDocTypes(); }
    function updateDoc(index, key, value) { docTypes[index][key] = value; prepareDocTypes(); }
    function prepareDocTypes() { document.getElementById('legalisir_document_types_input').value = JSON.stringify(docTypes); }

    // Data Provinsi untuk JS
    const allProvinces = <?php echo json_encode($all_provinces); ?>;

    // Shipping Zones JS
    function addZone() {
        const container = document.getElementById('zones-container');
        const index = container.children.length;
        const div = document.createElement('div');
        div.className = 'p-8 bg-slate-50/50 rounded-[2rem] border border-slate-100 space-y-6 animate-in slide-in-from-bottom-2 duration-300';
        
        // Generate Checkboxes HTML
        let provincesHtml = '';
        allProvinces.forEach(prov => {
            provincesHtml += `
                <label class="flex items-center gap-2 text-[10px] font-bold text-slate-600 cursor-pointer p-2 rounded-lg hover:bg-white border border-transparent hover:border-slate-100 transition-all">
                    <input type="checkbox" name="zones[${index}][provinces][]" value="${prov}" class="accent-blue-600">
                    ${prov}
                </label>
            `;
        });

        div.innerHTML = `
            <div class="flex items-center justify-between">
                <span class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">ZONA BARU #${index + 1}</span>
                <button type="button" onclick="this.closest('.p-8').remove()" class="text-red-400 hover:text-red-600 transition-colors">
                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                </button>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-2 uppercase tracking-wider" for="f_zones___index___label">Nama Label</label>
                    <input id="f_zones___index___label" type="text" name="zones[${index}][label]" class="w-full px-4 py-3 rounded-xl bg-white border border-slate-200 outline-none text-sm font-bold" placeholder="Contoh: Jawa Tengah">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-2 uppercase tracking-wider" for="f_zones___index___cost">Biaya (IDR)</label>
                    <input id="f_zones___index___cost" type="number" name="zones[${index}][cost]" class="w-full px-4 py-3 rounded-xl bg-white border border-slate-200 outline-none text-sm font-bold" placeholder="0">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-2 uppercase tracking-wider" for="f_zones___index___is_default">Is Default?</label>
                    <select id="f_zones___index___is_default" name="zones[${index}][is_default]" class="w-full px-4 py-3 rounded-xl bg-white border border-slate-200 outline-none text-sm cursor-pointer font-medium">
                        <option value="0">Tidak</option>
                        <option value="1">Ya</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 mb-3 uppercase tracking-wider">Pilih Cakupan Provinsi</label>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-2 max-h-40 overflow-y-auto pr-1">
                    ${provincesHtml}
                </div>
            </div>
        `;
        container.appendChild(div);
        lucide.createIcons();
    }

    renderDocTypes();

    // ── Sidebar Management JS ──────────────────────────────────────────────
    const allRoles = <?php echo json_encode(array_keys($roles_meta)); ?>;

    function switchTab(role) {
        // Sembunyikan semua panel
        document.querySelectorAll('.sidebar-role-panel').forEach(p => p.classList.add('hidden'));
        // Reset semua tab
        document.querySelectorAll('.sidebar-role-tab').forEach(t => {
            t.classList.remove('bg-white', 'text-slate-800', 'shadow-md');
            t.classList.add('text-slate-400');
        });
        // Tampilkan panel yang dipilih
        document.getElementById('panel-' + role).classList.remove('hidden');
        // Aktifkan tab yang dipilih
        const activeTab = document.getElementById('tab-' + role);
        activeTab.classList.add('bg-white', 'text-slate-800', 'shadow-md');
        activeTab.classList.remove('text-slate-400');
    }

    function updateCard(checkbox, role) {
        const card = document.getElementById('card-' + role + '-' + checkbox.value);
        if (checkbox.checked) {
            card.classList.remove('border-slate-100', 'bg-slate-50');
            card.classList.add('border-blue-500', 'bg-blue-50');
        } else {
            card.classList.remove('border-blue-500', 'bg-blue-50');
            card.classList.add('border-slate-100', 'bg-slate-50');
        }
        updateCounter(role);
    }

    function updateCounter(role) {
        const panel = document.getElementById('panel-' + role);
        const checked = panel.querySelectorAll('input[type="checkbox"]:checked').length;
        document.getElementById('count-' + role).textContent = checked;
        document.getElementById('badge-' + role).textContent = checked;
    }

    function selectAll(role) {
        const panel = document.getElementById('panel-' + role);
        panel.querySelectorAll('input[type="checkbox"]').forEach(cb => {
            cb.checked = true;
            updateCard(cb, role);
        });
        updateCounter(role);
    }

    function resetDefault(role, defaults) {
        const panel = document.getElementById('panel-' + role);
        panel.querySelectorAll('input[type="checkbox"]').forEach(cb => {
            cb.checked = defaults.includes(cb.value);
            updateCard(cb, role);
        });
        updateCounter(role);
    }

    // Loading state saat simpan setting
    document.getElementById('settings-form')?.addEventListener('submit', function() {
        const btn = this.querySelector('button[type="submit"]');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<svg class="animate-spin w-5 h-5 mr-2 inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path></svg> Menyimpan Perubahan...';
        }
    });

    // Geocoding manual trigger
    document.getElementById('btn-sync-geocoding')?.addEventListener('click', function() {
        const btn = this;
        const originalHtml = btn.innerHTML;
        
        Swal.fire({
            title: 'Sinkronisasi Koordinat Peta',
            text: 'Sistem akan mengonversi alamat alumni baru menjadi koordinat lintang/bujur via OpenStreetMap. Mohon tunggu...',
            icon: 'info',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        btn.disabled = true;
        btn.innerHTML = '<svg class="animate-spin w-4 h-4 mr-2 inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path></svg> Sinkronisasi...';
        
        fetch('api/admin/sync_geocoding.php')
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
                
                if (data.success) {
                    Swal.fire({
                        title: 'Sinkronisasi Selesai',
                        text: data.message,
                        icon: 'success',
                        confirmButtonColor: '#3b82f6'
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire({
                        title: 'Gagal',
                        text: data.error || 'Terjadi kesalahan sistem saat melakukan sinkronisasi.',
                        icon: 'error',
                        confirmButtonColor: '#ef4444'
                    });
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
                Swal.fire({
                    title: 'Kesalahan Jaringan',
                    text: 'Tidak dapat menghubungi server API.',
                    icon: 'error',
                    confirmButtonColor: '#ef4444'
                });
            });
    });

    // Tab Navigation for Settings Page
    /* Salin URL cron ke papan klip. Ditaruh di sini, bukan di atribut
       onclick sebagai satu baris panjang, agar tetap terbaca. */
    function salinCron(btn) {
        const input = btn.previousElementSibling;
        input.select();
        const selesai = () => {
            const semula = btn.textContent;
            btn.textContent = 'Tersalin';
            setTimeout(() => { btn.textContent = semula; }, 1500);
        };
        if (navigator.clipboard) {
            navigator.clipboard.writeText(input.value).then(selesai)
                .catch(() => { document.execCommand('copy'); selesai(); });
        } else {
            document.execCommand('copy');
            selesai();
        }
    }

    function switchSettingsTab(tabClass) {
        // Hide all setting tab contents
        document.querySelectorAll('.settings-tab-content').forEach(el => el.classList.add('hidden'));
        
        // Show selected tab elements
        document.querySelectorAll('.' + tabClass).forEach(el => el.classList.remove('hidden'));
        
        // Reset all tab button styles
        document.querySelectorAll('.settings-tab-btn').forEach(btn => {
            btn.classList.remove('bg-slate-900', 'text-white', 'shadow-lg');
            btn.classList.add('text-slate-500', 'hover:text-slate-800', 'hover:bg-slate-50');
        });
        
        // Set active style for selected tab button
        const activeBtn = document.getElementById('btn-' + tabClass);
        if (activeBtn) {
            activeBtn.classList.remove('text-slate-500', 'hover:text-slate-800', 'hover:bg-slate-50');
            activeBtn.classList.add('bg-slate-900', 'text-white', 'shadow-lg');
        }
    }
</script>
