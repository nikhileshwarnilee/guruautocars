<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/permission_sync.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$json = in_array('--json', $argv, true);
$pdo = db();
$root = dirname(__DIR__);

function qv(PDO $pdo, string $sql, array $p = []): mixed {
    $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn();
}
function qa(PDO $pdo, string $sql, array $p = []): array {
    $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function qr(PDO $pdo, string $sql, array $p = []): array {
    $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetch(PDO::FETCH_ASSOC); return is_array($r) ? $r : [];
}
function te(PDO $pdo, string $t): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) return false;
    try { $s = $pdo->prepare('SHOW TABLES LIKE :t'); $s->execute(['t' => $t]); return (bool) $s->fetchColumn(); } catch (Throwable) { return false; }
}
function ix(PDO $pdo, string $t, string $i): bool {
    return (bool) qv($pdo, 'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i LIMIT 1', ['t' => $t, 'i' => $i]);
}
function ck(array &$a, string $id, string $label, string $status, mixed $value = null, ?string $note = null, array $sample = []): void {
    $row = ['id' => $id, 'label' => $label, 'status' => strtoupper($status)];
    if ($value !== null) $row['value'] = $value;
    if ($note !== null && $note !== '') $row['note'] = $note;
    if ($sample !== []) $row['sample'] = $sample;
    $a[] = $row;
}
function rankS(string $s): int { return match (strtoupper($s)) { 'FAIL' => 3, 'WARN' => 2, default => 1 }; }
function secS(array $c): string { $r = 1; foreach ($c as $x) $r = max($r, rankS((string) ($x['status'] ?? 'PASS'))); return $r === 3 ? 'FAIL' : ($r === 2 ? 'WARN' : 'PASS'); }
function mods(string $root): array {
    $dir = $root . DIRECTORY_SEPARATOR . 'modules';
    $out = [];
    foreach ((array) scandir($dir) as $m) {
        if ($m === '.' || $m === '..' || !is_dir($dir . DIRECTORY_SEPARATOR . $m)) continue;
        $n = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir . DIRECTORY_SEPARATOR . $m, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) if ($f instanceof SplFileInfo && $f->isFile() && str_ends_with(strtolower($f->getFilename()), '.php')) $n++;
        $out[] = ['module' => $m, 'php_files' => $n];
    }
    usort($out, static fn ($a, $b) => strcmp((string) $a['module'], (string) $b['module']));
    return $out;
}

$modules = mods($root);
$tables = qa($pdo, 'SELECT TABLE_NAME AS table_name, COALESCE(TABLE_ROWS,0) AS table_rows FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME');
$tablesByRows = $tables; usort($tablesByRows, static fn ($a, $b) => ((int) ($b['table_rows'] ?? 0)) <=> ((int) ($a['table_rows'] ?? 0)));
$fks = qa($pdo, 'SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME, ORDINAL_POSITION FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION');
$permKeys = permission_sync_collect_code_keys($root);

$r = [
    'generated_at' => date('c'),
    'database' => DB_NAME,
    'discovery' => [
        'module_count' => count($modules),
        'modules' => $modules,
        'table_count' => count($tables),
        'top_tables_by_rows' => array_slice($tablesByRows, 0, 12),
        'fk_count' => count($fks),
        'permission_keys_in_code_count' => count($permKeys),
    ],
    'integrity' => ['title' => 'Integrity Checks', 'checks' => []],
    'cross' => ['title' => 'Report Cross-Verification', 'checks' => []],
    'performance' => ['title' => 'Performance & Index Checks', 'checks' => []],
    'summary' => [],
];

$ic = [];

