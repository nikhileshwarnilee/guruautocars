<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('audit.view');

$page_title = 'Audit Logs';
$active_menu = 'system.audit';

$companyId = active_company_id();
$activeGarageId = active_garage_id();
$roleKey = (string) ($_SESSION['role_key'] ?? '');
$isOwnerScope = analytics_is_owner_role($roleKey);

$auditColumns = table_columns('audit_logs');
$hasGarageColumn = in_array('garage_id', $auditColumns, true);
$hasRoleColumn = in_array('role_key', $auditColumns, true);
$hasEntityColumn = in_array('entity_name', $auditColumns, true);
$hasSourceColumn = in_array('source_channel', $auditColumns, true);
$hasIpColumn = in_array('ip_address', $auditColumns, true);
$hasBeforeColumn = in_array('before_snapshot', $auditColumns, true);
$hasAfterColumn = in_array('after_snapshot', $auditColumns, true);
$hasMetadataColumn = in_array('metadata_json', $auditColumns, true);
$hasRequestIdColumn = in_array('request_id', $auditColumns, true);

function audit_logs_csv_download(string $filename, array $headers, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $stream = fopen('php://output', 'w');
    if ($stream === false) {
        http_response_code(500);
        exit('Unable to generate CSV export.');
    }

    fwrite($stream, "\xEF\xBB\xBF");
    fputcsv($stream, $headers);

    foreach ($rows as $row) {
        $flat = [];
        foreach ($row as $value) {
            if (is_scalar($value) || $value === null) {
                $flat[] = (string) ($value ?? '');
            } else {
                $json = json_encode($value, JSON_UNESCAPED_UNICODE);
                $flat[] = is_string($json) ? $json : '';
            }
        }
        fputcsv($stream, $flat);
    }

    fclose($stream);
    exit;
}

function audit_logs_decode_json(string $json): array
{
    if (trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function audit_logs_json_pretty(string $json): string
{
    $decoded = audit_logs_decode_json($json);
    if ($decoded === []) {
        return '';
    }

    $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return is_string($encoded) ? $encoded : '';
}

function audit_logs_table_columns_uncached(PDO $pdo, string $tableName): array
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
        return [];
    }

    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . $tableName . '`');
        $columns = [];
        foreach ($stmt->fetchAll() as $row) {
            $field = (string) ($row['Field'] ?? '');
            if ($field !== '') {
                $columns[] = $field;
            }
        }
        return $columns;
    } catch (Throwable $exception) {
        return [];
    }
}

function audit_logs_has_column(PDO $pdo, string $tableName, string $columnName): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName) || !preg_match('/^[a-zA-Z0-9_]+$/', $columnName)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . $tableName . '` LIKE :column_name');
        $stmt->execute(['column_name' => $columnName]);
        return (bool) $stmt->fetch();
    } catch (Throwable $exception) {
        return false;
    }
}

function audit_logs_archive_table_ready(PDO $pdo): bool
{
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS `audit_logs_archive` LIKE `audit_logs`');

        if (!audit_logs_has_column($pdo, 'audit_logs_archive', 'archived_at')) {
            $pdo->exec('ALTER TABLE `audit_logs_archive` ADD COLUMN `archived_at` DATETIME NULL AFTER `created_at`');
        }
        if (!audit_logs_has_column($pdo, 'audit_logs_archive', 'archive_batch_id')) {
            $pdo->exec('ALTER TABLE `audit_logs_archive` ADD COLUMN `archive_batch_id` VARCHAR(64) NULL AFTER `archived_at`');
        }

        $indexRows = $pdo->query('SHOW INDEX FROM `audit_logs_archive`')->fetchAll();
        $indexNames = [];
        foreach ($indexRows as $indexRow) {
            $indexName = (string) ($indexRow['Key_name'] ?? '');
            if ($indexName !== '') {
                $indexNames[$indexName] = true;
            }
        }

        if (!isset($indexNames['idx_archived_at'])) {
            $pdo->exec('ALTER TABLE `audit_logs_archive` ADD INDEX `idx_archived_at` (`archived_at`)');
        }
        if (!isset($indexNames['idx_archive_batch_id'])) {
            $pdo->exec('ALTER TABLE `audit_logs_archive` ADD INDEX `idx_archive_batch_id` (`archive_batch_id`)');
        }

        return true;
    } catch (Throwable $exception) {
        return false;
    }
}

function audit_logs_scope_conditions(
    bool $hasGarageColumn,
    int $companyId,
    int $selectedGarageId,
    array $garageIds,
    string $alias = 'al',
    string $paramPrefix = 'scope'
): array {
    $prefix = $alias !== '' ? $alias . '.' : '';
    $params = [
        $paramPrefix . '_company_id' => $companyId,
    ];

    $where = [
        $prefix . 'company_id = :' . $paramPrefix . '_company_id',
    ];

    if ($hasGarageColumn) {
        if ($selectedGarageId > 0) {
            $where[] = $prefix . 'garage_id = :' . $paramPrefix . '_garage_id';
            $params[$paramPrefix . '_garage_id'] = $selectedGarageId;
        } elseif ($garageIds !== []) {
            $placeholders = [];
            foreach ($garageIds as $index => $garageId) {
                $paramKey = $paramPrefix . '_garage_' . $index;
                $placeholders[] = ':' . $paramKey;
                $params[$paramKey] = (int) $garageId;
            }
            if ($placeholders !== []) {
                $where[] = $prefix . 'garage_id IN (' . implode(', ', $placeholders) . ')';
            }
        }
    }

    return [
        'where' => $where,
        'params' => $params,
    ];
}

