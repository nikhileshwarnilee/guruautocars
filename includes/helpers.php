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

function table_columns(string $tableName): array
{
    static $cache = [];

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
        return [];
    }

    if (isset($cache[$tableName])) {
        return $cache[$tableName];
    }

    try {
        $stmt = db()->query('SHOW COLUMNS FROM `' . $tableName . '`');
        $columns = [];
        foreach ($stmt->fetchAll() as $row) {
            $field = (string) ($row['Field'] ?? '');
            if ($field !== '') {
                $columns[] = $field;
            }
        }
        $cache[$tableName] = $columns;
    } catch (Throwable $exception) {
        $cache[$tableName] = [];
    }

    return $cache[$tableName];
}

function table_insert_available_columns(string $tableName, array $payload): bool
{
    $columns = table_columns($tableName);
    if (empty($columns)) {
        return false;
    }

    $insertColumns = [];
    $params = [];
    foreach ($payload as $column => $value) {
        if (!in_array($column, $columns, true)) {
            continue;
        }

        $insertColumns[] = $column;
        $params[$column] = $value;
    }

    if (empty($insertColumns)) {
        return false;
    }

    $placeholders = array_map(static fn (string $column): string => ':' . $column, $insertColumns);
    $sql =
        'INSERT INTO `' . $tableName . '` (' . implode(', ', $insertColumns) . ')
         VALUES (' . implode(', ', $placeholders) . ')';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return true;
}

function audit_request_id(): string
{
    static $requestId = null;

    if (is_string($requestId) && $requestId !== '') {
        return $requestId;
    }

    try {
        $requestId = bin2hex(random_bytes(16));
    } catch (Throwable $exception) {
        $requestId = hash('sha256', microtime(true) . '|' . (string) mt_rand() . '|' . (string) getmypid());
    }

    return $requestId;
}

function audit_default_source_channel(): string
{
    if (PHP_SAPI === 'cli') {
        return 'SYSTEM';
    }

    return 'UI';
}

function audit_light_snapshot(?array $snapshot, int $maxFields = 24): ?array
{
    if (!is_array($snapshot) || $snapshot === []) {
        return null;
    }

    $result = [];
    $fieldCount = 0;

    foreach ($snapshot as $key => $value) {
        if ($fieldCount >= $maxFields) {
            $result['_truncated'] = true;
            break;
        }

        $safeKey = mb_substr((string) $key, 0, 80);
        if ($safeKey === '') {
            continue;
        }

        if (is_scalar($value) || $value === null) {
            $result[$safeKey] = $value;
            $fieldCount++;
            continue;
        }

        if (is_array($value)) {
            $nested = [];
            $nestedCount = 0;
            foreach ($value as $nestedKey => $nestedValue) {
                if ($nestedCount >= 10) {
                    $nested['_truncated'] = true;
                    break;
                }
                if (is_scalar($nestedValue) || $nestedValue === null) {
                    $nested[mb_substr((string) $nestedKey, 0, 60)] = $nestedValue;
                    $nestedCount++;
                }
            }
            $result[$safeKey] = $nested === [] ? '[complex]' : $nested;
            $fieldCount++;
            continue;
        }

        $result[$safeKey] = '[complex]';
        $fieldCount++;
    }

    return $result === [] ? null : $result;
}

function audit_snapshot_changes(?array $before, ?array $after, int $maxFields = 24): array
{
    $beforeSafe = audit_light_snapshot($before, $maxFields) ?? [];
    $afterSafe = audit_light_snapshot($after, $maxFields) ?? [];

    $allKeys = array_values(array_unique(array_merge(array_keys($beforeSafe), array_keys($afterSafe))));
    $changes = [];
    $changeCount = 0;

    foreach ($allKeys as $key) {
        $from = $beforeSafe[$key] ?? null;
        $to = $afterSafe[$key] ?? null;
        if ($from === $to) {
            continue;
        }

        if ($changeCount >= $maxFields) {
            $changes['_truncated'] = true;
            break;
        }

        $changes[$key] = [
            'from' => $from,
            'to' => $to,
        ];
        $changeCount++;
    }

    return $changes;
}

