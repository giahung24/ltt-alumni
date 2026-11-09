<?php
// includes/db.php - Database connection and configuration helper.

function get_config(): array {
    static $config = null;
    if ($config === null) {
        $file = __DIR__ . '/config.php';
        if (!is_file($file)) {
            throw new RuntimeException("Missing configuration file: {$file}. Copy includes/config.example.php to includes/config.php.");
        }
        $config = require $file;
        if (!is_array($config)) {
            throw new RuntimeException("Configuration file must return an array: {$file}");
        }
    }
    return $config;
}

function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        date_default_timezone_set('Asia/Ho_Chi_Minh');
        $cfg = get_config();
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $cfg['db_host'],
            $cfg['db_name']
        );
        $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '+07:00'");
    }
    return $pdo;
}
