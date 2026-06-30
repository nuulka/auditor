<?php

function get_pdo_connection() {
    static $pdo = null;
    if ($pdo === null) {
        $cfg = [];
        $cfg_file = __DIR__ . '/../config/app.php';
        if (file_exists($cfg_file)) {
            $cfg = include $cfg_file;
        }
        // A Google AI modul saját adatbázist használ
        $db = $cfg['db']['revizor'] ?? [];
        $db['host'] ??= 'localhost';
        $db['user'] ??= 'revizor_rw';
        $db['pass'] = (!empty($db['pass'])) ? $db['pass'] : 'revizor_2024_rw';
        $db['name'] = 'test_google_revizor_db';
        $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function get_ots_pdo() {
    static $pdo = null;
    if ($pdo === null) {
        $cfg = [];
        $cfg_file = __DIR__ . '/../config/app.php';
        if (file_exists($cfg_file)) {
            $cfg = include $cfg_file;
        }
        $db = $cfg['db']['ots'] ?? [];
        $db['host'] ??= 'localhost';
        $db['user'] ??= 'ots_ro';
        $db['pass'] = (!empty($db['pass'])) ? $db['pass'] : 'ots_2024_ro';
        $db['name'] ??= 'ots';
        $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
