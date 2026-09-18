# Pembayaran AlumniLink

Dokumen ini untuk **super admin** yang mengoperasikan pembayaran dan
**pengembang** yang merawat kodenya. Isinya: cara kerja, cara memindahkan
gateway saat salah satunya bermasalah, dan cara menangani kasus yang tidak
biasa.

Ringkasnya: ada dua gateway — **Flip** (pilihan utama) dan **Midtrans**
(cadangan) — dan satu jalur manual, **tunai**. Satu gateway dipakai pada
satu waktu untuk **tagihan baru**. Tagihan yang sudah terbit tetap dibayar
dan dikonfirmasi lewat gateway asalnya, jadi berpindah gateway tidak pernah
membatalkan tagihan siapa pun.

## 0. Urutan pemilihan gateway

Ada dua hal berbeda, dan membedakannya penting:

| Istilah | Artinya |
|---|---|
| **Pilihan utama** | Yang dikehendaki fakultas. Setting `payment_gateway_active`, bawaannya `flip`. |
| **Dipakai sekarang** | Yang benar-benar menerbitkan tagihan. Dihitung dari kesiapan. |

Urutannya setiap kali tagihan dibuat:

1. Pilihan utama, **bila siap**.
2. Bila belum siap → gateway lain yang siap.
3. Bila penerbitan tagihan **gagal saat itu juga** (API mati, kunci
   ditolak) → dicoba sekali lagi lewat gateway lain yang siap, dan
   transaksinya berpindah ke sana. Nominalnya tidak dihitung ulang.
4. Bila keduanya gagal → pengajuan tetap tersimpan sebagai `create_failed`;
   alumni dapat meminta tagihan baru dari halaman detail.
5. **Tunai** selalu tersedia sebagai jalan terakhir, lewat verifikasi manual
   di Kelola Legalisir.

**Siap** berarti tiga hal sekaligus: kredensial lengkap, profil biaya sah,
dan tarifnya sudah ditandai "sudah dicocokkan dengan tarif resmi". Tarif
yang belum ditandai membuat gateway dianggap belum siap dengan sengaja:
profil bawaan Flip nol, dan menagih dengan biaya nol berarti fakultas
menanggung sendiri potongan gateway tanpa ada yang menyadarinya.

Akibat praktisnya: **pilihan utama boleh disetel ke Flip sekarang juga**,
meski kredensialnya belum ada. Selama Flip belum siap, tagihan terbit lewat
Midtrans. Begitu kredensial dan tarif Flip diisi, tagihan baru berpindah ke
Flip sendiri — tidak ada tombol yang perlu ditekan dan tidak ada kode yang
perlu diubah.

Perpindahan otomatis karena kegagalan (nomor 3) dicatat di Audit Trail
sebagai `PAYMENT_GATEWAY_FALLBACK` dan diberitahukan ke super admin,
dibatasi satu notifikasi per 30 menit supaya tidak membanjiri. Fiturnya
dapat dimatikan lewat sakelar di panel.

Panel: **Pengaturan Sistem → Gateway Pembayaran**
(`index.php?page=admin_payment_gateway`, khusus super admin).

---

## 1. Peta berkas

```
includes/payment/
  http.php      satu-satunya jalan keluar HTTP; timeout 10/20 detik
  gateway.php   kontrak adaptor + registri + label kanal
  fee.php       SATU rumus biaya + zona ongkir
  midtrans.php  adaptor Midtrans (Snap + API status)
  flip.php      adaptor Flip (Accept Payment / Bill v2)
  service.php   buat, terbitkan ulang, ubah status, verifikasi tunai
  callback.php  penerima callback bersama untuk kedua gateway
  panel.php     aturan "boleh dipindah atau tidak", tes koneksi, transaksi uji
  report.php    agregat pendapatan per metode untuk laporan
  backfill.php  membuat transaksi ledger dari data lama

api/payment_quote.php            pratinjau biaya (dipakai halaman alumni)
handlers/midtrans_webhook.php    callback Midtrans
handlers/flip_callback.php       callback Flip
handlers/payment_return.php      halaman kembali setelah membayar
cron/payment_reconcile.php       rekonsiliasi terjadwal
pages/admin_payment_gateway.php  panel super admin
```

