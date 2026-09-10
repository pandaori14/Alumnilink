<?php
// Fetch all questions
$stmt = $pdo->prepare("SELECT * FROM tracer_questions ORDER BY order_no ASC");
$stmt->execute();
$questions = $stmt->fetchAll();

// Prepare data for Javascript
$questions_json = json_encode($questions);
?>

<div class="max-w-6xl mx-auto">
    <div class="mb-8 px-1">
        <h1 class="text-xl md:text-2xl font-bold outfit text-slate-800 tracking-tight">Konfigurasi Tracer Alumni</h1>
        <p class="text-slate-400 text-xs md:text-sm mt-1 font-medium">Atur pertanyaan dan tata letak form tracer secara dinamis.</p>
    </div>
    <div class="flex flex-col md:flex-row md:items-center justify-end gap-6 mb-10 px-1">
        <button onclick="openQuestionModal()" class="bg-blue-600 text-white px-6 py-3.5 rounded-2xl font-bold shadow-lg shadow-blue-200 hover:bg-blue-700 transition-all flex items-center gap-2 w-full md:w-auto justify-center">
            <i data-lucide="plus" class="w-5 h-5"></i>
            Tambah Pertanyaan
        </button>
    </div>

    <!-- Questions List -->
    <div id="questionsContainer" class="space-y-4">
        <?php foreach ($questions as $q): ?>
            <div data-id="<?php echo e($q->id); ?>" class="glass p-6 rounded-3xl shadow-sm border border-white/20 flex items-center justify-between group transition-all hover:border-blue-200 <?php echo e(!$q->is_active ? 'opacity-70 bg-slate-50/50' : ''); ?>">
                <div class="flex items-center gap-6">
                    <div class="handle w-10 h-10 bg-slate-100 text-slate-400 rounded-xl flex items-center justify-center cursor-move hover:bg-blue-600 hover:text-white transition-all shadow-inner">
                        <i data-lucide="grip-vertical" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2 mb-1 flex-wrap">
                            <span class="text-xs font-bold px-2 py-0.5 bg-blue-100 text-blue-600 rounded-md uppercase"><?php echo e($q->question_type); ?></span>
                            <?php if ($q->mapping_key): ?>
                                <span class="text-[10px] font-bold px-2 py-0.5 bg-emerald-100 text-emerald-600 rounded-md uppercase flex items-center gap-1" title="Metrik Sistem">
                                    <i data-lucide="database" class="w-3 h-3"></i> Map: <?php echo e(str_replace('_', ' ', $q->mapping_key)); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($q->is_required): ?>
                                <span class="text-[10px] font-bold text-red-500">* Wajib</span>
                            <?php endif; ?>
                        </div>
                        <h2 class="text-lg font-bold text-slate-800 <?php echo e(!$q->is_active ? 'text-slate-400 line-through decoration-slate-300' : ''); ?>"><?php echo e($q->question_text); ?></h2>
                        <?php if ($q->options): ?>
                            <p class="text-xs text-slate-400 mt-1">Pilihan: <?php echo e(implode(', ', json_decode($q->options))); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="flex items-center gap-4">
                    <!-- Quick Toggle Switch -->
                    <div class="flex items-center gap-2" title="Aktif / Nonaktifkan Pertanyaan">
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest hidden sm:inline"><?php echo e($q->is_active ? 'Aktif' : 'Nonaktif'); ?></span>
                        <label class="relative inline-flex items-center cursor-pointer select-none">
                            <input type="checkbox" onchange="toggleQuestionStatus(<?php echo e($q->id); ?>, this.checked)" <?php echo e($q->is_active ? 'checked' : ''); ?> class="sr-only peer">
                            <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                        </label>
                    </div>

                    <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-all">
                        <?php if(!empty($q->depends_on_question_id)): ?>
                            <?php 
                                $dep_vals = json_decode($q->depends_on_option_value, true); 
                                $dep_title = is_array($dep_vals) ? implode(', ', $dep_vals) : htmlspecialchars($q->depends_on_option_value);
                            ?>
                            <div class="mr-2 px-2 py-1 bg-amber-50 border border-amber-200 text-amber-600 rounded-lg text-[10px] font-bold flex items-center gap-1" title="Tampil jika Q#<?php echo e($q->depends_on_question_id); ?> = <?php echo e($dep_title); ?>">
                                <i data-lucide="git-branch" class="w-3 h-3"></i> Kondisional
                            </div>
                        <?php endif; ?>
                        <button onclick="editQuestion(<?php echo htmlspecialchars(json_encode($q)); ?>)" class="p-2 bg-blue-50 text-blue-600 rounded-xl hover:bg-blue-600 hover:text-white transition-all">
                            <i data-lucide="edit-2" class="w-4 h-4"></i>
                        </button>
                        <a href="handlers/admin_tracer_handler.php?action=delete&id=<?php echo e($q->id); ?>" onclick="confirmDelete(event, this.href, 'Hapus pertanyaan ini?')" class="p-2 bg-red-50 text-red-600 rounded-xl hover:bg-red-600 hover:text-white transition-all">
                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- SortableJS Library -->