function audit_logs_run_retention(
    PDO $pdo,
    bool $hasGarageColumn,
    int $companyId,
    int $selectedGarageId,
    array $garageIds,
    string $cutoffAt,
    bool $archiveFirst
): array {
    $countScope = audit_logs_scope_conditions($hasGarageColumn, $companyId, $selectedGarageId, $garageIds, '', 'ret');
    $countWhere = $countScope['where'];
    $countWhere[] = 'created_at < :ret_cutoff_at';
    $countParams = $countScope['params'];
    $countParams['ret_cutoff_at'] = $cutoffAt;

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM `audit_logs` WHERE ' . implode(' AND ', $countWhere));
    $countStmt->execute($countParams);
    $matchedCount = (int) $countStmt->fetchColumn();

    if ($matchedCount <= 0) {
        return [
            'ok' => true,
            'matched' => 0,
            'archived' => 0,
            'deleted' => 0,
            'batch_id' => null,
        ];
    }

    $batchId = '';
    try {
        $batchId = bin2hex(random_bytes(12));
    } catch (Throwable $exception) {
        $batchId = md5((string) microtime(true));
    }

    $archivedCount = 0;
    $deletedCount = 0;

    try {
        $pdo->beginTransaction();

        if ($archiveFirst) {
            if (!audit_logs_archive_table_ready($pdo)) {
                throw new RuntimeException('Archive table is unavailable.');
            }

            $liveColumns = audit_logs_table_columns_uncached($pdo, 'audit_logs');
            $archiveColumns = audit_logs_table_columns_uncached($pdo, 'audit_logs_archive');
            $commonColumns = array_values(array_intersect($liveColumns, $archiveColumns));
            if ($commonColumns === []) {
                throw new RuntimeException('No common columns found between audit tables.');
            }

            $insertColumns = $commonColumns;
            $selectColumns = array_map(static fn (string $column): string => 'al.`' . $column . '`', $commonColumns);

            if (in_array('archived_at', $archiveColumns, true)) {
                $insertColumns[] = 'archived_at';
                $selectColumns[] = 'NOW()';
            }
            if (in_array('archive_batch_id', $archiveColumns, true)) {
                $insertColumns[] = 'archive_batch_id';
                $selectColumns[] = ':ret_archive_batch_id';
            }

            $archiveScope = audit_logs_scope_conditions($hasGarageColumn, $companyId, $selectedGarageId, $garageIds, 'al', 'ret');
            $archiveWhere = $archiveScope['where'];
            $archiveWhere[] = 'al.created_at < :ret_cutoff_at';

            $archiveParams = $archiveScope['params'];
            $archiveParams['ret_cutoff_at'] = $cutoffAt;
            if (in_array('archive_batch_id', $archiveColumns, true)) {
                $archiveParams['ret_archive_batch_id'] = $batchId;
            }

            $insertSql =
                'INSERT IGNORE INTO `audit_logs_archive` ('
                . implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $insertColumns))
                . ') SELECT '
                . implode(', ', $selectColumns)
                . ' FROM `audit_logs` al WHERE '
                . implode(' AND ', $archiveWhere);

            $archiveStmt = $pdo->prepare($insertSql);
            $archiveStmt->execute($archiveParams);
            $archivedCount = (int) $archiveStmt->rowCount();
        }

        $deleteScope = audit_logs_scope_conditions($hasGarageColumn, $companyId, $selectedGarageId, $garageIds, '', 'ret');
        $deleteWhere = $deleteScope['where'];
        $deleteWhere[] = 'created_at < :ret_cutoff_at';
        $deleteParams = $deleteScope['params'];
        $deleteParams['ret_cutoff_at'] = $cutoffAt;

        $deleteStmt = $pdo->prepare('DELETE FROM `audit_logs` WHERE ' . implode(' AND ', $deleteWhere));
        $deleteStmt->execute($deleteParams);
        $deletedCount = (int) $deleteStmt->rowCount();

        $pdo->commit();

        return [
            'ok' => true,
            'matched' => $matchedCount,
            'archived' => $archivedCount,
            'deleted' => $deletedCount,
            'batch_id' => $archiveFirst ? $batchId : null,
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'matched' => $matchedCount,
            'archived' => 0,
            'deleted' => 0,
            'batch_id' => null,
            'message' => $exception->getMessage(),
        ];
    }
}

