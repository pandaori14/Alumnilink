<?php
/**
 * Impor Massal Alumni — unggah, pratinjau, lalu commit.
 *
 * Halaman ini juga memuat panel "Kesehatan Data NIM". Panel itu bersifat
 * baca-saja dan ada karena NIM belum bisa dijadikan UNIQUE di basis data:
 * sudah ada satu NIM ganda dan beberapa NIM ngawur, sehingga ALTER TABLE
 * akan gagal — dan blok migrasi di config/db.php hanya punya satu catch
 * terluar yang menutup seluruh situs dengan die(). Panel ini mengubah
 * masalah yang tidak terlihat menjadi daftar kerja, dan menjadi syarat
 * sebelum constraint itu boleh dipasang di rilis terpisah nanti.
 */

require_once __DIR__ . '/../includes/import_lib.php';

if (!role_can_open_page('admin_alumni_import', $_SESSION['user_role'] ?? '')) {
    deny_access(403, 'Halaman impor alumni hanya untuk pengelola data alumni.');
}

$sesi_impor = $_SESSION['alumni_import'] ?? null;
$galat_parse = $_SESSION['alumni_import_error'] ?? '';

// ── Diagnostik NIM (baca-saja) ────────────────────────────────────────
$nim_ganda = $pdo->query(
    "SELECT nim, COUNT(*) AS c, GROUP_CONCAT(id SEPARATOR ', ') AS ids
     FROM users WHERE nim IS NOT NULL AND nim <> '' GROUP BY nim HAVING c > 1"
)->fetchAll();

$nim_ngawur = $pdo->query(
    "SELECT id, nim, name, role FROM users
     WHERE nim IS NOT NULL AND nim <> '' AND nim NOT REGEXP '^[A-Za-z0-9]{6,20}$'
     ORDER BY name"
)->fetchAll();

$nim_kosong = (int)$pdo->query(
    "SELECT COUNT(*) FROM users WHERE role = 'alumni' AND (nim IS NULL OR nim = '')"
)->fetchColumn();

$total_alumni = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'alumni'")->fetchColumn();
$nim_sehat = ($nim_ganda === [] && $nim_ngawur === []);

$pesan_galat = [
    'unggah'      => 'Berkas gagal diunggah. Coba pilih ulang berkasnya.',
    'ukuran'      => 'Ukuran berkas melebihi batas ' . number_format(setting_int('import_max_bytes', 2097152) / 1024, 0, ',', '.') . ' KB.',
    'xlsx'        => 'Berkas Excel (.xlsx/.xls) belum didukung. Buka di Excel lalu pilih "Save As" → CSV UTF-8.',
    'ekstensi'    => 'Hanya berkas .csv yang diterima.',
    'mime'        => 'Isi berkas tidak dikenali sebagai teks CSV' . (isset($_GET['mime']) ? ' (terbaca: ' . htmlspecialchars($_GET['mime']) . ')' : '') . '.',
    'parse'       => $galat_parse ?: 'Berkas tidak dapat dibaca.',
    'sesi_habis'  => 'Data pratinjau sudah tidak ada. Silakan unggah ulang berkasnya.',
    'kedaluwarsa' => 'Pratinjau sudah lebih dari 30 menit. Unggah ulang agar pemeriksaan duplikat tetap akurat.',
    'token'       => 'Permintaan tidak cocok dengan pratinjau yang tersimpan.',
    'gagal'       => $galat_parse ?: 'Impor gagal dan dibatalkan seluruhnya.',
    'metode'      => 'Permintaan tidak sah.',
    'aksi'        => 'Aksi tidak dikenali.',
];
?>

<div class="mb-8">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-black outfit text-slate-800 tracking-tight">Impor Massal Alumni</h1>
            <p class="text-slate-500 text-sm mt-1">Unggah satu berkas CSV untuk menambahkan data satu angkatan sekaligus.</p>
        </div>
        <a href="index.php?page=admin_alumni" class="inline-flex items-center gap-2 px-5 py-3 bg-white border border-slate-200 text-slate-600 rounded-2xl font-bold text-sm hover:bg-slate-50 transition-all">
            <i data-lucide="arrow-left" class="w-4 h-4"></i> Database Alumni
        </a>
    </div>
</div>

