<?php
declare(strict_types=1);

ini_set('session.save_path', __DIR__ . '/tmp_sessions');
require __DIR__ . '/includes/app.php';

$_SESSION['user_id'] = 1;
$_SESSION['company_id'] = 1;
$_SESSION['role_key'] = 'super_admin';
$_SESSION['active_garage_id'] = 1;

$categoryStmt = db()->prepare(
    'SELECT id
     FROM expense_categories
     WHERE company_id = :company_id
       AND garage_id = :garage_id
       AND status_code = "ACTIVE"
     ORDER BY id ASC
     LIMIT 1'
);
$categoryStmt->execute([
    'company_id' => 1,
    'garage_id' => 1,
]);
$categoryId = (int) ($categoryStmt->fetchColumn() ?: 0);

if ($categoryId <= 0) {
    $insertCategory = db()->prepare(
        'INSERT INTO expense_categories
          (company_id, garage_id, category_name, status_code, created_by)
         VALUES
          (:company_id, :garage_id, :category_name, "ACTIVE", :created_by)'
    );
    $insertCategory->execute([
        'company_id' => 1,
        'garage_id' => 1,
        'category_name' => 'Smoke Expense Category',
        'created_by' => 1,
    ]);
    $categoryId = (int) db()->lastInsertId();
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    '_csrf' => csrf_token(),
    '_action' => 'record_expense',
    'category_id' => (string) $categoryId,
    'amount' => '180.00',
    'expense_date' => date('Y-m-d'),
    'payment_mode' => 'CASH',
    'paid_to' => 'Smoke Vendor',
    'notes' => 'Smoke expense entry',
];

require __DIR__ . '/modules/expenses/index.php';
