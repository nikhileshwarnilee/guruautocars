<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/workflow.php';

if (!billing_can_view()) {
    flash_set('access_denied', 'You do not have permission to view advance receipts.', 'danger');
    redirect('dashboard.php');
}

$companyId = active_company_id();
$garageId = active_garage_id();
$advanceId = get_int('id');
if ($advanceId <= 0) {
    flash_set('billing_error', 'Invalid advance receipt id.', 'danger');
    redirect('modules/billing/index.php');
}

$pdo = db();
$row = billing_fetch_advance_receipt_row($pdo, $advanceId, $companyId, $garageId);
if (!$row) {
    flash_set('billing_error', 'Advance receipt entry not found.', 'danger');
    redirect('modules/billing/index.php');
}

$metaStmt = $pdo->prepare(
    'SELECT c.name AS company_name, c.gstin AS company_gstin, g.name AS garage_name, u.name AS collector_name
     FROM job_advances ja
     INNER JOIN companies c ON c.id = ja.company_id
     INNER JOIN garages g ON g.id = ja.garage_id
     LEFT JOIN users u ON u.id = ja.created_by
     WHERE ja.id = :advance_id
     LIMIT 1'
);
$metaStmt->execute(['advance_id' => $advanceId]);
$meta = $metaStmt->fetch() ?: [];

$companyName = (string) ($meta['company_name'] ?? APP_SHORT_NAME);
$companyGstin = (string) ($meta['company_gstin'] ?? '');
$garageName = (string) ($meta['garage_name'] ?? '-');
$receiptNumber = (string) ($row['receipt_number'] ?? ('ADV-' . str_pad((string) $advanceId, 6, '0', STR_PAD_LEFT)));
$companyLogo = billing_invoice_logo_url($companyId, $garageId);
if ($companyLogo === null) {
    $companyLogo = company_logo_url($companyId, $garageId);
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Advance Receipt <?= e($receiptNumber); ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/adminlte.min.css')); ?>" />
    <style>
      body { background: #f1f5f9; padding: 20px; font-family: "Segoe UI", Arial, sans-serif; color: #0f172a; }
      .sheet { max-width: 820px; margin: 0 auto; background: #fff; border: 1px solid #dbe4ef; border-radius: 8px; padding: 22px; }
      .head { display: flex; justify-content: space-between; gap: 16px; border-bottom: 1px solid #dbe4ef; padding-bottom: 12px; margin-bottom: 16px; }
      .logo { max-height: 54px; max-width: 190px; display: block; margin-bottom: 8px; }
      .title { font-size: 1.25rem; font-weight: 700; margin-bottom: 6px; }
      table { width: 100%; border-collapse: collapse; }
      th, td { border: 1px solid #dbe4ef; padding: 8px 10px; font-size: 13px; }
      th { background: #f8fafc; width: 32%; text-align: left; }
      .text-end { text-align: right; }
      .actions { max-width: 820px; margin: 0 auto 10px auto; display: flex; justify-content: space-between; }
      @media print { .no-print { display: none !important; } body { background: #fff; padding: 0; } .sheet { border: 0; border-radius: 0; box-shadow: none; max-width: none; } }
    </style>
  </head>
  <body>
    <div class="actions no-print">
      <a href="<?= e(url('modules/billing/index.php')); ?>" class="btn btn-outline-secondary btn-sm">Back</a>
      <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">Print / Reprint</button>
    </div>

    <div class="sheet">
      <div class="head">
        <div>
          <?php if ($companyLogo !== null): ?>
            <img class="logo" src="<?= e($companyLogo); ?>" alt="Company Logo" />
          <?php endif; ?>
          <div class="title"><?= e($companyName); ?></div>
          <div><?= e($garageName); ?></div>
          <?php if ($companyGstin !== ''): ?><div>GSTIN: <?= e($companyGstin); ?></div><?php endif; ?>
        </div>
        <div>
          <div class="title">Advance Receipt</div>
          <div><strong>Receipt No:</strong> <?= e($receiptNumber); ?></div>
          <div><strong>Date:</strong> <?= e((string) ($row['received_on'] ?? '-')); ?></div>
          <div><strong>Job Card:</strong> <?= e((string) ($row['job_number'] ?? '-')); ?></div>
        </div>
      </div>

      <table>
        <tbody>
          <tr><th>Customer</th><td><?= e((string) ($row['customer_name'] ?? '-')); ?></td></tr>
          <tr><th>Vehicle</th><td><?= e((string) ($row['registration_no'] ?? '-')); ?></td></tr>
          <tr><th>Payment Mode</th><td><?= e((string) ($row['payment_mode'] ?? '-')); ?></td></tr>
          <tr><th>Advance Amount</th><td class="text-end"><?= e(number_format((float) ($row['advance_amount'] ?? 0), 2)); ?></td></tr>
          <tr><th>Adjusted Amount</th><td class="text-end"><?= e(number_format((float) ($row['adjusted_amount'] ?? 0), 2)); ?></td></tr>
          <tr><th>Balance Amount</th><td class="text-end"><?= e(number_format((float) ($row['balance_amount'] ?? 0), 2)); ?></td></tr>
          <tr><th>Reference</th><td><?= e((string) (($row['reference_no'] ?? '') !== '' ? $row['reference_no'] : '-')); ?></td></tr>
          <tr><th>Collected By</th><td><?= e((string) (($meta['collector_name'] ?? '') !== '' ? $meta['collector_name'] : '-')); ?></td></tr>
          <tr><th>Notes</th><td><?= e((string) (($row['notes'] ?? '') !== '' ? $row['notes'] : '-')); ?></td></tr>
        </tbody>
      </table>
    </div>
  </body>
</html>
