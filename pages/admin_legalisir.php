<?php
require_once __DIR__ . '/../includes/legalisir_lib.php';

// ── Saringan ──────────────────────────────────────────────────────────
// Halaman ini sebelumnya memuat SELURUH pengajuan tanpa WHERE maupun LIMIT,
// lalu merendernya dua kali (tabel desktop + kartu mobile). Tanpa cari,
// saring, maupun paginasi, satu semester pengajuan akan menjadi satu
// halaman raksasa yang tidak bisa dinavigasi.
$l_cari    = trim($_GET['cari'] ?? '');
$l_status  = $_GET['status'] ?? '';
$l_bayar   = $_GET['bayar'] ?? '';
$l_dari    = $_GET['dari'] ?? '';
$l_sampai  = $_GET['sampai'] ?? '';

$where  = " WHERE 1=1";
$params = [];

if ($l_cari !== '') {
    $where .= " AND (lr.id LIKE ? OR u.name LIKE ? OR u.nim LIKE ? OR lr.tracking_number LIKE ?)";
    $k = "%$l_cari%";
    array_push($params, $k, $k, $k, $k);
}
// Status dan pembayaran dicocokkan ke daftar tertutup sebelum dipakai.
if (in_array($l_status, legalisir_valid_statuses(), true)) {
    $where .= " AND lr.status = ?";
    $params[] = $l_status;
} else {
    $l_status = '';
}
if (in_array($l_bayar, ['paid', 'unpaid', 'pending'], true)) {
    $where .= " AND lr.payment_status = ?";
    $params[] = $l_bayar;
} else {
    $l_bayar = '';
}
if ($l_dari !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $l_dari)) {
    $where .= " AND lr.created_at >= ?";
    $params[] = $l_dari . ' 00:00:00';
} else { $l_dari = ''; }
if ($l_sampai !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $l_sampai)) {
    $where .= " AND lr.created_at <= ?";
    $params[] = $l_sampai . ' 23:59:59';
} else { $l_sampai = ''; }

$sql_dasar = "FROM legalisir_requests lr JOIN users u ON lr.user_id = u.id $where";

$hal_leg  = paginate($pdo, $sql_dasar,
    'lr.*, u.name as user_name, u.nim as user_nim, u.phone as user_phone',
    'lr.created_at DESC', $params, 20);
$requests = $hal_leg['rows'];
$l_total  = $hal_leg['total'];
$l_hal    = $hal_leg['hal'];
$l_hal_n  = $hal_leg['total_hal'];

