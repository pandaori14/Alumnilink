<?php
// Sebelumnya seluruh baris document_repository diambil tanpa LIMIT. Yang
// ditampilkan kini hanya satu halaman; hitungan "berkas hilang" di bawah
// tetap memeriksa SELURUH baris (lihat catatannya di sana).
$r_cari = trim($_GET['cari'] ?? '');

$r_where  = " FROM document_repository dr JOIN users u ON dr.uploaded_by = u.id WHERE 1=1";
$r_params = [];
if ($r_cari !== '') {
    $r_where .= " AND (dr.nim LIKE ? OR dr.document_type LIKE ? OR u.name LIKE ?)";
    $rk = "%$r_cari%";
    array_push($r_params, $rk, $rk, $rk);
}

$hal_dok   = paginate($pdo, $r_where, 'dr.*, u.name as uploader_name', 'dr.created_at DESC', $r_params, 20);
$documents = $hal_dok['rows'];
$dok_url   = pager_url_builder(['page' => 'admin_repository', 'cari' => $r_cari]);

// Fetch defined document types from settings
$settings = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$doc_types_json = $settings['legalisir_document_types'] ?? '[{"id":"ijazah","name":"Ijazah Asli (Scan)"},{"id":"transkrip","name":"Transkrip Nilai (Scan)"}]';
$doc_types = json_decode($doc_types_json);

// Fetch Master Accreditation Certificates
$stmt_accred = $pdo->query("SELECT ac.*, u.name as uploader_name FROM accreditation_certificates ac LEFT JOIN users u ON ac.uploaded_by = u.id ORDER BY ac.created_at DESC");
$accred_certs = $stmt_accred->fetchAll();

// Fetch Majors
$stmt_maj = $pdo->query("SELECT major_code, major_name FROM majors ORDER BY major_name ASC");
$all_majors = $stmt_maj->fetchAll();

/**
 * Periksa keberadaan berkas fisik untuk tiap baris repositori.
 *
 * Baris di basis data dapat menunjuk berkas yang sudah tidak ada di disk --
 * misalnya karena berkas terhapus manual, gagal ikut terunggah saat migrasi
 * server, atau berpindah folder. Sebelumnya kondisi ini tidak terlihat sama
 * sekali: tautan tetap tampil normal lalu menghasilkan 404 saat diklik.
 */
$file_exists_cache = function ($relative_path) {
    static $cache = [];
    $key = (string)$relative_path;
    if (!isset($cache[$key])) {
        $cache[$key] = $key !== '' && is_file(dirname(__DIR__) . '/' . ltrim($key, '/\\'));
    }
    return $cache[$key];
};

$missing_docs   = 0;
$missing_accred = 0;
// Sengaja memindai SELURUH baris, bukan hanya halaman yang sedang tampil:
// gunanya indikator ini justru menemukan berkas hilang di mana pun ia
// berada. Yang diambil hanya kolom file_path, sehingga jauh lebih ringan
// daripada mengambil seluruh baris seperti sebelumnya.
foreach ($pdo->query("SELECT file_path FROM document_repository") as $d_all) {
    if (!$file_exists_cache($d_all->file_path)) { $missing_docs++; }
}
foreach ($accred_certs as $a) {
    if (!$file_exists_cache($a->file_path)) { $missing_accred++; }
}
$missing_total = $missing_docs + $missing_accred;

// Notifications
$msg = '';
$msg_type = 'success';
if (isset($_GET['success'])) {
    if ($_GET['success'] == 'uploaded') { $msg = 'Dokumen resmi berhasil diunggah ke repositori.'; }
    if ($_GET['success'] == 'deleted') { $msg = 'Dokumen berhasil dihapus dari repositori.'; }
    if ($_GET['success'] == 'accred_uploaded') { $msg = 'Master Sertifikat Akreditasi berhasil diunggah.'; }
    if ($_GET['success'] == 'accred_deleted') { $msg = 'Master Sertifikat Akreditasi berhasil dihapus.'; }
}
if (isset($_GET['error'])) {
    $msg_type = 'error';
    if ($_GET['error'] == 'missing_data') { $msg = 'Data tidak lengkap. Pastikan NIM, Jenis Dokumen, dan File sudah diisi.'; }
    if ($_GET['error'] == 'invalid_format') { $msg = 'Format file tidak didukung. Gunakan PDF/JPG/PNG.'; }
    if ($_GET['error'] == 'upload_failed') { $msg = 'Gagal menyimpan file ke server.'; }
    if ($_GET['error'] == 'missing_accred_data') { $msg = 'Data master akreditasi tidak lengkap.'; }
    if ($_GET['error'] == 'invalid_year_range') { $msg = 'Rentang tahun berlaku tidak valid (Tahun Mulai melebihi Tahun Akhir).'; }
    if ($_GET['error'] == 'invalid_accred_format') { $msg = 'Format file akreditasi tidak didukung.'; }
    if ($_GET['error'] == 'accred_upload_failed') { $msg = 'Gagal menyimpan file master akreditasi ke server.'; }
}
?>

