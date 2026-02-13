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

function is_ajax_request(): bool
{
    $xmlHttpRequest = strtolower(trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
    if ($xmlHttpRequest === 'xmlhttprequest') {
        return true;
    }

    $acceptHeader = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    if ($acceptHeader !== '' && str_contains($acceptHeader, 'application/json')) {
        return true;
    }

    return false;
}

function ajax_json(array $payload, int $statusCode = 200): never
{
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function flash_messages_normalize(array $messages): array
{
    $normalized = [];
    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }

        $type = strtolower(trim((string) ($message['type'] ?? 'info')));
        if (!in_array($type, ['success', 'danger', 'warning', 'info'], true)) {
            $type = 'info';
        }

        $normalized[] = [
            'message' => (string) ($message['message'] ?? ''),
            'type' => $type,
        ];
    }

    return $normalized;
}

function redirect(string $path): never
{
    $location = url($path);

    if (is_ajax_request()) {
        $flashMessages = flash_messages_normalize(flash_pull_all());
        $hasDanger = false;
        foreach ($flashMessages as $message) {
            if (($message['type'] ?? 'info') === 'danger') {
                $hasDanger = true;
                break;
            }
        }

        ajax_json([
            'ok' => !$hasDanger,
            'redirect' => $location,
            'flash' => $flashMessages,
        ], $hasDanger ? 422 : 200);
    }

    header('Location: ' . $location);
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
        if (is_ajax_request()) {
            ajax_json([
                'ok' => false,
                'message' => 'Your session token expired. Refresh and try again.',
                'code' => 'CSRF_INVALID',
            ], 419);
        }

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

function resolve_pagination_request(int $defaultPerPage = 10, int $maxPerPage = 100): array
{
    $defaultPerPage = max(1, $defaultPerPage);
    $maxPerPage = max($defaultPerPage, $maxPerPage);

    $page = max(1, get_int('page', 1));
    $perPage = get_int('per_page', $defaultPerPage);
    if ($perPage <= 0) {
        $perPage = $defaultPerPage;
    }
    $perPage = min($maxPerPage, max(1, $perPage));

    return [
        'page' => $page,
        'per_page' => $perPage,
        'offset' => ($page - 1) * $perPage,
    ];
}

function pagination_payload(int $totalRecords, int $page, int $perPage): array
{
    $totalRecords = max(0, $totalRecords);
    $perPage = max(1, $perPage);
    $totalPages = max(1, (int) ceil($totalRecords / $perPage));
    $page = min(max(1, $page), $totalPages);

    return [
        'page' => $page,
        'per_page' => $perPage,
        'total_records' => $totalRecords,
        'total_pages' => $totalPages,
        'has_prev' => $page > 1,
        'has_next' => $page < $totalPages,
    ];
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

function system_setting_get_value(int $companyId, int $garageId, string $settingKey, ?string $default = null): ?string
{
    if ($companyId <= 0) {
        return $default;
    }

    $settingKey = trim($settingKey);
    if ($settingKey === '') {
        return $default;
    }

    try {
        $garageScope = $garageId > 0 ? $garageId : null;
        $stmt = db()->prepare(
            'SELECT setting_value
             FROM system_settings
             WHERE company_id = :company_id
               AND setting_key = :setting_key
               AND status_code = "ACTIVE"
               AND (garage_id = :garage_id OR garage_id IS NULL)
             ORDER BY CASE WHEN garage_id = :garage_id THEN 0 ELSE 1 END, id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'setting_key' => $settingKey,
            'garage_id' => $garageScope,
        ]);
        $value = $stmt->fetchColumn();
    } catch (Throwable $exception) {
        return $default;
    }

    if ($value === false || $value === null) {
        return $default;
    }

    $normalized = trim((string) $value);
    return $normalized !== '' ? $normalized : $default;
}

function system_setting_upsert_value(
    int $companyId,
    ?int $garageId,
    string $settingGroup,
    string $settingKey,
    ?string $settingValue,
    string $valueType = 'STRING',
    string $statusCode = 'ACTIVE',
    ?int $actorUserId = null
): int {
    if ($companyId <= 0) {
        return 0;
    }

    $settingGroup = strtoupper(trim($settingGroup));
    $settingKey = trim($settingKey);
    if ($settingGroup === '' || $settingKey === '') {
        return 0;
    }

    $valueType = strtoupper(trim($valueType));
    if (!in_array($valueType, ['STRING', 'NUMBER', 'BOOLEAN', 'JSON'], true)) {
        $valueType = 'STRING';
    }

    $statusCode = normalize_status_code($statusCode);
    $garageScope = ($garageId ?? 0) > 0 ? (int) $garageId : null;
    $trimmedValue = $settingValue !== null ? trim($settingValue) : null;
    $storedValue = $trimmedValue !== null && $trimmedValue !== '' ? $trimmedValue : null;

    try {
        $pdo = db();
        $findStmt = $pdo->prepare(
            'SELECT id
             FROM system_settings
             WHERE company_id = :company_id
               AND setting_key = :setting_key
               AND ((garage_id IS NULL AND :garage_id IS NULL) OR garage_id = :garage_id)
             ORDER BY id DESC
             LIMIT 1'
        );
        $findStmt->execute([
            'company_id' => $companyId,
            'setting_key' => $settingKey,
            'garage_id' => $garageScope,
        ]);
        $existingId = (int) ($findStmt->fetchColumn() ?: 0);

        if ($existingId > 0) {
            $updateStmt = $pdo->prepare(
                'UPDATE system_settings
                 SET setting_group = :setting_group,
                     setting_value = :setting_value,
                     value_type = :value_type,
                     status_code = :status_code,
                     deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
                 WHERE id = :id
                   AND company_id = :company_id'
            );
            $updateStmt->execute([
                'setting_group' => $settingGroup,
                'setting_value' => $storedValue,
                'value_type' => $valueType,
                'status_code' => $statusCode,
                'id' => $existingId,
                'company_id' => $companyId,
            ]);
            return $existingId;
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO system_settings
              (company_id, garage_id, setting_group, setting_key, setting_value, value_type, status_code, deleted_at, created_by)
             VALUES
              (:company_id, :garage_id, :setting_group, :setting_key, :setting_value, :value_type, :status_code, :deleted_at, :created_by)'
        );
        $insertStmt->execute([
            'company_id' => $companyId,
            'garage_id' => $garageScope,
            'setting_group' => $settingGroup,
            'setting_key' => $settingKey,
            'setting_value' => $storedValue,
            'value_type' => $valueType,
            'status_code' => $statusCode,
            'deleted_at' => $statusCode === 'DELETED' ? date('Y-m-d H:i:s') : null,
            'created_by' => $actorUserId !== null && $actorUserId > 0 ? $actorUserId : null,
        ]);
        return (int) $pdo->lastInsertId();
    } catch (Throwable $exception) {
        return 0;
    }
}

function date_filter_modes(): array
{
    return [
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
        'yearly' => 'Yearly',
        'custom' => 'Custom',
    ];
}

function date_filter_is_valid_iso(?string $value): bool
{
    $raw = trim((string) $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return false;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $raw));
    return checkdate($month, $day, $year);
}

