<?php
/**
 * Admin Broadcast Center — Gmail-like Rich Compose UI
 * Features: Rich text editor, layout picker, HTML mode, attachments, drag & drop, emoji picker
 */

// ── Data fetching ────────────────────────────────────────────────────────────
$page_num = max(1, intval($_GET['p'] ?? 1));
$per_page  = 6;
$offset    = ($page_num - 1) * $per_page;

$total_bc  = $pdo->query("SELECT COUNT(*) FROM broadcasts")->fetchColumn();

$where = [];
$params = [];

$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $where[] = "(b.title LIKE ? OR b.message LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$f_priority = trim($_GET['priority'] ?? '');
if (in_array($f_priority, ['normal', 'info', 'urgent'])) {
    $where[] = "b.priority = ?";
    $params[] = $f_priority;
}

$f_channel = trim($_GET['channel'] ?? '');
if (in_array($f_channel, ['app', 'email'])) {
    $where[] = "FIND_IN_SET(?, b.channels)";
    $params[] = $f_channel;
}

$where_sql = '';
if (!empty($where)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where);
}

// Count total with filters
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM broadcasts b $where_sql");
$count_stmt->execute($params);
$history_count = $count_stmt->fetchColumn();

$total_pages = max(1, ceil($history_count / $per_page));

$bc_stmt = $pdo->prepare(
    "SELECT b.*, u.name AS sender_name FROM broadcasts b
     LEFT JOIN users u ON b.sent_by = u.id
     $where_sql
     ORDER BY b.created_at DESC LIMIT $per_page OFFSET $offset"
);
$bc_stmt->execute($params);
$broadcasts = $bc_stmt->fetchAll();

$majors_list = $pdo->query("SELECT major_code, major_name FROM majors ORDER BY major_name ASC")->fetchAll();
$years_list  = $pdo->query("SELECT DISTINCT graduation_year FROM users WHERE graduation_year IS NOT NULL ORDER BY graduation_year DESC")->fetchAll(PDO::FETCH_COLUMN);
$total_alumni = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'alumni'")->fetchColumn();
$email_opt_in = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'alumni' AND email_notifications = 1 AND email IS NOT NULL AND email != ''")->fetchColumn();

// Custom saved layouts from DB
$custom_layouts_raw = [];
try {
    $cl_stmt = $pdo->prepare("SELECT id, name, html_content, thumb_type FROM broadcast_layouts WHERE created_by = ? ORDER BY created_at DESC LIMIT 20");
    $cl_stmt->execute([$_SESSION['user_id']]);
    $custom_layouts_raw = $cl_stmt->fetchAll();
} catch (PDOException $e) {}

