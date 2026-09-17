<?php
/**
 * Agregat pendapatan legalisir per metode pembayaran.
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
 * Sengaja tidak memuat includes/payment/service.php: laporan hanya butuh SQL.
 */

/**
 * @param int $potongan_per_transaksi admin_fee yang dikurangkan per baris
 *                                    (Laporan Keuangan menampilkan nilai
 *                                    bersih; dasbor menampilkan kotor)
 * @return array{midtrans:float, flip:float, cash:float, lainnya:float, total:float}
 */
function payment_revenue_by_method(PDO $pdo, $potongan_per_transaksi = 0)
{
    $hasil = ['midtrans' => 0.0, 'flip' => 0.0, 'cash' => 0.0, 'lainnya' => 0.0, 'total' => 0.0];
    $q = $pdo->prepare("SELECT payment_method m, COALESCE(SUM(GREATEST(0, amount - ?)), 0) jml
                          FROM legalisir_requests
                         WHERE payment_status = 'settlement'
                         GROUP BY payment_method");
    $q->execute([(int)$potongan_per_transaksi]);
    foreach ($q->fetchAll(PDO::FETCH_OBJ) as $r) {
        $kunci = in_array($r->m, ['midtrans', 'flip', 'cash'], true) ? $r->m : 'lainnya';
        $hasil[$kunci] += (float)$r->jml;
        $hasil['total'] += (float)$r->jml;
    }
    return $hasil;
}

/** Label metode untuk laporan, tanpa memuat adaptor gateway. */
function payment_method_report_label($m)
{
    return ['midtrans' => 'Midtrans', 'flip' => 'Flip', 'cash' => 'Tunai'][$m] ?? ($m ? strtoupper((string)$m) : '-');
}
