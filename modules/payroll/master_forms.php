<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();

$companyId = active_company_id();
$garageId = active_garage_id();
$today = date('Y-m-d');

$canView = has_permission('payroll.view') || has_permission('payroll.manage');
$canManage = has_permission('payroll.manage');
if (!$canView) {
    require_permission('payroll.view');
}

$page_title = 'Payroll Master Forms';
$active_menu = 'finance.payroll';

function payroll_master_badge_class(string $status): string
{
    return match (strtoupper($status)) {
        'ACTIVE', 'PAID', 'CLOSED' => 'success',
        'OPEN', 'PARTIAL' => 'warning',
        'INACTIVE' => 'secondary',
        'DELETED' => 'danger',
        default => 'secondary',
    };
}

$staffSearch = trim((string) ($_GET['staff_q'] ?? ''));
$sectionFilter = strtoupper(trim((string) ($_GET['section'] ?? 'ALL')));
if (!in_array($sectionFilter, ['ALL', 'STRUCTURE', 'ADVANCE', 'LOAN', 'PAYMENT'], true)) {
    $sectionFilter = 'ALL';
}

$returnQuery = [];
if ($staffSearch !== '') {
    $returnQuery['staff_q'] = $staffSearch;
}
if ($sectionFilter !== 'ALL') {
    $returnQuery['section'] = $sectionFilter;
}
$returnTo = 'modules/payroll/master_forms.php' . ($returnQuery !== [] ? '?' . http_build_query($returnQuery) : '');

$showStructure = in_array($sectionFilter, ['ALL', 'STRUCTURE'], true);
$showAdvance = in_array($sectionFilter, ['ALL', 'ADVANCE'], true);
$showLoan = in_array($sectionFilter, ['ALL', 'LOAN', 'PAYMENT'], true);

$db = db();
$staffStmt = $db->prepare(
    'SELECT u.id, u.name
     FROM users u
     INNER JOIN user_garages ug ON ug.user_id = u.id AND ug.garage_id = :garage_id
     WHERE u.company_id = :company_id
       AND u.status_code = "ACTIVE"
     ORDER BY u.name ASC'
);
$staffStmt->execute([
    'garage_id' => $garageId,
    'company_id' => $companyId,
]);
$staffList = $staffStmt->fetchAll();

$structureWhere = ['ss.company_id = :company_id', 'ss.garage_id = :garage_id'];
$structureParams = ['company_id' => $companyId, 'garage_id' => $garageId];
if ($staffSearch !== '') {
    $structureWhere[] = 'u.name LIKE :staff_q';
    $structureParams['staff_q'] = '%' . $staffSearch . '%';
}
$structureStmt = $db->prepare(
    'SELECT ss.*, u.name
     FROM payroll_salary_structures ss
     INNER JOIN users u ON u.id = ss.user_id
     WHERE ' . implode(' AND ', $structureWhere) . '
     ORDER BY u.name ASC'
);
$structureStmt->execute($structureParams);
$structures = $structureStmt->fetchAll();

$advanceWhere = ['pa.company_id = :company_id', 'pa.garage_id = :garage_id', 'pa.status <> "DELETED"'];
$advanceParams = ['company_id' => $companyId, 'garage_id' => $garageId];
if ($staffSearch !== '') {
    $advanceWhere[] = 'u.name LIKE :staff_q';
    $advanceParams['staff_q'] = '%' . $staffSearch . '%';
}
$advanceStmt = $db->prepare(
    'SELECT pa.*, u.name, (pa.amount - pa.applied_amount) AS pending
     FROM payroll_advances pa
     INNER JOIN users u ON u.id = pa.user_id
     WHERE ' . implode(' AND ', $advanceWhere) . '
     ORDER BY pa.advance_date DESC, pa.id DESC
     LIMIT 80'
);
$advanceStmt->execute($advanceParams);
$advances = $advanceStmt->fetchAll();

$loanWhere = ['pl.company_id = :company_id', 'pl.garage_id = :garage_id', 'pl.status <> "DELETED"'];
$loanParams = ['company_id' => $companyId, 'garage_id' => $garageId];
if ($staffSearch !== '') {
    $loanWhere[] = 'u.name LIKE :staff_q';
    $loanParams['staff_q'] = '%' . $staffSearch . '%';
}
$loanStmt = $db->prepare(
    'SELECT pl.*, u.name, (pl.total_amount - pl.paid_amount) AS pending
     FROM payroll_loans pl
     INNER JOIN users u ON u.id = pl.user_id
     WHERE ' . implode(' AND ', $loanWhere) . '
     ORDER BY pl.loan_date DESC, pl.id DESC
     LIMIT 80'
);
$loanStmt->execute($loanParams);
$loans = $loanStmt->fetchAll();

