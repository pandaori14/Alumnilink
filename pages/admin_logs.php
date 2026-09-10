<?php
/**
 * Admin Activity Logs Page
 * Premium Audit Trail View
 */

// Admin check
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'alumni') {
    ?>
    <div class="max-w-2xl mx-auto px-4 py-16 text-center">
        <div class="glass p-12 rounded-[3rem] border border-white shadow-xl relative overflow-hidden">
            <div class="absolute -right-10 -top-10 w-40 h-40 bg-red-50 rounded-full blur-2xl pointer-events-none"></div>
            <div class="w-24 h-24 bg-red-100 text-red-600 rounded-3xl flex items-center justify-center mx-auto mb-8 shadow-inner border border-red-200">
                <i data-lucide="shield-alert" class="w-12 h-12"></i>
            </div>
            <h2 class="text-3xl font-black outfit text-slate-800 mb-4 tracking-tight">Akses Ditolak</h2>
            <p class="text-slate-500 mb-8 max-w-md mx-auto leading-relaxed text-sm">Maaf, halaman Audit Trail ini hanya dapat diakses oleh akun administrator.</p>
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

// Pagination logic
$page_num = isset($_GET['p']) ? (int)$_GET['p'] : 1;
$limit = pagination_size(20); // dari settings.pagination_size
$offset = ($page_num - 1) * $limit;

// Search filter
$search = $_GET['search'] ?? '';
$where = "";
$params = [];
if ($search) {
    $where = "WHERE name LIKE ? OR action LIKE ? OR description LIKE ? OR ip_address LIKE ? OR role LIKE ?";
    $params = ["%$search%", "%$search%", "%$search%", "%$search%", "%$search%"];
}

$stmt = $pdo->prepare("SELECT * FROM activity_logs $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs $where");
$total_stmt->execute($params);
$total_logs = $total_stmt->fetchColumn();
$total_pages = ceil($total_logs / $limit);
?>

<div class="max-w-7xl mx-auto">
    <div class="mb-10 px-1 flex flex-col md:flex-row md:items-end justify-between gap-6">
        <div>
            <h1 class="text-2xl md:text-3xl font-black outfit text-slate-800 tracking-tight flex items-center gap-3">
                <span class="w-2 h-10 bg-blue-600 rounded-full"></span>
                Audit Trail
            </h1>
            <p class="text-slate-400 text-xs md:text-sm mt-1 font-medium italic">Memantau seluruh aktivitas sistem dan keamanan secara real-time.</p>
        </div>
        
        <form method="GET" class="relative group w-full md:w-80">
            <input type="hidden" name="page" value="admin_logs">
            <i data-lucide="search" class="absolute left-5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 group-focus-within:text-blue-600 transition-colors"></i>
            <input aria-label="Cari user, aksi, atau IP" type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                   placeholder="Cari user, aksi, atau IP..." 
                   class="w-full pl-12 pr-6 py-4 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 transition-all outline-none font-medium text-sm">
        </form>
    </div>

    <div class="glass rounded-[2.5rem] overflow-hidden border border-white shadow-xl shadow-slate-200/50">
        <!-- Desktop Table View -->
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50/50 border-b border-slate-100">
                        <th class="px-8 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Waktu</th>
                        <th class="px-8 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">User & Role</th>
                        <th class="px-8 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Aksi</th>
                        <th class="px-8 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Detail Aktivitas</th>
                        <th class="px-8 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Teknis (IP & Device)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" class="px-8 py-20 text-center">
                                <div class="flex flex-col items-center gap-4">
                                    <div class="w-16 h-16 bg-slate-50 rounded-3xl flex items-center justify-center">
                                        <i data-lucide="history" class="w-8 h-8 text-slate-200"></i>
                                    </div>
                                    <p class="text-slate-400 font-medium">Belum ada catatan aktivitas yang ditemukan.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($logs as $log):
                        $is_security_alert = ($log->action === 'SECURITY_ALERT');
                    ?>
                        <tr class="<?php echo $is_security_alert ? 'bg-red-50/60 border-l-4 border-l-red-500 hover:bg-red-50/80' : 'hover:bg-blue-50/30'; ?> transition-colors group">
                            <td class="px-8 py-6 whitespace-nowrap">
                                <p class="text-xs font-bold text-slate-800"><?php echo date('d M Y', strtotime($log->created_at)); ?></p>
                                <p class="text-[10px] font-medium text-slate-400"><?php echo date('H:i:s', strtotime($log->created_at)); ?> WIB</p>
                            </td>
                            <td class="px-8 py-6">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 <?php echo $is_security_alert ? 'bg-red-100 border-red-200' : 'bg-white border-slate-100'; ?> border rounded-xl flex items-center justify-center shadow-sm">
                                        <i data-lucide="<?php echo $is_security_alert ? 'shield-alert' : 'user'; ?>" class="w-4 h-4 <?php echo $is_security_alert ? 'text-red-500' : 'text-slate-400'; ?>"></i>
                                    </div>
                                    <div>
                                        <p class="text-xs font-black text-slate-800 leading-none mb-1"><?php echo htmlspecialchars($log->name ?? ''); ?></p>
                                        <span class="text-[9px] px-2 py-0.5 bg-slate-100 text-slate-500 rounded-md font-bold uppercase tracking-tighter">
                                            <?php echo htmlspecialchars($log->role ?? ''); ?>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-8 py-6">
                                <?php
                                    $action_colors = [
                                        'LOGIN_SUCCESS' => 'bg-emerald-100 text-emerald-600',
                                        'LOGIN_FAILED'  => 'bg-red-100 text-red-600',
                                        'UPDATE_SETTINGS' => 'bg-blue-100 text-blue-600',
                                        'LOGOUT' => 'bg-slate-100 text-slate-500',
                                        'SECURITY_ALERT' => 'bg-red-500 text-white animate-pulse',
                                        'RATE_LIMIT_BLOCKED' => 'bg-amber-100 text-amber-700',
                                        'UNAUTHORIZED_ACCESS' => 'bg-orange-100 text-orange-700'
                                    ];
                                    $color = $action_colors[$log->action] ?? 'bg-indigo-100 text-indigo-600';
                                ?>
                                <span class="px-3 py-1.5 rounded-xl text-[10px] font-black <?php echo e($color); ?> border border-white shadow-sm">
                                    <?php echo e(str_replace('_', ' ', $log->action)); ?>
                                </span>
                            </td>
                            <td class="px-8 py-6">
                                <p class="text-xs text-slate-500 leading-relaxed max-w-xs"><?php echo htmlspecialchars($log->description ?? ''); ?></p>
                            </td>
                             <td class="px-8 py-6">
                                 <div class="space-y-1.5">
                                     <div class="flex items-center gap-2">
                                         <i data-lucide="globe" class="w-3.5 h-3.5 text-slate-400 shrink-0"></i>
                                         <span class="text-[10px] font-mono font-bold text-slate-600"><?php echo htmlspecialchars($log->ip_address ?? ''); ?></span>
                                     </div>
                                     <div class="flex items-center gap-2">
                                         <i data-lucide="map-pin" class="w-3.5 h-3.5 text-rose-400 shrink-0"></i>
                                         <span class="text-[10px] font-medium text-slate-500"><?php echo htmlspecialchars($log->location ?? 'Lokasi Tidak Diketahui'); ?></span>
                                     </div>
                                     <div class="flex items-center gap-2">
                                         <i data-lucide="<?php echo e((($log->device_type ?? '') == 'Mobile') ? 'smartphone' : ((($log->device_type ?? '') == 'Tablet') ? 'tablet' : 'monitor')); ?>" class="w-3.5 h-3.5 text-blue-400 shrink-0"></i>
                                         <span class="text-[10px] font-medium text-slate-500">
                                             <?php echo htmlspecialchars($log->device_type ?? 'Unknown'); ?> 
                                             <span class="text-slate-400 font-normal">(<?php echo htmlspecialchars(get_ua_details($log->user_agent ?? '')); ?>)</span>
                                         </span>
                                     </div>
                                 </div>
                             </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Card View -->
        <div class="md:hidden divide-y divide-slate-100">
            <?php if (empty($logs)): ?>
                <div class="px-8 py-20 text-center">
                    <p class="text-slate-400 font-medium italic">Belum ada catatan aktivitas.</p>
                </div>
            <?php endif; ?>

            <?php foreach ($logs as $log):
                $is_security_alert = ($log->action === 'SECURITY_ALERT');
            ?>
                <div class="p-6 space-y-4 <?php echo $is_security_alert ? 'bg-red-50/60 border-l-4 border-l-red-500' : ''; ?>">
                    <div class="flex justify-between items-start">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 <?php echo $is_security_alert ? 'bg-red-100 border-red-200' : 'bg-slate-50 border-slate-100'; ?> rounded-2xl flex items-center justify-center border">
                                <i data-lucide="<?php echo $is_security_alert ? 'shield-alert' : 'user'; ?>" class="w-5 h-5 <?php echo $is_security_alert ? 'text-red-500' : 'text-slate-400'; ?>"></i>
                            </div>
                            <div>
                                <p class="text-sm font-black text-slate-800"><?php echo htmlspecialchars($log->name ?? ''); ?></p>
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest"><?php echo htmlspecialchars($log->role ?? ''); ?></p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] font-black text-slate-800"><?php echo date('H:i', strtotime($log->created_at)); ?></p>
                            <p class="text-[8px] font-bold text-slate-400"><?php echo date('d M Y', strtotime($log->created_at)); ?></p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <?php
                            $action_colors = [
                                'LOGIN_SUCCESS' => 'bg-emerald-100 text-emerald-600',
                                'LOGIN_FAILED'  => 'bg-red-100 text-red-600',
                                'UPDATE_SETTINGS' => 'bg-blue-100 text-blue-600',
                                'LOGOUT' => 'bg-slate-100 text-slate-500',
                                'SECURITY_ALERT' => 'bg-red-500 text-white animate-pulse',
                                'RATE_LIMIT_BLOCKED' => 'bg-amber-100 text-amber-700',
                                'UNAUTHORIZED_ACCESS' => 'bg-orange-100 text-orange-700'
                            ];
                            $color = $action_colors[$log->action] ?? 'bg-indigo-100 text-indigo-600';
                        ?>
                        <span class="px-2.5 py-1 rounded-lg text-[9px] font-black <?php echo e($color); ?> border border-white shadow-sm">
                            <?php echo e(str_replace('_', ' ', $log->action)); ?>
                        </span>
                    </div>

                    <p class="text-xs text-slate-600 font-medium leading-relaxed bg-slate-50/50 p-3 rounded-xl border border-slate-100">
                        <?php echo htmlspecialchars($log->description ?? ''); ?>
                    </p>
 
                     <div class="pt-3 border-t border-dashed border-slate-100 grid grid-cols-1 gap-2">
                         <div class="flex items-center gap-2">
                             <i data-lucide="globe" class="w-3.5 h-3.5 text-slate-400 shrink-0"></i>
                             <span class="text-[10px] font-mono font-bold text-slate-500"><?php echo htmlspecialchars($log->ip_address ?? ''); ?></span>
                         </div>
                         <div class="flex items-center gap-2">
                             <i data-lucide="map-pin" class="w-3.5 h-3.5 text-rose-400 shrink-0"></i>
                             <span class="text-[10px] font-medium text-slate-500"><?php echo htmlspecialchars($log->location ?? 'Lokasi Tidak Diketahui'); ?></span>
                         </div>
                         <div class="flex items-center gap-2">
                             <i data-lucide="<?php echo e((($log->device_type ?? '') == 'Mobile') ? 'smartphone' : ((($log->device_type ?? '') == 'Tablet') ? 'tablet' : 'monitor')); ?>" class="w-3.5 h-3.5 text-blue-400 shrink-0"></i>
                             <span class="text-[10px] font-medium text-slate-500">
                                 <?php echo htmlspecialchars($log->device_type ?? 'Unknown'); ?> 
                                 <span class="text-slate-400 font-normal">(<?php echo htmlspecialchars(get_ua_details($log->user_agent ?? '')); ?>)</span>
                             </span>
                         </div>
                     </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="px-8 py-6 border-t border-slate-100 flex items-center justify-between bg-slate-50/30">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                Menampilkan <?php echo count($logs); ?> dari <?php echo e($total_logs); ?> entri
            </p>
            <div class="flex items-center gap-2">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="index.php?page=admin_logs&p=<?php echo e($i); ?>&search=<?php echo urlencode($search); ?>" 
                       class="w-8 h-8 rounded-xl flex items-center justify-center text-xs font-black transition-all <?php echo ($i == $page_num) ? 'bg-blue-600 text-white shadow-lg shadow-blue-200' : 'bg-white border border-slate-100 text-slate-400 hover:bg-slate-50'; ?>">
                        <?php echo e($i); ?>
                    </a>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // Initialize icons
    lucide.createIcons();
</script>
