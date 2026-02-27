<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/ledger_posting_service.php';

if (!ledger_bootstrap_ready()) {
    fwrite(STDERR, "Ledger bootstrap failed.\n");
    exit(1);
}

$summary = [
    'purchases_finalized' => 0,
    'purchases_deleted_reversed' => 0,
    'purchase_payments' => 0,
    'purchase_payment_reversals' => 0,
    'invoices_finalized' => 0,
    'advance_adjustments' => 0,
    'invoice_payments' => 0,
    'invoice_payment_reversals' => 0,
    'advances_received' => 0,
    'advances_deleted_reversed' => 0,
    'customer_returns_approved' => 0,
    'customer_returns_deleted_reversed' => 0,
    'finance_expenses' => 0,
];

$pdo = db();

// Purchases (finalized, active)
if (table_columns('purchases') !== []) {
    $statusCols = table_columns('purchases');
    $whereDelete = in_array('status_code', $statusCols, true) ? ' AND p.status_code <> "DELETED"' : '';
    $stmt = $pdo->query(
        'SELECT p.*
         FROM purchases p
         WHERE p.purchase_status = "FINALIZED"' . $whereDelete . '
         ORDER BY p.id ASC'
    );
    foreach ($stmt->fetchAll() as $purchase) {
        ledger_post_purchase_finalized($pdo, $purchase, null);
        $summary['purchases_finalized']++;
    }

    // Deleted finalized purchases -> reverse finalization journal.
    if (in_array('status_code', $statusCols, true)) {
        $delStmt = $pdo->query(
            'SELECT p.*
             FROM purchases p
             WHERE p.purchase_status = "FINALIZED"
               AND p.status_code = "DELETED"
             ORDER BY p.id ASC'
        );
        foreach ($delStmt->fetchAll() as $purchase) {
            ledger_reverse_reference(
                $pdo,
                (int) ($purchase['company_id'] ?? 0),
                'PURCHASE_FINALIZE',
                (int) ($purchase['id'] ?? 0),
                'PURCHASE_DELETE_REVERSAL',
                (int) ($purchase['id'] ?? 0),
                date('Y-m-d'),
                'Backfill purchase delete reversal #' . (int) ($purchase['id'] ?? 0),
                null,
                true
            );
            $summary['purchases_deleted_reversed']++;
        }
    }
}

// Purchase payments
if (table_columns('purchase_payments') !== [] && table_columns('purchases') !== []) {
    $payStmt = $pdo->query(
        'SELECT pp.*, p.vendor_id, p.invoice_number
         FROM purchase_payments pp
         INNER JOIN purchases p ON p.id = pp.purchase_id
         WHERE pp.entry_type = "PAYMENT" AND pp.amount > 0
         ORDER BY pp.id ASC'
    );
    foreach ($payStmt->fetchAll() as $row) {
        ledger_post_vendor_payment($pdo, [
            'id' => (int) ($row['purchase_id'] ?? 0),
            'company_id' => (int) ($row['company_id'] ?? 0),
            'garage_id' => (int) ($row['garage_id'] ?? 0),
            'vendor_id' => (int) ($row['vendor_id'] ?? 0),
            'invoice_number' => (string) ($row['invoice_number'] ?? ''),
        ], $row, null);
        $summary['purchase_payments']++;
    }
    $revStmt = $pdo->query(
        'SELECT *
         FROM purchase_payments
         WHERE entry_type = "REVERSAL"
           AND amount < 0
           AND reversed_payment_id IS NOT NULL
         ORDER BY id ASC'
    );
    foreach ($revStmt->fetchAll() as $row) {
        $companyId = (int) ($row['company_id'] ?? 0);
        $reversalId = (int) ($row['id'] ?? 0);
        $originalPaymentId = (int) ($row['reversed_payment_id'] ?? 0);
        if ($companyId <= 0 || $reversalId <= 0 || $originalPaymentId <= 0) {
            continue;
        }
        ledger_reverse_reference(
            $pdo,
            $companyId,
            'PURCHASE_PAYMENT',
            $originalPaymentId,
            'PURCHASE_PAYMENT_REVERSAL',
            $reversalId,
            (string) ($row['payment_date'] ?? date('Y-m-d')),
            'Backfill purchase payment reversal #' . $originalPaymentId,
            null,
            true
        );
        $summary['purchase_payment_reversals']++;
    }
}

// Invoices finalized
if (table_columns('invoices') !== []) {
    $invoiceStmt = $pdo->query(
        'SELECT *
         FROM invoices
         WHERE invoice_status = "FINALIZED"
         ORDER BY id ASC'
    );
    $finalizedInvoices = [];
    foreach ($invoiceStmt->fetchAll() as $invoice) {
        $finalizedInvoices[(int) ($invoice['id'] ?? 0)] = $invoice;
        ledger_post_invoice_finalized($pdo, $invoice, null);
        $summary['invoices_finalized']++;
    }

    if (table_columns('advance_adjustments') !== []) {
        $adjStmt = $pdo->query(
            'SELECT aa.*, i.customer_id, i.company_id AS invoice_company_id, i.garage_id AS invoice_garage_id, i.invoice_date
             FROM advance_adjustments aa
             INNER JOIN invoices i ON i.id = aa.invoice_id
             WHERE i.invoice_status = "FINALIZED"
             ORDER BY aa.id ASC'
        );
        foreach ($adjStmt->fetchAll() as $row) {
            $invoiceId = (int) ($row['invoice_id'] ?? 0);
            $invoice = $finalizedInvoices[$invoiceId] ?? [
                'id' => $invoiceId,
                'company_id' => (int) ($row['invoice_company_id'] ?? 0),
                'garage_id' => (int) ($row['invoice_garage_id'] ?? 0),
                'customer_id' => (int) ($row['customer_id'] ?? 0),
                'invoice_date' => (string) ($row['invoice_date'] ?? date('Y-m-d')),
            ];
            ledger_post_advance_adjustment($pdo, $row, $invoice, null);
            $summary['advance_adjustments']++;
        }
    }
}

