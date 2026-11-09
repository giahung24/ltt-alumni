<?php
// api/verify_otp.php - POST {email, otp}: verify the code, mark the session verified (spec §5.3 step 2).

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/http.php';
require_once __DIR__ . '/../includes/repository.php';

start_session('ltt_pub');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    json_error('Phương thức không được hỗ trợ.', 405);
}

$in = read_input();
$str = fn(string $k): string => is_scalar($in[$k] ?? null) ? (string) $in[$k] : '';

$email = strtolower(trim($str('email')));
$otp = trim($str('otp'));
if (strlen($email) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    json_error('Địa chỉ email không hợp lệ.', 400);
}
if (!preg_match('/^[0-9]{6}$/D', $otp)) {
    json_error('Mã xác thực phải gồm đúng 6 chữ số.', 400);
}

$expired = 'Mã đã hết hạn, vui lòng lấy mã mới.';

try {
    $pdo = get_pdo();
    $stmt = $pdo->prepare('SELECT id, otp_hash, attempts FROM email_otps WHERE email = ? AND consumed_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1');
    $stmt->execute([$email]);
    $otpRow = $stmt->fetch();
    if (!$otpRow) {
        json_error($expired, 400);
    }

    if (!password_verify($otp, $otpRow['otp_hash'])) {
        // MariaDB applies SET left to right: IF() sees the already-incremented attempts,
        // so the code is burned on exactly the 5th failure.
        $pdo->prepare('UPDATE email_otps SET attempts = attempts + 1, consumed_at = IF(attempts >= 5, NOW(), consumed_at) WHERE id = ? AND consumed_at IS NULL')
            ->execute([$otpRow['id']]);
        if ((int) $otpRow['attempts'] + 1 >= 5) {
            json_error('Bạn đã nhập sai 5 lần, mã này đã bị hủy. Vui lòng lấy mã mới.', 400);
        }
        json_error('Mã xác thực không đúng.', 400);
    }

    // Atomic claim: only one concurrent request can consume the code.
    $claim = $pdo->prepare('UPDATE email_otps SET consumed_at = NOW() WHERE id = ? AND consumed_at IS NULL');
    $claim->execute([$otpRow['id']]);
    if ($claim->rowCount() !== 1) {
        json_error($expired, 400);
    }

    $profile = get_latest_row_by_email($pdo, $email);
} catch (Throwable $e) {
    error_log('[verify_otp] ' . $e->getMessage());
    json_error('Hệ thống đang bận, vui lòng thử lại sau.', 503);
}

session_regenerate_id(true);
$_SESSION['verified_email'] = $email;
$_SESSION['verified_until'] = time() + 1800;

json_out([
    'ok'               => true,
    'verified'         => true,
    'existing_profile' => $profile ? format_entry_row($profile) : null,
]);
