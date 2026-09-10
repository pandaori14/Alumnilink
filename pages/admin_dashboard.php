<?php
// Trigger Log Cleanup as Background/Lazy Process for Super Admin
if (($_SESSION['user_role'] ?? '') === 'super_admin') {
    require_once __DIR__ . '/../includes/log_cleanup.php';
}

// Admin Stats Summary
$total_alumni    = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'alumni'")->fetchColumn();
$verified_alumni = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'alumni' AND is_verified = 1")->fetchColumn();
$pending_verif   = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'alumni' AND is_verified = 0")->fetchColumn();
$total_legalisir = $pdo->query("SELECT COUNT(*) FROM legalisir_requests")->fetchColumn();
$pending_leg     = $pdo->query("SELECT COUNT(*) FROM legalisir_requests WHERE status = 'pending'")->fetchColumn();
$processing_leg  = $pdo->query("SELECT COUNT(*) FROM legalisir_requests WHERE status = 'processing'")->fetchColumn();
$total_tracer    = $pdo->query("SELECT COUNT(*) FROM tracer_submissions")->fetchColumn();
$total_revenue   = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM legalisir_requests WHERE payment_status = 'settlement'")->fetchColumn();
$cash_revenue    = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM legalisir_requests WHERE payment_status = 'settlement' AND payment_method = 'cash'")->fetchColumn();
$midtrans_rev    = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM legalisir_requests WHERE payment_status = 'settlement' AND payment_method = 'midtrans'")->fetchColumn();
$total_repo      = $pdo->query("SELECT COUNT(*) FROM document_repository")->fetchColumn();
$total_news      = $pdo->query("SELECT COUNT(*) FROM news_posts")->fetchColumn();

