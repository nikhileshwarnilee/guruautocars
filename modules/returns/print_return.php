<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/workflow.php';

if (
    !has_permission('inventory.view')
    && !has_permission('billing.view')
    && !has_permission('purchase.view')
    && !has_permission('report.view')
) {
    flash_set('access_denied', 'You do not have permission to print return documents.', 'danger');
    redirect('dashboard.php');
}

$companyId = active_company_id();
$garageId = active_garage_id();
$returnId = get_int('id');

if ($returnId <= 0 || !returns_module_ready()) {
    flash_set('return_error', 'Invalid return id.', 'danger');
    redirect('modules/returns/index.php');
}

$returnStmt = db()->prepare(
    'SELECT r.*,
            c.name AS company_name, c.gstin AS company_gstin, c.address_line1 AS company_address, c.city AS company_city, c.state AS company_state,
            g.name AS garage_name,
            i.invoice_number,
            p.invoice_number AS purchase_invoice_number,
            cu.full_name AS customer_name, cu.phone AS customer_phone,
            v.vendor_name
     FROM returns_rma r
     LEFT JOIN companies c ON c.id = r.company_id
     LEFT JOIN garages g ON g.id = r.garage_id
     LEFT JOIN invoices i ON i.id = r.invoice_id
     LEFT JOIN purchases p ON p.id = r.purchase_id
     LEFT JOIN customers cu ON cu.id = r.customer_id
     LEFT JOIN vendors v ON v.id = r.vendor_id
     WHERE r.id = :id
       AND r.company_id = :company_id
       AND r.garage_id = :garage_id
       AND r.status_code = "ACTIVE"
     LIMIT 1'
);
$returnStmt->execute([
    'id' => $returnId,
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$returnRow = $returnStmt->fetch();

if (!$returnRow) {
    flash_set('return_error', 'Return not found.', 'danger');
    redirect('modules/returns/index.php');
}

$itemsStmt = db()->prepare(
    'SELECT ri.*, p.part_name, p.part_sku, p.unit AS part_unit
     FROM return_items ri
     LEFT JOIN parts p ON p.id = ri.part_id
     WHERE ri.return_id = :return_id
     ORDER BY ri.id ASC'
);
$itemsStmt->execute(['return_id' => $returnId]);
$items = $itemsStmt->fetchAll();

$logoUrl = company_logo_url((int) ($returnRow['company_id'] ?? $companyId), $garageId);
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Return <?= e((string) ($returnRow['return_number'] ?? '')); ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/adminlte.min.css')); ?>" />
    <style>
      body { font-family: "Segoe UI", Arial, sans-serif; color: #1f2937; margin: 0; padding: 16px; }
      .head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
      .title { font-size: 22px; font-weight: 700; margin: 0 0 6px; }
      .muted { color: #64748b; }
      table { width: 100%; border-collapse: collapse; margin-top: 10px; }
      th, td { border: 1px solid #dbe3ef; padding: 8px; font-size: 13px; }
      th { background: #f1f5f9; text-align: left; }
      .text-end { text-align: right; }
      .meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin: 10px 0; }
      .chip { display: inline-block; border: 1px solid #cbd5e1; border-radius: 999px; padding: 2px 10px; font-size: 12px; }
      @media print { .no-print { display: none; } body { padding: 0; } }
    </style>
  </head>
  <body>
    <div class="no-print mb-3">
      <button onclick="window.print()" class="btn btn-primary btn-sm">Print</button>
      <a href="<?= e(url('modules/returns/index.php?view_id=' . (int) ($returnRow['id'] ?? 0))); ?>" class="btn btn-outline-secondary btn-sm">Back</a>
    </div>

    <div class="head">
      <div>
        <h1 class="title">Return / RMA Receipt</h1>
        <div><strong><?= e((string) ($returnRow['company_name'] ?? '')); ?></strong></div>
        <div class="muted"><?= e((string) ($returnRow['garage_name'] ?? '')); ?></div>
        <div class="muted"><?= e((string) ($returnRow['company_address'] ?? '')); ?> <?= e((string) ($returnRow['company_city'] ?? '')); ?> <?= e((string) ($returnRow['company_state'] ?? '')); ?></div>
        <?php if (!empty($returnRow['company_gstin'])): ?>
          <div class="muted">GSTIN: <?= e((string) ($returnRow['company_gstin'] ?? '')); ?></div>
        <?php endif; ?>
      </div>
      <div class="text-end">
        <?php if ($logoUrl !== null): ?>
          <img src="<?= e($logoUrl); ?>" alt="Logo" style="height:52px; width:auto; margin-bottom:8px;" />
        <?php endif; ?>
        <div><strong>Return No:</strong> <?= e((string) ($returnRow['return_number'] ?? '')); ?></div>
        <div><strong>Date:</strong> <?= e((string) ($returnRow['return_date'] ?? '')); ?></div>
        <div><span class="chip"><?= e((string) ($returnRow['approval_status'] ?? '')); ?></span></div>
      </div>
    </div>

    <div class="meta">
      <div>
        <strong>Type:</strong> <?= e(str_replace('_', ' ', (string) ($returnRow['return_type'] ?? ''))); ?><br />
        <strong>Source:</strong>
        <?php if (!empty($returnRow['invoice_number'])): ?>
          Invoice <?= e((string) ($returnRow['invoice_number'] ?? '')); ?>
        <?php elseif (!empty($returnRow['purchase_invoice_number'])): ?>
          Purchase <?= e((string) ($returnRow['purchase_invoice_number'] ?? '')); ?>
        <?php else: ?>
          -
        <?php endif; ?><br />
        <strong>Reason:</strong> <?= e((string) (($returnRow['reason_text'] ?? '') !== '' ? $returnRow['reason_text'] : '-')); ?>
      </div>
      <div>
        <?php if ((string) ($returnRow['return_type'] ?? '') === 'CUSTOMER_RETURN'): ?>
          <strong>Customer:</strong> <?= e((string) (($returnRow['customer_name'] ?? '') !== '' ? $returnRow['customer_name'] : '-')); ?><br />
          <strong>Phone:</strong> <?= e((string) (($returnRow['customer_phone'] ?? '') !== '' ? $returnRow['customer_phone'] : '-')); ?><br />
        <?php else: ?>
          <strong>Vendor:</strong> <?= e((string) (($returnRow['vendor_name'] ?? '') !== '' ? $returnRow['vendor_name'] : '-')); ?><br />
        <?php endif; ?>
        <strong>Notes:</strong> <?= e((string) (($returnRow['notes'] ?? '') !== '' ? $returnRow['notes'] : '-')); ?>
      </div>
    </div>

    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Description</th>
          <th class="text-end">Qty</th>
          <th class="text-end">Rate</th>
          <th class="text-end">GST %</th>
          <th class="text-end">Taxable</th>
          <th class="text-end">Tax</th>
          <th class="text-end">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($items === []): ?>
          <tr><td colspan="8" class="text-center muted">No line items found.</td></tr>
        <?php else: ?>
          <?php foreach ($items as $index => $line): ?>
            <tr>
              <td><?= (int) $index + 1; ?></td>
              <td>
                <?= e((string) ($line['description'] ?? '')); ?>
                <?php if (!empty($line['part_sku'])): ?>
                  <div class="muted"><?= e((string) ($line['part_sku'] ?? '')); ?></div>
                <?php endif; ?>
              </td>
              <td class="text-end"><?= e(number_format((float) ($line['quantity'] ?? 0), 2)); ?> <?= e((string) ($line['part_unit'] ?? '')); ?></td>
              <td class="text-end"><?= e(number_format((float) ($line['unit_price'] ?? 0), 2)); ?></td>
              <td class="text-end"><?= e(number_format((float) ($line['gst_rate'] ?? 0), 2)); ?></td>
              <td class="text-end"><?= e(number_format((float) ($line['taxable_amount'] ?? 0), 2)); ?></td>
              <td class="text-end"><?= e(number_format((float) ($line['tax_amount'] ?? 0), 2)); ?></td>
              <td class="text-end"><?= e(number_format((float) ($line['total_amount'] ?? 0), 2)); ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <th colspan="5" class="text-end">Totals</th>
          <th class="text-end"><?= e(number_format((float) ($returnRow['taxable_amount'] ?? 0), 2)); ?></th>
          <th class="text-end"><?= e(number_format((float) ($returnRow['tax_amount'] ?? 0), 2)); ?></th>
          <th class="text-end"><?= e(number_format((float) ($returnRow['total_amount'] ?? 0), 2)); ?></th>
        </tr>
      </tfoot>
    </table>
  </body>
</html>
