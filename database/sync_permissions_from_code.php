<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/permission_sync.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$result = permission_sync_run(db(), dirname(__DIR__), [
    'assign_super_admin' => true,
]);

echo "Permission Sync From Code\n";
echo "=========================\n";
echo 'Code permission keys: ' . (int) ($result['code_key_count'] ?? 0) . "\n";
echo 'DB permission keys:   ' . (int) ($result['db_key_count_before'] ?? 0) . "\n";
echo 'Missing inserted:     ' . count((array) ($result['inserted'] ?? [])) . "\n";

$inserted = (array) ($result['inserted'] ?? []);
if ($inserted !== []) {
    echo "\nInserted permissions:\n";
    foreach ($inserted as $permKey => $permId) {
        echo '- ' . $permKey . ' (id=' . (int) $permId . ')' . "\n";
    }
}

$assigned = array_values((array) ($result['assigned'] ?? []));
if ($assigned !== []) {
    echo "\nAssigned to roles:\n";
    foreach ($assigned as $assignment) {
        echo '- ' . (string) $assignment . "\n";
    }
}

$unusedDbKeys = array_values((array) ($result['unused_db_keys'] ?? []));
if ($unusedDbKeys !== []) {
    echo "\nUnused in code (kept in DB):\n";
    foreach ($unusedDbKeys as $permKey) {
        echo '- ' . (string) $permKey . "\n";
    }
}

$errors = array_values((array) ($result['errors'] ?? []));
if ($errors !== []) {
    echo "\nErrors:\n";
    foreach ($errors as $error) {
        echo '- ' . (string) $error . "\n";
    }
    exit(1);
}

echo "\nSync completed successfully.\n";
exit(0);

