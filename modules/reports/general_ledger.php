<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/ledger_common.php';

ledger_reports_require_financial_access();

$page_title = 'General Ledger Statement';
$active_menu = 'reports.ledger_general_ledger';

$scope = reports_build_scope_context();
$companyId = (int) ($scope['company_id'] ?? 0);
$accounts = ledger_reports_fetch_accounts($companyId);
$accountMap = ledger_reports_account_map_by_id($accounts);
$selectedAccountId = get_int('account_id', 0);
if ($selectedAccountId <= 0 && $accounts !== []) {
    $selectedAccountId = (int) ($accounts[0]['id'] ?? 0);
}
$selectedAccount = $accountMap[$selectedAccountId] ?? null;

$openingBalance = 0.0;
$entries = [];
$runningBalance = 0.0;

if ($selectedAccount) {
    $fromDate = (string) ($scope['from_date'] ?? date('Y-m-01'));
    $toDate = (string) ($scope['to_date'] ?? date('Y-m-d'));
    $sign = ledger_reports_normal_balance_sign((string) ($selectedAccount['type'] ?? 'ASSET'));

    $openingParams = [
        'company_id' => $companyId,
        'account_id' => $selectedAccountId,
        'from_date' => $fromDate,
    ];
    $openingScopeSql = ledger_reports_scope_sql('le.garage_id', $scope, $openingParams, 'ledger_gl_open');
    $openingStmt = db()->prepare(
        'SELECT COALESCE(SUM(le.debit_amount), 0) AS debit_total,
                COALESCE(SUM(le.credit_amount), 0) AS credit_total
         FROM ledger_entries le
         INNER JOIN ledger_journals lj ON lj.id = le.journal_id
         WHERE lj.company_id = :company_id
           AND le.account_id = :account_id
           AND lj.journal_date < :from_date
           ' . $openingScopeSql
    );
    $openingStmt->execute($openingParams);
    $opening = $openingStmt->fetch() ?: ['debit_total' => 0, 'credit_total' => 0];
    $openingBalance = ledger_round($sign * (((float) $opening['debit_total']) - ((float) $opening['credit_total'])));
    $runningBalance = $openingBalance;

    $entryParams = [
        'company_id' => $companyId,
        'account_id' => $selectedAccountId,
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ];
    $entryScopeSql = ledger_reports_scope_sql('le.garage_id', $scope, $entryParams, 'ledger_gl_rows');
    $entryStmt = db()->prepare(
        'SELECT le.*, lj.reference_type, lj.reference_id, lj.journal_date, lj.narration, lj.created_at AS journal_created_at,
                coa.code AS account_code, coa.name AS account_name
         FROM ledger_entries le
         INNER JOIN ledger_journals lj ON lj.id = le.journal_id
         INNER JOIN chart_of_accounts coa ON coa.id = le.account_id
         WHERE lj.company_id = :company_id
           AND le.account_id = :account_id
           AND lj.journal_date BETWEEN :from_date AND :to_date
           ' . $entryScopeSql . '
         ORDER BY lj.journal_date ASC, le.journal_id ASC, le.id ASC'
    );
    $entryStmt->execute($entryParams);
    foreach ($entryStmt->fetchAll() as $entry) {
        $delta = ledger_round($sign * (((float) ($entry['debit_amount'] ?? 0)) - ((float) ($entry['credit_amount'] ?? 0))));
        $runningBalance = ledger_round($runningBalance + $delta);
        $entry['delta_signed'] = $delta;
        $entry['running_balance'] = $runningBalance;
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
        <h1 class="h4 mb-1">General Ledger Statement</h1>
        <p class="text-muted mb-0"><?= e((string) ($scope['from_date'] ?? '')) ?> to <?= e((string) ($scope['to_date'] ?? '')) ?></p>
      </div>
      <div class="text-end"><strong><?= e((string) ($scope['scope_garage_label'] ?? '-')) ?></strong></div>
    </div>

    <form method="get" class="card card-body mb-3">
      <div class="row g-2">
        <div class="col-md-3"><label class="form-label">Garage</label><select name="garage_id" class="form-select"><?php if ((bool) ($scope['allow_all_garages'] ?? false)): ?><option value="0" <?= ((int) ($scope['selected_garage_id'] ?? 0) === 0) ? 'selected' : '' ?>>All Accessible Garages</option><?php endif; ?><?php foreach ((array) ($scope['garage_options'] ?? []) as $garage): ?><option value="<?= (int) ($garage['id'] ?? 0) ?>" <?= ((int) ($scope['selected_garage_id'] ?? 0) === (int) ($garage['id'] ?? 0)) ? 'selected' : '' ?>><?= e((string) ($garage['name'] ?? '')) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">FY</label><select name="fy_id" class="form-select"><?php foreach ((array) ($scope['financial_years'] ?? []) as $fy): ?><option value="<?= (int) ($fy['id'] ?? 0) ?>" <?= ((int) ($scope['selected_fy_id'] ?? 0) === (int) ($fy['id'] ?? 0)) ? 'selected' : '' ?>><?= e((string) ($fy['fy_label'] ?? '')) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= e((string) ($scope['from_date'] ?? '')) ?>"></div>
        <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= e((string) ($scope['to_date'] ?? '')) ?>"></div>
        <div class="col-md-2"><label class="form-label">Account</label>
          <select name="account_id" class="form-select">
            <?php foreach ($accounts as $account): ?>
              <option value="<?= (int) ($account['id'] ?? 0) ?>" <?= ($selectedAccountId === (int) ($account['id'] ?? 0)) ? 'selected' : '' ?>>
                <?= e((string) ($account['code'] ?? '')) ?> - <?= e((string) ($account['name'] ?? '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-1 d-grid"><label class="form-label">&nbsp;</label><button class="btn btn-primary">Apply</button></div>
      </div>
    </form>

    <?php if (!$selectedAccount): ?>
      <div class="alert alert-warning">No accounts found in chart of accounts for this company.</div>
    <?php else: ?>
      <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="card card-body"><small class="text-muted">Account</small><div class="fw-semibold"><?= e((string) ($selectedAccount['code'] ?? '')) ?> - <?= e((string) ($selectedAccount['name'] ?? '')) ?></div></div></div>
        <div class="col-md-4"><div class="card card-body"><small class="text-muted">Opening Balance</small><div class="h5 mb-0"><?= e(format_currency($openingBalance)) ?></div></div></div>
        <div class="col-md-4"><div class="card card-body"><small class="text-muted">Closing Balance</small><div class="h5 mb-0"><?= e(format_currency($runningBalance)) ?></div></div></div>
      </div>

      <div class="card">
        <div class="table-responsive">
          <table class="table table-sm table-striped align-middle mb-0">
            <thead>
              <tr>
                <th>Date</th>
                <th>Journal</th>
                <th>Reference</th>
                <th>Narration</th>
                <th class="text-end">Debit</th>
                <th class="text-end">Credit</th>
                <th class="text-end">Delta</th>
                <th class="text-end">Running</th>
              </tr>
            </thead>
            <tbody>
              <tr class="table-light">
                <td colspan="7"><strong>Opening Balance</strong></td>
                <td class="text-end"><strong><?= e(number_format($openingBalance, 2)) ?></strong></td>
              </tr>
              <?php if ($entries === []): ?>
                <tr><td colspan="8" class="text-center text-muted py-3">No entries in selected range.</td></tr>
              <?php else: foreach ($entries as $entry): ?>
                <tr>
                  <td><?= e((string) ($entry['journal_date'] ?? '')) ?></td>
                  <td>#<?= (int) ($entry['journal_id'] ?? 0) ?></td>
                  <td><?= e((string) ($entry['reference_type'] ?? '')) ?> / <?= (int) ($entry['reference_id'] ?? 0) ?></td>
                  <td><?= e((string) ($entry['narration'] ?? '')) ?></td>
                  <td class="text-end"><?= e(number_format((float) ($entry['debit_amount'] ?? 0), 2)) ?></td>
                  <td class="text-end"><?= e(number_format((float) ($entry['credit_amount'] ?? 0), 2)) ?></td>
                  <td class="text-end"><?= e(number_format((float) ($entry['delta_signed'] ?? 0), 2)) ?></td>
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