<div class="max-w-6xl mx-auto pb-24 md:pb-12 px-4 md:px-0">
    <?php if ($msg): ?>
        <div class="mb-8 p-4 <?php echo $msg_type == 'success' ? 'bg-green-50 border-green-200 text-green-700' : 'bg-red-50 border-red-200 text-red-700'; ?> border rounded-2xl flex items-center gap-3 shadow-sm">
            <i data-lucide="<?php echo $msg_type == 'success' ? 'check-circle' : 'alert-circle'; ?>" class="w-5 h-5"></i>
            <span class="font-medium text-sm"><?php echo e($msg); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($missing_total > 0): ?>
        <div class="mb-8 p-5 bg-amber-50 border border-amber-200 rounded-2xl flex items-start gap-4" role="alert">
            <i data-lucide="file-warning" class="w-6 h-6 text-amber-600 shrink-0 mt-0.5" aria-hidden="true"></i>
            <div class="flex-1">
                <p class="font-bold text-amber-800 text-sm">
                    <?php echo e($missing_total); ?> berkas tidak ditemukan di server
                </p>
                <p class="text-xs text-amber-700 mt-1.5 leading-relaxed">
                    Terdapat
                    <?php if ($missing_docs): ?><strong><?php echo e($missing_docs); ?> dokumen repositori</strong><?php endif; ?>
                    <?php if ($missing_docs && $missing_accred): ?> dan <?php endif; ?>
                    <?php if ($missing_accred): ?><strong><?php echo e($missing_accred); ?> master akreditasi</strong><?php endif; ?>
                    yang datanya masih tercatat, namun berkas fisiknya sudah tidak ada di server.
                    Baris tersebut ditandai <span class="font-bold">Berkas Hilang</span> di bawah.
                    Unggah ulang berkasnya, atau hapus barisnya agar tidak muncul sebagai tautan rusak bagi petugas.
                </p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Master Accreditation Section -->
    <div class="mb-8 px-1 flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div>
            <h1 class="text-xl md:text-2xl font-bold outfit text-slate-800 tracking-tight">Master Sertifikat Akreditasi Prodi</h1>
            <p class="text-slate-400 text-xs md:text-sm mt-1 font-medium leading-relaxed">Unggah sertifikat akreditasi BAN-PT/LAM-PTKes per prodi yang berlaku 5 tahun. Sistem otomatis mencocokkan dengan tahun lulus alumni.</p>
        </div>
        <div class="flex items-center gap-3 shrink-0 w-full md:w-auto mt-2 md:mt-0">
            <button onclick="document.getElementById('uploadAccredModal').classList.remove('hidden')" class="w-full md:w-auto justify-center bg-emerald-600 text-white px-6 py-3.5 rounded-2xl font-bold shadow-lg shadow-emerald-200 hover:bg-emerald-700 active:scale-95 transition-all flex items-center gap-2 text-sm">
                <i data-lucide="award" class="w-5 h-5"></i>
                Unggah Master Akreditasi
            </button>
        </div>
    </div>

    <div class="glass rounded-[2.5rem] overflow-hidden shadow-sm border border-white/50 mb-12">
        <div class="p-6 border-b border-white/20 bg-white/40 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <h3 class="font-bold text-slate-800 outfit text-base md:text-lg">Daftar Master Akreditasi</h3>
            <div class="relative w-full sm:w-64">
                <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-4 top-1/2 -translate-y-1/2"></i>
                <input aria-label="Cari Prodi" type="text" id="searchAccred" placeholder="Cari Prodi..." class="w-full pl-10 pr-4 py-2.5 bg-white/60 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-emerald-500 transition-all shadow-inner">
            </div>
        </div>
        
        <?php if (empty($accred_certs)): ?>
            <div class="p-12 text-center">
                <div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-400 shadow-inner">
                    <i data-lucide="award" class="w-8 h-8"></i>
                </div>
                <p class="font-bold text-slate-500">Master Akreditasi Kosong</p>
                <p class="text-xs text-slate-400 mt-1">Belum ada sertifikat akreditasi prodi yang diunggah.</p>
            </div>
        <?php else: ?>
            <!-- Desktop Table View -->
            <div class="hidden md:block overflow-x-auto custom-scrollbar">
                <table class="w-full text-left border-collapse min-w-[800px]">
                    <thead>
                        <tr class="bg-white/20 border-b border-white/20">
                            <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Kode Prodi</th>
                            <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Nama Sertifikat</th>
                            <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Masa Berlaku</th>
                            <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">File PDF</th>
                            <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Diunggah Oleh</th>
                            <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Tanggal</th>
                            <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="accredTableBody" class="divide-y divide-white/20">
                        <?php foreach ($accred_certs as $ac): ?>
                            <tr class="hover:bg-white/50 transition-all accred-row">
                                <td class="px-8 py-4 font-mono font-bold text-slate-700 accred-major uppercase"><?php echo htmlspecialchars($ac->major_code); ?></td>
                                <td class="px-8 py-4 font-semibold text-slate-800"><?php echo htmlspecialchars($ac->certificate_name); ?></td>
                                <td class="px-8 py-4">
                                    <span class="px-3 py-1 bg-emerald-50 text-emerald-600 rounded-lg text-xs font-bold border border-emerald-100">
                                        <?php echo htmlspecialchars($ac->start_year . ' - ' . $ac->end_year); ?>
                                    </span>
                                </td>
                                <td class="px-8 py-4">
                                    <a href="serve_document.php?ctx=accreditation&id=<?php echo (int)$ac->id; ?>" target="_blank" class="flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-emerald-600 transition-colors group">
                                        <div class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center group-hover:bg-emerald-100 transition-colors shadow-sm">
                                            <i data-lucide="external-link" class="w-4 h-4"></i>
                                        </div>
                                        <span class="truncate max-w-[150px]" title="<?php echo e(basename($ac->file_path)); ?>"><?php echo e(basename($ac->file_path)); ?></span>
                                    </a><?php if (!$file_exists_cache($ac->file_path)): ?><span class="ml-2 px-2 py-0.5 bg-red-100 text-red-600 rounded-md text-[10px] font-black uppercase tracking-wider align-middle" title="Berkas tidak ditemukan di server">Berkas Hilang</span><?php endif; ?>
                                </td>
                                <td class="px-8 py-4 text-xs font-medium text-slate-500"><?php echo htmlspecialchars($ac->uploader_name); ?></td>
                                <td class="px-8 py-4 text-xs font-medium text-slate-500"><?php echo date('d M Y, H:i', strtotime($ac->created_at)); ?></td>
                                <td class="px-8 py-4 text-right">
                                    <form action="handlers/admin_accreditation_handler.php" method="POST" onsubmit="confirmAction(event, this, 'Yakin ingin menghapus master akreditasi ini?');" class="inline-block">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo e($ac->id); ?>">
                                        <button type="submit" class="w-8 h-8 flex items-center justify-center bg-red-50 text-red-500 rounded-lg border border-red-100 hover:bg-red-500 hover:text-white transition-all shadow-sm" title="Hapus Master Akreditasi">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Card View -->
            <div class="md:hidden p-4 space-y-4" id="accredMobileBody">
                <?php foreach ($accred_certs as $ac): ?>
                    <div class="bg-white/60 p-5 rounded-2xl border border-slate-100 shadow-sm space-y-4 accred-row">
                        <div class="flex items-start justify-between gap-3 border-b border-slate-100 pb-3">
                            <div>
                                <span class="px-2.5 py-1 bg-slate-100 text-slate-700 font-mono font-bold text-xs rounded-lg accred-major uppercase"><?php echo htmlspecialchars($ac->major_code); ?></span>
                                <h4 class="font-bold text-slate-800 text-base mt-2"><?php echo htmlspecialchars($ac->certificate_name); ?></h4>
                            </div>
                            <span class="px-3 py-1 bg-emerald-50 text-emerald-600 rounded-lg text-xs font-bold border border-emerald-100 shrink-0">
                                <?php echo htmlspecialchars($ac->start_year . ' - ' . $ac->end_year); ?>
                            </span>
                        </div>
                        
                        <div class="flex items-center justify-between gap-2 text-xs text-slate-500 pt-1">
                            <div class="flex items-center gap-1.5">
                                <i data-lucide="user" class="w-3.5 h-3.5 text-slate-400"></i>
                                <span><?php echo htmlspecialchars($ac->uploader_name); ?></span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <i data-lucide="calendar" class="w-3.5 h-3.5 text-slate-400"></i>
                                <span><?php echo date('d M Y', strtotime($ac->created_at)); ?></span>
                            </div>
                        </div>

                        <div class="flex items-center justify-between gap-3 pt-2 border-t border-slate-100">
                            <a href="serve_document.php?ctx=accreditation&id=<?php echo (int)$ac->id; ?>" target="_blank" class="flex-1 flex items-center justify-center gap-2 py-2.5 bg-slate-50 hover:bg-emerald-50 text-slate-600 hover:text-emerald-600 rounded-xl font-bold text-xs border border-slate-100 transition-all shadow-sm">
                                <i data-lucide="external-link" class="w-4 h-4"></i>
                                <span>Lihat File PDF</span>
                            </a><?php if (!$file_exists_cache($ac->file_path)): ?><span class="ml-2 px-2 py-0.5 bg-red-100 text-red-600 rounded-md text-[10px] font-black uppercase tracking-wider align-middle" title="Berkas tidak ditemukan di server">Berkas Hilang</span><?php endif; ?>
                            <form action="handlers/admin_accreditation_handler.php" method="POST" onsubmit="confirmAction(event, this, 'Yakin ingin menghapus master akreditasi ini?');" class="shrink-0">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo e($ac->id); ?>">
                                <button type="submit" class="w-10 h-10 flex items-center justify-center bg-red-50 text-red-500 rounded-xl border border-red-100 hover:bg-red-500 hover:text-white transition-all shadow-sm" title="Hapus Master Akreditasi">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Student Repository Section -->
    <div class="mb-8 px-1 flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div>
            <h2 class="text-xl md:text-2xl font-bold outfit text-slate-800 tracking-tight">Repositori Dokumen Resmi (Mahasiswa)</h2>
            <p class="text-slate-400 text-xs md:text-sm mt-1 font-medium leading-relaxed">Unggah dan kelola softfile resmi per mahasiswa (NIM) seperti Ijazah dan Transkrip Nilai sebagai dasar Legalisir.</p>
        </div>
        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 shrink-0 w-full md:w-auto mt-2 md:mt-0">
            <button onclick="document.getElementById('uploadModal').classList.remove('hidden')" class="w-full sm:w-auto justify-center bg-blue-600 text-white px-6 py-3.5 rounded-2xl font-bold shadow-lg shadow-blue-200 hover:bg-blue-700 active:scale-95 transition-all flex items-center gap-2 text-sm">
                <i data-lucide="upload-cloud" class="w-5 h-5"></i>
                Unggah Dokumen
            </button>
            <button onclick="document.getElementById('bulkUploadModal').classList.remove('hidden')" class="w-full sm:w-auto justify-center bg-purple-600 text-white px-6 py-3.5 rounded-2xl font-bold shadow-lg shadow-purple-200 hover:bg-purple-700 active:scale-95 transition-all flex items-center gap-2 text-sm">
                <i data-lucide="layers" class="w-5 h-5"></i>
                Unggah Masal (Bulk)
            </button>
        </div>
    </div>

    <div class="glass rounded-[2.5rem] overflow-hidden shadow-sm border border-white/50 mb-12">
        <div class="p-6 border-b border-white/20 bg-white/40 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <h3 class="font-bold text-slate-800 outfit text-base md:text-lg">Daftar Dokumen Repositori</h3>
            <div class="relative w-full sm:w-64">
                <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-4 top-1/2 -translate-y-1/2"></i>
                <input aria-label="Cari NIM" type="text" id="searchRepo" placeholder="Cari NIM..." class="w-full pl-10 pr-4 py-2.5 bg-white/60 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-blue-500 transition-all shadow-inner">
            </div>
        </div>
        
        <?php if (empty($documents)): ?>
            <div class="p-12 text-center">
                <div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-400 shadow-inner">
                    <i data-lucide="database" class="w-8 h-8"></i>
                </div>
                <p class="font-bold text-slate-500">Repositori Kosong</p>
                <p class="text-xs text-slate-400 mt-1">Belum ada dokumen resmi yang diunggah.</p>
            </div>
        <?php else: ?>
            <!-- Desktop Table View -->
            <div class="hidden md:block overflow-x-auto custom-scrollbar">
                <table class="w-full text-left border-collapse min-w-[800px]">
                    <thead>
                        <tr class="bg-white/20 border-b border-white/20">
                            <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">NIM</th>
                            <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Jenis Dokumen</th>
                            <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">File</th>
                            <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Diunggah Oleh</th>
                            <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Tanggal</th>
                            <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="repoTableBody" class="divide-y divide-white/20">
                        <?php foreach ($documents as $doc): ?>
                            <tr class="hover:bg-white/50 transition-all repo-row">
                                <td class="px-8 py-4 font-mono font-bold text-slate-700 repo-nim uppercase"><?php echo htmlspecialchars($doc->nim); ?></td>
                                <td class="px-8 py-4">
                                    <span class="px-3 py-1 bg-blue-50 text-blue-600 rounded-lg text-xs font-bold border border-blue-100 capitalize">
                                        <?php echo e(str_replace('_', ' ', $doc->document_type)); ?>
                                    </span>
                                </td>
                                <td class="px-8 py-4">
                                    <a href="serve_document.php?ctx=repository&id=<?php echo (int)$doc->id; ?>" target="_blank" class="flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-blue-600 transition-colors group">
                                        <div class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center group-hover:bg-blue-100 transition-colors shadow-sm">
                                            <i data-lucide="external-link" class="w-4 h-4"></i>
                                        </div>
                                        <span class="truncate max-w-[150px]" title="<?php echo e(basename($doc->file_path)); ?>"><?php echo e(basename($doc->file_path)); ?></span>
                                    </a><?php if (!$file_exists_cache($doc->file_path)): ?><span class="ml-2 px-2 py-0.5 bg-red-100 text-red-600 rounded-md text-[10px] font-black uppercase tracking-wider align-middle" title="Berkas tidak ditemukan di server">Berkas Hilang</span><?php endif; ?>
                                </td>
                                <td class="px-8 py-4 text-xs font-medium text-slate-500"><?php echo htmlspecialchars($doc->uploader_name); ?></td>
                                <td class="px-8 py-4 text-xs font-medium text-slate-500"><?php echo date('d M Y, H:i', strtotime($doc->created_at)); ?></td>
                                <td class="px-8 py-4 text-right">
                                    <form action="handlers/admin_repository_handler.php" method="POST" onsubmit="confirmAction(event, this, 'Yakin ingin menghapus dokumen ini dari repositori? Legalisir yang sudah menggunakan dokumen ini tidak akan terpengaruh jika file sudah disalin/digenerate, tapi sebaiknya hati-hati.');" class="inline-block">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo e($doc->id); ?>">
                                        <button type="submit" class="w-8 h-8 flex items-center justify-center bg-red-50 text-red-500 rounded-lg border border-red-100 hover:bg-red-500 hover:text-white transition-all shadow-sm" title="Hapus Dokumen">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Card View -->
            <div class="md:hidden p-4 space-y-4" id="repoMobileBody">
                <?php foreach ($documents as $doc): ?>
                    <div class="bg-white/60 p-5 rounded-2xl border border-slate-100 shadow-sm space-y-4 repo-row">
                        <div class="flex items-center justify-between gap-3 border-b border-slate-100 pb-3">
                            <div>
                                <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-0.5">NIM Mahasiswa</span>
                                <h4 class="font-mono font-bold text-slate-800 text-base repo-nim uppercase"><?php echo htmlspecialchars($doc->nim); ?></h4>
                            </div>
                            <span class="px-3 py-1 bg-blue-50 text-blue-600 rounded-lg text-xs font-bold border border-blue-100 capitalize shrink-0">
                                <?php echo e(str_replace('_', ' ', $doc->document_type)); ?>
                            </span>
                        </div>
                        
                        <div class="flex items-center justify-between gap-2 text-xs text-slate-500 pt-1">
                            <div class="flex items-center gap-1.5">
                                <i data-lucide="user" class="w-3.5 h-3.5 text-slate-400"></i>
                                <span><?php echo htmlspecialchars($doc->uploader_name); ?></span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <i data-lucide="calendar" class="w-3.5 h-3.5 text-slate-400"></i>
                                <span><?php echo date('d M Y', strtotime($doc->created_at)); ?></span>
                            </div>
                        </div>

                        <div class="flex items-center justify-between gap-3 pt-2 border-t border-slate-100">
                            <a href="serve_document.php?ctx=repository&id=<?php echo (int)$doc->id; ?>" target="_blank" class="flex-1 flex items-center justify-center gap-2 py-2.5 bg-slate-50 hover:bg-blue-50 text-slate-600 hover:text-blue-600 rounded-xl font-bold text-xs border border-slate-100 transition-all shadow-sm">
                                <i data-lucide="external-link" class="w-4 h-4"></i>
                                <span>Lihat Dokumen</span>
                            </a><?php if (!$file_exists_cache($doc->file_path)): ?><span class="ml-2 px-2 py-0.5 bg-red-100 text-red-600 rounded-md text-[10px] font-black uppercase tracking-wider align-middle" title="Berkas tidak ditemukan di server">Berkas Hilang</span><?php endif; ?>
                            <form action="handlers/admin_repository_handler.php" method="POST" onsubmit="confirmAction(event, this, 'Yakin ingin menghapus dokumen ini dari repositori? Legalisir yang sudah menggunakan dokumen ini tidak akan terpengaruh jika file sudah disalin/digenerate, tapi sebaiknya hati-hati.');" class="shrink-0">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo e($doc->id); ?>">
                                <button type="submit" class="w-10 h-10 flex items-center justify-center bg-red-50 text-red-500 rounded-xl border border-red-100 hover:bg-red-500 hover:text-white transition-all shadow-sm" title="Hapus Dokumen">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php
        render_pager_summary(count($documents), $hal_dok['total'], $hal_dok['hal'], $hal_dok['total_hal'], 'dokumen');
        render_pager($dok_url, $hal_dok['hal'], $hal_dok['total_hal']);
        ?>
    </div>
