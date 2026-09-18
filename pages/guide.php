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
                                <li>Rincian biaya (dokumen, ongkir, biaya layanan) tampil sebelum Anda menekan tombol. Angka itulah yang akan ditagih.</li>
                                <li>Klik <b>Ajukan &amp; Lanjut ke Pembayaran</b>, lalu bayar lewat QRIS, Virtual Account, atau e-wallet. Tergantung pengaturan fakultas, halaman pembayarannya terbuka sebagai popup atau Anda dialihkan ke halaman penyedia pembayaran.</li>
                                <li>Bila tagihan gagal dibuat atau sudah kedaluwarsa, pengajuan Anda TIDAK hilang: buka detail pengajuan lalu tekan <b>Buat Tagihan Pembayaran</b>.</li>
                                <li>Status pembayaran diperbarui otomatis setelah pembayaran terkonfirmasi — biasanya beberapa detik, paling lambat sekitar setengah jam. Tidak perlu mengirim bukti transfer.</li>
                                <li>Status pesanan (<i>Menunggu, Diproses, Selesai</i>) dan nomor resi pengiriman dapat dipantau di halaman yang sama.</li>
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
                                <li>Rincian biaya tampil sebelum membayar. Lakukan pembayaran, lalu Anda kembali ke halaman donasi. Donasi tercatat dan memperbarui progres kampanye setelah pembayaran terkonfirmasi.</li>
                            </ul>
                        </div>
                    </div>

                    <div class="glass p-6 rounded-[2rem] border border-white/50 shadow-sm hover:shadow-md transition-shadow">
                        <h3 class="text-lg font-bold text-slate-800 mb-2 flex items-center gap-2"><i data-lucide="map-pin" class="w-5 h-5 text-emerald-500"></i> Peta Persebaran &amp; Privasi Anda</h3>
                        <div class="space-y-4 text-sm text-slate-600">
                            <p>Menu <b>Peta Persebaran</b> menampilkan sebaran alumni di seluruh Indonesia. Titik Anda berasal dari alamat yang Anda isi di Profil.</p>
                            <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200">
                                <p class="font-bold text-emerald-800 mb-1">Yang dilihat sesama alumni tentang Anda</p>
                                <p class="text-emerald-700">Hanya <b>nama, program studi, angkatan, dan lokasi perkiraan</b> dengan radius sekitar 10&nbsp;km. Alamat lengkap Anda <b>tidak pernah</b> ditampilkan kepada sesama alumni.</p>
                            </div>
                            <p>Staf fakultas tetap melihat data lengkap &mdash; sama seperti yang sudah ada di Database Alumni, dan sesuai keperluan pengiriman berkas legalisir.</p>
                            <p><strong>Cara menyembunyikan diri dari peta:</strong> buka <b>Profil</b>, lalu hapus centang pada <b>&ldquo;Tampilkan saya di Peta Persebaran Alumni&rdquo;</b> dan simpan. Titik Anda langsung dihapus dari peta, berikut koordinat yang tersimpan.</p>
                            <p class="text-xs text-slate-500">Alamat dikumpulkan untuk pengiriman berkas legalisir. Memakainya untuk menandai posisi di peta adalah tujuan yang berbeda, jadi Anda berhak menolaknya tanpa kehilangan layanan apa pun.</p>
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
                                <li>Permohonan berstatus <i>Lunas</i> dapat langsung diproses. Yang belum lunas tetap dapat diproses, tetapi Anda akan diberi peringatan dan kejadiannya dicatat di Audit Trail — kecuali super admin mengaktifkan aturan "wajib lunas", yang membuatnya ditolak.</li>
                                <li>Pembayaran tunai ditandai lewat tombol <b>verifikasi tunai</b>. Tombol itu sekaligus menutup tagihan online yang masih terbuka, supaya alumni tidak membayar dua kali.</li>
                                <li>Pengajuan yang sudah lunas tidak dapat dihapus, supaya uangnya tetap tercatat di Laporan Keuangan. Bila memang batal, tolak pengajuannya.</li>
                                <li>Setelah dokumen selesai dilegalisir, jika opsi pengiriman adalah kurir, masukkan Nomor Resi dan klik <b>Mark as Completed</b>. Notifikasi akan otomatis terkirim ke alumni.</li>
                                <li>Jika tidak memenuhi syarat, klik <b>Reject</b> dan sertakan alasannya.</li>
                            </ul>
                        </div>
                    </div>

                    <div class="glass p-6 rounded-[2rem] border border-white/50 shadow-sm">
                        <h3 class="text-lg font-bold text-slate-800 mb-2">Menangani Banyak Permohonan Sekaligus</h3>
                        <div class="space-y-4 text-sm text-slate-600">
                            <p>Daftar permohonan kini dapat <b>dicari</b> (nama, NIM, e-mail, nomor permohonan) dan <b>disaring</b> per status, sehingga tidak perlu lagi menggulir seluruh daftar.</p>
                            <ul class="list-disc pl-5 space-y-1">
                                <li><b>Umur permohonan (SLA).</b> Setiap baris menampilkan sudah berapa hari permohonan itu menunggu. Yang melewati batas ditandai agar tidak terlewat.</li>
                                <li><b>Tindakan massal.</b> Centang beberapa permohonan sekaligus, lalu ubah statusnya dalam satu langkah. Notifikasi tetap terkirim ke masing-masing alumni.</li>
                                <li><b>Label batch.</b> Beri tanda pada sekelompok permohonan yang diproses bersamaan &mdash; berguna saat mengantar berkas ke pimpinan untuk ditandatangani secara kolektif.</li>
                            </ul>
                            <div class="p-4 rounded-2xl bg-amber-50 border border-amber-200">
                                <p class="font-bold text-amber-800 mb-1">Penolakan wajib disertai alasan</p>
                                <p class="text-amber-700">Alasan yang Anda tulis <b>dibaca langsung oleh alumni</b> pada halaman permohonannya. Tulis yang jelas dan dapat ditindaklanjuti &mdash; misalnya &ldquo;Berkas ijazah buram, mohon unggah ulang hasil pindaian yang lebih terang&rdquo; &mdash; bukan sekadar &ldquo;tidak memenuhi syarat&rdquo;.</p>
                            </div>
                        </div>
                    </div>

                    <div class="glass p-6 rounded-[2rem] border border-white/50 shadow-sm hover:shadow-md transition-shadow">
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
                            <p>Di menu <b>Laporan Keuangan</b>, Anda dapat memantau rekapitulasi dana <b>legalisir</b> yang sudah lunas, dipisah per cara bayar: tunai, dan per penyedia pembayaran. Jumlah seluruh kartu selalu sama dengan totalnya.</p>
                            <p>Uang dibaca dalam tiga angka: <b>Dibayar Alumni</b> (yang masuk dari alumni), <b>Biaya Layanan</b> (potongan penyedia pembayaran, diambil dari rincian yang tersimpan saat tagihan itu terbit), dan <b>Diterima Fakultas</b> (selisih keduanya). Pembayaran tunai tidak dipotong apa pun, jadi seluruh uangnya masuk sebagai diterima.</p>
                            <p>Beberapa pengajuan lama lunas sebelum sistem mencatat rincian biaya per transaksi. Biayanya tidak dapat dipastikan, ditulis <b>tanpa rincian</b>, dan dihitung nol — laporan memberi tahu berapa banyak baris seperti itu, alih-alih menebak angkanya.</p>
                            <p><b>Donasi belum masuk laporan ini.</b> Rekap donasi ada di menu <b>Kelola Donasi</b>.</p>
                            <p>Status pembayaran diperbarui dari pemberitahuan penyedia pembayaran, dan diperiksa ulang secara berkala ke sistem mereka — jadi pembayaran tetap tercatat walau pemberitahuannya terlambat atau hilang.</p>
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
                            <p>Isinya dibagi lima bagian; satu tombol Simpan menyimpan semuanya, bukan hanya bagian yang sedang terbuka.</p>
                            <ul class="list-disc pl-5 space-y-1">
                                <li><b>Identitas &amp; Tampilan:</b> logo, favicon, warna institusi, kontak bantuan WhatsApp, isi halaman depan, dan mode perawatan.</li>
                                <li><b>Layanan &amp; Biaya:</b> harga per lembar, zona ongkir, jenis dokumen legalisir, stempel digital, ambang SLA, dan masa berlaku tracer.</li>
                                <li><b>Integrasi &amp; E-mail:</b> SMTP, antrean dan batas kirim harian, Google SSO, serta kunci Analitik AI.</li>
                                <li><b>Keamanan &amp; Akses:</b> penegakan izin peran (RBAC), menu yang terlihat tiap peran, kebijakan kata sandi dan sesi, pembatasan laju, dan retensi Audit Trail.</li>
                                <li><b>Operasional:</b> cadangan basis data, tugas terjadwal (cron), sinkronisasi peta, batas unggah dan impor, serta jumlah baris tabel.</li>
                            </ul>
                            <p class="text-xs text-slate-400">Kredensial dan tarif penyedia pembayaran TIDAK ada di sini — semuanya di menu <b>Gateway Pembayaran</b>. Halaman ini bahkan menolak menyimpannya, supaya tarif tidak pernah berubah tanpa sengaja.</p>
                        </div>
                    </div>

                    <div class="glass p-6 rounded-[2rem] border border-white/50 shadow-sm">
                        <h3 class="text-lg font-bold text-slate-800 mb-2 flex items-center gap-2"><i data-lucide="credit-card" class="w-5 h-5 text-purple-600"></i> Gateway Pembayaran</h3>
                        <div class="space-y-2 text-sm text-slate-600">
                            <p>Menu <b>Gateway Pembayaran</b> mengatur seluruh urusan uang: kredensial, tarif, dan penyedia mana yang dipakai.</p>
                            <ul class="list-disc pl-5 space-y-1">
                                <li><b>Pilihan utama vs yang dipakai.</b> Pilihan utama adalah penyedia yang dikehendaki fakultas. Ia dipakai bila sudah siap — kredensial lengkap, tarif sah dan sudah dicocokkan. Bila belum siap, tagihan otomatis terbit lewat penyedia lain yang siap, dan panel menjelaskan alasannya. Begitu yang utama siap, tagihan berpindah sendiri tanpa menekan tombol apa pun.</li>
                                <li><b>Menyalakan dan mematikan penyedia.</b> Setiap kartu punya sakelar <b>Matikan / Nyalakan</b>. Penyedia yang dimatikan berhenti ditawarkan ke alumni seketika — untuk legalisir maupun donasi — dan tidak dipakai sebagai cadangan. Kredensial serta tarifnya tetap tersimpan, jadi menyalakannya kembali tidak perlu mengisi ulang apa pun, dan tagihan yang sudah terbit tetap dapat dibayar.</li>
                                <li><b>Bila semua penyedia dimatikan</b>, pembayaran online tertutup: halaman Legalisir memberi tahu alumni di muka bahwa pembayarannya tunai di loket, dan halaman Donasi menutup tombol donasi karena donasi tidak punya jalur tunai. Karena itu mematikan penyedia terakhir meminta persetujuan tertulis pada formulirnya.</li>
                                <li><b>Kanal pembayaran.</b> Anda dapat membatasi cara alumni membayar lewat Midtrans (QRIS, VA bank tertentu, e-wallet, gerai retail). Tanpa centang sama sekali, seluruh kanal yang aktif di akun Midtrans ditawarkan. Kanal Flip diatur di dashboard Flip for Business, bukan di sini.</li>
                                <li><b>Cadangan otomatis.</b> Bila penyedia yang sedang dipakai menolak menerbitkan tagihan, sistem langsung mencoba penyedia lain yang menyala sekali lagi; alumni tidak melihat kegagalan. Perpindahan itu tercatat di Audit Trail dan diberitahukan ke Anda. Tagihan yang sudah terbit tetap dibayar lewat penyedia asalnya.</li>
                                <li><b>Tunai</b> selalu menjadi jalan terakhir: tandai lunas lewat verifikasi tunai di Kelola Legalisir.</li>
                                <li><b>Kredensial hanya-tulis.</b> Kunci yang sudah tersimpan tidak pernah ditampilkan kembali; kolom yang dikosongkan berarti "tidak diubah". Mengubah kunci atau mode membatalkan hasil tes koneksi terakhir.</li>
                                <li><b>Tes koneksi dulu.</b> Penyedia hanya bisa diaktifkan bila kredensialnya lengkap, tes koneksi berhasil dalam 24 jam terakhir pada mode yang sama, dan tarifnya sudah Anda cocokkan dengan tarif resmi.</li>
                                <li><b>Tarif.</b> Setiap perubahan menampilkan contoh perhitungan langsung. Tarif hanya berlaku untuk tagihan baru; tagihan yang sudah terbit tidak berubah.</li>
                                <li><b>Empat komponen, dua pemilik.</b> Harga per dokumen dan ongkir (Konfigurasi Sistem), biaya tambahan layanan, dan margin fakultas (Pengaturan umum di panel) semuanya diterima fakultas. Hanya <b>profil biaya tiap penyedia</b> yang menjadi potongan mereka. Margin diatur sekali dan berlaku untuk Flip, Midtrans, maupun tunai.</li>
                                <li><b>Profil biaya yang dikosongkan tidak membuat pembayaran gratis.</b> Penyedia tetap memotong tarifnya; yang hilang hanya penagihannya ke alumni, sehingga fakultas menanggung selisihnya sendiri. Isi dengan tarif resmi penyedia, bukan angka yang diinginkan.</li>
                                <li><b>Pembayaran tunai</b> tidak dikenai biaya penyedia sama sekali: alumni membayar harga dokumen + ongkir + biaya tambahan + margin.</li>
                                <li><b>Transaksi uji Rp 10.000</b> (hanya mode sandbox) membuktikan jalur bayar sampai konfirmasi berfungsi, tanpa menyentuh data legalisir atau donasi.</li>
                                <li><b>Monitor</b> menampilkan transaksi dan pemberitahuan terakhir. Bila alumni mengaku sudah membayar tetapi status belum berubah, tekan <b>Cek status</b> pada barisnya.</li>
                                <li><b>URL callback</b> di halaman ini wajib didaftarkan di dashboard penyedia. Tanpa itu, pembayaran hanya terkonfirmasi lewat pemeriksaan berkala.</li>
                            </ul>
                            <p class="text-xs text-slate-400">Langkah lengkap saat salah satu penyedia bermasalah ada di <b>_dev/PEMBAYARAN.md</b>.</p>
                        </div>
                    </div>

                    <div class="glass p-6 rounded-[2rem] border border-white/50 shadow-sm">
                        <h3 class="text-lg font-bold text-slate-800 mb-2">Kelola Pengguna & Audit Trail</h3>
                        <div class="space-y-2 text-sm text-slate-600">
                            <p>Di menu <b>Kelola User</b>, Anda dapat mengubah *Role* pengguna, mereset password mereka, atau memblokir akses. Menu <b>Audit Trail</b> melacak seluruh pergerakan sensitif pengguna (Login, Update, Delete) beserta IP dan deteksi lokasi (Geocoding).</p>
                            <p><b>Menghapus akun</b> hanya dapat dilakukan Super Administrator, lewat tombol di halaman ini — bukan lewat tautan, supaya tidak dapat dipicu tanpa sengaja. Dua hal ditolak sistem: menghapus akun Anda sendiri, dan menghapus akun yang punya pengajuan legalisir <b>sudah lunas</b>, karena catatan uang itu ikut terhapus dan Laporan Keuangan berubah. Setiap penghapusan tercatat di Audit Trail beserta nama dan e-mail akunnya.</p>
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
                            <div class="p-4 rounded-2xl bg-blue-50 border border-blue-200 mt-3">
                                <p class="font-bold text-blue-800 mb-1">Broadcast tidak terkirim seketika &mdash; ia mengantre</p>
                                <p class="text-blue-700">Menekan &ldquo;Kirim&rdquo; hanya memasukkan e-mail ke antrean. Yang benar-benar mengirimkannya adalah pekerjaan terjadwal <b>Antrean E-mail</b>. Pantau jalannya di menu <b>Kemajuan Broadcast</b>: berapa terkirim, berapa gagal, dan berapa yang masih menunggu.</p>
                            </div>
                        </div>
                    </div>

                    <div class="glass p-6 rounded-[2rem] border border-white/50 shadow-sm hover:shadow-md transition-shadow">
                        <h3 class="text-lg font-bold text-slate-800 mb-2 flex items-center gap-2"><i data-lucide="upload-cloud" class="w-5 h-5 text-indigo-500"></i> Impor Massal Alumni</h3>
                        <div class="space-y-4 text-sm text-slate-600">
                            <p>Menambahkan alumni satu per satu tidak masuk akal untuk ribuan lulusan. Menu <b>Impor Massal Alumni</b> menerima satu berkas CSV.</p>
                            <ul class="list-disc pl-5 space-y-1">
                                <li><b>Kolom wajib hanya dua:</b> <code>nama</code> dan <code>email</code>. Nama kolom Indonesia dikenali (<code>nama</code>, <code>surel</code>), begitu pula bahasa Inggris.</li>
                                <li><b>NIM opsional</b>, tetapi sangat dianjurkan &mdash; NIM dipakai untuk verifikasi legalisir dan pelaporan akreditasi.</li>
                                <li>Kolom lain seperti angkatan, program studi, dan nomor telepon ikut terbaca bila ada.</li>
                            </ul>
                            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200">
                                <p class="font-bold text-slate-700 mb-1">Pratinjau selalu wajib</p>
                                <p>Tidak ada satu baris pun yang tersimpan sebelum Anda melihat pratinjaunya dan menekan konfirmasi. Pratinjau menandai baris bermasalah, e-mail ganda, dan NIM yang sudah dipakai alumni lain. Pratinjau kedaluwarsa setelah 30 menit &mdash; unggah ulang bila terlewat.</p>
                            </div>
                        </div>
                    </div>

                    <div class="glass p-6 rounded-[2rem] border border-white/50 shadow-sm hover:shadow-md transition-shadow">
                        <h3 class="text-lg font-bold text-slate-800 mb-2 flex items-center gap-2"><i data-lucide="clock" class="w-5 h-5 text-rose-500"></i> Tugas Terjadwal (Cron) &mdash; wajib disiapkan</h3>
                        <div class="space-y-4 text-sm text-slate-600">
                            <p>Empat pekerjaan berjalan di latar belakang. Tanpa penjadwalan di server, keempatnya <b>tidak pernah berjalan</b> &mdash; dan kegagalannya tidak memunculkan pesan galat apa pun.</p>
                            <ul class="list-disc pl-5 space-y-1">
                                <li><b>Antrean E-mail</b> &mdash; tiap 5 menit. Tanpa ini, <b>broadcast tidak pernah sampai ke penerimanya.</b></li>
                                <li><b>Peta Persebaran</b> &mdash; tiap 30 menit. Menerjemahkan alamat alumni menjadi titik koordinat.</li>
                                <li><b>Pengingat Tracer</b> &mdash; sebulan sekali.</li>
                                <li><b>Cadangan Basis Data</b> &mdash; sehari sekali, tersimpan ke folder <code>backups/</code>.</li>
                            </ul>
                            <p>URL keempatnya sudah lengkap berikut tokennya di <b>Konfigurasi Sistem &rarr; Tugas Terjadwal (Cron)</b>, dengan tombol salin. Tempelkan ke penjadwal cron di panel hosting.</p>
                            <div class="p-4 rounded-2xl bg-red-50 border border-red-200">
                                <p class="font-bold text-red-800 mb-1">Perlakukan URL itu seperti kata sandi</p>
                                <p class="text-red-700">Siapa pun yang memilikinya dapat memicu pengiriman e-mail ke seluruh alumni. Jangan tempelkan ke dokumen bersama atau grup obrolan.</p>
                            </div>
                        </div>
                    </div>

                    <div class="glass p-6 rounded-[2rem] border border-white/50 shadow-sm hover:shadow-md transition-shadow">
                        <h3 class="text-lg font-bold text-slate-800 mb-2 flex items-center gap-2"><i data-lucide="database" class="w-5 h-5 text-emerald-500"></i> Cadangan Basis Data</h3>
                        <div class="space-y-4 text-sm text-slate-600">
                            <p>Di <b>Konfigurasi Sistem</b> tersedia tombol untuk membuat cadangan seketika, selain yang berjalan terjadwal setiap hari.</p>
                            <p>Berkas cadangan memuat hash kata sandi, e-mail, nomor telepon, dan alamat rumah <b>seluruh alumni</b>. Satu berkas yang bocor setara dengan seluruh basis data bocor.</p>
                            <ul class="list-disc pl-5 space-y-1">
                                <li>Folder <code>backups/</code> sudah diblokir dari akses web.</li>
                                <li>Unduh cadangan secara berkala ke penyimpanan di luar server &mdash; cadangan yang hanya ada di server yang sama tidak menolong saat servernya bermasalah.</li>
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
