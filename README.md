# AlumniLink — Sistem Manajemen Alumni Terintegrasi 🎓

Platform manajemen alumni untuk **Fakultas Kedokteran UMS**: tracer study, legalisir
dokumen daring, donasi, CMS berita/event, dan broadcast e-mail — dalam satu sistem.

> **Produksi:** `https://apps-kedokteran.ums.ac.id/alumnilink/`
> **Terakhir diperbarui:** 12 Agustus 2026

---

## 🚀 Fitur

### Tracer Study
Kuesioner dinamis dengan *conditional skip logic* (pertanyaan anak muncul berdasarkan
jawaban induk), penomoran otomatis sisi klien, dan penyusunan ulang urutan lewat
seret-lepas. Berperan sebagai **gerbang layanan**: alumni wajib memperbarui tracer
sebelum mengajukan legalisir atau mendaftar event. Masa berlakunya dapat diatur.

### Legalisir Dokumen
Alur lengkap: pengajuan → perhitungan biaya → pembayaran → pemrosesan admin →
softcopy terverifikasi ber-QR. Mendukung ambil di tempat maupun pengiriman kurir
dengan tarif per zona. Dokumen dapat diambil dari unggahan alumni atau dari
**repositori resmi** yang dikelola admin.

### Donasi (Midtrans)
Kampanye dengan target dan tenggat, pembayaran via Midtrans Snap, verifikasi
otomatis lewat webhook, serta pencatatan dan ekspor keuangan.

### CMS Berita & Event
Manajemen konten dengan pendaftaran event yang tersalur melalui gerbang tracer study.
Halaman publik dapat diakses tanpa login.

### Broadcast & Template E-mail
Editor HTML dengan pratinjau langsung, 8 template bawaan, CRUD layout kustom,
antrean e-mail dengan mekanisme percobaan ulang, dan tautan berhenti berlangganan.

### Autentikasi
Login lokal dan **Google OAuth 2.0**, pemulihan kata sandi via e-mail dengan token
berbatas waktu, verifikasi akun oleh admin, serta pembatasan laju permintaan.

### Notifikasi & Audit
Notifikasi dalam aplikasi berlencana, dan **audit trail** yang mencatat aktivitas
sensitif lengkap dengan alamat IP dan resolusi lokasi.

---

## 🛠️ Teknologi

| Lapisan | Teknologi |
|---|---|
| Backend | PHP 8.1+ native (tanpa framework), PDO + prepared statement |
| Database | MySQL / MariaDB |
| Frontend | Tailwind CSS **hasil build lokal**, Lucide Icons, SweetAlert2 |
| Aset | **Seluruhnya dilayani lokal dari `assets/`** — tanpa CDN eksternal |
| Pembayaran | Midtrans Snap |
| E-mail | PHPMailer + SMTP |
| Peta | Leaflet + MarkerCluster |

> **Catatan penting:** sistem ini **tidak lagi memuat aset dari CDN**.
> `cdn.tailwindcss.com` meng-compile CSS di dalam peramban pada setiap pemuatan
> halaman dan tidak diperuntukkan bagi produksi. Seluruh pustaka kini terkunci
> versinya dan dilayani dari `assets/`, sehingga sistem tetap tampil normal pada
> jaringan kampus yang membatasi domain luar.

### Membangun ulang CSS
Diperlukan hanya bila Anda menambah kelas Tailwind baru:
```bash
cd _dev/build && npm install
npx tailwindcss -c tailwind.config.js -i input.css -o ../../assets/css/app.css --minify
```
Kelas yang dirakit lewat interpolasi PHP (mis. `bg-<?= $color ?>-500`) tidak terdeteksi
pemindai, sehingga harus didaftarkan pada `safelist` konfigurasi build.

---

## 🔐 Arsitektur Keamanan

| Mekanisme | Lokasi | Keterangan |
|---|---|---|
| **Penjagaan peran (RBAC)** | `includes/auth_guard.php` | Kapabilitas **diturunkan otomatis** dari izin sidebar, sehingga menu, halaman, dan handler tidak mungkin tidak sinkron |
| **Mode audit RBAC** | `settings.rbac_enforce` | `0` = pelanggaran hanya dicatat; `1` = ditolak. Lihat bagian Operasional |
| **Sesi** | `includes/session_boot.php` | `HttpOnly`, `SameSite=Lax`, `Secure` otomatis di HTTPS, `session_regenerate_id` saat login |
| **CSRF** | `includes/csrf.php` | Mendukung token via form, query string, dan header `X-CSRF-Token` |
| **Akses dokumen** | `serve_document.php` | Path **selalu** dari basis data, tidak pernah dari pengguna; folder dokumen ditutup `.htaccess` |
| **Pembatasan laju** | `includes/rate_limit.php` | 9 tindakan, ambang dapat diatur |
| **Halaman galat** | `includes/error_page.php` | Menggantikan `die()` bertelanjang teks |

