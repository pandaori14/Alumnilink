<?php
// Fetch all majors
$stmt = $pdo->query("SELECT * FROM majors ORDER BY major_name ASC");
$majors_list = $stmt->fetchAll();
?>

<div class="max-w-6xl mx-auto">
    <div class="mb-8 px-1 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <h1 class="text-xl md:text-2xl font-bold outfit text-slate-800 tracking-tight">Kelola Program Studi (Prodi)</h1>
            <p class="text-slate-400 text-xs md:text-sm mt-1 font-medium">Database resmi program studi untuk dropdown pilihan alumni dan admin.</p>
        </div>
        <button onclick="openMajorModal()" class="bg-blue-600 text-white px-6 py-3.5 rounded-2xl font-bold shadow-lg shadow-blue-200 hover:bg-blue-700 transition-all flex items-center gap-2 shrink-0">
            <i data-lucide="plus-circle" class="w-5 h-5"></i>
            Tambah Prodi
        </button>
    </div>

    <!-- Alert Messages -->
    <?php if (isset($_GET['success'])): ?>
        <div class="p-5 mb-8 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl flex items-center gap-3 shadow-sm animate-fade">
            <i data-lucide="check-circle" class="w-6 h-6 text-emerald-600 shrink-0"></i>
            <span class="text-sm font-semibold">Data program studi berhasil diperbarui!</span>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="p-5 mb-8 bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl flex items-center gap-3 shadow-sm animate-fade">
            <i data-lucide="alert-circle" class="w-6 h-6 text-rose-600 shrink-0"></i>
            <span class="text-sm font-semibold">Terjadi kesalahan saat memproses data. Kode prodi mungkin sudah digunakan.</span>
        </div>
    <?php endif; ?>

    <!-- Majors Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-12">
        <?php if (empty($majors_list)): ?>
            <div class="col-span-full glass p-20 rounded-[3rem] text-center">
                <div class="w-20 h-20 bg-slate-100 text-slate-300 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i data-lucide="book-x" class="w-10 h-10"></i>
                </div>
                <h4 class="text-xl font-bold text-slate-400">Belum ada data Program Studi</h4>
                <p class="text-slate-400 mt-2">Klik tombol Tambah Prodi di atas untuk memasukkan data baru.</p>
            </div>
        <?php else: ?>
            <?php foreach ($majors_list as $m): ?>
                <div class="glass p-8 rounded-[2.5rem] shadow-sm hover:shadow-xl hover:shadow-blue-900/5 transition-all group relative flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between mb-6">
                            <span class="px-4 py-1.5 bg-blue-50 text-blue-600 rounded-2xl text-xs font-black outfit tracking-wider border border-blue-100/50 shadow-inner">
                                <?php echo htmlspecialchars($m->major_code); ?>
                            </span>
                            <span class="px-3 py-1 bg-emerald-100 text-emerald-700 rounded-full text-[10px] font-extrabold uppercase tracking-widest shadow-sm">
                                Akreditasi <?php echo htmlspecialchars($m->accreditation); ?>
                            </span>
                        </div>

                        <h4 class="text-xl font-bold outfit text-slate-800 mb-2 leading-snug"><?php echo htmlspecialchars($m->major_name); ?></h4>
                        <p class="text-xs text-slate-500 font-medium flex items-center gap-2 mb-6">
                            <i data-lucide="building-2" class="w-4 h-4 text-slate-400 shrink-0"></i>
                            <?php echo htmlspecialchars($m->faculty); ?>
                        </p>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-6 border-t border-slate-100 mt-4">
                        <button onclick='editMajor(<?php echo e(json_encode($m)); ?>)' class="px-4 py-2.5 bg-slate-50 hover:bg-blue-600 text-slate-500 hover:text-white rounded-xl text-xs font-bold transition-all flex items-center gap-2 shadow-sm">
                            <i data-lucide="edit-3" class="w-4 h-4"></i> Edit
                        </button>
                        <button onclick='deleteMajor(<?php echo e($m->id); ?>, <?php echo e(json_encode($m->major_name)); ?>)' class="px-4 py-2.5 bg-slate-50 hover:bg-rose-600 text-slate-500 hover:text-white rounded-xl text-xs font-bold transition-all flex items-center gap-2 shadow-sm">
                            <i data-lucide="trash-2" class="w-4 h-4"></i> Hapus
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Major CRUD Modal -->
<div id="majorModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm hidden animate-fade">
    <div class="glass w-full max-w-xl p-10 rounded-[3rem] shadow-2xl relative max-h-[95vh] overflow-y-auto custom-scrollbar">
        <button onclick="document.getElementById('majorModal').classList.add('hidden')" class="absolute top-8 right-8 text-slate-400 hover:text-slate-600">
            <i data-lucide="x" class="w-6 h-6"></i>
        </button>
        
        <h3 id="modalTitle" class="text-2xl font-bold outfit mb-8 tracking-tight">Tambah Program Studi</h3>
        
        <form action="handlers/admin_major_handler.php?action=save" method="POST" class="space-y-6">
            <?php csrf_field(); ?>
            <input type="hidden" name="id" id="m_id">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2 ml-1">Kode Prodi <span class="text-rose-500">*</span></label>
                    <input aria-label="Contoh: L200" type="text" name="major_code" id="m_code" required placeholder="Contoh: L200" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none font-semibold text-sm transition-all shadow-sm">
                    <p class="text-[10px] text-slate-400 mt-1.5 ml-1">Kode unik (misal: L200 untuk Informatika).</p>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2 ml-1">Akreditasi <span class="text-rose-500">*</span></label>
                    <select aria-label="Peringkat akreditasi" name="accreditation" id="m_accreditation" required class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none font-semibold text-sm transition-all shadow-sm appearance-none cursor-pointer">
                        <option value="Unggul">Unggul</option>
                        <option value="A">A</option>
                        <option value="B">B</option>
                        <option value="C">C</option>
                        <option value="Baik Sekali">Baik Sekali</option>
                        <option value="Baik">Baik</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2 ml-1">Nama Program Studi <span class="text-rose-500">*</span></label>
                <input aria-label="Contoh: S1 Informatika" type="text" name="major_name" id="m_name" required placeholder="Contoh: S1 Informatika" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none font-semibold text-sm transition-all shadow-sm">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2 ml-1">Nama Fakultas <span class="text-rose-500">*</span></label>
                <input aria-label="Contoh: Fakultas Komunikasi dan Informatika" type="text" name="faculty" id="m_faculty" required placeholder="Contoh: Fakultas Komunikasi dan Informatika" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none font-semibold text-sm transition-all shadow-sm">
            </div>
            
            <button type="submit" class="w-full py-4 bg-blue-600 text-white rounded-2xl font-bold shadow-lg shadow-blue-200 hover:bg-blue-700 active:scale-95 transition-all mt-4">
                Simpan Program Studi
            </button>
        </form>
    </div>
