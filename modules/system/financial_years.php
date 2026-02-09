<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('financial_year.view');

$page_title = 'Financial Year Master';
$active_menu = 'system.financial_years';
$canManage = has_permission('financial_year.manage');
$isSuperAdmin = (string) ($_SESSION['role_key'] ?? '') === 'super_admin';

$companies = [];
if ($isSuperAdmin) {
    $companies = db()->query("SELECT id, name FROM companies WHERE status_code <> 'DELETED' ORDER BY name ASC")->fetchAll();
}

$selectedCompanyId = $isSuperAdmin ? get_int('company_id', active_company_id()) : active_company_id();
if ($selectedCompanyId <= 0) {
    $selectedCompanyId = active_company_id();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!$canManage) {
        flash_set('fy_error', 'You do not have permission to modify financial years.', 'danger');
        redirect('modules/system/financial_years.php');
    }

    $action = (string) ($_POST['_action'] ?? '');
    $companyId = $isSuperAdmin ? post_int('company_id', active_company_id()) : active_company_id();
    $fyLabel = post_string('fy_label', 20);
    $startDate = post_string('start_date', 10);
    $endDate = post_string('end_date', 10);
    $isDefault = post_int('is_default', 0) === 1 ? 1 : 0;
    $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

    if ($action === 'create') {
        if ($companyId <= 0 || $fyLabel === '' || $startDate === '' || $endDate === '') {
            flash_set('fy_error', 'Company, label, start date and end date are required.', 'danger');
            redirect('modules/system/financial_years.php');
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            if ($isDefault === 1) {
                $clearStmt = $pdo->prepare('UPDATE financial_years SET is_default = 0 WHERE company_id = :company_id');
                $clearStmt->execute(['company_id' => $companyId]);
            }

            $insertStmt = $pdo->prepare(
                'INSERT INTO financial_years
                  (company_id, fy_label, start_date, end_date, is_default, status_code, deleted_at, created_by)
                 VALUES
                  (:company_id, :fy_label, :start_date, :end_date, :is_default, :status_code, :deleted_at, :created_by)'
            );
            $insertStmt->execute([
                'company_id' => $companyId,
                'fy_label' => $fyLabel,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_default' => $isDefault,
                'status_code' => $statusCode,
                'deleted_at' => $statusCode === 'DELETED' ? date('Y-m-d H:i:s') : null,
                'created_by' => (int) $_SESSION['user_id'],
            ]);

            $fyId = (int) $pdo->lastInsertId();
            $pdo->commit();
            log_audit('financial_year', 'create', $fyId, 'Created financial year ' . $fyLabel);
            flash_set('fy_success', 'Financial year created successfully.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('fy_error', 'Unable to create financial year. Label must be unique per company.', 'danger');
        }

        redirect('modules/system/financial_years.php?company_id=' . $companyId);
    }

    if ($action === 'update') {
        $fyId = post_int('fy_id');

        if ($fyId <= 0 || $companyId <= 0 || $fyLabel === '' || $startDate === '' || $endDate === '') {
            flash_set('fy_error', 'Invalid update payload.', 'danger');
            redirect('modules/system/financial_years.php?company_id=' . $companyId);
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            if ($isDefault === 1) {
                $clearStmt = $pdo->prepare('UPDATE financial_years SET is_default = 0 WHERE company_id = :company_id');
                $clearStmt->execute(['company_id' => $companyId]);
            }

            $updateStmt = $pdo->prepare(
                'UPDATE financial_years
                 SET fy_label = :fy_label,
                     start_date = :start_date,
                     end_date = :end_date,
                     is_default = :is_default,
                     status_code = :status_code,
                     deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
                 WHERE id = :id
                   AND company_id = :company_id'
            );
            $updateStmt->execute([
                'fy_label' => $fyLabel,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_default' => $isDefault,
                'status_code' => $statusCode,
                'id' => $fyId,
                'company_id' => $companyId,
            ]);

            $pdo->commit();
            log_audit('financial_year', 'update', $fyId, 'Updated financial year ' . $fyLabel);
            flash_set('fy_success', 'Financial year updated successfully.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('fy_error', 'Unable to update financial year.', 'danger');
        }

        redirect('modules/system/financial_years.php?company_id=' . $companyId);
    }

    if ($action === 'change_status') {
        $fyId = post_int('fy_id');
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));

        $stmt = db()->prepare(
            'UPDATE financial_years
             SET status_code = :status_code,
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'status_code' => $nextStatus,
            'id' => $fyId,
            'company_id' => $companyId,
        ]);

        log_audit('financial_year', 'status', $fyId, 'Changed status to ' . $nextStatus);
        flash_set('fy_success', 'Financial year status updated.', 'success');
        redirect('modules/system/financial_years.php?company_id=' . $companyId);
    }
}

$editId = get_int('edit_id');
$editFinancialYear = null;
if ($editId > 0) {
    $editStmt = db()->prepare(
        'SELECT *
         FROM financial_years
         WHERE id = :id
           AND company_id = :company_id
         LIMIT 1'
    );
    $editStmt->execute([
        'id' => $editId,
        'company_id' => $selectedCompanyId,
    ]);
    $editFinancialYear = $editStmt->fetch() ?: null;
}

