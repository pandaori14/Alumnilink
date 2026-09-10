<?php
// Ambil data pengaturan
$stmt = $pdo->query("SELECT * FROM settings");
$raw_settings = $stmt->fetchAll();
$sys_settings = [];
foreach ($raw_settings as $s) {
    $sys_settings[$s->setting_key] = $s->setting_value;
}
$institution_name = $sys_settings['institution_name'] ?? 'Universitas';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mode Perbaikan - AlumniLink</title>
    <?php
    $base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
    $system_logo = !empty($sys_settings['system_logo']) ? $sys_settings['system_logo'] : 'uploads/system/logo_1778236863.png';
    ?>
    <base href="<?php echo $base_path; ?>">
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($system_logo); ?>">
    <link rel="shortcut icon" type="image/png" href="<?php echo htmlspecialchars($system_logo); ?>">
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="assets/js/lucide.min.js"></script>
    <link rel="stylesheet" href="assets/fonts/fonts.css">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .outfit { font-family: 'Outfit', sans-serif; }
        .glass { background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.5); }
        .bg-gradient-mesh {
            background-color: #f8fafc;
            background-image: 
                radial-gradient(at 0% 0%, hsla(216,100%,97%,1) 0px, transparent 50%),
                radial-gradient(at 100% 0%, hsla(0,100%,97%,1) 0px, transparent 50%),
                radial-gradient(at 100% 100%, hsla(216,100%,97%,1) 0px, transparent 50%),
                radial-gradient(at 0% 100%, hsla(0,100%,97%,1) 0px, transparent 50%);
        }
        @keyframes float {
            0% { transform: translateY(0px) rotate(0deg); opacity: 0; }
            10% { opacity: 0.3; }
            90% { opacity: 0.3; }
            100% { transform: translateY(-100vh) rotate(360deg); opacity: 0; }
        }
        .particle {
            position: absolute;
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(248, 113, 113, 0.05));
            border-radius: 50%;
            animation: float linear infinite;
            bottom: -100px;
            z-index: -1;
        }
    </style>
</head>
<body class="bg-gradient-mesh min-h-screen flex items-center justify-center p-6 overflow-hidden">
    <div id="particles"></div>

    <div class="max-w-xl w-full text-center relative z-10">
        <div class="glass p-12 md:p-16 rounded-[3rem] shadow-2xl border-white relative overflow-hidden">
            <!-- Decorative Icon -->
            <div class="w-24 h-24 bg-red-50 text-red-500 rounded-3xl flex items-center justify-center mx-auto mb-10 animate-bounce">
                <i data-lucide="wrench" class="w-12 h-12"></i>
            </div>

            <h1 class="text-3xl md:text-4xl font-black outfit text-slate-800 mb-6 tracking-tight">Sistem Sedang Dalam Perbaikan</h1>
            
            <p class="text-slate-500 font-medium leading-relaxed mb-10">
                Halo Alumni <strong><?php echo htmlspecialchars($institution_name); ?></strong>. Saat ini kami sedang melakukan pemeliharaan rutin untuk meningkatkan kualitas layanan AlumniLink. 
                <br><br>
                Akses untuk alumni sementara dinonaktifkan. Silakan kembali lagi beberapa saat lagi. Terima kasih atas kesabarannya.
            </p>

            <div class="flex flex-col gap-4">
                <a href="landing" class="bg-slate-800 text-white px-8 py-4 rounded-2xl font-bold hover:bg-slate-900 transition-all shadow-lg flex items-center justify-center gap-2">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i> Kembali ke Beranda
                </a>
                <p class="text-[10px] text-slate-400 font-bold uppercase tracking-[0.2em]">Administrator & Tim IT tetap dapat masuk</p>
            </div>
        </div>
        
        <p class="mt-8 text-slate-400 text-sm font-bold outfit tracking-wider uppercase">&copy; <?php echo date('Y'); ?> AlumniLink - <?php echo htmlspecialchars($institution_name); ?></p>
    </div>

    <script>
        lucide.createIcons();
        
        // Simple Particle Generator
        const container = document.getElementById('particles');
        for (let i = 0; i < 10; i++) {
            const p = document.createElement('div');
            p.className = 'particle';
            const size = Math.random() * 80 + 20;
            p.style.width = size + 'px';
            p.style.height = size + 'px';
            p.style.left = Math.random() * 100 + '%';
            p.style.animationDuration = (Math.random() * 15 + 10) + 's';
            p.style.animationDelay = (Math.random() * 10) + 's';
            container.appendChild(p);
        }

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
