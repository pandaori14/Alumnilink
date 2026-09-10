<?php
// Fetch custom layouts for this admin
$stmt = $pdo->prepare("SELECT * FROM broadcast_layouts WHERE created_by = ? ORDER BY created_at DESC");
$stmt->execute([$_SESSION['user_id']]);
$layouts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Map thumb_types to SVGs
function getThumbSVG($type) {
    $s = function($shapes) { return '<svg width="92" height="80" viewBox="0 0 92 80" xmlns="http://www.w3.org/2000/svg">' . $shapes . '</svg>'; };
    $thumbs = [
        'simple'       => $s('<rect x="4" y="8" width="84" height="7" rx="3" fill="#c7d2fe"/><rect x="4" y="22" width="84" height="3" rx="1.5" fill="#e2e8f0"/><rect x="4" y="28" width="60" height="3" rx="1.5" fill="#e2e8f0"/><rect x="4" y="37" width="84" height="3" rx="1.5" fill="#e2e8f0"/><rect x="4" y="43" width="70" height="3" rx="1.5" fill="#e2e8f0"/><rect x="4" y="49" width="50" height="3" rx="1.5" fill="#e2e8f0"/><rect x="22" y="62" width="48" height="11" rx="5" fill="#6366f1"/>'),
        'announcement' => $s('<rect x="0" y="0" width="92" height="26" rx="2" fill="#6366f1"/><rect x="16" y="7" width="60" height="5" rx="2" fill="white" opacity="0.9"/><rect x="22" y="16" width="48" height="3" rx="1.5" fill="white" opacity="0.6"/><rect x="4" y="33" width="84" height="3" rx="1.5" fill="#e2e8f0"/><rect x="4" y="40" width="84" height="3" rx="1.5" fill="#e2e8f0"/><rect x="4" y="47" width="55" height="3" rx="1.5" fill="#e2e8f0"/><rect x="22" y="60" width="48" height="12" rx="5" fill="#6366f1"/>'),
        'newsletter'   => $s('<rect x="0" y="0" width="92" height="20" rx="2" fill="#1e293b"/><rect x="4" y="5" width="32" height="6" rx="2" fill="#6366f1"/><rect x="52" y="7" width="36" height="3" rx="1.5" fill="#475569"/><rect x="4" y="26" width="84" height="22" rx="4" fill="#eef2ff"/><rect x="8" y="30" width="45" height="5" rx="2" fill="#6366f1" opacity="0.5"/><rect x="8" y="38" width="74" height="3" rx="1.5" fill="#c7d2fe"/><rect x="4" y="54" width="40" height="16" rx="4" fill="#f8fafc" stroke="#e2e8f0" stroke-width="1"/><rect x="48" y="54" width="40" height="16" rx="4" fill="#f8fafc" stroke="#e2e8f0" stroke-width="1"/><rect x="0" y="74" width="92" height="6" fill="#1e293b"/>'),
        'event'        => $s('<rect x="0" y="0" width="92" height="30" rx="2" fill="#0f172a"/><circle cx="78" cy="8" r="12" fill="#6366f1" opacity="0.2"/><rect x="18" y="7" width="56" height="6" rx="3" fill="white" opacity="0.9"/><rect x="24" y="18" width="44" height="4" rx="2" fill="white" opacity="0.5"/><rect x="2" y="36" width="27" height="20" rx="4" fill="#f8fafc" stroke="#e2e8f0" stroke-width="1"/><rect x="32" y="36" width="27" height="20" rx="4" fill="#f8fafc" stroke="#e2e8f0" stroke-width="1"/><rect x="62" y="36" width="27" height="20" rx="4" fill="#f8fafc" stroke="#e2e8f0" stroke-width="1"/><rect x="22" y="62" width="48" height="12" rx="5" fill="#6366f1"/>'),
        'referral'     => $s('<circle cx="46" cy="18" r="13" fill="#eef2ff" stroke="#6366f1" stroke-width="1.5"/><rect x="40" y="12" width="12" height="12" rx="3" fill="#6366f1" opacity="0.4"/><rect x="4" y="38" width="84" height="10" rx="4" fill="#f0fdf4" stroke="#22c55e" stroke-width="1"/><rect x="4" y="52" width="84" height="10" rx="4" fill="#eff6ff" stroke="#3b82f6" stroke-width="1"/><rect x="4" y="66" width="84" height="10" rx="4" fill="#fef3c7" stroke="#f59e0b" stroke-width="1"/>'),
    ];
    return $thumbs[$type] ?? $thumbs['simple'];
}
?>

