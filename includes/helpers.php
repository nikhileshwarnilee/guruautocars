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

function normalize_status_code(?string $status): string
{
    $value = strtoupper(trim((string) $status));
    return in_array($value, ['ACTIVE', 'INACTIVE', 'DELETED'], true) ? $value : 'ACTIVE';
}

function status_badge_class(string $status): string
{
    return match (normalize_status_code($status)) {
        'ACTIVE' => 'success',
        'INACTIVE' => 'warning',
        'DELETED' => 'danger',
        default => 'secondary',
    };
}

function record_status_label(string $status): string
{
    return normalize_status_code($status);
}

function status_options(?string $current = null): array
{
    $options = ['ACTIVE', 'INACTIVE', 'DELETED'];
    $currentStatus = normalize_status_code($current);
    $result = [];

    foreach ($options as $status) {
        $result[] = [
            'value' => $status,
            'selected' => $status === $currentStatus,
        ];
    }

    return $result;
}

function log_audit(string $module, string $action, ?int $referenceId = null, ?string $details = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO audit_logs
              (company_id, user_id, module_name, action_name, reference_id, ip_address, details)
             VALUES
              (:company_id, :user_id, :module_name, :action_name, :reference_id, :ip_address, :details)'
        );
        $stmt->execute([
            'company_id' => active_company_id() > 0 ? active_company_id() : null,
            'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            'module_name' => $module,
            'action_name' => $action,
            'reference_id' => $referenceId,
            'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? null),
            'details' => $details,
        ]);
    } catch (Throwable $exception) {
        // Audit logging should never break business flow.
    }
}

function add_customer_history(int $customerId, string $actionType, ?string $actionNote = null, ?array $snapshot = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO customer_history
              (customer_id, action_type, action_note, snapshot_json, created_by)
             VALUES
              (:customer_id, :action_type, :action_note, :snapshot_json, :created_by)'
        );
        $stmt->execute([
            'customer_id' => $customerId,
            'action_type' => mb_substr($actionType, 0, 40),
            'action_note' => $actionNote !== null ? mb_substr($actionNote, 0, 255) : null,
            'snapshot_json' => $snapshot !== null ? json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null,
            'created_by' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
        ]);
    } catch (Throwable $exception) {
        // Non-blocking history writer.
    }
}

function add_vehicle_history(int $vehicleId, string $actionType, ?string $actionNote = null, ?array $snapshot = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO vehicle_history
              (vehicle_id, action_type, action_note, snapshot_json, created_by)
             VALUES
              (:vehicle_id, :action_type, :action_note, :snapshot_json, :created_by)'
        );
        $stmt->execute([
            'vehicle_id' => $vehicleId,
            'action_type' => mb_substr($actionType, 0, 40),
            'action_note' => $actionNote !== null ? mb_substr($actionNote, 0, 255) : null,
            'snapshot_json' => $snapshot !== null ? json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null,
            'created_by' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
        ]);
    } catch (Throwable $exception) {
        // Non-blocking history writer.
    }
}
