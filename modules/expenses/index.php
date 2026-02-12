<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();

$companyId = active_company_id();
$garageId = active_garage_id();
$today = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime('-30 days'));
$fromDate = trim((string) ($_GET['from'] ?? $defaultFrom));
$toDate = trim((string) ($_GET['to'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $fromDate = $defaultFrom;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $toDate = $today;
}

$canView = has_permission('expense.view') || has_permission('expense.manage');
$canManage = has_permission('expense.manage');
if (!$canView) {
    require_permission('expense.view');
}

$page_title = 'Expenses & Daily Cashflow';
$active_menu = 'finance.expenses';

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

        $stmt = db()->prepare(
            'UPDATE expense_categories
             SET status_code = "INACTIVE",
                  updated_by = :updated_by
             WHERE id = :id AND company_id = :company_id AND garage_id = :garage_id'
        );
        $stmt->execute([
            'updated_by' => $_SESSION['user_id'] ?? null,
            'id' => $categoryId,
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
        log_audit('expenses', 'category_inactivate', $categoryId, 'Inactivated expense category', [
            'entity' => 'expense_category',
            'company_id' => $companyId,
            'garage_id' => $garageId,
        ]);
        flash_set('expense_success', 'Category deleted.', 'success');
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

            $catStmt = $pdo->prepare('SELECT category_name FROM expense_categories WHERE id = :id AND company_id = :company_id AND garage_id = :garage_id');
            $catStmt->execute([
                'id' => $categoryId,
                'company_id' => $companyId,
                'garage_id' => $garageId,
            ]);
            if (!$catStmt->fetch()) {
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
        $expenseId = post_int('expense_id');
        if ($expenseId <= 0) {
            flash_set('expense_error', 'Invalid expense selected.', 'danger');
            redirect('modules/expenses/index.php');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $expStmt = $pdo->prepare('SELECT id, entry_type FROM expenses WHERE id = :id AND company_id = :company_id AND garage_id = :garage_id FOR UPDATE');
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
                throw new RuntimeException('Only original expenses can be deleted.');
            }

            $checkStmt = $pdo->prepare('SELECT id FROM expenses WHERE reversed_expense_id = :id LIMIT 1');
            $checkStmt->execute(['id' => $expenseId]);
            if ($checkStmt->fetch()) {
                throw new RuntimeException('Expense already reversed; cannot delete.');
            }

            $deleteStmt = $pdo->prepare(
                'UPDATE expenses
                 SET entry_type = "DELETED",
                     payment_mode = "VOID",
                     notes = CONCAT(COALESCE(notes, ""), " | Deleted"),
                     updated_by = :updated_by
                 WHERE id = :id'
            );
            $deleteStmt->execute([
                'updated_by' => $_SESSION['user_id'] ?? null,
                'id' => $expenseId,
            ]);

            log_audit('expenses', 'delete', $expenseId, 'Expense deleted', ['entity' => 'expense']);
            $pdo->commit();
            flash_set('expense_success', 'Expense deleted.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('expense_error', $exception->getMessage(), 'danger');
        }

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

            log_audit('expenses', 'reverse', $expenseId, 'Expense reversed', [
                'entity' => 'expense',
                'source' => 'MANUAL',
                'after' => ['reversal_id' => (int) $pdo->lastInsertId()],
            ]);
            $pdo->commit();
            flash_set('expense_success', 'Expense reversed.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('expense_error', $exception->getMessage(), 'danger');
        }

        redirect('modules/expenses/index.php');
    }
}

$categoryStmt = db()->prepare(
    'SELECT * FROM expense_categories
     WHERE company_id = :company_id
       AND garage_id = :garage_id
       AND status_code = "ACTIVE"
     ORDER BY category_name ASC'
);
$categoryStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
]);
$categories = $categoryStmt->fetchAll();

$expenseStmt = db()->prepare(
    'SELECT e.*, ec.category_name
     FROM expenses e
     LEFT JOIN expense_categories ec ON ec.id = e.category_id
     WHERE e.company_id = :company_id
       AND e.garage_id = :garage_id
                 AND e.entry_type <> "DELETED"
       AND e.expense_date BETWEEN :from_date AND :to_date
     ORDER BY e.expense_date DESC, e.id DESC
     LIMIT 50'
);
$expenseStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
]);
$expenses = $expenseStmt->fetchAll();

$summaryStmt = db()->prepare(
    'SELECT expense_date, SUM(amount) AS total
     FROM expenses
     WHERE company_id = :company_id
       AND garage_id = :garage_id
       AND expense_date BETWEEN :from_date AND :to_date
       AND entry_type <> "DELETED"
     GROUP BY expense_date
     ORDER BY expense_date ASC'
);
$summaryStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
]);
$dailySummary = $summaryStmt->fetchAll();