// ── Statistik operasional (satu kueri agregat) ────────────────────────
$st = $pdo->query("SELECT
        SUM(CASE WHEN status IN ('pending','processing') THEN 1 ELSE 0 END) AS terbuka,
        SUM(CASE WHEN status IN ('pending','processing')
                  AND created_at < DATE_SUB(NOW(), INTERVAL " . legalisir_sla_breach() . " DAY)
             THEN 1 ELSE 0 END) AS lewat_sla,
        AVG(CASE WHEN updated_at IS NOT NULL AND status IN ('completed','rejected')
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
             THEN TIMESTAMPDIFF(HOUR, created_at, updated_at) END) AS rata_jam
      FROM legalisir_requests")->fetch();

// Kueri filter dibawa ke tautan paginasi dan ke formulir aksi massal.
$l_query = array_filter([
    'page' => 'admin_legalisir', 'cari' => $l_cari, 'status' => $l_status,
    'bayar' => $l_bayar, 'dari' => $l_dari, 'sampai' => $l_sampai,
], fn($v) => $v !== '' && $v !== null);
$l_url = pager_url_builder($l_query);

// Fetch Midtrans Settings for payment assist
$settings = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$client_key = $settings['midtrans_client_key'] ?? '';
$is_production = (bool)($settings['midtrans_is_production'] ?? false);
$snap_url = $is_production ? "https://app.midtrans.com/snap/snap.js" : "https://app.sandbox.midtrans.com/snap/snap.js";
?>

<div class="max-w-6xl mx-auto">
    <?php csrf_field(); ?>
    <div class="mb-8 px-1">
        <h1 class="text-xl md:text-2xl font-bold outfit text-slate-800 tracking-tight">Kelola Permintaan Legalisir</h1>
        <p class="text-slate-400 text-xs md:text-sm mt-1 font-medium">Verifikasi dokumen dan perbarui status pengajuan alumni.</p>
    </div>

    <?php if (isset($_GET['success']) && $_GET['success'] == 'cash_verified'): ?>
        <div class="mb-8 p-4 bg-green-50 border border-green-200 text-green-700 rounded-2xl flex items-center gap-3 animate-pulse">
            <i data-lucide="check-circle" class="w-5 h-5"></i>
            <span class="font-medium">Pembayaran Tunai berhasil diverifikasi!</span>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['success']) && $_GET['success'] == 'token_regenerated'): ?>
        <div class="mb-8 p-4 bg-blue-50 border border-blue-200 text-blue-700 rounded-2xl flex items-center gap-3 animate-pulse">
            <i data-lucide="check-circle" class="w-5 h-5"></i>
            <span class="font-medium">Token pembayaran online berhasil dibuat ulang!</span>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['success']) && $_GET['success'] == 'deleted'): ?>
        <div class="mb-8 p-4 bg-green-50 border border-green-200 text-green-700 rounded-2xl flex items-center gap-3 animate-pulse">
            <i data-lucide="trash-2" class="w-5 h-5"></i>
            <span class="font-medium">Data pengajuan legalisir berhasil dihapus!</span>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error']) && $_GET['error'] == 'verify_failed'): ?>
        <div class="mb-8 p-4 bg-red-50 border border-red-200 text-red-700 rounded-2xl flex items-center gap-3 animate-pulse">
            <i data-lucide="alert-circle" class="w-5 h-5"></i>
            <span class="font-medium">Gagal memverifikasi. Data mungkin tidak ditemukan atau sudah diverifikasi sebelumnya.</span>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error']) && $_GET['error'] == 'midtrans_failed'): ?>
        <div class="mb-8 p-4 bg-red-50 border border-red-200 text-red-700 rounded-2xl flex items-center gap-3 animate-pulse">
            <i data-lucide="alert-circle" class="w-5 h-5"></i>
            <span class="font-medium">Gagal menghubungi Midtrans atau membuat token baru.</span>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error']) && $_GET['error'] == 'delete_failed'): ?>
        <div class="mb-8 p-4 bg-red-50 border border-red-200 text-red-700 rounded-2xl flex items-center gap-3 animate-pulse">
            <i data-lucide="alert-circle" class="w-5 h-5"></i>
            <span class="font-medium">Gagal menghapus pengajuan. Data tidak ditemukan.</span>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['success']) && $_GET['success'] === 'bulk'): ?>
        <div class="mb-8 p-4 bg-green-50 border border-green-200 text-green-700 rounded-2xl flex items-center gap-3">
            <i data-lucide="check-circle" class="w-5 h-5"></i>
            <span class="font-medium"><?php echo (int)($_GET['n'] ?? 0); ?> pengajuan berhasil diperbarui<?php echo e(!empty($_GET['gagal']) ? ', ' . (int)$_GET['gagal'] . ' gagal' : ''); ?>.</span>
        </div>
    <?php endif; ?>

    <?php
    $l_pesan_galat = [
        'alasan_wajib'        => 'Penolakan wajib disertai alasan minimal 10 karakter.',
        'status'              => 'Status tidak dikenali.',
        'bulk_kosong'         => 'Tidak ada pengajuan yang dipilih.',
        'bulk_status'         => 'Status tujuan tidak sah.',
        'bulk_alasan'         => 'Penolakan massal wajib disertai alasan minimal 10 karakter.',
        'bulk_terlalu_banyak' => 'Terlalu banyak pengajuan dipilih sekaligus (maksimal 100).',
        'bulk_gagal'          => 'Perubahan dibatalkan seluruhnya; tidak ada yang tersimpan.',
    ];
    ?>
    <?php if (isset($_GET['error']) && isset($l_pesan_galat[$_GET['error']])): ?>
        <div class="mb-8 p-4 bg-red-50 border border-red-200 text-red-700 rounded-2xl flex items-center gap-3">
            <i data-lucide="alert-circle" class="w-5 h-5"></i>
            <span class="font-medium"><?php echo htmlspecialchars($l_pesan_galat[$_GET['error']]); ?></span>
        </div>
    <?php endif; ?>

    <!-- Statistik operasional -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="glass p-5 rounded-2xl">
            <div class="text-2xl font-black outfit text-slate-700"><?php echo (int)($st->terbuka ?? 0); ?></div>
            <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mt-1">Masih terbuka</div>
        </div>
        <div class="glass p-5 rounded-2xl">
            <div class="text-2xl font-black outfit <?php echo e(($st->lewat_sla ?? 0) > 0 ? 'text-red-600' : 'text-slate-700'); ?>"><?php echo e((int)($st->lewat_sla ?? 0)); ?></div>
            <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mt-1">Lewat <?php echo legalisir_sla_breach(); ?> hari</div>
        </div>
        <div class="glass p-5 rounded-2xl">
            <div class="text-2xl font-black outfit text-slate-700">
                <?php echo $st->rata_jam !== null ? round($st->rata_jam / 24, 1) . ' hr' : '&mdash;'; ?>
            </div>
            <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mt-1">Rata-rata proses (90 hr)</div>
        </div>
        <div class="glass p-5 rounded-2xl">
            <div class="text-2xl font-black outfit text-slate-700"><?php echo e($l_total); ?></div>
            <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mt-1">Hasil saringan</div>
        </div>
    </div>
    <?php if ($st->rata_jam === null): ?>
        <p class="text-xs text-slate-400 -mt-6 mb-8">
            Rata-rata lama proses baru dapat dihitung untuk pengajuan yang diubah setelah pembaruan ini &mdash;
            pengajuan lama tidak menyimpan jejak kapan terakhir diubah.
        </p>
    <?php endif; ?>

    <!-- Cari &amp; Saring -->
    <div class="glass p-6 rounded-[2rem] shadow-sm mb-8">
        <form action="index.php" method="GET" class="grid grid-cols-1 md:grid-cols-6 gap-3">
            <input type="hidden" name="page" value="admin_legalisir">
            <div class="relative md:col-span-2">
                <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                <input type="text" name="cari" value="<?php echo htmlspecialchars($l_cari); ?>" placeholder="ID / nama / NIM / resi"
                       aria-label="Cari pengajuan" class="w-full pl-11 pr-4 py-3 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none text-sm">
            </div>
            <select name="status" aria-label="Saring status" class="px-4 py-3 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none text-sm">
                <option value="">Semua status</option>
                <?php foreach (legalisir_valid_statuses() as $sv): ?>
                    <option value="<?php echo e($sv); ?>" <?php echo $l_status === $sv ? 'selected' : ''; ?>><?php echo legalisir_status_label($sv); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="bayar" aria-label="Saring pembayaran" class="px-4 py-3 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none text-sm">
                <option value="">Semua pembayaran</option>
                <option value="paid"    <?php echo $l_bayar === 'paid' ? 'selected' : ''; ?>>Lunas</option>
                <option value="pending" <?php echo $l_bayar === 'pending' ? 'selected' : ''; ?>>Menunggu</option>
                <option value="unpaid"  <?php echo $l_bayar === 'unpaid' ? 'selected' : ''; ?>>Belum bayar</option>
            </select>
            <input type="date" name="dari" value="<?php echo htmlspecialchars($l_dari); ?>" aria-label="Tanggal mulai"
                   class="px-4 py-3 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none text-sm">
            <div class="flex gap-2">
                <input type="date" name="sampai" value="<?php echo htmlspecialchars($l_sampai); ?>" aria-label="Tanggal akhir"
                       class="flex-1 px-4 py-3 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none text-sm">
                <button type="submit" class="bg-blue-600 text-white p-3 rounded-2xl hover:bg-blue-700 transition-all shrink-0" aria-label="Terapkan saringan">
                    <i data-lucide="filter" class="w-5 h-5"></i>
                </button>
            </div>
        </form>
        <?php if (count($l_query) > 1): ?>
            <a href="index.php?page=admin_legalisir" class="inline-block mt-3 text-xs font-bold text-red-600 hover:underline">Hapus semua saringan</a>
        <?php endif; ?>
    </div>

    <!-- Aksi massal. Formulir dibuka di sini dan ditutup setelah kedua
         daftar (tabel desktop + kartu mobile), supaya kotak centang di
         keduanya termasuk dalam satu pengiriman. -->
    <form action="handlers/admin_legalisir_bulk.php" method="POST" id="form-bulk">
        <?php csrf_field(); ?>
        <input type="hidden" name="kembali" value="<?php echo htmlspecialchars(http_build_query($l_query)); ?>">
        <input type="hidden" name="rejection_reason" id="bulk-alasan" value="">
        <div id="bar-bulk" class="hidden sticky top-4 z-30 mb-6 glass p-4 rounded-2xl border border-blue-200 shadow-lg flex flex-wrap items-center gap-3">
            <span class="text-sm font-bold text-slate-700"><span id="bulk-jumlah">0</span> dipilih</span>
            <select name="status" id="bulk-status" aria-label="Status tujuan" class="px-4 py-2 rounded-xl border border-slate-200 bg-white text-sm font-bold outline-none">
                <?php foreach (legalisir_valid_statuses() as $sv): ?>
                    <option value="<?php echo e($sv); ?>"><?php echo legalisir_status_label($sv); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" onclick="jalankanBulk()" class="px-5 py-2 bg-slate-900 text-white rounded-xl font-bold text-sm hover:bg-black transition-all">
                Terapkan
            </button>
            <button type="button" onclick="cetakLabelTerpilih()" class="px-5 py-2 bg-white border border-slate-200 text-slate-600 rounded-xl font-bold text-sm hover:bg-slate-50 transition-all">
                <i data-lucide="printer" class="w-4 h-4 inline"></i> Cetak label
            </button>
            <button type="button" onclick="batalPilih()" class="px-4 py-2 text-slate-400 hover:text-slate-600 text-sm font-bold">Batal</button>
        </div>

    <!-- Desktop Table View -->
    <div class="hidden md:block glass rounded-[2.5rem] overflow-hidden shadow-sm border border-white/50">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-white/40 border-b border-white/20">
                    <th class="pl-6 pr-2 py-5">
                        <input type="checkbox" id="pilih-semua" onclick="pilihSemua(this)" aria-label="Pilih semua di halaman ini"
                               class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 cursor-pointer">
                    </th>
                    <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Alumni</th>
                    <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Dokumen</th>
                    <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Pembayaran</th>
                    <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Status</th>
                    <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/20">
                <?php if (empty($requests)): ?>
                    <tr>
                        <td colspan="6" class="px-8 py-16 text-center">
                            <div class="w-16 h-16 bg-slate-100 text-slate-300 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i data-lucide="search-x" class="w-8 h-8"></i>
                            </div>
                            <p class="font-bold text-slate-400">Tidak ada pengajuan yang cocok</p>
                            <p class="text-sm text-slate-400 mt-1">Coba ubah kata kunci atau saringan.</p>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($requests as $req): ?>
                    <?php $docs = json_decode($req->documents); ?>
                    <?php
                        // Umur dan lama proses dihitung dari baris yang sudah
                        // diambil; created_at selama ini hanya dipakai untuk
                        // mengurutkan dan tidak pernah ditampilkan.
                        $umur      = legalisir_age_days($req->created_at);
                        $badge     = legalisir_age_badge($req->status, $umur);
                        $turnaround = legalisir_turnaround_days($req->created_at, $req->updated_at ?? null);
                    ?>
                    <tr class="hover:bg-white/50 transition-all group">
                        <td class="pl-6 pr-2 py-6">
                            <input type="checkbox" name="ids[]" value="<?php echo htmlspecialchars($req->id); ?>"
                                   onchange="perbaruiBar()" aria-label="Pilih pengajuan <?php echo htmlspecialchars($req->id); ?>"
                                   class="pilih-baris w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 cursor-pointer">
                        </td>
                        <td class="px-8 py-6">
                            <div class="flex flex-col">
                                <span class="font-black text-slate-800 outfit text-base"><?php echo e($req->user_name); ?></span>
                                <div class="flex items-center gap-2 mt-1.5">
                                    <span class="px-2 py-0.5 rounded-lg text-[9px] font-bold uppercase tracking-wider <?php echo e($badge); ?>"
                                          title="Diajukan <?php echo htmlspecialchars((string)$req->created_at); ?>">
                                        <?php echo legalisir_age_text($umur); ?>
                                    </span>
                                    <?php if (in_array($req->status, ['completed','rejected'], true)): ?>
                                        <span class="text-[9px] text-slate-400 font-bold">
                                            <?php echo $turnaround !== null ? 'selesai dalam ' . $turnaround . ' hari' : 'lama proses tidak tercatat'; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex items-center gap-2 mt-1">
                                    <span class="text-[9px] text-slate-400 font-bold tracking-widest opacity-60 uppercase"><?php echo e($req->user_nim ?: $req->user_id); ?></span>
                                    <?php if($req->user_nim): ?>
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500" title="NIM Verified"></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="px-8 py-6">
                            <div class="flex -space-x-2">
                                <?php foreach ($docs as $doc): ?>
                                    <?php 
                                        $type = is_object($doc) ? $doc->type : $doc;
                                        $icon = ($type == 'ijazah') ? 'scroll' : 'file-spreadsheet';
                                        $color = ($type == 'ijazah') ? 'bg-blue-50 text-blue-600' : 'bg-purple-50 text-purple-600';
                                    ?>
                                    <div title="<?php echo ucfirst($type); ?>" class="w-10 h-10 rounded-2xl border-4 border-white <?php echo e($color); ?> flex items-center justify-center shadow-sm">
                                        <i data-lucide="<?php echo e($icon); ?>" class="w-4 h-4"></i>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td class="px-8 py-6">
                            <div class="flex flex-col gap-2">
                                <span class="flex items-center gap-2 text-[10px] font-black <?php echo e($req->payment_status == 'settlement' ? 'text-emerald-600' : 'text-amber-500'); ?> tracking-widest uppercase">
                                    <span class="w-2 h-2 rounded-full <?php echo e($req->payment_status == 'settlement' ? 'bg-emerald-600 animate-pulse' : 'bg-amber-500'); ?>"></span>
                                    <?php echo e($req->payment_status == 'settlement' ? 'LUNAS' : 'PENDING'); ?>
                                </span>
                                <?php if ($req->payment_status != 'settlement'): ?>
                                    <div class="flex items-center gap-2 mt-2">
                                        <!-- Universal Manual Verification -->
                                        <button type="button" onclick="openCashModal('<?php echo e($req->id); ?>')" class="flex items-center gap-2 text-[10px] bg-emerald-600 text-white px-4 py-2 rounded-xl font-black uppercase tracking-widest hover:bg-emerald-700 transition-all shadow-lg shadow-emerald-200">
                                            <i data-lucide="banknote" class="w-4 h-4"></i>
                                            Verifikasi Manual
                                        </button>
                                        
                                        <?php if ($req->midtrans_snap_token): ?>
                                            <button onclick="payAlumniBill('<?php echo e($req->midtrans_snap_token); ?>')" class="w-10 h-10 bg-orange-50 text-orange-600 rounded-xl flex items-center justify-center border border-orange-200 hover:bg-orange-600 hover:text-white transition-all" title="Cek Midtrans">
                                                <i data-lucide="credit-card" class="w-4 h-4"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <form action="handlers/regenerate_payment.php" method="POST" class="inline">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="request_id" value="<?php echo e($req->id); ?>">
                                            <button type="submit" class="w-10 h-10 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center border border-blue-200 hover:bg-blue-600 hover:text-white transition-all" title="Buat Ulang Token Pembayaran">
                                                <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="text-[9px] font-bold text-slate-400 uppercase tracking-[0.2em] mt-1 opacity-50">
                                        VIA <?php echo strtoupper($req->payment_method); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-8 py-6">
                            <div class="flex flex-col gap-1">
                                <form action="handlers/admin_update_legalisir.php" method="POST" class="flex items-center gap-2">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo e($req->id); ?>">
                                    <input type="hidden" name="rejection_reason" value="">
                                    <select aria-label="Status" name="status" data-semula="<?php echo e($req->status); ?>" onchange="ubahStatus(this)" class="text-xs font-black px-4 py-2 rounded-xl border border-slate-200 bg-white outline-none focus:border-blue-500 shadow-sm cursor-pointer">
                                        <option value="pending" <?php echo e($req->status == 'pending' ? 'selected' : ''); ?>>Pending</option>
                                        <option value="processing" <?php echo e($req->status == 'processing' ? 'selected' : ''); ?>>Processing</option>
                                        <option value="completed" <?php echo e($req->status == 'completed' ? 'selected' : ''); ?>>Completed</option>
                                        <option value="rejected" <?php echo e($req->status == 'rejected' ? 'selected' : ''); ?>>Rejected</option>
                                    </select>
                                </form>
                            </div>
                        </td>
                        <td class="px-8 py-6">
                            <div class="flex items-center gap-3">
                                <?php 
                                    $search_nim = $req->user_nim ?: $req->user_id;
                                    $wa_phone = $req->user_phone;
                                    if (empty($wa_phone) && !empty($req->shipping_address)) {
                                        $ship_data = json_decode($req->shipping_address);
                                        if (!empty($ship_data->phone)) $wa_phone = $ship_data->phone;
                                    }
                                    $clean_wa = preg_replace('/[^0-9]/', '', $wa_phone);
                                    if (substr($clean_wa, 0, 1) === '0') $clean_wa = '62' . substr($clean_wa, 1);
                                    elseif (substr($clean_wa, 0, 2) !== '62' && !empty($clean_wa)) $clean_wa = '62' . $clean_wa;
                                ?>
                                <a href="https://pddikti.kemdiktisaintek.go.id/search/<?php echo e($search_nim); ?>" target="_blank" class="w-10 h-10 flex items-center justify-center bg-white border border-slate-100 rounded-xl text-blue-500 hover:bg-blue-600 hover:text-white transition-all shadow-sm" title="Cek PDDikti">
                                    <i data-lucide="graduation-cap" class="w-4 h-4"></i>
                                </a>
                                <?php if (!empty($clean_wa)): ?>
                                    <a href="https://wa.me/<?php echo e($clean_wa); ?>" target="_blank" class="w-10 h-10 flex items-center justify-center bg-white border border-slate-100 rounded-xl text-emerald-500 hover:bg-emerald-600 hover:text-white transition-all shadow-sm" title="Chat WhatsApp Alumni (<?php echo htmlspecialchars($wa_phone); ?>)">
                                        <i data-lucide="message-circle" class="w-4 h-4"></i>
                                    </a>
                                <?php else: ?>
                                    <button onclick="showSwalAlert('WhatsApp Tidak Tersedia', 'Nomor WhatsApp alumni belum terdaftar di sistem atau alamat pengiriman.', 'warning')" class="w-10 h-10 flex items-center justify-center bg-white border border-slate-100 rounded-xl text-slate-300 hover:bg-slate-100 transition-all shadow-sm" title="WhatsApp Tidak Tersedia">
                                        <i data-lucide="message-circle" class="w-4 h-4"></i>
                                    </button>
                                <?php endif; ?>
                                <button onclick="openAdminModal('<?php echo e($req->id); ?>', '<?php echo e($req->tracking_number); ?>', '<?php echo base64_encode($req->shipping_address ?? ''); ?>')" class="w-10 h-10 flex items-center justify-center bg-white border border-slate-100 rounded-xl text-blue-600 hover:bg-blue-600 hover:text-white transition-all shadow-sm" title="Tracking">
                                    <i data-lucide="truck" class="w-4 h-4"></i>
                                </button>
                                <button onclick="openDocModal(<?php echo e(json_encode($docs)); ?>, <?php echo e(json_encode($req->user_name)); ?>, '<?php echo e($search_nim); ?>', '<?php echo e($req->id); ?>')" class="w-10 h-10 flex items-center justify-center bg-white border border-slate-100 rounded-xl text-purple-600 hover:bg-purple-600 hover:text-white transition-all shadow-sm" title="Berkas">
                                    <i data-lucide="eye" class="w-4 h-4"></i>
                                </button>
                                <a href="handlers/admin_delete_legalisir.php?id=<?php echo e($req->id); ?>&csrf_token=<?php echo get_csrf_token(); ?>" onclick="confirmDelete(event, this.href, 'Apakah Anda yakin ingin menghapus data pengajuan legalisir ini? Tindakan ini tidak dapat dibatalkan.')" class="w-10 h-10 flex items-center justify-center bg-white border border-slate-100 rounded-xl text-red-500 hover:bg-red-600 hover:text-white transition-all shadow-sm" title="Hapus Pengajuan">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile Card View -->
    <div class="grid grid-cols-1 gap-6 md:hidden">
        <?php foreach ($requests as $req): ?>
            <?php 
                $docs = json_decode($req->documents); 
                $paid = $req->payment_status === 'settlement';
            ?>
            <div class="glass p-6 rounded-[2.5rem] border border-white/80 shadow-sm hover:shadow-xl transition-all duration-300 space-y-6 bg-gradient-to-br from-white/90 to-white/40 relative overflow-hidden group">
                <!-- Accent Bar -->
                <div class="absolute left-0 top-0 bottom-0 w-1.5 <?php echo $paid ? 'bg-emerald-500' : 'bg-amber-500'; ?> group-hover:w-2 transition-all"></div>

                <?php
                    $umur_m  = legalisir_age_days($req->created_at);
                    $badge_m = legalisir_age_badge($req->status, $umur_m);
                ?>
                <div class="flex justify-between items-start pl-1">
                    <div class="flex items-start gap-3">
                        <input type="checkbox" name="ids[]" value="<?php echo htmlspecialchars($req->id); ?>"
                               onchange="perbaruiBar()" aria-label="Pilih pengajuan <?php echo htmlspecialchars($req->id); ?>"
                               class="pilih-baris mt-1.5 w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 cursor-pointer shrink-0">
                    <div>
                        <h2 class="font-black text-slate-800 outfit text-lg"><?php echo e($req->user_name); ?></h2>
                        <div class="flex items-center gap-2 mt-1 flex-wrap">
                            <span class="px-2 py-0.5 rounded-lg text-[9px] font-bold uppercase tracking-wider <?php echo e($badge_m); ?>"><?php echo legalisir_age_text($umur_m); ?></span>
                            <span class="text-[10px] font-extrabold text-slate-400 tracking-widest uppercase font-mono">ID: <?php echo strtoupper(substr($req->id, -8)); ?></span>
                            <?php if($req->user_nim): ?>
                                <span class="px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-600 text-[9px] font-bold border border-emerald-100">NIM Verified</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    </div>
                    <div class="flex -space-x-2">
                        <?php foreach ($docs as $doc): ?>
                            <?php 
                                $type = is_object($doc) ? $doc->type : $doc;
                                $icon = ($type == 'ijazah') ? 'scroll' : 'file-spreadsheet';
                                $color = ($type == 'ijazah') ? 'bg-blue-50 text-blue-600 border-blue-100' : 'bg-purple-50 text-purple-600 border-purple-100';
                            ?>
                            <div title="<?php echo ucfirst($type); ?>" class="w-9 h-9 rounded-xl border-2 border-white <?php echo e($color); ?> flex items-center justify-center shadow-sm">
                                <i data-lucide="<?php echo e($icon); ?>" class="w-4 h-4"></i>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Payment Info & Verification -->
                <div class="glass p-5 rounded-3xl border border-white/80 mb-6 bg-white/50 shadow-sm pl-6">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Status Pembayaran</p>
                            <?php if ($paid): ?>
                                <span class="flex items-center gap-1.5 text-emerald-600 font-black text-xs uppercase">
                                    <i data-lucide="check-circle" class="w-4 h-4"></i> LUNAS
                                </span>
                            <?php else: ?>
                                <span class="flex items-center gap-1.5 text-amber-600 font-black text-xs uppercase">
                                    <i data-lucide="clock" class="w-4 h-4"></i> PENDING
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <?php if (!$paid): ?>
                                <button type="button" onclick="openCashModal('<?php echo e($req->id); ?>')" class="flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2.5 rounded-xl shadow-lg shadow-emerald-200 active:scale-95 transition-all">
                                    <i data-lucide="banknote" class="w-4 h-4"></i>
                                    <span class="text-[10px] font-black uppercase tracking-widest">Verifikasi Manual</span>
                                </button>
                                
                                <?php if ($req->midtrans_snap_token): ?>
                                    <button onclick="payAlumniBill('<?php echo e($req->midtrans_snap_token); ?>')" class="w-11 h-11 bg-orange-50 text-orange-600 border border-orange-200 rounded-xl flex items-center justify-center shadow-sm active:scale-95 transition-all" title="Cek Midtrans">
                                        <i data-lucide="credit-card" class="w-5 h-5"></i>
                                    </button>
                                <?php endif; ?>

                                <form action="handlers/regenerate_payment.php" method="POST" class="inline">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="request_id" value="<?php echo e($req->id); ?>">
                                    <button type="submit" class="w-11 h-11 bg-blue-50 text-blue-600 border border-blue-200 rounded-xl flex items-center justify-center shadow-sm active:scale-95 transition-all" title="Buat Ulang Token Pembayaran">
                                        <i data-lucide="refresh-cw" class="w-5 h-5"></i>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="px-4 py-2 bg-emerald-50 text-emerald-600 rounded-xl text-[10px] font-black uppercase tracking-widest border border-emerald-100 shadow-sm">
                                    VIA <?php echo strtoupper($req->payment_method); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="space-y-4 pl-1">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest ml-1">Update Status & Aksi</p>
                    <div class="flex items-center gap-2.5">
                        <form action="handlers/admin_update_legalisir.php" method="POST" class="flex-1">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo e($req->id); ?>">
                            <select aria-label="Status" name="status" onchange="this.form.submit()" class="w-full text-xs font-black px-4 py-3.5 rounded-2xl border border-slate-200 bg-white outline-none focus:border-blue-500 shadow-sm cursor-pointer transition-all">
                                <option value="pending" <?php echo e($req->status == 'pending' ? 'selected' : ''); ?>>Pending</option>
                                <option value="processing" <?php echo e($req->status == 'processing' ? 'selected' : ''); ?>>Processing</option>
                                <option value="completed" <?php echo e($req->status == 'completed' ? 'selected' : ''); ?>>Completed</option>
                                <option value="rejected" <?php echo e($req->status == 'rejected' ? 'selected' : ''); ?>>Rejected</option>
                            </select>
                        </form>
                        <?php 
                            $search_nim = $req->user_nim ?: $req->user_id;
                            $wa_phone = $req->user_phone;
                            if (empty($wa_phone) && !empty($req->shipping_address)) {
                                $ship_data = json_decode($req->shipping_address);
                                if (!empty($ship_data->phone)) $wa_phone = $ship_data->phone;
                            }
                            $clean_wa = preg_replace('/[^0-9]/', '', $wa_phone);
                            if (substr($clean_wa, 0, 1) === '0') $clean_wa = '62' . substr($clean_wa, 1);
                            elseif (substr($clean_wa, 0, 2) !== '62' && !empty($clean_wa)) $clean_wa = '62' . $clean_wa;
                        ?>
                        <a href="https://pddikti.kemdiktisaintek.go.id/search/<?php echo e($search_nim); ?>" target="_blank" class="w-12 h-12 flex items-center justify-center bg-blue-50 hover:bg-blue-100 text-blue-600 rounded-2xl border border-blue-100 shadow-sm transition-all active:scale-95" title="Cek PDDikti">
                            <i data-lucide="graduation-cap" class="w-5 h-5"></i>
                        </a>
                        <?php if (!empty($clean_wa)): ?>
                            <a href="https://wa.me/<?php echo e($clean_wa); ?>" target="_blank" class="w-12 h-12 flex items-center justify-center bg-emerald-50 hover:bg-emerald-100 text-emerald-600 rounded-2xl border border-emerald-100 shadow-sm transition-all active:scale-95" title="Chat WhatsApp Alumni (<?php echo htmlspecialchars($wa_phone); ?>)">
                                <i data-lucide="message-circle" class="w-5 h-5"></i>
                            </a>
                        <?php else: ?>
                            <button onclick="showSwalAlert('WhatsApp Tidak Tersedia', 'Nomor WhatsApp alumni belum terdaftar di sistem atau alamat pengiriman.', 'warning')" class="w-12 h-12 flex items-center justify-center bg-slate-50 text-slate-300 rounded-2xl border border-slate-100 shadow-sm transition-all" title="WhatsApp Tidak Tersedia">
                                <i data-lucide="message-circle" class="w-5 h-5"></i>
                            </button>
                        <?php endif; ?>
                        <button onclick="openAdminModal('<?php echo e($req->id); ?>', '<?php echo e($req->tracking_number); ?>', '<?php echo base64_encode($req->shipping_address ?? ''); ?>')" class="w-12 h-12 flex items-center justify-center bg-blue-600 hover:bg-blue-700 text-white rounded-2xl shadow-lg shadow-blue-200 transition-all active:scale-95" title="Tracking Pengiriman">
                            <i data-lucide="truck" class="w-5 h-5"></i>
                        </button>
                        <button onclick="openDocModal(<?php echo e(json_encode($docs)); ?>, <?php echo e(json_encode($req->user_name)); ?>, '<?php echo e($search_nim); ?>', '<?php echo e($req->id); ?>')" class="w-12 h-12 flex items-center justify-center bg-purple-600 hover:bg-purple-700 text-white rounded-2xl shadow-lg shadow-purple-200 transition-all active:scale-95" title="Lihat Berkas">
                            <i data-lucide="eye" class="w-5 h-5"></i>
                        </button>
                        <a href="handlers/admin_delete_legalisir.php?id=<?php echo e($req->id); ?>&csrf_token=<?php echo get_csrf_token(); ?>" onclick="confirmDelete(event, this.href, 'Apakah Anda yakin ingin menghapus data pengajuan legalisir ini? Tindakan ini tidak dapat dibatalkan.')" class="w-12 h-12 flex items-center justify-center bg-red-50 border border-red-100 text-red-600 rounded-2xl shadow-sm transition-all active:scale-95 hover:bg-red-600 hover:text-white" title="Hapus Pengajuan">
                            <i data-lucide="trash-2" class="w-5 h-5"></i>
                        </a>
                    </div>
                    <?php if ($req->status === 'completed' && !empty($req->verification_token)): ?>
                        <a href="verify.php?token=<?php echo e($req->verification_token); ?>" target="_blank" 
                            class="inline-flex items-center gap-2 text-xs font-black text-emerald-600 bg-emerald-50 border border-emerald-100 px-4 py-3 rounded-2xl mt-2 w-full justify-center shadow-sm hover:bg-emerald-100 transition-all active:scale-[0.98]">
                            <i data-lucide="shield-check" class="w-4 h-4"></i> Verified Digital Signature
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    </form>
    <!-- /form aksi massal -->

    <div class="mt-8"></div>
    <?php
    render_pager_summary(count($requests), $l_total, $l_hal, $l_hal_n, 'pengajuan');
    render_pager($l_url, $l_hal, $l_hal_n);
    ?>
