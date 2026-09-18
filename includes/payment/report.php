<?php
/**
 * Agregat pendapatan legalisir: per metode pembayaran, dan biaya layanan
 * yang benar-benar dipotong penyedia.
 *
 * ── Mengapa satu fungsi ────────────────────────────────────────────────
 * Tiga layar menghitung "pendapatan Midtrans" dengan dua definisi berbeda:
 *
 *   pages/admin_dashboard.php, pages/admin_keuangan.php   payment_method = 'midtrans'
 *   pages/dashboard.php                                    payment_method != 'cash'
 *
 * Webhook lama pun menulis enum kanal ('bank_transfer', 'qris') ke
 * payment_method, sehingga pembayaran online lunas tidak masuk kartu
 * "Via Midtrans" di dua layar pertama tetapi masuk di layar ketiga.
 *
 * Sekarang payment_method hanya berisi kode gateway, dan ketiga layar
 * membaca angka dari sini. Metode yang tidak dikenal dikumpulkan ke
 * 'lainnya' supaya jumlah kartu selalu sama dengan totalnya.
 *
 * ── Mengapa tidak ada potongan tetap ───────────────────────────────────
 * Laporan dulu mengurangi `admin_fee` — satu angka tetap di tabel settings
 * (produksi: 9.997) — dari SETIAP baris. Angka itu tebakan: biaya yang
 * sesungguhnya berbeda per transaksi, karena dihitung dari profil biaya
 * gateway yang dipakai, dan sudah tersimpan apa adanya di
 * payment_transactions.fee_breakdown sejak tagihan terbit.
 *
 * Akibat tebakan itu ada dua arah, dan keduanya buruk: bila terlalu besar
 * laporan mengecilkan uang yang benar-benar diterima fakultas, bila terlalu
 * kecil fakultas mengira untung padahal menanggung selisihnya sendiri.
 * Karena itu `admin_fee` tidak lagi dibaca di mana pun.
 *
 * Sengaja tidak memuat includes/payment/service.php: laporan hanya butuh SQL.
 */

/**
 * Pendapatan KOTOR (yang dibayar alumni) per metode pembayaran.
 *
 * @return array{midtrans:float, flip:float, cash:float, lainnya:float, total:float}
 */
