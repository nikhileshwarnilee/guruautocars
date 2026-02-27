<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/ledger_common.php';

ledger_reports_require_financial_access();

$page_title = 'Cash Flow (Indirect, Ledger)';
$active_menu = 'reports.ledger_cash_flow';

$scope = reports_build_scope_context();
$fromDate = (string) ($scope['from_date'] ?? date('Y-m-01'));
$toDate = (string) ($scope['to_date'] ?? date('Y-m-d'));
$openingAsOf = date('Y-m-d', strtotime($fromDate . ' -1 day'));

$closingRows = ledger_reports_closing_balances_upto($scope, $toDate);
$openingRows = ledger_reports_closing_balances_upto($scope, $openingAsOf);
$periodRows = ledger_reports_period_totals($scope);

$balancesByCode = static function (array $rows): array {
    $map = [];
    foreach ($rows as $row) {
        $code = (string) ($row['code'] ?? '');
        if ($code === '') {
            continue;
        }
        $map[$code] = ledger_reports_signed_balance_from_row($row);
    }
    return $map;
};

$opening = $balancesByCode($openingRows);
$closing = $balancesByCode($closingRows);
$delta = static function (string $code) use ($opening, $closing): float {
    return ledger_round((float) ($closing[$code] ?? 0) - (float) ($opening[$code] ?? 0));
};

$periodRevenue = 0.0;
$periodExpense = 0.0;
foreach ($periodRows as $row) {
    $type = strtoupper(trim((string) ($row['type'] ?? '')));
    $debit = (float) ($row['debit_total'] ?? 0);
    $credit = (float) ($row['credit_total'] ?? 0);
    if ($type === 'REVENUE') {
        $periodRevenue = ledger_round($periodRevenue + ($credit - $debit));
    } elseif ($type === 'EXPENSE') {
        $periodExpense = ledger_round($periodExpense + ($debit - $credit));
    }
}
$netIncome = ledger_round($periodRevenue - $periodExpense);

$openingCash = ledger_round((float) ($opening['1100'] ?? 0) + (float) ($opening['1110'] ?? 0));
$closingCash = ledger_round((float) ($closing['1100'] ?? 0) + (float) ($closing['1110'] ?? 0));
$cashDelta = ledger_round($closingCash - $openingCash);

$deltaAr = $delta('1200');
$deltaInventory = $delta('1300');
$deltaAp = $delta('2100');
$deltaInputGst = ledger_round($delta('1210') + $delta('1211') + $delta('1212') + $delta('1215'));
$deltaOutputGst = ledger_round($delta('2200') + $delta('2201') + $delta('2202') + $delta('2205'));
$deltaAdvanceLiab = $delta('2300');