$garageOptions = analytics_accessible_garages($companyId, $isOwnerScope);
$garageIds = array_values(array_filter(array_map(static fn (array $garage): int => (int) ($garage['id'] ?? 0), $garageOptions), static fn (int $id): bool => $id > 0));
$allowAllGarages = $isOwnerScope && count($garageIds) > 1;
$garageRequested = isset($_GET['garage_id']) ? get_int('garage_id', $activeGarageId) : $activeGarageId;
$selectedGarageId = analytics_resolve_scope_garage_id($garageOptions, $activeGarageId, $garageRequested, $allowAllGarages);
$scopeGarageLabel = analytics_scope_garage_label($garageOptions, $selectedGarageId);

$fromDate = analytics_parse_iso_date($_GET['from'] ?? null, date('Y-m-01'));
$toDate = analytics_parse_iso_date($_GET['to'] ?? null, date('Y-m-d'));
if ($toDate < $fromDate) {
    $toDate = $fromDate;
}

$moduleFilter = trim((string) ($_GET['module_name'] ?? ''));
$entityFilter = trim((string) ($_GET['entity_name'] ?? ''));
$actionFilter = trim((string) ($_GET['action_name'] ?? ''));
$sourceFilter = strtoupper(trim((string) ($_GET['source_channel'] ?? '')));
$ipFilter = trim((string) ($_GET['ip_address'] ?? ''));
$userFilter = get_int('user_id', 0);
$query = trim((string) ($_GET['q'] ?? ''));
$exportRequested = strtolower(trim((string) ($_GET['export'] ?? ''))) === 'csv';

$filterParams = [
    'garage_id' => $allowAllGarages ? $selectedGarageId : null,
    'from' => $fromDate,
    'to' => $toDate,
    'module_name' => $moduleFilter !== '' ? $moduleFilter : null,
    'entity_name' => $entityFilter !== '' ? $entityFilter : null,
    'action_name' => $actionFilter !== '' ? $actionFilter : null,
    'source_channel' => $sourceFilter !== '' ? $sourceFilter : null,
    'ip_address' => $ipFilter !== '' ? $ipFilter : null,
    'user_id' => $userFilter > 0 ? $userFilter : null,
    'q' => $query !== '' ? $query : null,
];
if (!$allowAllGarages && $selectedGarageId > 0) {
    $filterParams['garage_id'] = $selectedGarageId;
}

$compactFilterParams = array_filter(
    $filterParams,
    static fn ($value): bool => !($value === null || (is_string($value) && trim($value) === ''))
);
$returnPath = 'modules/system/audit_logs.php';
if ($compactFilterParams !== []) {
    $returnPath .= '?' . http_build_query($compactFilterParams);
}

$canRetentionManage = has_permission('backup.manage');
$retentionDaysDefault = 180;
$retentionDaysRaw = system_setting_get_value($companyId, 0, 'audit_log_retention_days', (string) $retentionDaysDefault);
$auditRetentionDays = filter_var($retentionDaysRaw, FILTER_VALIDATE_INT) !== false ? (int) $retentionDaysRaw : $retentionDaysDefault;
if ($auditRetentionDays < 7) {
    $auditRetentionDays = 7;
}
if ($auditRetentionDays > 3650) {
    $auditRetentionDays = 3650;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $postAction = (string) ($_POST['_action'] ?? '');

    if ($postAction === 'set_audit_retention' || $postAction === 'run_audit_retention') {
        if (!$canRetentionManage) {
            flash_set('audit_error', 'You do not have permission to manage retention policy.', 'danger');
            redirect($returnPath);
        }
    }

    if ($postAction === 'set_audit_retention') {
        $newRetentionDays = post_int('audit_retention_days', $auditRetentionDays);
        if ($newRetentionDays < 7) {
            $newRetentionDays = 7;
        }
        if ($newRetentionDays > 3650) {
            $newRetentionDays = 3650;
        }

        system_setting_upsert_value(
            $companyId,
            0,
            'SECURITY',
            'audit_log_retention_days',
            (string) $newRetentionDays,
            'NUMBER',
            'ACTIVE',
            isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null
        );

        log_audit('audit_logs', 'retention_policy_update', null, 'Updated audit log retention policy.', [
            'entity' => 'audit_log_retention',
            'source' => 'UI',
            'metadata' => [
                'retention_days' => $newRetentionDays,
                'scope_garage_id' => $selectedGarageId > 0 ? $selectedGarageId : null,
            ],
        ]);

        flash_set('audit_success', 'Retention policy updated to ' . $newRetentionDays . ' day(s).', 'success');
        redirect($returnPath);
    }

    if ($postAction === 'run_audit_retention') {
        $runRetentionDays = post_int('audit_retention_days', $auditRetentionDays);
        if ($runRetentionDays < 7) {
            $runRetentionDays = 7;
        }
        if ($runRetentionDays > 3650) {
            $runRetentionDays = 3650;
        }

        $retentionMode = strtolower(post_string('retention_mode', 20));
        $archiveFirst = $retentionMode !== 'delete_only';
        $cutoffAt = date('Y-m-d H:i:s', strtotime('-' . $runRetentionDays . ' days'));

        $retentionResult = audit_logs_run_retention(
            db(),
            $hasGarageColumn,
            $companyId,
            $selectedGarageId,
            $garageIds,
            $cutoffAt,
            $archiveFirst
        );

        if (!(bool) ($retentionResult['ok'] ?? false)) {
            $message = trim((string) ($retentionResult['message'] ?? 'Unable to run retention.'));
            if ($message === '') {
                $message = 'Unable to run retention.';
            }
            flash_set('audit_error', $message, 'danger');
            redirect($returnPath);
        }

        $matchedCount = (int) ($retentionResult['matched'] ?? 0);
        $archivedCount = (int) ($retentionResult['archived'] ?? 0);
        $deletedCount = (int) ($retentionResult['deleted'] ?? 0);
        $batchId = (string) ($retentionResult['batch_id'] ?? '');

        if ($matchedCount <= 0) {
            flash_set('audit_success', 'No audit logs were eligible for retention cleanup.', 'success');
        } elseif ($archiveFirst) {
            flash_set(
                'audit_success',
                'Retention run complete. Archived ' . $archivedCount . ' and deleted ' . $deletedCount . ' log(s) older than ' . $runRetentionDays . ' day(s).',
                'success'
            );
        } else {
            flash_set(
                'audit_success',
                'Retention run complete. Deleted ' . $deletedCount . ' log(s) older than ' . $runRetentionDays . ' day(s) without archiving.',
                'warning'
            );
        }

        log_audit('audit_logs', $archiveFirst ? 'retention_archive_delete' : 'retention_delete', null, 'Executed audit log retention cleanup.', [
            'entity' => 'audit_log_retention',
            'source' => 'UI',
            'metadata' => [
                'retention_days' => $runRetentionDays,
                'cutoff_at' => $cutoffAt,
                'matched_count' => $matchedCount,
                'archived_count' => $archivedCount,
                'deleted_count' => $deletedCount,
                'archive_batch_id' => $batchId !== '' ? $batchId : null,
                'scope_garage_id' => $selectedGarageId > 0 ? $selectedGarageId : null,
                'mode' => $archiveFirst ? 'archive_delete' : 'delete_only',
            ],
        ]);

        redirect($returnPath);
    }
}

