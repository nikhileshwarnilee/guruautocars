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
    flash_set('login_error', 'Invalid credentials.', 'danger');
    redirect('login.php');
}

if ((int) $user['is_active'] !== 1) {
    flash_set('login_error', 'Your account is inactive. Contact administrator.', 'danger');
    redirect('login.php');
}

if ((string) ($user['status_code'] ?? 'ACTIVE') !== 'ACTIVE' || (string) ($user['role_status'] ?? 'ACTIVE') !== 'ACTIVE') {
    flash_set('login_error', 'Your account is inactive. Contact administrator.', 'danger');
    redirect('login.php');
}

login_user($user);

$updateStmt = db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
$updateStmt->execute(['id' => (int) $user['id']]);

redirect('dashboard.php');
