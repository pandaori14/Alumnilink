<?php
/**
 * tests/smoke.php
 * ─────────────────────────────────────────────────────────
 * Smoke test AlumniLink — verifikasi keamanan dan fungsi inti.
 *
 * Proyek ini tidak memakai framework pengujian. Skrip ini memformalkan
 * pemeriksaan yang sebelumnya dilakukan manual, agar dapat diulang sebelum dan
 * sesudah setiap upload FTP.
 *
 * PEMAKAIAN
 *   php tests/smoke.php
 *   php tests/smoke.php https://apps-kedokteran.ums.ac.id/alumnilink
 *   php tests/smoke.php http://localhost/alumnilink --with-db
 *
 * OPSI
 *   --with-db   Ambil satu token verifikasi sah dari database untuk menguji
 *               jalur POSITIF penyajian dokumen. Hanya jalan bila skrip
 *               dieksekusi di mesin yang punya akses ke database tersebut.
 *               Tanpa opsi ini, seluruh pemeriksaan bersifat jarak jauh dan
 *               aman dijalankan dari mana pun ke URL produksi.
 *
 * KODE KELUAR
 *   0 = seluruh pemeriksaan lulus
 *   1 = ada yang gagal
 *
 * Skrip ini hanya boleh dijalankan lewat CLI. Direktori tests/ juga diblokir
 * di .htaccess sehingga tidak dapat dijangkau lewat web.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Skrip ini hanya dapat dijalankan lewat command line.');
}

if (!function_exists('curl_init')) {
    exit("Ekstensi cURL PHP tidak tersedia. Aktifkan extension=curl di php.ini.\n");
}

$argvList = $argv;
array_shift($argvList);

$withDb  = in_array('--with-db', $argvList, true);
$argvList = array_values(array_filter($argvList, function ($a) {
    return strpos($a, '--') !== 0;
}));
$BASE = rtrim($argvList[0] ?? 'http://localhost/alumnilink', '/');

// Database dimuat SEBELUM ada keluaran apa pun. config/db.php ikut memuat
// includes/logger.php yang memanggil session_start(); bila dijalankan setelah
// baris pertama tercetak, PHP memunculkan peringatan "headers already sent"
// yang mengotori hasil tes.
$DB_READY = false;
if ($withDb) {
    $dbFile = dirname(__DIR__) . '/config/db.php';
    if (is_file($dbFile)) {
        require_once $dbFile;
        $DB_READY = isset($pdo);
    }
}

$PASS = 0;
$FAIL = 0;
$FAILED_LABELS = [];

// ─────────────────────────────────────────────────────────
// Utilitas
// ─────────────────────────────────────────────────────────

/** Lakukan satu permintaan HTTP, kembalikan status, tipe konten, ukuran, dan cuplikan isi. */
function req($url, $method = 'GET', $body = null, $headers = [])
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    return [
        'code'  => $code,
        'type'  => $type,
        'size'  => $raw === false ? 0 : strlen($raw),
        'body'  => $raw === false ? '' : substr($raw, 0, 400),
        'error' => $err,
    ];
}

function report($ok, $label, $detail)
{
    global $PASS, $FAIL, $FAILED_LABELS;
    if ($ok) {
        $PASS++;
        printf("  \033[32mLULUS\033[0m  %-52s %s\n", $label, $detail);
    } else {
        $FAIL++;
        $FAILED_LABELS[] = $label;
        printf("  \033[31mGAGAL\033[0m  %-52s %s\n", $label, $detail);
    }
}

/** Pastikan status HTTP termasuk salah satu yang diharapkan. */
function expect_status($label, $path, array $allowed, $method = 'GET', $body = null, $headers = [])
{
    global $BASE;
    $r = req($BASE . '/' . ltrim($path, '/'), $method, $body, $headers);

    if ($r['error'] !== '') {
        report(false, $label, 'galat koneksi: ' . $r['error']);
        return $r;
    }

    $ok = in_array($r['code'], $allowed, true);
    report($ok, $label, sprintf('HTTP %d (harap %s)', $r['code'], implode('/', $allowed)));
    return $r;
}

/**
 * Pastikan sebuah endpoint MENOLAK aksi. Sebagian handler lama menolak dengan
 * die("Unauthorized access.") yang tetap berstatus HTTP 200, sehingga status
 * saja tidak cukup untuk menilai.
 */
