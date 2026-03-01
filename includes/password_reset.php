<?php
declare(strict_types=1);

function password_reset_token_ttl_minutes(): int
{
    $configured = trim((string) getenv('PASSWORD_RESET_TOKEN_TTL_MINUTES'));
    $minutes = is_numeric($configured) ? (int) $configured : 30;
    return max(10, min(240, $minutes));
}

function password_reset_min_password_length(): int
{
    $configured = trim((string) getenv('PASSWORD_RESET_MIN_PASSWORD_LENGTH'));
    $length = is_numeric($configured) ? (int) $configured : 8;
    return max(8, min(64, $length));
}

function password_reset_ensure_table(): bool
{
    static $isReady = null;

    if ($isReady !== null) {
        return $isReady;
    }

    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT(10) UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                requested_ip VARCHAR(45) NULL,
                requested_user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_password_reset_token_hash (token_hash),
                KEY idx_password_reset_user_id (user_id),
                KEY idx_password_reset_expires_at (expires_at),
                CONSTRAINT fk_password_reset_user
                    FOREIGN KEY (user_id) REFERENCES users(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $isReady = true;
    } catch (Throwable $exception) {
        $isReady = false;
    }

    return $isReady;
}

function password_reset_normalize_email(string $email): string
{
    return strtolower(trim(mb_substr($email, 0, 150)));
}

function password_reset_mask_email(string $email): string
{
    $email = password_reset_normalize_email($email);
    if ($email === '' || !str_contains($email, '@')) {
        return '';
    }

    [$local, $domain] = explode('@', $email, 2);
    if ($local === '' || $domain === '') {
        return '';
    }

    $localVisible = mb_substr($local, 0, 1);
    $domainParts = explode('.', $domain);
    $domainLabel = (string) ($domainParts[0] ?? '');
    $domainVisible = mb_substr($domainLabel, 0, 1);
    $domainTld = count($domainParts) > 1 ? '.' . end($domainParts) : '';

    return $localVisible . str_repeat('*', max(1, mb_strlen($local) - 1))
        . '@'
        . $domainVisible . str_repeat('*', max(1, mb_strlen($domainLabel) - 1))
        . $domainTld;
}

function password_reset_is_valid_token_format(string $token): bool
{
    return preg_match('/^[a-f0-9]{64}$/', $token) === 1;
}

function password_reset_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function password_reset_absolute_url(string $path): string
{
    $relative = url($path);
    if (preg_match('/^https?:\/\//i', $relative) === 1) {
        return $relative;
    }

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return $relative;
    }

    $https = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));
    $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host . $relative;
}

function password_reset_find_eligible_user(string $email): ?array
{
    $stmt = db()->prepare(
        'SELECT u.id, u.company_id, u.primary_garage_id, u.name, u.email
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE u.email = :email
           AND u.is_active = 1
           AND u.status_code = "ACTIVE"
           AND (r.status_code IS NULL OR r.status_code = "ACTIVE")
         LIMIT 1'
    );
    $stmt->execute(['email' => $email]);

    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function password_reset_send_email(array $user, string $token): bool
{
    $email = (string) ($user['email'] ?? '');
    if ($email === '') {
        return false;
    }

    $minutes = password_reset_token_ttl_minutes();
    $name = trim((string) ($user['name'] ?? ''));
    $subject = APP_SHORT_NAME . ' Password Reset';
    $resetLink = password_reset_absolute_url('reset_password.php?token=' . urlencode($token));

    $greeting = $name !== '' ? ('Hello ' . $name . ',') : 'Hello,';

    $body = implode("\n", [
        $greeting,
        '',
        'We received a request to reset your password for ' . APP_SHORT_NAME . '.',
        'Use the link below to set a new password:',
        $resetLink,
        '',
        'This link will expire in ' . $minutes . ' minutes and can be used only once.',
        'If you did not request this reset, you can safely ignore this email.',
    ]);

    return app_mail_send_plaintext($email, $subject, $body);
}

function password_reset_recently_requested(int $userId, int $cooldownSeconds = 60): bool
{
    if ($userId <= 0 || $cooldownSeconds <= 0) {
        return false;
    }

    $stmt = db()->prepare(
        'SELECT created_at
         FROM password_reset_tokens
         WHERE user_id = :user_id
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute(['user_id' => $userId]);
    $createdAt = $stmt->fetchColumn();
    if (!is_string($createdAt) || $createdAt === '') {
        return false;
    }

    $createdAtTs = strtotime($createdAt);
    if ($createdAtTs === false) {
        return false;
    }

    return (time() - $createdAtTs) < $cooldownSeconds;
}

function password_reset_request(string $email): void
{
    $normalizedEmail = password_reset_normalize_email($email);
    $maskedEmail = password_reset_mask_email($normalizedEmail);

    if ($normalizedEmail === '' || filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) === false) {
        return;
    }

    if (!password_reset_ensure_table()) {
        log_audit('auth', 'password_reset_unavailable', null, 'Password reset table unavailable.', [
            'entity' => 'password_reset',
            'source' => 'UI',
            'metadata' => ['email' => $maskedEmail],
        ]);
        return;
    }

    $user = password_reset_find_eligible_user($normalizedEmail);
    if ($user === null) {
        log_audit('auth', 'password_reset_requested_unknown', null, 'Password reset requested for unknown email.', [
            'entity' => 'password_reset',
            'source' => 'UI',
            'metadata' => ['email' => $maskedEmail],
        ]);
        return;
    }

    $userId = (int) $user['id'];
    $companyId = (int) ($user['company_id'] ?? 0);
    $garageId = (int) ($user['primary_garage_id'] ?? 0);

    if (password_reset_recently_requested($userId, 60)) {
        log_audit('auth', 'password_reset_rate_limited', $userId, 'Password reset request throttled.', [
            'entity' => 'password_reset',
            'source' => 'UI',
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'user_id' => $userId,
            'metadata' => ['email' => password_reset_mask_email((string) ($user['email'] ?? ''))],
        ]);
        return;
    }

    try {
        $token = bin2hex(random_bytes(32));
    } catch (Throwable $exception) {
        log_audit('auth', 'password_reset_request_failed', $userId, 'Password reset token generation failed.', [
            'entity' => 'password_reset',
            'source' => 'UI',
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'user_id' => $userId,
            'metadata' => ['email' => password_reset_mask_email((string) ($user['email'] ?? ''))],
        ]);
        return;
    }

    $tokenHash = password_reset_token_hash($token);
    $expiresAt = date('Y-m-d H:i:s', time() + (password_reset_token_ttl_minutes() * 60));
    $requestIp = request_client_ip();
    $requestUa = request_user_agent();

    try {
        $pdo = db();
        $pdo->beginTransaction();

        $invalidateStmt = $pdo->prepare(
            'UPDATE password_reset_tokens
             SET used_at = NOW()
             WHERE user_id = :user_id
               AND used_at IS NULL'
        );
        $invalidateStmt->execute(['user_id' => $userId]);

        $insertStmt = $pdo->prepare(
            'INSERT INTO password_reset_tokens
              (user_id, token_hash, expires_at, requested_ip, requested_user_agent)
             VALUES
              (:user_id, :token_hash, :expires_at, :requested_ip, :requested_user_agent)'
        );
        $insertStmt->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'requested_ip' => $requestIp !== '' ? $requestIp : null,
            'requested_user_agent' => $requestUa !== '' ? mb_substr($requestUa, 0, 255) : null,
        ]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        log_audit('auth', 'password_reset_request_failed', $userId, 'Password reset token creation failed.', [
            'entity' => 'password_reset',
            'source' => 'UI',
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'user_id' => $userId,
            'metadata' => ['email' => password_reset_mask_email((string) ($user['email'] ?? ''))],
        ]);
        return;
    }

    $mailSent = password_reset_send_email($user, $token);
    $action = $mailSent ? 'password_reset_email_sent' : 'password_reset_email_failed';
    $details = $mailSent
        ? 'Password reset email sent.'
        : 'Password reset token created, but email delivery failed.';

    log_audit('auth', $action, $userId, $details, [
        'entity' => 'password_reset',
        'source' => 'UI',
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'user_id' => $userId,
        'metadata' => ['email' => password_reset_mask_email((string) ($user['email'] ?? ''))],
    ]);
}

