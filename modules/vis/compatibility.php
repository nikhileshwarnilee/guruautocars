<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('vis.view');

$page_title = 'VIS Compatibility Mapping';
$active_menu = 'vis.compatibility';
$canManage = has_permission('vis.manage');
$companyId = active_company_id();
$garageId = active_garage_id();

function vis_mapping_dependency_counts(PDO $pdo, string $entity, int $recordId, int $companyId, int $garageId): array
{
    $jobLinks = 0;
    $activeLinks = 0;
    if ($recordId <= 0) {
        return ['job_links' => 0, 'active_links' => 0];
    }

    $jobScopeSql = ' AND jc.company_id = :company_id AND jc.status_code = "ACTIVE"';
    $jobScopeParams = ['company_id' => $companyId];
    if ($garageId > 0) {
        $jobScopeSql .= ' AND jc.garage_id = :garage_id';
        $jobScopeParams['garage_id'] = $garageId;
    }

    if ($entity === 'compatibility') {
        $mappingStmt = $pdo->prepare(
            'SELECT c.variant_id, c.part_id, c.status_code,
                    COALESCE(p.status_code, "ACTIVE") AS part_status,
                    COALESCE(v.status_code, "ACTIVE") AS variant_status
             FROM vis_part_compatibility c
             LEFT JOIN parts p ON p.id = c.part_id AND p.company_id = c.company_id
             LEFT JOIN vis_variants v ON v.id = c.variant_id
             WHERE c.id = :id
               AND c.company_id = :company_id
             LIMIT 1'
        );
        $mappingStmt->execute(['id' => $recordId, 'company_id' => $companyId]);
        $mapping = $mappingStmt->fetch() ?: null;
        if ($mapping) {
            $activeLinks =
                strtoupper((string) ($mapping['status_code'] ?? 'ACTIVE')) === 'ACTIVE'
                && strtoupper((string) ($mapping['part_status'] ?? 'ACTIVE')) === 'ACTIVE'
                && strtoupper((string) ($mapping['variant_status'] ?? 'ACTIVE')) === 'ACTIVE'
                ? 1
                : 0;
            if (table_columns('vehicle_variants') !== [] && table_columns('vehicles') !== [] && table_columns('job_parts') !== []) {
                $jobStmt = $pdo->prepare(
                    'SELECT COUNT(DISTINCT jc.id)
                     FROM job_cards jc
                     INNER JOIN vehicles v ON v.id = jc.vehicle_id
                     INNER JOIN vehicle_variants vv ON vv.id = v.variant_id
                     INNER JOIN job_parts jp ON jp.job_card_id = jc.id
                     WHERE vv.vis_variant_id = :variant_id
                       AND jp.part_id = :part_id'
                     . $jobScopeSql
                );
                $jobStmt->execute(array_merge([
                    'variant_id' => (int) ($mapping['variant_id'] ?? 0),
                    'part_id' => (int) ($mapping['part_id'] ?? 0),
                ], $jobScopeParams));
                $jobLinks = (int) $jobStmt->fetchColumn();
            }
        }
    } elseif ($entity === 'service_map') {
        $mapStmt = $pdo->prepare(
            'SELECT sm.service_id, sm.part_id, sm.status_code,
                    COALESCE(s.status_code, "ACTIVE") AS service_status,
                    COALESCE(p.status_code, "ACTIVE") AS part_status
             FROM vis_service_part_map sm
             LEFT JOIN services s ON s.id = sm.service_id AND s.company_id = sm.company_id
             LEFT JOIN parts p ON p.id = sm.part_id AND p.company_id = sm.company_id
             WHERE sm.id = :id
               AND sm.company_id = :company_id
             LIMIT 1'
        );
        $mapStmt->execute(['id' => $recordId, 'company_id' => $companyId]);
        $mapping = $mapStmt->fetch() ?: null;
        if ($mapping) {
            $activeLinks =
                strtoupper((string) ($mapping['status_code'] ?? 'ACTIVE')) === 'ACTIVE'
                && strtoupper((string) ($mapping['service_status'] ?? 'ACTIVE')) === 'ACTIVE'
                && strtoupper((string) ($mapping['part_status'] ?? 'ACTIVE')) === 'ACTIVE'
                ? 1
                : 0;
            if (table_columns('job_labor') !== [] && table_columns('job_parts') !== []) {
                $jobStmt = $pdo->prepare(
                    'SELECT COUNT(DISTINCT jc.id)
                     FROM job_cards jc
                     INNER JOIN job_labor jl ON jl.job_card_id = jc.id
                     INNER JOIN job_parts jp ON jp.job_card_id = jc.id
                     WHERE jl.service_id = :service_id
                       AND jp.part_id = :part_id'
                    . $jobScopeSql
                );
                $jobStmt->execute(array_merge([
                    'service_id' => (int) ($mapping['service_id'] ?? 0),
                    'part_id' => (int) ($mapping['part_id'] ?? 0),
                ], $jobScopeParams));
                $jobLinks = (int) $jobStmt->fetchColumn();
            }
        }
    }

    return [
        'job_links' => $jobLinks,
        'active_links' => $activeLinks,
    ];
}