// Recent Activities (combined)
$recent_legalisir = $pdo->query("
    SELECT lr.id, lr.status, lr.payment_status, lr.amount, lr.created_at, u.name as user_name
    FROM legalisir_requests lr
    JOIN users u ON lr.user_id = u.id
    ORDER BY lr.created_at DESC LIMIT 6
")->fetchAll();

// Recent new alumni
$recent_users = $pdo->query("SELECT name, email, is_verified, created_at FROM users WHERE role='alumni' ORDER BY created_at DESC LIMIT 5")->fetchAll();

$tracer_rate = $total_alumni > 0 ? round(($total_tracer / $total_alumni) * 100) : 0;
$verif_rate  = $total_alumni > 0 ? round(($verified_alumni / $total_alumni) * 100) : 0;
?>

<?php
// Global Settings
$stmt = $pdo->query("SELECT * FROM settings");
$raw_settings = $stmt->fetchAll();
$sys_settings = [];
foreach ($raw_settings as $s) {
    $sys_settings[$s->setting_key] = $s->setting_value;
}
$is_maintenance = ($sys_settings['maintenance_mode'] ?? '0') == '1';
?>

<div class="max-w-6xl mx-auto">
    <?php if ($is_maintenance): ?>
    <!-- Maintenance Banner -->
    <div class="mb-6 bg-red-50 border border-red-100 p-4 rounded-2xl flex items-center justify-between animate-pulse">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-red-500 text-white rounded-xl flex items-center justify-center">
                <i data-lucide="wrench" class="w-5 h-5"></i>
            </div>
            <div>
                <p class="text-xs font-black outfit text-red-600 uppercase tracking-widest">Maintenance Mode Aktif</p>
                <p class="text-[10px] text-red-400 font-bold">Akses untuk akun alumni saat ini sedang dinonaktifkan.</p>
            </div>
        </div>
        <?php // Banner ini tampil untuk seluruh peran staf, sedangkan Pengaturan
              // Sistem hanya dapat dibuka super_admin. Tanpa penjagaan ini,
              // peran lain menekan "Kelola" lalu ditolak. ?>
        <?php if (($_SESSION['user_role'] ?? '') === 'super_admin'): ?>
        <a href="index.php?page=admin_settings" class="px-4 py-2 bg-red-600 text-white text-[10px] font-black outfit rounded-xl hover:bg-red-700 transition-all uppercase tracking-widest">Kelola</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="mb-8 px-1">
        <h1 class="text-xl md:text-2xl font-bold outfit text-slate-800 tracking-tight">Dashboard Admin</h1>
        <p class="text-slate-400 text-xs md:text-sm mt-1 font-medium">Ringkasan aktivitas real-time AlumniLink.</p>
    </div>

    <!-- Stats Grid: 2-col mobile, 4-col desktop -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <!-- Alumni -->
        <div class="glass p-5 md:p-6 rounded-[1.5rem] md:rounded-[2rem] shadow-sm relative overflow-hidden group">
            <div class="absolute -right-4 -bottom-4 w-20 h-20 bg-blue-50 rounded-full group-hover:scale-125 transition-all"></div>
            <div class="relative z-10">
                <div class="w-10 h-10 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center mb-3">
                    <i data-lucide="users" class="w-5 h-5"></i>
                </div>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Total Alumni</p>
                <h2 class="text-3xl font-black outfit text-slate-800 mt-1"><?php echo $total_alumni; ?></h2>
                <div class="mt-2">
                    <div class="w-full bg-slate-100 rounded-full h-1.5">
                        <div class="bg-blue-500 h-1.5 rounded-full" style="width: <?php echo $verif_rate; ?>%"></div>
                    </div>
                    <p class="text-[10px] text-slate-400 mt-1"><?php echo $verified_alumni; ?> terverifikasi (<?php echo $verif_rate; ?>%)</p>
                </div>
                <?php if($pending_verif > 0): ?>
                <p class="text-[10px] text-orange-500 font-bold mt-1 flex items-center gap-1">
                    <i data-lucide="alert-circle" class="w-3 h-3"></i> <?php echo $pending_verif; ?> Pending
                </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Legalisir -->
        <div class="glass p-5 md:p-6 rounded-[1.5rem] md:rounded-[2rem] shadow-sm relative overflow-hidden group">
            <div class="absolute -right-4 -bottom-4 w-20 h-20 bg-purple-50 rounded-full group-hover:scale-125 transition-all"></div>
            <div class="relative z-10">
                <div class="w-10 h-10 bg-purple-100 text-purple-600 rounded-xl flex items-center justify-center mb-3">
                    <i data-lucide="clipboard-check" class="w-5 h-5"></i>
                </div>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Legalisir</p>
                <h2 class="text-3xl font-black outfit text-slate-800 mt-1"><?php echo $total_legalisir; ?></h2>
                <?php if($pending_leg > 0): ?>
                <p class="text-[10px] text-yellow-600 font-bold mt-2 flex items-center gap-1">
                    <i data-lucide="clock" class="w-3 h-3"></i> <?php echo $pending_leg; ?> Pending
                </p>
                <?php endif; ?>
                <?php if($processing_leg > 0): ?>
                <p class="text-[10px] text-blue-600 font-bold flex items-center gap-1">
                    <i data-lucide="loader" class="w-3 h-3"></i> <?php echo $processing_leg; ?> Diproses
                </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tracer -->
        <div class="glass p-5 md:p-6 rounded-[1.5rem] md:rounded-[2rem] shadow-sm relative overflow-hidden group">
            <div class="absolute -right-4 -bottom-4 w-20 h-20 bg-green-50 rounded-full group-hover:scale-125 transition-all"></div>
            <div class="relative z-10">
                <div class="w-10 h-10 bg-green-100 text-green-600 rounded-xl flex items-center justify-center mb-3">
                    <i data-lucide="bar-chart-3" class="w-5 h-5"></i>
                </div>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Tracer Alumni</p>
                <h2 class="text-3xl font-black outfit text-slate-800 mt-1"><?php echo $total_tracer; ?></h2>
                <div class="mt-2">
                    <div class="w-full bg-slate-100 rounded-full h-1.5">
                        <div class="bg-green-500 h-1.5 rounded-full" style="width: <?php echo $tracer_rate; ?>%"></div>
                    </div>
                    <p class="text-[10px] text-green-600 font-bold mt-1"><?php echo $tracer_rate; ?>% Respons Rate</p>
                </div>
            </div>
        </div>

        <!-- Col 4: Revenue (Super Admin / Keuangan) OR Informasi Sistem (Other Admins) -->
        <?php if (($_SESSION['user_role'] ?? '') === 'super_admin' || ($_SESSION['user_role'] ?? '') === 'keuangan'): ?>
            <!-- Revenue -->
            <div class="glass p-5 md:p-6 rounded-[1.5rem] md:rounded-[2rem] shadow-sm relative overflow-hidden group">
                <div class="absolute -right-4 -bottom-4 w-20 h-20 bg-yellow-50 rounded-full group-hover:scale-125 transition-all"></div>
                <div class="relative z-10">
                    <div class="w-10 h-10 bg-yellow-100 text-yellow-600 rounded-xl flex items-center justify-center mb-3">
                        <i data-lucide="banknote" class="w-5 h-5"></i>
                    </div>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Pendapatan</p>
                    <h2 class="text-xl font-black outfit text-slate-800 mt-1">Rp <?php echo number_format($total_revenue,0,',','.'); ?></h2>
                    <div class="mt-2 space-y-1">
                        <p class="text-[10px] text-slate-500 flex items-center gap-1">
                            <i data-lucide="credit-card" class="w-3 h-3"></i> Midtrans: Rp <?php echo number_format($midtrans_rev,0,',','.'); ?>
                        </p>
                        <p class="text-[10px] text-slate-500 flex items-center gap-1">
                            <i data-lucide="banknote" class="w-3 h-3"></i> Tunai: Rp <?php echo number_format($cash_revenue,0,',','.'); ?>
                        </p>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Informasi Sistem (Repo & News) -->
            <div class="glass p-5 md:p-6 rounded-[1.5rem] md:rounded-[2rem] shadow-sm relative overflow-hidden group">
                <div class="absolute -right-4 -bottom-4 w-20 h-20 bg-purple-50 rounded-full group-hover:scale-125 transition-all"></div>
                <div class="relative z-10">
                    <div class="w-10 h-10 bg-purple-100 text-purple-600 rounded-xl flex items-center justify-center mb-3">
                        <i data-lucide="folder-kanban" class="w-5 h-5"></i>
                    </div>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Informasi Sistem</p>
                    <h2 class="text-2xl font-black outfit text-slate-800 mt-1"><?php echo $total_news; ?> <span class="text-xs font-bold text-slate-400">Publikasi</span></h2>
                    <div class="mt-2 space-y-1">
                        <p class="text-[10px] text-slate-500 flex items-center gap-1 font-medium">
                            <i data-lucide="database" class="w-3 h-3 text-purple-500"></i> <?php echo $total_repo; ?> Dokumen Repositori
                        </p>
                        <p class="text-[10px] text-slate-500 flex items-center gap-1 font-medium">
                            <i data-lucide="newspaper" class="w-3 h-3 text-blue-500"></i> <?php echo $total_news; ?> Berita & Event Aktif
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Quick Actions -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <?php if (can_see_menu('admin_legalisir', $_SESSION['user_role'], $sidebar_permissions)): ?>
        <a href="index.php?page=admin_legalisir" class="glass p-4 rounded-2xl flex items-center gap-3 hover:bg-white/80 transition-all group">
            <div class="w-9 h-9 bg-purple-100 text-purple-600 rounded-xl flex items-center justify-center shrink-0">
                <i data-lucide="clipboard-list" class="w-4 h-4"></i>
            </div>
            <span class="text-sm font-bold text-slate-700">Kelola Legalisir</span>
        </a>
        <?php endif; ?>
        <?php if (can_see_menu('admin_alumni', $_SESSION['user_role'], $sidebar_permissions)): ?>
        <a href="index.php?page=admin_alumni" class="glass p-4 rounded-2xl flex items-center gap-3 hover:bg-white/80 transition-all group">
            <div class="w-9 h-9 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center shrink-0">
                <i data-lucide="user-check" class="w-4 h-4"></i>
            </div>
            <span class="text-sm font-bold text-slate-700">Database Alumni</span>
        </a>
        <?php endif; ?>
        <?php if (can_see_menu('admin_keuangan', $_SESSION['user_role'], $sidebar_permissions)): ?>
        <a href="index.php?page=admin_keuangan" class="glass p-4 rounded-2xl flex items-center gap-3 hover:bg-white/80 transition-all group">
            <div class="w-9 h-9 bg-yellow-100 text-yellow-600 rounded-xl flex items-center justify-center shrink-0">
                <i data-lucide="receipt" class="w-4 h-4"></i>
            </div>
            <span class="text-sm font-bold text-slate-700">Laporan Keuangan</span>
        </a>
        <?php endif; ?>
        <?php if (can_see_menu('admin_tracer', $_SESSION['user_role'], $sidebar_permissions)): ?>
        <a href="index.php?page=admin_tracer" class="glass p-4 rounded-2xl flex items-center gap-3 hover:bg-white/80 transition-all group">
            <div class="w-9 h-9 bg-green-100 text-green-600 rounded-xl flex items-center justify-center shrink-0">
                <i data-lucide="bar-chart-2" class="w-4 h-4"></i>
            </div>
            <span class="text-sm font-bold text-slate-700">Laporan Tracer</span>
        </a>
        <?php endif; ?>
        <?php if (can_see_menu('admin_users', $_SESSION['user_role'], $sidebar_permissions)): ?>
        <a href="index.php?page=admin_users" class="glass p-4 rounded-2xl flex items-center gap-3 hover:bg-white/80 transition-all group">
            <div class="w-9 h-9 bg-red-100 text-red-600 rounded-xl flex items-center justify-center shrink-0">
                <i data-lucide="users" class="w-4 h-4"></i>
            </div>
            <span class="text-sm font-bold text-slate-700">Kelola User</span>
        </a>
        <?php endif; ?>
        <?php if (can_see_menu('admin_broadcast', $_SESSION['user_role'], $sidebar_permissions)): ?>
        <a href="index.php?page=admin_broadcast" class="glass p-4 rounded-2xl flex items-center gap-3 hover:bg-white/80 transition-all group">
            <div class="w-9 h-9 bg-indigo-100 text-indigo-600 rounded-xl flex items-center justify-center shrink-0">
                <i data-lucide="megaphone" class="w-4 h-4"></i>
            </div>
            <span class="text-sm font-bold text-slate-700">Broadcast</span>
        </a>
        <?php endif; ?>
        <?php if (can_see_menu('admin_tracer_config', $_SESSION['user_role'], $sidebar_permissions)): ?>
        <a href="index.php?page=admin_tracer_config" class="glass p-4 rounded-2xl flex items-center gap-3 hover:bg-white/80 transition-all group">
            <div class="w-9 h-9 bg-blue-500 text-white rounded-xl flex items-center justify-center shrink-0 shadow-lg shadow-blue-200">
                <i data-lucide="list-checks" class="w-4 h-4"></i>
            </div>
            <span class="text-sm font-bold text-slate-700">Konfigurasi Tracer</span>
        </a>
        <?php endif; ?>
        <?php if (can_see_menu('admin_settings', $_SESSION['user_role'], $sidebar_permissions)): ?>
        <a href="index.php?page=admin_settings" class="glass p-4 rounded-2xl flex items-center gap-3 hover:bg-white/80 transition-all group">
            <div class="w-9 h-9 bg-slate-800 text-white rounded-xl flex items-center justify-center shrink-0 shadow-lg shadow-slate-200">
                <i data-lucide="settings" class="w-4 h-4"></i>
            </div>
            <span class="text-sm font-bold text-slate-700">Konfigurasi Sistem</span>
        </a>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Recent Legalisir Activity -->
        <div class="glass p-6 md:p-8 rounded-[2rem] shadow-sm">
            <div class="flex justify-between items-center mb-6">
                <h4 class="text-base md:text-lg font-bold outfit text-slate-800">Aktivitas Legalisir</h4>
                <?php // Disembunyikan bila peran tidak berwenang membuka halamannya,
                      // agar tautan ini tidak berujung pada penolakan akses. ?>
                <?php if (can_see_menu('admin_legalisir', $_SESSION['user_role'], $sidebar_permissions)): ?>
                <a href="index.php?page=admin_legalisir" class="text-xs font-bold text-blue-600 hover:underline">Lihat Semua</a>
                <?php endif; ?>
            </div>
            <div class="space-y-3">
                <?php 
                $sColors = ['pending'=>'bg-yellow-100 text-yellow-700','processing'=>'bg-blue-100 text-blue-700','completed'=>'bg-green-100 text-green-700','rejected'=>'bg-red-100 text-red-700'];
                $sLabels = ['pending'=>'Pending','processing'=>'Proses','completed'=>'Selesai','rejected'=>'Batal'];
                foreach ($recent_legalisir as $rl): 
                ?>
                <div class="flex items-center justify-between p-3 rounded-2xl hover:bg-white/40 transition-all">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 bg-purple-50 text-purple-600 rounded-xl flex items-center justify-center shrink-0">
                            <i data-lucide="file-text" class="w-4 h-4"></i>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-slate-800 leading-tight"><?php echo htmlspecialchars($rl->user_name); ?></p>
                            <p class="text-[10px] text-slate-400">Rp <?php echo number_format($rl->amount,0,',','.'); ?> &bull; <?php echo date('d M', strtotime($rl->created_at)); ?></p>
                        </div>
                    </div>
                    <span class="text-[10px] font-bold px-2 py-1 rounded-lg <?php echo e($sColors[$rl->status] ?? 'bg-slate-100 text-slate-500'); ?>">
                        <?php echo $sLabels[$rl->status] ?? ucfirst($rl->status); ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Recent Alumni -->
        <div class="glass p-6 md:p-8 rounded-[2rem] shadow-sm">
            <div class="flex justify-between items-center mb-6">
                <h4 class="text-base md:text-lg font-bold outfit text-slate-800">Alumni Terbaru</h4>
                <?php if (can_see_menu('admin_alumni', $_SESSION['user_role'], $sidebar_permissions)): ?>
                <a href="index.php?page=admin_alumni" class="text-xs font-bold text-blue-600 hover:underline">Lihat Semua</a>
                <?php endif; ?>
            </div>
            <div class="space-y-3">
                <?php foreach ($recent_users as $ru): ?>
                <div class="flex items-center justify-between p-3 rounded-2xl hover:bg-white/40 transition-all">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 bg-blue-600 rounded-xl flex items-center justify-center text-white font-bold text-sm shrink-0">
                            <?php echo strtoupper(substr($ru->name, 0, 2)); ?>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-slate-800 leading-tight"><?php echo htmlspecialchars($ru->name); ?></p>
                            <p class="text-[10px] text-slate-400"><?php echo date('d M Y, H:i', strtotime($ru->created_at)); ?></p>
                        </div>
                    </div>
                    <?php if($ru->is_verified): ?>
                    <span class="text-[10px] font-bold px-2 py-1 rounded-lg bg-green-100 text-green-700">Verified</span>
                    <?php else: ?>
                    <span class="text-[10px] font-bold px-2 py-1 rounded-lg bg-yellow-100 text-yellow-700">Pending</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
