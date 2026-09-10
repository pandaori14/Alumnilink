<?php
/**
 * includes/error_page.php
 * ─────────────────────────────────────────────────────────
 * Halaman galat berdesain untuk menggantikan die("...") bertelanjang teks.
 *
 * MASALAH YANG DIPERBAIKI
 * Sebanyak 19 handler mengakhiri permintaan dengan die("Unauthorized access.")
 * atau die("Terjadi kesalahan sistem..."). Yang terlihat pengguna adalah
 * halaman putih polos berisi satu baris teks, tanpa identitas sistem, tanpa
 * tata letak, dan tanpa jalan kembali. Untuk sistem yang dipakai institusi,
 * ini merusak kredibilitas sekaligus membuat pengguna buntu.
 *
 * Berkas ini menyajikan halaman galat yang selaras dengan desain aplikasi,
 * memakai aset lokal yang sama, dan selalu menyediakan tautan kembali.
 *
 * PEMAKAIAN
 *     require_once __DIR__ . '/error_page.php';
 *     render_error_page('Akses Ditolak', 'Penjelasan singkat.', 403);
 *
 * Endpoint yang melayani JSON tetap membalas JSON -- bentuk balasan dideteksi
 * otomatis agar pemanggil fetch() tidak menerima HTML yang tak terduga.
 */

/**
 * Tampilkan halaman galat lalu hentikan permintaan.
 *
 * @param string $title   Judul singkat, mis. "Akses Ditolak".
 * @param string $message Penjelasan bagi pengguna.
 * @param int    $code    Kode status HTTP.
 * @param string $icon    Nama ikon Lucide.
 */
function render_error_page($title, $message, $code = 403, $icon = 'shield-alert')
{
    if (!headers_sent()) {
        http_response_code($code);
    }

    // Balas JSON bila pemanggilnya memang mengharapkan JSON.
    $wants_json = false;
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        $wants_json = true;
    } elseif (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        $wants_json = true;
    } elseif (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $wants_json = true;
    } else {
        foreach (headers_list() as $h) {
            if (stripos($h, 'content-type: application/json') !== false) {
                $wants_json = true;
                break;
            }
        }
    }

    if ($wants_json) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'status'  => 'error',
            'message' => $message,
        ]);
        exit;
    }

    // Path aset dihitung relatif terhadap akar aplikasi, bukan terhadap
    // direktori skrip pemanggil, agar tetap benar saat dipanggil dari
    // handlers/ maupun api/admin/.
    $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';

    $accent  = function_exists('brand_primary_color') ? brand_primary_color() : '#2563eb';
    $sysName = function_exists('setting') ? setting('system_name', 'AlumniLink') : 'AlumniLink';

    $isDenied = in_array($code, [401, 403], true);
    $tone     = $isDenied ? '#dc2626' : '#d97706';

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> — <?php echo htmlspecialchars($sysName); ?></title>
    <link rel="stylesheet" href="<?php echo $base; ?>/assets/css/app.css">
    <link rel="stylesheet" href="<?php echo $base; ?>/assets/fonts/fonts.css">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .outfit { font-family: 'Outfit', sans-serif; }
        .accent { color: <?php echo $accent; ?>; }
        .accent-bg { background-color: <?php echo $accent; ?>; }
        :focus-visible {
            outline: 3px solid <?php echo $accent; ?>;
            outline-offset: 2px;
            border-radius: 6px;
        }
    </style>
</head>
<body class="min-h-screen bg-slate-50 flex items-center justify-center p-6">
    <main class="w-full max-w-lg">
        <div class="bg-white border border-slate-200 rounded-3xl shadow-xl p-10 text-center">

            <div class="w-20 h-20 rounded-2xl flex items-center justify-center mx-auto mb-7 border"
                 style="background: <?php echo $tone; ?>14; border-color: <?php echo $tone; ?>33; color: <?php echo $tone; ?>;">
                <i data-lucide="<?php echo htmlspecialchars($icon); ?>" class="w-10 h-10" aria-hidden="true"></i>
            </div>

            <p class="text-[11px] font-black uppercase tracking-[0.2em] text-slate-400 mb-3">
                Kode <?php echo (int)$code; ?>
            </p>

            <h1 class="text-2xl font-black outfit text-slate-800 mb-4 tracking-tight">
                <?php echo htmlspecialchars($title); ?>
            </h1>

            <p class="text-sm text-slate-500 leading-relaxed mb-9">
                <?php echo htmlspecialchars($message); ?>
            </p>

            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                <a href="<?php echo $base; ?>/index.php?page=dashboard"
                   class="inline-flex items-center justify-center gap-2 px-7 py-3.5 accent-bg text-white rounded-2xl font-bold text-sm shadow-lg transition-transform active:scale-95">
                    <i data-lucide="layout-grid" class="w-4 h-4" aria-hidden="true"></i>
                    Kembali ke Beranda
                </a>
                <button type="button" onclick="history.back()"
                        class="inline-flex items-center justify-center gap-2 px-7 py-3.5 bg-white text-slate-600 border border-slate-200 rounded-2xl font-bold text-sm hover:bg-slate-50 transition-all active:scale-95">
                    <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i>
                    Halaman Sebelumnya
                </button>
            </div>
        </div>

        <p class="text-center text-[11px] text-slate-400 mt-6 font-medium">
            <?php echo htmlspecialchars($sysName); ?>
        </p>
    </main>

    <script src="<?php echo $base; ?>/assets/js/lucide.min.js"></script>
    <script>lucide.createIcons();</script>
</body>
</html>
    <?php
    exit;
}

/** Pintasan: akses ditolak. */
function error_forbidden($message = 'Anda tidak memiliki izin untuk mengakses halaman atau tindakan ini.')
{
    render_error_page('Akses Ditolak', $message, 403, 'shield-alert');
}

/** Pintasan: sesi tidak ditemukan. */
function error_unauthenticated($message = 'Sesi Anda tidak ditemukan atau telah berakhir. Silakan masuk kembali.')
{
    render_error_page('Sesi Berakhir', $message, 401, 'log-in');
}

/** Pintasan: kegagalan sistem. */
function error_system($message = 'Terjadi kesalahan sistem. Silakan coba lagi atau hubungi administrator.')
{
    render_error_page('Terjadi Kesalahan', $message, 500, 'alert-triangle');
}
