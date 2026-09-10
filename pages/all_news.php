<?php
// Halaman ini TERBUKA UNTUK PUBLIK dan sebelumnya mengambil seluruh baris
// news_posts tanpa LIMIT — komentar aslinya pun mengakuinya ("let's do all
// for now"). Setiap pengunjung anonim memicu satu pemindaian tabel penuh
// dan menerima seluruh arsip berita dalam satu halaman.
$hal_berita = paginate($pdo, ' FROM news_posts', '*', 'created_at DESC', [], 9);
$all_news   = $hal_berita['rows'];
$berita_url = pager_url_builder(['page' => 'all_news']);

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
    <title>Semua Berita & Kegiatan - AlumniLink</title>
    <meta name="description" content="Informasi terkini mengenai agenda, berita, dan perkembangan terbaru di lingkungan alumni <?php echo htmlspecialchars($institution_name); ?>.">
    <?php
    $base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
    $system_logo = !empty($sys_settings['system_logo']) ? $sys_settings['system_logo'] : 'uploads/system/logo_1778236863.png';
    ?>
    <base href="<?php echo e($base_path); ?>">

    <!-- Favicon & OpenGraph -->
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($system_logo); ?>">
    <link rel="shortcut icon" type="image/png" href="<?php echo htmlspecialchars($system_logo); ?>">
    <meta property="og:title" content="Semua Berita & Kegiatan - AlumniLink">
    <meta property="og:description" content="Informasi terkini mengenai agenda, berita, dan perkembangan terbaru di lingkungan alumni <?php echo htmlspecialchars($institution_name); ?>.">
    <meta property="og:type" content="website">
    <meta property="og:image" content="<?php echo htmlspecialchars($system_logo); ?>">
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="assets/js/lucide.min.js"></script>
    <link rel="stylesheet" href="assets/fonts/fonts.css">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .outfit { font-family: 'Outfit', sans-serif; }
        .glass { background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.5); }
    </style>
</head>
<body class="bg-slate-50 min-h-screen pb-20">

    <nav class="sticky top-0 w-full z-50 glass border-b border-white/50">
        <div class="max-w-7xl mx-auto px-6 py-6 flex items-center justify-between">
            <a href="<?php echo e(isset($_SESSION['user_id']) ? 'dashboard' : 'landing'); ?>" class="flex items-center gap-2 text-slate-800 font-black outfit hover:text-blue-600 transition-all">
                <i data-lucide="arrow-left" class="w-5 h-5"></i> Kembali
            </a>
            <div class="flex items-center gap-2">
                <span class="font-black outfit text-slate-800">Kabar <span class="text-blue-600">Alumni</span></span>
            </div>
        </div>
    </nav>

    <header class="py-20 px-6 text-center">
        <h1 class="text-4xl md:text-6xl font-black outfit text-slate-900 tracking-tight mb-4">Berita & Kegiatan</h1>
        <p class="text-slate-500 font-medium max-w-2xl mx-auto italic">Informasi terkini mengenai agenda, berita, dan perkembangan terbaru di lingkungan alumni <?php echo htmlspecialchars($institution_name); ?>.</p>
    </header>

    <main class="max-w-7xl mx-auto px-6">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            <?php foreach ($all_news as $news): ?>
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

            <?php if(empty($all_news)): ?>
            <div class="col-span-full py-20 text-center glass rounded-[3rem]">
                <i data-lucide="newspaper" class="w-16 h-16 text-slate-200 mx-auto mb-4"></i>
                <h3 class="text-xl font-bold outfit text-slate-400">Belum Ada Berita</h3>
                <p class="text-slate-300 text-sm mt-2">Maaf, saat ini belum ada informasi atau kegiatan yang dipublikasikan.</p>
            </div>
            <?php endif; ?>
        </div>

        <?php
        render_pager_summary(count($all_news), $hal_berita['total'], $hal_berita['hal'], $hal_berita['total_hal'], 'berita');
        render_pager($berita_url, $hal_berita['hal'], $hal_berita['total_hal'], 'putih');
        ?>
    </main>

    <footer class="mt-20 py-10 text-center">
        <p class="text-slate-400 text-sm font-bold outfit tracking-wider uppercase">&copy; <?php echo date('Y'); ?> AlumniLink - <?php echo htmlspecialchars($institution_name); ?></p>
    </footer>

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
