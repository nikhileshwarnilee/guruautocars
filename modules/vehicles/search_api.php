<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('vehicle.view');

header('Content-Type: application/json; charset=utf-8');

function vehicle_search_format_datetime(?string $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        return $raw;
    }

    return date('d M Y, h:i A', $timestamp);
}

function vehicle_search_digits(string $value): string
{
    $normalized = preg_replace('/\D+/', '', $value);
    return is_string($normalized) ? $normalized : '';
}

$companyId = active_company_id();
$search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);

if ($companyId <= 0 || mb_strlen($search) < 2) {
    echo json_encode([
        'ok' => true,
        'query' => $search,
        'items' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$customerColumns = table_columns('customers');
$hasAltPhone = in_array('alt_phone', $customerColumns, true);

$registrationCompactExpr = 'REPLACE(REPLACE(REPLACE(UPPER(v.registration_no), " ", ""), "-", ""), ".", "")';
$phoneCompactExpr = 'REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(c.phone, ""), "+", ""), "-", ""), " ", ""), "(", ""), ")", "")';
$altPhoneCompactExpr = 'REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(c.alt_phone, ""), "+", ""), "-", ""), " ", ""), "(", ""), ")", "")';

$searchCompactRegistration = preg_replace('/[^A-Z0-9]/', '', strtoupper($search));
if (!is_string($searchCompactRegistration)) {
    $searchCompactRegistration = '';
}
$searchDigits = vehicle_search_digits($search);

$searchClauses = [
    'v.registration_no LIKE :search_text',
    'c.phone LIKE :search_text',
    'c.full_name LIKE :search_text',
];
if ($hasAltPhone) {
    $searchClauses[] = 'c.alt_phone LIKE :search_text';
}
if ($searchCompactRegistration !== '') {
    $searchClauses[] = $registrationCompactExpr . ' LIKE :search_compact';
}
if ($searchDigits !== '') {
    $searchClauses[] = $phoneCompactExpr . ' LIKE :search_digits';
    if ($hasAltPhone) {
        $searchClauses[] = $altPhoneCompactExpr . ' LIKE :search_digits';
    }
}

$jobSummarySql =
    'SELECT jc.vehicle_id,
            COUNT(*) AS total_jobs,
            SUM(CASE
                    WHEN jc.status_code = "ACTIVE"
                     AND jc.status NOT IN ("CLOSED", "CANCELLED")
                    THEN 1 ELSE 0
                END) AS open_jobs,
            MAX(COALESCE(jc.closed_at, jc.completed_at, jc.updated_at, jc.opened_at, jc.created_at)) AS last_visit_at
     FROM job_cards jc
     WHERE jc.company_id = :job_company_id
       AND jc.status_code <> "DELETED"
     GROUP BY jc.vehicle_id';

$where = [
    'v.company_id = :company_id',
    'v.status_code <> "DELETED"',
    'c.status_code <> "DELETED"',
    '(' . implode(' OR ', $searchClauses) . ')',
];

$orderByExactPhone = $phoneCompactExpr . ' = :exact_digits';
if ($hasAltPhone) {
    $orderByExactPhone = '(' . $orderByExactPhone . ' OR ' . $altPhoneCompactExpr . ' = :exact_digits)';
}

$sql =
    'SELECT v.id, v.registration_no, v.brand, v.model, v.variant, v.status_code,
            c.full_name AS customer_name, c.phone AS customer_phone' . ($hasAltPhone ? ', c.alt_phone AS customer_alt_phone' : ', "" AS customer_alt_phone') . ',
            COALESCE(js.total_jobs, 0) AS total_jobs,
            COALESCE(js.open_jobs, 0) AS open_jobs,
            js.last_visit_at
     FROM vehicles v
     INNER JOIN customers c ON c.id = v.customer_id
     LEFT JOIN (' . $jobSummarySql . ') js ON js.vehicle_id = v.id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY
       CASE
         WHEN UPPER(TRIM(v.registration_no)) = :exact_registration THEN 0
         WHEN ' . $registrationCompactExpr . ' = :exact_registration_compact THEN 1
         WHEN ' . $orderByExactPhone . ' THEN 2
         ELSE 3
       END ASC,
       COALESCE(js.last_visit_at, v.updated_at, v.created_at) DESC,
       v.id DESC
     LIMIT 12';

$params = [
    'company_id' => $companyId,
    'job_company_id' => $companyId,
    'search_text' => '%' . $search . '%',
    'exact_registration' => strtoupper($search),
    'exact_registration_compact' => $searchCompactRegistration !== '' ? $searchCompactRegistration : '__NONE__',
    'exact_digits' => $searchDigits !== '' ? $searchDigits : '__NONE__',
];

if ($searchCompactRegistration !== '') {
    $params['search_compact'] = '%' . $searchCompactRegistration . '%';
}
if ($searchDigits !== '') {
    $params['search_digits'] = '%' . $searchDigits . '%';
}

try {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $items = [];
    foreach ($rows as $row) {
        $vehicleId = (int) ($row['id'] ?? 0);
        if ($vehicleId <= 0) {
            continue;
        }

        $vehicleLabel = trim(
            trim((string) ($row['brand'] ?? '')) . ' ' .
            trim((string) ($row['model'] ?? '')) . ' ' .
            trim((string) ($row['variant'] ?? ''))
        );

        $items[] = [
            'vehicle_id' => $vehicleId,
            'registration_no' => (string) ($row['registration_no'] ?? ''),
            'vehicle_label' => $vehicleLabel,
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'customer_phone' => (string) ($row['customer_phone'] ?? ''),
            'customer_alt_phone' => (string) ($row['customer_alt_phone'] ?? ''),
            'status_code' => (string) ($row['status_code'] ?? 'ACTIVE'),
            'total_jobs' => (int) ($row['total_jobs'] ?? 0),
            'open_jobs' => (int) ($row['open_jobs'] ?? 0),
            'last_visit_at' => vehicle_search_format_datetime((string) ($row['last_visit_at'] ?? '')),
            'intelligence_url' => url('modules/vehicles/intelligence.php?id=' . $vehicleId),
        ];
    }

    echo json_encode([
        'ok' => true,
        'query' => $search,
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to search vehicles right now.',
    ], JSON_UNESCAPED_UNICODE);
}
