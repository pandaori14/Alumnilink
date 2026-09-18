<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once __DIR__ . '/../includes/auth_guard.php';
// Penjagaan berbasis kapabilitas menggantikan pola blacklist lama.
// Selama settings.rbac_enforce masih '0', pelanggaran hanya DICATAT ke
// Audit Trail sebagai 'RBAC_AUDIT' dan akses tetap diizinkan.
require_capability('alumni.kelola');
require_once '../includes/csrf.php';
require_once __DIR__ . '/../includes/import_lib.php';

// Admin check
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'alumni') {
    error_forbidden();
}

validate_csrf();

$action = $_GET['action'] ?? '';

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $old_id = $_POST['old_id'] ?? '';
    $nim = $_POST['nim'];
    $name = $_POST['name'];
    $email = $_POST['email'];
    $major = $_POST['major'];
    $year = $_POST['graduation_year'];
    $ipk = $_POST['ipk'];
    $phone = $_POST['phone'];
    $address = $_POST['address'];
    $password = $_POST['password'] ?? '';

    // ── Pemeriksaan yang selama ini tidak ada ─────────────────────
    //
    // Sebelumnya tabrakan e-mail/NIM muncul sebagai PDOException mentah dan
    // berakhir di halaman "Terjadi kesalahan sistem" — admin tidak pernah
    // diberi tahu bahwa datanya memang sudah ada. Aturannya diambil dari
    // includes/import_lib.php supaya formulir ini dan impor massal menilai
    // dengan ukuran yang sama.
    $nim   = trim((string)$nim);
    $email = strtolower(trim((string)$email));

    if ($nim !== '' && !import_nim_is_valid($nim)) {
        header("Location: ../index.php?page=admin_alumni&error=nim_tidak_sah");
        exit();
    }

    $cek = $pdo->prepare("SELECT name FROM users WHERE LOWER(email) = ? AND id <> ? LIMIT 1");
    $cek->execute([$email, $old_id ?: '']);
    if ($bentrok = $cek->fetchColumn()) {
        header("Location: ../index.php?page=admin_alumni&error=email_ganda&nama=" . urlencode($bentrok));
        exit();
    }

    if ($nim !== '') {
        $cek = $pdo->prepare("SELECT name FROM users WHERE LOWER(nim) = LOWER(?) AND id <> ? LIMIT 1");
        $cek->execute([$nim, $old_id ?: '']);
        if ($bentrok = $cek->fetchColumn()) {
            header("Location: ../index.php?page=admin_alumni&error=nim_ganda&nama=" . urlencode($bentrok));
            exit();
        }
    }

    try {
        if ($old_id) {
            // Update Existing Alumni
            if ($password) {
                $pass_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET nim = ?, name = ?, email = ?, password = ?, major = ?, graduation_year = ?, ipk = ?, phone = ?, address = ? WHERE id = ?");
                $stmt->execute([$nim, $name, $email, $pass_hash, $major, $year, $ipk, $phone, $address, $old_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET nim = ?, name = ?, email = ?, major = ?, graduation_year = ?, ipk = ?, phone = ?, address = ? WHERE id = ?");
                $stmt->execute([$nim, $name, $email, $major, $year, $ipk, $phone, $address, $old_id]);
            }
            $success = "updated";
        } else {
            // Insert New Alumni (default is_verified=1 since added by admin)
            //
            // Id memakai pola pendaftaran mandiri, BUKAN NIM. Pola lama
            // "id = NIM" itulah yang melahirkan NIM ganda yang masih ada
            // sekarang: begitu satu baris memakai NIM sebagai id, baris
            // kedua dengan NIM sama tetap bisa masuk lewat jalur lain.
            // Baris LAMA tidak pernah diganti id-nya — empat tabel merujuk
            // users.id tanpa foreign key dan riwayatnya akan terputus.
            do {
                $new_id = import_generate_id();
                $adakah = $pdo->prepare("SELECT 1 FROM users WHERE id = ?");
                $adakah->execute([$new_id]);
            } while ($adakah->fetchColumn());

            $pass_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (id, nim, name, email, password, role, is_verified, major, graduation_year, ipk, phone, address) VALUES (?, ?, ?, ?, ?, 'alumni', 1, ?, ?, ?, ?, ?)");
            $stmt->execute([$new_id, $nim, $name, $email, $pass_hash, $major, $year, $ipk, $phone, $address]);
            $success = "added";
        }
        header("Location: ../index.php?page=admin_alumni&success=" . $success);
        exit();
    } catch (PDOException $e) {
        error_log("Admin Alumni Save Error: " . $e->getMessage());
        error_system('Terjadi kesalahan sistem saat menyimpan data alumni. Silakan hubungi administrator.');
    }
}

/**
 * Alasan sebuah akun tidak boleh dihapus. Kosong = boleh.
 *
 * users -> legalisir_requests memakai ON DELETE CASCADE, jadi menghapus satu
 * akun ikut menghapus seluruh pengajuannya. Untuk pengajuan yang SUDAH LUNAS
 * itu berarti menghapus jejak uang yang sudah diterima fakultas — persis yang
 * ditolak handlers/admin_delete_legalisir.php untuk satu pengajuan. Aturannya
 * disamakan supaya tidak ada pintu belakang lewat halaman pengguna.
 *
 * Ledger payment_transactions tidak ikut terhapus (sengaja tanpa FK), tetapi
 * laporan membaca legalisir_requests, jadi angkanya tetap berubah.
 */
function alasan_tidak_boleh_hapus(PDO $pdo, $id)
{
    $alasan = [];
    if ((string)$id === (string)($_SESSION['user_id'] ?? '')) {
        $alasan[] = 'Anda tidak dapat menghapus akun Anda sendiri.';
    }
    $q = $pdo->prepare("SELECT COUNT(*) FROM legalisir_requests WHERE user_id = ? AND payment_status = 'settlement'");
    $q->execute([$id]);
    if ($n = (int)$q->fetchColumn()) {
        $alasan[] = "Akun ini punya $n pengajuan legalisir yang sudah LUNAS. Menghapusnya ikut menghapus catatan uang itu dari Laporan Keuangan. Batalkan verifikasinya bila akun perlu dinonaktifkan.";
    }
    return $alasan;
}

if ($action === 'delete') {
    // Dulu: tautan GET tanpa token. validate_csrf() hanya memeriksa POST,
    // sehingga satu tautan yang dibuka admin — dari e-mail, dari halaman mana
    // pun — sudah cukup untuk menghapus alumni beserta seluruh pengajuannya.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        exit('Penghapusan hanya dapat dilakukan lewat formulir di halaman Kelola Alumni.');
    }
    validate_csrf_request();

    $id = (string)($_POST['id'] ?? '');
    $q = $pdo->prepare("SELECT name, email FROM users WHERE id = ? AND role = 'alumni'");
    $q->execute([$id]);
    $alumni = $q->fetch(PDO::FETCH_OBJ);
    if (!$alumni) {
        header("Location: ../index.php?page=admin_alumni&error=not_found");
        exit();
    }
    if ($alasan = alasan_tidak_boleh_hapus($pdo, $id)) {
        header("Location: ../index.php?page=admin_alumni&error=delete_blocked&reason=" . rawurlencode(implode(' ', $alasan)));
        exit();
    }
    try {
        $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'alumni'")->execute([$id]);
        log_activity('DELETE_ALUMNI', "Alumni {$alumni->name} ({$alumni->email}, $id) dihapus oleh "
            . ($_SESSION['user_name'] ?? $_SESSION['user_id']) . '.');
        header("Location: ../index.php?page=admin_alumni&success=deleted");
        exit();
    } catch (PDOException $e) {
        error_log("Admin Alumni Delete Error: " . $e->getMessage());
        error_system('Terjadi kesalahan sistem saat menghapus data alumni. Silakan hubungi administrator.');
    }
}
?>
