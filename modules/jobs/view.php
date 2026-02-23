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

$canCreate = has_permission('job.create') || has_permission('job.manage');
$canEdit = has_permission('job.edit') || has_permission('job.update') || has_permission('job.manage');
$canAssign = has_permission('job.assign') || has_permission('job.manage');
$canClose = has_permission('job.close') || has_permission('job.manage');
$canConditionPhotoUpload = $canEdit || $canCreate;
$jobCardColumns = table_columns('job_cards');
$jobOdometerEnabled = in_array('odometer_km', $jobCardColumns, true);
$jobLaborColumns = table_columns('job_labor');
$outsourceExpectedReturnSupported = in_array('outsource_expected_return_date', $jobLaborColumns, true);
$outsourcedModuleReady = table_columns('outsourced_works') !== [] && table_columns('outsourced_work_payments') !== [];

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

function normalize_labor_execution_type(?string $value): string
{
    $normalized = strtoupper(trim((string) $value));
    return $normalized === 'OUTSOURCED' ? 'OUTSOURCED' : 'IN_HOUSE';
}

function normalize_outsource_payable_status(?string $value): string
{
    $normalized = strtoupper(trim((string) $value));
    return $normalized === 'PAID' ? 'PAID' : 'UNPAID';
}

function normalize_outsourced_work_status(?string $value): string
{
    $normalized = strtoupper(trim((string) $value));
    return in_array($normalized, ['SENT', 'RECEIVED', 'VERIFIED', 'PAYABLE', 'PAID'], true) ? $normalized : 'SENT';
}

function outsourced_work_status_badge_class(string $status): string
{
    return match (normalize_outsourced_work_status($status)) {
        'SENT' => 'secondary',
        'RECEIVED' => 'info',
        'VERIFIED' => 'primary',
        'PAYABLE' => 'warning',
        'PAID' => 'success',
        default => 'secondary',
    };
}

function parse_iso_date_input(?string $value): ?string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return null;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $raw));
    if (!checkdate($month, $day, $year)) {
        return null;
    }

    return $raw;
}

function outsourced_work_tables_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $ready = table_columns('outsourced_works') !== [] && table_columns('outsourced_work_payments') !== [];
    return $ready;
}

function fetch_linked_outsourced_work(int $companyId, int $garageId, int $laborId): ?array
{
    if (!outsourced_work_tables_ready() || $companyId <= 0 || $garageId <= 0 || $laborId <= 0) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT ow.*,
                COALESCE(pay.total_paid, 0) AS paid_amount
         FROM outsourced_works ow
         LEFT JOIN (
            SELECT outsourced_work_id, SUM(amount) AS total_paid
            FROM outsourced_work_payments
            GROUP BY outsourced_work_id
         ) pay ON pay.outsourced_work_id = ow.id
         WHERE ow.company_id = :company_id
           AND ow.garage_id = :garage_id
           AND ow.job_labor_id = :job_labor_id
           AND ow.status_code = "ACTIVE"
         LIMIT 1'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'job_labor_id' => $laborId,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function sync_linked_outsourced_work(
    int $companyId,
    int $garageId,
    int $jobId,
    int $laborId,
    int $vendorId,
    string $partnerName,
    string $description,
    float $agreedCost,
    ?string $expectedReturnDate,
    int $userId
): ?int {
    if (!outsourced_work_tables_ready() || $companyId <= 0 || $garageId <= 0 || $jobId <= 0 || $laborId <= 0) {
        return null;
    }

    $pdo = db();
    $existingStmt = $pdo->prepare(
        'SELECT ow.id, ow.current_status, ow.payable_at, ow.paid_at,
                COALESCE(pay.total_paid, 0) AS paid_amount
         FROM outsourced_works ow
         LEFT JOIN (
            SELECT outsourced_work_id, SUM(amount) AS total_paid
            FROM outsourced_work_payments
            GROUP BY outsourced_work_id
         ) pay ON pay.outsourced_work_id = ow.id
         WHERE ow.company_id = :company_id
           AND ow.garage_id = :garage_id
           AND ow.job_labor_id = :job_labor_id
         LIMIT 1'
    );
    $existingStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'job_labor_id' => $laborId,
    ]);
    $existing = $existingStmt->fetch() ?: null;

    $now = date('Y-m-d H:i:s');
    if ($existing) {
        $paidAmount = round((float) ($existing['paid_amount'] ?? 0), 2);
        if ($agreedCost + 0.009 < $paidAmount) {
            return null;
        }

        $status = normalize_outsourced_work_status((string) ($existing['current_status'] ?? 'SENT'));
        if ($status === 'PAID' && $paidAmount + 0.009 < $agreedCost) {
            $status = 'PAYABLE';
        }
        if ($paidAmount > 0 && !in_array($status, ['PAYABLE', 'PAID'], true)) {
            $status = 'PAYABLE';
        }
        if ($agreedCost > 0 && $paidAmount + 0.009 >= $agreedCost) {
            $status = 'PAID';
        }

        $payableAt = !empty($existing['payable_at']) ? (string) $existing['payable_at'] : null;
        $paidAt = !empty($existing['paid_at']) ? (string) $existing['paid_at'] : null;
        if ($status === 'PAYABLE' && $payableAt === null) {
            $payableAt = $now;
        }
        if ($status === 'PAID') {
            if ($payableAt === null) {
                $payableAt = $now;
            }
            if ($paidAt === null) {
                $paidAt = $now;
            }
        } else {
            $paidAt = null;
        }

        $updateStmt = $pdo->prepare(
            'UPDATE outsourced_works
             SET vendor_id = :vendor_id,
                 partner_name = :partner_name,
                 service_description = :service_description,
                 agreed_cost = :agreed_cost,
                 expected_return_date = :expected_return_date,
                 current_status = :current_status,
                 payable_at = :payable_at,
                 paid_at = :paid_at,
                 status_code = "ACTIVE",
                 deleted_at = NULL,
                 updated_by = :updated_by
             WHERE id = :id'
        );
        $updateStmt->execute([
            'vendor_id' => $vendorId > 0 ? $vendorId : null,
            'partner_name' => $partnerName,
            'service_description' => $description,
            'agreed_cost' => round($agreedCost, 2),
            'expected_return_date' => $expectedReturnDate,
            'current_status' => $status,
            'payable_at' => $payableAt,
            'paid_at' => $paidAt,
            'updated_by' => $userId > 0 ? $userId : null,
            'id' => (int) $existing['id'],
        ]);
        return (int) $existing['id'];
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO outsourced_works
          (company_id, garage_id, job_card_id, job_labor_id, vendor_id, partner_name, service_description, agreed_cost,
           expected_return_date, current_status, sent_at, created_by, updated_by)
         VALUES
          (:company_id, :garage_id, :job_card_id, :job_labor_id, :vendor_id, :partner_name, :service_description, :agreed_cost,
           :expected_return_date, "SENT", :sent_at, :created_by, :updated_by)'
    );
    $insertStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'job_card_id' => $jobId,
        'job_labor_id' => $laborId,
        'vendor_id' => $vendorId > 0 ? $vendorId : null,
        'partner_name' => $partnerName,
        'service_description' => $description,
        'agreed_cost' => round($agreedCost, 2),
        'expected_return_date' => $expectedReturnDate,
        'sent_at' => $now,
        'created_by' => $userId > 0 ? $userId : null,
        'updated_by' => $userId > 0 ? $userId : null,
    ]);

    return (int) $pdo->lastInsertId();
}

