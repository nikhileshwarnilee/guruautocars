<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');

const APP_NAME = 'Guru Auto Cars - Garage Management ERP';
const APP_SHORT_NAME = 'Guru Auto Cars';

const DB_HOST = 'localhost';
const DB_NAME = 'guruautocars';
const DB_USER = 'root';
const DB_PASS = '';
const DB_CHARSET = 'utf8mb4';

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
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
