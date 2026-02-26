<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('job.view');
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/insurance.php';

$page_title = 'Job Card Details';
$active_menu = 'jobs';
$companyId = active_company_id();
$garageId = active_garage_id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$canCreate = has_permission('job.create') || has_permission('job.manage');
$canEdit = has_permission('job.edit') || has_permission('job.update') || has_permission('job.manage');
$canAssign = has_permission('job.assign') || has_permission('job.manage');
$canClose = has_permission('job.close') || has_permission('job.manage');
$canManageManualReminders = has_permission('job.manage') || has_permission('settings.manage') || has_permission('job.create');
$canConditionPhotoUpload = $canEdit || $canCreate;
$jobCardColumns = table_columns('job_cards');
$jobOdometerEnabled = in_array('odometer_km', $jobCardColumns, true);
$jobRecommendationNoteEnabled = job_recommendation_note_feature_ready();
$jobInsuranceEnabled = job_insurance_feature_ready();
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

function job_value_has_fraction(float $value): bool
{
    return abs($value - round($value)) > 0.00001;
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
        'SELECT jp.*, p.part_name, p.part_sku, p.unit AS part_unit, COALESCE(gi.quantity, 0) AS stock_qty
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
        'SELECT p.id, p.part_name, p.part_sku, p.unit, p.selling_price, p.gst_rate,
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
        $safeDeleteValidation = safe_delete_validate_post_confirmation('job_condition_photo', $photoId, [
            'operation' => 'delete',
            'reason_field' => 'deletion_reason',
        ]);
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
        safe_delete_log_cascade('job_condition_photo', 'delete', $photoId, $safeDeleteValidation, [
            'metadata' => [
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'job_id' => $jobId,
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

    if ($action === 'update_recommendation_note') {
        if (!$jobRecommendationNoteEnabled) {
            flash_set('job_error', 'Recommendation note storage is not ready.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }
        if (!$canEdit) {
            flash_set('job_error', 'You do not have permission to update recommendation note.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }
        if (normalize_status_code((string) ($jobForWrite['status_code'] ?? 'ACTIVE')) === 'DELETED') {
            flash_set('job_error', 'Deleted job cards cannot be modified.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
        }

        $recommendationNote = post_string('recommendation_note', 5000);
        $previousNote = trim((string) ($jobForWrite['recommendation_note'] ?? ''));
        $nextNote = trim((string) $recommendationNote);

        $updateStmt = db()->prepare(
            'UPDATE job_cards
             SET recommendation_note = :recommendation_note,
                 updated_by = :updated_by
             WHERE id = :id
               AND company_id = :company_id
               AND garage_id = :garage_id
               AND status_code <> "DELETED"'
        );
        $updateStmt->execute([
            'recommendation_note' => $nextNote !== '' ? $nextNote : null,
            'updated_by' => $userId > 0 ? $userId : null,
            'id' => $jobId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);

        if ($previousNote !== $nextNote) {
            job_append_history(
                $jobId,
                'UPDATE_RECOMMENDATION_NOTE',
                null,
                null,
                'Recommendation note updated',
                [
                    'from_length' => mb_strlen($previousNote),
                    'to_length' => mb_strlen($nextNote),
                    'has_note' => $nextNote !== '',
                ]
            );
            log_audit('job_cards', 'update_recommendation_note', $jobId, 'Updated recommendation note', [
                'entity' => 'job_card',
                'source' => 'UI',
                'before' => [
                    'recommendation_note' => $previousNote !== '' ? $previousNote : null,
                ],
                'after' => [
                    'recommendation_note' => $nextNote !== '' ? $nextNote : null,
                ],
            ]);
        }

        flash_set('job_success', 'Recommendation note saved.', 'success');
        redirect('modules/jobs/view.php?id=' . $jobId . '#job-information');
    }

    if ($action === 'update_insurance_claim') {
        if (!$jobInsuranceEnabled) {
            flash_set('job_error', 'Insurance claim storage is not ready.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#insurance-claim');
        }
        if (!$canEdit) {
            flash_set('job_error', 'You do not have permission to update insurance details.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#insurance-claim');
        }
        if (normalize_status_code((string) ($jobForWrite['status_code'] ?? 'ACTIVE')) === 'DELETED') {
            flash_set('job_error', 'Deleted job cards cannot be modified.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#insurance-claim');
        }

        $insuranceCompanyName = post_string('insurance_company_name', 150);
        $insuranceClaimNumber = post_string('insurance_claim_number', 80);
        $insuranceSurveyorName = post_string('insurance_surveyor_name', 120);
        $insuranceClaimAmountApproved = job_insurance_parse_amount($_POST['insurance_claim_amount_approved'] ?? null);
        $insuranceCustomerPayableAmount = job_insurance_parse_amount($_POST['insurance_customer_payable_amount'] ?? null);
        $insuranceClaimStatus = job_insurance_normalize_status((string) ($_POST['insurance_claim_status'] ?? 'PENDING'));

        $updateStmt = db()->prepare(
            'UPDATE job_cards
             SET insurance_company_name = :insurance_company_name,
                 insurance_claim_number = :insurance_claim_number,
                 insurance_surveyor_name = :insurance_surveyor_name,
                 insurance_claim_amount_approved = :insurance_claim_amount_approved,
                 insurance_customer_payable_amount = :insurance_customer_payable_amount,
                 insurance_claim_status = :insurance_claim_status,
                 updated_by = :updated_by
             WHERE id = :id
               AND company_id = :company_id
               AND garage_id = :garage_id
               AND status_code <> "DELETED"'
        );
        $updateStmt->execute([
            'insurance_company_name' => $insuranceCompanyName !== '' ? $insuranceCompanyName : null,
            'insurance_claim_number' => $insuranceClaimNumber !== '' ? $insuranceClaimNumber : null,
            'insurance_surveyor_name' => $insuranceSurveyorName !== '' ? $insuranceSurveyorName : null,
            'insurance_claim_amount_approved' => $insuranceClaimAmountApproved,
            'insurance_customer_payable_amount' => $insuranceCustomerPayableAmount,
            'insurance_claim_status' => $insuranceClaimStatus,
            'updated_by' => $userId > 0 ? $userId : null,
            'id' => $jobId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);

        job_append_history(
            $jobId,
            'UPDATE_INSURANCE_CLAIM',
            null,
            null,
            'Insurance claim details updated',
            [
                'claim_number' => $insuranceClaimNumber !== '' ? $insuranceClaimNumber : null,
                'claim_status' => $insuranceClaimStatus,
                'claim_amount_approved' => $insuranceClaimAmountApproved,
                'customer_payable_amount' => $insuranceCustomerPayableAmount,
            ]
        );
        log_audit('job_cards', 'update_insurance_claim', $jobId, 'Updated insurance claim details', [
            'entity' => 'job_card',
            'source' => 'UI',
            'before' => [
                'insurance_company_name' => (string) ($jobForWrite['insurance_company_name'] ?? ''),
                'insurance_claim_number' => (string) ($jobForWrite['insurance_claim_number'] ?? ''),
                'insurance_surveyor_name' => (string) ($jobForWrite['insurance_surveyor_name'] ?? ''),
                'insurance_claim_amount_approved' => $jobForWrite['insurance_claim_amount_approved'] !== null ? (float) $jobForWrite['insurance_claim_amount_approved'] : null,
                'insurance_customer_payable_amount' => $jobForWrite['insurance_customer_payable_amount'] !== null ? (float) $jobForWrite['insurance_customer_payable_amount'] : null,
                'insurance_claim_status' => (string) ($jobForWrite['insurance_claim_status'] ?? ''),
            ],
            'after' => [
                'insurance_company_name' => $insuranceCompanyName !== '' ? $insuranceCompanyName : null,
                'insurance_claim_number' => $insuranceClaimNumber !== '' ? $insuranceClaimNumber : null,
                'insurance_surveyor_name' => $insuranceSurveyorName !== '' ? $insuranceSurveyorName : null,
                'insurance_claim_amount_approved' => $insuranceClaimAmountApproved,
                'insurance_customer_payable_amount' => $insuranceCustomerPayableAmount,
                'insurance_claim_status' => $insuranceClaimStatus,
            ],
        ]);

        flash_set('job_success', 'Insurance claim details saved.', 'success');
        redirect('modules/jobs/view.php?id=' . $jobId . '#insurance-claim');
    }

    if ($action === 'upload_insurance_document') {
        if (!$jobInsuranceEnabled) {
            flash_set('job_error', 'Insurance document storage is not ready.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#insurance-claim');
        }
        if (!$canEdit) {
            flash_set('job_error', 'You do not have permission to upload insurance documents.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#insurance-claim');
        }
        if (normalize_status_code((string) ($jobForWrite['status_code'] ?? 'ACTIVE')) === 'DELETED') {
            flash_set('job_error', 'Deleted job cards cannot be modified.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#insurance-claim');
        }

        $note = post_string('insurance_document_note', 255);
        $uploadResult = job_insurance_store_document_upload(
            $_FILES['insurance_document'] ?? [],
            $companyId,
            $garageId,
            $jobId,
            $userId,
            $note !== '' ? $note : null
        );
        if (!(bool) ($uploadResult['ok'] ?? false)) {
            flash_set('job_error', (string) ($uploadResult['message'] ?? 'Unable to upload insurance document.'), 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#insurance-claim');
        }

        $documentId = (int) ($uploadResult['document_id'] ?? 0);
        job_append_history(
            $jobId,
            'UPLOAD_INSURANCE_DOCUMENT',
            null,
            null,
            'Uploaded insurance document',
            [
                'document_id' => $documentId > 0 ? $documentId : null,
                'file_name' => (string) ($uploadResult['file_name'] ?? ''),
                'file_size_bytes' => (int) ($uploadResult['file_size_bytes'] ?? 0),
            ]
        );
        log_audit('job_cards', 'upload_insurance_document', $jobId, 'Uploaded insurance document', [
            'entity' => 'job_insurance_documents',
            'source' => 'UI',
            'metadata' => [
                'document_id' => $documentId > 0 ? $documentId : null,
                'file_name' => (string) ($uploadResult['file_name'] ?? ''),
            ],
        ]);
        flash_set('job_success', 'Insurance document uploaded.', 'success');
        redirect('modules/jobs/view.php?id=' . $jobId . '#insurance-claim');
    }

    if ($action === 'delete_insurance_document') {
        if (!$jobInsuranceEnabled) {
            flash_set('job_error', 'Insurance document storage is not ready.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#insurance-claim');
        }
        if (!$canEdit) {
            flash_set('job_error', 'You do not have permission to delete insurance documents.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#insurance-claim');
        }

        $documentId = post_int('insurance_document_id');
        $safeDeleteValidation = safe_delete_validate_post_confirmation('job_insurance_document', $documentId, [
            'operation' => 'delete',
            'reason_field' => 'deletion_reason',
        ]);
        $deleteResult = job_insurance_delete_document($documentId, $companyId, $garageId);
        if (!(bool) ($deleteResult['ok'] ?? false)) {
            flash_set('job_error', (string) ($deleteResult['message'] ?? 'Unable to delete insurance document.'), 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#insurance-claim');
        }

        job_append_history(
            $jobId,
            'DELETE_INSURANCE_DOCUMENT',
            null,
            null,
            'Deleted insurance document',
            [
                'document_id' => $documentId,
                'file_deleted' => (bool) ($deleteResult['file_deleted'] ?? false),
            ]
        );
        log_audit('job_cards', 'delete_insurance_document', $jobId, 'Deleted insurance document', [
            'entity' => 'job_insurance_documents',
            'source' => 'UI',
            'metadata' => [
                'document_id' => $documentId,
                'file_deleted' => (bool) ($deleteResult['file_deleted'] ?? false),
            ],
        ]);
        safe_delete_log_cascade('job_insurance_document', 'delete', $documentId, $safeDeleteValidation, [
            'metadata' => [
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'job_id' => $jobId,
                'file_deleted' => (bool) ($deleteResult['file_deleted'] ?? false),
            ],
        ]);
        flash_set('job_success', 'Insurance document deleted.', 'success');
        redirect('modules/jobs/view.php?id=' . $jobId . '#insurance-claim');
    }

    if ($action === 'add_manual_service_reminder') {
        if (!$canManageManualReminders) {
            flash_set('job_error', 'You do not have permission to add manual reminders.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#service-reminders');
        }
        if (!service_reminder_feature_ready()) {
            flash_set('job_error', 'Maintenance reminder storage is not ready.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#service-reminders');
        }
        if (normalize_status_code((string) ($jobForWrite['status_code'] ?? 'ACTIVE')) === 'DELETED') {
            flash_set('job_error', 'Deleted job cards cannot be modified.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#service-reminders');
        }

        $vehicleId = (int) ($jobForWrite['vehicle_id'] ?? 0);
        if ($vehicleId <= 0) {
            flash_set('job_error', 'Invalid vehicle linked to this job.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#service-reminders');
        }

        $itemType = service_reminder_normalize_type((string) ($_POST['item_type'] ?? ''));
        $itemId = post_int('item_id');
        $itemKey = trim((string) ($_POST['item_key'] ?? ''));
        if (($itemType === '' || $itemId <= 0) && preg_match('/^(SERVICE|PART):(\d+)$/', strtoupper($itemKey), $matches) === 1) {
            $itemType = service_reminder_normalize_type((string) ($matches[1] ?? ''));
            $itemId = (int) ($matches[2] ?? 0);
        }

        $nextDueKm = service_reminder_parse_positive_int($_POST['next_due_km'] ?? null);
        $nextDueDate = service_reminder_parse_date((string) ($_POST['next_due_date'] ?? ''));
        $predictedVisitDate = service_reminder_parse_date((string) ($_POST['predicted_next_visit_date'] ?? ''));
        $recommendationText = post_string('recommendation_text', 255);

        $manualResult = service_reminder_create_manual_entry(
            $companyId,
            $garageId,
            $vehicleId,
            $itemType,
            $itemId,
            $nextDueKm,
            $nextDueDate,
            $predictedVisitDate,
            $recommendationText,
            $userId
        );

        if (!(bool) ($manualResult['ok'] ?? false)) {
            $message = trim((string) ($manualResult['message'] ?? 'Unable to add manual reminder.'));
            flash_set('job_error', $message !== '' ? $message : 'Unable to add manual reminder.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#service-reminders');
        }

        $reminderId = (int) ($manualResult['reminder_id'] ?? 0);
        job_append_history(
            $jobId,
            'ADD_MANUAL_REMINDER',
            null,
            null,
            'Admin manual reminder added',
            [
                'reminder_id' => $reminderId > 0 ? $reminderId : null,
                'item_type' => $itemType,
                'item_id' => $itemId,
                'source_type' => 'ADMIN_MANUAL',
            ]
        );
        log_audit('job_cards', 'add_manual_reminder', $jobId, 'Added manual maintenance reminder from job view', [
            'entity' => 'job_card',
            'source' => 'UI',
            'metadata' => [
                'reminder_id' => $reminderId > 0 ? $reminderId : null,
                'vehicle_id' => $vehicleId,
                'item_type' => $itemType,
                'item_id' => $itemId,
                'source_type' => 'ADMIN_MANUAL',
            ],
        ]);

        flash_set('job_success', 'Manual reminder added successfully.', 'success');
        redirect('modules/jobs/view.php?id=' . $jobId . '#service-reminders');
    }

    if ($action === 'add_reminder_to_job') {
        if (!$canEdit) {
            flash_set('job_error', 'You do not have permission to add reminder items to job lines.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#service-reminders');
        }
        if (job_is_locked($jobForWrite)) {
            flash_set('job_error', 'This job card is locked and cannot be modified.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#service-reminders');
        }
        if (!service_reminder_feature_ready()) {
            flash_set('job_error', 'Maintenance reminder storage is not ready.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#service-reminders');
        }

        $reminderId = post_int('reminder_id');
        if ($reminderId <= 0) {
            flash_set('job_error', 'Invalid reminder selected.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#service-reminders');
        }

        $vehicleId = (int) ($jobForWrite['vehicle_id'] ?? 0);
        $customerId = (int) ($jobForWrite['customer_id'] ?? 0);
        if ($vehicleId <= 0 || $customerId <= 0) {
            flash_set('job_error', 'Job vehicle/customer details are missing for reminder action.', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId . '#service-reminders');
        }

        $odometerKm = $jobOdometerEnabled
            ? service_reminder_parse_positive_int($jobForWrite['odometer_km'] ?? null)
            : null;

        $selectedReminderStmt = db()->prepare(
            'SELECT id, item_type, item_id, item_name
             FROM vehicle_maintenance_reminders
             WHERE id = :id
               AND company_id = :company_id
               AND vehicle_id = :vehicle_id
               AND is_active = 1
               AND status_code = "ACTIVE"
             LIMIT 1'
        );
        $selectedReminderStmt->execute([
            'id' => $reminderId,
            'company_id' => $companyId,
            'vehicle_id' => $vehicleId,
        ]);
        $selectedReminder = $selectedReminderStmt->fetch() ?: null;
        if (!$selectedReminder) {
            flash_set('job_warning', 'Selected reminder is no longer active.', 'warning');
            redirect('modules/jobs/view.php?id=' . $jobId . '#service-reminders');
        }

        $result = service_reminder_apply_job_creation_actions(
            $companyId,
            $garageId,
            $jobId,
            $vehicleId,
            $customerId,
            $odometerKm,
            $userId,
            [$reminderId => 'add']
        );

        $addedCount = (int) ($result['added_count'] ?? 0);
        $warnings = array_values(array_filter(array_map('trim', (array) ($result['warnings'] ?? []))));
        $visPartAddCount = 0;
        $visPartIds = [];

        $selectedType = service_reminder_normalize_type((string) ($selectedReminder['item_type'] ?? ''));
        $selectedItemId = (int) ($selectedReminder['item_id'] ?? 0);
        if ($addedCount > 0 && $selectedType === 'SERVICE' && $selectedItemId > 0) {
            $mappedParts = job_fetch_vis_parts_for_service($companyId, $garageId, $vehicleId, $selectedItemId);
            if ($mappedParts !== []) {
                $visPartResult = job_add_vis_parts_to_job($jobId, $mappedParts);
                $visPartAddCount = (int) ($visPartResult['added_count'] ?? 0);
                $visPartIds = array_values(array_unique(array_map('intval', (array) ($visPartResult['part_ids'] ?? []))));
                $warnings = array_values(array_filter(array_merge(
                    $warnings,
                    array_map('trim', (array) ($visPartResult['warnings'] ?? []))
                )));
            }
        }

        if ($addedCount > 0) {
            job_append_history(
                $jobId,
                'ADD_FROM_REMINDER',
                null,
                null,
                'Added reminder item to job lines',
                [
                    'reminder_id' => $reminderId,
                    'added_count' => $addedCount,
                    'selected_type' => $selectedType,
                    'selected_item_id' => $selectedItemId,
                    'vis_parts_added' => $visPartAddCount,
                    'vis_part_ids' => $visPartIds,
                ]
            );
            log_audit('job_cards', 'add_from_reminder', $jobId, 'Added reminder item to job lines', [
                'entity' => 'job_card',
                'source' => 'UI',
                'metadata' => [
                    'reminder_id' => $reminderId,
                    'added_count' => $addedCount,
                    'selected_type' => $selectedType,
                    'selected_item_id' => $selectedItemId,
                    'vis_parts_added' => $visPartAddCount,
                    'vis_part_ids' => $visPartIds,
                ],
            ]);
            $successMessage = 'Reminder item added to job lines.';
            if ($selectedType === 'SERVICE') {
                if ($visPartAddCount > 0) {
                    $successMessage = 'Service added. VIS compatible parts also added: ' . $visPartAddCount . '.';
                } else {
                    $successMessage = 'Service added. No VIS compatible mapped parts found for this vehicle/service.';
                }
            }
            flash_set('job_success', $successMessage, 'success');
            if (!empty($warnings)) {
                flash_set('job_warning', implode(' | ', array_slice($warnings, 0, 2)), 'warning');
            }
        } else {
            $message = 'Reminder could not be added. It may already be completed/inactive.';
            if (!empty($warnings)) {
                $message = implode(' | ', array_slice($warnings, 0, 2));
            }
            flash_set('job_warning', $message, 'warning');
        }

        redirect('modules/jobs/view.php?id=' . $jobId . '#service-reminders');
    }

    if ($action === 'transition_status') {
        $targetStatus = strtoupper(trim((string) ($_POST['next_status'] ?? '')));
        $statusNote = post_string('status_note', 255);
        $currentStatus = job_normalize_status((string) $jobForWrite['status']);
        $isReopenFromClosed = ($currentStatus === 'CLOSED' && $targetStatus === 'OPEN');
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

        if (job_is_locked($jobForWrite) && !$isReopenFromClosed) {
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
        $reopenResult = [
            'reversed' => false,
            'reversal_count' => 0,
            'movement_uids' => [],
            'warnings' => [],
        ];
        if ($targetStatus === 'CLOSED') {
            try {
                $postResult = job_post_inventory_on_close($jobId, $companyId, $garageId, $userId);
                $inventoryWarnings = $postResult['warnings'] ?? [];
            } catch (Throwable $exception) {
                flash_set('job_error', 'Unable to post inventory while closing job: ' . $exception->getMessage(), 'danger');
                redirect('modules/jobs/view.php?id=' . $jobId);
            }
        } elseif ($isReopenFromClosed) {
            try {
                $activeInvoiceStmt = db()->prepare(
                    'SELECT id, invoice_number, invoice_status
                     FROM invoices
                     WHERE job_card_id = :job_id
                       AND company_id = :company_id
                       AND garage_id = :garage_id
                       AND invoice_status IN ("DRAFT", "FINALIZED")
                     ORDER BY id DESC
                     LIMIT 1'
                );
                $activeInvoiceStmt->execute([
                    'job_id' => $jobId,
                    'company_id' => $companyId,
                    'garage_id' => $garageId,
                ]);
                $activeInvoice = $activeInvoiceStmt->fetch() ?: null;
                if ($activeInvoice) {
                    throw new RuntimeException('Cancel the active invoice ' . (string) ($activeInvoice['invoice_number'] ?? ('#' . (int) ($activeInvoice['id'] ?? 0))) . ' before reopening this job.');
                }

                $reopenResult = job_reverse_inventory_on_reopen($jobId, $companyId, $garageId, $userId);
                $inventoryWarnings = array_values(array_filter(array_map('trim', (array) ($reopenResult['warnings'] ?? []))));
            } catch (Throwable $exception) {
                flash_set('job_error', 'Unable to reopen job inventory posting: ' . $exception->getMessage(), 'danger');
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
                     WHEN :status = "OPEN" THEN NULL
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
                $userId
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
                'reopen_stock_reversal_count' => (int) ($reopenResult['reversal_count'] ?? 0),
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
                'reopen_stock_reversal_count' => (int) ($reopenResult['reversal_count'] ?? 0),
                'reminders_created' => (int) ($reminderResult['created_count'] ?? 0),
                'reminders_disabled' => (int) ($reminderResult['disabled_count'] ?? 0),
                'reminder_types' => (array) ($reminderResult['created_types'] ?? []),
                'reopen_movement_uids' => (array) ($reopenResult['movement_uids'] ?? []),
            ],
        ]);

        flash_set('job_success', 'Job status updated to ' . $targetStatus . '.', 'success');
        if ($targetStatus === 'CLOSED' && (int) ($reminderResult['created_count'] ?? 0) > 0) {
            flash_set('job_success', 'Auto maintenance reminders created: ' . (int) $reminderResult['created_count'] . '.', 'success');
        } elseif ($isReopenFromClosed && (int) ($reopenResult['reversal_count'] ?? 0) > 0) {
            flash_set('job_success', 'Job reopened and stock postings reversed: ' . (int) ($reopenResult['reversal_count'] ?? 0) . ' line(s).', 'success');
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
            flash_set('job_warning', 'Maintenance reminder warnings: ' . $preview, 'warning');
        }

        redirect('modules/jobs/view.php?id=' . $jobId);
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
        $safeDeleteValidation = null;
        try {
            $safeDeleteValidation = safe_delete_validate_post_confirmation('job_card', $jobId, [
                'operation' => 'delete',
                'reason_field' => 'delete_note',
            ]);
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

            $jobCardColumns = table_columns('job_cards');
            $deleteSetParts = [
                'status_code = "DELETED"',
                'deleted_at = NOW()',
                'cancel_note = :cancel_note',
                'updated_by = :updated_by',
            ];
            $deleteParams = [
                'cancel_note' => $deleteNote,
                'updated_by' => $userId > 0 ? $userId : null,
                'id' => $jobId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ];
            if (in_array('deleted_by', $jobCardColumns, true)) {
                $deleteSetParts[] = 'deleted_by = :deleted_by';
                $deleteParams['deleted_by'] = $userId > 0 ? $userId : null;
            }
            if (in_array('deletion_reason', $jobCardColumns, true)) {
                $deleteSetParts[] = 'deletion_reason = :deletion_reason';
                $deleteParams['deletion_reason'] = $deleteNote;
            }

            $deleteStmt = $pdo->prepare(
                'UPDATE job_cards
                 SET ' . implode(', ', $deleteSetParts) . '
                 WHERE id = :id
                   AND company_id = :company_id
                   AND garage_id = :garage_id'
            );
            $deleteStmt->execute($deleteParams);

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
            safe_delete_log_cascade('job_card', 'delete', $jobId, (array) $safeDeleteValidation, [
                'metadata' => [
                    'job_number' => (string) ($jobForWrite['job_number'] ?? ''),
                    'inventory_movements' => (int) ($dependencyReport['inventory_movements'] ?? 0),
                    'cancellable_outsourced_count' => count($cancellableOutsourcedIds),
                ],
            ]);
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
        $visSuggestionSource = trim((string) ($_POST['vis_suggestion_source'] ?? ''));
        $autoAddVisMappedParts = $visSuggestionSource === 'SERVICE';
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
        $jobVehicleId = (int) ($jobForWrite['vehicle_id'] ?? 0);
        $visPartAddCount = 0;
        $visPartIds = [];
        $visWarnings = [];
        if ($autoAddVisMappedParts && $serviceId > 0 && $jobVehicleId > 0) {
            $mappedParts = job_fetch_vis_parts_for_service($companyId, $garageId, $jobVehicleId, $serviceId);
            if ($mappedParts !== []) {
                $visPartResult = job_add_vis_parts_to_job($jobId, $mappedParts);
                $visPartAddCount = (int) ($visPartResult['added_count'] ?? 0);
                $visPartIds = array_values(array_unique(array_map('intval', (array) ($visPartResult['part_ids'] ?? []))));
                $visWarnings = array_values(array_filter(array_map('trim', (array) ($visPartResult['warnings'] ?? []))));
            }
        }

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
                'vis_suggestion_source' => $autoAddVisMappedParts ? 'SERVICE' : null,
                'vis_parts_added' => $visPartAddCount,
                'vis_part_ids' => $visPartIds,
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

        if ($autoAddVisMappedParts && $serviceId > 0) {
            if ($visPartAddCount > 0) {
                flash_set('job_success', 'Suggested service added with VIS compatible parts: ' . $visPartAddCount . '.', 'success');
            } else {
                flash_set('job_success', 'Suggested service added. No VIS compatible mapped parts found for this service/vehicle variant.', 'success');
            }
            if (!empty($visWarnings)) {
                flash_set('job_warning', implode(' | ', array_slice($visWarnings, 0, 2)), 'warning');
            }
        } else {
            flash_set('job_success', 'Labor line added successfully.', 'success');
        }
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
        $safeDeleteValidation = safe_delete_validate_post_confirmation('job_labor_line', $laborId, [
            'operation' => 'delete',
            'reason_field' => 'deletion_reason',
        ]);

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
        safe_delete_log_cascade('job_labor_line', 'delete', $laborId, $safeDeleteValidation, [
            'metadata' => [
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'job_id' => $jobId,
            ],
        ]);

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
            'SELECT p.id, p.part_name, p.part_sku, p.unit, p.selling_price, p.gst_rate,
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

        $partUnit = part_unit_normalize_code((string) ($part['unit'] ?? ''));
        if ($partUnit === '') {
            $partUnit = 'PCS';
        }
        $allowsDecimalQty = part_unit_allows_decimal($companyId, $partUnit);
        if (!$allowsDecimalQty && job_value_has_fraction($quantity)) {
            flash_set('job_error', 'Quantity for ' . (string) $part['part_name'] . ' must be a whole number (' . $partUnit . ').', 'danger');
            redirect('modules/jobs/view.php?id=' . $jobId);
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
                'part_unit' => $partUnit,
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
                'Stock warning: ' . (string) $part['part_name'] . ' requires ' . number_format($quantity, 2) . ' ' . $partUnit . ', available ' . number_format($availableQty, 2) . ' ' . $partUnit . '. Closing will still post stock.',
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
            'SELECT jp.id, jp.part_id, p.part_name, p.unit AS part_unit, COALESCE(gi.quantity, 0) AS stock_qty
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

        $partUnit = part_unit_normalize_code((string) ($line['part_unit'] ?? ''));
        if ($partUnit === '') {
            $partUnit = 'PCS';
        }
        if (!part_unit_allows_decimal($companyId, $partUnit) && job_value_has_fraction($quantity)) {
            flash_set('job_error', 'Quantity for ' . (string) $line['part_name'] . ' must be a whole number (' . $partUnit . ').', 'danger');
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
                'part_unit' => $partUnit,
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
                'Stock warning: ' . (string) $line['part_name'] . ' requires ' . number_format($quantity, 2) . ' ' . $partUnit . ', available ' . number_format($availableQty, 2) . ' ' . $partUnit . '. Closing will still post stock.',
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
        $safeDeleteValidation = safe_delete_validate_post_confirmation('job_part_line', $jobPartId, [
            'operation' => 'delete',
            'reason_field' => 'deletion_reason',
        ]);

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
        safe_delete_log_cascade('job_part_line', 'delete', $jobPartId, $safeDeleteValidation, [
            'metadata' => [
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'job_id' => $jobId,
            ],
        ]);

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
    'SELECT i.id, i.invoice_number, i.grand_total, i.payment_status, i.invoice_status,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id), 0) AS paid_amount
     FROM invoices i
     WHERE i.job_card_id = :job_id
       AND i.company_id = :company_id
       AND i.garage_id = :garage_id
     ORDER BY i.id DESC
     LIMIT 1'
);
$invoiceStmt->execute([
    'job_id' => $jobId,
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$invoice = $invoiceStmt->fetch() ?: null;
$advanceSummary = [
    'advance_amount' => 0.0,
    'adjusted_amount' => 0.0,
    'balance_amount' => 0.0,
    'receipt_count' => 0,
];
if (table_columns('job_advances') !== []) {
    $advanceStmt = db()->prepare(
        'SELECT COALESCE(SUM(advance_amount), 0) AS advance_amount,
                COALESCE(SUM(adjusted_amount), 0) AS adjusted_amount,
                COALESCE(SUM(balance_amount), 0) AS balance_amount,
                COUNT(*) AS receipt_count
         FROM job_advances
         WHERE company_id = :company_id
           AND garage_id = :garage_id
           AND job_card_id = :job_id
           AND status_code = "ACTIVE"'
    );
    $advanceStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'job_id' => $jobId,
    ]);
    $advanceRow = $advanceStmt->fetch() ?: [];
    $advanceSummary['advance_amount'] = round((float) ($advanceRow['advance_amount'] ?? 0), 2);
    $advanceSummary['adjusted_amount'] = round((float) ($advanceRow['adjusted_amount'] ?? 0), 2);
    $advanceSummary['balance_amount'] = round((float) ($advanceRow['balance_amount'] ?? 0), 2);
    $advanceSummary['receipt_count'] = (int) ($advanceRow['receipt_count'] ?? 0);
}
$pendingBaseInvoice = null;
if ($invoice) {
    $invoiceStatusForPending = strtoupper(trim((string) ($invoice['invoice_status'] ?? '')));
    if (in_array($invoiceStatusForPending, ['DRAFT', 'FINALIZED'], true)) {
        $pendingBaseInvoice = $invoice;
    }
}

$invoiceTotalAmount = $pendingBaseInvoice ? round((float) ($pendingBaseInvoice['grand_total'] ?? 0), 2) : $estimatedTotal;
if ($invoiceTotalAmount <= 0.009) {
    $invoiceTotalAmount = $estimatedTotal;
}
$invoiceReceivedAmount = $pendingBaseInvoice ? round((float) ($pendingBaseInvoice['paid_amount'] ?? 0), 2) : 0.0;
$advanceReceivedAmount = (float) ($advanceSummary['advance_amount'] ?? 0);
$advanceReceiptCount = (int) ($advanceSummary['receipt_count'] ?? 0);
$advanceReceiptLabel = $advanceReceiptCount === 1 ? 'receipt' : 'receipts';
$totalReceivedAmount = round($invoiceReceivedAmount + $advanceReceivedAmount, 2);
$pendingAmount = max(0.0, round($invoiceTotalAmount - $totalReceivedAmount, 2));
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
$insuranceDocuments = $jobInsuranceEnabled
    ? job_insurance_documents_by_job($companyId, $garageId, $jobId, 120)
    : [];
$insuranceDocumentCount = count($insuranceDocuments);
$insuranceDocMaxMb = $jobInsuranceEnabled ? round(job_insurance_doc_max_upload_bytes($companyId, $garageId) / 1048576, 2) : 0.0;
$jobInsuranceStatus = $jobInsuranceEnabled
    ? job_insurance_normalize_status((string) ($job['insurance_claim_status'] ?? 'PENDING'))
    : 'PENDING';

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
$workflowTransitionLocked = $jobLocked && !($jobStatus === 'CLOSED' && in_array('OPEN', $nextStatuses, true));

$serviceReminderFeatureReady = service_reminder_feature_ready();
$activeServiceReminders = $serviceReminderFeatureReady
    ? service_reminder_fetch_active_by_vehicle($companyId, (int) $job['vehicle_id'], $garageId, 25)
    : [];
$serviceReminderSummary = service_reminder_summary_counts($activeServiceReminders);
$manualReminderItems = ($serviceReminderFeatureReady && $canManageManualReminders)
    ? service_reminder_master_items($companyId, true)
    : [];

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
        <div class="col-lg-2 col-md-4">
          <div class="small-box text-bg-<?= e(job_status_badge_class((string) $job['status'])); ?>">
            <div class="inner">
              <h4><?= e((string) $job['status']); ?></h4>
              <p>Current Workflow State</p>
            </div>
            <span class="small-box-icon"><i class="bi bi-diagram-3"></i></span>
          </div>
        </div>
        <div class="col-lg-2 col-md-4">
          <div class="small-box text-bg-success">
            <div class="inner">
              <h4><?= e(format_currency($laborTotal)); ?></h4>
              <p>Labor Value</p>
            </div>
            <span class="small-box-icon"><i class="bi bi-tools"></i></span>
          </div>
        </div>
        <div class="col-lg-2 col-md-4">
          <div class="small-box text-bg-warning">
            <div class="inner">
              <h4><?= e(format_currency($partsTotal)); ?></h4>
              <p>Parts Value</p>
            </div>
            <span class="small-box-icon"><i class="bi bi-box-seam"></i></span>
          </div>
        </div>
        <div class="col-lg-2 col-md-4">
          <div class="small-box text-bg-danger">
            <div class="inner">
              <h4><?= e(format_currency($estimatedTotal)); ?></h4>
              <p>Estimated Total</p>
            </div>
            <span class="small-box-icon"><i class="bi bi-cash-stack"></i></span>
          </div>
        </div>
        <div class="col-lg-2 col-md-4">
          <div class="small-box text-bg-info">
            <div class="inner">
              <h4><?= e(format_currency($totalReceivedAmount)); ?></h4>
              <p>Received Amount</p>
              <?php if ($advanceReceivedAmount > 0.009): ?>
                <small>Advance: <?= e(format_currency($advanceReceivedAmount)); ?></small>
              <?php endif; ?>
            </div>
            <span class="small-box-icon"><i class="bi bi-wallet2"></i></span>
          </div>
        </div>
        <div class="col-lg-2 col-md-4">
          <div class="small-box text-bg-primary">
            <div class="inner">
              <h4><?= e(format_currency($pendingAmount)); ?></h4>
              <p>Pending Amount</p>
            </div>
            <span class="small-box-icon"><i class="bi bi-hourglass-split"></i></span>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-xl-4">
          <div class="card" id="job-information">
            <div class="card-header"><h3 class="card-title">Job Information</h3></div>
            <div class="card-body">
              <p class="mb-2"><strong>Status:</strong> <span class="badge text-bg-<?= e(job_status_badge_class((string) $job['status'])); ?>"><?= e((string) $job['status']); ?></span></p>
              <p class="mb-2"><strong>Priority:</strong> <span class="badge text-bg-<?= e(job_priority_badge_class((string) $job['priority'])); ?>"><?= e((string) $job['priority']); ?></span></p>
              <p class="mb-2"><strong>Opened At:</strong> <?= e((string) $job['opened_at']); ?></p>
              <p class="mb-2"><strong>Promised At:</strong> <?= e((string) ($job['promised_at'] ?? '-')); ?></p>
              <p class="mb-2"><strong>Service Advisor:</strong> <?= e((string) ($job['advisor_name'] ?? '-')); ?></p>
              <p class="mb-2"><strong>Assigned Staff:</strong> <?= e((string) (($job['assigned_staff'] ?? '') !== '' ? $job['assigned_staff'] : 'Unassigned')); ?></p>
              <p class="mb-2"><strong>Customer:</strong> <?= e((string) $job['customer_name']); ?> (<?= e((string) $job['customer_phone']); ?>)</p>
              <?php if ($advanceReceivedAmount > 0.009): ?>
                <p class="mb-2"><strong>Advance Received:</strong> <?= e(format_currency($advanceReceivedAmount)); ?> (<?= e(number_format($advanceReceiptCount)); ?> <?= e($advanceReceiptLabel); ?>)</p>
              <?php endif; ?>
              <p class="mb-2"><strong>Vehicle:</strong> <?= e((string) $job['registration_no']); ?> | <?= e((string) $job['brand']); ?> <?= e((string) $job['model']); ?> <?= e((string) ($job['variant'] ?? '')); ?></p>
              <?php if ($jobOdometerEnabled): ?>
                <p class="mb-2"><strong>Odometer:</strong> <?= e(number_format((float) ($job['odometer_km'] ?? 0), 0)); ?> KM</p>
              <?php endif; ?>
              <p class="mb-2"><strong>Complaint:</strong><br><?= nl2br(e((string) $job['complaint'])); ?></p>
              <p class="mb-2"><strong>Diagnosis:</strong><br><?= nl2br(e((string) ($job['diagnosis'] ?? '-'))); ?></p>
              <?php if ($jobRecommendationNoteEnabled): ?>
                <p class="mb-2"><strong>Recommendation Note:</strong><br><?= nl2br(e((string) ((($job['recommendation_note'] ?? '') !== '') ? $job['recommendation_note'] : '-'))); ?></p>
                <?php if ($canEdit && normalize_status_code((string) ($job['status_code'] ?? 'ACTIVE')) !== 'DELETED'): ?>
                  <form method="post" class="mt-2">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="_action" value="update_recommendation_note">
                    <label class="form-label">Update Recommendation Note</label>
                    <textarea name="recommendation_note" class="form-control" rows="3" maxlength="5000"><?= e((string) ($job['recommendation_note'] ?? '')); ?></textarea>
                    <div class="d-flex justify-content-end mt-2">
                      <button type="submit" class="btn btn-sm btn-outline-primary">Save Recommendation Note</button>
                    </div>
                  </form>
                <?php endif; ?>
              <?php endif; ?>
              <?php if ($jobInsuranceEnabled): ?>
                <hr class="my-3">
                <div id="insurance-claim">
                  <p class="mb-2"><strong>Insurance Company:</strong> <?= e((string) ((($job['insurance_company_name'] ?? '') !== '') ? $job['insurance_company_name'] : '-')); ?></p>
                  <p class="mb-2"><strong>Claim Number:</strong> <?= e((string) ((($job['insurance_claim_number'] ?? '') !== '') ? $job['insurance_claim_number'] : '-')); ?></p>
                  <p class="mb-2"><strong>Surveyor:</strong> <?= e((string) ((($job['insurance_surveyor_name'] ?? '') !== '') ? $job['insurance_surveyor_name'] : '-')); ?></p>
                  <p class="mb-2"><strong>Claim Status:</strong> <span class="badge text-bg-<?= e($jobInsuranceStatus === 'SETTLED' ? 'success' : ($jobInsuranceStatus === 'APPROVED' ? 'primary' : ($jobInsuranceStatus === 'REJECTED' ? 'danger' : 'warning'))); ?>"><?= e($jobInsuranceStatus); ?></span></p>
                  <p class="mb-2"><strong>Claim Amount Approved:</strong> <?= e(format_currency((float) ($job['insurance_claim_amount_approved'] ?? 0))); ?></p>
                  <p class="mb-3"><strong>Customer Payable Difference:</strong> <?= e(format_currency((float) ($job['insurance_customer_payable_amount'] ?? 0))); ?></p>
                  <?php if ($canEdit && normalize_status_code((string) ($job['status_code'] ?? 'ACTIVE')) !== 'DELETED'): ?>
                    <form method="post" class="row g-2">
                      <?= csrf_field(); ?>
                      <input type="hidden" name="_action" value="update_insurance_claim">
                      <div class="col-md-6">
                        <label class="form-label">Insurance Company</label>
                        <input type="text" name="insurance_company_name" class="form-control" maxlength="150" value="<?= e((string) ($job['insurance_company_name'] ?? '')); ?>">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">Claim Number</label>
                        <input type="text" name="insurance_claim_number" class="form-control" maxlength="80" value="<?= e((string) ($job['insurance_claim_number'] ?? '')); ?>">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">Surveyor Name</label>
                        <input type="text" name="insurance_surveyor_name" class="form-control" maxlength="120" value="<?= e((string) ($job['insurance_surveyor_name'] ?? '')); ?>">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">Claim Status</label>
                        <select name="insurance_claim_status" class="form-select">
                          <?php foreach (job_insurance_allowed_statuses() as $claimStatusOption): ?>
                            <option value="<?= e($claimStatusOption); ?>" <?= $jobInsuranceStatus === $claimStatusOption ? 'selected' : ''; ?>><?= e($claimStatusOption); ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">Claim Amount Approved</label>
                        <input type="number" name="insurance_claim_amount_approved" class="form-control" step="0.01" min="0"
                               value="<?= e(($job['insurance_claim_amount_approved'] ?? null) !== null ? number_format((float) $job['insurance_claim_amount_approved'], 2, '.', '') : ''); ?>">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">Customer Payable Difference</label>
                        <input type="number" name="insurance_customer_payable_amount" class="form-control" step="0.01" min="0"
                               value="<?= e(($job['insurance_customer_payable_amount'] ?? null) !== null ? number_format((float) $job['insurance_customer_payable_amount'], 2, '.', '') : ''); ?>">
                      </div>
                      <div class="col-12 d-flex justify-content-end">
                        <button type="submit" class="btn btn-sm btn-outline-primary">Save Insurance Details</button>
                      </div>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
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
                            <form method="post"
                                  class="mt-2"
                                  data-safe-delete
                                  data-safe-delete-entity="job_condition_photo"
                                  data-safe-delete-record-field="photo_id"
                                  data-safe-delete-operation="delete"
                                  data-safe-delete-reason-field="deletion_reason">
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

          <?php if ($jobInsuranceEnabled): ?>
            <div class="card card-outline card-info" id="insurance-documents">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Insurance Documents</h3>
                <span class="badge text-bg-light border"><?= (int) $insuranceDocumentCount; ?></span>
              </div>
              <div class="card-body">
                <p class="text-muted small mb-3">
                  Upload claim papers, surveyor reports, and insurer approvals. Max file size: <?= e(number_format($insuranceDocMaxMb, 2)); ?> MB per file.
                </p>
                <?php if ($canEdit && normalize_status_code((string) ($job['status_code'] ?? 'ACTIVE')) !== 'DELETED'): ?>
                  <form method="post" enctype="multipart/form-data" class="row g-2 mb-3">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="_action" value="upload_insurance_document">
                    <div class="col-md-7">
                      <label class="form-label">Select Document</label>
                      <input type="file" name="insurance_document" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" required>
                    </div>
                    <div class="col-md-5">
                      <label class="form-label">Note (Optional)</label>
                      <input type="text" name="insurance_document_note" class="form-control" maxlength="255" placeholder="Survey report, approval letter, etc.">
                    </div>
                    <div class="col-12">
                      <button type="submit" class="btn btn-outline-primary">Upload Insurance Document</button>
                    </div>
                  </form>
                <?php endif; ?>

                <div class="list-group">
                  <?php if ($insuranceDocuments === []): ?>
                    <div class="text-muted">No insurance documents uploaded yet.</div>
                  <?php else: ?>
                    <?php foreach ($insuranceDocuments as $document): ?>
                      <?php $documentUrl = job_insurance_doc_url((string) ($document['file_path'] ?? '')); ?>
                      <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                          <div class="me-2">
                            <?php if ($documentUrl !== null): ?>
                              <a href="<?= e($documentUrl); ?>" target="_blank"><strong><?= e((string) ($document['file_name'] ?? 'Document')); ?></strong></a>
                            <?php else: ?>
                              <strong><?= e((string) ($document['file_name'] ?? 'Document')); ?></strong>
                              <span class="text-danger small ms-1">(File missing)</span>
                            <?php endif; ?>
                            <div class="small text-muted">
                              Uploaded: <?= e((string) ($document['created_at'] ?? '')); ?>
                              <?php if (!empty($document['uploaded_by_name'])): ?>
                                | By <?= e((string) $document['uploaded_by_name']); ?>
                              <?php endif; ?>
                              | Size: <?= e(number_format(((float) ($document['file_size_bytes'] ?? 0)) / 1024, 1)); ?> KB
                            </div>
                            <?php if (!empty($document['note'])): ?>
                              <div class="small mt-1"><?= e((string) $document['note']); ?></div>
                            <?php endif; ?>
                          </div>
                          <?php if ($canEdit): ?>
                            <form method="post"
                                  data-safe-delete
                                  data-safe-delete-entity="job_insurance_document"
                                  data-safe-delete-record-field="insurance_document_id"
                                  data-safe-delete-operation="delete"
                                  data-safe-delete-reason-field="deletion_reason">
                              <?= csrf_field(); ?>
                              <input type="hidden" name="_action" value="delete_insurance_document">
                              <input type="hidden" name="insurance_document_id" value="<?= (int) ($document['id'] ?? 0); ?>">
                              <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                          <?php endif; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endif; ?>

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
                      <select id="next-status-select" name="next_status" class="form-select" required <?= $workflowTransitionLocked ? 'disabled' : ''; ?>>
                        <?php foreach ($nextStatuses as $status): ?>
                          <option value="<?= e($status); ?>"><?= e($status); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div>
                      <label class="form-label">Audit Note</label>
                      <textarea id="status-note-input" name="status_note" class="form-control" rows="2" placeholder="Optional note (required for CANCELLED)"></textarea>
                    </div>

                    <?php if (in_array('CLOSED', $nextStatuses, true)): ?>
                      <div class="alert alert-light border mt-3 mb-0">
                        On <strong>CLOSE</strong>, maintenance reminders are auto-generated from completed services/parts using vehicle-specific maintenance rules.
                      </div>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
                <div class="card-footer">
                  <button type="submit" class="btn btn-primary" <?= $workflowTransitionLocked || empty($nextStatuses) ? 'disabled' : ''; ?>>Apply Transition</button>
                </div>
              </form>
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
                            <input type="hidden" name="vis_suggestion_source" value="SERVICE">
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
                  <div class="col-md-2">
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
                            <form method="post"
                                  class="d-inline"
                                  data-safe-delete
                                  data-safe-delete-entity="job_labor_line"
                                  data-safe-delete-record-field="labor_id"
                                  data-safe-delete-operation="delete"
                                  data-safe-delete-reason-field="deletion_reason">
                              <?= csrf_field(); ?>
                              <input type="hidden" name="_action" value="delete_labor">
                              <input type="hidden" name="labor_id" value="<?= (int) $line['id']; ?>">
                              <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
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
                          $partUnitCode = part_unit_normalize_code((string) ($part['unit'] ?? ''));
                          if ($partUnitCode === '') {
                              $partUnitCode = 'PCS';
                          }
                          $partUnitLabel = part_unit_label($companyId, $partUnitCode);
                          $partAllowsDecimal = part_unit_allows_decimal($companyId, $partUnitCode);
                          $partVisCompatible = isset($visPartCompatibilityLookup[$partId]);
                        ?>
                        <option
                          value="<?= $partId; ?>"
                          data-price="<?= e((string) $part['selling_price']); ?>"
                          data-gst="<?= e((string) $part['gst_rate']); ?>"
                          data-stock="<?= e((string) $partStockQty); ?>"
                          data-unit="<?= e($partUnitCode); ?>"
                          data-unit-label="<?= e($partUnitLabel); ?>"
                          data-allow-decimal="<?= $partAllowsDecimal ? '1' : '0'; ?>"
                          data-name="<?= e($partNameText); ?>"
                          data-sku="<?= e($partSkuText); ?>"
                          data-vis-compatible="<?= $partVisCompatible ? '1' : '0'; ?>"
                          data-in-stock="<?= $partStockQty > 0 ? '1' : '0'; ?>"
                        >
                          <?= e($partNameText); ?> (<?= e($partSkuText); ?>) | Stock <?= e(number_format($partStockQty, 2)); ?> <?= e($partUnitCode); ?><?= $partVisCompatible ? ' | VIS' : ''; ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <small class="text-muted d-block mt-1">Keyboard: <kbd>/</kbd> search, <kbd>Alt+1</kbd>/<kbd>Alt+2</kbd>/<kbd>Alt+3</kbd> mode.</small>
                    <small id="add-part-stock-hint" class="text-muted d-block mt-1"></small>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Qty</label>
                    <input id="add-part-qty" type="number" step="1" min="1" name="quantity" class="form-control" value="1" required <?= $jobLocked ? 'disabled' : ''; ?>>
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
                        $lineUnitCode = part_unit_normalize_code((string) ($line['part_unit'] ?? ''));
                        if ($lineUnitCode === '') {
                            $lineUnitCode = 'PCS';
                        }
                        $lineAllowsDecimal = part_unit_allows_decimal($companyId, $lineUnitCode);
                        $stockClass = $lineStock >= $lineQty ? 'text-success' : 'text-danger';
                      ?>
                      <tr>
                        <td><?= e((string) $line['part_name']); ?> (<?= e((string) $line['part_sku']); ?>)</td>
                        <td><?= e(number_format($lineQty, 2)); ?> <?= e($lineUnitCode); ?></td>
                        <td><?= e(format_currency((float) $line['unit_price'])); ?></td>
                        <td><?= e(number_format((float) $line['gst_rate'], 2)); ?></td>
                        <td><?= e(format_currency((float) $line['total_amount'])); ?></td>
                        <td>
                          <span class="<?= e($stockClass); ?>"><?= e(number_format($lineStock, 2)); ?> <?= e($lineUnitCode); ?></span>
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
                            <form method="post"
                                  class="d-inline"
                                  data-safe-delete
                                  data-safe-delete-entity="job_part_line"
                                  data-safe-delete-record-field="job_part_id"
                                  data-safe-delete-operation="delete"
                                  data-safe-delete-reason-field="deletion_reason">
                              <?= csrf_field(); ?>
                              <input type="hidden" name="_action" value="delete_part">
                              <input type="hidden" name="job_part_id" value="<?= (int) $line['id']; ?>">
                              <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
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
                                <input type="number" step="<?= $lineAllowsDecimal ? '0.01' : '1'; ?>" min="<?= $lineAllowsDecimal ? '0.01' : '1'; ?>" name="quantity" class="form-control form-control-sm" value="<?= e((string) $line['quantity']); ?>" required>
                                <small class="text-muted"><?= e($lineUnitCode); ?><?= $lineAllowsDecimal ? ' (decimal allowed)' : ' (whole only)'; ?></small>
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

          <div class="card card-outline card-success" id="service-reminders">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Maintenance Reminders</h3>
              <span class="badge text-bg-light border"><?= (int) ($serviceReminderSummary['total'] ?? 0); ?> Active</span>
            </div>
            <div class="card-body">
              <?php if (!$serviceReminderFeatureReady): ?>
                <div class="alert alert-warning mb-0">Maintenance reminder storage is not ready. Please run DB upgrade.</div>
              <?php else: ?>
                <?php if ($canManageManualReminders): ?>
                  <div class="card card-outline card-success mb-3">
                    <div class="card-header py-2"><h3 class="card-title mb-0">Add Manual Service Reminder (Admin)</h3></div>
                    <div class="card-body">
                      <?php if (empty($manualReminderItems)): ?>
                        <div class="text-muted small">No reminder-enabled services/parts found in masters.</div>
                      <?php else: ?>
                        <form method="post" class="row g-2 align-items-end">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="_action" value="add_manual_service_reminder">
                          <div class="col-lg-5">
                            <label class="form-label">Service / Part</label>
                            <select name="item_key" class="form-select" required>
                              <option value="">Select Reminder-Enabled Item</option>
                              <?php foreach ($manualReminderItems as $manualItem): ?>
                                <?php
                                  $manualType = service_reminder_normalize_type((string) ($manualItem['item_type'] ?? ''));
                                  $manualId = (int) ($manualItem['item_id'] ?? 0);
                                  if ($manualType === '' || $manualId <= 0) {
                                      continue;
                                  }
                                  $manualName = trim((string) ($manualItem['item_name'] ?? ''));
                                  $manualCode = trim((string) ($manualItem['item_code'] ?? ''));
                                ?>
                                <option value="<?= e($manualType . ':' . $manualId); ?>">
                                  [<?= e($manualType); ?>] <?= e($manualName !== '' ? $manualName : ($manualType . ' #' . $manualId)); ?><?= $manualCode !== '' ? ' (' . e($manualCode) . ')' : ''; ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div class="col-lg-2">
                            <label class="form-label">Due KM</label>
                            <input type="number" name="next_due_km" class="form-control" min="0" placeholder="Optional">
                          </div>
                          <div class="col-lg-2">
                            <label class="form-label">Due Date</label>
                            <input type="date" name="next_due_date" class="form-control">
                          </div>
                          <div class="col-lg-3">
                            <label class="form-label">Predicted Visit</label>
                            <input type="date" name="predicted_next_visit_date" class="form-control">
                          </div>
                          <div class="col-lg-9">
                            <label class="form-label">Recommendation</label>
                            <input type="text" name="recommendation_text" class="form-control" maxlength="255" placeholder="Optional recommendation text">
                          </div>
                          <div class="col-lg-3 d-grid">
                            <button type="submit" class="btn btn-success">Add Manual Reminder</button>
                          </div>
                        </form>
                        <div class="form-text mt-2">Reminder source will be saved as <strong>ADMIN_MANUAL</strong>.</div>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endif; ?>

                <div class="row g-2 mb-3">
                  <div class="col-6 col-lg-3">
                    <div class="small text-muted">Overdue</div>
                    <div class="fw-semibold text-danger"><?= (int) ($serviceReminderSummary['overdue'] ?? 0); ?></div>
                  </div>
                  <div class="col-6 col-lg-3">
                    <div class="small text-muted">Due</div>
                    <div class="fw-semibold text-warning"><?= (int) (($serviceReminderSummary['due'] ?? 0) + ($serviceReminderSummary['due_soon'] ?? 0)); ?></div>
                  </div>
                  <div class="col-6 col-lg-3">
                    <div class="small text-muted">Upcoming</div>
                    <div class="fw-semibold text-info"><?= (int) ($serviceReminderSummary['upcoming'] ?? 0); ?></div>
                  </div>
                  <div class="col-6 col-lg-3">
                    <div class="small text-muted">Completed</div>
                    <div class="fw-semibold text-secondary"><?= (int) ($serviceReminderSummary['completed'] ?? 0); ?></div>
                  </div>
                </div>

                <div class="small text-muted mb-2">
                  Quick Add: selecting <strong>Add</strong> on a service reminder adds the service line and all VIS-mapped compatible parts for this vehicle variant.
                </div>

                <div class="table-responsive mb-3">
                  <table class="table table-sm table-bordered align-middle mb-0">
                    <thead>
                      <tr>
                        <th>Service/Part</th>
                        <th>Due KM</th>
                        <th>Due Date</th>
                        <th>Predicted Visit</th>
                        <th>Status</th>
                        <th>Source</th>
                        <th>Recommendation</th>
                        <th>Action</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($activeServiceReminders)): ?>
                        <tr><td colspan="8" class="text-center text-muted">No active reminders for this vehicle.</td></tr>
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
                            <td>
                              <?php if ($canEdit && !$jobLocked): ?>
                                <form method="post" class="d-inline">
                                  <?= csrf_field(); ?>
                                  <input type="hidden" name="_action" value="add_reminder_to_job">
                                  <input type="hidden" name="reminder_id" value="<?= (int) ($reminder['id'] ?? 0); ?>">
                                  <button type="submit" class="btn btn-sm btn-outline-primary">Add</button>
                                </form>
                              <?php else: ?>
                                <span class="text-muted small">Locked</span>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
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
      <form method="post"
            data-safe-delete
            data-safe-delete-entity="job_card"
            data-safe-delete-record-field="job_id"
            data-safe-delete-operation="delete"
            data-safe-delete-reason-field="delete_note">
        <div class="modal-header bg-danger-subtle">
          <h5 class="modal-title">Soft Delete Job Card</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?= csrf_field(); ?>
          <input type="hidden" name="_action" value="soft_delete">
          <input type="hidden" name="job_id" value="<?= (int) $jobId; ?>">
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
    var partQty = document.getElementById('add-part-qty');
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
      if (!partSelect || !partPrice || !partGst || !partStockHint || !partQty) {
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
      var unitCode = selected.getAttribute('data-unit') || 'PCS';
      var unitLabel = selected.getAttribute('data-unit-label') || unitCode;
      var allowDecimal = (selected.getAttribute('data-allow-decimal') || '1') === '1';
      partQty.step = allowDecimal ? '0.01' : '1';
      partQty.min = allowDecimal ? '0.01' : '1';
      if (!allowDecimal) {
        var currentQty = readNumber(partQty.value || 0);
        var wholeQty = Math.max(1, Math.round(currentQty));
        partQty.value = String(wholeQty);
      } else {
        var decimalQty = readNumber(partQty.value || 0);
        if (decimalQty < 0.01) {
          partQty.value = '1';
        }
      }
      var visCompatible = (selected.getAttribute('data-vis-compatible') || '0') === '1';
      var unitRule = allowDecimal ? 'decimal quantity allowed' : 'whole quantity only';
      partStockHint.textContent = 'Garage stock: ' + stock + ' ' + unitCode + ' (' + unitLabel + ') | ' + unitRule + ' | ' + (visCompatible ? 'VIS compatible' : 'Manual override part');
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

    if (partSelect && partQty && partPrice && partGst && partStockHint) {
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
      nextStatusSelect.addEventListener('change', function () {
        toggleStatusNoteRequirement();
      });
      toggleStatusNoteRequirement();
    }
  })();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
