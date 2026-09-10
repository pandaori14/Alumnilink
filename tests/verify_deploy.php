<?php
/**
 * Verifikasi deployment — aman dijalankan terhadap PRODUKSI.
 *
 *     php tests/verify_deploy.php https://apps-kedokteran.ums.ac.id/alumnilink
 *     php tests/verify_deploy.php http://localhost/alumnilink
 *
 * ── Mengapa ini terpisah dari run_all.php ──────────────────────────────
 * run_all.php MENULIS ke basis data — membuat pengguna, pengajuan legalisir,
 * dan berita, lalu menghapusnya. Karena itu ia menolak menyentuh alamat
 * bukan-lokal.
 *
 * Berkas ini hanya melakukan GET. Tidak menulis apa pun, tidak butuh sesi,
 * tidak butuh kredensial. Justru itulah gunanya: ia menjawab pertanyaan yang
 * selama ini tidak bisa dijawab siapa pun setelah upload lewat FTP —
 * "apakah yang saya unggah benar-benar sampai, dan apakah penjagaannya
 * bekerja?"
 *
 * ── Cara memakainya yang benar ─────────────────────────────────────────
 * Jalankan DUA KALI: sebelum upload dan sesudahnya.
 *
 * Sebelum upload ia HARUS gagal di beberapa butir. Kegagalan itulah garis
 * dasarnya. Skrip verifikasi yang hijau baik sebelum maupun sesudah
 * deployment tidak memverifikasi apa pun.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("CLI only.\n");
}

$base = $argv[1] ?? (getenv('ALUMNILINK_URL') ?: 'http://localhost/alumnilink');
$base = rtrim($base, '/');

$pass = 0;
$fail = 0;
$catatan = [];

function lapor($ok, $label, $detail = '', $saran = '')
{
    global $pass, $fail, $catatan;
    if ($ok) {
        $pass++;
        printf("  LULUS  %-48s %s\n", $label, $detail);
    } else {
        $fail++;
        printf("  GAGAL  %-48s %s\n", $label, $detail);
        if ($saran !== '') {
            $catatan[] = "$label -> $saran";
        }
    }
}

/** GET biasa. Tidak pernah mengirim POST, cookie, atau kredensial. */
function ambil($url, $range = null)
{
    $ch = curl_init($url);
    $o = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => true,
        CURLOPT_USERAGENT      => 'AlumniLink-verify-deploy',
    ];
    if ($range !== null) { $o[CURLOPT_RANGE] = $range; }
    curl_setopt_array($ch, $o);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $pisah = strpos((string)$raw, "\r\n\r\n");
    return [
        'code'  => $code,
        'head'  => $pisah !== false ? substr($raw, 0, $pisah) : '',
        'body'  => $pisah !== false ? substr($raw, $pisah + 4) : (string)$raw,
        'error' => $err,
    ];
}

echo "\n";
echo "  Verifikasi deployment AlumniLink\n";
echo "  Target: $base\n";
echo "  " . str_repeat('─', 62) . "\n\n";

// ═══ 1. Situs hidup ═══════════════════════════════════════════════════
// Dijalankan PALING DULU dan menghentikan sisanya bila gagal. Blok
// Options -Indexes di .htaccess menuntut AllowOverride Options; pada
// hosting yang membatasinya, mengunggah .htaccess memicu HTTP 500 pada
// SETIAP permintaan. Bila itu terjadi, tidak ada gunanya memeriksa yang lain.
echo "  1. Situs hidup\n";
$r = ambil("$base/index.php?page=landing");

if ($r['error'] !== '') {
    lapor(false, 'halaman depan dapat dihubungi', $r['error']);
    echo "\n  Tidak dapat menghubungi server. Verifikasi dihentikan.\n\n";
    exit(2);
}

lapor($r['code'] === 200, 'halaman depan HTTP 200', 'HTTP ' . $r['code'],
    $r['code'] === 500
        ? 'HTTP 500 sesaat setelah upload .htaccess biasanya berarti hosting '
          . 'tidak mengizinkan AllowOverride Options. Hapus blok '
          . '<IfModule mod_autoindex.c> dari .htaccess.'
        : '');

