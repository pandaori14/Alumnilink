<?php
/**
 * Paginasi bersama.
 *
 * ── Mengapa dijadikan satu ─────────────────────────────────────────────
 * Pola "hitung total, ambil satu halaman, gambar pager berjendela" sudah
 * ditulis ulang tiga kali di proyek ini (Database Alumni, Kelola Legalisir,
 * pratinjau Impor), dan masih ada empat halaman lagi yang membutuhkannya.
 *
 * Menyalinnya sekali lagi bukan sekadar pengulangan: di proyek ini
 * normalisasi tracer pernah ditulis dua kali, lalu menyimpang, dan
 * menghasilkan KPI akreditasi yang permanen 0%. Logika yang sama di dua
 * tempat akan selalu berakhir berbeda.
 *
 * Perbedaan yang disengaja terhadap pola lama di pages/admin_logs.php:
 * pager di sana mencetak SETIAP nomor halaman (`for $i = 1; $i <= $total`),
 * yang akan menjadi ribuan tautan begitu tabelnya tumbuh. Di sini jendelanya
 * dibatasi ±2 halaman di sekitar halaman aktif.
 */

require_once __DIR__ . '/settings.php';

/**
 * Ambil satu halaman hasil.
 *
 * @param string $sql_dasar Bagian "FROM ... WHERE ..." tanpa SELECT/ORDER/LIMIT.
 * @param string $kolom     Daftar kolom untuk SELECT baris.
 * @param string $urutan    Isi ORDER BY (tanpa kata ORDER BY).
 * @return array{rows:array,total:int,hal:int,total_hal:int,per:int,offset:int}
 */
function paginate($pdo, $sql_dasar, $kolom, $urutan, array $params = [], $per_bawaan = 20, $param_hal = 'p')
{
    $stmt_n = $pdo->prepare("SELECT COUNT(*) $sql_dasar");
    $stmt_n->execute($params);
    $total = (int)$stmt_n->fetchColumn();

    $per       = pagination_size($per_bawaan);
    $total_hal = max(1, (int)ceil($total / $per));
    // Nomor halaman di luar batas dijepit, bukan menghasilkan halaman kosong
    // atau galat — URL yang dibagikan sering tertinggal zaman.
    $hal       = max(1, min((int)($_GET[$param_hal] ?? 1), $total_hal));
    $offset    = ($hal - 1) * $per;

    // $per dan $offset SELALU berupa integer hasil perhitungan di atas,
    // tidak pernah berasal langsung dari input, sehingga aman diinterpolasi
    // (MySQL tidak menerima placeholder pada LIMIT dalam mode emulasi mati).
    $stmt = $pdo->prepare("SELECT $kolom $sql_dasar ORDER BY $urutan LIMIT " . (int)$per . " OFFSET " . (int)$offset);
    $stmt->execute($params);

    return [
        'rows'      => $stmt->fetchAll(),
        'total'     => $total,
        'hal'       => $hal,
        'total_hal' => $total_hal,
        'per'       => $per,
        'offset'    => $offset,
    ];
}

/**
 * Bangun pembuat URL yang mempertahankan seluruh saringan yang sedang aktif.
 *
 * @param array $tetap Pasangan kunci=>nilai yang ikut dibawa (nilai kosong dibuang).
 */
function pager_url_builder(array $tetap)
{
    $tetap = array_filter($tetap, fn($v) => $v !== '' && $v !== null);
    return fn(array $ubah) => 'index.php?' . http_build_query(array_merge($tetap, $ubah));
}

/**
 * Cetak pager berjendela. Tidak menampilkan apa pun bila hanya satu halaman.
 *
 * @param callable $url  Hasil pager_url_builder().
 * @param string   $gaya 'glass' (halaman admin) atau 'putih' (halaman publik).
 */
function render_pager($url, $hal, $total_hal, $gaya = 'glass')
{
    if ($total_hal <= 1) {
        return;
    }

    $dasar = $gaya === 'glass'
        ? 'glass text-slate-600 hover:bg-white'
        : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50';

    $btn = function ($ke, $label, $aktif = false, $mati = false) use ($url, $dasar) {
        if ($mati) {
            printf('<span class="w-10 h-10 rounded-xl flex items-center justify-center text-slate-300">%s</span>', $label);
            return;
        }
        printf('<a href="%s" class="w-10 h-10 rounded-xl flex items-center justify-center font-bold text-sm transition-all %s">%s</a>',
            htmlspecialchars($url(['p' => $ke])),
            $aktif ? 'bg-blue-600 text-white' : $dasar,
            $label);
    };

    echo '<div class="flex justify-center gap-2 flex-wrap">';
    $btn($hal - 1, '&lsaquo;', false, $hal <= 1);

    $a1 = max(1, $hal - 2);
    $a2 = min($total_hal, $hal + 2);
    if ($a1 > 1) {
        $btn(1, '1');
        if ($a1 > 2) { echo '<span class="w-10 h-10 flex items-center justify-center text-slate-300">&hellip;</span>'; }
    }
    for ($i = $a1; $i <= $a2; $i++) {
        $btn($i, (string)$i, $i === $hal);
    }
    if ($a2 < $total_hal) {
        if ($a2 < $total_hal - 1) { echo '<span class="w-10 h-10 flex items-center justify-center text-slate-300">&hellip;</span>'; }
        $btn($total_hal, (string)$total_hal);
    }

    $btn($hal + 1, '&rsaquo;', false, $hal >= $total_hal);
    echo '</div>';
}

/** Baris "Menampilkan X dari Y" di atas pager. */
function render_pager_summary($ditampilkan, $total, $hal, $total_hal, $satuan = 'baris')
{
    if ($total === 0) {
        return;
    }
    printf('<p class="text-center text-xs text-slate-400 mb-4">Menampilkan %d dari %d %s%s</p>',
        $ditampilkan, $total, htmlspecialchars($satuan),
        $total_hal > 1 ? sprintf(' &middot; halaman %d dari %d', $hal, $total_hal) : '');
}
