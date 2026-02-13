<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();

header('Content-Type: text/html; charset=utf-8');

$canVehicleManage = has_permission('vehicle.view') && has_permission('vehicle.manage');
$canCustomerManage = has_permission('customer.view') && has_permission('customer.manage');
$companyId = active_company_id();

$prefillRegistration = strtoupper(mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 30));
$prefillCustomerId = (int) ($_GET['customer_id'] ?? 0);

if (!$canVehicleManage) {
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
?>

<form method="post" action="<?= e(url('modules/vehicles/ajax_create.php')); ?>" data-inline-vehicle-form="1">
  <?= csrf_field(); ?>
  <div class="row g-3">
    <div class="col-md-12">
      <label class="form-label">Customer (Existing)</label>
      <select name="customer_id" class="form-select">
        <option value="">Select Existing Customer</option>
        <?php foreach ($customers as $customer): ?>
          <option value="<?= (int) $customer['id']; ?>" <?= $prefillCustomerId === (int) $customer['id'] ? 'selected' : ''; ?>>
            <?= e((string) $customer['full_name']); ?> (<?= e((string) ($customer['phone'] ?? '')); ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <div class="form-hint">If customer is not available, fill New Customer details below.</div>
    </div>

    <div class="col-md-12">
      <div class="border rounded p-3">
        <div class="fw-semibold mb-2">New Customer Details (Optional)</div>
        <?php if ($canCustomerManage): ?>
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

    <div class="col-md-4">
      <label class="form-label">Registration No</label>
      <input type="text" name="registration_no" class="form-control" maxlength="30" required value="<?= e($prefillRegistration); ?>" />
    </div>
    <div class="col-md-4">
      <label class="form-label">Brand</label>
      <input type="text" name="brand_text" class="form-control" maxlength="100" placeholder="Optional (defaults to UNKNOWN)" />
    </div>
    <div class="col-md-4">
      <label class="form-label">Model</label>
      <input type="text" name="model_text" class="form-control" maxlength="120" placeholder="Optional (defaults to UNKNOWN)" />
    </div>
    <div class="col-md-3">
      <label class="form-label">Variant</label>
      <input type="text" name="variant_text" class="form-control" maxlength="150" />
    </div>
    <div class="col-md-3">
      <label class="form-label">Vehicle Type</label>
      <select name="vehicle_type" class="form-select">
        <option value="2W">2 Wheeler</option>
        <option value="4W" selected>4 Wheeler</option>
        <option value="COMMERCIAL">Commercial</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Fuel Type</label>
      <select name="fuel_type" class="form-select">
        <option value="PETROL" selected>PETROL</option>
        <option value="DIESEL">DIESEL</option>
        <option value="CNG">CNG</option>
        <option value="EV">EV</option>
        <option value="HYBRID">HYBRID</option>
        <option value="OTHER">OTHER</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Model Year</label>
      <input type="text" name="model_year_text" class="form-control" maxlength="4" placeholder="e.g. 2024" />
    </div>
    <div class="col-md-3">
      <label class="form-label">Color</label>
      <input type="text" name="color_text" class="form-control" maxlength="60" />
    </div>
    <div class="col-md-9">
      <label class="form-label">Notes</label>
      <input type="text" name="notes" class="form-control" maxlength="500" />
    </div>
  </div>
  <div class="mt-3 d-flex justify-content-end gap-2">
    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn btn-primary">Save Vehicle</button>
  </div>
</form>