if ($r['code'] === 500) {
    echo "\n  Situs mengembalikan 500. Perbaiki itu lebih dulu; sisanya tidak berarti.\n\n";
    exit(1);
}

// ═══ 2. Kode baru benar-benar sampai ═════════════════════════════════
echo "\n  2. Kode baru sampai\n";

$r = ambil("$base/avatar.php?name=Uji&size=32");
$svg = $r['code'] === 200 && strpos($r['body'], '<svg') !== false;
lapor($svg, 'avatar.php melayani SVG', 'HTTP ' . $r['code'],
    'avatar.php belum ada di server — unggah berkas akar.');

// Berkas ini dimuat global oleh config/db.php. Bila config/db.php sudah naik
// tetapi settings.php belum, SETIAP permintaan akan fatal.
$r = ambil("$base/index.php?page=login");
$fatal = stripos($r['body'], 'Fatal error') !== false
      || stripos($r['body'], 'undefined function') !== false;
lapor(!$fatal, 'tidak ada fatal error di halaman masuk',
    $fatal ? '*** ada ***' : 'bersih',
    'Biasanya berarti upload separuh jalan: config/db.php sudah naik tetapi '
    . 'pustaka di includes/ belum. Selesaikan langkah 1 di UPLOAD.md.');

// ═══ 3. Berkas program tidak dapat dibuka langsung ═══════════════════
echo "\n  3. Berkas program tertutup\n";
foreach ([
    'pages/alumni_map.php'  => 'pages/ (halaman)',
    'pages/admin_users.php' => 'pages/ (halaman admin)',
    'includes/header.php'   => 'includes/ (pustaka)',
] as $path => $label) {
    $r = ambil("$base/$path");
    // 403 = diblokir; 404 = tidak ada. Yang salah adalah 200 (dirender)
    // dan 302 (dialihkan ke index.php, artinya aturan blokir belum ada).
    $ok = in_array($r['code'], [403, 404], true);
    lapor($ok, $label, 'HTTP ' . $r['code'],
        'Aturan ^(pages|includes)/ di .htaccess belum aktif. Unggah .htaccess.');
}

// ═══ 4. Berkas sensitif tertutup ═════════════════════════════════════
echo "\n  4. Berkas sensitif tertutup\n";
foreach ([
    'alumnilink.sql'      => 'dump basis data (.sql)',
    'schema.sql'          => 'skema (.sql)',
    'logs/mail_debug.log' => 'log e-mail (.log)',
    '.env'                => 'kredensial (.env)',
    'backups/'            => 'folder cadangan',
    'tests/smoke.php'     => 'folder uji',
    '_dev/build/input.css' => 'folder pengembangan',
] as $path => $label) {
    $r = ambil("$base/$path");
    $ok = in_array($r['code'], [403, 404], true);
    $detail = 'HTTP ' . $r['code'];
    $saran  = '';

    if ($r['code'] === 200) {
        // Angka membuat akibatnya nyata; "dapat diunduh" saja terlalu abstrak.
        if (preg_match('/Content-Length:\s*(\d+)/i', $r['head'], $m)) {
            $detail .= ' — ' . number_format((int)$m[1] / 1024, 1) . ' KB DAPAT DIUNDUH';
        } else {
            $detail .= ' — DAPAT DIUNDUH';
        }
        $saran = 'Dapat diunduh siapa pun sekarang juga. Unggah .htaccess, '
               . 'lalu hapus berkasnya dari server bila memang tidak dipakai.';
    } elseif ($r['code'] === 302) {
        // 302 berarti berkasnya TIDAK ADA dan permintaan dialihkan ke
        // index.php — jadi tidak ada yang bocor. Tetapi aturan pemblokirnya
        // juga belum ada: begitu berkas bernama itu muncul, ia langsung
        // terbuka. Dibedakan dari 200 supaya laporannya jujur; menyebut
        // keduanya "dapat diunduh" akan membuat orang berhenti mempercayai
        // alat ini.
        $detail .= ' (tidak ada di server — belum bocor, tetapi belum diblokir)';
        $saran = 'Belum ada yang bocor. Unggah .htaccess supaya polanya '
               . 'tertutup sebelum berkas semacam itu suatu saat muncul.';
    }

    lapor($ok, $label, $detail, $saran);
}

