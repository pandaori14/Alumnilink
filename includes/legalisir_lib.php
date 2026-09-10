<?php
/**
 * Logika transisi status legalisir — dipakai bersama.
 *
 * Perubahan status legalisir bukan satu UPDATE. Ia menerbitkan
 * verification_token sekali seumur pengajuan, menulis notifikasi dalam
 * aplikasi, mengirim e-mail status, dan pada status 'completed' mengirim
 * e-mail softcopy berisi tautan verifikasi.
 *
 * Bila handler perubahan satuan dan handler perubahan massal masing-masing
 * menyalin rangkaian itu, keduanya akan menyimpang dalam satu-dua rilis:
 * yang satu berhenti mengirim e-mail, atau menerbitkan token kedua yang
 * membuat tautan verifikasi lama mati diam-diam. Karena itu keduanya
 * memanggil fungsi di bawah.
 */

require_once __DIR__ . '/settings.php';

/** Status yang sah — dipakai sebagai daftar tertutup di seluruh handler. */
function legalisir_valid_statuses()
{
    return ['pending', 'processing', 'completed', 'rejected'];
}

function legalisir_status_label($status)
{
    $peta = [
        'pending'    => 'Menunggu Pembayaran',
        'processing' => 'Sedang Diproses',
        'completed'  => 'Selesai & Terverifikasi',
        'rejected'   => 'Ditolak',
    ];
    return $peta[$status] ?? $status;
}

/** Ambang SLA (hari) dari pengaturan. */
function legalisir_sla_warn()   { return setting_int('legalisir_sla_warn_days', 3, 1); }
function legalisir_sla_breach() { return setting_int('legalisir_sla_breach_days', 7, 1); }

/**
 * Umur pengajuan dalam hari penuh, atau null bila tanggalnya tidak terbaca.
 */
function legalisir_age_days($created_at)
{
    if (!$created_at) {
        return null;
    }
    $t = strtotime((string)$created_at);
    return $t ? (int)floor((time() - $t) / 86400) : null;
}

/**
 * Kelas badge untuk umur pengajuan.
 *
 * Hanya berlaku pada status yang masih terbuka: pengajuan 'completed'
 * berumur 40 hari bukan pelanggaran SLA, ia sudah selesai.
 */
function legalisir_age_badge($status, $umur)
{
    if ($umur === null || !in_array($status, ['pending', 'processing'], true)) {
        return 'bg-slate-100 text-slate-500';
    }
    if ($umur >= legalisir_sla_breach()) { return 'bg-red-100 text-red-700'; }
    if ($umur >= legalisir_sla_warn())   { return 'bg-amber-100 text-amber-700'; }
    return 'bg-emerald-100 text-emerald-700';
}

/** "3 hari", "hari ini", "—" */
function legalisir_age_text($umur)
{
    if ($umur === null) { return '—'; }
    if ($umur === 0)    { return 'hari ini'; }
    return $umur . ' hari';
}

/**
 * Lama proses untuk pengajuan yang sudah ditutup.
 *
 * Mengembalikan null bila updated_at masih NULL. Baris yang sudah ada
 * sebelum kolom ini ditambahkan memang tidak punya nilainya, dan
 * mengisinya dari created_at akan menyatakan lama proses NOL untuk setiap
 * pengajuan lama — itu bukan metrik, itu keterangan palsu.
 */
function legalisir_turnaround_days($created_at, $updated_at)
{
    if (!$created_at || !$updated_at) {
        return null;
    }
    $a = strtotime((string)$created_at);
    $b = strtotime((string)$updated_at);
    if (!$a || !$b || $b < $a) {
        return null;
    }
    return (int)floor(($b - $a) / 86400);
}

/**
 * Terapkan satu perubahan status, lengkap dengan seluruh efek sampingnya.
 *
 * E-MAIL TIDAK DIKIRIM DI SINI. Fungsi ini mengembalikan daftar e-mail yang
 * perlu dikirim supaya pemanggil dapat melakukannya SETELAH transaksi
 * di-commit; mengirim e-mail di dalam transaksi berarti alumni bisa menerima
 * kabar tentang perubahan yang kemudian dibatalkan.
 *
 * @param  string      $reason  Wajib bila status 'rejected'.
 * @return array{ok:bool, error:string, email:array|null}
 */
