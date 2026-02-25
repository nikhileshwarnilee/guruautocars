<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/workflow.php';

if (!billing_can_view()) {
    flash_set('access_denied', 'You do not have permission to access credit notes.', 'danger');
    redirect('dashboard.php');
}

$page_title = 'Credit Notes';
$active_menu = 'billing.credit_notes';
$companyId = active_company_id();
$garageId = active_garage_id();

if (!billing_financial_extensions_ready()) {
    flash_set('billing_error', 'Financial extensions are not ready for credit notes.', 'danger');
}

function billing_credit_notes_parse_date(?string $value): ?string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $raw);
    if ($date === false || $date->format('Y-m-d') !== $raw) {
        return null;
    }

    return $raw;
}

$fromDate = billing_credit_notes_parse_date((string) ($_GET['from'] ?? date('Y-m-01'))) ?? date('Y-m-01');
$toDate = billing_credit_notes_parse_date((string) ($_GET['to'] ?? date('Y-m-d'))) ?? date('Y-m-d');
$customerId = get_int('customer_id');
$invoiceNumber = trim((string) ($_GET['invoice_number'] ?? ''));

$where = [
    'cn.company_id = :company_id',
    'cn.garage_id = :garage_id',
    'cn.credit_note_date BETWEEN :from_date AND :to_date',
];
$params = [
    'company_id' => $companyId,
    'garage_id' => $garageId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
];

if ($customerId > 0) {
    $where[] = 'cn.customer_id = :customer_id';
    $params['customer_id'] = $customerId;
}
if ($invoiceNumber !== '') {
    $where[] = 'i.invoice_number LIKE :invoice_number';
    $params['invoice_number'] = '%' . $invoiceNumber . '%';
}

$creditNoteStmt = db()->prepare(
    'SELECT cn.*,
            i.invoice_number,
            c.full_name AS customer_name,
            jc.job_number,
            r.return_number
     FROM credit_notes cn
     LEFT JOIN invoices i ON i.id = cn.invoice_id
     LEFT JOIN customers c ON c.id = cn.customer_id
     LEFT JOIN job_cards jc ON jc.id = cn.job_card_id
     LEFT JOIN returns_rma r ON r.id = cn.return_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY cn.id DESC
     LIMIT 300'
);
$creditNoteStmt->execute($params);
$creditNotes = $creditNoteStmt->fetchAll();

$customersStmt = db()->prepare(
    'SELECT id, full_name
     FROM customers
     WHERE company_id = :company_id
       AND status_code = "ACTIVE"
     ORDER BY full_name ASC'
);
$customersStmt->execute(['company_id' => $companyId]);
$customers = $customersStmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Credit Notes</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/billing/index.php')); ?>">Billing</a></li>
            <li class="breadcrumb-item active">Credit Notes</li>
          </ol>
        </div>
      </div>
    </div>
  </div>
  <div class="app-content">
    <div class="container-fluid">
      <div class="card card-outline card-primary mb-3">
        <div class="card-header"><h3 class="card-title">Filters</h3></div>
        <div class="card-body">
          <form method="get" class="row g-2">
            <div class="col-md-2">
              <label class="form-label">From</label>
              <input type="date" name="from" class="form-control" value="<?= e($fromDate); ?>" />
            </div>
            <div class="col-md-2">
              <label class="form-label">To</label>
              <input type="date" name="to" class="form-control" value="<?= e($toDate); ?>" />
            </div>
            <div class="col-md-3">
              <label class="form-label">Customer</label>
              <select name="customer_id" class="form-select" data-searchable-select="1">
                <option value="">All Customers</option>
                <?php foreach ($customers as $customer): ?>
                  <option value="<?= (int) ($customer['id'] ?? 0); ?>" <?= $customerId === (int) ($customer['id'] ?? 0) ? 'selected' : ''; ?>>
                    <?= e((string) ($customer['full_name'] ?? '')); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Invoice Number</label>
              <input type="text" name="invoice_number" class="form-control" value="<?= e($invoiceNumber); ?>" />
            </div>
            <div class="col-md-2 d-flex align-items-end">
              <button type="submit" class="btn btn-outline-primary w-100">Apply</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card card-outline card-secondary">
        <div class="card-header"><h3 class="card-title">Credit Note Ledger</h3></div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-bordered table-hover table-sm">
              <thead>
                <tr>
                  <th>Credit Note</th>
                  <th>Date</th>
                  <th>Invoice</th>
                  <th>Return Ref</th>
                  <th>Customer</th>
                  <th class="text-end">Taxable</th>
                  <th class="text-end">Tax</th>
                  <th class="text-end">Total</th>
                  <th>Status</th>
                  <th>Print</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($creditNotes === []): ?>
                  <tr><td colspan="10" class="text-center text-muted">No credit notes found.</td></tr>
                <?php else: ?>
                  <?php foreach ($creditNotes as $note): ?>
                    <tr>
                      <td><?= e((string) ($note['credit_note_number'] ?? '')); ?></td>
                      <td><?= e((string) ($note['credit_note_date'] ?? '')); ?></td>
                      <td><?= e((string) (($note['invoice_number'] ?? '') !== '' ? $note['invoice_number'] : '-')); ?></td>
                      <td><?= e((string) (($note['return_number'] ?? '') !== '' ? $note['return_number'] : '-')); ?></td>
                      <td><?= e((string) (($note['customer_name'] ?? '') !== '' ? $note['customer_name'] : '-')); ?></td>
                      <td class="text-end"><?= e(number_format((float) ($note['taxable_amount'] ?? 0), 2)); ?></td>
                      <td class="text-end"><?= e(number_format((float) ($note['total_tax_amount'] ?? 0), 2)); ?></td>
                      <td class="text-end"><?= e(number_format((float) ($note['total_amount'] ?? 0), 2)); ?></td>
                      <td>
                        <span class="badge text-bg-<?= e((string) ($note['status_code'] ?? '') === 'ACTIVE' ? 'success' : 'secondary'); ?>">
                          <?= e((string) ($note['status_code'] ?? '')); ?>
                        </span>
                      </td>
                      <td>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('modules/billing/print_credit_note.php?id=' . (int) ($note['id'] ?? 0))); ?>" target="_blank">
                          Print
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