// Invoice payments and reversals
if (table_columns('payments') !== [] && table_columns('invoices') !== []) {
    $payStmt = $pdo->query(
        'SELECT p.*, i.company_id, i.garage_id, i.invoice_number, i.customer_id
         FROM payments p
         INNER JOIN invoices i ON i.id = p.invoice_id
         WHERE p.entry_type = "PAYMENT" AND p.amount > 0
         ORDER BY p.id ASC'
    );
    foreach ($payStmt->fetchAll() as $row) {
        ledger_post_customer_payment($pdo, [
            'id' => (int) ($row['invoice_id'] ?? 0),
            'company_id' => (int) ($row['company_id'] ?? 0),
            'garage_id' => (int) ($row['garage_id'] ?? 0),
            'invoice_number' => (string) ($row['invoice_number'] ?? ''),
            'customer_id' => (int) ($row['customer_id'] ?? 0),
        ], $row, null);
        $summary['invoice_payments']++;
    }
    $revStmt = $pdo->query(
        'SELECT p.*, i.company_id
         FROM payments p
         INNER JOIN invoices i ON i.id = p.invoice_id
         WHERE p.entry_type = "REVERSAL"
           AND p.amount < 0
           AND p.reversed_payment_id IS NOT NULL
         ORDER BY p.id ASC'
    );
    foreach ($revStmt->fetchAll() as $row) {
        $companyId = (int) ($row['company_id'] ?? 0);
        $reversalId = (int) ($row['id'] ?? 0);
        $originalPaymentId = (int) ($row['reversed_payment_id'] ?? 0);
        if ($companyId <= 0 || $reversalId <= 0 || $originalPaymentId <= 0) {
            continue;
        }
        ledger_reverse_reference(
            $pdo,
            $companyId,
            'INVOICE_PAYMENT',
            $originalPaymentId,
            'INVOICE_PAYMENT_REVERSAL',
            $reversalId,
            (string) ($row['paid_on'] ?? date('Y-m-d')),
            'Backfill invoice payment reversal #' . $originalPaymentId,
            null,
            true
        );
        $summary['invoice_payment_reversals']++;
    }
}

// Advances
if (table_columns('job_advances') !== []) {
    $advStmt = $pdo->query('SELECT * FROM job_advances ORDER BY id ASC');
    foreach ($advStmt->fetchAll() as $advance) {
        ledger_post_advance_received($pdo, $advance, null);
        $summary['advances_received']++;

        $status = strtoupper(trim((string) ($advance['status_code'] ?? 'ACTIVE')));
        if ($status === 'DELETED') {
            ledger_reverse_reference(
                $pdo,
                (int) ($advance['company_id'] ?? 0),
                'ADVANCE_RECEIVED',
                (int) ($advance['id'] ?? 0),
                'ADVANCE_DELETE_REVERSAL',
                (int) ($advance['id'] ?? 0),
                date('Y-m-d'),
                'Backfill advance delete reversal #' . (int) ($advance['id'] ?? 0),
                null,
                true
            );
            $summary['advances_deleted_reversed']++;
        }
    }
}

// Customer returns approved / deleted
if (table_columns('returns_rma') !== []) {
    $retStmt = $pdo->query(
        'SELECT *
         FROM returns_rma
         WHERE return_type = "CUSTOMER_RETURN"
           AND approval_status IN ("APPROVED", "CLOSED")
         ORDER BY id ASC'
    );
    foreach ($retStmt->fetchAll() as $returnRow) {
        $statusCode = strtoupper(trim((string) ($returnRow['status_code'] ?? 'ACTIVE')));
        ledger_post_customer_return_approved($pdo, $returnRow, null);
        $summary['customer_returns_approved']++;
        if ($statusCode === 'DELETED') {
            ledger_reverse_reference(
                $pdo,
                (int) ($returnRow['company_id'] ?? 0),
                'CUSTOMER_RETURN_APPROVAL',
                (int) ($returnRow['id'] ?? 0),
                'CUSTOMER_RETURN_DELETE_REVERSAL',
                (int) ($returnRow['id'] ?? 0),
                date('Y-m-d'),
                'Backfill customer return delete reversal #' . (int) ($returnRow['id'] ?? 0),
                null,
                true
            );
            $summary['customer_returns_deleted_reversed']++;
        }
    }
}

// Finance expenses (idempotent; purchase-payment source types are skipped internally)
if (table_columns('expenses') !== []) {
    $expenseStmt = $pdo->query(
        'SELECT e.*, ec.category_name
         FROM expenses e
         LEFT JOIN expense_categories ec ON ec.id = e.category_id
         WHERE COALESCE(e.entry_type, "EXPENSE") <> "DELETED"
         ORDER BY e.id ASC'
    );
    foreach ($expenseStmt->fetchAll() as $expense) {
        ledger_post_finance_expense_entry($pdo, $expense, (string) ($expense['category_name'] ?? ''), null);
        $summary['finance_expenses']++;
    }
}

echo "Ledger backfill completed.\n";
foreach ($summary as $key => $value) {
    echo str_pad($key, 30) . ': ' . $value . PHP_EOL;
}

