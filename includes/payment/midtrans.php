<?php
/**
 * Adaptor Midtrans (Snap untuk membuat tagihan, API v2 untuk status).
 *
 * ── Perbedaan dari webhook lama ────────────────────────────────────────
 * handlers/midtrans_webhook.php dulu memercayai transaction_status di body
 * begitu tanda tangan cocok. Padahal tanda tangan Midtrans hanya mengikat
 * order_id + status_code + gross_amount + server_key — BUKAN
 * transaction_status. Siapa pun yang memegang satu notifikasi sah 'pending'
 * dapat menggantinya menjadi 'settlement' tanpa merusak tanda tangan.
 *
 * Adaptor ini hanya memakai callback untuk MENGENALI order, lalu mengambil
 * status sebenarnya dari GET /v2/{order_id}/status.
 */

require_once __DIR__ . '/gateway.php';

class MidtransGateway implements PaymentGateway
{
    public function code()
    {
        return 'midtrans';
    }

    public function label()
    {
        return 'Midtrans';
    }

    public function isConfigured()
    {
        return $this->serverKey() !== '' && $this->clientKey() !== '';
    }

    public function isProduction()
    {
        return setting('midtrans_is_production', '0') === '1';
    }

    private function serverKey()
    {
        return trim((string)setting('midtrans_server_key', ''));
    }

    private function clientKey()
    {
        return trim((string)setting('midtrans_client_key', ''));
    }

    private function snapBase()
    {
        return $this->isProduction() ? 'https://app.midtrans.com' : 'https://app.sandbox.midtrans.com';
    }

    private function apiBase()
    {
        return $this->isProduction() ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';
    }

    private function headers()
    {
        return [
            'Accept: application/json',
            'Content-Type: application/json',
            payment_basic_auth($this->serverKey()),
        ];
    }

