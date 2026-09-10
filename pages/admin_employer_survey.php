<?php
/**
 * pages/admin_employer_survey.php — Survei Kepuasan Pengguna Lulusan
 * ─────────────────────────────────────────────────────────
 * Mengelola undangan penilaian kepada atasan alumni dan menampilkan rekapnya
 * bersanding dengan penilaian diri alumni pada aspek yang sama.
 *
 * Daftar calon responden TIDAK diisi manual: kuesioner tracer sudah menanyakan
 * nama, jabatan, dan e-mail atasan langsung, jadi daftar diturunkan dari
 * jawaban yang ada.
 */

$kandidat = employer_survey_candidates($pdo);
$aspek    = tracer_competency_questions($pdo);

// Rekap status undangan
$rekap = $pdo->query(
    "SELECT status, COUNT(*) AS n FROM employer_surveys GROUP BY status"
)->fetchAll();
$statusCount = ['pending' => 0, 'sent' => 0, 'completed' => 0, 'expired' => 0];
foreach ($rekap as $r) {
    $statusCount[$r->status] = (int)$r->n;
}

$terkirim = $pdo->query(
    "SELECT es.*, u.name AS alumni_name, u.nim
     FROM employer_surveys es
     JOIN users u ON es.alumni_user_id = u.id
     ORDER BY es.created_at DESC
     LIMIT 50"
)->fetchAll();

$agregat   = employer_survey_aggregate($pdo);
$adaJawab  = false;
foreach ($agregat as $a) {
    if ($a['total'] > 0) { $adaJawab = true; break; }
}

$valid_count = 0;
foreach ($kandidat as $k) {
    if ($k->email_valid && !$k->sudah_diundang) { $valid_count++; }
}

$msg  = $_GET['success'] ?? '';
$err  = $_GET['error'] ?? '';
?>

