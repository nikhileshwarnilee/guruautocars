<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/ledger_common.php';

ledger_reports_require_financial_access();

$page_title = 'Balance Sheet (Ledger)';
$active_menu = 'reports.ledger_balance_sheet';

$scope = reports_build_scope_context();
$toDate = (string) ($scope['to_date'] ?? date('Y-m-d'));
$rows = ledger_reports_closing_balances_upto($scope, $toDate);

$assets = [];
$liabilities = [];
$equity = [];
$assetsTotal = 0.0;
$liabilitiesTotal = 0.0;
$equityTotal = 0.0;
$netIncomeToDate = 0.0;

foreach ($rows as $row) {
    $type = strtoupper(trim((string) ($row['type'] ?? '')));
    $signed = ledger_reports_signed_balance_from_row($row);
    if (abs($signed) <= 0.009) {
        continue;
    }

    $row['balance'] = $signed;
    if ($type === 'ASSET') {
        $assets[] = $row;
        $assetsTotal = ledger_round($assetsTotal + $signed);
    } elseif ($type === 'LIABILITY') {
        $liabilities[] = $row;
        $liabilitiesTotal = ledger_round($liabilitiesTotal + $signed);
    } elseif ($type === 'EQUITY') {
        $equity[] = $row;
        $equityTotal = ledger_round($equityTotal + $signed);
    } elseif ($type === 'REVENUE') {
        $netIncomeToDate = ledger_round($netIncomeToDate + ($signed));
    } elseif ($type === 'EXPENSE') {
        $netIncomeToDate = ledger_round($netIncomeToDate - ($signed));
    }
}

