<?php
/**
 * Transport HTTP tunggal untuk seluruh panggilan ke gateway pembayaran.
 *
 * ── Mengapa satu fungsi ────────────────────────────────────────────────
 * Sebelumnya ada tiga salinan curl yang nyaris sama (legalisir_handler,
 * donation_handler, regenerate_payment), semuanya:
 *
 *   - tanpa CURLOPT_TIMEOUT, sehingga gateway yang lambat membuat permintaan
 *     alumni menggantung sampai batas PHP;
 *   - menulis galat mentah ke ../midtrans_error.log di ROOT WEB.
 *
 * Satu fungsi memberi satu tempat untuk timeout, pencatatan, dan — yang
 * paling penting — satu titik yang dapat DIGANTI saat pengujian:
 *
 *     $GLOBALS['payment_http_override'] = function ($method, $url, $headers, $body) {
 *         return ['status' => 200, 'body' => '{"..."}'];
 *     };
 *
 * Dengan begitu uji tidak pernah memanggil Midtrans atau Flip sungguhan.
 */

/**
 * @param string            $method  GET | POST | PUT
 * @param string            $url
 * @param array             $headers daftar "Nama: nilai"
 * @param string|array|null $body    string mentah, atau array untuk form-urlencoded
 * @return array{status:int, body:string, json:mixed, error:string}
 */
function payment_http($method, $url, array $headers = [], $body = null)
{
    if (is_array($body)) {
        $body = http_build_query($body);
    }

    $override = $GLOBALS['payment_http_override'] ?? null;
    if (is_callable($override)) {
        $r = $override($method, $url, $headers, $body);
        $status = (int)($r['status'] ?? 0);
        $raw    = (string)($r['body'] ?? '');
        return [
            'status' => $status,
            'body'   => $raw,
            'json'   => json_decode($raw, true),
            'error'  => (string)($r['error'] ?? ''),
        ];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($body !== null && strtoupper($method) !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw    = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error  = $raw === false ? curl_error($ch) : '';
    curl_close($ch);

    if ($raw === false || $status >= 500) {
        // Alamat dicatat, header TIDAK: header memuat kunci rahasia gateway.
        error_log(sprintf('Pembayaran HTTP %s %s -> %d %s',
            strtoupper($method), payment_http_redact_url($url), $status, $error));
    }

    $raw = $raw === false ? '' : (string)$raw;
    return [
        'status' => $status,
        'body'   => $raw,
        'json'   => json_decode($raw, true),
        'error'  => $error,
    ];
}

/** Buang query string dari URL sebelum dicatat. */
function payment_http_redact_url($url)
{
    $q = strpos($url, '?');
    return $q === false ? $url : substr($url, 0, $q) . '?…';
}

/** Header Authorization Basic dengan kunci sebagai username, sandi kosong. */
function payment_basic_auth($secret)
{
    return 'Authorization: Basic ' . base64_encode($secret . ':');
}
