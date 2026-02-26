<?php
declare(strict_types=1);

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

function permission_sync_run(PDO $pdo, string $projectRoot, array $options = []): array
{
    $assignNewToSuperAdmin = (bool) ($options['assign_super_admin'] ?? true);

    $result = [
        'code_keys' => [],
        'code_key_count' => 0,
        'db_key_count_before' => 0,
        'db_key_count_after' => 0,
        'inserted' => [],
        'assigned' => [],
        'unused_db_keys' => [],
        'errors' => [],
    ];

    if (!permission_sync_table_exists($pdo, 'permissions')) {
        $result['errors'][] = 'permissions table not found.';
        return $result;
    }

    $codeKeys = permission_sync_collect_code_keys($projectRoot);
    $result['code_keys'] = $codeKeys;
    $result['code_key_count'] = count($codeKeys);

    $existingRows = $pdo->query('SELECT id, perm_key FROM permissions')->fetchAll(PDO::FETCH_ASSOC);
    $existingByKey = [];
    foreach ($existingRows as $row) {
        $permKey = strtolower(trim((string) ($row['perm_key'] ?? '')));
        if ($permKey === '') {
            continue;
        }
        $existingByKey[$permKey] = (int) ($row['id'] ?? 0);
    }
    $result['db_key_count_before'] = count($existingByKey);

    $missingKeys = array_values(array_filter($codeKeys, static function (string $permKey) use ($existingByKey): bool {
        return !isset($existingByKey[$permKey]);
    }));

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
            $result['inserted'][$permKey] = $permId;
            $existingByKey[$permKey] = $permId;
        } catch (Throwable $exception) {
            $result['errors'][] = $permKey . ': ' . $exception->getMessage();
        }
    }

    if (
        $assignNewToSuperAdmin
        && $result['inserted'] !== []
        && permission_sync_table_exists($pdo, 'roles')
        && permission_sync_table_exists($pdo, 'role_permissions')
    ) {
        try {
            $rolesStmt = $pdo->query('SELECT id, role_key FROM roles');
            $roles = $rolesStmt ? $rolesStmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($roles as $role) {
                $roleId = (int) ($role['id'] ?? 0);
                $roleKey = strtolower(trim((string) ($role['role_key'] ?? '')));
                if ($roleId <= 0 || $roleKey !== 'super_admin') {
                    continue;
                }

                foreach ($result['inserted'] as $permKey => $permId) {
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
                    $result['assigned'][] = $roleKey . '=>' . $permKey;
                }
            }
        } catch (Throwable $exception) {
            $result['errors'][] = 'role_permissions(super_admin): ' . $exception->getMessage();
        }
    }

    $unusedDbKeys = array_values(array_filter(array_keys($existingByKey), static function (string $permKey) use ($codeKeys): bool {
        return !in_array($permKey, $codeKeys, true);
    }));
    sort($unusedDbKeys);

    $result['unused_db_keys'] = $unusedDbKeys;
    $result['db_key_count_after'] = count($existingByKey);

    return $result;
}

