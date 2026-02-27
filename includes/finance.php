<?php
declare(strict_types=1);

/**
 * Finance helpers for expenses and cross-module expense capture.
 */
function finance_tables_ready(): bool
{
    return table_columns('expenses') !== [] && table_columns('expense_categories') !== [];
}

function finance_payment_modes(): array
{
    return ['CASH', 'UPI', 'CARD', 'BANK_TRANSFER', 'CHEQUE', 'MIXED', 'ADJUSTMENT'];
}

function finance_normalize_payment_mode(string $mode): string
{
    $normalized = strtoupper(trim($mode));
    return in_array($normalized, finance_payment_modes(), true) ? $normalized : 'CASH';
}

function finance_ensure_category(int $companyId, int $garageId, string $categoryName, ?int $createdBy = null): ?int
{
    if (!finance_tables_ready()) {
        return null;
    }

    $cleanName = trim($categoryName);
    if ($cleanName === '') {
        return null;
    }

    $pdo = db();
    $selectStmt = $pdo->prepare(
        'SELECT id FROM expense_categories WHERE company_id = :company_id AND garage_id = :garage_id AND category_name = :category_name LIMIT 1'
    );
    $selectStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'category_name' => $cleanName,
    ]);
    $existing = $selectStmt->fetch();
    if ($existing) {
        return (int) $existing['id'];
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO expense_categories (company_id, garage_id, category_name, status_code, created_by)
         VALUES (:company_id, :garage_id, :category_name, "ACTIVE", :created_by)'
    );
    $insertStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'category_name' => $cleanName,
        'created_by' => $createdBy > 0 ? $createdBy : null,
    ]);

    return (int) $pdo->lastInsertId();
}

