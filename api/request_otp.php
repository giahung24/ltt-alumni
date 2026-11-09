<?php
// api/request_otp.php - GET: arithmetic captcha. POST: send a 6-digit OTP to the email (spec §5.3 step 1).

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/http.php';
require_once __DIR__ . '/../includes/mailer.php';

start_session('ltt_pub');

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'GET') {
    if (random_int(0, 1) === 1) {
        $a = random_int(1, 20);
        $b = random_int(1, 20);
        $_SESSION['captcha_answer'] = $a + $b;
        json_out(['ok' => true, 'question' => "{$a} + {$b} = ?"]);
    }
    $a = random_int(2, 20);
    $b = random_int(1, $a - 1);
    $_SESSION['captcha_answer'] = $a - $b;
    json_out(['ok' => true, 'question' => "{$a} - {$b} = ?"]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    json_error('Phương thức không được hỗ trợ.', 405);
}

// Single-use captcha: consumed by every POST, whatever the outcome.
$answer = $_SESSION['captcha_answer'] ?? null;
unset($_SESSION['captcha_answer']);
session_write_close(); // nothing else is written; don't hold the session lock during SMTP

$success = ['ok' => true, 'message' => 'Mã xác thực đã được gửi tới email của bạn.'];
$in = read_input();
$str = fn(string $k): string => is_scalar($in[$k] ?? null) ? (string) $in[$k] : '';

if (trim($str('website_url')) !== '') {
    json_out($success); // honeypot: fake success, no insert, no mail
}
if ($answer === null) {
    json_error('Phép tính đã hết hạn, vui lòng lấy câu hỏi mới.', 400);
}
$given = filter_var(trim($str('captcha')), FILTER_VALIDATE_INT);
if ($given === false || $given !== (int) $answer) {
    json_error('Kết quả phép tính không đúng, vui lòng thử lại với câu hỏi mới.', 400);
}
$email = strtolower(trim($str('email')));
if (strlen($email) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    json_error('Địa chỉ email không hợp lệ.', 400);
}

try {
    $pdo = get_pdo();
    $ip = client_ip();
    $pdo->exec('DELETE FROM email_otps WHERE created_at < NOW() - INTERVAL 1 DAY');

    // ponytail: count-then-insert is not atomic; concurrent requests can overshoot a limit by a few rows.
    $count = function (string $sql, array $args) use ($pdo): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        return (int) $stmt->fetchColumn();
    };
    if ($count('SELECT COUNT(*) FROM email_otps WHERE email = ? AND created_at > NOW() - INTERVAL 10 MINUTE', [$email]) >= 3) {
        json_error('Bạn đã yêu cầu quá nhiều mã cho email này, vui lòng thử lại sau 10 phút.', 429);
    }
    if ($count('SELECT COUNT(*) FROM email_otps WHERE requester_ip = ? AND created_at > NOW() - INTERVAL 15 MINUTE', [$ip]) >= 10) {
        json_error('Bạn đã yêu cầu quá nhiều mã, vui lòng thử lại sau 15 phút.', 429);
    }
    $cap = (int) (get_config()['otp_daily_cap'] ?? 100);
    if ($count('SELECT COUNT(*) FROM email_otps WHERE created_at > NOW() - INTERVAL 1 DAY', []) >= $cap) {
        json_error('Hệ thống đã đạt giới hạn gửi mã trong ngày, vui lòng thử lại vào ngày mai.', 429);
    }

    $code = (string) random_int(100000, 999999);
    $pdo->prepare('INSERT INTO email_otps (email, otp_hash, requester_ip, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL 10 MINUTE)')
        ->execute([$email, password_hash($code, PASSWORD_DEFAULT), $ip]);

    if (!send_otp_email($email, $code)) {
        // Row is kept on purpose so failed sends still count toward the limits.
        json_error('Không gửi được email xác thực, vui lòng thử lại sau.', 503);
    }
} catch (Throwable $e) {
    error_log('[request_otp] ' . $e->getMessage());
    json_error('Hệ thống đang bận, vui lòng thử lại sau.', 503);
}

json_out($success);
