<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('export.data');

$page_title = 'Data Exports';
$active_menu = 'system.exports';

function exports_csv_download(string $filename, array $headers, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $stream = fopen('php://output', 'w');
    if ($stream === false) {
        http_response_code(500);
        exit('Unable to generate export.');
    }

    fwrite($stream, "\xEF\xBB\xBF");
    fputcsv($stream, $headers);
    foreach ($rows as $row) {
        $flat = [];
        foreach ($row as $value) {
            $flat[] = is_scalar($value) || $value === null ? (string) ($value ?? '') : json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        fputcsv($stream, $flat);
    }
    fclose($stream);
    exit;
}

function exports_module_label(string $moduleKey): string
{
    return match ($moduleKey) {
        'jobs' => 'Jobs',
        'invoices' => 'Invoices',
        'payments' => 'Payments',
        'inventory' => 'Inventory Movements',
        'customers' => 'Customers',
        'reports_summary' => 'Reports Summary',
        default => ucfirst($moduleKey),
    };
}

function exports_is_sensitive_module(string $moduleKey): bool
{
    return in_array($moduleKey, ['invoices', 'payments', 'reports_summary'], true);
}

function exports_invoice_statuses(bool $includeDraft, bool $includeCancelled): array
{
    $statuses = ['FINALIZED'];
    if ($includeDraft) {
        $statuses[] = 'DRAFT';
    }
    if ($includeCancelled) {
        $statuses[] = 'CANCELLED';
    }

    return array_values(array_unique($statuses));
}

$companyId = active_company_id();
$activeGarageId = active_garage_id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$roleKey = (string) ($_SESSION['role_key'] ?? '');
$isOwnerScope = analytics_is_owner_role($roleKey);
$canSensitiveExports = in_array($roleKey, ['super_admin', 'garage_owner', 'accountant'], true);

$garageOptions = analytics_accessible_garages($companyId, $isOwnerScope);
$garageIds = array_values(array_filter(array_map(static fn (array $garage): int => (int) ($garage['id'] ?? 0), $garageOptions), static fn (int $id): bool => $id > 0));
$allowAllGarages = $isOwnerScope && count($garageIds) > 1;
$garageRequested = isset($_GET['garage_id']) ? get_int('garage_id', $activeGarageId) : $activeGarageId;
$selectedGarageId = analytics_resolve_scope_garage_id($garageOptions, $activeGarageId, $garageRequested, $allowAllGarages);
$scopeGarageLabel = analytics_scope_garage_label($garageOptions, $selectedGarageId);

$fyContext = analytics_resolve_financial_year($companyId, get_int('fy_id', 0));
$financialYears = $fyContext['years'];
$selectedFy = $fyContext['selected'];
$selectedFyId = (int) ($selectedFy['id'] ?? 0);
$fyStart = (string) ($selectedFy['start_date'] ?? date('Y-04-01'));
$fyEnd = (string) ($selectedFy['end_date'] ?? date('Y-03-31', strtotime('+1 year')));
$fyLabel = (string) ($selectedFy['fy_label'] ?? '-');

$today = date('Y-m-d');
$defaultToDate = $today <= $fyEnd ? $today : $fyEnd;
if ($defaultToDate < $fyStart) {
    $defaultToDate = $fyStart;
}

$fromDate = analytics_parse_iso_date($_GET['from'] ?? null, $fyStart);
$toDate = analytics_parse_iso_date($_GET['to'] ?? null, $defaultToDate);
if ($fromDate < $fyStart) {
    $fromDate = $fyStart;
}
if ($toDate > $fyEnd) {
    $toDate = $fyEnd;
}
if ($toDate < $fromDate) {
    $toDate = $fromDate;
}

$includeDraft = (string) ($_GET['include_draft'] ?? '0') === '1';
$includeCancelled = (string) ($_GET['include_cancelled'] ?? '0') === '1';
$download = (string) ($_GET['download'] ?? '0') === '1';
$moduleKey = trim((string) ($_GET['module'] ?? ''));
$confirmLarge = (string) ($_GET['confirm_large'] ?? '0') === '1';

$moduleOrder = ['jobs', 'invoices', 'payments', 'inventory', 'customers', 'reports_summary'];
$baseParams = [
    'garage_id' => $selectedGarageId,
    'fy_id' => $selectedFyId,
    'from' => $fromDate,
    'to' => $toDate,
    'include_draft' => $includeDraft ? 1 : 0,
    'include_cancelled' => $includeCancelled ? 1 : 0,
];

$pendingLargeExport = null;
$largeExportThreshold = 5000;

if ($download && $moduleKey !== '') {
    if (!in_array($moduleKey, $moduleOrder, true)) {
        flash_set('export_error', 'Invalid export module selected.', 'danger');
        redirect('modules/system/exports.php?' . http_build_query($baseParams));
    }

    if (exports_is_sensitive_module($moduleKey) && !$canSensitiveExports) {
        flash_set('export_error', 'Your role is not permitted to export sensitive financial data.', 'danger');
        redirect('modules/system/exports.php?' . http_build_query($baseParams));
    }

    $rows = [];
    $headers = [];
    $countRows = 0;
    $moduleLabel = exports_module_label($moduleKey);

    if ($moduleKey === 'jobs') {
        $params = [
            'company_id' => $companyId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ];
        $scopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $params, 'job_scope');
        $countStmt = db()->prepare(
            'SELECT COUNT(*)
             FROM job_cards jc
             WHERE jc.company_id = :company_id
               AND jc.status_code <> "DELETED"
               ' . $scopeSql . '
               AND DATE(COALESCE(jc.opened_at, jc.created_at)) BETWEEN :from_date AND :to_date'
        );
        $countStmt->execute($params);
        $countRows = (int) $countStmt->fetchColumn();

        $listStmt = db()->prepare(
            'SELECT jc.job_number, DATE(COALESCE(jc.opened_at, jc.created_at)) AS opened_date,
                    jc.status, jc.priority, jc.estimated_cost, jc.status_code,
                    c.full_name AS customer_name, v.registration_no, g.name AS garage_name
             FROM job_cards jc
             INNER JOIN customers c ON c.id = jc.customer_id
             INNER JOIN vehicles v ON v.id = jc.vehicle_id
             INNER JOIN garages g ON g.id = jc.garage_id
             WHERE jc.company_id = :company_id
               AND jc.status_code <> "DELETED"
               ' . $scopeSql . '
               AND DATE(COALESCE(jc.opened_at, jc.created_at)) BETWEEN :from_date AND :to_date
             ORDER BY jc.id DESC'
        );
        $listStmt->execute($params);
        $data = $listStmt->fetchAll();

        $headers = ['Job Number', 'Opened Date', 'Status', 'Priority', 'Customer', 'Vehicle', 'Estimated Cost', 'Garage', 'Status Code'];
        foreach ($data as $row) {
            $rows[] = [
                (string) ($row['job_number'] ?? ''),
                (string) ($row['opened_date'] ?? ''),
                (string) ($row['status'] ?? ''),
                (string) ($row['priority'] ?? ''),
                (string) ($row['customer_name'] ?? ''),
                (string) ($row['registration_no'] ?? ''),
                (float) ($row['estimated_cost'] ?? 0),
                (string) ($row['garage_name'] ?? ''),
                (string) ($row['status_code'] ?? ''),
            ];
        }
    }

    if ($moduleKey === 'invoices') {
        $invoiceStatuses = exports_invoice_statuses($includeDraft, $includeCancelled);
        $params = [
            'company_id' => $companyId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ];
        $scopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $params, 'invoice_scope');
        $statusPlaceholders = [];
        foreach ($invoiceStatuses as $index => $status) {
            $statusKey = 'inv_status_' . $index;
            $statusPlaceholders[] = ':' . $statusKey;
            $params[$statusKey] = $status;
        }
        $statusSql = ' AND i.invoice_status IN (' . implode(', ', $statusPlaceholders) . ') ';

        $countStmt = db()->prepare(
            'SELECT COUNT(*)
             FROM invoices i
             WHERE i.company_id = :company_id
               ' . $scopeSql . '
               AND i.invoice_date BETWEEN :from_date AND :to_date
               ' . $statusSql
        );
        $countStmt->execute($params);
        $countRows = (int) $countStmt->fetchColumn();

        $listStmt = db()->prepare(
            'SELECT i.invoice_number, i.invoice_date, i.invoice_status, i.payment_status, i.tax_regime,
                    i.taxable_amount, i.total_tax_amount, i.grand_total, i.financial_year_label,
                    c.full_name AS customer_name, v.registration_no, g.name AS garage_name
             FROM invoices i
             LEFT JOIN customers c ON c.id = i.customer_id
             LEFT JOIN vehicles v ON v.id = i.vehicle_id
             INNER JOIN garages g ON g.id = i.garage_id
             WHERE i.company_id = :company_id
               ' . $scopeSql . '
               AND i.invoice_date BETWEEN :from_date AND :to_date
               ' . $statusSql . '
             ORDER BY i.id DESC'
        );
        $listStmt->execute($params);
        $data = $listStmt->fetchAll();

        $headers = ['Invoice Number', 'Invoice Date', 'Status', 'Payment Status', 'Customer', 'Vehicle', 'Tax Regime', 'Taxable Amount', 'Tax Amount', 'Grand Total', 'FY', 'Garage'];
        foreach ($data as $row) {
            $rows[] = [
                (string) ($row['invoice_number'] ?? ''),
                (string) ($row['invoice_date'] ?? ''),
                (string) ($row['invoice_status'] ?? ''),
                (string) ($row['payment_status'] ?? ''),
                (string) ($row['customer_name'] ?? ''),
                (string) ($row['registration_no'] ?? ''),
                (string) ($row['tax_regime'] ?? ''),
                (float) ($row['taxable_amount'] ?? 0),
                (float) ($row['total_tax_amount'] ?? 0),
                (float) ($row['grand_total'] ?? 0),
                (string) ($row['financial_year_label'] ?? ''),
                (string) ($row['garage_name'] ?? ''),
            ];
        }
    }

    if ($moduleKey === 'payments') {
        $invoiceStatuses = exports_invoice_statuses($includeDraft, $includeCancelled);
        $params = [
            'company_id' => $companyId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ];
        $scopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $params, 'payment_scope');
        $statusPlaceholders = [];
        foreach ($invoiceStatuses as $index => $status) {
            $statusKey = 'pay_status_' . $index;
            $statusPlaceholders[] = ':' . $statusKey;
            $params[$statusKey] = $status;
        }
        $statusSql = ' AND i.invoice_status IN (' . implode(', ', $statusPlaceholders) . ') ';

        $countStmt = db()->prepare(
            'SELECT COUNT(*)
             FROM payments p
             INNER JOIN invoices i ON i.id = p.invoice_id
             WHERE i.company_id = :company_id
               ' . $scopeSql . '
               AND p.paid_on BETWEEN :from_date AND :to_date
               ' . $statusSql
        );
        $countStmt->execute($params);
        $countRows = (int) $countStmt->fetchColumn();

        $listStmt = db()->prepare(
            'SELECT p.paid_on, i.invoice_number, i.invoice_status, p.amount, p.payment_mode, p.reference_no,
                    u.name AS received_by, g.name AS garage_name
             FROM payments p
             INNER JOIN invoices i ON i.id = p.invoice_id
             INNER JOIN garages g ON g.id = i.garage_id
             LEFT JOIN users u ON u.id = p.received_by
             WHERE i.company_id = :company_id
               ' . $scopeSql . '
               AND p.paid_on BETWEEN :from_date AND :to_date
               ' . $statusSql . '
             ORDER BY p.id DESC'
        );
        $listStmt->execute($params);
        $data = $listStmt->fetchAll();

        $headers = ['Paid On', 'Invoice', 'Invoice Status', 'Amount', 'Payment Mode', 'Reference', 'Received By', 'Garage'];
        foreach ($data as $row) {
            $rows[] = [
                (string) ($row['paid_on'] ?? ''),
                (string) ($row['invoice_number'] ?? ''),
                (string) ($row['invoice_status'] ?? ''),
                (float) ($row['amount'] ?? 0),
                (string) ($row['payment_mode'] ?? ''),
                (string) ($row['reference_no'] ?? ''),
                (string) ($row['received_by'] ?? ''),
                (string) ($row['garage_name'] ?? ''),
            ];
        }
    }

    if ($moduleKey === 'inventory') {
        $params = [
            'company_id' => $companyId,
            'from_at' => $fromDate . ' 00:00:00',
            'to_at' => $toDate . ' 23:59:59',
        ];
        $scopeSql = analytics_garage_scope_sql('im.garage_id', $selectedGarageId, $garageIds, $params, 'inv_scope');

        $countStmt = db()->prepare(
            'SELECT COUNT(*)
             FROM inventory_movements im
             WHERE im.company_id = :company_id
               ' . $scopeSql . '
               AND im.created_at BETWEEN :from_at AND :to_at'
        );
        $countStmt->execute($params);
        $countRows = (int) $countStmt->fetchColumn();

        $listStmt = db()->prepare(
            'SELECT im.created_at, im.movement_type, im.reference_type, im.reference_id, im.quantity,
                    CASE
                      WHEN im.movement_type = "OUT" THEN -1 * ABS(im.quantity)
                      WHEN im.movement_type = "IN" THEN ABS(im.quantity)
                      ELSE im.quantity
                    END AS signed_qty,
                    p.part_name, p.part_sku, g.name AS garage_name, u.name AS created_by
             FROM inventory_movements im
             INNER JOIN parts p ON p.id = im.part_id
             INNER JOIN garages g ON g.id = im.garage_id
             LEFT JOIN users u ON u.id = im.created_by
             WHERE im.company_id = :company_id
               ' . $scopeSql . '
               AND im.created_at BETWEEN :from_at AND :to_at
             ORDER BY im.id DESC'
        );
        $listStmt->execute($params);
        $data = $listStmt->fetchAll();

        $headers = ['Created At', 'Garage', 'Part', 'SKU', 'Movement Type', 'Signed Qty', 'Absolute Qty', 'Source', 'Reference ID', 'Created By'];
        foreach ($data as $row) {
            $rows[] = [
                (string) ($row['created_at'] ?? ''),
                (string) ($row['garage_name'] ?? ''),
                (string) ($row['part_name'] ?? ''),
                (string) ($row['part_sku'] ?? ''),
                (string) ($row['movement_type'] ?? ''),
                (float) ($row['signed_qty'] ?? 0),
                (float) ($row['quantity'] ?? 0),
                (string) ($row['reference_type'] ?? ''),
                $row['reference_id'] !== null ? (int) $row['reference_id'] : '',
                (string) ($row['created_by'] ?? ''),
            ];
        }
    }

    if ($moduleKey === 'customers') {
        $params = [
            'company_id' => $companyId,
            'from_at' => $fromDate . ' 00:00:00',
            'to_at' => $toDate . ' 23:59:59',
        ];
        $countStmt = db()->prepare(
            'SELECT COUNT(*)
             FROM customers c
             WHERE c.company_id = :company_id
               AND c.status_code <> "DELETED"
               AND c.created_at BETWEEN :from_at AND :to_at'
        );
        $countStmt->execute($params);
        $countRows = (int) $countStmt->fetchColumn();

        $listStmt = db()->prepare(
            'SELECT c.full_name, c.phone, c.email, c.gstin, c.city, c.state, c.status_code, c.created_at
             FROM customers c
             WHERE c.company_id = :company_id
               AND c.status_code <> "DELETED"
               AND c.created_at BETWEEN :from_at AND :to_at
             ORDER BY c.id DESC'
        );
        $listStmt->execute($params);
        $data = $listStmt->fetchAll();

        $headers = ['Customer', 'Phone', 'Email', 'GSTIN', 'City', 'State', 'Status', 'Created At'];
        foreach ($data as $row) {
            $rows[] = [
                (string) ($row['full_name'] ?? ''),
                (string) ($row['phone'] ?? ''),
                (string) ($row['email'] ?? ''),
                (string) ($row['gstin'] ?? ''),
                (string) ($row['city'] ?? ''),
                (string) ($row['state'] ?? ''),
                (string) ($row['status_code'] ?? ''),
                (string) ($row['created_at'] ?? ''),
            ];
        }
    }

    if ($moduleKey === 'reports_summary') {
        $params = [
            'company_id' => $companyId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ];
        $jobScopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $params, 'sum_job_scope');
        $invoiceScopeSql = analytics_garage_scope_sql('i.garage_id', $selectedGarageId, $garageIds, $params, 'sum_inv_scope');
        $movementScopeSql = analytics_garage_scope_sql('im.garage_id', $selectedGarageId, $garageIds, $params, 'sum_mov_scope');

        $metrics = [];

        $stmt = db()->prepare(
            'SELECT COUNT(*) AS total_jobs,
                    COALESCE(SUM(CASE WHEN jc.status = "CLOSED" THEN 1 ELSE 0 END), 0) AS closed_jobs
             FROM job_cards jc
             WHERE jc.company_id = :company_id
               AND jc.status_code = "ACTIVE"
               ' . $jobScopeSql . '
               AND DATE(COALESCE(jc.closed_at, jc.opened_at, jc.created_at)) BETWEEN :from_date AND :to_date'
        );
        $stmt->execute($params);
        $jobSummary = $stmt->fetch() ?: ['total_jobs' => 0, 'closed_jobs' => 0];
        $metrics[] = ['Total Jobs', (int) ($jobSummary['total_jobs'] ?? 0)];
        $metrics[] = ['Closed Jobs', (int) ($jobSummary['closed_jobs'] ?? 0)];

        $stmt = db()->prepare(
            'SELECT COUNT(*) AS invoice_count,
                    COALESCE(SUM(i.grand_total), 0) AS revenue_total,
                    COALESCE(SUM(i.total_tax_amount), 0) AS tax_total
             FROM invoices i
             WHERE i.company_id = :company_id
               AND i.invoice_status = "FINALIZED"
               ' . $invoiceScopeSql . '
               AND i.invoice_date BETWEEN :from_date AND :to_date'
        );
        $stmt->execute($params);
        $invoiceSummary = $stmt->fetch() ?: ['invoice_count' => 0, 'revenue_total' => 0, 'tax_total' => 0];
        $metrics[] = ['Finalized Invoices', (int) ($invoiceSummary['invoice_count'] ?? 0)];
        $metrics[] = ['Finalized Revenue', (float) ($invoiceSummary['revenue_total'] ?? 0)];
        $metrics[] = ['GST Total', (float) ($invoiceSummary['tax_total'] ?? 0)];

        $stmt = db()->prepare(
            'SELECT COALESCE(SUM(p.amount), 0) AS collected_amount
             FROM payments p
             INNER JOIN invoices i ON i.id = p.invoice_id
             WHERE i.company_id = :company_id
               AND i.invoice_status = "FINALIZED"
               AND ' . reversal_sales_payment_unreversed_filter_sql('p') . '
               ' . $invoiceScopeSql . '
               AND p.paid_on BETWEEN :from_date AND :to_date'
        );
        $stmt->execute($params);
        $paymentSummary = $stmt->fetch() ?: ['collected_amount' => 0];
        $metrics[] = ['Payments Collected', (float) ($paymentSummary['collected_amount'] ?? 0)];

        $stmt = db()->prepare(
            'SELECT COUNT(*) AS movement_count
             FROM inventory_movements im
             WHERE im.company_id = :company_id
               ' . $movementScopeSql . '
               AND DATE(im.created_at) BETWEEN :from_date AND :to_date'
        );
        $stmt->execute($params);
        $movementSummary = $stmt->fetch() ?: ['movement_count' => 0];
        $metrics[] = ['Inventory Movements', (int) ($movementSummary['movement_count'] ?? 0)];

        $headers = ['Metric', 'Value', 'Scope'];
        foreach ($metrics as $metric) {
            $rows[] = [$metric[0], $metric[1], $scopeGarageLabel];
        }
        $countRows = count($rows);
    }

    if ($countRows > $largeExportThreshold && !$confirmLarge) {
        $pendingLargeExport = [
            'module_key' => $moduleKey,
            'module_label' => $moduleLabel,
            'row_count' => $countRows,
            'confirm_url' => url('modules/system/exports.php?' . http_build_query(array_merge($baseParams, [
                'module' => $moduleKey,
                'download' => 1,
                'confirm_large' => 1,
            ]))),
        ];
    } else {
        log_data_export($moduleKey, 'CSV', $countRows, [
            'company_id' => $companyId,
            'garage_id' => $selectedGarageId > 0 ? $selectedGarageId : null,
            'include_draft' => $includeDraft,
            'include_cancelled' => $includeCancelled,
            'filter_summary' => 'FY ' . $fyLabel . ', ' . $fromDate . ' to ' . $toDate,
            'scope' => [
                'garage_scope' => $scopeGarageLabel,
                'fy_label' => $fyLabel,
                'from' => $fromDate,
                'to' => $toDate,
            ],
            'requested_by' => $userId > 0 ? $userId : null,
        ]);

        log_audit('exports', 'download', null, 'Exported ' . $moduleLabel . ' data.', [
            'entity' => 'data_export',
            'source' => 'UI',
            'before' => ['requested' => true],
            'after' => [
                'module' => $moduleKey,
                'format' => 'CSV',
                'row_count' => $countRows,
            ],
            'metadata' => [
                'garage_scope' => $scopeGarageLabel,
                'fy_label' => $fyLabel,
                'from' => $fromDate,
                'to' => $toDate,
                'include_draft' => $includeDraft,
                'include_cancelled' => $includeCancelled,
            ],
        ]);

        $filename = strtolower(str_replace(' ', '_', $moduleLabel)) . '_' . date('Ymd_His') . '.csv';
        exports_csv_download($filename, $headers, $rows);
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-7">
          <h3 class="mb-0">Data Exports</h3>
          <small class="text-muted">Scoped CSV exports for audit/accounting workflows. Current scope: <?= e($scopeGarageLabel); ?> | FY <?= e($fyLabel); ?></small>
        </div>
        <div class="col-sm-5">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Data Exports</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="card card-outline card-primary mb-3">
        <div class="card-header"><h3 class="card-title">Export Scope Filters</h3></div>
        <form method="get">
          <div class="card-body row g-2">
            <div class="col-md-2">
              <label class="form-label">Garage</label>
              <select name="garage_id" class="form-select">
                <?php if ($allowAllGarages): ?>
                  <option value="0" <?= $selectedGarageId === 0 ? 'selected' : ''; ?>>All Accessible</option>
                <?php endif; ?>
                <?php foreach ($garageOptions as $garage): ?>
                  <option value="<?= (int) $garage['id']; ?>" <?= ((int) $garage['id'] === $selectedGarageId) ? 'selected' : ''; ?>>
                    <?= e((string) $garage['name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Financial Year</label>
              <select name="fy_id" class="form-select">
                <?php if (empty($financialYears)): ?>
                  <option value="0" selected><?= e($fyLabel); ?></option>
                <?php else: ?>
                  <?php foreach ($financialYears as $fy): ?>
                    <option value="<?= (int) $fy['id']; ?>" <?= ((int) $fy['id'] === $selectedFyId) ? 'selected' : ''; ?>>
                      <?= e((string) $fy['fy_label']); ?>
                    </option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">From</label>
              <input type="date" name="from" class="form-control" value="<?= e($fromDate); ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label">To</label>
              <input type="date" name="to" class="form-control" value="<?= e($toDate); ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="include_draft" id="include_draft" value="1" <?= $includeDraft ? 'checked' : ''; ?>>
                <label class="form-check-label" for="include_draft">Include Draft Financials</label>
              </div>
            </div>
            <div class="col-md-2 d-flex align-items-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="include_cancelled" id="include_cancelled" value="1" <?= $includeCancelled ? 'checked' : ''; ?>>
                <label class="form-check-label" for="include_cancelled">Include Cancelled Financials</label>
              </div>
            </div>
          </div>
          <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-primary">Apply Scope</button>
            <a href="<?= e(url('modules/system/exports.php')); ?>" class="btn btn-outline-secondary">Reset</a>
          </div>
        </form>
      </div>

      <?php if ($pendingLargeExport !== null): ?>
        <div class="alert alert-warning">
          <strong>Large Export Confirmation Required:</strong>
          <?= e($pendingLargeExport['module_label']); ?> will export approximately <?= e(number_format((int) $pendingLargeExport['row_count'])); ?> rows.
          <a href="<?= e((string) $pendingLargeExport['confirm_url']); ?>" class="btn btn-sm btn-warning ms-2">Confirm and Download</a>
        </div>
      <?php endif; ?>

      <div class="row g-3">
        <?php foreach ($moduleOrder as $module): ?>
          <?php
            $isSensitive = exports_is_sensitive_module($module);
            $blocked = $isSensitive && !$canSensitiveExports;
            $moduleParams = array_merge($baseParams, ['module' => $module, 'download' => 1]);
            $downloadUrl = url('modules/system/exports.php?' . http_build_query($moduleParams));
          ?>
          <div class="col-lg-4">
            <div class="card h-100 <?= $blocked ? 'card-outline card-secondary' : 'card-outline card-primary'; ?>">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0"><?= e(exports_module_label($module)); ?></h3>
                <?php if ($isSensitive): ?>
                  <span class="badge text-bg-warning">Sensitive</span>
                <?php endif; ?>
              </div>
              <div class="card-body">
                <div class="small text-muted mb-2">Scope: <?= e($scopeGarageLabel); ?> | <?= e($fromDate); ?> to <?= e($toDate); ?> | FY <?= e($fyLabel); ?></div>
                <?php if ($module === 'invoices' || $module === 'payments' || $module === 'reports_summary'): ?>
                  <div class="small text-muted">Default export includes finalized financial records only.</div>
                <?php else: ?>
                  <div class="small text-muted">Exports scoped operational data based on selected filters.</div>
                <?php endif; ?>
              </div>
              <div class="card-footer">
                <?php if ($blocked): ?>
                  <button class="btn btn-outline-secondary btn-sm" type="button" disabled>Not Allowed for Role</button>
                <?php else: ?>
                  <a href="<?= e($downloadUrl); ?>" class="btn btn-primary btn-sm">Export CSV</a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