Tabelnya dua: `payment_transactions` (ledger) dan `payment_callbacks`
(jurnal, hanya ditambah). Kolom lama (`legalisir_requests.payment_status`,
`payment_method`, `midtrans_order_id`, `midtrans_snap_token`,
`donations.status`) tetap ada dan tetap disinkronkan, sehingga seluruh
laporan dan halaman lama tetap benar.

**Ledger sengaja tanpa foreign key.** Menghapus alumni, pengajuan, atau
kampanye tidak ikut menghapus jejak uang.

---

## 2. Rumus biaya

Satu fungsi, `payment_quote()`. Pratinjau yang dilihat alumni dan tagihan
yang terbit memanggil fungsi yang sama — keduanya tidak mungkin berbeda.

```
pokok  = jumlah dokumen × harga per dokumen + ongkir      (legalisir)
       | nominal donasi                                    (donasi)
tambahan = payment_custom_charge_{legalisir,donasi}
m      = persen/100 × (1 + PPN/100)
biaya  = ceil( ((pokok + tambahan) × m + tetap + aplikasi) ÷ (1 − m) )
biaya  = max(biaya, minimum)
total  = pokok + biaya + tambahan
```

Pembagian dengan `(1 − m)` adalah *gross-up*: fakultas menerima utuh, biaya
gateway ditanggung pembayar. Persen **selalu ditulis sebagai persen** (5
berarti 5%), dan pembagian 100 hanya terjadi di satu tempat.

Profil biaya disimpan **per gateway** (`fee_midtrans_*`, `fee_flip_*`), dan
**rincian tagihan disimpan per transaksi**. Mengubah tarif tidak pernah
mengubah tagihan yang sudah terbit.

Panel menampilkan contoh perhitungan langsung setiap kali tarif disunting.
Periksa angkanya di sana sebelum menyimpan.

---

## 3. Mesin status

Status transaksi: `pending`, `paid`, `failed`, `expired`, `cancelled`,
`refunded`, `create_failed`.

Tiga aturan yang tidak boleh dilanggar:

1. **Callback hanya pemicu, bukan kebenaran.** Setiap callback
   diautentikasi (tanda tangan sha512 untuk Midtrans, validation token untuk
   Flip), lalu statusnya **diambil ulang dari API gateway**, dan nominalnya
   dicocokkan. Isi callback tidak pernah dipercaya apa adanya — tanda tangan
   Midtrans tidak mengikat `transaction_status`, dan callback Flip sama
   sekali tidak bertanda tangan.
2. **Uang yang masuk selalu menang.** `expired` atau `failed` tetap berubah
   menjadi `paid` bila gateway menyatakan lunas (VA yang dibayar tepat saat
   kedaluwarsa itu nyata). Sebaliknya `paid` tidak pernah mundur.
3. **Satu fungsi mengubah status.** `payment_apply_status()` mengunci baris
   (`SELECT … FOR UPDATE`), memeriksa transisinya, lalu menjalankan efek
   samping (e-mail, notifikasi) **setelah commit** — tepat sekali.

Balasan callback: `200` bila selesai, `403` bila autentikasi gagal, `503`
bila konfigurasi/basis data bermasalah (gateway akan mengulang), `400` bila
isinya tidak sah.

---

## 4. Yang harus didaftarkan di dashboard gateway

| Gateway | Tempat | URL |
|---|---|---|
| Midtrans | Settings → Configuration → Payment Notification URL | `https://<domain>/alumnilink/handlers/midtrans_webhook.php` |
| Flip | Pengaturan → Callback (Accept Payment) | `https://<domain>/alumnilink/handlers/flip_callback.php` |

URL lengkapnya beserta tombol salin ada di panel. Catatan:

- Midtrans memisahkan pengaturan **sandbox** dan **produksi**. Mendaftarkan
  di satu mode tidak berlaku untuk mode yang lain.
- `handlers/midtrans_notification.php` adalah alias lama yang tetap
  berfungsi; tidak perlu diubah bila sudah terlanjur terdaftar.
- Flip mengambil URL callback dari dashboard, bukan dari setiap permintaan.
- Validation token Flip diambil dari halaman yang sama dengan tempat URL
  callback didaftarkan.

---

## 5. Runbook: memindahkan gateway

Dipakai ketika gateway yang aktif bermasalah (API tidak membalas, kanal
pembayaran mati, akun ditangguhkan).