function vis_mapping_resolve_status(PDO $pdo, string $entity, int $recordId, string $requestedStatus, int $companyId, int $garageId): array
{
    $statusCode = normalize_status_code($requestedStatus);
    $result = [
        'status_code' => $statusCode,
        'blocked' => false,
        'job_links' => 0,
        'active_links' => 0,
        'message' => null,
    ];

    if ($statusCode !== 'DELETED' || $recordId <= 0) {
        return $result;
    }

    $counts = vis_mapping_dependency_counts($pdo, $entity, $recordId, $companyId, $garageId);
    $result['job_links'] = (int) ($counts['job_links'] ?? 0);
    $result['active_links'] = (int) ($counts['active_links'] ?? 0);
    if ($result['job_links'] > 0 || $result['active_links'] > 0) {
        $result['status_code'] = 'INACTIVE';
        $result['blocked'] = true;
        $result['message'] = 'Mapping is linked to active records (jobs: ' . $result['job_links'] . ', active part/service links: ' . $result['active_links'] . '). It was disabled instead of deleted.';
    }

    return $result;
}

function vis_mapping_parse_part_ids(mixed $rawPartIds, array $allowedPartLookup): array
{
    $inputs = is_array($rawPartIds) ? $rawPartIds : [$rawPartIds];
    $selectedPartIds = [];
    foreach ($inputs as $rawPartId) {
        $partId = (int) $rawPartId;
        if ($partId <= 0 || !isset($allowedPartLookup[$partId])) {
            continue;
        }
        $selectedPartIds[$partId] = true;
    }

    return array_keys($selectedPartIds);
}

$partsStmt = db()->prepare(
    'SELECT id, part_name, part_sku
     FROM parts
     WHERE company_id = :company_id
       AND status_code = "ACTIVE"
     ORDER BY part_name ASC'
);
$partsStmt->execute(['company_id' => $companyId]);
$parts = $partsStmt->fetchAll();
$partLookup = [];
foreach ($parts as $part) {
    $partId = (int) ($part['id'] ?? 0);
    if ($partId > 0) {
        $partLookup[$partId] = true;
    }
}

$servicesStmt = db()->prepare(
    'SELECT id, service_name, service_code
     FROM services
     WHERE company_id = :company_id
       AND status_code = "ACTIVE"
     ORDER BY service_name ASC'
);
$servicesStmt->execute(['company_id' => $companyId]);
$services = $servicesStmt->fetchAll();

