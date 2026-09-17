<?php
/**
 * Adaptor Flip for Business — Accept Payment (Bill / Payment Link) API v2.
 *
 * ── Mengapa v2 ─────────────────────────────────────────────────────────
 * v3 adalah versi terkini, tetapi dokumentasinya (docs.flip.id) tidak dapat
 * dipastikan saat integrasi ini ditulis: sumber yang tersedia saling
 * bertentangan soal content-type Create Bill v3 dan format callback-nya.
 * v2 terdokumentasi lengkap: form-urlencoded untuk permintaan, dan callback
 * berupa field `data` (JSON) + `token`.
 *
 * Mengirim uang lewat format yang ditebak bukan pilihan. Bila v2 dihentikan
 * Flip, "Tes koneksi" dan "Transaksi uji" di panel gateway akan gagal lebih
 * dulu, dan super admin tetap di Midtrans.
 *
 * ── Keamanan callback ─────────────────────────────────────────────────
 * Callback Flip hanya membawa Validation Token STATIS — tanpa tanda tangan
 * atas isi. Siapa pun yang mengetahui token dapat mengarang callback berisi
 * status apa pun. Karena itu callback hanya dipakai untuk MENGENALI tagihan;
 * status lunas selalu diambil ulang lewat GET /pwf/{link_id}/payment dengan
 * secret key yang tidak pernah meninggalkan server.
 */

require_once __DIR__ . '/gateway.php';

class FlipGateway implements PaymentGateway
{
    public function code()
    {
        return 'flip';
    }

    public function label()
    {
        return 'Flip';
    }

    public function isConfigured()
    {
        return $this->secretKey() !== '' && $this->validationToken() !== '';
    }

    public function isProduction()
    {
        return setting('flip_is_production', '0') === '1';
    }

    private function secretKey()
    {
        return trim((string)setting('flip_secret_key', ''));
    }

    private function validationToken()
    {
        return trim((string)setting('flip_validation_token', ''));
    }

    private function base()
    {
        return $this->isProduction() ? 'https://bigflip.id/api' : 'https://bigflip.id/big_sandbox_api';
    }

    private function headers()
    {
        return [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            payment_basic_auth($this->secretKey()),
        ];
    }

    /** link_id Flip selalu angka; apa pun selain itu tidak boleh masuk URL. */
    private static function validLinkId($id)
    {
        return is_string($id) && $id !== '' && ctype_digit($id);
    }