<div class="max-w-6xl mx-auto px-4 py-6">

    <div class="mb-8">
        <h1 class="text-xl md:text-2xl font-bold outfit text-slate-800 tracking-tight">Survei Kepuasan Pengguna Lulusan</h1>
        <p class="text-slate-400 text-xs md:text-sm mt-1 font-medium leading-relaxed">
            Penilaian atasan atas kompetensi lulusan — instrumen wajib LAM-PTKes.
            Aspek penilaian mengikuti pertanyaan kompetensi pada tracer, sehingga
            hasilnya dapat disandingkan dengan penilaian diri alumni.
        </p>
    </div>

    <?php if ($msg): ?>
        <div class="mb-6 p-4 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl text-sm font-semibold" role="status">
            <?php echo htmlspecialchars($msg === 'sent' ? 'Undangan survei berhasil dikirim.' : $msg); ?>
        </div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-700 rounded-2xl text-sm font-semibold" role="alert">
            <?php
            $pesanErr = [
                'no_valid'  => 'Tidak ada alamat e-mail atasan yang sah untuk dikirimi undangan.',
                'send_fail' => 'Sebagian undangan gagal dikirim. Periksa pengaturan SMTP.',
            ];
            echo htmlspecialchars($pesanErr[$err] ?? 'Terjadi kesalahan.');
            ?>
        </div>
    <?php endif; ?>

    <!-- Ringkasan -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <?php
        $kartu = [
            ['Calon Responden', count($kandidat), 'dari jawaban tracer', 'users'],
            ['Siap Diundang',   $valid_count,     'e-mail sah, belum diundang', 'mail'],
            ['Sudah Dikirim',   $statusCount['sent'], 'menunggu jawaban', 'send'],
            ['Sudah Menjawab',  $statusCount['completed'], 'penilaian diterima', 'check-circle-2'],
        ];
        foreach ($kartu as $k): ?>
            <div class="glass p-5 rounded-2xl border border-white shadow-sm">
                <div class="flex items-center gap-2 mb-2">
                    <i data-lucide="<?php echo e($k[3]); ?>" class="w-4 h-4 text-slate-400" aria-hidden="true"></i>
                    <p class="text-[10px] font-black uppercase tracking-widest text-slate-400"><?php echo e($k[0]); ?></p>
                </div>
                <p class="text-2xl font-black outfit text-slate-800"><?php echo (int)$k[1]; ?></p>
                <p class="text-[11px] text-slate-400 mt-1"><?php echo e($k[2]); ?></p>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Calon responden -->
    <div class="glass rounded-3xl border border-white shadow-sm overflow-hidden mb-10">
        <div class="p-6 border-b border-white/30 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h2 class="font-bold text-slate-800 outfit">Calon Responden</h2>
                <p class="text-xs text-slate-400 mt-0.5">Diambil dari kontak atasan yang diisi alumni pada tracer.</p>
            </div>
            <?php if ($valid_count > 0): ?>
                <form action="handlers/admin_employer_invite.php" method="POST" onsubmit="return confirmKirim(event, this)">
                    <?php csrf_field(); ?>
                    <button type="submit"
                            class="inline-flex items-center gap-2 brand-bg text-white px-5 py-3 rounded-2xl font-bold text-sm shadow-lg active:scale-95 transition-transform">
                        <i data-lucide="send" class="w-4 h-4" aria-hidden="true"></i>
                        Kirim Undangan (<?php echo e($valid_count); ?>)
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (!$kandidat): ?>
            <div class="p-12 text-center">
                <div class="w-16 h-16 bg-slate-100 rounded-2xl flex items-center justify-center mx-auto mb-4 text-slate-300">
                    <i data-lucide="user-x" class="w-8 h-8" aria-hidden="true"></i>
                </div>
                <p class="font-bold text-slate-500">Belum Ada Calon Responden</p>
                <p class="text-xs text-slate-400 mt-1 max-w-sm mx-auto">
                    Calon muncul setelah alumni mengisi tracer beserta kontak atasan langsungnya.
                </p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="bg-white/30 border-b border-white/20">
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Alumni</th>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Atasan</th>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">E-mail</th>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/20">
                        <?php foreach ($kandidat as $k): ?>
                            <tr class="hover:bg-white/40 transition-colors">
                                <td class="px-6 py-4">
                                    <p class="font-bold text-slate-800"><?php echo htmlspecialchars($k->alumni_name); ?></p>
                                    <p class="text-[11px] text-slate-400"><?php echo htmlspecialchars($k->nim ?: '-'); ?></p>
                                </td>
                                <td class="px-6 py-4">
                                    <p class="text-slate-700"><?php echo htmlspecialchars($k->employer_name ?: '-'); ?></p>
                                    <p class="text-[11px] text-slate-400"><?php echo htmlspecialchars($k->employer_position ?: '-'); ?></p>
                                </td>
                                <td class="px-6 py-4 font-mono text-xs text-slate-500"><?php echo htmlspecialchars($k->employer_email ?: '-'); ?></td>
                                <td class="px-6 py-4">
                                    <?php if ($k->sudah_diundang): ?>
                                        <span class="px-3 py-1 rounded-lg text-[10px] font-black uppercase bg-blue-50 text-blue-600 border border-blue-100">Sudah Diundang</span>
                                    <?php elseif (!$k->email_valid): ?>
                                        <span class="px-3 py-1 rounded-lg text-[10px] font-black uppercase bg-red-50 text-red-600 border border-red-100" title="Alamat e-mail tidak berformat sah">E-mail Tidak Sah</span>
                                    <?php else: ?>
                                        <span class="px-3 py-1 rounded-lg text-[10px] font-black uppercase bg-emerald-50 text-emerald-600 border border-emerald-100">Siap Diundang</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Hasil penilaian -->
    <?php if ($aspek): ?>
    <div class="glass rounded-3xl border border-white shadow-sm p-6 md:p-8">
        <h2 class="font-bold text-slate-800 outfit mb-1">Rekap Penilaian Atasan</h2>
        <p class="text-xs text-slate-400 mb-5">
            <?php echo $adaJawab
                ? 'Persentase per tingkat penguasaan menurut atasan langsung.'
                : 'Belum ada penilaian yang masuk. Tabel akan terisi setelah atasan mengirimkan jawaban.'; ?>
        </p>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm border-collapse">
                <thead>
                    <tr>
                        <th class="border border-slate-200 bg-slate-50 px-3 py-2 text-[10px] font-black text-slate-500 uppercase tracking-widest">Aspek</th>
                        <?php
                        $skala = [];
                        if ($agregat) { $skala = array_keys($agregat[0]['options']); }
                        foreach ($skala as $s): ?>
                            <th class="border border-slate-200 bg-slate-50 px-3 py-2 text-[10px] font-black text-slate-500 uppercase tracking-widest text-right"><?php echo htmlspecialchars($s); ?></th>
                        <?php endforeach; ?>
                        <th class="border border-slate-200 bg-slate-50 px-3 py-2 text-[10px] font-black text-slate-500 uppercase tracking-widest text-right">Responden</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agregat as $a): $tot = $a['total']; ?>
                        <tr>
                            <td class="border border-slate-200 px-3 py-2 font-semibold text-slate-700"><?php echo htmlspecialchars($a['label']); ?></td>
                            <?php foreach ($skala as $s):
                                $n = $a['options'][$s] ?? 0;
                                $p = $tot > 0 ? round($n / $tot * 100) : 0; ?>
                                <td class="border border-slate-200 px-3 py-2 text-right"><?php echo e($p); ?>%<span class="text-slate-400 text-[11px]"> (<?php echo e($n); ?>)</span></td>
                            <?php endforeach; ?>
                            <td class="border border-slate-200 px-3 py-2 text-right font-bold"><?php echo e($tot); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Riwayat undangan -->
    <?php if ($terkirim): ?>
    <div class="glass rounded-3xl border border-white shadow-sm overflow-hidden mt-10">
        <div class="p-6 border-b border-white/30">
            <h2 class="font-bold text-slate-800 outfit">Riwayat Undangan</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="bg-white/30 border-b border-white/20">
                        <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Alumni</th>
                        <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Dikirim ke</th>
                        <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                        <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Berlaku s/d</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/20">
                    <?php foreach ($terkirim as $t):
                        $warna = [
                            'pending'   => 'bg-slate-100 text-slate-500 border-slate-200',
                            'sent'      => 'bg-blue-50 text-blue-600 border-blue-100',
                            'completed' => 'bg-emerald-50 text-emerald-600 border-emerald-100',
                            'expired'   => 'bg-red-50 text-red-600 border-red-100',
                        ][$t->status] ?? 'bg-slate-100 text-slate-500 border-slate-200'; ?>
                        <tr>
                            <td class="px-6 py-4 font-semibold text-slate-700"><?php echo htmlspecialchars($t->alumni_name); ?></td>
                            <td class="px-6 py-4 font-mono text-xs text-slate-500"><?php echo htmlspecialchars($t->employer_email); ?></td>
                            <td class="px-6 py-4"><span class="px-3 py-1 rounded-lg text-[10px] font-black uppercase border <?php echo e($warna); ?>"><?php echo htmlspecialchars($t->status); ?></span></td>
                            <td class="px-6 py-4 text-xs text-slate-500"><?php echo $t->expires_at ? date('d M Y', strtotime($t->expires_at)) : '-'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
    function confirmKirim(e, form) {
        e.preventDefault();
        swalConfirm(
            'Kirim Undangan Survei?',
            'Undangan akan dikirim ke alamat e-mail atasan alumni. Pastikan pengaturan SMTP sudah benar.',
            () => form.submit(),
            'Ya, Kirim'
        );
        return false;
    }
    if (window.lucide) lucide.createIcons();
</script>
