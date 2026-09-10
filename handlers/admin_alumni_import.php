<?php
/**
 * Impor massal alumni dari CSV.
 *
 * Dua langkah, dan langkah pertama TIDAK MENULIS APA PUN:
 *
 *   ?action=preview  unggah + parse + nilai  -> hasilnya disimpan di sesi
 *   ?action=commit   tulis, satu transaksi   -> semua berhasil atau semua batal
 *   ?action=errors   unduh CSV berisi baris yang gagal, untuk diperbaiki
 *
 * Pratinjau bersifat wajib karena ini menulis ke tabel `users` di sistem
 * yang sedang berjalan. Satu berkas yang salah kolom, tanpa pratinjau,
 * cukup untuk mengotori seluruh basis data alumni.
 *
 * Hasil parse disimpan di $_SESSION, bukan sebagai berkas di server:
 * tidak perlu direktori unggahan baru, tidak perlu aturan .htaccess baru,
 * dan tidak ada berkas berisi data pribadi yang tertinggal bila operator
 * menutup peramban di tengah jalan.
 */

require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_capability('alumni.kelola');
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/import_lib.php';

$action = $_GET['action'] ?? '';
$KEMBALI = '../index.php?page=admin_alumni_import';

/** Berapa lama hasil pratinjau masih boleh di-commit. */
const IMPOR_KEDALUWARSA_DETIK = 1800; // 30 menit

function impor_kembali($params)
{
    global $KEMBALI;
    header('Location: ' . $KEMBALI . '&' . http_build_query($params));
    exit;
}

// ─────────────────────────────────────────────────────────────────────
// UNDUH BERKAS CONTOH — tidak mengubah apa pun, jadi cukup GET
// ─────────────────────────────────────────────────────────────────────
if ($action === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="contoh_impor_alumni.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");           // BOM, agar Excel membaca UTF-8
    fputcsv($out, import_template_header());
    // Satu baris contoh memakai kode prodi yang benar-benar ada.
    $prodi = $pdo->query("SELECT major_code FROM majors ORDER BY major_code LIMIT 1")->fetchColumn() ?: 'J500';
    fputcsv($out, ['J500123456', 'Nama Lengkap Alumni', 'alumni@contoh.ac.id', date('Y'), $prodi, '3.45', '081234567890', 'Jl. Contoh No. 1, Surakarta']);
    fclose($out);
    exit;
}