$paymentWhere = ['lp.company_id = :company_id', 'lp.garage_id = :garage_id'];
$paymentParams = ['company_id' => $companyId, 'garage_id' => $garageId];
if ($staffSearch !== '') {
    $paymentWhere[] = 'u.name LIKE :staff_q';
    $paymentParams['staff_q'] = '%' . $staffSearch . '%';
}
$paymentStmt = $db->prepare(
    'SELECT lp.*, u.name
     FROM payroll_loan_payments lp
     INNER JOIN payroll_loans pl ON pl.id = lp.loan_id
     INNER JOIN users u ON u.id = pl.user_id
     WHERE ' . implode(' AND ', $paymentWhere) . '
     ORDER BY lp.payment_date DESC, lp.id DESC
     LIMIT 80'
);
$paymentStmt->execute($paymentParams);
$loanPayments = $paymentStmt->fetchAll();

$reversedLoanPaymentIds = [];
foreach ($loanPayments as $row) {
    $reversedId = (int) ($row['reversed_payment_id'] ?? 0);
    if ($reversedId > 0) {
        $reversedLoanPaymentIds[$reversedId] = true;
    }
}

$activeStructureCount = 0;
foreach ($structures as $row) {
    if (strtoupper((string) ($row['status_code'] ?? 'ACTIVE')) === 'ACTIVE') {
        $activeStructureCount++;
    }
}

$advancePendingTotal = 0.0;
foreach ($advances as $row) {
    $advancePendingTotal += max(0.0, round((float) ($row['pending'] ?? 0), 2));
}
$advancePendingTotal = round($advancePendingTotal, 2);

$loanPendingTotal = 0.0;
foreach ($loans as $row) {
    $loanPendingTotal += max(0.0, round((float) ($row['pending'] ?? 0), 2));
}
$loanPendingTotal = round($loanPendingTotal, 2);

