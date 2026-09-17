<?php
require_once __DIR__ . '/../includes/payment/service.php';

$id = $_GET['id'] ?? null;
$user_id = $_SESSION['user_id'];

if (!$id) {
    echo "<script>window.location.href='index.php?page=legalisir';</script>";
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM legalisir_requests WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $user_id]);
$req = $stmt->fetch();

if (!$req) {
    echo "<div class='glass p-10 rounded-3xl text-center'><h3 class='text-xl font-bold'>Data tidak ditemukan.</h3></div>";
    exit();
}

$docs = json_decode($req->documents);

// ── Pembayaran ───────────────────────────────────────────────────────
// Area bayar dibangun dari transaksi di ledger, bukan dari kolom
// midtrans_snap_token. Gateway yang berbeda membuka pembayaran dengan cara
// berbeda: Midtrans lewat popup Snap, Flip lewat halaman bayarnya sendiri.
$transaksi   = payment_txns_for_subject('legalisir', $req->id);
$txn_terbaru = $transaksi[0] ?? null;
$txn_bayar   = payment_txn_payable('legalisir', $req->id);
$aksi_bayar  = $txn_bayar ? payment_gateway($txn_bayar->gateway)->frontendAction($txn_bayar) : ['type' => 'none'];
$label_bayar = payment_gateway_label($txn_bayar->gateway ?? payment_active_gateway_code());

// Rincian biaya yang TERSIMPAN saat tagihan terbit. Halaman ini dulu
// menghitung ulang dari setting SAAT INI, sehingga begitu tarif diubah,
// rincian tagihan lama ikut berubah dan tidak lagi berjumlah sama.
$rincian = null;
foreach ($transaksi as $t) {
    if ($t->fee_breakdown && ($r = json_decode($t->fee_breakdown, true)) && isset($r['documents'], $r['admin_total'])) {
        $rincian = $r;
        break;
    }
}
?>