function expect_rejected($label, $path, $method = 'GET', $body = null, $headers = [])
{
    global $BASE;
    $r = req($BASE . '/' . ltrim($path, '/'), $method, $body, $headers);

    if ($r['error'] !== '') {
        report(false, $label, 'galat koneksi: ' . $r['error']);
        return;
    }

    $blockedByStatus = in_array($r['code'], [400, 401, 403, 404, 405], true);
    $blockedByBody   = (bool)preg_match(
        '/unauthorized|akses ditolak|csrf|tidak ditemukan|silakan masuk/i',
        $r['body']
    );

    report(
        $blockedByStatus || $blockedByBody,
        $label,
        sprintf('HTTP %d%s', $r['code'], $blockedByBody ? ' + badan pesan menolak' : '')
    );
}

function heading($text)
{
    printf("\n\033[1m%s\033[0m\n", $text);
}

// ─────────────────────────────────────────────────────────
// Mulai
// ─────────────────────────────────────────────────────────

printf("\n\033[1mSMOKE TEST ALUMNILINK\033[0m\n");
printf("Target   : %s\n", $BASE);
printf("Mode DB  : %s\n", $withDb ? 'aktif (menguji jalur positif dokumen)' : 'nonaktif');

// ── 1. Berkas sensitif harus tertutup ────────────────────
heading('1. Berkas & direktori sensitif (harap 403/404)');

$blocked = [
    '.env',
    '.env.example',
    'alumnilink.sql',
    'schema.sql',
    'README.md',
    '_dev/docs/DEPLOY_CHECKLIST.md',
    '_dev/docs/PLAN_PENYEMPURNAAN.md',
    'logs/mail_debug.log',
    '_dev/scratch/inspect_db.php',
    '_dev/scratch/dump.php',
    'tests/smoke.php',
    '_dev/docker/docker-compose.yml',
    '_dev/docker/Dockerfile',
    '_dev/build/tailwind.config.js',
    'scratch_migrate_broadcast_fixes.php',
];
foreach ($blocked as $p) {
    expect_status($p, $p, [403, 404]);
}

// ── 2. Halaman publik harus hidup ────────────────────────
heading('2. Halaman publik (harap 200)');

foreach ([
    ''                          => 'akar',
    'index.php?page=landing'    => 'landing',
    'index.php?page=login'      => 'login',
    'index.php?page=register'   => 'register',
    'index.php?page=all_news'   => 'all_news',
    'index.php?page=terms'      => 'terms',
    'login'                     => 'pretty URL /login',
] as $path => $label) {
    expect_status($label, $path, [200]);
}

// ── 2b. Kesehatan struktur HTML ──────────────────────────
//
// Ditambahkan setelah sebuah bug membuat SELURUH halaman menampilkan potongan
// kode mentah di bagian atas, namun lolos dari `php -l` maupun pemeriksaan
// kode status HTTP -- keduanya tidak melihat hasil render.
//
// Dua penyebab yang pernah terjadi dan ditangkap di sini:
//   1. Tag penutup PHP di dalam komentar menutup blok PHP lebih awal,
//      sehingga sisa komentar tercetak sebelum <!DOCTYPE html>.
//   2. Penyisipan markup ke dalam atribut tag <body> karena pola pencarian
//      berhenti pada tanda '>' milik blok PHP di dalam tag tersebut.
heading('2b. Struktur HTML halaman (deteksi keluaran rusak)');

/** Periksa keutuhan struktur satu dokumen HTML. */
function periksa_struktur($label, $html)
{
    // a. Tidak boleh ada apa pun sebelum <!DOCTYPE>
    $mulaiBersih = stripos(ltrim($html), '<!doctype html') === 0;
    report($mulaiBersih, "$label: diawali <!DOCTYPE>", $mulaiBersih
        ? 'bersih'
        : 'ADA TEKS LIAR: "' . trim(preg_replace('/\s+/', ' ', substr(ltrim($html), 0, 70))) . '"');

    // b. Tag <body> harus utuh -- tidak boleh memuat '<' di dalam atributnya
    if (preg_match('/<body\b([^>]*)>/i', $html, $bm)) {
        $bodyBersih = strpos($bm[1], '<') === false;
        report($bodyBersih, "$label: tag <body> utuh", $bodyBersih
            ? 'atribut wajar'
            : 'MARKUP TERSISIP DI ATRIBUT: "' . trim(preg_replace('/\s+/', ' ', substr($bm[1], 0, 70))) . '"');
    } else {
        report(false, "$label: tag <body> ditemukan", 'tidak ada <body>');
    }
}

