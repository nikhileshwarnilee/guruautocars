<?php

return [
    'app' => [
        'name' => getenv('APP_NAME') ?: 'Guru Auto Cars',
        'env' => getenv('APP_ENV') ?: 'production',
        'debug' => filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOL),
        'url' => getenv('APP_URL') ?: 'http://localhost',
        'timezone' => getenv('APP_TIMEZONE') ?: 'Asia/Kolkata',
    ],
    'security' => [
        'session_name' => getenv('SESSION_NAME') ?: 'guru_session',
        'csrf_token_key' => getenv('CSRF_TOKEN_KEY') ?: '_token',
        'password_algo' => PASSWORD_DEFAULT,
    ],
];
