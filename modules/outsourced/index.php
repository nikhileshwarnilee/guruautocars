<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('outsourced.view');

$page_title = 'Outsourced Works';
$active_menu = 'outsourced.index';

$companyId = active_company_id();
$garageId = active_garage_id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$canManage = has_permission('outsourced.manage');
$canPay = has_permission('outsourced.pay') || $canManage;
$canExport = has_permission('export.data') || $canManage;

function ow_decimal(mixed $raw, float $default = 0.0): float
{
    if (is_array($raw)) {
        return $default;
    }

    $value = trim((string) $raw);
    if ($value === '') {
        return $default;
    }

    $normalized = str_replace([',', ' '], '', $value);
    if (!is_numeric($normalized)) {
        return $default;
    }

    return (float) $normalized;
}

function ow_is_valid_date(?string $value): bool
{
    if ($value === null || $value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $value));
    return checkdate($month, $day, $year);
}

function ow_normalize_status(?string $status): string
{
    $normalized = strtoupper(trim((string) $status));
    return in_array($normalized, ['SENT', 'RECEIVED', 'VERIFIED', 'PAYABLE', 'PAID'], true) ? $normalized : 'SENT';
}

function ow_status_rank(string $status): int
{
    return match (ow_normalize_status($status)) {
        'SENT' => 1,
        'RECEIVED' => 2,
        'VERIFIED' => 3,
        'PAYABLE' => 4,
        'PAID' => 5,
        default => 1,
    };
}

function ow_next_status(string $status): ?string
{
    return match (ow_normalize_status($status)) {
        'SENT' => 'RECEIVED',
        'RECEIVED' => 'VERIFIED',
        'VERIFIED' => 'PAYABLE',
        'PAYABLE' => 'PAID',
        default => null,
    };
}

function ow_status_badge_class(string $status): string
{
    return match (ow_normalize_status($status)) {
        'SENT' => 'secondary',
        'RECEIVED' => 'info',
        'VERIFIED' => 'primary',
        'PAYABLE' => 'warning',
        'PAID' => 'success',
        default => 'secondary',
    };
}

function ow_payment_modes(): array
{
    return ['CASH', 'UPI', 'CARD', 'BANK_TRANSFER', 'CHEQUE', 'MIXED', 'ADJUSTMENT'];
}

function ow_csv_download(string $filename, array $headers, array $rows, array $filterSummary): never
{
    log_data_export('outsourced_works', 'CSV', count($rows), [
        'company_id' => active_company_id(),
        'garage_id' => active_garage_id() > 0 ? active_garage_id() : null,
        'filter_summary' => json_encode($filterSummary, JSON_UNESCAPED_UNICODE),
        'scope' => $filterSummary,
        'requested_by' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
    ]);

    log_audit('exports', 'download', null, 'Exported outsourced CSV: ' . $filename, [
        'entity' => 'data_export',
        'source' => 'UI',
        'metadata' => [
            'module' => 'outsourced',
            'format' => 'CSV',
            'row_count' => count($rows),
            'filters' => $filterSummary,
        ],
    ]);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $stream = fopen('php://output', 'w');
    if ($stream === false) {
        http_response_code(500);
        exit('Unable to generate CSV export.');
    }

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

function ow_append_history(int $workId, string $actionType, ?string $fromStatus, ?string $toStatus, ?string $note, ?array $payload, int $userId): void
{
    if (table_columns('outsourced_work_history') === []) {
        return;
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO outsourced_work_history
              (outsourced_work_id, action_type, from_status, to_status, action_note, payload_json, created_by)
             VALUES
              (:outsourced_work_id, :action_type, :from_status, :to_status, :action_note, :payload_json, :created_by)'
        );
        $stmt->execute([
            'outsourced_work_id' => $workId,
            'action_type' => mb_substr($actionType, 0, 40),
            'from_status' => $fromStatus !== null ? mb_substr(ow_normalize_status($fromStatus), 0, 20) : null,
            'to_status' => $toStatus !== null ? mb_substr(ow_normalize_status($toStatus), 0, 20) : null,
            'action_note' => $note !== null ? mb_substr($note, 0, 255) : null,
            'payload_json' => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            'created_by' => $userId > 0 ? $userId : null,
        ]);
    } catch (Throwable $exception) {
        // History logging should not block core flow.
    }
}

function ow_fetch_work_for_update(PDO $pdo, int $workId, int $companyId, int $garageId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT ow.*,
                COALESCE(pay.total_paid, 0) AS paid_amount
         FROM outsourced_works ow
         LEFT JOIN (
             SELECT outsourced_work_id, SUM(amount) AS total_paid
             FROM outsourced_work_payments
             GROUP BY outsourced_work_id
         ) pay ON pay.outsourced_work_id = ow.id
         WHERE ow.id = :id
           AND ow.company_id = :company_id
           AND ow.garage_id = :garage_id
           AND ow.status_code = "ACTIVE"
         LIMIT 1
         FOR UPDATE'
    );
    $stmt->execute([
        'id' => $workId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);

    $row = $stmt->fetch();
    return $row ?: null;
}