// ═══ 5. Privasi: alamat rumah alumni ═════════════════════════════════
echo "\n  5. Privasi peta\n";
$r = ambil("$base/api/alumni/geodistribution.php");
lapor(in_array($r['code'], [401, 403], true), 'endpoint peta menolak tanpa sesi',
    'HTTP ' . $r['code']);
lapor(strpos($r['body'], '"address"') === false, 'nol kunci address di balasan',
    strpos($r['body'], '"address"') === false ? 'bersih' : '*** ALAMAT TERKIRIM ***');
lapor(stripos($r['body'], 'SQLSTATE') === false && stripos($r['body'], 'SELECT ') === false,
    'balasan galat tidak memuat detail SQL');

// ═══ 6. Cron berkunci ════════════════════════════════════════════════
//
// DUA hal yang mudah salah di bagian ini, keduanya sudah pernah salah:
//
// 1. Probe tidak boleh punya efek samping. Versi pertama memakai pemicu
//    SUNGGUHAN (`?run=1`, dan token lama yang tertulis di kode). Pada server
//    yang belum diperbarui keduanya benar-benar MENJALANKAN skripnya:
//    geocoder mengirim alamat rumah alumni ke Nominatim, pengingat mengirim
//    e-mail ke alumni sungguhan. Alat yang mengaku aman terhadap produksi
//    tidak boleh begitu. Sekarang dipakai token yang PASTI SALAH.
//
// 2. Kode status saja TIDAK cukup untuk menyimpulkan. Penjagaan versi lama
//    memakai die() polos tanpa http_response_code(), sehingga PENOLAKAN pun
//    mengembalikan HTTP 200. Menilai dari statusnya saja membuat penolakan
//    dilaporkan sebagai "berjalan tanpa token" — persis kesalahan yang
//    sempat dicetak berkas ini.
//
// Karena itu keputusannya diambil dari ISI balasan: apakah skripnya
// benar-benar berjalan, atau menolak.
$token_palsu = 'verifikasi-sengaja-salah-' . bin2hex(random_bytes(4));

/** Kalimat yang hanya muncul bila skripnya BENAR-BENAR berjalan. */
$tanda_jalan = [
    'cron/geocoder.php'            => ['Starting Geocoder', 'Geocoding:', 'No pending addresses'],
    'cron/process_email_queue.php' => ['Email queue worker started', 'No pending emails'],
    'cron/tracer_reminder.php'     => ['[START]', 'Reminder Cron Job'],
    'cron/backup.php'              => ['Selesai:', 'Menghapus', 'GAGAL: folder'],
];

echo "\n  6. Pemicu cron berkunci\n";
foreach ([
    'cron/geocoder.php'            => 'geocoder',
    'cron/process_email_queue.php' => 'antrean e-mail',
    'cron/tracer_reminder.php'     => 'pengingat tracer',
    'cron/backup.php'              => 'cadangan',
] as $path => $label) {
    $r = ambil("$base/$path?token=" . urlencode($token_palsu));

    $berjalan = false;
    foreach ($tanda_jalan[$path] as $tanda) {
        if (stripos($r['body'], $tanda) !== false) { $berjalan = true; break; }
    }

    $ada = $r['code'] !== 302 && $r['code'] !== 404;

    if (!$ada) {
        // Berkasnya belum ada di server. Bukan lubang, tetapi kode belum
        // lengkap — dan pekerjaan terjadwalnya belum bisa dijalankan.
        lapor(false, $label, 'HTTP ' . $r['code'] . ' (berkas belum ada di server)',
            'Unggah folder cron/ beserta includes/cron_auth.php.');
        continue;
    }

    if ($berjalan) {
        lapor(false, $label, 'HTTP ' . $r['code'] . ' *** BERJALAN meski token ngawur ***',
            'Skrip ini dapat dipicu siapa pun dari internet. Unggah cron/ '
            . 'beserta includes/cron_auth.php.');
        continue;
    }

    // Menolak. Sempurna bila 403; bila 200 berarti penjagaannya bekerja
    // tetapi memakai die() polos tanpa kode status yang benar.
    if ($r['code'] === 403) {
        lapor(true, $label, 'HTTP 403 — ditolak');
    } else {
        lapor(true, $label, 'HTTP ' . $r['code'] . ' — menolak, tetapi tanpa status 403');
        echo "         (penjagaan versi lama memakai die() polos; versi baru\n";
        echo "          mengembalikan 403 sebagaimana mestinya)\n";
    }
}

