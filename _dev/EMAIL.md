# Pengiriman E-mail Massal

## Masalahnya, dengan angka

Proposal sistem menjanjikan broadcast ke *"kotak masuk **puluhan ribu alumni**
dengan sekali klik"*. Perhitungan dari kode dan konfigurasi yang berjalan:

```
batch 20 e-mail × jeda 1,5 detik  = 30 detik per jalan cron
cron tiap 5 menit                 = 240 e-mail/jam = 5.760/hari
```

Tetapi penghambat sesungguhnya bukan antreannya. `smtp_host` menunjuk `smtp.gmail.com`
dengan akun berdomain institusi, yaitu **Google Workspace**, yang membatasi
**±2.000 e-mail per hari**.

| Penerima | Lewat Google Workspace |
|---|---|
| 500 | ±6 jam |
| 2.000 | ±1 hari |
| 5.000 | ±2,5 hari |
| **20.000** | **±10 hari** |

Sepuluh hari bukan "sekali klik". Dan melewati batas itu bukan sekadar lambat
— Google akan **memblokir akun pengirimnya sementara**, yang juga mematikan
e-mail transaksional: verifikasi akun, reset kata sandi, notifikasi legalisir.

> Halaman **Kemajuan Broadcast** dan panel estimasi di layar kirim sekarang
> menampilkan angka ini sebelum tombol ditekan, bukan setelahnya.

---

## Jalan keluarnya: pindah penyedia

**Tidak ada perubahan kode yang diperlukan.** `smtp_host`, `smtp_port`,
`smtp_user`, `smtp_pass`, dan `smtp_secure` semuanya dibaca dari tabel
`settings` (lihat `includes/mailer.php`), jadi berpindah penyedia adalah
mengisi formulir di **Pengaturan Sistem → Sistem & Keamanan**.

### Perbandingan

| Penyedia | Gratis | Berbayar | Catatan |
|---|---|---|---|
| **Google Workspace** (sekarang) | — | 2.000/hari | Terikat kuota akun; blokir memengaruhi e-mail transaksional |
| **Brevo** (d/h Sendinblue) | 300/hari | mulai ±9 USD/bln | Antarmuka Indonesia, SMTP relay biasa |
| **Mailgun** | 100/hari (uji) | mulai ±15 USD/bln | Laporan bounce paling lengkap |
| **Amazon SES** | — | ±0,10 USD/1.000 | Termurah pada volume besar; perlu keluar dari *sandbox* |
| **Postmark** | — | mulai ±15 USD/bln | Pengiriman paling andal; melarang e-mail pemasaran murni |

Untuk 20.000 alumni beberapa kali setahun, **Amazon SES** paling murah dan
**Brevo** paling mudah dipasang. Keduanya sanggup menyelesaikan 20.000 e-mail
dalam hitungan **jam**, bukan hari.

### Langkah pindah

1. Daftar, verifikasi domain `<domain-institusi>` (butuh akses DNS).
2. Ambil kredensial **SMTP relay** — bukan kunci API; sistem ini memakai SMTP.
3. Pengaturan Sistem → isi `smtp_host`, `smtp_port`, `smtp_user`, `smtp_pass`.
4. Naikkan kecepatan — **hanya setelah** langkah 1–3 selesai:

   | Pengaturan | Sekarang | Setelah pindah |
   |---|---|---|
   | `email_daily_limit` | 2000 | sesuai paket penyedia |
   | `email_batch_size` | 20 | 100–200 |
   | `email_throttle_ms` | 1500 | 0–100 |

5. Uji dengan broadcast ke **satu alamat** milik sendiri lebih dulu.

> `email_throttle_ms = 0` sah dan memang benar untuk penyedia transaksional.
> Bawaannya sengaja tetap 1500 supaya tidak ada yang berubah sampai
> seseorang memutuskan untuk mengubahnya.

---

## Bagian yang paling sering dilewatkan: otentikasi domain