</div>

<script>
/* ── Pilihan massal ────────────────────────────────────────────────── */
function idTerpilih() {
    // Tabel desktop dan kartu mobile merender daftar yang sama, jadi tiap
    // pengajuan punya DUA kotak centang di DOM (satu disembunyikan CSS).
    // Nilainya harus dijadikan unik, kalau tidak jumlahnya terhitung dobel.
    return [...new Set(Array.from(document.querySelectorAll('.pilih-baris:checked')).map(c => c.value))];
}
function perbaruiBar() {
    const n = idTerpilih().length;
    document.getElementById('bulk-jumlah').textContent = n;
    document.getElementById('bar-bulk').classList.toggle('hidden', n === 0);
}
function pilihSemua(src) {
    document.querySelectorAll('.pilih-baris').forEach(c => { c.checked = src.checked; });
    perbaruiBar();
}
/* Saat satu kotak diubah, pasangannya di tampilan lain ikut disamakan
   supaya keduanya tidak pernah bertentangan. */
document.addEventListener('change', function (e) {
    if (!e.target.classList || !e.target.classList.contains('pilih-baris')) return;
    document.querySelectorAll('.pilih-baris').forEach(c => {
        if (c.value === e.target.value) c.checked = e.target.checked;
    });
    perbaruiBar();
});
function batalPilih() {
    document.querySelectorAll('.pilih-baris').forEach(c => { c.checked = false; });
    const sa = document.getElementById('pilih-semua');
    if (sa) sa.checked = false;
    perbaruiBar();
}

