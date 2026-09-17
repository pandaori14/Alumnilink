<?php
/**
 * URL notifikasi Midtrans.
 *
 * Seluruh logika ada di includes/payment/callback.php, dipakai bersama
 * handlers/flip_callback.php. Berkas ini dipertahankan hanya supaya URL
 * yang sudah terdaftar di dashboard Midtrans tidak berubah.
 *
 * Perbedaan dari implementasi sebelumnya:
 *   - Status TIDAK lagi dibaca dari body. Tanda tangan Midtrans tidak
 *     mengikat transaction_status, jadi status diambil ulang dari
 *     GET /v2/{order_id}/status.
 *   - Status yang sudah lunas tidak pernah mundur.
 *   - Retry setelah galat diproses ulang (dulu dibalas "sudah diproses").
 *   - Galat sementara dibalas 5xx supaya Midtrans mengulang.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/payment/callback.php';

payment_handle_callback('midtrans');
