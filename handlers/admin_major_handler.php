<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';
require_once __DIR__ . '/../includes/auth_guard.php';
// Penjagaan berbasis kapabilitas menggantikan pola blacklist lama.
// Selama settings.rbac_enforce masih '0', pelanggaran hanya DICATAT ke
// Audit Trail sebagai 'RBAC_AUDIT' dan akses tetap diizinkan.
require_capability('master.kelola');
require_once '../includes/csrf.php';

// Check Admin Access
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'alumni') {
    header("Location: ../index.php?page=dashboard&error=unauthorized");
    exit();
}

$action = $_GET['action'] ?? '';

try {
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        validate_csrf();
        
        $id = $_POST['id'] ?? '';
        $major_code = trim($_POST['major_code'] ?? '');
        $major_name = trim($_POST['major_name'] ?? '');
        $faculty = trim($_POST['faculty'] ?? '');
        $accreditation = trim($_POST['accreditation'] ?? 'Unggul');

        if (!empty($id)) {
            // Update
            $stmt = $pdo->prepare("UPDATE majors SET major_code = ?, major_name = ?, faculty = ?, accreditation = ? WHERE id = ?");
            $stmt->execute([$major_code, $major_name, $faculty, $accreditation, $id]);
            log_activity('UPDATE_MAJOR', "User {$_SESSION['user_id']} updated major $major_code");
        } else {
            // Insert
            $stmt = $pdo->prepare("INSERT INTO majors (major_code, major_name, faculty, accreditation) VALUES (?, ?, ?, ?)");
            $stmt->execute([$major_code, $major_name, $faculty, $accreditation]);
            log_activity('CREATE_MAJOR', "User {$_SESSION['user_id']} created major $major_code");
        }

        header("Location: ../index.php?page=admin_majors&success=save");
        exit();
    } elseif ($action === 'delete' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        
        $stmt = $pdo->prepare("DELETE FROM majors WHERE id = ?");
        $stmt->execute([$id]);
        log_activity('DELETE_MAJOR', "User {$_SESSION['user_id']} deleted major ID $id");

        header("Location: ../index.php?page=admin_majors&success=delete");
        exit();
    } else {
        header("Location: ../index.php?page=admin_majors");
        exit();
    }
} catch (PDOException $e) {
    error_log("Major Handler Error: " . $e->getMessage());
    header("Location: ../index.php?page=admin_majors&error=db");
    exit();
}
