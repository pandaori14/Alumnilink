<?php
/**
 * serve_document.php
 * ─────────────────────────────────────────────────────────
 * Pelayan berkas dokumen dengan pemeriksaan otorisasi.
 *
 * LATAR BELAKANG
 * Berkas hasil unggahan berada di dalam web root dan sebelumnya ditautkan
 * secara langsung, misalnya:
 *     https://host/alumnilink/uploads/legalisir/ijazah_L200160042_1778141213.pdf
 *
 * uploads/.htaccess hanya mencegah EKSEKUSI skrip, bukan PEMBACAAN berkas,
 * sehingga scan ijazah dan transkrip dapat diunduh siapa pun yang mengetahui
 * atau menebak nama berkasnya. Pola namanya sendiri mudah ditebak karena
 * memuat NIM dan stempel waktu Unix.
 *
 * Berkas ini menutup celah tersebut. Folder dokumen sensitif ditutup lewat
 * .htaccess masing-masing, dan seluruh akses harus melalui skrip ini.
 *
 * PRINSIP KEAMANAN
 * Skrip ini TIDAK PERNAH menerima path berkas dari pengguna. Pemanggil hanya
 * menyebutkan dokumen mana yang diinginkan (id pengajuan + indeks), lalu path
 * sebenarnya diambil dari database. Dengan demikian path traversal tidak
 * mungkin terjadi. Sebagai lapisan kedua, path hasil resolusi tetap
 * diverifikasi masih berada di dalam direktori yang diizinkan.
 *
 * MODE AKSES
 *   ?ctx=legalisir&req=<id>&i=<n>
 *       Pemilik pengajuan, atau staf mana pun. Memakai sesi.
 *   ?ctx=softcopy&req=<id>&token=<token>&i=<n>
 *       URL kapabilitas untuk lembar verifikasi. Token harus cocok dan status
 *       pengajuan harus 'completed' -- aturan yang sama dengan view_softcopy.php.
 *   ?ctx=accreditation&id=<n>
 *       Sertifikat akreditasi master. Staf saja.
 *   ?ctx=repository&id=<n>
 *       Dokumen resmi dari repositori. Staf saja.
 */

require_once __DIR__ . '/config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/includes/session_boot.php';
}

/** Hentikan permintaan dengan status dan pesan singkat. */
function doc_deny($code, $message)
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

/**
 * Direktori yang boleh dilayani, dalam bentuk path absolut ternormalisasi.
 *
 * Sengaja mencakup SELURUH uploads/, bukan hanya tiga subfolder dokumen.
 * Alasannya: sebagian baris lama di document_repository menyimpan path yang
 * menunjuk langsung ke akar uploads/ (mis. 'uploads/ijazah_profesi.pdf') atau
 * ke uploads/legalisir/, warisan dari sebelum penataan folder. Membatasi ke
 * tiga subfolder saja akan membuat baris-baris lama itu berhenti tampil.
 *
 * Pelonggaran ini tidak mengurangi keamanan: path tidak pernah berasal dari
 * pengguna melainkan selalu dari database, otorisasi sudah diperiksa di
 * pengatur alur di bawah, dan batas ini tetap mencegah pembacaan menembus
 * keluar uploads/ menuju config/, .env, atau berkas sistem lain.
 */
function doc_allowed_roots()
{
    $abs = realpath(__DIR__ . '/uploads');
    return $abs === false ? [] : [$abs];
}

/**
 * Ubah path relatif dari database menjadi path absolut yang sudah dipastikan
 * berada di dalam salah satu direktori yang diizinkan.
 *
 * @return string|false Path absolut, atau false bila tidak valid.
 */
function doc_resolve_safe_path($relative_path)
{
    if (!$relative_path || !is_string($relative_path)) {
        return false;
    }

    // Tolak URL absolut -- skrip ini hanya melayani berkas lokal.
    if (filter_var($relative_path, FILTER_VALIDATE_URL)) {
        return false;
    }

    $absolute = realpath(__DIR__ . '/' . ltrim($relative_path, '/\\'));
    if ($absolute === false || !is_file($absolute)) {
        return false;
    }

    // Lapisan kedua: pastikan hasil resolusi benar-benar di dalam direktori
    // yang diizinkan, bukan di tempat lain lewat symlink atau '..'.
    foreach (doc_allowed_roots() as $root) {
        if (strpos($absolute, $root . DIRECTORY_SEPARATOR) === 0) {
            return $absolute;
        }
    }

    return false;
}

/** Petakan tahun lulus + prodi alumni ke sertifikat akreditasi master. */
function doc_master_accreditation($pdo, $major_raw, $year)
{
    $target_major_code = '';
    foreach ($pdo->query("SELECT major_code, major_name FROM majors")->fetchAll() as $m) {
        if (strcasecmp((string)$major_raw, $m->major_code) === 0 ||
            strcasecmp((string)$major_raw, $m->major_name) === 0) {
            $target_major_code = $m->major_code;
            break;
        }
    }
    if ($target_major_code === '') {
        $target_major_code = default_major_code(); // settings.default_major_code
    }

    $stmt = $pdo->prepare(
        "SELECT file_path FROM accreditation_certificates
         WHERE major_code = ? AND start_year <= ? AND end_year >= ?
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$target_major_code, (int)$year, (int)$year]);
    return $stmt->fetchColumn();
}

