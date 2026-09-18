# AlumniLink

Sistem manajemen alumni untuk fakultas kedokteran: **Tracer Study**,
**Legalisir dokumen**, **Donasi**, **Broadcast e-mail**, dan **Repositori
dokumen** — dengan pelaporan yang mengikuti kebutuhan akreditasi LAM-PTKes.

PHP 8 native tanpa framework, MariaDB, Tailwind yang di-build lokal.
Di-deploy lewat FTP: **tidak ada langkah build maupun perintah apa pun yang
harus dijalankan di server.**

---

## Menjalankan secara lokal

Butuh PHP 8.1+, MySQL/MariaDB, dan Apache (XAMPP sudah cukup).

```bash
git clone https://github.com/pandaori14/Alumnilink.git
cd Alumnilink

cp .env.example .env          # isi kredensial basis data
mysql -u root -e "CREATE DATABASE alumnilink CHARACTER SET utf8mb4"
mysql -u root alumnilink < schema.sql
```

Buka `http://localhost/alumnilink/`. **Skema dan pengaturan menyusul
sendiri** pada permintaan pertama — lihat "Migrasi" di bawah.

### Berkas `.env.local`

Bila komputer Anda memakai basis data berbeda dari `.env`, buat
`.env.local`; ia dimuat **sesudah** `.env` sehingga nilainya menang, dan
tidak pernah ikut ter-commit.

```ini
DB_HOST=localhost
DB_NAME=alumnilink
DB_USER=root
DB_PASS=
APP_URL=http://localhost/alumnilink
APP_ENV=local        # WAJIB: menahan e-mail agar tidak benar-benar terkirim
```

> `APP_ENV=local` bukan formalitas. Kotak-pasir e-mail di
> `includes/mailer.php` hanya aktif pada nilai itu; tanpanya, menjalankan
> uji akan mengirim e-mail sungguhan ke alamat yang ada di basis data.

---

## Uji

```bash
php tests/run_all.php
```

Menjalankan lint statis, uji asap, dan 19 suite — **±995 pemeriksaan**.
Mengembalikan kode keluar bukan-nol bila ada yang gagal, jadi layak dipakai
sebagai gerbang sebelum deploy.

Uji ini **menulis ke basis data** yang ditunjuk (membuat lalu menghapus data
uji). Ia menolak berjalan terhadap alamat bukan-lokal kecuali diberi
`--saya-yakin-ini-bukan-produksi`.

Tiga pemeriksa statis layak disebut tersendiri, karena masing-masing lahir
dari kesalahan yang pernah lolos ke produksi:

| Pemeriksa | Menangkap |
|---|---|
| `tests/lint_syntax.php` | Galat sintaks di seluruh berkas |
| `tests/lint_leaked_php.php` | Kode PHP yang tercetak sebagai teks — `?>` liar pernah membocorkan 2 KB kode sumber ke setiap halaman, dan `php -l` tetap bersih |
| `tests/lint_unescaped.php` | Keluaran variabel tanpa escape (XSS), termasuk JSON yang di-escape HTML di dalam blok skrip |
| `tests/lint_inline_js.php` | Galat sintaks di JavaScript yang ditulis langsung di berkas PHP |

Tiga suite memakai peramban sungguhan (Chrome headless, tanpa paket npm)
dan otomatis dilewati di mesin yang tidak memasangnya:

| Suite | Menangkap |
|---|---|
| `uji_js_render.php` | Skrip yang rusak hanya SETELAH nilai PHP disisipkan — kerangkanya sah, hasil render-nya tidak |
| `uji_responsif.php` | Halaman yang dapat digeser ke samping di ponsel, tablet, atau laptop; isi yang tertutup menu bawah |
| `uji_alur_bayar.php` | Alur pembayaran lewat HTTP, tanpa satu pun panggilan ke gateway sungguhan |
| `uji_pengaturan.php` | Kolom yang berhenti tersimpan, penyimpanan yang merembet ke nilai lain, rahasia yang ikut tercetak ke halaman, dan pengaturan yang tak terjangkau antarmuka |

---

## Migrasi

Perubahan skema berjalan sendiri, digerbangi `ALUMNILINK_SCHEMA_VERSION` di
`config/db.php`. Migrasi dijalankan **sekali** pada permintaan pertama
setelah berkas baru di-upload, lalu dilewati seluruhnya.

Menambah migrasi: tulis pernyataannya di dalam blok bergerbang, **naikkan
nomor versinya**, dan pastikan pernyataannya idempoten — blok itu dapat
berjalan ulang pada permintaan yang hanya menyentuh handler.

> Satu `ALTER TABLE` yang gagal tanpa `try/catch` sendiri akan mematikan
> seluruh situs: `catch` terluar di `config/db.php` memanggil `die()`.
> Bungkus setiap ALTER yang berisiko dengan catch-nya sendiri.

---

