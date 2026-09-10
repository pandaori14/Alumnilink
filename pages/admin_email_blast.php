<?php
/**
 * Admin Email Blast Page
 * Premium UI for super admin to send mass emails with filters.
 */

// Only super_admin can access
if ($_SESSION['user_role'] !== 'super_admin') {
    echo "<div class='glass p-10 rounded-3xl text-center'><h2 class='text-2xl font-bold outfit'>Akses Ditolak</h2><p class='text-slate-500 mt-2'>Fitur ini hanya untuk Super Admin.</p></div>";
    return;
}

// Fetch majors for filter dropdown
$majors_stmt = $pdo->query("SELECT major_code, major_name FROM majors ORDER BY major_name");
$majors = $majors_stmt->fetchAll();

// Fetch graduation years
$years_stmt = $pdo->query("SELECT DISTINCT graduation_year FROM users WHERE graduation_year IS NOT NULL ORDER BY graduation_year DESC");
$years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch blast history
$history_stmt = $pdo->query("SELECT eb.*, u.name AS sender_name FROM email_blasts eb LEFT JOIN users u ON eb.sent_by = u.id ORDER BY eb.created_at DESC LIMIT 20");
$blast_history = $history_stmt->fetchAll();

// Count recipients for preview
$total_alumni = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'alumni' AND email_notifications = 1 AND email IS NOT NULL AND email != ''")->fetchColumn();
?>

