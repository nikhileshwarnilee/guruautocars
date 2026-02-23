<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

$dotenvPath = APP_ROOT . DIRECTORY_SEPARATOR . '.env';
if (is_file($dotenvPath) && is_readable($dotenvPath)) {
    $lines = file($dotenvPath, FILE_IGNORE_NEW_LINES);
    if ($lines !== false) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $separatorPos = strpos($line, '=');
            if ($separatorPos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separatorPos));
            if ($key === '') {
                continue;
            }

            $value = trim(substr($line, $separatorPos + 1));
            $isQuoted = (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"));
            if ($isQuoted) {
                $value = substr($value, 1, -1);
            }

            if (getenv($key) === false) {
                putenv($key . '=' . $value);
            }
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
            }
            if (!array_key_exists($key, $_SERVER)) {
                $_SERVER[$key] = $value;
            }
        }
    }
}

$env = static function (string $key, string $default): string {
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }

    return $value;
};

date_default_timezone_set($env('APP_TIMEZONE', 'Asia/Kolkata'));

if (!defined('APP_NAME')) {
    define('APP_NAME', $env('APP_NAME', 'Guru Auto Cars - Garage Management ERP'));
}
if (!defined('APP_SHORT_NAME')) {
    define('APP_SHORT_NAME', $env('APP_SHORT_NAME', 'Guru Auto Cars'));
}
if (!defined('DB_HOST')) {
    define('DB_HOST', $env('DB_HOST', 'localhost'));
}
if (!defined('DB_NAME')) {
    define('DB_NAME', $env('DB_NAME', 'guruautocars'));
}
if (!defined('DB_USER')) {
    define('DB_USER', $env('DB_USER', 'root'));
}
if (!defined('DB_PASS')) {
    define('DB_PASS', $env('DB_PASS', ''));
}
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', $env('DB_CHARSET', 'utf8mb4'));
}

if (!defined('APP_BASE_URL')) {
    $projectRootRaw = realpath(APP_ROOT) ?: APP_ROOT;
    $documentRootInput = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $documentRootRaw = realpath($documentRootInput) ?: $documentRootInput;

    $projectRoot = str_replace('\\', '/', $projectRootRaw);
    $documentRoot = str_replace('\\', '/', $documentRootRaw);

    $baseUrl = '';
    if ($documentRoot !== '' && str_starts_with($projectRoot, $documentRoot)) {
        $baseUrl = substr($projectRoot, strlen($documentRoot));
    }

    $baseUrl = '/' . trim($baseUrl, '/');
    if ($baseUrl === '/') {
        $baseUrl = '';
    }

    define('APP_BASE_URL', $baseUrl);
}
