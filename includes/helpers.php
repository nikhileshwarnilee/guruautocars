<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    $cleanPath = ltrim($path, '/');

    if ($cleanPath === '') {
        return APP_BASE_URL !== '' ? APP_BASE_URL . '/' : '/';
    }

    return (APP_BASE_URL !== '' ? APP_BASE_URL : '') . '/' . $cleanPath;
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function flash_set(string $key, string $message, string $type = 'info'): void
{
    $_SESSION['_flash'][$key] = [
        'message' => $message,
        'type' => $type,
    ];
}

function flash_get(string $key): ?array
{
    if (!isset($_SESSION['_flash'][$key])) {
        return null;
    }

    $flash = $_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);

    return $flash;
}

function flash_pull_all(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);

    return is_array($messages) ? $messages : [];
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf_token(?string $token): bool
{
    if (!isset($_SESSION['_csrf_token']) || !is_string($token)) {
        return false;
    }

    return hash_equals($_SESSION['_csrf_token'], $token);
}

function require_csrf(): void
{
    $token = $_POST['_csrf'] ?? null;
    if (!verify_csrf_token(is_string($token) ? $token : null)) {
        http_response_code(419);
        exit('Invalid CSRF token.');
    }
}

function post_string(string $key, int $maxLength = 255): string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    if ($maxLength > 0) {
        $value = mb_substr($value, 0, $maxLength);
    }

    return $value;
}

function get_int(string $key, int $default = 0): int
{
    $value = $_GET[$key] ?? $default;
    return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : $default;
}

function post_int(string $key, int $default = 0): int
{
    $value = $_POST[$key] ?? $default;
    return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : $default;
}

function format_currency(float $amount): string
{
    return 'INR ' . number_format($amount, 2);
}
