<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/db.php';

$id = $_GET['id'] ?? null;
$status = $_GET['status'] ?? null;

if ($id) {
    if (strpos($id, 'LEG-') === 0) {
        header("Location: ../index.php?page=legalisir_detail&id=" . $id . "&payment=" . $status);
    } else if (strpos($id, 'DON-') === 0) {
        header("Location: ../index.php?page=donasi&status=" . $status);
    } else {
        header("Location: ../index.php?page=dashboard");
    }
    exit();
} else {
    header("Location: ../index.php?page=dashboard");
    exit();
}
?>
