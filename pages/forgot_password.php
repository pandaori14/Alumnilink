<?php
// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../includes/session_boot.php';
}
// Get system settings if available
global $pdo;
$sys_settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch()) {
        $sys_settings[$row->setting_key] = $row->setting_value;
    }
} catch (Exception $e) {
    // Fail silently
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Kata Sandi | <?php echo e($sys_settings['institution_name'] ?? 'AlumniLink'); ?></title>
    <meta name="description" content="Masukkan email Anda untuk menerima tautan atur ulang kata sandi AlumniLink.">
    
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
<body class="bg-gradient-mesh min-h-screen flex items-center justify-center p-6 overflow-hidden relative">
    <div class="bg-grid-pattern"></div>
    <div class="particles-container fixed top-0 left-0 w-full h-full -z-10 pointer-events-none" id="particles"></div>

    <div class="w-full max-w-md glass p-10 md:p-12 rounded-[3rem] shadow-2xl relative z-10">
        <div class="flex flex-col items-center mb-8">
            <div class="w-16 h-16 bg-white rounded-2xl flex items-center justify-center shadow-lg shadow-blue-100 mb-6 overflow-hidden p-3 border border-white">
                <img src="<?php echo e(!empty($sys_settings['system_logo']) ? $sys_settings['system_logo'] : 'uploads/system/logo_1778236863.png'); ?>" class="w-full h-full object-contain" alt="Logo">
            </div>
            <h1 class="text-3xl font-black outfit tracking-tighter text-slate-800">Lupa <span class="text-blue-600">Sandi</span></h1>
            <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest mt-2">Atur ulang kata sandi akun Anda</p>
        </div>

        <?php if (isset($_GET['error'])): ?>
            <?php if ($_GET['error'] === 'oauth_account'): ?>
                <div class="bg-amber-50 border border-amber-200 text-amber-800 p-5 rounded-2xl mb-6 text-sm space-y-3">
                    <div class="flex items-center gap-2 font-bold text-amber-700">
                        <i data-lucide="shield-alert" class="w-5 h-5 shrink-0"></i>
                        <span>Akun Google OAuth Terdeteksi</span>
                    </div>
                    <p class="text-slate-600 leading-relaxed font-medium">
                        Akun Anda terdaftar menggunakan metode <strong>Google OAuth</strong>.
                    </p>
                    <p class="text-slate-500 text-xs">
                        Jika Anda lupa kata sandi Google Anda, silakan lakukan pemulihan mandiri melalui Google. Jika Anda memerlukan kata sandi sistem manual, silakan hubungi <strong>Superadmin</strong>.
                    </p>
                    <div class="pt-2">
                        <a href="https://accounts.google.com/signin/recovery" target="_blank" class="w-full py-3 bg-amber-600 text-white rounded-xl font-semibold hover:bg-amber-700 transition-all text-center inline-block text-xs shadow-md shadow-amber-200">
                            Buka Pemulihan Akun Google
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="bg-red-50 border border-red-100 text-red-600 px-4 py-3 rounded-2xl mb-6 text-sm flex items-center gap-2">
                    <i data-lucide="alert-circle" class="w-4 h-4 shrink-0"></i>
                    <span>
                        <?php
                        $err = $_GET['error'];
                        if ($err === 'email_not_found') {
                            echo "Email tidak ditemukan atau tidak terdaftar.";
                        } elseif ($err === 'csrf') {
                            echo "Validasi keamanan gagal. Silakan coba lagi.";
                        } elseif ($err === 'mail_failed') {
                            echo "Gagal mengirim email reset. Hubungi administrator.";
                        } elseif ($err === 'rate_limit') {
                            $retry = isset($_GET['retry_after']) ? intval($_GET['retry_after']) : 15;
                            echo "Terlalu banyak permintaan reset. Silakan coba lagi dalam " . $retry . " menit.";
                        } else {
                            echo "Terjadi kesalahan sistem.";
                        }
                        ?>
                    </span>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (isset($_GET['success']) && $_GET['success'] === 'reset_requested'): ?>
            <div class="bg-emerald-50 border border-emerald-100 text-emerald-700 px-5 py-4 rounded-2xl mb-6 text-sm">
                <div class="flex items-center gap-2 font-bold mb-1">
                    <i data-lucide="check-circle" class="w-5 h-5 shrink-0"></i>
                    <span>Permintaan Dikirim!</span>
                </div>
                <p class="text-slate-600 font-medium">Tautan untuk mengatur ulang kata sandi Anda telah dikirimkan ke email terdaftar.</p>
            </div>

            <!-- Developer Sandbox for Local Testing -->
            <?php if (getenv('APP_ENV') === 'local' && isset($_SESSION['dev_mail_sandbox'])): ?>
                <div class="bg-blue-50 border border-blue-100 text-blue-800 px-5 py-4 rounded-2xl mb-6 text-xs space-y-3">
                    <div class="flex items-center gap-2 font-bold">
                        <i data-lucide="terminal" class="w-4 h-4"></i>
                        <span>Developer Sandbox (Local Only)</span>
                    </div>
                    <p class="text-slate-600 font-medium">Email pengiriman disimulasikan secara lokal:</p>
                    <div class="bg-white/60 p-3 rounded-xl border border-blue-200/50 font-mono break-all select-all">
                        <strong>To:</strong> <?php echo htmlspecialchars($_SESSION['dev_mail_sandbox']['to']); ?><br>
                        <strong>Link:</strong> <a href="<?php echo htmlspecialchars($_SESSION['dev_mail_sandbox']['link']); ?>" class="text-blue-600 hover:underline font-bold"><?php echo htmlspecialchars($_SESSION['dev_mail_sandbox']['link']); ?></a>
                    </div>
                    <p class="text-[10px] text-slate-400">Log lengkap dicatat di file <code>logs/mail_debug.log</code></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <form action="handlers/forgot_password.php" method="POST" class="space-y-6">
            <?php csrf_field(); ?>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-2 ml-1" for="f_email">Email Terdaftar</label>
                <input id="f_email" type="email" name="email" required class="w-full px-5 py-4 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 transition-all outline-none" placeholder="alumni@example.com">
            </div>

            <button type="submit" class="w-full py-4 bg-blue-600 text-white rounded-2xl font-semibold shadow-lg shadow-blue-200 hover:bg-blue-700 transition-all active:scale-[0.98]">
                Kirim Tautan Atur Ulang
            </button>
        </form>

        <div class="mt-8 text-center">
            <a href="index.php?page=login" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-500 hover:text-blue-600 transition-all">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>
                Kembali ke Halaman Masuk
            </a>
        </div>
    </div>

    <script>
        // Initialize Lucide icons
        lucide.createIcons();

        // Particles Animation
        const particlesContainer = document.getElementById('particles');
        const particleCount = 15;

        for (let i = 0; i < particleCount; i++) {
            const particle = document.createElement('div');
            particle.classList.add('particle');
            
            const size = Math.random() * 80 + 20;
            particle.style.width = `${size}px`;
            particle.style.height = `${size}px`;
            
            particle.style.left = `${Math.random() * 100}%`;
            particle.style.animationDuration = `${Math.random() * 15 + 10}s`;
            particle.style.animationDelay = `${Math.random() * 8}s`;
            
            particlesContainer.appendChild(particle);
        }
    </script>
</body>
</html>
