<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();

header('Content-Type: text/html; charset=utf-8');

$canManage = has_permission('customer.view') && has_permission('customer.manage');
$prefillName = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 150);

if (!$canManage) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mb-0">You do not have permission to create customers.</div>
    <?php
    exit;
}
?>

<form method="post" action="<?= e(url('modules/customers/ajax_create.php')); ?>" data-inline-customer-form="1">
  <?= csrf_field(); ?>
  <div class="row g-3">
    <div class="col-md-6">
      <label class="form-label">Full Name</label>
      <input type="text" name="full_name" class="form-control" maxlength="150" required value="<?= e($prefillName); ?>" />
    </div>
    <div class="col-md-3">
      <label class="form-label">Phone</label>
      <input type="text" name="phone" class="form-control" maxlength="20" required />
    </div>
    <div class="col-md-3">
      <label class="form-label">Alt Phone</label>
      <input type="text" name="alt_phone" class="form-control" maxlength="20" />
    </div>
    <div class="col-md-4">
      <label class="form-label">Email</label>
      <input type="email" name="email" class="form-control" maxlength="150" />
    </div>
    <div class="col-md-3">
      <label class="form-label">GSTIN</label>
      <input type="text" name="gstin" class="form-control" maxlength="15" />
    </div>
    <div class="col-md-3">
      <label class="form-label">City</label>
      <input type="text" name="city" class="form-control" maxlength="80" />
    </div>
    <div class="col-md-2">
      <label class="form-label">State</label>
      <input type="text" name="state" class="form-control" maxlength="80" />
    </div>
    <div class="col-md-8">
      <label class="form-label">Address</label>
      <input type="text" name="address_line1" class="form-control" maxlength="200" />
    </div>
    <div class="col-md-4">
      <label class="form-label">Notes</label>
      <input type="text" name="notes" class="form-control" maxlength="500" />
    </div>
  </div>
  <div class="mt-3 d-flex justify-content-end gap-2">
    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn btn-primary">Save Customer</button>
  </div>
</form>
