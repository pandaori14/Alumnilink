<?php
// Determine Role
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$is_admin = $_SESSION['user_role'] !== 'alumni';

// Fetch latest broadcast (only for App channel notifications)
$broadcast = $pdo->query("SELECT * FROM broadcasts WHERE channels IS NULL OR FIND_IN_SET('app', channels) ORDER BY created_at DESC LIMIT 1")->fetch();

// Fetch all settings for WA numbers etc
$stmt = $pdo->query("SELECT * FROM settings");
$raw_settings = $stmt->fetchAll();
$settings = [];
foreach ($raw_settings as $s) {
    $settings[$s->setting_key] = $s->setting_value;
}

if ($is_admin) {
    // ---- ADMIN STATS ----
    // 1. Total Alumni
    $stat1_label = 'Total Alumni';
    $total_alumni = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'alumni'")->fetchColumn();
    $verified_alumni = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'alumni' AND is_verified = 1")->fetchColumn();
    $stat1_val = number_format($total_alumni);
    $stat1_sub = $verified_alumni . ' Terverifikasi';
    $stat1_icon = 'users'; $stat1_color = 'blue';

    // 2. Legalisir Status
    $stat2_label = 'Legalisir';
    $leg_pending = $pdo->query("SELECT COUNT(*) FROM legalisir_requests WHERE status = 'pending'")->fetchColumn();
    $leg_process = $pdo->query("SELECT COUNT(*) FROM legalisir_requests WHERE status = 'processing'")->fetchColumn();
    $stat2_val = number_format($pdo->query("SELECT COUNT(*) FROM legalisir_requests")->fetchColumn());
    $stat2_sub = "<span class='text-amber-500'>$leg_pending Pending</span> • <span class='text-blue-500'>$leg_process Proses</span>";
    $stat2_icon = 'award'; $stat2_color = 'indigo';

    // 3. Tracer Alumni
    $stat3_label = 'Tracer Alumni';
    $tracer_total = $pdo->query("SELECT COUNT(*) FROM tracer_submissions")->fetchColumn();
    $tracer_rate = $total_alumni > 0 ? round(($tracer_total / $total_alumni) * 100) : 0;
    $stat3_val = number_format($tracer_total);
    $stat3_sub = $tracer_rate . '% Respons Rate';
    $stat3_icon = 'bar-chart-3'; $stat3_color = 'emerald';

    // 4. Pendapatan (Special Card)
    $income_midtrans = $pdo->query("SELECT SUM(amount) FROM legalisir_requests WHERE payment_status = 'settlement' AND payment_method != 'cash'")->fetchColumn() ?? 0;
    $income_cash = $pdo->query("SELECT SUM(amount) FROM legalisir_requests WHERE payment_status = 'settlement' AND payment_method = 'cash'")->fetchColumn() ?? 0;
    $total_income = $income_midtrans + $income_cash;

    // Recent Global Activity
    $recent_activities = $pdo->query("
        SELECT 'legalisir' as type, lr.id as ref_id, u.name as actor, lr.status, lr.created_at 
        FROM legalisir_requests lr 
        JOIN users u ON lr.user_id = u.id
        UNION ALL 
        SELECT 'tracer' as type, ts.id as ref_id, u.name as actor, 'submitted' as status, ts.created_at 
        FROM tracer_submissions ts
        JOIN users u ON ts.user_id = u.id
        ORDER BY created_at DESC LIMIT 5
    ")->fetchAll();

} else {
    // ---- ALUMNI STATS ----
    // 1. Legalisir
    $stat1_label = 'Total Pengajuan Legalisir';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM legalisir_requests WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $stat1_val = $stmt->fetchColumn();
    $stat1_sub = 'Lihat riwayat pengajuan';
    $stat1_icon = 'file-text'; $stat1_color = 'blue';

    // 2. Tracer
    $stmt = $pdo->prepare("SELECT is_verified, last_tracer_update FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch();
    
    $tracer_date = $user_data->last_tracer_update ?? null;
    $six_months_ago = tracer_validity_threshold(); // dari settings.tracer_validity_months
    $needs_tracer = !$tracer_date || $tracer_date < $six_months_ago;

    $stat2_label = 'Status Tracer Alumni';
    $stat2_val = $needs_tracer ? 'Perlu Update' : 'Aman';
    $stat2_sub = $needs_tracer ? 'Wajib isi setiap 6 bulan' : 'Terakhir: ' . ($tracer_date ? date('d/m/Y', strtotime($tracer_date)) : '-');
    $stat2_icon = 'pie-chart'; $stat2_color = $needs_tracer ? 'red' : 'green';

    // 3. Verification
    $stat3_label = 'Status Akun';
    $stat3_val = $user_data->is_verified ? 'Terverifikasi' : 'Menunggu';
    $stat3_sub = $user_data->is_verified ? 'Akses layanan penuh aktif' : 'Sedang ditinjau Admin';
    $stat3_icon = $user_data->is_verified ? 'shield-check' : 'shield-alert'; 
    $stat3_color = $user_data->is_verified ? 'green' : 'orange';

    // Recent Personal Activity
    $stmt = $pdo->prepare("
        SELECT 'legalisir' as type, lr.id as ref_id, u.name as actor, lr.status, lr.created_at 
        FROM legalisir_requests lr 
        JOIN users u ON lr.user_id = u.id
        WHERE lr.user_id = ?
        ORDER BY lr.created_at DESC LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $recent_activities = $stmt->fetchAll();
}

// Success message check
$success_msg = '';
if (isset($_GET['success'])) {
    if ($_GET['success'] == 'tracer') {
        $success_msg = 'Data Tracer Alumni berhasil disimpan. Terima kasih atas partisipasinya!';
    } else if ($_GET['success'] == 'welcome') {
        $success_msg = 'Selamat datang di AlumniLink, ' . htmlspecialchars($user_name) . '!';
    }
}

// Format time ago helper
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $w = (int)floor($diff->d / 7);
    $d = $diff->d - ($w * 7);

    $diff_vals = [
        'y' => $diff->y,
        'm' => $diff->m,
        'w' => $w,
        'd' => $d,
        'h' => $diff->h,
        'i' => $diff->i,
        's' => $diff->s
    ];

    $string = ['y' => 'tahun','m' => 'bulan','w' => 'minggu','d' => 'hari','h' => 'jam','i' => 'menit','s' => 'detik'];
    foreach ($string as $k => &$v) {
        if ($diff_vals[$k]) { 
            $v = $diff_vals[$k] . ' ' . $v; 
        } else { 
            unset($string[$k]); 
        }
    }
    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' yang lalu' : 'baru saja';
}
?>

<?php if ($success_msg): ?>
<div class="mb-8 p-4 bg-green-50 border border-green-200 text-green-700 rounded-2xl flex items-center gap-3 animate-pulse shadow-sm">
    <i data-lucide="check-circle" class="w-5 h-5"></i>
    <span class="font-medium"><?php echo e($success_msg); ?></span>
</div>
<?php endif; ?>

<?php if ($broadcast): ?>
    <div class="bg-blue-600 rounded-[2.5rem] p-8 mb-10 shadow-xl shadow-blue-200 relative overflow-hidden group">
        <div class="absolute -right-10 -bottom-10 w-40 h-40 bg-white/10 rounded-full group-hover:scale-125 transition-all"></div>
        <div class="relative z-10 flex flex-col md:flex-row items-center gap-6">
            <div class="w-16 h-16 bg-white/20 rounded-2xl flex items-center justify-center text-white shrink-0 backdrop-blur-sm">
                <i data-lucide="megaphone" class="w-8 h-8"></i>
            </div>
            <div class="flex-1 text-center md:text-left">
                <h3 class="text-xl font-bold text-white outfit"><?php echo htmlspecialchars($broadcast->title); ?></h3>
                <p class="text-blue-100 text-sm mt-1 line-clamp-2"><?php echo nl2br(htmlspecialchars($broadcast->message)); ?></p>
                <button onclick="viewBroadcastDetails(<?php echo e($broadcast->id); ?>)" class="mt-3 px-4 py-1.5 bg-white/25 hover:bg-white/35 text-white rounded-xl text-xs font-black transition-all inline-flex items-center gap-1.5 shadow-sm">
                    <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                    Lihat Selengkapnya
                </button>
            </div>
            <div class="text-[10px] font-bold text-blue-200 uppercase tracking-widest self-start md:self-center shrink-0">
                <?php echo date('d M Y', strtotime($broadcast->created_at)); ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Welcome Message -->
<div class="mb-10 px-2 md:px-0">
    <h1 class="text-3xl font-black outfit text-slate-800 tracking-tight">Halo, <?php echo $is_admin ? 'Admin' : explode(' ', $user_name)[0]; ?>!</h1>
    <p class="text-slate-500 font-medium"><?php echo $is_admin ? 'Ringkasan aktivitas sistem hari ini.' : 'Selamat datang kembali di portal AlumniLink.'; ?></p>
</div>

<!-- Stats Grid: 2-col mobile, 3-col desktop -->
<div class="grid grid-cols-2 md:grid-cols-3 gap-6 mb-8 px-2 md:px-0">
    <!-- Stat 1 -->
    <div class="glass p-6 md:p-8 rounded-[2.5rem] border border-white shadow-sm relative overflow-hidden group">
        <div class="absolute -right-6 -bottom-6 w-24 h-24 bg-<?php echo e($stat1_color); ?>-500/5 rounded-full group-hover:scale-150 transition-all duration-700"></div>
        <div class="w-12 h-12 bg-<?php echo e($stat1_color); ?>-50 text-<?php echo e($stat1_color); ?>-600 rounded-2xl flex items-center justify-center mb-5 shadow-inner">
            <i data-lucide="<?php echo e($stat1_icon); ?>" class="w-6 h-6"></i>
        </div>
        <h3 class="text-slate-400 text-[10px] font-black uppercase tracking-[0.2em] mb-1"><?php echo e($stat1_label); ?></h3>
        <p class="text-3xl font-black text-slate-800 outfit mb-2"><?php echo e($stat1_val); ?></p>
        <p class="text-[10px] font-bold text-<?php echo e($stat1_color); ?>-600/60 uppercase tracking-wider"><?php echo e($stat1_sub); ?></p>
    </div>

    <!-- Stat 2 -->
    <div class="glass p-6 md:p-8 rounded-[2.5rem] border border-white shadow-sm relative overflow-hidden group">
        <div class="absolute -right-6 -bottom-6 w-24 h-24 bg-<?php echo e($stat2_color); ?>-500/5 rounded-full group-hover:scale-150 transition-all duration-700"></div>
        <div class="w-12 h-12 bg-<?php echo e($stat2_color); ?>-50 text-<?php echo e($stat2_color); ?>-600 rounded-2xl flex items-center justify-center mb-5 shadow-inner">
            <i data-lucide="<?php echo e($stat2_icon); ?>" class="w-6 h-6"></i>
        </div>
        <h3 class="text-slate-400 text-[10px] font-black uppercase tracking-[0.2em] mb-1"><?php echo e($stat2_label); ?></h3>
        <p class="text-3xl font-black text-slate-800 outfit mb-2"><?php echo e($stat2_val); ?></p>
        <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider"><?php echo e($stat2_sub); ?></p>
    </div>

    <!-- Stat 3 -->
    <div class="glass p-6 md:p-8 rounded-[2.5rem] border border-white shadow-sm relative overflow-hidden group col-span-2 md:col-span-1">
        <div class="absolute -right-6 -bottom-6 w-24 h-24 bg-<?php echo e($stat3_color); ?>-500/5 rounded-full group-hover:scale-150 transition-all duration-700"></div>
        <div class="w-12 h-12 bg-<?php echo e($stat3_color); ?>-50 text-<?php echo e($stat3_color); ?>-600 rounded-2xl flex items-center justify-center mb-5 shadow-inner">
            <i data-lucide="<?php echo e($stat3_icon); ?>" class="w-6 h-6"></i>
        </div>
        <h3 class="text-slate-400 text-[10px] font-black uppercase tracking-[0.2em] mb-1"><?php echo e($stat3_label); ?></h3>
        <p class="text-3xl font-black text-slate-800 outfit mb-2"><?php echo e($stat3_val); ?></p>
        <p class="text-[10px] font-bold text-<?php echo e($stat3_color); ?>-600/60 uppercase tracking-wider"><?php echo e($stat3_sub); ?></p>
    </div>

    <?php if($is_admin): ?>
    <!-- Income Card (Admin Only) -->
    <div class="col-span-2 md:col-span-3 glass p-8 md:p-10 rounded-[3rem] border border-white shadow-sm relative overflow-hidden group">
        <div class="absolute -right-10 -top-10 w-40 h-40 bg-amber-500/5 rounded-full group-hover:scale-150 transition-all duration-1000"></div>
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-8">
            <div class="flex items-center gap-6">
                <div class="w-16 h-16 bg-amber-50 text-amber-600 rounded-[1.5rem] flex items-center justify-center shadow-inner shrink-0">
                    <i data-lucide="wallet" class="w-8 h-8"></i>
                </div>
                <div>
                    <h3 class="text-slate-400 text-[10px] font-black uppercase tracking-[0.2em] mb-1">Total Pendapatan Legalisir</h3>
                    <p class="text-4xl font-black text-slate-800 outfit tracking-tight">Rp <?php echo number_format($total_income, 0, ',', '.'); ?></p>
                </div>
            </div>
            <div class="flex flex-wrap gap-4 w-full md:w-auto">
                <div class="flex-1 md:flex-none px-6 py-4 bg-white/50 rounded-2xl border border-slate-100 text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">Midtrans</p>
                    <p class="text-sm font-bold text-blue-600 outfit">Rp <?php echo number_format($income_midtrans, 0, ',', '.'); ?></p>
                </div>
                <div class="flex-1 md:flex-none px-6 py-4 bg-white/50 rounded-2xl border border-slate-100 text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">Tunai (Cash)</p>
                    <p class="text-sm font-bold text-emerald-600 outfit">Rp <?php echo number_format($income_cash, 0, ',', '.'); ?></p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-8 px-2 md:px-0">
    <!-- Activities -->
    <div class="glass p-10 rounded-[3rem] border border-white shadow-sm">
        <div class="flex items-center justify-between mb-10">
            <h3 class="text-xl font-black outfit text-slate-800 uppercase tracking-tight"><?php echo $is_admin ? 'Aktivitas Terbaru' : 'Riwayat Anda'; ?></h3>
            <div class="w-10 h-10 bg-slate-50 rounded-xl flex items-center justify-center text-slate-300">
                <i data-lucide="history" class="w-5 h-5"></i>
            </div>
        </div>
        <?php if(empty($recent_activities)): ?>
            <div class="py-10 text-center">
                <p class="text-slate-400 italic text-sm">Belum ada aktivitas terekam.</p>
            </div>
        <?php else: ?>
            <div class="space-y-8">
                <?php foreach($recent_activities as $act): 
                    $is_leg = $act->type === 'legalisir';
                    $color = $is_leg ? 'blue' : 'purple';
                    $title = $is_leg ? 'Layanan Legalisir' : 'Tracer Alumni';
                    $status_text = strtoupper($act->status);
                ?>
                <div class="flex gap-6 items-start group">
                    <div class="relative pt-1">
                        <div class="w-3 h-3 bg-<?php echo e($color); ?>-500 rounded-full shadow-[0_0_10px_rgba(59,130,246,0.5)] group-hover:scale-125 transition-transform"></div>
                        <div class="absolute top-4 bottom-[-32px] left-[5px] w-[2px] bg-slate-100 group-last:hidden"></div>
                    </div>
                    <div class="flex-1">
                        <div class="flex justify-between items-start gap-4">
                            <p class="font-black text-slate-800 text-sm leading-tight"><?php echo e($title); ?></p>
                            <span class="px-3 py-1 rounded-full bg-<?php echo e($color); ?>-50 text-<?php echo e($color); ?>-600 text-[8px] font-black tracking-widest"><?php echo e($status_text); ?></span>
                        </div>
                        <p class="text-xs text-slate-500 mt-2 font-medium">
                            <span class="text-slate-800 font-bold"><?php echo htmlspecialchars($act->actor); ?></span> 
                            <span class="mx-2 text-slate-300">•</span>
                            <?php echo e(time_elapsed_string($act->created_at)); ?>
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Quick Actions -->
    <div class="space-y-6">
        <div class="glass p-10 rounded-[3rem] border border-white shadow-sm">
            <h3 class="text-xl font-black outfit text-slate-800 uppercase tracking-tight mb-8">Layanan Cepat</h3>
            <div class="grid grid-cols-2 gap-4">
                <?php if($is_admin): ?>
                    <a href="index.php?page=admin_legalisir" class="flex flex-col items-center justify-center p-8 bg-slate-900 text-white rounded-[2rem] hover:bg-blue-600 transition-all shadow-xl shadow-slate-900/10 group text-center">
                        <div class="w-12 h-12 bg-white/10 rounded-2xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                            <i data-lucide="award" class="w-6 h-6"></i>
                        </div>
                        <span class="text-xs font-black uppercase tracking-widest">Kelola Legalisir</span>
                    </a>
                    <a href="index.php?page=admin_tracer" class="flex flex-col items-center justify-center p-8 bg-white border border-slate-100 rounded-[2rem] hover:border-blue-200 transition-all shadow-sm group text-center">
                        <div class="w-12 h-12 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform shadow-inner">
                            <i data-lucide="bar-chart-3" class="w-6 h-6"></i>
                        </div>
                        <span class="text-xs font-black uppercase tracking-widest text-slate-600">Data Tracer</span>
                    </a>
                <?php else: ?>
                    <a href="index.php?page=legalisir" class="flex flex-col items-center justify-center p-8 bg-blue-600 text-white rounded-[2rem] hover:bg-blue-700 transition-all shadow-xl shadow-blue-200 group text-center">
                        <div class="w-12 h-12 bg-white/10 rounded-2xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                            <i data-lucide="award" class="w-6 h-6"></i>
                        </div>
                        <span class="text-xs font-black uppercase tracking-widest">Ajukan Legalisir</span>
                    </a>
                    <a href="index.php?page=tracer" class="flex flex-col items-center justify-center p-8 bg-white border border-slate-100 rounded-[2rem] hover:border-blue-200 transition-all shadow-sm group text-center">
                        <div class="w-12 h-12 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform shadow-inner">
                            <i data-lucide="file-search" class="w-6 h-6"></i>
                        </div>
                        <span class="text-xs font-black uppercase tracking-widest text-slate-600">Isi Tracer Alumni</span>
                    </a>
                <?php endif; ?>

                <a href="index.php?page=donasi" class="col-span-2 flex items-center justify-between p-6 bg-white border border-slate-100 rounded-[2rem] hover:border-blue-200 transition-all shadow-sm group">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-pink-50 text-pink-600 rounded-2xl flex items-center justify-center shadow-inner group-hover:scale-110 transition-transform">
                            <i data-lucide="heart" class="w-6 h-6"></i>
                        </div>
                        <span class="text-xs font-black uppercase tracking-widest text-slate-600">Donasi Alumni</span>
                    </div>
                    <i data-lucide="chevron-right" class="w-5 h-5 text-slate-300 group-hover:translate-x-1 transition-transform"></i>
                </a>
            </div>
        </div>

        <!-- Help Section with Dynamic WA Buttons -->
        <div class="space-y-4">
            <div class="bg-gradient-to-br from-blue-600 to-indigo-700 p-8 rounded-[3rem] shadow-xl shadow-blue-200 relative overflow-hidden group">
                <div class="absolute -right-10 -bottom-10 w-40 h-40 bg-white/10 rounded-full group-hover:scale-150 transition-all duration-1000"></div>
                <div class="relative z-10">
                    <h4 class="text-white font-black outfit text-lg uppercase tracking-tight">Butuh Bantuan?</h4>
                    <p class="text-blue-100 text-xs mt-1">Hubungi admin kami untuk kendala layanan portal AlumniLink.</p>
                    
                    <div class="grid grid-cols-1 gap-2 mt-6">
                        <?php 
                        $wa_contacts = [
                            ['key' => 'wa_tracer', 'label' => 'Admin Tracer', 'icon' => 'bar-chart-3'],
                            ['key' => 'wa_legalisir', 'label' => 'Admin Legalisir', 'icon' => 'award'],
                            ['key' => 'wa_itsupport', 'label' => 'IT Support', 'icon' => 'cpu']
                        ];
                        
                        foreach($wa_contacts as $contact):
                            $wa_num = $settings[$contact['key']] ?? '';
                            if ($wa_num): 
                                // Clean number: remove spaces, dashes, etc
                                $wa_num = preg_replace('/[^0-9]/', '', $wa_num);
                        ?>
                            <a href="https://wa.me/<?php echo e($wa_num); ?>" target="_blank" 
                               class="flex items-center gap-3 bg-white/10 hover:bg-white/20 border border-white/20 p-3 rounded-2xl transition-all group/wa">
                                <div class="w-8 h-8 bg-white/20 rounded-xl flex items-center justify-center text-white group-hover/wa:scale-110 transition-transform">
                                    <i data-lucide="<?php echo e($contact['icon']); ?>" class="w-4 h-4"></i>
                                </div>
                                <span class="text-xs font-bold text-white"><?php echo e($contact['label']); ?></span>
                                <i data-lucide="external-link" class="w-3 h-3 text-white/40 ml-auto"></i>
                            </a>
                        <?php endif; endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
