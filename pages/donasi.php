<?php
// Fetch active program donasi with dynamic total collected amount
$stmt = $pdo->query("
    SELECT c.*, COALESCE(SUM(d.amount), 0) as current_amount 
    FROM donation_campaigns c 
    LEFT JOIN donations d ON c.id = d.campaign_id AND d.status = 'success'
    WHERE c.is_active = 1 
    GROUP BY c.id
    ORDER BY c.created_at DESC
");
$campaigns = $stmt->fetchAll();

// Fetch total donors count and overall collected
$donor_count = $pdo->query("SELECT COUNT(DISTINCT donor_name) FROM donations WHERE status = 'success'")->fetchColumn();
$total_collected = $pdo->query("SELECT SUM(amount) FROM donations WHERE status = 'success'")->fetchColumn() ?? 0;
?>

<div class="max-w-6xl mx-auto px-2 md:px-0">
    <!-- Header Hero -->
    <div class="relative overflow-hidden rounded-[3rem] bg-slate-900 p-8 md:p-20 mb-12 shadow-2xl shadow-blue-900/10">
        <!-- Abstract BG Elements -->
        <div class="absolute top-0 right-0 -translate-y-1/4 translate-x-1/4 opacity-20 pointer-events-none">
            <div class="w-[500px] h-[500px] bg-blue-500 rounded-full blur-[120px]"></div>
        </div>
        <div class="absolute bottom-0 left-0 translate-y-1/4 -translate-x-1/4 opacity-10 pointer-events-none">
            <div class="w-[400px] h-[400px] bg-indigo-500 rounded-full blur-[100px]"></div>
        </div>
        
        <div class="relative z-10 max-w-2xl">
            <div class="inline-flex items-center gap-2 px-4 py-2 bg-blue-500/10 backdrop-blur-md rounded-full border border-blue-500/20 text-blue-400 text-[10px] font-bold uppercase tracking-widest mb-6">
                <i data-lucide="sparkles" class="w-3 h-3"></i> Investasi Akhirat
            </div>
            <h1 class="text-4xl md:text-6xl font-black outfit text-white mb-6 leading-[1.1]">Dana Abadi <br><span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-indigo-300">Alumni FK UMS</span></h1>
            <p class="text-slate-400 text-lg mb-10 leading-relaxed">Berikan kontribusi terbaik Anda untuk mencetak generasi dokter hebat dan pengembangan fasilitas pendidikan unggulan.</p>
            
            <div class="flex flex-wrap gap-4">
                <div class="bg-white/5 backdrop-blur-xl px-8 py-5 rounded-[2rem] border border-white/10 flex flex-col justify-center">
                    <p class="text-[9px] font-bold text-slate-500 uppercase tracking-[0.2em] mb-2">Total Donasi</p>
                    <p class="text-3xl font-black text-white outfit">Rp <?php echo number_format($total_collected, 0, ',', '.'); ?></p>
                </div>
                <div class="bg-white/5 backdrop-blur-xl px-8 py-5 rounded-[2rem] border border-white/10 flex flex-col justify-center">
                    <p class="text-[9px] font-bold text-slate-500 uppercase tracking-[0.2em] mb-2">Donatur Aktif</p>
                    <p class="text-3xl font-black text-white outfit"><?php echo number_format($donor_count, 0, ',', '.'); ?> <span class="text-sm font-medium text-slate-500 ml-1">Jiwa</span></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Title Section -->
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-10 px-2">
        <div>
            <h2 class="text-2xl font-black outfit text-slate-800">Program Dana Abadi</h2>
            <p class="text-slate-500 text-sm mt-1">Pilih program yang ingin Anda dukung hari ini.</p>
        </div>
        <div class="flex items-center gap-2 text-blue-600 font-bold text-sm hover:translate-x-1 transition-transform cursor-pointer">
            Lihat Semua <i data-lucide="arrow-right" class="w-4 h-4"></i>
        </div>
    </div>

    <!-- Program Donasi List -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-8 md:gap-12 mb-20">
        <?php foreach ($campaigns as $camp): 
            $percent = min(100, ($camp->target_amount > 0 ? ($camp->current_amount / $camp->target_amount) * 100 : 0));
        ?>
            <div class="group relative">
                <div class="absolute -inset-4 bg-gradient-to-br from-blue-500/5 to-indigo-500/5 rounded-[4rem] blur-2xl opacity-0 group-hover:opacity-100 transition-all duration-500"></div>
                
                <div class="relative glass overflow-hidden rounded-[3.5rem] bg-white/70 hover:bg-white transition-all duration-500 border border-white/50 shadow-sm hover:shadow-2xl hover:shadow-blue-500/10 h-full flex flex-col">
                    <div class="relative h-72 overflow-hidden">
                        <?php if ($camp->image): ?>
                            <img src="<?php echo e($camp->image); ?>" class="w-full h-full object-cover group-hover:scale-110 transition-all duration-1000" alt="Program Donasi">
                        <?php else: ?>
                            <div class="w-full h-full bg-gradient-to-br from-slate-50 to-slate-100 flex items-center justify-center">
                                <div class="w-20 h-20 bg-white rounded-3xl shadow-inner flex items-center justify-center">
                                    <i data-lucide="heart" class="w-10 h-10 text-slate-200 fill-slate-50"></i>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="absolute top-8 left-8">
                            <div class="px-5 py-2 bg-white/90 backdrop-blur-md rounded-full text-[10px] font-black text-blue-600 shadow-xl shadow-black/5 border border-white flex items-center gap-2">
                                <span class="w-2 h-2 bg-blue-600 rounded-full animate-pulse"></span>
                                AKTIF SEKARANG
                            </div>
                        </div>
                    </div>
                    
                    <div class="p-10 flex-1 flex flex-col">
                        <h4 class="text-2xl font-black outfit text-slate-800 mb-4 group-hover:text-blue-600 transition-colors leading-tight"><?php echo e($camp->title); ?></h4>
                        <p class="text-slate-500 text-sm mb-10 leading-relaxed line-clamp-3"><?php echo e($camp->description); ?></p>
                        
                            <!-- Progress Bar Section -->
                        <div class="mt-auto space-y-3">
                            <div class="flex justify-between items-end">
                                <div>
                                    <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Terkumpul</p>
                                    <p class="text-xl font-black text-blue-600 outfit">Rp <?php echo number_format($camp->current_amount, 0, ',', '.'); ?></p>
                                </div>
                                <div class="text-right">
                                    <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Target Dana</p>
                                    <p class="text-sm font-bold text-slate-500">Rp <?php echo number_format($camp->target_amount, 0, ',', '.'); ?></p>
                                </div>
                            </div>

                            <div class="w-full h-3 bg-slate-100 rounded-full overflow-hidden border border-slate-200/50 shadow-inner">
                                <div class="h-full bg-gradient-to-r from-blue-600 via-indigo-500 to-purple-500 rounded-full transition-all duration-1000 ease-out relative group-hover:shadow-[0_0_10px_rgba(37,99,235,0.4)]" style="width: <?php echo e($percent); ?>%">
                                    <div class="absolute inset-0 bg-white/20 animate-shimmer"></div>
                                </div>
                            </div>

                            <div class="flex justify-end">
                                <span class="inline-block bg-slate-800 text-white text-[9px] font-black px-3 py-1 rounded-lg outfit shadow-sm">
                                    <?php echo round($percent, 1); ?>%
                                </span>
                            </div>

                            <a href="index.php?page=donasi_detail&id=<?php echo e($camp->id); ?>" class="flex items-center justify-center gap-4 w-full py-5 bg-slate-900 text-white rounded-[2rem] font-bold hover:bg-blue-600 shadow-2xl shadow-slate-900/10 hover:shadow-blue-500/20 active:scale-[0.98] transition-all duration-300">
                                <i data-lucide="heart" class="w-5 h-5 fill-white"></i>
                                Berikan Donasi Terbaik
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Recent Donors Section -->
    <div class="mb-20 px-2">
        <div class="glass p-10 md:p-16 rounded-[4rem] border border-white shadow-sm overflow-hidden relative">
            <div class="absolute -top-24 -right-24 opacity-5 rotate-12">
                <i data-lucide="heart" class="w-96 h-96"></i>
            </div>

            <?php
            // Fetch top donors for stack and cards
            $stmt_donors = $pdo->query("
                SELECT d.*, u.avatar, c.title as campaign_title 
                FROM donations d 
                LEFT JOIN users u ON d.user_id = u.id
                JOIN donation_campaigns c ON d.campaign_id = c.id 
                WHERE d.status = 'success' 
                ORDER BY d.created_at DESC 
                LIMIT 10
            ");
            $donors = $stmt_donors->fetchAll();
            $stack_donors = array_slice($donors, 0, 8);
            $card_donors = array_slice($donors, 0, 6);
            ?>

            <div class="relative z-10">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-12">
                    <div>
                        <h2 class="text-3xl font-black outfit text-slate-800">Deretan Donatur</h2>
                        <p class="text-slate-500 mt-2 font-medium">Terima kasih atas kepedulian Anda terhadap almamater.</p>
                    </div>
                    <div class="flex -space-x-4">
                        <?php foreach($stack_donors as $sd): ?>
                            <div class="w-12 h-12 rounded-full border-4 border-white bg-blue-600 overflow-hidden shadow-lg relative group">
                                <?php if ($sd->avatar && file_exists('uploads/avatars/' . $sd->avatar)): ?>
                                    <img src="uploads/avatars/<?php echo e($sd->avatar); ?>" alt="Donor" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <div class="w-full h-full flex items-center justify-center text-[10px] font-black text-white uppercase">
                                        <?php echo e(substr($sd->donor_name, 0, 2)); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                                    <span class="text-[8px] text-white font-bold"><?php echo e(explode(' ', $sd->donor_name)[0]); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="w-12 h-12 rounded-full border-4 border-white bg-slate-900 flex items-center justify-center text-xs font-black text-white shadow-xl">+</div>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
                    <?php foreach ($card_donors as $don): ?>
                        <div class="bg-white/50 p-8 rounded-[3rem] border border-slate-50 hover:border-blue-200 hover:shadow-2xl hover:shadow-blue-500/5 transition-all group relative">
                            <div class="flex items-center gap-5 mb-6">
                                <div class="w-14 h-14 bg-blue-600 rounded-2xl flex items-center justify-center shrink-0 shadow-lg shadow-blue-500/10 border-2 border-white overflow-hidden">
                                    <?php if ($don->avatar && file_exists('uploads/avatars/' . $don->avatar)): ?>
                                        <img src="uploads/avatars/<?php echo e($don->avatar); ?>" alt="Donor" class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <span class="text-white font-black outfit text-sm uppercase"><?php echo e(substr($don->donor_name, 0, 2)); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="overflow-hidden">
                                    <p class="text-base font-black text-slate-800 truncate outfit"><?php echo e($don->donor_name); ?></p>
                                    <p class="text-[9px] font-black text-blue-600/60 uppercase tracking-[0.2em] mt-1"><?php echo date('d M Y', strtotime($don->created_at)); ?></p>
                                </div>
                            </div>
                            <div class="space-y-4">
                                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest leading-relaxed">Mendukung <br><span class="text-slate-800"><?php echo e($don->campaign_title); ?></span></p>
                                <div class="p-5 bg-slate-50/50 rounded-2xl text-xs text-slate-500 italic leading-relaxed relative border border-slate-100">
                                    <i data-lucide="quote" class="absolute -top-2 -left-2 w-5 h-5 text-slate-200 fill-white"></i>
                                    "<?php echo htmlspecialchars($don->message ?: 'Berkontribusi untuk kebaikan bersama.'); ?>"
                                </div>
                                <div class="flex items-center justify-between pt-2">
                                    <span class="text-[9px] font-black text-emerald-600 bg-emerald-50 px-3 py-1.5 rounded-lg border border-emerald-100 uppercase tracking-widest">SUCCESS</span>
                                    <p class="text-xl font-black text-slate-800 outfit">Rp <?php echo number_format($don->amount, 0, ',', '.'); ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}
.animate-shimmer {
    animation: shimmer 2s infinite;
}
</style>