// Satu hal yang TIDAK dapat diperiksa tanpa efek samping, jadi disampaikan
// sebagai pengingat alih-alih sebagai pemeriksaan: pemicu lama `?run=1` pada
// geocoder, dan token yang dulu tertulis di dalam kode, keduanya masih
// berlaku pada server yang belum diperbarui — dan keduanya kini terbaca di
// repositori publik. Memeriksanya berarti menjalankannya, jadi tidak
// diperiksa. Keduanya berhenti berlaku begitu includes/cron_auth.php naik.
echo "         catatan: pemicu lama (?run=1 dan token yang tertulis di kode)\n";
echo "                  kini terbaca publik di GitHub. Tidak diperiksa di sini\n";
echo "                  karena memeriksanya berarti menjalankannya. Keduanya\n";
echo "                  mati begitu includes/cron_auth.php naik ke server.\n";

// ═══ 7. Galat tidak bocor ke pengunjung ══════════════════════════════
echo "\n  7. Galat tidak bocor\n";
$bocor = [];
foreach (['index.php?page=landing', 'index.php?page=login', 'index.php?page=all_news'] as $p) {
    $r = ambil("$base/$p");
    if (preg_match('/\b(Warning|Notice|Fatal error|Parse error|Deprecated):/', $r['body'])) {
        $bocor[] = "$p (pesan galat)";
    }
    if (preg_match('#(/var/www/|/home/[a-z0-9_]+/|C:\\\\xampp\\\\)#i', $r['body'])) {
        $bocor[] = "$p (jalur server)";
    }
}
lapor(empty($bocor), 'nol galat/jalur server di halaman publik',
    $bocor ? implode(', ', array_unique($bocor)) : 'bersih',
    'Unggah config/db.php — ia menyetel display_errors=0 saat APP_ENV bukan local. '
    . 'Pastikan juga .env di server memuat APP_ENV="production".');

// ═══ 8. Aset ═════════════════════════════════════════════════════════
echo "\n  8. Aset\n";
$r = ambil("$base/assets/css/app.css");
lapor($r['code'] === 200, 'assets/css/app.css tersedia', 'HTTP ' . $r['code']);

$r = ambil("$base/index.php?page=landing");
$cdn = preg_match_all('#(src|href)="https?://(cdn|unpkg|cdnjs|ajax|fonts\.googleapis|ui-avatars)#i', $r['body']);
lapor($cdn === 0, 'nol aset dari CDN / ui-avatars',
    $cdn === 0 ? 'bersih' : "$cdn rujukan",
    'Halaman masih memuat aset pihak ketiga. ui-avatars mengirimkan NAMA ASLI '
    . 'alumni ke server luar pada setiap pemuatan halaman.');

// ═══ Ringkasan ═══════════════════════════════════════════════════════
echo "\n  " . str_repeat('─', 62) . "\n";
printf("  %d lulus · %d gagal\n", $pass, $fail);

if ($catatan) {
    echo "\n  Yang perlu dikerjakan:\n";
    foreach (array_unique($catatan) as $i => $c) {
        echo "\n  " . ($i + 1) . ". " . wordwrap($c, 66, "\n     ") . "\n";
    }
}

if ($fail === 0) {
    echo "\n  Deployment terverifikasi.\n\n";
    exit(0);
}

echo "\n";
exit(1);