$retentionCutoffAt = date('Y-m-d H:i:s', strtotime('-' . $auditRetentionDays . ' days'));
$retentionStats = [
    'active_total' => 0,
    'eligible_count' => 0,
    'archive_total' => 0,
];

try {
    $scopeStats = audit_logs_scope_conditions($hasGarageColumn, $companyId, $selectedGarageId, $garageIds, '', 'stat');

    $activeStmt = db()->prepare('SELECT COUNT(*) FROM `audit_logs` WHERE ' . implode(' AND ', $scopeStats['where']));
    $activeStmt->execute($scopeStats['params']);
    $retentionStats['active_total'] = (int) $activeStmt->fetchColumn();

    $eligibleWhere = $scopeStats['where'];
    $eligibleWhere[] = 'created_at < :stat_cutoff_at';
    $eligibleParams = $scopeStats['params'];
    $eligibleParams['stat_cutoff_at'] = $retentionCutoffAt;
    $eligibleStmt = db()->prepare('SELECT COUNT(*) FROM `audit_logs` WHERE ' . implode(' AND ', $eligibleWhere));
    $eligibleStmt->execute($eligibleParams);
    $retentionStats['eligible_count'] = (int) $eligibleStmt->fetchColumn();

    if (table_columns('audit_logs_archive') !== []) {
        $archiveScope = audit_logs_scope_conditions($hasGarageColumn, $companyId, $selectedGarageId, $garageIds, '', 'arc');
        $archiveStmt = db()->prepare('SELECT COUNT(*) FROM `audit_logs_archive` WHERE ' . implode(' AND ', $archiveScope['where']));
        $archiveStmt->execute($archiveScope['params']);
        $retentionStats['archive_total'] = (int) $archiveStmt->fetchColumn();
    }
} catch (Throwable $exception) {
    // Do not block audit log view if stats query fails.
}

$exportParams = $compactFilterParams;
$exportParams['export'] = 'csv';
$exportCsvUrl = url('modules/system/audit_logs.php?' . http_build_query($exportParams));

$params = [
    'company_id' => $companyId,
    'from_at' => $fromDate . ' 00:00:00',
    'to_at' => $toDate . ' 23:59:59',
];

$where = [
    'al.company_id = :company_id',
    'al.created_at BETWEEN :from_at AND :to_at',
];

if ($hasGarageColumn) {
    if ($selectedGarageId > 0) {
        $where[] = 'al.garage_id = :garage_id';
        $params['garage_id'] = $selectedGarageId;
    } elseif (!empty($garageIds)) {
        $garagePlaceholders = [];
        foreach ($garageIds as $index => $garageId) {
            $paramKey = 'scope_garage_' . $index;
            $garagePlaceholders[] = ':' . $paramKey;
            $params[$paramKey] = $garageId;
        }
        $where[] = 'al.garage_id IN (' . implode(', ', $garagePlaceholders) . ')';
    }
}

if ($moduleFilter !== '') {
    $where[] = 'al.module_name = :module_name';
    $params['module_name'] = $moduleFilter;
}

