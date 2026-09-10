<?php
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Fetch majors from database
$db_majors = $pdo->query("SELECT major_code, major_name FROM majors ORDER BY major_name ASC")->fetchAll();
?>

<div class="max-w-5xl mx-auto px-4 pb-24">
    <!-- Header Title -->
    <div class="mb-10 text-center md:text-left">
        <h1 class="text-3xl font-black outfit text-slate-800 tracking-tight">Profil & Pengaturan</h1>
        <p class="text-slate-500 font-medium text-sm mt-1">Kelola informasi pribadi, data akademik, dan preferensi keamanan Anda.</p>
    </div>

    <?php if (isset($_GET['success']) && $_GET['success'] == 'updated'): ?>
        <div class="mb-8 p-5 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-3xl flex items-center gap-4 shadow-lg shadow-emerald-100 animate-fade-in">
            <div class="w-10 h-10 bg-emerald-100 rounded-2xl flex items-center justify-center shrink-0">
                <i data-lucide="check-circle" class="w-5 h-5 text-emerald-600"></i>
            </div>
            <span class="font-bold text-sm">Profil dan data Anda berhasil diperbarui dengan sukses!</span>
        </div>
    <?php elseif (isset($_GET['error']) && $_GET['error'] == 'password_mismatch'): ?>
        <div class="mb-8 p-5 bg-rose-50 border border-rose-200 text-rose-800 rounded-3xl flex items-center gap-4 shadow-lg shadow-rose-100 animate-fade-in">
            <div class="w-10 h-10 bg-rose-100 rounded-2xl flex items-center justify-center shrink-0">
                <i data-lucide="alert-circle" class="w-5 h-5 text-rose-600"></i>
            </div>
            <span class="font-bold text-sm">Gagal memperbarui: Kata sandi baru dan konfirmasi tidak cocok.</span>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        <!-- Left Column: Unified Profile Card & Status (lg:col-span-4) -->
        <div class="lg:col-span-4 space-y-6">
            <div class="glass p-8 rounded-[3rem] shadow-xl shadow-slate-100 border border-white/80 bg-gradient-to-b from-white/90 via-white/50 to-blue-50/20 flex flex-col items-center text-center relative overflow-hidden group">
                <!-- Decorative Blur Glow -->
                <div class="absolute -right-12 -top-12 w-40 h-40 bg-blue-600/10 rounded-full blur-3xl pointer-events-none group-hover:bg-blue-600/20 transition-all duration-500"></div>
                <div class="absolute -left-12 -bottom-12 w-40 h-40 bg-indigo-600/10 rounded-full blur-3xl pointer-events-none"></div>

                <!-- Status Badge at Top Right -->
                <div class="absolute top-6 right-6">
                    <?php if ($user->is_verified): ?>
                        <span class="px-3 py-1.5 bg-emerald-100/80 text-emerald-700 rounded-2xl text-[10px] font-black uppercase tracking-widest flex items-center gap-1.5 border border-emerald-200/60 shadow-sm backdrop-blur-md">
                            <i data-lucide="shield-check" class="w-3.5 h-3.5"></i> Terverifikasi
                        </span>
                    <?php else: ?>
                        <span class="px-3 py-1.5 bg-amber-100/80 text-amber-700 rounded-2xl text-[10px] font-black uppercase tracking-widest flex items-center gap-1.5 border border-amber-200/60 shadow-sm backdrop-blur-md">
                            <i data-lucide="clock" class="w-3.5 h-3.5"></i> Pending Review
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Avatar Container -->
                <div class="relative mt-4 mb-6 cursor-pointer group/avatar" onclick="document.getElementById('avatar_input').click()" title="Klik untuk mengganti foto profil">
                    <div class="w-36 h-36 rounded-full bg-gradient-to-tr from-blue-600 to-indigo-600 p-1 shadow-2xl shadow-blue-300/50 overflow-hidden border-4 border-white relative transition-transform duration-300 group-hover/avatar:scale-105">
                        <?php if ($user->avatar && file_exists('uploads/avatars/' . $user->avatar)): ?>
                            <img src="uploads/avatars/<?php echo e($user->avatar); ?>" alt="Avatar" class="w-full h-full rounded-full object-cover">
                        <?php else: ?>
                            <img src="avatar.php?name=<?php echo urlencode($user->name); ?>&bg=0066FF&size=150" alt="Avatar" class="w-full h-full rounded-full object-cover">
                        <?php endif; ?>
                        
                        <!-- Hover Overlay -->
                        <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm flex flex-col items-center justify-center opacity-0 group-hover/avatar:opacity-100 transition-all duration-300 text-white">
                            <i data-lucide="camera" class="w-8 h-8 mb-1 animate-bounce"></i>
                            <span class="text-[10px] font-bold uppercase tracking-wider">Ubah Foto</span>
                        </div>
                    </div>
                </div>

                <h2 class="text-2xl font-black outfit text-slate-800 tracking-tight mb-1"><?php echo e($user->name); ?></h2>
                <p class="text-xs font-black text-blue-600 uppercase tracking-widest bg-blue-50 px-4 py-1.5 rounded-full mb-8 border border-blue-100 shadow-sm"><?php echo e(strtoupper($user->role)); ?></p>
                
                <!-- Quick Info List -->
                <div class="w-full space-y-3 text-left pt-6 border-t border-slate-200/60">
                    <div class="flex items-center gap-3 p-3.5 bg-white/60 rounded-2xl border border-slate-100 shadow-sm">
                        <div class="w-9 h-9 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center shrink-0">
                            <i data-lucide="mail" class="w-4 h-4"></i>
                        </div>
                        <div class="overflow-hidden">
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Email Terdaftar</p>
                            <p class="text-xs font-bold text-slate-700 truncate"><?php echo e($user->email); ?></p>
                        </div>
                    </div>

                    <?php if ($user->role === 'alumni'): ?>
                    <div class="flex items-center gap-3 p-3.5 bg-white/60 rounded-2xl border border-slate-100 shadow-sm">
                        <div class="w-9 h-9 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center shrink-0">
                            <i data-lucide="hash" class="w-4 h-4"></i>
                        </div>
                        <div class="overflow-hidden">
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">NIM</p>
                            <p class="text-xs font-bold text-slate-700 truncate">
                                <?php echo !empty($user->nim) ? htmlspecialchars($user->nim) : '<span class="text-rose-500 italic">Belum diatur</span>'; ?>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Action Card for Logout -->
            <div class="glass p-6 rounded-[2.5rem] shadow-xl shadow-slate-100 border border-white/80 bg-gradient-to-br from-rose-50/30 to-white/60">
                <a href="logout.php" class="flex items-center justify-between p-4 bg-rose-500 text-white rounded-2xl hover:bg-rose-600 transition-all shadow-lg shadow-rose-200 group active:scale-[0.98]">
                    <div class="flex items-center gap-3.5">
                        <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center backdrop-blur-sm group-hover:scale-110 transition-transform">
                            <i data-lucide="log-out" class="w-5 h-5"></i>
                        </div>
                        <span class="font-bold text-sm tracking-wide">Keluar dari Akun</span>
                    </div>
                    <i data-lucide="arrow-right" class="w-5 h-5 group-hover:translate-x-1 transition-transform"></i>
                </a>
            </div>
        </div>

        <!-- Right Column: Account Settings Form (lg:col-span-8) -->
        <div class="lg:col-span-8 space-y-8">
            <div class="glass p-8 md:p-12 rounded-[3.5rem] shadow-2xl shadow-slate-200/50 border border-white/80 bg-gradient-to-b from-white/95 to-white/70">
                <div class="flex items-center gap-4 mb-10 pb-6 border-b border-slate-100">
                    <div class="w-12 h-12 bg-blue-600 text-white rounded-2xl flex items-center justify-center shadow-xl shadow-blue-200 shrink-0">
                        <i data-lucide="settings-2" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h2 class="text-2xl font-black outfit text-slate-800 tracking-tight">Pengaturan & Kelengkapan Profil</h2>
                        <p class="text-xs text-slate-500 font-medium mt-0.5">Lengkapi dan perbarui data diri Anda di bawah ini.</p>
                    </div>
                </div>
                
                <form action="handlers/update_profile.php" method="POST" enctype="multipart/form-data" class="space-y-10">
                    <?php csrf_field(); ?>
                    <!-- Hidden File Input -->
                    <input aria-label="Unggah foto profil" type="file" name="avatar" id="avatar_input" class="hidden" accept="image/*">

                    <!-- SECTION 1: INFORMASI DASAR -->
                    <div>
                        <h3 class="text-xs font-black text-slate-400 uppercase tracking-[0.25em] mb-6 flex items-center gap-2">
                            <i data-lucide="user" class="w-4 h-4 text-blue-600"></i>
                            Informasi Akun Dasar
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2" for="f_name">Nama Lengkap</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                        <i data-lucide="lock" class="w-5 h-5 text-slate-400"></i>
                                    </div>
                                    <input id="f_name" type="text" name="name" value="<?php echo htmlspecialchars($user->name ?? ''); ?>" class="w-full pl-12 pr-6 py-4 rounded-2xl bg-slate-50 border border-slate-200 text-slate-500 font-semibold text-sm outline-none cursor-not-allowed shadow-inner" readonly title="Hubungi admin untuk mengubah nama">
                                </div>
                                <p class="text-[10px] text-slate-400 mt-2 ml-1 italic font-medium">Hubungi administrator kampus jika ingin mengubah nama lengkap.</p>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2" for="f_email">Alamat Email <span class="text-rose-500">*</span></label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                        <i data-lucide="mail" class="w-5 h-5 text-slate-400"></i>
                                    </div>
                                    <input id="f_email" type="email" name="email" value="<?php echo htmlspecialchars($user->email ?? ''); ?>" required placeholder="email@contoh.com" class="w-full pl-12 pr-6 py-4 rounded-2xl bg-white border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 transition-all outline-none font-semibold text-sm shadow-sm">
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($user->role === 'alumni'): ?>
                    <!-- SECTION 2: DATA AKADEMIK & KONTAK -->
                    <div class="pt-8 border-t border-slate-100">
                        <h3 class="text-xs font-black text-slate-400 uppercase tracking-[0.25em] mb-6 flex items-center gap-2">
                            <i data-lucide="graduation-cap" class="w-4 h-4 text-indigo-600"></i>
                            Data Akademik & Kontak
                        </h3>
                        
                        <div class="space-y-6">
                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2" for="f_nim">Nomor Induk Mahasiswa (NIM)</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                        <i data-lucide="hash" class="w-5 h-5 text-slate-400"></i>
                                    </div>
                                    <input id="f_nim" type="text" name="nim" value="<?php echo htmlspecialchars($user->nim ?? ''); ?>" placeholder="Contoh: J500230013" class="w-full pl-12 pr-6 py-4 rounded-2xl bg-white border border-slate-200 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 transition-all outline-none font-semibold text-sm shadow-sm">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2" for="f_major">Program Studi <span class="text-rose-500">*</span></label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <i data-lucide="book-open" class="w-5 h-5 text-slate-400"></i>
                                        </div>
                                        <select id="f_major" name="major" required class="w-full pl-12 pr-10 py-4 rounded-2xl bg-white border border-slate-200 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 transition-all outline-none font-semibold text-sm shadow-sm appearance-none cursor-pointer">
                                            <option value="">-- Pilih Program Studi --</option>
                                            <?php foreach ($db_majors as $m): ?>
                                                <option value="<?php echo htmlspecialchars($m->major_code); ?>" <?php echo e(($user->major ?? '') === $m->major_code ? 'selected' : ''); ?>><?php echo htmlspecialchars($m->major_name); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none">
                                            <i data-lucide="chevron-down" class="w-5 h-5 text-slate-400"></i>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2" for="f_graduation_year">Tahun Lulus <span class="text-rose-500">*</span></label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <i data-lucide="calendar" class="w-5 h-5 text-slate-400"></i>
                                        </div>
                                        <input id="f_graduation_year" type="number" name="graduation_year" value="<?php echo htmlspecialchars($user->graduation_year ?? ''); ?>" required placeholder="Contoh: 2022" min="1950" max="2030" class="w-full pl-12 pr-6 py-4 rounded-2xl bg-white border border-slate-200 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 transition-all outline-none font-semibold text-sm shadow-sm">
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2" for="f_phone">Nomor Handphone / WhatsApp</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                        <i data-lucide="phone" class="w-5 h-5 text-slate-400"></i>
                                    </div>
                                    <input id="f_phone" type="text" name="phone" value="<?php echo htmlspecialchars($user->phone ?? ''); ?>" placeholder="Contoh: +6281234567890" class="w-full pl-12 pr-6 py-4 rounded-2xl bg-white border border-slate-200 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 transition-all outline-none font-semibold text-sm shadow-sm">
                                </div>
                                <p class="text-[10px] text-slate-400 mt-1.5 ml-1 italic font-medium">Gunakan kode negara +62 (bukan awalan 0) agar tautan WhatsApp otomatis berfungsi.</p>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2" for="f_address">Alamat Domisili Lengkap</label>
                                <div class="relative">
                                    <div class="absolute top-4 left-0 pl-4 flex items-start pointer-events-none">
                                        <i data-lucide="map-pin" class="w-5 h-5 text-slate-400"></i>
                                    </div>
                                    <textarea id="f_address" name="address" placeholder="Tuliskan alamat lengkap domisili Anda saat ini..." class="w-full pl-12 pr-6 py-4 rounded-2xl bg-white border border-slate-200 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 transition-all outline-none h-28 font-semibold text-sm shadow-sm"><?php echo htmlspecialchars($user->address ?? ''); ?></textarea>
                                </div>

                                <!-- Persetujuan tampil di Peta Persebaran.
                                     Alamat dikumpulkan untuk pengiriman berkas legalisir;
                                     memakainya untuk menandai posisi di peta yang dilihat
                                     alumni lain adalah tujuan yang berbeda, jadi harus
                                     bisa ditolak. -->
                                <div class="mt-4 flex items-start gap-3 p-4 rounded-2xl bg-slate-50 border border-slate-200">
                                    <input type="checkbox" id="f_map_participate" name="map_participate" value="1"
                                           <?php echo e(empty($user->map_opt_out) ? 'checked' : ''); ?>
                                           class="mt-0.5 w-4 h-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 shrink-0">
                                    <label for="f_map_participate" class="text-xs text-slate-600 leading-relaxed cursor-pointer">
                                        <span class="font-bold text-slate-700 block mb-0.5">Tampilkan saya di Peta Persebaran Alumni</span>
                                        Sesama alumni hanya melihat nama, prodi, angkatan, dan lokasi perkiraan (radius sekitar 10&nbsp;km) &mdash; alamat lengkap Anda tidak pernah ditampilkan kepada mereka. Staf fakultas tetap dapat melihat data lengkap sebagaimana di Database Alumni.
                                        Bila dimatikan, titik Anda dihapus dari peta beserta koordinat yang tersimpan.
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- SECTION 3: KEAMANAN AKUN -->
                    <div class="pt-8 border-t border-slate-100">
                        <h3 class="text-xs font-black text-slate-400 uppercase tracking-[0.25em] mb-6 flex items-center gap-2">
                            <i data-lucide="shield" class="w-4 h-4 text-emerald-600"></i>
                            Keamanan & Kata Sandi
                        </h3>
                        <?php if (!empty($user->google_id)): ?>
                            <div class="p-5 bg-emerald-50/50 border border-emerald-100 text-emerald-800 rounded-3xl flex items-center gap-4 shadow-sm">
                                <div class="w-10 h-10 bg-emerald-100/50 rounded-2xl flex items-center justify-center shrink-0">
                                    <svg class="w-5 h-5 text-emerald-600" viewBox="0 0 24 24" fill="currentColor"><path d="M12.24 10.285V13.4h6.887C18.2 15.614 15.645 18 12.24 18c-3.86 0-7-3.14-7-7s3.14-7 7-7c1.7 0 3.24.61 4.45 1.64l2.42-2.42C17.305 1.574 14.935 1 12.24 1 6.58 1 2 5.58 2 11.24s4.58 10.24 10.24 10.24c5.9 0 9.8-4.15 9.8-9.98 0-.67-.06-1.3-.18-1.85h-9.62z"/></svg>
                                </div>
                                <div class="text-xs font-medium text-slate-600 leading-relaxed">
                                    Akun Anda terhubung dengan <strong class="text-emerald-700">Google Sign-In</strong>. Pengelolaan kata sandi dikelola secara aman oleh Google, sehingga Anda tidak perlu mengatur kata sandi lokal di sistem ini.
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="text-xs text-slate-500 mb-6">Biarkan kosong jika Anda tidak ingin mengubah kata sandi saat ini.</p>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2" for="f_new_password">Kata Sandi Baru</label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <i data-lucide="key" class="w-5 h-5 text-slate-400"></i>
                                        </div>
                                        <input id="f_new_password" type="password" name="new_password" placeholder="••••••••" class="w-full pl-12 pr-6 py-4 rounded-2xl bg-white border border-slate-200 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 transition-all outline-none font-semibold text-sm shadow-sm">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2" for="f_confirm_password">Konfirmasi Kata Sandi Baru</label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <i data-lucide="key" class="w-5 h-5 text-slate-400"></i>
                                        </div>
                                        <input id="f_confirm_password" type="password" name="confirm_password" placeholder="••••••••" class="w-full pl-12 pr-6 py-4 rounded-2xl bg-white border border-slate-200 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 transition-all outline-none font-semibold text-sm shadow-sm">
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ACTION FOOTER -->
                    <div class="pt-8 border-t border-slate-100 flex flex-col md:flex-row items-center justify-end gap-4">
                        <button type="submit" class="w-full md:w-auto px-10 py-4 bg-blue-600 text-white rounded-2xl font-black uppercase tracking-[0.2em] text-xs shadow-xl shadow-blue-200 hover:bg-blue-700 transition-all active:scale-[0.98] flex items-center justify-center gap-3">
                            <i data-lucide="save" class="w-4 h-4"></i>
                            Simpan Perubahan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
