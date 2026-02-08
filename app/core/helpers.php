<?php

function config(string $key, mixed $default = null): mixed
{
    static $config;

    if ($config === null) {
        $config = require __DIR__ . '/../config/config.php';
    }

    $segments = explode('.', $key);
    $value = $config;

    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function csrf_token(): string
{
    $key = config('security.csrf_token_key', '_token');

    if (!Session::get($key)) {
        Session::set($key, bin2hex(random_bytes(32)));
    }

    return (string) Session::get($key);
}

function csrf_field(): string
{
    $token = csrf_token();
    $key = config('security.csrf_token_key', '_token');

    return '<input type="hidden" name="' . e($key) . '" value="' . e($token) . '">';
}
