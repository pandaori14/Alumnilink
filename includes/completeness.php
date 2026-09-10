<?php
/**
 * Kelengkapan data alumni.
 *
 * Sebelumnya tidak ada satu pun angka yang menunjukkan seberapa banyak data
 * alumni yang bolong. Akibatnya tidak ada yang tahu bahwa sebagian besar
 * baris tidak punya telepon atau alamat, sampai ada yang membutuhkannya
 * untuk broadcast atau pengiriman berkas legalisir dan menemukannya kosong.
 *
 * Bobot dipilih menurut seberapa besar kerugiannya bila kolom itu kosong:
 * NIM adalah penanda resmi lulusan dan dipakai LAM-PTKes; telepon dan alamat
 * menentukan apakah orangnya masih bisa dihubungi sama sekali.
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/import_lib.php';

/** field => [label, bobot]. Bobot berjumlah 100. */
function alumni_completeness_fields()
{
    return [
        'nim'             => ['NIM',         20],
        'graduation_year' => ['Tahun lulus', 15],
        'major'           => ['Prodi',       15],
        'phone'           => ['Telepon',     15],
        'email'           => ['E-mail',      10],
        'address'         => ['Alamat',      10],
        'ipk'             => ['IPK',          5],
        'tracer'          => ['Tracer terkini', 10],
    ];
}

/**
 * Skor 0-100 untuk satu baris alumni.
 *
 * NIM yang terisi tetapi tidak sesuai format ('a', 'Admin Tracer') diberi
 * nilai NOL, bukan nilai penuh: NIM ngawur lebih menyesatkan daripada NIM
 * kosong, karena ia tampak terisi pada setiap laporan.
 */
function alumni_completeness_score($u)
{
    $skor = 0;
    foreach (alumni_completeness_fields() as $f => [$label, $bobot]) {
        if ($f === 'tracer') {
            // Diisi pemanggil lewat properti sintetis; bila tidak tersedia,
            // butir ini tidak dihitung sebagai terpenuhi.
            if (!empty($u->tracer_ok)) { $skor += $bobot; }
            continue;
        }
        if ($f === 'nim') {
            if (!empty($u->nim) && import_nim_is_valid($u->nim)) { $skor += $bobot; }
            continue;
        }
        $v = $u->$f ?? null;
        if ($v !== null && trim((string)$v) !== '') { $skor += $bobot; }
    }
    return $skor;
}

/** Daftar label kolom yang masih kosong pada satu baris. */
function alumni_completeness_missing($u)
{
    $kurang = [];
    foreach (alumni_completeness_fields() as $f => [$label, $bobot]) {
        if ($f === 'tracer') {
            if (empty($u->tracer_ok)) { $kurang[] = $label; }
            continue;
        }
        if ($f === 'nim') {
            if (empty($u->nim) || !import_nim_is_valid($u->nim)) { $kurang[] = $label; }
            continue;
        }
        $v = $u->$f ?? null;
        if ($v === null || trim((string)$v) === '') { $kurang[] = $label; }
    }
    return $kurang;
}

/**
 * Ringkasan seluruh alumni dalam SATU kueri agregat.
 * Dipakai untuk kartu statistik; tidak menarik baris apa pun ke PHP.
 */
function alumni_completeness_summary($pdo)
{
    $sql = "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN nim IS NULL OR nim = '' OR nim NOT REGEXP '^[A-Za-z0-9]{6,20}$' THEN 1 ELSE 0 END) AS tanpa_nim,
        SUM(CASE WHEN graduation_year IS NULL THEN 1 ELSE 0 END) AS tanpa_tahun,
        SUM(CASE WHEN major IS NULL OR major = '' THEN 1 ELSE 0 END) AS tanpa_prodi,
        SUM(CASE WHEN phone IS NULL OR phone = '' THEN 1 ELSE 0 END) AS tanpa_telepon,
        SUM(CASE WHEN address IS NULL OR address = '' THEN 1 ELSE 0 END) AS tanpa_alamat,
        SUM(CASE WHEN ipk IS NULL THEN 1 ELSE 0 END) AS tanpa_ipk,
        SUM(CASE WHEN email IS NULL OR email = '' THEN 1 ELSE 0 END) AS tanpa_email
      FROM users WHERE role = 'alumni'";
    $r = $pdo->query($sql)->fetch();

    $total = (int)($r->total ?? 0);
    if ($total === 0) {
        return ['total' => 0, 'rata' => 0, 'rincian' => []];
    }

    // Rata-rata diturunkan dari hitungan agregat, bukan dari perulangan baris.
    $bobot = alumni_completeness_fields();
    $terisi = 0;
    $peta = [
        'nim'             => 'tanpa_nim',
        'graduation_year' => 'tanpa_tahun',
        'major'           => 'tanpa_prodi',
        'phone'           => 'tanpa_telepon',
        'email'           => 'tanpa_email',
        'address'         => 'tanpa_alamat',
        'ipk'             => 'tanpa_ipk',
    ];
    foreach ($peta as $f => $kolom) {
        $terisi += ($total - (int)$r->$kolom) * $bobot[$f][1];
    }
    // Butir tracer tidak ikut dalam kueri agregat ini (butuh join ke
    // tracer_submissions); rata-rata dihitung atas 90 bobot yang tersisa
    // lalu diskalakan, agar angkanya tidak selalu tampak kurang 10 poin.
    $maks = $total * (100 - $bobot['tracer'][1]);

    return [
        'total'   => $total,
        'rata'    => $maks > 0 ? (int)round($terisi / $maks * 100) : 0,
        'rincian' => [
            ['label' => 'tanpa NIM sah', 'n' => (int)$r->tanpa_nim,     'filter' => 'nim'],
            ['label' => 'tanpa telepon', 'n' => (int)$r->tanpa_telepon, 'filter' => 'phone'],
            ['label' => 'tanpa alamat',  'n' => (int)$r->tanpa_alamat,  'filter' => 'address'],
            ['label' => 'tanpa prodi',   'n' => (int)$r->tanpa_prodi,   'filter' => 'major'],
            ['label' => 'tanpa tahun',   'n' => (int)$r->tanpa_tahun,   'filter' => 'year'],
        ],
    ];
}

/**
 * Potongan SQL untuk menyaring baris yang kolom tertentunya kosong.
 * @return string Kosong bila $kunci tidak dikenali (jadi tidak bisa disuntik).
 */
function alumni_missing_filter_sql($kunci)
{
    $peta = [
        'nim'     => "(u.nim IS NULL OR u.nim = '' OR u.nim NOT REGEXP '^[A-Za-z0-9]{6,20}$')",
        'phone'   => "(u.phone IS NULL OR u.phone = '')",
        'address' => "(u.address IS NULL OR u.address = '')",
        'major'   => "(u.major IS NULL OR u.major = '')",
        'year'    => "(u.graduation_year IS NULL)",
        'email'   => "(u.email IS NULL OR u.email = '')",
    ];
    return $peta[$kunci] ?? '';
}
