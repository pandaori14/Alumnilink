<?php
/**
 * Uji end-to-end operasional legalisir: saring, paginasi, umur/SLA,
 * alasan penolakan, aksi massal, dan cetak label.
 *
 * E-mail diarahkan ke log (smtp_force_real = 0) supaya pengujian tidak
 * pernah mengirim surat ke alumni sungguhan.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once AKAR . '/includes/legalisir_lib.php';

$BASE = uji_base_url();
function kirim($sid, $url, $post = null) {
    $ch = curl_init($url);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HEADER => true,
          CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIE => 'PHPSESSID=' . $sid];
    if ($post !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = http_build_query($post); }
    curl_setopt_array($ch, $o);
    $raw = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $loc = preg_match('/^Location:\s*(\S+)/mi', (string)$raw, $m) ? $m[1] : '';
    return [$c, (string)$raw, $loc];
}

// ── Amankan: e-mail tidak boleh benar-benar terkirim ─────────────────
$smtp_lama = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='smtp_force_real'")->fetchColumn();
$pdo->exec("INSERT INTO settings (setting_key,setting_value) VALUES ('smtp_force_real','0')
            ON DUPLICATE KEY UPDATE setting_value='0'");

$csrf = bin2hex(random_bytes(16));
$uid  = $pdo->query("SELECT id FROM users WHERE role='super_admin' LIMIT 1")->fetchColumn();
$sid  = sesi_palsu($uid, 'super_admin', $csrf);
$alum = $pdo->query("SELECT id, email FROM users WHERE role='alumni' LIMIT 1")->fetch();

// ── Buat pengajuan uji dengan umur berbeda ──────────────────────────
$pdo->exec("DELETE FROM legalisir_requests WHERE id LIKE 'UJI-LEG-%'");
$buat = $pdo->prepare(
    "INSERT INTO legalisir_requests (id, user_id, documents, delivery_method, amount, payment_status, status, created_at)
     VALUES (?, ?, ?, 'kurir', 25000, ?, ?, ?)");
$dokumen = json_encode([['type' => 'ijazah', 'qty' => 2]]);
$uji = [
    ['UJI-LEG-BARU',  'pending',    'settlement', '-1 day'],
    ['UJI-LEG-WARN',  'pending',    'settlement', '-4 days'],
    ['UJI-LEG-LATE',  'processing', 'settlement', '-15 days'],
    ['UJI-LEG-BULK1', 'pending',    'settlement', '-2 days'],
    ['UJI-LEG-BULK2', 'pending',    'settlement', '-2 days'],
];
foreach ($uji as [$id, $st, $bayar, $rel]) {
    $buat->execute([$id, $alum->id, $dokumen, $bayar, $st, date('Y-m-d H:i:s', strtotime($rel))]);
}
printf("5 pengajuan uji dibuat (SLA warn=%d, breach=%d hari)\n\n", legalisir_sla_warn(), legalisir_sla_breach());

echo "=== A. Halaman, saringan, paginasi ===\n";
[$c, $b] = kirim($sid, "$BASE/index.php?page=admin_legalisir");
cek($c === 200, 'halaman tampil', "HTTP $c");
cek(strpos($b, 'Masih terbuka') !== false, 'kartu statistik tampil');
cek(preg_match('/Menampilkan (\d+) dari (\d+) pengajuan/', $b, $m) === 1, 'baris jumlah tampil', $m[0] ?? '-');
$total_semua = (int)($m[2] ?? 0);
cek($total_semua === (int)$pdo->query("SELECT COUNT(*) FROM legalisir_requests")->fetchColumn(),
    'jumlah cocok dengan basis data', (string)$total_semua);

[$c, $b] = kirim($sid, "$BASE/index.php?page=admin_legalisir&cari=UJI-LEG-LATE");
preg_match('/Menampilkan (\d+) dari (\d+) pengajuan/', $b, $m);
cek((int)($m[2] ?? -1) === 1, 'cari berdasarkan ID menyaring tepat', ($m[2] ?? '?') . ' hasil');

[$c, $b] = kirim($sid, "$BASE/index.php?page=admin_legalisir&status=processing");
preg_match('/Menampilkan (\d+) dari (\d+) pengajuan/', $b, $m);
$n_proc = (int)$pdo->query("SELECT COUNT(*) FROM legalisir_requests WHERE status='processing'")->fetchColumn();
cek((int)($m[2] ?? -1) === $n_proc, 'saring status cocok', ($m[2] ?? '?') . " vs $n_proc");

[$c, $b] = kirim($sid, "$BASE/index.php?page=admin_legalisir&status=bukan_status");
cek($c === 200 && strpos($b, 'Menampilkan') !== false, 'status ngawur diabaikan, bukan error', "HTTP $c");

$hari_ini = date('Y-m-d');
[$c, $b] = kirim($sid, "$BASE/index.php?page=admin_legalisir&dari=$hari_ini&sampai=$hari_ini");
cek($c === 200, 'saring rentang tanggal berjalan', "HTTP $c");

// Paginasi
$lama_p = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='pagination_size'")->fetchColumn();

// Pengaturan dikembalikan lewat shutdown handler, bukan di baris terakhir:
// bila skrip mati di tengah (keluaran dipotong, exception, exit dini), nilai
// yang diubah untuk pengujian akan tertinggal dan MERACUNI uji berikutnya.
register_shutdown_function(function () use ($pdo, $lama_p) {
    if ($lama_p !== false) {
        $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='pagination_size'")->execute([$lama_p]);
    } else {
        $pdo->exec("DELETE FROM settings WHERE setting_key='pagination_size'");
    }
});

$pdo->exec("INSERT INTO settings (setting_key,setting_value) VALUES ('pagination_size','5')
            ON DUPLICATE KEY UPDATE setting_value='5'");
[$c, $b] = kirim($sid, "$BASE/index.php?page=admin_legalisir");
preg_match('/Menampilkan (\d+) dari (\d+) pengajuan/', $b, $m);
cek((int)($m[1] ?? 0) === 5, 'satu halaman berisi 5 baris', ($m[1] ?? '?') . ' dari ' . ($m[2] ?? '?'));
[$c, $b2] = kirim($sid, "$BASE/index.php?page=admin_legalisir&p=2&status=pending");
cek($c === 200 && strpos($b2, 'value="pending" selected') !== false, 'saringan ikut terbawa ke halaman 2');

echo "\n=== B. Umur & SLA ===\n";
cek(legalisir_age_days(date('Y-m-d H:i:s', strtotime('-4 days'))) === 4, 'umur 4 hari terhitung benar');
cek(legalisir_age_badge('pending', 1)  === 'bg-emerald-100 text-emerald-700', 'umur 1 hari: hijau');
cek(legalisir_age_badge('pending', 4)  === 'bg-amber-100 text-amber-700',     'umur 4 hari: kuning');
cek(legalisir_age_badge('pending', 15) === 'bg-red-100 text-red-700',         'umur 15 hari: merah');
cek(legalisir_age_badge('completed', 40) === 'bg-slate-100 text-slate-500',   'selesai 40 hari: netral, bukan pelanggaran');
cek(legalisir_turnaround_days('2026-09-01 10:00:00', null) === null, 'lama proses null bila updated_at kosong');
cek(legalisir_turnaround_days('2026-09-01 10:00:00', '2026-09-05 10:00:00') === 4, 'lama proses 4 hari');

[$c, $b] = kirim($sid, "$BASE/index.php?page=admin_legalisir&cari=UJI-LEG-LATE");
cek(strpos($b, '15 hari') !== false, 'umur tampil di layar');
cek(strpos($b, 'lama proses tidak tercatat') !== false || strpos($b, 'bg-red-100 text-red-700') !== false,
    'penanda SLA/lama proses tampil');

echo "\n=== C. Alasan penolakan wajib ===\n";
[$c, , $loc] = kirim($sid, "$BASE/handlers/admin_update_legalisir.php",
    ['csrf_token' => $csrf, 'id' => 'UJI-LEG-BARU', 'status' => 'rejected', 'rejection_reason' => 'pendek']);
cek(strpos($loc, 'error=alasan_wajib') !== false, 'alasan terlalu pendek ditolak', substr($loc, -28));
cek($pdo->query("SELECT status FROM legalisir_requests WHERE id='UJI-LEG-BARU'")->fetchColumn() === 'pending',
    'status tidak berubah saat ditolak sistem');

$alasan = 'Hasil pindai ijazah tidak terbaca, mohon unggah ulang dengan resolusi lebih tinggi.';
[$c, , $loc] = kirim($sid, "$BASE/handlers/admin_update_legalisir.php",
    ['csrf_token' => $csrf, 'id' => 'UJI-LEG-BARU', 'status' => 'rejected', 'rejection_reason' => $alasan]);
cek(strpos($loc, 'success=updated') !== false, 'penolakan beralasan diterima', substr($loc, -24));
$r = $pdo->query("SELECT status, rejection_reason, updated_at FROM legalisir_requests WHERE id='UJI-LEG-BARU'")->fetch();
cek($r->status === 'rejected', 'status jadi rejected');
cek($r->rejection_reason === $alasan, 'alasan tersimpan di basis data');
cek($r->updated_at !== null, 'updated_at terisi otomatis', (string)$r->updated_at);

$n = $pdo->prepare("SELECT message FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$n->execute([$alum->id]);
cek(strpos((string)$n->fetchColumn(), 'Hasil pindai ijazah') !== false, 'alasan ikut di notifikasi alumni');

// Alumni melihat alasannya
$sid_al = sesi_palsu($alum->id, 'alumni', $csrf);
[$c, $b] = kirim($sid_al, "$BASE/index.php?page=legalisir_detail&id=UJI-LEG-BARU");
cek($c === 200 && strpos($b, 'Hasil pindai ijazah') !== false, 'alumni melihat alasan di halaman detail', "HTTP $c");
cek(strpos($b, 'Alasan penolakan') !== false, 'label "Alasan penolakan" tampil');

// Pindah dari rejected -> alasan dibersihkan
[$c, , $loc] = kirim($sid, "$BASE/handlers/admin_update_legalisir.php",
    ['csrf_token' => $csrf, 'id' => 'UJI-LEG-BARU', 'status' => 'processing']);
cek($pdo->query("SELECT rejection_reason FROM legalisir_requests WHERE id='UJI-LEG-BARU'")->fetchColumn() === null,
    'alasan dibersihkan saat status pindah dari rejected');

echo "\n=== D. Token verifikasi diterbitkan sekali ===\n";
kirim($sid, "$BASE/handlers/admin_update_legalisir.php",
    ['csrf_token' => $csrf, 'id' => 'UJI-LEG-WARN', 'status' => 'completed']);
$t1 = $pdo->query("SELECT verification_token FROM legalisir_requests WHERE id='UJI-LEG-WARN'")->fetchColumn();
cek(!empty($t1), 'token verifikasi diterbitkan', substr((string)$t1, 0, 10) . '...');
kirim($sid, "$BASE/handlers/admin_update_legalisir.php",
    ['csrf_token' => $csrf, 'id' => 'UJI-LEG-WARN', 'status' => 'processing']);
kirim($sid, "$BASE/handlers/admin_update_legalisir.php",
    ['csrf_token' => $csrf, 'id' => 'UJI-LEG-WARN', 'status' => 'completed']);
$t2 = $pdo->query("SELECT verification_token FROM legalisir_requests WHERE id='UJI-LEG-WARN'")->fetchColumn();
cek($t1 === $t2, 'token TIDAK diterbitkan ulang (tautan lama tetap hidup)');

echo "\n=== E. Aksi massal ===\n";
[$c] = kirim($sid, "$BASE/handlers/admin_legalisir_bulk.php", ['ids' => ['UJI-LEG-BULK1'], 'status' => 'processing']);
cek($c === 403 || $c === 400, 'tanpa token CSRF ditolak', "HTTP $c");
[$c] = kirim('tidakadasesi', "$BASE/handlers/admin_legalisir_bulk.php",
    ['csrf_token' => $csrf, 'ids' => ['UJI-LEG-BULK1'], 'status' => 'processing']);
cek($c === 401 || $c === 403, 'tanpa sesi ditolak', "HTTP $c");

[$c, , $loc] = kirim($sid, "$BASE/handlers/admin_legalisir_bulk.php",
    ['csrf_token' => $csrf, 'ids' => [], 'status' => 'processing']);
cek(strpos($loc, 'error=bulk_kosong') !== false, 'tanpa pilihan ditolak', substr($loc, -26));

[$c, , $loc] = kirim($sid, "$BASE/handlers/admin_legalisir_bulk.php",
    ['csrf_token' => $csrf, 'ids' => ['UJI-LEG-BULK1'], 'status' => 'bukan_status']);
cek(strpos($loc, 'error=bulk_status') !== false, 'status tujuan ngawur ditolak', substr($loc, -26));

[$c, , $loc] = kirim($sid, "$BASE/handlers/admin_legalisir_bulk.php",
    ['csrf_token' => $csrf, 'ids' => ['UJI-LEG-BULK1', 'UJI-LEG-BULK2'], 'status' => 'rejected', 'rejection_reason' => '']);
cek(strpos($loc, 'error=bulk_alasan') !== false, 'penolakan massal tanpa alasan ditolak', substr($loc, -26));
cek($pdo->query("SELECT status FROM legalisir_requests WHERE id='UJI-LEG-BULK1'")->fetchColumn() === 'pending',
    'tidak ada yang berubah saat ditolak sistem');

$sebelum_lain = $pdo->query("SELECT status FROM legalisir_requests WHERE id='UJI-LEG-LATE'")->fetchColumn();
[$c, , $loc] = kirim($sid, "$BASE/handlers/admin_legalisir_bulk.php",
    ['csrf_token' => $csrf, 'ids' => ['UJI-LEG-BULK1', 'UJI-LEG-BULK2'], 'status' => 'processing',
     'kembali' => 'status=pending&cari=UJI']);
cek(strpos($loc, 'success=bulk') !== false && strpos($loc, 'n=2') !== false, 'dua pengajuan diubah', substr($loc, -46));
cek(strpos($loc, 'cari=UJI') !== false, 'saringan dibawa kembali setelah aksi massal');
foreach (['UJI-LEG-BULK1', 'UJI-LEG-BULK2'] as $id) {
    cek($pdo->query("SELECT status FROM legalisir_requests WHERE id='$id'")->fetchColumn() === 'processing',
        "$id berubah jadi processing");
}
cek($pdo->query("SELECT status FROM legalisir_requests WHERE id='UJI-LEG-LATE'")->fetchColumn() === $sebelum_lain,
    'yang TIDAK dicentang tidak tersentuh');
$log = $pdo->query("SELECT description FROM activity_logs WHERE action='BULK_UPDATE_LEGALISIR' ORDER BY id DESC LIMIT 1")->fetchColumn();
cek(strpos((string)$log, '2 pengajuan') !== false, 'tercatat di Audit Trail', (string)$log);

$banyak = array_fill(0, 101, 'UJI-LEG-BULK1');
[$c, , $loc] = kirim($sid, "$BASE/handlers/admin_legalisir_bulk.php",
    ['csrf_token' => $csrf, 'ids' => $banyak, 'status' => 'processing']);
cek(strpos($loc, 'error=bulk_terlalu_banyak') !== false, 'lebih dari 100 pilihan ditolak', substr($loc, -30));

echo "\n=== F. Cetak label ===\n";
[$c, $b] = kirim($sid, "$BASE/cetak_label.php?id=UJI-LEG-BULK1");
cek($c === 200 && substr_count($b, 'class="print-container') === 1, 'pemanggilan lama ?id= tetap 1 label', "HTTP $c");
[$c, $b] = kirim($sid, "$BASE/cetak_label.php?ids=UJI-LEG-BULK1,UJI-LEG-BULK2,UJI-LEG-LATE");
cek($c === 200 && substr_count($b, 'class="print-container') === 3, 'tiga ID menghasilkan 3 label',
    substr_count($b, 'class="print-container') . ' label');
[$c, $b] = kirim($sid, "$BASE/cetak_label.php?ids=UJI-LEG-BULK1,TIDAK-ADA");
cek($c === 200 && strpos($b, '1 ID tidak ditemukan') !== false, 'ID tak dikenal dilewati & dilaporkan');
[$c, $b] = kirim($sid, "$BASE/cetak_label.php?ids=SEMUA-NGAWUR");
cek($c === 404, 'seluruh ID ngawur menghasilkan 404', "HTTP $c");
[$c] = kirim('tidakadasesi', "$BASE/cetak_label.php?id=UJI-LEG-BULK1");
cek($c === 401 || $c === 403, 'cetak label tanpa sesi ditolak', "HTTP $c");
[$c] = kirim($sid_al, "$BASE/cetak_label.php?id=UJI-LEG-BULK1");
cek($c === 403, 'alumni tidak boleh mencetak label', "HTTP $c");

// ── Bersihkan ───────────────────────────────────────────────────────
$pdo->exec("DELETE FROM legalisir_requests WHERE id LIKE 'UJI-LEG-%'");
$pdo->prepare("DELETE FROM notifications WHERE user_id = ? AND message LIKE '%UJI-LEG-%'")->execute([$alum->id]);
$pdo->exec("DELETE FROM activity_logs WHERE description LIKE '%UJI-LEG-%'");
if ($lama_p !== false) { $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='pagination_size'")->execute([$lama_p]); }
if ($smtp_lama !== false) { $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='smtp_force_real'")->execute([$smtp_lama]); }
else { $pdo->exec("DELETE FROM settings WHERE setting_key='smtp_force_real'"); }
foreach ([$sid, $sid_al] as $x) { @unlink((session_save_path() ?: sys_get_temp_dir()) . '/sess_' . $x); }
cek((int)$pdo->query("SELECT COUNT(*) FROM legalisir_requests WHERE id LIKE 'UJI-LEG-%'")->fetchColumn() === 0,
    'data uji dibersihkan');

echo "\n────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
exit($fail ? 1 : 0);
