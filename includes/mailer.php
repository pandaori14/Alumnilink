<?php
/**
 * Mailer Utility for AlumniLink
 * Centralized email sending: supports local simulation, SMTP (PHPMailer), and native mail() fallback.
 * 
 * Functions:
 *   - send_reset_password_email()  → forgot password flow
 *   - send_email_generic()         → reusable core sender
 *   - build_email_wrapper()        → premium HTML wrapper template
 *   - send_legalisir_status_email()→ status change notification
 *   - send_legalisir_softcopy_email() → soft copy ready
 *   - send_news_event_email()      → berita/event published
 *   - send_broadcast_email()       → admin broadcast
 *   - send_blast_email()           → email blast
 *   - check_email_preference()     → user opt-in check
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../includes/session_boot.php';
}

// ══════════════════════════════════════════════════════════════════════════════
// SECTION 1: Core Generic Email Sender
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Check if a user has opted in for email notifications.
 * Transactional emails (legalisir status) are always sent.
 * Marketing/broadcast emails respect this toggle.
 * 
 * @param string $user_id
 * @param bool $is_transactional If true, skip preference check (always send)
 * @return bool
 */
function check_email_preference($user_id, $is_transactional = false) {
    if ($is_transactional) return true;
    
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT email_notifications FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $val = $stmt->fetchColumn();
        return ($val === false) ? true : (bool)$val; // default ON if column missing
    } catch (Exception $e) {
        return true; // fail-open for non-critical preference
    }
}

/**
 * Generic email sender using PHPMailer SMTP with DB/env config.
 * Handles local simulation, SMTP sending, and native mail() fallback.
 *
 * @param string $to_email Recipient email
 * @param string $to_name  Recipient name (for personalization)
 * @param string $subject  Email subject
 * @param string $body_html Full HTML body
 * @return bool
 */
function send_email_generic($to_email, $to_name, $subject, $body_html) {
    global $pdo;
    $smtp_settings = [];
    if (isset($pdo)) {
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%' OR setting_key = 'smtp_force_real'");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $smtp_settings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Exception $e) {
            error_log("Failed to fetch SMTP settings: " . $e->getMessage());
        }
    }
    $smtp_force_real = ($smtp_settings['smtp_force_real'] ?? '0') === '1';

    $env = getenv('APP_ENV') ?: 'local';

    // Debug log for all environments
    $log_dir = dirname(__DIR__) . '/logs';
    if (!file_exists($log_dir)) {
        @mkdir($log_dir, 0777, true);
    }
    $log_entry = "[" . date('Y-m-d H:i:s') . "] TO: $to_email ($to_name) | SUBJECT: $subject\n----------------------------------------\n";
    @file_put_contents($log_dir . '/mail_debug.log', $log_entry, FILE_APPEND);

    if ($env === 'local' && !$smtp_force_real) {
        $_SESSION['dev_mail_sandbox'] = [
            'to' => $to_email,
            'subject' => $subject,
            'body' => substr(strip_tags($body_html), 0, 300),
            'timestamp' => time()
        ];
        return true;
    }

    // Production: PHPMailer SMTP
    try {

        $mail = new PHPMailer(true);
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->CharSet = 'UTF-8';

        $mail->Host     = !empty($smtp_settings['smtp_host']) ? $smtp_settings['smtp_host'] : (getenv('SMTP_HOST') ?: 'smtp.gmail.com');
        $mail->SMTPAuth = true;
        $mail->Username = !empty($smtp_settings['smtp_user']) ? $smtp_settings['smtp_user'] : (getenv('SMTP_USER') ?: '');
        $mail->Password = !empty($smtp_settings['smtp_pass']) ? $smtp_settings['smtp_pass'] : (getenv('SMTP_PASS') ?: '');

        $secure_val = !empty($smtp_settings['smtp_secure']) ? $smtp_settings['smtp_secure'] : (getenv('SMTP_SECURE') ?: 'tls');
        $secure = strtolower($secure_val);
        if ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $port_val = !empty($smtp_settings['smtp_port']) ? $smtp_settings['smtp_port'] : getenv('SMTP_PORT');
            $mail->Port = $port_val ? intval($port_val) : 465;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $port_val = !empty($smtp_settings['smtp_port']) ? $smtp_settings['smtp_port'] : getenv('SMTP_PORT');
            $mail->Port = $port_val ? intval($port_val) : 587;
        }

        $fromEmail = !empty($smtp_settings['smtp_from_email']) ? $smtp_settings['smtp_from_email'] : (getenv('SMTP_FROM_EMAIL') ?: ($mail->Username ?: 'no-reply@alumnilink.com'));
        $fromName  = !empty($smtp_settings['smtp_from_name'])  ? $smtp_settings['smtp_from_name']  : (getenv('SMTP_FROM_NAME') ?: (getenv('INSTITUTION_NAME') ?: 'AlumniLink'));
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to_email, $to_name);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body_html;
        $mail->AltBody = strip_tags($body_html);

        // Anti-Spam Headers
        $app_base_url = defined('BASE_URL') ? rtrim(BASE_URL, '/') : (isset($_SERVER['HTTP_HOST']) ? 'http://' . $_SERVER['HTTP_HOST'] . '/alumnilink' : 'http://localhost/alumnilink');
        $unsubscribe_url = $app_base_url . '/index.php?page=email_unsubscribe&e=' . urlencode($to_email) . '&t=' . sign_token($to_email, 'unsubscribe');
        $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribe_url . '>');
        $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        $mail->addCustomHeader('Precedence', 'list');
        $mail->addCustomHeader('X-Entity-Ref-ID', uniqid('al_', true));
        $mail->XMailer = 'AlumniLink Mailer';

        return $mail->send();
    } catch (Exception $e) {
        error_log("PHPMailer Send Failure [$to_email]: " . $e->getMessage() . ". Falling back to mail().");
        // Fallback
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8\r\n";
        $headers .= "From: AlumniLink <no-reply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ">\r\n";
        try {
            return @mail($to_email, $subject, $body_html, $headers);
        } catch (Exception $ex) {
            error_log("Fallback Mail Failure [$to_email]: " . $ex->getMessage());
            return false;
        }
    }
}


