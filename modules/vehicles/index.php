<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('vehicle.view');

$page_title = 'Vehicle Master';
$active_menu = 'vehicles';
$canManage = has_permission('vehicle.manage');
$companyId = active_company_id();

$allowedVehicleTypes = ['2W', '4W', 'COMMERCIAL'];
$allowedFuelTypes = ['PETROL', 'DIESEL', 'CNG', 'EV', 'HYBRID', 'OTHER'];
$vehicleAttributeEnabled = vehicle_masters_enabled() && vehicle_master_link_columns_supported();
$jobCardColumns = table_columns('job_cards');
$jobOdometerEnabled = in_array('odometer_km', $jobCardColumns, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!$canManage) {
        flash_set('vehicle_error', 'You do not have permission to modify vehicle master.', 'danger');
        redirect('modules/vehicles/index.php');
    }

    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'create' || $action === 'update') {
        $vehicleId = post_int('vehicle_id');
        $customerId = post_int('customer_id');
        $registrationNo = strtoupper(post_string('registration_no', 30));
        $vehicleType = (string) ($_POST['vehicle_type'] ?? '4W');
        $brandId = post_int('brand_id');
        $modelId = post_int('model_id');
        $variantId = post_int('variant_id');
        $fuelType = (string) ($_POST['fuel_type'] ?? 'PETROL');
        $modelYearId = post_int('model_year_id');
        $colorId = post_int('color_id');
        $chassisNo = post_string('chassis_no', 60);
        $engineNo = post_string('engine_no', 60);
        $notes = post_string('notes', 2000);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));
        $visVariantId = post_int('vis_variant_id');

        $brandText = vehicle_master_normalize_text(post_string('brand_text', 100), 100);
        $modelText = vehicle_master_normalize_text(post_string('model_text', 120), 120);
        $variantText = vehicle_master_normalize_text(post_string('variant_text', 150), 150);
        $modelYearText = vehicle_master_normalize_text(post_string('model_year_text', 4), 4);
        $colorText = vehicle_master_normalize_text(post_string('color_text', 60), 60);

        if ($brandText === '') {
            $brandText = vehicle_master_normalize_text(post_string('brand', 100), 100);
        }
        if ($modelText === '') {
            $modelText = vehicle_master_normalize_text(post_string('model', 120), 120);
        }
        if ($variantText === '') {
            $variantText = vehicle_master_normalize_text(post_string('variant', 150), 150);
        }
        if ($modelYearText === '') {
            $legacyYear = post_int('model_year');
            if ($legacyYear > 0) {
                $modelYearText = (string) $legacyYear;
            }
        }
        if ($colorText === '') {
            $colorText = vehicle_master_normalize_text(post_string('color', 60), 60);
        }

        if (!in_array($vehicleType, $allowedVehicleTypes, true)) {
            $vehicleType = '4W';
        }

        if (!in_array($fuelType, $allowedFuelTypes, true)) {
            $fuelType = 'PETROL';
        }

        $resolvedBrandId = null;
        $resolvedModelId = null;
        $resolvedVariantId = null;
        $resolvedModelYearId = null;
        $resolvedColorId = null;

        $brand = '';
        $model = '';
        $variant = '';
        $modelYear = 0;
        $color = '';

        if ($vehicleAttributeEnabled) {
            $brandRow = vehicle_master_get_brand($brandId);
            if ($brandRow !== null) {
                $resolvedBrandId = (int) $brandRow['id'];
                $brand = (string) $brandRow['brand_name'];
            } elseif ($brandText !== '') {
                $brandRow = vehicle_master_ensure_brand($brandText);
                if ($brandRow !== null) {
                    $resolvedBrandId = (int) $brandRow['id'];
                    $brand = (string) $brandRow['brand_name'];
                } else {
                    $brand = mb_substr($brandText, 0, 80);
                }
            }

            if ($resolvedBrandId !== null && $resolvedBrandId > 0) {
                $modelRow = vehicle_master_get_model($modelId, $resolvedBrandId);
                if ($modelRow !== null) {
                    $resolvedModelId = (int) $modelRow['id'];
                    $model = (string) $modelRow['model_name'];
                } elseif ($modelText !== '') {
                    $modelRow = vehicle_master_ensure_model($resolvedBrandId, $modelText, $vehicleType);
                    if ($modelRow !== null) {
                        $resolvedModelId = (int) $modelRow['id'];
                        $model = (string) $modelRow['model_name'];
                    } else {
                        $model = mb_substr($modelText, 0, 100);
                    }
                }
            } else {
                $model = mb_substr($modelText, 0, 100);
            }

            if ($resolvedModelId !== null && $resolvedModelId > 0) {
                $variantRow = vehicle_master_get_variant($variantId, $resolvedModelId);
                if ($variantRow !== null) {
                    $resolvedVariantId = (int) $variantRow['id'];
                    $variant = (string) $variantRow['variant_name'];
                    if ($visVariantId <= 0 && !empty($variantRow['vis_variant_id'])) {
                        $visVariantId = (int) $variantRow['vis_variant_id'];
                    }
                } elseif ($variantText !== '') {
                    $variantRow = vehicle_master_ensure_variant($resolvedModelId, $variantText, $fuelType, null, $visVariantId > 0 ? $visVariantId : null);
                    if ($variantRow !== null) {
                        $resolvedVariantId = (int) $variantRow['id'];
                        $variant = (string) $variantRow['variant_name'];
                        if ($visVariantId <= 0 && !empty($variantRow['vis_variant_id'])) {
                            $visVariantId = (int) $variantRow['vis_variant_id'];
                        }
                    } else {
                        $variant = mb_substr($variantText, 0, 100);
                    }
                }
            } else {
                $variant = mb_substr($variantText, 0, 100);
            }

            $yearRow = vehicle_master_get_year($modelYearId);
            if ($yearRow !== null) {
                $resolvedModelYearId = (int) $yearRow['id'];
                $modelYear = (int) $yearRow['year_value'];
            } elseif ($modelYearText !== '' && ctype_digit($modelYearText)) {
                $yearValue = (int) $modelYearText;
                if ($yearValue >= 1900 && $yearValue <= 2100) {
                    $yearRow = vehicle_master_ensure_year($yearValue);
                    if ($yearRow !== null) {
                        $resolvedModelYearId = (int) $yearRow['id'];
                        $modelYear = (int) $yearRow['year_value'];
                    } else {
                        $modelYear = $yearValue;
                    }
                }
            }

            $colorRow = vehicle_master_get_color($colorId);
            if ($colorRow !== null) {
                $resolvedColorId = (int) $colorRow['id'];
                $color = (string) $colorRow['color_name'];
            } elseif ($colorText !== '') {
                $colorRow = vehicle_master_ensure_color($colorText);
                if ($colorRow !== null) {
                    $resolvedColorId = (int) $colorRow['id'];
                    $color = (string) $colorRow['color_name'];
                } else {
                    $color = mb_substr($colorText, 0, 40);
                }
            }
        } else {
            $brand = mb_substr($brandText, 0, 80);
            $model = mb_substr($modelText, 0, 100);
            $variant = mb_substr($variantText, 0, 100);
            if ($modelYearText !== '' && ctype_digit($modelYearText)) {
                $modelYear = (int) $modelYearText;
            }
            $color = mb_substr($colorText, 0, 40);
        }

        if ($customerId <= 0 || $registrationNo === '' || $brand === '' || $model === '') {
            flash_set('vehicle_error', 'Customer, registration number, brand and model are required.', 'danger');
            redirect('modules/vehicles/index.php');
        }

        $customerStmt = db()->prepare(
            'SELECT id
             FROM customers
             WHERE id = :id
               AND company_id = :company_id
               AND status_code <> "DELETED"
             LIMIT 1'
        );
        $customerStmt->execute([
            'id' => $customerId,
            'company_id' => $companyId,
        ]);

        if (!$customerStmt->fetch()) {
            flash_set('vehicle_error', 'Invalid customer selected.', 'danger');
            redirect('modules/vehicles/index.php');
        }

        $visVariantName = null;
        if ($visVariantId > 0) {
            try {
                $visStmt = db()->prepare(
                    'SELECT vv.id, vv.variant_name, vv.fuel_type, vm.model_name, vb.brand_name
                     FROM vis_variants vv
                     INNER JOIN vis_models vm ON vm.id = vv.model_id
                     INNER JOIN vis_brands vb ON vb.id = vm.brand_id
                     WHERE vv.id = :id
                       AND vv.status_code = "ACTIVE"
                       AND vm.status_code = "ACTIVE"
                       AND vb.status_code = "ACTIVE"
                     LIMIT 1'
                );
                $visStmt->execute(['id' => $visVariantId]);
                $visVariant = $visStmt->fetch();

                if (!$visVariant) {
                    $visVariantId = 0;
                } else {
                    $visVariantName = (string) $visVariant['brand_name'] . ' / ' . (string) $visVariant['model_name'] . ' / ' . (string) $visVariant['variant_name'];
                }
            } catch (Throwable $exception) {
                $visVariantId = 0;
            }
        }

        $isActive = $statusCode === 'ACTIVE' ? 1 : 0;

        if ($action === 'create') {
            try {
                if ($vehicleAttributeEnabled) {
                    $insertStmt = db()->prepare(
                        'INSERT INTO vehicles
                          (company_id, customer_id, registration_no, vehicle_type, brand, brand_id, model, model_id, variant, variant_id, fuel_type, model_year, model_year_id, color, color_id, chassis_no, engine_no, notes, vis_variant_id, is_active, status_code, deleted_at)
                         VALUES
                          (:company_id, :customer_id, :registration_no, :vehicle_type, :brand, :brand_id, :model, :model_id, :variant, :variant_id, :fuel_type, :model_year, :model_year_id, :color, :color_id, :chassis_no, :engine_no, :notes, :vis_variant_id, :is_active, :status_code, :deleted_at)'
                    );
                    $insertStmt->execute([
                        'company_id' => $companyId,
                        'customer_id' => $customerId,
                        'registration_no' => $registrationNo,
                        'vehicle_type' => $vehicleType,
                        'brand' => $brand,
                        'brand_id' => $resolvedBrandId !== null && $resolvedBrandId > 0 ? $resolvedBrandId : null,
                        'model' => $model,
                        'model_id' => $resolvedModelId !== null && $resolvedModelId > 0 ? $resolvedModelId : null,
                        'variant' => $variant !== '' ? $variant : null,
                        'variant_id' => $resolvedVariantId !== null && $resolvedVariantId > 0 ? $resolvedVariantId : null,
                        'fuel_type' => $fuelType,
                        'model_year' => $modelYear > 0 ? $modelYear : null,
                        'model_year_id' => $resolvedModelYearId !== null && $resolvedModelYearId > 0 ? $resolvedModelYearId : null,
                        'color' => $color !== '' ? $color : null,
                        'color_id' => $resolvedColorId !== null && $resolvedColorId > 0 ? $resolvedColorId : null,
                        'chassis_no' => $chassisNo !== '' ? $chassisNo : null,
                        'engine_no' => $engineNo !== '' ? $engineNo : null,
                        'notes' => $notes !== '' ? $notes : null,
                        'vis_variant_id' => $visVariantId > 0 ? $visVariantId : null,
                        'is_active' => $isActive,
                        'status_code' => $statusCode,
                        'deleted_at' => $statusCode === 'DELETED' ? date('Y-m-d H:i:s') : null,
                    ]);
                } else {
                    $insertStmt = db()->prepare(
                        'INSERT INTO vehicles
                          (company_id, customer_id, registration_no, vehicle_type, brand, model, variant, fuel_type, model_year, color, chassis_no, engine_no, notes, vis_variant_id, is_active, status_code, deleted_at)
                         VALUES
                          (:company_id, :customer_id, :registration_no, :vehicle_type, :brand, :model, :variant, :fuel_type, :model_year, :color, :chassis_no, :engine_no, :notes, :vis_variant_id, :is_active, :status_code, :deleted_at)'
                    );
                    $insertStmt->execute([
                        'company_id' => $companyId,
                        'customer_id' => $customerId,
                        'registration_no' => $registrationNo,
                        'vehicle_type' => $vehicleType,
                        'brand' => $brand,
                        'model' => $model,
                        'variant' => $variant !== '' ? $variant : null,
                        'fuel_type' => $fuelType,
                        'model_year' => $modelYear > 0 ? $modelYear : null,
                        'color' => $color !== '' ? $color : null,
                        'chassis_no' => $chassisNo !== '' ? $chassisNo : null,
                        'engine_no' => $engineNo !== '' ? $engineNo : null,
                        'notes' => $notes !== '' ? $notes : null,
                        'vis_variant_id' => $visVariantId > 0 ? $visVariantId : null,
                        'is_active' => $isActive,
                        'status_code' => $statusCode,
                        'deleted_at' => $statusCode === 'DELETED' ? date('Y-m-d H:i:s') : null,
                    ]);
                }

                $createdVehicleId = (int) db()->lastInsertId();
                add_vehicle_history($createdVehicleId, 'CREATE', 'Vehicle created', [
                    'registration_no' => $registrationNo,
                    'status_code' => $statusCode,
                    'vis_variant' => $visVariantName,
                ]);
                log_audit('vehicles', 'create', $createdVehicleId, 'Created vehicle ' . $registrationNo);

                flash_set('vehicle_success', 'Vehicle created successfully.', 'success');
            } catch (Throwable $exception) {
                flash_set('vehicle_error', 'Unable to create vehicle. Registration number must be unique.', 'danger');
            }
        }

        if ($action === 'update') {
            if ($vehicleId <= 0) {
                flash_set('vehicle_error', 'Invalid vehicle selected for update.', 'danger');
                redirect('modules/vehicles/index.php');
            }

            try {
                if ($vehicleAttributeEnabled) {
                    $updateStmt = db()->prepare(
                        'UPDATE vehicles
                         SET customer_id = :customer_id,
                             registration_no = :registration_no,
                             vehicle_type = :vehicle_type,
                             brand = :brand,
                             brand_id = :brand_id,
                             model = :model,
                             model_id = :model_id,
                             variant = :variant,
                             variant_id = :variant_id,
                             fuel_type = :fuel_type,
                             model_year = :model_year,
                             model_year_id = :model_year_id,
                             color = :color,
                             color_id = :color_id,
                             chassis_no = :chassis_no,
                             engine_no = :engine_no,
                             notes = :notes,
                             vis_variant_id = :vis_variant_id,
                             is_active = :is_active,
                             status_code = :status_code,
                             deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
                         WHERE id = :id
                           AND company_id = :company_id'
                    );
                    $updateStmt->execute([
                        'customer_id' => $customerId,
                        'registration_no' => $registrationNo,
                        'vehicle_type' => $vehicleType,
                        'brand' => $brand,
                        'brand_id' => $resolvedBrandId !== null && $resolvedBrandId > 0 ? $resolvedBrandId : null,
                        'model' => $model,
                        'model_id' => $resolvedModelId !== null && $resolvedModelId > 0 ? $resolvedModelId : null,
                        'variant' => $variant !== '' ? $variant : null,
                        'variant_id' => $resolvedVariantId !== null && $resolvedVariantId > 0 ? $resolvedVariantId : null,
                        'fuel_type' => $fuelType,
                        'model_year' => $modelYear > 0 ? $modelYear : null,
                        'model_year_id' => $resolvedModelYearId !== null && $resolvedModelYearId > 0 ? $resolvedModelYearId : null,
                        'color' => $color !== '' ? $color : null,
                        'color_id' => $resolvedColorId !== null && $resolvedColorId > 0 ? $resolvedColorId : null,
                        'chassis_no' => $chassisNo !== '' ? $chassisNo : null,
                        'engine_no' => $engineNo !== '' ? $engineNo : null,
                        'notes' => $notes !== '' ? $notes : null,
                        'vis_variant_id' => $visVariantId > 0 ? $visVariantId : null,
                        'is_active' => $isActive,
                        'status_code' => $statusCode,
                        'id' => $vehicleId,
                        'company_id' => $companyId,
                    ]);
                } else {
                    $updateStmt = db()->prepare(
                        'UPDATE vehicles
                         SET customer_id = :customer_id,
                             registration_no = :registration_no,
                             vehicle_type = :vehicle_type,
                             brand = :brand,
                             model = :model,
                             variant = :variant,
                             fuel_type = :fuel_type,
                             model_year = :model_year,
                             color = :color,
                             chassis_no = :chassis_no,
                             engine_no = :engine_no,
                             notes = :notes,
                             vis_variant_id = :vis_variant_id,
                             is_active = :is_active,
                             status_code = :status_code,
                             deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
                         WHERE id = :id
                           AND company_id = :company_id'
                    );
                    $updateStmt->execute([
                        'customer_id' => $customerId,
                        'registration_no' => $registrationNo,
                        'vehicle_type' => $vehicleType,
                        'brand' => $brand,
                        'model' => $model,
                        'variant' => $variant !== '' ? $variant : null,
                        'fuel_type' => $fuelType,
                        'model_year' => $modelYear > 0 ? $modelYear : null,
                        'color' => $color !== '' ? $color : null,
                        'chassis_no' => $chassisNo !== '' ? $chassisNo : null,
                        'engine_no' => $engineNo !== '' ? $engineNo : null,
                        'notes' => $notes !== '' ? $notes : null,
                        'vis_variant_id' => $visVariantId > 0 ? $visVariantId : null,
                        'is_active' => $isActive,
                        'status_code' => $statusCode,
                        'id' => $vehicleId,
                        'company_id' => $companyId,
                    ]);
                }

                add_vehicle_history($vehicleId, 'UPDATE', 'Vehicle details updated', [
                    'registration_no' => $registrationNo,
                    'status_code' => $statusCode,
                    'vis_variant' => $visVariantName,
                ]);
                log_audit('vehicles', 'update', $vehicleId, 'Updated vehicle ' . $registrationNo);

                flash_set('vehicle_success', 'Vehicle updated successfully.', 'success');
            } catch (Throwable $exception) {
                flash_set('vehicle_error', 'Unable to update vehicle. Registration number must be unique.', 'danger');
            }
        }

        redirect('modules/vehicles/index.php');
    }

    if ($action === 'change_status') {
        $vehicleId = post_int('vehicle_id');
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));

        if ($vehicleId <= 0) {
            flash_set('vehicle_error', 'Invalid vehicle selected.', 'danger');
            redirect('modules/vehicles/index.php');
        }

        $isActive = $nextStatus === 'ACTIVE' ? 1 : 0;
        $stmt = db()->prepare(
            'UPDATE vehicles
             SET is_active = :is_active,
                 status_code = :status_code,
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'is_active' => $isActive,
            'status_code' => $nextStatus,
            'id' => $vehicleId,
            'company_id' => $companyId,
        ]);

        add_vehicle_history($vehicleId, 'STATUS', 'Status changed to ' . $nextStatus, [
            'status_code' => $nextStatus,
        ]);
        log_audit('vehicles', 'status', $vehicleId, 'Changed vehicle status to ' . $nextStatus);

        flash_set('vehicle_success', 'Vehicle status updated.', 'success');
        redirect('modules/vehicles/index.php');
    }
}

