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

$salaryMonth = trim((string) ($_GET['salary_month'] ?? date('Y-m')));
if (!payroll_valid_month($salaryMonth)) {
    $salaryMonth = date('Y-m');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!$canManage) {
        flash_set('payroll_error', 'You do not have permission to modify payroll.', 'danger');
        redirect('modules/payroll/index.php');
    }

    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'save_structure') {
        $userId = post_int('user_id');
        $salaryType = strtoupper(trim((string) ($_POST['salary_type'] ?? 'MONTHLY')));
        $baseAmount = payroll_decimal($_POST['base_amount'] ?? 0);
        $commissionRate = round((float) ($_POST['commission_rate'] ?? 0), 3);
        $overtimeRate = payroll_decimal($_POST['overtime_rate'] ?? 0);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($userId <= 0 || $baseAmount < 0) {
            flash_set('payroll_error', 'Select staff and enter base amount.', 'danger');
            redirect('modules/payroll/index.php');
        }
        if (!in_array($salaryType, ['MONTHLY', 'PER_DAY', 'PER_JOB'], true)) {
            $salaryType = 'MONTHLY';
        }

        $pdo = db();
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
            flash_set('payroll_success', 'Salary structure saved.', 'success');
        }

        redirect('modules/payroll/index.php');
    }

  if ($action === 'delete_structure') {
    $structureId = post_int('structure_id');
    if ($structureId <= 0) {
      flash_set('payroll_error', 'Invalid salary structure.', 'danger');
      redirect('modules/payroll/index.php');
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

    flash_set('payroll_success', 'Salary structure removed.', 'success');
    redirect('modules/payroll/index.php');
  }

    if ($action === 'record_advance') {
        $userId = post_int('user_id');
        $amount = payroll_decimal($_POST['amount'] ?? 0);
        $advanceDate = trim((string) ($_POST['advance_date'] ?? $today));
        $notes = post_string('notes', 255);

        if ($userId <= 0 || $amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $advanceDate)) {
            flash_set('payroll_error', 'Valid staff, amount, and date are required for advance.', 'danger');
            redirect('modules/payroll/index.php');
        }

        $insertStmt = db()->prepare(
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

        flash_set('payroll_success', 'Advance recorded.', 'success');
        redirect('modules/payroll/index.php');
    }

      if ($action === 'update_advance') {
        $advanceId = post_int('advance_id');
        $amount = payroll_decimal($_POST['amount'] ?? 0);
        $advanceDate = trim((string) ($_POST['advance_date'] ?? $today));
        $notes = post_string('notes', 255);

        if ($advanceId <= 0 || $amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $advanceDate)) {
          flash_set('payroll_error', 'Valid advance, amount, and date are required.', 'danger');
          redirect('modules/payroll/index.php');
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

          $pdo->commit();
          flash_set('payroll_success', 'Advance updated.', 'success');
        } catch (Throwable $exception) {
          $pdo->rollBack();
          flash_set('payroll_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/payroll/index.php');
      }

      if ($action === 'delete_advance') {
        $advanceId = post_int('advance_id');
        if ($advanceId <= 0) {
          flash_set('payroll_error', 'Invalid advance selected.', 'danger');
          redirect('modules/payroll/index.php');
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
          $pdo->commit();
          flash_set('payroll_success', 'Advance deleted.', 'success');
        } catch (Throwable $exception) {
          $pdo->rollBack();
          flash_set('payroll_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/payroll/index.php');
      }

    if ($action === 'record_loan') {
        $userId = post_int('user_id');
        $loanDate = trim((string) ($_POST['loan_date'] ?? $today));
        $totalAmount = payroll_decimal($_POST['total_amount'] ?? 0);
        $emiAmount = payroll_decimal($_POST['emi_amount'] ?? 0);
        $notes = post_string('notes', 255);

        if ($userId <= 0 || $totalAmount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $loanDate)) {
            flash_set('payroll_error', 'Valid staff, date, and loan amount are required.', 'danger');
            redirect('modules/payroll/index.php');
        }

        $insertStmt = db()->prepare(
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

        flash_set('payroll_success', 'Loan recorded for staff.', 'success');
        redirect('modules/payroll/index.php');
    }

      if ($action === 'update_loan') {
        $loanId = post_int('loan_id');
        $loanDate = trim((string) ($_POST['loan_date'] ?? $today));
        $totalAmount = payroll_decimal($_POST['total_amount'] ?? 0);
        $emiAmount = payroll_decimal($_POST['emi_amount'] ?? 0);
        $notes = post_string('notes', 255);

        if ($loanId <= 0 || $totalAmount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $loanDate)) {
          flash_set('payroll_error', 'Valid loan, date, and amount are required.', 'danger');
          redirect('modules/payroll/index.php');
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

          $pdo->commit();
          flash_set('payroll_success', 'Loan updated.', 'success');
        } catch (Throwable $exception) {
          $pdo->rollBack();
          flash_set('payroll_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/payroll/index.php');
      }

      if ($action === 'delete_loan') {
        $loanId = post_int('loan_id');
        if ($loanId <= 0) {
          flash_set('payroll_error', 'Invalid loan selected.', 'danger');
          redirect('modules/payroll/index.php');
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
          $pdo->commit();
          flash_set('payroll_success', 'Loan deleted.', 'success');
        } catch (Throwable $exception) {
          $pdo->rollBack();
          flash_set('payroll_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/payroll/index.php');
      }

    if ($action === 'loan_manual_payment') {
        $loanId = post_int('loan_id');
        $amount = payroll_decimal($_POST['amount'] ?? 0);
        $paymentDate = trim((string) ($_POST['payment_date'] ?? $today));
        $notes = post_string('notes', 255);
        if ($loanId <= 0 || $amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
            flash_set('payroll_error', 'Valid loan, amount, and date are required.', 'danger');
            redirect('modules/payroll/index.php');
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

            $pdo->commit();
            flash_set('payroll_success', 'Loan payment captured.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('payroll_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/payroll/index.php');
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

            payroll_update_sheet_totals($pdo, $sheetId);
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
            'SELECT psi.id, psi.sheet_id, pss.status
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
        flash_set('payroll_success', 'Salary row updated.', 'success');
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

            payroll_update_sheet_totals($pdo, (int) $item['sheet_id']);
            $pdo->commit();

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

            flash_set('payroll_success', 'Salary payment recorded.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('payroll_error', $exception->getMessage(), 'danger');
        }

        $sheetIdParam = isset($item) && isset($item['sheet_id']) ? (string) $item['sheet_id'] : '';
        redirect('modules/payroll/index.php?salary_month=' . urlencode($salaryMonth) . '&sheet=' . $sheetIdParam);
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

            $updateStmt = $pdo->prepare(
                'UPDATE payroll_salary_sheets
                 SET status = "LOCKED",
                     locked_at = :locked_at,
                     locked_by = :locked_by
                 WHERE id = :id'
            );
            $updateStmt->execute([
                'locked_at' => date('Y-m-d H:i:s'),
                'locked_by' => $_SESSION['user_id'] ?? null,
                'id' => $sheetId,
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
    $itemsStmt = db()->prepare(
        'SELECT psi.*, u.name
         FROM payroll_salary_items psi
         INNER JOIN users u ON u.id = psi.user_id
         WHERE psi.sheet_id = :sheet_id
         ORDER BY u.name ASC'
    );
    $itemsStmt->execute(['sheet_id' => (int) $currentSheet['id']]);
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

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row align-items-center">
        <div class="col-sm-6"><h3 class="mb-0">Payroll & Salary</h3></div>
        <div class="col-sm-6 d-flex justify-content-end">
          <form class="d-flex gap-2 align-items-center" method="get" action="">
            <label class="form-label form-label-sm mb-0">Month</label>
            <input type="month" name="salary_month" value="<?= e($salaryMonth) ?>" class="form-control form-control-sm" style="max-width: 160px;" />
            <button class="btn btn-sm btn-primary" type="submit">Load</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="row g-3">
        <div class="col-xl-7">
          <div class="card card-outline card-primary h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Salary Structure</h3>
            </div>
            <div class="card-body">
              <?php if ($canManage): ?>
              <form method="post" class="ajax-form mb-3">
                <?= csrf_field(); ?>
                <input type="hidden" name="_action" value="save_structure" />
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label">Staff</label>
                    <select name="user_id" class="form-select form-select-sm" required>
                      <option value="">Select Staff</option>
                      <?php foreach ($staffList as $staff): ?>
                        <option value="<?= (int) $staff['id'] ?>"><?= e($staff['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Salary Type</label>
                    <select name="salary_type" class="form-select form-select-sm">
                      <option value="MONTHLY">Monthly</option>
                      <option value="PER_DAY">Per Day</option>
                      <option value="PER_JOB">Per Job</option>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Base Amount</label>
                    <input type="number" step="0.01" name="base_amount" class="form-control form-control-sm" required />
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Commission %</label>
                    <input type="number" step="0.001" name="commission_rate" class="form-control form-control-sm" />
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Overtime Rate</label>
                    <input type="number" step="0.01" name="overtime_rate" class="form-control form-control-sm" />
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status_code" class="form-select form-select-sm">
                      <option value="ACTIVE">Active</option>
                      <option value="INACTIVE">Inactive</option>
                    </select>
                  </div>
                  <div class="col-md-3 d-flex align-items-end">
                    <button class="btn btn-sm btn-primary w-100" type="submit">Save Structure</button>
                  </div>
                </div>
              </form>
              <?php endif; ?>

              <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                  <thead>
                    <tr>
                      <th>Staff</th>
                      <th>Type</th>
                      <th>Base</th>
                      <th>Commission %</th>
                      <th>Overtime</th>
                      <th>Status</th>
                      <th style="min-width: 240px;">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($structures as $structure): ?>
                      <tr>
                        <td><?= e($structure['name']) ?></td>
                        <td><?= e($structure['salary_type']) ?></td>
                        <td><?= format_currency((float) $structure['base_amount']) ?></td>
                        <td><?= e($structure['commission_rate']) ?></td>
                        <td><?= e($structure['overtime_rate'] ?? '-') ?></td>
                        <td><span class="badge text-bg-<?= e(status_badge_class((string) $structure['status_code'])) ?>"><?= record_status_label((string) $structure['status_code']) ?></span></td>
                        <td>
                          <?php if ($canManage): ?>
                          <form method="post" class="ajax-form d-flex flex-wrap gap-1 align-items-center mb-1">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="save_structure" />
                            <input type="hidden" name="user_id" value="<?= (int) $structure['user_id'] ?>" />
                            <select name="salary_type" class="form-select form-select-sm" style="max-width: 110px;">
                              <option value="MONTHLY" <?= $structure['salary_type'] === 'MONTHLY' ? 'selected' : '' ?>>Monthly</option>
                              <option value="PER_DAY" <?= $structure['salary_type'] === 'PER_DAY' ? 'selected' : '' ?>>Per Day</option>
                              <option value="PER_JOB" <?= $structure['salary_type'] === 'PER_JOB' ? 'selected' : '' ?>>Per Job</option>
                            </select>
                            <input type="number" step="0.01" name="base_amount" value="<?= e($structure['base_amount']) ?>" class="form-control form-control-sm" style="max-width: 110px;" />
                            <input type="number" step="0.001" name="commission_rate" value="<?= e($structure['commission_rate']) ?>" class="form-control form-control-sm" style="max-width: 110px;" />
                            <input type="number" step="0.01" name="overtime_rate" value="<?= e($structure['overtime_rate']) ?>" class="form-control form-control-sm" style="max-width: 110px;" />
                            <select name="status_code" class="form-select form-select-sm" style="max-width: 110px;">
                              <option value="ACTIVE" <?= $structure['status_code'] === 'ACTIVE' ? 'selected' : '' ?>>Active</option>
                              <option value="INACTIVE" <?= $structure['status_code'] === 'INACTIVE' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                            <button class="btn btn-xs btn-outline-primary" type="submit">Update</button>
                          </form>
                          <form method="post" class="ajax-form" onsubmit="return confirm('Delete this salary structure?');">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="delete_structure" />
                            <input type="hidden" name="structure_id" value="<?= (int) $structure['id'] ?>" />
                            <button class="btn btn-xs btn-outline-danger" type="submit">Delete</button>
                          </form>
                          <?php else: ?>
                            <span class="text-muted small">-</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="col-xl-5">
          <div class="card card-outline card-success mb-3">
            <div class="card-header">
              <h3 class="card-title mb-0">Advances</h3>
            </div>
            <div class="card-body">
              <?php if ($canManage): ?>
              <form method="post" class="ajax-form mb-3">
                <?= csrf_field(); ?>
                <input type="hidden" name="_action" value="record_advance" />
                <div class="row g-3 align-items-end">
                  <div class="col-md-6">
                    <label class="form-label">Staff</label>
                    <select name="user_id" class="form-select form-select-sm" required>
                      <option value="">Select</option>
                      <?php foreach ($staffList as $staff): ?>
                        <option value="<?= (int) $staff['id'] ?>"><?= e($staff['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Amount</label>
                    <input type="number" step="0.01" name="amount" class="form-control form-control-sm" required />
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Date</label>
                    <input type="date" name="advance_date" value="<?= e($today) ?>" class="form-control form-control-sm" />
                  </div>
                  <div class="col-12">
                    <input type="text" name="notes" class="form-control form-control-sm" placeholder="Notes" />
                  </div>
                  <div class="col-12 d-flex justify-content-end">
                    <button class="btn btn-sm btn-success" type="submit">Add Advance</button>
                  </div>
                </div>
              </form>
              <?php endif; ?>

              <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                  <thead><tr><th>Staff</th><th>Date</th><th>Amount</th><th>Pending</th><th>Status</th><th style="min-width: 220px;">Actions</th></tr></thead>
                  <tbody>
                    <?php foreach ($advances as $advance): ?>
                      <tr>
                        <td><?= e($advance['name']) ?></td>
                        <td><?= e($advance['advance_date']) ?></td>
                        <td><?= format_currency((float) $advance['amount']) ?></td>
                        <td><?= format_currency((float) ($advance['pending'] ?? 0)) ?></td>
                        <td><span class="badge text-bg-<?= e(status_badge_class((string) $advance['status'])) ?>"><?= e($advance['status']) ?></span></td>
                        <td>
                          <?php if ($canManage): ?>
                          <form method="post" class="ajax-form d-flex flex-wrap gap-1 align-items-center mb-1">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="update_advance" />
                            <input type="hidden" name="advance_id" value="<?= (int) $advance['id'] ?>" />
                            <input type="number" step="0.01" name="amount" value="<?= e($advance['amount']) ?>" class="form-control form-control-sm" style="max-width: 110px;" />
                            <input type="date" name="advance_date" value="<?= e($advance['advance_date']) ?>" class="form-control form-control-sm" style="max-width: 140px;" />
                            <input type="text" name="notes" value="<?= e($advance['notes'] ?? '') ?>" class="form-control form-control-sm" placeholder="Notes" style="min-width: 140px;" />
                            <button class="btn btn-xs btn-outline-primary" type="submit">Update</button>
                          </form>
                          <form method="post" class="ajax-form" onsubmit="return confirm('Delete this advance?');">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="delete_advance" />
                            <input type="hidden" name="advance_id" value="<?= (int) $advance['id'] ?>" />
                            <button class="btn btn-xs btn-outline-danger" type="submit">Delete</button>
                          </form>
                          <?php else: ?>
                            <span class="text-muted small">-</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="card card-outline card-warning">
            <div class="card-header">
              <h3 class="card-title mb-0">Loans</h3>
            </div>
            <div class="card-body">
              <?php if ($canManage): ?>
              <form method="post" class="ajax-form mb-3">
                <?= csrf_field(); ?>
                <input type="hidden" name="_action" value="record_loan" />
                <div class="row g-3 align-items-end">
                  <div class="col-md-5">
                    <label class="form-label">Staff</label>
                    <select name="user_id" class="form-select form-select-sm" required>
                      <option value="">Select</option>
                      <?php foreach ($staffList as $staff): ?>
                        <option value="<?= (int) $staff['id'] ?>"><?= e($staff['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Loan Amount</label>
                    <input type="number" step="0.01" name="total_amount" class="form-control form-control-sm" required />
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">EMI</label>
                    <input type="number" step="0.01" name="emi_amount" class="form-control form-control-sm" />
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Date</label>
                    <input type="date" name="loan_date" value="<?= e($today) ?>" class="form-control form-control-sm" />
                  </div>
                  <div class="col-12">
                    <input type="text" name="notes" class="form-control form-control-sm" placeholder="Notes" />
                  </div>
                  <div class="col-12 d-flex justify-content-end">
                    <button class="btn btn-sm btn-warning" type="submit">Save Loan</button>
                  </div>
                </div>
              </form>
              <?php endif; ?>

              <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                  <thead><tr><th>Staff</th><th>Date</th><th>Amount</th><th>Paid</th><th>Pending</th><th>EMI</th><th>Status</th><th style="min-width: 240px;">Actions</th></tr></thead>
                  <tbody>
                    <?php foreach ($loans as $loan): ?>
                      <tr>
                        <td><?= e($loan['name']) ?></td>
                        <td><?= e($loan['loan_date']) ?></td>
                        <td><?= format_currency((float) $loan['total_amount']) ?></td>
                        <td><?= format_currency((float) $loan['paid_amount']) ?></td>
                        <td><?= format_currency((float) ($loan['pending'] ?? 0)) ?></td>
                        <td><?= format_currency((float) $loan['emi_amount']) ?></td>
                        <td><span class="badge text-bg-<?= e(status_badge_class((string) $loan['status'])) ?>"><?= e($loan['status']) ?></span></td>
                        <td>
                          <?php if ($canManage): ?>
                          <form method="post" class="ajax-form d-flex flex-wrap gap-1 align-items-center mb-1">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="update_loan" />
                            <input type="hidden" name="loan_id" value="<?= (int) $loan['id'] ?>" />
                            <input type="number" step="0.01" name="total_amount" value="<?= e($loan['total_amount']) ?>" class="form-control form-control-sm" style="max-width: 110px;" />
                            <input type="number" step="0.01" name="emi_amount" value="<?= e($loan['emi_amount']) ?>" class="form-control form-control-sm" style="max-width: 110px;" />
                            <input type="date" name="loan_date" value="<?= e($loan['loan_date']) ?>" class="form-control form-control-sm" style="max-width: 140px;" />
                            <input type="text" name="notes" value="<?= e($loan['notes'] ?? '') ?>" class="form-control form-control-sm" placeholder="Notes" style="min-width: 140px;" />
                            <button class="btn btn-xs btn-outline-primary" type="submit">Update</button>
                          </form>
                          <form method="post" class="ajax-form" onsubmit="return confirm('Delete this loan?');">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="delete_loan" />
                            <input type="hidden" name="loan_id" value="<?= (int) $loan['id'] ?>" />
                            <button class="btn btn-xs btn-outline-danger" type="submit">Delete</button>
                          </form>
                          <?php else: ?>
                            <span class="text-muted small">-</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <?php if ($canManage): ?>
              <form method="post" class="ajax-form mt-3">
                <?= csrf_field(); ?>
                <input type="hidden" name="_action" value="loan_manual_payment" />
                <div class="row g-3 align-items-end">
                  <div class="col-md-6">
                    <label class="form-label">Loan</label>
                    <select name="loan_id" class="form-select form-select-sm" required>
                      <option value="">Select loan</option>
                      <?php foreach ($loans as $loan): ?>
                        <option value="<?= (int) $loan['id'] ?>"><?= e($loan['name']) ?> - <?= format_currency((float) ($loan['pending'] ?? 0)) ?> pending</option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Amount</label>
                    <input type="number" step="0.01" name="amount" class="form-control form-control-sm" required />
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Date</label>
                    <input type="date" name="payment_date" value="<?= e($today) ?>" class="form-control form-control-sm" />
                  </div>
                  <div class="col-12">
                    <input type="text" name="notes" class="form-control form-control-sm" placeholder="Reason / Notes" />
                  </div>
                  <div class="col-12 d-flex justify-content-end">
                    <button class="btn btn-sm btn-outline-warning" type="submit">Add Payment</button>
                  </div>
                </div>
              </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="card card-outline card-info mt-2">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
          <h3 class="card-title mb-0">Salary Sheet (<?= e($salaryMonth) ?>)</h3>
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
            <div class="row g-3 mb-2">
              <div class="col-md-3"><strong>Status:</strong> <span class="badge text-bg-<?= e(status_badge_class((string) $currentSheet['status'])) ?>"><?= e($currentSheet['status']) ?></span></div>
              <div class="col-md-3"><strong>Total Gross:</strong> <?= format_currency((float) $currentSheet['total_gross']) ?></div>
              <div class="col-md-3"><strong>Total Payable:</strong> <?= format_currency((float) $currentSheet['total_payable']) ?></div>
              <div class="col-md-3"><strong>Total Paid:</strong> <?= format_currency((float) $currentSheet['total_paid']) ?></div>
            </div>
            <?php if ($canManage && (string) $currentSheet['status'] !== 'LOCKED'): ?>
            <form method="post" class="ajax-form mb-2">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="lock_sheet" />
              <input type="hidden" name="sheet_id" value="<?= (int) $currentSheet['id'] ?>" />
              <button class="btn btn-sm btn-danger" type="submit">Lock Sheet</button>
            </form>
            <?php endif; ?>
          <?php else: ?>
            <p class="text-muted mb-0">Generate the sheet for this month to start payroll.</p>
          <?php endif; ?>

          <?php if ($currentSheet): ?>
          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
              <thead class="table-light">
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
                  <th style="min-width: 240px;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($salaryItems as $item): ?>
                  <tr>
                    <td><?= e($item['name']) ?></td>
                    <td><?= e($item['salary_type']) ?></td>
                    <td><?= format_currency((float) $item['base_amount']) ?></td>
                    <td><?= format_currency((float) $item['commission_amount']) ?></td>
                    <td><?= format_currency((float) $item['overtime_amount']) ?></td>
                    <td><?= format_currency((float) $item['advance_deduction']) ?> / <?= format_currency((float) $item['loan_deduction']) ?> / <?= format_currency((float) $item['manual_deduction']) ?></td>
                    <td><?= format_currency((float) $item['net_payable']) ?></td>
                    <td><?= format_currency((float) $item['paid_amount']) ?></td>
                    <td><span class="badge text-bg-<?= e(status_badge_class((string) $item['status'])) ?>"><?= e($item['status']) ?></span></td>
                    <td>
                      <?php if ($canManage && (string) $currentSheet['status'] !== 'LOCKED'): ?>
                      <form method="post" class="ajax-form mb-2">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="_action" value="update_item" />
                        <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>" />
                        <div class="row g-2">
                          <div class="col-4 col-md-2"><input type="number" step="0.01" name="base_amount" value="<?= e($item['base_amount']) ?>" class="form-control form-control-sm" placeholder="Base" /></div>
                          <div class="col-4 col-md-2"><input type="number" step="0.01" name="commission_base" value="<?= e($item['commission_base']) ?>" class="form-control form-control-sm" placeholder="Comm Base" /></div>
                          <div class="col-4 col-md-2"><input type="number" step="0.001" name="commission_rate" value="<?= e($item['commission_rate']) ?>" class="form-control form-control-sm" placeholder="%" /></div>
                          <div class="col-4 col-md-2"><input type="number" step="0.01" name="overtime_hours" value="<?= e($item['overtime_hours']) ?>" class="form-control form-control-sm" placeholder="OT Hrs" /></div>
                          <div class="col-4 col-md-2"><input type="number" step="0.01" name="overtime_rate" value="<?= e($item['overtime_rate']) ?>" class="form-control form-control-sm" placeholder="OT Rate" /></div>
                          <div class="col-4 col-md-2 d-grid"><button class="btn btn-xs btn-outline-primary" type="submit">Update</button></div>
                        </div>
                        <div class="row g-2 mt-1">
                          <div class="col-4 col-md-3"><input type="number" step="0.01" name="advance_deduction" value="<?= e($item['advance_deduction']) ?>" class="form-control form-control-sm" placeholder="Advance" /></div>
                          <div class="col-4 col-md-3"><input type="number" step="0.01" name="loan_deduction" value="<?= e($item['loan_deduction']) ?>" class="form-control form-control-sm" placeholder="Loan" /></div>
                          <div class="col-4 col-md-3"><input type="number" step="0.01" name="manual_deduction" value="<?= e($item['manual_deduction']) ?>" class="form-control form-control-sm" placeholder="Manual" /></div>
                          <div class="col-12 col-md-3 d-grid"><button class="btn btn-xs btn-outline-secondary" type="submit">Save Deductions</button></div>
                        </div>
                      </form>

                      <form method="post" class="ajax-form">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="_action" value="add_salary_payment" />
                        <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>" />
                        <div class="row g-2 align-items-end">
                          <div class="col-6 col-md-3"><input type="number" step="0.01" name="amount" class="form-control form-control-sm" placeholder="Amount" required /></div>
                          <div class="col-6 col-md-3"><input type="date" name="payment_date" value="<?= e($today) ?>" class="form-control form-control-sm" /></div>
                          <div class="col-6 col-md-3">
                            <select name="payment_mode" class="form-select form-select-sm">
                              <?php foreach (finance_payment_modes() as $mode): ?>
                                <option value="<?= e($mode) ?>"><?= e($mode) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div class="col-6 col-md-3 d-grid"><button class="btn btn-xs btn-success" type="submit">Record Payment</button></div>
                          <div class="col-12"><input type="text" name="notes" class="form-control form-control-sm" placeholder="Notes" /></div>
                        </div>
                      </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($currentSheet): ?>
      <div class="card card-outline card-secondary mt-3">
        <div class="card-header"><h3 class="card-title mb-0">Payment History</h3></div>
        <div class="card-body table-responsive">
          <table class="table table-sm table-striped align-middle mb-0">
            <thead><tr><th>Date</th><th>Staff</th><th>Amount</th><th>Mode</th><th>Notes</th></tr></thead>
            <tbody>
              <?php foreach ($salaryPayments as $payment): ?>
                <tr>
                  <td><?= e($payment['payment_date']) ?></td>
                  <td><?= e($payment['name']) ?></td>
                  <td><?= format_currency((float) $payment['amount']) ?></td>
                  <td><?= e($payment['payment_mode']) ?></td>
                  <td><?= e($payment['notes'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