function payment_revenue_by_method(PDO $pdo)
{
    $hasil = ['midtrans' => 0.0, 'flip' => 0.0, 'cash' => 0.0, 'lainnya' => 0.0, 'total' => 0.0];
    $q = $pdo->query("SELECT payment_method m, COALESCE(SUM(amount), 0) jml
                        FROM legalisir_requests
                       WHERE payment_status = 'settlement'
                       GROUP BY payment_method");
    foreach ($q->fetchAll(PDO::FETCH_OBJ) as $r) {
        $kunci = in_array($r->m, ['midtrans', 'flip', 'cash'], true) ? $r->m : 'lainnya';
        $hasil[$kunci] += (float)$r->jml;
        $hasil['total'] += (float)$r->jml;
    }
    return $hasil;
}

/**
 * Biaya layanan nyata per permohonan, dibaca dari ledger.
 *
 * Yang dipotong penyedia adalah komponen `fee` pada rincian yang tersimpan
 * saat tagihan terbit — bukan `admin_total`, karena `admin_total` sudah
 * memuat biaya tambahan layanan fakultas, dan uang itu diterima fakultas.
 *
 * Pembayaran tunai tidak dipotong siapa pun: seluruh uangnya sampai ke
 * loket, jadi biayanya nol dan itu pasti.
 *
 * Transaksi lama dari sebelum ledger ini ada tidak punya rincian. Biayanya
 * dilaporkan nol dan ditandai `pasti = false`, supaya layar dapat berkata
 * "tanpa rincian" alih-alih menyajikan tebakan sebagai fakta.
 *
 * @param string[]|null $subject_ids batasi ke permohonan tertentu
 * @return array<string, array{fee: float, pasti: bool}>
 */
function payment_fee_by_subject(PDO $pdo, $purpose = 'legalisir', array $subject_ids = null)
{
    if ($subject_ids !== null && !$subject_ids) {
        return [];
    }
    $sql = "SELECT subject_id, gateway, fee_breakdown, amount_expected
              FROM payment_transactions
             WHERE purpose = ? AND status = 'paid'";
    $params = [$purpose];
    if ($subject_ids !== null) {
        $sql .= ' AND subject_id IN (' . implode(',', array_fill(0, count($subject_ids), '?')) . ')';
        $params = array_merge($params, array_map('strval', $subject_ids));
    }
    // Bila satu permohonan punya lebih dari satu transaksi lunas (bayar
    // ganda), yang dipakai adalah yang PERTAMA. Transaksi kedua ditangani
    // sebagai refund manual, bukan sebagai pendapatan.
    $sql .= ' ORDER BY id';

    $q = $pdo->prepare($sql);
    $q->execute($params);

    $hasil = [];
    foreach ($q->fetchAll(PDO::FETCH_OBJ) as $r) {
        $id = (string)$r->subject_id;
        if (isset($hasil[$id])) {
            continue;
        }
        $umum = ['gateway' => $r->gateway, 'bruto' => (float)$r->amount_expected];
        if ($r->gateway === 'cash') {
            $hasil[$id] = $umum + ['fee' => 0.0, 'pasti' => true];
            continue;
        }
        $rincian = $r->fee_breakdown ? json_decode($r->fee_breakdown, true) : null;
        $hasil[$id] = $umum + (isset($rincian['fee'])
            ? ['fee' => (float)$rincian['fee'], 'pasti' => true]
            : ['fee' => 0.0, 'pasti' => false]);
    }
    return $hasil;
}

/** Jenis layanan yang dapat dilaporkan. */
function payment_report_kinds()
{
    return ['semua', 'legalisir', 'donasi'];
}

/**
 * Baris Laporan Keuangan, legalisir dan donasi dalam satu bentuk.
 *
 * Donasi selama ini tidak pernah masuk laporan: rekapnya berdiri sendiri di
 * Kelola Donasi, dan tidak ada satu angka pun yang menyatukan keduanya.
 * Padahal uangnya masuk lewat jalur yang sama persis dan dipotong penyedia
 * yang sama.
 *
 * Nominal donasi diambil dari ledger (amount_expected), bukan dari
 * donations.amount: kolom itu menyimpan donasi POKOK, sedangkan yang
 * benar-benar dibayar donatur termasuk biaya layanan.
 *
 * @param array $f jenis, start_date, end_date, status, method, month
 * @return array daftar baris, terbaru lebih dulu
 */
function payment_finance_rows(PDO $pdo, array $f = [])
{
    $jenis = in_array($f['jenis'] ?? 'semua', payment_report_kinds(), true) ? $f['jenis'] : 'semua';
    $baris = [];

    if ($jenis !== 'donasi') {
        $q = $pdo->query("SELECT lr.id, lr.created_at, lr.amount, lr.payment_status, lr.payment_method,
                                 lr.status, lr.documents, u.name, u.email, u.nim
                            FROM legalisir_requests lr
                            JOIN users u ON lr.user_id = u.id");
        $rows = $q->fetchAll(PDO::FETCH_OBJ);
        $peta = payment_fee_by_subject($pdo, 'legalisir', array_map(fn($r) => $r->id, $rows));
        foreach ($rows as $r) {
            $docs = json_decode((string)$r->documents);
            $nama_dok = array_map(fn($d) => ucfirst(is_object($d) ? ($d->type ?? '-') : (string)$d), (array)$docs);
            $baris[] = payment_finance_row([
                'jenis'       => 'legalisir',
                'id'          => $r->id,
                'created_at'  => $r->created_at,
                'nama'        => $r->name,
                'identitas'   => $r->nim ?: $r->email,
                'keterangan'  => implode(', ', $nama_dok),
                'status_doc'  => $r->status,
                'metode'      => $r->payment_method,
                'bayar'       => $r->payment_status === 'settlement' ? 'settlement'
                                 : ($r->payment_status === 'pending' ? 'pending' : 'failed'),
                'tagihan'     => (float)$r->amount,
            ], $peta[(string)$r->id] ?? null);
        }
    }

    if ($jenis !== 'legalisir') {
        $q = $pdo->query("SELECT d.id, d.created_at, d.amount, d.status, d.donor_name, d.user_id,
                                 c.title AS kampanye, u.email, u.nim
                            FROM donations d
                            LEFT JOIN donation_campaigns c ON c.id = d.campaign_id
                            LEFT JOIN users u ON u.id = d.user_id");
        $rows = $q->fetchAll(PDO::FETCH_OBJ);
        $peta = payment_fee_by_subject($pdo, 'donasi', array_map(fn($r) => (string)$r->id, $rows));
        foreach ($rows as $r) {
            $l = $peta[(string)$r->id] ?? null;
            $baris[] = payment_finance_row([
                'jenis'       => 'donasi',
                'id'          => (string)$r->id,
                'created_at'  => $r->created_at,
                'nama'        => $r->donor_name ?: 'Hamba Allah',
                'identitas'   => $r->nim ?: ($r->email ?: '-'),
                'keterangan'  => $r->kampanye ?: 'Donasi',
                'status_doc'  => null,
                // Donasi tidak menyimpan metodenya sendiri; yang tahu adalah
                // ledger. Tanpa transaksi lunas, metodenya memang belum ada.
                'metode'      => $l['gateway'] ?? null,
                'bayar'       => in_array($r->status, ['success', 'completed'], true) ? 'settlement'
                                 : ($r->status === 'pending' ? 'pending' : 'failed'),
                'tagihan'     => (float)($l['bruto'] ?? $r->amount),
            ], $l);
        }
    }

    // Penyaringan dilakukan setelah kedua sumber disatukan, supaya aturannya
    // tertulis sekali dan tidak mungkin berbeda antara legalisir dan donasi.
    $baris = array_values(array_filter($baris, function ($b) use ($f) {
        $tgl = substr((string)$b['created_at'], 0, 10);
        if (!empty($f['start_date']) && $tgl < $f['start_date']) {
            return false;
        }
        if (!empty($f['end_date']) && $tgl > $f['end_date']) {
            return false;
        }
        if (!empty($f['month']) && substr((string)$b['created_at'], 0, 7) !== $f['month']) {
            return false;
        }
        if (!empty($f['status']) && $b['bayar'] !== $f['status']) {
            return false;
        }
        if (!empty($f['method']) && $b['metode'] !== $f['method']) {
            return false;
        }
        return true;
    }));

    usort($baris, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
    return $baris;
}

/** Satu baris laporan, lengkap dengan uangnya. */
function payment_finance_row(array $b, $ledger)
{
    $lunas = $b['bayar'] === 'settlement';
    $b['lunas'] = $lunas;
    $b['biaya'] = $lunas && $ledger ? (float)$ledger['fee'] : 0.0;
    $b['pasti'] = !$lunas || ($ledger && $ledger['pasti']);
    $b['diterima'] = max(0.0, $b['tagihan'] - $b['biaya']);
    return $b;
}

/**
 * Ringkasan uang untuk kartu Laporan Keuangan, dihitung DARI BARIS yang
 * sedang ditampilkan — sehingga jumlah kartu selalu sama dengan tabelnya.
 *
 * bruto  uang yang dibayar pembayar (lunas saja)
 * biaya  potongan penyedia pembayaran
 * neto   yang benar-benar diterima fakultas
 * belum_lunas    tagihan yang masih menunggu pembayaran
 * tanpa_rincian  baris lunas yang biayanya tidak dapat dipastikan
 */
function payment_finance_summary(array $baris)
{
    $r = ['bruto' => 0.0, 'biaya' => 0.0, 'neto' => 0.0, 'belum_lunas' => 0.0, 'tanpa_rincian' => 0,
          'metode' => ['midtrans' => 0.0, 'flip' => 0.0, 'cash' => 0.0, 'lainnya' => 0.0, 'total' => 0.0]];
    foreach ($baris as $b) {
        if ($b['bayar'] === 'pending') {
            $r['belum_lunas'] += $b['tagihan'];
        }
        if (!$b['lunas']) {
            continue;
        }
        $r['bruto'] += $b['tagihan'];
        $r['biaya'] += $b['biaya'];
        if (!$b['pasti']) {
            $r['tanpa_rincian']++;
        }
        $kunci = in_array($b['metode'], ['midtrans', 'flip', 'cash'], true) ? $b['metode'] : 'lainnya';
        $r['metode'][$kunci] += $b['tagihan'];
        $r['metode']['total'] += $b['tagihan'];
    }
    $r['neto'] = $r['bruto'] - $r['biaya'];
    return $r;
}

/**
 * Ringkasan seluruh data satu jenis layanan, tanpa penyaring.
 *
 * @return array{bruto:float, biaya:float, neto:float, tanpa_rincian:int, metode:array}
 */
function payment_revenue_summary(PDO $pdo, $jenis = 'legalisir')
{
    return payment_finance_summary(payment_finance_rows($pdo, ['jenis' => $jenis]));
}

/** Label metode untuk laporan, tanpa memuat adaptor gateway. */
function payment_method_report_label($m)
{
    return ['midtrans' => 'Midtrans', 'flip' => 'Flip', 'cash' => 'Tunai'][$m] ?? ($m ? strtoupper((string)$m) : '-');
}
