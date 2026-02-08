<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    DB_HOST,
    DB_NAME,
    DB_CHARSET
);

$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    // Keep emulation enabled so repeated named placeholders are supported.
    PDO::ATTR_EMULATE_PREPARES => true,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $pdoOptions);
} catch (PDOException $exception) {
    http_response_code(500);
    exit('Database connection failed.');
}

function db(): PDO
{
    global $pdo;
    return $pdo;
}
