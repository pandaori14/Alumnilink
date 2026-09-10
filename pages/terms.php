<?php
// Settings sudah di-load oleh index.php — ambil langsung dari $pdo
$stmt = $pdo->query("SELECT * FROM settings");
$raw_settings = $stmt->fetchAll();
$sys = [];
foreach ($raw_settings as $s) {
    $sys[$s->setting_key] = $s->setting_value;
}
$institution_name = $sys['institution_name'] ?? 'Universitas Muhammadiyah Surakarta';
$system_logo      = !empty($sys['system_logo']) ? $sys['system_logo'] : 'uploads/system/logo_1778236863.png';

// Harga & Biaya Legalisir
$price_per_doc = (int)($sys['price_per_doc'] ?? 0);
$admin_fee     = (int)($sys['admin_fee'] ?? 0);
$doc_types     = json_decode($sys['legalisir_document_types'] ?? '[]', true) ?: [];
$shipping_zones = json_decode($sys['shipping_zones'] ?? '[]', true) ?: [];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Syarat & Ketentuan — AlumniLink <?php echo htmlspecialchars($institution_name); ?></title>
    <meta name="description" content="Syarat dan ketentuan penggunaan platform AlumniLink <?php echo htmlspecialchars($institution_name); ?>. Baca selengkapnya sebelum menggunakan layanan kami.">
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($system_logo); ?>">
    <link rel="shortcut icon" type="image/png" href="<?php echo htmlspecialchars($system_logo); ?>">
    <?php
    $base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
    ?>
    <base href="<?php echo $base_path; ?>">
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="assets/js/lucide.min.js"></script>
    <link rel="stylesheet" href="assets/fonts/fonts.css">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; scroll-behavior: smooth; }
        .outfit { font-family: 'Outfit', sans-serif; }
        .glass { background: rgba(255,255,255,0.7); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.5); }
        .bg-gradient-mesh {
            background-color: #f0f4f8;
            background-image:
                radial-gradient(at 40% 20%, hsla(216,100%,94%,1) 0px, transparent 50%),
                radial-gradient(at 80% 0%,  hsla(189,100%,92%,1) 0px, transparent 50%),
                radial-gradient(at 0%  50%, hsla(216,100%,94%,1) 0px, transparent 50%),
                radial-gradient(at 80% 50%, hsla(223,100%,93%,1) 0px, transparent 50%),
                radial-gradient(at 0% 100%, hsla(189,100%,92%,1) 0px, transparent 50%);
        }
        .bg-grid-pattern {
            position: fixed; top:0; left:0; width:100%; height:100%;
            background-image:
                linear-gradient(to right,  rgba(99,102,241,0.04) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(99,102,241,0.04) 1px, transparent 1px);
            background-size: 50px 50px;
            z-index: -11;
            mask-image: radial-gradient(circle at center, black 40%, transparent 80%);
            -webkit-mask-image: radial-gradient(circle at center, black 40%, transparent 80%);
        }
        .section-num {
            display: inline-flex; align-items: center; justify-content: center;
            width: 2rem; height: 2rem; border-radius: 0.75rem;
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            color: white; font-weight: 900; font-size: 0.75rem;
            flex-shrink: 0; margin-right: 0.75rem; font-family: 'Outfit', sans-serif;
        }
    </style>
</head>
<body class="bg-gradient-mesh min-h-screen text-slate-800 relative overflow-x-hidden">

<div class="bg-grid-pattern"></div>

<!-- Decorative Blobs -->
<div class="fixed top-0 left-0 w-full h-full overflow-hidden -z-10 pointer-events-none">
    <div class="absolute top-10 left-10 w-96 h-96 bg-blue-400 rounded-full mix-blend-multiply filter blur-3xl opacity-15"></div>
    <div class="absolute top-40 right-20 w-96 h-96 bg-indigo-400 rounded-full mix-blend-multiply filter blur-3xl opacity-15"></div>
</div>

<!-- Navbar -->
<nav class="fixed w-full z-50">
    <div class="max-w-5xl mx-auto px-6 py-4">
        <div class="glass rounded-full px-6 py-3 flex items-center justify-between shadow-sm">
            <a href="index.php?page=landing" class="flex items-center gap-3">
                <img src="<?php echo htmlspecialchars($system_logo); ?>" alt="Logo" class="w-8 h-8 object-contain" width="32" height="32">
                <span class="font-black outfit tracking-tighter text-lg text-slate-800">Alumni<span class="text-blue-600">Link</span></span>
            </a>
            <a href="index.php?page=landing" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-blue-600 transition-colors">
                <i data-lucide="arrow-left" class="w-4 h-4"></i> Kembali
            </a>
        </div>
    </div>