$variants = db()->query(
    'SELECT v.id, v.variant_name, m.model_name, b.brand_name
     FROM vis_variants v
     INNER JOIN vis_models m ON m.id = v.model_id
     INNER JOIN vis_brands b ON b.id = m.brand_id
     WHERE v.status_code = "ACTIVE"
       AND m.status_code = "ACTIVE"
       AND b.status_code = "ACTIVE"
     ORDER BY b.brand_name ASC, m.model_name ASC, v.variant_name ASC'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!$canManage) {
        flash_set('vis_map_error', 'You do not have permission to modify VIS mappings.', 'danger');
        redirect('modules/vis/compatibility.php');
    }

    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'create_compatibility') {
        $variantId = post_int('variant_id');
        $partIds = vis_mapping_parse_part_ids($_POST['part_ids'] ?? ($_POST['part_id'] ?? []), $partLookup);
        $note = post_string('compatibility_note', 255);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($variantId <= 0 || $partIds === []) {
            flash_set('vis_map_error', 'Variant and at least one valid part are required for compatibility mapping.', 'danger');
            redirect('modules/vis/compatibility.php');
        }

        $pdo = db();
        try {
            $params = [
                'company_id' => $companyId,
                'variant_id' => $variantId,
            ];
            $placeholders = [];
            foreach ($partIds as $index => $partId) {
                $paramName = 'part_id_' . $index;
                $placeholders[] = ':' . $paramName;
                $params[$paramName] = $partId;
            }

            $existingStmt = $pdo->prepare(
                'SELECT part_id
                 FROM vis_part_compatibility
                 WHERE company_id = :company_id
                   AND variant_id = :variant_id
                   AND part_id IN (' . implode(', ', $placeholders) . ')'
            );
            $existingStmt->execute($params);
            $existingPartLookup = [];
            foreach ($existingStmt->fetchAll() as $existingRow) {
                $existingPartId = (int) ($existingRow['part_id'] ?? 0);
                if ($existingPartId > 0) {
                    $existingPartLookup[$existingPartId] = true;
                }
            }

            $insertStmt = $pdo->prepare(
                'INSERT INTO vis_part_compatibility
                  (company_id, variant_id, part_id, compatibility_note, status_code, deleted_at)
                 VALUES
                  (:company_id, :variant_id, :part_id, :compatibility_note, :status_code, :deleted_at)'
            );
            $createdCount = 0;
            $skippedCount = 0;
            $firstInsertId = 0;
            $pdo->beginTransaction();
            foreach ($partIds as $partId) {
                if (isset($existingPartLookup[(int) $partId])) {
                    $skippedCount++;
                    continue;
                }

                $insertStmt->execute([
                    'company_id' => $companyId,
                    'variant_id' => $variantId,
                    'part_id' => $partId,
                    'compatibility_note' => $note !== '' ? $note : null,
                    'status_code' => $statusCode,
                    'deleted_at' => $statusCode === 'DELETED' ? date('Y-m-d H:i:s') : null,
                ]);
                $createdCount++;
                if ($firstInsertId === 0) {
                    $firstInsertId = (int) $pdo->lastInsertId();
                }
            }
            $pdo->commit();

            if ($createdCount > 0) {
                log_audit('vis_mapping', 'create_part_compatibility', $firstInsertId, 'Created VIS part compatibility mapping', [
                    'entity' => 'vis_part_compatibility',
                    'source' => 'UI',
                    'after' => [
                        'variant_id' => $variantId,
                        'created_count' => $createdCount,
                        'status_code' => $statusCode,
                    ],
                    'metadata' => [
                        'part_ids' => $partIds,
                        'skipped_duplicates' => $skippedCount,
                    ],
                ]);
                flash_set('vis_map_success', 'Part compatibility mappings created for ' . $createdCount . ' part(s).', 'success');
            } elseif ($skippedCount > 0) {
                flash_set('vis_map_warning', 'No new compatibility mappings were created because selected parts are already mapped to this variant.', 'warning');
            }

            if ($createdCount > 0 && $skippedCount > 0) {
                flash_set('vis_map_warning', 'Skipped ' . $skippedCount . ' part(s) already mapped to this variant.', 'warning');
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash_set('vis_map_error', 'Unable to create compatibility mappings.', 'danger');
        }

        redirect('modules/vis/compatibility.php');
    }

    if ($action === 'update_compatibility') {
        $mappingId = post_int('mapping_id');
        $note = post_string('compatibility_note', 255);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));
        $statusMeta = vis_mapping_resolve_status(db(), 'compatibility', $mappingId, $statusCode, $companyId, $garageId);
        $resolvedStatus = (string) ($statusMeta['status_code'] ?? $statusCode);

        $stmt = db()->prepare(
            'UPDATE vis_part_compatibility
             SET compatibility_note = :compatibility_note,
                 status_code = :status_code,
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'compatibility_note' => $note !== '' ? $note : null,
            'status_code' => $resolvedStatus,
            'id' => $mappingId,
            'company_id' => $companyId,
        ]);

        log_audit('vis_mapping', 'update_part_compatibility', $mappingId, 'Updated VIS part compatibility mapping', [
            'entity' => 'vis_part_compatibility',
            'source' => 'UI',
            'metadata' => [
                'requested_status' => $statusCode,
                'applied_status' => $resolvedStatus,
                'job_links' => (int) ($statusMeta['job_links'] ?? 0),
            ],
        ]);
        flash_set('vis_map_success', 'Part compatibility mapping updated.', 'success');
        if (($statusMeta['blocked'] ?? false) && !empty($statusMeta['message'])) {
            flash_set('vis_map_warning', (string) $statusMeta['message'], 'warning');
        }
        redirect('modules/vis/compatibility.php');
    }

    if ($action === 'create_service_part_map') {
        $serviceId = post_int('service_id');
        $partIds = vis_mapping_parse_part_ids($_POST['part_ids'] ?? ($_POST['part_id'] ?? []), $partLookup);
        $isRequired = post_int('is_required', 1) === 1 ? 1 : 0;
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($serviceId <= 0 || $partIds === []) {
            flash_set('vis_map_error', 'Service and at least one valid part are required for service-to-part mapping.', 'danger');
            redirect('modules/vis/compatibility.php');
        }

        $pdo = db();
        try {
            $params = [
                'company_id' => $companyId,
                'service_id' => $serviceId,
            ];
            $placeholders = [];
            foreach ($partIds as $index => $partId) {
                $paramName = 'part_id_' . $index;
                $placeholders[] = ':' . $paramName;
                $params[$paramName] = $partId;
            }

            $existingStmt = $pdo->prepare(
                'SELECT part_id
                 FROM vis_service_part_map
                 WHERE company_id = :company_id
                   AND service_id = :service_id
                   AND part_id IN (' . implode(', ', $placeholders) . ')'
            );
            $existingStmt->execute($params);
            $existingPartLookup = [];
            foreach ($existingStmt->fetchAll() as $existingRow) {
                $existingPartId = (int) ($existingRow['part_id'] ?? 0);
                if ($existingPartId > 0) {
                    $existingPartLookup[$existingPartId] = true;
                }
            }

            $insertStmt = $pdo->prepare(
                'INSERT INTO vis_service_part_map
                  (company_id, service_id, part_id, is_required, status_code, deleted_at)
                 VALUES
                  (:company_id, :service_id, :part_id, :is_required, :status_code, :deleted_at)'
            );
            $createdCount = 0;
            $skippedCount = 0;
            $firstInsertId = 0;
            $pdo->beginTransaction();
            foreach ($partIds as $partId) {
                if (isset($existingPartLookup[(int) $partId])) {
                    $skippedCount++;
                    continue;
                }

                $insertStmt->execute([
                    'company_id' => $companyId,
                    'service_id' => $serviceId,
                    'part_id' => $partId,
                    'is_required' => $isRequired,
                    'status_code' => $statusCode,
                    'deleted_at' => $statusCode === 'DELETED' ? date('Y-m-d H:i:s') : null,
                ]);
                $createdCount++;
                if ($firstInsertId === 0) {
                    $firstInsertId = (int) $pdo->lastInsertId();
                }
            }
            $pdo->commit();

            if ($createdCount > 0) {
                log_audit('vis_mapping', 'create_service_part_map', $firstInsertId, 'Created VIS service-to-part mapping', [
                    'entity' => 'vis_service_part_map',
                    'source' => 'UI',
                    'after' => [
                        'service_id' => $serviceId,
                        'created_count' => $createdCount,
                        'is_required' => $isRequired,
                        'status_code' => $statusCode,
                    ],
                    'metadata' => [
                        'part_ids' => $partIds,
                        'skipped_duplicates' => $skippedCount,
                    ],
                ]);
                flash_set('vis_map_success', 'Service-to-part mappings created for ' . $createdCount . ' part(s).', 'success');
            } elseif ($skippedCount > 0) {
                flash_set('vis_map_warning', 'No new service-to-part mappings were created because selected parts are already mapped to this service.', 'warning');
            }

            if ($createdCount > 0 && $skippedCount > 0) {
                flash_set('vis_map_warning', 'Skipped ' . $skippedCount . ' part(s) already mapped to this service.', 'warning');
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash_set('vis_map_error', 'Unable to create service mappings.', 'danger');
        }

        redirect('modules/vis/compatibility.php');
    }

    if ($action === 'update_service_part_map') {
        $serviceMapId = post_int('service_map_id');
        $isRequired = post_int('is_required', 1) === 1 ? 1 : 0;
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));
        $statusMeta = vis_mapping_resolve_status(db(), 'service_map', $serviceMapId, $statusCode, $companyId, $garageId);
        $resolvedStatus = (string) ($statusMeta['status_code'] ?? $statusCode);

        $stmt = db()->prepare(
            'UPDATE vis_service_part_map
             SET is_required = :is_required,
                 status_code = :status_code,
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'is_required' => $isRequired,
            'status_code' => $resolvedStatus,
            'id' => $serviceMapId,
            'company_id' => $companyId,
        ]);

        log_audit('vis_mapping', 'update_service_part_map', $serviceMapId, 'Updated VIS service-to-part mapping', [
            'entity' => 'vis_service_part_map',
            'source' => 'UI',
            'metadata' => [
                'requested_status' => $statusCode,
                'applied_status' => $resolvedStatus,
                'job_links' => (int) ($statusMeta['job_links'] ?? 0),
            ],
        ]);
        flash_set('vis_map_success', 'Service-to-part mapping updated.', 'success');
        if (($statusMeta['blocked'] ?? false) && !empty($statusMeta['message'])) {
            flash_set('vis_map_warning', (string) $statusMeta['message'], 'warning');
        }
        redirect('modules/vis/compatibility.php');
    }

    if ($action === 'change_status') {
        $entity = (string) ($_POST['entity'] ?? '');
        $recordId = post_int('record_id');
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));
        $safeDeleteValidation = null;
        $safeDeleteEntityMap = [
            'compatibility' => 'vis_part_compatibility_map',
            'service_map' => 'vis_service_part_map',
        ];
        $safeDeleteEntity = (string) ($safeDeleteEntityMap[$entity] ?? '');
        if (!in_array($entity, ['compatibility', 'service_map'], true) || $recordId <= 0) {
            flash_set('vis_map_error', 'Invalid mapping status request.', 'danger');
            redirect('modules/vis/compatibility.php');
        }
        if ($nextStatus === 'DELETED') {
            if ($safeDeleteEntity === '') {
                flash_set('vis_map_error', 'Safe delete mapping is not configured for this VIS entity.', 'danger');
                redirect('modules/vis/compatibility.php');
            }
            $safeDeleteValidation = safe_delete_validate_post_confirmation($safeDeleteEntity, $recordId, [
                'operation' => 'delete',
                'reason_field' => 'deletion_reason',
            ]);
        }
        $statusMeta = vis_mapping_resolve_status(db(), $entity, $recordId, $nextStatus, $companyId, $garageId);
        $resolvedStatus = (string) ($statusMeta['status_code'] ?? $nextStatus);

        if ($entity === 'compatibility') {
            $stmt = db()->prepare('UPDATE vis_part_compatibility SET status_code = :status_code, deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END WHERE id = :id AND company_id = :company_id');
            $stmt->execute([
                'status_code' => $resolvedStatus,
                'id' => $recordId,
                'company_id' => $companyId,
            ]);
            log_audit('vis_mapping', 'status_part_compatibility', $recordId, 'Changed compatibility status to ' . $resolvedStatus, [
                'entity' => 'vis_part_compatibility',
                'source' => 'UI',
                'metadata' => [
                    'requested_status' => $nextStatus,
                    'applied_status' => $resolvedStatus,
                    'job_links' => (int) ($statusMeta['job_links'] ?? 0),
                    'active_links' => (int) ($statusMeta['active_links'] ?? 0),
                ],
            ]);
        }

        if ($entity === 'service_map') {
            $stmt = db()->prepare('UPDATE vis_service_part_map SET status_code = :status_code, deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END WHERE id = :id AND company_id = :company_id');
            $stmt->execute([
                'status_code' => $resolvedStatus,
                'id' => $recordId,
                'company_id' => $companyId,
            ]);
            log_audit('vis_mapping', 'status_service_part_map', $recordId, 'Changed service map status to ' . $resolvedStatus, [
                'entity' => 'vis_service_part_map',
                'source' => 'UI',
                'metadata' => [
                    'requested_status' => $nextStatus,
                    'applied_status' => $resolvedStatus,
                    'job_links' => (int) ($statusMeta['job_links'] ?? 0),
                    'active_links' => (int) ($statusMeta['active_links'] ?? 0),
                ],
            ]);
        }

        if ($nextStatus === 'DELETED' && is_array($safeDeleteValidation) && $safeDeleteEntity !== '') {
            safe_delete_log_cascade($safeDeleteEntity, 'delete', $recordId, $safeDeleteValidation, [
                'metadata' => [
                    'entity' => $entity,
                    'company_id' => $companyId,
                    'garage_id' => $garageId,
                    'requested_status' => $nextStatus,
                    'applied_status' => $resolvedStatus,
                    'job_links' => (int) ($statusMeta['job_links'] ?? 0),
                    'active_links' => (int) ($statusMeta['active_links'] ?? 0),
                ],
            ]);
        }

        flash_set('vis_map_success', 'Mapping status updated.', 'success');
        if (($statusMeta['blocked'] ?? false) && !empty($statusMeta['message'])) {
            flash_set('vis_map_warning', (string) $statusMeta['message'], 'warning');
        }
        redirect('modules/vis/compatibility.php');
    }
}

