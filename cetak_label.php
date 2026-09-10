<?php
require_once __DIR__ . '/includes/session_boot.php';
require_once 'config/db.php';
require_once __DIR__ . '/includes/error_page.php';
require_once __DIR__ . '/includes/auth_guard.php';

// Penjagaan lama memakai daftar-hitam ("bukan alumni berarti boleh") dan
// menjawab dengan die() polos. Diselaraskan dengan seluruh handler lain:
// kapabilitas yang sama dengan yang mengelola legalisir, dan halaman galat
// yang sama sehingga uji asap dapat mengenali penolakannya.
require_capability('legalisir.kelola');

/** Batas cetak sekali jalan. */
const CETAK_LABEL_MAX = 50;

// Menerima ?ids=a,b,c (cetak massal) MAUPUN ?id=a (pemanggilan lama dari
// modal dokumen di admin_legalisir.php, yang harus tetap berjalan).
$daftar_id = [];
if (!empty($_GET['ids'])) {
    $daftar_id = array_filter(array_map('trim', explode(',', (string)$_GET['ids'])), 'strlen');
} elseif (!empty($_GET['id'])) {
    $daftar_id = [trim((string)$_GET['id'])];
}
$daftar_id = array_values(array_unique($daftar_id));

if (!$daftar_id) {
    render_error_page('Permintaan Tidak Lengkap', 'ID pengajuan tidak disertakan.', 400, 'alert-circle');
}
$dipotong = false;
if (count($daftar_id) > CETAK_LABEL_MAX) {
    $daftar_id = array_slice($daftar_id, 0, CETAK_LABEL_MAX);
    $dipotong = true;
}

