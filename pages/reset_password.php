<?php
// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../includes/session_boot.php';
}

global $pdo;

// Get system settings
$sys_settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch()) {
        $sys_settings[$row->setting_key] = $row->setting_value;
    }
} catch (Exception $e) {
    // Fail silently
}

$token = $_GET['token'] ?? '';
$isValid = false;
$email = '';
$errorMsg = '';

if (!empty($token)) {
    try {
        // Query to check if token exists
        $stmt = $pdo->prepare("SELECT email, created_at FROM password_resets WHERE token = ?");
        $stmt->execute([$token]);
        $reset = $stmt->fetch();

        if ($reset) {
            // Token expiry check: 1 hour (3600 seconds)
            $created_time = strtotime($reset->created_at);
            $current_time = time();
            $expiry_time = 3600;

            if (($current_time - $created_time) <= $expiry_time) {
                $isValid = true;
                $email = $reset->email;
            } else {
                $errorMsg = 'Tautan pengaturan ulang kata sandi telah kedaluwarsa (berlaku maks. 1 jam).';
                // Clean up expired token
                $stmt_del = $pdo->prepare("DELETE FROM password_resets WHERE token = ?");
                $stmt_del->execute([$token]);
            }
        } else {
            $errorMsg = 'Tautan pengaturan ulang kata sandi tidak valid atau sudah pernah digunakan.';
        }
    } catch (PDOException $e) {
        error_log("Reset Password Token Verification Database Error: " . $e->getMessage());
        $errorMsg = 'Terjadi kesalahan sistem saat memverifikasi tautan.';
    }
} else {
    $errorMsg = 'Tautan pengaturan ulang kata sandi tidak lengkap.';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atur Ulang Kata Sandi | <?php echo e($sys_settings['institution_name'] ?? 'AlumniLink'); ?></title>
    <meta name="description" content="Masukkan kata sandi baru Anda untuk mengamankan akun Anda.">
    
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
            <h1 class="text-3xl font-black outfit tracking-tighter text-slate-800">Atur Ulang <span class="text-blue-600">Sandi</span></h1>
            <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest mt-2">Buat kata sandi baru untuk akun Anda</p>
        </div>

        <?php if (!$isValid): ?>
            <div class="bg-red-50 border border-red-100 text-red-600 px-5 py-4 rounded-2xl mb-6 text-sm">
                <div class="flex items-center gap-2 font-bold mb-1">
                    <i data-lucide="alert-triangle" class="w-5 h-5 shrink-0"></i>
                    <span>Tautan Tidak Valid</span>
                </div>
                <p class="text-slate-600 font-medium"><?php echo htmlspecialchars($errorMsg); ?></p>
            </div>
            <div class="text-center">
                <a href="index.php?page=forgot_password" class="w-full py-4 bg-blue-600 text-white rounded-2xl font-semibold shadow-lg shadow-blue-200 hover:bg-blue-700 transition-all inline-block">
                    Minta Tautan Baru
                </a>
                <a href="index.php?page=login" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-500 hover:text-blue-600 transition-all mt-6">
                    <i data-lucide="arrow-left" class="w-4 h-4"></i>
                    Kembali ke Halaman Masuk
                </a>
            </div>
        <?php else: ?>
            <?php if (isset($_GET['error'])): ?>
                <div class="bg-red-50 border border-red-100 text-red-600 px-4 py-3 rounded-2xl mb-6 text-sm flex items-center gap-2">
                    <i data-lucide="alert-circle" class="w-4 h-4 shrink-0"></i>
                    <span>
                        <?php
                        $err = $_GET['error'];
                        if ($err === 'mismatch') {
                            echo "Kata sandi konfirmasi tidak cocok.";
                        } elseif ($err === 'too_short') {
                            echo "Kata sandi minimal harus 6 karakter.";
                        } elseif ($err === 'csrf') {
                            echo "Validasi keamanan gagal. Silakan coba lagi.";
                        } elseif ($err === 'rate_limit') {
                            $retry = isset($_GET['retry_after']) ? intval($_GET['retry_after']) : 15;
                            echo "Terlalu banyak percobaan reset kata sandi. Silakan coba lagi dalam " . $retry . " menit.";
                        } else {
                            echo "Gagal memperbarui kata sandi. Silakan coba lagi.";
                        }
                        ?>
                    </span>
                </div>
            <?php endif; ?>

            <form action="handlers/reset_password.php" method="POST" id="resetForm" class="space-y-6">
                <?php csrf_field(); ?>
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2 ml-1">Email Anda</label>
                    <input type="text" readonly value="<?php echo htmlspecialchars($email); ?>" class="w-full px-5 py-4 rounded-2xl bg-slate-100/75 border border-slate-200 text-slate-500 cursor-not-allowed outline-none font-medium">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2 ml-1">Kata Sandi Baru</label>
                    <input aria-label="Minimal 6 karakter" type="password" name="password" id="password" required class="w-full px-5 py-4 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 transition-all outline-none" placeholder="Minimal 6 karakter">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2 ml-1">Konfirmasi Kata Sandi Baru</label>
                    <input aria-label="••••••••" type="password" name="confirm_password" id="confirm_password" required class="w-full px-5 py-4 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 transition-all outline-none" placeholder="••••••••">
                    <p id="validationHint" class="text-[10px] text-red-500 mt-2 ml-1 hidden">Konfirmasi kata sandi tidak cocok.</p>
                </div>

                <button type="submit" class="w-full py-4 bg-blue-600 text-white rounded-2xl font-semibold shadow-lg shadow-blue-200 hover:bg-blue-700 transition-all active:scale-[0.98]">
                    Atur Ulang Kata Sandi
                </button>
            </form>
        <?php endif; ?>
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

        // Live Match Checking
        const form = document.getElementById('resetForm');
        if (form) {
            const pwd = document.getElementById('password');
            const confirmPwd = document.getElementById('confirm_password');
            const hint = document.getElementById('validationHint');

            function checkMatch() {
                if (confirmPwd.value && pwd.value !== confirmPwd.value) {
                    hint.classList.remove('hidden');
                } else {
                    hint.classList.add('hidden');
                }
            }

            pwd.addEventListener('input', checkMatch);
            confirmPwd.addEventListener('input', checkMatch);

            form.addEventListener('submit', function(e) {
                if (pwd.value !== confirmPwd.value) {
                    e.preventDefault();
                    hint.classList.remove('hidden');
                }
            });
        }
    </script>
</body>
</html>