// ══════════════════════════════════════════════════════════════════════════════
// SECTION 2: HTML Email Template Builder
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Build a premium HTML email wrapper with consistent branding.
 *
 * @param string $title         Header title / greeting
 * @param string $body_content  Inner HTML content
 * @param string|null $cta_url  CTA button URL (optional)
 * @param string|null $cta_label CTA button text (optional)
 * @param string $accent_color  Accent color for header bar (hex)
 * @return string Full HTML email
 */
function build_email_wrapper($title, $body_content, $cta_url = null, $cta_label = null, $accent_color = '#2563eb') {
    $institution = getenv('INSTITUTION_NAME') ?: 'FK UMS';
    $year = date('Y');

    $cta_html = '';
    if ($cta_url && $cta_label) {
        $cta_html = '
        <div style="text-align:center;margin:30px 0;">
            <a href="' . htmlspecialchars($cta_url) . '" 
               style="display:inline-block;padding:14px 32px;background-color:' . $accent_color . ';color:#ffffff!important;text-decoration:none;border-radius:14px;font-weight:600;font-size:15px;box-shadow:0 4px 12px rgba(37,99,235,0.2);">
                ' . htmlspecialchars($cta_label) . '
            </a>
        </div>';
    }

    return '<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>' . htmlspecialchars($title) . '</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:\'Segoe UI\',\'Helvetica Neue\',Arial,sans-serif;color:#334155;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9;padding:40px 20px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:24px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.06);">
                    <!-- Header Bar -->
                    <tr>
                        <td style="background:linear-gradient(135deg,' . $accent_color . ',#1e40af);padding:28px 32px;text-align:center;">
                            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;letter-spacing:-0.5px;">' . htmlspecialchars($institution) . ' AlumniLink</h1>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding:36px 32px;">
                            <h2 style="margin:0 0 16px;font-size:20px;font-weight:700;color:#1e293b;">' . $title . '</h2>
                            <div style="line-height:1.7;font-size:15px;color:#475569;">
                                ' . $body_content . '
                            </div>
                            ' . $cta_html . '
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding:24px 32px;background-color:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;">
                            <p style="margin:0 0 8px;font-size:12px;color:#94a3b8;">
                                &copy; ' . $year . ' ' . htmlspecialchars($institution) . ' AlumniLink. All rights reserved.
                            </p>
                            <p style="margin:0;font-size:11px;color:#cbd5e1;">
                                Anda menerima email ini karena terdaftar sebagai alumni di sistem AlumniLink.<br>
                                Untuk mengatur preferensi email, kunjungi menu Profil di portal AlumniLink.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
}


// ══════════════════════════════════════════════════════════════════════════════
// SECTION 3: Specific Email Senders
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Send password reset email (existing functionality, now uses generic sender).
 */