<script src="assets/js/sortable.min.js"></script>

<!-- Question Modal -->
<div id="questionModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm hidden">
    <div class="glass w-full max-w-xl p-8 rounded-[2.5rem] shadow-2xl relative">
        <button onclick="document.getElementById('questionModal').classList.add('hidden')" class="absolute top-6 right-6 text-slate-400 hover:text-slate-600">
            <i data-lucide="x" class="w-6 h-6"></i>
        </button>
        
        <h2 id="modalTitle" class="text-2xl font-bold outfit mb-6">Tambah Pertanyaan</h2>
        
        <form action="handlers/admin_tracer_handler.php?action=save" method="POST" class="space-y-6">
            <?php csrf_field(); ?>
            <input type="hidden" name="id" id="q_id">
            
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2 ml-1">Teks Pertanyaan</label>
                <input aria-label="Contoh: Apa pekerjaan Anda saat ini?" type="text" name="question_text" id="q_text" required class="w-full px-5 py-3.5 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none" placeholder="Contoh: Apa pekerjaan Anda saat ini?">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2 ml-1">Tipe Input</label>
                    <select aria-label="Tipe pertanyaan" name="question_type" id="q_type" onchange="toggleOptions()" class="w-full px-5 py-3.5 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none appearance-none">
                        <option value="text">Teks Singkat</option>
                        <option value="textarea">Teks Panjang</option>
                        <option value="radio">Pilihan (Radio)</option>
                        <option value="select">Pilihan (Dropdown)</option>
                        <option value="checkbox">Pilihan Ganda (Checkbox)</option>
                        <option value="date">Tanggal</option>
                        <option value="rating">Skala Penilaian (Rating 1-5)</option>
                        <option value="number">Angka</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2 ml-1">Urutan (Manual)</label>
                    <input aria-label="Urutan tampil" type="number" name="order_no" id="q_order" value="1" class="w-full px-5 py-3.5 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none">
                </div>
            </div>

            <div id="optionsContainer" class="hidden">
                <label class="block text-sm font-semibold text-slate-700 mb-2 ml-1">Pilihan (Pisahkan dengan koma)</label>
                <textarea aria-label="Contoh: Ya, Tidak, Mungkin" name="options" id="q_options" class="w-full px-5 py-3.5 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none" placeholder="Contoh: Ya, Tidak, Mungkin"></textarea>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="flex items-center gap-3 ml-1">
                    <input type="checkbox" name="is_required" id="q_required" value="1" checked class="w-5 h-5 accent-blue-600">
                    <label class="text-sm font-semibold text-slate-700">Wajib Diisi</label>
                </div>
                <div class="flex items-center gap-3 ml-1">
                    <input type="checkbox" name="is_active" id="q_active" value="1" checked class="w-5 h-5 accent-blue-600">
                    <label class="text-sm font-semibold text-slate-700">Status Aktif</label>
                </div>
            </div>

            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2 ml-1 font-bold flex items-center gap-2">
                    <i data-lucide="database" class="w-4 h-4 text-emerald-500"></i> Pemetaan Metrik Sistem (Untuk Filter & Laporan)
                </label>
                <select aria-label="-- Tidak Dipetakan (Pertanyaan Tambahan) --" name="mapping_key" id="q_mapping" class="w-full px-5 py-3.5 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none appearance-none text-sm font-semibold text-slate-700 cursor-pointer">
                    <option value="">-- Tidak Dipetakan (Pertanyaan Tambahan) --</option>
                    <option value="work_status">Status Pekerjaan (work_status)</option>
                    <option value="company_name">Nama Perusahaan (company_name)</option>
                    <option value="job_title">Posisi / Jabatan (job_title)</option>
                    <option value="salary_range">Rentang Gaji (salary_range)</option>
                    <option value="field_relevance">Relevansi Bidang Ilmu (field_relevance)</option>
                </select>
                <p class="text-[10px] text-slate-400 mt-2 ml-1 leading-relaxed">Penting: Setiap metrik hanya boleh dipetakan ke 1 pertanyaan. Pemetaan metrik yang sama pada pertanyaan lain akan dikosongkan otomatis.</p>
            </div>
            
            <!-- Logika Kondisional (Skip Logic) -->
            <div class="pt-4 border-t border-slate-100">
                <label class="block text-sm font-bold text-slate-700 mb-4 flex items-center gap-2">
                    <i data-lucide="git-branch" class="w-4 h-4 text-blue-500"></i> Logika Tampil (Opsional)
                </label>
                <div class="space-y-4">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-500 mb-1.5 ml-1">Tampil jika menjawab pertanyaan:</label>
                        <select aria-label="-- Selalu Tampil (Tanpa Syarat) --" name="depends_on_question_id" id="q_depends_id" onchange="updateDependencyOptions()" class="w-full px-5 py-3.5 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none appearance-none text-sm font-medium cursor-pointer">
                            <option value="">-- Selalu Tampil (Tanpa Syarat) --</option>
                        </select>
                    </div>
                    <div id="dependsValueContainer" class="hidden">
                        <label class="block text-[11px] font-bold text-slate-500 mb-2 ml-1">Dengan jawaban secara spesifik (Bisa pilih lebih dari 1):</label>
                        <div id="q_depends_val_wrapper" class="bg-slate-50 border border-slate-200 rounded-2xl p-4 max-h-40 overflow-y-auto space-y-2">
                            <!-- Checkboxes will be generated here -->
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="w-full py-4 bg-blue-600 text-white rounded-2xl font-bold shadow-lg shadow-blue-200 hover:bg-blue-700 transition-all">
                Simpan Pertanyaan
            </button>
        </form>
    </div>
