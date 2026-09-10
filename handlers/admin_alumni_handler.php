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

if ($action === 'delete') {
    $id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'alumni'");
        $stmt->execute([$id]);
        header("Location: ../index.php?page=admin_alumni&success=deleted");
        exit();
    } catch (PDOException $e) {
        error_log("Admin Alumni Delete Error: " . $e->getMessage());
        error_system('Terjadi kesalahan sistem saat menghapus data alumni. Silakan hubungi administrator.');
    }
}
?>
