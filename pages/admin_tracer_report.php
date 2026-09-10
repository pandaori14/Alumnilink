<?php
/**
 * pages/admin_tracer_report.php — Laporan Tracer Study untuk Akreditasi
 * ─────────────────────────────────────────────────────────
 * Menyajikan indikator yang diminta BAN-PT / LAM-PTKes dari data yang SUDAH
 * terkumpul namun sebelumnya tidak dapat diagregasi.
 *
 * Sebelumnya hanya 5 pertanyaan ber-mapping_key yang bisa dilaporkan. Halaman
 * ini memakai agregasi generik di includes/tracer_lib.php sehingga seluruh
 * pertanyaan tertutup ikut terbaca — termasuk masa tunggu kerja dan matriks
 * kompetensi 8 aspek.
 *
 * Sengaja TANPA pustaka PDF. Deployment lewat FTP dan belum ada vendor;
 * menambah PhpSpreadsheet/TCPDF berarti puluhan MB berkas. Halaman ini
 * dioptimalkan untuk cetak lalu disimpan sebagai PDF lewat peramban — pola
 * yang sudah dipakai view_softcopy.php dan cetak_label.php.
 */

$filters = [
    'graduation_year' => $_GET['graduation_year'] ?? null,
    'major'           => $_GET['major'] ?? null,
];

$counts   = tracer_response_counts($pdo, $filters);
$expected = tracer_expected_respondents($pdo, $filters);
$rate     = $expected > 0 ? round($counts['responden'] / $expected * 100) : 0;

$questions = tracer_reportable_questions($pdo);

// Pisahkan blok kompetensi dari pertanyaan lain. Deteksi berdasarkan teks
// supaya tidak bergantung pada id yang bisa berbeda antar instalasi.
$kompetensi = [];
$umum       = [];
foreach ($questions as $q) {
    if (stripos($q->question_text, 'kompetensi') !== false) {
        $kompetensi[] = $q;
    } else {
        $umum[] = $q;
    }
}

/** Ambil label ringkas aspek kompetensi dari teks pertanyaan yang panjang. */
function aspek_kompetensi($teks)
{
    if (preg_match('/terkait\s+(.+?)\s*\?*\s*$/iu', $teks, $m)) {
        return ucfirst(trim($m[1]));
    }
    return $teks;
}

$tahun_opsi = $pdo->query(
    "SELECT DISTINCT graduation_year FROM users
     WHERE role='alumni' AND graduation_year IS NOT NULL
     ORDER BY graduation_year DESC"
)->fetchAll();
$prodi_opsi = $pdo->query("SELECT major_code, major_name FROM majors ORDER BY major_name ASC")->fetchAll();

// Tingkat respons per angkatan — indikator inti BAN-PT.
$per_angkatan = [];
foreach ($tahun_opsi as $t) {
    $f = ['graduation_year' => $t->graduation_year];
    if (!empty($filters['major'])) {
        $f['major'] = $filters['major'];
    }
    $c = tracer_response_counts($pdo, $f);
    $e = tracer_expected_respondents($pdo, $f);
    $per_angkatan[] = [
        'tahun'     => (int)$t->graduation_year,
        'responden' => $c['responden'],
        'alumni'    => $e,
        'rate'      => $e > 0 ? round($c['responden'] / $e * 100) : 0,
    ];
}

$nama_institusi = setting('system_name', 'AlumniLink');
$logo           = setting('system_logo', '');
?>

