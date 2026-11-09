<?php
// includes/mailer.php - SMTP email delivery using PHPMailer.

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';
require_once __DIR__ . '/db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Sends an email via SMTP using configuration from get_config().
 *
 * @param string $to Recipient email address
 * @param string $subject Email subject line
 * @param string $html HTML email body
 * @param string $text Optional plain text body. If empty, auto-generated from HTML via strip_tags().
 * @return bool True if sent successfully, false on any error.
 */
function send_mail(string $to, string $subject, string $html, string $text = ''): bool {
    try {
        $cfg = get_config();

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = (string)($cfg['smtp_host'] ?? 'smtp.gmail.com');
        $mail->Port       = (int)($cfg['smtp_port'] ?? 587);
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAuth   = true;
        $mail->Username   = (string)($cfg['smtp_user'] ?? '');
        $mail->Password   = (string)($cfg['smtp_pass'] ?? '');

        $fromEmail = (string)($cfg['smtp_from'] ?? $cfg['smtp_user'] ?? '');
        $fromName  = (string)($cfg['smtp_from_name'] ?? 'Kỷ yếu lớp Pháp LTT');
        $mail->setFrom($fromEmail, $fromName);

        $mail->addAddress($to);
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 15;

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;

        if ($text === '') {
            $mail->AltBody = trim(strip_tags($html));
        } else {
            $mail->AltBody = $text;
        }

        return $mail->send();
    } catch (\Throwable $e) {
        error_log('[mailer] Send failed to ' . $to . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Sends a 6-digit OTP verification email with bilingual French/Vietnamese text.
 *
 * @param string $to Recipient email address
 * @param string $code 6-digit numeric OTP code
 * @return bool True on success, false on failure
 */
function send_otp_email(string $to, string $code): bool {
    $subject = "[Kỷ yếu lớp Pháp LTT] Mã xác thực của bạn: {$code}";
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

    $html = <<<HTML
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #222; margin: 0; padding: 20px; background-color: #f7f7f7;">
  <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 32px; border: 1px solid #e0e0e0;">
    <h2 style="color: #80182a; margin-top: 0; font-size: 20px;">Kỷ yếu lớp Pháp ngữ THPT Chuyên Lý Tự Trọng</h2>
    <p style="margin-bottom: 8px;">Xin chào bạn / <em>Bonjour</em>,</p>
    <p style="margin-top: 0;">Bạn (hoặc ai đó) vừa yêu cầu mã xác thực để gửi hoặc cập nhật hồ sơ kỷ yếu.<br>
    <span style="color: #666; font-size: 14px;"><em>Vous avez demandé un code de vérification pour ajouter ou modifier votre fiche d'annuaire.</em></span></p>
    <div style="text-align: center; margin: 28px 0;">
      <span style="display: inline-block; font-size: 32px; font-weight: bold; letter-spacing: 6px; padding: 12px 28px; background: #fbf2f3; border: 2px dashed #80182a; color: #80182a; border-radius: 6px;">{$safeCode}</span>
    </div>
    <p style="margin-bottom: 8px;">Mã này có hiệu lực trong vòng <strong>10 phút</strong>. Vui lòng không chia sẻ mã cho bất kỳ ai.<br>
    <span style="color: #666; font-size: 14px;"><em>Ce code est valable pendant <strong>10 minutes</strong>. Merci de ne le partager avec personne.</em></span></p>
    <p style="margin-top: 16px; font-size: 13px; color: #888;">Nếu bạn không thực hiện yêu cầu này, vui lòng bỏ qua email.<br>
    <em>Si vous n'êtes pas à l'origine de cette demande, veuillez ignorer ce message.</em></p>
    <hr style="border: none; border-top: 1px solid #eee; margin: 24px 0 16px;">
    <p style="margin: 0; font-size: 12px; color: #999; text-align: center;">Annuaire des Anciens Élèves du Français &bull; THPT Chuyên Lý Tự Trọng Cần Thơ</p>
  </div>
</body>
</html>
HTML;

    $text = "Xin chào bạn / Bonjour,\n\n"
        . "Mã xác thực của bạn là: {$code}\n"
        . "(Votre code de vérification est : {$code})\n\n"
        . "Mã này có hiệu lực trong vòng 10 phút.\n"
        . "(Ce code est valable pendant 10 minutes.)\n\n"
        . "Nếu bạn không thực hiện yêu cầu này, vui lòng bỏ qua email.\n"
        . "(Si vous n'êtes pas à l'origine de cette demande, veuillez ignorer cet e-mail.)\n\n"
        . "Kỷ yếu lớp Pháp ngữ THPT Chuyên Lý Tự Trọng Cần Thơ";

    return send_mail($to, $subject, $html, $text);
}

/**
 * Sends an email notification to the administrator (configured in config.php as admin_email).
 * Never throws exceptions; returns false if admin_email is not configured or delivery fails.
 *
 * @param string $subject Subject line
 * @param string $html HTML content of the notification
 * @return bool True on success, false if skipped or failed
 */
function send_admin_notification(string $subject, string $html): bool {
    try {
        $cfg = get_config();
        $adminEmail = trim((string)($cfg['admin_email'] ?? ''));
        if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        return send_mail($adminEmail, $subject, $html);
    } catch (\Throwable $e) {
        error_log('[mailer] send_admin_notification error: ' . $e->getMessage());
        return false;
    }
}