$listStmt = db()->prepare(
    'SELECT fy.*, c.name AS company_name
     FROM financial_years fy
     INNER JOIN companies c ON c.id = fy.company_id
     WHERE fy.company_id = :company_id
     ORDER BY fy.start_date DESC'
);
$listStmt->execute(['company_id' => $selectedCompanyId]);
$financialYears = $listStmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Financial Year Master</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Financial Year</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if ($isSuperAdmin): ?>
        <div class="card card-outline card-primary">
          <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
              <div class="col-md-4">
                <label class="form-label">Company Scope</label>
                <select name="company_id" class="form-select" onchange="this.form.submit()">
                  <?php foreach ($companies as $company): ?>
                    <option value="<?= (int) $company['id']; ?>" <?= ((int) $company['id'] === $selectedCompanyId) ? 'selected' : ''; ?>>
                      <?= e((string) $company['name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </form>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($canManage): ?>
        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title"><?= $editFinancialYear ? 'Edit Financial Year' : 'Add Financial Year'; ?></h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="<?= $editFinancialYear ? 'update' : 'create'; ?>" />
              <input type="hidden" name="fy_id" value="<?= (int) ($editFinancialYear['id'] ?? 0); ?>" />
              <input type="hidden" name="company_id" value="<?= (int) $selectedCompanyId; ?>" />

              <div class="col-md-3">
                <label class="form-label">FY Label</label>
                <input type="text" name="fy_label" class="form-control" required value="<?= e((string) ($editFinancialYear['fy_label'] ?? '')); ?>" placeholder="2026-27" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Start Date</label>
                <input type="date" name="start_date" class="form-control" required value="<?= e((string) ($editFinancialYear['start_date'] ?? '')); ?>" />
              </div>
              <div class="col-md-3">
                <label class="form-label">End Date</label>
                <input type="date" name="end_date" class="form-control" required value="<?= e((string) ($editFinancialYear['end_date'] ?? '')); ?>" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status_code" class="form-select" required>
                  <?php foreach (status_options((string) ($editFinancialYear['status_code'] ?? 'ACTIVE')) as $option): ?>
                    <option value="<?= e($option['value']); ?>" <?= $option['selected'] ? 'selected' : ''; ?>><?= e($option['value']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3">
                <div class="form-check mt-4">
                  <input class="form-check-input" type="checkbox" name="is_default" value="1" id="is_default" <?= ((int) ($editFinancialYear['is_default'] ?? 0) === 1) ? 'checked' : ''; ?> />
                  <label class="form-check-label" for="is_default">Set as Default FY</label>
                </div>
              </div>
            </div>
            <div class="card-footer d-flex gap-2">
              <button type="submit" class="btn btn-primary"><?= $editFinancialYear ? 'Update Financial Year' : 'Create Financial Year'; ?></button>
              <?php if ($editFinancialYear): ?>
                <a href="<?= e(url('modules/system/financial_years.php?company_id=' . $selectedCompanyId)); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header"><h3 class="card-title">Financial Year List</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>ID</th>
                <th>Label</th>
                <th>Start</th>
                <th>End</th>
                <th>Default</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($financialYears)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No financial years found.</td></tr>
              <?php else: ?>
                <?php foreach ($financialYears as $fy): ?>
                  <tr>
                    <td><?= (int) $fy['id']; ?></td>
                    <td><?= e((string) $fy['fy_label']); ?></td>
                    <td><?= e((string) $fy['start_date']); ?></td>
                    <td><?= e((string) $fy['end_date']); ?></td>
                    <td>
                      <?= ((int) $fy['is_default'] === 1) ? '<span class="badge text-bg-primary">Default</span>' : '-'; ?>
                    </td>
                    <td><span class="badge text-bg-<?= e(status_badge_class((string) $fy['status_code'])); ?>"><?= e((string) $fy['status_code']); ?></span></td>
                    <td class="d-flex gap-1">
                      <?php if ($canManage): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/system/financial_years.php?company_id=' . $selectedCompanyId . '&edit_id=' . (int) $fy['id'])); ?>">Edit</a>
                        <form method="post" class="d-inline" data-confirm="Change financial year status?">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="_action" value="change_status" />
                          <input type="hidden" name="company_id" value="<?= (int) $selectedCompanyId; ?>" />
                          <input type="hidden" name="fy_id" value="<?= (int) $fy['id']; ?>" />
                          <input type="hidden" name="next_status" value="<?= e(((string) $fy['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>" />
                          <button type="submit" class="btn btn-sm btn-outline-secondary"><?= ((string) $fy['status_code'] === 'ACTIVE') ? 'Inactivate' : 'Activate'; ?></button>
                        </form>
                        <?php if ((string) $fy['status_code'] !== 'DELETED'): ?>
                          <form method="post" class="d-inline" data-confirm="Soft delete this financial year?">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="company_id" value="<?= (int) $selectedCompanyId; ?>" />
                            <input type="hidden" name="fy_id" value="<?= (int) $fy['id']; ?>" />
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
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