$operatingCash = ledger_round(
    $netIncome
    - $deltaAr
    - $deltaInventory
    - $deltaInputGst
    + $deltaAp
    + $deltaOutputGst
    + $deltaAdvanceLiab
);
$unclassified = ledger_round($cashDelta - $operatingCash);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
  <div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <div>
        <h1 class="h4 mb-1">Cash Flow (Indirect Method, Ledger)</h1>
        <p class="text-muted mb-0"><?= e($fromDate) ?> to <?= e($toDate) ?></p>
      </div>
      <div class="text-end">
        <div><strong><?= e((string) ($scope['scope_garage_label'] ?? '-')) ?></strong></div>
      </div>
    </div>

    <?php reports_render_page_navigation($active_menu, (array) ($scope['base_params'] ?? [])); ?>

    <form method="get" class="card card-body mb-3">
      <div class="row g-2">
        <div class="col-md-3"><label class="form-label">Garage</label><select name="garage_id" class="form-select"><?php if ((bool) ($scope['allow_all_garages'] ?? false)): ?><option value="0" <?= ((int) ($scope['selected_garage_id'] ?? 0) === 0) ? 'selected' : '' ?>>All Accessible Garages</option><?php endif; ?><?php foreach ((array) ($scope['garage_options'] ?? []) as $garage): ?><option value="<?= (int) ($garage['id'] ?? 0) ?>" <?= ((int) ($scope['selected_garage_id'] ?? 0) === (int) ($garage['id'] ?? 0)) ? 'selected' : '' ?>><?= e((string) ($garage['name'] ?? '')) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">FY</label><select name="fy_id" class="form-select"><?php foreach ((array) ($scope['financial_years'] ?? []) as $fy): ?><option value="<?= (int) ($fy['id'] ?? 0) ?>" <?= ((int) ($scope['selected_fy_id'] ?? 0) === (int) ($fy['id'] ?? 0)) ? 'selected' : '' ?>><?= e((string) ($fy['fy_label'] ?? '')) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= e($fromDate) ?>"></div>
        <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= e($toDate) ?>"></div>
        <div class="col-md-2"><label class="form-label">Date Mode</label><select name="date_mode" class="form-select"><?php foreach ((array) ($scope['date_mode_options'] ?? []) as $modeKey => $modeLabel): ?><option value="<?= e((string) $modeKey) ?>" <?= ((string) ($scope['date_mode'] ?? '') === (string) $modeKey) ? 'selected' : '' ?>><?= e((string) $modeLabel) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-1 d-grid"><label class="form-label">&nbsp;</label><button class="btn btn-primary">Apply</button></div>
      </div>
    </form>

    <div class="row g-3 mb-3">
      <div class="col-md-4"><div class="card card-body"><small class="text-muted">Opening Cash + Bank</small><div class="h5 mb-0"><?= e(format_currency($openingCash)) ?></div></div></div>
      <div class="col-md-4"><div class="card card-body"><small class="text-muted">Closing Cash + Bank</small><div class="h5 mb-0"><?= e(format_currency($closingCash)) ?></div></div></div>
      <div class="col-md-4"><div class="card card-body"><small class="text-muted">Net Cash Change</small><div class="h5 mb-0 <?= $cashDelta >= 0 ? 'text-success' : 'text-danger' ?>"><?= e(format_currency($cashDelta)) ?></div></div></div>
    </div>

    <div class="card">
      <div class="card-header">Indirect Method (Operating Reconciliation from Ledger Balances)</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <tbody>
            <tr><td>Net profit / (loss)</td><td class="text-end"><?= e(number_format($netIncome, 2)) ?></td></tr>
            <tr><td>Less: Increase in Accounts Receivable (1200)</td><td class="text-end"><?= e(number_format($deltaAr, 2)) ?></td></tr>
            <tr><td>Less: Increase in Inventory (1300)</td><td class="text-end"><?= e(number_format($deltaInventory, 2)) ?></td></tr>
            <tr><td>Less: Increase in Input GST assets (121x)</td><td class="text-end"><?= e(number_format($deltaInputGst, 2)) ?></td></tr>
            <tr><td>Add: Increase in Accounts Payable (2100)</td><td class="text-end"><?= e(number_format($deltaAp, 2)) ?></td></tr>
            <tr><td>Add: Increase in Output GST liabilities (220x)</td><td class="text-end"><?= e(number_format($deltaOutputGst, 2)) ?></td></tr>
            <tr><td>Add: Increase in Customer Advance Liability (2300)</td><td class="text-end"><?= e(number_format($deltaAdvanceLiab, 2)) ?></td></tr>
            <tr class="table-light"><th>Estimated Net Cash from Operating Activities</th><th class="text-end"><?= e(number_format($operatingCash, 2)) ?></th></tr>
            <tr><td>Actual cash change (Cash + Bank)</td><td class="text-end"><?= e(number_format($cashDelta, 2)) ?></td></tr>
            <tr class="table-warning"><td>Unclassified cash movement (plug)</td><td class="text-end"><?= e(number_format($unclassified, 2)) ?></td></tr>
          </tbody>
        </table>
      </div>
      <div class="card-footer text-muted">
        Based on GL account balance deltas. Unclassified movement indicates entries outside the simplified operating mapping.
      </div>
    </div>
  </div>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