function finance_record_expense(array $payload): ?int
{
    if (!finance_tables_ready()) {
        return null;
    }

    $companyId = (int) ($payload['company_id'] ?? 0);
    $garageId = (int) ($payload['garage_id'] ?? 0);
    $categoryName = trim((string) ($payload['category_name'] ?? 'General Expense'));
    $expenseDate = (string) ($payload['expense_date'] ?? date('Y-m-d'));
    $amount = round((float) ($payload['amount'] ?? 0), 2);
    $paymentMode = finance_normalize_payment_mode((string) ($payload['payment_mode'] ?? 'CASH'));
    $paidTo = trim((string) ($payload['paid_to'] ?? ''));
    $notes = trim((string) ($payload['notes'] ?? ''));
    $sourceType = trim((string) ($payload['source_type'] ?? ''));
    $sourceId = (int) ($payload['source_id'] ?? 0);
    $entryType = strtoupper(trim((string) ($payload['entry_type'] ?? 'EXPENSE')));
    $reversedExpenseId = (int) ($payload['reversed_expense_id'] ?? 0);
    $createdBy = (int) ($payload['created_by'] ?? 0);

    if ($companyId <= 0 || $garageId < 0 || $amount == 0.0) {
        return null;
    }

    if (!in_array($entryType, ['EXPENSE', 'REVERSAL'], true)) {
        $entryType = 'EXPENSE';
    }

    $pdo = db();
    $ownsTx = !$pdo->inTransaction();
    if ($ownsTx) {
        $pdo->beginTransaction();
    }

    try {
        if ($sourceType !== '' && $sourceId > 0) {
            $existingStmt = $pdo->prepare(
                'SELECT id FROM expenses WHERE company_id = :company_id AND source_type = :source_type AND source_id = :source_id AND entry_type = :entry_type LIMIT 1'
            );
            $existingStmt->execute([
                'company_id' => $companyId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'entry_type' => $entryType,
            ]);
            $existing = $existingStmt->fetch();
            if ($existing) {
                if ($ownsTx) {
                    $pdo->commit();
                }
                return (int) $existing['id'];
            }
        }

        $categoryId = finance_ensure_category($companyId, $garageId, $categoryName, $createdBy);
        if (!$categoryId && $garageId !== 0) {
            $categoryId = finance_ensure_category($companyId, 0, $categoryName, $createdBy);
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO expenses
              (company_id, garage_id, category_id, expense_date, amount, paid_to, payment_mode, notes, source_type, source_id, entry_type, reversed_expense_id, created_by)
             VALUES
              (:company_id, :garage_id, :category_id, :expense_date, :amount, :paid_to, :payment_mode, :notes, :source_type, :source_id, :entry_type, :reversed_expense_id, :created_by)'
        );
        $insertStmt->execute([
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'category_id' => $categoryId,
            'expense_date' => $expenseDate,
            'amount' => $amount,
            'paid_to' => $paidTo !== '' ? $paidTo : null,
            'payment_mode' => $paymentMode,
            'notes' => $notes !== '' ? $notes : null,
            'source_type' => $sourceType !== '' ? $sourceType : null,
            'source_id' => $sourceId > 0 ? $sourceId : null,
            'entry_type' => $entryType,
            'reversed_expense_id' => $reversedExpenseId > 0 ? $reversedExpenseId : null,
            'created_by' => $createdBy > 0 ? $createdBy : null,
        ]);

        $expenseId = (int) $pdo->lastInsertId();

        if (function_exists('ledger_post_finance_expense_entry')) {
            ledger_post_finance_expense_entry(
                $pdo,
                [
                    'id' => $expenseId,
                    'company_id' => $companyId,
                    'garage_id' => $garageId,
                    'category_id' => $categoryId,
                    'expense_date' => $expenseDate,
                    'amount' => $amount,
                    'paid_to' => $paidTo !== '' ? $paidTo : null,
                    'payment_mode' => $paymentMode,
                    'notes' => $notes !== '' ? $notes : null,
                    'source_type' => $sourceType !== '' ? $sourceType : null,
                    'source_id' => $sourceId > 0 ? $sourceId : null,
                    'entry_type' => $entryType,
                    'reversed_expense_id' => $reversedExpenseId > 0 ? $reversedExpenseId : null,
                    'created_by' => $createdBy > 0 ? $createdBy : null,
                ],
                $categoryName,
                $createdBy > 0 ? $createdBy : null
            );
        }

        log_audit('expenses', strtolower($entryType), $expenseId, 'Expense entry created', [
            'entity' => 'expense',
            'source' => $sourceType !== '' ? $sourceType : 'MANUAL',
            'after' => [
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'category_name' => $categoryName,
                'amount' => $amount,
                'entry_type' => $entryType,
            ],
        ]);

        if ($ownsTx) {
            $pdo->commit();
        }

        return $expenseId;
    } catch (Throwable $exception) {
        if ($ownsTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function finance_record_expense_for_purchase_payment(int $paymentId, int $purchaseId, int $companyId, int $garageId, float $amount, string $paymentDate, string $paymentMode, string $notes, bool $isReversal, ?int $createdBy): ?int
{
    if (!finance_tables_ready()) {
        return null;
    }

    $pdo = db();
    $vendorName = '';
    $vendorStmt = $pdo->prepare(
        'SELECT v.vendor_name
         FROM purchases p
         LEFT JOIN vendors v ON v.id = p.vendor_id
         WHERE p.id = :purchase_id AND p.company_id = :company_id AND p.garage_id = :garage_id
         LIMIT 1'
    );
    $vendorStmt->execute([
        'purchase_id' => $purchaseId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
    $vendor = $vendorStmt->fetch();
    if ($vendor && !empty($vendor['vendor_name'])) {
        $vendorName = (string) $vendor['vendor_name'];
    }

    $signedAmount = $isReversal ? -abs($amount) : abs($amount);
    $entryType = $isReversal ? 'REVERSAL' : 'EXPENSE';
    $categoryName = 'Purchases';

    return finance_record_expense([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'category_name' => $categoryName,
        'expense_date' => $paymentDate,
        'amount' => $signedAmount,
        'payment_mode' => $paymentMode,
        'paid_to' => $vendorName,
        'notes' => $notes,
        'source_type' => $isReversal ? 'PURCHASE_PAYMENT_REV' : 'PURCHASE_PAYMENT',
        'source_id' => $paymentId,
        'entry_type' => $entryType,
        'created_by' => $createdBy,
    ]);
}

function finance_record_expense_for_outsourced_payment(int $paymentId, int $workId, int $companyId, int $garageId, float $amount, string $paymentDate, string $paymentMode, string $notes, bool $isReversal, ?int $createdBy): ?int
{
    if (!finance_tables_ready()) {
        return null;
    }

    $signedAmount = $isReversal ? -abs($amount) : abs($amount);
    $entryType = $isReversal ? 'REVERSAL' : 'EXPENSE';

    return finance_record_expense([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'category_name' => 'Outsourced Works',
        'expense_date' => $paymentDate,
        'amount' => $signedAmount,
        'payment_mode' => $paymentMode,
        'paid_to' => '',
        'notes' => $notes,
        'source_type' => $isReversal ? 'OUTSOURCED_PAYMENT_REV' : 'OUTSOURCED_PAYMENT',
        'source_id' => $paymentId,
        'entry_type' => $entryType,
        'created_by' => $createdBy,
    ]);
}

function finance_record_expense_for_salary_payment(int $salaryPaymentId, int $salaryItemId, int $userId, int $companyId, int $garageId, float $amount, string $paymentDate, string $paymentMode, string $notes, bool $isReversal, ?int $createdBy): ?int
{
    if (!finance_tables_ready()) {
        return null;
    }

    $pdo = db();
    $staffName = '';
    $userStmt = $pdo->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
    $userStmt->execute(['id' => $userId]);
    $userRow = $userStmt->fetch();
    if ($userRow && !empty($userRow['name'])) {
        $staffName = (string) $userRow['name'];
    }

    $signedAmount = $isReversal ? -abs($amount) : abs($amount);
    $entryType = $isReversal ? 'REVERSAL' : 'EXPENSE';

    return finance_record_expense([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'category_name' => 'Salary & Wages',
        'expense_date' => $paymentDate,
        'amount' => $signedAmount,
        'payment_mode' => $paymentMode,
        'paid_to' => $staffName,
        'notes' => $notes,
        'source_type' => $isReversal ? 'PAYROLL_PAYMENT_REV' : 'PAYROLL_PAYMENT',
        'source_id' => $salaryPaymentId,
        'entry_type' => $entryType,
        'created_by' => $createdBy,
    ]);
}
