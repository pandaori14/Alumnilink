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

// ── Mode audit: pelanggaran dicatat, bukan ditolak ──────────────────
echo "\n=== perbandingan mode audit ===\n";
$pdo->exec("UPDATE settings SET setting_value='0' WHERE setting_key='rbac_enforce'");
$sid_k = sesi_palsu($uid, 'keuangan', $csrf);
$sebelum = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='RBAC_AUDIT'")->fetchColumn();
[$c, $raw] = panggil($sid_k, "$BASE/handlers/admin_news_handler.php", ['csrf_token' => $csrf]);
$sesudah = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='RBAC_AUDIT'")->fetchColumn();
cek($c !== 403, 'mode audit: pelanggaran TIDAK ditolak', "HTTP $c");
cek($sesudah > $sebelum, 'mode audit: pelanggaran tercatat di Audit Trail', "$sebelum -> $sesudah");

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
