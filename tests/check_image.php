<?php
// tests/check_image.php - Verification suite for image processing and GD normalization.

declare(strict_types=1);

ini_set('zend.assertions', '1');
ini_set('assert.exception', '1');

require_once __DIR__ . '/../includes/image.php';

function test_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new AssertionError("Assertion failed: {$message}");
    }
}

$tempDir = sys_get_temp_dir() . '/ltt_image_test_' . bin2hex(random_bytes(4));
mkdir($tempDir, 0755, true);

$tempFiles = [];
function make_temp_file(string $prefix, string $suffix = ''): string {
    global $tempFiles;
    $path = tempnam(sys_get_temp_dir(), $prefix);
    if ($suffix !== '') {
        $newPath = $path . $suffix;
        rename($path, $newPath);
        $path = $newPath;
    }
    $tempFiles[] = $path;
    return $path;
}

try {
    // -------------------------------------------------------------
    // Test 1: 1200x900 transparent PNG -> 800x600 JPEG with white background
    // -------------------------------------------------------------
    $p1 = make_temp_file('png_trans_', '.png');
    $png = imagecreatetruecolor(1200, 900);
    test_assert($png !== false, "Created 1200x900 truecolor image");
    imagesavealpha($png, true);
    imagealphablending($png, false);
    $transparent = imagecolorallocatealpha($png, 0, 0, 0, 127);
    imagefilledrectangle($png, 0, 0, 1200, 900, $transparent);
    imagepng($png, $p1);
    imagedestroy($png);

    $file1 = [
        'tmp_name' => $p1,
        'size'     => filesize($p1),
        'error'    => UPLOAD_ERR_OK,
    ];
    $result1 = process_uploaded_photo($file1, $tempDir);

    test_assert(
        (bool)preg_match('/^u_\d+_[0-9a-f]{8}\.jpg$/', $result1),
        "Result filename matches pattern: {$result1}"
    );

    $outPath1 = $tempDir . DIRECTORY_SEPARATOR . $result1;
    test_assert(is_file($outPath1), "Output file exists: {$outPath1}");

    $info1 = getimagesize($outPath1);
    test_assert(is_array($info1), "Output is valid image");
    test_assert($info1[0] === 800 && $info1[1] === 600, "Output is resized to 800x600 (was {$info1[0]}x{$info1[1]})");
    test_assert($info1['mime'] === 'image/jpeg', "Output MIME is image/jpeg");

    $im1 = imagecreatefromjpeg($outPath1);
    test_assert($im1 !== false, "Output JPEG can be decoded");
    $rgb1 = imagecolorat($im1, 10, 10);
    $r1 = ($rgb1 >> 16) & 0xFF;
    $g1 = ($rgb1 >> 8) & 0xFF;
    $b1 = $rgb1 & 0xFF;
    imagedestroy($im1);
    test_assert(
        $r1 >= 250 && $g1 >= 250 && $b1 >= 250,
        "Pixel (10,10) is white background: R={$r1}, G={$g1}, B={$b1}"
    );

    // -------------------------------------------------------------
    // Test 2: 300x200 JPEG -> not upscaled (output 300x200)
    // -------------------------------------------------------------
    $p2 = make_temp_file('jpg_small_', '.jpg');
    $jpg2 = imagecreatetruecolor(300, 200);
    imagejpeg($jpg2, $p2, 85);
    imagedestroy($jpg2);

    $file2 = [
        'tmp_name' => $p2,
        'size'     => filesize($p2),
        'error'    => UPLOAD_ERR_OK,
    ];
    $result2 = process_uploaded_photo($file2, $tempDir);

    $outPath2 = $tempDir . DIRECTORY_SEPARATOR . $result2;
    $info2 = getimagesize($outPath2);
    test_assert(is_array($info2), "Output is valid image");
    test_assert(
        $info2[0] === 300 && $info2[1] === 200,
        "Small 300x200 image not upscaled: got {$info2[0]}x{$info2[1]}"
    );

    // -------------------------------------------------------------
    // Test 3: Hand-built PNG header 6000x5000 (30 MP) with no image data
    // Must throw InvalidArgumentException before decoding; NO GD warnings emitted.
    // -------------------------------------------------------------
    $p3 = make_temp_file('png_bomb_', '.png');
    $ihdrData = pack('N', 6000) . pack('N', 5000) . chr(8) . chr(6) . chr(0) . chr(0) . chr(0);
    $ihdrChunk = 'IHDR' . $ihdrData;
    $fakePng = "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdrChunk . pack('N', crc32($ihdrChunk));
    file_put_contents($p3, $fakePng);

    $file3 = [
        'tmp_name' => $p3,
        'size'     => strlen($fakePng),
        'error'    => UPLOAD_ERR_OK,
    ];

    $warnings = [];
    set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
        $warnings[] = "[$errno] $errstr";
        return true;
    });

    $threwBomb = false;
    try {
        process_uploaded_photo($file3, $tempDir);
    } catch (InvalidArgumentException $e) {
        $threwBomb = true;
        test_assert(
            str_contains($e->getMessage(), '25 megapixel'),
            "Exception message mentions 25 megapixel: {$e->getMessage()}"
        );
    } finally {
        restore_error_handler();
    }

    test_assert($threwBomb, "6000x5000 PNG bomb threw InvalidArgumentException");
    test_assert(
        empty($warnings),
        "Zero GD warnings were emitted for PNG bomb test (got: " . implode(', ', $warnings) . ")"
    );

    // -------------------------------------------------------------
    // Test 4: Text file renamed .jpg -> InvalidArgumentException
    // -------------------------------------------------------------
    $p4 = make_temp_file('fake_img_', '.jpg');
    file_put_contents($p4, "Hello world, this is plain text, not a JPEG image!");

    $file4 = [
        'tmp_name' => $p4,
        'size'     => filesize($p4),
        'error'    => UPLOAD_ERR_OK,
    ];

    $threwText = false;
    try {
        process_uploaded_photo($file4, $tempDir);
    } catch (InvalidArgumentException $e) {
        $threwText = true;
        test_assert(
            str_contains($e->getMessage(), 'không phải là ảnh hợp lệ'),
            "Exception message explains not a valid image: {$e->getMessage()}"
        );
    }
    test_assert($threwText, "Text file renamed .jpg threw InvalidArgumentException");

    // -------------------------------------------------------------
    // Test 5: File size > 5 MB rejection
    // -------------------------------------------------------------
    $file5 = [
        'tmp_name' => $p2,
        'size'     => 5 * 1024 * 1024 + 1,
        'error'    => UPLOAD_ERR_OK,
    ];

    $threwOversize = false;
    try {
        process_uploaded_photo($file5, $tempDir);
    } catch (InvalidArgumentException $e) {
        $threwOversize = true;
        test_assert(
            str_contains($e->getMessage(), '5 MB'),
            "Exception message mentions 5 MB: {$e->getMessage()}"
        );
    }
    test_assert($threwOversize, "File > 5 MB threw InvalidArgumentException");

    // -------------------------------------------------------------
    // Test 6: UPLOAD_ERR_INI_SIZE and UPLOAD_ERR_FORM_SIZE rejection
    // -------------------------------------------------------------
    $file6 = [
        'tmp_name' => '',
        'size'     => 0,
        'error'    => UPLOAD_ERR_INI_SIZE,
    ];

    $threwIniSize = false;
    try {
        process_uploaded_photo($file6, $tempDir);
    } catch (InvalidArgumentException $e) {
        $threwIniSize = true;
        test_assert(
            $e->getMessage() === 'Ảnh tải lên vượt quá dung lượng tối đa 5 MB.',
            "UPLOAD_ERR_INI_SIZE message matches exact specification: {$e->getMessage()}"
        );
    }
    test_assert($threwIniSize, "UPLOAD_ERR_INI_SIZE threw InvalidArgumentException");

    $file7 = [
        'tmp_name' => '',
        'size'     => 0,
        'error'    => UPLOAD_ERR_FORM_SIZE,
    ];

    $threwFormSize = false;
    try {
        process_uploaded_photo($file7, $tempDir);
    } catch (InvalidArgumentException $e) {
        $threwFormSize = true;
        test_assert(
            $e->getMessage() === 'Ảnh tải lên vượt quá dung lượng tối đa 5 MB.',
            "UPLOAD_ERR_FORM_SIZE message matches exact specification: {$e->getMessage()}"
        );
    }
    test_assert($threwFormSize, "UPLOAD_ERR_FORM_SIZE threw InvalidArgumentException");

} finally {
    // Cleanup temporary files
    foreach ($tempFiles as $f) {
        if (file_exists($f)) {
            @unlink($f);
        }
    }
    // Cleanup generated outputs in $tempDir
    if (is_dir($tempDir)) {
        $files = scandir($tempDir);
        if (is_array($files)) {
            foreach ($files as $f) {
                if ($f !== '.' && $f !== '..') {
                    @unlink($tempDir . DIRECTORY_SEPARATOR . $f);
                }
            }
        }
        @rmdir($tempDir);
    }
}

echo "OK check_image\n";
