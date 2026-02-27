<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/ledger_common.php';

ledger_reports_require_financial_access();

$page_title = 'Customer Ledger (GL)';
$active_menu = 'reports.ledger_customer_ledger';

$scope = reports_build_scope_context();
$companyId = (int) ($scope['company_id'] ?? 0);
$customerId = get_int('customer_id', 0);

$customerParams = ['company_id' => $companyId];
$customerScopeSql = ledger_reports_scope_sql('le.garage_id', $scope, $customerParams, 'cust_led_opts');
$customerOptionsStmt = db()->prepare(
    'SELECT le.party_id AS customer_id,
            COALESCE(c.full_name, CONCAT("Customer #", le.party_id)) AS customer_name
     FROM ledger_entries le
     INNER JOIN ledger_journals lj ON lj.id = le.journal_id
     INNER JOIN chart_of_accounts coa ON coa.id = le.account_id
     LEFT JOIN customers c ON c.id = le.party_id
     WHERE lj.company_id = :company_id
       AND le.party_type = "CUSTOMER"
       AND coa.code IN ("1200", "2300")
       ' . $customerScopeSql . '
     GROUP BY le.party_id, c.full_name
     ORDER BY customer_name ASC'
);
$customerOptionsStmt->execute($customerParams);
$customerOptions = $customerOptionsStmt->fetchAll();
if ($customerId <= 0 && $customerOptions !== []) {
    $customerId = (int) ($customerOptions[0]['customer_id'] ?? 0);
}

$entries = [];
$openingBalance = 0.0;
$closingBalance = 0.0;