    public function createCharge(array $o)
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Kredensial Midtrans belum diisi.'];
        }

        $menit = max(15, (int)($o['expiry_minutes'] ?? 1440));
        $items = [];
        foreach ($o['items'] ?? [] as $it) {
            $items[] = [
                'id'       => substr((string)$it['id'], 0, 50),
                'price'    => (int)$it['price'],
                'quantity' => (int)$it['quantity'],
                'name'     => substr((string)$it['name'], 0, 50),
            ];
        }

        $payload = [
            'transaction_details' => [
                'order_id'     => (string)$o['merchant_ref'],
                'gross_amount' => (int)$o['amount'],
            ],
            'customer_details' => array_filter([
                'first_name' => substr((string)($o['customer_name'] ?? 'Alumni'), 0, 255),
                'email'      => (string)($o['customer_email'] ?? ''),
                'phone'      => (string)($o['customer_phone'] ?? ''),
            ], 'strlen'),
            'expiry' => [
                'start_time' => date('Y-m-d H:i:s O'),
                'unit'       => 'minute',
                'duration'   => $menit,
            ],
        ];
        if ($items) {
            $payload['item_details'] = $items;
        }
        if (!empty($o['return_url'])) {
            $payload['callbacks'] = ['finish' => (string)$o['return_url']];
        }
        // Kanal yang ditawarkan ke alumni. Dikosongkan berarti "seluruh kanal
        // yang aktif di akun Midtrans" — daftar kosong TIDAK boleh dikirim,
        // karena Snap akan menafsirkannya sebagai "tidak ada kanal".
        $kanal = payment_enabled_channels('midtrans');
        if ($kanal) {
            $payload['enabled_payments'] = $kanal;
        }

        $r = payment_http('POST', $this->snapBase() . '/snap/v1/transactions',
            $this->headers(), json_encode($payload));

        if ($r['status'] === 201 && !empty($r['json']['token'])) {
            return [
                'ok'           => true,
                'provider_ref' => (string)$o['merchant_ref'],
                'snap_token'   => (string)$r['json']['token'],
                'pay_url'      => (string)($r['json']['redirect_url'] ?? ''),
                'expires_at'   => date('Y-m-d H:i:s', time() + $menit * 60),
                'error'        => null,
            ];
        }

        $pesan = $r['json']['error_messages'][0] ?? ($r['error'] ?: ('HTTP ' . $r['status']));
        return ['ok' => false, 'error' => 'Midtrans: ' . $pesan];
    }

    public function fetchStatus($provider_ref)
    {
        $ref = (string)$provider_ref;
        if ($ref === '' || !$this->isConfigured()) {
            return ['ok' => false, 'status' => 'unknown', 'error' => 'Referensi atau kredensial kosong.'];
        }

        $r = payment_http('GET', $this->apiBase() . '/v2/' . rawurlencode($ref) . '/status', $this->headers());
        $d = is_array($r['json']) ? $r['json'] : [];
        $kode = (string)($d['status_code'] ?? $r['status']);

        if ($kode === '404') {
            // Snap token sudah terbit tetapi alumni belum memilih metode:
            // Midtrans belum mengenal transaksinya. Bukan galat, bukan lunas.
            return ['ok' => true, 'status' => 'pending', 'amount' => null, 'channel' => null,
                    'paid_at' => null, 'not_found' => true, 'error' => null];
        }
        if ($r['status'] === 0 || $r['status'] >= 500 || $kode === '401' || empty($d['transaction_status'])) {
            return ['ok' => false, 'status' => 'unknown',
                    'error' => 'Status Midtrans tidak dapat dibaca (' . ($r['error'] ?: "HTTP {$r['status']} / $kode") . ').'];
        }

        return [
            'ok'      => true,
            'status'  => self::mapStatus($d['transaction_status'], $d['fraud_status'] ?? null),
            'amount'  => isset($d['gross_amount']) ? (float)$d['gross_amount'] : null,
            'channel' => $d['payment_type'] ?? null,
            'paid_at' => $d['settlement_time'] ?? ($d['transaction_time'] ?? null),
            'error'   => null,
        ];
    }

    /**
     * Kosakata Midtrans -> kosakata internal.
     *
     * capture tanpa fraud_status 'accept' TIDAK dianggap lunas: 'challenge'
     * berarti transaksi kartu masih ditahan untuk ditinjau.
     */
    public static function mapStatus($transaction_status, $fraud_status = null)
    {
        switch ((string)$transaction_status) {
            case 'settlement':
                return 'paid';
            case 'capture':
                return ($fraud_status === null || $fraud_status === 'accept') ? 'paid'
                     : ($fraud_status === 'deny' ? 'failed' : 'pending');
            case 'pending':
            case 'authorize':
                return 'pending';
            case 'deny':
            case 'cancel':
            case 'failure':
                return 'failed';
            case 'expire':
                return 'expired';
            case 'refund':
            case 'partial_refund':
            case 'chargeback':
            case 'partial_chargeback':
                return 'refunded';
        }
        return 'unknown';
    }

    public function parseCallback($raw, array $post)
    {
        $raw = (string)$raw;
        $n = json_decode($raw, true);
        if (!is_array($n) || empty($n['order_id']) || !isset($n['status_code'], $n['gross_amount'], $n['signature_key'])) {
            return ['ok' => false, 'http' => 400, 'error' => 'Notifikasi tidak lengkap.', 'raw' => $raw];
        }
        if ($this->serverKey() === '') {
            // 503, bukan 200: kunci yang belum terisi adalah salah konfigurasi
            // sementara, dan Midtrans harus mengulang setelah diperbaiki.
            return ['ok' => false, 'http' => 503, 'error' => 'Server key belum dikonfigurasi.', 'raw' => $raw];
        }

        $harap = hash('sha512', $n['order_id'] . $n['status_code'] . $n['gross_amount'] . $this->serverKey());
        if (!hash_equals($harap, (string)$n['signature_key'])) {
            return ['ok' => false, 'http' => 403, 'error' => 'Tanda tangan tidak sah.', 'raw' => $raw];
        }

        return [
            'ok'           => true,
            'http'         => 200,
            'merchant_ref' => (string)$n['order_id'],
            'provider_ref' => (string)$n['order_id'],
            'event'        => (string)($n['transaction_id'] ?? $n['order_id']),
            'status_hint'  => (string)($n['transaction_status'] ?? ''),
            'raw'          => $raw,
            'error'        => null,
        ];
    }

    public function deactivate($provider_ref)
    {
        if ((string)$provider_ref === '' || !$this->isConfigured()) {
            return false;
        }
        // /expire hanya berlaku untuk transaksi pending yang sudah dibuat.
        // Token yang belum dipakai membalas 404 — itu pun berarti tidak ada
        // yang bisa dibayar lewat order_id tersebut.
        $r = payment_http('POST', $this->apiBase() . '/v2/' . rawurlencode($provider_ref) . '/expire', $this->headers());
        $kode = (string)($r['json']['status_code'] ?? $r['status']);
        return in_array($kode, ['200', '407', '404', '412'], true);
    }

    public function testConnection()
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'Server key dan client key wajib diisi.'];
        }
        // Order yang pasti tidak ada: kunci sah dibalas 404, kunci salah 401.
        $r = payment_http('GET', $this->apiBase() . '/v2/ALUMNILINK-TES-KONEKSI-' . bin2hex(random_bytes(4)) . '/status', $this->headers());
        $kode = (string)($r['json']['status_code'] ?? $r['status']);
        if ($kode === '404') {
            return ['ok' => true, 'message' => 'Terhubung. Server key diterima Midtrans (' . ($this->isProduction() ? 'produksi' : 'sandbox') . ').'];
        }
        if ($kode === '401') {
            return ['ok' => false, 'message' => 'Server key DITOLAK Midtrans. Pastikan kunci sesuai mode ' . ($this->isProduction() ? 'produksi' : 'sandbox') . '.'];
        }
        return ['ok' => false, 'message' => 'Balasan tidak terduga dari Midtrans: ' . ($r['error'] ?: "HTTP {$r['status']} / $kode")];
    }

    public function frontendAction($txn)
    {
        if (!empty($txn->snap_token)) {
            return [
                'type'       => 'snap',
                'token'      => (string)$txn->snap_token,
                'script'     => $this->snapBase() . '/snap/snap.js',
                'client_key' => $this->clientKey(),
            ];
        }
        if (!empty($txn->pay_url)) {
            return ['type' => 'redirect', 'url' => (string)$txn->pay_url];
        }
        return ['type' => 'none'];
    }
}
