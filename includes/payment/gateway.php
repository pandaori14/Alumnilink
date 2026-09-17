<?php
/**
 * Kontrak gateway pembayaran dan registrinya.
 *
 * ── Aturan yang dipegang setiap adaptor ────────────────────────────────
 * 1. parseCallback() hanya MENGENALI dan MENGAUTENTIKASI notifikasi. Status
 *    di dalam body TIDAK dipercaya. Tanda tangan Midtrans tidak mengikat
 *    transaction_status, dan callback Flip hanya membawa token statis tanpa
 *    HMAC. Status sebenarnya selalu diambil ulang lewat fetchStatus().
 * 2. fetchStatus() hanya menerima provider_ref yang berasal dari transaksi
 *    TERSIMPAN, tidak pernah dari body permintaan. Nilai itu menjadi bagian
 *    URL, jadi mengizinkannya dari luar membuka SSRF.
 * 3. Status dikembalikan dalam kosakata internal yang sama untuk semua
 *    gateway: paid | pending | failed | expired | cancelled | refunded |
 *    unknown.
 */

require_once __DIR__ . '/http.php';

interface PaymentGateway
{
    /** Kode tetap: 'midtrans' | 'flip'. */
    public function code();

    /** Nama untuk ditampilkan ke pengguna. */
    public function label();

    /** Kredensial wajib sudah diisi. */
    public function isConfigured();

    public function isProduction();

    /**
     * Buat tagihan.
     *
     * @param array $o merchant_ref, amount(int), title, customer_name,
     *                 customer_email, customer_phone, items[], expiry_minutes,
     *                 return_url
     * @return array ok, provider_ref, snap_token, pay_url, expires_at, error
     */
    public function createCharge(array $o);

    /** @return array ok, status, amount, channel, paid_at, error */
    public function fetchStatus($provider_ref);

    /**
     * Autentikasi satu callback.
     *
     * Body dan POST diterima sebagai parameter, bukan dibaca dari
     * php://input / $_POST di dalam adaptor, supaya logika autentikasi
     * dapat diuji dari CLI tanpa server web.
     *
     * @param string $raw  body mentah permintaan
     * @param array  $post isi $_POST
     * @return array ok, http (kode galat bila !ok), provider_ref,
     *               merchant_ref, event, status_hint, error
     */
    public function parseCallback($raw, array $post);

    /** Nonaktifkan tagihan (upaya terbaik). */
    public function deactivate($provider_ref);

    /** @return array ok, message */
    public function testConnection();

    /**
     * Cara frontend membuka pembayaran untuk satu transaksi.
     *
     * @return array type = snap (token, script, client_key)
     *                    | redirect (url) | none
     */
    public function frontendAction($txn);
}

/** Seluruh gateway yang dikenal, sesuai urutan tampil. */
function payment_gateway_codes()
{
    return ['midtrans', 'flip'];
}

/** Instans gateway berdasarkan kode, atau null bila tidak dikenal. */
function payment_gateway($code)
{
    static $instans = [];
    $code = (string)$code;
    if (isset($instans[$code])) {
        return $instans[$code];
    }
    switch ($code) {
        case 'midtrans':
            require_once __DIR__ . '/midtrans.php';
            return $instans[$code] = new MidtransGateway();
        case 'flip':
            require_once __DIR__ . '/flip.php';
            return $instans[$code] = new FlipGateway();
    }
    return null;
}

/**
 * Kode gateway untuk transaksi BARU.
 *
 * Nilai yang tidak dikenal jatuh ke 'midtrans' — perilaku sistem sebelum
 * sakelar ini ada — alih-alih membuat pembayaran berhenti total.
 */
function payment_active_gateway_code()
{
    $code = setting('payment_gateway_active', 'midtrans');
    return in_array($code, payment_gateway_codes(), true) ? $code : 'midtrans';
}

function payment_active_gateway()
{
    return payment_gateway(payment_active_gateway_code());
}

/** Nama gateway atau metode untuk tampilan; aman untuk 'cash'. */
function payment_gateway_label($code)
{
    if ($code === 'cash') {
        return 'Tunai';
    }
    $g = payment_gateway($code);
    return $g ? $g->label() : 'Pembayaran Online';
}

/**
 * Nama kanal pembayaran yang dapat dibaca manusia.
 *
 * Sebelumnya enum mentah Midtrans ('bank_transfer', 'cstore') langsung
 * masuk ke e-mail dan notifikasi alumni.
 */
function payment_channel_label($channel)
{
    $channel = strtolower(trim((string)$channel));
    $peta = [
        'bank_transfer'   => 'Transfer Bank / Virtual Account',
        'virtual_account' => 'Virtual Account',
        'bank_account'    => 'Transfer Bank',
        'echannel'        => 'Mandiri Bill',
        'permata'         => 'Permata VA',
        'qris'            => 'QRIS',
        'gopay'           => 'GoPay',
        'shopeepay'       => 'ShopeePay',
        'shopeepay_app'   => 'ShopeePay',
        'ovo'             => 'OVO',
        'linkaja'         => 'LinkAja',
        'wallet_account'  => 'E-Wallet',
        'cstore'          => 'Gerai Retail',
        'retail'          => 'Gerai Retail',
        'credit_card'     => 'Kartu Kredit',
        'credit_card_account' => 'Kartu Kredit',
        'akulaku'         => 'Akulaku',
        'cash'            => 'Tunai',
    ];
    if ($channel === '') {
        return 'Pembayaran Online';
    }
    return $peta[$channel] ?? strtoupper(str_replace('_', ' ', $channel));
}
