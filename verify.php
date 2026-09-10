<?php
require_once 'config/db.php';

$token = $_GET['token'] ?? '';

if (empty($token)) {
    $error = "Token verifikasi tidak valid atau tidak disediakan.";
} else {
    $stmt = $pdo->prepare("
        SELECT lr.*, u.name as alumni_name, u.nim as alumni_nim 
        FROM legalisir_requests lr
        JOIN users u ON lr.user_id = u.id
        WHERE lr.verification_token = ? AND lr.status = 'completed'
    ");
    $stmt->execute([$token]);
    $request = $stmt->fetch();

    if (!$request) {
        $error = "Dokumen tidak ditemukan, palsu, atau belum diverifikasi secara sah.";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Dokumen Digital - AlumniLink</title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/fonts/fonts.css">
    <script src="assets/js/lucide.min.js"></script>
    <style>
        body { font-family: 'Outfit', sans-serif; background-color: #f8fafc; }
        .glass { background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    
    <div class="max-w-xl w-full">
        <?php if (isset($error)): ?>
            <!-- Invalid / Error State -->
            <div class="glass p-10 rounded-[2.5rem] shadow-2xl border border-white text-center relative overflow-hidden">
                <div class="absolute top-0 left-0 w-full h-2 bg-red-500"></div>
                <div class="w-24 h-24 bg-red-50 text-red-600 rounded-full flex items-center justify-center mx-auto mb-6 shadow-inner shadow-red-200/50">
                    <i data-lucide="x-circle" class="w-12 h-12"></i>
                </div>
                <h1 class="text-3xl font-black text-slate-800 mb-2">Verifikasi Gagal</h1>
                <p class="text-slate-500 font-medium mb-8"><?php echo $error; ?></p>
                <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100 text-sm text-slate-400">
                    Jika Anda merasa ini adalah kesalahan, silakan hubungi pihak Fakultas Kedokteran Universitas Muhammadiyah Surakarta.
                </div>
            </div>
        <?php else: ?>
            <!-- Valid State -->
            <div class="glass p-10 rounded-[2.5rem] shadow-2xl border border-white relative overflow-hidden">
                <div class="absolute top-0 left-0 w-full h-2 bg-emerald-500"></div>
                
                <div class="text-center mb-8">
                    <div class="w-24 h-24 bg-emerald-50 text-emerald-600 rounded-full flex items-center justify-center mx-auto mb-6 shadow-inner shadow-emerald-200/50 relative">
                        <i data-lucide="shield-check" class="w-12 h-12 relative z-10"></i>
                        <div class="absolute inset-0 border-4 border-emerald-500 rounded-full animate-ping opacity-20"></div>
                    </div>
                    <h1 class="text-3xl font-black text-slate-800 mb-2">Digital Signature Valid</h1>
                    <p class="text-emerald-600 font-bold tracking-widest uppercase text-sm">Dokumen Resmi Terverifikasi</p>
                </div>

                <div class="space-y-4 mb-8">
                    <div class="flex items-center justify-between p-4 bg-white/60 rounded-2xl border border-slate-100">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-widest">Nama Alumni</span>
                        <span class="text-sm font-black text-slate-800 uppercase text-right"><?php echo htmlspecialchars($request->alumni_name); ?></span>
                    </div>
                    <div class="flex items-center justify-between p-4 bg-white/60 rounded-2xl border border-slate-100">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-widest">NIM / ID</span>
                        <span class="text-sm font-black text-slate-800 font-mono text-right"><?php echo htmlspecialchars($request->alumni_nim); ?></span>
                    </div>
                    <div class="flex items-center justify-between p-4 bg-white/60 rounded-2xl border border-slate-100">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-widest">Waktu Pengesahan</span>
                        <span class="text-sm font-black text-slate-800 text-right"><?php echo date('d F Y, H:i', strtotime($request->verified_at)); ?> WIB</span>
                    </div>
                    <div class="flex items-center justify-between p-4 bg-white/60 rounded-2xl border border-slate-100">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-widest">ID Dokumen</span>
                        <span class="text-xs font-black text-slate-400 font-mono text-right">#<?php echo e(strtoupper(substr($request->id, -12))); ?></span>
                    </div>
                </div>

                <a href="view_softcopy.php?id=<?php echo urlencode($request->id); ?>&token=<?php echo urlencode($token); ?>" class="w-full flex items-center justify-center gap-2 py-4 bg-blue-600 text-white rounded-2xl font-bold shadow-lg shadow-blue-200 hover:bg-blue-700 active:scale-95 transition-all">
                    <i data-lucide="file-text" class="w-5 h-5"></i>
                    Lihat Dokumen Asli
                </a>
                
                <p class="text-center text-[10px] text-slate-400 mt-6 uppercase tracking-widest font-bold">
                    Diterbitkan oleh Fakultas Kedokteran<br>Universitas Muhammadiyah Surakarta
                </p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
