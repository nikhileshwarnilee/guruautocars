<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }

    session_name('GACSESSID');
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/password_reset.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/ledger_posting_service.php';
require_once __DIR__ . '/analytics.php';
require_once __DIR__ . '/reversal.php';
require_once __DIR__ . '/safe_delete.php';

if (function_exists('ledger_bootstrap_ready')) {
    ledger_bootstrap_ready();
}

log_request_access_event();

if (is_logged_in() && ($_POST['_action'] ?? '') === 'switch_garage') {
    require_csrf();
    $garageId = post_int('garage_id');
    if (set_active_garage($garageId)) {
        flash_set('garage_switched', 'Active garage switched successfully.', 'success');
    } else {
        flash_set('garage_switch_failed', 'Unable to switch garage.', 'danger');
    }

    $redirectTo = $_POST['_redirect'] ?? 'dashboard.php';
    redirect((string) $redirectTo);
}