function date_filter_normalize_mode(?string $value, string $fallback = 'monthly'): string
{
    $normalized = strtolower(trim((string) $value));
    if (array_key_exists($normalized, date_filter_modes())) {
        return $normalized;
    }

    $fallbackNormalized = strtolower(trim($fallback));
    return array_key_exists($fallbackNormalized, date_filter_modes()) ? $fallbackNormalized : 'monthly';
}

function date_filter_clamp(string $date, string $minDate, string $maxDate): string
{
    if ($date < $minDate) {
        return $minDate;
    }
    if ($date > $maxDate) {
        return $maxDate;
    }
    return $date;
}

function date_filter_default_range_for_mode(
    string $mode,
    string $rangeStart,
    string $rangeEnd,
    ?string $yearlyStart = null,
    ?string $customDefaultFrom = null,
    ?string $customDefaultTo = null
): array {
    $start = date_filter_is_valid_iso($rangeStart) ? (string) $rangeStart : date('Y-m-01');
    $end = date_filter_is_valid_iso($rangeEnd) ? (string) $rangeEnd : date('Y-m-d');
    if ($end < $start) {
        $end = $start;
    }

    $today = date('Y-m-d');
    $boundedToday = date_filter_clamp($today, $start, $end);
    $modeKey = date_filter_normalize_mode($mode, 'monthly');
    $fromDate = $start;
    $toDate = $boundedToday;

    if ($modeKey === 'daily') {
        $fromDate = $boundedToday;
        $toDate = $boundedToday;
    } elseif ($modeKey === 'weekly') {
        $weekStart = date('Y-m-d', strtotime($boundedToday . ' -6 days'));
        $fromDate = date_filter_clamp($weekStart, $start, $end);
        $toDate = $boundedToday;
    } elseif ($modeKey === 'monthly') {
        $monthStart = date('Y-m-01', strtotime($boundedToday));
        $fromDate = date_filter_clamp($monthStart, $start, $end);
        $toDate = $boundedToday;
    } elseif ($modeKey === 'yearly') {
        $yearStart = $yearlyStart !== null && date_filter_is_valid_iso($yearlyStart)
            ? (string) $yearlyStart
            : date('Y-01-01', strtotime($boundedToday));
        $fromDate = date_filter_clamp($yearStart, $start, $end);
        $toDate = $boundedToday;
    } else {
        $customFrom = $customDefaultFrom !== null && date_filter_is_valid_iso($customDefaultFrom)
            ? (string) $customDefaultFrom
            : $start;
        $customTo = $customDefaultTo !== null && date_filter_is_valid_iso($customDefaultTo)
            ? (string) $customDefaultTo
            : $boundedToday;
        $fromDate = date_filter_clamp($customFrom, $start, $end);
        $toDate = date_filter_clamp($customTo, $start, $end);
    }

    if ($toDate < $fromDate) {
        $toDate = $fromDate;
    }

    return [
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ];
}

