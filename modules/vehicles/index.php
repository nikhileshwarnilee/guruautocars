<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('vehicle.view');

$page_title = 'Vehicles';
$active_menu = 'vehicles';
$canManage = has_permission('vehicle.manage');
$companyId = active_company_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    require_csrf();
    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'create') {
        $customerId = post_int('customer_id');
        $registrationNo = strtoupper(post_string('registration_no', 30));
        $vehicleType = (string) ($_POST['vehicle_type'] ?? '4W');
        $brand = post_string('brand', 80);
        $model = post_string('model', 100);
        $variant = post_string('variant', 100);
        $fuelType = (string) ($_POST['fuel_type'] ?? 'PETROL');
        $modelYear = post_int('model_year');
        $color = post_string('color', 40);
        $chassisNo = post_string('chassis_no', 60);
        $engineNo = post_string('engine_no', 60);
        $odometer = post_int('odometer_km');
        $notes = post_string('notes', 1000);

        $allowedVehicleTypes = ['2W', '4W', 'COMMERCIAL'];
        $allowedFuelTypes = ['PETROL', 'DIESEL', 'CNG', 'EV', 'HYBRID', 'OTHER'];

        if (!in_array($vehicleType, $allowedVehicleTypes, true)) {
            $vehicleType = '4W';
        }
        if (!in_array($fuelType, $allowedFuelTypes, true)) {
            $fuelType = 'PETROL';
        }

        if ($customerId <= 0 || $registrationNo === '' || $brand === '' || $model === '') {
            flash_set('vehicle_error', 'Customer, registration number, brand and model are required.', 'danger');
            redirect('modules/vehicles/index.php');
        }

        $customerCheck = db()->prepare('SELECT id FROM customers WHERE id = :id AND company_id = :company_id LIMIT 1');
        $customerCheck->execute([
            'id' => $customerId,
            'company_id' => $companyId,
        ]);
        if (!$customerCheck->fetch()) {
            flash_set('vehicle_error', 'Invalid customer selected.', 'danger');
            redirect('modules/vehicles/index.php');
        }

        try {
            $stmt = db()->prepare(
                'INSERT INTO vehicles
                  (company_id, customer_id, registration_no, vehicle_type, brand, model, variant, fuel_type, model_year, color, chassis_no, engine_no, odometer_km, notes, is_active)
                 VALUES
                  (:company_id, :customer_id, :registration_no, :vehicle_type, :brand, :model, :variant, :fuel_type, :model_year, :color, :chassis_no, :engine_no, :odometer_km, :notes, 1)'
            );
            $stmt->execute([
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
                'odometer_km' => $odometer > 0 ? $odometer : 0,
                'notes' => $notes !== '' ? $notes : null,
            ]);

            flash_set('vehicle_success', 'Vehicle added successfully.', 'success');
        } catch (Throwable $exception) {
            flash_set('vehicle_error', 'Unable to add vehicle. Registration number must be unique.', 'danger');
        }

        redirect('modules/vehicles/index.php');
    }

    if ($action === 'toggle_status') {
        $vehicleId = post_int('vehicle_id');
        $nextActive = post_int('next_active', 0) === 1 ? 1 : 0;

        $stmt = db()->prepare(
            'UPDATE vehicles
             SET is_active = :is_active
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'is_active' => $nextActive,
            'id' => $vehicleId,
            'company_id' => $companyId,
        ]);

        flash_set('vehicle_success', 'Vehicle status updated.', 'success');
        redirect('modules/vehicles/index.php');
    }
}

$customersStmt = db()->prepare(
    'SELECT id, full_name, phone
     FROM customers
     WHERE company_id = :company_id
       AND is_active = 1
     ORDER BY full_name ASC'
);
$customersStmt->execute(['company_id' => $companyId]);
$customers = $customersStmt->fetchAll();

$search = trim((string) ($_GET['q'] ?? ''));

