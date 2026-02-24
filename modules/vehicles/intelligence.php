<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('vehicle.view');
require_once __DIR__ . '/../jobs/workflow.php';

$page_title = 'Vehicle Intelligence';
$active_menu = 'vehicles';
$companyId = active_company_id();
$garageId = active_garage_id();
$vehicleId = get_int('id');

if ($vehicleId <= 0) {
    flash_set('vehicle_intel_error', 'Invalid vehicle selected for intelligence view.', 'danger');
    redirect('modules/vehicles/index.php');
}

function intelligence_format_datetime(?string $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '-';
    }

    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        return $raw;
    }

    return date('d M Y, h:i A', $timestamp);
}

function intelligence_job_status_badge(string $status): string
{
    $normalized = strtoupper(trim($status));
    return match ($normalized) {
        'OPEN' => 'secondary',
        'IN_PROGRESS' => 'primary',
        'WAITING_PARTS' => 'warning',
        'READY_FOR_DELIVERY' => 'info',
        'COMPLETED' => 'success',
        'CLOSED' => 'dark',
        'CANCELLED' => 'danger',
        default => 'secondary',
    };
}

function intelligence_priority_badge(string $priority): string
{
    $normalized = strtoupper(trim($priority));
    return match ($normalized) {
        'LOW' => 'secondary',
        'MEDIUM' => 'info',
        'HIGH' => 'warning',
        'URGENT' => 'danger',
        default => 'secondary',
    };
}

function intelligence_short_text(string $value, int $limit = 100): string
{
    $normalized = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    if ($normalized === '') {
        return '-';
    }

    if (mb_strlen($normalized) <= $limit) {
        return $normalized;
    }

    return mb_substr($normalized, 0, max(1, $limit - 3)) . '...';
}

$customerColumns = table_columns('customers');
$hasCustomerAltPhone = in_array('alt_phone', $customerColumns, true);
$hasCustomerType = in_array('customer_type', $customerColumns, true);
$jobCardColumns = table_columns('job_cards');
$jobOdometerEnabled = in_array('odometer_km', $jobCardColumns, true);

$vehicleStmt = db()->prepare(
    'SELECT v.*,
            c.id AS owner_id,
            c.full_name AS owner_name,
            c.phone AS owner_phone,
            ' . ($hasCustomerAltPhone ? 'c.alt_phone' : '""') . ' AS owner_alt_phone,
            c.email AS owner_email,
            c.gstin AS owner_gstin,
            c.address_line1 AS owner_address_line1,
            c.address_line2 AS owner_address_line2,
            c.city AS owner_city,
            c.state AS owner_state,
            c.pincode AS owner_pincode,
            c.status_code AS owner_status_code,
            ' . ($hasCustomerType ? 'c.customer_type' : '""') . ' AS owner_type,
            (SELECT COUNT(*)
             FROM vehicles vx
             WHERE vx.company_id = v.company_id
               AND vx.customer_id = v.customer_id
               AND vx.status_code <> "DELETED") AS owner_vehicle_count,
            vv.variant_name AS vis_variant_name,
            vm.model_name AS vis_model_name,
            vb.brand_name AS vis_brand_name
     FROM vehicles v
     INNER JOIN customers c ON c.id = v.customer_id
     LEFT JOIN vis_variants vv ON vv.id = v.vis_variant_id
     LEFT JOIN vis_models vm ON vm.id = vv.model_id
     LEFT JOIN vis_brands vb ON vb.id = vm.brand_id
     WHERE v.id = :vehicle_id
       AND v.company_id = :company_id
       AND v.status_code <> "DELETED"
     LIMIT 1'
);
$vehicleStmt->execute([
    'vehicle_id' => $vehicleId,
    'company_id' => $companyId,
]);
$vehicle = $vehicleStmt->fetch() ?: null;

if ($vehicle === null) {
    flash_set('vehicle_intel_error', 'Vehicle not found or no longer available.', 'danger');
    redirect('modules/vehicles/index.php');
}

$jobHistory = [];
$jobStats = [
    'total_jobs' => 0,
    'open_jobs' => 0,
    'closed_jobs' => 0,
    'cancelled_jobs' => 0,
    'total_estimate' => 0.0,
];
$serviceUsage = [];
$partsUsage = [];
$repeatedIssues = [];
$odometerTimeline = [];

