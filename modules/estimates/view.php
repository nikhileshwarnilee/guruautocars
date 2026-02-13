<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('estimate.view');
require_once __DIR__ . '/workflow.php';

$estimateTablesReady = table_columns('estimates') !== []
    && table_columns('estimate_counters') !== []
    && table_columns('estimate_services') !== []
    && table_columns('estimate_parts') !== []
    && table_columns('estimate_history') !== [];
if (!$estimateTablesReady) {
    flash_set('estimate_error', 'Estimate module database upgrade is pending. Run database/estimate_module_upgrade.sql.', 'danger');
    redirect('dashboard.php');
}

$page_title = 'Estimate Details';
$active_menu = 'estimates';
$companyId = active_company_id();
$garageId = active_garage_id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$canEdit = has_permission('estimate.edit') || has_permission('estimate.manage');
$canApprove = has_permission('estimate.approve') || has_permission('estimate.manage');
$canReject = has_permission('estimate.reject') || has_permission('estimate.manage');
$canConvert = has_permission('estimate.convert') || has_permission('estimate.manage');
$canPrint = has_permission('estimate.print') || has_permission('estimate.manage') || has_permission('estimate.view');

function estimate_post_decimal(string $key, float $default = 0.0): float
{
    $raw = trim((string) ($_POST[$key] ?? ''));
    if ($raw === '') {
        return $default;
    }

    $normalized = str_replace([',', ' '], '', $raw);
    if (!is_numeric($normalized)) {
        return $default;
    }

    return (float) $normalized;
}

function estimate_post_date(string $key): ?string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    if ($value === '') {
        return null;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $value));
    if (!checkdate($month, $day, $year)) {
        return null;
    }

    return $value;
}