if ($hasEntityColumn && $entityFilter !== '') {
    $where[] = 'al.entity_name = :entity_name';
    $params['entity_name'] = $entityFilter;
}

if ($actionFilter !== '') {
    $where[] = 'al.action_name = :action_name';
    $params['action_name'] = $actionFilter;
}

if ($hasSourceColumn && $sourceFilter !== '') {
    $where[] = 'al.source_channel = :source_channel';
    $params['source_channel'] = $sourceFilter;
}

if ($hasIpColumn && $ipFilter !== '') {
    $where[] = 'al.ip_address LIKE :ip_address';
    $params['ip_address'] = '%' . $ipFilter . '%';
}

if ($userFilter > 0) {
    $where[] = 'al.user_id = :user_id';
    $params['user_id'] = $userFilter;
}

if ($query !== '') {
    $queryParts = [
        'al.details LIKE :query',
        'al.module_name LIKE :query',
        'al.action_name LIKE :query',
    ];
    if ($hasEntityColumn) {
        $queryParts[] = 'al.entity_name LIKE :query';
    }
    if ($hasIpColumn) {
        $queryParts[] = 'al.ip_address LIKE :query';
    }
    if ($hasRequestIdColumn) {
        $queryParts[] = 'al.request_id LIKE :query';
    }
    if ($hasMetadataColumn) {
        $queryParts[] = 'al.metadata_json LIKE :query';
    }

    $where[] = '(' . implode(' OR ', $queryParts) . ')';
    $params['query'] = '%' . $query . '%';
}

$entitySelect = $hasEntityColumn ? 'al.entity_name' : 'al.module_name';
$sourceSelect = $hasSourceColumn ? 'al.source_channel' : 'NULL';
$roleSelect = $hasRoleColumn ? 'al.role_key' : 'NULL';
$garageSelect = $hasGarageColumn ? 'al.garage_id' : 'NULL';
$garageNameSelect = $hasGarageColumn ? 'g.name' : 'NULL';
$garageJoin = $hasGarageColumn ? 'LEFT JOIN garages g ON g.id = al.garage_id' : '';
$beforeSelect = $hasBeforeColumn ? 'al.before_snapshot' : 'NULL';
$afterSelect = $hasAfterColumn ? 'al.after_snapshot' : 'NULL';
$ipSelect = $hasIpColumn ? 'al.ip_address' : 'NULL';
$metadataSelect = $hasMetadataColumn ? 'al.metadata_json' : 'NULL';
$requestIdSelect = $hasRequestIdColumn ? 'al.request_id' : 'NULL';

if ($exportRequested) {
    $exportLimit = 15000;
    $exportSql =
        'SELECT al.id, al.created_at, al.module_name, al.action_name, al.reference_id, al.details,
                ' . $entitySelect . ' AS entity_name,
                ' . $sourceSelect . ' AS source_channel,
                ' . $roleSelect . ' AS role_key,
                ' . $garageSelect . ' AS garage_id,
                ' . $beforeSelect . ' AS before_snapshot,
                ' . $afterSelect . ' AS after_snapshot,
                ' . $ipSelect . ' AS ip_address,
                ' . $metadataSelect . ' AS metadata_json,
                ' . $requestIdSelect . ' AS request_id,
                u.name AS user_name, u.username AS username,
                ' . $garageNameSelect . ' AS garage_name
         FROM audit_logs al
         LEFT JOIN users u ON u.id = al.user_id
         ' . $garageJoin . '
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY al.id DESC
         LIMIT ' . $exportLimit;

    $exportStmt = db()->prepare($exportSql);
    $exportStmt->execute($params);
    $exportRows = $exportStmt->fetchAll();

    $csvHeaders = [
        'When',
        'User',
        'Username',
        'Role',
        'Garage',
        'Module',
        'Entity',
        'Action',
        'Source',
        'IP Address',
        'Request ID',
        'Request Method',
        'Request Path',
        'Post Action',
        'Query Params',
        'Referrer',
        'User Agent',
        'Reference ID',
        'Details',
        'Before Snapshot',
        'After Snapshot',
        'Metadata JSON',
    ];

    $csvRows = [];
    foreach ($exportRows as $row) {
        $metadata = audit_logs_decode_json((string) ($row['metadata_json'] ?? ''));
        $requestMethod = strtoupper(trim((string) ($metadata['request_method'] ?? '')));
        $requestPath = trim((string) ($metadata['request_path'] ?? ''));
        $postAction = trim((string) ($metadata['post_action'] ?? ''));
        $requestQuery = $metadata['request_query'] ?? $metadata['query'] ?? null;
        $requestQueryText = '';
        if (is_array($requestQuery) && $requestQuery !== []) {
            $encodedQuery = json_encode($requestQuery, JSON_UNESCAPED_UNICODE);
            $requestQueryText = is_string($encodedQuery) ? $encodedQuery : '';
        }

        $csvRows[] = [
            (string) ($row['created_at'] ?? ''),
            (string) ($row['user_name'] ?? 'System'),
            (string) ($row['username'] ?? ''),
            (string) ($row['role_key'] ?? ''),
            (string) ($row['garage_name'] ?? 'Company'),
            (string) ($row['module_name'] ?? ''),
            (string) ($row['entity_name'] ?? ''),
            (string) ($row['action_name'] ?? ''),
            (string) ($row['source_channel'] ?? ''),
            (string) ($row['ip_address'] ?? ''),
            (string) ($row['request_id'] ?? ''),
            $requestMethod,
            $requestPath,
            $postAction,
            $requestQueryText,
            (string) ($metadata['referrer'] ?? ''),
            (string) ($metadata['user_agent'] ?? ''),
            $row['reference_id'] !== null ? (string) (int) $row['reference_id'] : '',
            (string) ($row['details'] ?? ''),
            (string) ($row['before_snapshot'] ?? ''),
            (string) ($row['after_snapshot'] ?? ''),
            (string) ($row['metadata_json'] ?? ''),
        ];
    }

    log_data_export('audit_logs', 'CSV', count($csvRows), [
        'company_id' => $companyId,
        'garage_id' => $selectedGarageId > 0 ? $selectedGarageId : null,
        'filter_summary' => 'Audit logs CSV export',
        'scope' => [
            'from' => $fromDate,
            'to' => $toDate,
            'module_name' => $moduleFilter,
            'action_name' => $actionFilter,
            'source_channel' => $sourceFilter,
            'ip_address' => $ipFilter,
            'query' => $query,
        ],
        'requested_by' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
    ]);

    log_audit('exports', 'download', null, 'Exported audit log CSV.', [
        'entity' => 'audit_logs_export',
        'source' => 'UI',
        'metadata' => [
            'row_count' => count($csvRows),
            'row_limit' => $exportLimit,
            'scope_garage_id' => $selectedGarageId > 0 ? $selectedGarageId : null,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ],
    ]);

    audit_logs_csv_download('audit_logs_' . date('Ymd_His') . '.csv', $csvHeaders, $csvRows);
}

