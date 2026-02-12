<?php
declare(strict_types=1);

ini_set('session.save_path', __DIR__ . '/tmp_sessions');
require __DIR__ . '/includes/app.php';

$_SESSION['user_id'] = 1;
$_SESSION['company_id'] = 1;
$_SESSION['role_key'] = 'super_admin';
$_SESSION['active_garage_id'] = 1;

$pdo = db();
$month = date('Y-m');

$staffStmt = $pdo->prepare(
    'SELECT u.id
     FROM users u
     INNER JOIN user_garages ug ON ug.user_id = u.id
     WHERE u.company_id = :company_id
       AND ug.garage_id = :garage_id
       AND u.status_code = "ACTIVE"
     ORDER BY u.id ASC
     LIMIT 1'
);
$staffStmt->execute([
    'company_id' => 1,
    'garage_id' => 1,
]);
$staffId = (int) ($staffStmt->fetchColumn() ?: 1);

$pdo->beginTransaction();
try {
    $structureStmt = $pdo->prepare(
        'INSERT INTO payroll_salary_structures
          (user_id, company_id, garage_id, salary_type, base_amount, commission_rate, overtime_rate, status_code, created_by)
         VALUES
          (:user_id, :company_id, :garage_id, "MONTHLY", 15000, 0, NULL, "ACTIVE", :created_by)
         ON DUPLICATE KEY UPDATE
          salary_type = VALUES(salary_type),
          base_amount = VALUES(base_amount),
          commission_rate = VALUES(commission_rate),
          overtime_rate = VALUES(overtime_rate),
          status_code = "ACTIVE",
          updated_by = VALUES(created_by)'
    );
    $structureStmt->execute([
        'user_id' => $staffId,
        'company_id' => 1,
        'garage_id' => 1,
        'created_by' => 1,
    ]);

    $loanInsertStmt = $pdo->prepare(
        'INSERT INTO payroll_loans
          (user_id, company_id, garage_id, loan_date, total_amount, emi_amount, paid_amount, status, notes, created_by)
         VALUES
          (:user_id, :company_id, :garage_id, :loan_date, 2000, 300, 0, "ACTIVE", :notes, :created_by)'
    );
    $loanInsertStmt->execute([
        'user_id' => $staffId,
        'company_id' => 1,
        'garage_id' => 1,
        'loan_date' => date('Y-m-d'),
        'notes' => 'Smoke EMI loan',
        'created_by' => 1,
    ]);

    $sheetStmt = $pdo->prepare(
        'SELECT id
         FROM payroll_salary_sheets
         WHERE company_id = :company_id
           AND garage_id = :garage_id
           AND salary_month = :salary_month
         LIMIT 1'
    );
    $sheetStmt->execute([
        'company_id' => 1,
        'garage_id' => 1,
        'salary_month' => $month,
    ]);
    $sheetId = (int) ($sheetStmt->fetchColumn() ?: 0);
    if ($sheetId <= 0) {
        $insertSheet = $pdo->prepare(
            'INSERT INTO payroll_salary_sheets
              (company_id, garage_id, salary_month, status, created_by)
             VALUES
              (:company_id, :garage_id, :salary_month, "OPEN", :created_by)'
        );
        $insertSheet->execute([
            'company_id' => 1,
            'garage_id' => 1,
            'salary_month' => $month,
            'created_by' => 1,
        ]);
        $sheetId = (int) $pdo->lastInsertId();
    }

    $itemStmt = $pdo->prepare(
        'SELECT id
         FROM payroll_salary_items
         WHERE sheet_id = :sheet_id
           AND user_id = :user_id
         LIMIT 1'
    );
    $itemStmt->execute([
        'sheet_id' => $sheetId,
        'user_id' => $staffId,
    ]);
    $itemId = (int) ($itemStmt->fetchColumn() ?: 0);

    if ($itemId <= 0) {
        $insertItem = $pdo->prepare(
            'INSERT INTO payroll_salary_items
              (sheet_id, user_id, salary_type, base_amount, commission_base, commission_rate, commission_amount, overtime_hours, overtime_rate, overtime_amount,
               advance_deduction, loan_deduction, manual_deduction, gross_amount, net_payable, paid_amount, deductions_applied, status)
             VALUES
              (:sheet_id, :user_id, "MONTHLY", 15000, 15000, 0, 0, 0, NULL, 0, 0, 300, 0, 15000, 14700, 0, 0, "PENDING")'
        );
        $insertItem->execute([
            'sheet_id' => $sheetId,
            'user_id' => $staffId,
        ]);
        $itemId = (int) $pdo->lastInsertId();
    } else {
        $updateItem = $pdo->prepare(
            'UPDATE payroll_salary_items
             SET salary_type = "MONTHLY",
                 base_amount = 15000,
                 commission_base = 15000,
                 commission_rate = 0,
                 commission_amount = 0,
                 overtime_hours = 0,
                 overtime_rate = NULL,
                 overtime_amount = 0,
                 advance_deduction = 0,
                 loan_deduction = 300,
                 manual_deduction = 0,
                 gross_amount = 15000,
                 net_payable = 14700,
                 paid_amount = 0,
                 deductions_applied = 0,
                 status = "PENDING"
             WHERE id = :id'
        );
        $updateItem->execute(['id' => $itemId]);
    }

    $sheetTotalStmt = $pdo->prepare(
        'UPDATE payroll_salary_sheets
         SET status = "OPEN",
             locked_at = NULL,
             locked_by = NULL,
             total_gross = 15000,
             total_deductions = 300,
             total_payable = 14700,
             total_paid = 0
         WHERE id = :id'
    );
    $sheetTotalStmt->execute(['id' => $sheetId]);

    $pdo->commit();
} catch (Throwable $exception) {
    $pdo->rollBack();
    throw $exception;
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    '_csrf' => csrf_token(),
    '_action' => 'add_salary_payment',
    'item_id' => (string) $itemId,
    'amount' => '500.00',
    'payment_date' => date('Y-m-d'),
    'payment_mode' => 'BANK_TRANSFER',
    'notes' => 'Smoke EMI deduction payment',
];
$_GET = ['salary_month' => $month];

require __DIR__ . '/modules/payroll/index.php';
