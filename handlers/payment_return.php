<?php
/**
 * Tujuan kembali setelah pembayaran di halaman gateway.
 *
 * Dipakai sebagai redirect_url Flip dan callbacks.finish Midtrans.
 *
 * Halaman ini mengambil ulang status dari gateway SEBELUM mengarahkan
 * pengguna. Tanpa itu, alumni yang baru membayar tetap melihat "Menunggu
 * Pembayaran" sampai callback tiba — dan callback bisa terlambat beberapa
 * menit atau hilang.
 *
 * Tidak mensyaratkan sesi: alumni bisa kembali dari halaman bayar setelah
 * sesinya habis. Yang dilakukan hanyalah cek status (dibatasi 1x per 15
 * detik per transaksi) dan pengalihan ke halaman yang tetap wajib login.
 * Parameter tambahan yang ditempelkan gateway (order_id, status_code,
 * transaction_status) sengaja DIABAIKAN — tidak dipercaya.
 */
require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/payment/service.php';

$ref = substr((string)($_GET['ref'] ?? ''), 0, 64);
$txn = $ref !== '' ? payment_txn_by_merchant_ref($ref) : null;

if (!$txn) {
    header('Location: ../index.php?page=dashboard');
    exit();
}

payment_recheck($txn, 'return');
$txn = payment_txn_get($txn->id);

$hasil = $txn->status === 'paid' ? 'success' : ($txn->status === 'pending' ? 'pending' : 'failed');

switch ($txn->purpose) {
    case 'legalisir':
        $tujuan = 'index.php?page=legalisir_detail&id=' . rawurlencode($txn->subject_id) . '&payment=' . $hasil;
        break;
    case 'donasi':
        $tujuan = 'index.php?page=donasi&status=' . $hasil;
        break;
    case 'uji':
        $tujuan = 'index.php?page=admin_payment_gateway&uji=' . $hasil;
        break;
    default:
        $tujuan = 'index.php?page=dashboard';
}

header('Location: ../' . $tujuan);
exit();
