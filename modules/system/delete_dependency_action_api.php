<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_csrf();

$entity = safe_delete_normalize_entity(post_string('entity', 80));
$recordId = post_int('record_id');
if ($recordId <= 0) {
    $recordId = post_int('id');
}
$operation = strtolower(trim((string) ($_POST['operation'] ?? 'delete')));
if ($operation === '') {
    $operation = 'delete';
}
$reason = post_string('reason', 255);

try {
    if ($entity === '') {
        throw new RuntimeException('Dependency entity is required.');
    }
    if ($recordId <= 0) {
        throw new RuntimeException('Dependency record is required.');
    }
    if ($reason === '') {
        throw new RuntimeException('Reason is required for dependency action.');
    }
    if (!safe_delete_dependency_action_supported($entity, $operation)) {
        throw new RuntimeException('This dependency cannot be resolved directly from the summary.');
    }

    safe_delete_require_dependency_resolution_permission();
    safe_delete_require_global_permissions($entity);

    $scope = [
        'company_id' => active_company_id(),
        'garage_id' => active_garage_id(),
        'user_id' => (int) ($_SESSION['user_id'] ?? 0),
    ];

    $summary = safe_delete_analyze(db(), $entity, $recordId, $scope, ['operation' => $operation]);
    if (!(bool) ($summary['can_proceed'] ?? false)) {
        $blockers = array_values(array_filter(array_map('trim', (array) ($summary['blockers'] ?? []))));
        $message = 'Dependency action is blocked.';
        if ($blockers !== []) {
            $message .= ' ' . implode(' ', $blockers);
        }
        throw new RuntimeException($message);
    }

    $result = safe_delete_dependency_execute_action(
        db(),
        $entity,
        $recordId,
        $operation,
        $scope,
        (int) ($_SESSION['user_id'] ?? 0),
        $reason
    );

    $validationLike = [
        'entity' => $entity,
        'record_id' => $recordId,
        'operation' => $operation,
        'summary' => $summary,
        'reason' => $reason,
    ];
    safe_delete_log_cascade($entity, 'dependency_' . $operation, $recordId, $validationLike, [
        'reversal_references' => (array) ($result['reversal_references'] ?? []),
        'metadata' => (array) ($result['metadata'] ?? []),
    ]);

    ajax_json([
        'ok' => true,
        'message' => (string) ($result['message'] ?? 'Dependency action completed successfully.'),
        'entity' => $entity,
        'record_id' => $recordId,
        'operation' => $operation,
        'result' => $result,
    ]);
} catch (Throwable $exception) {
    ajax_json([
        'ok' => false,
        'message' => $exception->getMessage(),
        'entity' => $entity,
        'record_id' => $recordId,
        'operation' => $operation,
    ], 422);
}

