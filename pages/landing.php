<?php
// Ambil data pengaturan untuk landing page
$stmt = $pdo->query("SELECT * FROM settings");
$raw_settings = $stmt->fetchAll();
$landing_settings = [];
foreach ($raw_settings as $s) {
    $landing_settings[$s->setting_key] = $s->setting_value;
}

$hero_title = $landing_settings['landing_hero_title'] ?? 'Platform Manajemen Alumni Terintegrasi';
$hero_subtitle = $landing_settings['landing_hero_subtitle'] ?? 'Portal resmi untuk layanan Tracer Alumni, Legalisir Dokumen, dan Penggalangan Dana Alumni secara digital, cepat, dan transparan.';
$cta_text = $landing_settings['landing_cta_text'] ?? 'Masuk';
$about_text = $landing_settings['landing_about'] ?? 'AlumniLink adalah platform inovatif yang dirancang khusus untuk mempererat tali silaturahmi dan mempermudah layanan administratif bagi seluruh alumni. Kami berkomitmen memberikan pengalaman terbaik melalui digitalisasi layanan.';
$show_stats = ($landing_settings['landing_show_stats'] ?? '1') == '1';
$institution_name = $landing_settings['institution_name'] ?? 'Universitas';

// Kontak
$contact_email   = $landing_settings['contact_email'] ?? '';
$contact_address = $landing_settings['contact_address'] ?? '';
$wa_tracer       = $landing_settings['wa_tracer'] ?? '';
$wa_legalisir    = $landing_settings['wa_legalisir'] ?? '';
$wa_itsupport    = $landing_settings['wa_itsupport'] ?? '';

// Ambil Statistik
if ($show_stats) {
    $total_alumni = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'alumni' AND is_verified = 1")->fetchColumn();
    $total_tracer = $pdo->query("SELECT COUNT(*) FROM tracer_submissions")->fetchColumn();
    $total_legalisir = $pdo->query("SELECT COUNT(*) FROM legalisir_requests")->fetchColumn();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Selamat Datang - AlumniLink <?php echo htmlspecialchars($institution_name); ?></title>
    <?php
    $base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
    $current_logo = !empty($landing_settings['system_logo']) ? $landing_settings['system_logo'] : 'uploads/system/logo_1778236863.png';
    ?>
    <base href="<?php echo e($base_path); ?>">
    <meta property="og:image" content="<?php echo e($current_logo); ?>">
    <link rel="icon" type="image/png" href="<?php echo e($current_logo); ?>">
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="assets/js/lucide.min.js"></script>
    <link rel="stylesheet" href="assets/fonts/fonts.css">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; scroll-behavior: smooth; }
        .outfit { font-family: 'Outfit', sans-serif; }
        .glass { background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.5); }
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

        /* Antigravity Floating Particles */
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

        /* Tech Grid Background */
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

        /* Offset scroll target agar tidak tertutup fixed navbar */
        section[id] {
            scroll-margin-top: 96px;
        }
    </style>