1. Buka **Gateway Pembayaran**.
2. Pada kartu gateway tujuan, pastikan **mode** benar (produksi untuk uang
   sungguhan) dan kredensialnya terisi.
3. Tekan **Tes koneksi**. Harus berhasil.
4. Periksa **profil biaya**-nya, cocokkan dengan tarif resmi yang berlaku
   untuk akun fakultas, lalu centang pernyataan "sudah dicocokkan".
5. Tekan **Jadikan gateway aktif**, baca konfirmasinya, lanjutkan.

Sistem menolak perpindahan bila salah satu syarat berikut tidak terpenuhi:

- kredensial belum lengkap;
- belum ada tes koneksi yang berhasil **dalam 24 jam terakhir**;
- tes terakhir dilakukan pada mode yang berbeda dengan mode sekarang;
- profil biaya tidak sah atau belum ditandai sudah dicocokkan.

Penolakan itu disengaja: begitu sakelar berpindah, **setiap** tagihan baru
terbit lewat gateway itu. Kunci sandbox yang lulus tes tidak membuktikan
apa pun tentang kunci produksi.

Setelah berpindah:

- Tagihan lama tetap diproses gateway asalnya — biarkan.
- Perpindahan tercatat di Audit Trail dan diberitahukan ke super admin.
- Untuk kembali, jalankan langkah yang sama pada gateway asal (termasuk tes
  koneksi ulang).

**Mengubah kredensial atau mode akan membatalkan tes koneksi terakhir.**
Jalankan tes lagi sesudahnya.

---

## 6. Sebelum memakai gateway pertama kali

1. Isi kredensial **sandbox** di panel, tes koneksi.
2. Terbitkan **transaksi uji Rp 10.000** (tombolnya hanya muncul di mode
   sandbox), bayar di simulator gateway.
3. Di **monitor**, pastikan muncul callback dengan hasil `applied` dan
   transaksinya menjadi `paid`. Bila callback tidak pernah muncul, URL
   callback belum terdaftar atau diblokir di sisi jaringan.
4. Barulah isi kredensial produksi, tes koneksi ulang, dan pindahkan
   sakelar.

Transaksi uji memakai `purpose = 'uji'`: tidak menyentuh legalisir maupun
donasi, dan tidak masuk Laporan Keuangan.

---

## 7. Bila callback tidak masuk

Callback bisa terlambat, tertahan Cloudflare, atau hilang. Sistem tidak
bergantung padanya:

- **`cron/payment_reconcile.php`** (setiap 30 menit) menarik status
  langsung dari gateway untuk tagihan pending yang berumur lebih dari 20
  menit atau sudah lewat masa berlaku. URL cron ada di Pengaturan Sistem.
- **Halaman kembali** (`payment_return.php`) juga mengambil status ulang
  begitu alumni kembali dari halaman pembayaran.
- **Tombol "Cek status"** di monitor melakukannya secara manual.

Bila alumni mengaku sudah membayar tetapi statusnya masih menunggu: tekan
"Cek status" pada barisnya. Bila gateway pun mengatakan belum lunas,
pembayarannya memang belum sampai ke gateway.

---

## 8. Kasus tidak biasa

| Kejadian | Yang dilakukan sistem | Yang perlu Anda lakukan |
|---|---|---|
| **Bayar ganda** (satu pengajuan lunas dua kali) | Transaksi kedua ditandai `double_payment`, disorot merah di monitor, super admin diberi tahu. E-mail "lunas" tidak dikirim dua kali. | Refund manual lewat dashboard gateway. |
| **Nominal tidak cocok** | Status TIDAK diubah menjadi lunas; transaksi ditandai `amount_mismatch` dan super admin diberi tahu sekali. Retry berikutnya tetap diproses. | Periksa di dashboard gateway. Bila nominalnya memang benar, cocokkan manual. |
| **Tagihan kedaluwarsa** | Setelah 30 menit lewat masa berlaku dan gateway tidak pernah melihat pembayaran, statusnya menjadi `expired`. | Alumni dapat menekan "Buat Tagihan Pembayaran" sendiri di halaman detail. |
| **Gateway menolak saat tagihan dibuat** | Dicoba sekali lagi lewat gateway lain yang siap. Bila itu pun gagal, pengajuan tetap tersimpan sebagai `create_failed` dan alumni dapat meminta tagihan ulang. Tidak ada transaksi yatim. | Periksa tes koneksi dan kredensial; perpindahan otomatis ada di Audit Trail. |
| **Pembayaran tunai** | Verifikasi tunai menutup tagihan online yang masih terbuka, lalu mencatat transaksi `cash`. Ditolak bila sudah lunas online. | Gunakan tombol verifikasi tunai di Kelola Legalisir. |
| **Pengajuan lunas dihapus** | Ditolak. Uang yang sudah diterima tidak boleh hilang dari Laporan Keuangan. | Tolak pengajuannya bila memang dibatalkan. |