$customersStmt = db()->prepare(
    'SELECT id, full_name, phone
     FROM customers
     WHERE company_id = :company_id
       AND status_code = "ACTIVE"
     ORDER BY full_name ASC'
);
$customersStmt->execute(['company_id' => $companyId]);
$customers = $customersStmt->fetchAll();

$visVariants = [];
try {
    $visStmt = db()->query(
        'SELECT vv.id, vv.variant_name, vv.fuel_type, vm.model_name, vb.brand_name
         FROM vis_variants vv
         INNER JOIN vis_models vm ON vm.id = vv.model_id
         INNER JOIN vis_brands vb ON vb.id = vm.brand_id
         WHERE vv.status_code = "ACTIVE"
           AND vm.status_code = "ACTIVE"
           AND vb.status_code = "ACTIVE"
         ORDER BY vb.brand_name ASC, vm.model_name ASC, vv.variant_name ASC
         LIMIT 500'
    );
    $visVariants = $visStmt->fetchAll();
} catch (Throwable $exception) {
    $visVariants = [];
}
$hasVisData = !empty($visVariants);

$editId = get_int('edit_id');
$editVehicle = null;
if ($editId > 0) {
    $editStmt = db()->prepare('SELECT * FROM vehicles WHERE id = :id AND company_id = :company_id LIMIT 1');
    $editStmt->execute([
        'id' => $editId,
        'company_id' => $companyId,
    ]);
    $editVehicle = $editStmt->fetch() ?: null;
}

