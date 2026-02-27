<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();

$companyId = active_company_id();
$garageId = active_garage_id();
$today = date('Y-m-d');
$expenseBoundsStmt = db()->prepare(
    'SELECT MIN(expense_date) AS min_date, MAX(expense_date) AS max_date
     FROM expenses
     WHERE company_id = :company_id
       AND garage_id = :garage_id'
);
$expenseBoundsStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$expenseBounds = $expenseBoundsStmt->fetch() ?: [];
$expenseRangeStart = date_filter_is_valid_iso((string) ($expenseBounds['min_date'] ?? ''))
    ? (string) $expenseBounds['min_date']
    : date('Y-m-01', strtotime($today));
$expenseRangeEndCandidate = date_filter_is_valid_iso((string) ($expenseBounds['max_date'] ?? ''))
    ? (string) $expenseBounds['max_date']
    : $today;
$expenseRangeEnd = $expenseRangeEndCandidate > $today ? $expenseRangeEndCandidate : $today;
if ($expenseRangeEnd < $expenseRangeStart) {
    $expenseRangeStart = $expenseRangeEnd;
}

$expenseDateFilter = date_filter_resolve_request([
    'company_id' => $companyId,
    'garage_id' => $garageId,
    'range_start' => $expenseRangeStart,
    'range_end' => $expenseRangeEnd,
    'yearly_start' => date('Y-01-01', strtotime($expenseRangeEnd)),
    'session_namespace' => 'expenses_index',
    'request_mode' => $_GET['date_mode'] ?? null,
    'request_from' => $_GET['from'] ?? null,
    'request_to' => $_GET['to'] ?? null,
]);
$dateMode = (string) ($expenseDateFilter['mode'] ?? 'monthly');
$dateModeOptions = date_filter_modes();
$fromDate = (string) ($expenseDateFilter['from_date'] ?? $expenseRangeStart);
$toDate = (string) ($expenseDateFilter['to_date'] ?? $expenseRangeEnd);
$selectedCategoryId = get_int('category_id');
$entryTypeFilter = strtoupper(trim((string) ($_GET['entry_type'] ?? '')));
if (!in_array($entryTypeFilter, ['', 'EXPENSE', 'REVERSAL'], true)) {
    $entryTypeFilter = '';
}
$searchTerm = trim((string) ($_GET['q'] ?? ''));

$canView = has_permission('expense.view') || has_permission('expense.manage');
$canManage = has_permission('expense.manage');
if (!$canView) {
    require_permission('expense.view');
}

$page_title = 'Expenses & Daily Cashflow';
$active_menu = 'finance.expenses';

function expense_source_bucket(?string $sourceType): string
{
    $value = strtoupper(trim((string) $sourceType));
    if ($value === '' || $value === 'MANUAL_EXPENSE' || $value === 'MANUAL_EXPENSE_REV') {
        return 'MANUAL';
    }
    if (str_starts_with($value, 'PAYROLL_')) {
        return 'PAYROLL';
    }
    if (str_starts_with($value, 'PURCHASE_')) {
        return 'PURCHASE';
    }
    if (str_starts_with($value, 'OUTSOURCED_')) {
        return 'OUTSOURCE';
    }

    return 'SYSTEM';
}

function expense_source_label(?string $sourceType): string
{
    return match (expense_source_bucket($sourceType)) {
        'MANUAL' => 'Manual Expense',
        'PAYROLL' => 'Payroll Linked',
        'PURCHASE' => 'Purchase Linked',
        'OUTSOURCE' => 'Outsource Linked',
        default => 'System Linked',
    };
}

