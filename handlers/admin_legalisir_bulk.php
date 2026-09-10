<?php
/**
 * Ubah status beberapa pengajuan legalisir sekaligus.
 *
 * Setiap pengajuan tetap melalui apply_legalisir_status() yang sama dengan
 * perubahan satuan — token verifikasi, notifikasi, alasan penolakan, dan
 * penyusunan e-mail tidak ditulis ulang di sini.
 *
 * Yang perlu diingat saat memakainya di produksi: aksi ini MENGIRIM E-MAIL
 * SUNGGUHAN ke alumni sungguhan. Karena itu jumlahnya dibatasi, dimintakan
 * konfirmasi di layar, dan e-mail baru dikirim setelah seluruh perubahan
 * basis data berhasil di-commit.
 */

require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/error_page.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_capability('legalisir.kelola');
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/legalisir_lib.php';

/** Batas keras, terlepas dari ukuran halaman. */
const LEGALISIR_BULK_MAX = 100;

function bulk_kembali($params)
{
    // Bawa kembali seluruh saringan yang sedang aktif agar admin kembali ke
    // daftar yang sama, bukan ke halaman pertama tanpa saringan.
    $sisa = [];
    parse_str((string)($_POST['kembali'] ?? ''), $sisa);
    unset($sisa['page']);
    header('Location: ../index.php?' . http_build_query(array_merge(
        ['page' => 'admin_legalisir'], $sisa, $params)));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bulk_kembali([]);
}
validate_csrf();

$ids    = $_POST['ids'] ?? [];
$status = $_POST['status'] ?? '';
$alasan = trim((string)($_POST['rejection_reason'] ?? ''));

if (!is_array($ids) || !$ids) {
    bulk_kembali(['error' => 'bulk_kosong']);
}
if (!in_array($status, legalisir_valid_statuses(), true)) {
    bulk_kembali(['error' => 'bulk_status']);
}
if (count($ids) > LEGALISIR_BULK_MAX) {
    bulk_kembali(['error' => 'bulk_terlalu_banyak']);
}
if ($status === 'rejected' && mb_strlen($alasan) < 10) {
    // Penolakan massal tanpa alasan akan mengulang persis cacat yang baru
    // saja diperbaiki: sekumpulan alumni ditolak tanpa pernah diberi tahu
    // apa sebabnya.
    bulk_kembali(['error' => 'bulk_alasan']);
}

$ids = array_values(array_unique(array_map('strval', $ids)));

$berhasil = 0;
$gagal    = [];
$surat    = [];

try {
    $pdo->beginTransaction();
    foreach ($ids as $id) {
        $h = apply_legalisir_status($pdo, $id, $status, $alasan);
        if ($h['ok']) {
            $berhasil++;
            $surat[] = $h['email'];
        } else {
            $gagal[] = $id . ' (' . $h['error'] . ')';
        }
    }
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Aksi massal legalisir gagal: ' . $e->getMessage());
    bulk_kembali(['error' => 'bulk_gagal']);
}

// Baru sekarang, setelah commit: bila transaksi tadi dibatalkan, alumni
// tidak akan menerima kabar tentang perubahan yang tidak pernah terjadi.
send_legalisir_emails($surat);

$ringkas = "$berhasil pengajuan → " . legalisir_status_label($status);
if ($gagal) {
    $ringkas .= '; gagal: ' . implode(', ', array_slice($gagal, 0, 5));
}
log_activity('BULK_UPDATE_LEGALISIR', $ringkas);

bulk_kembali(['success' => 'bulk', 'n' => $berhasil, 'gagal' => count($gagal)]);