// Halaman publik memakai <head> sendiri dan TIDAK memuat includes/header.php.
foreach ([
    'index.php?page=landing'  => 'landing',
    'index.php?page=login'    => 'login',
    'index.php?page=register' => 'register',
    'index.php?page=terms'    => 'terms',
] as $path => $label) {
    $full = @file_get_contents($BASE . '/' . $path);
    periksa_struktur($label, $full === false ? req($BASE . '/' . $path)['body'] : $full);
}

// PENTING: halaman berlayout (yang memuat includes/header.php) hanya dapat
// diakses setelah masuk. Tanpa memeriksa salah satunya, kerusakan pada
// header.php TIDAK akan terdeteksi sama sekali -- persis yang pernah terjadi.
if ($DB_READY) {
    $sessDir = ini_get('session.save_path') ?: sys_get_temp_dir();
    $staff   = $pdo->query("SELECT id FROM users WHERE role <> 'alumni' LIMIT 1")->fetchColumn();

    if ($staff && is_dir($sessDir)) {
        $sid = 'smoke' . bin2hex(random_bytes(6));
        file_put_contents(
            $sessDir . '/sess_' . $sid,
            'user_id|s:' . strlen($staff) . ':"' . $staff . '";'
            . 'user_name|s:5:"Smoke";user_role|s:11:"super_admin";'
        );

        foreach (['dashboard' => 'dashboard (berlayout)', 'profile' => 'profile (berlayout)'] as $page => $label) {
            $ch = curl_init($BASE . '/index.php?page=' . $page);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_COOKIE         => 'PHPSESSID=' . $sid,
            ]);
            $html = (string)curl_exec($ch);
            curl_close($ch);
            periksa_struktur($label, $html);
        }

        @unlink($sessDir . '/sess_' . $sid);
    } else {
        report(true, 'halaman berlayout', 'dilewati — tidak ada akun staf atau direktori sesi');
    }
} else {
    report(true, 'halaman berlayout', 'dilewati — perlu opsi --with-db');
}

// ── 3. Aset publik tetap tersaji ─────────────────────────
heading('3. Aset publik (harap 200) — avatar & cover berita sengaja tetap publik');

$logo = req($BASE . '/uploads/system/logo_1778236863.png');
report(
    in_array($logo['code'], [200, 404], true),
    'logo sistem',
    sprintf('HTTP %d%s', $logo['code'], $logo['code'] === 404 ? ' (berkas memang tidak ada di target ini)' : '')
);

// ── 3b. Aset lokal pengganti CDN ─────────────────────────
heading('3b. Aset lokal (harap 200) — pengganti CDN');

foreach ([
    'assets/css/app.css'              => 'Tailwind hasil build',
    'assets/fonts/fonts.css'          => 'font lokal',
    'assets/js/lucide.min.js'         => 'Lucide',
    'assets/js/sweetalert2.min.js'    => 'SweetAlert2',
    'assets/js/apexcharts.min.js'     => 'ApexCharts',
    'assets/js/sortable.min.js'       => 'SortableJS',
    'assets/js/leaflet.js'            => 'Leaflet',
    'assets/js/pdf.min.js'            => 'pdf.js',
    'assets/css/images/marker-icon.png' => 'ikon marker Leaflet',
] as $path => $label) {
    expect_status($label, $path, [200]);
}

// Pastikan halaman tidak lagi memuat aset dari CDN mana pun.
heading('3c. Halaman tidak boleh memuat aset eksternal');

foreach (['index.php?page=landing', 'index.php?page=login'] as $p) {
    $r = req($BASE . '/' . $p);
    // ui-avatars.com ikut diperiksa: layanan itu menerima NAMA ASLI alumni
    // pada setiap pemuatan halaman, jadi kembalinya rujukan itu adalah
    // kebocoran data, bukan sekadar ketergantungan CDN.
    $pola = '#(src|href)="https?://(cdn|unpkg|fonts\.googleapis|cdnjs|ajax|ui-avatars)#i';
    $hits = preg_match_all($pola, $r['body'] . '', $mm);
    // body dipotong 400 karakter oleh req(); ambil ulang penuh untuk pemeriksaan ini
    $full = @file_get_contents($BASE . '/' . $p);
    $hits = $full === false ? $hits : preg_match_all($pola, $full, $mm);
    report($hits === 0, "tanpa aset CDN: $p", $hits === 0 ? 'bersih' : "$hits rujukan tersisa");
}

// Badan halaman dan pustaka bersama hanya boleh dimuat lewat index.php,
// yang menerapkan penjagaan sesi/verifikasi/RBAC sebelum menyertakannya.
heading('3d. Berkas program tidak dapat dibuka langsung (harap 403)');

