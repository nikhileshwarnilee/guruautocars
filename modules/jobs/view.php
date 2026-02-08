<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('job.view');

$page_title = 'Job Details';
$active_menu = 'jobs';
$companyId = active_company_id();
$garageId = active_garage_id();

$canUpdate = has_permission('job.update');
$canAssign = has_permission('job.assign');

$jobId = get_int('id');
if ($jobId <= 0) {
    flash_set('job_error', 'Invalid job card id.', 'danger');
    redirect('modules/jobs/index.php');
}

function load_job_details(int $jobId, int $companyId, int $garageId): ?array
{
    $stmt = db()->prepare(
        'SELECT jc.*, c.full_name AS customer_name, c.phone AS customer_phone,
                v.registration_no, v.brand, v.model,
                u.name AS mechanic_name,
                sa.name AS advisor_name,
                g.name AS garage_name
         FROM job_cards jc
         INNER JOIN customers c ON c.id = jc.customer_id
         INNER JOIN vehicles v ON v.id = jc.vehicle_id
         LEFT JOIN users u ON u.id = jc.assigned_to
         LEFT JOIN users sa ON sa.id = jc.service_advisor_id
         INNER JOIN garages g ON g.id = jc.garage_id
         WHERE jc.id = :job_id
           AND jc.company_id = :company_id
           AND jc.garage_id = :garage_id
         LIMIT 1'
    );
    $stmt->execute([
        'job_id' => $jobId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);

    $job = $stmt->fetch();
    return $job ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'update_status' && $canUpdate) {
        $status = (string) ($_POST['status'] ?? 'OPEN');
        $diagnosis = post_string('diagnosis', 3000);
        $allowedStatuses = ['OPEN', 'IN_PROGRESS', 'WAITING_PARTS', 'READY_FOR_DELIVERY', 'COMPLETED', 'CANCELLED'];

        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'OPEN';
        }

        $stmt = db()->prepare(
            'UPDATE job_cards
             SET status = :status,
                 diagnosis = :diagnosis,
                 completed_at = CASE WHEN :status = "COMPLETED" THEN NOW() ELSE completed_at END,
                 updated_by = :updated_by
             WHERE id = :id
               AND company_id = :company_id
               AND garage_id = :garage_id'
        );
        $stmt->execute([
            'status' => $status,
            'diagnosis' => $diagnosis !== '' ? $diagnosis : null,
            'updated_by' => (int) $_SESSION['user_id'],
            'id' => $jobId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);

        flash_set('job_success', 'Job status updated.', 'success');
        redirect('modules/jobs/view.php?id=' . $jobId);
    }

    if ($action === 'assign_mechanic' && $canAssign) {
        $assignedTo = post_int('assigned_to');

        $stmt = db()->prepare(
            'UPDATE job_cards
             SET assigned_to = :assigned_to,
                 updated_by = :updated_by
             WHERE id = :id
               AND company_id = :company_id
               AND garage_id = :garage_id'
        );
        $stmt->execute([
            'assigned_to' => $assignedTo > 0 ? $assignedTo : null,
            'updated_by' => (int) $_SESSION['user_id'],
            'id' => $jobId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);

        flash_set('job_success', 'Mechanic assignment updated.', 'success');
        redirect('modules/jobs/view.php?id=' . $jobId);
    }

    if ($action === 'add_issue' && $canUpdate) {
        $issueTitle = post_string('issue_title', 150);
        $issueNotes = post_string('issue_notes', 1500);

        if ($issueTitle !== '') {
            $stmt = db()->prepare(
                'INSERT INTO job_issues (job_card_id, issue_title, issue_notes, resolved_flag)
                 VALUES (:job_card_id, :issue_title, :issue_notes, 0)'
            );
            $stmt->execute([
                'job_card_id' => $jobId,
                'issue_title' => $issueTitle,
                'issue_notes' => $issueNotes !== '' ? $issueNotes : null,
            ]);
            flash_set('job_success', 'Issue added.', 'success');
        }

        redirect('modules/jobs/view.php?id=' . $jobId);
    }

    if ($action === 'resolve_issue' && $canUpdate) {
        $issueId = post_int('issue_id');
        $resolvedFlag = post_int('resolved_flag') === 1 ? 1 : 0;

        $stmt = db()->prepare(
            'UPDATE job_issues ji
             INNER JOIN job_cards jc ON jc.id = ji.job_card_id
             SET ji.resolved_flag = :resolved_flag
             WHERE ji.id = :issue_id
               AND jc.id = :job_id
               AND jc.company_id = :company_id
               AND jc.garage_id = :garage_id'
        );
        $stmt->execute([
            'resolved_flag' => $resolvedFlag,
            'issue_id' => $issueId,
            'job_id' => $jobId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);

        flash_set('job_success', 'Issue updated.', 'success');
        redirect('modules/jobs/view.php?id=' . $jobId);
    }

    if ($action === 'add_labor' && $canUpdate) {
        $description = post_string('description', 255);
        $quantity = (float) ($_POST['quantity'] ?? 0);
        $unitPrice = (float) ($_POST['unit_price'] ?? 0);
        $gstRate = (float) ($_POST['gst_rate'] ?? 18);

        if ($description !== '' && $quantity > 0 && $unitPrice >= 0) {
            $totalAmount = round($quantity * $unitPrice, 2);

            $stmt = db()->prepare(
                'INSERT INTO job_labor (job_card_id, description, quantity, unit_price, gst_rate, total_amount)
                 VALUES (:job_card_id, :description, :quantity, :unit_price, :gst_rate, :total_amount)'
            );
            $stmt->execute([
                'job_card_id' => $jobId,
                'description' => $description,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'gst_rate' => $gstRate,
                'total_amount' => $totalAmount,
            ]);

            flash_set('job_success', 'Labor entry added.', 'success');
        } else {
            flash_set('job_error', 'Invalid labor input.', 'danger');
        }

        redirect('modules/jobs/view.php?id=' . $jobId);
    }

    if ($action === 'add_part' && $canUpdate) {
        $partId = post_int('part_id');
        $quantity = (float) ($_POST['quantity'] ?? 0);

        if ($partId <= 0 || $quantity <= 0) {
            flash_set('job_error', 'Select a part and valid quantity.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $partStmt = $pdo->prepare(
                'SELECT p.id, p.part_name, p.selling_price, p.gst_rate,
                        gi.quantity AS stock_qty
                 FROM parts p
                 INNER JOIN garage_inventory gi ON gi.part_id = p.id AND gi.garage_id = :garage_id
                 WHERE p.id = :part_id
                   AND p.company_id = :company_id
                 FOR UPDATE'
            );
            $partStmt->execute([
                'garage_id' => $garageId,
                'part_id' => $partId,
                'company_id' => $companyId,
            ]);
            $part = $partStmt->fetch();

            if (!$part) {
                throw new RuntimeException('Part not found in active garage inventory.');
            }

            $availableQty = (float) $part['stock_qty'];
            if ($availableQty < $quantity) {
                throw new RuntimeException('Insufficient stock. Available: ' . $availableQty);
            }

            $unitPrice = (float) $part['selling_price'];
            $gstRate = (float) $part['gst_rate'];
            $totalAmount = round($quantity * $unitPrice, 2);

            $jobPartStmt = $pdo->prepare(
                'INSERT INTO job_parts
                  (job_card_id, part_id, quantity, unit_price, gst_rate, total_amount)
                 VALUES
                  (:job_card_id, :part_id, :quantity, :unit_price, :gst_rate, :total_amount)'
            );
            $jobPartStmt->execute([
                'job_card_id' => $jobId,
                'part_id' => $partId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'gst_rate' => $gstRate,
                'total_amount' => $totalAmount,
            ]);

            $stockStmt = $pdo->prepare(
                'UPDATE garage_inventory
                 SET quantity = quantity - :quantity
                 WHERE garage_id = :garage_id
                   AND part_id = :part_id'
            );
            $stockStmt->execute([
                'quantity' => $quantity,
                'garage_id' => $garageId,
                'part_id' => $partId,
            ]);

            $movementStmt = $pdo->prepare(
                'INSERT INTO inventory_movements
                  (company_id, garage_id, part_id, movement_type, quantity, reference_type, reference_id, notes, created_by)
                 VALUES
                  (:company_id, :garage_id, :part_id, "OUT", :quantity, "JOB_CARD", :reference_id, :notes, :created_by)'
            );
            $movementStmt->execute([
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'part_id' => $partId,
                'quantity' => $quantity,
                'reference_id' => $jobId,
                'notes' => 'Issued for Job Card #' . $jobId,
                'created_by' => (int) $_SESSION['user_id'],
            ]);

            $pdo->commit();
            flash_set('job_success', 'Part added to job card and stock updated.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('job_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/jobs/view.php?id=' . $jobId);
    }
}

$job = load_job_details($jobId, $companyId, $garageId);
if ($job === null) {
    flash_set('job_error', 'Job card not found for active garage.', 'danger');
    redirect('modules/jobs/index.php');
}

$issuesStmt = db()->prepare('SELECT * FROM job_issues WHERE job_card_id = :job_card_id ORDER BY id DESC');
$issuesStmt->execute(['job_card_id' => $jobId]);
$issues = $issuesStmt->fetchAll();

$laborStmt = db()->prepare('SELECT * FROM job_labor WHERE job_card_id = :job_card_id ORDER BY id DESC');
$laborStmt->execute(['job_card_id' => $jobId]);
$laborEntries = $laborStmt->fetchAll();

$partsStmt = db()->prepare(
    'SELECT jp.*, p.part_name, p.part_sku
     FROM job_parts jp
     INNER JOIN parts p ON p.id = jp.part_id
     WHERE jp.job_card_id = :job_card_id
     ORDER BY jp.id DESC'
);
$partsStmt->execute(['job_card_id' => $jobId]);
$jobParts = $partsStmt->fetchAll();

$inventoryPartsStmt = db()->prepare(
    'SELECT p.id, p.part_name, p.part_sku, p.selling_price, p.gst_rate, gi.quantity
     FROM garage_inventory gi
     INNER JOIN parts p ON p.id = gi.part_id
     WHERE gi.garage_id = :garage_id
       AND p.company_id = :company_id
       AND p.is_active = 1
       AND gi.quantity > 0
     ORDER BY p.part_name ASC'
);
$inventoryPartsStmt->execute([
    'garage_id' => $garageId,
    'company_id' => $companyId,
]);
$inventoryParts = $inventoryPartsStmt->fetchAll();

$mechanicsStmt = db()->prepare(
    'SELECT u.id, u.name
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     INNER JOIN user_garages ug ON ug.user_id = u.id
     WHERE u.company_id = :company_id
       AND ug.garage_id = :garage_id
       AND u.is_active = 1
       AND r.role_key IN ("mechanic", "manager")
     ORDER BY u.name ASC'
);
$mechanicsStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$mechanics = $mechanicsStmt->fetchAll();

$totalsStmt = db()->prepare(
    'SELECT
        (SELECT COALESCE(SUM(total_amount), 0) FROM job_labor WHERE job_card_id = :job_id) AS labor_total,
        (SELECT COALESCE(SUM(total_amount), 0) FROM job_parts WHERE job_card_id = :job_id) AS parts_total'
);
$totalsStmt->execute(['job_id' => $jobId]);
$totals = $totalsStmt->fetch();
$laborTotal = (float) ($totals['labor_total'] ?? 0);
$partsTotal = (float) ($totals['parts_total'] ?? 0);

$invoiceStmt = db()->prepare('SELECT id, invoice_number, grand_total, payment_status FROM invoices WHERE job_card_id = :job_id LIMIT 1');
$invoiceStmt->execute(['job_id' => $jobId]);
$invoice = $invoiceStmt->fetch();

$statusOptions = ['OPEN', 'IN_PROGRESS', 'WAITING_PARTS', 'READY_FOR_DELIVERY', 'COMPLETED', 'CANCELLED'];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-8">
          <h3 class="mb-0">Job Card <?= e((string) $job['job_number']); ?></h3>
          <small class="text-muted">Garage: <?= e((string) $job['garage_name']); ?> | Customer: <?= e((string) $job['customer_name']); ?></small>
        </div>
        <div class="col-sm-4 text-sm-end">
          <a href="<?= e(url('modules/jobs/index.php')); ?>" class="btn btn-outline-secondary btn-sm">Back to Jobs</a>
          <?php if ($invoice): ?>
            <a href="<?= e(url('modules/billing/index.php')); ?>" class="btn btn-success btn-sm">Invoice <?= e((string) $invoice['invoice_number']); ?></a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="row g-3 mb-3">
        <div class="col-md-3">
          <div class="small-box text-bg-primary">
            <div class="inner">
              <h4><?= e((string) $job['status']); ?></h4>
              <p>Current Status</p>
            </div>
            <span class="small-box-icon"><i class="bi bi-activity"></i></span>
          </div>
        </div>
        <div class="col-md-3">
          <div class="small-box text-bg-success">
            <div class="inner">
              <h4><?= e(format_currency($laborTotal)); ?></h4>
              <p>Labor Total</p>
            </div>
            <span class="small-box-icon"><i class="bi bi-tools"></i></span>
          </div>
        </div>
        <div class="col-md-3">
          <div class="small-box text-bg-warning">
            <div class="inner">
              <h4><?= e(format_currency($partsTotal)); ?></h4>
              <p>Parts Total</p>
            </div>
            <span class="small-box-icon"><i class="bi bi-box-seam"></i></span>
          </div>
        </div>
        <div class="col-md-3">
          <div class="small-box text-bg-danger">
            <div class="inner">
              <h4><?= e(format_currency($laborTotal + $partsTotal)); ?></h4>
              <p>Estimated Total</p>
            </div>
            <span class="small-box-icon"><i class="bi bi-cash-stack"></i></span>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-lg-6">
          <div class="card">
            <div class="card-header"><h3 class="card-title">Job Information</h3></div>
            <div class="card-body">
              <p><strong>Vehicle:</strong> <?= e((string) $job['registration_no']); ?> (<?= e((string) $job['brand']); ?> <?= e((string) $job['model']); ?>)</p>
              <p><strong>Complaint:</strong> <?= nl2br(e((string) $job['complaint'])); ?></p>
              <p><strong>Diagnosis:</strong> <?= nl2br(e((string) ($job['diagnosis'] ?? 'Not updated'))); ?></p>
              <p><strong>Assigned Mechanic:</strong> <?= e((string) ($job['mechanic_name'] ?? 'Unassigned')); ?></p>
              <p><strong>Promised At:</strong> <?= e((string) ($job['promised_at'] ?? '-')); ?></p>
            </div>
          </div>

          <?php if ($canUpdate): ?>
            <div class="card card-primary">
              <div class="card-header"><h3 class="card-title">Update Status / Diagnosis</h3></div>
              <form method="post">
                <div class="card-body row g-3">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="update_status" />
                  <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select" required>
                      <?php foreach ($statusOptions as $status): ?>
                        <option value="<?= e($status); ?>" <?= ((string) $job['status'] === $status) ? 'selected' : ''; ?>><?= e($status); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-8">
                    <label class="form-label">Diagnosis</label>
                    <input type="text" name="diagnosis" class="form-control" value="<?= e((string) ($job['diagnosis'] ?? '')); ?>" />
                  </div>
                </div>
                <div class="card-footer">
                  <button type="submit" class="btn btn-primary">Save Update</button>
                </div>
              </form>
            </div>
          <?php endif; ?>

          <?php if ($canAssign): ?>
            <div class="card card-info">
              <div class="card-header"><h3 class="card-title">Assign Mechanic</h3></div>
              <form method="post">
                <div class="card-body row g-3">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="assign_mechanic" />
                  <div class="col-md-8">
                    <select name="assigned_to" class="form-select">
                      <option value="">Unassigned</option>
                      <?php foreach ($mechanics as $mechanic): ?>
                        <option value="<?= (int) $mechanic['id']; ?>" <?= ((int) ($job['assigned_to'] ?? 0) === (int) $mechanic['id']) ? 'selected' : ''; ?>>
                          <?= e((string) $mechanic['name']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <button type="submit" class="btn btn-info">Assign</button>
                  </div>
                </div>
              </form>
            </div>
          <?php endif; ?>
        </div>

        <div class="col-lg-6">
          <div class="card card-secondary">
            <div class="card-header"><h3 class="card-title">Issues</h3></div>
            <div class="card-body">
              <?php if ($canUpdate): ?>
                <form method="post" class="row g-2 mb-3">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="add_issue" />
                  <div class="col-md-5">
                    <input type="text" name="issue_title" class="form-control" placeholder="Issue title" required />
                  </div>
                  <div class="col-md-5">
                    <input type="text" name="issue_notes" class="form-control" placeholder="Notes" />
                  </div>
                  <div class="col-md-2">
                    <button type="submit" class="btn btn-secondary w-100">Add</button>
                  </div>
                </form>
              <?php endif; ?>
              <ul class="list-group">
                <?php if (empty($issues)): ?>
                  <li class="list-group-item text-muted">No issues logged.</li>
                <?php else: ?>
                  <?php foreach ($issues as $issue): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-start">
                      <div>
                        <div class="fw-semibold"><?= e((string) $issue['issue_title']); ?></div>
                        <small class="text-muted"><?= e((string) ($issue['issue_notes'] ?? '-')); ?></small>
                      </div>
                      <?php if ($canUpdate): ?>
                        <form method="post" class="ms-2">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="_action" value="resolve_issue" />
                          <input type="hidden" name="issue_id" value="<?= (int) $issue['id']; ?>" />
                          <input type="hidden" name="resolved_flag" value="<?= ((int) $issue['resolved_flag'] === 1) ? '0' : '1'; ?>" />
                          <button type="submit" class="btn btn-sm <?= ((int) $issue['resolved_flag'] === 1) ? 'btn-success' : 'btn-outline-success'; ?>">
                            <?= ((int) $issue['resolved_flag'] === 1) ? 'Resolved' : 'Mark Resolved'; ?>
                          </button>
                        </form>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                <?php endif; ?>
              </ul>
            </div>
          </div>

          <div class="card card-success">
            <div class="card-header"><h3 class="card-title">Labor Entries</h3></div>
            <div class="card-body">
              <?php if ($canUpdate): ?>
                <form method="post" class="row g-2 mb-3">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="add_labor" />
                  <div class="col-md-5"><input type="text" name="description" class="form-control" placeholder="Labor description" required /></div>
                  <div class="col-md-2"><input type="number" step="0.01" name="quantity" class="form-control" placeholder="Qty" required /></div>
                  <div class="col-md-2"><input type="number" step="0.01" name="unit_price" class="form-control" placeholder="Rate" required /></div>
                  <div class="col-md-2"><input type="number" step="0.01" name="gst_rate" class="form-control" value="18" /></div>
                  <div class="col-md-1"><button type="submit" class="btn btn-success w-100">Add</button></div>
                </form>
              <?php endif; ?>
              <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                  <thead><tr><th>Description</th><th>Qty</th><th>Rate</th><th>Total</th></tr></thead>
                  <tbody>
                    <?php if (empty($laborEntries)): ?>
                      <tr><td colspan="4" class="text-center text-muted">No labor entries.</td></tr>
                    <?php else: ?>
                      <?php foreach ($laborEntries as $labor): ?>
                        <tr>
                          <td><?= e((string) $labor['description']); ?></td>
                          <td><?= e((string) $labor['quantity']); ?></td>
                          <td><?= e(format_currency((float) $labor['unit_price'])); ?></td>
                          <td><?= e(format_currency((float) $labor['total_amount'])); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="card card-warning">
            <div class="card-header"><h3 class="card-title">Parts Used</h3></div>
            <div class="card-body">
              <?php if ($canUpdate): ?>
                <form method="post" class="row g-2 mb-3">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="add_part" />
                  <div class="col-md-8">
                    <select name="part_id" class="form-select" required>
                      <option value="">Select Part</option>
                      <?php foreach ($inventoryParts as $part): ?>
                        <option value="<?= (int) $part['id']; ?>">
                          <?= e((string) $part['part_name']); ?> (<?= e((string) $part['part_sku']); ?>) | Stock: <?= e((string) $part['quantity']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3"><input type="number" step="0.01" min="0.01" name="quantity" class="form-control" placeholder="Qty" required /></div>
                  <div class="col-md-1"><button type="submit" class="btn btn-warning w-100">Add</button></div>
                </form>
              <?php endif; ?>
              <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                  <thead><tr><th>Part</th><th>Qty</th><th>Rate</th><th>Total</th></tr></thead>
                  <tbody>
                    <?php if (empty($jobParts)): ?>
                      <tr><td colspan="4" class="text-center text-muted">No parts used.</td></tr>
                    <?php else: ?>
                      <?php foreach ($jobParts as $part): ?>
                        <tr>
                          <td><?= e((string) $part['part_name']); ?></td>
                          <td><?= e((string) $part['quantity']); ?></td>
                          <td><?= e(format_currency((float) $part['unit_price'])); ?></td>
                          <td><?= e(format_currency((float) $part['total_amount'])); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