</div>

<script>
    // Dikirim sebagai header X-CSRF-Token pada setiap fetch() di halaman ini,
    // karena permintaan ber-body JSON tidak pernah mengisi $_POST di sisi PHP.
    const CSRF_TOKEN = <?php echo json_encode(get_csrf_token()); ?>;

    const allQuestions = <?php echo e($questions_json); ?>;

    // Initialize Sortable
    const el = document.getElementById('questionsContainer');
    if (el) {
        Sortable.create(el, {
            animation: 150,
            handle: '.handle',
            ghostClass: 'bg-blue-50',
            onEnd: function() {
                const order = [];
                el.querySelectorAll('[data-id]').forEach(item => {
                    order.push(item.getAttribute('data-id'));
                });

                // Send to server
                fetch('handlers/admin_tracer_reorder.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: JSON.stringify({ order: order })
                })
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        showSwalAlert('Gagal Menyimpan', 'Gagal menyimpan urutan baru: ' + (data.message || 'Error tidak diketahui'), 'error');
                    }
                })
                .catch(err => {
                    console.error('Reorder error:', err);
                    showSwalAlert('Kesalahan Koneksi', 'Terjadi kesalahan koneksi saat menyimpan urutan.', 'error');
                });
            }
        });
    }

    function toggleQuestionStatus(id, isChecked) {
        const status = isChecked ? 1 : 0;
        fetch('handlers/admin_tracer_toggle.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN
            },
            body: JSON.stringify({ id: id, is_active: status })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const card = document.querySelector(`[data-id="${id}"]`);
                const textEl = card.querySelector('h2');
                const badgeEl = card.querySelector('.tracking-widest');
                if (status === 0) {
                    card.classList.add('opacity-70', 'bg-slate-50/50');
                    textEl.classList.add('text-slate-400', 'line-through', 'decoration-slate-300');
                    if (badgeEl) badgeEl.textContent = 'Nonaktif';
                } else {
                    card.classList.remove('opacity-70', 'bg-slate-50/50');
                    textEl.classList.remove('text-slate-400', 'line-through', 'decoration-slate-300');
                    if (badgeEl) badgeEl.textContent = 'Aktif';
                }
                
                // Show a quick success toast
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil',
                        text: 'Status keaktifan pertanyaan berhasil diperbarui.',
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 3000
                    });
                }
            } else {
                if (typeof Swal !== 'undefined') {
                    Swal.fire('Gagal', data.message || 'Gagal mengubah status', 'error');
                } else {
                    showSwalAlert('Gagal', data.message || 'Gagal mengubah status pertanyaan.', 'error');
                }
                const cb = document.querySelector(`[data-id="${id}"] input[type="checkbox"]`);
                if (cb) cb.checked = !isChecked;
            }
        })
        .catch(err => {
            console.error(err);
            if (typeof Swal !== 'undefined') {
                Swal.fire('Kesalahan', 'Terjadi kesalahan koneksi internet.', 'error');
            } else {
                showSwalAlert('Kesalahan Koneksi', 'Terjadi kesalahan koneksi internet.', 'error');
            }
            const cb = document.querySelector(`[data-id="${id}"] input[type="checkbox"]`);
            if (cb) cb.checked = !isChecked;
        });
    }

    function openQuestionModal() {
        document.getElementById('modalTitle').innerText = 'Tambah Pertanyaan';
        document.getElementById('q_id').value = '';
        document.getElementById('q_text').value = '';
        document.getElementById('q_type').value = 'text';
        document.getElementById('q_options').value = '';
        document.getElementById('q_order').value = document.querySelectorAll('#questionsContainer > div').length + 1;
        document.getElementById('q_required').checked = true;
        document.getElementById('q_active').checked = true;
        document.getElementById('q_mapping').value = '';
        toggleOptions();
        document.getElementById('questionModal').classList.remove('hidden');
    }

    function editQuestion(q) {
        document.getElementById('modalTitle').innerText = 'Edit Pertanyaan';
        document.getElementById('q_id').value = q.id;
        document.getElementById('q_text').value = q.question_text;
        document.getElementById('q_type').value = q.question_type;
        
        // Robust options handling
        let optionsArr = [];
        try {
            if (q.options) {
                optionsArr = typeof q.options === 'string' ? JSON.parse(q.options) : q.options;
            }
        } catch (e) {
            console.error('Options parse error:', e);
            optionsArr = [];
        }
        document.getElementById('q_options').value = Array.isArray(optionsArr) ? optionsArr.join(', ') : '';
        
        document.getElementById('q_order').value = q.order_no;
        document.getElementById('q_required').checked = q.is_required == 1;
        document.getElementById('q_active').checked = q.is_active == 1;
        document.getElementById('q_mapping').value = q.mapping_key || '';
        toggleOptions();
        
        // Populate dependency logic
        populateDependencyDropdown(q.id);
        document.getElementById('q_depends_id').value = q.depends_on_question_id || '';
        updateDependencyOptions();
        
        if (q.depends_on_question_id && q.depends_on_option_value) {
            let selectedVals = [];
            try {
                selectedVals = JSON.parse(q.depends_on_option_value);
                if (!Array.isArray(selectedVals)) selectedVals = [selectedVals];
            } catch(e) {
                selectedVals = [q.depends_on_option_value];
            }
            
            const checkboxes = document.querySelectorAll('input[name="depends_on_option_value[]"]');
            checkboxes.forEach(cb => {
                if (selectedVals.includes(cb.value)) {
                    cb.checked = true;
                }
            });
        }

        document.getElementById('questionModal').classList.remove('hidden');
    }

    function toggleOptions() {
        const type = document.getElementById('q_type').value;
        const container = document.getElementById('optionsContainer');
        if (type === 'radio' || type === 'select' || type === 'checkbox') {
            container.classList.remove('hidden');
        } else {
            container.classList.add('hidden');
        }
    }

    function populateDependencyDropdown(currentId = null) {
        const select = document.getElementById('q_depends_id');
        select.innerHTML = '<option value="">-- Selalu Tampil (Tanpa Syarat) --</option>';
        
        allQuestions.forEach(q => {
            // Can only depend on previous questions that have options (radio, select)
            // Cannot depend on itself
            if (q.id != currentId && (q.question_type === 'radio' || q.question_type === 'select')) {
                const opt = document.createElement('option');
                opt.value = q.id;
                // Truncate text for display
                let text = q.question_text.length > 50 ? q.question_text.substring(0, 50) + '...' : q.question_text;
                opt.text = `[ID: ${q.id}] ${text}`;
                select.appendChild(opt);
            }
        });
    }

    function updateDependencyOptions() {
        const parentId = document.getElementById('q_depends_id').value;
        const valContainer = document.getElementById('dependsValueContainer');
        const wrapper = document.getElementById('q_depends_val_wrapper');
        
        wrapper.innerHTML = '';
        
        if (!parentId) {
            valContainer.classList.add('hidden');
            return;
        }

        const parentQ = allQuestions.find(q => q.id == parentId);
        if (parentQ && parentQ.options) {
            let optionsArr = [];
            try {
                optionsArr = typeof parentQ.options === 'string' ? JSON.parse(parentQ.options) : parentQ.options;
            } catch(e) {}
            
            if (Array.isArray(optionsArr)) {
                optionsArr.forEach((opt, idx) => {
                    const id = `dep_opt_${idx}`;
                    wrapper.innerHTML += `
                        <label class="flex items-center gap-3 cursor-pointer group">
                            <input type="checkbox" name="depends_on_option_value[]" value="${opt.replace(/"/g, '&quot;')}" class="w-4 h-4 text-blue-600 bg-white border-slate-300 rounded focus:ring-blue-500">
                            <span class="text-sm text-slate-700 font-medium group-hover:text-blue-600 transition-colors">${opt}</span>
                        </label>
                    `;
                });
                valContainer.classList.remove('hidden');
                return;
            }
        }
        valContainer.classList.add('hidden');
    }
    
    // Override openQuestionModal to populate dropdown for new question
    const originalOpenModal = openQuestionModal;
    openQuestionModal = function() {
        populateDependencyDropdown(null);
        document.getElementById('q_depends_id').value = '';
        updateDependencyOptions();
        originalOpenModal();
    }
</script>