foreach ([
    'pages/admin_users.php'   => 'pages/ (halaman admin)',
    'pages/alumni_map.php'    => 'pages/ (halaman peta)',
    'includes/header.php'     => 'includes/ (tata letak)',
    'includes/menu.php'       => 'includes/ (definisi menu)',
] as $path => $label) {
    expect_status($label, $path, [403]);
}

// ── 4. Folder dokumen sensitif tertutup ──────────────────
heading('4. Folder dokumen sensitif (harap 403)');

foreach ([
    'uploads/legalisir/',
    'uploads/repository/',
    'uploads/accreditation/',
    'uploads/legalisir/ijazah_l200160042_1778141213.pdf',
] as $p) {
    expect_status($p, $p, [403]);
}

// ── 5. serve_document.php menegakkan otorisasi ───────────
heading('5. serve_document.php tanpa sesi (harap ditolak)');

foreach ([
    'ctx=legalisir&req=LEG-X&i=0'                => 'konteks legalisir',
    'ctx=repository&id=1'                        => 'konteks repository',
    'ctx=accreditation&id=1'                     => 'konteks accreditation',
    'ctx=softcopy&req=LEG-X&token=salah&i=0'     => 'konteks softcopy token salah',
    'ctx=tidakdikenal'                           => 'konteks tidak dikenal',
    ''                                           => 'tanpa parameter',
] as $q => $label) {
    expect_rejected($label, 'serve_document.php?' . $q);
}

// ── 6. Handler ber-CSRF menolak tanpa token ──────────────
heading('6. Handler terlindung, tanpa sesi & tanpa token CSRF (harap ditolak)');

expect_rejected('admin_settings_handler', 'handlers/admin_settings_handler.php', 'POST', ['x' => '1']);
expect_rejected('admin_verify_cash',      'handlers/admin_verify_cash.php?id=LEG-X');
expect_rejected('admin_delete_legalisir', 'handlers/admin_delete_legalisir.php?id=LEG-X');
expect_rejected('gemini_ai',              'handlers/gemini_ai.php');
expect_rejected('get_accreditation_file', 'handlers/get_accreditation_file.php?nim=123');
expect_rejected(
    'admin_tracer_toggle (JSON)',
    'handlers/admin_tracer_toggle.php',
    'POST',
    '{"id":1,"is_active":1}',
    ['Content-Type: application/json']
);
expect_rejected('admin_alumni_import',   'handlers/admin_alumni_import.php?action=preview', 'POST', ['x' => '1']);
expect_rejected('admin_legalisir_bulk',  'handlers/admin_legalisir_bulk.php', 'POST', ['ids' => ['X'], 'status' => 'processing']);
expect_rejected('cetak_label (massal)',  'cetak_label.php?ids=LEG-X,LEG-Y');
expect_rejected('admin_backup',          'handlers/admin_backup.php', 'POST', ['x' => '1']);

// Pemicu tugas terjadwal wajib bertoken. Sebelumnya geocoder.php terbuka
// tanpa kunci apa pun, dan tracer_reminder.php memakai token yang ditulis
// di dalam kode.
foreach ([
    'cron/geocoder.php?run=1'      => 'cron geocoder (cara lama)',
    'cron/process_email_queue.php' => 'cron antrean e-mail',
    'cron/tracer_reminder.php?token=tracer-cron-secure-token-123' => 'cron pengingat (token lama)',
    'cron/backup.php'              => 'cron cadangan',
] as $path => $label) {
    expect_rejected($label, $path);
}

// Dump basis data tidak boleh dapat diambil lewat web.
expect_status('folder backups/ tertutup', 'backups/', [403, 404]);

// ── 6b. Endpoint peta tidak boleh membocorkan data pribadi ──
heading('6b. Peta persebaran — data pribadi (harap tanpa sesi ditolak)');

expect_rejected('geodistribution tanpa sesi', 'api/alumni/geodistribution.php');

// Alamat rumah hanya boleh keluar untuk staf. Tanpa sesi, badan balasan
// tidak boleh memuat kunci "address" sama sekali.
$rg = req($BASE . '/api/alumni/geodistribution.php');
report(strpos($rg['body'], '"address"') === false,
    'tanpa sesi: nol kunci address di balasan', 'HTTP ' . $rg['code']);
report(strpos($rg['body'], 'SQLSTATE') === false && stripos($rg['body'], 'SELECT ') === false,
    'balasan galat tidak memuat detail SQL', 'bersih');

// ── 6c. Kode PHP tidak bocor sebagai teks ────────────────
heading('6c. Kode PHP tidak bocor sebagai teks');