    public function createCharge(array $o)
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Kredensial Flip belum diisi.'];
        }
        $amount = (int)$o['amount'];
        if ($amount < 10000) {
            return ['ok' => false, 'error' => 'Flip menolak tagihan di bawah Rp 10.000.'];
        }

        $menit = max(15, (int)($o['expiry_minutes'] ?? 1440));
        $nama  = trim((string)($o['customer_name'] ?? ''));
        $email = trim((string)($o['customer_email'] ?? ''));
        $telp  = preg_replace('/[^0-9+]/', '', (string)($o['customer_phone'] ?? ''));
        $alamat = trim((string)($o['customer_address'] ?? ''));

        // step 2 melewati formulir data di halaman Flip, tetapi mewajibkan
        // nama, e-mail, telepon, dan alamat. Bila salah satunya tidak ada,
        // alumni mengisinya sendiri di halaman Flip (step 1) — lebih baik
        // satu langkah tambahan daripada tagihan yang ditolak.
        $lengkap = $nama !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($telp) >= 9 && $alamat !== '';

        $form = [
            // Tagihan v2 tidak punya reference_id; merchant_ref dimasukkan ke
            // judul supaya tetap dapat dicocokkan secara manual di dashboard.
            'title'                    => substr('AlumniLink ' . $o['merchant_ref'], 0, 50),
            'type'                     => 'SINGLE',
            'amount'                   => $amount,
            'expired_date'             => date('Y-m-d H:i', time() + $menit * 60),
            'redirect_url'             => (string)($o['return_url'] ?? ''),
            'is_address_required'      => 0,
            'is_phone_number_required' => 0,
            'step'                     => $lengkap ? 2 : 1,
        ];
        if ($lengkap) {
            $form += [
                'sender_name'         => substr($nama, 0, 100),
                'sender_email'        => $email,
                'sender_phone_number' => $telp,
                'sender_address'      => substr($alamat, 0, 200),
            ];
        }

        $r = payment_http('POST', $this->base() . '/v2/pwf/bill', $this->headers(), $form);
        $d = is_array($r['json']) ? $r['json'] : [];

        if ($r['status'] === 200 && isset($d['link_id']) && !empty($d['link_url'])) {
            $url = (string)$d['link_url'];
            if (!preg_match('#^https?://#i', $url)) {
                $url = 'https://' . ltrim($url, '/');
            }
            return [
                'ok'           => true,
                'provider_ref' => (string)$d['link_id'],
                'snap_token'   => null,
                'pay_url'      => $url,
                'expires_at'   => date('Y-m-d H:i:s', time() + $menit * 60),
                'error'        => null,
            ];
        }

        $pesan = $d['errors'][0]['message'] ?? ($d['message'] ?? ($r['error'] ?: ('HTTP ' . $r['status'])));
        return ['ok' => false, 'error' => 'Flip: ' . (is_string($pesan) ? $pesan : json_encode($pesan))];
    }

    public function fetchStatus($provider_ref)
    {
        $ref = (string)$provider_ref;
        if (!self::validLinkId($ref) || !$this->isConfigured()) {
            return ['ok' => false, 'status' => 'unknown', 'error' => 'Referensi Flip tidak sah atau kredensial kosong.'];
        }

        $r = payment_http('GET', $this->base() . '/v2/pwf/' . $ref . '/payment', $this->headers());
        if ($r['status'] !== 200 || !is_array($r['json'])) {
            return ['ok' => false, 'status' => 'unknown',
                    'error' => 'Status Flip tidak dapat dibaca (' . ($r['error'] ?: "HTTP {$r['status']}") . ').'];
        }

        $data = $r['json']['data'] ?? (array_is_list($r['json']) ? $r['json'] : []);
        return self::summarizePayments(is_array($data) ? $data : []);
    }

    /**
     * Ringkas daftar pembayaran satu tagihan SINGLE menjadi satu status.
     *
     * Satu pembayaran SUCCESSFUL cukup untuk lunas. Daftar kosong berarti
     * alumni belum membayar — pending, bukan gagal.
     */
    public static function summarizePayments(array $data)
    {
        $status = [];
        foreach ($data as $p) {
            if (!is_array($p)) {
                continue;
            }
            $s = strtoupper((string)($p['status'] ?? ''));
            if ($s === 'SUCCESSFUL') {
                return [
                    'ok'      => true,
                    'status'  => 'paid',
                    'amount'  => isset($p['amount']) ? (float)$p['amount'] : null,
                    'channel' => $p['sender_bank'] ?? null,
                    'paid_at' => $p['created_at'] ?? null,
                    'error'   => null,
                ];
            }
            $status[] = $s;
        }

        $hasil = 'pending';
        if ($status && !in_array('PENDING', $status, true)) {
            if (count(array_unique($status)) === 1 && $status[0] === 'CANCELLED') {
                $hasil = 'expired';
            } elseif (in_array('FAILED', $status, true)) {
                $hasil = 'failed';
            }
        }
        // not_found: belum ada upaya bayar sama sekali. payment_recheck()
        // memakainya untuk mengedaluwarsakan tagihan yang masa berlakunya
        // sudah lewat, karena Flip tidak menjamin callback untuk itu.
        return ['ok' => true, 'status' => $hasil, 'amount' => null, 'channel' => null,
                'paid_at' => null, 'not_found' => !$status, 'error' => null];
    }

    public function parseCallback($raw, array $post)
    {
        $token = (string)($post['token'] ?? '');
        $raw   = (string)($post['data'] ?? '');

        if ($this->validationToken() === '') {
            return ['ok' => false, 'http' => 503, 'error' => 'Validation token Flip belum dikonfigurasi.', 'raw' => $raw];
        }
        if ($token === '' || !hash_equals($this->validationToken(), $token)) {
            return ['ok' => false, 'http' => 403, 'error' => 'Validation token tidak sah.', 'raw' => $raw];
        }

        $d = json_decode($raw, true);
        if (!is_array($d)) {
            return ['ok' => false, 'http' => 400, 'error' => 'Field data bukan JSON.', 'raw' => $raw];
        }

        $link = (string)($d['bill_link_id'] ?? '');
        if (!self::validLinkId($link)) {
            return ['ok' => false, 'http' => 400, 'error' => 'bill_link_id tidak sah.', 'raw' => $raw];
        }

        // Cadangan pencocokan bila provider_ref tidak ditemukan: merchant_ref
        // yang disisipkan ke judul tagihan.
        $merchant = '';
        if (preg_match('/((?:LEG|DON|UJI)-[A-Z0-9-]+)/', (string)($d['bill_title'] ?? ''), $m)) {
            $merchant = $m[1];
        }

        return [
            'ok'           => true,
            'http'         => 200,
            'provider_ref' => $link,
            'merchant_ref' => (string)($d['reference_id'] ?? $merchant),
            'event'        => (string)($d['id'] ?? $link),
            'status_hint'  => (string)($d['status'] ?? ''),
            'raw'          => $raw,
            'error'        => null,
        ];
    }

    public function deactivate($provider_ref)
    {
        $ref = (string)$provider_ref;
        if (!self::validLinkId($ref) || !$this->isConfigured()) {
            return false;
        }
        $r = payment_http('PUT', $this->base() . '/v2/pwf/' . $ref . '/bill', $this->headers(), ['status' => 'INACTIVE']);
        return $r['status'] === 200;
    }

    public function testConnection()
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'Secret key dan validation token wajib diisi.'];
        }
        $r = payment_http('GET', $this->base() . '/v2/pwf/bill', $this->headers());
        if ($r['status'] === 200) {
            return ['ok' => true, 'message' => 'Terhubung. Secret key diterima Flip (' . ($this->isProduction() ? 'produksi' : 'sandbox') . ').'];
        }
        if ($r['status'] === 401) {
            return ['ok' => false, 'message' => 'Secret key DITOLAK Flip. Kunci sandbox dan produksi berbeda — pastikan sesuai mode.'];
        }
        return ['ok' => false, 'message' => 'Balasan tidak terduga dari Flip: ' . ($r['error'] ?: "HTTP {$r['status']}")];
    }

    public function frontendAction($txn)
    {
        if (!empty($txn->pay_url)) {
            return ['type' => 'redirect', 'url' => (string)$txn->pay_url];
        }
        return ['type' => 'none'];
    }
}