<div class="max-w-7xl mx-auto">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
        <div>
            <h1 class="text-2xl font-black outfit text-slate-800 tracking-tight">Template Email (Layouts)</h1>
            <p class="text-slate-400 text-sm font-medium mt-1">Kelola dan desain kustom template email broadcast tingkat enterprise.</p>
        </div>
        <button onclick="openLayoutModal()" class="bg-blue-600 text-white px-6 py-3 rounded-2xl font-bold flex items-center gap-2 hover:bg-blue-700 transition-all shadow-lg shadow-blue-100">
            <i data-lucide="plus" class="w-5 h-5"></i> Buat Layout Baru
        </button>
    </div>

    <!-- Info Banner -->
    <div class="bg-indigo-50 border border-indigo-100 rounded-[2rem] p-6 mb-8 flex gap-4 animate-in fade-in slide-in-from-top-2">
        <div class="w-12 h-12 bg-white rounded-2xl flex items-center justify-center flex-shrink-0 shadow-sm">
            <i data-lucide="layout-template" class="w-6 h-6 text-indigo-600"></i>
        </div>
        <div>
            <h3 class="text-indigo-900 font-bold mb-1">Manajemen Template Personal</h3>
            <p class="text-indigo-700/80 text-sm leading-relaxed">
                Anda dapat membuat dan menyimpan template rancangan Anda sendiri untuk digunakan di menu Broadcast. 
                Gunakan editor HTML untuk menyalin dan memodifikasi email perusahaan pihak ketiga.
            </p>
        </div>
    </div>

    <!-- Grid Layouts -->
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6">
        <?php foreach ($layouts as $l): ?>
        <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden hover:shadow-xl transition-all group flex flex-col">
            <div class="h-32 bg-slate-50 flex items-center justify-center relative border-b border-slate-100">
                <?php echo getThumbSVG($l['thumb_type'] ?? 'simple'); ?>
                <!-- Overlay Hover Actions -->
                <div class="absolute inset-0 bg-slate-900/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2 backdrop-blur-[2px]">
                    <button onclick="editLayout(<?php echo htmlspecialchars(json_encode($l)); ?>)" class="w-10 h-10 bg-white text-blue-600 rounded-xl flex items-center justify-center hover:scale-110 transition-transform" title="Edit Layout">
                        <i data-lucide="edit-3" class="w-5 h-5"></i>
                    </button>
                    <button onclick="deleteLayout(<?php echo e($l['id']); ?>)" class="w-10 h-10 bg-red-500 text-white rounded-xl flex items-center justify-center hover:scale-110 transition-transform" title="Hapus Layout">
                        <i data-lucide="trash-2" class="w-5 h-5"></i>
                    </button>
                </div>
            </div>
            <div class="p-5 flex-1 flex flex-col">
                <h3 class="font-bold text-sm text-slate-800 line-clamp-1 mb-1"><?php echo htmlspecialchars($l['name']); ?></h3>
                <p class="text-[10px] text-slate-400 mt-auto"><?php echo date('d M Y, H:i', strtotime($l['created_at'])); ?></p>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (empty($layouts)): ?>
        <div class="col-span-full py-20 text-center glass rounded-[3rem]">
            <i data-lucide="layout-dashboard" class="w-16 h-16 text-slate-200 mx-auto mb-4"></i>
            <h3 class="text-xl font-bold outfit text-slate-400">Belum Ada Layout Personal</h3>
            <p class="text-slate-300 text-sm mt-2">Anda belum membuat layout kustom. Klik "Buat Layout Baru" untuk memulai.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add/Edit Modal (Full Screen Layout Editor) -->
