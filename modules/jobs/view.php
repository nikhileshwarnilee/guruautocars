<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('job.view');
require_once __DIR__ . '/workflow.php';

$page_title = 'Job Card Details';
$active_menu = 'jobs';
$companyId = active_company_id();
$garageId = active_garage_id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$canEdit = has_permission('job.edit') || has_permission('job.update') || has_permission('job.manage');
$canAssign = has_permission('job.assign') || has_permission('job.manage');
$canClose = has_permission('job.close') || has_permission('job.manage');

function post_decimal(string $key, float $default = 0.0): float
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

function parse_user_ids(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $ids = [];
    foreach ($value as $item) {
        if (filter_var($item, FILTER_VALIDATE_INT) !== false && (int) $item > 0) {
            $ids[] = (int) $item;
        }
    }

    return array_values(array_unique($ids));
}

function job_status_badge_class(string $status): string
{
    return match (job_normalize_status($status)) {
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

function job_priority_badge_class(string $priority): string
{
    return match (strtoupper(trim($priority))) {
        'LOW' => 'secondary',
        'MEDIUM' => 'info',
        'HIGH' => 'warning',
        'URGENT' => 'danger',
        default => 'secondary',
    };
}

function fetch_job_row(int $jobId, int $companyId, int $garageId): ?array
{
    $stmt = db()->prepare(
        'SELECT *
         FROM job_cards
         WHERE id = :id
           AND company_id = :company_id
           AND garage_id = :garage_id
         LIMIT 1'
    );
    $stmt->execute([
        'id' => $jobId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);

    $row = $stmt->fetch();
    return $row ?: null;
}

function fetch_job_details(int $jobId, int $companyId, int $garageId): ?array
{
    $stmt = db()->prepare(
        'SELECT jc.*, c.full_name AS customer_name, c.phone AS customer_phone,
                v.registration_no, v.brand, v.model, v.variant, v.fuel_type,
                sa.name AS advisor_name, g.name AS garage_name,
                (
                    SELECT GROUP_CONCAT(DISTINCT au.name ORDER BY ja2.is_primary DESC, au.name SEPARATOR ", ")
                    FROM job_assignments ja2
                    INNER JOIN users au ON au.id = ja2.user_id
                    WHERE ja2.job_card_id = jc.id
                      AND ja2.status_code = "ACTIVE"
                ) AS assigned_staff
         FROM job_cards jc
         INNER JOIN customers c ON c.id = jc.customer_id
         INNER JOIN vehicles v ON v.id = jc.vehicle_id
         INNER JOIN garages g ON g.id = jc.garage_id
         LEFT JOIN users sa ON sa.id = jc.service_advisor_id
         WHERE jc.id = :job_id
           AND jc.company_id = :company_id
           AND jc.garage_id = :garage_id
         LIMIT 1'
    );
    $stmt->execute([
        'job_id' => $jobId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);

    $job = $stmt->fetch();
    return $job ?: null;
}

function fetch_job_labor(int $jobId): array
{
    $stmt = db()->prepare(
        'SELECT jl.*, s.service_name, s.service_code
         FROM job_labor jl
         LEFT JOIN services s ON s.id = jl.service_id
         WHERE jl.job_card_id = :job_id
         ORDER BY jl.id DESC'
    );
    $stmt->execute(['job_id' => $jobId]);
    return $stmt->fetchAll();
}

function fetch_job_parts(int $jobId, int $garageId): array
{
    $stmt = db()->prepare(
        'SELECT jp.*, p.part_name, p.part_sku, COALESCE(gi.quantity, 0) AS stock_qty
         FROM job_parts jp
         INNER JOIN parts p ON p.id = jp.part_id
         LEFT JOIN garage_inventory gi ON gi.part_id = jp.part_id AND gi.garage_id = :garage_id
         WHERE jp.job_card_id = :job_id
         ORDER BY jp.id DESC'
    );
    $stmt->execute([
        'job_id' => $jobId,
        'garage_id' => $garageId,
    ]);
    return $stmt->fetchAll();
}

function fetch_services_master(int $companyId): array
{
    $stmt = db()->prepare(
        'SELECT id, service_code, service_name, default_rate, gst_rate
         FROM services
         WHERE company_id = :company_id
           AND status_code = "ACTIVE"
         ORDER BY service_name ASC'
    );
    $stmt->execute(['company_id' => $companyId]);
    return $stmt->fetchAll();
}

function fetch_parts_master(int $companyId, int $garageId): array
{
    $stmt = db()->prepare(
        'SELECT p.id, p.part_name, p.part_sku, p.selling_price, p.gst_rate,
                COALESCE(gi.quantity, 0) AS stock_qty
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

function fetch_job_history_timeline(int $jobId): array
{
    $stmt = db()->prepare(
        'SELECT jh.*, u.name AS actor_name
         FROM job_history jh
         LEFT JOIN users u ON u.id = jh.created_by
         WHERE jh.job_card_id = :job_id
         ORDER BY jh.id DESC
         LIMIT 100'
    );
    $stmt->execute(['job_id' => $jobId]);
    return $stmt->fetchAll();
}

$jobId = get_int('id');
if ($jobId <= 0) {
    flash_set('job_error', 'Invalid job card id.', 'danger');
    redirect('modules/jobs/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = (string) ($_POST['_action'] ?? '');
    $jobForWrite = fetch_job_row($jobId, $companyId, $garageId);
    if (!$jobForWrite) {
        flash_set('job_error', 'Job card not found for active garage.', 'danger');
        redirect('modules/jobs/index.php');
    }

    if ($action === 'assign_staff') {
        if (!$canAssign) {
            flash_set('job_error', 'You do not have permission to assign staff.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        if (job_is_locked($jobForWrite)) {
            flash_set('job_error', 'This job card is locked and cannot be modified.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        $requestedIds = parse_user_ids($_POST['assigned_user_ids'] ?? []);
        $assignedIds = job_sync_assignments($jobId, $companyId, $garageId, $requestedIds, $userId);
        job_append_history($jobId, 'ASSIGN_UPDATE', null, null, 'Assignments updated', ['user_ids' => $assignedIds]);
        log_audit('job_cards', 'assign', $jobId, 'Updated job assignments');

        flash_set('job_success', 'Assignment updated successfully.', 'success');
        redirect('modules/jobs/view.php?id=' . $jobId);
    }

    if ($action === 'transition_status') {
        $targetStatus = strtoupper(trim((string) ($_POST['next_status'] ?? '')));
        $statusNote = post_string('status_note', 255);
        $currentStatus = job_normalize_status((string) $jobForWrite['status']);

        if (!in_array($targetStatus, job_workflow_statuses(true), true)) {
            flash_set('job_error', 'Invalid target status selected.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        if ($targetStatus === 'CLOSED' && !$canClose) {
            flash_set('job_error', 'You do not have permission to close jobs.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        if ($targetStatus !== 'CLOSED' && !$canEdit) {
            flash_set('job_error', 'You do not have permission to update job workflow.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        if (job_is_locked($jobForWrite)) {
            flash_set('job_error', 'This job card is locked and status cannot be changed.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        if (!job_can_transition($currentStatus, $targetStatus)) {
            flash_set('job_error', 'Invalid workflow transition from ' . $currentStatus . ' to ' . $targetStatus . '.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        if ($targetStatus === 'CANCELLED' && $statusNote === '') {
            flash_set('job_error', 'Cancellation requires an audit note.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        $inventoryWarnings = [];
        if ($targetStatus === 'CLOSED') {
            try {
                $postResult = job_post_inventory_on_close($jobId, $companyId, $garageId, $userId);
                $inventoryWarnings = $postResult['warnings'] ?? [];
            } catch (Throwable $exception) {
                flash_set('job_error', 'Unable to post inventory while closing job: ' . $exception->getMessage(), 'danger');
                redirect('modules/jobs/view.php?id=' . $jobId);
            }
        }

        $updateStmt = db()->prepare(
            'UPDATE job_cards
             SET status = :status,
                 completed_at = CASE
                     WHEN :status IN ("COMPLETED", "CLOSED") AND completed_at IS NULL THEN NOW()
                     ELSE completed_at
                 END,
                 closed_at = CASE
                     WHEN :status = "CLOSED" THEN NOW()
                     ELSE closed_at
                 END,
                 status_code = CASE
                     WHEN :status = "CANCELLED" THEN "INACTIVE"
                     ELSE status_code
                 END,
                 cancel_note = CASE
                     WHEN :status = "CANCELLED" THEN :cancel_note
                     ELSE cancel_note
                 END,
                 updated_by = :updated_by
             WHERE id = :id
               AND company_id = :company_id
               AND garage_id = :garage_id
               AND status_code <> "DELETED"'
        );
        $updateStmt->execute([
            'status' => $targetStatus,
            'cancel_note' => $targetStatus === 'CANCELLED' ? $statusNote : null,
            'updated_by' => $userId,
            'id' => $jobId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);

        job_append_history(
            $jobId,
            'STATUS_CHANGE',
            $currentStatus,
            $targetStatus,
            $statusNote !== '' ? $statusNote : null,
            ['inventory_warnings' => count($inventoryWarnings)]
        );
        log_audit('job_cards', 'status', $jobId, 'Status changed from ' . $currentStatus . ' to ' . $targetStatus);

        flash_set('job_success', 'Job status updated to ' . $targetStatus . '.', 'success');
        if (!empty($inventoryWarnings)) {
            $preview = implode(' | ', array_slice($inventoryWarnings, 0, 3));
            if (count($inventoryWarnings) > 3) {
                $preview .= ' | +' . (count($inventoryWarnings) - 3) . ' more';
            }
            flash_set('job_warning', 'Job closed with stock warnings: ' . $preview, 'warning');
        }

        redirect('modules/jobs/view.php?id=' . $jobId);
    }

    if ($action === 'soft_delete') {
        if (!$canEdit && !$canClose) {
            flash_set('job_error', 'You do not have permission to soft delete jobs.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        if (job_is_locked($jobForWrite)) {
            flash_set('job_error', 'Locked job cards cannot be soft deleted.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        $deleteNote = post_string('delete_note', 255);
        if ($deleteNote === '') {
            flash_set('job_error', 'Soft delete requires an audit note.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        $deleteStmt = db()->prepare(
            'UPDATE job_cards
             SET status_code = "DELETED",
                 deleted_at = NOW(),
                 cancel_note = :cancel_note,
                 updated_by = :updated_by
             WHERE id = :id
               AND company_id = :company_id
               AND garage_id = :garage_id'
        );
        $deleteStmt->execute([
            'cancel_note' => $deleteNote,
            'updated_by' => $userId,
            'id' => $jobId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);

        job_append_history(
            $jobId,
            'SOFT_DELETE',
            (string) $jobForWrite['status'],
            (string) $jobForWrite['status'],
            $deleteNote
        );
        log_audit('job_cards', 'soft_delete', $jobId, 'Soft deleted job card');

        flash_set('job_success', 'Job card soft deleted.', 'success');
        redirect('modules/jobs/index.php');
    }

    $lineActions = [
        'add_labor',
        'update_labor',
        'delete_labor',
        'add_part',
        'update_part',
        'delete_part',
    ];

    if (in_array($action, $lineActions, true)) {
        if (!$canEdit) {
            flash_set('job_error', 'You do not have permission to edit job lines.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        if (job_is_locked($jobForWrite)) {
            flash_set('job_error', 'This job card is locked and line edits are disabled.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }
    }

    if ($action === 'add_labor') {
        $serviceId = post_int('service_id');
        $description = post_string('description', 255);
        $quantity = post_decimal('quantity', 1.0);
        $unitPrice = post_decimal('unit_price', 0.0);
        $gstRate = post_decimal('gst_rate', 18.0);
        $serviceName = null;

        if ($serviceId > 0) {
            $serviceStmt = db()->prepare(
                'SELECT id, service_name, service_code, default_rate, gst_rate
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
                flash_set('job_error', 'Selected service is not available.', 'danger');
                redirect('modules/jobs/view.php?id=' . $jobId);
            }

            $serviceName = (string) $service['service_name'];
            if ($description === '') {
                $description = $serviceName;
            }
            if ($unitPrice <= 0) {
                $unitPrice = (float) $service['default_rate'];
            }
            if (!isset($_POST['gst_rate']) || trim((string) $_POST['gst_rate']) === '') {
                $gstRate = (float) $service['gst_rate'];
            }
        }

        if ($description === '' || $quantity <= 0 || $unitPrice < 0) {
            flash_set('job_error', 'Valid labor description, quantity and price are required.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        $gstRate = max(0, min(100, $gstRate));
        $totalAmount = round($quantity * $unitPrice, 2);

        $insertStmt = db()->prepare(
            'INSERT INTO job_labor
              (job_card_id, service_id, description, quantity, unit_price, gst_rate, total_amount)
             VALUES
              (:job_card_id, :service_id, :description, :quantity, :unit_price, :gst_rate, :total_amount)'
        );
        $insertStmt->execute([
            'job_card_id' => $jobId,
            'service_id' => $serviceId > 0 ? $serviceId : null,
            'description' => $description,
            'quantity' => round($quantity, 2),
            'unit_price' => round($unitPrice, 2),
            'gst_rate' => round($gstRate, 2),
            'total_amount' => $totalAmount,
        ]);

        job_recalculate_estimate($jobId);
        job_append_history(
            $jobId,
            'LABOR_ADD',
            null,
            null,
            'Labor line added',
            [
                'service_id' => $serviceId > 0 ? $serviceId : null,
                'service_name' => $serviceName,
                'description' => $description,
                'quantity' => round($quantity, 2),
                'unit_price' => round($unitPrice, 2),
            ]
        );
        log_audit('job_cards', 'add_labor', $jobId, 'Added labor line to job card');

        flash_set('job_success', 'Labor line added successfully.', 'success');
        redirect('modules/jobs/view.php?id=' . $jobId);
    }

    if ($action === 'update_labor') {
        $laborId = post_int('labor_id');
        $description = post_string('description', 255);
        $quantity = post_decimal('quantity', 0.0);
        $unitPrice = post_decimal('unit_price', 0.0);
        $gstRate = post_decimal('gst_rate', 18.0);

        if ($laborId <= 0 || $description === '' || $quantity <= 0 || $unitPrice < 0) {
            flash_set('job_error', 'Invalid labor update payload.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        $lineStmt = db()->prepare(
            'SELECT jl.id
             FROM job_labor jl
             INNER JOIN job_cards jc ON jc.id = jl.job_card_id
             WHERE jl.id = :line_id
               AND jl.job_card_id = :job_id
               AND jc.company_id = :company_id
               AND jc.garage_id = :garage_id
             LIMIT 1'
        );
        $lineStmt->execute([
            'line_id' => $laborId,
            'job_id' => $jobId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);

        if (!$lineStmt->fetch()) {
            flash_set('job_error', 'Labor line not found for this job.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        $gstRate = max(0, min(100, $gstRate));
        $totalAmount = round($quantity * $unitPrice, 2);

        $updateStmt = db()->prepare(
            'UPDATE job_labor
             SET description = :description,
                 quantity = :quantity,
                 unit_price = :unit_price,
                 gst_rate = :gst_rate,
                 total_amount = :total_amount
             WHERE id = :id
               AND job_card_id = :job_card_id'
        );
        $updateStmt->execute([
            'description' => $description,
            'quantity' => round($quantity, 2),
            'unit_price' => round($unitPrice, 2),
            'gst_rate' => round($gstRate, 2),
            'total_amount' => $totalAmount,
            'id' => $laborId,
            'job_card_id' => $jobId,
        ]);

        job_recalculate_estimate($jobId);
        job_append_history(
            $jobId,
            'LABOR_EDIT',
            null,
            null,
            'Labor line updated',
            [
                'labor_id' => $laborId,
                'description' => $description,
                'quantity' => round($quantity, 2),
                'unit_price' => round($unitPrice, 2),
            ]
        );
        log_audit('job_cards', 'update_labor', $jobId, 'Updated labor line #' . $laborId);

        flash_set('job_success', 'Labor line updated.', 'success');
        redirect('modules/jobs/view.php?id=' . $jobId);
    }

    if ($action === 'delete_labor') {
        $laborId = post_int('labor_id');
        if ($laborId <= 0) {
            flash_set('job_error', 'Invalid labor line selected.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        $deleteStmt = db()->prepare(
            'DELETE jl
             FROM job_labor jl
             INNER JOIN job_cards jc ON jc.id = jl.job_card_id
             WHERE jl.id = :line_id
               AND jl.job_card_id = :job_id
               AND jc.company_id = :company_id
               AND jc.garage_id = :garage_id'
        );
        $deleteStmt->execute([
            'line_id' => $laborId,
            'job_id' => $jobId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);

        job_recalculate_estimate($jobId);
        job_append_history(
            $jobId,
            'LABOR_REMOVE',
            null,
            null,
            'Labor line removed',
            ['labor_id' => $laborId]
        );
        log_audit('job_cards', 'delete_labor', $jobId, 'Deleted labor line #' . $laborId);

        flash_set('job_success', 'Labor line removed.', 'success');
        redirect('modules/jobs/view.php?id=' . $jobId);
    }

    if ($action === 'add_part') {
        $partId = post_int('part_id');
        $quantity = post_decimal('quantity', 0.0);
        $unitPrice = post_decimal('unit_price', -1.0);
        $gstRate = post_decimal('gst_rate', -1.0);

        if ($partId <= 0 || $quantity <= 0) {
            flash_set('job_error', 'Select part and quantity to add.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        $partStmt = db()->prepare(
            'SELECT p.id, p.part_name, p.part_sku, p.selling_price, p.gst_rate,
                    COALESCE(gi.quantity, 0) AS stock_qty
             FROM parts p
             LEFT JOIN garage_inventory gi ON gi.part_id = p.id AND gi.garage_id = :garage_id
             WHERE p.id = :part_id
               AND p.company_id = :company_id
               AND p.status_code = "ACTIVE"
             LIMIT 1'
        );
        $partStmt->execute([
            'part_id' => $partId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
        $part = $partStmt->fetch();

        if (!$part) {
            flash_set('job_error', 'Selected part is not available in this company.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        if ($unitPrice < 0) {
            $unitPrice = (float) $part['selling_price'];
        }
        if ($gstRate < 0) {
            $gstRate = (float) $part['gst_rate'];
        }

        $gstRate = max(0, min(100, $gstRate));
        $totalAmount = round($quantity * $unitPrice, 2);
        $availableQty = (float) $part['stock_qty'];

        $insertStmt = db()->prepare(
            'INSERT INTO job_parts
              (job_card_id, part_id, quantity, unit_price, gst_rate, total_amount)
             VALUES
              (:job_card_id, :part_id, :quantity, :unit_price, :gst_rate, :total_amount)'
        );
        $insertStmt->execute([
            'job_card_id' => $jobId,
            'part_id' => $partId,
            'quantity' => round($quantity, 2),
            'unit_price' => round($unitPrice, 2),
            'gst_rate' => round($gstRate, 2),
            'total_amount' => $totalAmount,
        ]);

        job_recalculate_estimate($jobId);
        job_append_history(
            $jobId,
            'PART_ADD',
            null,
            null,
            'Part line added',
            [
                'part_id' => $partId,
                'part_name' => (string) $part['part_name'],
                'quantity' => round($quantity, 2),
                'unit_price' => round($unitPrice, 2),
                'available_qty' => round($availableQty, 2),
            ]
        );
        log_audit('job_cards', 'add_part', $jobId, 'Added part #' . $partId . ' to job card');

        flash_set('job_success', 'Part line added successfully.', 'success');
        if ($availableQty < $quantity) {
            flash_set(
                'job_warning',
                'Stock warning: ' . (string) $part['part_name'] . ' requires ' . number_format($quantity, 2) . ', available ' . number_format($availableQty, 2) . '. Closing will still post stock.',
                'warning'
            );
        }

        redirect('modules/jobs/view.php?id=' . $jobId);
    }

    if ($action === 'update_part') {
        $jobPartId = post_int('job_part_id');
        $quantity = post_decimal('quantity', 0.0);
        $unitPrice = post_decimal('unit_price', 0.0);
        $gstRate = post_decimal('gst_rate', 18.0);

        if ($jobPartId <= 0 || $quantity <= 0 || $unitPrice < 0) {
            flash_set('job_error', 'Invalid part update payload.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        $lineStmt = db()->prepare(
            'SELECT jp.id, jp.part_id, p.part_name, COALESCE(gi.quantity, 0) AS stock_qty
             FROM job_parts jp
             INNER JOIN job_cards jc ON jc.id = jp.job_card_id
             INNER JOIN parts p ON p.id = jp.part_id
             LEFT JOIN garage_inventory gi ON gi.part_id = jp.part_id AND gi.garage_id = :garage_id
             WHERE jp.id = :line_id
               AND jp.job_card_id = :job_id
               AND jc.company_id = :company_id
               AND jc.garage_id = :garage_id
             LIMIT 1'
        );
        $lineStmt->execute([
            'line_id' => $jobPartId,
            'job_id' => $jobId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
        $line = $lineStmt->fetch();

        if (!$line) {
            flash_set('job_error', 'Part line not found for this job.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        $gstRate = max(0, min(100, $gstRate));
        $totalAmount = round($quantity * $unitPrice, 2);
        $availableQty = (float) $line['stock_qty'];

        $updateStmt = db()->prepare(
            'UPDATE job_parts
             SET quantity = :quantity,
                 unit_price = :unit_price,
                 gst_rate = :gst_rate,
                 total_amount = :total_amount
             WHERE id = :id
               AND job_card_id = :job_card_id'
        );
        $updateStmt->execute([
            'quantity' => round($quantity, 2),
            'unit_price' => round($unitPrice, 2),
            'gst_rate' => round($gstRate, 2),
            'total_amount' => $totalAmount,
            'id' => $jobPartId,
            'job_card_id' => $jobId,
        ]);

        job_recalculate_estimate($jobId);
        job_append_history(
            $jobId,
            'PART_EDIT',
            null,
            null,
            'Part line updated',
            [
                'job_part_id' => $jobPartId,
                'part_id' => (int) $line['part_id'],
                'quantity' => round($quantity, 2),
                'unit_price' => round($unitPrice, 2),
                'available_qty' => round($availableQty, 2),
            ]
        );
        log_audit('job_cards', 'update_part', $jobId, 'Updated part line #' . $jobPartId);

        flash_set('job_success', 'Part line updated.', 'success');
        if ($availableQty < $quantity) {
            flash_set(
                'job_warning',
                'Stock warning: ' . (string) $line['part_name'] . ' requires ' . number_format($quantity, 2) . ', available ' . number_format($availableQty, 2) . '. Closing will still post stock.',
                'warning'
            );
        }

        redirect('modules/jobs/view.php?id=' . $jobId);
    }

    if ($action === 'delete_part') {
        $jobPartId = post_int('job_part_id');
        if ($jobPartId <= 0) {
            flash_set('job_error', 'Invalid part line selected.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        $deleteStmt = db()->prepare(
            'DELETE jp
             FROM job_parts jp
             INNER JOIN job_cards jc ON jc.id = jp.job_card_id
             WHERE jp.id = :line_id
               AND jp.job_card_id = :job_id
               AND jc.company_id = :company_id
               AND jc.garage_id = :garage_id'
        );
        $deleteStmt->execute([
            'line_id' => $jobPartId,
            'job_id' => $jobId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);

        job_recalculate_estimate($jobId);
        job_append_history(
            $jobId,
            'PART_REMOVE',
            null,
            null,
            'Part line removed',
            ['job_part_id' => $jobPartId]
        );
        log_audit('job_cards', 'delete_part', $jobId, 'Deleted part line #' . $jobPartId);

        flash_set('job_success', 'Part line removed.', 'success');
        redirect('modules/jobs/view.php?id=' . $jobId);
    }
}

$job = fetch_job_details($jobId, $companyId, $garageId);
if (!$job) {
    flash_set('job_error', 'Job card not found for active garage.', 'danger');
    redirect('modules/jobs/index.php');
}

$jobStatus = job_normalize_status((string) $job['status']);
$jobLocked = job_is_locked($job);

$assignmentCandidates = $canAssign ? job_assignment_candidates($companyId, $garageId) : [];
$currentAssignments = job_current_assignments($jobId);
$currentAssignmentIds = array_map(static fn (array $row): int => (int) $row['user_id'], $currentAssignments);

$servicesMaster = fetch_services_master($companyId);
$partsMaster = fetch_parts_master($companyId, $garageId);
$laborEntries = fetch_job_labor($jobId);
$partEntries = fetch_job_parts($jobId, $garageId);
$historyEntries = fetch_job_history_timeline($jobId);

$totalsStmt = db()->prepare(
    'SELECT
        COALESCE((SELECT SUM(total_amount) FROM job_labor WHERE job_card_id = :job_id), 0) AS labor_total,
        COALESCE((SELECT SUM(total_amount) FROM job_parts WHERE job_card_id = :job_id), 0) AS parts_total'
);
$totalsStmt->execute(['job_id' => $jobId]);
$totals = $totalsStmt->fetch() ?: ['labor_total' => 0, 'parts_total' => 0];
$laborTotal = (float) ($totals['labor_total'] ?? 0);
$partsTotal = (float) ($totals['parts_total'] ?? 0);
$estimatedTotal = round($laborTotal + $partsTotal, 2);

$invoiceStmt = db()->prepare(
    'SELECT id, invoice_number, grand_total, payment_status, invoice_status
     FROM invoices
     WHERE job_card_id = :job_id
       AND company_id = :company_id
       AND garage_id = :garage_id
     LIMIT 1'
);
$invoiceStmt->execute([
    'job_id' => $jobId,
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$invoice = $invoiceStmt->fetch() ?: null;

$nextStatuses = [];
foreach (job_workflow_statuses(true) as $status) {
    if ($status === $jobStatus) {
        continue;
    }

    if (!job_can_transition($jobStatus, $status)) {
        continue;
    }

    if ($status === 'CLOSED' && !$canClose) {
        continue;
    }

    if ($status !== 'CLOSED' && !$canEdit) {
        continue;
    }

    $nextStatuses[] = $status;
}

$visData = job_fetch_vis_suggestions($companyId, $garageId, (int) $job['vehicle_id']);
$visVariant = $visData['vehicle_variant'];
$visServiceSuggestions = $visData['service_suggestions'] ?? [];
$visPartSuggestions = $visData['part_suggestions'] ?? [];
$hasVisData = $visVariant !== null || !empty($visServiceSuggestions) || !empty($visPartSuggestions);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-8">
          <h3 class="mb-0">Job Card <?= e((string) $job['job_number']); ?></h3>
          <small class="text-muted">
            Garage: <?= e((string) $job['garage_name']); ?> |
            Customer: <?= e((string) $job['customer_name']); ?> |
            Vehicle: <?= e((string) $job['registration_no']); ?>
          </small>
        </div>
        <div class="col-sm-4 text-sm-end">
          <a href="<?= e(url('modules/jobs/index.php')); ?>" class="btn btn-outline-secondary btn-sm">Back to Jobs</a>
          <?php if ($invoice): ?>
            <a href="<?= e(url('modules/billing/print_invoice.php?id=' . (int) $invoice['id'])); ?>" class="btn btn-success btn-sm" target="_blank">
              Invoice <?= e((string) $invoice['invoice_number']); ?> (<?= e((string) ($invoice['invoice_status'] ?? '')); ?>)
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if ($jobLocked): ?>
        <div class="alert alert-warning">
          This job card is locked because it is <?= e((string) $job['status']); ?> or not active. Line items and assignment edits are disabled.
        </div>
      <?php endif; ?>

      <div class="row g-3 mb-3">
        <div class="col-md-3">
          <div class="small-box text-bg-<?= e(job_status_badge_class((string) $job['status'])); ?>">
            <div class="inner">
              <h4><?= e((string) $job['status']); ?></h4>
              <p>Current Workflow State</p>
            </div>
            <span class="small-box-icon"><i class="bi bi-diagram-3"></i></span>
          </div>
        </div>
        <div class="col-md-3">
          <div class="small-box text-bg-success">
            <div class="inner">
              <h4><?= e(format_currency($laborTotal)); ?></h4>
              <p>Labor Value</p>
            </div>
            <span class="small-box-icon"><i class="bi bi-tools"></i></span>
          </div>
        </div>
        <div class="col-md-3">
          <div class="small-box text-bg-warning">
            <div class="inner">
              <h4><?= e(format_currency($partsTotal)); ?></h4>
              <p>Parts Value</p>
            </div>
            <span class="small-box-icon"><i class="bi bi-box-seam"></i></span>
          </div>
        </div>
        <div class="col-md-3">
          <div class="small-box text-bg-danger">
            <div class="inner">
              <h4><?= e(format_currency($estimatedTotal)); ?></h4>
              <p>Estimated Total</p>
            </div>
            <span class="small-box-icon"><i class="bi bi-cash-stack"></i></span>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-xl-4">
          <div class="card">
            <div class="card-header"><h3 class="card-title">Job Information</h3></div>
            <div class="card-body">
              <p class="mb-2"><strong>Status:</strong> <span class="badge text-bg-<?= e(job_status_badge_class((string) $job['status'])); ?>"><?= e((string) $job['status']); ?></span></p>
              <p class="mb-2"><strong>Priority:</strong> <span class="badge text-bg-<?= e(job_priority_badge_class((string) $job['priority'])); ?>"><?= e((string) $job['priority']); ?></span></p>
              <p class="mb-2"><strong>Opened At:</strong> <?= e((string) $job['opened_at']); ?></p>
              <p class="mb-2"><strong>Promised At:</strong> <?= e((string) ($job['promised_at'] ?? '-')); ?></p>
              <p class="mb-2"><strong>Service Advisor:</strong> <?= e((string) ($job['advisor_name'] ?? '-')); ?></p>
              <p class="mb-2"><strong>Assigned Staff:</strong> <?= e((string) (($job['assigned_staff'] ?? '') !== '' ? $job['assigned_staff'] : 'Unassigned')); ?></p>
              <p class="mb-2"><strong>Customer:</strong> <?= e((string) $job['customer_name']); ?> (<?= e((string) $job['customer_phone']); ?>)</p>
              <p class="mb-2"><strong>Vehicle:</strong> <?= e((string) $job['registration_no']); ?> | <?= e((string) $job['brand']); ?> <?= e((string) $job['model']); ?> <?= e((string) ($job['variant'] ?? '')); ?></p>
              <p class="mb-2"><strong>Complaint:</strong><br><?= nl2br(e((string) $job['complaint'])); ?></p>
              <p class="mb-0"><strong>Diagnosis:</strong><br><?= nl2br(e((string) ($job['diagnosis'] ?? '-'))); ?></p>
              <?php if (!empty($job['cancel_note'])): ?>
                <div class="alert alert-light border mt-3 mb-0"><strong>Audit Note:</strong> <?= e((string) $job['cancel_note']); ?></div>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($canAssign): ?>
            <div class="card card-info">
              <div class="card-header"><h3 class="card-title">Staff Assignment</h3></div>
              <form method="post">
                <div class="card-body">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="assign_staff">
                  <label class="form-label">Assigned Team (Multiple)</label>
                  <select name="assigned_user_ids[]" class="form-select" multiple size="6" <?= $jobLocked ? 'disabled' : ''; ?>>
                    <?php foreach ($assignmentCandidates as $staff): ?>
                      <option value="<?= (int) $staff['id']; ?>" <?= in_array((int) $staff['id'], $currentAssignmentIds, true) ? 'selected' : ''; ?>>
                        <?= e((string) $staff['name']); ?> - <?= e((string) $staff['role_name']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <small class="text-muted">Only manager/mechanic in this garage are assignable.</small>
                </div>
                <div class="card-footer">
                  <button type="submit" class="btn btn-info" <?= $jobLocked ? 'disabled' : ''; ?>>Save Assignment</button>
                </div>
              </form>
            </div>
          <?php endif; ?>

          <?php if ($canEdit || $canClose): ?>
            <div class="card card-primary">
              <div class="card-header"><h3 class="card-title">Workflow Transition</h3></div>
              <form method="post">
                <div class="card-body">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="transition_status">
                  <?php if (empty($nextStatuses)): ?>
                    <p class="text-muted mb-0">No valid next status available from current state.</p>
                  <?php else: ?>
                    <div class="mb-3">
                      <label class="form-label">Next Status</label>
                      <select id="next-status-select" name="next_status" class="form-select" required <?= $jobLocked ? 'disabled' : ''; ?>>
                        <?php foreach ($nextStatuses as $status): ?>
                          <option value="<?= e($status); ?>"><?= e($status); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div>
                      <label class="form-label">Audit Note</label>
                      <textarea id="status-note-input" name="status_note" class="form-control" rows="2" placeholder="Optional note (required for CANCELLED)"></textarea>
                    </div>
                  <?php endif; ?>
                </div>
                <div class="card-footer">
                  <button type="submit" class="btn btn-primary" <?= $jobLocked || empty($nextStatuses) ? 'disabled' : ''; ?>>Apply Transition</button>
                </div>
              </form>
            </div>
          <?php endif; ?>

          <?php if (($canEdit || $canClose) && normalize_status_code((string) ($job['status_code'] ?? 'ACTIVE')) !== 'DELETED'): ?>
            <div class="card card-outline card-danger">
              <div class="card-header"><h3 class="card-title">Soft Delete Job Card</h3></div>
              <form method="post" data-confirm="Soft delete this job card? It will be hidden from normal job lists.">
                <div class="card-body">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="soft_delete">
                  <label class="form-label">Audit Note (Required)</label>
                  <textarea name="delete_note" class="form-control" rows="2" required></textarea>
                </div>
                <div class="card-footer">
                  <button type="submit" class="btn btn-outline-danger">Soft Delete</button>
                </div>
              </form>
            </div>
          <?php endif; ?>

          <div class="card card-outline card-info <?= $hasVisData ? '' : 'collapsed-card'; ?>">
            <div class="card-header">
              <h3 class="card-title">VIS Suggestions (Optional)</h3>
              <div class="card-tools">
                <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse">
                  <i class="bi bi-dash-lg"></i>
                </button>
              </div>
            </div>
            <div class="card-body">
              <?php if (!$hasVisData): ?>
                <p class="text-muted mb-0">No VIS mapping found for this vehicle. Continue with manual service/parts entry.</p>
              <?php else: ?>
                <?php if ($visVariant): ?>
                  <p class="mb-3">
                    <strong>Vehicle Variant:</strong>
                    <?= e((string) $visVariant['brand_name']); ?> /
                    <?= e((string) $visVariant['model_name']); ?> /
                    <?= e((string) $visVariant['variant_name']); ?>
                  </p>
                <?php endif; ?>

                <h6 class="mb-2">Suggested Services</h6>
                <?php if (empty($visServiceSuggestions)): ?>
                  <p class="text-muted small">No service suggestions.</p>
                <?php else: ?>
                  <ul class="list-group mb-3">
                    <?php foreach ($visServiceSuggestions as $suggestion): ?>
                      <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                          <div class="fw-semibold"><?= e((string) $suggestion['service_name']); ?></div>
                          <small class="text-muted">
                            Code: <?= e((string) $suggestion['service_code']); ?> |
                            Default Rate: <?= e(format_currency((float) $suggestion['default_rate'])); ?> |
                            GST: <?= e((string) $suggestion['gst_rate']); ?>%
                          </small>
                        </div>
                        <?php if ($canEdit && !$jobLocked): ?>
                          <form method="post" class="d-inline">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="add_labor">
                            <input type="hidden" name="service_id" value="<?= (int) $suggestion['service_id']; ?>">
                            <input type="hidden" name="description" value="<?= e((string) $suggestion['service_name']); ?>">
                            <input type="hidden" name="quantity" value="1">
                            <input type="hidden" name="unit_price" value="<?= e((string) $suggestion['default_rate']); ?>">
                            <input type="hidden" name="gst_rate" value="<?= e((string) $suggestion['gst_rate']); ?>">
                            <button type="submit" class="btn btn-sm btn-outline-primary">Add</button>
                          </form>
                        <?php endif; ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>

                <h6 class="mb-2">Compatible Parts</h6>
                <?php if (empty($visPartSuggestions)): ?>
                  <p class="text-muted small mb-0">No part suggestions.</p>
                <?php else: ?>
                  <ul class="list-group mb-0">
                    <?php foreach ($visPartSuggestions as $suggestion): ?>
                      <?php $stockQty = (float) ($suggestion['stock_qty'] ?? 0); ?>
                      <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                          <div class="fw-semibold"><?= e((string) $suggestion['part_name']); ?> (<?= e((string) $suggestion['part_sku']); ?>)</div>
                          <small class="text-muted">
                            Price: <?= e(format_currency((float) $suggestion['selling_price'])); ?> |
                            GST: <?= e((string) $suggestion['gst_rate']); ?>% |
                            Stock: <span class="<?= $stockQty > 0 ? 'text-success' : 'text-danger'; ?>"><?= e(number_format($stockQty, 2)); ?></span>
                          </small>
                        </div>
                        <?php if ($canEdit && !$jobLocked): ?>
                          <form method="post" class="d-inline">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="add_part">
                            <input type="hidden" name="part_id" value="<?= (int) $suggestion['part_id']; ?>">
                            <input type="hidden" name="quantity" value="1">
                            <input type="hidden" name="unit_price" value="<?= e((string) $suggestion['selling_price']); ?>">
                            <input type="hidden" name="gst_rate" value="<?= e((string) $suggestion['gst_rate']); ?>">
                            <button type="submit" class="btn btn-sm btn-outline-primary">Add</button>
                          </form>
                        <?php endif; ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="col-xl-8">
          <div class="card card-success">
            <div class="card-header"><h3 class="card-title">Service / Labour Lines</h3></div>
            <?php if ($canEdit): ?>
              <form method="post">
                <div class="card-body border-bottom row g-2">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="add_labor">
                  <div class="col-md-4">
                    <label class="form-label">Service Master</label>
                    <select id="add-labor-service" name="service_id" class="form-select" <?= $jobLocked ? 'disabled' : ''; ?>>
                      <option value="0">Custom Labor Item</option>
                      <?php foreach ($servicesMaster as $service): ?>
                        <option
                          value="<?= (int) $service['id']; ?>"
                          data-name="<?= e((string) $service['service_name']); ?>"
                          data-rate="<?= e((string) $service['default_rate']); ?>"
                          data-gst="<?= e((string) $service['gst_rate']); ?>"
                        >
                          <?= e((string) $service['service_code']); ?> - <?= e((string) $service['service_name']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Description</label>
                    <input id="add-labor-description" type="text" name="description" class="form-control" required <?= $jobLocked ? 'disabled' : ''; ?>>
                  </div>
                  <div class="col-md-1">
                    <label class="form-label">Qty</label>
                    <input type="number" step="0.01" min="0.01" name="quantity" class="form-control" value="1" required <?= $jobLocked ? 'disabled' : ''; ?>>
                  </div>
                  <div class="col-md-1">
                    <label class="form-label">Rate</label>
                    <input id="add-labor-rate" type="number" step="0.01" min="0" name="unit_price" class="form-control" value="0" required <?= $jobLocked ? 'disabled' : ''; ?>>
                  </div>
                  <div class="col-md-1">
                    <label class="form-label">GST%</label>
                    <input id="add-labor-gst" type="number" step="0.01" min="0" max="100" name="gst_rate" class="form-control" value="18" required <?= $jobLocked ? 'disabled' : ''; ?>>
                  </div>
                  <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-success w-100" <?= $jobLocked ? 'disabled' : ''; ?>>Add</button>
                  </div>
                </div>
              </form>
            <?php endif; ?>

            <div class="card-body table-responsive p-0">
              <table class="table table-sm table-striped mb-0">
                <thead>
                  <tr>
                    <th>Service</th>
                    <th>Description</th>
                    <th>Qty</th>
                    <th>Rate</th>
                    <th>GST%</th>
                    <th>Total</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($laborEntries)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No labor lines added yet.</td></tr>
                  <?php else: ?>
                    <?php foreach ($laborEntries as $line): ?>
                      <tr>
                        <td>
                          <?php if (!empty($line['service_code'])): ?>
                            <?= e((string) $line['service_code']); ?> - <?= e((string) ($line['service_name'] ?? '')); ?>
                          <?php else: ?>
                            <span class="text-muted">Custom</span>
                          <?php endif; ?>
                        </td>
                        <td><?= e((string) $line['description']); ?></td>
                        <td><?= e(number_format((float) $line['quantity'], 2)); ?></td>
                        <td><?= e(format_currency((float) $line['unit_price'])); ?></td>
                        <td><?= e(number_format((float) $line['gst_rate'], 2)); ?></td>
                        <td><?= e(format_currency((float) $line['total_amount'])); ?></td>
                        <td>
                          <?php if ($canEdit && !$jobLocked): ?>
                            <button
                              class="btn btn-sm btn-outline-primary"
                              type="button"
                              data-bs-toggle="collapse"
                              data-bs-target="#labor-edit-<?= (int) $line['id']; ?>"
                              aria-expanded="false"
                            >
                              Edit
                            </button>
                            <form method="post" class="d-inline">
                              <?= csrf_field(); ?>
                              <input type="hidden" name="_action" value="delete_labor">
                              <input type="hidden" name="labor_id" value="<?= (int) $line['id']; ?>">
                              <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this labor line?');">Remove</button>
                            </form>
                          <?php else: ?>
                            <span class="text-muted">Locked</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                      <?php if ($canEdit && !$jobLocked): ?>
                        <tr class="collapse" id="labor-edit-<?= (int) $line['id']; ?>">
                          <td colspan="7">
                            <form method="post" class="row g-2 p-2 bg-light">
                              <?= csrf_field(); ?>
                              <input type="hidden" name="_action" value="update_labor">
                              <input type="hidden" name="labor_id" value="<?= (int) $line['id']; ?>">
                              <div class="col-md-5">
                                <input type="text" name="description" class="form-control form-control-sm" value="<?= e((string) $line['description']); ?>" required>
                              </div>
                              <div class="col-md-2">
                                <input type="number" step="0.01" min="0.01" name="quantity" class="form-control form-control-sm" value="<?= e((string) $line['quantity']); ?>" required>
                              </div>
                              <div class="col-md-2">
                                <input type="number" step="0.01" min="0" name="unit_price" class="form-control form-control-sm" value="<?= e((string) $line['unit_price']); ?>" required>
                              </div>
                              <div class="col-md-2">
                                <input type="number" step="0.01" min="0" max="100" name="gst_rate" class="form-control form-control-sm" value="<?= e((string) $line['gst_rate']); ?>" required>
                              </div>
                              <div class="col-md-1">
                                <button type="submit" class="btn btn-sm btn-primary w-100">Save</button>
                              </div>
                            </form>
                          </td>
                        </tr>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="card card-warning">
            <div class="card-header"><h3 class="card-title">Parts Lines</h3></div>
            <?php if ($canEdit): ?>
              <form method="post">
                <div class="card-body border-bottom row g-2">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="add_part">
                  <div class="col-md-5">
                    <label class="form-label">Part Master</label>
                    <select id="add-part-select" name="part_id" class="form-select" required <?= $jobLocked ? 'disabled' : ''; ?>>
                      <option value="">Select Part</option>
                      <?php foreach ($partsMaster as $part): ?>
                        <option
                          value="<?= (int) $part['id']; ?>"
                          data-price="<?= e((string) $part['selling_price']); ?>"
                          data-gst="<?= e((string) $part['gst_rate']); ?>"
                          data-stock="<?= e((string) $part['stock_qty']); ?>"
                        >
                          <?= e((string) $part['part_name']); ?> (<?= e((string) $part['part_sku']); ?>) | Stock <?= e(number_format((float) $part['stock_qty'], 2)); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <small id="add-part-stock-hint" class="text-muted"></small>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Qty</label>
                    <input type="number" step="0.01" min="0.01" name="quantity" class="form-control" value="1" required <?= $jobLocked ? 'disabled' : ''; ?>>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Rate</label>
                    <input id="add-part-price" type="number" step="0.01" min="0" name="unit_price" class="form-control" value="0" required <?= $jobLocked ? 'disabled' : ''; ?>>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">GST%</label>
                    <input id="add-part-gst" type="number" step="0.01" min="0" max="100" name="gst_rate" class="form-control" value="18" required <?= $jobLocked ? 'disabled' : ''; ?>>
                  </div>
                  <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-warning w-100" <?= $jobLocked ? 'disabled' : ''; ?>>Add</button>
                  </div>
                </div>
              </form>
            <?php endif; ?>

            <div class="card-body table-responsive p-0">
              <table class="table table-sm table-striped mb-0">
                <thead>
                  <tr>
                    <th>Part</th>
                    <th>Qty</th>
                    <th>Rate</th>
                    <th>GST%</th>
                    <th>Total</th>
                    <th>Garage Stock</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($partEntries)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No parts added yet.</td></tr>
                  <?php else: ?>
                    <?php foreach ($partEntries as $line): ?>
                      <?php
                        $lineQty = (float) $line['quantity'];
                        $lineStock = (float) $line['stock_qty'];
                        $stockClass = $lineStock >= $lineQty ? 'text-success' : 'text-danger';
                      ?>
                      <tr>
                        <td><?= e((string) $line['part_name']); ?> (<?= e((string) $line['part_sku']); ?>)</td>
                        <td><?= e(number_format($lineQty, 2)); ?></td>
                        <td><?= e(format_currency((float) $line['unit_price'])); ?></td>
                        <td><?= e(number_format((float) $line['gst_rate'], 2)); ?></td>
                        <td><?= e(format_currency((float) $line['total_amount'])); ?></td>
                        <td>
                          <span class="<?= e($stockClass); ?>"><?= e(number_format($lineStock, 2)); ?></span>
                        </td>
                        <td>
                          <?php if ($canEdit && !$jobLocked): ?>
                            <button
                              class="btn btn-sm btn-outline-primary"
                              type="button"
                              data-bs-toggle="collapse"
                              data-bs-target="#part-edit-<?= (int) $line['id']; ?>"
                              aria-expanded="false"
                            >
                              Edit
                            </button>
                            <form method="post" class="d-inline">
                              <?= csrf_field(); ?>
                              <input type="hidden" name="_action" value="delete_part">
                              <input type="hidden" name="job_part_id" value="<?= (int) $line['id']; ?>">
                              <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this part line?');">Remove</button>
                            </form>
                          <?php else: ?>
                            <span class="text-muted">Locked</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                      <?php if ($canEdit && !$jobLocked): ?>
                        <tr class="collapse" id="part-edit-<?= (int) $line['id']; ?>">
                          <td colspan="7">
                            <form method="post" class="row g-2 p-2 bg-light">
                              <?= csrf_field(); ?>
                              <input type="hidden" name="_action" value="update_part">
                              <input type="hidden" name="job_part_id" value="<?= (int) $line['id']; ?>">
                              <div class="col-md-3">
                                <input type="number" step="0.01" min="0.01" name="quantity" class="form-control form-control-sm" value="<?= e((string) $line['quantity']); ?>" required>
                              </div>
                              <div class="col-md-3">
                                <input type="number" step="0.01" min="0" name="unit_price" class="form-control form-control-sm" value="<?= e((string) $line['unit_price']); ?>" required>
                              </div>
                              <div class="col-md-3">
                                <input type="number" step="0.01" min="0" max="100" name="gst_rate" class="form-control form-control-sm" value="<?= e((string) $line['gst_rate']); ?>" required>
                              </div>
                              <div class="col-md-3">
                                <button type="submit" class="btn btn-sm btn-primary w-100">Save</button>
                              </div>
                            </form>
                          </td>
                        </tr>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="card card-outline card-secondary">
            <div class="card-header"><h3 class="card-title">Job History Timeline</h3></div>
            <div class="card-body p-0">
              <ul class="list-group list-group-flush">
                <?php if (empty($historyEntries)): ?>
                  <li class="list-group-item text-muted">No history events found.</li>
                <?php else: ?>
                  <?php foreach ($historyEntries as $entry): ?>
                    <li class="list-group-item">
                      <div class="d-flex justify-content-between">
                        <div>
                          <span class="badge text-bg-secondary"><?= e((string) $entry['action_type']); ?></span>
                          <?php if (!empty($entry['from_status']) || !empty($entry['to_status'])): ?>
                            <span class="ms-2 text-muted"><?= e((string) ($entry['from_status'] ?? '-')); ?> -> <?= e((string) ($entry['to_status'] ?? '-')); ?></span>
                          <?php endif; ?>
                        </div>
                        <small class="text-muted"><?= e((string) $entry['created_at']); ?></small>
                      </div>
                      <?php if (!empty($entry['action_note'])): ?>
                        <div class="mt-1"><?= e((string) $entry['action_note']); ?></div>
                      <?php endif; ?>
                      <small class="text-muted">By: <?= e((string) ($entry['actor_name'] ?? 'System')); ?></small>
                    </li>
                  <?php endforeach; ?>
                <?php endif; ?>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<script>
  (function () {
    var laborServiceSelect = document.getElementById('add-labor-service');
    var laborDescription = document.getElementById('add-labor-description');
    var laborRate = document.getElementById('add-labor-rate');
    var laborGst = document.getElementById('add-labor-gst');

    if (laborServiceSelect && laborDescription && laborRate && laborGst) {
      laborServiceSelect.addEventListener('change', function () {
        var selected = laborServiceSelect.options[laborServiceSelect.selectedIndex];
        if (!selected) {
          return;
        }

        if (selected.value !== '0') {
          laborDescription.value = selected.getAttribute('data-name') || laborDescription.value;
          laborRate.value = selected.getAttribute('data-rate') || laborRate.value;
          laborGst.value = selected.getAttribute('data-gst') || laborGst.value;
        }
      });
    }

    var partSelect = document.getElementById('add-part-select');
    var partPrice = document.getElementById('add-part-price');
    var partGst = document.getElementById('add-part-gst');
    var partStockHint = document.getElementById('add-part-stock-hint');

    if (partSelect && partPrice && partGst && partStockHint) {
      partSelect.addEventListener('change', function () {
        var selected = partSelect.options[partSelect.selectedIndex];
        if (!selected) {
          return;
        }

        partPrice.value = selected.getAttribute('data-price') || partPrice.value;
        partGst.value = selected.getAttribute('data-gst') || partGst.value;
        var stock = selected.getAttribute('data-stock') || '0';
        partStockHint.textContent = 'Available stock in this garage: ' + stock;
      });
    }

    var nextStatusSelect = document.getElementById('next-status-select');
    var statusNoteInput = document.getElementById('status-note-input');

    function toggleStatusNoteRequirement() {
      if (!nextStatusSelect || !statusNoteInput) {
        return;
      }

      if (nextStatusSelect.value === 'CANCELLED') {
        statusNoteInput.required = true;
        statusNoteInput.placeholder = 'Cancellation note is required.';
      } else {
        statusNoteInput.required = false;
        statusNoteInput.placeholder = 'Optional note (required for CANCELLED)';
      }
    }

    if (nextStatusSelect && statusNoteInput) {
      nextStatusSelect.addEventListener('change', toggleStatusNoteRequirement);
      toggleStatusNoteRequirement();
    }
  })();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