$manualLoanPaymentCount = 0;
foreach ($loanPayments as $row) {
    $entryType = strtoupper((string) ($row['entry_type'] ?? ''));
    $paymentId = (int) ($row['id'] ?? 0);
    if ($entryType === 'MANUAL' && !isset($reversedLoanPaymentIds[$paymentId])) {
        $manualLoanPaymentCount++;
    }
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Payroll Setup</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/payroll/index.php')); ?>">Payroll</a></li>
            <li class="breadcrumb-item active">Setup</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="row g-3 mb-3">
        <div class="col-md-3">
          <div class="small-box text-bg-primary">
            <div class="inner"><h4><?= number_format($activeStructureCount) ?></h4><p>Active Structures</p></div>
            <span class="small-box-icon"><i class="bi bi-diagram-3"></i></span>
          </div>
        </div>
        <div class="col-md-3">
          <div class="small-box text-bg-warning">
            <div class="inner"><h4><?= e(format_currency($advancePendingTotal)); ?></h4><p>Advance Pending</p></div>
            <span class="small-box-icon"><i class="bi bi-wallet2"></i></span>
          </div>
        </div>
        <div class="col-md-3">
          <div class="small-box text-bg-info">
            <div class="inner"><h4><?= e(format_currency($loanPendingTotal)); ?></h4><p>Loan Pending</p></div>
            <span class="small-box-icon"><i class="bi bi-bank"></i></span>
          </div>
        </div>
        <div class="col-md-3">
          <div class="small-box text-bg-success">
            <div class="inner"><h4><?= number_format($manualLoanPaymentCount) ?></h4><p>Open Manual Payments</p></div>
            <span class="small-box-icon"><i class="bi bi-cash-coin"></i></span>
          </div>
        </div>
      </div>

      <div class="card card-primary mb-3">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
          <h3 class="card-title mb-0">Filters</h3>
          <div class="d-flex flex-wrap gap-2">
            <a href="<?= e(url('modules/payroll/index.php')); ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Payroll Dashboard</a>
            <a href="<?= e(url('modules/reports/payroll.php')); ?>" class="btn btn-sm btn-outline-light"><i class="bi bi-graph-up me-1"></i>Payroll Reports</a>
          </div>
        </div>
        <div class="card-body">
          <form method="get" class="row g-2 align-items-end" data-master-filter-form="1">
            <div class="col-md-3">
              <label class="form-label">Section</label>
              <select name="section" class="form-select">
                <option value="ALL" <?= $sectionFilter === 'ALL' ? 'selected' : ''; ?>>All</option>
                <option value="STRUCTURE" <?= $sectionFilter === 'STRUCTURE' ? 'selected' : ''; ?>>Salary Structure</option>
                <option value="ADVANCE" <?= $sectionFilter === 'ADVANCE' ? 'selected' : ''; ?>>Advances</option>
                <option value="LOAN" <?= $sectionFilter === 'LOAN' ? 'selected' : ''; ?>>Loans</option>
                <option value="PAYMENT" <?= $sectionFilter === 'PAYMENT' ? 'selected' : ''; ?>>Loan Payments</option>
              </select>
            </div>
            <div class="col-md-5">
              <label class="form-label">Staff Search</label>
              <input type="text" name="staff_q" value="<?= e($staffSearch) ?>" class="form-control" placeholder="Filter by staff name" />
            </div>
            <div class="col-md-4 d-flex gap-2">
              <button class="btn btn-primary" type="submit">Apply</button>
              <a href="<?= e(url('modules/payroll/master_forms.php')); ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
          </form>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-4">
          <div class="card card-outline card-secondary mb-3">
            <div class="card-header"><h3 class="card-title mb-0">Financial Safety Rules</h3></div>
            <div class="card-body">
              <ul class="mb-0 ps-3">
                <li>Direct deletion is blocked once entries are financially used.</li>
                <li>Use reverse actions to preserve audit and payment history.</li>
                <li>Salary reconciliation must happen before row edits.</li>
                <li>All setup changes are scoped by company and garage.</li>
              </ul>
            </div>
          </div>

          <div class="card card-outline card-dark">
            <div class="card-header"><h3 class="card-title mb-0">Quick Snapshot</h3></div>
            <div class="card-body">
              <div class="mb-2"><strong>Total Structures:</strong> <?= number_format(count($structures)) ?></div>
              <div class="mb-2"><strong>Total Advances:</strong> <?= number_format(count($advances)) ?></div>
              <div class="mb-2"><strong>Total Loans:</strong> <?= number_format(count($loans)) ?></div>
              <div class="mb-0"><strong>Loan Payment Rows:</strong> <?= number_format(count($loanPayments)) ?></div>
            </div>
          </div>
        </div>

        <div class="col-lg-8">
          <?php if ($showStructure): ?>
            <div id="salary-structure" class="card card-outline card-primary mb-3">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Salary Structure</h3>
                <?php if ($canManage): ?>
                  <button type="button" class="btn btn-sm btn-primary js-structure-modal-btn" data-mode="create" data-bs-toggle="modal" data-bs-target="#structureModal"><i class="bi bi-plus-circle me-1"></i>Create</button>
                <?php endif; ?>
              </div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                  <thead>
                    <tr>
                      <th>Staff</th>
                      <th>Type</th>
                      <th>Base</th>
                      <th>Commission %</th>
                      <th>Overtime</th>
                      <th>Status</th>
                      <th style="min-width: 140px;">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($structures)): ?>
                      <tr><td colspan="7" class="text-center text-muted py-3">No salary structures found.</td></tr>
                    <?php else: ?>
                      <?php foreach ($structures as $row): ?>
                        <?php $status = strtoupper((string) ($row['status_code'] ?? 'ACTIVE')); ?>
                        <tr>
                          <td><?= e((string) ($row['name'] ?? '')) ?></td>
                          <td><?= e((string) ($row['salary_type'] ?? 'MONTHLY')) ?></td>
                          <td><?= format_currency((float) ($row['base_amount'] ?? 0)) ?></td>
                          <td><?= e((string) ($row['commission_rate'] ?? '0')) ?></td>
                          <td><?= e((string) ($row['overtime_rate'] ?? '0')) ?></td>
                          <td><span class="badge text-bg-<?= e(payroll_master_badge_class($status)) ?>"><?= e($status) ?></span></td>
                          <td class="text-nowrap">
                            <?php if ($canManage): ?>
                              <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Action</button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                  <li><button type="button" class="dropdown-item js-structure-modal-btn" data-mode="edit" data-bs-toggle="modal" data-bs-target="#structureModal" data-user-id="<?= (int) ($row['user_id'] ?? 0) ?>" data-salary-type="<?= e((string) ($row['salary_type'] ?? 'MONTHLY')) ?>" data-base-amount="<?= e((string) ($row['base_amount'] ?? '0')) ?>" data-commission-rate="<?= e((string) ($row['commission_rate'] ?? '0')) ?>" data-overtime-rate="<?= e((string) ($row['overtime_rate'] ?? '0')) ?>" data-status-code="<?= e($status) ?>">Edit</button></li>
                                  <li><hr class="dropdown-divider"></li>
                                  <li><button type="button" class="dropdown-item text-danger js-master-reverse-btn" data-bs-toggle="modal" data-bs-target="#masterReverseModal" data-action="delete_structure" data-id-field="structure" data-id="<?= (int) ($row['id'] ?? 0) ?>" data-label="<?= e((string) ($row['name'] ?? '') . ' | ' . format_currency((float) ($row['base_amount'] ?? 0))) ?>" data-hint="This will mark the structure inactive." <?= $status === 'INACTIVE' ? 'disabled' : ''; ?>>Inactivate</button></li>
                                </ul>
                              </div>
                            <?php else: ?>
                              <span class="text-muted small">-</span>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($showAdvance): ?>
            <div id="advances" class="card card-outline card-success mb-3">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Advances</h3>
                <?php if ($canManage): ?>
                  <button type="button" class="btn btn-sm btn-success js-advance-modal-btn" data-mode="create" data-bs-toggle="modal" data-bs-target="#advanceModal"><i class="bi bi-plus-circle me-1"></i>Record</button>
                <?php endif; ?>
              </div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                  <thead>
                    <tr>
                      <th>Staff</th>
                      <th>Date</th>
                      <th>Amount</th>
                      <th>Applied</th>
                      <th>Pending</th>
                      <th>Status</th>
                      <th>Notes</th>
                      <th style="min-width: 140px;">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($advances)): ?>
                      <tr><td colspan="8" class="text-center text-muted py-3">No advance entries.</td></tr>
                    <?php else: ?>
                      <?php foreach ($advances as $row): ?>
                        <?php
                          $applied = round((float) ($row['applied_amount'] ?? 0), 2);
                          $pending = max(0.0, round((float) ($row['pending'] ?? 0), 2));
                          $status = strtoupper((string) ($row['status'] ?? 'OPEN'));
                          $canReverse = $applied <= 0.009;
                        ?>
                        <tr>
                          <td><?= e((string) ($row['name'] ?? '')) ?></td>
                          <td><?= e((string) ($row['advance_date'] ?? '')) ?></td>
                          <td><?= format_currency((float) ($row['amount'] ?? 0)) ?></td>
                          <td><?= format_currency($applied) ?></td>
                          <td><?= format_currency($pending) ?></td>
                          <td><span class="badge text-bg-<?= e(payroll_master_badge_class($status)) ?>"><?= e($status) ?></span></td>
                          <td><?= e((string) ($row['notes'] ?? '')) ?></td>
                          <td class="text-nowrap">
                            <?php if ($canManage): ?>
                              <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Action</button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                  <li><button type="button" class="dropdown-item js-advance-modal-btn" data-mode="edit" data-bs-toggle="modal" data-bs-target="#advanceModal" data-advance-id="<?= (int) ($row['id'] ?? 0) ?>" data-staff-name="<?= e((string) ($row['name'] ?? '')) ?>" data-advance-date="<?= e((string) ($row['advance_date'] ?? '')) ?>" data-amount="<?= e((string) ($row['amount'] ?? '0')) ?>" data-notes="<?= e((string) ($row['notes'] ?? '')) ?>">Edit</button></li>
                                  <li><hr class="dropdown-divider"></li>
                                  <li><button type="button" class="dropdown-item text-danger js-master-reverse-btn" data-bs-toggle="modal" data-bs-target="#masterReverseModal" data-action="delete_advance" data-id-field="advance" data-id="<?= (int) ($row['id'] ?? 0) ?>" data-label="<?= e((string) ($row['name'] ?? '') . ' | ' . format_currency((float) ($row['amount'] ?? 0))) ?>" data-hint="Applied advances cannot be reversed directly." <?= $canReverse ? '' : 'disabled'; ?>>Reverse Entry</button></li>
                                </ul>
                              </div>
                              <?php if (!$canReverse): ?><div><small class="text-muted">Applied advance must be reconciled first.</small></div><?php endif; ?>
                            <?php else: ?>
                              <span class="text-muted small">-</span>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($showLoan): ?>
            <div id="loans" class="card card-outline card-warning mb-3">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Loans</h3>
                <?php if ($canManage): ?>
                  <button type="button" class="btn btn-sm btn-warning text-dark js-loan-modal-btn" data-mode="create" data-bs-toggle="modal" data-bs-target="#loanModal"><i class="bi bi-plus-circle me-1"></i>Record</button>
                <?php endif; ?>
              </div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                  <thead>
                    <tr>
                      <th>Staff</th>
                      <th>Date</th>
                      <th>Total</th>
                      <th>Paid</th>
                      <th>Pending</th>
                      <th>EMI</th>
                      <th>Status</th>
                      <th>Notes</th>
                      <th style="min-width: 140px;">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($loans)): ?>
                      <tr><td colspan="9" class="text-center text-muted py-3">No loan entries.</td></tr>
                    <?php else: ?>
                      <?php foreach ($loans as $row): ?>
                        <?php
                          $paid = round((float) ($row['paid_amount'] ?? 0), 2);
                          $pending = max(0.0, round((float) ($row['pending'] ?? 0), 2));
                          $status = strtoupper((string) ($row['status'] ?? 'ACTIVE'));
                          $canReverse = $paid <= 0.009;
                          $canPay = $pending > 0.009 && $status !== 'PAID';
                        ?>
                        <tr>
                          <td><?= e((string) ($row['name'] ?? '')) ?></td>
                          <td><?= e((string) ($row['loan_date'] ?? '')) ?></td>
                          <td><?= format_currency((float) ($row['total_amount'] ?? 0)) ?></td>
                          <td><?= format_currency($paid) ?></td>
                          <td><?= format_currency($pending) ?></td>
                          <td><?= format_currency((float) ($row['emi_amount'] ?? 0)) ?></td>
                          <td><span class="badge text-bg-<?= e(payroll_master_badge_class($status)) ?>"><?= e($status) ?></span></td>
                          <td><?= e((string) ($row['notes'] ?? '')) ?></td>
                          <td class="text-nowrap">
                            <?php if ($canManage): ?>
                              <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Action</button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                  <li><button type="button" class="dropdown-item js-loan-modal-btn" data-mode="edit" data-bs-toggle="modal" data-bs-target="#loanModal" data-loan-id="<?= (int) ($row['id'] ?? 0) ?>" data-staff-name="<?= e((string) ($row['name'] ?? '')) ?>" data-loan-date="<?= e((string) ($row['loan_date'] ?? '')) ?>" data-total-amount="<?= e((string) ($row['total_amount'] ?? '0')) ?>" data-emi-amount="<?= e((string) ($row['emi_amount'] ?? '0')) ?>" data-notes="<?= e((string) ($row['notes'] ?? '')) ?>">Edit</button></li>
                                  <li><button type="button" class="dropdown-item js-loan-manual-pay-btn" data-bs-toggle="modal" data-bs-target="#loanManualPaymentModal" data-loan-id="<?= (int) ($row['id'] ?? 0) ?>" data-staff-name="<?= e((string) ($row['name'] ?? '')) ?>" data-pending="<?= e((string) $pending) ?>" data-pending-label="<?= e(format_currency($pending)) ?>" <?= $canPay ? '' : 'disabled'; ?>>Record Payment</button></li>
                                  <li><hr class="dropdown-divider"></li>
                                  <li><button type="button" class="dropdown-item text-danger js-master-reverse-btn" data-bs-toggle="modal" data-bs-target="#masterReverseModal" data-action="delete_loan" data-id-field="loan" data-id="<?= (int) ($row['id'] ?? 0) ?>" data-label="<?= e((string) ($row['name'] ?? '') . ' | ' . format_currency((float) ($row['total_amount'] ?? 0))) ?>" data-hint="Loan reversal is blocked once payments exist." <?= $canReverse ? '' : 'disabled'; ?>>Reverse Entry</button></li>
                                </ul>
                              </div>
                              <?php if (!$canReverse): ?><div><small class="text-muted">Reverse loan payments first to reconcile.</small></div><?php endif; ?>
                            <?php else: ?>
                              <span class="text-muted small">-</span>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="card card-outline card-info mb-3">
              <div class="card-header"><h3 class="card-title mb-0">Loan Payment History</h3></div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th>Staff</th>
                      <th>Type</th>
                      <th>Amount</th>
                      <th>Notes</th>
                      <th>Status</th>
                      <th style="min-width: 140px;">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($loanPayments)): ?>
                      <tr><td colspan="7" class="text-center text-muted py-3">No loan payment history.</td></tr>
                    <?php else: ?>
                      <?php foreach ($loanPayments as $row): ?>
                        <?php
                          $paymentId = (int) ($row['id'] ?? 0);
                          $entryType = strtoupper((string) ($row['entry_type'] ?? ''));
                          $isReversed = isset($reversedLoanPaymentIds[$paymentId]);
                          $canReversePayment = $canManage && $entryType === 'MANUAL' && !$isReversed && (float) ($row['amount'] ?? 0) > 0;
                        ?>
                        <tr>
                          <td><?= e((string) ($row['payment_date'] ?? '')) ?></td>
                          <td><?= e((string) ($row['name'] ?? '')) ?></td>
                          <td><?= e($entryType) ?></td>
                          <td><?= format_currency((float) ($row['amount'] ?? 0)) ?></td>
                          <td><?= e((string) ($row['notes'] ?? '')) ?></td>
                          <td><?= $isReversed ? '<span class="badge text-bg-secondary">Reversed</span>' : '<span class="badge text-bg-success">Open</span>' ?></td>
                          <td class="text-nowrap">
                            <?php if ($canManage): ?>
                              <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Action</button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                  <li><button type="button" class="dropdown-item text-danger js-loan-payment-reverse-btn" data-bs-toggle="modal" data-bs-target="#loanPaymentReverseModal" data-payment-id="<?= $paymentId ?>" data-payment-label="<?= e((string) ($row['payment_date'] ?? '') . ' | ' . (string) ($row['name'] ?? '') . ' | ' . format_currency((float) ($row['amount'] ?? 0))) ?>" <?= $canReversePayment ? '' : 'disabled'; ?>>Reverse Payment</button></li>
                                </ul>
                              </div>
                              <?php if (!$canReversePayment): ?><div><small class="text-muted"><?= $isReversed ? 'Already reversed.' : 'Only manual positive payments can be reversed.'; ?></small></div><?php endif; ?>
                            <?php else: ?>
                              <span class="text-muted small">-</span>
                            <?php endif; ?>
                          </td>
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
    </div>
  </div>