try {
    $jobHistoryStmt = db()->prepare(
        'SELECT jc.id, jc.job_number, jc.status, jc.status_code, jc.priority, jc.opened_at, jc.completed_at, jc.closed_at,
                jc.complaint, jc.diagnosis, jc.estimated_cost, jc.updated_at,
                ' . ($jobOdometerEnabled ? 'jc.odometer_km' : 'NULL') . ' AS odometer_km,
                g.name AS garage_name,
                inv.invoice_number, inv.grand_total AS invoice_grand_total, inv.payment_status,
                (SELECT COUNT(*) FROM job_labor jl WHERE jl.job_card_id = jc.id) AS service_lines_count,
                (SELECT COUNT(*) FROM job_parts jp WHERE jp.job_card_id = jc.id) AS part_lines_count,
                COALESCE((SELECT SUM(total_amount) FROM job_labor jl WHERE jl.job_card_id = jc.id), 0) AS labor_total,
                COALESCE((SELECT SUM(total_amount) FROM job_parts jp WHERE jp.job_card_id = jc.id), 0) AS parts_total
         FROM job_cards jc
         INNER JOIN garages g ON g.id = jc.garage_id
         LEFT JOIN invoices inv ON inv.job_card_id = jc.id
         WHERE jc.company_id = :company_id
           AND jc.vehicle_id = :vehicle_id
           AND jc.status_code <> "DELETED"
         ORDER BY COALESCE(jc.closed_at, jc.completed_at, jc.opened_at, jc.created_at) DESC, jc.id DESC'
    );
    $jobHistoryStmt->execute([
        'company_id' => $companyId,
        'vehicle_id' => $vehicleId,
    ]);
    $jobHistory = $jobHistoryStmt->fetchAll();

    $jobStatsStmt = db()->prepare(
        'SELECT COUNT(*) AS total_jobs,
                SUM(CASE
                        WHEN jc.status_code = "ACTIVE"
                         AND jc.status NOT IN ("CLOSED", "CANCELLED")
                        THEN 1 ELSE 0
                    END) AS open_jobs,
                SUM(CASE WHEN jc.status = "CLOSED" THEN 1 ELSE 0 END) AS closed_jobs,
                SUM(CASE WHEN jc.status = "CANCELLED" THEN 1 ELSE 0 END) AS cancelled_jobs,
                COALESCE(SUM(jc.estimated_cost), 0) AS total_estimate
         FROM job_cards jc
         WHERE jc.company_id = :company_id
           AND jc.vehicle_id = :vehicle_id
           AND jc.status_code <> "DELETED"'
    );
    $jobStatsStmt->execute([
        'company_id' => $companyId,
        'vehicle_id' => $vehicleId,
    ]);
    $stats = $jobStatsStmt->fetch() ?: [];
    $jobStats = [
        'total_jobs' => (int) ($stats['total_jobs'] ?? 0),
        'open_jobs' => (int) ($stats['open_jobs'] ?? 0),
        'closed_jobs' => (int) ($stats['closed_jobs'] ?? 0),
        'cancelled_jobs' => (int) ($stats['cancelled_jobs'] ?? 0),
        'total_estimate' => (float) ($stats['total_estimate'] ?? 0),
    ];
} catch (Throwable $exception) {
    $jobHistory = [];
}

try {
    $serviceUsageStmt = db()->prepare(
        'SELECT
            CASE
              WHEN s.service_name IS NOT NULL AND TRIM(s.service_name) <> "" THEN s.service_name
              WHEN jl.description IS NOT NULL AND TRIM(jl.description) <> "" THEN jl.description
              ELSE "Unlabeled Service"
            END AS service_label,
            COALESCE(sc.category_name, "Uncategorized") AS category_name,
            COUNT(*) AS line_count,
            SUM(COALESCE(jl.quantity, 0)) AS total_qty,
            SUM(COALESCE(jl.total_amount, 0)) AS total_amount,
            MAX(COALESCE(jl.updated_at, jl.created_at, jc.opened_at, jc.created_at)) AS last_used_at
         FROM job_labor jl
         INNER JOIN job_cards jc ON jc.id = jl.job_card_id
         LEFT JOIN services s ON s.id = jl.service_id
         LEFT JOIN service_categories sc ON sc.id = s.category_id AND sc.company_id = s.company_id
         WHERE jc.company_id = :company_id
           AND jc.vehicle_id = :vehicle_id
           AND jc.status_code <> "DELETED"
         GROUP BY service_label, category_name
         ORDER BY line_count DESC, total_amount DESC, service_label ASC
         LIMIT 30'
    );
    $serviceUsageStmt->execute([
        'company_id' => $companyId,
        'vehicle_id' => $vehicleId,
    ]);
    $serviceUsage = $serviceUsageStmt->fetchAll();
} catch (Throwable $exception) {
    $serviceUsage = [];
}

try {
    $partsUsageStmt = db()->prepare(
        'SELECT p.id AS part_id, p.part_name, p.part_sku,
                COUNT(*) AS line_count,
                SUM(COALESCE(jp.quantity, 0)) AS total_qty,
                SUM(COALESCE(jp.total_amount, 0)) AS total_amount,
                MAX(COALESCE(jp.updated_at, jp.created_at, jc.opened_at, jc.created_at)) AS last_used_at
         FROM job_parts jp
         INNER JOIN job_cards jc ON jc.id = jp.job_card_id
         INNER JOIN parts p ON p.id = jp.part_id
         WHERE jc.company_id = :company_id
           AND jc.vehicle_id = :vehicle_id
           AND jc.status_code <> "DELETED"
         GROUP BY p.id, p.part_name, p.part_sku
         ORDER BY line_count DESC, total_qty DESC, p.part_name ASC
         LIMIT 30'
    );
    $partsUsageStmt->execute([
        'company_id' => $companyId,
        'vehicle_id' => $vehicleId,
    ]);
    $partsUsage = $partsUsageStmt->fetchAll();
} catch (Throwable $exception) {
    $partsUsage = [];
}

