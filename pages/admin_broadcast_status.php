<?php
/**
 * Kemajuan Broadcast.
 *
 * ── Mengapa halaman ini ada ────────────────────────────────────────────
 * Sebelumnya broadcast adalah tombol yang ditekan lalu senyap. Tabel
 * `broadcasts` menyimpan recipient_count, tetapi angka itu tidak pernah
 * dibandingkan dengan kenyataan — jadi tidak ada satu pun layar yang bisa
 * menjawab "sudah berapa yang terkirim?".
 *
 * Pada volume kecil itu hanya mengganggu. Pada volume yang dijanjikan
 * proposal — puluhan ribu alumni, yang memakan berhari-hari karena batas
 * penyedia SMTP — senyap selama berhari-hari tidak dapat dibedakan dari
 * rusak. Itulah yang membuat orang menekan kirim untuk kedua kalinya.
 */

if (!role_can_open_page('admin_broadcast_status', $_SESSION['user_role'] ?? '')) {
    deny_access(403, 'Halaman ini hanya untuk pengelola broadcast.');
}

// ── Daftar broadcast beserta kemajuan nyatanya ───────────────────────
// Kemajuan dihitung dari email_queue, bukan dari recipient_count yang
// dicatat saat pengiriman dimulai: yang pertama adalah kenyataan, yang
// kedua hanya niat.
$hal_bc = paginate($pdo, ' FROM broadcasts', '*', 'created_at DESC', [], 15);
$daftar = $hal_bc['rows'];
$bc_url = pager_url_builder(['page' => 'admin_broadcast_status']);

// Satu kueri agregat untuk seluruh halaman, bukan satu kueri per baris.
$kemajuan = [];
if ($daftar) {
    $ids = array_map(fn($b) => (int)$b->id, $daftar);
    $isi = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare(
        "SELECT broadcast_id, status, COUNT(*) n
         FROM email_queue WHERE broadcast_id IN ($isi)
         GROUP BY broadcast_id, status"
    );
    $q->execute($ids);
    foreach ($q as $r) {
        $kemajuan[(int)$r->broadcast_id][$r->status] = (int)$r->n;
    }
}

// ── Alamat yang berhenti dikirimi ────────────────────────────────────
// Dua pager di satu halaman: yang kedua memakai kunci 'pu' agar keduanya
// tidak bergerak bersamaan. paginate() sudah menerima nama kunci; tautannya
// dibangun manual karena render_pager() selalu menulis 'p'.
$hal_unsub = paginate($pdo, " FROM unsubscribes", '*', 'created_at DESC', [], 10, 'pu');
$unsub     = $hal_unsub['rows'];

$batas_harian = setting_int('email_daily_limit', 2000, 1);
$batch        = setting_int('email_batch_size', 20, 1);
$jeda_ms      = setting_int('email_throttle_ms', 1500, 0);
$per_hari_q   = $batch * 12 * 24;

/** Ubah jumlah e-mail menjadi perkiraan lama pengiriman. */
function bc_perkiraan($sisa, $batas_harian, $per_hari_q)
{
    if ($sisa <= 0) {
        return '—';
    }
    $per_hari = min($batas_harian, $per_hari_q);
    $hari = $sisa / max(1, $per_hari);
    if ($hari < 1 / 24) { return 'kurang dari 1 jam'; }
    if ($hari < 1)      { return '±' . ceil($hari * 24) . ' jam'; }
    return '±' . round($hari, 1) . ' hari';
}
?>

