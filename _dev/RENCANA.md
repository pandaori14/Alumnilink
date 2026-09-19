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
| Rangkaian uji | `php tests/run_all.php` — 26 langkah, 994 pemeriksaan |
| Versi skema | `2026.09.18.1` |

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
| `handlers/admin_alumni_handler.php?action=delete` | Cabang hapus alumni. Terjaga POST + token sejak 18 September 2026, tetapi **tidak ada tombol** yang memanggilnya; penghapusan alumni dilakukan dari Kelola User. | Pasang tombolnya di Kelola Alumni, atau hapus cabangnya. |
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
| ~~`checkMatch()`~~ | `pages/reset_password.php` (JavaScript) | **Keliru dilaporkan.** Fungsinya terpasang benar lewat `addEventListener('input', checkMatch)` — tanpa tanda kurung, sehingga pemeriksa statis menghitungnya "tidak pernah dipanggil". Diperiksa 19 September 2026: indikator "kata sandi tidak cocok" berfungsi, dan pengiriman form juga ditahan bila tidak cocok. |

### 2.3 Data lama yang tidak lagi dibaca

- Tabel `midtrans_notifications` — digantikan `payment_callbacks`.
  Dipertahankan sebagai sejarah; tidak menambah beban.
- Kunci `midtrans_mdr_rate`, `midtrans_ppn_rate`, `midtrans_payout_fee`,
  `midtrans_margin_admin`, `custom_tax_value` — hanya dibaca sekali saat
  migrasi untuk menyemai profil biaya baru. Aman dibiarkan.
- Kunci `admin_fee` (produksi: 9.997) — sejak 18 September 2026 tidak dibaca
  kode mana pun. Dibiarkan di basis data sebagai jejak nilai lama; menghapus
  barisnya tidak berpengaruh pada apa pun.
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

**Laporan keuangan memakai biaya nyata** · 18 September 2026
Laporan Keuangan dan ekspornya tidak lagi mengurangi potongan tetap
`admin_fee`. Angka itu tebakan yang tidak pernah cocok dengan tarif gateway
mana pun; di produksi ia bahkan memotong sepuluh pembayaran **tunai** yang
tidak pernah dipotong siapa pun, sebesar Rp 99.970. Sekarang uang dibaca
dalam tiga angka — dibayar alumni, biaya layanan (dari `fee_breakdown` yang
tersimpan saat tagihan terbit), dan diterima fakultas. Baris lama tanpa
rincian ditandai "tanpa rincian" dan dihitung nol, bukan ditebak.
`admin_fee` tidak lagi dibaca kode mana pun. Dijaga 15 pemeriksaan baru.

**Hapus akun tidak lagi lewat tautan** · 18 September 2026
`admin_alumni_handler.php` dan `admin_user_handler.php` menghapus lewat GET
tanpa token, sementara `validate_csrf()` hanya memeriksa POST. Keduanya kini
menolak selain POST (405), memvalidasi token lewat `validate_csrf_request()`,
dan mencatat penghapusan di Audit Trail. Ditambah dua penjaga: akun sendiri
tidak dapat dihapus, dan akun yang punya pengajuan legalisir **lunas** tidak
dapat dihapus — sebab `users -> legalisir_requests` memakai ON DELETE CASCADE,
sehingga menghapusnya ikut menghapus catatan uang dari Laporan Keuangan.
Halaman Kelola User yang selama ini membuang seluruh pesan galat dari handler
kini menampilkannya. Dijaga 9 pemeriksaan baru di `uji_rbac`.

**Margin fakultas dilepas dari profil gateway** · 18 September 2026
Margin dulu bernama "biaya aplikasi" dan berada di dalam profil biaya tiap
gateway, disemai dari `midtrans_margin_admin`. Nilainya jadi berbeda-beda
tergantung penyedia yang kebetulan dipakai (Midtrans 2.500, Flip 1.000), dan
pembayaran tunai tidak mendapat margin sama sekali. Sekarang ia satu
pengaturan layanan, `payment_margin_{legalisir,donasi}`, berlaku sama untuk
Flip, Midtrans, maupun tunai — disemai dari gateway pilihan utama supaya
tagihan yang berlaku tidak berubah nominalnya.

Sekaligus menutup dua hal lain. Laporan keuangan kini benar: `fee` hanya
berisi potongan penyedia, sehingga "Diterima Fakultas" tidak lagi kehilangan
margin. Dan tagihan yang pasti dibayar tunai — ketika semua gateway
dimatikan — tidak lagi dikenai biaya gateway yang tidak akan diambil siapa
pun (Rp 63.933 → Rp 57.500 untuk satu dokumen).

Versi skema naik ke `2026.09.18.1`. Migrasi kini menyegarkan cache
pengaturan, karena permintaan pertama sesudah pembaruan sempat memakai nilai
lama — untuk tarif, itu berarti satu tagihan dihitung salah tanpa jejak.

**Donasi masuk Laporan Keuangan** · 18 September 2026
Laporan Keuangan hanya menghitung legalisir; rekap donasi berdiri sendiri di
Kelola Donasi, dan tidak ada satu angka pun yang menyatukan keduanya.
Sekarang keduanya dibangun satu fungsi (`payment_finance_rows()`) dengan
penyaring jenis, tanggal, bulan, status, dan metode — dan halaman maupun
ekspor CSV memanggil fungsi yang sama, sehingga berkas yang diunduh tidak
mungkin berbeda dari layar. Kartu dihitung dari baris yang sedang tampil,
jadi jumlahnya selalu sama dengan tabelnya, termasuk saat penyaring aktif.

Nominal donasi diambil dari ledger, bukan `donations.amount`: kolom itu
menyimpan donasi POKOK, sedangkan yang dibayar donatur termasuk biaya
layanan. Metode bayarnya juga dari ledger, karena tabel donasi tidak pernah
menyimpannya. Dijaga 11 pemeriksaan baru.

### Prioritas 1 — Uang dan keamanan

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

**3.1 Kirim e-mail uji dari panel SMTP** · kecil
Saat ini satu-satunya cara menguji SMTP adalah memakai fitur sungguhan.

**3.2 Pratinjau e-mail sebelum broadcast** · sedang

**3.3 Wizard pemasangan awal** · sedang
Untuk pemasangan di fakultas lain: memandu logo, SMTP, gateway, dan akun
super admin pertama.

**3.4 Uji end-to-end alumni** · sedang
Satu suite yang menempuh daftar → verifikasi → tracer → legalisir → bayar →
selesai, sebagai jaring pengaman terakhir.

**3.5 Pemeriksa aksesibilitas otomatis** · sedang
Kontras warna, label kolom, dan urutan fokus — diperiksa di Chrome headless
seperti `uji_responsif`.

---

## 4. Urutan yang disarankan

1. **Unggah dulu** kode yang sudah selesai (lihat `_dev/UPLOAD.md`), lalu
   konfigurasi Flip (lihat `_dev/PEMBAYARAN.md` bagian 5–6).
2. Prioritas 1.4 — menyentuh jejak audit.
4. Prioritas 2.2 (Status Sistem) — paling terasa bagi operator harian.
5. Sisanya sesuai kebutuhan.

Setiap pekerjaan diakhiri dengan `php tests/run_all.php` hijau dan satu uji
baru yang menutup perilakunya. Itu pola yang dipakai sejauh ini, dan yang
membuat 994 pemeriksaan sekarang berarti.
