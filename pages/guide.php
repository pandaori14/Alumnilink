<?php
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php?page=login");
    exit();
}

$current_role = $_SESSION['user_role'] ?? 'alumni';
$is_super = ($current_role === 'super_admin');
?>

<div class="max-w-6xl mx-auto space-y-6">
    <!-- Header Hero -->
    <div class="glass p-8 rounded-[2rem] border border-white/50 shadow-sm relative overflow-hidden group">
        <div class="absolute top-0 right-0 w-64 h-64 bg-gradient-to-br from-blue-400/20 to-purple-400/20 rounded-full blur-3xl -z-10 group-hover:scale-110 transition-transform duration-700"></div>
        <div class="flex items-center gap-5">
            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-blue-500/30 text-white">
                <i data-lucide="book-open" class="w-8 h-8"></i>
            </div>
            <div>
                <h1 class="text-3xl font-black outfit text-slate-800 tracking-tight">Pusat Bantuan & Panduan</h1>
                <p class="text-sm text-slate-500 mt-1 font-medium">Pelajari cara menggunakan fitur-fitur di AlumniLink sesuai dengan peran Anda.</p>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="flex flex-col md:flex-row gap-6 items-start">
        
        <!-- Sidebar Navigation (Scrollspy/Tabs) -->
        <div class="w-full md:w-64 shrink-0 glass p-4 rounded-[2rem] border border-white/50 shadow-sm sticky top-28 z-10 hidden md:block">
            <h3 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-4 px-2">Daftar Isi</h3>
            <nav class="space-y-1 flex flex-col" id="guide-nav">
                <a href="#umum" class="guide-tab active flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold text-slate-600 hover:bg-blue-50 hover:text-blue-600 transition-all">
                    <i data-lucide="users" class="w-4 h-4"></i> Umum & Alumni
                </a>
                
                <?php if ($current_role === 'admin_tracer' || $is_super): ?>
                <a href="#tracer" class="guide-tab flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold text-slate-600 hover:bg-blue-50 hover:text-blue-600 transition-all">
                    <i data-lucide="file-search" class="w-4 h-4"></i> Admin Tracer
                </a>
                <?php endif; ?>
                
                <?php if ($current_role === 'admin_legalisir' || $is_super): ?>
                <a href="#legalisir" class="guide-tab flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold text-slate-600 hover:bg-blue-50 hover:text-blue-600 transition-all">
                    <i data-lucide="award" class="w-4 h-4"></i> Admin Legalisir
                </a>
                <?php endif; ?>
                
                <?php if ($current_role === 'keuangan' || $is_super): ?>
                <a href="#keuangan" class="guide-tab flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold text-slate-600 hover:bg-blue-50 hover:text-blue-600 transition-all">
                    <i data-lucide="receipt" class="w-4 h-4"></i> Keuangan
                </a>
                <?php endif; ?>
                
                <?php if ($is_super): ?>
                <a href="#superadmin" class="guide-tab flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold text-slate-600 hover:bg-blue-50 hover:text-blue-600 transition-all">
                    <i data-lucide="shield-alert" class="w-4 h-4"></i> Super Admin
                </a>
                <?php endif; ?>
            </nav>
        </div>

        <!-- Mobile Nav Select -->
        <div class="w-full md:hidden glass p-4 rounded-2xl border border-white/50 sticky top-20 z-20">
            <select id="mobile-guide-nav" class="w-full bg-white border border-slate-200 text-slate-700 text-sm rounded-xl focus:ring-blue-500 focus:border-blue-500 block p-3 font-bold outfit outline-none">
                <option value="#umum">Umum & Alumni</option>
                <?php if ($current_role === 'admin_tracer' || $is_super): ?><option value="#tracer">Admin Tracer</option><?php endif; ?>
                <?php if ($current_role === 'admin_legalisir' || $is_super): ?><option value="#legalisir">Admin Legalisir</option><?php endif; ?>
                <?php if ($current_role === 'keuangan' || $is_super): ?><option value="#keuangan">Keuangan</option><?php endif; ?>
                <?php if ($is_super): ?><option value="#superadmin">Super Admin</option><?php endif; ?>
            </select>
        </div>

        <!-- Content Area -->
        <div class="flex-1 space-y-8 w-full pb-20">
            
            <!-- SECTION: UMUM & ALUMNI -->
            <section id="umum" class="guide-section scroll-mt-28">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-10 h-10 rounded-xl bg-slate-800 text-white flex items-center justify-center shadow-lg"><i data-lucide="users" class="w-5 h-5"></i></div>
                    <h2 class="text-2xl font-black outfit text-slate-800">Panduan Umum & Alumni</h2>
                </div>
                
                <div class="grid grid-cols-1 gap-6">
                    <!-- Card -->
                    <div class="glass p-6 rounded-[2rem] border border-white/50 shadow-sm hover:shadow-md transition-shadow">
                        <h3 class="text-lg font-bold text-slate-800 mb-2 flex items-center gap-2"><i data-lucide="log-in" class="w-5 h-5 text-blue-500"></i> Autentikasi & Akun</h3>
                        <div class="space-y-4 text-sm text-slate-600">
                            <p><strong>Login & Pendaftaran:</strong> Anda dapat mendaftar menggunakan formulir registrasi biasa atau menggunakan fitur <b>Google SSO</b>. Jika menggunakan Google, akun Anda mungkin memerlukan persetujuan Admin (tergantung pengaturan sistem).</p>
                            <p><strong>Lupa Kata Sandi:</strong> Pada halaman login, klik "Lupa Password". Masukkan email Anda yang terdaftar. Sistem akan mengirimkan email berisi tautan (berlaku terbatas) untuk mengatur ulang sandi Anda.</p>
                        </div>
                    </div>

                    <div class="glass p-6 rounded-[2rem] border border-white/50 shadow-sm hover:shadow-md transition-shadow">
                        <h3 class="text-lg font-bold text-slate-800 mb-2 flex items-center gap-2"><i data-lucide="file-search" class="w-5 h-5 text-indigo-500"></i> Mengisi Tracer Study</h3>
                        <div class="space-y-4 text-sm text-slate-600">
                            <p>Tracer Study wajib diisi untuk memperbarui data karir Anda. Beberapa layanan (seperti Legalisir) mungkin akan terkunci jika Anda belum mengisi Tracer Study dalam jangka waktu tertentu.</p>
                            <ul class="list-disc pl-5 space-y-1">
                                <li>Masuk ke menu <b>Tracer Alumni</b>.</li>
                                <li>Jawab kuesioner yang tersedia. Beberapa pertanyaan mungkin muncul secara dinamis bergantung pada jawaban Anda sebelumnya (Skip Logic).</li>
                                <li>Klik <b>Simpan Data Tracer</b>.</li>
                            </ul>
                        </div>
                    </div>

                    <div class="glass p-6 rounded-[2rem] border border-white/50 shadow-sm hover:shadow-md transition-shadow">
                        <h3 class="text-lg font-bold text-slate-800 mb-2 flex items-center gap-2"><i data-lucide="award" class="w-5 h-5 text-amber-500"></i> Layanan Legalisir Online</h3>
                        <div class="space-y-4 text-sm text-slate-600">
                            <p>AlumniLink memfasilitasi legalisir dokumen resmi tanpa harus ke kampus.</p>
                            <ol class="list-decimal pl-5 space-y-1">
                                <li>Pilih menu <b>Layanan Legalisir</b>.</li>
                                <li>Pilih jumlah dokumen yang ingin dilegalisir (Ijazah/Transkrip).</li>
                                <li>Pilih metode pengiriman: <b>Ambil di Kampus</b> atau <b>Kirim ke Alamat</b>. Jika dikirim, masukkan alamat lengkap dan provinsi. Sistem otomatis menghitung estimasi biaya ongkir.</li>
                                <li>Klik <b>Ajukan Legalisir</b>. Lakukan pembayaran melalui Payment Gateway Midtrans (QRIS, VA, E-Wallet, dll).</li>
                                <li>Status pesanan (<i>Pending, Processing, Completed</i>) dan nomor resi pengiriman dapat dipantau di halaman yang sama.</li>
                            </ol>
                        </div>
                    </div>

                    <div class="glass p-6 rounded-[2rem] border border-white/50 shadow-sm hover:shadow-md transition-shadow">
                        <h3 class="text-lg font-bold text-slate-800 mb-2 flex items-center gap-2"><i data-lucide="heart" class="w-5 h-5 text-rose-500"></i> Donasi Alumni</h3>
                        <div class="space-y-4 text-sm text-slate-600">
                            <p>Berikan kontribusi kembali ke almamater melalui kampanye donasi.</p>
                            <ul class="list-disc pl-5 space-y-1">
                                <li>Buka menu <b>Donasi Alumni</b>. Pilih kampanye donasi yang aktif.</li>
                                <li>Masukkan nominal, nama donatur (bisa Anonim), dan pesan/doa.</li>
                                <li>Lakukan pembayaran instan via Midtrans. Donasi akan otomatis tercatat dan memperbarui progress bar kampanye jika berhasil.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </section>

            <?php if ($current_role === 'admin_tracer' || $is_super): ?>
            <!-- SECTION: ADMIN TRACER -->
            <section id="tracer" class="guide-section scroll-mt-28 hidden-section pt-8 border-t border-slate-200/60">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-10 h-10 rounded-xl bg-indigo-600 text-white flex items-center justify-center shadow-lg"><i data-lucide="file-search" class="w-5 h-5"></i></div>
                    <h2 class="text-2xl font-black outfit text-slate-800">Panduan Admin Tracer</h2>
                </div>
                
                <div class="grid grid-cols-1 gap-6">
                    <div class="glass p-6 rounded-[2rem] border border-white/50 shadow-sm">
                        <h3 class="text-lg font-bold text-slate-800 mb-2">Manajemen Kuesioner (Skip Logic)</h3>
                        <div class="space-y-2 text-sm text-slate-600">
                            <p>Admin dapat membuat kuesioner dinamis bersyarat melalui menu <b>Konfigurasi Tracer</b>.</p>
                            <ul class="list-disc pl-5 space-y-1">
                                <li><b>Pemicu Skip Logic:</b> Saat membuat pertanyaan baru, Anda bisa memilih <i>"Bergantung pada pertanyaan lain"</i>. Contoh: Pertanyaan "Nama Perusahaan" hanya muncul jika alumni menjawab "Bekerja Full-time" pada pertanyaan status karir.</li>
                                <li>Mendukung pemicu jamak (menggunakan checkbox/select).</li>
                                <li>Setiap perubahan urutan/nomor pertanyaan otomatis disesuaikan oleh sistem di sisi pengguna (Dynamic Numbering).</li>
                            </ul>
                        </div>
                    </div>

                    <div class="glass p-6 rounded-[2rem] border border-white/50 shadow-sm">
                        <h3 class="text-lg font-bold text-slate-800 mb-2">Laporan & Analitik</h3>
                        <div class="space-y-2 text-sm text-slate-600">
                            <p>Di menu <b>Analitik Alumni</b>, Anda dapat melihat statistik lulusan (tingkat penyerapan kerja, sebaran gaji, dll). Di menu <b>Laporan Tracer</b>, Anda dapat mengekspor data tanggapan alumni ke dalam format CSV/Excel untuk pelaporan akreditasi.</p>
                        </div>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($current_role === 'admin_legalisir' || $is_super): ?>
            <!-- SECTION: ADMIN LEGALISIR -->
            <section id="legalisir" class="guide-section scroll-mt-28 hidden-section pt-8 border-t border-slate-200/60">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-10 h-10 rounded-xl bg-amber-500 text-white flex items-center justify-center shadow-lg"><i data-lucide="award" class="w-5 h-5"></i></div>
                    <h2 class="text-2xl font-black outfit text-slate-800">Panduan Admin Legalisir</h2>
                </div>
                
                <div class="grid grid-cols-1 gap-6">
                    <div class="glass p-6 rounded-[2rem] border border-white/50 shadow-sm">
                        <h3 class="text-lg font-bold text-slate-800 mb-2">Memproses Pesanan Legalisir</h3>
                        <div class="space-y-2 text-sm text-slate-600">
                            <p>Masuk ke menu <b>Kelola Legalisir</b>. Di sini terdapat daftar permohonan dari alumni.</p>
                            <ul class="list-disc pl-5 space-y-1">
                                <li>Permohonan dengan status pembayaran <i>success</i> / lunas dapat langsung Anda proses (Klik <b>Process</b>).</li>
                                <li>Setelah dokumen selesai dilegalisir, jika opsi pengiriman adalah kurir, masukkan Nomor Resi dan klik <b>Mark as Completed</b>. Notifikasi akan otomatis terkirim ke alumni.</li>
                                <li>Jika tidak memenuhi syarat, klik <b>Reject</b> dan sertakan alasannya.</li>
                            </ul>
                        </div>
                    </div>

                    <div class="glass p-6 rounded-[2rem] border border-white/50 shadow-sm">
                        <h3 class="text-lg font-bold text-slate-800 mb-2">Repositori Dokumen Digital</h3>
                        <div class="space-y-2 text-sm text-slate-600">
                            <p>Anda dapat mengelola dokumen *softcopy* terenkripsi di menu <b>Repositori Dokumen</b> sebagai basis pengecekan keabsahan sebelum melegalisir berkas.</p>
                        </div>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($current_role === 'keuangan' || $is_super): ?>
            <!-- SECTION: KEUANGAN -->
            <section id="keuangan" class="guide-section scroll-mt-28 hidden-section pt-8 border-t border-slate-200/60">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-10 h-10 rounded-xl bg-emerald-600 text-white flex items-center justify-center shadow-lg"><i data-lucide="receipt" class="w-5 h-5"></i></div>
                    <h2 class="text-2xl font-black outfit text-slate-800">Panduan Keuangan</h2>
                </div>
                
                <div class="grid grid-cols-1 gap-6">
                    <div class="glass p-6 rounded-[2rem] border border-white/50 shadow-sm">
                        <h3 class="text-lg font-bold text-slate-800 mb-2">Manajemen Kampanye Donasi</h3>
                        <div class="space-y-2 text-sm text-slate-600">
                            <p>Gunakan menu <b>Kelola Donasi</b> untuk membuat program penggalangan dana. Tentukan Target Nominal dan Tanggal Selesai. Status kampanye otomatis *Non-aktif* jika batas waktu terlewati.</p>
                        </div>
                    </div>

                    <div class="glass p-6 rounded-[2rem] border border-white/50 shadow-sm">
                        <h3 class="text-lg font-bold text-slate-800 mb-2">Laporan Keuangan</h3>
                        <div class="space-y-2 text-sm text-slate-600">
                            <p>Di menu <b>Laporan Keuangan</b>, Anda dapat memantau mutasi dan rekapitulasi dana masuk (dari Legalisir maupun Donasi) dengan akurasi 100% yang disinkronisasi melalui *Webhook Payment Gateway* Midtrans.</p>
                        </div>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($is_super): ?>
            <!-- SECTION: SUPER ADMIN -->
            <section id="superadmin" class="guide-section scroll-mt-28 hidden-section pt-8 border-t border-slate-200/60">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-10 h-10 rounded-xl bg-red-600 text-white flex items-center justify-center shadow-lg"><i data-lucide="shield-alert" class="w-5 h-5"></i></div>
                    <h2 class="text-2xl font-black outfit text-slate-800">Panduan Eksekutif Super Admin</h2>
                </div>
                
                <div class="grid grid-cols-1 gap-6">
                    <div class="glass p-6 rounded-[2rem] border border-white/50 shadow-sm">
                        <h3 class="text-lg font-bold text-slate-800 mb-2">Konfigurasi Sistem Utama</h3>
                        <div class="space-y-2 text-sm text-slate-600">
                            <p>Masuk ke menu <b>Konfigurasi Sistem</b> untuk mengontrol aspek fundamental web:</p>
                            <ul class="list-disc pl-5 space-y-1">
                                <li><b>Konfigurasi Identitas:</b> Ubah Logo, Nama Institusi, dan Kontak.</li>
                                <li><b>Midtrans:</b> Masukkan Client Key & Server Key. Gunakan opsi *Sandbox* untuk testing dan *Production* untuk Go-Live.</li>
                                <li><b>Email SMTP:</b> Wajib diisi agar fitur Lupa Sandi dan Email Broadcast bekerja.</li>
                                <li><b>Google SSO:</b> Masukkan *Google Client ID*. Anda bisa menentukan apakah *user* yang mendaftar via Google otomatis *Verified* atau harus disetujui manual.</li>
                                <li><b>Maintenance Mode:</b> Matikan akses *user* awam ketika Anda perlu melakukan perbaikan web.</li>
                            </ul>
                        </div>
                    </div>

                    <div class="glass p-6 rounded-[2rem] border border-white/50 shadow-sm">
                        <h3 class="text-lg font-bold text-slate-800 mb-2">Kelola Pengguna & Audit Trail</h3>
                        <div class="space-y-2 text-sm text-slate-600">
                            <p>Di menu <b>Kelola User</b>, Anda dapat mengubah *Role* pengguna, mereset password mereka, atau memblokir akses. Menu <b>Audit Trail</b> melacak seluruh pergerakan sensitif pengguna (Login, Update, Delete) beserta IP dan deteksi lokasi (Geocoding).</p>
                        </div>
                    </div>

                    <div class="glass p-6 rounded-[2rem] border border-white/50 shadow-sm">
                        <h3 class="text-lg font-bold text-slate-800 mb-2">Broadcast & Template Email</h3>
                        <div class="space-y-2 text-sm text-slate-600">
                            <p>AlumniLink dibekali kapabilitas pemasaran surel (*Email Marketing*).</p>
                            <ul class="list-disc pl-5 space-y-1">
                                <li><b>Template Email (Layouts):</b> Rancang tata letak HTML email perusahaan. Beberapa preset standar (seperti *Newsletter* dan *Invoice*) telah disediakan dengan desain responsif.</li>
                                <li><b>Broadcast:</b> Pilih penerima berdasarkan Angkatan/Prodi, tulis pesan Anda dengan editor visual, pilih *Layout*, dan sistem akan mengirimkan pesan massal.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </section>
            <?php endif; ?>

        </div>
    </div>