// FK orphan sweep (single-column constraints only, grouped by constraint)
$fkGroups = [];
foreach ($fks as $fk) {
    $k = (string) ($fk['TABLE_NAME'] ?? '') . '|' . (string) ($fk['CONSTRAINT_NAME'] ?? '');
    if (!isset($fkGroups[$k])) $fkGroups[$k] = [];
    $fkGroups[$k][] = $fk;
}
$fkFail = 0; $fkSkip = 0; $fkSample = [];
foreach ($fkGroups as $rows) {
    if (count($rows) !== 1) { $fkSkip++; continue; }
    $x = $rows[0];
    $ct = (string) ($x['TABLE_NAME'] ?? ''); $cc = (string) ($x['COLUMN_NAME'] ?? '');
    $pt = (string) ($x['REFERENCED_TABLE_NAME'] ?? ''); $pc = (string) ($x['REFERENCED_COLUMN_NAME'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $ct . $cc . $pt . $pc)) { $fkSkip++; continue; }
    $c = (int) (qv($pdo, 'SELECT COUNT(*) FROM `' . $ct . '` c LEFT JOIN `' . $pt . '` p ON c.`' . $cc . '` = p.`' . $pc . '` WHERE c.`' . $cc . '` IS NOT NULL AND p.`' . $pc . '` IS NULL') ?? 0);
    if ($c > 0) { $fkFail++; if (count($fkSample) < 8) $fkSample[] = ['constraint' => (string) ($x['CONSTRAINT_NAME'] ?? ''), 'table' => $ct, 'count' => $c]; }
}
ck($ic, 'fk_orphans', 'Foreign key orphan rows', $fkFail === 0 ? 'PASS' : 'FAIL', $fkFail, $fkSkip > 0 ? ('skipped composite/invalid=' . $fkSkip) : null, $fkSample);

if (te($pdo, 'inventory_movements')) {
    $dup = (int) (qv($pdo, 'SELECT COUNT(*) FROM (SELECT movement_uid FROM inventory_movements WHERE movement_uid IS NOT NULL AND TRIM(movement_uid)<>"" GROUP BY movement_uid HAVING COUNT(*)>1) x') ?? 0);
    ck($ic, 'dup_movement_uid', 'Duplicate inventory movement_uid', $dup === 0 ? 'PASS' : 'FAIL', $dup);
}

if (te($pdo, 'garage_inventory') && te($pdo, 'inventory_movements')) {
    $stockMis = qa($pdo, 'SELECT gi.garage_id, gi.part_id, ROUND(gi.quantity,2) AS garage_qty, ROUND(COALESCE(SUM(CASE WHEN im.movement_type="IN" THEN ABS(im.quantity) WHEN im.movement_type="OUT" THEN -ABS(im.quantity) ELSE im.quantity END),0),2) AS movement_qty FROM garage_inventory gi LEFT JOIN inventory_movements im ON im.garage_id=gi.garage_id AND im.part_id=gi.part_id GROUP BY gi.garage_id,gi.part_id,gi.quantity HAVING ABS(garage_qty-movement_qty)>0.01 LIMIT 10');
    ck($ic, 'stock_mismatch', 'garage_inventory vs inventory_movements mismatches', $stockMis === [] ? 'PASS' : 'FAIL', count($stockMis), null, $stockMis);
    $neg = (int) (qv($pdo, 'SELECT COUNT(*) FROM garage_inventory WHERE quantity < -0.01') ?? 0);
    ck($ic, 'negative_stock', 'Negative stock rows', $neg === 0 ? 'PASS' : 'WARN', $neg);
}

if (te($pdo, 'purchases') && te($pdo, 'purchase_items')) {
    $ptm = (int) (qv($pdo, 'SELECT COUNT(*) FROM (SELECT p.id FROM purchases p JOIN purchase_items pi ON pi.purchase_id=p.id WHERE COALESCE(p.status_code,"ACTIVE")<>"DELETED" GROUP BY p.id,p.taxable_amount,p.gst_amount,p.grand_total HAVING ABS(COALESCE(SUM(pi.taxable_amount),0)-p.taxable_amount)>0.05 OR ABS(COALESCE(SUM(pi.gst_amount),0)-p.gst_amount)>0.05 OR ABS(COALESCE(SUM(pi.total_amount),0)-p.grand_total)>0.05) x') ?? 0);
    ck($ic, 'purchase_totals', 'Purchase header totals mismatch vs purchase_items', $ptm === 0 ? 'PASS' : 'FAIL', $ptm);
}