</head>
<body class="bg-gradient-mesh min-h-screen text-slate-800 relative overflow-x-hidden">

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

    <!-- Navigation -->
    <nav class="fixed w-full z-50 transition-all duration-300" id="navbar">
        <div class="max-w-7xl mx-auto px-4 md:px-6 py-4">
            <div class="glass rounded-full px-5 py-3 flex items-center justify-between shadow-sm">
                <!-- Logo -->
                <div class="flex items-center gap-3">
                    <img src="<?php echo htmlspecialchars(!empty($landing_settings['system_logo']) ? $landing_settings['system_logo'] : 'uploads/system/logo_1778236863.png'); ?>" alt="Logo" class="w-8 h-8 object-contain" width="32" height="32">
                    <span class="font-black outfit tracking-tighter text-lg text-slate-800">Alumni<span class="text-blue-600">Link</span></span>
                </div>

                <!-- Desktop Menu -->
                <div class="hidden md:flex items-center gap-8 text-sm font-bold text-slate-600">
                    <a href="#fitur" class="hover:text-blue-600 transition-colors">Layanan</a>
                    <a href="#berita" class="hover:text-blue-600 transition-colors">Kabar</a>
                    <a href="#tentang" class="hover:text-blue-600 transition-colors">Tentang</a>
                    <?php if($show_stats): ?>
                    <a href="#statistik" class="hover:text-blue-600 transition-colors">Statistik</a>
                    <?php endif; ?>
                    <a href="#kontak" class="hover:text-blue-600 transition-colors">Kontak</a>
                    <a href="index.php?page=terms" class="hover:text-blue-600 transition-colors">S&amp;K</a>
                </div>

                <!-- Right Side: CTA + Hamburger -->
                <div class="flex items-center gap-3">
                    <a href="index.php?page=login" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-full text-sm font-bold shadow-lg shadow-blue-500/30 transition-all hover:scale-105">
                        <?php echo htmlspecialchars($cta_text); ?>
                    </a>
                    <!-- Hamburger (mobile only) -->
                    <button id="hamburgerBtn" aria-label="Buka menu navigasi" onclick="toggleMobileMenu()"
                        class="md:hidden w-10 h-10 flex flex-col items-center justify-center gap-1.5 rounded-full bg-white/70 border border-white/80 shadow-sm hover:bg-white transition-all">
                        <span id="hb1" class="block w-5 h-0.5 bg-slate-700 rounded-full transition-all duration-300 origin-center"></span>
                        <span id="hb2" class="block w-5 h-0.5 bg-slate-700 rounded-full transition-all duration-300"></span>
                        <span id="hb3" class="block w-5 h-0.5 bg-slate-700 rounded-full transition-all duration-300 origin-center"></span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile Menu Drawer -->
        <div id="mobileMenu"
            class="md:hidden mx-4 overflow-hidden transition-all duration-300 ease-in-out"
            style="max-height: 0; opacity: 0;">
            <div class="glass rounded-[2rem] mt-2 px-6 py-6 shadow-xl border border-white/60">
                <div class="flex flex-col gap-1">
                    <a href="#fitur" onclick="closeMobileMenu()" class="flex items-center gap-3 px-4 py-3.5 rounded-2xl text-sm font-bold text-slate-700 hover:bg-blue-50 hover:text-blue-600 transition-all">
                        <i data-lucide="layout-grid" class="w-4 h-4 text-blue-400"></i> Layanan
                    </a>
                    <a href="#berita" onclick="closeMobileMenu()" class="flex items-center gap-3 px-4 py-3.5 rounded-2xl text-sm font-bold text-slate-700 hover:bg-blue-50 hover:text-blue-600 transition-all">
                        <i data-lucide="newspaper" class="w-4 h-4 text-indigo-400"></i> Kabar Alumni
                    </a>
                    <a href="#tentang" onclick="closeMobileMenu()" class="flex items-center gap-3 px-4 py-3.5 rounded-2xl text-sm font-bold text-slate-700 hover:bg-blue-50 hover:text-blue-600 transition-all">
                        <i data-lucide="info" class="w-4 h-4 text-cyan-400"></i> Tentang
                    </a>
                    <?php if($show_stats): ?>
                    <a href="#statistik" onclick="closeMobileMenu()" class="flex items-center gap-3 px-4 py-3.5 rounded-2xl text-sm font-bold text-slate-700 hover:bg-blue-50 hover:text-blue-600 transition-all">
                        <i data-lucide="bar-chart-2" class="w-4 h-4 text-emerald-400"></i> Statistik
                    </a>
                    <?php endif; ?>
                    <a href="#kontak" onclick="closeMobileMenu()" class="flex items-center gap-3 px-4 py-3.5 rounded-2xl text-sm font-bold text-slate-700 hover:bg-blue-50 hover:text-blue-600 transition-all">
                        <i data-lucide="phone" class="w-4 h-4 text-green-400"></i> Kontak
                    </a>
                    <a href="index.php?page=terms" onclick="closeMobileMenu()" class="flex items-center gap-3 px-4 py-3.5 rounded-2xl text-sm font-bold text-slate-700 hover:bg-blue-50 hover:text-blue-600 transition-all">
                        <i data-lucide="shield-check" class="w-4 h-4 text-purple-400"></i> Syarat &amp; Ketentuan
                    </a>
                    <div class="pt-3 mt-2 border-t border-slate-100">
                        <a href="index.php?page=register" class="flex items-center justify-center gap-2 w-full py-3.5 bg-blue-600 text-white rounded-2xl text-sm font-bold shadow-lg shadow-blue-500/20 hover:bg-blue-700 transition-all">
                            <i data-lucide="user-plus" class="w-4 h-4"></i> Daftar Sekarang
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </nav>


    <!-- Hero Section -->
    <section class="pt-40 pb-20 px-6 min-h-screen flex items-center">
        <div class="max-w-7xl mx-auto text-center">
            <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/60 border border-white/80 shadow-sm text-sm font-bold text-blue-600 mb-8 mx-auto">
                <span class="relative flex h-3 w-3">
                  <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span>
                  <span class="relative inline-flex rounded-full h-3 w-3 bg-blue-500"></span>
                </span>
                Ruang Temu & Layanan Alumni
            </div>
            
            <h1 class="text-5xl md:text-7xl font-black outfit text-slate-900 tracking-tight mb-8 leading-tight max-w-4xl mx-auto">
                <?php echo htmlspecialchars($hero_title); ?>
            </h1>
            
            <p class="text-lg md:text-xl text-slate-600 mb-12 max-w-2xl mx-auto font-medium leading-relaxed">
                <?php echo htmlspecialchars($hero_subtitle); ?>
            </p>
            
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                <a href="index.php?page=register" class="w-full sm:w-auto bg-blue-600 hover:bg-blue-700 text-white px-8 py-4 rounded-full text-base font-bold shadow-xl shadow-blue-500/30 transition-all hover:scale-105 flex items-center justify-center gap-2">
                    Daftar Sekarang <i data-lucide="arrow-right" class="w-5 h-5"></i>
                </a>
                <a href="index.php?page=login" class="w-full sm:w-auto bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 px-8 py-4 rounded-full text-base font-bold shadow-sm transition-all hover:scale-105 flex items-center justify-center gap-2">
                    Sudah Punya Akun?
                </a>
            </div>
        </div>
    </section>

    <?php if($show_stats): ?>
    <!-- Statistics Section -->
    <section id="statistik" class="py-20 px-6">
        <div class="max-w-7xl mx-auto">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="glass p-8 rounded-[2rem] text-center transform hover:-translate-y-2 transition-all duration-300">
                    <div class="w-16 h-16 bg-blue-100 text-blue-600 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <i data-lucide="users-2" class="w-8 h-8"></i>
                    </div>
                    <h3 class="text-4xl font-black outfit text-slate-800 mb-2"><?php echo number_format($total_alumni); ?>+</h3>
                    <p class="text-sm font-bold text-slate-500 uppercase tracking-widest">Alumni Terdaftar</p>
                </div>
                <div class="glass p-8 rounded-[2rem] text-center transform hover:-translate-y-2 transition-all duration-300">
                    <div class="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <i data-lucide="bar-chart-3" class="w-8 h-8"></i>
                    </div>
                    <h3 class="text-4xl font-black outfit text-slate-800 mb-2"><?php echo number_format($total_tracer); ?></h3>
                    <p class="text-sm font-bold text-slate-500 uppercase tracking-widest">Data Tracer Terkumpul</p>
                </div>
                <div class="glass p-8 rounded-[2rem] text-center transform hover:-translate-y-2 transition-all duration-300">
                    <div class="w-16 h-16 bg-indigo-100 text-indigo-600 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <i data-lucide="award" class="w-8 h-8"></i>
                    </div>
                    <h3 class="text-4xl font-black outfit text-slate-800 mb-2"><?php echo number_format($total_legalisir); ?></h3>
                    <p class="text-sm font-bold text-slate-500 uppercase tracking-widest">Legalisir Diproses</p>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Features Section -->
    <section id="fitur" class="py-20 px-6 relative">
        <div class="max-w-7xl mx-auto">
            <div class="text-center mb-16">
                <h2 class="text-3xl md:text-4xl font-black outfit text-slate-800 mb-4 tracking-tight">Layanan Utama</h2>
                <p class="text-slate-500 font-medium max-w-2xl mx-auto">Kami menyediakan berbagai layanan digital untuk mempermudah kebutuhan administratif Anda.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <!-- Feature 1 -->
                <div class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100 group hover:shadow-xl hover:shadow-blue-500/10 transition-all duration-500">
                    <div class="w-16 h-16 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center mb-8 group-hover:scale-110 group-hover:bg-blue-600 group-hover:text-white transition-all duration-300">
                        <i data-lucide="file-search" class="w-8 h-8"></i>
                    </div>
                    <h3 class="text-xl font-bold outfit text-slate-800 mb-4">Tracer Alumni</h3>
                    <p class="text-slate-500 text-sm leading-relaxed mb-8">Berpartisipasi dalam pendataan jejak alumni untuk membantu akreditasi dan pengembangan kurikulum institusi.</p>
                    <a href="index.php?page=login" class="inline-flex items-center gap-2 text-sm font-bold text-blue-600 hover:text-blue-700">Mulai Tracer <i data-lucide="arrow-right" class="w-4 h-4"></i></a>
                </div>

                <!-- Feature 2 -->
                <div class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100 group hover:shadow-xl hover:shadow-indigo-500/10 transition-all duration-500">
                    <div class="w-16 h-16 bg-indigo-50 text-indigo-600 rounded-2xl flex items-center justify-center mb-8 group-hover:scale-110 group-hover:bg-indigo-600 group-hover:text-white transition-all duration-300">
                        <i data-lucide="award" class="w-8 h-8"></i>
                    </div>
                    <h3 class="text-xl font-bold outfit text-slate-800 mb-4">Legalisir Digital</h3>
                    <p class="text-slate-500 text-sm leading-relaxed mb-8">Ajukan legalisir ijazah dan transkrip nilai secara online dengan verifikasi stempel digital barcode yang aman.</p>
                    <a href="index.php?page=login" class="inline-flex items-center gap-2 text-sm font-bold text-indigo-600 hover:text-indigo-700">Ajukan Legalisir <i data-lucide="arrow-right" class="w-4 h-4"></i></a>
                </div>

                <!-- Feature 3 -->
                <div class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100 group hover:shadow-xl hover:shadow-pink-500/10 transition-all duration-500">
                    <div class="w-16 h-16 bg-pink-50 text-pink-600 rounded-2xl flex items-center justify-center mb-8 group-hover:scale-110 group-hover:bg-pink-600 group-hover:text-white transition-all duration-300">
                        <i data-lucide="heart" class="w-8 h-8"></i>
                    </div>
                    <h3 class="text-xl font-bold outfit text-slate-800 mb-4">Program Donasi</h3>
                    <p class="text-slate-500 text-sm leading-relaxed mb-8">Dukung kemajuan almamater dan bantu mahasiswa yang membutuhkan melalui program donasi yang transparan.</p>
                    <a href="index.php?page=login" class="inline-flex items-center gap-2 text-sm font-bold text-pink-600 hover:text-pink-700">Lihat Program <i data-lucide="arrow-right" class="w-4 h-4"></i></a>
                </div>
            </div>
        </div>
    </section>

    <!-- News & Events Section -->
    <section id="berita" class="py-20 px-6">
        <div class="max-w-7xl mx-auto">
            <div class="flex flex-col md:flex-row md:items-end justify-between mb-16 gap-6">
                <div>
                    <h2 class="text-3xl md:text-4xl font-black outfit text-slate-800 mb-4 tracking-tight">Kabar Alumni</h2>
                    <p class="text-slate-500 font-medium max-w-xl">Ikuti terus berita terbaru, kegiatan, dan event seru dari almamater tercinta.</p>
                </div>
                <a href="index.php?page=all_news" class="text-sm font-bold text-blue-600 hover:text-blue-700 flex items-center gap-2 group">
                    Lihat Semua Berita <i data-lucide="arrow-right" class="w-4 h-4 group-hover:translate-x-1 transition-transform"></i>
                </a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <?php
                $news_stmt = $pdo->query("SELECT * FROM news_posts ORDER BY created_at DESC LIMIT 3");
                $latest_news = $news_stmt->fetchAll();
                foreach ($latest_news as $news):
                ?>
                <div class="bg-white rounded-[2.5rem] overflow-hidden border border-slate-100 shadow-sm group hover:shadow-xl hover:shadow-blue-500/5 transition-all duration-500 flex flex-col">
                    <div class="h-56 overflow-hidden relative">
                        <?php if($news->image): ?>
                            <img src="<?php echo e($news->image); ?>" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110" alt="News Image">
                        <?php else: ?>
                            <div class="w-full h-full bg-slate-50 flex items-center justify-center text-slate-200">
                                <i data-lucide="image" class="w-12 h-12"></i>
                            </div>
                        <?php endif; ?>
                        <div class="absolute top-4 left-4">
                            <span class="px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest glass text-slate-700 border-white">
                                <?php echo e($news->type); ?>
                            </span>
                        </div>
                    </div>
                    <div class="p-8 flex flex-col flex-1">
                        <span class="text-[10px] font-bold text-blue-600 uppercase tracking-[0.2em] mb-3"><?php echo date('d M Y', strtotime($news->created_at)); ?></span>
                        <h3 class="text-xl font-bold outfit text-slate-800 mb-4 group-hover:text-blue-600 transition-colors line-clamp-2"><?php echo htmlspecialchars($news->title); ?></h3>
                        <p class="text-slate-500 text-sm leading-relaxed mb-8 line-clamp-3">
                            <?php echo e(strip_tags($news->content)); ?>
                        </p>
                        <div class="mt-auto pt-6 border-t border-slate-50">
                            <a href="index.php?page=news_detail&id=<?php echo e($news->id); ?>" class="inline-flex items-center gap-2 text-sm font-black text-slate-800 hover:text-blue-600 transition-colors">
                                Baca Selengkapnya <i data-lucide="chevron-right" class="w-4 h-4"></i>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php if(empty($latest_news)): ?>
                <div class="col-span-full py-20 text-center glass rounded-[3rem]">
                    <p class="text-slate-400 font-bold outfit">Belum ada berita atau kegiatan terbaru.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section id="tentang" class="py-20 px-6">
        <div class="max-w-4xl mx-auto text-center">
            <h2 class="text-3xl md:text-4xl font-black outfit text-slate-800 mb-8 tracking-tight">Tentang <?php echo htmlspecialchars($institution_name); ?></h2>
            <div class="glass p-10 md:p-16 rounded-[3rem]">
                <p class="text-lg text-slate-600 leading-relaxed font-medium">
                    <?php echo nl2br(htmlspecialchars($about_text)); ?>
                </p>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="py-20 px-6 mb-20">
        <div class="max-w-5xl mx-auto bg-slate-900 rounded-[3rem] p-12 md:p-20 text-center relative overflow-hidden shadow-2xl">
            <div class="absolute top-0 right-0 w-64 h-64 bg-blue-500/20 rounded-full mix-blend-screen filter blur-3xl"></div>
            <div class="absolute bottom-0 left-0 w-64 h-64 bg-indigo-500/20 rounded-full mix-blend-screen filter blur-3xl"></div>
            
            <div class="relative z-10">
                <h2 class="text-3xl md:text-5xl font-black outfit text-white mb-6 tracking-tight">Siap Bergabung Kembali?</h2>
                <p class="text-slate-300 text-lg max-w-2xl mx-auto mb-10 font-medium">Jangan lewatkan kesempatan untuk terus terhubung, berkontribusi, dan menikmati berbagai kemudahan layanan digital kami.</p>
                <a href="index.php?page=register" class="inline-block bg-white text-slate-900 hover:bg-slate-100 px-10 py-4 rounded-full text-lg font-bold shadow-lg transition-all hover:scale-105">
                    Buat Akun Sekarang
                </a>
            </div>
        </div>
    </section>

    <!-- Contact Us Section -->
    <section id="kontak" class="py-24 px-6">
        <div class="max-w-6xl mx-auto">
            <div class="text-center mb-14">
                <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/60 border border-white/80 shadow-sm text-sm font-bold text-blue-600 mb-6">
                    <i data-lucide="phone" class="w-4 h-4"></i> Layanan Pelanggan
                </div>
                <h2 class="text-3xl md:text-4xl font-black outfit text-slate-800 mb-4 tracking-tight">Hubungi Kami</h2>
                <p class="text-slate-500 font-medium max-w-xl mx-auto">Ada pertanyaan atau butuh bantuan? Tim kami siap membantu Anda melalui berbagai saluran komunikasi di bawah ini.</p>
            </div>

            <!-- Info Cards Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                <!-- Email -->
                <?php if (!empty($contact_email)): ?>
                <div class="glass p-8 rounded-[2.5rem] flex items-start gap-5 group hover:shadow-xl hover:shadow-blue-500/10 transition-all duration-500 hover:-translate-y-1">
                    <div class="w-14 h-14 bg-blue-100 text-blue-600 rounded-2xl flex items-center justify-center flex-shrink-0 group-hover:bg-blue-600 group-hover:text-white transition-all duration-300">
                        <i data-lucide="mail" class="w-7 h-7"></i>
                    </div>
                    <div>
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Email Resmi</p>
                        <a href="mailto:<?php echo htmlspecialchars($contact_email); ?>" class="text-lg font-bold text-slate-800 hover:text-blue-600 transition-colors break-all"><?php echo htmlspecialchars($contact_email); ?></a>
                        <p class="text-xs text-slate-400 mt-1">Kami merespons dalam 1x24 jam kerja</p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Address -->
                <?php if (!empty($contact_address)): ?>
                <div class="glass p-8 rounded-[2.5rem] flex items-start gap-5 group hover:shadow-xl hover:shadow-indigo-500/10 transition-all duration-500 hover:-translate-y-1">
                    <div class="w-14 h-14 bg-indigo-100 text-indigo-600 rounded-2xl flex items-center justify-center flex-shrink-0 group-hover:bg-indigo-600 group-hover:text-white transition-all duration-300">
                        <i data-lucide="map-pin" class="w-7 h-7"></i>
                    </div>
                    <div>
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Alamat</p>
                        <p class="text-sm font-semibold text-slate-800 leading-relaxed"><?php echo nl2br(htmlspecialchars($contact_address)); ?></p>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- WA Cards -->
            <?php if (!empty($wa_tracer) || !empty($wa_legalisir) || !empty($wa_itsupport)): ?>
            <div class="glass p-8 rounded-[2.5rem]">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-6 flex items-center gap-2">
                    <i data-lucide="message-circle" class="w-4 h-4 text-green-500"></i>
                    Kontak WhatsApp Tim Kami
                </p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                    <?php if (!empty($wa_tracer)): ?>
                    <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/','',$wa_tracer); ?>" target="_blank" rel="noopener noreferrer"
                       class="flex items-center gap-4 p-5 bg-white rounded-2xl border border-slate-100 hover:border-green-300 hover:shadow-lg hover:shadow-green-500/10 transition-all duration-300 group">
                        <div class="w-11 h-11 bg-blue-500 text-white rounded-xl flex items-center justify-center flex-shrink-0 group-hover:scale-110 transition-transform">
                            <i data-lucide="bar-chart-3" class="w-5 h-5"></i>
                        </div>
                        <div class="min-w-0">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Admin Tracer</p>
                            <p class="text-xs text-slate-400 font-medium">Chat via WhatsApp</p>
                        </div>
                        <i data-lucide="external-link" class="w-4 h-4 text-slate-300 ml-auto group-hover:text-green-500 transition-colors flex-shrink-0"></i>
                    </a>
                    <?php endif; ?>

                    <?php if (!empty($wa_legalisir)): ?>
                    <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/','',$wa_legalisir); ?>" target="_blank" rel="noopener noreferrer"
                       class="flex items-center gap-4 p-5 bg-white rounded-2xl border border-slate-100 hover:border-green-300 hover:shadow-lg hover:shadow-green-500/10 transition-all duration-300 group">
                        <div class="w-11 h-11 bg-indigo-500 text-white rounded-xl flex items-center justify-center flex-shrink-0 group-hover:scale-110 transition-transform">
                            <i data-lucide="award" class="w-5 h-5"></i>
                        </div>
                        <div class="min-w-0">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Admin Legalisir</p>
                            <p class="text-xs text-slate-400 font-medium">Chat via WhatsApp</p>
                        </div>
                        <i data-lucide="external-link" class="w-4 h-4 text-slate-300 ml-auto group-hover:text-green-500 transition-colors flex-shrink-0"></i>
                    </a>
                    <?php endif; ?>

                    <?php if (!empty($wa_itsupport)): ?>
                    <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/','',$wa_itsupport); ?>" target="_blank" rel="noopener noreferrer"
                       class="flex items-center gap-4 p-5 bg-white rounded-2xl border border-slate-100 hover:border-green-300 hover:shadow-lg hover:shadow-green-500/10 transition-all duration-300 group">
                        <div class="w-11 h-11 bg-amber-500 text-white rounded-xl flex items-center justify-center flex-shrink-0 group-hover:scale-110 transition-transform">
                            <i data-lucide="cpu" class="w-5 h-5"></i>
                        </div>
                        <div class="min-w-0">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">IT Support</p>
                            <p class="text-xs text-slate-400 font-medium">Chat via WhatsApp</p>
                        </div>
                        <i data-lucide="external-link" class="w-4 h-4 text-slate-300 ml-auto group-hover:text-green-500 transition-colors flex-shrink-0"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-white border-t border-slate-100 py-10 px-6">
        <div class="max-w-7xl mx-auto flex flex-col md:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-2">
                <img src="<?php echo htmlspecialchars(!empty($landing_settings['system_logo']) ? $landing_settings['system_logo'] : 'uploads/system/logo_1778236863.png'); ?>" alt="Logo" class="w-6 h-6 object-contain grayscale opacity-50" width="24" height="24" loading="lazy">
                <span class="font-black outfit text-slate-400">AlumniLink</span>
            </div>
            <p class="text-slate-400 text-sm font-medium text-center">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($institution_name); ?>. Hak cipta dilindungi undang-undang.</p>
            <a href="index.php?page=terms" class="text-xs font-bold text-slate-400 hover:text-blue-600 transition-colors underline underline-offset-2">Syarat &amp; Ketentuan</a>
        </div>
    </footer>

    <script>
        lucide.createIcons();
        
        // Navbar scroll effect
        window.addEventListener('scroll', () => {
            const nav = document.getElementById('navbar');
            if (window.scrollY > 20) {
                nav.classList.add('py-2');
                nav.classList.remove('py-4');
            } else {
                nav.classList.add('py-4');
                nav.classList.remove('py-2');
            }
        });

        // Antigravity Particles Generator
        function createParticles() {
            const container = document.getElementById('particles');
            const particleCount = 15; // Jumlah partikel
            
            for (let i = 0; i < particleCount; i++) {
                setTimeout(() => {
                    const particle = document.createElement('div');
                    particle.classList.add('particle');
                    
                    // Randomize size, position, and animation duration
                    const size = Math.random() * 60 + 20; // 20px - 80px
                    const left = Math.random() * 100; // 0% - 100%
                    const duration = Math.random() * 20 + 15; // 15s - 35s
                    
                    particle.style.width = `${size}px`;
                    particle.style.height = `${size}px`;
                    particle.style.left = `${left}%`;
                    particle.style.animationDuration = `${duration}s`;
                    
                    container.appendChild(particle);
                    
                    // Remove and recreate particle when animation ends to keep it looping infinitely
                    particle.addEventListener('animationend', () => {
                        particle.remove();
                        createSingleParticle(container);
                    });
                }, i * 1500); // Stagger particle creation
            }
        }

        function createSingleParticle(container) {
            const particle = document.createElement('div');
            particle.classList.add('particle');
            const size = Math.random() * 60 + 20;
            const left = Math.random() * 100;
            const duration = Math.random() * 20 + 15;
            
            particle.style.width = `${size}px`;
            particle.style.height = `${size}px`;
            particle.style.left = `${left}%`;
            particle.style.animationDuration = `${duration}s`;
            
            container.appendChild(particle);
            particle.addEventListener('animationend', () => {
                particle.remove();
                createSingleParticle(container);
            });
        }

        // Initialize particles
        createParticles();

        // ── Mobile Hamburger Menu ──────────────────────────────
        let mobileMenuOpen = false;

        function toggleMobileMenu() {
            mobileMenuOpen ? closeMobileMenu() : openMobileMenu();
        }

        function openMobileMenu() {
            mobileMenuOpen = true;
            const menu = document.getElementById('mobileMenu');
            menu.style.maxHeight = menu.scrollHeight + 'px';
            menu.style.opacity  = '1';
            // Animate to ✕
            document.getElementById('hb1').style.transform = 'rotate(45deg) translateY(6px)';
            document.getElementById('hb2').style.opacity   = '0';
            document.getElementById('hb3').style.transform = 'rotate(-45deg) translateY(-6px)';
            lucide.createIcons(); // re-render icons inside menu
        }

        function closeMobileMenu() {
            mobileMenuOpen = false;
            const menu = document.getElementById('mobileMenu');
            menu.style.maxHeight = '0';
            menu.style.opacity   = '0';
            // Animate back to ☰
            document.getElementById('hb1').style.transform = 'none';
            document.getElementById('hb2').style.opacity   = '1';
            document.getElementById('hb3').style.transform = 'none';
        }

        // Close menu when clicking outside
        document.addEventListener('click', (e) => {
            const nav = document.getElementById('navbar');
            if (mobileMenuOpen && !nav.contains(e.target)) {
                closeMobileMenu();
            }
        });

        // Premium Universal URL Masking for Status Bar & Copy Link: Convert all index.php?page= links to clean encrypted/RESTful paths
        function maskAllLinks() {
            document.querySelectorAll('a').forEach(a => {
                const origHref = a.getAttribute('href');
                if (origHref && origHref.includes('index.php?page=')) {
                    try {
                        const urlObj = new URL(a.href, window.location.origin);
                        const pageParam = urlObj.searchParams.get('page');
                        const idParam = urlObj.searchParams.get('id');
                        const actionParam = urlObj.searchParams.get('action');
                        
                        let cleanPath = pageParam;
                        if (idParam) cleanPath += '/' + idParam;
                        if (actionParam) cleanPath += '/' + actionParam;
                        
                        a.setAttribute('href', cleanPath);
                        a.removeAttribute('onclick');
                    } catch(err) {}
                }
            });
        }

        maskAllLinks();
        document.addEventListener('DOMContentLoaded', maskAllLinks);
    </script>

</body>
</html>