</div>

<!-- Upload Master Accreditation Modal -->
<div id="uploadAccredModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm hidden">
    <div class="glass w-full max-w-lg p-8 rounded-[2.5rem] shadow-2xl relative">
        <button onclick="document.getElementById('uploadAccredModal').classList.add('hidden')" class="absolute top-6 right-6 text-slate-400 hover:text-slate-600">
            <i data-lucide="x" class="w-6 h-6"></i>
        </button>
        
        <div class="flex items-center gap-4 mb-6">
            <div class="w-12 h-12 bg-emerald-100 text-emerald-600 rounded-2xl flex items-center justify-center shadow-inner">
                <i data-lucide="award" class="w-6 h-6"></i>
            </div>
            <div>
                <h2 class="text-2xl font-bold outfit text-slate-800">Unggah Master Akreditasi</h2>
                <p class="text-xs text-slate-500 font-medium">Tambahkan sertifikat akreditasi prodi yang berlaku 5 tahun.</p>
            </div>
        </div>
        
        <form action="handlers/admin_accreditation_handler.php" method="POST" enctype="multipart/form-data" class="space-y-5">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="upload">
            
            <div>
                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 ml-1" for="f_major_code">Program Studi <span class="text-red-500">*</span></label>
                <div class="relative">
                    <select id="f_major_code" name="major_code" required class="w-full px-4 py-3 rounded-xl bg-white/70 border border-slate-200 focus:border-emerald-500 outline-none text-sm cursor-pointer appearance-none shadow-sm transition-all">
                        <option value="">-- Pilih Program Studi --</option>
                        <?php foreach ($all_majors as $maj): ?>
                            <option value="<?php echo htmlspecialchars($maj->major_code); ?>"><?php echo htmlspecialchars($maj->major_code . ' - ' . $maj->major_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i data-lucide="chevron-down" class="w-4 h-4 absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none"></i>
                </div>
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 ml-1" for="f_certificate_name">Nama Sertifikat <span class="text-red-500">*</span></label>
                <input id="f_certificate_name" type="text" name="certificate_name" required placeholder="Contoh: Akreditasi S1 Kedokteran (2020-2025)" class="w-full px-4 py-3 rounded-xl bg-white/70 border border-slate-200 focus:border-emerald-500 outline-none text-sm transition-all shadow-sm">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 ml-1" for="f_start_year">Tahun Mulai <span class="text-red-500">*</span></label>
                    <input id="f_start_year" type="number" name="start_year" required min="1990" max="2100" placeholder="Contoh: 2020" class="w-full px-4 py-3 rounded-xl bg-white/70 border border-slate-200 focus:border-emerald-500 outline-none text-sm transition-all shadow-sm">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 ml-1" for="f_end_year">Tahun Akhir <span class="text-red-500">*</span></label>
                    <input id="f_end_year" type="number" name="end_year" required min="1990" max="2100" placeholder="Contoh: 2025" class="w-full px-4 py-3 rounded-xl bg-white/70 border border-slate-200 focus:border-emerald-500 outline-none text-sm transition-all shadow-sm">
                </div>
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 ml-1" for="f_file">File Sertifikat (PDF/JPG/PNG) <span class="text-red-500">*</span></label>
                <input id="f_file" type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.webp" class="w-full text-sm text-slate-500 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100 cursor-pointer bg-white/70 border border-slate-200 rounded-xl p-1 shadow-sm transition-all">
            </div>
            
            <div class="pt-2">
                <button type="submit" class="w-full py-3.5 bg-emerald-600 text-white rounded-xl font-bold shadow-lg shadow-emerald-200 hover:bg-emerald-700 active:scale-[0.98] transition-all flex items-center justify-center gap-2">
                    <i data-lucide="upload" class="w-4 h-4"></i>
                    Simpan Master Akreditasi
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Upload Modal -->
<div id="uploadModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm hidden">
    <div class="glass w-full max-w-lg p-8 rounded-[2.5rem] shadow-2xl relative">
        <button onclick="document.getElementById('uploadModal').classList.add('hidden')" class="absolute top-6 right-6 text-slate-400 hover:text-slate-600">
            <i data-lucide="x" class="w-6 h-6"></i>
        </button>
        
        <div class="flex items-center gap-4 mb-6">
            <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-2xl flex items-center justify-center shadow-inner">
                <i data-lucide="file-up" class="w-6 h-6"></i>
            </div>
            <div>
                <h2 class="text-2xl font-bold outfit text-slate-800">Unggah Dokumen</h2>
                <p class="text-xs text-slate-500 font-medium">Tambahkan softfile resmi ke Repositori.</p>
            </div>
        </div>
        
        <form action="handlers/admin_repository_handler.php" method="POST" enctype="multipart/form-data" class="space-y-5">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="upload">
            
            <div>
                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 ml-1" for="f_nim">NIM Alumni <span class="text-red-500">*</span></label>
                <input id="f_nim" type="text" name="nim" required placeholder="Contoh: J500230013" class="w-full px-4 py-3 rounded-xl bg-white/70 border border-slate-200 focus:border-blue-500 outline-none uppercase font-mono text-sm transition-all shadow-sm">
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 ml-1" for="f_document_type">Jenis Dokumen <span class="text-red-500">*</span></label>
                <div class="relative">
                    <select id="f_document_type" name="document_type" required class="w-full px-4 py-3 rounded-xl bg-white/70 border border-slate-200 focus:border-blue-500 outline-none text-sm cursor-pointer appearance-none shadow-sm transition-all">
                        <option value="">-- Pilih Jenis Dokumen --</option>
                        <?php foreach ($doc_types as $dt): ?>
                            <option value="<?php echo htmlspecialchars($dt->id); ?>"><?php echo htmlspecialchars($dt->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i data-lucide="chevron-down" class="w-4 h-4 absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none"></i>
                </div>
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 ml-1" for="f_file_2">File Dokumen (PDF/JPG/PNG) <span class="text-red-500">*</span></label>
                <input id="f_file_2" type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.webp" class="w-full text-sm text-slate-500 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer bg-white/70 border border-slate-200 rounded-xl p-1 shadow-sm transition-all">
            </div>
            
            <div class="pt-2">
                <button type="submit" class="w-full py-3.5 bg-blue-600 text-white rounded-xl font-bold shadow-lg shadow-blue-200 hover:bg-blue-700 active:scale-[0.98] transition-all flex items-center justify-center gap-2">
                    <i data-lucide="upload" class="w-4 h-4"></i>
                    Simpan ke Repositori
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // Simple client-side search
    document.getElementById('searchRepo')?.addEventListener('input', function(e) {
        const term = e.target.value.toLowerCase();
        document.querySelectorAll('.repo-row').forEach(row => {
            const nim = row.querySelector('.repo-nim').textContent.toLowerCase();
            if (nim.includes(term)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
</script>

<!-- Bulk Upload Modal -->
<div id="bulkUploadModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm hidden">
    <div class="glass w-full max-w-2xl p-8 rounded-[2.5rem] shadow-2xl relative max-h-[90vh] overflow-y-auto custom-scrollbar">
        <button id="closeBulkTopBtn" onclick="closeBulkModal()" class="absolute top-6 right-6 text-slate-400 hover:text-slate-600">
            <i data-lucide="x" class="w-6 h-6"></i>
        </button>
        
        <div class="flex items-center gap-4 mb-6">
            <div class="w-12 h-12 bg-purple-100 text-purple-600 rounded-2xl flex items-center justify-center shadow-inner shrink-0">
                <i data-lucide="layers" class="w-6 h-6"></i>
            </div>
            <div>
                <h2 class="text-2xl font-bold outfit text-slate-800">Unggah Masal (Bulk Upload)</h2>
                <p class="text-xs text-slate-500 font-medium">Unggah hingga 200+ file sekaligus menggunakan antrean AJAX otomatis.</p>
            </div>
        </div>

        <!-- Panduan Penamaan File -->
        <div class="mb-6 p-5 bg-amber-50 border border-amber-200 rounded-2xl text-amber-800">
            <h4 class="font-bold text-sm flex items-center gap-2 mb-2">
                <i data-lucide="alert-triangle" class="w-4 h-4 text-amber-600"></i>
                Aturan Penamaan File Sangat Penting!
            </h4>
            <p class="text-xs leading-relaxed mb-3">
                Agar sistem dapat mendeteksi NIM dan Jenis Dokumen secara otomatis, setiap file <b>wajib</b> dinamai dengan format <code class="bg-amber-100 px-1.5 py-0.5 rounded font-mono font-bold text-amber-900">jenisdokumen_nim.ekstensi</code>.
            </p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-[11px] bg-white/60 p-3 rounded-xl border border-amber-100">
                <div>
                    <span class="font-bold text-slate-700 block mb-1">✅ Contoh Benar (Sesuai Pengaturan Sistem):</span>
                    <ul class="list-disc list-inside space-y-1 text-slate-600 font-mono">
                        <?php 
                        $sample_nims = ['j500230013', 'j500230014', 'j500230015', 'j500230016'];
                        $idx = 0;
                        foreach ($doc_types as $dt) {
                            $norm_id = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $dt->id)));
                            $ext = ($idx % 2 === 0) ? 'pdf' : 'jpg';
                            $nim = $sample_nims[$idx % count($sample_nims)];
                            echo '<li>' . htmlspecialchars($norm_id . '_' . $nim . '.' . $ext) . ' <span class="text-slate-400 font-sans text-[10px]">(' . htmlspecialchars($dt->name) . ')</span></li>';
                            $idx++;
                        }
                        ?>
                    </ul>
                </div>
                <div>
                    <span class="font-bold text-slate-700 block mb-1">❌ Contoh Salah:</span>
                    <ul class="list-disc list-inside space-y-1 text-red-600 font-mono">
                        <li>j500230013.pdf <span class="text-slate-400 font-sans">(tanpa jenis)</span></li>
                        <li>ijazahku.jpg <span class="text-slate-400 font-sans">(tanpa nim)</span></li>
                        <li>scan_ijazah_asli.pdf <span class="text-slate-400 font-sans">(tanpa nim)</span></li>
                    </ul>
                </div>
            </div>
        </div>
        
        <!-- Input Form / Dropzone -->
        <div id="bulkInputContainer" class="space-y-5">
            <div>
                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-widest mb-2 ml-1">Pilih File (Bisa pilih ratusan file sekaligus) <span class="text-red-500">*</span></label>
                <div class="border-2 border-dashed border-slate-300 hover:border-purple-500 bg-white/50 rounded-2xl p-8 text-center cursor-pointer transition-all group" onclick="document.getElementById('bulkFileInput').click()">
                    <input type="file" id="bulkFileInput" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" class="hidden" onchange="updateBulkFileCount(this)">
                    <div class="w-14 h-14 bg-purple-50 group-hover:bg-purple-100 text-purple-600 rounded-full flex items-center justify-center mx-auto mb-3 transition-all shadow-sm">
                        <i data-lucide="folder-up" class="w-6 h-6"></i>
                    </div>
                    <p id="bulkFileCountText" class="font-bold text-slate-700 text-sm">Klik untuk memilih file dari komputer</p>
                    <p class="text-xs text-slate-400 mt-1">Mendukung PDF, JPG, PNG, WEBP (Maks 200+ file)</p>
                </div>
            </div>

            <button type="button" id="startBulkBtn" onclick="startBulkUpload()" disabled class="w-full py-4 bg-purple-600 disabled:bg-slate-300 disabled:cursor-not-allowed text-white rounded-2xl font-bold shadow-lg shadow-purple-200 hover:bg-purple-700 active:scale-[0.98] transition-all flex items-center justify-center gap-2 text-sm">
                <i data-lucide="play" class="w-4 h-4"></i>
                Mulai Unggah Masal
            </button>
        </div>

        <!-- Progress Container (Hidden Initially) -->
        <div id="bulkProgressContainer" class="hidden space-y-6 pt-2">
            <!-- Progress Bar -->
            <div>
                <div class="flex justify-between text-xs font-bold text-slate-700 mb-2">
                    <span id="bulkStatusLabel">Mengunggah file 1 dari 150...</span>
                    <span id="bulkPercentageLabel">0%</span>
                </div>
                <div class="w-full h-4 bg-slate-100 rounded-full overflow-hidden p-0.5 shadow-inner border border-slate-200/60">
                    <div id="bulkProgressBar" class="h-full bg-gradient-to-r from-purple-600 to-blue-600 rounded-full w-0 transition-all duration-300 shadow-sm"></div>
                </div>
            </div>

            <!-- Stats & Counters -->
            <div class="grid grid-cols-3 gap-4">
                <div class="bg-white/80 border border-slate-100 p-4 rounded-2xl text-center shadow-sm">
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-1">Total File</span>
                    <span id="statTotal" class="text-2xl font-black outfit text-slate-800">0</span>
                </div>
                <div class="bg-green-50 border border-green-100 p-4 rounded-2xl text-center shadow-sm">
                    <span class="text-[10px] font-black text-green-600 uppercase tracking-widest block mb-1">Berhasil</span>
                    <span id="statSuccess" class="text-2xl font-black outfit text-green-700">0</span>
                </div>
                <div class="bg-red-50 border border-red-100 p-4 rounded-2xl text-center shadow-sm">
                    <span class="text-[10px] font-black text-red-600 uppercase tracking-widest block mb-1">Gagal</span>
                    <span id="statFailed" class="text-2xl font-black outfit text-red-700">0</span>
                </div>
            </div>

            <!-- Log Error (if any) -->
            <div id="bulkErrorLogBox" class="hidden">
                <span class="text-[11px] font-bold text-slate-500 uppercase tracking-widest block mb-2 ml-1">Rincian File Gagal</span>
                <div class="bg-white border border-red-100 rounded-2xl max-h-40 overflow-y-auto custom-scrollbar p-3 space-y-2 text-xs font-mono">
                    <ul id="bulkErrorList" class="space-y-1 text-red-600"></ul>
                </div>
            </div>

            <button type="button" id="finishBulkBtn" onclick="location.reload()" class="hidden w-full py-4 bg-blue-600 text-white rounded-2xl font-bold shadow-lg shadow-blue-200 hover:bg-blue-700 active:scale-[0.98] transition-all flex items-center justify-center gap-2 text-sm">
                <i data-lucide="check-circle" class="w-5 h-5"></i>
                Selesai & Muat Ulang Halaman
            </button>
        </div>
    </div>
</div>

<script>
    let bulkFileList = [];
    let currentBulkIdx = 0;
    let bulkSuccessCount = 0;
    let bulkFailedCount = 0;
    let isUploadingBulk = false;

    function updateBulkFileCount(input) {
        const count = input.files.length;
        const textElem = document.getElementById('bulkFileCountText');
        const startBtn = document.getElementById('startBulkBtn');
        
        if (count > 0) {
            textElem.textContent = `${count} file terpilih`;
            textElem.classList.add('text-purple-600');
            startBtn.disabled = false;
        } else {
            textElem.textContent = 'Klik untuk memilih file dari komputer';
            textElem.classList.remove('text-purple-600');
            startBtn.disabled = true;
        }
    }

    function closeBulkModal() {
        if (isUploadingBulk) {
            Swal.fire({
                title: 'Proses Sedang Berlangsung',
                text: 'Proses unggah sedang berlangsung. Yakin ingin menutup? Proses yang sudah berhasil tidak akan dibatalkan.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Ya, Tutup',
                cancelButtonText: 'Batal',
                borderRadius: '1.5rem',
                customClass: {
                    popup: 'rounded-[2rem] border border-slate-100 shadow-2xl outfit',
                    title: 'text-xl font-black text-slate-800',
                    confirmButton: 'px-6 py-3 bg-red-600 text-white font-bold rounded-2xl shadow-lg shadow-red-200 hover:bg-red-700 transition-all',
                    cancelButton: 'px-6 py-3 bg-slate-500 text-white font-bold rounded-2xl shadow-lg shadow-slate-200 hover:bg-slate-600 transition-all'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('bulkUploadModal').classList.add('hidden');
                }
            });
            return;
        }
        document.getElementById('bulkUploadModal').classList.add('hidden');
    }

    function startBulkUpload() {
        const input = document.getElementById('bulkFileInput');
        if (!input.files || input.files.length === 0) return;

        bulkFileList = Array.from(input.files);
        currentBulkIdx = 0;
        bulkSuccessCount = 0;
        bulkFailedCount = 0;
        isUploadingBulk = true;

        // Update UI state
        document.getElementById('bulkInputContainer').classList.add('hidden');
        document.getElementById('bulkProgressContainer').classList.remove('hidden');
        document.getElementById('closeBulkTopBtn').classList.add('hidden');
        
        document.getElementById('statTotal').textContent = bulkFileList.length;
        document.getElementById('statSuccess').textContent = '0';
        document.getElementById('statFailed').textContent = '0';
        document.getElementById('bulkErrorList').innerHTML = '';
        document.getElementById('bulkErrorLogBox').classList.add('hidden');
        document.getElementById('finishBulkBtn').classList.add('hidden');

        // Start recursive upload
        uploadNextBulkItem();
    }

    function uploadNextBulkItem() {
        if (currentBulkIdx >= bulkFileList.length) {
            // Completed!
            isUploadingBulk = false;
            document.getElementById('bulkStatusLabel').textContent = 'Proses Unggah Selesai!';
            document.getElementById('bulkPercentageLabel').textContent = '100%';
            document.getElementById('bulkProgressBar').style.width = '100%';
            document.getElementById('finishBulkBtn').classList.remove('hidden');
            document.getElementById('closeBulkTopBtn').classList.remove('hidden');
            return;
        }

        const file = bulkFileList[currentBulkIdx];
        const currentNum = currentBulkIdx + 1;
        const totalNum = bulkFileList.length;
        
        // Update labels
        document.getElementById('bulkStatusLabel').textContent = `Mengunggah file ${currentNum} dari ${totalNum}... (${file.name})`;
        const percentage = Math.round((currentBulkIdx / totalNum) * 100);
        document.getElementById('bulkPercentageLabel').textContent = `${percentage}%`;
        document.getElementById('bulkProgressBar').style.width = `${percentage}%`;

        // Prepare FormData
        const formData = new FormData();
        formData.append('action', 'bulk_upload_single');
        formData.append('file', file);
        // Get CSRF token from the existing upload form
        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
        formData.append('csrf_token', csrfToken);

        fetch('handlers/admin_repository_handler.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                bulkSuccessCount++;
                document.getElementById('statSuccess').textContent = bulkSuccessCount;
            } else {
                bulkFailedCount++;
                document.getElementById('statFailed').textContent = bulkFailedCount;
                logBulkError(file.name, data.message || 'Gagal tidak diketahui');
            }
            currentBulkIdx++;
            uploadNextBulkItem();
        })
        .catch(err => {
            console.error(err);
            bulkFailedCount++;
            document.getElementById('statFailed').textContent = bulkFailedCount;
            logBulkError(file.name, 'Kesalahan jaringan / server error');
            currentBulkIdx++;
            uploadNextBulkItem();
        });
    }

    function logBulkError(filename, reason) {
        const box = document.getElementById('bulkErrorLogBox');
        const list = document.getElementById('bulkErrorList');
        box.classList.remove('hidden');
        
        const li = document.createElement('li');
        li.innerHTML = `<b>${filename}</b>: ${reason}`;
        list.appendChild(li);
    }

    // Live Search Filtering for Master Accreditation
    document.getElementById('searchAccred')?.addEventListener('input', function(e) {
        const query = e.target.value.toLowerCase().trim();
        // Filter desktop table rows
        document.querySelectorAll('#accredTableBody .accred-row').forEach(row => {
            const major = row.querySelector('.accred-major')?.textContent.toLowerCase() || '';
            const cert = row.querySelector('td:nth-child(2)')?.textContent.toLowerCase() || '';
            if (major.includes(query) || cert.includes(query)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
        // Filter mobile card rows
        document.querySelectorAll('#accredMobileBody .accred-row').forEach(card => {
            const major = card.querySelector('.accred-major')?.textContent.toLowerCase() || '';
            const cert = card.querySelector('h4')?.textContent.toLowerCase() || '';
            if (major.includes(query) || cert.includes(query)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    });

    // Live Search Filtering for Student Repository
    document.getElementById('searchRepo')?.addEventListener('input', function(e) {
        const query = e.target.value.toLowerCase().trim();
        // Filter desktop table rows
        document.querySelectorAll('#repoTableBody .repo-row').forEach(row => {
            const nim = row.querySelector('.repo-nim')?.textContent.toLowerCase() || '';
            if (nim.includes(query)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
        // Filter mobile card rows
        document.querySelectorAll('#repoMobileBody .repo-row').forEach(card => {
            const nim = card.querySelector('.repo-nim')?.textContent.toLowerCase() || '';
            if (nim.includes(query)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    });
</script>
