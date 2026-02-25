<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/workflow.php';

if (!billing_can_view()) {
    flash_set('access_denied', 'You do not have permission to print credit notes.', 'danger');
    redirect('dashboard.php');
}

$companyId = active_company_id();
$garageId = active_garage_id();
$creditNoteId = get_int('id');

if ($creditNoteId <= 0 || !billing_financial_extensions_ready()) {
    flash_set('billing_error', 'Invalid credit note request.', 'danger');
    redirect('modules/billing/credit_notes.php');
}

$noteStmt = db()->prepare(
    'SELECT cn.*,
            c.name AS company_name, c.gstin AS company_gstin, c.address_line1 AS company_address, c.city AS company_city, c.state AS company_state,
            g.name AS garage_name,
            i.invoice_number, i.invoice_date,
            cu.full_name AS customer_name, cu.phone AS customer_phone, cu.gstin AS customer_gstin, cu.address_line1 AS customer_address,
            r.return_number
     FROM credit_notes cn
     LEFT JOIN companies c ON c.id = cn.company_id
     LEFT JOIN garages g ON g.id = cn.garage_id
     LEFT JOIN invoices i ON i.id = cn.invoice_id
     LEFT JOIN customers cu ON cu.id = cn.customer_id
     LEFT JOIN returns_rma r ON r.id = cn.return_id
     WHERE cn.id = :id
       AND cn.company_id = :company_id
       AND cn.garage_id = :garage_id
     LIMIT 1'
);
$noteStmt->execute([
    'id' => $creditNoteId,
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$note = $noteStmt->fetch();

if (!$note) {
    flash_set('billing_error', 'Credit note not found.', 'danger');
    redirect('modules/billing/credit_notes.php');
}

$itemsStmt = db()->prepare(
    'SELECT *
     FROM credit_note_items
     WHERE credit_note_id = :credit_note_id
     ORDER BY id ASC'
);
$itemsStmt->execute(['credit_note_id' => $creditNoteId]);
$items = $itemsStmt->fetchAll();

$logoUrl = billing_invoice_logo_url((int) ($note['company_id'] ?? $companyId), $garageId);
if ($logoUrl === null) {
    $logoUrl = company_logo_url((int) ($note['company_id'] ?? $companyId), $garageId);
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Credit Note <?= e((string) ($note['credit_note_number'] ?? '')); ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/adminlte.min.css')); ?>" />
    <style>
      body { font-family: "Segoe UI", Arial, sans-serif; color: #1f2937; margin: 0; padding: 16px; }
      .head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
      .title { font-size: 22px; font-weight: 700; margin: 0 0 4px; }
      .muted { color: #64748b; }
      table { width: 100%; border-collapse: collapse; margin-top: 10px; }
      th, td { border: 1px solid #dbe3ef; padding: 8px; font-size: 13px; }
      th { background: #f1f5f9; text-align: left; }
      .text-end { text-align: right; }
      .meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin: 10px 0; }
      @media print { .no-print { display: none; } body { padding: 0; } }
    </style>
  </head>
  <body>
    <div class="no-print mb-3">
      <button onclick="window.print()" class="btn btn-primary btn-sm">Print</button>
      <a href="<?= e(url('modules/billing/credit_notes.php')); ?>" class="btn btn-outline-secondary btn-sm">Back</a>
    </div>

    <div class="head">
      <div>
        <h1 class="title">Credit Note</h1>
        <div><strong><?= e((string) ($note['company_name'] ?? '')); ?></strong></div>
        <div class="muted"><?= e((string) ($note['garage_name'] ?? '')); ?></div>
        <div class="muted"><?= e((string) ($note['company_address'] ?? '')); ?> <?= e((string) ($note['company_city'] ?? '')); ?> <?= e((string) ($note['company_state'] ?? '')); ?></div>
        <?php if (!empty($note['company_gstin'])): ?>
          <div class="muted">GSTIN: <?= e((string) ($note['company_gstin'] ?? '')); ?></div>
        <?php endif; ?>
      </div>
      <div class="text-end">
        <?php if ($logoUrl !== null): ?>
          <img src="<?= e($logoUrl); ?>" alt="Logo" style="height:52px; width:auto; margin-bottom:8px;" />
        <?php endif; ?>
        <div><strong>Credit Note:</strong> <?= e((string) ($note['credit_note_number'] ?? '')); ?></div>
        <div><strong>Date:</strong> <?= e((string) ($note['credit_note_date'] ?? '')); ?></div>
      </div>
    </div>

    <div class="meta">
      <div>
        <strong>Invoice:</strong> <?= e((string) (($note['invoice_number'] ?? '') !== '' ? $note['invoice_number'] : '-')); ?><br />
        <strong>Invoice Date:</strong> <?= e((string) (($note['invoice_date'] ?? '') !== '' ? $note['invoice_date'] : '-')); ?><br />
        <strong>Return Ref:</strong> <?= e((string) (($note['return_number'] ?? '') !== '' ? $note['return_number'] : '-')); ?>
      </div>
      <div>
        <strong>Customer:</strong> <?= e((string) (($note['customer_name'] ?? '') !== '' ? $note['customer_name'] : '-')); ?><br />
        <strong>Phone:</strong> <?= e((string) (($note['customer_phone'] ?? '') !== '' ? $note['customer_phone'] : '-')); ?><br />
        <strong>GSTIN:</strong> <?= e((string) (($note['customer_gstin'] ?? '') !== '' ? $note['customer_gstin'] : '-')); ?>
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
          <?php foreach ($items as $index => $item): ?>
            <tr>
              <td><?= (int) $index + 1; ?></td>
              <td><?= e((string) ($item['description'] ?? '')); ?></td>
              <td class="text-end"><?= e(number_format((float) ($item['quantity'] ?? 0), 2)); ?></td>
              <td class="text-end"><?= e(number_format((float) ($item['unit_price'] ?? 0), 2)); ?></td>
              <td class="text-end"><?= e(number_format((float) ($item['gst_rate'] ?? 0), 2)); ?></td>
              <td class="text-end"><?= e(number_format((float) ($item['taxable_amount'] ?? 0), 2)); ?></td>
              <td class="text-end"><?= e(number_format((float) ($item['tax_amount'] ?? 0), 2)); ?></td>
              <td class="text-end"><?= e(number_format((float) ($item['total_amount'] ?? 0), 2)); ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <th colspan="5" class="text-end">Totals</th>
          <th class="text-end"><?= e(number_format((float) ($note['taxable_amount'] ?? 0), 2)); ?></th>
          <th class="text-end"><?= e(number_format((float) ($note['total_tax_amount'] ?? 0), 2)); ?></th>
          <th class="text-end"><?= e(number_format((float) ($note['total_amount'] ?? 0), 2)); ?></th>
        </tr>
      </tfoot>
    </table>
  </body>
</html>
