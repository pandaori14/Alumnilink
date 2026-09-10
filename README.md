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

Menjalankan lint statis, uji asap, dan 13 suite — **±500 pemeriksaan**.
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
| `tests/lint_unescaped.php` | Keluaran variabel tanpa escape (XSS) |

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
cron/        pekerjaan terjadwal (antrean e-mail, geocoder, cadangan)
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
  Fungsinya `e()` di `includes/settings.php`.

---

## E-mail massal

Batasnya bukan sistem ini melainkan penyedia SMTP. Google Workspace
membatasi ±2.000 e-mail/hari, sehingga 20.000 penerima memakan sepuluh hari.
Layar kirim menampilkan perkiraan itu sebelum tombol ditekan.

`_dev/EMAIL.md` memuat perbandingan penyedia dan langkah SPF/DKIM/DMARC.
Berpindah penyedia **tidak menuntut perubahan kode** — seluruh pengaturan
SMTP dibaca dari basis data.

---

## Lisensi

Belum ditentukan. Tanpa berkas lisensi, hak cipta tetap sepenuhnya pada
pemilik dan tidak ada izin penggunaan yang diberikan kepada siapa pun.