function estimate_fetch_details(int $estimateId, int $companyId, int $garageId): ?array
{
    $stmt = db()->prepare(
        'SELECT e.*,
                c.full_name AS customer_name, c.phone AS customer_phone,
                v.registration_no, v.brand, v.model, v.variant, v.fuel_type,
                g.name AS garage_name,
                j.job_number AS converted_job_number
         FROM estimates e
         INNER JOIN customers c ON c.id = e.customer_id
         INNER JOIN vehicles v ON v.id = e.vehicle_id
         INNER JOIN garages g ON g.id = e.garage_id
         LEFT JOIN job_cards j ON j.id = e.converted_job_card_id
         WHERE e.id = :estimate_id
           AND e.company_id = :company_id
           AND e.garage_id = :garage_id
         LIMIT 1'
    );
    $stmt->execute([
        'estimate_id' => $estimateId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function estimate_fetch_services(int $estimateId): array
{
    $stmt = db()->prepare(
        'SELECT es.*, s.service_name, s.service_code, sc.category_name
         FROM estimate_services es
         LEFT JOIN services s ON s.id = es.service_id
         LEFT JOIN service_categories sc ON sc.id = s.category_id AND sc.company_id = s.company_id
         WHERE es.estimate_id = :estimate_id
         ORDER BY es.id DESC'
    );
    $stmt->execute(['estimate_id' => $estimateId]);
    return $stmt->fetchAll();
}

function estimate_fetch_parts(int $estimateId, int $garageId): array
{
    $stmt = db()->prepare(
        'SELECT ep.*, p.part_name, p.part_sku, COALESCE(gi.quantity, 0) AS stock_qty
         FROM estimate_parts ep
         INNER JOIN parts p ON p.id = ep.part_id
         LEFT JOIN garage_inventory gi ON gi.part_id = ep.part_id AND gi.garage_id = :garage_id
         WHERE ep.estimate_id = :estimate_id
         ORDER BY ep.id DESC'
    );
    $stmt->execute([
        'estimate_id' => $estimateId,
        'garage_id' => $garageId,
    ]);
    return $stmt->fetchAll();
}

function estimate_fetch_service_master(int $companyId): array
{
    $stmt = db()->prepare(
        'SELECT s.id, s.service_code, s.service_name, s.default_rate, s.gst_rate, s.category_id, sc.category_name
         FROM services s
         LEFT JOIN service_categories sc ON sc.id = s.category_id AND sc.company_id = s.company_id
         WHERE s.company_id = :company_id
           AND s.status_code = "ACTIVE"
         ORDER BY
           CASE WHEN s.category_id IS NULL THEN 1 ELSE 0 END,
           COALESCE(sc.category_name, "Uncategorized"),
           s.service_name ASC'
    );
    $stmt->execute(['company_id' => $companyId]);
    return $stmt->fetchAll();
}

function estimate_fetch_part_master(int $companyId, int $garageId): array
{
    $stmt = db()->prepare(
        'SELECT p.id, p.part_name, p.part_sku, p.selling_price, p.gst_rate, COALESCE(gi.quantity, 0) AS stock_qty
         FROM parts p
         LEFT JOIN garage_inventory gi ON gi.part_id = p.id AND gi.garage_id = :garage_id
         WHERE p.company_id = :company_id
           AND p.status_code = "ACTIVE"
         ORDER BY p.part_name ASC'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
    return $stmt->fetchAll();
}

function estimate_fetch_history(int $estimateId): array
{
    $stmt = db()->prepare(
        'SELECT eh.*, u.name AS actor_name
         FROM estimate_history eh
         LEFT JOIN users u ON u.id = eh.created_by
         WHERE eh.estimate_id = :estimate_id
         ORDER BY eh.id DESC
         LIMIT 100'
    );
    $stmt->execute(['estimate_id' => $estimateId]);
    return $stmt->fetchAll();
}

$estimateId = get_int('id');
if ($estimateId <= 0) {
    flash_set('estimate_error', 'Invalid estimate id.', 'danger');
    redirect('modules/estimates/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['_action'] ?? '');

    $estimateForWrite = estimate_fetch_row($estimateId, $companyId, $garageId);
    if (!$estimateForWrite) {
        flash_set('estimate_error', 'Estimate not found for active garage.', 'danger');
        redirect('modules/estimates/index.php');
    }

    $editable = estimate_is_editable($estimateForWrite);

    if ($action === 'update_meta') {
        if (!$canEdit) {
            flash_set('estimate_error', 'You do not have permission to edit estimates.', 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }
        if (!$editable) {
            flash_set('estimate_error', 'Only draft estimates can be edited.', 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }

        $customerId = post_int('customer_id');
        $vehicleId = post_int('vehicle_id');
        $complaint = post_string('complaint', 3000);
        $notes = post_string('notes', 3000);
        $validUntil = estimate_post_date('valid_until');

        if ($customerId <= 0 || $vehicleId <= 0 || $complaint === '') {
            flash_set('estimate_error', 'Customer, vehicle and complaint are required.', 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }

        $ownershipCheck = db()->prepare(
            'SELECT COUNT(*)
             FROM vehicles v
             INNER JOIN customers c ON c.id = v.customer_id
             WHERE v.id = :vehicle_id
               AND c.id = :customer_id
               AND v.company_id = :company_id
               AND c.company_id = :company_id
               AND v.status_code = "ACTIVE"
               AND c.status_code = "ACTIVE"'
        );
        $ownershipCheck->execute([
            'vehicle_id' => $vehicleId,
            'customer_id' => $customerId,
            'company_id' => $companyId,
        ]);
        if ((int) $ownershipCheck->fetchColumn() === 0) {
            flash_set('estimate_error', 'Vehicle must belong to selected active customer.', 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }

        $updateStmt = db()->prepare(
            'UPDATE estimates
             SET customer_id = :customer_id,
                 vehicle_id = :vehicle_id,
                 complaint = :complaint,
                 notes = :notes,
                 valid_until = :valid_until,
                 updated_by = :updated_by
             WHERE id = :id
               AND company_id = :company_id
               AND garage_id = :garage_id'
        );
        $updateStmt->execute([
            'customer_id' => $customerId,
            'vehicle_id' => $vehicleId,
            'complaint' => $complaint,
            'notes' => $notes !== '' ? $notes : null,
            'valid_until' => $validUntil,
            'updated_by' => $userId > 0 ? $userId : null,
            'id' => $estimateId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);

        estimate_append_history($estimateId, 'UPDATE_META', 'DRAFT', 'DRAFT', 'Estimate details updated');
        log_audit('estimates', 'update', $estimateId, 'Updated estimate details');

        flash_set('estimate_success', 'Estimate details updated.', 'success');
        redirect('modules/estimates/view.php?id=' . $estimateId);
    }

    if ($action === 'transition_status') {
        $nextStatus = estimate_normalize_status((string) ($_POST['next_status'] ?? 'DRAFT'));
        $statusNote = post_string('status_note', 255);
        $currentStatus = estimate_normalize_status((string) ($estimateForWrite['estimate_status'] ?? 'DRAFT'));

        if ($nextStatus === 'CONVERTED') {
            flash_set('estimate_error', 'Use convert action to mark estimate as converted.', 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }

        if (!estimate_can_transition($currentStatus, $nextStatus)) {
            flash_set('estimate_error', 'Invalid status transition from ' . $currentStatus . ' to ' . $nextStatus . '.', 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }

        if ($nextStatus === 'APPROVED' && !$canApprove) {
            flash_set('estimate_error', 'You do not have permission to approve estimates.', 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }
        if ($nextStatus === 'REJECTED' && !$canReject) {
            flash_set('estimate_error', 'You do not have permission to reject estimates.', 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }
        if ($nextStatus === 'DRAFT' && !$canEdit) {
            flash_set('estimate_error', 'You do not have permission to move estimate back to draft.', 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }

        if ($nextStatus === 'REJECTED' && $statusNote === '') {
            flash_set('estimate_error', 'Rejection reason is required.', 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }

        $approvedAt = null;
        $rejectedAt = null;
        $rejectReason = null;
        if ($nextStatus === 'APPROVED') {
            $approvedAt = date('Y-m-d H:i:s');
        } elseif ($nextStatus === 'REJECTED') {
            $rejectedAt = date('Y-m-d H:i:s');
            $rejectReason = $statusNote;
        }

        $updateStmt = db()->prepare(
            'UPDATE estimates
             SET estimate_status = :estimate_status,
                 approved_at = :approved_at,
                 rejected_at = :rejected_at,
                 reject_reason = :reject_reason,
                 updated_by = :updated_by
             WHERE id = :id
               AND company_id = :company_id
               AND garage_id = :garage_id
               AND status_code = "ACTIVE"'
        );
        $updateStmt->execute([
            'estimate_status' => $nextStatus,
            'approved_at' => $approvedAt,
            'rejected_at' => $rejectedAt,
            'reject_reason' => $rejectReason,
            'updated_by' => $userId > 0 ? $userId : null,
            'id' => $estimateId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);

        estimate_append_history($estimateId, 'STATUS_CHANGE', $currentStatus, $nextStatus, $statusNote !== '' ? $statusNote : null);
        log_audit('estimates', 'status_change', $estimateId, 'Changed estimate status from ' . $currentStatus . ' to ' . $nextStatus);

        flash_set('estimate_success', 'Estimate status updated to ' . $nextStatus . '.', 'success');
        redirect('modules/estimates/view.php?id=' . $estimateId);
    }

    if ($action === 'convert_to_job') {
        if (!$canConvert) {
            flash_set('estimate_error', 'You do not have permission to convert estimates.', 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }

        try {
            $conversion = estimate_convert_to_job_card($estimateId, $companyId, $garageId, $userId);
            $jobId = (int) ($conversion['job_id'] ?? 0);
            $jobNumber = (string) ($conversion['job_number'] ?? ('#' . $jobId));
            $alreadyConverted = !empty($conversion['already_converted']);

            if ($alreadyConverted) {
                flash_set('estimate_success', 'Estimate already converted. Opening existing job card ' . $jobNumber . '.', 'info');
            } else {
                flash_set('estimate_success', 'Estimate converted successfully to Job Card ' . $jobNumber . '.', 'success');
            }

            redirect('modules/jobs/view.php?id=' . $jobId);
        } catch (Throwable $exception) {
            flash_set('estimate_error', $exception->getMessage(), 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }
    }

    $lineActions = ['add_service', 'update_service', 'delete_service', 'add_part', 'update_part', 'delete_part'];
    if (in_array($action, $lineActions, true)) {
        if (!$canEdit) {
            flash_set('estimate_error', 'You do not have permission to edit estimate lines.', 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }
        if (!$editable) {
            flash_set('estimate_error', 'Only draft estimates can be edited.', 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }
    }

    if ($action === 'add_service') {
        $serviceId = post_int('service_id');
        $description = post_string('description', 255);
        $quantity = estimate_post_decimal('quantity', 1.0);
        $unitPrice = estimate_post_decimal('unit_price', -1.0);
        $gstRate = estimate_post_decimal('gst_rate', -1.0);

        if ($serviceId <= 0 || $quantity <= 0) {
            flash_set('estimate_error', 'Select service and quantity.', 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }

        $serviceStmt = db()->prepare(
            'SELECT id, service_name, default_rate, gst_rate
             FROM services
             WHERE id = :id
               AND company_id = :company_id
               AND status_code = "ACTIVE"
             LIMIT 1'
        );
        $serviceStmt->execute([
            'id' => $serviceId,
            'company_id' => $companyId,
        ]);
        $service = $serviceStmt->fetch();
        if (!$service) {
            flash_set('estimate_error', 'Selected service is not available.', 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }

        if ($description === '') {
            $description = (string) $service['service_name'];
        }
        if ($unitPrice < 0) {
            $unitPrice = (float) $service['default_rate'];
        }
        if ($gstRate < 0) {
            $gstRate = (float) $service['gst_rate'];
        }

        $unitPrice = max(0, $unitPrice);
        $gstRate = max(0, min(100, $gstRate));
        $totalAmount = round($quantity * $unitPrice, 2);

        $insertStmt = db()->prepare(
            'INSERT INTO estimate_services
              (estimate_id, service_id, description, quantity, unit_price, gst_rate, total_amount)
             VALUES
              (:estimate_id, :service_id, :description, :quantity, :unit_price, :gst_rate, :total_amount)'
        );
        $insertStmt->execute([
            'estimate_id' => $estimateId,
            'service_id' => $serviceId,
            'description' => $description,
            'quantity' => round($quantity, 2),
            'unit_price' => round($unitPrice, 2),
            'gst_rate' => round($gstRate, 2),
            'total_amount' => $totalAmount,
        ]);

        estimate_recalculate_total($estimateId);
        estimate_append_history($estimateId, 'SERVICE_ADD', null, null, 'Service line added');
        log_audit('estimates', 'add_service', $estimateId, 'Added service line to estimate');

        flash_set('estimate_success', 'Service line added.', 'success');
        redirect('modules/estimates/view.php?id=' . $estimateId);
    }

    if ($action === 'update_service') {
        $lineId = post_int('estimate_service_id');
        $description = post_string('description', 255);
        $quantity = estimate_post_decimal('quantity', 0.0);
        $unitPrice = estimate_post_decimal('unit_price', 0.0);
        $gstRate = estimate_post_decimal('gst_rate', 18.0);

        if ($lineId <= 0 || $description === '' || $quantity <= 0 || $unitPrice < 0) {
            flash_set('estimate_error', 'Invalid service update payload.', 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }

        $lineStmt = db()->prepare(
            'SELECT es.id
             FROM estimate_services es
             INNER JOIN estimates e ON e.id = es.estimate_id
             WHERE es.id = :line_id
               AND es.estimate_id = :estimate_id
               AND e.company_id = :company_id
               AND e.garage_id = :garage_id
             LIMIT 1'
        );
        $lineStmt->execute([
            'line_id' => $lineId,
            'estimate_id' => $estimateId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
        if (!$lineStmt->fetch()) {
            flash_set('estimate_error', 'Service line not found.', 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }

        $gstRate = max(0, min(100, $gstRate));
        $totalAmount = round($quantity * $unitPrice, 2);

        $updateStmt = db()->prepare(
            'UPDATE estimate_services
             SET description = :description,
                 quantity = :quantity,
                 unit_price = :unit_price,
                 gst_rate = :gst_rate,
                 total_amount = :total_amount
             WHERE id = :line_id
               AND estimate_id = :estimate_id'
        );
        $updateStmt->execute([
            'description' => $description,
            'quantity' => round($quantity, 2),
            'unit_price' => round($unitPrice, 2),
            'gst_rate' => round($gstRate, 2),
            'total_amount' => $totalAmount,
            'line_id' => $lineId,
            'estimate_id' => $estimateId,
        ]);

        estimate_recalculate_total($estimateId);
        estimate_append_history($estimateId, 'SERVICE_EDIT', null, null, 'Service line updated');
        log_audit('estimates', 'update_service', $estimateId, 'Updated service line #' . $lineId);

        flash_set('estimate_success', 'Service line updated.', 'success');
        redirect('modules/estimates/view.php?id=' . $estimateId);
    }

    if ($action === 'delete_service') {
        $lineId = post_int('estimate_service_id');
        if ($lineId <= 0) {
            flash_set('estimate_error', 'Invalid service line selected.', 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }

        $deleteStmt = db()->prepare(
            'DELETE es
             FROM estimate_services es
             INNER JOIN estimates e ON e.id = es.estimate_id
             WHERE es.id = :line_id
               AND es.estimate_id = :estimate_id
               AND e.company_id = :company_id
               AND e.garage_id = :garage_id'
        );
        $deleteStmt->execute([
            'line_id' => $lineId,
            'estimate_id' => $estimateId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);

        estimate_recalculate_total($estimateId);
        estimate_append_history($estimateId, 'SERVICE_REMOVE', null, null, 'Service line removed');
        log_audit('estimates', 'delete_service', $estimateId, 'Deleted service line #' . $lineId);

        flash_set('estimate_success', 'Service line removed.', 'success');
        redirect('modules/estimates/view.php?id=' . $estimateId);
    }

    if ($action === 'add_part') {
        $partId = post_int('part_id');
        $quantity = estimate_post_decimal('quantity', 0.0);
        $unitPrice = estimate_post_decimal('unit_price', -1.0);
        $gstRate = estimate_post_decimal('gst_rate', -1.0);

        if ($partId <= 0 || $quantity <= 0) {
            flash_set('estimate_error', 'Select part and quantity.', 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }

        $partStmt = db()->prepare(
            'SELECT id, selling_price, gst_rate
             FROM parts
             WHERE id = :id
               AND company_id = :company_id
               AND status_code = "ACTIVE"
             LIMIT 1'
        );
        $partStmt->execute([
            'id' => $partId,
            'company_id' => $companyId,
        ]);
        $part = $partStmt->fetch();
        if (!$part) {
            flash_set('estimate_error', 'Selected part is not available.', 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }

        if ($unitPrice < 0) {
            $unitPrice = (float) $part['selling_price'];
        }
        if ($gstRate < 0) {
            $gstRate = (float) $part['gst_rate'];
        }

        $unitPrice = max(0, $unitPrice);
        $gstRate = max(0, min(100, $gstRate));
        $totalAmount = round($quantity * $unitPrice, 2);

        $insertStmt = db()->prepare(
            'INSERT INTO estimate_parts
              (estimate_id, part_id, quantity, unit_price, gst_rate, total_amount)
             VALUES
              (:estimate_id, :part_id, :quantity, :unit_price, :gst_rate, :total_amount)'
        );
        $insertStmt->execute([
            'estimate_id' => $estimateId,
            'part_id' => $partId,
            'quantity' => round($quantity, 2),
            'unit_price' => round($unitPrice, 2),
            'gst_rate' => round($gstRate, 2),
            'total_amount' => $totalAmount,
        ]);

        estimate_recalculate_total($estimateId);
        estimate_append_history($estimateId, 'PART_ADD', null, null, 'Part line added');
        log_audit('estimates', 'add_part', $estimateId, 'Added part line to estimate');

        flash_set('estimate_success', 'Part line added.', 'success');
        redirect('modules/estimates/view.php?id=' . $estimateId);
    }

    if ($action === 'update_part') {
        $lineId = post_int('estimate_part_id');
        $quantity = estimate_post_decimal('quantity', 0.0);
        $unitPrice = estimate_post_decimal('unit_price', 0.0);
        $gstRate = estimate_post_decimal('gst_rate', 18.0);

        if ($lineId <= 0 || $quantity <= 0 || $unitPrice < 0) {
            flash_set('estimate_error', 'Invalid part update payload.', 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }

        $lineStmt = db()->prepare(
            'SELECT ep.id
             FROM estimate_parts ep
             INNER JOIN estimates e ON e.id = ep.estimate_id
             WHERE ep.id = :line_id
               AND ep.estimate_id = :estimate_id
               AND e.company_id = :company_id
               AND e.garage_id = :garage_id
             LIMIT 1'
        );
        $lineStmt->execute([
            'line_id' => $lineId,
            'estimate_id' => $estimateId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
        if (!$lineStmt->fetch()) {
            flash_set('estimate_error', 'Part line not found.', 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }

        $gstRate = max(0, min(100, $gstRate));
        $totalAmount = round($quantity * $unitPrice, 2);

        $updateStmt = db()->prepare(
            'UPDATE estimate_parts
             SET quantity = :quantity,
                 unit_price = :unit_price,
                 gst_rate = :gst_rate,
                 total_amount = :total_amount
             WHERE id = :line_id
               AND estimate_id = :estimate_id'
        );
        $updateStmt->execute([
            'quantity' => round($quantity, 2),
            'unit_price' => round($unitPrice, 2),
            'gst_rate' => round($gstRate, 2),
            'total_amount' => $totalAmount,
            'line_id' => $lineId,
            'estimate_id' => $estimateId,
        ]);

        estimate_recalculate_total($estimateId);
        estimate_append_history($estimateId, 'PART_EDIT', null, null, 'Part line updated');
        log_audit('estimates', 'update_part', $estimateId, 'Updated part line #' . $lineId);

        flash_set('estimate_success', 'Part line updated.', 'success');
        redirect('modules/estimates/view.php?id=' . $estimateId);
    }

    if ($action === 'delete_part') {
        $lineId = post_int('estimate_part_id');
        if ($lineId <= 0) {
            flash_set('estimate_error', 'Invalid part line selected.', 'danger');
            redirect('modules/estimates/view.php?id=' . $estimateId);
        }

        $deleteStmt = db()->prepare(
            'DELETE ep
             FROM estimate_parts ep
             INNER JOIN estimates e ON e.id = ep.estimate_id
             WHERE ep.id = :line_id
               AND ep.estimate_id = :estimate_id
               AND e.company_id = :company_id
               AND e.garage_id = :garage_id'
        );
        $deleteStmt->execute([
            'line_id' => $lineId,
            'estimate_id' => $estimateId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);

        estimate_recalculate_total($estimateId);
        estimate_append_history($estimateId, 'PART_REMOVE', null, null, 'Part line removed');
        log_audit('estimates', 'delete_part', $estimateId, 'Deleted part line #' . $lineId);

        flash_set('estimate_success', 'Part line removed.', 'success');
        redirect('modules/estimates/view.php?id=' . $estimateId);
    }
}

$estimate = estimate_fetch_details($estimateId, $companyId, $garageId);
if (!$estimate) {
    flash_set('estimate_error', 'Estimate not found.', 'danger');
    redirect('modules/estimates/index.php');
}

$estimateStatus = estimate_normalize_status((string) $estimate['estimate_status']);
$editable = estimate_is_editable($estimate);

$customersStmt = db()->prepare(
    'SELECT id, full_name, phone
     FROM customers
     WHERE company_id = :company_id
       AND status_code = "ACTIVE"
     ORDER BY full_name ASC'
);
$customersStmt->execute(['company_id' => $companyId]);
$customers = $customersStmt->fetchAll();

$vehiclesStmt = db()->prepare(
    'SELECT id, customer_id, registration_no, brand, model
     FROM vehicles
     WHERE company_id = :company_id
       AND status_code = "ACTIVE"
     ORDER BY registration_no ASC'
);
$vehiclesStmt->execute(['company_id' => $companyId]);
$vehicles = $vehiclesStmt->fetchAll();

$serviceMaster = estimate_fetch_service_master($companyId);
$partMaster = estimate_fetch_part_master($companyId, $garageId);
$serviceLines = estimate_fetch_services($estimateId);
$partLines = estimate_fetch_parts($estimateId, $garageId);
$historyEntries = estimate_fetch_history($estimateId);

$serviceTotal = 0.0;
foreach ($serviceLines as $line) {
    $serviceTotal += (float) ($line['total_amount'] ?? 0);
}
$partsTotal = 0.0;
foreach ($partLines as $line) {
    $partsTotal += (float) ($line['total_amount'] ?? 0);
}
$grandTotal = round($serviceTotal + $partsTotal, 2);

$nextStatuses = [];
foreach (estimate_statuses() as $candidateStatus) {
    if ($candidateStatus === $estimateStatus || $candidateStatus === 'CONVERTED') {
        continue;
    }
    if (!estimate_can_transition($estimateStatus, $candidateStatus)) {
        continue;
    }
    if ($candidateStatus === 'APPROVED' && !$canApprove) {
        continue;
    }
    if ($candidateStatus === 'REJECTED' && !$canReject) {
        continue;
    }
    if ($candidateStatus === 'DRAFT' && !$canEdit) {
        continue;
    }
    $nextStatuses[] = $candidateStatus;
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-8">
          <h3 class="mb-0">Estimate <?= e((string) $estimate['estimate_number']); ?></h3>
          <small class="text-muted">
            Garage: <?= e((string) $estimate['garage_name']); ?> |
            Customer: <?= e((string) $estimate['customer_name']); ?> |
            Vehicle: <?= e((string) $estimate['registration_no']); ?>
          </small>
        </div>
        <div class="col-sm-4">
          <div class="d-flex flex-column align-items-sm-end gap-2">
            <ol class="breadcrumb mb-0">
              <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
              <li class="breadcrumb-item">Operations</li>
              <li class="breadcrumb-item"><a href="<?= e(url('modules/estimates/index.php')); ?>">Estimates</a></li>
              <li class="breadcrumb-item active">Details</li>
            </ol>
            <div class="d-flex flex-wrap justify-content-sm-end gap-1">
              <a href="<?= e(url('modules/estimates/index.php')); ?>" class="btn btn-outline-secondary btn-sm">Back to Estimates</a>
              <?php if ($canPrint): ?>
                <a href="<?= e(url('modules/estimates/print_estimate.php?id=' . $estimateId)); ?>" class="btn btn-outline-primary btn-sm" target="_blank">Print</a>
              <?php endif; ?>
              <?php if ((int) ($estimate['converted_job_card_id'] ?? 0) > 0): ?>
                <a href="<?= e(url('modules/jobs/view.php?id=' . (int) $estimate['converted_job_card_id'])); ?>" class="btn btn-success btn-sm">Open Job</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if (!$editable): ?>
        <div class="alert alert-warning">This estimate is <?= e($estimateStatus); ?>. Draft-only editing is disabled.</div>
      <?php endif; ?>

      <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="small-box text-bg-<?= e(estimate_status_badge_class($estimateStatus)); ?>"><div class="inner"><h4><?= e($estimateStatus); ?></h4><p>Current Status</p></div><span class="small-box-icon"><i class="bi bi-file-earmark-text"></i></span></div></div>
        <div class="col-md-3"><div class="small-box text-bg-success"><div class="inner"><h4><?= e(format_currency($serviceTotal)); ?></h4><p>Services</p></div><span class="small-box-icon"><i class="bi bi-tools"></i></span></div></div>
        <div class="col-md-3"><div class="small-box text-bg-warning"><div class="inner"><h4><?= e(format_currency($partsTotal)); ?></h4><p>Parts</p></div><span class="small-box-icon"><i class="bi bi-box-seam"></i></span></div></div>
        <div class="col-md-3"><div class="small-box text-bg-primary"><div class="inner"><h4><?= e(format_currency($grandTotal)); ?></h4><p>Estimate Total</p></div><span class="small-box-icon"><i class="bi bi-currency-rupee"></i></span></div></div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-8">
          <div class="card">
            <div class="card-header"><h3 class="card-title">Estimate Details</h3></div>
            <?php if ($canEdit && $editable): ?>
              <form method="post">
                <div class="card-body row g-3">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="update_meta" />
                  <div class="col-md-4">
                    <label class="form-label">Customer</label>
                    <select id="estimate-customer-select" name="customer_id" class="form-select" required>
                      <option value="">Select Customer</option>
                      <?php foreach ($customers as $customer): ?>
                        <option value="<?= (int) $customer['id']; ?>" <?= ((int) $estimate['customer_id'] === (int) $customer['id']) ? 'selected' : ''; ?>>
                          <?= e((string) $customer['full_name']); ?> (<?= e((string) $customer['phone']); ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Vehicle</label>
                    <select id="estimate-vehicle-select" name="vehicle_id" class="form-select" required>
                      <option value="">Select Vehicle</option>
                      <?php foreach ($vehicles as $vehicle): ?>
                        <option value="<?= (int) $vehicle['id']; ?>" data-customer-id="<?= (int) $vehicle['customer_id']; ?>" <?= ((int) $estimate['vehicle_id'] === (int) $vehicle['id']) ? 'selected' : ''; ?>>
                          <?= e((string) $vehicle['registration_no']); ?> - <?= e((string) $vehicle['brand']); ?> <?= e((string) $vehicle['model']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Valid Until</label>
                    <input type="date" name="valid_until" class="form-control" value="<?= e((string) ($estimate['valid_until'] ?? '')); ?>" />
                  </div>
                  <div class="col-md-12"><label class="form-label">Complaint / Scope</label><textarea name="complaint" class="form-control" rows="2" required><?= e((string) ($estimate['complaint'] ?? '')); ?></textarea></div>
                  <div class="col-md-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= e((string) ($estimate['notes'] ?? '')); ?></textarea></div>
                </div>
                <div class="card-footer"><button type="submit" class="btn btn-primary">Update Estimate Details</button></div>
              </form>
            <?php else: ?>
              <div class="card-body">
                <div class="row g-3">
                  <div class="col-md-6"><strong>Customer:</strong> <?= e((string) $estimate['customer_name']); ?> (<?= e((string) $estimate['customer_phone']); ?>)</div>
                  <div class="col-md-6"><strong>Vehicle:</strong> <?= e((string) $estimate['registration_no']); ?> - <?= e((string) $estimate['brand']); ?> <?= e((string) $estimate['model']); ?></div>
                  <div class="col-md-6"><strong>Valid Until:</strong> <?= e((string) (($estimate['valid_until'] ?? '') !== '' ? $estimate['valid_until'] : '-')); ?></div>
                  <div class="col-md-6"><strong>Created At:</strong> <?= e((string) ($estimate['created_at'] ?? '')); ?></div>
                  <div class="col-md-12"><strong>Complaint / Scope:</strong><div class="border rounded p-2 mt-1"><?= nl2br(e((string) ($estimate['complaint'] ?? ''))); ?></div></div>
                  <div class="col-md-12"><strong>Notes:</strong><div class="border rounded p-2 mt-1"><?= nl2br(e((string) ($estimate['notes'] ?? '-'))); ?></div></div>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="col-lg-4">
          <div class="card card-outline card-info">
            <div class="card-header"><h3 class="card-title">Workflow</h3></div>
            <div class="card-body">
              <div class="mb-2"><strong>Status:</strong> <span class="badge text-bg-<?= e(estimate_status_badge_class($estimateStatus)); ?>"><?= e($estimateStatus); ?></span></div>
              <div class="mb-2"><strong>Approved At:</strong> <?= e((string) (($estimate['approved_at'] ?? '') !== '' ? $estimate['approved_at'] : '-')); ?></div>
              <div class="mb-2"><strong>Rejected At:</strong> <?= e((string) (($estimate['rejected_at'] ?? '') !== '' ? $estimate['rejected_at'] : '-')); ?></div>
              <div class="mb-2"><strong>Reject Reason:</strong> <?= e((string) (($estimate['reject_reason'] ?? '') !== '' ? $estimate['reject_reason'] : '-')); ?></div>
              <div class="mb-3"><strong>Converted At:</strong> <?= e((string) (($estimate['converted_at'] ?? '') !== '' ? $estimate['converted_at'] : '-')); ?></div>

              <?php if (!empty($nextStatuses)): ?>
                <form method="post" class="mb-3">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="transition_status" />
                  <div class="mb-2">
                    <label class="form-label">Change Status</label>
                    <select name="next_status" id="estimate-next-status" class="form-select" required>
                      <?php foreach ($nextStatuses as $status): ?>
                        <option value="<?= e($status); ?>"><?= e($status); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="mb-2">
                    <label class="form-label">Status Note</label>
                    <input type="text" name="status_note" id="estimate-status-note" class="form-control" maxlength="255" placeholder="Required for REJECTED" />
                  </div>
                  <button type="submit" class="btn btn-outline-primary w-100">Apply Status</button>
                </form>
              <?php endif; ?>

              <?php if ($canConvert && estimate_can_convert($estimate)): ?>
                <form method="post" data-confirm="Convert approved estimate into a job card now?">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="convert_to_job" />
                  <button type="submit" class="btn btn-success w-100">Convert to Job Card</button>
                  <div class="form-hint mt-1">Services and parts will be copied automatically. No re-entry required.</div>
                </form>
              <?php elseif ($estimateStatus === 'APPROVED' && !$canConvert): ?>
                <div class="text-muted">Estimate is approved but you do not have conversion permission.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="card card-success mb-3">
        <div class="card-header"><h3 class="card-title">Service Lines</h3></div>
        <?php if ($canEdit && $editable): ?>
          <form method="post">
            <div class="card-body border-bottom row g-2">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="add_service" />
              <div class="col-md-5">
                <label class="form-label">Service</label>
                <select id="estimate-add-service" name="service_id" class="form-select" required>
                  <option value="">Select Service</option>
                  <?php foreach ($serviceMaster as $service): ?>
                    <option value="<?= (int) $service['id']; ?>" data-name="<?= e((string) $service['service_name']); ?>" data-rate="<?= e((string) $service['default_rate']); ?>" data-gst="<?= e((string) $service['gst_rate']); ?>">
                      <?= e((string) ($service['category_name'] ?? 'Uncategorized')); ?> :: <?= e((string) $service['service_name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3"><label class="form-label">Description</label><input id="estimate-add-service-description" type="text" name="description" class="form-control" maxlength="255" /></div>
              <div class="col-md-1"><label class="form-label">Qty</label><input type="number" step="0.01" min="0.01" name="quantity" class="form-control" value="1" required /></div>
              <div class="col-md-1"><label class="form-label">Rate</label><input id="estimate-add-service-rate" type="number" step="0.01" min="0" name="unit_price" class="form-control" value="0" required /></div>
              <div class="col-md-1"><label class="form-label">GST%</label><input id="estimate-add-service-gst" type="number" step="0.01" min="0" max="100" name="gst_rate" class="form-control" value="18" required /></div>
              <div class="col-md-1 d-flex align-items-end"><button type="submit" class="btn btn-success w-100">Add</button></div>
            </div>
          </form>
        <?php endif; ?>

        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0">
            <thead><tr><th>Service</th><th>Description</th><th>Qty</th><th>Rate</th><th>GST%</th><th>Total</th><th>Actions</th></tr></thead>
            <tbody>
              <?php if (empty($serviceLines)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No service lines added.</td></tr>
              <?php else: foreach ($serviceLines as $line): ?>
                <tr>
                  <td><?= e((string) (($line['service_name'] ?? '') !== '' ? $line['service_name'] : 'Manual Service')); ?></td>
                  <td><?= e((string) $line['description']); ?></td>
                  <td><?= e(number_format((float) $line['quantity'], 2)); ?></td>
                  <td><?= e(format_currency((float) $line['unit_price'])); ?></td>
                  <td><?= e(number_format((float) $line['gst_rate'], 2)); ?></td>
                  <td><?= e(format_currency((float) $line['total_amount'])); ?></td>
                  <td>
                    <?php if ($canEdit && $editable): ?>
                      <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#service-edit-<?= (int) $line['id']; ?>">Edit</button>
                      <form method="post" class="d-inline">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="_action" value="delete_service" />
                        <input type="hidden" name="estimate_service_id" value="<?= (int) $line['id']; ?>" />
                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this service line?');">Remove</button>
                      </form>
                    <?php else: ?><span class="text-muted">Locked</span><?php endif; ?>
                  </td>
                </tr>
                <?php if ($canEdit && $editable): ?>
                  <tr class="collapse" id="service-edit-<?= (int) $line['id']; ?>"><td colspan="7">
                    <form method="post" class="row g-2 p-2 bg-light">
                      <?= csrf_field(); ?>
                      <input type="hidden" name="_action" value="update_service" />
                      <input type="hidden" name="estimate_service_id" value="<?= (int) $line['id']; ?>" />
                      <div class="col-md-4"><input type="text" name="description" class="form-control form-control-sm" maxlength="255" value="<?= e((string) $line['description']); ?>" required /></div>
                      <div class="col-md-2"><input type="number" step="0.01" min="0.01" name="quantity" class="form-control form-control-sm" value="<?= e((string) $line['quantity']); ?>" required /></div>
                      <div class="col-md-2"><input type="number" step="0.01" min="0" name="unit_price" class="form-control form-control-sm" value="<?= e((string) $line['unit_price']); ?>" required /></div>
                      <div class="col-md-2"><input type="number" step="0.01" min="0" max="100" name="gst_rate" class="form-control form-control-sm" value="<?= e((string) $line['gst_rate']); ?>" required /></div>
                      <div class="col-md-2"><button type="submit" class="btn btn-sm btn-primary w-100">Save</button></div>
                    </form>
                  </td></tr>
                <?php endif; ?>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card card-warning mb-3">
        <div class="card-header"><h3 class="card-title">Part Lines</h3></div>
        <?php if ($canEdit && $editable): ?>
          <form method="post">
            <div class="card-body border-bottom row g-2">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="add_part" />
              <div class="col-md-5">
                <label class="form-label">Part</label>
                <select id="estimate-add-part" name="part_id" class="form-select" required>
                  <option value="">Select Part</option>
                  <?php foreach ($partMaster as $part): ?>
                    <option value="<?= (int) $part['id']; ?>" data-price="<?= e((string) $part['selling_price']); ?>" data-gst="<?= e((string) $part['gst_rate']); ?>" data-stock="<?= e((string) $part['stock_qty']); ?>">
                      <?= e((string) $part['part_name']); ?> (<?= e((string) $part['part_sku']); ?>) | Stock <?= e(number_format((float) $part['stock_qty'], 2)); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <small id="estimate-add-part-stock-hint" class="text-muted"></small>
              </div>
              <div class="col-md-2"><label class="form-label">Qty</label><input type="number" step="0.01" min="0.01" name="quantity" class="form-control" value="1" required /></div>
              <div class="col-md-2"><label class="form-label">Rate</label><input id="estimate-add-part-rate" type="number" step="0.01" min="0" name="unit_price" class="form-control" value="0" required /></div>
              <div class="col-md-2"><label class="form-label">GST%</label><input id="estimate-add-part-gst" type="number" step="0.01" min="0" max="100" name="gst_rate" class="form-control" value="18" required /></div>
              <div class="col-md-1 d-flex align-items-end"><button type="submit" class="btn btn-warning w-100">Add</button></div>
            </div>
          </form>
        <?php endif; ?>

        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0">
            <thead><tr><th>Part</th><th>Qty</th><th>Rate</th><th>GST%</th><th>Total</th><th>Stock</th><th>Actions</th></tr></thead>
            <tbody>
              <?php if (empty($partLines)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No part lines added.</td></tr>
              <?php else: foreach ($partLines as $line): ?>
                <?php $lineQty = (float) $line['quantity']; $lineStock = (float) ($line['stock_qty'] ?? 0); $stockClass = $lineStock >= $lineQty ? 'text-success' : 'text-danger'; ?>
                <tr>
                  <td><?= e((string) $line['part_name']); ?> (<?= e((string) $line['part_sku']); ?>)</td>
                  <td><?= e(number_format($lineQty, 2)); ?></td>
                  <td><?= e(format_currency((float) $line['unit_price'])); ?></td>
                  <td><?= e(number_format((float) $line['gst_rate'], 2)); ?></td>
                  <td><?= e(format_currency((float) $line['total_amount'])); ?></td>
                  <td><span class="<?= e($stockClass); ?>"><?= e(number_format($lineStock, 2)); ?></span></td>
                  <td>
                    <?php if ($canEdit && $editable): ?>
                      <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#part-edit-<?= (int) $line['id']; ?>">Edit</button>
                      <form method="post" class="d-inline">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="_action" value="delete_part" />
                        <input type="hidden" name="estimate_part_id" value="<?= (int) $line['id']; ?>" />
                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this part line?');">Remove</button>
                      </form>
                    <?php else: ?><span class="text-muted">Locked</span><?php endif; ?>
                  </td>
                </tr>
                <?php if ($canEdit && $editable): ?>
                  <tr class="collapse" id="part-edit-<?= (int) $line['id']; ?>"><td colspan="7">
                    <form method="post" class="row g-2 p-2 bg-light">
                      <?= csrf_field(); ?>
                      <input type="hidden" name="_action" value="update_part" />
                      <input type="hidden" name="estimate_part_id" value="<?= (int) $line['id']; ?>" />
                      <div class="col-md-3"><input type="number" step="0.01" min="0.01" name="quantity" class="form-control form-control-sm" value="<?= e((string) $line['quantity']); ?>" required /></div>
                      <div class="col-md-3"><input type="number" step="0.01" min="0" name="unit_price" class="form-control form-control-sm" value="<?= e((string) $line['unit_price']); ?>" required /></div>
                      <div class="col-md-3"><input type="number" step="0.01" min="0" max="100" name="gst_rate" class="form-control form-control-sm" value="<?= e((string) $line['gst_rate']); ?>" required /></div>
                      <div class="col-md-3"><button type="submit" class="btn btn-sm btn-primary w-100">Save</button></div>
                    </form>
                  </td></tr>
                <?php endif; ?>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card card-outline card-secondary">
        <div class="card-header"><h3 class="card-title">Estimate History</h3></div>
        <div class="card-body p-0">
          <ul class="list-group list-group-flush">
            <?php if (empty($historyEntries)): ?>
              <li class="list-group-item text-muted">No history events found.</li>
            <?php else: foreach ($historyEntries as $entry): ?>
              <li class="list-group-item">
                <div class="d-flex justify-content-between"><div><span class="badge text-bg-secondary"><?= e((string) $entry['action_type']); ?></span><?php if (!empty($entry['from_status']) || !empty($entry['to_status'])): ?><span class="ms-2 text-muted"><?= e((string) ($entry['from_status'] ?? '-')); ?> -> <?= e((string) ($entry['to_status'] ?? '-')); ?></span><?php endif; ?></div><small class="text-muted"><?= e((string) $entry['created_at']); ?></small></div>
                <?php if (!empty($entry['action_note'])): ?><div class="mt-1"><?= e((string) $entry['action_note']); ?></div><?php endif; ?>
                <small class="text-muted">By: <?= e((string) ($entry['actor_name'] ?? 'System')); ?></small>
              </li>
            <?php endforeach; endif; ?>
          </ul>
        </div>
      </div>
    </div>
  </div>
</main>

<script>
  (function () {
    var customerSelect = document.getElementById('estimate-customer-select');
    var vehicleSelect = document.getElementById('estimate-vehicle-select');
    if (customerSelect && vehicleSelect) {
      function selectedVehicleCustomerId() {
        var selected = vehicleSelect.options[vehicleSelect.selectedIndex];
        return selected ? (selected.getAttribute('data-customer-id') || '').trim() : '';
      }
      function syncCustomerFromVehicle() {
        var ownerId = selectedVehicleCustomerId();
        if (ownerId && (customerSelect.value || '') !== ownerId) {
          customerSelect.value = ownerId;
          if (typeof gacRefreshSearchableSelect === 'function') {
            gacRefreshSearchableSelect(customerSelect);
          }
        }
      }
      function enforceOwnerMatch() {
        var selectedVehicleId = (vehicleSelect.value || '').trim();
        if (!selectedVehicleId) {
          return;
        }
        var ownerId = selectedVehicleCustomerId();
        if (ownerId && (customerSelect.value || '') !== ownerId) {
          vehicleSelect.value = '';
          if (typeof gacRefreshSearchableSelect === 'function') {
            gacRefreshSearchableSelect(vehicleSelect);
          }
        }
      }
      vehicleSelect.addEventListener('change', syncCustomerFromVehicle);
      customerSelect.addEventListener('change', enforceOwnerMatch);
    }

    var addServiceSelect = document.getElementById('estimate-add-service');
    var addServiceDescription = document.getElementById('estimate-add-service-description');
    var addServiceRate = document.getElementById('estimate-add-service-rate');
    var addServiceGst = document.getElementById('estimate-add-service-gst');
    if (addServiceSelect && addServiceDescription && addServiceRate && addServiceGst) {
      addServiceSelect.addEventListener('change', function () {
        var selected = addServiceSelect.options[addServiceSelect.selectedIndex];
        if (!selected || !selected.value) {
          return;
        }
        addServiceDescription.value = selected.getAttribute('data-name') || addServiceDescription.value;
        addServiceRate.value = selected.getAttribute('data-rate') || addServiceRate.value;
        addServiceGst.value = selected.getAttribute('data-gst') || addServiceGst.value;
      });
    }

    var addPartSelect = document.getElementById('estimate-add-part');
    var addPartRate = document.getElementById('estimate-add-part-rate');
    var addPartGst = document.getElementById('estimate-add-part-gst');
    var addPartStockHint = document.getElementById('estimate-add-part-stock-hint');
    if (addPartSelect && addPartRate && addPartGst && addPartStockHint) {
      addPartSelect.addEventListener('change', function () {
        var selected = addPartSelect.options[addPartSelect.selectedIndex];
        if (!selected) {
          return;
        }
        addPartRate.value = selected.getAttribute('data-price') || addPartRate.value;
        addPartGst.value = selected.getAttribute('data-gst') || addPartGst.value;
        addPartStockHint.textContent = 'Available stock in this garage: ' + (selected.getAttribute('data-stock') || '0');
      });
    }

    var nextStatusSelect = document.getElementById('estimate-next-status');
    var statusNoteInput = document.getElementById('estimate-status-note');
    if (nextStatusSelect && statusNoteInput) {
      function syncStatusNoteRequirement() {
        if ((nextStatusSelect.value || '') === 'REJECTED') {
          statusNoteInput.required = true;
          statusNoteInput.placeholder = 'Rejection reason is required.';
        } else {
          statusNoteInput.required = false;
          statusNoteInput.placeholder = 'Optional note (required for REJECTED)';
        }
      }
      nextStatusSelect.addEventListener('change', syncStatusNoteRequirement);
      syncStatusNoteRequirement();
    }
  })();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