/* Penolakan WAJIB beralasan. Ini hanya kenyamanan di layar; penegakan
   sesungguhnya ada di apply_legalisir_status() pada sisi server. */
function mintaAlasan(judul) {
    return Swal.fire({
        title: judul,
        input: 'textarea',
        inputLabel: 'Alasan penolakan (dilihat alumni, minimal 10 karakter)',
        inputPlaceholder: 'Contoh: Berkas ijazah yang diunggah tidak terbaca, mohon unggah ulang hasil pindai yang jelas.',
        inputAttributes: { 'aria-label': 'Alasan penolakan' },
        showCancelButton: true,
        confirmButtonText: 'Tolak pengajuan',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#dc2626',
        inputValidator: (v) => (!v || v.trim().length < 10) ? 'Alasan minimal 10 karakter.' : undefined
    });
}

function ubahStatus(sel) {
    const form = sel.form;
    if (sel.value !== 'rejected') { form.submit(); return; }
    mintaAlasan('Tolak pengajuan ini?').then(r => {
        if (r.isConfirmed) {
            form.querySelector('input[name="rejection_reason"]').value = r.value;
            form.submit();
        } else {
            sel.value = sel.dataset.semula;   // kembalikan pilihan semula
        }
    });
}

function jalankanBulk() {
    const form   = document.getElementById('form-bulk');
    const status = document.getElementById('bulk-status').value;
    const n      = idTerpilih().length;
    if (n === 0) return;

    const lanjut = (alasan) => {
        document.getElementById('bulk-alasan').value = alasan || '';
        form.submit();
    };

    if (status === 'rejected') {
        mintaAlasan('Tolak ' + n + ' pengajuan sekaligus?').then(r => { if (r.isConfirmed) lanjut(r.value); });
        return;
    }
    Swal.fire({
        title: 'Ubah ' + n + ' pengajuan?',
        html: 'Status akan menjadi <b>' + document.getElementById('bulk-status').selectedOptions[0].text +
              '</b>.<br><span style="color:#b45309">Setiap alumni akan menerima e-mail pemberitahuan.</span>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Ya, ubah',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#0f172a'
    }).then(r => { if (r.isConfirmed) lanjut(''); });
}