function send_reset_password_email($email, $reset_link) {
    $subject = "Atur Ulang Kata Sandi - " . (getenv('APP_NAME') ?: 'AlumniLink');

    $body = '
        <p>Halo,</p>
        <p>Kami menerima permintaan untuk mengatur ulang kata sandi akun AlumniLink Anda. Silakan klik tombol di bawah ini untuk melanjutkan:</p>
        <p>Atau salin tautan berikut ke peramban Anda:</p>
        <p style="word-break:break-all;font-size:13px;"><a href="' . htmlspecialchars($reset_link) . '" style="color:#2563eb;">' . htmlspecialchars($reset_link) . '</a></p>
        <div style="background-color:#fffbeb;border-left:4px solid #f59e0b;padding:14px;border-radius:12px;margin-top:16px;font-size:13px;color:#b45309;">
            Tautan ini hanya berlaku selama <strong>1 jam</strong>. Jika Anda tidak meminta pengaturan ulang ini, abaikan email ini dengan aman.
        </div>';

    $html = build_email_wrapper('Atur Ulang Kata Sandi', $body, $reset_link, 'Atur Ulang Kata Sandi');
    return send_email_generic($email, '', $subject, $html);
}


/**
 * Send legalisir status update email.
 *
 * @param string $to_email
 * @param string $to_name
 * @param string $order_id
 * @param string $status   processing|completed|rejected
 * @param string $doc_desc Document description
 */
/**
 * @param string $rejection_reason Parameter opsional di posisi TERAKHIR,
 *        supaya seluruh pemanggil lama tetap berjalan tanpa diubah.
 */
function send_legalisir_status_email($to_email, $to_name, $order_id, $status, $doc_desc, $rejection_reason = '') {
    $status_map = [
        'processing' => ['label' => 'Sedang Diproses', 'color' => '#2563eb', 'icon' => '⚙️'],
        'completed'  => ['label' => 'Selesai & Terverifikasi', 'color' => '#16a34a', 'icon' => '✅'],
        'rejected'   => ['label' => 'Ditolak', 'color' => '#dc2626', 'icon' => '❌'],
    ];

    $info = $status_map[$status] ?? ['label' => ucfirst($status), 'color' => '#6b7280', 'icon' => '📋'];
    $subject = $info['icon'] . " Legalisir $order_id — " . $info['label'];
    $detail_url = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://localhost/alumnilink') . '/index.php?page=legalisir_detail&id=' . urlencode($order_id);

    $body = '
        <p>Halo <strong>' . htmlspecialchars($to_name) . '</strong>,</p>
        <p>Status pengajuan legalisir Anda telah diperbarui:</p>
        <table role="presentation" width="100%" style="margin:20px 0;border-collapse:collapse;">
            <tr>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:8px 0 0 8px;font-weight:600;font-size:13px;color:#64748b;width:140px;">ID Pengajuan</td>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:0 8px 8px 0;font-size:14px;">' . htmlspecialchars($order_id) . '</td>
            </tr>
            <tr><td colspan="2" style="height:6px;"></td></tr>
            <tr>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:8px 0 0 8px;font-weight:600;font-size:13px;color:#64748b;">Dokumen</td>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:0 8px 8px 0;font-size:14px;">' . htmlspecialchars($doc_desc) . '</td>
            </tr>
            <tr><td colspan="2" style="height:6px;"></td></tr>
            <tr>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:8px 0 0 8px;font-weight:600;font-size:13px;color:#64748b;">Status</td>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:0 8px 8px 0;">
                    <span style="display:inline-block;padding:5px 14px;background:' . $info['color'] . ';color:#fff;border-radius:20px;font-size:13px;font-weight:600;">' . $info['icon'] . ' ' . $info['label'] . '</span>
                </td>
            </tr>
        </table>';

    if ($status === 'rejected') {
        // Alasan yang ditulis admin disertakan bila ada. Tanpa ini alumni
        // hanya diberi tahu bahwa pengajuannya ditolak, tanpa pernah tahu
        // apa yang harus diperbaiki sebelum mengajukan ulang.
        $alasan = trim((string)$rejection_reason);
        $body .= '<div style="background:#fef2f2;border-left:4px solid #dc2626;padding:14px;border-radius:12px;font-size:13px;color:#991b1b;margin-top:12px;">';
        if ($alasan !== '') {
            $body .= '<strong>Alasan penolakan:</strong><br>' . nl2br(htmlspecialchars($alasan)) . '<br><br>';
        }
        $body .= 'Jika Anda merasa keputusan ini tidak tepat, silakan hubungi admin melalui portal AlumniLink untuk informasi lebih lanjut.
        </div>';
    }

    $cta_label = ($status === 'completed') ? 'Lihat Hasil & Unduh' : 'Lihat Detail Pengajuan';
    $html = build_email_wrapper('Status Legalisir Diperbarui', $body, $detail_url, $cta_label, $info['color']);
    return send_email_generic($to_email, $to_name, $subject, $html);
}


