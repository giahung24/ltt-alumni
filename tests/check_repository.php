<?php
// tests/check_repository.php - Verification suite for repository, cache, and backup functions.

declare(strict_types=1);

ini_set('zend.assertions', '1');
ini_set('assert.exception', '1');

require_once __DIR__ . '/../includes/repository.php';

function test_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new AssertionError("Assertion failed: {$message}");
    }
}

// 1. Set up SQLite in-memory database with identical schema
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec("CREATE TABLE alumni_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    fullname TEXT NOT NULL,
    graduation_year INTEGER NOT NULL,
    education TEXT NOT NULL,
    work TEXT NULL,
    photo_filename TEXT NULL,
    submitted_at TEXT NOT NULL,
    submitter_ip TEXT NULL,
    is_deleted INTEGER NOT NULL DEFAULT 0
)");

// Insert test rows:
// - a@x.com twice (second row newer, photo_filename = 'u_1.jpg')
// - b@x.com active row then a newer row with is_deleted = 1
// - c@x.com with photo_filename = NULL
$insert = $pdo->prepare("INSERT INTO alumni_entries (
    email, fullname, graduation_year, education, work, photo_filename, submitted_at, submitter_ip, is_deleted
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

// Row 1: a@x.com (older, active)
$insert->execute(['a@x.com', 'A Nguyen', 2015, 'Edu A1', 'Work A1', 'u_old.jpg', '2024-01-01 10:00:00', '1.1.1.1', 0]);
// Row 2: a@x.com (newer, active)
$insert->execute(['a@x.com', 'A Nguyen Updated', 2015, 'Edu A2', 'Work A2', 'u_1.jpg', '2024-01-02 10:00:00', '1.1.1.2', 0]);
// Row 3: b@x.com (older, active)
$insert->execute(['b@x.com', 'B Tran', 2016, 'Edu B1', 'Work B1', 'u_b.jpg', '2024-01-01 11:00:00', '2.2.2.1', 0]);
// Row 4: b@x.com (newer, deleted)
$insert->execute(['b@x.com', 'B Tran Deleted', 2016, 'Edu B2', 'Work B2', 'u_b_del.jpg', '2024-01-03 11:00:00', '2.2.2.2', 1]);
// Row 5: c@x.com (active, null photo)
$insert->execute(['c@x.com', 'C Le', 2017, 'Edu C', 'Work C', null, '2024-01-01 12:00:00', '3.3.3.1', 0]);

$tempDir = sys_get_temp_dir() . '/ltt_repo_test_' . bin2hex(random_bytes(4));
mkdir($tempDir, 0755, true);
$cacheFile = $tempDir . '/alumni_test.php';

try {
    // 2. rebuild_cache() -> 3 rows
    $rows = rebuild_cache($pdo, $cacheFile);
    test_assert(count($rows) === 3, 'rebuild_cache should return exactly 3 active rows');

    $byEmail = [];
    foreach ($rows as $r) {
        $byEmail[$r['email']] = $r;
        test_assert(is_int($r['year']), "Year must be int, got " . gettype($r['year']));
    }

    // a@x.com is the newer row with photo = 'static/img/u_1.jpg'
    test_assert(isset($byEmail['a@x.com']), 'a@x.com must exist in cache');
    test_assert($byEmail['a@x.com']['photo'] === 'static/img/u_1.jpg', 'a@x.com should have photo static/img/u_1.jpg');
    test_assert($byEmail['a@x.com']['timestamp'] === '2024-01-02 10:00:00', 'a@x.com should be the newer row');

    // b@x.com is its older active row
    test_assert(isset($byEmail['b@x.com']), 'b@x.com must exist in cache');
    test_assert($byEmail['b@x.com']['photo'] === 'static/img/u_b.jpg', 'b@x.com should have photo static/img/u_b.jpg');
    test_assert($byEmail['b@x.com']['timestamp'] === '2024-01-01 11:00:00', 'b@x.com should be the older active row');

    // c@x.com has photo = 'static/img/default.jpg'
    test_assert(isset($byEmail['c@x.com']), 'c@x.com must exist in cache');
    test_assert($byEmail['c@x.com']['photo'] === 'static/img/default.jpg', 'c@x.com should fall back to default.jpg');

    // cache file assertions
    test_assert(is_file($cacheFile), 'Cache file must exist on disk');
    $content = (string) file_get_contents($cacheFile);
    test_assert(str_starts_with($content, '<?php return'), 'Cache file must start with "<?php return"');
    $included = include $cacheFile;
    test_assert($included === $rows, 'include of cache file must return the identical array');

    // No *.tmp files left in the directory
    $tmpFiles = glob($tempDir . '/*.tmp');
    test_assert(empty($tmpFiles), 'No temporary .tmp files should remain in cache directory');

    // 3. get_public_entries(fn() => throw ..., $cacheFile) returns cached rows without calling factory
    $publicEntries = get_public_entries(function () {
        throw new RuntimeException('DB must not be touched on cache hit');
    }, $cacheFile);
    test_assert($publicEntries === $rows, 'get_public_entries should return cached array on cache hit');

    // 4. Overwrite cache with <?php return 'garbage'; -> get_public_entries recovers
    file_put_contents($cacheFile, "<?php return 'garbage';\n");
    $recovered = get_public_entries(fn() => $pdo, $cacheFile);
    test_assert(count($recovered) === 3, 'get_public_entries should rebuild cache when cache is corrupted');
    $newIncluded = include $cacheFile;
    test_assert(is_array($newIncluded) && count($newIncluded) === 3, 'Cache file should be valid array after rebuild');

    // 5. Verify get_latest_row_by_email()
    // a@x.com -> returns newer active row (id 2)
    $rowA = get_latest_row_by_email($pdo, 'a@x.com');
    test_assert($rowA !== null && (int)$rowA['id'] === 2, 'get_latest_row_by_email should return row 2 for a@x.com');
    test_assert($rowA['photo_filename'] === 'u_1.jpg', 'Row A photo_filename should be u_1.jpg');

    // case-insensitivity and trim check
    $rowAUpper = get_latest_row_by_email($pdo, '   A@X.COM  ');
    test_assert($rowAUpper !== null && (int)$rowAUpper['id'] === 2, 'get_latest_row_by_email should trim and lower-case email');

    // b@x.com -> returns older active row (id 3), ignoring newer deleted row (id 4)
    $rowB = get_latest_row_by_email($pdo, 'b@x.com');
    test_assert($rowB !== null && (int)$rowB['id'] === 3, 'get_latest_row_by_email should return active row 3 for b@x.com');

    // c@x.com -> returns row 5 with null photo_filename
    $rowC = get_latest_row_by_email($pdo, 'c@x.com');
    test_assert($rowC !== null && (int)$rowC['id'] === 5 && $rowC['photo_filename'] === null, 'get_latest_row_by_email should return row 5 for c@x.com');

    // Non-existent email -> null
    test_assert(get_latest_row_by_email($pdo, 'none@x.com') === null, 'Unknown email should return null');

    // Email with only deleted rows -> null
    $insert->execute(['d@x.com', 'D Pham', 2018, 'Edu D', 'Work D', null, '2024-01-05 10:00:00', '4.4.4.4', 1]);
    test_assert(get_latest_row_by_email($pdo, 'd@x.com') === null, 'Email with only deleted rows should return null');

    // 6. Verify export_backup_payload()
    $backup = export_backup_payload($pdo);
    test_assert(isset($backup['exported_at']) && $backup['exported_at'] === date('Y-m-d'), 'exported_at must match current date Y-m-d');
    test_assert(isset($backup['entries']) && is_array($backup['entries']), 'entries must be an array');
    test_assert(count($backup['entries']) === 6, 'export_backup_payload should contain all 6 rows (including deleted)');

    $prevId = 0;
    $contractKeys = ['id', 'timestamp', 'fullname', 'year', 'education', 'work', 'email', 'photo', 'is_deleted'];
    foreach ($backup['entries'] as $entry) {
        test_assert((int)$entry['id'] > $prevId, 'Entries must be strictly ordered by id ASC');
        $prevId = (int)$entry['id'];

        foreach ($contractKeys as $key) {
            test_assert(array_key_exists($key, $entry), "Entry must have key {$key}");
        }
        test_assert(!array_key_exists('submitter_ip', $entry), 'Entry must not expose submitter_ip');
        test_assert(is_int($entry['id']), 'id must be int');
        test_assert(is_int($entry['year']), 'year must be int');
        test_assert(is_int($entry['is_deleted']), 'is_deleted must be int');
    }

    // Check deleted flag on row 4 (id 4, deleted) and row 6 (id 6, deleted)
    test_assert($backup['entries'][3]['is_deleted'] === 1, 'Row 4 must have is_deleted = 1');
    test_assert($backup['entries'][2]['is_deleted'] === 0, 'Row 3 must have is_deleted = 0');
    test_assert($backup['entries'][5]['is_deleted'] === 1, 'Row 6 must have is_deleted = 1');

} finally {
    if (is_file($cacheFile)) {
        @unlink($cacheFile);
    }
    if (is_dir($tempDir)) {
        @rmdir($tempDir);
    }
}

echo "OK check_repository\n";