function date_filter_resolve_request(array $config): array
{
    $companyId = (int) ($config['company_id'] ?? 0);
    $garageId = (int) ($config['garage_id'] ?? 0);

    $rangeStart = (string) ($config['range_start'] ?? date('Y-m-01'));
    $rangeEnd = (string) ($config['range_end'] ?? date('Y-m-d'));
    if (!date_filter_is_valid_iso($rangeStart)) {
        $rangeStart = date('Y-m-01');
    }
    if (!date_filter_is_valid_iso($rangeEnd)) {
        $rangeEnd = date('Y-m-d');
    }
    if ($rangeEnd < $rangeStart) {
        $rangeEnd = $rangeStart;
    }

    $systemDefaultMode = date_filter_normalize_mode(
        system_setting_get_value($companyId, $garageId, 'default_date_filter_mode', 'monthly'),
        'monthly'
    );
    $sessionNamespaceRaw = trim((string) ($config['session_namespace'] ?? 'global'));
    $sessionNamespace = preg_replace('/[^a-z0-9_]+/i', '_', $sessionNamespaceRaw) ?: 'global';
    $sessionKey = 'gac_date_filter_mode_' . $companyId . '_' . strtolower($sessionNamespace);
    $sessionFromKey = $sessionKey . '_from';
    $sessionToKey = $sessionKey . '_to';

    $requestedModeRaw = isset($config['request_mode']) ? trim((string) $config['request_mode']) : '';
    $hasRequestedMode = $requestedModeRaw !== '';
    $requestedMode = date_filter_normalize_mode($requestedModeRaw, $systemDefaultMode);
    $sessionMode = isset($_SESSION[$sessionKey]) ? date_filter_normalize_mode((string) $_SESSION[$sessionKey], $systemDefaultMode) : '';
    $resolvedMode = $hasRequestedMode
        ? $requestedMode
        : ($sessionMode !== '' ? $sessionMode : $systemDefaultMode);

    $defaultRange = date_filter_default_range_for_mode(
        $resolvedMode,
        $rangeStart,
        $rangeEnd,
        isset($config['yearly_start']) ? (string) $config['yearly_start'] : null,
        isset($config['custom_default_from']) ? (string) $config['custom_default_from'] : null,
        isset($config['custom_default_to']) ? (string) $config['custom_default_to'] : null
    );

    $requestedFromRaw = isset($config['request_from']) ? trim((string) $config['request_from']) : '';
    $requestedToRaw = isset($config['request_to']) ? trim((string) $config['request_to']) : '';
    $hasRequestedFrom = date_filter_is_valid_iso($requestedFromRaw);
    $hasRequestedTo = date_filter_is_valid_iso($requestedToRaw);
    $sessionFrom = isset($_SESSION[$sessionFromKey]) && date_filter_is_valid_iso((string) $_SESSION[$sessionFromKey])
        ? (string) $_SESSION[$sessionFromKey]
        : '';
    $sessionTo = isset($_SESSION[$sessionToKey]) && date_filter_is_valid_iso((string) $_SESSION[$sessionToKey])
        ? (string) $_SESSION[$sessionToKey]
        : '';
    $useSessionCustomRange = $resolvedMode === 'custom' && !$hasRequestedFrom && !$hasRequestedTo && $sessionFrom !== '' && $sessionTo !== '';

    if ($useSessionCustomRange) {
        $fromDate = $sessionFrom;
        $toDate = $sessionTo;
    } else {
        $fromDate = $hasRequestedFrom ? $requestedFromRaw : (string) $defaultRange['from_date'];
        $toDate = $hasRequestedTo ? $requestedToRaw : (string) $defaultRange['to_date'];
    }

    $fromDate = date_filter_clamp($fromDate, $rangeStart, $rangeEnd);
    $toDate = date_filter_clamp($toDate, $rangeStart, $rangeEnd);
    if ($toDate < $fromDate) {
        $toDate = $fromDate;
    }

    if (!$hasRequestedMode && ($hasRequestedFrom || $hasRequestedTo)) {
        $resolvedMode = 'custom';
    }
    $_SESSION[$sessionKey] = $resolvedMode;
    if ($resolvedMode === 'custom') {
        $_SESSION[$sessionFromKey] = $fromDate;
        $_SESSION[$sessionToKey] = $toDate;
    } else {
        unset($_SESSION[$sessionFromKey], $_SESSION[$sessionToKey]);
    }

    return [
        'mode' => $resolvedMode,
        'default_mode' => $systemDefaultMode,
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'range_start' => $rangeStart,
        'range_end' => $rangeEnd,
    ];
}

function company_logo_relative_path(int $companyId, int $garageId = 0): ?string
{
    $rawPath = system_setting_get_value($companyId, $garageId, 'business_logo_path', null);
    if ($rawPath === null) {
        return null;
    }

    $normalized = str_replace('\\', '/', trim($rawPath));
    $normalized = ltrim($normalized, '/');
    if ($normalized === '' || str_contains($normalized, '..')) {
        return null;
    }
    if (!str_starts_with($normalized, 'assets/uploads/company_logos/')) {
        return null;
    }

    return $normalized;
}

function company_logo_fs_path(int $companyId, int $garageId = 0): ?string
{
    $relative = company_logo_relative_path($companyId, $garageId);
    if ($relative === null) {
        return null;
    }

    $fullPath = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    return is_file($fullPath) ? $fullPath : null;
}

