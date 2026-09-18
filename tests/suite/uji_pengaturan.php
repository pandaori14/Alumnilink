<?php
/**
 * Halaman Konfigurasi Sistem: setiap kolom benar-benar tersimpan, dan
 * menyimpan tanpa mengubah apa pun tidak boleh mengubah data.
 *
 * ── Mengapa diuji begini ───────────────────────────────────────────────
 * Formulir ini memuat 59 kolom dalam lima bagian, dan seluruhnya dikirim
 * sekali tekan — termasuk bagian yang sedang tidak terlihat. Dua kesalahan
 * mudah terjadi dan sulit disadari:
 *
 *   1. Sebuah kolom dipindah antar bagian lalu tertinggal di luar <form>.
 *      Kolomnya tetap tampil, tetapi diam-diam tidak pernah tersimpan.
 *   2. Menyimpan pengaturan apa pun ikut menimpa nilai lain. Ini pernah
 *      terjadi: formulir selalu mengirim ulang tarif Midtrans, sehingga
 *      menyimpan logo pun menulis ulang tarif pembayaran.
 *
 * Karena itu suite ini tidak menebak isi formulir. Ia MEMBACA halaman hasil
 * render, menyusun kiriman persis seperti yang dilakukan peramban, lalu
 * membandingkan seluruh tabel settings sebelum dan sesudah.
 *
 * Seluruh nilai dikembalikan di akhir, apa pun yang terjadi di tengah.
 */
require_once __DIR__ . '/_bootstrap.php';

$BASE = uji_base_url();

// ── Cadangkan SELURUH tabel settings ─────────────────────────────────
$SEMULA = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);

register_shutdown_function(function () use ($SEMULA) {
    global $pdo;
    $simpan = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ($SEMULA as $k => $v) {
        $simpan->execute([$k, $v]);
    }
    // Kunci yang lahir selama uji dan tidak ada sebelumnya.
    $kini = $pdo->query("SELECT setting_key FROM settings")->fetchAll(PDO::FETCH_COLUMN);
    $hapus = $pdo->prepare("DELETE FROM settings WHERE setting_key = ?");
    foreach (array_diff($kini, array_keys($SEMULA)) as $k) {
        $hapus->execute([$k]);
    }
});

$csrf = bin2hex(random_bytes(16));
$uid  = $pdo->query("SELECT id FROM users WHERE role = 'super_admin' LIMIT 1")->fetchColumn();
$sid  = sesi_palsu($uid, 'super_admin', $csrf);
register_shutdown_function(function () use ($sid) { sesi_hapus($sid); });

function ambil_halaman($sid, $url)
{
    global $BASE;
    $ch = curl_init("$BASE/$url");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_COOKIE => "PHPSESSID=$sid"]);
    $b = (string)curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$c, $b];
}

function kirim_form($sid, $url, $body)
{
    global $BASE;
    $ch = curl_init("$BASE/$url");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_COOKIE => "PHPSESSID=$sid"]);
    $raw = (string)curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $loc = preg_match('/^Location:\s*(\S+)/mi', $raw, $m) ? $m[1] : '';
    return [$c, $loc];
}

/**
 * Susun kiriman formulir dari HTML hasil render — seperti peramban:
 * kotak centang yang tidak dicentang tidak dikirim, select memakai opsi
 * terpilih, dan berkas dilewati.
 *
 * @return array daftar pasangan [nama, nilai] menurut urutan kemunculan
 */
