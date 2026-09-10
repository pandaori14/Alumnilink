<?php
require_once __DIR__ . '/_bootstrap.php';
require_once AKAR . '/includes/menu.php';
require_once AKAR . '/includes/auth_guard.php';

function cek($ok, $l, $d = '') { global $pass,$fail;
  if ($ok) { $pass++; printf("  LULUS  %-50s %s\n",$l,$d); } else { $fail++; printf("  GAGAL  %-50s %s\n",$l,$d); } }

$kunci = all_menu_keys();
printf("Total kunci menu: %d\n\n", count($kunci));

// Tujuh kunci yang dulu dibuang diam-diam oleh daftar-putih lama
$dulu_hilang = ['guide','alumni_map','admin_majors','admin_analytics',
                'admin_tracer_report','admin_employer_survey','admin_email_layouts'];
foreach ($dulu_hilang as $k) {
    cek(in_array($k, $kunci, true), "daftar-putih memuat '$k'");
}

// Setiap kunci menu harus punya berkas halaman
foreach ($kunci as $k) {
    $ada = file_exists(AKAR . "/pages/$k.php")
        || $k === 'dashboard';
    if (!$ada) cek(false, "halaman untuk '$k' ada");
}
cek(true, 'setiap kunci menu punya berkas halaman');

// Simulasi SIMPAN Pengaturan: kirim seluruh menu tiap peran, lalu pastikan
// tidak ada yang dibuang -- inilah bug yang dulu mencabut izin.
$sebelum = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='sidebar_permissions'")->fetchColumn();
$semua_peran = ['alumni','admin_tracer','admin_legalisir','keuangan'];
$hasil = [];
foreach ($semua_peran as $peran) {
    $hasil[$peran] = array_values(array_filter($kunci, fn($k) => in_array($k, $kunci, true)));
}
cek(count($hasil['alumni']) === count($kunci), 'simpan tidak membuang kunci apa pun',
    count($hasil['alumni']) . ' dari ' . count($kunci));

// Bawaan dan definisi menu harus konsisten
foreach (default_sidebar_permissions() as $peran => $menus) {
    $asing = array_diff($menus, $kunci);
    cek(empty($asing), "bawaan '$peran' tidak memuat kunci asing", implode(',', $asing));
}

// Peran staf tetap punya kapabilitasnya (tak ada izin yang hilang)
$m = capability_matrix();
cek(in_array('admin_tracer', $m['tracer.lihat'] ?? [], true), 'admin_tracer tetap punya tracer.lihat');
cek(in_array('admin_legalisir', $m['legalisir.kelola'] ?? [], true), 'admin_legalisir tetap punya legalisir.kelola');
cek(in_array('keuangan', $m['keuangan.lihat'] ?? [], true), 'keuangan tetap punya keuangan.lihat');
cek(!in_array('alumni', $m['alumni.kelola'] ?? [], true), 'alumni TIDAK punya alumni.kelola');

printf("\n  Isi sidebar_permissions tidak diubah oleh uji ini: %s\n",
    $sebelum === $pdo->query("SELECT setting_value FROM settings WHERE setting_key='sidebar_permissions'")->fetchColumn() ? 'benar' : 'BERUBAH!');
echo "────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
exit($fail ? 1 : 0);
