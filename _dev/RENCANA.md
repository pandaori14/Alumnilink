# Rencana Pengembangan AlumniLink

Dokumen ini untuk **pemilik sistem** (super admin FK UMS) dan **pengembang
penerus**. Isinya: keadaan sistem hari ini, hasil audit apa yang tidak
terpakai, dan daftar pekerjaan berikutnya yang sudah diurutkan.

Ditulis 18 September 2026, setelah upgrade gateway pembayaran dan penataan
ulang Konfigurasi Sistem.

---

## 1. Keadaan hari ini

| | |
|---|---|
| Berkas PHP | 178 (±50.000 baris, termasuk uji) |
| Tabel basis data | 28 |
| Kunci pengaturan | 120 |
| Rangkaian uji | `php tests/run_all.php` — 26 langkah, 958 pemeriksaan |
| Versi skema | `2026.09.17.1` |

Sepuluh commit terakhir belum diunggah ke server produksi. Selama belum
diunggah, produksi masih memakai kode lama.

### Yang sudah dijamin uji

- **Pembayaran**: rumus biaya tunggal, mesin status maju, callback tidak
  dipercaya, cadangan gateway otomatis, rekonsiliasi terjadwal.
- **Konfigurasi Sistem**: setiap kolom benar-benar tersimpan; menyimpan
  tanpa mengubah apa pun tidak mengubah data; rahasia tidak pernah dirender;
  kunci pembayaran ditolak; tidak ada pengaturan yang tersembunyi dari
  antarmuka.
- **Tata letak**: 12 halaman × 3 lebar layar tanpa geser mendatar.
- **JavaScript**: seluruh skrip hasil render diurai mesin V8.

---

## 2. Hasil audit: yang tidak terpakai

Diaudit otomatis (lihat riwayat commit). Tidak ada yang dihapus dulu —
keputusan membuang kode ada di pemilik sistem.

### 2.1 Endpoint tanpa pemanggil

| Berkas | Keterangan | Saran |
|---|---|---|
| `handlers/admin_use_repository.php` | Fitur "pakai berkas dari Repositori Dokumen untuk memenuhi pengajuan legalisir". Handler-nya lengkap dan terjaga kapabilitas, tetapi **tidak ada tombol di halaman mana pun** yang memanggilnya. | Pasang tombolnya di Kelola Legalisir (Prioritas 2.3), atau hapus berkasnya. |
| `handlers/midtrans_notification.php` | Alias URL lama untuk webhook. | **Pertahankan.** Bila dashboard Midtrans terlanjur memakai nama ini, menghapusnya memutus konfirmasi pembayaran. |
| `handlers/payment_finish.php` | Tujuan kembali versi lama, kini meneruskan ke `payment_return.php`. | **Pertahankan** sampai dipastikan tidak ada tagihan lama yang memakainya. |

### 2.2 Fungsi yang tidak pernah dipanggil

| Fungsi | Berkas | Saran |
|---|---|---|
| `bentuk_notifikasi()` | `api/notifications.php` | Sisa penulisan ulang; aman dihapus. |
| `all_admin_roles()` | `includes/auth_guard.php` | Aman dihapus, atau dipakai menggantikan daftar peran yang masih ditulis manual di beberapa handler. |
| `tracer_needs_update()` | `includes/settings.php` | Logikanya kini ada di `index.php`. Aman dihapus. |
| `error_unauthenticated()` | `includes/error_page.php` | Bagian dari kumpulan halaman galat; **pertahankan** agar lengkap. |
| `setting_bool()`, `require_login()` | `includes/settings.php`, `auth_guard.php` | Pustaka bersama yang belum terpakai. Pertahankan. |
| `checkMatch()` | `pages/reset_password.php` (JavaScript) | Pemeriksa kecocokan kata sandi yang tidak pernah dipasang ke kolomnya — **kemungkinan cacat UI**, bukan kode mati. Periksa (Prioritas 3.1). |

### 2.3 Data lama yang tidak lagi dibaca

- Tabel `midtrans_notifications` — digantikan `payment_callbacks`.
  Dipertahankan sebagai sejarah; tidak menambah beban.