$categoryBreakStmt = db()->prepare(
    'SELECT COALESCE(ec.category_name, "Uncategorized") AS category_name, SUM(e.amount) AS total
     FROM expenses e
     LEFT JOIN expense_categories ec ON ec.id = e.category_id
     WHERE e.company_id = :company_id
       AND e.garage_id = :garage_id
       AND e.expense_date BETWEEN :from_date AND :to_date
       AND e.entry_type <> "DELETED"
     GROUP BY category_name
     ORDER BY total DESC'
);
$categoryBreakStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
]);
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

$expenseTotalStmt = db()->prepare(
    'SELECT SUM(amount) AS total
     FROM expenses
     WHERE company_id = :company_id
       AND garage_id = :garage_id
       AND expense_date BETWEEN :from_date AND :to_date
       AND entry_type <> "DELETED"'
);
$expenseTotalStmt->execute([
    'company_id' => $companyId,
    'garage_id' => $garageId,
    'from_date' => $fromDate,
    'to_date' => $toDate,
]);
$expenseTotal = round((float) ($expenseTotalStmt->fetchColumn() ?? 0), 2);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6"><h1>Expenses</h1></div>
                <div class="col-sm-6 text-right">
                    <form class="form-inline" method="get" action="">
                        <input type="date" name="from" value="<?= e($fromDate) ?>" class="form-control form-control-sm mr-2" />
                        <input type="date" name="to" value="<?= e($toDate) ?>" class="form-control form-control-sm mr-2" />
                        <button class="btn btn-sm btn-primary" type="submit">Filter</button>
                    </form>
                </div>
            </div>
        </div>
    </section>
    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-4">
                    <div class="card card-outline card-success">
                        <div class="card-header"><h3 class="card-title">Record Expense</h3></div>
                        <div class="card-body">
                            <?php if ($canManage): ?>
                            <form method="post" class="ajax-form">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="_action" value="record_expense" />
                                <div class="form-group">
                                    <label>Category</label>
                                    <select name="category_id" class="form-control form-control-sm" required>
                                        <option value="">Select category</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?= (int) $category['id'] ?>"><?= e($category['category_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-row">
                                    <div class="form-group col-md-6">
                                        <label>Amount</label>
                                        <input type="number" step="0.01" name="amount" class="form-control form-control-sm" required />
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label>Date</label>
                                        <input type="date" name="expense_date" value="<?= e($today) ?>" class="form-control form-control-sm" />
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group col-md-6">
                                        <label>Payment Mode</label>
                                        <select name="payment_mode" class="form-control form-control-sm">
                                            <?php foreach (finance_payment_modes() as $mode): ?>
                                                <option value="<?= e($mode) ?>"><?= e($mode) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label>Paid To</label>
                                        <input type="text" name="paid_to" class="form-control form-control-sm" />
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Notes</label>
                                    <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="What is this expense for?"></textarea>
                                </div>
                                <button class="btn btn-sm btn-success" type="submit">Save Expense</button>
                            </form>
                            <?php else: ?>
                                <p class="text-muted mb-0">You have view-only access.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card card-outline card-primary">
                        <div class="card-header"><h3 class="card-title">Categories</h3></div>
                        <div class="card-body">
                            <?php if ($canManage): ?>
                            <form method="post" class="ajax-form mb-2">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="_action" value="save_category" />
                                <div class="input-group input-group-sm">
                                    <input type="text" name="category_name" class="form-control" placeholder="New category" required />
                                    <div class="input-group-append">
                                        <button class="btn btn-primary" type="submit">Add</button>
                                    </div>
                                </div>
                            </form>
                            <?php endif; ?>
                            <ul class="list-group list-group-sm">
                                <?php foreach ($categories as $category): ?>
                                    <li class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <strong><?= e($category['category_name']) ?></strong>
                                            <span class="badge badge-light">Garage</span>
                                        </div>
                                        <?php if ($canManage): ?>
                                        <form method="post" class="ajax-form d-flex flex-wrap align-items-center mb-1" style="gap: 6px;">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="_action" value="update_category" />
                                            <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>" />
                                            <input type="text" name="category_name" value="<?= e($category['category_name']) ?>" class="form-control form-control-sm" style="max-width: 160px;" />
                                            <select name="status_code" class="form-control form-control-sm" style="max-width: 120px;">
                                                <option value="ACTIVE" <?= $category['status_code'] === 'ACTIVE' ? 'selected' : '' ?>>Active</option>
                                                <option value="INACTIVE" <?= $category['status_code'] === 'INACTIVE' ? 'selected' : '' ?>>Inactive</option>
                                            </select>
                                            <button class="btn btn-xs btn-outline-primary" type="submit">Update</button>
                                        </form>
                                        <form method="post" class="ajax-form" onsubmit="return confirm('Delete this category?');">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="_action" value="delete_category" />
                                            <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>" />
                                            <button class="btn btn-xs btn-outline-danger" type="submit">Delete</button>
                                        </form>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="col-md-8">
                    <div class="card card-outline card-info">
                        <div class="card-header"><h3 class="card-title">Recent Expenses</h3></div>
                        <div class="card-body table-responsive">
                            <table class="table table-sm table-striped">
                                <thead>
                                  <tr>
                                    <th>Date</th>
                                    <th>Category</th>
                                    <th>Amount</th>
                                    <th>Mode</th>
                                    <th>Paid To</th>
                                    <th>Notes</th>
                                    <th>Status</th>
                                    <th style="min-width: 260px;">Manage</th>
                                  </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($expenses as $expense): ?>
                                        <?php $entryType = (string) ($expense['entry_type'] ?? ''); ?>
                                        <tr>
                                            <td><?= e($expense['expense_date']) ?></td>
                                            <td><?= e($expense['category_name'] ?? 'Uncategorized') ?></td>
                                            <td><?= format_currency((float) $expense['amount']) ?></td>
                                            <td><?= e($expense['payment_mode']) ?></td>
                                            <td><?= e($expense['paid_to'] ?? '') ?></td>
                                            <td><?= e($expense['notes'] ?? '') ?></td>
                                            <td>
                                              <?php if ($entryType === 'EXPENSE'): ?>
                                                <span class="badge badge-success">Expense</span>
                                              <?php elseif ($entryType === 'REVERSAL'): ?>
                                                <span class="badge badge-secondary">Reversal</span>
                                              <?php else: ?>
                                                <span class="badge badge-dark"><?= e($entryType) ?></span>
                                              <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($canManage && $entryType === 'EXPENSE'): ?>
                                                <form method="post" class="ajax-form d-flex flex-wrap align-items-center mb-1" style="gap: 6px;">
                                                    <?= csrf_field(); ?>
                                                    <input type="hidden" name="_action" value="update_expense" />
                                                    <input type="hidden" name="expense_id" value="<?= (int) $expense['id'] ?>" />
                                                    <select name="category_id" class="form-control form-control-sm" style="max-width: 150px;">
                                                        <option value="">Category</option>
                                                        <?php foreach ($categories as $category): ?>
                                                            <option value="<?= (int) $category['id'] ?>" <?= (int) $expense['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['category_name']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <input type="number" step="0.01" name="amount" value="<?= e($expense['amount']) ?>" class="form-control form-control-sm" style="max-width: 110px;" />
                                                    <input type="date" name="expense_date" value="<?= e($expense['expense_date']) ?>" class="form-control form-control-sm" style="max-width: 140px;" />
                                                    <select name="payment_mode" class="form-control form-control-sm" style="max-width: 130px;">
                                                        <?php foreach (finance_payment_modes() as $mode): ?>
                                                            <option value="<?= e($mode) ?>" <?= $expense['payment_mode'] === $mode ? 'selected' : '' ?>><?= e($mode) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <input type="text" name="paid_to" value="<?= e($expense['paid_to'] ?? '') ?>" class="form-control form-control-sm" placeholder="Paid to" style="max-width: 140px;" />
                                                    <input type="text" name="notes" value="<?= e($expense['notes'] ?? '') ?>" class="form-control form-control-sm" placeholder="Notes" style="min-width: 160px;" />
                                                    <button class="btn btn-xs btn-outline-primary" type="submit">Update</button>
                                                </form>
                                                <div class="d-flex flex-wrap" style="gap: 6px;">
                                                  <form method="post" class="ajax-form d-inline">
                                                      <?= csrf_field(); ?>
                                                      <input type="hidden" name="_action" value="reverse_expense" />
                                                      <input type="hidden" name="expense_id" value="<?= (int) $expense['id'] ?>" />
                                                      <input type="hidden" name="reverse_reason" value="Reversed via expense screen" />
                                                      <button class="btn btn-xs btn-outline-secondary" type="submit">Reverse</button>
                                                  </form>
                                                  <form method="post" class="ajax-form d-inline" onsubmit="return confirm('Delete this expense?');">
                                                      <?= csrf_field(); ?>
                                                      <input type="hidden" name="_action" value="delete_expense" />
                                                      <input type="hidden" name="expense_id" value="<?= (int) $expense['id'] ?>" />
                                                      <button class="btn btn-xs btn-outline-danger" type="submit">Delete</button>
                                                  </form>
                                                </div>
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
                                <div class="col-md-4"><strong>Revenue:</strong> <?= format_currency($revenueTotal) ?></div>
                                <div class="col-md-4"><strong>Expenses:</strong> <?= format_currency($expenseTotal) ?></div>
                                <div class="col-md-4"><strong>Net:</strong> <?= format_currency($revenueTotal - $expenseTotal) ?></div>
                            </div>
                            <?php if ($revenueTotal === 0.0 && table_columns('invoices') === []): ?>
                                <p class="text-muted mb-0">Revenue data not available because invoices table is missing.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
