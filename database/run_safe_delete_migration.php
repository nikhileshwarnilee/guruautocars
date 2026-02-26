<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

function migration_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :table_name');
        $stmt->execute(['table_name' => $table]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $exception) {
        return false;
    }
}

function migration_add_column_if_missing(PDO $pdo, string $table, string $column, string $sqlType): bool
{
    $columns = table_columns($table);
    if ($columns === [] || in_array($column, $columns, true)) {
        return false;
    }

    $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $sqlType);
    return true;
}

$pdo = db();
$targetTables = [
    'job_cards',
    'invoices',
    'payments',
    'purchase_payments',
    'purchases',
    'returns_rma',
    'return_settlements',
    'job_advances',
    'customers',
    'vehicles',
    'vendors',
    'expenses',
    'inventory_movements',
    'credit_notes',
    'payroll_advances',
    'payroll_loans',
    'payroll_salary_items',
    'payroll_salary_payments',
    'payroll_loan_payments',
    'insurance_claims',
];

$changes = [];
$errors = [];

foreach ($targetTables as $table) {
    if (!migration_table_exists($pdo, $table)) {
        continue;
    }

    try {
        $tableChanges = [];
        if (migration_add_column_if_missing($pdo, $table, 'deleted_at', 'DATETIME NULL DEFAULT NULL')) {
            $tableChanges[] = 'deleted_at';
        }
        if (migration_add_column_if_missing($pdo, $table, 'deleted_by', 'BIGINT UNSIGNED NULL DEFAULT NULL')) {
            $tableChanges[] = 'deleted_by';
        }
        if (migration_add_column_if_missing($pdo, $table, 'deletion_reason', 'VARCHAR(255) NULL DEFAULT NULL')) {
            $tableChanges[] = 'deletion_reason';
        }
        if ($tableChanges !== []) {
            $changes[$table] = $tableChanges;
        }

        $columnsAfter = table_columns($table);
        if (in_array('deleted_at', $columnsAfter, true)) {
            $statusColumn = null;
            if (in_array('status_code', $columnsAfter, true)) {
                $statusColumn = 'status_code';
            } elseif (in_array('status', $columnsAfter, true)) {
                $statusColumn = 'status';
            }

            if ($statusColumn !== null) {
                $backfillStmt = $pdo->exec(
                    'UPDATE `' . $table . '`
                     SET `deleted_at` = COALESCE(`deleted_at`, NOW())
                     WHERE `' . $statusColumn . '` = "DELETED"
                       AND `deleted_at` IS NULL'
                );
                if (is_int($backfillStmt) && $backfillStmt > 0) {
                    $changes[$table][] = 'deleted_at_backfilled:' . $backfillStmt;
                }
            }
        }
    } catch (Throwable $exception) {
        $errors[] = $table . ': ' . $exception->getMessage();
    }
}

if (migration_table_exists($pdo, 'permissions')) {
    foreach ([
        ['perm_key' => 'record.delete', 'perm_name' => 'Global Safe Record Delete'],
        ['perm_key' => 'financial.reverse', 'perm_name' => 'Financial Reversal & Safe Delete'],
        ['perm_key' => 'dependency.resolve', 'perm_name' => 'Resolve Dependencies From Delete Summary'],
    ] as $permissionRow) {
        try {
            $existsStmt = $pdo->prepare('SELECT id FROM permissions WHERE perm_key = :perm_key LIMIT 1');
            $existsStmt->execute(['perm_key' => $permissionRow['perm_key']]);
            if ($existsStmt->fetchColumn()) {
                continue;
            }
            table_insert_available_columns('permissions', [
                'perm_key' => $permissionRow['perm_key'],
                'perm_name' => $permissionRow['perm_name'],
                'status_code' => 'ACTIVE',
            ]);
            $changes['permissions'][] = $permissionRow['perm_key'];
        } catch (Throwable $exception) {
            $errors[] = 'permissions:' . $permissionRow['perm_key'] . ': ' . $exception->getMessage();
        }
    }

    if (migration_table_exists($pdo, 'roles') && migration_table_exists($pdo, 'role_permissions')) {
        try {
            $permStmt = $pdo->prepare('SELECT id FROM permissions WHERE perm_key = :perm_key LIMIT 1');
            $permStmt->execute(['perm_key' => 'dependency.resolve']);
            $dependencyResolvePermId = (int) ($permStmt->fetchColumn() ?: 0);

            if ($dependencyResolvePermId > 0) {
                $rolesStmt = $pdo->query('SELECT id, role_key FROM roles');
                $roles = $rolesStmt ? $rolesStmt->fetchAll() : [];
                foreach ($roles as $roleRow) {
                    $roleId = (int) ($roleRow['id'] ?? 0);
                    $roleKey = strtolower(trim((string) ($roleRow['role_key'] ?? '')));
                    if ($roleId <= 0 || !in_array($roleKey, ['super_admin', 'admin'], true)) {
                        continue;
                    }

                    $mapCheck = $pdo->prepare(
                        'SELECT 1
                         FROM role_permissions
                         WHERE role_id = :role_id
                           AND permission_id = :permission_id
                         LIMIT 1'
                    );
                    $mapCheck->execute([
                        'role_id' => $roleId,
                        'permission_id' => $dependencyResolvePermId,
                    ]);
                    if ($mapCheck->fetchColumn()) {
                        continue;
                    }

                    table_insert_available_columns('role_permissions', [
                        'role_id' => $roleId,
                        'permission_id' => $dependencyResolvePermId,
                    ]);
                    $changes['role_permissions'][] = $roleKey . '=>dependency.resolve';
                }
            }
        } catch (Throwable $exception) {
            $errors[] = 'role_permissions:dependency.resolve: ' . $exception->getMessage();
        }
    }
}

echo "Global Safe Delete Migration\n";
echo "===========================\n";
if ($changes === []) {
    echo "No schema/permission changes were needed.\n";
} else {
    foreach ($changes as $table => $tableChanges) {
        echo '[UPDATED] ' . $table . ': ' . implode(', ', array_values(array_unique($tableChanges))) . "\n";
    }
}

if ($errors !== []) {
    echo "\nErrors:\n";
    foreach ($errors as $error) {
        echo '- ' . $error . "\n";
    }
    exit(1);
}

echo "\nMigration completed successfully.\n";
exit(0);