// ── Email template definitions ───────────────────────────────────────────────
$email_templates = [
    [
        'id' => 'simple', 'name' => 'Simple Text', 'thumb_type' => 'simple',
        'html' => '<div style="max-width:600px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;color:#374151;background:#ffffff;"><div style="padding:36px 32px;"><h2 style="font-size:22px;font-weight:700;color:#1e293b;margin:0 0 18px;line-height:1.35;">Judul Pengumuman</h2><p style="font-size:15px;line-height:1.85;color:#475569;margin:0 0 16px;">Tulis isi pesan Anda di sini. Sampaikan informasi penting kepada seluruh alumni dengan bahasa yang jelas dan mudah dipahami oleh semua orang.</p><p style="font-size:15px;line-height:1.85;color:#475569;margin:0 0 28px;">Tambahkan detail atau konteks tambahan yang perlu diketahui oleh penerima pesan ini. Pastikan informasi yang disampaikan lengkap dan akurat.</p><p style="font-size:15px;line-height:1.85;color:#475569;margin:0;">Salam hangat,<br><strong style="color:#1e293b;">Tim AlumniLink</strong></p></div><div style="border-top:1px solid #e2e8f0;padding:16px 32px;text-align:center;background:#f8fafc;"><p style="font-size:12px;color:#94a3b8;margin:0;">AlumniLink &mdash; Portal Alumni Resmi &nbsp;&bull;&nbsp; <a href="#" style="color:#6366f1;text-decoration:none;">Kelola Preferensi Email</a></p></div></div>',
    ],
    [
        'id' => 'announcement', 'name' => 'Announcement', 'thumb_type' => 'announcement',
        'html' => '<div style="max-width:600px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;background:#ffffff;"><div style="background:linear-gradient(135deg,#6366f1 0%,#8b5cf6 100%);padding:48px 32px;text-align:center;"><div style="display:inline-block;background:rgba(255,255,255,0.18);border-radius:50px;padding:6px 18px;margin-bottom:20px;"><span style="color:rgba(255,255,255,0.95);font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;">Pengumuman Resmi</span></div><h1 style="color:#ffffff;font-size:27px;font-weight:800;margin:0 0 12px;line-height:1.25;">Judul Pengumuman Penting</h1><p style="color:rgba(255,255,255,0.78);font-size:15px;margin:0;">Portal Alumni AlumniLink</p></div><div style="background:#ffffff;padding:40px 32px;"><p style="font-size:15px;line-height:1.85;color:#475569;margin:0 0 22px;">Dengan hormat, kami ingin menyampaikan informasi penting kepada seluruh alumni. Harap membaca dan memperhatikan informasi berikut ini dengan seksama.</p><div style="background:#f0f4ff;border-left:4px solid #6366f1;padding:18px 22px;border-radius:0 12px 12px 0;margin-bottom:30px;"><p style="font-size:14px;font-weight:700;color:#3730a3;margin:0 0 8px;">&#128204; Detail Penting</p><p style="font-size:14px;color:#4f46e5;margin:0;line-height:1.7;">Isi detail informasi penting Anda di sini. Gunakan bagian ini untuk menonjolkan poin-poin utama yang perlu diperhatikan.</p></div><p style="font-size:15px;line-height:1.85;color:#475569;margin:0 0 36px;">Untuk informasi lebih lanjut, silakan kunjungi portal alumni atau hubungi kami langsung melalui fitur pesan.</p><div style="text-align:center;"><a href="#" style="display:inline-block;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#ffffff;padding:15px 40px;border-radius:12px;font-weight:700;text-decoration:none;font-size:15px;letter-spacing:0.3px;">Lihat Selengkapnya &rarr;</a></div></div><div style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:18px 32px;text-align:center;"><p style="font-size:12px;color:#94a3b8;margin:0;">AlumniLink &nbsp;&bull;&nbsp; <a href="#" style="color:#6366f1;text-decoration:none;">Kelola Email</a> &nbsp;&bull;&nbsp; <a href="#" style="color:#6366f1;text-decoration:none;">Hubungi Kami</a></p></div></div>',
    ],
    [
        'id' => 'newsletter', 'name' => 'Newsletter', 'thumb_type' => 'newsletter',
        'html' => '<div style="max-width:600px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;background:#f1f5f9;"><div style="background:#ffffff;border-bottom:3px solid #6366f1;padding:20px 32px;"><table style="width:100%;border-collapse:collapse;"><tr><td><span style="font-size:20px;font-weight:800;color:#1e293b;letter-spacing:-0.5px;">Alumni<span style="color:#6366f1;">Link</span></span><br><span style="font-size:10px;color:#94a3b8;letter-spacing:1.5px;text-transform:uppercase;">Newsletter</span></td><td style="text-align:right;"><span style="font-size:12px;color:#64748b;">Edisi Bulanan</span></td></tr></table></div><div style="padding:24px 24px 8px;"><div style="background:linear-gradient(135deg,#eef2ff,#faf5ff);border-radius:16px;padding:28px;margin-bottom:20px;"><span style="font-size:11px;font-weight:700;color:#6366f1;text-transform:uppercase;letter-spacing:1.5px;">&#11088; Utama</span><h2 style="font-size:22px;font-weight:800;color:#1e293b;margin:10px 0 12px;line-height:1.3;">Judul Berita Utama Edisi Ini</h2><p style="font-size:14px;line-height:1.8;color:#475569;margin:0 0 18px;">Ringkasan berita atau pengumuman utama edisi ini. Ceritakan hal-hal menarik dan penting yang terjadi di komunitas alumni kita bulan ini.</p><a href="#" style="display:inline-block;background:#6366f1;color:#ffffff;padding:10px 24px;border-radius:8px;font-weight:700;text-decoration:none;font-size:13px;">Baca Selengkapnya &rarr;</a></div><table style="width:100%;border-collapse:separate;border-spacing:8px 0;margin-bottom:16px;"><tr><td style="width:50%;vertical-align:top;background:#ffffff;border-radius:12px;padding:18px;border:1px solid #e2e8f0;"><span style="font-size:10px;font-weight:700;color:#0ea5e9;text-transform:uppercase;letter-spacing:1px;">Karir</span><p style="font-size:14px;font-weight:700;color:#1e293b;margin:8px 0 8px;line-height:1.4;">Peluang Karir Terbaru</p><p style="font-size:13px;color:#64748b;margin:0 0 12px;line-height:1.6;">Temukan lowongan dan peluang karir terkini dari mitra kami.</p><a href="#" style="font-size:12px;color:#6366f1;text-decoration:none;font-weight:600;">Lihat &rarr;</a></td><td style="width:50%;vertical-align:top;background:#ffffff;border-radius:12px;padding:18px;border:1px solid #e2e8f0;"><span style="font-size:10px;font-weight:700;color:#22c55e;text-transform:uppercase;letter-spacing:1px;">Event</span><p style="font-size:14px;font-weight:700;color:#1e293b;margin:8px 0 8px;line-height:1.4;">Acara Mendatang</p><p style="font-size:13px;color:#64748b;margin:0 0 12px;line-height:1.6;">Jangan lewatkan event dan reuni yang akan segera diselenggarakan.</p><a href="#" style="font-size:12px;color:#6366f1;text-decoration:none;font-weight:600;">Daftar &rarr;</a></td></tr></table></div><div style="background:#1e293b;padding:24px 32px;text-align:center;"><p style="color:#94a3b8;font-size:12px;margin:0 0 8px;">&#169; 2026 AlumniLink. Semua hak dilindungi.</p><p style="margin:0;"><a href="#" style="color:#6366f1;text-decoration:none;font-size:12px;">Kelola Email</a> &nbsp;&middot;&nbsp; <a href="#" style="color:#6366f1;text-decoration:none;font-size:12px;">Berhenti Langganan</a></p></div></div>',
    ],
    [
        'id' => 'event', 'name' => 'Event Invite', 'thumb_type' => 'event',
        'html' => '<div style="max-width:600px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;background:#ffffff;"><div style="background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%);padding:52px 32px;text-align:center;overflow:hidden;position:relative;"><div style="position:absolute;top:-30px;right:-30px;width:140px;height:140px;background:rgba(99,102,241,0.15);border-radius:50%;"></div><div style="position:absolute;bottom:-40px;left:-40px;width:180px;height:180px;background:rgba(139,92,246,0.1);border-radius:50%;"></div><div style="position:relative;z-index:1;"><div style="display:inline-block;background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:50px;padding:8px 22px;margin-bottom:22px;"><span style="color:#ffffff;font-size:12px;font-weight:700;letter-spacing:1px;">&#127881; UNDANGAN EKSKLUSIF</span></div><h1 style="color:#ffffff;font-size:28px;font-weight:800;margin:0 0 10px;line-height:1.2;">Nama Acara / Event</h1><p style="color:#94a3b8;font-size:15px;margin:0;">Anda diundang untuk hadir bersama kami</p></div></div><div style="background:#ffffff;padding:40px 32px;"><table style="width:100%;border-collapse:collapse;margin-bottom:32px;"><tr><td style="width:33%;text-align:center;padding:18px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px 0 0 12px;"><div style="font-size:26px;margin-bottom:6px;">&#128197;</div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">Tanggal</div><div style="font-size:14px;font-weight:700;color:#1e293b;">DD Bulan YYYY</div></td><td style="width:33%;text-align:center;padding:18px 12px;background:#f8fafc;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;"><div style="font-size:26px;margin-bottom:6px;">&#9200;</div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">Waktu</div><div style="font-size:14px;font-weight:700;color:#1e293b;">00.00 WIB</div></td><td style="width:33%;text-align:center;padding:18px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:0 12px 12px 0;"><div style="font-size:26px;margin-bottom:6px;">&#128205;</div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">Lokasi</div><div style="font-size:14px;font-weight:700;color:#1e293b;">Nama Tempat</div></td></tr></table><p style="font-size:15px;line-height:1.85;color:#475569;margin:0 0 28px;">Jelaskan detail acara di sini. Ceritakan apa yang akan terjadi, siapa yang akan hadir, dan mengapa event ini penting untuk para alumni.</p><div style="text-align:center;"><a href="#" style="display:inline-block;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#ffffff;padding:15px 44px;border-radius:12px;font-weight:700;text-decoration:none;font-size:15px;">&#127903; Daftar Sekarang</a><br><a href="#" style="display:inline-block;margin-top:12px;font-size:13px;color:#6366f1;text-decoration:none;">+ Tambahkan ke Kalender</a></div></div><div style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:18px 32px;text-align:center;"><p style="font-size:12px;color:#94a3b8;margin:0;">AlumniLink &nbsp;&bull;&nbsp; <a href="#" style="color:#6366f1;text-decoration:none;">Kelola Email</a></p></div></div>',
    ],
    [
        'id' => 'referral', 'name' => 'Referral', 'thumb_type' => 'referral',
        'html' => '<div style="max-width:600px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;background:#ffffff;"><div style="padding:40px 32px;"><div style="text-align:center;margin-bottom:36px;"><div style="display:inline-flex;align-items:center;justify-content:center;width:72px;height:72px;background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:24px;margin-bottom:18px;"><span style="font-size:32px;">&#127873;</span></div><h2 style="font-size:24px;font-weight:800;color:#1e293b;margin:0 0 12px;line-height:1.25;">Ajak Teman, Raih Manfaat Bersama!</h2><p style="font-size:15px;color:#64748b;margin:0;line-height:1.75;">Bagikan AlumniLink kepada rekan Anda dan dapatkan reward eksklusif untuk setiap alumni yang bergabung.</p></div><div style="background:linear-gradient(135deg,#f0f4ff,#faf5ff);border-radius:16px;padding:24px;margin-bottom:28px;text-align:center;"><p style="font-size:12px;font-weight:700;color:#6366f1;margin:0 0 10px;text-transform:uppercase;letter-spacing:1px;">Kode Referral Anda</p><div style="background:#ffffff;border:2px dashed #a5b4fc;border-radius:12px;padding:18px;display:inline-block;min-width:180px;"><span style="font-size:24px;font-weight:800;color:#4f46e5;letter-spacing:5px;font-family:\'Courier New\',monospace;">ALUMNI25</span></div></div><div style="margin-bottom:28px;"><div style="background:#f0fdf4;border-radius:12px;padding:16px 20px;margin-bottom:10px;border-left:4px solid #22c55e;display:flex;align-items:center;gap:12px;"><span style="font-size:20px;">&#9989;</span><div><strong style="color:#166534;font-size:14px;">Langkah 1:</strong> <span style="color:#15803d;font-size:14px;">Bagikan kode atau link referral ke teman</span></div></div><div style="background:#eff6ff;border-radius:12px;padding:16px 20px;margin-bottom:10px;border-left:4px solid #3b82f6;display:flex;align-items:center;gap:12px;"><span style="font-size:20px;">&#128101;</span><div><strong style="color:#1e40af;font-size:14px;">Langkah 2:</strong> <span style="color:#1d4ed8;font-size:14px;">Teman Anda mendaftar menggunakan kode</span></div></div><div style="background:#fef3c7;border-radius:12px;padding:16px 20px;border-left:4px solid #f59e0b;display:flex;align-items:center;gap:12px;"><span style="font-size:20px;">&#127942;</span><div><strong style="color:#92400e;font-size:14px;">Langkah 3:</strong> <span style="color:#b45309;font-size:14px;">Keduanya mendapatkan reward eksklusif!</span></div></div></div><div style="text-align:center;"><a href="#" style="display:inline-block;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#ffffff;padding:15px 44px;border-radius:12px;font-weight:700;text-decoration:none;font-size:15px;">Bagikan Sekarang &rarr;</a></div></div><div style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:18px 32px;text-align:center;"><p style="font-size:12px;color:#94a3b8;margin:0;">AlumniLink &nbsp;&bull;&nbsp; <a href="#" style="color:#6366f1;text-decoration:none;">Kelola Email</a></p></div></div>',
    ],
    [
        'id' => 'welcome', 'name' => 'Welcome Onboarding', 'thumb_type' => 'announcement',
        'html' => '<div style="max-width:600px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;background:#ffffff;border-radius:24px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,0.05);"><div style="background:linear-gradient(135deg,#3b82f6 0%,#2dd4bf 100%);padding:60px 40px;text-align:center;"><h1 style="color:#ffffff;font-size:32px;font-weight:900;margin:0 0 16px;line-height:1.2;letter-spacing:-0.5px;">Selamat Datang!</h1><p style="color:rgba(255,255,255,0.9);font-size:16px;margin:0;line-height:1.6;">Kami sangat senang Anda bergabung bersama keluarga besar AlumniLink.</p></div><div style="padding:40px;"><p style="font-size:16px;line-height:1.8;color:#475569;margin:0 0 24px;">Halo <strong>[Nama Alumni]</strong>,</p><p style="font-size:16px;line-height:1.8;color:#475569;margin:0 0 32px;">Akun Anda telah berhasil dibuat. Melalui portal ini, Anda dapat terhubung kembali dengan teman lama, mendapatkan informasi karir terbaru, hingga memproses legalisir dokumen secara digital dengan cepat.</p><div style="text-align:center;margin-bottom:40px;"><a href="#" style="display:inline-block;background:#0f172a;color:#ffffff;padding:16px 48px;border-radius:16px;font-weight:800;text-decoration:none;font-size:16px;transition:all 0.3s ease;box-shadow:0 8px 20px rgba(15,23,42,0.2);">Jelajahi Portal Sekarang</a></div><table style="width:100%;border-collapse:collapse;"><tr><td style="padding:20px;background:#f8fafc;border-radius:16px;border:1px solid #e2e8f0;"><h3 style="font-size:14px;font-weight:800;color:#1e293b;margin:0 0 8px;text-transform:uppercase;letter-spacing:1px;">Langkah Selanjutnya:</h3><ul style="margin:0;padding-left:20px;color:#64748b;font-size:14px;line-height:1.8;"><li style="margin-bottom:4px;">Lengkapi profil profesional Anda.</li><li style="margin-bottom:4px;">Isi kuesioner Tracer Study.</li><li>Temukan rekan satu angkatan Anda.</li></ul></td></tr></table></div><div style="background:#f1f5f9;padding:24px;text-align:center;"><p style="font-size:12px;color:#94a3b8;margin:0;">&#169; 2026 AlumniLink. Jangan balas email ini.</p></div></div>',
    ],
    [
        'id' => 'invoice', 'name' => 'Billing / Tagihan', 'thumb_type' => 'newsletter',
        'html' => '<div style="max-width:600px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;"><div style="background:#f8fafc;padding:32px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;"><table style="width:100%"><tr><td><span style="font-size:24px;font-weight:900;color:#1e293b;letter-spacing:-1px;">Alumni<span style="color:#2563eb;">Link</span></span></td><td style="text-align:right;"><span style="font-size:12px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:2px;">Invoice</span><br><strong style="color:#0f172a;font-size:16px;">#INV-2026001</strong></td></tr></table></div><div style="padding:40px 32px;"><p style="font-size:15px;line-height:1.6;color:#64748b;margin:0 0 32px;">Terima kasih atas partisipasi Anda dalam layanan kami. Berikut adalah rincian tagihan Anda yang perlu diselesaikan.</p><div style="background:#f8fafc;border-radius:12px;padding:24px;margin-bottom:32px;"><table style="width:100%;border-collapse:collapse;"><tr style="border-bottom:2px solid #e2e8f0;"><th style="text-align:left;padding-bottom:12px;color:#475569;font-size:12px;text-transform:uppercase;letter-spacing:1px;">Deskripsi</th><th style="text-align:right;padding-bottom:12px;color:#475569;font-size:12px;text-transform:uppercase;letter-spacing:1px;">Jumlah</th></tr><tr><td style="padding:16px 0;border-bottom:1px solid #e2e8f0;color:#1e293b;font-weight:600;font-size:15px;">Donasi Pembangunan Gedung</td><td style="padding:16px 0;border-bottom:1px solid #e2e8f0;text-align:right;color:#1e293b;font-weight:700;font-size:15px;">Rp 500.000</td></tr><tr><td style="padding:16px 0;border-bottom:1px solid #e2e8f0;color:#1e293b;font-weight:600;font-size:15px;">Biaya Administrasi</td><td style="padding:16px 0;border-bottom:1px solid #e2e8f0;text-align:right;color:#1e293b;font-weight:700;font-size:15px;">Rp 5.000</td></tr><tr><td style="padding:16px 0;color:#0f172a;font-weight:900;font-size:18px;">Total Tagihan</td><td style="padding:16px 0;text-align:right;color:#2563eb;font-weight:900;font-size:20px;">Rp 505.000</td></tr></table></div><div style="text-align:center;"><a href="#" style="display:inline-block;background:#2563eb;color:#ffffff;padding:16px 40px;border-radius:12px;font-weight:800;text-decoration:none;font-size:15px;">Bayar Sekarang &rarr;</a></div></div><div style="background:#1e293b;padding:24px;text-align:center;"><p style="font-size:12px;color:#94a3b8;margin:0;">Bila Anda memiliki pertanyaan, silakan hubungi tim kami di support@alumnilink.com.</p></div></div>',
    ],
    [
        'id' => 'survey', 'name' => 'Tracer Study / Survei', 'thumb_type' => 'event',
        'html' => '<div style="max-width:600px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;background:#ffffff;border:1px solid #e2e8f0;border-radius:24px;overflow:hidden;"><div style="background:#f8fafc;padding:48px 32px;text-align:center;border-bottom:1px solid #e2e8f0;"><div style="display:inline-flex;width:64px;height:64px;background:#dcfce7;border-radius:20px;align-items:center;justify-content:center;margin-bottom:24px;"><span style="font-size:28px;">&#128221;</span></div><h2 style="font-size:24px;font-weight:800;color:#0f172a;margin:0 0 12px;">Waktunya Mengisi Tracer Study</h2><p style="font-size:15px;color:#64748b;margin:0;line-height:1.6;">Suara Anda sangat berarti untuk kemajuan dan akreditasi almamater kita tercinta.</p></div><div style="padding:40px 32px;"><p style="font-size:15px;line-height:1.8;color:#475569;margin:0 0 32px;">Halo Alumni! Data tracer study digunakan untuk mengevaluasi kualitas pembelajaran dan relevansi kurikulum dengan dunia kerja saat ini. Kami mohon kesediaan Anda meluangkan waktu 5 menit untuk mengisinya.</p><div style="text-align:center;"><a href="#" style="display:inline-block;background:#16a34a;color:#ffffff;padding:16px 48px;border-radius:16px;font-weight:800;text-decoration:none;font-size:16px;box-shadow:0 10px 25px rgba(22,163,74,0.3);transition:all 0.3s ease;">Mulai Isi Kuesioner</a></div></div></div>',
    ],
];

