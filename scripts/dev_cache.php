<?php
// scripts/dev_cache.php - Local helper to rebuild storage/cache/alumni_latest.php from seed.sql.

require_once __DIR__ . '/../includes/repository.php';

$seedFile = __DIR__ . '/../seed.sql';
if (!is_file($seedFile)) {
    fwrite(STDERR, "Error: seed.sql not found at {$seedFile}. Run 'python scripts/build_seed.py' first.\n");
    exit(1);
}

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

$lines = file($seedFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$count = 0;

foreach ($lines as $line) {
    $trimmed = trim($line);
    if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, 'SET ')) {
        continue;
    }

    // seed.sql uses MySQL backslash escapes \n, \r, \\ inside string literals.
    // Unescape them so SQLite stores real newlines, returns, and single backslashes.
    $sql = preg_replace_callback('/\\\\([\\\\nr])/', function ($m) {
        return match ($m[1]) {
            'n' => "\n",
            'r' => "\r",
            '\\' => "\\",
            default => $m[0],
        };
    }, $trimmed);

    $pdo->exec($sql);
    $count++;
}

$rows = rebuild_cache($pdo);
echo "Rebuilt cache from {$count} seed entries -> " . count($rows) . " active entries in " . CACHE_FILE . "\n";