function log_audit(
    string $module,
    string $action,
    ?int $referenceId = null,
    ?string $details = null,
    array $context = []
): void
{
    try {
        $beforeRaw = $context['before'] ?? $context['before_snapshot'] ?? null;
        $afterRaw = $context['after'] ?? $context['after_snapshot'] ?? null;
        $beforeSnapshot = is_array($beforeRaw) ? audit_light_snapshot($beforeRaw) : null;
        $afterSnapshot = is_array($afterRaw) ? audit_light_snapshot($afterRaw) : null;

        $metadata = [];
        $metadataContext = $context['metadata'] ?? null;
        if (is_array($metadataContext)) {
            $metadata = $metadataContext;
        }

        $changes = audit_snapshot_changes($beforeSnapshot, $afterSnapshot);
        if ($changes !== []) {
            $metadata['changes'] = $changes;
        }

        $sourceChannel = strtoupper(trim((string) ($context['source_channel'] ?? $context['source'] ?? audit_default_source_channel())));
        if ($sourceChannel === '') {
            $sourceChannel = 'UI';
        }

        $companyId = (int) ($context['company_id'] ?? active_company_id());
        $garageId = (int) ($context['garage_id'] ?? active_garage_id());
        $userId = isset($context['user_id']) ? (int) $context['user_id'] : (int) ($_SESSION['user_id'] ?? 0);
        $roleKey = trim((string) ($context['role_key'] ?? ($_SESSION['role_key'] ?? '')));
        $entityName = trim((string) ($context['entity_name'] ?? $context['entity'] ?? $module));
        $requestId = trim((string) ($context['request_id'] ?? audit_request_id()));
        $ipAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

        $payload = [
            'company_id' => $companyId > 0 ? $companyId : null,
            'garage_id' => $garageId > 0 ? $garageId : null,
            'user_id' => $userId > 0 ? $userId : null,
            'role_key' => $roleKey !== '' ? mb_substr($roleKey, 0, 50) : null,
            'module_name' => mb_substr($module, 0, 80),
            'entity_name' => $entityName !== '' ? mb_substr($entityName, 0, 80) : null,
            'action_name' => mb_substr($action, 0, 80),
            'source_channel' => mb_substr($sourceChannel, 0, 40),
            'reference_id' => $referenceId,
            'ip_address' => $ipAddress !== '' ? mb_substr($ipAddress, 0, 45) : null,
            'details' => $details !== null ? mb_substr($details, 0, 10000) : null,
            'before_snapshot' => $beforeSnapshot !== null ? json_encode($beforeSnapshot, JSON_UNESCAPED_UNICODE) : null,
            'after_snapshot' => $afterSnapshot !== null ? json_encode($afterSnapshot, JSON_UNESCAPED_UNICODE) : null,
            'metadata_json' => $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            'request_id' => $requestId !== '' ? mb_substr($requestId, 0, 64) : null,
        ];

        table_insert_available_columns('audit_logs', $payload);
    } catch (Throwable $exception) {
        // Audit logging should never break business flow.
    }
}

function log_data_export(
    string $moduleKey,
    string $formatKey,
    int $rowCount,
    array $context = []
): void {
    try {
        $companyId = (int) ($context['company_id'] ?? active_company_id());
        $garageId = (int) ($context['garage_id'] ?? active_garage_id());
        $payload = [
            'company_id' => $companyId > 0 ? $companyId : null,
            'garage_id' => $garageId > 0 ? $garageId : null,
            'module_key' => mb_substr($moduleKey, 0, 60),
            'format_key' => mb_substr(strtoupper(trim($formatKey)), 0, 20),
            'row_count' => max(0, $rowCount),
            'include_draft' => !empty($context['include_draft']) ? 1 : 0,
            'include_cancelled' => !empty($context['include_cancelled']) ? 1 : 0,
            'filter_summary' => isset($context['filter_summary']) ? mb_substr((string) $context['filter_summary'], 0, 255) : null,
            'scope_json' => isset($context['scope']) && is_array($context['scope']) ? json_encode($context['scope'], JSON_UNESCAPED_UNICODE) : null,
            'requested_by' => isset($context['requested_by']) ? (int) $context['requested_by'] : ((int) ($_SESSION['user_id'] ?? 0) ?: null),
        ];
        table_insert_available_columns('data_export_logs', $payload);
    } catch (Throwable $exception) {
        // Export logging must never block business flow.
    }
}

function log_backup_run(array $context): void
{
    try {
        $companyId = (int) ($context['company_id'] ?? active_company_id());
        $payload = [
            'company_id' => $companyId > 0 ? $companyId : null,
            'backup_type' => mb_substr(strtoupper(trim((string) ($context['backup_type'] ?? 'MANUAL'))), 0, 20),
            'backup_label' => mb_substr((string) ($context['backup_label'] ?? 'Manual backup'), 0, 140),
            'dump_file_name' => mb_substr((string) ($context['dump_file_name'] ?? 'unknown.sql'), 0, 255),
            'file_size_bytes' => max(0, (int) ($context['file_size_bytes'] ?? 0)),
            'checksum_sha256' => isset($context['checksum_sha256']) ? mb_substr((string) $context['checksum_sha256'], 0, 128) : null,
            'dump_started_at' => $context['dump_started_at'] ?? null,
            'dump_completed_at' => $context['dump_completed_at'] ?? null,
            'status_code' => mb_substr(strtoupper(trim((string) ($context['status_code'] ?? 'SUCCESS'))), 0, 20),
            'notes' => isset($context['notes']) ? mb_substr((string) $context['notes'], 0, 255) : null,
            'created_by' => isset($context['created_by']) ? (int) $context['created_by'] : ((int) ($_SESSION['user_id'] ?? 0) ?: null),
        ];
        table_insert_available_columns('backup_runs', $payload);
    } catch (Throwable $exception) {
        // Backup metadata logging must never block business flow.
    }
}

function log_backup_integrity_check(array $context): void
{
    try {
        $companyId = (int) ($context['company_id'] ?? active_company_id());
        $summaryJson = null;
        if (isset($context['summary']) && is_array($context['summary'])) {
            $summaryJson = json_encode($context['summary'], JSON_UNESCAPED_UNICODE);
        }

        $payload = [
            'company_id' => $companyId > 0 ? $companyId : null,
            'result_code' => mb_substr(strtoupper(trim((string) ($context['result_code'] ?? 'PASS'))), 0, 20),
            'issues_count' => max(0, (int) ($context['issues_count'] ?? 0)),
            'summary_json' => $summaryJson,
            'checked_by' => isset($context['checked_by']) ? (int) $context['checked_by'] : ((int) ($_SESSION['user_id'] ?? 0) ?: null),
        ];
        table_insert_available_columns('backup_integrity_checks', $payload);
    } catch (Throwable $exception) {
        // Integrity logging must never block business flow.
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
