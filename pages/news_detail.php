<?php
$id = $_GET['id'] ?? null;
if (!$id) {
    echo "<script>window.location.href='index.php?page=landing';</script>";
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM news_posts WHERE id = ?");
$stmt->execute([$id]);
$post = $stmt->fetch();

if (!$post) {
    echo "<script>window.location.href='index.php?page=landing';</script>";
    exit();
}

// Global Settings
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
    <title><?php echo htmlspecialchars($post->title); ?> - AlumniLink</title>
    <meta name="description" content="<?php echo htmlspecialchars(mb_substr(strip_tags($post->content), 0, 160)); ?>">
    <?php
    $base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
    $system_logo = !empty($sys_settings['system_logo']) ? $sys_settings['system_logo'] : 'uploads/system/logo_1778236863.png';
    ?>
    <base href="<?php echo e($base_path); ?>">

    <!-- Favicon & OpenGraph -->
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($system_logo); ?>">
    <link rel="shortcut icon" type="image/png" href="<?php echo htmlspecialchars($system_logo); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($post->title); ?> - AlumniLink">
    <meta property="og:description" content="<?php echo htmlspecialchars(mb_substr(strip_tags($post->content), 0, 160)); ?>">
    <meta property="og:type" content="article">
    <meta property="og:image" content="<?php echo htmlspecialchars($post->image ? $post->image : $system_logo); ?>">
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="assets/js/lucide.min.js"></script>
    <link rel="stylesheet" href="assets/fonts/fonts.css">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .outfit { font-family: 'Outfit', sans-serif; }
        .glass { background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.5); }
        .prose p { margin-bottom: 1.5rem; line-height: 1.8; color: #475569; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen pb-20">

    <nav class="sticky top-0 w-full z-50 glass border-b border-white/50">
        <div class="max-w-4xl mx-auto px-6 py-4 flex items-center justify-between">
            <a href="<?php echo e(isset($_SESSION['user_id']) ? 'dashboard' : 'landing'); ?>" class="flex items-center gap-2 text-slate-800 font-black outfit hover:text-blue-600 transition-all">
                <i data-lucide="arrow-left" class="w-5 h-5"></i> Kembali
            </a>
            <div class="flex items-center gap-2">
                <span class="font-black outfit text-slate-400">Alumni<span class="text-blue-600">Link</span></span>
            </div>
        </div>
    </nav>

    <main class="max-w-4xl mx-auto px-6 mt-12">
        <div class="mb-10 text-center">
            <span class="px-4 py-1.5 rounded-full text-[10px] font-black uppercase tracking-widest bg-blue-100 text-blue-600 mb-6 inline-block">
                <?php echo e($post->type); ?>
            </span>
            <h1 class="text-3xl md:text-5xl font-black outfit text-slate-900 tracking-tight leading-tight mb-6">
                <?php echo htmlspecialchars($post->title); ?>
            </h1>
            <div class="flex items-center justify-center gap-4 text-slate-400 text-sm font-bold">
                <div class="flex items-center gap-2">
                    <i data-lucide="calendar" class="w-4 h-4"></i>
                    <?php echo date('d F Y', strtotime($post->created_at)); ?>
                </div>
                <span>•</span>
                <div class="flex items-center gap-2">
                    <i data-lucide="clock" class="w-4 h-4"></i>
                    <?php echo date('H:i', strtotime($post->created_at)); ?> WIB
                </div>
            </div>
        </div>

        <?php if ($post->image): ?>
        <div class="rounded-[3rem] overflow-hidden shadow-2xl mb-12 aspect-video">
            <img src="<?php echo e($post->image); ?>" class="w-full h-full object-cover" alt="Cover Image">
        </div>
        <?php endif; ?>

        <article class="glass p-8 md:p-16 rounded-[3rem] border-white prose max-w-none shadow-sm">
            <?php echo nl2br(htmlspecialchars($post->content)); ?>
        </article>

        <!--<div class="mt-20 p-10 bg-slate-900 rounded-[3rem] text-center relative overflow-hidden">
             <div class="relative z-10">
                <h3 class="text-2xl font-black outfit text-white mb-4">Ingin mengikuti kegiatan lainnya?</h3>
                <p class="text-slate-400 mb-8 max-w-md mx-auto">Daftar sekarang untuk menjadi bagian dari komunitas alumni kami.</p>
                <a href="register" class="inline-block bg-white text-slate-900 px-8 py-4 rounded-full font-bold hover:scale-105 transition-all shadow-xl shadow-white/10">Daftar Sekarang</a>
             </div>
        </div>-->
    </main>

    <script>
        lucide.createIcons();

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
