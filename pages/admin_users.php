<?php
// Halaman ini sebelumnya menarik SELURUH baris users tanpa LIMIT dan tanpa
// cara apa pun untuk mencari. Pada satu angkatan saja itu sudah ratusan
// kartu dalam satu halaman, dan mencari satu orang berarti Ctrl+F.
$u_cari  = trim($_GET['cari'] ?? '');
$u_peran = $_GET['peran'] ?? '';

$where  = " FROM users WHERE 1=1";
$params = [];

if ($u_cari !== '') {
    $where .= " AND (name LIKE ? OR email LIKE ? OR nim LIKE ?)";
    $k = "%$u_cari%";
    array_push($params, $k, $k, $k);
}
// Peran dicocokkan ke daftar tertutup sebelum dipakai.
$peran_sah = ['alumni', 'admin_tracer', 'admin_legalisir', 'keuangan', 'super_admin'];
if (in_array($u_peran, $peran_sah, true)) {
    $where .= " AND role = ?";
    $params[] = $u_peran;
} else {
    $u_peran = '';
}

$hal_data = paginate($pdo, $where, '*', 'created_at DESC', $params, 20);
$users    = $hal_data['rows'];
$u_url    = pager_url_builder(['page' => 'admin_users', 'cari' => $u_cari, 'peran' => $u_peran]);

// Jumlah per peran untuk pintasan saringan — satu kueri agregat.
$u_hitung = [];
foreach ($pdo->query("SELECT role, COUNT(*) n FROM users GROUP BY role") as $r) {
    $u_hitung[$r->role] = (int)$r->n;
}
?>