/**
 * Send legalisir soft copy ready email.
 */
function send_legalisir_softcopy_email($to_email, $to_name, $order_id, $verify_url) {
    $subject = "🎓 Soft Copy Legalisir $order_id Siap Diunduh";
    $detail_url = $verify_url;

    $body = '
        <p>Halo <strong>' . htmlspecialchars($to_name) . '</strong>,</p>
        <p>Kabar baik! Dokumen legalisir Anda dengan ID <strong>' . htmlspecialchars($order_id) . '</strong> telah selesai diverifikasi dan soft copy-nya siap untuk diunduh melalui portal AlumniLink.</p>
        <div style="background:#f0fdf4;border-left:4px solid #16a34a;padding:14px;border-radius:12px;font-size:14px;color:#166534;margin:16px 0;">
            <strong>✅ Dokumen telah terverifikasi secara resmi.</strong><br>
            Anda dapat mengunduh dan mencetak soft copy kapan saja melalui halaman detail pengajuan.
        </div>';

    $html = build_email_wrapper('Soft Copy Legalisir Siap', $body, $detail_url, 'Unduh Soft Copy Sekarang', '#16a34a');
    return send_email_generic($to_email, $to_name, $subject, $html);
}


/**
 * Send news/event notification email.
 */
function send_news_event_email($to_email, $to_name, $news_title, $type, $excerpt, $news_url) {
    $type_labels = [
        'berita'   => ['emoji' => '📰', 'label' => 'Berita Terbaru'],
        'event'    => ['emoji' => '🎉', 'label' => 'Event & Kegiatan Baru'],
        'kegiatan' => ['emoji' => '🎉', 'label' => 'Kegiatan Baru'],
    ];
    $info = $type_labels[$type] ?? ['emoji' => '📋', 'label' => 'Informasi Baru'];
    $subject = $info['emoji'] . ' ' . $info['label'] . ': ' . $news_title;

    $body = '
        <p>Halo <strong>' . htmlspecialchars($to_name) . '</strong>,</p>
        <p>Ada ' . strtolower($info['label']) . ' yang mungkin menarik untuk Anda:</p>
        <div style="background:#f8fafc;border-radius:16px;padding:20px;margin:16px 0;border:1px solid #e2e8f0;">
            <h3 style="margin:0 0 8px;font-size:17px;color:#1e293b;">' . $info['emoji'] . ' ' . htmlspecialchars($news_title) . '</h3>
            <p style="margin:0;font-size:14px;color:#64748b;line-height:1.6;">' . htmlspecialchars($excerpt) . '...</p>
        </div>';

    $html = build_email_wrapper($info['label'], $body, $news_url, 'Baca Selengkapnya', '#2563eb');
    return send_email_generic($to_email, $to_name, $subject, $html);
}


/**
 * Send broadcast email from admin.
 */
function send_broadcast_email($to_email, $to_name, $broadcast_title, $broadcast_message) {
    $subject = "📢 " . $broadcast_title;

    $body = '
        <p>Halo <strong>' . htmlspecialchars($to_name) . '</strong>,</p>
        <div style="background:#f8fafc;border-radius:16px;padding:20px;margin:16px 0;border:1px solid #e2e8f0;">
            <h3 style="margin:0 0 12px;font-size:17px;color:#1e293b;">📢 ' . htmlspecialchars($broadcast_title) . '</h3>
            <div style="font-size:14px;color:#475569;line-height:1.7;">' . nl2br(htmlspecialchars($broadcast_message)) . '</div>
        </div>';

    $portal_url = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://localhost/alumnilink') . '/index.php?page=dashboard';
    $html = build_email_wrapper('Pengumuman', $body, $portal_url, 'Buka Portal AlumniLink', '#7c3aed');
    return send_email_generic($to_email, $to_name, $subject, $html);
}


/**
 * Send email blast (admin-triggered mass email).
 */
