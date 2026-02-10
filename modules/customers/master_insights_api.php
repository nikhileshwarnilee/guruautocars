<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('customer.view');

header('Content-Type: application/json; charset=utf-8');

function customer_master_badge_class(string $customerType): string
{
    $type = strtoupper(trim($customerType));
    return match ($type) {
        'BUSINESS' => 'info',
        'FLEET' => 'primary',
        'GOVERNMENT' => 'warning',
        'OTHER' => 'secondary',
        default => 'secondary',
    };
}

function customer_master_render_rows(array $customers, bool $canManage): string
{
    ob_start();

    if ($customers === []) {
        ?>
        <tr><td colspan="8" class="text-center text-muted py-4">No customers found for selected filters.</td></tr>
        <?php
        return (string) ob_get_clean();
    }

    foreach ($customers as $customer) {
        $statusCode = (string) ($customer['status_code'] ?? 'ACTIVE');
        $customerType = strtoupper(trim((string) ($customer['customer_type_label'] ?? 'INDIVIDUAL')));
        if ($customerType === '') {
            $customerType = 'INDIVIDUAL';
        }

        ?>
        <tr>
          <td><?= (int) ($customer['id'] ?? 0); ?></td>
          <td>
            <?= e((string) ($customer['full_name'] ?? '')); ?>
            <span class="badge text-bg-<?= e(customer_master_badge_class($customerType)); ?> ms-1"><?= e($customerType); ?></span><br>
            <small class="text-muted"><?= e((string) (($customer['city'] ?? '-') . ', ' . ($customer['state'] ?? '-'))); ?></small>
          </td>
          <td><?= e((string) ($customer['phone'] ?? '-')); ?><br><small class="text-muted"><?= e((string) ($customer['email'] ?? '-')); ?></small></td>
          <td><?= e((string) ($customer['gstin'] ?? '-')); ?></td>
          <td><?= (int) ($customer['vehicle_count'] ?? 0); ?></td>
          <td>
            <span class="badge text-bg-light border me-1">History <?= (int) ($customer['history_count'] ?? 0); ?></span>
            <span class="badge text-bg-warning">Open Jobs <?= (int) ($customer['open_job_count'] ?? 0); ?></span><br>
            <?php if ((int) ($customer['job_count'] ?? 0) >= 2): ?>
              <small class="text-success">Repeat customer</small>
            <?php else: ?>
              <small class="text-muted">Single/new service profile</small>
            <?php endif; ?>
          </td>
          <td><span class="badge text-bg-<?= e(status_badge_class($statusCode)); ?>"><?= e(record_status_label($statusCode)); ?></span></td>
          <td class="d-flex gap-1">
            <a class="btn btn-sm btn-outline-info" href="<?= e(url('modules/customers/index.php?history_id=' . (int) ($customer['id'] ?? 0))); ?>">History</a>
            <?php if ($canManage): ?>
              <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/customers/index.php?edit_id=' . (int) ($customer['id'] ?? 0))); ?>">Edit</a>
              <?php if ($statusCode !== 'DELETED'): ?>
                <form method="post" class="d-inline" data-confirm="Change customer status?">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="change_status" />
                  <input type="hidden" name="customer_id" value="<?= (int) ($customer['id'] ?? 0); ?>" />
                  <input type="hidden" name="next_status" value="<?= e($statusCode === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'); ?>" />
                  <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $statusCode === 'ACTIVE' ? 'Inactivate' : 'Activate'; ?></button>
                </form>
                <form method="post" class="d-inline" data-confirm="Soft delete this customer?">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="change_status" />
                  <input type="hidden" name="customer_id" value="<?= (int) ($customer['id'] ?? 0); ?>" />
                  <input type="hidden" name="next_status" value="DELETED" />
                  <button type="submit" class="btn btn-sm btn-outline-danger">Soft Delete</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php
    }

    return (string) ob_get_clean();
}

