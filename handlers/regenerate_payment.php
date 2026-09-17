<?php
/**
 * Terbitkan ulang tagihan legalisir yang belum lunas.
 *
 * Perbedaan dari versi sebelumnya:
 *   - Menolak permohonan yang sudah lunas. Dulu tagihan baru tetap dibuat,
 *     sehingga alumni bisa membayar dua kali.
 *   - Tagihan lama dinonaktifkan di gateway sebelum yang baru terbit.
 *   - Memakai gateway AKTIF saat ini dengan nominal yang SAMA — alumni tidak
 *     ditagih berbeda hanya karena gateway dipindah setelah tagihannya terbit.
 *   - Staf wajib punya kapabilitas legalisir.kelola. Dulu setiap peran
 *     non-alumni boleh meregenerasi permohonan siapa pun.
 */
require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/payment/service.php';

validate_csrf();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || empty($_SESSION['user_id'])) {
    header("Location: ../index.php?page=legalisir");
    exit();
}

$is_alumni = ($_SESSION['user_role'] ?? '') === 'alumni';
if (!$is_alumni) {
    require_capability('legalisir.kelola');
}

$request_id = (string)($_POST['request_id'] ?? '');
$tujuan = function ($param) use ($is_alumni, $request_id) {
    $page = $is_alumni ? 'legalisir_detail&id=' . rawurlencode($request_id) : 'admin_legalisir';
    header("Location: ../index.php?page=$page&$param");
    exit();
};

if ($is_alumni) {
    $q = $pdo->prepare("SELECT lr.id, u.name, u.email, u.phone, u.address, lr.shipping_address
                          FROM legalisir_requests lr JOIN users u ON lr.user_id = u.id
                         WHERE lr.id = ? AND lr.user_id = ?");
    $q->execute([$request_id, $_SESSION['user_id']]);
} else {
    $q = $pdo->prepare("SELECT lr.id, u.name, u.email, u.phone, u.address, lr.shipping_address
                          FROM legalisir_requests lr JOIN users u ON lr.user_id = u.id
                         WHERE lr.id = ?");
    $q->execute([$request_id]);
}
$req = $q->fetch();
if (!$req) {
    $is_alumni ? header("Location: ../index.php?page=legalisir&error=not_found")
               : header("Location: ../index.php?page=admin_legalisir&error=not_found");
    exit();
}

$alamat = json_decode((string)$req->shipping_address, true) ?: [];
$hasil = payment_regenerate('legalisir', $req->id, [
    'name'    => $req->name,
    'email'   => $req->email,
    'phone'   => $alamat['phone'] ?? $req->phone,
    'address' => $req->address,
], $_SESSION['user_id']);

if ($hasil['ok']) {
    $tujuan('success=token_regenerated');
}
if ($hasil['txn'] && $hasil['txn']->status === 'paid') {
    $tujuan('error=already_paid');
}
$tujuan('error=payment_create_failed');
