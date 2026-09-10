<?php
$user_id = $_SESSION['user_id'];

// Fetch user data
$stmt = $pdo->prepare("SELECT is_verified, major, graduation_year, phone, address FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_data = $stmt->fetch();
$is_verified = (bool)($user_data->is_verified ?? false);
$user_major  = trim($user_data->major ?? '');
$user_year   = trim($user_data->graduation_year ?? '');
$user_phone  = trim($user_data->phone ?? '');
$user_address = trim($user_data->address ?? '');

// Fetch dynamic questions (only active ones)
$stmt = $pdo->prepare("SELECT * FROM tracer_questions WHERE is_active = 1 ORDER BY order_no ASC");
$stmt->execute();
$questions = $stmt->fetchAll();

// Fetch majors from database
$db_majors = $pdo->query("SELECT major_code, major_name FROM majors ORDER BY major_name ASC")->fetchAll();
?>

<div class="max-w-4xl mx-auto px-4 pb-20">
    <div class="mb-10 text-center">
        <h1 class="text-3xl font-black outfit text-slate-800">Tracer Alumni</h1>
        <p class="text-slate-500 mt-2 font-medium">Bantu almamater dengan memperbarui profil profesional Anda.</p>
    </div>

    <?php if (!$is_verified): ?>
        <div class="glass p-10 rounded-[3rem] border border-white text-center">
            <div class="w-20 h-20 bg-amber-100 text-amber-600 rounded-full flex items-center justify-center mx-auto mb-6">
                <i data-lucide="shield-alert" class="w-10 h-10"></i>
            </div>
            <h4 class="text-2xl font-bold outfit text-slate-800 mb-2">Verifikasi Diperlukan</h4>
            <p class="text-slate-500 mb-8 max-w-sm mx-auto leading-relaxed">Admin sedang meninjau data Anda. Form ini akan terbuka otomatis setelah akun Anda terverifikasi.</p>
            <div class="inline-flex items-center gap-2 px-6 py-3 bg-slate-100 text-slate-500 rounded-2xl text-xs font-bold">
                <span class="w-2 h-2 bg-amber-500 rounded-full animate-pulse"></span>
                Status: Menunggu Verifikasi
            </div>
        </div>
    <?php elseif (empty($questions)): ?>
        <div class="glass p-12 rounded-[3rem] border border-white text-center">
            <div class="w-20 h-20 bg-slate-50 text-slate-200 rounded-full flex items-center justify-center mx-auto mb-6">
                <i data-lucide="clipboard-x" class="w-10 h-10"></i>
            </div>
            <h4 class="text-xl font-bold outfit text-slate-400">Belum Ada Pertanyaan</h4>
            <p class="text-slate-400 text-sm mt-1">Silakan hubungi admin untuk mengaktifkan kuesioner.</p>
        </div>
    <?php else: ?>
        <form action="handlers/tracer_handler.php" method="POST" class="space-y-8">
            <?php csrf_field(); ?>
            <?php if (empty($user_major) || empty($user_year) || empty($user_phone) || empty($user_address)): ?>
            <div class="glass p-8 md:p-10 rounded-[2.5rem] border border-rose-100 shadow-sm mb-8 bg-gradient-to-br from-rose-50/50 to-orange-50/30 relative overflow-hidden">
                <div class="absolute top-0 right-0 w-32 h-32 bg-rose-500/5 rounded-bl-full pointer-events-none"></div>
                <div class="flex items-center gap-4 mb-6 relative">
                    <div class="w-12 h-12 bg-rose-500 text-white rounded-2xl flex items-center justify-center shadow-lg shadow-rose-200 shrink-0">
                        <i data-lucide="graduation-cap" class="w-6 h-6 animate-bounce"></i>
                    </div>
                    <div>
                        <h3 class="text-xl font-black outfit text-rose-800 tracking-tight">Lengkapi Data Akademik</h3>
                        <p class="text-xs text-rose-600/80 font-bold mt-0.5 leading-relaxed">Anda wajib mengisi Program Studi, Tahun Lulus, Nomor HP, dan Alamat Domisili untuk keperluan verifikasi akreditasi dan pengiriman dokumen legalisisasi dokumen jika diperlukan.</p>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 relative z-10 mb-6">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2" for="f_tracer_major">Program Studi <span class="text-rose-500">*</span></label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <i data-lucide="book-open" class="w-5 h-5 text-slate-400"></i>
                            </div>
                            <select id="f_tracer_major" name="tracer_major" required class="w-full pl-12 pr-10 py-4 rounded-2xl bg-white border border-rose-200 focus:border-rose-500 focus:ring-4 focus:ring-rose-500/20 transition-all outline-none font-semibold text-sm shadow-sm appearance-none cursor-pointer">
                                <option value="">-- Pilih Program Studi --</option>
                                <?php foreach ($db_majors as $m): ?>
                                    <option value="<?php echo htmlspecialchars($m->major_code); ?>" <?php echo e($user_major === $m->major_code ? 'selected' : ''); ?>><?php echo htmlspecialchars($m->major_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none">
                                <i data-lucide="chevron-down" class="w-5 h-5 text-slate-400"></i>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2" for="f_tracer_graduation_year">Tahun Lulus <span class="text-rose-500">*</span></label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <i data-lucide="calendar" class="w-5 h-5 text-slate-400"></i>
                            </div>
                            <input id="f_tracer_graduation_year" type="number" name="tracer_graduation_year" value="<?php echo htmlspecialchars($user_year); ?>" required placeholder="Contoh: 2022" min="1950" max="2030" class="w-full pl-12 pr-6 py-4 rounded-2xl bg-white border border-rose-200 focus:border-rose-500 focus:ring-4 focus:ring-rose-500/20 transition-all outline-none font-semibold text-sm shadow-sm">
                        </div>
                    </div>
                </div>
                <div class="space-y-6 relative z-10">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2" for="f_tracer_phone">Nomor Handphone / WhatsApp <span class="text-rose-500">*</span></label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <i data-lucide="phone" class="w-5 h-5 text-slate-400"></i>
                            </div>
                            <input id="f_tracer_phone" type="text" name="tracer_phone" value="<?php echo htmlspecialchars($user_phone); ?>" required placeholder="Contoh: +6281234567890" class="w-full pl-12 pr-6 py-4 rounded-2xl bg-white border border-rose-200 focus:border-rose-500 focus:ring-4 focus:ring-rose-500/20 transition-all outline-none font-semibold text-sm shadow-sm">
                        </div>
                        <p class="text-[10px] text-rose-600 mt-1.5 ml-1 italic font-semibold">Wajib menggunakan kode negara +62 (bukan awalan 0) agar terhubung langsung dengan tautan WhatsApp.</p>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2" for="f_tracer_address">Alamat Domisili <span class="text-rose-500">*</span></label>
                        <div class="relative">
                            <div class="absolute top-4 left-0 pl-4 flex items-start pointer-events-none">
                                <i data-lucide="map-pin" class="w-5 h-5 text-slate-400"></i>
                            </div>
                            <textarea id="f_tracer_address" name="tracer_address" required placeholder="Tuliskan alamat lengkap (Jalan, RT/RW, Kelurahan, Kecamatan, Kota, Kode Pos)..." class="w-full pl-12 pr-6 py-4 rounded-2xl bg-white border border-rose-200 focus:border-rose-500 focus:ring-4 focus:ring-rose-500/20 transition-all outline-none h-28 font-semibold text-sm shadow-sm"><?php echo htmlspecialchars($user_address); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="glass p-6 md:px-8 md:py-6 rounded-[2.5rem] border border-emerald-100 shadow-sm mb-8 bg-gradient-to-br from-emerald-50/50 to-teal-50/30 flex flex-col md:flex-row items-center justify-between gap-6 relative overflow-hidden">
                <div class="absolute -right-10 -top-10 w-40 h-40 bg-emerald-500/5 rounded-full blur-2xl pointer-events-none"></div>
                
                <!-- Hidden inputs to submit existing values so the handler doesn't fail -->
                <input type="hidden" name="tracer_major" value="<?php echo htmlspecialchars($user_major); ?>">
                <input type="hidden" name="tracer_graduation_year" value="<?php echo htmlspecialchars($user_year); ?>">
                <input type="hidden" name="tracer_phone" value="<?php echo htmlspecialchars($user_phone); ?>">
                <input type="hidden" name="tracer_address" value="<?php echo htmlspecialchars($user_address); ?>">
                
                <div class="flex items-center gap-4 relative z-10 w-full md:w-auto">
                    <div class="w-12 h-12 bg-emerald-100 text-emerald-600 rounded-2xl flex items-center justify-center shrink-0 border border-emerald-200/50 shadow-inner">
                        <i data-lucide="check-circle" class="w-6 h-6"></i>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-sm font-black outfit text-emerald-800 tracking-tight">Data Akademik & Kontak Pengiriman Lengkap</h3>
                        <p class="text-xs text-emerald-700/80 font-bold mt-1 leading-relaxed">
                            Prodi: <span class="text-emerald-900"><?php echo htmlspecialchars($user_major); ?> (<?php echo htmlspecialchars($user_year); ?>)</span> &bull; 
                            WA: <span class="text-emerald-900"><?php echo htmlspecialchars($user_phone); ?></span><br>
                            Alamat: <span class="text-emerald-900"><?php echo htmlspecialchars(substr($user_address, 0, 50)) . (strlen($user_address) > 50 ? '...' : ''); ?></span>
                        </p>
                        <p class="text-[10px] text-emerald-600 mt-1 italic">Pastikan alamat di atas aktif untuk pengiriman ekspedisi. Jika ada perubahan, silakan perbarui di Profil.</p>
                    </div>
                </div>
                <a href="?page=profile" class="w-full md:w-auto px-5 py-3 bg-white text-emerald-700 border border-emerald-200 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-emerald-50 transition-all shrink-0 shadow-sm text-center relative z-10">
                    Perbarui Profil
                </a>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 gap-8" id="tracerFormContainer">
                <?php foreach ($questions as $index => $q): ?>
                    <?php
                        $dep_attr = "";
                        if (!empty($q->depends_on_question_id)) {
                            $dep_attr = 'data-depends-on="' . $q->depends_on_question_id . '" data-depends-value="' . htmlspecialchars($q->depends_on_option_value) . '"';
                        }
                    ?>
                    <div class="glass p-8 md:p-10 rounded-[2.5rem] border border-white shadow-sm hover:shadow-md transition-all group question-container" id="qcontainer_<?php echo e($q->id); ?>" <?php echo e($dep_attr); ?>>
                        <div class="flex items-start gap-4 mb-8">
                            <div class="w-10 h-10 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center font-black outfit shrink-0 dynamic-question-number">
                                <?php echo $index + 1; ?>
                            </div>
                            <label class="text-lg font-bold text-slate-800 leading-tight">
                                <?php echo e($q->question_text); ?>
                                <?php if ($q->is_required): ?><span class="text-red-500 ml-1">*</span><?php endif; ?>
                            </label>
                        </div>

                        <?php if ($q->question_type == 'radio'): ?>
                            <?php $opts = json_decode($q->options); ?>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <?php foreach ($opts as $opt): ?>
                                    <label class="relative flex items-center p-5 rounded-2xl border-2 border-slate-50 bg-white/40 hover:bg-blue-50/50 cursor-pointer transition-all has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50 has-[:checked]:ring-4 has-[:checked]:ring-blue-100/50 group/opt">
                                        <input type="radio" name="q_<?php echo e($q->id); ?>" value="<?php echo e($opt); ?>" <?php echo e($q->is_required ? 'required' : ''); ?> class="peer absolute opacity-0">
                                        <div class="w-5 h-5 rounded-full border-2 border-slate-200 flex items-center justify-center peer-checked:border-blue-600 peer-checked:bg-blue-600 mr-3 transition-all shrink-0">
                                            <div class="w-2 h-2 bg-white rounded-full"></div>
                                        </div>
                                        <span class="text-sm font-bold text-slate-600 peer-checked:text-blue-700 transition-colors"><?php echo e($opt); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                        <?php elseif ($q->question_type == 'select'): ?>
                            <?php $opts = json_decode($q->options); ?>
                            <div class="relative">
                                <select aria-label="Pilih Opsi" name="q_<?php echo e($q->id); ?>" <?php echo e($q->is_required ? 'required' : ''); ?> class="w-full px-6 py-5 rounded-2xl bg-white/50 border-2 border-slate-50 focus:border-blue-500 focus:bg-white outline-none appearance-none font-bold text-slate-700 transition-all cursor-pointer pr-12">
                                    <option value="">Pilih Opsi</option>
                                    <?php foreach ($opts as $opt): ?>
                                        <option value="<?php echo e($opt); ?>"><?php echo e($opt); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <i data-lucide="chevron-down" class="absolute right-5 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400 pointer-events-none"></i>
                            </div>

                        <?php elseif ($q->question_type == 'checkbox'): ?>
                            <?php $opts = json_decode($q->options); ?>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <?php foreach ($opts as $opt): ?>
                                    <label class="relative flex items-center p-5 rounded-2xl border-2 border-slate-50 bg-white/40 hover:bg-blue-50/50 cursor-pointer transition-all has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50 has-[:checked]:ring-4 has-[:checked]:ring-blue-100/50 group/opt">
                                        <input type="checkbox" name="q_<?php echo e($q->id); ?>[]" value="<?php echo e($opt); ?>" class="peer absolute opacity-0">
                                        <div class="w-5 h-5 rounded-lg border-2 border-slate-200 flex items-center justify-center peer-checked:border-blue-600 peer-checked:bg-blue-600 mr-3 transition-all shrink-0 text-white">
                                            <i data-lucide="check" class="w-3 h-3 opacity-0 peer-checked:opacity-100 transition-opacity"></i>
                                        </div>
                                        <span class="text-sm font-bold text-slate-600 peer-checked:text-blue-700 transition-colors"><?php echo e($opt); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                        <?php elseif ($q->question_type == 'date'): ?>
                            <div class="relative">
                                <input type="date" name="q_<?php echo e($q->id); ?>" <?php echo e($q->is_required ? 'required' : ''); ?> class="w-full pl-14 pr-6 py-5 rounded-2xl bg-white/50 border-2 border-slate-50 focus:border-blue-500 focus:bg-white outline-none font-bold text-slate-700 transition-all cursor-pointer">
                                <i data-lucide="calendar" class="absolute left-6 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400 pointer-events-none"></i>
                            </div>

                        <?php elseif ($q->question_type == 'rating'): ?>
                            <div class="flex items-center gap-4 bg-white/50 p-6 rounded-2xl border-2 border-slate-50 justify-around sm:justify-start sm:gap-8">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <label class="flex flex-col items-center gap-2 cursor-pointer group/rate">
                                        <input type="radio" name="q_<?php echo e($q->id); ?>" value="<?php echo e($i); ?>" <?php echo e($q->is_required ? 'required' : ''); ?> class="peer absolute opacity-0">
                                        <div class="w-12 h-12 rounded-2xl bg-slate-100 flex items-center justify-center text-slate-400 peer-checked:bg-amber-500 peer-checked:text-white peer-checked:shadow-lg peer-checked:shadow-amber-200 peer-hover:bg-amber-100 peer-hover:text-amber-500 transition-all">
                                            <i data-lucide="star" class="w-6 h-6 fill-current"></i>
                                        </div>
                                        <span class="text-xs font-bold text-slate-400 peer-checked:text-amber-600"><?php echo e($i); ?></span>
                                    </label>
                                <?php endfor; ?>
                            </div>

                        <?php elseif ($q->question_type == 'textarea'): ?>
                            <textarea aria-label="Tulis jawaban Anda di sini" name="q_<?php echo e($q->id); ?>" <?php echo e($q->is_required ? 'required' : ''); ?> class="w-full px-6 py-5 rounded-2xl bg-white/50 border-2 border-slate-50 focus:border-blue-500 focus:bg-white outline-none font-bold text-slate-700 transition-all h-32 placeholder:font-medium" placeholder="Tulis jawaban Anda di sini..."></textarea>

                        <?php elseif ($q->question_type == 'number'): ?>
                            <div class="relative">
                                <input type="number" name="q_<?php echo e($q->id); ?>" <?php echo e($q->is_required ? 'required' : ''); ?> class="w-full pl-14 pr-6 py-5 rounded-2xl bg-white/50 border-2 border-slate-50 focus:border-blue-500 focus:bg-white outline-none font-bold text-slate-700 transition-all" placeholder="0">
                                <i data-lucide="hash" class="absolute left-6 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400"></i>
                            </div>

                        <?php else: // text ?>
                            <div class="relative">
                                <input aria-label="Masukkan jawaban" type="text" name="q_<?php echo e($q->id); ?>" <?php echo e($q->is_required ? 'required' : ''); ?> class="w-full pl-14 pr-6 py-5 rounded-2xl bg-white/50 border-2 border-slate-50 focus:border-blue-500 focus:bg-white outline-none font-bold text-slate-700 transition-all" placeholder="Masukkan jawaban...">
                                <i data-lucide="edit-3" class="absolute left-6 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="pt-10 flex justify-center">
                <button type="submit" class="w-full md:w-auto px-12 py-5 bg-blue-600 text-white rounded-[2rem] font-black uppercase tracking-[0.2em] text-sm shadow-xl shadow-blue-200 hover:bg-blue-700 hover:-translate-y-1 transition-all active:scale-95 flex items-center justify-center gap-3">
                    <i data-lucide="check-circle" class="w-5 h-5"></i>
                    Simpan Jawaban
                </button>
            </div>
        </form>
    <?php endif; ?>

    <!-- Security Info -->
    <div class="mt-12 flex items-center justify-center gap-3 text-slate-400">
        <i data-lucide="shield-check" class="w-5 h-5"></i>
        <span class="text-xs font-bold uppercase tracking-widest">End-to-End Encryption - Private Data</span>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form[action="handlers/tracer_handler.php"]');
    if (!form) return;

    const questions = document.querySelectorAll('.question-container');

    function evaluateDependencies() {
        questions.forEach(container => {
            const dependsOn = container.getAttribute('data-depends-on');
            const dependsValue = container.getAttribute('data-depends-value');
            
            if (dependsOn) {
                // Cari input dari pertanyaan yang menjadi parent
                let parentSelectedValue = null;
                const parentInputs = form.querySelectorAll(`[name="q_${dependsOn}"], [name="q_${dependsOn}[]"]`);
                
                if (parentInputs.length > 0) {
                    const firstInput = parentInputs[0];
                    if (firstInput.type === 'radio' || firstInput.type === 'checkbox') {
                        // Untuk radio/checkbox, cari yang di-check
                        Array.from(parentInputs).forEach(input => {
                            // Cek jika input tercentang dan masuk dalam daftar dependensi yang diizinkan
                            if (input.checked) {
                                let allowedValues = [];
                                try {
                                    allowedValues = JSON.parse(dependsValue);
                                    if (!Array.isArray(allowedValues)) allowedValues = [allowedValues];
                                } catch(e) {
                                    allowedValues = [dependsValue];
                                }
                                
                                if (allowedValues.includes(input.value)) {
                                    parentSelectedValue = input.value;
                                }
                            }
                        });
                    } else if (firstInput.tagName === 'SELECT') {
                        let allowedValues = [];
                        try {
                            allowedValues = JSON.parse(dependsValue);
                            if (!Array.isArray(allowedValues)) allowedValues = [allowedValues];
                        } catch(e) {
                            allowedValues = [dependsValue];
                        }
                        
                        if (allowedValues.includes(firstInput.value)) {
                            parentSelectedValue = firstInput.value;
                        }
                    }
                }

                const shouldShow = (parentSelectedValue !== null);

                if (shouldShow) {
                    container.classList.remove('hidden');
                    // Aktifkan kembali input di dalam container ini
                    const innerInputs = container.querySelectorAll('input, select, textarea');
                    innerInputs.forEach(input => {
                        input.disabled = false;
                    });
                } else {
                    container.classList.add('hidden');
                    // Nonaktifkan input agar tidak dihitung saat submit dan required validasi
                    const innerInputs = container.querySelectorAll('input, select, textarea');
                    innerInputs.forEach(input => {
                        input.disabled = true;
                        // Reset nilainya agar bersih jika user berubah pikiran
                        if (input.type === 'radio' || input.type === 'checkbox') {
                            input.checked = false;
                        } else {
                            input.value = '';
                        }
                    });
                }
            }
        });
        
        // Recalculate dynamic numbering for visible questions
        let currentNumber = 1;
        questions.forEach(container => {
            if (!container.classList.contains('hidden')) {
                const numberElement = container.querySelector('.dynamic-question-number');
                if (numberElement) {
                    numberElement.textContent = currentNumber;
                }
                currentNumber++;
            }
        });
    }

    // Attach event listeners ke semua input
    form.addEventListener('change', evaluateDependencies);
    
    // Jalankan sekali saat pertama kali di-load
    evaluateDependencies();
});
</script>

