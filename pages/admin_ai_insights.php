<?php
// Check if user is super_admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'super_admin') {
    ?>
    <div class="max-w-2xl mx-auto px-4 py-16 text-center">
        <div class="glass p-12 rounded-[3rem] border border-white shadow-xl relative overflow-hidden">
            <div class="absolute -right-10 -top-10 w-40 h-40 bg-red-50 rounded-full blur-2xl pointer-events-none"></div>
            <div class="w-24 h-24 bg-red-100 text-red-600 rounded-3xl flex items-center justify-center mx-auto mb-8 shadow-inner border border-red-200">
                <i data-lucide="shield-alert" class="w-12 h-12"></i>
            </div>
            <h2 class="text-3xl font-black outfit text-slate-800 mb-4 tracking-tight">Akses Ditolak</h2>
            <p class="text-slate-500 mb-8 max-w-md mx-auto leading-relaxed text-sm">Maaf, halaman ini berisi fitur Analisis AI tingkat lanjut yang hanya dapat diakses oleh akun dengan peran <span class="font-bold text-slate-700">Super Administrator</span>.</p>
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

// Fetch aggregate data for AI
$total_tracer = $pdo->query("SELECT COUNT(*) FROM tracer_submissions")->fetchColumn();
$work_status = $pdo->query("SELECT work_status, COUNT(*) as count FROM tracer_submissions GROUP BY work_status")->fetchAll(PDO::FETCH_ASSOC);
$relevance = $pdo->query("SELECT field_relevance, COUNT(*) as count FROM tracer_submissions GROUP BY field_relevance")->fetchAll(PDO::FETCH_ASSOC);
$salary = $pdo->query("SELECT salary_range, COUNT(*) as count FROM tracer_submissions GROUP BY salary_range")->fetchAll(PDO::FETCH_ASSOC);

// Get Gemini API Key
$gemini_key = $sys_settings['gemini_api_key'] ?? '';
?>