try {
    $issueMap = [];

    $issuesStmt = db()->prepare(
        'SELECT LOWER(TRIM(ji.issue_title)) AS issue_key,
                MIN(TRIM(ji.issue_title)) AS issue_label,
                COUNT(*) AS hit_count,
                SUM(CASE WHEN ji.resolved_flag = 0 THEN 1 ELSE 0 END) AS unresolved_count,
                MAX(jc.opened_at) AS last_seen_at
         FROM job_issues ji
         INNER JOIN job_cards jc ON jc.id = ji.job_card_id
         WHERE jc.company_id = :company_id
           AND jc.vehicle_id = :vehicle_id
           AND jc.status_code <> "DELETED"
           AND TRIM(ji.issue_title) <> ""
         GROUP BY LOWER(TRIM(ji.issue_title))
         HAVING COUNT(*) >= 2
         ORDER BY hit_count DESC, last_seen_at DESC
         LIMIT 15'
    );
    $issuesStmt->execute([
        'company_id' => $companyId,
        'vehicle_id' => $vehicleId,
    ]);
    $issueRows = $issuesStmt->fetchAll();

    foreach ($issueRows as $row) {
        $key = trim((string) ($row['issue_key'] ?? ''));
        if ($key === '') {
            continue;
        }

        $issueMap[$key] = [
            'issue_label' => intelligence_short_text((string) ($row['issue_label'] ?? ''), 140),
            'hit_count' => (int) ($row['hit_count'] ?? 0),
            'unresolved_count' => (int) ($row['unresolved_count'] ?? 0),
            'last_seen_at' => (string) ($row['last_seen_at'] ?? ''),
        ];
    }

    $complaintsStmt = db()->prepare(
        'SELECT LOWER(TRIM(jc.complaint)) AS issue_key,
                MIN(TRIM(jc.complaint)) AS issue_label,
                COUNT(*) AS hit_count,
                MAX(jc.opened_at) AS last_seen_at
         FROM job_cards jc
         WHERE jc.company_id = :company_id
           AND jc.vehicle_id = :vehicle_id
           AND jc.status_code <> "DELETED"
           AND TRIM(jc.complaint) <> ""
         GROUP BY LOWER(TRIM(jc.complaint))
         HAVING COUNT(*) >= 2
         ORDER BY hit_count DESC, last_seen_at DESC
         LIMIT 15'
    );
    $complaintsStmt->execute([
        'company_id' => $companyId,
        'vehicle_id' => $vehicleId,
    ]);
    $complaintRows = $complaintsStmt->fetchAll();

    foreach ($complaintRows as $row) {
        $key = trim((string) ($row['issue_key'] ?? ''));
        if ($key === '') {
            continue;
        }

        if (!isset($issueMap[$key])) {
            $issueMap[$key] = [
                'issue_label' => intelligence_short_text((string) ($row['issue_label'] ?? ''), 140),
                'hit_count' => (int) ($row['hit_count'] ?? 0),
                'unresolved_count' => 0,
                'last_seen_at' => (string) ($row['last_seen_at'] ?? ''),
            ];
            continue;
        }

        $issueMap[$key]['hit_count'] += (int) ($row['hit_count'] ?? 0);
        $complaintLastSeen = (string) ($row['last_seen_at'] ?? '');
        if ($complaintLastSeen !== '' && strcmp($complaintLastSeen, (string) ($issueMap[$key]['last_seen_at'] ?? '')) > 0) {
            $issueMap[$key]['last_seen_at'] = $complaintLastSeen;
        }
    }

    $repeatedIssues = array_values($issueMap);
    usort($repeatedIssues, static function (array $left, array $right): int {
        $leftCount = (int) ($left['hit_count'] ?? 0);
        $rightCount = (int) ($right['hit_count'] ?? 0);
        if ($leftCount !== $rightCount) {
            return $leftCount < $rightCount ? 1 : -1;
        }

        return strcmp((string) ($right['last_seen_at'] ?? ''), (string) ($left['last_seen_at'] ?? ''));
    });
    $repeatedIssues = array_slice($repeatedIssues, 0, 12);
} catch (Throwable $exception) {
    $repeatedIssues = [];
}

$latestOdometerReading = null;
$latestOdometerSource = '';
$timeline = [];

if ($jobOdometerEnabled) {
    foreach ($jobHistory as $jobRow) {
        if (!isset($jobRow['odometer_km']) || !is_numeric($jobRow['odometer_km'])) {
            continue;
        }

        $readingKm = (int) round((float) $jobRow['odometer_km']);
        if ($readingKm < 0) {
            continue;
        }

        $capturedAt = trim((string) ($jobRow['opened_at'] ?? ''));
        if ($capturedAt === '') {
            $capturedAt = trim((string) ($jobRow['updated_at'] ?? ''));
        }
        if ($capturedAt === '') {
            $capturedAt = trim((string) ($jobRow['closed_at'] ?? ''));
        }
        if ($capturedAt === '') {
            $capturedAt = trim((string) ($jobRow['completed_at'] ?? ''));
        }

        $jobNumber = trim((string) ($jobRow['job_number'] ?? ''));
        if ($jobNumber === '') {
            $jobNumber = '#' . (string) ($jobRow['id'] ?? '');
        }

        $timeline[] = [
            'captured_at' => $capturedAt,
            'reading_km' => $readingKm,
            'source' => 'Job ' . $jobNumber . ' (' . (string) ($jobRow['status'] ?? 'OPEN') . ')',
        ];
    }
}

if (empty($timeline)) {
    $legacyReading = (int) ($vehicle['odometer_km'] ?? 0);
    if ($legacyReading > 0) {
        $timeline[] = [
            'captured_at' => (string) ($vehicle['updated_at'] ?? $vehicle['created_at'] ?? ''),
            'reading_km' => $legacyReading,
            'source' => 'Legacy Vehicle Master',
        ];
    }
}

