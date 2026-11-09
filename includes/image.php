<?php
// includes/image.php - Image validation, normalization, and GD processing.

/**
 * Validates and normalizes an uploaded portrait photo:
 * - Checks file size (max 5 MB) and MIME type (JPEG, PNG, WebP).
 * - Rejects dimensions > 25 MP before decoding to avoid memory exhaustion.
 * - Applies EXIF rotation for JPEGs when available.
 * - Scales down to fit within 800x1000 while maintaining aspect ratio (no upscaling).
 * - Fills transparent backgrounds with solid white.
 * - Saves as JPEG with quality 85 and a unique filename `u_<timestamp>_<random8>.jpg`.
 *
 * Note: Does NOT call is_uploaded_file() so it can be tested with local files.
 * The HTTP endpoint is responsible for is_uploaded_file() validation.
 *
 * @param array $file Array with keys 'tmp_name', 'size', 'error' (e.g. from $_FILES['photo'])
 * @param string $targetDir Directory where the normalized JPEG will be saved
 * @return string The generated filename (not the full path)
 * @throws InvalidArgumentException On validation errors
 * @throws RuntimeException On GD or file system errors
 */
function process_uploaded_photo(array $file, string $targetDir = __DIR__ . '/../static/img'): string {
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException("Ảnh tải lên vượt quá dung lượng tối đa 5 MB.");
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException("Lỗi tải lên ảnh (mã {$error}).");
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        throw new InvalidArgumentException("File ảnh rỗng hoặc không hợp lệ.");
    }
    if ($size > 5 * 1024 * 1024) {
        throw new InvalidArgumentException("Ảnh tải lên vượt quá dung lượng tối đa 5 MB.");
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !file_exists($tmpPath)) {
        throw new InvalidArgumentException("Không tìm thấy file ảnh tải lên.");
    }

    $info = @getimagesize($tmpPath);
    if (!is_array($info)) {
        throw new InvalidArgumentException("File tải lên không phải là ảnh hợp lệ.");
    }

    $mime = (string)($info['mime'] ?? '');
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowedMimes, true)) {
        throw new InvalidArgumentException("Định dạng ảnh không được hỗ trợ (chỉ chấp nhận JPEG, PNG hoặc WebP).");
    }

    $w = (int)($info[0] ?? 0);
    $h = (int)($info[1] ?? 0);
    if ($w <= 0 || $h <= 0) {
        throw new InvalidArgumentException("Kích thước ảnh không hợp lệ.");
    }
    if (($w * $h) > 25_000_000) {
        throw new InvalidArgumentException("Kích thước ảnh quá lớn (vượt quá 25 megapixel).");
    }

    // Decode image according to MIME type
    switch ($mime) {
        case 'image/jpeg':
            $src = @imagecreatefromjpeg($tmpPath);
            break;
        case 'image/png':
            $src = @imagecreatefrompng($tmpPath);
            break;
        case 'image/webp':
            if (!function_exists('imagecreatefromwebp')) {
                throw new RuntimeException("Máy chủ không hỗ trợ giải mã ảnh WebP.");
            }
            $src = @imagecreatefromwebp($tmpPath);
            break;
        default:
            $src = false;
    }

    if ($src === false) {
        throw new InvalidArgumentException("Không thể đọc dữ liệu ảnh.");
    }

    // Handle EXIF orientation for JPEG if exif extension is available
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmpPath);
        $orientation = (int)($exif['Orientation'] ?? $exif['IFD0']['Orientation'] ?? 0);
        $angle = 0;
        if ($orientation === 3) {
            $angle = 180;
        } elseif ($orientation === 6) {
            $angle = 270;
        } elseif ($orientation === 8) {
            $angle = 90;
        }

        if ($angle !== 0 && function_exists('imagerotate')) {
            $rotated = @imagerotate($src, $angle, 0);
            if ($rotated !== false) {
                imagedestroy($src);
                $src = $rotated;
                $w = imagesx($src);
                $h = imagesy($src);
            }
        }
    }

    // Calculate dimensions to fit within 800x1000 without upscaling
    $scale = min(1.0, 800 / $w, 1000 / $h);
    $dstW = max(1, (int)round($w * $scale));
    $dstH = max(1, (int)round($h * $scale));

    $dst = imagecreatetruecolor($dstW, $dstH);
    if ($dst === false) {
        imagedestroy($src);
        throw new RuntimeException("Không thể khởi tạo ảnh đích.");
    }

    // Fill with solid white background (avoids black background when converting transparent PNG/WebP to JPEG)
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $white);

    // Resample
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $w, $h);
    imagedestroy($src);

    // Ensure target directory exists
    if (!is_dir($targetDir)) {
        if (!@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            imagedestroy($dst);
            throw new RuntimeException("Không thể tạo thư mục lưu ảnh: {$targetDir}");
        }
    }

    $filename = sprintf('u_%d_%s.jpg', time(), bin2hex(random_bytes(4)));
    $destPath = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

    $saved = @imagejpeg($dst, $destPath, 85);
    imagedestroy($dst);

    if (!$saved) {
        throw new RuntimeException("Không thể lưu ảnh JPEG: {$destPath}");
    }

    return $filename;
}
