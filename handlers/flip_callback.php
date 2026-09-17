<?php
/**
 * URL callback Flip for Business.
 *
 * Daftarkan alamat ini di Flip for Business Dashboard (Accept Payment >
 * callback URL). Flip tidak menerima URL callback per tagihan.
 *
 *   https://<domain>/alumnilink/handlers/flip_callback.php
 *
 * Callback Flip hanya membawa Validation Token statis tanpa tanda tangan
 * atas isinya, jadi status lunas TIDAK dibaca dari callback. Lihat
 * includes/payment/flip.php dan includes/payment/callback.php.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/payment/callback.php';

payment_handle_callback('flip');
