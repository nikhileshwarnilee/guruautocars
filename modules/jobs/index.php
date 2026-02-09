<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('job.view');

$page_title = 'Job Cards';
$active_menu = 'jobs';
$canManage = has_permission('job.manage');
$canAssign = has_permission('job.assign');
$companyId = active_company_id();
$garageId = active_garage_id();

function generate_job_number(PDO $pdo, int $garageId): string
{
    $counterStmt = $pdo->prepare('SELECT prefix, current_number FROM job_counters WHERE garage_id = :garage_id FOR UPDATE');
    $counterStmt->execute(['garage_id' => $garageId]);
    $counter = $counterStmt->fetch();

    if (!$counter) {
        $insertCounter = $pdo->prepare('INSERT INTO job_counters (garage_id, prefix, current_number) VALUES (:garage_id, "JOB", 1000)');
        $insertCounter->execute(['garage_id' => $garageId]);
        $counter = ['prefix' => 'JOB', 'current_number' => 1000];
    }

    $nextNumber = ((int) $counter['current_number']) + 1;

    $updateStmt = $pdo->prepare('UPDATE job_counters SET current_number = :current_number WHERE garage_id = :garage_id');
    $updateStmt->execute([
        'current_number' => $nextNumber,
        'garage_id' => $garageId,
    ]);

    $prefix = (string) $counter['prefix'];
    return sprintf('%s-%s-%04d', $prefix, date('ym'), $nextNumber);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'create_job' && $canManage) {
        $customerId = post_int('customer_id');
        $vehicleId = post_int('vehicle_id');
        $complaint = post_string('complaint', 3000);
        $priority = (string) ($_POST['priority'] ?? 'MEDIUM');
        $assignedTo = $canAssign ? post_int('assigned_to') : 0;
        $promisedAt = post_string('promised_at', 25);

        $allowedPriority = ['LOW', 'MEDIUM', 'HIGH', 'URGENT'];
        if (!in_array($priority, $allowedPriority, true)) {
            $priority = 'MEDIUM';
        }

        if ($customerId <= 0 || $vehicleId <= 0 || $complaint === '') {
            flash_set('job_error', 'Customer, vehicle, and complaint are required.', 'danger');
            redirect('modules/jobs/index.php');
        }

        $validationStmt = db()->prepare(
            'SELECT COUNT(*)
             FROM vehicles v
             INNER JOIN customers c ON c.id = v.customer_id
             WHERE v.id = :vehicle_id
               AND c.id = :customer_id
               AND v.company_id = :company_id
               AND c.company_id = :company_id
               AND v.status_code = "ACTIVE"
               AND c.status_code = "ACTIVE"'
        );
        $validationStmt->execute([
            'vehicle_id' => $vehicleId,
            'customer_id' => $customerId,
            'company_id' => $companyId,
        ]);

        if ((int) $validationStmt->fetchColumn() === 0) {
            flash_set('job_error', 'Vehicle is not linked with the selected customer.', 'danger');
            redirect('modules/jobs/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $jobNumber = generate_job_number($pdo, $garageId);

            $stmt = $pdo->prepare(
                'INSERT INTO job_cards
                  (company_id, garage_id, job_number, customer_id, vehicle_id, assigned_to, service_advisor_id, complaint, status, priority, promised_at, created_by, updated_by)
                 VALUES
                  (:company_id, :garage_id, :job_number, :customer_id, :vehicle_id, :assigned_to, :service_advisor_id, :complaint, "OPEN", :priority, :promised_at, :created_by, :updated_by)'
            );
            $stmt->execute([
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'job_number' => $jobNumber,
                'customer_id' => $customerId,
                'vehicle_id' => $vehicleId,
                'assigned_to' => $assignedTo > 0 ? $assignedTo : null,
                'service_advisor_id' => (int) $_SESSION['user_id'],
                'complaint' => $complaint,
                'priority' => $priority,
                'promised_at' => $promisedAt !== '' ? str_replace('T', ' ', $promisedAt) : null,
                'created_by' => (int) $_SESSION['user_id'],
                'updated_by' => (int) $_SESSION['user_id'],
            ]);

            $pdo->commit();
            flash_set('job_success', 'Job card created successfully.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('job_error', 'Unable to create job card. Please retry.', 'danger');
        }

        redirect('modules/jobs/index.php');
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

$vehiclesStmt = db()->prepare(
    'SELECT id, customer_id, registration_no, brand, model
     FROM vehicles
     WHERE company_id = :company_id
       AND status_code = "ACTIVE"
     ORDER BY registration_no ASC'
);
$vehiclesStmt->execute(['company_id' => $companyId]);
$vehicles = $vehiclesStmt->fetchAll();

$mechanicsStmt = db()->prepare(
    'SELECT u.id, u.name
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     INNER JOIN user_garages ug ON ug.user_id = u.id
     WHERE u.company_id = :company_id
       AND ug.garage_id = :garage_id
       AND u.status_code = "ACTIVE"
       AND r.role_key IN ("mechanic", "manager")
     ORDER BY u.name ASC'
);
$mechanicsStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$mechanics = $mechanicsStmt->fetchAll();

$statusFilter = (string) ($_GET['status'] ?? '');
$allowedStatuses = ['OPEN', 'IN_PROGRESS', 'WAITING_PARTS', 'READY_FOR_DELIVERY', 'COMPLETED', 'CANCELLED'];

if ($statusFilter !== '' && in_array($statusFilter, $allowedStatuses, true)) {
    $jobsStmt = db()->prepare(
        'SELECT jc.id, jc.job_number, jc.status, jc.priority, jc.opened_at, jc.promised_at,
                c.full_name AS customer_name,
                v.registration_no,
                u.name AS mechanic_name
         FROM job_cards jc
         INNER JOIN customers c ON c.id = jc.customer_id
         INNER JOIN vehicles v ON v.id = jc.vehicle_id
         LEFT JOIN users u ON u.id = jc.assigned_to
         WHERE jc.company_id = :company_id
           AND jc.garage_id = :garage_id
           AND jc.status = :status
         ORDER BY jc.id DESC'
    );
    $jobsStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'status' => $statusFilter,
    ]);
} else {
    $jobsStmt = db()->prepare(
        'SELECT jc.id, jc.job_number, jc.status, jc.priority, jc.opened_at, jc.promised_at,
                c.full_name AS customer_name,
                v.registration_no,
                u.name AS mechanic_name
         FROM job_cards jc
         INNER JOIN customers c ON c.id = jc.customer_id
         INNER JOIN vehicles v ON v.id = jc.vehicle_id
         LEFT JOIN users u ON u.id = jc.assigned_to
         WHERE jc.company_id = :company_id
           AND jc.garage_id = :garage_id
         ORDER BY jc.id DESC'
    );
    $jobsStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
}
$jobs = $jobsStmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Job Cards</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Job Cards</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if ($canManage): ?>
        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title">Create Job Card</h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="create_job" />

              <div class="col-md-4">
                <label class="form-label">Customer</label>
                <select name="customer_id" class="form-select" required>
                  <option value="">Select Customer</option>
                  <?php foreach ($customers as $customer): ?>
                    <option value="<?= (int) $customer['id']; ?>"><?= e((string) $customer['full_name']); ?> (<?= e((string) $customer['phone']); ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">Vehicle</label>
                <select name="vehicle_id" class="form-select" required>
                  <option value="">Select Vehicle</option>
                  <?php foreach ($vehicles as $vehicle): ?>
                    <option value="<?= (int) $vehicle['id']; ?>">
                      <?= e((string) $vehicle['registration_no']); ?> - <?= e((string) $vehicle['brand']); ?> <?= e((string) $vehicle['model']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Priority</label>
                <select name="priority" class="form-select" required>
                  <option value="LOW">Low</option>
                  <option value="MEDIUM" selected>Medium</option>
                  <option value="HIGH">High</option>
                  <option value="URGENT">Urgent</option>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Promised Date</label>
                <input type="datetime-local" name="promised_at" class="form-control" />
              </div>

              <?php if ($canAssign): ?>
                <div class="col-md-4">
                  <label class="form-label">Assign Mechanic</label>
                  <select name="assigned_to" class="form-select">
                    <option value="">Unassigned</option>
                    <?php foreach ($mechanics as $mechanic): ?>
                      <option value="<?= (int) $mechanic['id']; ?>"><?= e((string) $mechanic['name']); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              <?php endif; ?>

              <div class="col-md-12">
                <label class="form-label">Complaint / Issue Description</label>
                <textarea name="complaint" rows="3" class="form-control" required></textarea>
              </div>
            </div>
            <div class="card-footer">
              <button type="submit" class="btn btn-primary">Create Job Card</button>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Job List</h3>
          <div class="card-tools">
            <form method="get" class="d-flex gap-2">
              <select name="status" class="form-select form-select-sm">
                <option value="">All Statuses</option>
                <?php foreach ($allowedStatuses as $status): ?>
                  <option value="<?= e($status); ?>" <?= $statusFilter === $status ? 'selected' : ''; ?>><?= e($status); ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn btn-sm btn-outline-primary">Filter</button>
            </form>
          </div>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>Job Number</th>
                <th>Customer</th>
                <th>Vehicle</th>
                <th>Mechanic</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Opened</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($jobs)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No job cards found.</td></tr>
              <?php else: ?>
                <?php foreach ($jobs as $job): ?>
                  <tr>
                    <td><?= e((string) $job['job_number']); ?></td>
                    <td><?= e((string) $job['customer_name']); ?></td>
                    <td><?= e((string) $job['registration_no']); ?></td>
                    <td><?= e((string) ($job['mechanic_name'] ?? 'Unassigned')); ?></td>
                    <td><span class="badge text-bg-warning"><?= e((string) $job['priority']); ?></span></td>
                    <td><span class="badge text-bg-secondary"><?= e((string) $job['status']); ?></span></td>
                    <td><?= e((string) $job['opened_at']); ?></td>
                    <td>
                      <a href="<?= e(url('modules/jobs/view.php?id=' . (int) $job['id'])); ?>" class="btn btn-sm btn-outline-primary">Open</a>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