function password_reset_get_valid_request(string $token): ?array
{
    $token = strtolower(trim($token));
    if ($token === '' || !password_reset_is_valid_token_format($token)) {
        return null;
    }

    if (!password_reset_ensure_table()) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT prt.id, prt.user_id, prt.expires_at, u.company_id, u.primary_garage_id, u.email
         FROM password_reset_tokens prt
         INNER JOIN users u ON u.id = prt.user_id
         WHERE prt.token_hash = :token_hash
           AND prt.used_at IS NULL
           AND prt.expires_at >= NOW()
           AND u.is_active = 1
           AND u.status_code = "ACTIVE"
         LIMIT 1'
    );
    $stmt->execute(['token_hash' => password_reset_token_hash($token)]);
    $request = $stmt->fetch();

    return is_array($request) ? $request : null;
}

function password_reset_apply_new_password(string $token, string $newPassword): bool
{
    $token = strtolower(trim($token));
    if (!password_reset_is_valid_token_format($token)) {
        return false;
    }

    if (mb_strlen($newPassword) < password_reset_min_password_length()) {
        return false;
    }

    if (!password_reset_ensure_table()) {
        return false;
    }

    $tokenHash = password_reset_token_hash($token);
    $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);

    $context = null;
    try {
        $pdo = db();
        $pdo->beginTransaction();

        $selectStmt = $pdo->prepare(
            'SELECT prt.id, prt.user_id, u.company_id, u.primary_garage_id
             FROM password_reset_tokens prt
             INNER JOIN users u ON u.id = prt.user_id
             WHERE prt.token_hash = :token_hash
               AND prt.used_at IS NULL
               AND prt.expires_at >= NOW()
               AND u.is_active = 1
               AND u.status_code = "ACTIVE"
             LIMIT 1
             FOR UPDATE'
        );
        $selectStmt->execute(['token_hash' => $tokenHash]);
        $context = $selectStmt->fetch();

        if (!is_array($context)) {
            $pdo->rollBack();
            return false;
        }

        $userUpdateStmt = $pdo->prepare(
            'UPDATE users
             SET password_hash = :password_hash,
                 updated_at = NOW()
             WHERE id = :user_id'
        );
        $userUpdateStmt->execute([
            'password_hash' => $newPasswordHash,
            'user_id' => (int) $context['user_id'],
        ]);

        $tokenUpdateStmt = $pdo->prepare(
            'UPDATE password_reset_tokens
             SET used_at = NOW()
             WHERE user_id = :user_id
               AND used_at IS NULL'
        );
        $tokenUpdateStmt->execute(['user_id' => (int) $context['user_id']]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }

    log_audit('auth', 'password_reset_completed', (int) $context['user_id'], 'Password reset completed successfully.', [
        'entity' => 'password_reset',
        'source' => 'UI',
        'company_id' => (int) ($context['company_id'] ?? 0),
        'garage_id' => (int) ($context['primary_garage_id'] ?? 0),
        'user_id' => (int) $context['user_id'],
        'before' => ['password_changed' => false],
        'after' => ['password_changed' => true],
    ]);

    return true;
}