function soft_delete_linked_outsourced_work(int $companyId, int $garageId, int $laborId, int $userId): bool
{
    if (!outsourced_work_tables_ready() || $companyId <= 0 || $garageId <= 0 || $laborId <= 0) {
        return true;
    }

    $work = fetch_linked_outsourced_work($companyId, $garageId, $laborId);
    if (!$work) {
        return true;
    }

    $paidAmount = round((float) ($work['paid_amount'] ?? 0), 2);
    if ($paidAmount > 0.009) {
        return false;
    }

    $stmt = db()->prepare(
        'UPDATE outsourced_works
         SET status_code = "DELETED",
             deleted_at = NOW(),
             updated_by = :updated_by
         WHERE id = :id
           AND company_id = :company_id
           AND garage_id = :garage_id'
    );
    $stmt->execute([
        'updated_by' => $userId > 0 ? $userId : null,
        'id' => (int) $work['id'],
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);

    return true;
}

function fetch_outsource_vendor(int $companyId, int $vendorId): ?array
{
    if ($companyId <= 0 || $vendorId <= 0) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT id, vendor_name, vendor_code
         FROM vendors
         WHERE id = :id
           AND company_id = :company_id
           AND status_code = "ACTIVE"
         LIMIT 1'
    );
    $stmt->execute([
        'id' => $vendorId,
        'company_id' => $companyId,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
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
    $selectExtras = '';
    $joinExtras = '';
    if (outsourced_work_tables_ready()) {
        $selectExtras = ',
                ow.id AS outsourced_work_id,
                ow.current_status AS outsourced_work_status,
                ow.expected_return_date AS outsourced_work_expected_return_date,
                ow.agreed_cost AS outsourced_work_agreed_cost,
                ow.payable_at AS outsourced_work_payable_at,
                ow.paid_at AS outsourced_work_paid_at,
                COALESCE(owp.total_paid, 0) AS outsourced_work_paid_amount,
                GREATEST(ow.agreed_cost - COALESCE(owp.total_paid, 0), 0) AS outsourced_work_outstanding';
        $joinExtras = '
         LEFT JOIN outsourced_works ow ON ow.job_labor_id = jl.id AND ow.status_code = "ACTIVE"
         LEFT JOIN (
            SELECT outsourced_work_id, SUM(amount) AS total_paid
            FROM outsourced_work_payments
            GROUP BY outsourced_work_id
         ) owp ON owp.outsourced_work_id = ow.id';
    }

    $stmt = db()->prepare(
        'SELECT jl.*, s.service_name, s.service_code, s.category_id AS service_category_id,
                sc.category_name AS service_category_name,
                v.vendor_name AS outsource_vendor_name,
                up.name AS outsource_paid_by_name' . $selectExtras . '
         FROM job_labor jl
         LEFT JOIN services s ON s.id = jl.service_id
         LEFT JOIN service_categories sc ON sc.id = s.category_id AND sc.company_id = s.company_id
         LEFT JOIN vendors v ON v.id = jl.outsource_vendor_id
         LEFT JOIN users up ON up.id = jl.outsource_paid_by' . $joinExtras . '
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
        'SELECT s.id, s.service_code, s.service_name, s.default_rate, s.gst_rate, s.category_id,
                sc.category_name
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

function fetch_service_categories_for_labor(int $companyId): array
{
    $stmt = db()->prepare(
        'SELECT sc.id, sc.category_name, sc.category_code, sc.status_code,
                (SELECT COUNT(*)
                 FROM services s
                 WHERE s.company_id = sc.company_id
                   AND s.category_id = sc.id
                   AND s.status_code = "ACTIVE") AS active_service_count
         FROM service_categories sc
         WHERE sc.company_id = :company_id
           AND sc.status_code = "ACTIVE"
         ORDER BY sc.category_name ASC'
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

function fetch_outsource_vendors(int $companyId): array
{
    $stmt = db()->prepare(
        'SELECT id, vendor_name, vendor_code
         FROM vendors
         WHERE company_id = :company_id
           AND status_code = "ACTIVE"
         ORDER BY vendor_name ASC'
    );
    $stmt->execute(['company_id' => $companyId]);
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

    if ($action === 'upload_condition_photos') {
        if (!$canConditionPhotoUpload) {
            flash_set('job_error', 'You do not have permission to upload condition photos.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#condition-photos');
        }
        if (!job_condition_photo_feature_ready()) {
            flash_set('job_error', 'Condition photo storage is not ready. Please run DB upgrade.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#condition-photos');
        }

        $photoNote = post_string('condition_photo_note', 255);
        $uploadedFiles = job_condition_photo_normalize_uploads($_FILES['condition_images'] ?? null);
        if ($uploadedFiles === []) {
            flash_set('job_error', 'Select at least one image file to upload.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#condition-photos');
        }

        $uploadedCount = 0;
        $uploadedBytes = 0;
        $errors = [];
        $maxFilesPerRequest = 10;

        foreach ($uploadedFiles as $index => $uploadedFile) {
            if ($index >= $maxFilesPerRequest) {
                $errors[] = 'Only first ' . $maxFilesPerRequest . ' files were processed in one upload.';
                break;
            }

            if ((int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $uploadResult = job_condition_photo_store_upload(
                $uploadedFile,
                $companyId,
                $garageId,
                $jobId,
                $userId,
                $photoNote
            );
            if ((bool) ($uploadResult['ok'] ?? false)) {
                $uploadedCount++;
                $uploadedBytes += (int) ($uploadResult['file_size_bytes'] ?? 0);
            } else {
                $errors[] = (string) ($uploadResult['message'] ?? 'Unable to upload one of the images.');
            }
        }

        if ($uploadedCount > 0) {
            $sizeMb = round($uploadedBytes / 1048576, 2);
            flash_set('job_success', 'Uploaded ' . $uploadedCount . ' condition photo(s). (~' . number_format($sizeMb, 2) . ' MB)', 'success');
            job_append_history($jobId, 'UPLOAD_CONDITION_PHOTO', null, null, 'Uploaded condition photo(s)', [
                'count' => $uploadedCount,
                'bytes' => $uploadedBytes,
            ]);
            log_audit('job_cards', 'upload_condition_photos', $jobId, 'Uploaded job condition photos', [
                'entity' => 'job_condition_photos',
                'source' => 'UI',
                'metadata' => [
                    'count' => $uploadedCount,
                    'bytes' => $uploadedBytes,
                ],
            ]);
        }

        if ($uploadedCount === 0 && $errors === []) {
            $errors[] = 'No valid files were uploaded.';
        }

        if ($errors !== []) {
            $previewErrors = array_slice(array_values(array_unique(array_filter($errors))), 0, 2);
            flash_set('job_warning', implode(' | ', $previewErrors), 'warning');
        }

        redirect('modules/jobs/view.php?id=' . $jobId . '#condition-photos');
    }

    if ($action === 'delete_condition_photo') {
        if (!$canConditionPhotoUpload) {
            flash_set('job_error', 'You do not have permission to delete condition photos.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#condition-photos');
        }
        if (!job_condition_photo_feature_ready()) {
            flash_set('job_error', 'Condition photo storage is not ready. Please run DB upgrade.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#condition-photos');
        }

        $photoId = post_int('photo_id');
        $deleteResult = job_condition_photo_delete($photoId, $companyId, $garageId);
        if (!(bool) ($deleteResult['ok'] ?? false)) {
            flash_set('job_error', (string) ($deleteResult['message'] ?? 'Unable to delete condition photo.'), 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#condition-photos');
        }

        flash_set('job_success', 'Condition photo deleted.', 'success');
        if (!(bool) ($deleteResult['file_deleted'] ?? false)) {
            flash_set('job_warning', 'Photo metadata was deleted, but file was not found on server.', 'warning');
        }
        job_append_history($jobId, 'DELETE_CONDITION_PHOTO', null, null, 'Deleted condition photo', ['photo_id' => $photoId]);
        log_audit('job_cards', 'delete_condition_photo', $jobId, 'Deleted job condition photo', [
            'entity' => 'job_condition_photos',
            'source' => 'UI',
            'metadata' => [
                'photo_id' => $photoId,
                'file_deleted' => (bool) ($deleteResult['file_deleted'] ?? false),
            ],
        ]);
        redirect('modules/jobs/view.php?id=' . $jobId . '#condition-photos');
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
        $reminderPreviewRows = [];
        $reminderOverrides = [];

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
            $reminderPreviewRows = service_reminder_build_preview_for_job($companyId, $garageId, $jobId);
            $reminderOverrides = service_reminder_parse_close_override_payload($_POST, $reminderPreviewRows);
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

        $reminderResult = [
            'created_count' => 0,
            'disabled_count' => 0,
            'created_types' => [],
            'warnings' => [],
        ];
        if ($targetStatus === 'CLOSED') {
            $reminderResult = service_reminder_apply_on_job_close(
                $jobId,
                $companyId,
                $garageId,
                $userId,
                $reminderOverrides
            );
        }

        job_append_history(
            $jobId,
            'STATUS_CHANGE',
            $currentStatus,
            $targetStatus,
            $statusNote !== '' ? $statusNote : null,
            [
                'inventory_warnings' => count($inventoryWarnings),
                'reminders_created' => (int) ($reminderResult['created_count'] ?? 0),
                'reminders_disabled' => (int) ($reminderResult['disabled_count'] ?? 0),
            ]
        );
        $jobAfterWrite = fetch_job_row($jobId, $companyId, $garageId);
        $auditAction = 'status_change';
        $auditSource = 'UI';
        if ($targetStatus === 'CLOSED') {
            $auditAction = 'close';
            $auditSource = 'JOB-CLOSE';
        } elseif ($targetStatus === 'CANCELLED') {
            $auditAction = 'cancel';
        }
        log_audit('job_cards', $auditAction, $jobId, 'Status changed from ' . $currentStatus . ' to ' . $targetStatus, [
            'entity' => 'job_card',
            'source' => $auditSource,
            'before' => [
                'status' => (string) ($jobForWrite['status'] ?? ''),
                'status_code' => (string) ($jobForWrite['status_code'] ?? ''),
                'closed_at' => (string) ($jobForWrite['closed_at'] ?? ''),
                'completed_at' => (string) ($jobForWrite['completed_at'] ?? ''),
                'cancel_note' => (string) ($jobForWrite['cancel_note'] ?? ''),
            ],
            'after' => [
                'status' => (string) ($jobAfterWrite['status'] ?? $targetStatus),
                'status_code' => (string) ($jobAfterWrite['status_code'] ?? ($targetStatus === 'CANCELLED' ? 'INACTIVE' : (string) ($jobForWrite['status_code'] ?? 'ACTIVE'))),
                'closed_at' => (string) ($jobAfterWrite['closed_at'] ?? ''),
                'completed_at' => (string) ($jobAfterWrite['completed_at'] ?? ''),
                'cancel_note' => (string) ($jobAfterWrite['cancel_note'] ?? ''),
            ],
            'metadata' => [
                'workflow_note' => $statusNote !== '' ? $statusNote : null,
                'inventory_warning_count' => count($inventoryWarnings),
                'reminders_created' => (int) ($reminderResult['created_count'] ?? 0),
                'reminders_disabled' => (int) ($reminderResult['disabled_count'] ?? 0),
                'reminder_types' => (array) ($reminderResult['created_types'] ?? []),
            ],
        ]);

        flash_set('job_success', 'Job status updated to ' . $targetStatus . '.', 'success');
        if ($targetStatus === 'CLOSED' && (int) ($reminderResult['created_count'] ?? 0) > 0) {
            flash_set('job_success', 'Auto service reminders created: ' . (int) $reminderResult['created_count'] . '.', 'success');
        }
        if (!empty($inventoryWarnings)) {
            $preview = implode(' | ', array_slice($inventoryWarnings, 0, 3));
            if (count($inventoryWarnings) > 3) {
                $preview .= ' | +' . (count($inventoryWarnings) - 3) . ' more';
            }
            flash_set('job_warning', 'Job closed with stock warnings: ' . $preview, 'warning');
        }
        if (!empty($reminderResult['warnings'])) {
            $preview = implode(' | ', array_slice((array) $reminderResult['warnings'], 0, 2));
            if (count((array) $reminderResult['warnings']) > 2) {
                $preview .= ' | +' . (count((array) $reminderResult['warnings']) - 2) . ' more';
            }
            flash_set('job_warning', 'Reminder engine warnings: ' . $preview, 'warning');
        }

        redirect('modules/jobs/view.php?id=' . $jobId);
    }

    if ($action === 'add_manual_reminder') {
        if (!$canEdit && !$canClose) {
            flash_set('job_error', 'You do not have permission to add manual reminders.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#service-reminders');
        }

        if (!service_reminder_feature_ready()) {
            flash_set('job_error', 'Service reminder storage is not ready. Contact administrator.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#service-reminders');
        }

        $serviceType = (string) ($_POST['manual_service_type'] ?? '');
        $lastServiceKm = service_reminder_parse_positive_int($_POST['manual_last_km'] ?? null);
        $intervalKm = service_reminder_parse_positive_int($_POST['manual_interval_km'] ?? null);
        $nextDueKm = service_reminder_parse_positive_int($_POST['manual_next_due_km'] ?? null);
        $intervalDays = service_reminder_parse_positive_int($_POST['manual_interval_days'] ?? null);
        $nextDueDate = service_reminder_parse_date((string) ($_POST['manual_next_due_date'] ?? ''));
        $recommendation = post_string('manual_note', 255);

        $manualResult = service_reminder_create_manual(
            $companyId,
            $garageId,
            (int) ($jobForWrite['vehicle_id'] ?? 0),
            $jobId,
            $serviceType,
            $lastServiceKm,
            $intervalKm,
            $nextDueKm,
            $intervalDays,
            $nextDueDate,
            $recommendation,
            $userId
        );
        if (!(bool) ($manualResult['ok'] ?? false)) {
            flash_set('job_error', (string) ($manualResult['message'] ?? 'Unable to save manual reminder.'), 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#service-reminders');
        }

        job_append_history(
            $jobId,
            'MANUAL_REMINDER_ADD',
            null,
            null,
            'Manual service reminder added',
            [
                'service_type' => service_reminder_normalize_type($serviceType),
                'reminder_id' => (int) ($manualResult['id'] ?? 0),
            ]
        );
        log_audit('job_cards', 'manual_reminder_add', $jobId, 'Manual service reminder added for vehicle', [
            'entity' => 'service_reminder',
            'source' => 'UI',
            'metadata' => [
                'reminder_id' => (int) ($manualResult['id'] ?? 0),
                'service_type' => service_reminder_normalize_type($serviceType),
            ],
        ]);

        flash_set('job_success', 'Manual service reminder saved.', 'success');
        redirect('modules/jobs/view.php?id=' . $jobId . '#service-reminders');
    }

    if ($action === 'soft_delete') {
        if (!$canEdit && !$canClose) {
            flash_set('job_error', 'You do not have permission to soft delete jobs.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        $deleteNote = post_string('delete_note', 255);
        if ($deleteNote === '') {
            flash_set('job_error', 'Soft delete requires an audit note.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $dependencyReport = reversal_job_delete_dependency_report($pdo, $jobId, $companyId, $garageId);
            $jobReportRow = $dependencyReport['job'] ?? null;
            if (!is_array($jobReportRow)) {
                throw new RuntimeException('Job card not found for delete operation.');
            }

            if (!(bool) ($dependencyReport['can_delete'] ?? false)) {
                $blockers = array_values(array_filter(array_map('trim', (array) ($dependencyReport['blockers'] ?? []))));
                $steps = array_values(array_filter(array_map('trim', (array) ($dependencyReport['steps'] ?? []))));
                $intro = 'Job deletion blocked.';
                if ($blockers !== []) {
                    $intro .= ' ' . implode(' ', $blockers);
                }
                throw new RuntimeException(reversal_chain_message($intro, $steps));
            }

            $cancellableOutsourcedIds = array_values(array_filter(
                (array) ($dependencyReport['cancellable_outsourced_ids'] ?? []),
                static fn (mixed $id): bool => (int) $id > 0
            ));

            if ($cancellableOutsourcedIds !== [] && table_columns('outsourced_works') !== []) {
                $outsourceCancelStmt = $pdo->prepare(
                    'UPDATE outsourced_works
                     SET status_code = "INACTIVE",
                         deleted_at = COALESCE(deleted_at, NOW()),
                         notes = CONCAT(COALESCE(notes, ""), CASE WHEN COALESCE(notes, "") = "" THEN "" ELSE " | " END, :cancel_tag),
                         updated_by = :updated_by
                     WHERE id = :id
                       AND company_id = :company_id
                       AND garage_id = :garage_id
                       AND status_code = "ACTIVE"'
                );
                foreach ($cancellableOutsourcedIds as $outsourcedId) {
                    $outsourceCancelStmt->execute([
                        'cancel_tag' => 'Cancelled due to job delete #' . $jobId,
                        'updated_by' => $userId > 0 ? $userId : null,
                        'id' => (int) $outsourcedId,
                        'company_id' => $companyId,
                        'garage_id' => $garageId,
                    ]);
                }
            }

            $deleteStmt = $pdo->prepare(
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
                'updated_by' => $userId > 0 ? $userId : null,
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
            log_audit('job_cards', 'soft_delete', $jobId, 'Soft deleted job card with dependency enforcement', [
                'entity' => 'job_card',
                'source' => 'UI',
                'before' => [
                    'status' => (string) ($jobForWrite['status'] ?? ''),
                    'status_code' => (string) ($jobForWrite['status_code'] ?? ''),
                ],
                'after' => [
                    'status' => (string) ($jobForWrite['status'] ?? ''),
                    'status_code' => 'DELETED',
                ],
                'metadata' => [
                    'cancellable_outsourced_count' => count($cancellableOutsourcedIds),
                    'inventory_movements' => (int) ($dependencyReport['inventory_movements'] ?? 0),
                ],
            ]);

            $pdo->commit();
            flash_set('job_success', 'Job card soft deleted with safe dependency checks.', 'success');
            redirect('modules/jobs/index.php');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('job_error', $exception->getMessage(), 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }
    }

    $lineActions = [
        'add_labor',
        'update_labor',
        'delete_labor',
        'toggle_outsource_payable',
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
        $serviceCategoryKey = trim((string) ($_POST['service_category_key'] ?? ''));
        $description = post_string('description', 255);
        $quantity = post_decimal('quantity', 1.0);
        $unitPrice = post_decimal('unit_price', 0.0);
        $gstRate = post_decimal('gst_rate', 18.0);
        $executionType = normalize_labor_execution_type($_POST['execution_type'] ?? 'IN_HOUSE');
        $outsourceVendorId = post_int('outsource_vendor_id');
        $outsourcePartnerName = post_string('outsource_partner_name', 150);
        $outsourceCost = post_decimal('outsource_cost', 0.0);
        $outsourceExpectedReturnRaw = trim((string) ($_POST['outsource_expected_return_date'] ?? ''));
        $outsourceExpectedReturnDate = null;
        $outsourceVendorName = null;
        $serviceName = null;
        $serviceCategoryName = null;

        if ($serviceId > 0) {
            $serviceStmt = db()->prepare(
                'SELECT s.id, s.service_name, s.service_code, s.default_rate, s.gst_rate, s.category_id,
                        sc.category_name
                 FROM services s
                 LEFT JOIN service_categories sc ON sc.id = s.category_id AND sc.company_id = s.company_id
                 WHERE s.id = :id
                   AND s.company_id = :company_id
                   AND s.status_code = "ACTIVE"
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

            $resolvedCategoryKey = ((int) ($service['category_id'] ?? 0) > 0)
                ? (string) (int) $service['category_id']
                : 'uncategorized';
            if ($serviceCategoryKey !== '' && $serviceCategoryKey !== $resolvedCategoryKey) {
                flash_set('job_error', 'Selected service does not belong to the chosen category.', 'danger');
                redirect('modules/jobs/view.php?id=' . $jobId);
            }
            if ($serviceCategoryKey === '') {
                $serviceCategoryKey = $resolvedCategoryKey;
            }

            $serviceName = (string) $service['service_name'];
            $serviceCategoryName = trim((string) ($service['category_name'] ?? ''));
            if ($serviceCategoryName === '' && (int) ($service['category_id'] ?? 0) <= 0) {
                $serviceCategoryName = 'Uncategorized (Legacy)';
            }
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

        if ($outsourceExpectedReturnRaw !== '') {
            $outsourceExpectedReturnDate = parse_iso_date_input($outsourceExpectedReturnRaw);
            if ($outsourceExpectedReturnDate === null) {
                flash_set('job_error', 'Invalid expected return date for outsourced labor.', 'danger');
                redirect('modules/jobs/view.php?id=' . $jobId);
            }
        }

        if ($executionType === 'OUTSOURCED') {
            if ($outsourceVendorId > 0) {
                $vendor = fetch_outsource_vendor($companyId, $outsourceVendorId);
                if (!$vendor) {
                    flash_set('job_error', 'Selected outsourcing vendor is not active for this company.', 'danger');
                    redirect('modules/jobs/view.php?id=' . $jobId);
                }
                $outsourceVendorName = (string) ($vendor['vendor_name'] ?? '');
                if ($outsourcePartnerName === '') {
                    $outsourcePartnerName = $outsourceVendorName;
                }
            }

            if ($outsourcePartnerName === '') {
                flash_set('job_error', 'Select vendor or enter outsourced partner name for outsourced labor.', 'danger');
                redirect('modules/jobs/view.php?id=' . $jobId);
            }
            if ($outsourceCost <= 0) {
                flash_set('job_error', 'Outsourced labor requires a payable cost greater than zero.', 'danger');
                redirect('modules/jobs/view.php?id=' . $jobId);
            }
        } else {
            $outsourceVendorId = 0;
            $outsourcePartnerName = '';
            $outsourceCost = 0.0;
            $outsourceExpectedReturnDate = null;
        }

        $gstRate = max(0, min(100, $gstRate));
        $totalAmount = round($quantity * $unitPrice, 2);
        $payableStatus = ($executionType === 'OUTSOURCED' && $outsourceCost > 0) ? 'UNPAID' : 'PAID';

        $insertStmt = db()->prepare(
            'INSERT INTO job_labor
              (job_card_id, service_id, execution_type, outsource_vendor_id, outsource_partner_name, outsource_cost,
               outsource_payable_status, outsource_paid_at, outsource_paid_by, description, quantity, unit_price, gst_rate, total_amount)
             VALUES
              (:job_card_id, :service_id, :execution_type, :outsource_vendor_id, :outsource_partner_name, :outsource_cost,
               :outsource_payable_status, :outsource_paid_at, :outsource_paid_by, :description, :quantity, :unit_price, :gst_rate, :total_amount)'
        );
        $insertStmt->execute([
            'job_card_id' => $jobId,
            'service_id' => $serviceId > 0 ? $serviceId : null,
            'execution_type' => $executionType,
            'outsource_vendor_id' => ($executionType === 'OUTSOURCED' && $outsourceVendorId > 0) ? $outsourceVendorId : null,
            'outsource_partner_name' => ($executionType === 'OUTSOURCED' && $outsourcePartnerName !== '') ? $outsourcePartnerName : null,
            'outsource_cost' => round($executionType === 'OUTSOURCED' ? $outsourceCost : 0.0, 2),
            'outsource_payable_status' => $payableStatus,
            'outsource_paid_at' => null,
            'outsource_paid_by' => null,
            'description' => $description,
            'quantity' => round($quantity, 2),
            'unit_price' => round($unitPrice, 2),
            'gst_rate' => round($gstRate, 2),
            'total_amount' => $totalAmount,
        ]);

        $laborId = (int) db()->lastInsertId();
        if ($outsourceExpectedReturnSupported && $executionType === 'OUTSOURCED') {
            $expectedStmt = db()->prepare(
                'UPDATE job_labor
                 SET outsource_expected_return_date = :expected_return_date
                 WHERE id = :id
                   AND job_card_id = :job_card_id'
            );
            $expectedStmt->execute([
                'expected_return_date' => $outsourceExpectedReturnDate,
                'id' => $laborId,
                'job_card_id' => $jobId,
            ]);
        }

        $linkedOutsourceWorkId = null;
        if ($executionType === 'OUTSOURCED' && $outsourcedModuleReady) {
            $linkedOutsourceWorkId = sync_linked_outsourced_work(
                $companyId,
                $garageId,
                $jobId,
                $laborId,
                $outsourceVendorId,
                $outsourcePartnerName,
                $description,
                (float) $outsourceCost,
                $outsourceExpectedReturnDate,
                $userId
            );
            if ($linkedOutsourceWorkId === null) {
                flash_set('job_warning', 'Labor line added, but outsourced work register sync needs review.', 'warning');
            }
        }

        job_recalculate_estimate($jobId);
        job_append_history(
            $jobId,
            'LABOR_ADD',
            null,
            null,
            'Labor line added',
            [
                'service_id' => $serviceId > 0 ? $serviceId : null,
                'service_category_key' => $serviceId > 0 ? $serviceCategoryKey : null,
                'service_category_name' => $serviceCategoryName,
                'service_name' => $serviceName,
                'description' => $description,
                'quantity' => round($quantity, 2),
                'unit_price' => round($unitPrice, 2),
                'execution_type' => $executionType,
                'outsource_vendor_id' => ($executionType === 'OUTSOURCED' && $outsourceVendorId > 0) ? $outsourceVendorId : null,
                'outsource_vendor_name' => $outsourceVendorName,
                'outsource_partner_name' => $executionType === 'OUTSOURCED' ? $outsourcePartnerName : null,
                'outsource_cost' => round($executionType === 'OUTSOURCED' ? $outsourceCost : 0.0, 2),
                'outsource_expected_return_date' => $executionType === 'OUTSOURCED' ? $outsourceExpectedReturnDate : null,
                'outsource_payable_status' => $payableStatus,
                'outsourced_work_id' => $linkedOutsourceWorkId,
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
        $executionType = normalize_labor_execution_type($_POST['execution_type'] ?? 'IN_HOUSE');
        $outsourceVendorId = post_int('outsource_vendor_id');
        $outsourcePartnerName = post_string('outsource_partner_name', 150);
        $outsourceCost = post_decimal('outsource_cost', 0.0);
        $outsourceExpectedReturnRaw = trim((string) ($_POST['outsource_expected_return_date'] ?? ''));
        $outsourceExpectedReturnDate = null;
        $outsourceVendorName = null;

        if ($laborId <= 0 || $description === '' || $quantity <= 0 || $unitPrice < 0) {
            flash_set('job_error', 'Invalid labor update payload.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        $lineStmt = db()->prepare(
            'SELECT jl.id, jl.execution_type, jl.outsource_cost, jl.outsource_payable_status, jl.outsource_paid_at, jl.outsource_paid_by
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

        $line = $lineStmt->fetch();
        if (!$line) {
            flash_set('job_error', 'Labor line not found for this job.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        if ($outsourceExpectedReturnRaw !== '') {
            $outsourceExpectedReturnDate = parse_iso_date_input($outsourceExpectedReturnRaw);
            if ($outsourceExpectedReturnDate === null) {
                flash_set('job_error', 'Invalid expected return date for outsourced labor.', 'danger');
                redirect('modules/jobs/view.php?id=' . $jobId);
            }
        }

        if ($executionType === 'OUTSOURCED') {
            if ($outsourceVendorId > 0) {
                $vendor = fetch_outsource_vendor($companyId, $outsourceVendorId);
                if (!$vendor) {
                    flash_set('job_error', 'Selected outsourcing vendor is not active for this company.', 'danger');
                    redirect('modules/jobs/view.php?id=' . $jobId);
                }
                $outsourceVendorName = (string) ($vendor['vendor_name'] ?? '');
                if ($outsourcePartnerName === '') {
                    $outsourcePartnerName = $outsourceVendorName;
                }
            }

            if ($outsourcePartnerName === '') {
                flash_set('job_error', 'Select vendor or enter outsourced partner name for outsourced labor.', 'danger');
                redirect('modules/jobs/view.php?id=' . $jobId);
            }
            if ($outsourceCost <= 0) {
                flash_set('job_error', 'Outsourced labor requires a payable cost greater than zero.', 'danger');
                redirect('modules/jobs/view.php?id=' . $jobId);
            }
        } else {
            $outsourceVendorId = 0;
            $outsourcePartnerName = '';
            $outsourceCost = 0.0;
            $outsourceExpectedReturnDate = null;
        }

        $gstRate = max(0, min(100, $gstRate));
        $totalAmount = round($quantity * $unitPrice, 2);
        $previousExecutionType = normalize_labor_execution_type((string) ($line['execution_type'] ?? 'IN_HOUSE'));

        if (
            $outsourcedModuleReady
            && $previousExecutionType === 'OUTSOURCED'
            && $executionType !== 'OUTSOURCED'
        ) {
            $linkedWork = fetch_linked_outsourced_work($companyId, $garageId, $laborId);
            if ($linkedWork && round((float) ($linkedWork['paid_amount'] ?? 0), 2) > 0.009) {
                flash_set('job_error', 'Cannot convert outsourced labor to in-house after payments are recorded. Reverse payments from Outsourced Works module first.', 'danger');
                redirect('modules/jobs/view.php?id=' . $jobId);
            }
        }

        $payableStatus = normalize_outsource_payable_status((string) ($line['outsource_payable_status'] ?? 'UNPAID'));
        $paidAt = !empty($line['outsource_paid_at']) ? (string) $line['outsource_paid_at'] : null;
        $paidBy = (int) ($line['outsource_paid_by'] ?? 0);

        if ($executionType === 'OUTSOURCED') {
            if ($previousExecutionType !== 'OUTSOURCED') {
                $payableStatus = 'UNPAID';
            }
            if ($payableStatus === 'PAID') {
                if ($paidAt === null) {
                    $paidAt = date('Y-m-d H:i:s');
                }
                if ($paidBy <= 0) {
                    $paidBy = $userId;
                }
            } else {
                $paidAt = null;
                $paidBy = 0;
            }
        } else {
            $payableStatus = 'PAID';
            $paidAt = null;
            $paidBy = 0;
        }

        $updateStmt = db()->prepare(
            'UPDATE job_labor
             SET description = :description,
                 execution_type = :execution_type,
                 outsource_vendor_id = :outsource_vendor_id,
                 outsource_partner_name = :outsource_partner_name,
                 outsource_cost = :outsource_cost,
                 outsource_payable_status = :outsource_payable_status,
                 outsource_paid_at = :outsource_paid_at,
                 outsource_paid_by = :outsource_paid_by,
                 quantity = :quantity,
                 unit_price = :unit_price,
                 gst_rate = :gst_rate,
                 total_amount = :total_amount
             WHERE id = :id
               AND job_card_id = :job_card_id'
        );
        $updateStmt->execute([
            'description' => $description,
            'execution_type' => $executionType,
            'outsource_vendor_id' => ($executionType === 'OUTSOURCED' && $outsourceVendorId > 0) ? $outsourceVendorId : null,
            'outsource_partner_name' => ($executionType === 'OUTSOURCED' && $outsourcePartnerName !== '') ? $outsourcePartnerName : null,
            'outsource_cost' => round($executionType === 'OUTSOURCED' ? $outsourceCost : 0.0, 2),
            'outsource_payable_status' => $payableStatus,
            'outsource_paid_at' => $paidAt,
            'outsource_paid_by' => $paidBy > 0 ? $paidBy : null,
            'quantity' => round($quantity, 2),
            'unit_price' => round($unitPrice, 2),
            'gst_rate' => round($gstRate, 2),
            'total_amount' => $totalAmount,
            'id' => $laborId,
            'job_card_id' => $jobId,
        ]);

        if ($outsourceExpectedReturnSupported) {
            $expectedStmt = db()->prepare(
                'UPDATE job_labor
                 SET outsource_expected_return_date = :expected_return_date
                 WHERE id = :id
                   AND job_card_id = :job_card_id'
            );
            $expectedStmt->execute([
                'expected_return_date' => $executionType === 'OUTSOURCED' ? $outsourceExpectedReturnDate : null,
                'id' => $laborId,
                'job_card_id' => $jobId,
            ]);
        }

        $linkedOutsourceWorkId = null;
        if ($outsourcedModuleReady) {
            if ($executionType === 'OUTSOURCED') {
                $linkedOutsourceWorkId = sync_linked_outsourced_work(
                    $companyId,
                    $garageId,
                    $jobId,
                    $laborId,
                    $outsourceVendorId,
                    $outsourcePartnerName,
                    $description,
                    (float) $outsourceCost,
                    $outsourceExpectedReturnDate,
                    $userId
                );
                if ($linkedOutsourceWorkId === null) {
                    flash_set('job_warning', 'Labor updated, but outsourced work register sync needs review.', 'warning');
                }
            } elseif ($previousExecutionType === 'OUTSOURCED') {
                if (!soft_delete_linked_outsourced_work($companyId, $garageId, $laborId, $userId)) {
                    flash_set('job_error', 'Cannot remove outsourced linkage with paid records. Reverse payments from Outsourced Works module first.', 'danger');
                    redirect('modules/jobs/view.php?id=' . $jobId);
                }
            }
        }

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
                'execution_type' => $executionType,
                'outsource_vendor_id' => ($executionType === 'OUTSOURCED' && $outsourceVendorId > 0) ? $outsourceVendorId : null,
                'outsource_vendor_name' => $outsourceVendorName,
                'outsource_partner_name' => $executionType === 'OUTSOURCED' ? $outsourcePartnerName : null,
                'outsource_cost' => round($executionType === 'OUTSOURCED' ? $outsourceCost : 0.0, 2),
                'outsource_expected_return_date' => $executionType === 'OUTSOURCED' ? $outsourceExpectedReturnDate : null,
                'outsource_payable_status' => $payableStatus,
                'outsourced_work_id' => $linkedOutsourceWorkId,
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

        if ($outsourcedModuleReady) {
            $linkedWork = fetch_linked_outsourced_work($companyId, $garageId, $laborId);
            if ($linkedWork && round((float) ($linkedWork['paid_amount'] ?? 0), 2) > 0.009) {
                flash_set('job_error', 'Cannot delete outsourced labor with paid records. Use payment reversal from Outsourced Works module.', 'danger');
                redirect('modules/jobs/view.php?id=' . $jobId);
            }
            if ($linkedWork && !soft_delete_linked_outsourced_work($companyId, $garageId, $laborId, $userId)) {
                flash_set('job_error', 'Unable to archive linked outsourced work.', 'danger');
                redirect('modules/jobs/view.php?id=' . $jobId);
            }
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

    if ($action === 'toggle_outsource_payable') {
        $laborId = post_int('labor_id');
        $nextPayableStatus = normalize_outsource_payable_status($_POST['next_payable_status'] ?? 'UNPAID');
        if ($laborId <= 0) {
            flash_set('job_error', 'Invalid outsourced labor line selected.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        if ($outsourcedModuleReady) {
            $linkedWork = fetch_linked_outsourced_work($companyId, $garageId, $laborId);
            if ($linkedWork) {
                flash_set('job_error', 'Use Outsourced Works module for payable lifecycle and payments.', 'danger');
                redirect('modules/jobs/view.php?id=' . $jobId);
            }
        }

        $lineStmt = db()->prepare(
            'SELECT jl.id, jl.execution_type, jl.outsource_cost, jl.outsource_payable_status
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
        $line = $lineStmt->fetch();
        if (!$line) {
            flash_set('job_error', 'Outsourced labor line not found for this job.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        if (normalize_labor_execution_type((string) ($line['execution_type'] ?? 'IN_HOUSE')) !== 'OUTSOURCED') {
            flash_set('job_error', 'Payable status can only be changed for outsourced labor lines.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        if ((float) ($line['outsource_cost'] ?? 0) <= 0) {
            flash_set('job_error', 'Outsourced payable cost must be greater than zero.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        $updateStmt = db()->prepare(
            'UPDATE job_labor
             SET outsource_payable_status = :payable_status,
                 outsource_paid_at = :paid_at,
                 outsource_paid_by = :paid_by
             WHERE id = :id
               AND job_card_id = :job_card_id'
        );
        $updateStmt->execute([
            'payable_status' => $nextPayableStatus,
            'paid_at' => $nextPayableStatus === 'PAID' ? date('Y-m-d H:i:s') : null,
            'paid_by' => $nextPayableStatus === 'PAID' ? $userId : null,
            'id' => $laborId,
            'job_card_id' => $jobId,
        ]);

        job_append_history(
            $jobId,
            'OUTSOURCE_PAYABLE',
            null,
            null,
            'Outsourced payable marked as ' . $nextPayableStatus,
            [
                'labor_id' => $laborId,
                'from_status' => normalize_outsource_payable_status((string) ($line['outsource_payable_status'] ?? 'UNPAID')),
                'to_status' => $nextPayableStatus,
                'outsource_cost' => round((float) ($line['outsource_cost'] ?? 0), 2),
            ]
        );
        log_audit('job_cards', 'outsource_payable', $jobId, 'Updated outsourced payable status on labor line #' . $laborId, [
            'entity' => 'job_labor',
            'source' => 'UI',
            'before' => [
                'outsource_payable_status' => normalize_outsource_payable_status((string) ($line['outsource_payable_status'] ?? 'UNPAID')),
            ],
            'after' => [
                'outsource_payable_status' => $nextPayableStatus,
            ],
            'metadata' => [
                'labor_id' => $laborId,
                'outsource_cost' => round((float) ($line['outsource_cost'] ?? 0), 2),
            ],
        ]);

        flash_set('job_success', 'Outsourced payable marked as ' . $nextPayableStatus . '.', 'success');
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

$serviceCategories = fetch_service_categories_for_labor($companyId);
$servicesMaster = fetch_services_master($companyId);
$outsourceVendors = fetch_outsource_vendors($companyId);
$hasLegacyUncategorizedServices = false;
foreach ($servicesMaster as $serviceMasterRow) {
    if ((int) ($serviceMasterRow['category_id'] ?? 0) <= 0) {
        $hasLegacyUncategorizedServices = true;
        break;
    }
}
$partsMaster = fetch_parts_master($companyId, $garageId);
$laborEntries = fetch_job_labor($jobId);
$partEntries = fetch_job_parts($jobId, $garageId);
$historyEntries = fetch_job_history_timeline($jobId);
$outsourceLineCount = 0;
$outsourceCostTotal = 0.0;
$outsourceUnpaidTotal = 0.0;
$outsourcePaidTotal = 0.0;
foreach ($laborEntries as $laborEntry) {
    if (normalize_labor_execution_type((string) ($laborEntry['execution_type'] ?? 'IN_HOUSE')) !== 'OUTSOURCED') {
        continue;
    }
    $lineCost = (float) ($laborEntry['outsource_cost'] ?? 0);
    $outsourceLineCount++;
    $outsourceCostTotal += $lineCost;
    if (normalize_outsource_payable_status((string) ($laborEntry['outsource_payable_status'] ?? 'UNPAID')) === 'PAID') {
        $outsourcePaidTotal += $lineCost;
    } else {
        $outsourceUnpaidTotal += $lineCost;
    }
}

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
$canPrintJob = has_permission('job.print') || has_permission('job.manage') || has_permission('job.view');
$jobPrintRestricted = $jobStatus === 'CANCELLED' || normalize_status_code((string) ($job['status_code'] ?? 'ACTIVE')) === 'DELETED';
$canPrintRestricted = has_permission('job.print.cancelled') || has_permission('job.manage');
$canRenderPrintButton = $canPrintJob && (!$jobPrintRestricted || $canPrintRestricted);
$conditionPhotoFeatureReady = job_condition_photo_feature_ready();
$conditionPhotos = $conditionPhotoFeatureReady
    ? job_condition_photo_fetch_by_job($companyId, $garageId, $jobId, 200)
    : [];
$conditionPhotoCount = count($conditionPhotos);
$conditionPhotoMaxMb = round(job_condition_photo_max_upload_bytes($companyId, $garageId) / 1048576, 2);
$conditionPhotoPrompt = get_int('prompt_condition_photos') === 1 && $conditionPhotoCount === 0;

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

$serviceReminderFeatureReady = service_reminder_feature_ready();
$autoReminderPreviewRows = [];
if ($serviceReminderFeatureReady && !$jobLocked && in_array('CLOSED', $nextStatuses, true)) {
    $autoReminderPreviewRows = service_reminder_build_preview_for_job($companyId, $garageId, $jobId);
}
$activeServiceReminders = $serviceReminderFeatureReady
    ? service_reminder_fetch_active_by_vehicle($companyId, (int) $job['vehicle_id'], $garageId, 25)
    : [];
$serviceReminderSummary = service_reminder_summary_counts($activeServiceReminders);
$jobCurrentOdometerForReminder = $jobOdometerEnabled
    ? service_reminder_parse_positive_int($job['odometer_km'] ?? null)
    : null;

$visData = job_fetch_vis_suggestions($companyId, $garageId, (int) $job['vehicle_id']);
$visVariant = $visData['vehicle_variant'];
$visServiceSuggestions = $visData['service_suggestions'] ?? [];
$visPartSuggestions = $visData['part_suggestions'] ?? [];
$hasVisData = $visVariant !== null || !empty($visServiceSuggestions) || !empty($visPartSuggestions);
$visPartCompatibilityLookup = [];
foreach ($visPartSuggestions as $visPartSuggestion) {
    $visPartId = (int) ($visPartSuggestion['part_id'] ?? 0);
    if ($visPartId > 0) {
        $visPartCompatibilityLookup[$visPartId] = true;
    }
}
$partsTotalCount = count($partsMaster);
$partsVisCount = 0;
$partsInStockCount = 0;
foreach ($partsMaster as $partMasterRow) {
    $partMasterId = (int) ($partMasterRow['id'] ?? 0);
    if ($partMasterId > 0 && isset($visPartCompatibilityLookup[$partMasterId])) {
        $partsVisCount++;
    }
    if ((float) ($partMasterRow['stock_qty'] ?? 0) > 0) {
        $partsInStockCount++;
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
          <h3 class="mb-0">Job Card <?= e((string) $job['job_number']); ?></h3>
          <small class="text-muted">
            Garage: <?= e((string) $job['garage_name']); ?> |
            Customer: <?= e((string) $job['customer_name']); ?> |
            Vehicle: <?= e((string) $job['registration_no']); ?>
          </small>
        </div>
        <div class="col-sm-4">
          <div class="d-flex flex-column align-items-sm-end gap-2">
            <ol class="breadcrumb mb-0">
              <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
              <li class="breadcrumb-item">Operations</li>
              <li class="breadcrumb-item"><a href="<?= e(url('modules/jobs/index.php')); ?>">Job Cards</a></li>
              <li class="breadcrumb-item active">Details</li>
            </ol>
            <div class="d-flex flex-wrap justify-content-sm-end gap-1">
              <a href="<?= e(url('modules/jobs/index.php')); ?>" class="btn btn-outline-secondary btn-sm">Back to Jobs</a>
              <?php if ((int) ($job['estimate_id'] ?? 0) > 0): ?>
                <a href="<?= e(url('modules/estimates/view.php?id=' . (int) $job['estimate_id'])); ?>" class="btn btn-info btn-sm">
                  Source Estimate
                </a>
              <?php endif; ?>
              <?php if ($canRenderPrintButton): ?>
                <a href="<?= e(url('modules/jobs/print_job_card.php?id=' . $jobId)); ?>" class="btn btn-outline-dark btn-sm" target="_blank">
                  Print Job Card
                </a>
              <?php elseif ($jobPrintRestricted): ?>
                <span class="btn btn-outline-dark btn-sm disabled">Print blocked for <?= e($jobStatus); ?></span>
              <?php endif; ?>
              <?php if ($invoice): ?>
                <a href="<?= e(url('modules/billing/print_invoice.php?id=' . (int) $invoice['id'])); ?>" class="btn btn-success btn-sm" target="_blank">
                  Invoice <?= e((string) $invoice['invoice_number']); ?> (<?= e((string) ($invoice['invoice_status'] ?? '')); ?>)
                </a>
              <?php endif; ?>
            </div>
          </div>
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
      <?php if ($conditionPhotoPrompt): ?>
        <div class="alert alert-info">
          Capture and upload vehicle current-condition photos now for dispute-proof records.
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
              <?php if ($jobOdometerEnabled): ?>
                <p class="mb-2"><strong>Odometer:</strong> <?= e(number_format((float) ($job['odometer_km'] ?? 0), 0)); ?> KM</p>
              <?php endif; ?>
              <p class="mb-2"><strong>Complaint:</strong><br><?= nl2br(e((string) $job['complaint'])); ?></p>
              <p class="mb-0"><strong>Diagnosis:</strong><br><?= nl2br(e((string) ($job['diagnosis'] ?? '-'))); ?></p>
              <?php if (!empty($job['cancel_note'])): ?>
                <div class="alert alert-light border mt-3 mb-0"><strong>Audit Note:</strong> <?= e((string) $job['cancel_note']); ?></div>
              <?php endif; ?>
            </div>
          </div>

          <div class="card card-outline card-secondary" id="condition-photos">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Vehicle Condition Photos</h3>
              <span class="badge text-bg-light border"><?= (int) $conditionPhotoCount; ?></span>
            </div>
            <div class="card-body">
              <?php if (!$conditionPhotoFeatureReady): ?>
                <div class="alert alert-warning mb-0">
                  Condition photo storage is not ready. Run <code>database/job_condition_photos_upgrade.sql</code> to enable this feature.
                </div>
              <?php else: ?>
                <p class="text-muted small mb-3">
                  Upload intake-condition photos before/while opening work. Max file size: <?= e(number_format($conditionPhotoMaxMb, 2)); ?> MB per image.
                </p>
                <?php if ($canConditionPhotoUpload): ?>
                  <form method="post" enctype="multipart/form-data" class="row g-2 mb-3">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="_action" value="upload_condition_photos">
                    <div class="col-md-8">
                      <label class="form-label">Select Images</label>
                      <input type="file" name="condition_images[]" class="form-control" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple required>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Note (Optional)</label>
                      <input type="text" name="condition_photo_note" class="form-control" maxlength="255" placeholder="Front bumper scratch, etc.">
                    </div>
                    <div class="col-12">
                      <button type="submit" class="btn btn-outline-primary">Upload Condition Photos</button>
                    </div>
                  </form>
                <?php else: ?>
                  <div class="text-muted small mb-3">Upload/delete is restricted to users with job edit permissions.</div>
                <?php endif; ?>

                <div class="row g-2">
                  <?php if (empty($conditionPhotos)): ?>
                    <div class="col-12">
                      <div class="text-muted">No condition photos uploaded yet.</div>
                    </div>
                  <?php else: ?>
                    <?php foreach ($conditionPhotos as $photo): ?>
                      <?php $photoUrl = job_condition_photo_file_url((string) ($photo['file_path'] ?? '')); ?>
                      <div class="col-md-4 col-sm-6">
                        <div class="border rounded p-2 h-100 d-flex flex-column">
                          <?php if ($photoUrl !== null): ?>
                            <a href="<?= e($photoUrl); ?>" target="_blank" class="d-block mb-2">
                              <img src="<?= e($photoUrl); ?>" alt="Condition Photo" class="img-fluid rounded" style="width:100%;height:140px;object-fit:cover;">
                            </a>
                          <?php else: ?>
                            <div class="bg-light border rounded d-flex align-items-center justify-content-center mb-2" style="height:140px;">
                              <span class="text-muted small">File Missing</span>
                            </div>
                          <?php endif; ?>
                          <div class="small text-muted"><?= e((string) ($photo['created_at'] ?? '')); ?></div>
                          <div class="small"><?= e((string) ($photo['uploaded_by_name'] ?? 'System')); ?></div>
                          <?php if (!empty($photo['note'])): ?>
                            <div class="small mt-1"><?= e((string) $photo['note']); ?></div>
                          <?php endif; ?>
                          <?php if ($canConditionPhotoUpload): ?>
                            <form method="post" class="mt-2" data-confirm="Delete this condition photo?">
                              <?= csrf_field(); ?>
                              <input type="hidden" name="_action" value="delete_condition_photo">
                              <input type="hidden" name="photo_id" value="<?= (int) ($photo['id'] ?? 0); ?>">
                              <button type="submit" class="btn btn-sm btn-outline-danger w-100">Delete</button>
                            </form>
                          <?php endif; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
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

                    <div id="close-reminder-preview" class="mt-3 <?= ($serviceReminderFeatureReady && !empty($autoReminderPreviewRows)) ? '' : 'd-none'; ?>">
                      <div class="border rounded p-2 bg-light">
                        <div class="fw-semibold mb-2">Auto Service Reminder Preview (Applied On CLOSE)</div>
                        <?php if (!$serviceReminderFeatureReady): ?>
                          <div class="text-muted small">Reminder storage is not ready. Run DB upgrade to enable this module.</div>
                        <?php elseif (empty($autoReminderPreviewRows)): ?>
                          <div class="text-muted small">No auto reminders detected from current job lines and VIS interval rules.</div>
                        <?php else: ?>
                          <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-2">
                              <thead>
                                <tr>
                                  <th style="width:6%;">On</th>
                                  <th style="width:15%;">Service</th>
                                  <th style="width:10%;">Last KM</th>
                                  <th style="width:10%;">Interval KM</th>
                                  <th style="width:10%;">Next Due KM</th>
                                  <th style="width:9%;">Days</th>
                                  <th style="width:12%;">Next Date</th>
                                  <th style="width:12%;">Predicted Visit</th>
                                  <th style="width:16%;">Recommendation</th>
                                </tr>
                              </thead>
                              <tbody>
                                <?php foreach ($autoReminderPreviewRows as $previewRow): ?>
                                  <?php
                                    $serviceTypeKey = (string) ($previewRow['service_type'] ?? '');
                                    if ($serviceTypeKey === '') {
                                        continue;
                                    }
                                  ?>
                                  <tr data-reminder-row="1">
                                    <td class="text-center">
                                      <input type="hidden" name="reminder_enabled[<?= e($serviceTypeKey); ?>]" value="0">
                                      <input class="form-check-input js-reminder-enable" type="checkbox" name="reminder_enabled[<?= e($serviceTypeKey); ?>]" value="1" checked>
                                    </td>
                                    <td class="fw-semibold"><?= e((string) ($previewRow['service_label'] ?? $serviceTypeKey)); ?></td>
                                    <td>
                                      <input type="number" step="1" min="0" name="reminder_last_km[<?= e($serviceTypeKey); ?>]" value="<?= e((string) ($previewRow['last_service_km'] ?? '')); ?>" class="form-control form-control-sm js-reminder-input">
                                    </td>
                                    <td>
                                      <input type="number" step="1" min="0" name="reminder_interval_km[<?= e($serviceTypeKey); ?>]" value="<?= e((string) ($previewRow['interval_km'] ?? '')); ?>" class="form-control form-control-sm js-reminder-input">
                                    </td>
                                    <td>
                                      <input type="number" step="1" min="0" name="reminder_next_due_km[<?= e($serviceTypeKey); ?>]" value="<?= e((string) ($previewRow['next_due_km'] ?? '')); ?>" class="form-control form-control-sm js-reminder-input">
                                    </td>
                                    <td>
                                      <input type="number" step="1" min="0" name="reminder_interval_days[<?= e($serviceTypeKey); ?>]" value="<?= e((string) ($previewRow['interval_days'] ?? '')); ?>" class="form-control form-control-sm js-reminder-input">
                                    </td>
                                    <td>
                                      <input type="date" name="reminder_next_due_date[<?= e($serviceTypeKey); ?>]" value="<?= e((string) ($previewRow['next_due_date'] ?? '')); ?>" class="form-control form-control-sm js-reminder-input">
                                    </td>
                                    <td class="small"><?= e((string) (($previewRow['predicted_next_visit_date'] ?? '') !== '' ? $previewRow['predicted_next_visit_date'] : '-')); ?></td>
                                    <td>
                                      <input type="text" maxlength="255" name="reminder_note[<?= e($serviceTypeKey); ?>]" value="<?= e((string) ($previewRow['recommendation_text'] ?? '')); ?>" class="form-control form-control-sm js-reminder-input">
                                    </td>
                                  </tr>
                                <?php endforeach; ?>
                              </tbody>
                            </table>
                          </div>
                          <div class="small text-muted">Unchecked rows are skipped on close, and any existing active reminder for that service type is marked inactive.</div>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>
                <div class="card-footer">
                  <button type="submit" class="btn btn-primary" <?= $jobLocked || empty($nextStatuses) ? 'disabled' : ''; ?>>Apply Transition</button>
                </div>
              </form>
            </div>

            <div class="card card-outline card-success" id="service-reminders">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Service Reminders</h3>
                <span class="badge text-bg-light border"><?= (int) ($serviceReminderSummary['total'] ?? 0); ?> Active</span>
              </div>
              <div class="card-body">
                <?php if (!$serviceReminderFeatureReady): ?>
                  <div class="alert alert-warning mb-0">Service reminder storage is not ready. Please run DB upgrade.</div>
                <?php else: ?>
                  <div class="row g-2 mb-3">
                    <div class="col-6 col-lg-3">
                      <div class="small text-muted">Overdue</div>
                      <div class="fw-semibold text-danger"><?= (int) ($serviceReminderSummary['overdue'] ?? 0); ?></div>
                    </div>
                    <div class="col-6 col-lg-3">
                      <div class="small text-muted">Due Soon</div>
                      <div class="fw-semibold text-warning"><?= (int) ($serviceReminderSummary['due_soon'] ?? 0); ?></div>
                    </div>
                    <div class="col-6 col-lg-3">
                      <div class="small text-muted">Upcoming</div>
                      <div class="fw-semibold text-info"><?= (int) ($serviceReminderSummary['upcoming'] ?? 0); ?></div>
                    </div>
                    <div class="col-6 col-lg-3">
                      <div class="small text-muted">Unscheduled</div>
                      <div class="fw-semibold text-secondary"><?= (int) ($serviceReminderSummary['unscheduled'] ?? 0); ?></div>
                    </div>
                  </div>

                  <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle mb-0">
                      <thead>
                        <tr>
                          <th>Service</th>
                          <th>Due KM</th>
                          <th>Due Date</th>
                          <th>Predicted Visit</th>
                          <th>Status</th>
                          <th>Source</th>
                          <th>Recommendation</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php if (empty($activeServiceReminders)): ?>
                          <tr><td colspan="7" class="text-center text-muted">No active reminders for this vehicle.</td></tr>
                        <?php else: ?>
                          <?php foreach ($activeServiceReminders as $reminder): ?>
                            <tr>
                              <td><?= e((string) ($reminder['service_label'] ?? service_reminder_type_label((string) ($reminder['service_type'] ?? '')))); ?></td>
                              <td class="text-end"><?= isset($reminder['next_due_km']) && $reminder['next_due_km'] !== null ? e(number_format((float) $reminder['next_due_km'], 0)) : '-'; ?></td>
                              <td><?= e((string) (($reminder['next_due_date'] ?? '') !== '' ? $reminder['next_due_date'] : '-')); ?></td>
                              <td><?= e((string) (($reminder['predicted_next_visit_date'] ?? '') !== '' ? $reminder['predicted_next_visit_date'] : '-')); ?></td>
                              <td><span class="badge text-bg-<?= e(service_reminder_due_badge_class((string) ($reminder['due_state'] ?? 'UNSCHEDULED'))); ?>"><?= e((string) ($reminder['due_state'] ?? 'UNSCHEDULED')); ?></span></td>
                              <td><?= e((string) ($reminder['source_type'] ?? 'AUTO')); ?></td>
                              <td class="small"><?= e((string) ($reminder['recommendation_text'] ?? '-')); ?></td>
                            </tr>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>

                  <?php if ($canEdit || $canClose): ?>
                    <h6 class="mb-2">Add / Override Manual Reminder</h6>
                    <form method="post" class="row g-2 align-items-end">
                      <?= csrf_field(); ?>
                      <input type="hidden" name="_action" value="add_manual_reminder">
                      <div class="col-md-3">
                        <label class="form-label">Service Type</label>
                        <select name="manual_service_type" class="form-select" required>
                          <option value="">Select</option>
                          <?php foreach (service_reminder_supported_types() as $serviceType): ?>
                            <option value="<?= e($serviceType); ?>"><?= e(service_reminder_type_label($serviceType)); ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-md-2">
                        <label class="form-label">Last KM</label>
                        <input type="number" step="1" min="0" name="manual_last_km" class="form-control" value="<?= e((string) ($jobCurrentOdometerForReminder ?? '')); ?>">
                      </div>
                      <div class="col-md-2">
                        <label class="form-label">Interval KM</label>
                        <input type="number" step="1" min="0" name="manual_interval_km" class="form-control">
                      </div>
                      <div class="col-md-2">
                        <label class="form-label">Next Due KM</label>
                        <input type="number" step="1" min="0" name="manual_next_due_km" class="form-control">
                      </div>
                      <div class="col-md-1">
                        <label class="form-label">Days</label>
                        <input type="number" step="1" min="0" name="manual_interval_days" class="form-control">
                      </div>
                      <div class="col-md-2">
                        <label class="form-label">Next Date</label>
                        <input type="date" name="manual_next_due_date" class="form-control">
                      </div>
                      <div class="col-12">
                        <label class="form-label">Recommendation</label>
                        <input type="text" maxlength="255" name="manual_note" class="form-control" placeholder="Custom recommendation text">
                      </div>
                      <div class="col-12">
                        <button type="submit" class="btn btn-outline-success">Save Manual Reminder</button>
                      </div>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

          <?php if (($canEdit || $canClose) && normalize_status_code((string) ($job['status_code'] ?? 'ACTIVE')) !== 'DELETED'): ?>
            <div class="card card-outline card-danger">
              <div class="card-header"><h3 class="card-title">Soft Delete Job Card</h3></div>
              <div class="card-body">
                <p class="text-muted mb-3">Delete is allowed only after dependency reversals are complete. The system will block unsafe cascades and show a reversal path.</p>
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#jobDeleteModal">Soft Delete</button>
              </div>
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
                      <?php
                        $suggestionCategoryKey = ((int) ($suggestion['category_id'] ?? 0) > 0)
                          ? (string) (int) $suggestion['category_id']
                          : 'uncategorized';
                        $suggestionCategoryName = trim((string) ($suggestion['category_name'] ?? ''));
                        if ($suggestionCategoryName === '' && $suggestionCategoryKey === 'uncategorized') {
                            $suggestionCategoryName = 'Uncategorized (Legacy)';
                        }
                      ?>
                      <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                          <div class="fw-semibold"><?= e((string) $suggestion['service_name']); ?></div>
                          <small class="text-muted">
                            Code: <?= e((string) $suggestion['service_code']); ?> |
                            Category: <?= e($suggestionCategoryName !== '' ? $suggestionCategoryName : ('Category #' . (int) $suggestion['category_id'])); ?> |
                            Default Rate: <?= e(format_currency((float) $suggestion['default_rate'])); ?> |
                            GST: <?= e((string) $suggestion['gst_rate']); ?>%
                          </small>
                        </div>
                        <?php if ($canEdit && !$jobLocked): ?>
                          <form method="post" class="d-inline">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="add_labor">
                            <input type="hidden" name="service_id" value="<?= (int) $suggestion['service_id']; ?>">
                            <input type="hidden" name="service_category_key" value="<?= e($suggestionCategoryKey); ?>">
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
            <div class="card-body border-bottom py-2">
              <div class="row g-2">
                <div class="col-md-4">
                  <div class="small text-muted">Outsourced Lines</div>
                  <div class="fw-semibold"><?= e(number_format($outsourceLineCount)); ?></div>
                </div>
                <div class="col-md-4">
                  <div class="small text-muted">Outsource Cost (Total)</div>
                  <div class="fw-semibold"><?= e(format_currency($outsourceCostTotal)); ?></div>
                </div>
                <div class="col-md-4">
                  <div class="small text-muted">Outsource Payable (Unpaid)</div>
                  <div class="fw-semibold text-danger"><?= e(format_currency($outsourceUnpaidTotal)); ?></div>
                </div>
              </div>
            </div>
            <?php if ($canEdit): ?>
              <form method="post">
                <div class="card-body border-bottom row g-2">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="add_labor">
                  <div class="col-md-2">
                    <label class="form-label">Category</label>
                    <select id="add-labor-category" name="service_category_key" class="form-select" <?= $jobLocked ? 'disabled' : ''; ?>>
                      <option value="">All Categories</option>
                      <?php foreach ($serviceCategories as $category): ?>
                        <option value="<?= (int) $category['id']; ?>">
                          <?= e((string) $category['category_name']); ?>
                        </option>
                      <?php endforeach; ?>
                      <?php if ($hasLegacyUncategorizedServices): ?>
                        <option value="uncategorized">Uncategorized (Legacy)</option>
                      <?php endif; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Service Master</label>
                    <select id="add-labor-service" name="service_id" class="form-select" <?= $jobLocked ? 'disabled' : ''; ?>>
                      <option value="0" data-category-key="">Custom Labor Item</option>
                      <?php foreach ($servicesMaster as $service): ?>
                        <?php $serviceCategoryKey = ((int) ($service['category_id'] ?? 0) > 0) ? (string) (int) $service['category_id'] : 'uncategorized'; ?>
                        <option
                          value="<?= (int) $service['id']; ?>"
                          data-category-key="<?= e($serviceCategoryKey); ?>"
                          data-name="<?= e((string) $service['service_name']); ?>"
                          data-rate="<?= e((string) $service['default_rate']); ?>"
                          data-gst="<?= e((string) $service['gst_rate']); ?>"
                        >
                          <?= e((string) $service['service_code']); ?> - <?= e((string) $service['service_name']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Category is optional. Choose one to narrow the service list.</small>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Description</label>
                    <input id="add-labor-description" type="text" name="description" class="form-control" required <?= $jobLocked ? 'disabled' : ''; ?>>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Execution</label>
                    <select id="add-labor-execution" name="execution_type" class="form-select" <?= $jobLocked ? 'disabled' : ''; ?>>
                      <option value="IN_HOUSE">In-house</option>
                      <option value="OUTSOURCED">Outsourced</option>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Vendor</label>
                    <select id="add-labor-vendor" name="outsource_vendor_id" class="form-select" <?= $jobLocked ? 'disabled' : ''; ?>>
                      <option value="0">Select Vendor (Optional)</option>
                      <?php foreach ($outsourceVendors as $vendor): ?>
                        <option value="<?= (int) $vendor['id']; ?>" data-vendor-name="<?= e((string) $vendor['vendor_name']); ?>"><?= e((string) $vendor['vendor_code']); ?> - <?= e((string) $vendor['vendor_name']); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Outsourced To</label>
                    <input id="add-labor-partner" type="text" name="outsource_partner_name" class="form-control" maxlength="150" placeholder="Vendor or individual name" <?= $jobLocked ? 'disabled' : ''; ?>>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Outsource Cost</label>
                    <input id="add-labor-cost" type="number" step="0.01" min="0" name="outsource_cost" class="form-control" value="0" <?= $jobLocked ? 'disabled' : ''; ?>>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Expected Return</label>
                    <input id="add-labor-expected-return" type="date" name="outsource_expected_return_date" class="form-control" <?= $jobLocked ? 'disabled' : ''; ?>>
                  </div>
                  <div class="col-md-1">
                    <label class="form-label">Qty</label>
                    <input type="number" step="0.01" min="0.01" name="quantity" class="form-control" value="1" required <?= $jobLocked ? 'disabled' : ''; ?>>
                  </div>
                  <div class="col-md-2">
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
                  <div class="col-md-1 d-flex align-items-end">
                    <small id="add-labor-outsourced-hint" class="text-muted"></small>
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
                    <th>Execution</th>
                    <th>Qty</th>
                    <th>Rate</th>
                    <th>GST%</th>
                    <th>Bill Total</th>
                    <th>Outsource Cost</th>
                    <th>Payable</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($laborEntries)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">No labor lines added yet.</td></tr>
                  <?php else: ?>
                    <?php foreach ($laborEntries as $line): ?>
                      <?php
                        $lineExecutionType = normalize_labor_execution_type((string) ($line['execution_type'] ?? 'IN_HOUSE'));
                        $lineIsOutsourced = $lineExecutionType === 'OUTSOURCED';
                        $lineOutsourceCost = (float) ($line['outsource_cost'] ?? 0);
                        $linePayableStatus = normalize_outsource_payable_status((string) ($line['outsource_payable_status'] ?? 'UNPAID'));
                        $lineOutsourcedWorkId = (int) ($line['outsourced_work_id'] ?? 0);
                        $lineOutsourcedWorkStatus = normalize_outsourced_work_status((string) ($line['outsourced_work_status'] ?? 'SENT'));
                        $lineOutsourcedPaidAmount = (float) ($line['outsourced_work_paid_amount'] ?? 0);
                        $lineOutsourcedOutstanding = (float) ($line['outsourced_work_outstanding'] ?? 0);
                        $lineOutsourceExpectedReturn = trim((string) ($line['outsourced_work_expected_return_date'] ?? ($line['outsource_expected_return_date'] ?? '')));
                        $lineVendorName = trim((string) ($line['outsource_vendor_name'] ?? ''));
                        $linePartnerName = trim((string) ($line['outsource_partner_name'] ?? ''));
                        $linePartnerLabel = $lineVendorName !== '' ? $lineVendorName : ($linePartnerName !== '' ? $linePartnerName : '-');
                      ?>
                      <tr>
                        <td>
                          <?php if (!empty($line['service_code'])): ?>
                            <?= e((string) $line['service_code']); ?> - <?= e((string) ($line['service_name'] ?? '')); ?>
                            <div class="small text-muted">
                              Category:
                              <?php if (!empty($line['service_category_name'])): ?>
                                <?= e((string) $line['service_category_name']); ?>
                              <?php elseif (!empty($line['service_id'])): ?>
                                Uncategorized (Legacy)
                              <?php else: ?>
                                -
                              <?php endif; ?>
                            </div>
                          <?php else: ?>
                            <span class="text-muted">Custom</span>
                          <?php endif; ?>
                        </td>
                        <td><?= e((string) $line['description']); ?></td>
                        <td>
                          <?php if ($lineIsOutsourced): ?>
                            <span class="badge text-bg-warning">Outsourced</span>
                            <div class="small text-muted"><?= e($linePartnerLabel); ?></div>
                            <?php if ($lineOutsourceExpectedReturn !== ''): ?>
                              <div class="small text-muted">Return: <?= e($lineOutsourceExpectedReturn); ?></div>
                            <?php endif; ?>
                            <?php if ($outsourcedModuleReady && $lineOutsourcedWorkId > 0): ?>
                              <div class="small">
                                <span class="badge text-bg-<?= e(outsourced_work_status_badge_class($lineOutsourcedWorkStatus)); ?>">
                                  <?= e($lineOutsourcedWorkStatus); ?>
                                </span>
                              </div>
                            <?php endif; ?>
                          <?php else: ?>
                            <span class="badge text-bg-success">In-house</span>
                          <?php endif; ?>
                        </td>
                        <td><?= e(number_format((float) $line['quantity'], 2)); ?></td>
                        <td><?= e(format_currency((float) $line['unit_price'])); ?></td>
                        <td><?= e(number_format((float) $line['gst_rate'], 2)); ?></td>
                        <td><?= e(format_currency((float) $line['total_amount'])); ?></td>
                        <td><?= $lineIsOutsourced ? e(format_currency($lineOutsourceCost)) : '-'; ?></td>
                        <td>
                          <?php if ($lineIsOutsourced): ?>
                            <?php if ($outsourcedModuleReady && $lineOutsourcedWorkId > 0): ?>
                              <span class="badge text-bg-<?= e(outsourced_work_status_badge_class($lineOutsourcedWorkStatus)); ?>"><?= e($lineOutsourcedWorkStatus); ?></span>
                              <div class="small text-muted">Paid: <?= e(format_currency($lineOutsourcedPaidAmount)); ?></div>
                              <div class="small text-muted">O/S: <?= e(format_currency($lineOutsourcedOutstanding)); ?></div>
                            <?php else: ?>
                              <span class="badge text-bg-<?= $linePayableStatus === 'PAID' ? 'success' : 'danger'; ?>"><?= e($linePayableStatus); ?></span>
                              <?php if ($linePayableStatus === 'PAID' && !empty($line['outsource_paid_at'])): ?>
                                <div class="small text-muted"><?= e((string) $line['outsource_paid_at']); ?></div>
                              <?php endif; ?>
                            <?php endif; ?>
                          <?php else: ?>
                            -
                          <?php endif; ?>
                        </td>
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
                            <?php if ($lineIsOutsourced): ?>
                              <?php if ($outsourcedModuleReady && $lineOutsourcedWorkId > 0): ?>
                                <a href="<?= e(url('modules/outsourced/index.php?edit_id=' . $lineOutsourcedWorkId)); ?>" class="btn btn-sm btn-outline-warning">Outsource Desk</a>
                              <?php else: ?>
                                <form method="post" class="d-inline">
                                  <?= csrf_field(); ?>
                                  <input type="hidden" name="_action" value="toggle_outsource_payable">
                                  <input type="hidden" name="labor_id" value="<?= (int) $line['id']; ?>">
                                  <input type="hidden" name="next_payable_status" value="<?= $linePayableStatus === 'PAID' ? 'UNPAID' : 'PAID'; ?>">
                                  <button type="submit" class="btn btn-sm btn-outline-<?= $linePayableStatus === 'PAID' ? 'secondary' : 'success'; ?>">
                                    <?= $linePayableStatus === 'PAID' ? 'Mark Unpaid' : 'Mark Paid'; ?>
                                  </button>
                                </form>
                              <?php endif; ?>
                            <?php endif; ?>
                          <?php else: ?>
                            <span class="text-muted">Locked</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                      <?php if ($canEdit && !$jobLocked): ?>
                        <tr class="collapse" id="labor-edit-<?= (int) $line['id']; ?>">
                          <td colspan="10">
                            <form method="post" class="row g-2 p-2 bg-light labor-edit-form">
                              <?= csrf_field(); ?>
                              <input type="hidden" name="_action" value="update_labor">
                              <input type="hidden" name="labor_id" value="<?= (int) $line['id']; ?>">
                              <div class="col-md-3">
                                <input type="text" name="description" class="form-control form-control-sm" value="<?= e((string) $line['description']); ?>" required>
                              </div>
                              <div class="col-md-2">
                                <select name="execution_type" class="form-select form-select-sm js-labor-execution">
                                  <option value="IN_HOUSE" <?= $lineExecutionType === 'IN_HOUSE' ? 'selected' : ''; ?>>In-house</option>
                                  <option value="OUTSOURCED" <?= $lineExecutionType === 'OUTSOURCED' ? 'selected' : ''; ?>>Outsourced</option>
                                </select>
                              </div>
                              <div class="col-md-3">
                                <select name="outsource_vendor_id" class="form-select form-select-sm js-labor-vendor">
                                  <option value="0">Select Vendor (Optional)</option>
                                  <?php foreach ($outsourceVendors as $vendor): ?>
                                    <option value="<?= (int) $vendor['id']; ?>" data-vendor-name="<?= e((string) $vendor['vendor_name']); ?>" <?= ((int) ($line['outsource_vendor_id'] ?? 0) === (int) $vendor['id']) ? 'selected' : ''; ?>><?= e((string) $vendor['vendor_code']); ?> - <?= e((string) $vendor['vendor_name']); ?></option>
                                  <?php endforeach; ?>
                                </select>
                              </div>
                              <div class="col-md-2">
                                <input type="text" name="outsource_partner_name" class="form-control form-control-sm js-labor-partner" maxlength="150" value="<?= e((string) ($line['outsource_partner_name'] ?? '')); ?>" placeholder="Outsourced To">
                              </div>
                              <div class="col-md-2">
                                <input type="number" step="0.01" min="0" name="outsource_cost" class="form-control form-control-sm js-labor-cost" value="<?= e((string) ($line['outsource_cost'] ?? '0')); ?>" placeholder="Outsource Cost">
                              </div>
                              <div class="col-md-2">
                                <input type="date" name="outsource_expected_return_date" class="form-control form-control-sm js-labor-expected-return" value="<?= e((string) ($line['outsourced_work_expected_return_date'] ?? ($line['outsource_expected_return_date'] ?? ''))); ?>" placeholder="Expected Return">
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
                              <div class="col-md-2">
                                <button type="submit" class="btn btn-sm btn-primary w-100">Save</button>
                              </div>
                              <div class="col-md-4 d-flex align-items-center">
                                <small class="text-muted js-labor-outsource-hint"></small>
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
              <form method="post" id="add-part-form">
                <div class="card-body border-bottom row g-2">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="add_part">
                  <input type="hidden" id="add-part-mode" name="part_mode" value="all">
                  <div class="col-12">
                    <label class="form-label d-block mb-1">Selection Mode</label>
                    <div id="add-part-mode-group" class="btn-group btn-group-sm" role="group" aria-label="Part selection mode">
                      <button type="button" class="btn btn-outline-secondary active" data-part-mode="all" <?= $jobLocked ? 'disabled' : ''; ?>>
                        All Parts (<?= e(number_format($partsTotalCount)); ?>)
                      </button>
                      <button type="button" class="btn btn-outline-secondary" data-part-mode="vis" <?= $jobLocked ? 'disabled' : ''; ?>>
                        Vehicle-Compatible (VIS) (<?= e(number_format($partsVisCount)); ?>)
                      </button>
                      <button type="button" class="btn btn-outline-secondary" data-part-mode="stock" <?= $jobLocked ? 'disabled' : ''; ?>>
                        In-Stock (<?= e(number_format($partsInStockCount)); ?>)
                      </button>
                    </div>
                    <small id="add-part-mode-hint" class="text-muted d-block mt-1">VIS suggestions are optional. Use All Parts anytime for manual override.</small>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Part Master</label>
                    <select id="add-part-select" name="part_id" class="form-select" required <?= $jobLocked ? 'disabled' : ''; ?>>
                      <option value="">Select Part</option>
                      <?php foreach ($partsMaster as $part): ?>
                        <?php
                          $partId = (int) ($part['id'] ?? 0);
                          $partStockQty = (float) ($part['stock_qty'] ?? 0);
                          $partNameText = (string) ($part['part_name'] ?? '');
                          $partSkuText = (string) ($part['part_sku'] ?? '');
                          $partVisCompatible = isset($visPartCompatibilityLookup[$partId]);
                        ?>
                        <option
                          value="<?= $partId; ?>"
                          data-price="<?= e((string) $part['selling_price']); ?>"
                          data-gst="<?= e((string) $part['gst_rate']); ?>"
                          data-stock="<?= e((string) $partStockQty); ?>"
                          data-name="<?= e($partNameText); ?>"
                          data-sku="<?= e($partSkuText); ?>"
                          data-vis-compatible="<?= $partVisCompatible ? '1' : '0'; ?>"
                          data-in-stock="<?= $partStockQty > 0 ? '1' : '0'; ?>"
                        >
                          <?= e($partNameText); ?> (<?= e($partSkuText); ?>) | Stock <?= e(number_format($partStockQty, 2)); ?><?= $partVisCompatible ? ' | VIS' : ''; ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <small class="text-muted d-block mt-1">Keyboard: <kbd>/</kbd> search, <kbd>Alt+1</kbd>/<kbd>Alt+2</kbd>/<kbd>Alt+3</kbd> mode.</small>
                    <small id="add-part-stock-hint" class="text-muted d-block mt-1"></small>
                  </div>
                  <div class="col-md-1">
                    <label class="form-label">Qty</label>
                    <input id="add-part-qty" type="number" step="0.01" min="0.01" name="quantity" class="form-control" value="1" required <?= $jobLocked ? 'disabled' : ''; ?>>
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

<?php if (($canEdit || $canClose) && normalize_status_code((string) ($job['status_code'] ?? 'ACTIVE')) !== 'DELETED'): ?>
<div class="modal fade" id="jobDeleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header bg-danger-subtle">
          <h5 class="modal-title">Soft Delete Job Card</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?= csrf_field(); ?>
          <input type="hidden" name="_action" value="soft_delete">
          <div class="mb-3">
            <label class="form-label">Job Reference</label>
            <input type="text" class="form-control" readonly value="<?= e((string) ($job['job_number'] ?? ('#' . $jobId))); ?>">
          </div>
          <div class="mb-0">
            <label class="form-label">Audit Note (Required)</label>
            <textarea name="delete_note" class="form-control" rows="3" maxlength="255" required></textarea>
            <small class="text-muted">If invoice, payment, inventory, or outsourced dependencies exist, deletion will be blocked with reversal steps.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-danger">Confirm Soft Delete</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
  (function () {
    var laborCategorySelect = document.getElementById('add-labor-category');
    var laborServiceSelect = document.getElementById('add-labor-service');
    var laborDescription = document.getElementById('add-labor-description');
    var laborRate = document.getElementById('add-labor-rate');
    var laborGst = document.getElementById('add-labor-gst');
    var laborExecutionSelect = document.getElementById('add-labor-execution');
    var laborVendorSelect = document.getElementById('add-labor-vendor');
    var laborPartnerInput = document.getElementById('add-labor-partner');
    var laborCostInput = document.getElementById('add-labor-cost');
    var laborExpectedReturnInput = document.getElementById('add-labor-expected-return');
    var laborOutsourceHint = document.getElementById('add-labor-outsourced-hint');

    function getSelectedVendorName(select) {
      if (!select) {
        return '';
      }
      var option = select.options[select.selectedIndex];
      if (!option) {
        return '';
      }
      return (option.getAttribute('data-vendor-name') || '').trim();
    }

    function toggleOutsourceFields(executionSelect, vendorSelect, partnerInput, costInput, expectedReturnInput, hintNode, locked) {
      if (!executionSelect || !vendorSelect || !partnerInput || !costInput) {
        return;
      }

      var outsourced = (executionSelect.value || 'IN_HOUSE') === 'OUTSOURCED';
      vendorSelect.disabled = !!locked || !outsourced;
      partnerInput.disabled = !!locked || !outsourced;
      costInput.disabled = !!locked || !outsourced;
      costInput.required = outsourced;
      if (expectedReturnInput) {
        expectedReturnInput.disabled = !!locked || !outsourced;
        expectedReturnInput.required = false;
      }

      if (!outsourced) {
        vendorSelect.value = '0';
        partnerInput.value = '';
        costInput.value = '0';
        if (expectedReturnInput) {
          expectedReturnInput.value = '';
        }
        if (hintNode) {
          hintNode.textContent = '';
        }
        return;
      }

      if (partnerInput.value.trim() === '' && vendorSelect.value !== '0') {
        partnerInput.value = getSelectedVendorName(vendorSelect);
      }
      if (hintNode) {
        hintNode.textContent = 'Vendor/partner and outsource cost are required for outsourced lines.';
      }
    }

    if (laborCategorySelect && laborServiceSelect && laborDescription && laborRate && laborGst) {
      var laborInputsLocked = laborCategorySelect.disabled || laborServiceSelect.disabled;

      function syncLaborServiceOptions() {
        var selectedCategoryKey = laborCategorySelect.value || '';
        var hasCategory = selectedCategoryKey !== '';
        var hasSearchableRefresh = typeof gacRefreshSearchableSelect === 'function';
        for (var i = 0; i < laborServiceSelect.options.length; i++) {
          var option = laborServiceSelect.options[i];
          if (!option) {
            continue;
          }

          if (option.value === '0') {
            option.setAttribute('data-gac-filter-visible', '1');
            if (!hasSearchableRefresh) {
              option.hidden = false;
            }
            continue;
          }

          var optionCategoryKey = option.getAttribute('data-category-key') || '';
          var visibleByCategory = !hasCategory || optionCategoryKey === selectedCategoryKey;
          option.setAttribute('data-gac-filter-visible', visibleByCategory ? '1' : '0');
          if (!hasSearchableRefresh) {
            option.hidden = !visibleByCategory;
          }
        }

        if (hasSearchableRefresh) {
          gacRefreshSearchableSelect(laborServiceSelect);
        }

        if (laborInputsLocked) {
          return;
        }

        laborServiceSelect.disabled = false;
        var currentOption = laborServiceSelect.options[laborServiceSelect.selectedIndex];
        if (currentOption && currentOption.value !== '0' && currentOption.getAttribute('data-gac-filter-visible') === '0') {
          laborServiceSelect.value = '0';
        }
      }

      function applyServiceDefaults() {
        var selected = laborServiceSelect.options[laborServiceSelect.selectedIndex];
        if (!selected) {
          return;
        }

        if (selected.value !== '0') {
          laborDescription.value = selected.getAttribute('data-name') || laborDescription.value;
          laborRate.value = selected.getAttribute('data-rate') || laborRate.value;
          laborGst.value = selected.getAttribute('data-gst') || laborGst.value;
        }
      }

      if (!laborInputsLocked) {
        laborCategorySelect.addEventListener('change', function () {
          syncLaborServiceOptions();
          applyServiceDefaults();
        });
        if (laborExecutionSelect && laborVendorSelect && laborPartnerInput && laborCostInput) {
          laborExecutionSelect.addEventListener('change', function () {
            toggleOutsourceFields(laborExecutionSelect, laborVendorSelect, laborPartnerInput, laborCostInput, laborExpectedReturnInput, laborOutsourceHint, false);
          });
          laborVendorSelect.addEventListener('change', function () {
            if (laborExecutionSelect.value === 'OUTSOURCED' && laborPartnerInput.value.trim() === '') {
              laborPartnerInput.value = getSelectedVendorName(laborVendorSelect);
            }
          });
        }
      }

      laborServiceSelect.addEventListener('change', applyServiceDefaults);
      syncLaborServiceOptions();
      toggleOutsourceFields(
        laborExecutionSelect,
        laborVendorSelect,
        laborPartnerInput,
        laborCostInput,
        laborExpectedReturnInput,
        laborOutsourceHint,
        laborInputsLocked
      );
    }

    var laborEditForms = document.querySelectorAll('.labor-edit-form');
    if (laborEditForms && laborEditForms.length > 0) {
      for (var formIndex = 0; formIndex < laborEditForms.length; formIndex++) {
        var laborEditForm = laborEditForms[formIndex];
        if (!laborEditForm) {
          continue;
        }
        (function (formNode) {
          var editExecutionSelect = formNode.querySelector('.js-labor-execution');
          var editVendorSelect = formNode.querySelector('.js-labor-vendor');
          var editPartnerInput = formNode.querySelector('.js-labor-partner');
          var editCostInput = formNode.querySelector('.js-labor-cost');
          var editExpectedReturnInput = formNode.querySelector('.js-labor-expected-return');
          var editHint = formNode.querySelector('.js-labor-outsource-hint');
          if (!editExecutionSelect || !editVendorSelect || !editPartnerInput || !editCostInput) {
            return;
          }

          function syncEditOutsourceFields() {
            toggleOutsourceFields(editExecutionSelect, editVendorSelect, editPartnerInput, editCostInput, editExpectedReturnInput, editHint, false);
          }

          editExecutionSelect.addEventListener('change', syncEditOutsourceFields);
          editVendorSelect.addEventListener('change', function () {
            if (editExecutionSelect.value === 'OUTSOURCED' && editPartnerInput.value.trim() === '') {
              editPartnerInput.value = getSelectedVendorName(editVendorSelect);
            }
          });
          syncEditOutsourceFields();
        })(laborEditForm);
      }
    }

    var partSelect = document.getElementById('add-part-select');
    var partPrice = document.getElementById('add-part-price');
    var partGst = document.getElementById('add-part-gst');
    var partStockHint = document.getElementById('add-part-stock-hint');
    var partModeInput = document.getElementById('add-part-mode');
    var partModeGroup = document.getElementById('add-part-mode-group');
    var partModeHint = document.getElementById('add-part-mode-hint');
    var partModeButtons = partModeGroup ? partModeGroup.querySelectorAll('[data-part-mode]') : [];

    function normalizePartSearch(value) {
      return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function partModeLabel(mode) {
      if (mode === 'vis') {
        return 'Vehicle-Compatible (VIS)';
      }
      if (mode === 'stock') {
        return 'In-Stock';
      }
      return 'All Parts';
    }

    function getPartOptionByValue(value) {
      if (!partSelect) {
        return null;
      }
      var targetValue = String(value || '');
      for (var index = 0; index < partSelect.options.length; index++) {
        var option = partSelect.options[index];
        if (option && option.value === targetValue) {
          return option;
        }
      }
      return null;
    }

    function optionMatchesPartMode(option, mode) {
      if (!option || option.value === '') {
        return true;
      }
      if (mode === 'vis') {
        return (option.getAttribute('data-vis-compatible') || '0') === '1';
      }
      if (mode === 'stock') {
        return (option.getAttribute('data-in-stock') || '0') === '1';
      }
      return true;
    }

    function getPartSearchInput() {
      if (!partSelect) {
        return null;
      }
      if (typeof gacSearchableSelectInput === 'function') {
        var enhancedInput = gacSearchableSelectInput(partSelect);
        if (enhancedInput) {
          return enhancedInput;
        }
      }
      if (!partSelect.closest) {
        return null;
      }
      var wrapper = partSelect.closest('.gac-searchable-select');
      if (!wrapper) {
        return null;
      }
      return wrapper.querySelector('.gac-combobox-input');
    }

    function updatePartModeButtons(activeMode) {
      for (var index = 0; index < partModeButtons.length; index++) {
        var button = partModeButtons[index];
        if (!button) {
          continue;
        }
        var mode = button.getAttribute('data-part-mode') || 'all';
        var isActive = mode === activeMode;
        button.classList.toggle('active', isActive);
        button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
      }
    }

    function syncPartOptionVisibility() {
      if (!partSelect) {
        return;
      }

      var mode = partModeInput ? (partModeInput.value || 'all') : 'all';
      var searchInput = getPartSearchInput();
      var searchTerm = normalizePartSearch(searchInput ? searchInput.value : '');
      var selectedValue = partSelect.value || '';
      var selectedForcedVisible = false;

      for (var index = 0; index < partSelect.options.length; index++) {
        var option = partSelect.options[index];
        if (!option) {
          continue;
        }
        if (option.value === '') {
          option.setAttribute('data-gac-filter-visible', '1');
          option.hidden = false;
          continue;
        }

        var visibleByRule = optionMatchesPartMode(option, mode);
        if (!visibleByRule && selectedValue !== '' && option.value === selectedValue) {
          visibleByRule = true;
          selectedForcedVisible = true;
        }

        option.setAttribute('data-gac-filter-visible', visibleByRule ? '1' : '0');
        if (typeof gacRefreshSearchableSelect !== 'function') {
          option.hidden = !visibleByRule;
        }
      }

      if (typeof gacRefreshSearchableSelect === 'function') {
        gacRefreshSearchableSelect(partSelect);
      }

      var visibleCount = 0;
      for (var countIndex = 0; countIndex < partSelect.options.length; countIndex++) {
        var visibleOption = partSelect.options[countIndex];
        if (!visibleOption || visibleOption.value === '' || visibleOption.hidden) {
          continue;
        }
        visibleCount++;
      }

      if (selectedValue === '') {
        return updatePartModeHint(mode, searchTerm, visibleCount, false);
      }

      var selectedOption = getPartOptionByValue(selectedValue);
      if (!selectedOption) {
        partSelect.value = '';
      }

      updatePartModeHint(mode, searchTerm, visibleCount, selectedForcedVisible);
    }

    function updatePartModeHint(mode, searchTerm, visibleCount, selectedForcedVisible) {
      if (!partModeHint) {
        return;
      }

      var message = visibleCount + ' part(s) in ' + partModeLabel(mode) + ' mode.';
      if (searchTerm !== '') {
        message += ' Search filter is active.';
      }
      if (visibleCount === 0) {
        message += ' No matches. Switch mode or clear search.';
      }
      if (selectedForcedVisible) {
        message += ' Selected part kept visible as manual override.';
      }
      if (mode === 'vis') {
        message += ' VIS suggestions are optional.';
      }
      partModeHint.textContent = message;
    }

    function applyPartDefaults() {
      if (!partSelect || !partPrice || !partGst || !partStockHint) {
        return;
      }
      var selected = partSelect.options[partSelect.selectedIndex];
      if (!selected || selected.value === '') {
        partStockHint.textContent = '';
        return;
      }

      partPrice.value = selected.getAttribute('data-price') || partPrice.value;
      partGst.value = selected.getAttribute('data-gst') || partGst.value;
      var stock = selected.getAttribute('data-stock') || '0';
      var visCompatible = (selected.getAttribute('data-vis-compatible') || '0') === '1';
      partStockHint.textContent = 'Garage stock: ' + stock + ' | ' + (visCompatible ? 'VIS compatible' : 'Manual override part');
    }

    function setPartMode(mode, focusSearch) {
      var normalizedMode = mode === 'vis' || mode === 'stock' ? mode : 'all';
      if (partModeInput) {
        partModeInput.value = normalizedMode;
      }
      updatePartModeButtons(normalizedMode);
      syncPartOptionVisibility();
      applyPartDefaults();

      var partSearchInput = getPartSearchInput();
      if (focusSearch) {
        if (partSearchInput && !partSearchInput.disabled) {
          partSearchInput.focus();
          if (typeof partSearchInput.select === 'function') {
            partSearchInput.select();
          }
        } else if (partSelect && !partSelect.disabled) {
          partSelect.focus();
        }
      }
    }

    if (partSelect && partPrice && partGst && partStockHint) {
      partSelect.addEventListener('change', function () {
        applyPartDefaults();
        syncPartOptionVisibility();
      });

      document.addEventListener('input', function (event) {
        var partSearchInput = getPartSearchInput();
        if (!partSearchInput || event.target !== partSearchInput) {
          return;
        }
        window.requestAnimationFrame(function () {
          syncPartOptionVisibility();
        });
      });

      for (var buttonIndex = 0; buttonIndex < partModeButtons.length; buttonIndex++) {
        var modeButton = partModeButtons[buttonIndex];
        if (!modeButton) {
          continue;
        }
        modeButton.addEventListener('click', function () {
          setPartMode((this.getAttribute('data-part-mode') || 'all'), false);
        });
      }

      document.addEventListener('keydown', function (event) {
        var targetTag = (event.target && event.target.tagName ? event.target.tagName : '').toUpperCase();
        var isTypingContext = targetTag === 'INPUT' || targetTag === 'TEXTAREA' || targetTag === 'SELECT';

        if (!isTypingContext && event.key === '/' && !event.altKey && !event.ctrlKey && !event.metaKey) {
          var partSearchInput = getPartSearchInput();
          if (partSearchInput && !partSearchInput.disabled) {
            event.preventDefault();
            partSearchInput.focus();
            if (typeof partSearchInput.select === 'function') {
              partSearchInput.select();
            }
          } else if (partSelect && !partSelect.disabled) {
            event.preventDefault();
            partSelect.focus();
          }
          return;
        }

        if (!event.altKey || event.ctrlKey || event.metaKey) {
          return;
        }

        var shortcut = String(event.key || '').toLowerCase();
        if (shortcut === '1') {
          event.preventDefault();
          setPartMode('all', true);
          return;
        }
        if (shortcut === '2') {
          event.preventDefault();
          setPartMode('vis', true);
          return;
        }
        if (shortcut === '3') {
          event.preventDefault();
          setPartMode('stock', true);
          return;
        }
        if (shortcut === 's' && laborServiceSelect && !laborServiceSelect.disabled) {
          event.preventDefault();
          laborServiceSelect.focus();
        }
      });

      setPartMode(partModeInput ? partModeInput.value : 'all', false);
      applyPartDefaults();
      window.setTimeout(syncPartOptionVisibility, 0);
    }

    var nextStatusSelect = document.getElementById('next-status-select');
    var statusNoteInput = document.getElementById('status-note-input');
    var closeReminderPreview = document.getElementById('close-reminder-preview');
    var reminderRows = document.querySelectorAll('[data-reminder-row]');

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

    function toggleCloseReminderPreview() {
      if (!nextStatusSelect || !closeReminderPreview) {
        return;
      }
      if (nextStatusSelect.value === 'CLOSED') {
        closeReminderPreview.classList.remove('d-none');
      } else {
        closeReminderPreview.classList.add('d-none');
      }
    }

    function syncReminderRowInputs(row) {
      if (!row) {
        return;
      }
      var enableCheckbox = row.querySelector('.js-reminder-enable');
      if (!enableCheckbox) {
        return;
      }
      var rowInputs = row.querySelectorAll('.js-reminder-input');
      for (var index = 0; index < rowInputs.length; index++) {
        rowInputs[index].disabled = !enableCheckbox.checked;
      }
    }

    if (reminderRows && reminderRows.length > 0) {
      for (var rowIndex = 0; rowIndex < reminderRows.length; rowIndex++) {
        var reminderRow = reminderRows[rowIndex];
        var rowCheckbox = reminderRow ? reminderRow.querySelector('.js-reminder-enable') : null;
        if (!rowCheckbox) {
          continue;
        }
        rowCheckbox.addEventListener('change', (function (rowRef) {
          return function () {
            syncReminderRowInputs(rowRef);
          };
        })(reminderRow));
        syncReminderRowInputs(reminderRow);
      }
    }

    if (nextStatusSelect && statusNoteInput) {
      nextStatusSelect.addEventListener('change', function () {
        toggleStatusNoteRequirement();
        toggleCloseReminderPreview();
      });
      toggleStatusNoteRequirement();
      toggleCloseReminderPreview();
    }
  })();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