function kolom_formulir($html, $id_form)
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    $form = $dom->getElementById($id_form);
    if (!$form) {
        return [];
    }
    $xp = new DOMXPath($dom);
    $pasangan = [];
    foreach ($xp->query('.//input | .//select | .//textarea', $form) as $el) {
        $nama = $el->getAttribute('name');
        if ($nama === '') {
            continue;
        }
        $tag = strtolower($el->tagName);
        if ($tag === 'input') {
            $tipe = strtolower($el->getAttribute('type') ?: 'text');
            if ($tipe === 'file' || $tipe === 'submit' || $tipe === 'button') {
                continue;
            }
            if (($tipe === 'checkbox' || $tipe === 'radio') && !$el->hasAttribute('checked')) {
                continue;
            }
            $pasangan[] = [$nama, $el->getAttribute('value')];
        } elseif ($tag === 'textarea') {
            $pasangan[] = [$nama, $el->textContent];
        } else {
            $nilai = '';
            foreach ($xp->query('.//option', $el) as $opt) {
                if ($opt->hasAttribute('selected')) {
                    $nilai = $opt->hasAttribute('value') ? $opt->getAttribute('value') : $opt->textContent;
                    break;
                }
            }
            $pasangan[] = [$nama, $nilai];
        }
    }
    return $pasangan;
}

function jadi_body(array $pasangan)
{
    $bagian = [];
    foreach ($pasangan as [$n, $v]) {
        $bagian[] = rawurlencode($n) . '=' . rawurlencode($v);
    }
    return implode('&', $bagian);
}

