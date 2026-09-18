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

// ── Penyaring ────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/payment/report.php';

$start_date    = $_GET['start_date'] ?? '';
$end_date      = $_GET['end_date'] ?? '';
$filter_status = in_array($_GET['status'] ?? '', ['settlement', 'pending', 'failed'], true) ? $_GET['status'] : '';
$filter_method = in_array($_GET['method'] ?? '', ['midtrans', 'flip', 'cash'], true) ? $_GET['method'] : '';
$filter_month  = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : '';
$filter_jenis  = (string)($_GET['jenis'] ?? 'semua');
$filter_jenis  = in_array($filter_jenis, payment_report_kinds(), true) ? $filter_jenis : 'semua';

// Legalisir DAN donasi, dalam satu bentuk. Donasi selama ini tidak pernah
// masuk laporan ini: rekapnya berdiri sendiri di Kelola Donasi, sehingga
// tidak ada satu angka pun yang menyatakan berapa uang yang benar-benar
// masuk ke fakultas.
$records = payment_finance_rows($pdo, [
    'jenis'      => $filter_jenis,
    'start_date' => $start_date,
    'end_date'   => $end_date,
    'status'     => $filter_status,
    'method'     => $filter_method,
    'month'      => $filter_month,
]);

// Kartu dihitung DARI BARIS yang sedang ditampilkan, sehingga jumlahnya
// selalu sama dengan tabel di bawahnya — termasuk ketika penyaring aktif.
//
// Biaya layanan dibaca dari rincian yang tersimpan per transaksi, bukan dari
// potongan tetap `admin_fee` yang dipakai laporan ini sebelumnya. Potongan
// tetap itu tebakan yang tidak pernah cocok dengan tarif gateway mana pun,
// dan arah salahnya menentukan apakah fakultas mengira untung atau justru
// menanggung selisihnya sendiri.
$ringkas       = payment_finance_summary($records);
$pendapatan    = $ringkas['metode'];
$total_revenue = $ringkas['bruto'];
$biaya_layanan = $ringkas['biaya'];
$neto_fakultas = $ringkas['neto'];
$midtrans_rev  = $pendapatan['midtrans'];
$flip_rev      = $pendapatan['flip'];
$cash_rev      = $pendapatan['cash'];
$pending_rev   = $ringkas['belum_lunas'];
$menyaring     = ($start_date || $end_date || $filter_status || $filter_method || $filter_month || $filter_jenis !== 'semua');