<?php if (isset($_GET['error'])): ?>
    <div class="mb-6 flex items-start gap-3 p-5 rounded-2xl bg-red-50 border border-red-200">
        <i data-lucide="alert-circle" class="w-5 h-5 text-red-600 shrink-0 mt-0.5"></i>
        <div class="text-sm text-red-700 leading-relaxed">
            <span class="font-bold block mb-0.5">Impor tidak dijalankan</span>
            <?php echo htmlspecialchars($pesan_galat[$_GET['error']] ?? 'Terjadi kesalahan.'); ?>
        </div>
    </div>
<?php endif; ?>

<?php if (isset($_GET['success'])): ?>
    <div class="mb-6 flex items-start gap-3 p-5 rounded-2xl bg-emerald-50 border border-emerald-200">
        <i data-lucide="check-circle-2" class="w-5 h-5 text-emerald-600 shrink-0 mt-0.5"></i>
        <div class="text-sm text-emerald-800 leading-relaxed">
            <span class="font-bold block mb-0.5">Impor selesai</span>
            <?php echo (int)($_GET['dibuat'] ?? 0); ?> alumni dibuat,
            <?php echo (int)($_GET['diperbarui'] ?? 0); ?> dilengkapi,
            <?php echo (int)($_GET['dilewati'] ?? 0); ?> dilewati.
        </div>
    </div>
<?php endif; ?>

<?php if ($sesi_impor && !empty($sesi_impor['rows'])):
    $r = $sesi_impor['ringkasan'];
    $rows = $sesi_impor['rows'];
    $hal   = max(1, (int)($_GET['p'] ?? 1));
    $per   = pagination_size(25);
    $total_hal = max(1, (int)ceil(count($rows) / $per));
    $hal   = min($hal, $total_hal);
    $iris  = array_slice($rows, ($hal - 1) * $per, $per);
    $umur  = time() - $sesi_impor['dibuat'];
