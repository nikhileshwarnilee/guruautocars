<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/ledger_common.php';

ledger_reports_require_financial_access();

$page_title = 'Trial Balance (Ledger)';
$active_menu = 'reports.ledger_trial_balance';

$scope = reports_build_scope_context();
$rows = ledger_reports_period_totals($scope);

$totalDebit = 0.0;
$totalCredit = 0.0;
foreach ($rows as $row) {
    $totalDebit = ledger_round($totalDebit + (float) ($row['debit_total'] ?? 0));
    $totalCredit = ledger_round($totalCredit + (float) ($row['credit_total'] ?? 0));
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
  <div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <h1 class="h4 mb-1">Trial Balance (Ledger)</h1>
        <p class="text-muted mb-0">Source: `ledger_entries` + `ledger_journals`</p>
      </div>
      <div class="text-end">
        <div><strong><?= e((string) ($scope['scope_garage_label'] ?? '-')) ?></strong></div>
        <small class="text-muted"><?= e((string) ($scope['from_date'] ?? '')) ?> to <?= e((string) ($scope['to_date'] ?? '')) ?></small>
      </div>
    </div>

    <?php reports_render_page_navigation($active_menu, (array) ($scope['base_params'] ?? [])); ?>

    <form method="get" class="card card-body mb-3">
      <div class="row g-2">
        <div class="col-md-3">
          <label class="form-label">Garage</label>
          <select name="garage_id" class="form-select">
            <?php if ((bool) ($scope['allow_all_garages'] ?? false)): ?>
              <option value="0" <?= ((int) ($scope['selected_garage_id'] ?? 0) === 0) ? 'selected' : '' ?>>All Accessible Garages</option>
            <?php endif; ?>
            <?php foreach ((array) ($scope['garage_options'] ?? []) as $garage): ?>
              <option value="<?= (int) ($garage['id'] ?? 0) ?>" <?= ((int) ($scope['selected_garage_id'] ?? 0) === (int) ($garage['id'] ?? 0)) ? 'selected' : '' ?>>
                <?= e((string) ($garage['name'] ?? ('Garage #' . (int) ($garage['id'] ?? 0)))) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Financial Year</label>
          <select name="fy_id" class="form-select">
            <?php foreach ((array) ($scope['financial_years'] ?? []) as $fy): ?>
              <option value="<?= (int) ($fy['id'] ?? 0) ?>" <?= ((int) ($scope['selected_fy_id'] ?? 0) === (int) ($fy['id'] ?? 0)) ? 'selected' : '' ?>>
                <?= e((string) ($fy['fy_label'] ?? ('FY #' . (int) ($fy['id'] ?? 0)))) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">From</label>
          <input type="date" name="from" class="form-control" value="<?= e((string) ($scope['from_date'] ?? '')) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">To</label>
          <input type="date" name="to" class="form-control" value="<?= e((string) ($scope['to_date'] ?? '')) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Date Mode</label>
          <select name="date_mode" class="form-select">
            <?php foreach ((array) ($scope['date_mode_options'] ?? []) as $modeKey => $modeLabel): ?>
              <option value="<?= e((string) $modeKey) ?>" <?= ((string) ($scope['date_mode'] ?? '') === (string) $modeKey) ? 'selected' : '' ?>>
                <?= e((string) $modeLabel) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-1 d-grid">
          <label class="form-label">&nbsp;</label>
          <button class="btn btn-primary" type="submit">Apply</button>
        </div>
      </div>
    </form>

    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <div class="card card-body">
          <small class="text-muted">Total Debits</small>
          <div class="h5 mb-0"><?= e(format_currency($totalDebit)) ?></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card card-body">
          <small class="text-muted">Total Credits</small>
          <div class="h5 mb-0"><?= e(format_currency($totalCredit)) ?></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card card-body">
          <small class="text-muted">Difference</small>
          <div class="h5 mb-0 <?= abs($totalDebit - $totalCredit) <= 0.009 ? 'text-success' : 'text-danger' ?>">
            <?= e(format_currency(ledger_round($totalDebit - $totalCredit))) ?>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle mb-0">
          <thead>
            <tr>
              <th>Code</th>
              <th>Account</th>
              <th>Type</th>
              <th class="text-end">Debit</th>
              <th class="text-end">Credit</th>
              <th class="text-end">Normal Balance</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($rows === []): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">No ledger entries in selected range.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <?php $normalBalance = ledger_reports_signed_balance_from_row($row); ?>
                <tr>
                  <td><?= e((string) ($row['code'] ?? '')) ?></td>
                  <td><?= e((string) ($row['name'] ?? '')) ?></td>
                  <td><?= e((string) ($row['type'] ?? '')) ?></td>
                  <td class="text-end"><?= e(number_format((float) ($row['debit_total'] ?? 0), 2)) ?></td>
                  <td class="text-end"><?= e(number_format((float) ($row['credit_total'] ?? 0), 2)) ?></td>
                  <td class="text-end"><?= e(number_format($normalBalance, 2)) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