$editAttrIds = [
    'brand_id' => 0,
    'model_id' => 0,
    'variant_id' => 0,
    'model_year_id' => 0,
    'color_id' => 0,
];
if ($vehicleAttributeEnabled && $editVehicle) {
    $editAttrIds['brand_id'] = (int) ($editVehicle['brand_id'] ?? 0);
    $editAttrIds['model_id'] = (int) ($editVehicle['model_id'] ?? 0);
    $editAttrIds['variant_id'] = (int) ($editVehicle['variant_id'] ?? 0);
    $editAttrIds['model_year_id'] = (int) ($editVehicle['model_year_id'] ?? 0);
    $editAttrIds['color_id'] = (int) ($editVehicle['color_id'] ?? 0);

    if ($editAttrIds['brand_id'] <= 0 && trim((string) ($editVehicle['brand'] ?? '')) !== '') {
        $brandRow = vehicle_master_ensure_brand((string) $editVehicle['brand']);
        $editAttrIds['brand_id'] = (int) ($brandRow['id'] ?? 0);
    }
    if ($editAttrIds['model_id'] <= 0 && $editAttrIds['brand_id'] > 0 && trim((string) ($editVehicle['model'] ?? '')) !== '') {
        $modelRow = vehicle_master_ensure_model($editAttrIds['brand_id'], (string) $editVehicle['model'], (string) ($editVehicle['vehicle_type'] ?? '4W'));
        $editAttrIds['model_id'] = (int) ($modelRow['id'] ?? 0);
    }
    if ($editAttrIds['variant_id'] <= 0 && $editAttrIds['model_id'] > 0 && trim((string) ($editVehicle['variant'] ?? '')) !== '') {
        $variantRow = vehicle_master_ensure_variant(
            $editAttrIds['model_id'],
            (string) $editVehicle['variant'],
            (string) ($editVehicle['fuel_type'] ?? 'PETROL'),
            null,
            isset($editVehicle['vis_variant_id']) ? (int) $editVehicle['vis_variant_id'] : null
        );
        $editAttrIds['variant_id'] = (int) ($variantRow['id'] ?? 0);
    }
    if ($editAttrIds['model_year_id'] <= 0 && !empty($editVehicle['model_year'])) {
        $yearRow = vehicle_master_ensure_year((int) $editVehicle['model_year']);
        $editAttrIds['model_year_id'] = (int) ($yearRow['id'] ?? 0);
    }
    if ($editAttrIds['color_id'] <= 0 && trim((string) ($editVehicle['color'] ?? '')) !== '') {
        $colorRow = vehicle_master_ensure_color((string) $editVehicle['color']);
        $editAttrIds['color_id'] = (int) ($colorRow['id'] ?? 0);
    }
}