?>
<!-- ══ LANGKAH 2: PRATINJAU ══════════════════════════════════════ -->
<div class="glass p-8 rounded-[2rem] border border-white shadow-sm mb-6">
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <h2 class="text-xl font-black outfit text-slate-800 flex items-center gap-2">
                <i data-lucide="eye" class="w-5 h-5 text-blue-600"></i> Pratinjau &mdash; belum ada yang tersimpan
            </h2>
            <p class="text-slate-500 text-sm mt-1">
                Berkas <span class="font-bold text-slate-700"><?php echo htmlspecialchars($sesi_impor['berkas']); ?></span>,
                <?php echo count($rows); ?> baris, dibaca <?php echo max(1, (int)round($umur / 60)); ?> menit lalu.
            </p>
        </div>
        <form action="handlers/admin_alumni_import.php?action=batal" method="POST">
            <?php echo csrf_field(); ?>
            <button type="submit" class="px-5 py-3 bg-white border border-slate-200 text-slate-600 rounded-2xl font-bold text-sm hover:bg-slate-50 transition-all">
                Buang pratinjau
            </button>
        </form>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <?php foreach ([
            ['create',   'Akan dibuat',   'emerald'],
            ['update',   'Lengkapi data', 'blue'],
            ['conflict', 'Bentrok NIM',   'amber'],
            ['error',    'Tidak sah',     'red'],
        ] as [$k, $label, $warna]): ?>
            <div class="p-5 rounded-2xl bg-<?php echo e($warna); ?>-50 border border-<?php echo e($warna); ?>-100">
                <div class="text-3xl font-black text-<?php echo e($warna); ?>-700 outfit"><?php echo e($r[$k]); ?></div>
                <div class="text-xs font-bold text-<?php echo e($warna); ?>-600 uppercase tracking-wider mt-1"><?php echo e($label); ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($r['create'] === 0 && $r['update'] === 0): ?>
        <div class="flex items-start gap-3 p-5 rounded-2xl bg-amber-50 border border-amber-200 mb-6">
            <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600 shrink-0 mt-0.5"></i>
            <p class="text-sm text-amber-800 leading-relaxed">Tidak ada baris yang dapat diproses. Perbaiki berkasnya lalu unggah ulang.</p>
        </div>
    <?php else: ?>
    <form action="handlers/admin_alumni_import.php?action=commit" method="POST" id="form-commit" class="mb-6">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($sesi_impor['token']); ?>">

        <?php if ($r['update'] > 0): ?>
        <label class="flex items-start gap-3 p-4 rounded-2xl bg-slate-50 border border-slate-200 mb-4 cursor-pointer">
            <input type="checkbox" name="izinkan_perbarui" value="1" class="mt-0.5 w-4 h-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 shrink-0">
            <span class="text-xs text-slate-600 leading-relaxed">
                <span class="font-bold text-slate-700 block mb-0.5">Lengkapi data alumni yang sudah terdaftar (<?php echo e($r['update']); ?> baris)</span>
                Hanya kolom yang saat ini <em>masih kosong</em> yang akan diisi. Nilai yang sudah ada tidak pernah ditimpa,
                dan kata sandi, peran, serta status verifikasi tidak pernah disentuh.
                Bila tidak dicentang, baris-baris itu dilewati.
            </span>
        </label>
        <?php endif; ?>

        <button type="submit" class="inline-flex items-center gap-2 px-8 py-4 bg-slate-900 text-white rounded-2xl font-bold text-sm shadow-lg hover:bg-black transition-all active:scale-95">
            <i data-lucide="database-backup" class="w-4 h-4"></i>
            Jalankan impor (<?php echo e($r['create']); ?> baris baru)
        </button>
    </form>
    <?php endif; ?>

    <?php if ($r['error'] > 0 || $r['conflict'] > 0): ?>
        <a href="handlers/admin_alumni_import.php?action=errors" class="inline-flex items-center gap-2 text-sm font-bold text-blue-600 hover:underline mb-6">
            <i data-lucide="download" class="w-4 h-4"></i> Unduh daftar baris bermasalah (CSV)
        </a>
    <?php endif; ?>

    <div class="overflow-x-auto rounded-2xl border border-slate-100">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3 text-left font-bold">Baris</th>
                    <th class="px-4 py-3 text-left font-bold">Status</th>
                    <th class="px-4 py-3 text-left font-bold">Nama</th>
                    <th class="px-4 py-3 text-left font-bold">E-mail</th>
                    <th class="px-4 py-3 text-left font-bold">NIM</th>
                    <th class="px-4 py-3 text-left font-bold">Prodi</th>
                    <th class="px-4 py-3 text-left font-bold">Keterangan</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($iris as $row): $m = import_verdict_meta($row['verdict']); ?>
                <tr class="hover:bg-slate-50/70">
                    <td class="px-4 py-3 text-slate-400 font-mono text-xs"><?php echo (int)$row['_line']; ?></td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider <?php echo e($m['kelas']); ?>">
                            <i data-lucide="<?php echo e($m['ikon']); ?>" class="w-3 h-3"></i><?php echo e($m['label']); ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 font-semibold text-slate-700"><?php echo htmlspecialchars($row['name']); ?></td>
                    <td class="px-4 py-3 text-slate-500"><?php echo htmlspecialchars($row['email']); ?></td>
                    <td class="px-4 py-3 text-slate-500 font-mono text-xs"><?php echo htmlspecialchars($row['nim'] ?? '—'); ?></td>
                    <td class="px-4 py-3 text-slate-500"><?php echo htmlspecialchars($row['major'] ?? '—'); ?></td>
                    <td class="px-4 py-3 text-slate-500 text-xs leading-relaxed max-w-md"><?php echo htmlspecialchars(implode(' ', $row['messages'])) ?: '—'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_hal > 1): ?>
    <div class="flex items-center justify-center gap-2 mt-6">
        <?php
        $awal = max(1, $hal - 2);
        $akhir = min($total_hal, $hal + 2);
        $tautan = function ($p, $label, $aktif = false) {
            printf('<a href="index.php?page=admin_alumni_import&preview=1&p=%d" class="px-3 py-2 rounded-xl text-sm font-bold %s">%s</a>',
                $p, $aktif ? 'bg-slate-900 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50', $label);
        };
        if ($hal > 1) { $tautan(1, '«'); $tautan($hal - 1, '‹'); }
        for ($i = $awal; $i <= $akhir; $i++) { $tautan($i, (string)$i, $i === $hal); }
        if ($hal < $total_hal) { $tautan($hal + 1, '›'); $tautan($total_hal, '»'); }
        ?>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- ══ LANGKAH 1: UNGGAH ═════════════════════════════════════════ -->
