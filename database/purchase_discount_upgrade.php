<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

function purchase_discount_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :table_name');
        $stmt->execute(['table_name' => $table]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

function purchase_discount_add_column_if_missing(PDO $pdo, string $table, string $column, string $sqlType): bool
{
    $columns = table_columns($table);
    if ($columns === [] || in_array($column, $columns, true)) {
        return false;
    }

    $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $sqlType);
    return true;
}

$pdo = db();

if (!purchase_discount_table_exists($pdo, 'purchases')) {
    echo "Purchases table not found. Run purchase module setup first.\n";
    exit(1);
}

$changes = [];
if (purchase_discount_add_column_if_missing($pdo, 'purchases', 'gross_total', 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `gst_amount`')) {
    $changes[] = 'gross_total';
}
if (purchase_discount_add_column_if_missing($pdo, 'purchases', 'discount_type', 'VARCHAR(20) NOT NULL DEFAULT "AMOUNT" AFTER `gross_total`')) {
    $changes[] = 'discount_type';
}
if (purchase_discount_add_column_if_missing($pdo, 'purchases', 'discount_value', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `discount_type`')) {
    $changes[] = 'discount_value';
}
if (purchase_discount_add_column_if_missing($pdo, 'purchases', 'discount_amount', 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `discount_value`')) {
    $changes[] = 'discount_amount';
}

$backfillCount = (int) $pdo->exec(
    'UPDATE purchases p
     LEFT JOIN (
       SELECT purchase_id, ROUND(COALESCE(SUM(total_amount), 0), 2) AS line_gross_total
       FROM purchase_items
       GROUP BY purchase_id
     ) li ON li.purchase_id = p.id
     SET p.gross_total = ROUND(GREATEST(COALESCE(NULLIF(p.gross_total, 0), COALESCE(li.line_gross_total, p.grand_total)), p.grand_total), 2),
         p.discount_type = CASE
           WHEN UPPER(COALESCE(p.discount_type, "AMOUNT")) IN ("AMOUNT", "PERCENT")
             THEN UPPER(COALESCE(p.discount_type, "AMOUNT"))
           ELSE "AMOUNT"
         END,
         p.discount_value = ROUND(GREATEST(COALESCE(p.discount_value, 0), 0), 2),
         p.discount_amount = ROUND(LEAST(
           GREATEST(COALESCE(p.discount_amount, 0), 0),
           GREATEST(COALESCE(NULLIF(p.gross_total, 0), COALESCE(li.line_gross_total, p.grand_total)), p.grand_total)
         ), 2)'
);

echo "Purchase Discount Upgrade\n";
echo "=========================\n";
if ($changes === []) {
    echo "No schema changes needed.\n";
} else {
    echo "Added columns: " . implode(', ', $changes) . "\n";
}
echo "Rows normalized: " . $backfillCount . "\n";
echo "Done.\n";
exit(0);
