<?php
/**
 * Uji paginasi pada tujuh halaman yang kini memakai includes/pagination.php.
 * Membuat data uji secukupnya agar benar-benar ada lebih dari satu halaman.
 */
require_once __DIR__ . '/_bootstrap.php';

$BASE = uji_base_url();
function muat($sid, $url) {
    $ch = curl_init($url);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40];
    if ($sid) { $o[CURLOPT_COOKIE] = 'PHPSESSID=' . $sid; }
    curl_setopt_array($ch, $o);
    $b = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return [$c, (string)$b];
}
function jumlah($body) {
    return preg_match('/Menampilkan (\d+) dari (\d+)/', $body, $m)
        ? ['tampil' => (int)$m[1], 'total' => (int)$m[2]] : null;
}

// ── Siapkan: ukuran halaman kecil + data uji ────────────────────────
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

$pdo->exec("DELETE FROM news_posts WHERE title LIKE 'UJI-PAG-%'");
$ins = $pdo->prepare("INSERT INTO news_posts (title, content, type, created_at) VALUES (?, 'isi uji', ?, NOW())");
for ($i = 1; $i <= 8; $i++) {
    $ins->execute(["UJI-PAG-Berita $i", $i % 2 ? 'event' : 'berita']);
}
$n_berita = (int)$pdo->query("SELECT COUNT(*) FROM news_posts")->fetchColumn();
$n_event  = (int)$pdo->query("SELECT COUNT(*) FROM news_posts WHERE type IN ('event','kegiatan')")->fetchColumn();
$n_user   = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$n_dok    = (int)$pdo->query("SELECT COUNT(*) FROM document_repository")->fetchColumn();

printf("Data: %d berita, %d event, %d user, %d dokumen. pagination_size=5\n\n", $n_berita, $n_event, $n_user, $n_dok);

$uid_sa = $pdo->query("SELECT id FROM users WHERE role='super_admin' LIMIT 1")->fetchColumn();
$uid_al = $pdo->query("SELECT id FROM users WHERE role='alumni' AND is_verified=1 LIMIT 1")->fetchColumn();
$sa = sesi_palsu($uid_sa, 'super_admin');
$al = $uid_al ? sesi_palsu($uid_al, 'alumni') : $sa;

// ── Halaman yang diuji ──────────────────────────────────────────────
$halaman = [
    ['admin_users',      $sa, $n_user, 'pengguna'],
    ['all_news',         null, $n_berita, 'berita'],      // publik, tanpa sesi
    ['events',           $al, $n_event, 'kegiatan'],
    ['admin_repository', $sa, $n_dok, 'dokumen'],
    ['admin_alumni',     $sa, null, 'alumni'],
    ['admin_legalisir',  $sa, null, 'pengajuan'],
];

foreach ($halaman as [$p, $sid, $harap_total, $satuan]) {
    echo "--- $p ---\n";
    [$c, $b] = muat($sid, "$BASE/index.php?page=$p");
    cek($c === 200, "$p tampil", "HTTP $c");
    if ($c !== 200) { continue; }

    cek(!preg_match('/\b(Warning|Notice|Fatal error|Parse error)\b:/', $b), "$p tanpa warning PHP");

    $j = jumlah($b);
    if ($harap_total !== null && $harap_total > 0) {
        cek($j !== null, "$p menampilkan jumlah baris", $j ? "{$j['tampil']} dari {$j['total']}" : 'TIDAK ADA');
        if ($j) {
            cek($j['total'] === $harap_total, "$p total cocok basis data", "{$j['total']} vs $harap_total");
            cek($j['tampil'] <= 5, "$p satu halaman maks 5 baris", (string)$j['tampil']);
        }
    } elseif ($j) {
        cek($j['tampil'] <= 5, "$p satu halaman maks 5 baris", "{$j['tampil']} dari {$j['total']}");
    }

    // Halaman 2 harus berbeda isinya, dan nomor di luar batas tidak error.
    if ($j && $j['total'] > 5) {
        [$c2, $b2] = muat($sid, "$BASE/index.php?page=$p&p=2");
        cek($c2 === 200 && $b2 !== $b, "$p halaman 2 berbeda", "HTTP $c2");
        [$c3, $b3] = muat($sid, "$BASE/index.php?page=$p&p=9999");
        cek($c3 === 200, "$p nomor di luar batas dijepit", "HTTP $c3");
        $j3 = jumlah($b3);
        cek($j3 && $j3['tampil'] > 0, "$p halaman terakhir tetap berisi", $j3 ? (string)$j3['tampil'] : '-');
        [$c4, $b4] = muat($sid, "$BASE/index.php?page=$p&p=-5");
        cek($c4 === 200, "$p nomor negatif tidak error", "HTTP $c4");
    }
    echo "\n";
}

// ── Saringan admin_users ────────────────────────────────────────────
echo "--- saringan admin_users ---\n";
[$c, $b] = muat($sa, "$BASE/index.php?page=admin_users&peran=alumni");
$j = jumlah($b);
$n_alumni = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='alumni'")->fetchColumn();
cek($j && $j['total'] === $n_alumni, 'saring peran=alumni cocok', ($j['total'] ?? '?') . " vs $n_alumni");
[$c, $b] = muat($sa, "$BASE/index.php?page=admin_users&peran=ngawur");
cek($c === 200 && jumlah($b)['total'] === $n_user, 'peran ngawur diabaikan, bukan error');
// Pada 0 hasil, baris "Menampilkan" memang sengaja tidak dicetak; yang
// diperiksa adalah tidak ada satu pun baris tabel dan keadaan kosong tampil.
[$c, $b] = muat($sa, "$BASE/index.php?page=admin_users&cari=" . urlencode("' OR 1=1 -- "));
cek($c === 200
    && substr_count($b, '<tr class="hover:bg-white/40') === 0
    && strpos($b, 'Tidak ada pengguna yang cocok') !== false,
    'percobaan injeksi SQL tidak mengembalikan apa pun', "HTTP $c");

// ── Indikator berkas hilang tetap atas SELURUH baris ────────────────
echo "\n--- admin_repository ---\n";
[$c, $b] = muat($sa, "$BASE/index.php?page=admin_repository");
$hilang_sql = 0;
foreach ($pdo->query("SELECT file_path FROM document_repository") as $d) {
    if (!is_file('' . AKAR . '/' . ltrim((string)$d->file_path, '/\\'))) { $hilang_sql++; }
}
cek(strpos($b, 'Menampilkan') !== false || $n_dok === 0, 'halaman repositori merender daftar');
cek(preg_match('/(\d+)\s*(berkas )?(hilang|tidak ditemukan)/i', $b) || $hilang_sql === 0,
    'indikator berkas hilang tetap tampil', "hitungan SQL: $hilang_sql");

// ── Bersihkan ───────────────────────────────────────────────────────
$pdo->exec("DELETE FROM news_posts WHERE title LIKE 'UJI-PAG-%'");
if ($lama_p !== false) { $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='pagination_size'")->execute([$lama_p]); }
else { $pdo->exec("DELETE FROM settings WHERE setting_key='pagination_size'"); }
foreach (array_unique([$sa, $al]) as $x) { @unlink((session_save_path() ?: sys_get_temp_dir()) . '/sess_' . $x); }
cek((int)$pdo->query("SELECT COUNT(*) FROM news_posts WHERE title LIKE 'UJI-PAG-%'")->fetchColumn() === 0, 'data uji dibersihkan');

echo "\n────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
exit($fail ? 1 : 0);