</main>
<?php if ($canManage): ?><div class="modal fade" id="structureModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="structure-modal-title">Create Salary Structure</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <form method="post" action="<?= e(url('modules/payroll/index.php')); ?>" class="ajax-form">
        <div class="modal-body">
          <?= csrf_field(); ?>
          <input type="hidden" name="_action" value="save_structure" />
          <input type="hidden" name="return_to" value="<?= e($returnTo) ?>" />
          <div class="row g-2">
            <div class="col-md-6"><label class="form-label">Staff</label><select name="user_id" id="structure-user-id" class="form-select" required><option value="">Select staff</option><?php foreach ($staffList as $staff): ?><option value="<?= (int) ($staff['id'] ?? 0) ?>"><?= e((string) ($staff['name'] ?? '')) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label">Salary Type</label><select name="salary_type" id="structure-salary-type" class="form-select"><option value="MONTHLY">MONTHLY</option><option value="PER_DAY">PER_DAY</option><option value="PER_JOB">PER_JOB</option></select></div>
            <div class="col-md-4"><label class="form-label">Base Amount</label><input type="number" step="0.01" min="0" name="base_amount" id="structure-base-amount" class="form-control" required /></div>
            <div class="col-md-4"><label class="form-label">Commission %</label><input type="number" step="0.001" min="0" name="commission_rate" id="structure-commission-rate" class="form-control" /></div>
            <div class="col-md-4"><label class="form-label">Overtime Rate</label><input type="number" step="0.01" min="0" name="overtime_rate" id="structure-overtime-rate" class="form-control" /></div>
            <div class="col-md-4"><label class="form-label">Status</label><select name="status_code" id="structure-status-code" class="form-select"><option value="ACTIVE">ACTIVE</option><option value="INACTIVE">INACTIVE</option></select></div>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary" id="structure-submit-btn">Save Structure</button></div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="advanceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="advance-modal-title">Record Advance</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <form method="post" action="<?= e(url('modules/payroll/index.php')); ?>" class="ajax-form">
        <div class="modal-body">
          <?= csrf_field(); ?>
          <input type="hidden" name="_action" id="advance-action" value="record_advance" />
          <input type="hidden" name="return_to" value="<?= e($returnTo) ?>" />
          <input type="hidden" name="advance_id" id="advance-id" />
          <div class="row g-2">
            <div class="col-md-6" id="advance-user-wrap"><label class="form-label">Staff</label><select name="user_id" id="advance-user-id" class="form-select" required><option value="">Select staff</option><?php foreach ($staffList as $staff): ?><option value="<?= (int) ($staff['id'] ?? 0) ?>"><?= e((string) ($staff['name'] ?? '')) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6 d-none" id="advance-staff-wrap"><label class="form-label">Staff</label><input type="text" id="advance-staff-name" class="form-control" readonly /></div>
            <div class="col-md-3"><label class="form-label">Date</label><input type="date" name="advance_date" id="advance-date" class="form-control" required /></div>
            <div class="col-md-3"><label class="form-label">Amount</label><input type="number" step="0.01" min="0.01" name="amount" id="advance-amount" class="form-control" required /></div>
            <div class="col-md-12"><label class="form-label">Notes</label><input type="text" name="notes" id="advance-notes" class="form-control" maxlength="255" /></div>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-success" id="advance-submit-btn">Save Advance</button></div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="loanModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="loan-modal-title">Record Loan</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <form method="post" action="<?= e(url('modules/payroll/index.php')); ?>" class="ajax-form">
        <div class="modal-body">
          <?= csrf_field(); ?>
          <input type="hidden" name="_action" id="loan-action" value="record_loan" />
          <input type="hidden" name="return_to" value="<?= e($returnTo) ?>" />
          <input type="hidden" name="loan_id" id="loan-id" />
          <div class="row g-2">
            <div class="col-md-6" id="loan-user-wrap"><label class="form-label">Staff</label><select name="user_id" id="loan-user-id" class="form-select" required><option value="">Select staff</option><?php foreach ($staffList as $staff): ?><option value="<?= (int) ($staff['id'] ?? 0) ?>"><?= e((string) ($staff['name'] ?? '')) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6 d-none" id="loan-staff-wrap"><label class="form-label">Staff</label><input type="text" id="loan-staff-name" class="form-control" readonly /></div>
            <div class="col-md-3"><label class="form-label">Date</label><input type="date" name="loan_date" id="loan-date" class="form-control" required /></div>
            <div class="col-md-3"><label class="form-label">Total Amount</label><input type="number" step="0.01" min="0.01" name="total_amount" id="loan-total-amount" class="form-control" required /></div>
            <div class="col-md-3"><label class="form-label">EMI Amount</label><input type="number" step="0.01" min="0" name="emi_amount" id="loan-emi-amount" class="form-control" /></div>
            <div class="col-md-12"><label class="form-label">Notes</label><input type="text" name="notes" id="loan-notes" class="form-control" maxlength="255" /></div>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-warning text-dark" id="loan-submit-btn">Save Loan</button></div>
      </form>
    </div>
  </div>