<div class="max-w-4xl mx-auto">
    <div class="mb-8 flex items-center gap-4">
        <a href="index.php?page=legalisir" class="w-10 h-10 glass rounded-full flex items-center justify-center text-slate-500 hover:text-blue-600 transition-all">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <h3 class="text-2xl font-bold outfit">Detail Permintaan #<?php echo e($req->id); ?></h3>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
        <!-- Main Info -->
        <div class="md:col-span-2 space-y-6">
            <div class="glass p-8 rounded-[2.5rem] shadow-sm">
                <h4 class="text-lg font-bold outfit mb-6 flex items-center gap-2">
                    <i data-lucide="info" class="w-5 h-5 text-blue-600"></i>
                    Informasi Dokumen
                </h4>
                <div class="space-y-4">
                    <div class="py-3 border-b border-white/20">
                        <span class="text-slate-500 block mb-3 text-sm">Berkas yang Diunggah:</span>
                        <div class="grid grid-cols-1 gap-2">
                            <?php foreach ($docs as $doc_index => $doc): ?>
                                <?php
                                    // Dilayani serve_document.php (konteks 'legalisir'), yang
                                    // memastikan pengajuan ini memang milik akun yang sedang
                                    // masuk -- atau pengaksesnya adalah staf.
                                    $doc_url = 'serve_document.php?ctx=legalisir&req=' . urlencode($req->id)
                                             . '&i=' . (int)$doc_index;
                                ?>
                                <a href="<?php echo e($doc_url); ?>" target="_blank" class="flex items-center justify-between p-3 bg-white/40 border border-slate-100 rounded-2xl hover:bg-white/60 transition-all">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 <?php echo e($doc->type == 'ijazah' ? 'bg-blue-100 text-blue-600' : 'bg-purple-100 text-purple-600'); ?> rounded-lg flex items-center justify-center">
                                            <i data-lucide="<?php echo e($doc->type == 'ijazah' ? 'scroll' : 'file-spreadsheet'); ?>" class="w-4 h-4"></i>
                                        </div>
                                        <span class="text-sm font-semibold text-slate-800 capitalize"><?php echo e($doc->type); ?></span>
                                    </div>
                                    <i data-lucide="external-link" class="w-4 h-4 text-slate-400"></i>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="flex justify-between py-3 border-b border-white/20">
                        <span class="text-slate-500">Metode Pengiriman:</span>
                        <span class="font-medium"><?php echo e(ucwords(str_replace('_', ' ', $req->delivery_method))); ?></span>
                    </div>
                    <?php
                        if ($rincian) {
                            $biaya_dokumen = (int)$rincian['documents'];
                            $biaya_pengiriman = (int)($rincian['shipping'] ?? 0);
                            $biaya_admin_dan_layanan = (int)$rincian['admin_total'];
                        } else {
                            // Pengajuan lama tanpa rincian tersimpan: perkiraan dari
                            // setting saat ini, sisa selisih menjadi biaya layanan.
                            $biaya_dokumen = count($docs) * (int)setting('price_per_doc', '10000');
                            $biaya_pengiriman = 0;
                            if ($req->delivery_method === 'kurir' && $req->shipping_address) {
                                $addr = json_decode($req->shipping_address, true) ?: [];
                                $biaya_pengiriman = payment_shipping_cost($addr['province'] ?? '');
                            }
                            $biaya_admin_dan_layanan = max(0, (int)$req->amount - $biaya_dokumen - $biaya_pengiriman);
                        }
                    ?>
                    <div class="flex justify-between py-2">
                        <span class="text-slate-500">Biaya Dokumen:</span>
                        <span class="font-medium">Rp <?php echo number_format($biaya_dokumen, 0, ',', '.'); ?></span>
                    </div>
                    <div class="flex justify-between py-2">
                        <span class="text-slate-500">Biaya Admin & Layanan:</span>
                        <span class="font-medium">Rp <?php echo number_format($biaya_admin_dan_layanan, 0, ',', '.'); ?></span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-white/20">
                        <span class="text-slate-500">Biaya Pengiriman:</span>
                        <span class="font-medium">Rp <?php echo number_format($biaya_pengiriman, 0, ',', '.'); ?></span>
                    </div>
                    <div class="flex justify-between py-3 mt-1">
                        <span class="text-slate-800 font-bold">TOTAL BAYAR:</span>
                        <span class="font-black text-blue-600 text-lg">Rp <?php echo number_format($req->amount, 0, ',', '.'); ?></span>
                    </div>
                </div>

                <?php
                    $url_success = $_GET['success'] ?? null;
                    $url_error   = $_GET['error']   ?? null;
                ?>

                <?php if ($req->status == 'rejected'): ?>
                    <!-- ❌ Status: Ditolak -->
                    <div class="mt-8 overflow-hidden rounded-3xl border border-red-100">
                        <div class="bg-red-500 px-6 py-4 flex items-center gap-3">
                            <div class="w-9 h-9 bg-white/20 rounded-xl flex items-center justify-center shrink-0">
                                <i data-lucide="x-circle" class="w-5 h-5 text-white"></i>
                            </div>
                            <p class="text-sm font-black text-white">Pengajuan Dibatalkan oleh Admin</p>
                        </div>
                        <div class="bg-red-50 px-6 py-5">
                            <?php
                                // Kolom rejection_reason sudah lama ada di skema
                                // tetapi tidak pernah ditulis maupun dibaca, jadi
                                // alumni yang ditolak tidak pernah tahu sebabnya.
                                // Pengajuan lama tetap memakai kalimat umum.
                                $alasan_tolak = trim((string)($req->rejection_reason ?? ''));
                            ?>
                            <?php if ($alasan_tolak !== ''): ?>
                                <p class="text-[10px] font-black text-red-400 uppercase tracking-widest mb-2">Alasan penolakan</p>
                                <p class="text-sm text-red-700 font-medium leading-relaxed"><?php echo nl2br(htmlspecialchars($alasan_tolak)); ?></p>
                                <p class="text-sm text-red-600/80 leading-relaxed mt-3">
                                    Setelah diperbaiki, silakan buat pengajuan baru dari halaman Legalisir.
                                </p>
                            <?php else: ?>
                                <p class="text-sm text-red-700 font-medium leading-relaxed">
                                    Pengajuan ini telah ditolak. Jika ada kesalahan data, silakan buat pengajuan baru dari halaman Legalisir.
                                </p>
                            <?php endif; ?>
                            <a href="index.php?page=legalisir" class="mt-4 inline-flex items-center gap-2 text-sm font-bold text-red-600 hover:text-red-700 transition-colors">
                                <i data-lucide="plus-circle" class="w-4 h-4"></i>
                                Buat Pengajuan Baru
                            </a>
                        </div>
                    </div>

                <?php elseif ($req->payment_status != 'settlement'): ?>

                    <!-- ✅ Alert Sukses Regenerate Token -->
                    <?php if ($url_success === 'token_regenerated'): ?>
                    <div id="alert-success" class="mt-6 flex items-start gap-3 bg-emerald-50 border border-emerald-200 text-emerald-800 px-5 py-4 rounded-2xl">
                        <i data-lucide="check-circle-2" class="w-5 h-5 text-emerald-600 shrink-0 mt-0.5"></i>
                        <div>
                            <p class="text-sm font-bold">Token Berhasil Diperbarui!</p>
                            <p class="text-xs font-medium opacity-80 mt-0.5">Sesi pembayaran baru aktif selama 24 jam. Silakan lanjutkan pembayaran.</p>
                        </div>
                        <button onclick="document.getElementById('alert-success').remove()" class="ml-auto text-emerald-500 hover:text-emerald-700 transition-colors shrink-0">
                            <i data-lucide="x" class="w-4 h-4"></i>
                        </button>
                    </div>
                    <?php endif; ?>

                    <?php $url_payment = $_GET['payment'] ?? null; ?>
                    <?php if ($url_payment === 'pending'): ?>
                    <div class="mt-6 flex items-start gap-3 bg-blue-50 border border-blue-200 text-blue-800 px-5 py-4 rounded-2xl">
                        <i data-lucide="loader" class="w-5 h-5 text-blue-500 shrink-0 mt-0.5"></i>
                        <div>
                            <p class="text-sm font-bold">Pembayaran Belum Terkonfirmasi</p>
                            <p class="text-xs font-medium opacity-80 mt-0.5">Bila Anda sudah membayar, status diperbarui otomatis dalam beberapa menit. Tidak perlu membayar lagi.</p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- ❗ Alert Error Regenerate Token -->
                    <?php if ($url_error === 'midtrans_failed' || $url_error === 'payment_create_failed'): ?>
                    <div id="alert-error" class="mt-6 flex items-start gap-3 bg-red-50 border border-red-200 text-red-800 px-5 py-4 rounded-2xl">
                        <i data-lucide="alert-triangle" class="w-5 h-5 text-red-500 shrink-0 mt-0.5"></i>
                        <div>
                            <p class="text-sm font-bold">Gagal Membuat Tagihan</p>
                            <p class="text-xs font-medium opacity-80 mt-0.5">Tagihan belum dapat dibuat karena layanan pembayaran tidak merespons. Silakan coba lagi beberapa saat lagi.</p>
                        </div>
                        <button onclick="document.getElementById('alert-error').remove()" class="ml-auto text-red-400 hover:text-red-600 transition-colors shrink-0">
                            <i data-lucide="x" class="w-4 h-4"></i>
                        </button>
                    </div>
                    <?php endif; ?>

                    <!-- 💳 Blok Pembayaran Utama -->
                    <div class="mt-8 overflow-hidden rounded-3xl border border-orange-100 shadow-sm shadow-orange-100/50">

                        <!-- Header Banner -->
                        <div class="bg-gradient-to-r from-orange-500 to-amber-500 px-6 py-4 flex items-center gap-3">
                            <div class="w-9 h-9 bg-white/20 rounded-xl flex items-center justify-center shrink-0">
                                <i data-lucide="clock" class="w-5 h-5 text-white"></i>
                            </div>
                            <div>
                                <p class="text-sm font-black text-white">Menunggu Pembayaran</p>
                                <p class="text-[11px] text-orange-100 font-medium">Selesaikan pembayaran agar dokumen dapat diproses</p>
                            </div>
                        </div>

                        <!-- Ringkasan Tagihan -->
                        <div class="bg-white px-6 py-5 border-b border-orange-50">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Tagihan</p>
                                    <p class="text-2xl font-black text-orange-600 outfit mt-1">Rp <?php echo number_format($req->amount, 0, ',', '.'); ?></p>
                                </div>
                                <div class="text-right">
                                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">ID Pesanan</p>
                                    <p class="text-xs font-mono font-bold text-slate-700 mt-1 break-all"><?php echo htmlspecialchars($req->id); ?></p>
                                </div>
                            </div>
                        </div>

                        <!-- Tombol Aksi Utama -->
                        <div class="bg-orange-50 px-6 py-6 space-y-3">
                            <?php if ($aksi_bayar['type'] === 'snap'): ?>
                                <button id="pay-button" type="button"
                                    class="w-full py-4 bg-orange-500 text-white rounded-2xl font-black text-base shadow-lg shadow-orange-200 hover:bg-orange-600 active:scale-95 transition-all flex items-center justify-center gap-3 group">
                                    <i data-lucide="credit-card" class="w-5 h-5 group-hover:scale-110 transition-transform"></i>
                                    Bayar Sekarang
                                </button>
                            <?php elseif ($aksi_bayar['type'] === 'redirect'): ?>
                                <!-- Flip: pembayaran dilanjutkan di halaman bayar Flip, lalu
                                     kembali ke handlers/payment_return.php -->
                                <a href="<?php echo e($aksi_bayar['url']); ?>" rel="noopener"
                                    class="w-full py-4 bg-orange-500 text-white rounded-2xl font-black text-base shadow-lg shadow-orange-200 hover:bg-orange-600 active:scale-95 transition-all flex items-center justify-center gap-3 group">
                                    <i data-lucide="credit-card" class="w-5 h-5 group-hover:scale-110 transition-transform"></i>
                                    Bayar Sekarang
                                </a>
                            <?php else: ?>
                                <div class="flex items-center gap-2 mb-3">
                                    <i data-lucide="alert-triangle" class="w-4 h-4 text-amber-500 shrink-0"></i>
                                    <p class="text-sm text-amber-700 font-medium">
                                        <?php if ($txn_terbaru && in_array($txn_terbaru->status, ['expired', 'cancelled'], true)): ?>
                                            Tagihan sebelumnya sudah kedaluwarsa. Buat tagihan baru di bawah.
                                        <?php elseif ($txn_terbaru && $txn_terbaru->status === 'failed'): ?>
                                            Pembayaran sebelumnya gagal. Buat tagihan baru di bawah.
                                        <?php else: ?>
                                            Tagihan pembayaran belum tersedia. Buat tagihan di bawah.
                                        <?php endif; ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                            <?php if ($aksi_bayar['type'] !== 'none'): ?>
                                <p class="text-center text-[10px] text-orange-400 font-medium">
                                    Diproses aman oleh <?php echo e($label_bayar); ?> · QRIS · Virtual Account · E-Wallet
                                </p>
                            <?php endif; ?>

                            <!-- Divider -->
                            <div class="flex items-center gap-3 py-1">
                                <div class="flex-1 h-px bg-orange-200"></div>
                                <span class="text-[10px] font-black text-orange-300 uppercase tracking-widest">atau</span>
                                <div class="flex-1 h-px bg-orange-200"></div>
                            </div>

                            <!-- Tombol Perbarui Token -->
                            <form action="handlers/regenerate_payment.php" method="POST" id="regen-form">
                                <?php if (function_exists('csrf_field')): csrf_field(); endif; ?>
                                <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($req->id); ?>">
                                <button type="submit" id="regen-btn"
                                    class="w-full py-3.5 bg-white border-2 border-slate-200 text-slate-700 rounded-2xl font-bold text-sm hover:border-blue-400 hover:text-blue-600 hover:bg-blue-50 active:scale-95 transition-all flex items-center justify-center gap-2.5 group">
                                    <i data-lucide="refresh-cw" class="w-4 h-4 group-hover:rotate-180 transition-transform duration-500"></i>
                                    <?php echo e($txn_bayar ? 'Tagihan Kedaluwarsa? Buat Tagihan Baru' : 'Buat Tagihan Pembayaran'); ?>
                                </button>
                            </form>

                            <p class="text-center text-[10px] text-slate-400 font-medium leading-relaxed px-2">
                                Tagihan baru berlaku <?php echo e(round(setting_int('payment_expiry', 1440, 15) / 60)); ?> jam. Data dokumen dan jumlah tagihan tidak berubah.
                            </p>
                        </div>
                    </div>

                <?php endif; ?>
            </div>

            <!-- Tracking Timeline -->
            <div class="glass p-8 rounded-[2.5rem] shadow-sm">
                <h4 class="text-lg font-bold outfit mb-8 flex items-center gap-2">
                    <i data-lucide="map-pin" class="w-5 h-5 text-blue-600"></i>
                    Status Pelacakan
                </h4>

                <?php
                // Determine step completion based on status
                $status   = $req->status;
                $paid     = $req->payment_status == 'settlement';
                $steps = [
                    'submitted'  => true,
                    'paid'       => $paid,
                    'processing' => in_array($status, ['processing','completed']),
                    'completed'  => $status == 'completed',
                    'rejected'   => $status == 'rejected',
                ];
                $status_labels = [
                    'pending'    => 'Menunggu Pembayaran',
                    'processing' => 'Sedang Diproses',
                    'completed'  => 'Selesai',
                    'rejected'   => 'Dibatalkan',
                ];
                ?>

                <?php if ($status == 'rejected'): ?>
                <!-- Rejected path -->
                <div class="relative space-y-10 pl-8">
                    <div class="absolute left-3 top-2 bottom-2 w-0.5 bg-red-100"></div>
                    <div class="relative">
                        <div class="absolute -left-[29px] w-6 h-6 rounded-full bg-blue-600 border-4 border-white shadow-sm"></div>
                        <div>
                            <h5 class="font-bold text-slate-800">Permintaan Diterima</h5>
                            <p class="text-xs text-slate-500"><?php echo date('d M Y, H:i', strtotime($req->created_at)); ?></p>
                            <p class="text-sm text-slate-600 mt-1">Dokumen telah didaftarkan ke sistem.</p>
                        </div>
                    </div>
                    <div class="relative">
                        <div class="absolute -left-[29px] w-6 h-6 rounded-full bg-red-500 border-4 border-white shadow-sm flex items-center justify-center">
                        </div>
                        <div>
                            <h5 class="font-bold text-red-600">Pengajuan Dibatalkan</h5>
                            <?php $alasan_lini = trim((string)($req->rejection_reason ?? '')); ?>
                            <?php if ($alasan_lini !== ''): ?>
                                <p class="text-sm text-red-500 mt-1"><?php echo nl2br(htmlspecialchars($alasan_lini)); ?></p>
                            <?php else: ?>
                                <p class="text-sm text-red-500 mt-1">Admin telah membatalkan pengajuan ini. Silakan buat pengajuan baru.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <!-- Normal progress path -->
                <div class="relative space-y-10 pl-8">
                    <div class="absolute left-3 top-2 bottom-2 w-0.5 bg-blue-100"></div>

                    <!-- Step 1: Submitted -->
                    <div class="relative">
                        <div class="absolute -left-[29px] w-6 h-6 rounded-full bg-blue-600 border-4 border-white shadow-sm"></div>
                        <div>
                            <h5 class="font-bold text-slate-800">Permintaan Diterima</h5>
                            <p class="text-xs text-slate-500"><?php echo date('d M Y, H:i', strtotime($req->created_at)); ?></p>
                            <p class="text-sm text-slate-600 mt-1">Dokumen telah didaftarkan ke sistem.</p>
                        </div>
                    </div>

                    <!-- Step 2: Paid -->
                    <div class="relative">
                        <div class="absolute -left-[29px] w-6 h-6 rounded-full <?php echo e($steps['paid'] ? 'bg-blue-600' : 'bg-slate-200'); ?> border-4 border-white shadow-sm"></div>
                        <div class="<?php echo e($steps['paid'] ? '' : 'opacity-40'); ?>">
                            <h5 class="font-bold text-slate-800">Pembayaran Terverifikasi</h5>
                            <p class="text-sm text-slate-600 mt-1">
                                <?php echo e($paid ? ('Lunas via ' . strtoupper($req->payment_method)) : 'Menunggu konfirmasi pembayaran.'); ?>
                            </p>
                        </div>
                    </div>

                    <!-- Step 3: Processing -->
                    <div class="relative">
                        <div class="absolute -left-[29px] w-6 h-6 rounded-full <?php echo e($steps['processing'] ? 'bg-blue-600' : 'bg-slate-200'); ?> border-4 border-white shadow-sm"></div>
                        <div class="<?php echo e($steps['processing'] ? '' : 'opacity-40'); ?>">
                            <h5 class="font-bold text-slate-800">Sedang Diproses</h5>
                            <p class="text-sm text-slate-600 mt-1">Dokumen sedang dilegalisir oleh bagian TU.</p>
                        </div>
                    </div>

                    <!-- Step 4: Completed -->
                    <div class="relative">
                        <div class="absolute -left-[29px] w-6 h-6 rounded-full <?php echo e($steps['completed'] ? 'bg-green-500' : 'bg-slate-200'); ?> border-4 border-white shadow-sm"></div>
                        <div class="<?php echo e($steps['completed'] ? '' : 'opacity-40'); ?>">
                            <h5 class="font-bold text-slate-800">Selesai & Dikirim</h5>
                            <p class="text-sm text-slate-600 mt-1">Dokumen telah dilegalisir dan siap dikirimkan.</p>
                            <?php if ($req->tracking_number): ?>
                                <span class="inline-block mt-2 text-xs font-mono font-bold text-green-600 bg-green-50 px-3 py-1 rounded-lg">
                                    Resi: <?php echo htmlspecialchars($req->tracking_number); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sidebar Info -->
        <div class="space-y-6">
            <div class="glass p-6 rounded-[2rem] shadow-sm">
                <h4 class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-4">Nomor Resi / Tracking</h4>
                <div class="bg-white/50 p-4 rounded-2xl border border-slate-100">
                    <?php if ($req->tracking_number): ?>
                        <span class="text-lg font-mono font-bold text-blue-600"><?php echo e($req->tracking_number); ?></span>
                    <?php else: ?>
                        <span class="text-sm text-slate-400 italic">Akan tersedia setelah dikirim</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($aksi_bayar['type'] === 'snap'): ?>