function settings_kini()
{
    global $pdo;
    return $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Urutkan isi array bersarang, supaya dua JSON yang setara dapat dibandingkan. */
function rapikan($v)
{
    if (!is_array($v)) {
        return $v;
    }
    $v = array_map('rapikan', $v);
    if (array_values($v) === $v) {
        sort($v);
    } else {
        ksort($v);
    }
    return $v;
}

/**
 * Dua nilai setara bila isinya sama.
 *
 * Nilai berbentuk JSON dibandingkan sebagai data, bukan sebagai teks:
 * izin sidebar disimpan ulang menurut urutan menu, jadi teksnya dapat
 * berbeda meski isinya persis sama. Yang penting tidak ada yang HILANG.
 */
function setara($a, $b)
{
    if ($a === $b) {
        return true;
    }
    $ja = json_decode((string)$a, true);
    $jb = json_decode((string)$b, true);
    return is_array($ja) && is_array($jb) && rapikan($ja) === rapikan($jb);
}

// ═════════════════════════════════════════════════════════════════════
echo "=== A. Halaman dan strukturnya ===\n";

[$kode, $html] = ambil_halaman($sid, 'index.php?page=admin_settings');
cek($kode === 200 && !preg_match('/<b>(Fatal error|Warning|Parse error)<\/b>:/', $html),
    'halaman tampil tanpa galat PHP', "HTTP $kode");

$bagian = ['identitas', 'layanan', 'integrasi', 'keamanan', 'operasional'];
foreach ($bagian as $b) {
    cek(strpos($html, "tab-$b") !== false && strpos($html, "btn-tab-$b") !== false,
        "bagian '$b' punya wadah dan tombolnya");
}
cek(preg_match_all('/settings-tab-content\s+tab-/', $html) === count($bagian),
    'jumlah wadah bagian sama dengan jumlah tombol', count($bagian) . ' bagian');

// Tanpa <form> bersarang: formulir cadangan harus berada DI LUAR settings-form.
$tanpa_skrip = preg_replace('#<script\b[^>]*>.*?</script>#si', '', $html);
preg_match_all('#<(/?)form\b[^>]*>#i', $tanpa_skrip, $mf);
$dalam = 0;
$maks = 0;
foreach ($mf[1] as $t) {
    $dalam += $t === '' ? 1 : -1;
    $maks = max($maks, $dalam);
}
cek($maks === 1 && $dalam === 0, 'tidak ada <form> bersarang', "kedalaman maks $maks, sisa $dalam");
cek(preg_match('#<button[^>]*form="form-cadangan"#', $html) === 1
    && preg_match('#<form[^>]*id="form-cadangan"#', $html) === 1,
    'tombol unduh cadangan menunjuk formulirnya sendiri');

$kolom = kolom_formulir($html, 'settings-form');
cek(count($kolom) > 50, 'seluruh kolom formulir terbaca', count($kolom) . ' kolom');

// Setiap kolom harus berada di dalam salah satu bagian — bukan menggantung
// di luar, tempat ia tetap terlihat tetapi tidak pernah ikut tersimpan.
$nama_kolom = array_unique(array_column($kolom, 0));
cek(in_array('price_per_doc', $nama_kolom, true) && in_array('smtp_host', $nama_kolom, true)
    && in_array('brand_primary_color', $nama_kolom, true) && in_array('rate_limit_login_max', $nama_kolom, true),
    'kolom dari kelima bagian ikut terkirim dalam satu formulir');

// ═════════════════════════════════════════════════════════════════════
echo "\n=== B. Menyimpan tanpa mengubah apa pun ===\n";

$sebelum = settings_kini();
$body = jadi_body(array_merge([['csrf_token', $csrf]], $kolom));
[$kode, $loc] = kirim_form($sid, 'handlers/admin_settings_handler.php', $body);
cek(strpos($loc, 'success=saved') !== false, 'penyimpanan diterima', "HTTP $kode $loc");

$sesudah = settings_kini();
$berubah = [];
foreach ($sesudah as $k => $v) {
    if (!array_key_exists($k, $sebelum) || !setara($sebelum[$k], $v)) {
        $berubah[] = $k;
    }
}
foreach (array_diff(array_keys($sebelum), array_keys($sesudah)) as $k) {
    $berubah[] = "$k (hilang)";
}
cek($berubah === [], 'tidak ada nilai yang berubah diam-diam', implode(', ', $berubah) ?: 'nol perubahan');

// Penyimpanan kedua harus menghasilkan tabel yang PERSIS sama. Bila sebuah
// nilai terus berubah tiap kali Simpan ditekan, ada yang menulis ulang
// dirinya sendiri — dan itu selalu berakhir sebagai data yang bergeser.
$sesudah1 = settings_kini();
kirim_form($sid, 'handlers/admin_settings_handler.php', $body);
$sesudah2 = settings_kini();
$goyah = [];
foreach ($sesudah2 as $k => $v) {
    if (($sesudah1[$k] ?? null) !== $v) {
        $goyah[] = $k;
    }
}
cek($goyah === [], 'menyimpan dua kali menghasilkan hasil yang sama persis', implode(', ', $goyah) ?: 'stabil');

// Jenis dokumen legalisir TIDAK boleh terhapus bila kolomnya dikirim kosong
// (JavaScript gagal dimuat, atau formulir dikirim tanpa menekan tombolnya).
$dok_sebelum = settings_kini()['legalisir_document_types'] ?? '';
$tanpa_dok = [];
foreach ($kolom as [$n, $v]) {
    $tanpa_dok[] = [$n, $n === 'legalisir_document_types' ? '' : $v];
}
kirim_form($sid, 'handlers/admin_settings_handler.php', jadi_body(array_merge([['csrf_token', $csrf]], $tanpa_dok)));
cek((settings_kini()['legalisir_document_types'] ?? '') === $dok_sebelum,
    'kiriman kosong tidak menghapus daftar jenis dokumen',
    substr((string)(settings_kini()['legalisir_document_types'] ?? ''), 0, 40));

// Kolom tersembunyinya pun membawa nilai sendiri, jadi pengiriman tanpa
// JavaScript tetap mengirim daftar yang benar.
cek(preg_match('#name="legalisir_document_types"[^>]*value="[^"]+"#', $html) === 1
    || preg_match('#value="[^"]+"[^>]*name="legalisir_document_types"#', $html) === 1,
    'kolom tersembunyi jenis dokumen sudah berisi nilainya');

// ═════════════════════════════════════════════════════════════════════
echo "\n=== C. Setiap kolom benar-benar tersimpan ===\n";

/** Nilai uji per kolom: berbeda dari nilai sekarang, tetapi masuk akal. */
$ubah = [
    'institution_name'           => 'FK UMS UJIPENGATURAN',
    'price_per_doc'              => '12345',
    'pagination_size'            => '17',
    'password_min_length'        => '9',
    'session_timeout_minutes'    => '42',
    'tracer_validity_months'     => '7',
    'smtp_host'                  => 'smtp.ujipengaturan.test',
    'smtp_port'                  => '2525',
    'max_file_size'              => '3072',
    'audit_log_retention_days'   => '45',
    'rate_limit_login_max'       => '4',
    'brand_primary_color'        => '#123456',
    'email_batch_size'           => '25',
    'geocoder_batch_size'        => '12',
    'default_major_code'         => 'UJI9',
];

$kolom_ubah = [];
foreach ($kolom as [$n, $v]) {
    $kolom_ubah[] = [$n, $ubah[$n] ?? $v];
}
$body = jadi_body(array_merge([['csrf_token', $csrf]], $kolom_ubah));
[$kode, $loc] = kirim_form($sid, 'handlers/admin_settings_handler.php', $body);
cek(strpos($loc, 'success=saved') !== false, 'penyimpanan dengan perubahan diterima', "HTTP $kode");

$sesudah = settings_kini();
$gagal_simpan = [];
foreach ($ubah as $k => $v) {
    if (($sesudah[$k] ?? null) !== $v) {
        $gagal_simpan[] = $k . ' (' . var_export($sesudah[$k] ?? null, true) . ')';
    }
}
cek($gagal_simpan === [], 'seluruh kolom yang diubah tersimpan', implode(', ', $gagal_simpan) ?: count($ubah) . ' kolom');

// Kolom yang TIDAK diubah tetap utuh — penyimpanan tidak merembet.
$merembet = [];
foreach ($sebelum as $k => $v) {
    if (!isset($ubah[$k]) && !setara($v, $sesudah[$k] ?? null)) {
        $merembet[] = $k;
    }
}
cek($merembet === [], 'kolom lain tidak ikut berubah', implode(', ', $merembet) ?: 'nol rembetan');

// ═════════════════════════════════════════════════════════════════════
echo "\n=== D. Sakelar, daftar, dan JSON ===\n";

// Kotak centang yang DILEPAS harus benar-benar tersimpan sebagai '0'.
// Nilainya tidak ikut terkirim saat tidak dicentang, jadi handler wajib
// menanganinya sendiri — dulu inilah cara sakelar "menghidup sendiri".
$sakelar = ['dashboard_bg_animation', 'smtp_force_real', 'email_send_direct', 'audit_log_auto_erase', 'rbac_enforce'];
$tanpa_sakelar = array_values(array_filter($kolom, function ($p) use ($sakelar) {
    return !in_array($p[0], $sakelar, true);
}));
kirim_form($sid, 'handlers/admin_settings_handler.php',
    jadi_body(array_merge([['csrf_token', $csrf]], $tanpa_sakelar)));
$sesudah = settings_kini();
$salah = [];
foreach ($sakelar as $s) {
    if (($sesudah[$s] ?? null) !== '0') {
        $salah[] = $s . '=' . var_export($sesudah[$s] ?? null, true);
    }
}
cek($salah === [], 'kotak centang yang dilepas tersimpan sebagai nonaktif', implode(', ', $salah) ?: count($sakelar) . ' sakelar');

// Zona ongkir: array bersarang -> JSON
$zona_baru = [
    ['csrf_token', $csrf],
    ['zones[0][label]', 'Zona UJIPENGATURAN'],
    ['zones[0][cost]', '23000'],
    ['zones[0][is_default]', '1'],
    ['zones[0][provinces][]', 'Jawa Tengah'],
    ['zones[0][provinces][]', 'DI Yogyakarta'],
];
kirim_form($sid, 'handlers/admin_settings_handler.php', jadi_body($zona_baru));
$zona = json_decode(settings_kini()['shipping_zones'] ?? '[]', true);
cek(is_array($zona) && count($zona) === 1 && $zona[0]['label'] === 'Zona UJIPENGATURAN'
    && (int)$zona[0]['cost'] === 23000 && $zona[0]['is_default'] === true
    && $zona[0]['provinces'] === ['Jawa Tengah', 'DI Yogyakarta'],
    'zona ongkir tersimpan sebagai JSON yang benar', json_encode($zona[0] ?? null));

// Izin sidebar per peran
$menu_baru = [
    ['csrf_token', $csrf],
    ['menus[alumni][]', 'dashboard'],
    ['menus[alumni][]', 'legalisir'],
    ['menus[alumni][]', 'bukan_menu_yang_sah'],
    ['menus[keuangan][]', 'admin_keuangan'],
];
kirim_form($sid, 'handlers/admin_settings_handler.php', jadi_body($menu_baru));
$izin = json_decode(settings_kini()['sidebar_permissions'] ?? '[]', true);
cek(($izin['alumni'] ?? []) === ['dashboard', 'legalisir'], 'izin sidebar tersimpan, kunci ngawur dibuang',
    implode(',', $izin['alumni'] ?? []));
cek(($izin['keuangan'] ?? []) === ['admin_keuangan'], 'izin peran lain ikut tersimpan');

// Jenis dokumen legalisir (JSON dari editor JavaScript)
$dok = json_encode([
    ['id' => 'uji_ijazah', 'name' => 'Ijazah UJIPENGATURAN', 'target_major_code' => 'J500', 'is_akreditasi' => false],
]);
kirim_form($sid, 'handlers/admin_settings_handler.php',
    jadi_body([['csrf_token', $csrf], ['legalisir_document_types', $dok]]));
$tersimpan = json_decode(settings_kini()['legalisir_document_types'] ?? '[]', true);
cek(($tersimpan[0]['id'] ?? '') === 'uji_ijazah', 'jenis dokumen tersimpan sebagai JSON', json_encode($tersimpan[0] ?? null));

// ═════════════════════════════════════════════════════════════════════
echo "\n=== E. Kunci yang tidak boleh ditulis dari sini ===\n";

$terlarang = ['midtrans_server_key', 'flip_secret_key', 'fee_midtrans_percent', 'payment_gateway_active',
              'cron_token', 'schema_version', 'app_signing_secret'];
$nilai_sebelum = [];
foreach ($terlarang as $k) {
    $nilai_sebelum[$k] = settings_kini()[$k] ?? null;
}
$serangan = [['csrf_token', $csrf]];
foreach ($terlarang as $k) {
    $serangan[] = [$k, 'DISUSUPKAN-UJIPENGATURAN'];
}
kirim_form($sid, 'handlers/admin_settings_handler.php', jadi_body($serangan));
$kini = settings_kini();
$tembus = [];
foreach ($terlarang as $k) {
    if (($kini[$k] ?? null) !== $nilai_sebelum[$k]) {
        $tembus[] = $k;
    }
}
cek($tembus === [], 'kunci pembayaran dan rahasia sistem ditolak', implode(', ', $tembus) ?: count($terlarang) . ' kunci aman');

// ═════════════════════════════════════════════════════════════════════
echo "\n=== F. Penjagaan peran ===\n";

$sid_staf = sesi_palsu($uid, 'admin_legalisir', $csrf);
register_shutdown_function(function () use ($sid_staf) { sesi_hapus($sid_staf); });
[$kode] = kirim_form($sid_staf, 'handlers/admin_settings_handler.php',
    jadi_body([['csrf_token', $csrf], ['institution_name', 'DIUBAH STAF']]));
cek($kode === 403, 'staf non-super-admin ditolak handler', "HTTP $kode");
cek((settings_kini()['institution_name'] ?? '') !== 'DIUBAH STAF', 'nilainya tidak berubah');

[$kode] = kirim_form($sid, 'handlers/admin_settings_handler.php',
    jadi_body([['institution_name', 'TANPA TOKEN']]));
cek($kode === 403, 'tanpa token CSRF ditolak', "HTTP $kode");

// ═════════════════════════════════════════════════════════════════════
echo "\n=== G. Tidak ada pengaturan yang tersembunyi dari antarmuka ===\n";

/**
 * Setiap kunci yang DIBACA kode harus dapat diatur di suatu tempat.
 *
 * Kunci yang hanya hidup di basis data adalah jebakan: perilakunya nyata,
 * tetapi tidak ada yang tahu ia ada, dan mengubahnya menuntut akses
 * phpMyAdmin. Sempat ada 13 kunci seperti itu — termasuk kunci API yang
 * halamannya sendiri menyuruh mengisinya di Pengaturan, padahal kolomnya
 * tidak pernah dibuat.
 */
$dikelola_panel = ['midtrans_server_key', 'midtrans_client_key', 'midtrans_is_production',
    'flip_secret_key', 'flip_validation_token', 'flip_is_production', 'payment_gateway_active',
    'payment_fallback_enabled', 'payment_expiry', 'payment_custom_charge_legalisir',
    'payment_custom_charge_donasi', 'legalisir_require_paid', 'payment_fallback_notice_at'];
foreach (['midtrans', 'flip'] as $g) {
    foreach (['percent', 'vat_percent', 'flat', 'app', 'min', 'reviewed'] as $k) {
        $dikelola_panel[] = "fee_{$g}_{$k}";
    }
    $dikelola_panel[] = "payment_last_test_$g";
}

// Kunci internal: ditulis sistem, bukan manusia. Setiap tambahan di sini
// harus punya alasan.
$internal = [
    'schema_version'           => 'penanda migrasi, ditulis config/db.php',
    'cron_token'               => 'dibuat sekali oleh includes/cron_auth.php',
    'app_signing_secret'       => 'dibuat sekali oleh includes/token_lib.php',
    'system_logo'              => 'diisi lewat unggahan berkas, bukan kolom teks',
    'sidebar_permissions'      => 'diisi matriks kotak centang per peran',
    'shipping_zones'           => 'diisi editor zona',
    'legacy_unsubscribe_token' => 'sakelar kompatibilitas tautan lama',
    'system_name'              => 'kunci lama; digantikan institution_name lewat nama_institusi()',
    'midtrans_mdr_rate'        => 'kunci tarif lama, hanya dibaca saat migrasi',
    'midtrans_ppn_rate'        => 'kunci tarif lama, hanya dibaca saat migrasi',
    'midtrans_payout_fee'      => 'kunci tarif lama, hanya dibaca saat migrasi',
    'midtrans_margin_admin'    => 'kunci tarif lama, hanya dibaca saat migrasi',
    'custom_tax_value'         => 'kunci tarif lama, hanya dibaca saat migrasi',
    'shipping_fee'             => 'ongkir cadangan bila zona kosong',
    'admin_fee'                => 'potongan laporan keuangan, belum ada di antarmuka',
];

$dibaca = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(AKAR, FilesystemIterator::SKIP_DOTS));
foreach ($it as $berkas) {
    $jalur = str_replace('\\', '/', $berkas->getPathname());
    if (substr($jalur, -4) !== '.php' || preg_match('#/(_dev|node_modules|backups|tests)/#', $jalur)) {
        continue;
    }
    $isi = file_get_contents($jalur);
    foreach ([
        "/setting(?:_int|_bool)?\(\s*'([a-z0-9_]+)'/i",
        "/\\\$settings\['([a-z0-9_]+)'\]/",
        "/\\\$sys_settings\['([a-z0-9_]+)'\]/",
    ] as $pola) {
        if (preg_match_all($pola, $isi, $m)) {
            foreach ($m[1] as $k) {
                if (substr($k, -1) === '_') {
                    continue;   // potongan nama yang dirangkai di kode
                }
                $dibaca[$k] = str_replace(str_replace(chr(92), '/', AKAR) . '/', '', $jalur);
            }
        }
    }
}

