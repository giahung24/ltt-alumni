<?php
// api/submit_profile.php - POST multipart {fullname, graduation_year, education, work, photo?} (spec §5.3 step 3).

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/http.php';
require_once __DIR__ . '/../includes/repository.php';
require_once __DIR__ . '/../includes/image.php';
require_once __DIR__ . '/../includes/mailer.php';

start_session('ltt_pub');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    json_error('Phương thức không được hỗ trợ.', 405);
}

$email = $_SESSION['verified_email'] ?? null;
if (!is_string($email) || $email === '' || (int) ($_SESSION['verified_until'] ?? 0) < time()) {
    json_error('Phiên xác thực đã hết hạn, vui lòng xác thực lại email.', 401);
}

// post_max_size exceeded: PHP silently drops the whole body.
if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    json_error('Dữ liệu gửi lên quá lớn (file quá lớn), vui lòng chọn ảnh nhỏ hơn 5 MB.', 400);
}

$str = fn(string $k): string => is_scalar($_POST[$k] ?? null) ? (string) $_POST[$k] : '';
$text = fn(string $k): string => trim(str_replace("\r\n", "\n", $str($k)));

foreach (['fullname', 'education', 'work'] as $k) {
    if (!mb_check_encoding($str($k), 'UTF-8')) {
        json_error('Dữ liệu gửi lên không hợp lệ.', 400);
    }
}

$fullname = mb_convert_case(preg_replace('/\s+/u', ' ', trim($str('fullname'))), MB_CASE_TITLE, 'UTF-8');
$len = mb_strlen($fullname, 'UTF-8');
if ($len < 2 || $len > 120) {
    json_error('Họ và tên phải có từ 2 đến 120 ký tự.', 400);
}
$maxYear = (int) date('Y') + 3;
$year = filter_var(trim($str('graduation_year')), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1990, 'max_range' => $maxYear]]);
if ($year === false) {
    json_error("Năm tốt nghiệp phải là số từ 1990 đến {$maxYear}.", 400);
}
$education = $text('education');
if ($education === '' || mb_strlen($education, 'UTF-8') > 3000) {
    json_error('Quá trình học tập là bắt buộc và không quá 3000 ký tự.', 400);
}
$work = $text('work');
if (mb_strlen($work, 'UTF-8') > 3000) {
    json_error('Công việc không quá 3000 ký tự.', 400);
}

$newPhoto = null;
$file = $_FILES['photo'] ?? null;
if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    if (!is_int($file['error'] ?? null) || !is_string($file['tmp_name'] ?? null)
        || ($file['error'] === UPLOAD_ERR_OK && !is_uploaded_file($file['tmp_name']))) {
        json_error('Ảnh tải lên không hợp lệ.', 400);
    }
    try {
        $newPhoto = process_uploaded_photo($file, __DIR__ . '/../static/img');
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 400); // user-safe Vietnamese message from image.php
    } catch (Throwable $e) {
        error_log('[submit_profile] photo: ' . $e->getMessage());
        json_error('Không xử lý được ảnh, vui lòng thử lại sau.', 503);
    }
}

try {
    $pdo = get_pdo();
    $prev = get_latest_row_by_email($pdo, $email);
    $photo = $newPhoto ?? (($prev['photo_filename'] ?? null) ?: null);
    $pdo->prepare('INSERT INTO alumni_entries (email, fullname, graduation_year, education, work, photo_filename, submitter_ip) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$email, $fullname, $year, $education, $work, $photo, client_ip()]);
} catch (Throwable $e) {
    error_log('[submit_profile] insert: ' . $e->getMessage());
    json_error('Hệ thống đang bận, vui lòng thử lại sau.', 503);
}

unset($_SESSION['verified_email'], $_SESSION['verified_until']);
session_write_close(); // release the session lock before cache rebuild and SMTP

// The row is saved: a cache or mail failure must not report the submission as failed.
try {
    rebuild_cache($pdo);
} catch (Throwable $e) {
    error_log('[submit_profile] rebuild_cache: ' . $e->getMessage()); // admin can "Làm mới cache"
}

$h = fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$site = rtrim((string) (get_config()['site_url'] ?? ''), '/');
$subject = ($prev ? 'Cập nhật hồ sơ' : 'Hồ sơ mới') . ': ' . $fullname . ' (' . $year . ')';
$html = '<p><strong>' . $h($prev ? 'Cập nhật hồ sơ' : 'Hồ sơ mới') . '</strong></p>'
    . '<p>Họ và tên: ' . $h($fullname) . '<br>Khóa: ' . $h($year) . '<br>Email: ' . $h($email)
    . '<br>IP: ' . $h(client_ip()) . '<br>Ảnh: ' . $h($photo ?? 'default.jpg') . '</p>'
    . '<p>Quá trình học tập:<br>' . nl2br($h($education)) . '</p>'
    . '<p>Công việc:<br>' . nl2br($h($work)) . '</p>'
    . '<p><a href="' . $h($site) . '">Xem kỷ yếu</a> | <a href="' . $h($site . '/admin.php') . '">Trang quản trị</a></p>';
send_admin_notification($subject, $html); // never throws; failure is logged in mailer.php

json_out(['ok' => true, 'fullname' => $fullname, 'year' => $year]);
