<?php
declare(strict_types=1);

require_once __DIR__ . '/regression_common.php';

function cur_scope(): array
{
    $dir = dirname(__DIR__) . '/tmp_sessions';
    if (is_dir($dir)) {
        $files = glob($dir . '/sess_*') ?: [];
        usort($files, static fn (string $a, string $b): int => (filemtime($b) <=> filemtime($a)));
        foreach ($files as $f) {
            $raw = @file_get_contents($f);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            if (preg_match('/user_id\|i:(\d+);.*?company_id\|i:(\d+);.*?active_garage_id\|i:(\d+);/s', $raw, $m) === 1) {
                $u = (int) ($m[1] ?? 0);
                $c = (int) ($m[2] ?? 0);
                $g = (int) ($m[3] ?? 0);
                if ($u > 0 && $c > 0 && $g > 0) {
                    return [$u, $c, $g];
                }
            }
        }
    }
    $pdo = db();
    $r = $pdo->query('SELECT id, company_id, primary_garage_id FROM users WHERE is_active=1 ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    return [(int) ($r['id'] ?? 1), (int) ($r['company_id'] ?? 1), (int) ($r['primary_garage_id'] ?? 1)];
}

function n2(float $v): float { return round($v, 2); }
function tax2(float $taxable): array {
    $taxable = n2($taxable);
    $tax = n2($taxable * 0.18);
    $cgst = n2($tax / 2);
    $sgst = n2($tax - $cgst);
    return [$cgst, $sgst, $tax, n2($taxable + $tax)];
}

[$uid, $cid, $gid] = cur_scope();
$tag = 'LIVE-SAMPLE-' . date('YmdHis');
$h = new RegressionHarness([
    'admin_user_id' => $uid,
    'company_id' => $cid,
    'garage_id' => $gid,
    'dataset_tag' => $tag,
    'base_date' => date('Y-m-d', strtotime('-25 days')) ?: date('Y-m-d'),
]);

$h->ensureLedgerBootstrap();
ledger_ensure_default_coa($h->pdo, $cid);

$already = (int) ($h->qv(
    'SELECT COUNT(*) FROM purchases WHERE company_id=:c AND garage_id=:g AND notes LIKE :t',
    ['c' => $cid, 'g' => $gid, 't' => 'LIVE-SAMPLE-%']
) ?? 0);
if ($already > 0) {
    $h->logLine('Sample rows already exist for this scope. Skipping fresh seed.');
    exit(0);
}

$nextId = static function (RegressionHarness $h, string $table, int $floor = 1000000): int {
    $mx = (int) ($h->qv('SELECT COALESCE(MAX(id),0) FROM `' . $table . '`') ?? 0);
    return max($floor, $mx + 1);
};

$fy = ['id' => 0, 'label' => '2025-26'];
if ($h->tableExists('financial_years')) {
    $r = $h->qr('SELECT id,fy_label FROM financial_years WHERE company_id=:c ORDER BY is_default DESC,id DESC LIMIT 1', ['c' => $cid]);
    $fy['id'] = (int) ($r['id'] ?? 0);
    $lbl = trim((string) ($r['fy_label'] ?? ''));
    if ($lbl !== '') $fy['label'] = $lbl;
}

$vid = $nextId($h, 'vendors', 2100000);
$h->insert('vendors', [
    'id' => $vid, 'company_id' => $cid, 'vendor_code' => $tag . '-VEN-1', 'vendor_name' => 'Live Vendor One',
    'contact_person' => 'Live Contact', 'phone' => '+91-777770001', 'email' => 'live.vendor@example.test',
    'gstin' => '27AACCL7001T1Z1', 'address_line1' => 'Live vendor street', 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001', 'status_code' => 'ACTIVE',
]);

$pid = $nextId($h, 'parts', 2110000);
$h->insert('parts', [
    'id' => $pid, 'company_id' => $cid, 'part_name' => 'Live Sample Brake Pad', 'part_sku' => $tag . '-PART-1',
    'hsn_code' => '87089991', 'unit' => 'PCS', 'purchase_price' => 300.00, 'selling_price' => 450.00,
    'gst_rate' => 18.00, 'min_stock' => 2.0, 'is_active' => 1, 'vendor_id' => $vid, 'status_code' => 'ACTIVE',
]);

$cust = $nextId($h, 'customers', 2120000);
$h->insert('customers', [
    'id' => $cust, 'company_id' => $cid, 'created_by' => $uid, 'full_name' => 'Live Sample Customer',
    'phone' => '+91-888880001', 'email' => 'live.customer@example.test', 'address_line1' => 'Live customer lane',
    'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411045', 'notes' => $tag . ' customer',
    'is_active' => 1, 'status_code' => 'ACTIVE',
]);

$veh = $nextId($h, 'vehicles', 2130000);
$h->insert('vehicles', [
    'id' => $veh, 'company_id' => $cid, 'customer_id' => $cust, 'registration_no' => 'MH12LS' . date('His'),
    'vehicle_type' => '4W', 'brand' => 'Hyundai', 'model' => 'Creta', 'variant' => 'SX', 'fuel_type' => 'PETROL',
    'model_year' => 2024, 'color' => 'White', 'chassis_no' => 'CHSLS' . date('His') . '01', 'engine_no' => 'ENGLS' . date('His') . '01',
    'odometer_km' => 18000, 'notes' => $tag . ' vehicle', 'is_active' => 1, 'status_code' => 'ACTIVE',
]);

$pqty = 14.0; $pcost = 300.0; $ptaxable = n2($pqty * $pcost); $pgst = n2($ptaxable * 0.18); $pgrand = n2($ptaxable + $pgst);
$pur = $nextId($h, 'purchases', 2140000);
$purchase = [
    'id' => $pur, 'company_id' => $cid, 'garage_id' => $gid, 'vendor_id' => $vid, 'invoice_number' => $tag . '-PUR-1',
    'purchase_date' => date('Y-m-d', strtotime('-12 days')) ?: date('Y-m-d'),
    'purchase_source' => 'VENDOR_ENTRY', 'assignment_status' => 'ASSIGNED', 'purchase_status' => 'FINALIZED',
    'payment_status' => 'UNPAID', 'status_code' => 'ACTIVE', 'taxable_amount' => $ptaxable, 'gst_amount' => $pgst, 'grand_total' => $pgrand,
    'notes' => $tag . ' purchase', 'created_by' => $uid, 'finalized_by' => $uid, 'finalized_at' => date('Y-m-d H:i:s', strtotime('-12 days 11:30:00')) ?: date('Y-m-d H:i:s'),
];
$h->insert('purchases', $purchase);
$h->insert('purchase_items', [
    'id' => $nextId($h, 'purchase_items', 2150000), 'purchase_id' => $pur, 'part_id' => $pid, 'quantity' => $pqty, 'unit_cost' => $pcost,
    'gst_rate' => 18.0, 'taxable_amount' => $ptaxable, 'gst_amount' => $pgst, 'total_amount' => $pgrand,
]);
$h->recordInventoryMovement([
    'part_id' => $pid, 'movement_type' => 'IN', 'quantity' => $pqty, 'reference_type' => 'PURCHASE', 'reference_id' => $pur,
    'movement_uid' => $tag . '-PUR-MV', 'notes' => $tag . ' purchase stock in', 'created_by' => $uid,
]);
ledger_post_purchase_finalized($h->pdo, $purchase, $uid);

$pp = $nextId($h, 'purchase_payments', 2160000);
$payAmt = 1000.0;
$ppRow = [
    'id' => $pp, 'purchase_id' => $pur, 'company_id' => $cid, 'garage_id' => $gid, 'payment_date' => date('Y-m-d', strtotime('-10 days')) ?: date('Y-m-d'),
    'entry_type' => 'PAYMENT', 'amount' => $payAmt, 'payment_mode' => 'BANK_TRANSFER', 'reference_no' => $tag . '-PPAY-1',
    'notes' => $tag . ' purchase payment', 'reversed_payment_id' => null, 'created_by' => $uid,
];
$h->insert('purchase_payments', $ppRow);
ledger_post_vendor_payment($h->pdo, $purchase, $ppRow, $uid);
$ppRev = $nextId($h, 'purchase_payments', 2160001);
$h->insert('purchase_payments', [
    'id' => $ppRev, 'purchase_id' => $pur, 'company_id' => $cid, 'garage_id' => $gid, 'payment_date' => date('Y-m-d', strtotime('-9 days')) ?: date('Y-m-d'),
    'entry_type' => 'REVERSAL', 'amount' => -$payAmt, 'payment_mode' => 'ADJUSTMENT', 'reference_no' => $tag . '-PPAY-REV-1',
    'notes' => $tag . ' purchase payment reversal audit', 'reversed_payment_id' => $pp, 'created_by' => $uid,
]);
$h->reverseLedgerReference('PURCHASE_PAYMENT', $pp, 'LIVE_SAMPLE_PPAY_REV', $ppRev, date('Y-m-d', strtotime('-9 days')) ?: date('Y-m-d'), 'Live sample purchase payment reversal');
$h->recalcPurchasePaymentStatus($pur);

$job = $nextId($h, 'job_cards', 2170000);
$h->insert('job_cards', [
    'id' => $job, 'company_id' => $cid, 'garage_id' => $gid, 'job_number' => $tag . '-JOB-1', 'customer_id' => $cust, 'vehicle_id' => $veh,
    'odometer_km' => 19500, 'assigned_to' => $uid, 'service_advisor_id' => $uid, 'complaint' => $tag . ' brake noise complaint',
    'diagnosis' => 'Brake pad wear and service needed', 'status' => 'CLOSED', 'priority' => 'MEDIUM', 'estimated_cost' => 0.0,
    'opened_at' => date('Y-m-d H:i:s', strtotime('-8 days 10:00:00')) ?: date('Y-m-d H:i:s'),
    'completed_at' => date('Y-m-d H:i:s', strtotime('-7 days 17:00:00')) ?: date('Y-m-d H:i:s'),
    'closed_at' => date('Y-m-d H:i:s', strtotime('-7 days 17:20:00')) ?: date('Y-m-d H:i:s'),
    'created_by' => $uid, 'updated_by' => $uid, 'status_code' => 'ACTIVE',
]);
$laborTaxable = 1200.0;
$labor = $nextId($h, 'job_labor', 2180000);
$h->insert('job_labor', [
    'id' => $labor, 'job_card_id' => $job, 'description' => 'Brake service labor', 'quantity' => 1.0, 'unit_price' => $laborTaxable, 'gst_rate' => 18.0,
    'total_amount' => $laborTaxable, 'execution_type' => 'OUTSOURCED', 'outsource_vendor_id' => $vid, 'outsource_partner_name' => 'Live Outsource Partner',
    'outsource_cost' => 700.0, 'outsource_expected_return_date' => date('Y-m-d', strtotime('+2 days')) ?: date('Y-m-d'), 'outsource_payable_status' => 'UNPAID',
]);
$h->insert('outsourced_works', [
    'id' => $nextId($h, 'outsourced_works', 2190000), 'company_id' => $cid, 'garage_id' => $gid, 'job_card_id' => $job, 'job_labor_id' => $labor,
    'vendor_id' => $vid, 'partner_name' => 'Live Outsource Partner', 'service_description' => 'Brake caliper machining',
    'agreed_cost' => 700.0, 'expected_return_date' => date('Y-m-d', strtotime('+2 days')) ?: date('Y-m-d'), 'current_status' => 'PAYABLE',
    'sent_at' => date('Y-m-d H:i:s', strtotime('-8 days 13:00:00')) ?: date('Y-m-d H:i:s'),
    'received_at' => date('Y-m-d H:i:s', strtotime('-7 days 12:30:00')) ?: date('Y-m-d H:i:s'),
    'verified_at' => date('Y-m-d H:i:s', strtotime('-7 days 13:00:00')) ?: date('Y-m-d H:i:s'),
    'payable_at' => date('Y-m-d H:i:s', strtotime('-7 days 13:10:00')) ?: date('Y-m-d H:i:s'),
    'notes' => $tag . ' outsourced work', 'status_code' => 'ACTIVE', 'created_by' => $uid, 'updated_by' => $uid,
]);
$partTaxable = 450.0;
$h->insert('job_parts', [
    'id' => $nextId($h, 'job_parts', 2200000), 'job_card_id' => $job, 'part_id' => $pid, 'quantity' => 1.0, 'unit_price' => 450.0, 'gst_rate' => 18.0, 'total_amount' => $partTaxable,
]);
$h->recordInventoryMovement([
    'part_id' => $pid, 'movement_type' => 'OUT', 'quantity' => 1.0, 'reference_type' => 'JOB_CARD', 'reference_id' => $job,
    'movement_uid' => $tag . '-JOB-MV', 'notes' => $tag . ' job stock out', 'created_by' => $uid,
]);

$invTaxable = n2($laborTaxable + $partTaxable); [$cg,$sg,$itax,$igrand] = tax2($invTaxable);
$inv = $nextId($h, 'invoices', 2210000);
$invoice = [
    'id' => $inv, 'company_id' => $cid, 'garage_id' => $gid, 'invoice_number' => $tag . '-INV-1', 'job_card_id' => $job, 'customer_id' => $cust, 'vehicle_id' => $veh,
    'invoice_date' => date('Y-m-d', strtotime('-6 days')) ?: date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+4 days')) ?: date('Y-m-d'),
    'invoice_status' => 'FINALIZED', 'subtotal_service' => $laborTaxable, 'subtotal_parts' => $partTaxable, 'taxable_amount' => $invTaxable, 'tax_regime' => 'INTRASTATE',
    'cgst_rate' => 9.0, 'sgst_rate' => 9.0, 'igst_rate' => 0.0, 'cgst_amount' => $cg, 'sgst_amount' => $sg, 'igst_amount' => 0.0,
    'service_tax_amount' => n2($laborTaxable * 0.18), 'parts_tax_amount' => n2($partTaxable * 0.18), 'total_tax_amount' => $itax, 'gross_total' => $igrand, 'round_off' => 0.0,
    'grand_total' => $igrand, 'payment_status' => 'UNPAID', 'notes' => $tag . ' invoice', 'created_by' => $uid,
    'financial_year_id' => $fy['id'] > 0 ? $fy['id'] : null, 'financial_year_label' => $fy['label'], 'sequence_number' => 9991,
    'finalized_at' => date('Y-m-d H:i:s', strtotime('-6 days 19:00:00')) ?: date('Y-m-d H:i:s'), 'finalized_by' => $uid,
];
$h->insert('invoices', $invoice);
ledger_post_invoice_finalized($h->pdo, $invoice, $uid);
[$lcg,$lsg,$ltax,$ltotal] = tax2($laborTaxable);
$h->insert('invoice_items', [
    'id' => $nextId($h, 'invoice_items', 2220000), 'invoice_id' => $inv, 'item_type' => 'LABOR', 'description' => 'Brake labor',
    'hsn_sac_code' => '998714', 'quantity' => 1.0, 'unit_price' => $laborTaxable, 'gst_rate' => 18.0, 'cgst_rate' => 9.0, 'sgst_rate' => 9.0, 'igst_rate' => 0.0,
    'taxable_value' => $laborTaxable, 'cgst_amount' => $lcg, 'sgst_amount' => $lsg, 'igst_amount' => 0.0, 'tax_amount' => $ltax, 'total_value' => $ltotal,
]);
[$pcg,$psg,$ptax,$ptotal] = tax2($partTaxable);
$h->insert('invoice_items', [
    'id' => $nextId($h, 'invoice_items', 2220001), 'invoice_id' => $inv, 'item_type' => 'PART', 'description' => 'Brake pad',
    'part_id' => $pid, 'hsn_sac_code' => '87089991', 'quantity' => 1.0, 'unit_price' => 450.0, 'gst_rate' => 18.0, 'cgst_rate' => 9.0, 'sgst_rate' => 9.0, 'igst_rate' => 0.0,
    'taxable_value' => $partTaxable, 'cgst_amount' => $pcg, 'sgst_amount' => $psg, 'igst_amount' => 0.0, 'tax_amount' => $ptax, 'total_value' => $ptotal,
]);

$invPay = $nextId($h, 'payments', 2230000);
$invPayRow = [
    'id' => $invPay, 'invoice_id' => $inv, 'entry_type' => 'PAYMENT', 'amount' => 800.0, 'paid_on' => date('Y-m-d', strtotime('-5 days')) ?: date('Y-m-d'),
    'payment_mode' => 'CASH', 'reference_no' => $tag . '-PAY-1', 'receipt_number' => $tag . '-RCPT-1', 'receipt_sequence_number' => 9991,
    'receipt_financial_year_label' => $fy['label'], 'notes' => $tag . ' invoice payment', 'received_by' => $uid, 'is_reversed' => 0,
];
$h->insert('payments', $invPayRow);
ledger_post_customer_payment($h->pdo, $invoice, $invPayRow, $uid);
$h->recalcInvoicePaymentStatus($inv);

$adv = $nextId($h, 'job_advances', 2240000);
$advAmt = 300.0;
$advRow = [
    'id' => $adv, 'company_id' => $cid, 'garage_id' => $gid, 'job_card_id' => $job, 'customer_id' => $cust,
    'receipt_number' => $tag . '-ADV-1', 'receipt_sequence_number' => 9991, 'receipt_financial_year_label' => $fy['label'],
    'received_on' => date('Y-m-d', strtotime('-4 days')) ?: date('Y-m-d'), 'payment_mode' => 'UPI', 'reference_no' => $tag . '-ADV-REF-1',
    'notes' => $tag . ' advance', 'advance_amount' => $advAmt, 'adjusted_amount' => 0.0, 'balance_amount' => $advAmt,
    'status_code' => 'ACTIVE', 'created_by' => $uid, 'updated_by' => $uid,
];
$h->insert('job_advances', $advRow);
ledger_post_advance_received($h->pdo, $advRow, $uid);
$adj = $nextId($h, 'advance_adjustments', 2240100);
$adjRow = [
    'id' => $adj, 'company_id' => $cid, 'garage_id' => $gid, 'advance_id' => $adv, 'invoice_id' => $inv, 'job_card_id' => $job,
    'adjusted_amount' => $advAmt, 'adjusted_on' => date('Y-m-d', strtotime('-3 days')) ?: date('Y-m-d'),
    'notes' => $tag . ' advance adjustment', 'created_by' => $uid,
];
$h->insert('advance_adjustments', $adjRow);
ledger_post_advance_adjustment($h->pdo, $adjRow, $invoice, $uid);
$h->refreshJobAdvanceBalances();
$h->recalcInvoicePaymentStatus($inv);

$retC = $nextId($h, 'returns_rma', 2250000);
$rtax = 450.0; [$rcg,$rsg,$rt,$rtot] = tax2($rtax);
$retCrow = [
    'id' => $retC, 'company_id' => $cid, 'garage_id' => $gid, 'return_number' => $tag . '-RET-C-1', 'return_sequence_number' => 9991, 'financial_year_label' => $fy['label'],
    'return_type' => 'CUSTOMER_RETURN', 'return_date' => date('Y-m-d', strtotime('-2 days')) ?: date('Y-m-d'), 'job_card_id' => $job, 'invoice_id' => $inv, 'customer_id' => $cust,
    'reason_text' => 'Live sample customer return', 'reason_detail' => 'For module visibility', 'approval_status' => 'APPROVED',
    'approved_by' => $uid, 'approved_at' => date('Y-m-d H:i:s') ?: null, 'taxable_amount' => $rtax, 'tax_amount' => $rt, 'total_amount' => $rtot,
    'notes' => $tag . ' customer return', 'status_code' => 'ACTIVE', 'created_by' => $uid, 'updated_by' => $uid,
];
$h->insert('returns_rma', $retCrow);
$h->insert('return_items', [
    'id' => $nextId($h, 'return_items', 2250100), 'return_id' => $retC, 'source_item_id' => 0, 'part_id' => $pid, 'description' => 'Returned brake pad',
    'quantity' => 1.0, 'unit_price' => $rtax, 'gst_rate' => 18.0, 'taxable_amount' => $rtax, 'tax_amount' => $rt, 'total_amount' => $rtot,
]);
$h->recordInventoryMovement([
    'part_id' => $pid, 'movement_type' => 'IN', 'quantity' => 1.0, 'reference_type' => 'ADJUSTMENT', 'reference_id' => $retC,
    'movement_uid' => $tag . '-RET-C-MV', 'notes' => $tag . ' customer return stock', 'created_by' => $uid,
]);
ledger_post_customer_return_approved($h->pdo, $retCrow, $uid);

$retV = $nextId($h, 'returns_rma', 2250200);
$vtax = 300.0; [$vcg,$vsg,$vt,$vtot] = tax2($vtax);
$retVrow = [
    'id' => $retV, 'company_id' => $cid, 'garage_id' => $gid, 'return_number' => $tag . '-RET-V-1', 'return_sequence_number' => 9992, 'financial_year_label' => $fy['label'],
    'return_type' => 'VENDOR_RETURN', 'return_date' => date('Y-m-d', strtotime('-1 days')) ?: date('Y-m-d'), 'purchase_id' => $pur, 'vendor_id' => $vid,
    'reason_text' => 'Live sample vendor return', 'reason_detail' => 'For module visibility', 'approval_status' => 'APPROVED',
    'approved_by' => $uid, 'approved_at' => date('Y-m-d H:i:s') ?: null, 'taxable_amount' => $vtax, 'tax_amount' => $vt, 'total_amount' => $vtot,
    'notes' => $tag . ' vendor return', 'status_code' => 'ACTIVE', 'created_by' => $uid, 'updated_by' => $uid,
];
$h->insert('returns_rma', $retVrow);
$h->insert('return_items', [
    'id' => $nextId($h, 'return_items', 2250300), 'return_id' => $retV, 'source_item_id' => 0, 'part_id' => $pid, 'description' => 'Vendor returned brake pad',
    'quantity' => 1.0, 'unit_price' => $vtax, 'gst_rate' => 18.0, 'taxable_amount' => $vtax, 'tax_amount' => $vt, 'total_amount' => $vtot,
]);
$h->recordInventoryMovement([
    'part_id' => $pid, 'movement_type' => 'OUT', 'quantity' => 1.0, 'reference_type' => 'ADJUSTMENT', 'reference_id' => $retV,
    'movement_uid' => $tag . '-RET-V-MV', 'notes' => $tag . ' vendor return stock', 'created_by' => $uid,
]);
$h->postVendorReturnApprovalJournal($retVrow);

$sheet = $nextId($h, 'payroll_salary_sheets', 2260000);
$item = $nextId($h, 'payroll_salary_items', 2260100);
$gross = 26000.0; $ded = 1200.0; $pay = 24800.0;
$h->insert('payroll_salary_sheets', [
    'id' => $sheet, 'company_id' => $cid, 'garage_id' => $gid, 'salary_month' => date('Y-m', strtotime('-1 month')) ?: date('Y-m'),
    'status' => 'LOCKED', 'total_gross' => $gross, 'total_deductions' => $ded, 'total_payable' => $pay, 'total_paid' => 0.0,
    'locked_at' => date('Y-m-d H:i:s') ?: null, 'locked_by' => $uid, 'created_by' => $uid,
]);
$h->insert('payroll_salary_items', [
    'id' => $item, 'sheet_id' => $sheet, 'user_id' => $uid, 'salary_type' => 'MONTHLY', 'base_amount' => 25000.0, 'commission_base' => 25000.0,
    'commission_rate' => 0.0, 'commission_amount' => 1000.0, 'overtime_hours' => 0.0, 'overtime_amount' => 0.0, 'advance_deduction' => 500.0,
    'loan_deduction' => 400.0, 'manual_deduction' => 300.0, 'gross_amount' => $gross, 'net_payable' => $pay, 'paid_amount' => 0.0,
    'deductions_applied' => 1, 'status' => 'LOCKED', 'notes' => $tag . ' payroll',
]);
$h->postPayrollAccrualJournal($sheet, $pay, date('Y-m-d'), $tag . ' payroll accrual');

$cat = $nextId($h, 'expense_categories', 2270000);
$h->insert('expense_categories', [
    'id' => $cat, 'company_id' => $cid, 'garage_id' => $gid, 'category_name' => 'Live Sample Utilities', 'status_code' => 'ACTIVE', 'created_by' => $uid, 'updated_by' => $uid,
]);
$exp = $nextId($h, 'expenses', 2270100);
$expRow = [
    'id' => $exp, 'company_id' => $cid, 'garage_id' => $gid, 'category_id' => $cat, 'expense_date' => date('Y-m-d') ?: null, 'amount' => 900.0,
    'paid_to' => 'Live Utility Vendor', 'payment_mode' => 'UPI', 'notes' => $tag . ' expense', 'source_type' => 'MANUAL', 'source_id' => null,
    'entry_type' => 'EXPENSE', 'reversed_expense_id' => null, 'created_by' => $uid, 'updated_by' => $uid,
];
$h->insert('expenses', $expRow);
ledger_post_finance_expense_entry($h->pdo, $expRow, 'Live Sample Utilities', $uid);

$h->writeJson(__DIR__ . '/regression_outputs/seed_current_scope_sample_data.latest.json', [
    'generated_at' => date('c'),
    'scope' => ['company_id' => $cid, 'garage_id' => $gid, 'user_id' => $uid],
    'dataset_tag' => $tag,
    'counts' => $h->countOperationalRows(),
]);
$h->logLine('Seeded sample data for current scope company=' . $cid . ' garage=' . $gid . ' tag=' . $tag);

