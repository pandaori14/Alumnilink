<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk | <?php echo e($sys_settings['institution_name'] ?? 'AlumniLink'); ?></title>
    <meta name="description" content="Masuk ke akun AlumniLink Anda untuk mengakses layanan Tracer Alumni, Legalisir, dan Donasi.">
    
    <!-- OpenGraph Tags -->
    <meta property="og:title" content="Masuk - AlumniLink">
    <meta property="og:description" content="Masuk ke akun AlumniLink Anda untuk mengakses layanan alumni terintegrasi.">
    <meta property="og:type" content="website">
    <meta property="og:image" content="<?php echo e(!empty($sys_settings['system_logo']) ? $sys_settings['system_logo'] : 'uploads/system/logo_1778236863.png'); ?>">

    <link rel="icon" type="image/png" href="<?php echo e(!empty($sys_settings['system_logo']) ? $sys_settings['system_logo'] : 'uploads/system/logo_1778236863.png'); ?>">
    <?php
    $base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
    ?>
    <base href="<?php echo e($base_path); ?>">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/fonts/fonts.css">
    <script src="assets/js/lucide.min.js"></script>
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
        @keyframes float {
            0% { transform: translateY(0px) rotate(0deg); opacity: 0; }
            10% { opacity: 0.5; }
            90% { opacity: 0.5; }
            100% { transform: translateY(-100vh) rotate(360deg); opacity: 0; }
        }
        .particle {
            position: absolute;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.4), rgba(147, 197, 253, 0.1));
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
</head>
<body class="bg-gradient-mesh min-h-screen flex items-center justify-center p-6 overflow-hidden">
    <!-- Antigravity Tech Grid -->
    <div class="bg-grid-pattern"></div>

    <!-- Antigravity Floating Particles -->
    <div class="particles-container fixed top-0 left-0 w-full h-full -z-10 pointer-events-none" id="particles"></div>

    <div class="w-full max-w-md glass p-10 md:p-12 rounded-[3rem] shadow-2xl relative z-10">
        <div class="flex flex-col items-center mb-8">
            <div class="w-16 h-16 bg-white rounded-2xl flex items-center justify-center shadow-lg shadow-blue-100 mb-6 overflow-hidden p-3 border border-white">
                <img src="<?php echo e(!empty($sys_settings['system_logo']) ? $sys_settings['system_logo'] : 'uploads/system/logo_1778236863.png'); ?>" class="w-full h-full object-contain" alt="Logo">
            </div>
            <h1 class="text-3xl font-black outfit tracking-tighter text-slate-800">Alumni<span class="text-blue-600">Link</span></h1>
            <p class="text-slate-400 text-xs font-bold uppercase tracking-widest mt-2"><?php echo e($sys_settings['institution_name'] ?? 'Portal Alumni'); ?></p>
        </div>

        <?php if (isset($_GET['error'])): ?>
            <div class="bg-red-50 text-red-600 px-4 py-3 rounded-xl mb-6 text-sm flex items-center gap-2">
                <i data-lucide="alert-circle" class="w-4 h-4"></i>
                Username atau password salah.
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['reset_success'])): ?>
            <div class="bg-emerald-50 text-emerald-600 px-4 py-3 rounded-xl mb-6 text-sm flex items-center gap-2 border border-emerald-100">
                <i data-lucide="check-circle" class="w-4 h-4"></i>
                Kata sandi berhasil diatur ulang! Silakan masuk.
            </div>
        <?php endif; ?>

        <form action="handlers/auth.php" method="POST" class="space-y-6">
            <?php csrf_field(); ?>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-2 ml-1" for="f_identity">Email / Username</label>
                <input id="f_identity" type="text" name="identity" required class="w-full px-5 py-4 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 transition-all outline-none" placeholder="admin / alumni@example.com">
            </div>
            <div>
                <div class="flex justify-between items-center mb-2 ml-1">
                    <label class="block text-sm font-medium text-slate-700" for="f_password">Kata Sandi</label>
                    <a href="index.php?page=forgot_password" class="text-xs font-semibold text-blue-600 hover:text-blue-700 hover:underline transition-all">Lupa Kata Sandi?</a>
                </div>
                <input id="f_password" type="password" name="password" required class="w-full px-5 py-4 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 transition-all outline-none" placeholder="••••••••">
            </div>
            
            <button type="submit" class="w-full py-4 bg-blue-600 text-white rounded-2xl font-semibold shadow-lg shadow-blue-200 hover:bg-blue-700 transition-all active:scale-[0.98]">
                Masuk Sekarang
            </button>
        </form>

        <div class="mt-8 flex items-center gap-4">
            <div class="h-px bg-slate-200 flex-1"></div>
            <span class="text-xs font-bold text-slate-400 uppercase tracking-widest">Atau</span>
            <div class="h-px bg-slate-200 flex-1"></div>
        </div>

        <div class="mt-6">
            <a href="handlers/google_oauth.php?action=login" class="w-full flex items-center justify-center gap-3 py-4 bg-white border-2 border-slate-100 rounded-2xl font-bold text-slate-600 hover:bg-slate-50 hover:border-slate-200 hover:shadow-md transition-all active:scale-[0.98]">
                <img src="assets/img/google-color.svg" alt="Google" class="w-5 h-5">
                Masuk dengan Google
            </a>
        </div>

        <div class="mt-10 text-center">
            <p class="text-slate-500 text-sm">Belum punya akun? <a href="register" class="text-blue-600 font-semibold hover:underline">Daftar di sini</a></p>
        </div>
    </div>

    <script>
        lucide.createIcons();

        // Antigravity Particles Generator
        function createParticles() {
            const container = document.getElementById('particles');
            const particleCount = 10;
            
            for (let i = 0; i < particleCount; i++) {
                setTimeout(() => {
                    const particle = document.createElement('div');
                    particle.classList.add('particle');
                    const size = Math.random() * 40 + 10;
                    const left = Math.random() * 100;
                    const duration = Math.random() * 15 + 10;
                    particle.style.width = `${size}px`;
                    particle.style.height = `${size}px`;
                    particle.style.left = `${left}%`;
                    particle.style.animationDuration = `${duration}s`;
                    container.appendChild(particle);
                    particle.addEventListener('animationend', () => {
                        particle.remove();
                        createSingleParticle(container);
                    });
                }, i * 1000);
            }
        }

        function createSingleParticle(container) {
            const particle = document.createElement('div');
            particle.classList.add('particle');
            const size = Math.random() * 40 + 10;
            const left = Math.random() * 100;
            const duration = Math.random() * 15 + 10;
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
        createParticles();

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
