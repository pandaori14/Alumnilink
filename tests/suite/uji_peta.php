<?php
require_once __DIR__ . '/_bootstrap.php';
require_once AKAR . '/includes/auth_guard.php';
$BASE = uji_base_url();
function cek($ok, $label, $detail = '') {
    global $pass, $fail;
    if ($ok) { $pass++; printf("  LULUS  %-52s %s\n", $label, $detail); }
    else     { $fail++; printf("  GAGAL  %-52s %s\n", $label, $detail); }
}

/** Palsukan sesi peran tertentu langsung di penyimpanan sesi PHP. */
function sesi_palsu($role) {
    $sid = bin2hex(random_bytes(16));
    $path = session_save_path() ?: sys_get_temp_dir();
    $data = "user_id|s:9:\"UJI_PETA_\";user_role|s:" . strlen($role) . ":\"$role\";user_name|s:3:\"Uji\";last_activity|i:" . time() . ";";
    file_put_contents($path . '/sess_' . $sid, $data);
    return $sid;
}
function ambil($sid, $qs = '') {
    global $BASE;
    $ch = curl_init("$BASE/api/alumni/geodistribution.php?$qs");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>25,
        CURLOPT_COOKIE => 'PHPSESSID=' . $sid]);
    $b = curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$c, $b, json_decode((string)$b, true)];
}

// Siapkan satu alumnus beralamat + koordinat agar ada yang diuji
$al = $pdo->query("SELECT id, name FROM users WHERE role='alumni' LIMIT 1")->fetch();
if (!$al) { echo "Tidak ada alumni.\n"; exit(1); }
$pdo->prepare("UPDATE users SET address = ?, map_opt_out = 0 WHERE id = ?")
    ->execute(['Jl. Uji No. 1, Surakarta', $al->id]);
$pdo->prepare("DELETE FROM alumni_geocoding_cache WHERE user_id = ?")->execute([$al->id]);
$pdo->prepare("INSERT INTO alumni_geocoding_cache (user_id, raw_address, status, latitude, longitude)
               VALUES (?, ?, 'success', ?, ?)")
    ->execute([$al->id, 'Jl. Uji No. 1, Surakarta', -7.556789, 110.831234]);

echo "Alumnus uji: {$al->name} ({$al->id})\n\n";

// ── Anonim ────────────────────────────────────────────────────────
[$c] = ambil('tidakadasesi');
cek($c === 403, 'anonim ditolak', "HTTP $c");

// ── Alumni ────────────────────────────────────────────────────────
$s_al = sesi_palsu('alumni');
[$c, $raw, $j] = ambil($s_al);
cek($c === 200, 'alumni dapat memuat peta', "HTTP $c");
cek(($j['detail'] ?? '') === 'limited', 'mode alumni = limited', $j['detail'] ?? '(kosong)');
cek(strpos($raw, '"address"') === false, 'ALAMAT tidak dikirim ke alumni');
cek(strpos($raw, '"company"') === false, 'TEMPAT KERJA tidak dikirim ke alumni');
cek(strpos($raw, 'Jl. Uji No. 1') === false, 'alamat mentah tidak bocor di body');

$f = null;
foreach ($j['features'] ?? [] as $x) { if (($x['properties']['id'] ?? '') === $al->id) $f = $x; }
cek($f !== null, 'titik alumnus uji ada di hasil');
if ($f) {
    [$lo, $la] = $f['geometry']['coordinates'];
    cek($la == round(-7.556789, 1) && $lo == round(110.831234, 1),
        'koordinat dibulatkan tingkat kota', "[$lo, $la]");
    cek(($f['properties']['name'] ?? '') === $al->name, 'nama tetap tampil');
}

// ── Staf ──────────────────────────────────────────────────────────
$s_st = sesi_palsu('admin_legalisir');
[$c, $raw2, $j2] = ambil($s_st);
cek($c === 200, 'staf dapat memuat peta', "HTTP $c");
cek(($j2['detail'] ?? '') === 'full', 'mode staf = full', $j2['detail'] ?? '(kosong)');
$g = null;
foreach ($j2['features'] ?? [] as $x) { if (($x['properties']['id'] ?? '') === $al->id) $g = $x; }
cek($g !== null && isset($g['properties']['address']), 'staf tetap melihat alamat',
    $g['properties']['address'] ?? '-');
if ($g) {
    [$lo, $la] = $g['geometry']['coordinates'];
    cek(abs($la - (-7.556789)) < 0.000001, 'koordinat staf tepat', "[$lo, $la]");
}

// ── Opt-out ───────────────────────────────────────────────────────
$pdo->prepare("UPDATE users SET map_opt_out = 1 WHERE id = ?")->execute([$al->id]);
[, , $j3] = ambil($s_st);
$ada = false;
foreach ($j3['features'] ?? [] as $x) { if (($x['properties']['id'] ?? '') === $al->id) $ada = true; }
cek(!$ada, 'yang memilih keluar hilang bahkan dari tampilan staf');

// Geocoder harus menghapus barisnya dan tidak mendaftarkannya ulang
exec(escapeshellarg(PHP_BINARY) . ' ' . AKAR . '/cron/geocoder.php 2>&1', $out);
$sisa = $pdo->prepare("SELECT COUNT(*) FROM alumni_geocoding_cache WHERE user_id = ?");
$sisa->execute([$al->id]);
cek((int)$sisa->fetchColumn() === 0, 'koordinatnya terhapus & tidak didaftarkan ulang');

$yatim = (int)$pdo->query("SELECT COUNT(*) FROM alumni_geocoding_cache c
                           JOIN users u ON u.id = c.user_id WHERE u.map_opt_out = 1")->fetchColumn();
cek($yatim === 0, 'invarian: nol koordinat milik yang keluar', "sisa=$yatim");

// ── Galat tidak membocorkan detail ────────────────────────────────
[$c4, $raw4] = ambil($s_al, 'major=' . urlencode(str_repeat('x', 300)));
cek(strpos($raw4, 'SQLSTATE') === false && strpos($raw4, 'SELECT') === false,
    'pesan galat tidak memuat detail SQL');

// Bersihkan
$pdo->prepare("UPDATE users SET map_opt_out = 0 WHERE id = ?")->execute([$al->id]);
$pdo->prepare("DELETE FROM alumni_geocoding_cache WHERE user_id = ?")->execute([$al->id]);
foreach ([$s_al, $s_st] as $sid) { @unlink((session_save_path() ?: sys_get_temp_dir()) . '/sess_' . $sid); }

echo "\n────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
exit($fail ? 1 : 0);
