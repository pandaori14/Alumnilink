<?php
require_once __DIR__ . '/../includes/completeness.php';

// Filters & Search
$search  = $_GET['search'] ?? '';
$major   = $_GET['major'] ?? '';
$year    = $_GET['year'] ?? '';
$sort    = $_GET['sort'] ?? 'name_asc';
$missing = $_GET['missing'] ?? '';   // saring baris yang kolom tertentunya kosong

// Build Query
$where  = " WHERE u.role = 'alumni'";
$params = [];

if ($search) {
    $where .= " AND (u.name LIKE ? OR u.nim LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($major) {
    $where .= " AND u.major = ?";
    $params[] = $major;
}
if ($year) {
    $where .= " AND u.graduation_year = ?";
    $params[] = $year;
}
// Potongan SQL diambil dari daftar tertutup di completeness.php; nilai yang
// tidak dikenali menghasilkan string kosong, jadi tidak dapat disuntikkan.
if ($missing && ($frag = alumni_missing_filter_sql($missing))) {
    $where .= " AND $frag";
}

// Sorting
$sortMap = [
    'name_asc' => 'u.name ASC',
    'name_desc' => 'u.name DESC',
    'year_desc' => 'u.graduation_year DESC',
    'year_asc' => 'u.graduation_year ASC',
    'ipk_desc' => 'u.ipk DESC'
];
$orderBy = $sortMap[$sort] ?? 'u.name ASC';

// ── Paginasi sungguhan ────────────────────────────────────────────────
// Halaman ini sebelumnya menarik SELURUH baris alumni tanpa LIMIT lalu
// menampilkan tombol "1 2" yang tidak tersambung ke apa pun. Pada satu
// angkatan saja itu sudah ratusan kartu dalam satu halaman.
$hal_data = paginate($pdo,
    " FROM users u LEFT JOIN majors m ON u.major = m.major_code $where",
    'u.*, m.major_name', $orderBy, $params, 24);
$alumni              = $hal_data['rows'];
$total_alumni_filter = $hal_data['total'];
$total_hal           = $hal_data['total_hal'];
$hal                 = $hal_data['hal'];

$kelengkapan = alumni_completeness_summary($pdo);

// URL yang mempertahankan seluruh saringan aktif (includes/pagination.php).
$url_dengan = pager_url_builder([
    'page' => 'admin_alumni', 'search' => $search, 'major' => $major,
    'year' => $year, 'sort' => $sort, 'missing' => $missing,
]);

$pesan_galat_alumni = [
    'email_ganda'    => 'E-mail tersebut sudah dipakai oleh ' . htmlspecialchars($_GET['nama'] ?? 'pengguna lain') . '.',
    'nim_ganda'      => 'NIM tersebut sudah dipakai oleh ' . htmlspecialchars($_GET['nama'] ?? 'pengguna lain') . '.',
    'nim_tidak_sah'  => 'NIM harus 6-20 huruf/angka tanpa spasi.',
];

// Fetch distinct majors (joined with official majors table) for filters
$majors = $pdo->query("SELECT DISTINCT m.major_code, m.major_name FROM users u JOIN majors m ON u.major = m.major_code WHERE u.role = 'alumni'")->fetchAll();
$years = $pdo->query("SELECT DISTINCT graduation_year FROM users WHERE role = 'alumni' AND graduation_year IS NOT NULL ORDER BY graduation_year DESC")->fetchAll(PDO::FETCH_COLUMN);

// Fetch official majors from database for modal dropdown
$db_majors_list = $pdo->query("SELECT major_code, major_name FROM majors ORDER BY major_name ASC")->fetchAll();
?>