function expense_editable_entry(array $expense): bool
{
    if ((string) ($expense['entry_type'] ?? '') !== 'EXPENSE') {
        return false;
    }

    return expense_source_bucket((string) ($expense['source_type'] ?? '')) === 'MANUAL';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!$canManage) {
        flash_set('expense_error', 'You do not have permission to modify expenses.', 'danger');
        redirect('modules/expenses/index.php');
    }

    $action = (string) ($_POST['_action'] ?? '');
    if ($action === 'save_category') {
        $categoryName = trim((string) ($_POST['category_name'] ?? ''));
        if ($categoryName === '') {
            flash_set('expense_error', 'Category name is required.', 'danger');
            redirect('modules/expenses/index.php');
        }

        $pdo = db();
        $existsStmt = $pdo->prepare(
            'SELECT id FROM expense_categories WHERE company_id = :company_id AND garage_id = :garage_id AND category_name = :category_name LIMIT 1'
        );
        $existsStmt->execute([
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'category_name' => $categoryName,
        ]);
        $existing = $existsStmt->fetch();
        if ($existing) {
            flash_set('expense_warning', 'Category already exists for this garage.', 'warning');
            redirect('modules/expenses/index.php');
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO expense_categories (company_id, garage_id, category_name, status_code, created_by)
             VALUES (:company_id, :garage_id, :category_name, "ACTIVE", :created_by)'
        );
        $insertStmt->execute([
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'category_name' => $categoryName,
            'created_by' => $_SESSION['user_id'] ?? null,
        ]);
        $categoryId = (int) $pdo->lastInsertId();
        log_audit('expenses', 'category_create', $categoryId, 'Created expense category', [
            'entity' => 'expense_category',
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'after' => ['category_name' => $categoryName],
        ]);
        flash_set('expense_success', 'Category added.', 'success');
        redirect('modules/expenses/index.php');
    }

    if ($action === 'update_category') {
        $categoryId = post_int('category_id');
        $categoryName = trim((string) ($_POST['category_name'] ?? ''));
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));
        if (!in_array($statusCode, ['ACTIVE', 'INACTIVE'], true)) {
            $statusCode = 'ACTIVE';
        }
        if ($categoryId <= 0 || $categoryName === '') {
            flash_set('expense_error', 'Valid category and name are required.', 'danger');
            redirect('modules/expenses/index.php');
        }

        $pdo = db();
        $catStmt = $pdo->prepare(
            'SELECT * FROM expense_categories WHERE id = :id AND company_id = :company_id AND garage_id = :garage_id'
        );
        $catStmt->execute([
            'id' => $categoryId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
        $existing = $catStmt->fetch();
        if (!$existing) {
            flash_set('expense_error', 'Category not found for this garage.', 'danger');
            redirect('modules/expenses/index.php');
        }

        $duplicateStmt = $pdo->prepare(
            'SELECT id FROM expense_categories
             WHERE company_id = :company_id AND garage_id = :garage_id AND category_name = :category_name AND id <> :id LIMIT 1'
        );
        $duplicateStmt->execute([
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'category_name' => $categoryName,
            'id' => $categoryId,
        ]);
        if ($duplicateStmt->fetch()) {
            flash_set('expense_error', 'Another category with this name exists.', 'danger');
            redirect('modules/expenses/index.php');
        }

        $updateStmt = $pdo->prepare(
            'UPDATE expense_categories
             SET category_name = :category_name,
                 status_code = :status_code,
                 updated_by = :updated_by
             WHERE id = :id'
        );
        $updateStmt->execute([
            'category_name' => $categoryName,
            'status_code' => $statusCode,
            'updated_by' => $_SESSION['user_id'] ?? null,
            'id' => $categoryId,
        ]);

        log_audit('expenses', 'category_update', $categoryId, 'Updated expense category', [
            'entity' => 'expense_category',
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'before' => [
                'category_name' => (string) ($existing['category_name'] ?? ''),
                'status_code' => (string) ($existing['status_code'] ?? ''),
            ],
            'after' => [
                'category_name' => $categoryName,
                'status_code' => $statusCode,
            ],
        ]);
        flash_set('expense_success', 'Category updated.', 'success');
        redirect('modules/expenses/index.php');
    }

    if ($action === 'delete_category') {
        $categoryId = post_int('category_id');
        if ($categoryId <= 0) {
            flash_set('expense_error', 'Invalid category selected.', 'danger');
            redirect('modules/expenses/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $safeDeleteValidation = safe_delete_validate_post_confirmation('expense_category', $categoryId, [
                'operation' => 'delete',
                'reason_field' => 'deletion_reason',
            ]);
            $deletionReason = (string) ($safeDeleteValidation['reason'] ?? '');

            $catStmt = $pdo->prepare(
                'SELECT id, category_name, status_code
                 FROM expense_categories
                 WHERE id = :id AND company_id = :company_id AND garage_id = :garage_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $catStmt->execute([
                'id' => $categoryId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ]);
            $category = $catStmt->fetch();
            if (!$category) {
                throw new RuntimeException('Category not found.');
            }
            $statusCode = strtoupper(trim((string) ($category['status_code'] ?? 'ACTIVE')));
            if (in_array($statusCode, ['INACTIVE', 'DELETED'], true)) {
                throw new RuntimeException('Category is already inactive.');
            }

            $categoryColumns = table_columns('expense_categories');
            $setParts = [
                'status_code = "INACTIVE"',
                'updated_by = :updated_by',
            ];
            $params = [
                'updated_by' => $_SESSION['user_id'] ?? null,
                'id' => $categoryId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ];
            if (in_array('deleted_at', $categoryColumns, true)) {
                $setParts[] = 'deleted_at = COALESCE(deleted_at, NOW())';
            }
            if (in_array('deleted_by', $categoryColumns, true)) {
                $setParts[] = 'deleted_by = :deleted_by';
                $params['deleted_by'] = $_SESSION['user_id'] ?? null;
            }
            if (in_array('deletion_reason', $categoryColumns, true)) {
                $setParts[] = 'deletion_reason = :deletion_reason';
                $params['deletion_reason'] = $deletionReason !== '' ? $deletionReason : null;
            }

            $stmt = $pdo->prepare(
                'UPDATE expense_categories
                 SET ' . implode(', ', $setParts) . '
                 WHERE id = :id AND company_id = :company_id AND garage_id = :garage_id'
            );
            $stmt->execute($params);

            log_audit('expenses', 'category_inactivate', $categoryId, 'Inactivated expense category', [
                'entity' => 'expense_category',
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'metadata' => [
                    'category_name' => (string) ($category['category_name'] ?? ''),
                    'deletion_reason' => $deletionReason,
                ],
            ]);
            $pdo->commit();
            safe_delete_log_cascade('expense_category', 'delete', $categoryId, $safeDeleteValidation, [
                'metadata' => [
                    'company_id' => $companyId,
                    'garage_id' => $garageId,
                    'category_name' => (string) ($category['category_name'] ?? ''),
                ],
            ]);
            flash_set('expense_success', 'Category deleted.', 'success');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash_set('expense_error', $exception->getMessage(), 'danger');
        }
        redirect('modules/expenses/index.php');
    }

    if ($action === 'record_expense') {
        $categoryId = post_int('category_id');
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $paymentMode = finance_normalize_payment_mode((string) ($_POST['payment_mode'] ?? 'CASH'));
        $notes = post_string('notes', 255);
        $expenseDate = trim((string) ($_POST['expense_date'] ?? $today));
        $paidTo = post_string('paid_to', 120);

        if ($categoryId <= 0 || $amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
            flash_set('expense_error', 'Category, date, and amount are required.', 'danger');
            redirect('modules/expenses/index.php');
        }

        $pdo = db();
        $catStmt = $pdo->prepare('SELECT category_name FROM expense_categories WHERE id = :id AND company_id = :company_id AND garage_id = :garage_id LIMIT 1');
        $catStmt->execute([
            'id' => $categoryId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
        $catRow = $catStmt->fetch();
        if (!$catRow) {
            flash_set('expense_error', 'Category not found.', 'danger');
            redirect('modules/expenses/index.php');
        }

        try {
            $expenseId = finance_record_expense([
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'category_name' => (string) $catRow['category_name'],
                'expense_date' => $expenseDate,
                'amount' => $amount,
                'payment_mode' => $paymentMode,
                'paid_to' => $paidTo,
                'notes' => $notes,
                'source_type' => 'MANUAL_EXPENSE',
                'entry_type' => 'EXPENSE',
                'created_by' => $_SESSION['user_id'] ?? null,
            ]);
        } catch (Throwable $exception) {
            flash_set('expense_error', $exception->getMessage(), 'danger');
            redirect('modules/expenses/index.php');
        }
        if ($expenseId) {
            log_audit('expenses', 'expense_create', (int) $expenseId, 'Recorded manual expense', [
                'entity' => 'expense',
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'after' => [
                    'category_id' => $categoryId,
                    'amount' => $amount,
                    'payment_mode' => $paymentMode,
                    'expense_date' => $expenseDate,
                ],
            ]);
        }

        flash_set('expense_success', 'Expense recorded.', 'success');
        redirect('modules/expenses/index.php');
    }

    if ($action === 'update_expense') {
        $expenseId = post_int('expense_id');
        $categoryId = post_int('category_id');
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $paymentMode = finance_normalize_payment_mode((string) ($_POST['payment_mode'] ?? 'CASH'));
        $notes = post_string('notes', 255);
        $expenseDate = trim((string) ($_POST['expense_date'] ?? $today));
        $paidTo = post_string('paid_to', 120);

        if ($expenseId <= 0 || $categoryId <= 0 || $amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
            flash_set('expense_error', 'All fields are required to update.', 'danger');
            redirect('modules/expenses/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $expenseStmt = $pdo->prepare(
                'SELECT * FROM expenses WHERE id = :id AND company_id = :company_id AND garage_id = :garage_id FOR UPDATE'
            );
            $expenseStmt->execute([
                'id' => $expenseId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ]);
            $expense = $expenseStmt->fetch();
            if (!$expense) {
                throw new RuntimeException('Expense not found.');
            }
            if ((string) ($expense['entry_type'] ?? '') !== 'EXPENSE') {
                throw new RuntimeException('Only original expenses can be edited.');
            }
            if (!expense_editable_entry($expense)) {
                $sourceLabel = expense_source_label((string) ($expense['source_type'] ?? ''));
                throw new RuntimeException($sourceLabel . ' entries cannot be edited directly. Use reversal from the source module.');
            }

            $reversalCheckStmt = $pdo->prepare('SELECT id FROM expenses WHERE reversed_expense_id = :id LIMIT 1');
            $reversalCheckStmt->execute(['id' => $expenseId]);
            if ($reversalCheckStmt->fetch()) {
                throw new RuntimeException('Reversed entries cannot be edited.');
            }

            $catStmt = $pdo->prepare('SELECT category_name FROM expense_categories WHERE id = :id AND company_id = :company_id AND garage_id = :garage_id');
            $catStmt->execute([
                'id' => $categoryId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ]);
            $catRow = $catStmt->fetch();
            if (!$catRow) {
                throw new RuntimeException('Category not found.');
            }

            $updateStmt = $pdo->prepare(
                'UPDATE expenses
                 SET category_id = :category_id,
                     expense_date = :expense_date,
                     amount = :amount,
                     paid_to = :paid_to,
                     payment_mode = :payment_mode,
                     notes = :notes
                 WHERE id = :id'
            );
            $updateStmt->execute([
                'category_id' => $categoryId,
                'expense_date' => $expenseDate,
                'amount' => $amount,
                'paid_to' => $paidTo !== '' ? $paidTo : null,
                'payment_mode' => $paymentMode,
                'notes' => $notes !== '' ? $notes : null,
                'id' => $expenseId,
            ]);

            if (function_exists('ledger_reverse_all_reference_types') && function_exists('ledger_post_finance_expense_entry')) {
                ledger_reverse_all_reference_types(
                    $pdo,
                    $companyId,
                    [
                        ['reference_type' => 'EXPENSE', 'reference_id' => $expenseId],
                        ['reference_type' => 'EXPENSE_UPDATE', 'reference_id' => $expenseId],
                    ],
                    'EXPENSE_UPDATE_RESTATE',
                    $expenseId,
                    $expenseDate,
                    'Expense update restatement for expense #' . $expenseId,
                    $_SESSION['user_id'] ?? null
                );

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
                        'source_type' => (string) ($expense['source_type'] ?? 'MANUAL_EXPENSE'),
                        'source_id' => isset($expense['source_id']) ? (int) $expense['source_id'] : null,
                        'entry_type' => 'EXPENSE',
                        'reversed_expense_id' => null,
                        'created_by' => $_SESSION['user_id'] ?? null,
                    ],
                    (string) ($catRow['category_name'] ?? 'General Expense'),
                    $_SESSION['user_id'] ?? null,
                    'EXPENSE_UPDATE'
                );
            }

            log_audit('expenses', 'expense_update', $expenseId, 'Updated expense', [
                'entity' => 'expense',
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'before' => [
                    'category_id' => (int) ($expense['category_id'] ?? 0),
                    'amount' => (float) ($expense['amount'] ?? 0),
                    'payment_mode' => (string) ($expense['payment_mode'] ?? ''),
                    'expense_date' => (string) ($expense['expense_date'] ?? ''),
                ],
                'after' => [
                    'category_id' => $categoryId,
                    'amount' => $amount,
                    'payment_mode' => $paymentMode,
                    'expense_date' => $expenseDate,
                ],
            ]);
            $pdo->commit();
            flash_set('expense_success', 'Expense updated.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('expense_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/expenses/index.php');
    }

    if ($action === 'delete_expense') {
        flash_set('expense_error', 'Direct deletion is disabled. Reverse the expense entry instead.', 'danger');
        redirect('modules/expenses/index.php');
    }

    if ($action === 'reverse_expense') {
        $expenseId = post_int('expense_id');
        $reverseReason = post_string('reverse_reason', 255);
        if ($expenseId <= 0 || $reverseReason === '') {
            flash_set('expense_error', 'Expense and reason are required for reversal.', 'danger');
            redirect('modules/expenses/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $safeDeleteValidation = safe_delete_validate_post_confirmation('expense', $expenseId, [
                'operation' => 'reverse',
                'reason_field' => 'reverse_reason',
            ]);
            $expStmt = $pdo->prepare(
                'SELECT * FROM expenses WHERE id = :id AND company_id = :company_id AND garage_id = :garage_id FOR UPDATE'
            );
            $expStmt->execute([
                'id' => $expenseId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ]);
            $expense = $expStmt->fetch();
            if (!$expense) {
                throw new RuntimeException('Expense not found.');
            }
            if ((string) ($expense['entry_type'] ?? '') !== 'EXPENSE') {
                throw new RuntimeException('Only original expenses can be reversed.');
            }
            if (!expense_editable_entry($expense)) {
                $sourceLabel = expense_source_label((string) ($expense['source_type'] ?? ''));
                throw new RuntimeException($sourceLabel . ' entries must be reversed from their source workflow.');
            }

            $checkStmt = $pdo->prepare('SELECT id FROM expenses WHERE reversed_expense_id = :id LIMIT 1');
            $checkStmt->execute(['id' => $expenseId]);
            if ($checkStmt->fetch()) {
                throw new RuntimeException('Expense already reversed.');
            }

            $insertStmt = $pdo->prepare(
                'INSERT INTO expenses
                  (company_id, garage_id, category_id, expense_date, amount, paid_to, payment_mode, notes, source_type, source_id, entry_type, reversed_expense_id, created_by)
                 VALUES
                  (:company_id, :garage_id, :category_id, :expense_date, :amount, :paid_to, :payment_mode, :notes, :source_type, :source_id, "REVERSAL", :reversed_expense_id, :created_by)'
            );
            $insertStmt->execute([
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'category_id' => $expense['category_id'] ?? null,
                'expense_date' => $today,
                'amount' => -round((float) ($expense['amount'] ?? 0), 2),
                'paid_to' => $expense['paid_to'] ?? null,
                'payment_mode' => 'ADJUSTMENT',
                'notes' => $reverseReason,
                'source_type' => 'MANUAL_EXPENSE_REV',
                'source_id' => $expenseId,
                'reversed_expense_id' => $expenseId,
                'created_by' => $_SESSION['user_id'] ?? null,
            ]);
            $reversalId = (int) $pdo->lastInsertId();

            if (function_exists('ledger_reverse_all_reference_types')) {
                ledger_reverse_all_reference_types(
                    $pdo,
                    $companyId,
                    [
                        ['reference_type' => 'EXPENSE', 'reference_id' => $expenseId],
                        ['reference_type' => 'EXPENSE_UPDATE', 'reference_id' => $expenseId],
                    ],
                    'EXPENSE_MANUAL_REVERSAL',
                    $reversalId,
                    $today,
                    'Manual expense reversal for expense #' . $expenseId,
                    $_SESSION['user_id'] ?? null
                );
            }

            log_audit('expenses', 'reverse', $expenseId, 'Expense reversed', [
                'entity' => 'expense',
                'source' => 'MANUAL',
                'after' => [
                    'reversal_id' => $reversalId,
                    'reverse_reason' => $reverseReason,
                ],
            ]);
            $pdo->commit();
            safe_delete_log_cascade('expense', 'reverse', $expenseId, $safeDeleteValidation, [
                'reversal_references' => ['EXPREV#' . $reversalId],
                'metadata' => [
                    'company_id' => $companyId,
                    'garage_id' => $garageId,
                    'reversal_id' => $reversalId,
                ],
            ]);
            flash_set('expense_success', 'Expense reversed.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('expense_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/expenses/index.php');
    }
}

$categoryAllStmt = db()->prepare(
    'SELECT *
     FROM expense_categories
     WHERE company_id = :company_id
       AND garage_id = :garage_id
     ORDER BY category_name ASC'
);
$categoryAllStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$allCategories = $categoryAllStmt->fetchAll();
$categories = array_values(array_filter(
    $allCategories,
    static fn (array $category): bool => (string) ($category['status_code'] ?? '') === 'ACTIVE'
));

$expenseConditions = [
    'e.company_id = :company_id',
    'e.garage_id = :garage_id',
    'e.entry_type <> "DELETED"',
    'e.expense_date BETWEEN :from_date AND :to_date',
];
$expenseParams = [
    'company_id' => $companyId,
    'garage_id' => $garageId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
];
if ($selectedCategoryId > 0) {
    $expenseConditions[] = 'e.category_id = :category_id';
    $expenseParams['category_id'] = $selectedCategoryId;
}
if ($entryTypeFilter !== '') {
    $expenseConditions[] = 'e.entry_type = :entry_type';
    $expenseParams['entry_type'] = $entryTypeFilter;
}

$expenseListSql = 'SELECT e.*, ec.category_name
     FROM expenses e
     LEFT JOIN expense_categories ec ON ec.id = e.category_id
     WHERE ' . implode(' AND ', $expenseConditions);
$expenseListParams = $expenseParams;
if ($searchTerm !== '') {
    $expenseListSql .= ' AND (
        COALESCE(ec.category_name, "") LIKE :search_term
        OR COALESCE(e.paid_to, "") LIKE :search_term
        OR COALESCE(e.notes, "") LIKE :search_term
        OR COALESCE(e.source_type, "") LIKE :search_term
    )';
    $expenseListParams['search_term'] = '%' . $searchTerm . '%';
}
$expenseListSql .= '
     ORDER BY e.expense_date DESC, e.id DESC
     LIMIT 200';
$expenseStmt = db()->prepare($expenseListSql);
$expenseStmt->execute($expenseListParams);
$expenses = $expenseStmt->fetchAll();

$statsStmt = db()->prepare(
    'SELECT
        COUNT(*) AS total_entries,
        COALESCE(SUM(CASE WHEN e.entry_type = "EXPENSE" THEN e.amount ELSE 0 END), 0) AS gross_expense_total,
        COALESCE(SUM(CASE WHEN e.entry_type = "REVERSAL" THEN ABS(e.amount) ELSE 0 END), 0) AS reversal_total,
        COALESCE(SUM(e.amount), 0) AS net_expense_total
     FROM expenses e
     WHERE ' . implode(' AND ', $expenseConditions)
);
$statsStmt->execute($expenseParams);
$expenseStats = $statsStmt->fetch() ?: [
    'total_entries' => 0,
    'gross_expense_total' => 0,
    'reversal_total' => 0,
    'net_expense_total' => 0,
];
$totalEntries = (int) ($expenseStats['total_entries'] ?? 0);
$grossExpenseTotal = round((float) ($expenseStats['gross_expense_total'] ?? 0), 2);
$reversalTotal = round((float) ($expenseStats['reversal_total'] ?? 0), 2);
$expenseTotal = round((float) ($expenseStats['net_expense_total'] ?? 0), 2);

$summaryStmt = db()->prepare(
    'SELECT e.expense_date, COALESCE(SUM(e.amount), 0) AS total
     FROM expenses e
     WHERE ' . implode(' AND ', $expenseConditions) . '
     GROUP BY e.expense_date
     ORDER BY e.expense_date ASC'
);
$summaryStmt->execute($expenseParams);
$dailySummary = $summaryStmt->fetchAll();

$categoryBreakStmt = db()->prepare(
    'SELECT COALESCE(ec.category_name, "Uncategorized") AS category_name, COALESCE(SUM(e.amount), 0) AS total
     FROM expenses e
     LEFT JOIN expense_categories ec ON ec.id = e.category_id
     WHERE ' . implode(' AND ', $expenseConditions) . '
     GROUP BY category_name
     ORDER BY total DESC'
);
$categoryBreakStmt->execute($expenseParams);
$categoryBreakdown = $categoryBreakStmt->fetchAll();

$revenueTotal = 0.0;
if (table_columns('invoices') !== []) {
    $revenueStmt = db()->prepare(
        'SELECT SUM(grand_total) AS total
         FROM invoices
         WHERE company_id = :company_id
           AND garage_id = :garage_id
           AND invoice_status = "FINALIZED"
           AND invoice_date BETWEEN :from_date AND :to_date'
    );
    $revenueStmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ]);
    $revenueTotal = round((float) ($revenueStmt->fetchColumn() ?? 0), 2);
}