// `php -l` tidak dapat menangkap kelas galat ini: berkasnya sah secara
// sintaks, yang salah adalah PHP menganggap sebagian isinya sebagai
// keluaran. Sudah tiga kali terjadi di proyek ini, terakhir membuat
// send_employer_survey_invitation() tidak pernah terdefinisi.
$lint = __DIR__ . '/lint_leaked_php.php';
if (is_file($lint)) {
    $keluaran = [];
    $kode = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($lint) . ' 2>&1', $keluaran, $kode);
    $ringkas = '';
    foreach ($keluaran as $baris) {
        if (strpos($baris, 'Kebocoran:') !== false) { $ringkas = trim($baris); }
    }
    report($kode === 0, 'nol kebocoran kode PHP', $ringkas ?: "exit=$kode");
} else {
    report(false, 'nol kebocoran kode PHP', 'tests/lint_leaked_php.php tidak ada');
}

// ── 6d. Keluaran variabel tidak boleh tanpa escape ───────
heading('6d. Keluaran variabel di-escape (XSS)');

// Nama alumni disimpan mentah lalu dulu dirender tanpa escape ke layar staf.
// Parameter URL juga dipantulkan mentah ke atribut. Keduanya sudah dibuktikan
// dapat dieksekusi sebelum diperbaiki.
$lint2 = __DIR__ . '/lint_unescaped.php';
if (is_file($lint2)) {
    $keluaran = [];
    $kode = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($lint2) . ' 2>&1', $keluaran, $kode);
    $ringkas = '';
    foreach ($keluaran as $baris) {
        if (strpos($baris, 'tanpa escape:') !== false) { $ringkas = trim($baris); }
    }
    report($kode === 0, 'nol keluaran tanpa escape', $ringkas ?: "exit=$kode");
} else {
    report(false, 'nol keluaran tanpa escape', 'tests/lint_unescaped.php tidak ada');
}

// ── 7. Alias webhook Midtrans tersedia ───────────────────
heading('7. Webhook Midtrans — kedua nama berkas harus ADA (bukan 404)');

foreach (['handlers/midtrans_webhook.php', 'handlers/midtrans_notification.php'] as $p) {
    $r = req($BASE . '/' . $p, 'POST', '{}', ['Content-Type: application/json']);
    // Payload kosong wajar ditolak 400/403/500 — yang penting BUKAN 404.
    report($r['code'] !== 404, $p, sprintf('HTTP %d (yang penting bukan 404)', $r['code']));
}

// ── 8. Jalur positif dokumen (butuh database) ────────────
if ($withDb) {
    heading('8. Jalur POSITIF penyajian dokumen (via database)');

    if (!$DB_READY) {
        report(false, 'muat config/db.php', 'gagal dimuat atau koneksi database tidak tersedia');
    } else {
        try {
            $row = $pdo->query(
                "SELECT id, verification_token FROM legalisir_requests
                 WHERE verification_token IS NOT NULL AND status = 'completed' LIMIT 1"
            )->fetch();

            if (!$row) {
                report(true, 'data uji softcopy', 'dilewati — tidak ada pengajuan completed bertoken');
            } else {
                $url = sprintf(
                    'serve_document.php?ctx=softcopy&req=%s&token=%s&i=0',
                    urlencode($row->id),
                    urlencode($row->verification_token)
                );
                $r = req($BASE . '/' . $url);
                report(
                    $r['code'] === 200 && stripos($r['type'], 'pdf') !== false || stripos($r['type'], 'image') !== false,
                    'softcopy token SAH menyajikan berkas',
                    sprintf('HTTP %d | %s | %d byte', $r['code'], $r['type'], $r['size'])
                );

                $bad = str_replace($row->verification_token, str_repeat('0', 32), $url);
                expect_rejected('softcopy token DIUBAH ditolak', $bad);
            }
        } catch (PDOException $e) {
            report(false, 'kueri database', $e->getMessage());
        }
    }
}

// ─────────────────────────────────────────────────────────
// Ringkasan
// ─────────────────────────────────────────────────────────

printf("\n%s\n", str_repeat('─', 72));
printf("  LULUS: %d    GAGAL: %d\n", $PASS, $FAIL);

if ($FAIL > 0) {
    printf("\n  Yang gagal:\n");
    foreach ($FAILED_LABELS as $l) {
        printf("    - %s\n", $l);
    }
    printf("\n");
    exit(1);
}

printf("\n  Seluruh pemeriksaan lulus.\n\n");
exit(0);