function cetakLabelTerpilih() {
    const ids = idTerpilih();
    if (!ids.length) return;
    window.open('cetak_label.php?ids=' + encodeURIComponent(ids.join(',')), '_blank');
}
</script>

<!-- Admin Edit Modal -->
<div id="adminEditModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm hidden">
    <div class="glass w-full max-w-md p-8 rounded-[2.5rem] shadow-2xl relative">
        <button onclick="document.getElementById('adminEditModal').classList.add('hidden')" class="absolute top-6 right-6 text-slate-400 hover:text-slate-600">
            <i data-lucide="x" class="w-6 h-6"></i>
        </button>
        
        <h2 class="text-2xl font-bold outfit mb-6">Update Pengiriman</h2>
        
        <div id="shippingInfoBox"></div>
        
        <form action="handlers/admin_update_legalisir.php" method="POST" class="space-y-6">
            <?php csrf_field(); ?>
            <input type="hidden" name="id" id="modal_req_id">
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2 ml-1">Nomor Resi / Tracking</label>
                <input aria-label="Masukkan Nomor Resi" type="text" name="tracking_number" id="modal_tracking" class="w-full px-5 py-3.5 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none" placeholder="Masukkan Nomor Resi">
            </div>
            
            <div class="flex gap-3 pt-2">
                <button type="submit" class="flex-1 py-4 bg-blue-600 text-white rounded-2xl font-bold shadow-lg shadow-blue-200 hover:bg-blue-700 transition-all text-sm">
                    Simpan Perubahan
                </button>
                <a id="printLabelBtn" href="#" target="_blank" class="px-5 py-4 bg-emerald-600 text-white rounded-2xl font-bold shadow-lg shadow-emerald-200 hover:bg-emerald-700 transition-all flex items-center justify-center gap-2 text-sm hidden">
                    <i data-lucide="printer" class="w-4 h-4"></i>
                    Cetak Label
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Document Preview Modal (Inline) -->
<div id="docModal" class="fixed inset-0 z-[60] flex items-end md:items-center justify-center p-0 md:p-4 bg-slate-900/50 backdrop-blur-sm hidden">
    <div class="glass w-full md:max-w-2xl max-h-[90vh] rounded-t-[2.5rem] md:rounded-[2.5rem] shadow-2xl relative overflow-hidden flex flex-col">
        <!-- Modal Header -->
        <div class="flex items-center justify-between p-6 border-b border-white/20 shrink-0">
            <div>
                <h2 class="text-lg font-bold outfit">Preview Berkas</h2>
                <p id="docModalSubtitle" class="text-xs text-slate-500 mt-0.5"></p>
            </div>
            <button onclick="document.getElementById('docModal').classList.add('hidden')" class="w-9 h-9 rounded-full glass flex items-center justify-center text-slate-500 hover:text-slate-800">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <!-- Source Switcher Bar -->
        <div id="sourceSwitcherBar" class="px-6 py-4 bg-slate-50/80 border-b border-slate-200/60 flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0 hidden">
            <div class="flex items-center gap-3">
                <div id="sourceIconBox" class="w-10 h-10 rounded-2xl bg-blue-100 text-blue-600 flex items-center justify-center shrink-0 shadow-inner">
                    <i data-lucide="file-check" class="w-5 h-5"></i>
                </div>
                <div>
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-0.5">Status Validitas Berkas</span>
                    <div class="flex items-center gap-2">
                        <span id="activeSourceBadge" class="px-2.5 py-1 rounded-lg text-xs font-bold uppercase tracking-wider shadow-sm">Versi Alumni</span>
                        <span id="repoAvailabilityText" class="text-xs text-slate-500 font-medium"></span>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <button id="btnSwitchAlumni" onclick="toggleDocSource('alumni')" class="flex-1 md:flex-none px-4 py-2.5 rounded-xl text-xs font-bold border transition-all flex items-center justify-center gap-1.5 shadow-sm">
                    <i data-lucide="user" class="w-4 h-4"></i> Pakai Versi Alumni
                </button>
                <button id="btnSwitchRepo" onclick="toggleDocSource('repository')" class="flex-1 md:flex-none px-4 py-2.5 rounded-xl text-xs font-bold border transition-all flex items-center justify-center gap-1.5 shadow-sm">
                    <i data-lucide="building-2" class="w-4 h-4"></i> Pakai Versi Repositori
                </button>
            </div>
        </div>

        <!-- Tab Switcher (if multiple docs) -->
        <div id="docTabs" class="flex gap-2 px-6 pt-4 shrink-0 overflow-x-auto"></div>

        <!-- Preview Area -->
        <div id="docPreviewArea" class="flex-1 overflow-auto p-6 min-h-[300px] flex items-center justify-center">
            <p class="text-slate-400 italic text-sm">Memuat berkas...</p>
        </div>

        <!-- Download Bar -->
        <div id="docDownloadBar" class="px-6 py-4 border-t border-white/20 flex items-center justify-between gap-3 shrink-0 bg-white/30">
            <span id="docCurrentName" class="text-sm text-slate-600 font-medium truncate"></span>
            <a id="docDownloadBtn" href="#" target="_blank" class="shrink-0 flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-xl text-sm font-bold hover:bg-blue-700 transition-all">
                <i data-lucide="download" class="w-4 h-4"></i> Unduh
            </a>
        </div>
    </div>