$months = $pdo->query("SELECT DISTINCT DATE_FORMAT(created_at,'%Y-%m') m FROM legalisir_requests
                        UNION SELECT DISTINCT DATE_FORMAT(created_at,'%Y-%m') FROM donations
                        ORDER BY m DESC")->fetchAll(PDO::FETCH_COLUMN);
$warna_jenis = ['legalisir' => 'bg-blue-100 text-blue-700', 'donasi' => 'bg-pink-100 text-pink-700'];
?>

<div class="max-w-6xl mx-auto">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <h3 class="text-xl md:text-2xl font-bold outfit">Laporan Keuangan</h3>
            <p class="text-slate-500 text-sm mt-1">Rekap uang yang masuk: legalisir dokumen, ongkos kirim, dan donasi.</p>
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

    <!-- Ringkasan uang: dibayar alumni -> potongan penyedia -> diterima fakultas -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
        <div class="glass p-5 rounded-2xl shadow-sm">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Dibayar Alumni</p>
            <h2 class="text-lg md:text-xl font-black outfit text-slate-800 mt-1">Rp <?php echo number_format($total_revenue, 0, ',', '.'); ?></h2>
            <p class="text-[10px] text-green-600 font-bold mt-1"><?php echo e($menyaring ? 'Lunas, sesuai penyaring' : 'Sudah Lunas'); ?></p>
        </div>
        <div class="glass p-5 rounded-2xl shadow-sm">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Biaya Layanan</p>
            <h2 class="text-lg md:text-xl font-black outfit text-slate-800 mt-1">Rp <?php echo number_format($biaya_layanan, 0, ',', '.'); ?></h2>
            <p class="text-[10px] text-slate-500 font-bold mt-1">Potongan penyedia pembayaran</p>
        </div>
        <div class="glass p-5 rounded-2xl shadow-sm border border-blue-100">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Diterima Fakultas</p>
            <h2 class="text-lg md:text-xl font-black outfit text-blue-700 mt-1">Rp <?php echo number_format($neto_fakultas, 0, ',', '.'); ?></h2>
            <p class="text-[10px] text-blue-600 font-bold mt-1">Dibayar alumni &minus; biaya layanan</p>
        </div>
        <div class="glass p-5 rounded-2xl shadow-sm">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Belum Lunas</p>
            <h2 class="text-lg md:text-xl font-black outfit text-slate-800 mt-1">Rp <?php echo number_format($pending_rev, 0, ',', '.'); ?></h2>
            <p class="text-[10px] text-orange-500 font-bold mt-1">Menunggu Pembayaran</p>
        </div>
    </div>

    <!-- Rincian per metode: jumlahnya selalu sama dengan "Dibayar Alumni" -->
    <div class="glass p-4 rounded-2xl mb-6 flex flex-wrap items-center gap-x-8 gap-y-3">
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Dibayar alumni, per metode</p>
        <div class="flex items-center gap-2 text-sm">
            <i data-lucide="credit-card" class="w-4 h-4 text-blue-600"></i>
            <span class="text-slate-500">Midtrans</span>
            <span class="font-black text-slate-800">Rp <?php echo number_format($midtrans_rev, 0, ',', '.'); ?></span>
        </div>
        <div class="flex items-center gap-2 text-sm">
            <i data-lucide="wallet" class="w-4 h-4 text-indigo-600"></i>
            <span class="text-slate-500">Flip</span>
            <span class="font-black text-slate-800">Rp <?php echo number_format($flip_rev, 0, ',', '.'); ?></span>
        </div>
        <div class="flex items-center gap-2 text-sm">
            <i data-lucide="banknote" class="w-4 h-4 text-emerald-600"></i>
            <span class="text-slate-500">Tunai</span>
            <span class="font-black text-slate-800">Rp <?php echo number_format($cash_rev, 0, ',', '.'); ?></span>
        </div>
        <?php if ($pendapatan['lainnya'] > 0): ?>
        <div class="flex items-center gap-2 text-sm">
            <span class="text-slate-500">Lainnya</span>
            <span class="font-black text-slate-800">Rp <?php echo number_format($pendapatan['lainnya'], 0, ',', '.'); ?></span>
        </div>
        <?php endif; ?>
        <?php if ($ringkas['tanpa_rincian'] > 0): ?>
        <p class="w-full text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2">
            <?php echo e($ringkas['tanpa_rincian']); ?> transaksi lunas berasal dari sebelum rincian biaya dicatat per
            transaksi, sehingga biaya layanannya tidak dapat dipastikan dan dihitung nol. Angka "Diterima Fakultas"
            untuk baris itu berarti batas atas, bukan hasil pasti.
        </p>
        <?php endif; ?>
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

        <select aria-label="Jenis layanan" name="jenis" class="text-xs px-4 py-2 rounded-xl border border-slate-200 bg-white outline-none focus:border-blue-500">
            <option value="semua"     <?php echo $filter_jenis=='semua'?'selected':''; ?>>Legalisir &amp; Donasi</option>
            <option value="legalisir" <?php echo $filter_jenis=='legalisir'?'selected':''; ?>>Legalisir saja</option>
            <option value="donasi"    <?php echo $filter_jenis=='donasi'?'selected':''; ?>>Donasi saja</option>
        </select>
        <select aria-label="Filter Status" name="status" class="text-xs px-4 py-2 rounded-xl border border-slate-200 bg-white outline-none focus:border-blue-500">
            <option value="">Semua Status</option>
            <option value="settlement" <?php echo $filter_status=='settlement'?'selected':''; ?>>Lunas</option>
            <option value="pending"    <?php echo $filter_status=='pending'?'selected':''; ?>>Pending</option>
            <option value="failed"     <?php echo $filter_status=='failed'?'selected':''; ?>>Gagal / batal</option>
        </select>
        <select aria-label="Filter Bulan" name="month" class="text-xs px-4 py-2 rounded-xl border border-slate-200 bg-white outline-none focus:border-blue-500">
            <option value="">Semua Bulan</option>
            <?php foreach ($months as $m): if (!$m) { continue; } ?>
            <option value="<?php echo e($m); ?>" <?php echo e($filter_month === $m ? 'selected' : ''); ?>>
                <?php echo e(date('F Y', strtotime($m . '-01'))); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select aria-label="Filter Metode" name="method" class="text-xs px-4 py-2 rounded-xl border border-slate-200 bg-white outline-none focus:border-blue-500">
            <option value="">Semua Metode</option>
            <option value="midtrans" <?php echo $filter_method=='midtrans'?'selected':''; ?>>Midtrans</option>
            <option value="flip"     <?php echo $filter_method=='flip'?'selected':''; ?>>Flip</option>
            <option value="cash"     <?php echo $filter_method=='cash'?'selected':''; ?>>Tunai</option>
        </select>
        
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-xl text-xs font-bold hover:bg-blue-700 transition-all">
            Filter
        </button>

        <?php if ($menyaring): ?>
        <a href="?page=admin_keuangan" class="text-xs text-red-500 font-bold hover:underline flex items-center gap-1">
            <i data-lucide="x" class="w-3 h-3"></i> Reset
        </a>
        <?php endif; ?>
        <span class="ml-auto text-xs text-slate-400 font-semibold"><?php echo e(count($records)); ?> data</span>
    </form>

    <!-- Mobile Cards -->
    <div class="space-y-3 md:hidden">
        <?php if (empty($records)): ?>
        <div class="glass rounded-2xl p-10 text-center text-slate-400 italic text-sm">Tidak ada data untuk ditampilkan.</div>
        <?php else: foreach ($records as $r):
            $paid = $r['lunas'];
        ?>
        <div class="glass rounded-2xl p-5 shadow-sm">
            <div class="flex justify-between items-start mb-3">
                <div>
                    <p class="font-bold text-slate-800"><?php echo e($r['nama']); ?></p>
                    <p class="text-xs text-slate-400 font-mono"><?php echo e($r['identitas']); ?></p>
                    <p class="text-[10px] text-slate-400 mt-0.5"><?php echo date('d M Y, H:i', strtotime($r['created_at'])); ?></p>
                </div>
                <div class="shrink-0 text-right space-y-1">
                    <span class="block px-2 py-1 rounded-lg text-[10px] font-bold <?php echo e($warna_jenis[$r['jenis']]); ?>"><?php echo e(ucfirst($r['jenis'])); ?></span>
                    <span class="block px-2 py-1 rounded-lg text-[10px] font-bold <?php echo e($paid ? 'bg-green-100 text-green-700' : ($r['bayar'] === 'pending' ? 'bg-orange-100 text-orange-600' : 'bg-red-100 text-red-600')); ?>">
                        <?php echo e($paid ? 'Lunas' : ($r['bayar'] === 'pending' ? 'Pending' : 'Gagal')); ?>
                    </span>
                </div>
            </div>
            <div class="flex items-center justify-between pt-3 border-t border-white/30">
                <div class="text-xs text-slate-500">
                    <span class="font-semibold text-slate-700"><?php echo e(payment_method_report_label($r['metode'])); ?></span>
                    &bull; <?php echo e($r['keterangan']); ?>
                </div>
                <div class="text-right">
                    <span class="font-bold text-slate-800 text-sm">Rp <?php echo number_format($r['tagihan'], 0, ',', '.'); ?></span>
                    <span class="block text-[10px] font-medium <?php echo e(!$paid ? 'text-slate-400' : ($r['pasti'] ? 'text-blue-600' : 'text-amber-600')); ?>">
                        <?php echo e(!$paid
                            ? ($r['bayar'] === 'pending' ? 'belum dibayar' : 'tidak jadi dibayar')
                            : ($r['pasti']
                                ? 'diterima Rp ' . number_format($r['diterima'], 0, ',', '.')
                                : 'biaya tanpa rincian')); ?>
                    </span>
                </div>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- Desktop Table -->
    <div class="hidden md:block glass rounded-[2rem] overflow-hidden shadow-sm">
        <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse text-sm min-w-[820px]">
            <thead>
                <tr class="bg-white/40 border-b border-white/20">
                    <th class="px-4 py-4 font-semibold text-slate-600">Tanggal</th>
                    <th class="px-4 py-4 font-semibold text-slate-600">Pembayar</th>
                    <th class="px-4 py-4 font-semibold text-slate-600">Keterangan</th>
                    <th class="px-4 py-4 font-semibold text-slate-600">Metode</th>
                    <th class="px-4 py-4 font-semibold text-slate-600">Status</th>
                    <th class="px-4 py-4 font-semibold text-slate-600 text-right">Dibayar</th>
                    <th class="px-4 py-4 font-semibold text-slate-600 text-right">Diterima Fakultas</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/20">
                <?php if (empty($records)): ?>
                <tr><td colspan="7" class="px-6 py-10 text-center text-slate-400 italic">Tidak ada data.</td></tr>
                <?php else:
                    $statusLabels = ['pending'=>'Menunggu','processing'=>'Diproses','completed'=>'Selesai','rejected'=>'Dibatalkan'];
                    foreach ($records as $r):
                    $paid = $r['lunas'];
                ?>
                <tr class="hover:bg-white/30 transition-all">
                    <td class="px-4 py-4 text-slate-500 whitespace-nowrap"><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
                    <td class="px-4 py-4">
                        <p class="font-bold text-slate-800"><?php echo e($r['nama']); ?></p>
                        <p class="text-[10px] text-slate-400"><?php echo e($r['identitas']); ?></p>
                    </td>
                    <td class="px-4 py-4 text-slate-600">
                        <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold mr-1 <?php echo e($warna_jenis[$r['jenis']]); ?>"><?php echo e(ucfirst($r['jenis'])); ?></span>
                        <?php echo e($r['keterangan']); ?>
                        <?php if ($r['status_doc'] !== null): ?>
                            <span class="block text-[10px] text-slate-400 mt-0.5"><?php echo e($statusLabels[$r['status_doc']] ?? ucfirst((string)$r['status_doc'])); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-4">
                        <span class="px-2 py-1 rounded-lg text-[10px] font-bold <?php echo e($r['metode'] === 'cash' ? 'bg-emerald-100 text-emerald-700' : ($r['metode'] ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-500')); ?>">
                            <?php echo e(payment_method_report_label($r['metode'])); ?>
                        </span>
                    </td>
                    <td class="px-4 py-4">
                        <span class="px-2 py-1 rounded-lg text-[10px] font-bold <?php echo e($paid ? 'bg-green-100 text-green-700' : ($r['bayar'] === 'pending' ? 'bg-orange-100 text-orange-600' : 'bg-red-100 text-red-600')); ?>">
                            <?php echo e($paid ? 'Lunas' : ($r['bayar'] === 'pending' ? 'Pending' : 'Gagal')); ?>
                        </span>
                    </td>
                    <td class="px-4 py-4 text-right font-bold text-slate-800 whitespace-nowrap">
                        Rp <?php echo number_format($r['tagihan'], 0, ',', '.'); ?>
                    </td>
                    <td class="px-4 py-4 text-right whitespace-nowrap">
                        <?php if (!$paid): ?>
                            <span class="text-slate-300">&mdash;</span>
                            <span class="block text-[10px] text-slate-400"><?php echo e($r['bayar'] === 'pending' ? 'belum dibayar' : 'tidak jadi dibayar'); ?></span>
                        <?php else: ?>
                            <span class="font-bold text-slate-800">Rp <?php echo number_format($r['diterima'], 0, ',', '.'); ?></span>
                            <span class="block text-[10px] font-medium <?php echo e($r['pasti'] ? 'text-slate-400' : 'text-amber-600'); ?>">
                                <?php echo e($r['pasti'] ? 'biaya Rp ' . number_format($r['biaya'], 0, ',', '.') : 'biaya tanpa rincian'); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($records)): ?>
            <tfoot>
                <tr class="bg-white/40 border-t border-white/30">
                    <td colspan="5" class="px-4 py-4 font-bold text-slate-700 text-right">TOTAL LUNAS:</td>
                    <td class="px-4 py-4 text-right font-black text-slate-700 text-base whitespace-nowrap">
                        Rp <?php echo number_format($total_revenue, 0, ',', '.'); ?>
                    </td>
                    <td class="px-4 py-4 text-right font-black text-blue-700 text-base whitespace-nowrap">
                        Rp <?php echo number_format($neto_fakultas, 0, ',', '.'); ?>
                    </td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
        </div>
    </div>
</div>
