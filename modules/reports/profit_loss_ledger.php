<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/ledger_common.php';

ledger_reports_require_financial_access();

$page_title = 'Profit & Loss (Ledger)';
$active_menu = 'reports.ledger_profit_loss';

$scope = reports_build_scope_context();
$rows = ledger_reports_period_totals($scope);

$revenues = [];
$expenses = [];
$totalRevenue = 0.0;
$totalExpense = 0.0;

foreach ($rows as $row) {
    $type = strtoupper(trim((string) ($row['type'] ?? '')));
    $debit = (float) ($row['debit_total'] ?? 0);
    $credit = (float) ($row['credit_total'] ?? 0);
    if ($type === 'REVENUE') {
        $amount = ledger_round($credit - $debit);
        if (abs($amount) > 0.009) {
            $row['net_amount'] = $amount;
            $revenues[] = $row;
            $totalRevenue = ledger_round($totalRevenue + $amount);
        }
    } elseif ($type === 'EXPENSE') {
        $amount = ledger_round($debit - $credit);
        if (abs($amount) > 0.009) {
            $row['net_amount'] = $amount;
            $expenses[] = $row;
            $totalExpense = ledger_round($totalExpense + $amount);
        }
    }
}

$netProfit = ledger_round($totalRevenue - $totalExpense);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
  <div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <div>
        <h1 class="h4 mb-1">Profit & Loss (Ledger)</h1>
        <p class="text-muted mb-0"><?= e((string) ($scope['from_date'] ?? '')) ?> to <?= e((string) ($scope['to_date'] ?? '')) ?></p>
      </div>
      <div class="text-end">
        <div><strong><?= e((string) ($scope['scope_garage_label'] ?? '-')) ?></strong></div>
        <small class="text-muted">Ledger driven</small>
      </div>
    </div>

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
          <label class="form-label">FY</label>
          <select name="fy_id" class="form-select">
            <?php foreach ((array) ($scope['financial_years'] ?? []) as $fy): ?>
              <option value="<?= (int) ($fy['id'] ?? 0) ?>" <?= ((int) ($scope['selected_fy_id'] ?? 0) === (int) ($fy['id'] ?? 0)) ? 'selected' : '' ?>>
                <?= e((string) ($fy['fy_label'] ?? '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= e((string) ($scope['from_date'] ?? '')) ?>"></div>
        <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= e((string) ($scope['to_date'] ?? '')) ?>"></div>
        <div class="col-md-2"><label class="form-label">Date Mode</label>
          <select name="date_mode" class="form-select">
            <?php foreach ((array) ($scope['date_mode_options'] ?? []) as $modeKey => $modeLabel): ?>
              <option value="<?= e((string) $modeKey) ?>" <?= ((string) ($scope['date_mode'] ?? '') === (string) $modeKey) ? 'selected' : '' ?>><?= e((string) $modeLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-1 d-grid"><label class="form-label">&nbsp;</label><button class="btn btn-primary" type="submit">Apply</button></div>
      </div>
    </form>

    <div class="row g-3 mb-3">
      <div class="col-md-4"><div class="card card-body"><small class="text-muted">Revenue</small><div class="h5 mb-0 text-success"><?= e(format_currency($totalRevenue)) ?></div></div></div>
      <div class="col-md-4"><div class="card card-body"><small class="text-muted">Expense</small><div class="h5 mb-0 text-danger"><?= e(format_currency($totalExpense)) ?></div></div></div>
      <div class="col-md-4"><div class="card card-body"><small class="text-muted">Net Profit / (Loss)</small><div class="h5 mb-0 <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?>"><?= e(format_currency($netProfit)) ?></div></div></div>
    </div>

    <div class="row g-3">
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header">Revenue Accounts</div>
          <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
              <thead><tr><th>Code</th><th>Account</th><th class="text-end">Amount</th></tr></thead>
              <tbody>
                <?php if ($revenues === []): ?>
                  <tr><td colspan="3" class="text-center text-muted py-3">No revenue postings.</td></tr>
                <?php else: foreach ($revenues as $row): ?>
                  <tr>
                    <td><?= e((string) ($row['code'] ?? '')) ?></td>
                    <td><?= e((string) ($row['name'] ?? '')) ?></td>
                    <td class="text-end"><?= e(number_format((float) ($row['net_amount'] ?? 0), 2)) ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header">Expense Accounts</div>
          <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
              <thead><tr><th>Code</th><th>Account</th><th class="text-end">Amount</th></tr></thead>
              <tbody>
                <?php if ($expenses === []): ?>
                  <tr><td colspan="3" class="text-center text-muted py-3">No expense postings.</td></tr>
                <?php else: foreach ($expenses as $row): ?>
                  <tr>
                    <td><?= e((string) ($row['code'] ?? '')) ?></td>
                    <td><?= e((string) ($row['name'] ?? '')) ?></td>
                    <td class="text-end"><?= e(number_format((float) ($row['net_amount'] ?? 0), 2)) ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