/**
 * Ambil path berkas untuk satu dokumen di dalam sebuah pengajuan legalisir.
 * Meniru logika view_softcopy.php, termasuk penanganan lampiran akreditasi
 * otomatis yang tidak menyimpan path melainkan teks penanda.
 */
function doc_path_from_request($pdo, $request, $index)
{
    $docs = json_decode($request->documents);
    if (!is_array($docs) || !isset($docs[$index])) {
        return false;
    }

    $doc  = $docs[$index];
    $path = is_object($doc) ? ($doc->file ?? null) : $doc;

    if (!$path || $path === '#') {
        return false;
    }

    // Lampiran akreditasi otomatis: bukan path, melainkan teks penanda.
    if (stripos($path, 'Otomatis terlampir') !== false) {
        $path = doc_master_accreditation($pdo, $request->alumni_major, $request->alumni_year);
    }

    return $path;
}

/** Kirim berkas ke browser untuk ditampilkan inline. */
function doc_stream($absolute_path)
{
    $ext = strtolower(pathinfo($absolute_path, PATHINFO_EXTENSION));
    $types = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];

    if (!isset($types[$ext])) {
        doc_deny(415, 'Tipe berkas tidak didukung.');
    }

    header('Content-Type: ' . $types[$ext]);
    header('Content-Length: ' . filesize($absolute_path));
    header('Content-Disposition: inline; filename="' . basename($absolute_path) . '"');
    // Dokumen bersifat pribadi -- jangan disimpan cache bersama oleh proxy.
    header('Cache-Control: private, max-age=600');
    header('X-Content-Type-Options: nosniff');

    readfile($absolute_path);
    exit;
}

// ─────────────────────────────────────────────────────────
// PENGATUR ALUR
// ─────────────────────────────────────────────────────────

$ctx   = $_GET['ctx'] ?? '';
$index = isset($_GET['i']) ? (int)$_GET['i'] : 0;

$is_staff = isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? 'alumni') !== 'alumni';

switch ($ctx) {

    case 'legalisir':
        // Pemilik pengajuan, atau staf.
        if (!isset($_SESSION['user_id'])) {
            doc_deny(401, 'Silakan masuk terlebih dahulu.');
        }

        $req_id = $_GET['req'] ?? '';
        $stmt = $pdo->prepare(
            "SELECT lr.documents, lr.user_id, u.major AS alumni_major, u.graduation_year AS alumni_year
             FROM legalisir_requests lr
             JOIN users u ON lr.user_id = u.id
             WHERE lr.id = ?"
        );
        $stmt->execute([$req_id]);
        $request = $stmt->fetch();

        if (!$request) {
            doc_deny(404, 'Pengajuan tidak ditemukan.');
        }

        if (!$is_staff && $request->user_id !== $_SESSION['user_id']) {
            doc_deny(403, 'Dokumen ini bukan milik akun Anda.');
        }

        $path = doc_path_from_request($pdo, $request, $index);
        break;

    case 'softcopy':
        // URL kapabilitas: token + status 'completed', tanpa memerlukan sesi.
        // Aturan identik dengan view_softcopy.php agar tidak ada jalur yang
        // lebih longgar daripada halaman yang menampilkannya.
        $req_id = $_GET['req'] ?? '';
        $token  = $_GET['token'] ?? '';

        if ($req_id === '' || $token === '') {
            doc_deny(403, 'Akses ditolak.');
        }

        $stmt = $pdo->prepare(
            "SELECT lr.documents, u.major AS alumni_major, u.graduation_year AS alumni_year
             FROM legalisir_requests lr
             JOIN users u ON lr.user_id = u.id
             WHERE lr.id = ? AND lr.verification_token = ? AND lr.status = 'completed'"
        );
        $stmt->execute([$req_id, $token]);
        $request = $stmt->fetch();

        if (!$request) {
            doc_deny(403, 'Akses ditolak.');
        }

        $path = doc_path_from_request($pdo, $request, $index);
        break;

    case 'accreditation':
        if (!$is_staff) {
            doc_deny(403, 'Akses ditolak.');
        }
        $stmt = $pdo->prepare("SELECT file_path FROM accreditation_certificates WHERE id = ?");
        $stmt->execute([(int)($_GET['id'] ?? 0)]);
        $path = $stmt->fetchColumn();
        break;

    case 'repository':
        if (!$is_staff) {
            doc_deny(403, 'Akses ditolak.');
        }
        $stmt = $pdo->prepare("SELECT file_path FROM document_repository WHERE id = ?");
        $stmt->execute([(int)($_GET['id'] ?? 0)]);
        $path = $stmt->fetchColumn();
        break;

    default:
        doc_deny(400, 'Konteks dokumen tidak dikenal.');
}

$absolute = doc_resolve_safe_path($path);

if ($absolute === false) {
    doc_deny(404, 'Berkas dokumen tidak tersedia.');
}

doc_stream($absolute);
