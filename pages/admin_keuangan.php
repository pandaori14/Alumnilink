<?php
// Admin-only guard
if ($_SESSION['user_role'] === 'alumni') {
    ?>
    <div class="max-w-2xl mx-auto px-4 py-16 text-center">
        <div class="glass p-12 rounded-[3rem] border border-white shadow-xl relative overflow-hidden">
            <div class="absolute -right-10 -top-10 w-40 h-40 bg-red-50 rounded-full blur-2xl pointer-events-none"></div>
            <div class="w-24 h-24 bg-red-100 text-red-600 rounded-3xl flex items-center justify-center mx-auto mb-8 shadow-inner border border-red-200">
                <i data-lucide="shield-alert" class="w-12 h-12"></i>
            </div>
            <h2 class="text-3xl font-black outfit text-slate-800 mb-4 tracking-tight">Akses Ditolak</h2>
            <p class="text-slate-500 mb-8 max-w-md mx-auto leading-relaxed text-sm">Maaf, halaman Laporan Keuangan ini hanya dapat diakses oleh administrator.</p>
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

// Filters
$start_date     = $_GET['start_date'] ?? '';
$end_date       = $_GET['end_date'] ?? '';
$filter_status  = $_GET['status'] ?? '';
$filter_method  = $_GET['method'] ?? '';
$filter_month   = $_GET['month'] ?? '';
// Build WHERE clause
$where = ["1=1"];
$params = [];
if ($start_date)    { $where[] = "DATE(lr.created_at) >= ?"; $params[] = $start_date; }
if ($end_date)      { $where[] = "DATE(lr.created_at) <= ?"; $params[] = $end_date; }
if ($filter_status) { $where[] = "lr.payment_status = ?"; $params[] = $filter_status; }
if ($filter_method) { $where[] = "lr.payment_method = ?"; $params[] = $filter_method; }
if ($filter_month)  { $where[] = "DATE_FORMAT(lr.created_at, '%Y-%m') = ?"; $params[] = $filter_month; }
$where_sql = implode(' AND ', $where);

// Fetch filtered data
$stmt = $pdo->prepare("
    SELECT lr.id, lr.created_at, lr.amount, lr.payment_status, lr.payment_method,
           lr.delivery_method, lr.status, lr.tracking_number, lr.documents,
           u.name as alumni_name, u.email as alumni_email, u.nim as alumni_nim
    FROM legalisir_requests lr
    JOIN users u ON lr.user_id = u.id
    WHERE {$where_sql}
    ORDER BY lr.created_at DESC
");
$stmt->execute($params);
$records = $stmt->fetchAll();

// Fetch admin fee from settings
$admin_fee = (int)($pdo->query("SELECT setting_value FROM settings WHERE setting_key='admin_fee'")->fetchColumn() ?: 5000);

// Summary stats (unfiltered for cards, excluding admin fee)
$total_revenue   = $pdo->query("SELECT COALESCE(SUM(GREATEST(0, amount - {$admin_fee})),0) FROM legalisir_requests WHERE payment_status='settlement'")->fetchColumn();
$midtrans_rev    = $pdo->query("SELECT COALESCE(SUM(GREATEST(0, amount - {$admin_fee})),0) FROM legalisir_requests WHERE payment_status='settlement' AND payment_method='midtrans'")->fetchColumn();
$cash_rev        = $pdo->query("SELECT COALESCE(SUM(GREATEST(0, amount - {$admin_fee})),0) FROM legalisir_requests WHERE payment_status='settlement' AND payment_method='cash'")->fetchColumn();
$pending_rev     = $pdo->query("SELECT COALESCE(SUM(GREATEST(0, amount - {$admin_fee})),0) FROM legalisir_requests WHERE payment_status='pending'")->fetchColumn();

// Months for filter
$months = $pdo->query("SELECT DISTINCT DATE_FORMAT(created_at,'%Y-%m') as m, DATE_FORMAT(created_at,'%M %Y') as label FROM legalisir_requests ORDER BY m DESC")->fetchAll();
?>

<div class="max-w-6xl mx-auto">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <h3 class="text-xl md:text-2xl font-bold outfit">Laporan Keuangan</h3>
            <p class="text-slate-500 text-sm mt-1">Rekap transaksi legalisir dokumen & ongkos kirim.</p>
        </div>
        <?php
        $export_params = http_build_query($_GET);
        ?>
        <a href="handlers/export_keuangan.php?<?php echo e($export_params); ?>"
           class="flex items-center gap-2 bg-green-600 text-white px-5 py-3 rounded-2xl font-bold text-sm shadow-lg shadow-green-200 hover:bg-green-700 transition-all">
            <i data-lucide="download" class="w-4 h-4"></i>
            Export CSV
        </a>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="glass p-5 rounded-2xl shadow-sm">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Total Pendapatan</p>
            <h2 class="text-lg md:text-xl font-black outfit text-slate-800 mt-1">Rp <?php echo number_format($total_revenue, 0, ',', '.'); ?></h2>
            <p class="text-[10px] text-green-600 font-bold mt-1">Sudah Lunas</p>
        </div>
        <div class="glass p-5 rounded-2xl shadow-sm">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Via Midtrans</p>
            <h2 class="text-lg md:text-xl font-black outfit text-slate-800 mt-1">Rp <?php echo number_format($midtrans_rev, 0, ',', '.'); ?></h2>
            <p class="text-[10px] text-blue-600 font-bold mt-1 flex items-center gap-1"><i data-lucide="credit-card" class="w-3 h-3"></i> Digital</p>
        </div>
        <div class="glass p-5 rounded-2xl shadow-sm">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Via Tunai</p>
            <h2 class="text-lg md:text-xl font-black outfit text-slate-800 mt-1">Rp <?php echo number_format($cash_rev, 0, ',', '.'); ?></h2>
            <p class="text-[10px] text-emerald-600 font-bold mt-1 flex items-center gap-1"><i data-lucide="banknote" class="w-3 h-3"></i> Cash</p>
        </div>
        <div class="glass p-5 rounded-2xl col-span-2 lg:col-span-1 shadow-sm">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Belum Lunas</p>
            <h2 class="text-lg md:text-xl font-black outfit text-slate-800 mt-1">Rp <?php echo number_format($pending_rev, 0, ',', '.'); ?></h2>
            <p class="text-[10px] text-orange-500 font-bold mt-1">Menunggu Pembayaran</p>
        </div>
    </div>

    <!-- Filter Bar -->
    <form method="GET" action="" class="glass p-4 rounded-2xl mb-6 flex flex-wrap gap-4 items-center">
        <input type="hidden" name="page" value="admin_keuangan">
        
        <div class="flex items-center gap-2">
            <div class="relative">
                <input type="date" name="start_date" value="<?php echo e($start_date); ?>" class="text-xs px-4 py-2 rounded-xl border border-slate-200 bg-white outline-none focus:border-blue-500" title="Mulai Tanggal">
            </div>
            <span class="text-slate-300">-</span>
            <div class="relative">
                <input type="date" name="end_date" value="<?php echo e($end_date); ?>" class="text-xs px-4 py-2 rounded-xl border border-slate-200 bg-white outline-none focus:border-blue-500" title="Sampai Tanggal">
            </div>
        </div>

        <select aria-label="Filter Status" name="status" class="text-xs px-4 py-2 rounded-xl border border-slate-200 bg-white outline-none focus:border-blue-500">
            <option value="">Semua Status</option>
            <option value="settlement" <?php echo $filter_status=='settlement'?'selected':''; ?>>Lunas</option>
            <option value="pending"    <?php echo $filter_status=='pending'?'selected':''; ?>>Pending</option>
        </select>
        <select aria-label="Filter Metode" name="method" class="text-xs px-4 py-2 rounded-xl border border-slate-200 bg-white outline-none focus:border-blue-500">
            <option value="">Semua Metode</option>
            <option value="midtrans" <?php echo $filter_method=='midtrans'?'selected':''; ?>>Midtrans</option>
            <option value="cash"     <?php echo $filter_method=='cash'?'selected':''; ?>>Tunai</option>
        </select>
        
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-xl text-xs font-bold hover:bg-blue-700 transition-all">
            Filter
        </button>

        <?php if ($filter_status || $filter_method || $start_date || $end_date): ?>
        <a href="?page=admin_keuangan" class="text-xs text-red-500 font-bold hover:underline flex items-center gap-1">
            <i data-lucide="x" class="w-3 h-3"></i> Reset
        </a>
        <?php endif; ?>
        <span class="ml-auto text-xs text-slate-400 font-semibold"><?php echo count($records); ?> data</span>
    </form>

    <!-- Mobile Cards -->
    <div class="space-y-3 md:hidden">
        <?php if (empty($records)): ?>
        <div class="glass rounded-2xl p-10 text-center text-slate-400 italic text-sm">Tidak ada data untuk ditampilkan.</div>
        <?php else: foreach ($records as $r):
            $paid = $r->payment_status === 'settlement';
            $docs = json_decode($r->documents);
            $docNames = array_map(fn($d) => ucfirst(is_object($d) ? $d->type : $d), (array)$docs);
            $net_amount = max(0, $r->amount - $admin_fee);
        ?>
        <div class="glass rounded-2xl p-5 shadow-sm">
            <div class="flex justify-between items-start mb-3">
                <div>
                    <p class="font-bold text-slate-800"><?php echo htmlspecialchars($r->alumni_name); ?></p>
                    <p class="text-xs text-slate-400 font-mono"><?php echo e($r->alumni_nim ?? '-'); ?></p>
                    <p class="text-[10px] text-slate-400 mt-0.5"><?php echo date('d M Y, H:i', strtotime($r->created_at)); ?></p>
                </div>
                <span class="shrink-0 px-2 py-1 rounded-lg text-[10px] font-bold <?php echo $paid ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-600'; ?>">
                    <?php echo $paid ? 'Lunas' : 'Pending'; ?>
                </span>
            </div>
            <div class="flex items-center justify-between pt-3 border-t border-white/30">
                <div class="text-xs text-slate-500">
                    <span class="font-semibold text-slate-700"><?php echo strtoupper($r->payment_method); ?></span>
                    &bull; <?php echo implode(', ', $docNames); ?>
                </div>
                <span class="font-bold text-blue-600 text-sm">Rp <?php echo number_format($net_amount, 0, ',', '.'); ?></span>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- Desktop Table -->
    <div class="hidden md:block glass rounded-[2rem] overflow-hidden shadow-sm">
        <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse text-sm min-w-[900px]">
            <thead>
                <tr class="bg-white/40 border-b border-white/20">
                    <th class="px-5 py-4 font-semibold text-slate-600">Tanggal</th>
                    <th class="px-5 py-4 font-semibold text-slate-600">Alumni</th>
                    <th class="px-5 py-4 font-semibold text-slate-600">Dokumen</th>
                    <th class="px-5 py-4 font-semibold text-slate-600">Metode Bayar</th>
                    <th class="px-5 py-4 font-semibold text-slate-600">Status Bayar</th>
                    <th class="px-5 py-4 font-semibold text-slate-600">Status Dokumen</th>
                    <th class="px-5 py-4 font-semibold text-slate-600 text-right">Jumlah</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/20">
                <?php if (empty($records)): ?>
                <tr><td colspan="7" class="px-6 py-10 text-center text-slate-400 italic">Tidak ada data.</td></tr>
                <?php else: foreach ($records as $r):
                    $paid = $r->payment_status === 'settlement';
                    $docs = json_decode($r->documents);
                    $docNames = array_map(fn($d) => ucfirst(is_object($d) ? $d->type : $d), (array)$docs);
                    $statusColors = ['pending'=>'bg-yellow-100 text-yellow-700','processing'=>'bg-blue-100 text-blue-700','completed'=>'bg-green-100 text-green-700','rejected'=>'bg-red-100 text-red-700'];
                    $statusLabels = ['pending'=>'Menunggu','processing'=>'Diproses','completed'=>'Selesai','rejected'=>'Dibatalkan'];
                    $net_amount = max(0, $r->amount - $admin_fee);
                ?>
                <tr class="hover:bg-white/30 transition-all">
                    <td class="px-5 py-4 text-slate-500 whitespace-nowrap"><?php echo date('d M Y', strtotime($r->created_at)); ?></td>
                    <td class="px-5 py-4">
                        <p class="font-bold text-slate-800"><?php echo htmlspecialchars($r->alumni_name); ?></p>
                        <p class="text-[10px] text-slate-400"><?php echo e($r->alumni_nim ?? $r->alumni_email); ?></p>
                    </td>
                    <td class="px-5 py-4 text-slate-600"><?php echo implode(', ', $docNames); ?></td>
                    <td class="px-5 py-4">
                        <span class="px-2 py-1 rounded-lg text-[10px] font-bold <?php echo e($r->payment_method === 'cash' ? 'bg-emerald-100 text-emerald-700' : 'bg-blue-100 text-blue-700'); ?>">
                            <?php echo strtoupper($r->payment_method); ?>
                        </span>
                    </td>
                    <td class="px-5 py-4">
                        <span class="px-2 py-1 rounded-lg text-[10px] font-bold <?php echo $paid ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-600'; ?>">
                            <?php echo $paid ? 'Lunas' : 'Pending'; ?>
                        </span>
                    </td>
                    <td class="px-5 py-4">
                        <span class="px-2 py-1 rounded-lg text-[10px] font-bold <?php echo e($statusColors[$r->status] ?? 'bg-slate-100 text-slate-600'); ?>">
                            <?php echo $statusLabels[$r->status] ?? ucfirst($r->status); ?>
                        </span>
                    </td>
                    <td class="px-5 py-4 text-right font-bold text-slate-800 whitespace-nowrap">
                        Rp <?php echo number_format($net_amount, 0, ',', '.'); ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($records)): ?>
            <tfoot>
                <tr class="bg-white/40 border-t border-white/30">
                    <td colspan="6" class="px-5 py-4 font-bold text-slate-700 text-right">TOTAL LUNAS:</td>
                    <td class="px-5 py-4 text-right font-black text-blue-700 text-base whitespace-nowrap">
                        Rp <?php
                        $filtered_total = array_sum(array_map(fn($r) => $r->payment_status === 'settlement' ? max(0, $r->amount - $admin_fee) : 0, $records));
                        echo number_format($filtered_total, 0, ',', '.');
                        ?>
                    </td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
        </div>
    </div>
</div>
