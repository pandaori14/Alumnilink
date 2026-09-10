# Build Aset Frontend

Berkas di folder ini dipakai untuk **membangun ulang `assets/css/app.css`**.
Tidak perlu diunggah ke server produksi.

## Kapan perlu build ulang?

Setiap kali Anda **menambahkan kelas Tailwind baru** di berkas PHP mana pun.
Bila hanya mengubah teks, logika PHP, atau memakai kelas yang sudah ada,
build ulang tidak diperlukan.

## Cara menjalankan

Dari dalam folder ini:

```bash
npm install          # sekali saja, memasang tailwindcss v3
npx tailwindcss -c tailwind.config.js -i input.css -o ../../assets/css/app.css --minify
```

Membutuhkan Node.js. Diuji dengan Node v24 dan npm 11.

## ⚠️ Tentang `safelist`

Sebagian kelas Tailwind di proyek ini **dirakit lewat interpolasi PHP**, misalnya:

```php
class="bg-<?php echo $color; ?>-500"
```

Pemindai Tailwind **tidak dapat melihat** kelas semacam itu — yang terbaca hanya
`bg-<?php`. Bila tidak didaftarkan, kelasnya hilang dari CSS hasil build dan
elemen terkait kehilangan warna **tanpa pesan galat apa pun**.

Karena itu `tailwind.config.js` memuat `safelist` yang membangkitkan kombinasi
`bg|text|border|from|to` × 10 warna × 7 tingkat.

Warna dinamis tersebut dipakai antara lain di:
- `pages/dashboard.php` — kartu statistik
- `pages/admin_tracer.php` — gradasi kartu pertanyaan

**Bila Anda menambah warna dinamis baru, tambahkan ke `safelist` lalu build ulang.**

## Cara memeriksa hasil build

Pastikan kelas dinamis benar-benar ada di CSS hasil build:

```bash
grep -c "bg-emerald-500" ../../assets/css/app.css   # harus > 0
```

## Aset lain

Pustaka JavaScript dan font di `assets/js/`, `assets/fonts/`, dan `assets/img/`
diunduh sekali dengan **versi terkunci** dan tidak memerlukan proses build:

| Aset | Versi |
|---|---|
| Lucide Icons | 1.31.0 |
| SweetAlert2 | 11.26.25 |
| ApexCharts | 3.54.1 |
| SortableJS | 1.15.0 |
| Leaflet + MarkerCluster | 1.9.4 / 1.5.3 |
| pdf.js | 3.4.120 |
| html2pdf | 0.10.1 |
| Font | Outfit, Plus Jakarta Sans, Inter, Libre Barcode 39 |

Versi sengaja dikunci (bukan `@latest`) agar pustaka tidak berubah sendiri
sewaktu-waktu tanpa diketahui.