<!-- Midtrans Snap: hanya dimuat bila transaksi yang dibayar memang milik Midtrans -->
<script src="<?php echo e($aksi_bayar['script']); ?>" data-client-key="<?php echo e($aksi_bayar['client_key']); ?>"></script>
<?php endif; ?>
<script type="text/javascript">
    const payButton = document.getElementById('pay-button');
    if (payButton && window.snap) {
        const kembali = 'handlers/payment_return.php?ref=<?php echo e(rawurlencode($txn_bayar->merchant_ref ?? '')); ?>';
        const pulihkan = function () {
            payButton.disabled = false;
            payButton.innerHTML = '<i data-lucide="credit-card" class="w-5 h-5"></i> Bayar Sekarang';
            lucide.createIcons();
        };
        payButton.onclick = function() {
            payButton.disabled = true;
            payButton.innerHTML = '<svg class="animate-spin w-5 h-5 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path></svg> Membuka Pembayaran...';

            window.snap.pay(<?php echo js_json($aksi_bayar['token'] ?? ''); ?>, {
                // Status tidak dipercaya dari browser: halaman kembali
                // mengambil ulang status dari gateway sebelum menampilkannya.
                onSuccess: function() { window.location.href = kembali; },
                onPending: function() { window.location.href = kembali; },
                onError: pulihkan,
                onClose: pulihkan
            });
        };
    }

    // Loading state tombol regenerate
    const regenForm = document.getElementById('regen-form');
    const regenBtn  = document.getElementById('regen-btn');
    if (regenForm && regenBtn) {
        regenForm.onsubmit = function(e) {
            regenBtn.disabled = true;
            regenBtn.innerHTML = '<svg class="animate-spin w-4 h-4 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path></svg> Memproses...';
        };
    }
</script>