// Safe JSON for JS embedding
$templates_json        = json_encode($email_templates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
$custom_layouts_json   = json_encode($custom_layouts_raw, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
?>

<style>
/* ══════════════════════════════════════════════════
   BROADCAST COMPOSE — Gmail-Inspired Design System
══════════════════════════════════════════════════ */

/* Compose Window */
.compose-win {
    background: #fff;
    border: 1.5px solid #e2e8f0;
    border-radius: 1.25rem;
    overflow: hidden;
    box-shadow: 0 4px 24px rgba(0,0,0,0.07);
}
.compose-win-header {
    background: #f8fafc;
    padding: 10px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid #f1f5f9;
}
.compose-win-title {
    font-size: 13px;
    font-weight: 700;
    color: #475569;
}

/* Field rows (To / Subject) */
.cfield {
    display: flex;
    align-items: flex-start;
    padding: 10px 18px;
    border-bottom: 1px solid #f1f5f9;
    gap: 10px;
    transition: background 0.15s;
}
.cfield:focus-within { background: #fafbff; }
.cfield label {
    font-size: 13px;
    color: #94a3b8;
    font-weight: 600;
    min-width: 64px;
    padding-top: 3px;
    flex-shrink: 0;
}
.cfield input {
    flex: 1;
    border: none;
    outline: none;
    font-size: 14px;
    color: #1e293b;
    background: transparent;
    font-family: inherit;
}
.cfield input::placeholder { color: #cbd5e1; }
.to-display {
    flex: 1;
    font-size: 13.5px;
    color: #374151;
    cursor: default;
}
.to-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    background: #eef2ff;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    color: #4f46e5;
    border: 1px solid #c7d2fe;
}

/* Editor area */
.editor-wrapper { position: relative; }

#compose-editor {
    min-height: 280px;
    max-height: 460px;
    overflow-y: auto;
    padding: 20px 20px;
    font-size: 14.5px;
    line-height: 1.75;
    color: #374151;
    outline: none;
    word-break: break-word;
}
#compose-editor:empty::before {
    content: attr(data-placeholder);
    color: #cbd5e1;
    pointer-events: none;
    display: block;
}
#compose-editor img { max-width: 100%; border-radius: 6px; }
#compose-editor a { color: #6366f1; }
#compose-editor blockquote {
    border-left: 3px solid #6366f1;
    margin: 12px 0;
    padding: 8px 16px;
    color: #64748b;
    background: #f8fafc;
    border-radius: 0 8px 8px 0;
}

/* Drag over state */
#compose-editor.drag-over {
    background: #f0f4ff;
    outline: 2px dashed #6366f1;
    outline-offset: -4px;
}

/* HTML editor (code mode) */
#html-editor {
    min-height: 280px;
    max-height: 460px;
    width: 100%;
    padding: 18px 20px;
    font-family: 'Consolas','Monaco','Courier New',monospace;
    font-size: 12.5px;
    line-height: 1.65;
    background: #1e293b;
    color: #e2e8f0;
    border: none;
    outline: none;
    resize: none;
    overflow-y: auto;
    tab-size: 2;
    display: none;
}

/* ── Toolbar (Gmail-style bottom) ─────────────── */
.compose-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 6px 10px;
    border-top: 1px solid #f1f5f9;
    background: #fafafa;
    gap: 4px;
    flex-wrap: wrap;
}
.toolbar-left { display: flex; align-items: center; gap: 2px; flex-wrap: wrap; }

.tb {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    cursor: pointer;
    color: #64748b;
    background: transparent;
    border: none;
    transition: background 0.15s, color 0.15s;
    font-size: 14px;
    flex-shrink: 0;
}
.tb:hover { background: #f1f5f9; color: #1e293b; }
.tb.active { background: #eef2ff; color: #6366f1; }
.tb svg { width: 17px; height: 17px; stroke-width: 1.75; }

/* Toolbar tooltips */
.tb::after {
    content: attr(data-tip);
    position: absolute;
    bottom: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%);
    background: #1e293b;
    color: #fff;
    font-size: 11px;
    font-weight: 500;
    padding: 4px 8px;
    border-radius: 6px;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.15s;
    z-index: 50;
}
.tb:hover::after { opacity: 1; }

/* ── Formatting Popover ────────────────────────── */
#fmt-popover {
    position: absolute;
    bottom: 54px;
    left: 8px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 14px;
    box-shadow: 0 12px 40px rgba(0,0,0,0.14);
    z-index: 300;
    width: 294px;
    display: none;
}
#fmt-popover.open { display: block; }

