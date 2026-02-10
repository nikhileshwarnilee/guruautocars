<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('login.php');
}

require_csrf();

$identifier = post_string('identifier', 150);
$password = (string) ($_POST['password'] ?? '');

if ($identifier === '' || $password === '') {
    log_audit('auth', 'login_failed', null, 'Login failed: missing credentials.', [
        'entity' => 'user_session',
        'source' => 'UI',
        'before' => ['authenticated' => false],
        'after' => ['authenticated' => false, 'reason' => 'MISSING_CREDENTIALS'],
        'metadata' => ['identifier' => $identifier],
    ]);
    flash_set('login_error', 'Enter both email/username and password.', 'danger');
    redirect('login.php');
}

$stmt = db()->prepare(
    'SELECT u.id, u.company_id, u.primary_garage_id, u.password_hash, u.is_active,
            u.status_code,
            r.role_key,
            r.status_code AS role_status
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE (u.email = :email OR u.username = :username)
     LIMIT 1'
);
$stmt->execute([
    'email' => $identifier,
    'username' => $identifier,
]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, (string) $user['password_hash'])) {
    log_audit('auth', 'login_failed', null, 'Login failed: invalid credentials.', [
        'entity' => 'user_session',
        'source' => 'UI',
        'before' => ['authenticated' => false],
        'after' => ['authenticated' => false, 'reason' => 'INVALID_CREDENTIALS'],
        'metadata' => ['identifier' => $identifier],
    ]);
    flash_set('login_error', 'Invalid credentials.', 'danger');
    redirect('login.php');
}

if ((int) $user['is_active'] !== 1) {
    log_audit('auth', 'login_failed', (int) $user['id'], 'Login failed: inactive account.', [
        'entity' => 'user_session',
        'source' => 'UI',
        'company_id' => (int) $user['company_id'],
        'garage_id' => isset($user['primary_garage_id']) ? (int) $user['primary_garage_id'] : null,
        'user_id' => (int) $user['id'],
        'role_key' => (string) ($user['role_key'] ?? ''),
        'before' => ['authenticated' => false],
        'after' => ['authenticated' => false, 'reason' => 'INACTIVE_ACCOUNT'],
    ]);
    flash_set('login_error', 'Your account is inactive. Contact administrator.', 'danger');
    redirect('login.php');
}

if ((string) ($user['status_code'] ?? 'ACTIVE') !== 'ACTIVE' || (string) ($user['role_status'] ?? 'ACTIVE') !== 'ACTIVE') {
    log_audit('auth', 'login_failed', (int) $user['id'], 'Login failed: account or role not active.', [
        'entity' => 'user_session',
        'source' => 'UI',
        'company_id' => (int) $user['company_id'],
        'garage_id' => isset($user['primary_garage_id']) ? (int) $user['primary_garage_id'] : null,
        'user_id' => (int) $user['id'],
        'role_key' => (string) ($user['role_key'] ?? ''),
        'before' => ['authenticated' => false],
        'after' => ['authenticated' => false, 'reason' => 'INACTIVE_ROLE_OR_USER'],
    ]);
    flash_set('login_error', 'Your account is inactive. Contact administrator.', 'danger');
    redirect('login.php');
}

login_user($user);

$updateStmt = db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
$updateStmt->execute(['id' => (int) $user['id']]);

log_audit('auth', 'login', (int) $user['id'], 'User login successful.', [
    'entity' => 'user_session',
    'source' => 'UI',
    'company_id' => (int) $user['company_id'],
    'garage_id' => isset($user['primary_garage_id']) ? (int) $user['primary_garage_id'] : null,
    'user_id' => (int) $user['id'],
    'role_key' => (string) ($user['role_key'] ?? ''),
    'before' => ['authenticated' => false],
    'after' => ['authenticated' => true],
]);

redirect('dashboard.php');