$listSql =
    'SELECT al.id, al.created_at, al.module_name, al.action_name, al.reference_id, al.details,
            ' . $entitySelect . ' AS entity_name,
            ' . $sourceSelect . ' AS source_channel,
            ' . $roleSelect . ' AS role_key,
            ' . $garageSelect . ' AS garage_id,
            ' . $beforeSelect . ' AS before_snapshot,
            ' . $afterSelect . ' AS after_snapshot,
            ' . $ipSelect . ' AS ip_address,
            ' . $metadataSelect . ' AS metadata_json,
            ' . $requestIdSelect . ' AS request_id,
            u.name AS user_name, u.username AS username,
            ' . $garageNameSelect . ' AS garage_name
     FROM audit_logs al
     LEFT JOIN users u ON u.id = al.user_id
     ' . $garageJoin . '
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY al.id DESC
     LIMIT 300';

$listStmt = db()->prepare($listSql);
$listStmt->execute($params);
$auditRows = $listStmt->fetchAll();

$modulesStmt = db()->prepare(
    'SELECT DISTINCT module_name
     FROM audit_logs
     WHERE company_id = :company_id
     ORDER BY module_name ASC'
);
$modulesStmt->execute(['company_id' => $companyId]);
$moduleOptions = $modulesStmt->fetchAll();

$actionsStmt = db()->prepare(
    'SELECT DISTINCT action_name
     FROM audit_logs
     WHERE company_id = :company_id
     ORDER BY action_name ASC'
);
$actionsStmt->execute(['company_id' => $companyId]);
$actionOptions = $actionsStmt->fetchAll();