<div class="grid lg:grid-cols-3 gap-6 mb-6">
    <div class="lg:col-span-2 glass p-8 rounded-[2rem] border border-white shadow-sm">
        <h2 class="text-xl font-black outfit text-slate-800 flex items-center gap-2 mb-2">
            <i data-lucide="upload-cloud" class="w-5 h-5 text-blue-600"></i> Unggah Berkas CSV
        </h2>
        <p class="text-slate-500 text-sm mb-6 leading-relaxed">
            Berkas akan dibaca dan diperiksa lebih dulu. <span class="font-bold text-slate-700">Tidak ada data yang ditulis sebelum Anda menekan tombol jalankan di layar pratinjau.</span>
        </p>

        <form action="handlers/admin_alumni_import.php?action=preview" method="POST" enctype="multipart/form-data" class="space-y-5">
            <?php echo csrf_field(); ?>
            <div>
                <label for="f_csv" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Berkas CSV</label>
                <input type="file" id="f_csv" name="csv" accept=".csv,text/csv,text/plain" required
                       class="w-full px-4 py-3 rounded-2xl bg-white border border-slate-200 text-sm font-semibold text-slate-600 file:mr-4 file:px-4 file:py-2 file:rounded-xl file:border-0 file:bg-slate-900 file:text-white file:font-bold file:text-xs file:cursor-pointer">
            </div>
            <button type="submit" class="inline-flex items-center gap-2 px-8 py-4 bg-blue-600 text-white rounded-2xl font-bold text-sm shadow-lg hover:bg-blue-700 transition-all active:scale-95">
                <i data-lucide="scan-line" class="w-4 h-4"></i> Periksa berkas
            </button>
        </form>
    </div>

    <div class="glass p-8 rounded-[2rem] border border-white shadow-sm">
        <h3 class="text-sm font-black text-slate-700 uppercase tracking-wider mb-4">Format Kolom</h3>
        <ol class="text-xs text-slate-500 space-y-2 leading-relaxed list-decimal list-inside">
            <li><span class="font-bold text-slate-700">NIM</span> &mdash; 6&ndash;20 huruf/angka, boleh kosong</li>
            <li><span class="font-bold text-slate-700">Nama</span> &mdash; wajib</li>
            <li><span class="font-bold text-slate-700">Email</span> &mdash; wajib, jadi penanda orang</li>
            <li><span class="font-bold text-slate-700">Tahun Lulus</span> &mdash; 1950&ndash;<?php echo date('Y') + 1; ?></li>
            <li><span class="font-bold text-slate-700">Prodi</span> &mdash; kode atau nama prodi</li>
            <li><span class="font-bold text-slate-700">IPK</span> &mdash; 0&ndash;4, koma atau titik</li>
            <li><span class="font-bold text-slate-700">Telepon</span></li>
            <li><span class="font-bold text-slate-700">Alamat</span></li>
        </ol>
        <p class="text-xs text-slate-400 mt-4 leading-relaxed">
            Urutannya sama persis dengan hasil <span class="font-semibold">Ekspor Excel</span>, jadi ekspor &rarr; sunting &rarr; impor kembali bisa dilakukan langsung.
            Pemisah koma maupun titik-koma diterima.
        </p>
        <a href="handlers/admin_alumni_import.php?action=template" class="inline-flex items-center gap-2 mt-5 text-sm font-bold text-blue-600 hover:underline">
            <i data-lucide="file-down" class="w-4 h-4"></i> Unduh berkas contoh
        </a>
    </div>
</div>
<?php endif; ?>