Mengganti penyedia tanpa ini akan membuat keadaan **lebih buruk**, bukan
lebih baik. Mengirim 20.000 e-mail dari domain yang tidak terotentikasi adalah
pola persis yang dicari penyaring spam.

Tiga catatan DNS pada `<domain-institusi>` — minta ke pengelola DNS universitas:

**SPF** — menyatakan server mana yang boleh mengirim atas nama domain.
```
<domain-institusi>.  TXT  "v=spf1 include:_spf.google.com include:<penyedia-baru> ~all"
```
Satu domain hanya boleh punya **satu** baris SPF. Tambahkan `include:` penyedia
baru ke baris yang sudah ada; jangan membuat baris kedua.

**DKIM** — tanda tangan kriptografis pada setiap e-mail. Penyedia memberi
nilainya; biasanya berbentuk `<pemilih>._domainkey.<domain-institusi>`.

**DMARC** — memberi tahu penerima apa yang harus dilakukan bila SPF/DKIM gagal.
```
_dmarc.<domain-institusi>.  TXT  "v=DMARC1; p=none; rua=mailto:dmarc@<domain-institusi>;"
```
Mulai dengan `p=none` (hanya melapor). Setelah beberapa minggu laporan
menunjukkan semuanya lolos, naikkan ke `p=quarantine`.

Periksa hasilnya di <https://www.mail-tester.com> — kirim satu e-mail ke sana,
targetkan skor **≥ 9/10** sebelum broadcast pertama.

---

## Alamat mati

Sejak versi ini, alamat yang gagal `email_bounce_threshold` kali (bawaan 3)
pada pengiriman berbeda **berhenti dikirimi otomatis** dan masuk tabel
`unsubscribes` dengan sebab `bounce: ...`.

Ini bukan penghematan, melainkan syarat agar broadcast berikutnya sampai:
penyedia menilai pengirim dari rasio bounce-nya, dan begitu reputasi jatuh,
e-mail yang sah pun mulai masuk folder spam.

**Selalu dapat dibatalkan.** Kotak masuk penuh sementara menghasilkan
kegagalan yang sama dengan alamat yang benar-benar mati. Halaman **Kemajuan
Broadcast** menampilkan daftarnya dengan tombol *Kembalikan* untuk yang
ditandai otomatis. Alumni yang berhenti berlangganan atas kehendak sendiri
tidak dapat dikembalikan admin — itu keputusan mereka.

---

## Token tautan e-mail

Tautan berhenti-langganan ditandatangani `hash_hmac('sha256', ...)` dengan
rahasia yang dibuat acak saat pemasangan pertama dan disimpan di basis data
(`includes/token_lib.php`). Sebelumnya memakai `md5($email . <salt tetap>)`
dengan salt yang tertulis di kode sumber — setiap pemasangan memakai salt
yang sama, sehingga siapa pun yang membaca sumbernya dapat memalsukan tautan
untuk alamat mana pun.

Tautan lama yang sudah terlanjur ada di kotak masuk alumni **masih diterima**
selama pengaturan `legacy_unsubscribe_token` bernilai `1` (bawaan). Matikan
setelah beberapa siklus broadcast. Alasannya bukan kenyamanan: tombol
berhenti-langganan yang rusak mendorong orang menekan "laporkan spam", dan
itu jauh lebih merugikan daripada salt lama yang sudah bocor.

> Halaman `email_unsubscribe` juga baru dibuka untuk pengunjung anonim.
> Sebelumnya ia tidak ada di daftar halaman publik `index.php`, sehingga
> setiap tautan berhenti-langganan mengalihkan penerima ke halaman depan
> tanpa penjelasan — persis keadaan yang memicu laporan spam.

## Yang belum dikerjakan

**Bounce hanya terbaca dari kegagalan SMTP.** Cara ini
menangkap alamat yang ditolak saat pengiriman, tetapi tidak menangkap *soft
bounce* yang dilaporkan belakangan lewat webhook. Bila kelak pindah ke Mailgun
atau SES, keduanya menyediakan webhook bounce yang jauh lebih akurat dan layak
disambungkan.