function send_blast_email($to_email, $to_name, $subject, $html_content) {
    $body = '
        <p>Halo <strong>' . htmlspecialchars($to_name) . '</strong>,</p>
        <div style="font-size:14px;color:#475569;line-height:1.7;margin:16px 0;">' . $html_content . '</div>';

    $portal_url = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://localhost/alumnilink') . '/index.php?page=dashboard';
    $html = build_email_wrapper($subject, $body, $portal_url, 'Kunjungi Portal', '#0f766e');
    return send_email_generic($to_email, $to_name, $subject, $html);
}


/**
 * Send payment success email for legalisir.
 */
function send_legalisir_payment_email($to_email, $to_name, $order_id, $amount, $payment_type) {
    $subject = "💳 Pembayaran Legalisir $order_id Berhasil";
    $detail_url = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://localhost/alumnilink') . '/index.php?page=legalisir_detail&id=' . urlencode($order_id);

    $body = '
        <p>Halo <strong>' . htmlspecialchars($to_name) . '</strong>,</p>
        <p>Pembayaran untuk pengajuan legalisir Anda telah berhasil dikonfirmasi.</p>
        <table role="presentation" width="100%" style="margin:20px 0;border-collapse:collapse;">
            <tr>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:8px 0 0 8px;font-weight:600;font-size:13px;color:#64748b;width:140px;">ID Pengajuan</td>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:0 8px 8px 0;font-size:14px;">' . htmlspecialchars($order_id) . '</td>
            </tr>
            <tr><td colspan="2" style="height:6px;"></td></tr>
            <tr>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:8px 0 0 8px;font-weight:600;font-size:13px;color:#64748b;">Jumlah</td>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:0 8px 8px 0;font-size:14px;font-weight:600;color:#16a34a;">Rp ' . number_format($amount, 0, ',', '.') . '</td>
            </tr>
            <tr><td colspan="2" style="height:6px;"></td></tr>
            <tr>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:8px 0 0 8px;font-weight:600;font-size:13px;color:#64748b;">Metode</td>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:0 8px 8px 0;font-size:14px;">' . htmlspecialchars($payment_type) . '</td>
            </tr>
        </table>
        <div style="background:#f0fdf4;border-left:4px solid #16a34a;padding:14px;border-radius:12px;font-size:13px;color:#166534;">
            Dokumen Anda sekarang sedang diproses oleh admin. Anda akan menerima notifikasi saat dokumen selesai.
        </div>';

    $html = build_email_wrapper('Pembayaran Berhasil', $body, $detail_url, 'Lihat Detail Pengajuan', '#16a34a');
    return send_email_generic($to_email, $to_name, $subject, $html);
}

/**
 * Send initial invoice email for legalisir (pending payment).
 */
function send_invoice_email($to_email, $to_name, $order_id, $amount, $docs_desc, $payment_method) {
    $subject = "🧾 Tagihan Legalisir $order_id";
    $detail_url = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://localhost/alumnilink') . '/index.php?page=legalisir_detail&id=' . urlencode($order_id);

    $body = '
        <p>Halo <strong>' . htmlspecialchars($to_name) . '</strong>,</p>
        <p>Terima kasih telah melakukan pengajuan legalisir. Berikut adalah detail tagihan Anda:</p>
        <table role="presentation" width="100%" style="margin:20px 0;border-collapse:collapse;">
            <tr>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:8px 0 0 8px;font-weight:600;font-size:13px;color:#64748b;width:140px;">ID Pengajuan</td>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:0 8px 8px 0;font-size:14px;">' . htmlspecialchars($order_id) . '</td>
            </tr>
            <tr><td colspan="2" style="height:6px;"></td></tr>
            <tr>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:8px 0 0 8px;font-weight:600;font-size:13px;color:#64748b;">Dokumen</td>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:0 8px 8px 0;font-size:14px;">' . htmlspecialchars($docs_desc) . '</td>
            </tr>
            <tr><td colspan="2" style="height:6px;"></td></tr>
            <tr>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:8px 0 0 8px;font-weight:600;font-size:13px;color:#64748b;">Metode Bayar</td>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:0 8px 8px 0;font-size:14px;">' . htmlspecialchars($payment_method) . '</td>
            </tr>
            <tr><td colspan="2" style="height:6px;"></td></tr>
            <tr>
                <td style="padding:10px 16px;background:#fff1f2;border-radius:8px 0 0 8px;font-weight:600;font-size:13px;color:#e11d48;">Total Tagihan</td>
                <td style="padding:10px 16px;background:#fff1f2;border-radius:0 8px 8px 0;font-size:16px;font-weight:800;color:#e11d48;">Rp ' . number_format($amount, 0, ',', '.') . '</td>
            </tr>
        </table>
        <div style="background:#eff6ff;border-left:4px solid #3b82f6;padding:14px;border-radius:12px;font-size:13px;color:#1e40af;">
            Silakan klik tombol di bawah ini untuk melihat detail lengkap dan menyelesaikan pembayaran Anda.
        </div>';

    $html = build_email_wrapper('Tagihan Menunggu Pembayaran', $body, $detail_url, 'Selesaikan Pembayaran', '#f59e0b');
    return send_email_generic($to_email, $to_name, $subject, $html);
}

