<?php
$user_id = $_SESSION['user_id'];

// Fetch user data
$stmt = $pdo->prepare("SELECT is_verified, last_tracer_update, major, graduation_year, phone, address FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_data = $stmt->fetch();
$is_verified = (bool)($user_data->is_verified ?? false);

$user_major = trim($user_data->major ?? '');
$user_year  = trim($user_data->graduation_year ?? '');
$user_phone = trim($user_data->phone ?? '');
$user_address = trim($user_data->address ?? '');
$profile_incomplete = empty($user_major) || empty($user_year) || empty($user_phone) || empty($user_address);

// Check Tracer Alumni status (6 months rule)
$tracer_date = $user_data->last_tracer_update ?? null;
$six_months_ago = tracer_validity_threshold(); // dari settings.tracer_validity_months
$needs_tracer = !$tracer_date || $tracer_date < $six_months_ago;

$can_request = $is_verified && !$needs_tracer && !$profile_incomplete;

// Fetch user's legalisir requests
$stmt = $pdo->prepare("SELECT * FROM legalisir_requests WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$requests = $stmt->fetchAll();

// Notifications
$msg = '';
$msg_type = 'success';
if (isset($_GET['error'])) {
    $msg_type = 'error';
    switch($_GET['error']) {
        case 'no_docs':
        case 'no_docs_selected':
            $msg = 'Silakan pilih minimal satu dokumen dan unggah berkasnya.';
            break;
        case 'invalid_format':
            $ext = strtoupper($_GET['ext'] ?? '');
            $msg = "Format file ($ext) tidak didukung. Gunakan format yang diizinkan.";
            break;
        case 'file_too_large':
            $msg = 'Ukuran file terlalu besar. Pastikan tidak melebihi batas maksimal.';
            break;
        case 'missing_file':
            $msg = 'Ada dokumen yang dicentang tapi file belum diunggah.';
            break;
        case 'missing_address':
            $msg = 'Mohon lengkapi semua field alamat pengiriman yang wajib diisi.';
            break;
        case 'unverified':
            $msg = 'Akun Anda belum diverifikasi oleh admin.';
            break;
        case 'needs_tracer':
            $msg = 'Silakan perbarui data Tracer Alumni terlebih dahulu.';
            break;
        case 'upload_failed':
            $msg = 'Gagal menyimpan file ke server. Pastikan folder (uploads/) memiliki izin tulis/write permissions (CHMOD 775/777).';
            break;
        default:
            $msg = 'Terjadi kesalahan saat mengunggah. Silakan coba lagi.';
    }
}
?>

<div class="max-w-5xl mx-auto">
    <?php if (!$is_verified): ?>
        <div class="mb-8 p-6 bg-orange-50 border border-orange-200 text-orange-800 rounded-[2rem] flex items-start gap-4 shadow-sm">
            <div class="w-12 h-12 bg-orange-100 rounded-full flex items-center justify-center shrink-0">
                <i data-lucide="shield-alert" class="w-6 h-6 text-orange-600"></i>
            </div>
            <div>
                <h4 class="font-bold text-lg mb-1">Akun Belum Diverifikasi</h4>
                <p class="text-sm opacity-80 leading-relaxed">
                    Mohon maaf, Anda belum dapat melakukan pengajuan legalisir. Admin sedang memverifikasi data akademik Anda. Silakan cek kembali secara berkala.
                </p>
            </div>
        </div>
    <?php elseif ($needs_tracer || $profile_incomplete): ?>
        <div class="mb-8 p-8 bg-blue-50 border border-blue-200 text-blue-800 rounded-[2.5rem] flex flex-col md:flex-row items-start gap-6 shadow-sm">
            <div class="w-14 h-14 bg-blue-100 rounded-2xl flex items-center justify-center shrink-0 shadow-inner">
                <i data-lucide="file-search" class="w-7 h-7 text-blue-600"></i>
            </div>
            <div class="flex-1">
                <h2 class="font-black text-xl mb-2 outfit tracking-tight">Wajib Melengkapi Profil & Tracer Alumni</h2>
                <p class="text-sm opacity-90 leading-relaxed mb-6 max-w-2xl">
                    Untuk memproses Layanan Legalisir dan pengiriman dokumen via ekspedisi, sistem dan admin memerlukan data akurat terkait <span class="font-bold underline">Program Studi</span>, <span class="font-bold underline">Tahun Lulus</span>, <span class="font-bold underline">Nomor HP (WA)</span>, dan <span class="font-bold underline">Alamat Pengiriman</span> Anda. Data ini digunakan untuk melampirkan Sertifikat Akreditasi Prodi serta memastikan dokumen fisik legalisir terkirim ke alamat Anda dengan tepat dan aman. Silakan lengkapi Profil atau isi Tracer Alumni terlebih dahulu.
                </p>
                <div class="flex flex-wrap items-center gap-4">
                    <a href="index.php?page=tracer" class="inline-flex items-center gap-3 bg-blue-600 text-white px-6 py-3.5 rounded-2xl font-bold text-sm shadow-lg shadow-blue-200 hover:bg-blue-700 transition-all active:scale-95">
                        <i data-lucide="clipboard-edit" class="w-4 h-4"></i>
                        Isi Tracer Alumni
                    </a>
                    <a href="index.php?page=profile" class="inline-flex items-center gap-3 bg-white text-slate-700 border border-slate-200 px-6 py-3.5 rounded-2xl font-bold text-sm shadow-sm hover:bg-slate-50 transition-all active:scale-95">
                        <i data-lucide="user" class="w-4 h-4 text-blue-600"></i>
                        Perbarui Profil
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($msg): ?>
        <div class="mb-8 p-4 <?php echo $msg_type == 'success' ? 'bg-green-50 border-green-200 text-green-700' : 'bg-red-50 border-red-200 text-red-700'; ?> border rounded-2xl flex items-center gap-3">
            <i data-lucide="<?php echo $msg_type == 'success' ? 'check-circle' : 'alert-circle'; ?>" class="w-5 h-5"></i>
            <span class="font-medium"><?php echo e($msg); ?></span>
        </div>
    <?php endif; ?>
    
    <!-- Hero Action Card -->
    <div class="relative bg-gradient-to-br from-blue-600 to-blue-700 rounded-[2rem] p-6 mb-6 overflow-hidden shadow-xl shadow-blue-200">
        <!-- Decorative blobs -->
        <div class="absolute -right-6 -top-6 w-32 h-32 bg-white/10 rounded-full blur-xl"></div>
        <div class="absolute -right-2 -bottom-4 w-20 h-20 bg-white/10 rounded-full"></div>

        <div class="relative z-10 flex flex-col md:flex-row md:items-center md:justify-between gap-5">
            <div class="flex items-start gap-4">
                <div class="w-12 h-12 bg-white/20 backdrop-blur-sm rounded-2xl flex items-center justify-center shrink-0">
                    <i data-lucide="award" class="w-6 h-6 text-white"></i>
                </div>
                <div>
                    <h1 class="text-lg font-bold text-white outfit">Legalisir Dokumen</h1>
                    <p class="text-blue-100 text-sm mt-1 leading-relaxed">Ijazah & Transkrip Nilai resmi dengan tanda tangan dan stempel institusi.</p>
                </div>
            </div>

            <?php if ($can_request): ?>
                <button onclick="document.getElementById('newRequestModal').classList.remove('hidden')"
                    class="w-full md:w-auto flex items-center justify-center gap-2 bg-white text-blue-600 px-6 py-3.5 rounded-2xl font-bold shadow-lg hover:bg-blue-50 transition-all active:scale-[0.97] shrink-0">
                    <i data-lucide="plus-circle" class="w-5 h-5"></i>
                    Ajukan Permohonan Baru
                </button>
            <?php else: ?>
                <div class="w-full md:w-auto flex items-center gap-3 bg-white/20 backdrop-blur-sm px-5 py-3.5 rounded-2xl text-white/80 text-sm font-semibold">
                    <i data-lucide="lock" class="w-4 h-4 shrink-0"></i>
                    <span>
                        <?php if (!$is_verified): ?>
                            Akun belum diverifikasi
                        <?php elseif ($needs_tracer || $profile_incomplete): ?>
                            Lengkapi Profil & Tracer dulu
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- History: Mobile Cards + Desktop Table -->
    <?php
    $statusClasses = [
        'pending'    => ['bg' => 'bg-yellow-100 text-yellow-700', 'icon' => 'clock'],
        'processing' => ['bg' => 'bg-blue-100 text-blue-700',   'icon' => 'loader'],
        'completed'  => ['bg' => 'bg-green-100 text-green-700', 'icon' => 'check-circle'],
        'rejected'   => ['bg' => 'bg-red-100 text-red-700',     'icon' => 'x-circle'],
    ];
    $statusLabels = [
        'pending'    => 'Menunggu',
        'processing' => 'Diproses',
        'completed'  => 'Selesai',
        'rejected'   => 'Dibatalkan',
    ];
    ?>

    <?php if (empty($requests)): ?>
        <div class="glass rounded-[2rem] p-12 text-center shadow-sm">
            <div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i data-lucide="inbox" class="w-8 h-8 text-slate-400"></i>
            </div>
            <p class="font-semibold text-slate-500">Belum ada riwayat pengajuan</p>
            <p class="text-sm text-slate-400 mt-1">Mulai ajukan dokumen legalisir Anda.</p>
        </div>
    <?php else: ?>

    <!-- Mobile Card View (hidden on md+) -->
    <div class="space-y-5 md:hidden">
        <?php foreach ($requests as $req): 
            $s = $req->status;
            $sc = $statusClasses[$s] ?? ['bg' => 'bg-slate-100 text-slate-600', 'icon' => 'circle'];
            $sl = $statusLabels[$s] ?? ucfirst($s);
            $paid = $req->payment_status == 'settlement';
            $short_id = strtoupper(substr($req->id, -8));
        ?>
        <div class="glass p-6 rounded-[2rem] border border-white/60 shadow-sm hover:shadow-xl transition-all duration-300 relative overflow-hidden bg-gradient-to-br from-white/90 to-white/40 group">
            <!-- Accent Bar -->
            <div class="absolute left-0 top-0 bottom-0 w-1.5 <?php echo $paid ? 'bg-emerald-500' : 'bg-amber-500'; ?> group-hover:w-2 transition-all"></div>
            
            <div class="flex items-start justify-between gap-3 mb-4 pl-1">
                <div>
                    <span class="inline-block px-3 py-1 bg-slate-100/80 text-slate-600 font-mono text-[10px] font-extrabold rounded-xl mb-2 border border-slate-200/50 shadow-sm">
                        ID #<?php echo e($short_id); ?>
                    </span>
                    <p class="font-black text-slate-800 text-base flex items-center gap-2 outfit">
                        <i data-lucide="calendar" class="w-4 h-4 text-blue-600"></i>
                        <?php echo date('d M Y', strtotime($req->created_at)); ?>
                    </p>
                </div>
                <span class="shrink-0 px-3.5 py-1.5 rounded-xl text-xs font-black flex items-center gap-1.5 shadow-sm border border-white/80 <?php echo e($sc['bg']); ?>">
                    <i data-lucide="<?php echo e($sc['icon']); ?>" class="w-3.5 h-3.5"></i>
                    <?php echo e($sl); ?>
                </span>
            </div>

            <!-- Payment Badge & Actions -->
            <div class="pt-4 border-t border-slate-100 space-y-4 pl-1">
                <div class="flex items-center justify-between">
                    <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">Status Pembayaran</span>
                    <span class="px-3 py-1.5 rounded-xl text-xs font-black flex items-center gap-1.5 shadow-sm border <?php echo $paid ? 'bg-emerald-50 text-emerald-600 border-emerald-100' : 'bg-amber-50 text-amber-600 border-amber-100'; ?>">
                        <i data-lucide="<?php echo $paid ? 'check-circle' : 'credit-card'; ?>" class="w-3.5 h-3.5"></i>
                        <?php echo $paid ? 'LUNAS' : 'MENUNGGU'; ?>
                    </span>
                </div>

                <?php if ($s === 'completed' && !empty($req->verification_token)): ?>
                    <div class="grid grid-cols-1 gap-2 pt-2">
                        <a href="verify.php?token=<?php echo e($req->verification_token); ?>" target="_blank" 
                           class="flex items-center justify-center gap-2 w-full py-3 bg-blue-50 hover:bg-blue-100 text-blue-600 rounded-xl text-xs font-black border border-blue-100 transition-all shadow-sm active:scale-[0.98]">
                            <i data-lucide="shield-check" class="w-4 h-4"></i> Digital Signature
                        </a>
                        <a href="view_softcopy.php?id=<?php echo e($req->id); ?>&token=<?php echo e($req->verification_token); ?>" target="_blank" 
                           class="flex items-center justify-center gap-2 w-full py-3 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-black transition-all shadow-lg shadow-emerald-200 active:scale-[0.98]">
                            <i data-lucide="file-down" class="w-4 h-4"></i> Unduh Softcopy (Berstempel QR)
                        </a>
                    </div>
                <?php endif; ?>

                <div class="pt-2 flex justify-end">
                    <a href="index.php?page=legalisir_detail&id=<?php echo e($req->id); ?>" class="inline-flex items-center gap-1.5 text-xs font-black text-blue-600 hover:text-blue-700 bg-white/80 hover:bg-white px-4 py-2.5 rounded-xl border border-slate-100 shadow-sm transition-all active:scale-[0.98]">
                        Lihat Detail Permohonan <i data-lucide="arrow-right" class="w-4 h-4"></i>
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Desktop Table View (hidden on mobile) -->
    <div class="hidden md:block glass rounded-[2rem] overflow-hidden shadow-sm">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-white/40 border-b border-white/20">
                    <th class="px-6 py-4 text-sm font-semibold text-slate-600">ID Permintaan</th>
                    <th class="px-6 py-4 text-sm font-semibold text-slate-600">Tanggal</th>
                    <th class="px-6 py-4 text-sm font-semibold text-slate-600">Status</th>
                    <th class="px-6 py-4 text-sm font-semibold text-slate-600">Pembayaran</th>
                    <th class="px-6 py-4 text-sm font-semibold text-slate-600">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/20">
                <?php foreach ($requests as $req): 
                    $s = $req->status;
                    $sc = $statusClasses[$s] ?? ['bg' => 'bg-slate-100 text-slate-600', 'icon' => 'circle'];
                    $sl = $statusLabels[$s] ?? ucfirst($s);
                    $paid = $req->payment_status == 'settlement';
                ?>
                <tr class="hover:bg-white/30 transition-all">
                    <td class="px-6 py-4 font-mono text-xs text-slate-500">#<?php echo strtoupper(substr($req->id, -12)); ?></td>
                    <td class="px-6 py-4 text-sm text-slate-600"><?php echo date('d M Y', strtotime($req->created_at)); ?></td>
                    <td class="px-6 py-4">
                        <div class="flex flex-col gap-1">
                            <span class="px-3 py-1 rounded-full text-xs font-bold flex items-center gap-1 w-fit <?php echo e($sc['bg']); ?>">
                                <i data-lucide="<?php echo e($sc['icon']); ?>" class="w-3 h-3"></i>
                                <?php echo e($sl); ?>
                            </span>
                            <?php if ($s === 'completed' && !empty($req->verification_token)): ?>
                                <a href="verify.php?token=<?php echo e($req->verification_token); ?>" target="_blank" 
                                   class="text-[10px] font-bold text-blue-600 hover:underline flex items-center gap-1">
                                    <i data-lucide="shield-check" class="w-3 h-3"></i> Digital Signature
                                </a>
                                <a href="view_softcopy.php?id=<?php echo e($req->id); ?>&token=<?php echo e($req->verification_token); ?>" target="_blank" 
                                   class="text-[10px] font-bold text-emerald-600 hover:underline flex items-center gap-1 mt-0.5">
                                    <i data-lucide="file-down" class="w-3 h-3"></i> Unduh Softcopy
                                </a>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-sm">
                        <span class="flex items-center gap-2 <?php echo $paid ? 'text-green-600' : 'text-orange-500'; ?>">
                            <i data-lucide="<?php echo $paid ? 'check-circle' : 'credit-card'; ?>" class="w-4 h-4"></i>
                            <?php echo $paid ? 'Lunas' : 'Menunggu'; ?>
                        </span>
                    </td>
                    <td class="px-6 py-4">
                        <a href="index.php?page=legalisir_detail&id=<?php echo e($req->id); ?>" class="inline-flex items-center gap-1 text-blue-600 font-semibold text-sm hover:underline">
                            Detail <i data-lucide="arrow-right" class="w-3 h-3"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- New Request Modal (Dynamic) -->
    <?php
    $stmt_settings = $pdo->query("SELECT setting_key, setting_value FROM settings");
    $raw_settings = $stmt_settings->fetchAll();
    $sys_settings = [];
    foreach ($raw_settings as $s) { $sys_settings[$s->setting_key] = $s->setting_value; }

    $price_per_doc = (int)($sys_settings['price_per_doc'] ?? 10000);
    $shipping_fee = (int)($sys_settings['shipping_fee'] ?? 15000);

    // Gross-up config variables
    $mdr_rate_max    = (float)($sys_settings['midtrans_mdr_rate'] ?? 4.0);
    $ppn_rate        = (float)($sys_settings['midtrans_ppn_rate'] ?? 11.0);
    $biaya_payout    = (int)($sys_settings['midtrans_payout_fee'] ?? 2500);
    $margin_admin    = (int)($sys_settings['midtrans_margin_admin'] ?? 2500);
    $custom_tax_value= (int)($sys_settings['custom_tax_value'] ?? 0);

    $doc_types_json = $sys_settings['legalisir_document_types'] ?? '[{"id":"ijazah","name":"Ijazah Asli (Scan)"},{"id":"transkrip","name":"Transkrip Nilai (Scan)"}]';
    $doc_types = json_decode($doc_types_json);

    $allowed_types = $sys_settings['allowed_file_types'] ?? 'pdf,jpg,jpeg,png';
    $max_size_kb = $sys_settings['max_file_size'] ?? 2048;
    $max_size_mb = round($max_size_kb / 1024, 1);
    
    // Format for 'accept' attribute
    $accept_attr = '.' . str_replace(',', ',.', $allowed_types);
    // Format for display
    $display_types = strtoupper(str_replace(',', ', ', $allowed_types));

    // Shipping Zones for JS
    $zones_raw = $sys_settings['shipping_zones'] ?? '[{"label":"Luar Kota","provinces":[],"cost":25000,"is_default":true}]';
    $zones_php = json_decode($zones_raw, true) ?: [];
    $zones_js  = json_encode($zones_php, JSON_UNESCAPED_UNICODE);
    ?>
    <div id="newRequestModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm hidden">
        <div class="glass w-full max-w-xl p-6 md:p-8 rounded-[2.5rem] shadow-2xl relative max-h-[90vh] overflow-y-auto custom-scrollbar">
            <button onclick="document.getElementById('newRequestModal').classList.add('hidden')" class="absolute top-6 right-6 text-slate-400 hover:text-slate-600">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
            
            <h3 class="text-xl md:text-2xl font-bold outfit mb-4 md:mb-6">Ajukan Legalisir Baru</h3>
            
            <form action="handlers/legalisir_handler.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                <div id="uploadError" class="hidden p-4 bg-red-50 text-red-700 border border-red-200 rounded-2xl text-sm font-bold flex items-center gap-2 shadow-sm">
                    <i data-lucide="alert-triangle" class="w-5 h-5 shrink-0"></i>
                    <span id="uploadErrorText"></span>
                </div>
                <?php csrf_field(); ?>
                <?php
                // Fetch all majors from DB to map user's major string/name to major_code and vice-versa
                $stmt_all_majors = $pdo->query("SELECT major_code, major_name FROM majors");
                $all_majors_map = $stmt_all_majors->fetchAll();

                $user_major_code = '';
                $user_major_name = '';

                foreach ($all_majors_map as $m) {
                    if (strcasecmp($user_major, $m->major_code) === 0 || strcasecmp($user_major, $m->major_name) === 0) {
                        $user_major_code = $m->major_code;
                        $user_major_name = $m->major_name;
                        break;
                    }
                }

                // Determine user prodi classification for fallback intelligent document filtering
                $major_lower = strtolower($user_major);
                $user_prodi_type = 'other';
                if (stripos($major_lower, 's1') !== false || (stripos($major_lower, 'kedokteran') !== false && stripos($major_lower, 'profesi') === false && stripos($major_lower, 's2') === false) || $user_major_code === 'J500') {
                    $user_prodi_type = 's1';
                } elseif (stripos($major_lower, 'profesi') !== false || $user_major_code === 'J530') {
                    $user_prodi_type = 'profesi';
                } elseif (stripos($major_lower, 's2') !== false || stripos($major_lower, 'mars') !== false || $user_major_code === 'M500') {
                    $user_prodi_type = 's2';
                }
                ?>
                <div class="max-h-[35vh] overflow-y-auto pr-2 custom-scrollbar">
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-3 ml-1">Pilih Dokumen & Unggah Berkas</label>
                    <div class="grid grid-cols-1 gap-4">
                        <?php foreach ($doc_types as $doc): 
                            // Sanitize ID: PHP replaces spaces with underscores in $_FILES keys
                            $safe_id = str_replace(' ', '_', $doc->id);
                            
                            $target_major = trim($doc->target_major_code ?? '');
                            $is_akreditasi = !empty($doc->is_akreditasi) || stripos($doc->id, 'akreditasi') !== false || stripos($doc->name, 'akreditasi') !== false;

                            $is_my_prodi = false;
                            if ($target_major !== '') {
                                // Match exactly by major code or major name
                                if (strcasecmp($target_major, $user_major_code) === 0 || strcasecmp($target_major, $user_major_name) === 0 || strcasecmp($target_major, $user_major) === 0) {
                                    $is_my_prodi = true;
                                }
                            }

                            if (!$is_my_prodi) {
                                // Fallback to intelligent keyword matching if target_major_code is not set OR if user is a legacy account
                                $doc_name_lower = strtolower($doc->name);
                                $doc_id_lower   = strtolower($doc->id);
                                $doc_prodi_type = 'other';

                                if (stripos($doc_name_lower, 's1') !== false || stripos($doc_id_lower, 's1') !== false) {
                                    $doc_prodi_type = 's1';
                                } elseif (stripos($doc_name_lower, 'profesi') !== false || stripos($doc_id_lower, 'profesi') !== false || stripos($doc_name_lower, 'sumpah') !== false) {
                                    $doc_prodi_type = 'profesi';
                                } elseif (stripos($doc_name_lower, 's2') !== false || stripos($doc_id_lower, 's2') !== false || stripos($doc_name_lower, 'mars') !== false) {
                                    $doc_prodi_type = 's2';
                                }

                                // If user prodi is 'other' (legacy account), show all documents so they don't get a blank screen
                                $is_my_prodi = ($user_prodi_type === $doc_prodi_type) || ($doc_prodi_type === 'other') || ($user_prodi_type === 'other');
                            }

                            if (!$is_my_prodi) continue; // Hide documents that do not belong to the user's prodi
                        ?>
                        <div class="p-4 bg-white/40 hover:bg-white/60 border border-slate-200 rounded-3xl group transition-all">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 <?php echo $is_akreditasi ? 'bg-emerald-50 text-emerald-600' : 'bg-blue-50 text-blue-600'; ?> rounded-xl flex items-center justify-center shrink-0">
                                        <i data-lucide="<?php echo e($is_akreditasi ? 'award' : ((stripos($doc->id, 'ijazah') !== false) ? 'scroll' : ((stripos($doc->id, 'transkrip') !== false) ? 'file-spreadsheet' : 'file-text'))); ?>" class="w-5 h-5"></i>
                                    </div>
                                    <div class="flex flex-col">
                                        <span class="text-sm font-bold text-slate-800 leading-tight"><?php echo htmlspecialchars($doc->name); ?></span>
                                        <?php if ($is_akreditasi): ?>
                                            <span class="text-[10px] font-bold text-emerald-600 uppercase tracking-widest mt-0.5 flex items-center gap-1">
                                                <i data-lucide="check-circle-2" class="w-3 h-3"></i> Terlampir Otomatis (Tahun Lulus: <?php echo htmlspecialchars($user_year); ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">Format: <?php echo e($display_types); ?> &bull; Maks <?php echo e($max_size_mb); ?>MB</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <input aria-label="Pilih dokumen" type="checkbox" name="docs_type[]" value="<?php echo e($safe_id); ?>" class="w-5 h-5 accent-blue-600 doc-checkbox">
                            </div>
                            <?php if (!$is_akreditasi): ?>
                                <input type="file" name="file_<?php echo e($safe_id); ?>" accept="<?php echo e($accept_attr); ?>" class="w-full text-[10px] text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-[10px] file:font-black file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer">
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2 ml-1">Metode Pengiriman</label>
                    <select aria-label="Metode pengiriman" name="delivery_method" id="delivery_method" required class="w-full px-5 py-3 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none appearance-none cursor-pointer text-sm">
                        <option value="ambil_sendiri">Ambil Sendiri di Kampus (Rp 0)</option>
                        <option value="kurir">Kirim via Kurir (biaya berdasarkan wilayah)</option>
                    </select>
                </div>

                <!-- Address Form — shown only when 'kurir' selected -->
                <div id="addressForm" class="hidden space-y-3 p-4 bg-slate-50/80 border border-slate-200 rounded-2xl">
                    <p class="text-xs font-bold text-slate-500 uppercase tracking-widest">Alamat Pengiriman</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-500 mb-1">Nama Penerima <span class="text-red-500">*</span></label>
                            <input aria-label="Nama lengkap penerima" type="text" name="addr_name" id="addr_name" placeholder="Nama lengkap penerima"
                                class="w-full px-4 py-2.5 rounded-xl bg-white border border-slate-200 focus:border-blue-400 outline-none text-sm">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-500 mb-1">No. HP <span class="text-red-500">*</span></label>
                            <input aria-label="08xxxxxxxxxx" type="tel" name="addr_phone" id="addr_phone" placeholder="08xxxxxxxxxx"
                                class="w-full px-4 py-2.5 rounded-xl bg-white border border-slate-200 focus:border-blue-400 outline-none text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-500 mb-1">Alamat Jalan <span class="text-red-500">*</span></label>
                        <input aria-label="Nama jalan, nomor rumah, RT/RW" type="text" name="addr_street" id="addr_street" placeholder="Nama jalan, nomor rumah, RT/RW"
                            class="w-full px-4 py-2.5 rounded-xl bg-white border border-slate-200 focus:border-blue-400 outline-none text-sm">
                    </div>

                    <!-- Cascading Region Dropdowns -->
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-500 mb-1">Provinsi <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <select aria-label="⏳ Memuat daftar provinsi..." id="dd_province" class="w-full px-4 py-2.5 rounded-xl bg-white border border-slate-200 focus:border-blue-400 outline-none text-sm cursor-pointer appearance-none">
                                <option value="">⏳ Memuat daftar provinsi...</option>
                            </select>
                            <i data-lucide="chevron-down" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                        </div>
                        <!-- Hidden input stores text value for server -->
                        <input type="hidden" name="addr_province" id="addr_province">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-500 mb-1">Kabupaten/Kota <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <select aria-label="-- Pilih Provinsi dulu --" id="dd_city" disabled class="w-full px-4 py-2.5 rounded-xl bg-slate-100 border border-slate-200 outline-none text-sm cursor-not-allowed appearance-none transition-colors">
                                    <option value="">-- Pilih Provinsi dulu --</option>
                                </select>
                                <i data-lucide="chevron-down" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                            </div>
                            <input type="hidden" name="addr_city" id="addr_city">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-500 mb-1">Kecamatan <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <select aria-label="-- Pilih Kab/Kota dulu --" id="dd_district" disabled class="w-full px-4 py-2.5 rounded-xl bg-slate-100 border border-slate-200 outline-none text-sm cursor-not-allowed appearance-none transition-colors">
                                    <option value="">-- Pilih Kab/Kota dulu --</option>
                                </select>
                                <i data-lucide="chevron-down" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                            </div>
                            <input type="hidden" name="addr_district" id="addr_district">
                        </div>
                    </div>

                    <div>
                        <label class="block text-[11px] font-semibold text-slate-500 mb-1">Kode Pos</label>
                        <input aria-label="5 digit kode pos" type="text" name="addr_postal" id="addr_postal" placeholder="5 digit kode pos" maxlength="5"
                            class="w-full px-4 py-2.5 rounded-xl bg-white border border-slate-200 focus:border-blue-400 outline-none text-sm">
                    </div>
                </div>

                <div class="bg-blue-50/50 p-4 rounded-2xl border border-blue-100">
                    <div class="space-y-1.5 mb-3 border-b border-blue-100 pb-3">
                        <div class="flex justify-between text-xs text-blue-600">
                            <span>Biaya Dokumen</span>
                            <span id="docCostDisplay">Rp 0</span>
                        </div>
                        <div class="flex justify-between text-xs text-blue-600">
                            <span>Biaya Admin & Layanan</span>
                            <span id="adminCostDisplay">Rp 0</span>
                        </div>
                        <div class="flex justify-between text-xs text-blue-600">
                            <span>Biaya Pengiriman</span>
                            <span id="shipCostDisplay">Rp 0</span>
                        </div>
                    </div>
                    <div class="flex justify-between items-center text-blue-800">
                        <span class="font-bold text-sm">Total Pembayaran:</span>
                        <span class="text-xl font-bold outfit">Rp <span id="costDisplay">0</span></span>
                    </div>
                </div>

                <div class="flex items-start gap-3 p-4 bg-slate-50 rounded-2xl border border-slate-200">
                    <input type="checkbox" id="legalStatement" class="mt-1 w-4 h-4 accent-blue-600 cursor-pointer">
                    <label for="legalStatement" class="text-[10px] text-slate-500 leading-relaxed font-medium cursor-pointer">
                        Saya menyatakan dengan sadar dan sebenar-benarnya bahwa seluruh dokumen yang saya unggah adalah asli, sesuai dengan data institusi, dan dapat dipertanggungjawabkan secara hukum. Saya memahami bahwa segala bentuk pemalsuan dokumen akan diproses sesuai hukum yang berlaku di Negara Republik Indonesia.
                    </label>
                </div>

                <button type="submit" id="submitBtn" disabled class="w-full py-4 bg-slate-200 text-slate-400 rounded-2xl font-black shadow-lg cursor-not-allowed transition-all flex items-center justify-center gap-2">
                    <i data-lucide="credit-card" class="w-5 h-5"></i>
                    Bayar Sekarang (Midtrans)
                </button>

                <!-- Spacer for Mobile Nav -->
                <div class="h-20 md:hidden"></div>
            </form>
        </div>
    </div>
</div>

<script>
    const pricePerDoc   = <?php echo e($price_per_doc); ?>;
    const shippingZones = <?php echo e($zones_js); ?>;
    
    // Gross-up config values
    const mdrRateMax    = <?php echo e($mdr_rate_max); ?>;
    const ppnRate       = <?php echo e($ppn_rate); ?>;
    const payoutFee     = <?php echo e($biaya_payout); ?>;
    const marginAdmin   = <?php echo e($margin_admin); ?>;
    const customTaxVal  = <?php echo e($custom_tax_value); ?>;

    // ── DOM refs ────────────────────────────────────────────────
    const checkboxes     = document.querySelectorAll('.doc-checkbox');
    const deliverySel    = document.getElementById('delivery_method');
    const display        = document.getElementById('costDisplay');
    const docDisplay     = document.getElementById('docCostDisplay');
    const adminDisplay   = document.getElementById('adminCostDisplay');
    const shipDisplay    = document.getElementById('shipCostDisplay');
    const legalCheckbox  = document.getElementById('legalStatement');
    const submitBtn      = document.getElementById('submitBtn');
    const addressForm    = document.getElementById('addressForm');
    const zoneIndicator  = document.getElementById('zoneIndicator');
    const zoneLabel      = document.getElementById('zoneLabel');

    // Cascading dropdowns (UI)
    const ddProvince = document.getElementById('dd_province');
    const ddCity     = document.getElementById('dd_city');
    const ddDistrict = document.getElementById('dd_district');

    // Hidden inputs submitted to PHP
    const hidProvince = document.getElementById('addr_province');
    const hidCity     = document.getElementById('addr_city');
    const hidDistrict = document.getElementById('addr_district');

    // Gunakan endpoint resmi yang lebih stabil
    const WILAYAH_API = 'https://www.emsifa.com/api-wilayah-indonesia/api';

    // Data lokal 38 Provinsi sebagai cadangan (Pasti Muncul)
    const PROVINCES_LOCAL = [
        {id:"11",name:"ACEH"},{id:"12",name:"SUMATERA UTARA"},{id:"13",name:"SUMATERA BARAT"},{id:"14",name:"RIAU"},{id:"15",name:"JAMBI"},{id:"16",name:"SUMATERA SELATAN"},{id:"17",name:"BENGKULU"},{id:"18",name:"LAMPUNG"},{id:"19",name:"KEPULAUAN BANGKA BELITUNG"},{id:"21",name:"KEPULAUAN RIAU"},{id:"31",name:"DKI JAKARTA"},{id:"32",name:"JAWA BARAT"},{id:"33",name:"JAWA TENGAH"},{id:"34",name:"DI YOGYAKARTA"},{id:"35",name:"JAWA TIMUR"},{id:"36",name:"BANTEN"},{id:"51",name:"BALI"},{id:"52",name:"NUSA TENGGARA BARAT"},{id:"53",name:"NUSA TENGGARA TIMUR"},{id:"61",name:"KALIMANTAN BARAT"},{id:"62",name:"KALIMANTAN TENGAH"},{id:"63",name:"KALIMANTAN SELATAN"},{id:"64",name:"KALIMANTAN TIMUR"},{id:"65",name:"KALIMANTAN UTARA"},{id:"71",name:"SULAWESI UTARA"},{id:"72",name:"SULAWESI TENGAH"},{id:"73",name:"SULAWESI SELATAN"},{id:"74",name:"SULAWESI TENGGARA"},{id:"75",name:"GORONTALO"},{id:"76",name:"SULAWESI BARAT"},{id:"81",name:"MALUKU"},{id:"82",name:"MALUKU UTARA"},{id:"91",name:"PAPUA BARAT"},{id:"92",name:"PAPUA"},{id:"93",name:"PAPUA SELATAN"},{id:"94",name:"PAPUA TENGAH"},{id:"95",name:"PAPUA PEGUNUNGAN"},{id:"96",name:"PAPUA BARAT DAYA"}
    ];

    let currentShippingCost = 0;
    let provincesLoaded     = false;

    // ── Helpers ─────────────────────────────────────────────────
    function formatRp(val) { return 'Rp ' + val.toLocaleString('id-ID'); }

    function toTitleCase(str) {
        return str.toLowerCase().replace(/\b\w/g, c => c.toUpperCase());
    }

    function setSelectLoading(el, msg = '⏳ Memuat...') {
        el.innerHTML = `<option value="">${msg}</option>`;
        el.disabled = true;
        el.classList.add('bg-slate-100', 'cursor-not-allowed');
        el.classList.remove('bg-white', 'cursor-pointer');
    }

    function enableSelect(el) {
        el.disabled = false;
        el.classList.remove('bg-slate-100', 'cursor-not-allowed');
        el.classList.add('bg-white', 'cursor-pointer');
    }

    function getZoneForProvince(province) {
        if (!province) return null;
        const norm    = province.toLowerCase();
        const matched = shippingZones.find(z =>
            z.provinces && z.provinces.some(p => p.toLowerCase() === norm)
        );
        if (matched) return matched;
        return shippingZones.find(z => z.is_default) || null;
    }

    // ── Zone update ─────────────────────────────────────────────
    function applyZone(provinceName) {
        const zone = getZoneForProvince(provinceName);
        currentShippingCost = (zone && provinceName) ? zone.cost : 0;
        calculate(); // Langsung update rincian biaya di bawah
    }

    // ── Render Provinces ────────────────────────────────────────
    function populateProvinces(data) {
        ddProvince.innerHTML = '<option value="">-- Pilih Provinsi --</option>';
        data.sort((a, b) => a.name.localeCompare(b.name)).forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.dataset.name = toTitleCase(p.name);
            opt.textContent  = toTitleCase(p.name);
            ddProvince.appendChild(opt);
        });
        enableSelect(ddProvince);
        provincesLoaded = true;
    }

    // ── Load Provinces ───────────────────────────────────────────
    function loadProvinces() {
        if (provincesLoaded) return;
        
        // Gunakan data lokal dulu agar instan
        populateProvinces(PROVINCES_LOCAL);

        // Opsional: Tetap fetch untuk memastikan data terbaru/sinkron (background)
        fetch(`${WILAYAH_API}/provinces.json`)
            .then(r => r.ok ? r.json() : null)
            .then(data => {
                if (data) populateProvinces(data);
            })
            .catch(err => console.warn('Fetch provinces background failed, using local data.'));
    }

    // ── Load Regencies (Kabupaten/Kota) ─────────────────────────
    function loadCities(provinceId, provinceName) {
        // Reset downstream
        ddDistrict.innerHTML = '<option value="">-- Pilih Kab/Kota dulu --</option>';
        ddDistrict.disabled  = true;
        hidCity.value        = '';
        hidDistrict.value    = '';

        setSelectLoading(ddCity, '⏳ Memuat kabupaten/kota...');
        fetch(`${WILAYAH_API}/regencies/${provinceId}.json`)
            .then(r => r.json())
            .then(data => {
                ddCity.innerHTML = '<option value="">-- Pilih Kabupaten/Kota --</option>';
                data.forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c.id;
                    opt.dataset.name = toTitleCase(c.name);
                    opt.textContent  = toTitleCase(c.name);
                    ddCity.appendChild(opt);
                });
                enableSelect(ddCity);
            })
            .catch(() => {
                ddCity.innerHTML = '<option value="">⚠ Gagal memuat data kabupaten.</option>';
            });

        hidProvince.value = provinceName;
        applyZone(provinceName);
        updateButtonState();
    }

    // ── Load Districts (Kecamatan) ───────────────────────────────
    function loadDistricts(cityId, cityName) {
        hidCity.value     = cityName;
        hidDistrict.value = '';
        setSelectLoading(ddDistrict, '⏳ Memuat kecamatan...');
        fetch(`${WILAYAH_API}/districts/${cityId}.json`)
            .then(r => r.json())
            .then(data => {
                ddDistrict.innerHTML = '<option value="">-- Pilih Kecamatan --</option>';
                data.forEach(d => {
                    const opt = document.createElement('option');
                    opt.value = d.id;
                    opt.dataset.name = toTitleCase(d.name);
                    opt.textContent  = toTitleCase(d.name);
                    ddDistrict.appendChild(opt);
                });
                enableSelect(ddDistrict);
            })
            .catch(() => {
                ddDistrict.innerHTML = '<option value="">⚠ Gagal memuat kecamatan.</option>';
            });
        updateButtonState();
    }

    // ── Dropdown Event Listeners ─────────────────────────────────
    ddProvince.addEventListener('change', () => {
        const sel  = ddProvince.options[ddProvince.selectedIndex];
        const id   = sel.value;
        const name = sel.dataset.name || '';
        if (id) {
            loadCities(id, name);
        } else {
            hidProvince.value = '';
            hidCity.value     = '';
            hidDistrict.value = '';
            setSelectLoading(ddCity,     '-- Pilih Provinsi dulu --');
            setSelectLoading(ddDistrict, '-- Pilih Kab/Kota dulu --');
            currentShippingCost = 0;
            zoneIndicator.classList.add('hidden');
            calculate();
        }
    });

    ddCity.addEventListener('change', () => {
        const sel  = ddCity.options[ddCity.selectedIndex];
        const id   = sel.value;
        const name = sel.dataset.name || '';
        if (id) { loadDistricts(id, name); }
        else    { hidCity.value = ''; hidDistrict.value = ''; }
        updateButtonState();
    });

    ddDistrict.addEventListener('change', () => {
        const sel  = ddDistrict.options[ddDistrict.selectedIndex];
        hidDistrict.value = sel.value ? (sel.dataset.name || '') : '';
        updateButtonState();
    });

    // ── Calculate totals ─────────────────────────────────────────
    function calculate() {
        const count      = Array.from(checkboxes).filter(cb => cb.checked).length;
        const isShipping = deliverySel.value === 'kurir';
        const docTotal   = count * pricePerDoc;
        
        // Ongkir muncul jika mode kurir aktif & biaya sudah terdeteksi (meskipun docs belum dicentang)
        const shipTotal  = isShipping ? currentShippingCost : 0;
        
        let adminTotal = 0;
        if (count > 0) {
            const tagihanPokok = docTotal + shipTotal;
            const multiplierPajak = (ppnRate / 100.0) + 1; 
            const totalMdrMultiplier = (mdrRateMax / 100.0) * multiplierPajak;
            const grossUpDivider = 1 - totalMdrMultiplier;

            let gwFee = ((tagihanPokok + customTaxVal) * totalMdrMultiplier + payoutFee + marginAdmin) / grossUpDivider;
            gwFee = Math.ceil(gwFee);
            adminTotal = gwFee + customTaxVal; 
        }
        
        const total      = docTotal + adminTotal + shipTotal;

        docDisplay.innerText   = formatRp(docTotal);
        adminDisplay.innerText = formatRp(adminTotal);
        shipDisplay.innerText  = formatRp(shipTotal);
        display.innerText      = total.toLocaleString('id-ID');

        updateButtonState();
    }

    // ── Button state ─────────────────────────────────────────────
    function updateButtonState() {
        const checkedBoxes = Array.from(checkboxes).filter(cb => cb.checked);
        const anyChecked   = checkedBoxes.length > 0;
        const isKurir      = deliverySel.value === 'kurir';

        let allFilesSelected = true;
        checkedBoxes.forEach(cb => {
            const fi = document.querySelector(`input[name="file_${cb.value}"]`);
            // If fi exists (not an auto-attached akreditasi document), check if a file is selected
            if (fi && (!fi.files || fi.files.length === 0)) {
                allFilesSelected = false;
            }
        });

        let addressOk = true;
        if (isKurir) {
            const name     = document.getElementById('addr_name')?.value.trim();
            const phone    = document.getElementById('addr_phone')?.value.trim();
            const street   = document.getElementById('addr_street')?.value.trim();
            const province = hidProvince.value;
            const city     = hidCity.value;
            const district = hidDistrict.value;
            addressOk = !!(name && phone && street && province && city && district);
        }

        const canSubmit = legalCheckbox.checked && anyChecked && allFilesSelected && addressOk;
        submitBtn.disabled = !canSubmit;
        if (canSubmit) {
            submitBtn.classList.remove('bg-slate-200', 'text-slate-400', 'cursor-not-allowed');
            submitBtn.classList.add('bg-blue-600', 'text-white', 'hover:bg-blue-700', 'shadow-blue-200');
        } else {
            submitBtn.classList.add('bg-slate-200', 'text-slate-400', 'cursor-not-allowed');
            submitBtn.classList.remove('bg-blue-600', 'text-white', 'hover:bg-blue-700', 'shadow-blue-200');
        }
    }

    // ── Toggle address form ──────────────────────────────────────
    deliverySel.addEventListener('change', () => {
        if (deliverySel.value === 'kurir') {
            addressForm.classList.remove('hidden');
            loadProvinces(); // lazy load only when needed
        } else {
            addressForm.classList.add('hidden');
            currentShippingCost = 0;
            zoneIndicator.classList.add('hidden');
        }
        calculate();
    });

    // ── Text field changes ───────────────────────────────────────
    ['addr_name', 'addr_phone', 'addr_street', 'addr_postal'].forEach(id => {
        document.getElementById(id)?.addEventListener('input', updateButtonState);
    });

    checkboxes.forEach(cb => cb.addEventListener('change', calculate));
    document.querySelectorAll('input[type="file"]').forEach(fi => {
        fi.addEventListener('change', function() {
            const maxSizeBytes = <?php echo e($max_size_kb); ?> * 1024;
            const errBox = document.getElementById('uploadError');
            const errText = document.getElementById('uploadErrorText');
            
            if (this.files && this.files[0]) {
                if (this.files[0].size > maxSizeBytes) {
                    errBox.classList.remove('hidden');
                    errText.innerText = `Ukuran file "${this.files[0].name}" terlalu besar! (Maksimal <?php echo e($max_size_mb); ?>MB)`;
                    this.value = ''; // clear the input to prevent submission
                } else {
                    errBox.classList.add('hidden');
                }
            }
            updateButtonState();
        });
    });
    
    legalCheckbox.addEventListener('change', updateButtonState);

    // Init
    calculate();
</script>