<section class="space-y-6" id="admin-email-blast">
    <!-- Page Header -->
    <header class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <h1 class="text-2xl md:text-3xl font-extrabold outfit tracking-tight text-slate-800 flex items-center gap-3">
                <span class="w-10 h-10 bg-gradient-to-br from-teal-500 to-emerald-600 rounded-2xl flex items-center justify-center shadow-lg shadow-teal-200">
                    <i data-lucide="mail" class="w-5 h-5 text-white"></i>
                </span>
                Email Blast
            </h1>
            <p class="text-slate-500 text-sm mt-1">Kirim email massal ke alumni dengan filter khusus</p>
        </div>
        <div class="flex items-center gap-2 text-sm">
            <span class="px-3 py-1.5 bg-teal-50 text-teal-700 rounded-xl font-semibold border border-teal-100">
                <i data-lucide="users" class="w-3.5 h-3.5 inline mr-1"></i>
                <?= number_format($total_alumni) ?> alumni eligible
            </span>
        </div>
    </header>

    <!-- Alert Messages -->
    <?php if (isset($_GET['success']) && $_GET['success'] === 'sent'): ?>
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 rounded-2xl flex items-start gap-3" role="alert">
            <i data-lucide="check-circle" class="w-5 h-5 text-emerald-600 mt-0.5 flex-shrink-0"></i>
            <div>
                <p class="font-semibold">Email Blast Berhasil Terkirim!</p>
                <p class="text-sm mt-0.5">
                    <?= intval($_GET['count'] ?? 0) ?> email berhasil dikirim
                    <?php if (intval($_GET['fail'] ?? 0) > 0): ?>
                        <span class="text-amber-700">(<?= intval($_GET['fail']) ?> gagal)</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="bg-red-50 border border-red-200 text-red-800 p-4 rounded-2xl flex items-start gap-3" role="alert">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-600 mt-0.5 flex-shrink-0"></i>
            <p class="font-semibold">
                <?php if ($_GET['error'] === 'missing_fields'): ?>
                    Subject dan konten email tidak boleh kosong.
                <?php else: ?>
                    Terjadi kesalahan saat mengirim email blast.
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Compose Form (2/3 width) -->
        <div class="lg:col-span-2">
            <form action="handlers/admin_email_blast.php" method="POST" class="glass rounded-3xl p-6 md:p-8 space-y-5" id="blastForm" onsubmit="return confirmBlast()">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

                <div>
                    <label for="blast-subject" class="block text-sm font-semibold text-slate-700 mb-2">Subject Email</label>
                    <input type="text" name="subject" id="blast-subject" required maxlength="255"
                        class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-400 focus:border-transparent transition-all duration-200 text-sm"
                        placeholder="Contoh: Undangan Reuni Akbar 2026">
                </div>

                <div>
                    <label for="blast-content" class="block text-sm font-semibold text-slate-700 mb-2">Isi Email</label>
                    <textarea name="content" id="blast-content" required rows="10"
                        class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-400 focus:border-transparent transition-all duration-200 text-sm"
                        placeholder="Tulis isi email Anda di sini. Anda dapat menggunakan tag HTML dasar seperti <b>, <i>, <a>, <ul>, <li>."></textarea>
                    <p class="text-xs text-slate-400 mt-1">
                        <i data-lucide="info" class="w-3 h-3 inline"></i>
                        Mendukung tag HTML dasar: &lt;b&gt;, &lt;i&gt;, &lt;a href=""&gt;, &lt;ul&gt;, &lt;li&gt;, &lt;p&gt;
                    </p>
                </div>

                <!-- Filter Section -->
                <div class="bg-slate-50 rounded-2xl p-5 space-y-4 border border-slate-100">
                    <h3 class="text-sm font-bold text-slate-700 flex items-center gap-2">
                        <i data-lucide="filter" class="w-4 h-4 text-teal-600"></i>
                        Filter Penerima
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label for="filter-role" class="block text-xs font-medium text-slate-500 mb-1">Role</label>
                            <select name="filter_role" id="filter-role" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-teal-400" onchange="updateRecipientCount()">
                                <option value="alumni">Alumni</option>
                                <option value="all">Semua Role</option>
                            </select>
                        </div>
                        <div>
                            <label for="filter-major" class="block text-xs font-medium text-slate-500 mb-1">Program Studi</label>
                            <select name="filter_major" id="filter-major" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-teal-400" onchange="updateRecipientCount()">
                                <option value="">Semua Prodi</option>
                                <?php foreach ($majors as $m): ?>
                                    <option value="<?= htmlspecialchars($m->major_code) ?>"><?= htmlspecialchars($m->major_name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="filter-year" class="block text-xs font-medium text-slate-500 mb-1">Angkatan / Tahun Lulus</label>
                            <select name="filter_graduation_year" id="filter-year" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-teal-400" onchange="updateRecipientCount()">
                                <option value="">Semua Angkatan</option>
                                <?php foreach ($years as $y): ?>
                                    <option value="<?= e($y) ?>"><?= e($y) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="pt-2 border-t border-slate-200">
                        <p class="text-sm text-slate-600">
                            Estimasi penerima:
                            <span id="recipientCount" class="font-bold text-teal-700"><?= number_format($total_alumni) ?></span>
                            <span class="text-xs text-slate-400">orang</span>
                        </p>
                    </div>
                </div>

                <!-- Warning -->
                <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 flex items-start gap-3">
                    <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600 mt-0.5 flex-shrink-0"></i>
                    <div class="text-sm text-amber-800">
                        <p class="font-semibold">Perhatian</p>
                        <p class="mt-0.5">Email blast akan dikirim ke semua alumni yang memenuhi filter dan mengaktifkan notifikasi email. Tindakan ini tidak dapat dibatalkan. Penggunaan dibatasi maksimum 2 kali per 30 menit.</p>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" id="btnSendBlast"
                        class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-teal-600 to-emerald-600 text-white font-semibold rounded-xl hover:from-teal-700 hover:to-emerald-700 focus:ring-4 focus:ring-teal-200 transition-all duration-300 shadow-lg shadow-teal-200"
                        aria-label="Kirim email blast ke alumni terpilih">
                        <i data-lucide="send" class="w-4 h-4"></i>
                        Kirim Email Blast
                    </button>
                </div>
            </form>
        </div>

        <!-- Sidebar: History -->
        <div class="lg:col-span-1">
            <div class="glass rounded-3xl p-6 space-y-4">
                <h3 class="text-sm font-bold text-slate-700 flex items-center gap-2">
                    <i data-lucide="history" class="w-4 h-4 text-slate-500"></i>
                    Riwayat Blast Terakhir
                </h3>
                <?php if (empty($blast_history)): ?>
                    <div class="text-center py-8">
                        <i data-lucide="inbox" class="w-10 h-10 text-slate-300 mx-auto mb-2"></i>
                        <p class="text-sm text-slate-400">Belum ada email blast yang dikirim</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-3 max-h-[600px] overflow-y-auto pr-1">
                        <?php foreach ($blast_history as $h): ?>
                            <div class="bg-slate-50 rounded-xl p-3 border border-slate-100 hover:border-teal-200 transition-colors duration-200">
                                <p class="font-semibold text-sm text-slate-800 truncate" title="<?= htmlspecialchars($h->subject) ?>">
                                    <?= htmlspecialchars($h->subject) ?>
                                </p>
                                <div class="flex items-center gap-3 mt-1.5 text-xs text-slate-500">
                                    <span class="flex items-center gap-1">
                                        <i data-lucide="users" class="w-3 h-3"></i>
                                        <?= number_format($h->recipient_count) ?> penerima
                                    </span>
                                    <span class="flex items-center gap-1">
                                        <i data-lucide="clock" class="w-3 h-3"></i>
                                        <?= date('d M Y, H:i', strtotime($h->created_at)) ?>
                                    </span>
                                </div>
                                <?php if (!empty($h->filter_major) || !empty($h->filter_graduation_year)): ?>
                                    <div class="flex flex-wrap gap-1 mt-2">
                                        <?php if (!empty($h->filter_major)): ?>
                                            <span class="px-2 py-0.5 bg-blue-50 text-blue-700 rounded-lg text-[10px] font-medium"><?= htmlspecialchars($h->filter_major) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($h->filter_graduation_year)): ?>
                                            <span class="px-2 py-0.5 bg-purple-50 text-purple-700 rounded-lg text-[10px] font-medium">Angkatan <?= e($h->filter_graduation_year) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<script>
    function confirmBlast() {
        const count = document.getElementById('recipientCount').textContent;
        const subject = document.getElementById('blast-subject').value;
        const btn = document.getElementById('btnSendBlast');

        // Dialog konfirmasi bersifat asinkron, sehingga fungsi ini SELALU
        // mengembalikan false untuk menahan submit bawaan. Pengiriman
        // sesungguhnya dilakukan di dalam callback lewat form.submit(),
        // yang tidak memicu ulang handler onsubmit ini.
        const form = document.getElementById('blastForm') || btn.closest('form');

        swalConfirm(
            'Kirim Email Blast?',
            `Email "${subject}" akan dikirim ke ${count} penerima. Tindakan ini tidak dapat dibatalkan.`,
            () => {
                btn.disabled = true;
                btn.innerHTML = '<svg class="animate-spin w-4 h-4 mr-2" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Mengirim...';
                if (form) form.submit();
            },
            'Ya, Kirim Sekarang'
        );

        return false;
    }

    function updateRecipientCount() {
        const role = document.getElementById('filter-role').value;
        const major = document.getElementById('filter-major').value;
        const year = document.getElementById('filter-year').value;
        
        const params = new URLSearchParams();
        params.append('action', 'count_recipients');
        if (role) params.append('filter_role', role);
        if (major) params.append('filter_major', major);
        if (year) params.append('filter_graduation_year', year);
        
        fetch('api/email_blast_count.php?' + params.toString())
            .then(r => r.json())
            .then(data => {
                document.getElementById('recipientCount').textContent = data.count?.toLocaleString('id-ID') ?? '0';
            })
            .catch(() => {});
    }
</script>