- Kunci `midtrans_mdr_rate`, `midtrans_ppn_rate`, `midtrans_payout_fee`,
  `midtrans_margin_admin`, `custom_tax_value` — hanya dibaca sekali saat
  migrasi untuk menyemai profil biaya baru. Aman dibiarkan.
- `legacy_unsubscribe_token` — sakelar kompatibilitas tautan berhenti
  berlangganan versi lama. Dapat dimatikan setelah satu siklus broadcast.

### 2.4 Yang TIDAK ditemukan (kabar baik)

- Tidak ada tabel yang tidak pernah diquery.
- Tidak ada handler tanpa penjagaan peran/CSRF.
- Tidak ada pengaturan yang dibaca kode tetapi tak terjangkau antarmuka
  (dijaga `tests/suite/uji_pengaturan.php` bagian G).

---

## 3. Rencana pengembangan

Diurutkan menurut akibat bila tidak dikerjakan, bukan menurut kesulitan.

### Sudah dikerjakan sejak rencana ini ditulis

**Sakelar aktif/nonaktif per gateway** · 18 September 2026
Super admin kini memilih metode pembayaran mana yang benar-benar ditawarkan
ke alumni. Gateway yang dimatikan dilewati sepenuhnya — bukan sebagai
pilihan utama, bukan pula sebagai cadangan otomatis — sementara kredensial
dan tarifnya tetap tersimpan. Mematikan gateway terakhir yang menyala, atau
gateway terakhir yang siap, meminta persetujuan eksplisit karena akibatnya
terasa langsung oleh alumni. Ketika semua gateway dimatikan, halaman
Legalisir mengarahkan ke pembayaran tunai di loket dan halaman Donasi
menutup tombolnya, alih-alih menerbitkan tagihan yang pasti gagal.
Ditambah pilihan kanal pembayaran untuk Midtrans (`enabled_payments`);
kanal Flip diatur di dashboard Flip dan panel menyatakannya apa adanya.
Dijaga 27 pemeriksaan baru di `uji_pembayaran` dan `uji_alur_bayar`.

### Prioritas 1 — Uang dan keamanan

**1.1 Donasi masuk Laporan Keuangan** · sedang
Laporan Keuangan hanya menghitung legalisir. Rekap donasi terpisah di
Kelola Donasi, dan tidak ada satu angka pun yang menyatukan keduanya.
*Selesai bila:* Laporan Keuangan punya penyaring jenis (legalisir/donasi/
semua), ekspornya ikut, dan jumlah kartunya tetap sama dengan total.

**1.2 Laporan keuangan memakai biaya nyata, bukan `admin_fee`** · sedang
Laporan Keuangan mengurangi potongan tetap `admin_fee` (produksi: 9.997)
dari setiap transaksi. Angka itu tebakan: biaya yang sesungguhnya sudah
tercatat per transaksi di `payment_transactions.fee_breakdown`, hasil
kombinasi profil biaya gateway. Akibatnya laporan tidak cocok dengan uang
yang benar-benar diterima fakultas.

Keputusan pemilik sistem (18 Sep 2026): `admin_fee` **tidak ditampilkan di
antarmuka mana pun**. Laporan audit harus bersih dari angka karangan, dan
fakultas tidak boleh tombok atas biaya di luar yang dibayar alumni.

*Selesai bila:* Laporan Keuangan dan ekspornya menampilkan bruto yang
dibayar alumni, biaya layanan, dan neto yang diterima fakultas — seluruhnya
dari `fee_breakdown`; baris lama tanpa rincian memakai cadangan yang jujur
dan ditandai sebagai perkiraan; `admin_fee` tidak lagi dibaca di
`pages/admin_keuangan.php`, `handlers/export_keuangan.php`, dan
`pages/terms.php`.

**1.3 CSRF pada hapus alumni dan hapus user** · kecil
`admin_alumni_handler.php` dan `admin_user_handler.php` masih menghapus
lewat tautan GET tanpa token. Satu tautan yang dibuka admin dapat menghapus
alumni beserta seluruh pengajuan legalisirnya (FK CASCADE).
*Selesai bila:* keduanya memakai token seperti hapus legalisir, dan uji
RBAC menutupnya.