## Struktur

```
config/      koneksi basis data + seluruh migrasi
includes/    pustaka bersama (auth, settings, mailer, pagination, …)
pages/       badan halaman — SELALU dimuat lewat index.php
handlers/    aksi POST
api/         endpoint JSON
cron/        pekerjaan terjadwal (antrean e-mail, geocoder, cadangan,
             rekonsiliasi pembayaran)
tests/       uji asap, lint, dan suite
_dev/build/  sumber Tailwind
```

`pages/` dan `includes/` diblokir dari akses HTTP langsung lewat
`.htaccess`; keduanya hanya boleh dimuat dari `index.php`, yang menerapkan
penjagaan sesi, verifikasi, mode perawatan, dan RBAC.

### Beberapa keputusan yang perlu diketahui

- **Tanpa CDN.** Seluruh pustaka JavaScript dan font dimuat dari
  `assets/`, versinya terkunci dan tercatat di `tests/dependencies.json`,
  diverifikasi `tests/check_versions.php` lewat sha256.
- **Tanpa Composer.** Server tidak punya akses shell. PHPMailer disalin
  manual — tiga berkas. Lihat `_dev/DEPENDENSI.md`.
- **Kapabilitas diturunkan dari menu**, bukan ditulis terpisah
  (`includes/auth_guard.php`). Dengan begitu menu, halaman, dan handler
  tidak dapat menyimpang: menu yang tampil tetapi aksinya ditolak, atau
  menu tersembunyi tetapi aksinya boleh, keduanya mustahil.
- **Escape saat menampilkan, bukan saat menyimpan.** Data yang sama dipakai
  di HTML, CSV, e-mail, dan JSON — masing-masing butuh escape berbeda.
  Fungsinya `e()` di `includes/settings.php`; untuk isi blok skrip
  `js_json()`, karena di sana `e()` justru merusak JSON.
- **Aset dipanggil dengan penanda versi** (`aset()`), diturunkan dari waktu
  ubah berkas. Tanpa itu, peramban terus memakai `app.css` lama setelah
  upload — dan yang terlihat adalah tata letak baru dengan gaya lama.

---

## Konfigurasi Sistem

Satu halaman untuk seluruh pengaturan (`index.php?page=admin_settings`,
khusus super admin), dibagi lima bagian yang dapat ditautkan langsung lewat
tanda pagar — `#keamanan`, `#operasional`, dan seterusnya:

```
Identitas & Tampilan   logo, favicon, warna, kontak bantuan, halaman depan
Layanan & Biaya        harga, ongkir, jenis dokumen, stempel, SLA, tracer
Integrasi & E-mail     SMTP, antrean, Google SSO, kunci Analitik AI
Keamanan & Akses       RBAC, menu per peran, kata sandi, laju, audit
Operasional            cadangan, cron, peta, batas unggah dan impor
```

Satu tombol menyimpan **seluruh bagian**, termasuk yang sedang tidak
terbuka. Karena halaman ini memuat lebih dari seratus kolom, ada kotak
pencarian yang menyaring lintas bagian; tiap kartu membawa kata kunci
sehari-hari, sehingga "ongkir" menemukan *Tarif Pengiriman (Zona)* dan
"backup" menemukan *Cadangan Basis Data*.

Tiga aturan yang dijaga uji:

- **Rahasia tidak pernah dirender kembali.** Kata sandi SMTP, secret Google,
  dan kunci Gemini dikirim kosong; kosong berarti "tidak diubah", dan ada
  kotak "Kosongkan" tersendiri. `type="password"` hanya menyembunyikan di
  layar — sumber halaman tetap terbaca siapa pun.
- **Kunci pembayaran ditolak di sini**, walau di-POST langsung. Satu-satunya
  jalur yang menulisnya adalah panel Gateway Pembayaran.
- **Tidak ada pengaturan yang tersembunyi.** Setiap kunci yang dibaca kode
  harus punya kolom di antarmuka atau terdaftar sebagai internal beserta
  alasannya.

Rencana pengembangan berikutnya ada di [`_dev/RENCANA.md`](_dev/RENCANA.md).

---

## Pembayaran

Dua gateway — **Flip** sebagai pilihan utama dan **Midtrans** sebagai
cadangan — ditambah **tunai** sebagai jalur manual. Satu gateway dipakai
pada satu waktu untuk tagihan **baru**; tagihan yang sudah terbit tetap
dibayar dan dikonfirmasi lewat gateway asalnya. Semuanya diatur di
**Pengaturan Sistem → Gateway Pembayaran** (khusus super admin), bukan di
berkas konfigurasi.

