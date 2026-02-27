<?php
declare(strict_types=1);

require_once __DIR__ . '/regression_common.php';

final class RegressionDatasetSeeder
{
    private RegressionHarness $h;

    /** @var array<int,array<string,mixed>> */
    private array $vendors = [];
    /** @var array<int,array<string,mixed>> */
    private array $parts = [];
    /** @var array<int,array<string,mixed>> */
    private array $customers = [];
    /** @var array<int,array<string,mixed>> */
    private array $vehicles = [];
    /** @var array<int,array<string,mixed>> */
    private array $jobs = [];
    /** @var array<int,array<string,mixed>> */
    private array $jobPartLines = [];
    /** @var array<int,array<string,mixed>> */
    private array $jobLaborLines = [];
    /** @var array<int,array<string,mixed>> */
    private array $purchases = [];
    /** @var array<int,array<string,mixed>> */
    private array $purchaseItems = [];
    /** @var array<int,array<string,mixed>> */
    private array $invoices = [];
    /** @var array<int,array<string,mixed>> */
    private array $invoiceItems = [];
    /** @var array<int,array<string,mixed>> */
    private array $invoicePartItemByInvoiceId = [];
    /** @var array<int,array<string,mixed>> */
    private array $payments = [];
    /** @var array<int,array<string,mixed>> */
    private array $advances = [];
    /** @var array<int,array<string,mixed>> */
    private array $advanceAdjustments = [];
    /** @var array<int,array<string,mixed>> */
    private array $returns = [];
    /** @var array<int,array<string,mixed>> */
    private array $returnItems = [];
    /** @var array<int,array<string,mixed>> */
    private array $outsourcedWorks = [];
    /** @var array<int,array<string,mixed>> */
    private array $expenseCategories = [];
    /** @var array<int,array<string,mixed>> */
    private array $expenses = [];
    /** @var array<int,array<string,mixed>> */
    private array $payrollSheets = [];
    /** @var array<int,array<string,mixed>> */
    private array $payrollItems = [];

    public function __construct(RegressionHarness $h)
    {
        $this->h = $h;
    }

    public function run(bool $jsonMode = false): int
    {
        $this->h->logLine('Seeding deterministic regression dataset: ' . $this->h->scopeLabel());
        $this->h->purgeScopeData(true);
        $this->h->ensureCoreMasters();

        $this->seedVendors();
        $this->seedParts();
        $this->seedCustomers();
        $this->seedVehicles();
        $this->seedPurchasesAndStock();
        $this->seedJobsAndOutsourced();
        $this->seedInvoices();
        $this->seedPayments();
        $this->seedAdvancesAndAdjustments();
        $this->seedReturns();
        $this->seedPayroll();
        $this->seedExpenses();

        $this->h->refreshJobAdvanceBalances();
        foreach (array_keys($this->invoices) as $invoiceId) {
            $this->h->recalcInvoicePaymentStatus($invoiceId);
        }
        foreach (array_keys($this->purchases) as $purchaseId) {
            $this->h->recalcPurchasePaymentStatus($purchaseId);
        }

        $countCheck = $this->h->ensureExpectedCounts($this->h->defaultExpectedSeedCounts());
        $validation = $this->h->validateAll();
        $snapshot = $this->h->captureSnapshot();

        $payload = [
            'generated_at' => date('c'),
            'scope' => [
                'company_id' => $this->h->companyId,
                'garage_id' => $this->h->garageId,
                'dataset_tag' => $this->h->datasetTag,
            ],
            'counts' => $countCheck,
            'validation' => $validation,
            'snapshot' => $snapshot,
            'seeded_ids' => $this->seededIdManifest(),
        ];

        $outFile = __DIR__ . '/regression_outputs/seed_regression_dataset.latest.json';
        $this->h->writeJson($outFile, $payload);

        if ($jsonMode) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        } else {
            $this->h->logLine('Seed snapshot written: ' . $outFile);
            $this->h->logLine('Count mismatches: ' . count((array) ($countCheck['mismatches'] ?? [])));
            $this->h->logLine('Validation status: ' . (string) ($validation['status'] ?? 'UNKNOWN'));
            foreach ((array) ($countCheck['actual'] ?? []) as $k => $v) {
                $this->h->logLine(' - ' . $k . ': ' . (int) $v);
            }
        }