// Seluruh nama kolom di HTML — termasuk kotak centang yang sedang tidak
// tercentang, yang memang tidak ikut terkirim tetapi kolomnya ada.
preg_match_all('/name="([a-z0-9_]+)(?:\[[^"]*\])?"/', $html, $m_nama);
$kolom_form = array_flip($m_nama[1]);
$tersembunyi = [];
foreach ($dibaca as $k => $tempat) {
    if (isset($kolom_form[$k]) || in_array($k, $dikelola_panel, true) || isset($internal[$k])
        || strpos($k, 'rate_limit_') === 0) {
        continue;
    }
    $tersembunyi[] = "$k ($tempat)";
}
cek($tersembunyi === [], 'setiap pengaturan dapat diatur dari antarmuka atau terdaftar internal',
    implode('; ', $tersembunyi) ?: count($dibaca) . ' kunci diperiksa');

// Kunci API AI tidak boleh terhapus hanya karena kolomnya dirender kosong.
setting_save('gemini_api_key', 'KUNCI-UJIPENGATURAN');
all_settings(true);
[$kode2, $html2] = ambil_halaman($sid, 'index.php?page=admin_settings');
$kolom2 = kolom_formulir($html2, 'settings-form');
$isi_gemini = '';
foreach ($kolom2 as [$n, $v]) {
    if ($n === 'gemini_api_key') {
        $isi_gemini = $v;
    }
}
cek($isi_gemini === '', 'kunci API tidak pernah dicetak kembali ke halaman');
kirim_form($sid, 'handlers/admin_settings_handler.php', jadi_body(array_merge([['csrf_token', $csrf]], $kolom2)));
cek((settings_kini()['gemini_api_key'] ?? '') === 'KUNCI-UJIPENGATURAN',
    'menyimpan pengaturan tidak menghapus kunci API yang sudah terisi');
