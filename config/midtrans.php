<?php
/**
 * config/midtrans.php
 * ─────────────────────────────────────────────────────────
 * BERKAS INI SUDAH TIDAK DIPAKAI (deprecated).
 *
 * Sebelumnya berkas ini mendefinisikan MIDTRANS_SERVER_KEY dan
 * MIDTRANS_CLIENT_KEY sebagai konstanta hardcoded. Tidak ada satu pun
 * berkas dalam aplikasi yang me-require berkas ini -- sudah diverifikasi
 * dengan pencarian menyeluruh pada seluruh kodebase.
 *
 * Sumber kebenaran kunci Midtrans saat ini adalah tabel `settings`:
 *   - settings.midtrans_server_key
 *   - settings.midtrans_client_key
 *   - settings.midtrans_is_production
 * Diatur lewat menu Pengaturan Sistem (Superadmin) dan dibaca antara lain
 * oleh handlers/midtrans_webhook.php serta handlers/donation_handler.php.
 *
 * Isi berkas dikosongkan (bukan dihapus) agar proses upload FTP menimpa
 * versi lama di server yang masih memuat nilai placeholder. Setelah upload
 * berhasil, berkas ini boleh dihapus dari server.
 *
 * JANGAN menambahkan kredensial apa pun di sini.
 */