<div class="max-w-5xl mx-auto px-4 md:px-0">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-10">
        <div>
            <h3 class="text-3xl font-black outfit text-slate-800 flex items-center gap-3">
                <span class="p-3 bg-indigo-600 text-white rounded-2xl shadow-lg shadow-indigo-200">
                    <i data-lucide="sparkles" class="w-6 h-6"></i>
                </span>
                AI-Powered Analytics
            </h3>
            <p class="text-slate-500 mt-2 font-medium">Otomatisasi Laporan Naratif untuk Akreditasi BAN-PT/LAM-PTKes</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="index.php?page=admin_tracer" class="px-6 py-3 bg-white text-slate-600 rounded-2xl font-bold border border-slate-100 hover:bg-slate-50 transition-all flex items-center gap-2">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>
                Kembali
            </a>
        </div>
    </div>

    <?php if (!$gemini_key): ?>
        <div class="glass p-10 rounded-[3rem] border-2 border-dashed border-amber-200 bg-amber-50/50 text-center">
            <div class="w-20 h-20 bg-amber-100 text-amber-600 rounded-full flex items-center justify-center mx-auto mb-6">
                <i data-lucide="key" class="w-10 h-10"></i>
            </div>
            <h4 class="text-2xl font-bold outfit text-slate-800 mb-2">API Key Belum Diatur</h4>
            <p class="text-slate-500 mb-8 max-w-md mx-auto">Silakan masukkan Google Gemini API Key di menu Pengaturan Sistem untuk mengaktifkan fitur analisis AI.</p>
            <a href="index.php?page=admin_settings" class="inline-flex items-center gap-2 px-8 py-4 bg-amber-600 text-white rounded-2xl font-bold shadow-lg shadow-amber-200 hover:bg-amber-700 transition-all">
                Buka Pengaturan <i data-lucide="external-link" class="w-4 h-4"></i>
            </a>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <!-- Stats Column -->
            <div class="md:col-span-1 space-y-6">
                <div class="glass p-8 rounded-[2.5rem] border border-white shadow-sm">
                    <h5 class="text-xs font-black text-slate-400 uppercase tracking-[0.2em] mb-6">Data Agregasi</h5>
                    <div class="space-y-6">
                        <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100">
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Total Responden</p>
                            <p class="text-2xl font-black text-slate-800 outfit"><?php echo e($total_tracer); ?> Alumni</p>
                        </div>
                        
                        <div>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-3 ml-1">Status Kerja</p>
                            <div class="space-y-2">
                                <?php foreach($work_status as $ws): ?>
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="text-slate-500 font-medium capitalize"><?php echo e($ws['work_status']); ?></span>
                                        <span class="font-bold text-slate-800"><?php echo e($ws['count']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="pt-4 border-t border-slate-100">
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-3 ml-1">Relevansi Bidang</p>
                            <div class="space-y-2">
                                <?php foreach($relevance as $r): ?>
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="text-slate-500 font-medium capitalize"><?php echo e($r['field_relevance']); ?></span>
                                        <span class="font-bold text-slate-800"><?php echo e($r['count']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="glass p-8 rounded-[2.5rem] bg-indigo-600 text-white shadow-xl shadow-indigo-200 relative overflow-hidden group">
                    <div class="absolute -right-10 -bottom-10 opacity-10 group-hover:scale-110 transition-transform">
                        <i data-lucide="sparkles" class="w-40 h-40"></i>
                    </div>
                    <div class="relative z-10">
                        <h4 class="text-lg font-bold outfit mb-2">Siap Menganalisis?</h4>
                        <p class="text-indigo-100 text-xs leading-relaxed mb-6 opacity-80">Klik tombol di bawah untuk membuat narasi laporan profesional menggunakan kecerdasan buatan.</p>
                        <button id="generateAI" class="w-full py-4 bg-white text-indigo-600 rounded-2xl font-black uppercase tracking-widest text-[10px] shadow-lg hover:bg-indigo-50 transition-all flex items-center justify-center gap-2">
                            <i data-lucide="wand-2" class="w-4 h-4"></i>
                            Generate Laporan
                        </button>
                    </div>
                </div>
            </div>

            <!-- Analysis Result Column -->
            <div class="md:col-span-2">
                <div class="glass h-full min-h-[600px] rounded-[3rem] border border-white shadow-sm flex flex-col overflow-hidden bg-white/40">
                    <div class="p-8 border-b border-white/20 flex items-center justify-between shrink-0">
                        <div class="flex items-center gap-3">
                            <div class="w-2 h-8 bg-indigo-500 rounded-full"></div>
                            <h4 class="text-xl font-black outfit text-slate-800">Hasil Analisis Naratif</h4>
                        </div>
                        <div id="aiLoading" class="hidden">
                            <div class="flex items-center gap-2 px-4 py-2 bg-indigo-50 text-indigo-600 rounded-xl text-[10px] font-bold animate-pulse">
                                <span class="w-2 h-2 bg-indigo-600 rounded-full animate-ping"></span>
                                Gemini sedang berpikir...
                            </div>
                        </div>
                        <button id="copyToClipboard" class="hidden px-4 py-2 bg-slate-800 text-white rounded-xl text-[10px] font-bold hover:bg-slate-900 transition-all flex items-center gap-2">
                            <i data-lucide="copy" class="w-3.5 h-3.5"></i> Copy Text
                        </button>
                    </div>

                    <div id="aiPlaceholder" class="flex-1 flex flex-col items-center justify-center p-12 text-center">
                        <div class="w-20 h-20 bg-slate-50 text-slate-200 rounded-full flex items-center justify-center mb-6">
                            <i data-lucide="brain-circuit" class="w-10 h-10"></i>
                        </div>
                        <h5 class="text-lg font-bold text-slate-400 mb-2">Belum Ada Laporan</h5>
                        <p class="text-slate-400 text-sm max-w-xs mx-auto italic">Klik "Generate Laporan" untuk memulai analisis naratif menggunakan data terkini.</p>
                    </div>

                    <div id="aiResult" class="hidden flex-1 p-10 prose prose-slate max-w-none prose-sm font-medium leading-relaxed overflow-y-auto">
                        <!-- Result will be here -->
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
document.getElementById('generateAI')?.addEventListener('click', async function() {
    const btn = this;
    const loading = document.getElementById('aiLoading');
    const placeholder = document.getElementById('aiPlaceholder');
    const resultDiv = document.getElementById('aiResult');
    const copyBtn = document.getElementById('copyToClipboard');

    // Show loading
    btn.disabled = true;
    loading.classList.remove('hidden');
    placeholder.classList.add('hidden');
    resultDiv.classList.add('hidden');
    copyBtn.classList.add('hidden');

    try {
        const response = await fetch('handlers/gemini_ai.php');
        const data = await response.json();

        if (data.success) {
            resultDiv.innerHTML = data.content.replace(/\n/g, '<br>');
            resultDiv.classList.remove('hidden');
            copyBtn.classList.remove('hidden');
            
            // Re-render lucide for icons in dynamic content if any
            lucide.createIcons();
        } else {
            showSwalAlert('Gagal', 'Error: ' + data.message, 'error');
            placeholder.classList.remove('hidden');
        }
    } catch (error) {
        showSwalAlert('Kesalahan Koneksi', 'Terjadi kesalahan koneksi saat menghubungi AI server.', 'error');
        placeholder.classList.remove('hidden');
    } finally {
        btn.disabled = false;
        loading.classList.add('hidden');
    }
});

document.getElementById('copyToClipboard')?.addEventListener('click', function() {
    const text = document.getElementById('aiResult').innerText;
    navigator.clipboard.writeText(text).then(() => {
        const originalText = this.innerHTML;
        this.innerHTML = '<i data-lucide="check" class="w-3.5 h-3.5"></i> Copied!';
        lucide.createIcons();
        setTimeout(() => {
            this.innerHTML = originalText;
            lucide.createIcons();
        }, 2000);
    });
});
</script>