$usersStmt = db()->prepare(
    'SELECT DISTINCT u.id, u.name
     FROM audit_logs al
     INNER JOIN users u ON u.id = al.user_id
     WHERE al.company_id = :company_id
     ORDER BY u.name ASC'
);
$usersStmt->execute(['company_id' => $companyId]);
$userOptions = $usersStmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-7">
          <h3 class="mb-0">Audit Logs</h3>
          <small class="text-muted">Immutable, read-only compliance stream. Scope: <?= e($scopeGarageLabel); ?></small>
        </div>
        <div class="col-sm-5">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Audit Logs</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="card card-outline card-primary mb-3">
        <div class="card-header"><h3 class="card-title">Filter Scope</h3></div>
        <form method="get">
          <div class="card-body row g-2">
            <div class="col-md-2">
              <label class="form-label">Garage</label>
              <select name="garage_id" class="form-select">
                <?php if ($allowAllGarages): ?>
                  <option value="0" <?= $selectedGarageId === 0 ? 'selected' : ''; ?>>All Accessible</option>
                <?php endif; ?>
                <?php foreach ($garageOptions as $garage): ?>
                  <option value="<?= (int) $garage['id']; ?>" <?= ((int) $garage['id'] === $selectedGarageId) ? 'selected' : ''; ?>>
                    <?= e((string) $garage['name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">From</label>
              <input type="date" name="from" class="form-control" value="<?= e($fromDate); ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label">To</label>
              <input type="date" name="to" class="form-control" value="<?= e($toDate); ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label">Module</label>
              <select name="module_name" class="form-select">
                <option value="">All</option>
                <?php foreach ($moduleOptions as $module): ?>
                  <?php $moduleName = (string) ($module['module_name'] ?? ''); ?>
                  <option value="<?= e($moduleName); ?>" <?= $moduleFilter === $moduleName ? 'selected' : ''; ?>><?= e($moduleName); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Action</label>
              <select name="action_name" class="form-select">
                <option value="">All</option>
                <?php foreach ($actionOptions as $action): ?>
                  <?php $actionName = (string) ($action['action_name'] ?? ''); ?>
                  <option value="<?= e($actionName); ?>" <?= $actionFilter === $actionName ? 'selected' : ''; ?>><?= e($actionName); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">User</label>
              <select name="user_id" class="form-select">
                <option value="0">All</option>
                <?php foreach ($userOptions as $user): ?>
                  <option value="<?= (int) $user['id']; ?>" <?= ((int) $user['id'] === $userFilter) ? 'selected' : ''; ?>>
                    <?= e((string) $user['name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Entity</label>
              <input type="text" name="entity_name" class="form-control" value="<?= e($entityFilter); ?>" placeholder="invoice, job_card">
            </div>
            <div class="col-md-2">
              <label class="form-label">Source</label>
              <select name="source_channel" class="form-select">
                <option value="">All</option>
                <option value="UI" <?= $sourceFilter === 'UI' ? 'selected' : ''; ?>>UI</option>
                <option value="API" <?= $sourceFilter === 'API' ? 'selected' : ''; ?>>API</option>
                <option value="SYSTEM" <?= $sourceFilter === 'SYSTEM' ? 'selected' : ''; ?>>SYSTEM</option>
                <option value="JOB-CLOSE" <?= $sourceFilter === 'JOB-CLOSE' ? 'selected' : ''; ?>>JOB-CLOSE</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">IP Address</label>
              <input type="text" name="ip_address" class="form-control" value="<?= e($ipFilter); ?>" placeholder="172.16.x.x">
            </div>
            <div class="col-md-4">
              <label class="form-label">Search</label>
              <input type="text" name="q" class="form-control" value="<?= e($query); ?>" placeholder="Details, request path, IP, request id">
            </div>
          </div>
          <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-primary">Apply Filters</button>
            <a href="<?= e(url('modules/system/audit_logs.php')); ?>" class="btn btn-outline-secondary">Reset</a>
            <a href="<?= e($exportCsvUrl); ?>" class="btn btn-outline-success">Export CSV</a>
          </div>
        </form>
      </div>

      <?php if ($canRetentionManage): ?>
        <div class="card card-outline card-warning mb-3">
          <div class="card-header">
            <h3 class="card-title">Retention Policy</h3>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-lg-4">
                <form method="post" class="row g-2">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="set_audit_retention">
                  <div class="col-12">
                    <label class="form-label">Keep Logs For (Days)</label>
                    <input type="number" name="audit_retention_days" min="7" max="3650" class="form-control" value="<?= (int) $auditRetentionDays; ?>" required>
                    <div class="form-text">Default suggested policy: 180 days.</div>
                  </div>
                  <div class="col-12">
                    <button type="submit" class="btn btn-warning">Save Retention Policy</button>
                  </div>
                </form>
              </div>
              <div class="col-lg-8">
                <div class="row g-2 mb-3">
                  <div class="col-md-4">
                    <div class="border rounded p-2 h-100 bg-light">
                      <div class="small text-muted">Active Logs (Scope)</div>
                      <div class="fw-semibold fs-5"><?= number_format((int) ($retentionStats['active_total'] ?? 0)); ?></div>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="border rounded p-2 h-100 bg-light">
                      <div class="small text-muted">Eligible for Cleanup</div>
                      <div class="fw-semibold fs-5"><?= number_format((int) ($retentionStats['eligible_count'] ?? 0)); ?></div>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="border rounded p-2 h-100 bg-light">
                      <div class="small text-muted">Archived Logs (Scope)</div>
                      <div class="fw-semibold fs-5"><?= number_format((int) ($retentionStats['archive_total'] ?? 0)); ?></div>
                    </div>
                  </div>
                </div>

                <form method="post" class="row g-2" data-confirm="Run retention now? This will archive and/or delete old audit logs based on selected mode.">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="run_audit_retention">
                  <div class="col-md-4">
                    <label class="form-label">Retention Window</label>
                    <input type="number" name="audit_retention_days" min="7" max="3650" class="form-control" value="<?= (int) $auditRetentionDays; ?>" required>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Cleanup Mode</label>
                    <select name="retention_mode" class="form-select">
                      <option value="archive_delete">Archive then Delete</option>
                      <option value="delete_only">Delete Only</option>
                    </select>
                  </div>
                  <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-danger w-100">Run Retention Now</button>
                  </div>
                  <div class="col-12">
                    <div class="small text-muted">
                      Cutoff timestamp for current policy: <?= e($retentionCutoffAt); ?>. Scope: <?= e($scopeGarageLabel); ?>.
                    </div>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Audit Entries (Latest 300)</h3>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th>When</th>
                <th>Who</th>
                <th>Role</th>
                <th>Where</th>
                <th>Module</th>
                <th>Entity</th>
                <th>Action</th>
                <th>Source</th>
                <th>Network</th>
                <th>Request</th>
                <th>Reference</th>
                <th>Details</th>
                <th>Snapshots</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($auditRows)): ?>
                <tr><td colspan="13" class="text-center text-muted py-4">No audit entries for selected scope.</td></tr>
              <?php else: ?>
                <?php foreach ($auditRows as $row): ?>
                  <?php
                    $beforeJson = (string) ($row['before_snapshot'] ?? '');
                    $afterJson = (string) ($row['after_snapshot'] ?? '');
                    $beforeText = audit_logs_json_pretty($beforeJson);
                    $afterText = audit_logs_json_pretty($afterJson);
                    $metadataJson = (string) ($row['metadata_json'] ?? '');
                    $metadata = audit_logs_decode_json($metadataJson);
                    $requestMethod = strtoupper(trim((string) ($metadata['request_method'] ?? '')));
                    $requestPath = trim((string) ($metadata['request_path'] ?? ''));
                    $requestLine = trim($requestMethod . ' /' . ltrim($requestPath, '/'));
                    if ($requestMethod === '' && $requestPath !== '') {
                        $requestLine = '/' . ltrim($requestPath, '/');
                    } elseif ($requestMethod !== '' && $requestPath === '') {
                        $requestLine = $requestMethod;
                    }
                    $requestQuery = $metadata['request_query'] ?? $metadata['query'] ?? null;
                    $requestQueryText = '';
                    if (is_array($requestQuery) && $requestQuery !== []) {
                        $requestQueryEncoded = json_encode($requestQuery, JSON_UNESCAPED_UNICODE);
                        $requestQueryText = is_string($requestQueryEncoded) ? $requestQueryEncoded : '';
                    }
                    $postAction = trim((string) ($metadata['post_action'] ?? ''));
                    $userAgent = trim((string) ($metadata['user_agent'] ?? ''));
                    $referrer = trim((string) ($metadata['referrer'] ?? ''));
                    $who = (string) ($row['user_name'] ?? '');
                    if ($who === '') {
                        $who = 'System';
                    }
                  ?>
                  <tr>
                    <td><?= e((string) $row['created_at']); ?></td>
                    <td>
                      <?= e($who); ?>
                      <?php if (!empty($row['username'])): ?>
                        <div class="small text-muted">@<?= e((string) $row['username']); ?></div>
                      <?php endif; ?>
                    </td>
                    <td><?= e((string) ($row['role_key'] ?? '-')); ?></td>
                    <td><?= e((string) ($row['garage_name'] ?? 'Company')); ?></td>
                    <td><code><?= e((string) $row['module_name']); ?></code></td>
                    <td><?= e((string) ($row['entity_name'] ?? '-')); ?></td>
                    <td><span class="badge text-bg-secondary"><?= e((string) $row['action_name']); ?></span></td>
                    <td><?= e((string) ($row['source_channel'] ?? '-')); ?></td>
                    <td>
                      <?= e((string) ($row['ip_address'] ?? '-')); ?>
                      <?php if ($userAgent !== ''): ?>
                        <div class="small text-muted text-break"><?= e(mb_substr($userAgent, 0, 120)); ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($requestLine !== ''): ?>
                        <code><?= e($requestLine); ?></code>
                      <?php else: ?>
                        <span class="text-muted">-</span>
                      <?php endif; ?>
                      <?php if (!empty($row['request_id'])): ?>
                        <div class="small text-muted">Req: <?= e((string) $row['request_id']); ?></div>
                      <?php endif; ?>
                      <?php if ($postAction !== ''): ?>
                        <div class="small text-muted">Action: <?= e($postAction); ?></div>
                      <?php endif; ?>
                      <?php if ($requestQueryText !== ''): ?>
                        <div class="small text-muted text-break">Query: <?= e($requestQueryText); ?></div>
                      <?php endif; ?>
                      <?php if ($referrer !== ''): ?>
                        <div class="small text-muted text-break">Ref: <?= e($referrer); ?></div>
                      <?php endif; ?>
                    </td>
                    <td><?= $row['reference_id'] !== null ? (int) $row['reference_id'] : '-'; ?></td>
                    <td><?= e((string) ($row['details'] ?? '-')); ?></td>
                    <td>
                      <?php if ($beforeText === '' && $afterText === ''): ?>
                        <span class="text-muted">-</span>
                      <?php else: ?>
                        <details>
                          <summary class="small">View</summary>
                          <?php if ($beforeText !== '' && $beforeText !== 'null'): ?>
                            <div class="small text-muted mt-1">Before</div>
                            <pre class="small mb-2"><?= e((string) $beforeText); ?></pre>
                          <?php endif; ?>
                          <?php if ($afterText !== '' && $afterText !== 'null'): ?>
                            <div class="small text-muted">After</div>
                            <pre class="small mb-0"><?= e((string) $afterText); ?></pre>
                          <?php endif; ?>
                        </details>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