</div>

<script>
    // Tab Navigation Logic
    document.addEventListener('DOMContentLoaded', () => {
        const tabs = document.querySelectorAll('.guide-tab');
        const sections = document.querySelectorAll('.guide-section');
        const mobileSelect = document.getElementById('mobile-guide-nav');

        function activateTab(targetId) {
            // Update desktop tabs
            tabs.forEach(t => {
                if(t.getAttribute('href') === targetId) {
                    t.classList.add('bg-blue-50', 'text-blue-600', 'active');
                    t.classList.remove('text-slate-600');
                } else {
                    t.classList.remove('bg-blue-50', 'text-blue-600', 'active');
                    t.classList.add('text-slate-600');
                }
            });

            // Update mobile select
            if(mobileSelect) {
                mobileSelect.value = targetId;
            }
        }

        // Click Desktop Tabs
        tabs.forEach(tab => {
            tab.addEventListener('click', (e) => {
                e.preventDefault(); // prevent navigation that reloads page
                const target = tab.getAttribute('href');
                activateTab(target);
                const section = document.querySelector(target);
                if(section) {
                    section.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });

        // Change Mobile Select
        if(mobileSelect) {
            mobileSelect.addEventListener('change', (e) => {
                const target = e.target.value;
                activateTab(target);
                const section = document.querySelector(target);
                if(section) {
                    section.scrollIntoView({ behavior: 'smooth' });
                }
            });
        }

        // Intersection Observer for Scrollspy
        const observerOptions = {
            root: null,
            rootMargin: '-20% 0px -60% 0px',
            threshold: 0
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const id = '#' + entry.target.id;
                    activateTab(id);
                }
            });
        }, observerOptions);

        sections.forEach(section => {
            observer.observe(section);
        });
    });
</script>
