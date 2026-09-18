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
    $sql = "SELECT subject_id, gateway, fee_breakdown
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
        if ($r->gateway === 'cash') {
            $hasil[$id] = ['fee' => 0.0, 'pasti' => true];
            continue;
        }
        $rincian = $r->fee_breakdown ? json_decode($r->fee_breakdown, true) : null;
        $hasil[$id] = isset($rincian['fee'])
            ? ['fee' => (float)$rincian['fee'], 'pasti' => true]
            : ['fee' => 0.0, 'pasti' => false];
    }
    return $hasil;
}

/**
 * Ringkasan uang untuk kartu Laporan Keuangan.
 *
 * bruto  uang yang dibayar alumni
 * biaya  potongan penyedia pembayaran
 * neto   yang benar-benar diterima fakultas
 * tanpa_rincian  jumlah permohonan lunas yang biayanya tidak dapat dipastikan
 *
 * @return array{bruto:float, biaya:float, neto:float, tanpa_rincian:int, metode:array}
 */
function payment_revenue_summary(PDO $pdo)
{
    $metode = payment_revenue_by_method($pdo);
    $biaya = 0.0;
    $tanpa = 0;

    $lunas = $pdo->query("SELECT id FROM legalisir_requests WHERE payment_status = 'settlement'")
        ->fetchAll(PDO::FETCH_COLUMN);
    $peta = payment_fee_by_subject($pdo, 'legalisir', $lunas);
    foreach ($lunas as $id) {
        $b = $peta[(string)$id] ?? ['fee' => 0.0, 'pasti' => false];
        $biaya += $b['fee'];
        if (!$b['pasti']) {
            $tanpa++;
        }
    }

    return [
        'bruto'         => $metode['total'],
        'biaya'         => $biaya,
        'neto'          => $metode['total'] - $biaya,
        'tanpa_rincian' => $tanpa,
        'metode'        => $metode,
    ];
}

/** Label metode untuk laporan, tanpa memuat adaptor gateway. */
function payment_method_report_label($m)
{
    return ['midtrans' => 'Midtrans', 'flip' => 'Flip', 'cash' => 'Tunai'][$m] ?? ($m ? strtoupper((string)$m) : '-');
}