</div>

<!-- Cash Verification Modal -->
<div id="cashModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm hidden">
    <div class="glass w-full max-w-md p-8 rounded-[2.5rem] shadow-2xl relative">
        <button onclick="document.getElementById('cashModal').classList.add('hidden')" class="absolute top-6 right-6 text-slate-400 hover:text-slate-600">
            <i data-lucide="x" class="w-6 h-6"></i>
        </button>
        
        <div class="text-center mb-6">
            <div class="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto mb-4">
                <i data-lucide="banknote" class="w-8 h-8"></i>
            </div>
            <h2 class="text-2xl font-bold outfit">Verifikasi Tunai</h2>
            <p class="text-slate-500 mt-2 text-sm">Apakah Anda yakin alumni ini sudah membayar secara tunai (cash)? Status akan diubah menjadi LUNAS.</p>
        </div>
        
        <form action="handlers/admin_verify_cash.php" method="GET" class="flex gap-4">
            <?php csrf_field(); ?>
            <input type="hidden" name="id" id="modal_cash_id">
            <button type="button" onclick="document.getElementById('cashModal').classList.add('hidden')" class="flex-1 py-4 bg-slate-100 text-slate-600 rounded-2xl font-bold hover:bg-slate-200 transition-all">
                Batal
            </button>
            <button type="submit" class="flex-1 py-4 bg-emerald-600 text-white rounded-2xl font-bold shadow-lg shadow-emerald-200 hover:bg-emerald-700 transition-all">
                Lanjut Cash
            </button>
        </form>
    </div>
