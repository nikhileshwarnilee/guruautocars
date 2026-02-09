<?php
declare(strict_types=1);

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function login_user(array $user): void
{
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['company_id'] = (int) $user['company_id'];
    $_SESSION['role_key'] = (string) $user['role_key'];
    $_SESSION['active_garage_id'] = isset($user['primary_garage_id']) ? (int) $user['primary_garage_id'] : 0;
}

function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }

    session_destroy();
}

function require_login(): void
{
    if (!is_logged_in()) {
        flash_set('auth', 'Please sign in to continue.', 'warning');
        redirect('login.php');
    }
}

function current_user(bool $refresh = false): ?array
{
    static $cachedUser = null;

    if ($refresh) {
        $cachedUser = null;
    }

    if (!is_logged_in()) {
        return null;
    }

    if (is_array($cachedUser)) {
        return $cachedUser;
    }

    $stmt = db()->prepare(
        'SELECT u.id, u.company_id, u.primary_garage_id, u.name, u.email, u.username, u.phone, u.is_active,
                u.status_code,
                r.role_key, r.role_name,
                g.name AS primary_garage_name,
                c.name AS company_name
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         INNER JOIN companies c ON c.id = u.company_id
         LEFT JOIN garages g ON g.id = u.primary_garage_id
         WHERE u.id = :user_id
           AND u.is_active = 1
           AND u.status_code = "ACTIVE"
           AND (r.status_code IS NULL OR r.status_code = "ACTIVE")
           AND (c.status_code IS NULL OR c.status_code = "ACTIVE")
         LIMIT 1'
    );
    $stmt->execute(['user_id' => (int) $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        logout_user();
        return null;
    }

    $garageStmt = db()->prepare(
        'SELECT g.id, g.name, g.code
         FROM user_garages ug
         INNER JOIN garages g ON g.id = ug.garage_id
         WHERE ug.user_id = :user_id
           AND (g.status = "active" OR g.status IS NULL)
           AND (g.status_code IS NULL OR g.status_code = "ACTIVE")
         ORDER BY g.name ASC'
    );
    $garageStmt->execute(['user_id' => (int) $user['id']]);
    $garages = $garageStmt->fetchAll();

    $user['garages'] = $garages;
    $cachedUser = $user;

    if (empty($_SESSION['active_garage_id'])) {
        if (!empty($user['primary_garage_id'])) {
            $_SESSION['active_garage_id'] = (int) $user['primary_garage_id'];
        } elseif (!empty($garages)) {
            $_SESSION['active_garage_id'] = (int) $garages[0]['id'];
        }
    }

    return $cachedUser;
}

function active_company_id(): int
{
    return (int) ($_SESSION['company_id'] ?? 0);
}

function active_garage_id(): int
{
    return (int) ($_SESSION['active_garage_id'] ?? 0);
}

function set_active_garage(int $garageId): bool
{
    if ($garageId <= 0 || !is_logged_in()) {
        return false;
    }

    $stmt = db()->prepare(
        'SELECT COUNT(*)
         FROM user_garages
         INNER JOIN garages g ON g.id = user_garages.garage_id
         WHERE user_id = :user_id
           AND garage_id = :garage_id
           AND (g.status = "active" OR g.status IS NULL)
           AND (g.status_code IS NULL OR g.status_code = "ACTIVE")'
    );
    $stmt->execute([
        'user_id' => (int) $_SESSION['user_id'],
        'garage_id' => $garageId,
    ]);

    if ((int) $stmt->fetchColumn() === 0) {
        return false;
    }

    $_SESSION['active_garage_id'] = $garageId;
    return true;
}

function user_permissions(bool $refresh = false): array
{
    static $cachedPermissions = null;

    if ($refresh) {
        $cachedPermissions = null;
    }

    if (!is_logged_in()) {
        return [];
    }

    if (is_array($cachedPermissions)) {
        return $cachedPermissions;
    }

    $roleKey = (string) ($_SESSION['role_key'] ?? '');
    if ($roleKey === 'super_admin') {
        $cachedPermissions = ['*'];
        return $cachedPermissions;
    }

    $stmt = db()->prepare(
        'SELECT p.perm_key
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         INNER JOIN role_permissions rp ON rp.role_id = u.role_id
         INNER JOIN permissions p ON p.id = rp.permission_id
         WHERE u.id = :user_id
           AND u.status_code = "ACTIVE"
           AND r.status_code = "ACTIVE"
           AND p.status_code = "ACTIVE"'
    );
    $stmt->execute(['user_id' => (int) $_SESSION['user_id']]);

    $permissions = [];
    foreach ($stmt->fetchAll() as $row) {
        $permissions[] = (string) $row['perm_key'];
    }

    $cachedPermissions = $permissions;
    return $cachedPermissions;
}

function has_permission(string $permission): bool
{
    $permissions = user_permissions();

    if (in_array('*', $permissions, true)) {
        return true;
    }

    return in_array($permission, $permissions, true);
}

function require_permission(string $permission): void
{
    if (!has_permission($permission)) {
        flash_set('access_denied', 'You do not have permission to access this section.', 'danger');
        redirect('dashboard.php');
    }
}

function is_active_menu(string $menuKey, string $activeMenu): string
{
    return $menuKey === $activeMenu ? 'active' : '';
}

function is_menu_group_open(string $prefix, string $activeMenu): bool
{
    return str_starts_with($activeMenu, $prefix);
}
