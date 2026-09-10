<?php
/**
 * employer_survey.php — Survei Kepuasan Pengguna Lulusan
 * ─────────────────────────────────────────────────────────
 * Halaman PUBLIK tanpa login. Atasan alumni bukan pengguna sistem, sehingga
 * akses diberikan lewat tautan bertoken yang dikirim ke e-mail mereka —
 * pola URL kapabilitas yang sama dengan verify.php dan view_softcopy.php.
 *
 * Aspek penilaian diambil dari pertanyaan kompetensi tracer (Q23-Q30), bukan
 * ditulis ulang, sehingga jawaban atasan dapat disandingkan langsung dengan
 * penilaian diri alumni pada aspek dan skala yang sama.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/error_page.php';

$token = trim($_GET['token'] ?? '');

if ($token === '') {
    render_error_page(
        'Tautan Tidak Lengkap',
        'Tautan survei tidak memuat kode akses. Silakan buka kembali tautan yang dikirim ke e-mail Anda.',
        400,
        'link-2-off'
    );
}

// Batasi percobaan menebak token.
check_rate_limit('EMPLOYER_SURVEY', 20, 15);

$stmt = $pdo->prepare(
    "SELECT es.*, u.name AS alumni_name, u.nim, u.graduation_year,
            COALESCE(m.major_name, u.major) AS major_name
     FROM employer_surveys es
     JOIN users u ON es.alumni_user_id = u.id
     LEFT JOIN majors m ON u.major = m.major_code
     WHERE es.token = ?"
);
$stmt->execute([$token]);
$survey = $stmt->fetch();

if (!$survey) {
    render_error_page(
        'Tautan Tidak Dikenal',
        'Kode akses pada tautan ini tidak dikenali sistem. Pastikan tautan disalin secara utuh.',
        404,
        'search-x'
    );
}

if ($survey->status === 'completed') {
    render_error_page(
        'Survei Sudah Terisi',
        'Terima kasih — penilaian untuk lulusan ini sudah kami terima sebelumnya. Setiap tautan hanya dapat digunakan satu kali.',
        410,
        'check-circle-2'
    );
}

if ($survey->expires_at !== null && strtotime($survey->expires_at) < time()) {
    render_error_page(
        'Tautan Kedaluwarsa',
        'Masa berlaku tautan survei ini sudah berakhir. Silakan hubungi bagian akademik untuk memperoleh tautan baru.',
        410,
        'clock-alert'
    );
}

$aspek     = tracer_competency_questions($pdo);
$institusi = setting('system_name', 'AlumniLink');
$logo      = setting('system_logo', '');
$warna     = brand_primary_color();

$sukses = isset($_GET['success']);
$galat  = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Survei Kepuasan Pengguna Lulusan — <?php echo htmlspecialchars($institusi); ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/fonts/fonts.css">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .outfit { font-family: 'Outfit', sans-serif; }
        :root { --brand-primary: <?php echo $warna; ?>; }
        .brand-bg { background-color: var(--brand-primary); }
        .brand-text { color: var(--brand-primary); }
        :focus-visible { outline: 3px solid var(--brand-primary); outline-offset: 2px; border-radius: 6px; }
        .skala input:checked + span { background: var(--brand-primary); color: #fff; border-color: var(--brand-primary); }
    </style>
</head>
<body class="min-h-screen bg-slate-50 py-8 px-4">
<main class="max-w-3xl mx-auto">

    <?php if ($sukses): ?>
        <div class="bg-white border border-slate-200 rounded-3xl shadow-xl p-12 text-center">
            <div class="w-20 h-20 bg-emerald-50 text-emerald-600 border border-emerald-200 rounded-2xl flex items-center justify-center mx-auto mb-6">
                <i data-lucide="check-circle-2" class="w-10 h-10" aria-hidden="true"></i>
            </div>
            <h1 class="text-2xl font-black outfit text-slate-800 mb-3">Terima Kasih</h1>
            <p class="text-sm text-slate-500 leading-relaxed max-w-md mx-auto">
                Penilaian Anda sudah kami terima. Masukan ini sangat berarti bagi
                evaluasi mutu lulusan <?php echo htmlspecialchars($institusi); ?>.
            </p>
        </div>
        <script src="assets/js/lucide.min.js"></script>
        <script>lucide.createIcons();</script>
        </main></body></html>
        <?php exit; ?>
    <?php endif; ?>

    <!-- Kop -->
    <div class="bg-white border border-slate-200 rounded-3xl shadow-sm p-8 mb-6">
        <div class="flex items-start gap-4">
            <?php if ($logo): ?>
                <img src="<?php echo htmlspecialchars($logo); ?>" alt="" class="w-14 h-14 object-contain shrink-0">
            <?php endif; ?>
            <div>
                <h1 class="text-xl font-black outfit text-slate-800 tracking-tight">Survei Kepuasan Pengguna Lulusan</h1>
                <p class="text-sm text-slate-500 mt-1"><?php echo htmlspecialchars($institusi); ?></p>
            </div>
        </div>

        <div class="mt-6 p-5 bg-slate-50 border border-slate-100 rounded-2xl">
            <p class="text-[11px] font-black uppercase tracking-widest text-slate-400 mb-3">Lulusan yang dinilai</p>
            <p class="text-lg font-bold text-slate-800"><?php echo htmlspecialchars($survey->alumni_name); ?></p>
            <p class="text-xs text-slate-500 mt-1">
                <?php echo htmlspecialchars($survey->nim ?: '-'); ?>
                <?php if ($survey->major_name): ?> &middot; <?php echo htmlspecialchars($survey->major_name); ?><?php endif; ?>
                <?php if ($survey->graduation_year): ?> &middot; Lulus <?php echo (int)$survey->graduation_year; ?><?php endif; ?>
            </p>
        </div>

        <p class="text-xs text-slate-500 leading-relaxed mt-5">
            Mohon kesediaan Bapak/Ibu selaku atasan langsung untuk menilai penguasaan
            kompetensi lulusan berikut. Jawaban digunakan semata-mata untuk evaluasi
            mutu pendidikan dan tidak dipublikasikan secara perorangan.
        </p>
    </div>

    <?php if ($galat): ?>
        <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-700 rounded-2xl text-sm font-semibold" role="alert">
            <?php
            $pesan = [
                'incomplete' => 'Mohon lengkapi penilaian untuk seluruh aspek sebelum mengirim.',
                'csrf'       => 'Sesi formulir sudah kedaluwarsa. Silakan muat ulang halaman ini.',
                'failed'     => 'Penilaian gagal disimpan. Silakan coba lagi beberapa saat lagi.',
            ];
            echo htmlspecialchars($pesan[$galat] ?? 'Terjadi kesalahan. Silakan coba lagi.');
            ?>
        </div>
    <?php endif; ?>

    <?php if (!$aspek): ?>
        <div class="bg-white border border-slate-200 rounded-3xl p-10 text-center">
            <p class="font-bold text-slate-600">Kuesioner belum tersedia</p>
            <p class="text-sm text-slate-400 mt-2">Aspek penilaian belum dikonfigurasi. Silakan hubungi bagian akademik.</p>
        </div>
    <?php else: ?>
    <form action="handlers/employer_survey_handler.php" method="POST"
          class="bg-white border border-slate-200 rounded-3xl shadow-sm p-8 space-y-8">
        <?php csrf_field(); ?>
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

        <?php foreach ($aspek as $i => $q):
            $opsi = json_decode((string)$q->options, true);
            if (!is_array($opsi)) { $opsi = []; }
        ?>
            <fieldset class="border-b border-slate-100 pb-7 last:border-0 last:pb-0">
                <legend class="text-sm font-bold text-slate-800 mb-4">
                    <span class="brand-text"><?php echo $i + 1; ?>.</span>
                    <?php echo htmlspecialchars(tracer_competency_label($q->question_text)); ?>
                </legend>

                <div class="skala grid grid-cols-2 md:grid-cols-4 gap-2">
                    <?php foreach ($opsi as $o): ?>
                        <label class="cursor-pointer">
                            <input type="radio" name="q_<?php echo (int)$q->id; ?>"
                                   value="<?php echo htmlspecialchars($o); ?>" required class="sr-only">
                            <span class="block text-center text-xs font-bold px-3 py-3 rounded-xl border border-slate-200 bg-white text-slate-600 transition-all hover:border-slate-300">
                                <?php echo htmlspecialchars($o); ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        <?php endforeach; ?>

        <button type="submit"
                class="w-full brand-bg text-white py-4 rounded-2xl font-black outfit text-sm shadow-lg active:scale-95 transition-transform">
            Kirim Penilaian
        </button>

        <p class="text-[11px] text-slate-400 text-center">
            Tautan ini bersifat pribadi dan hanya dapat digunakan satu kali.
        </p>
    </form>
    <?php endif; ?>

    <p class="text-center text-[11px] text-slate-400 mt-6"><?php echo htmlspecialchars($institusi); ?></p>
</main>

<script src="assets/js/lucide.min.js"></script>
<script>lucide.createIcons();</script>
</body>
</html>
