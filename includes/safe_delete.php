<?php
declare(strict_types=1);

if (!defined('SAFE_DELETE_PREVIEW_TTL_SECONDS')) {
    define('SAFE_DELETE_PREVIEW_TTL_SECONDS', 900);
}
if (!defined('SAFE_DELETE_GROUP_ITEM_LIMIT')) {
    define('SAFE_DELETE_GROUP_ITEM_LIMIT', 25);
}
require_once __DIR__ . '/safe_delete_generic_entities.php';
require_once __DIR__ . '/safe_delete_dependency_actions.php';

function safe_delete_normalize_entity(string $entity): string
{
    $value = strtolower(trim($entity));
    $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '';
    return trim($value, '_');
}

function safe_delete_supported_entities(): array
{
    $entities = [
        'invoice',
        'invoice_payment',
        'billing_advance',
        'purchase',
        'purchase_payment',
        'job_card',
        'return',
        'return_settlement',
        'expense',
        'expense_category',
        'outsourced_payment',
        'customer',
        'vendor',
        'vehicle',
        'payroll_salary_structure',
        'payroll_advance',
        'payroll_loan',
        'payroll_loan_payment',
        'payroll_salary_entry',
        'payroll_salary_payment',
        'inventory_unit',
    ];

    return array_values(array_unique(array_merge($entities, array_keys(safe_delete_generic_entity_configs()))));
}

function safe_delete_entity_label(string $entity): string
{
    $entity = safe_delete_normalize_entity($entity);
    $genericConfig = safe_delete_generic_entity_configs()[$entity] ?? null;
    if (is_array($genericConfig) && trim((string) ($genericConfig['label'] ?? '')) !== '') {
        return trim((string) $genericConfig['label']);
    }
    return match ($entity) {
        'invoice' => 'Invoice',
        'invoice_payment' => 'Invoice Payment',
        'billing_advance' => 'Advance Receipt',
        'purchase' => 'Purchase',
        'purchase_payment' => 'Purchase Payment',
        'job_card' => 'Job Card',
        'return' => 'Return',
        'return_settlement' => 'Return Settlement',
        'expense' => 'Expense',
        'expense_category' => 'Expense Category',
        'outsourced_payment' => 'Outsourced Payment',
        'customer' => 'Customer',
        'vendor' => 'Vendor',
        'vehicle' => 'Vehicle',
        'payroll_salary_structure' => 'Payroll Salary Structure',
        'payroll_advance' => 'Payroll Advance',
        'payroll_loan' => 'Payroll Loan',
        'payroll_loan_payment' => 'Payroll Loan Payment',
        'payroll_salary_entry' => 'Payroll Salary Entry',
        'payroll_salary_payment' => 'Payroll Salary Payment',
        'inventory_unit' => 'Part Unit',
        default => ucwords(str_replace('_', ' ', $entity)),
    };
}

function safe_delete_is_financial_entity(string $entity): bool
{
    return in_array(safe_delete_normalize_entity($entity), [
        'invoice',
        'invoice_payment',
        'billing_advance',
        'purchase',
        'purchase_payment',
        'return',
        'return_settlement',
        'expense',
        'outsourced_payment',
        'payroll_advance',
        'payroll_loan',
        'payroll_loan_payment',
        'payroll_salary_entry',
        'payroll_salary_payment',
    ], true);
}

function safe_delete_permission_key_exists(string $permissionKey): bool
{
    static $cache = [];

    $permissionKey = strtolower(trim($permissionKey));
    if ($permissionKey === '') {
        return false;
    }
    if (array_key_exists($permissionKey, $cache)) {
        return (bool) $cache[$permissionKey];
    }
    if (table_columns('permissions') === []) {
        $cache[$permissionKey] = false;
        return false;
    }

    try {
        $stmt = db()->prepare('SELECT id FROM permissions WHERE perm_key = :perm_key LIMIT 1');
        $stmt->execute(['perm_key' => $permissionKey]);
        $cache[$permissionKey] = (bool) $stmt->fetchColumn();
    } catch (Throwable $exception) {
        $cache[$permissionKey] = false;
    }

    return (bool) $cache[$permissionKey];
}

function safe_delete_require_global_permissions(string $entity): void
{
    $entity = safe_delete_normalize_entity($entity);

    if (safe_delete_permission_key_exists('record.delete') && !has_permission('record.delete')) {
        throw new RuntimeException('Global delete permission (`record.delete`) is required.');
    }
    if (safe_delete_is_financial_entity($entity)
        && safe_delete_permission_key_exists('financial.reverse')
        && !has_permission('financial.reverse')
    ) {
        throw new RuntimeException('Financial reversal permission (`financial.reverse`) is required for this record.');
    }
}

function safe_delete_require_dependency_resolution_permission(): void
{
    if (safe_delete_permission_key_exists('dependency.resolve') && !has_permission('dependency.resolve')) {
        throw new RuntimeException('Dependency resolution permission (`dependency.resolve`) is required.');
    }
}

function safe_delete_make_item(
    string $reference,
    ?string $date = null,
    ?float $amount = null,
    ?string $status = null,
    ?string $note = null,
    ?string $related = null,
    array $actions = []
): array {
    return [
        'reference' => trim($reference),
        'date' => $date !== null ? trim($date) : null,
        'amount' => $amount !== null ? round($amount, 2) : null,
        'status' => $status !== null ? trim($status) : null,
        'note' => $note !== null ? trim($note) : null,
        'related' => $related !== null ? trim($related) : null,
        'actions' => $actions,
    ];
}

function safe_delete_make_dependency_action(
    string $label,
    string $entity,
    int $recordId = 0,
    string $operation = 'delete',
    ?string $recordKey = null,
    bool $enabled = true,
    string $style = 'outline-danger',
    bool $requireBeforeParent = false,
    ?string $hint = null
): array {
    return [
        'label' => trim($label),
        'entity' => safe_delete_normalize_entity($entity),
        'record_id' => max(0, $recordId),
        'record_key' => $recordKey !== null ? trim($recordKey) : '',
        'operation' => strtolower(trim($operation)) !== '' ? strtolower(trim($operation)) : 'delete',
        'enabled' => $enabled,
        'style' => trim($style) !== '' ? trim($style) : 'outline-secondary',
        'require_before_parent' => $requireBeforeParent,
        'hint' => $hint !== null ? trim($hint) : '',
    ];
}

function safe_delete_make_dependency_action_auto(string $label = 'Auto on Final Delete', ?string $hint = null): array
{
    return [
        'label' => trim($label) !== '' ? trim($label) : 'Auto on Final Delete',
        'entity' => '',
        'record_id' => 0,
        'record_key' => '',
        'operation' => 'auto',
        'enabled' => false,
        'style' => 'outline-secondary',
        'require_before_parent' => false,
        'hint' => $hint !== null ? trim($hint) : '',
    ];
}

function safe_delete_make_dependency_action_unavailable(string $label = 'No Direct Action', ?string $hint = null): array
{
    return [
        'label' => trim($label) !== '' ? trim($label) : 'No Direct Action',
        'entity' => '',
        'record_id' => 0,
        'record_key' => '',
        'operation' => 'none',
        'enabled' => false,
        'style' => 'outline-secondary',
        'require_before_parent' => false,
        'hint' => $hint !== null ? trim($hint) : '',
    ];
}

function safe_delete_make_group(
    string $key,
    string $label,
    int $count,
    array $items = [],
    ?float $financialImpact = null,
    ?string $warning = null
): array {
    $cleanItems = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $reference = trim((string) ($item['reference'] ?? ''));
        if ($reference === '') {
            continue;
        }
        $cleanItems[] = [
            'reference' => $reference,
            'date' => ($item['date'] ?? null) !== null ? trim((string) $item['date']) : null,
            'amount' => isset($item['amount']) && $item['amount'] !== null ? round((float) $item['amount'], 2) : null,
            'status' => ($item['status'] ?? null) !== null ? trim((string) $item['status']) : null,
            'note' => ($item['note'] ?? null) !== null ? trim((string) $item['note']) : null,
            'related' => ($item['related'] ?? null) !== null ? trim((string) $item['related']) : null,
            'actions' => safe_delete_normalize_dependency_actions((array) ($item['actions'] ?? [])),
        ];
    }

    $manualActionCount = 0;
    $pendingResolutionCount = 0;
    foreach ($cleanItems as $cleanItem) {
        foreach ((array) ($cleanItem['actions'] ?? []) as $action) {
            if (!is_array($action)) {
                continue;
            }
            if ((bool) ($action['enabled'] ?? false) && safe_delete_normalize_entity((string) ($action['entity'] ?? '')) !== '') {
                $manualActionCount++;
                if ((bool) ($action['require_before_parent'] ?? false)) {
                    $pendingResolutionCount++;
                }
            }
        }
    }

    $remainingCount = max(0, count($cleanItems) - SAFE_DELETE_GROUP_ITEM_LIMIT);
    if (count($cleanItems) > SAFE_DELETE_GROUP_ITEM_LIMIT) {
        $cleanItems = array_slice($cleanItems, 0, SAFE_DELETE_GROUP_ITEM_LIMIT);
    }

    return [
        'key' => safe_delete_normalize_entity($key),
        'label' => trim($label) !== '' ? trim($label) : ucfirst($key),
        'count' => max(0, $count),
        'financial_impact' => $financialImpact !== null ? round($financialImpact, 2) : 0.0,
        'warning' => $warning !== null ? trim($warning) : '',
        'items' => $cleanItems,
        'remaining_count' => $remainingCount,
        'manual_action_count' => $manualActionCount,
        'pending_resolution_count' => $pendingResolutionCount,
    ];
}

function safe_delete_normalize_dependency_actions(array $actions): array
{
    $normalized = [];
    foreach ($actions as $action) {
        if (!is_array($action)) {
            continue;
        }
        $label = trim((string) ($action['label'] ?? ''));
        $entity = safe_delete_normalize_entity((string) ($action['entity'] ?? ''));
        $operation = strtolower(trim((string) ($action['operation'] ?? 'delete')));
        $recordId = max(0, (int) ($action['record_id'] ?? 0));
        $recordKey = trim((string) ($action['record_key'] ?? ''));
        $enabled = (bool) ($action['enabled'] ?? false);
        $style = trim((string) ($action['style'] ?? 'outline-secondary'));
        $requireBeforeParent = (bool) ($action['require_before_parent'] ?? false);
        $hint = trim((string) ($action['hint'] ?? ''));

        if ($label === '') {
            if ($operation === 'reverse') {
                $label = 'Reverse';
            } elseif ($operation === 'delete') {
                $label = 'Delete';
            } elseif ($operation === 'auto') {
                $label = 'Auto on Final Delete';
            } else {
                $label = 'Action';
            }
        }

        if ($enabled && $entity === '') {
            $enabled = false;
        }
        if ($enabled && $recordId <= 0 && $recordKey === '') {
            $enabled = false;
        }

        $normalized[] = [
            'label' => $label,
            'entity' => $entity,
            'record_id' => $recordId,
            'record_key' => $recordKey,
            'operation' => $operation !== '' ? $operation : 'delete',
            'enabled' => $enabled,
            'style' => $style !== '' ? $style : 'outline-secondary',
            'require_before_parent' => $requireBeforeParent && $enabled,
            'hint' => $hint,
        ];
    }
    return $normalized;
}

function safe_delete_summary_base(string $entity, int $recordId, string $operation = 'delete'): array
{
    return [
        'entity' => safe_delete_normalize_entity($entity),
        'record_id' => max(0, $recordId),
        'operation' => strtolower(trim($operation)) !== '' ? strtolower(trim($operation)) : 'delete',
        'title' => 'Deletion Impact Summary',
        'entity_label' => safe_delete_entity_label($entity),
        'main_record' => [
            'label' => '',
            'reference' => '',
            'date' => null,
            'amount' => null,
            'status' => null,
            'note' => null,
        ],
        'groups' => [],
        'total_dependencies' => 0,
        'total_financial_impact' => 0.0,
        'blockers' => [],
        'warnings' => [],
        'severity' => 'normal',
        'can_proceed' => false,
        'recommended_action' => 'BLOCK',
        'execution_mode' => 'block',
        'requires_strong_confirmation' => true,
        'requires_reason' => true,
        'requires_dependency_clearance' => false,
        'pending_dependency_resolutions' => 0,
    ];
}

function safe_delete_summary_base_key(string $entity, string $recordKey, string $operation = 'delete'): array
{
    $summary = safe_delete_summary_base($entity, 0, $operation);
    $summary['record_key'] = trim($recordKey);
    return $summary;
}

function safe_delete_summary_finalize(array $summary): array
{
    $groups = [];
    $totalDependencies = 0;
    $totalFinancialImpact = 0.0;
    $pendingDependencyResolutions = 0;
    $hasManualDependencyActions = false;

    foreach ((array) ($summary['groups'] ?? []) as $group) {
        if (!is_array($group)) {
            continue;
        }
        $count = max(0, (int) ($group['count'] ?? 0));
        if ($count <= 0) {
            continue;
        }
        $totalDependencies += $count;
        $totalFinancialImpact += (float) ($group['financial_impact'] ?? 0);
        $pendingDependencyResolutions += max(0, (int) ($group['pending_resolution_count'] ?? 0));
        if ((int) ($group['manual_action_count'] ?? 0) > 0) {
            $hasManualDependencyActions = true;
        }
        $groups[] = $group;
    }

    $summary['groups'] = $groups;
    $summary['total_dependencies'] = $totalDependencies;
    $summary['total_financial_impact'] = round($totalFinancialImpact, 2);
    $summary['pending_dependency_resolutions'] = max(0, $pendingDependencyResolutions);
    $summary['has_manual_dependency_actions'] = $hasManualDependencyActions;
    $summary['requires_dependency_clearance'] = ((bool) ($summary['requires_dependency_clearance'] ?? false))
        || $pendingDependencyResolutions > 0;

    $summary['blockers'] = array_values(array_unique(array_filter(array_map(
        static fn (mixed $v): string => trim((string) $v),
        (array) ($summary['blockers'] ?? [])
    ))));
    $summary['warnings'] = array_values(array_unique(array_filter(array_map(
        static fn (mixed $v): string => trim((string) $v),
        (array) ($summary['warnings'] ?? [])
    ))));

    $severity = 'normal';
    if ($summary['blockers'] !== []) {
        $severity = 'high';
    } elseif ((float) $summary['total_financial_impact'] >= 100000 || (int) $summary['total_dependencies'] >= 10) {
        $severity = 'high';
    } elseif ((float) $summary['total_financial_impact'] >= 10000 || (int) $summary['total_dependencies'] >= 4 || $summary['warnings'] !== []) {
        $severity = 'medium';
    }
    $summary['severity'] = $severity;

    return $summary;
}

function safe_delete_compact_summary_for_token(array $summary): array
{
    $groupCounts = [];
    foreach ((array) ($summary['groups'] ?? []) as $group) {
        if (!is_array($group)) {
            continue;
        }
        $groupCounts[] = [
            'key' => (string) ($group['key'] ?? ''),
            'label' => (string) ($group['label'] ?? ''),
            'count' => (int) ($group['count'] ?? 0),
            'financial_impact' => round((float) ($group['financial_impact'] ?? 0), 2),
        ];
    }

    return [
        'entity' => (string) ($summary['entity'] ?? ''),
        'record_id' => (int) ($summary['record_id'] ?? 0),
        'record_key' => trim((string) ($summary['record_key'] ?? '')),
        'operation' => (string) ($summary['operation'] ?? 'delete'),
        'main_record' => [
            'label' => (string) (($summary['main_record']['label'] ?? '') ?: ''),
            'reference' => (string) (($summary['main_record']['reference'] ?? '') ?: ''),
            'amount' => isset($summary['main_record']['amount']) ? round((float) $summary['main_record']['amount'], 2) : null,
            'date' => (string) (($summary['main_record']['date'] ?? '') ?: ''),
        ],
        'group_counts' => $groupCounts,
        'total_dependencies' => (int) ($summary['total_dependencies'] ?? 0),
        'total_financial_impact' => round((float) ($summary['total_financial_impact'] ?? 0), 2),
        'blockers' => array_values((array) ($summary['blockers'] ?? [])),
        'warnings' => array_values((array) ($summary['warnings'] ?? [])),
        'can_proceed' => (bool) ($summary['can_proceed'] ?? false),
        'recommended_action' => (string) ($summary['recommended_action'] ?? 'BLOCK'),
        'execution_mode' => (string) ($summary['execution_mode'] ?? 'block'),
        'severity' => (string) ($summary['severity'] ?? 'normal'),
        'requires_dependency_clearance' => (bool) ($summary['requires_dependency_clearance'] ?? false),
        'pending_dependency_resolutions' => (int) ($summary['pending_dependency_resolutions'] ?? 0),
    ];
}

function safe_delete_preview_store_key(): string
{
    return '_safe_delete_preview_tokens';
}

function safe_delete_issue_preview_token(array $summary): string
{
    $entity = safe_delete_normalize_entity((string) ($summary['entity'] ?? ''));
    $recordId = (int) ($summary['record_id'] ?? 0);
    $recordKey = trim((string) ($summary['record_key'] ?? ''));
    $operation = strtolower(trim((string) ($summary['operation'] ?? 'delete')));

    if ($entity === '' || ($recordId <= 0 && $recordKey === '')) {
        throw new RuntimeException('Unable to issue safe-delete preview token for invalid summary.');
    }

    $token = bin2hex(random_bytes(24));
    $compactSummary = safe_delete_compact_summary_for_token($summary);

    if (!isset($_SESSION[safe_delete_preview_store_key()]) || !is_array($_SESSION[safe_delete_preview_store_key()])) {
        $_SESSION[safe_delete_preview_store_key()] = [];
    }

    $_SESSION[safe_delete_preview_store_key()][$token] = [
        'entity' => $entity,
        'record_id' => $recordId,
        'record_key' => $recordKey,
        'operation' => $operation !== '' ? $operation : 'delete',
        'summary' => $compactSummary,
        'user_id' => (int) ($_SESSION['user_id'] ?? 0),
        'company_id' => active_company_id(),
        'garage_id' => active_garage_id(),
        'created_at' => time(),
    ];

    foreach ((array) $_SESSION[safe_delete_preview_store_key()] as $existingToken => $payload) {
        if (!is_array($payload)) {
            unset($_SESSION[safe_delete_preview_store_key()][$existingToken]);
            continue;
        }
        $createdAt = (int) ($payload['created_at'] ?? 0);
        if ($createdAt <= 0 || (time() - $createdAt) > SAFE_DELETE_PREVIEW_TTL_SECONDS) {
            unset($_SESSION[safe_delete_preview_store_key()][$existingToken]);
        }
    }

    return $token;
}

function safe_delete_get_preview_token_payload(string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }

    $store = $_SESSION[safe_delete_preview_store_key()] ?? null;
    if (!is_array($store)) {
        return null;
    }

    $payload = $store[$token] ?? null;
    return is_array($payload) ? $payload : null;
}

function safe_delete_consume_preview_token(string $token): void
{
    $token = trim($token);
    if ($token === '') {
        return;
    }
    if (isset($_SESSION[safe_delete_preview_store_key()]) && is_array($_SESSION[safe_delete_preview_store_key()])) {
        unset($_SESSION[safe_delete_preview_store_key()][$token]);
    }
}

function safe_delete_validate_post_confirmation(string $entity, int $recordId, array $options = []): array
{
    $entity = safe_delete_normalize_entity($entity);
    $recordId = max(0, $recordId);
    $expectedOperation = strtolower(trim((string) ($options['operation'] ?? 'delete')));
    $reasonField = trim((string) ($options['reason_field'] ?? ''));
    $allowCheckboxOnly = (bool) ($options['allow_checkbox_only'] ?? true);
    $consumeToken = (bool) ($options['consume_token'] ?? true);

    if ($entity === '' || $recordId <= 0) {
        throw new RuntimeException('Invalid safe-delete validation scope.');
    }

    safe_delete_require_global_permissions($entity);

    $previewToken = trim((string) ($_POST['_safe_delete_preview_token'] ?? ''));
    if ($previewToken === '') {
        throw new RuntimeException('Deletion impact preview is required before confirmation.');
    }

    $payload = safe_delete_get_preview_token_payload($previewToken);
    if (!is_array($payload)) {
        throw new RuntimeException('Deletion preview expired. Open the impact summary again and confirm.');
    }

    $createdAt = (int) ($payload['created_at'] ?? 0);
    if ($createdAt <= 0 || (time() - $createdAt) > SAFE_DELETE_PREVIEW_TTL_SECONDS) {
        safe_delete_consume_preview_token($previewToken);
        throw new RuntimeException('Deletion preview expired. Open the impact summary again and confirm.');
    }

    if ((int) ($payload['user_id'] ?? 0) !== (int) ($_SESSION['user_id'] ?? 0)
        || (int) ($payload['company_id'] ?? 0) !== active_company_id()
        || (int) ($payload['garage_id'] ?? 0) !== active_garage_id()
    ) {
        safe_delete_consume_preview_token($previewToken);
        throw new RuntimeException('Deletion preview scope changed. Re-open the impact summary and retry.');
    }

    if (safe_delete_normalize_entity((string) ($payload['entity'] ?? '')) !== $entity
        || (int) ($payload['record_id'] ?? 0) !== $recordId
    ) {
        safe_delete_consume_preview_token($previewToken);
        throw new RuntimeException('Deletion preview does not match the selected record.');
    }

    if ($expectedOperation !== '' && strtolower((string) ($payload['operation'] ?? '')) !== $expectedOperation) {
        safe_delete_consume_preview_token($previewToken);
        throw new RuntimeException('Deletion preview action does not match this request.');
    }

    $confirmText = trim((string) ($_POST['_safe_delete_confirm_text'] ?? ''));
    $confirmChecked = (string) ($_POST['_safe_delete_confirm_checked'] ?? '') === '1';
    $typedConfirmed = strtoupper($confirmText) === 'CONFIRM';
    if (!$typedConfirmed && !($allowCheckboxOnly && $confirmChecked)) {
        throw new RuntimeException('Strong confirmation is required. Type CONFIRM or tick the confirmation checkbox.');
    }

    $reason = '';
    if ($reasonField !== '') {
        $reason = post_string($reasonField, 255);
        if ($reason === '') {
            throw new RuntimeException('A deletion/reversal reason is required.');
        }
    }

    $summary = is_array($payload['summary'] ?? null) ? (array) $payload['summary'] : [];
    if (!(bool) ($summary['can_proceed'] ?? false)) {
        $blockers = array_values(array_filter(array_map('trim', (array) ($summary['blockers'] ?? []))));
        $message = 'Deletion is blocked by dependency rules.';
        if ($blockers !== []) {
            $message .= ' ' . implode(' ', $blockers);
        }
        throw new RuntimeException($message);
    }

    $requiresDependencyClearance = (bool) ($summary['requires_dependency_clearance'] ?? false);
    $pendingDependencyResolutions = max(0, (int) ($summary['pending_dependency_resolutions'] ?? 0));
    if ($requiresDependencyClearance && $pendingDependencyResolutions > 0) {
        throw new RuntimeException('Resolve required dependency actions before final deletion/reversal.');
    }

    if ($consumeToken) {
        safe_delete_consume_preview_token($previewToken);
    }

    return [
        'token' => $previewToken,
        'entity' => $entity,
        'record_id' => $recordId,
        'record_key' => trim((string) ($payload['record_key'] ?? '')),
        'operation' => strtolower((string) ($payload['operation'] ?? $expectedOperation)),
        'summary' => $summary,
        'reason' => $reason,
        'confirm_text' => $confirmText,
        'confirm_checked' => $confirmChecked,
    ];
}

