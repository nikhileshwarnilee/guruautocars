<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/modules/jobs/workflow.php';
require_once __DIR__ . '/modules/billing/workflow.php';

final class IndianDemoDataSeeder
{
    private PDO $pdo;
    private int $companyId = 1;
    private int $actorUserId = 1;
    private string $startDate;
    private string $endDate;
    private int $startTs;
    private int $endTs;
    private string $adminPasswordHash;

    /** @var array<string,int> */
    private array $garageIds = [];
    /** @var array<string,int> */
    private array $roleIds = [];
    /** @var array<string,int> */
    private array $userIds = [];
    /** @var array<int,array<string,mixed>> */
    private array $customerRows = [];
    /** @var array<int,array<string,mixed>> */
    private array $vehicleRows = [];
    /** @var array<string,int> */
    private array $serviceCategoryIds = [];
    /** @var array<string,array<string,mixed>> */
    private array $serviceRows = [];
    /** @var array<string,int> */
    private array $partCategoryIds = [];
    /** @var array<int,array<string,mixed>> */
    private array $partRows = [];
    /** @var array<int,array<string,mixed>> */
    private array $vendorRows = [];
    /** @var array<int,array<string,mixed>> */
    private array $variantRows = [];
    /** @var array<int,string> */
    private array $modelByVariantId = [];
    /** @var array<int,array<string,mixed>> */
    private array $jobRows = [];
    /** @var array<int,int> */
    private array $jobGarage = [];
    /** @var array<int,int> */
    private array $jobVehicle = [];
    /** @var array<int,array<string,mixed>> */
    private array $invoiceRows = [];
    /** @var array<int,array<string,mixed>> */
    private array $purchaseRows = [];
    /** @var array<int,array<string,mixed>> */
    private array $outsourcedRows = [];

    /** @var array<int,float> */
    private array $lastOdometerByVehicle = [];
    /** @var array<int,array<int,float>> */
    private array $inventoryByGaragePart = [];

    /** @var array<int,float> */
    private array $advanceOutstandingByUser = [];
    /** @var array<int,array<string,mixed>> */
    private array $loanByUser = [];

    /** @var array<int,float> */
    private array $paidInvoiceAmountById = [];
    /** @var array<int,float> */
    private array $paidPurchaseAmountById = [];
    /** @var array<int,float> */
    private array $paidOutsourcedAmountById = [];

    /** @var array<int,array<string,mixed>> */
    private array $estimateRows = [];

    public function __construct()
    {
        $this->pdo = db();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->startDate = date('Y-m-d', strtotime('-6 months +2 days'));
        $this->endDate = date('Y-m-d', strtotime('-2 days'));
        $this->startTs = strtotime($this->startDate . ' 09:00:00') ?: time();
        $this->endTs = strtotime($this->endDate . ' 19:30:00') ?: time();
        $this->adminPasswordHash = $this->resolveAdminPasswordHash();
        mt_srand(20260213);
    }

    public function run(): void
    {
        $this->bootstrapSession();
        $this->resetDatabaseForDemo();
        $this->seedOrganizationAndUsers();
        $this->seedVehicleMastersAndVis();
        $this->seedCustomersAndVehicles();
        $this->seedServiceCatalog();
        $this->seedVendors();
        $this->seedPartsAndInventoryMasters();
        $this->seedVisMappings();
        $this->seedPurchasesAndStock();
        $this->seedTemporaryStock();
        $this->seedStockTransfers();
        $this->seedJobsAndOutsourcedFlows();
        $this->seedInvoicesAndCollections();
        $this->seedEstimates();
        $this->seedPayrollAndStaffFinance();
        $this->seedManualExpenses();
        $this->runIntegrityChecks();
        $this->printSummary();
    }

    private function bootstrapSession(): void
    {
        $_SESSION['user_id'] = $this->actorUserId;
        $_SESSION['company_id'] = $this->companyId;
        $_SESSION['active_garage_id'] = 1;
        $_SESSION['role_key'] = 'super_admin';
    }

