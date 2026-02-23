<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();

header('Content-Type: text/html; charset=utf-8');

$canVehicleManage = has_permission('vehicle.view') && has_permission('vehicle.manage');
$canCustomerManage = has_permission('customer.view') && has_permission('customer.manage');
$canJobCreate = has_permission('job.create') || has_permission('job.manage');
$canVehicleInlineCreate = $canVehicleManage || $canJobCreate;
$canCustomerInlineCreate = $canCustomerManage || $canJobCreate;
$companyId = active_company_id();
$vehicleAttributeEnabled = vehicle_masters_enabled() && vehicle_master_link_columns_supported();
$attributeApiUrl = url('modules/vehicles/attributes_api.php');

$allowedVehicleTypes = ['2W', '4W', 'COMMERCIAL'];
$allowedFuelTypes = ['PETROL', 'DIESEL', 'CNG', 'EV', 'HYBRID', 'OTHER'];

$prefillRegistration = strtoupper(mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 30));
$prefillCustomerId = (int) ($_GET['customer_id'] ?? 0);

if (!$canVehicleInlineCreate) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mb-0">You do not have permission to create vehicles.</div>
    <?php
    exit;
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
?>

<form method="post" action="<?= e(url('modules/vehicles/ajax_create.php')); ?>" data-inline-vehicle-form="1">
  <?= csrf_field(); ?>
  <div class="row g-3">
    <div class="col-md-12">
      <label class="form-label">Customer (Existing)</label>
      <select name="customer_id" class="form-select" data-inline-customer-add-disabled="1">
        <option value="">Select Existing Customer</option>
        <?php foreach ($customers as $customer): ?>
          <option value="<?= (int) $customer['id']; ?>" <?= $prefillCustomerId === (int) $customer['id'] ? 'selected' : ''; ?>>
            <?= e((string) $customer['full_name']); ?> (<?= e((string) ($customer['phone'] ?? '')); ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <div class="form-hint">Select existing customer, or leave empty and fill new customer details below.</div>
    </div>

    <div class="col-md-12" data-inline-new-customer-wrap="1">
      <div class="border rounded p-3">
        <div class="fw-semibold mb-2">New Customer Details</div>
        <?php if ($canCustomerInlineCreate): ?>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label">Full Name</label>
              <input type="text" name="new_customer_full_name" class="form-control" maxlength="150" />
            </div>
            <div class="col-md-3">
              <label class="form-label">Phone</label>
              <input type="text" name="new_customer_phone" class="form-control" maxlength="20" />
            </div>
            <div class="col-md-3">
              <label class="form-label">Alt Phone</label>
              <input type="text" name="new_customer_alt_phone" class="form-control" maxlength="20" />
            </div>
          </div>
        <?php else: ?>
          <div class="text-muted small">You can attach only existing customers because customer-create permission is not available.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-md-3">
      <label class="form-label">Registration No</label>
      <input type="text" name="registration_no" class="form-control" maxlength="30" required value="<?= e($prefillRegistration); ?>" />
    </div>
    <div class="col-md-2">
      <label class="form-label">Vehicle Type</label>
      <select name="vehicle_type" class="form-select" required>
        <?php foreach ($allowedVehicleTypes as $type): ?>
          <option value="<?= e($type); ?>" <?= $type === '4W' ? 'selected' : ''; ?>><?= e($type); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Fuel Type</label>
      <select name="fuel_type" class="form-select" required>
        <?php foreach ($allowedFuelTypes as $fuel): ?>
          <option value="<?= e($fuel); ?>" <?= $fuel === 'PETROL' ? 'selected' : ''; ?>><?= e($fuel); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php if ($vehicleAttributeEnabled): ?>
      <div class="col-12" data-vehicle-attributes-root="1" data-vehicle-attributes-mode="entry" data-vehicle-attributes-endpoint="<?= e($attributeApiUrl); ?>">
        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label">Brand / Model / Variant</label>
            <select name="vehicle_combo_selector" data-vehicle-attr="combo" class="form-select" required>
              <option value="">Loading vehicle combinations...</option>
            </select>
            <input type="hidden" name="brand_id" data-vehicle-attr-id="brand" value="" />
            <input type="hidden" name="model_id" data-vehicle-attr-id="model" value="" />
            <input type="hidden" name="variant_id" data-vehicle-attr-id="variant" value="" />
          </div>
          <div class="col-md-2">
            <label class="form-label">Model Year</label>
            <select name="model_year_id" data-vehicle-attr="model_year" class="form-select">
              <option value="">Loading Years...</option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Color</label>
            <select name="color_id" data-vehicle-attr="color" class="form-select">
              <option value="">Loading Colors...</option>
            </select>
          </div>

          <div class="col-md-4" data-vehicle-fallback-wrap="brand" style="display:none;">
            <label class="form-label">Brand (Manual)</label>
            <input type="text" name="brand_text" data-vehicle-fallback="brand" class="form-control" maxlength="100" />
          </div>
          <div class="col-md-4" data-vehicle-fallback-wrap="model" style="display:none;">
            <label class="form-label">Model (Manual)</label>
            <input type="text" name="model_text" data-vehicle-fallback="model" class="form-control" maxlength="120" />
          </div>
          <div class="col-md-4" data-vehicle-fallback-wrap="variant" style="display:none;">
            <label class="form-label">Variant (Manual)</label>
            <input type="text" name="variant_text" data-vehicle-fallback="variant" class="form-control" maxlength="150" />
          </div>
          <div class="col-md-2" data-vehicle-fallback-wrap="model_year" style="display:none;">
            <label class="form-label">Model Year (Manual)</label>
            <input type="number" name="model_year_text" data-vehicle-fallback="model_year" class="form-control" min="1900" max="2100" />
          </div>
          <div class="col-md-2" data-vehicle-fallback-wrap="color" style="display:none;">
            <label class="form-label">Color (Manual)</label>
            <input type="text" name="color_text" data-vehicle-fallback="color" class="form-control" maxlength="60" />
          </div>
        </div>
        <div class="form-hint mt-2">Search Brand + Model + Variant. If not listed, pick "Not listed" and enter manual values.</div>
      </div>
    <?php else: ?>
      <div class="col-md-4">
        <label class="form-label">Brand</label>
        <input type="text" name="brand" class="form-control" maxlength="100" required />
      </div>
      <div class="col-md-4">
        <label class="form-label">Model</label>
        <input type="text" name="model" class="form-control" maxlength="120" required />
      </div>
      <div class="col-md-4">
        <label class="form-label">Variant</label>
        <input type="text" name="variant" class="form-control" maxlength="150" />
      </div>
      <div class="col-md-2">
        <label class="form-label">Model Year</label>
        <input type="number" name="model_year" class="form-control" min="1900" max="2100" />
      </div>
      <div class="col-md-2">
        <label class="form-label">Color</label>
        <input type="text" name="color" class="form-control" maxlength="60" />
      </div>
    <?php endif; ?>

    <div class="col-md-6">
      <label class="form-label">Chassis No</label>
      <input type="text" name="chassis_no" class="form-control" maxlength="60" />
    </div>
    <div class="col-md-6">
      <label class="form-label">Engine No</label>
      <input type="text" name="engine_no" class="form-control" maxlength="60" />
    </div>

    <div class="col-md-8">
      <label class="form-label">VIS Variant (Optional)</label>
      <select name="vis_variant_id" class="form-select">
        <option value="0">No VIS linkage</option>
        <?php foreach ($visVariants as $variant): ?>
          <option value="<?= (int) $variant['id']; ?>">
            <?= e((string) $variant['brand_name']); ?> / <?= e((string) $variant['model_name']); ?> / <?= e((string) $variant['variant_name']); ?> (<?= e((string) $variant['fuel_type']); ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <div class="form-hint">
        <?= $hasVisData ? 'VIS data found: selecting a variant enables downstream compatibility suggestions.' : 'No VIS catalog data available right now. Vehicle creation works normally without VIS.'; ?>
      </div>
    </div>
    <div class="col-md-4">
      <label class="form-label">Notes</label>
      <input type="text" name="notes" class="form-control" maxlength="2000" />
    </div>
  </div>
  <div class="mt-3 d-flex justify-content-end gap-2">
    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn btn-primary">Save Vehicle</button>
  </div>
</form>
