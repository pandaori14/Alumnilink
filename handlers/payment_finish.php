<?php
/**
 * Tujuan lama setelah popup Snap selesai (?id=LEG-...|DON-...&status=...).
 *
 * Dipertahankan untuk halaman versi lama yang masih memanggilnya selama
 * jendela upload. Tidak lagi mengarahkan berdasarkan ?status= dari browser;
 * semuanya diteruskan ke payment_return.php yang mengambil ulang status
 * dari gateway.
 */
require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/payment/service.php';

$id = substr((string)($_GET['id'] ?? ''), 0, 64);
$txn = null;

if ($id !== '') {
    $txn = payment_txn_by_merchant_ref($id);
    if (!$txn && strpos($id, 'LEG-') === 0) {
        $txn = payment_txn_latest('legalisir', $id);
    }
}

if ($txn) {
    header('Location: payment_return.php?ref=' . rawurlencode($txn->merchant_ref));
} else {
    header('Location: ../index.php?page=dashboard');
}
exit();