    private function resolveAdminPasswordHash(): string
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT password_hash
                 FROM users
                 WHERE username = :username
                 LIMIT 1'
            );
            $stmt->execute(['username' => 'admin']);
            $hash = (string) ($stmt->fetchColumn() ?: '');
            if ($hash !== '') {
                return $hash;
            }
        } catch (Throwable $exception) {
            // Fallback below.
        }

        return password_hash('admin123', PASSWORD_BCRYPT);
    }

    private function resetDatabaseForDemo(): void
    {
        $tablesToClear = [
            'backup_integrity_checks',
            'backup_runs',
            'data_export_logs',
            'audit_logs',
            'job_issues',
            'invoice_payment_history',
            'invoice_status_history',
            'payments',
            'invoice_items',
            'invoices',
            'invoice_number_sequences',
            'invoice_counters',
            'job_history',
            'job_assignments',
            'job_parts',
            'job_labor',
            'job_cards',
            'job_counters',
            'estimate_history',
            'estimate_parts',
            'estimate_services',
            'estimates',
            'estimate_counters',
            'outsourced_work_payments',
            'outsourced_work_history',
            'outsourced_works',
            'purchase_payments',
            'purchase_items',
            'purchases',
            'inventory_transfers',
            'inventory_movements',
            'garage_inventory',
            'temp_stock_events',
            'temp_stock_entries',
            'vis_service_part_map',
            'vis_part_compatibility',
            'vis_variant_specs',
            'vis_variants',
            'vis_models',
            'vis_brands',
            'vehicle_history',
            'customer_history',
            'vehicles',
            'customers',
            'parts',
            'part_categories',
            'services',
            'service_categories',
            'vendors',
            'payroll_salary_payments',
            'payroll_salary_items',
            'payroll_salary_sheets',
            'payroll_loan_payments',
            'payroll_loans',
            'payroll_advances',
            'payroll_salary_structures',
            'expenses',
            'expense_categories',
            'system_settings',
            'financial_years',
            'user_garages',
            'users',
            'garages',
            'companies',
            'vehicle_variants',
            'vehicle_models',
            'vehicle_brands',
            'vehicle_model_years',
            'vehicle_colors',
        ];

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tablesToClear as $tableName) {
            $this->pdo->exec('TRUNCATE TABLE `' . $tableName . '`');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function seedOrganizationAndUsers(): void
    {
        $this->insert('companies', [
            'id' => 1,
            'name' => 'Shree Guru Auto Mobility',
            'legal_name' => 'Shree Guru Auto Mobility Private Limited',
            'gstin' => '27AAXCG5123D1Z8',
            'pan' => 'AAXCG5123D',
            'phone' => '+91-20-67676767',
            'email' => 'info@guruautomobility.in',
            'address_line1' => 'Plot 18, Wakad Road',
            'address_line2' => 'Near Hinjawadi Flyover',
            'city' => 'Pune',
            'state' => 'Maharashtra',
            'pincode' => '411057',
            'status' => 'active',
            'status_code' => 'ACTIVE',
        ]);

        $this->insert('garages', [
            'id' => 1,
            'company_id' => 1,
            'name' => 'Guru Auto Cars Pune',
            'code' => 'PUNE',
            'phone' => '+91-20-66554411',
            'email' => 'pune@guruautomobility.in',
            'gstin' => '27AAXCG5123D1Z8',
            'address_line1' => 'Survey 42, Baner Road',
            'address_line2' => 'Opposite Balewadi Stadium',
            'city' => 'Pune',
            'state' => 'Maharashtra',
            'pincode' => '411045',
            'status' => 'active',
            'status_code' => 'ACTIVE',
        ]);
        $this->insert('garages', [
            'id' => 2,
            'company_id' => 1,
            'name' => 'Guru Auto Cars Mumbai',
            'code' => 'MUMBAI',
            'phone' => '+91-22-45556677',
            'email' => 'mumbai@guruautomobility.in',
            'gstin' => '27AAXCG5123D2Z6',
            'address_line1' => 'LBS Marg Service Lane',
            'address_line2' => 'Near Mulund Check Naka',
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'pincode' => '400080',
            'status' => 'active',
            'status_code' => 'ACTIVE',
        ]);
        $this->garageIds = ['pune' => 1, 'mumbai' => 2];

        $this->seedFinancialYears();
        $this->seedLogoAndSettings();
        $this->seedRoleIfMissing('helper', 'Helper');

        $roles = $this->pdo->query('SELECT id, role_key FROM roles WHERE status_code = "ACTIVE"')->fetchAll();
        foreach ($roles as $role) {
            $this->roleIds[(string) $role['role_key']] = (int) $role['id'];
        }

        $this->insert('users', [
            'id' => 1,
            'company_id' => 1,
            'role_id' => $this->roleIds['super_admin'] ?? 1,
            'primary_garage_id' => 1,
            'name' => 'System Admin',
            'email' => 'admin@guruautocars.in',
            'username' => 'admin',
            'password_hash' => $this->adminPasswordHash,
            'phone' => '+91-9000000000',
            'is_active' => 1,
            'status_code' => 'ACTIVE',
        ]);

        $staffSeed = [
            [
                'key' => 'owner',
                'role' => 'garage_owner',
                'garage' => 1,
                'name' => 'Rajesh Kulkarni',
                'email' => 'rajesh.kulkarni@guruautomobility.in',
                'username' => 'rajesh.owner',
                'phone' => '+91-9822012310',
            ],
            [
                'key' => 'manager',
                'role' => 'manager',
                'garage' => 1,
                'name' => 'Sneha Patil',
                'email' => 'sneha.patil@guruautomobility.in',
                'username' => 'sneha.manager',
                'phone' => '+91-9881122334',
            ],
            [
                'key' => 'mechanic_pune',
                'role' => 'mechanic',
                'garage' => 1,
                'name' => 'Amit Jadhav',
                'email' => 'amit.jadhav@guruautomobility.in',
                'username' => 'amit.mech',
                'phone' => '+91-9767011223',
            ],
            [
                'key' => 'mechanic_mumbai_1',
                'role' => 'mechanic',
                'garage' => 2,
                'name' => 'Imran Shaikh',
                'email' => 'imran.shaikh@guruautomobility.in',
                'username' => 'imran.mech',
                'phone' => '+91-9768899011',
            ],
            [
                'key' => 'mechanic_mumbai_2',
                'role' => 'mechanic',
                'garage' => 2,
                'name' => 'Prakash More',
                'email' => 'prakash.more@guruautomobility.in',
                'username' => 'prakash.mech',
                'phone' => '+91-9892255510',
            ],
            [
                'key' => 'accountant',
                'role' => 'accountant',
                'garage' => 1,
                'name' => 'Meenal Deshmukh',
                'email' => 'meenal.deshmukh@guruautomobility.in',
                'username' => 'meenal.accounts',
                'phone' => '+91-9922334411',
            ],
            [
                'key' => 'helper',
                'role' => 'helper',
                'garage' => 2,
                'name' => 'Raju Pawar',
                'email' => 'raju.pawar@guruautomobility.in',
                'username' => 'raju.helper',
                'phone' => '+91-9819345612',
            ],
        ];

        foreach ($staffSeed as $staff) {
            $newUserId = $this->insert('users', [
                'company_id' => 1,
                'role_id' => $this->roleIds[(string) $staff['role']] ?? ($this->roleIds['mechanic'] ?? 4),
                'primary_garage_id' => (int) $staff['garage'],
                'name' => (string) $staff['name'],
                'email' => (string) $staff['email'],
                'username' => (string) $staff['username'],
                'password_hash' => password_hash('Demo@123', PASSWORD_BCRYPT),
                'phone' => (string) $staff['phone'],
                'is_active' => 1,
                'status_code' => 'ACTIVE',
            ]);
            $this->userIds[(string) $staff['key']] = $newUserId;
        }
        $this->userIds['admin'] = 1;

        foreach ($this->userIds as $userId) {
            $this->insert('user_garages', ['user_id' => $userId, 'garage_id' => 1]);
            $this->insert('user_garages', ['user_id' => $userId, 'garage_id' => 2]);
        }

        $this->actorUserId = $this->userIds['owner'] ?? 1;
        $this->bootstrapSession();
    }

    private function seedFinancialYears(): void
    {
        $years = [
            ['label' => '2024-25', 'start' => '2024-04-01', 'end' => '2025-03-31', 'default' => 0],
            ['label' => '2025-26', 'start' => '2025-04-01', 'end' => '2026-03-31', 'default' => 1],
            ['label' => '2026-27', 'start' => '2026-04-01', 'end' => '2027-03-31', 'default' => 0],
        ];
        foreach ($years as $year) {
            $this->insert('financial_years', [
                'company_id' => 1,
                'fy_label' => (string) $year['label'],
                'start_date' => (string) $year['start'],
                'end_date' => (string) $year['end'],
                'is_default' => (int) $year['default'],
                'status_code' => 'ACTIVE',
                'created_by' => null,
            ]);
        }
    }

    private function seedLogoAndSettings(): void
    {
        $logoDir = __DIR__ . '/assets/uploads/company_logos';
        if (!is_dir($logoDir)) {
            mkdir($logoDir, 0777, true);
        }
        $logoRelativePath = 'assets/uploads/company_logos/company_1_demo_logo.png';
        $logoFsPath = __DIR__ . '/' . $logoRelativePath;
        $logoSource = __DIR__ . '/assets/images/AdminLTEFullLogo.png';
        if (is_file($logoSource)) {
            @copy($logoSource, $logoFsPath);
        }

        system_setting_upsert_value(1, null, 'BUSINESS', 'business_logo_path', $logoRelativePath, 'STRING', 'ACTIVE', null);
        system_setting_upsert_value(1, null, 'BILLING', 'invoice_prefix', 'GAC', 'STRING', 'ACTIVE', null);
        system_setting_upsert_value(1, 1, 'BILLING', 'invoice_prefix', 'PUN', 'STRING', 'ACTIVE', null);
        system_setting_upsert_value(1, 2, 'BILLING', 'invoice_prefix', 'MUM', 'STRING', 'ACTIVE', null);
        system_setting_upsert_value(1, null, 'REPORTS', 'default_date_filter_mode', 'monthly', 'STRING', 'ACTIVE', null);
    }

    private function seedRoleIfMissing(string $roleKey, string $roleName): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE role_key = :role_key LIMIT 1');
        $stmt->execute(['role_key' => $roleKey]);
        if ($stmt->fetch()) {
            return;
        }

        $this->insert('roles', [
            'role_key' => $roleKey,
            'role_name' => $roleName,
            'description' => $roleName,
            'is_system' => 0,
            'status_code' => 'ACTIVE',
        ]);
    }

    private function seedVehicleMastersAndVis(): void
    {
        for ($year = 2015; $year <= 2026; $year++) {
            $this->insert('vehicle_model_years', [
                'year_value' => $year,
                'source_code' => 'MANUAL',
                'status_code' => 'ACTIVE',
            ]);
        }

        $colors = ['White', 'Silver', 'Grey', 'Black', 'Blue', 'Red', 'Brown', 'Pearl White', 'Wine Red', 'Urban Bronze'];
        foreach ($colors as $colorName) {
            $this->insert('vehicle_colors', [
                'color_name' => $colorName,
                'source_code' => 'MANUAL',
                'status_code' => 'ACTIVE',
            ]);
        }

        $catalog = [
            [
                'brand' => 'Maruti Suzuki',
                'models' => [
                    ['name' => 'Baleno', 'variants' => [['name' => 'Sigma', 'fuel' => 'PETROL'], ['name' => 'Alpha', 'fuel' => 'PETROL']]],
                    ['name' => 'Swift', 'variants' => [['name' => 'VXI', 'fuel' => 'PETROL'], ['name' => 'ZDI', 'fuel' => 'DIESEL']]],
                ],
            ],
            [
                'brand' => 'Hyundai',
                'models' => [
                    ['name' => 'Creta', 'variants' => [['name' => 'SX', 'fuel' => 'PETROL'], ['name' => 'SX(O)', 'fuel' => 'DIESEL']]],
                ],
            ],
            [
                'brand' => 'Tata',
                'models' => [
                    ['name' => 'Nexon', 'variants' => [['name' => 'XZ+', 'fuel' => 'PETROL'], ['name' => 'XZ+ Dark', 'fuel' => 'DIESEL']]],
                ],
            ],
            [
                'brand' => 'Mahindra',
                'models' => [
                    ['name' => 'XUV700', 'variants' => [['name' => 'AX5', 'fuel' => 'PETROL'], ['name' => 'AX7', 'fuel' => 'DIESEL']]],
                ],
            ],
            [
                'brand' => 'Honda',
                'models' => [
                    ['name' => 'City', 'variants' => [['name' => 'V', 'fuel' => 'PETROL'], ['name' => 'ZX', 'fuel' => 'PETROL']]],
                ],
            ],
            [
                'brand' => 'Toyota',
                'models' => [
                    ['name' => 'Innova', 'variants' => [['name' => 'GX', 'fuel' => 'DIESEL'], ['name' => 'VX', 'fuel' => 'DIESEL']]],
                ],
            ],
            [
                'brand' => 'Kia',
                'models' => [
                    ['name' => 'Seltos', 'variants' => [['name' => 'HTK+', 'fuel' => 'PETROL'], ['name' => 'GTX+', 'fuel' => 'DIESEL']]],
                ],
            ],
        ];

        foreach ($catalog as $brandData) {
            $visBrandId = $this->insert('vis_brands', [
                'brand_name' => (string) $brandData['brand'],
                'status_code' => 'ACTIVE',
            ]);
            $vehicleBrandId = $this->insert('vehicle_brands', [
                'brand_name' => (string) $brandData['brand'],
                'vis_brand_id' => $visBrandId,
                'source_code' => 'VIS',
                'status_code' => 'ACTIVE',
            ]);

            foreach ((array) $brandData['models'] as $modelData) {
                $visModelId = $this->insert('vis_models', [
                    'brand_id' => $visBrandId,
                    'model_name' => (string) $modelData['name'],
                    'vehicle_type' => '4W',
                    'status_code' => 'ACTIVE',
                ]);
                $vehicleModelId = $this->insert('vehicle_models', [
                    'brand_id' => $vehicleBrandId,
                    'model_name' => (string) $modelData['name'],
                    'vehicle_type' => '4W',
                    'vis_model_id' => $visModelId,
                    'source_code' => 'VIS',
                    'status_code' => 'ACTIVE',
                ]);

                foreach ((array) $modelData['variants'] as $variantData) {
                    $variantName = (string) $variantData['name'];
                    $fuelType = (string) $variantData['fuel'];
                    $visVariantId = $this->insert('vis_variants', [
                        'model_id' => $visModelId,
                        'variant_name' => $variantName,
                        'fuel_type' => $fuelType,
                        'engine_cc' => $this->randomEngineCcForModel((string) $modelData['name']),
                        'status_code' => 'ACTIVE',
                    ]);
                    $vehicleVariantId = $this->insert('vehicle_variants', [
                        'model_id' => $vehicleModelId,
                        'variant_name' => $variantName,
                        'fuel_type' => $fuelType,
                        'engine_cc' => $this->randomEngineCcForModel((string) $modelData['name']),
                        'vis_variant_id' => $visVariantId,
                        'source_code' => 'VIS',
                        'status_code' => 'ACTIVE',
                    ]);

                    $this->insert('vis_variant_specs', [
                        'variant_id' => $visVariantId,
                        'spec_key' => 'Transmission',
                        'spec_value' => $this->pick(['Manual', 'Automatic', 'CVT']),
                        'status_code' => 'ACTIVE',
                    ]);
                    $this->insert('vis_variant_specs', [
                        'variant_id' => $visVariantId,
                        'spec_key' => 'Segment',
                        'spec_value' => $this->pick(['Hatchback', 'Sedan', 'SUV', 'MPV']),
                        'status_code' => 'ACTIVE',
                    ]);

                    $this->variantRows[$vehicleVariantId] = [
                        'vehicle_variant_id' => $vehicleVariantId,
                        'vehicle_model_id' => $vehicleModelId,
                        'vehicle_brand_id' => $vehicleBrandId,
                        'vis_variant_id' => $visVariantId,
                        'brand_name' => (string) $brandData['brand'],
                        'model_name' => (string) $modelData['name'],
                        'variant_name' => $variantName,
                        'fuel_type' => $fuelType,
                    ];
                    $this->modelByVariantId[$vehicleVariantId] = (string) $modelData['name'];
                }
            }
        }
    }

    private function seedCustomersAndVehicles(): void
    {
        $businessCustomers = [
            ['name' => 'Sai Logistics LLP', 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411018'],
            ['name' => 'Morya Travels Private Limited', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'pincode' => '400078'],
            ['name' => 'Shivneri Cabs Services', 'city' => 'Nashik', 'state' => 'Maharashtra', 'pincode' => '422002'],
            ['name' => 'Greenline Infra Projects', 'city' => 'Thane', 'state' => 'Maharashtra', 'pincode' => '400602'],
            ['name' => 'Aarav Distribution Company', 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411030'],
            ['name' => 'Western Fleet Support', 'city' => 'Navi Mumbai', 'state' => 'Maharashtra', 'pincode' => '400705'],
            ['name' => 'Konkan Agro Foods', 'city' => 'Ratnagiri', 'state' => 'Maharashtra', 'pincode' => '415612'],
            ['name' => 'Blue Ridge Facilities', 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411014'],
            ['name' => 'Sigma Engineering Works', 'city' => 'Aurangabad', 'state' => 'Maharashtra', 'pincode' => '431001'],
        ];

        $firstNames = [
            'Aarav', 'Vihaan', 'Aditya', 'Ishaan', 'Arjun', 'Vedant', 'Rohan', 'Nikhil', 'Sahil', 'Kiran',
            'Tanmay', 'Pranav', 'Yash', 'Harsh', 'Atharva', 'Siddharth', 'Aniket', 'Omkar', 'Abhishek', 'Rahul',
            'Sneha', 'Pooja', 'Ritika', 'Neha', 'Aishwarya', 'Priya', 'Shruti', 'Ananya', 'Kavya', 'Mitali',
            'Swati', 'Deepa', 'Komal', 'Aarti', 'Bhavna', 'Sonal', 'Vaishnavi', 'Mrunal', 'Trupti', 'Prachi',
        ];
        $lastNames = [
            'Patil', 'Shinde', 'Jadhav', 'Pawar', 'More', 'Deshmukh', 'Kulkarni', 'Joshi', 'Kale', 'Gawande',
            'Gaikwad', 'Shah', 'Mehta', 'Mane', 'Sawant', 'Nikam', 'Rane', 'Chavan', 'Kadam', 'Bhosale',
            'Shaikh', 'Ansari', 'Khan', 'D\'souza', 'Fernandes', 'Naik', 'Bhatt', 'Sharma', 'Verma', 'Singh',
        ];
        $cities = [
            ['city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001'],
            ['city' => 'Mumbai', 'state' => 'Maharashtra', 'pincode' => '400001'],
            ['city' => 'Thane', 'state' => 'Maharashtra', 'pincode' => '400601'],
            ['city' => 'Nashik', 'state' => 'Maharashtra', 'pincode' => '422001'],
            ['city' => 'Kolhapur', 'state' => 'Maharashtra', 'pincode' => '416003'],
            ['city' => 'Nagpur', 'state' => 'Maharashtra', 'pincode' => '440001'],
            ['city' => 'Aurangabad', 'state' => 'Maharashtra', 'pincode' => '431001'],
            ['city' => 'Pimpri-Chinchwad', 'state' => 'Maharashtra', 'pincode' => '411017'],
            ['city' => 'Satara', 'state' => 'Maharashtra', 'pincode' => '415001'],
            ['city' => 'Solapur', 'state' => 'Maharashtra', 'pincode' => '413001'],
            ['city' => 'Panaji', 'state' => 'Goa', 'pincode' => '403001'],
            ['city' => 'Belagavi', 'state' => 'Karnataka', 'pincode' => '590001'],
        ];

        $customerCount = 72;
        $inactiveSlots = [9, 17, 29, 41, 53, 69];
        $phoneSeed = 8100000000;

        for ($i = 0; $i < $customerCount; $i++) {
            $isBusiness = $i < count($businessCustomers);
            $cityMeta = $cities[$i % count($cities)];

            $fullName = '';
            $gstin = null;
            if ($isBusiness) {
                $business = $businessCustomers[$i];
                $fullName = (string) $business['name'];
                $cityMeta = [
                    'city' => (string) $business['city'],
                    'state' => (string) $business['state'],
                    'pincode' => (string) $business['pincode'],
                ];
                $gstin = $this->generatePseudoGstin('27', $i + 101);
            } else {
                $fullName = $this->pick($firstNames) . ' ' . $this->pick($lastNames);
                if ($this->chance(18)) {
                    $fullName .= ' ' . $this->pick(['Sr.', 'Jr.']);
                }
            }

            $phone = '+91-' . (string) ($phoneSeed + ($i * 47));
            $altPhone = $this->chance(28) ? ('+91-' . (string) ($phoneSeed + 500000000 + ($i * 31))) : null;
            $emailUser = strtolower(preg_replace('/[^a-z0-9]+/i', '.', $fullName) ?? ('customer.' . $i));
            $email = $isBusiness
                ? strtolower(str_replace(' ', '', preg_replace('/[^a-z0-9 ]+/i', '', $fullName) ?? 'business')) . '@example.in'
                : $emailUser . '@mail.in';

            $isActive = in_array($i, $inactiveSlots, true) ? 0 : 1;
            $statusCode = $isActive === 1 ? 'ACTIVE' : 'INACTIVE';

            $customerId = $this->insert('customers', [
                'company_id' => $this->companyId,
                'created_by' => $this->actorUserId,
                'full_name' => $fullName,
                'phone' => $phone,
                'alt_phone' => $altPhone,
                'email' => $email,
                'gstin' => $gstin,
                'address_line1' => $isBusiness ? 'Office No. ' . (($i % 12) + 1) . ', ' . $cityMeta['city'] . ' Industrial Estate' : (($i % 42) + 5) . ', Shanti Nagar',
                'address_line2' => $this->pick(['Near Market Yard', 'Opposite Petrol Pump', 'Behind Bus Depot', 'Near Highway Service Road']),
                'city' => $cityMeta['city'],
                'state' => $cityMeta['state'],
                'pincode' => $cityMeta['pincode'],
                'notes' => $isBusiness ? 'Fleet customer with monthly servicing cycle' : 'Retail customer',
                'is_active' => $isActive,
                'status_code' => $statusCode,
            ]);

            $this->customerRows[] = [
                'id' => $customerId,
                'full_name' => $fullName,
                'city' => (string) $cityMeta['city'],
                'state' => (string) $cityMeta['state'],
                'is_business' => $isBusiness,
                'is_active' => $isActive === 1,
            ];

            $this->insert('customer_history', [
                'customer_id' => $customerId,
                'action_type' => 'CREATE',
                'action_note' => 'Customer created for demo',
                'snapshot_json' => json_encode(['full_name' => $fullName, 'city' => $cityMeta['city']], JSON_UNESCAPED_UNICODE),
                'created_by' => $this->actorUserId,
            ]);
        }

        $yearMap = $this->pdo->query('SELECT id, year_value FROM vehicle_model_years')->fetchAll();
        $yearIdByValue = [];
        foreach ($yearMap as $row) {
            $yearIdByValue[(int) $row['year_value']] = (int) $row['id'];
        }

        $colorMap = $this->pdo->query('SELECT id FROM vehicle_colors')->fetchAll();
        $colorIds = [];
        foreach ($colorMap as $row) {
            $colorIds[] = (int) $row['id'];
        }

        $counterByPrefix = [];
        foreach ($this->customerRows as $idx => $customer) {
            $vehiclePerCustomer = 1;
            if ((bool) $customer['is_business']) {
                $vehiclePerCustomer = $this->pick([2, 3, 4]);
            } elseif ($this->chance(22)) {
                $vehiclePerCustomer = 2;
            }

            for ($v = 0; $v < $vehiclePerCustomer; $v++) {
                $variantRow = $this->pick(array_values($this->variantRows));
                $modelYear = (int) $this->pick([2016, 2017, 2018, 2019, 2020, 2021, 2022, 2023, 2024, 2025]);
                $modelYearId = $yearIdByValue[$modelYear] ?? null;
                $colorId = $this->pick($colorIds);

                $platePrefix = $this->platePrefixForCity((string) $customer['city']);
                $counterByPrefix[$platePrefix] = (int) ($counterByPrefix[$platePrefix] ?? 9000) + 1;
                $registration = $this->buildVehicleNumber($platePrefix, $counterByPrefix[$platePrefix], $idx + $v);

                $odometer = (int) $this->randomFloat(14000, 155000, 0);
                $vehicleId = $this->insert('vehicles', [
                    'company_id' => $this->companyId,
                    'customer_id' => (int) $customer['id'],
                    'registration_no' => $registration,
                    'vehicle_type' => '4W',
                    'brand' => (string) $variantRow['brand_name'],
                    'brand_id' => (int) $variantRow['vehicle_brand_id'],
                    'model' => (string) $variantRow['model_name'],
                    'model_id' => (int) $variantRow['vehicle_model_id'],
                    'variant' => (string) $variantRow['variant_name'],
                    'variant_id' => (int) $variantRow['vehicle_variant_id'],
                    'fuel_type' => (string) $variantRow['fuel_type'],
                    'model_year' => $modelYear,
                    'model_year_id' => $modelYearId,
                    'color' => null,
                    'color_id' => $colorId,
                    'chassis_no' => 'MCH' . strtoupper(substr(hash('sha1', $registration . '-chassis'), 0, 14)),
                    'engine_no' => 'ENG' . strtoupper(substr(hash('sha1', $registration . '-engine'), 0, 12)),
                    'odometer_km' => $odometer,
                    'notes' => $this->pick(['Regular customer vehicle', 'Used for city commute', 'Intercity usage']),
                    'is_active' => (bool) $customer['is_active'] ? 1 : 0,
                    'vis_variant_id' => (int) $variantRow['vis_variant_id'],
                    'status_code' => (bool) $customer['is_active'] ? 'ACTIVE' : 'INACTIVE',
                ]);

                $this->vehicleRows[] = [
                    'id' => $vehicleId,
                    'customer_id' => (int) $customer['id'],
                    'city' => (string) $customer['city'],
                    'state' => (string) $customer['state'],
                    'variant_id' => (int) $variantRow['vehicle_variant_id'],
                    'vis_variant_id' => (int) $variantRow['vis_variant_id'],
                    'fuel_type' => (string) $variantRow['fuel_type'],
                    'model_name' => (string) $variantRow['model_name'],
                    'is_active' => (bool) $customer['is_active'],
                ];
                $this->lastOdometerByVehicle[$vehicleId] = (float) $odometer;

                $this->insert('vehicle_history', [
                    'vehicle_id' => $vehicleId,
                    'action_type' => 'CREATE',
                    'action_note' => 'Vehicle profile created for demo',
                    'snapshot_json' => json_encode(['registration_no' => $registration, 'odometer_km' => $odometer], JSON_UNESCAPED_UNICODE),
                    'created_by' => $this->actorUserId,
                ]);
            }
        }
    }

    private function seedServiceCatalog(): void
    {
        $categories = [
            ['code' => 'GEN', 'name' => 'General Service', 'desc' => 'Periodic preventive maintenance'],
            ['code' => 'ENG', 'name' => 'Engine Work', 'desc' => 'Engine performance and overhaul'],
            ['code' => 'ACR', 'name' => 'AC Repair', 'desc' => 'Air-conditioning diagnostics and repairs'],
            ['code' => 'ELE', 'name' => 'Electrical', 'desc' => 'Electrical system diagnostics and fitments'],
            ['code' => 'BDY', 'name' => 'Body Work', 'desc' => 'Denting, painting, and panel jobs'],
            ['code' => 'DET', 'name' => 'Detailing', 'desc' => 'Interior and exterior detailing'],
        ];
        foreach ($categories as $category) {
            $categoryId = $this->insert('service_categories', [
                'company_id' => $this->companyId,
                'category_code' => (string) $category['code'],
                'category_name' => (string) $category['name'],
                'description' => (string) $category['desc'],
                'status_code' => 'ACTIVE',
                'created_by' => $this->actorUserId,
            ]);
            $this->serviceCategoryIds[(string) $category['code']] = $categoryId;
        }

        $services = [
            ['code' => 'GS-001', 'cat' => 'GEN', 'name' => 'Basic Service Package', 'hours' => 2.5, 'rate' => 2200, 'gst' => 18],
            ['code' => 'GS-002', 'cat' => 'GEN', 'name' => 'Comprehensive Service Package', 'hours' => 4.0, 'rate' => 4200, 'gst' => 18],
            ['code' => 'GS-003', 'cat' => 'GEN', 'name' => 'Periodic Service 10,000 KM', 'hours' => 3.0, 'rate' => 2800, 'gst' => 18],
            ['code' => 'GS-004', 'cat' => 'GEN', 'name' => 'Wheel Alignment & Balancing', 'hours' => 1.2, 'rate' => 1200, 'gst' => 18],
            ['code' => 'EN-001', 'cat' => 'ENG', 'name' => 'Engine Tuning and Diagnostics', 'hours' => 2.0, 'rate' => 2500, 'gst' => 18],
            ['code' => 'EN-002', 'cat' => 'ENG', 'name' => 'Clutch Overhaul Labour', 'hours' => 5.0, 'rate' => 6800, 'gst' => 18],
            ['code' => 'EN-003', 'cat' => 'ENG', 'name' => 'Timing Belt Replacement Labour', 'hours' => 3.5, 'rate' => 4600, 'gst' => 18],
            ['code' => 'EN-004', 'cat' => 'ENG', 'name' => 'Cylinder Head Work (Outsourced)', 'hours' => 6.0, 'rate' => 9500, 'gst' => 18],
            ['code' => 'AC-001', 'cat' => 'ACR', 'name' => 'AC Gas Refill and Leak Check', 'hours' => 1.5, 'rate' => 1800, 'gst' => 18],
            ['code' => 'AC-002', 'cat' => 'ACR', 'name' => 'AC Compressor Replacement Labour', 'hours' => 4.5, 'rate' => 6200, 'gst' => 18],
            ['code' => 'AC-003', 'cat' => 'ACR', 'name' => 'AC Condenser Cleaning', 'hours' => 1.2, 'rate' => 950, 'gst' => 18],
            ['code' => 'EL-001', 'cat' => 'ELE', 'name' => 'Battery and Charging System Check', 'hours' => 0.8, 'rate' => 650, 'gst' => 18],
            ['code' => 'EL-002', 'cat' => 'ELE', 'name' => 'Starter Motor Repair Labour', 'hours' => 2.8, 'rate' => 3100, 'gst' => 18],
            ['code' => 'EL-003', 'cat' => 'ELE', 'name' => 'Wiring Harness Rectification', 'hours' => 2.3, 'rate' => 2800, 'gst' => 18],
            ['code' => 'EL-004', 'cat' => 'ELE', 'name' => 'Headlamp Assembly Fitment', 'hours' => 1.0, 'rate' => 850, 'gst' => 18],
            ['code' => 'BW-001', 'cat' => 'BDY', 'name' => 'Dent Removal per Panel', 'hours' => 2.5, 'rate' => 1900, 'gst' => 18],
            ['code' => 'BW-002', 'cat' => 'BDY', 'name' => 'Bumper Repair and Refit', 'hours' => 3.5, 'rate' => 3600, 'gst' => 18],
            ['code' => 'BW-003', 'cat' => 'BDY', 'name' => 'Panel Paint Job (Outsourced)', 'hours' => 5.0, 'rate' => 7600, 'gst' => 18],
            ['code' => 'BW-004', 'cat' => 'BDY', 'name' => 'Windshield Replacement Labour', 'hours' => 2.0, 'rate' => 2100, 'gst' => 18],
            ['code' => 'DT-001', 'cat' => 'DET', 'name' => 'Interior Deep Cleaning', 'hours' => 2.4, 'rate' => 2400, 'gst' => 18],
            ['code' => 'DT-002', 'cat' => 'DET', 'name' => 'Ceramic Coating', 'hours' => 5.5, 'rate' => 11200, 'gst' => 18],
            ['code' => 'DT-003', 'cat' => 'DET', 'name' => 'Headlight Restoration', 'hours' => 1.0, 'rate' => 1300, 'gst' => 18],
            ['code' => 'DT-004', 'cat' => 'DET', 'name' => 'Engine Bay Detailing', 'hours' => 1.8, 'rate' => 1700, 'gst' => 18],
        ];

        foreach ($services as $service) {
            $serviceId = $this->insert('services', [
                'company_id' => $this->companyId,
                'category_id' => (int) ($this->serviceCategoryIds[(string) $service['cat']] ?? 0),
                'service_code' => (string) $service['code'],
                'service_name' => (string) $service['name'],
                'description' => (string) $service['name'],
                'default_hours' => (float) $service['hours'],
                'default_rate' => (float) $service['rate'],
                'gst_rate' => (float) $service['gst'],
                'status_code' => 'ACTIVE',
                'created_by' => $this->actorUserId,
            ]);
            $this->serviceRows[(string) $service['code']] = [
                'id' => $serviceId,
                'service_code' => (string) $service['code'],
                'service_name' => (string) $service['name'],
                'category_code' => (string) $service['cat'],
                'default_rate' => (float) $service['rate'],
                'gst_rate' => (float) $service['gst'],
            ];
        }
    }

    private function seedVendors(): void
    {
        $vendors = [
            ['code' => 'VND001', 'name' => 'Omkar Auto Spares', 'person' => 'Vijay Shinde', 'city' => 'Pune', 'state' => 'Maharashtra', 'gstin' => '27AABFO8891R1ZT'],
            ['code' => 'VND002', 'name' => 'Metro Car Components', 'person' => 'Rehan Khan', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'gstin' => '27AABFM1123P1ZC'],
            ['code' => 'VND003', 'name' => 'Sai Battery Hub', 'person' => 'Amit Gole', 'city' => 'Pune', 'state' => 'Maharashtra', 'gstin' => '27AABFS8812D1ZN'],
            ['code' => 'VND004', 'name' => 'Kalyan Brake Solutions', 'person' => 'Nitin Sawant', 'city' => 'Thane', 'state' => 'Maharashtra', 'gstin' => '27AABFK2013J1Z9'],
            ['code' => 'VND005', 'name' => 'Universal Lubricants', 'person' => 'Siddhesh Patil', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'gstin' => '27AABFU7654Q1ZF'],
            ['code' => 'VND006', 'name' => 'Apex AC Specialists', 'person' => 'Faizal Shaikh', 'city' => 'Pune', 'state' => 'Maharashtra', 'gstin' => '27AABFA6322M1ZR'],
            ['code' => 'VND007', 'name' => 'Prime Suspension House', 'person' => 'Darshan Kulkarni', 'city' => 'Nashik', 'state' => 'Maharashtra', 'gstin' => '27AABFP5612R1ZI'],
            ['code' => 'VND008', 'name' => 'Western Electricals', 'person' => 'Neeraj Mehta', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'gstin' => '27AABFW7423B1ZU'],
            ['code' => 'VND009', 'name' => 'Fine Finish Body Shop', 'person' => 'Kailas More', 'city' => 'Pune', 'state' => 'Maharashtra', 'gstin' => '27AABFF5522E1ZP'],
            ['code' => 'VND010', 'name' => 'Precision Engine Works', 'person' => 'Ganesh Rane', 'city' => 'Pune', 'state' => 'Maharashtra', 'gstin' => '27AABFP8731K1ZW'],
            ['code' => 'VND011', 'name' => 'Mumbai Tyre Line', 'person' => 'Vimal Shah', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'gstin' => '27AABFM3288N1Z4'],
            ['code' => 'VND012', 'name' => 'Shree Paint Booth', 'person' => 'Paresh Chavan', 'city' => 'Pune', 'state' => 'Maharashtra', 'gstin' => '27AABFS1010H1ZS'],
            ['code' => 'VND013', 'name' => 'Spark Plug Depot', 'person' => 'Manoj Kale', 'city' => 'Nagpur', 'state' => 'Maharashtra', 'gstin' => '27AABFS9951N1ZG'],
            ['code' => 'VND014', 'name' => 'Elite Cooling Systems', 'person' => 'Roshan Das', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'gstin' => '27AABFE4491Q1Z2'],
            ['code' => 'VND015', 'name' => 'Autocraft External Works', 'person' => 'Tushar Bhosale', 'city' => 'Pune', 'state' => 'Maharashtra', 'gstin' => '27AABFA7711J1ZV'],
            ['code' => 'VND016', 'name' => 'Maharashtra Fleet Parts', 'person' => 'Kiran Mane', 'city' => 'Aurangabad', 'state' => 'Maharashtra', 'gstin' => '27AABFM5520R1ZI'],
        ];

        foreach ($vendors as $vendor) {
            $vendorId = $this->insert('vendors', [
                'company_id' => $this->companyId,
                'vendor_code' => (string) $vendor['code'],
                'vendor_name' => (string) $vendor['name'],
                'contact_person' => (string) $vendor['person'],
                'phone' => '+91-' . (string) (9000010000 + count($this->vendorRows) * 79),
                'email' => strtolower(str_replace(' ', '', (string) $vendor['name'])) . '@vendor.in',
                'gstin' => (string) $vendor['gstin'],
                'address_line1' => 'Shop ' . ((count($this->vendorRows) % 28) + 1) . ', Trade Center',
                'city' => (string) $vendor['city'],
                'state' => (string) $vendor['state'],
                'pincode' => (string) $this->pick(['411045', '400078', '422001', '431001']),
                'status_code' => 'ACTIVE',
            ]);
            $this->vendorRows[] = [
                'id' => $vendorId,
                'vendor_code' => (string) $vendor['code'],
                'vendor_name' => (string) $vendor['name'],
            ];
        }
    }

    private function seedPartsAndInventoryMasters(): void
    {
        $categories = [
            ['code' => 'FLT', 'name' => 'Filters'],
            ['code' => 'LUB', 'name' => 'Lubricants'],
            ['code' => 'BRK', 'name' => 'Brake System'],
            ['code' => 'CLT', 'name' => 'Clutch Components'],
            ['code' => 'BAT', 'name' => 'Batteries'],
            ['code' => 'LGT', 'name' => 'Lights'],
            ['code' => 'AC', 'name' => 'AC Components'],
            ['code' => 'SUS', 'name' => 'Suspension'],
            ['code' => 'ENG', 'name' => 'Engine Components'],
            ['code' => 'ELE', 'name' => 'Electrical Parts'],
            ['code' => 'BDY', 'name' => 'Body Parts'],
            ['code' => 'TYR', 'name' => 'Tyres and Wheels'],
            ['code' => 'WPR', 'name' => 'Wiper and Wash'],
        ];
        foreach ($categories as $category) {
            $categoryId = $this->insert('part_categories', [
                'company_id' => $this->companyId,
                'category_code' => (string) $category['code'],
                'category_name' => (string) $category['name'],
                'description' => (string) $category['name'],
                'status_code' => 'ACTIVE',
            ]);
            $this->partCategoryIds[(string) $category['code']] = $categoryId;
        }

        $baseParts = [
            ['Oil Filter', 'FLT', '84212300', 180, 300, 18, 20],
            ['Air Filter', 'FLT', '84213100', 260, 420, 18, 18],
            ['Cabin Filter', 'FLT', '84213990', 220, 390, 18, 15],
            ['Fuel Filter', 'FLT', '84212300', 310, 520, 18, 12],
            ['Engine Oil 5W30 (1L)', 'LUB', '27101980', 380, 610, 18, 35],
            ['Engine Oil 0W20 (1L)', 'LUB', '27101980', 420, 660, 18, 24],
            ['Brake Fluid DOT4 (500ml)', 'LUB', '38190090', 140, 260, 18, 18],
            ['Coolant Premix (1L)', 'LUB', '38200000', 170, 290, 18, 22],
            ['Front Brake Pad Set', 'BRK', '87083000', 980, 1680, 28, 14],
            ['Rear Brake Shoe Set', 'BRK', '87083000', 760, 1320, 28, 12],
            ['Brake Disc Rotor', 'BRK', '87083000', 1850, 2890, 28, 8],
            ['Clutch Plate', 'CLT', '87089300', 1650, 2790, 28, 8],
            ['Clutch Pressure Plate', 'CLT', '87089300', 1480, 2520, 28, 7],
            ['Clutch Release Bearing', 'CLT', '84821090', 530, 910, 18, 10],
            ['Battery 35AH', 'BAT', '85071000', 3450, 5190, 28, 6],
            ['Battery 45AH', 'BAT', '85071000', 4180, 6190, 28, 6],
            ['Headlight Assembly', 'LGT', '85122090', 2450, 3890, 18, 6],
            ['Tail Lamp Assembly', 'LGT', '85122090', 1280, 2190, 18, 8],
            ['Fog Lamp Set', 'LGT', '85122090', 940, 1740, 18, 7],
            ['AC Compressor', 'AC', '84143000', 9800, 14500, 28, 3],
            ['AC Condenser', 'AC', '84159000', 4200, 6390, 28, 4],
            ['AC Blower Motor', 'AC', '84159000', 1850, 3150, 18, 5],
            ['Shock Absorber Front', 'SUS', '87088000', 1750, 2960, 28, 7],
            ['Shock Absorber Rear', 'SUS', '87088000', 1500, 2590, 28, 7],
            ['Suspension Link Rod', 'SUS', '87088000', 420, 760, 18, 12],
            ['Control Arm', 'SUS', '87088000', 1680, 2840, 28, 8],
            ['Spark Plug', 'ENG', '85111000', 180, 340, 18, 25],
            ['Ignition Coil', 'ENG', '85113000', 920, 1540, 18, 10],
            ['Timing Belt Kit', 'ENG', '40103290', 2100, 3490, 28, 6],
            ['Radiator Hose', 'ENG', '40093100', 240, 420, 18, 18],
            ['Alternator Belt', 'ENG', '40103100', 280, 490, 18, 14],
            ['Starter Motor', 'ELE', '85114000', 3600, 5540, 18, 4],
            ['Alternator Assembly', 'ELE', '85115000', 4200, 6580, 18, 4],
            ['Wiper Motor', 'ELE', '85013100', 1450, 2480, 18, 6],
            ['Power Window Switch', 'ELE', '85365090', 540, 980, 18, 9],
            ['Front Bumper', 'BDY', '87081090', 3400, 5790, 28, 4],
            ['Rear Bumper', 'BDY', '87081090', 3200, 5480, 28, 4],
            ['Door Mirror Assembly', 'BDY', '70091000', 2100, 3490, 18, 5],
            ['Windshield Glass', 'BDY', '70072190', 4900, 7390, 28, 3],
            ['Tyre 195/55 R16', 'TYR', '40111010', 3950, 5990, 28, 12],
            ['Tyre 205/65 R15', 'TYR', '40111010', 4220, 6360, 28, 12],
            ['Steel Wheel Rim', 'TYR', '87087000', 1890, 3140, 18, 8],
            ['Wiper Blade Pair', 'WPR', '85129090', 380, 690, 18, 16],
            ['Washer Pump Motor', 'WPR', '84138190', 460, 840, 18, 12],
            ['Washer Fluid (1L)', 'WPR', '34022090', 95, 180, 18, 30],
        ];

        $modelSuffixes = ['Baleno', 'Swift', 'Creta', 'Nexon', 'XUV700', 'City', 'Innova', 'Seltos'];
        $generatedParts = [];
        foreach ($baseParts as $base) {
            $generatedParts[] = [
                'name' => (string) $base[0],
                'category' => (string) $base[1],
                'hsn' => (string) $base[2],
                'purchase' => (float) $base[3],
                'sell' => (float) $base[4],
                'gst' => (float) $base[5],
                'min' => (float) $base[6],
            ];
            foreach ($modelSuffixes as $suffix) {
                if (count($generatedParts) >= 112) {
                    break 2;
                }
                if (!$this->chance(30)) {
                    continue;
                }
                $generatedParts[] = [
                    'name' => (string) $base[0] . ' - ' . $suffix,
                    'category' => (string) $base[1],
                    'hsn' => (string) $base[2],
                    'purchase' => round(((float) $base[3]) * $this->randomFloat(0.95, 1.2, 2), 2),
                    'sell' => round(((float) $base[4]) * $this->randomFloat(0.95, 1.25, 2), 2),
                    'gst' => (float) $base[5],
                    'min' => max(3, (float) $base[6] - 2),
                ];
            }
        }

        $partCount = 0;
        foreach ($generatedParts as $part) {
            $partCount++;
            $sku = sprintf('GAC-%s-%04d', (string) $part['category'], $partCount);
            $vendor = $this->pick($this->vendorRows);
            $partId = $this->insert('parts', [
                'company_id' => $this->companyId,
                'part_name' => (string) $part['name'],
                'part_sku' => $sku,
                'hsn_code' => (string) $part['hsn'],
                'unit' => 'PCS',
                'purchase_price' => (float) $part['purchase'],
                'selling_price' => (float) $part['sell'],
                'gst_rate' => (float) $part['gst'],
                'min_stock' => (float) $part['min'],
                'is_active' => 1,
                'category_id' => (int) ($this->partCategoryIds[(string) $part['category']] ?? 0),
                'vendor_id' => (int) ($vendor['id'] ?? 0),
                'status_code' => 'ACTIVE',
            ]);
            $this->partRows[] = [
                'id' => $partId,
                'part_name' => (string) $part['name'],
                'part_sku' => $sku,
                'category_code' => (string) $part['category'],
                'purchase_price' => (float) $part['purchase'],
                'selling_price' => (float) $part['sell'],
                'gst_rate' => (float) $part['gst'],
                'hsn_code' => (string) $part['hsn'],
            ];

            $this->ensureInventoryRow(1, $partId);
            $this->ensureInventoryRow(2, $partId);
        }
    }

    private function seedVisMappings(): void
    {
        if ($this->partRows === [] || $this->variantRows === [] || $this->serviceRows === []) {
            return;
        }

        $allVariantRows = array_values($this->variantRows);
        foreach ($this->partRows as $part) {
            $compatibilityCount = $this->chance(50) ? $this->pick([2, 3, 4]) : 1;
            $picked = [];
            for ($i = 0; $i < $compatibilityCount; $i++) {
                $variant = $this->pick($allVariantRows);
                $visVariantId = (int) ($variant['vis_variant_id'] ?? 0);
                if ($visVariantId <= 0 || in_array($visVariantId, $picked, true)) {
                    continue;
                }
                $picked[] = $visVariantId;
                $this->insert('vis_part_compatibility', [
                    'company_id' => $this->companyId,
                    'variant_id' => $visVariantId,
                    'part_id' => (int) $part['id'],
                    'compatibility_note' => $this->pick(['OEM fit', 'Aftermarket compatible', 'Recommended with service kit']),
                    'status_code' => 'ACTIVE',
                ]);
            }
        }

        $serviceList = array_values($this->serviceRows);
        for ($i = 0; $i < 220; $i++) {
            $service = $this->pick($serviceList);
            $part = $this->pick($this->partRows);
            if ($service === [] || $part === []) {
                continue;
            }
            $existsStmt = $this->pdo->prepare(
                'SELECT id
                 FROM vis_service_part_map
                 WHERE company_id = :company_id
                   AND service_id = :service_id
                   AND part_id = :part_id
                 LIMIT 1'
            );
            $existsStmt->execute([
                'company_id' => $this->companyId,
                'service_id' => (int) $service['id'],
                'part_id' => (int) $part['id'],
            ]);
            if ($existsStmt->fetch()) {
                continue;
            }

            $this->insert('vis_service_part_map', [
                'company_id' => $this->companyId,
                'service_id' => (int) $service['id'],
                'part_id' => (int) $part['id'],
                'is_required' => $this->chance(55) ? 1 : 0,
                'status_code' => 'ACTIVE',
            ]);
        }
    }

    private function seedPurchasesAndStock(): void
    {
        $purchaseCount = 18;
        for ($i = 1; $i <= $purchaseCount; $i++) {
            $garageId = $i % 3 === 0 ? 2 : 1;
            $vendor = $this->pick($this->vendorRows);
            $purchaseDate = $this->randomDate($this->startDate, $this->endDate);
            $status = 'FINALIZED';
            $paymentIntent = $this->pick(['PAID', 'PARTIAL', 'UNPAID', 'PAID', 'PARTIAL']);

            $lineCount = $this->pick([3, 4, 5, 6]);
            $lines = [];
            for ($l = 0; $l < $lineCount; $l++) {
                $part = $this->pick($this->partRows);
                $qty = $this->pick([2, 3, 4, 5, 8, 10]);
                $unitCost = round(((float) $part['purchase_price']) * $this->randomFloat(0.95, 1.1, 2), 2);
                $gstRate = (float) $part['gst_rate'];
                $taxable = round($qty * $unitCost, 2);
                $gst = round($taxable * $gstRate / 100, 2);
                $total = round($taxable + $gst, 2);
                $lines[] = [
                    'part_id' => (int) $part['id'],
                    'quantity' => (float) $qty,
                    'unit_cost' => $unitCost,
                    'gst_rate' => $gstRate,
                    'taxable_amount' => $taxable,
                    'gst_amount' => $gst,
                    'total_amount' => $total,
                ];
            }

            $purchaseId = $this->createPurchaseRecord(
                $garageId,
                (int) ($vendor['id'] ?? 0),
                sprintf('INV-%s-%04d', (string) ($vendor['vendor_code'] ?? 'VND'), $i),
                $purchaseDate,
                $lines,
                $status,
                $paymentIntent,
                'VENDOR_ENTRY',
                'Routine stock replenishment'
            );
            $this->purchaseRows[] = [
                'id' => $purchaseId,
                'garage_id' => $garageId,
                'payment_intent' => $paymentIntent,
                'purchase_date' => $purchaseDate,
            ];
        }

        if ($this->purchaseRows !== []) {
            $target = $this->pick($this->purchaseRows);
            $purchaseId = (int) ($target['id'] ?? 0);
            if ($purchaseId > 0) {
                $paymentRow = $this->pdo->prepare(
                    'SELECT id, amount, payment_mode
                     FROM purchase_payments
                     WHERE purchase_id = :purchase_id
                       AND entry_type = "PAYMENT"
                     ORDER BY id ASC
                     LIMIT 1'
                );
                $paymentRow->execute(['purchase_id' => $purchaseId]);
                $payment = $paymentRow->fetch();
                if ($payment) {
                    $paymentId = (int) $payment['id'];
                    $amount = round((float) $payment['amount'], 2);
                    $reverseDate = $this->randomDate((string) ($target['purchase_date'] ?? $this->startDate), $this->endDate);
                    $reverseId = $this->insert('purchase_payments', [
                        'purchase_id' => $purchaseId,
                        'company_id' => $this->companyId,
                        'garage_id' => (int) ($target['garage_id'] ?? 1),
                        'payment_date' => $reverseDate,
                        'entry_type' => 'REVERSAL',
                        'amount' => -$amount,
                        'payment_mode' => 'ADJUSTMENT',
                        'reference_no' => 'REV-' . $paymentId,
                        'notes' => 'Demo reversal for purchase payment',
                        'reversed_payment_id' => $paymentId,
                        'created_by' => $this->actorUserId,
                    ]);
                    $this->touchCreatedAt('purchase_payments', $reverseId, $reverseDate . ' 18:15:00');

                    finance_record_expense_for_purchase_payment(
                        $reverseId,
                        $purchaseId,
                        $this->companyId,
                        (int) ($target['garage_id'] ?? 1),
                        $amount,
                        $reverseDate,
                        'ADJUSTMENT',
                        'Demo reversal for purchase payment',
                        true,
                        $this->actorUserId
                    );
                    $this->refreshPurchasePaymentStatus($purchaseId);
                }
            }
        }
    }

    private function seedTemporaryStock(): void
    {
        $states = ['OPEN', 'RETURNED', 'PURCHASED', 'CONSUMED'];
        for ($i = 1; $i <= 10; $i++) {
            $garageId = $i % 2 === 0 ? 2 : 1;
            $part = $this->pick($this->partRows);
            $quantity = (float) $this->pick([1, 2, 3, 4]);
            $status = $states[$i % count($states)];
            if ($i <= 2) {
                $status = 'OPEN';
            }

            $tempRef = sprintf('TMP-%s-%03d', date('ymd', strtotime($this->startDate)), $i);
            $createdAt = $this->randomDate($this->startDate, $this->endDate) . ' ' . $this->pick(['10:15:00', '12:40:00', '16:05:00']);
            $entryId = $this->insert('temp_stock_entries', [
                'company_id' => $this->companyId,
                'garage_id' => $garageId,
                'temp_ref' => $tempRef,
                'part_id' => (int) ($part['id'] ?? 0),
                'quantity' => $quantity,
                'status_code' => 'OPEN',
                'notes' => 'Temporary inward for urgent job',
                'created_by' => $this->actorUserId,
            ]);
            $this->touchCreatedAt('temp_stock_entries', $entryId, $createdAt);
            $this->touchUpdatedAt('temp_stock_entries', $entryId, $createdAt);

            $eventId = $this->insert('temp_stock_events', [
                'temp_entry_id' => $entryId,
                'company_id' => $this->companyId,
                'garage_id' => $garageId,
                'event_type' => 'TEMP_IN',
                'quantity' => $quantity,
                'from_status' => null,
                'to_status' => 'OPEN',
                'notes' => 'Initial temporary stock inward',
                'purchase_id' => null,
                'created_by' => $this->actorUserId,
            ]);
            $this->touchCreatedAt('temp_stock_events', $eventId, $createdAt);

            if ($status === 'OPEN') {
                continue;
            }

            $resolutionAt = date('Y-m-d H:i:s', strtotime($createdAt . ' +' . $this->pick([1, 2, 3, 4, 6]) . ' day'));
            $purchaseId = null;
            if ($status === 'PURCHASED') {
                $unitCost = round((float) ($part['purchase_price'] ?? 0), 2);
                $gstRate = round((float) ($part['gst_rate'] ?? 0), 2);
                $taxable = round($quantity * $unitCost, 2);
                $gst = round($taxable * $gstRate / 100, 2);
                $total = round($taxable + $gst, 2);

                $purchaseId = $this->insert('purchases', [
                    'company_id' => $this->companyId,
                    'garage_id' => $garageId,
                    'vendor_id' => null,
                    'invoice_number' => null,
                    'purchase_date' => substr($resolutionAt, 0, 10),
                    'purchase_source' => 'TEMP_CONVERSION',
                    'assignment_status' => 'UNASSIGNED',
                    'purchase_status' => 'DRAFT',
                    'payment_status' => 'UNPAID',
                    'status_code' => 'ACTIVE',
                    'taxable_amount' => $taxable,
                    'gst_amount' => $gst,
                    'grand_total' => $total,
                    'notes' => 'Converted from temporary stock ' . $tempRef,
                    'created_by' => $this->actorUserId,
                ]);
                $this->touchCreatedAt('purchases', $purchaseId, $resolutionAt);
                $this->touchUpdatedAt('purchases', $purchaseId, $resolutionAt);

                $purchaseItemId = $this->insert('purchase_items', [
                    'purchase_id' => $purchaseId,
                    'part_id' => (int) ($part['id'] ?? 0),
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'gst_rate' => $gstRate,
                    'taxable_amount' => $taxable,
                    'gst_amount' => $gst,
                    'total_amount' => $total,
                ]);
                $this->touchCreatedAt('purchase_items', $purchaseItemId, $resolutionAt);

                $this->adjustInventory(
                    $garageId,
                    (int) ($part['id'] ?? 0),
                    $quantity,
                    'IN',
                    'PURCHASE',
                    $purchaseId,
                    'Temp stock conversion ' . $tempRef,
                    $resolutionAt
                );
            }

            $update = $this->pdo->prepare(
                'UPDATE temp_stock_entries
                 SET status_code = :status_code,
                     resolution_notes = :resolution_notes,
                     purchase_id = :purchase_id,
                     resolved_by = :resolved_by,
                     resolved_at = :resolved_at,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $update->execute([
                'status_code' => $status,
                'resolution_notes' => 'Resolved via demo flow',
                'purchase_id' => $purchaseId,
                'resolved_by' => $this->actorUserId,
                'resolved_at' => $resolutionAt,
                'updated_at' => $resolutionAt,
                'id' => $entryId,
            ]);

            $eventId = $this->insert('temp_stock_events', [
                'temp_entry_id' => $entryId,
                'company_id' => $this->companyId,
                'garage_id' => $garageId,
                'event_type' => $status,
                'quantity' => $quantity,
                'from_status' => 'OPEN',
                'to_status' => $status,
                'notes' => 'Resolved as ' . $status,
                'purchase_id' => $purchaseId,
                'created_by' => $this->actorUserId,
            ]);
            $this->touchCreatedAt('temp_stock_events', $eventId, $resolutionAt);
        }
    }

    private function seedStockTransfers(): void
    {
        $transferCandidates = array_slice($this->partRows, 0, 20);
        $posted = 0;
        foreach ($transferCandidates as $part) {
            if ($posted >= 12) {
                break;
            }
            $partId = (int) $part['id'];
            $available = $this->inventoryByGaragePart[1][$partId] ?? 0.0;
            if ($available < 6) {
                continue;
            }

            $qty = round(min($this->pick([1, 2, 3, 4]), $available - 2), 2);
            if ($qty <= 0) {
                continue;
            }

            $transferRef = sprintf('TRF-%s-%03d', date('ymd', strtotime($this->startDate)), $posted + 1);
            $transferDate = $this->randomDate($this->startDate, $this->endDate);
            $transferId = $this->insert('inventory_transfers', [
                'company_id' => $this->companyId,
                'from_garage_id' => 1,
                'to_garage_id' => 2,
                'part_id' => $partId,
                'quantity' => $qty,
                'transfer_ref' => $transferRef,
                'request_uid' => hash('sha256', $transferRef . '-' . $partId . '-' . $qty),
                'status_code' => 'POSTED',
                'notes' => 'Demo stock balancing transfer',
                'created_by' => $this->actorUserId,
            ]);
            $transferAt = $transferDate . ' 15:20:00';
            $this->touchCreatedAt('inventory_transfers', $transferId, $transferAt);

            $this->adjustInventory(1, $partId, -$qty, 'OUT', 'TRANSFER', $transferId, 'Transfer to Mumbai', $transferAt);
            $this->adjustInventory(2, $partId, $qty, 'IN', 'TRANSFER', $transferId, 'Transfer from Pune', $transferAt);
            $posted++;
        }
    }

    private function seedJobsAndOutsourcedFlows(): void
    {
        if ($this->vehicleRows === [] || $this->serviceRows === [] || $this->partRows === []) {
            return;
        }

        $garageMechanics = [
            1 => array_values(array_filter([
                $this->userIds['mechanic_pune'] ?? 0,
                $this->userIds['manager'] ?? 0,
            ], static fn (int $id): bool => $id > 0)),
            2 => array_values(array_filter([
                $this->userIds['mechanic_mumbai_1'] ?? 0,
                $this->userIds['mechanic_mumbai_2'] ?? 0,
                $this->userIds['helper'] ?? 0,
            ], static fn (int $id): bool => $id > 0)),
        ];

        $serviceRows = array_values($this->serviceRows);
        $serviceByCode = $this->serviceRows;
        $outsourceServiceCodes = ['EN-004', 'BW-003', 'AC-002'];
        $complaints = [
            'Periodic service due',
            'Engine vibration at idle',
            'AC cooling not effective',
            'Braking noise from front wheel',
            'Steering pulls to left',
            'Battery drains overnight',
            'Check engine light intermittently ON',
            'Suspension noise on speed breaker',
            'Minor accident body dent',
            'Cabin blower noise',
        ];
        $repeatComplaints = [
            'AC cooling not effective',
            'Braking noise from front wheel',
            'Suspension noise on speed breaker',
        ];

        $targetStatuses = array_merge(
            array_fill(0, 68, 'CLOSED'),
            array_fill(0, 12, 'COMPLETED'),
            array_fill(0, 10, 'WAITING_PARTS'),
            array_fill(0, 12, 'IN_PROGRESS'),
            array_fill(0, 8, 'OPEN'),
            array_fill(0, 2, 'CANCELLED')
        );
        shuffle($targetStatuses);

        $activeVehicles = array_values(array_filter($this->vehicleRows, static fn (array $row): bool => (bool) ($row['is_active'] ?? false)));
        $repeatVehicleIds = [];
        for ($i = 0; $i < 6; $i++) {
            $repeatVehicleIds[] = (int) ($this->pick($activeVehicles)['id'] ?? 0);
        }

        $reversalCandidateOutsourcePaymentId = 0;
        $reversalCandidateWorkId = 0;
        $reversalCandidateGarageId = 0;
        foreach ($targetStatuses as $index => $targetStatus) {
            $vehicle = $this->pick($activeVehicles);
            if ($index % 11 === 0) {
                $repeatVehicleId = $repeatVehicleIds[$index % count($repeatVehicleIds)] ?? 0;
                foreach ($activeVehicles as $candidateVehicle) {
                    if ((int) ($candidateVehicle['id'] ?? 0) === $repeatVehicleId) {
                        $vehicle = $candidateVehicle;
                        break;
                    }
                }
            }

            $vehicleId = (int) ($vehicle['id'] ?? 0);
            $customerId = (int) ($vehicle['customer_id'] ?? 0);
            if ($vehicleId <= 0 || $customerId <= 0) {
                continue;
            }

            $garageId = $this->garageForCity((string) ($vehicle['city'] ?? 'Pune'));
            $openedDate = $this->randomDate($this->startDate, $this->endDate);
            $openedAt = $openedDate . ' ' . $this->pick(['09:20:00', '10:45:00', '12:15:00', '15:10:00', '17:35:00']);

            $odometer = $this->nextOdometerReading($vehicleId);
            $complaint = $this->pick($complaints);
            if ($index % 13 === 0) {
                $complaint = $this->pick($repeatComplaints);
            }

            $jobId = $this->insert('job_cards', [
                'company_id' => $this->companyId,
                'garage_id' => $garageId,
                'job_number' => job_generate_number($this->pdo, $garageId),
                'customer_id' => $customerId,
                'vehicle_id' => $vehicleId,
                'odometer_km' => (int) $odometer,
                'assigned_to' => null,
                'service_advisor_id' => $this->userIds['manager'] ?? $this->actorUserId,
                'complaint' => $complaint,
                'diagnosis' => null,
                'status' => 'OPEN',
                'priority' => $this->pick(['LOW', 'MEDIUM', 'MEDIUM', 'HIGH', 'URGENT']),
                'estimated_cost' => 0,
                'opened_at' => $openedAt,
                'promised_at' => date('Y-m-d H:i:s', strtotime($openedAt . ' +2 day')),
                'status_code' => 'ACTIVE',
                'created_by' => $this->actorUserId,
                'updated_by' => $this->actorUserId,
            ]);
            $this->touchCreatedAt('job_cards', $jobId, $openedAt);
            $this->touchUpdatedAt('job_cards', $jobId, $openedAt);
            $this->jobGarage[$jobId] = $garageId;
            $this->jobVehicle[$jobId] = $vehicleId;

            $assignable = $garageMechanics[$garageId] ?? [];
            if ($assignable !== []) {
                $primary = (int) $this->pick($assignable);
                $requested = [$primary];
                if ($this->chance(36) && count($assignable) > 1) {
                    $secondary = (int) $this->pick(array_values(array_filter($assignable, static fn (int $id): bool => $id !== $primary)));
                    if ($secondary > 0) {
                        $requested[] = $secondary;
                    }
                }
                job_sync_assignments($jobId, $this->companyId, $garageId, $requested, $this->actorUserId);
            }
            job_append_history($jobId, 'CREATE', null, 'OPEN', 'Demo job created', null);

            $laborLineCount = $this->pick([1, 2, 2, 3]);
            $outsourcedWorksForJob = [];
            for ($l = 0; $l < $laborLineCount; $l++) {
                $service = $this->pick($serviceRows);
                $serviceCode = (string) ($service['service_code'] ?? '');
                $qty = (float) $this->pick([1, 1, 1.5, 2]);
                $unit = round(((float) ($service['default_rate'] ?? 0)) * $this->randomFloat(0.92, 1.12, 2), 2);
                $gst = (float) ($service['gst_rate'] ?? 18.0);
                $total = round($qty * $unit, 2);
                $executionType = (in_array($serviceCode, $outsourceServiceCodes, true) && $this->chance(65)) ? 'OUTSOURCED' : 'IN_HOUSE';

                $outsourceVendorId = null;
                $outsourcePartnerName = null;
                $outsourceCost = 0.0;
                $payableStatus = 'PAID';
                if ($executionType === 'OUTSOURCED') {
                    $vendor = $this->pick($this->vendorRows);
                    $outsourceVendorId = (int) ($vendor['id'] ?? 0);
                    $outsourcePartnerName = (string) ($vendor['vendor_name'] ?? 'Partner Workshop');
                    $outsourceCost = round($total * $this->randomFloat(0.55, 0.82, 2), 2);
                    $payableStatus = 'UNPAID';
                }

                $laborId = $this->insert('job_labor', [
                    'job_card_id' => $jobId,
                    'service_id' => (int) ($service['id'] ?? 0),
                    'execution_type' => $executionType,
                    'outsource_vendor_id' => $outsourceVendorId,
                    'outsource_partner_name' => $outsourcePartnerName,
                    'outsource_cost' => $outsourceCost,
                    'outsource_payable_status' => $payableStatus,
                    'outsource_paid_at' => null,
                    'outsource_paid_by' => null,
                    'description' => (string) ($service['service_name'] ?? 'Labor Line'),
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'gst_rate' => $gst,
                    'total_amount' => $total,
                ]);
                $this->touchCreatedAt('job_labor', $laborId, $openedAt);
                $this->touchUpdatedAt('job_labor', $laborId, $openedAt);

                if ($executionType === 'OUTSOURCED') {
                    $outsourceStatus = 'SENT';
                    if (in_array($targetStatus, ['WAITING_PARTS', 'IN_PROGRESS'], true)) {
                        $outsourceStatus = $this->pick(['SENT', 'RECEIVED']);
                    } elseif (in_array($targetStatus, ['COMPLETED', 'CLOSED'], true)) {
                        $outsourceStatus = $this->pick(['PAYABLE', 'PAID']);
                    }
                    $sentAt = date('Y-m-d H:i:s', strtotime($openedAt . ' +6 hour'));
                    $receivedAt = in_array($outsourceStatus, ['RECEIVED', 'VERIFIED', 'PAYABLE', 'PAID'], true) ? date('Y-m-d H:i:s', strtotime($openedAt . ' +1 day')) : null;
                    $verifiedAt = in_array($outsourceStatus, ['VERIFIED', 'PAYABLE', 'PAID'], true) ? date('Y-m-d H:i:s', strtotime($openedAt . ' +2 day')) : null;
                    $payableAt = in_array($outsourceStatus, ['PAYABLE', 'PAID'], true) ? date('Y-m-d H:i:s', strtotime($openedAt . ' +3 day')) : null;
                    $paidAt = $outsourceStatus === 'PAID' ? date('Y-m-d H:i:s', strtotime($openedAt . ' +4 day')) : null;

                    $workId = $this->insert('outsourced_works', [
                        'company_id' => $this->companyId,
                        'garage_id' => $garageId,
                        'job_card_id' => $jobId,
                        'job_labor_id' => $laborId,
                        'vendor_id' => $outsourceVendorId,
                        'partner_name' => $outsourcePartnerName ?? 'Partner Workshop',
                        'service_description' => (string) ($service['service_name'] ?? 'Outsourced Job'),
                        'agreed_cost' => $outsourceCost,
                        'expected_return_date' => date('Y-m-d', strtotime($openedAt . ' +3 day')),
                        'current_status' => $outsourceStatus,
                        'sent_at' => $sentAt,
                        'received_at' => $receivedAt,
                        'verified_at' => $verifiedAt,
                        'payable_at' => $payableAt,
                        'paid_at' => $paidAt,
                        'notes' => 'Auto seeded from job labor',
                        'status_code' => 'ACTIVE',
                        'created_by' => $this->actorUserId,
                        'updated_by' => $this->actorUserId,
                    ]);
                    $this->touchCreatedAt('outsourced_works', $workId, $sentAt);
                    $this->touchUpdatedAt('outsourced_works', $workId, $paidAt ?? $payableAt ?? $sentAt);

                    $this->insert('outsourced_work_history', [
                        'outsourced_work_id' => $workId,
                        'action_type' => 'CREATE',
                        'from_status' => null,
                        'to_status' => $outsourceStatus,
                        'action_note' => 'Seeded outsourced work',
                        'payload_json' => json_encode(['job_id' => $jobId, 'labor_id' => $laborId], JSON_UNESCAPED_UNICODE),
                        'created_by' => $this->actorUserId,
                    ]);

                    $outsourcedWorksForJob[] = [
                        'id' => $workId,
                        'garage_id' => $garageId,
                        'status' => $outsourceStatus,
                        'agreed_cost' => $outsourceCost,
                        'opened_at' => $openedAt,
                    ];
                }
            }

            $partLineCount = in_array($targetStatus, ['CLOSED', 'COMPLETED', 'WAITING_PARTS'], true)
                ? $this->pick([1, 2, 2, 3])
                : $this->pick([0, 1, 2]);
            for ($p = 0; $p < $partLineCount; $p++) {
                $partPool = $this->partRows;
                if ($targetStatus === 'CLOSED') {
                    $partPool = array_values(array_filter(
                        $this->partRows,
                        fn (array $row): bool => ($this->inventoryByGaragePart[$garageId][(int) $row['id']] ?? 0.0) >= 2.0
                    ));
                    if ($partPool === []) {
                        break;
                    }
                }
                $part = $this->pick($partPool);
                $partId = (int) $part['id'];
                $available = $this->inventoryByGaragePart[$garageId][$partId] ?? 0.0;
                $qty = 1.0;
                if ($targetStatus === 'CLOSED') {
                    $maxAllowed = max(1.0, floor(max(1.0, $available / 2)));
                    $qty = (float) min($this->pick([1, 1, 2]), $maxAllowed);
                } else {
                    $qty = (float) $this->pick([1, 1, 2]);
                }
                $unit = round(((float) $part['selling_price']) * $this->randomFloat(0.97, 1.03, 2), 2);
                $gst = (float) $part['gst_rate'];
                $total = round($qty * $unit, 2);

                $partLineId = $this->insert('job_parts', [
                    'job_card_id' => $jobId,
                    'part_id' => $partId,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'gst_rate' => $gst,
                    'total_amount' => $total,
                ]);
                $this->touchCreatedAt('job_parts', $partLineId, $openedAt);
                $this->touchUpdatedAt('job_parts', $partLineId, $openedAt);
            }

            if ($index % 15 === 0) {
                $issueId = $this->insert('job_issues', [
                    'job_card_id' => $jobId,
                    'issue_title' => 'Repeat customer concern',
                    'issue_notes' => 'Customer reported recurrence of: ' . $complaint,
                    'resolved_flag' => in_array($targetStatus, ['COMPLETED', 'CLOSED'], true) ? 1 : 0,
                ]);
                $this->touchCreatedAt('job_issues', $issueId, date('Y-m-d H:i:s', strtotime($openedAt . ' +3 hour')));
            }

            job_recalculate_estimate($jobId);
            $this->progressJobToStatus($jobId, $garageId, $targetStatus, $openedAt);

            foreach ($outsourcedWorksForJob as $work) {
                $workId = (int) $work['id'];
                $agreedCost = (float) $work['agreed_cost'];
                $status = (string) $work['status'];
                $payNow = $status === 'PAID' || ($status === 'PAYABLE' && $this->chance(45));
                if ($payNow && $agreedCost > 0) {
                    $firstPayment = $status === 'PAID'
                        ? $agreedCost
                        : round($agreedCost * $this->randomFloat(0.35, 0.75, 2), 2);
                    $paymentDate = date('Y-m-d', strtotime((string) $work['opened_at'] . ' +4 day'));
                    $paymentId = $this->insert('outsourced_work_payments', [
                        'outsourced_work_id' => $workId,
                        'company_id' => $this->companyId,
                        'garage_id' => (int) $work['garage_id'],
                        'payment_date' => $paymentDate,
                        'entry_type' => 'PAYMENT',
                        'amount' => $firstPayment,
                        'payment_mode' => $this->pick(['BANK_TRANSFER', 'UPI', 'CASH']),
                        'reference_no' => 'OWP-' . $workId . '-1',
                        'notes' => 'Outsourced payment seeded',
                        'created_by' => $this->actorUserId,
                    ]);
                    $this->touchCreatedAt('outsourced_work_payments', $paymentId, $paymentDate . ' 16:40:00');
                    finance_record_expense_for_outsourced_payment(
                        $paymentId,
                        $workId,
                        $this->companyId,
                        (int) $work['garage_id'],
                        $firstPayment,
                        $paymentDate,
                        'BANK_TRANSFER',
                        'Outsourced payment seeded',
                        false,
                        $this->actorUserId
                    );

                    if ($reversalCandidateOutsourcePaymentId === 0 && $firstPayment > 0) {
                        $reversalCandidateOutsourcePaymentId = $paymentId;
                        $reversalCandidateWorkId = $workId;
                        $reversalCandidateGarageId = (int) $work['garage_id'];
                    }
                }
                $this->refreshOutsourcedWorkStatusAndLabor($workId);
            }

            $this->jobRows[] = [
                'id' => $jobId,
                'garage_id' => $garageId,
                'vehicle_id' => $vehicleId,
                'target_status' => $targetStatus,
                'opened_at' => $openedAt,
            ];
        }

        if ($reversalCandidateOutsourcePaymentId > 0) {
            $payment = $this->pdo->prepare(
                'SELECT amount
                 FROM outsourced_work_payments
                 WHERE id = :id'
            );
            $payment->execute(['id' => $reversalCandidateOutsourcePaymentId]);
            $amount = round((float) $payment->fetchColumn(), 2);
            if ($amount > 0) {
                $reverseDate = $this->randomDate($this->startDate, $this->endDate);
                $reversalId = $this->insert('outsourced_work_payments', [
                    'outsourced_work_id' => $reversalCandidateWorkId,
                    'company_id' => $this->companyId,
                    'garage_id' => $reversalCandidateGarageId,
                    'payment_date' => $reverseDate,
                    'entry_type' => 'REVERSAL',
                    'amount' => -$amount,
                    'payment_mode' => 'ADJUSTMENT',
                    'reference_no' => 'REV-' . $reversalCandidateOutsourcePaymentId,
                    'notes' => 'Demo reversal for outsourced payment',
                    'reversed_payment_id' => $reversalCandidateOutsourcePaymentId,
                    'created_by' => $this->actorUserId,
                ]);
                $this->touchCreatedAt('outsourced_work_payments', $reversalId, $reverseDate . ' 17:50:00');
                finance_record_expense_for_outsourced_payment(
                    $reversalId,
                    $reversalCandidateWorkId,
                    $this->companyId,
                    $reversalCandidateGarageId,
                    $amount,
                    $reverseDate,
                    'ADJUSTMENT',
                    'Demo reversal for outsourced payment',
                    true,
                    $this->actorUserId
                );
                $this->refreshOutsourcedWorkStatusAndLabor($reversalCandidateWorkId);
            }
        }
    }

    private function seedInvoicesAndCollections(): void
    {
        $closedJobs = array_values(array_filter(
            $this->jobRows,
            static fn (array $row): bool => strtoupper((string) ($row['target_status'] ?? '')) === 'CLOSED'
        ));
        if ($closedJobs === []) {
            return;
        }
        shuffle($closedJobs);

        $billableCount = max(45, count($closedJobs) - 8);
        $billableCount = min($billableCount, count($closedJobs));
        $billableJobs = array_slice($closedJobs, 0, $billableCount);

        $mixedInvoiceId = 0;
        $paymentReversalCandidate = ['invoice_id' => 0, 'payment_id' => 0, 'garage_id' => 0];
        foreach ($billableJobs as $idx => $job) {
            $jobId = (int) ($job['id'] ?? 0);
            $garageId = (int) ($job['garage_id'] ?? 0);
            if ($jobId <= 0 || $garageId <= 0) {
                continue;
            }

            $invoiceDate = $this->randomDate(substr((string) ($job['opened_at'] ?? $this->startDate), 0, 10), $this->endDate);
            $dueDate = date('Y-m-d', strtotime($invoiceDate . ' +7 day'));
            $invoiceId = $this->createInvoiceFromClosedJob(
                $jobId,
                $garageId,
                $invoiceDate,
                $dueDate,
                'Auto generated from closed job for demo'
            );
            if ($invoiceId <= 0) {
                continue;
            }

            $this->invoiceRows[] = [
                'id' => $invoiceId,
                'job_id' => $jobId,
                'garage_id' => $garageId,
                'invoice_date' => $invoiceDate,
            ];

            $grandTotal = $this->invoiceGrandTotal($invoiceId);
            $scenario = $this->pick(['PAID', 'PARTIAL', 'UNPAID', 'PAID', 'PARTIAL']);
            if ($idx % 10 === 0) {
                $scenario = 'UNPAID';
            }
            if ($scenario === 'UNPAID') {
                continue;
            }

            if ($scenario === 'PAID') {
                if ($mixedInvoiceId === 0 && $grandTotal > 5000) {
                    $half = round($grandTotal * 0.45, 2);
                    $paymentA = $this->recordInvoicePayment($invoiceId, $garageId, $half, $invoiceDate, 'UPI', 'UPI split payment');
                    $remaining = round($grandTotal - $half, 2);
                    $paymentB = $this->recordInvoicePayment($invoiceId, $garageId, $remaining, date('Y-m-d', strtotime($invoiceDate . ' +1 day')), 'CARD', 'Card split payment');
                    if ($paymentA > 0 || $paymentB > 0) {
                        $mixedInvoiceId = $invoiceId;
                        if ($paymentA > 0) {
                            $paymentReversalCandidate = ['invoice_id' => $invoiceId, 'payment_id' => $paymentA, 'garage_id' => $garageId];
                        }
                    }
                } else {
                    $paymentId = $this->recordInvoicePayment(
                        $invoiceId,
                        $garageId,
                        $grandTotal,
                        $invoiceDate,
                        $this->pick(['CASH', 'UPI', 'CARD', 'BANK_TRANSFER']),
                        'Full settlement'
                    );
                    if ($paymentReversalCandidate['payment_id'] === 0 && $paymentId > 0) {
                        $paymentReversalCandidate = ['invoice_id' => $invoiceId, 'payment_id' => $paymentId, 'garage_id' => $garageId];
                    }
                }
            } else {
                $partialAmount = round($grandTotal * $this->randomFloat(0.25, 0.7, 2), 2);
                $partialAmount = max(1.0, min($partialAmount, $grandTotal - 1));
                $paymentId = $this->recordInvoicePayment(
                    $invoiceId,
                    $garageId,
                    $partialAmount,
                    $invoiceDate,
                    $this->pick(['CASH', 'UPI', 'BANK_TRANSFER']),
                    'Advance collected'
                );
                if ($paymentReversalCandidate['payment_id'] === 0 && $paymentId > 0) {
                    $paymentReversalCandidate = ['invoice_id' => $invoiceId, 'payment_id' => $paymentId, 'garage_id' => $garageId];
                }
            }
        }

        if ($paymentReversalCandidate['payment_id'] > 0) {
            $this->reverseInvoicePayment(
                (int) $paymentReversalCandidate['payment_id'],
                (int) $paymentReversalCandidate['garage_id'],
                'Demo payment reversal entry'
            );
        }

        $cancelledDone = false;
        $cancelCandidatesStmt = $this->pdo->prepare(
            'SELECT id, garage_id
             FROM invoices
             WHERE company_id = :company_id
               AND invoice_status = "FINALIZED"
               AND payment_status = "UNPAID"
             ORDER BY grand_total ASC, id ASC'
        );
        $cancelCandidatesStmt->execute(['company_id' => $this->companyId]);
        foreach ($cancelCandidatesStmt->fetchAll() as $candidate) {
            $cancelledDone = $this->cancelInvoice(
                (int) ($candidate['id'] ?? 0),
                (int) ($candidate['garage_id'] ?? 1),
                'Customer requested cancellation before delivery'
            );
            if ($cancelledDone) {
                break;
            }
        }

        if (!$cancelledDone) {
            $activeVehicles = array_values(array_filter($this->vehicleRows, static fn (array $row): bool => (bool) ($row['is_active'] ?? false)));
            $vehicle = $this->pick($activeVehicles);
            if (is_array($vehicle)) {
                $vehicleId = (int) ($vehicle['id'] ?? 0);
                $customerId = (int) ($vehicle['customer_id'] ?? 0);
                $garageId = $this->garageForCity((string) ($vehicle['city'] ?? 'Pune'));
                if ($vehicleId > 0 && $customerId > 0) {
                    $openedAt = $this->randomDate($this->startDate, $this->endDate) . ' 10:20:00';
                    $jobId = $this->insert('job_cards', [
                        'company_id' => $this->companyId,
                        'garage_id' => $garageId,
                        'job_number' => job_generate_number($this->pdo, $garageId),
                        'customer_id' => $customerId,
                        'vehicle_id' => $vehicleId,
                        'odometer_km' => $this->nextOdometerReading($vehicleId),
                        'service_advisor_id' => $this->userIds['manager'] ?? $this->actorUserId,
                        'complaint' => 'Final inspection and cleaning',
                        'status' => 'OPEN',
                        'priority' => 'LOW',
                        'estimated_cost' => 0,
                        'opened_at' => $openedAt,
                        'status_code' => 'ACTIVE',
                        'created_by' => $this->actorUserId,
                        'updated_by' => $this->actorUserId,
                    ]);
                    $service = $this->serviceRows['DT-001'] ?? $this->pick(array_values($this->serviceRows));
                    $this->insert('job_labor', [
                        'job_card_id' => $jobId,
                        'service_id' => (int) ($service['id'] ?? 0),
                        'execution_type' => 'IN_HOUSE',
                        'description' => (string) ($service['service_name'] ?? 'Inspection labor'),
                        'quantity' => 1,
                        'unit_price' => (float) ($service['default_rate'] ?? 1200),
                        'gst_rate' => (float) ($service['gst_rate'] ?? 18),
                        'total_amount' => (float) ($service['default_rate'] ?? 1200),
                    ]);
                    job_recalculate_estimate($jobId);
                    $this->progressJobToStatus($jobId, $garageId, 'CLOSED', $openedAt);

                    $invoiceDate = $this->randomDate(substr($openedAt, 0, 10), $this->endDate);
                    $invoiceId = $this->createInvoiceFromClosedJob(
                        $jobId,
                        $garageId,
                        $invoiceDate,
                        date('Y-m-d', strtotime($invoiceDate . ' +7 day')),
                        'Cancellation scenario invoice'
                    );
                    if ($invoiceId > 0) {
                        $this->cancelInvoice($invoiceId, $garageId, 'Demo cancelled invoice edge case');
                    }
                }
            }
        }
    }

    private function seedEstimates(): void
    {
        require_once __DIR__ . '/modules/estimates/workflow.php';

        $vehicles = array_values(array_filter($this->vehicleRows, static fn (array $row): bool => (bool) ($row['is_active'] ?? false)));
        if ($vehicles === [] || $this->serviceRows === [] || $this->partRows === []) {
            return;
        }

        $statusPlan = ['DRAFT', 'DRAFT', 'DRAFT', 'APPROVED', 'APPROVED', 'APPROVED', 'REJECTED', 'REJECTED', 'APPROVED', 'APPROVED', 'DRAFT', 'APPROVED'];
        foreach ($statusPlan as $idx => $status) {
            $vehicle = $this->pick($vehicles);
            $vehicleId = (int) ($vehicle['id'] ?? 0);
            $customerId = (int) ($vehicle['customer_id'] ?? 0);
            if ($vehicleId <= 0 || $customerId <= 0) {
                continue;
            }

            $garageId = $this->garageForCity((string) ($vehicle['city'] ?? 'Pune'));
            $estimateDate = $this->randomDate($this->startDate, $this->endDate);
            $estimateNumber = estimate_generate_number($this->pdo, $garageId);

            $estimateId = $this->insert('estimates', [
                'company_id' => $this->companyId,
                'garage_id' => $garageId,
                'estimate_number' => $estimateNumber,
                'customer_id' => $customerId,
                'vehicle_id' => $vehicleId,
                'complaint' => $this->pick([
                    'General service and inspection quote',
                    'AC and electrical repair estimation',
                    'Suspension overhaul estimate',
                    'Body dent and paint quote',
                ]),
                'notes' => 'Estimate prepared after initial inspection',
                'estimate_status' => 'DRAFT',
                'estimate_total' => 0,
                'valid_until' => date('Y-m-d', strtotime($estimateDate . ' +10 day')),
                'status_code' => 'ACTIVE',
                'created_by' => $this->actorUserId,
                'updated_by' => $this->actorUserId,
            ]);
            $createdAt = $estimateDate . ' ' . $this->pick(['11:05:00', '13:40:00', '16:25:00']);
            $this->touchCreatedAt('estimates', $estimateId, $createdAt);
            $this->touchUpdatedAt('estimates', $estimateId, $createdAt);

            $serviceLines = $this->pick([1, 2, 2, 3]);
            for ($s = 0; $s < $serviceLines; $s++) {
                $service = $this->pick(array_values($this->serviceRows));
                $qty = (float) $this->pick([1, 1, 1.5, 2]);
                $unit = round(((float) ($service['default_rate'] ?? 0)) * $this->randomFloat(0.95, 1.08, 2), 2);
                $lineId = $this->insert('estimate_services', [
                    'estimate_id' => $estimateId,
                    'service_id' => (int) ($service['id'] ?? 0),
                    'description' => (string) ($service['service_name'] ?? 'Service line'),
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'gst_rate' => (float) ($service['gst_rate'] ?? 18),
                    'total_amount' => round($qty * $unit, 2),
                ]);
                $this->touchCreatedAt('estimate_services', $lineId, $createdAt);
                $this->touchUpdatedAt('estimate_services', $lineId, $createdAt);
            }

            $partLines = $this->pick([1, 1, 2]);
            for ($p = 0; $p < $partLines; $p++) {
                $part = $this->pick($this->partRows);
                $qty = (float) $this->pick([1, 1, 2]);
                $unit = round(((float) $part['selling_price']) * $this->randomFloat(0.97, 1.03, 2), 2);
                $lineId = $this->insert('estimate_parts', [
                    'estimate_id' => $estimateId,
                    'part_id' => (int) $part['id'],
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'gst_rate' => (float) $part['gst_rate'],
                    'total_amount' => round($qty * $unit, 2),
                ]);
                $this->touchCreatedAt('estimate_parts', $lineId, $createdAt);
                $this->touchUpdatedAt('estimate_parts', $lineId, $createdAt);
            }

            estimate_recalculate_total($estimateId);
            estimate_append_history($estimateId, 'CREATE', null, 'DRAFT', 'Estimate created', null);

            if ($status === 'REJECTED') {
                $rejectAt = date('Y-m-d H:i:s', strtotime($createdAt . ' +1 day'));
                $this->pdo->prepare(
                    'UPDATE estimates
                     SET estimate_status = "REJECTED",
                         rejected_at = :rejected_at,
                         reject_reason = :reject_reason,
                         updated_by = :updated_by,
                         updated_at = :updated_at
                     WHERE id = :id'
                )->execute([
                    'rejected_at' => $rejectAt,
                    'reject_reason' => 'Customer postponed decision',
                    'updated_by' => $this->actorUserId,
                    'updated_at' => $rejectAt,
                    'id' => $estimateId,
                ]);
                estimate_append_history($estimateId, 'STATUS_CHANGE', 'DRAFT', 'REJECTED', 'Customer postponed decision', null);
            } elseif ($status === 'APPROVED') {
                $approveAt = date('Y-m-d H:i:s', strtotime($createdAt . ' +1 day'));
                $this->pdo->prepare(
                    'UPDATE estimates
                     SET estimate_status = "APPROVED",
                         approved_at = :approved_at,
                         updated_by = :updated_by,
                         updated_at = :updated_at
                     WHERE id = :id'
                )->execute([
                    'approved_at' => $approveAt,
                    'updated_by' => $this->actorUserId,
                    'updated_at' => $approveAt,
                    'id' => $estimateId,
                ]);
                estimate_append_history($estimateId, 'STATUS_CHANGE', 'DRAFT', 'APPROVED', 'Approved by customer', null);

                if ($idx % 4 === 0) {
                    $result = estimate_convert_to_job_card($estimateId, $this->companyId, $garageId, $this->actorUserId);
                    $convertedJobId = (int) ($result['job_id'] ?? 0);
                    if ($convertedJobId > 0) {
                        $this->jobRows[] = [
                            'id' => $convertedJobId,
                            'garage_id' => $garageId,
                            'vehicle_id' => $vehicleId,
                            'target_status' => 'OPEN',
                            'opened_at' => date('Y-m-d H:i:s', strtotime($approveAt . ' +2 hour')),
                        ];
                    }
                }
            }

            $this->estimateRows[] = [
                'id' => $estimateId,
                'garage_id' => $garageId,
                'status' => $status,
            ];
        }
    }

    private function seedPayrollAndStaffFinance(): void
    {
        $structureSeed = [
            ['user_key' => 'owner', 'garage_id' => 1, 'type' => 'MONTHLY', 'base' => 90000, 'commission' => 0.0, 'ot' => null],
            ['user_key' => 'manager', 'garage_id' => 1, 'type' => 'MONTHLY', 'base' => 52000, 'commission' => 0.5, 'ot' => 450],
            ['user_key' => 'mechanic_pune', 'garage_id' => 1, 'type' => 'MONTHLY', 'base' => 32000, 'commission' => 1.2, 'ot' => 280],
            ['user_key' => 'accountant', 'garage_id' => 1, 'type' => 'MONTHLY', 'base' => 38000, 'commission' => 0.0, 'ot' => null],
            ['user_key' => 'mechanic_mumbai_1', 'garage_id' => 2, 'type' => 'PER_DAY', 'base' => 1400, 'commission' => 0.8, 'ot' => 200],
            ['user_key' => 'mechanic_mumbai_2', 'garage_id' => 2, 'type' => 'PER_JOB', 'base' => 22000, 'commission' => 2.5, 'ot' => 250],
            ['user_key' => 'helper', 'garage_id' => 2, 'type' => 'PER_DAY', 'base' => 900, 'commission' => 0.0, 'ot' => 120],
        ];

        foreach ($structureSeed as $structure) {
            $userId = (int) ($this->userIds[(string) $structure['user_key']] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $this->insert('payroll_salary_structures', [
                'user_id' => $userId,
                'company_id' => $this->companyId,
                'garage_id' => (int) $structure['garage_id'],
                'salary_type' => (string) $structure['type'],
                'base_amount' => (float) $structure['base'],
                'commission_rate' => (float) $structure['commission'],
                'overtime_rate' => $structure['ot'] !== null ? (float) $structure['ot'] : null,
                'status_code' => 'ACTIVE',
                'created_by' => $this->actorUserId,
                'updated_by' => $this->actorUserId,
            ]);
        }

        $advances = [
            ['user_key' => 'helper', 'garage_id' => 2, 'date' => date('Y-m-d', strtotime($this->endDate . ' -80 day')), 'amount' => 6000, 'notes' => 'Festival advance'],
            ['user_key' => 'mechanic_mumbai_1', 'garage_id' => 2, 'date' => date('Y-m-d', strtotime($this->endDate . ' -72 day')), 'amount' => 8000, 'notes' => 'Medical advance'],
            ['user_key' => 'accountant', 'garage_id' => 1, 'date' => date('Y-m-d', strtotime($this->endDate . ' -66 day')), 'amount' => 5000, 'notes' => 'Family function advance'],
        ];
        foreach ($advances as $advance) {
            $userId = (int) ($this->userIds[(string) $advance['user_key']] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $advanceId = $this->insert('payroll_advances', [
                'user_id' => $userId,
                'company_id' => $this->companyId,
                'garage_id' => (int) $advance['garage_id'],
                'advance_date' => (string) $advance['date'],
                'amount' => (float) $advance['amount'],
                'applied_amount' => 0,
                'status' => 'OPEN',
                'notes' => (string) $advance['notes'],
                'created_by' => $this->actorUserId,
                'updated_by' => $this->actorUserId,
            ]);
            $this->touchCreatedAt('payroll_advances', $advanceId, (string) $advance['date'] . ' 11:00:00');
            $this->touchUpdatedAt('payroll_advances', $advanceId, (string) $advance['date'] . ' 11:00:00');
        }

        $loanUserId = (int) ($this->userIds['mechanic_pune'] ?? 0);
        $loanId = 0;
        if ($loanUserId > 0) {
            $loanDate = date('Y-m-d', strtotime($this->endDate . ' -78 day'));
            $loanId = $this->insert('payroll_loans', [
                'user_id' => $loanUserId,
                'company_id' => $this->companyId,
                'garage_id' => 1,
                'loan_date' => $loanDate,
                'total_amount' => 60000,
                'emi_amount' => 5000,
                'paid_amount' => 0,
                'status' => 'ACTIVE',
                'notes' => 'Two-wheeler purchase loan',
                'created_by' => $this->actorUserId,
                'updated_by' => $this->actorUserId,
            ]);
            $this->touchCreatedAt('payroll_loans', $loanId, $loanDate . ' 10:10:00');
            $this->touchUpdatedAt('payroll_loans', $loanId, $loanDate . ' 10:10:00');
        }

        $months = [
            date('Y-m', strtotime($this->endDate . ' -3 month')),
            date('Y-m', strtotime($this->endDate . ' -2 month')),
            date('Y-m', strtotime($this->endDate . ' -1 month')),
        ];
        $staffByGarage = [
            1 => ['owner', 'manager', 'mechanic_pune', 'accountant'],
            2 => ['mechanic_mumbai_1', 'mechanic_mumbai_2', 'helper'],
        ];

        foreach ($months as $monthIdx => $month) {
            foreach ($staffByGarage as $garageId => $staffKeys) {
                $sheetId = $this->insert('payroll_salary_sheets', [
                    'company_id' => $this->companyId,
                    'garage_id' => $garageId,
                    'salary_month' => $month,
                    'status' => 'OPEN',
                    'total_gross' => 0,
                    'total_deductions' => 0,
                    'total_payable' => 0,
                    'total_paid' => 0,
                    'created_by' => $this->actorUserId,
                ]);
                $sheetCreatedAt = date('Y-m-d H:i:s', strtotime($month . '-01 09:00:00'));
                $this->touchCreatedAt('payroll_salary_sheets', $sheetId, $sheetCreatedAt);

                $sheetGross = 0.0;
                $sheetDeductions = 0.0;
                $sheetPayable = 0.0;
                $sheetPaid = 0.0;

                foreach ($staffKeys as $staffKey) {
                    $userId = (int) ($this->userIds[$staffKey] ?? 0);
                    if ($userId <= 0) {
                        continue;
                    }
                    $structure = $this->findSalaryStructure($userId, $garageId);
                    if ($structure === null) {
                        continue;
                    }

                    $salaryType = (string) $structure['salary_type'];
                    $baseAmount = (float) $structure['base_amount'];
                    $commissionRate = (float) $structure['commission_rate'];
                    $overtimeRate = (float) ($structure['overtime_rate'] ?? 0);
                    $daysPresent = $this->pick([23, 24, 25, 26]);

                    $computedBase = $baseAmount;
                    if ($salaryType === 'PER_DAY') {
                        $computedBase = round($baseAmount * $daysPresent, 2);
                    } elseif ($salaryType === 'PER_JOB') {
                        $computedBase = round($baseAmount + ($this->pick([8, 9, 10, 11]) * 450), 2);
                    }

                    $commissionBase = $computedBase;
                    $commissionAmount = round($commissionBase * $commissionRate / 100, 2);
                    $overtimeHours = $this->pick([0, 1, 2, 3, 4, 5]);
                    $overtimeAmount = round($overtimeHours * $overtimeRate, 2);
                    $gross = round($computedBase + $commissionAmount + $overtimeAmount, 2);

                    $advanceDeduction = $this->applyAdvanceToUser($userId, $garageId, $this->pick([0, 500, 1000, 1500]));
                    $loanDeduction = 0.0;
                    if ($loanId > 0 && $userId === $loanUserId) {
                        $loanDeduction = $this->applyLoanDeduction($loanId, $sheetId, $garageId, $month, 5000);
                    }
                    $manualDeduction = $this->pick([0, 0, 250, 500]);
                    $totalDeduction = round($advanceDeduction + $loanDeduction + $manualDeduction, 2);
                    $net = round(max(0, $gross - $totalDeduction), 2);

                    $itemId = $this->insert('payroll_salary_items', [
                        'sheet_id' => $sheetId,
                        'user_id' => $userId,
                        'salary_type' => $salaryType,
                        'base_amount' => $computedBase,
                        'commission_base' => $commissionBase,
                        'commission_rate' => $commissionRate,
                        'commission_amount' => $commissionAmount,
                        'overtime_hours' => $overtimeHours,
                        'overtime_rate' => $overtimeRate > 0 ? $overtimeRate : null,
                        'overtime_amount' => $overtimeAmount,
                        'advance_deduction' => $advanceDeduction,
                        'loan_deduction' => $loanDeduction,
                        'manual_deduction' => $manualDeduction,
                        'gross_amount' => $gross,
                        'net_payable' => $net,
                        'paid_amount' => 0,
                        'deductions_applied' => 1,
                        'status' => 'PENDING',
                        'notes' => 'Auto generated demo salary row',
                    ]);
                    $itemCreatedAt = date('Y-m-d H:i:s', strtotime($month . '-01 09:30:00'));
                    $this->touchCreatedAt('payroll_salary_items', $itemId, $itemCreatedAt);

                    $targetPaid = $net;
                    if ($staffKey === 'helper' && $monthIdx === 1) {
                        $targetPaid = round($net * 0.62, 2);
                    }
                    if ($targetPaid > 0) {
                        $firstPayment = $targetPaid;
                        if ($targetPaid > 20000 && $this->chance(35)) {
                            $firstPayment = round($targetPaid * 0.7, 2);
                        }

                        $paymentDate = date('Y-m-d', strtotime($month . '-28'));
                        $paymentId = $this->insert('payroll_salary_payments', [
                            'sheet_id' => $sheetId,
                            'salary_item_id' => $itemId,
                            'user_id' => $userId,
                            'company_id' => $this->companyId,
                            'garage_id' => $garageId,
                            'payment_date' => $paymentDate,
                            'entry_type' => 'PAYMENT',
                            'amount' => $firstPayment,
                            'payment_mode' => $this->pick(['BANK_TRANSFER', 'UPI', 'CASH']),
                            'reference_no' => 'SAL-' . $sheetId . '-' . $itemId . '-1',
                            'notes' => 'Salary disbursement',
                            'created_by' => $this->actorUserId,
                        ]);
                        $this->touchCreatedAt('payroll_salary_payments', $paymentId, $paymentDate . ' 18:00:00');
                        finance_record_expense_for_salary_payment(
                            $paymentId,
                            $itemId,
                            $userId,
                            $this->companyId,
                            $garageId,
                            $firstPayment,
                            $paymentDate,
                            'BANK_TRANSFER',
                            'Salary disbursement',
                            false,
                            $this->actorUserId
                        );

                        if ($firstPayment + 0.009 < $targetPaid) {
                            $second = round($targetPaid - $firstPayment, 2);
                            $paymentId = $this->insert('payroll_salary_payments', [
                                'sheet_id' => $sheetId,
                                'salary_item_id' => $itemId,
                                'user_id' => $userId,
                                'company_id' => $this->companyId,
                                'garage_id' => $garageId,
                                'payment_date' => date('Y-m-d', strtotime($paymentDate . ' +2 day')),
                                'entry_type' => 'PAYMENT',
                                'amount' => $second,
                                'payment_mode' => 'UPI',
                                'reference_no' => 'SAL-' . $sheetId . '-' . $itemId . '-2',
                                'notes' => 'Salary split settlement',
                                'created_by' => $this->actorUserId,
                            ]);
                            $this->touchCreatedAt('payroll_salary_payments', $paymentId, date('Y-m-d', strtotime($paymentDate . ' +2 day')) . ' 17:15:00');
                            finance_record_expense_for_salary_payment(
                                $paymentId,
                                $itemId,
                                $userId,
                                $this->companyId,
                                $garageId,
                                $second,
                                date('Y-m-d', strtotime($paymentDate . ' +2 day')),
                                'UPI',
                                'Salary split settlement',
                                false,
                                $this->actorUserId
                            );
                        }
                    }

                    $paid = $this->salaryItemPaidAmount($itemId);
                    $status = 'PENDING';
                    if ($paid + 0.009 >= $net) {
                        $status = 'PAID';
                    } elseif ($paid > 0) {
                        $status = 'PARTIAL';
                    }

                    $this->pdo->prepare(
                        'UPDATE payroll_salary_items
                         SET paid_amount = :paid_amount,
                             status = :status
                         WHERE id = :id'
                    )->execute([
                        'paid_amount' => $paid,
                        'status' => $status,
                        'id' => $itemId,
                    ]);

                    $sheetGross += $gross;
                    $sheetDeductions += $totalDeduction;
                    $sheetPayable += $net;
                    $sheetPaid += $paid;
                }

                $sheetStatus = ($sheetPaid + 0.009 >= $sheetPayable) ? 'LOCKED' : 'OPEN';
                $this->pdo->prepare(
                    'UPDATE payroll_salary_sheets
                     SET status = :status,
                         total_gross = :total_gross,
                         total_deductions = :total_deductions,
                         total_payable = :total_payable,
                         total_paid = :total_paid,
                         locked_at = :locked_at,
                         locked_by = :locked_by
                     WHERE id = :id'
                )->execute([
                    'status' => $sheetStatus,
                    'total_gross' => round($sheetGross, 2),
                    'total_deductions' => round($sheetDeductions, 2),
                    'total_payable' => round($sheetPayable, 2),
                    'total_paid' => round($sheetPaid, 2),
                    'locked_at' => $sheetStatus === 'LOCKED' ? date('Y-m-d H:i:s', strtotime($month . '-28 20:00:00')) : null,
                    'locked_by' => $sheetStatus === 'LOCKED' ? $this->actorUserId : null,
                    'id' => $sheetId,
                ]);
            }
        }
    }

    private function seedManualExpenses(): void
    {
        $categoryIds = [];
        $categoryMap = [
            'Rent',
            'Electricity',
            'Internet',
            'Tea & Snacks',
            'Housekeeping',
            'Miscellaneous',
            'Marketing',
            'Travel',
        ];
        foreach ([1, 2] as $garageId) {
            foreach ($categoryMap as $categoryName) {
                $categoryId = $this->insert('expense_categories', [
                    'company_id' => $this->companyId,
                    'garage_id' => $garageId,
                    'category_name' => $categoryName,
                    'status_code' => 'ACTIVE',
                    'created_by' => $this->actorUserId,
                    'updated_by' => $this->actorUserId,
                ]);
                $categoryIds[$garageId][$categoryName] = $categoryId;
            }
        }

        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $months[] = date('Y-m', strtotime($this->endDate . ' -' . $i . ' month'));
        }

        foreach ([1, 2] as $garageId) {
            foreach ($months as $month) {
                $rentDate = $month . '-05';
                $rentAmount = $garageId === 1 ? 85000 : 96000;
                $this->insert('expenses', [
                    'company_id' => $this->companyId,
                    'garage_id' => $garageId,
                    'category_id' => (int) ($categoryIds[$garageId]['Rent'] ?? 0),
                    'expense_date' => $rentDate,
                    'amount' => $rentAmount,
                    'paid_to' => $garageId === 1 ? 'Baner Commercial Estate' : 'Mulund Trade Park',
                    'payment_mode' => 'BANK_TRANSFER',
                    'notes' => 'Monthly workshop rent',
                    'source_type' => 'MANUAL',
                    'source_id' => null,
                    'entry_type' => 'EXPENSE',
                    'reversed_expense_id' => null,
                    'created_by' => $this->actorUserId,
                    'updated_by' => $this->actorUserId,
                ]);

                $electricityDate = $month . '-18';
                $electricityAmount = $garageId === 1 ? $this->pick([11800, 12450, 13100]) : $this->pick([10900, 11600, 12200]);
                $this->insert('expenses', [
                    'company_id' => $this->companyId,
                    'garage_id' => $garageId,
                    'category_id' => (int) ($categoryIds[$garageId]['Electricity'] ?? 0),
                    'expense_date' => $electricityDate,
                    'amount' => $electricityAmount,
                    'paid_to' => 'MSEDCL',
                    'payment_mode' => 'UPI',
                    'notes' => 'Monthly electricity bill',
                    'source_type' => 'MANUAL',
                    'source_id' => null,
                    'entry_type' => 'EXPENSE',
                    'reversed_expense_id' => null,
                    'created_by' => $this->actorUserId,
                    'updated_by' => $this->actorUserId,
                ]);
            }
        }

        for ($i = 0; $i < 140; $i++) {
            $garageId = $i % 3 === 0 ? 2 : 1;
            $category = $this->pick(['Internet', 'Tea & Snacks', 'Housekeeping', 'Miscellaneous', 'Marketing', 'Travel']);
            $expenseDate = $this->randomDate($this->startDate, $this->endDate);
            $amount = match ($category) {
                'Internet' => $this->pick([1499, 1599, 1899]),
                'Tea & Snacks' => $this->pick([280, 340, 460, 520]),
                'Housekeeping' => $this->pick([650, 800, 950, 1200]),
                'Marketing' => $this->pick([1800, 2400, 3200, 4500]),
                'Travel' => $this->pick([500, 750, 980, 1300]),
                default => $this->pick([300, 450, 600, 850]),
            };
            $this->insert('expenses', [
                'company_id' => $this->companyId,
                'garage_id' => $garageId,
                'category_id' => (int) ($categoryIds[$garageId][$category] ?? 0),
                'expense_date' => $expenseDate,
                'amount' => (float) $amount,
                'paid_to' => $this->pick(['Local supplier', 'Service provider', 'Cash vendor', 'Online vendor']),
                'payment_mode' => $this->pick(['CASH', 'UPI', 'CARD']),
                'notes' => $category . ' expense entry',
                'source_type' => 'MANUAL',
                'source_id' => null,
                'entry_type' => 'EXPENSE',
                'reversed_expense_id' => null,
                'created_by' => $this->actorUserId,
                'updated_by' => $this->actorUserId,
            ]);
        }
    }

    private function runIntegrityChecks(): void
    {
        $checks = [];
        $checks['customer_count'] = (int) $this->pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
        $checks['part_count'] = (int) $this->pdo->query('SELECT COUNT(*) FROM parts')->fetchColumn();
        $checks['job_count'] = (int) $this->pdo->query('SELECT COUNT(*) FROM job_cards')->fetchColumn();
        $checks['invoice_count'] = (int) $this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
        $checks['purchase_count'] = (int) $this->pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn();
        $checks['negative_stock_count'] = (int) $this->pdo->query('SELECT COUNT(*) FROM garage_inventory WHERE quantity < 0')->fetchColumn();
        $checks['invalid_invoice_job_link'] = (int) $this->pdo->query(
            'SELECT COUNT(*)
             FROM invoices i
             INNER JOIN job_cards jc ON jc.id = i.job_card_id
             WHERE jc.status <> "CLOSED"'
        )->fetchColumn();
        $checks['stock_posted_on_non_closed'] = (int) $this->pdo->query(
            'SELECT COUNT(*)
             FROM job_cards
             WHERE status <> "CLOSED"
               AND stock_posted_at IS NOT NULL'
        )->fetchColumn();

        $errors = [];
        if ($checks['customer_count'] < 50 || $checks['customer_count'] > 80) {
            $errors[] = 'Customer count is outside expected range (50-80).';
        }
        if ($checks['part_count'] < 100) {
            $errors[] = 'Part count is below 100.';
        }
        if ($checks['job_count'] < 100) {
            $errors[] = 'Job count is below 100.';
        }
        if ($checks['purchase_count'] < 15) {
            $errors[] = 'Purchase count is below 15.';
        }
        if ($checks['negative_stock_count'] > 0) {
            $errors[] = 'Negative stock detected.';
        }
        if ($checks['invalid_invoice_job_link'] > 0) {
            $errors[] = 'Invoice linked to non-closed job detected.';
        }
        if ($checks['stock_posted_on_non_closed'] > 0) {
            $errors[] = 'Stock posted on non-closed jobs detected.';
        }

        $resultCode = $errors === [] ? 'PASS' : 'FAIL';
        $this->insert('backup_integrity_checks', [
            'company_id' => $this->companyId,
            'result_code' => $resultCode,
            'issues_count' => count($errors),
            'summary_json' => json_encode(['checks' => $checks, 'errors' => $errors], JSON_UNESCAPED_UNICODE),
            'checked_by' => $this->actorUserId,
            'checked_at' => date('Y-m-d H:i:s'),
        ]);

        if ($errors !== []) {
            throw new RuntimeException('Integrity checks failed: ' . implode(' ', $errors));
        }
    }

    private function printSummary(): void
    {
        $summary = [
            'companies' => (int) $this->pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn(),
            'garages' => (int) $this->pdo->query('SELECT COUNT(*) FROM garages')->fetchColumn(),
            'users' => (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'customers' => (int) $this->pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(),
            'vehicles' => (int) $this->pdo->query('SELECT COUNT(*) FROM vehicles')->fetchColumn(),
            'services' => (int) $this->pdo->query('SELECT COUNT(*) FROM services')->fetchColumn(),
            'parts' => (int) $this->pdo->query('SELECT COUNT(*) FROM parts')->fetchColumn(),
            'jobs' => (int) $this->pdo->query('SELECT COUNT(*) FROM job_cards')->fetchColumn(),
            'invoices' => (int) $this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn(),
            'purchases' => (int) $this->pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn(),
            'outsourced_works' => (int) $this->pdo->query('SELECT COUNT(*) FROM outsourced_works')->fetchColumn(),
            'salary_sheets' => (int) $this->pdo->query('SELECT COUNT(*) FROM payroll_salary_sheets')->fetchColumn(),
            'expenses' => (int) $this->pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn(),
        ];

        $invoiceStatus = $this->pdo->query(
            'SELECT payment_status, COUNT(*) AS cnt
             FROM invoices
             GROUP BY payment_status
             ORDER BY payment_status'
        )->fetchAll();
        $purchaseStatus = $this->pdo->query(
            'SELECT payment_status, COUNT(*) AS cnt
             FROM purchases
             GROUP BY payment_status
             ORDER BY payment_status'
        )->fetchAll();
        $jobStatus = $this->pdo->query(
            'SELECT status, COUNT(*) AS cnt
             FROM job_cards
             GROUP BY status
             ORDER BY status'
        )->fetchAll();

        echo "Demo data seeding completed successfully.\n";
        foreach ($summary as $key => $value) {
            echo str_pad($key, 20, ' ') . ': ' . $value . "\n";
        }

        echo "Invoice payment scenarios:\n";
        foreach ($invoiceStatus as $row) {
            echo '  - ' . (string) ($row['payment_status'] ?? 'UNKNOWN') . ': ' . (int) ($row['cnt'] ?? 0) . "\n";
        }
        echo "Purchase payment scenarios:\n";
        foreach ($purchaseStatus as $row) {
            echo '  - ' . (string) ($row['payment_status'] ?? 'UNKNOWN') . ': ' . (int) ($row['cnt'] ?? 0) . "\n";
        }
        echo "Job workflow scenarios:\n";
        foreach ($jobStatus as $row) {
            echo '  - ' . (string) ($row['status'] ?? 'UNKNOWN') . ': ' . (int) ($row['cnt'] ?? 0) . "\n";
        }

        echo "Edge cases included: inactive customers/vehicles, mixed and reversed payments, cancelled invoice, temporary stock conversion, unpaid outsourced payables, partial salary payouts.\n";
    }

    private function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $col): string => ':' . $col, $columns);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        $stmt = $this->pdo->prepare($sql);

        $params = [];
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $params[$key] = $value ? 1 : 0;
            } else {
                $params[$key] = $value;
            }
        }
        $stmt->execute($params);

        $lastId = (int) $this->pdo->lastInsertId();
        if ($lastId > 0) {
            return $lastId;
        }
        return isset($data['id']) ? (int) $data['id'] : 0;
    }

    private function pick(array $items): mixed
    {
        if ($items === []) {
            return null;
        }
        $key = array_rand($items);
        return $items[$key];
    }

    private function chance(int $percentage): bool
    {
        $percentage = max(0, min(100, $percentage));
        return mt_rand(1, 100) <= $percentage;
    }

    private function randomFloat(float $min, float $max, int $precision = 2): float
    {
        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }
        $factor = 10 ** $precision;
        $scaledMin = (int) round($min * $factor);
        $scaledMax = (int) round($max * $factor);
        if ($scaledMax === $scaledMin) {
            return round($min, $precision);
        }
        return round(mt_rand($scaledMin, $scaledMax) / $factor, $precision);
    }

    private function randomDate(string $fromDate, string $toDate): string
    {
        $fromTs = strtotime($fromDate . ' 00:00:00') ?: time();
        $toTs = strtotime($toDate . ' 23:59:59') ?: $fromTs;
        if ($toTs < $fromTs) {
            [$fromTs, $toTs] = [$toTs, $fromTs];
        }
        return date('Y-m-d', mt_rand($fromTs, $toTs));
    }

    private function randomEngineCcForModel(string $modelName): string
    {
        $model = strtoupper(trim($modelName));
        return match ($model) {
            'BALENO', 'SWIFT' => (string) $this->pick(['1197', '1248']),
            'CRETA', 'SELTOS' => (string) $this->pick(['1497', '1493']),
            'NEXON' => (string) $this->pick(['1199', '1497']),
            'XUV700' => (string) $this->pick(['1999', '2198']),
            'CITY' => (string) $this->pick(['1498']),
            'INNOVA' => (string) $this->pick(['2393']),
            default => (string) $this->pick(['1197', '1498', '1999']),
        };
    }

    private function generatePseudoGstin(string $stateCode, int $seed): string
    {
        $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $pan = '';
        for ($i = 0; $i < 5; $i++) {
            $pan .= $letters[($seed + $i * 7) % 26];
        }
        $pan .= str_pad((string) (($seed * 13) % 10000), 4, '0', STR_PAD_LEFT);
        $pan .= $letters[($seed * 5) % 26];
        $entity = (string) (($seed % 9) + 1);
        $checksum = $letters[($seed * 11) % 26];
        return sprintf('%s%s%sZ%s', $stateCode, $pan, $entity, $checksum);
    }

    private function platePrefixForCity(string $city): string
    {
        $key = strtoupper(trim($city));
        return match ($key) {
            'MUMBAI' => 'MH01',
            'THANE' => 'MH04',
            'NAVI MUMBAI' => 'MH43',
            'PUNE', 'PIMPRI-CHINCHWAD' => 'MH12',
            'NASHIK' => 'MH15',
            'KOLHAPUR' => 'MH09',
            'NAGPUR' => 'MH31',
            'AURANGABAD' => 'MH20',
            'SATARA' => 'MH11',
            'SOLAPUR' => 'MH13',
            default => 'MH14',
        };
    }

    private function buildVehicleNumber(string $prefix, int $counter, int $seed): string
    {
        $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $s1 = $alpha[($seed * 3 + 5) % 26];
        $s2 = $alpha[($seed * 7 + 11) % 26];
        $number = 1000 + ($counter % 9000);
        return sprintf('%s%s%s%04d', $prefix, $s1, $s2, $number);
    }

    private function garageForCity(string $city): int
    {
        $normalized = strtoupper(trim($city));
        if (in_array($normalized, ['MUMBAI', 'THANE', 'NAVI MUMBAI'], true)) {
            return 2;
        }
        return 1;
    }

    private function nextOdometerReading(int $vehicleId): int
    {
        $last = (float) ($this->lastOdometerByVehicle[$vehicleId] ?? 18000.0);
        $next = $last + (float) $this->pick([220, 340, 520, 780, 950, 1200, 1650]);
        $next = round($next, 0);
        $this->lastOdometerByVehicle[$vehicleId] = $next;
        $this->pdo->prepare('UPDATE vehicles SET odometer_km = :odometer_km WHERE id = :id')->execute([
            'odometer_km' => (int) $next,
            'id' => $vehicleId,
        ]);
        return (int) $next;
    }

    private function touchCreatedAt(string $table, int $id, string $timestamp): void
    {
        if ($id <= 0 || !in_array('created_at', table_columns($table), true)) {
            return;
        }
        $sql = sprintf('UPDATE %s SET created_at = :created_at WHERE id = :id', $table);
        $this->pdo->prepare($sql)->execute([
            'created_at' => $timestamp,
            'id' => $id,
        ]);
    }

    private function touchUpdatedAt(string $table, int $id, string $timestamp): void
    {
        if ($id <= 0 || !in_array('updated_at', table_columns($table), true)) {
            return;
        }
        $sql = sprintf('UPDATE %s SET updated_at = :updated_at WHERE id = :id', $table);
        $this->pdo->prepare($sql)->execute([
            'updated_at' => $timestamp,
            'id' => $id,
        ]);
    }

    private function ensureInventoryRow(int $garageId, int $partId): float
    {
        $seedStmt = $this->pdo->prepare(
            'INSERT INTO garage_inventory (garage_id, part_id, quantity)
             VALUES (:garage_id, :part_id, 0)
             ON DUPLICATE KEY UPDATE quantity = quantity'
        );
        $seedStmt->execute([
            'garage_id' => $garageId,
            'part_id' => $partId,
        ]);

        $qtyStmt = $this->pdo->prepare(
            'SELECT quantity
             FROM garage_inventory
             WHERE garage_id = :garage_id
               AND part_id = :part_id
             LIMIT 1'
        );
        $qtyStmt->execute([
            'garage_id' => $garageId,
            'part_id' => $partId,
        ]);
        $qty = round((float) $qtyStmt->fetchColumn(), 2);
        $this->inventoryByGaragePart[$garageId][$partId] = $qty;
        return $qty;
    }

    private function adjustInventory(
        int $garageId,
        int $partId,
        float $delta,
        string $movementType,
        string $referenceType,
        ?int $referenceId,
        ?string $notes,
        ?string $movementAt = null
    ): void {
        $currentQty = $this->ensureInventoryRow($garageId, $partId);
        $nextQty = round($currentQty + $delta, 2);
        if ($nextQty < 0) {
            $nextQty = 0.0;
            $delta = round($nextQty - $currentQty, 2);
        }

        $this->pdo->prepare(
            'UPDATE garage_inventory
             SET quantity = :quantity
             WHERE garage_id = :garage_id
               AND part_id = :part_id'
        )->execute([
            'quantity' => $nextQty,
            'garage_id' => $garageId,
            'part_id' => $partId,
        ]);

        $movementId = $this->insert('inventory_movements', [
            'company_id' => $this->companyId,
            'garage_id' => $garageId,
            'part_id' => $partId,
            'movement_type' => $movementType,
            'quantity' => round(abs($delta), 2),
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'movement_uid' => substr(hash('sha256', $garageId . '|' . $partId . '|' . $movementType . '|' . microtime(true)), 0, 64),
            'notes' => $notes,
            'created_by' => $this->actorUserId,
        ]);
        if ($movementAt !== null) {
            $this->touchCreatedAt('inventory_movements', $movementId, $movementAt);
        }

        $this->inventoryByGaragePart[$garageId][$partId] = $nextQty;
    }

    private function createPurchaseRecord(
        int $garageId,
        int $vendorId,
        string $invoiceNumber,
        string $purchaseDate,
        array $lines,
        string $purchaseStatus,
        string $paymentIntent,
        string $purchaseSource,
        string $notes
    ): int {
        $totals = ['taxable' => 0.0, 'gst' => 0.0, 'grand' => 0.0];
        foreach ($lines as $line) {
            $totals['taxable'] += (float) ($line['taxable_amount'] ?? 0);
            $totals['gst'] += (float) ($line['gst_amount'] ?? 0);
            $totals['grand'] += (float) ($line['total_amount'] ?? 0);
        }
        $totals = [
            'taxable' => round($totals['taxable'], 2),
            'gst' => round($totals['gst'], 2),
            'grand' => round($totals['grand'], 2),
        ];

        $purchaseId = $this->insert('purchases', [
            'company_id' => $this->companyId,
            'garage_id' => $garageId,
            'vendor_id' => $vendorId > 0 ? $vendorId : null,
            'invoice_number' => $invoiceNumber,
            'purchase_date' => $purchaseDate,
            'purchase_source' => $purchaseSource,
            'assignment_status' => $vendorId > 0 ? 'ASSIGNED' : 'UNASSIGNED',
            'purchase_status' => $purchaseStatus,
            'payment_status' => 'UNPAID',
            'status_code' => 'ACTIVE',
            'taxable_amount' => $totals['taxable'],
            'gst_amount' => $totals['gst'],
            'grand_total' => $totals['grand'],
            'notes' => $notes,
            'created_by' => $this->actorUserId,
            'finalized_by' => $purchaseStatus === 'FINALIZED' ? $this->actorUserId : null,
            'finalized_at' => $purchaseStatus === 'FINALIZED' ? ($purchaseDate . ' 14:45:00') : null,
        ]);
        $this->touchCreatedAt('purchases', $purchaseId, $purchaseDate . ' 10:05:00');
        $this->touchUpdatedAt('purchases', $purchaseId, $purchaseDate . ' 14:45:00');

        foreach ($lines as $lineIdx => $line) {
            $itemId = $this->insert('purchase_items', [
                'purchase_id' => $purchaseId,
                'part_id' => (int) ($line['part_id'] ?? 0),
                'quantity' => (float) ($line['quantity'] ?? 0),
                'unit_cost' => (float) ($line['unit_cost'] ?? 0),
                'gst_rate' => (float) ($line['gst_rate'] ?? 0),
                'taxable_amount' => (float) ($line['taxable_amount'] ?? 0),
                'gst_amount' => (float) ($line['gst_amount'] ?? 0),
                'total_amount' => (float) ($line['total_amount'] ?? 0),
            ]);
            $this->touchCreatedAt('purchase_items', $itemId, $purchaseDate . ' 10:20:00');

            $this->adjustInventory(
                $garageId,
                (int) ($line['part_id'] ?? 0),
                (float) ($line['quantity'] ?? 0),
                'IN',
                'PURCHASE',
                $purchaseId,
                'Purchase stock in #' . $purchaseId,
                $purchaseDate . ' 10:25:0' . ($lineIdx % 5)
            );
        }

        if ($paymentIntent !== 'UNPAID' && $totals['grand'] > 0) {
            $amount = $totals['grand'];
            if ($paymentIntent === 'PARTIAL') {
                $amount = round($totals['grand'] * $this->randomFloat(0.35, 0.7, 2), 2);
            }

            $paymentId = $this->insert('purchase_payments', [
                'purchase_id' => $purchaseId,
                'company_id' => $this->companyId,
                'garage_id' => $garageId,
                'payment_date' => $purchaseDate,
                'entry_type' => 'PAYMENT',
                'amount' => $amount,
                'payment_mode' => $this->pick(['BANK_TRANSFER', 'UPI', 'CHEQUE']),
                'reference_no' => 'PAY-' . $purchaseId . '-1',
                'notes' => 'Demo purchase payment',
                'created_by' => $this->actorUserId,
            ]);
            $this->touchCreatedAt('purchase_payments', $paymentId, $purchaseDate . ' 17:20:00');
            finance_record_expense_for_purchase_payment(
                $paymentId,
                $purchaseId,
                $this->companyId,
                $garageId,
                $amount,
                $purchaseDate,
                'BANK_TRANSFER',
                'Demo purchase payment',
                false,
                $this->actorUserId
            );
        }

        $this->refreshPurchasePaymentStatus($purchaseId);
        return $purchaseId;
    }

    private function refreshPurchasePaymentStatus(int $purchaseId): void
    {
        $purchaseStmt = $this->pdo->prepare('SELECT grand_total FROM purchases WHERE id = :id LIMIT 1');
        $purchaseStmt->execute(['id' => $purchaseId]);
        $grandTotal = round((float) $purchaseStmt->fetchColumn(), 2);

        $paidStmt = $this->pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM purchase_payments WHERE purchase_id = :id');
        $paidStmt->execute(['id' => $purchaseId]);
        $paid = round((float) $paidStmt->fetchColumn(), 2);

        $status = 'PARTIAL';
        if ($paid <= 0.009) {
            $status = 'UNPAID';
        } elseif ($paid + 0.009 >= $grandTotal) {
            $status = 'PAID';
        }
        $this->pdo->prepare(
            'UPDATE purchases
             SET payment_status = :payment_status,
                 updated_at = NOW()
             WHERE id = :id'
        )->execute([
            'payment_status' => $status,
            'id' => $purchaseId,
        ]);
        $this->paidPurchaseAmountById[$purchaseId] = $paid;
    }

    private function progressJobToStatus(int $jobId, int $garageId, string $targetStatus, string $openedAt): void
    {
        $targetStatus = job_normalize_status($targetStatus);
        $path = match ($targetStatus) {
            'OPEN' => ['OPEN'],
            'IN_PROGRESS' => ['OPEN', 'IN_PROGRESS'],
            'WAITING_PARTS' => ['OPEN', 'IN_PROGRESS', 'WAITING_PARTS'],
            'COMPLETED' => ['OPEN', 'IN_PROGRESS', 'COMPLETED'],
            'CLOSED' => ['OPEN', 'IN_PROGRESS', 'COMPLETED', 'CLOSED'],
            'CANCELLED' => ['OPEN', 'CANCELLED'],
            default => ['OPEN'],
        };

        $current = 'OPEN';
        foreach ($path as $idx => $status) {
            if ($idx === 0) {
                continue;
            }
            if (!job_can_transition($current, $status)) {
                continue;
            }

            $statusAt = date('Y-m-d H:i:s', strtotime($openedAt . ' +' . ($idx * 7) . ' hour'));
            if ($status === 'CLOSED') {
                try {
                    job_post_inventory_on_close($jobId, $this->companyId, $garageId, $this->actorUserId);
                    $parts = $this->pdo->prepare(
                        'SELECT part_id, SUM(quantity) AS qty
                         FROM job_parts
                         WHERE job_card_id = :job_card_id
                         GROUP BY part_id'
                    );
                    $parts->execute(['job_card_id' => $jobId]);
                    foreach ($parts->fetchAll() as $partRow) {
                        $partId = (int) ($partRow['part_id'] ?? 0);
                        $qty = round((float) ($partRow['qty'] ?? 0), 2);
                        $existing = $this->inventoryByGaragePart[$garageId][$partId] ?? 0.0;
                        $this->inventoryByGaragePart[$garageId][$partId] = round(max(0.0, $existing - $qty), 2);
                    }
                } catch (Throwable $exception) {
                    // Continue; integrity checks run after full seed.
                }
            }

            $this->pdo->prepare(
                'UPDATE job_cards
                 SET status = :status,
                     completed_at = CASE
                        WHEN :status IN ("COMPLETED", "CLOSED") THEN :status_at
                        ELSE completed_at
                     END,
                     closed_at = CASE
                        WHEN :status = "CLOSED" THEN :status_at
                        ELSE closed_at
                     END,
                     status_code = CASE
                        WHEN :status = "CANCELLED" THEN "INACTIVE"
                        ELSE status_code
                     END,
                     cancel_note = CASE
                        WHEN :status = "CANCELLED" THEN :cancel_note
                        ELSE cancel_note
                     END,
                     updated_by = :updated_by,
                     updated_at = :updated_at
                 WHERE id = :id'
            )->execute([
                'status' => $status,
                'status_at' => $statusAt,
                'cancel_note' => $status === 'CANCELLED' ? 'Cancelled in demo workflow' : null,
                'updated_by' => $this->actorUserId,
                'updated_at' => $statusAt,
                'id' => $jobId,
            ]);
            if ($status === 'CLOSED') {
                $this->pdo->prepare('UPDATE job_cards SET stock_posted_at = :stock_posted_at WHERE id = :id')->execute([
                    'stock_posted_at' => $statusAt,
                    'id' => $jobId,
                ]);
            }

            job_append_history($jobId, 'STATUS_CHANGE', $current, $status, 'Demo workflow transition', null);
            $current = $status;
        }
    }

    private function refreshOutsourcedWorkStatusAndLabor(int $workId): void
    {
        $workStmt = $this->pdo->prepare(
            'SELECT ow.*, COALESCE(pay.total_paid, 0) AS total_paid
             FROM outsourced_works ow
             LEFT JOIN (
                SELECT outsourced_work_id, SUM(amount) AS total_paid
                FROM outsourced_work_payments
                GROUP BY outsourced_work_id
             ) pay ON pay.outsourced_work_id = ow.id
             WHERE ow.id = :id
             LIMIT 1'
        );
        $workStmt->execute(['id' => $workId]);
        $work = $workStmt->fetch();
        if (!$work) {
            return;
        }

        $agreed = round((float) ($work['agreed_cost'] ?? 0), 2);
        $paid = round((float) ($work['total_paid'] ?? 0), 2);
        $currentStatus = strtoupper(trim((string) ($work['current_status'] ?? 'SENT')));
        if (!in_array($currentStatus, ['SENT', 'RECEIVED', 'VERIFIED', 'PAYABLE', 'PAID'], true)) {
            $currentStatus = 'SENT';
        }
        $rank = ['SENT' => 1, 'RECEIVED' => 2, 'VERIFIED' => 3, 'PAYABLE' => 4, 'PAID' => 5];
        $nextStatus = $currentStatus;
        if ($agreed > 0 && $paid + 0.009 >= $agreed) {
            $nextStatus = 'PAID';
        } elseif (($rank[$currentStatus] ?? 1) >= 4 || $paid > 0) {
            $nextStatus = 'PAYABLE';
        }

        $payableAt = !empty($work['payable_at']) ? (string) $work['payable_at'] : null;
        if ($nextStatus === 'PAYABLE' && $payableAt === null) {
            $payableAt = date('Y-m-d H:i:s');
        }
        $paidAt = $nextStatus === 'PAID' ? date('Y-m-d H:i:s') : null;

        $this->pdo->prepare(
            'UPDATE outsourced_works
             SET current_status = :current_status,
                 payable_at = :payable_at,
                 paid_at = :paid_at,
                 updated_by = :updated_by
             WHERE id = :id'
        )->execute([
            'current_status' => $nextStatus,
            'payable_at' => $payableAt,
            'paid_at' => $paidAt,
            'updated_by' => $this->actorUserId,
            'id' => $workId,
        ]);

        $laborPayableStatus = ($agreed > 0 && $paid + 0.009 >= $agreed) ? 'PAID' : 'UNPAID';
        $this->pdo->prepare(
            'UPDATE job_labor
             SET outsource_payable_status = :outsource_payable_status,
                 outsource_paid_at = :outsource_paid_at,
                 outsource_paid_by = :outsource_paid_by
             WHERE id = :id'
        )->execute([
            'outsource_payable_status' => $laborPayableStatus,
            'outsource_paid_at' => $laborPayableStatus === 'PAID' ? date('Y-m-d H:i:s') : null,
            'outsource_paid_by' => $laborPayableStatus === 'PAID' ? $this->actorUserId : null,
            'id' => (int) ($work['job_labor_id'] ?? 0),
        ]);
    }

    private function invoiceGrandTotal(int $invoiceId): float
    {
        $stmt = $this->pdo->prepare('SELECT grand_total FROM invoices WHERE id = :id');
        $stmt->execute(['id' => $invoiceId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    private function findSalaryStructure(int $userId, int $garageId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM payroll_salary_structures
             WHERE user_id = :user_id
               AND garage_id = :garage_id
               AND status_code = "ACTIVE"
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'garage_id' => $garageId,
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function applyAdvanceToUser(int $userId, int $garageId, float $requestedAmount): float
    {
        $remaining = round(max(0, $requestedAmount), 2);
        if ($remaining <= 0) {
            return 0.0;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, amount, applied_amount
             FROM payroll_advances
             WHERE user_id = :user_id
               AND company_id = :company_id
               AND garage_id = :garage_id
               AND status = "OPEN"
             ORDER BY advance_date ASC, id ASC'
        );
        $stmt->execute([
            'user_id' => $userId,
            'company_id' => $this->companyId,
            'garage_id' => $garageId,
        ]);

        $applied = 0.0;
        foreach ($stmt->fetchAll() as $row) {
            $available = round((float) ($row['amount'] ?? 0) - (float) ($row['applied_amount'] ?? 0), 2);
            if ($available <= 0) {
                continue;
            }
            $consume = min($available, $remaining);
            $this->pdo->prepare(
                'UPDATE payroll_advances
                 SET applied_amount = applied_amount + :consume,
                     status = CASE WHEN applied_amount + :consume + 0.009 >= amount THEN "CLOSED" ELSE "OPEN" END,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id'
            )->execute([
                'consume' => $consume,
                'updated_by' => $this->actorUserId,
                'id' => (int) $row['id'],
            ]);
            $applied += $consume;
            $remaining -= $consume;
            if ($remaining <= 0.001) {
                break;
            }
        }
        return round($applied, 2);
    }

    private function applyLoanDeduction(int $loanId, int $sheetId, int $garageId, string $month, float $requestedAmount): float
    {
        $loanStmt = $this->pdo->prepare('SELECT * FROM payroll_loans WHERE id = :id LIMIT 1');
        $loanStmt->execute(['id' => $loanId]);
        $loan = $loanStmt->fetch();
        if (!$loan) {
            return 0.0;
        }

        $pending = round((float) ($loan['total_amount'] ?? 0) - (float) ($loan['paid_amount'] ?? 0), 2);
        if ($pending <= 0) {
            return 0.0;
        }

        $emi = round((float) ($loan['emi_amount'] ?? 0), 2);
        $deduct = round(min($pending, $requestedAmount, $emi > 0 ? $emi : $requestedAmount), 2);
        if ($deduct <= 0) {
            return 0.0;
        }

        $paymentId = $this->insert('payroll_loan_payments', [
            'loan_id' => $loanId,
            'company_id' => $this->companyId,
            'garage_id' => $garageId,
            'salary_item_id' => null,
            'payment_date' => $month . '-28',
            'entry_type' => 'EMI',
            'amount' => $deduct,
            'reference_no' => 'EMI-' . $sheetId . '-' . $loanId,
            'notes' => 'Auto EMI deduction in salary',
            'reversed_payment_id' => null,
            'created_by' => $this->actorUserId,
        ]);
        $this->touchCreatedAt('payroll_loan_payments', $paymentId, $month . '-28 18:10:00');

        $this->pdo->prepare(
            'UPDATE payroll_loans
             SET paid_amount = paid_amount + :amount,
                 status = CASE WHEN paid_amount + :amount + 0.009 >= total_amount THEN "PAID" ELSE "ACTIVE" END,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id'
        )->execute([
            'amount' => $deduct,
            'updated_by' => $this->actorUserId,
            'id' => $loanId,
        ]);
        return $deduct;
    }

    private function salaryItemPaidAmount(int $salaryItemId): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0)
             FROM payroll_salary_payments
             WHERE salary_item_id = :salary_item_id'
        );
        $stmt->execute(['salary_item_id' => $salaryItemId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    private function createInvoiceFromClosedJob(
        int $jobId,
        int $garageId,
        string $invoiceDate,
        ?string $dueDate,
        string $notes
    ): int {
        $jobStmt = $this->pdo->prepare(
            'SELECT jc.id, jc.job_number, jc.customer_id, jc.vehicle_id, jc.closed_at,
                    cu.full_name AS customer_name, cu.phone AS customer_phone, cu.gstin AS customer_gstin,
                    cu.address_line1 AS customer_address_line1, cu.address_line2 AS customer_address_line2,
                    cu.city AS customer_city, cu.state AS customer_state, cu.pincode AS customer_pincode,
                    v.registration_no, v.brand, v.model, v.variant, v.fuel_type, v.model_year,
                    g.name AS garage_name, g.code AS garage_code, g.gstin AS garage_gstin,
                    g.address_line1 AS garage_address_line1, g.address_line2 AS garage_address_line2,
                    g.city AS garage_city, g.state AS garage_state, g.pincode AS garage_pincode,
                    c.name AS company_name, c.legal_name AS company_legal_name, c.gstin AS company_gstin,
                    c.address_line1 AS company_address_line1, c.address_line2 AS company_address_line2,
                    c.city AS company_city, c.state AS company_state, c.pincode AS company_pincode
             FROM job_cards jc
             INNER JOIN customers cu ON cu.id = jc.customer_id
             INNER JOIN vehicles v ON v.id = jc.vehicle_id
             INNER JOIN garages g ON g.id = jc.garage_id
             INNER JOIN companies c ON c.id = jc.company_id
             WHERE jc.id = :job_id
               AND jc.company_id = :company_id
               AND jc.garage_id = :garage_id
               AND jc.status = "CLOSED"
               AND jc.status_code = "ACTIVE"
             LIMIT 1'
        );
        $jobStmt->execute([
            'job_id' => $jobId,
            'company_id' => $this->companyId,
            'garage_id' => $garageId,
        ]);
        $job = $jobStmt->fetch();
        if (!$job) {
            return 0;
        }

        $existing = $this->pdo->prepare('SELECT id FROM invoices WHERE job_card_id = :job_card_id LIMIT 1');
        $existing->execute(['job_card_id' => $jobId]);
        if ($existing->fetch()) {
            return 0;
        }

        $laborStmt = $this->pdo->prepare(
            'SELECT jl.id, jl.service_id, jl.description, jl.quantity, jl.unit_price, jl.gst_rate, s.service_code
             FROM job_labor jl
             LEFT JOIN services s ON s.id = jl.service_id
             WHERE jl.job_card_id = :job_card_id
             ORDER BY jl.id ASC'
        );
        $laborStmt->execute(['job_card_id' => $jobId]);
        $laborLines = $laborStmt->fetchAll();

        $partStmt = $this->pdo->prepare(
            'SELECT jp.id, jp.part_id, jp.quantity, jp.unit_price, jp.gst_rate, p.part_name, p.hsn_code
             FROM job_parts jp
             INNER JOIN parts p ON p.id = jp.part_id
             WHERE jp.job_card_id = :job_card_id
             ORDER BY jp.id ASC'
        );
        $partStmt->execute(['job_card_id' => $jobId]);
        $partLines = $partStmt->fetchAll();

        if ($laborLines === [] && $partLines === []) {
            return 0;
        }

        $taxRegime = billing_tax_regime((string) ($job['garage_state'] ?? ''), (string) ($job['customer_state'] ?? ''));
        $invoiceLines = [];
        foreach ($laborLines as $line) {
            $invoiceLines[] = billing_calculate_line(
                'LABOR',
                trim((string) ($line['description'] ?? '')) !== '' ? (string) $line['description'] : 'Labor Line #' . (int) $line['id'],
                null,
                (int) ($line['service_id'] ?? 0) > 0 ? (int) $line['service_id'] : null,
                (string) ($line['service_code'] ?? ''),
                (float) ($line['quantity'] ?? 0),
                (float) ($line['unit_price'] ?? 0),
                (float) ($line['gst_rate'] ?? 18),
                $taxRegime
            );
        }
        foreach ($partLines as $line) {
            $invoiceLines[] = billing_calculate_line(
                'PART',
                (string) ($line['part_name'] ?? ''),
                (int) ($line['part_id'] ?? 0),
                null,
                (string) ($line['hsn_code'] ?? ''),
                (float) ($line['quantity'] ?? 0),
                (float) ($line['unit_price'] ?? 0),
                (float) ($line['gst_rate'] ?? 18),
                $taxRegime
            );
        }
        $totals = billing_calculate_totals($invoiceLines);
        if ((float) ($totals['grand_total'] ?? 0) <= 0) {
            return 0;
        }

        $numberMeta = billing_generate_invoice_number($this->pdo, $this->companyId, $garageId, $invoiceDate);
        $snapshot = [
            'company' => [
                'name' => (string) ($job['company_name'] ?? ''),
                'legal_name' => (string) ($job['company_legal_name'] ?? ''),
                'gstin' => (string) ($job['company_gstin'] ?? ''),
                'address_line1' => (string) ($job['company_address_line1'] ?? ''),
                'address_line2' => (string) ($job['company_address_line2'] ?? ''),
                'city' => (string) ($job['company_city'] ?? ''),
                'state' => (string) ($job['company_state'] ?? ''),
                'pincode' => (string) ($job['company_pincode'] ?? ''),
            ],
            'garage' => [
                'name' => (string) ($job['garage_name'] ?? ''),
                'code' => (string) ($job['garage_code'] ?? ''),
                'gstin' => (string) ($job['garage_gstin'] ?? ''),
                'address_line1' => (string) ($job['garage_address_line1'] ?? ''),
                'address_line2' => (string) ($job['garage_address_line2'] ?? ''),
                'city' => (string) ($job['garage_city'] ?? ''),
                'state' => (string) ($job['garage_state'] ?? ''),
                'pincode' => (string) ($job['garage_pincode'] ?? ''),
            ],
            'customer' => [
                'full_name' => (string) ($job['customer_name'] ?? ''),
                'phone' => (string) ($job['customer_phone'] ?? ''),
                'gstin' => (string) ($job['customer_gstin'] ?? ''),
                'address_line1' => (string) ($job['customer_address_line1'] ?? ''),
                'address_line2' => (string) ($job['customer_address_line2'] ?? ''),
                'city' => (string) ($job['customer_city'] ?? ''),
                'state' => (string) ($job['customer_state'] ?? ''),
                'pincode' => (string) ($job['customer_pincode'] ?? ''),
            ],
            'vehicle' => [
                'registration_no' => (string) ($job['registration_no'] ?? ''),
                'brand' => (string) ($job['brand'] ?? ''),
                'model' => (string) ($job['model'] ?? ''),
                'variant' => (string) ($job['variant'] ?? ''),
                'fuel_type' => (string) ($job['fuel_type'] ?? ''),
                'model_year' => $job['model_year'] !== null ? (int) $job['model_year'] : null,
            ],
            'job' => [
                'id' => (int) ($job['id'] ?? 0),
                'job_number' => (string) ($job['job_number'] ?? ''),
                'closed_at' => (string) ($job['closed_at'] ?? ''),
            ],
            'tax_regime' => $taxRegime,
            'generated_at' => date('c'),
        ];

        $invoiceId = $this->insert('invoices', [
            'company_id' => $this->companyId,
            'garage_id' => $garageId,
            'invoice_number' => (string) ($numberMeta['invoice_number'] ?? ''),
            'job_card_id' => (int) ($job['id'] ?? 0),
            'customer_id' => (int) ($job['customer_id'] ?? 0),
            'vehicle_id' => (int) ($job['vehicle_id'] ?? 0),
            'invoice_date' => $invoiceDate,
            'due_date' => $dueDate,
            'invoice_status' => 'DRAFT',
            'subtotal_service' => (float) ($totals['subtotal_service'] ?? 0),
            'subtotal_parts' => (float) ($totals['subtotal_parts'] ?? 0),
            'taxable_amount' => (float) ($totals['taxable_amount'] ?? 0),
            'tax_regime' => $taxRegime,
            'cgst_rate' => (float) ($totals['cgst_rate'] ?? 0),
            'sgst_rate' => (float) ($totals['sgst_rate'] ?? 0),
            'igst_rate' => (float) ($totals['igst_rate'] ?? 0),
            'cgst_amount' => (float) ($totals['cgst_amount'] ?? 0),
            'sgst_amount' => (float) ($totals['sgst_amount'] ?? 0),
            'igst_amount' => (float) ($totals['igst_amount'] ?? 0),
            'service_tax_amount' => (float) ($totals['service_tax_amount'] ?? 0),
            'parts_tax_amount' => (float) ($totals['parts_tax_amount'] ?? 0),
            'total_tax_amount' => (float) ($totals['total_tax_amount'] ?? 0),
            'gross_total' => (float) ($totals['gross_total'] ?? 0),
            'round_off' => (float) ($totals['round_off'] ?? 0),
            'grand_total' => (float) ($totals['grand_total'] ?? 0),
            'payment_status' => 'UNPAID',
            'payment_mode' => null,
            'notes' => $notes,
            'created_by' => $this->actorUserId,
            'financial_year_id' => $numberMeta['financial_year_id'],
            'financial_year_label' => (string) ($numberMeta['financial_year_label'] ?? ''),
            'sequence_number' => (int) ($numberMeta['sequence_number'] ?? 0),
            'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
        ]);
        $this->touchCreatedAt('invoices', $invoiceId, $invoiceDate . ' 12:20:00');

        foreach ($invoiceLines as $line) {
            $this->insert('invoice_items', [
                'invoice_id' => $invoiceId,
                'item_type' => (string) ($line['item_type'] ?? 'LABOR'),
                'description' => (string) ($line['description'] ?? ''),
                'part_id' => $line['part_id'],
                'service_id' => $line['service_id'],
                'hsn_sac_code' => $line['hsn_sac_code'],
                'quantity' => (float) ($line['quantity'] ?? 0),
                'unit_price' => (float) ($line['unit_price'] ?? 0),
                'gst_rate' => (float) ($line['gst_rate'] ?? 0),
                'cgst_rate' => (float) ($line['cgst_rate'] ?? 0),
                'sgst_rate' => (float) ($line['sgst_rate'] ?? 0),
                'igst_rate' => (float) ($line['igst_rate'] ?? 0),
                'taxable_value' => (float) ($line['taxable_value'] ?? 0),
                'cgst_amount' => (float) ($line['cgst_amount'] ?? 0),
                'sgst_amount' => (float) ($line['sgst_amount'] ?? 0),
                'igst_amount' => (float) ($line['igst_amount'] ?? 0),
                'tax_amount' => (float) ($line['tax_amount'] ?? 0),
                'total_value' => (float) ($line['total_value'] ?? 0),
            ]);
        }

        billing_record_status_history(
            $this->pdo,
            $invoiceId,
            null,
            'DRAFT',
            'CREATE_DRAFT',
            'Draft invoice created from closed job card.',
            $this->actorUserId,
            ['job_card_id' => $jobId]
        );

        $this->pdo->prepare(
            'UPDATE invoices
             SET invoice_status = "FINALIZED",
                 finalized_at = :finalized_at,
                 finalized_by = :finalized_by
             WHERE id = :id'
        )->execute([
            'finalized_at' => $invoiceDate . ' 12:40:00',
            'finalized_by' => $this->actorUserId,
            'id' => $invoiceId,
        ]);
        billing_record_status_history(
            $this->pdo,
            $invoiceId,
            'DRAFT',
            'FINALIZED',
            'FINALIZE',
            'Invoice finalized by demo seeder.',
            $this->actorUserId,
            null
        );

        return $invoiceId;
    }

    private function recordInvoicePayment(
        int $invoiceId,
        int $garageId,
        float $amount,
        string $paidOn,
        string $paymentMode,
        string $notes
    ): int {
        $invoiceStmt = $this->pdo->prepare(
            'SELECT id, grand_total, invoice_status
             FROM invoices
             WHERE id = :id
               AND company_id = :company_id
               AND garage_id = :garage_id
             LIMIT 1'
        );
        $invoiceStmt->execute([
            'id' => $invoiceId,
            'company_id' => $this->companyId,
            'garage_id' => $garageId,
        ]);
        $invoice = $invoiceStmt->fetch();
        if (!$invoice || (string) ($invoice['invoice_status'] ?? '') !== 'FINALIZED') {
            return 0;
        }

        $paidStmt = $this->pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = :invoice_id');
        $paidStmt->execute(['invoice_id' => $invoiceId]);
        $alreadyPaid = round((float) $paidStmt->fetchColumn(), 2);
        $grandTotal = round((float) ($invoice['grand_total'] ?? 0), 2);
        $outstanding = round(max(0, $grandTotal - $alreadyPaid), 2);
        if ($outstanding <= 0.009) {
            return 0;
        }
        $amount = round(min($amount, $outstanding), 2);
        if ($amount <= 0) {
            return 0;
        }

        $paymentId = $this->insert('payments', [
            'invoice_id' => $invoiceId,
            'entry_type' => 'PAYMENT',
            'amount' => $amount,
            'paid_on' => $paidOn,
            'payment_mode' => billing_normalize_payment_mode($paymentMode),
            'reference_no' => 'INVPAY-' . $invoiceId . '-' . date('His', strtotime($paidOn . ' 10:00:00')),
            'notes' => $notes,
            'reversed_payment_id' => null,
            'is_reversed' => 0,
            'reversed_at' => null,
            'reversed_by' => null,
            'reverse_reason' => null,
            'received_by' => $this->actorUserId,
        ]);
        $this->touchCreatedAt('payments', $paymentId, $paidOn . ' 18:05:00');

        $paidStmt->execute(['invoice_id' => $invoiceId]);
        $newPaid = round((float) $paidStmt->fetchColumn(), 2);
        $status = 'PARTIAL';
        if ($newPaid <= 0.009) {
            $status = 'UNPAID';
        } elseif ($newPaid + 0.009 >= $grandTotal) {
            $status = 'PAID';
        }
        $summaryMode = billing_payment_mode_summary($this->pdo, $invoiceId);

        $this->pdo->prepare(
            'UPDATE invoices
             SET payment_status = :payment_status,
                 payment_mode = :payment_mode
             WHERE id = :id'
        )->execute([
            'payment_status' => $status,
            'payment_mode' => $summaryMode,
            'id' => $invoiceId,
        ]);
        billing_record_payment_history(
            $this->pdo,
            $invoiceId,
            $paymentId,
            'PAYMENT_RECORDED',
            $notes,
            $this->actorUserId,
            ['amount' => $amount, 'mode' => $paymentMode]
        );
        $this->paidInvoiceAmountById[$invoiceId] = $newPaid;
        return $paymentId;
    }

    private function reverseInvoicePayment(int $paymentId, int $garageId, string $reason): int
    {
        $paymentStmt = $this->pdo->prepare(
            'SELECT p.*, i.grand_total, i.id AS invoice_id
             FROM payments p
             INNER JOIN invoices i ON i.id = p.invoice_id
             WHERE p.id = :id
               AND i.company_id = :company_id
               AND i.garage_id = :garage_id
             LIMIT 1'
        );
        $paymentStmt->execute([
            'id' => $paymentId,
            'company_id' => $this->companyId,
            'garage_id' => $garageId,
        ]);
        $payment = $paymentStmt->fetch();
        if (!$payment || (string) ($payment['entry_type'] ?? '') !== 'PAYMENT') {
            return 0;
        }

        $exists = $this->pdo->prepare('SELECT id FROM payments WHERE reversed_payment_id = :reversed_payment_id LIMIT 1');
        $exists->execute(['reversed_payment_id' => $paymentId]);
        if ($exists->fetch()) {
            return 0;
        }

        $amount = round((float) ($payment['amount'] ?? 0), 2);
        if ($amount <= 0) {
            return 0;
        }

        $reversalDate = $this->randomDate($this->startDate, $this->endDate);
        $reversalId = $this->insert('payments', [
            'invoice_id' => (int) ($payment['invoice_id'] ?? 0),
            'entry_type' => 'REVERSAL',
            'amount' => -$amount,
            'paid_on' => $reversalDate,
            'payment_mode' => 'BANK_TRANSFER',
            'reference_no' => 'REV-' . $paymentId,
            'notes' => $reason,
            'reversed_payment_id' => $paymentId,
            'is_reversed' => 0,
            'reversed_at' => null,
            'reversed_by' => null,
            'reverse_reason' => $reason,
            'received_by' => $this->actorUserId,
        ]);
        $this->touchCreatedAt('payments', $reversalId, $reversalDate . ' 19:10:00');

        $this->pdo->prepare(
            'UPDATE payments
             SET is_reversed = 1,
                 reversed_at = :reversed_at,
                 reversed_by = :reversed_by,
                 reverse_reason = :reverse_reason
             WHERE id = :id'
        )->execute([
            'reversed_at' => $reversalDate . ' 19:10:00',
            'reversed_by' => $this->actorUserId,
            'reverse_reason' => $reason,
            'id' => $paymentId,
        ]);

        $invoiceId = (int) ($payment['invoice_id'] ?? 0);
        $sumStmt = $this->pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = :invoice_id');
        $sumStmt->execute(['invoice_id' => $invoiceId]);
        $netPaid = round((float) $sumStmt->fetchColumn(), 2);
        $grandTotal = round((float) ($payment['grand_total'] ?? 0), 2);
        $status = 'PARTIAL';
        if ($netPaid <= 0.009) {
            $status = 'UNPAID';
        } elseif ($netPaid + 0.009 >= $grandTotal) {
            $status = 'PAID';
        }
        $mode = billing_payment_mode_summary($this->pdo, $invoiceId);
        $this->pdo->prepare(
            'UPDATE invoices
             SET payment_status = :payment_status,
                 payment_mode = :payment_mode
             WHERE id = :id'
        )->execute([
            'payment_status' => $status,
            'payment_mode' => $mode,
            'id' => $invoiceId,
        ]);

        billing_record_payment_history(
            $this->pdo,
            $invoiceId,
            $reversalId,
            'PAYMENT_REVERSED',
            $reason,
            $this->actorUserId,
            ['reversed_payment_id' => $paymentId, 'amount' => -$amount]
        );
        $this->paidInvoiceAmountById[$invoiceId] = $netPaid;
        return $reversalId;
    }

    private function cancelInvoice(int $invoiceId, int $garageId, string $cancelReason): bool
    {
        $this->pdo->beginTransaction();
        try {
            $report = reversal_invoice_cancel_dependency_report($this->pdo, $invoiceId, $this->companyId, $garageId);
            $invoice = $report['invoice'] ?? null;
            if (!is_array($invoice)) {
                $this->pdo->rollBack();
                return false;
            }
            if (!(bool) ($report['can_cancel'] ?? false)) {
                $this->pdo->rollBack();
                return false;
            }

            $currentStatus = strtoupper((string) ($invoice['invoice_status'] ?? 'FINALIZED'));
            $this->pdo->prepare(
                'UPDATE invoices
                 SET invoice_status = "CANCELLED",
                     payment_status = "CANCELLED",
                     cancelled_at = :cancelled_at,
                     cancelled_by = :cancelled_by,
                     cancel_reason = :cancel_reason
                 WHERE id = :id'
            )->execute([
                'cancelled_at' => date('Y-m-d H:i:s'),
                'cancelled_by' => $this->actorUserId,
                'cancel_reason' => $cancelReason,
                'id' => $invoiceId,
            ]);
            billing_record_status_history(
                $this->pdo,
                $invoiceId,
                $currentStatus,
                'CANCELLED',
                'CANCEL',
                $cancelReason,
                $this->actorUserId,
                null
            );
            $this->pdo->commit();
            return true;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return false;
        }
    }
}

try {
    $seeder = new IndianDemoDataSeeder();
    $seeder->run();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Seeding failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
