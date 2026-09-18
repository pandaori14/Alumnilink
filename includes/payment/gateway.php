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
require_once __DIR__ . '/fee.php';

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

/**
 * Apakah gateway dinyalakan super admin?
 *
 * Terpisah dari "siap": sebuah gateway bisa terkonfigurasi lengkap tetapi
 * sengaja dimatikan, misalnya ketika kontrak dengan penyedia itu berakhir
 * atau tarifnya sedang ditinjau.
 */
function payment_gateway_enabled($code)
{
    return setting('payment_gateway_enabled_' . preg_replace('/[^a-z]/', '', (string)$code), '1') === '1';
}

/**
 * Kanal pembayaran yang ditawarkan ke alumni untuk sebuah gateway.
 * Kosong = seluruh kanal yang aktif di akun penyedia.
 */
function payment_enabled_channels($code)
{
    $daftar = json_decode((string)setting('payment_channels_' . preg_replace('/[^a-z]/', '', (string)$code), ''), true);
    return is_array($daftar) ? array_values(array_filter(array_map('strval', $daftar))) : [];
}

/** Kanal yang dapat dipilih super admin, per gateway. */
function payment_channel_options($code)
{
    if ($code === 'midtrans') {
        // Nilai enabled_payments Snap. Hanya yang aktif di akun Midtrans
        // yang benar-benar muncul, apa pun yang dicentang di sini.
        return [
            'qris'            => 'QRIS',
            'gopay'           => 'GoPay',
            'shopeepay'       => 'ShopeePay',
            'other_va'        => 'Virtual Account (bank lain)',
            'bca_va'          => 'BCA Virtual Account',
            'bni_va'          => 'BNI Virtual Account',
            'bri_va'          => 'BRI Virtual Account',
            'permata_va'      => 'Permata Virtual Account',
            'echannel'        => 'Mandiri Bill',
            'indomaret'       => 'Indomaret',
            'alfamart'        => 'Alfamart',
            'credit_card'     => 'Kartu Kredit',
            'akulaku'         => 'Akulaku',
        ];
    }
    // Flip tidak menerima daftar kanal per tagihan; kanalnya diatur di
    // dashboard Flip for Business.
    return [];
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
 * Gateway PILIHAN UTAMA super admin — belum tentu siap dipakai.
 *
 * Bawaannya 'flip'. Nilai yang tidak dikenal jatuh ke 'flip' juga, bukan
 * berhenti total.
 */
function payment_preferred_gateway_code()
{
    $code = setting('payment_gateway_active', 'flip');
    return in_array($code, payment_gateway_codes(), true) ? $code : 'flip';
}

/**
 * Alasan sebuah gateway BELUM dapat menerbitkan tagihan. Kosong = siap.
 *
 * Sengaja TIDAK memeriksa tes koneksi terakhir. Kesiapan di sini dipakai
 * setiap kali tagihan dibuat; menautkannya pada tes yang kedaluwarsa tiap
 * 24 jam akan membuat gateway berpindah sendiri di tengah malam. Tes
 * koneksi tetap wajib untuk memindahkan PILIHAN secara manual
 * (payment_switch_blockers di panel.php).
 *
 * Tarif yang belum ditandai "sudah dicocokkan" dianggap belum siap: profil
 * biaya bawaan Flip nol, dan menagih dengan biaya nol berarti fakultas
 * menanggung sendiri potongan gateway tanpa ada yang menyadarinya.
 */
function payment_gateway_readiness($code)
{
    $gw = payment_gateway($code);
    if (!$gw) {
        return ['gateway tidak dikenal'];
    }
    $alasan = [];
    if (!payment_gateway_enabled($code)) {
        // Dimatikan super admin: tidak dipakai sebagai pilihan utama, tidak
        // pula sebagai cadangan. Ini satu-satunya alasan "belum siap" yang
        // merupakan keputusan, bukan kekurangan konfigurasi.
        $alasan[] = 'dimatikan oleh super admin';
    }
    if (!$gw->isConfigured()) {
        $alasan[] = 'kredensial belum lengkap';
    }
    $profil = payment_fee_profile($code);
    if ($galat = payment_fee_profile_error($profil)) {
        $alasan[] = 'profil biaya tidak sah (' . $galat . ')';
    } elseif (!$profil['reviewed']) {
        $alasan[] = 'tarif belum ditandai sudah dicocokkan dengan tarif resmi';
    }
    return $alasan;
}

function payment_gateway_is_ready($code)
{
    return payment_gateway_readiness($code) === [];
}

/**
 * Gateway yang BENAR-BENAR dipakai transaksi baru.
 *
 * Pilihan utama bila siap; bila belum, gateway lain yang siap. Dengan
 * begitu "pilihan utama" dapat disetel ke gateway yang kredensialnya belum
 * ada tanpa menghentikan pembayaran — begitu kredensial dan tarifnya diisi,
 * tagihan baru berpindah sendiri tanpa menyentuh kode.
 *
 * Bila tidak ada yang siap, pilihan utama tetap dikembalikan supaya
 * kegagalannya muncul satu kali di tempat yang jelas (payment_charge),
 * lengkap dengan pesan dari adaptornya.
 */
function payment_active_gateway_code()
{
    $pilihan = payment_preferred_gateway_code();
    if (payment_gateway_is_ready($pilihan)) {
        return $pilihan;
    }
    foreach (payment_gateway_codes() as $code) {
        if ($code !== $pilihan && payment_gateway_is_ready($code)) {
            return $code;
        }
    }
    return $pilihan;
}

function payment_active_gateway()
{
    return payment_gateway(payment_active_gateway_code());
}

/**
 * Gateway cadangan untuk dicoba bila $code gagal menerbitkan tagihan.
 * null bila tidak ada yang siap atau fitur cadangan dimatikan.
 */
function payment_backup_gateway_code($code)
{
    if (setting('payment_fallback_enabled', '1') !== '1') {
        return null;
    }
    foreach (payment_gateway_codes() as $lain) {
        if ($lain !== $code && payment_gateway_is_ready($lain)) {
            return $lain;
        }
    }
    return null;
}

/**
 * Adakah metode pembayaran online yang DINYALAKAN super admin?
 *
 * Sengaja diukur dari sakelar, bukan dari kesiapan. Gateway yang menyala
 * tetapi kredensialnya bermasalah adalah gangguan yang harus terlihat:
 * tagihannya gagal terbit, alumni menekan "buat tagihan baru", dan panel
 * menampilkan sebabnya. Sebaliknya, gateway yang dimatikan adalah keputusan
 * — dan ketika semuanya dimatikan, alumni diberi tahu di muka bahwa
 * pembayaran dilakukan tunai, alih-alih dibiarkan menabrak kegagalan.
 */
function payment_online_available()
{
    foreach (payment_gateway_codes() as $code) {
        if (payment_gateway_enabled($code)) {
            return true;
        }
    }
    return false;
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