// ─────────────────────────────────────────────────────────────────────
// UNDUH DAFTAR BARIS BERMASALAH
// ─────────────────────────────────────────────────────────────────────
if ($action === 'errors') {
    $sesi = $_SESSION['alumni_import'] ?? null;
    if (!$sesi || !isset($sesi['rows'])) {
        impor_kembali(['error' => 'sesi_habis']);
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="baris_bermasalah.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_merge(['Baris', 'Status', 'Keterangan'], import_template_header()));
    foreach ($sesi['rows'] as $r) {
        if ($r['verdict'] === 'create') {
            continue;
        }
        fputcsv($out, [
            $r['_line'],
            import_verdict_meta($r['verdict'])['label'],
            implode(' ', $r['messages']),
            $r['nim'] ?? '', $r['name'], $r['email'],
            $r['graduation_year'] ?? '', $r['major'] ?? '',
            $r['ipk'] ?? '', $r['phone'] ?? '', $r['address'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// Sisanya mengubah keadaan -> wajib POST + CSRF.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    impor_kembali(['error' => 'metode']);
}
validate_csrf();

// ─────────────────────────────────────────────────────────────────────
// LANGKAH 1 — PRATINJAU (tidak menulis apa pun)
// ─────────────────────────────────────────────────────────────────────
if ($action === 'preview') {
    check_rate_limit('IMPORT_ALUMNI',
        setting_int('rate_limit_import_alumni_max', 5, 1),
        setting_int('rate_limit_import_alumni_window', 15, 1));

    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        impor_kembali(['error' => 'unggah']);
    }

    $tmp   = $_FILES['csv']['tmp_name'];
    $asli  = $_FILES['csv']['name'];
    $ukuran = (int)$_FILES['csv']['size'];
    $ext   = strtolower(pathinfo($asli, PATHINFO_EXTENSION));

    if ($ukuran > setting_int('import_max_bytes', 2097152, 1024)) {
        impor_kembali(['error' => 'ukuran']);
    }
    if (in_array($ext, ['xlsx', 'xls'], true)) {
        // Tidak ada pembaca spreadsheet di proyek ini, dan menambahkannya
        // melanggar sifat "tinggal upload lewat FTP tanpa dependensi".
        impor_kembali(['error' => 'xlsx']);
    }
    if (!in_array($ext, ['csv', 'txt'], true)) {
        impor_kembali(['error' => 'ekstensi']);
    }

    // finfo melaporkan text/plain untuk sebagian besar CSV — itu WAJIB ada
    // di daftar terima, kalau tidak semua berkas sah akan ditolak.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $tmp);
    finfo_close($finfo);
    $mime_ok = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel', 'text/x-csv'];
    if (!in_array($mime, $mime_ok, true)) {
        impor_kembali(['error' => 'mime', 'mime' => $mime]);
    }

    $baca = import_read_csv($tmp, setting_int('import_max_rows', 2000, 1));
    if (!$baca['ok']) {
        $_SESSION['alumni_import_error'] = $baca['error'];
        impor_kembali(['error' => 'parse']);
    }

    $nilai = import_evaluate($pdo, $baca['rows']);

    $_SESSION['alumni_import'] = [
        'token'     => bin2hex(random_bytes(16)),
        'dibuat'    => time(),
        'berkas'    => $asli,
        'sha'       => hash_file('sha256', $tmp),
        'rows'      => $nilai['rows'],
        'ringkasan' => $nilai['ringkasan'],
    ];
    unset($_SESSION['alumni_import_error']);
    reset_rate_limit('IMPORT_ALUMNI');

    log_activity('IMPORT_ALUMNI_PREVIEW',
        'Pratinjau impor "' . $asli . '": ' . count($nilai['rows']) . ' baris ('
        . $nilai['ringkasan']['create'] . ' baru, '
        . $nilai['ringkasan']['update'] . ' lengkapi, '
        . $nilai['ringkasan']['conflict'] . ' bentrok, '
        . $nilai['ringkasan']['error'] . ' tidak sah)');

    impor_kembali(['preview' => 1]);
}

// ─────────────────────────────────────────────────────────────────────
// LANGKAH 2 — COMMIT
// ─────────────────────────────────────────────────────────────────────
if ($action === 'commit') {
    $sesi = $_SESSION['alumni_import'] ?? null;
    if (!$sesi || empty($sesi['rows'])) {
        impor_kembali(['error' => 'sesi_habis']);
    }
    if (($_POST['token'] ?? '') !== $sesi['token']) {
        impor_kembali(['error' => 'token']);
    }
    if (time() - $sesi['dibuat'] > IMPOR_KEDALUWARSA_DETIK) {
        unset($_SESSION['alumni_import']);
        impor_kembali(['error' => 'kedaluwarsa']);
    }

    // Mati-matikan bawaannya: impor pertama yang dijalankan siapa pun tidak
    // boleh bisa mengubah satu baris pun yang sudah ada.
    $boleh_perbarui = isset($_POST['izinkan_perbarui']);

    $dibuat = 0; $diperbarui = 0; $dilewati = 0;
    $catatan_lewat = [];

    try {
        $pdo->beginTransaction();

        // Muat ulang di DALAM transaksi: pratinjau bisa sudah beberapa menit
        // umurnya, dan orang lain mungkin menambah data di sela itu.
        $email_kini = [];
        foreach ($pdo->query("SELECT id, LOWER(email) AS email FROM users WHERE email IS NOT NULL") as $u) {
            $email_kini[$u->email] = $u->id;
        }
        $nim_kini = [];
        foreach ($pdo->query("SELECT id, LOWER(nim) AS nim FROM users WHERE nim IS NOT NULL AND nim <> ''") as $u) {
            if (!isset($nim_kini[$u->nim])) { $nim_kini[$u->nim] = $u->id; }
        }

        $sel_id = $pdo->prepare("SELECT 1 FROM users WHERE id = ?");
        $ins = $pdo->prepare(
            "INSERT INTO users (id, nim, name, email, password, role, is_verified, major, graduation_year, ipk, phone, address)
             VALUES (?, ?, ?, ?, ?, 'alumni', 1, ?, ?, ?, ?, ?)"
        );

        foreach ($sesi['rows'] as $r) {
            $email = $r['email'];
            $nim_l = $r['nim'] !== null ? strtolower($r['nim']) : '';

            if ($r['verdict'] === 'error' || $r['verdict'] === 'conflict') {
                $dilewati++;
                continue;
            }

            // ── Baris yang cocok dengan pengguna yang sudah ada ──
            if (isset($email_kini[$email])) {
                if (!$boleh_perbarui) {
                    $dilewati++;
                    continue;
                }
                $target = $email_kini[$email];

                // NIM di baris ini ternyata milik orang lain -> jangan sentuh.
                if ($nim_l !== '' && isset($nim_kini[$nim_l]) && $nim_kini[$nim_l] !== $target) {
                    $dilewati++;
                    $catatan_lewat[] = "baris {$r['_line']}: NIM bentrok saat commit";
                    continue;
                }

                // HANYA mengisi kolom yang masih kosong. Nilai yang sudah
                // ada tidak pernah ditimpa — impor tidak boleh diam-diam
                // menghapus data yang sudah dikurasi seseorang.
                $sets = []; $vals = [];
                foreach (['nim', 'major', 'graduation_year', 'ipk', 'phone', 'address'] as $kol) {
                    if ($r[$kol] !== null && $r[$kol] !== '') {
                        $sets[] = "$kol = COALESCE(NULLIF($kol, ''), ?)";
                        $vals[] = $r[$kol];
                    }
                }
                if (!$sets) {
                    $dilewati++;
                    continue;
                }
                $vals[] = $target;
                $pdo->prepare("UPDATE users SET " . implode(', ', $sets)
                    . " WHERE id = ? AND role = 'alumni'")->execute($vals);
                $diperbarui++;
                if ($nim_l !== '') { $nim_kini[$nim_l] = $target; }
                continue;
            }

            // ── Baris baru ────────────────────────────────────────
            if ($nim_l !== '' && isset($nim_kini[$nim_l])) {
                // Muncul di antara pratinjau dan commit.
                $dilewati++;
                $catatan_lewat[] = "baris {$r['_line']}: NIM sudah terpakai saat commit";
                continue;
            }

            // Id mengikuti pola pendaftaran mandiri, BUKAN id = NIM.
            // Pola id = NIM itulah yang melahirkan NIM ganda yang ada.
            do {
                $id_baru = import_generate_id();
                $sel_id->execute([$id_baru]);
            } while ($sel_id->fetchColumn());

            // Kata sandi acak yang tidak mungkin ditebak, bukan NULL:
            // alumni menggantinya lewat "Lupa Kata Sandi". Dengan begitu
            // tidak perlu memastikan apakah proses masuk aman terhadap
            // hash NULL.
            $ins->execute([
                $id_baru, $r['nim'], $r['name'], $email,
                password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                $r['major'], $r['graduation_year'], $r['ipk'], $r['phone'], $r['address'],
            ]);
            $dibuat++;
            $email_kini[$email] = $id_baru;
            if ($nim_l !== '') { $nim_kini[$nim_l] = $id_baru; }
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Impor alumni gagal: ' . $e->getMessage());
        $_SESSION['alumni_import_error'] = 'Impor dibatalkan seluruhnya; tidak ada baris yang tertulis.';
        impor_kembali(['error' => 'gagal']);
    }

    $ringkas = "$dibuat dibuat, $diperbarui dilengkapi, $dilewati dilewati — berkas \"{$sesi['berkas']}\"";
    if ($catatan_lewat) {
        $ringkas .= ' (' . implode('; ', array_slice($catatan_lewat, 0, 5)) . ')';
    }
    log_activity('IMPORT_ALUMNI', $ringkas);
    // Impor harus terlihat oleh orang lain selain yang menjalankannya.
    notify_roles(['super_admin'], 'Impor data alumni',
        ($_SESSION['user_name'] ?? 'Seorang admin') . " mengimpor data alumni: $ringkas.",
        'info', 'index.php?page=admin_logs');

    unset($_SESSION['alumni_import']);
    impor_kembali(['success' => 1, 'dibuat' => $dibuat, 'diperbarui' => $diperbarui, 'dilewati' => $dilewati]);
}

// ─────────────────────────────────────────────────────────────────────
if ($action === 'batal') {
    unset($_SESSION['alumni_import'], $_SESSION['alumni_import_error']);
    impor_kembali([]);
}

impor_kembali(['error' => 'aksi']);