**1.4 Riwayat perubahan pengaturan** · sedang
Audit Trail hanya mencatat "Administrator updated system settings" — tanpa
menyebut apa yang berubah. Bila tarif atau kebijakan berubah diam-diam,
tidak ada yang dapat ditelusuri.
*Selesai bila:* setiap penyimpanan mencatat kunci yang berubah beserta
nilai lama → baru (rahasia cukup ditulis "diubah"), tampil di Audit Trail.

**1.5 Penguncian akun setelah gagal berulang** · sedang
Pembatasan laju sudah ada, tetapi tidak ada penguncian dan tidak ada
pemberitahuan ke pemilik akun.
*Selesai bila:* akun terkunci sementara setelah ambang tertentu, pemiliknya
diberi tahu lewat e-mail, dan super admin dapat membuka kuncinya.

### Prioritas 2 — Operasional

**2.1 Cadangan ke penyimpanan luar** · besar
Cadangan tersimpan di server yang sama dengan basis datanya. Itu melindungi
dari kesalahan manusia, bukan dari kehilangan akun hosting.
*Selesai bila:* cadangan dapat dikirim otomatis ke penyimpanan luar, dan
panel menampilkan kapan terakhir berhasil.

**2.2 Halaman Status Sistem** · sedang
Tidak ada satu layar pun yang menjawab "apakah semuanya berjalan?".
*Selesai bila:* satu halaman menampilkan kapan tiap cron terakhir berhasil,
panjang antrean e-mail, tagihan pending, ruang disk, dan versi skema.

**2.3 Pasang kembali "pakai berkas Repositori"** · kecil
Lihat 2.1 di atas: handler-nya ada, tombolnya tidak.
*Selesai bila:* admin legalisir dapat memenuhi pengajuan dengan berkas
repositori, atau handler-nya dihapus bila fiturnya memang dibatalkan.

**2.4 Uji pemulihan cadangan terjadwal** · sedang
`uji_cadangan` membuktikan dump dapat dipulihkan — tetapi hanya saat
pengembang menjalankannya.
*Selesai bila:* cron bulanan memulihkan cadangan terakhir ke basis data
sementara dan melaporkan hasilnya ke super admin.

### Prioritas 3 — Kenyamanan dan kualitas

**3.1 Periksa `checkMatch()` di halaman atur ulang kata sandi** · kecil
Fungsinya ada tetapi tidak pernah dipasang; kemungkinan indikator
"kata sandi cocok" tidak pernah muncul.

**3.2 Kirim e-mail uji dari panel SMTP** · kecil
Saat ini satu-satunya cara menguji SMTP adalah memakai fitur sungguhan.

**3.3 Pratinjau e-mail sebelum broadcast** · sedang

**3.4 Wizard pemasangan awal** · sedang
Untuk pemasangan di fakultas lain: memandu logo, SMTP, gateway, dan akun
super admin pertama.

**3.5 Uji end-to-end alumni** · sedang
Satu suite yang menempuh daftar → verifikasi → tracer → legalisir → bayar →
selesai, sebagai jaring pengaman terakhir.

**3.6 Pemeriksa aksesibilitas otomatis** · sedang
Kontras warna, label kolom, dan urutan fokus — diperiksa di Chrome headless
seperti `uji_responsif`.

---

## 4. Urutan yang disarankan

1. **Unggah dulu** kode yang sudah selesai (lihat `_dev/UPLOAD.md`), lalu
   konfigurasi Flip (lihat `_dev/PEMBAYARAN.md` bagian 5–6).
2. Prioritas 1.2 dan 1.3 — keduanya kecil, langsung menutup risiko.
3. Prioritas 1.1 dan 1.4 — menyentuh laporan dan jejak audit.
4. Prioritas 2.2 (Status Sistem) — paling terasa bagi operator harian.
5. Sisanya sesuai kebutuhan.

Setiap pekerjaan diakhiri dengan `php tests/run_all.php` hijau dan satu uji
baru yang menutup perilakunya. Itu pola yang dipakai sejauh ini, dan yang
membuat 958 pemeriksaan sekarang berarti.