function safe_delete_validate_post_confirmation_key(string $entity, string $recordKey, array $options = []): array
{
    $entity = safe_delete_normalize_entity($entity);
    $recordKey = trim($recordKey);
    $expectedOperation = strtolower(trim((string) ($options['operation'] ?? 'delete')));
    $reasonField = trim((string) ($options['reason_field'] ?? ''));
    $allowCheckboxOnly = (bool) ($options['allow_checkbox_only'] ?? true);
    $consumeToken = (bool) ($options['consume_token'] ?? true);

    if ($entity === '' || $recordKey === '') {
        throw new RuntimeException('Invalid safe-delete validation scope.');
    }

    safe_delete_require_global_permissions($entity);

    $previewToken = trim((string) ($_POST['_safe_delete_preview_token'] ?? ''));
    if ($previewToken === '') {
        throw new RuntimeException('Deletion impact preview is required before confirmation.');
    }

    $payload = safe_delete_get_preview_token_payload($previewToken);
    if (!is_array($payload)) {
        throw new RuntimeException('Deletion preview expired. Open the impact summary again and confirm.');
    }

    $createdAt = (int) ($payload['created_at'] ?? 0);
    if ($createdAt <= 0 || (time() - $createdAt) > SAFE_DELETE_PREVIEW_TTL_SECONDS) {
        safe_delete_consume_preview_token($previewToken);
        throw new RuntimeException('Deletion preview expired. Open the impact summary again and confirm.');
    }

    if ((int) ($payload['user_id'] ?? 0) !== (int) ($_SESSION['user_id'] ?? 0)
        || (int) ($payload['company_id'] ?? 0) !== active_company_id()
        || (int) ($payload['garage_id'] ?? 0) !== active_garage_id()
    ) {
        safe_delete_consume_preview_token($previewToken);
        throw new RuntimeException('Deletion preview scope changed. Re-open the impact summary and retry.');
    }

    if (safe_delete_normalize_entity((string) ($payload['entity'] ?? '')) !== $entity
        || trim((string) ($payload['record_key'] ?? '')) !== $recordKey
    ) {
        safe_delete_consume_preview_token($previewToken);
        throw new RuntimeException('Deletion preview does not match the selected record.');
    }

    if ($expectedOperation !== '' && strtolower((string) ($payload['operation'] ?? '')) !== $expectedOperation) {
        safe_delete_consume_preview_token($previewToken);
        throw new RuntimeException('Deletion preview action does not match this request.');
    }

    $confirmText = trim((string) ($_POST['_safe_delete_confirm_text'] ?? ''));
    $confirmChecked = (string) ($_POST['_safe_delete_confirm_checked'] ?? '') === '1';
    $typedConfirmed = strtoupper($confirmText) === 'CONFIRM';
    if (!$typedConfirmed && !($allowCheckboxOnly && $confirmChecked)) {
        throw new RuntimeException('Strong confirmation is required. Type CONFIRM or tick the confirmation checkbox.');
    }

    $reason = '';
    if ($reasonField !== '') {
        $reason = post_string($reasonField, 255);
        if ($reason === '') {
            throw new RuntimeException('A deletion/reversal reason is required.');
        }
    }

    $summary = is_array($payload['summary'] ?? null) ? (array) $payload['summary'] : [];
    if (!(bool) ($summary['can_proceed'] ?? false)) {
        $blockers = array_values(array_filter(array_map('trim', (array) ($summary['blockers'] ?? []))));
        $message = 'Deletion is blocked by dependency rules.';
        if ($blockers !== []) {
            $message .= ' ' . implode(' ', $blockers);
        }
        throw new RuntimeException($message);
    }

    $requiresDependencyClearance = (bool) ($summary['requires_dependency_clearance'] ?? false);
    $pendingDependencyResolutions = max(0, (int) ($summary['pending_dependency_resolutions'] ?? 0));
    if ($requiresDependencyClearance && $pendingDependencyResolutions > 0) {
        throw new RuntimeException('Resolve required dependency actions before final deletion/reversal.');
    }

    if ($consumeToken) {
        safe_delete_consume_preview_token($previewToken);
    }

    return [
        'token' => $previewToken,
        'entity' => $entity,
        'record_id' => 0,
        'record_key' => $recordKey,
        'operation' => strtolower((string) ($payload['operation'] ?? $expectedOperation)),
        'summary' => $summary,
        'reason' => $reason,
        'confirm_text' => $confirmText,
        'confirm_checked' => $confirmChecked,
    ];
}

function safe_delete_log_cascade(string $targetEntity, string $event, int $recordId, array $validation, array $extra = []): void
{
    try {
        $summary = is_array($validation['summary'] ?? null) ? (array) $validation['summary'] : [];
        $metadata = [
            'target_entity' => safe_delete_normalize_entity($targetEntity),
            'event' => trim($event),
            'record_id' => max(0, $recordId),
            'record_key' => trim((string) ($validation['record_key'] ?? ($summary['record_key'] ?? ''))),
            'operation' => (string) ($validation['operation'] ?? ($summary['operation'] ?? 'delete')),
            'main_record' => (array) ($summary['main_record'] ?? []),
            'total_dependencies' => (int) ($summary['total_dependencies'] ?? 0),
            'total_financial_impact' => round((float) ($summary['total_financial_impact'] ?? 0), 2),
            'severity' => (string) ($summary['severity'] ?? 'normal'),
            'group_counts' => (array) ($summary['group_counts'] ?? []),
            'warnings' => array_values((array) ($summary['warnings'] ?? [])),
            'reversal_references' => array_values(array_filter(array_map(
                static fn (mixed $ref): string => trim((string) $ref),
                (array) ($extra['reversal_references'] ?? [])
            ))),
        ];
        if (trim((string) ($validation['reason'] ?? '')) !== '') {
            $metadata['deletion_reason'] = (string) $validation['reason'];
        }
        if (isset($extra['metadata']) && is_array($extra['metadata'])) {
            $metadata = array_merge($metadata, $extra['metadata']);
        }

        log_audit('safe_delete', 'cascade_execute', $recordId > 0 ? $recordId : null, 'Safe delete cascade executed', [
            'entity' => 'safe_delete',
            'source' => 'SAFE_DELETE',
            'metadata' => $metadata,
        ]);
    } catch (Throwable $exception) {
        // Do not block primary transaction flow.
    }
}

function safe_delete_table_exists(string $tableName): bool
{
    return table_columns($tableName) !== [];
}

function safe_delete_table_has_column(string $tableName, string $columnName): bool
{
    return in_array($columnName, table_columns($tableName), true);
}

function safe_delete_table_has_all_columns(string $tableName, array $columns): bool
{
    $tableColumns = table_columns($tableName);
    if ($tableColumns === []) {
        return false;
    }
    foreach ($columns as $column) {
        if (!in_array((string) $column, $tableColumns, true)) {
            return false;
        }
    }
    return true;
}