function company_logo_url(int $companyId, int $garageId = 0): ?string
{
    $relative = company_logo_relative_path($companyId, $garageId);
    if ($relative === null) {
        return null;
    }

    return company_logo_fs_path($companyId, $garageId) !== null ? url($relative) : null;
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

function vehicle_master_normalize_text(string $value, int $maxLength): string
{
    $normalized = preg_replace('/\s+/u', ' ', trim($value));
    if ($normalized === null) {
        $normalized = trim($value);
    }

    return mb_substr($normalized, 0, $maxLength);
}

function vehicle_masters_enabled(): bool
{
    return table_columns('vehicle_brands') !== []
        && table_columns('vehicle_models') !== []
        && table_columns('vehicle_variants') !== []
        && table_columns('vehicle_model_years') !== []
        && table_columns('vehicle_colors') !== [];
}

function vehicle_master_link_columns_supported(): bool
{
    $columns = table_columns('vehicles');
    $required = ['brand_id', 'model_id', 'variant_id', 'model_year_id', 'color_id'];
    foreach ($required as $column) {
        if (!in_array($column, $columns, true)) {
            return false;
        }
    }

    return true;
}

function vehicle_master_get_brand(int $brandId): ?array
{
    if ($brandId <= 0 || !vehicle_masters_enabled()) {
        return null;
    }

    try {
        $stmt = db()->prepare(
            'SELECT id, brand_name, vis_brand_id
             FROM vehicle_brands
             WHERE id = :id
               AND status_code = "ACTIVE"
             LIMIT 1'
        );
        $stmt->execute(['id' => $brandId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $exception) {
        return null;
    }
}

function vehicle_master_get_model(int $modelId, ?int $brandId = null): ?array
{
    if ($modelId <= 0 || !vehicle_masters_enabled()) {
        return null;
    }

    try {
        $where = ['vm.id = :id', 'vm.status_code = "ACTIVE"', 'vb.status_code = "ACTIVE"'];
        $params = ['id' => $modelId];
        if ($brandId !== null && $brandId > 0) {
            $where[] = 'vm.brand_id = :brand_id';
            $params['brand_id'] = $brandId;
        }

        $stmt = db()->prepare(
            'SELECT vm.id, vm.brand_id, vm.model_name, vm.vehicle_type, vm.vis_model_id, vb.brand_name
             FROM vehicle_models vm
             INNER JOIN vehicle_brands vb ON vb.id = vm.brand_id
             WHERE ' . implode(' AND ', $where) . '
             LIMIT 1'
        );
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $exception) {
        return null;
    }
}

function vehicle_master_get_variant(int $variantId, ?int $modelId = null): ?array
{
    if ($variantId <= 0 || !vehicle_masters_enabled()) {
        return null;
    }

    try {
        $where = ['vv.id = :id', 'vv.status_code = "ACTIVE"', 'vm.status_code = "ACTIVE"', 'vb.status_code = "ACTIVE"'];
        $params = ['id' => $variantId];
        if ($modelId !== null && $modelId > 0) {
            $where[] = 'vv.model_id = :model_id';
            $params['model_id'] = $modelId;
        }

        $stmt = db()->prepare(
            'SELECT vv.id, vv.model_id, vv.variant_name, vv.fuel_type, vv.engine_cc, vv.vis_variant_id,
                    vm.model_name, vm.brand_id, vb.brand_name
             FROM vehicle_variants vv
             INNER JOIN vehicle_models vm ON vm.id = vv.model_id
             INNER JOIN vehicle_brands vb ON vb.id = vm.brand_id
             WHERE ' . implode(' AND ', $where) . '
             LIMIT 1'
        );
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $exception) {
        return null;
    }
}

function vehicle_master_get_year(int $yearId): ?array
{
    if ($yearId <= 0 || !vehicle_masters_enabled()) {
        return null;
    }

    try {
        $stmt = db()->prepare(
            'SELECT id, year_value
             FROM vehicle_model_years
             WHERE id = :id
               AND status_code = "ACTIVE"
             LIMIT 1'
        );
        $stmt->execute(['id' => $yearId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $exception) {
        return null;
    }
}

function vehicle_master_get_color(int $colorId): ?array
{
    if ($colorId <= 0 || !vehicle_masters_enabled()) {
        return null;
    }

    try {
        $stmt = db()->prepare(
            'SELECT id, color_name
             FROM vehicle_colors
             WHERE id = :id
               AND status_code = "ACTIVE"
             LIMIT 1'
        );
        $stmt->execute(['id' => $colorId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $exception) {
        return null;
    }
}

function vehicle_master_ensure_brand(string $brandName, ?int $visBrandId = null): ?array
{
    if (!vehicle_masters_enabled()) {
        return null;
    }

    $brandName = vehicle_master_normalize_text($brandName, 100);
    if ($brandName === '') {
        return null;
    }

    try {
        $selectStmt = db()->prepare('SELECT id FROM vehicle_brands WHERE brand_name = :brand_name LIMIT 1');
        $selectStmt->execute(['brand_name' => $brandName]);
        $existingId = (int) ($selectStmt->fetchColumn() ?: 0);

        if ($existingId > 0) {
            $updateStmt = db()->prepare(
                'UPDATE vehicle_brands
                 SET status_code = "ACTIVE",
                     deleted_at = NULL,
                     vis_brand_id = CASE
                         WHEN vis_brand_id IS NULL AND :vis_brand_id > 0 THEN :vis_brand_id
                         ELSE vis_brand_id
                     END
                 WHERE id = :id'
            );
            $updateStmt->execute([
                'id' => $existingId,
                'vis_brand_id' => $visBrandId !== null ? (int) $visBrandId : 0,
            ]);

            return vehicle_master_get_brand($existingId);
        }

        $insertStmt = db()->prepare(
            'INSERT INTO vehicle_brands (brand_name, vis_brand_id, source_code, status_code, deleted_at)
             VALUES (:brand_name, :vis_brand_id, :source_code, "ACTIVE", NULL)'
        );
        $insertStmt->execute([
            'brand_name' => $brandName,
            'vis_brand_id' => $visBrandId !== null && $visBrandId > 0 ? $visBrandId : null,
            'source_code' => $visBrandId !== null && $visBrandId > 0 ? 'VIS' : 'MANUAL',
        ]);

        return vehicle_master_get_brand((int) db()->lastInsertId());
    } catch (Throwable $exception) {
        return null;
    }
}

function vehicle_master_ensure_model(
    int $brandId,
    string $modelName,
    ?string $vehicleType = null,
    ?int $visModelId = null
): ?array {
    if (!vehicle_masters_enabled() || $brandId <= 0) {
        return null;
    }

    $modelName = vehicle_master_normalize_text($modelName, 120);
    if ($modelName === '') {
        return null;
    }

    $vehicleType = strtoupper(trim((string) $vehicleType));
    if (!in_array($vehicleType, ['2W', '4W', 'COMMERCIAL'], true)) {
        $vehicleType = null;
    }

    try {
        $selectStmt = db()->prepare(
            'SELECT id
             FROM vehicle_models
             WHERE brand_id = :brand_id
               AND model_name = :model_name
             LIMIT 1'
        );
        $selectStmt->execute([
            'brand_id' => $brandId,
            'model_name' => $modelName,
        ]);
        $existingId = (int) ($selectStmt->fetchColumn() ?: 0);

        if ($existingId > 0) {
            $updateStmt = db()->prepare(
                'UPDATE vehicle_models
                 SET status_code = "ACTIVE",
                     deleted_at = NULL,
                     vehicle_type = COALESCE(vehicle_type, :vehicle_type),
                     vis_model_id = CASE
                         WHEN vis_model_id IS NULL AND :vis_model_id > 0 THEN :vis_model_id
                         ELSE vis_model_id
                     END
                 WHERE id = :id'
            );
            $updateStmt->execute([
                'id' => $existingId,
                'vehicle_type' => $vehicleType,
                'vis_model_id' => $visModelId !== null ? (int) $visModelId : 0,
            ]);

            return vehicle_master_get_model($existingId, $brandId);
        }

        $insertStmt = db()->prepare(
            'INSERT INTO vehicle_models
              (brand_id, model_name, vehicle_type, vis_model_id, source_code, status_code, deleted_at)
             VALUES
              (:brand_id, :model_name, :vehicle_type, :vis_model_id, :source_code, "ACTIVE", NULL)'
        );
        $insertStmt->execute([
            'brand_id' => $brandId,
            'model_name' => $modelName,
            'vehicle_type' => $vehicleType,
            'vis_model_id' => $visModelId !== null && $visModelId > 0 ? $visModelId : null,
            'source_code' => $visModelId !== null && $visModelId > 0 ? 'VIS' : 'MANUAL',
        ]);

        return vehicle_master_get_model((int) db()->lastInsertId(), $brandId);
    } catch (Throwable $exception) {
        return null;
    }
}

function vehicle_master_ensure_variant(
    int $modelId,
    string $variantName,
    ?string $fuelType = null,
    ?string $engineCc = null,
    ?int $visVariantId = null
): ?array {
    if (!vehicle_masters_enabled() || $modelId <= 0) {
        return null;
    }

    $variantName = vehicle_master_normalize_text($variantName, 150);
    if ($variantName === '') {
        return null;
    }

    $fuelType = strtoupper(trim((string) $fuelType));
    if (!in_array($fuelType, ['PETROL', 'DIESEL', 'CNG', 'EV', 'HYBRID', 'OTHER'], true)) {
        $fuelType = null;
    }
    $engineCc = vehicle_master_normalize_text((string) $engineCc, 30);

    try {
        $selectStmt = db()->prepare(
            'SELECT id
             FROM vehicle_variants
             WHERE model_id = :model_id
               AND variant_name = :variant_name
             LIMIT 1'
        );
        $selectStmt->execute([
            'model_id' => $modelId,
            'variant_name' => $variantName,
        ]);
        $existingId = (int) ($selectStmt->fetchColumn() ?: 0);

        if ($existingId > 0) {
            $updateStmt = db()->prepare(
                'UPDATE vehicle_variants
                 SET status_code = "ACTIVE",
                     deleted_at = NULL,
                     fuel_type = COALESCE(fuel_type, :fuel_type),
                     engine_cc = CASE
                         WHEN (engine_cc IS NULL OR engine_cc = "") AND :engine_cc <> "" THEN :engine_cc
                         ELSE engine_cc
                     END,
                     vis_variant_id = CASE
                         WHEN vis_variant_id IS NULL AND :vis_variant_id > 0 THEN :vis_variant_id
                         ELSE vis_variant_id
                     END
                 WHERE id = :id'
            );
            $updateStmt->execute([
                'id' => $existingId,
                'fuel_type' => $fuelType,
                'engine_cc' => $engineCc,
                'vis_variant_id' => $visVariantId !== null ? (int) $visVariantId : 0,
            ]);

            return vehicle_master_get_variant($existingId, $modelId);
        }

        $insertStmt = db()->prepare(
            'INSERT INTO vehicle_variants
              (model_id, variant_name, fuel_type, engine_cc, vis_variant_id, source_code, status_code, deleted_at)
             VALUES
              (:model_id, :variant_name, :fuel_type, :engine_cc, :vis_variant_id, :source_code, "ACTIVE", NULL)'
        );
        $insertStmt->execute([
            'model_id' => $modelId,
            'variant_name' => $variantName,
            'fuel_type' => $fuelType,
            'engine_cc' => $engineCc !== '' ? $engineCc : null,
            'vis_variant_id' => $visVariantId !== null && $visVariantId > 0 ? $visVariantId : null,
            'source_code' => $visVariantId !== null && $visVariantId > 0 ? 'VIS' : 'MANUAL',
        ]);

        return vehicle_master_get_variant((int) db()->lastInsertId(), $modelId);
    } catch (Throwable $exception) {
        return null;
    }
}

function vehicle_master_ensure_year(int $yearValue): ?array
{
    if (!vehicle_masters_enabled()) {
        return null;
    }

    if ($yearValue < 1900 || $yearValue > 2100) {
        return null;
    }

    try {
        $selectStmt = db()->prepare('SELECT id FROM vehicle_model_years WHERE year_value = :year_value LIMIT 1');
        $selectStmt->execute(['year_value' => $yearValue]);
        $existingId = (int) ($selectStmt->fetchColumn() ?: 0);

        if ($existingId > 0) {
            $updateStmt = db()->prepare(
                'UPDATE vehicle_model_years
                 SET status_code = "ACTIVE",
                     deleted_at = NULL
                 WHERE id = :id'
            );
            $updateStmt->execute(['id' => $existingId]);

            return vehicle_master_get_year($existingId);
        }

        $insertStmt = db()->prepare(
            'INSERT INTO vehicle_model_years (year_value, source_code, status_code, deleted_at)
             VALUES (:year_value, "MANUAL", "ACTIVE", NULL)'
        );
        $insertStmt->execute(['year_value' => $yearValue]);

        return vehicle_master_get_year((int) db()->lastInsertId());
    } catch (Throwable $exception) {
        return null;
    }
}

function vehicle_master_ensure_color(string $colorName): ?array
{
    if (!vehicle_masters_enabled()) {
        return null;
    }

    $colorName = vehicle_master_normalize_text($colorName, 60);
    if ($colorName === '') {
        return null;
    }

    try {
        $selectStmt = db()->prepare('SELECT id FROM vehicle_colors WHERE color_name = :color_name LIMIT 1');
        $selectStmt->execute(['color_name' => $colorName]);
        $existingId = (int) ($selectStmt->fetchColumn() ?: 0);

        if ($existingId > 0) {
            $updateStmt = db()->prepare(
                'UPDATE vehicle_colors
                 SET status_code = "ACTIVE",
                     deleted_at = NULL
                 WHERE id = :id'
            );
            $updateStmt->execute(['id' => $existingId]);

            return vehicle_master_get_color($existingId);
        }

        $insertStmt = db()->prepare(
            'INSERT INTO vehicle_colors (color_name, source_code, status_code, deleted_at)
             VALUES (:color_name, "MANUAL", "ACTIVE", NULL)'
        );
        $insertStmt->execute(['color_name' => $colorName]);

        return vehicle_master_get_color((int) db()->lastInsertId());
    } catch (Throwable $exception) {
        return null;
    }
}

function vehicle_master_search_brands(string $query = '', int $limit = 50): array
{
    if (!vehicle_masters_enabled()) {
        return [];
    }

    $query = vehicle_master_normalize_text($query, 100);
    $limit = max(1, min(200, $limit));

    try {
        $sql =
            'SELECT id, brand_name, vis_brand_id
             FROM vehicle_brands
             WHERE status_code = "ACTIVE"';
        $params = [];
        if ($query !== '') {
            $sql .= ' AND brand_name LIKE :query';
            $params['query'] = '%' . $query . '%';
        }
        $sql .= ' ORDER BY brand_name ASC LIMIT ' . $limit;

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $exception) {
        return [];
    }
}

function vehicle_master_search_models(int $brandId, string $query = '', int $limit = 80): array
{
    if (!vehicle_masters_enabled() || $brandId <= 0) {
        return [];
    }

    $query = vehicle_master_normalize_text($query, 120);
    $limit = max(1, min(300, $limit));

    try {
        $sql =
            'SELECT vm.id, vm.brand_id, vm.model_name, vm.vehicle_type, vm.vis_model_id, vb.brand_name
             FROM vehicle_models vm
             INNER JOIN vehicle_brands vb ON vb.id = vm.brand_id
             WHERE vm.brand_id = :brand_id
               AND vm.status_code = "ACTIVE"
               AND vb.status_code = "ACTIVE"';
        $params = ['brand_id' => $brandId];
        if ($query !== '') {
            $sql .= ' AND vm.model_name LIKE :query';
            $params['query'] = '%' . $query . '%';
        }
        $sql .= ' ORDER BY vm.model_name ASC LIMIT ' . $limit;

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $exception) {
        return [];
    }
}

function vehicle_master_search_variants(int $modelId, string $query = '', int $limit = 120): array
{
    if (!vehicle_masters_enabled() || $modelId <= 0) {
        return [];
    }

    $query = vehicle_master_normalize_text($query, 150);
    $limit = max(1, min(400, $limit));

    try {
        $sql =
            'SELECT vv.id, vv.model_id, vv.variant_name, vv.fuel_type, vv.engine_cc, vv.vis_variant_id,
                    vm.brand_id, vm.model_name
             FROM vehicle_variants vv
             INNER JOIN vehicle_models vm ON vm.id = vv.model_id
             WHERE vv.model_id = :model_id
               AND vv.status_code = "ACTIVE"
               AND vm.status_code = "ACTIVE"';
        $params = ['model_id' => $modelId];
        if ($query !== '') {
            $sql .= ' AND vv.variant_name LIKE :query';
            $params['query'] = '%' . $query . '%';
        }
        $sql .= ' ORDER BY vv.variant_name ASC LIMIT ' . $limit;

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $exception) {
        return [];
    }
}

function vehicle_master_search_combos(string $query = '', int $limit = 120, array $filters = []): array
{
    if (!vehicle_masters_enabled()) {
        return [];
    }

    $query = vehicle_master_normalize_text($query, 150);
    $limit = max(1, min(500, $limit));

    $brandId = (int) ($filters['brand_id'] ?? 0);
    $modelId = (int) ($filters['model_id'] ?? 0);
    $variantId = (int) ($filters['variant_id'] ?? 0);

    try {
        $where = [
            'vb.status_code = "ACTIVE"',
            'vm.status_code = "ACTIVE"',
            'vv.status_code = "ACTIVE"',
        ];
        $params = [];

        if ($brandId > 0) {
            $where[] = 'vb.id = :brand_id';
            $params['brand_id'] = $brandId;
        }

        if ($modelId > 0) {
            $where[] = 'vm.id = :model_id';
            $params['model_id'] = $modelId;
        }

        if ($variantId > 0) {
            $where[] = 'vv.id = :variant_id';
            $params['variant_id'] = $variantId;
        }

        if ($query !== '') {
            $where[] = '(vb.brand_name LIKE :query OR vm.model_name LIKE :query OR vv.variant_name LIKE :query OR CONCAT_WS(" ", vb.brand_name, vm.model_name, vv.variant_name) LIKE :query)';
            $params['query'] = '%' . $query . '%';
        }

        $stmt = db()->prepare(
            'SELECT vb.id AS brand_id,
                    vm.id AS model_id,
                    vv.id AS variant_id,
                    vb.brand_name,
                    vm.model_name,
                    vv.variant_name,
                    vv.fuel_type,
                    vv.engine_cc,
                    vv.vis_variant_id,
                    vb.vis_brand_id,
                    vm.vis_model_id,
                    vb.source_code AS brand_source_code,
                    vm.source_code AS model_source_code,
                    vv.source_code AS variant_source_code,
                    CASE
                      WHEN vv.vis_variant_id IS NOT NULL
                        OR vm.vis_model_id IS NOT NULL
                        OR vb.vis_brand_id IS NOT NULL
                        OR vv.source_code = "VIS"
                        OR vm.source_code = "VIS"
                        OR vb.source_code = "VIS"
                      THEN 1 ELSE 0
                    END AS vis_priority
             FROM vehicle_variants vv
             INNER JOIN vehicle_models vm ON vm.id = vv.model_id
             INNER JOIN vehicle_brands vb ON vb.id = vm.brand_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY vis_priority DESC, vb.brand_name ASC, vm.model_name ASC, vv.variant_name ASC
             LIMIT ' . $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $exception) {
        return [];
    }
}

function vehicle_master_search_years(string $query = '', int $limit = 120): array
{
    if (!vehicle_masters_enabled()) {
        return [];
    }

    $query = vehicle_master_normalize_text($query, 10);
    $limit = max(1, min(300, $limit));

    try {
        $sql =
            'SELECT id, year_value
             FROM vehicle_model_years
             WHERE status_code = "ACTIVE"';
        $params = [];
        if ($query !== '') {
            $sql .= ' AND CAST(year_value AS CHAR) LIKE :query';
            $params['query'] = '%' . $query . '%';
        }
        $sql .= ' ORDER BY year_value DESC LIMIT ' . $limit;

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $exception) {
        return [];
    }
}

function vehicle_master_search_colors(string $query = '', int $limit = 120): array
{
    if (!vehicle_masters_enabled()) {
        return [];
    }

    $query = vehicle_master_normalize_text($query, 60);
    $limit = max(1, min(300, $limit));

    try {
        $sql =
            'SELECT id, color_name
             FROM vehicle_colors
             WHERE status_code = "ACTIVE"';
        $params = [];
        if ($query !== '') {
            $sql .= ' AND color_name LIKE :query';
            $params['query'] = '%' . $query . '%';
        }
        $sql .= ' ORDER BY color_name ASC LIMIT ' . $limit;

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $exception) {
        return [];
    }
}

function vehicle_master_scope_sql(string $vehicleAlias, array $filters, array &$params, string $prefix = 'vehicle'): string
{
    if (!vehicle_master_link_columns_supported()) {
        return '';
    }

    $clauses = [];
    $brandId = (int) ($filters['brand_id'] ?? 0);
    $modelId = (int) ($filters['model_id'] ?? 0);
    $variantId = (int) ($filters['variant_id'] ?? 0);
    $modelYearId = (int) ($filters['model_year_id'] ?? 0);
    $colorId = (int) ($filters['color_id'] ?? 0);

    if ($brandId > 0) {
        $key = $prefix . '_brand_id';
        $params[$key] = $brandId;
        $clauses[] = $vehicleAlias . '.brand_id = :' . $key;
    }
    if ($modelId > 0) {
        $key = $prefix . '_model_id';
        $params[$key] = $modelId;
        $clauses[] = $vehicleAlias . '.model_id = :' . $key;
    }
    if ($variantId > 0) {
        $key = $prefix . '_variant_id';
        $params[$key] = $variantId;
        $clauses[] = $vehicleAlias . '.variant_id = :' . $key;
    }
    if ($modelYearId > 0) {
        $key = $prefix . '_model_year_id';
        $params[$key] = $modelYearId;
        $clauses[] = $vehicleAlias . '.model_year_id = :' . $key;
    }
    if ($colorId > 0) {
        $key = $prefix . '_color_id';
        $params[$key] = $colorId;
        $clauses[] = $vehicleAlias . '.color_id = :' . $key;
    }

    return $clauses === [] ? '' : (' AND ' . implode(' AND ', $clauses));
}

function vehicle_master_search_vehicles(int $companyId, array $filters = [], int $limit = 200): array
{
    if ($companyId <= 0) {
        return [];
    }

    $limit = max(1, min(500, $limit));
    $columns = table_columns('vehicles');
    $hasBrandId = in_array('brand_id', $columns, true);
    $hasModelId = in_array('model_id', $columns, true);
    $hasVariantId = in_array('variant_id', $columns, true);
    $hasModelYearId = in_array('model_year_id', $columns, true);
    $hasColorId = in_array('color_id', $columns, true);

    $where = ['v.company_id = :company_id', 'v.status_code = "ACTIVE"'];
    $params = ['company_id' => $companyId];

    $customerId = (int) ($filters['customer_id'] ?? 0);
    if ($customerId > 0) {
        $where[] = 'v.customer_id = :customer_id';
        $params['customer_id'] = $customerId;
    }

    $query = vehicle_master_normalize_text((string) ($filters['q'] ?? ''), 120);
    if ($query !== '') {
        $where[] = '(v.registration_no LIKE :query OR v.brand LIKE :query OR v.model LIKE :query OR v.variant LIKE :query)';
        $params['query'] = '%' . $query . '%';
    }

    if ($hasBrandId) {
        $brandId = (int) ($filters['brand_id'] ?? 0);
        if ($brandId > 0) {
            $where[] = 'v.brand_id = :brand_id';
            $params['brand_id'] = $brandId;
        }
    }

    if ($hasModelId) {
        $modelId = (int) ($filters['model_id'] ?? 0);
        if ($modelId > 0) {
            $where[] = 'v.model_id = :model_id';
            $params['model_id'] = $modelId;
        }
    }

    if ($hasVariantId) {
        $variantId = (int) ($filters['variant_id'] ?? 0);
        if ($variantId > 0) {
            $where[] = 'v.variant_id = :variant_id';
            $params['variant_id'] = $variantId;
        }
    }

    if ($hasModelYearId) {
        $modelYearId = (int) ($filters['model_year_id'] ?? 0);
        if ($modelYearId > 0) {
            $where[] = 'v.model_year_id = :model_year_id';
            $params['model_year_id'] = $modelYearId;
        }
    }

    if ($hasColorId) {
        $colorId = (int) ($filters['color_id'] ?? 0);
        if ($colorId > 0) {
            $where[] = 'v.color_id = :color_id';
            $params['color_id'] = $colorId;
        }
    }

    $selectFields = [
        'v.id',
        'v.customer_id',
        'v.registration_no',
        'v.brand',
        'v.model',
        'v.variant',
        'v.model_year',
        'v.color',
    ];
    $selectFields[] = $hasBrandId ? 'v.brand_id' : 'NULL AS brand_id';
    $selectFields[] = $hasModelId ? 'v.model_id' : 'NULL AS model_id';
    $selectFields[] = $hasVariantId ? 'v.variant_id' : 'NULL AS variant_id';
    $selectFields[] = $hasModelYearId ? 'v.model_year_id' : 'NULL AS model_year_id';
    $selectFields[] = $hasColorId ? 'v.color_id' : 'NULL AS color_id';

    try {
        $stmt = db()->prepare(
            'SELECT ' . implode(', ', $selectFields) . '
             FROM vehicles v
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY v.registration_no ASC
             LIMIT ' . $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $exception) {
        return [];
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