### Berkas yang diblokir dari akses publik
`.htaccess` di akar memblokir `.env`, `*.sql`, `*.md`, `*.log`, serta direktori
`_dev/`, `logs/`, dan `tests/`. Pemblokiran memakai `mod_rewrite`
(bukan `Require all denied`) agar tidak memicu HTTP 500 pada hosting yang
membatasi `AllowOverride`.

---

## ⚙️ Konfigurasi

**Sumber kebenaran konfigurasi adalah tabel `settings` (±82 kunci)**, bukan `.env`.
Berkas `.env` hanya memuat koneksi basis data dan `APP_URL`.

Seluruh pengaturan diubah lewat **Pengaturan Sistem** (khusus `super_admin`), terbagi
dalam tab: Identitas, Sistem, Pembayaran, Integrasi API, Dokumen & Akses, serta
**Aturan & Keamanan**.

Tab **Aturan & Keamanan** memuat nilai-nilai yang sebelumnya ter-hardcode:

| Kelompok | Isi |
|---|---|
| Aturan Layanan | Masa berlaku tracer, ambang pengingat, kode prodi cadangan, baris per halaman |
| Keamanan Akun | Panjang minimal kata sandi, masa berlaku tautan reset, keluar otomatis |
| Pembatasan Laju | 9 tindakan × maksimal percobaan × rentang menit |
| Operasional & Visual | Batch e-mail, percobaan ulang, batch geocoding, favicon, warna institusi |

Pemilih warna institusi menampilkan **rasio kontras WCAG secara langsung** dan
memperingatkan bila warna pilihan membuat teks putih sulit dibaca.

### `.env`
```ini
APP_NAME="AlumniLink"
APP_URL="https://apps-kedokteran.ums.ac.id/alumnilink"
APP_ENV="production"

DB_HOST="localhost"
DB_NAME="alumnilink"
DB_USER="..."
DB_PASS="..."
```

---

## 📦 Instalasi Lokal

1. PHP 8.1+ dan MySQL/MariaDB aktif (XAMPP memadai).
2. Tempatkan proyek di `htdocs/alumnilink`.
3. Salin `.env.example` menjadi `.env`, sesuaikan kredensial basis data.
4. Buat basis data `alumnilink`, impor `schema.sql`.
5. Buka `http://localhost/alumnilink` — migrasi tambahan berjalan otomatis
   pada permintaan pertama.
6. **Buat akun super_admin sendiri**, lalu segera ganti kata sandinya.

> ⚠️ Jangan memakai kata sandi contoh apa pun di lingkungan produksi. Pastikan
> setiap akun administrator memakai kata sandi unik yang kuat.

### Docker
```bash
cd _dev/docker && docker-compose up -d   # akses di http://localhost:8080
```

---

## 🚢 Deployment

Deployment dilakukan lewat **FTP**. Prosedur lengkap, daftar berkas, dan langkah
verifikasi ada di **`_dev/docs/DEPLOY_CHECKLIST.md`**.

### Jangan pernah diunggah
`.env` (kredensial lokal), `_dev/`, `*.sql`, `logs/`, `tests/`, dan berkas `*.md`.

### Migrasi skema
`config/db.php` memuat `ALUMNILINK_SCHEMA_VERSION`. Pada permintaan pertama setelah
upload, seluruh migrasi berjalan **sekali** lalu menuliskan `schema_version` ke tabel
`settings` dan dilewati pada permintaan berikutnya. Tidak ada langkah manual.

Bila menambah migrasi baru, **naikkan** nomor versi tersebut.

### Webhook Midtrans
Daftarkan salah satu URL berikut — keduanya berfungsi:
```
https://domain-anda/alumnilink/handlers/midtrans_webhook.php
https://domain-anda/alumnilink/handlers/midtrans_notification.php
```
Webhook memverifikasi signature SHA-512, bersifat **idempoten** (notifikasi yang
terkirim ulang tidak menghasilkan e-mail atau notifikasi ganda), dan memvalidasi
ulang nominal terhadap catatan basis data.

### Google OAuth
Daftarkan *Authorized Redirect URI*:
```
https://domain-anda/alumnilink/handlers/google_oauth.php
```
Client ID dan Secret diisi lewat Pengaturan Sistem, bukan `.env`.

---

## ⏰ Cron

Ketiganya **wajib** dijadwalkan di produksi:

| Job | Frekuensi | Fungsi |
|---|---|---|
| `cron/process_email_queue.php` | tiap 5 menit | Mengirim antrean e-mail, dengan percobaan ulang |
| `cron/geocoder.php` | berkala | Memetakan alamat alumni untuk peta persebaran |
| `cron/tracer_reminder.php` | harian/mingguan | Mengirim pengingat pengisian tracer |

Contoh (Windows Task Scheduler):
```
C:\xampp\php\php.exe C:\xampp\htdocs\alumnilink\cron\process_email_queue.php
```

