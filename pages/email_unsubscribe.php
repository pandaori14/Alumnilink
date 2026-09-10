<?php
// pages/email_unsubscribe.php
if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
}

$email = $_GET['e'] ?? '';
$token = $_GET['t'] ?? '';

// Basic validation
$expected_token = md5($email . 'alumnilink_salt');

$error = null;
$success = null;

if (empty($email) || empty($token) || $token !== $expected_token) {
    $error = "Tautan tidak valid atau telah kedaluwarsa.";
} else {
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $reason = $_POST['reason'] ?? '';
            
            $stmt = $pdo->prepare("INSERT IGNORE INTO unsubscribes (email, reason) VALUES (?, ?)");
            $stmt->execute([$email, $reason]);
            
            // Optionally, also update user's email_notifications setting to 0
            $pdo->prepare("UPDATE users SET email_notifications = 0 WHERE email = ?")->execute([$email]);
            
            $success = "Anda telah berhasil berhenti berlangganan dari email kami.";
        }
    } catch (PDOException $e) {
        $error = "Terjadi kesalahan sistem. Silakan coba lagi nanti.";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Berhenti Berlangganan - AlumniLink</title>
    <!-- Use the same script/css setup if possible, or plain tailwind CDN for simplicity -->
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/fonts/fonts.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-xl p-8 max-w-md w-full border border-slate-100">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-100 text-slate-500 mb-4">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
            </div>
            <h1 class="text-2xl font-bold text-slate-800">Berhenti Berlangganan</h1>
        </div>
        
        <?php if ($error): ?>
            <div class="bg-red-50 text-red-700 p-4 rounded-2xl text-sm mb-6 text-center font-medium">
                <?= htmlspecialchars($error) ?>
            </div>
            <div class="text-center">
                <a href="index.php" class="text-indigo-600 hover:text-indigo-700 font-medium">Kembali ke Beranda</a>
            </div>
        <?php elseif ($success): ?>
            <div class="bg-green-50 text-green-700 p-4 rounded-2xl text-sm mb-6 text-center font-medium">
                <?= htmlspecialchars($success) ?>
            </div>
            <div class="text-center">
                <a href="index.php" class="text-indigo-600 hover:text-indigo-700 font-medium">Kembali ke Beranda</a>
            </div>
        <?php else: ?>
            <p class="text-slate-600 mb-6 text-center text-sm leading-relaxed">
                Anda yakin ingin berhenti menerima email dari kami di <strong class="text-slate-800 break-all"><?= htmlspecialchars($email) ?></strong>?
            </p>
            
            <form method="POST" action="">
                <div class="mb-6">
                    <label class="block text-sm font-medium text-slate-700 mb-2" for="f_reason">Alasan (opsional)</label>
                    <textarea id="f_reason" name="reason" rows="3" class="w-full border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500 px-4 py-3 text-sm transition-colors border" placeholder="Mengapa Anda berhenti berlangganan?"></textarea>
                </div>
                
                <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-semibold py-3 px-4 rounded-xl transition duration-200 flex justify-center items-center gap-2">
                    Berhenti Berlangganan
                </button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