usort($timeline, static function (array $left, array $right): int {
    $compare = strcmp((string) ($left['captured_at'] ?? ''), (string) ($right['captured_at'] ?? ''));
    if ($compare !== 0) {
        return $compare;
    }

    return strcmp((string) ($left['source'] ?? ''), (string) ($right['source'] ?? ''));
});

$deduped = [];
foreach ($timeline as $entry) {
    $key = (string) ($entry['captured_at'] ?? '') . '|' . (string) ($entry['reading_km'] ?? '') . '|' . (string) ($entry['source'] ?? '');
    $deduped[$key] = $entry;
}

$odometerTimeline = array_values($deduped);
$previousReading = null;
foreach ($odometerTimeline as $index => $entry) {
    $currentReading = (int) ($entry['reading_km'] ?? 0);
    $odometerTimeline[$index]['delta_km'] = $previousReading === null ? null : $currentReading - $previousReading;
    $previousReading = $currentReading;
}

if (!empty($odometerTimeline)) {
    $lastEntry = $odometerTimeline[count($odometerTimeline) - 1];
    $latestOdometerReading = (int) ($lastEntry['reading_km'] ?? 0);
    $latestOdometerSource = (string) ($lastEntry['source'] ?? '');
}

$visSuggestions = job_fetch_vis_suggestions($companyId, $garageId, $vehicleId);
$visVariant = is_array($visSuggestions['vehicle_variant'] ?? null) ? $visSuggestions['vehicle_variant'] : null;
$visServiceSuggestions = is_array($visSuggestions['service_suggestions'] ?? null) ? $visSuggestions['service_suggestions'] : [];
$visCompatibleParts = is_array($visSuggestions['part_suggestions'] ?? null) ? $visSuggestions['part_suggestions'] : [];

usort($visCompatibleParts, static function (array $left, array $right): int {
    $leftStock = (float) ($left['stock_qty'] ?? 0);
    $rightStock = (float) ($right['stock_qty'] ?? 0);
    $leftInStock = $leftStock > 0;
    $rightInStock = $rightStock > 0;

    if ($leftInStock !== $rightInStock) {
        return $leftInStock ? -1 : 1;
    }
    if ($leftStock !== $rightStock) {
        return $leftStock < $rightStock ? 1 : -1;
    }

    return strcasecmp((string) ($left['part_name'] ?? ''), (string) ($right['part_name'] ?? ''));
});

$visPartCount = count($visCompatibleParts);
$visInStockCount = 0;
foreach ($visCompatibleParts as $part) {
    if ((float) ($part['stock_qty'] ?? 0) > 0) {
        $visInStockCount++;
    }
}

$serviceUsageTotal = 0.0;
foreach ($serviceUsage as $row) {
    $serviceUsageTotal += (float) ($row['total_amount'] ?? 0);
}

$partsUsageTotal = 0.0;
foreach ($partsUsage as $row) {
    $partsUsageTotal += (float) ($row['total_amount'] ?? 0);
}

$activeServiceReminders = service_reminder_feature_ready()
    ? service_reminder_fetch_active_by_vehicle($companyId, $vehicleId, 0, 25)
    : [];