---

## 🧪 Pengujian

### Smoke test otomatis
```bash
php tests/smoke.php https://apps-kedokteran.ums.ac.id/alumnilink
php tests/smoke.php http://localhost/alumnilink --with-db
```
Menjalankan **54 pemeriksaan**: pemblokiran berkas sensitif, ketersediaan halaman
publik, aset lokal, penguncian folder dokumen, otorisasi `serve_document.php`,
penolakan CSRF, dan ketersediaan webhook. Kode keluar `0` bila seluruhnya lulus.

Opsi `--with-db` menambahkan pengujian jalur positif penyajian dokumen dan hanya
dapat dijalankan pada mesin yang memiliki akses basis data.

### Lint
```powershell
Get-ChildItem -Recurse -Filter *.php handlers,pages,includes,config |
  ForEach-Object { c:\xampp\php\php.exe -l $_.FullName }
```

### Belum tersedia
Belum ada uji unit maupun uji integrasi. Verifikasi alur bisnis end-to-end
(pengajuan → bayar → proses → softcopy) masih dilakukan manual.

---

## 📁 Struktur Direktori

| Direktori | Isi |
|---|---|
| `/` | `index.php` (router), `serve_document.php`, `view_softcopy.php`, `verify.php`, `cetak_label.php` |
| `pages/` | Berkas antarmuka; `admin_*` untuk panel administrator |
| `handlers/` | Aksi sisi server (target POST) |
| `includes/` | Komponen bersama: header, footer, `auth_guard`, `session_boot`, `settings`, `csrf`, `logger`, `mailer`, `rate_limit`, `error_page`, PHPMailer |
| `config/` | `db.php` — env loader, koneksi PDO, migrasi berversi |
| `api/` | Endpoint JSON dan `bridge.php` untuk klien Flutter |
| `cron/` | Job terjadwal |
| `assets/` | **CSS, JS, font, dan gambar lokal** — wajib ikut diunggah |
| `uploads/` | Berkas unggahan; `legalisir/`, `repository/`, `accreditation/` ditutup dari akses langsung |
| `tests/` | Smoke test (khusus CLI, diblokir dari web) |
| `_dev/` | **Seluruh berkas pengembangan** — dokumen, skrip debug, konfigurasi build, Docker. Diblokir dari web, **jangan diunggah** |


---

## 🔀 Catatan Reverse Proxy

`includes/logger.php` mendeteksi IP klien asli dengan urutan: `CF-Connecting-IP` →
`X-Real-IP` → `X-Forwarded-For` → `REMOTE_ADDR`.

Bila berada di belakang Nginx, teruskan header berikut agar audit trail mencatat IP
alumni yang sebenarnya:
```nginx
proxy_set_header X-Real-IP       $remote_addr;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
```
Pengguna Cloudflare tidak memerlukan konfigurasi tambahan.

---

## 🧭 Operasional

### Penegakan RBAC (dua fase)
Peran `admin_tracer`, `admin_legalisir`, dan `keuangan` sebelumnya memiliki akses
setara `super_admin`. Pemisahan izin kini tersedia, namun dipasang dalam **mode audit**
agar tidak ada staf yang terkunci mendadak.

1. **Fase audit** (`rbac_enforce = 0`, bawaan) — pelanggaran dicatat di Audit Trail
   sebagai `RBAC_AUDIT`, akses tetap diizinkan.
2. Setelah beberapa hari, tinjau Audit Trail dan saring aksi `RBAC_AUDIT`.
3. **Fase penegakan** — nyalakan *Tegakkan Pemisahan Izin Peran* di Pengaturan Sistem.
   Berlaku seketika, tanpa upload ulang, dan dapat dimatikan kembali kapan saja.

`Kelola User` **selalu** khusus `super_admin` apa pun isi pengaturan sidebar, karena
halaman tersebut dapat mengubah peran.

### Integritas repositori dokumen
Halaman Repositori Dokumen menampilkan peringatan bila terdapat baris yang berkas
fisiknya tidak ada di server, dan menandai barisnya dengan lencana **Berkas Hilang**.

---

## ♿ Aksesibilitas

- `lang="id"` konsisten di seluruh halaman
- Indikator fokus keyboard (`:focus-visible`) memakai warna institusi
- Tautan *Lewati ke konten utama* (WCAG 2.4.1)
- Menghormati `prefers-reduced-motion`
- ±88% kontrol form terhubung dengan label atau `aria-label`
- Pemeriksa kontras WCAG pada pemilih warna

Sisa kontrol tanpa label adalah elemen yang dibangkitkan JavaScript di dalam modal.

---

## 📄 Lisensi

Dikembangkan untuk **Fakultas Kedokteran Universitas Muhammadiyah Surakarta**.
Seluruh kode sumber dan aset desain dilindungi untuk kepentingan institusi.