function apply_legalisir_status($pdo, $id, $status, $reason = '')
{
    if (!in_array($status, legalisir_valid_statuses(), true)) {
        return ['ok' => false, 'error' => 'Status tidak dikenali.', 'email' => null];
    }

    $reason = trim((string)$reason);
    if ($status === 'rejected' && mb_strlen($reason) < 10) {
        // Penegakan di sisi server. Pemeriksaan di peramban bukan penegakan:
        // kolom rejection_reason sudah ada sejak lama tetapi tidak pernah
        // terisi, sehingga alumni yang ditolak tidak pernah diberi alasan.
        return ['ok' => false, 'error' => 'Alasan penolakan wajib diisi minimal 10 karakter.', 'email' => null];
    }

    $q = $pdo->prepare("SELECT user_id, documents, status, verification_token FROM legalisir_requests WHERE id = ?");
    $q->execute([$id]);
    $req = $q->fetch();
    if (!$req) {
        return ['ok' => false, 'error' => 'Pengajuan tidak ditemukan.', 'email' => null];
    }

    $docs     = json_decode((string)$req->documents, true) ?: [];
    $doc_type = !empty($docs[0]['type']) ? $docs[0]['type'] : 'Dokumen';
    $doc_desc = $doc_type . (count($docs) > 1 ? ' (' . count($docs) . ' berkas)' : '');

    // Alasan penolakan dibersihkan begitu status pindah dari 'rejected',
    // agar alasan lama tidak muncul kembali pada pengajuan yang sudah
    // diproses ulang.
    $alasan_simpan = ($status === 'rejected') ? $reason : null;

    $token = $req->verification_token;
    if ($status === 'completed' && empty($token)) {
        // Token diterbitkan SEKALI. Menerbitkan ulang akan mematikan tautan
        // verifikasi yang sudah diterima alumni sebelumnya.
        $token = bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE legalisir_requests
                       SET status = ?, rejection_reason = ?, verification_token = ?, verified_at = NOW()
                       WHERE id = ?")->execute([$status, $alasan_simpan, $token, $id]);
    } else {
        $pdo->prepare("UPDATE legalisir_requests SET status = ?, rejection_reason = ? WHERE id = ?")
            ->execute([$status, $alasan_simpan, $id]);
    }

    $surat = null;
    if ($req->user_id) {
        $label = legalisir_status_label($status);
        $tipe  = $status === 'completed' ? 'success' : ($status === 'rejected' ? 'error' : 'info');

        $pesan = 'Pengajuan legalisir ' . $doc_desc . ' Anda (' . $id . ') sekarang berstatus: ' . $label . '.';
        if ($status === 'rejected' && $reason !== '') {
            // Alasan ikut di notifikasi supaya alumni tidak perlu membuka
            // halaman detail hanya untuk tahu apa yang harus diperbaiki.
            $pesan .= ' Alasan: ' . mb_substr($reason, 0, 120) . (mb_strlen($reason) > 120 ? '…' : '');
        }

        add_notification($req->user_id, 'Status Legalisir Diperbarui', $pesan, $tipe,
                         'index.php?page=legalisir_detail&id=' . $id);

        $a = $pdo->prepare("SELECT email, name FROM users WHERE id = ?");
        $a->execute([$req->user_id]);
        $alumni = $a->fetch();
        if ($alumni && !empty($alumni->email)) {
            $surat = [
                'email'     => $alumni->email,
                'name'      => $alumni->name,
                'id'        => $id,
                'status'    => $status,
                'doc_desc'  => $doc_desc,
                'reason'    => $status === 'rejected' ? $reason : '',
                'softcopy'  => $status === 'completed' ? (BASE_URL . '/verify.php?token=' . $token) : '',
            ];
        }
    }

    return ['ok' => true, 'error' => '', 'email' => $surat, 'status_lama' => $req->status];
}

/**
 * Kirim e-mail yang dikumpulkan apply_legalisir_status().
 * Dipanggil SETELAH commit — lihat alasannya di atas.
 */
function send_legalisir_emails(array $daftar)
{
    foreach ($daftar as $s) {
        if (!$s) { continue; }
        send_legalisir_status_email($s['email'], $s['name'], $s['id'], $s['status'], $s['doc_desc'], $s['reason']);
        if ($s['softcopy'] !== '') {
            send_legalisir_softcopy_email($s['email'], $s['name'], $s['id'], $s['softcopy']);
        }
    }
}