<div class="max-w-6xl mx-auto">
    <div class="mb-8 px-1">
        <h1 class="text-xl md:text-2xl font-bold outfit text-slate-800 tracking-tight">Kelola User</h1>
        <p class="text-slate-400 text-xs md:text-sm mt-1 font-medium">Manajemen akun alumni, staf, dan administrator sistem.</p>
    </div>
    <div class="flex flex-col md:flex-row md:items-center justify-end gap-6 mb-10 px-1">
        <button onclick="openUserModal()" class="bg-blue-600 text-white px-6 py-3.5 rounded-2xl font-bold shadow-lg shadow-blue-200 hover:bg-blue-700 transition-all flex items-center gap-2 w-full md:w-auto justify-center">
            <i data-lucide="user-plus" class="w-5 h-5"></i>
            Tambah User
        </button>
    </div>

    <!-- Cari &amp; Saring -->
    <div class="glass p-6 rounded-[2rem] shadow-sm mb-8">
        <form action="index.php" method="GET" class="flex flex-col md:flex-row gap-3">
            <input type="hidden" name="page" value="admin_users">
            <div class="relative flex-1">
                <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                <input type="text" name="cari" value="<?php echo htmlspecialchars($u_cari); ?>" placeholder="Cari nama / e-mail / NIM"
                       aria-label="Cari pengguna" class="w-full pl-11 pr-4 py-3 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none text-sm">
            </div>
            <select name="peran" aria-label="Saring peran" class="px-4 py-3 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none text-sm">
                <option value="">Semua peran (<?php echo array_sum($u_hitung); ?>)</option>
                <?php foreach ([
                    'alumni' => 'Alumni', 'admin_tracer' => 'Admin Tracer',
                    'admin_legalisir' => 'Admin Legalisir', 'keuangan' => 'Keuangan',
                    'super_admin' => 'Super Admin',
                ] as $rk => $rl): ?>
                    <option value="<?php echo e($rk); ?>" <?php echo $u_peran === $rk ? 'selected' : ''; ?>>
                        <?php echo e($rl); ?> (<?php echo e($u_hitung[$rk] ?? 0); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="bg-blue-600 text-white px-6 py-3 rounded-2xl hover:bg-blue-700 transition-all font-bold text-sm shrink-0">
                <i data-lucide="filter" class="w-4 h-4 inline"></i> Terapkan
            </button>
        </form>
        <?php if ($u_cari !== '' || $u_peran !== ''): ?>
            <a href="index.php?page=admin_users" class="inline-block mt-3 text-xs font-bold text-red-600 hover:underline">Hapus saringan</a>
        <?php endif; ?>
    </div>

    <?php if (empty($users)): ?>
        <div class="glass p-16 rounded-[3rem] text-center mb-8">
            <div class="w-16 h-16 bg-slate-100 text-slate-300 rounded-full flex items-center justify-center mx-auto mb-4">
                <i data-lucide="user-x" class="w-8 h-8"></i>
            </div>
            <h4 class="text-lg font-bold text-slate-400">Tidak ada pengguna yang cocok</h4>
            <p class="text-sm text-slate-400 mt-1">Coba ubah kata kunci atau saringan peran.</p>
        </div>
    <?php endif; ?>

    <!-- Desktop Table View (Hidden on Mobile) -->
    <div class="hidden md:block glass rounded-[2rem] overflow-hidden shadow-sm border border-white/50">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-white/40 border-b border-white/20">
                    <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">User / Alumni</th>
                    <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">ID / NIM</th>
                    <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Role</th>
                    <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Status</th>
                    <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php foreach ($users as $u): ?>
                    <tr class="hover:bg-white/40 transition-all group">
                        <td class="px-8 py-6">
                            <div class="flex items-center gap-4">
                                <div class="relative">
                                    <img src="avatar.php?name=<?php echo urlencode($u->name); ?>&size=48" alt="" class="w-12 h-12 rounded-2xl border-2 border-white shadow-sm">
                                    <?php if($u->is_verified): ?>
                                        <div class="absolute -bottom-1 -right-1 w-5 h-5 bg-emerald-500 border-2 border-white rounded-full flex items-center justify-center">
                                            <i data-lucide="check" class="w-2.5 h-2.5 text-white"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex flex-col">
                                    <span class="font-black text-slate-800 outfit text-base"><?php echo e($u->name); ?></span>
                                    <div class="flex items-center gap-2 mt-0.5">
                                        <span class="text-[10px] text-slate-400 font-bold tracking-wider opacity-70"><?php echo e($u->email); ?></span>
                                        <?php if (!empty($u->google_id)): ?>
                                            <span class="text-[8px] bg-emerald-50 text-emerald-600 font-black px-1.5 py-0.5 rounded border border-emerald-100 uppercase tracking-wider flex items-center gap-0.5">
                                                <svg class="w-2 h-2" viewBox="0 0 24 24" fill="currentColor"><path d="M12.24 10.285V13.4h6.887C18.2 15.614 15.645 18 12.24 18c-3.86 0-7-3.14-7-7s3.14-7 7-7c1.7 0 3.24.61 4.45 1.64l2.42-2.42C17.305 1.574 14.935 1 12.24 1 6.58 1 2 5.58 2 11.24s4.58 10.24 10.24 10.24c5.9 0 9.8-4.15 9.8-9.98 0-.67-.06-1.3-.18-1.85h-9.62z"/></svg>
                                                Google
                                            </span>
                                        <?php else: ?>
                                            <span class="text-[8px] bg-slate-50 text-slate-500 font-black px-1.5 py-0.5 rounded border border-slate-100 uppercase tracking-wider flex items-center gap-0.5">
                                                <i data-lucide="key" class="w-2 h-2"></i>
                                                Sistem
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-8 py-6 text-sm font-mono font-bold text-slate-500 uppercase"><?php echo htmlspecialchars($u->nim ?: $u->id); ?></td>
                        <td class="px-8 py-6">
                            <?php 
                                $roleColors = [
                                    'alumni' => 'bg-slate-50 text-slate-500 border-slate-100',
                                    'super_admin' => 'bg-blue-50 text-blue-600 border-blue-100',
                                    'admin_legalisir' => 'bg-purple-50 text-purple-600 border-purple-100',
                                    'admin_tracer' => 'bg-orange-50 text-orange-600 border-orange-100',
                                    'keuangan' => 'bg-emerald-50 text-emerald-600 border-emerald-100'
                                ];
                                $roleColor = $roleColors[$u->role] ?? 'bg-slate-50 text-slate-500';
                            ?>
                            <span class="text-[9px] font-black px-3 py-1.5 rounded-xl border uppercase tracking-widest <?php echo e($roleColor); ?>">
                                <?php echo e(str_replace('_', ' ', $u->role)); ?>
                            </span>
                        </td>
                        <td class="px-8 py-6">
                            <?php if ($u->is_verified): ?>
                                <span class="flex items-center gap-1.5 text-emerald-600 font-black text-[10px] uppercase">
                                    <i data-lucide="check-circle" class="w-3.5 h-3.5"></i> Verified
                                </span>
                            <?php else: ?>
                                <span class="flex items-center gap-1.5 text-amber-500 font-black text-[10px] uppercase">
                                    <i data-lucide="clock" class="w-3.5 h-3.5"></i> Pending
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-8 py-6">
                            <div class="flex items-center gap-2">
                                <?php if ($u->role === 'alumni' || !empty($u->nim)): ?>
                                    <a href="https://pddikti.kemdiktisaintek.go.id/search/<?php echo urlencode($u->nim ?: $u->id); ?>" target="_blank" class="w-9 h-9 flex items-center justify-center glass rounded-xl text-blue-500 hover:bg-blue-600 hover:text-white transition-all border border-transparent hover:border-blue-100 shadow-sm" title="Cek PDDikti">
                                        <i data-lucide="graduation-cap" class="w-4 h-4"></i>
                                    </a>
                                <?php endif; ?>
                                <button onclick="viewUserDetail(<?php echo htmlspecialchars(json_encode($u)); ?>)" class="w-9 h-9 flex items-center justify-center glass rounded-xl text-slate-400 hover:text-blue-600 transition-all border border-transparent hover:border-blue-100 shadow-sm" title="Detail">
                                    <i data-lucide="eye" class="w-4 h-4"></i>
                                </button>
                                <button onclick="editUser(<?php echo htmlspecialchars(json_encode($u)); ?>)" class="w-9 h-9 flex items-center justify-center glass rounded-xl text-slate-400 hover:text-purple-600 transition-all border border-transparent hover:border-purple-100 shadow-sm" title="Edit">
                                    <i data-lucide="edit-3" class="w-4 h-4"></i>
                                </button>
                                <?php if ($_SESSION['user_role'] === 'super_admin'): ?>
                                <button onclick="openDeleteModal(<?php echo e(json_encode($u->id)); ?>, <?php echo e(json_encode($u->name)); ?>)" class="w-9 h-9 flex items-center justify-center glass rounded-xl text-slate-400 hover:text-red-600 transition-all border border-transparent hover:border-red-100 shadow-sm" title="Hapus">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile Card View (Hidden on Desktop) -->
    <div class="grid grid-cols-1 gap-6 md:hidden">
        <?php foreach ($users as $u): ?>
            <div class="glass p-6 rounded-[2.5rem] border border-white shadow-sm space-y-6">
                <div class="flex items-center gap-4">
                    <img src="avatar.php?name=<?php echo urlencode($u->name); ?>&size=64" alt="" class="w-16 h-16 rounded-[1.5rem] border-2 border-white shadow-md">
                    <div class="flex-1">
                        <div class="flex items-center justify-between">
                            <h4 class="font-black text-slate-800 outfit text-lg"><?php echo e($u->name); ?></h4>
                            <?php if ($u->is_verified): ?>
                                <i data-lucide="badge-check" class="w-5 h-5 text-emerald-500"></i>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-2 mt-0.5">
                            <span class="text-[10px] font-bold text-slate-400 tracking-wider uppercase opacity-70"><?php echo e($u->email); ?></span>
                            <?php if (!empty($u->google_id)): ?>
                                <span class="text-[8px] bg-emerald-50 text-emerald-600 font-black px-1.5 py-0.5 rounded border border-emerald-100 uppercase tracking-wider flex items-center gap-0.5 scale-90 origin-left">
                                    Google
                                </span>
                            <?php else: ?>
                                <span class="text-[8px] bg-slate-50 text-slate-500 font-black px-1.5 py-0.5 rounded border border-slate-100 uppercase tracking-wider flex items-center gap-0.5 scale-90 origin-left">
                                    Sistem
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-2 mt-2">
                            <span class="text-[8px] font-black px-2 py-1 bg-slate-100 text-slate-500 rounded-lg uppercase tracking-widest border border-slate-200">
                                <?php echo e(str_replace('_', ' ', $u->role)); ?>
                            </span>
                            <span class="text-[8px] font-black text-slate-400 font-mono"><?php echo htmlspecialchars($u->nim ?: $u->id); ?></span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between p-4 bg-slate-50/50 rounded-2xl border border-slate-100">
                    <div>
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">Status Akun</p>
                        <?php if ($u->is_verified): ?>
                            <span class="text-[10px] font-black text-emerald-600 uppercase tracking-tighter flex items-center gap-1">
                                <i data-lucide="check-circle" class="w-3 h-3"></i> Terverifikasi
                            </span>
                        <?php else: ?>
                            <span class="text-[10px] font-black text-amber-500 uppercase tracking-tighter flex items-center gap-1">
                                <i data-lucide="clock" class="w-3 h-3"></i> Menunggu
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="flex gap-2">
                        <?php if ($u->role === 'alumni' || !empty($u->nim)): ?>
                            <a href="https://pddikti.kemdiktisaintek.go.id/search/<?php echo urlencode($u->nim ?: $u->id); ?>" target="_blank" class="w-10 h-10 bg-white border border-slate-100 rounded-xl flex items-center justify-center text-blue-500 shadow-sm active:scale-95 transition-all">
                                <i data-lucide="graduation-cap" class="w-5 h-5"></i>
                            </a>
                        <?php endif; ?>
                        <button onclick="viewUserDetail(<?php echo htmlspecialchars(json_encode($u)); ?>)" class="w-10 h-10 bg-white border border-slate-100 rounded-xl flex items-center justify-center text-blue-600 shadow-sm active:scale-95 transition-all">
                            <i data-lucide="eye" class="w-5 h-5"></i>
                        </button>
                        <button onclick="editUser(<?php echo htmlspecialchars(json_encode($u)); ?>)" class="w-10 h-10 bg-white border border-slate-100 rounded-xl flex items-center justify-center text-purple-600 shadow-sm active:scale-95 transition-all">
                            <i data-lucide="edit-3" class="w-5 h-5"></i>
                        </button>
                        <?php if ($_SESSION['user_role'] === 'super_admin'): ?>
                        <button onclick="openDeleteModal(<?php echo e(json_encode($u->id)); ?>, <?php echo e(json_encode($u->name)); ?>)" class="w-10 h-10 bg-white border border-slate-100 rounded-xl flex items-center justify-center text-red-600 shadow-sm active:scale-95 transition-all">
                            <i data-lucide="trash-2" class="w-5 h-5"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php
    render_pager_summary(count($users), $hal_data['total'], $hal_data['hal'], $hal_data['total_hal'], 'pengguna');
    render_pager($u_url, $hal_data['hal'], $hal_data['total_hal']);
    ?>
</div>
</div>

<!-- User CRUD Modal -->
<div id="userModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm hidden">
    <div class="glass w-full max-w-2xl p-10 rounded-[2.5rem] shadow-2xl relative max-h-[95vh] overflow-y-auto custom-scrollbar">
        <button onclick="document.getElementById('userModal').classList.add('hidden')" class="absolute top-8 right-8 text-slate-400 hover:text-slate-600">
            <i data-lucide="x" class="w-6 h-6"></i>
        </button>
        
        <h3 id="modalTitle" class="text-2xl font-bold outfit mb-8">Tambah User Baru</h3>
        
        <form action="handlers/admin_user_handler.php?action=save" method="POST" class="space-y-6">
            <?php csrf_field(); ?>
            <input type="hidden" name="old_id" id="old_id">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2 ml-1">NIM / ID Alumni</label>
                    <input aria-label="J123456789" type="text" name="nim" id="u_nim" required class="w-full px-5 py-3.5 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none" placeholder="J123456789">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2 ml-1">Nama Lengkap</label>
                    <input aria-label="Ahmad Fauzi" type="text" name="name" id="u_name" required class="w-full px-5 py-3.5 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none" placeholder="Ahmad Fauzi">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2 ml-1">Email</label>
                    <input aria-label="ahmad@example.com" type="email" name="email" id="u_email" required class="w-full px-5 py-3.5 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none" placeholder="ahmad@example.com">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2 ml-1">Role Akses</label>
                    <?php if ($_SESSION['user_role'] === 'super_admin'): ?>
                    <select aria-label="Peran pengguna" name="role" id="u_role" class="w-full px-5 py-3.5 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none appearance-none">
                        <option value="alumni">Alumni</option>
                        <option value="admin_tracer">Admin Tracer</option>
                        <option value="admin_legalisir">Admin Legalisir</option>
                        <option value="keuangan">Keuangan</option>
                        <option value="super_admin">Super Admin</option>
                    </select>
                    <?php else: ?>
                    <input type="text" id="u_role_display" readonly class="w-full px-5 py-3.5 rounded-2xl bg-slate-100 border border-slate-200 text-slate-500 outline-none cursor-not-allowed">
                    <input type="hidden" name="role" id="u_role">
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2 ml-1">Kata Sandi</label>
                    <input aria-label="••••••••" type="password" name="password" id="u_pass" class="w-full px-5 py-3.5 rounded-2xl bg-white/50 border border-slate-200 focus:border-blue-500 outline-none" placeholder="••••••••">
                    <p id="passHint" class="text-[10px] text-slate-400 mt-2 ml-1 hidden">Kosongkan jika tidak ingin mengubah password.</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2 ml-1">Status Verifikasi</label>
                    <div class="flex items-center gap-4 h-[54px]">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="is_verified" value="1" id="u_v_1" class="w-5 h-5 accent-green-600">
                            <span class="text-sm font-medium">Verified</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="is_verified" value="0" id="u_v_0" checked class="w-5 h-5 accent-yellow-600">
                            <span class="text-sm font-medium">Pending</span>
                        </label>
                    </div>
                </div>
            </div>
            
            <button type="submit" class="w-full py-4 bg-blue-600 text-white rounded-2xl font-bold shadow-lg shadow-blue-200 hover:bg-blue-700 transition-all mt-4">
                Simpan Perubahan
            </button>
            <div class="h-20 md:hidden"></div>
        </form>
    </div>
</div>

<!-- User Detail Modal -->
<div id="detailModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm hidden">
    <div class="glass w-full max-w-md p-8 rounded-[2.5rem] shadow-2xl relative max-h-[90vh] overflow-y-auto custom-scrollbar">
        <button onclick="document.getElementById('detailModal').classList.add('hidden')" class="absolute top-6 right-6 text-slate-400 hover:text-slate-600">
            <i data-lucide="x" class="w-6 h-6"></i>
        </button>
        
        <div class="flex flex-col items-center text-center mb-8">
            <img id="d_avatar" src="" class="w-24 h-24 rounded-full border-4 border-white shadow-lg mb-4">
            <h3 id="d_name" class="text-2xl font-bold outfit"></h3>
            <p id="d_role" class="text-xs font-bold text-blue-600 uppercase tracking-widest"></p>
        </div>

        <div class="space-y-4">
            <div class="p-4 bg-white/40 border border-white/20 rounded-2xl">
                <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Email Address</p>
                <p id="d_email" class="text-sm font-medium text-slate-800"></p>
            </div>
            <div class="p-4 bg-white/40 border border-white/20 rounded-2xl">
                <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">NIM / System ID</p>
                <p id="d_id" class="text-sm font-medium text-slate-800 font-mono"></p>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="p-4 bg-white/40 border border-white/20 rounded-2xl">
                    <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Metode Login</p>
                    <p id="d_auth_method" class="text-xs font-bold uppercase tracking-wider"></p>
                </div>
                <div class="p-4 bg-white/40 border border-white/20 rounded-2xl">
                    <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Status Verifikasi</p>
                    <p id="d_status" class="text-xs font-bold uppercase tracking-wider"></p>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="p-4 bg-white/40 border border-white/20 rounded-2xl">
                    <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Joined Date</p>
                    <p id="d_date" class="text-xs font-medium text-slate-800"></p>
                </div>
                <div class="p-4 bg-white/40 border border-white/20 rounded-2xl">
                    <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Last Tracer</p>
                    <p id="d_tracer" class="text-xs font-medium text-slate-800"></p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Custom Delete Confirmation Modal -->
<div id="deleteModal" class="fixed inset-0 z-[110] flex items-center justify-center p-4 bg-slate-900/80 backdrop-blur-md opacity-0 pointer-events-none transition-all duration-300">
    <div class="glass w-full max-w-sm p-8 rounded-[2.5rem] shadow-2xl relative text-center transform scale-95 transition-transform duration-300" id="deleteModalContent">
        <div class="w-20 h-20 bg-red-100 text-red-600 rounded-full flex items-center justify-center mx-auto mb-6 shadow-inner shadow-red-200/50">
            <i data-lucide="alert-triangle" class="w-10 h-10 animate-pulse"></i>
        </div>
        
        <h3 class="text-2xl font-black outfit text-slate-800 mb-2">Hapus User?</h3>
        <p class="text-sm font-medium text-slate-500 mb-8 leading-relaxed">
            Anda yakin ingin menghapus <strong id="deleteUserName" class="text-slate-800"></strong> secara permanen? Tindakan ini tidak dapat dibatalkan.
        </p>
        
        <div class="flex flex-col gap-3">
            <a href="#" id="confirmDeleteBtn" class="w-full py-4 bg-red-600 text-white rounded-2xl font-black text-sm shadow-lg shadow-red-200 hover:bg-red-700 active:scale-95 transition-all">
                Ya, Hapus Sekarang
            </a>
            <button onclick="closeDeleteModal()" class="w-full py-4 bg-white text-slate-600 border border-slate-200 rounded-2xl font-bold text-sm hover:bg-slate-50 active:scale-95 transition-all">
                Batal
            </button>
        </div>
    </div>
</div>

<script>
    function openUserModal() {
        document.getElementById('modalTitle').innerText = 'Tambah User Baru';
        document.getElementById('old_id').value = '';
        document.getElementById('u_nim').value = '';
        document.getElementById('u_name').value = '';
        document.getElementById('u_email').value = '';
        if (document.getElementById('u_role_display')) {
            document.getElementById('u_role_display').value = 'Alumni';
        }
        document.getElementById('u_role').value = 'alumni';
        document.getElementById('u_pass').placeholder = '••••••••';
        document.getElementById('u_pass').required = true;
        const passHint = document.getElementById('passHint');
        passHint.innerText = 'Password default adalah 123456 jika dikosongkan.';
        passHint.className = 'text-[10px] text-slate-400 mt-2 ml-1';
        passHint.classList.remove('hidden');
        document.getElementById('u_v_0').checked = true;
        document.getElementById('userModal').classList.remove('hidden');
    }

    function editUser(u) {
        document.getElementById('modalTitle').innerText = 'Edit Data User';
        document.getElementById('old_id').value = u.id;
        document.getElementById('u_nim').value = u.nim;
        document.getElementById('u_name').value = u.name;
        document.getElementById('u_email').value = u.email;
        if (document.getElementById('u_role_display')) {
            document.getElementById('u_role_display').value = u.role.replace('_', ' ').toUpperCase();
        }
        document.getElementById('u_role').value = u.role;
        document.getElementById('u_pass').placeholder = '(Tetap)';
        document.getElementById('u_pass').required = false;
        
        const passHint = document.getElementById('passHint');
        if (u.google_id) {
            passHint.innerText = 'Akun terhubung Google OAuth. Anda dapat mengatur password sistem agar user dapat login secara manual menggunakan password.';
            passHint.className = 'text-[10px] text-emerald-600 mt-2 ml-1 font-semibold';
        } else {
            passHint.innerText = 'Kosongkan jika tidak ingin mengubah password.';
            passHint.className = 'text-[10px] text-slate-400 mt-2 ml-1';
        }
        passHint.classList.remove('hidden');
        
        if(u.is_verified == 1) document.getElementById('u_v_1').checked = true;
        else document.getElementById('u_v_0').checked = true;
        document.getElementById('userModal').classList.remove('hidden');
    }

    function viewUserDetail(u) {
        document.getElementById('d_avatar').src = `avatar.php?name=${encodeURIComponent(u.name)}&size=128`;
        document.getElementById('d_name').innerText = u.name;
        document.getElementById('d_role').innerText = u.role.replace('_', ' ');
        document.getElementById('d_email').innerText = u.email;
        document.getElementById('d_id').innerText = u.nim || u.id;
        
        const authEl = document.getElementById('d_auth_method');
        authEl.innerText = u.google_id ? 'Google OAuth' : 'Sistem (Password)';
        authEl.className = 'text-xs font-bold uppercase tracking-wider ' + (u.google_id ? 'text-emerald-600' : 'text-slate-500');
        
        const statusEl = document.getElementById('d_status');
        statusEl.innerText = u.is_verified == 1 ? 'Verified' : 'Pending';
        statusEl.className = 'text-xs font-bold uppercase tracking-wider ' + (u.is_verified == 1 ? 'text-emerald-600' : 'text-amber-500');

        document.getElementById('d_date').innerText = new Date(u.created_at).toLocaleDateString();
        document.getElementById('d_tracer').innerText = u.last_tracer_update ? new Date(u.last_tracer_update).toLocaleDateString() : 'Belum Pernah';
        document.getElementById('detailModal').classList.remove('hidden');
    }

    function openDeleteModal(id, name) {
        document.getElementById('deleteUserName').innerText = name;
        document.getElementById('confirmDeleteBtn').href = `handlers/admin_user_handler.php?action=delete&id=${id}`;
        
        const modal = document.getElementById('deleteModal');
        const content = document.getElementById('deleteModalContent');
        
        modal.classList.remove('opacity-0', 'pointer-events-none');
        content.classList.remove('scale-95');
        content.classList.add('scale-100');
    }

    function closeDeleteModal() {
        const modal = document.getElementById('deleteModal');
        const content = document.getElementById('deleteModalContent');
        
        modal.classList.add('opacity-0', 'pointer-events-none');
        content.classList.remove('scale-100');
        content.classList.add('scale-95');
    }
</script>