<!-- ══ KESEHATAN DATA NIM ════════════════════════════════════════ -->
<div class="glass p-8 rounded-[2rem] border border-white shadow-sm">
    <h2 class="text-xl font-black outfit text-slate-800 flex items-center gap-2 mb-2">
        <i data-lucide="stethoscope" class="w-5 h-5 text-<?php echo $nim_sehat ? 'emerald' : 'amber'; ?>-600"></i> Kesehatan Data NIM
    </h2>
    <p class="text-slate-500 text-sm mb-6 leading-relaxed">
        Panel ini hanya membaca &mdash; tidak mengubah apa pun. NIM belum dijadikan kunci unik di basis data karena
        data yang ada belum bersih; selama masih ada baris di bawah, memasang kunci unik akan menggagalkan migrasi.
    </p>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="p-5 rounded-2xl bg-slate-50 border border-slate-100">
            <div class="text-3xl font-black text-slate-700 outfit"><?php echo e($total_alumni); ?></div>
            <div class="text-xs font-bold text-slate-500 uppercase tracking-wider mt-1">Total alumni</div>
        </div>
        <div class="p-5 rounded-2xl bg-<?php echo $nim_ganda ? 'red' : 'emerald'; ?>-50 border border-<?php echo $nim_ganda ? 'red' : 'emerald'; ?>-100">
            <div class="text-3xl font-black text-<?php echo $nim_ganda ? 'red' : 'emerald'; ?>-700 outfit"><?php echo count($nim_ganda); ?></div>
            <div class="text-xs font-bold text-<?php echo $nim_ganda ? 'red' : 'emerald'; ?>-600 uppercase tracking-wider mt-1">NIM ganda</div>
        </div>
        <div class="p-5 rounded-2xl bg-<?php echo $nim_ngawur ? 'amber' : 'emerald'; ?>-50 border border-<?php echo $nim_ngawur ? 'amber' : 'emerald'; ?>-100">
            <div class="text-3xl font-black text-<?php echo $nim_ngawur ? 'amber' : 'emerald'; ?>-700 outfit"><?php echo count($nim_ngawur); ?></div>
            <div class="text-xs font-bold text-<?php echo $nim_ngawur ? 'amber' : 'emerald'; ?>-600 uppercase tracking-wider mt-1">NIM tidak sah</div>
        </div>
        <div class="p-5 rounded-2xl bg-slate-50 border border-slate-100">
            <div class="text-3xl font-black text-slate-700 outfit"><?php echo e($nim_kosong); ?></div>
            <div class="text-xs font-bold text-slate-500 uppercase tracking-wider mt-1">Belum ber-NIM</div>
        </div>
    </div>

    <?php if ($nim_ganda): ?>
    <h3 class="text-sm font-black text-slate-700 uppercase tracking-wider mb-3">NIM dipakai lebih dari satu akun</h3>
    <div class="overflow-x-auto rounded-2xl border border-slate-100 mb-8">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
                <tr><th class="px-4 py-3 text-left font-bold">NIM</th><th class="px-4 py-3 text-left font-bold">Jumlah</th><th class="px-4 py-3 text-left font-bold">ID akun</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($nim_ganda as $d): ?>
                <tr><td class="px-4 py-3 font-mono text-xs font-bold text-slate-700"><?php echo htmlspecialchars($d->nim); ?></td>
                    <td class="px-4 py-3 text-slate-500"><?php echo (int)$d->c; ?></td>
                    <td class="px-4 py-3 font-mono text-xs text-slate-500"><?php echo htmlspecialchars($d->ids); ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="text-xs text-slate-500 leading-relaxed mb-8 -mt-6">
        Penggabungan akun sengaja tidak diotomatiskan: memindahkan riwayat lintas enam tabel lalu menghapus satu baris
        <span class="font-mono">users</span> tidak dapat dibatalkan. Pada jumlah sekecil ini, menyatukannya secara manual lebih aman.
    </p>
    <?php endif; ?>

    <?php if ($nim_ngawur): ?>
    <h3 class="text-sm font-black text-slate-700 uppercase tracking-wider mb-3">NIM tidak sesuai format</h3>
    <div class="overflow-x-auto rounded-2xl border border-slate-100">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
                <tr><th class="px-4 py-3 text-left font-bold">Nama</th><th class="px-4 py-3 text-left font-bold">Peran</th><th class="px-4 py-3 text-left font-bold">NIM tersimpan</th><th class="px-4 py-3 text-left font-bold"></th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($nim_ngawur as $u): ?>
                <tr>
                    <td class="px-4 py-3 font-semibold text-slate-700"><?php echo htmlspecialchars($u->name); ?></td>
                    <td class="px-4 py-3 text-slate-500 text-xs"><?php echo htmlspecialchars($u->role); ?></td>
                    <td class="px-4 py-3"><span class="px-2 py-1 rounded-lg bg-amber-50 text-amber-700 font-mono text-xs font-bold"><?php echo htmlspecialchars($u->nim); ?></span></td>
                    <td class="px-4 py-3 text-right">
                        <a href="index.php?page=<?php echo e($u->role === 'alumni' ? 'admin_alumni' : 'admin_users'); ?>" class="text-xs font-bold text-blue-600 hover:underline">Perbaiki &rarr;</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($nim_sehat): ?>
    <div class="flex items-start gap-3 p-5 rounded-2xl bg-emerald-50 border border-emerald-200">
        <i data-lucide="check-circle-2" class="w-5 h-5 text-emerald-600 shrink-0 mt-0.5"></i>
        <p class="text-sm text-emerald-800 leading-relaxed">
            Tidak ada NIM ganda maupun NIM tidak sah. Kunci unik pada kolom NIM sudah aman untuk dipasang pada rilis berikutnya.
        </p>
    </div>
    <?php endif; ?>
</div>
