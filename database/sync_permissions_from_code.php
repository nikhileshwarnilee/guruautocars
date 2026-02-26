<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

function permission_sync_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :table_name');
        $stmt->execute(['table_name' => $table]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $exception) {
        return false;
    }
}

function permission_sync_title_case_key(string $permKey): string
{
    $parts = preg_split('/[._]+/', strtolower(trim($permKey))) ?: [];
    $words = array_values(array_filter(array_map(static function (string $part): string {
        $part = trim($part);
        if ($part === '') {
            return '';
        }
        if (in_array($part, ['gst', 'vis'], true)) {
            return strtoupper($part);
        }
        return ucfirst($part);
    }, $parts)));

    return trim(implode(' ', $words));
}

function permission_sync_display_name(string $permKey): string
{
    $custom = [
        'job.print' => 'Print job cards',
        'job.print.cancelled' => 'Print cancelled job cards',
    ];

    $normalized = strtolower(trim($permKey));
    if (isset($custom[$normalized])) {
        return $custom[$normalized];
    }

    return permission_sync_title_case_key($normalized);
}

function permission_sync_collect_code_keys(string $rootDir): array
{
    $keys = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
            continue;
        }

        $path = str_replace('\\', '/', $fileInfo->getPathname());
        if (!str_ends_with($path, '.php')) {
            continue;
        }
        if (str_contains($path, '/.git/')) {
            continue;
        }

        $source = @file_get_contents($fileInfo->getPathname());
        if (!is_string($source) || $source === '') {
            continue;
        }

        if (preg_match_all('/\b(?:has_permission|require_permission)\s*\(\s*["\']([^"\']+)["\']\s*\)/', $source, $matches)) {
            foreach (($matches[1] ?? []) as $permKey) {
                $permKey = strtolower(trim((string) $permKey));
                if ($permKey !== '') {
                    $keys[$permKey] = true;
                }
            }
        }

        if (preg_match_all('/\bbilling_has_permission\s*\(\s*\[(.*?)\]\s*\)/s', $source, $arrayMatches)) {
            foreach (($arrayMatches[1] ?? []) as $chunk) {
                if (!is_string($chunk) || $chunk === '') {
                    continue;
                }
                if (preg_match_all('/["\']([^"\']+)["\']/', $chunk, $stringMatches)) {
                    foreach (($stringMatches[1] ?? []) as $permKey) {
                        $permKey = strtolower(trim((string) $permKey));
                        if ($permKey !== '') {
                            $keys[$permKey] = true;
                        }
                    }
                }
            }
        }
    }

    ksort($keys);
    return array_keys($keys);
}

$pdo = db();

if (!permission_sync_table_exists($pdo, 'permissions')) {
    fwrite(STDERR, "permissions table not found.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
$codeKeys = permission_sync_collect_code_keys($projectRoot);

$existingRows = $pdo->query('SELECT id, perm_key FROM permissions')->fetchAll(PDO::FETCH_ASSOC);
$existingByKey = [];
foreach ($existingRows as $row) {
    $permKey = strtolower(trim((string) ($row['perm_key'] ?? '')));
    if ($permKey === '') {
        continue;
    }
    $existingByKey[$permKey] = (int) ($row['id'] ?? 0);
}

$missingKeys = array_values(array_filter($codeKeys, static function (string $permKey) use ($existingByKey): bool {
    return !isset($existingByKey[$permKey]);
}));

$inserted = [];
$errors = [];

foreach ($missingKeys as $permKey) {
    try {
        $permName = permission_sync_display_name($permKey);
        table_insert_available_columns('permissions', [
            'perm_key' => $permKey,
            'perm_name' => $permName,
            'status_code' => 'ACTIVE',
        ]);

        $lookupStmt = $pdo->prepare('SELECT id FROM permissions WHERE perm_key = :perm_key LIMIT 1');
        $lookupStmt->execute(['perm_key' => $permKey]);
        $permId = (int) ($lookupStmt->fetchColumn() ?: 0);
        $inserted[$permKey] = $permId;
    } catch (Throwable $exception) {
        $errors[] = $permKey . ': ' . $exception->getMessage();
    }
}

$assigned = [];
if ($inserted !== [] && permission_sync_table_exists($pdo, 'roles') && permission_sync_table_exists($pdo, 'role_permissions')) {
    try {
        $rolesStmt = $pdo->query('SELECT id, role_key FROM roles');
        $roles = $rolesStmt ? $rolesStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($roles as $role) {
            $roleId = (int) ($role['id'] ?? 0);
            $roleKey = strtolower(trim((string) ($role['role_key'] ?? '')));
            if ($roleId <= 0 || $roleKey !== 'super_admin') {
                continue;
            }

            foreach ($inserted as $permKey => $permId) {
                if ($permId <= 0) {
                    continue;
                }
                $mapCheck = $pdo->prepare(
                    'SELECT 1 FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id LIMIT 1'
                );
                $mapCheck->execute([
                    'role_id' => $roleId,
                    'permission_id' => $permId,
                ]);
                if ($mapCheck->fetchColumn()) {
                    continue;
                }

                table_insert_available_columns('role_permissions', [
                    'role_id' => $roleId,
                    'permission_id' => $permId,
                ]);
                $assigned[] = $roleKey . '=>' . $permKey;
            }
        }
    } catch (Throwable $exception) {
        $errors[] = 'role_permissions(super_admin): ' . $exception->getMessage();
    }
}

$unusedDbKeys = array_values(array_filter(array_keys($existingByKey), static function (string $permKey) use ($codeKeys): bool {
    return !in_array($permKey, $codeKeys, true);
}));
sort($unusedDbKeys);

echo "Permission Sync From Code\n";
echo "=========================\n";
echo 'Code permission keys: ' . count($codeKeys) . "\n";
echo 'DB permission keys:   ' . count($existingByKey) . "\n";
echo 'Missing inserted:     ' . count($inserted) . "\n";

if ($inserted !== []) {
    echo "\nInserted permissions:\n";
    foreach ($inserted as $permKey => $permId) {
        echo '- ' . $permKey . ' (id=' . $permId . ')' . "\n";
    }
}

if ($assigned !== []) {
    echo "\nAssigned to roles:\n";
    foreach ($assigned as $assignment) {
        echo '- ' . $assignment . "\n";
    }
}

if ($unusedDbKeys !== []) {
    echo "\nUnused in code (kept in DB):\n";
    foreach ($unusedDbKeys as $permKey) {
        echo '- ' . $permKey . "\n";
    }
}

if ($errors !== []) {
    echo "\nErrors:\n";
    foreach ($errors as $error) {
        echo '- ' . $error . "\n";
    }
    exit(1);
}

echo "\nSync completed successfully.\n";
exit(0);

