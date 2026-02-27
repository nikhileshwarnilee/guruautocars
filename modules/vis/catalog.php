<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('vis.view');
require_once __DIR__ . '/../jobs/workflow.php';

$page_title = 'VIS Vehicle Catalog';
$active_menu = 'vis.catalog';
$canManage = has_permission('vis.manage');
$companyId = active_company_id();
$garageId = active_garage_id();

function vis_table_for_entity(string $entity): ?string
{
    return match ($entity) {
        'brand' => 'vis_brands',
        'model' => 'vis_models',
        'variant' => 'vis_variants',
        'spec' => 'vis_variant_specs',
        default => null,
    };
}

function vis_catalog_dependency_counts(PDO $pdo, string $entity, int $id, int $companyId, int $garageId): array
{
    $partLinks = 0;
    $jobLinks = 0;
    if ($id <= 0) {
        return ['part_links' => 0, 'job_links' => 0];
    }

    $jobScopeSql = ' AND jc.company_id = :company_id AND jc.status_code = "ACTIVE"';
    $jobScopeParams = ['company_id' => $companyId];
    if ($garageId > 0) {
        $jobScopeSql .= ' AND jc.garage_id = :garage_id';
        $jobScopeParams['garage_id'] = $garageId;
    }

    if ($entity === 'brand') {
        $partStmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM vis_part_compatibility c
             INNER JOIN vis_variants vv ON vv.id = c.variant_id
             INNER JOIN vis_models vm ON vm.id = vv.model_id
             WHERE vm.brand_id = :id
               AND c.company_id = :company_id
               AND c.status_code = "ACTIVE"'
        );
        $partStmt->execute(['id' => $id, 'company_id' => $companyId]);
        $partLinks = (int) $partStmt->fetchColumn();

        if (table_columns('vehicle_brands') !== [] && table_columns('vehicles') !== []) {
            $jobStmt = $pdo->prepare(
                'SELECT COUNT(DISTINCT jc.id)
                 FROM job_cards jc
                 INNER JOIN vehicles v ON v.id = jc.vehicle_id
                 INNER JOIN vehicle_brands vb ON vb.id = v.brand_id
                 WHERE vb.vis_brand_id = :id'
                 . $jobScopeSql
            );
            $jobStmt->execute(array_merge(['id' => $id], $jobScopeParams));
            $jobLinks = (int) $jobStmt->fetchColumn();
        }
    } elseif ($entity === 'model') {
        $partStmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM vis_part_compatibility c
             INNER JOIN vis_variants vv ON vv.id = c.variant_id
             WHERE vv.model_id = :id
               AND c.company_id = :company_id
               AND c.status_code = "ACTIVE"'
        );
        $partStmt->execute(['id' => $id, 'company_id' => $companyId]);
        $partLinks = (int) $partStmt->fetchColumn();

        if (table_columns('vehicle_models') !== [] && table_columns('vehicles') !== []) {
            $jobStmt = $pdo->prepare(
                'SELECT COUNT(DISTINCT jc.id)
                 FROM job_cards jc
                 INNER JOIN vehicles v ON v.id = jc.vehicle_id
                 INNER JOIN vehicle_models vm ON vm.id = v.model_id
                 WHERE vm.vis_model_id = :id'
                 . $jobScopeSql
            );
            $jobStmt->execute(array_merge(['id' => $id], $jobScopeParams));
            $jobLinks = (int) $jobStmt->fetchColumn();
        }
    } elseif ($entity === 'variant' || $entity === 'spec') {
        $variantId = $id;
        if ($entity === 'spec') {
            $variantStmt = $pdo->prepare('SELECT variant_id FROM vis_variant_specs WHERE id = :id LIMIT 1');
            $variantStmt->execute(['id' => $id]);
            $variantId = (int) ($variantStmt->fetchColumn() ?: 0);
        }

        if ($variantId > 0) {
            $partStmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM vis_part_compatibility
                 WHERE variant_id = :variant_id
                   AND company_id = :company_id
                   AND status_code = "ACTIVE"'
            );
            $partStmt->execute([
                'variant_id' => $variantId,
                'company_id' => $companyId,
            ]);
            $partLinks = (int) $partStmt->fetchColumn();

            if (table_columns('vehicle_variants') !== [] && table_columns('vehicles') !== []) {
                $jobStmt = $pdo->prepare(
                    'SELECT COUNT(DISTINCT jc.id)
                     FROM job_cards jc
                     INNER JOIN vehicles v ON v.id = jc.vehicle_id
                     INNER JOIN vehicle_variants vv ON vv.id = v.variant_id
                     WHERE vv.vis_variant_id = :variant_id'
                    . $jobScopeSql
                );
                $jobStmt->execute(array_merge(['variant_id' => $variantId], $jobScopeParams));
                $jobLinks = (int) $jobStmt->fetchColumn();
            }
        }
    }

    return [
        'part_links' => $partLinks,
        'job_links' => $jobLinks,
    ];
}

