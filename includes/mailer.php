<?php
declare(strict_types=1);

function app_mail_driver(): string
{
    $driver = strtolower(trim((string) getenv('MAIL_DRIVER')));
    return in_array($driver, ['mail', 'log'], true) ? $driver : 'mail';
}

function app_mail_from_address(): string
{
    $configured = trim((string) getenv('MAIL_FROM_ADDRESS'));
    if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_EMAIL) !== false) {
        return $configured;
    }

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $host = strtolower(preg_replace('/:\d+$/', '', $host) ?? 'localhost');
    if ($host === '') {
        $host = 'localhost';
    }

    return 'no-reply@' . $host;
}

function app_mail_from_name(): string
{
    $configured = trim((string) getenv('MAIL_FROM_NAME'));
    if ($configured !== '') {
        return mb_substr($configured, 0, 120);
    }

    return APP_SHORT_NAME;
}

function app_mail_sanitize_header_value(string $value, int $maxLength = 255): string
{
    $value = str_replace(["\r", "\n"], ' ', trim($value));
    return mb_substr($value, 0, $maxLength);
}

function app_mail_format_from_header(string $address, string $name): string
{
    $safeAddress = app_mail_sanitize_header_value($address, 190);
    $safeName = app_mail_sanitize_header_value($name, 120);
    if ($safeName === '') {
        return $safeAddress;
    }

    $safeName = str_replace('"', '\"', $safeName);
    return '"' . $safeName . '" <' . $safeAddress . '>';
}

function app_mail_write_log(string $toEmail, string $subject, string $body): bool
{
    $path = trim((string) getenv('MAIL_LOG_PATH'));
    if ($path === '') {
        $path = APP_ROOT . DIRECTORY_SEPARATOR . 'tmp_mail.log';
    }

    $entry = implode(PHP_EOL, [
        '---- ' . gmdate('c') . ' ----',
        'To: ' . $toEmail,
        'Subject: ' . $subject,
        '',
        $body,
        '',
    ]);

    return file_put_contents($path, $entry, FILE_APPEND | LOCK_EX) !== false;
}

function app_mail_send_plaintext(string $toEmail, string $subject, string $body): bool
{
    $toEmail = strtolower(trim($toEmail));
    if ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }

    $subject = app_mail_sanitize_header_value($subject, 190);
    if ($subject === '') {
        $subject = APP_SHORT_NAME . ' Notification';
    }

    $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
    if ($body === '') {
        return false;
    }

    if (app_mail_driver() === 'log') {
        return app_mail_write_log($toEmail, $subject, $body);
    }

    $fromAddress = app_mail_from_address();
    $fromName = app_mail_from_name();

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . app_mail_format_from_header($fromAddress, $fromName),
        'Reply-To: ' . app_mail_sanitize_header_value($fromAddress, 190),
        'X-Mailer: PHP/' . PHP_VERSION,
    ];

    return @mail($toEmail, $subject, $body, implode("\r\n", $headers));
}
