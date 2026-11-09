<?php
// includes/repository.php - Data access, cache management, and backup exports.

date_default_timezone_set('Asia/Ho_Chi_Minh');

const CACHE_FILE = __DIR__ . '/../storage/cache/alumni_latest.php';

function format_entry_row(array $row): array {
    $photoFilename = $row['photo_filename'] ?? null;
    if (!empty($photoFilename)) {
        $photo = 'static/img/' . $photoFilename;
    } elseif (!empty($row['photo'])) {
        $photo = (string) $row['photo'];
    } else {
        $photo = 'static/img/default.jpg';
    }

    return [
        'timestamp' => (string) ($row['submitted_at'] ?? $row['timestamp'] ?? ''),
        'fullname'  => (string) ($row['fullname'] ?? ''),
        'year'      => (int) ($row['graduation_year'] ?? $row['year'] ?? 0),
        'education' => (string) ($row['education'] ?? ''),
        'work'      => (string) ($row['work'] ?? ''),
        'email'     => (string) ($row['email'] ?? ''),
        'photo'     => $photo,
    ];
}

function fetch_latest_rows(PDO $pdo): array {
    $sql = 'SELECT e.* FROM alumni_entries e '
         . 'JOIN (SELECT MAX(id) AS id FROM alumni_entries WHERE is_deleted = 0 GROUP BY email) m USING (id) '
         . 'ORDER BY e.id DESC';
    $stmt = $pdo->query($sql);
    $rows = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = format_entry_row($row);
    }
    return $rows;
}

function rebuild_cache(PDO $pdo, string $cacheFile = CACHE_FILE): array {
    $rows = fetch_latest_rows($pdo);
    $dir = dirname($cacheFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $tmpFile = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $content = "<?php return " . var_export($rows, true) . ";\n";

    try {
        if (file_put_contents($tmpFile, $content, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write temporary cache file: {$tmpFile}");
        }
        if (!rename($tmpFile, $cacheFile)) {
            throw new RuntimeException("Failed to rename temporary cache file to {$cacheFile}");
        }
    } finally {
        if (is_file($tmpFile)) {
            @unlink($tmpFile);
        }
    }

    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($cacheFile, true);
    }

    return $rows;
}

function get_public_entries(?callable $pdoFactory = null, string $cacheFile = CACHE_FILE): array {
    $cached = null;
    if (is_file($cacheFile)) {
        try {
            $cached = @include $cacheFile;
        } catch (\Throwable $e) {
            $cached = null;
        }
    }
    if (is_array($cached)) {
        return $cached;
    }

    if ($pdoFactory === null && !function_exists('get_pdo')) {
        require_once __DIR__ . '/db.php';
    }
    $factory = $pdoFactory ?? 'get_pdo';
    $pdo = $factory();
    return rebuild_cache($pdo, $cacheFile);
}

function get_latest_row_by_email(PDO $pdo, string $email): ?array {
    $normalized = strtolower(trim($email));
    $stmt = $pdo->prepare('SELECT * FROM alumni_entries WHERE email = ? AND is_deleted = 0 ORDER BY id DESC LIMIT 1');
    $stmt->execute([$normalized]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function export_backup_payload(PDO $pdo): array {
    $stmt = $pdo->query('SELECT * FROM alumni_entries ORDER BY id ASC');
    $entries = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $formatted = format_entry_row($row);
        $entries[] = [
            'id'         => (int) $row['id'],
            'timestamp'  => $formatted['timestamp'],
            'fullname'   => $formatted['fullname'],
            'year'       => $formatted['year'],
            'education'  => $formatted['education'],
            'work'       => $formatted['work'],
            'email'      => $formatted['email'],
            'photo'      => $formatted['photo'],
            'is_deleted' => (int) $row['is_deleted'],
        ];
    }
    return [
        'exported_at' => date('Y-m-d'),
        'entries'     => $entries,
    ];
}