/**
 * Send donation invoice (pending payment).
 */
function send_donation_invoice($to_email, $to_name, $order_id, $amount, $campaign_title, $payment_method) {
    $subject = "❤️ Instruksi Pembayaran Donasi $order_id";
    $detail_url = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://localhost/alumnilink') . '/index.php?page=donasi';

    $body = '
        <p>Halo <strong>' . htmlspecialchars($to_name) . '</strong>,</p>
        <p>Terima kasih atas niat baik Anda untuk berdonasi pada program <strong>"' . htmlspecialchars($campaign_title) . '"</strong>.</p>
        <p>Silakan selesaikan pembayaran donasi Anda dengan detail berikut:</p>
        <table role="presentation" width="100%" style="margin:20px 0;border-collapse:collapse;">
            <tr>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:8px 0 0 8px;font-weight:600;font-size:13px;color:#64748b;width:140px;">ID Donasi</td>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:0 8px 8px 0;font-size:14px;">' . htmlspecialchars($order_id) . '</td>
            </tr>
            <tr><td colspan="2" style="height:6px;"></td></tr>
            <tr>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:8px 0 0 8px;font-weight:600;font-size:13px;color:#64748b;">Metode Bayar</td>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:0 8px 8px 0;font-size:14px;">' . htmlspecialchars($payment_method) . '</td>
            </tr>
            <tr><td colspan="2" style="height:6px;"></td></tr>
            <tr>
                <td style="padding:10px 16px;background:#fff1f2;border-radius:8px 0 0 8px;font-weight:600;font-size:13px;color:#e11d48;">Nominal Donasi</td>
                <td style="padding:10px 16px;background:#fff1f2;border-radius:0 8px 8px 0;font-size:16px;font-weight:800;color:#e11d48;">Rp ' . number_format($amount, 0, ',', '.') . '</td>
            </tr>
        </table>
        <div style="background:#eff6ff;border-left:4px solid #3b82f6;padding:14px;border-radius:12px;font-size:13px;color:#1e40af;">
            Kebaikan Anda akan sangat berarti bagi kemajuan institusi dan sesama alumni.
        </div>';

    $html = build_email_wrapper('Menunggu Pembayaran Donasi', $body, $detail_url, 'Selesaikan Pembayaran', '#e11d48');
    return send_email_generic($to_email, $to_name, $subject, $html);
}

/**
 * Send donation receipt (payment success).
 */
function send_donation_receipt($to_email, $to_name, $order_id, $amount, $campaign_title) {
    $subject = "🎉 Tanda Terima Donasi $order_id";
    $detail_url = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://localhost/alumnilink') . '/index.php?page=donasi';

    $body = '
        <p>Halo <strong>' . htmlspecialchars($to_name) . '</strong>,</p>
        <p>Kami telah menerima donasi Anda untuk program <strong>"' . htmlspecialchars($campaign_title) . '"</strong>.</p>
        <table role="presentation" width="100%" style="margin:20px 0;border-collapse:collapse;">
            <tr>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:8px 0 0 8px;font-weight:600;font-size:13px;color:#64748b;width:140px;">ID Tanda Terima</td>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:0 8px 8px 0;font-size:14px;">' . htmlspecialchars($order_id) . '</td>
            </tr>
            <tr><td colspan="2" style="height:6px;"></td></tr>
            <tr>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:8px 0 0 8px;font-weight:600;font-size:13px;color:#64748b;">Nominal</td>
                <td style="padding:10px 16px;background:#f8fafc;border-radius:0 8px 8px 0;font-size:14px;font-weight:600;color:#16a34a;">Rp ' . number_format($amount, 0, ',', '.') . '</td>
            </tr>
        </table>
        <div style="background:#f0fdf4;border-left:4px solid #16a34a;padding:14px;border-radius:12px;font-size:13px;color:#166534;">
            Terima kasih yang sebesar-besarnya atas partisipasi dan kepedulian Anda. Donasi Anda telah tercatat dengan sukses.
        </div>';

    $html = build_email_wrapper('Donasi Berhasil', $body, $detail_url, 'Lihat Donasi Saya', '#16a34a');
    return send_email_generic($to_email, $to_name, $subject, $html);
}

