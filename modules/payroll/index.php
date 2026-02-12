<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();

$companyId = active_company_id();
$garageId = active_garage_id();
$today = date('Y-m-d');

$canView = has_permission('payroll.view') || has_permission('payroll.manage');
$canManage = has_permission('payroll.manage');
if (!$canView) {
    require_permission('payroll.view');
}

$page_title = 'Payroll, Advances, and Salary';
$active_menu = 'finance.payroll';

function payroll_decimal(mixed $value): float
{
    if (is_string($value)) {
        $value = str_replace([','], '', $value);
    }

    return round((float) $value, 2);
}

function payroll_valid_month(string $month): bool
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        return false;
    }
    [$year, $mon] = explode('-', $month);
    return checkdate((int) $mon, 1, (int) $year);
}

function payroll_staff_in_scope(PDO $pdo, int $userId, int $companyId, int $garageId): bool
{
    if ($userId <= 0 || $companyId <= 0 || $garageId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT u.id
         FROM users u
         INNER JOIN user_garages ug ON ug.user_id = u.id AND ug.garage_id = :garage_id
         WHERE u.id = :user_id
           AND u.company_id = :company_id
           AND u.status_code = "ACTIVE"
         LIMIT 1'
    );
    $stmt->execute([
        'user_id' => $userId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);

    return (bool) $stmt->fetch();
}

function payroll_recompute_item(PDO $pdo, int $itemId): void
{
    $itemStmt = $pdo->prepare(
        'SELECT id, sheet_id, base_amount, commission_base, commission_rate, overtime_hours, overtime_rate,
                advance_deduction, loan_deduction, manual_deduction, paid_amount
         FROM payroll_salary_items
         WHERE id = :id
         LIMIT 1'
    );
    $itemStmt->execute(['id' => $itemId]);
    $item = $itemStmt->fetch();
    if (!$item) {
        return;
    }

    $baseAmount = round((float) ($item['base_amount'] ?? 0), 2);
    $commissionBase = round((float) ($item['commission_base'] ?? 0), 2);
    $commissionRate = round((float) ($item['commission_rate'] ?? 0), 3);
    $commissionAmount = round($commissionBase * ($commissionRate / 100), 2);
    $overtimeHours = round((float) ($item['overtime_hours'] ?? 0), 2);
    $overtimeRate = round((float) ($item['overtime_rate'] ?? 0), 2);
    $overtimeAmount = round($overtimeHours * $overtimeRate, 2);

    $advanceDeduction = round((float) ($item['advance_deduction'] ?? 0), 2);
    $loanDeduction = round((float) ($item['loan_deduction'] ?? 0), 2);
    $manualDeduction = round((float) ($item['manual_deduction'] ?? 0), 2);
    $grossAmount = round($baseAmount + $commissionAmount + $overtimeAmount, 2);
    $totalDeductions = round($advanceDeduction + $loanDeduction + $manualDeduction, 2);
    $netPayable = max(0, round($grossAmount - $totalDeductions, 2));

    $paidAmount = round((float) ($item['paid_amount'] ?? 0), 2);
    $status = 'PENDING';
    if ($netPayable <= 0.001) {
        $status = 'PAID';
    } elseif ($paidAmount + 0.009 >= $netPayable) {
        $status = 'PAID';
    } elseif ($paidAmount > 0) {
        $status = 'PARTIAL';
    }

    $updateStmt = $pdo->prepare(
        'UPDATE payroll_salary_items
         SET commission_amount = :commission_amount,
             overtime_amount = :overtime_amount,
             gross_amount = :gross_amount,
             net_payable = :net_payable,
             status = :status
         WHERE id = :id'
    );
    $updateStmt->execute([
        'commission_amount' => $commissionAmount,
        'overtime_amount' => $overtimeAmount,
        'gross_amount' => $grossAmount,
        'net_payable' => $netPayable,
        'status' => $status,
        'id' => $itemId,
    ]);

    payroll_update_sheet_totals($pdo, (int) $item['sheet_id']);
}

function payroll_update_sheet_totals(PDO $pdo, int $sheetId): void
{
    $totalsStmt = $pdo->prepare(
        'SELECT
            COALESCE(SUM(gross_amount), 0) AS total_gross,
            COALESCE(SUM(advance_deduction + loan_deduction + manual_deduction), 0) AS total_deductions,
            COALESCE(SUM(net_payable), 0) AS total_payable,
            COALESCE(SUM(paid_amount), 0) AS total_paid
         FROM payroll_salary_items
         WHERE sheet_id = :sheet_id'
    );
    $totalsStmt->execute(['sheet_id' => $sheetId]);
    $totals = $totalsStmt->fetch();

    $updateStmt = $pdo->prepare(
        'UPDATE payroll_salary_sheets
         SET total_gross = :total_gross,
             total_deductions = :total_deductions,
             total_payable = :total_payable,
             total_paid = :total_paid
         WHERE id = :id'
    );
    $updateStmt->execute([
        'total_gross' => round((float) ($totals['total_gross'] ?? 0), 2),
        'total_deductions' => round((float) ($totals['total_deductions'] ?? 0), 2),
        'total_payable' => round((float) ($totals['total_payable'] ?? 0), 2),
        'total_paid' => round((float) ($totals['total_paid'] ?? 0), 2),
        'id' => $sheetId,
    ]);
}

function payroll_sheet_set_lock_state(PDO $pdo, int $sheetId, bool $lock, ?int $userId): void
{
    if ($lock) {
        $stmt = $pdo->prepare(
            'UPDATE payroll_salary_sheets
             SET status = "LOCKED",
                 locked_at = :locked_at,
                 locked_by = :locked_by
             WHERE id = :id'
        );
        $stmt->execute([
            'locked_at' => date('Y-m-d H:i:s'),
            'locked_by' => $userId > 0 ? $userId : null,
            'id' => $sheetId,
        ]);
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE payroll_salary_sheets
         SET status = "OPEN",
             locked_at = NULL,
             locked_by = NULL
         WHERE id = :id'
    );
    $stmt->execute(['id' => $sheetId]);
}

function payroll_sync_sheet_lock_state(PDO $pdo, int $sheetId, ?int $userId): bool
{
    payroll_update_sheet_totals($pdo, $sheetId);

    $sheetStmt = $pdo->prepare('SELECT status, total_payable, total_paid FROM payroll_salary_sheets WHERE id = :id LIMIT 1');
    $sheetStmt->execute(['id' => $sheetId]);
    $sheet = $sheetStmt->fetch();
    if (!$sheet) {
        return false;
    }

    $payable = round((float) ($sheet['total_payable'] ?? 0), 2);
    $paid = round((float) ($sheet['total_paid'] ?? 0), 2);
    $isSettled = $payable <= 0.009 || $paid + 0.009 >= $payable;
    $isLocked = (string) ($sheet['status'] ?? '') === 'LOCKED';

    if ($isSettled && !$isLocked) {
        payroll_sheet_set_lock_state($pdo, $sheetId, true, $userId);
    } elseif (!$isSettled && $isLocked) {
        payroll_sheet_set_lock_state($pdo, $sheetId, false, $userId);
    }

    return $isSettled;
}

function payroll_item_is_fully_paid(array $item): bool
{
    $netPayable = round((float) ($item['net_payable'] ?? 0), 2);
    $paidAmount = round((float) ($item['paid_amount'] ?? 0), 2);
    if ($netPayable <= 0.009) {
        return true;
    }

    return $paidAmount + 0.009 >= $netPayable;
}

function payroll_apply_advance_deduction(PDO $pdo, int $userId, int $companyId, int $garageId, float $applyAmount): float
{
    $remaining = max(0, round($applyAmount, 2));
    if ($remaining <= 0.0) {
        return 0.0;
    }

    $advStmt = $pdo->prepare(
        'SELECT id, amount, applied_amount
         FROM payroll_advances
         WHERE user_id = :user_id
           AND company_id = :company_id
           AND garage_id = :garage_id
           AND status = "OPEN"
         ORDER BY advance_date ASC, id ASC
         FOR UPDATE'
    );
    $advStmt->execute([
        'user_id' => $userId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
    $rows = $advStmt->fetchAll();

    $applied = 0.0;
    foreach ($rows as $row) {
        $available = round((float) ($row['amount'] ?? 0) - (float) ($row['applied_amount'] ?? 0), 2);
        if ($available <= 0) {
            continue;
        }
        $consume = min($available, $remaining);
        $updateStmt = $pdo->prepare(
            'UPDATE payroll_advances
             SET applied_amount = applied_amount + :consume,
                 status = CASE WHEN applied_amount + :consume + 0.009 >= amount THEN "CLOSED" ELSE "OPEN" END
             WHERE id = :id'
        );
        $updateStmt->execute([
            'consume' => $consume,
            'id' => (int) $row['id'],
        ]);
        $applied += $consume;
        $remaining -= $consume;
        if ($remaining <= 0.001) {
            break;
        }
    }

    return round($applied, 2);
}

function payroll_apply_loan_deduction(PDO $pdo, int $userId, int $companyId, int $garageId, float $applyAmount, int $salaryItemId, ?int $createdBy): float
{
    $remaining = max(0, round($applyAmount, 2));
    if ($remaining <= 0.0) {
        return 0.0;
    }

    $loanStmt = $pdo->prepare(
        'SELECT id, total_amount, paid_amount, emi_amount
         FROM payroll_loans
         WHERE user_id = :user_id
           AND company_id = :company_id
           AND garage_id = :garage_id
           AND status IN ("ACTIVE", "PAID")
         ORDER BY loan_date ASC, id ASC
         FOR UPDATE'
    );
    $loanStmt->execute([
        'user_id' => $userId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
    $loans = $loanStmt->fetchAll();

    $applied = 0.0;
    foreach ($loans as $loan) {
        $loanId = (int) $loan['id'];
        $totalAmount = round((float) ($loan['total_amount'] ?? 0), 2);
        $paidAmount = round((float) ($loan['paid_amount'] ?? 0), 2);
        $remainingLoan = max(0, round($totalAmount - $paidAmount, 2));
        if ($remainingLoan <= 0) {
            continue;
        }

        $emi = round((float) ($loan['emi_amount'] ?? 0), 2);
        $consume = min($remainingLoan, $remaining, $emi > 0 ? $emi : $remainingLoan);

        $insertStmt = $pdo->prepare(
            'INSERT INTO payroll_loan_payments
              (loan_id, company_id, garage_id, salary_item_id, payment_date, entry_type, amount, reference_no, notes, created_by)
             VALUES
              (:loan_id, :company_id, :garage_id, :salary_item_id, :payment_date, "EMI", :amount, :reference_no, :notes, :created_by)'
        );
        $insertStmt->execute([
            'loan_id' => $loanId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'salary_item_id' => $salaryItemId,
            'payment_date' => date('Y-m-d'),
            'amount' => $consume,
            'reference_no' => 'EMI-' . $salaryItemId,
            'notes' => 'Auto EMI from salary sheet',
            'created_by' => $createdBy > 0 ? $createdBy : null,
        ]);

        $updateStmt = $pdo->prepare(
            'UPDATE payroll_loans
             SET paid_amount = paid_amount + :consume,
                 status = CASE WHEN paid_amount + :consume + 0.009 >= total_amount THEN "PAID" ELSE "ACTIVE" END
             WHERE id = :id'
        );
        $updateStmt->execute([
            'consume' => $consume,
            'id' => $loanId,
        ]);

        $applied += $consume;
        $remaining -= $consume;
        if ($remaining <= 0.001) {
            break;
        }
    }

    return round($applied, 2);
}

function payroll_master_form_return_path(): string
{
    $returnTo = trim((string) ($_POST['return_to'] ?? ''));
    if ($returnTo === '') {
        return 'modules/payroll/index.php';
    }

    if (preg_match('/^modules\/payroll\/(index|master_forms)\.php(?:\?.*)?$/', $returnTo) === 1) {
        return $returnTo;
    }

    return 'modules/payroll/index.php';
}

$salaryMonth = trim((string) ($_GET['salary_month'] ?? date('Y-m')));
if (!payroll_valid_month($salaryMonth)) {
    $salaryMonth = date('Y-m');
}
$itemStatusFilter = strtoupper(trim((string) ($_GET['item_status'] ?? '')));
if (!in_array($itemStatusFilter, ['', 'PENDING', 'PARTIAL', 'PAID', 'LOCKED'], true)) {
    $itemStatusFilter = '';
}
$staffSearch = trim((string) ($_GET['staff_q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!$canManage) {
        flash_set('payroll_error', 'You do not have permission to modify payroll.', 'danger');
        redirect('modules/payroll/index.php');
    }

    $action = (string) ($_POST['_action'] ?? '');
    $masterFormReturn = payroll_master_form_return_path();

    if ($action === 'save_structure') {
        $userId = post_int('user_id');
        $salaryType = strtoupper(trim((string) ($_POST['salary_type'] ?? 'MONTHLY')));
        $baseAmount = payroll_decimal($_POST['base_amount'] ?? 0);
        $commissionRate = round((float) ($_POST['commission_rate'] ?? 0), 3);
        $overtimeRate = payroll_decimal($_POST['overtime_rate'] ?? 0);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));
        if (!in_array($statusCode, ['ACTIVE', 'INACTIVE'], true)) {
            $statusCode = 'ACTIVE';
        }

        if ($userId <= 0 || $baseAmount < 0) {
            flash_set('payroll_error', 'Select staff and enter base amount.', 'danger');
            redirect($masterFormReturn);
        }
        if (!in_array($salaryType, ['MONTHLY', 'PER_DAY', 'PER_JOB'], true)) {
            $salaryType = 'MONTHLY';
        }

        $pdo = db();
        if (!payroll_staff_in_scope($pdo, $userId, $companyId, $garageId)) {
            flash_set('payroll_error', 'Selected staff is outside current garage scope.', 'danger');
            redirect($masterFormReturn);
        }

        $existingStmt = $pdo->prepare(
            'SELECT id FROM payroll_salary_structures WHERE user_id = :user_id AND garage_id = :garage_id LIMIT 1'
        );
        $existingStmt->execute([
            'user_id' => $userId,
            'garage_id' => $garageId,
        ]);
        $existing = $existingStmt->fetch();

        if ($existing) {
            $updateStmt = $pdo->prepare(
                'UPDATE payroll_salary_structures
                 SET salary_type = :salary_type,
                     base_amount = :base_amount,
                     commission_rate = :commission_rate,
                     overtime_rate = :overtime_rate,
                     status_code = :status_code,
                     updated_by = :updated_by
                 WHERE id = :id'
            );
            $updateStmt->execute([
                'salary_type' => $salaryType,
                'base_amount' => $baseAmount,
                'commission_rate' => $commissionRate,
                'overtime_rate' => $overtimeRate > 0 ? $overtimeRate : null,
                'status_code' => $statusCode,
                'updated_by' => $_SESSION['user_id'] ?? null,
                'id' => (int) $existing['id'],
            ]);
            log_audit('payroll', 'salary_structure_update', (int) $existing['id'], 'Updated salary structure', [
                'entity' => 'salary_structure',
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'after' => [
                    'user_id' => $userId,
                    'salary_type' => $salaryType,
                    'base_amount' => $baseAmount,
                    'commission_rate' => $commissionRate,
                    'overtime_rate' => $overtimeRate > 0 ? $overtimeRate : null,
                    'status_code' => $statusCode,
                ],
            ]);
            flash_set('payroll_success', 'Salary structure updated.', 'success');
        } else {
            $insertStmt = $pdo->prepare(
                'INSERT INTO payroll_salary_structures
                  (user_id, company_id, garage_id, salary_type, base_amount, commission_rate, overtime_rate, status_code, created_by)
                 VALUES
                  (:user_id, :company_id, :garage_id, :salary_type, :base_amount, :commission_rate, :overtime_rate, :status_code, :created_by)'
            );
            $insertStmt->execute([
                'user_id' => $userId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'salary_type' => $salaryType,
                'base_amount' => $baseAmount,
                'commission_rate' => $commissionRate,
                'overtime_rate' => $overtimeRate > 0 ? $overtimeRate : null,
                'status_code' => $statusCode,
                'created_by' => $_SESSION['user_id'] ?? null,
            ]);
            $structureId = (int) $pdo->lastInsertId();
            log_audit('payroll', 'salary_structure_create', $structureId, 'Created salary structure', [
                'entity' => 'salary_structure',
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'after' => [
                    'user_id' => $userId,
                    'salary_type' => $salaryType,
                    'base_amount' => $baseAmount,
                    'commission_rate' => $commissionRate,
                    'overtime_rate' => $overtimeRate > 0 ? $overtimeRate : null,
                    'status_code' => $statusCode,
                ],
            ]);
            flash_set('payroll_success', 'Salary structure saved.', 'success');
        }

        redirect($masterFormReturn);
    }

  if ($action === 'delete_structure') {
    $structureId = post_int('structure_id');
    if ($structureId <= 0) {
      flash_set('payroll_error', 'Invalid salary structure.', 'danger');
      redirect($masterFormReturn);
    }

    $stmt = db()->prepare(
      'UPDATE payroll_salary_structures
       SET status_code = "INACTIVE",
         updated_by = :updated_by
       WHERE id = :id AND company_id = :company_id AND garage_id = :garage_id'
    );
    $stmt->execute([
      'updated_by' => $_SESSION['user_id'] ?? null,
      'id' => $structureId,
      'company_id' => $companyId,
      'garage_id' => $garageId,
    ]);

    log_audit('payroll', 'salary_structure_inactivate', $structureId, 'Inactivated salary structure', [
      'entity' => 'salary_structure',
      'company_id' => $companyId,
      'garage_id' => $garageId,
    ]);
    flash_set('payroll_success', 'Salary structure removed.', 'success');
    redirect($masterFormReturn);
  }

    if ($action === 'record_advance') {
        $userId = post_int('user_id');
        $amount = payroll_decimal($_POST['amount'] ?? 0);
        $advanceDate = trim((string) ($_POST['advance_date'] ?? $today));
        $notes = post_string('notes', 255);

        if ($userId <= 0 || $amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $advanceDate)) {
            flash_set('payroll_error', 'Valid staff, amount, and date are required for advance.', 'danger');
            redirect($masterFormReturn);
        }

        $pdo = db();
        if (!payroll_staff_in_scope($pdo, $userId, $companyId, $garageId)) {
            flash_set('payroll_error', 'Selected staff is outside current garage scope.', 'danger');
            redirect($masterFormReturn);
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO payroll_advances (user_id, company_id, garage_id, advance_date, amount, notes, created_by)
             VALUES (:user_id, :company_id, :garage_id, :advance_date, :amount, :notes, :created_by)'
        );
        $insertStmt->execute([
            'user_id' => $userId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'advance_date' => $advanceDate,
            'amount' => $amount,
            'notes' => $notes !== '' ? $notes : null,
            'created_by' => $_SESSION['user_id'] ?? null,
        ]);

        $advanceId = (int) $pdo->lastInsertId();
        log_audit('payroll', 'advance_create', $advanceId, 'Recorded staff advance', [
            'entity' => 'payroll_advance',
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'after' => [
                'user_id' => $userId,
                'advance_date' => $advanceDate,
                'amount' => $amount,
            ],
        ]);
        flash_set('payroll_success', 'Advance recorded.', 'success');
        redirect($masterFormReturn);
    }

      if ($action === 'update_advance') {
        $advanceId = post_int('advance_id');
        $amount = payroll_decimal($_POST['amount'] ?? 0);
        $advanceDate = trim((string) ($_POST['advance_date'] ?? $today));
        $notes = post_string('notes', 255);

        if ($advanceId <= 0 || $amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $advanceDate)) {
          flash_set('payroll_error', 'Valid advance, amount, and date are required.', 'danger');
          redirect($masterFormReturn);
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
          $advStmt = $pdo->prepare(
            'SELECT * FROM payroll_advances WHERE id = :id AND company_id = :company_id AND garage_id = :garage_id FOR UPDATE'
          );
          $advStmt->execute([
            'id' => $advanceId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
          ]);
          $advance = $advStmt->fetch();
          if (!$advance) {
            throw new RuntimeException('Advance not found.');
          }

          $applied = round((float) ($advance['applied_amount'] ?? 0), 2);
          if ($amount + 0.009 < $applied) {
            throw new RuntimeException('Amount cannot be less than applied value.');
          }

          $status = ($applied + 0.009 >= $amount) ? 'CLOSED' : 'OPEN';
          $update = $pdo->prepare(
            'UPDATE payroll_advances
             SET amount = :amount,
               advance_date = :advance_date,
               notes = :notes,
               status = :status
             WHERE id = :id'
          );
          $update->execute([
            'amount' => $amount,
            'advance_date' => $advanceDate,
            'notes' => $notes !== '' ? $notes : null,
            'status' => $status,
            'id' => $advanceId,
          ]);

          log_audit('payroll', 'advance_update', $advanceId, 'Updated staff advance', [
            'entity' => 'payroll_advance',
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'before' => [
              'amount' => (float) ($advance['amount'] ?? 0),
              'advance_date' => (string) ($advance['advance_date'] ?? ''),
              'status' => (string) ($advance['status'] ?? ''),
            ],
            'after' => [
              'amount' => $amount,
              'advance_date' => $advanceDate,
              'status' => $status,
            ],
          ]);
          $pdo->commit();
          flash_set('payroll_success', 'Advance updated.', 'success');
        } catch (Throwable $exception) {
          $pdo->rollBack();
          flash_set('payroll_error', $exception->getMessage(), 'danger');
        }

        redirect($masterFormReturn);
      }

      if ($action === 'delete_advance') {
        $advanceId = post_int('advance_id');
        if ($advanceId <= 0) {
          flash_set('payroll_error', 'Invalid advance selected.', 'danger');
          redirect($masterFormReturn);
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
          $advStmt = $pdo->prepare(
            'SELECT applied_amount FROM payroll_advances WHERE id = :id AND company_id = :company_id AND garage_id = :garage_id FOR UPDATE'
          );
          $advStmt->execute([
            'id' => $advanceId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
          ]);
          $advance = $advStmt->fetch();
          if (!$advance) {
            throw new RuntimeException('Advance not found.');
          }
          $applied = round((float) ($advance['applied_amount'] ?? 0), 2);
          if ($applied > 0.009) {
            throw new RuntimeException('Cannot delete an advance that is already applied.');
          }

          $deleteStmt = $pdo->prepare(
            'UPDATE payroll_advances SET status = "DELETED" WHERE id = :id'
          );
          $deleteStmt->execute(['id' => $advanceId]);
          log_audit('payroll', 'advance_delete', $advanceId, 'Soft deleted staff advance', [
            'entity' => 'payroll_advance',
            'company_id' => $companyId,
            'garage_id' => $garageId,
          ]);
          $pdo->commit();
          flash_set('payroll_success', 'Advance deleted.', 'success');
        } catch (Throwable $exception) {
          $pdo->rollBack();
          flash_set('payroll_error', $exception->getMessage(), 'danger');
        }

        redirect($masterFormReturn);
      }

    if ($action === 'record_loan') {
        $userId = post_int('user_id');
        $loanDate = trim((string) ($_POST['loan_date'] ?? $today));
        $totalAmount = payroll_decimal($_POST['total_amount'] ?? 0);
        $emiAmount = payroll_decimal($_POST['emi_amount'] ?? 0);
        $notes = post_string('notes', 255);

        if ($userId <= 0 || $totalAmount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $loanDate)) {
            flash_set('payroll_error', 'Valid staff, date, and loan amount are required.', 'danger');
            redirect($masterFormReturn);
        }

        $pdo = db();
        if (!payroll_staff_in_scope($pdo, $userId, $companyId, $garageId)) {
            flash_set('payroll_error', 'Selected staff is outside current garage scope.', 'danger');
            redirect($masterFormReturn);
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO payroll_loans (user_id, company_id, garage_id, loan_date, total_amount, emi_amount, notes, created_by)
             VALUES (:user_id, :company_id, :garage_id, :loan_date, :total_amount, :emi_amount, :notes, :created_by)'
        );
        $insertStmt->execute([
            'user_id' => $userId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'loan_date' => $loanDate,
            'total_amount' => $totalAmount,
            'emi_amount' => $emiAmount,
            'notes' => $notes !== '' ? $notes : null,
            'created_by' => $_SESSION['user_id'] ?? null,
        ]);

        $loanId = (int) $pdo->lastInsertId();
        log_audit('payroll', 'loan_create', $loanId, 'Created staff loan', [
            'entity' => 'payroll_loan',
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'after' => [
                'user_id' => $userId,
                'loan_date' => $loanDate,
                'total_amount' => $totalAmount,
                'emi_amount' => $emiAmount,
            ],
        ]);
        flash_set('payroll_success', 'Loan recorded for staff.', 'success');
        redirect($masterFormReturn);
    }

      if ($action === 'update_loan') {
        $loanId = post_int('loan_id');
        $loanDate = trim((string) ($_POST['loan_date'] ?? $today));
        $totalAmount = payroll_decimal($_POST['total_amount'] ?? 0);
        $emiAmount = payroll_decimal($_POST['emi_amount'] ?? 0);
        $notes = post_string('notes', 255);

        if ($loanId <= 0 || $totalAmount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $loanDate)) {
          flash_set('payroll_error', 'Valid loan, date, and amount are required.', 'danger');
          redirect($masterFormReturn);
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
          $loanStmt = $pdo->prepare(
            'SELECT * FROM payroll_loans WHERE id = :id AND company_id = :company_id AND garage_id = :garage_id FOR UPDATE'
          );
          $loanStmt->execute([
            'id' => $loanId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
          ]);
          $loan = $loanStmt->fetch();
          if (!$loan) {
            throw new RuntimeException('Loan not found.');
          }

          $paid = round((float) ($loan['paid_amount'] ?? 0), 2);
          if ($totalAmount + 0.009 < $paid) {
            throw new RuntimeException('Total cannot be less than already paid amount.');
          }

          $status = ($paid + 0.009 >= $totalAmount) ? 'PAID' : 'ACTIVE';
          $updateLoan = $pdo->prepare(
            'UPDATE payroll_loans
             SET loan_date = :loan_date,
               total_amount = :total_amount,
               emi_amount = :emi_amount,
               notes = :notes,
               status = :status
             WHERE id = :id'
          );
          $updateLoan->execute([
            'loan_date' => $loanDate,
            'total_amount' => $totalAmount,
            'emi_amount' => $emiAmount,
            'notes' => $notes !== '' ? $notes : null,
            'status' => $status,
            'id' => $loanId,
          ]);

          log_audit('payroll', 'loan_update', $loanId, 'Updated staff loan', [
            'entity' => 'payroll_loan',
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'before' => [
              'loan_date' => (string) ($loan['loan_date'] ?? ''),
              'total_amount' => (float) ($loan['total_amount'] ?? 0),
              'emi_amount' => (float) ($loan['emi_amount'] ?? 0),
              'status' => (string) ($loan['status'] ?? ''),
            ],
            'after' => [
              'loan_date' => $loanDate,
              'total_amount' => $totalAmount,
              'emi_amount' => $emiAmount,
              'status' => $status,
            ],
          ]);
          $pdo->commit();
          flash_set('payroll_success', 'Loan updated.', 'success');
        } catch (Throwable $exception) {
          $pdo->rollBack();
          flash_set('payroll_error', $exception->getMessage(), 'danger');
        }

        redirect($masterFormReturn);
      }

      if ($action === 'delete_loan') {
        $loanId = post_int('loan_id');
        if ($loanId <= 0) {
          flash_set('payroll_error', 'Invalid loan selected.', 'danger');
          redirect($masterFormReturn);
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
          $loanStmt = $pdo->prepare(
            'SELECT paid_amount FROM payroll_loans WHERE id = :id AND company_id = :company_id AND garage_id = :garage_id FOR UPDATE'
          );
          $loanStmt->execute([
            'id' => $loanId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
          ]);
          $loan = $loanStmt->fetch();
          if (!$loan) {
            throw new RuntimeException('Loan not found.');
          }
          $paid = round((float) ($loan['paid_amount'] ?? 0), 2);
          if ($paid > 0.009) {
            throw new RuntimeException('Cannot delete a loan that has payments.');
          }

          $deleteStmt = $pdo->prepare('UPDATE payroll_loans SET status = "DELETED" WHERE id = :id');
          $deleteStmt->execute(['id' => $loanId]);
          log_audit('payroll', 'loan_delete', $loanId, 'Soft deleted staff loan', [
            'entity' => 'payroll_loan',
            'company_id' => $companyId,
            'garage_id' => $garageId,
          ]);
          $pdo->commit();
          flash_set('payroll_success', 'Loan deleted.', 'success');
        } catch (Throwable $exception) {
          $pdo->rollBack();
          flash_set('payroll_error', $exception->getMessage(), 'danger');
        }

        redirect($masterFormReturn);
      }

    if ($action === 'loan_manual_payment') {
        $loanId = post_int('loan_id');
        $amount = payroll_decimal($_POST['amount'] ?? 0);
        $paymentDate = trim((string) ($_POST['payment_date'] ?? $today));
        $notes = post_string('notes', 255);
        if ($loanId <= 0 || $amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
            flash_set('payroll_error', 'Valid loan, amount, and date are required.', 'danger');
            redirect($masterFormReturn);
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $loanStmt = $pdo->prepare('SELECT * FROM payroll_loans WHERE id = :id AND company_id = :company_id AND garage_id = :garage_id FOR UPDATE');
            $loanStmt->execute([
                'id' => $loanId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ]);
            $loan = $loanStmt->fetch();
            if (!$loan) {
                throw new RuntimeException('Loan not found for this garage.');
            }

            $remaining = max(0, round((float) ($loan['total_amount'] ?? 0) - (float) ($loan['paid_amount'] ?? 0), 2));
            if ($remaining <= 0) {
                throw new RuntimeException('Loan already cleared.');
            }
            if ($amount > $remaining + 0.009) {
                throw new RuntimeException('Amount exceeds pending loan balance.');
            }

            $insertPay = $pdo->prepare(
                'INSERT INTO payroll_loan_payments
                  (loan_id, company_id, garage_id, payment_date, entry_type, amount, reference_no, notes, created_by)
                 VALUES
                  (:loan_id, :company_id, :garage_id, :payment_date, "MANUAL", :amount, :reference_no, :notes, :created_by)'
            );
            $insertPay->execute([
                'loan_id' => $loanId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'payment_date' => $paymentDate,
                'amount' => $amount,
                'reference_no' => 'MANUAL-' . $loanId,
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => $_SESSION['user_id'] ?? null,
            ]);
            $loanPaymentId = (int) $pdo->lastInsertId();

            $updateLoan = $pdo->prepare(
                'UPDATE payroll_loans
                 SET paid_amount = paid_amount + :amount,
                     status = CASE WHEN paid_amount + :amount + 0.009 >= total_amount THEN "PAID" ELSE "ACTIVE" END
                 WHERE id = :id'
            );
            $updateLoan->execute([
                'amount' => $amount,
                'id' => $loanId,
            ]);

            log_audit('payroll', 'loan_payment_manual', $loanId, 'Captured manual loan payment', [
                'entity' => 'payroll_loan_payment',
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'after' => [
                    'loan_id' => $loanId,
                    'payment_id' => $loanPaymentId,
                    'amount' => $amount,
                    'payment_date' => $paymentDate,
                ],
            ]);
            $pdo->commit();
            flash_set('payroll_success', 'Loan payment captured.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('payroll_error', $exception->getMessage(), 'danger');
        }

        redirect($masterFormReturn);
    }

    if ($action === 'reverse_loan_payment') {
        $paymentId = post_int('payment_id');
        $reverseReason = post_string('reverse_reason', 255);
        if ($paymentId <= 0 || $reverseReason === '') {
            flash_set('payroll_error', 'Payment and reversal reason are required.', 'danger');
            redirect($masterFormReturn);
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $paymentStmt = $pdo->prepare(
                'SELECT lp.*, pl.id AS loan_id, pl.total_amount
                 FROM payroll_loan_payments lp
                 INNER JOIN payroll_loans pl ON pl.id = lp.loan_id
                 WHERE lp.id = :id
                   AND lp.company_id = :company_id
                   AND lp.garage_id = :garage_id
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
                throw new RuntimeException('Loan payment not found.');
            }
            if ((string) ($payment['entry_type'] ?? '') === 'REVERSAL') {
                throw new RuntimeException('Reversal entries cannot be reversed again.');
            }
            if ((float) ($payment['amount'] ?? 0) <= 0) {
                throw new RuntimeException('Only positive payment entries can be reversed.');
            }
            if ((string) ($payment['entry_type'] ?? '') !== 'MANUAL') {
                throw new RuntimeException('Only manual loan payments can be reversed from this screen.');
            }

            $checkStmt = $pdo->prepare(
                'SELECT id
                 FROM payroll_loan_payments
                 WHERE reversed_payment_id = :payment_id
                 LIMIT 1'
            );
            $checkStmt->execute(['payment_id' => $paymentId]);
            if ($checkStmt->fetch()) {
                throw new RuntimeException('This loan payment is already reversed.');
            }

            $paymentAmount = round((float) ($payment['amount'] ?? 0), 2);
            $insertStmt = $pdo->prepare(
                'INSERT INTO payroll_loan_payments
                  (loan_id, company_id, garage_id, salary_item_id, payment_date, entry_type, amount, reference_no, notes, reversed_payment_id, created_by)
                 VALUES
                  (:loan_id, :company_id, :garage_id, :salary_item_id, :payment_date, "REVERSAL", :amount, :reference_no, :notes, :reversed_payment_id, :created_by)'
            );
            $insertStmt->execute([
                'loan_id' => (int) $payment['loan_id'],
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'salary_item_id' => isset($payment['salary_item_id']) ? (int) $payment['salary_item_id'] : null,
                'payment_date' => $today,
                'amount' => -$paymentAmount,
                'reference_no' => 'REV-' . $paymentId,
                'notes' => $reverseReason,
                'reversed_payment_id' => $paymentId,
                'created_by' => $_SESSION['user_id'] ?? null,
            ]);
            $reversalId = (int) $pdo->lastInsertId();

            $totalPaidStmt = $pdo->prepare(
                'SELECT COALESCE(SUM(amount), 0) AS total_paid
                 FROM payroll_loan_payments
                 WHERE loan_id = :loan_id'
            );
            $totalPaidStmt->execute(['loan_id' => (int) $payment['loan_id']]);
            $newPaidAmount = max(0.0, round((float) ($totalPaidStmt->fetchColumn() ?? 0), 2));

            $updateLoanStmt = $pdo->prepare(
                'UPDATE payroll_loans
                 SET paid_amount = :paid_amount,
                     status = CASE WHEN :paid_amount + 0.009 >= total_amount THEN "PAID" ELSE "ACTIVE" END
                 WHERE id = :id'
            );
            $updateLoanStmt->execute([
                'paid_amount' => $newPaidAmount,
                'id' => (int) $payment['loan_id'],
            ]);

            log_audit('payroll', 'loan_payment_reverse', (int) $payment['loan_id'], 'Reversed manual loan payment', [
                'entity' => 'payroll_loan_payment',
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'after' => [
                    'payment_id' => $paymentId,
                    'reversal_id' => $reversalId,
                    'reversal_amount' => -$paymentAmount,
                    'loan_id' => (int) $payment['loan_id'],
                ],
            ]);
            $pdo->commit();
            flash_set('payroll_success', 'Loan payment reversed.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('payroll_error', $exception->getMessage(), 'danger');
        }

        redirect($masterFormReturn);
    }

    if ($action === 'generate_sheet') {
        $month = post_string('salary_month', 7);
        if (!payroll_valid_month($month)) {
            flash_set('payroll_error', 'Invalid payroll month.', 'danger');
            redirect('modules/payroll/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $sheetStmt = $pdo->prepare(
                'SELECT id, status FROM payroll_salary_sheets WHERE company_id = :company_id AND garage_id = :garage_id AND salary_month = :salary_month LIMIT 1 FOR UPDATE'
            );
            $sheetStmt->execute([
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'salary_month' => $month,
            ]);
            $sheet = $sheetStmt->fetch();
            if ($sheet && (string) ($sheet['status'] ?? '') === 'LOCKED') {
                throw new RuntimeException('Sheet already locked for this month.');
            }

            if (!$sheet) {
                $insertSheet = $pdo->prepare(
                    'INSERT INTO payroll_salary_sheets (company_id, garage_id, salary_month, status, created_by)
                     VALUES (:company_id, :garage_id, :salary_month, "OPEN", :created_by)'
                );
                $insertSheet->execute([
                    'company_id' => $companyId,
                    'garage_id' => $garageId,
                    'salary_month' => $month,
                    'created_by' => $_SESSION['user_id'] ?? null,
                ]);
                $sheetId = (int) $pdo->lastInsertId();
            } else {
                $sheetId = (int) $sheet['id'];
            }

            $structuresStmt = $pdo->prepare(
                'SELECT ss.*, u.name
                 FROM payroll_salary_structures ss
                 INNER JOIN users u ON u.id = ss.user_id
                 INNER JOIN user_garages ug ON ug.user_id = u.id AND ug.garage_id = :garage_id
                 WHERE ss.company_id = :company_id
                   AND ss.garage_id = :garage_id
                   AND ss.status_code = "ACTIVE"'
            );
            $structuresStmt->execute([
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ]);
            $structures = $structuresStmt->fetchAll();
            if (empty($structures)) {
                throw new RuntimeException('No active salary structures found for this garage.');
            }

            $advanceMap = [];
            $advQuery = $pdo->prepare(
                'SELECT user_id, SUM(amount - applied_amount) AS pending
                 FROM payroll_advances
                 WHERE company_id = :company_id AND garage_id = :garage_id AND status = "OPEN"
                 GROUP BY user_id'
            );
            $advQuery->execute([
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ]);
            foreach ($advQuery->fetchAll() as $row) {
                $advanceMap[(int) $row['user_id']] = round((float) ($row['pending'] ?? 0), 2);
            }

            $loanMap = [];
            $loanQuery = $pdo->prepare(
                'SELECT user_id, SUM(total_amount - paid_amount) AS pending, MAX(emi_amount) AS emi
                 FROM payroll_loans
                 WHERE company_id = :company_id AND garage_id = :garage_id AND status IN ("ACTIVE", "PAID")
                 GROUP BY user_id'
            );
            $loanQuery->execute([
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ]);
            foreach ($loanQuery->fetchAll() as $row) {
                $loanMap[(int) $row['user_id']] = [
                    'pending' => round((float) ($row['pending'] ?? 0), 2),
                    'emi' => round((float) ($row['emi'] ?? 0), 2),
                ];
            }

            $existingItemsStmt = $pdo->prepare('SELECT user_id FROM payroll_salary_items WHERE sheet_id = :sheet_id');
            $existingItemsStmt->execute(['sheet_id' => $sheetId]);
            $existingUsers = array_map(static fn (array $row): int => (int) $row['user_id'], $existingItemsStmt->fetchAll());

            $insertItem = $pdo->prepare(
                'INSERT INTO payroll_salary_items
                  (sheet_id, user_id, salary_type, base_amount, commission_base, commission_rate, commission_amount, overtime_hours, overtime_rate, overtime_amount,
                   advance_deduction, loan_deduction, manual_deduction, gross_amount, net_payable, paid_amount, status)
                 VALUES
                  (:sheet_id, :user_id, :salary_type, :base_amount, :commission_base, :commission_rate, :commission_amount, :overtime_hours, :overtime_rate, :overtime_amount,
                   :advance_deduction, :loan_deduction, 0, :gross_amount, :net_payable, 0, :status)'
            );

            foreach ($structures as $structure) {
                $staffId = (int) $structure['user_id'];
                if (in_array($staffId, $existingUsers, true)) {
                    continue;
                }

                $baseAmount = round((float) ($structure['base_amount'] ?? 0), 2);
                $commissionRate = round((float) ($structure['commission_rate'] ?? 0), 3);
                $commissionBase = $baseAmount;
                $commissionAmount = round($commissionBase * ($commissionRate / 100), 2);
                $overtimeRate = round((float) ($structure['overtime_rate'] ?? 0), 2);
                $overtimeHours = 0.0;
                $overtimeAmount = 0.0;

                $advancePending = $advanceMap[$staffId] ?? 0.0;
                $loanPending = $loanMap[$staffId]['pending'] ?? 0.0;
                $loanEmi = $loanMap[$staffId]['emi'] ?? 0.0;
                $loanDeduction = min($loanPending, $loanEmi > 0 ? $loanEmi : $loanPending);
                $advanceDeduction = $advancePending;

                $grossAmount = round($baseAmount + $commissionAmount + $overtimeAmount, 2);
                $totalDeductions = round($advanceDeduction + $loanDeduction, 2);
                $netPayable = max(0, round($grossAmount - $totalDeductions, 2));
                $status = $netPayable <= 0.001 ? 'PAID' : 'PENDING';

                $insertItem->execute([
                    'sheet_id' => $sheetId,
                    'user_id' => $staffId,
                    'salary_type' => (string) ($structure['salary_type'] ?? 'MONTHLY'),
                    'base_amount' => $baseAmount,
                    'commission_base' => $commissionBase,
                    'commission_rate' => $commissionRate,
                    'commission_amount' => $commissionAmount,
                    'overtime_hours' => $overtimeHours,
                    'overtime_rate' => $overtimeRate > 0 ? $overtimeRate : null,
                    'overtime_amount' => $overtimeAmount,
                    'advance_deduction' => $advanceDeduction,
                    'loan_deduction' => $loanDeduction,
                    'gross_amount' => $grossAmount,
                    'net_payable' => $netPayable,
                    'status' => $status,
                ]);
            }

            $isSettled = payroll_sync_sheet_lock_state($pdo, $sheetId, $_SESSION['user_id'] ?? null);
            log_audit('payroll', 'salary_sheet_generate', $sheetId, 'Generated / synced salary sheet', [
                'entity' => 'payroll_salary_sheet',
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'after' => [
                    'salary_month' => $month,
                    'auto_locked' => $isSettled ? 1 : 0,
                    'staff_count' => count($structures),
                ],
            ]);
            $pdo->commit();
            flash_set('payroll_success', 'Salary sheet prepared for ' . e($month) . '.', 'success');
            redirect('modules/payroll/index.php?salary_month=' . urlencode($month));
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('payroll_error', $exception->getMessage(), 'danger');
            redirect('modules/payroll/index.php');
        }
    }

    if ($action === 'update_item') {
        $itemId = post_int('item_id');
        $baseAmount = payroll_decimal($_POST['base_amount'] ?? 0);
        $commissionBase = payroll_decimal($_POST['commission_base'] ?? 0);
        $commissionRate = round((float) ($_POST['commission_rate'] ?? 0), 3);
        $overtimeHours = round((float) ($_POST['overtime_hours'] ?? 0), 2);
        $overtimeRate = payroll_decimal($_POST['overtime_rate'] ?? 0);
        $advanceDeduction = payroll_decimal($_POST['advance_deduction'] ?? 0);
        $loanDeduction = payroll_decimal($_POST['loan_deduction'] ?? 0);
        $manualDeduction = payroll_decimal($_POST['manual_deduction'] ?? 0);

        if ($itemId <= 0) {
            flash_set('payroll_error', 'Invalid salary item.', 'danger');
            redirect('modules/payroll/index.php');
        }

        $pdo = db();
        $itemStmt = $pdo->prepare(
            'SELECT psi.id, psi.sheet_id, psi.paid_amount, psi.net_payable, pss.status
             FROM payroll_salary_items psi
             INNER JOIN payroll_salary_sheets pss ON pss.id = psi.sheet_id
             WHERE psi.id = :id AND pss.company_id = :company_id AND pss.garage_id = :garage_id'
        );
        $itemStmt->execute([
            'id' => $itemId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
        $item = $itemStmt->fetch();
        if (!$item) {
            flash_set('payroll_error', 'Salary item not found.', 'danger');
            redirect('modules/payroll/index.php');
        }
        if ((string) ($item['status'] ?? '') === 'LOCKED') {
            flash_set('payroll_error', 'Locked sheet cannot be edited.', 'danger');
            redirect('modules/payroll/index.php?salary_month=' . urlencode($salaryMonth));
        }

        $paidAmount = round((float) ($item['paid_amount'] ?? 0), 2);
        if (payroll_item_is_fully_paid($item)) {
            flash_set('payroll_error', 'Fully settled salary rows cannot be edited.', 'danger');
            redirect('modules/payroll/index.php?salary_month=' . urlencode($salaryMonth));
        }
        if ($paidAmount > 0.009) {
            flash_set('payroll_error', 'Partial salary payment exists. Reverse payments before editing salary amounts.', 'danger');
            redirect('modules/payroll/index.php?salary_month=' . urlencode($salaryMonth));
        }

        $updateStmt = $pdo->prepare(
            'UPDATE payroll_salary_items
             SET base_amount = :base_amount,
                 commission_base = :commission_base,
                 commission_rate = :commission_rate,
                 overtime_hours = :overtime_hours,
                 overtime_rate = :overtime_rate,
                 advance_deduction = :advance_deduction,
                 loan_deduction = :loan_deduction,
                 manual_deduction = :manual_deduction
             WHERE id = :id'
        );
        $updateStmt->execute([
            'base_amount' => $baseAmount,
            'commission_base' => $commissionBase,
            'commission_rate' => $commissionRate,
            'overtime_hours' => $overtimeHours,
            'overtime_rate' => $overtimeRate > 0 ? $overtimeRate : null,
            'advance_deduction' => $advanceDeduction,
            'loan_deduction' => $loanDeduction,
            'manual_deduction' => $manualDeduction,
            'id' => $itemId,
        ]);

        payroll_recompute_item($pdo, $itemId);
        payroll_sync_sheet_lock_state($pdo, (int) $item['sheet_id'], $_SESSION['user_id'] ?? null);
        log_audit('payroll', 'salary_item_update', $itemId, 'Updated salary item values', [
            'entity' => 'payroll_salary_item',
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'after' => [
                'base_amount' => $baseAmount,
                'commission_base' => $commissionBase,
                'commission_rate' => $commissionRate,
                'overtime_hours' => $overtimeHours,
                'overtime_rate' => $overtimeRate,
                'advance_deduction' => $advanceDeduction,
                'loan_deduction' => $loanDeduction,
                'manual_deduction' => $manualDeduction,
            ],
        ]);
        flash_set('payroll_success', 'Salary row updated.', 'success');
        redirect('modules/payroll/index.php?salary_month=' . urlencode($salaryMonth));
    }

    if ($action === 'reverse_salary_entry') {
        $itemId = post_int('item_id');
        $reverseReason = post_string('reverse_reason', 255);
        if ($itemId <= 0 || $reverseReason === '') {
            flash_set('payroll_error', 'Salary row and reversal reason are required.', 'danger');
            redirect('modules/payroll/index.php?salary_month=' . urlencode($salaryMonth));
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $itemStmt = $pdo->prepare(
                'SELECT psi.*, pss.id AS sheet_id, pss.status AS sheet_status
                 FROM payroll_salary_items psi
                 INNER JOIN payroll_salary_sheets pss ON pss.id = psi.sheet_id
                 WHERE psi.id = :id
                   AND pss.company_id = :company_id
                   AND pss.garage_id = :garage_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $itemStmt->execute([
                'id' => $itemId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ]);
            $item = $itemStmt->fetch();
            if (!$item) {
                throw new RuntimeException('Salary row not found.');
            }

            if ((string) ($item['sheet_status'] ?? '') === 'LOCKED') {
                throw new RuntimeException('Unlock by reversing payments before reversing this salary row.');
            }

            if (payroll_item_is_fully_paid($item)) {
                throw new RuntimeException('Fully settled salary rows cannot be reversed directly.');
            }

            if (round((float) ($item['paid_amount'] ?? 0), 2) > 0.009) {
                throw new RuntimeException('Reverse salary payments before reversing salary entry.');
            }

            if ((int) ($item['deductions_applied'] ?? 0) === 1) {
                throw new RuntimeException('This row has applied deductions. Reverse linked deductions first.');
            }

            $activePaymentStmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM payroll_salary_payments p
                 LEFT JOIN payroll_salary_payments r ON r.reversed_payment_id = p.id
                 WHERE p.salary_item_id = :salary_item_id
                   AND p.entry_type = "PAYMENT"
                   AND p.amount > 0
                   AND r.id IS NULL'
            );
            $activePaymentStmt->execute(['salary_item_id' => $itemId]);
            if ((int) ($activePaymentStmt->fetchColumn() ?? 0) > 0) {
                throw new RuntimeException('Open payment history exists. Reverse those payments first.');
            }

            $existingNotes = trim((string) ($item['notes'] ?? ''));
            $newNotes = 'Reversed salary entry: ' . $reverseReason;
            if ($existingNotes !== '') {
                $newNotes = $existingNotes . ' | ' . $newNotes;
            }

            $reverseStmt = $pdo->prepare(
                'UPDATE payroll_salary_items
                 SET base_amount = 0,
                     commission_base = 0,
                     commission_rate = 0,
                     commission_amount = 0,
                     overtime_hours = 0,
                     overtime_rate = NULL,
                     overtime_amount = 0,
                     advance_deduction = 0,
                     loan_deduction = 0,
                     manual_deduction = 0,
                     gross_amount = 0,
                     net_payable = 0,
                     paid_amount = 0,
                     deductions_applied = 0,
                     status = "PAID",
                     notes = :notes
                 WHERE id = :id'
            );
            $reverseStmt->execute([
                'notes' => $newNotes,
                'id' => $itemId,
            ]);

            $sheetSettled = payroll_sync_sheet_lock_state($pdo, (int) $item['sheet_id'], $_SESSION['user_id'] ?? null);
            log_audit('payroll', 'salary_entry_reverse', $itemId, 'Reversed salary entry row', [
                'entity' => 'payroll_salary_item',
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'before' => [
                    'gross_amount' => (float) ($item['gross_amount'] ?? 0),
                    'net_payable' => (float) ($item['net_payable'] ?? 0),
                    'paid_amount' => (float) ($item['paid_amount'] ?? 0),
                ],
                'after' => [
                    'gross_amount' => 0.0,
                    'net_payable' => 0.0,
                    'paid_amount' => 0.0,
                    'reason' => $reverseReason,
                    'sheet_settled_after_reversal' => $sheetSettled ? 1 : 0,
                ],
            ]);

            $pdo->commit();
            flash_set('payroll_success', 'Salary entry reversed successfully.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('payroll_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/payroll/index.php?salary_month=' . urlencode($salaryMonth));
    }

    if ($action === 'add_salary_payment') {
        $itemId = post_int('item_id');
        $amount = payroll_decimal($_POST['amount'] ?? 0);
        $paymentDate = trim((string) ($_POST['payment_date'] ?? $today));
        $paymentMode = finance_normalize_payment_mode((string) ($_POST['payment_mode'] ?? 'BANK_TRANSFER'));
        $notes = post_string('notes', 255);
        if ($itemId <= 0 || $amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
            flash_set('payroll_error', 'Payment item, amount, and date are required.', 'danger');
            redirect('modules/payroll/index.php?salary_month=' . urlencode($salaryMonth));
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $itemStmt = $pdo->prepare(
                'SELECT psi.*, pss.status AS sheet_status, pss.company_id, pss.garage_id
                 FROM payroll_salary_items psi
                 INNER JOIN payroll_salary_sheets pss ON pss.id = psi.sheet_id
                 WHERE psi.id = :id AND pss.company_id = :company_id AND pss.garage_id = :garage_id
                 FOR UPDATE'
            );
            $itemStmt->execute([
                'id' => $itemId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ]);
            $item = $itemStmt->fetch();
            if (!$item) {
                throw new RuntimeException('Salary item not found.');
            }
            if ((string) ($item['sheet_status'] ?? '') === 'LOCKED') {
                throw new RuntimeException('Sheet is locked for payments.');
            }

            $netPayable = round((float) ($item['net_payable'] ?? 0), 2);
            $paidAmount = round((float) ($item['paid_amount'] ?? 0), 2);
            $outstanding = max(0, round($netPayable - $paidAmount, 2));
            if ($outstanding <= 0.009) {
                throw new RuntimeException('No outstanding payroll amount.');
            }
            if ($amount > $outstanding + 0.009) {
                throw new RuntimeException('Payment exceeds outstanding payroll amount.');
            }

            $insertPay = $pdo->prepare(
                'INSERT INTO payroll_salary_payments
                  (sheet_id, salary_item_id, user_id, company_id, garage_id, payment_date, entry_type, amount, payment_mode, reference_no, notes, created_by)
                 VALUES
                  (:sheet_id, :salary_item_id, :user_id, :company_id, :garage_id, :payment_date, "PAYMENT", :amount, :payment_mode, :reference_no, :notes, :created_by)'
            );
            $insertPay->execute([
                'sheet_id' => (int) $item['sheet_id'],
                'salary_item_id' => $itemId,
                'user_id' => (int) $item['user_id'],
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'payment_date' => $paymentDate,
                'amount' => $amount,
                'payment_mode' => $paymentMode,
                'reference_no' => 'PAY-' . $itemId,
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => $_SESSION['user_id'] ?? null,
            ]);
            $paymentId = (int) $pdo->lastInsertId();

            $newPaid = round($paidAmount + $amount, 2);
            $status = $newPaid + 0.009 >= $netPayable ? 'PAID' : 'PARTIAL';

            $deductionsApplied = (int) ($item['deductions_applied'] ?? 0) === 1;
            if (!$deductionsApplied) {
                $advanceApplied = payroll_apply_advance_deduction($pdo, (int) $item['user_id'], $companyId, $garageId, (float) ($item['advance_deduction'] ?? 0));
                $loanApplied = payroll_apply_loan_deduction($pdo, (int) $item['user_id'], $companyId, $garageId, (float) ($item['loan_deduction'] ?? 0), $itemId, $_SESSION['user_id'] ?? null);
                if ($advanceApplied > 0 || $loanApplied > 0) {
                    $deductionsApplied = true;
                }
            }

            $updateItem = $pdo->prepare(
                'UPDATE payroll_salary_items
                 SET paid_amount = :paid_amount,
                     status = :status,
                     deductions_applied = :deductions_applied
                 WHERE id = :id'
            );
            $updateItem->execute([
                'paid_amount' => $newPaid,
                'status' => $status,
                'deductions_applied' => $deductionsApplied ? 1 : 0,
                'id' => $itemId,
            ]);

            $sheetId = (int) $item['sheet_id'];
            $sheetSettled = payroll_sync_sheet_lock_state($pdo, $sheetId, $_SESSION['user_id'] ?? null);
            log_audit('payroll', 'salary_payment_add', $itemId, 'Added salary payment entry', [
                'entity' => 'payroll_salary_payment',
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'after' => [
                    'payment_id' => $paymentId,
                    'sheet_id' => $sheetId,
                    'amount' => $amount,
                    'payment_date' => $paymentDate,
                    'payment_mode' => $paymentMode,
                    'sheet_auto_locked' => $sheetSettled ? 1 : 0,
                ],
            ]);
            finance_record_expense_for_salary_payment(
                $paymentId,
                $itemId,
                (int) $item['user_id'],
                $companyId,
                $garageId,
                $amount,
                $paymentDate,
                $paymentMode,
                $notes,
                false,
                $_SESSION['user_id'] ?? null
            );

            $pdo->commit();
            flash_set('payroll_success', 'Salary payment recorded.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('payroll_error', $exception->getMessage(), 'danger');
        }

        $sheetIdParam = isset($item) && isset($item['sheet_id']) ? (string) $item['sheet_id'] : '';
        redirect('modules/payroll/index.php?salary_month=' . urlencode($salaryMonth) . '&sheet=' . $sheetIdParam);
    }

    if ($action === 'reverse_salary_payment') {
        $paymentId = post_int('payment_id');
        $reverseReason = post_string('reverse_reason', 255);
        if ($paymentId <= 0 || $reverseReason === '') {
            flash_set('payroll_error', 'Payment and reversal reason are required.', 'danger');
            redirect('modules/payroll/index.php?salary_month=' . urlencode($salaryMonth));
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $paymentStmt = $pdo->prepare(
                'SELECT psp.*, psi.net_payable, pss.id AS sheet_id, pss.salary_month
                 FROM payroll_salary_payments psp
                 INNER JOIN payroll_salary_items psi ON psi.id = psp.salary_item_id
                 INNER JOIN payroll_salary_sheets pss ON pss.id = psp.sheet_id
                 WHERE psp.id = :id
                   AND psp.company_id = :company_id
                   AND psp.garage_id = :garage_id
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
                throw new RuntimeException('Salary payment not found.');
            }
            if ((string) ($payment['entry_type'] ?? '') !== 'PAYMENT') {
                throw new RuntimeException('Only payment entries can be reversed.');
            }
            if ((float) ($payment['amount'] ?? 0) <= 0) {
                throw new RuntimeException('Invalid payment amount for reversal.');
            }

            $checkStmt = $pdo->prepare(
                'SELECT id
                 FROM payroll_salary_payments
                 WHERE reversed_payment_id = :payment_id
                 LIMIT 1'
            );
            $checkStmt->execute(['payment_id' => $paymentId]);
            if ($checkStmt->fetch()) {
                throw new RuntimeException('This salary payment is already reversed.');
            }

            $paymentAmount = round((float) ($payment['amount'] ?? 0), 2);
            $reversalDate = $today;
            $insertStmt = $pdo->prepare(
                'INSERT INTO payroll_salary_payments
                  (sheet_id, salary_item_id, user_id, company_id, garage_id, payment_date, entry_type, amount, payment_mode, reference_no, notes, reversed_payment_id, created_by)
                 VALUES
                  (:sheet_id, :salary_item_id, :user_id, :company_id, :garage_id, :payment_date, "REVERSAL", :amount, "ADJUSTMENT", :reference_no, :notes, :reversed_payment_id, :created_by)'
            );
            $insertStmt->execute([
                'sheet_id' => (int) $payment['sheet_id'],
                'salary_item_id' => (int) $payment['salary_item_id'],
                'user_id' => (int) $payment['user_id'],
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'payment_date' => $reversalDate,
                'amount' => -$paymentAmount,
                'reference_no' => 'REV-' . $paymentId,
                'notes' => $reverseReason,
                'reversed_payment_id' => $paymentId,
                'created_by' => $_SESSION['user_id'] ?? null,
            ]);
            $reversalId = (int) $pdo->lastInsertId();

            $paidStmt = $pdo->prepare(
                'SELECT COALESCE(SUM(amount), 0)
                 FROM payroll_salary_payments
                 WHERE salary_item_id = :salary_item_id'
            );
            $paidStmt->execute(['salary_item_id' => (int) $payment['salary_item_id']]);
            $newPaidAmount = max(0.0, round((float) ($paidStmt->fetchColumn() ?? 0), 2));

            $netPayable = round((float) ($payment['net_payable'] ?? 0), 2);
            $itemStatus = 'PENDING';
            if ($netPayable <= 0.001 || $newPaidAmount + 0.009 >= $netPayable) {
                $itemStatus = 'PAID';
            } elseif ($newPaidAmount > 0) {
                $itemStatus = 'PARTIAL';
            }

            $updateItemStmt = $pdo->prepare(
                'UPDATE payroll_salary_items
                 SET paid_amount = :paid_amount,
                     status = :status
                 WHERE id = :id'
            );
            $updateItemStmt->execute([
                'paid_amount' => $newPaidAmount,
                'status' => $itemStatus,
                'id' => (int) $payment['salary_item_id'],
            ]);

            $sheetSettled = payroll_sync_sheet_lock_state($pdo, (int) $payment['sheet_id'], $_SESSION['user_id'] ?? null);
            log_audit('payroll', 'salary_payment_reverse', (int) $payment['salary_item_id'], 'Reversed salary payment entry', [
                'entity' => 'payroll_salary_payment',
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'after' => [
                    'payment_id' => $paymentId,
                    'reversal_id' => $reversalId,
                    'reversal_amount' => -$paymentAmount,
                    'sheet_id' => (int) $payment['sheet_id'],
                    'sheet_settled_after_reversal' => $sheetSettled ? 1 : 0,
                ],
            ]);

            finance_record_expense_for_salary_payment(
                $reversalId,
                (int) $payment['salary_item_id'],
                (int) $payment['user_id'],
                $companyId,
                $garageId,
                $paymentAmount,
                $reversalDate,
                'ADJUSTMENT',
                $reverseReason,
                true,
                $_SESSION['user_id'] ?? null
            );

            $pdo->commit();

            flash_set('payroll_success', 'Salary payment reversed.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('payroll_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/payroll/index.php?salary_month=' . urlencode($salaryMonth));
    }

    if ($action === 'lock_sheet') {
        $sheetId = post_int('sheet_id');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $sheetStmt = $pdo->prepare(
                'SELECT * FROM payroll_salary_sheets WHERE id = :id AND company_id = :company_id AND garage_id = :garage_id FOR UPDATE'
            );
            $sheetStmt->execute([
                'id' => $sheetId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ]);
            $sheet = $sheetStmt->fetch();
            if (!$sheet) {
                throw new RuntimeException('Salary sheet not found.');
            }
            if ((string) ($sheet['status'] ?? '') === 'LOCKED') {
                throw new RuntimeException('Sheet already locked.');
            }

            payroll_update_sheet_totals($pdo, $sheetId);
            $freshTotals = $pdo->prepare('SELECT total_payable, total_paid FROM payroll_salary_sheets WHERE id = :id');
            $freshTotals->execute(['id' => $sheetId]);
            $fresh = $freshTotals->fetch();
            $payable = round((float) ($fresh['total_payable'] ?? $sheet['total_payable'] ?? 0), 2);
            $paid = round((float) ($fresh['total_paid'] ?? $sheet['total_paid'] ?? 0), 2);
            if ($paid + 0.009 < $payable) {
                throw new RuntimeException('Cannot lock sheet with outstanding balance.');
            }

            payroll_sheet_set_lock_state($pdo, $sheetId, true, $_SESSION['user_id'] ?? null);
            log_audit('payroll', 'salary_sheet_lock', $sheetId, 'Locked salary sheet', [
                'entity' => 'payroll_salary_sheet',
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'after' => [
                    'salary_month' => (string) ($sheet['salary_month'] ?? ''),
                    'total_payable' => $payable,
                    'total_paid' => $paid,
                ],
            ]);

            $pdo->commit();
            flash_set('payroll_success', 'Salary sheet locked.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('payroll_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/payroll/index.php?salary_month=' . urlencode($salaryMonth));
    }
}

$staffStmt = db()->prepare(
    'SELECT u.id, u.name, u.email, r.role_name
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     INNER JOIN user_garages ug ON ug.user_id = u.id AND ug.garage_id = :garage_id
     WHERE u.company_id = :company_id
       AND u.status_code = "ACTIVE"
     ORDER BY u.name ASC'
);
$staffStmt->execute([
    'garage_id' => $garageId,
    'company_id' => $companyId,
]);
$staffList = $staffStmt->fetchAll();

$structureStmt = db()->prepare(
    'SELECT ss.*, u.name
     FROM payroll_salary_structures ss
     INNER JOIN users u ON u.id = ss.user_id
     WHERE ss.company_id = :company_id
       AND ss.garage_id = :garage_id
       AND ss.status_code <> "INACTIVE"
     ORDER BY u.name ASC'
);
$structureStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$structures = $structureStmt->fetchAll();

$advanceStmt = db()->prepare(
    'SELECT pa.*, u.name, (pa.amount - pa.applied_amount) AS pending
     FROM payroll_advances pa
     INNER JOIN users u ON u.id = pa.user_id
  WHERE pa.company_id = :company_id AND pa.garage_id = :garage_id AND pa.status <> "DELETED"
     ORDER BY pa.advance_date DESC, pa.id DESC
     LIMIT 20'
);
$advanceStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$advances = $advanceStmt->fetchAll();

$loanStmt = db()->prepare(
    'SELECT pl.*, u.name, (pl.total_amount - pl.paid_amount) AS pending
     FROM payroll_loans pl
     INNER JOIN users u ON u.id = pl.user_id
  WHERE pl.company_id = :company_id AND pl.garage_id = :garage_id AND pl.status <> "DELETED"
     ORDER BY pl.loan_date DESC, pl.id DESC
     LIMIT 20'
);
$loanStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$loans = $loanStmt->fetchAll();

$loanPaymentStmt = db()->prepare(
    'SELECT lp.*, u.name
     FROM payroll_loan_payments lp
     INNER JOIN payroll_loans pl ON pl.id = lp.loan_id
     INNER JOIN users u ON u.id = pl.user_id
     WHERE lp.company_id = :company_id
       AND lp.garage_id = :garage_id
     ORDER BY lp.payment_date DESC, lp.id DESC
     LIMIT 40'
);
$loanPaymentStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$loanPayments = $loanPaymentStmt->fetchAll();
$reversedLoanPaymentIds = [];
foreach ($loanPayments as $loanPaymentRow) {
    $reversedId = (int) ($loanPaymentRow['reversed_payment_id'] ?? 0);
    if ($reversedId > 0) {
        $reversedLoanPaymentIds[$reversedId] = true;
    }
}

$sheetStmt = db()->prepare(
    'SELECT * FROM payroll_salary_sheets WHERE company_id = :company_id AND garage_id = :garage_id AND salary_month = :salary_month LIMIT 1'
);
$sheetStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
    'salary_month' => $salaryMonth,
]);
$currentSheet = $sheetStmt->fetch();

$salaryItems = [];
$salaryPayments = [];
if ($currentSheet) {
    $itemConditions = ['psi.sheet_id = :sheet_id'];
    $itemParams = ['sheet_id' => (int) $currentSheet['id']];
    if ($itemStatusFilter !== '') {
        $itemConditions[] = 'psi.status = :status_filter';
        $itemParams['status_filter'] = $itemStatusFilter;
    }
    if ($staffSearch !== '') {
        $itemConditions[] = 'u.name LIKE :staff_search';
        $itemParams['staff_search'] = '%' . $staffSearch . '%';
    }

    $itemsStmt = db()->prepare(
        'SELECT psi.*, u.name
         FROM payroll_salary_items psi
         INNER JOIN users u ON u.id = psi.user_id
         WHERE ' . implode(' AND ', $itemConditions) . '
         ORDER BY u.name ASC'
    );
    $itemsStmt->execute($itemParams);
    $salaryItems = $itemsStmt->fetchAll();

    $paymentsStmt = db()->prepare(
        'SELECT psp.*, u.name
         FROM payroll_salary_payments psp
         INNER JOIN users u ON u.id = psp.user_id
         WHERE psp.sheet_id = :sheet_id
         ORDER BY psp.payment_date DESC, psp.id DESC
         LIMIT 30'
    );
    $paymentsStmt->execute(['sheet_id' => (int) $currentSheet['id']]);
    $salaryPayments = $paymentsStmt->fetchAll();
}

$reversedSalaryPaymentIds = [];
foreach ($salaryPayments as $salaryPaymentRow) {
    $reversedId = (int) ($salaryPaymentRow['reversed_payment_id'] ?? 0);
    if ($reversedId > 0) {
        $reversedSalaryPaymentIds[$reversedId] = true;
    }
}

$visiblePendingCount = 0;
$visiblePartialCount = 0;
$visiblePaidCount = 0;
$visibleOutstanding = 0.0;
foreach ($salaryItems as $itemRow) {
    $status = strtoupper((string) ($itemRow['status'] ?? 'PENDING'));
    $netPayable = round((float) ($itemRow['net_payable'] ?? 0), 2);
    $paidAmount = round((float) ($itemRow['paid_amount'] ?? 0), 2);
    $outstanding = max(0.0, round($netPayable - $paidAmount, 2));
    $visibleOutstanding += $outstanding;
    if ($status === 'PAID') {
        $visiblePaidCount++;
    } elseif ($status === 'PARTIAL') {
        $visiblePartialCount++;
    } else {
        $visiblePendingCount++;
    }
}
$visibleOutstanding = round($visibleOutstanding, 2);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Payroll Module</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Payroll</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="row g-3 mb-3">
        <div class="col-md-3">
          <div class="small-box text-bg-primary">
            <div class="inner"><h4><?= number_format((int) count($salaryItems)); ?></h4><p>Visible Salary Rows</p></div>
            <span class="small-box-icon"><i class="bi bi-people"></i></span>
          </div>
        </div>
        <div class="col-md-3">
          <div class="small-box text-bg-warning">
            <div class="inner"><h4><?= e(format_currency($visibleOutstanding)); ?></h4><p>Visible Outstanding</p></div>
            <span class="small-box-icon"><i class="bi bi-hourglass-split"></i></span>
          </div>
        </div>
        <div class="col-md-3">
          <div class="small-box text-bg-success">
            <div class="inner"><h4><?= number_format($visiblePaidCount); ?></h4><p>Paid Rows</p></div>
            <span class="small-box-icon"><i class="bi bi-check2-circle"></i></span>
          </div>
        </div>
        <div class="col-md-3">
          <div class="small-box text-bg-secondary">
            <div class="inner"><h4><?= number_format($visiblePendingCount + $visiblePartialCount); ?></h4><p>Pending + Partial</p></div>
            <span class="small-box-icon"><i class="bi bi-list-check"></i></span>
          </div>
        </div>
      </div>

      <div class="card card-primary mb-3">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
          <h3 class="card-title mb-0">Filters</h3>
          <div class="d-flex flex-wrap gap-2">
            <?php if ($canManage): ?>
              <a href="<?= e(url('modules/payroll/master_forms.php')); ?>" class="btn btn-sm btn-light"><i class="bi bi-sliders2 me-1"></i>Payroll Setup</a>
            <?php endif; ?>
            <a href="<?= e(url('modules/reports/payroll.php?salary_month=' . urlencode($salaryMonth))); ?>" class="btn btn-sm btn-outline-light"><i class="bi bi-graph-up me-1"></i>Payroll Reports</a>
          </div>
        </div>
        <div class="card-body">
          <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
              <label class="form-label">Salary Month</label>
              <input type="month" name="salary_month" value="<?= e($salaryMonth) ?>" class="form-control" required />
            </div>
            <div class="col-md-3">
              <label class="form-label">Row Status</label>
              <select name="item_status" class="form-select">
                <option value="" <?= $itemStatusFilter === '' ? 'selected' : ''; ?>>All</option>
                <option value="PENDING" <?= $itemStatusFilter === 'PENDING' ? 'selected' : ''; ?>>Pending</option>
                <option value="PARTIAL" <?= $itemStatusFilter === 'PARTIAL' ? 'selected' : ''; ?>>Partial</option>
                <option value="PAID" <?= $itemStatusFilter === 'PAID' ? 'selected' : ''; ?>>Paid</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Staff Search</label>
              <input type="text" name="staff_q" value="<?= e($staffSearch) ?>" class="form-control" placeholder="Filter salary rows by staff name" />
            </div>
            <div class="col-md-2 d-flex gap-2">
              <button class="btn btn-primary" type="submit">Apply</button>
              <a href="<?= e(url('modules/payroll/index.php?salary_month=' . urlencode($salaryMonth))); ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
          </form>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-xl-8">
          <div class="card card-outline card-info">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
              <h3 class="card-title mb-0">Salary Sheet Ledger (<?= e($salaryMonth) ?>)</h3>
              <?php if ($canManage): ?>
                <form method="post" class="ajax-form d-flex gap-2 align-items-center">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="generate_sheet" />
                  <input type="month" name="salary_month" value="<?= e($salaryMonth) ?>" class="form-control form-control-sm" style="max-width: 160px;" />
                  <button class="btn btn-sm btn-info" type="submit">Generate / Sync</button>
                </form>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <?php if ($currentSheet): ?>
                <?php $sheetOutstanding = max(0.0, round((float) ($currentSheet['total_payable'] ?? 0) - (float) ($currentSheet['total_paid'] ?? 0), 2)); ?>
                <div class="row g-2 mb-3">
                  <div class="col-md-3"><strong>Status:</strong> <span class="badge text-bg-<?= e(status_badge_class((string) ($currentSheet['status'] ?? 'OPEN'))) ?>"><?= e((string) ($currentSheet['status'] ?? 'OPEN')) ?></span></div>
                  <div class="col-md-3"><strong>Total Gross:</strong> <?= format_currency((float) ($currentSheet['total_gross'] ?? 0)) ?></div>
                  <div class="col-md-3"><strong>Total Payable:</strong> <?= format_currency((float) ($currentSheet['total_payable'] ?? 0)) ?></div>
                  <div class="col-md-3"><strong>Outstanding:</strong> <?= format_currency($sheetOutstanding) ?></div>
                </div>

                <?php if ($canManage && (string) ($currentSheet['status'] ?? '') !== 'LOCKED'): ?>
                  <form method="post" class="ajax-form mb-3">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="_action" value="lock_sheet" />
                    <input type="hidden" name="sheet_id" value="<?= (int) ($currentSheet['id'] ?? 0) ?>" />
                    <button class="btn btn-sm btn-danger" type="submit">Lock Sheet</button>
                  </form>
                <?php endif; ?>

                <div class="table-responsive">
                  <table class="table table-sm table-striped align-middle mb-0">
                    <thead>
                      <tr>
                        <th>Staff</th>
                        <th>Type</th>
                        <th>Base</th>
                        <th>Commission</th>
                        <th>Overtime</th>
                        <th>Deductions (Adv / Loan / Manual)</th>
                        <th>Net</th>
                        <th>Paid</th>
                        <th>Status</th>
                        <th style="min-width: 180px;">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($salaryItems)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-3">No salary rows found for selected filters.</td></tr>
                      <?php else: ?>
                        <?php foreach ($salaryItems as $item): ?>
                          <?php
                            $itemNetPayable = round((float) ($item['net_payable'] ?? 0), 2);
                            $itemPaidAmount = round((float) ($item['paid_amount'] ?? 0), 2);
                            $itemOutstanding = max(0.0, round($itemNetPayable - $itemPaidAmount, 2));
                            $sheetLocked = (string) ($currentSheet['status'] ?? '') === 'LOCKED';
                            $itemFullyPaid = payroll_item_is_fully_paid($item);
                            $itemHasPartialPayment = $itemPaidAmount > 0.009;
                            $canEditSalaryRow = $canManage && !$sheetLocked && !$itemFullyPaid && !$itemHasPartialPayment;
                            $canRecordSalaryPayment = $canManage && !$sheetLocked && $itemOutstanding > 0.009;
                            $canReverseSalaryRow = $canManage && !$sheetLocked && !$itemFullyPaid && !$itemHasPartialPayment;
                            $salaryActionHint = '';
                            if ($canManage) {
                                if ($sheetLocked) {
                                    $salaryActionHint = 'Sheet is locked after settlement.';
                                } elseif ($itemFullyPaid) {
                                    $salaryActionHint = 'Fully settled row.';
                                } elseif ($itemHasPartialPayment) {
                                    $salaryActionHint = 'Reverse salary payments before edit/reversal.';
                                } elseif ($itemOutstanding <= 0.009) {
                                    $salaryActionHint = 'No pending amount for payment.';
                                }
                            }
                          ?>
                          <tr>
                            <td><?= e((string) ($item['name'] ?? '')) ?></td>
                            <td><?= e((string) ($item['salary_type'] ?? '')) ?></td>
                            <td><?= format_currency((float) ($item['base_amount'] ?? 0)) ?></td>
                            <td><?= format_currency((float) ($item['commission_amount'] ?? 0)) ?></td>
                            <td><?= format_currency((float) ($item['overtime_amount'] ?? 0)) ?></td>
                            <td><?= format_currency((float) ($item['advance_deduction'] ?? 0)) ?> / <?= format_currency((float) ($item['loan_deduction'] ?? 0)) ?> / <?= format_currency((float) ($item['manual_deduction'] ?? 0)) ?></td>
                            <td><?= format_currency($itemNetPayable) ?></td>
                            <td><?= format_currency($itemPaidAmount) ?></td>
                            <td><span class="badge text-bg-<?= e(status_badge_class((string) ($item['status'] ?? 'PENDING'))) ?>"><?= e((string) ($item['status'] ?? 'PENDING')) ?></span></td>
                            <td class="text-nowrap">
                              <?php if ($canManage): ?>
                                <div class="dropdown">
                                  <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Action</button>
                                  <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                      <button
                                        type="button"
                                        class="dropdown-item js-salary-edit-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#salaryItemEditModal"
                                        data-item-id="<?= (int) ($item['id'] ?? 0) ?>"
                                        data-staff-name="<?= e((string) ($item['name'] ?? '')) ?>"
                                        data-base-amount="<?= e((string) ($item['base_amount'] ?? '0')) ?>"
                                        data-commission-base="<?= e((string) ($item['commission_base'] ?? '0')) ?>"
                                        data-commission-rate="<?= e((string) ($item['commission_rate'] ?? '0')) ?>"
                                        data-overtime-hours="<?= e((string) ($item['overtime_hours'] ?? '0')) ?>"
                                        data-overtime-rate="<?= e((string) ($item['overtime_rate'] ?? '0')) ?>"
                                        data-advance-deduction="<?= e((string) ($item['advance_deduction'] ?? '0')) ?>"
                                        data-loan-deduction="<?= e((string) ($item['loan_deduction'] ?? '0')) ?>"
                                        data-manual-deduction="<?= e((string) ($item['manual_deduction'] ?? '0')) ?>"
                                        <?= $canEditSalaryRow ? '' : 'disabled'; ?>
                                      >Edit Salary Row</button>
                                    </li>
                                    <li>
                                      <button
                                        type="button"
                                        class="dropdown-item js-salary-pay-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#salaryPaymentAddModal"
                                        data-item-id="<?= (int) ($item['id'] ?? 0) ?>"
                                        data-staff-name="<?= e((string) ($item['name'] ?? '')) ?>"
                                        data-outstanding="<?= e((string) $itemOutstanding) ?>"
                                        data-outstanding-label="<?= e(format_currency($itemOutstanding)) ?>"
                                        <?= $canRecordSalaryPayment ? '' : 'disabled'; ?>
                                      >Record Payment</button>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                      <button
                                        type="button"
                                        class="dropdown-item text-danger js-salary-entry-reverse-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#salaryEntryReverseModal"
                                        data-item-id="<?= (int) ($item['id'] ?? 0) ?>"
                                        data-item-label="<?= e((string) ($item['name'] ?? '') . ' | ' . format_currency($itemNetPayable)) ?>"
                                        <?= $canReverseSalaryRow ? '' : 'disabled'; ?>
                                      >Reverse Salary Entry</button>
                                    </li>
                                  </ul>
                                </div>
                                <?php if ($salaryActionHint !== ''): ?>
                                  <div><small class="text-muted"><?= e($salaryActionHint) ?></small></div>
                                <?php endif; ?>
                              <?php else: ?>
                                <span class="text-muted small">-</span>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <p class="text-muted mb-0">No salary sheet exists for this month yet. Use Generate / Sync to create it.</p>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="col-xl-4">
          <div class="card card-outline card-secondary mb-3">
            <div class="card-header"><h3 class="card-title mb-0">Financial Safety Rules</h3></div>
            <div class="card-body">
              <ul class="mb-0 ps-3">
                <li>Salary row edit is blocked after partial/full payment.</li>
                <li>Reverse payment first, then edit/reverse salary entry.</li>
                <li>Direct delete is blocked for salary and payment records.</li>
                <li>All payroll changes are audit logged.</li>
              </ul>
            </div>
          </div>

          <div class="card card-outline card-primary mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Salary Structure Snapshot</h3>
              <?php if ($canManage): ?><a href="<?= e(url('modules/payroll/master_forms.php#salary-structure')); ?>" class="btn btn-sm btn-outline-primary">Manage</a><?php endif; ?>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Staff</th><th>Type</th><th>Base</th></tr></thead>
                <tbody>
                  <?php if (empty($structures)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-3">No structures available.</td></tr>
                  <?php else: ?>
                    <?php foreach (array_slice($structures, 0, 8) as $structure): ?>
                      <tr>
                        <td><?= e((string) ($structure['name'] ?? '')) ?></td>
                        <td><?= e((string) ($structure['salary_type'] ?? '')) ?></td>
                        <td><?= format_currency((float) ($structure['base_amount'] ?? 0)) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="card card-outline card-success mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Advances Snapshot</h3>
              <?php if ($canManage): ?><a href="<?= e(url('modules/payroll/master_forms.php#advances')); ?>" class="btn btn-sm btn-outline-success">Manage</a><?php endif; ?>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Staff</th><th>Pending</th><th>Status</th></tr></thead>
                <tbody>
                  <?php if (empty($advances)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-3">No advances found.</td></tr>
                  <?php else: ?>
                    <?php foreach (array_slice($advances, 0, 8) as $advance): ?>
                      <tr>
                        <td><?= e((string) ($advance['name'] ?? '')) ?></td>
                        <td><?= format_currency((float) ($advance['pending'] ?? 0)) ?></td>
                        <td><span class="badge text-bg-<?= e(status_badge_class((string) ($advance['status'] ?? 'OPEN'))) ?>"><?= e((string) ($advance['status'] ?? 'OPEN')) ?></span></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="card card-outline card-warning">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Loans Snapshot</h3>
              <?php if ($canManage): ?><a href="<?= e(url('modules/payroll/master_forms.php#loans')); ?>" class="btn btn-sm btn-outline-warning">Manage</a><?php endif; ?>
            </div>
            <div class="card-body p-0 table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Staff</th><th>Pending</th><th>Status</th></tr></thead>
                <tbody>
                  <?php if (empty($loans)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-3">No loans found.</td></tr>
                  <?php else: ?>
                    <?php foreach (array_slice($loans, 0, 8) as $loan): ?>
                      <tr>
                        <td><?= e((string) ($loan['name'] ?? '')) ?></td>
                        <td><?= format_currency((float) ($loan['pending'] ?? 0)) ?></td>
                        <td><span class="badge text-bg-<?= e(status_badge_class((string) ($loan['status'] ?? 'ACTIVE'))) ?>"><?= e((string) ($loan['status'] ?? 'ACTIVE')) ?></span></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <?php if ($currentSheet): ?>
        <div class="card card-outline card-secondary mt-3">
          <div class="card-header"><h3 class="card-title mb-0">Salary Payment History</h3></div>
          <div class="card-body table-responsive p-0">
            <table class="table table-sm table-striped mb-0">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Staff</th>
                  <th>Type</th>
                  <th>Amount</th>
                  <th>Mode</th>
                  <th>Notes</th>
                  <th style="min-width: 140px;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($salaryPayments)): ?>
                  <tr><td colspan="7" class="text-center text-muted py-3">No salary payment history.</td></tr>
                <?php else: ?>
                  <?php foreach ($salaryPayments as $payment): ?>
                    <?php
                      $entryType = (string) ($payment['entry_type'] ?? '');
                      $paymentId = (int) ($payment['id'] ?? 0);
                      $isReversed = isset($reversedSalaryPaymentIds[$paymentId]);
                      $canReverseSalaryPayment = $canManage && $entryType === 'PAYMENT' && !$isReversed;
                    ?>
                    <tr>
                      <td><?= e((string) ($payment['payment_date'] ?? '')) ?></td>
                      <td><?= e((string) ($payment['name'] ?? '')) ?></td>
                      <td><?= e($entryType) ?></td>
                      <td><?= format_currency((float) ($payment['amount'] ?? 0)) ?></td>
                      <td><?= e((string) ($payment['payment_mode'] ?? '')) ?></td>
                      <td><?= e((string) ($payment['notes'] ?? '')) ?></td>
                      <td class="text-nowrap">
                        <?php if ($canManage): ?>
                          <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Action</button>
                            <ul class="dropdown-menu dropdown-menu-end">
                              <li>
                                <button
                                  type="button"
                                  class="dropdown-item text-danger js-salary-payment-reverse-btn"
                                  data-bs-toggle="modal"
                                  data-bs-target="#salaryPaymentReverseModal"
                                  data-payment-id="<?= $paymentId ?>"
                                  data-payment-label="<?= e((string) ($payment['payment_date'] ?? '') . ' | ' . (string) ($payment['name'] ?? '') . ' | ' . format_currency((float) ($payment['amount'] ?? 0))) ?>"
                                  <?= $canReverseSalaryPayment ? '' : 'disabled'; ?>
                                >Reverse Payment</button>
                              </li>
                            </ul>
                          </div>
                          <?php if (!$canReverseSalaryPayment): ?>
                            <div><small class="text-muted"><?= $isReversed ? 'Already reversed.' : 'Only manual PAYMENT rows can be reversed.'; ?></small></div>
                          <?php endif; ?>
                        <?php else: ?>
                          <span class="text-muted small">-</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<?php if ($canManage): ?>
  <div class="modal fade" id="salaryItemEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Edit Salary Row</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post" class="ajax-form">
          <div class="modal-body">
            <?= csrf_field(); ?>
            <input type="hidden" name="_action" value="update_item" />
            <input type="hidden" name="item_id" id="salary-item-edit-id" />
            <div class="mb-2">
              <label class="form-label">Staff</label>
              <input type="text" class="form-control" id="salary-item-edit-staff" readonly />
            </div>
            <div class="row g-2">
              <div class="col-md-4">
                <label class="form-label">Base Amount</label>
                <input type="number" step="0.01" min="0" name="base_amount" id="salary-item-edit-base" class="form-control" required />
              </div>
              <div class="col-md-4">
                <label class="form-label">Commission Base</label>
                <input type="number" step="0.01" min="0" name="commission_base" id="salary-item-edit-commission-base" class="form-control" />
              </div>
              <div class="col-md-4">
                <label class="form-label">Commission %</label>
                <input type="number" step="0.001" min="0" name="commission_rate" id="salary-item-edit-commission-rate" class="form-control" />
              </div>
            </div>
            <div class="row g-2 mt-1">
              <div class="col-md-6">
                <label class="form-label">Overtime Hours</label>
                <input type="number" step="0.01" min="0" name="overtime_hours" id="salary-item-edit-overtime-hours" class="form-control" />
              </div>
              <div class="col-md-6">
                <label class="form-label">Overtime Rate</label>
                <input type="number" step="0.01" min="0" name="overtime_rate" id="salary-item-edit-overtime-rate" class="form-control" />
              </div>
            </div>
            <div class="row g-2 mt-1">
              <div class="col-md-4">
                <label class="form-label">Advance Deduction</label>
                <input type="number" step="0.01" min="0" name="advance_deduction" id="salary-item-edit-advance" class="form-control" />
              </div>
              <div class="col-md-4">
                <label class="form-label">Loan Deduction</label>
                <input type="number" step="0.01" min="0" name="loan_deduction" id="salary-item-edit-loan" class="form-control" />
              </div>
              <div class="col-md-4">
                <label class="form-label">Manual Deduction</label>
                <input type="number" step="0.01" min="0" name="manual_deduction" id="salary-item-edit-manual" class="form-control" />
              </div>
            </div>
            <small class="text-muted">Edit is blocked when partial/full payment exists. Reverse payments first if reconciliation already started.</small>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            <button type="submit" class="btn btn-primary">Save Salary Row</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="salaryPaymentAddModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Record Salary Payment</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post" class="ajax-form">
          <div class="modal-body">
            <?= csrf_field(); ?>
            <input type="hidden" name="_action" value="add_salary_payment" />
            <input type="hidden" name="item_id" id="salary-pay-item-id" />
            <div class="mb-2">
              <label class="form-label">Staff</label>
              <input type="text" id="salary-pay-staff" class="form-control" readonly />
            </div>
            <div class="mb-2">
              <label class="form-label">Outstanding</label>
              <input type="text" id="salary-pay-outstanding-label" class="form-control" readonly />
            </div>
            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label">Amount</label>
                <input type="number" step="0.01" min="0.01" name="amount" id="salary-pay-amount" class="form-control" required />
              </div>
              <div class="col-md-6">
                <label class="form-label">Date</label>
                <input type="date" name="payment_date" value="<?= e($today) ?>" class="form-control" required />
              </div>
            </div>
            <div class="row g-2 mt-1">
              <div class="col-md-6">
                <label class="form-label">Payment Mode</label>
                <select name="payment_mode" class="form-select">
                  <?php foreach (finance_payment_modes() as $mode): ?>
                    <option value="<?= e($mode) ?>"><?= e($mode) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" class="form-control" maxlength="255" />
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            <button type="submit" class="btn btn-success">Record Payment</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="salaryEntryReverseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Reverse Salary Entry</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post" class="ajax-form">
          <div class="modal-body">
            <?= csrf_field(); ?>
            <input type="hidden" name="_action" value="reverse_salary_entry" />
            <input type="hidden" name="item_id" id="salary-entry-reverse-id" />
            <div class="mb-2">
              <label class="form-label">Salary Row</label>
              <input type="text" id="salary-entry-reverse-label" class="form-control" readonly />
            </div>
            <div class="mb-2">
              <label class="form-label">Reversal Reason</label>
              <input type="text" name="reverse_reason" class="form-control" maxlength="255" required />
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            <button type="submit" class="btn btn-danger">Confirm Reversal</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="salaryPaymentReverseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Reverse Salary Payment</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post" class="ajax-form">
          <div class="modal-body">
            <?= csrf_field(); ?>
            <input type="hidden" name="_action" value="reverse_salary_payment" />
            <input type="hidden" name="payment_id" id="salary-payment-reverse-id" />
            <div class="mb-2">
              <label class="form-label">Payment Entry</label>
              <input type="text" id="salary-payment-reverse-label" class="form-control" readonly />
            </div>
            <div class="mb-2">
              <label class="form-label">Reversal Reason</label>
              <input type="text" name="reverse_reason" class="form-control" maxlength="255" required />
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            <button type="submit" class="btn btn-danger">Reverse Payment</button>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<script>
  (function () {
    function setValue(id, value) {
      var field = document.getElementById(id);
      if (field) {
        field.value = value || '';
      }
    }

    document.addEventListener('click', function (event) {
      var editTrigger = event.target.closest('.js-salary-edit-btn');
      if (editTrigger && !editTrigger.disabled) {
        setValue('salary-item-edit-id', editTrigger.getAttribute('data-item-id'));
        setValue('salary-item-edit-staff', editTrigger.getAttribute('data-staff-name'));
        setValue('salary-item-edit-base', editTrigger.getAttribute('data-base-amount'));
        setValue('salary-item-edit-commission-base', editTrigger.getAttribute('data-commission-base'));
        setValue('salary-item-edit-commission-rate', editTrigger.getAttribute('data-commission-rate'));
        setValue('salary-item-edit-overtime-hours', editTrigger.getAttribute('data-overtime-hours'));
        setValue('salary-item-edit-overtime-rate', editTrigger.getAttribute('data-overtime-rate'));
        setValue('salary-item-edit-advance', editTrigger.getAttribute('data-advance-deduction'));
        setValue('salary-item-edit-loan', editTrigger.getAttribute('data-loan-deduction'));
        setValue('salary-item-edit-manual', editTrigger.getAttribute('data-manual-deduction'));
      }

      var payTrigger = event.target.closest('.js-salary-pay-btn');
      if (payTrigger && !payTrigger.disabled) {
        var outstanding = payTrigger.getAttribute('data-outstanding') || '';
        setValue('salary-pay-item-id', payTrigger.getAttribute('data-item-id'));
        setValue('salary-pay-staff', payTrigger.getAttribute('data-staff-name'));
        setValue('salary-pay-outstanding-label', payTrigger.getAttribute('data-outstanding-label'));
        setValue('salary-pay-amount', outstanding);
        var amountField = document.getElementById('salary-pay-amount');
        if (amountField) {
          if (outstanding !== '') {
            amountField.setAttribute('max', outstanding);
          } else {
            amountField.removeAttribute('max');
          }
        }
      }

      var entryReverseTrigger = event.target.closest('.js-salary-entry-reverse-btn');
      if (entryReverseTrigger && !entryReverseTrigger.disabled) {
        setValue('salary-entry-reverse-id', entryReverseTrigger.getAttribute('data-item-id'));
        setValue('salary-entry-reverse-label', entryReverseTrigger.getAttribute('data-item-label'));
      }

      var paymentReverseTrigger = event.target.closest('.js-salary-payment-reverse-btn');
      if (paymentReverseTrigger && !paymentReverseTrigger.disabled) {
        setValue('salary-payment-reverse-id', paymentReverseTrigger.getAttribute('data-payment-id'));
        setValue('salary-payment-reverse-label', paymentReverseTrigger.getAttribute('data-payment-label'));
      }
    });
  })();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