$editCompatibilityId = get_int('edit_compatibility_id');
$editCompatibility = null;
if ($editCompatibilityId > 0) {
    $editCompatibilityStmt = db()->prepare(
        'SELECT *
         FROM vis_part_compatibility
         WHERE id = :id
           AND company_id = :company_id
         LIMIT 1'
    );
    $editCompatibilityStmt->execute([
        'id' => $editCompatibilityId,
        'company_id' => $companyId,
    ]);
    $editCompatibility = $editCompatibilityStmt->fetch() ?: null;
}

$editServiceMapId = get_int('edit_service_map_id');
$editServiceMap = null;
if ($editServiceMapId > 0) {
    $editServiceMapStmt = db()->prepare(
        'SELECT *
         FROM vis_service_part_map
         WHERE id = :id
           AND company_id = :company_id
         LIMIT 1'
    );
    $editServiceMapStmt->execute([
        'id' => $editServiceMapId,
        'company_id' => $companyId,
    ]);
    $editServiceMap = $editServiceMapStmt->fetch() ?: null;
}

$compatibilityList = db()->prepare(
    'SELECT c.*, v.variant_name, m.model_name, b.brand_name, p.part_name, p.part_sku
     FROM vis_part_compatibility c
     INNER JOIN vis_variants v ON v.id = c.variant_id
     INNER JOIN vis_models m ON m.id = v.model_id
     INNER JOIN vis_brands b ON b.id = m.brand_id
     INNER JOIN parts p ON p.id = c.part_id
     WHERE c.company_id = :company_id
     ORDER BY c.id DESC'
);
$compatibilityList->execute(['company_id' => $companyId]);
$compatibilities = $compatibilityList->fetchAll();