kirim_form($sid, 'handlers/admin_settings_handler.php',
    jadi_body([['csrf_token', $csrf], ['gemini_api_key', 'KUNCI-BARU-UJIPENGATURAN']]));
cek((settings_kini()['gemini_api_key'] ?? '') === 'KUNCI-BARU-UJIPENGATURAN', 'kunci API dapat diganti');

// ── Rahasia tidak boleh ikut tercetak ke halaman ─────────────────────
//
// type="password" hanya menyembunyikan di layar. Bila nilainya dirender,
// siapa pun yang membuka sumber halaman — atau menerima tangkapan layar
// sumbernya — membaca kata sandi SMTP dan secret OAuth apa adanya.
$rahasia_uji = [
    'smtp_pass'            => 'SANDI-SMTP-UJIPENGATURAN',
    'google_client_secret' => 'SECRET-GOOGLE-UJIPENGATURAN',
    'gemini_api_key'       => 'KUNCI-GEMINI-UJIPENGATURAN',
];
foreach ($rahasia_uji as $k => $v) {
    setting_save($k, $v);
}
all_settings(true);
[, $html3] = ambil_halaman($sid, 'index.php?page=admin_settings');
$bocor = [];
foreach ($rahasia_uji as $k => $v) {
    if (strpos($html3, $v) !== false) {
        $bocor[] = $k;
    }
}
cek($bocor === [], 'rahasia tidak pernah dirender ke halaman',
    implode(', ', $bocor) ?: count($rahasia_uji) . ' rahasia aman');

// Menyimpan seluruh formulir tidak boleh menghapusnya.
$kolom3 = kolom_formulir($html3, 'settings-form');
kirim_form($sid, 'handlers/admin_settings_handler.php', jadi_body(array_merge([['csrf_token', $csrf]], $kolom3)));
$kini3 = settings_kini();
$hilang = [];
foreach ($rahasia_uji as $k => $v) {
    if (($kini3[$k] ?? null) !== $v) {
        $hilang[] = $k;
    }
}
cek($hilang === [], 'menyimpan pengaturan tidak menghapus rahasia yang tersimpan',
    implode(', ', $hilang) ?: 'utuh');

// Tetapi kotak "Kosongkan" memang mengosongkannya.
kirim_form($sid, 'handlers/admin_settings_handler.php',
    jadi_body([['csrf_token', $csrf], ['hapus_smtp_pass', '1']]));
cek((settings_kini()['smtp_pass'] ?? null) === '', 'kotak "Kosongkan" menghapus rahasia bila diminta');
cek((settings_kini()['google_client_secret'] ?? null) === $rahasia_uji['google_client_secret'],
    'rahasia lain tidak ikut terhapus');

echo "\n────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
exit($fail ? 1 : 0);