<div class="max-w-6xl mx-auto">
    <div class="mb-8 px-1">
        <h1 class="text-xl md:text-2xl font-bold outfit text-slate-800 tracking-tight">Database Alumni</h1>
        <p class="text-slate-400 text-xs md:text-sm mt-1 font-medium">Kelola data akademik dan profesional alumni secara mendalam.</p>
    </div>
    <div class="mb-10 flex flex-col md:flex-row justify-end items-start md:items-center gap-6">
        <div class="flex gap-3 w-full md:w-auto">
            <a href="handlers/export_handler.php?type=alumni" class="bg-white/60 border border-slate-200 text-slate-700 px-6 py-3.5 rounded-2xl font-bold hover:bg-white transition-all flex items-center gap-2 shadow-sm">
                <i data-lucide="download" class="w-5 h-5"></i>
                Export Excel
            </a>
            <a href="index.php?page=admin_alumni_import" class="bg-white/60 border border-slate-200 text-slate-700 px-6 py-3.5 rounded-2xl font-bold hover:bg-white transition-all flex items-center gap-2 shadow-sm">
                <i data-lucide="upload-cloud" class="w-5 h-5"></i>
                Impor Massal
            </a>
            <button onclick="openAlumniModal()" class="bg-blue-600 text-white px-6 py-3.5 rounded-2xl font-bold shadow-lg shadow-blue-200 hover:bg-blue-700 transition-all flex items-center gap-2">
                <i data-lucide="user-plus" class="w-5 h-5"></i>
                Tambah Alumni
            </button>
        </div>
    </div>

    <?php if (isset($_GET['error']) && isset($pesan_galat_alumni[$_GET['error']])): ?>
        <div class="mb-6 flex items-start gap-3 p-5 rounded-2xl bg-red-50 border border-red-200">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-600 shrink-0 mt-0.5"></i>
            <div class="text-sm text-red-700 leading-relaxed">
                <span class="font-bold block mb-0.5">Data tidak disimpan</span>
                <?php echo e($pesan_galat_alumni[$_GET['error']]); ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Kelengkapan Data -->
    <?php if ($kelengkapan['total'] > 0): ?>
    <div class="glass p-6 rounded-[2.5rem] shadow-sm mb-6">
        <div class="flex flex-wrap items-center gap-6">
            <div class="flex items-center gap-4">
                <?php $r = $kelengkapan['rata']; $w = $r >= 75 ? '#059669' : ($r >= 50 ? '#d97706' : '#dc2626'); ?>
                <!-- Cincin digambar dengan conic-gradient inline, bukan kelas
                     Tailwind dinamis, supaya tidak menuntut build ulang CSS. -->
                <div class="w-16 h-16 rounded-full flex items-center justify-center shrink-0"
                     style="background: conic-gradient(<?php echo e($w); ?> <?php echo $r * 3.6; ?>deg, #e2e8f0 0deg);">
                    <div class="w-12 h-12 rounded-full bg-white flex items-center justify-center">
                        <span class="text-sm font-black outfit" style="color: <?php echo e($w); ?>"><?php echo e($r); ?>%</span>
                    </div>
                </div>
                <div>
                    <div class="text-sm font-black text-slate-700">Kelengkapan data rata-rata</div>
                    <div class="text-xs text-slate-400 mt-0.5"><?php echo e($kelengkapan['total']); ?> alumni terdaftar</div>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($kelengkapan['rincian'] as $d): if ($d['n'] === 0) continue; ?>
                    <a href="<?php echo e($url_dengan(['missing' => $d['filter'], 'p' => 1])); ?>"
                       class="px-4 py-2 rounded-xl text-xs font-bold transition-all <?php echo e($missing === $d['filter'] ? 'bg-slate-900 text-white' : 'bg-white/60 border border-slate-200 text-slate-600 hover:bg-white'); ?>">
                        <?php echo e($d['n']); ?> <?php echo e($d['label']); ?>
                    </a>
                <?php endforeach; ?>
                <?php if ($missing): ?>
                    <a href="<?php echo e($url_dengan(['missing' => '', 'p' => 1])); ?>" class="px-4 py-2 rounded-xl text-xs font-bold bg-red-50 text-red-600 hover:bg-red-100 transition-all">
                        Hapus saringan
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter & Search Bar -->
    <div class="glass p-6 rounded-[2.5rem] shadow-sm mb-10">
        <form action="index.php" method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <input type="hidden" name="page" value="admin_alumni">
            <input type="hidden" name="missing" value="<?php echo htmlspecialchars($missing); ?>">
            
            <div class="relative">
                <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                <input aria-label="Cari Nama / NIM" type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Cari Nama / NIM..." class="w-full pl-11 pr-5 py-3 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none text-sm transition-all">
            </div>

            <select aria-label="Filter Program Studi" name="major" class="px-5 py-3 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none text-sm appearance-none cursor-pointer">
                <option value="">Semua Program Studi</option>
                <?php foreach ($majors as $m): ?>
                    <option value="<?php echo htmlspecialchars($m->major_code); ?>" <?php echo e($major == $m->major_code ? 'selected' : ''); ?>><?php echo htmlspecialchars($m->major_name); ?></option>
                <?php endforeach; ?>
            </select>

            <select aria-label="Filter Angkatan" name="year" class="px-5 py-3 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none text-sm appearance-none cursor-pointer">
                <option value="">Semua Angkatan</option>
                <?php foreach ($years as $y): ?>
                    <option value="<?php echo e($y); ?>" <?php echo $year == $y ? 'selected' : ''; ?>><?php echo e($y); ?></option>
                <?php endforeach; ?>
            </select>

            <div class="flex gap-2">
                <select name="sort" class="flex-1 px-5 py-3 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none text-sm appearance-none cursor-pointer">
                    <option value="name_asc" <?php echo $sort == 'name_asc' ? 'selected' : ''; ?>>Nama (A-Z)</option>
                    <option value="name_desc" <?php echo $sort == 'name_desc' ? 'selected' : ''; ?>>Nama (Z-A)</option>
                    <option value="year_desc" <?php echo $sort == 'year_desc' ? 'selected' : ''; ?>>Lulus Terbaru</option>
                    <option value="ipk_desc" <?php echo $sort == 'ipk_desc' ? 'selected' : ''; ?>>IPK Tertinggi</option>
                </select>
                <button type="submit" class="bg-blue-600 text-white p-3 rounded-2xl hover:bg-blue-700 transition-all">
                    <i data-lucide="filter" class="w-5 h-5"></i>
                </button>
            </div>
        </form>
    </div>

    <!-- Alumni List -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-10">
        <?php if (empty($alumni)): ?>
            <div class="col-span-full glass p-20 rounded-[3rem] text-center">
                <div class="w-20 h-20 bg-slate-100 text-slate-300 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i data-lucide="user-x" class="w-10 h-10"></i>
                </div>
                <h4 class="text-xl font-bold text-slate-400">Data tidak ditemukan</h4>
                <p class="text-slate-400 mt-2">Coba sesuaikan filter atau kata kunci pencarian Anda.</p>
            </div>
        <?php else: ?>
            <?php foreach ($alumni as $a): ?>
                <div class="glass p-8 rounded-[2.5rem] shadow-sm hover:shadow-xl hover:shadow-blue-900/5 transition-all group relative">
                    <?php
                        // Dihitung dari baris yang SUDAH diambil — tidak ada
                        // kueri tambahan per kartu.
                        $skor    = alumni_completeness_score($a);
                        $kurang  = alumni_completeness_missing($a);
                        $w_skor  = $skor >= 75 ? '#059669' : ($skor >= 50 ? '#d97706' : '#dc2626');
                    ?>
                    <div class="flex items-start justify-between mb-6">
                        <div class="flex items-center gap-3">
                            <img src="avatar.php?name=<?php echo urlencode($a->name); ?>&bg=0066FF&size=64" alt="" class="w-16 h-16 rounded-2xl shadow-lg border-2 border-white">
                            <span class="text-[10px] font-black px-2 py-1 rounded-lg"
                                  style="color: <?php echo e($w_skor); ?>; background: <?php echo e($w_skor); ?>1a;"
                                  title="Kelengkapan data<?php echo $kurang ? '. Belum ada: ' . htmlspecialchars(implode(', ', $kurang)) : ' lengkap'; ?>">
                                <?php echo e($skor); ?>%
                            </span>
                        </div>
                        <div class="flex flex-col items-end gap-2">
                            <span class="px-3 py-1 bg-blue-100 text-blue-600 rounded-full text-[10px] font-bold uppercase tracking-widest">
                                Angkatan <?php echo e($a->graduation_year ?? '-'); ?>
                            </span>
                            <div class="flex gap-1">
                                <button onclick='editAlumni(<?php echo e(json_encode($a)); ?>)' class="p-2 bg-slate-50 text-slate-400 rounded-lg hover:bg-blue-600 hover:text-white transition-all">
                                    <i data-lucide="edit-3" class="w-3 h-3"></i>
                                </button>
                                <button onclick='viewAlumniDetail(<?php echo e(json_encode($a)); ?>)' class="p-2 bg-slate-50 text-slate-400 rounded-lg hover:bg-purple-600 hover:text-white transition-all">
                                    <i data-lucide="eye" class="w-3 h-3"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <h4 class="text-xl font-bold outfit text-slate-800 mb-1"><?php echo e($a->name); ?></h4>
                    <p class="text-sm text-slate-500 mb-4"><?php echo htmlspecialchars($a->major_name ?? $a->major ?? 'Program Studi'); ?></p>

                    <div class="space-y-3 pt-4 border-t border-slate-100">
                        <div class="flex items-center gap-3 text-xs text-slate-600">
                            <i data-lucide="hash" class="w-4 h-4 text-slate-400"></i>
                            NIM: <span class="font-bold text-slate-800"><?php echo e($a->nim ?? $a->id); ?></span>
                        </div>
                        <div class="flex items-center justify-between text-xs text-slate-600">
                            <div class="flex items-center gap-3">
                                <i data-lucide="mail" class="w-4 h-4 text-slate-400"></i>
                                <span class="truncate max-w-[150px] md:max-w-[180px]" title="<?php echo e($a->email); ?>"><?php echo e($a->email); ?></span>
                            </div>
                            <?php if (!empty($a->google_id)): ?>
                                <span class="text-[8px] bg-emerald-50 text-emerald-600 font-black px-1.5 py-0.5 rounded border border-emerald-100 uppercase tracking-wider flex items-center gap-0.5 scale-90 origin-right">
                                    Google
                                </span>
                            <?php else: ?>
                                <span class="text-[8px] bg-slate-50 text-slate-500 font-black px-1.5 py-0.5 rounded border border-slate-100 uppercase tracking-wider flex items-center gap-0.5 scale-90 origin-right">
                                    Sistem
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-3 text-xs text-slate-600">
                            <i data-lucide="award" class="w-4 h-4 text-slate-400"></i>
                            IPK: <span class="font-bold"><?php echo e($a->ipk ?? '0.00'); ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php
    render_pager_summary(count($alumni), $total_alumni_filter, $hal, $total_hal, 'alumni');
    render_pager($url_dengan, $hal, $total_hal);
    ?>
