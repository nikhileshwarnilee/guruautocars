<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/db.php';
$pdo = db();
$tables = ['vendors','parts','customers','vehicles','job_cards','job_parts','job_labor','invoices','invoice_items','payments','job_advances','advance_adjustments','purchases','purchase_items','purchase_payments','expenses','payroll_salary_sheets','payroll_salary_items','payroll_salary_payments','outsourced_works','outsourced_work_payments','returns_rma','return_items','ledger_journals','ledger_entries','garage_inventory','inventory_movements'];
foreach ($tables as $t) {
    $s = $pdo->prepare('SHOW TABLES LIKE :t');
    $s->execute(['t' => $t]);
    if (!$s->fetchColumn()) { echo "## {$t} MISSING\n"; continue; }
    echo "## {$t}\n";
    foreach ($pdo->query('DESCRIBE `'.$t.'`') as $c) {
        $d = $c['Default'];
        if ($d === null) { $d = 'NULL'; }
        echo $c['Field'].'|'.$c['Type'].'|'.$c['Null'].'|'.$c['Key'].'|'.$d.'|'.$c['Extra']."\n";
    }
}