if (te($pdo, 'purchases') && te($pdo, 'purchase_payments')) {
    $psm = (int) (qv($pdo, 'SELECT COUNT(*) FROM (SELECT p.id, UPPER(COALESCE(p.payment_status,"")) s, CASE WHEN p.grand_total<=0 THEN "PAID" WHEN COALESCE(SUM(pp.amount),0)<=0.009 THEN "UNPAID" WHEN COALESCE(SUM(pp.amount),0)+0.009>=p.grand_total THEN "PAID" ELSE "PARTIAL" END c FROM purchases p LEFT JOIN purchase_payments pp ON pp.purchase_id=p.id WHERE COALESCE(p.status_code,"ACTIVE")<>"DELETED" GROUP BY p.id,p.payment_status,p.grand_total HAVING s<>c) x') ?? 0);
    ck($ic, 'purchase_payment_status', 'Purchase payment_status mismatch vs purchase_payments', $psm === 0 ? 'PASS' : 'FAIL', $psm);
}

if (te($pdo, 'invoices') && te($pdo, 'payments')) {
    $advJoin = te($pdo, 'advance_adjustments')
        ? 'LEFT JOIN (SELECT invoice_id, COALESCE(SUM(adjusted_amount),0) a FROM advance_adjustments GROUP BY invoice_id) adv ON adv.invoice_id=i.id'
        : '';
    $advExpr = te($pdo, 'advance_adjustments') ? 'COALESCE(adv.a,0)' : '0';
    $ips = (int) (qv($pdo, 'SELECT COUNT(*) FROM (SELECT i.id, UPPER(COALESCE(i.payment_status,"")) s, CASE WHEN i.invoice_status="CANCELLED" THEN "CANCELLED" WHEN (COALESCE(p.p,0)+' . $advExpr . ')<=0.009 THEN "UNPAID" WHEN (COALESCE(p.p,0)+' . $advExpr . ')+0.009>=i.grand_total THEN "PAID" ELSE "PARTIAL" END c FROM invoices i LEFT JOIN (SELECT invoice_id, COALESCE(SUM(amount),0) p FROM payments GROUP BY invoice_id) p ON p.invoice_id=i.id ' . $advJoin . ') x WHERE s<>c') ?? 0);
    ck($ic, 'invoice_payment_status', 'Invoice payment_status mismatch vs payments + advances', $ips === 0 ? 'PASS' : 'FAIL', $ips);
}

if (te($pdo, 'audit_logs')) {
    $als = (int) (qv($pdo, 'SELECT COUNT(*) FROM audit_logs WHERE (company_id IS NULL OR garage_id IS NULL) AND NOT (module_name="auth" AND action_name="login_failed")') ?? 0);
    ck($ic, 'audit_scope_non_auth', 'Audit logs missing scope (excluding auth.login_failed)', $als === 0 ? 'PASS' : 'WARN', $als);
}

$r['integrity']['checks'] = $ic;
$r['integrity']['status'] = secS($ic);

$xc = [];
if (te($pdo, 'garage_inventory') && te($pdo, 'inventory_movements')) {
    $invT = qr($pdo, 'SELECT ROUND(COALESCE((SELECT SUM(quantity) FROM garage_inventory),0),2) AS garage_inventory_qty, ROUND(COALESCE((SELECT SUM(sq) FROM (SELECT COALESCE(SUM(CASE WHEN movement_type="IN" THEN ABS(quantity) WHEN movement_type="OUT" THEN -ABS(quantity) ELSE quantity END),0) sq FROM inventory_movements GROUP BY garage_id,part_id) m),0),2) AS movement_qty');
    $d = round((float) ($invT['garage_inventory_qty'] ?? 0) - (float) ($invT['movement_qty'] ?? 0), 2);
    ck($xc, 'inventory_qty_total', 'Inventory total qty snapshot (garage_inventory vs movement aggregate)', abs($d) <= 0.01 ? 'PASS' : 'FAIL', $invT, 'difference=' . number_format($d, 2));
}