<div class="max-w-6xl mx-auto">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-xl md:text-2xl font-bold outfit text-slate-800 tracking-tight">Kemajuan Broadcast</h1>
            <p class="text-slate-400 text-xs md:text-sm mt-1 font-medium">Berapa yang benar-benar terkirim, bukan berapa yang diniatkan.</p>
        </div>
        <a href="index.php?page=admin_broadcast" class="inline-flex items-center gap-2 px-5 py-3 bg-white border border-slate-200 text-slate-600 rounded-2xl font-bold text-sm hover:bg-slate-50 transition-all">
            <i data-lucide="megaphone" class="w-4 h-4"></i> Kirim Broadcast
        </a>
    </div>

    <!-- Kapasitas pengiriman saat ini -->
    <div class="glass p-6 rounded-[2rem] shadow-sm mb-8">
        <h2 class="text-sm font-black text-slate-700 uppercase tracking-wider mb-4">Kapasitas Pengiriman</h2>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100">
                <div class="text-2xl font-black outfit text-slate-700"><?= e(number_format($batas_harian, 0, ',', '.')) ?></div>
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mt-1">Batas penyedia /hari</div>
            </div>
            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100">
                <div class="text-2xl font-black outfit text-slate-700"><?= e(number_format($per_hari_q, 0, ',', '.')) ?></div>
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mt-1">Kapasitas antrean /hari</div>
            </div>
            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100">
                <div class="text-2xl font-black outfit text-slate-700"><?= e($batch) ?></div>
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mt-1">E-mail per jalan cron</div>
            </div>
            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100">
                <div class="text-2xl font-black outfit text-slate-700"><?= e($jeda_ms) ?> ms</div>
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mt-1">Jeda antar e-mail</div>
            </div>
        </div>
        <p class="text-xs text-slate-500 mt-4 leading-relaxed">
            Yang benar-benar membatasi adalah angka <span class="font-bold">terkecil</span> di antara keduanya:
            <span class="font-bold"><?= e(number_format(min($batas_harian, $per_hari_q), 0, ',', '.')) ?> e-mail per hari</span>.
            <?php if ($per_hari_q < $batas_harian): ?>
                Saat ini yang membatasi adalah <span class="font-bold">antreannya sendiri</span> &mdash;
                naikkan <span class="font-mono">email_batch_size</span> atau turunkan
                <span class="font-mono">email_throttle_ms</span> di Pengaturan.
            <?php else: ?>
                Saat ini yang membatasi adalah <span class="font-bold">penyedia SMTP</span>.
                Untuk pengiriman massal, pindah ke penyedia e-mail transaksional.
            <?php endif; ?>
        </p>
    </div>

    <!-- Riwayat broadcast -->
    <div class="glass rounded-[2rem] shadow-sm overflow-hidden mb-6">
        <table class="w-full text-left">
            <thead class="bg-white/40 border-b border-white/20">
                <tr>
                    <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Broadcast</th>
                    <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Kemajuan</th>
                    <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Rincian</th>
                    <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Sisa waktu</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/20">
                <?php if (empty($daftar)): ?>
                    <tr><td colspan="4" class="px-6 py-16 text-center">
                        <div class="w-16 h-16 bg-slate-100 text-slate-300 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i data-lucide="megaphone-off" class="w-8 h-8"></i>
                        </div>
                        <p class="font-bold text-slate-400">Belum ada broadcast</p>
                    </td></tr>
                <?php endif; ?>

                <?php foreach ($daftar as $b):
                    $k        = $kemajuan[(int)$b->id] ?? [];
                    $terkirim = (int)($k['sent'] ?? 0);
                    $gagal    = (int)($k['failed'] ?? 0);
                    $menunggu = (int)($k['pending'] ?? 0) + (int)($k['processing'] ?? 0);
                    $antre    = $terkirim + $gagal + $menunggu;

                    // recipient_count mencatat NIAT saat pengiriman dimulai;
                    // yang benar-benar masuk antrean bisa lebih sedikit
                    // (mis. penerima yang sudah berhenti berlangganan).
                    $niat  = (int)($b->recipient_count ?? 0);
                    $total = $antre > 0 ? $antre : $niat;
                    $persen = $total > 0 ? round($terkirim / $total * 100) : 0;
                    $selesai = ($menunggu === 0 && $total > 0);
                ?>
                <tr class="hover:bg-white/50 transition-all">
                    <td class="px-6 py-5">
                        <div class="font-black text-slate-800 outfit"><?= e($b->title) ?></div>
                        <div class="text-[10px] text-slate-400 font-bold tracking-wider mt-1">
                            <?= e(date('d M Y, H:i', strtotime((string)$b->created_at))) ?>
                        </div>
                    </td>
                    <td class="px-6 py-5" style="min-width:200px">
                        <div class="flex items-center gap-3">
                            <div class="flex-1 h-2 rounded-full bg-slate-200 overflow-hidden">
                                <div class="h-full rounded-full <?= $gagal > 0 ? 'bg-amber-500' : ($selesai ? 'bg-emerald-500' : 'bg-blue-500') ?>"
                                     style="width: <?= e(max(2, $persen)) ?>%"></div>
                            </div>
                            <span class="text-xs font-black text-slate-600 shrink-0"><?= e($persen) ?>%</span>
                        </div>
                        <div class="text-[10px] text-slate-400 font-bold mt-1.5">
                            <?= e(number_format($terkirim, 0, ',', '.')) ?> dari <?= e(number_format($total, 0, ',', '.')) ?>
                        </div>
                    </td>
                    <td class="px-6 py-5">
                        <div class="flex flex-wrap gap-1.5">
                            <?php if ($terkirim): ?><span class="px-2 py-1 rounded-lg bg-emerald-50 text-emerald-700 text-[10px] font-bold"><?= e($terkirim) ?> terkirim</span><?php endif; ?>
                            <?php if ($menunggu): ?><span class="px-2 py-1 rounded-lg bg-blue-50 text-blue-700 text-[10px] font-bold"><?= e($menunggu) ?> menunggu</span><?php endif; ?>
                            <?php if ($gagal): ?><span class="px-2 py-1 rounded-lg bg-red-50 text-red-700 text-[10px] font-bold"><?= e($gagal) ?> gagal</span><?php endif; ?>
                            <?php if ($antre === 0): ?><span class="px-2 py-1 rounded-lg bg-slate-100 text-slate-500 text-[10px] font-bold">tidak lewat antrean</span><?php endif; ?>
                        </div>
                        <?php if ($niat > 0 && $antre > 0 && $antre < $niat): ?>
                            <div class="text-[10px] text-slate-400 mt-1.5">
                                <?= e($niat - $antre) ?> penerima dilewati (berhenti berlangganan)
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-5">
                        <span class="text-sm font-bold <?= $menunggu > 0 ? 'text-slate-700' : 'text-slate-400' ?>">
                            <?= e($selesai ? 'selesai' : bc_perkiraan($menunggu, $batas_harian, $per_hari_q)) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php
    render_pager_summary(count($daftar), $hal_bc['total'], $hal_bc['hal'], $hal_bc['total_hal'], 'broadcast');
    render_pager($bc_url, $hal_bc['hal'], $hal_bc['total_hal']);
    ?>

    <!-- Alamat yang berhenti dikirimi -->
    <div class="glass p-6 rounded-[2rem] shadow-sm mt-8">
        <h2 class="text-sm font-black text-slate-700 uppercase tracking-wider mb-2">Alamat yang Berhenti Dikirimi</h2>
        <p class="text-xs text-slate-500 mb-5 leading-relaxed">
            Berisi dua hal: alumni yang memilih berhenti sendiri, dan alamat yang ditandai otomatis
            karena berkali-kali gagal. Yang kedua bisa saja keliru &mdash; kotak masuk penuh sementara
            juga menghasilkan kegagalan. Kembalikan bila memang begitu.
        </p>

        <?php if (isset($_GET['unsub']) && $_GET['unsub'] === 'kembali'): ?>
            <div class="mb-4 p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-sm text-emerald-800">
                Alamat dikembalikan dan akan ikut pada broadcast berikutnya.
            </div>
        <?php endif; ?>

        <?php if (empty($unsub)): ?>
            <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200 text-xs text-slate-500">
                Belum ada alamat yang berhenti dikirimi.
            </div>
        <?php else: ?>
            <div class="overflow-x-auto rounded-2xl border border-slate-100">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
                        <tr>
                            <th class="px-4 py-3 text-left font-bold">Alamat</th>
                            <th class="px-4 py-3 text-left font-bold">Sebab</th>
                            <th class="px-4 py-3 text-left font-bold">Sejak</th>
                            <th class="px-4 py-3 text-right font-bold"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($unsub as $u):
                            $otomatis = stripos((string)$u->reason, 'bounce') === 0;
                        ?>
                        <tr>
                            <td class="px-4 py-3 font-mono text-xs text-slate-600"><?= e($u->email) ?></td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-1 rounded-lg text-[10px] font-bold <?= $otomatis ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600' ?>">
                                    <?= e($otomatis ? 'otomatis' : 'atas permintaan') ?>
                                </span>
                                <span class="text-xs text-slate-500 ml-2"><?= e($u->reason) ?></span>
                            </td>
                            <td class="px-4 py-3 text-slate-500 text-xs"><?= e(date('d M Y', strtotime((string)$u->created_at))) ?></td>
                            <td class="px-4 py-3 text-right">
                                <?php if ($otomatis): ?>
                                <form action="handlers/admin_unsubscribe_restore.php" method="POST" class="inline">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="email" value="<?= e($u->email) ?>">
                                    <button type="submit" class="text-xs font-bold text-blue-600 hover:underline">Kembalikan</button>
                                </form>
                                <?php else: ?>
                                    <span class="text-[10px] text-slate-300">dipilih sendiri</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($hal_unsub['total_hal'] > 1): ?>
            <div class="flex justify-center gap-2 flex-wrap mt-4">
                <?php for ($i = 1; $i <= $hal_unsub['total_hal']; $i++): ?>
                    <a href="index.php?page=admin_broadcast_status&p=<?= e($hal_bc['hal']) ?>&pu=<?= e($i) ?>"
                       class="w-9 h-9 rounded-xl flex items-center justify-center font-bold text-xs transition-all
                              <?= e($i === $hal_unsub['hal'] ? 'bg-blue-600 text-white' : 'glass text-slate-600 hover:bg-white') ?>">
                        <?= e($i) ?>
                    </a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