.fmt-row { display: flex; align-items: center; gap: 4px; padding: 3px 0; }
.fmt-divider { height: 1px; background: #f1f5f9; margin: 6px 0; }
.fmt-label { font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .8px; padding: 2px 0; }

.fbtn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
    border: none;
    background: transparent;
    color: #475569;
    font-size: 13px;
    font-weight: 700;
    transition: all 0.15s;
}
.fbtn:hover { background: #f1f5f9; color: #1e293b; }
.fbtn.w-auto { width: auto; padding: 0 10px; }

/* Color swatches */
.swatch-row { display: flex; flex-wrap: wrap; gap: 4px; }
.swatch {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    cursor: pointer;
    border: 2px solid transparent;
    transition: transform 0.15s, border-color 0.15s;
    flex-shrink: 0;
}
.swatch:hover { transform: scale(1.2); border-color: #475569; }

/* ── Emoji Picker ──────────────────────────────── */
#emoji-picker {
    position: absolute;
    bottom: 54px;
    left: 116px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 12px;
    box-shadow: 0 12px 40px rgba(0,0,0,0.14);
    z-index: 300;
    width: 262px;
    display: none;
}
#emoji-picker.open { display: block; }
.emoji-grid { display: flex; flex-wrap: wrap; gap: 2px; }
.ej {
    width: 32px; height: 32px;
    border: none; background: transparent;
    cursor: pointer; font-size: 18px;
    border-radius: 6px; transition: background 0.1s;
    display: flex; align-items: center; justify-content: center;
}
.ej:hover { background: #f1f5f9; }

/* ── Attachment chips ──────────────────────────── */
#attachment-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding: 0 14px 10px;
}
.att-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 10px 5px 9px;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    font-size: 12px;
    color: #475569;
    max-width: 220px;
}
.att-chip .att-name { max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.att-rm { cursor: pointer; color: #94a3b8; background: none; border: none; font-size: 15px; line-height: 1; padding: 0; transition: color .15s; }
.att-rm:hover { color: #ef4444; }

/* Error bar */
#compose-error {
    margin: 0 14px 8px;
    padding: 8px 14px;
    background: #fff1f2;
    border: 1px solid #fca5a5;
    border-radius: 10px;
    font-size: 12.5px;
    color: #be123c;
    display: none;
}

/* ── Modal System ──────────────────────────────── */
.mo {
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,0.45);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    backdrop-filter: blur(5px);
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s;
}
.mo.open { opacity: 1; pointer-events: all; }
.mo-box {
    background: #fff;
    border-radius: 20px;
    width: 100%;
    box-shadow: 0 30px 70px rgba(0,0,0,0.22);
    transform: scale(0.94) translateY(12px);
    transition: transform 0.22s;
    overflow: hidden;
}
.mo.open .mo-box { transform: scale(1) translateY(0); }

/* Layout picker modal */
#layout-modal .mo-box { max-width: 880px; }
.lp-body { display: flex; height: 460px; }
.lp-left { width: 52%; border-right: 1px solid #f1f5f9; display: flex; flex-direction: column; overflow: hidden; }
.lp-tabs { display: flex; border-bottom: 1px solid #f1f5f9; }
.lp-tab {
    flex: 1; padding: 12px; text-align: center;
    font-size: 13px; font-weight: 600; color: #94a3b8;
    cursor: pointer; border-bottom: 2px solid transparent;
    transition: all 0.2s;
}
.lp-tab.active { color: #6366f1; border-bottom-color: #6366f1; }
.lp-grid-wrap { flex: 1; overflow-y: auto; padding: 12px; }
.lp-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(118px, 1fr));
    gap: 10px;
}
.lp-card {
    cursor: pointer;
    border-radius: 12px;
    overflow: hidden;
    border: 2px solid #e2e8f0;
    transition: all 0.2s;
    background: white;
}
.lp-card:hover { border-color: #a5b4fc; box-shadow: 0 4px 14px rgba(99,102,241,0.15); }
.lp-card.sel { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.2); }
.lp-thumb { height: 88px; background: #f8fafc; display: flex; align-items: center; justify-content: center; padding: 8px; }
.lp-name { padding: 7px 6px; font-size: 11px; font-weight: 600; text-align: center; color: #475569; border-top: 1px solid #f1f5f9; }
.lp-right { flex: 1; overflow: auto; background: #f8fafc; display: flex; flex-direction: column; }
.lp-preview-label { padding: 12px 16px; font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; border-bottom: 1px solid #f1f5f9; background: white; }
#lp-iframe { flex: 1; border: none; background: white; }

/* ── Channel cards ─────────────────────────────── */
.ch-card { position: relative; cursor: pointer; }
.ch-card input { position: absolute; opacity: 0; width: 0; height: 0; }
.ch-inner { padding: 13px; border-radius: 14px; border: 2px solid #e2e8f0; background: white; transition: all 0.2s; }
.ch-card.app input:checked ~ .ch-inner { border-color: #6366f1; background: #eef2ff; }
.ch-card.em  input:checked ~ .ch-inner { border-color: #0ea5e9; background: #f0f9ff; }

/* Priority toggle */
.pri { position: relative; cursor: pointer; }
.pri input { position: absolute; opacity: 0; width: 0; height: 0; }
.pri-inner { padding: 7px 14px; border-radius: 999px; border: 2px solid #e2e8f0; font-size: 12.5px; font-weight: 600; background: white; transition: all 0.2s; display: flex; align-items: center; gap: 5px; color: #64748b; }
.pri.n  input:checked ~ .pri-inner { border-color: #22c55e; background: #f0fdf4; color: #16a34a; }
.pri.i  input:checked ~ .pri-inner { border-color: #3b82f6; background: #eff6ff; color: #1d4ed8; }
.pri.u  input:checked ~ .pri-inner { border-color: #f43f5e; background: #fff1f2; color: #be123c; }

/* Send button */
.send-grp { display: flex; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 14px rgba(99,102,241,0.28); }
.send-main { flex: 1; padding: 13px 18px; background: linear-gradient(135deg,#6366f1,#8b5cf6); color: white; border: none; font-size: 14px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: opacity .2s; }
.send-main:hover { opacity: .92; }
.send-main:disabled { opacity: .65; cursor: not-allowed; }

/* History card */
.hcard { border: 1.5px solid transparent; border-radius: 16px; padding: 13px 14px; transition: all 0.22s; }
.hcard:hover { border-color: rgba(99,102,241,.2); background: #fafbff; }

/* Responsive: stack on mobile */
@media (max-width: 1024px) {
    .lp-body { flex-direction: column; height: auto; max-height: 80vh; }
    .lp-left { width: 100%; border-right: none; border-bottom: 1px solid #f1f5f9; }
    .lp-right { height: 260px; }
}
@media (max-width: 640px) {
    #fmt-popover, #emoji-picker { left: 4px; right: 4px; width: auto; }
    .compose-toolbar { padding: 4px 6px; }
}
</style>

<section class="space-y-5" id="broadcast-center">

    <!-- ── Page Header ──────────────────────────── -->
    <header class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl md:text-3xl font-extrabold outfit tracking-tight text-slate-800 flex items-center gap-3">
                <span class="w-10 h-10 bg-gradient-to-br from-indigo-500 to-violet-600 rounded-2xl flex items-center justify-center shadow-lg shadow-indigo-200">
                    <i data-lucide="megaphone" class="w-5 h-5 text-white"></i>
                </span>
                Broadcast Center
            </h1>
            <p class="text-slate-500 text-sm mt-1">Compose dan kirim pesan ke alumni via notifikasi aplikasi &amp; email</p>
        </div>
        <div class="flex flex-wrap gap-2 text-xs font-semibold">
            <span class="px-3 py-1.5 bg-indigo-50 text-indigo-700 rounded-xl border border-indigo-100 flex items-center gap-1.5">
                <i data-lucide="users" class="w-3.5 h-3.5"></i> <?= number_format($total_alumni) ?> alumni
            </span>
            <span class="px-3 py-1.5 bg-sky-50 text-sky-700 rounded-xl border border-sky-100 flex items-center gap-1.5">
                <i data-lucide="mail-check" class="w-3.5 h-3.5"></i> <?= number_format($email_opt_in) ?> opt-in email
            </span>
            <span class="px-3 py-1.5 bg-slate-100 text-slate-600 rounded-xl border border-slate-200 flex items-center gap-1.5">
                <i data-lucide="send" class="w-3.5 h-3.5"></i> <?= number_format($total_bc) ?> terkirim
            </span>
        </div>
    </header>

    <!-- ── Alerts ───────────────────────────────── -->
    <?php if (!empty($_GET['success'])): ?>
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 rounded-2xl flex items-start gap-3">
            <i data-lucide="check-circle-2" class="w-5 h-5 mt-0.5 flex-shrink-0 text-emerald-600"></i>
            <div>
                <?php if ($_GET['success'] === 'sent'): ?>
                    <p class="font-bold">Broadcast berhasil dikirim!</p>
                    <p class="text-sm mt-0.5">Notifikasi app: <strong><?= intval($_GET['app'] ?? 0) ?></strong> &nbsp;·&nbsp; Email di-queue: <strong><?= intval($_GET['queued'] ?? 0) ?></strong></p>
                <?php elseif ($_GET['success'] === 'deleted'): ?>
                    <p class="font-bold">Broadcast dihapus.</p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['error'])): ?>
        <div class="bg-red-50 border border-red-200 text-red-800 p-4 rounded-2xl flex items-start gap-3">
            <i data-lucide="alert-circle" class="w-5 h-5 mt-0.5 flex-shrink-0 text-red-500"></i>
            <p class="font-semibold text-sm"><?php $errs = ['missing_fields'=>'Judul dan isi pesan tidak boleh kosong.','no_channel'=>'Pilih minimal satu channel pengiriman.','no_external_emails'=>'Harap masukkan minimal satu email pada penerima eksternal.','db_error'=>'Terjadi kesalahan database.']; echo e($errs[$_GET['error']] ?? 'Terjadi kesalahan.'); ?></p>
        </div>
    <?php endif; ?>

    <!-- ── Main Grid ─────────────────────────────── -->
    <div class="grid grid-cols-1 xl:grid-cols-7 gap-5">

        <!-- ═════ LEFT: Compose (4 cols) ══════════ -->
        <div class="xl:col-span-4 space-y-4">

            <!-- COMPOSE WINDOW -->
            <form id="broadcastForm"
                  action="handlers/admin_broadcast_handler.php?action=send"
                  method="POST"
                  enctype="multipart/form-data"
                  onsubmit="return handleSubmit(event)">

                <?php csrf_field(); ?>
                <input type="hidden" name="body_html" id="body_html">
                <input type="hidden" name="target_type"  id="h-target-type"  value="all">
                <input type="hidden" name="target_value" id="h-target-value" value="">
                <input type="hidden" name="priority"     id="h-priority"     value="normal">

                <div class="compose-win">
                    <!-- Title bar -->
                    <div class="compose-win-header">
                        <span class="compose-win-title flex items-center gap-2">
                            <i data-lucide="edit-3" class="w-3.5 h-3.5 text-slate-400"></i>
                            Pesan Baru
                        </span>
                        <div class="flex items-center gap-2 text-slate-400 text-xs font-medium">
                            <span id="editor-mode-badge" class="px-2 py-0.5 bg-indigo-50 text-indigo-600 rounded-full font-semibold">GUI</span>
                            <span id="char-display">0 karakter</span>
                        </div>
                    </div>

                    <!-- To row (dynamic target display) -->
                    <div class="cfield">
                        <label>Kepada</label>
                        <div class="to-display flex items-center flex-wrap gap-2">
                            <span class="to-badge" id="to-badge">
                                <i data-lucide="users" class="w-3 h-3"></i>
                                <span id="to-label">Semua Alumni (<?= number_format($total_alumni) ?> orang)</span>
                            </span>
                        </div>
                    </div>

                    <!-- Subject row -->
                    <div class="cfield">
                        <label for="bc-subject">Subjek</label>
                        <input type="text" name="title" id="bc-subject" required
                               placeholder="Judul pengumuman..."
                               autocomplete="off" maxlength="200">
                    </div>

                    <!-- Editor wrapper (relative for popovers) -->
                    <div class="editor-wrapper" style="position:relative;">
                        <!-- Formatting popover -->
                        <div id="fmt-popover" role="dialog" aria-label="Opsi format teks">
                            <div class="fmt-label">Format Teks</div>
                            <div class="fmt-row">
                                <button type="button" class="fbtn" style="font-weight:700;" onclick="fmt('bold')" title="Bold"><b>B</b></button>
                                <button type="button" class="fbtn" style="font-style:italic;" onclick="fmt('italic')" title="Italic"><i>I</i></button>
                                <button type="button" class="fbtn" style="text-decoration:underline;" onclick="fmt('underline')" title="Underline"><u>U</u></button>
                                <button type="button" class="fbtn" style="text-decoration:line-through;" onclick="fmt('strikeThrough')" title="Strikethrough"><s>S</s></button>
                                <span style="flex:1"></span>
                                <button type="button" class="fbtn" onclick="fmt('removeFormat')" title="Hapus Format" style="font-size:11px;color:#94a3b8;">✕ fmt</button>
                            </div>
                            <div class="fmt-divider"></div>
                            <div class="fmt-label">Ukuran</div>
                            <div class="fmt-row">
                                <button type="button" class="fbtn w-auto" onclick="setFontSize('1')" style="font-size:10px;">Kecil</button>
                                <button type="button" class="fbtn w-auto" onclick="setFontSize('3')" style="font-size:13px;">Normal</button>
                                <button type="button" class="fbtn w-auto" onclick="setFontSize('5')" style="font-size:16px;">Besar</button>
                                <button type="button" class="fbtn w-auto" onclick="setFontSize('7')" style="font-size:19px;">Jumbo</button>
                            </div>
                            <div class="fmt-divider"></div>
                            <div class="fmt-label">Heading</div>
                            <div class="fmt-row">
                                <button type="button" class="fbtn w-auto" onclick="execInsertHTML('<h1 style=\'font-size:26px;font-weight:800;color:#1e293b;\'>Heading 1</h1>')" style="font-size:15px;font-weight:800;">H1</button>
                                <button type="button" class="fbtn w-auto" onclick="execInsertHTML('<h2 style=\'font-size:20px;font-weight:700;color:#1e293b;\'>Heading 2</h2>')" style="font-size:13px;font-weight:700;">H2</button>
                                <button type="button" class="fbtn w-auto" onclick="execInsertHTML('<h3 style=\'font-size:16px;font-weight:700;color:#374151;\'>Heading 3</h3>')" style="font-size:12px;font-weight:700;">H3</button>
                            </div>
                            <div class="fmt-divider"></div>
                            <div class="fmt-label">Warna Teks</div>
                            <div class="swatch-row" id="text-colors"></div>
                            <div class="fmt-label" style="margin-top:8px;">Sorot</div>
                            <div class="swatch-row" id="hl-colors"></div>
                            <div class="fmt-divider"></div>
                            <div class="fmt-label">Perataan &amp; List</div>
                            <div class="fmt-row">
                                <button type="button" class="fbtn" onclick="fmt('justifyLeft')" title="Kiri">&#8676;</button>
                                <button type="button" class="fbtn" onclick="fmt('justifyCenter')" title="Tengah">&#8660;</button>
                                <button type="button" class="fbtn" onclick="fmt('justifyRight')" title="Kanan">&#8677;</button>
                                <button type="button" class="fbtn" onclick="fmt('insertUnorderedList')" title="Bullet">&#8226;</button>
                                <button type="button" class="fbtn" onclick="fmt('insertOrderedList')" title="Nomor">1.</button>
                                <button type="button" class="fbtn" onclick="execInsertHTML('<blockquote style=\'border-left:3px solid #6366f1;margin:12px 0;padding:8px 16px;color:#64748b;background:#f8fafc;border-radius:0 8px 8px 0;\'>Kutipan</blockquote>')" title="Kutipan">"</button>
                            </div>
                        </div>

                        <!-- Emoji picker -->
                        <div id="emoji-picker" role="dialog" aria-label="Pilih emoji">
                            <p style="font-size:11px;font-weight:700;color:#94a3b8;margin:0 0 8px;text-transform:uppercase;letter-spacing:.8px;">Emoji</p>
                            <div class="emoji-grid" id="emoji-grid"></div>
                        </div>

                        <!-- WYSIWYG Editor -->
                        <div id="compose-editor"
                             contenteditable="true"
                             spellcheck="true"
                             data-placeholder="Tulis pesan Anda atau pilih layout dari toolbar...">
                        </div>

                        <!-- HTML / Code editor -->
                        <textarea id="html-editor" spellcheck="false" aria-label="HTML editor"></textarea>
                    </div>

                    <!-- Error bar -->
                    <div id="compose-error" role="alert"></div>

                    <!-- Attachment chips -->
                    <div id="attachment-list"></div>

                    <!-- ─── Gmail-style Bottom Toolbar ─────── -->
                    <div class="compose-toolbar" style="position:relative;">
                        <div class="toolbar-left">
                            <!-- Aa: Formatting -->
                            <button type="button" class="tb" id="tb-fmt" data-tip="Format Teks" onclick="toggleFmt()"
                                    aria-label="Buka opsi format teks">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><text x="3" y="16" font-family="serif" font-size="15" font-weight="700" stroke="none" fill="currentColor">Aa</text></svg>
                            </button>

                            <!-- Attachment -->
                            <button type="button" class="tb" data-tip="Lampirkan File" onclick="document.getElementById('file-input').click()"
                                    aria-label="Lampirkan file">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/></svg>
                            </button>

                            <!-- Link -->
                            <button type="button" class="tb" data-tip="Sisipkan Tautan" onclick="insertLinkModal()"
                                    aria-label="Sisipkan tautan">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
                            </button>

                            <!-- Emoji -->
                            <button type="button" class="tb" id="tb-emoji" data-tip="Emoji" onclick="toggleEmoji()"
                                    aria-label="Pilih emoji">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
                            </button>

                            <!-- Image -->
                            <button type="button" class="tb" data-tip="Sisipkan Gambar" onclick="openMo('img-modal')"
                                    aria-label="Sisipkan gambar">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21,15 16,10 5,21"/></svg>
                            </button>

                            <!-- Layout -->
                            <button type="button" class="tb" data-tip="Pilih Layout" onclick="openLayoutPicker()"
                                    aria-label="Pilih layout email">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="18" height="5" rx="1"/><rect x="3" y="11" width="8" height="10" rx="1"/><rect x="14" y="11" width="7" height="10" rx="1"/></svg>
                            </button>

                            <!-- HTML mode -->
                            <button type="button" class="tb" id="tb-html" data-tip="Mode HTML" onclick="toggleMode()"
                                    aria-label="Beralih ke mode HTML">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="16,18 22,12 16,6"/><polyline points="8,6 2,12 8,18"/></svg>
                            </button>

                            <div style="width:1px;height:22px;background:#e2e8f0;margin:0 4px;"></div>

                            <!-- Horizontal Rule -->
                            <button type="button" class="tb" data-tip="Garis Pemisah" onclick="fmt('insertHorizontalRule')"
                                    aria-label="Sisipkan garis pemisah">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="9" y2="6"/><line x1="3" y1="18" x2="9" y2="18"/></svg>
                            </button>

                            <!-- Indent -->
                            <button type="button" class="tb" data-tip="Inden" onclick="fmt('indent')"
                                    aria-label="Inden teks">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/><polyline points="8,8 12,12 8,16"/></svg>
                            </button>
                        </div>

                        <!-- Right: Clear -->
                        <button type="button" class="tb hover:!bg-red-50 hover:!text-red-500" data-tip="Hapus Konten" onclick="clearContent()"
                                aria-label="Hapus seluruh konten editor">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="3,6 5,6 21,6"/><path d="M19,6l-1,14a2,2 0 01-2,2H8a2,2 0 01-2-2L5,6"/><path d="M10,11v6"/><path d="M14,11v6"/><path d="M9,6V4h6v2"/></svg>
                        </button>
                    </div>
                    <!-- /toolbar -->

                </div>
                <!-- /compose-win -->

                <!-- Hidden file input -->
                <input type="file" id="file-input" multiple style="display:none"
                       accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.zip,.txt"
                       onchange="handleFiles(this.files)">

                <!-- ─── Delivery Config ─────────────── -->
                <div class="glass rounded-2xl p-5 space-y-4">
                    <h3 class="text-sm font-bold text-slate-700 flex items-center gap-2">
                        <i data-lucide="settings-2" class="w-4 h-4 text-indigo-500"></i>
                        Pengaturan Pengiriman
                    </h3>

                    <!-- Channels -->
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2.5">Channel</label>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="ch-card app">
                                <input type="checkbox" name="ch_app" value="1" checked id="ch-app">
                                <div class="ch-inner">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-8 h-8 bg-indigo-100 rounded-xl flex items-center justify-center flex-shrink-0">
                                            <i data-lucide="bell" class="w-4 h-4 text-indigo-600"></i>
                                        </div>
                                        <div>
                                            <p class="font-bold text-sm text-slate-800 leading-tight">Notifikasi App</p>
                                            <p class="text-xs text-slate-500 mt-0.5">Real-time di bell icon</p>
                                        </div>
                                    </div>
                                    <p class="text-xs text-indigo-600 font-semibold mt-2 ml-10"><?= number_format($total_alumni) ?> alumni</p>
                                </div>
                            </label>
                            <label class="ch-card em">
                                <input type="checkbox" name="ch_email" value="1" id="ch-email">
                                <div class="ch-inner">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-8 h-8 bg-sky-100 rounded-xl flex items-center justify-center flex-shrink-0">
                                            <i data-lucide="mail" class="w-4 h-4 text-sky-600"></i>
                                        </div>
                                        <div>
                                            <p class="font-bold text-sm text-slate-800 leading-tight">Email</p>
                                            <p class="text-xs text-slate-500 mt-0.5">Masuk ke inbox</p>
                                        </div>
                                    </div>
                                    <p class="text-xs text-sky-600 font-semibold mt-2 ml-10"><?= number_format($email_opt_in) ?> opt-in</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Priority -->
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2.5">Prioritas</label>
                        <div class="flex flex-wrap gap-2">
                            <label class="pri n">
                                <input type="radio" name="priority" value="normal" checked onchange="document.getElementById('h-priority').value=this.value">
                                <div class="pri-inner">
                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"/></svg>
                                    Normal
                                </div>
                            </label>
                            <label class="pri i">
                                <input type="radio" name="priority" value="info" onchange="document.getElementById('h-priority').value=this.value">
                                <div class="pri-inner">
                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                    Info
                                </div>
                            </label>
                            <label class="pri u">
                                <input type="radio" name="priority" value="urgent" onchange="document.getElementById('h-priority').value=this.value">
                                <div class="pri-inner">
                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><polygon points="13,2 3,14 12,14 11,22 21,10 12,10 13,2"/></svg>
                                    Urgent
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Target -->
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2.5">Target Penerima Database</label>
                        <div class="grid grid-cols-2 gap-3 mb-4">
                            <div>
                                <select id="target-type" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm bg-white focus:ring-2 focus:ring-indigo-400 outline-none"
                                        onchange="handleTargetTypeChange()">
                                    <option value="all">Semua Alumni</option>
                                    <option value="major">Per Program Studi</option>
                                    <option value="year">Per Angkatan</option>
                                    <option value="role">Semua Pengguna</option>
                                    <option value="external">Hanya Penerima Eksternal</option>
                                </select>
                            </div>
                            <div id="target-val-wrap" class="hidden">
                                <select id="target-major" class="hidden w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm bg-white focus:ring-2 focus:ring-indigo-400 outline-none" onchange="setTargetValue(this.value, this.options[this.selectedIndex].text)">
                                    <?php foreach ($majors_list as $m): ?>
                                        <option value="<?= htmlspecialchars($m->major_code) ?>"><?= htmlspecialchars($m->major_name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select id="target-year" class="hidden w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm bg-white focus:ring-2 focus:ring-indigo-400 outline-none" onchange="setTargetValue(this.value)">
                                    <?php foreach ($years_list as $y): ?>
                                        <option value="<?= e($y) ?>">Angkatan <?= e($y) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2.5">Penerima Eksternal Tambahan</label>
                        <div class="bg-slate-50 p-4 rounded-xl border border-slate-200">
                            <p class="text-xs text-slate-500 mb-3">Paste list email (pisahkan dengan koma/baris baru) atau upload file CSV/TXT.</p>
                            <textarea aria-label="email1@example.com, email2@example.com" id="ext-email-input" class="w-full h-20 px-3 py-2 rounded-lg border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-400 outline-none mb-2" placeholder="email1@example.com, email2@example.com"></textarea>
                            <div class="flex items-center justify-between text-xs text-slate-500 mt-2">
                                <span id="ext-email-count" class="font-semibold text-slate-600">0 email terekstrak</span>
                                <label class="cursor-pointer text-indigo-600 hover:text-indigo-800 font-semibold flex items-center gap-1">
                                    <i data-lucide="upload" class="w-3.5 h-3.5"></i>
                                    Upload File
                                    <input type="file" accept=".csv,.txt" class="hidden" onchange="parseExternalEmails(this)">
                                </label>
                            </div>
                            <input type="hidden" name="external_emails" id="h-external-emails" value="[]">
                        </div>
                    </div>

                    <!-- Send button -->
                    <div class="pt-2">
                        <div class="send-grp">
                            <button type="submit" id="btnSend" class="send-main">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                                Kirim Broadcast
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- ═════ RIGHT: History (3 cols) ══════════ -->
        <div class="xl:col-span-3 space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-bold text-slate-700 outfit flex items-center gap-2">
                    <i data-lucide="history" class="w-4 h-4 text-slate-400"></i>
                    Riwayat Broadcast
                </h2>
                <span class="text-xs text-slate-400"><?= number_format($history_count) ?> total</span>
            </div>

            <!-- Filters -->
            <form method="GET" action="index.php" class="glass p-3.5 rounded-2xl space-y-2.5 border border-slate-100">
                <input type="hidden" name="page" value="admin_broadcast">
                
                <!-- Search input -->
                <div class="relative">
                    <input aria-label="Cari judul/isi" type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cari judul/isi..."
                           class="w-full pl-8 pr-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-2 focus:ring-indigo-400 outline-none">
                    <i data-lucide="search" class="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-1/2 -translate-y-1/2"></i>
                </div>
                
                <div class="grid grid-cols-2 gap-2">
                    <!-- Priority filter -->
                    <select aria-label="Filter Prioritas" name="priority" class="px-2 py-2 rounded-xl border border-slate-200 text-xs bg-white focus:ring-2 focus:ring-indigo-400 outline-none" onchange="this.form.submit()">
                        <option value="">Semua Prioritas</option>
                        <option value="normal" <?= $f_priority === 'normal' ? 'selected' : '' ?>>Normal</option>
                        <option value="info" <?= $f_priority === 'info' ? 'selected' : '' ?>>Info</option>
                        <option value="urgent" <?= $f_priority === 'urgent' ? 'selected' : '' ?>>🔴 Urgent</option>
                    </select>
                    
                    <!-- Channel filter -->
                    <select aria-label="Filter Channel" name="channel" class="px-2 py-2 rounded-xl border border-slate-200 text-xs bg-white focus:ring-2 focus:ring-indigo-400 outline-none" onchange="this.form.submit()">
                        <option value="">Semua Channel</option>
                        <option value="app" <?= $f_channel === 'app' ? 'selected' : '' ?>>🔔 App</option>
                        <option value="email" <?= $f_channel === 'email' ? 'selected' : '' ?>>✉ Email</option>
                    </select>
                </div>
                
                <?php if ($search !== '' || $f_priority !== '' || $f_channel !== ''): ?>
                    <a href="index.php?page=admin_broadcast" class="block text-center text-[10px] text-indigo-600 hover:text-indigo-800 font-bold transition-colors">Reset Filter</a>
                <?php endif; ?>
            </form>

            <?php if (empty($broadcasts)): ?>
                <div class="glass rounded-3xl p-12 text-center">
                    <i data-lucide="inbox" class="w-12 h-12 text-slate-200 mx-auto mb-3"></i>
                    <p class="text-slate-400 text-sm">Belum ada broadcast atau filter tidak cocok.</p>
                </div>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($broadcasts as $b):
                        $chs = explode(',', $b->channels ?? 'app');
                        $pc  = ['normal'=>['bg'=>'bg-slate-100','text'=>'text-slate-600','lbl'=>'Normal'], 'info'=>['bg'=>'bg-blue-100','text'=>'text-blue-700','lbl'=>'Info'], 'urgent'=>['bg'=>'bg-red-100','text'=>'text-red-700','lbl'=>'🔴 Urgent']];
                        $p   = $pc[$b->priority ?? 'normal'] ?? $pc['normal'];
                    ?>
                        <div class="hcard glass group cursor-pointer" onclick="if(!event.target.closest('button')) viewBroadcastDetails(<?= e($b->id) ?>)">
                            <div class="flex items-start justify-between gap-2 mb-1.5">
                                <div class="flex-1 min-w-0">
                                    <div class="flex flex-wrap gap-1 mb-1.5">
                                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= e($p['bg'].' '.$p['text']) ?>"><?= e($p['lbl']) ?></span>
                                        <?php if (in_array('app',   $chs)): ?><span class="text-[10px] font-bold px-2 py-0.5 bg-indigo-100 text-indigo-700 rounded-full">🔔 App</span><?php endif; ?>
                                        <?php if (in_array('email', $chs)): ?><span class="text-[10px] font-bold px-2 py-0.5 bg-sky-100 text-sky-700 rounded-full">✉ Email</span><?php endif; ?>
                                    </div>
                                    <p class="font-bold text-sm text-slate-800 truncate"><?= htmlspecialchars($b->title) ?></p>
                                </div>
                                <div class="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0">
                                    <button onclick="viewBroadcastDetails(<?= e($b->id) ?>)"
                                            class="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all"
                                            aria-label="Detail broadcast <?= htmlspecialchars($b->title) ?>">
                                        <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                                    </button>
                                    <button onclick="confirmDelete(event,'handlers/admin_broadcast_handler.php?action=delete&id=<?= e($b->id) ?>&csrf_token=<?= get_csrf_token() ?>','Hapus broadcast ini?')"
                                            class="p-1.5 text-slate-400 hover:text-red-500 hover:bg-red-50 rounded-lg transition-all"
                                            aria-label="Hapus broadcast <?= htmlspecialchars($b->title) ?>">
                                        <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                    </button>
                                </div>
                            </div>
                            <p class="text-xs text-slate-500 leading-relaxed line-clamp-2 mb-2"><?= htmlspecialchars(mb_substr($b->message ?? '', 0, 120)) ?>...</p>
                            <div class="flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-slate-400 border-t border-slate-100 pt-2">
                                <span class="flex items-center gap-1"><i data-lucide="clock" class="w-3 h-3"></i><?= date('d M Y, H:i', strtotime($b->created_at)) ?></span>
                                <?php if (!empty($b->recipient_count)): ?><span class="flex items-center gap-1"><i data-lucide="users" class="w-3 h-3"></i><?= number_format($b->recipient_count) ?> penerima</span><?php endif; ?>
                                <?php if (!empty($b->target_filter) && $b->target_filter !== 'all'): ?><span class="text-indigo-500"><?= htmlspecialchars($b->target_filter) ?></span><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($total_pages > 1): ?>
                    <div class="flex items-center justify-center gap-1 pt-2">
                        <?php for ($i = 1; $i <= $total_pages; $i++): 
                            $qp = $_GET;
                            $qp['p'] = $i;
                            $qp['page'] = 'admin_broadcast';
                        ?>
                            <a href="?<?= http_build_query($qp) ?>"
                               class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-bold transition-all <?= $i === $page_num ? 'bg-indigo-600 text-white' : 'bg-white text-slate-500 hover:bg-indigo-50 border border-slate-200' ?>">
                                <?= e($i) ?>
                            </a>
                        <?php endfor; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

    </div>
</section>


<!-- ══════ MODAL: Layout Picker ══════════════════════ -->
<div class="mo" id="layout-modal" onclick="moOverlayClose(event,this)">
    <div class="mo-box" style="max-width:880px;">
        <!-- Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h2 class="font-bold text-lg text-slate-800 outfit">Pilih Layout Email</h2>
            <div class="flex items-center gap-3">
                <a href="index.php?page=admin_email_layouts" class="text-xs font-bold bg-blue-50 text-blue-600 px-4 py-2 rounded-xl hover:bg-blue-600 hover:text-white transition-colors flex items-center gap-2">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                    Kelola Template Email Baru
                </a>
                <button type="button" onclick="closeMo('layout-modal')" class="w-8 h-8 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500 transition-colors" aria-label="Tutup">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
        </div>
        <!-- Body -->
        <div class="lp-body">
            <div class="lp-left">
                <!-- Tabs -->
                <div class="lp-tabs">
                    <div class="lp-tab active" id="lp-tab-default" onclick="switchLpTab('default')">Layout Default</div>
                    <div class="lp-tab" id="lp-tab-custom" onclick="switchLpTab('custom')">Layout Saya</div>
                </div>
                <div class="lp-grid-wrap">
                    <div class="lp-grid" id="lp-grid-default"></div>
                    <div class="lp-grid hidden" id="lp-grid-custom"></div>
                </div>
            </div>
            <div class="lp-right">
                <div class="lp-preview-label">Preview</div>
                <div style="padding:12px;flex:1;overflow:auto;">
                    <div id="lp-no-preview" style="display:flex;align-items:center;justify-content:center;height:100%;color:#94a3b8;font-size:13px;text-align:center;">
                        <div><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#e2e8f0" stroke-width="1.5" style="margin:0 auto 8px;display:block;"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>Klik layout untuk melihat preview</div>
                    </div>
                    <iframe id="lp-iframe" style="width:100%;min-height:360px;border:none;border-radius:10px;display:none;"></iframe>
                </div>
            </div>
        </div>
        <!-- Footer -->
        <div class="flex items-center justify-between px-6 py-4 border-t border-slate-100 bg-slate-50/50">
            <button type="button" onclick="saveCurrentLayout()" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 flex items-center gap-1.5 transition-colors">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17,21 17,13 7,13 7,21"/><polyline points="7,3 7,8 15,8"/></svg>
                Simpan Layout Saat Ini
            </button>
            <div class="flex items-center gap-2">
                <button type="button" onclick="closeMo('layout-modal')" class="px-4 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-100 transition-colors">Batal</button>
                <button type="button" id="btn-use-layout" onclick="applyLayout()" disabled
                    class="px-5 py-2 rounded-xl text-sm font-bold text-white bg-gradient-to-r from-indigo-600 to-violet-600 disabled:opacity-40 disabled:cursor-not-allowed hover:opacity-90 transition-opacity">
                    Gunakan Layout →
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══════ MODAL: Insert Link ═════════════════════════ -->
<div class="mo" id="link-modal" onclick="moOverlayClose(event,this)">
    <div class="mo-box" style="max-width:420px;">
        <div class="px-6 pt-6 pb-5 space-y-4">
            <h2 class="font-bold text-lg text-slate-800 outfit">Sisipkan Tautan</h2>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">Teks Tampilan</label>
                <input aria-label="Teks yang terlihat" id="link-text" type="text" placeholder="Teks yang terlihat..."
                       class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-400 outline-none">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">URL</label>
                <input aria-label="https://" id="link-url" type="url" placeholder="https://..."
                       class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-400 outline-none">
            </div>
            <div class="flex justify-end gap-2 pt-1">
                <button type="button" onclick="closeMo('link-modal')" class="px-4 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-100 transition-colors">Batal</button>
                <button type="button" onclick="applyLink()" class="px-5 py-2 rounded-xl text-sm font-bold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:opacity-90">Sisipkan</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════ MODAL: Insert Image ════════════════════════ -->
<div class="mo" id="img-modal" onclick="moOverlayClose(event,this)">
    <div class="mo-box" style="max-width:420px;">
        <div class="px-6 pt-6 pb-5 space-y-4">
            <h2 class="font-bold text-lg text-slate-800 outfit">Sisipkan Gambar</h2>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">URL Gambar</label>
                    <input aria-label="https://example.com/image.jpg" id="img-url" type="url" placeholder="https://example.com/image.jpg"
                           class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-400 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">Teks Alt</label>
                    <input aria-label="Deskripsi gambar" id="img-alt" type="text" placeholder="Deskripsi gambar..."
                           class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-400 outline-none">
                </div>
                <div class="bg-slate-50 border border-dashed border-slate-300 rounded-xl p-4 text-center" id="img-drop-zone">
                    <svg class="w-8 h-8 text-slate-300 mx-auto mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21,15 16,10 5,21"/></svg>
                    <p class="text-xs text-slate-400">Atau upload dari komputer</p>
                    <input type="file" id="img-file" accept="image/*" class="mt-2 text-xs text-slate-500 w-full" onchange="handleImgFile(this)">
                </div>
            </div>
            <div class="flex justify-end gap-2 pt-1">
                <button type="button" onclick="closeMo('img-modal')" class="px-4 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-100 transition-colors">Batal</button>
                <button type="button" onclick="applyImage()" class="px-5 py-2 rounded-xl text-sm font-bold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:opacity-90">Sisipkan</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════ MODAL: Save Layout ═════════════════════════ -->
<div class="mo" id="save-layout-modal" onclick="moOverlayClose(event,this)">
    <div class="mo-box" style="max-width:380px;">
        <div class="px-6 pt-6 pb-5 space-y-4">
            <h2 class="font-bold text-lg text-slate-800 outfit">Simpan Layout</h2>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">Nama Layout</label>
                <input aria-label="Contoh: Template Reuni Tahunan" id="save-layout-name" type="text" placeholder="Contoh: Template Reuni Tahunan"
                       class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-400 outline-none">
            </div>
            <div class="flex justify-end gap-2 pt-1">
                <button type="button" onclick="closeMo('save-layout-modal')" class="px-4 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-100 transition-colors">Batal</button>
                <button type="button" onclick="doSaveLayout()" class="px-5 py-2 rounded-xl text-sm font-bold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:opacity-90">Simpan</button>
            </div>
        </div>
    </div>
</div>

<script>
/* ══════════════════════════════════════════════════
   BROADCAST COMPOSE ENGINE — Full Featured
══════════════════════════════════════════════════ */

// ── Templates from PHP ──────────────────────────
const DEFAULT_TEMPLATES = <?= e($templates_json) ?>;
const CUSTOM_LAYOUTS    = <?= e($custom_layouts_json) ?>;

// ── DOM refs ────────────────────────────────────
const editor    = document.getElementById('compose-editor');
const htmlEdit  = document.getElementById('html-editor');
const attList   = document.getElementById('attachment-list');
const fileInput = document.getElementById('file-input');
const errBar    = document.getElementById('compose-error');
const charDisp  = document.getElementById('char-display');

let editorMode = 'gui';    // 'gui' | 'html'
let attachments = [];       // File[] array
let selLayout   = null;     // currently selected layout id
let savedRange  = null;     // caret position before popover opens

// ── Utility: Save / Restore caret ──────────────
function saveRange() {
    const sel = window.getSelection();
    if (sel && sel.rangeCount > 0 && editor.contains(sel.anchorNode)) {
        savedRange = sel.getRangeAt(0).cloneRange();
    }
}
function restoreRange() {
    if (!savedRange) return;
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(savedRange);
}

editor.addEventListener('mouseup', saveRange);
editor.addEventListener('keyup',   saveRange);

// Character count update
editor.addEventListener('input', () => {
    const len = editor.innerText.trim().length;
    charDisp.textContent = len.toLocaleString('id-ID') + ' karakter';
});

// ── Formatting commands ─────────────────────────
function fmt(cmd, val) {
    editor.focus();
    restoreRange();
    document.execCommand(cmd, false, val ?? null);
    editor.focus();
    saveRange();
}

function setFontSize(size) {
    editor.focus();
    restoreRange();
    document.execCommand('fontSize', false, size);
    editor.focus();
}

function execInsertHTML(html) {
    editor.focus();
    restoreRange();
    document.execCommand('insertHTML', false, html);
    editor.focus();
    saveRange();
    closeFmt();
}

// ── Format popover ──────────────────────────────
const fmtPop = document.getElementById('fmt-popover');
function toggleFmt() {
    saveRange();
    fmtPop.classList.toggle('open');
    document.getElementById('emoji-picker').classList.remove('open');
    document.getElementById('tb-fmt').classList.toggle('active', fmtPop.classList.contains('open'));
}
function closeFmt() {
    fmtPop.classList.remove('open');
    document.getElementById('tb-fmt').classList.remove('active');
}

// Color swatches
const TEXT_COLORS  = ['#1e293b','#ef4444','#f97316','#eab308','#22c55e','#3b82f6','#6366f1','#8b5cf6','#ec4899','#14b8a6','#ffffff','#94a3b8'];
const HL_COLORS    = ['#fef08a','#fed7aa','#fecaca','#bbf7d0','#bfdbfe','#ddd6fe','#fbcfe8','#e0f2fe'];

document.getElementById('text-colors').innerHTML = TEXT_COLORS.map(c =>
    `<div class="swatch" style="background:${c};${c==='#ffffff'?'border:1px solid #e2e8f0;':''}" onclick="fmt('foreColor','${c}')" title="${c}"></div>`
).join('');
document.getElementById('hl-colors').innerHTML = HL_COLORS.map(c =>
    `<div class="swatch" style="background:${c}" onclick="fmt('backColor','${c}')" title="${c}"></div>`
).join('');

// ── Emoji picker ────────────────────────────────
const EMOJIS = ['😊','😁','🎉','🎊','👋','🙌','🏆','⭐','🔔','📢','💡','🚀','❤️','💙','💚','💜','🔥','✅','⚠️','ℹ️','📝','📅','📌','🔗','💼','🎓','🏫','👨‍🎓','👩‍🎓','🤝','💬','📧','📱','💻','🌟','🎯','📊','🏅','🎗️','📸','🗓️','🎤','🎵','✨','🌐','🔑','📜','💡','🔎'];

document.getElementById('emoji-grid').innerHTML = EMOJIS.map(e =>
    `<button class="ej" type="button" onmousedown="event.preventDefault()" onclick="insertEmoji('${e}')" aria-label="Emoji ${e}">${e}</button>`
).join('');

const emojiPicker = document.getElementById('emoji-picker');
function toggleEmoji() {
    emojiPicker.classList.toggle('open');
    closeFmt();
    document.getElementById('tb-emoji').classList.toggle('active', emojiPicker.classList.contains('open'));
}
function insertEmoji(e) {
    editor.focus();
    restoreRange();
    document.execCommand('insertText', false, e);
    emojiPicker.classList.remove('open');
    document.getElementById('tb-emoji').classList.remove('active');
}

// Close popovers on outside click
document.addEventListener('click', (ev) => {
    if (!fmtPop.contains(ev.target) && ev.target.id !== 'tb-fmt') closeFmt();
    if (!emojiPicker.contains(ev.target) && ev.target.id !== 'tb-emoji') {
        emojiPicker.classList.remove('open');
        document.getElementById('tb-emoji').classList.remove('active');
    }
});

// ── Mode Toggle (GUI ↔ HTML) ────────────────────
function toggleMode() {
    const btn = document.getElementById('tb-html');
    const badge = document.getElementById('editor-mode-badge');
    if (editorMode === 'gui') {
        // GUI → HTML
        htmlEdit.value = editor.innerHTML;
        editor.style.display = 'none';
        htmlEdit.style.display = 'block';
        btn.classList.add('active');
        badge.textContent = 'HTML';
        badge.style.background = '#1e293b';
        badge.style.color = '#7dd3fc';
        editorMode = 'html';
    } else {
        // HTML → GUI
        editor.innerHTML = htmlEdit.value;
        htmlEdit.style.display = 'none';
        editor.style.display = 'block';
        btn.classList.remove('active');
        badge.textContent = 'GUI';
        badge.style.background = '';
        badge.style.color = '';
        editorMode = 'gui';
    }
}

function getEditorHTML() {
    return editorMode === 'html' ? htmlEdit.value : editor.innerHTML;
}

// ── Clear ───────────────────────────────────────
function clearContent() {
    swalConfirm('Kosongkan Editor?', 'Seluruh isi editor akan dihapus dan tidak dapat dikembalikan.', () => {
        editor.innerHTML = '';
        htmlEdit.value   = '';
    }, 'Ya, Kosongkan');
}

// ── File Attachments ────────────────────────────
fileInput.addEventListener('change', () => { handleFiles(fileInput.files); fileInput.value = ''; });

function handleFiles(files) {
    Array.from(files).forEach(f => {
        if (attachments.length >= 5) { showErr('Maks. 5 lampiran per broadcast.'); return; }
        if (f.size > 10 * 1024 * 1024) { showErr(`File "${f.name}" terlalu besar (maks. 10 MB).`); return; }
        attachments.push(f);
    });
    renderAttachments();
}

function removeAtt(i) {
    attachments.splice(i, 1);
    renderAttachments();
}

function parseExternalEmails(input) {
    if(!input.files.length) return;
    const reader = new FileReader();
    reader.onload = e => {
        const text = e.target.result;
        document.getElementById('ext-email-input').value += (document.getElementById('ext-email-input').value ? ',\n' : '') + text;
        updateExtEmailCount();
    };
    reader.readAsText(input.files[0]);
    input.value = '';
}

document.getElementById('ext-email-input')?.addEventListener('input', updateExtEmailCount);
function updateExtEmailCount() {
    const text = document.getElementById('ext-email-input').value;
    const emails = text.match(/([a-zA-Z0-9._-]+@[a-zA-Z0-9._-]+\.[a-zA-Z0-9._-]+)/gi) || [];
    const unique = [...new Set(emails)];
    document.getElementById('h-external-emails').value = JSON.stringify(unique);
    document.getElementById('ext-email-count').textContent = unique.length + ' email terekstrak';
}

function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}

function renderAttachments() {
    const icons = { 'image/': '🖼', 'application/pdf': '📄', 'application/msword': '📝', 'application/vnd': '📊', 'text/': '📄' };
    attList.innerHTML = attachments.map((f, i) => {
        const icon = Object.entries(icons).find(([k]) => f.type.startsWith(k))?.[1] ?? '📎';
        return `<div class="att-chip">
            <span>${icon}</span>
            <span class="att-name">${escH(f.name)}</span>
            <span style="color:#94a3b8;flex-shrink:0">${formatSize(f.size)}</span>
            <button class="att-rm" type="button" onclick="removeAtt(${i})" aria-label="Hapus lampiran ${escH(f.name)}">×</button>
        </div>`;
    }).join('');
}

// Drag & drop into editor
editor.addEventListener('dragover', e => { e.preventDefault(); editor.classList.add('drag-over'); });
editor.addEventListener('dragleave', ()  => editor.classList.remove('drag-over'));
editor.addEventListener('drop', e => {
    e.preventDefault();
    editor.classList.remove('drag-over');
    if (e.dataTransfer.files.length) {
        handleFiles(e.dataTransfer.files);
    } else {
        // Allow dropping text/html
        const html = e.dataTransfer.getData('text/html');
        const text = e.dataTransfer.getData('text/plain');
        if (html)  document.execCommand('insertHTML', false, html);
        else if (text) document.execCommand('insertText', false, text);
    }
});

// ── Link modal ──────────────────────────────────
function insertLinkModal() {
    saveRange();
    const sel = window.getSelection().toString();
    document.getElementById('link-text').value = sel || '';
    document.getElementById('link-url').value  = 'https://';
    openMo('link-modal');
}
function applyLink() {
    const url  = document.getElementById('link-url').value.trim();
    const text = document.getElementById('link-text').value.trim();
    if (!url || url === 'https://') { showErr('Masukkan URL yang valid.'); return; }
    closeMo('link-modal');
    editor.focus();
    restoreRange();
    const anchor = `<a href="${escA(url)}" target="_blank" style="color:#6366f1;">${escH(text || url)}</a>`;
    document.execCommand('insertHTML', false, anchor);
}

// ── Image modal ─────────────────────────────────
function applyImage() {
    const url = document.getElementById('img-url').value.trim();
    const alt = document.getElementById('img-alt').value.trim();
    if (!url) { showErr('Masukkan URL gambar.'); return; }
    closeMo('img-modal');
    editor.focus();
    document.execCommand('insertHTML', false,
        `<img src="${escA(url)}" alt="${escA(alt || 'Gambar')}" style="max-width:100%;border-radius:8px;margin:8px 0;">`
    );
}
function handleImgFile(input) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('img-url').value = e.target.result;
    };
    reader.readAsDataURL(file);
}

// ── Layout picker ───────────────────────────────
let selectedLayoutHTML = null;

function openLayoutPicker() {
    renderLpGrid('default', DEFAULT_TEMPLATES);
    renderLpGrid('custom',  CUSTOM_LAYOUTS.map(l => ({id: l.id, name: l.name, thumb_type: l.thumb_type || 'simple', html: l.html_content})));
    document.getElementById('btn-use-layout').disabled = true;
    document.getElementById('lp-iframe').style.display = 'none';
    document.getElementById('lp-no-preview').style.display = 'flex';
    switchLpTab('default');
    openMo('layout-modal');
}

function switchLpTab(tab) {
    ['default','custom'].forEach(t => {
        document.getElementById('lp-tab-' + t).classList.toggle('active', t === tab);
        document.getElementById('lp-grid-' + t).style.display = (t === tab) ? 'grid' : 'none';
    });
}

function renderLpGrid(tab, layouts) {
    const container = document.getElementById('lp-grid-' + tab);
    if (!layouts || layouts.length === 0) {
        container.innerHTML = '<p style="font-size:12px;color:#94a3b8;padding:12px;text-align:center;">Belum ada layout tersimpan.<br>Buat pesan lalu klik "Simpan Layout".</p>';
        return;
    }
    container.innerHTML = layouts.map(l => `
        <div class="lp-card ${selLayout === l.id ? 'sel' : ''}"
             onclick="pickLayout('${escA(l.id)}', this)"
             data-html="${escA(l.html || l.html_content || '')}"
             data-id="${escA(l.id)}"
             title="${escA(l.name)}">
            <div class="lp-thumb">${getThumbSVG(l.thumb_type || 'simple')}</div>
            <div class="lp-name">${escH(l.name)}</div>
        </div>
    `).join('');
}

function pickLayout(id, el) {
    selLayout = id;
    selectedLayoutHTML = el.getAttribute('data-html');

    // Decode HTML entities in the stored attribute
    const ta = document.createElement('textarea');
    ta.innerHTML = selectedLayoutHTML;
    selectedLayoutHTML = ta.value;

    document.querySelectorAll('.lp-card').forEach(c => c.classList.remove('sel'));
    el.classList.add('sel');

    // Show in iframe
    const frame = document.getElementById('lp-iframe');
    const np    = document.getElementById('lp-no-preview');
    frame.srcdoc = `<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><style>body{margin:0;padding:8px;}</style></head><body>${selectedLayoutHTML}</body></html>`;
    frame.style.display = 'block';
    np.style.display    = 'none';
    document.getElementById('btn-use-layout').disabled = false;
}

function applyLayout() {
    if (!selectedLayoutHTML) return;
    if (editorMode === 'html') {
        htmlEdit.value = selectedLayoutHTML;
    } else {
        editor.innerHTML = selectedLayoutHTML;
    }
    closeMo('layout-modal');
    selLayout = null;
}

// ── SVG Thumbnails ──────────────────────────────
function getThumbSVG(type) {
    const s = (shapes) => `<svg width="92" height="80" viewBox="0 0 92 80" xmlns="http://www.w3.org/2000/svg">${shapes}</svg>`;
    const thumbs = {
        simple:       s(`<rect x="4" y="8"  w="84" h="7"  rx="3" fill="#c7d2fe" width="84" height="7"/><rect x="4" y="22" width="84" height="3" rx="1.5" fill="#e2e8f0"/><rect x="4" y="28" width="60" height="3" rx="1.5" fill="#e2e8f0"/><rect x="4" y="37" width="84" height="3" rx="1.5" fill="#e2e8f0"/><rect x="4" y="43" width="70" height="3" rx="1.5" fill="#e2e8f0"/><rect x="4" y="49" width="50" height="3" rx="1.5" fill="#e2e8f0"/><rect x="22" y="62" width="48" height="11" rx="5" fill="#6366f1"/>`),
        announcement: s(`<rect x="0" y="0"  width="92" height="26" rx="2" fill="#6366f1"/><rect x="16" y="7"  width="60" height="5"  rx="2"   fill="white" opacity="0.9"/><rect x="22" y="16" width="48" height="3"  rx="1.5" fill="white" opacity="0.6"/><rect x="4"  y="33" width="84" height="3"  rx="1.5" fill="#e2e8f0"/><rect x="4"  y="40" width="84" height="3"  rx="1.5" fill="#e2e8f0"/><rect x="4"  y="47" width="55" height="3"  rx="1.5" fill="#e2e8f0"/><rect x="22" y="60" width="48" height="12" rx="5"   fill="#6366f1"/>`),
        newsletter:   s(`<rect x="0" y="0"  width="92" height="20" rx="2" fill="#1e293b"/><rect x="4" y="5"  width="32" height="6"  rx="2"   fill="#6366f1"/><rect x="52" y="7" width="36" height="3"  rx="1.5" fill="#475569"/><rect x="4" y="26" width="84" height="22" rx="4"   fill="#eef2ff"/><rect x="8" y="30" width="45" height="5"  rx="2"   fill="#6366f1" opacity="0.5"/><rect x="8" y="38" width="74" height="3"  rx="1.5" fill="#c7d2fe"/><rect x="4" y="54" width="40" height="16" rx="4"   fill="#f8fafc" stroke="#e2e8f0" stroke-width="1"/><rect x="48" y="54" width="40" height="16" rx="4"   fill="#f8fafc" stroke="#e2e8f0" stroke-width="1"/><rect x="0" y="74" width="92" height="6"  rx="0"   fill="#1e293b"/>`),
        event:        s(`<rect x="0" y="0"  width="92" height="30" rx="2" fill="#0f172a"/><circle cx="78" cy="8"  r="12" fill="#6366f1" opacity="0.2"/><rect x="18" y="7"  width="56" height="6"  rx="3"   fill="white" opacity="0.9"/><rect x="24" y="18" width="44" height="4"  rx="2"   fill="white" opacity="0.5"/><rect x="2"  y="36" width="27" height="20" rx="4"   fill="#f8fafc" stroke="#e2e8f0" stroke-width="1"/><rect x="32" y="36" width="27" height="20" rx="4"   fill="#f8fafc" stroke="#e2e8f0" stroke-width="1"/><rect x="62" y="36" width="27" height="20" rx="4"   fill="#f8fafc" stroke="#e2e8f0" stroke-width="1"/><rect x="22" y="62" width="48" height="12" rx="5"   fill="#6366f1"/>`),
        referral:     s(`<circle cx="46" cy="18" r="13" fill="#eef2ff" stroke="#6366f1" stroke-width="1.5"/><rect x="40" y="12" width="12" height="12" rx="3" fill="#6366f1" opacity="0.4"/><rect x="4"  y="38" width="84" height="10" rx="4"   fill="#f0fdf4" stroke="#22c55e" stroke-width="1"/><rect x="4"  y="52" width="84" height="10" rx="4"   fill="#eff6ff" stroke="#3b82f6" stroke-width="1"/><rect x="4"  y="66" width="84" height="10" rx="4"   fill="#fef3c7" stroke="#f59e0b" stroke-width="1"/>`),
    };
    return thumbs[type] || thumbs.simple;
}

// ── Save Layout ─────────────────────────────────
function saveCurrentLayout() {
    document.getElementById('save-layout-name').value = '';
    openMo('save-layout-modal');
}

function doSaveLayout() {
    const name = document.getElementById('save-layout-name').value.trim();
    if (!name) { showSwalAlert('Nama Kosong', 'Masukkan nama layout terlebih dahulu.', 'warning'); return; }
    const html = getEditorHTML();
    if (!html || html === '<br>') { showSwalAlert('Editor Kosong', 'Tidak ada layout yang dapat disimpan.', 'warning'); return; }

    const fd = new FormData();
    fd.append('name', name);
    fd.append('html_content', html);
    fd.append('csrf_token', document.querySelector('[name=csrf_token]').value);

    fetch('api/broadcast_layout_save.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                CUSTOM_LAYOUTS.unshift({ id: d.id, name: name, html_content: html, thumb_type: 'simple' });
                renderLpGrid('custom', CUSTOM_LAYOUTS.map(l => ({id: l.id, name: l.name, thumb_type: l.thumb_type || 'simple', html: l.html_content})));
                closeMo('save-layout-modal');
                openLayoutPicker();
                switchLpTab('custom');
                showSwalAlert('Tersimpan', 'Layout berhasil disimpan ke "Layout Saya".', 'success');
            } else {
                showSwalAlert('Gagal Menyimpan', d.error || 'Layout tidak dapat disimpan.', 'error');
            }
        })
        .catch(() => showSwalAlert('Kesalahan Koneksi', 'Gagal menghubungi server.', 'error'));
}

// ── Target filter ───────────────────────────────
function handleTargetTypeChange() {
    const type = document.getElementById('target-type').value;
    const wrap  = document.getElementById('target-val-wrap');
    const maj   = document.getElementById('target-major');
    const yr    = document.getElementById('target-year');
    const toLabel = document.getElementById('to-label');
    const labels  = {
        'all':   'Semua Alumni (<?= number_format($total_alumni) ?> orang)',
        'major': 'Alumni — Filter: Program Studi',
        'year':  'Alumni — Filter: Tahun Angkatan',
        'role':  'Semua Pengguna (Alumni + Staff)',
        'external': 'Hanya Penerima Eksternal'
    };

    document.getElementById('h-target-type').value  = type;
    document.getElementById('h-target-value').value = '';
    toLabel.textContent = labels[type] || labels.all;

    wrap.classList.toggle('hidden', type === 'all' || type === 'role' || type === 'external');
    maj.classList.toggle('hidden', type !== 'major');
    yr.classList.toggle('hidden',  type !== 'year');

    if (type === 'major') setTargetValue(maj.value, maj.options[maj.selectedIndex].text);
    if (type === 'year')  setTargetValue(yr.value);
}

function setTargetValue(val, label) {
    document.getElementById('h-target-value').value = val;
    const type = document.getElementById('target-type').value;
    const toLabel = document.getElementById('to-label');
    if (type === 'major') toLabel.textContent = 'Alumni — Prodi: ' + (label || val);
    if (type === 'year')  toLabel.textContent = 'Alumni — Angkatan: ' + val;
}

// ── Modal helpers ───────────────────────────────
function openMo(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeMo(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}
function moOverlayClose(e, el) {
    if (e.target === el) { el.classList.remove('open'); document.body.style.overflow = ''; }
}

// ── Form submit ─────────────────────────────────
function handleSubmit(e) {
    e.preventDefault();
    const chApp   = document.getElementById('ch-app').checked;
    const chEmail = document.getElementById('ch-email').checked;
    const title   = document.getElementById('bc-subject').value.trim();
    const html    = getEditorHTML().trim();

    if (!title) { showErr('Judul/Subjek tidak boleh kosong.'); return false; }
    if (!html || html === '<br>') { showErr('Isi pesan tidak boleh kosong.'); return false; }
    if (!chApp && !chEmail) { showErr('Pilih minimal satu channel pengiriman.'); return false; }

    const channels = [chApp ? 'Notifikasi App' : '', chEmail ? 'Email' : ''].filter(Boolean).join(' + ');

    // Konfirmasi bersifat asinkron, sehingga pengiriman sesungguhnya
    // dipindahkan ke sendBroadcast() dan dipanggil dari callback.
    swalConfirm(
        'Kirim Broadcast?',
        `Pesan "${title}" akan dikirim via ${channels}.`,
        () => sendBroadcast(e, html),
        'Ya, Kirim'
    );
    return false;
}

function sendBroadcast(e, html) {
    // Sync editor HTML to hidden field
    document.getElementById('body_html').value = html;

    const btn = document.getElementById('btnSend');
    btn.disabled = true;
    btn.innerHTML = `<svg class="animate-spin w-4 h-4" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Mengirim...`;

    // Build FormData with attachments
    const fd = new FormData(e.target);
    attachments.forEach(f => fd.append('attachments[]', f));

    fetch(e.target.action, { method: 'POST', body: fd })
        .then(r => {
            if (r.redirected) { window.location.href = r.url; return; }
            return r.text().then(t => { throw new Error(t.substring(0, 200)); });
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg> Kirim Broadcast`;
            showErr('Gagal: ' + err.message);
        });

    return false;
}

// ── Error bar ───────────────────────────────────
function showErr(msg) {
    errBar.textContent = msg;
    errBar.style.display = 'block';
    clearTimeout(errBar._t);
    errBar._t = setTimeout(() => errBar.style.display = 'none', 4500);
}

function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escA(s) { return String(s).replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }

// ── Init ────────────────────────────────────────
renderLpGrid('default', DEFAULT_TEMPLATES);
renderLpGrid('custom',  CUSTOM_LAYOUTS.map(l => ({id: l.id, name: l.name, thumb_type: l.thumb_type || 'simple', html: l.html_content})));
</script>