/**
 * Send thank you email after Tracer Study completion.
 */
function send_tracer_thankyou_email($to_email, $to_name) {
    $subject = "🎓 Terima Kasih Telah Mengisi Tracer Study";
    $detail_url = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://localhost/alumnilink') . '/index.php?page=dashboard';

    $body = '
        <p>Halo <strong>' . htmlspecialchars($to_name) . '</strong>,</p>
        <p>Terima kasih banyak atas waktu dan partisipasi Anda dalam melengkapi data <strong>Tracer Study</strong>.</p>
        <p>Data dan *feedback* yang Anda berikan sangat berharga bagi institusi untuk terus meningkatkan kualitas pendidikan dan lulusan di masa depan.</p>
        <div style="background:#f0fdf4;border-left:4px solid #16a34a;padding:14px;border-radius:12px;font-size:13px;color:#166534;margin-top:20px;">
            Sistem telah memperbarui status Anda. Anda dapat kembali beraktivitas di portal AlumniLink.
        </div>';

    $html = build_email_wrapper('Tracer Study Selesai', $body, $detail_url, 'Kembali ke Dashboard', '#0f766e');
    return send_email_generic($to_email, $to_name, $subject, $html);
}

/**
 * Send auto-reminder for Tracer Study update.
 */
function send_tracer_reminder_email($to_email, $to_name, $last_update = null) {
    $subject = "🔔 Pengingat Pembaruan Data Tracer Study";
    $detail_url = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://localhost/alumnilink') . '/index.php?page=tracer';

    $last_update_text = $last_update ? "pada tanggal " . date('d F Y', strtotime($last_update)) : "sejak kelulusan Anda";

    $body = '
        <p>Halo <strong>' . htmlspecialchars($to_name) . '</strong>,</p>
        <p>Semoga Anda senantiasa dalam keadaan sehat dan sukses selalu.</p>
        <p>Sistem kami mencatat bahwa Anda belum memperbarui data karir / aktivitas Anda <strong>' . $last_update_text . '</strong>.</p>
        <p>Untuk memastikan data alumni selalu _up-to-date_ dan membantu akreditasi kampus, kami mohon kesediaan Anda untuk meluangkan waktu sejenak guna memperbarui data Tracer Study Anda saat ini.</p>
        <div style="background:#fff7ed;border-left:4px solid #f97316;padding:14px;border-radius:12px;font-size:13px;color:#9a3412;margin-top:20px;">
            Data Anda sangat penting bagi pengembangan kurikulum dan almamater.
        </div>';

    $html = build_email_wrapper('Pembaruan Tracer Study', $body, $detail_url, 'Perbarui Data Sekarang', '#f97316');
    return send_email_generic($to_email, $to_name, $subject, $html);
}

// ══════════════════════════════════════════════════════════════════════════════
// SECTION 6: HTML Email with Attachments (for queue worker)
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Send a fully-formed HTML email with optional file attachments.
 * Used by cron/process_email_queue.php to process queued broadcast emails.
 *
 * @param string $to_email     Recipient email address
 * @param string $to_name      Recipient display name
 * @param string $subject      Email subject
 * @param string $body_html    Full HTML email body (already envelope-wrapped)
 * @param array  $attachments  [['path' => '/path/to/file', 'name' => 'filename.pdf'], ...]
 * @return bool True on success
 */
