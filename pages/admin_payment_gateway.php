<?php
/**
 * Panel gateway pembayaran — khusus super admin.
 *
 * Satu gateway aktif untuk tagihan BARU. Tagihan yang sudah terbit tetap
 * dibayar dan dikonfirmasi lewat gateway asalnya, jadi memindahkan sakelar
 * tidak pernah membatalkan tagihan siapa pun.
 *
 * Aksi ditangani handlers/admin_payment_gateway_handler.php; aturan "boleh
 * dipindah atau tidak" ada di includes/payment/panel.php supaya halaman dan
 * handler menilai syarat yang sama.
 */
if (($_SESSION['user_role'] ?? '') !== 'super_admin') {
    // Penjaga keras, bukan mode audit rbac_enforce: halaman ini memuat
    // petunjuk kredensial dan sakelar pembayaran.
    ?>
    <div class="max-w-2xl mx-auto px-4 py-16 text-center">
        <div class="glass p-12 rounded-[3rem] border border-white shadow-xl">
            <h2 class="text-3xl font-black outfit text-slate-800 mb-4 tracking-tight">Akses Ditolak</h2>
            <p class="text-slate-500 text-sm">Halaman ini hanya untuk Super Administrator.</p>
        </div>
    </div>
    <?php
    return;
}

require_once __DIR__ . '/../includes/payment/panel.php';

$pilihan = payment_preferred_gateway_code();   // yang dikehendaki fakultas
$aktif   = payment_active_gateway_code();      // yang benar-benar dipakai sekarang
$flash = $_SESSION['panel_bayar_flash'] ?? null;
unset($_SESSION['panel_bayar_flash']);

$pesan_uji = [
    'success' => ['bg-emerald-50 border-emerald-200 text-emerald-800', 'Transaksi uji LUNAS. Jalur buat → bayar → konfirmasi berfungsi.'],
    'pending' => ['bg-blue-50 border-blue-200 text-blue-800', 'Transaksi uji belum terkonfirmasi. Bila sudah dibayar di simulator, tunggu callback lalu periksa monitor.'],
    'failed'  => ['bg-amber-50 border-amber-200 text-amber-800', 'Transaksi uji gagal atau kedaluwarsa.'],
];

$gateway = [];
foreach (payment_gateway_codes() as $kode) {
    $g = payment_gateway($kode);
    $kolom = [];
    foreach (payment_panel_credential_fields($kode) as $kunci => [$label, $rahasia]) {
        $nilai = (string)setting($kunci, '');
        $kolom[$kunci] = ['label' => $label, 'rahasia' => $rahasia, 'terisi' => $nilai !== '',
                          'petunjuk' => $rahasia ? payment_secret_hint($nilai) : $nilai];
    }
    $gateway[$kode] = [
        'gw'        => $g,
        'label'     => $g->label(),
        'produksi'  => $g->isProduction(),
        'siap'      => $g->isConfigured(),
        'kolom'     => $kolom,
        'tes'       => payment_last_test($kode),
        'penghalang'=> payment_switch_blockers($kode),
        'callback'  => payment_callback_url($kode),
        'profil'    => payment_fee_profile($kode),
        'contoh'    => payment_panel_examples($kode),
        'kesiapan'  => payment_gateway_readiness($kode),
    ];
}

$umum = [
    'legalisir' => (int)setting('payment_custom_charge_legalisir', '0'),
    'donasi'    => (int)setting('payment_custom_charge_donasi', '0'),
    'expiry'    => setting_int('payment_expiry', 1440, 15),
    'wajib_lunas' => setting('legalisir_require_paid', '0') === '1',
    'cadangan'    => setting('payment_fallback_enabled', '1') === '1',
];
$diproses_belum_lunas = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs
    WHERE action = 'LEGALISIR_UNPAID_PROCESSED' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();