$equityTotalWithPnl = ledger_round($equityTotal + $netIncomeToDate);
$difference = ledger_round($assetsTotal - ($liabilitiesTotal + $equityTotalWithPnl));

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
  <div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <div>
        <h1 class="h4 mb-1">Balance Sheet (Ledger)</h1>
        <p class="text-muted mb-0">As of <?= e($toDate) ?></p>
      </div>
      <div class="text-end">
        <div><strong><?= e((string) ($scope['scope_garage_label'] ?? '-')) ?></strong></div>
        <small class="text-muted">Ledger driven</small>
      </div>
    </div>

    <?php reports_render_page_navigation($active_menu, (array) ($scope['base_params'] ?? [])); ?>

    <form method="get" class="card card-body mb-3">
      <div class="row g-2">
        <div class="col-md-3">
          <label class="form-label">Garage</label>
          <select name="garage_id" class="form-select">
            <?php if ((bool) ($scope['allow_all_garages'] ?? false)): ?><option value="0" <?= ((int) ($scope['selected_garage_id'] ?? 0) === 0) ? 'selected' : '' ?>>All Accessible Garages</option><?php endif; ?>
            <?php foreach ((array) ($scope['garage_options'] ?? []) as $garage): ?>
              <option value="<?= (int) ($garage['id'] ?? 0) ?>" <?= ((int) ($scope['selected_garage_id'] ?? 0) === (int) ($garage['id'] ?? 0)) ? 'selected' : '' ?>><?= e((string) ($garage['name'] ?? '')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2"><label class="form-label">FY</label><select name="fy_id" class="form-select"><?php foreach ((array) ($scope['financial_years'] ?? []) as $fy): ?><option value="<?= (int) ($fy['id'] ?? 0) ?>" <?= ((int) ($scope['selected_fy_id'] ?? 0) === (int) ($fy['id'] ?? 0)) ? 'selected' : '' ?>><?= e((string) ($fy['fy_label'] ?? '')) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= e((string) ($scope['from_date'] ?? '')) ?>"></div>
        <div class="col-md-2"><label class="form-label">To (As Of)</label><input type="date" name="to" class="form-control" value="<?= e($toDate) ?>"></div>
        <div class="col-md-2"><label class="form-label">Date Mode</label><select name="date_mode" class="form-select"><?php foreach ((array) ($scope['date_mode_options'] ?? []) as $modeKey => $modeLabel): ?><option value="<?= e((string) $modeKey) ?>" <?= ((string) ($scope['date_mode'] ?? '') === (string) $modeKey) ? 'selected' : '' ?>><?= e((string) $modeLabel) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-1 d-grid"><label class="form-label">&nbsp;</label><button type="submit" class="btn btn-primary">Apply</button></div>
      </div>
    </form>

    <div class="row g-3 mb-3">
      <div class="col-md-3"><div class="card card-body"><small class="text-muted">Assets</small><div class="h5 mb-0"><?= e(format_currency($assetsTotal)) ?></div></div></div>
      <div class="col-md-3"><div class="card card-body"><small class="text-muted">Liabilities</small><div class="h5 mb-0"><?= e(format_currency($liabilitiesTotal)) ?></div></div></div>
      <div class="col-md-3"><div class="card card-body"><small class="text-muted">Equity + Current Earnings</small><div class="h5 mb-0"><?= e(format_currency($equityTotalWithPnl)) ?></div></div></div>
      <div class="col-md-3"><div class="card card-body"><small class="text-muted">Balance Difference</small><div class="h5 mb-0 <?= abs($difference) <= 0.009 ? 'text-success' : 'text-danger' ?>"><?= e(format_currency($difference)) ?></div></div></div>
    </div>

    <div class="row g-3">
      <div class="col-lg-4">
        <div class="card">
          <div class="card-header">Assets</div>
          <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
              <thead><tr><th>Code</th><th>Account</th><th class="text-end">Balance</th></tr></thead>
              <tbody>
              <?php if ($assets === []): ?><tr><td colspan="3" class="text-center text-muted py-3">No balances</td></tr>
              <?php else: foreach ($assets as $row): ?>
                <tr><td><?= e((string) ($row['code'] ?? '')) ?></td><td><?= e((string) ($row['name'] ?? '')) ?></td><td class="text-end"><?= e(number_format((float) ($row['balance'] ?? 0), 2)) ?></td></tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card mb-3">
          <div class="card-header">Liabilities</div>
          <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
              <thead><tr><th>Code</th><th>Account</th><th class="text-end">Balance</th></tr></thead>
              <tbody>
              <?php if ($liabilities === []): ?><tr><td colspan="3" class="text-center text-muted py-3">No balances</td></tr>
              <?php else: foreach ($liabilities as $row): ?>
                <tr><td><?= e((string) ($row['code'] ?? '')) ?></td><td><?= e((string) ($row['name'] ?? '')) ?></td><td class="text-end"><?= e(number_format((float) ($row['balance'] ?? 0), 2)) ?></td></tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="card">
          <div class="card-header">Current Earnings (Derived from Ledger)</div>
          <div class="card-body">
            <div class="d-flex justify-content-between">
              <span>Net Income To Date</span>
              <strong class="<?= $netIncomeToDate >= 0 ? 'text-success' : 'text-danger' ?>"><?= e(format_currency($netIncomeToDate)) ?></strong>
            </div>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card">
          <div class="card-header">Equity</div>
          <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
              <thead><tr><th>Code</th><th>Account</th><th class="text-end">Balance</th></tr></thead>
              <tbody>
              <?php if ($equity === []): ?><tr><td colspan="3" class="text-center text-muted py-3">No balances</td></tr>
              <?php else: foreach ($equity as $row): ?>
                <tr><td><?= e((string) ($row['code'] ?? '')) ?></td><td><?= e((string) ($row['name'] ?? '')) ?></td><td class="text-end"><?= e(number_format((float) ($row['balance'] ?? 0), 2)) ?></td></tr>
              <?php endforeach; endif; ?>
              <tr class="table-light">
                <td>PL</td>
                <td>Current Earnings (Derived)</td>
                <td class="text-end"><?= e(number_format($netIncomeToDate, 2)) ?></td>
              </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
