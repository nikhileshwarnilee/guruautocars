<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function vehicle_inline_json(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    vehicle_inline_json([
        'ok' => false,
        'message' => 'Method not allowed.',
    ], 405);
}

require_csrf();

if (!has_permission('vehicle.view') || !has_permission('vehicle.manage')) {
    vehicle_inline_json([
        'ok' => false,
        'message' => 'You do not have permission to create vehicles.',
    ], 403);
}

$companyId = active_company_id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$canCustomerManage = has_permission('customer.view') && has_permission('customer.manage');
$vehicleAttributeEnabled = vehicle_masters_enabled() && vehicle_master_link_columns_supported();

$allowedVehicleTypes = ['2W', '4W', 'COMMERCIAL'];
$allowedFuelTypes = ['PETROL', 'DIESEL', 'CNG', 'EV', 'HYBRID', 'OTHER'];

$customerId = post_int('customer_id');
$newCustomerFullName = post_string('new_customer_full_name', 150);
$newCustomerPhone = post_string('new_customer_phone', 20);
$newCustomerAltPhone = post_string('new_customer_alt_phone', 20);

$registrationNo = strtoupper(post_string('registration_no', 30));
$vehicleType = strtoupper(trim((string) ($_POST['vehicle_type'] ?? '4W')));
$fuelType = strtoupper(trim((string) ($_POST['fuel_type'] ?? 'PETROL')));
$notes = post_string('notes', 500);

$brandInput = vehicle_master_normalize_text(post_string('brand_text', 100), 100);
$modelInput = vehicle_master_normalize_text(post_string('model_text', 120), 120);
$variantInput = vehicle_master_normalize_text(post_string('variant_text', 150), 150);
$modelYearText = vehicle_master_normalize_text(post_string('model_year_text', 4), 4);
$colorInput = vehicle_master_normalize_text(post_string('color_text', 60), 60);

if ($registrationNo === '') {
    vehicle_inline_json([
        'ok' => false,
        'message' => 'Registration number is required.',
    ], 422);
}

if (!in_array($vehicleType, $allowedVehicleTypes, true)) {
    $vehicleType = '4W';
}
if (!in_array($fuelType, $allowedFuelTypes, true)) {
    $fuelType = 'PETROL';
}

$brandProvided = $brandInput !== '';
$modelProvided = $modelInput !== '';
$brand = $brandProvided ? mb_substr($brandInput, 0, 80) : 'UNKNOWN';
$model = $modelProvided ? mb_substr($modelInput, 0, 100) : 'UNKNOWN';
$variant = $variantInput !== '' ? mb_substr($variantInput, 0, 100) : '';
$color = $colorInput !== '' ? mb_substr($colorInput, 0, 40) : '';

$modelYear = 0;
if ($modelYearText !== '' && ctype_digit($modelYearText)) {
    $year = (int) $modelYearText;
    if ($year >= 1900 && $year <= 2100) {
        $modelYear = $year;
    }
}

$customerRow = null;
if ($customerId > 0) {
    $customerStmt = db()->prepare(
        'SELECT id, full_name, phone
         FROM customers
         WHERE id = :id
           AND company_id = :company_id
           AND status_code = "ACTIVE"
         LIMIT 1'
    );
    $customerStmt->execute([
        'id' => $customerId,
        'company_id' => $companyId,
    ]);
    $customerRow = $customerStmt->fetch() ?: null;

    if ($customerRow === null) {
        vehicle_inline_json([
            'ok' => false,
            'message' => 'Selected customer is not valid.',
        ], 422);
    }
} else {
    if (!$canCustomerManage) {
        vehicle_inline_json([
            'ok' => false,
            'message' => 'Customer-create permission is required when no existing customer is selected.',
        ], 403);
    }
    if ($newCustomerFullName === '' || $newCustomerPhone === '') {
        vehicle_inline_json([
            'ok' => false,
            'message' => 'Select an existing customer or enter new customer name and phone.',
        ], 422);
    }
}

$pdo = db();
$pdo->beginTransaction();