function vis_catalog_resolve_status(PDO $pdo, string $entity, int $id, string $requestedStatus, int $companyId, int $garageId): array
{
    $statusCode = normalize_status_code($requestedStatus);
    $dependency = [
        'status_code' => $statusCode,
        'blocked' => false,
        'part_links' => 0,
        'job_links' => 0,
        'message' => null,
    ];

    if ($statusCode !== 'DELETED' || $id <= 0) {
        return $dependency;
    }

    $counts = vis_catalog_dependency_counts($pdo, $entity, $id, $companyId, $garageId);
    $dependency['part_links'] = (int) ($counts['part_links'] ?? 0);
    $dependency['job_links'] = (int) ($counts['job_links'] ?? 0);
    if ($dependency['part_links'] > 0 || $dependency['job_links'] > 0) {
        $dependency['status_code'] = 'INACTIVE';
        $dependency['blocked'] = true;
        $dependency['message'] = 'Active links found (parts: ' . $dependency['part_links'] . ', jobs: ' . $dependency['job_links'] . '). Record was disabled instead of deleted.';
    }

    return $dependency;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!$canManage) {
        flash_set('vis_error', 'You do not have permission to modify VIS catalog.', 'danger');
        redirect('modules/vis/catalog.php');
    }

    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'save_brand') {
        $id = post_int('id');
        $brandName = post_string('brand_name', 100);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));
        if ($brandName === '') {
            flash_set('vis_error', 'Brand name is required.', 'danger');
            redirect('modules/vis/catalog.php');
        }
        try {
            if ($id > 0) {
                $statusMeta = vis_catalog_resolve_status(db(), 'brand', $id, $statusCode, $companyId, $garageId);
                $resolvedStatus = (string) ($statusMeta['status_code'] ?? $statusCode);
                $stmt = db()->prepare(
                    'UPDATE vis_brands
                     SET brand_name = :brand_name,
                         status_code = :status_code,
                         deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
                     WHERE id = :id'
                );
                $stmt->execute([
                    'brand_name' => $brandName,
                    'status_code' => $resolvedStatus,
                    'id' => $id,
                ]);
                if ($resolvedStatus === 'ACTIVE') {
                    vehicle_master_ensure_brand($brandName, $id);
                }
                log_audit('vis_catalog', 'update_brand', $id, 'Updated brand ' . $brandName, [
                    'entity' => 'vis_brand',
                    'source' => 'UI',
                    'metadata' => [
                        'requested_status' => $statusCode,
                        'applied_status' => $resolvedStatus,
                        'dependency_part_links' => (int) ($statusMeta['part_links'] ?? 0),
                        'dependency_job_links' => (int) ($statusMeta['job_links'] ?? 0),
                    ],
                ]);
                flash_set('vis_success', 'Brand updated.', 'success');
                if (($statusMeta['blocked'] ?? false) && !empty($statusMeta['message'])) {
                    flash_set('vis_warning', (string) $statusMeta['message'], 'warning');
                }
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO vis_brands (brand_name, status_code, deleted_at)
                     VALUES (:brand_name, :status_code, :deleted_at)'
                );
                $stmt->execute([
                    'brand_name' => $brandName,
                    'status_code' => $statusCode,
                    'deleted_at' => $statusCode === 'DELETED' ? date('Y-m-d H:i:s') : null,
                ]);
                $createdBrandId = (int) db()->lastInsertId();
                if ($statusCode === 'ACTIVE') {
                    vehicle_master_ensure_brand($brandName, $createdBrandId);
                }
                log_audit('vis_catalog', 'create_brand', $createdBrandId, 'Created brand ' . $brandName);
                flash_set('vis_success', 'Brand created.', 'success');
            }
        } catch (Throwable $exception) {
            flash_set('vis_error', 'Unable to save brand. Name must be unique.', 'danger');
        }
        redirect('modules/vis/catalog.php');
    }

    if ($action === 'save_model') {
        $id = post_int('id');
        $brandId = post_int('brand_id');
        $modelName = post_string('model_name', 120);
        $vehicleType = (string) ($_POST['vehicle_type'] ?? '4W');
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));
        if (!in_array($vehicleType, ['2W', '4W', 'COMMERCIAL'], true)) {
            $vehicleType = '4W';
        }
        if ($brandId <= 0 || $modelName === '') {
            flash_set('vis_error', 'Brand and model name are required.', 'danger');
            redirect('modules/vis/catalog.php');
        }
        try {
            if ($id > 0) {
                $statusMeta = vis_catalog_resolve_status(db(), 'model', $id, $statusCode, $companyId, $garageId);
                $resolvedStatus = (string) ($statusMeta['status_code'] ?? $statusCode);
                $stmt = db()->prepare(
                    'UPDATE vis_models
                     SET brand_id = :brand_id,
                         model_name = :model_name,
                         vehicle_type = :vehicle_type,
                         status_code = :status_code,
                         deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
                     WHERE id = :id'
                );
                $stmt->execute([
                    'brand_id' => $brandId,
                    'model_name' => $modelName,
                    'vehicle_type' => $vehicleType,
                    'status_code' => $resolvedStatus,
                    'id' => $id,
                ]);
                if ($resolvedStatus === 'ACTIVE') {
                    $visBrandStmt = db()->prepare('SELECT brand_name FROM vis_brands WHERE id = :id LIMIT 1');
                    $visBrandStmt->execute(['id' => $brandId]);
                    $visBrandName = (string) ($visBrandStmt->fetchColumn() ?: '');
                    if ($visBrandName !== '') {
                        $masterBrand = vehicle_master_ensure_brand($visBrandName, $brandId);
                        if ($masterBrand !== null) {
                            vehicle_master_ensure_model((int) $masterBrand['id'], $modelName, $vehicleType, $id);
                        }
                    }
                }
                log_audit('vis_catalog', 'update_model', $id, 'Updated model ' . $modelName, [
                    'entity' => 'vis_model',
                    'source' => 'UI',
                    'metadata' => [
                        'requested_status' => $statusCode,
                        'applied_status' => $resolvedStatus,
                        'dependency_part_links' => (int) ($statusMeta['part_links'] ?? 0),
                        'dependency_job_links' => (int) ($statusMeta['job_links'] ?? 0),
                    ],
                ]);
                flash_set('vis_success', 'Model updated.', 'success');
                if (($statusMeta['blocked'] ?? false) && !empty($statusMeta['message'])) {
                    flash_set('vis_warning', (string) $statusMeta['message'], 'warning');
                }
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO vis_models (brand_id, model_name, vehicle_type, status_code, deleted_at)
                     VALUES (:brand_id, :model_name, :vehicle_type, :status_code, :deleted_at)'
                );
                $stmt->execute([
                    'brand_id' => $brandId,
                    'model_name' => $modelName,
                    'vehicle_type' => $vehicleType,
                    'status_code' => $statusCode,
                    'deleted_at' => $statusCode === 'DELETED' ? date('Y-m-d H:i:s') : null,
                ]);
                $createdModelId = (int) db()->lastInsertId();
                if ($statusCode === 'ACTIVE') {
                    $visBrandStmt = db()->prepare('SELECT brand_name FROM vis_brands WHERE id = :id LIMIT 1');
                    $visBrandStmt->execute(['id' => $brandId]);
                    $visBrandName = (string) ($visBrandStmt->fetchColumn() ?: '');
                    if ($visBrandName !== '') {
                        $masterBrand = vehicle_master_ensure_brand($visBrandName, $brandId);
                        if ($masterBrand !== null) {
                            vehicle_master_ensure_model((int) $masterBrand['id'], $modelName, $vehicleType, $createdModelId);
                        }
                    }
                }
                log_audit('vis_catalog', 'create_model', $createdModelId, 'Created model ' . $modelName);
                flash_set('vis_success', 'Model created.', 'success');
            }
        } catch (Throwable $exception) {
            flash_set('vis_error', 'Unable to save model. Model must be unique per brand.', 'danger');
        }
        redirect('modules/vis/catalog.php');
    }

    if ($action === 'save_variant') {
        $id = post_int('id');
        $modelId = post_int('model_id');
        $variantName = post_string('variant_name', 150);
        $fuelType = (string) ($_POST['fuel_type'] ?? 'PETROL');
        $engineCc = post_string('engine_cc', 30);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));
        if (!in_array($fuelType, ['PETROL', 'DIESEL', 'CNG', 'EV', 'HYBRID', 'OTHER'], true)) {
            $fuelType = 'PETROL';
        }
        if ($modelId <= 0 || $variantName === '') {
            flash_set('vis_error', 'Model and variant name are required.', 'danger');
            redirect('modules/vis/catalog.php');
        }
        try {
            if ($id > 0) {
                $statusMeta = vis_catalog_resolve_status(db(), 'variant', $id, $statusCode, $companyId, $garageId);
                $resolvedStatus = (string) ($statusMeta['status_code'] ?? $statusCode);
                $stmt = db()->prepare(
                    'UPDATE vis_variants
                     SET model_id = :model_id,
                         variant_name = :variant_name,
                         fuel_type = :fuel_type,
                         engine_cc = :engine_cc,
                         status_code = :status_code,
                         deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
                     WHERE id = :id'
                );
                $stmt->execute([
                    'model_id' => $modelId,
                    'variant_name' => $variantName,
                    'fuel_type' => $fuelType,
                    'engine_cc' => $engineCc !== '' ? $engineCc : null,
                    'status_code' => $resolvedStatus,
                    'id' => $id,
                ]);
                if ($resolvedStatus === 'ACTIVE') {
                    $visModelStmt = db()->prepare(
                        'SELECT vm.id AS vis_model_id, vm.model_name, vm.vehicle_type, vb.id AS vis_brand_id, vb.brand_name
                         FROM vis_models vm
                         INNER JOIN vis_brands vb ON vb.id = vm.brand_id
                         WHERE vm.id = :id
                         LIMIT 1'
                    );
                    $visModelStmt->execute(['id' => $modelId]);
                    $visModel = $visModelStmt->fetch();
                    if ($visModel) {
                        $masterBrand = vehicle_master_ensure_brand((string) $visModel['brand_name'], (int) $visModel['vis_brand_id']);
                        if ($masterBrand !== null) {
                            $masterModel = vehicle_master_ensure_model(
                                (int) $masterBrand['id'],
                                (string) $visModel['model_name'],
                                (string) $visModel['vehicle_type'],
                                (int) $visModel['vis_model_id']
                            );
                            if ($masterModel !== null) {
                                vehicle_master_ensure_variant(
                                    (int) $masterModel['id'],
                                    $variantName,
                                    $fuelType,
                                    $engineCc !== '' ? $engineCc : null,
                                    $id
                                );
                            }
                        }
                    }
                }
                log_audit('vis_catalog', 'update_variant', $id, 'Updated variant ' . $variantName, [
                    'entity' => 'vis_variant',
                    'source' => 'UI',
                    'metadata' => [
                        'requested_status' => $statusCode,
                        'applied_status' => $resolvedStatus,
                        'dependency_part_links' => (int) ($statusMeta['part_links'] ?? 0),
                        'dependency_job_links' => (int) ($statusMeta['job_links'] ?? 0),
                    ],
                ]);
                flash_set('vis_success', 'Variant updated.', 'success');
                if (($statusMeta['blocked'] ?? false) && !empty($statusMeta['message'])) {
                    flash_set('vis_warning', (string) $statusMeta['message'], 'warning');
                }
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO vis_variants (model_id, variant_name, fuel_type, engine_cc, status_code, deleted_at)
                     VALUES (:model_id, :variant_name, :fuel_type, :engine_cc, :status_code, :deleted_at)'
                );
                $stmt->execute([
                    'model_id' => $modelId,
                    'variant_name' => $variantName,
                    'fuel_type' => $fuelType,
                    'engine_cc' => $engineCc !== '' ? $engineCc : null,
                    'status_code' => $statusCode,
                    'deleted_at' => $statusCode === 'DELETED' ? date('Y-m-d H:i:s') : null,
                ]);
                $createdVariantId = (int) db()->lastInsertId();
                if ($statusCode === 'ACTIVE') {
                    $visModelStmt = db()->prepare(
                        'SELECT vm.id AS vis_model_id, vm.model_name, vm.vehicle_type, vb.id AS vis_brand_id, vb.brand_name
                         FROM vis_models vm
                         INNER JOIN vis_brands vb ON vb.id = vm.brand_id
                         WHERE vm.id = :id
                         LIMIT 1'
                    );
                    $visModelStmt->execute(['id' => $modelId]);
                    $visModel = $visModelStmt->fetch();
                    if ($visModel) {
                        $masterBrand = vehicle_master_ensure_brand((string) $visModel['brand_name'], (int) $visModel['vis_brand_id']);
                        if ($masterBrand !== null) {
                            $masterModel = vehicle_master_ensure_model(
                                (int) $masterBrand['id'],
                                (string) $visModel['model_name'],
                                (string) $visModel['vehicle_type'],
                                (int) $visModel['vis_model_id']
                            );
                            if ($masterModel !== null) {
                                vehicle_master_ensure_variant(
                                    (int) $masterModel['id'],
                                    $variantName,
                                    $fuelType,
                                    $engineCc !== '' ? $engineCc : null,
                                    $createdVariantId
                                );
                            }
                        }
                    }
                }
                log_audit('vis_catalog', 'create_variant', $createdVariantId, 'Created variant ' . $variantName);
                flash_set('vis_success', 'Variant created.', 'success');
            }
        } catch (Throwable $exception) {
            flash_set('vis_error', 'Unable to save variant. Variant must be unique per model.', 'danger');
        }
        redirect('modules/vis/catalog.php');
    }

    if ($action === 'save_spec') {
        $id = post_int('id');
        $variantId = post_int('variant_id');
        $specKey = post_string('spec_key', 80);
        $specValue = post_string('spec_value', 255);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));
        if ($variantId <= 0 || $specKey === '' || $specValue === '') {
            flash_set('vis_error', 'Variant, spec key and spec value are required.', 'danger');
            redirect('modules/vis/catalog.php');
        }
        if ($id > 0) {
            $statusMeta = vis_catalog_resolve_status(db(), 'spec', $id, $statusCode, $companyId, $garageId);
            $resolvedStatus = (string) ($statusMeta['status_code'] ?? $statusCode);
            $stmt = db()->prepare(
                'UPDATE vis_variant_specs
                 SET variant_id = :variant_id,
                     spec_key = :spec_key,
                     spec_value = :spec_value,
                     status_code = :status_code,
                     deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
                 WHERE id = :id'
            );
            $stmt->execute([
                'variant_id' => $variantId,
                'spec_key' => $specKey,
                'spec_value' => $specValue,
                'status_code' => $resolvedStatus,
                'id' => $id,
            ]);
            log_audit('vis_catalog', 'update_spec', $id, 'Updated spec ' . $specKey, [
                'entity' => 'vis_variant_spec',
                'source' => 'UI',
                'metadata' => [
                    'requested_status' => $statusCode,
                    'applied_status' => $resolvedStatus,
                    'dependency_part_links' => (int) ($statusMeta['part_links'] ?? 0),
                    'dependency_job_links' => (int) ($statusMeta['job_links'] ?? 0),
                ],
            ]);
            flash_set('vis_success', 'Specification updated.', 'success');
            if (($statusMeta['blocked'] ?? false) && !empty($statusMeta['message'])) {
                flash_set('vis_warning', (string) $statusMeta['message'], 'warning');
            }
        } else {
            $stmt = db()->prepare(
                'INSERT INTO vis_variant_specs (variant_id, spec_key, spec_value, status_code, deleted_at)
                 VALUES (:variant_id, :spec_key, :spec_value, :status_code, :deleted_at)'
            );
            $stmt->execute([
                'variant_id' => $variantId,
                'spec_key' => $specKey,
                'spec_value' => $specValue,
                'status_code' => $statusCode,
                'deleted_at' => $statusCode === 'DELETED' ? date('Y-m-d H:i:s') : null,
            ]);
            log_audit('vis_catalog', 'create_spec', (int) db()->lastInsertId(), 'Created spec ' . $specKey);
            flash_set('vis_success', 'Specification created.', 'success');
        }
        redirect('modules/vis/catalog.php');
    }

    if ($action === 'save_interval_rule') {
        flash_set('vis_warning', 'VIS interval rules are retired. Use Vehicle Maintenance Setup for vehicle-specific reminders.', 'warning');
        redirect('modules/vis/catalog.php');
    }

    if ($action === 'change_status') {
        $entity = (string) ($_POST['entity'] ?? '');
        $id = post_int('id');
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));
        $table = vis_table_for_entity($entity);
        $safeDeleteValidation = null;
        $safeDeleteEntityMap = [
            'brand' => 'vis_catalog_brand',
            'model' => 'vis_catalog_model',
            'variant' => 'vis_catalog_variant',
            'spec' => 'vis_catalog_spec',
        ];
        $safeDeleteEntity = (string) ($safeDeleteEntityMap[$entity] ?? '');
        if ($id <= 0 || $table === null) {
            flash_set('vis_error', 'Invalid status payload.', 'danger');
            redirect('modules/vis/catalog.php');
        }
        if ($nextStatus === 'DELETED') {
            if ($safeDeleteEntity === '') {
                flash_set('vis_error', 'Safe delete mapping is not configured for VIS entity.', 'danger');
                redirect('modules/vis/catalog.php');
            }
            $safeDeleteValidation = safe_delete_validate_post_confirmation($safeDeleteEntity, $id, [
                'operation' => 'delete',
                'reason_field' => 'deletion_reason',
            ]);
        }

        $statusMeta = vis_catalog_resolve_status(db(), $entity, $id, $nextStatus, $companyId, $garageId);
        $resolvedStatus = (string) ($statusMeta['status_code'] ?? $nextStatus);
        $stmt = db()->prepare(
            "UPDATE {$table}
             SET status_code = :status_code,
                 deleted_at = CASE WHEN :status_code = 'DELETED' THEN NOW() ELSE NULL END
             WHERE id = :id"
        );
        $stmt->execute([
            'status_code' => $resolvedStatus,
            'id' => $id,
        ]);
        log_audit('vis_catalog', 'status_' . $entity, $id, 'Changed status to ' . $resolvedStatus, [
            'entity' => 'vis_' . $entity,
            'source' => 'UI',
            'metadata' => [
                'requested_status' => $nextStatus,
                'applied_status' => $resolvedStatus,
                'dependency_part_links' => (int) ($statusMeta['part_links'] ?? 0),
                'dependency_job_links' => (int) ($statusMeta['job_links'] ?? 0),
            ],
        ]);
        if ($nextStatus === 'DELETED' && is_array($safeDeleteValidation) && $safeDeleteEntity !== '') {
            safe_delete_log_cascade($safeDeleteEntity, 'delete', $id, $safeDeleteValidation, [
                'metadata' => [
                    'entity' => $entity,
                    'requested_status' => $nextStatus,
                    'applied_status' => $resolvedStatus,
                    'dependency_part_links' => (int) ($statusMeta['part_links'] ?? 0),
                    'dependency_job_links' => (int) ($statusMeta['job_links'] ?? 0),
                ],
            ]);
        }
        flash_set('vis_success', 'Status updated.', 'success');
        if (($statusMeta['blocked'] ?? false) && !empty($statusMeta['message'])) {
            flash_set('vis_warning', (string) $statusMeta['message'], 'warning');
        }
        redirect('modules/vis/catalog.php');
    }
}