$serviceReminderSummary = service_reminder_summary_counts($activeServiceReminders);
$predictedNextVisitDate = null;
foreach ($activeServiceReminders as $reminder) {
    $candidate = service_reminder_parse_date((string) ($reminder['predicted_next_visit_date'] ?? ''));
    if ($candidate === null) {
        $candidate = service_reminder_parse_date((string) ($reminder['next_due_date'] ?? ''));
    }
    if ($candidate === null) {
        continue;
    }
    if ($predictedNextVisitDate === null || strcmp($candidate, $predictedNextVisitDate) < 0) {
        $predictedNextVisitDate = $candidate;
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-8">
          <h3 class="mb-0">Vehicle Intelligence</h3>
          <div class="text-muted">Vehicle #<?= (int) $vehicle['id']; ?> | <?= e((string) ($vehicle['registration_no'] ?? '')); ?></div>
        </div>
        <div class="col-sm-4">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/vehicles/index.php')); ?>">Vehicle Master</a></li>
            <li class="breadcrumb-item active">Intelligence</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="row g-3 mb-3">
        <div class="col-md-3 col-sm-6">
          <div class="small-box text-bg-primary mb-0">
            <div class="inner">
              <h3><?= (int) $jobStats['total_jobs']; ?></h3>
              <p>Total Jobs</p>
            </div>
            <div class="small-box-icon"><i class="bi bi-card-checklist"></i></div>
          </div>
        </div>
        <div class="col-md-3 col-sm-6">
          <div class="small-box text-bg-warning mb-0">
            <div class="inner">
              <h3><?= (int) $jobStats['open_jobs']; ?></h3>
              <p>Open Jobs</p>
            </div>
            <div class="small-box-icon"><i class="bi bi-tools"></i></div>
          </div>
        </div>
        <div class="col-md-3 col-sm-6">
          <div class="small-box text-bg-danger mb-0">
            <div class="inner">
              <h3><?= count($repeatedIssues); ?></h3>
              <p>Repeated Issues</p>
            </div>
            <div class="small-box-icon"><i class="bi bi-exclamation-diamond"></i></div>
          </div>
        </div>
        <div class="col-md-3 col-sm-6">
          <div class="small-box text-bg-success mb-0">
            <div class="inner">
              <h3><?= $visInStockCount; ?>/<?= $visPartCount; ?></h3>
              <p>VIS Parts In Stock</p>
            </div>
            <div class="small-box-icon"><i class="bi bi-cpu"></i></div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-7">
          <div class="card h-100">
            <div class="card-header">
              <h3 class="card-title mb-0">Vehicle Details</h3>
            </div>
            <div class="card-body">
              <div class="row g-2">
                <div class="col-md-4">
                  <div class="text-muted small">Registration</div>
                  <div class="fw-semibold"><?= e((string) ($vehicle['registration_no'] ?? '-')); ?></div>
                </div>
                <div class="col-md-4">
                  <div class="text-muted small">Vehicle Type</div>
                  <div class="fw-semibold"><?= e((string) ($vehicle['vehicle_type'] ?? '-')); ?></div>
                </div>
                <div class="col-md-4">
                  <div class="text-muted small">Fuel Type</div>
                  <div class="fw-semibold"><?= e((string) ($vehicle['fuel_type'] ?? '-')); ?></div>
                </div>
                <div class="col-md-4">
                  <div class="text-muted small">Brand / Model / Variant</div>
                  <div class="fw-semibold">
                    <?= e((string) ($vehicle['brand'] ?? '-')); ?>
                    <?= e((string) ($vehicle['model'] ?? '-')); ?>
                    <?= e((string) ($vehicle['variant'] ?? '')); ?>
                  </div>
                </div>
                <div class="col-md-2">
                  <div class="text-muted small">Model Year</div>
                  <div class="fw-semibold"><?= e((string) ($vehicle['model_year'] ?? '-')); ?></div>
                </div>
                <div class="col-md-2">
                  <div class="text-muted small">Color</div>
                  <div class="fw-semibold"><?= e((string) ($vehicle['color'] ?? '-')); ?></div>
                </div>
                <div class="col-md-4">
                  <div class="text-muted small">Status</div>
                  <div><span class="badge text-bg-<?= e(status_badge_class((string) ($vehicle['status_code'] ?? 'ACTIVE'))); ?>"><?= e(record_status_label((string) ($vehicle['status_code'] ?? 'ACTIVE'))); ?></span></div>
                </div>
                <div class="col-md-4">
                  <div class="text-muted small">Latest Odometer</div>
                  <?php if ($latestOdometerReading !== null): ?>
                    <div class="fw-semibold"><?= e(number_format((float) $latestOdometerReading, 0)); ?> KM</div>
                    <div class="small text-muted"><?= e($latestOdometerSource !== '' ? $latestOdometerSource : 'Job Card'); ?></div>
                  <?php else: ?>
                    <div class="text-muted">No odometer reading captured yet.</div>
                  <?php endif; ?>
                </div>
                <div class="col-md-4">
                  <div class="text-muted small">Predicted Next Visit</div>
                  <?php if ($predictedNextVisitDate !== null): ?>
                    <div class="fw-semibold"><?= e($predictedNextVisitDate); ?></div>
                    <div class="small text-muted">Based on average usage and active maintenance reminders</div>
                  <?php else: ?>
                    <div class="text-muted">Insufficient reminder/usage data.</div>
                  <?php endif; ?>
                </div>
                <div class="col-md-4">
                  <div class="text-muted small">Chassis No</div>
                  <div class="fw-semibold"><?= e((string) ($vehicle['chassis_no'] ?? '-')); ?></div>
                </div>
                <div class="col-md-4">
                  <div class="text-muted small">Engine No</div>
                  <div class="fw-semibold"><?= e((string) ($vehicle['engine_no'] ?? '-')); ?></div>
                </div>
                <div class="col-md-8">
                  <div class="text-muted small">VIS Mapping</div>
                  <?php if ($visVariant !== null): ?>
                    <div class="fw-semibold">
                      <?= e((string) ($visVariant['brand_name'] ?? '-')); ?> /
                      <?= e((string) ($visVariant['model_name'] ?? '-')); ?> /
                      <?= e((string) ($visVariant['variant_name'] ?? '-')); ?>
                    </div>
                  <?php elseif ((int) ($vehicle['vis_variant_id'] ?? 0) > 0): ?>
                    <div class="fw-semibold">
                      <?= e((string) ($vehicle['vis_brand_name'] ?? '-')); ?> /
                      <?= e((string) ($vehicle['vis_model_name'] ?? '-')); ?> /
                      <?= e((string) ($vehicle['vis_variant_name'] ?? '-')); ?>
                    </div>
                  <?php else: ?>
                    <div class="text-muted">No VIS variant linked.</div>
                  <?php endif; ?>
                </div>
                <?php if (trim((string) ($vehicle['notes'] ?? '')) !== ''): ?>
                  <div class="col-12">
                    <div class="text-muted small">Notes</div>
                    <div><?= e((string) $vehicle['notes']); ?></div>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-5">
          <div class="card h-100">
            <div class="card-header">
              <h3 class="card-title mb-0">Owner Details</h3>
            </div>
            <div class="card-body">
              <div class="row g-2">
                <div class="col-md-7">
                  <div class="text-muted small">Owner Name</div>
                  <div class="fw-semibold"><?= e((string) ($vehicle['owner_name'] ?? '-')); ?></div>
                </div>
                <div class="col-md-5">
                  <div class="text-muted small">Owner Status</div>
                  <div><span class="badge text-bg-<?= e(status_badge_class((string) ($vehicle['owner_status_code'] ?? 'ACTIVE'))); ?>"><?= e(record_status_label((string) ($vehicle['owner_status_code'] ?? 'ACTIVE'))); ?></span></div>
                </div>
                <div class="col-md-6">
                  <div class="text-muted small">Mobile</div>
                  <div class="fw-semibold"><?= e((string) ($vehicle['owner_phone'] ?? '-')); ?></div>
                </div>
                <div class="col-md-6">
                  <div class="text-muted small">Alternate Mobile</div>
                  <div class="fw-semibold"><?= e((string) ($vehicle['owner_alt_phone'] ?? '-')); ?></div>
                </div>
                <div class="col-md-6">
                  <div class="text-muted small">Email</div>
                  <div class="fw-semibold"><?= e((string) ($vehicle['owner_email'] ?? '-')); ?></div>
                </div>
                <div class="col-md-6">
                  <div class="text-muted small">Customer Type</div>
                  <div class="fw-semibold"><?= e((string) ($vehicle['owner_type'] ?? '-')); ?></div>
                </div>
                <div class="col-md-6">
                  <div class="text-muted small">GSTIN</div>
                  <div class="fw-semibold"><?= e((string) ($vehicle['owner_gstin'] ?? '-')); ?></div>
                </div>
                <div class="col-md-6">
                  <div class="text-muted small">Total Owner Vehicles</div>
                  <div class="fw-semibold"><?= (int) ($vehicle['owner_vehicle_count'] ?? 0); ?></div>
                </div>
                <div class="col-12">
                  <div class="text-muted small">Address</div>
                  <div>
                    <?= e(trim((string) ($vehicle['owner_address_line1'] ?? '')) !== '' ? (string) $vehicle['owner_address_line1'] : '-'); ?>
                    <?php if (trim((string) ($vehicle['owner_address_line2'] ?? '')) !== ''): ?>
                      , <?= e((string) $vehicle['owner_address_line2']); ?>
                    <?php endif; ?>
                    <?php if (trim((string) ($vehicle['owner_city'] ?? '')) !== ''): ?>
                      , <?= e((string) $vehicle['owner_city']); ?>
                    <?php endif; ?>
                    <?php if (trim((string) ($vehicle['owner_state'] ?? '')) !== ''): ?>
                      , <?= e((string) $vehicle['owner_state']); ?>
                    <?php endif; ?>
                    <?php if (trim((string) ($vehicle['owner_pincode'] ?? '')) !== ''): ?>
                      - <?= e((string) $vehicle['owner_pincode']); ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">Maintenance Reminders And Next Visit Prediction</h3>
          <span class="badge text-bg-light border"><?= (int) ($serviceReminderSummary['total'] ?? 0); ?> Active</span>
        </div>
        <div class="card-body">
          <div class="row g-2 mb-3">
            <div class="col-6 col-md-3">
              <div class="text-muted small">Overdue</div>
              <div class="fw-semibold text-danger"><?= (int) ($serviceReminderSummary['overdue'] ?? 0); ?></div>
            </div>
            <div class="col-6 col-md-3">
              <div class="text-muted small">Due</div>
              <div class="fw-semibold text-warning"><?= (int) (($serviceReminderSummary['due'] ?? 0) + ($serviceReminderSummary['due_soon'] ?? 0)); ?></div>
            </div>
            <div class="col-6 col-md-3">
              <div class="text-muted small">Upcoming</div>
              <div class="fw-semibold text-info"><?= (int) ($serviceReminderSummary['upcoming'] ?? 0); ?></div>
            </div>
            <div class="col-6 col-md-3">
              <div class="text-muted small">Predicted Next Visit</div>
              <div class="fw-semibold"><?= e((string) ($predictedNextVisitDate ?? '-')); ?></div>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
              <thead>
                <tr>
                  <th>Service/Part</th>
                  <th class="text-end">Due KM</th>
                  <th>Due Date</th>
                  <th>Predicted Visit</th>
                  <th>Status</th>
                  <th>Recommendation</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($activeServiceReminders)): ?>
                  <tr><td colspan="6" class="text-center text-muted py-3">No active reminders for this vehicle.</td></tr>
                <?php else: ?>
                  <?php foreach ($activeServiceReminders as $reminder): ?>
                    <tr>
                      <td><?= e((string) ($reminder['service_label'] ?? service_reminder_type_label((string) ($reminder['service_type'] ?? '')))); ?></td>
                      <td class="text-end"><?= isset($reminder['next_due_km']) && $reminder['next_due_km'] !== null ? e(number_format((float) $reminder['next_due_km'], 0)) : '-'; ?></td>
                      <td><?= e((string) (($reminder['next_due_date'] ?? '') !== '' ? $reminder['next_due_date'] : '-')); ?></td>
                      <td><?= e((string) (($reminder['predicted_next_visit_date'] ?? '') !== '' ? $reminder['predicted_next_visit_date'] : '-')); ?></td>
                      <td><span class="badge text-bg-<?= e(service_reminder_due_badge_class((string) ($reminder['due_state'] ?? 'UNSCHEDULED'))); ?>"><?= e((string) ($reminder['due_state'] ?? 'UNSCHEDULED')); ?></span></td>
                      <td class="small"><?= e((string) ($reminder['recommendation_text'] ?? '-')); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">Complete Job History</h3>
          <span class="badge text-bg-light border"><?= (int) $jobStats['total_jobs']; ?> Jobs</span>
        </div>
        <div class="card-body p-0 table-responsive">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>Job #</th>
                <th>Garage</th>
                <th>Opened</th>
                <th>Odometer</th>
                <th>Status</th>
                <th>Priority</th>
                <th>Complaint</th>
                <th>Services</th>
                <th>Parts</th>
                <th>Estimate</th>
                <th>Invoice</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($jobHistory)): ?>
                <tr><td colspan="11" class="text-center text-muted py-4">No jobs found for this vehicle.</td></tr>
              <?php else: ?>
                <?php foreach ($jobHistory as $job): ?>
                  <tr>
                    <td class="fw-semibold"><?= e((string) ($job['job_number'] ?? '-')); ?></td>
                    <td><?= e((string) ($job['garage_name'] ?? '-')); ?></td>
                    <td>
                      <?= e(intelligence_format_datetime((string) ($job['opened_at'] ?? ''))); ?><br>
                      <small class="text-muted">Updated: <?= e(intelligence_format_datetime((string) ($job['updated_at'] ?? ''))); ?></small>
                    </td>
                    <td>
                      <?php if (isset($job['odometer_km']) && $job['odometer_km'] !== null && $job['odometer_km'] !== ''): ?>
                        <?= e(number_format((float) $job['odometer_km'], 0)); ?> KM
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="badge text-bg-<?= e(intelligence_job_status_badge((string) ($job['status'] ?? 'OPEN'))); ?>"><?= e((string) ($job['status'] ?? 'OPEN')); ?></span>
                      <?php if ((string) ($job['status_code'] ?? 'ACTIVE') !== 'ACTIVE'): ?>
                        <span class="badge text-bg-<?= e(status_badge_class((string) ($job['status_code'] ?? 'INACTIVE'))); ?>"><?= e((string) ($job['status_code'] ?? 'INACTIVE')); ?></span>
                      <?php endif; ?>
                    </td>
                    <td><span class="badge text-bg-<?= e(intelligence_priority_badge((string) ($job['priority'] ?? 'MEDIUM'))); ?>"><?= e((string) ($job['priority'] ?? 'MEDIUM')); ?></span></td>
                    <td>
                      <?= e(intelligence_short_text((string) ($job['complaint'] ?? '-'), 90)); ?><br>
                      <small class="text-muted"><?= e(intelligence_short_text((string) ($job['diagnosis'] ?? '-'), 90)); ?></small>
                    </td>
                    <td>
                      <?= (int) ($job['service_lines_count'] ?? 0); ?> lines<br>
                      <small class="text-muted"><?= e(format_currency((float) ($job['labor_total'] ?? 0))); ?></small>
                    </td>
                    <td>
                      <?= (int) ($job['part_lines_count'] ?? 0); ?> lines<br>
                      <small class="text-muted"><?= e(format_currency((float) ($job['parts_total'] ?? 0))); ?></small>
                    </td>
                    <td><?= e(format_currency((float) ($job['estimated_cost'] ?? 0))); ?></td>
                    <td>
                      <?php if (trim((string) ($job['invoice_number'] ?? '')) !== ''): ?>
                        <?= e((string) $job['invoice_number']); ?><br>
                        <small class="text-muted"><?= e(format_currency((float) ($job['invoice_grand_total'] ?? 0))); ?> | <?= e((string) ($job['payment_status'] ?? '')); ?></small>
                      <?php else: ?>
                        <span class="text-muted">Not billed</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Services Used</h3>
              <span class="badge text-bg-light border"><?= e(format_currency($serviceUsageTotal)); ?></span>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead>
                  <tr>
                    <th>Service</th>
                    <th>Category</th>
                    <th>Count</th>
                    <th>Qty</th>
                    <th>Total</th>
                    <th>Last Used</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($serviceUsage)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No service usage data available.</td></tr>
                  <?php else: ?>
                    <?php foreach ($serviceUsage as $service): ?>
                      <tr>
                        <td><?= e((string) ($service['service_label'] ?? '-')); ?></td>
                        <td><?= e((string) ($service['category_name'] ?? '-')); ?></td>
                        <td><?= (int) ($service['line_count'] ?? 0); ?></td>
                        <td><?= e(number_format((float) ($service['total_qty'] ?? 0), 2)); ?></td>
                        <td><?= e(format_currency((float) ($service['total_amount'] ?? 0))); ?></td>
                        <td><?= e(intelligence_format_datetime((string) ($service['last_used_at'] ?? ''))); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Parts Used</h3>
              <span class="badge text-bg-light border"><?= e(format_currency($partsUsageTotal)); ?></span>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead>
                  <tr>
                    <th>Part</th>
                    <th>SKU</th>
                    <th>Count</th>
                    <th>Qty</th>
                    <th>Total</th>
                    <th>Last Used</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($partsUsage)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No part usage data available.</td></tr>
                  <?php else: ?>
                    <?php foreach ($partsUsage as $part): ?>
                      <tr>
                        <td><?= e((string) ($part['part_name'] ?? '-')); ?></td>
                        <td><?= e((string) ($part['part_sku'] ?? '-')); ?></td>
                        <td><?= (int) ($part['line_count'] ?? 0); ?></td>
                        <td><?= e(number_format((float) ($part['total_qty'] ?? 0), 2)); ?></td>
                        <td><?= e(format_currency((float) ($part['total_amount'] ?? 0))); ?></td>
                        <td><?= e(intelligence_format_datetime((string) ($part['last_used_at'] ?? ''))); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-5">
          <div class="card h-100">
            <div class="card-header">
              <h3 class="card-title mb-0">Odometer Readings</h3>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead>
                  <tr>
                    <th>Captured</th>
                    <th>Source</th>
                    <th>Reading (KM)</th>
                    <th>Delta</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($odometerTimeline)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No odometer readings captured yet.</td></tr>
                  <?php else: ?>
                    <?php foreach ($odometerTimeline as $reading): ?>
                      <?php
                        $delta = $reading['delta_km'];
                        $deltaClass = 'text-muted';
                        if ($delta !== null) {
                            $deltaValue = (int) $delta;
                            if ($deltaValue > 0) {
                                $deltaClass = 'text-success';
                            } elseif ($deltaValue < 0) {
                                $deltaClass = 'text-danger';
                            }
                        }
                      ?>
                      <tr>
                        <td><?= e(intelligence_format_datetime((string) ($reading['captured_at'] ?? ''))); ?></td>
                        <td><?= e((string) ($reading['source'] ?? '-')); ?></td>
                        <td><?= e(number_format((float) ($reading['reading_km'] ?? 0), 0)); ?></td>
                        <td class="<?= e($deltaClass); ?>">
                          <?php if ($delta === null): ?>
                            -
                          <?php else: ?>
                            <?= ((int) $delta >= 0 ? '+' : ''); ?><?= e(number_format((float) $delta, 0)); ?>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-lg-7">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Repeated Issues</h3>
              <span class="badge text-bg-light border"><?= count($repeatedIssues); ?> Patterns</span>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead>
                  <tr>
                    <th>Issue Pattern</th>
                    <th>Occurrences</th>
                    <th>Unresolved</th>
                    <th>Last Seen</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($repeatedIssues)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No repeated issues detected yet.</td></tr>
                  <?php else: ?>
                    <?php foreach ($repeatedIssues as $issue): ?>
                      <tr>
                        <td><?= e((string) ($issue['issue_label'] ?? '-')); ?></td>
                        <td><span class="badge text-bg-danger"><?= (int) ($issue['hit_count'] ?? 0); ?>x</span></td>
                        <td>
                          <?php if ((int) ($issue['unresolved_count'] ?? 0) > 0): ?>
                            <span class="badge text-bg-warning"><?= (int) ($issue['unresolved_count'] ?? 0); ?></span>
                          <?php else: ?>
                            <span class="text-muted">0</span>
                          <?php endif; ?>
                        </td>
                        <td><?= e(intelligence_format_datetime((string) ($issue['last_seen_at'] ?? ''))); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0">VIS-Compatible Parts (Active Garage Stock)</h3>
          <span class="badge text-bg-light border"><?= $visInStockCount; ?> in stock / <?= $visPartCount; ?> compatible</span>
        </div>
        <div class="card-body">
          <?php if ($visVariant === null): ?>
            <div class="alert alert-info mb-3">
              This vehicle is not linked to a valid VIS variant. Link a VIS variant in Vehicle Master to unlock compatibility intelligence.
            </div>
          <?php else: ?>
            <div class="mb-3">
              <strong>Variant:</strong>
              <?= e((string) ($visVariant['brand_name'] ?? '-')); ?> /
              <?= e((string) ($visVariant['model_name'] ?? '-')); ?> /
              <?= e((string) ($visVariant['variant_name'] ?? '-')); ?>
            </div>
          <?php endif; ?>

          <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
              <thead>
                <tr>
                  <th>Part</th>
                  <th>SKU</th>
                  <th>Compatibility Note</th>
                  <th>Stock Qty</th>
                  <th>Selling Price</th>
                  <th>GST%</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($visCompatibleParts)): ?>
                  <tr><td colspan="6" class="text-center text-muted py-4">No compatible parts found for this vehicle variant.</td></tr>
                <?php else: ?>
                  <?php foreach ($visCompatibleParts as $part): ?>
                    <?php $stockQty = (float) ($part['stock_qty'] ?? 0); ?>
                    <tr>
                      <td><?= e((string) ($part['part_name'] ?? '-')); ?></td>
                      <td><?= e((string) ($part['part_sku'] ?? '-')); ?></td>
                      <td><?= e((string) ($part['compatibility_note'] ?? '-')); ?></td>
                      <td>
                        <?php if ($stockQty > 0): ?>
                          <span class="badge text-bg-success"><?= e(number_format($stockQty, 2)); ?></span>
                        <?php else: ?>
                          <span class="badge text-bg-secondary">0.00</span>
                        <?php endif; ?>
                      </td>
                      <td><?= e(format_currency((float) ($part['selling_price'] ?? 0))); ?></td>
                      <td><?= e(number_format((float) ($part['gst_rate'] ?? 0), 2)); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="mt-3">
            <h6 class="mb-2">VIS Service Suggestions</h6>
            <?php if (empty($visServiceSuggestions)): ?>
              <div class="text-muted small">No VIS service mappings found.</div>
            <?php else: ?>
              <div class="d-flex flex-wrap gap-2">
                <?php foreach ($visServiceSuggestions as $service): ?>
                  <span class="badge text-bg-info">
                    <?= e((string) ($service['service_name'] ?? 'Service')); ?>
                    (<?= (int) ($service['mapped_parts'] ?? 0); ?> mapped parts)
                  </span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