Pilihan utama dan gateway yang benar-benar dipakai adalah dua hal berbeda:
pilihan utama dipakai **bila siap** (kredensial lengkap, profil biaya sah,
tarif sudah dicocokkan); bila belum, tagihan terbit lewat gateway lain yang
siap. Jadi `payment_gateway_active` boleh menunjuk Flip sebelum
kredensialnya ada — begitu diisi, tagihan berpindah sendiri. Bila gateway
yang dipakai menolak saat tagihan dibuat, tagihan dicoba sekali lagi lewat
gateway lain dan alumni tidak melihat kegagalan apa pun.

URL yang harus didaftarkan di dashboard gateway:

```
Midtrans   https://<domain>/alumnilink/handlers/midtrans_webhook.php
Flip       https://<domain>/alumnilink/handlers/flip_callback.php
```

Tiga hal yang membedakannya dari integrasi pembayaran kebanyakan:

- **Callback hanya pemicu.** Setelah diautentikasi, status selalu diambil
  ulang dari API gateway dan nominalnya dicocokkan sebelum apa pun berubah.
  Tanda tangan Midtrans tidak mengikat `transaction_status`, dan callback
  Flip tidak bertanda tangan sama sekali.
- **Uang yang masuk selalu menang.** `expired` tetap bisa menjadi `paid`;
  `paid` tidak pernah mundur. Satu fungsi yang mengubah status, dengan kunci
  baris dan efek samping setelah commit.
- **Ledger tanpa foreign key.** `payment_transactions` tidak ikut terhapus
  ketika alumni, pengajuan, atau kampanye dihapus.

Callback bisa hilang — produksi tidak pernah menerima satu pun sebelum
upgrade ini. Karena itu `cron/payment_reconcile.php` menarik status secara
berkala, dan panel punya tombol cek status manual.

Rumus biaya, runbook pindah gateway, dan penanganan kasus tidak biasa
(bayar ganda, nominal tidak cocok, tunai) ada di
[`_dev/PEMBAYARAN.md`](_dev/PEMBAYARAN.md).

---

### Laporan Keuangan

Satu halaman untuk legalisir **dan** donasi, dengan penyaring jenis, bulan,
tanggal, status, dan metode. Uang dibaca dalam tiga angka: dibayar pembayar,
biaya layanan penyedia (dari rincian yang tersimpan saat tagihan terbit), dan
diterima fakultas. Kartu dihitung dari baris yang sedang tampil, sehingga
jumlahnya selalu sama dengan tabelnya; ekspor CSV memakai fungsi penyaring
yang sama persis dengan halamannya.

### Menyalakan dan mematikan metode pembayaran

Panel **Gateway Pembayaran** punya sakelar per gateway. Yang dimatikan tidak
lagi ditawarkan ke alumni — bukan sebagai pilihan utama, bukan pula sebagai
cadangan — sementara kredensial dan tarifnya tetap tersimpan.

Bila **seluruh** gateway dimatikan, sistem tidak menjanjikan sesuatu yang
tidak dapat ditepati: halaman Legalisir memberi tahu di muka bahwa
pembayaran dilakukan tunai di loket dan permohonan baru langsung ditandai
`cash`, sedangkan halaman Donasi menutup tombolnya karena donasi tidak punya
jalur tunai. Tagihan yang sudah terbit tetap dapat dibayar.

Kanal pembayaran (QRIS, VA, e-wallet, gerai retail) dapat dibatasi untuk
Midtrans lewat panel yang sama; Flip mengatur kanalnya di dashboard Flip.
Rinciannya di `_dev/PEMBAYARAN.md` bagian 0.

## E-mail massal

Batasnya bukan sistem ini melainkan penyedia SMTP. Google Workspace
membatasi ±2.000 e-mail/hari, sehingga 20.000 penerima memakan sepuluh hari.
Layar kirim menampilkan perkiraan itu sebelum tombol ditekan.

`_dev/EMAIL.md` memuat perbandingan penyedia dan langkah SPF/DKIM/DMARC.
Berpindah penyedia **tidak menuntut perubahan kode** — seluruh pengaturan
SMTP dibaca dari basis data.

---

## Lisensi

**Hak milik. Bukan perangkat lunak bebas.** Lihat [LICENSE](LICENSE).

Hak Cipta (c) 2026 Pandu Egi Ferdian. Seluruh hak dilindungi
undang-undang.

Repositori ini publik agar kodenya dapat **dibaca**. Keterbacaan itu bukan
pemberian izin: menjalankan, memasang, menyalin sebagian, atau menurunkan
karya dari perangkat lunak ini memerlukan izin tertulis terlebih dahulu.

Fakultas Kedokteran Universitas Muhammadiyah Surakarta memegang lisensi
pemasangan berdasarkan perjanjian terpisah. Lisensi itu tidak berpindah
kepada pihak ketiga.

Melaporkan kerentanan keamanan tidak memerlukan izin dan selalu diterima.

---

**Proprietary — source-available, not open source.** Reading is permitted;
using, deploying, copying, or deriving from this code is not, without prior
written permission. See [LICENSE](LICENSE).