$editBrandId = get_int('edit_brand_id');
$editModelId = get_int('edit_model_id');
$editVariantId = get_int('edit_variant_id');
$editSpecId = get_int('edit_spec_id');

$editBrand = null;
$editModel = null;
$editVariant = null;
$editSpec = null;

if ($editBrandId > 0) {
    $stmt = db()->prepare('SELECT * FROM vis_brands WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $editBrandId]);
    $editBrand = $stmt->fetch() ?: null;
}
if ($editModelId > 0) {
    $stmt = db()->prepare('SELECT * FROM vis_models WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $editModelId]);
    $editModel = $stmt->fetch() ?: null;
}
if ($editVariantId > 0) {
    $stmt = db()->prepare('SELECT * FROM vis_variants WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $editVariantId]);
    $editVariant = $stmt->fetch() ?: null;
}
if ($editSpecId > 0) {
    $stmt = db()->prepare('SELECT * FROM vis_variant_specs WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $editSpecId]);
    $editSpec = $stmt->fetch() ?: null;
}

$brands = db()->query('SELECT * FROM vis_brands ORDER BY brand_name ASC')->fetchAll();
$models = db()->query(
    'SELECT m.*, b.brand_name
     FROM vis_models m
     INNER JOIN vis_brands b ON b.id = m.brand_id
     ORDER BY b.brand_name ASC, m.model_name ASC'
)->fetchAll();
$variants = db()->query(
    'SELECT v.*, m.model_name, b.brand_name
     FROM vis_variants v
     INNER JOIN vis_models m ON m.id = v.model_id
     INNER JOIN vis_brands b ON b.id = m.brand_id
     ORDER BY b.brand_name ASC, m.model_name ASC, v.variant_name ASC'
)->fetchAll();
$specs = db()->query(
    'SELECT s.*, v.variant_name, m.model_name, b.brand_name
     FROM vis_variant_specs s
     INNER JOIN vis_variants v ON v.id = s.variant_id
     INNER JOIN vis_models m ON m.id = v.model_id
     INNER JOIN vis_brands b ON b.id = m.brand_id
     ORDER BY s.id DESC
     LIMIT 120'
)->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">VIS Vehicle Catalog</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">VIS Catalog</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if (!$canManage): ?>
        <div class="alert alert-info">VIS catalog is in read-only mode for your role.</div>
      <?php endif; ?>

      <?php if ($canManage): ?>
        <div class="row g-3">
          <div class="col-lg-3">
            <div class="card card-primary"><div class="card-header"><h3 class="card-title"><?= $editBrand ? 'Edit Brand' : 'Add Brand'; ?></h3></div>
              <form method="post"><div class="card-body">
                <?= csrf_field(); ?><input type="hidden" name="_action" value="save_brand"><input type="hidden" name="id" value="<?= (int) ($editBrand['id'] ?? 0); ?>">
                <input type="text" name="brand_name" class="form-control mb-2" required value="<?= e((string) ($editBrand['brand_name'] ?? '')); ?>" placeholder="Brand name">
                <select name="status_code" class="form-select mb-2"><?php foreach (status_options((string) ($editBrand['status_code'] ?? 'ACTIVE')) as $option): ?><option value="<?= e($option['value']); ?>" <?= $option['selected'] ? 'selected' : ''; ?>><?= e($option['value']); ?></option><?php endforeach; ?></select>
                <button class="btn btn-primary w-100" type="submit"><?= $editBrand ? 'Update' : 'Add'; ?></button>
              </div></form>
            </div>
          </div>
          <div class="col-lg-4">
            <div class="card card-info"><div class="card-header"><h3 class="card-title"><?= $editModel ? 'Edit Model' : 'Add Model'; ?></h3></div>
              <form method="post"><div class="card-body">
                <?= csrf_field(); ?><input type="hidden" name="_action" value="save_model"><input type="hidden" name="id" value="<?= (int) ($editModel['id'] ?? 0); ?>">
                <select name="brand_id" class="form-select mb-2" required><option value="">Select brand</option><?php foreach ($brands as $brand): ?><option value="<?= (int) $brand['id']; ?>" <?= ((int) ($editModel['brand_id'] ?? 0) === (int) $brand['id']) ? 'selected' : ''; ?>><?= e((string) $brand['brand_name']); ?></option><?php endforeach; ?></select>
                <input type="text" name="model_name" class="form-control mb-2" required value="<?= e((string) ($editModel['model_name'] ?? '')); ?>" placeholder="Model name">
                <select name="vehicle_type" class="form-select mb-2">
                  <option value="2W" <?= ((string) ($editModel['vehicle_type'] ?? '4W') === '2W') ? 'selected' : ''; ?>>2W</option>
                  <option value="4W" <?= ((string) ($editModel['vehicle_type'] ?? '4W') === '4W') ? 'selected' : ''; ?>>4W</option>
                  <option value="COMMERCIAL" <?= ((string) ($editModel['vehicle_type'] ?? '4W') === 'COMMERCIAL') ? 'selected' : ''; ?>>COMMERCIAL</option>
                </select>
                <select name="status_code" class="form-select mb-2"><?php foreach (status_options((string) ($editModel['status_code'] ?? 'ACTIVE')) as $option): ?><option value="<?= e($option['value']); ?>" <?= $option['selected'] ? 'selected' : ''; ?>><?= e($option['value']); ?></option><?php endforeach; ?></select>
                <button class="btn btn-info w-100" type="submit"><?= $editModel ? 'Update' : 'Add'; ?></button>
              </div></form>
            </div>
          </div>
          <div class="col-lg-5">
            <div class="card card-success"><div class="card-header"><h3 class="card-title"><?= $editVariant ? 'Edit Variant' : 'Add Variant'; ?></h3></div>
              <form method="post"><div class="card-body">
                <?= csrf_field(); ?><input type="hidden" name="_action" value="save_variant"><input type="hidden" name="id" value="<?= (int) ($editVariant['id'] ?? 0); ?>">
                <select name="model_id" class="form-select mb-2" required><option value="">Select model</option><?php foreach ($models as $model): ?><option value="<?= (int) $model['id']; ?>" <?= ((int) ($editVariant['model_id'] ?? 0) === (int) $model['id']) ? 'selected' : ''; ?>><?= e((string) $model['brand_name']); ?> - <?= e((string) $model['model_name']); ?></option><?php endforeach; ?></select>
                <div class="row g-2">
                  <div class="col-md-5"><input type="text" name="variant_name" class="form-control" required value="<?= e((string) ($editVariant['variant_name'] ?? '')); ?>" placeholder="Variant name"></div>
                  <div class="col-md-3"><select name="fuel_type" class="form-select"><?php $fuel = (string) ($editVariant['fuel_type'] ?? 'PETROL'); ?><option value="PETROL" <?= $fuel === 'PETROL' ? 'selected' : ''; ?>>PETROL</option><option value="DIESEL" <?= $fuel === 'DIESEL' ? 'selected' : ''; ?>>DIESEL</option><option value="CNG" <?= $fuel === 'CNG' ? 'selected' : ''; ?>>CNG</option><option value="EV" <?= $fuel === 'EV' ? 'selected' : ''; ?>>EV</option><option value="HYBRID" <?= $fuel === 'HYBRID' ? 'selected' : ''; ?>>HYBRID</option><option value="OTHER" <?= $fuel === 'OTHER' ? 'selected' : ''; ?>>OTHER</option></select></div>
                  <div class="col-md-2"><input type="text" name="engine_cc" class="form-control" value="<?= e((string) ($editVariant['engine_cc'] ?? '')); ?>" placeholder="CC"></div>
                  <div class="col-md-2"><select name="status_code" class="form-select"><?php foreach (status_options((string) ($editVariant['status_code'] ?? 'ACTIVE')) as $option): ?><option value="<?= e($option['value']); ?>" <?= $option['selected'] ? 'selected' : ''; ?>><?= e($option['value']); ?></option><?php endforeach; ?></select></div>
                </div>
                <button class="btn btn-success w-100 mt-2" type="submit"><?= $editVariant ? 'Update' : 'Add'; ?></button>
              </div></form>
            </div>
          </div>
        </div>
        <div class="card card-warning mt-3"><div class="card-header"><h3 class="card-title"><?= $editSpec ? 'Edit Specification' : 'Add Specification'; ?></h3></div>
          <form method="post"><div class="card-body row g-2">
            <?= csrf_field(); ?><input type="hidden" name="_action" value="save_spec"><input type="hidden" name="id" value="<?= (int) ($editSpec['id'] ?? 0); ?>">
            <div class="col-md-5"><select name="variant_id" class="form-select" required><option value="">Select variant</option><?php foreach ($variants as $variant): ?><option value="<?= (int) $variant['id']; ?>" <?= ((int) ($editSpec['variant_id'] ?? 0) === (int) $variant['id']) ? 'selected' : ''; ?>><?= e((string) $variant['brand_name']); ?> / <?= e((string) $variant['model_name']); ?> / <?= e((string) $variant['variant_name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><input type="text" name="spec_key" class="form-control" required value="<?= e((string) ($editSpec['spec_key'] ?? '')); ?>" placeholder="spec_key"></div>
            <div class="col-md-3"><input type="text" name="spec_value" class="form-control" required value="<?= e((string) ($editSpec['spec_value'] ?? '')); ?>" placeholder="spec_value"></div>
            <div class="col-md-1"><select name="status_code" class="form-select"><?php foreach (status_options((string) ($editSpec['status_code'] ?? 'ACTIVE')) as $option): ?><option value="<?= e($option['value']); ?>" <?= $option['selected'] ? 'selected' : ''; ?>><?= e($option['value']); ?></option><?php endforeach; ?></select></div>
          </div><div class="card-footer"><button class="btn btn-warning" type="submit"><?= $editSpec ? 'Update' : 'Add'; ?></button></div></form>
        </div>

        <div class="alert alert-light border mt-3 mb-0">
          VIS fixed interval rules are retired.
          Use <a href="<?= e(url('modules/jobs/maintenance_setup.php')); ?>" class="alert-link">Vehicle Maintenance Setup</a>
          for vehicle-specific Service/Part reminder rules.
        </div>
      <?php endif; ?>

      <div class="row g-3 mt-1">
        <div class="col-lg-4">
          <div class="card"><div class="card-header"><h3 class="card-title">Brands</h3></div><div class="card-body table-responsive p-0" style="max-height: 280px;"><table class="table table-sm table-striped mb-0"><thead><tr><th>Brand</th><th>Status</th><th></th></tr></thead><tbody>
          <?php foreach ($brands as $brand): ?>
            <tr>
              <td><?= e((string) $brand['brand_name']); ?></td>
              <td><span class="badge text-bg-<?= e(status_badge_class((string) $brand['status_code'])); ?>"><?= e((string) $brand['status_code']); ?></span></td>
              <td>
                <?php if ($canManage): ?>
                  <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/vis/catalog.php?edit_brand_id=' . (int) $brand['id'])); ?>">Edit</a>
                  <form method="post" class="d-inline">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="_action" value="change_status">
                    <input type="hidden" name="entity" value="brand">
                    <input type="hidden" name="id" value="<?= (int) $brand['id']; ?>">
                    <input type="hidden" name="next_status" value="<?= e(((string) $brand['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>">
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Toggle</button>
                  </form>
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-danger js-vis-delete-btn"
                    data-bs-toggle="modal"
                    data-bs-target="#visDeleteModal"
                    data-entity="brand"
                    data-record-id="<?= (int) $brand['id']; ?>"
                    data-record-label="Brand: <?= e((string) $brand['brand_name']); ?>"
                  >Delete</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody></table></div></div>
        </div>
        <div class="col-lg-4">
          <div class="card"><div class="card-header"><h3 class="card-title">Models</h3></div><div class="card-body table-responsive p-0" style="max-height: 280px;"><table class="table table-sm table-striped mb-0"><thead><tr><th>Model</th><th>Type</th><th>Status</th><th></th></tr></thead><tbody>
          <?php foreach ($models as $model): ?>
            <tr>
              <td><?= e((string) $model['brand_name']); ?> / <?= e((string) $model['model_name']); ?></td>
              <td><?= e((string) $model['vehicle_type']); ?></td>
              <td><span class="badge text-bg-<?= e(status_badge_class((string) $model['status_code'])); ?>"><?= e((string) $model['status_code']); ?></span></td>
              <td>
                <?php if ($canManage): ?>
                  <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/vis/catalog.php?edit_model_id=' . (int) $model['id'])); ?>">Edit</a>
                  <form method="post" class="d-inline">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="_action" value="change_status">
                    <input type="hidden" name="entity" value="model">
                    <input type="hidden" name="id" value="<?= (int) $model['id']; ?>">
                    <input type="hidden" name="next_status" value="<?= e(((string) $model['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>">
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Toggle</button>
                  </form>
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-danger js-vis-delete-btn"
                    data-bs-toggle="modal"
                    data-bs-target="#visDeleteModal"
                    data-entity="model"
                    data-record-id="<?= (int) $model['id']; ?>"
                    data-record-label="Model: <?= e((string) $model['brand_name']); ?> / <?= e((string) $model['model_name']); ?>"
                  >Delete</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody></table></div></div>
        </div>
        <div class="col-lg-4">
          <div class="card"><div class="card-header"><h3 class="card-title">Variants</h3></div><div class="card-body table-responsive p-0" style="max-height: 280px;"><table class="table table-sm table-striped mb-0"><thead><tr><th>Variant</th><th>Fuel</th><th>Status</th><th></th></tr></thead><tbody>
          <?php foreach ($variants as $variant): ?>
            <tr>
              <td><?= e((string) $variant['brand_name']); ?> / <?= e((string) $variant['model_name']); ?> / <?= e((string) $variant['variant_name']); ?></td>
              <td><?= e((string) $variant['fuel_type']); ?></td>
              <td><span class="badge text-bg-<?= e(status_badge_class((string) $variant['status_code'])); ?>"><?= e((string) $variant['status_code']); ?></span></td>
              <td>
                <?php if ($canManage): ?>
                  <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/vis/catalog.php?edit_variant_id=' . (int) $variant['id'])); ?>">Edit</a>
                  <form method="post" class="d-inline">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="_action" value="change_status">
                    <input type="hidden" name="entity" value="variant">
                    <input type="hidden" name="id" value="<?= (int) $variant['id']; ?>">
                    <input type="hidden" name="next_status" value="<?= e(((string) $variant['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>">
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Toggle</button>
                  </form>
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-danger js-vis-delete-btn"
                    data-bs-toggle="modal"
                    data-bs-target="#visDeleteModal"
                    data-entity="variant"
                    data-record-id="<?= (int) $variant['id']; ?>"
                    data-record-label="Variant: <?= e((string) $variant['brand_name']); ?> / <?= e((string) $variant['model_name']); ?> / <?= e((string) $variant['variant_name']); ?>"
                  >Delete</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody></table></div></div>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header"><h3 class="card-title">Variant Specifications</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0">
            <thead><tr><th>Variant</th><th>Key</th><th>Value</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <?php if (empty($specs)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No specs configured.</td></tr>
              <?php else: ?>
                <?php foreach ($specs as $spec): ?>
                  <tr>
                    <td><?= e((string) $spec['brand_name']); ?> / <?= e((string) $spec['model_name']); ?> / <?= e((string) $spec['variant_name']); ?></td>
                    <td><code><?= e((string) $spec['spec_key']); ?></code></td>
                    <td><?= e((string) $spec['spec_value']); ?></td>
                    <td><span class="badge text-bg-<?= e(status_badge_class((string) $spec['status_code'])); ?>"><?= e((string) $spec['status_code']); ?></span></td>
                    <td>
                      <?php if ($canManage): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/vis/catalog.php?edit_spec_id=' . (int) $spec['id'])); ?>">Edit</a>
                        <form method="post" class="d-inline">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="_action" value="change_status">
                          <input type="hidden" name="entity" value="spec">
                          <input type="hidden" name="id" value="<?= (int) $spec['id']; ?>">
                          <input type="hidden" name="next_status" value="<?= e(((string) $spec['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>">
                          <button class="btn btn-sm btn-outline-secondary" type="submit">Toggle</button>
                        </form>
                        <button
                          type="button"
                          class="btn btn-sm btn-outline-danger js-vis-delete-btn"
                          data-bs-toggle="modal"
                          data-bs-target="#visDeleteModal"
                          data-entity="spec"
                          data-record-id="<?= (int) $spec['id']; ?>"
                          data-record-label="Spec: <?= e((string) $spec['brand_name']); ?> / <?= e((string) $spec['model_name']); ?> / <?= e((string) $spec['variant_name']); ?> / <?= e((string) $spec['spec_key']); ?>"
                        >Delete</button>
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

<div class="modal fade" id="visDeleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post"
            data-safe-delete
            data-safe-delete-entity-field="safe_delete_entity"
            data-safe-delete-record-field="id"
            data-safe-delete-operation="delete"
            data-safe-delete-reason-field="deletion_reason">
        <div class="modal-header bg-danger-subtle">
          <h5 class="modal-title">Delete VIS Record</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?= csrf_field(); ?>
          <input type="hidden" name="_action" value="change_status" />
          <input type="hidden" name="entity" id="vis-delete-entity" />
          <input type="hidden" name="safe_delete_entity" id="vis-delete-safe-entity" />
          <input type="hidden" name="id" id="vis-delete-id" />
          <input type="hidden" name="next_status" value="DELETED" />
          <div class="mb-3">
            <label class="form-label">Record</label>
            <input type="text" id="vis-delete-label" class="form-control" readonly />
          </div>
          <div class="alert alert-warning mb-0">
            If active part or job links exist, the system will safely disable this record instead of deleting it.
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
      var trigger = event.target.closest('.js-vis-delete-btn');
      if (!trigger) {
        return;
      }

      setValue('vis-delete-entity', trigger.getAttribute('data-entity'));
      var entity = trigger.getAttribute('data-entity') || '';
      var safeEntity = '';
      if (entity === 'brand') safeEntity = 'vis_catalog_brand';
      if (entity === 'model') safeEntity = 'vis_catalog_model';
      if (entity === 'variant') safeEntity = 'vis_catalog_variant';
      if (entity === 'spec') safeEntity = 'vis_catalog_spec';
      setValue('vis-delete-safe-entity', safeEntity);
      setValue('vis-delete-id', trigger.getAttribute('data-record-id'));
      setValue('vis-delete-label', trigger.getAttribute('data-record-label'));
    });
  })();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