if ($customerId > 0) {
    $fromDate = (string) ($scope['from_date'] ?? date('Y-m-01'));
    $toDate = (string) ($scope['to_date'] ?? date('Y-m-d'));

    $openParams = [
        'company_id' => $companyId,
        'customer_id' => $customerId,
        'from_date' => $fromDate,
    ];
    $openScopeSql = ledger_reports_scope_sql('le.garage_id', $scope, $openParams, 'cust_led_open');
    $openStmt = db()->prepare(
        'SELECT COALESCE(SUM(le.debit_amount), 0) AS debit_total,
                COALESCE(SUM(le.credit_amount), 0) AS credit_total
         FROM ledger_entries le
         INNER JOIN ledger_journals lj ON lj.id = le.journal_id
         INNER JOIN chart_of_accounts coa ON coa.id = le.account_id
         WHERE lj.company_id = :company_id
           AND le.party_type = "CUSTOMER"
           AND le.party_id = :customer_id
           AND coa.code IN ("1200", "2300")
           AND lj.journal_date < :from_date
           ' . $openScopeSql
    );
    $openStmt->execute($openParams);
    $openRow = $openStmt->fetch() ?: ['debit_total' => 0, 'credit_total' => 0];
    $openingBalance = ledger_round((float) $openRow['debit_total'] - (float) $openRow['credit_total']);
    $closingBalance = $openingBalance;

    $entryParams = [
        'company_id' => $companyId,
        'customer_id' => $customerId,
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ];
    $entryScopeSql = ledger_reports_scope_sql('le.garage_id', $scope, $entryParams, 'cust_led_rows');
    $entryStmt = db()->prepare(
        'SELECT le.*, lj.journal_date, lj.reference_type, lj.reference_id, lj.narration,
                coa.code AS account_code, coa.name AS account_name
         FROM ledger_entries le
         INNER JOIN ledger_journals lj ON lj.id = le.journal_id
         INNER JOIN chart_of_accounts coa ON coa.id = le.account_id
         WHERE lj.company_id = :company_id
           AND le.party_type = "CUSTOMER"
           AND le.party_id = :customer_id
           AND coa.code IN ("1200", "2300")
           AND lj.journal_date BETWEEN :from_date AND :to_date
           ' . $entryScopeSql . '
         ORDER BY lj.journal_date ASC, le.journal_id ASC, le.id ASC'
    );
    $entryStmt->execute($entryParams);
    foreach ($entryStmt->fetchAll() as $entry) {
        $delta = ledger_round((float) ($entry['debit_amount'] ?? 0) - (float) ($entry['credit_amount'] ?? 0));
        $closingBalance = ledger_round($closingBalance + $delta);
        $entry['delta'] = $delta;
        $entry['running_balance'] = $closingBalance;
        $entries[] = $entry;
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
  <div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <div>
        <h1 class="h4 mb-1">Customer Ledger (GL)</h1>
        <p class="text-muted mb-0">Control accounts: AR (1200) and Customer Advance Liability (2300)</p>
      </div>
      <div class="text-end"><strong><?= e((string) ($scope['scope_garage_label'] ?? '-')) ?></strong></div>
    </div>

    <form method="get" class="card card-body mb-3">
      <div class="row g-2">
        <div class="col-md-3"><label class="form-label">Garage</label><select name="garage_id" class="form-select"><?php if ((bool) ($scope['allow_all_garages'] ?? false)): ?><option value="0" <?= ((int) ($scope['selected_garage_id'] ?? 0) === 0) ? 'selected' : '' ?>>All Accessible Garages</option><?php endif; ?><?php foreach ((array) ($scope['garage_options'] ?? []) as $garage): ?><option value="<?= (int) ($garage['id'] ?? 0) ?>" <?= ((int) ($scope['selected_garage_id'] ?? 0) === (int) ($garage['id'] ?? 0)) ? 'selected' : '' ?>><?= e((string) ($garage['name'] ?? '')) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">FY</label><select name="fy_id" class="form-select"><?php foreach ((array) ($scope['financial_years'] ?? []) as $fy): ?><option value="<?= (int) ($fy['id'] ?? 0) ?>" <?= ((int) ($scope['selected_fy_id'] ?? 0) === (int) ($fy['id'] ?? 0)) ? 'selected' : '' ?>><?= e((string) ($fy['fy_label'] ?? '')) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= e((string) ($scope['from_date'] ?? '')) ?>"></div>
        <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= e((string) ($scope['to_date'] ?? '')) ?>"></div>
        <div class="col-md-2"><label class="form-label">Customer</label><select name="customer_id" class="form-select"><?php foreach ($customerOptions as $opt): ?><option value="<?= (int) ($opt['customer_id'] ?? 0) ?>" <?= ($customerId === (int) ($opt['customer_id'] ?? 0)) ? 'selected' : '' ?>><?= e((string) ($opt['customer_name'] ?? '')) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-1 d-grid"><label class="form-label">&nbsp;</label><button class="btn btn-primary">Apply</button></div>
      </div>
    </form>

    <?php if ($customerOptions === []): ?>
      <div class="alert alert-info">No customer ledger entries available yet.</div>
    <?php else: ?>
      <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="card card-body"><small class="text-muted">Opening Net Receivable (+) / Advance (-)</small><div class="h5 mb-0"><?= e(format_currency($openingBalance)) ?></div></div></div>
        <div class="col-md-4"><div class="card card-body"><small class="text-muted">Closing Net Receivable (+) / Advance (-)</small><div class="h5 mb-0"><?= e(format_currency($closingBalance)) ?></div></div></div>
        <div class="col-md-4"><div class="card card-body"><small class="text-muted">Rows</small><div class="h5 mb-0"><?= e(number_format(count($entries))) ?></div></div></div>
      </div>

      <div class="card">
        <div class="table-responsive">
          <table class="table table-sm table-striped align-middle mb-0">
            <thead><tr><th>Date</th><th>Journal</th><th>Reference</th><th>Account</th><th>Narration</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Net Delta</th><th class="text-end">Running</th></tr></thead>
            <tbody>
              <tr class="table-light"><td colspan="8"><strong>Opening Balance</strong></td><td class="text-end"><strong><?= e(number_format($openingBalance, 2)) ?></strong></td></tr>
              <?php if ($entries === []): ?>
                <tr><td colspan="9" class="text-center text-muted py-3">No entries in selected range.</td></tr>
              <?php else: foreach ($entries as $entry): ?>
                <tr>
                  <td><?= e((string) ($entry['journal_date'] ?? '')) ?></td>
                  <td>#<?= (int) ($entry['journal_id'] ?? 0) ?></td>
                  <td><?= e((string) ($entry['reference_type'] ?? '')) ?> / <?= (int) ($entry['reference_id'] ?? 0) ?></td>
                  <td><?= e((string) ($entry['account_code'] ?? '')) ?> - <?= e((string) ($entry['account_name'] ?? '')) ?></td>
                  <td><?= e((string) ($entry['narration'] ?? '')) ?></td>
                  <td class="text-end"><?= e(number_format((float) ($entry['debit_amount'] ?? 0), 2)) ?></td>
                  <td class="text-end"><?= e(number_format((float) ($entry['credit_amount'] ?? 0), 2)) ?></td>
                  <td class="text-end"><?= e(number_format((float) ($entry['delta'] ?? 0), 2)) ?></td>
                  <td class="text-end"><?= e(number_format((float) ($entry['running_balance'] ?? 0), 2)) ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