$isi  = implode(',', array_fill(0, count($daftar_id), '?'));
$stmt = $pdo->prepare("
    SELECT lr.*, u.name as user_name, u.email as user_email, u.nim as user_nim, u.phone as user_phone
    FROM legalisir_requests lr
    JOIN users u ON lr.user_id = u.id
    WHERE lr.id IN ($isi)
");
$stmt->execute($daftar_id);
$daftar_req = $stmt->fetchAll();

if (!$daftar_req) {
    render_error_page('Tidak Ditemukan', 'Data pengajuan tidak ditemukan.', 404, 'search-x');
}
// Id yang tidak dikenali dilewati diam-diam, tetapi jumlahnya dilaporkan
// di layar supaya operator tahu bahwa tidak semua yang dipilih tercetak.
$tak_ketemu = count($daftar_id) - count($daftar_req);

// Berkas ini merender satu label per pengajuan; $req dipakai di seluruh
// badan label, jadi diisi ulang pada tiap putaran.
$req = $daftar_req[0];
$shipping = json_decode($req->shipping_address ?? 'null');

// Fetch Institution Settings
$settings_query = $pdo->query("SELECT setting_key, setting_value FROM settings");
$settings = [];
while ($row = $settings_query->fetch()) {
    $settings[$row->setting_key] = $row->setting_value;
}

$inst_name = $settings['institution_name'] ?? 'Universitas Muhammadiyah Surakarta';
$sys_name  = $settings['system_name'] ?? 'AlumniLink UMS';
$sender_phone = $settings['wa_legalisir'] ?: '+62 271 717417';
$sender_address = 'Jl. A. Yani, Mendungan, Pabelan, Kec. Kartasura, Kabupaten Sukoharjo, Jawa Tengah 57162';

// Decode documents
$docs = json_decode($req->documents ?? '[]', true);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak Label Pengiriman<?php echo count($daftar_req) > 1 ? ' (' . count($daftar_req) . ' label)' : ' - ' . htmlspecialchars($req->id); ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/fonts/fonts.css">
    <script src="assets/js/lucide.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .outfit { font-family: 'Outfit', sans-serif; }
        .barcode { font-family: 'Libre Barcode 39', cursive; font-size: 3rem; line-height: 1; }
        
        @media print {
            body { background-color: white !important; margin: 0; padding: 0; }
            .no-print { display: none !important; }
            .print-container { box-shadow: none !important; border: 1px solid #cbd5e1 !important; margin: 0 !important; width: 100% !important; max-width: 100% !important; padding: 2rem !important; }
            /* Satu label per halaman kertas; label terakhir tidak
               menghasilkan halaman kosong tambahan. */
            .print-container { page-break-after: always; break-after: page; break-inside: avoid; }
            .print-container:last-of-type { page-break-after: auto; break-after: auto; }
        }
    </style>
</head>
<body class="bg-slate-100 min-h-screen flex flex-col items-center justify-center p-4 md:p-8">

    <!-- Action Navigation Bar (Screen Only) -->
    <div class="no-print mb-6 w-full max-w-3xl flex flex-col sm:flex-row items-center justify-between gap-4 bg-white px-6 py-4 rounded-2xl shadow-sm border border-slate-200/80">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center shadow-inner">
                <i data-lucide="printer" class="w-5 h-5"></i>
            </div>
            <div>
                <h2 class="font-bold text-slate-800 text-sm">Label Pengiriman Paket</h2>
                <p class="text-xs text-slate-500">
                    <?php echo count($daftar_req); ?> label siap dicetak &middot; gunakan kertas A6 atau A5 untuk hasil terbaik.
                    <?php if ($tak_ketemu > 0): ?>
                        <span class="text-amber-600 font-bold"><?php echo e($tak_ketemu); ?> ID tidak ditemukan dan dilewati.</span>
                    <?php endif; ?>
                    <?php if ($dipotong): ?>
                        <span class="text-amber-600 font-bold">Dibatasi <?php echo CETAK_LABEL_MAX; ?> label sekali cetak.</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2.5 w-full sm:w-auto">
            <button onclick="window.close()" class="flex-1 sm:flex-none px-5 py-2.5 bg-slate-100 text-slate-600 hover:bg-slate-200 rounded-xl text-xs font-bold transition-all text-center shadow-sm">
                Tutup
            </button>
            <button onclick="window.print()" class="flex-1 sm:flex-none px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold transition-all shadow-lg shadow-blue-200 text-center flex items-center justify-center gap-2">
                <i data-lucide="printer" class="w-4 h-4"></i> Cetak Sekarang
            </button>
        </div>
    </div>

    <!-- Main Printable Label Container (satu per pengajuan) -->
    <?php foreach ($daftar_req as $req):
        $shipping = json_decode($req->shipping_address ?? 'null');
        $docs     = json_decode($req->documents ?? '[]', true);
    ?>
    <div class="print-container bg-white w-full max-w-3xl rounded-[2rem] shadow-xl border border-slate-200/80 p-8 md:p-10 space-y-8 text-slate-800 relative overflow-hidden mb-8">
        
        <!-- Header Banner -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-6 border-b-2 border-slate-800 pb-6">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 bg-slate-900 text-white rounded-2xl flex items-center justify-center shadow-md shrink-0">
                    <i data-lucide="graduation-cap" class="w-8 h-8"></i>
                </div>
                <div>
                    <h1 class="text-xl font-black outfit tracking-tight text-slate-900"><?php echo htmlspecialchars($inst_name); ?></h1>
                    <p class="text-xs font-bold tracking-widest text-slate-500 uppercase mt-0.5">Layanan Legalisir Resmi - <?php echo htmlspecialchars($sys_name); ?></p>
                </div>
            </div>
            <div class="flex flex-col sm:items-end gap-2 shrink-0">
                <span class="px-4 py-1.5 bg-slate-900 text-white font-black text-xs uppercase tracking-widest rounded-xl shadow-sm inline-block text-center">
                    <?php echo htmlspecialchars(strtoupper(str_replace('_', ' ', $req->delivery_method))); ?>
                </span>
                <span class="text-xs font-bold text-slate-500 font-mono">ID: <?php echo htmlspecialchars($req->id); ?></span>
            </div>
        </div>

        <!-- Sender & Recipient Two Columns -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 border-b-2 border-slate-800 pb-8">
            <!-- Sender Column -->
            <div class="bg-slate-50 p-6 rounded-2xl border border-slate-200/80 space-y-3">
                <div class="flex items-center gap-2 text-xs font-black text-slate-400 uppercase tracking-widest border-b border-slate-200 pb-2">
                    <i data-lucide="send" class="w-4 h-4 text-slate-600"></i> Informasi Pengirim
                </div>
                <div>
                    <h2 class="text-sm font-black text-slate-900 outfit tracking-tight"><?php echo htmlspecialchars($inst_name); ?> (AlumniLink)</h2>
                    <p class="text-xs font-semibold text-slate-600 mt-1"><?php echo htmlspecialchars($sender_address); ?></p>
                </div>
                <div class="pt-1 flex items-center gap-2 text-xs font-semibold text-slate-600">
                    <i data-lucide="phone" class="w-3.5 h-3.5 text-slate-400"></i> Telp/WA: <?php echo htmlspecialchars($sender_phone); ?>
                </div>
            </div>

            <!-- Recipient Column (Highlighted) -->
            <div class="bg-blue-50/60 p-6 rounded-2xl border-2 border-blue-200 space-y-3 shadow-sm">
                <div class="flex items-center justify-between border-b border-blue-200/80 pb-2">
                    <div class="flex items-center gap-2 text-xs font-black text-blue-800 uppercase tracking-widest">
                        <i data-lucide="map-pin" class="w-4 h-4 text-blue-600"></i> Penerima (Recipient)
                    </div>
                    <span class="px-2 py-0.5 bg-blue-200 text-blue-800 font-bold text-[10px] rounded-md">PRIORITY</span>
                </div>
                <div>
                    <h2 class="text-base font-black text-slate-900 outfit tracking-tight">
                        <?php echo htmlspecialchars($shipping->name ?? $req->user_name); ?>
                    </h2>
                    <div class="flex items-center gap-2 text-xs font-bold text-blue-900 mt-1">
                        <i data-lucide="phone" class="w-3.5 h-3.5 text-blue-600"></i> <?php echo htmlspecialchars($shipping->phone ?? $req->user_phone ?? '-'); ?>
                    </div>
                </div>
                <div class="pt-2 border-t border-blue-100">
                    <p class="text-xs font-bold text-slate-700 leading-relaxed uppercase">
                        <?php echo htmlspecialchars($shipping->street ?? '-'); ?><br>
                        Kec. <?php echo htmlspecialchars($shipping->district ?? '-'); ?>, Kota/Kab. <?php echo htmlspecialchars($shipping->city ?? '-'); ?><br>
                        Prov. <?php echo htmlspecialchars($shipping->province ?? '-'); ?> <?php echo htmlspecialchars($shipping->postal ?? ''); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Package Content & Barcode Section -->
        <div class="flex flex-col sm:flex-row items-center justify-between gap-6 pt-2">
            <!-- Package Content Details -->
            <div class="space-y-2 w-full sm:w-1/2">
                <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest flex items-center gap-1.5">
                    <i data-lucide="package" class="w-4 h-4 text-slate-600"></i> Isi Paket Dokumen
                </h3>
                <div class="flex flex-wrap gap-1.5 pt-1">
                    <?php if (!empty($docs)): ?>
                        <?php foreach ($docs as $doc): ?>
                            <span class="px-3 py-1 bg-slate-100 border border-slate-200 text-slate-700 font-bold text-xs rounded-xl inline-block">
                                <?php echo htmlspecialchars($doc['name'] ?? $doc['type'] ?? 'Dokumen'); ?>
                                <?php if (isset($doc['qty'])) echo e(' (' . $doc['qty'] . 'x)'); ?>
                            </span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="text-xs text-slate-500 font-medium">Dokumen Legalisir Resmi</span>
                    <?php endif; ?>
                </div>
                <p class="text-[11px] font-bold text-amber-800 bg-amber-50 border border-amber-200 p-2.5 rounded-xl mt-4 leading-normal">
                    ⚠️ <strong>PERHATIAN KURIR:</strong> Dokumen penting universitas. Jangan dilipat, dibanting, atau terkena air.
                </p>
            </div>

            <!-- Visual Barcode Display -->
            <div class="flex flex-col items-center justify-center bg-slate-50 border border-slate-200 p-6 rounded-2xl w-full sm:w-auto shrink-0 shadow-inner">
                <div class="barcode text-slate-900 tracking-widest select-none">
                    *<?php echo htmlspecialchars($req->tracking_number ?: $req->id); ?>*
                </div>
                <span class="text-xs font-mono font-bold text-slate-600 tracking-wider mt-2 bg-white px-3 py-1 rounded-lg border border-slate-200 shadow-sm">
                    <?php echo htmlspecialchars($req->tracking_number ?: $req->id); ?>
                </span>
                <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mt-1">ID Legalisasi</span>
            </div>
        </div>

        <!-- Footer / Stamp -->
        <div class="border-t border-slate-200/80 pt-6 flex flex-col sm:flex-row items-center justify-between text-xs text-slate-400 font-medium gap-4">
            <div class="flex items-center gap-2">
                <i data-lucide="shield-check" class="w-4 h-4 text-emerald-600"></i>
                <span>Telah diverifikasi oleh Sistem AlumniLink UMS</span>
            </div>
            <div>
                Tanggal Cetak: <?php echo date('d/m/Y H:i'); ?>
            </div>
        </div>

    </div>
    <?php endforeach; ?>

    <script>
        // Initialize Lucide Icons
        lucide.createIcons();

        // Auto-trigger print dialog on load
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>
