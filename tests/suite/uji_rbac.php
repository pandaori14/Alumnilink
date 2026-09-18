<?php
/**
 * Uji penegakan RBAC.
 *
 * Menyalakan settings.rbac_enforce = 1, lalu untuk SETIAP peran staf
 * memanggil SETIAP handler yang dijaga kapabilitas — memastikan yang di
 * dalam wewenangnya diterima dan yang di luar wewenangnya ditolak 403.
 *
 * Dijalankan dengan sesi palsu berperan staf. Saat ini belum ada satu pun
 * akun staf sungguhan di basis data, jadi inilah saat paling aman untuk
 * menyalakan penegakan: tidak ada yang bisa terkunci.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once AKAR . '/includes/auth_guard.php';

$BASE = uji_base_url();
function panggil($sid, $url, $post = null) {
    $ch = curl_init($url);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_HEADER => true,
          CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIE => 'PHPSESSID=' . $sid];
    if ($post !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = http_build_query($post); }
    curl_setopt_array($ch, $o);
    $raw = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return [$c, (string)$raw];
}

// ── Nyalakan penegakan untuk durasi uji ─────────────────────────────
$semula = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='rbac_enforce'")->fetchColumn();
$pdo->exec("INSERT INTO settings (setting_key,setting_value) VALUES ('rbac_enforce','1')
            ON DUPLICATE KEY UPDATE setting_value='1'");
echo "rbac_enforce dinyalakan untuk pengujian.\n\n";

$csrf = bin2hex(random_bytes(16));
$uid  = $pdo->query("SELECT id FROM users WHERE role='super_admin' LIMIT 1")->fetchColumn();

// Endpoint -> kapabilitas yang dijaganya (dipetakan dari require_capability()).
$endpoint = [
    'handlers/admin_alumni_handler.php?action=save' => 'alumni.kelola',
    'handlers/admin_alumni_import.php?action=preview' => 'alumni.kelola',
    'handlers/admin_update_legalisir.php'          => 'legalisir.kelola',
    'handlers/admin_legalisir_bulk.php'            => 'legalisir.kelola',
    'handlers/admin_delete_legalisir.php?id=X'     => 'legalisir.hapus',
    'handlers/admin_verify_cash.php?id=X'          => 'pembayaran.verifikasi',
    'handlers/admin_tracer_handler.php'            => 'tracer.kelola',
    'handlers/admin_employer_invite.php'           => 'tracer.kelola',
    'handlers/admin_news_handler.php'              => 'konten.kelola',
    'handlers/admin_major_handler.php'             => 'master.kelola',
    'handlers/admin_user_handler.php'              => 'pengguna.kelola',
    'handlers/admin_broadcast_handler.php'         => 'broadcast.kirim',
    'handlers/admin_campaign_handler.php'          => 'keuangan.kelola',
    'handlers/export_keuangan.php'                 => 'keuangan.lihat',
    'handlers/export_handler.php?type=alumni'      => 'laporan.ekspor',
    'handlers/admin_use_repository.php'            => 'repositori.kelola',
    'api/admin/tracer_analytics.php'               => 'analitik.lihat',
    'cetak_label.php?id=X'                         => 'legalisir.kelola',
    'handlers/admin_payment_gateway_handler.php'   => 'pengaturan.kelola',
];

$matrix = capability_matrix();
$peran  = ['admin_tracer', 'admin_legalisir', 'keuangan'];

foreach ($peran as $r) {
    printf("=== %s ===\n", $r);
    $sid = sesi_palsu($uid, $r, $csrf);
    $boleh = 0; $tolak = 0;

    foreach ($endpoint as $ep => $kap) {
        $harus_boleh = in_array($r, $matrix[$kap] ?? [], true);
        // CSRF disertakan supaya yang ditolak benar-benar ditolak KARENA
        // peran, bukan karena token.
        [$c, $raw] = panggil($sid, "$BASE/$ep", ['csrf_token' => $csrf, 'id' => 'X']);

        $ditolak = ($c === 403) || preg_match('/akses ditolak/i', $raw);

        if ($harus_boleh) {
            // "Boleh" berarti TIDAK ditolak karena peran. Kode selain 403
            // (302 redirect, 200, bahkan 400 karena data uji tidak lengkap)
            // sama-sama berarti penjagaan peran sudah dilewati.
            cek(!$ditolak, sprintf('%-42s boleh (%s)', $ep, $kap), "HTTP $c");
            $boleh++;
        } else {
            cek($ditolak, sprintf('%-42s DITOLAK (%s)', $ep, $kap), "HTTP $c");
            $tolak++;
        }
    }
    printf("  -> %d boleh, %d ditolak\n\n", $boleh, $tolak);
    @unlink((session_save_path() ?: sys_get_temp_dir()) . '/sess_' . $sid);
}

// ── Alumni: batas keras, apa pun keadaannya ─────────────────────────
echo "=== alumni (batas keras) ===\n";
$uid_al = $pdo->query("SELECT id FROM users WHERE role='alumni' LIMIT 1")->fetchColumn();
$sid_al = sesi_palsu($uid_al, 'alumni', $csrf);
$semua_ditolak = true;
foreach ($endpoint as $ep => $kap) {
    [$c, $raw] = panggil($sid_al, "$BASE/$ep", ['csrf_token' => $csrf, 'id' => 'X']);
    if ($c !== 403 && !preg_match('/akses ditolak/i', $raw)) {
        $semua_ditolak = false;
        printf("  *** alumni TIDAK ditolak di %s (HTTP %d)\n", $ep, $c);
    }
}
cek($semua_ditolak, 'alumni ditolak di SELURUH endpoint staf', count($endpoint) . ' endpoint');

// ── Super admin: harus lolos semuanya ───────────────────────────────
echo "\n=== super_admin ===\n";
$sid_sa = sesi_palsu($uid, 'super_admin', $csrf);
$semua_boleh = true;
foreach ($endpoint as $ep => $kap) {
    [$c, $raw] = panggil($sid_sa, "$BASE/$ep", ['csrf_token' => $csrf, 'id' => 'X']);
    if ($c === 403 && preg_match('/akses ditolak/i', $raw)) {
        $semua_boleh = false;
        printf("  *** super_admin DITOLAK di %s\n", $ep);
    }
}
cek($semua_boleh, 'super_admin tidak pernah ditolak', count($endpoint) . ' endpoint');

// ---- Hapus akun: wajib POST bertoken, jejak uang dilindungi --------
//
// Dulu keduanya menghapus lewat tautan GET tanpa token, sementara
// validate_csrf() hanya memeriksa POST. Karena users -> legalisir_requests
// memakai ON DELETE CASCADE, satu tautan yang dibuka admin sudah cukup untuk
// menghapus seorang alumni beserta seluruh pengajuannya.
echo "\n=== hapus akun ===\n";

$korban_nim = 'UJIRBAC' . substr((string)time(), -6);
$korban = 'UJIRBAC-' . bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO users (id, name, email, password, nim, role, is_verified)
               VALUES (?, 'Uji Hapus', ?, '', ?, 'alumni', 1)")
    ->execute([$korban, $korban . '@example.test', $korban_nim]);

foreach ([['alumni', 'admin_alumni_handler.php'], ['user', 'admin_user_handler.php']] as [$nama, $berkas]) {
    [$c] = panggil($sid_sa, "$BASE/handlers/$berkas?action=delete&id=" . rawurlencode($korban) . "&csrf_token=$csrf");
    $masih = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE id = " . $pdo->quote($korban))->fetchColumn();
    cek($c === 405 && $masih === 1, "hapus $nama lewat GET ditolak, datanya utuh", "HTTP $c");

    [$c] = panggil($sid_sa, "$BASE/handlers/$berkas?action=delete", ['id' => $korban, 'csrf_token' => 'salah']);
    $masih = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE id = " . $pdo->quote($korban))->fetchColumn();
    cek($c === 403 && $masih === 1, "hapus $nama dengan token salah ditolak", "HTTP $c");
}

// Akun dengan pengajuan LUNAS tidak boleh dihapus: laporan keuangan membaca
// legalisir_requests, dan barisnya ikut terhapus oleh CASCADE.
$leg = 'LEG-UJIRBAC-' . bin2hex(random_bytes(3));
$pdo->prepare("INSERT INTO legalisir_requests (id, user_id, documents, delivery_method, amount, status, payment_status, payment_method)
               VALUES (?, ?, '[]', 'ambil_sendiri', 68344, 'completed', 'settlement', 'cash')")
    ->execute([$leg, $korban]);
[$c, $raw] = panggil($sid_sa, "$BASE/handlers/admin_user_handler.php?action=delete", ['id' => $korban, 'csrf_token' => $csrf]);
$masih = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE id = " . $pdo->quote($korban))->fetchColumn();
cek($masih === 1 && strpos($raw, 'delete_blocked') !== false, 'akun dengan pengajuan LUNAS tidak dapat dihapus', "HTTP $c");
cek((int)$pdo->query("SELECT COUNT(*) FROM legalisir_requests WHERE id = " . $pdo->quote($leg))->fetchColumn() === 1,
    'pengajuan lunasnya tetap ada');

// Super admin tidak dapat menghapus akunnya sendiri
[$c, $raw] = panggil($sid_sa, "$BASE/handlers/admin_user_handler.php?action=delete", ['id' => $uid, 'csrf_token' => $csrf]);
cek((int)$pdo->query("SELECT COUNT(*) FROM users WHERE id = " . $pdo->quote($uid))->fetchColumn() === 1
    && strpos($raw, 'delete_blocked') !== false, 'akun sendiri tidak dapat dihapus', "HTTP $c");

// Tanpa penghalang, penghapusan yang sah tetap berjalan dan tercatat
$pdo->prepare("DELETE FROM legalisir_requests WHERE id = ?")->execute([$leg]);
$log_awal = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'DELETE_USER'")->fetchColumn();
[$c] = panggil($sid_sa, "$BASE/handlers/admin_user_handler.php?action=delete", ['id' => $korban, 'csrf_token' => $csrf]);
cek((int)$pdo->query("SELECT COUNT(*) FROM users WHERE id = " . $pdo->quote($korban))->fetchColumn() === 0,
    'penghapusan yang sah tetap berjalan', "HTTP $c");
cek((int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'DELETE_USER'")->fetchColumn() === $log_awal + 1,
    'penghapusan tercatat di Audit Trail');

$pdo->exec("DELETE FROM users WHERE id LIKE 'UJIRBAC-%'");
$pdo->exec("DELETE FROM legalisir_requests WHERE id LIKE 'LEG-UJIRBAC-%'");
$pdo->exec("DELETE FROM activity_logs WHERE action = 'DELETE_USER' AND description LIKE '%UJIRBAC%'");

// ---- Mode audit: pelanggaran dicatat, bukan ditolak ----------------
echo "\n=== perbandingan mode audit ===\n";
$pdo->exec("UPDATE settings SET setting_value='0' WHERE setting_key='rbac_enforce'");
$sid_k = sesi_palsu($uid, 'keuangan', $csrf);
$sebelum = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='RBAC_AUDIT'")->fetchColumn();
[$c, $raw] = panggil($sid_k, "$BASE/handlers/admin_news_handler.php", ['csrf_token' => $csrf]);
$sesudah = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='RBAC_AUDIT'")->fetchColumn();
cek($c !== 403, 'mode audit: pelanggaran TIDAK ditolak', "HTTP $c");
cek($sesudah > $sebelum, 'mode audit: pelanggaran tercatat di Audit Trail', "$sebelum -> $sesudah");
// Kredensial dan sakelar gateway TIDAK ikut longgar dalam mode audit.
[$c, $raw] = panggil($sid_k, "$BASE/handlers/admin_payment_gateway_handler.php", ['csrf_token' => $csrf, 'aksi' => 'tes', 'gateway' => 'flip']);
cek($c === 403, 'mode audit: panel gateway pembayaran TETAP ditolak', "HTTP $c");

$pdo->exec("UPDATE settings SET setting_value='1' WHERE setting_key='rbac_enforce'");
[$c] = panggil($sid_k, "$BASE/handlers/admin_news_handler.php", ['csrf_token' => $csrf]);
cek($c === 403, 'mode tegak: pelanggaran yang sama DITOLAK', "HTTP $c");

$pdo->exec("DELETE FROM activity_logs WHERE action IN ('RBAC_AUDIT','RBAC_DENIED') AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
foreach ([$sid_al, $sid_sa, $sid_k] as $x) { @unlink((session_save_path() ?: sys_get_temp_dir()) . '/sess_' . $x); }

// Kembalikan ke nilai semula; keputusan menyalakannya dibuat terpisah.
if ($semula !== false) {
    $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='rbac_enforce'")->execute([$semula]);
}
printf("\nrbac_enforce dikembalikan ke: %s\n", $semula === false ? '(tidak ada)' : $semula);

echo "\n────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
exit($fail ? 1 : 0);
