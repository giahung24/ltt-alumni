<?php
// ===== DELETE AFTER USE =====  Throwaway diagnostic, no auth gate. Delete from the server right after running.
// Upload as htdocs/spike_check.php; includes/ must be its sibling (paths below are as on the server).
// Add ?mail=1 to send one test email to admin_email.
$cfg = require __DIR__ . '/includes/config.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');
$gd = function_exists('gd_info') ? gd_info() : [];
$oc = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
$rows = [
    'PHP version' => PHP_VERSION,
    'memory_limit' => ini_get('memory_limit'),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'GD JPEG' => var_export($gd['JPEG Support'] ?? false, true),
    'GD PNG' => var_export($gd['PNG Support'] ?? false, true),
    'GD WebP' => var_export($gd['WebP Support'] ?? false, true),
    'exif extension' => var_export(extension_loaded('exif'), true),
    'opcache enabled' => $oc ? var_export($oc['opcache_enabled'] ?? false, true) : 'n/a',
    'opcache.revalidate_freq' => var_export(ini_get('opcache.revalidate_freq'), true),
    'opcache.validate_timestamps' => var_export(ini_get('opcache.validate_timestamps'), true),
    'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? '-',
    'HTTP_X_FORWARDED_FOR' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '-',
    'HTTPS' => $_SERVER['HTTPS'] ?? '-',
    'HTTP_X_FORWARDED_PROTO' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '-',
    'PHP timezone' => date_default_timezone_get(),
    'PHP date' => date('Y-m-d H:i:s'),
];
try {
    $pdo = new PDO("mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4", $cfg['db_user'], $cfg['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET time_zone = '+07:00'");
    $r = $pdo->query('SELECT VERSION() v, NOW() n')->fetch(PDO::FETCH_ASSOC);
    $rows['MySQL VERSION()'] = $r['v'];
    $rows['MySQL NOW() (+07:00)'] = $r['n'];
} catch (Throwable $e) {
    $rows['MySQL error'] = $e->getMessage();
}
if (isset($_GET['mail'])) {
    require __DIR__ . '/includes/PHPMailer/Exception.php';
    require __DIR__ . '/includes/PHPMailer/PHPMailer.php';
    require __DIR__ . '/includes/PHPMailer/SMTP.php';
    $m = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $m->isSMTP();
        $m->Host = $cfg['smtp_host'];
        $m->Port = $cfg['smtp_port'];
        $m->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $m->SMTPAuth = true;
        $m->Username = $cfg['smtp_user'];
        $m->Password = $cfg['smtp_pass'];
        $m->CharSet = 'UTF-8';
        $m->Timeout = 15;
        $m->setFrom($cfg['smtp_from'], $cfg['smtp_from_name']);
        $m->addAddress($cfg['admin_email']);
        $m->Subject = '[Kỷ yếu lớp Pháp LTT] Thử gửi mail';
        $m->Body = 'Spike OK ' . date('c');
        $m->send();
        $rows['Mail'] = 'sent to ' . $cfg['admin_email'];
    } catch (Throwable $e) {
        $rows['Mail error'] = $m->ErrorInfo ?: $e->getMessage();
    }
}
echo '<!doctype html><meta charset="utf-8"><h1>DELETE AFTER USE</h1><table border="1" cellpadding="4">';
foreach ($rows as $k => $v) {
    echo '<tr><th align="left">' . htmlspecialchars($k) . '</th><td>' . htmlspecialchars((string)$v) . '</td></tr>';
}
echo '</table>';