function safe_delete_fetch_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function safe_delete_fetch_row(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function safe_delete_fetch_scalar(PDO $pdo, string $sql, array $params = []): mixed
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function safe_delete_pick_date(array $row, array $candidates): ?string
{
    foreach ($candidates as $column) {
        $value = trim((string) ($row[$column] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return null;
}

function safe_delete_pick_amount(array $row, array $candidates): ?float
{
    foreach ($candidates as $column) {
        if (!array_key_exists($column, $row) || $row[$column] === null || $row[$column] === '') {
            continue;
        }
        return round((float) $row[$column], 2);
    }
    return null;
}

function safe_delete_row_reference(array $row, array $candidates, string $fallbackPrefix, string $idKey = 'id'): string
{
    foreach ($candidates as $column) {
        $value = trim((string) ($row[$column] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    $id = (int) ($row[$idKey] ?? 0);
    return $id > 0 ? ($fallbackPrefix . '#' . $id) : $fallbackPrefix;
}

function safe_delete_analyze(PDO $pdo, string $entity, int $recordId, array $scope = [], array $options = []): array
{
    $entity = safe_delete_normalize_entity($entity);
    if (!in_array($entity, safe_delete_supported_entities(), true)) {
        throw new RuntimeException('Safe delete preview is not configured for entity: ' . ($entity !== '' ? $entity : '(empty)'));
    }
    if ($recordId <= 0) {
        throw new RuntimeException('Invalid record selected for safe delete preview.');
    }

    return match ($entity) {
        'invoice' => safe_delete_analyze_invoice($pdo, $recordId, $scope, $options),
        'invoice_payment' => safe_delete_analyze_invoice_payment($pdo, $recordId, $scope, $options),
        'billing_advance' => safe_delete_analyze_billing_advance($pdo, $recordId, $scope, $options),
        'purchase' => safe_delete_analyze_purchase($pdo, $recordId, $scope, $options),
        'purchase_payment' => safe_delete_analyze_purchase_payment($pdo, $recordId, $scope, $options),
        'job_card' => safe_delete_analyze_job_card($pdo, $recordId, $scope, $options),
        'return' => safe_delete_analyze_return($pdo, $recordId, $scope, $options),
        'return_settlement' => safe_delete_analyze_return_settlement($pdo, $recordId, $scope, $options),
        'expense' => safe_delete_analyze_expense($pdo, $recordId, $scope, $options),
        'expense_category' => safe_delete_analyze_expense_category($pdo, $recordId, $scope, $options),
        'outsourced_payment' => safe_delete_analyze_outsourced_payment($pdo, $recordId, $scope, $options),
        'customer' => safe_delete_analyze_customer($pdo, $recordId, $scope, $options),
        'vendor' => safe_delete_analyze_vendor($pdo, $recordId, $scope, $options),
        'vehicle' => safe_delete_analyze_vehicle($pdo, $recordId, $scope, $options),
        'payroll_salary_structure' => safe_delete_analyze_payroll_salary_structure($pdo, $recordId, $scope, $options),
        'payroll_advance' => safe_delete_analyze_payroll_advance($pdo, $recordId, $scope, $options),
        'payroll_loan' => safe_delete_analyze_payroll_loan($pdo, $recordId, $scope, $options),
        'payroll_loan_payment' => safe_delete_analyze_payroll_loan_payment($pdo, $recordId, $scope, $options),
        'payroll_salary_entry' => safe_delete_analyze_payroll_salary_entry($pdo, $recordId, $scope, $options),
        'payroll_salary_payment' => safe_delete_analyze_payroll_salary_payment($pdo, $recordId, $scope, $options),
        default => safe_delete_analyze_generic_entity($pdo, $entity, $recordId, $scope, $options),
    };
}

function safe_delete_analyze_key(PDO $pdo, string $entity, string $recordKey, array $scope = [], array $options = []): array
{
    $entity = safe_delete_normalize_entity($entity);
    $recordKey = trim($recordKey);

    if (!in_array($entity, safe_delete_supported_entities(), true)) {
        throw new RuntimeException('Safe delete preview is not configured for entity: ' . ($entity !== '' ? $entity : '(empty)'));
    }
    if ($recordKey === '') {
        throw new RuntimeException('Invalid record selected for safe delete preview.');
    }

    return match ($entity) {
        'inventory_unit' => safe_delete_analyze_inventory_unit($pdo, $recordKey, $scope, $options),
        default => throw new RuntimeException('Safe delete preview does not support key-based lookup for entity: ' . $entity),
    };
}

function safe_delete_analyze_inventory_unit(PDO $pdo, string $unitCode, array $scope, array $options = []): array
{
    $normalizedCode = function_exists('part_unit_normalize_code')
        ? part_unit_normalize_code($unitCode)
        : strtoupper(trim($unitCode));
    if ($normalizedCode === '') {
        throw new RuntimeException('Part unit code is required for preview.');
    }

    $companyId = (int) ($scope['company_id'] ?? 0);
    $summary = safe_delete_summary_base_key('inventory_unit', $normalizedCode, (string) ($options['operation'] ?? 'delete'));

    $catalogRows = function_exists('part_unit_catalog') ? part_unit_catalog($companyId) : [];
    $unitRow = null;
    foreach ($catalogRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $candidateCode = function_exists('part_unit_normalize_code')
            ? part_unit_normalize_code((string) ($row['code'] ?? ''))
            : strtoupper(trim((string) ($row['code'] ?? '')));
        if ($candidateCode === $normalizedCode) {
            $unitRow = $row;
            break;
        }
    }

    if (!is_array($unitRow)) {
        throw new RuntimeException('Part unit not found for this company scope.');
    }

    $statusCode = normalize_status_code((string) ($unitRow['status_code'] ?? 'ACTIVE'));
    $allowDecimal = (int) ($unitRow['allow_decimal'] ?? 0) === 1;

    $summary['main_record'] = [
        'label' => 'Part Unit ' . $normalizedCode,
        'reference' => $normalizedCode,
        'date' => null,
        'amount' => null,
        'status' => $statusCode,
        'note' => trim((string) ($unitRow['name'] ?? '')) . ' | Decimal Qty: ' . ($allowDecimal ? 'Yes' : 'No'),
    ];

    $detectedColumns = [];
    $partRefs = [];
    $partCount = 0;
    $seenPartIds = [];
    if (safe_delete_table_exists('parts')) {
        $partCols = table_columns('parts');
        foreach (['unit_code', 'uom_code', 'part_unit_code'] as $column) {
            if (!in_array($column, $partCols, true)) {
                continue;
            }
            $detectedColumns[] = $column;
            $sql = 'SELECT id, part_name, part_sku, status_code
                    FROM parts
                    WHERE ' . $column . ' = :unit_code';
            if (in_array('company_id', $partCols, true) && $companyId > 0) {
                $sql .= ' AND company_id = :company_id';
            }
            if (in_array('status_code', $partCols, true)) {
                $sql .= ' AND status_code <> "DELETED"';
            }
            $sql .= ' ORDER BY id DESC';
            $params = ['unit_code' => $normalizedCode];
            if (str_contains($sql, ':company_id')) {
                $params['company_id'] = $companyId;
            }
            $rows = safe_delete_fetch_rows($pdo, $sql, $params);
            foreach ($rows as $row) {
                $partId = (int) ($row['id'] ?? 0);
                if ($partId > 0 && isset($seenPartIds[$partId])) {
                    continue;
                }
                if ($partId > 0) {
                    $seenPartIds[$partId] = true;
                }
                $partCount++;
                if (count($partRefs) < SAFE_DELETE_GROUP_ITEM_LIMIT) {
                    $partRefs[] = safe_delete_make_item(
                        safe_delete_row_reference((array) $row, ['part_sku', 'part_name'], 'PART'),
                        null,
                        null,
                        (string) ($row['status_code'] ?? '')
                    );
                }
            }
        }
    }

    if ($partCount > 0) {
        $summary['groups'][] = safe_delete_make_group('parts', 'Parts', $partCount, $partRefs);
        $summary['warnings'][] = 'Unit is referenced by part master records. Historical transaction rows may also reference this code.';
    } else {
        $summary['groups'][] = safe_delete_make_group('parts', 'Parts', 0, []);
    }

    if ($detectedColumns === []) {
        $summary['warnings'][] = 'Part master unit reference column was not detected. Preview shows configured unit only.';
    }
    if ($statusCode === 'DELETED') {
        $summary['blockers'][] = 'Part unit is already deleted.';
    }

    $summary['can_proceed'] = $summary['blockers'] === [];
    $summary['recommended_action'] = $summary['can_proceed'] ? 'SOFT_DELETE' : 'BLOCK';
    $summary['execution_mode'] = $summary['can_proceed'] ? 'soft_delete' : 'block';

    return safe_delete_summary_finalize($summary);
}

function safe_delete_analyze_invoice(PDO $pdo, int $invoiceId, array $scope, array $options = []): array
{
    $summary = safe_delete_summary_base('invoice', $invoiceId, (string) ($options['operation'] ?? 'cancel'));
    $invoiceCols = table_columns('invoices');
    if ($invoiceCols === []) {
        throw new RuntimeException('Invoices table is not available for preview.');
    }

    $select = ['i.id', 'i.invoice_number', 'i.invoice_status', 'i.payment_status'];
    foreach (['invoice_date', 'created_at', 'updated_at', 'grand_total', 'job_card_id', 'customer_id', 'vehicle_id', 'cgst_amount', 'sgst_amount', 'igst_amount', 'gst_amount'] as $col) {
        if (in_array($col, $invoiceCols, true)) {
            $select[] = 'i.' . $col;
        }
    }

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM invoices i WHERE i.id = :invoice_id';
    $params = ['invoice_id' => $invoiceId];
    if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $invoiceCols, true)) {
        $sql .= ' AND i.company_id = :company_id';
        $params['company_id'] = (int) $scope['company_id'];
    }
    if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $invoiceCols, true)) {
        $sql .= ' AND i.garage_id = :garage_id';
        $params['garage_id'] = (int) $scope['garage_id'];
    }
    $sql .= ' LIMIT 1';

    $invoice = safe_delete_fetch_row($pdo, $sql, $params);
    if (!is_array($invoice)) {
        throw new RuntimeException('Invoice not found for this scope.');
    }

    $dep = reversal_invoice_cancel_dependency_report(
        $pdo,
        $invoiceId,
        (int) ($scope['company_id'] ?? 0),
        (int) ($scope['garage_id'] ?? 0)
    );

    $invoiceCustomerName = '';
    if ((int) ($invoice['customer_id'] ?? 0) > 0 && safe_delete_table_exists('customers')) {
        $customerRow = safe_delete_fetch_row($pdo, 'SELECT full_name, phone FROM customers WHERE id = :id LIMIT 1', [
            'id' => (int) $invoice['customer_id'],
        ]);
        if (is_array($customerRow)) {
            $invoiceCustomerName = trim((string) ($customerRow['full_name'] ?? ''));
            if ($invoiceCustomerName === '') {
                $invoiceCustomerName = trim((string) ($customerRow['phone'] ?? ''));
            }
        }
    }
    $invoiceVehicleLabel = '';
    if ((int) ($invoice['vehicle_id'] ?? 0) > 0 && safe_delete_table_exists('vehicles')) {
        $vehicleRow = safe_delete_fetch_row($pdo, 'SELECT registration_no, brand, model FROM vehicles WHERE id = :id LIMIT 1', [
            'id' => (int) $invoice['vehicle_id'],
        ]);
        if (is_array($vehicleRow)) {
            $invoiceVehicleLabel = trim((string) ($vehicleRow['registration_no'] ?? ''));
            if ($invoiceVehicleLabel === '') {
                $invoiceVehicleLabel = trim((string) (($vehicleRow['brand'] ?? '') . ' ' . ($vehicleRow['model'] ?? '')));
            }
        }
    }
    $invoiceNoteParts = [];
    if ($invoiceCustomerName !== '') {
        $invoiceNoteParts[] = 'Customer: ' . $invoiceCustomerName;
    }
    if ($invoiceVehicleLabel !== '') {
        $invoiceNoteParts[] = 'Vehicle: ' . $invoiceVehicleLabel;
    }
    $invoiceNoteParts[] = 'Payment status: ' . (string) ($invoice['payment_status'] ?? '');

    $summary['main_record'] = [
        'label' => 'Invoice ' . safe_delete_row_reference($invoice, ['invoice_number'], 'INV'),
        'reference' => safe_delete_row_reference($invoice, ['invoice_number'], 'INV'),
        'date' => safe_delete_pick_date($invoice, ['invoice_date', 'created_at', 'updated_at']),
        'amount' => safe_delete_pick_amount($invoice, ['grand_total']),
        'status' => (string) ($invoice['invoice_status'] ?? ''),
        'note' => implode(' | ', array_filter($invoiceNoteParts)),
    ];

    $paymentRows = [];
    $paymentImpact = 0.0;
    if (safe_delete_table_exists('payments')) {
        $payCols = table_columns('payments');
        $select = ['p.id', 'p.amount'];
        foreach (['paid_on', 'payment_date', 'reference_no', 'entry_type', 'is_reversed'] as $col) {
            if (in_array($col, $payCols, true)) {
                $select[] = 'p.' . $col;
            }
        }
        $rows = safe_delete_fetch_rows(
            $pdo,
            'SELECT ' . implode(', ', $select) . '
             FROM payments p
             WHERE p.invoice_id = :invoice_id
             ORDER BY p.id DESC',
            ['invoice_id' => $invoiceId]
        );
        foreach ($rows as $row) {
            $amount = round((float) ($row['amount'] ?? 0), 2);
            $entryType = strtoupper(trim((string) ($row['entry_type'] ?? ($amount < 0 ? 'REVERSAL' : 'PAYMENT'))));
            if ($entryType === 'PAYMENT' && $amount > 0) {
                $paymentImpact += $amount;
            }
            $canReverseRow = $entryType === 'PAYMENT'
                && $amount > 0
                && !((int) ($row['is_reversed'] ?? 0) === 1);
            $rowActions = $canReverseRow
                ? [safe_delete_make_dependency_action(
                    'Reverse',
                    'invoice_payment',
                    (int) ($row['id'] ?? 0),
                    'reverse',
                    null,
                    true,
                    'outline-danger',
                    true,
                    'Create reversal entry for this payment before deleting the invoice.'
                )]
                : [safe_delete_make_dependency_action_unavailable(
                    $entryType === 'REVERSAL' ? 'Already Reversal' : 'No Direct Action',
                    $entryType === 'REVERSAL' ? 'This row is already a reversal entry.' : ''
                )];
            $paymentRows[] = safe_delete_make_item(
                safe_delete_row_reference($row, ['reference_no'], 'PAY'),
                safe_delete_pick_date($row, ['paid_on', 'payment_date']),
                $amount,
                $entryType,
                null,
                $invoiceCustomerName !== '' ? ('Customer: ' . $invoiceCustomerName) : null,
                $rowActions
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group(
        'payments',
        'Payments',
        count($paymentRows),
        $paymentRows,
        $paymentImpact,
        ((int) (($dep['payment_summary']['unreversed_count'] ?? 0)) > 0) ? 'Unreversed payments must be reversed before cancellation.' : null
    );

    $adjRows = [];
    $adjImpact = 0.0;
    if (safe_delete_table_exists('advance_adjustments')) {
        $adjCols = table_columns('advance_adjustments');
        if (in_array('invoice_id', $adjCols, true)) {
            $select = ['aa.id'];
            foreach (['adjusted_amount', 'amount', 'created_at', 'applied_on', 'status_code'] as $col) {
                if (in_array($col, $adjCols, true)) {
                    $select[] = 'aa.' . $col;
                }
            }
            $sql = 'SELECT ' . implode(', ', $select) . ' FROM advance_adjustments aa WHERE aa.invoice_id = :invoice_id';
            if (in_array('status_code', $adjCols, true)) {
                $sql .= ' AND aa.status_code <> "DELETED"';
            }
            $sql .= ' ORDER BY aa.id DESC';
            foreach (safe_delete_fetch_rows($pdo, $sql, ['invoice_id' => $invoiceId]) as $row) {
                $amt = safe_delete_pick_amount($row, ['adjusted_amount', 'amount']) ?? 0.0;
                $adjImpact += $amt;
                $adjRows[] = safe_delete_make_item(
                    'Adjustment #' . (int) ($row['id'] ?? 0),
                    safe_delete_pick_date($row, ['applied_on', 'created_at']),
                    $amt,
                    (string) ($row['status_code'] ?? ''),
                    null,
                    $invoiceCustomerName !== '' ? ('Customer: ' . $invoiceCustomerName) : null,
                    [safe_delete_make_dependency_action_auto('Auto Release on Cancel', 'Advance adjustments are released automatically during invoice cancellation.')]
                );
            }
        }
    }
    $summary['groups'][] = safe_delete_make_group('advance_adjustments', 'Advance Adjustments', count($adjRows), $adjRows, $adjImpact);

    $stockRows = [];
    $jobId = (int) ($invoice['job_card_id'] ?? 0);
    if ($jobId > 0 && safe_delete_table_exists('inventory_movements')) {
        $movCols = table_columns('inventory_movements');
        $select = ['m.id'];
        foreach (['movement_uid', 'movement_type', 'created_at'] as $col) {
            if (in_array($col, $movCols, true)) {
                $select[] = 'm.' . $col;
            }
        }
        $sql = 'SELECT ' . implode(', ', $select) . '
                FROM inventory_movements m
                WHERE m.reference_type = "JOB_CARD"
                  AND m.reference_id = :job_id';
        $params = ['job_id' => $jobId];
        if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $movCols, true)) {
            $sql .= ' AND m.company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $movCols, true)) {
            $sql .= ' AND m.garage_id = :garage_id';
            $params['garage_id'] = (int) $scope['garage_id'];
        }
        $sql .= ' ORDER BY m.id DESC';
        foreach (safe_delete_fetch_rows($pdo, $sql, $params) as $row) {
            $stockRows[] = safe_delete_make_item(
                safe_delete_row_reference($row, ['movement_uid'], 'MOV'),
                safe_delete_pick_date($row, ['created_at']),
                null,
                (string) ($row['movement_type'] ?? ''),
                'Posted stock movement linked to job card.',
                $invoiceVehicleLabel !== '' ? ('Vehicle: ' . $invoiceVehicleLabel) : null,
                [safe_delete_make_dependency_action_unavailable('Manage in Job/Stock', 'Stock postings are managed in the job/stock workflow, not directly from invoice summary.')]
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group(
        'stock_movements',
        'Stock Movements',
        count($stockRows),
        $stockRows,
        0.0,
        ((int) ($dep['inventory_movements'] ?? 0) > 0) ? 'Linked job stock movements are already posted.' : null
    );

    $gstImpact = 0.0;
    foreach (['cgst_amount', 'sgst_amount', 'igst_amount', 'gst_amount'] as $col) {
        if (isset($invoice[$col])) {
            $gstImpact += (float) ($invoice[$col] ?? 0);
        }
    }
    if ($gstImpact > 0.009) {
        $summary['groups'][] = safe_delete_make_group(
            'gst_impact',
            'GST Impact',
            1,
            [safe_delete_make_item(
                safe_delete_row_reference($invoice, ['invoice_number'], 'INV'),
                safe_delete_pick_date($invoice, ['invoice_date']),
                $gstImpact,
                'TAX',
                'GST impact will be marked reversed with invoice cancellation workflow.',
                $invoiceCustomerName !== '' ? ('Customer: ' . $invoiceCustomerName) : null,
                [safe_delete_make_dependency_action_auto('Auto GST Reversal', 'GST impact is handled by the invoice cancellation workflow.')]
            )],
            $gstImpact
        );
    }

    if (safe_delete_table_exists('credit_notes') && safe_delete_table_has_column('credit_notes', 'invoice_id')) {
        $cnCols = table_columns('credit_notes');
        $select = ['c.id'];
        foreach (['credit_note_number', 'note_number', 'credit_note_date', 'created_at', 'amount', 'total_amount', 'status_code'] as $col) {
            if (in_array($col, $cnCols, true)) {
                $select[] = 'c.' . $col;
            }
        }
        $sql = 'SELECT ' . implode(', ', $select) . ' FROM credit_notes c WHERE c.invoice_id = :invoice_id';
        $params = ['invoice_id' => $invoiceId];
        if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $cnCols, true)) {
            $sql .= ' AND c.company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $cnCols, true)) {
            $sql .= ' AND c.garage_id = :garage_id';
            $params['garage_id'] = (int) $scope['garage_id'];
        }
        if (in_array('status_code', $cnCols, true)) {
            $sql .= ' AND c.status_code <> "DELETED"';
        }
        $sql .= ' ORDER BY c.id DESC';

        $rows = [];
        $impact = 0.0;
        foreach (safe_delete_fetch_rows($pdo, $sql, $params) as $row) {
            $amt = safe_delete_pick_amount($row, ['amount', 'total_amount']) ?? 0.0;
            $impact += $amt;
            $rows[] = safe_delete_make_item(
                safe_delete_row_reference($row, ['credit_note_number', 'note_number'], 'CN'),
                safe_delete_pick_date($row, ['credit_note_date', 'created_at']),
                $amt,
                (string) ($row['status_code'] ?? ''),
                'Reverse this credit note before cancelling invoice if it blocks cancellation.',
                $invoiceCustomerName !== '' ? ('Customer: ' . $invoiceCustomerName) : null,
                [safe_delete_make_dependency_action_unavailable('Reverse in Credit Notes', 'Credit note reversal is not yet wired in the summary modal.')]
            );
        }
        $summary['groups'][] = safe_delete_make_group('credit_notes', 'Credit Notes', count($rows), $rows, $impact);
    }

    $summary['blockers'] = array_values((array) ($dep['blockers'] ?? []));
    $summary['warnings'] = array_values((array) ($dep['steps'] ?? []));
    $summary['can_proceed'] = (bool) ($dep['can_cancel'] ?? false);
    $summary['recommended_action'] = $summary['can_proceed'] ? 'FINANCIAL_REVERSAL_AND_CANCEL' : 'BLOCK';
    $summary['execution_mode'] = $summary['can_proceed'] ? 'cancel_with_reversals' : 'block';

    return safe_delete_summary_finalize($summary);
}

function safe_delete_analyze_invoice_payment(PDO $pdo, int $paymentId, array $scope, array $options = []): array
{
    $summary = safe_delete_summary_base('invoice_payment', $paymentId, (string) ($options['operation'] ?? 'reverse'));
    if (table_columns('payments') === [] || table_columns('invoices') === []) {
        throw new RuntimeException('Payment preview tables are not available.');
    }

    $paymentCols = table_columns('payments');
    $select = ['p.id', 'p.invoice_id', 'p.amount', 'i.invoice_number'];
    foreach (['paid_on', 'reference_no', 'entry_type', 'is_reversed'] as $col) {
        if (in_array($col, $paymentCols, true)) {
            $select[] = 'p.' . $col;
        }
    }
    foreach (['invoice_status', 'payment_status', 'grand_total'] as $col) {
        if (safe_delete_table_has_column('invoices', $col)) {
            $select[] = 'i.' . $col;
        }
    }

    $sql = 'SELECT ' . implode(', ', $select) . '
            FROM payments p
            INNER JOIN invoices i ON i.id = p.invoice_id
            WHERE p.id = :payment_id';
    $params = ['payment_id' => $paymentId];
    if (($scope['company_id'] ?? 0) > 0 && safe_delete_table_has_column('invoices', 'company_id')) {
        $sql .= ' AND i.company_id = :company_id';
        $params['company_id'] = (int) $scope['company_id'];
    }
    if (($scope['garage_id'] ?? 0) > 0 && safe_delete_table_has_column('invoices', 'garage_id')) {
        $sql .= ' AND i.garage_id = :garage_id';
        $params['garage_id'] = (int) $scope['garage_id'];
    }
    $sql .= ' LIMIT 1';
    $row = safe_delete_fetch_row($pdo, $sql, $params);
    if (!is_array($row)) {
        throw new RuntimeException('Payment entry not found for this scope.');
    }

    $amount = round((float) ($row['amount'] ?? 0), 2);
    $entryType = strtoupper(trim((string) ($row['entry_type'] ?? ($amount < 0 ? 'REVERSAL' : 'PAYMENT'))));
    $alreadyReversed = (isset($row['is_reversed']) && (int) ($row['is_reversed'] ?? 0) === 1);
    if (!$alreadyReversed && safe_delete_table_has_column('payments', 'reversed_payment_id')) {
        $alreadyReversed = safe_delete_fetch_scalar($pdo, 'SELECT id FROM payments WHERE reversed_payment_id = :payment_id LIMIT 1', ['payment_id' => $paymentId]) !== false;
    }

    $summary['main_record'] = [
        'label' => 'Payment ' . safe_delete_row_reference($row, ['reference_no'], 'PAY'),
        'reference' => safe_delete_row_reference($row, ['reference_no'], 'PAY'),
        'date' => safe_delete_pick_date($row, ['paid_on']),
        'amount' => $amount,
        'status' => $entryType,
        'note' => 'Invoice ' . (string) ($row['invoice_number'] ?? ('#' . (int) ($row['invoice_id'] ?? 0))),
    ];
    $summary['groups'][] = safe_delete_make_group('invoice', 'Linked Invoice', 1, [
        safe_delete_make_item((string) ($row['invoice_number'] ?? ('INV#' . (int) ($row['invoice_id'] ?? 0))), null, safe_delete_pick_amount($row, ['grand_total']), (string) ($row['invoice_status'] ?? ''))
    ], 0.0);
    $summary['groups'][] = safe_delete_make_group('payment_reversal', 'Reversal Entry To Be Created', 1, [
        safe_delete_make_item('REV-' . $paymentId, date('Y-m-d'), -abs($amount), 'REVERSAL')
    ], abs($amount));

    if ($entryType !== 'PAYMENT') {
        $summary['blockers'][] = 'Only original payment entries can be reversed.';
    }
    if ($alreadyReversed) {
        $summary['blockers'][] = 'This payment is already reversed.';
    }
    if ($amount <= 0.009) {
        $summary['blockers'][] = 'Invalid payment amount for reversal.';
    }

    $summary['can_proceed'] = $summary['blockers'] === [];
    $summary['recommended_action'] = $summary['can_proceed'] ? 'FINANCIAL_REVERSAL_ENTRY' : 'BLOCK';
    $summary['execution_mode'] = $summary['can_proceed'] ? 'reversal_entry' : 'block';

    return safe_delete_summary_finalize($summary);
}

function safe_delete_analyze_billing_advance(PDO $pdo, int $advanceId, array $scope, array $options = []): array
{
    $summary = safe_delete_summary_base('billing_advance', $advanceId, (string) ($options['operation'] ?? 'delete'));
    $advCols = table_columns('job_advances');
    if ($advCols === []) {
        throw new RuntimeException('Advance receipt table is not available.');
    }

    $select = ['ja.id', 'ja.job_card_id', 'jc.job_number'];
    foreach (['receipt_number', 'advance_amount', 'adjusted_amount', 'balance_amount', 'received_on', 'status_code'] as $col) {
        if (in_array($col, $advCols, true)) {
            $select[] = 'ja.' . $col;
        }
    }
    $sql = 'SELECT ' . implode(', ', $select) . '
            FROM job_advances ja
            LEFT JOIN job_cards jc ON jc.id = ja.job_card_id
            WHERE ja.id = :advance_id';
    $params = ['advance_id' => $advanceId];
    if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $advCols, true)) {
        $sql .= ' AND ja.company_id = :company_id';
        $params['company_id'] = (int) $scope['company_id'];
    }
    if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $advCols, true)) {
        $sql .= ' AND ja.garage_id = :garage_id';
        $params['garage_id'] = (int) $scope['garage_id'];
    }
    $sql .= ' LIMIT 1';
    $advance = safe_delete_fetch_row($pdo, $sql, $params);
    if (!is_array($advance)) {
        throw new RuntimeException('Advance receipt not found for this scope.');
    }

    $adjustedAmount = round((float) ($advance['adjusted_amount'] ?? 0), 2);
    $balanceAmount = round((float) ($advance['balance_amount'] ?? 0), 2);
    $statusCode = strtoupper(trim((string) ($advance['status_code'] ?? 'ACTIVE')));

    $summary['main_record'] = [
        'label' => 'Advance ' . safe_delete_row_reference($advance, ['receipt_number'], 'ADV'),
        'reference' => safe_delete_row_reference($advance, ['receipt_number'], 'ADV'),
        'date' => safe_delete_pick_date($advance, ['received_on']),
        'amount' => round((float) ($advance['advance_amount'] ?? 0), 2),
        'status' => $statusCode,
        'note' => 'Job ' . (string) ($advance['job_number'] ?? ('#' . (int) ($advance['job_card_id'] ?? 0))),
    ];

    $adjustmentRows = [];
    $adjustmentCount = 0;
    if (safe_delete_table_exists('advance_adjustments') && safe_delete_table_has_column('advance_adjustments', 'advance_id')) {
        $adjCols = table_columns('advance_adjustments');
        $select = ['id'];
        foreach (['adjusted_amount', 'amount', 'created_at', 'status_code'] as $col) {
            if (in_array($col, $adjCols, true)) {
                $select[] = $col;
            }
        }
        $sql = 'SELECT ' . implode(', ', $select) . ' FROM advance_adjustments WHERE advance_id = :advance_id';
        if (in_array('status_code', $adjCols, true)) {
            $sql .= ' AND status_code <> "DELETED"';
        }
        $sql .= ' ORDER BY id DESC';
        foreach (safe_delete_fetch_rows($pdo, $sql, ['advance_id' => $advanceId]) as $row) {
            $adjustmentCount++;
            $adjustmentRows[] = safe_delete_make_item(
                'Adjustment #' . (int) ($row['id'] ?? 0),
                safe_delete_pick_date($row, ['created_at']),
                safe_delete_pick_amount($row, ['adjusted_amount', 'amount']),
                (string) ($row['status_code'] ?? '')
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group(
        'advance_adjustments',
        'Advance Adjustments',
        $adjustmentCount,
        $adjustmentRows,
        $adjustedAmount,
        $adjustmentCount > 0 ? 'Advance has adjustment history.' : null
    );
    $summary['groups'][] = safe_delete_make_group('balance', 'Unadjusted Balance', 1, [
        safe_delete_make_item(safe_delete_row_reference($advance, ['receipt_number'], 'ADV'), safe_delete_pick_date($advance, ['received_on']), $balanceAmount, 'BALANCE')
    ], $balanceAmount);

    if ($statusCode !== 'ACTIVE') {
        $summary['blockers'][] = 'Advance receipt is already reversed/deleted.';
    }
    if ($adjustedAmount > 0.009) {
        $summary['blockers'][] = 'Adjusted advance cannot be deleted. Reverse invoice-side advance adjustment first.';
    }
    if ($adjustmentCount > 0) {
        $summary['blockers'][] = 'Advance has adjustment history and cannot be deleted.';
    }

    $summary['can_proceed'] = $summary['blockers'] === [];
    $summary['recommended_action'] = $summary['can_proceed'] ? 'SOFT_DELETE' : 'BLOCK';
    $summary['execution_mode'] = $summary['can_proceed'] ? 'soft_delete' : 'block';

    return safe_delete_summary_finalize($summary);
}

function safe_delete_analyze_purchase(PDO $pdo, int $purchaseId, array $scope, array $options = []): array
{
    $summary = safe_delete_summary_base('purchase', $purchaseId, (string) ($options['operation'] ?? 'delete'));
    $purchaseCols = table_columns('purchases');
    if ($purchaseCols === []) {
        throw new RuntimeException('Purchases table is not available.');
    }

    $select = ['p.id', 'p.invoice_number'];
    foreach (['purchase_date', 'grand_total', 'payment_status', 'purchase_status', 'status_code', 'vendor_id'] as $col) {
        if (in_array($col, $purchaseCols, true)) {
            $select[] = 'p.' . $col;
        }
    }
    $sql = 'SELECT ' . implode(', ', $select) . ' FROM purchases p WHERE p.id = :purchase_id';
    $params = ['purchase_id' => $purchaseId];
    if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $purchaseCols, true)) {
        $sql .= ' AND p.company_id = :company_id';
        $params['company_id'] = (int) $scope['company_id'];
    }
    if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $purchaseCols, true)) {
        $sql .= ' AND p.garage_id = :garage_id';
        $params['garage_id'] = (int) $scope['garage_id'];
    }
    $sql .= ' LIMIT 1';
    $purchase = safe_delete_fetch_row($pdo, $sql, $params);
    if (!is_array($purchase)) {
        throw new RuntimeException('Purchase not found for this scope.');
    }

    $dep = reversal_purchase_delete_dependency_report(
        $pdo,
        $purchaseId,
        (int) ($scope['company_id'] ?? 0),
        (int) ($scope['garage_id'] ?? 0)
    );

    $purchaseVendorName = '';
    $purchaseVendorCode = '';
    if ((int) ($purchase['vendor_id'] ?? 0) > 0 && safe_delete_table_exists('vendors')) {
        $vendorRow = safe_delete_fetch_row($pdo, 'SELECT vendor_code, vendor_name FROM vendors WHERE id = :id LIMIT 1', [
            'id' => (int) $purchase['vendor_id'],
        ]);
        if (is_array($vendorRow)) {
            $purchaseVendorName = trim((string) ($vendorRow['vendor_name'] ?? ''));
            $purchaseVendorCode = trim((string) ($vendorRow['vendor_code'] ?? ''));
        }
    }
    $purchaseNoteParts = [];
    if ($purchaseVendorName !== '') {
        $purchaseNoteParts[] = 'Vendor: ' . $purchaseVendorName;
    }
    if ($purchaseVendorCode !== '') {
        $purchaseNoteParts[] = 'Code: ' . $purchaseVendorCode;
    }
    $purchaseNoteParts[] = 'Payment status: ' . (string) ($purchase['payment_status'] ?? '');

    $summary['main_record'] = [
        'label' => 'Purchase ' . safe_delete_row_reference($purchase, ['invoice_number'], 'PUR'),
        'reference' => safe_delete_row_reference($purchase, ['invoice_number'], 'PUR'),
        'date' => safe_delete_pick_date($purchase, ['purchase_date']),
        'amount' => safe_delete_pick_amount($purchase, ['grand_total']),
        'status' => (string) ($purchase['purchase_status'] ?? ''),
        'note' => implode(' | ', array_filter($purchaseNoteParts)),
    ];

    $paymentRows = [];
    $paymentImpact = 0.0;
    if (safe_delete_table_exists('purchase_payments')) {
        $ppCols = table_columns('purchase_payments');
        $select = ['pp.id', 'pp.amount'];
        foreach (['payment_date', 'entry_type', 'reference_no', 'payment_mode', 'reversed_payment_id'] as $col) {
            if (in_array($col, $ppCols, true)) {
                $select[] = 'pp.' . $col;
            }
        }
        foreach (safe_delete_fetch_rows(
            $pdo,
            'SELECT ' . implode(', ', $select) . ' FROM purchase_payments pp WHERE pp.purchase_id = :purchase_id ORDER BY pp.id DESC',
            ['purchase_id' => $purchaseId]
        ) as $row) {
            $amount = round((float) ($row['amount'] ?? 0), 2);
            $entryType = strtoupper(trim((string) ($row['entry_type'] ?? ($amount < 0 ? 'REVERSAL' : 'PAYMENT'))));
            if ($entryType === 'PAYMENT' && $amount > 0) {
                $paymentImpact += $amount;
            }
            $canReverseRow = $entryType === 'PAYMENT' && $amount > 0;
            $rowActions = $canReverseRow
                ? [safe_delete_make_dependency_action(
                    'Reverse',
                    'purchase_payment',
                    (int) ($row['id'] ?? 0),
                    'reverse',
                    null,
                    true,
                    'outline-danger',
                    true,
                    'Create reversal entry for this purchase payment before deleting the purchase.'
                )]
                : [safe_delete_make_dependency_action_unavailable(
                    $entryType === 'REVERSAL' ? 'Already Reversal' : 'No Direct Action',
                    $entryType === 'REVERSAL' ? 'This row is already a reversal entry.' : ''
                )];
            $paymentRows[] = safe_delete_make_item(
                safe_delete_row_reference($row, ['reference_no'], 'PPAY'),
                safe_delete_pick_date($row, ['payment_date']),
                $amount,
                $entryType,
                trim((string) ($row['payment_mode'] ?? '')) !== '' ? ('Mode: ' . (string) $row['payment_mode']) : null,
                $purchaseVendorName !== '' ? ('Vendor: ' . $purchaseVendorName) : null,
                $rowActions
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group(
        'payments',
        'Payments',
        count($paymentRows),
        $paymentRows,
        $paymentImpact,
        ((int) (($dep['payment_summary']['unreversed_count'] ?? 0)) > 0) ? 'Unreversed purchase payments must be reversed before delete.' : null
    );

    $itemRows = [];
    if (safe_delete_table_exists('purchase_items')) {
        $piCols = table_columns('purchase_items');
        $select = ['pi.id', 'pi.part_id'];
        foreach (['quantity', 'total_amount'] as $col) {
            if (in_array($col, $piCols, true)) {
                $select[] = 'pi.' . $col;
            }
        }
        $sql = 'SELECT ' . implode(', ', $select) . ', p.part_name
                FROM purchase_items pi
                LEFT JOIN parts p ON p.id = pi.part_id
                WHERE pi.purchase_id = :purchase_id
                ORDER BY pi.id ASC';
        foreach (safe_delete_fetch_rows($pdo, $sql, ['purchase_id' => $purchaseId]) as $row) {
            $itemRows[] = safe_delete_make_item(
                trim((string) ($row['part_name'] ?? '')) !== '' ? (string) $row['part_name'] : ('Part #' . (int) ($row['part_id'] ?? 0)),
                null,
                safe_delete_pick_amount($row, ['total_amount']),
                (($row['quantity'] ?? null) !== null ? ('QTY ' . number_format((float) ($row['quantity'] ?? 0), 2)) : null),
                'Line item is part of the purchase and handled with parent delete.',
                $purchaseVendorName !== '' ? ('Vendor: ' . $purchaseVendorName) : null,
                [safe_delete_make_dependency_action_auto('Auto with Purchase Delete', 'Purchase line items are handled by the purchase delete workflow.')]
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group('purchase_items', 'Purchase Items', count($itemRows), $itemRows, 0.0);

    $stockRows = [];
    if (safe_delete_table_exists('inventory_movements')) {
        $movCols = table_columns('inventory_movements');
        $select = ['m.id'];
        foreach (['movement_uid', 'movement_type', 'created_at'] as $col) {
            if (in_array($col, $movCols, true)) {
                $select[] = 'm.' . $col;
            }
        }
        $sql = 'SELECT ' . implode(', ', $select) . '
                FROM inventory_movements m
                WHERE m.reference_type = "PURCHASE"
                  AND m.reference_id = :purchase_id';
        $params = ['purchase_id' => $purchaseId];
        if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $movCols, true)) {
            $sql .= ' AND m.company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $movCols, true)) {
            $sql .= ' AND m.garage_id = :garage_id';
            $params['garage_id'] = (int) $scope['garage_id'];
        }
        $sql .= ' ORDER BY m.id DESC';
        foreach (safe_delete_fetch_rows($pdo, $sql, $params) as $row) {
            $stockRows[] = safe_delete_make_item(
                safe_delete_row_reference($row, ['movement_uid'], 'MOV'),
                safe_delete_pick_date($row, ['created_at']),
                null,
                (string) ($row['movement_type'] ?? ''),
                'Stock movement will be reversed when purchase delete executes.',
                $purchaseVendorName !== '' ? ('Vendor: ' . $purchaseVendorName) : null,
                [safe_delete_make_dependency_action_auto('Auto Stock Reversal', 'Purchase delete creates stock reversal movements transactionally.')]
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group('stock_movements', 'Stock Movements', count($stockRows), $stockRows, 0.0, 'Final stock availability validation runs in transaction at delete time.');

    if ((int) ($purchase['vendor_id'] ?? 0) > 0) {
        $vendor = safe_delete_fetch_row($pdo, 'SELECT id, vendor_code, vendor_name FROM vendors WHERE id = :id LIMIT 1', ['id' => (int) $purchase['vendor_id']]);
        if (is_array($vendor)) {
            $summary['groups'][] = safe_delete_make_group('vendor_payable', 'Vendor Payable Impact', 1, [
                safe_delete_make_item(
                    trim((string) ($vendor['vendor_code'] ?? '')) !== '' ? (string) $vendor['vendor_code'] : ('Vendor #' . (int) ($vendor['id'] ?? 0)),
                    null,
                    safe_delete_pick_amount($purchase, ['grand_total']),
                    null,
                    (string) ($vendor['vendor_name'] ?? ''),
                    null,
                    [safe_delete_make_dependency_action_auto('Auto Ledger/Payable Reversal', 'Vendor payable impact is handled by the purchase delete workflow.')]
                )
            ], safe_delete_pick_amount($purchase, ['grand_total']) ?? 0.0);
        }
    }

    $summary['blockers'] = array_values((array) ($dep['blockers'] ?? []));
    $summary['warnings'] = array_values((array) ($dep['steps'] ?? []));
    $summary['can_proceed'] = (bool) ($dep['can_delete'] ?? false);
    $summary['recommended_action'] = $summary['can_proceed'] ? 'STOCK_REVERSAL_AND_SOFT_DELETE' : 'BLOCK';
    $summary['execution_mode'] = $summary['can_proceed'] ? 'stock_reversal_then_soft_delete' : 'block';

    return safe_delete_summary_finalize($summary);
}

function safe_delete_analyze_purchase_payment(PDO $pdo, int $paymentId, array $scope, array $options = []): array
{
    $summary = safe_delete_summary_base('purchase_payment', $paymentId, (string) ($options['operation'] ?? 'reverse'));
    if (table_columns('purchase_payments') === [] || table_columns('purchases') === []) {
        throw new RuntimeException('Purchase payment preview tables are not available.');
    }

    $ppCols = table_columns('purchase_payments');
    $select = ['pp.id', 'pp.purchase_id', 'pp.amount', 'p.invoice_number'];
    foreach (['payment_date', 'entry_type', 'reference_no'] as $col) {
        if (in_array($col, $ppCols, true)) {
            $select[] = 'pp.' . $col;
        }
    }
    foreach (['grand_total', 'payment_status'] as $col) {
        if (safe_delete_table_has_column('purchases', $col)) {
            $select[] = 'p.' . $col;
        }
    }

    $sql = 'SELECT ' . implode(', ', $select) . '
            FROM purchase_payments pp
            INNER JOIN purchases p ON p.id = pp.purchase_id
            WHERE pp.id = :payment_id';
    $params = ['payment_id' => $paymentId];
    if (($scope['company_id'] ?? 0) > 0 && safe_delete_table_has_column('purchases', 'company_id')) {
        $sql .= ' AND p.company_id = :company_id';
        $params['company_id'] = (int) $scope['company_id'];
    }
    if (($scope['garage_id'] ?? 0) > 0 && safe_delete_table_has_column('purchases', 'garage_id')) {
        $sql .= ' AND p.garage_id = :garage_id';
        $params['garage_id'] = (int) $scope['garage_id'];
    }
    $sql .= ' LIMIT 1';
    $row = safe_delete_fetch_row($pdo, $sql, $params);
    if (!is_array($row)) {
        throw new RuntimeException('Purchase payment not found for this scope.');
    }

    $amount = round((float) ($row['amount'] ?? 0), 2);
    $entryType = strtoupper(trim((string) ($row['entry_type'] ?? 'PAYMENT')));
    $alreadyReversed = false;
    if (safe_delete_table_has_column('purchase_payments', 'reversed_payment_id')) {
        $alreadyReversed = safe_delete_fetch_scalar($pdo, 'SELECT id FROM purchase_payments WHERE reversed_payment_id = :payment_id LIMIT 1', ['payment_id' => $paymentId]) !== false;
    }

    $summary['main_record'] = [
        'label' => 'Purchase Payment ' . safe_delete_row_reference($row, ['reference_no'], 'PPAY'),
        'reference' => safe_delete_row_reference($row, ['reference_no'], 'PPAY'),
        'date' => safe_delete_pick_date($row, ['payment_date']),
        'amount' => $amount,
        'status' => $entryType,
        'note' => 'Purchase ' . (string) ($row['invoice_number'] ?? ('#' . (int) ($row['purchase_id'] ?? 0))),
    ];
    $summary['groups'][] = safe_delete_make_group('purchase', 'Linked Purchase', 1, [
        safe_delete_make_item((string) ($row['invoice_number'] ?? ('PUR#' . (int) ($row['purchase_id'] ?? 0))), null, safe_delete_pick_amount($row, ['grand_total']), (string) ($row['payment_status'] ?? ''))
    ], 0.0);
    $summary['groups'][] = safe_delete_make_group('payment_reversal', 'Reversal Entry To Be Created', 1, [
        safe_delete_make_item('REV-' . $paymentId, date('Y-m-d'), -abs($amount), 'REVERSAL')
    ], abs($amount));

    if ($entryType !== 'PAYMENT') {
        $summary['blockers'][] = 'Only payment entries can be reversed.';
    }
    if ($amount <= 0.009) {
        $summary['blockers'][] = 'Invalid payment amount for reversal.';
    }
    if ($alreadyReversed) {
        $summary['blockers'][] = 'This purchase payment is already reversed.';
    }

    $summary['can_proceed'] = $summary['blockers'] === [];
    $summary['recommended_action'] = $summary['can_proceed'] ? 'FINANCIAL_REVERSAL_ENTRY' : 'BLOCK';
    $summary['execution_mode'] = $summary['can_proceed'] ? 'reversal_entry' : 'block';

    return safe_delete_summary_finalize($summary);
}

function safe_delete_analyze_job_card(PDO $pdo, int $jobId, array $scope, array $options = []): array
{
    $summary = safe_delete_summary_base('job_card', $jobId, (string) ($options['operation'] ?? 'delete'));
    $jobCols = table_columns('job_cards');
    if ($jobCols === []) {
        throw new RuntimeException('Job cards table is not available.');
    }

    $select = ['j.id', 'j.job_number'];
    foreach (['status', 'status_code', 'created_at', 'closed_at'] as $col) {
        if (in_array($col, $jobCols, true)) {
            $select[] = 'j.' . $col;
        }
    }
    $sql = 'SELECT ' . implode(', ', $select) . ' FROM job_cards j WHERE j.id = :job_id';
    $params = ['job_id' => $jobId];
    if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $jobCols, true)) {
        $sql .= ' AND j.company_id = :company_id';
        $params['company_id'] = (int) $scope['company_id'];
    }
    if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $jobCols, true)) {
        $sql .= ' AND j.garage_id = :garage_id';
        $params['garage_id'] = (int) $scope['garage_id'];
    }
    $sql .= ' LIMIT 1';
    $job = safe_delete_fetch_row($pdo, $sql, $params);
    if (!is_array($job)) {
        throw new RuntimeException('Job card not found for this scope.');
    }

    $dep = reversal_job_delete_dependency_report(
        $pdo,
        $jobId,
        (int) ($scope['company_id'] ?? 0),
        (int) ($scope['garage_id'] ?? 0)
    );

    $summary['main_record'] = [
        'label' => 'Job Card ' . safe_delete_row_reference($job, ['job_number'], 'JOB'),
        'reference' => safe_delete_row_reference($job, ['job_number'], 'JOB'),
        'date' => safe_delete_pick_date($job, ['closed_at', 'created_at']),
        'amount' => null,
        'status' => (string) ($job['status'] ?? ''),
        'note' => 'Status Code: ' . (string) ($job['status_code'] ?? ''),
    ];
    $jobStatusUpper = strtoupper(trim((string) ($job['status'] ?? '')));
    $jobStatusCodeUpper = strtoupper(trim((string) ($job['status_code'] ?? 'ACTIVE')));
    $jobLockedForLineDelete = ($jobStatusUpper === 'CLOSED' || $jobStatusCodeUpper !== 'ACTIVE');

    $advanceRows = [];
    $advanceImpact = 0.0;
    if (safe_delete_table_exists('job_advances')) {
        $jaCols = table_columns('job_advances');
        $select = ['ja.id', 'ja.job_card_id'];
        foreach (['receipt_number', 'advance_amount', 'adjusted_amount', 'balance_amount', 'received_on', 'status_code'] as $col) {
            if (in_array($col, $jaCols, true)) {
                $select[] = 'ja.' . $col;
            }
        }
        $sql = 'SELECT ' . implode(', ', $select) . ' FROM job_advances ja WHERE ja.job_card_id = :job_id';
        $params = ['job_id' => $jobId];
        if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $jaCols, true)) {
            $sql .= ' AND ja.company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $jaCols, true)) {
            $sql .= ' AND ja.garage_id = :garage_id';
            $params['garage_id'] = (int) $scope['garage_id'];
        }
        if (in_array('status_code', $jaCols, true)) {
            $sql .= ' AND ja.status_code <> "DELETED"';
        }
        $sql .= ' ORDER BY ja.id DESC';
        foreach (safe_delete_fetch_rows($pdo, $sql, $params) as $row) {
            $amt = round((float) ($row['advance_amount'] ?? 0), 2);
            $advanceImpact += $amt;
            $adjustedAmount = round((float) ($row['adjusted_amount'] ?? 0), 2);
            $canDeleteAdvanceRow = strtoupper(trim((string) ($row['status_code'] ?? 'ACTIVE'))) === 'ACTIVE';
            $advanceActions = $canDeleteAdvanceRow
                ? [safe_delete_make_dependency_action(
                    'Delete',
                    'billing_advance',
                    (int) ($row['id'] ?? 0),
                    'delete',
                    null,
                    true,
                    'outline-danger',
                    true,
                    'Delete the advance receipt (unadjusted only) before deleting the job card.'
                )]
                : [safe_delete_make_dependency_action_unavailable('Already Deleted', 'Advance receipt is not active.')];
            $advanceRows[] = safe_delete_make_item(
                safe_delete_row_reference($row, ['receipt_number'], 'ADV'),
                safe_delete_pick_date($row, ['received_on']),
                $amt,
                (string) ($row['status_code'] ?? ''),
                'Adjusted: ' . number_format($adjustedAmount, 2)
                    . ' | Balance: ' . number_format((float) ($row['balance_amount'] ?? 0), 2),
                safe_delete_row_reference($job, ['job_number'], 'JOB'),
                $advanceActions
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group(
        'job_advances',
        'Advance Receipts',
        count($advanceRows),
        $advanceRows,
        $advanceImpact,
        ((int) ($dep['advance_receipt_count'] ?? 0) > 0)
            ? ('Advance receipts must be cleared before job delete. Active receipts: ' . (int) ($dep['advance_receipt_count'] ?? 0))
            : null
    );

    $laborRows = [];
    $laborImpact = 0.0;
    if (safe_delete_table_exists('job_labor')) {
        $jlCols = table_columns('job_labor');
        $select = ['jl.id'];
        foreach (['description', 'total_amount', 'quantity', 'execution_type', 'outsource_payable_status', 'outsource_cost'] as $col) {
            if (in_array($col, $jlCols, true)) {
                $select[] = 'jl.' . $col;
            }
        }
        $sql = 'SELECT ' . implode(', ', $select) . ' FROM job_labor jl WHERE jl.job_card_id = :job_id ORDER BY jl.id ASC';
        foreach (safe_delete_fetch_rows($pdo, $sql, ['job_id' => $jobId]) as $row) {
            $amt = safe_delete_pick_amount($row, ['total_amount']) ?? 0.0;
            $laborImpact += $amt;
            $lineActions = [];
            if ($jobLockedForLineDelete) {
                $lineActions[] = safe_delete_make_dependency_action_unavailable(
                    'Reopen Job First',
                    $jobStatusUpper === 'CLOSED'
                        ? 'Reopen the job card to OPEN before deleting labor lines.'
                        : 'Job card must be ACTIVE and open for line deletion.'
                );
            } else {
                $lineActions[] = safe_delete_make_dependency_action(
                    'Delete',
                    'job_labor_line',
                    (int) ($row['id'] ?? 0),
                    'delete',
                    null,
                    true,
                    'outline-danger',
                    true,
                    'Delete labor line before soft deleting the job card.'
                );
            }
            $laborNoteParts = [];
            if (trim((string) ($row['execution_type'] ?? '')) !== '') {
                $laborNoteParts[] = 'Type: ' . (string) $row['execution_type'];
            }
            if (strtoupper(trim((string) ($row['execution_type'] ?? ''))) === 'OUTSOURCED') {
                $laborNoteParts[] = 'Payable: ' . (string) ($row['outsource_payable_status'] ?? 'UNPAID');
                if (array_key_exists('outsource_cost', $row)) {
                    $laborNoteParts[] = 'Outsource Cost: ' . number_format((float) ($row['outsource_cost'] ?? 0), 2);
                }
            }
            $laborRows[] = safe_delete_make_item(
                trim((string) ($row['description'] ?? '')) !== '' ? (string) $row['description'] : ('Labor #' . (int) ($row['id'] ?? 0)),
                null,
                $amt,
                (string) (($row['execution_type'] ?? 'LINE') ?: 'LINE'),
                implode(' | ', $laborNoteParts),
                safe_delete_row_reference($job, ['job_number'], 'JOB'),
                $lineActions
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group(
        'job_labor',
        'Labor Lines',
        count($laborRows),
        $laborRows,
        $laborImpact,
        ((int) ($dep['labor_line_count'] ?? 0) > 0) ? 'Delete all labor lines before soft deleting the job card.' : null
    );

    $partRows = [];
    $partImpact = 0.0;
    if (safe_delete_table_exists('job_parts')) {
        $jpCols = table_columns('job_parts');
        $select = ['jp.id', 'jp.part_id'];
        foreach (['quantity', 'total_amount'] as $col) {
            if (in_array($col, $jpCols, true)) {
                $select[] = 'jp.' . $col;
            }
        }
        $sql = 'SELECT ' . implode(', ', $select) . ', p.part_name, p.part_sku
                FROM job_parts jp
                LEFT JOIN parts p ON p.id = jp.part_id
                WHERE jp.job_card_id = :job_id
                ORDER BY jp.id ASC';
        foreach (safe_delete_fetch_rows($pdo, $sql, ['job_id' => $jobId]) as $row) {
            $amt = safe_delete_pick_amount($row, ['total_amount']) ?? 0.0;
            $partImpact += $amt;
            $lineActions = [];
            if ($jobLockedForLineDelete) {
                $lineActions[] = safe_delete_make_dependency_action_unavailable(
                    'Reopen Job First',
                    $jobStatusUpper === 'CLOSED'
                        ? 'Reopen the job card to OPEN before deleting part lines.'
                        : 'Job card must be ACTIVE and open for line deletion.'
                );
            } else {
                $lineActions[] = safe_delete_make_dependency_action(
                    'Delete',
                    'job_part_line',
                    (int) ($row['id'] ?? 0),
                    'delete',
                    null,
                    true,
                    'outline-danger',
                    true,
                    'Delete part line before soft deleting the job card.'
                );
            }
            $partRows[] = safe_delete_make_item(
                safe_delete_row_reference($row, ['part_sku', 'part_name'], 'JPART'),
                null,
                $amt,
                'LINE',
                'Qty: ' . number_format((float) ($row['quantity'] ?? 0), 2),
                safe_delete_row_reference($job, ['job_number'], 'JOB'),
                $lineActions
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group(
        'job_parts',
        'Part Lines',
        count($partRows),
        $partRows,
        $partImpact,
        ((int) ($dep['part_line_count'] ?? 0) > 0) ? 'Delete all part lines before soft deleting the job card.' : null
    );

    $invoiceRows = [];
    if (safe_delete_table_exists('invoices')) {
        $sql = 'SELECT id, invoice_number'
            . (safe_delete_table_has_column('invoices', 'invoice_status') ? ', invoice_status' : '')
            . (safe_delete_table_has_column('invoices', 'grand_total') ? ', grand_total' : '')
            . (safe_delete_table_has_column('invoices', 'invoice_date') ? ', invoice_date' : '')
            . ' FROM invoices WHERE job_card_id = :job_id';
        $params = ['job_id' => $jobId];
        if (($scope['company_id'] ?? 0) > 0 && safe_delete_table_has_column('invoices', 'company_id')) {
            $sql .= ' AND company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        if (($scope['garage_id'] ?? 0) > 0 && safe_delete_table_has_column('invoices', 'garage_id')) {
            $sql .= ' AND garage_id = :garage_id';
            $params['garage_id'] = (int) $scope['garage_id'];
        }
        $sql .= ' ORDER BY id DESC';
        foreach (safe_delete_fetch_rows($pdo, $sql, $params) as $row) {
            $invoiceStatus = strtoupper(trim((string) ($row['invoice_status'] ?? '')));
            $invoiceActions = ($invoiceStatus !== 'CANCELLED')
                ? [
                    safe_delete_make_dependency_action(
                        'Cancel',
                        'invoice',
                        (int) ($row['id'] ?? 0),
                        'cancel',
                        null,
                        true,
                        'outline-danger',
                        true,
                        'Cancel linked invoices before deleting the job card.'
                    ),
                ]
                : [safe_delete_make_dependency_action_unavailable('Already Cancelled', 'This invoice is already cancelled.')];
            $invoiceRows[] = safe_delete_make_item(
                safe_delete_row_reference($row, ['invoice_number'], 'INV'),
                safe_delete_pick_date($row, ['invoice_date']),
                safe_delete_pick_amount($row, ['grand_total']),
                (string) ($row['invoice_status'] ?? ''),
                null,
                safe_delete_row_reference($job, ['job_number'], 'JOB'),
                $invoiceActions
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group('invoices', 'Invoices', count($invoiceRows), $invoiceRows, 0.0);

    $stockRows = [];
    if (safe_delete_table_exists('inventory_movements')) {
        $sql = 'SELECT id'
            . (safe_delete_table_has_column('inventory_movements', 'movement_uid') ? ', movement_uid' : '')
            . (safe_delete_table_has_column('inventory_movements', 'movement_type') ? ', movement_type' : '')
            . (safe_delete_table_has_column('inventory_movements', 'created_at') ? ', created_at' : '')
            . ' FROM inventory_movements WHERE reference_type = "JOB_CARD" AND reference_id = :job_id';
        $params = ['job_id' => $jobId];
        if (($scope['company_id'] ?? 0) > 0 && safe_delete_table_has_column('inventory_movements', 'company_id')) {
            $sql .= ' AND company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        if (($scope['garage_id'] ?? 0) > 0 && safe_delete_table_has_column('inventory_movements', 'garage_id')) {
            $sql .= ' AND garage_id = :garage_id';
            $params['garage_id'] = (int) $scope['garage_id'];
        }
        $sql .= ' ORDER BY id DESC';
        foreach (safe_delete_fetch_rows($pdo, $sql, $params) as $row) {
            $stockRows[] = safe_delete_make_item(
                safe_delete_row_reference($row, ['movement_uid'], 'MOV'),
                safe_delete_pick_date($row, ['created_at']),
                null,
                (string) ($row['movement_type'] ?? ''),
                'Posted inventory movement linked to this job.',
                safe_delete_row_reference($job, ['job_number'], 'JOB'),
                [safe_delete_make_dependency_action_unavailable('Manage in Job/Stock', 'Stock movement reversals are enforced by job/stock workflows.')]
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group(
        'stock_movements',
        'Stock Movements',
        count($stockRows),
        $stockRows,
        0.0,
        ((int) ($dep['inventory_movements'] ?? 0) > 0) ? 'Inventory postings exist for this job.' : null
    );

    $outRows = [];
    $outImpact = 0.0;
    if (safe_delete_table_exists('outsourced_works')) {
        $rows = safe_delete_fetch_rows(
            $pdo,
            'SELECT ow.id, ow.agreed_cost, ow.status_code, COALESCE(pay.total_paid, 0) AS paid_total
             FROM outsourced_works ow
             LEFT JOIN (
               SELECT outsourced_work_id, SUM(amount) AS total_paid
               FROM outsourced_work_payments
               GROUP BY outsourced_work_id
             ) pay ON pay.outsourced_work_id = ow.id
             WHERE ow.job_card_id = :job_id'
            . (($scope['company_id'] ?? 0) > 0 && safe_delete_table_has_column('outsourced_works', 'company_id') ? ' AND ow.company_id = :company_id' : '')
            . (($scope['garage_id'] ?? 0) > 0 && safe_delete_table_has_column('outsourced_works', 'garage_id') ? ' AND ow.garage_id = :garage_id' : ''),
            array_filter([
                'job_id' => $jobId,
                'company_id' => (int) ($scope['company_id'] ?? 0),
                'garage_id' => (int) ($scope['garage_id'] ?? 0),
            ], static fn (mixed $v): bool => !($v === 0 || $v === null))
        );
        foreach ($rows as $row) {
            $outImpact += round((float) ($row['paid_total'] ?? 0), 2);
            $outRows[] = safe_delete_make_item(
                'Outsource #' . (int) ($row['id'] ?? 0),
                null,
                round((float) ($row['paid_total'] ?? 0), 2),
                (string) ($row['status_code'] ?? ''),
                'Agreed: ' . number_format((float) ($row['agreed_cost'] ?? 0), 2),
                safe_delete_row_reference($job, ['job_number'], 'JOB'),
                [safe_delete_make_dependency_action_unavailable('Manage Outsourced Work', 'Reverse outsourced payments / close work from job or outsourced module before job delete.')]
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group(
        'outsourced_work',
        'Outsourced Work',
        count($outRows),
        $outRows,
        $outImpact,
        ((float) ($dep['outsourced_paid_total'] ?? 0) > 0.009) ? 'Paid outsourced work must be reversed before delete.' : null
    );

    $summary['blockers'] = array_values((array) ($dep['blockers'] ?? []));
    $summary['warnings'] = array_values((array) ($dep['steps'] ?? []));
    $summary['can_proceed'] = (bool) ($dep['can_delete'] ?? false);
    $jobStatus = strtoupper(trim((string) ($job['status'] ?? '')));
    if ($summary['can_proceed'] && $jobStatus === 'OPEN') {
        $summary['recommended_action'] = 'SOFT_DELETE';
        $summary['execution_mode'] = 'soft_delete';
    } elseif ($summary['can_proceed']) {
        $summary['recommended_action'] = 'SOFT_DELETE_WITH_DEPENDENCY_REVERSAL';
        $summary['execution_mode'] = 'mixed_reversal_soft_delete';
    } else {
        $summary['recommended_action'] = 'BLOCK';
        $summary['execution_mode'] = 'block';
    }

    return safe_delete_summary_finalize($summary);
}

function safe_delete_analyze_return(PDO $pdo, int $returnId, array $scope, array $options = []): array
{
    $summary = safe_delete_summary_base('return', $returnId, (string) ($options['operation'] ?? 'delete'));
    $rCols = table_columns('returns_rma');
    if ($rCols === []) {
        throw new RuntimeException('Returns table is not available.');
    }

    $select = ['r.id', 'r.return_number'];
    foreach (['return_type', 'return_date', 'approval_status', 'status_code', 'total_amount'] as $col) {
        if (in_array($col, $rCols, true)) {
            $select[] = 'r.' . $col;
        }
    }
    $sql = 'SELECT ' . implode(', ', $select) . ' FROM returns_rma r WHERE r.id = :return_id';
    $params = ['return_id' => $returnId];
    if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $rCols, true)) {
        $sql .= ' AND r.company_id = :company_id';
        $params['company_id'] = (int) $scope['company_id'];
    }
    if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $rCols, true)) {
        $sql .= ' AND r.garage_id = :garage_id';
        $params['garage_id'] = (int) $scope['garage_id'];
    }
    $sql .= ' LIMIT 1';
    $returnRow = safe_delete_fetch_row($pdo, $sql, $params);
    if (!is_array($returnRow)) {
        throw new RuntimeException('Return entry not found for this scope.');
    }

    $summary['main_record'] = [
        'label' => 'Return ' . safe_delete_row_reference($returnRow, ['return_number'], 'RMA'),
        'reference' => safe_delete_row_reference($returnRow, ['return_number'], 'RMA'),
        'date' => safe_delete_pick_date($returnRow, ['return_date']),
        'amount' => safe_delete_pick_amount($returnRow, ['total_amount']),
        'status' => (string) ($returnRow['approval_status'] ?? ''),
        'note' => (string) ($returnRow['return_type'] ?? ''),
    ];

    $itemRows = [];
    if (safe_delete_table_exists('return_items')) {
        $riCols = table_columns('return_items');
        $select = ['ri.id', 'ri.part_id'];
        foreach (['quantity', 'total_amount'] as $col) {
            if (in_array($col, $riCols, true)) {
                $select[] = 'ri.' . $col;
            }
        }
        $sql = 'SELECT ' . implode(', ', $select) . ', p.part_name
                FROM return_items ri
                LEFT JOIN parts p ON p.id = ri.part_id
                WHERE ri.return_id = :return_id
                ORDER BY ri.id ASC';
        foreach (safe_delete_fetch_rows($pdo, $sql, ['return_id' => $returnId]) as $row) {
            $itemRows[] = safe_delete_make_item(
                trim((string) ($row['part_name'] ?? '')) !== '' ? (string) $row['part_name'] : ('Part #' . (int) ($row['part_id'] ?? 0)),
                null,
                safe_delete_pick_amount($row, ['total_amount']),
                (($row['quantity'] ?? null) !== null ? ('QTY ' . number_format((float) ($row['quantity'] ?? 0), 2)) : null)
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group('return_items', 'Return Items', count($itemRows), $itemRows, 0.0);

    $settlementRows = [];
    $activeSettlementCount = 0;
    $activeSettlementAmount = 0.0;
    if (safe_delete_table_exists('return_settlements')) {
        $rsCols = table_columns('return_settlements');
        $select = ['rs.id'];
        foreach (['settlement_date', 'amount', 'status_code', 'settlement_type'] as $col) {
            if (in_array($col, $rsCols, true)) {
                $select[] = 'rs.' . $col;
            }
        }
        $sql = 'SELECT ' . implode(', ', $select) . ' FROM return_settlements rs WHERE rs.return_id = :return_id';
        $params = ['return_id' => $returnId];
        if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $rsCols, true)) {
            $sql .= ' AND rs.company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $rsCols, true)) {
            $sql .= ' AND rs.garage_id = :garage_id';
            $params['garage_id'] = (int) $scope['garage_id'];
        }
        $sql .= ' ORDER BY rs.id DESC';
        foreach (safe_delete_fetch_rows($pdo, $sql, $params) as $row) {
            $isActive = strtoupper(trim((string) ($row['status_code'] ?? 'ACTIVE'))) === 'ACTIVE';
            if ($isActive) {
                $activeSettlementCount++;
                $activeSettlementAmount += round((float) ($row['amount'] ?? 0), 2);
            }
            $settlementRows[] = safe_delete_make_item(
                'Settlement #' . (int) ($row['id'] ?? 0),
                safe_delete_pick_date($row, ['settlement_date']),
                safe_delete_pick_amount($row, ['amount']),
                (string) ($row['status_code'] ?? ''),
                (string) ($row['settlement_type'] ?? ''),
                null,
                [
                    $isActive
                        ? safe_delete_make_dependency_action(
                            'Reverse',
                            'return_settlement',
                            (int) ($row['id'] ?? 0),
                            'reverse',
                            null,
                            true,
                            'outline-danger',
                            true,
                            'Reverse active settlement before deleting return.'
                        )
                        : safe_delete_make_dependency_action_unavailable('Already Reversed', 'Settlement is not active.')
                ]
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group(
        'settlements',
        'Settlements',
        count($settlementRows),
        $settlementRows,
        $activeSettlementAmount,
        $activeSettlementCount > 0 ? 'Active settlement entries must be reversed first.' : null
    );

    $stockRows = [];
    if (safe_delete_table_exists('stock_reversal_links') && safe_delete_table_has_all_columns('stock_reversal_links', ['reversal_context_type', 'reversal_context_id'])) {
        $contextType = (string) ($returnRow['return_type'] ?? 'CUSTOMER_RETURN');
        $srlCols = table_columns('stock_reversal_links');
        $sql = 'SELECT id, movement_type, quantity, reversal_movement_uid'
            . (in_array('created_at', $srlCols, true) ? ', created_at' : '')
            . ' FROM stock_reversal_links
               WHERE reversal_context_type = :context_type
                 AND reversal_context_id = :context_id';
        $params = ['context_type' => $contextType, 'context_id' => $returnId];
        if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $srlCols, true)) {
            $sql .= ' AND company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $srlCols, true)) {
            $sql .= ' AND garage_id = :garage_id';
            $params['garage_id'] = (int) $scope['garage_id'];
        }
        $sql .= ' ORDER BY id DESC';
        foreach (safe_delete_fetch_rows($pdo, $sql, $params) as $row) {
            $stockRows[] = safe_delete_make_item(
                safe_delete_row_reference($row, ['reversal_movement_uid'], 'MOV'),
                safe_delete_pick_date($row, ['created_at']),
                null,
                (string) ($row['movement_type'] ?? ''),
                (($row['quantity'] ?? null) !== null ? ('Qty ' . number_format((float) ($row['quantity'] ?? 0), 2)) : null)
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group('stock_movements', 'Stock Movements', count($stockRows), $stockRows, 0.0);

    $attachmentRows = [];
    if (safe_delete_table_exists('return_attachments')) {
        $raCols = table_columns('return_attachments');
        $sql = 'SELECT id'
            . (in_array('file_name', $raCols, true) ? ', file_name' : '')
            . (in_array('status_code', $raCols, true) ? ', status_code' : '')
            . (in_array('created_at', $raCols, true) ? ', created_at' : '')
            . ' FROM return_attachments WHERE return_id = :return_id';
        $params = ['return_id' => $returnId];
        if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $raCols, true)) {
            $sql .= ' AND company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $raCols, true)) {
            $sql .= ' AND garage_id = :garage_id';
            $params['garage_id'] = (int) $scope['garage_id'];
        }
        if (in_array('status_code', $raCols, true)) {
            $sql .= ' AND status_code <> "DELETED"';
        }
        foreach (safe_delete_fetch_rows($pdo, $sql, $params) as $row) {
            $attachmentRows[] = safe_delete_make_item(
                safe_delete_row_reference($row, ['file_name'], 'FILE'),
                safe_delete_pick_date($row, ['created_at']),
                null,
                (string) ($row['status_code'] ?? ''),
                null,
                null,
                [
                    safe_delete_make_dependency_action(
                        'Delete',
                        'return_attachment',
                        (int) ($row['id'] ?? 0),
                        'delete',
                        null,
                        true,
                        'outline-danger',
                        true,
                        'Delete attachment before final return delete.'
                    )
                ]
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group('attachments', 'Attachments', count($attachmentRows), $attachmentRows, 0.0);

    if (strtoupper(trim((string) ($returnRow['status_code'] ?? 'ACTIVE'))) !== 'ACTIVE') {
        $summary['blockers'][] = 'Return entry is already deleted/inactive.';
    }
    if ($activeSettlementCount > 0) {
        $summary['blockers'][] = 'Reverse all active settlement entries before deleting this return.';
        $summary['warnings'][] = 'Settlement reversals may create linked financial reversal entries.';
    }

    $summary['can_proceed'] = $summary['blockers'] === [];
    $summary['recommended_action'] = $summary['can_proceed'] ? 'STOCK_REVERSAL_AND_SOFT_DELETE' : 'BLOCK';
    $summary['execution_mode'] = $summary['can_proceed'] ? 'stock_reversal_then_soft_delete' : 'block';

    return safe_delete_summary_finalize($summary);
}

function safe_delete_analyze_customer(PDO $pdo, int $customerId, array $scope, array $options = []): array
{
    $summary = safe_delete_summary_base('customer', $customerId, (string) ($options['operation'] ?? 'delete'));
    if (table_columns('customers') === []) {
        throw new RuntimeException('Customers table is not available.');
    }

    $sql = 'SELECT id, full_name, phone, status_code FROM customers WHERE id = :id';
    $params = ['id' => $customerId];
    if (($scope['company_id'] ?? 0) > 0 && safe_delete_table_has_column('customers', 'company_id')) {
        $sql .= ' AND company_id = :company_id';
        $params['company_id'] = (int) $scope['company_id'];
    }
    $sql .= ' LIMIT 1';
    $customer = safe_delete_fetch_row($pdo, $sql, $params);
    if (!is_array($customer)) {
        throw new RuntimeException('Customer not found for this scope.');
    }

    $summary['main_record'] = [
        'label' => 'Customer ' . (string) ($customer['full_name'] ?? ('#' . $customerId)),
        'reference' => 'Customer #' . $customerId,
        'date' => null,
        'amount' => null,
        'status' => (string) ($customer['status_code'] ?? ''),
        'note' => (string) ($customer['phone'] ?? ''),
    ];

    $vehicleRows = [];
    if (safe_delete_table_exists('vehicles')) {
        $sql = 'SELECT id, registration_no, status_code FROM vehicles WHERE customer_id = :customer_id';
        $params = ['customer_id' => $customerId];
        if (($scope['company_id'] ?? 0) > 0 && safe_delete_table_has_column('vehicles', 'company_id')) {
            $sql .= ' AND company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        $sql .= ' ORDER BY id DESC';
        foreach (safe_delete_fetch_rows($pdo, $sql, $params) as $row) {
            $rowActions = [];
            if ((int) ($row['id'] ?? 0) > 0) {
                $rowActions[] = safe_delete_make_dependency_action(
                    'Delete',
                    'vehicle',
                    (int) ($row['id'] ?? 0),
                    'delete',
                    null,
                    true,
                    'outline-warning',
                    true,
                    'Delete or disable the linked vehicle before deleting the customer.'
                );
            }
            $vehicleRows[] = safe_delete_make_item(
                safe_delete_row_reference($row, ['registration_no'], 'VH'),
                null,
                null,
                (string) ($row['status_code'] ?? ''),
                null,
                (string) ($customer['full_name'] ?? '') !== '' ? ('Customer: ' . (string) $customer['full_name']) : null,
                $rowActions
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group('vehicles', 'Vehicles', count($vehicleRows), $vehicleRows, 0.0);

    $invoiceRows = [];
    $invoiceImpact = 0.0;
    if (safe_delete_table_exists('invoices') && safe_delete_table_has_column('invoices', 'customer_id')) {
        $sql = 'SELECT id, invoice_number'
            . (safe_delete_table_has_column('invoices', 'invoice_date') ? ', invoice_date' : '')
            . (safe_delete_table_has_column('invoices', 'grand_total') ? ', grand_total' : '')
            . (safe_delete_table_has_column('invoices', 'invoice_status') ? ', invoice_status' : '')
            . ' FROM invoices WHERE customer_id = :customer_id';
        $params = ['customer_id' => $customerId];
        if (($scope['company_id'] ?? 0) > 0 && safe_delete_table_has_column('invoices', 'company_id')) {
            $sql .= ' AND company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        $sql .= ' ORDER BY id DESC';
        foreach (safe_delete_fetch_rows($pdo, $sql, $params) as $row) {
            $amt = safe_delete_pick_amount($row, ['grand_total']) ?? 0.0;
            $invoiceImpact += $amt;
            $rowActions = [];
            if ((int) ($row['id'] ?? 0) > 0) {
                $rowActions[] = safe_delete_make_dependency_action(
                    'Cancel',
                    'invoice',
                    (int) ($row['id'] ?? 0),
                    'cancel',
                    null,
                    true,
                    'outline-danger',
                    true,
                    'Cancel this invoice (with safe reversals) before deleting the customer.'
                );
            }
            $invoiceRows[] = safe_delete_make_item(
                safe_delete_row_reference($row, ['invoice_number'], 'INV'),
                safe_delete_pick_date($row, ['invoice_date']),
                $amt,
                (string) ($row['invoice_status'] ?? ''),
                null,
                (string) ($customer['full_name'] ?? '') !== '' ? ('Customer: ' . (string) $customer['full_name']) : null,
                $rowActions
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group('invoices', 'Invoices', count($invoiceRows), $invoiceRows, $invoiceImpact);

    $paymentRows = [];
    $paymentImpact = 0.0;
    if (safe_delete_table_exists('payments') && safe_delete_table_exists('invoices') && safe_delete_table_has_column('invoices', 'customer_id')) {
        $paymentCols = table_columns('payments');
        $sql = 'SELECT p.id, p.amount, i.id AS invoice_id'
            . (in_array('paid_on', $paymentCols, true) ? ', p.paid_on' : '')
            . (in_array('reference_no', $paymentCols, true) ? ', p.reference_no' : '')
            . (in_array('entry_type', $paymentCols, true) ? ', p.entry_type' : '')
            . (in_array('is_reversed', $paymentCols, true) ? ', p.is_reversed' : '')
            . (safe_delete_table_has_column('invoices', 'invoice_number') ? ', i.invoice_number' : '')
            . (safe_delete_table_has_column('invoices', 'invoice_status') ? ', i.invoice_status' : '')
            . ' FROM payments p
               INNER JOIN invoices i ON i.id = p.invoice_id
               WHERE i.customer_id = :customer_id';
        $params = ['customer_id' => $customerId];
        if (($scope['company_id'] ?? 0) > 0 && safe_delete_table_has_column('invoices', 'company_id')) {
            $sql .= ' AND i.company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        $sql .= ' ORDER BY p.id DESC';
        foreach (safe_delete_fetch_rows($pdo, $sql, $params) as $row) {
            $amt = round((float) ($row['amount'] ?? 0), 2);
            $paymentImpact += abs($amt);
            $entryType = strtoupper(trim((string) ($row['entry_type'] ?? ($amt < 0 ? 'REVERSAL' : 'PAYMENT'))));
            $canReverseRow = $entryType === 'PAYMENT'
                && $amt > 0
                && !((int) ($row['is_reversed'] ?? 0) === 1);
            $rowActions = $canReverseRow
                ? [safe_delete_make_dependency_action(
                    'Reverse',
                    'invoice_payment',
                    (int) ($row['id'] ?? 0),
                    'reverse',
                    null,
                    true,
                    'outline-danger',
                    true,
                    'Reverse this payment before deleting the customer.'
                )]
                : [safe_delete_make_dependency_action_unavailable(
                    $entryType === 'REVERSAL' ? 'Already Reversal' : 'Protected',
                    $entryType === 'REVERSAL'
                        ? 'This row is already a reversal entry.'
                        : 'Only original payment entries can be reversed from the summary.'
                )];
            $paymentRows[] = safe_delete_make_item(
                safe_delete_row_reference($row, ['reference_no'], 'PAY'),
                safe_delete_pick_date($row, ['paid_on']),
                $amt,
                $entryType,
                null,
                trim((string) ($row['invoice_number'] ?? '')) !== '' ? ('Invoice: ' . (string) $row['invoice_number']) : null,
                $rowActions
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group('payments', 'Payments', count($paymentRows), $paymentRows, $paymentImpact);

    $ledgerCount = 0;
    $ledgerImpact = 0.0;
    if (safe_delete_table_exists('customer_ledger_entries') && safe_delete_table_has_column('customer_ledger_entries', 'customer_id')) {
        $sql = 'SELECT COUNT(*) AS c, COALESCE(SUM(COALESCE(debit_amount, 0) + COALESCE(credit_amount, 0)), 0) AS t
                FROM customer_ledger_entries
                WHERE customer_id = :customer_id';
        $params = ['customer_id' => $customerId];
        if (($scope['company_id'] ?? 0) > 0 && safe_delete_table_has_column('customer_ledger_entries', 'company_id')) {
            $sql .= ' AND company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        $row = safe_delete_fetch_row($pdo, $sql, $params);
        $ledgerCount = (int) ($row['c'] ?? 0);
        $ledgerImpact = round((float) ($row['t'] ?? 0), 2);
    }
    if ($ledgerCount > 0) {
        $summary['groups'][] = safe_delete_make_group('ledger_entries', 'Customer Ledger Entries', $ledgerCount, [
            safe_delete_make_item(
                'Ledger History',
                null,
                $ledgerImpact,
                'HISTORY',
                'Historical ledger entries cannot be deleted from summary.',
                (string) ($customer['full_name'] ?? '') !== '' ? ('Customer: ' . (string) $customer['full_name']) : null,
                [safe_delete_make_dependency_action_unavailable('Protected History', 'Customer financial history is retained for audit and compliance.')]
            )
        ], $ledgerImpact);
    }

    if ((count($invoiceRows) + count($paymentRows) + $ledgerCount) > 0) {
        $summary['blockers'][] = 'Customer has financial history and cannot be deleted. Use status disable only.';
        $summary['warnings'][] = 'Allowed alternative: change status to INACTIVE.';
    }
    if (strtoupper(trim((string) ($customer['status_code'] ?? 'ACTIVE'))) === 'DELETED') {
        $summary['blockers'][] = 'Customer is already deleted.';
    }

    $summary['can_proceed'] = $summary['blockers'] === [];
    $summary['recommended_action'] = $summary['can_proceed'] ? 'SOFT_DELETE' : 'DISABLE_ONLY';
    $summary['execution_mode'] = $summary['can_proceed'] ? 'soft_delete' : 'disable_only';

    return safe_delete_summary_finalize($summary);
}

function safe_delete_analyze_vendor(PDO $pdo, int $vendorId, array $scope, array $options = []): array
{
    $summary = safe_delete_summary_base('vendor', $vendorId, (string) ($options['operation'] ?? 'delete'));
    if (table_columns('vendors') === []) {
        throw new RuntimeException('Vendors table is not available.');
    }

    $sql = 'SELECT id, vendor_code, vendor_name, status_code FROM vendors WHERE id = :id';
    $params = ['id' => $vendorId];
    if (($scope['company_id'] ?? 0) > 0 && safe_delete_table_has_column('vendors', 'company_id')) {
        $sql .= ' AND company_id = :company_id';
        $params['company_id'] = (int) $scope['company_id'];
    }
    $sql .= ' LIMIT 1';
    $vendor = safe_delete_fetch_row($pdo, $sql, $params);
    if (!is_array($vendor)) {
        throw new RuntimeException('Vendor not found for this scope.');
    }

    $summary['main_record'] = [
        'label' => 'Vendor ' . (string) ($vendor['vendor_name'] ?? ('#' . $vendorId)),
        'reference' => (string) ($vendor['vendor_code'] ?? ('Vendor#' . $vendorId)),
        'date' => null,
        'amount' => null,
        'status' => (string) ($vendor['status_code'] ?? ''),
        'note' => (string) ($vendor['vendor_name'] ?? ''),
    ];

    $partsRows = [];
    if (safe_delete_table_exists('parts') && safe_delete_table_has_column('parts', 'vendor_id')) {
        foreach (safe_delete_fetch_rows($pdo, 'SELECT id, part_sku, part_name' . (safe_delete_table_has_column('parts', 'status_code') ? ', status_code' : '') . ' FROM parts WHERE vendor_id = :vendor_id ORDER BY id DESC', ['vendor_id' => $vendorId]) as $row) {
            $rowActions = [];
            if ((int) ($row['id'] ?? 0) > 0) {
                $rowActions[] = safe_delete_make_dependency_action(
                    'Delete',
                    'inventory_part',
                    (int) ($row['id'] ?? 0),
                    'delete',
                    null,
                    true,
                    'outline-warning',
                    false,
                    'Optional: delete or reassign linked parts before deleting the vendor.'
                );
            }
            $partsRows[] = safe_delete_make_item(
                safe_delete_row_reference($row, ['part_sku', 'part_name'], 'PART'),
                null,
                null,
                (string) ($row['status_code'] ?? ''),
                trim((string) ($row['part_name'] ?? '')) !== '' ? (string) ($row['part_name'] ?? '') : null,
                (string) ($vendor['vendor_name'] ?? '') !== '' ? ('Vendor: ' . (string) $vendor['vendor_name']) : null,
                $rowActions
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group('parts', 'Linked Parts', count($partsRows), $partsRows, 0.0);

    $purchaseRows = [];
    $purchaseImpact = 0.0;
    if (safe_delete_table_exists('purchases') && safe_delete_table_has_column('purchases', 'vendor_id')) {
        $sql = 'SELECT id, invoice_number'
            . (safe_delete_table_has_column('purchases', 'purchase_date') ? ', purchase_date' : '')
            . (safe_delete_table_has_column('purchases', 'grand_total') ? ', grand_total' : '')
            . (safe_delete_table_has_column('purchases', 'purchase_status') ? ', purchase_status' : '')
            . ' FROM purchases WHERE vendor_id = :vendor_id';
        $params = ['vendor_id' => $vendorId];
        if (($scope['company_id'] ?? 0) > 0 && safe_delete_table_has_column('purchases', 'company_id')) {
            $sql .= ' AND company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        $sql .= ' ORDER BY id DESC';
        foreach (safe_delete_fetch_rows($pdo, $sql, $params) as $row) {
            $amt = safe_delete_pick_amount($row, ['grand_total']) ?? 0.0;
            $purchaseImpact += $amt;
            $rowActions = [];
            if ((int) ($row['id'] ?? 0) > 0) {
                $rowActions[] = safe_delete_make_dependency_action(
                    'Delete',
                    'purchase',
                    (int) ($row['id'] ?? 0),
                    'delete',
                    null,
                    true,
                    'outline-danger',
                    true,
                    'Delete this purchase (with stock reversal) before deleting the vendor.'
                );
            }
            $purchaseRows[] = safe_delete_make_item(
                safe_delete_row_reference($row, ['invoice_number'], 'PUR'),
                safe_delete_pick_date($row, ['purchase_date']),
                $amt,
                (string) ($row['purchase_status'] ?? ''),
                null,
                (string) ($vendor['vendor_name'] ?? '') !== '' ? ('Vendor: ' . (string) $vendor['vendor_name']) : null,
                $rowActions
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group('purchases', 'Purchases', count($purchaseRows), $purchaseRows, $purchaseImpact);

    $paymentRows = [];
    $paymentImpact = 0.0;
    if (safe_delete_table_exists('purchase_payments') && safe_delete_table_exists('purchases') && safe_delete_table_has_column('purchases', 'vendor_id')) {
        $ppCols = table_columns('purchase_payments');
        $sql = 'SELECT pp.id, pp.amount, p.id AS purchase_id'
            . (in_array('payment_date', $ppCols, true) ? ', pp.payment_date' : '')
            . (in_array('reference_no', $ppCols, true) ? ', pp.reference_no' : '')
            . (in_array('entry_type', $ppCols, true) ? ', pp.entry_type' : '')
            . (safe_delete_table_has_column('purchases', 'invoice_number') ? ', p.invoice_number' : '')
            . ' FROM purchase_payments pp
               INNER JOIN purchases p ON p.id = pp.purchase_id
               WHERE p.vendor_id = :vendor_id';
        $params = ['vendor_id' => $vendorId];
        if (($scope['company_id'] ?? 0) > 0 && safe_delete_table_has_column('purchases', 'company_id')) {
            $sql .= ' AND p.company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        foreach (safe_delete_fetch_rows($pdo, $sql, $params) as $row) {
            $amt = round((float) ($row['amount'] ?? 0), 2);
            $paymentImpact += abs($amt);
            $entryType = strtoupper(trim((string) ($row['entry_type'] ?? ($amt < 0 ? 'REVERSAL' : 'PAYMENT'))));
            $canReverseRow = $entryType === 'PAYMENT' && $amt > 0;
            $rowActions = $canReverseRow
                ? [safe_delete_make_dependency_action(
                    'Reverse',
                    'purchase_payment',
                    (int) ($row['id'] ?? 0),
                    'reverse',
                    null,
                    true,
                    'outline-danger',
                    true,
                    'Reverse this purchase payment before deleting the vendor.'
                )]
                : [safe_delete_make_dependency_action_unavailable(
                    $entryType === 'REVERSAL' ? 'Already Reversal' : 'Protected',
                    $entryType === 'REVERSAL'
                        ? 'This row is already a reversal entry.'
                        : 'Only payment entries can be reversed from the summary.'
                )];
            $paymentRows[] = safe_delete_make_item(
                safe_delete_row_reference($row, ['reference_no'], 'PPAY'),
                safe_delete_pick_date($row, ['payment_date']),
                $amt,
                $entryType,
                null,
                trim((string) ($row['invoice_number'] ?? '')) !== '' ? ('Purchase: ' . (string) $row['invoice_number']) : null,
                $rowActions
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group('payments', 'Vendor Payments', count($paymentRows), $paymentRows, $paymentImpact);

    if ((count($purchaseRows) + count($paymentRows)) > 0) {
        $summary['blockers'][] = 'Vendor has financial history and cannot be deleted. Use status disable only.';
        $summary['warnings'][] = 'Allowed alternative: change status to INACTIVE.';
    }
    if (strtoupper(trim((string) ($vendor['status_code'] ?? 'ACTIVE'))) === 'DELETED') {
        $summary['blockers'][] = 'Vendor is already deleted.';
    }

    $summary['can_proceed'] = $summary['blockers'] === [];
    $summary['recommended_action'] = $summary['can_proceed'] ? 'SOFT_DELETE' : 'DISABLE_ONLY';
    $summary['execution_mode'] = $summary['can_proceed'] ? 'soft_delete' : 'disable_only';

    return safe_delete_summary_finalize($summary);
}

function safe_delete_analyze_vehicle(PDO $pdo, int $vehicleId, array $scope, array $options = []): array
{
    $summary = safe_delete_summary_base('vehicle', $vehicleId, (string) ($options['operation'] ?? 'delete'));
    if (table_columns('vehicles') === []) {
        throw new RuntimeException('Vehicles table is not available.');
    }

    $sql = 'SELECT id, registration_no, status_code, customer_id FROM vehicles WHERE id = :id';
    $params = ['id' => $vehicleId];
    if (($scope['company_id'] ?? 0) > 0 && safe_delete_table_has_column('vehicles', 'company_id')) {
        $sql .= ' AND company_id = :company_id';
        $params['company_id'] = (int) $scope['company_id'];
    }
    $sql .= ' LIMIT 1';
    $vehicle = safe_delete_fetch_row($pdo, $sql, $params);
    if (!is_array($vehicle)) {
        throw new RuntimeException('Vehicle not found for this scope.');
    }

    $summary['main_record'] = [
        'label' => 'Vehicle ' . safe_delete_row_reference($vehicle, ['registration_no'], 'VH'),
        'reference' => safe_delete_row_reference($vehicle, ['registration_no'], 'VH'),
        'date' => null,
        'amount' => null,
        'status' => (string) ($vehicle['status_code'] ?? ''),
        'note' => 'Customer #' . (int) ($vehicle['customer_id'] ?? 0),
    ];

    $jobRows = [];
    if (safe_delete_table_exists('job_cards') && safe_delete_table_has_column('job_cards', 'vehicle_id')) {
        $sql = 'SELECT id, job_number'
            . (safe_delete_table_has_column('job_cards', 'status') ? ', status' : '')
            . (safe_delete_table_has_column('job_cards', 'status_code') ? ', status_code' : '')
            . (safe_delete_table_has_column('job_cards', 'created_at') ? ', created_at' : '')
            . ' FROM job_cards WHERE vehicle_id = :vehicle_id';
        $params = ['vehicle_id' => $vehicleId];
        if (($scope['company_id'] ?? 0) > 0 && safe_delete_table_has_column('job_cards', 'company_id')) {
            $sql .= ' AND company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        $sql .= ' ORDER BY id DESC';
        foreach (safe_delete_fetch_rows($pdo, $sql, $params) as $row) {
            $jobIdRow = (int) ($row['id'] ?? 0);
            $jobActions = $jobIdRow > 0
                ? [safe_delete_make_dependency_action(
                    'Delete',
                    'job_card',
                    $jobIdRow,
                    'delete',
                    null,
                    true,
                    'outline-danger',
                    true,
                    'Delete the job card (after resolving its own blockers) before deleting the vehicle.'
                )]
                : [safe_delete_make_dependency_action_unavailable(
                    'Delete in Job Cards',
                    'Use Job Card screen safe delete to clear this dependency.'
                )];
            $jobRows[] = safe_delete_make_item(
                safe_delete_row_reference($row, ['job_number'], 'JOB'),
                safe_delete_pick_date($row, ['created_at']),
                null,
                (string) (($row['status'] ?? '') ?: ($row['status_code'] ?? '')),
                'Delete from Job Cards to clear vehicle dependency.',
                safe_delete_row_reference($vehicle, ['registration_no'], 'VH'),
                $jobActions
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group('job_cards', 'Job Cards', count($jobRows), $jobRows, 0.0);

    $invoiceRows = [];
    $invoiceImpact = 0.0;
    if (safe_delete_table_exists('invoices') && safe_delete_table_exists('job_cards') && safe_delete_table_has_column('job_cards', 'vehicle_id')) {
        $sql = 'SELECT i.id, i.invoice_number'
            . (safe_delete_table_has_column('invoices', 'invoice_status') ? ', i.invoice_status' : '')
            . (safe_delete_table_has_column('invoices', 'grand_total') ? ', i.grand_total' : '')
            . (safe_delete_table_has_column('invoices', 'invoice_date') ? ', i.invoice_date' : '')
            . ' FROM invoices i
               INNER JOIN job_cards jc ON jc.id = i.job_card_id
               WHERE jc.vehicle_id = :vehicle_id';
        $params = ['vehicle_id' => $vehicleId];
        if (($scope['company_id'] ?? 0) > 0 && safe_delete_table_has_column('invoices', 'company_id')) {
            $sql .= ' AND i.company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        $sql .= ' ORDER BY i.id DESC';
        foreach (safe_delete_fetch_rows($pdo, $sql, $params) as $row) {
            $amt = safe_delete_pick_amount($row, ['grand_total']) ?? 0.0;
            $invoiceImpact += $amt;
            $invoiceRows[] = safe_delete_make_item(
                safe_delete_row_reference($row, ['invoice_number'], 'INV'),
                safe_delete_pick_date($row, ['invoice_date']),
                $amt,
                (string) ($row['invoice_status'] ?? ''),
                null,
                safe_delete_row_reference($vehicle, ['registration_no'], 'VH'),
                [
                    safe_delete_make_dependency_action(
                        'Cancel',
                        'invoice',
                        (int) ($row['id'] ?? 0),
                        'cancel',
                        null,
                        true,
                        'outline-danger',
                        true,
                        'Cancel the linked invoice before deleting the vehicle.'
                    ),
                ]
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group('invoices', 'Invoices', count($invoiceRows), $invoiceRows, $invoiceImpact);

    if (safe_delete_table_exists('insurance_claims') && safe_delete_table_has_column('insurance_claims', 'vehicle_id')) {
        $rows = [];
        foreach (safe_delete_fetch_rows(
            $pdo,
            'SELECT id'
            . (safe_delete_table_has_column('insurance_claims', 'claim_number') ? ', claim_number' : '')
            . (safe_delete_table_has_column('insurance_claims', 'claim_date') ? ', claim_date' : '')
            . (safe_delete_table_has_column('insurance_claims', 'status_code') ? ', status_code' : '')
            . ' FROM insurance_claims WHERE vehicle_id = :vehicle_id',
            ['vehicle_id' => $vehicleId]
        ) as $row) {
            $rows[] = safe_delete_make_item(
                safe_delete_row_reference($row, ['claim_number'], 'CLM'),
                safe_delete_pick_date($row, ['claim_date']),
                null,
                (string) ($row['status_code'] ?? ''),
                'Delete or close claim from Insurance Claims module if required.',
                safe_delete_row_reference($vehicle, ['registration_no'], 'VH'),
                [safe_delete_make_dependency_action_unavailable('Manage in Claims', 'Insurance claim actions are not yet wired in this summary.')]
            );
        }
        if ($rows !== []) {
            $summary['groups'][] = safe_delete_make_group('insurance_claims', 'Insurance Claims', count($rows), $rows, 0.0);
        }
    }

    if ((count($jobRows) + count($invoiceRows)) > 0) {
        $summary['blockers'][] = 'Vehicle has service/financial history and should not be deleted. Use status disable only.';
        $summary['warnings'][] = 'Allowed alternative: change status to INACTIVE.';
    }
    if (strtoupper(trim((string) ($vehicle['status_code'] ?? 'ACTIVE'))) === 'DELETED') {
        $summary['blockers'][] = 'Vehicle is already deleted.';
    }

    $summary['can_proceed'] = $summary['blockers'] === [];
    $summary['recommended_action'] = $summary['can_proceed'] ? 'SOFT_DELETE' : 'DISABLE_ONLY';
    $summary['execution_mode'] = $summary['can_proceed'] ? 'soft_delete' : 'disable_only';

    return safe_delete_summary_finalize($summary);
}

function safe_delete_analyze_payroll_advance(PDO $pdo, int $advanceId, array $scope, array $options = []): array
{
    $summary = safe_delete_summary_base('payroll_advance', $advanceId, (string) ($options['operation'] ?? 'delete'));
    if (table_columns('payroll_advances') === []) {
        throw new RuntimeException('Payroll advances table is not available.');
    }

    $sql = 'SELECT pa.id, pa.user_id, pa.advance_date, pa.amount, pa.applied_amount, pa.status, u.name
            FROM payroll_advances pa
            LEFT JOIN users u ON u.id = pa.user_id
            WHERE pa.id = :id';
    $params = ['id' => $advanceId];
    if (($scope['company_id'] ?? 0) > 0 && safe_delete_table_has_column('payroll_advances', 'company_id')) {
        $sql .= ' AND pa.company_id = :company_id';
        $params['company_id'] = (int) $scope['company_id'];
    }
    if (($scope['garage_id'] ?? 0) > 0 && safe_delete_table_has_column('payroll_advances', 'garage_id')) {
        $sql .= ' AND pa.garage_id = :garage_id';
        $params['garage_id'] = (int) $scope['garage_id'];
    }
    $sql .= ' LIMIT 1';
    $row = safe_delete_fetch_row($pdo, $sql, $params);
    if (!is_array($row)) {
        throw new RuntimeException('Payroll advance not found for this scope.');
    }

    $amount = round((float) ($row['amount'] ?? 0), 2);
    $applied = round((float) ($row['applied_amount'] ?? 0), 2);
    $pending = max(0.0, round($amount - $applied, 2));

    $summary['main_record'] = [
        'label' => 'Payroll Advance #' . $advanceId,
        'reference' => 'Advance #' . $advanceId,
        'date' => safe_delete_pick_date($row, ['advance_date']),
        'amount' => $amount,
        'status' => (string) ($row['status'] ?? ''),
        'note' => (string) ($row['name'] ?? ''),
    ];
    $summary['groups'][] = safe_delete_make_group('advance_application', 'Advance Application', 1, [
        safe_delete_make_item('Applied Amount', null, $applied, $applied > 0.009 ? 'APPLIED' : 'UNUSED', 'Pending: ' . number_format($pending, 2))
    ], $applied);

    if ($applied > 0.009) {
        $summary['blockers'][] = 'Cannot delete an advance that is already applied.';
    }
    if (strtoupper(trim((string) ($row['status'] ?? 'OPEN'))) === 'DELETED') {
        $summary['blockers'][] = 'Advance is already deleted.';
    }

    $summary['can_proceed'] = $summary['blockers'] === [];
    $summary['recommended_action'] = $summary['can_proceed'] ? 'SOFT_DELETE' : 'BLOCK';
    $summary['execution_mode'] = $summary['can_proceed'] ? 'soft_delete' : 'block';

    return safe_delete_summary_finalize($summary);
}

function safe_delete_analyze_payroll_loan(PDO $pdo, int $loanId, array $scope, array $options = []): array
{
    $summary = safe_delete_summary_base('payroll_loan', $loanId, (string) ($options['operation'] ?? 'delete'));
    if (table_columns('payroll_loans') === []) {
        throw new RuntimeException('Payroll loans table is not available.');
    }

    $sql = 'SELECT pl.id, pl.user_id, pl.loan_date, pl.total_amount, pl.paid_amount, pl.status, u.name
            FROM payroll_loans pl
            LEFT JOIN users u ON u.id = pl.user_id
            WHERE pl.id = :id';
    $params = ['id' => $loanId];
    if (($scope['company_id'] ?? 0) > 0 && safe_delete_table_has_column('payroll_loans', 'company_id')) {
        $sql .= ' AND pl.company_id = :company_id';
        $params['company_id'] = (int) $scope['company_id'];
    }
    if (($scope['garage_id'] ?? 0) > 0 && safe_delete_table_has_column('payroll_loans', 'garage_id')) {
        $sql .= ' AND pl.garage_id = :garage_id';
        $params['garage_id'] = (int) $scope['garage_id'];
    }
    $sql .= ' LIMIT 1';
    $row = safe_delete_fetch_row($pdo, $sql, $params);
    if (!is_array($row)) {
        throw new RuntimeException('Payroll loan not found for this scope.');
    }

    $total = round((float) ($row['total_amount'] ?? 0), 2);
    $paid = round((float) ($row['paid_amount'] ?? 0), 2);
    $pending = max(0.0, round($total - $paid, 2));

    $summary['main_record'] = [
        'label' => 'Payroll Loan #' . $loanId,
        'reference' => 'Loan #' . $loanId,
        'date' => safe_delete_pick_date($row, ['loan_date']),
        'amount' => $total,
        'status' => (string) ($row['status'] ?? ''),
        'note' => (string) ($row['name'] ?? ''),
    ];

    $paymentRows = [];
    if (safe_delete_table_exists('payroll_loan_payments')) {
        foreach (safe_delete_fetch_rows(
            $pdo,
            'SELECT id, payment_date, amount, entry_type'
            . (safe_delete_table_has_column('payroll_loan_payments', 'reference_no') ? ', reference_no' : '')
            . ' FROM payroll_loan_payments WHERE loan_id = :loan_id ORDER BY id DESC',
            ['loan_id' => $loanId]
        ) as $payRow) {
            $paymentRows[] = safe_delete_make_item(
                safe_delete_row_reference($payRow, ['reference_no'], 'LPMT'),
                safe_delete_pick_date($payRow, ['payment_date']),
                round((float) ($payRow['amount'] ?? 0), 2),
                (string) ($payRow['entry_type'] ?? '')
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group('loan_payments', 'Loan Payments', count($paymentRows), $paymentRows, abs($paid));
    $summary['groups'][] = safe_delete_make_group('loan_balance', 'Loan Balance', 1, [
        safe_delete_make_item('Paid Amount', null, $paid, $paid > 0.009 ? 'PAID' : 'UNPAID', 'Pending: ' . number_format($pending, 2))
    ], abs($paid));

    if ($paid > 0.009) {
        $summary['blockers'][] = 'Cannot delete a loan that has payments.';
    }
    if (strtoupper(trim((string) ($row['status'] ?? 'ACTIVE'))) === 'DELETED') {
        $summary['blockers'][] = 'Loan is already deleted.';
    }

    $summary['can_proceed'] = $summary['blockers'] === [];
    $summary['recommended_action'] = $summary['can_proceed'] ? 'SOFT_DELETE' : 'BLOCK';
    $summary['execution_mode'] = $summary['can_proceed'] ? 'soft_delete' : 'block';

    return safe_delete_summary_finalize($summary);
}

function safe_delete_analyze_return_settlement(PDO $pdo, int $settlementId, array $scope, array $options = []): array
{
    $summary = safe_delete_summary_base('return_settlement', $settlementId, (string) ($options['operation'] ?? 'reverse'));
    if (!safe_delete_table_exists('return_settlements') || !safe_delete_table_exists('returns_rma')) {
        throw new RuntimeException('Return settlement tables are not available.');
    }

    $settlementCols = table_columns('return_settlements');
    $returnCols = table_columns('returns_rma');
    $select = ['rs.id', 'rs.return_id'];
    foreach (['settlement_date', 'settlement_type', 'amount', 'payment_mode', 'reference_no', 'notes', 'expense_id', 'status_code'] as $col) {
        if (in_array($col, $settlementCols, true)) {
            $select[] = 'rs.' . $col;
        }
    }
    foreach (['return_number', 'return_type', 'approval_status', 'total_amount'] as $col) {
        if (in_array($col, $returnCols, true)) {
            $select[] = 'r.' . $col;
        }
    }

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM return_settlements rs'
        . ' INNER JOIN returns_rma r ON r.id = rs.return_id'
        . ' WHERE rs.id = :id';
    $params = ['id' => $settlementId];
    if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $settlementCols, true)) {
        $sql .= ' AND rs.company_id = :company_id';
        $params['company_id'] = (int) $scope['company_id'];
    }
    if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $settlementCols, true)) {
        $sql .= ' AND rs.garage_id = :garage_id';
        $params['garage_id'] = (int) $scope['garage_id'];
    }
    $sql .= ' LIMIT 1';
    $row = safe_delete_fetch_row($pdo, $sql, $params);
    if (!is_array($row)) {
        throw new RuntimeException('Return settlement not found for this scope.');
    }

    $amount = abs(round((float) ($row['amount'] ?? 0), 2));
    $settlementType = strtoupper(trim((string) ($row['settlement_type'] ?? '')));
    $returnLabel = safe_delete_row_reference($row, ['return_number'], 'RET', 'return_id');
    $mainRef = 'Settlement #' . $settlementId . ' (' . ($settlementType !== '' ? $settlementType : 'ENTRY') . ')';
    $summary['main_record'] = [
        'label' => $mainRef . ' for ' . $returnLabel,
        'reference' => $mainRef,
        'date' => safe_delete_pick_date($row, ['settlement_date']),
        'amount' => $amount,
        'status' => (string) ($row['status_code'] ?? ''),
        'note' => $returnLabel . ' | ' . (string) ($row['approval_status'] ?? ''),
    ];

    $summary['groups'][] = safe_delete_make_group('return_record', 'Return Record', 1, [
        safe_delete_make_item(
            $returnLabel,
            null,
            safe_delete_pick_amount($row, ['total_amount']),
            (string) ($row['approval_status'] ?? ''),
            (string) ($row['return_type'] ?? '')
        ),
    ], 0.0);

    $expenseId = (int) ($row['expense_id'] ?? 0);
    if ($expenseId > 0 && safe_delete_table_exists('expenses')) {
        $expenseRows = [];
        $expenseImpact = 0.0;
        foreach (safe_delete_fetch_rows(
            $pdo,
            'SELECT id'
            . (safe_delete_table_has_column('expenses', 'expense_date') ? ', expense_date' : '')
            . (safe_delete_table_has_column('expenses', 'amount') ? ', amount' : '')
            . (safe_delete_table_has_column('expenses', 'entry_type') ? ', entry_type' : '')
            . (safe_delete_table_has_column('expenses', 'source_type') ? ', source_type' : '')
            . (safe_delete_table_has_column('expenses', 'status_code') ? ', status_code' : '')
            . ' FROM expenses WHERE id = :id',
            ['id' => $expenseId]
        ) as $expRow) {
            $amt = abs(round((float) ($expRow['amount'] ?? 0), 2));
            $expenseImpact += $amt;
            $expenseRows[] = safe_delete_make_item(
                'Expense #' . (int) ($expRow['id'] ?? 0),
                safe_delete_pick_date($expRow, ['expense_date']),
                $amt,
                (string) (($expRow['entry_type'] ?? '') ?: ($expRow['status_code'] ?? '')),
                (string) ($expRow['source_type'] ?? '')
            );
        }
        $summary['groups'][] = safe_delete_make_group('linked_expense', 'Linked Finance Expense', count($expenseRows), $expenseRows, $expenseImpact);

        if (safe_delete_table_has_column('expenses', 'reversed_expense_id')) {
            $revRows = [];
            foreach (safe_delete_fetch_rows(
                $pdo,
                'SELECT id'
                . (safe_delete_table_has_column('expenses', 'expense_date') ? ', expense_date' : '')
                . (safe_delete_table_has_column('expenses', 'amount') ? ', amount' : '')
                . (safe_delete_table_has_column('expenses', 'entry_type') ? ', entry_type' : '')
                . ' FROM expenses WHERE reversed_expense_id = :expense_id ORDER BY id DESC',
                ['expense_id' => $expenseId]
            ) as $revRow) {
                $revRows[] = safe_delete_make_item(
                    'Expense #' . (int) ($revRow['id'] ?? 0),
                    safe_delete_pick_date($revRow, ['expense_date']),
                    abs(round((float) ($revRow['amount'] ?? 0), 2)),
                    (string) ($revRow['entry_type'] ?? '')
                );
            }
            if ($revRows !== []) {
                $summary['groups'][] = safe_delete_make_group('finance_reversal', 'Existing Finance Reversal', count($revRows), $revRows, 0.0);
                $summary['warnings'][] = 'Linked finance reversal already exists and will be reused if applicable.';
            }
        }
    }

    $statusCode = strtoupper(trim((string) ($row['status_code'] ?? 'ACTIVE')));
    if ($statusCode !== '' && $statusCode !== 'ACTIVE') {
        $summary['blockers'][] = 'Settlement entry is already reversed/deleted.';
    }
    $approvalStatus = strtoupper(trim((string) ($row['approval_status'] ?? '')));
    if (!in_array($approvalStatus, ['APPROVED', 'CLOSED'], true)) {
        $summary['blockers'][] = 'Settlement reversal is allowed only for APPROVED or CLOSED returns.';
    }
    if ($amount <= 0.0) {
        $summary['blockers'][] = 'Settlement amount is invalid for reversal.';
    }

    $summary['can_proceed'] = $summary['blockers'] === [];
    $summary['recommended_action'] = $summary['can_proceed'] ? 'FINANCIAL_REVERSAL' : 'BLOCK';
    $summary['execution_mode'] = $summary['can_proceed'] ? 'financial_reversal' : 'block';

    return safe_delete_summary_finalize($summary);
}

function safe_delete_analyze_expense(PDO $pdo, int $expenseId, array $scope, array $options = []): array
{
    $summary = safe_delete_summary_base('expense', $expenseId, (string) ($options['operation'] ?? 'reverse'));
    $expenseCols = table_columns('expenses');
    if ($expenseCols === []) {
        throw new RuntimeException('Expenses table is not available.');
    }

    $select = ['e.id'];
    foreach ([
        'company_id', 'garage_id', 'category_id', 'expense_date', 'amount', 'paid_to', 'payment_mode',
        'notes', 'source_type', 'source_id', 'entry_type', 'reversed_expense_id', 'status_code'
    ] as $col) {
        if (in_array($col, $expenseCols, true)) {
            $select[] = 'e.' . $col;
        }
    }
    $joinCategory = safe_delete_table_exists('expense_categories')
        && safe_delete_table_has_column('expense_categories', 'category_name')
        && in_array('category_id', $expenseCols, true);
    if ($joinCategory) {
        $select[] = 'c.category_name';
    }

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM expenses e';
    if ($joinCategory) {
        $sql .= ' LEFT JOIN expense_categories c ON c.id = e.category_id';
    }
    $sql .= ' WHERE e.id = :id';
    $params = ['id' => $expenseId];
    if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $expenseCols, true)) {
        $sql .= ' AND e.company_id = :company_id';
        $params['company_id'] = (int) $scope['company_id'];
    }
    if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $expenseCols, true)) {
        $sql .= ' AND e.garage_id = :garage_id';
        $params['garage_id'] = (int) $scope['garage_id'];
    }
    $sql .= ' LIMIT 1';
    $row = safe_delete_fetch_row($pdo, $sql, $params);
    if (!is_array($row)) {
        throw new RuntimeException('Expense not found for this scope.');
    }

    $amount = abs(round((float) ($row['amount'] ?? 0), 2));
    $entryType = strtoupper(trim((string) ($row['entry_type'] ?? '')));
    $sourceType = strtoupper(trim((string) ($row['source_type'] ?? '')));
    $categoryName = trim((string) ($row['category_name'] ?? ''));

    $summary['main_record'] = [
        'label' => 'Expense #' . $expenseId,
        'reference' => 'Expense #' . $expenseId,
        'date' => safe_delete_pick_date($row, ['expense_date']),
        'amount' => $amount,
        'status' => (string) (($row['entry_type'] ?? '') ?: ($row['status_code'] ?? '')),
        'note' => ($categoryName !== '' ? $categoryName : 'Uncategorized')
            . ((string) ($row['paid_to'] ?? '') !== '' ? (' | Paid To: ' . (string) $row['paid_to']) : ''),
    ];

    $summary['groups'][] = safe_delete_make_group('expense_context', 'Expense Context', 1, [
        safe_delete_make_item(
            $categoryName !== '' ? $categoryName : 'Category',
            null,
            $amount,
            (string) ($row['payment_mode'] ?? ''),
            (string) ($row['source_type'] ?? '')
        ),
    ], $amount);

    $reversalRows = [];
    $reversalImpact = 0.0;
    if (in_array('reversed_expense_id', $expenseCols, true)) {
        $sql = 'SELECT id'
            . (in_array('expense_date', $expenseCols, true) ? ', expense_date' : '')
            . (in_array('amount', $expenseCols, true) ? ', amount' : '')
            . (in_array('entry_type', $expenseCols, true) ? ', entry_type' : '')
            . (in_array('source_type', $expenseCols, true) ? ', source_type' : '')
            . ' FROM expenses WHERE reversed_expense_id = :expense_id';
        $params = ['expense_id' => $expenseId];
        if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $expenseCols, true)) {
            $sql .= ' AND company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $expenseCols, true)) {
            $sql .= ' AND garage_id = :garage_id';
            $params['garage_id'] = (int) $scope['garage_id'];
        }
        $sql .= ' ORDER BY id DESC';
        foreach (safe_delete_fetch_rows($pdo, $sql, $params) as $revRow) {
            $amt = abs(round((float) ($revRow['amount'] ?? 0), 2));
            $reversalImpact += $amt;
            $reversalRows[] = safe_delete_make_item(
                'Expense #' . (int) ($revRow['id'] ?? 0),
                safe_delete_pick_date($revRow, ['expense_date']),
                $amt,
                (string) ($revRow['entry_type'] ?? ''),
                (string) ($revRow['source_type'] ?? '')
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group('expense_reversals', 'Existing Reversals', count($reversalRows), $reversalRows, $reversalImpact);

    if ($entryType !== 'EXPENSE') {
        $summary['blockers'][] = 'Only original EXPENSE entries can be reversed.';
    }
    if (round((float) ($row['amount'] ?? 0), 2) <= 0.0) {
        $summary['blockers'][] = 'Only positive expense entries can be reversed.';
    }
    if ((int) ($row['reversed_expense_id'] ?? 0) > 0) {
        $summary['blockers'][] = 'Reversal entries cannot be reversed again.';
    }
    if ($reversalRows !== []) {
        $summary['blockers'][] = 'Expense is already reversed.';
    }
    if (!in_array($sourceType, ['', 'MANUAL_EXPENSE', 'MANUAL_EXPENSE_REV'], true)) {
        $summary['blockers'][] = 'Linked/system expense entries must be reversed from their source module.';
        $summary['warnings'][] = 'Source type: ' . $sourceType;
    }
    if (strtoupper(trim((string) ($row['status_code'] ?? 'ACTIVE'))) === 'DELETED') {
        $summary['blockers'][] = 'Expense is already deleted.';
    }

    $summary['can_proceed'] = $summary['blockers'] === [];
    $summary['recommended_action'] = $summary['can_proceed'] ? 'FINANCIAL_REVERSAL' : 'BLOCK';
    $summary['execution_mode'] = $summary['can_proceed'] ? 'financial_reversal' : 'block';

    return safe_delete_summary_finalize($summary);
}

function safe_delete_analyze_expense_category(PDO $pdo, int $categoryId, array $scope, array $options = []): array
{
    $summary = safe_delete_summary_base('expense_category', $categoryId, (string) ($options['operation'] ?? 'delete'));
    if (!safe_delete_table_exists('expense_categories')) {
        throw new RuntimeException('Expense categories table is not available.');
    }

    $catCols = table_columns('expense_categories');
    $sql = 'SELECT id'
        . (in_array('category_name', $catCols, true) ? ', category_name' : '')
        . (in_array('status_code', $catCols, true) ? ', status_code' : '')
        . ' FROM expense_categories WHERE id = :id';
    $params = ['id' => $categoryId];
    if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $catCols, true)) {
        $sql .= ' AND company_id = :company_id';
        $params['company_id'] = (int) $scope['company_id'];
    }
    if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $catCols, true)) {
        $sql .= ' AND garage_id = :garage_id';
        $params['garage_id'] = (int) $scope['garage_id'];
    }
    $sql .= ' LIMIT 1';
    $category = safe_delete_fetch_row($pdo, $sql, $params);
    if (!is_array($category)) {
        throw new RuntimeException('Expense category not found for this scope.');
    }

    $summary['main_record'] = [
        'label' => 'Expense Category ' . safe_delete_row_reference($category, ['category_name'], 'CAT'),
        'reference' => safe_delete_row_reference($category, ['category_name'], 'CAT'),
        'date' => null,
        'amount' => null,
        'status' => (string) ($category['status_code'] ?? ''),
        'note' => 'Inactivate only (history retained)',
    ];

    $expenseRows = [];
    $expenseImpact = 0.0;
    if (safe_delete_table_exists('expenses') && safe_delete_table_has_column('expenses', 'category_id')) {
        $expenseCols = table_columns('expenses');
        $sql = 'SELECT id'
            . (in_array('expense_date', $expenseCols, true) ? ', expense_date' : '')
            . (in_array('amount', $expenseCols, true) ? ', amount' : '')
            . (in_array('entry_type', $expenseCols, true) ? ', entry_type' : '')
            . (in_array('source_type', $expenseCols, true) ? ', source_type' : '')
            . ' FROM expenses WHERE category_id = :category_id';
        $params = ['category_id' => $categoryId];
        if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $expenseCols, true)) {
            $sql .= ' AND company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $expenseCols, true)) {
            $sql .= ' AND garage_id = :garage_id';
            $params['garage_id'] = (int) $scope['garage_id'];
        }
        $sql .= ' ORDER BY id DESC';
        foreach (safe_delete_fetch_rows($pdo, $sql, $params) as $row) {
            $amt = abs(round((float) ($row['amount'] ?? 0), 2));
            $expenseImpact += $amt;
            $expenseRows[] = safe_delete_make_item(
                'Expense #' . (int) ($row['id'] ?? 0),
                safe_delete_pick_date($row, ['expense_date']),
                $amt,
                (string) ($row['entry_type'] ?? ''),
                (string) ($row['source_type'] ?? '')
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group('expenses', 'Linked Expenses', count($expenseRows), $expenseRows, $expenseImpact);
    if ($expenseRows !== []) {
        $summary['warnings'][] = 'Category has historical usage. It will be inactivated only.';
    }

    $status = strtoupper(trim((string) ($category['status_code'] ?? 'ACTIVE')));
    if (in_array($status, ['INACTIVE', 'DELETED'], true)) {
        $summary['blockers'][] = 'Expense category is already inactive.';
    }

    $summary['can_proceed'] = $summary['blockers'] === [];
    $summary['recommended_action'] = $summary['can_proceed'] ? 'DISABLE_ONLY' : 'BLOCK';
    $summary['execution_mode'] = $summary['can_proceed'] ? 'disable_only' : 'block';

    return safe_delete_summary_finalize($summary);
}

function safe_delete_analyze_outsourced_payment(PDO $pdo, int $paymentId, array $scope, array $options = []): array
{
    $summary = safe_delete_summary_base('outsourced_payment', $paymentId, (string) ($options['operation'] ?? 'reverse'));
    if (!safe_delete_table_exists('outsourced_work_payments') || !safe_delete_table_exists('outsourced_works')) {
        throw new RuntimeException('Outsourced payment tables are not available.');
    }

    $paymentCols = table_columns('outsourced_work_payments');
    $workCols = table_columns('outsourced_works');
    $joinJob = safe_delete_table_exists('job_cards')
        && safe_delete_table_has_column('outsourced_works', 'job_card_id')
        && safe_delete_table_has_column('job_cards', 'job_number');

    $select = ['p.id', 'p.outsourced_work_id'];
    foreach (['payment_date', 'entry_type', 'amount', 'payment_mode', 'reference_no', 'notes', 'reversed_payment_id'] as $col) {
        if (in_array($col, $paymentCols, true)) {
            $select[] = 'p.' . $col;
        }
    }
    foreach (['current_status', 'agreed_cost'] as $col) {
        if (in_array($col, $workCols, true)) {
            $select[] = 'ow.' . $col;
        }
    }
    if ($joinJob) {
        $select[] = 'jc.job_number';
    }

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM outsourced_work_payments p'
        . ' INNER JOIN outsourced_works ow ON ow.id = p.outsourced_work_id';
    if ($joinJob) {
        $sql .= ' LEFT JOIN job_cards jc ON jc.id = ow.job_card_id';
    }
    $sql .= ' WHERE p.id = :id';
    $params = ['id' => $paymentId];
    if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $paymentCols, true)) {
        $sql .= ' AND p.company_id = :company_id';
        $params['company_id'] = (int) $scope['company_id'];
    }
    if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $paymentCols, true)) {
        $sql .= ' AND p.garage_id = :garage_id';
        $params['garage_id'] = (int) $scope['garage_id'];
    }
    $sql .= ' LIMIT 1';
    $row = safe_delete_fetch_row($pdo, $sql, $params);
    if (!is_array($row)) {
        throw new RuntimeException('Outsourced payment not found for this scope.');
    }

    $amount = abs(round((float) ($row['amount'] ?? 0), 2));
    $workLabel = 'Outsourced Work #' . (int) ($row['outsourced_work_id'] ?? 0);
    if (trim((string) ($row['job_number'] ?? '')) !== '') {
        $workLabel .= ' | ' . (string) $row['job_number'];
    }

    $summary['main_record'] = [
        'label' => 'Outsourced Payment #' . $paymentId,
        'reference' => safe_delete_row_reference($row, ['reference_no'], 'OWPAY'),
        'date' => safe_delete_pick_date($row, ['payment_date']),
        'amount' => $amount,
        'status' => (string) ($row['entry_type'] ?? ''),
        'note' => $workLabel . ((string) ($row['payment_mode'] ?? '') !== '' ? (' | ' . (string) $row['payment_mode']) : ''),
    ];

    $summary['groups'][] = safe_delete_make_group('outsourced_work', 'Outsourced Work', 1, [
        safe_delete_make_item(
            $workLabel,
            null,
            safe_delete_pick_amount($row, ['agreed_cost']),
            (string) ($row['current_status'] ?? '')
        ),
    ], 0.0);

    $reversalRows = [];
    if (in_array('reversed_payment_id', $paymentCols, true)) {
        $sql = 'SELECT id'
            . (in_array('payment_date', $paymentCols, true) ? ', payment_date' : '')
            . (in_array('amount', $paymentCols, true) ? ', amount' : '')
            . (in_array('entry_type', $paymentCols, true) ? ', entry_type' : '')
            . (in_array('reference_no', $paymentCols, true) ? ', reference_no' : '')
            . ' FROM outsourced_work_payments WHERE reversed_payment_id = :payment_id';
        $params = ['payment_id' => $paymentId];
        if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $paymentCols, true)) {
            $sql .= ' AND company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $paymentCols, true)) {
            $sql .= ' AND garage_id = :garage_id';
            $params['garage_id'] = (int) $scope['garage_id'];
        }
        $sql .= ' ORDER BY id DESC';
        foreach (safe_delete_fetch_rows($pdo, $sql, $params) as $revRow) {
            $reversalRows[] = safe_delete_make_item(
                safe_delete_row_reference($revRow, ['reference_no'], 'OWREV'),
                safe_delete_pick_date($revRow, ['payment_date']),
                abs(round((float) ($revRow['amount'] ?? 0), 2)),
                (string) ($revRow['entry_type'] ?? '')
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group('payment_reversals', 'Existing Payment Reversal', count($reversalRows), $reversalRows, 0.0);

    if (safe_delete_table_exists('expenses') && safe_delete_table_has_column('expenses', 'source_id') && safe_delete_table_has_column('expenses', 'source_type')) {
        $financeRows = [];
        $financeImpact = 0.0;
        foreach (safe_delete_fetch_rows(
            $pdo,
            'SELECT id'
            . (safe_delete_table_has_column('expenses', 'expense_date') ? ', expense_date' : '')
            . (safe_delete_table_has_column('expenses', 'amount') ? ', amount' : '')
            . (safe_delete_table_has_column('expenses', 'entry_type') ? ', entry_type' : '')
            . ' FROM expenses
               WHERE source_id = :source_id
                 AND source_type IN ("OUTSOURCED_PAYMENT", "OUTSOURCED_PAYMENT_REV")
               ORDER BY id DESC',
            ['source_id' => $paymentId]
        ) as $financeRow) {
            $amt = abs(round((float) ($financeRow['amount'] ?? 0), 2));
            $financeImpact += $amt;
            $financeRows[] = safe_delete_make_item(
                'Expense #' . (int) ($financeRow['id'] ?? 0),
                safe_delete_pick_date($financeRow, ['expense_date']),
                $amt,
                (string) ($financeRow['entry_type'] ?? '')
            );
        }
        if ($financeRows !== []) {
            $summary['groups'][] = safe_delete_make_group('finance_entries', 'Linked Expense Entries', count($financeRows), $financeRows, $financeImpact);
        }
    }

    if (strtoupper(trim((string) ($row['entry_type'] ?? ''))) !== 'PAYMENT') {
        $summary['blockers'][] = 'Only outsourced PAYMENT entries can be reversed.';
    }
    if (round((float) ($row['amount'] ?? 0), 2) <= 0.0) {
        $summary['blockers'][] = 'Only positive outsourced payment entries can be reversed.';
    }
    if ($reversalRows !== []) {
        $summary['blockers'][] = 'This outsourced payment is already reversed.';
    }

    $summary['can_proceed'] = $summary['blockers'] === [];
    $summary['recommended_action'] = $summary['can_proceed'] ? 'FINANCIAL_REVERSAL' : 'BLOCK';
    $summary['execution_mode'] = $summary['can_proceed'] ? 'financial_reversal' : 'block';

    return safe_delete_summary_finalize($summary);
}

function safe_delete_analyze_payroll_salary_structure(PDO $pdo, int $structureId, array $scope, array $options = []): array
{
    $summary = safe_delete_summary_base('payroll_salary_structure', $structureId, (string) ($options['operation'] ?? 'delete'));
    if (!safe_delete_table_exists('payroll_salary_structures')) {
        throw new RuntimeException('Payroll salary structures table is not available.');
    }

    $cols = table_columns('payroll_salary_structures');
    $joinUsers = safe_delete_table_exists('users') && safe_delete_table_has_column('users', 'name');
    $select = ['pss.id', 'pss.user_id'];
    foreach (['salary_type', 'base_amount', 'commission_rate', 'overtime_rate', 'status_code'] as $col) {
        if (in_array($col, $cols, true)) {
            $select[] = 'pss.' . $col;
        }
    }
    if ($joinUsers) {
        $select[] = 'u.name';
    }

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM payroll_salary_structures pss';
    if ($joinUsers) {
        $sql .= ' LEFT JOIN users u ON u.id = pss.user_id';
    }
    $sql .= ' WHERE pss.id = :id';
    $params = ['id' => $structureId];
    if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $cols, true)) {
        $sql .= ' AND pss.company_id = :company_id';
        $params['company_id'] = (int) $scope['company_id'];
    }
    if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $cols, true)) {
        $sql .= ' AND pss.garage_id = :garage_id';
        $params['garage_id'] = (int) $scope['garage_id'];
    }
    $sql .= ' LIMIT 1';
    $row = safe_delete_fetch_row($pdo, $sql, $params);
    if (!is_array($row)) {
        throw new RuntimeException('Payroll salary structure not found for this scope.');
    }

    $staffLabel = trim((string) ($row['name'] ?? '')) !== ''
        ? (string) $row['name']
        : ('User #' . (int) ($row['user_id'] ?? 0));
    $summary['main_record'] = [
        'label' => 'Salary Structure #' . $structureId . ' - ' . $staffLabel,
        'reference' => 'Structure #' . $structureId,
        'date' => null,
        'amount' => safe_delete_pick_amount($row, ['base_amount']),
        'status' => (string) ($row['status_code'] ?? ''),
        'note' => (string) ($row['salary_type'] ?? ''),
    ];

    if (safe_delete_table_exists('payroll_salary_items') && safe_delete_table_has_column('payroll_salary_items', 'user_id')) {
        $itemRows = [];
        $impact = 0.0;
        $itemCols = table_columns('payroll_salary_items');
        $joinSheet = safe_delete_table_exists('payroll_salary_sheets') && safe_delete_table_has_column('payroll_salary_items', 'sheet_id') && safe_delete_table_has_column('payroll_salary_sheets', 'salary_month');
        $sql = 'SELECT psi.id'
            . (in_array('net_payable', $itemCols, true) ? ', psi.net_payable' : '')
            . (in_array('paid_amount', $itemCols, true) ? ', psi.paid_amount' : '')
            . (in_array('status', $itemCols, true) ? ', psi.status' : '')
            . ($joinSheet ? ', psh.salary_month' : '')
            . ' FROM payroll_salary_items psi';
        if ($joinSheet) {
            $sql .= ' LEFT JOIN payroll_salary_sheets psh ON psh.id = psi.sheet_id';
        }
        $sql .= ' WHERE psi.user_id = :user_id';
        $params = ['user_id' => (int) ($row['user_id'] ?? 0)];
        if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $itemCols, true)) {
            $sql .= ' AND psi.company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $itemCols, true)) {
            $sql .= ' AND psi.garage_id = :garage_id';
            $params['garage_id'] = (int) $scope['garage_id'];
        }
        $sql .= ' ORDER BY psi.id DESC';
        foreach (safe_delete_fetch_rows($pdo, $sql, $params) as $itemRow) {
            $amt = abs(round((float) ($itemRow['net_payable'] ?? 0), 2));
            $impact += $amt;
            $note = trim((string) ($itemRow['salary_month'] ?? ''));
            $paid = round((float) ($itemRow['paid_amount'] ?? 0), 2);
            if ($paid > 0.0) {
                $note = trim($note . ($note !== '' ? ' | ' : '') . 'Paid: ' . number_format($paid, 2));
            }
            $itemRows[] = safe_delete_make_item(
                'Salary Row #' . (int) ($itemRow['id'] ?? 0),
                null,
                $amt,
                (string) ($itemRow['status'] ?? ''),
                $note !== '' ? $note : null
            );
        }
        $summary['groups'][] = safe_delete_make_group('salary_history', 'Salary History For Staff', count($itemRows), $itemRows, $impact);
        if ($itemRows !== []) {
            $summary['warnings'][] = 'Structure has payroll history. It will be inactivated only.';
        }
    }

    $status = strtoupper(trim((string) ($row['status_code'] ?? 'ACTIVE')));
    if (in_array($status, ['INACTIVE', 'DELETED'], true)) {
        $summary['blockers'][] = 'Salary structure is already inactive.';
    }

    $summary['can_proceed'] = $summary['blockers'] === [];
    $summary['recommended_action'] = $summary['can_proceed'] ? 'DISABLE_ONLY' : 'BLOCK';
    $summary['execution_mode'] = $summary['can_proceed'] ? 'disable_only' : 'block';

    return safe_delete_summary_finalize($summary);
}

function safe_delete_analyze_payroll_loan_payment(PDO $pdo, int $paymentId, array $scope, array $options = []): array
{
    $summary = safe_delete_summary_base('payroll_loan_payment', $paymentId, (string) ($options['operation'] ?? 'reverse'));
    if (!safe_delete_table_exists('payroll_loan_payments') || !safe_delete_table_exists('payroll_loans')) {
        throw new RuntimeException('Payroll loan payment tables are not available.');
    }

    $payCols = table_columns('payroll_loan_payments');
    $loanCols = table_columns('payroll_loans');
    $select = ['lp.id', 'lp.loan_id'];
    foreach (['payment_date', 'entry_type', 'amount', 'reference_no', 'notes', 'reversed_payment_id', 'salary_item_id'] as $col) {
        if (in_array($col, $payCols, true)) {
            $select[] = 'lp.' . $col;
        }
    }
    foreach (['total_amount', 'paid_amount', 'status'] as $col) {
        if (in_array($col, $loanCols, true)) {
            $select[] = 'pl.' . $col;
        }
    }

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM payroll_loan_payments lp
           INNER JOIN payroll_loans pl ON pl.id = lp.loan_id
           WHERE lp.id = :id';
    $params = ['id' => $paymentId];
    if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $payCols, true)) {
        $sql .= ' AND lp.company_id = :company_id';
        $params['company_id'] = (int) $scope['company_id'];
    }
    if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $payCols, true)) {
        $sql .= ' AND lp.garage_id = :garage_id';
        $params['garage_id'] = (int) $scope['garage_id'];
    }
    $sql .= ' LIMIT 1';
    $row = safe_delete_fetch_row($pdo, $sql, $params);
    if (!is_array($row)) {
        throw new RuntimeException('Payroll loan payment not found for this scope.');
    }

    $amount = abs(round((float) ($row['amount'] ?? 0), 2));
    $summary['main_record'] = [
        'label' => 'Loan Payment #' . $paymentId,
        'reference' => safe_delete_row_reference($row, ['reference_no'], 'LPMT'),
        'date' => safe_delete_pick_date($row, ['payment_date']),
        'amount' => $amount,
        'status' => (string) ($row['entry_type'] ?? ''),
        'note' => 'Loan #' . (int) ($row['loan_id'] ?? 0),
    ];

    $summary['groups'][] = safe_delete_make_group('loan_context', 'Loan Context', 1, [
        safe_delete_make_item(
            'Loan #' . (int) ($row['loan_id'] ?? 0),
            null,
            safe_delete_pick_amount($row, ['total_amount']),
            (string) ($row['status'] ?? ''),
            'Paid: ' . number_format((float) ($row['paid_amount'] ?? 0), 2)
        ),
    ], 0.0);

    $reversalRows = [];
    if (in_array('reversed_payment_id', $payCols, true)) {
        $sql = 'SELECT id'
            . (in_array('payment_date', $payCols, true) ? ', payment_date' : '')
            . (in_array('amount', $payCols, true) ? ', amount' : '')
            . (in_array('entry_type', $payCols, true) ? ', entry_type' : '')
            . (in_array('reference_no', $payCols, true) ? ', reference_no' : '')
            . ' FROM payroll_loan_payments WHERE reversed_payment_id = :payment_id';
        $params = ['payment_id' => $paymentId];
        if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $payCols, true)) {
            $sql .= ' AND company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $payCols, true)) {
            $sql .= ' AND garage_id = :garage_id';
            $params['garage_id'] = (int) $scope['garage_id'];
        }
        $sql .= ' ORDER BY id DESC';
        foreach (safe_delete_fetch_rows($pdo, $sql, $params) as $revRow) {
            $reversalRows[] = safe_delete_make_item(
                safe_delete_row_reference($revRow, ['reference_no'], 'LREV'),
                safe_delete_pick_date($revRow, ['payment_date']),
                abs(round((float) ($revRow['amount'] ?? 0), 2)),
                (string) ($revRow['entry_type'] ?? '')
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group('payment_reversals', 'Existing Reversal', count($reversalRows), $reversalRows, 0.0);

    if (strtoupper(trim((string) ($row['entry_type'] ?? ''))) !== 'MANUAL') {
        $summary['blockers'][] = 'Only manual loan payments can be reversed from this screen.';
    }
    if (round((float) ($row['amount'] ?? 0), 2) <= 0.0) {
        $summary['blockers'][] = 'Only positive loan payment entries can be reversed.';
    }
    if ($reversalRows !== []) {
        $summary['blockers'][] = 'This loan payment is already reversed.';
    }

    $summary['can_proceed'] = $summary['blockers'] === [];
    $summary['recommended_action'] = $summary['can_proceed'] ? 'FINANCIAL_REVERSAL' : 'BLOCK';
    $summary['execution_mode'] = $summary['can_proceed'] ? 'financial_reversal' : 'block';

    return safe_delete_summary_finalize($summary);
}

function safe_delete_analyze_payroll_salary_entry(PDO $pdo, int $itemId, array $scope, array $options = []): array
{
    $summary = safe_delete_summary_base('payroll_salary_entry', $itemId, (string) ($options['operation'] ?? 'reverse'));
    if (!safe_delete_table_exists('payroll_salary_items') || !safe_delete_table_exists('payroll_salary_sheets')) {
        throw new RuntimeException('Payroll salary tables are not available.');
    }

    $itemCols = table_columns('payroll_salary_items');
    $sheetCols = table_columns('payroll_salary_sheets');
    $select = ['psi.id', 'psi.sheet_id'];
    foreach ([
        'user_id', 'gross_amount', 'net_payable', 'paid_amount', 'status', 'deductions_applied',
        'advance_deduction', 'loan_deduction', 'manual_deduction', 'notes'
    ] as $col) {
        if (in_array($col, $itemCols, true)) {
            $select[] = 'psi.' . $col;
        }
    }
    foreach (['salary_month', 'status'] as $col) {
        if (in_array($col, $sheetCols, true)) {
            $select[] = 'psh.' . $col . ' AS sheet_' . $col;
        }
    }

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM payroll_salary_items psi
           INNER JOIN payroll_salary_sheets psh ON psh.id = psi.sheet_id
           WHERE psi.id = :id';
    $params = ['id' => $itemId];
    if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $sheetCols, true)) {
        $sql .= ' AND psh.company_id = :company_id';
        $params['company_id'] = (int) $scope['company_id'];
    }
    if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $sheetCols, true)) {
        $sql .= ' AND psh.garage_id = :garage_id';
        $params['garage_id'] = (int) $scope['garage_id'];
    }
    $sql .= ' LIMIT 1';
    $row = safe_delete_fetch_row($pdo, $sql, $params);
    if (!is_array($row)) {
        throw new RuntimeException('Payroll salary entry not found for this scope.');
    }

    $gross = round((float) ($row['gross_amount'] ?? 0), 2);
    $net = round((float) ($row['net_payable'] ?? 0), 2);
    $paid = round((float) ($row['paid_amount'] ?? 0), 2);
    $sheetStatus = strtoupper(trim((string) ($row['sheet_status'] ?? '')));

    $summary['main_record'] = [
        'label' => 'Salary Entry #' . $itemId,
        'reference' => 'Salary Row #' . $itemId,
        'date' => (string) ($row['sheet_salary_month'] ?? ''),
        'amount' => abs($net),
        'status' => (string) (($row['status'] ?? '') ?: $sheetStatus),
        'note' => 'Sheet #' . (int) ($row['sheet_id'] ?? 0) . ' | User #' . (int) ($row['user_id'] ?? 0),
    ];

    $summary['groups'][] = safe_delete_make_group('salary_values', 'Salary Values', 1, [
        safe_delete_make_item(
            'Gross/Net/Paid',
            null,
            abs($net),
            (string) ($row['status'] ?? ''),
            'Gross: ' . number_format($gross, 2)
                . ' | Paid: ' . number_format($paid, 2)
                . ' | Adv Ded: ' . number_format((float) ($row['advance_deduction'] ?? 0), 2)
                . ' | Loan Ded: ' . number_format((float) ($row['loan_deduction'] ?? 0), 2)
        ),
    ], abs($net));

    $activePaymentCount = 0;
    $paymentRows = [];
    if (safe_delete_table_exists('payroll_salary_payments')) {
        $payCols = table_columns('payroll_salary_payments');
        $sql = 'SELECT p.id'
            . (in_array('payment_date', $payCols, true) ? ', p.payment_date' : '')
            . (in_array('amount', $payCols, true) ? ', p.amount' : '')
            . (in_array('entry_type', $payCols, true) ? ', p.entry_type' : '')
            . (in_array('reference_no', $payCols, true) ? ', p.reference_no' : '')
            . ', r.id AS reversal_id'
            . ' FROM payroll_salary_payments p
               LEFT JOIN payroll_salary_payments r ON r.reversed_payment_id = p.id
               WHERE p.salary_item_id = :salary_item_id';
        $params = ['salary_item_id' => $itemId];
        if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $payCols, true)) {
            $sql .= ' AND p.company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $payCols, true)) {
            $sql .= ' AND p.garage_id = :garage_id';
            $params['garage_id'] = (int) $scope['garage_id'];
        }
        $sql .= ' ORDER BY p.id DESC';
        foreach (safe_delete_fetch_rows($pdo, $sql, $params) as $payRow) {
            $isActivePayment = strtoupper(trim((string) ($payRow['entry_type'] ?? ''))) === 'PAYMENT'
                && round((float) ($payRow['amount'] ?? 0), 2) > 0.0
                && (int) ($payRow['reversal_id'] ?? 0) <= 0;
            if ($isActivePayment) {
                $activePaymentCount++;
            }
            $paymentRows[] = safe_delete_make_item(
                safe_delete_row_reference($payRow, ['reference_no'], 'SPMT'),
                safe_delete_pick_date($payRow, ['payment_date']),
                abs(round((float) ($payRow['amount'] ?? 0), 2)),
                (string) ($payRow['entry_type'] ?? ''),
                $isActivePayment ? 'ACTIVE PAYMENT' : ((int) ($payRow['reversal_id'] ?? 0) > 0 ? 'REVERSED' : null)
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group('salary_payments', 'Salary Payment History', count($paymentRows), $paymentRows, abs($paid));

    if (safe_delete_table_exists('payroll_loan_payments') && safe_delete_table_has_column('payroll_loan_payments', 'salary_item_id')) {
        $loanDedRows = [];
        foreach (safe_delete_fetch_rows(
            $pdo,
            'SELECT id'
            . (safe_delete_table_has_column('payroll_loan_payments', 'payment_date') ? ', payment_date' : '')
            . (safe_delete_table_has_column('payroll_loan_payments', 'amount') ? ', amount' : '')
            . (safe_delete_table_has_column('payroll_loan_payments', 'entry_type') ? ', entry_type' : '')
            . ' FROM payroll_loan_payments WHERE salary_item_id = :salary_item_id ORDER BY id DESC',
            ['salary_item_id' => $itemId]
        ) as $loanPayRow) {
            $loanDedRows[] = safe_delete_make_item(
                'Loan Pay #' . (int) ($loanPayRow['id'] ?? 0),
                safe_delete_pick_date($loanPayRow, ['payment_date']),
                abs(round((float) ($loanPayRow['amount'] ?? 0), 2)),
                (string) ($loanPayRow['entry_type'] ?? '')
            );
        }
        if ($loanDedRows !== []) {
            $summary['groups'][] = safe_delete_make_group('loan_deductions', 'Linked Loan Deductions', count($loanDedRows), $loanDedRows, 0.0);
        }
    }

    if ($sheetStatus === 'LOCKED') {
        $summary['blockers'][] = 'Unlock by reversing payments before reversing this salary row.';
    }
    if ($net > 0.001 && ($paid + 0.009) >= $net) {
        $summary['blockers'][] = 'Fully settled salary rows cannot be reversed directly.';
    }
    if ($paid > 0.009) {
        $summary['blockers'][] = 'Reverse salary payments before reversing salary entry.';
    }
    if ((int) ($row['deductions_applied'] ?? 0) === 1) {
        $summary['blockers'][] = 'This row has applied deductions. Reverse linked deductions first.';
    }
    if ($activePaymentCount > 0) {
        $summary['blockers'][] = 'Open payment history exists. Reverse those payments first.';
    }
    if (abs($gross) <= 0.001 && abs($net) <= 0.001 && abs($paid) <= 0.001) {
        $summary['warnings'][] = 'Salary row already appears zeroed/reversed.';
    }

    $summary['can_proceed'] = $summary['blockers'] === [];
    $summary['recommended_action'] = $summary['can_proceed'] ? 'FINANCIAL_REVERSAL' : 'BLOCK';
    $summary['execution_mode'] = $summary['can_proceed'] ? 'financial_reversal' : 'block';

    return safe_delete_summary_finalize($summary);
}

function safe_delete_analyze_payroll_salary_payment(PDO $pdo, int $paymentId, array $scope, array $options = []): array
{
    $summary = safe_delete_summary_base('payroll_salary_payment', $paymentId, (string) ($options['operation'] ?? 'reverse'));
    if (!safe_delete_table_exists('payroll_salary_payments') || !safe_delete_table_exists('payroll_salary_items') || !safe_delete_table_exists('payroll_salary_sheets')) {
        throw new RuntimeException('Payroll salary payment tables are not available.');
    }

    $payCols = table_columns('payroll_salary_payments');
    $itemCols = table_columns('payroll_salary_items');
    $sheetCols = table_columns('payroll_salary_sheets');
    $select = ['psp.id', 'psp.salary_item_id', 'psp.sheet_id', 'psp.user_id'];
    foreach (['payment_date', 'entry_type', 'amount', 'payment_mode', 'reference_no', 'notes', 'reversed_payment_id'] as $col) {
        if (in_array($col, $payCols, true)) {
            $select[] = 'psp.' . $col;
        }
    }
    foreach (['net_payable', 'paid_amount', 'status'] as $col) {
        if (in_array($col, $itemCols, true)) {
            $select[] = 'psi.' . $col;
        }
    }
    foreach (['salary_month', 'status'] as $col) {
        if (in_array($col, $sheetCols, true)) {
            $select[] = 'psh.' . $col . ' AS sheet_' . $col;
        }
    }

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM payroll_salary_payments psp
           INNER JOIN payroll_salary_items psi ON psi.id = psp.salary_item_id
           INNER JOIN payroll_salary_sheets psh ON psh.id = psp.sheet_id
           WHERE psp.id = :id';
    $params = ['id' => $paymentId];
    if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $payCols, true)) {
        $sql .= ' AND psp.company_id = :company_id';
        $params['company_id'] = (int) $scope['company_id'];
    }
    if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $payCols, true)) {
        $sql .= ' AND psp.garage_id = :garage_id';
        $params['garage_id'] = (int) $scope['garage_id'];
    }
    $sql .= ' LIMIT 1';
    $row = safe_delete_fetch_row($pdo, $sql, $params);
    if (!is_array($row)) {
        throw new RuntimeException('Payroll salary payment not found for this scope.');
    }

    $amount = abs(round((float) ($row['amount'] ?? 0), 2));
    $summary['main_record'] = [
        'label' => 'Salary Payment #' . $paymentId,
        'reference' => safe_delete_row_reference($row, ['reference_no'], 'PAY'),
        'date' => safe_delete_pick_date($row, ['payment_date']),
        'amount' => $amount,
        'status' => (string) ($row['entry_type'] ?? ''),
        'note' => 'Salary Row #' . (int) ($row['salary_item_id'] ?? 0) . ' | Sheet ' . (string) ($row['sheet_salary_month'] ?? ''),
    ];

    $summary['groups'][] = safe_delete_make_group('salary_entry', 'Salary Entry', 1, [
        safe_delete_make_item(
            'Salary Row #' . (int) ($row['salary_item_id'] ?? 0),
            (string) ($row['sheet_salary_month'] ?? ''),
            abs(round((float) ($row['net_payable'] ?? 0), 2)),
            (string) (($row['status'] ?? '') ?: ($row['sheet_status'] ?? '')),
            'Current paid: ' . number_format((float) ($row['paid_amount'] ?? 0), 2)
        ),
    ], 0.0);

    $reversalRows = [];
    if (in_array('reversed_payment_id', $payCols, true)) {
        $sql = 'SELECT id'
            . (in_array('payment_date', $payCols, true) ? ', payment_date' : '')
            . (in_array('amount', $payCols, true) ? ', amount' : '')
            . (in_array('entry_type', $payCols, true) ? ', entry_type' : '')
            . (in_array('reference_no', $payCols, true) ? ', reference_no' : '')
            . ' FROM payroll_salary_payments WHERE reversed_payment_id = :payment_id';
        $params = ['payment_id' => $paymentId];
        if (($scope['company_id'] ?? 0) > 0 && in_array('company_id', $payCols, true)) {
            $sql .= ' AND company_id = :company_id';
            $params['company_id'] = (int) $scope['company_id'];
        }
        if (($scope['garage_id'] ?? 0) > 0 && in_array('garage_id', $payCols, true)) {
            $sql .= ' AND garage_id = :garage_id';
            $params['garage_id'] = (int) $scope['garage_id'];
        }
        $sql .= ' ORDER BY id DESC';
        foreach (safe_delete_fetch_rows($pdo, $sql, $params) as $revRow) {
            $reversalRows[] = safe_delete_make_item(
                safe_delete_row_reference($revRow, ['reference_no'], 'SREV'),
                safe_delete_pick_date($revRow, ['payment_date']),
                abs(round((float) ($revRow['amount'] ?? 0), 2)),
                (string) ($revRow['entry_type'] ?? '')
            );
        }
    }
    $summary['groups'][] = safe_delete_make_group('payment_reversals', 'Existing Reversal', count($reversalRows), $reversalRows, 0.0);

    if (strtoupper(trim((string) ($row['entry_type'] ?? ''))) !== 'PAYMENT') {
        $summary['blockers'][] = 'Only salary PAYMENT entries can be reversed.';
    }
    if (round((float) ($row['amount'] ?? 0), 2) <= 0.0) {
        $summary['blockers'][] = 'Only positive salary payment entries can be reversed.';
    }
    if ($reversalRows !== []) {
        $summary['blockers'][] = 'This salary payment is already reversed.';
    }

    $summary['can_proceed'] = $summary['blockers'] === [];
    $summary['recommended_action'] = $summary['can_proceed'] ? 'FINANCIAL_REVERSAL' : 'BLOCK';
    $summary['execution_mode'] = $summary['can_proceed'] ? 'financial_reversal' : 'block';

    return safe_delete_summary_finalize($summary);
}