$companyId = active_company_id();
$canManage = has_permission('customer.manage');

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = strtoupper(trim((string) ($_GET['status'] ?? '')));
$allowedStatuses = ['ACTIVE', 'INACTIVE', 'DELETED', 'ALL'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$gstinPresence = strtoupper(trim((string) ($_GET['gstin_presence'] ?? '')));
if (!in_array($gstinPresence, ['HAS_GSTIN', 'NO_GSTIN'], true)) {
    $gstinPresence = '';
}

$hasCustomerTypeColumn = in_array('customer_type', table_columns('customers'), true);
$customerTypeFilter = strtoupper(trim((string) ($_GET['customer_type'] ?? '')));
$allowedCustomerTypes = $hasCustomerTypeColumn
    ? ['', 'INDIVIDUAL', 'BUSINESS', 'FLEET', 'GOVERNMENT', 'OTHER', 'UNSPECIFIED']
    : ['', 'INDIVIDUAL', 'BUSINESS'];
if (!in_array($customerTypeFilter, $allowedCustomerTypes, true)) {
    $customerTypeFilter = '';
}

$fromDate = trim((string) ($_GET['from_date'] ?? ''));
$toDate = trim((string) ($_GET['to_date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $fromDate = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $toDate = '';
}
if ($fromDate !== '' && $toDate !== '' && strcmp($fromDate, $toDate) > 0) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$jobSummarySql =
    'SELECT jc.customer_id,
            COUNT(*) AS total_jobs,
            SUM(CASE WHEN jc.status_code = "ACTIVE" AND jc.status NOT IN ("CLOSED", "CANCELLED") THEN 1 ELSE 0 END) AS open_jobs
     FROM job_cards jc
     WHERE jc.company_id = :job_company_id
       AND jc.status_code <> "DELETED"
     GROUP BY jc.customer_id';

$customerTypeExpr = $hasCustomerTypeColumn
    ? 'UPPER(COALESCE(NULLIF(TRIM(c.customer_type), ""), CASE WHEN c.gstin IS NULL OR TRIM(c.gstin) = "" THEN "INDIVIDUAL" ELSE "BUSINESS" END))'
    : 'CASE WHEN c.gstin IS NULL OR TRIM(c.gstin) = "" THEN "INDIVIDUAL" ELSE "BUSINESS" END';

$whereParts = ['c.company_id = :company_id'];
$params = [
    'company_id' => $companyId,
    'job_company_id' => $companyId,
];

if ($search !== '') {
    $whereParts[] = '(c.full_name LIKE :query OR c.phone LIKE :query OR c.email LIKE :query OR c.gstin LIKE :query)';
    $params['query'] = '%' . $search . '%';
}

if ($statusFilter === '') {
    $whereParts[] = 'c.status_code <> "DELETED"';
} elseif ($statusFilter !== 'ALL') {
    $whereParts[] = 'c.status_code = :status_code';
    $params['status_code'] = $statusFilter;
}

if ($customerTypeFilter !== '') {
    if ($hasCustomerTypeColumn && $customerTypeFilter === 'UNSPECIFIED') {
        $whereParts[] = '(c.customer_type IS NULL OR TRIM(c.customer_type) = "")';
    } else {
        $whereParts[] = $customerTypeExpr . ' = :customer_type_filter';
        $params['customer_type_filter'] = $customerTypeFilter;
    }
}

if ($gstinPresence === 'HAS_GSTIN') {
    $whereParts[] = '(c.gstin IS NOT NULL AND TRIM(c.gstin) <> "")';
} elseif ($gstinPresence === 'NO_GSTIN') {
    $whereParts[] = '(c.gstin IS NULL OR TRIM(c.gstin) = "")';
}

if ($fromDate !== '') {
    $whereParts[] = 'DATE(c.created_at) >= :from_date';
    $params['from_date'] = $fromDate;
}
if ($toDate !== '') {
    $whereParts[] = 'DATE(c.created_at) <= :to_date';
    $params['to_date'] = $toDate;
}

try {
    $statsSql =
        'SELECT COUNT(*) AS total_customers,
                SUM(CASE WHEN c.status_code = "ACTIVE" THEN 1 ELSE 0 END) AS active_customers,
                SUM(CASE WHEN COALESCE(js.total_jobs, 0) >= 2 THEN 1 ELSE 0 END) AS repeat_customers,
                SUM(CASE WHEN COALESCE(js.open_jobs, 0) > 0 THEN 1 ELSE 0 END) AS customers_with_open_jobs
         FROM customers c
         LEFT JOIN (' . $jobSummarySql . ') js ON js.customer_id = c.id
         WHERE ' . implode(' AND ', $whereParts);

    $statsStmt = db()->prepare($statsSql);
    $statsStmt->execute($params);
    $statsRow = $statsStmt->fetch() ?: [];

    $listSql =
        'SELECT c.*,
                ' . $customerTypeExpr . ' AS customer_type_label,
                COALESCE(js.total_jobs, 0) AS job_count,
                COALESCE(js.open_jobs, 0) AS open_job_count,
                (SELECT COUNT(*) FROM vehicles v WHERE v.customer_id = c.id AND v.status_code <> "DELETED") AS vehicle_count,
                (SELECT COUNT(*) FROM customer_history h WHERE h.customer_id = c.id) AS history_count
         FROM customers c
         LEFT JOIN (' . $jobSummarySql . ') js ON js.customer_id = c.id
         WHERE ' . implode(' AND ', $whereParts) . '
         ORDER BY c.id DESC
         LIMIT 500';

    $listStmt = db()->prepare($listSql);
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll();

    echo json_encode([
        'ok' => true,
        'stats' => [
            'total_customers' => (int) ($statsRow['total_customers'] ?? 0),
            'active_customers' => (int) ($statsRow['active_customers'] ?? 0),
            'repeat_customers' => (int) ($statsRow['repeat_customers'] ?? 0),
            'customers_with_open_jobs' => (int) ($statsRow['customers_with_open_jobs'] ?? 0),
        ],
        'rows_count' => count($rows),
        'table_rows_html' => customer_master_render_rows($rows, $canManage),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to load customer insights right now.',
    ], JSON_UNESCAPED_UNICODE);
}
