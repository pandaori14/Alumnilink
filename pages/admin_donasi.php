<?php
// Stats
$total_donations = $pdo->query("SELECT SUM(amount) FROM donations WHERE status = 'success'")->fetchColumn() ?? 0;
$total_donors = $pdo->query("SELECT COUNT(id) FROM donations WHERE status = 'success'")->fetchColumn() ?? 0;
$active_campaigns = $pdo->query("SELECT COUNT(id) FROM donation_campaigns WHERE is_active = 1")->fetchColumn() ?? 0;

// Fetch Programs with dynamic totals
$campaigns = $pdo->query("
    SELECT c.*, COALESCE(SUM(d.amount), 0) as current_amount 
    FROM donation_campaigns c 
    LEFT JOIN donations d ON c.id = d.campaign_id AND d.status = 'success'
    GROUP BY c.id
    ORDER BY c.created_at DESC
")->fetchAll();

// Fetch Transactions
$transactions = $pdo->query("
    SELECT d.*, c.title as campaign_title 
    FROM donations d 
    JOIN donation_campaigns c ON d.campaign_id = c.id 
    ORDER BY d.created_at DESC
")->fetchAll();
?>

<div class="max-w-7xl mx-auto">
    <div class="mb-8 px-1">
        <h1 class="text-xl md:text-2xl font-bold outfit text-slate-800 tracking-tight">Manajemen Donasi</h1>
        <p class="text-slate-400 text-xs md:text-sm mt-1 font-medium">Kelola program donasi dan pantau kontribusi alumni.</p>
    </div>
    <div class="flex flex-col md:flex-row md:items-center justify-end gap-6 mb-10 px-1">
        <div class="flex gap-3 w-full md:w-auto">
            <button onclick="document.getElementById('modalAddCampaign').classList.remove('hidden')" class="bg-blue-600 text-white px-6 py-3.5 rounded-2xl font-bold shadow-lg shadow-blue-200 hover:bg-blue-700 transition-all flex items-center gap-2 flex-1 md:flex-initial">
                <i data-lucide="plus-circle" class="w-5 h-5"></i>
                Tambah
            </button>
            <a href="handlers/export_handler.php?type=donations" class="bg-emerald-50 text-emerald-700 px-6 py-3.5 rounded-2xl font-bold border border-emerald-100 hover:bg-emerald-100 transition-all flex items-center gap-2 flex-1 md:flex-initial">
                <i data-lucide="download" class="w-5 h-5"></i>
                Export
            </a>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
        <div class="glass p-8 rounded-[2.5rem] border border-white/50">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-2xl flex items-center justify-center">
                    <i data-lucide="banknote" class="w-6 h-6"></i>
                </div>
                <h2 class="text-sm font-bold text-slate-500 uppercase tracking-widest">Total Dana Masuk</h2>
            </div>
            <p class="text-3xl font-black text-slate-800 outfit">Rp <?php echo number_format($total_donations, 0, ',', '.'); ?></p>
        </div>
        <div class="glass p-8 rounded-[2.5rem] border border-white/50">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-12 h-12 bg-emerald-100 text-emerald-600 rounded-2xl flex items-center justify-center">
                    <i data-lucide="users" class="w-6 h-6"></i>
                </div>
                <h2 class="text-sm font-bold text-slate-500 uppercase tracking-widest">Jumlah Donatur</h2>
            </div>
            <p class="text-3xl font-black text-slate-800 outfit"><?php echo e($total_donors); ?> <span class="text-sm font-medium">Transaksi</span></p>
        </div>
        <div class="glass p-8 rounded-[2.5rem] border border-white/50">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-12 h-12 bg-purple-100 text-purple-600 rounded-2xl flex items-center justify-center">
                    <i data-lucide="flame" class="w-6 h-6"></i>
                </div>
                <h2 class="text-sm font-bold text-slate-500 uppercase tracking-widest">Program Donasi Aktif</h2>
            </div>
            <p class="text-3xl font-black text-slate-800 outfit"><?php echo e($active_campaigns); ?> <span class="text-sm font-medium">Program</span></p>
        </div>
    </div>

    <!-- Tabs -->
    <div class="space-y-10">
        <!-- Section: Campaigns -->
        <section>
            <div class="flex items-center gap-2 mb-6 ml-1">
                <i data-lucide="layers" class="w-5 h-5 text-blue-600"></i>
                <h2 class="text-xl font-bold outfit text-slate-800">Daftar Program Donasi</h2>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <?php foreach ($campaigns as $camp): ?>
                    <div class="glass p-6 rounded-[2rem] border border-white/50 flex gap-6 items-center">
                        <div class="w-24 h-24 rounded-2xl bg-slate-100 overflow-hidden shrink-0">
                            <?php if($camp->image): ?>
                                <img src="<?php echo e($camp->image); ?>" class="w-full h-full object-cover">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center text-slate-300">
                                    <i data-lucide="image" class="w-8 h-8"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="flex-1">
                            <div class="flex justify-between items-start mb-2">
                                <h5 class="font-bold text-slate-800"><?php echo e($camp->title); ?></h5>
                                <span class="px-3 py-1 rounded-full text-[10px] font-bold <?php echo e($camp->is_active ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-100 text-slate-500'); ?>">
                                    <?php echo e($camp->is_active ? 'AKTIF' : 'NON-AKTIF'); ?>
                                </span>
                            </div>
                            <p class="text-[10px] text-slate-500 mb-4 tracking-wide uppercase font-bold">
                                Rp <?php echo number_format($camp->current_amount, 0, ',', '.'); ?> / Rp <?php echo number_format($camp->target_amount, 0, ',', '.'); ?>
                            </p>
                            <div class="flex gap-2 flex-wrap">
                                <button onclick="editCampaign(<?php echo htmlspecialchars(json_encode($camp)); ?>)" class="p-2 bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-100 transition-all" title="Edit">
                                    <i data-lucide="edit-3" class="w-4 h-4"></i>
                                </button>
                                <a href="handlers/admin_campaign_handler.php?action=toggle&id=<?php echo e($camp->id); ?>" class="p-2 <?php echo e($camp->is_active ? 'bg-amber-50 text-amber-600 hover:bg-amber-100' : 'bg-emerald-50 text-emerald-600 hover:bg-emerald-100'); ?> rounded-lg transition-all" title="<?php echo e($camp->is_active ? 'Nonaktifkan' : 'Aktifkan'); ?>">
                                    <i data-lucide="<?php echo e($camp->is_active ? 'pause' : 'play'); ?>" class="w-4 h-4"></i>
                                </a>
                                <a href="handlers/admin_campaign_handler.php?action=delete&id=<?php echo e($camp->id); ?>" onclick="confirmDelete(event, this.href, 'Hapus program donasi ini?')" class="p-2 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition-all" title="Hapus">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Section: Transaction Logs -->
        <section>
            <div class="flex items-center gap-2 mb-6 ml-1">
                <i data-lucide="history" class="w-5 h-5 text-blue-600"></i>
                <h2 class="text-xl font-bold outfit text-slate-800">Riwayat Donasi</h2>
            </div>
            <div class="glass rounded-[2.5rem] overflow-hidden border border-white/50">
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-50/50 border-b border-slate-100">
                                <th class="px-8 py-5 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Donatur</th>
                                <th class="px-8 py-5 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Program Donasi</th>
                                <th class="px-8 py-5 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Nominal</th>
                                <th class="px-8 py-5 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Status</th>
                                <th class="px-8 py-5 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Tanggal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php foreach ($transactions as $tx): ?>
                                <tr class="hover:bg-blue-50/30 transition-all">
                                    <td class="px-8 py-5">
                                        <p class="font-bold text-slate-800"><?php echo e($tx->donor_name); ?></p>
                                        <p class="text-[10px] text-slate-400"><?php echo e($tx->midtrans_order_id); ?></p>
                                    </td>
                                    <td class="px-8 py-5">
                                        <p class="text-sm font-medium text-slate-600"><?php echo e($tx->campaign_title); ?></p>
                                    </td>
                                    <td class="px-8 py-5 font-bold text-blue-600">
                                        Rp <?php echo number_format($tx->amount, 0, ',', '.'); ?>
                                    </td>
                                    <td class="px-8 py-5">
                                        <?php 
                                            $s = $tx->status;
                                            $color = 'bg-slate-100 text-slate-500';
                                            if($s === 'success') $color = 'bg-emerald-100 text-emerald-600';
                                            if($s === 'pending') $color = 'bg-amber-100 text-amber-600';
                                            if($s === 'failed' || $s === 'expired') $color = 'bg-red-100 text-red-600';
                                        ?>
                                        <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-tighter <?php echo e($color); ?>">
                                            <?php echo e($s); ?>
                                        </span>
                                    </td>
                                    <td class="px-8 py-5 text-sm text-slate-400">
                                        <?php echo date('d/m/Y H:i', strtotime($tx->created_at)); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>

<!-- Modal Add/Edit Campaign -->
<div id="modalAddCampaign" class="fixed inset-0 z-[60] bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-6">
    <div class="glass max-w-xl w-full rounded-[3rem] p-10 border border-white relative shadow-2xl">
        <button onclick="document.getElementById('modalAddCampaign').classList.add('hidden')" class="absolute top-8 right-8 text-slate-400 hover:text-slate-800 transition-all">
            <i data-lucide="x" class="w-6 h-6"></i>
        </button>
        
        <h2 id="modalTitle" class="text-2xl font-bold outfit text-slate-800 mb-8">Tambah Program Donasi</h2>
        
        <form action="handlers/admin_campaign_handler.php" method="POST" enctype="multipart/form-data" class="space-y-5">
            <?php csrf_field(); ?>
            <input type="hidden" name="id" id="formId">
            <input type="hidden" name="existing_image" id="formExistingImage">
            <div>
                <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Judul Program Donasi</label>
                <input type="text" name="title" id="formTitle" class="w-full px-5 py-4 rounded-2xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none" required>
            </div>
            <div>
                <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Deskripsi</label>
                <textarea name="description" id="formDesc" rows="3" class="w-full px-5 py-4 rounded-2xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none" required></textarea>
            </div>
            <div>
                <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Foto Flyer / Gambar Program</label>
                <div id="imagePreviewWrap" class="mb-3 hidden">
                    <img id="imagePreview" src="" alt="Preview" class="w-full h-40 object-cover rounded-2xl border border-slate-200">
                </div>
                <input aria-label="Unggah gambar sampul" type="file" name="image" id="formImage" accept="image/*" onchange="previewImage(this)"
                    class="w-full px-5 py-4 rounded-2xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none text-sm">
                <p class="text-[10px] text-slate-400 mt-1 ml-1">Format: JPG, PNG, WebP. Maks 5MB. Kosongkan jika tidak ingin mengubah gambar.</p>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Target Dana (Rp)</label>
                    <input type="number" name="target_amount" id="formTarget" class="w-full px-5 py-4 rounded-2xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none" required>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Batas Waktu</label>
                    <input aria-label="Tanggal akhir" type="date" name="end_date" id="formEndDate" class="w-full px-5 py-4 rounded-2xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none" required>
                </div>
            </div>
            <button type="submit" class="w-full py-5 bg-blue-600 text-white rounded-[2rem] font-bold shadow-xl shadow-blue-500/20 hover:bg-blue-700 transition-all mt-2">
                Simpan Program Donasi
            </button>
        </form>
    </div>
</div>

<script>
function editCampaign(camp) {
    document.getElementById('modalTitle').innerText = 'Edit Program Donasi';
    document.getElementById('formId').value = camp.id;
    document.getElementById('formTitle').value = camp.title;
    document.getElementById('formDesc').value = camp.description;
    document.getElementById('formTarget').value = camp.target_amount;
    document.getElementById('formEndDate').value = camp.end_date ? camp.end_date.substring(0, 10) : '';
    document.getElementById('formExistingImage').value = camp.image || '';
    // Show existing image preview
    const wrap = document.getElementById('imagePreviewWrap');
    const prev = document.getElementById('imagePreview');
    if (camp.image) {
        prev.src = camp.image;
        wrap.classList.remove('hidden');
    } else {
        wrap.classList.add('hidden');
    }
    document.getElementById('formImage').value = ''; // reset file input
    document.getElementById('modalAddCampaign').classList.remove('hidden');
}

function previewImage(input) {
    const wrap = document.getElementById('imagePreviewWrap');
    const prev = document.getElementById('imagePreview');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { prev.src = e.target.result; wrap.classList.remove('hidden'); };
        reader.readAsDataURL(input.files[0]);
    }
}

// Reset modal saat Tambah
document.querySelector('[onclick="document.getElementById(\'modalAddCampaign\').classList.remove(\'hidden\')"]')?.addEventListener('click', () => {
    document.getElementById('modalTitle').innerText = 'Tambah Program Donasi';
    document.getElementById('formId').value = '';
    document.getElementById('formTitle').value = '';
    document.getElementById('formDesc').value = '';
    document.getElementById('formTarget').value = '';
    document.getElementById('formEndDate').value = '';
    document.getElementById('formExistingImage').value = '';
    document.getElementById('formImage').value = '';
    document.getElementById('imagePreviewWrap').classList.add('hidden');
});
</script>