<style>
    @media print {
        .no-print, aside, nav { display: none !important; }
        .print-full { max-width: 100% !important; padding: 0 !important; }
        .print-break { page-break-inside: avoid; }
        body { background: #fff !important; }
    }
    .rpt-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .rpt-table th, .rpt-table td { border: 1px solid #e2e8f0; padding: 8px 10px; text-align: left; }
    .rpt-table th { background: #f8fafc; font-weight: 800; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #64748b; }
    .rpt-table td.num, .rpt-table th.num { text-align: right; white-space: nowrap; }
</style>

<div class="max-w-5xl mx-auto px-4 py-6 print-full">

    <div class="flex items-start gap-4 mb-8 pb-6 border-b-2 border-slate-200 print-break">
        <?php if ($logo): ?>
            <img src="<?php echo htmlspecialchars($logo); ?>" alt="" class="w-16 h-16 object-contain shrink-0">
        <?php endif; ?>
        <div class="flex-1">
            <h1 class="text-2xl font-black outfit text-slate-800 tracking-tight">Laporan Tracer Study</h1>
            <p class="text-sm font-bold text-slate-500 mt-0.5"><?php echo htmlspecialchars($nama_institusi); ?></p>
            <p class="text-xs text-slate-400 mt-1">
                Dicetak <?php echo date('d F Y, H:i'); ?> WIB
                <?php if (!empty($filters['graduation_year'])): ?>
                    &middot; Angkatan <?php echo (int)$filters['graduation_year']; ?>
                <?php endif; ?>
                <?php if (!empty($filters['major'])): ?>
                    &middot; Prodi <?php echo htmlspecialchars($filters['major']); ?>
                <?php endif; ?>
            </p>
        </div>
        <button type="button" onclick="window.print()"
                class="no-print shrink-0 inline-flex items-center gap-2 px-5 py-3 brand-bg text-white rounded-2xl font-bold text-sm shadow-lg active:scale-95 transition-transform">
            <i data-lucide="printer" class="w-4 h-4" aria-hidden="true"></i>
            Cetak / Simpan PDF
        </button>
    </div>

    <form method="GET" class="no-print glass p-5 rounded-3xl border border-white shadow-sm mb-8 flex flex-col md:flex-row md:items-end gap-4">
        <input type="hidden" name="page" value="admin_tracer_report">
        <div class="flex-1 min-w-[160px]">
            <label for="r-year" class="block text-[11px] font-black uppercase tracking-widest text-slate-400 mb-2">Angkatan</label>
            <select id="r-year" name="graduation_year" class="w-full px-4 py-3 rounded-2xl bg-white border border-slate-200 focus:border-blue-500 outline-none text-sm font-semibold">
                <option value="">Semua Angkatan</option>
                <?php foreach ($tahun_opsi as $t): ?>
                    <option value="<?php echo e((int)$t->graduation_year); ?>" <?php echo e((string)$filters['graduation_year'] === (string)$t->graduation_year ? 'selected' : ''); ?>>
                        <?php echo (int)$t->graduation_year; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex-1 min-w-[200px]">
            <label for="r-major" class="block text-[11px] font-black uppercase tracking-widest text-slate-400 mb-2">Program Studi</label>
            <select id="r-major" name="major" class="w-full px-4 py-3 rounded-2xl bg-white border border-slate-200 focus:border-blue-500 outline-none text-sm font-semibold">
                <option value="">Semua Prodi</option>
                <?php foreach ($prodi_opsi as $m): ?>
                    <option value="<?php echo htmlspecialchars($m->major_code); ?>" <?php echo e($filters['major'] === $m->major_code ? 'selected' : ''); ?>>
                        <?php echo htmlspecialchars($m->major_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="px-6 py-3 brand-bg text-white rounded-2xl font-bold text-sm active:scale-95 transition-transform">Terapkan</button>
        <a href="index.php?page=admin_tracer_report" class="px-5 py-3 rounded-2xl bg-white border border-slate-200 text-slate-600 font-bold text-sm hover:bg-slate-50 transition-all">Reset</a>
    </form>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-10 print-break">
        <?php
        $ringkas = [
            ['Responden Unik',  number_format($counts['responden'], 0, ',', '.'), 'alumni'],
            ['Total Pengisian', number_format($counts['submission'], 0, ',', '.'), 'termasuk pengisian ulang'],
            ['Alumni Sasaran',  number_format($expected, 0, ',', '.'), 'terverifikasi'],
            ['Tingkat Respons', $rate . '%', 'responden / sasaran'],
        ];
        foreach ($ringkas as $r): ?>
            <div class="bg-white border border-slate-200 rounded-2xl p-5">
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2"><?php echo e($r[0]); ?></p>
                <p class="text-2xl font-black outfit text-slate-800"><?php echo e($r[1]); ?></p>
                <p class="text-[11px] text-slate-400 mt-1"><?php echo e($r[2]); ?></p>
            </div>
        <?php endforeach; ?>
    </div>

    <section class="mb-10 print-break">
        <h2 class="text-lg font-black outfit text-slate-800 mb-3">1. Tingkat Respons per Angkatan</h2>
        <table class="rpt-table">
            <thead>
                <tr>
                    <th>Angkatan</th>
                    <th class="num">Alumni Sasaran</th>
                    <th class="num">Responden</th>
                    <th class="num">Tingkat Respons</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$per_angkatan): ?>
                    <tr><td colspan="4" class="text-slate-400">Belum ada data angkatan.</td></tr>
                <?php else: foreach ($per_angkatan as $b): ?>
                    <tr>
                        <td class="font-bold"><?php echo e($b['tahun']); ?></td>
                        <td class="num"><?php echo e($b['alumni']); ?></td>
                        <td class="num"><?php echo e($b['responden']); ?></td>
                        <td class="num font-bold"><?php echo e($b['rate']); ?>%</td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </section>

    <?php if ($kompetensi): ?>
    <section class="mb-10 print-break">
        <h2 class="text-lg font-black outfit text-slate-800 mb-1">2. Penilaian Diri Kompetensi Lulusan</h2>
        <p class="text-xs text-slate-500 mb-3">Persentase per tingkat penguasaan, dinilai sendiri oleh alumni saat lulus.</p>
        <?php
        $agg0  = tracer_aggregate_question($pdo, $kompetensi[0]->id, $filters);
        $skala = $agg0 ? array_keys($agg0['options']) : [];
        ?>
        <table class="rpt-table">
            <thead>
                <tr>
                    <th>Aspek Kompetensi</th>
                    <?php foreach ($skala as $s): ?>
                        <th class="num"><?php echo htmlspecialchars($s); ?></th>
                    <?php endforeach; ?>
                    <th class="num">Responden</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($kompetensi as $q):
                    $a = tracer_aggregate_question($pdo, $q->id, $filters);
                    if (!$a) { continue; }
                    $tot = $a['total']; ?>
                    <tr>
                        <td class="font-semibold"><?php echo htmlspecialchars(aspek_kompetensi($q->question_text)); ?></td>
                        <?php foreach ($skala as $s):
                            $n = $a['options'][$s] ?? 0;
                            $p = $tot > 0 ? round($n / $tot * 100) : 0; ?>
                            <td class="num"><?php echo e($p); ?>%<span class="text-slate-400 text-[11px]"> (<?php echo e($n); ?>)</span></td>
                        <?php endforeach; ?>
                        <td class="num font-bold"><?php echo e($tot); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <?php
    // Perbandingan penilaian diri alumni dengan penilaian atasan.
    // Keduanya memakai aspek dan skala yang sama karena modul survei atasan
    // mengambil aspeknya langsung dari pertanyaan kompetensi tracer.
    $penilaian_atasan = employer_survey_aggregate($pdo);
    $ada_atasan = false;
    foreach ($penilaian_atasan as $pa) {
        if ($pa['total'] > 0) { $ada_atasan = true; break; }
    }
    ?>
    <?php if ($ada_atasan): ?>
    <section class="mb-10 print-break">
        <h2 class="text-lg font-black outfit text-slate-800 mb-1">3. Perbandingan Penilaian Diri dan Penilaian Atasan</h2>
        <p class="text-xs text-slate-500 mb-3">
            Persentase responden yang menilai penguasaan pada tingkat tertinggi
            (opsi pertama pada skala). Selisih mencolok menandakan kesenjangan
            antara persepsi lulusan dan penilaian dunia kerja.
        </p>
        <table class="rpt-table">
            <thead>
                <tr>
                    <th>Aspek Kompetensi</th>
                    <th class="num">Penilaian Diri Alumni</th>
                    <th class="num">Penilaian Atasan</th>
                    <th class="num">Selisih</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($penilaian_atasan as $pa):
                    $qid = $pa['question']->id;

                    $diri = tracer_aggregate_question($pdo, $qid, $filters);
                    $skalaTeratas = array_key_first($pa['options']);

                    $pDiri = ($diri && $diri['total'] > 0 && $skalaTeratas !== null)
                        ? round(($diri['options'][$skalaTeratas] ?? 0) / $diri['total'] * 100) : null;
                    $pAtasan = $pa['total'] > 0
                        ? round(($pa['options'][$skalaTeratas] ?? 0) / $pa['total'] * 100) : null;

                    $selisih = ($pDiri !== null && $pAtasan !== null) ? $pAtasan - $pDiri : null;
                ?>
                    <tr>
                        <td class="font-semibold"><?php echo htmlspecialchars($pa['label']); ?></td>
                        <td class="num"><?php echo $pDiri === null ? '-' : $pDiri . '%'; ?></td>
                        <td class="num"><?php echo $pAtasan === null ? '-' : $pAtasan . '%'; ?></td>
                        <td class="num font-bold" style="color: <?php echo $selisih === null ? '#94a3b8' : ($selisih < 0 ? '#dc2626' : '#059669'); ?>">
                            <?php echo $selisih === null ? '-' : ($selisih > 0 ? '+' : '') . $selisih; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-[11px] text-slate-400 mt-2">
            Nilai negatif berarti atasan menilai lebih rendah daripada penilaian diri alumni.
        </p>
    </section>
    <?php endif; ?>

    <section class="print-break">
        <h2 class="text-lg font-black outfit text-slate-800 mb-3"><?php echo $ada_atasan ? '4' : ($kompetensi ? '3' : '2'); ?>. Distribusi Jawaban</h2>
        <?php foreach ($umum as $q):
            $a = tracer_aggregate_question($pdo, $q->id, $filters);
            if (!$a || $a['total'] === 0) { continue; }
            $tot = $a['total']; ?>
            <div class="mb-7 print-break">
                <h3 class="text-sm font-bold text-slate-700 mb-2"><?php echo htmlspecialchars($q->question_text); ?></h3>
                <table class="rpt-table">
                    <thead>
                        <tr><th>Jawaban</th><th class="num">Jumlah</th><th class="num">Persentase</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($a['options'] as $opsi => $n): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($opsi); ?></td>
                                <td class="num"><?php echo e($n); ?></td>
                                <td class="num font-bold"><?php echo $tot > 0 ? round($n / $tot * 100) : 0; ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="bg-slate-50">
                            <td class="font-bold">Total menjawab</td>
                            <td class="num font-bold"><?php echo e($tot); ?></td>
                            <td class="num">100%</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    </section>

    <p class="text-[11px] text-slate-400 mt-10 pt-4 border-t border-slate-200">
        Metrik dihitung atas pengisian terbaru tiap alumni, sehingga pengisian berulang
        tidak terhitung lebih dari sekali. Pertanyaan berjawaban bebas tidak diagregasi
        dan hanya tersedia pada ekspor CSV.
    </p>
</div>

<script>if (window.lucide) lucide.createIcons();</script>
