# Dependensi Pihak Ketiga

Sepuluh pustaka disalin manual ke dalam proyek ini. **Tidak ada Composer,
tidak ada npm di jalur deploy** — server hanya menerima berkas lewat FTP.

Konsekuensinya jujur saja: tidak ada mekanisme yang akan memberi tahu Anda
bila celah keamanan diumumkan untuk salah satunya. Itu tugas manusia, dan
dokumen ini yang membuatnya mungkin dikerjakan.

Daftar mesin-terbacanya ada di [`tests/dependencies.json`](../tests/dependencies.json),
diperiksa `tests/check_versions.php` setiap kali `run_all.php` berjalan.

---

## Cara memeriksa

```bash
php tests/check_versions.php
```

Membandingkan sha256 setiap berkas dengan yang tercatat. Bila ada yang tidak
cocok, ia berhenti merah — entah karena Anda sengaja memperbarui, atau karena
seseorang menimpanya tanpa mencatat.

Setelah pembaruan yang disengaja:

```bash
php tests/check_versions.php --perbarui
git diff tests/dependencies.json     # periksa selisihnya
```

**Hash, bukan nomor versi.** Berkas JavaScript minified tidak mencantumkan
versinya di dalam berkas, jadi nomor versi hanya seakurat ingatan orang yang
menuliskannya. Hash menangkap lebih banyak: penggantian berkas, unduhan yang
rusak separuh, dan penyuntingan langsung.

---

## PHP

| Pustaka | Versi | Lisensi |
|---|---|---|
| PHPMailer | 7.1.1 | LGPL-2.1 |

Rilis: <https://github.com/PHPMailer/PHPMailer/releases>

**Cara memperbarui.** Unduh rilis, lalu salin **hanya tiga berkas** dari
`src/` ke `includes/PHPMailer/`:

```
src/PHPMailer.php   →  includes/PHPMailer/PHPMailer.php
src/SMTP.php        →  includes/PHPMailer/SMTP.php
src/Exception.php   →  includes/PHPMailer/Exception.php
```

Tidak perlu autoloader — `includes/mailer.php` me-require ketiganya langsung.
Jangan menyalin `src/OAuth.php`, `POP3.php`, atau berkas bahasa: tidak dipakai
dan hanya menambah berkas yang harus ikut naik lewat FTP.

`PHPMailer.php` mendeklarasikan `const VERSION`. `check_versions.php`
membacanya langsung, jadi versi di manifest tidak bisa berbohong.

---

## JavaScript

Seluruhnya di `assets/js/`, dimuat lokal. **Tidak ada CDN di mana pun** —
itu keputusan yang disengaja dan dijaga uji asap (`smoke.php`, bagian 3c).

| Pustaka | Versi | Berkas |
|---|---|---|
| ApexCharts | 3.54.1 | `apexcharts.min.js` |
| html2pdf.js | 0.10.1 | `html2pdf.bundle.min.js` |
| Leaflet | 1.9.4 | `leaflet.js` |
| Leaflet.markercluster | 1.5.3 | `leaflet.markercluster.js` |
| Lucide | 1.31.0 | `lucide.min.js` |
| pdf.js | 3.4.120 | `pdf.min.js` |
| pdf.js worker | 3.4.120 | `pdf.worker.min.js` |
| SortableJS | 1.15.0 | `sortable.min.js` |
| SweetAlert2 | 11.26.25 | `sweetalert2.min.js` |

### Yang mudah salah

**pdf.js dan worker-nya harus SATU VERSI.** Versi yang tidak cocok tidak
menghasilkan pesan galat apa pun — dokumen sekadar gagal dimuat, dan penyebabnya
sangat sulit ditebak dari layar. `check_versions.php` memeriksa kecocokan ini
secara khusus.

**Berkas CSS Leaflet punya ikon terpisah.** `assets/css/images/marker-icon.png`
dirujuk dari dalam `leaflet.css` secara relatif. Bila memperbarui Leaflet,
ikutkan folder `images/`-nya; kalau tidak, penanda peta menjadi kotak kosong.
Uji asap memeriksa berkas ini ada.

---

## Build (hanya lokal)

| Pustaka | Versi | Lokasi |
|---|---|---|
| tailwindcss | ^3.4.19 | `_dev/build/package.json` |

Dipakai membangun `assets/css/app.css`. **Server tidak menjalankan npm** —
yang di-deploy adalah `app.css` hasil build, dan berkas itu memang ikut
di-commit.

```bash
cd _dev/build
npx tailwindcss -c tailwind.config.js -i input.css -o ../../assets/css/app.css --minify
```

Baca `_dev/build/README.md` soal `safelist`: sebagian kelas warna dirakit
lewat interpolasi PHP dan tidak terlihat pemindai Tailwind. Menghapusnya dari
safelist membuat elemen kehilangan warna **tanpa pesan galat apa pun**.

---

## Mengapa tidak memakai Composer

Bukan karena Composer buruk, melainkan karena jalur deploy-nya:

- server tidak memberi akses shell, jadi `composer install` tidak dapat
  dijalankan di sana;
- `vendor/` menambah ratusan berkas yang harus naik lewat FTP untuk satu
  pustaka yang sebenarnya hanya butuh tiga berkas;
- autoloader menambah satu lapisan yang harus benar sebelum apa pun berjalan.

Bila suatu saat server mendukung shell atau deploy berganti ke git-pull,
keputusan ini layak ditinjau ulang. Sampai saat itu, `check_versions.php`
adalah penggantinya yang jujur: ia tidak mengelola dependensi, tetapi ia
memastikan tidak ada yang berubah tanpa diketahui.