$reversedExpenseIds = [];
foreach ($expenses as $expenseRow) {
    $reversedId = (int) ($expenseRow['reversed_expense_id'] ?? 0);
    if ($reversedId > 0) {
        $reversedExpenseIds[$reversedId] = true;
    }
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Expense Module</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Expenses</li>
          </ol>
        </div>
      </div>
    </div>
  </div>
  <div class="app-content">
    <div class="container-fluid">
      <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="small-box text-bg-danger"><div class="inner"><h4><?= e(number_format($grossExpenseTotal, 2)); ?></h4><p>Gross Expense</p></div><span class="small-box-icon"><i class="bi bi-cash-stack"></i></span></div></div>
        <div class="col-md-3"><div class="small-box text-bg-secondary"><div class="inner"><h4><?= e(number_format($reversalTotal, 2)); ?></h4><p>Reversal Total</p></div><span class="small-box-icon"><i class="bi bi-arrow-counterclockwise"></i></span></div></div>
        <div class="col-md-3"><div class="small-box text-bg-warning"><div class="inner"><h4><?= e(number_format($expenseTotal, 2)); ?></h4><p>Net Expense</p></div><span class="small-box-icon"><i class="bi bi-wallet2"></i></span></div></div>
        <div class="col-md-3"><div class="small-box text-bg-primary"><div class="inner"><h4><?= number_format($totalEntries); ?></h4><p>Ledger Entries</p></div><span class="small-box-icon"><i class="bi bi-journal-text"></i></span></div></div>
      </div>
      <div class="card card-primary mb-3">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
          <h3 class="card-title mb-0">Filters</h3>
          <div class="d-flex flex-wrap gap-2">
            <?php if ($canManage): ?>
              <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#expenseCreateModal"><i class="bi bi-plus-circle me-1"></i>New Expense</button>
              <button type="button" class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#categoryManageModal"><i class="bi bi-tags me-1"></i>Categories</button>
            <?php endif; ?>
            <a href="<?= e(url('modules/reports/expenses.php')); ?>" class="btn btn-sm btn-outline-light"><i class="bi bi-graph-up me-1"></i>Expense Reports</a>
          </div>
        </div>
        <div class="card-body">
          <form
            method="get"
            class="row g-2 align-items-end"
            data-date-filter-form="1"
            data-date-range-start="<?= e($expenseRangeStart); ?>"
            data-date-range-end="<?= e($expenseRangeEnd); ?>"
            data-date-yearly-start="<?= e(date('Y-01-01', strtotime($expenseRangeEnd))); ?>"
          >
            <div class="col-md-2">
              <label class="form-label">Date Mode</label>
              <select name="date_mode" class="form-select">
                <?php foreach ($dateModeOptions as $modeValue => $modeLabel): ?>
                  <option value="<?= e((string) $modeValue); ?>" <?= $dateMode === $modeValue ? 'selected' : ''; ?>>
                    <?= e((string) $modeLabel); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" value="<?= e($fromDate); ?>" class="form-control" required /></div>
            <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" value="<?= e($toDate); ?>" class="form-control" required /></div>
            <div class="col-md-3">
              <label class="form-label">Category</label>
              <select name="category_id" class="form-select">
                <option value="0">All Categories</option>
                <?php foreach ($allCategories as $category): ?>
                  <option value="<?= (int) $category['id']; ?>" <?= (int) $category['id'] === $selectedCategoryId ? 'selected' : ''; ?>><?= e((string) $category['category_name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Entry Type</label>
              <select name="entry_type" class="form-select">
                <option value="" <?= $entryTypeFilter === '' ? 'selected' : ''; ?>>All</option>
                <option value="EXPENSE" <?= $entryTypeFilter === 'EXPENSE' ? 'selected' : ''; ?>>Expense</option>
                <option value="REVERSAL" <?= $entryTypeFilter === 'REVERSAL' ? 'selected' : ''; ?>>Reversal</option>
              </select>
            </div>
            <div class="col-md-3"><label class="form-label">Search</label><input type="text" name="q" class="form-control" placeholder="Paid to / notes / source" value="<?= e($searchTerm); ?>" /></div>
            <div class="col-12 d-flex gap-2">
              <button type="submit" class="btn btn-primary">Apply</button>
              <a href="<?= e(url('modules/expenses/index.php')); ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
          </form>
        </div>
      </div>
      <div class="row">
                <div class="col-md-4">
                    <div class="card card-outline card-primary mb-3">
                        <div class="card-header"><h3 class="card-title">Financial Safety Rules</h3></div>
                        <div class="card-body">
                            <ul class="mb-0 ps-3">
                                <li>Direct deletion is disabled for expenses.</li>
                                <li>Manual entries can be edited before reversal only.</li>
                                <li>Payroll, Purchase, and Outsource linked entries must be reversed from source modules.</li>
                                <li>Every edit and reversal is audit logged.</li>
                            </ul>
                        </div>
                    </div>
                    <div class="card card-outline card-secondary">
                        <div class="card-header"><h3 class="card-title">Active Categories</h3></div>
                        <div class="card-body p-0 table-responsive">
                            <table class="table table-sm table-striped mb-0">
                                <thead><tr><th>Category</th><th>Status</th></tr></thead>
                                <tbody>
                                    <?php if (empty($allCategories)): ?>
                                      <tr><td colspan="2" class="text-center text-muted py-3">No categories configured.</td></tr>
                                    <?php else: foreach ($allCategories as $category): ?>
                                      <tr>
                                        <td><?= e((string) $category['category_name']) ?></td>
                                        <td><span class="badge text-bg-<?= e(status_badge_class((string) $category['status_code'])) ?>"><?= e((string) $category['status_code']) ?></span></td>
                                      </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-md-8">
                    <div class="card card-outline card-info">
                        <div class="card-header"><h3 class="card-title">Expense Ledger</h3></div>
                        <div class="card-body table-responsive p-0">
                            <table class="table table-sm table-striped mb-0">
                                <thead>
                                  <tr>
                                    <th>Date</th>
                                    <th>Category</th>
                                    <th>Source</th>
                                    <th>Entry</th>
                                    <th>Amount</th>
                                    <th>Mode</th>
                                    <th>Paid To</th>
                                    <th>Notes</th>
                                    <th>Actions</th>
                                  </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($expenses)): ?>
                                      <tr><td colspan="9" class="text-center text-muted py-4">No expense entries found for selected filters.</td></tr>
                                    <?php else: foreach ($expenses as $expense): ?>
                                        <?php
                                          $entryType = (string) ($expense['entry_type'] ?? '');
                                          $expenseId = (int) ($expense['id'] ?? 0);
                                          $sourceLabel = expense_source_label((string) ($expense['source_type'] ?? ''));
                                          $isExpenseEntry = $entryType === 'EXPENSE';
                                          $isReversed = isset($reversedExpenseIds[$expenseId]);
                                          $isEditable = expense_editable_entry($expense) && !$isReversed;
                                          $canReverse = $isEditable;
                                          $actionHint = '';
                                          if (!$isEditable) {
                                            $actionHint = $isReversed ? 'Already reversed' : 'Use source module reversal';
                                          }
                                        ?>
                                        <tr>
                                            <td><?= e((string) ($expense['expense_date'] ?? '')) ?></td>
                                            <td><?= e((string) ($expense['category_name'] ?? 'Uncategorized')) ?></td>
                                            <td><span class="badge text-bg-light border"><?= e($sourceLabel) ?></span></td>
                                            <td>
                                              <?php if ($entryType === 'EXPENSE'): ?>
                                                <span class="badge text-bg-success">Expense</span>
                                              <?php elseif ($entryType === 'REVERSAL'): ?>
                                                <span class="badge text-bg-secondary">Reversal</span>
                                              <?php else: ?>
                                                <span class="badge text-bg-dark"><?= e($entryType) ?></span>
                                              <?php endif; ?>
                                            </td>
                                            <td><?= e(format_currency((float) ($expense['amount'] ?? 0))) ?></td>
                                            <td><?= e((string) ($expense['payment_mode'] ?? '')) ?></td>
                                            <td><?= e((string) ($expense['paid_to'] ?? '')) ?></td>
                                            <td><?= e((string) ($expense['notes'] ?? '')) ?></td>
                                            <td class="text-nowrap">
                                              <?php if ($canManage && $isExpenseEntry): ?>
                                                <div class="dropdown">
                                                  <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Action</button>
                                                  <ul class="dropdown-menu dropdown-menu-end">
                                                    <li>
                                                      <button
                                                        type="button"
                                                        class="dropdown-item js-expense-edit-btn"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#expenseEditModal"
                                                        data-expense-id="<?= $expenseId ?>"
                                                        data-category-id="<?= (int) ($expense['category_id'] ?? 0) ?>"
                                                        data-expense-date="<?= e((string) ($expense['expense_date'] ?? '')) ?>"
                                                        data-amount="<?= e((string) ($expense['amount'] ?? '0')) ?>"
                                                        data-payment-mode="<?= e((string) ($expense['payment_mode'] ?? 'CASH')) ?>"
                                                        data-paid-to="<?= e((string) ($expense['paid_to'] ?? '')) ?>"
                                                        data-notes="<?= e((string) ($expense['notes'] ?? '')) ?>"
                                                        <?= $isEditable ? '' : 'disabled'; ?>
                                                      >Edit Expense</button>
                                                    </li>
                                                    <li>
                                                      <button
                                                        type="button"
                                                        class="dropdown-item js-expense-reverse-btn"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#expenseReverseModal"
                                                        data-expense-id="<?= $expenseId ?>"
                                                        data-expense-label="<?= e((string) ($expense['expense_date'] ?? '') . ' | ' . (string) ($expense['category_name'] ?? 'Uncategorized') . ' | ' . format_currency((float) ($expense['amount'] ?? 0))) ?>"
                                                        <?= $canReverse ? '' : 'disabled'; ?>
                                                      >Reverse Expense</button>
                                                    </li>
                                                  </ul>
                                                </div>
                                                <?php if ($actionHint !== ''): ?>
                                                  <div><small class="text-muted"><?= e($actionHint) ?></small></div>
                                                <?php endif; ?>
                                              <?php else: ?>
                                                <span class="text-muted small">-</span>
                                              <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="card card-outline card-secondary">
                                <div class="card-header"><h3 class="card-title">Daily Summary</h3></div>
                                <div class="card-body table-responsive">
                                    <table class="table table-sm table-striped">
                                        <thead><tr><th>Date</th><th>Total</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($dailySummary as $row): ?>
                                                <tr><td><?= e($row['expense_date']) ?></td><td><?= format_currency((float) $row['total']) ?></td></tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card card-outline card-secondary">
                                <div class="card-header"><h3 class="card-title">Category Breakdown</h3></div>
                                <div class="card-body table-responsive">
                                    <table class="table table-sm table-striped">
                                        <thead><tr><th>Category</th><th>Total</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($categoryBreakdown as $row): ?>
                                                <tr><td><?= e($row['category_name']) ?></td><td><?= format_currency((float) $row['total']) ?></td></tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card card-outline card-warning">
                        <div class="card-header"><h3 class="card-title">Revenue vs Expense</h3></div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4"><strong>Finalized Revenue:</strong> <?= e(format_currency($revenueTotal)) ?></div>
                                <div class="col-md-4"><strong>Net Expense:</strong> <?= e(format_currency($expenseTotal)) ?></div>
                                <div class="col-md-4"><strong>Net Margin:</strong> <?= e(format_currency($revenueTotal - $expenseTotal)) ?></div>
                            </div>
                            <?php if ($revenueTotal === 0.0 && table_columns('invoices') === []): ?>
                                <p class="text-muted mb-0">Revenue data not available because invoices table is missing.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
      </div>
    </div>
  </div>
</main>

<?php if ($canManage): ?>
  <div class="modal fade" id="expenseCreateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Record Expense</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post"
              class="ajax-form"
              data-safe-delete
              data-safe-delete-entity="expense"
              data-safe-delete-record-field="expense_id"
              data-safe-delete-operation="reverse"
              data-safe-delete-reason-field="reverse_reason">
          <div class="modal-body">
            <?= csrf_field(); ?>
            <input type="hidden" name="_action" value="record_expense" />
            <div class="mb-2">
              <label class="form-label">Category</label>
              <select name="category_id" class="form-select" required>
                <option value="">Select category</option>
                <?php foreach ($categories as $category): ?>
                  <option value="<?= (int) $category['id'] ?>"><?= e((string) $category['category_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label">Amount</label>
                <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required />
              </div>
              <div class="col-md-6">
                <label class="form-label">Date</label>
                <input type="date" name="expense_date" value="<?= e($today) ?>" class="form-control" required />
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
                <label class="form-label">Paid To</label>
                <input type="text" name="paid_to" class="form-control" maxlength="120" />
              </div>
            </div>
            <div class="mt-2">
              <label class="form-label">Notes</label>
              <textarea name="notes" class="form-control" rows="2" maxlength="255"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            <button type="submit" class="btn btn-primary">Save Expense</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="expenseEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Edit Expense</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post" class="ajax-form">
          <div class="modal-body">
            <?= csrf_field(); ?>
            <input type="hidden" name="_action" value="update_expense" />
            <input type="hidden" name="expense_id" id="expense-edit-id" />
            <div class="mb-2">
              <label class="form-label">Category</label>
              <select name="category_id" id="expense-edit-category" class="form-select" required>
                <option value="">Select category</option>
                <?php foreach ($categories as $category): ?>
                  <option value="<?= (int) $category['id'] ?>"><?= e((string) $category['category_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label">Amount</label>
                <input type="number" step="0.01" min="0.01" name="amount" id="expense-edit-amount" class="form-control" required />
              </div>
              <div class="col-md-6">
                <label class="form-label">Date</label>
                <input type="date" name="expense_date" id="expense-edit-date" class="form-control" required />
              </div>
            </div>
            <div class="row g-2 mt-1">
              <div class="col-md-6">
                <label class="form-label">Payment Mode</label>
                <select name="payment_mode" id="expense-edit-mode" class="form-select">
                  <?php foreach (finance_payment_modes() as $mode): ?>
                    <option value="<?= e($mode) ?>"><?= e($mode) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Paid To</label>
                <input type="text" name="paid_to" id="expense-edit-paid-to" class="form-control" maxlength="120" />
              </div>
            </div>
            <div class="mt-2">
              <label class="form-label">Notes</label>
              <textarea name="notes" id="expense-edit-notes" class="form-control" rows="2" maxlength="255"></textarea>
            </div>
            <small class="text-muted">Only manual entries can be edited. Linked entries must be reversed from source modules.</small>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            <button type="submit" class="btn btn-primary">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="expenseReverseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Reverse Expense</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post" class="ajax-form">
          <div class="modal-body">
            <?= csrf_field(); ?>
            <input type="hidden" name="_action" value="reverse_expense" />
            <input type="hidden" name="expense_id" id="expense-reverse-id" />
            <div class="mb-2">
              <label class="form-label">Expense</label>
              <input type="text" class="form-control" id="expense-reverse-label" readonly />
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

  <div class="modal fade" id="categoryManageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Expense Categories</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form method="post" class="ajax-form mb-3">
            <?= csrf_field(); ?>
            <input type="hidden" name="_action" value="save_category" />
            <div class="input-group">
              <input type="text" name="category_name" class="form-control" placeholder="New category name" required />
              <button class="btn btn-primary" type="submit">Add Category</button>
            </div>
          </form>
          <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
              <thead><tr><th>Category</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody>
                <?php if (empty($allCategories)): ?>
                  <tr><td colspan="3" class="text-center text-muted py-3">No categories configured.</td></tr>
                <?php else: foreach ($allCategories as $category): ?>
                  <tr>
                    <td><?= e((string) $category['category_name']) ?></td>
                    <td><span class="badge text-bg-<?= e(status_badge_class((string) $category['status_code'])) ?>"><?= e((string) $category['status_code']) ?></span></td>
                    <td>
                      <form method="post" class="ajax-form d-flex flex-wrap gap-1 align-items-center">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="_action" value="update_category" />
                        <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>" />
                        <input type="text" name="category_name" value="<?= e((string) $category['category_name']) ?>" class="form-control form-control-sm" style="max-width: 200px;" required />
                        <select name="status_code" class="form-select form-select-sm" style="max-width: 130px;">
                          <option value="ACTIVE" <?= (string) $category['status_code'] === 'ACTIVE' ? 'selected' : ''; ?>>Active</option>
                          <option value="INACTIVE" <?= (string) $category['status_code'] === 'INACTIVE' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                        <button type="submit" class="btn btn-sm btn-outline-primary">Update</button>
                      </form>
                      <form method="post"
                            class="ajax-form mt-1"
                            data-safe-delete
                            data-safe-delete-entity="expense_category"
                            data-safe-delete-record-field="category_id"
                            data-safe-delete-operation="delete"
                            data-safe-delete-reason-field="deletion_reason">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="_action" value="delete_category" />
                        <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>" />
                        <button class="btn btn-sm btn-outline-danger" type="submit">Inactivate</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
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
      var editTrigger = event.target.closest('.js-expense-edit-btn');
      if (editTrigger && !editTrigger.disabled) {
        setValue('expense-edit-id', editTrigger.getAttribute('data-expense-id'));
        setValue('expense-edit-category', editTrigger.getAttribute('data-category-id'));
        setValue('expense-edit-date', editTrigger.getAttribute('data-expense-date'));
        setValue('expense-edit-amount', editTrigger.getAttribute('data-amount'));
        setValue('expense-edit-mode', editTrigger.getAttribute('data-payment-mode'));
        setValue('expense-edit-paid-to', editTrigger.getAttribute('data-paid-to'));
        setValue('expense-edit-notes', editTrigger.getAttribute('data-notes'));
      }

      var reverseTrigger = event.target.closest('.js-expense-reverse-btn');
      if (reverseTrigger && !reverseTrigger.disabled) {
        setValue('expense-reverse-id', reverseTrigger.getAttribute('data-expense-id'));
        setValue('expense-reverse-label', reverseTrigger.getAttribute('data-expense-label'));
      }
    });
  })();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