</div>

<!-- Major Delete Modal -->
<div id="deleteModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm hidden animate-fade">
    <div class="glass w-full max-w-md p-8 rounded-[2.5rem] shadow-2xl relative text-center">
        <div class="w-16 h-16 bg-rose-100 text-rose-600 rounded-full flex items-center justify-center mx-auto mb-6 shadow-inner">
            <i data-lucide="alert-triangle" class="w-8 h-8"></i>
        </div>
        <h3 class="text-xl font-black outfit mb-2 tracking-tight">Hapus Program Studi?</h3>
        <p class="text-xs text-slate-500 mb-8 leading-relaxed">Apakah Anda yakin ingin menghapus <span id="delMajorName" class="font-bold text-slate-700"></span>? Data ini tidak dapat dikembalikan.</p>
        
        <div class="flex gap-4">
            <button onclick="document.getElementById('deleteModal').classList.add('hidden')" class="flex-1 py-3.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-2xl font-bold text-sm transition-all">
                Batal
            </button>
            <a id="confirmDeleteBtn" href="#" class="flex-1 py-3.5 bg-rose-600 hover:bg-rose-700 text-white rounded-2xl font-bold text-sm shadow-lg shadow-rose-200 transition-all flex items-center justify-center">
                Hapus
            </a>
        </div>
    </div>
</div>

<script>
function openMajorModal() {
    document.getElementById('modalTitle').innerText = 'Tambah Program Studi';
    document.getElementById('m_id').value = '';
    document.getElementById('m_code').value = '';
    document.getElementById('m_name').value = '';
    document.getElementById('m_faculty').value = '';
    document.getElementById('m_accreditation').value = 'Unggul';
    document.getElementById('majorModal').classList.remove('hidden');
}

function editMajor(m) {
    document.getElementById('modalTitle').innerText = 'Edit Program Studi';
    document.getElementById('m_id').value = m.id;
    document.getElementById('m_code').value = m.major_code;
    document.getElementById('m_name').value = m.major_name;
    document.getElementById('m_faculty').value = m.faculty;
    document.getElementById('m_accreditation').value = m.accreditation;
    document.getElementById('majorModal').classList.remove('hidden');
}

function deleteMajor(id, name) {
    document.getElementById('delMajorName').innerText = name;
    document.getElementById('confirmDeleteBtn').href = 'handlers/admin_major_handler.php?action=delete&id=' + id;
    document.getElementById('deleteModal').classList.remove('hidden');
}
</script>