$transaksi = $pdo->query("SELECT * FROM payment_transactions ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_OBJ);
$uji = $pdo->query("SELECT * FROM payment_transactions WHERE purpose = 'uji' ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_OBJ);
$callback = $pdo->query("SELECT * FROM payment_callbacks ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_OBJ);
$ringkas = $pdo->query("SELECT
        SUM(status = 'pending') AS pending,
        SUM(status = 'pending' AND expires_at IS NOT NULL AND expires_at < NOW()) AS pending_lewat,
        SUM(flag = 'double_payment') AS ganda,
        SUM(flag = 'amount_mismatch') AS selisih,
        SUM(status = 'create_failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)) AS gagal_terbit
    FROM payment_transactions")->fetch(PDO::FETCH_OBJ);

$snap_uji = null;   // transaksi uji Midtrans yang menunggu dibayar lewat Snap
foreach ($uji as $t) {
    if ($t->status === 'pending' && $t->gateway === 'midtrans' && $t->snap_token) {
        $snap_uji = payment_gateway('midtrans')->frontendAction($t);
        break;
    }
}

$warna_status = [
    'paid'          => 'bg-emerald-100 text-emerald-700',
    'pending'       => 'bg-blue-100 text-blue-700',
    'create_failed' => 'bg-red-100 text-red-700',
    'failed'        => 'bg-red-100 text-red-700',
    'expired'       => 'bg-slate-100 text-slate-500',
    'cancelled'     => 'bg-slate-100 text-slate-500',
    'refunded'      => 'bg-amber-100 text-amber-700',
];
$warna_hasil = [
    'applied'            => 'bg-emerald-100 text-emerald-700',
    'no_change'          => 'bg-slate-100 text-slate-500',
    'ignored_regression' => 'bg-slate-100 text-slate-500',
    'amount_mismatch'    => 'bg-red-100 text-red-700',
    'not_found'          => 'bg-amber-100 text-amber-700',
    'auth_failed'        => 'bg-red-100 text-red-700',
    'invalid'            => 'bg-amber-100 text-amber-700',
    'error'              => 'bg-red-100 text-red-700',
];
$rp = function ($n) { return 'Rp ' . number_format((float)$n, 0, ',', '.'); };
?>

<div class="max-w-7xl mx-auto space-y-8">

    <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
        <div>
            <h1 class="text-2xl md:text-3xl font-black outfit text-slate-800">Gateway Pembayaran</h1>
            <p class="text-slate-500 text-sm mt-1">Pilihan utama dipakai bila sudah siap; bila belum, tagihan baru terbit lewat gateway lain yang siap. Tagihan yang sudah terbit tetap diproses gateway asalnya.</p>
        </div>
        <div class="flex items-center gap-3 px-5 py-3 rounded-2xl bg-slate-900 text-white">
            <i data-lucide="zap" class="w-5 h-5"></i>
            <span class="text-sm font-bold">Dipakai sekarang: <?php echo e(payment_gateway_label($aktif)); ?>
                · <?php echo e($gateway[$aktif]['produksi'] ? 'PRODUKSI' : 'sandbox'); ?></span>
        </div>
    </div>

    <?php if ($pilihan !== $aktif): ?>
        <div class="p-4 rounded-2xl border bg-amber-50 border-amber-200 text-amber-800 flex items-start gap-3">
            <i data-lucide="info" class="w-5 h-5 shrink-0 mt-0.5"></i>
            <span class="text-sm font-medium">
                Pilihan utama fakultas adalah <b><?php echo e(payment_gateway_label($pilihan)); ?></b>, tetapi tagihan
                baru sementara terbit lewat <b><?php echo e(payment_gateway_label($aktif)); ?></b> karena
                <?php echo e(implode(' dan ', $gateway[$pilihan]['kesiapan'])); ?>.
                Begitu keduanya dibereskan, tagihan baru berpindah sendiri — tidak ada tombol yang perlu ditekan.
            </span>
        </div>
    <?php endif; ?>

    <?php if ($flash): ?>
        <div class="p-4 rounded-2xl border flex items-start gap-3 <?php echo e($flash['ok'] ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-700'); ?>">
            <i data-lucide="<?php echo e($flash['ok'] ? 'check-circle' : 'alert-circle'); ?>" class="w-5 h-5 shrink-0 mt-0.5"></i>
            <span class="text-sm font-medium"><?php echo e($flash['pesan']); ?></span>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['uji'], $pesan_uji[$_GET['uji']])): [$kelas, $teks] = $pesan_uji[$_GET['uji']]; ?>
        <div class="p-4 rounded-2xl border text-sm font-medium <?php echo e($kelas); ?>"><?php echo e($teks); ?></div>
    <?php endif; ?>

    <?php if ($ringkas->ganda || $ringkas->selisih): ?>
        <div class="p-4 rounded-2xl border bg-red-50 border-red-200 text-red-700 flex items-start gap-3">
            <i data-lucide="alert-triangle" class="w-5 h-5 shrink-0 mt-0.5"></i>
            <span class="text-sm font-medium">
                <?php if ($ringkas->ganda): ?><?php echo e((int)$ringkas->ganda); ?> transaksi terbayar GANDA — perlu refund manual. <?php endif; ?>
                <?php if ($ringkas->selisih): ?><?php echo e((int)$ringkas->selisih); ?> transaksi dengan nominal tidak cocok — tidak ditandai lunas. <?php endif; ?>
                Lihat monitor di bawah.
            </span>
        </div>
    <?php endif; ?>

    <!-- Kartu per gateway -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <?php foreach ($gateway as $kode => $g): $tes = $g['tes']; ?>
        <div id="<?php echo e($kode); ?>" class="glass p-6 md:p-8 rounded-[2.5rem] shadow-sm border <?php echo e($kode === $aktif ? 'border-blue-500' : 'border-white'); ?> space-y-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-black outfit text-slate-800"><?php echo e($g['label']); ?></h2>
                    <div class="flex flex-wrap gap-2 mt-2">
                        <?php if ($kode === $aktif): ?>
                            <span class="px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-wider bg-blue-600 text-white">Dipakai sekarang</span>
                        <?php else: ?>
                            <span class="px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-wider bg-slate-100 text-slate-500">Cadangan</span>
                        <?php endif; ?>
                        <?php if ($kode === $pilihan): ?>
                            <span class="px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-wider bg-slate-900 text-white">Pilihan utama</span>
                        <?php endif; ?>
                        <?php if ($g['kesiapan']): ?>
                            <span class="px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-wider bg-amber-100 text-amber-700">Belum siap</span>
                        <?php endif; ?>
                        <span class="px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-wider <?php echo e($g['produksi'] ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'); ?>">
                            <?php echo e($g['produksi'] ? 'Produksi' : 'Sandbox'); ?>
                        </span>
                        <span class="px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-wider <?php echo e($g['siap'] ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'); ?>">
                            <?php echo e($g['siap'] ? 'Kredensial lengkap' : 'Kredensial belum lengkap'); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Tes koneksi -->
            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="text-xs">
                    <p class="font-bold text-slate-700">Tes koneksi terakhir</p>
                    <?php if ($tes): ?>
                        <p class="<?php echo e($tes['ok'] ? 'text-emerald-600' : 'text-red-600'); ?> font-medium mt-1">
                            <?php echo e(($tes['ok'] ? 'Berhasil' : 'Gagal') . ' · ' . date('d M Y H:i', strtotime($tes['at'])) . ' · ' . ($tes['production'] ? 'produksi' : 'sandbox')); ?>
                        </p>
                        <p class="text-slate-400 mt-0.5"><?php echo e($tes['message']); ?></p>
                    <?php else: ?>
                        <p class="text-slate-400 mt-1">Belum pernah, atau kredensial diubah sejak tes terakhir.</p>
                    <?php endif; ?>
                </div>
                <form method="POST" action="handlers/admin_payment_gateway_handler.php">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="aksi" value="tes">
                    <input type="hidden" name="gateway" value="<?php echo e($kode); ?>">
                    <button type="submit" class="px-4 py-2.5 rounded-xl bg-white border border-slate-200 text-xs font-bold text-slate-700 hover:border-blue-400 hover:text-blue-600 transition-all flex items-center gap-2 shrink-0">
                        <i data-lucide="plug-zap" class="w-4 h-4"></i> Tes koneksi
                    </button>
                </form>
            </div>

            <!-- Sakelar -->
            <?php if ($kode !== $pilihan): ?>
                <?php if ($g['penghalang']): ?>
                    <div class="p-4 rounded-2xl bg-amber-50 border border-amber-200 text-xs text-amber-800">
                        <p class="font-bold mb-2">Belum dapat dijadikan pilihan utama:</p>
                        <ul class="list-disc pl-5 space-y-1">
                            <?php foreach ($g['penghalang'] as $alasan): ?>
                                <li><?php echo e($alasan); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php else: ?>
                    <form method="POST" action="handlers/admin_payment_gateway_handler.php" class="form-aktifkan"
                          data-label="<?php echo e($g['label']); ?>" data-mode="<?php echo e($g['produksi'] ? 'PRODUKSI' : 'sandbox'); ?>"
                          data-dari="<?php echo e(payment_gateway_label($aktif)); ?>">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="aksi" value="aktifkan">
                        <input type="hidden" name="gateway" value="<?php echo e($kode); ?>">
                        <button type="submit" class="w-full py-3.5 rounded-2xl bg-blue-600 text-white text-sm font-bold hover:bg-blue-700 transition-all flex items-center justify-center gap-2">
                            <i data-lucide="repeat" class="w-4 h-4"></i> Jadikan pilihan utama
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <!-- URL callback -->
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-2" for="cb_<?php echo e($kode); ?>">URL callback — daftarkan di dashboard <?php echo e($g['label']); ?></label>
                <div class="flex gap-2">
                    <input id="cb_<?php echo e($kode); ?>" type="text" readonly value="<?php echo e($g['callback']); ?>"
                           class="flex-1 px-4 py-2.5 rounded-xl bg-white border border-slate-200 text-xs font-mono text-slate-600 outline-none">
                    <button type="button" data-salin="cb_<?php echo e($kode); ?>" class="tombol-salin px-4 py-2.5 rounded-xl bg-slate-900 text-white text-xs font-bold hover:bg-black transition-all shrink-0">Salin</button>
                </div>
                <p class="text-[10px] text-slate-400 mt-1.5">
                    <?php echo e($kode === 'midtrans'
                        ? 'Midtrans: Settings → Payment → Notification URL. Kunci sandbox dan produksi punya pengaturan sendiri.'
                        : 'Flip for Business: Pengaturan → Callback Accept Payment. Validation token diambil dari halaman yang sama.'); ?>
                </p>
            </div>

            <!-- Kredensial -->
            <details class="rounded-2xl border border-slate-100 bg-white/60">
                <summary class="px-5 py-4 cursor-pointer text-sm font-bold text-slate-700">Mode & kredensial</summary>
                <form method="POST" action="handlers/admin_payment_gateway_handler.php" autocomplete="off" class="px-5 pb-5 space-y-4">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="aksi" value="kredensial">
                    <input type="hidden" name="gateway" value="<?php echo e($kode); ?>">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-2" for="mode_<?php echo e($kode); ?>">Mode</label>
                        <select id="mode_<?php echo e($kode); ?>" name="mode" class="w-full px-4 py-3 rounded-xl bg-white border border-slate-200 outline-none text-sm">
                            <option value="0" <?php echo e($g['produksi'] ? '' : 'selected'); ?>>Sandbox (uji, uang tidak sungguhan)</option>
                            <option value="1" <?php echo e($g['produksi'] ? 'selected' : ''); ?>>Produksi (uang sungguhan)</option>
                        </select>
                    </div>
                    <?php foreach ($g['kolom'] as $kunci => $k): ?>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-2" for="f_<?php echo e($kunci); ?>"><?php echo e($k['label']); ?></label>
                        <?php if ($k['rahasia']): ?>
                            <input id="f_<?php echo e($kunci); ?>" type="password" name="<?php echo e($kunci); ?>" value="" autocomplete="new-password"
                                   placeholder="<?php echo e($k['terisi'] ? $k['petunjuk'] . ' — kosongkan bila tidak diubah' : 'Belum diisi'); ?>"
                                   class="w-full px-4 py-3 rounded-xl bg-white border border-slate-200 outline-none text-xs font-mono">
                            <?php if ($k['terisi']): ?>
                            <label class="flex items-center gap-2 text-[10px] text-slate-500 mt-1.5">
                                <input type="checkbox" name="hapus_<?php echo e($kunci); ?>" value="1" class="accent-blue-600"> Kosongkan <?php echo e($k['label']); ?>
                            </label>
                            <?php endif; ?>
                        <?php else: ?>
                            <input id="f_<?php echo e($kunci); ?>" type="text" name="<?php echo e($kunci); ?>" value="<?php echo e($k['petunjuk']); ?>"
                                   class="w-full px-4 py-3 rounded-xl bg-white border border-slate-200 outline-none text-xs font-mono">
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <p class="text-[10px] text-slate-400">Rahasia tidak pernah ditampilkan kembali. Mengubah mode atau kredensial membatalkan tes koneksi terakhir.</p>
                    <button type="submit" class="w-full py-3 rounded-xl bg-slate-900 text-white text-xs font-bold hover:bg-black transition-all">Simpan kredensial <?php echo e($g['label']); ?></button>
                </form>
            </details>

            <!-- Profil biaya -->
            <details id="biaya-<?php echo e($kode); ?>" class="rounded-2xl border border-slate-100 bg-white/60">
                <summary class="px-5 py-4 cursor-pointer text-sm font-bold text-slate-700">Profil biaya
                    <?php if (!$g['profil']['reviewed']): ?><span class="ml-2 px-2 py-0.5 rounded-lg text-[10px] bg-amber-100 text-amber-700">belum ditinjau</span><?php endif; ?>
                </summary>
                <div class="px-5 pb-5 space-y-4">
                    <form method="POST" action="handlers/admin_payment_gateway_handler.php" class="space-y-4">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="aksi" value="biaya">
                        <input type="hidden" name="gateway" value="<?php echo e($kode); ?>">
                        <div class="grid grid-cols-2 lg:grid-cols-3 gap-3">
                            <?php foreach (['percent' => ['Persen gateway (%)', '0.01'], 'vat_percent' => ['PPN atas biaya (%)', '0.01'],
                                            'flat' => ['Biaya tetap (Rp)', '1'], 'app' => ['Biaya aplikasi (Rp)', '1'], 'min' => ['Biaya minimum (Rp)', '1']] as $k => [$label, $step]): ?>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 mb-1.5" for="fee_<?php echo e($kode . '_' . $k); ?>"><?php echo e($label); ?></label>
                                <input id="fee_<?php echo e($kode . '_' . $k); ?>" type="number" min="0" step="<?php echo e($step); ?>" name="<?php echo e($k); ?>" required
                                       value="<?php echo e($g['profil'][$k]); ?>"
                                       class="w-full px-3 py-2.5 rounded-xl bg-white border border-slate-200 outline-none text-sm font-bold">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <label class="flex items-start gap-2 text-xs text-slate-600">
                            <input type="checkbox" name="reviewed" value="1" <?php echo e($g['profil']['reviewed'] ? 'checked' : ''); ?> class="accent-blue-600 mt-0.5">
                            Tarif ini sudah dicocokkan dengan tarif resmi <?php echo e($g['label']); ?> yang berlaku untuk akun fakultas.
                        </label>
                        <p class="text-[10px] text-slate-400 leading-relaxed">
                            Rumus: biaya = (pokok × m + tetap + aplikasi) ÷ (1 − m), dengan m = persen × (1 + PPN), dibulatkan ke atas
                            dan tidak kurang dari minimum. Alumni membayar pokok + biaya + biaya tambahan layanan. Tagihan yang sudah terbit tidak berubah.
                        </p>
                        <button type="submit" class="w-full py-3 rounded-xl bg-slate-900 text-white text-xs font-bold hover:bg-black transition-all">Simpan profil biaya <?php echo e($g['label']); ?></button>
                    </form>
                    <div class="rounded-xl border border-slate-100 overflow-hidden">
                        <p class="px-4 py-2 bg-slate-50 text-[10px] font-black text-slate-400 uppercase tracking-widest">Contoh dengan profil tersimpan</p>
                        <?php foreach ($g['contoh'] as $label => $q): ?>
                            <div class="px-4 py-2 flex justify-between gap-3 text-xs border-t border-slate-100">
                                <span class="text-slate-500"><?php echo e($label); ?></span>
                                <?php if ($q['ok']): ?>
                                    <span class="font-bold text-slate-800 text-right"><?php echo e($rp($q['total'])); ?>
                                        <span class="block text-[10px] font-medium text-slate-400">biaya <?php echo e($rp($q['admin_total'])); ?></span></span>
                                <?php else: ?>
                                    <span class="font-bold text-red-600"><?php echo e($q['error']); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </details>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Pengaturan umum -->
    <div id="umum" class="glass p-6 md:p-8 rounded-[2.5rem] shadow-sm border border-white">
        <h2 class="text-lg font-black outfit text-slate-800 mb-1">Pengaturan umum</h2>
        <p class="text-xs text-slate-400 mb-6">Berlaku untuk semua gateway. Harga per dokumen dan zona ongkir diatur di Konfigurasi Sistem.</p>
        <form method="POST" action="handlers/admin_payment_gateway_handler.php" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
            <?php csrf_field(); ?>
            <input type="hidden" name="aksi" value="umum">
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-2" for="u_leg">Biaya tambahan legalisir (Rp)</label>
                <input id="u_leg" type="number" min="0" step="1" name="payment_custom_charge_legalisir" value="<?php echo e($umum['legalisir']); ?>" required class="w-full px-4 py-3 rounded-xl bg-white border border-slate-200 outline-none text-sm font-bold">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-2" for="u_don">Biaya tambahan donasi (Rp)</label>
                <input id="u_don" type="number" min="0" step="1" name="payment_custom_charge_donasi" value="<?php echo e($umum['donasi']); ?>" required class="w-full px-4 py-3 rounded-xl bg-white border border-slate-200 outline-none text-sm font-bold">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-2" for="u_exp">Masa berlaku tagihan (menit)</label>
                <input id="u_exp" type="number" min="15" max="10080" step="1" name="payment_expiry" value="<?php echo e($umum['expiry']); ?>" required class="w-full px-4 py-3 rounded-xl bg-white border border-slate-200 outline-none text-sm font-bold">
            </div>
            <button type="submit" class="py-3 rounded-xl bg-slate-900 text-white text-xs font-bold hover:bg-black transition-all">Simpan</button>
            <label class="md:col-span-4 flex items-start gap-3 p-4 rounded-2xl bg-slate-50 border border-slate-100 text-xs text-slate-600">
                <input type="checkbox" name="payment_fallback_enabled" value="1" <?php echo e($umum['cadangan'] ? 'checked' : ''); ?> class="accent-blue-600 mt-0.5">
                <span>
                    <span class="font-bold text-slate-800 block mb-1">Pakai gateway cadangan bila yang utama gagal</span>
                    Bila penyedia yang sedang dipakai menolak menerbitkan tagihan (API mati, kunci ditolak), tagihan
                    langsung dicoba sekali lagi lewat penyedia lain yang siap, sehingga alumni tidak melihat kegagalan
                    apa pun. Perpindahan dicatat di Audit Trail dan diberitahukan ke super admin. Pembayaran tunai
                    tetap menjadi jalan terakhir lewat verifikasi manual.
                </span>
            </label>
            <label class="md:col-span-4 flex items-start gap-3 p-4 rounded-2xl bg-slate-50 border border-slate-100 text-xs text-slate-600">
                <input type="checkbox" name="legalisir_require_paid" value="1" <?php echo e($umum['wajib_lunas'] ? 'checked' : ''); ?> class="accent-blue-600 mt-0.5">
                <span>
                    <span class="font-bold text-slate-800 block mb-1">Legalisir wajib lunas sebelum diproses</span>
                    Bila dicentang, pengajuan yang belum lunas tidak dapat diubah ke "Diproses" atau "Selesai" — pembayaran tunai
                    harus diverifikasi lebih dulu. Bila tidak dicentang, perubahan tetap diizinkan tetapi admin diberi peringatan
                    dan kejadiannya dicatat di Audit Trail
                    (<?php echo e($diproses_belum_lunas); ?> kali dalam 30 hari terakhir — periksa angka ini sebelum mencentang).
                </span>
            </label>
        </form>
    </div>

    <!-- Transaksi uji -->
    <div id="uji" class="glass p-6 md:p-8 rounded-[2.5rem] shadow-sm border border-white">
        <h2 class="text-lg font-black outfit text-slate-800 mb-1">Transaksi uji</h2>
        <p class="text-xs text-slate-400 mb-6">Rp 10.000 ditambah biaya, KHUSUS mode sandbox. Tidak menyentuh legalisir maupun donasi. Bayar di simulator gateway, lalu pastikan monitor menunjukkan callback <span class="font-bold">applied</span> dan transaksi <span class="font-bold">paid</span>.</p>
        <div class="flex flex-wrap gap-3 mb-6">
            <?php foreach ($gateway as $kode => $g): ?>
            <form method="POST" action="handlers/admin_payment_gateway_handler.php">
                <?php csrf_field(); ?>
                <input type="hidden" name="aksi" value="uji">
                <input type="hidden" name="gateway" value="<?php echo e($kode); ?>">
                <button type="submit" <?php echo e($g['produksi'] || !$g['siap'] ? 'disabled' : ''); ?>
                        class="px-4 py-2.5 rounded-xl bg-white border border-slate-200 text-xs font-bold text-slate-700 hover:border-blue-400 hover:text-blue-600 transition-all disabled:opacity-50 disabled:cursor-not-allowed">
                    Terbitkan uji <?php echo e($g['label']); ?><?php echo e($g['produksi'] ? ' (mode produksi)' : (!$g['siap'] ? ' (kredensial belum lengkap)' : '')); ?>
                </button>
            </form>
            <?php endforeach; ?>
        </div>
        <?php if ($uji): ?>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs min-w-[640px]">
                <thead><tr class="text-slate-400 uppercase tracking-wider text-[10px]">
                    <th class="py-2 pr-4">Referensi</th><th class="py-2 pr-4">Gateway</th><th class="py-2 pr-4">Nominal</th><th class="py-2 pr-4">Status</th><th class="py-2">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                <?php foreach ($uji as $t): $aksi_t = $t->status === 'pending' && payment_gateway($t->gateway) ? payment_gateway($t->gateway)->frontendAction($t) : ['type' => 'none']; ?>
                    <tr>
                        <td class="py-2 pr-4 font-mono"><?php echo e($t->merchant_ref); ?></td>
                        <td class="py-2 pr-4"><?php echo e(payment_gateway_label($t->gateway)); ?></td>
                        <td class="py-2 pr-4"><?php echo e($rp($t->amount_expected)); ?></td>
                        <td class="py-2 pr-4"><span class="px-2 py-0.5 rounded-lg font-bold <?php echo e($warna_status[$t->status] ?? 'bg-slate-100 text-slate-500'); ?>"><?php echo e($t->status); ?></span></td>
                        <td class="py-2">
                            <?php if ($aksi_t['type'] === 'snap'): ?>
                                <button type="button" class="bayar-snap-uji px-3 py-1.5 rounded-lg bg-orange-500 text-white font-bold"
                                        data-token="<?php echo e($aksi_t['token']); ?>" data-ref="<?php echo e(rawurlencode($t->merchant_ref)); ?>">Bayar</button>
                            <?php elseif ($aksi_t['type'] === 'redirect'): ?>
                                <a href="<?php echo e($aksi_t['url']); ?>" rel="noopener" class="px-3 py-1.5 rounded-lg bg-orange-500 text-white font-bold">Bayar</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Monitor -->
    <div id="monitor" class="glass p-6 md:p-8 rounded-[2.5rem] shadow-sm border border-white space-y-6">
        <div>
            <h2 class="text-lg font-black outfit text-slate-800 mb-1">Monitor</h2>
            <p class="text-xs text-slate-400">
                <?php echo e((int)$ringkas->pending); ?> tagihan menunggu pembayaran
                (<?php echo e((int)$ringkas->pending_lewat); ?> sudah lewat masa berlaku),
                <?php echo e((int)$ringkas->gagal_terbit); ?> gagal terbit dalam 24 jam terakhir.
                "Cek status" mengambil status langsung dari gateway — dipakai bila callback terlambat atau hilang.
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs min-w-[900px]">
                <thead><tr class="text-slate-400 uppercase tracking-wider text-[10px]">
                    <th class="py-2 pr-3">Dibuat</th><th class="py-2 pr-3">Referensi</th><th class="py-2 pr-3">Layanan</th><th class="py-2 pr-3">Gateway</th>
                    <th class="py-2 pr-3">Nominal</th><th class="py-2 pr-3">Status</th><th class="py-2 pr-3">Terakhir dicek</th><th class="py-2">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                <?php foreach ($transaksi as $t): ?>
                    <tr class="<?php echo e($t->flag ? 'bg-red-50' : ''); ?>">
                        <td class="py-2 pr-3 text-slate-500 whitespace-nowrap"><?php echo e(date('d M H:i', strtotime($t->created_at))); ?></td>
                        <td class="py-2 pr-3 font-mono"><?php echo e($t->merchant_ref); ?>
                            <?php if ($t->flag): ?><span class="block text-[10px] font-bold text-red-600"><?php echo e($t->flag === 'double_payment' ? 'BAYAR GANDA — refund manual' : $t->flag); ?></span><?php endif; ?>
                            <?php if ($t->last_error): ?><span class="block text-[10px] text-red-500"><?php echo e($t->last_error); ?></span><?php endif; ?>
                        </td>
                        <td class="py-2 pr-3"><?php echo e($t->purpose); ?></td>
                        <td class="py-2 pr-3"><?php echo e(payment_gateway_label($t->gateway)); ?><?php echo e($t->channel ? ' · ' . payment_channel_label($t->channel) : ''); ?></td>
                        <td class="py-2 pr-3 whitespace-nowrap"><?php echo e($t->amount_expected !== null ? $rp($t->amount_expected) : '-'); ?></td>
                        <td class="py-2 pr-3"><span class="px-2 py-0.5 rounded-lg font-bold <?php echo e($warna_status[$t->status] ?? 'bg-slate-100 text-slate-500'); ?>"><?php echo e($t->status); ?></span></td>
                        <td class="py-2 pr-3 text-slate-500 whitespace-nowrap"><?php echo e($t->last_checked_at ? date('d M H:i', strtotime($t->last_checked_at)) : '-'); ?></td>
                        <td class="py-2">
                            <?php if ($t->gateway !== 'cash' && $t->provider_ref): ?>
                            <form method="POST" action="handlers/admin_payment_gateway_handler.php">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="aksi" value="cek">
                                <input type="hidden" name="txn_id" value="<?php echo e($t->id); ?>">
                                <button type="submit" class="px-3 py-1.5 rounded-lg bg-white border border-slate-200 font-bold text-slate-600 hover:border-blue-400 hover:text-blue-600 whitespace-nowrap">Cek status</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$transaksi): ?>
                    <tr><td colspan="8" class="py-6 text-center text-slate-400">Belum ada transaksi.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div>
            <h3 class="text-sm font-black text-slate-700 mb-3">Callback masuk (50 terakhir)</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs min-w-[760px]">
                    <thead><tr class="text-slate-400 uppercase tracking-wider text-[10px]">
                        <th class="py-2 pr-3">Diterima</th><th class="py-2 pr-3">Gateway</th><th class="py-2 pr-3">Referensi</th>
                        <th class="py-2 pr-3">Status dari gateway</th><th class="py-2 pr-3">Hasil</th><th class="py-2">Keterangan</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($callback as $c): ?>
                        <tr>
                            <td class="py-2 pr-3 text-slate-500 whitespace-nowrap"><?php echo e(date('d M H:i:s', strtotime($c->received_at))); ?></td>
                            <td class="py-2 pr-3"><?php echo e(payment_gateway_label($c->gateway)); ?></td>
                            <td class="py-2 pr-3 font-mono"><?php echo e($c->merchant_ref ?: ($c->provider_ref ?: '-')); ?></td>
                            <td class="py-2 pr-3"><?php echo e($c->provider_status ?: '-'); ?></td>
                            <td class="py-2 pr-3"><span class="px-2 py-0.5 rounded-lg font-bold <?php echo e($warna_hasil[$c->outcome] ?? 'bg-slate-100 text-slate-500'); ?>"><?php echo e($c->outcome); ?></span></td>
                            <td class="py-2 text-slate-500"><?php echo e($c->detail ?: ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$callback): ?>
                        <tr><td colspan="6" class="py-6 text-center text-slate-400">Belum ada callback yang diterima. Bila tagihan sudah dibayar tetapi daftar ini tetap kosong, periksa URL callback di dashboard gateway.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if ($snap_uji): ?>
<script src="<?php echo e($snap_uji['script']); ?>" data-client-key="<?php echo e($snap_uji['client_key']); ?>"></script>
<?php endif; ?>
<script>
document.querySelectorAll('.tombol-salin').forEach(function (b) {
    b.addEventListener('click', async function () {
        const el = document.getElementById(b.dataset.salin);
        el.select();
        el.setSelectionRange(0, el.value.length);   // iOS
        let berhasil = false;
        // navigator.clipboard MENOLAK di banyak keadaan wajar: halaman bukan
        // HTTPS, tab sedang tidak fokus, atau izin ditolak. Penolakannya
        // berupa Promise reject; tanpa ditangkap, tombol diam saja dan galat
        // hanya muncul di konsol.
        try {
            if (navigator.clipboard) {
                await navigator.clipboard.writeText(el.value);
                berhasil = true;
            }
        } catch (e) {
            berhasil = false;
        }
        if (!berhasil) {
            try { berhasil = document.execCommand('copy'); } catch (e) { berhasil = false; }
        }
        b.textContent = berhasil ? 'Tersalin' : 'Salin manual';
        if (!berhasil) {
            el.focus();
        }
        setTimeout(function () { b.textContent = 'Salin'; }, 2000);
    });
});

document.querySelectorAll('.form-aktifkan').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
        if (f.dataset.konfirmasi === '1') return;
        ev.preventDefault();
        Swal.fire({
            title: 'Pindahkan ke ' + f.dataset.label + '?',
            html: 'Mulai sekarang SETIAP tagihan baru — legalisir dan donasi — terbit lewat <b>' + f.dataset.label +
                  '</b> (mode ' + f.dataset.mode + ').<br><br>Tagihan yang sudah terbit tetap dibayar dan dikonfirmasi lewat ' +
                  f.dataset.dari + '. Anda dapat memindahkannya kembali kapan saja.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Ya, pindahkan',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#2563eb'
        }).then(function (r) {
            if (r.isConfirmed) { f.dataset.konfirmasi = '1'; f.submit(); }
        });
    });
});

document.querySelectorAll('.bayar-snap-uji').forEach(function (b) {
    b.addEventListener('click', function () {
        if (!window.snap) { Swal.fire('Snap belum termuat', 'Muat ulang halaman lalu coba lagi.', 'error'); return; }
        const kembali = 'handlers/payment_return.php?ref=' + b.dataset.ref;
        window.snap.pay(b.dataset.token, {
            onSuccess: function () { window.location.href = kembali; },
            onPending: function () { window.location.href = kembali; }
        });
    });
});
</script>