</nav>

<!-- Main Content -->
<main class="pt-36 pb-24 px-6">
    <div class="max-w-4xl mx-auto">

        <!-- Header -->
        <div class="text-center mb-14">
            <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/60 border border-white/80 shadow-sm text-sm font-bold text-blue-600 mb-6">
                <i data-lucide="shield-check" class="w-4 h-4"></i>
                Dokumen Legal
            </div>
            <h1 class="text-4xl md:text-5xl font-black outfit text-slate-900 tracking-tight mb-4">
                Syarat &amp; Ketentuan
            </h1>
            <p class="text-slate-500 font-medium max-w-xl mx-auto">
                Platform <strong>AlumniLink <?php echo htmlspecialchars($institution_name); ?></strong>.<br>
                Harap baca dengan saksama sebelum menggunakan layanan kami.
            </p>
            <div class="mt-6 inline-flex items-center gap-2 text-xs text-slate-400 font-medium">
                <i data-lucide="calendar" class="w-3.5 h-3.5"></i>
                Terakhir diperbarui: <?php echo date('d F Y'); ?>
            </div>
        </div>

        <!-- T&C Content Card -->
        <article class="glass rounded-[3rem] shadow-sm overflow-hidden">

            <!-- Intro Banner -->
            <div class="bg-gradient-to-r from-blue-600 to-indigo-600 px-10 py-8 text-white">
                <h2 class="text-xl font-black outfit mb-2">Ketentuan Umum Penggunaan</h2>
                <p class="text-blue-100 text-sm leading-relaxed">
                    Dengan mengakses dan menggunakan platform AlumniLink, Anda menyatakan telah membaca, memahami, dan menyetujui seluruh syarat dan ketentuan yang tercantum di bawah ini. Jika Anda tidak menyetujuinya, mohon untuk tidak melanjutkan penggunaan layanan kami.
                </p>
            </div>

            <div class="p-10 md:p-14 space-y-10">

                <!-- Section 1 -->
                <section>
                    <h2 class="flex items-center text-lg font-black outfit text-slate-800 mb-4">
                        <span class="section-num">1</span> Kondisi Penggunaan
                    </h2>
                    <p class="text-slate-600 text-sm leading-relaxed ml-11">
                        AlumniLink ditawarkan kepada Anda, pengguna, dengan syarat bahwa Anda menerima ketentuan, syarat, dan pemberitahuan yang tercantum di sini. Platform ini merupakan layanan resmi yang dikelola oleh <strong><?php echo htmlspecialchars($institution_name); ?></strong> untuk kepentingan alumni, institusi, dan pemangku kepentingan terkait.
                    </p>
                </section>

                <hr class="border-slate-100">

                <!-- Section 2 -->
                <section>
                    <h2 class="flex items-center text-lg font-black outfit text-slate-800 mb-4">
                        <span class="section-num">2</span> Gambaran Umum Layanan
                    </h2>
                    <p class="text-slate-600 text-sm leading-relaxed ml-11 mb-4">
                        Platform AlumniLink menyediakan tiga layanan utama bagi alumni terdaftar:
                    </p>
                    <ul class="ml-11 space-y-3">
                        <li class="flex items-start gap-3">
                            <div class="w-6 h-6 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <i data-lucide="file-search" class="w-3.5 h-3.5"></i>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-slate-700">Tracer Alumni</p>
                                <p class="text-xs text-slate-500 leading-relaxed">Pendataan jejak karir alumni pasca kelulusan untuk mendukung akreditasi dan pengembangan kurikulum institusi.</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-3">
                            <div class="w-6 h-6 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <i data-lucide="award" class="w-3.5 h-3.5"></i>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-slate-700">Legalisir Dokumen Digital</p>
                                <p class="text-xs text-slate-500 leading-relaxed">Pengajuan legalisir ijazah dan transkrip nilai secara daring dengan verifikasi stempel digital barcode yang sah dan terenkripsi.</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-3">
                            <div class="w-6 h-6 rounded-lg bg-pink-50 text-pink-600 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <i data-lucide="heart" class="w-3.5 h-3.5"></i>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-slate-700">Program Donasi</p>
                                <p class="text-xs text-slate-500 leading-relaxed">Fasilitas penggalangan dana alumni secara transparan untuk mendukung kemajuan almamater dan mahasiswa yang membutuhkan.</p>
                            </div>
                        </li>
                    </ul>
                </section>

                <hr class="border-slate-100">

                <!-- Section 3 -->
                <section>
                    <h2 class="flex items-center text-lg font-black outfit text-slate-800 mb-4">
                        <span class="section-num">3</span> Pendaftaran &amp; Akun Pengguna
                    </h2>
                    <div class="ml-11 space-y-3 text-slate-600 text-sm leading-relaxed">
                        <p>Untuk menggunakan layanan AlumniLink, Anda diwajibkan mendaftarkan akun dengan informasi yang akurat, lengkap, dan terkini. Anda sepenuhnya bertanggung jawab atas:</p>
                        <ul class="list-disc list-inside space-y-1 ml-2 text-slate-500">
                            <li>Kerahasiaan kata sandi dan keamanan akun Anda</li>
                            <li>Seluruh aktivitas yang terjadi di bawah akun Anda</li>
                            <li>Kebenaran data diri yang diinputkan ke dalam sistem</li>
                        </ul>
                        <p>Penyalahgunaan akun, termasuk penggunaan identitas palsu atau impersonasi pihak lain, merupakan pelanggaran serius yang dapat mengakibatkan penangguhan atau penghapusan akun secara permanen.</p>
                    </div>
                </section>

                <hr class="border-slate-100">

                <!-- Section 4 -->
                <section>
                    <h2 class="flex items-center text-lg font-black outfit text-slate-800 mb-4">
                        <span class="section-num">4</span> Layanan Legalisir — Ketentuan &amp; Rincian Biaya
                    </h2>
                    <div class="ml-11 space-y-5 text-slate-600 text-sm leading-relaxed">
                        <p>Penggunaan layanan Legalisir Digital tunduk pada ketentuan tambahan berikut:</p>
                        <ul class="list-disc list-inside space-y-1 ml-2 text-slate-500">
                            <li>Dokumen yang diunggah harus merupakan dokumen asli milik pengguna yang mendaftar</li>
                            <li>Biaya legalisir yang telah dibayarkan bersifat <strong>non-refundable</strong> setelah proses verifikasi dimulai</li>
                            <li>Waktu pemrosesan bergantung pada antrean dan ketersediaan administrator</li>
                            <li>Hasil legalisir digital bersifat sah dan dapat diverifikasi melalui kode QR yang tersemat</li>
                            <li>Penggunaan dokumen yang dilegalisir untuk keperluan penipuan adalah tindakan ilegal dan dapat dipidana</li>
                        </ul>

                        <!-- Pricing Table -->
                        <div class="mt-6 rounded-[1.5rem] overflow-hidden border border-indigo-100 shadow-sm">
                            <!-- Header -->
                            <div class="bg-gradient-to-r from-indigo-600 to-blue-600 px-6 py-4 flex items-center gap-3">
                                <i data-lucide="receipt" class="w-4 h-4 text-white"></i>
                                <span class="text-sm font-black text-white uppercase tracking-widest">Rincian Biaya Layanan</span>
                                <span class="ml-auto text-[10px] text-indigo-200 font-bold">Ditetapkan oleh administrator &bull; dapat berubah sewaktu-waktu</span>
                            </div>

                            <div class="bg-white divide-y divide-slate-100">
                                <!-- Per-doc pricing -->
                                <div class="px-6 py-4">
                                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">Biaya Per Paket Dokumen</p>
                                    <p class="text-[10px] text-slate-400 mb-3">1 paket = 10 lembar dokumen yang dilegalisir</p>
                                    <?php if (!empty($doc_types)): ?>
                                    <div class="space-y-2">
                                        <?php foreach ($doc_types as $dt): ?>
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-2">
                                                <i data-lucide="file-text" class="w-3.5 h-3.5 text-indigo-400"></i>
                                                <span class="text-sm font-semibold text-slate-700"><?php echo htmlspecialchars($dt['name'] ?? $dt['id']); ?></span>
                                            </div>
                                            <span class="font-black text-indigo-600 text-sm">
                                                Rp <?php echo number_format($price_per_doc, 0, ',', '.'); ?> <span class="text-slate-400 font-normal text-xs">/paket</span>
                                            </span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php else: ?>
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm font-semibold text-slate-700">Biaya per paket dokumen</span>
                                        <span class="font-black text-indigo-600 text-sm">Rp <?php echo number_format($price_per_doc, 0, ',', '.'); ?> <span class="text-slate-400 font-normal text-xs">/paket</span></span>
                                    </div>
                                    <?php endif; ?>
                                </div>



                                <!-- Disclaimer -->
                                <div class="px-6 py-5 bg-amber-50 space-y-3">
                                    <p class="text-[11px] text-amber-700 font-medium leading-relaxed">
                                        <strong>⚠ Catatan Harga:</strong> Harga per paket di atas adalah biaya dokumen saja. Terdapat <strong>biaya tambahan lain</strong> yaitu biaya administrasi dan biaya pengiriman yang <strong>tidak ditampilkan di sini</strong>, namun akan tercantum secara terperinci pada <strong>invoice/tagihan sebelum halaman pembayaran</strong>. Pastikan Anda memeriksa rincian invoice sebelum melakukan pembayaran.
                                    </p>
                                    <p class="text-[10px] text-amber-600 italic">
                                        Seluruh biaya mengacu pada ketetapan yang berlaku dan dapat berubah sewaktu-waktu.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <hr class="border-slate-100">

                <!-- Section 5 -->
                <section>
                    <h2 class="flex items-center text-lg font-black outfit text-slate-800 mb-4">
                        <span class="section-num">5</span> Kebijakan Privasi &amp; Perlindungan Data
                    </h2>
                    <div class="ml-11 space-y-3 text-slate-600 text-sm leading-relaxed">
                        <p>Kami berkomitmen menjaga kerahasiaan dan keamanan data pribadi Anda sesuai regulasi perlindungan data yang berlaku. Data yang Anda berikan kepada kami digunakan semata-mata untuk:</p>
                        <ul class="list-disc list-inside space-y-1 ml-2 text-slate-500">
                            <li>Verifikasi identitas dan kelengkapan layanan</li>
                            <li>Keperluan statistik dan laporan akreditasi institusi</li>
                            <li>Komunikasi resmi terkait layanan yang Anda gunakan</li>
                        </ul>
                        <p class="mt-2 px-4 py-3 bg-amber-50 border border-amber-100 rounded-2xl text-amber-700 text-xs font-medium">
                            <strong>⚠ Penting:</strong> Kami tidak pernah menjual, menyewakan, atau membagikan data pribadi Anda kepada pihak ketiga tanpa persetujuan eksplisit Anda, kecuali diwajibkan oleh hukum yang berlaku di Indonesia.
                        </p>
                    </div>
                </section>

                <hr class="border-slate-100">

                <!-- Section 6 -->
                <section>
                    <h2 class="flex items-center text-lg font-black outfit text-slate-800 mb-4">
                        <span class="section-num">6</span> Hak Kekayaan Intelektual
                    </h2>
                    <p class="text-slate-600 text-sm leading-relaxed ml-11">
                        Seluruh konten dalam platform AlumniLink, termasuk namun tidak terbatas pada desain antarmuka, logo, teks, kode perangkat lunak, dan materi grafis adalah milik <strong><?php echo htmlspecialchars($institution_name); ?></strong> dan dilindungi oleh hukum hak cipta Indonesia. Reproduksi, distribusi, atau modifikasi tanpa izin tertulis dilarang keras.
                    </p>
                </section>

                <hr class="border-slate-100">

                <!-- Section 7 -->
                <section>
                    <h2 class="flex items-center text-lg font-black outfit text-slate-800 mb-4">
                        <span class="section-num">7</span> Pembatasan Tanggung Jawab
                    </h2>
                    <p class="text-slate-600 text-sm leading-relaxed ml-11">
                        Platform AlumniLink disediakan sebagaimana adanya ("as is"). Kami tidak bertanggung jawab atas kerugian yang timbul akibat gangguan sistem, ketidaktersediaan layanan sementara, atau kesalahan dalam penggunaan platform yang disebabkan oleh pengguna. Kami senantiasa berusaha memastikan ketersediaan sistem namun tidak dapat menjamin layanan bebas dari gangguan 100% sepanjang waktu.
                    </p>
                </section>

                <hr class="border-slate-100">

                <!-- Section 8 -->
                <section>
                    <h2 class="flex items-center text-lg font-black outfit text-slate-800 mb-4">
                        <span class="section-num">8</span> Penangguhan &amp; Penghentian Akun
                    </h2>
                    <div class="ml-11 space-y-2 text-slate-600 text-sm leading-relaxed">
                        <p>Administrator berhak menangguhkan atau menghapus akun pengguna tanpa pemberitahuan sebelumnya apabila:</p>
                        <ul class="list-disc list-inside space-y-1 ml-2 text-slate-500">
                            <li>Terdeteksi pelanggaran terhadap syarat dan ketentuan ini</li>
                            <li>Penggunaan platform untuk tujuan penipuan atau aktivitas ilegal</li>
                            <li>Unggah dokumen palsu atau manipulasi data sistem</li>
                            <li>Terdapat permintaan resmi dari pihak berwenang</li>
                        </ul>
                    </div>
                </section>

                <hr class="border-slate-100">

                <!-- Section 9 -->
                <section>
                    <h2 class="flex items-center text-lg font-black outfit text-slate-800 mb-4">
                        <span class="section-num">9</span> Perubahan Syarat &amp; Ketentuan
                    </h2>
                    <p class="text-slate-600 text-sm leading-relaxed ml-11">
                        <?php echo htmlspecialchars($institution_name); ?> berhak mengubah, memodifikasi, atau memperbarui syarat dan ketentuan ini sewaktu-waktu tanpa pemberitahuan terlebih dahulu. Penggunaan platform setelah perubahan dilakukan dianggap sebagai persetujuan Anda terhadap ketentuan yang baru.
                    </p>
                </section>

                <hr class="border-slate-100">

                <!-- Section 10 -->
                <section>
                    <h2 class="flex items-center text-lg font-black outfit text-slate-800 mb-4">
                        <span class="section-num">10</span> Hukum yang Berlaku
                    </h2>
                    <p class="text-slate-600 text-sm leading-relaxed ml-11">
                        Syarat dan ketentuan ini diatur dan ditafsirkan berdasarkan hukum yang berlaku di Republik Indonesia, termasuk Undang-Undang No. 11 Tahun 2008 tentang Informasi dan Transaksi Elektronik (ITE) beserta perubahannya, serta regulasi perlindungan data pribadi yang berlaku.
                    </p>
                </section>

                <hr class="border-slate-100">

                <!-- Section 11 -->
                <section>
                    <h2 class="flex items-center text-lg font-black outfit text-slate-800 mb-4">
                        <span class="section-num">11</span> Pertanyaan &amp; Umpan Balik
                    </h2>
                    <p class="text-slate-600 text-sm leading-relaxed ml-11">
                        Jika Anda memiliki pertanyaan, masukan, atau keberatan terkait syarat dan ketentuan ini, silakan hubungi kami melalui saluran kontak resmi yang tersedia di halaman <a href="index.php?page=landing#kontak" class="text-blue-600 font-bold hover:underline">Hubungi Kami</a>.
                    </p>
                </section>

                <!-- Footer Note -->
                <div class="mt-10 p-6 bg-slate-50 rounded-3xl border border-slate-100 text-center">
                    <p class="text-xs text-slate-400 font-medium">
                        &copy; <?php echo date('Y'); ?> <strong><?php echo htmlspecialchars($institution_name); ?></strong> — AlumniLink Platform.<br>
                        Hak cipta dilindungi undang-undang. All Rights Reserved.
                    </p>
                </div>

            </div>
        </article>

        <!-- Back to Landing -->
        <div class="text-center mt-10">
            <a href="landing" class="inline-flex items-center gap-2 bg-slate-900 text-white px-8 py-4 rounded-full font-bold text-sm hover:bg-slate-800 hover:scale-105 transition-all shadow-lg">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>
                Kembali ke Halaman Utama
            </a>
        </div>

    </div>