if (te($pdo, 'invoices') && te($pdo, 'payments') && te($pdo, 'advance_adjustments')) {
    $so = qr($pdo, 'SELECT ROUND(COALESCE(SUM(GREATEST(i.grand_total-COALESCE(p.p,0),0)),0),2) AS outstanding_without_advances, ROUND(COALESCE(SUM(GREATEST(i.grand_total-COALESCE(p.p,0)-COALESCE(a.a,0),0)),0),2) AS outstanding_with_advances, ROUND(COALESCE(SUM(LEAST(COALESCE(p.p,0), i.grand_total)),0),2) AS collected_without_advances, ROUND(COALESCE(SUM(LEAST(COALESCE(p.p,0)+COALESCE(a.a,0), i.grand_total)),0),2) AS collected_with_advances FROM invoices i LEFT JOIN (SELECT invoice_id,COALESCE(SUM(amount),0) p FROM payments GROUP BY invoice_id) p ON p.invoice_id=i.id LEFT JOIN (SELECT invoice_id,COALESCE(SUM(adjusted_amount),0) a FROM advance_adjustments GROUP BY invoice_id) a ON a.invoice_id=i.id WHERE i.invoice_status="FINALIZED"');
    $do = round((float) ($so['outstanding_without_advances'] ?? 0) - (float) ($so['outstanding_with_advances'] ?? 0), 2);
    $dc = round((float) ($so['collected_with_advances'] ?? 0) - (float) ($so['collected_without_advances'] ?? 0), 2);
    ck($xc, 'sales_advance_impact', 'Sales outstanding/collection delta caused by advance adjustments', (abs($do) <= 0.01 && abs($dc) <= 0.01) ? 'PASS' : 'WARN', $so, 'delta_outstanding=' . number_format($do, 2) . ', delta_collected=' . number_format($dc, 2));
}

if (te($pdo, 'invoices')) {
    $gstS = qr($pdo, 'SELECT COUNT(*) invoice_count, ROUND(COALESCE(SUM(taxable_amount),0),2) taxable_total, ROUND(COALESCE(SUM(cgst_amount),0),2) cgst_total, ROUND(COALESCE(SUM(sgst_amount),0),2) sgst_total, ROUND(COALESCE(SUM(igst_amount),0),2) igst_total, ROUND(COALESCE(SUM(grand_total),0),2) grand_total FROM invoices WHERE invoice_status="FINALIZED"');
    ck($xc, 'gst_sales_snapshot', 'GST sales aggregate snapshot (finalized invoices)', 'PASS', $gstS);
}

if (te($pdo, 'purchases')) {
    $gstP = qr($pdo, 'SELECT COUNT(*) purchase_count, ROUND(COALESCE(SUM(taxable_amount),0),2) taxable_total, ROUND(COALESCE(SUM(gst_amount),0),2) gst_total, ROUND(COALESCE(SUM(grand_total),0),2) grand_total FROM purchases WHERE purchase_status="FINALIZED" AND COALESCE(status_code,"ACTIVE")<>"DELETED"');
    ck($xc, 'gst_purchase_snapshot', 'GST purchase aggregate snapshot (finalized purchases)', 'PASS', $gstP);
}

$r['cross']['checks'] = $xc;
$r['cross']['status'] = secS($xc);

$pc = [];
$idxMap = [
    'inventory_movements' => ['uniq_inventory_movement_uid', 'idx_inventory_garage_part', 'idx_inventory_reference'],
    'payments' => ['idx_payments_invoice_entry', 'idx_payments_reversed', 'idx_payments_analytics_scope'],
    'purchase_payments' => ['idx_purchase_payments_scope_date', 'idx_purchase_payments_reversed'],
    'advance_adjustments' => ['idx_advance_adj_invoice', 'uniq_invoice_advance'],
];
foreach ($idxMap as $t => $idxs) {
    if (!te($pdo, $t)) { ck($pc, 'idx_' . $t, 'Key indexes on ' . $t, 'WARN', 'table_missing'); continue; }
    $miss = [];
    foreach ($idxs as $i) if (!ix($pdo, $t, $i)) $miss[] = $i;
    ck($pc, 'idx_' . $t, 'Key indexes on ' . $t, $miss === [] ? 'PASS' : 'WARN', $miss === [] ? 'all_present' : $miss);
}
$rows = [];
foreach (['audit_logs','garage_inventory','inventory_movements','parts','vehicles','purchases','purchase_payments','invoices','payments','job_cards'] as $t) if (te($pdo, $t)) $rows[$t] = (int) (qv($pdo, 'SELECT COUNT(*) FROM `' . $t . '`') ?? 0);
ck($pc, 'row_counts', 'Operational row count snapshot', 'PASS', $rows);