</div>

<!-- Alumni CRUD Modal -->
<div id="alumniModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm hidden">
    <div class="glass w-full max-w-2xl p-10 rounded-[3rem] shadow-2xl relative overflow-y-auto max-h-[95vh] custom-scrollbar">
        <button onclick="document.getElementById('alumniModal').classList.add('hidden')" class="absolute top-8 right-8 text-slate-400 hover:text-slate-600">
            <i data-lucide="x" class="w-6 h-6"></i>
        </button>
        
        <h3 id="modalTitle" class="text-2xl font-bold outfit mb-8">Tambah Data Alumni</h3>
        
        <form action="handlers/admin_alumni_handler.php?action=save" method="POST" class="space-y-6">
            <?php csrf_field(); ?>
            <input type="hidden" name="old_id" id="old_id">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Nama Lengkap</label>
                    <input type="text" name="name" id="a_name" required class="w-full px-5 py-3.5 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">NIM / ID Alumni</label>
                    <input type="text" name="nim" id="a_nim" required class="w-full px-5 py-3.5 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Program Studi</label>
                    <select aria-label="-- Pilih Program Studi --" name="major" id="a_major" required class="w-full px-5 py-3.5 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none appearance-none cursor-pointer">
                        <option value="">-- Pilih Program Studi --</option>
                        <?php foreach ($db_majors_list as $m): ?>
                            <option value="<?php echo htmlspecialchars($m->major_code); ?>"><?php echo htmlspecialchars($m->major_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Tahun Lulus</label>
                    <input aria-label="2024" type="number" name="graduation_year" id="a_year" required class="w-full px-5 py-3.5 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none" placeholder="2024">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">IPK Akhir</label>
                    <input aria-label="3.85" type="number" step="0.01" name="ipk" id="a_ipk" required class="w-full px-5 py-3.5 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none" placeholder="3.85">
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Email</label>
                    <input type="email" name="email" id="a_email" required class="w-full px-5 py-3.5 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Nomor Telepon</label>
                    <input type="text" name="phone" id="a_phone" class="w-full px-5 py-3.5 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none">
                </div>
                <div id="passContainer">
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Password</label>
                    <input aria-label="••••••••" type="password" name="password" id="a_pass" class="w-full px-5 py-3.5 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none" placeholder="••••••••">
                    <p id="passHint" class="text-[10px] text-slate-400 mt-2 ml-1 hidden">Kosongkan jika tidak ingin mengubah password.</p>
                </div>
            </div>

            <div>
                <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Alamat Lengkap</label>
                <textarea name="address" id="a_address" class="w-full px-5 py-3.5 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none h-24"></textarea>
            </div>
            
            <button type="submit" class="w-full py-4 bg-blue-600 text-white rounded-2xl font-bold shadow-lg shadow-blue-200 hover:bg-blue-700 transition-all mt-4">
                Simpan Data Alumni
            </button>
            <div class="h-20 md:hidden"></div>
        </form>
    </div>
</div>

<!-- Alumni Detail Modal (Reuse from previous implementation) -->
<div id="alumniDetailModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm hidden">
    <div class="glass w-full max-w-2xl p-10 rounded-[3rem] shadow-2xl relative overflow-y-auto max-h-[90vh] custom-scrollbar">
        <button onclick="document.getElementById('alumniDetailModal').classList.add('hidden')" class="absolute top-8 right-8 text-slate-400 hover:text-slate-600">
            <i data-lucide="x" class="w-6 h-6"></i>
        </button>
        <!-- Modal content same as before -->
        <div id="detailContent"></div>
        <div class="h-20 md:hidden"></div>
    </div>
</div>

<script>
    function openAlumniModal() {
        document.getElementById('modalTitle').innerText = 'Tambah Data Alumni';
        document.getElementById('old_id').value = '';
        document.getElementById('a_nim').value = '';
        document.getElementById('a_name').value = '';
        document.getElementById('a_major').value = '';
        document.getElementById('a_year').value = '';
        document.getElementById('a_ipk').value = '';
        document.getElementById('a_email').value = '';
        document.getElementById('a_phone').value = '';
        document.getElementById('a_address').value = '';
        document.getElementById('a_pass').required = true;
        document.getElementById('passHint').classList.add('hidden');
        document.getElementById('alumniModal').classList.remove('hidden');
    }

    function editAlumni(a) {
        document.getElementById('modalTitle').innerText = 'Edit Data Alumni';
        document.getElementById('old_id').value = a.id;
        document.getElementById('a_nim').value = a.nim || a.id;
        document.getElementById('a_name').value = a.name;
        document.getElementById('a_major').value = a.major || '';
        document.getElementById('a_year').value = a.graduation_year || '';
        document.getElementById('a_ipk').value = a.ipk || '';
        document.getElementById('a_email').value = a.email;
        document.getElementById('a_phone').value = a.phone || '';
        document.getElementById('a_address').value = a.address || '';
        document.getElementById('a_pass').required = false;
        document.getElementById('passHint').classList.remove('hidden');
        document.getElementById('alumniModal').classList.remove('hidden');
    }

    // Reuse viewAlumniDetail function from before, but with simplified modal injection or just copying the structure
    function viewAlumniDetail(a) {
        // (Similar to before, but showing detailed modal)
        // I'll reuse the logic from the previous turn here
        const content = `
            <div class="flex flex-col md:flex-row gap-8 mb-10">
                <img src="avatar.php?name=${encodeURIComponent(a.name)}&bg=0066FF&size=128" alt="" class="w-32 h-32 rounded-[2rem] shadow-xl border-4 border-white object-cover">
                <div>
                    <h3 class="text-3xl font-bold outfit text-slate-800">${a.name}</h3>
                    <p class="text-blue-600 font-semibold">${a.major_name || a.major || 'Program Studi'}</p>
                    <div class="flex flex-wrap gap-2 mt-3">
                        <span class="px-3 py-1 bg-slate-100 text-slate-600 rounded-full text-[10px] font-bold uppercase tracking-widest">Angkatan ${a.graduation_year || '-'}</span>
                        <span class="px-3 py-1 ${a.google_id ? 'bg-emerald-50 text-emerald-600 border border-emerald-100' : 'bg-slate-50 text-slate-500 border border-slate-100'} rounded-full text-[10px] font-bold uppercase tracking-widest">${a.google_id ? 'Google Auth' : 'System Auth'}</span>
                        <span class="px-3 py-1 ${a.is_verified == 1 ? 'bg-emerald-50 text-emerald-600 border border-emerald-100' : 'bg-amber-50 text-amber-600 border border-amber-100'} rounded-full text-[10px] font-bold uppercase tracking-widest">${a.is_verified == 1 ? 'Verified' : 'Pending'}</span>
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="space-y-6">
                    <h4 class="text-sm font-bold text-slate-400 uppercase tracking-widest border-b border-slate-100 pb-2">Informasi Akademik</h4>
                    <div class="space-y-3">
                        <div class="flex justify-between"><span class="text-sm text-slate-500">NIM:</span><span class="text-sm font-bold text-slate-800">${a.nim || a.id}</span></div>
                        <div class="flex justify-between"><span class="text-sm text-slate-500">IPK Terakhir:</span><span class="text-sm font-bold text-slate-800">${a.ipk || '0.00'}</span></div>
                    </div>
                    <h4 class="text-sm font-bold text-slate-400 uppercase tracking-widest border-b border-slate-100 pb-2 pt-4">Kontak</h4>
                    <div class="space-y-3">
                        <div class="flex justify-between"><span class="text-sm text-slate-500">Email:</span><span class="text-sm font-bold text-slate-800">${a.email}</span></div>
                        <div class="flex justify-between"><span class="text-sm text-slate-500">Telepon:</span><span class="text-sm font-bold text-slate-800">${a.phone || '-'}</span></div>
                    </div>
                </div>
                <div class="space-y-6">
                    <h4 class="text-sm font-bold text-slate-400 uppercase tracking-widest border-b border-slate-100 pb-2">Alamat</h4>
                    <p class="text-sm font-medium text-slate-800 leading-relaxed">${a.address || 'Alamat belum dilengkapi.'}</p>
                </div>
            </div>
        `;
        document.getElementById('detailContent').innerHTML = content;
        document.getElementById('alumniDetailModal').classList.remove('hidden');
    }
</script>