<div id="layoutModal" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-slate-900/80 backdrop-blur-sm"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4 md:p-6">
        <div class="bg-white rounded-[2rem] md:rounded-[3rem] shadow-2xl w-full max-w-7xl h-[95vh] flex flex-col overflow-hidden animate-in zoom-in-95 duration-300">
            
            <!-- Header -->
            <div class="p-6 md:px-10 md:py-6 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-blue-600 rounded-2xl flex items-center justify-center text-white shadow-lg shadow-blue-200">
                        <i data-lucide="code-2" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h2 id="modalTitle" class="text-xl md:text-2xl font-black outfit text-slate-800 leading-none mb-1">Buat Layout Kustom</h2>
                        <p class="text-[10px] md:text-xs font-bold text-slate-400 uppercase tracking-widest">HTML Code Editor & Live Preview</p>
                    </div>
                </div>
                <button onclick="closeLayoutModal()" class="w-12 h-12 bg-white border border-slate-200 text-slate-400 rounded-2xl flex items-center justify-center hover:bg-red-50 hover:text-red-500 hover:border-red-200 transition-all shadow-sm">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>

            <!-- Body: Split Screen -->
            <div class="flex-1 flex flex-col md:flex-row overflow-hidden bg-slate-100/50">
                
                <!-- Left: Form & Editor -->
                <div class="w-full md:w-1/2 flex flex-col border-r border-slate-200 bg-white">
                    <div class="p-6 md:p-8 flex-1 overflow-y-auto space-y-6">
                        
                        <input type="hidden" id="layout_id">
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div class="col-span-2">
                                <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Nama Layout</label>
                                <input aria-label="Misal: Template Invoice Pembayaran" type="text" id="layout_name" class="w-full px-5 py-4 rounded-2xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none text-sm font-bold" placeholder="Misal: Template Invoice Pembayaran">
                            </div>
                            
                            <div class="col-span-2">
                                <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Ikon Thumbnail (UI)</label>
                                <div class="grid grid-cols-5 gap-2">
                                    <?php
                                    $thumbs = ['simple', 'announcement', 'newsletter', 'event', 'referral'];
                                    foreach ($thumbs as $t):
                                    ?>
                                    <label class="cursor-pointer relative">
                                        <input type="radio" name="thumb_type" value="<?php echo e($t); ?>" class="peer sr-only">
                                        <div class="p-2 border-2 border-slate-100 rounded-xl peer-checked:border-blue-600 peer-checked:bg-blue-50 hover:bg-slate-50 transition-all flex justify-center bg-white">
                                            <?php echo getThumbSVG($t); ?>
                                        </div>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="flex-1 flex flex-col min-h-[300px]">
                            <div class="flex items-center justify-between mb-2 ml-1">
                                <label class="block text-xs font-black text-slate-400 uppercase tracking-widest">Source Code (HTML Murni)</label>
                                <span class="text-[10px] font-bold bg-amber-100 text-amber-700 px-2 py-1 rounded border border-amber-200">Gunakan Inline CSS</span>
                            </div>
                            <!-- Note: We use a textarea for HTML input to guarantee safe pasting of complex raw HTML without browser rich-text mangling -->
                            <textarea id="layout_html" class="w-full flex-1 min-h-[400px] p-5 rounded-2xl bg-[#0f172a] text-emerald-400 font-mono text-[13px] leading-relaxed border-2 border-slate-800 focus:border-blue-500 outline-none resize-none shadow-inner overflow-y-auto whitespace-pre font-medium" placeholder="<div>Tulis struktur HTML email di sini...</div>" oninput="updatePreview()"></textarea>
                        </div>
                    </div>
                    
                    <!-- Footer Actions -->
                    <div class="p-6 border-t border-slate-100 bg-slate-50">
                        <button onclick="saveLayout()" id="btnSave" class="w-full py-4 bg-blue-600 text-white rounded-2xl font-black outfit text-lg shadow-xl shadow-blue-200 hover:bg-blue-700 hover:scale-[1.01] transition-all flex items-center justify-center gap-2">
                            <i data-lucide="save" class="w-5 h-5"></i> Simpan Layout
                        </button>
                    </div>
                </div>

                <!-- Right: Live Preview -->
                <div class="w-full md:w-1/2 flex flex-col bg-slate-100/50 relative">
                    <div class="absolute top-4 right-4 bg-white/80 backdrop-blur border border-slate-200 px-3 py-1.5 rounded-xl text-[10px] font-black text-slate-500 uppercase tracking-widest z-10 shadow-sm flex items-center gap-2">
                        <div class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></div>
                        Live Preview
                    </div>
                    <div class="flex-1 p-6 flex justify-center overflow-y-auto">
                        <div class="w-full max-w-[600px] min-h-full bg-white shadow-sm border border-slate-200 overflow-hidden relative">
                            <!-- Preview iframe -->
                            <iframe id="preview_frame" class="w-full h-full border-0"></iframe>
                            <!-- Empty State -->
                            <div id="preview_empty" class="absolute inset-0 flex flex-col items-center justify-center bg-slate-50 text-slate-300">
                                <i data-lucide="monitor" class="w-16 h-16 mb-4"></i>
                                <p class="font-bold">Ketik HTML untuk melihat pratinjau</p>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
    function updatePreview() {
        const html = document.getElementById('layout_html').value;
        const frame = document.getElementById('preview_frame');
        const empty = document.getElementById('preview_empty');
        
        if (html.trim() === '') {
            frame.style.display = 'none';
            empty.style.display = 'flex';
        } else {
            frame.style.display = 'block';
            empty.style.display = 'none';
            // Secure HTML injection into iframe
            frame.srcdoc = `<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><style>body{margin:0;padding:0;background:#ffffff;}</style></head><body>${html}</body></html>`;
        }
    }

    function openLayoutModal() {
        document.getElementById('layoutModal').classList.remove('hidden');
        document.getElementById('modalTitle').innerText = 'Buat Layout Baru';
        document.getElementById('layout_id').value = '';
        document.getElementById('layout_name').value = '';
        document.getElementById('layout_html').value = '';
        document.querySelector('input[name="thumb_type"][value="simple"]').checked = true;
        updatePreview();
    }

    function closeLayoutModal() {
        document.getElementById('layoutModal').classList.add('hidden');
    }

    function editLayout(layout) {
        document.getElementById('layoutModal').classList.remove('hidden');
        document.getElementById('modalTitle').innerText = 'Edit Layout';
        document.getElementById('layout_id').value = layout.id;
        document.getElementById('layout_name').value = layout.name;
        document.getElementById('layout_html').value = layout.html_content;
        
        const r = document.querySelector(`input[name="thumb_type"][value="${layout.thumb_type}"]`);
        if (r) r.checked = true;
        else document.querySelector('input[name="thumb_type"][value="simple"]').checked = true;
        
        updatePreview();
    }

    function saveLayout() {
        const id = document.getElementById('layout_id').value;
        const name = document.getElementById('layout_name').value.trim();
        const html = document.getElementById('layout_html').value.trim();
        const thumb = document.querySelector('input[name="thumb_type"]:checked').value;
        
        if (!name || !html) {
            Swal.fire('Peringatan', 'Nama layout dan Source Code HTML wajib diisi!', 'warning');
            return;
        }

        const btn = document.getElementById('btnSave');
        const origText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<i data-lucide="loader-2" class="w-5 h-5 animate-spin"></i> Menyimpan...`;
        lucide.createIcons();

        const fd = new FormData();
        fd.append('action', 'save');
        fd.append('id', id);
        fd.append('name', name);
        fd.append('html_content', html);
        fd.append('thumb_type', thumb);

        fetch('handlers/admin_email_layouts_handler.php', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({ title: 'Berhasil!', text: data.message, icon: 'success' }).then(() => location.reload());
                } else {
                    Swal.fire('Gagal', data.error || 'Terjadi kesalahan sistem', 'error');
                    btn.disabled = false;
                    btn.innerHTML = origText;
                    lucide.createIcons();
                }
            })
            .catch(() => {
                Swal.fire('Gagal', 'Terjadi kesalahan jaringan', 'error');
                btn.disabled = false;
                btn.innerHTML = origText;
                lucide.createIcons();
            });
    }

    function deleteLayout(id) {
        Swal.fire({
            title: 'Hapus Layout?',
            text: "Data yang dihapus tidak dapat dikembalikan!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                const fd = new FormData();
                fd.append('action', 'delete');
                fd.append('id', id);

                fetch('handlers/admin_email_layouts_handler.php', { method: 'POST', body: fd })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('Terhapus!', data.message, 'success').then(() => location.reload());
                        } else {
                            Swal.fire('Gagal', data.error || 'Terjadi kesalahan sistem', 'error');
                        }
                    })
                    .catch(() => Swal.fire('Gagal', 'Terjadi kesalahan jaringan', 'error'));
            }
        });
    }
</script>