$r['performance']['checks'] = $pc;
$r['performance']['status'] = secS($pc);

$all = array_merge($ic, $xc, $pc);
$pass = $warn = $fail = 0;
foreach ($all as $c) {
    $s = strtoupper((string) ($c['status'] ?? 'PASS'));
    if ($s === 'FAIL') $fail++; elseif ($s === 'WARN') $warn++; else $pass++;
}
$overall = secS([['status' => $r['integrity']['status']], ['status' => $r['cross']['status']], ['status' => $r['performance']['status']]]);
$reco = [];
if (($rows['invoices'] ?? 0) === 0 || ($rows['job_cards'] ?? 0) === 0) $reco[] = 'Current DB has low transactional coverage (jobs/invoices). Run CRUD/browser regression on a seeded staging clone.';
$reco[] = 'Run `php database/full_system_audit.php` after migrations and before release cutover.';
$reco[] = 'Keep receivables/outstanding reports advance-aware (payments + advance_adjustments).';
$reco[] = 'Consider adding customer ledger entries for advance receipt/deletion events for full ledger continuity.';
$r['summary'] = ['overall_status' => $overall, 'integrity_status' => $r['integrity']['status'], 'cross_verification_status' => $r['cross']['status'], 'performance_status' => $r['performance']['status'], 'issue_count' => $fail, 'warning_count' => $warn, 'pass_count' => $pass, 'recommendations' => $reco];

if ($json) {
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($overall === 'FAIL' ? 1 : 0);
}

echo "Full System Autonomous Audit\n";
echo "==========================\n";
echo 'Generated: ' . $r['generated_at'] . "\n";
echo 'Database:  ' . $r['database'] . "\n";
echo 'Overall:   ' . $r['summary']['overall_status'] . "\n\n";
echo "Discovery\n--------\n";
echo 'Modules=' . (int) $r['discovery']['module_count'] . ' Tables=' . (int) $r['discovery']['table_count'] . ' FKs=' . (int) $r['discovery']['fk_count'] . ' PermissionKeys=' . (int) $r['discovery']['permission_keys_in_code_count'] . "\n";
foreach ((array) $r['discovery']['top_tables_by_rows'] as $t) echo '- ' . (string) ($t['table_name'] ?? '') . ': ' . (int) ($t['table_rows'] ?? 0) . "\n";
echo "\n";
foreach (['integrity','cross','performance'] as $k) {
    echo $r[$k]['title'] . "\n";
    echo str_repeat('-', strlen((string) $r[$k]['title'])) . "\n";
    foreach ((array) $r[$k]['checks'] as $c) {
        echo '[' . (string) ($c['status'] ?? 'INFO') . '] ' . (string) ($c['label'] ?? $c['id'] ?? 'check');
        if (array_key_exists('value', $c)) echo ': ' . (is_scalar($c['value']) || $c['value'] === null ? (string) $c['value'] : json_encode($c['value']));
        if (!empty($c['note'])) echo ' | ' . (string) $c['note'];
        echo "\n";
    }
    echo 'Section status: ' . (string) ($r[$k]['status'] ?? 'UNKNOWN') . "\n\n";
}
echo "Summary\n-------\n";
echo 'Issues=' . $fail . ' Warnings=' . $warn . ' Pass=' . $pass . "\n";
echo "Recommendations\n---------------\n";
foreach ($reco as $x) echo '- ' . $x . "\n";
exit($overall === 'FAIL' ? 1 : 0);