</div><div class="modal fade" id="masterReverseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Reverse Entry</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <form method="post" action="<?= e(url('modules/payroll/index.php')); ?>" class="ajax-form">
        <div class="modal-body">
          <?= csrf_field(); ?>
          <input type="hidden" name="_action" id="master-reverse-action" />
          <input type="hidden" name="return_to" value="<?= e($returnTo) ?>" />
          <input type="hidden" name="structure_id" id="master-reverse-structure-id" />
          <input type="hidden" name="advance_id" id="master-reverse-advance-id" />
          <input type="hidden" name="loan_id" id="master-reverse-loan-id" />
          <div class="mb-2"><label class="form-label">Entry</label><input type="text" id="master-reverse-label" class="form-control" readonly /></div>
          <p class="small text-muted mb-0" id="master-reverse-hint"></p>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-danger">Confirm</button></div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="loanManualPaymentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Record Manual Loan Payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <form method="post" action="<?= e(url('modules/payroll/index.php')); ?>" class="ajax-form">
        <div class="modal-body">
          <?= csrf_field(); ?>
          <input type="hidden" name="_action" value="loan_manual_payment" />
          <input type="hidden" name="return_to" value="<?= e($returnTo) ?>" />
          <input type="hidden" name="loan_id" id="loan-pay-loan-id" />
          <div class="mb-2"><label class="form-label">Staff</label><input type="text" id="loan-pay-staff" class="form-control" readonly /></div>
          <div class="mb-2"><label class="form-label">Pending</label><input type="text" id="loan-pay-pending" class="form-control" readonly /></div>
          <div class="row g-2">
            <div class="col-md-6"><label class="form-label">Amount</label><input type="number" step="0.01" min="0.01" name="amount" id="loan-pay-amount" class="form-control" required /></div>
            <div class="col-md-6"><label class="form-label">Date</label><input type="date" name="payment_date" value="<?= e($today) ?>" class="form-control" required /></div>
            <div class="col-md-12"><label class="form-label">Notes</label><input type="text" name="notes" class="form-control" maxlength="255" /></div>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-success">Record Payment</button></div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="loanPaymentReverseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Reverse Loan Payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <form method="post" action="<?= e(url('modules/payroll/index.php')); ?>" class="ajax-form">
        <div class="modal-body">
          <?= csrf_field(); ?>
          <input type="hidden" name="_action" value="reverse_loan_payment" />
          <input type="hidden" name="return_to" value="<?= e($returnTo) ?>" />
          <input type="hidden" name="payment_id" id="loan-payment-reverse-id" />
          <div class="mb-2"><label class="form-label">Payment Entry</label><input type="text" id="loan-payment-reverse-label" class="form-control" readonly /></div>
          <div class="mb-2"><label class="form-label">Reversal Reason</label><input type="text" name="reverse_reason" class="form-control" maxlength="255" required /></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-danger">Reverse Payment</button></div>
      </form>
    </div>
  </div>
