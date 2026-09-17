<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_capability('pembayaran.verifikasi');
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/payment/service.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] === 'alumni') {
    error_forbidden();
}

// Menandai LUNAS adalah aksi keuangan, namun dipicu lewat formulir GET di
// pages/admin_legalisir.php. csrf_field() pada formulir tersebut ikut
// terserialisasi ke query string, lalu diperiksa di sini.
validate_csrf_request();

$id = trim((string)($_GET['id'] ?? ''));
if ($id === '') {
    header("Location: ../index.php?page=admin_legalisir");
    exit();
}

// payment_verify_cash menolak permohonan yang SUDAH lunas. Versi lama
// menimpa payment_method menjadi 'cash' tanpa syarat, termasuk atas yang
// sudah dibayar online — pendapatan gateway lalu berpindah ke kolom tunai
// di laporan. Tagihan online yang masih terbuka juga ditutup, supaya tidak
// ada yang membayar lagi setelah uang tunai diterima.
$hasil = payment_verify_cash($id, $_SESSION['user_name'] ?? $_SESSION['user_id']);

if ($hasil['ok']) {
    log_activity('VERIFY_CASH_PAYMENT', "Pembayaran tunai legalisir $id diverifikasi manual.");
    header("Location: ../index.php?page=admin_legalisir&success=cash_verified&t=" . time());
} else {
    header("Location: ../index.php?page=admin_legalisir&error=verify_failed&reason="
        . rawurlencode((string)$hasil['error']) . "&t=" . time());
}
exit();