try {
    if ($customerRow === null) {
        $insertCustomerStmt = $pdo->prepare(
            'INSERT INTO customers
              (company_id, created_by, full_name, phone, alt_phone, email, gstin, address_line1, city, state, notes, is_active, status_code, deleted_at)
             VALUES
              (:company_id, :created_by, :full_name, :phone, :alt_phone, NULL, NULL, NULL, NULL, NULL, NULL, 1, "ACTIVE", NULL)'
        );
        $insertCustomerStmt->execute([
            'company_id' => $companyId,
            'created_by' => $userId > 0 ? $userId : null,
            'full_name' => $newCustomerFullName,
            'phone' => $newCustomerPhone,
            'alt_phone' => $newCustomerAltPhone !== '' ? $newCustomerAltPhone : null,
        ]);

        $customerId = (int) $pdo->lastInsertId();
        $customerRow = [
            'id' => $customerId,
            'full_name' => $newCustomerFullName,
            'phone' => $newCustomerPhone,
        ];

        add_customer_history($customerId, 'CREATE', 'Customer created via vehicle inline modal', [
            'full_name' => $newCustomerFullName,
            'phone' => $newCustomerPhone,
            'status_code' => 'ACTIVE',
        ]);
        log_audit('customers', 'create_inline_vehicle', $customerId, 'Created customer via vehicle inline modal', [
            'entity' => 'customer',
            'source' => 'UI-AJAX',
            'after' => [
                'id' => $customerId,
                'full_name' => $newCustomerFullName,
                'phone' => $newCustomerPhone,
                'status_code' => 'ACTIVE',
            ],
        ]);
    }

    $resolvedBrandId = null;
    $resolvedModelId = null;
    $resolvedVariantId = null;
    $resolvedModelYearId = null;
    $resolvedColorId = null;

    if ($vehicleAttributeEnabled) {
        if ($brandProvided) {
            $brandRow = vehicle_master_ensure_brand($brand);
            if ($brandRow !== null) {
                $resolvedBrandId = (int) $brandRow['id'];
                $brand = (string) $brandRow['brand_name'];
            }
        }

        if ($modelProvided && $resolvedBrandId !== null && $resolvedBrandId > 0) {
            $modelRow = vehicle_master_ensure_model($resolvedBrandId, $model, $vehicleType);
            if ($modelRow !== null) {
                $resolvedModelId = (int) $modelRow['id'];
                $model = (string) $modelRow['model_name'];
            }
        }

        if ($variant !== '' && $resolvedModelId !== null && $resolvedModelId > 0) {
            $variantRow = vehicle_master_ensure_variant($resolvedModelId, $variant, $fuelType);
            if ($variantRow !== null) {
                $resolvedVariantId = (int) $variantRow['id'];
                $variant = (string) $variantRow['variant_name'];
            }
        }

        if ($modelYear > 0) {
            $yearRow = vehicle_master_ensure_year($modelYear);
            if ($yearRow !== null) {
                $resolvedModelYearId = (int) $yearRow['id'];
                $modelYear = (int) $yearRow['year_value'];
            }
        }

        if ($color !== '') {
            $colorRow = vehicle_master_ensure_color($color);
            if ($colorRow !== null) {
                $resolvedColorId = (int) $colorRow['id'];
                $color = (string) $colorRow['color_name'];
            }
        }
    }

    if ($vehicleAttributeEnabled) {
        $insertVehicleStmt = $pdo->prepare(
            'INSERT INTO vehicles
              (company_id, customer_id, registration_no, vehicle_type, brand, brand_id, model, model_id, variant, variant_id, fuel_type, model_year, model_year_id, color, color_id, notes, is_active, status_code, deleted_at)
             VALUES
              (:company_id, :customer_id, :registration_no, :vehicle_type, :brand, :brand_id, :model, :model_id, :variant, :variant_id, :fuel_type, :model_year, :model_year_id, :color, :color_id, :notes, 1, "ACTIVE", NULL)'
        );
        $insertVehicleStmt->execute([
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
            'notes' => $notes !== '' ? $notes : null,
        ]);
    } else {
        $insertVehicleStmt = $pdo->prepare(
            'INSERT INTO vehicles
              (company_id, customer_id, registration_no, vehicle_type, brand, model, variant, fuel_type, model_year, color, notes, is_active, status_code, deleted_at)
             VALUES
              (:company_id, :customer_id, :registration_no, :vehicle_type, :brand, :model, :variant, :fuel_type, :model_year, :color, :notes, 1, "ACTIVE", NULL)'
        );
        $insertVehicleStmt->execute([
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
            'notes' => $notes !== '' ? $notes : null,
        ]);
    }

    $vehicleId = (int) $pdo->lastInsertId();
    add_vehicle_history($vehicleId, 'CREATE', 'Vehicle created via inline modal', [
        'registration_no' => $registrationNo,
        'status_code' => 'ACTIVE',
    ]);
    log_audit('vehicles', 'create_inline', $vehicleId, 'Created vehicle ' . $registrationNo . ' via inline modal', [
        'entity' => 'vehicle',
        'source' => 'UI-AJAX',
        'after' => [
            'id' => $vehicleId,
            'customer_id' => $customerId,
            'registration_no' => $registrationNo,
            'brand' => $brand,
            'model' => $model,
            'status_code' => 'ACTIVE',
        ],
    ]);

    $pdo->commit();

    $customerLabel = (string) ($customerRow['full_name'] ?? '');
    $customerPhone = trim((string) ($customerRow['phone'] ?? ''));
    if ($customerPhone !== '') {
        $customerLabel .= ' (' . $customerPhone . ')';
    }

    $vehicleLabel = $registrationNo . ' - ' . trim($brand . ' ' . $model);

    vehicle_inline_json([
        'ok' => true,
        'message' => 'Vehicle created successfully.',
        'vehicle' => [
            'id' => $vehicleId,
            'customer_id' => $customerId,
            'registration_no' => $registrationNo,
            'brand' => $brand,
            'model' => $model,
            'label' => $vehicleLabel,
        ],
        'customer' => [
            'id' => $customerId,
            'full_name' => (string) ($customerRow['full_name'] ?? ''),
            'phone' => $customerPhone,
            'label' => $customerLabel,
        ],
    ]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $errorMessage = 'Unable to create vehicle right now.';
    $statusCode = 500;
    if (stripos($exception->getMessage(), 'duplicate') !== false || stripos($exception->getMessage(), 'unique') !== false) {
        $errorMessage = 'Unable to create vehicle. Registration number must be unique.';
        $statusCode = 422;
    }

    vehicle_inline_json([
        'ok' => false,
        'message' => $errorMessage,
    ], $statusCode);
}
