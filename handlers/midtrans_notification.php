<?php
/**
 * handlers/midtrans_notification.php
 * ─────────────────────────────────────────────────────────
 * ALIAS untuk handlers/midtrans_webhook.php.
 *
 * Alasan keberadaan berkas ini:
 * README.md menginstruksikan pendaftaran URL notifikasi Midtrans ke
 *   https://domain/alumnilink/handlers/midtrans_notification.php
 * sementara implementasi sebenarnya bernama midtrans_webhook.php.
 *
 * Bila dashboard Midtrans terlanjur didaftarkan dengan nama berkas ini,
 * seluruh notifikasi pembayaran akan menghasilkan HTTP 404 dan status
 * donasi maupun legalisir tidak pernah diperbarui otomatis.
 *
 * Alias ini membuat KEDUA URL berfungsi, sehingga tidak diperlukan
 * perubahan konfigurasi apa pun di sisi dashboard Midtrans.
 *
 * Seluruh logika -- termasuk verifikasi signature SHA-512 -- tetap berada
 * di midtrans_webhook.php. Jangan menduplikasi logika di sini.
 */

require __DIR__ . '/midtrans_webhook.php';