</div>

<script>
    function openCashModal(id) {
        document.getElementById('modal_cash_id').value = id;
        document.getElementById('cashModal').classList.remove('hidden');
    }

    function openAdminModal(id, tracking, shippingB64) {
        document.getElementById('modal_req_id').value = id;
        document.getElementById('modal_tracking').value = tracking || '';
        
        const printBtn = document.getElementById('printLabelBtn');
        const shippingInfoBox = document.getElementById('shippingInfoBox');
        
        if (shippingB64 && shippingB64.trim() !== '') {
            try {
                const shippingJson = decodeURIComponent(escape(atob(shippingB64)));
                const addr = JSON.parse(shippingJson);
                let html = `<div class="p-5 bg-slate-50 border border-slate-200 rounded-2xl mb-6 text-xs text-slate-600 space-y-1.5 shadow-sm">
                    <div class="font-bold text-slate-800 text-sm mb-3 flex items-center justify-between border-b border-slate-200/60 pb-2">
                        <span class="flex items-center gap-2"><i data-lucide="map-pin" class="w-4 h-4 text-blue-600"></i> Detail Alamat Penerima</span>
                        <span class="px-2.5 py-1 bg-blue-100 text-blue-700 rounded-lg text-[10px] font-black uppercase tracking-wider">Kurir</span>
                    </div>
                    <p><span class="font-semibold text-slate-700">Penerima:</span> ${addr.name || '-'} (${addr.phone || '-'})</p>
                    <p><span class="font-semibold text-slate-700">Alamat:</span> ${addr.street || '-'}</p>
                    <p><span class="font-semibold text-slate-700">Wilayah:</span> ${addr.district || '-'}, ${addr.city || '-'}, ${addr.province || '-'} ${addr.postal || ''}</p>
                </div>`;
                shippingInfoBox.innerHTML = html;
                printBtn.href = 'cetak_label.php?id=' + id;
                printBtn.classList.remove('hidden');
                if (window.lucide) window.lucide.createIcons();
            } catch(e) {
                shippingInfoBox.innerHTML = '<div class="p-4 bg-amber-50 border border-amber-200 text-amber-700 rounded-2xl mb-6 text-xs font-medium">Alamat pengiriman tidak tersedia / format tidak valid.</div>';
                printBtn.classList.add('hidden');
            }
        } else {
            shippingInfoBox.innerHTML = '<div class="p-4 bg-slate-50 border border-slate-200 text-slate-500 rounded-2xl mb-6 text-xs font-medium">Pengajuan ini tidak menggunakan metode pengiriman kurir atau alamat belum diatur.</div>';
            printBtn.classList.add('hidden');
        }

        document.getElementById('adminEditModal').classList.remove('hidden');
    }

    let currentReqId = '';
    let currentNim = '';
    let currentRepoDocs = [];

    function openDocModal(docsJson, alumniName, nim, reqId) {
        currentDocs = JSON.parse(docsJson);
        currentDocIndex = 0;
        currentReqId = reqId;
        currentNim = nim;
        currentRepoDocs = [];

        document.getElementById('docModalSubtitle').textContent = alumniName || '';
        document.getElementById('sourceSwitcherBar').classList.remove('hidden');
        
        renderDocTabs();
        showDoc(0);
        document.getElementById('docModal').classList.remove('hidden');

        // Fetch repository availability
        if (nim) {
            fetch('handlers/admin_check_repository.php?nim=' + nim)
                .then(res => res.json())
                .then(data => {
                    if (data && data.length > 0) {
                        currentRepoDocs = data;
                    } else {
                        currentRepoDocs = [];
                    }
                    showDoc(currentDocIndex); // re-render switcher bar
                })
                .catch(err => console.error(err));
        }
    }

    function toggleDocSource(targetSource) {
        const doc = currentDocs[currentDocIndex];
        const type = typeof doc === 'object' ? (doc.type || doc.id) : doc;
        
        const formData = new FormData();
        formData.append('req_id', currentReqId);
        formData.append('nim', currentNim);
        formData.append('doc_type', type);
        formData.append('target_source', targetSource);
        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
        formData.append('csrf_token', csrfToken);

        fetch('handlers/admin_toggle_document_source.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showSwalAlert('Berhasil', `Berhasil beralih ke versi ${targetSource === 'repository' ? 'Repositori Resmi' : 'Upload Alumni'}!`, 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showSwalAlert('Gagal', 'Gagal: ' + (data.error || 'Terjadi kesalahan sistem'), 'error');
            }
        })
        .catch(err => {
            console.error(err);
            showSwalAlert('Kesalahan Jaringan', 'Terjadi kesalahan jaringan saat menghubungi server.', 'error');
        });
    }

    function renderDocTabs() {
        const tabs = document.getElementById('docTabs');
        tabs.innerHTML = '';
        if (currentDocs.length <= 1) { tabs.classList.add('hidden'); return; }
        tabs.classList.remove('hidden');
        currentDocs.forEach((doc, i) => {
            const type = typeof doc === 'object' ? (doc.type || doc.id) : doc;
            const btn = document.createElement('button');
            btn.className = `px-4 py-2 rounded-xl text-xs font-bold transition-all ${i === 0 ? 'bg-blue-600 text-white' : 'glass text-slate-600 hover:bg-white/80'}`;
            btn.textContent = type.charAt(0).toUpperCase() + type.slice(1);
            btn.onclick = () => showDoc(i);
            btn.id = `docTab_${i}`;
            tabs.appendChild(btn);
        });
    }

    function showDoc(index) {
        currentDocIndex = index;
        const doc = currentDocs[index];
        const file = typeof doc === 'object' ? doc.file : null;
        const type = typeof doc === 'object' ? (doc.type || doc.id) : doc;
        const source = typeof doc === 'object' ? (doc.source || 'alumni') : 'alumni';

        // Update active tab
        document.querySelectorAll('[id^="docTab_"]').forEach((btn, i) => {
            btn.className = `px-4 py-2 rounded-xl text-xs font-bold transition-all ${i === index ? 'bg-blue-600 text-white' : 'glass text-slate-600 hover:bg-white/80'}`;
        });

        // Update Source Switcher Bar UI
        const activeBadge = document.getElementById('activeSourceBadge');
        const repoText = document.getElementById('repoAvailabilityText');
        const btnAlumni = document.getElementById('btnSwitchAlumni');
        const btnRepo = document.getElementById('btnSwitchRepo');

        const isAkreditasi = type.toLowerCase().includes('akreditasi');

        if (isAkreditasi) {
            // Set source switcher bar to indicate Master Accreditation mode
            activeBadge.textContent = 'Master Akreditasi Prodi';
            activeBadge.className = 'px-2.5 py-1 rounded-lg text-xs font-bold uppercase tracking-wider bg-emerald-100 text-emerald-700 shadow-sm';
            btnAlumni.disabled = true;
            btnAlumni.className = 'hidden';
            btnRepo.disabled = true;
            btnRepo.className = 'hidden';

            repoText.textContent = '⏳ Memeriksa Master Akreditasi...';
            repoText.className = 'text-xs text-slate-400 font-medium';

            const area = document.getElementById('docPreviewArea');
            const dlBtn = document.getElementById('docDownloadBtn');
            const dlName = document.getElementById('docCurrentName');

            fetch('handlers/get_accreditation_file.php?nim=' + currentNim)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        repoText.textContent = `✅ Master Akreditasi Tersedia (${data.start_year}-${data.end_year})`;
                        repoText.className = 'text-xs text-emerald-600 font-bold';
                        // Sertifikat akreditasi juga dilayani serve_document.php,
                        // dirujuk lewat id-nya dan bukan lewat path berkas.
                        const accredUrl = `serve_document.php?ctx=accreditation&id=${encodeURIComponent(data.id)}`;
                        dlBtn.classList.remove('hidden');
                        dlBtn.href = accredUrl;
                        dlName.textContent = data.certificate_name;

                        const ext = data.file_path.split('.').pop().toLowerCase();
                        if (['jpg','jpeg','png','webp','gif'].includes(ext)) {
                            area.innerHTML = `<img src="${accredUrl}" alt="${type}" class="max-w-full max-h-[50vh] object-contain rounded-2xl shadow-md">`;
                        } else if (ext === 'pdf') {
                            area.innerHTML = `<iframe src="${accredUrl}" class="w-full h-[50vh] rounded-2xl border border-slate-200" title="${type}"></iframe>`;
                        } else {
                            area.innerHTML = `<div class="text-center"><div class="w-16 h-16 bg-emerald-100 rounded-full flex items-center justify-center mx-auto mb-3"><i data-lucide="award" class="w-8 h-8 text-emerald-500"></i></div><p class="text-slate-500 text-sm">Format tidak bisa dipreview.<br>Klik unduh untuk membuka.</p></div>`;
                        }
                    } else {
                        repoText.textContent = `⚠️ ${data.message || 'Master akreditasi belum diunggah'}`;
                        repoText.className = 'text-xs text-red-500 font-bold';
                        dlBtn.classList.add('hidden');
                        area.innerHTML = `<div class="text-center"><div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-3"><i data-lucide="alert-triangle" class="w-8 h-8 text-red-500"></i></div><p class="text-slate-500 text-sm font-bold">Master Akreditasi Belum Tersedia</p><p class="text-xs text-slate-400 mt-1">Silakan minta admin mengunggah Master Akreditasi untuk prodi ${data.major_code || ''} periode kelulusan ${data.user_year || ''} di menu Repositori Dokumen.</p></div>`;
                    }
                    lucide.createIcons();
                })
                .catch(err => {
                    console.error(err);
                    repoText.textContent = '⚠️ Gagal memuat data akreditasi';
                    repoText.className = 'text-xs text-red-500 font-bold';
                });
            return;
        }

        if (source === 'repository') {
            activeBadge.textContent = 'Versi Repositori Resmi';
            activeBadge.className = 'px-2.5 py-1 rounded-lg text-xs font-bold uppercase tracking-wider bg-blue-100 text-blue-700 shadow-sm';
            btnRepo.disabled = true;
            btnRepo.className = 'flex-1 md:flex-none px-4 py-2.5 rounded-xl text-xs font-bold border bg-slate-100 text-slate-400 cursor-not-allowed flex items-center justify-center gap-1.5 shadow-sm';
            btnAlumni.disabled = false;
            btnAlumni.className = 'flex-1 md:flex-none px-4 py-2.5 rounded-xl text-xs font-bold border bg-white hover:bg-slate-50 text-slate-700 transition-all flex items-center justify-center gap-1.5 shadow-sm';
        } else {
            activeBadge.textContent = 'Versi Upload Alumni';
            activeBadge.className = 'px-2.5 py-1 rounded-lg text-xs font-bold uppercase tracking-wider bg-amber-100 text-amber-700 shadow-sm';
            btnAlumni.disabled = true;
            btnAlumni.className = 'flex-1 md:flex-none px-4 py-2.5 rounded-xl text-xs font-bold border bg-slate-100 text-slate-400 cursor-not-allowed flex items-center justify-center gap-1.5 shadow-sm';
            btnRepo.disabled = false;
            btnRepo.className = 'flex-1 md:flex-none px-4 py-2.5 rounded-xl text-xs font-bold border bg-blue-600 hover:bg-blue-700 text-white transition-all flex items-center justify-center gap-1.5 shadow-sm';
        }

        // Check if repo match exists in currentRepoDocs
        const cleanType = type.toLowerCase().replace(/[^a-z0-9_]/g, '').replace(/ /g, '_');
        let repoMatch = currentRepoDocs.find(r => r.document_type.toLowerCase() === cleanType);
        if (!repoMatch) {
            repoMatch = currentRepoDocs.find(r => r.document_type.toLowerCase().includes(cleanType) || cleanType.includes(r.document_type.toLowerCase()));
        }

        if (repoMatch) {
            repoText.textContent = '✅ Tersedia di Repositori';
            repoText.className = 'text-xs text-green-600 font-bold';
        } else {
            repoText.textContent = '⚠️ Belum diupload di Repositori';
            repoText.className = 'text-xs text-slate-400 font-medium';
            if (source !== 'repository') {
                btnRepo.disabled = true;
                btnRepo.className = 'flex-1 md:flex-none px-4 py-2.5 rounded-xl text-xs font-bold border bg-slate-100 text-slate-400 cursor-not-allowed flex items-center justify-center gap-1.5 shadow-sm';
            }
        }

        const area = document.getElementById('docPreviewArea');
        const dlBtn = document.getElementById('docDownloadBtn');
        const dlName = document.getElementById('docCurrentName');

        if (!file || file === '#') {
            area.innerHTML = `<div class="text-center"><div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-3"><i data-lucide="file-x" class="w-8 h-8 text-slate-400"></i></div><p class="text-slate-400 text-sm">File tidak tersedia</p></div>`;
            dlBtn.classList.add('hidden');
        } else {
            const ext = file.split('.').pop().toLowerCase();
            // Berkas tidak lagi ditautkan langsung ke uploads/ (folder tersebut
            // kini tertutup .htaccess). serve_document.php memeriksa peran staf
            // atau kepemilikan pengajuan sebelum mengirim berkas.
            const fileUrl = `serve_document.php?ctx=legalisir&req=${encodeURIComponent(currentReqId)}&i=${index}`;
            dlBtn.classList.remove('hidden');
            dlBtn.href = fileUrl;
            dlName.textContent = type.charAt(0).toUpperCase() + type.slice(1) + '.' + ext;

            if (['jpg','jpeg','png','webp','gif'].includes(ext)) {
                area.innerHTML = `<img src="${fileUrl}" alt="${type}" class="max-w-full max-h-[50vh] object-contain rounded-2xl shadow-md">`;
            } else if (ext === 'pdf') {
                area.innerHTML = `<iframe src="${fileUrl}" class="w-full h-[50vh] rounded-2xl border border-slate-200" title="${type}"></iframe>`;
            } else {
                area.innerHTML = `<div class="text-center"><div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-3"><i data-lucide="file" class="w-8 h-8 text-blue-500"></i></div><p class="text-slate-500 text-sm">Format tidak bisa dipreview.<br>Klik unduh untuk membuka.</p></div>`;
            }
        }

        lucide.createIcons();
    }

    function payAlumniBill(token) {
        window.snap.pay(token, {
            onSuccess: function(result) { location.reload(); },
            onPending: function(result) { location.reload(); },
            onError: function(result) { showSwalAlert('Pembayaran Gagal', 'Proses pembayaran gagal atau dibatalkan.', 'error'); }
        });
    }
</script>

<script src="<?php echo e($snap_url); ?>" data-client-key="<?php echo e($client_key); ?>"></script>