function send_html_email(string $to_email, string $to_name, string $subject, string $body_html, array $attachments = []): bool {
    global $pdo;
    $smtp_settings = [];
    if (isset($pdo)) {
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%' OR setting_key = 'smtp_force_real'");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $smtp_settings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Exception $e) {}
    }
    $smtp_force_real = ($smtp_settings['smtp_force_real'] ?? '0') === '1';

    $env = getenv('APP_ENV') ?: 'local';

    // Dev/sandbox: log and return true
    if ($env === 'local' && !$smtp_force_real) {
        $log_dir = dirname(__DIR__) . '/logs';
        if (!file_exists($log_dir)) @mkdir($log_dir, 0777, true);
        @file_put_contents(
            $log_dir . '/mail_debug.log',
            "[" . date('Y-m-d H:i:s') . "] [QUEUE] TO: $to_email | SUBJ: $subject | Attachments: " . count($attachments) . "\n",
            FILE_APPEND
        );
        return true;
    }

    // Production: PHPMailer with attachments
    try {

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->CharSet     = 'UTF-8';
        $mail->SMTPDebug   = 0;
        $mail->Host        = $smtp_settings['smtp_host']       ?? getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $mail->Port        = (int)($smtp_settings['smtp_port'] ?? getenv('SMTP_PORT') ?: 587);
        $mail->SMTPAuth    = true;
        $mail->Username    = $smtp_settings['smtp_user']       ?? getenv('SMTP_USER') ?: '';
        $mail->Password    = $smtp_settings['smtp_pass']       ?? getenv('SMTP_PASS') ?: '';
        $mail->SMTPSecure  = ($mail->Port === 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;

        $from_email = $smtp_settings['smtp_from_email'] ?? getenv('SMTP_FROM_EMAIL') ?: $mail->Username;
        $from_name  = $smtp_settings['smtp_from_name']  ?? getenv('SMTP_FROM_NAME')  ?: 'AlumniLink';

        $mail->setFrom($from_email, $from_name);
        $mail->addAddress($to_email, $to_name ?: 'Alumni');
        $mail->addReplyTo($from_email, $from_name);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body_html;
        $mail->AltBody = strip_tags($body_html);

        // Anti-Spam Headers
        $app_base_url = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://localhost/alumnilink';
        $unsubscribe_url = $app_base_url . '/index.php?page=email_unsubscribe&e=' . urlencode($to_email) . '&t=' . sign_token($to_email, 'unsubscribe');
        $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribe_url . '>');
        $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        $mail->addCustomHeader('Precedence', 'list');
        $mail->addCustomHeader('X-Entity-Ref-ID', uniqid('al_', true));
        $mail->XMailer = 'AlumniLink Mailer';

        // Add file attachments
        foreach ($attachments as $att) {
            if (!empty($att['path']) && file_exists($att['path'])) {
                $mail->addAttachment($att['path'], $att['name'] ?? basename($att['path']));
            }
        }

        return $mail->send();

    } catch (Exception $e) {
        error_log("send_html_email FAIL [$to_email]: " . $e->getMessage());
        return false;
    }
}

/**
 * Undangan Survei Kepuasan Pengguna Lulusan kepada atasan alumni.
 *
 * Penerima BUKAN pengguna sistem, sehingga e-mail memuat tautan bertoken
 * beserta penjelasan singkat mengapa mereka menerimanya.
 */
function send_employer_survey_invitation($to_email, $to_name, $alumni_name, $link, $berlaku_hari)
{
    $institusi = function_exists('setting') ? setting('system_name', 'AlumniLink') : 'AlumniLink';

    $subject = 'Permohonan Penilaian Lulusan - ' . $institusi;

    $isi = '
        <p style="margin:0 0 16px">Yth. ' . htmlspecialchars($to_name) . ',</p>
        <p style="margin:0 0 16px;line-height:1.7">
            Dalam rangka evaluasi mutu lulusan, kami memohon kesediaan Bapak/Ibu untuk
            memberikan penilaian atas kompetensi lulusan kami yang bekerja di bawah
            supervisi Bapak/Ibu:
        </p>
        <p style="margin:0 0 20px;padding:14px 18px;background:#f1f5f9;border-radius:10px;font-weight:700">'
            . htmlspecialchars($alumni_name) . '</p>
        <p style="margin:0 0 24px;line-height:1.7">
            Pengisian hanya memerlukan waktu sekitar 2 menit. Nama Bapak/Ibu tidak
            dipublikasikan; hasil digunakan semata-mata untuk perbaikan mutu pendidikan.
        </p>
        <p style="margin:0 0 24px;text-align:center">
            <a href="' . htmlspecialchars($link) . '"
               style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;
                      padding:14px 28px;border-radius:10px;font-weight:700">
                Isi Penilaian
            </a>
        </p>
        <p style="margin:0 0 8px;font-size:12px;color:#64748b;line-height:1.6">
            Tautan berlaku ' . (int)$berlaku_hari . ' hari dan hanya dapat digunakan satu kali.
            Bila tombol tidak berfungsi, salin alamat berikut ke peramban Anda:<br>
            <span style="word-break:break-all">' . htmlspecialchars($link) . '</span>
        </p>';

    $html = function_exists('build_email_html')
        ? build_email_html('Permohonan Penilaian Lulusan', $isi)
        : $isi;

    return send_html_email($to_email, $to_name, $subject, $html);
}
