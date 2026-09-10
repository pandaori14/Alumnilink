<?php
/**
 * api/notifications.php
 * ─────────────────────────────────────────────────────────
 * Endpoint JSON untuk Pusat Notifikasi.
 *
 * Menggantikan aksi berbasis GET pada pages/notifications.php yang memuat ulang
 * seluruh halaman untuk setiap tindakan kecil.
 *
 * CATATAN KEAMANAN — celah yang ditutup di sini
 * Implementasi lama menerima tujuan pengalihan dari parameter URL:
 *     index.php?page=notifications&action=read_go&id=5&to=<URL apa pun>
 * lalu meneruskannya ke window.location.href. Siapa pun dapat menyusun tautan
 * yang tampak berasal dari sistem ini namun mengarahkan korban ke situs lain
 * (open redirect) -- pola yang lazim dipakai pada penipuan phishing.
 *
 * Di sini tujuan pengalihan SELALU dibaca dari kolom `link` milik baris
 * notifikasi di basis data, dan hanya setelah kepemilikannya diverifikasi.
 * Parameter dari pengguna tidak pernah dipakai sebagai tujuan.
 *
 * AKSI
 *   GET  ?action=list&filter=unread|all&limit=N
 *   GET  ?action=count
 *   POST action=read      { id }        -> tandai satu dibaca, kembalikan link
 *   POST action=read_all                -> tandai seluruhnya dibaca
 *   POST action=delete    { id }        -> hapus satu notifikasi
 *   POST action=delete_read             -> hapus seluruh yang sudah dibaca
 */

require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi tidak ditemukan.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action  = $_GET['action'] ?? $_POST['action'] ?? '';

// Seluruh tindakan yang mengubah data wajib membawa token CSRF.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_request(true);
}

/**
 * Ubah stempel waktu menjadi keterangan relatif berbahasa Indonesia.
 * Notifikasi terasa jauh lebih hidup dengan "2 jam lalu" ketimbang
 * "12 Aug 2026 - 19:26".
 */
function waktu_relatif($timestamp)
{
    $selisih = time() - strtotime($timestamp);

    if ($selisih < 60)      return 'Baru saja';
    if ($selisih < 3600)    return floor($selisih / 60) . ' menit lalu';
    if ($selisih < 86400)   return floor($selisih / 3600) . ' jam lalu';
    if ($selisih < 172800)  return 'Kemarin';
    if ($selisih < 604800)  return floor($selisih / 86400) . ' hari lalu';
    if ($selisih < 2592000) return floor($selisih / 604800) . ' minggu lalu';

    return date('d M Y', strtotime($timestamp));
}

/** Kelompok tanggal untuk pemisah pada daftar. */
function grup_tanggal($timestamp)
{
    $tgl = date('Y-m-d', strtotime($timestamp));
    if ($tgl === date('Y-m-d'))                          return 'Hari Ini';
    if ($tgl === date('Y-m-d', strtotime('-1 day')))     return 'Kemarin';
    if (strtotime($timestamp) > strtotime('-7 days'))    return 'Minggu Ini';
    return 'Lebih Lama';
}

/** Bentuk satu baris notifikasi menjadi struktur yang siap dipakai antarmuka. */
function bentuk_notifikasi($n)
{
    $peta = [
        'success' => ['icon' => 'check-circle-2', 'tone' => 'emerald'],
        'warning' => ['icon' => 'alert-triangle', 'tone' => 'amber'],
        'error'   => ['icon' => 'x-circle',       'tone' => 'red'],
        'info'    => ['icon' => 'info',           'tone' => 'blue'],
    ];
    $gaya = $peta[$n->type] ?? $peta['info'];

    return [
        'id'         => (int)$n->id,
        'title'      => $n->title,
        'message'    => $n->message,
        'type'       => $n->type,
        'icon'       => $gaya['icon'],
        'tone'       => $gaya['tone'],
        'is_read'    => (int)$n->is_read === 1,
        'has_link'   => !empty($n->link),
        'created_at' => $n->created_at,
        'relative'   => waktu_relatif($n->created_at),
        'group'      => grup_tanggal($n->created_at),
        'absolute'   => date('d M Y, H:i', strtotime($n->created_at)),
    ];
}

try {
    switch ($action) {

        // ── Daftar notifikasi ────────────────────────────────────────
        case 'list':
            $filter = ($_GET['filter'] ?? 'unread') === 'all' ? 'all' : 'unread';
            $limit  = min(100, max(1, (int)($_GET['limit'] ?? 30)));

            $sql = "SELECT * FROM notifications WHERE user_id = ?";
            if ($filter === 'unread') {
                $sql .= " AND is_read = 0";
            }
            $sql .= " ORDER BY created_at DESC LIMIT " . $limit;

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_id]);

            $items = array_map('bentuk_notifikasi', $stmt->fetchAll());

            $unread = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $unread->execute([$user_id]);

            $total = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
            $total->execute([$user_id]);

            echo json_encode([
                'success' => true,
                'items'   => $items,
                'unread'  => (int)$unread->fetchColumn(),
                'total'   => (int)$total->fetchColumn(),
            ]);
            break;

        // ── Jumlah belum dibaca (untuk lencana) ──────────────────────
        case 'count':
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$user_id]);
            echo json_encode(['success' => true, 'unread' => (int)$stmt->fetchColumn()]);
            break;

        // ── Tandai satu dibaca, kembalikan tujuan dari BASIS DATA ────
        case 'read':
            $id = (int)($_POST['id'] ?? 0);

            // Ambil dulu sekaligus verifikasi kepemilikan.
            $stmt = $pdo->prepare("SELECT link FROM notifications WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $user_id]);
            $row = $stmt->fetch();

            if (!$row) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Notifikasi tidak ditemukan.']);
                break;
            }

            $upd = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $upd->execute([$id, $user_id]);

            $sisa = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $sisa->execute([$user_id]);

            // Tujuan berasal dari basis data, bukan dari masukan pengguna.
            // Tautan absolut ke domain luar sengaja ditolak.
            $link = $row->link;
            if ($link && preg_match('#^(https?:)?//#i', $link)) {
                $link = null;
            }

            echo json_encode([
                'success' => true,
                'link'    => $link,
                'unread'  => (int)$sisa->fetchColumn(),
            ]);
            break;

        // ── Tandai seluruhnya dibaca ─────────────────────────────────
        case 'read_all':
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$user_id]);
            echo json_encode(['success' => true, 'affected' => $stmt->rowCount(), 'unread' => 0]);
            break;

        // ── Hapus satu ───────────────────────────────────────────────
        case 'delete':
            $id   = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $user_id]);

            $sisa = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $sisa->execute([$user_id]);

            echo json_encode([
                'success' => $stmt->rowCount() > 0,
                'unread'  => (int)$sisa->fetchColumn(),
            ]);
            break;

        // ── Bersihkan riwayat yang sudah dibaca ──────────────────────
        case 'delete_read':
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1");
            $stmt->execute([$user_id]);
            echo json_encode(['success' => true, 'affected' => $stmt->rowCount()]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenal.']);
    }
} catch (PDOException $e) {
    error_log('Notifications API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem.']);
}