function ow_sync_job_labor_payable(PDO $pdo, int $workId, int $companyId, int $garageId, int $actorUserId): void
{
    $stmt = $pdo->prepare(
        'SELECT ow.id, ow.job_labor_id, ow.vendor_id, ow.partner_name, ow.expected_return_date,
                ow.agreed_cost, ow.current_status,
                COALESCE(pay.total_paid, 0) AS paid_amount
         FROM outsourced_works ow
         LEFT JOIN (
             SELECT outsourced_work_id, SUM(amount) AS total_paid
             FROM outsourced_work_payments
             GROUP BY outsourced_work_id
         ) pay ON pay.outsourced_work_id = ow.id
         WHERE ow.id = :id
           AND ow.company_id = :company_id
           AND ow.garage_id = :garage_id
         LIMIT 1'
    );
    $stmt->execute([
        'id' => $workId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
    $row = $stmt->fetch();
    if (!$row || (int) ($row['job_labor_id'] ?? 0) <= 0) {
        return;
    }

    $agreedCost = round((float) ($row['agreed_cost'] ?? 0), 2);
    $paidAmount = round((float) ($row['paid_amount'] ?? 0), 2);
    $payableStatus = ($agreedCost > 0 && $paidAmount + 0.009 >= $agreedCost) ? 'PAID' : 'UNPAID';
    $paidAt = $payableStatus === 'PAID' ? date('Y-m-d H:i:s') : null;
    $paidBy = $payableStatus === 'PAID' ? ($actorUserId > 0 ? $actorUserId : null) : null;

    $update = $pdo->prepare(
        'UPDATE job_labor jl
         INNER JOIN outsourced_works ow ON ow.job_labor_id = jl.id
         SET jl.outsource_vendor_id = :vendor_id,
             jl.outsource_partner_name = :partner_name,
             jl.outsource_cost = :outsource_cost,
             jl.outsource_expected_return_date = :expected_return_date,
             jl.outsource_payable_status = :payable_status,
             jl.outsource_paid_at = :paid_at,
             jl.outsource_paid_by = :paid_by
         WHERE ow.id = :work_id
           AND ow.company_id = :company_id
           AND ow.garage_id = :garage_id'
    );
    $update->execute([
        'vendor_id' => (int) ($row['vendor_id'] ?? 0) > 0 ? (int) $row['vendor_id'] : null,
        'partner_name' => trim((string) ($row['partner_name'] ?? '')) !== '' ? trim((string) $row['partner_name']) : null,
        'outsource_cost' => $agreedCost,
        'expected_return_date' => !empty($row['expected_return_date']) ? (string) $row['expected_return_date'] : null,
        'payable_status' => $payableStatus,
        'paid_at' => $paidAt,
        'paid_by' => $paidBy,
        'work_id' => $workId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
}

$outsourcedReady = table_columns('outsourced_works') !== [] && table_columns('outsourced_work_payments') !== [];

$today = date('Y-m-d');
$defaultFrom = date('Y-m-01');

$fromDate = trim((string) ($_GET['from'] ?? $defaultFrom));
if (!ow_is_valid_date($fromDate)) {
    $fromDate = $defaultFrom;
}

$toDate = trim((string) ($_GET['to'] ?? $today));
if (!ow_is_valid_date($toDate)) {
    $toDate = $today;
}

if ($toDate < $fromDate) {
    $toDate = $fromDate;
}

$searchQuery = trim((string) ($_GET['q'] ?? ''));
$vendorFilter = get_int('vendor_id', 0);
$statusFilter = ow_normalize_status((string) ($_GET['status'] ?? ''));
if (!isset($_GET['status']) || trim((string) $_GET['status']) === '') {
    $statusFilter = '';
}
$outstandingOnly = isset($_GET['outstanding']) ? (trim((string) $_GET['outstanding']) === '1') : false;

$vendors = [];
if ($outsourcedReady) {
    $vendorStmt = db()->prepare(
        'SELECT id, vendor_code, vendor_name
         FROM vendors
         WHERE company_id = :company_id
           AND status_code = "ACTIVE"
         ORDER BY vendor_name ASC'
    );
    $vendorStmt->execute(['company_id' => $companyId]);
    $vendors = $vendorStmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!$outsourcedReady) {
        flash_set('outsource_error', 'Outsourced module tables are missing. Run database/outsourced_works_upgrade.sql first.', 'danger');
        redirect('modules/outsourced/index.php');
    }

    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'update_work') {
        if (!$canManage) {
            flash_set('outsource_error', 'You do not have permission to update outsourced works.', 'danger');
            redirect('modules/outsourced/index.php');
        }

        $workId = post_int('work_id');
        $vendorId = post_int('vendor_id');
        $partnerName = post_string('partner_name', 150);
        $description = post_string('service_description', 255);
        $agreedCost = round(max(0, ow_decimal($_POST['agreed_cost'] ?? 0)), 2);
        $expectedReturnDateRaw = trim((string) ($_POST['expected_return_date'] ?? ''));
        $expectedReturnDate = $expectedReturnDateRaw === '' ? null : $expectedReturnDateRaw;
        $notes = post_string('notes', 255);

        if ($workId <= 0 || $partnerName === '' || $description === '') {
            flash_set('outsource_error', 'Partner name and service description are required.', 'danger');
            redirect('modules/outsourced/index.php');
        }
        if ($expectedReturnDate !== null && !ow_is_valid_date($expectedReturnDate)) {
            flash_set('outsource_error', 'Expected return date is invalid.', 'danger');
            redirect('modules/outsourced/index.php');
        }
        if ($vendorId > 0) {
            $vendorStmt = db()->prepare(
                'SELECT id FROM vendors
                 WHERE id = :id
                   AND company_id = :company_id
                   AND status_code = "ACTIVE"
                 LIMIT 1'
            );
            $vendorStmt->execute([
                'id' => $vendorId,
                'company_id' => $companyId,
            ]);
            if (!$vendorStmt->fetch()) {
                flash_set('outsource_error', 'Selected vendor is not active for this company.', 'danger');
                redirect('modules/outsourced/index.php');
            }
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $work = ow_fetch_work_for_update($pdo, $workId, $companyId, $garageId);
            if (!$work) {
                throw new RuntimeException('Outsourced work not found.');
            }

            $paidAmount = round((float) ($work['paid_amount'] ?? 0), 2);
            if ($agreedCost + 0.009 < $paidAmount) {
                throw new RuntimeException('Agreed cost cannot be lower than already paid amount (' . number_format($paidAmount, 2) . ').');
            }

            $currentStatus = ow_normalize_status((string) ($work['current_status'] ?? 'SENT'));
            $nextStatus = $currentStatus;
            if ($paidAmount > 0 && ow_status_rank($nextStatus) < ow_status_rank('PAYABLE')) {
                $nextStatus = 'PAYABLE';
            }
            if ($agreedCost > 0 && $paidAmount + 0.009 >= $agreedCost) {
                $nextStatus = 'PAID';
            } elseif ($nextStatus === 'PAID' && $paidAmount + 0.009 < $agreedCost) {
                $nextStatus = 'PAYABLE';
            }

            $now = date('Y-m-d H:i:s');
            $payableAt = !empty($work['payable_at']) ? (string) $work['payable_at'] : null;
            $paidAt = !empty($work['paid_at']) ? (string) $work['paid_at'] : null;
            if ($nextStatus === 'PAYABLE' && $payableAt === null) {
                $payableAt = $now;
            }
            if ($nextStatus === 'PAID') {
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
                     notes = :notes,
                     current_status = :current_status,
                     payable_at = :payable_at,
                     paid_at = :paid_at,
                     updated_by = :updated_by
                 WHERE id = :id
                   AND company_id = :company_id
                   AND garage_id = :garage_id'
            );
            $updateStmt->execute([
                'vendor_id' => $vendorId > 0 ? $vendorId : null,
                'partner_name' => $partnerName,
                'service_description' => $description,
                'agreed_cost' => $agreedCost,
                'expected_return_date' => $expectedReturnDate,
                'notes' => $notes !== '' ? $notes : null,
                'current_status' => $nextStatus,
                'payable_at' => $payableAt,
                'paid_at' => $paidAt,
                'updated_by' => $userId > 0 ? $userId : null,
                'id' => $workId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ]);

            ow_append_history(
                $workId,
                'WORK_EDIT',
                $currentStatus,
                $nextStatus,
                'Outsourced work details updated',
                [
                    'vendor_id' => $vendorId > 0 ? $vendorId : null,
                    'partner_name' => $partnerName,
                    'service_description' => $description,
                    'agreed_cost' => $agreedCost,
                    'paid_amount' => $paidAmount,
                    'expected_return_date' => $expectedReturnDate,
                ],
                $userId
            );

            ow_sync_job_labor_payable($pdo, $workId, $companyId, $garageId, $userId);

            $pdo->commit();
            log_audit('outsourced_works', 'update', $workId, 'Updated outsourced work #' . $workId, [
                'entity' => 'outsourced_work',
                'source' => 'UI',
                'before' => [
                    'partner_name' => (string) ($work['partner_name'] ?? ''),
                    'agreed_cost' => (float) ($work['agreed_cost'] ?? 0),
                    'current_status' => $currentStatus,
                ],
                'after' => [
                    'partner_name' => $partnerName,
                    'agreed_cost' => $agreedCost,
                    'current_status' => $nextStatus,
                ],
            ]);
            flash_set('outsource_success', 'Outsourced work updated successfully.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('outsource_error', 'Unable to update outsourced work. ' . $exception->getMessage(), 'danger');
        }

        redirect('modules/outsourced/index.php');
    }

    if ($action === 'transition_status') {
        if (!$canManage) {
            flash_set('outsource_error', 'You do not have permission to transition outsourced works.', 'danger');
            redirect('modules/outsourced/index.php');
        }

        $workId = post_int('work_id');
        $nextStatus = ow_normalize_status((string) ($_POST['next_status'] ?? 'SENT'));
        if ($workId <= 0) {
            flash_set('outsource_error', 'Invalid outsourced work selected.', 'danger');
            redirect('modules/outsourced/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $work = ow_fetch_work_for_update($pdo, $workId, $companyId, $garageId);
            if (!$work) {
                throw new RuntimeException('Outsourced work not found.');
            }

            $currentStatus = ow_normalize_status((string) ($work['current_status'] ?? 'SENT'));
            $expectedNextStatus = ow_next_status($currentStatus);
            if ($expectedNextStatus === null || $nextStatus !== $expectedNextStatus) {
                throw new RuntimeException('Invalid lifecycle transition requested.');
            }

            $agreedCost = round((float) ($work['agreed_cost'] ?? 0), 2);
            $paidAmount = round((float) ($work['paid_amount'] ?? 0), 2);
            if ($nextStatus === 'PAID' && ($agreedCost <= 0 || $paidAmount + 0.009 < $agreedCost)) {
                throw new RuntimeException('Mark as PAID only after full payment is recorded.');
            }

            $now = date('Y-m-d H:i:s');
            $sentAt = !empty($work['sent_at']) ? (string) $work['sent_at'] : $now;
            $receivedAt = !empty($work['received_at']) ? (string) $work['received_at'] : null;
            $verifiedAt = !empty($work['verified_at']) ? (string) $work['verified_at'] : null;
            $payableAt = !empty($work['payable_at']) ? (string) $work['payable_at'] : null;
            $paidAt = !empty($work['paid_at']) ? (string) $work['paid_at'] : null;

            if ($nextStatus === 'RECEIVED') {
                $receivedAt = $now;
            } elseif ($nextStatus === 'VERIFIED') {
                $receivedAt = $receivedAt ?? $now;
                $verifiedAt = $now;
            } elseif ($nextStatus === 'PAYABLE') {
                $receivedAt = $receivedAt ?? $now;
                $verifiedAt = $verifiedAt ?? $now;
                $payableAt = $now;
            } elseif ($nextStatus === 'PAID') {
                $receivedAt = $receivedAt ?? $now;
                $verifiedAt = $verifiedAt ?? $now;
                $payableAt = $payableAt ?? $now;
                $paidAt = $now;
            }

            $updateStmt = $pdo->prepare(
                'UPDATE outsourced_works
                 SET current_status = :current_status,
                     sent_at = :sent_at,
                     received_at = :received_at,
                     verified_at = :verified_at,
                     payable_at = :payable_at,
                     paid_at = :paid_at,
                     updated_by = :updated_by
                 WHERE id = :id
                   AND company_id = :company_id
                   AND garage_id = :garage_id'
            );
            $updateStmt->execute([
                'current_status' => $nextStatus,
                'sent_at' => $sentAt,
                'received_at' => $receivedAt,
                'verified_at' => $verifiedAt,
                'payable_at' => $payableAt,
                'paid_at' => $paidAt,
                'updated_by' => $userId > 0 ? $userId : null,
                'id' => $workId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ]);

            ow_append_history(
                $workId,
                'STATUS_TRANSITION',
                $currentStatus,
                $nextStatus,
                'Lifecycle moved to ' . $nextStatus,
                [
                    'agreed_cost' => $agreedCost,
                    'paid_amount' => $paidAmount,
                ],
                $userId
            );

            ow_sync_job_labor_payable($pdo, $workId, $companyId, $garageId, $userId);

            $pdo->commit();
            log_audit('outsourced_works', 'status', $workId, 'Transitioned outsourced work to ' . $nextStatus);
            flash_set('outsource_success', 'Outsourced work moved to ' . $nextStatus . '.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('outsource_error', 'Unable to transition outsourced work. ' . $exception->getMessage(), 'danger');
        }

        redirect('modules/outsourced/index.php');
    }

    if ($action === 'add_payment') {
        if (!$canPay) {
            flash_set('outsource_error', 'You do not have permission to record outsourced payments.', 'danger');
            redirect('modules/outsourced/index.php');
        }

        $workId = post_int('work_id');
        $paymentDate = trim((string) ($_POST['payment_date'] ?? $today));
        $amount = round(max(0, ow_decimal($_POST['amount'] ?? 0)), 2);
        $paymentMode = strtoupper(trim((string) ($_POST['payment_mode'] ?? '')));
        $referenceNo = post_string('reference_no', 100);
        $notes = post_string('notes', 255);

        if ($workId <= 0 || $amount <= 0) {
            flash_set('outsource_error', 'Work and payment amount are required.', 'danger');
            redirect('modules/outsourced/index.php');
        }
        if (!ow_is_valid_date($paymentDate)) {
            flash_set('outsource_error', 'Payment date is invalid.', 'danger');
            redirect('modules/outsourced/index.php');
        }
        if (!in_array($paymentMode, ow_payment_modes(), true)) {
            flash_set('outsource_error', 'Invalid payment mode selected.', 'danger');
            redirect('modules/outsourced/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $work = ow_fetch_work_for_update($pdo, $workId, $companyId, $garageId);
            if (!$work) {
                throw new RuntimeException('Outsourced work not found.');
            }

            $currentStatus = ow_normalize_status((string) ($work['current_status'] ?? 'SENT'));
            if (ow_status_rank($currentStatus) < ow_status_rank('PAYABLE')) {
                throw new RuntimeException('Move lifecycle to PAYABLE before recording payments.');
            }

            $agreedCost = round((float) ($work['agreed_cost'] ?? 0), 2);
            $paidAmount = round((float) ($work['paid_amount'] ?? 0), 2);
            $outstanding = max(0.0, round($agreedCost - $paidAmount, 2));

            if ($outstanding <= 0.009) {
                throw new RuntimeException('No outstanding amount left for this outsourced work.');
            }
            if ($amount > $outstanding + 0.009) {
                throw new RuntimeException('Payment exceeds outstanding payable (' . number_format($outstanding, 2) . ').');
            }

            $insertStmt = $pdo->prepare(
                'INSERT INTO outsourced_work_payments
                  (outsourced_work_id, company_id, garage_id, payment_date, entry_type, amount, payment_mode, reference_no, notes, created_by)
                 VALUES
                  (:outsourced_work_id, :company_id, :garage_id, :payment_date, "PAYMENT", :amount, :payment_mode, :reference_no, :notes, :created_by)'
            );
            $insertStmt->execute([
                'outsourced_work_id' => $workId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'payment_date' => $paymentDate,
                'amount' => $amount,
                'payment_mode' => $paymentMode,
                'reference_no' => $referenceNo !== '' ? $referenceNo : null,
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => $userId > 0 ? $userId : null,
            ]);

            $paymentId = (int) $pdo->lastInsertId();
            $newPaidAmount = round($paidAmount + $amount, 2);
            $nextStatus = ($agreedCost > 0 && $newPaidAmount + 0.009 >= $agreedCost) ? 'PAID' : 'PAYABLE';
            $now = date('Y-m-d H:i:s');
            $payableAt = !empty($work['payable_at']) ? (string) $work['payable_at'] : $now;
            $paidAt = $nextStatus === 'PAID' ? $now : null;

            $updateStmt = $pdo->prepare(
                'UPDATE outsourced_works
                 SET current_status = :current_status,
                     payable_at = :payable_at,
                     paid_at = :paid_at,
                     updated_by = :updated_by
                 WHERE id = :id
                   AND company_id = :company_id
                   AND garage_id = :garage_id'
            );
            $updateStmt->execute([
                'current_status' => $nextStatus,
                'payable_at' => $payableAt,
                'paid_at' => $paidAt,
                'updated_by' => $userId > 0 ? $userId : null,
                'id' => $workId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ]);

            ow_append_history(
                $workId,
                'PAYMENT_ADD',
                $currentStatus,
                $nextStatus,
                'Outsourced payment recorded',
                [
                    'payment_id' => $paymentId,
                    'payment_date' => $paymentDate,
                    'amount' => $amount,
                    'payment_mode' => $paymentMode,
                    'reference_no' => $referenceNo,
                    'old_paid_amount' => $paidAmount,
                    'new_paid_amount' => $newPaidAmount,
                ],
                $userId
            );

            ow_sync_job_labor_payable($pdo, $workId, $companyId, $garageId, $userId);

            $pdo->commit();
            log_audit('outsourced_works', 'payment_add', $workId, 'Recorded outsourced payment entry #' . $paymentId);
            flash_set('outsource_success', 'Payment recorded successfully.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('outsource_error', 'Unable to record payment. ' . $exception->getMessage(), 'danger');
        }

        redirect('modules/outsourced/index.php');
    }

    if ($action === 'reverse_payment') {
        if (!$canPay) {
            flash_set('outsource_error', 'You do not have permission to reverse outsourced payments.', 'danger');
            redirect('modules/outsourced/index.php');
        }

        $paymentId = post_int('payment_id');
        $reverseReason = post_string('reverse_reason', 255);
        if ($paymentId <= 0 || $reverseReason === '') {
            flash_set('outsource_error', 'Payment and reversal reason are required.', 'danger');
            redirect('modules/outsourced/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $paymentStmt = $pdo->prepare(
                'SELECT p.*,
                        ow.current_status,
                        ow.agreed_cost,
                        ow.id AS work_id,
                        COALESCE(pay.total_paid, 0) AS paid_amount
                 FROM outsourced_work_payments p
                 INNER JOIN outsourced_works ow ON ow.id = p.outsourced_work_id
                 LEFT JOIN (
                    SELECT outsourced_work_id, SUM(amount) AS total_paid
                    FROM outsourced_work_payments
                    GROUP BY outsourced_work_id
                 ) pay ON pay.outsourced_work_id = ow.id
                 WHERE p.id = :id
                   AND ow.company_id = :company_id
                   AND ow.garage_id = :garage_id
                   AND ow.status_code = "ACTIVE"
                 LIMIT 1
                 FOR UPDATE'
            );
            $paymentStmt->execute([
                'id' => $paymentId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ]);
            $payment = $paymentStmt->fetch();
            if (!$payment) {
                throw new RuntimeException('Payment entry not found.');
            }

            if ((string) ($payment['entry_type'] ?? '') !== 'PAYMENT') {
                throw new RuntimeException('Only payment entries can be reversed.');
            }
            if ((float) ($payment['amount'] ?? 0) <= 0) {
                throw new RuntimeException('Invalid payment amount for reversal.');
            }

            $checkStmt = $pdo->prepare(
                'SELECT id
                 FROM outsourced_work_payments
                 WHERE reversed_payment_id = :payment_id
                 LIMIT 1'
            );
            $checkStmt->execute(['payment_id' => $paymentId]);
            if ($checkStmt->fetch()) {
                throw new RuntimeException('This payment has already been reversed.');
            }

            $workId = (int) ($payment['work_id'] ?? 0);
            $paymentAmount = round((float) ($payment['amount'] ?? 0), 2);
            $insertStmt = $pdo->prepare(
                'INSERT INTO outsourced_work_payments
                  (outsourced_work_id, company_id, garage_id, payment_date, entry_type, amount, payment_mode, reference_no, notes, reversed_payment_id, created_by)
                 VALUES
                  (:outsourced_work_id, :company_id, :garage_id, :payment_date, "REVERSAL", :amount, "ADJUSTMENT", :reference_no, :notes, :reversed_payment_id, :created_by)'
            );
            $insertStmt->execute([
                'outsourced_work_id' => $workId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'payment_date' => date('Y-m-d'),
                'amount' => -$paymentAmount,
                'reference_no' => 'REV-' . $paymentId,
                'notes' => $reverseReason,
                'reversed_payment_id' => $paymentId,
                'created_by' => $userId > 0 ? $userId : null,
            ]);
            $reversalId = (int) $pdo->lastInsertId();

            $work = ow_fetch_work_for_update($pdo, $workId, $companyId, $garageId);
            if (!$work) {
                throw new RuntimeException('Outsourced work not found after reversal.');
            }

            $agreedCost = round((float) ($work['agreed_cost'] ?? 0), 2);
            $newPaidAmount = round((float) ($work['paid_amount'] ?? 0), 2);
            $oldStatus = ow_normalize_status((string) ($payment['current_status'] ?? 'PAYABLE'));
            $nextStatus = ($agreedCost > 0 && $newPaidAmount + 0.009 >= $agreedCost) ? 'PAID' : 'PAYABLE';

            $updateStmt = $pdo->prepare(
                'UPDATE outsourced_works
                 SET current_status = :current_status,
                     payable_at = COALESCE(payable_at, :now_dt),
                     paid_at = :paid_at,
                     updated_by = :updated_by
                 WHERE id = :id
                   AND company_id = :company_id
                   AND garage_id = :garage_id'
            );
            $now = date('Y-m-d H:i:s');
            $updateStmt->execute([
                'current_status' => $nextStatus,
                'now_dt' => $now,
                'paid_at' => $nextStatus === 'PAID' ? $now : null,
                'updated_by' => $userId > 0 ? $userId : null,
                'id' => $workId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ]);

            ow_append_history(
                $workId,
                'PAYMENT_REVERSE',
                $oldStatus,
                $nextStatus,
                'Outsourced payment reversed',
                [
                    'payment_id' => $paymentId,
                    'reversal_id' => $reversalId,
                    'reversal_amount' => -$paymentAmount,
                    'new_paid_amount' => $newPaidAmount,
                    'reason' => $reverseReason,
                ],
                $userId
            );

            ow_sync_job_labor_payable($pdo, $workId, $companyId, $garageId, $userId);

            $pdo->commit();
            log_audit('outsourced_works', 'payment_reverse', $workId, 'Reversed outsourced payment #' . $paymentId);
            flash_set('outsource_success', 'Payment reversed successfully.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('outsource_error', 'Unable to reverse payment. ' . $exception->getMessage(), 'danger');
        }

        redirect('modules/outsourced/index.php');
    }
}

$works = [];
$pendingPayables = [];
$vendorOutstanding = [];
$jobCostSummary = [];
$profitabilityRows = [];
$paymentHistory = [];
$reversiblePayments = [];
$payableWorks = [];
$editWork = null;

$totalAgreed = 0.0;
$totalPaid = 0.0;
$totalOutstanding = 0.0;

if ($outsourcedReady) {
    $whereParts = [
        'ow.company_id = :company_id',
        'ow.garage_id = :garage_id',
        'ow.status_code = "ACTIVE"',
        'DATE(ow.created_at) BETWEEN :from_date AND :to_date',
    ];
    $baseParams = [
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ];

    if ($vendorFilter > 0) {
        $whereParts[] = 'ow.vendor_id = :vendor_id';
        $baseParams['vendor_id'] = $vendorFilter;
    }
    if ($statusFilter !== '') {
        $whereParts[] = 'ow.current_status = :status';
        $baseParams['status'] = $statusFilter;
    }
    if ($searchQuery !== '') {
        $whereParts[] = '(jc.job_number LIKE :query OR ow.partner_name LIKE :query OR ow.service_description LIKE :query OR COALESCE(vn.vendor_name, "") LIKE :query OR COALESCE(c.full_name, "") LIKE :query OR COALESCE(v.registration_no, "") LIKE :query)';
        $baseParams['query'] = '%' . $searchQuery . '%';
    }
    if ($outstandingOnly) {
        $whereParts[] = '(ow.agreed_cost - COALESCE(pay.total_paid, 0)) > 0.01';
    }

    $whereSql = implode(' AND ', $whereParts);

    $worksStmt = db()->prepare(
        'SELECT ow.*,
                jc.job_number,
                jc.status AS job_status,
                c.full_name AS customer_name,
                v.registration_no,
                COALESCE(vn.vendor_name, "") AS vendor_name,
                COALESCE(vn.vendor_code, "") AS vendor_code,
                COALESCE(pay.total_paid, 0) AS paid_amount,
                GREATEST(ow.agreed_cost - COALESCE(pay.total_paid, 0), 0) AS outstanding_amount,
                COALESCE(pay.payment_count, 0) AS payment_count
         FROM outsourced_works ow
         INNER JOIN job_cards jc ON jc.id = ow.job_card_id
         LEFT JOIN customers c ON c.id = jc.customer_id
         LEFT JOIN vehicles v ON v.id = jc.vehicle_id
         LEFT JOIN vendors vn ON vn.id = ow.vendor_id
         LEFT JOIN (
             SELECT outsourced_work_id, SUM(amount) AS total_paid, COUNT(*) AS payment_count
             FROM outsourced_work_payments
             GROUP BY outsourced_work_id
         ) pay ON pay.outsourced_work_id = ow.id
         WHERE ' . $whereSql . '
         ORDER BY
            CASE ow.current_status
                WHEN "SENT" THEN 1
                WHEN "RECEIVED" THEN 2
                WHEN "VERIFIED" THEN 3
                WHEN "PAYABLE" THEN 4
                WHEN "PAID" THEN 5
                ELSE 6
            END,
            ow.expected_return_date IS NULL,
            ow.expected_return_date ASC,
            ow.id DESC
         LIMIT 400'
    );
    $worksStmt->execute($baseParams);
    $works = $worksStmt->fetchAll();

    foreach ($works as $row) {
        $totalAgreed += (float) ($row['agreed_cost'] ?? 0);
        $totalPaid += (float) ($row['paid_amount'] ?? 0);
        $totalOutstanding += (float) ($row['outstanding_amount'] ?? 0);
    }

    $pendingStmt = db()->prepare(
        'SELECT ow.id, ow.current_status, ow.partner_name, ow.service_description, ow.expected_return_date,
                ow.agreed_cost,
                jc.job_number,
                COALESCE(vn.vendor_name, ow.partner_name) AS vendor_label,
                COALESCE(pay.total_paid, 0) AS paid_amount,
                GREATEST(ow.agreed_cost - COALESCE(pay.total_paid, 0), 0) AS outstanding_amount
         FROM outsourced_works ow
         INNER JOIN job_cards jc ON jc.id = ow.job_card_id
         LEFT JOIN vendors vn ON vn.id = ow.vendor_id
         LEFT JOIN (
             SELECT outsourced_work_id, SUM(amount) AS total_paid
             FROM outsourced_work_payments
             GROUP BY outsourced_work_id
         ) pay ON pay.outsourced_work_id = ow.id
         WHERE ow.company_id = :company_id
           AND ow.garage_id = :garage_id
           AND ow.status_code = "ACTIVE"
           AND DATE(ow.created_at) BETWEEN :from_date AND :to_date
           AND GREATEST(ow.agreed_cost - COALESCE(pay.total_paid, 0), 0) > 0.01
         ORDER BY outstanding_amount DESC, ow.expected_return_date ASC, ow.id DESC
         LIMIT 250'
    );
    $pendingStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ]);
    $pendingPayables = $pendingStmt->fetchAll();

    $vendorSummaryStmt = db()->prepare(
        'SELECT
            CASE
                WHEN ow.vendor_id IS NOT NULL THEN CONCAT("V-", ow.vendor_id)
                ELSE CONCAT("P-", LOWER(TRIM(ow.partner_name)))
            END AS vendor_key,
            COALESCE(vn.vendor_name, ow.partner_name) AS vendor_label,
            COUNT(*) AS work_count,
            COALESCE(SUM(ow.agreed_cost), 0) AS agreed_total,
            COALESCE(SUM(COALESCE(pay.total_paid, 0)), 0) AS paid_total,
            COALESCE(SUM(GREATEST(ow.agreed_cost - COALESCE(pay.total_paid, 0), 0)), 0) AS outstanding_total
         FROM outsourced_works ow
         LEFT JOIN vendors vn ON vn.id = ow.vendor_id
         LEFT JOIN (
            SELECT outsourced_work_id, SUM(amount) AS total_paid
            FROM outsourced_work_payments
            GROUP BY outsourced_work_id
         ) pay ON pay.outsourced_work_id = ow.id
         WHERE ow.company_id = :company_id
           AND ow.garage_id = :garage_id
           AND ow.status_code = "ACTIVE"
           AND DATE(ow.created_at) BETWEEN :from_date AND :to_date
         GROUP BY vendor_key, vendor_label
         ORDER BY outstanding_total DESC, agreed_total DESC'
    );
    $vendorSummaryStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ]);
    $vendorOutstanding = $vendorSummaryStmt->fetchAll();

    $jobSummaryStmt = db()->prepare(
        'SELECT jc.id AS job_card_id,
                jc.job_number,
                c.full_name AS customer_name,
                v.registration_no,
                COUNT(ow.id) AS outsourced_lines,
                COALESCE(SUM(ow.agreed_cost), 0) AS agreed_total,
                COALESCE(SUM(COALESCE(pay.total_paid, 0)), 0) AS paid_total,
                COALESCE(SUM(GREATEST(ow.agreed_cost - COALESCE(pay.total_paid, 0), 0)), 0) AS outstanding_total
         FROM outsourced_works ow
         INNER JOIN job_cards jc ON jc.id = ow.job_card_id
         LEFT JOIN customers c ON c.id = jc.customer_id
         LEFT JOIN vehicles v ON v.id = jc.vehicle_id
         LEFT JOIN (
            SELECT outsourced_work_id, SUM(amount) AS total_paid
            FROM outsourced_work_payments
            GROUP BY outsourced_work_id
         ) pay ON pay.outsourced_work_id = ow.id
         WHERE ow.company_id = :company_id
           AND ow.garage_id = :garage_id
           AND ow.status_code = "ACTIVE"
           AND DATE(ow.created_at) BETWEEN :from_date AND :to_date
         GROUP BY jc.id, jc.job_number, c.full_name, v.registration_no
         ORDER BY agreed_total DESC, outsourced_lines DESC
         LIMIT 250'
    );
    $jobSummaryStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ]);
    $jobCostSummary = $jobSummaryStmt->fetchAll();

    $profitStmt = db()->prepare(
        'SELECT jc.id AS job_card_id,
                jc.job_number,
                c.full_name AS customer_name,
                v.registration_no,
                COALESCE(lt.inhouse_billed, 0) AS inhouse_billed,
                COALESCE(lt.outsourced_billed, 0) AS outsourced_billed,
                COALESCE(lt.labor_billed_total, 0) AS labor_billed_total,
                COALESCE(oc.outsourced_cost, 0) AS outsourced_cost,
                COALESCE(oc.outsourced_paid, 0) AS outsourced_paid
         FROM job_cards jc
         INNER JOIN (
             SELECT ow.job_card_id,
                    COALESCE(SUM(ow.agreed_cost), 0) AS outsourced_cost,
                    COALESCE(SUM(COALESCE(pay.total_paid, 0)), 0) AS outsourced_paid
             FROM outsourced_works ow
             LEFT JOIN (
                SELECT outsourced_work_id, SUM(amount) AS total_paid
                FROM outsourced_work_payments
                GROUP BY outsourced_work_id
             ) pay ON pay.outsourced_work_id = ow.id
             WHERE ow.company_id = :company_id
               AND ow.garage_id = :garage_id
               AND ow.status_code = "ACTIVE"
               AND DATE(ow.created_at) BETWEEN :from_date AND :to_date
             GROUP BY ow.job_card_id
         ) oc ON oc.job_card_id = jc.id
         LEFT JOIN (
             SELECT jl.job_card_id,
                    COALESCE(SUM(CASE WHEN jl.execution_type = "IN_HOUSE" THEN jl.total_amount ELSE 0 END), 0) AS inhouse_billed,
                    COALESCE(SUM(CASE WHEN jl.execution_type = "OUTSOURCED" THEN jl.total_amount ELSE 0 END), 0) AS outsourced_billed,
                    COALESCE(SUM(jl.total_amount), 0) AS labor_billed_total
             FROM job_labor jl
             GROUP BY jl.job_card_id
         ) lt ON lt.job_card_id = jc.id
         LEFT JOIN customers c ON c.id = jc.customer_id
         LEFT JOIN vehicles v ON v.id = jc.vehicle_id
         WHERE jc.company_id = :company_id
           AND jc.garage_id = :garage_id
         ORDER BY oc.outsourced_cost DESC, jc.id DESC
         LIMIT 250'
    );
    $profitStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ]);
    $profitabilityRows = $profitStmt->fetchAll();

    $paymentHistoryStmt = db()->prepare(
        'SELECT p.id, p.outsourced_work_id, p.payment_date, p.entry_type, p.amount, p.payment_mode, p.reference_no, p.notes, p.reversed_payment_id,
                ow.partner_name, ow.current_status,
                jc.job_number,
                COALESCE(vn.vendor_name, ow.partner_name) AS vendor_label
         FROM outsourced_work_payments p
         INNER JOIN outsourced_works ow ON ow.id = p.outsourced_work_id
         INNER JOIN job_cards jc ON jc.id = ow.job_card_id
         LEFT JOIN vendors vn ON vn.id = ow.vendor_id
         WHERE ow.company_id = :company_id
           AND ow.garage_id = :garage_id
           AND ow.status_code = "ACTIVE"
           AND p.payment_date BETWEEN :from_date AND :to_date
         ORDER BY p.payment_date DESC, p.id DESC
         LIMIT 200'
    );
    $paymentHistoryStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ]);
    $paymentHistory = $paymentHistoryStmt->fetchAll();

    $reversibleStmt = db()->prepare(
        'SELECT p.id, p.outsourced_work_id, p.payment_date, p.amount,
                jc.job_number,
                COALESCE(vn.vendor_name, ow.partner_name) AS vendor_label
         FROM outsourced_work_payments p
         INNER JOIN outsourced_works ow ON ow.id = p.outsourced_work_id
         INNER JOIN job_cards jc ON jc.id = ow.job_card_id
         LEFT JOIN vendors vn ON vn.id = ow.vendor_id
         LEFT JOIN outsourced_work_payments r ON r.reversed_payment_id = p.id
         WHERE ow.company_id = :company_id
           AND ow.garage_id = :garage_id
           AND ow.status_code = "ACTIVE"
           AND p.entry_type = "PAYMENT"
           AND p.amount > 0
           AND r.id IS NULL
         ORDER BY p.id DESC
         LIMIT 200'
    );
    $reversibleStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
    $reversiblePayments = $reversibleStmt->fetchAll();

    $payableWorksStmt = db()->prepare(
        'SELECT ow.id, jc.job_number, COALESCE(vn.vendor_name, ow.partner_name) AS vendor_label,
                ow.agreed_cost,
                COALESCE(pay.total_paid, 0) AS paid_amount,
                GREATEST(ow.agreed_cost - COALESCE(pay.total_paid, 0), 0) AS outstanding_amount
         FROM outsourced_works ow
         INNER JOIN job_cards jc ON jc.id = ow.job_card_id
         LEFT JOIN vendors vn ON vn.id = ow.vendor_id
         LEFT JOIN (
             SELECT outsourced_work_id, SUM(amount) AS total_paid
             FROM outsourced_work_payments
             GROUP BY outsourced_work_id
         ) pay ON pay.outsourced_work_id = ow.id
         WHERE ow.company_id = :company_id
           AND ow.garage_id = :garage_id
           AND ow.status_code = "ACTIVE"
           AND ow.current_status IN ("PAYABLE", "PAID")
           AND GREATEST(ow.agreed_cost - COALESCE(pay.total_paid, 0), 0) > 0.01
         ORDER BY outstanding_amount DESC, ow.id DESC
         LIMIT 250'
    );
    $payableWorksStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
    $payableWorks = $payableWorksStmt->fetchAll();

    $editId = get_int('edit_id', 0);
    if ($editId > 0) {
        $editStmt = db()->prepare(
            'SELECT ow.*,
                    COALESCE(pay.total_paid, 0) AS paid_amount
             FROM outsourced_works ow
             LEFT JOIN (
                 SELECT outsourced_work_id, SUM(amount) AS total_paid
                 FROM outsourced_work_payments
                 GROUP BY outsourced_work_id
             ) pay ON pay.outsourced_work_id = ow.id
             WHERE ow.id = :id
               AND ow.company_id = :company_id
               AND ow.garage_id = :garage_id
               AND ow.status_code = "ACTIVE"
             LIMIT 1'
        );
        $editStmt->execute([
            'id' => $editId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
        $editWork = $editStmt->fetch() ?: null;
    }

    $exportKey = trim((string) ($_GET['export'] ?? ''));
    if ($exportKey !== '') {
        if (!$canExport) {
            http_response_code(403);
            exit('Export access denied.');
        }

        $filterSummary = [
            'garage_id' => $garageId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'vendor_id' => $vendorFilter > 0 ? $vendorFilter : null,
            'status' => $statusFilter !== '' ? $statusFilter : null,
            'q' => $searchQuery !== '' ? $searchQuery : null,
            'outstanding_only' => $outstandingOnly ? 1 : 0,
        ];
        $timestamp = date('Ymd_His');

        switch ($exportKey) {
            case 'pending_payables':
                $rows = array_map(
                    static fn (array $row): array => [
                        (string) ($row['job_number'] ?? ''),
                        (string) ($row['vendor_label'] ?? ''),
                        (string) ($row['service_description'] ?? ''),
                        (string) ($row['expected_return_date'] ?? ''),
                        (string) ow_normalize_status((string) ($row['current_status'] ?? 'SENT')),
                        (float) ($row['agreed_cost'] ?? 0),
                        (float) ($row['paid_amount'] ?? 0),
                        (float) ($row['outstanding_amount'] ?? 0),
                    ],
                    $pendingPayables
                );
                ow_csv_download(
                    'outsourced_pending_payables_' . $timestamp . '.csv',
                    ['Job Number', 'Vendor/Partner', 'Service', 'Expected Return', 'Status', 'Agreed Cost', 'Paid', 'Outstanding'],
                    $rows,
                    $filterSummary
                );

            case 'vendor_outstanding':
                $rows = array_map(
                    static fn (array $row): array => [
                        (string) ($row['vendor_label'] ?? ''),
                        (int) ($row['work_count'] ?? 0),
                        (float) ($row['agreed_total'] ?? 0),
                        (float) ($row['paid_total'] ?? 0),
                        (float) ($row['outstanding_total'] ?? 0),
                    ],
                    $vendorOutstanding
                );
                ow_csv_download(
                    'outsourced_vendor_outstanding_' . $timestamp . '.csv',
                    ['Vendor/Partner', 'Work Entries', 'Agreed Total', 'Paid Total', 'Outstanding Total'],
                    $rows,
                    $filterSummary
                );

            case 'job_cost_summary':
                $rows = array_map(
                    static fn (array $row): array => [
                        (string) ($row['job_number'] ?? ''),
                        (string) ($row['customer_name'] ?? ''),
                        (string) ($row['registration_no'] ?? ''),
                        (int) ($row['outsourced_lines'] ?? 0),
                        (float) ($row['agreed_total'] ?? 0),
                        (float) ($row['paid_total'] ?? 0),
                        (float) ($row['outstanding_total'] ?? 0),
                    ],
                    $jobCostSummary
                );
                ow_csv_download(
                    'outsourced_job_cost_summary_' . $timestamp . '.csv',
                    ['Job Number', 'Customer', 'Vehicle', 'Outsourced Lines', 'Agreed Total', 'Paid Total', 'Outstanding Total'],
                    $rows,
                    $filterSummary
                );

            case 'profitability':
                $rows = array_map(
                    static fn (array $row): array => [
                        (string) ($row['job_number'] ?? ''),
                        (string) ($row['customer_name'] ?? ''),
                        (string) ($row['registration_no'] ?? ''),
                        (float) ($row['inhouse_billed'] ?? 0),
                        (float) ($row['outsourced_billed'] ?? 0),
                        (float) ($row['outsourced_cost'] ?? 0),
                        (float) (((float) ($row['outsourced_billed'] ?? 0)) - ((float) ($row['outsourced_cost'] ?? 0))),
                        (float) (((float) ($row['labor_billed_total'] ?? 0)) - ((float) ($row['outsourced_cost'] ?? 0))),
                    ],
                    $profitabilityRows
                );
                ow_csv_download(
                    'outsourced_profitability_' . $timestamp . '.csv',
                    ['Job Number', 'Customer', 'Vehicle', 'In-house Billed', 'Outsourced Billed', 'Outsource Cost', 'Outsource Margin', 'Overall Labor Margin'],
                    $rows,
                    $filterSummary
                );

            default:
                flash_set('outsource_error', 'Unknown export request.', 'warning');
                redirect('modules/outsourced/index.php');
        }
    }
}

$profitInhouseBilled = array_reduce($profitabilityRows, static fn (float $sum, array $row): float => $sum + (float) ($row['inhouse_billed'] ?? 0), 0.0);
$profitOutsourcedBilled = array_reduce($profitabilityRows, static fn (float $sum, array $row): float => $sum + (float) ($row['outsourced_billed'] ?? 0), 0.0);
$profitOutsourceCost = array_reduce($profitabilityRows, static fn (float $sum, array $row): float => $sum + (float) ($row['outsourced_cost'] ?? 0), 0.0);
$profitOutsourceMargin = $profitOutsourcedBilled - $profitOutsourceCost;
$profitOverallLaborMargin = ($profitInhouseBilled + $profitOutsourcedBilled) - $profitOutsourceCost;

$baseParams = [
    'from' => $fromDate,
    'to' => $toDate,
    'q' => $searchQuery !== '' ? $searchQuery : null,
    'vendor_id' => $vendorFilter > 0 ? $vendorFilter : null,
    'status' => $statusFilter !== '' ? $statusFilter : null,
    'outstanding' => $outstandingOnly ? 1 : null,
];
$baseQuery = http_build_query(array_filter($baseParams, static fn (mixed $value): bool => $value !== null && $value !== ''));

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Outsourced Works</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Outsourced Works</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if (!$outsourcedReady): ?>
        <div class="alert alert-danger">
          Outsourced module tables are missing. Run <code>database/outsourced_works_upgrade.sql</code> first.
        </div>
      <?php else: ?>
        <div class="row g-3 mb-3">
          <div class="col-md-3">
            <div class="info-box">
              <span class="info-box-icon text-bg-primary"><i class="bi bi-list-check"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Tracked Works</span>
                <span class="info-box-number"><?= number_format(count($works)); ?></span>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="info-box">
              <span class="info-box-icon text-bg-warning"><i class="bi bi-currency-rupee"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Agreed Cost</span>
                <span class="info-box-number"><?= e(format_currency($totalAgreed)); ?></span>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="info-box">
              <span class="info-box-icon text-bg-success"><i class="bi bi-wallet2"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Paid</span>
                <span class="info-box-number"><?= e(format_currency($totalPaid)); ?></span>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="info-box">
              <span class="info-box-icon text-bg-danger"><i class="bi bi-exclamation-diamond"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Outstanding</span>
                <span class="info-box-number"><?= e(format_currency($totalOutstanding)); ?></span>
              </div>
            </div>
          </div>
        </div>

        <div class="card card-primary mb-3">
          <div class="card-header"><h3 class="card-title">Filters</h3></div>
          <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
              <div class="col-md-2">
                <label class="form-label">From</label>
                <input type="date" name="from" class="form-control" value="<?= e($fromDate); ?>" required />
              </div>
              <div class="col-md-2">
                <label class="form-label">To</label>
                <input type="date" name="to" class="form-control" value="<?= e($toDate); ?>" required />
              </div>
              <div class="col-md-2">
                <label class="form-label">Vendor</label>
                <select name="vendor_id" class="form-select">
                  <option value="0">All</option>
                  <?php foreach ($vendors as $vendor): ?>
                    <option value="<?= (int) $vendor['id']; ?>" <?= $vendorFilter === (int) $vendor['id'] ? 'selected' : ''; ?>>
                      <?= e((string) $vendor['vendor_name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                  <option value="" <?= $statusFilter === '' ? 'selected' : ''; ?>>All</option>
                  <?php foreach (['SENT', 'RECEIVED', 'VERIFIED', 'PAYABLE', 'PAID'] as $status): ?>
                    <option value="<?= e($status); ?>" <?= $statusFilter === $status ? 'selected' : ''; ?>><?= e($status); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Search</label>
                <input type="text" name="q" class="form-control" value="<?= e($searchQuery); ?>" placeholder="Job/Vendor/Service" />
              </div>
              <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Apply</button>
                <a href="<?= e(url('modules/outsourced/index.php')); ?>" class="btn btn-outline-secondary">Reset</a>
              </div>
              <div class="col-md-4">
                <div class="form-check mt-2">
                  <input type="checkbox" class="form-check-input" name="outstanding" value="1" id="outstanding-only" <?= $outstandingOnly ? 'checked' : ''; ?> />
                  <label class="form-check-label" for="outstanding-only">Show only outstanding payables</label>
                </div>
              </div>
              <div class="col-md-8 text-md-end mt-2 mt-md-0">
                <?php if ($canExport): ?>
                  <a href="<?= e(url('modules/outsourced/index.php?' . $baseQuery . '&export=pending_payables')); ?>" class="btn btn-sm btn-outline-danger">Pending CSV</a>
                  <a href="<?= e(url('modules/outsourced/index.php?' . $baseQuery . '&export=vendor_outstanding')); ?>" class="btn btn-sm btn-outline-warning">Vendor CSV</a>
                  <a href="<?= e(url('modules/outsourced/index.php?' . $baseQuery . '&export=job_cost_summary')); ?>" class="btn btn-sm btn-outline-primary">Job Cost CSV</a>
                  <a href="<?= e(url('modules/outsourced/index.php?' . $baseQuery . '&export=profitability')); ?>" class="btn btn-sm btn-outline-success">Profitability CSV</a>
                <?php endif; ?>
              </div>
            </form>
          </div>
        </div>

        <?php if ($editWork && $canManage): ?>
          <div class="card card-info mb-3">
            <div class="card-header"><h3 class="card-title">Edit Outsourced Work #<?= (int) $editWork['id']; ?></h3></div>
            <form method="post">
              <div class="card-body row g-2">
                <?= csrf_field(); ?>
                <input type="hidden" name="_action" value="update_work" />
                <input type="hidden" name="work_id" value="<?= (int) $editWork['id']; ?>" />
                <div class="col-md-3">
                  <label class="form-label">Vendor</label>
                  <select name="vendor_id" class="form-select">
                    <option value="0">No mapped vendor (individual)</option>
                    <?php foreach ($vendors as $vendor): ?>
                      <option value="<?= (int) $vendor['id']; ?>" <?= ((int) ($editWork['vendor_id'] ?? 0) === (int) $vendor['id']) ? 'selected' : ''; ?>>
                        <?= e((string) $vendor['vendor_name']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Partner / Individual</label>
                  <input type="text" name="partner_name" class="form-control" maxlength="150" value="<?= e((string) ($editWork['partner_name'] ?? '')); ?>" required />
                </div>
                <div class="col-md-3">
                  <label class="form-label">Service Description</label>
                  <input type="text" name="service_description" class="form-control" maxlength="255" value="<?= e((string) ($editWork['service_description'] ?? '')); ?>" required />
                </div>
                <div class="col-md-3">
                  <label class="form-label">Agreed Cost</label>
                  <input type="number" step="0.01" min="0" name="agreed_cost" class="form-control" value="<?= e((string) ($editWork['agreed_cost'] ?? '0')); ?>" required />
                  <small class="text-muted">Paid till now: <?= e(format_currency((float) ($editWork['paid_amount'] ?? 0))); ?></small>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Expected Return Date</label>
                  <input type="date" name="expected_return_date" class="form-control" value="<?= e((string) ($editWork['expected_return_date'] ?? '')); ?>" />
                </div>
                <div class="col-md-9">
                  <label class="form-label">Notes</label>
                  <input type="text" name="notes" class="form-control" maxlength="255" value="<?= e((string) ($editWork['notes'] ?? '')); ?>" />
                </div>
              </div>
              <div class="card-footer d-flex gap-2">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="<?= e(url('modules/outsourced/index.php' . ($baseQuery !== '' ? '?' . $baseQuery : ''))); ?>" class="btn btn-outline-secondary">Cancel</a>
              </div>
            </form>
          </div>
        <?php endif; ?>

        <?php if ($canPay): ?>
          <div class="row g-3 mb-3">
            <div class="col-lg-7">
              <div class="card card-success h-100">
                <div class="card-header"><h3 class="card-title">Record Outsourced Payment</h3></div>
                <form method="post">
                  <div class="card-body row g-2">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="_action" value="add_payment" />
                    <div class="col-md-6">
                      <label class="form-label">Work</label>
                      <select name="work_id" class="form-select" required>
                        <option value="">Select payable work</option>
                        <?php foreach ($payableWorks as $work): ?>
                          <option value="<?= (int) $work['id']; ?>">
                            #<?= (int) $work['id']; ?> | <?= e((string) ($work['job_number'] ?? '')); ?> | <?= e((string) ($work['vendor_label'] ?? '')); ?> | O/S <?= e(format_currency((float) ($work['outstanding_amount'] ?? 0))); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-2">
                      <label class="form-label">Date</label>
                      <input type="date" name="payment_date" class="form-control" value="<?= e($today); ?>" required />
                    </div>
                    <div class="col-md-2">
                      <label class="form-label">Amount</label>
                      <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required />
                    </div>
                    <div class="col-md-2">
                      <label class="form-label">Mode</label>
                      <select name="payment_mode" class="form-select" required>
                        <?php foreach (ow_payment_modes() as $mode): ?>
                          <?php if ($mode === 'ADJUSTMENT'): ?>
                            <?php continue; ?>
                          <?php endif; ?>
                          <option value="<?= e($mode); ?>"><?= e($mode); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Reference No</label>
                      <input type="text" name="reference_no" class="form-control" maxlength="100" />
                    </div>
                    <div class="col-md-8">
                      <label class="form-label">Notes</label>
                      <input type="text" name="notes" class="form-control" maxlength="255" placeholder="Optional remarks" />
                    </div>
                  </div>
                  <div class="card-footer">
                    <button type="submit" class="btn btn-success">Save Payment</button>
                  </div>
                </form>
              </div>
            </div>
            <div class="col-lg-5">
              <div class="card card-warning h-100">
                <div class="card-header"><h3 class="card-title">Reverse Payment</h3></div>
                <form method="post">
                  <div class="card-body row g-2">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="_action" value="reverse_payment" />
                    <div class="col-12">
                      <label class="form-label">Payment Entry</label>
                      <select name="payment_id" class="form-select" required>
                        <option value="">Select payment to reverse</option>
                        <?php foreach ($reversiblePayments as $payment): ?>
                          <option value="<?= (int) $payment['id']; ?>">
                            #<?= (int) $payment['id']; ?> | <?= e((string) ($payment['payment_date'] ?? '')); ?> | <?= e((string) ($payment['job_number'] ?? '')); ?> | <?= e(format_currency((float) ($payment['amount'] ?? 0))); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-12">
                      <label class="form-label">Reversal Reason</label>
                      <input type="text" name="reverse_reason" class="form-control" maxlength="255" required placeholder="Mandatory reason for reversal" />
                    </div>
                  </div>
                  <div class="card-footer">
                    <button type="submit" class="btn btn-warning">Create Reversal Entry</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <div class="card mb-3">
          <div class="card-header"><h3 class="card-title">Outsourced Work Register</h3></div>
          <div class="card-body p-0 table-responsive">
            <table class="table table-sm table-striped mb-0">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Job</th>
                  <th>Vendor / Partner</th>
                  <th>Service</th>
                  <th>Status</th>
                  <th>Expected Return</th>
                  <th>Agreed</th>
                  <th>Paid</th>
                  <th>Outstanding</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($works)): ?>
                  <tr><td colspan="10" class="text-center text-muted py-4">No outsourced work rows found for selected filters.</td></tr>
                <?php else: ?>
                  <?php foreach ($works as $work): ?>
                    <?php
                      $currentStatus = ow_normalize_status((string) ($work['current_status'] ?? 'SENT'));
                      $nextStatus = ow_next_status($currentStatus);
                      $agreedCost = (float) ($work['agreed_cost'] ?? 0);
                      $paidAmount = (float) ($work['paid_amount'] ?? 0);
                      $outstanding = max(0.0, (float) ($work['outstanding_amount'] ?? 0));
                      $canMovePaid = $nextStatus === 'PAID' ? ($agreedCost > 0 && $paidAmount + 0.009 >= $agreedCost) : true;
                    ?>
                    <tr>
                      <td>#<?= (int) $work['id']; ?></td>
                      <td>
                        <div><a href="<?= e(url('modules/jobs/view.php?id=' . (int) $work['job_card_id'])); ?>" target="_blank"><?= e((string) ($work['job_number'] ?? '')); ?></a></div>
                        <small class="text-muted"><?= e((string) ($work['registration_no'] ?? '-')); ?> | <?= e((string) ($work['customer_name'] ?? '-')); ?></small>
                      </td>
                      <td>
                        <?= e((string) (($work['vendor_name'] ?? '') !== '' ? $work['vendor_name'] : ($work['partner_name'] ?? '-'))); ?>
                        <?php if (!empty($work['vendor_code'])): ?>
                          <div><small class="text-muted"><?= e((string) $work['vendor_code']); ?></small></div>
                        <?php endif; ?>
                      </td>
                      <td><?= e((string) ($work['service_description'] ?? '')); ?></td>
                      <td><span class="badge text-bg-<?= e(ow_status_badge_class($currentStatus)); ?>"><?= e($currentStatus); ?></span></td>
                      <td><?= e((string) ($work['expected_return_date'] ?? '-')); ?></td>
                      <td><?= e(format_currency($agreedCost)); ?></td>
                      <td><?= e(format_currency($paidAmount)); ?><br><small class="text-muted"><?= (int) ($work['payment_count'] ?? 0); ?> entries</small></td>
                      <td><?= e(format_currency($outstanding)); ?></td>
                      <td class="text-nowrap">
                        <?php if ($canManage): ?>
                          <a href="<?= e(url('modules/outsourced/index.php?' . ($baseQuery !== '' ? $baseQuery . '&' : '') . 'edit_id=' . (int) $work['id'])); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                          <?php if ($nextStatus !== null): ?>
                            <form method="post" class="d-inline">
                              <?= csrf_field(); ?>
                              <input type="hidden" name="_action" value="transition_status" />
                              <input type="hidden" name="work_id" value="<?= (int) $work['id']; ?>" />
                              <input type="hidden" name="next_status" value="<?= e($nextStatus); ?>" />
                              <button type="submit" class="btn btn-sm btn-outline-secondary" <?= (!$canMovePaid) ? 'disabled' : ''; ?>>
                                <?= e('Move ' . $nextStatus); ?>
                              </button>
                            </form>
                          <?php endif; ?>
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
              <div class="card-header"><h3 class="card-title mb-0">Pending Payable Report</h3></div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0">
                  <thead><tr><th>Job</th><th>Vendor</th><th>Status</th><th>Expected Return</th><th>Outstanding</th></tr></thead>
                  <tbody>
                    <?php if (empty($pendingPayables)): ?>
                      <tr><td colspan="5" class="text-center text-muted py-4">No pending payable rows.</td></tr>
                    <?php else: foreach ($pendingPayables as $row): ?>
                      <tr>
                        <td><?= e((string) ($row['job_number'] ?? '')); ?></td>
                        <td><?= e((string) ($row['vendor_label'] ?? '')); ?></td>
                        <td><span class="badge text-bg-<?= e(ow_status_badge_class((string) ($row['current_status'] ?? 'SENT'))); ?>"><?= e((string) ow_normalize_status((string) ($row['current_status'] ?? 'SENT'))); ?></span></td>
                        <td><?= e((string) ($row['expected_return_date'] ?? '-')); ?></td>
                        <td><?= e(format_currency((float) ($row['outstanding_amount'] ?? 0))); ?></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card h-100">
              <div class="card-header"><h3 class="card-title mb-0">Vendor-wise Outstanding Summary</h3></div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0">
                  <thead><tr><th>Vendor</th><th>Works</th><th>Agreed</th><th>Paid</th><th>Outstanding</th></tr></thead>
                  <tbody>
                    <?php if (empty($vendorOutstanding)): ?>
                      <tr><td colspan="5" class="text-center text-muted py-4">No vendor summary rows.</td></tr>
                    <?php else: foreach ($vendorOutstanding as $row): ?>
                      <tr>
                        <td><?= e((string) ($row['vendor_label'] ?? '')); ?></td>
                        <td><?= (int) ($row['work_count'] ?? 0); ?></td>
                        <td><?= e(format_currency((float) ($row['agreed_total'] ?? 0))); ?></td>
                        <td><?= e(format_currency((float) ($row['paid_total'] ?? 0))); ?></td>
                        <td><?= e(format_currency((float) ($row['outstanding_total'] ?? 0))); ?></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-lg-6">
            <div class="card h-100">
              <div class="card-header"><h3 class="card-title mb-0">Job-wise Outsourcing Cost Summary</h3></div>
              <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-striped mb-0">
                  <thead><tr><th>Job</th><th>Customer</th><th>Vehicle</th><th>Lines</th><th>Agreed</th><th>Outstanding</th></tr></thead>
                  <tbody>
                    <?php if (empty($jobCostSummary)): ?>
                      <tr><td colspan="6" class="text-center text-muted py-4">No job summary rows.</td></tr>
                    <?php else: foreach ($jobCostSummary as $row): ?>
                      <tr>
                        <td><?= e((string) ($row['job_number'] ?? '')); ?></td>
                        <td><?= e((string) ($row['customer_name'] ?? '')); ?></td>
                        <td><?= e((string) ($row['registration_no'] ?? '-')); ?></td>
                        <td><?= (int) ($row['outsourced_lines'] ?? 0); ?></td>
                        <td><?= e(format_currency((float) ($row['agreed_total'] ?? 0))); ?></td>
                        <td><?= e(format_currency((float) ($row['outstanding_total'] ?? 0))); ?></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card h-100">
              <div class="card-header"><h3 class="card-title mb-0">Outsourced vs In-house Profitability</h3></div>
              <div class="card-body">
                <div class="row g-2 mb-3">
                  <div class="col-6"><strong>In-house Billed:</strong> <?= e(format_currency($profitInhouseBilled)); ?></div>
                  <div class="col-6"><strong>Outsourced Billed:</strong> <?= e(format_currency($profitOutsourcedBilled)); ?></div>
                  <div class="col-6"><strong>Outsource Cost:</strong> <?= e(format_currency($profitOutsourceCost)); ?></div>
                  <div class="col-6"><strong>Outsource Margin:</strong> <?= e(format_currency($profitOutsourceMargin)); ?></div>
                  <div class="col-12"><strong>Overall Labor Margin:</strong> <?= e(format_currency($profitOverallLaborMargin)); ?></div>
                </div>
                <div class="table-responsive">
                  <table class="table table-sm table-striped mb-0">
                    <thead><tr><th>Job</th><th>In-house</th><th>Outsource Billed</th><th>Outsource Cost</th><th>Margin</th></tr></thead>
                    <tbody>
                      <?php if (empty($profitabilityRows)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">No profitability rows.</td></tr>
                      <?php else: foreach ($profitabilityRows as $row): ?>
                        <?php $margin = ((float) ($row['outsourced_billed'] ?? 0)) - ((float) ($row['outsourced_cost'] ?? 0)); ?>
                        <tr>
                          <td><?= e((string) ($row['job_number'] ?? '')); ?></td>
                          <td><?= e(format_currency((float) ($row['inhouse_billed'] ?? 0))); ?></td>
                          <td><?= e(format_currency((float) ($row['outsourced_billed'] ?? 0))); ?></td>
                          <td><?= e(format_currency((float) ($row['outsourced_cost'] ?? 0))); ?></td>
                          <td class="<?= $margin < 0 ? 'text-danger' : 'text-success'; ?>"><?= e(format_currency($margin)); ?></td>
                        </tr>
                      <?php endforeach; endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="card mb-3">
          <div class="card-header"><h3 class="card-title">Payment History Ledger</h3></div>
          <div class="card-body p-0 table-responsive">
            <table class="table table-sm table-striped mb-0">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Entry</th>
                  <th>Job</th>
                  <th>Vendor</th>
                  <th>Mode</th>
                  <th>Amount</th>
                  <th>Reference</th>
                  <th>Notes</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($paymentHistory)): ?>
                  <tr><td colspan="8" class="text-center text-muted py-4">No payment history in selected range.</td></tr>
                <?php else: foreach ($paymentHistory as $row): ?>
                  <tr>
                    <td><?= e((string) ($row['payment_date'] ?? '')); ?></td>
                    <td>
                      <?php if ((string) ($row['entry_type'] ?? '') === 'REVERSAL'): ?>
                        <span class="badge text-bg-warning">REVERSAL</span>
                      <?php else: ?>
                        <span class="badge text-bg-success">PAYMENT</span>
                      <?php endif; ?>
                    </td>
                    <td><?= e((string) ($row['job_number'] ?? '')); ?></td>
                    <td><?= e((string) ($row['vendor_label'] ?? '')); ?></td>
                    <td><?= e((string) ($row['payment_mode'] ?? '')); ?></td>
                    <td><?= e(format_currency((float) ($row['amount'] ?? 0))); ?></td>
                    <td><?= e((string) ($row['reference_no'] ?? '-')); ?></td>
                    <td><?= e((string) ($row['notes'] ?? '-')); ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