</div>

<script>
  (function () {
    function setValue(id, value) {
      var el = document.getElementById(id);
      if (el) {
        el.value = value || '';
      }
    }

    function setText(id, value) {
      var el = document.getElementById(id);
      if (el) {
        el.textContent = value || '';
      }
    }

    function setSelect(id, value, fallback) {
      var el = document.getElementById(id);
      if (!el) {
        return;
      }
      var matched = false;
      for (var i = 0; i < el.options.length; i++) {
        if (el.options[i].value === value) {
          matched = true;
          break;
        }
      }
      el.value = matched ? value : fallback;
    }

    function toggleClass(id, className, show) {
      var el = document.getElementById(id);
      if (el) {
        el.classList[show ? 'remove' : 'add'](className);
      }
    }
    document.addEventListener('click', function (event) {
      var structureBtn = event.target.closest('.js-structure-modal-btn');
      if (structureBtn && !structureBtn.disabled) {
        var mode = structureBtn.getAttribute('data-mode') || 'create';
        setText('structure-modal-title', mode === 'edit' ? 'Edit Salary Structure' : 'Create Salary Structure');
        setText('structure-submit-btn', mode === 'edit' ? 'Update Structure' : 'Save Structure');
        setSelect('structure-user-id', structureBtn.getAttribute('data-user-id') || '', '');
        setSelect('structure-salary-type', structureBtn.getAttribute('data-salary-type') || 'MONTHLY', 'MONTHLY');
        setValue('structure-base-amount', structureBtn.getAttribute('data-base-amount') || '');
        setValue('structure-commission-rate', structureBtn.getAttribute('data-commission-rate') || '');
        setValue('structure-overtime-rate', structureBtn.getAttribute('data-overtime-rate') || '');
        setSelect('structure-status-code', structureBtn.getAttribute('data-status-code') || 'ACTIVE', 'ACTIVE');
        if (mode === 'create') {
          setSelect('structure-user-id', '', '');
          setSelect('structure-salary-type', 'MONTHLY', 'MONTHLY');
          setValue('structure-base-amount', '');
          setValue('structure-commission-rate', '0');
          setValue('structure-overtime-rate', '0');
          setSelect('structure-status-code', 'ACTIVE', 'ACTIVE');
        }
      }

      var advanceBtn = event.target.closest('.js-advance-modal-btn');
      if (advanceBtn && !advanceBtn.disabled) {
        var aMode = advanceBtn.getAttribute('data-mode') || 'create';
        var createMode = aMode === 'create';
        setText('advance-modal-title', createMode ? 'Record Advance' : 'Edit Advance');
        setText('advance-submit-btn', createMode ? 'Save Advance' : 'Update Advance');
        setValue('advance-action', createMode ? 'record_advance' : 'update_advance');
        setValue('advance-id', createMode ? '' : advanceBtn.getAttribute('data-advance-id'));
        setValue('advance-date', createMode ? '<?= e($today) ?>' : (advanceBtn.getAttribute('data-advance-date') || '<?= e($today) ?>'));
        setValue('advance-amount', createMode ? '' : (advanceBtn.getAttribute('data-amount') || ''));
        setValue('advance-notes', createMode ? '' : (advanceBtn.getAttribute('data-notes') || ''));
        setValue('advance-staff-name', advanceBtn.getAttribute('data-staff-name') || '');
        toggleClass('advance-user-wrap', 'd-none', createMode);
        toggleClass('advance-staff-wrap', 'd-none', !createMode);
        var advanceUser = document.getElementById('advance-user-id');
        if (advanceUser) {
          advanceUser.required = createMode;
          if (createMode) {
            advanceUser.value = '';
          }
        }
      }

      var loanBtn = event.target.closest('.js-loan-modal-btn');
      if (loanBtn && !loanBtn.disabled) {
        var lMode = loanBtn.getAttribute('data-mode') || 'create';
        var lCreateMode = lMode === 'create';
        setText('loan-modal-title', lCreateMode ? 'Record Loan' : 'Edit Loan');
        setText('loan-submit-btn', lCreateMode ? 'Save Loan' : 'Update Loan');
        setValue('loan-action', lCreateMode ? 'record_loan' : 'update_loan');
        setValue('loan-id', lCreateMode ? '' : loanBtn.getAttribute('data-loan-id'));
        setValue('loan-date', lCreateMode ? '<?= e($today) ?>' : (loanBtn.getAttribute('data-loan-date') || '<?= e($today) ?>'));
        setValue('loan-total-amount', lCreateMode ? '' : (loanBtn.getAttribute('data-total-amount') || ''));
        setValue('loan-emi-amount', lCreateMode ? '' : (loanBtn.getAttribute('data-emi-amount') || ''));
        setValue('loan-notes', lCreateMode ? '' : (loanBtn.getAttribute('data-notes') || ''));
        setValue('loan-staff-name', loanBtn.getAttribute('data-staff-name') || '');
        toggleClass('loan-user-wrap', 'd-none', lCreateMode);
        toggleClass('loan-staff-wrap', 'd-none', !lCreateMode);
        var loanUser = document.getElementById('loan-user-id');
        if (loanUser) {
          loanUser.required = lCreateMode;
          if (lCreateMode) {
            loanUser.value = '';
          }
        }
      }

      var reverseBtn = event.target.closest('.js-master-reverse-btn');
      if (reverseBtn && !reverseBtn.disabled) {
        setValue('master-reverse-action', reverseBtn.getAttribute('data-action'));
        setValue('master-reverse-structure-id', '');
        setValue('master-reverse-advance-id', '');
        setValue('master-reverse-loan-id', '');
        var idField = reverseBtn.getAttribute('data-id-field') || '';
        var entryId = reverseBtn.getAttribute('data-id') || '';
        if (idField === 'structure') {
          setValue('master-reverse-structure-id', entryId);
        }
        if (idField === 'advance') {
          setValue('master-reverse-advance-id', entryId);
        }
        if (idField === 'loan') {
          setValue('master-reverse-loan-id', entryId);
        }
        setValue('master-reverse-label', reverseBtn.getAttribute('data-label'));
        setText('master-reverse-hint', reverseBtn.getAttribute('data-hint'));
      }

      var payBtn = event.target.closest('.js-loan-manual-pay-btn');
      if (payBtn && !payBtn.disabled) {
        var pending = payBtn.getAttribute('data-pending') || '';
        setValue('loan-pay-loan-id', payBtn.getAttribute('data-loan-id'));
        setValue('loan-pay-staff', payBtn.getAttribute('data-staff-name'));
        setValue('loan-pay-pending', payBtn.getAttribute('data-pending-label'));
        setValue('loan-pay-amount', pending);
        var amountField = document.getElementById('loan-pay-amount');
        if (amountField) {
          if (pending !== '') {
            amountField.setAttribute('max', pending);
          } else {
            amountField.removeAttribute('max');
          }
        }
      }

      var reversePaymentBtn = event.target.closest('.js-loan-payment-reverse-btn');
      if (reversePaymentBtn && !reversePaymentBtn.disabled) {
        setValue('loan-payment-reverse-id', reversePaymentBtn.getAttribute('data-payment-id'));
        setValue('loan-payment-reverse-label', reversePaymentBtn.getAttribute('data-payment-label'));
      }
    });
  })();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
