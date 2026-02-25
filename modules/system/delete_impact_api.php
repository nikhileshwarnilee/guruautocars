<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_csrf();

if (!is_ajax_request()) {
    // Keep endpoint usable from normal POST for debugging, but always return JSON.
}

$entity = safe_delete_normalize_entity(post_string('entity', 80));
$recordId = post_int('record_id');
if ($recordId <= 0) {
    $recordId = post_int('id');
}
$operation = strtolower(trim((string) ($_POST['operation'] ?? 'delete')));
if ($operation === '') {
    $operation = 'delete';
}

try {
    if ($entity === '') {
        throw new RuntimeException('Entity is required for deletion preview.');
    }
    if ($recordId <= 0) {
        throw new RuntimeException('Record is required for deletion preview.');
    }

    safe_delete_require_global_permissions($entity);

    $summary = safe_delete_analyze(
        db(),
        $entity,
        $recordId,
        [
            'company_id' => active_company_id(),
            'garage_id' => active_garage_id(),
            'user_id' => (int) ($_SESSION['user_id'] ?? 0),
        ],
        ['operation' => $operation]
    );
    $token = safe_delete_issue_preview_token($summary);

    ajax_json([
        'ok' => true,
        'summary' => $summary,
        'preview_token' => $token,
    ]);
} catch (Throwable $exception) {
    ajax_json([
        'ok' => false,
        'message' => $exception->getMessage(),
        'entity' => $entity,
        'record_id' => $recordId,
    ], 422);
}