</main>

<!-- Footer -->
<footer class="bg-white/60 border-t border-white/80 py-8 px-6 text-center backdrop-blur-sm">
    <p class="text-slate-400 text-xs font-medium">
        &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($institution_name); ?> &mdash; AlumniLink. Hak cipta dilindungi undang-undang.
    </p>
</footer>

<script>
    lucide.createIcons();

    // Premium Universal URL Masking for Status Bar & Copy Link: Convert all index.php?page= links to clean encrypted/RESTful paths
    function maskAllLinks() {
        document.querySelectorAll('a').forEach(a => {
            const origHref = a.getAttribute('href');
            if (origHref && origHref.includes('index.php?page=')) {
                try {
                    const urlObj = new URL(a.href, window.location.origin);
                    const pageParam = urlObj.searchParams.get('page');
                    const idParam = urlObj.searchParams.get('id');
                    const actionParam = urlObj.searchParams.get('action');
                    
                    let cleanPath = pageParam;
                    if (idParam) cleanPath += '/' + idParam;
                    if (actionParam) cleanPath += '/' + actionParam;
                    
                    a.setAttribute('href', cleanPath);
                    a.removeAttribute('onclick');
                } catch(err) {}
            }
        });
    }

    maskAllLinks();
    document.addEventListener('DOMContentLoaded', maskAllLinks);
</script>
</body>
</html>