$servicePartMapList = db()->prepare(
    'SELECT sm.*, s.service_name, s.service_code, p.part_name, p.part_sku
     FROM vis_service_part_map sm
     INNER JOIN services s ON s.id = sm.service_id
     INNER JOIN parts p ON p.id = sm.part_id
     WHERE sm.company_id = :company_id
     ORDER BY sm.id DESC'
);
$servicePartMapList->execute(['company_id' => $companyId]);
$serviceMappings = $servicePartMapList->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">VIS Compatibility Mapping</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">VIS Mapping</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if (!$canManage): ?>
        <div class="alert alert-info">VIS mapping is in read-only mode for your role.</div>
      <?php endif; ?>

      <?php if ($canManage): ?>
        <div class="row g-3">
          <div class="col-lg-6">
            <div class="card card-primary">
              <div class="card-header"><h3 class="card-title"><?= $editCompatibility ? 'Edit Part Compatibility' : 'Map Part Compatibility'; ?></h3></div>
              <form method="post">
                <div class="card-body row g-2">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="<?= $editCompatibility ? 'update_compatibility' : 'create_compatibility'; ?>" />
                  <input type="hidden" name="mapping_id" value="<?= (int) ($editCompatibility['id'] ?? 0); ?>" />

                  <div class="col-md-6">
                    <label class="form-label">Vehicle Variant</label>
                    <select name="variant_id" class="form-select" required <?= $editCompatibility ? 'disabled' : ''; ?>>
                      <option value="">Select Variant</option>
                      <?php foreach ($variants as $variant): ?>
                        <option value="<?= (int) $variant['id']; ?>" <?= ((int) ($editCompatibility['variant_id'] ?? 0) === (int) $variant['id']) ? 'selected' : ''; ?>>
                          <?= e((string) $variant['brand_name']); ?> / <?= e((string) $variant['model_name']); ?> / <?= e((string) $variant['variant_name']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <?php if ($editCompatibility): ?><input type="hidden" name="variant_id" value="<?= (int) $editCompatibility['variant_id']; ?>" /><?php endif; ?>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Part</label>
                    <?php if ($editCompatibility): ?>
                      <select name="part_id" class="form-select" required disabled>
                        <option value="">Select Part</option>
                        <?php foreach ($parts as $part): ?>
                          <option value="<?= (int) $part['id']; ?>" <?= ((int) ($editCompatibility['part_id'] ?? 0) === (int) $part['id']) ? 'selected' : ''; ?>>
                            <?= e((string) $part['part_name']); ?> (<?= e((string) $part['part_sku']); ?>)
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <input type="hidden" name="part_id" value="<?= (int) $editCompatibility['part_id']; ?>" />
                    <?php else: ?>
                      <select name="part_ids[]" class="form-select" required multiple size="8">
                        <?php foreach ($parts as $part): ?>
                          <option value="<?= (int) $part['id']; ?>">
                            <?= e((string) $part['part_name']); ?> (<?= e((string) $part['part_sku']); ?>)
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <small class="text-muted">Hold Ctrl (Windows) or Cmd (Mac) to select multiple parts.</small>
                    <?php endif; ?>
                  </div>

                  <div class="col-md-8">
                    <label class="form-label">Compatibility Note</label>
                    <input type="text" name="compatibility_note" class="form-control" value="<?= e((string) ($editCompatibility['compatibility_note'] ?? '')); ?>" />
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select name="status_code" class="form-select">
                      <?php foreach (status_options((string) ($editCompatibility['status_code'] ?? 'ACTIVE')) as $option): ?>
                        <option value="<?= e($option['value']); ?>" <?= $option['selected'] ? 'selected' : ''; ?>><?= e($option['value']); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="card-footer d-flex gap-2">
                  <button type="submit" class="btn btn-primary"><?= $editCompatibility ? 'Update Mapping' : 'Create Mapping'; ?></button>
                  <?php if ($editCompatibility): ?>
                    <a href="<?= e(url('modules/vis/compatibility.php')); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
                  <?php endif; ?>
                </div>
              </form>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="card card-info">
              <div class="card-header"><h3 class="card-title"><?= $editServiceMap ? 'Edit Service-to-Part Mapping' : 'Map Service-to-Part'; ?></h3></div>
              <form method="post">
                <div class="card-body row g-2">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="<?= $editServiceMap ? 'update_service_part_map' : 'create_service_part_map'; ?>" />
                  <input type="hidden" name="service_map_id" value="<?= (int) ($editServiceMap['id'] ?? 0); ?>" />

                  <div class="col-md-6">
                    <label class="form-label">Service</label>
                    <select name="service_id" class="form-select" required <?= $editServiceMap ? 'disabled' : ''; ?>>
                      <option value="">Select Service</option>
                      <?php foreach ($services as $service): ?>
                        <option value="<?= (int) $service['id']; ?>" <?= ((int) ($editServiceMap['service_id'] ?? 0) === (int) $service['id']) ? 'selected' : ''; ?>>
                          <?= e((string) $service['service_name']); ?> (<?= e((string) $service['service_code']); ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <?php if ($editServiceMap): ?><input type="hidden" name="service_id" value="<?= (int) $editServiceMap['service_id']; ?>" /><?php endif; ?>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Part</label>
                    <?php if ($editServiceMap): ?>
                      <select name="part_id" class="form-select" required disabled>
                        <option value="">Select Part</option>
                        <?php foreach ($parts as $part): ?>
                          <option value="<?= (int) $part['id']; ?>" <?= ((int) ($editServiceMap['part_id'] ?? 0) === (int) $part['id']) ? 'selected' : ''; ?>>
                            <?= e((string) $part['part_name']); ?> (<?= e((string) $part['part_sku']); ?>)
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <input type="hidden" name="part_id" value="<?= (int) $editServiceMap['part_id']; ?>" />
                    <?php else: ?>
                      <select name="part_ids[]" class="form-select" required multiple size="8">
                        <?php foreach ($parts as $part): ?>
                          <option value="<?= (int) $part['id']; ?>">
                            <?= e((string) $part['part_name']); ?> (<?= e((string) $part['part_sku']); ?>)
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <small class="text-muted">Hold Ctrl (Windows) or Cmd (Mac) to select multiple parts.</small>
                    <?php endif; ?>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Required?</label>
                    <select name="is_required" class="form-select">
                      <option value="1" <?= ((int) ($editServiceMap['is_required'] ?? 1) === 1) ? 'selected' : ''; ?>>Required</option>
                      <option value="0" <?= ((int) ($editServiceMap['is_required'] ?? 1) === 0) ? 'selected' : ''; ?>>Optional</option>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select name="status_code" class="form-select">
                      <?php foreach (status_options((string) ($editServiceMap['status_code'] ?? 'ACTIVE')) as $option): ?>
                        <option value="<?= e($option['value']); ?>" <?= $option['selected'] ? 'selected' : ''; ?>><?= e($option['value']); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="card-footer d-flex gap-2">
                  <button type="submit" class="btn btn-info"><?= $editServiceMap ? 'Update Mapping' : 'Create Mapping'; ?></button>
                  <?php if ($editServiceMap): ?>
                    <a href="<?= e(url('modules/vis/compatibility.php')); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
                  <?php endif; ?>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="card mt-3">
        <div class="card-header"><h3 class="card-title">Part Compatibility Mapping</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>Variant</th>
                <th>Part</th>
                <th>Note</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($compatibilities)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No compatibility mappings.</td></tr>
              <?php else: ?>
                <?php foreach ($compatibilities as $compatibility): ?>
                  <tr>
                    <td><?= e((string) $compatibility['brand_name']); ?> / <?= e((string) $compatibility['model_name']); ?> / <?= e((string) $compatibility['variant_name']); ?></td>
                    <td><?= e((string) $compatibility['part_name']); ?> (<?= e((string) $compatibility['part_sku']); ?>)</td>
                    <td><?= e((string) ($compatibility['compatibility_note'] ?? '-')); ?></td>
                    <td><span class="badge text-bg-<?= e(status_badge_class((string) $compatibility['status_code'])); ?>"><?= e((string) $compatibility['status_code']); ?></span></td>
                    <td class="d-flex gap-1">
                      <?php if ($canManage): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/vis/compatibility.php?edit_compatibility_id=' . (int) $compatibility['id'])); ?>">Edit</a>
                        <button
                          type="button"
                          class="btn btn-sm btn-outline-secondary js-vis-map-status-btn"
                          data-bs-toggle="modal"
                          data-bs-target="#visMapStatusModal"
                          data-entity="compatibility"
                          data-record-id="<?= (int) $compatibility['id']; ?>"
                          data-next-status="<?= e(((string) $compatibility['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>"
                          data-record-label="Compatibility: <?= e((string) $compatibility['brand_name']); ?> / <?= e((string) $compatibility['model_name']); ?> / <?= e((string) $compatibility['variant_name']); ?> / <?= e((string) $compatibility['part_name']); ?>"
                        >Toggle</button>
                        <?php if ((string) ($compatibility['status_code'] ?? '') !== 'DELETED'): ?>
                          <button
                            type="button"
                            class="btn btn-sm btn-outline-danger js-vis-map-delete-btn"
                            data-bs-toggle="modal"
                            data-bs-target="#visMapDeleteModal"
                            data-entity="compatibility"
                            data-record-id="<?= (int) $compatibility['id']; ?>"
                            data-record-label="Compatibility: <?= e((string) $compatibility['brand_name']); ?> / <?= e((string) $compatibility['model_name']); ?> / <?= e((string) $compatibility['variant_name']); ?> / <?= e((string) $compatibility['part_name']); ?>"
                          >Delete</button>
                        <?php endif; ?>
                      <?php else: ?>
                        <span class="text-muted">-</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header"><h3 class="card-title">Service-to-Part Mapping</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>Service</th>
                <th>Part</th>
                <th>Required</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($serviceMappings)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No service-to-part mappings.</td></tr>
              <?php else: ?>
                <?php foreach ($serviceMappings as $mapping): ?>
                  <tr>
                    <td><?= e((string) $mapping['service_name']); ?> (<?= e((string) $mapping['service_code']); ?>)</td>
                    <td><?= e((string) $mapping['part_name']); ?> (<?= e((string) $mapping['part_sku']); ?>)</td>
                    <td><?= ((int) $mapping['is_required'] === 1) ? 'Yes' : 'No'; ?></td>
                    <td><span class="badge text-bg-<?= e(status_badge_class((string) $mapping['status_code'])); ?>"><?= e((string) $mapping['status_code']); ?></span></td>
                    <td class="d-flex gap-1">
                      <?php if ($canManage): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/vis/compatibility.php?edit_service_map_id=' . (int) $mapping['id'])); ?>">Edit</a>
                        <button
                          type="button"
                          class="btn btn-sm btn-outline-secondary js-vis-map-status-btn"
                          data-bs-toggle="modal"
                          data-bs-target="#visMapStatusModal"
                          data-entity="service_map"
                          data-record-id="<?= (int) $mapping['id']; ?>"
                          data-next-status="<?= e(((string) $mapping['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>"
                          data-record-label="Service Map: <?= e((string) $mapping['service_name']); ?> / <?= e((string) $mapping['part_name']); ?>"
                        >Toggle</button>
                        <?php if ((string) ($mapping['status_code'] ?? '') !== 'DELETED'): ?>
                          <button
                            type="button"
                            class="btn btn-sm btn-outline-danger js-vis-map-delete-btn"
                            data-bs-toggle="modal"
                            data-bs-target="#visMapDeleteModal"
                            data-entity="service_map"
                            data-record-id="<?= (int) $mapping['id']; ?>"
                            data-record-label="Service Map: <?= e((string) $mapping['service_name']); ?> / <?= e((string) $mapping['part_name']); ?>"
                          >Delete</button>
                        <?php endif; ?>
                      <?php else: ?>
                        <span class="text-muted">-</span>
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

<div class="modal fade" id="visMapStatusModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post"
            data-safe-delete
            data-safe-delete-entity-field="safe_delete_entity"
            data-safe-delete-record-field="record_id"
            data-safe-delete-operation="delete"
            data-safe-delete-reason-field="deletion_reason">
        <div class="modal-header bg-warning-subtle">
          <h5 class="modal-title">Change Mapping Status</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?= csrf_field(); ?>
          <input type="hidden" name="_action" value="change_status" />
          <input type="hidden" name="entity" id="vis-map-status-entity" />
          <input type="hidden" name="record_id" id="vis-map-status-record-id" />
          <input type="hidden" name="next_status" id="vis-map-status-next" />
          <div class="mb-3">
            <label class="form-label">Mapping</label>
            <input type="text" id="vis-map-status-label" class="form-control" readonly />
          </div>
          <div class="mb-0">
            <label class="form-label">Next Status</label>
            <input type="text" id="vis-map-status-next-label" class="form-control" readonly />
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-warning">Confirm Status Change</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="visMapDeleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header bg-danger-subtle">
          <h5 class="modal-title">Delete Mapping</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?= csrf_field(); ?>
          <input type="hidden" name="_action" value="change_status" />
          <input type="hidden" name="entity" id="vis-map-delete-entity" />
          <input type="hidden" name="safe_delete_entity" id="vis-map-delete-safe-entity" />
          <input type="hidden" name="record_id" id="vis-map-delete-record-id" />
          <input type="hidden" name="next_status" value="DELETED" />
          <div class="mb-3">
            <label class="form-label">Mapping</label>
            <input type="text" id="vis-map-delete-label" class="form-control" readonly />
          </div>
          <div class="alert alert-warning mb-0">
            If active part/service or job links exist, this mapping will be safely disabled instead of deleted.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-danger">Confirm Delete</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  (function () {
    function setValue(id, value) {
      var field = document.getElementById(id);
      if (!field) {
        return;
      }
      field.value = value || '';
    }

    document.addEventListener('click', function (event) {
      var statusTrigger = event.target.closest('.js-vis-map-status-btn');
      if (statusTrigger) {
        var nextStatus = statusTrigger.getAttribute('data-next-status') || '';
        setValue('vis-map-status-entity', statusTrigger.getAttribute('data-entity'));
        setValue('vis-map-status-record-id', statusTrigger.getAttribute('data-record-id'));
        setValue('vis-map-status-next', nextStatus);
        setValue('vis-map-status-label', statusTrigger.getAttribute('data-record-label'));
        setValue('vis-map-status-next-label', nextStatus);
      }

      var deleteTrigger = event.target.closest('.js-vis-map-delete-btn');
      if (deleteTrigger) {
        var deleteEntity = deleteTrigger.getAttribute('data-entity') || '';
        setValue('vis-map-delete-entity', deleteEntity);
        setValue('vis-map-delete-safe-entity', deleteEntity === 'compatibility' ? 'vis_part_compatibility_map' : (deleteEntity === 'service_map' ? 'vis_service_part_map' : ''));
        setValue('vis-map-delete-record-id', deleteTrigger.getAttribute('data-record-id'));
        setValue('vis-map-delete-label', deleteTrigger.getAttribute('data-record-label'));
      }
    });
  })();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