$historyVehicleId = get_int('history_id');
$vehicleHistory = [];
if ($historyVehicleId > 0) {
    if ($jobOdometerEnabled) {
        $historyStmt = db()->prepare(
            'SELECT h.event_at AS created_at, h.action_type, h.action_note, h.created_by_name, h.odometer_km, h.source_label
             FROM (
                SELECT vh.created_at AS event_at,
                       vh.action_type,
                       vh.action_note,
                       u.name AS created_by_name,
                       NULL AS odometer_km,
                       "VEHICLE_MASTER" AS source_label
                FROM vehicle_history vh
                LEFT JOIN vehicles v ON v.id = vh.vehicle_id
                LEFT JOIN users u ON u.id = vh.created_by
                WHERE vh.vehicle_id = :vehicle_id
                  AND v.company_id = :company_id

                UNION ALL

                SELECT COALESCE(jc.opened_at, jc.created_at, jc.updated_at) AS event_at,
                       "JOB_ODOMETER" AS action_type,
                       CONCAT("Job ", jc.job_number, " (", jc.status, ")") AS action_note,
                       u2.name AS created_by_name,
                       jc.odometer_km AS odometer_km,
                       "JOB_CARD" AS source_label
                FROM job_cards jc
                INNER JOIN vehicles v2 ON v2.id = jc.vehicle_id
                LEFT JOIN users u2 ON u2.id = jc.created_by
                WHERE jc.vehicle_id = :vehicle_id
                  AND jc.company_id = :company_id
                  AND jc.status_code <> "DELETED"
                  AND v2.company_id = :company_id
             ) h
             ORDER BY h.event_at DESC
             LIMIT 50'
        );
    } else {
        $historyStmt = db()->prepare(
            'SELECT vh.created_at, vh.action_type, vh.action_note, u.name AS created_by_name,
                    NULL AS odometer_km, "VEHICLE_MASTER" AS source_label
             FROM vehicle_history vh
             LEFT JOIN vehicles v ON v.id = vh.vehicle_id
             LEFT JOIN users u ON u.id = vh.created_by
             WHERE vh.vehicle_id = :vehicle_id
               AND v.company_id = :company_id
             ORDER BY vh.id DESC
             LIMIT 30'
        );
    }
    $historyStmt->execute([
        'vehicle_id' => $historyVehicleId,
        'company_id' => $companyId,
    ]);
    $vehicleHistory = $historyStmt->fetchAll();
}

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = strtoupper(trim((string) ($_GET['status'] ?? '')));
$brandFilterId = get_int('vehicle_filter_brand_id');
$modelFilterId = get_int('vehicle_filter_model_id');
$variantFilterId = get_int('vehicle_filter_variant_id');
$modelYearFilterId = get_int('vehicle_filter_model_year_id');
$colorFilterId = get_int('vehicle_filter_color_id');
$customerFilterId = get_int('vehicle_filter_customer_id');
$fuelTypeFilter = strtoupper(trim((string) ($_GET['vehicle_filter_fuel_type'] ?? '')));
if (!in_array($fuelTypeFilter, $allowedFuelTypes, true)) {
    $fuelTypeFilter = '';
}
$lastServiceFromFilter = trim((string) ($_GET['last_service_from'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastServiceFromFilter)) {
    $lastServiceFromFilter = '';
}
$lastServiceToFilter = trim((string) ($_GET['last_service_to'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastServiceToFilter)) {
    $lastServiceToFilter = '';
}
$allowedStatuses = ['ACTIVE', 'INACTIVE', 'DELETED', 'ALL'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$whereParts = ['v.company_id = :company_id'];
$params = ['company_id' => $companyId];

if ($search !== '') {
    $whereParts[] = '(v.registration_no LIKE :query OR v.brand LIKE :query OR v.model LIKE :query OR v.variant LIKE :query OR c.full_name LIKE :query)';
    $params['query'] = '%' . $search . '%';
}

if ($statusFilter === '') {
    $whereParts[] = 'v.status_code <> "DELETED"';
} elseif ($statusFilter !== 'ALL') {
    $whereParts[] = 'v.status_code = :status_code';
    $params['status_code'] = $statusFilter;
}

if ($vehicleAttributeEnabled) {
    if ($brandFilterId > 0) {
        $whereParts[] = 'v.brand_id = :brand_filter_id';
        $params['brand_filter_id'] = $brandFilterId;
    }
    if ($modelFilterId > 0) {
        $whereParts[] = 'v.model_id = :model_filter_id';
        $params['model_filter_id'] = $modelFilterId;
    }
    if ($variantFilterId > 0) {
        $whereParts[] = 'v.variant_id = :variant_filter_id';
        $params['variant_filter_id'] = $variantFilterId;
    }
    if ($modelYearFilterId > 0) {
        $whereParts[] = 'v.model_year_id = :model_year_filter_id';
        $params['model_year_filter_id'] = $modelYearFilterId;
    }
    if ($colorFilterId > 0) {
        $whereParts[] = 'v.color_id = :color_filter_id';
        $params['color_filter_id'] = $colorFilterId;
    }
}

$historyCountExpr = '(SELECT COUNT(*) FROM vehicle_history h WHERE h.vehicle_id = v.id)';
if ($jobOdometerEnabled) {
  $historyCountExpr =
    '(
      (SELECT COUNT(*) FROM vehicle_history vh WHERE vh.vehicle_id = v.id)
      +
      (SELECT COUNT(*) FROM job_cards jc WHERE jc.vehicle_id = v.id AND jc.company_id = v.company_id AND jc.status_code <> "DELETED")
    )';
}

$vehicleSql =
    'SELECT v.*, c.full_name AS customer_name, c.phone AS customer_phone,
            (SELECT COUNT(*) FROM job_cards jc WHERE jc.vehicle_id = v.id) AS service_count,
            ' . $historyCountExpr . ' AS history_count,
            vv.variant_name AS vis_variant_name, vm.model_name AS vis_model_name, vb.brand_name AS vis_brand_name
     FROM vehicles v
     INNER JOIN customers c ON c.id = v.customer_id
     LEFT JOIN vis_variants vv ON vv.id = v.vis_variant_id
     LEFT JOIN vis_models vm ON vm.id = vv.model_id
     LEFT JOIN vis_brands vb ON vb.id = vm.brand_id
     WHERE ' . implode(' AND ', $whereParts) . '
     ORDER BY v.id DESC';

$vehiclesStmt = db()->prepare($vehicleSql);
$vehiclesStmt->execute($params);
$vehicles = $vehiclesStmt->fetchAll();
$attributeApiUrl = url('modules/vehicles/attributes_api.php');
$vehicleInsightsApiUrl = url('modules/vehicles/master_insights_api.php');

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Vehicle Master</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Vehicle Master</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if ($canManage): ?>
        <?php if (!$vehicleAttributeEnabled): ?>
          <div class="alert alert-warning">
            Vehicle Attribute Masters are not enabled in the database yet. Run `database/vehicle_attribute_masters_upgrade.sql` to activate cascading dropdowns.
          </div>
        <?php endif; ?>
        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title"><?= $editVehicle ? 'Edit Vehicle' : 'Add Vehicle'; ?></h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="<?= $editVehicle ? 'update' : 'create'; ?>" />
              <input type="hidden" name="vehicle_id" value="<?= (int) ($editVehicle['id'] ?? 0); ?>" />

              <div class="col-md-4">
                <label class="form-label">Customer</label>
                <select name="customer_id" class="form-select" required>
                  <option value="">Select Customer</option>
                  <?php foreach ($customers as $customer): ?>
                    <option value="<?= (int) $customer['id']; ?>" <?= ((int) ($editVehicle['customer_id'] ?? 0) === (int) $customer['id']) ? 'selected' : ''; ?>>
                      <?= e((string) $customer['full_name']); ?> (<?= e((string) $customer['phone']); ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Registration No</label>
                <input type="text" name="registration_no" class="form-control" required value="<?= e((string) ($editVehicle['registration_no'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Vehicle Type</label>
                <select name="vehicle_type" class="form-select" required>
                  <?php foreach ($allowedVehicleTypes as $type): ?>
                    <option value="<?= e($type); ?>" <?= ((string) ($editVehicle['vehicle_type'] ?? '4W') === $type) ? 'selected' : ''; ?>><?= e($type); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Fuel Type</label>
                <select name="fuel_type" class="form-select" required>
                  <?php foreach ($allowedFuelTypes as $fuel): ?>
                    <option value="<?= e($fuel); ?>" <?= ((string) ($editVehicle['fuel_type'] ?? 'PETROL') === $fuel) ? 'selected' : ''; ?>><?= e($fuel); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status_code" class="form-select" required>
                  <?php foreach (status_options((string) ($editVehicle['status_code'] ?? 'ACTIVE')) as $option): ?>
                    <option value="<?= e($option['value']); ?>" <?= $option['selected'] ? 'selected' : ''; ?>><?= e($option['value']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <?php if ($vehicleAttributeEnabled): ?>
                <div class="col-12" data-vehicle-attributes-root="1" data-vehicle-attributes-mode="entry" data-vehicle-attributes-endpoint="<?= e($attributeApiUrl); ?>">
                  <div class="row g-3">
                    <div class="col-md-8">
                      <label class="form-label">Brand / Model / Variant</label>
                      <select
                        name="vehicle_combo_selector"
                        data-vehicle-attr="combo"
                        class="form-select"
                        required
                        data-selected-label="<?= e(trim((string) ($editVehicle['brand'] ?? '') . ' -> ' . (string) ($editVehicle['model'] ?? '') . ' -> ' . (string) ($editVehicle['variant'] ?? ''))); ?>"
                      >
                        <option value="">Loading vehicle combinations...</option>
                      </select>
                      <input type="hidden" name="brand_id" data-vehicle-attr-id="brand" value="<?= e($editAttrIds['brand_id'] > 0 ? (string) $editAttrIds['brand_id'] : ''); ?>" />
                      <input type="hidden" name="model_id" data-vehicle-attr-id="model" value="<?= e($editAttrIds['model_id'] > 0 ? (string) $editAttrIds['model_id'] : ''); ?>" />
                      <input type="hidden" name="variant_id" data-vehicle-attr-id="variant" value="<?= e($editAttrIds['variant_id'] > 0 ? (string) $editAttrIds['variant_id'] : ''); ?>" />
                    </div>

                    <div class="col-md-2">
                      <label class="form-label">Model Year</label>
                      <select name="model_year_id" data-vehicle-attr="model_year" class="form-select" data-selected-id="<?= e($editAttrIds['model_year_id'] > 0 ? (string) $editAttrIds['model_year_id'] : ((!empty($editVehicle['model_year'])) ? '__custom__' : '')); ?>">
                        <option value="">Loading Years...</option>
                      </select>
                    </div>
                    <div class="col-md-2">
                      <label class="form-label">Color</label>
                      <select name="color_id" data-vehicle-attr="color" class="form-select" data-selected-id="<?= e($editAttrIds['color_id'] > 0 ? (string) $editAttrIds['color_id'] : ((trim((string) ($editVehicle['color'] ?? '')) !== '') ? '__custom__' : '')); ?>">
                        <option value="">Loading Colors...</option>
                      </select>
                    </div>

                    <div class="col-md-4" data-vehicle-fallback-wrap="brand" style="display:none;">
                      <label class="form-label">Brand (Manual)</label>
                      <input type="text" name="brand_text" data-vehicle-fallback="brand" class="form-control" maxlength="100" value="<?= e((string) ($editVehicle['brand'] ?? '')); ?>" />
                    </div>
                    <div class="col-md-4" data-vehicle-fallback-wrap="model" style="display:none;">
                      <label class="form-label">Model (Manual)</label>
                      <input type="text" name="model_text" data-vehicle-fallback="model" class="form-control" maxlength="120" value="<?= e((string) ($editVehicle['model'] ?? '')); ?>" />
                    </div>
                    <div class="col-md-4" data-vehicle-fallback-wrap="variant" style="display:none;">
                      <label class="form-label">Variant (Manual)</label>
                      <input type="text" name="variant_text" data-vehicle-fallback="variant" class="form-control" maxlength="150" value="<?= e((string) ($editVehicle['variant'] ?? '')); ?>" />
                    </div>
                    <div class="col-md-2" data-vehicle-fallback-wrap="model_year" style="display:none;">
                      <label class="form-label">Model Year (Manual)</label>
                      <input type="number" name="model_year_text" data-vehicle-fallback="model_year" class="form-control" min="1900" max="2100" value="<?= e((string) ($editVehicle['model_year'] ?? '')); ?>" />
                    </div>
                    <div class="col-md-2" data-vehicle-fallback-wrap="color" style="display:none;">
                      <label class="form-label">Color (Manual)</label>
                      <input type="text" name="color_text" data-vehicle-fallback="color" class="form-control" maxlength="60" value="<?= e((string) ($editVehicle['color'] ?? '')); ?>" />
                    </div>
                  </div>
                  <div class="form-hint mt-2">
                    Search across Brand + Model + Variant in one dropdown. If a combination is missing, choose "Not listed" and enter manual text.
                  </div>
                </div>
              <?php else: ?>
                <div class="col-md-3">
                  <label class="form-label">Brand</label>
                  <input type="text" name="brand" class="form-control" required value="<?= e((string) ($editVehicle['brand'] ?? '')); ?>" />
                </div>
                <div class="col-md-3">
                  <label class="form-label">Model</label>
                  <input type="text" name="model" class="form-control" required value="<?= e((string) ($editVehicle['model'] ?? '')); ?>" />
                </div>
                <div class="col-md-3">
                  <label class="form-label">Variant</label>
                  <input type="text" name="variant" class="form-control" value="<?= e((string) ($editVehicle['variant'] ?? '')); ?>" />
                </div>
                <div class="col-md-3">
                  <label class="form-label">Model Year</label>
                  <input type="number" name="model_year" class="form-control" min="1990" max="2099" value="<?= e((string) ($editVehicle['model_year'] ?? '')); ?>" />
                </div>
                <div class="col-md-2">
                  <label class="form-label">Color</label>
                  <input type="text" name="color" class="form-control" value="<?= e((string) ($editVehicle['color'] ?? '')); ?>" />
                </div>
              <?php endif; ?>

              <div class="col-md-6">
                <label class="form-label">Chassis No</label>
                <input type="text" name="chassis_no" class="form-control" value="<?= e((string) ($editVehicle['chassis_no'] ?? '')); ?>" />
              </div>
              <div class="col-md-6">
                <label class="form-label">Engine No</label>
                <input type="text" name="engine_no" class="form-control" value="<?= e((string) ($editVehicle['engine_no'] ?? '')); ?>" />
              </div>

              <div class="col-md-8">
                <label class="form-label">VIS Variant (Optional)</label>
                <select name="vis_variant_id" class="form-select">
                  <option value="0">No VIS linkage</option>
                  <?php foreach ($visVariants as $variant): ?>
                    <option value="<?= (int) $variant['id']; ?>" <?= ((int) ($editVehicle['vis_variant_id'] ?? 0) === (int) $variant['id']) ? 'selected' : ''; ?>>
                      <?= e((string) $variant['brand_name']); ?> / <?= e((string) $variant['model_name']); ?> / <?= e((string) $variant['variant_name']); ?> (<?= e((string) $variant['fuel_type']); ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-hint">
                  <?= $hasVisData ? 'VIS data found: selecting a variant enables compatibility suggestions in downstream modules.' : 'No VIS catalog data available right now. Vehicle creation works normally without VIS.'; ?>
                </div>
              </div>
              <div class="col-md-4">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" class="form-control" value="<?= e((string) ($editVehicle['notes'] ?? '')); ?>" />
              </div>
            </div>
            <div class="card-footer d-flex gap-2">
              <button type="submit" class="btn btn-primary"><?= $editVehicle ? 'Update Vehicle' : 'Create Vehicle'; ?></button>
              <?php if ($editVehicle): ?>
                <a href="<?= e(url('modules/vehicles/index.php')); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="row g-3 mb-3">
        <div class="col-md-4 col-sm-6">
          <div class="small-box text-bg-primary mb-0">
            <div class="inner">
              <h3 data-stat-value="total_vehicles"><?= count($vehicles); ?></h3>
              <p>Total Vehicles</p>
            </div>
            <div class="small-box-icon"><i class="bi bi-car-front-fill"></i></div>
          </div>
        </div>
        <div class="col-md-4 col-sm-6">
          <div class="small-box text-bg-warning mb-0">
            <div class="inner">
              <h3 data-stat-value="vehicles_with_active_jobs">0</h3>
              <p>Vehicles With Active Jobs</p>
            </div>
            <div class="small-box-icon"><i class="bi bi-tools"></i></div>
          </div>
        </div>
        <div class="col-md-4 col-sm-6">
          <div class="small-box text-bg-success mb-0">
            <div class="inner">
              <h3 data-stat-value="recently_serviced_vehicles">0</h3>
              <p>Recently Serviced (Last 30 Days)</p>
            </div>
            <div class="small-box-icon"><i class="bi bi-check2-circle"></i></div>
          </div>
        </div>
      </div>

      <div class="card" data-master-insights-root="vehicles" data-master-insights-endpoint="<?= e($vehicleInsightsApiUrl); ?>">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">Vehicle List</h3>
          <span class="badge text-bg-light border" data-master-results-count="1"><?= count($vehicles); ?></span>
        </div>
        <div class="card-body border-bottom">
          <form method="get" class="row g-2 align-items-end" data-master-filter-form="1">
              <div class="col-md-4">
                <label class="form-label form-label-sm mb-1">Search</label>
                <input type="text" name="q" value="<?= e($search); ?>" class="form-control form-control-sm" placeholder="Registration / brand / model / customer" />
              </div>
              <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                  <option value="" <?= $statusFilter === '' ? 'selected' : ''; ?>>Active + Inactive</option>
                  <option value="ACTIVE" <?= $statusFilter === 'ACTIVE' ? 'selected' : ''; ?>>ACTIVE</option>
                  <option value="INACTIVE" <?= $statusFilter === 'INACTIVE' ? 'selected' : ''; ?>>INACTIVE</option>
                  <option value="DELETED" <?= $statusFilter === 'DELETED' ? 'selected' : ''; ?>>DELETED</option>
                  <option value="ALL" <?= $statusFilter === 'ALL' ? 'selected' : ''; ?>>ALL</option>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Customer</label>
                <select name="vehicle_filter_customer_id" class="form-select form-select-sm">
                  <option value="">All Customers</option>
                  <?php foreach ($customers as $customer): ?>
                    <option value="<?= (int) $customer['id']; ?>" <?= $customerFilterId === (int) $customer['id'] ? 'selected' : ''; ?>>
                      <?= e((string) $customer['full_name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Fuel Type</label>
                <select name="vehicle_filter_fuel_type" class="form-select form-select-sm">
                  <option value="" <?= $fuelTypeFilter === '' ? 'selected' : ''; ?>>All Fuel Types</option>
                  <?php foreach ($allowedFuelTypes as $fuel): ?>
                    <option value="<?= e($fuel); ?>" <?= $fuelTypeFilter === $fuel ? 'selected' : ''; ?>><?= e($fuel); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <?php if ($vehicleAttributeEnabled): ?>
                <div class="col-12" data-vehicle-attributes-root="1" data-vehicle-attributes-mode="filter" data-vehicle-attributes-endpoint="<?= e($attributeApiUrl); ?>">
                  <div class="row g-2">
                    <div class="col-md-4">
                      <label class="form-label form-label-sm mb-1">Brand / Model / Variant</label>
                      <select name="vehicle_filter_combo_selector" data-vehicle-attr="combo" class="form-select form-select-sm">
                        <option value="">All Brand / Model / Variant</option>
                      </select>
                      <input type="hidden" name="vehicle_filter_brand_id" data-vehicle-attr-id="brand" value="<?= e((string) $brandFilterId); ?>" />
                      <input type="hidden" name="vehicle_filter_model_id" data-vehicle-attr-id="model" value="<?= e((string) $modelFilterId); ?>" />
                      <input type="hidden" name="vehicle_filter_variant_id" data-vehicle-attr-id="variant" value="<?= e((string) $variantFilterId); ?>" />
                    </div>
                    <div class="col-md-2">
                      <label class="form-label form-label-sm mb-1">Year</label>
                      <select name="vehicle_filter_model_year_id" data-vehicle-attr="model_year" data-selected-id="<?= e((string) $modelYearFilterId); ?>" class="form-select form-select-sm">
                        <option value="">All Years</option>
                      </select>
                    </div>
                    <div class="col-md-2">
                      <label class="form-label form-label-sm mb-1">Color</label>
                      <select name="vehicle_filter_color_id" data-vehicle-attr="color" data-selected-id="<?= e((string) $colorFilterId); ?>" class="form-select form-select-sm">
                        <option value="">All Colors</option>
                      </select>
                    </div>
                  </div>
                </div>
              <?php endif; ?>

              <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Last Serviced From</label>
                <input type="date" name="last_service_from" value="<?= e($lastServiceFromFilter); ?>" class="form-control form-control-sm" />
              </div>
              <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Last Serviced To</label>
                <input type="date" name="last_service_to" value="<?= e($lastServiceToFilter); ?>" class="form-control form-control-sm" />
              </div>
              <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-outline-primary">Apply</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-master-filter-reset="1">Reset</button>
              </div>
          </form>
          <div class="alert alert-danger d-none mt-3 mb-0" data-master-insights-error="1"></div>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>ID</th>
                <th>Registration</th>
                <th>Vehicle</th>
                <th>Customer</th>
                <th>VIS Match</th>
                <th>Jobs</th>
                <th>History</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody data-master-table-body="1" data-table-colspan="9">
              <?php if (empty($vehicles)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No vehicles found.</td></tr>
              <?php else: ?>
                <?php foreach ($vehicles as $vehicle): ?>
                  <tr>
                    <td><?= (int) $vehicle['id']; ?></td>
                    <td><?= e((string) $vehicle['registration_no']); ?></td>
                    <td>
                      <?= e((string) $vehicle['brand']); ?> <?= e((string) $vehicle['model']); ?> <?= e((string) ($vehicle['variant'] ?? '')); ?><br>
                      <small class="text-muted"><?= e((string) $vehicle['vehicle_type']); ?> | <?= e((string) $vehicle['fuel_type']); ?></small>
                    </td>
                    <td>
                      <?= e((string) $vehicle['customer_name']); ?><br>
                      <small class="text-muted"><?= e((string) ($vehicle['customer_phone'] ?? '-')); ?></small>
                    </td>
                    <td>
                      <?php if ((int) ($vehicle['vis_variant_id'] ?? 0) > 0): ?>
                        <span class="badge text-bg-info">Linked</span><br>
                        <small class="text-muted"><?= e((string) (($vehicle['vis_brand_name'] ?? '-') . ' / ' . ($vehicle['vis_model_name'] ?? '-') . ' / ' . ($vehicle['vis_variant_name'] ?? '-'))); ?></small>
                      <?php else: ?>
                        <span class="badge text-bg-secondary">Not Linked</span>
                      <?php endif; ?>
                    </td>
                    <td><?= (int) $vehicle['service_count']; ?></td>
                    <td><?= (int) $vehicle['history_count']; ?></td>
                    <td><span class="badge text-bg-<?= e(status_badge_class((string) $vehicle['status_code'])); ?>"><?= e(record_status_label((string) $vehicle['status_code'])); ?></span></td>
                    <td class="d-flex gap-1">
                      <a class="btn btn-sm btn-outline-dark" href="<?= e(url('modules/vehicles/intelligence.php?id=' . (int) $vehicle['id'])); ?>">Intel</a>
                      <a class="btn btn-sm btn-outline-info" href="<?= e(url('modules/vehicles/index.php?history_id=' . (int) $vehicle['id'])); ?>">History</a>
                      <?php if ($canManage): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/vehicles/index.php?edit_id=' . (int) $vehicle['id'])); ?>">Edit</a>
                        <?php if ((string) $vehicle['status_code'] !== 'DELETED'): ?>
                          <form method="post" class="d-inline" data-confirm="Change vehicle status?">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="vehicle_id" value="<?= (int) $vehicle['id']; ?>" />
                            <input type="hidden" name="next_status" value="<?= e(((string) $vehicle['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>" />
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><?= ((string) $vehicle['status_code'] === 'ACTIVE') ? 'Inactivate' : 'Activate'; ?></button>
                          </form>
                          <form method="post" class="d-inline" data-confirm="Soft delete this vehicle?">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="vehicle_id" value="<?= (int) $vehicle['id']; ?>" />
                            <input type="hidden" name="next_status" value="DELETED" />
                            <button type="submit" class="btn btn-sm btn-outline-danger">Soft Delete</button>
                          </form>
                        <?php endif; ?>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php if ($historyVehicleId > 0): ?>
        <div class="card mt-3">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">Vehicle History #<?= (int) $historyVehicleId; ?></h3>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('modules/vehicles/index.php')); ?>">Close</a>
          </div>
          <div class="card-body table-responsive p-0">
            <table class="table table-sm table-striped mb-0">
              <thead>
                <tr>
                  <th>When</th>
                  <th>Action</th>
                  <th>Source</th>
                  <th>Odometer (KM)</th>
                  <th>Note</th>
                  <th>By</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($vehicleHistory)): ?>
                  <tr><td colspan="6" class="text-center text-muted py-4">No history found.</td></tr>
                <?php else: ?>
                  <?php foreach ($vehicleHistory as $history): ?>
                    <tr>
                      <td><?= e((string) $history['created_at']); ?></td>
                      <td><span class="badge text-bg-secondary"><?= e((string) $history['action_type']); ?></span></td>
                      <td><?= e((string) ($history['source_label'] ?? '-')); ?></td>
                      <td>
                        <?php if ($history['odometer_km'] !== null && $history['odometer_km'] !== ''): ?>
                          <?= e(number_format((float) $history['odometer_km'], 0)); ?>
                        <?php else: ?>
                          -
                        <?php endif; ?>
                      </td>
                      <td><?= e((string) ($history['action_note'] ?? '-')); ?></td>
                      <td><?= e((string) ($history['created_by_name'] ?? '-')); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