---

## 9. Pengaturan

Seluruhnya diatur dari panel; tidak ada yang perlu diubah di berkas.

| Kunci | Arti |
|---|---|
| `payment_gateway_active` | Gateway PILIHAN UTAMA untuk tagihan baru (`flip` / `midtrans`) |
| `payment_fallback_enabled` | `1` = coba gateway lain bila yang dipakai gagal menerbitkan tagihan |
| `midtrans_server_key`, `midtrans_client_key`, `midtrans_is_production` | Kredensial dan mode Midtrans |
| `flip_secret_key`, `flip_validation_token`, `flip_is_production` | Kredensial dan mode Flip |
| `fee_{midtrans,flip}_{percent,vat_percent,flat,app,min}` | Profil biaya per gateway |
| `fee_{midtrans,flip}_reviewed` | Tanda "tarif sudah dicocokkan"; syarat mengaktifkan |
| `payment_custom_charge_{legalisir,donasi}` | Biaya tambahan layanan |
| `payment_expiry` | Masa berlaku tagihan (menit) |
| `payment_last_test_{midtrans,flip}` | Hasil tes koneksi terakhir |
| `legalisir_require_paid` | `0` = peringatan saja, `1` = pengajuan belum lunas tidak dapat diproses |

Kunci pembayaran **ditolak** oleh handler Pengaturan Sistem, walau di-POST
langsung. Satu-satunya jalur yang menulisnya adalah panel ini.

Kunci lama (`midtrans_mdr_rate`, `midtrans_ppn_rate`, `midtrans_payout_fee`,
`midtrans_margin_admin`, `custom_tax_value`) sudah tidak dibaca. Nilainya
dipakai sekali saat migrasi untuk menyemai profil baru, lalu dibiarkan.

---

## 10. Yang masih perlu dipastikan tentang Flip

Ditulis apa adanya supaya tidak ada yang mengira sudah selesai:

- Adaptor memakai **Accept Payment (Bill) v2** — satu-satunya versi yang
  terdokumentasi lengkap dan dapat diverifikasi. **v3 belum diimplementasikan**,
  dan tidak ada pengaturan untuk memilihnya — versinya tertulis di kode
  (`includes/payment/flip.php`). Memakai v3 berarti menyunting adaptor itu,
  bukan mengubah setting.
- **Tarif resmi Flip belum dikonfirmasi** ke pihak Flip. Karena itu
  `fee_flip_*` disemai 0 dan `fee_flip_reviewed = 0`: Flip tidak dapat
  diaktifkan sebelum seseorang mengisi tarif yang benar dan menandainya.
- Nominal minimum Flip **Rp 10.000**.
- Kanal pembayaran yang aktif berbeda per akun. Bila sebuah kanal ditolak
  (galat 1091), kanal itu belum diaktifkan untuk akun tersebut.
- Callback Flip **tidak bertanda tangan** — hanya token statis. Karena itu
  status selalu diambil ulang lewat API.

---

## 11. Uji

```
php tests/run_all.php
```

Yang menyentuh pembayaran:

- `tests/suite/uji_pembayaran.php` — rumus, mesin status, adaptor, callback,
  backfill, penjaga sakelar, transaksi uji, cron. Tanpa jaringan: seluruh
  HTTP keluar diganti transport palsu.
- `tests/suite/uji_alur_bayar.php` — handler dan halaman lewat HTTP, dengan
  kredensial dikosongkan supaya tidak ada panggilan ke gateway sungguhan.

Keduanya mengembalikan setiap pengaturan dan menghapus data ujinya sendiri.
Tidak ada uji yang boleh memanggil API gateway sungguhan.
