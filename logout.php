<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';

if (is_logged_in()) {
    $user = current_user();
    log_audit('auth', 'logout', isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null, 'User logout.', [
        'entity' => 'user_session',
        'source' => 'UI',
        'company_id' => isset($_SESSION['company_id']) ? (int) $_SESSION['company_id'] : null,
        'garage_id' => isset($_SESSION['active_garage_id']) ? (int) $_SESSION['active_garage_id'] : null,
        'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
        'role_key' => (string) ($_SESSION['role_key'] ?? ''),
        'before' => [
            'authenticated' => true,
            'user_name' => (string) ($user['name'] ?? ''),
        ],
        'after' => ['authenticated' => false],
    ]);
}

logout_user();
redirect('login.php');