if ($search !== '') {
    $vehiclesStmt = db()->prepare(
        'SELECT v.*, c.full_name AS customer_name, c.phone AS customer_phone,
                (SELECT COUNT(*) FROM job_cards jc WHERE jc.vehicle_id = v.id) AS service_count
         FROM vehicles v
         INNER JOIN customers c ON c.id = v.customer_id
         WHERE v.company_id = :company_id
           AND (v.registration_no LIKE :query OR v.brand LIKE :query OR v.model LIKE :query OR c.full_name LIKE :query)
         ORDER BY v.id DESC'
    );
    $vehiclesStmt->execute([
        'company_id' => $companyId,
        'query' => '%' . $search . '%',
    ]);
} else {
    $vehiclesStmt = db()->prepare(
        'SELECT v.*, c.full_name AS customer_name, c.phone AS customer_phone,
                (SELECT COUNT(*) FROM job_cards jc WHERE jc.vehicle_id = v.id) AS service_count
         FROM vehicles v
         INNER JOIN customers c ON c.id = v.customer_id
         WHERE v.company_id = :company_id
         ORDER BY v.id DESC'
    );
    $vehiclesStmt->execute(['company_id' => $companyId]);
}
$vehicles = $vehiclesStmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Vehicle Management</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Vehicles</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if ($canManage): ?>
        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title">Add Vehicle</h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="create" />

              <div class="col-md-4">
                <label class="form-label">Customer</label>
                <select name="customer_id" class="form-select" required>
                  <option value="">Select Customer</option>
                  <?php foreach ($customers as $customer): ?>
                    <option value="<?= (int) $customer['id']; ?>">
                      <?= e((string) $customer['full_name']); ?> (<?= e((string) $customer['phone']); ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">Registration Number</label>
                <input type="text" name="registration_no" class="form-control" required />
              </div>
              <div class="col-md-4">
                <label class="form-label">Vehicle Type</label>
                <select name="vehicle_type" class="form-select" required>
                  <option value="2W">2W</option>
                  <option value="4W" selected>4W</option>
                  <option value="COMMERCIAL">Commercial</option>
                </select>
              </div>

              <div class="col-md-3">
                <label class="form-label">Brand</label>
                <input type="text" name="brand" class="form-control" required />
              </div>
              <div class="col-md-3">
                <label class="form-label">Model</label>
                <input type="text" name="model" class="form-control" required />
              </div>
              <div class="col-md-3">
                <label class="form-label">Variant</label>
                <input type="text" name="variant" class="form-control" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Fuel Type</label>
                <select name="fuel_type" class="form-select" required>
                  <option value="PETROL">Petrol</option>
                  <option value="DIESEL">Diesel</option>
                  <option value="CNG">CNG</option>
                  <option value="EV">EV</option>
                  <option value="HYBRID">Hybrid</option>
                  <option value="OTHER">Other</option>
                </select>
              </div>

              <div class="col-md-3">
                <label class="form-label">Model Year</label>
                <input type="number" name="model_year" class="form-control" min="1990" max="2099" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Color</label>
                <input type="text" name="color" class="form-control" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Odometer (KM)</label>
                <input type="number" name="odometer_km" class="form-control" min="0" step="1" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Engine No</label>
                <input type="text" name="engine_no" class="form-control" />
              </div>

              <div class="col-md-6">
                <label class="form-label">Chassis No</label>
                <input type="text" name="chassis_no" class="form-control" />
              </div>
              <div class="col-md-6">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" class="form-control" />
              </div>
            </div>
            <div class="card-footer">
              <button type="submit" class="btn btn-primary">Save Vehicle</button>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Vehicles</h3>
          <div class="card-tools">
            <form method="get" class="d-flex gap-2">
              <input type="text" name="q" class="form-control form-control-sm" value="<?= e($search); ?>" placeholder="Search registration/brand/model/customer" />
              <button type="submit" class="btn btn-sm btn-outline-primary">Search</button>
            </form>
          </div>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>ID</th>
                <th>Registration</th>
                <th>Vehicle</th>
                <th>Customer</th>
                <th>Service Count</th>
                <th>Status</th>
                <?php if ($canManage): ?><th>Action</th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($vehicles)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No vehicles found.</td></tr>
              <?php else: ?>
                <?php foreach ($vehicles as $vehicle): ?>
                  <tr>
                    <td><?= (int) $vehicle['id']; ?></td>
                    <td><?= e((string) $vehicle['registration_no']); ?></td>
                    <td>
                      <?= e((string) $vehicle['brand']); ?> <?= e((string) $vehicle['model']); ?>
                      <br><small class="text-muted"><?= e((string) $vehicle['vehicle_type']); ?> | <?= e((string) $vehicle['fuel_type']); ?></small>
                    </td>
                    <td>
                      <?= e((string) $vehicle['customer_name']); ?>
                      <br><small class="text-muted"><?= e((string) $vehicle['customer_phone']); ?></small>
                    </td>
                    <td><?= (int) $vehicle['service_count']; ?></td>
                    <td>
                      <span class="badge text-bg-<?= ((int) $vehicle['is_active'] === 1) ? 'success' : 'secondary'; ?>">
                        <?= ((int) $vehicle['is_active'] === 1) ? 'Active' : 'Inactive'; ?>
                      </span>
                    </td>
                    <?php if ($canManage): ?>
                      <td>
                        <form method="post" class="d-inline" data-confirm="Change vehicle status?">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="_action" value="toggle_status" />
                          <input type="hidden" name="vehicle_id" value="<?= (int) $vehicle['id']; ?>" />
                          <input type="hidden" name="next_active" value="<?= ((int) $vehicle['is_active'] === 1) ? '0' : '1'; ?>" />
                          <button type="submit" class="btn btn-sm btn-outline-primary">
                            <?= ((int) $vehicle['is_active'] === 1) ? 'Deactivate' : 'Activate'; ?>
                          </button>
                        </form>
                      </td>
                    <?php endif; ?>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
