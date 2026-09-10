<?php
$campaign_id = $_GET['id'] ?? '';
$stmt = $pdo->prepare("
    SELECT c.*, COALESCE(SUM(d.amount), 0) as current_amount 
    FROM donation_campaigns c 
    LEFT JOIN donations d ON c.id = d.campaign_id AND d.status = 'success'
    WHERE c.id = ?
    GROUP BY c.id
");
$stmt->execute([$campaign_id]);
$camp = $stmt->fetch();

if (!$camp) {
    die("Program Donasi tidak ditemukan.");
}

// Fetch Midtrans Client Key from settings
$stmt_settings = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'midtrans_client_key'");
$stmt_settings->execute();
$client_key = $stmt_settings->fetchColumn();

// Fetch Midtrans Environment
$stmt_env = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'midtrans_is_production'");
$stmt_env->execute();
$is_production = $stmt_env->fetchColumn() == '1';

$snap_url = $is_production 
    ? "https://app.midtrans.com/snap/snap.js" 
    : "https://app.sandbox.midtrans.com/snap/snap.js";
?>

<div class="max-w-4xl mx-auto px-4 md:px-0 pb-24">
    <!-- Back Button -->
    <div class="mb-10 mt-4 md:mt-0">
        <a href="index.php?page=donasi" class="inline-flex items-center gap-3 text-slate-500 hover:text-blue-600 transition-all font-bold group">
            <div class="w-12 h-12 rounded-full bg-white shadow-sm border border-slate-100 flex items-center justify-center group-hover:bg-blue-50 transition-all">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </div>
            <span class="text-sm">Kembali</span>
        </a>
    </div>

    <!-- Main Content Grid -->
    <div class="flex flex-col gap-10">
        
        <!-- Section 1: Campaign Info Card -->
        <div class="glass overflow-hidden rounded-[3.5rem] shadow-2xl shadow-blue-900/5 border border-white">
            <div class="relative h-64 md:h-96 overflow-hidden">
                <?php if ($camp->image): ?>
                    <img src="<?php echo e($camp->image); ?>" class="w-full h-full object-cover" alt="Program Donasi">
                <?php else: ?>
                    <div class="w-full h-full bg-slate-900 flex items-center justify-center">
                        <i data-lucide="heart" class="w-20 h-20 text-blue-500/20 fill-blue-500/10"></i>
                    </div>
                <?php endif; ?>
                <div class="absolute inset-0 bg-gradient-to-t from-slate-900/80 via-transparent to-transparent"></div>
                <div class="absolute bottom-10 left-10 right-10">
                    <h2 class="text-3xl md:text-4xl font-black outfit text-white leading-tight"><?php echo e($camp->title); ?></h2>
                </div>
            </div>
            
            <div class="p-10">
                <p class="text-slate-500 text-base leading-relaxed mb-10"><?php echo e($camp->description); ?></p>
                
                <div class="bg-blue-50/50 p-8 rounded-[2.5rem] border border-blue-100/50">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 mb-6">
                        <div>
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-2">Dana Terkumpul</p>
                            <p class="text-3xl font-black text-blue-600 outfit">Rp <?php echo number_format($camp->current_amount, 0, ',', '.'); ?></p>
                        </div>
                        <div class="md:text-right">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-2">Target Program</p>
                            <p class="text-xl font-bold text-slate-600 outfit opacity-60">Rp <?php echo number_format($camp->target_amount, 0, ',', '.'); ?></p>
                        </div>
                    </div>
                    <div class="w-full h-4 bg-white rounded-full overflow-hidden border border-blue-100 p-1 shadow-inner">
                        <div class="h-full bg-gradient-to-r from-blue-600 to-indigo-500 rounded-full transition-all duration-1000" style="width: <?php echo min(100, ($camp->target_amount > 0 ? ($camp->current_amount/$camp->target_amount)*100 : 0)); ?>%"></div>
                    </div>
                    <p class="text-right text-[11px] font-black text-blue-600 mt-4 outfit tracking-widest uppercase"><?php echo round(($camp->target_amount > 0 ? ($camp->current_amount/$camp->target_amount)*100 : 0), 1); ?>% Tercapai</p>
                </div>
            </div>
        </div>

        <!-- Section 2: Trust Badges -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="glass p-8 rounded-[2.5rem] border border-white shadow-sm flex items-center gap-6 group hover:bg-white transition-all">
                <div class="w-16 h-16 bg-emerald-50 rounded-3xl flex items-center justify-center shrink-0 shadow-inner">
                    <i data-lucide="shield-check" class="w-8 h-8 text-emerald-600"></i>
                </div>
                <div>
                    <h2 class="font-black text-slate-800 outfit text-sm uppercase tracking-wider mb-1">Pembayaran Aman</h2>
                    <p class="text-xs text-slate-400 leading-tight">Terenskripsi End-to-End via Midtrans</p>
                </div>
            </div>
            <div class="glass p-8 rounded-[2.5rem] border border-white shadow-sm flex items-center gap-6 group hover:bg-white transition-all">
                <div class="w-16 h-16 bg-blue-50 rounded-3xl flex items-center justify-center shrink-0 shadow-inner">
                    <i data-lucide="award" class="w-8 h-8 text-blue-600"></i>
                </div>
                <div>
                    <h2 class="font-black text-slate-800 outfit text-sm uppercase tracking-wider mb-1">Terverifikasi</h2>
                    <p class="text-xs text-slate-400 leading-tight">Fakultas Kedokteran UMS Surakarta</p>
                </div>
            </div>
        </div>

        <!-- Section 3: Donation Form -->
        <div class="glass p-10 md:p-16 rounded-[4rem] shadow-2xl shadow-blue-900/10 border-4 border-white relative overflow-hidden">
            <div class="relative z-10">
                <div class="flex items-center gap-4 mb-12">
                    <div class="w-14 h-14 bg-slate-900 text-white rounded-2xl flex items-center justify-center shadow-xl">
                        <i data-lucide="heart-handshake" class="w-7 h-7"></i>
                    </div>
                    <div>
                        <h2 class="text-3xl font-black outfit text-slate-800 tracking-tight">Kirim Kontribusi</h2>
                        <p class="text-slate-400 text-sm">Pilih nominal atau masukkan jumlah kustom.</p>
                    </div>
                </div>
                
                <form id="donationForm" class="space-y-10">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="campaign_id" value="<?php echo e($camp->id); ?>">
                    
                    <div class="space-y-6">
                        <div class="grid grid-cols-2 gap-4">
                            <?php $presets = [50000, 100000, 500000, 1000000]; ?>
                            <?php foreach($presets as $p): ?>
                                <button type="button" onclick="setAmount(<?php echo e($p); ?>, this)" class="preset-btn py-5 px-6 rounded-2xl border-2 border-slate-50 bg-slate-50/50 hover:border-blue-500 hover:bg-blue-50 transition-all font-black text-slate-600 text-sm shadow-sm">
                                    Rp <?php echo number_format($p, 0, ',', '.'); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="relative group mt-8">
                            <div class="absolute left-8 top-1/2 -translate-y-1/2 font-black text-blue-600 outfit text-2xl">Rp</div>
                            <input aria-label="Nominal Lainnya" type="number" id="amount" name="amount" placeholder="Nominal Lainnya" class="w-full pl-20 pr-10 py-8 rounded-[2.5rem] bg-white border-4 border-slate-50 focus:border-blue-500 outline-none font-black text-slate-800 text-2xl transition-all shadow-inner" required>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="relative group">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3 ml-2" for="f_donor_name">Nama Donatur</label>
                            <div class="relative">
                                <div class="absolute left-6 top-1/2 -translate-y-1/2 text-slate-300 group-focus-within:text-blue-500 transition-colors">
                                    <i data-lucide="user" class="w-5 h-5"></i>
                                </div>
                                <input id="f_donor_name" type="text" name="donor_name" value="<?php echo e($_SESSION['user_name'] ?? ''); ?>" class="w-full pl-16 pr-8 py-5 rounded-2xl bg-slate-50 border border-slate-100 focus:border-blue-500 focus:bg-white outline-none font-bold text-slate-800 transition-all shadow-sm" required>
                            </div>
                        </div>
                        <div class="relative group">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3 ml-2" for="f_message">Pesan & Doa (Opsional)</label>
                            <div class="relative">
                                <div class="absolute left-6 top-6 text-slate-300 group-focus-within:text-blue-500 transition-colors">
                                    <i data-lucide="message-square" class="w-5 h-5"></i>
                                </div>
                                <textarea id="f_message" name="message" rows="1" class="w-full pl-16 pr-8 py-5 rounded-2xl bg-slate-50 border border-slate-100 focus:border-blue-500 focus:bg-white outline-none text-slate-600 font-medium transition-all shadow-sm" placeholder="Tuliskan pesan Anda..."></textarea>
                            </div>
                        </div>
                    </div>

                    <button type="submit" id="payButton" class="w-full py-8 bg-blue-600 text-white rounded-[2.5rem] font-black shadow-2xl shadow-blue-600/40 hover:bg-blue-700 hover:scale-[1.02] active:scale-95 transition-all flex items-center justify-center gap-4 text-xl mt-6 group">
                        <i data-lucide="heart" class="w-8 h-8 fill-white group-hover:scale-125 transition-transform"></i>
                        Donasi Sekarang
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo e($snap_url); ?>" data-client-key="<?php echo e($client_key); ?>"></script>
<script>
function setAmount(val, btn) {
    document.getElementById('amount').value = val;
    document.querySelectorAll('.preset-btn').forEach(b => {
        b.classList.remove('border-blue-500', 'bg-blue-50', 'text-blue-600');
        b.classList.add('border-slate-50', 'bg-slate-50/50', 'text-slate-600');
    });
    btn.classList.remove('border-slate-50', 'bg-slate-50/50', 'text-slate-600');
    btn.classList.add('border-blue-500', 'bg-blue-50', 'text-blue-600');
}

document.getElementById('donationForm').onsubmit = async function(e) {
    e.preventDefault();
    const btn = document.getElementById('payButton');
    btn.disabled = true;
    const originalContent = btn.innerHTML;
    btn.innerHTML = '<i data-lucide="loader-2" class="w-8 h-8 animate-spin"></i> Memproses...';
    lucide.createIcons();

    const formData = new FormData(this);
    try {
        const response = await fetch('handlers/donation_handler.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.snap_token) {
            window.snap.pay(result.snap_token, {
                onSuccess: function(result) { window.location.href = 'index.php?page=donasi&status=success'; },
                onPending: function(result) { window.location.href = 'index.php?page=donasi&status=pending'; },
                onError: function(result) { window.location.href = 'index.php?page=donasi&status=error'; },
                onClose: function() { 
                    btn.disabled = false; 
                    btn.innerHTML = originalContent;
                    lucide.createIcons();
                }
            });
        } else {
            showSwalAlert('Donasi Gagal', result.error || 'Terjadi kesalahan sistem.', 'error');
            btn.disabled = false;
            btn.innerHTML = originalContent;
            lucide.createIcons();
        }
    } catch (err) {
        showSwalAlert('Kesalahan Jaringan', 'Gagal menghubungi server saat memproses donasi.', 'error');
        btn.disabled = false;
        btn.innerHTML = originalContent;
        lucide.createIcons();
    }
};
</script>