        if (!empty($countCheck['mismatches']) || strtoupper((string) ($validation['status'] ?? 'FAIL')) === 'FAIL') {
            return 1;
        }
        return 0;
    }

    private function seededIdManifest(): array
    {
        return [
            'vendors' => array_keys($this->vendors),
            'parts' => array_keys($this->parts),
            'customers' => array_keys($this->customers),
            'vehicles' => array_keys($this->vehicles),
            'job_cards' => array_keys($this->jobs),
            'invoices' => array_keys($this->invoices),
            'payments' => array_keys($this->payments),
            'job_advances' => array_keys($this->advances),
            'advance_adjustments' => array_keys($this->advanceAdjustments),
            'returns_rma' => array_keys($this->returns),
            'purchases' => array_keys($this->purchases),
            'payroll_salary_sheets' => array_keys($this->payrollSheets),
            'payroll_salary_items' => array_keys($this->payrollItems),
            'expenses' => array_keys($this->expenses),
            'outsourced_works' => array_keys($this->outsourcedWorks),
        ];
    }

    private function vendorId(int $i): int { return 910100 + $i; }
    private function partId(int $i): int { return 910200 + $i; }
    private function customerId(int $i): int { return 910300 + $i; }
    private function vehicleId(int $i): int { return 910400 + $i; }
    private function jobId(int $i): int { return 910500 + $i; }
    private function invoiceId(int $i): int { return 910600 + $i; }
    private function paymentId(int $i): int { return 910700 + $i; }
    private function advanceId(int $i): int { return 910800 + $i; }
    private function adjustmentId(int $i): int { return 910900 + $i; }
    private function returnId(int $i): int { return 911000 + $i; }
    private function purchaseId(int $i): int { return 911200 + $i; }

    private function round2(float $v): float { return round($v, 2); }

    /** @return array{cgst:float,sgst:float,igst:float,tax:float,total:float} */
    private function taxBreakup(float $taxable): array
    {
        $taxable = $this->round2($taxable);
        $tax = $this->round2($taxable * 0.18);
        $cgst = $this->round2($tax / 2);
        $sgst = $this->round2($tax - $cgst);
        return [
            'cgst' => $cgst,
            'sgst' => $sgst,
            'igst' => 0.0,
            'tax' => $tax,
            'total' => $this->round2($taxable + $tax),
        ];
    }

    private function seedVendors(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $id = $this->vendorId($i);
            $row = [
                'id' => $id,
                'company_id' => $this->h->companyId,
                'vendor_code' => 'REG-VEN-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'vendor_name' => 'Regression Vendor ' . $i,
                'contact_person' => 'Contact ' . $i,
                'phone' => '+91-3000000' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'email' => 'vendor' . $i . '.' . $this->h->companyId . '@example.test',
                'gstin' => '27AACCV9' . str_pad((string) $i, 4, '0', STR_PAD_LEFT) . 'V1Z' . (($i % 9) + 1),
                'address_line1' => 'Vendor Street ' . $i,
                'city' => 'Pune',
                'state' => 'Maharashtra',
                'pincode' => '4110' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'status_code' => 'ACTIVE',
            ];
            $this->h->upsert('vendors', $row, ['company_id','vendor_code','vendor_name','contact_person','phone','email','gstin','address_line1','city','state','pincode','status_code']);
            $this->vendors[$id] = $row;
        }
    }

    private function seedParts(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $id = $this->partId($i);
            $vendorId = $this->vendorId((($i - 1) % 5) + 1);
            $purchasePrice = $this->round2(100 + ($i * 20));
            $sellingPrice = $this->round2(160 + ($i * 25));
            $row = [
                'id' => $id,
                'company_id' => $this->h->companyId,
                'part_name' => 'Regression Part ' . $i,
                'part_sku' => 'REG-PART-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'hsn_code' => '8708' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'unit' => 'PCS',
                'purchase_price' => $purchasePrice,
                'selling_price' => $sellingPrice,
                'gst_rate' => 18.00,
                'min_stock' => 2.00,
                'is_active' => 1,
                'category_id' => $this->h->tableExists('part_categories') ? 910050 : null,
                'vendor_id' => $vendorId,
                'status_code' => 'ACTIVE',
            ];
            $this->h->upsert('parts', $row, ['company_id','part_name','part_sku','hsn_code','unit','purchase_price','selling_price','gst_rate','min_stock','is_active','category_id','vendor_id','status_code']);
            $this->parts[$id] = $row;
        }
    }

    private function seedCustomers(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $id = $this->customerId($i);
            $row = [
                'id' => $id,
                'company_id' => $this->h->companyId,
                'created_by' => $this->h->adminUserId,
                'full_name' => 'Regression Customer ' . $i,
                'phone' => '+91-4000000' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'email' => 'customer' . $i . '.' . $this->h->companyId . '@example.test',
                'gstin' => null,
                'address_line1' => 'Customer Lane ' . $i,
                'address_line2' => 'Regression Nagar',
                'city' => 'Pune',
                'state' => 'Maharashtra',
                'pincode' => '4111' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'notes' => $this->h->datasetTag . ' seeded customer',
                'is_active' => 1,
                'status_code' => 'ACTIVE',
            ];
            $this->h->upsert('customers', $row, ['company_id','created_by','full_name','phone','email','gstin','address_line1','address_line2','city','state','pincode','notes','is_active','status_code']);
            $this->customers[$id] = $row;
        }
    }

    private function seedVehicles(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $id = $this->vehicleId($i);
            $customerId = $this->customerId((($i - 1) % 5) + 1);
            $row = [
                'id' => $id,
                'company_id' => $this->h->companyId,
                'customer_id' => $customerId,
                'registration_no' => 'MH12REG' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'vehicle_type' => '4W',
                'brand' => 'Brand ' . (($i % 3) + 1),
                'model' => 'Model ' . (($i % 4) + 1),
                'variant' => 'V' . (($i % 2) + 1),
                'fuel_type' => ($i % 4 === 0 ? 'DIESEL' : 'PETROL'),
                'model_year' => 2021 + ($i % 4),
                'color' => ['White','Silver','Grey','Blue','Red'][$i % 5],
                'chassis_no' => 'CHSREG' . str_pad((string) $i, 10, '0', STR_PAD_LEFT),
                'engine_no' => 'ENGREG' . str_pad((string) $i, 10, '0', STR_PAD_LEFT),
                'odometer_km' => 10000 + ($i * 1111),
                'notes' => $this->h->datasetTag . ' seeded vehicle',
                'is_active' => 1,
                'status_code' => 'ACTIVE',
            ];
            $this->h->upsert('vehicles', $row, ['company_id','customer_id','registration_no','vehicle_type','brand','model','variant','fuel_type','model_year','color','chassis_no','engine_no','odometer_km','notes','is_active','status_code']);
            $this->vehicles[$id] = $row;
        }
    }

    private function seedPurchasesAndStock(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $purchaseId = $this->purchaseId($i);
            $partId = $this->partId($i);
            $part = $this->parts[$partId];
            $qty = 25 + $i;
            $unitCost = (float) $part['purchase_price'];
            $taxable = $this->round2($qty * $unitCost);
            $gst = $this->round2($taxable * 0.18);
            $grand = $this->round2($taxable + $gst);
            $purchaseDate = $this->h->datePlus($i);

            $purchase = [
                'id' => $purchaseId,
                'company_id' => $this->h->companyId,
                'garage_id' => $this->h->garageId,
                'vendor_id' => $this->vendorId((($i - 1) % 5) + 1),
                'invoice_number' => 'REG-PUR-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'purchase_date' => $purchaseDate,
                'purchase_source' => 'VENDOR_ENTRY',
                'assignment_status' => 'ASSIGNED',
                'purchase_status' => 'FINALIZED',
                'payment_status' => 'UNPAID',
                'status_code' => 'ACTIVE',
                'taxable_amount' => $taxable,
                'gst_amount' => $gst,
                'grand_total' => $grand,
                'notes' => $this->h->datasetTag . ' purchase ' . $i,
                'created_by' => $this->h->adminUserId,
                'finalized_by' => $this->h->adminUserId,
                'finalized_at' => $this->h->dateTimePlus($i, '11:00:00'),
            ];
            $this->h->insert('purchases', $purchase);
            $this->purchases[$purchaseId] = $purchase;

            $itemId = 912000 + $i;
            $item = [
                'id' => $itemId,
                'purchase_id' => $purchaseId,
                'part_id' => $partId,
                'quantity' => (float) $qty,
                'unit_cost' => $unitCost,
                'gst_rate' => 18.00,
                'taxable_amount' => $taxable,
                'gst_amount' => $gst,
                'total_amount' => $grand,
            ];
            $this->h->insert('purchase_items', $item);
            $this->purchaseItems[$purchaseId] = $item;

            $this->h->recordInventoryMovement([
                'part_id' => $partId,
                'movement_type' => 'IN',
                'quantity' => (float) $qty,
                'reference_type' => 'PURCHASE',
                'reference_id' => $purchaseId,
                'movement_uid' => 'REG-PUR-' . $purchaseId . '-' . $partId,
                'notes' => $this->h->datasetTag . ' purchase stock in',
            ]);

            ledger_post_purchase_finalized($this->h->pdo, $purchase, $this->h->adminUserId);
        }
    }

    private function seedJobsAndOutsourced(): void
    {
        $outsourceJobIndexes = [3, 7, 11];
        $outsourceSeq = 0;

        for ($i = 1; $i <= 20; $i++) {
            $jobId = $this->jobId($i);
            $vehicleId = $this->vehicleId((($i - 1) % 10) + 1);
            $vehicle = $this->vehicles[$vehicleId];
            $customerId = (int) $vehicle['customer_id'];
            $openedDate = $this->h->datePlus(20 + $i);
            $closed = $i <= 15;

            $job = [
                'id' => $jobId,
                'company_id' => $this->h->companyId,
                'garage_id' => $this->h->garageId,
                'job_number' => 'REG-JOB-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'customer_id' => $customerId,
                'vehicle_id' => $vehicleId,
                'odometer_km' => (int) $vehicle['odometer_km'] + (100 * $i),
                'assigned_to' => $this->h->staffUserIds[($i - 1) % count($this->h->staffUserIds)],
                'service_advisor_id' => $this->h->adminUserId,
                'complaint' => $this->h->datasetTag . ' complaint ' . $i,
                'diagnosis' => 'Deterministic diagnosis ' . $i,
                'recommendation_note' => 'Deterministic recommendation ' . $i,
                'status' => $closed ? 'CLOSED' : ($i % 2 === 0 ? 'IN_PROGRESS' : 'OPEN'),
                'priority' => ($i % 4 === 0 ? 'HIGH' : 'MEDIUM'),
                'estimated_cost' => 0.00,
                'opened_at' => $openedDate . ' 09:30:00',
                'completed_at' => $closed ? $openedDate . ' 18:00:00' : null,
                'closed_at' => $closed ? $openedDate . ' 18:15:00' : null,
                'created_by' => $this->h->adminUserId,
                'updated_by' => $this->h->adminUserId,
                'status_code' => 'ACTIVE',
            ];

            $partTaxable = 0.0;
            $partQty = 0.0;
            $partId = null;
            if ($closed) {
                $partId = $this->partId((($i - 1) % 10) + 1);
                $partRow = $this->parts[$partId];
                $partQty = (float) (1 + ($i % 2));
                $partTaxable = $this->round2($partQty * (float) $partRow['selling_price']);
            }

            $laborTaxable = $this->round2(700 + ($i * 35));
            $job['estimated_cost'] = $this->round2($partTaxable + $laborTaxable);
            $job['stock_posted_at'] = $closed ? $openedDate . ' 18:10:00' : null;
            $this->h->insert('job_cards', $job);
            $this->jobs[$jobId] = $job;

            $laborId = 914000 + $i;
            $isOutsourced = in_array($i, $outsourceJobIndexes, true);
            $labor = [
                'id' => $laborId,
                'job_card_id' => $jobId,
                'description' => 'Regression labor line ' . $i,
                'quantity' => 1.00,
                'unit_price' => $laborTaxable,
                'gst_rate' => 18.00,
                'total_amount' => $laborTaxable,
                'service_id' => $this->h->tableExists('services') ? (910070 + (($i - 1) % 3) + 1) : null,
                'execution_type' => $isOutsourced ? 'OUTSOURCED' : 'IN_HOUSE',
                'outsource_vendor_id' => $isOutsourced ? $this->vendorId((($i - 1) % 5) + 1) : null,
                'outsource_partner_name' => $isOutsourced ? ('Regression Vendor Partner ' . $i) : null,
                'outsource_cost' => $isOutsourced ? $this->round2($laborTaxable * 0.60) : 0.00,
                'outsource_expected_return_date' => $isOutsourced ? $this->h->datePlus(21 + $i) : null,
                'outsource_payable_status' => $isOutsourced ? 'UNPAID' : 'PAID',
            ];
            $this->h->insert('job_labor', $labor);
            $this->jobLaborLines[$jobId] = $labor;

            if ($closed && $partId !== null) {
                $partLine = [
                    'id' => 913000 + $i,
                    'job_card_id' => $jobId,
                    'part_id' => $partId,
                    'quantity' => $partQty,
                    'unit_price' => (float) $this->parts[$partId]['selling_price'],
                    'gst_rate' => 18.00,
                    'total_amount' => $partTaxable,
                ];
                $this->h->insert('job_parts', $partLine);
                $this->jobPartLines[$jobId] = $partLine;

                $this->h->recordInventoryMovement([
                    'part_id' => $partId,
                    'movement_type' => 'OUT',
                    'quantity' => $partQty,
                    'reference_type' => 'JOB_CARD',
                    'reference_id' => $jobId,
                    'movement_uid' => 'REG-JOB-' . $jobId . '-' . $partId,
                    'notes' => $this->h->datasetTag . ' job consumption',
                ]);
            }

            if ($isOutsourced) {
                $outsourceSeq++;
                $workId = 911500 + $outsourceSeq;
                $ow = [
                    'id' => $workId,
                    'company_id' => $this->h->companyId,
                    'garage_id' => $this->h->garageId,
                    'job_card_id' => $jobId,
                    'job_labor_id' => $laborId,
                    'vendor_id' => (int) ($labor['outsource_vendor_id'] ?? 0),
                    'partner_name' => (string) ($labor['outsource_partner_name'] ?? 'Regression Partner'),
                    'service_description' => (string) ($labor['description'] ?? 'Outsourced work'),
                    'agreed_cost' => (float) ($labor['outsource_cost'] ?? 0),
                    'expected_return_date' => $this->h->datePlus(22 + $i),
                    'current_status' => $closed ? 'PAYABLE' : 'SENT',
                    'sent_at' => $this->h->dateTimePlus(20 + $i, '12:00:00'),
                    'received_at' => $closed ? $this->h->dateTimePlus(21 + $i, '16:30:00') : null,
                    'verified_at' => $closed ? $this->h->dateTimePlus(21 + $i, '17:00:00') : null,
                    'payable_at' => $closed ? $this->h->dateTimePlus(21 + $i, '17:10:00') : null,
                    'notes' => $this->h->datasetTag . ' outsourced work ' . $outsourceSeq,
                    'status_code' => 'ACTIVE',
                    'created_by' => $this->h->adminUserId,
                    'updated_by' => $this->h->adminUserId,
                ];
                $this->h->insert('outsourced_works', $ow);
                $this->outsourcedWorks[$workId] = $ow;
            }
        }
    }

    private function seedInvoices(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $invoiceId = $this->invoiceId($i);
            $jobId = $this->jobId($i);
            $job = $this->jobs[$jobId];
            $partLine = $this->jobPartLines[$jobId] ?? null;
            $laborLine = $this->jobLaborLines[$jobId];

            $invoiceDate = $this->h->datePlus(45 + $i);
            $serviceTaxable = $this->round2((float) ($laborLine['total_amount'] ?? 0));
            $partsTaxable = $this->round2((float) ($partLine['total_amount'] ?? 0));
            $taxableTotal = $this->round2($serviceTaxable + $partsTaxable);
            $headerTax = $this->taxBreakup($taxableTotal);
            $serviceTax = $this->taxBreakup($serviceTaxable);
            $partsTax = $this->taxBreakup($partsTaxable);

            $invoice = [
                'id' => $invoiceId,
                'company_id' => $this->h->companyId,
                'garage_id' => $this->h->garageId,
                'invoice_number' => 'REG-INV-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'job_card_id' => $jobId,
                'customer_id' => (int) $job['customer_id'],
                'vehicle_id' => (int) $job['vehicle_id'],
                'invoice_date' => $invoiceDate,
                'due_date' => $this->h->datePlus(52 + $i),
                'invoice_status' => 'FINALIZED',
                'subtotal_service' => $serviceTaxable,
                'subtotal_parts' => $partsTaxable,
                'taxable_amount' => $taxableTotal,
                'tax_regime' => 'INTRASTATE',
                'cgst_rate' => $taxableTotal > 0 ? 9.00 : 0.00,
                'sgst_rate' => $taxableTotal > 0 ? 9.00 : 0.00,
                'igst_rate' => 0.00,
                'cgst_amount' => $headerTax['cgst'],
                'sgst_amount' => $headerTax['sgst'],
                'igst_amount' => 0.00,
                'service_tax_amount' => $serviceTax['tax'],
                'parts_tax_amount' => $partsTax['tax'],
                'total_tax_amount' => $headerTax['tax'],
                'gross_total' => $headerTax['total'],
                'round_off' => 0.00,
                'grand_total' => $headerTax['total'],
                'payment_status' => 'UNPAID',
                'payment_mode' => null,
                'notes' => $this->h->datasetTag . ' invoice ' . $i,
                'created_by' => $this->h->adminUserId,
                'financial_year_id' => $this->h->financialYearId,
                'financial_year_label' => $this->h->fyLabel,
                'sequence_number' => $i,
                'finalized_at' => $invoiceDate . ' 19:00:00',
                'finalized_by' => $this->h->adminUserId,
            ];
            $this->h->insert('invoices', $invoice);
            $this->invoices[$invoiceId] = $invoice;

            $laborLineTax = $this->taxBreakup($serviceTaxable);
            $laborItem = [
                'id' => 915000 + (($i - 1) * 2) + 1,
                'invoice_id' => $invoiceId,
                'item_type' => 'LABOR',
                'description' => (string) ($laborLine['description'] ?? ('Regression labor ' . $i)),
                'part_id' => null,
                'service_id' => $laborLine['service_id'] ?? null,
                'hsn_sac_code' => '998714',
                'quantity' => 1.00,
                'unit_price' => $serviceTaxable,
                'gst_rate' => 18.00,
                'cgst_rate' => 9.00,
                'sgst_rate' => 9.00,
                'igst_rate' => 0.00,
                'taxable_value' => $serviceTaxable,
                'cgst_amount' => $laborLineTax['cgst'],
                'sgst_amount' => $laborLineTax['sgst'],
                'igst_amount' => 0.00,
                'tax_amount' => $laborLineTax['tax'],
                'total_value' => $laborLineTax['total'],
            ];
            $this->h->insert('invoice_items', $laborItem);
            $this->invoiceItems[$laborItem['id']] = $laborItem;

            if ($partLine !== null) {
                $partTaxable = $this->round2((float) $partLine['total_amount']);
                $partLineTax = $this->taxBreakup($partTaxable);
                $partId = (int) $partLine['part_id'];
                $partMaster = $this->parts[$partId];
                $partItem = [
                    'id' => 915000 + (($i - 1) * 2) + 2,
                    'invoice_id' => $invoiceId,
                    'item_type' => 'PART',
                    'description' => (string) $partMaster['part_name'],
                    'part_id' => $partId,
                    'service_id' => null,
                    'hsn_sac_code' => (string) ($partMaster['hsn_code'] ?? ''),
                    'quantity' => (float) $partLine['quantity'],
                    'unit_price' => (float) $partLine['unit_price'],
                    'gst_rate' => 18.00,
                    'cgst_rate' => 9.00,
                    'sgst_rate' => 9.00,
                    'igst_rate' => 0.00,
                    'taxable_value' => $partTaxable,
                    'cgst_amount' => $partLineTax['cgst'],
                    'sgst_amount' => $partLineTax['sgst'],
                    'igst_amount' => 0.00,
                    'tax_amount' => $partLineTax['tax'],
                    'total_value' => $partLineTax['total'],
                ];
                $this->h->insert('invoice_items', $partItem);
                $this->invoiceItems[$partItem['id']] = $partItem;
                $this->invoicePartItemByInvoiceId[$invoiceId] = $partItem;
            }

            ledger_post_invoice_finalized($this->h->pdo, $invoice, $this->h->adminUserId);
        }
    }

    private function seedPayments(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $paymentId = $this->paymentId($i);
            $invoiceId = $this->invoiceId($i);
            $invoice = $this->invoices[$invoiceId];
            $grand = (float) $invoice['grand_total'];
            $amount = $i <= 5 ? $grand : $this->round2($grand * 0.50);

            $payment = [
                'id' => $paymentId,
                'invoice_id' => $invoiceId,
                'entry_type' => 'PAYMENT',
                'amount' => $amount,
                'paid_on' => $this->h->datePlus(70 + $i),
                'payment_mode' => ($i % 2 === 0 ? 'UPI' : 'CASH'),
                'reference_no' => 'REG-RCPT-REF-' . $paymentId,
                'receipt_number' => 'REG-RCPT-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'receipt_sequence_number' => $i,
                'receipt_financial_year_label' => $this->h->fyLabel,
                'notes' => $this->h->datasetTag . ' payment ' . $i,
                'outstanding_before' => $grand,
                'outstanding_after' => $this->round2(max($grand - $amount, 0.0)),
                'is_reversed' => 0,
                'received_by' => $this->h->adminUserId,
            ];
            $this->h->insert('payments', $payment);
            $this->payments[$paymentId] = $payment;

            ledger_post_customer_payment($this->h->pdo, $invoice, $payment, $this->h->adminUserId);
            $this->h->recalcInvoicePaymentStatus($invoiceId);
        }
    }

    private function seedAdvancesAndAdjustments(): void
    {
        for ($j = 1; $j <= 5; $j++) {
            $advanceId = $this->advanceId($j);
            $adjustmentId = $this->adjustmentId($j);
            $invoiceId = $this->invoiceId(10 + $j);
            $jobId = $this->jobId(10 + $j);
            $invoice = $this->invoices[$invoiceId];
            $grand = (float) $invoice['grand_total'];
            $advanceAmount = $j <= 2 ? $grand : $this->round2($grand * 0.40);

            $advance = [
                'id' => $advanceId,
                'company_id' => $this->h->companyId,
                'garage_id' => $this->h->garageId,
                'job_card_id' => $jobId,
                'customer_id' => (int) $invoice['customer_id'],
                'receipt_number' => 'REG-ADV-' . str_pad((string) $j, 4, '0', STR_PAD_LEFT),
                'receipt_sequence_number' => $j,
                'receipt_financial_year_label' => $this->h->fyLabel,
                'received_on' => $this->h->datePlus(65 + $j),
                'payment_mode' => ($j % 2 === 0 ? 'UPI' : 'CASH'),
                'reference_no' => 'REG-ADV-REF-' . $advanceId,
                'notes' => $this->h->datasetTag . ' advance ' . $j,
                'advance_amount' => $advanceAmount,
                'adjusted_amount' => 0.00,
                'balance_amount' => $advanceAmount,
                'status_code' => 'ACTIVE',
                'created_by' => $this->h->adminUserId,
                'updated_by' => $this->h->adminUserId,
            ];
            $this->h->insert('job_advances', $advance);
            $this->advances[$advanceId] = $advance;
            ledger_post_advance_received($this->h->pdo, $advance, $this->h->adminUserId);

            $adjustment = [
                'id' => $adjustmentId,
                'company_id' => $this->h->companyId,
                'garage_id' => $this->h->garageId,
                'advance_id' => $advanceId,
                'invoice_id' => $invoiceId,
                'job_card_id' => $jobId,
                'adjusted_amount' => $advanceAmount,
                'adjusted_on' => $this->h->datePlus(75 + $j),
                'notes' => $this->h->datasetTag . ' advance adjustment ' . $j,
                'created_by' => $this->h->adminUserId,
            ];
            $this->h->insert('advance_adjustments', $adjustment);
            $this->advanceAdjustments[$adjustmentId] = $adjustment;
            ledger_post_advance_adjustment($this->h->pdo, $adjustment, $invoice, $this->h->adminUserId);

            $this->h->refreshJobAdvanceBalances();
            $this->h->recalcInvoicePaymentStatus($invoiceId);
        }
    }

    private function seedReturns(): void
    {
        $returnSeq = 0;

        for ($i = 1; $i <= 3; $i++) {
            $returnSeq++;
            $returnId = $this->returnId($returnSeq);
            $invoiceId = $this->invoiceId($i);
            $invoice = $this->invoices[$invoiceId];
            $partItem = $this->invoicePartItemByInvoiceId[$invoiceId];
            $partId = (int) $partItem['part_id'];
            $unitPrice = (float) $partItem['unit_price'];
            $qty = 1.0;
            $taxable = $this->round2($unitPrice * $qty);
            $tax = $this->round2($taxable * 0.18);
            $total = $this->round2($taxable + $tax);

            $rma = [
                'id' => $returnId,
                'company_id' => $this->h->companyId,
                'garage_id' => $this->h->garageId,
                'return_number' => 'REG-RET-C-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'return_sequence_number' => $returnSeq,
                'financial_year_label' => $this->h->fyLabel,
                'return_type' => 'CUSTOMER_RETURN',
                'return_date' => $this->h->datePlus(90 + $i),
                'job_card_id' => (int) $invoice['job_card_id'],
                'invoice_id' => $invoiceId,
                'customer_id' => (int) $invoice['customer_id'],
                'reason_text' => 'Defective part',
                'reason_detail' => 'Deterministic customer return',
                'approval_status' => 'APPROVED',
                'approved_by' => $this->h->adminUserId,
                'approved_at' => $this->h->dateTimePlus(90 + $i, '15:00:00'),
                'taxable_amount' => $taxable,
                'tax_amount' => $tax,
                'total_amount' => $total,
                'notes' => $this->h->datasetTag . ' customer return ' . $i,
                'status_code' => 'ACTIVE',
                'created_by' => $this->h->adminUserId,
                'updated_by' => $this->h->adminUserId,
            ];
            $this->h->insert('returns_rma', $rma);
            $this->returns[$returnId] = $rma;

            $item = [
                'id' => 916000 + $returnSeq,
                'return_id' => $returnId,
                'source_item_id' => (int) $partItem['id'],
                'part_id' => $partId,
                'description' => (string) $partItem['description'],
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'gst_rate' => 18.00,
                'taxable_amount' => $taxable,
                'tax_amount' => $tax,
                'total_amount' => $total,
            ];
            $this->h->insert('return_items', $item);
            $this->returnItems[$item['id']] = $item;

            $this->h->recordInventoryMovement([
                'part_id' => $partId,
                'movement_type' => 'IN',
                'quantity' => $qty,
                'reference_type' => 'ADJUSTMENT',
                'reference_id' => $returnId,
                'movement_uid' => 'REG-RET-C-' . $returnId . '-' . $partId,
                'notes' => $this->h->datasetTag . ' customer return stock',
            ]);

            ledger_post_customer_return_approved($this->h->pdo, $rma, $this->h->adminUserId);
        }

        for ($i = 1; $i <= 2; $i++) {
            $returnSeq++;
            $returnId = $this->returnId($returnSeq);
            $purchaseId = $this->purchaseId($i);
            $purchase = $this->purchases[$purchaseId];
            $purchaseItem = $this->purchaseItems[$purchaseId];
            $partId = (int) $purchaseItem['part_id'];
            $unitCost = (float) $purchaseItem['unit_cost'];
            $qty = 1.0;
            $taxable = $this->round2($unitCost * $qty);
            $tax = $this->round2($taxable * 0.18);
            $total = $this->round2($taxable + $tax);

            $rma = [
                'id' => $returnId,
                'company_id' => $this->h->companyId,
                'garage_id' => $this->h->garageId,
                'return_number' => 'REG-RET-V-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'return_sequence_number' => $returnSeq,
                'financial_year_label' => $this->h->fyLabel,
                'return_type' => 'VENDOR_RETURN',
                'return_date' => $this->h->datePlus(95 + $i),
                'purchase_id' => $purchaseId,
                'vendor_id' => (int) $purchase['vendor_id'],
                'reason_text' => 'Vendor replacement',
                'reason_detail' => 'Deterministic vendor return',
                'approval_status' => 'APPROVED',
                'approved_by' => $this->h->adminUserId,
                'approved_at' => $this->h->dateTimePlus(95 + $i, '14:00:00'),
                'taxable_amount' => $taxable,
                'tax_amount' => $tax,
                'total_amount' => $total,
                'notes' => $this->h->datasetTag . ' vendor return ' . $i,
                'status_code' => 'ACTIVE',
                'created_by' => $this->h->adminUserId,
                'updated_by' => $this->h->adminUserId,
            ];
            $this->h->insert('returns_rma', $rma);
            $this->returns[$returnId] = $rma;

            $item = [
                'id' => 916000 + $returnSeq,
                'return_id' => $returnId,
                'source_item_id' => (int) $purchaseItem['id'],
                'part_id' => $partId,
                'description' => (string) ($this->parts[$partId]['part_name'] ?? ('Part ' . $partId)),
                'quantity' => $qty,
                'unit_price' => $unitCost,
                'gst_rate' => 18.00,
                'taxable_amount' => $taxable,
                'tax_amount' => $tax,
                'total_amount' => $total,
            ];
            $this->h->insert('return_items', $item);
            $this->returnItems[$item['id']] = $item;

            $this->h->recordInventoryMovement([
                'part_id' => $partId,
                'movement_type' => 'OUT',
                'quantity' => $qty,
                'reference_type' => 'ADJUSTMENT',
                'reference_id' => $returnId,
                'movement_uid' => 'REG-RET-V-' . $returnId . '-' . $partId,
                'notes' => $this->h->datasetTag . ' vendor return stock out',
            ]);

            $this->h->postVendorReturnApprovalJournal($rma);
        }
    }

    private function seedPayroll(): void
    {
        $sheetId = 911301;
        $grossTotal = 0.0;
        $deductionTotal = 0.0;
        $payableTotal = 0.0;

        for ($i = 1; $i <= 5; $i++) {
            $itemId = 911310 + $i;
            $userId = $this->h->staffUserIds[$i - 1] ?? $this->h->adminUserId;
            $base = $this->round2(24000 + ($i * 2000));
            $commission = $this->round2(500 + ($i * 100));
            $manualDeduction = $this->round2(300 + ($i * 50));
            $advanceDeduction = $i % 2 === 0 ? 500.00 : 0.00;
            $loanDeduction = $i === 5 ? 700.00 : 0.00;
            $gross = $this->round2($base + $commission);
            $net = $this->round2($gross - $manualDeduction - $advanceDeduction - $loanDeduction);

            $row = [
                'id' => $itemId,
                'sheet_id' => $sheetId,
                'user_id' => $userId,
                'salary_type' => 'MONTHLY',
                'base_amount' => $base,
                'commission_base' => $base,
                'commission_rate' => 0.000,
                'commission_amount' => $commission,
                'overtime_hours' => 0.00,
                'overtime_rate' => null,
                'overtime_amount' => 0.00,
                'advance_deduction' => $advanceDeduction,
                'loan_deduction' => $loanDeduction,
                'manual_deduction' => $manualDeduction,
                'gross_amount' => $gross,
                'net_payable' => $net,
                'paid_amount' => 0.00,
                'deductions_applied' => 1,
                'status' => 'LOCKED',
                'notes' => $this->h->datasetTag . ' payroll item ' . $i,
            ];
            $this->payrollItems[$itemId] = $row;

            $grossTotal = $this->round2($grossTotal + $gross);
            $deductionTotal = $this->round2($deductionTotal + ($gross - $net));
            $payableTotal = $this->round2($payableTotal + $net);
        }

        $sheet = [
            'id' => $sheetId,
            'company_id' => $this->h->companyId,
            'garage_id' => $this->h->garageId,
            'salary_month' => '2026-01',
            'status' => 'LOCKED',
            'total_gross' => $grossTotal,
            'total_deductions' => $deductionTotal,
            'total_payable' => $payableTotal,
            'total_paid' => 0.00,
            'locked_at' => $this->h->dateTimePlus(110, '20:00:00'),
            'locked_by' => $this->h->adminUserId,
            'created_by' => $this->h->adminUserId,
        ];
        $this->h->insert('payroll_salary_sheets', $sheet);
        $this->payrollSheets[$sheetId] = $sheet;

        foreach ($this->payrollItems as $item) {
            $this->h->insert('payroll_salary_items', $item);
        }

        $this->h->postPayrollAccrualJournal($sheetId, $payableTotal, $this->h->datePlus(110), 'Regression payroll accrual 2026-01');
    }

    private function seedExpenses(): void
    {
        $names = ['Utilities', 'Rent', 'Office Supplies', 'Internet', 'Misc'];
        $modes = ['CASH', 'UPI', 'BANK_TRANSFER', 'CARD', 'CHEQUE'];
        for ($i = 1; $i <= 5; $i++) {
            $catId = 911410 + $i;
            $cat = [
                'id' => $catId,
                'company_id' => $this->h->companyId,
                'garage_id' => $this->h->garageId,
                'category_name' => 'Regression ' . $names[$i - 1],
                'status_code' => 'ACTIVE',
                'created_by' => $this->h->adminUserId,
                'updated_by' => $this->h->adminUserId,
            ];
            $this->h->insert('expense_categories', $cat);
            $this->expenseCategories[$catId] = $cat;

            $expenseId = 911400 + $i;
            $expense = [
                'id' => $expenseId,
                'company_id' => $this->h->companyId,
                'garage_id' => $this->h->garageId,
                'category_id' => $catId,
                'expense_date' => $this->h->datePlus(120 + $i),
                'amount' => $this->round2(1200 + ($i * 275)),
                'paid_to' => 'Regression Expense Party ' . $i,
                'payment_mode' => $modes[$i - 1],
                'notes' => $this->h->datasetTag . ' expense ' . $i,
                'source_type' => 'MANUAL',
                'source_id' => null,
                'entry_type' => 'EXPENSE',
                'reversed_expense_id' => null,
                'created_by' => $this->h->adminUserId,
                'updated_by' => $this->h->adminUserId,
            ];
            $this->h->insert('expenses', $expense);
            $this->expenses[$expenseId] = $expense;
            ledger_post_finance_expense_entry($this->h->pdo, $expense, (string) $cat['category_name'], $this->h->adminUserId);
        }
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $jsonMode = in_array('--json', $argv ?? [], true);
    $harness = new RegressionHarness();
    $seeder = new RegressionDatasetSeeder($harness);
    exit($seeder->run($jsonMode));
}
