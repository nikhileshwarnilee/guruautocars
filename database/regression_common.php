<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/finance.php';
require_once __DIR__ . '/../includes/ledger_posting_service.php';

final class RegressionHarness
{
    public PDO $pdo;
    public int $companyId;
    public int $garageId;
    public int $roleId;
    public int $adminUserId;
    public int $financialYearId;
    public string $fyLabel;
    public string $datasetTag;
    public string $baseDate;

    /** @var int[] */
    public array $staffUserIds;

    /** @var array<string,string[]> */
    private array $columnCache = [];

    public function __construct(array $config = [])
    {
        $this->pdo = db();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->companyId = (int) ($config['company_id'] ?? 910001);
        $this->garageId = (int) ($config['garage_id'] ?? 910001);
        $this->roleId = (int) ($config['role_id'] ?? 910001);
        $this->adminUserId = (int) ($config['admin_user_id'] ?? 910001);
        $this->financialYearId = (int) ($config['financial_year_id'] ?? 910001);
        $this->fyLabel = (string) ($config['fy_label'] ?? '2025-26');
        $this->datasetTag = (string) ($config['dataset_tag'] ?? 'REG-DET-20260226');
        $this->baseDate = (string) ($config['base_date'] ?? '2026-01-01');
        $this->staffUserIds = array_map('intval', (array) ($config['staff_user_ids'] ?? [910011, 910012, 910013, 910014, 910015]));
    }

    public function scopeLabel(): string
    {
        return $this->datasetTag . ' [company=' . $this->companyId . ', garage=' . $this->garageId . ']';
    }

    public function datePlus(int $days): string
    {
        $ts = strtotime($this->baseDate . ' +' . $days . ' days');
        return $ts === false ? $this->baseDate : date('Y-m-d', $ts);
    }

    public function dateTimePlus(int $days, string $time = '10:00:00'): string
    {
        return $this->datePlus($days) . ' ' . $time;
    }

    public function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    public function logLine(string $line): void
    {
        echo $line . PHP_EOL;
    }

    public function tableExists(string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }
        $stmt = $this->pdo->prepare('SHOW TABLES LIKE :t');
        $stmt->execute(['t' => $table]);
        return (bool) $stmt->fetchColumn();
    }

    public function columns(string $table): array
    {
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }
        if (!$this->tableExists($table)) {
            return $this->columnCache[$table] = [];
        }
        $stmt = $this->pdo->query('SHOW COLUMNS FROM `' . $table . '`');
        $cols = [];
        foreach ($stmt->fetchAll() as $row) {
            $field = (string) ($row['Field'] ?? '');
            if ($field !== '') {
                $cols[] = $field;
            }
        }
        return $this->columnCache[$table] = $cols;
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columns($table), true);
    }

    /**
     * Build SQL filter for operational payment rows while treating reversals as audit-only.
     * Reversed payment rows are excluded from calculations via NOT EXISTS on reversal link.
     */
    private function unreversedPaymentFilterSql(string $table, string $alias): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return '1 = 0';
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $alias)) {
            $alias = 'p';
        }

        $cols = $this->columns($table);
        $hasEntryType = in_array('entry_type', $cols, true);
        $hasReversedPaymentId = in_array('reversed_payment_id', $cols, true);
        $hasIsReversed = in_array('is_reversed', $cols, true);
        $hasDeletedAt = in_array('deleted_at', $cols, true);

        $conditions = [];
        if ($hasDeletedAt) {
            $conditions[] = $alias . '.deleted_at IS NULL';
        }

        if ($hasEntryType) {
            $conditions[] = 'COALESCE(' . $alias . '.entry_type, "PAYMENT") = "PAYMENT"';
        } else {
            $conditions[] = $alias . '.amount > 0';
        }

        if ($table === 'payments' && $hasIsReversed) {
            $conditions[] = 'COALESCE(' . $alias . '.is_reversed, 0) = 0';
        }

        if ($hasReversedPaymentId) {
            $reverseAlias = $alias . '_rev';
            $reverseConditions = [
                $reverseAlias . '.reversed_payment_id = ' . $alias . '.id',
            ];
            if ($hasEntryType) {
                $reverseConditions[] = 'COALESCE(' . $reverseAlias . '.entry_type, "REVERSAL") = "REVERSAL"';
            }
            if ($hasDeletedAt) {
                $reverseConditions[] = $reverseAlias . '.deleted_at IS NULL';
            }
            $conditions[] = 'NOT EXISTS (
                SELECT 1
                FROM ' . $table . ' ' . $reverseAlias . '
                WHERE ' . implode(' AND ', $reverseConditions) . '
            )';
        }

        return $conditions === [] ? '1 = 1' : implode(' AND ', $conditions);
    }

    public function effectivePaymentTotal(string $table, string $scopeColumn, int $scopeId): float
    {
        if ($scopeId <= 0 || !$this->tableExists($table)) {
            return 0.0;
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $scopeColumn)) {
            return 0.0;
        }
        if (!$this->hasColumn($table, $scopeColumn)) {
            return 0.0;
        }

        $filterSql = $this->unreversedPaymentFilterSql($table, 'p');
        $sql =
            'SELECT COALESCE(SUM(ABS(p.amount)),0)
             FROM ' . $table . ' p
             WHERE p.' . $scopeColumn . ' = :scope_id
               AND ' . $filterSql;

        return round((float) ($this->qv($sql, ['scope_id' => $scopeId]) ?? 0), 2);
    }

    public function qv(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public function qa(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function qr(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    public function exec(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function insert(string $table, array $payload, bool $replace = false): void
    {
        $valid = [];
        $cols = $this->columns($table);
        foreach ($payload as $column => $value) {
            if (in_array((string) $column, $cols, true)) {
                $valid[(string) $column] = $value;
            }
        }
        if ($valid === []) {
            throw new RuntimeException('No valid insertable columns for ' . $table);
        }

        $keys = array_keys($valid);
        $sql =
            ($replace ? 'REPLACE' : 'INSERT') . ' INTO `' . $table . '` (`'
            . implode('`,`', $keys) . '`) VALUES ('
            . implode(', ', array_map(static fn (string $k): string => ':' . $k, $keys))
            . ')';
        $this->exec($sql, $valid);
    }

    public function upsert(string $table, array $payload, array $updateColumns): void
    {
        $valid = [];
        $cols = $this->columns($table);
        foreach ($payload as $column => $value) {
            if (in_array((string) $column, $cols, true)) {
                $valid[(string) $column] = $value;
            }
        }
        if ($valid === []) {
            throw new RuntimeException('No valid upsert columns for ' . $table);
        }

        $keys = array_keys($valid);
        $updates = [];
        foreach ($updateColumns as $column) {
            $column = (string) $column;
            if (in_array($column, $keys, true)) {
                $updates[] = '`' . $column . '` = VALUES(`' . $column . '`)';
            }
        }
        if ($updates === []) {
            $updates[] = '`' . $keys[0] . '` = VALUES(`' . $keys[0] . '`)';
        }

        $sql =
            'INSERT INTO `' . $table . '` (`' . implode('`,`', $keys) . '`) VALUES ('
            . implode(', ', array_map(static fn (string $k): string => ':' . $k, $keys))
            . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
        $this->exec($sql, $valid);
    }

    public function updateById(string $table, int|string $id, array $payload, string $idColumn = 'id'): int
    {
        $cols = $this->columns($table);
        $set = [];
        $params = ['_id' => $id];
        foreach ($payload as $column => $value) {
            $column = (string) $column;
            if ($column === $idColumn || !in_array($column, $cols, true)) {
                continue;
            }
            $param = 'p_' . $column;
            $set[] = '`' . $column . '` = :' . $param;
            $params[$param] = $value;
        }
        if ($set === []) {
            return 0;
        }
        return $this->exec(
            'UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE `' . $idColumn . '` = :_id',
            $params
        );
    }

    public function writeJson(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    }

    public function ensureLedgerBootstrap(): void
    {
        if (!ledger_bootstrap_ready()) {
            throw new RuntimeException('Ledger bootstrap failed.');
        }
        ledger_ensure_default_coa($this->pdo, $this->companyId);
    }

    public function ensureCoreMasters(): void
    {
        $this->ensureLedgerBootstrap();

        $this->upsert('companies', [
            'id' => $this->companyId,
            'name' => 'Regression Staging Co',
            'legal_name' => 'Regression Staging Co Pvt Ltd',
            'gstin' => '27AACCR9001R1Z1',
            'pan' => 'AACCR9001R',
            'phone' => '+91-2000000001',
            'email' => 'regression+' . $this->companyId . '@example.test',
            'address_line1' => 'Deterministic Seed Address 1',
            'address_line2' => 'Regression Lane',
            'city' => 'Pune',
            'state' => 'Maharashtra',
            'pincode' => '411001',
            'status' => 'active',
            'status_code' => 'ACTIVE',
        ], ['name','legal_name','gstin','pan','phone','email','address_line1','address_line2','city','state','pincode','status','status_code']);

        $this->upsert('garages', [
            'id' => $this->garageId,
            'company_id' => $this->companyId,
            'name' => 'Regression Garage',
            'code' => 'REG',
            'phone' => '+91-2000000100',
            'email' => 'garage+' . $this->garageId . '@example.test',
            'gstin' => '27AACCR9001R1Z1',
            'address_line1' => 'Garage Plot 1',
            'address_line2' => 'Regression Industrial Area',
            'city' => 'Pune',
            'state' => 'Maharashtra',
            'pincode' => '411001',
            'status' => 'active',
            'status_code' => 'ACTIVE',
        ], ['company_id','name','code','phone','email','gstin','address_line1','address_line2','city','state','pincode','status','status_code']);

        $this->upsert('roles', [
            'id' => $this->roleId,
            'role_key' => 'regression_admin_' . $this->companyId,
            'role_name' => 'Regression Admin',
            'description' => 'Deterministic regression automation role',
            'is_system' => 0,
            'status_code' => 'ACTIVE',
        ], ['role_key','role_name','description','is_system','status_code']);

        $allUsers = array_merge([$this->adminUserId], $this->staffUserIds);
        foreach ($allUsers as $idx => $userId) {
            $name = $idx === 0 ? 'Regression Admin' : ('Regression Staff ' . $idx);
            $username = $idx === 0 ? ('reg_admin_' . $this->companyId) : ('reg_staff_' . $this->companyId . '_' . $idx);
            $email = $username . '@example.test';
            $this->upsert('users', [
                'id' => $userId,
                'company_id' => $this->companyId,
                'role_id' => $this->roleId,
                'primary_garage_id' => $this->garageId,
                'name' => $name,
                'email' => $email,
                'username' => $username,
                'password_hash' => password_hash('regression123', PASSWORD_BCRYPT),
                'phone' => '+91-20000' . str_pad((string) ($idx + 1), 5, '0', STR_PAD_LEFT),
                'is_active' => 1,
                'status_code' => 'ACTIVE',
            ], ['company_id','role_id','primary_garage_id','name','email','username','password_hash','phone','is_active','status_code']);

            if ($this->tableExists('user_garages')) {
                $this->upsert('user_garages', ['user_id' => $userId, 'garage_id' => $this->garageId], ['garage_id']);
            }
        }

        if ($this->tableExists('financial_years')) {
            $this->upsert('financial_years', [
                'id' => $this->financialYearId,
                'company_id' => $this->companyId,
                'fy_label' => $this->fyLabel,
                'start_date' => '2025-04-01',
                'end_date' => '2026-03-31',
                'is_default' => 1,
                'status_code' => 'ACTIVE',
                'created_by' => $this->adminUserId,
            ], ['company_id','fy_label','start_date','end_date','is_default','status_code','created_by']);
        }

        if ($this->tableExists('part_categories')) {
            $this->upsert('part_categories', [
                'id' => 910050,
                'company_id' => $this->companyId,
                'category_code' => 'REGPART',
                'category_name' => 'Regression Parts',
                'description' => 'Deterministic regression seed',
                'status_code' => 'ACTIVE',
            ], ['company_id','category_code','category_name','description','status_code']);
        }

        if ($this->tableExists('service_categories')) {
            $this->upsert('service_categories', [
                'id' => 910060,
                'company_id' => $this->companyId,
                'category_code' => 'REGSRV',
                'category_name' => 'Regression Services',
                'description' => 'Deterministic regression seed',
                'status_code' => 'ACTIVE',
                'created_by' => $this->adminUserId,
            ], ['company_id','category_code','category_name','description','status_code','created_by']);
        }

        if ($this->tableExists('services')) {
            for ($i = 1; $i <= 3; $i++) {
                $this->upsert('services', [
                    'id' => 910070 + $i,
                    'company_id' => $this->companyId,
                    'category_id' => 910060,
                    'service_code' => 'REG-SRV-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                    'service_name' => 'Regression Service ' . $i,
                    'description' => 'Deterministic labor service ' . $i,
                    'default_hours' => (float) $i,
                    'default_rate' => 600.0 + ($i * 150.0),
                    'gst_rate' => 18.0,
                    'status_code' => 'ACTIVE',
                    'created_by' => $this->adminUserId,
                ], ['company_id','category_id','service_code','service_name','description','default_hours','default_rate','gst_rate','status_code','created_by']);
            }
        }
    }

    public function purgeScopeData(bool $includeMasters = true): void
    {
        $pdo = $this->pdo;
        $ownsTx = !$pdo->inTransaction();
        if ($ownsTx) {
            $pdo->beginTransaction();
        }

        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

            $deleteJoin = function (string $child, string $alias, string $joinSql, string $whereSql, array $params): void {
                $sql = 'DELETE ' . $alias . ' FROM `' . $child . '` ' . $alias . ' ' . $joinSql . ' WHERE ' . $whereSql;
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
            };

            if ($this->tableExists('ledger_entries') && $this->tableExists('ledger_journals')) {
                $deleteJoin('ledger_entries', 'le', 'INNER JOIN ledger_journals lj ON lj.id = le.journal_id', 'lj.company_id = :company_id', ['company_id' => $this->companyId]);
            }
            if ($this->tableExists('ledger_journals')) {
                $this->exec('DELETE FROM ledger_journals WHERE company_id = :company_id', ['company_id' => $this->companyId]);
            }
            if ($this->tableExists('chart_of_accounts')) {
                $this->exec('DELETE FROM chart_of_accounts WHERE company_id = :company_id', ['company_id' => $this->companyId]);
            }
            if ($this->tableExists('audit_logs') && $this->hasColumn('audit_logs', 'company_id')) {
                $this->exec('DELETE FROM audit_logs WHERE company_id = :company_id', ['company_id' => $this->companyId]);
            }

            if ($this->tableExists('invoice_items') && $this->tableExists('invoices')) {
                $deleteJoin('invoice_items', 'ii', 'INNER JOIN invoices i ON i.id = ii.invoice_id', 'i.company_id = :company_id', ['company_id' => $this->companyId]);
            }
            if ($this->tableExists('payments') && $this->tableExists('invoices')) {
                $deleteJoin('payments', 'p', 'INNER JOIN invoices i ON i.id = p.invoice_id', 'i.company_id = :company_id', ['company_id' => $this->companyId]);
            }
            if ($this->tableExists('invoice_status_history') && $this->tableExists('invoices')) {
                $deleteJoin('invoice_status_history', 'ish', 'INNER JOIN invoices i ON i.id = ish.invoice_id', 'i.company_id = :company_id', ['company_id' => $this->companyId]);
            }
            if ($this->tableExists('invoice_payment_history') && $this->tableExists('invoices')) {
                $deleteJoin('invoice_payment_history', 'iph', 'INNER JOIN invoices i ON i.id = iph.invoice_id', 'i.company_id = :company_id', ['company_id' => $this->companyId]);
            }
            if ($this->tableExists('purchase_items') && $this->tableExists('purchases')) {
                $deleteJoin('purchase_items', 'pi', 'INNER JOIN purchases p ON p.id = pi.purchase_id', 'p.company_id = :company_id', ['company_id' => $this->companyId]);
            }
            if ($this->tableExists('job_parts') && $this->tableExists('job_cards')) {
                $deleteJoin('job_parts', 'jp', 'INNER JOIN job_cards j ON j.id = jp.job_card_id', 'j.company_id = :company_id', ['company_id' => $this->companyId]);
            }
            if ($this->tableExists('job_labor') && $this->tableExists('job_cards')) {
                $deleteJoin('job_labor', 'jl', 'INNER JOIN job_cards j ON j.id = jl.job_card_id', 'j.company_id = :company_id', ['company_id' => $this->companyId]);
            }
            if ($this->tableExists('job_assignments') && $this->tableExists('job_cards')) {
                $deleteJoin('job_assignments', 'ja', 'INNER JOIN job_cards j ON j.id = ja.job_card_id', 'j.company_id = :company_id', ['company_id' => $this->companyId]);
            }
            if ($this->tableExists('return_items') && $this->tableExists('returns_rma')) {
                $deleteJoin('return_items', 'ri', 'INNER JOIN returns_rma r ON r.id = ri.return_id', 'r.company_id = :company_id', ['company_id' => $this->companyId]);
            }
            if ($this->tableExists('return_settlements') && $this->tableExists('returns_rma') && $this->hasColumn('return_settlements', 'return_id')) {
                $deleteJoin('return_settlements', 'rs', 'INNER JOIN returns_rma r ON r.id = rs.return_id', 'r.company_id = :company_id', ['company_id' => $this->companyId]);
            }
            if ($this->tableExists('payroll_salary_items') && $this->tableExists('payroll_salary_sheets')) {
                $deleteJoin('payroll_salary_items', 'psi', 'INNER JOIN payroll_salary_sheets pss ON pss.id = psi.sheet_id', 'pss.company_id = :company_id', ['company_id' => $this->companyId]);
            }

            $companyTables = [
                'advance_adjustments',
                'job_advances',
                'invoices',
                'purchase_payments',
                'purchases',
                'returns_rma',
                'outsourced_work_payments',
                'outsourced_works',
                'expenses',
                'expense_categories',
                'payroll_salary_payments',
                'payroll_salary_sheets',
                'inventory_movements',
                'job_cards',
                'vehicle_history',
                'customer_history',
                'vehicles',
                'customers',
                'parts',
                'vendors',
                'services',
                'service_categories',
                'part_categories',
                'financial_years',
            ];
            foreach ($companyTables as $table) {
                if ($this->tableExists($table) && $this->hasColumn($table, 'company_id')) {
                    $this->exec('DELETE FROM `' . $table . '` WHERE company_id = :company_id', ['company_id' => $this->companyId]);
                }
            }
            if ($this->tableExists('garage_inventory')) {
                $this->exec('DELETE FROM garage_inventory WHERE garage_id = :garage_id', ['garage_id' => $this->garageId]);
            }

            if ($includeMasters) {
                if ($this->tableExists('user_garages')) {
                    $userIds = array_merge([$this->adminUserId], $this->staffUserIds);
                    $ph = implode(',', array_fill(0, count($userIds), '?'));
                    $stmt = $pdo->prepare('DELETE FROM user_garages WHERE garage_id = ? OR user_id IN (' . $ph . ')');
                    $stmt->execute(array_merge([$this->garageId], $userIds));
                }
                if ($this->tableExists('users') && $this->hasColumn('users', 'company_id')) {
                    $this->exec('DELETE FROM users WHERE company_id = :company_id', ['company_id' => $this->companyId]);
                }
                if ($this->tableExists('roles')) {
                    $this->exec('DELETE FROM roles WHERE id = :id', ['id' => $this->roleId]);
                }
                if ($this->tableExists('garages')) {
                    $this->exec('DELETE FROM garages WHERE id = :id OR company_id = :company_id', ['id' => $this->garageId, 'company_id' => $this->companyId]);
                }
                if ($this->tableExists('companies')) {
                    $this->exec('DELETE FROM companies WHERE id = :id', ['id' => $this->companyId]);
                }
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            if ($ownsTx) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            } catch (Throwable) {
            }
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function purgeTestArtifacts(): void
    {
        $prefix = $this->datasetTag . ' TEST%';
        $targets = [
            ['vendors', 'vendor_name'],
            ['parts', 'part_name'],
            ['customers', 'full_name'],
            ['vehicles', 'notes'],
            ['job_cards', 'complaint'],
            ['purchases', 'notes'],
            ['invoices', 'notes'],
            ['returns_rma', 'notes'],
            ['expenses', 'notes'],
            ['outsourced_works', 'notes'],
        ];
        foreach ($targets as [$table, $column]) {
            if (!$this->tableExists($table) || !$this->hasColumn($table, 'company_id') || !$this->hasColumn($table, $column)) {
                continue;
            }
            $this->exec(
                'DELETE FROM `' . $table . '` WHERE company_id = :company_id AND `' . $column . '` LIKE :needle',
                ['company_id' => $this->companyId, 'needle' => $prefix]
            );
        }
    }

    public function upsertGarageInventoryDelta(int $partId, float $deltaQty): void
    {
        $row = $this->qr(
            'SELECT quantity FROM garage_inventory WHERE garage_id = :garage_id AND part_id = :part_id LIMIT 1',
            ['garage_id' => $this->garageId, 'part_id' => $partId]
        );
        $current = round((float) ($row['quantity'] ?? 0), 2);
        $next = round($current + $deltaQty, 2);
        if ($row !== []) {
            $this->exec(
                'UPDATE garage_inventory SET quantity = :q WHERE garage_id = :garage_id AND part_id = :part_id',
                ['q' => $next, 'garage_id' => $this->garageId, 'part_id' => $partId]
            );
            return;
        }
        $this->insert('garage_inventory', ['garage_id' => $this->garageId, 'part_id' => $partId, 'quantity' => $next]);
    }

    public function recordInventoryMovement(array $row): int
    {
        $partId = (int) ($row['part_id'] ?? 0);
        $movementType = strtoupper(trim((string) ($row['movement_type'] ?? 'ADJUST')));
        $qty = round((float) ($row['quantity'] ?? 0), 2);
        if ($partId <= 0 || abs($qty) <= 0.009) {
            throw new RuntimeException('Invalid inventory movement payload.');
        }

        $absQty = abs($qty);
        $payload = [
            'company_id' => $this->companyId,
            'garage_id' => $this->garageId,
            'part_id' => $partId,
            'movement_type' => $movementType,
            'quantity' => $absQty,
            'reference_type' => (string) ($row['reference_type'] ?? 'ADJUSTMENT'),
            'reference_id' => isset($row['reference_id']) ? (int) $row['reference_id'] : null,
            'movement_uid' => (string) ($row['movement_uid'] ?? ''),
            'notes' => (string) ($row['notes'] ?? null),
            'created_by' => (int) ($row['created_by'] ?? $this->adminUserId),
        ];
        $this->insert('inventory_movements', $payload);
        $id = (int) $this->pdo->lastInsertId();

        $delta = match ($movementType) {
            'IN' => $absQty,
            'OUT' => -$absQty,
            default => $qty,
        };
        $this->upsertGarageInventoryDelta($partId, $delta);

        return $id;
    }

    public function softDeleteInventoryMovementByUid(string $movementUid, string $reason = 'Regression reverse'): void
    {
        if ($movementUid === '' || !$this->tableExists('inventory_movements')) {
            return;
        }
        $row = $this->qr(
            'SELECT * FROM inventory_movements WHERE company_id = :company_id AND movement_uid = :movement_uid LIMIT 1',
            ['company_id' => $this->companyId, 'movement_uid' => $movementUid]
        );
        if ($row === [] || !empty($row['deleted_at'])) {
            return;
        }
        $qty = round((float) ($row['quantity'] ?? 0), 2);
        $movementType = strtoupper((string) ($row['movement_type'] ?? 'ADJUST'));
        $undo = match ($movementType) {
            'IN' => -abs($qty),
            'OUT' => abs($qty),
            default => -$qty,
        };
        $this->upsertGarageInventoryDelta((int) ($row['part_id'] ?? 0), $undo);
        $this->exec(
            'UPDATE inventory_movements SET deleted_at = NOW(), deleted_by = :uid, deletion_reason = :reason WHERE id = :id',
            ['uid' => $this->adminUserId, 'reason' => $reason, 'id' => (int) ($row['id'] ?? 0)]
        );
    }

    public function recalcInvoicePaymentStatus(int $invoiceId): void
    {
        $invoice = $this->qr('SELECT id, grand_total, invoice_status FROM invoices WHERE id = :id AND company_id = :company_id LIMIT 1', ['id' => $invoiceId, 'company_id' => $this->companyId]);
        if ($invoice === []) {
            return;
        }
        if (strtoupper((string) ($invoice['invoice_status'] ?? '')) === 'CANCELLED') {
            $this->updateById('invoices', $invoiceId, ['payment_status' => 'CANCELLED']);
            return;
        }
        $paid = $this->effectivePaymentTotal('payments', 'invoice_id', $invoiceId);
        $adv = $this->tableExists('advance_adjustments')
            ? (float) ($this->qv('SELECT COALESCE(SUM(adjusted_amount),0) FROM advance_adjustments WHERE company_id = :company_id AND invoice_id = :invoice_id', ['company_id' => $this->companyId, 'invoice_id' => $invoiceId]) ?? 0)
            : 0.0;
        $grand = round((float) ($invoice['grand_total'] ?? 0), 2);
        $applied = round($paid + $adv, 2);
        $status = 'UNPAID';
        if ($applied + 0.009 >= $grand && $grand > 0) {
            $status = 'PAID';
        } elseif ($applied > 0.009) {
            $status = 'PARTIAL';
        }
        $this->updateById('invoices', $invoiceId, ['payment_status' => $status]);
    }

    public function recalcPurchasePaymentStatus(int $purchaseId): void
    {
        $purchase = $this->qr('SELECT id, grand_total FROM purchases WHERE id = :id AND company_id = :company_id LIMIT 1', ['id' => $purchaseId, 'company_id' => $this->companyId]);
        if ($purchase === []) {
            return;
        }
        $net = $this->effectivePaymentTotal('purchase_payments', 'purchase_id', $purchaseId);
        $grand = round((float) ($purchase['grand_total'] ?? 0), 2);
        $status = 'UNPAID';
        if ($net + 0.009 >= $grand && $grand > 0) {
            $status = 'PAID';
        } elseif ($net > 0.009) {
            $status = 'PARTIAL';
        }
        $this->updateById('purchases', $purchaseId, ['payment_status' => $status]);
    }

    public function refreshJobAdvanceBalances(): void
    {
        if (!$this->tableExists('job_advances')) {
            return;
        }
        $rows = $this->qa(
            'SELECT a.id, a.advance_amount, COALESCE((SELECT SUM(ad.adjusted_amount) FROM advance_adjustments ad WHERE ad.advance_id = a.id),0) AS adjusted_total
             FROM job_advances a WHERE a.company_id = :company_id',
            ['company_id' => $this->companyId]
        );
        foreach ($rows as $row) {
            $advanceAmount = round((float) ($row['advance_amount'] ?? 0), 2);
            $adjusted = round((float) ($row['adjusted_total'] ?? 0), 2);
            $this->updateById('job_advances', (int) ($row['id'] ?? 0), [
                'adjusted_amount' => $adjusted,
                'balance_amount' => round(max($advanceAmount - $adjusted, 0.0), 2),
            ]);
        }
    }

    public function reverseLedgerReference(string $origType, int $origId, string $revType, int $revId, ?string $date = null, ?string $narration = null): ?int
    {
        return ledger_reverse_reference(
            $this->pdo,
            $this->companyId,
            $origType,
            $origId,
            $revType,
            $revId,
            $date,
            $narration,
            $this->adminUserId,
            true
        );
    }

    public function reverseLedgerReferences(array $pairs, string $revType, int $revId, ?string $date = null, ?string $narration = null): array
    {
        return ledger_reverse_all_reference_types(
            $this->pdo,
            $this->companyId,
            $pairs,
            $revType,
            $revId,
            $date,
            $narration,
            $this->adminUserId
        );
    }

    public function postVendorReturnApprovalJournal(array $returnRow): ?int
    {
        $returnId = (int) ($returnRow['id'] ?? 0);
        $vendorId = (int) ($returnRow['vendor_id'] ?? 0);
        $taxable = round((float) ($returnRow['taxable_amount'] ?? 0), 2);
        $tax = round((float) ($returnRow['tax_amount'] ?? 0), 2);
        $total = round((float) ($returnRow['total_amount'] ?? 0), 2);
        if ($returnId <= 0 || $total <= 0) {
            return null;
        }
        $existing = ledger_find_latest_journal_by_reference($this->pdo, $this->companyId, 'VENDOR_RETURN_APPROVAL', $returnId);
        if ($existing) {
            return (int) ($existing['id'] ?? 0);
        }
        $lines = [[
            'account_code' => '2100',
            'debit_amount' => $total,
            'credit_amount' => 0.0,
            'garage_id' => $this->garageId,
            'party_type' => $vendorId > 0 ? 'VENDOR' : null,
            'party_id' => $vendorId > 0 ? $vendorId : null,
        ]];
        if ($taxable > 0) {
            $lines[] = ['account_code' => '1300', 'debit_amount' => 0.0, 'credit_amount' => $taxable, 'garage_id' => $this->garageId];
        }
        if ($tax > 0) {
            $lines[] = ['account_code' => '1215', 'debit_amount' => 0.0, 'credit_amount' => $tax, 'garage_id' => $this->garageId];
        }
        return ledger_create_journal($this->pdo, [
            'company_id' => $this->companyId,
            'reference_type' => 'VENDOR_RETURN_APPROVAL',
            'reference_id' => $returnId,
            'journal_date' => (string) ($returnRow['return_date'] ?? $this->baseDate),
            'narration' => 'Vendor return approved #' . $returnId,
            'created_by' => $this->adminUserId,
            'garage_id' => $this->garageId,
        ], $lines);
    }

    public function postPayrollAccrualJournal(int $sheetId, float $amount, string $journalDate, string $narration = ''): ?int
    {
        $amount = round($amount, 2);
        if ($sheetId <= 0 || $amount <= 0) {
            return null;
        }
        $existing = ledger_find_latest_journal_by_reference($this->pdo, $this->companyId, 'PAYROLL_ACCRUAL', $sheetId);
        if ($existing) {
            return (int) ($existing['id'] ?? 0);
        }
        return ledger_create_journal($this->pdo, [
            'company_id' => $this->companyId,
            'reference_type' => 'PAYROLL_ACCRUAL',
            'reference_id' => $sheetId,
            'journal_date' => $journalDate,
            'narration' => $narration !== '' ? $narration : ('Payroll accrual #' . $sheetId),
            'created_by' => $this->adminUserId,
            'garage_id' => $this->garageId,
        ], [
            ['account_code' => '5110', 'debit_amount' => $amount, 'credit_amount' => 0.0, 'garage_id' => $this->garageId],
            ['account_code' => '2400', 'debit_amount' => 0.0, 'credit_amount' => $amount, 'garage_id' => $this->garageId],
        ]);
    }

    public function countOperationalRows(): array
    {
        $counts = [];
        if ($this->tableExists('vendors')) $counts['vendors'] = (int) ($this->qv('SELECT COUNT(*) FROM vendors WHERE company_id=:c AND COALESCE(status_code,"ACTIVE")<>"DELETED"', ['c' => $this->companyId]) ?? 0);
        if ($this->tableExists('parts')) $counts['parts'] = (int) ($this->qv('SELECT COUNT(*) FROM parts WHERE company_id=:c AND COALESCE(status_code,"ACTIVE")<>"DELETED"', ['c' => $this->companyId]) ?? 0);
        if ($this->tableExists('customers')) $counts['customers'] = (int) ($this->qv('SELECT COUNT(*) FROM customers WHERE company_id=:c AND COALESCE(status_code,"ACTIVE")<>"DELETED"', ['c' => $this->companyId]) ?? 0);
        if ($this->tableExists('vehicles')) $counts['vehicles'] = (int) ($this->qv('SELECT COUNT(*) FROM vehicles WHERE company_id=:c AND COALESCE(status_code,"ACTIVE")<>"DELETED"', ['c' => $this->companyId]) ?? 0);
        if ($this->tableExists('job_cards')) $counts['job_cards'] = (int) ($this->qv('SELECT COUNT(*) FROM job_cards WHERE company_id=:c AND COALESCE(status_code,"ACTIVE")<>"DELETED"', ['c' => $this->companyId]) ?? 0);
        if ($this->tableExists('invoices')) $counts['invoices'] = (int) ($this->qv('SELECT COUNT(*) FROM invoices WHERE company_id=:c AND deleted_at IS NULL', ['c' => $this->companyId]) ?? 0);
        if ($this->tableExists('payments') && $this->tableExists('invoices')) {
            $salesPaymentFilterSql = $this->unreversedPaymentFilterSql('payments', 'p');
            $counts['payments'] = (int) ($this->qv(
                'SELECT COUNT(*)
                 FROM payments p
                 INNER JOIN invoices i ON i.id = p.invoice_id
                 WHERE i.company_id = :c
                   AND ' . $salesPaymentFilterSql,
                ['c' => $this->companyId]
            ) ?? 0);
        }
        if ($this->tableExists('job_advances')) $counts['advances'] = (int) ($this->qv('SELECT COUNT(*) FROM job_advances WHERE company_id=:c AND COALESCE(status_code,"ACTIVE")<>"DELETED"', ['c' => $this->companyId]) ?? 0);
        if ($this->tableExists('returns_rma')) $counts['returns'] = (int) ($this->qv('SELECT COUNT(*) FROM returns_rma WHERE company_id=:c AND COALESCE(status_code,"ACTIVE")<>"DELETED"', ['c' => $this->companyId]) ?? 0);
        if ($this->tableExists('purchases')) $counts['purchases'] = (int) ($this->qv('SELECT COUNT(*) FROM purchases WHERE company_id=:c AND COALESCE(status_code,"ACTIVE")<>"DELETED"', ['c' => $this->companyId]) ?? 0);
        if ($this->tableExists('purchase_payments') && $this->tableExists('purchases')) {
            $purchasePaymentFilterSql = $this->unreversedPaymentFilterSql('purchase_payments', 'pp');
            $counts['purchase_payments'] = (int) ($this->qv(
                'SELECT COUNT(*)
                 FROM purchase_payments pp
                 INNER JOIN purchases p ON p.id = pp.purchase_id
                 WHERE p.company_id = :c
                   AND COALESCE(p.status_code,"ACTIVE") <> "DELETED"
                   AND ' . $purchasePaymentFilterSql,
                ['c' => $this->companyId]
            ) ?? 0);
        }
        if ($this->tableExists('payroll_salary_items') && $this->tableExists('payroll_salary_sheets')) $counts['payroll_entries'] = (int) ($this->qv('SELECT COUNT(*) FROM payroll_salary_items psi INNER JOIN payroll_salary_sheets pss ON pss.id=psi.sheet_id WHERE pss.company_id=:c AND psi.deleted_at IS NULL', ['c' => $this->companyId]) ?? 0);
        if ($this->tableExists('expenses')) $counts['expenses'] = (int) ($this->qv('SELECT COUNT(*) FROM expenses WHERE company_id=:c AND deleted_at IS NULL AND entry_type="EXPENSE"', ['c' => $this->companyId]) ?? 0);
        if ($this->tableExists('outsourced_works')) $counts['outsourced_jobs'] = (int) ($this->qv('SELECT COUNT(*) FROM outsourced_works WHERE company_id=:c AND COALESCE(status_code,"ACTIVE")<>"DELETED"', ['c' => $this->companyId]) ?? 0);
        return $counts;
    }

    public function reportTotals(): array
    {
        $totals = [];

        if ($this->tableExists('invoices')) {
            $totals['sales'] = $this->qr(
                'SELECT COUNT(*) AS invoice_count,
                        ROUND(COALESCE(SUM(taxable_amount),0),2) AS taxable_total,
                        ROUND(COALESCE(SUM(total_tax_amount),0),2) AS gst_total,
                        ROUND(COALESCE(SUM(grand_total),0),2) AS grand_total
                 FROM invoices
                 WHERE company_id = :c AND deleted_at IS NULL',
                ['c' => $this->companyId]
            );
            $salesPaymentFilterSql = $this->tableExists('payments')
                ? $this->unreversedPaymentFilterSql('payments', 'pay')
                : '1 = 0';
            $totals['receivables'] = $this->qr(
                'SELECT
                    ROUND(COALESCE(SUM(i.grand_total),0),2) AS invoiced_total,
                    ROUND(COALESCE(SUM(COALESCE(pay.paid,0)),0),2) AS paid_total,
                    ROUND(COALESCE(SUM(COALESCE(a.adv,0)),0),2) AS advance_adjusted_total,
                    ROUND(COALESCE(SUM(GREATEST(i.grand_total - COALESCE(pay.paid,0) - COALESCE(a.adv,0),0)),0),2) AS outstanding_total
                 FROM invoices i
                 LEFT JOIN (
                     SELECT pay.invoice_id,
                            SUM(ABS(pay.amount)) AS paid
                     FROM payments pay
                     WHERE ' . $salesPaymentFilterSql . '
                     GROUP BY pay.invoice_id
                 ) pay ON pay.invoice_id = i.id
                 LEFT JOIN (
                     SELECT invoice_id, SUM(adjusted_amount) AS adv
                     FROM advance_adjustments
                     WHERE company_id = :c
                     GROUP BY invoice_id
                 ) a ON a.invoice_id = i.id
                 WHERE i.company_id = :c
                   AND i.invoice_status = "FINALIZED"
                   AND i.deleted_at IS NULL',
                ['c' => $this->companyId]
            );
        }

        if ($this->tableExists('purchases')) {
            $totals['purchases'] = $this->qr(
                'SELECT COUNT(*) AS purchase_count,
                        ROUND(COALESCE(SUM(taxable_amount),0),2) AS taxable_total,
                        ROUND(COALESCE(SUM(gst_amount),0),2) AS gst_total,
                        ROUND(COALESCE(SUM(grand_total),0),2) AS grand_total
                 FROM purchases
                 WHERE company_id = :c
                   AND COALESCE(status_code,"ACTIVE") <> "DELETED"',
                ['c' => $this->companyId]
            );
            if ($this->tableExists('purchase_payments')) {
                $purchasePaymentFilterSql = $this->unreversedPaymentFilterSql('purchase_payments', 'pp_raw');
                $totals['payables'] = $this->qr(
                    'SELECT
                        ROUND(COALESCE(SUM(p.grand_total),0),2) AS purchases_total,
                        ROUND(COALESCE(SUM(COALESCE(pp.paid,0)),0),2) AS paid_total,
                        ROUND(COALESCE(SUM(GREATEST(p.grand_total - COALESCE(pp.paid,0),0)),0),2) AS outstanding_total
                     FROM purchases p
                     LEFT JOIN (
                         SELECT pp_raw.purchase_id,
                                SUM(ABS(pp_raw.amount)) AS paid
                         FROM purchase_payments pp_raw
                         WHERE ' . $purchasePaymentFilterSql . '
                         GROUP BY pp_raw.purchase_id
                     ) pp ON pp.purchase_id = p.id
                     WHERE p.company_id = :c
                       AND p.purchase_status = "FINALIZED"
                       AND COALESCE(p.status_code,"ACTIVE") <> "DELETED"',
                    ['c' => $this->companyId]
                );
            }
        }

        if ($this->tableExists('job_advances')) {
            $totals['advances'] = $this->qr(
                'SELECT COUNT(*) AS advance_count,
                        ROUND(COALESCE(SUM(advance_amount),0),2) AS received_total,
                        ROUND(COALESCE(SUM(adjusted_amount),0),2) AS adjusted_total,
                        ROUND(COALESCE(SUM(balance_amount),0),2) AS balance_total
                 FROM job_advances
                 WHERE company_id = :c
                   AND COALESCE(status_code,"ACTIVE") <> "DELETED"',
                ['c' => $this->companyId]
            );
        }

        if ($this->tableExists('returns_rma')) {
            $totals['returns'] = $this->qr(
                'SELECT COUNT(*) AS return_count,
                        ROUND(COALESCE(SUM(CASE WHEN return_type="CUSTOMER_RETURN" THEN total_amount ELSE 0 END),0),2) AS customer_return_total,
                        ROUND(COALESCE(SUM(CASE WHEN return_type="VENDOR_RETURN" THEN total_amount ELSE 0 END),0),2) AS vendor_return_total,
                        ROUND(COALESCE(SUM(taxable_amount),0),2) AS taxable_total,
                        ROUND(COALESCE(SUM(tax_amount),0),2) AS tax_total,
                        ROUND(COALESCE(SUM(total_amount),0),2) AS grand_total
                 FROM returns_rma
                 WHERE company_id = :c
                   AND COALESCE(status_code,"ACTIVE") <> "DELETED"',
                ['c' => $this->companyId]
            );
        }

        if ($this->tableExists('expenses')) {
            $totals['expenses'] = $this->qr(
                'SELECT COUNT(*) AS row_count,
                        ROUND(COALESCE(SUM(CASE WHEN entry_type="EXPENSE" THEN amount ELSE 0 END),0),2) AS expense_total,
                        ROUND(COALESCE(SUM(CASE WHEN entry_type="REVERSAL" THEN amount ELSE 0 END),0),2) AS reversal_total,
                        ROUND(COALESCE(SUM(amount),0),2) AS net_total
                 FROM expenses
                 WHERE company_id = :c
                   AND deleted_at IS NULL',
                ['c' => $this->companyId]
            );
        }

        if ($this->tableExists('payroll_salary_sheets')) {
            $totals['payroll_sheets'] = $this->qr(
                'SELECT COUNT(*) AS sheet_count,
                        ROUND(COALESCE(SUM(total_gross),0),2) AS total_gross,
                        ROUND(COALESCE(SUM(total_deductions),0),2) AS total_deductions,
                        ROUND(COALESCE(SUM(total_payable),0),2) AS total_payable,
                        ROUND(COALESCE(SUM(total_paid),0),2) AS total_paid
                 FROM payroll_salary_sheets
                 WHERE company_id = :c',
                ['c' => $this->companyId]
            );
        }

        if ($this->tableExists('payroll_salary_items') && $this->tableExists('payroll_salary_sheets')) {
            $totals['payroll_items'] = $this->qr(
                'SELECT COUNT(*) AS item_count,
                        ROUND(COALESCE(SUM(psi.gross_amount),0),2) AS total_gross,
                        ROUND(COALESCE(SUM(psi.net_payable),0),2) AS total_payable,
                        ROUND(COALESCE(SUM(psi.paid_amount),0),2) AS total_paid
                 FROM payroll_salary_items psi
                 INNER JOIN payroll_salary_sheets pss ON pss.id = psi.sheet_id
                 WHERE pss.company_id = :c
                   AND psi.deleted_at IS NULL',
                ['c' => $this->companyId]
            );
        }

        if ($this->tableExists('outsourced_works')) {
            $totals['outsourced'] = $this->qr(
                'SELECT COUNT(*) AS work_count,
                        ROUND(COALESCE(SUM(agreed_cost),0),2) AS agreed_cost_total
                 FROM outsourced_works
                 WHERE company_id = :c
                   AND COALESCE(status_code,"ACTIVE") <> "DELETED"',
                ['c' => $this->companyId]
            );
        }

        if ($this->tableExists('outsourced_work_payments') && $this->tableExists('outsourced_works')) {
            $totals['outsourced_payments'] = $this->qr(
                'SELECT COUNT(*) AS payment_count,
                        ROUND(COALESCE(SUM(CASE WHEN owp.entry_type="REVERSAL" THEN -ABS(owp.amount) ELSE ABS(owp.amount) END),0),2) AS net_paid
                 FROM outsourced_work_payments owp
                 INNER JOIN outsourced_works ow ON ow.id = owp.outsourced_work_id
                 WHERE ow.company_id = :c',
                ['c' => $this->companyId]
            );
        }

        return $totals;
    }

    public function captureSnapshot(): array
    {
        $snapshot = [
            'captured_at' => date('c'),
            'scope' => [
                'company_id' => $this->companyId,
                'garage_id' => $this->garageId,
                'dataset_tag' => $this->datasetTag,
            ],
            'counts' => $this->countOperationalRows(),
            'ledger_totals' => [],
            'stock_totals' => [],
            'report_totals' => $this->reportTotals(),
        ];

        if ($this->tableExists('ledger_journals') && $this->tableExists('ledger_entries')) {
            $snapshot['ledger_totals'] = $this->qr(
                'SELECT COUNT(DISTINCT lj.id) AS journal_count,
                        COUNT(le.id) AS entry_count,
                        ROUND(COALESCE(SUM(le.debit_amount),0),2) AS debit_total,
                        ROUND(COALESCE(SUM(le.credit_amount),0),2) AS credit_total
                 FROM ledger_journals lj
                 LEFT JOIN ledger_entries le ON le.journal_id = lj.id
                 WHERE lj.company_id = :c',
                ['c' => $this->companyId]
            );
            $byAccount = $this->qa(
                'SELECT coa.code,
                        ROUND(COALESCE(SUM(le.debit_amount),0),2) AS debit_total,
                        ROUND(COALESCE(SUM(le.credit_amount),0),2) AS credit_total,
                        ROUND(COALESCE(SUM(le.debit_amount - le.credit_amount),0),2) AS net
                 FROM ledger_entries le
                 INNER JOIN ledger_journals lj ON lj.id = le.journal_id
                 INNER JOIN chart_of_accounts coa ON coa.id = le.account_id
                 WHERE lj.company_id = :c
                 GROUP BY coa.code
                 ORDER BY coa.code',
                ['c' => $this->companyId]
            );
            $snapshot['ledger_totals']['by_account'] = [];
            foreach ($byAccount as $row) {
                $snapshot['ledger_totals']['by_account'][(string) $row['code']] = [
                    'debit_total' => (float) ($row['debit_total'] ?? 0),
                    'credit_total' => (float) ($row['credit_total'] ?? 0),
                    'net' => (float) ($row['net'] ?? 0),
                ];
            }
        }

        if ($this->tableExists('garage_inventory')) {
            $snapshot['stock_totals'] = $this->qr(
                'SELECT COUNT(*) AS stock_rows,
                        ROUND(COALESCE(SUM(gi.quantity),0),2) AS garage_quantity_total,
                        ROUND(COALESCE(SUM(gi.quantity * COALESCE(p.purchase_price,0)),0),2) AS garage_stock_value_cost
                 FROM garage_inventory gi
                 LEFT JOIN parts p ON p.id = gi.part_id
                 WHERE gi.garage_id = :g',
                ['g' => $this->garageId]
            );
            if ($this->tableExists('inventory_movements')) {
                $mov = $this->qr(
                    'SELECT COUNT(*) AS movement_count,
                            ROUND(COALESCE(SUM(CASE
                                WHEN movement_type="IN" THEN ABS(quantity)
                                WHEN movement_type="OUT" THEN -ABS(quantity)
                                ELSE quantity END),0),2) AS movement_quantity_total
                     FROM inventory_movements
                     WHERE company_id = :c
                       AND garage_id = :g
                       AND deleted_at IS NULL',
                    ['c' => $this->companyId, 'g' => $this->garageId]
                );
                $snapshot['stock_totals'] = array_merge($snapshot['stock_totals'], $mov);
            }
        }

        return $snapshot;
    }

    public function flattenNumeric(mixed $value, string $prefix = ''): array
    {
        $out = [];
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $key = $prefix === '' ? (string) $k : ($prefix . '.' . (string) $k);
                foreach ($this->flattenNumeric($v, $key) as $fk => $fv) {
                    $out[$fk] = $fv;
                }
            }
            return $out;
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $out[$prefix] = (float) $value;
        }
        return $out;
    }

    public function snapshotDiff(array $before, array $after): array
    {
        $diff = [];
        foreach (['counts', 'ledger_totals', 'stock_totals', 'report_totals'] as $section) {
            $b = $this->flattenNumeric($before[$section] ?? [], $section);
            $a = $this->flattenNumeric($after[$section] ?? [], $section);
            $keys = array_values(array_unique(array_merge(array_keys($b), array_keys($a))));
            sort($keys);
            foreach ($keys as $path) {
                $beforeVal = $b[$path] ?? 0.0;
                $afterVal = $a[$path] ?? 0.0;
                $delta = round($afterVal - $beforeVal, 2);
                if (abs($delta) <= 0.009) {
                    continue;
                }
                $diff[$path] = [
                    'before' => round($beforeVal, 2),
                    'after' => round($afterVal, 2),
                    'delta' => $delta,
                ];
            }
        }
        return $diff;
    }

    public function compareExpectedDelta(array $beforeSnapshot, array $afterSnapshot, array $expectedDelta, float $tol = 0.01): array
    {
        $b = $this->flattenNumeric($beforeSnapshot, '');
        $a = $this->flattenNumeric($afterSnapshot, '');
        $e = $this->flattenNumeric($expectedDelta, '');
        $errors = [];
        foreach ($e as $path => $expected) {
            $beforeVal = $b[$path] ?? 0.0;
            $afterVal = $a[$path] ?? 0.0;
            $actualDelta = round($afterVal - $beforeVal, 2);
            if (abs($actualDelta - $expected) > $tol) {
                $errors[$path] = [
                    'before' => round($beforeVal, 2),
                    'after' => round($afterVal, 2),
                    'expected_delta' => round($expected, 2),
                    'actual_delta' => $actualDelta,
                ];
            }
        }
        return $errors;
    }

    public function defaultExpectedSeedCounts(): array
    {
        return [
            'vendors' => 5,
            'parts' => 10,
            'customers' => 5,
            'vehicles' => 10,
            'job_cards' => 20,
            'invoices' => 15,
            'payments' => 10,
            'advances' => 5,
            'returns' => 5,
            'purchases' => 10,
            'payroll_entries' => 5,
            'expenses' => 5,
            'outsourced_jobs' => 3,
        ];
    }

    public function ensureExpectedCounts(array $expected): array
    {
        $actual = $this->countOperationalRows();
        $mismatches = [];
        foreach ($expected as $key => $value) {
            if ((int) ($actual[$key] ?? 0) !== (int) $value) {
                $mismatches[$key] = ['expected' => (int) $value, 'actual' => (int) ($actual[$key] ?? 0)];
            }
        }
        return ['actual' => $actual, 'mismatches' => $mismatches];
    }

    public function validateAll(): array
    {
        $checks = [
            $this->validateStockConsistency(),
            $this->validateLedgerIntegrity(),
            $this->validateReportConsistency(),
            $this->validateOutstandingAccuracy(),
            $this->validateGstTotals(),
            $this->validateNoOrphans(),
        ];

        $status = 'PASS';
        foreach ($checks as $check) {
            $s = strtoupper((string) ($check['status'] ?? 'FAIL'));
            if ($s === 'FAIL') {
                $status = 'FAIL';
                break;
            }
            if ($s === 'WARN' && $status !== 'FAIL') {
                $status = 'WARN';
            }
        }

        return ['status' => $status, 'checks' => $checks];
    }

    private function validateStockConsistency(): array
    {
        if (!$this->tableExists('garage_inventory') || !$this->tableExists('inventory_movements')) {
            return ['name' => 'stock_consistency', 'status' => 'WARN', 'message' => 'Stock tables missing'];
        }

        $duplicateUids = (int) ($this->qv(
            'SELECT COUNT(*) FROM (
                SELECT movement_uid
                FROM inventory_movements
                WHERE company_id = :c
                  AND movement_uid IS NOT NULL
                  AND TRIM(movement_uid) <> ""
                GROUP BY movement_uid
                HAVING COUNT(*) > 1
             ) x',
            ['c' => $this->companyId]
        ) ?? 0);

        $mismatches = $this->qa(
            'SELECT gi.part_id,
                    ROUND(gi.quantity,2) AS garage_qty,
                    ROUND(COALESCE(SUM(CASE
                        WHEN im.deleted_at IS NOT NULL THEN 0
                        WHEN im.movement_type="IN" THEN ABS(im.quantity)
                        WHEN im.movement_type="OUT" THEN -ABS(im.quantity)
                        ELSE im.quantity END),0),2) AS movement_qty
             FROM garage_inventory gi
             LEFT JOIN inventory_movements im
                    ON im.company_id = :c
                   AND im.garage_id = gi.garage_id
                   AND im.part_id = gi.part_id
             WHERE gi.garage_id = :g
             GROUP BY gi.part_id, gi.quantity
             HAVING ABS(garage_qty - movement_qty) > 0.01
             ORDER BY gi.part_id
             LIMIT 20',
            ['c' => $this->companyId, 'g' => $this->garageId]
        );

        $negative = (int) ($this->qv('SELECT COUNT(*) FROM garage_inventory WHERE garage_id = :g AND quantity < -0.01', ['g' => $this->garageId]) ?? 0);
        $status = ($duplicateUids === 0 && $mismatches === [] && $negative === 0) ? 'PASS' : 'FAIL';

        return [
            'name' => 'stock_consistency',
            'status' => $status,
            'details' => [
                'duplicate_movement_uid' => $duplicateUids,
                'stock_mismatches' => count($mismatches),
                'negative_stock_rows' => $negative,
            ],
            'sample' => $mismatches,
        ];
    }

    private function validateLedgerIntegrity(): array
    {
        if (!$this->tableExists('ledger_journals') || !$this->tableExists('ledger_entries') || !$this->tableExists('chart_of_accounts')) {
            return ['name' => 'ledger_balance', 'status' => 'WARN', 'message' => 'Ledger tables missing'];
        }

        $unbalanced = $this->qa(
            'SELECT lj.id,
                    ROUND(COALESCE(SUM(le.debit_amount),0),2) AS debit_total,
                    ROUND(COALESCE(SUM(le.credit_amount),0),2) AS credit_total,
                    COUNT(le.id) AS entry_count
             FROM ledger_journals lj
             LEFT JOIN ledger_entries le ON le.journal_id = lj.id
             WHERE lj.company_id = :c
             GROUP BY lj.id
             HAVING entry_count = 0 OR ABS(debit_total - credit_total) > 0.009
             ORDER BY lj.id
             LIMIT 20',
            ['c' => $this->companyId]
        );

        $invoiceMismatches = [];
        if ($this->tableExists('invoices')) {
            $invoiceMismatches = $this->qa(
                'SELECT i.id, ROUND(i.grand_total,2) AS grand_total,
                        ROUND(COALESCE((
                            SELECT SUM(le.debit_amount - le.credit_amount)
                            FROM ledger_journals lj
                            INNER JOIN ledger_entries le ON le.journal_id = lj.id
                            INNER JOIN chart_of_accounts coa ON coa.id = le.account_id
                            WHERE lj.company_id = i.company_id
                              AND lj.reference_type = "INVOICE_FINALIZE"
                              AND lj.reference_id = i.id
                              AND coa.code = "1200"
                        ),0),2) AS ledger_ar
                 FROM invoices i
                 WHERE i.company_id = :c
                   AND i.invoice_status = "FINALIZED"
                   AND i.deleted_at IS NULL
                 HAVING ABS(grand_total - ledger_ar) > 0.01
                 ORDER BY i.id
                 LIMIT 20',
                ['c' => $this->companyId]
            );
        }

        $purchaseMismatches = [];
        if ($this->tableExists('purchases')) {
            $purchaseMismatches = $this->qa(
                'SELECT p.id, ROUND(p.grand_total,2) AS grand_total,
                        ROUND(COALESCE((
                            SELECT SUM(le.credit_amount - le.debit_amount)
                            FROM ledger_journals lj
                            INNER JOIN ledger_entries le ON le.journal_id = lj.id
                            INNER JOIN chart_of_accounts coa ON coa.id = le.account_id
                            WHERE lj.company_id = p.company_id
                              AND lj.reference_type = "PURCHASE_FINALIZE"
                              AND lj.reference_id = p.id
                              AND coa.code = "2100"
                        ),0),2) AS ledger_ap
                 FROM purchases p
                 WHERE p.company_id = :c
                   AND p.purchase_status = "FINALIZED"
                   AND COALESCE(p.status_code,"ACTIVE") <> "DELETED"
                 HAVING ABS(grand_total - ledger_ap) > 0.01
                 ORDER BY p.id
                 LIMIT 20',
                ['c' => $this->companyId]
            );
        }

        $status = ($unbalanced === [] && $invoiceMismatches === [] && $purchaseMismatches === []) ? 'PASS' : 'FAIL';
        return [
            'name' => 'ledger_balance',
            'status' => $status,
            'details' => [
                'unbalanced_journals' => count($unbalanced),
                'invoice_ledger_mismatches' => count($invoiceMismatches),
                'purchase_ledger_mismatches' => count($purchaseMismatches),
            ],
            'sample' => array_slice(array_merge($unbalanced, $invoiceMismatches, $purchaseMismatches), 0, 20),
        ];
    }

    private function validateReportConsistency(): array
    {
        $samples = [];
        $count = 0;

        if ($this->tableExists('invoices') && $this->tableExists('invoice_items')) {
            $rows = $this->qa(
                'SELECT i.id,
                        ROUND(i.taxable_amount,2) AS header_taxable,
                        ROUND(i.total_tax_amount,2) AS header_tax,
                        ROUND(i.grand_total,2) AS header_total,
                        ROUND(COALESCE(SUM(ii.taxable_value),0),2) AS line_taxable,
                        ROUND(COALESCE(SUM(ii.tax_amount),0),2) AS line_tax,
                        ROUND(COALESCE(SUM(ii.total_value),0),2) AS line_total
                 FROM invoices i
                 LEFT JOIN invoice_items ii ON ii.invoice_id = i.id
                 WHERE i.company_id = :c
                   AND i.deleted_at IS NULL
                 GROUP BY i.id, i.taxable_amount, i.total_tax_amount, i.grand_total
                 HAVING ABS(header_taxable-line_taxable) > 0.01
                     OR ABS(header_tax-line_tax) > 0.01
                     OR ABS(header_total-line_total) > 0.01
                 ORDER BY i.id
                 LIMIT 20',
                ['c' => $this->companyId]
            );
            $count += count($rows);
            $samples = array_merge($samples, $rows);
        }

        if ($this->tableExists('purchases') && $this->tableExists('purchase_items')) {
            $rows = $this->qa(
                'SELECT p.id,
                        ROUND(p.taxable_amount,2) AS header_taxable,
                        ROUND(p.gst_amount,2) AS header_tax,
                        ROUND(p.grand_total,2) AS header_total,
                        ROUND(COALESCE(SUM(pi.taxable_amount),0),2) AS line_taxable,
                        ROUND(COALESCE(SUM(pi.gst_amount),0),2) AS line_tax,
                        ROUND(COALESCE(SUM(pi.total_amount),0),2) AS line_total
                 FROM purchases p
                 LEFT JOIN purchase_items pi ON pi.purchase_id = p.id
                 WHERE p.company_id = :c
                   AND COALESCE(p.status_code,"ACTIVE") <> "DELETED"
                 GROUP BY p.id, p.taxable_amount, p.gst_amount, p.grand_total
                 HAVING ABS(header_taxable-line_taxable) > 0.01
                     OR ABS(header_tax-line_tax) > 0.01
                     OR ABS(header_total-line_total) > 0.01
                 ORDER BY p.id
                 LIMIT 20',
                ['c' => $this->companyId]
            );
            $count += count($rows);
            $samples = array_merge($samples, $rows);
        }

        if ($this->tableExists('returns_rma') && $this->tableExists('return_items')) {
            $rows = $this->qa(
                'SELECT r.id,
                        ROUND(r.taxable_amount,2) AS header_taxable,
                        ROUND(r.tax_amount,2) AS header_tax,
                        ROUND(r.total_amount,2) AS header_total,
                        ROUND(COALESCE(SUM(ri.taxable_amount),0),2) AS line_taxable,
                        ROUND(COALESCE(SUM(ri.tax_amount),0),2) AS line_tax,
                        ROUND(COALESCE(SUM(ri.total_amount),0),2) AS line_total
                 FROM returns_rma r
                 LEFT JOIN return_items ri ON ri.return_id = r.id
                 WHERE r.company_id = :c
                   AND COALESCE(r.status_code,"ACTIVE") <> "DELETED"
                 GROUP BY r.id, r.taxable_amount, r.tax_amount, r.total_amount
                 HAVING ABS(header_taxable-line_taxable) > 0.01
                     OR ABS(header_tax-line_tax) > 0.01
                     OR ABS(header_total-line_total) > 0.01
                 ORDER BY r.id
                 LIMIT 20',
                ['c' => $this->companyId]
            );
            $count += count($rows);
            $samples = array_merge($samples, $rows);
        }

        if ($this->tableExists('payroll_salary_sheets') && $this->tableExists('payroll_salary_items')) {
            $rows = $this->qa(
                'SELECT pss.id,
                        ROUND(pss.total_gross,2) AS sheet_gross,
                        ROUND(pss.total_deductions,2) AS sheet_deductions,
                        ROUND(pss.total_payable,2) AS sheet_payable,
                        ROUND(pss.total_paid,2) AS sheet_paid,
                        ROUND(COALESCE(SUM(psi.gross_amount),0),2) AS item_gross,
                        ROUND(COALESCE(SUM(psi.gross_amount-psi.net_payable),0),2) AS item_deductions,
                        ROUND(COALESCE(SUM(psi.net_payable),0),2) AS item_payable,
                        ROUND(COALESCE(SUM(psi.paid_amount),0),2) AS item_paid
                 FROM payroll_salary_sheets pss
                 LEFT JOIN payroll_salary_items psi ON psi.sheet_id = pss.id AND psi.deleted_at IS NULL
                 WHERE pss.company_id = :c
                 GROUP BY pss.id, pss.total_gross, pss.total_deductions, pss.total_payable, pss.total_paid
                 HAVING ABS(sheet_gross-item_gross) > 0.01
                     OR ABS(sheet_deductions-item_deductions) > 0.01
                     OR ABS(sheet_payable-item_payable) > 0.01
                     OR ABS(sheet_paid-item_paid) > 0.01
                 ORDER BY pss.id
                 LIMIT 20',
                ['c' => $this->companyId]
            );
            $count += count($rows);
            $samples = array_merge($samples, $rows);
        }

        return ['name' => 'report_totals', 'status' => $count === 0 ? 'PASS' : 'FAIL', 'details' => ['mismatch_count' => $count], 'sample' => array_slice($samples, 0, 20)];
    }

    private function validateOutstandingAccuracy(): array
    {
        $issues = [];

        if ($this->tableExists('invoices')) {
            $salesPaymentFilterSql = $this->tableExists('payments')
                ? $this->unreversedPaymentFilterSql('payments', 'p')
                : '1 = 0';
            $rows = $this->qa(
                'SELECT * FROM (
                    SELECT i.id,
                           UPPER(COALESCE(i.payment_status,"")) AS header_status,
                           CASE
                               WHEN i.invoice_status = "CANCELLED" THEN "CANCELLED"
                               WHEN (COALESCE(p.paid,0) + COALESCE(a.adv,0)) <= 0.009 THEN "UNPAID"
                               WHEN (COALESCE(p.paid,0) + COALESCE(a.adv,0)) + 0.009 >= i.grand_total THEN "PAID"
                               ELSE "PARTIAL"
                           END AS calc_status
                    FROM invoices i
                    LEFT JOIN (
                        SELECT p.invoice_id, SUM(ABS(p.amount)) AS paid
                        FROM payments p
                        WHERE ' . $salesPaymentFilterSql . '
                        GROUP BY p.invoice_id
                    ) p ON p.invoice_id = i.id
                    LEFT JOIN (
                        SELECT invoice_id, SUM(adjusted_amount) AS adv
                        FROM advance_adjustments
                        WHERE company_id = :c
                        GROUP BY invoice_id
                    ) a ON a.invoice_id = i.id
                    WHERE i.company_id = :c
                      AND i.deleted_at IS NULL
                ) x
                WHERE header_status <> calc_status
                ORDER BY id
                LIMIT 20',
                ['c' => $this->companyId]
            );
            $issues = array_merge($issues, $rows);
        }

        if ($this->tableExists('purchases') && $this->tableExists('purchase_payments')) {
            $purchasePaymentFilterSql = $this->unreversedPaymentFilterSql('purchase_payments', 'pp');
            $rows = $this->qa(
                'SELECT * FROM (
                    SELECT p.id,
                           UPPER(COALESCE(p.payment_status,"")) AS header_status,
                           CASE
                               WHEN COALESCE(pay.paid,0) <= 0.009 THEN "UNPAID"
                               WHEN COALESCE(pay.paid,0) + 0.009 >= p.grand_total THEN "PAID"
                               ELSE "PARTIAL"
                           END AS calc_status
                    FROM purchases p
                    LEFT JOIN (
                        SELECT pp.purchase_id, SUM(ABS(pp.amount)) AS paid
                        FROM purchase_payments pp
                        WHERE ' . $purchasePaymentFilterSql . '
                        GROUP BY pp.purchase_id
                    ) pay ON pay.purchase_id = p.id
                    WHERE p.company_id = :c
                      AND COALESCE(p.status_code,"ACTIVE") <> "DELETED"
                ) x
                WHERE header_status <> calc_status
                ORDER BY id
                LIMIT 20',
                ['c' => $this->companyId]
            );
            $issues = array_merge($issues, $rows);
        }

        return ['name' => 'outstanding_accuracy', 'status' => $issues === [] ? 'PASS' : 'FAIL', 'details' => ['issue_count' => count($issues)], 'sample' => $issues];
    }

    private function validateGstTotals(): array
    {
        $issues = [];

        if ($this->tableExists('invoices')) {
            $rows = $this->qa(
                'SELECT id,
                        ROUND(total_tax_amount,2) AS total_tax_amount,
                        ROUND(cgst_amount + sgst_amount + igst_amount,2) AS split_tax_total
                 FROM invoices
                 WHERE company_id = :c
                   AND deleted_at IS NULL
                   AND ABS(total_tax_amount - (cgst_amount + sgst_amount + igst_amount)) > 0.01
                 ORDER BY id
                 LIMIT 20',
                ['c' => $this->companyId]
            );
            $issues = array_merge($issues, $rows);
        }

        if ($this->tableExists('returns_rma') && $this->tableExists('return_items')) {
            $rows = $this->qa(
                'SELECT r.id,
                        ROUND(r.tax_amount,2) AS header_tax,
                        ROUND(COALESCE(SUM(ri.tax_amount),0),2) AS line_tax
                 FROM returns_rma r
                 LEFT JOIN return_items ri ON ri.return_id = r.id
                 WHERE r.company_id = :c
                   AND COALESCE(r.status_code,"ACTIVE") <> "DELETED"
                 GROUP BY r.id, r.tax_amount
                 HAVING ABS(header_tax - line_tax) > 0.01
                 ORDER BY r.id
                 LIMIT 20',
                ['c' => $this->companyId]
            );
            $issues = array_merge($issues, $rows);
        }

        return ['name' => 'gst_totals', 'status' => $issues === [] ? 'PASS' : 'FAIL', 'details' => ['issue_count' => count($issues)], 'sample' => $issues];
    }

    private function validateNoOrphans(): array
    {
        $issues = [];
        $add = function (string $name, string $sql, array $params = []) use (&$issues): void {
            $count = (int) ($this->qv($sql, $params) ?? 0);
            if ($count > 0) {
                $issues[$name] = $count;
            }
        };

        if ($this->tableExists('vehicles') && $this->tableExists('customers')) {
            $add('vehicles_customer', 'SELECT COUNT(*) FROM vehicles v LEFT JOIN customers c ON c.id=v.customer_id WHERE v.company_id=:c AND c.id IS NULL', ['c' => $this->companyId]);
        }
        if ($this->tableExists('job_cards')) {
            if ($this->tableExists('customers')) {
                $add('job_cards_customer', 'SELECT COUNT(*) FROM job_cards j LEFT JOIN customers c ON c.id=j.customer_id WHERE j.company_id=:c AND c.id IS NULL', ['c' => $this->companyId]);
            }
            if ($this->tableExists('vehicles')) {
                $add('job_cards_vehicle', 'SELECT COUNT(*) FROM job_cards j LEFT JOIN vehicles v ON v.id=j.vehicle_id WHERE j.company_id=:c AND v.id IS NULL', ['c' => $this->companyId]);
            }
        }
        if ($this->tableExists('job_parts') && $this->tableExists('job_cards')) {
            $add('job_parts_job', 'SELECT COUNT(*) FROM job_parts jp LEFT JOIN job_cards j ON j.id=jp.job_card_id WHERE j.id IS NULL', []);
            if ($this->tableExists('parts')) {
                $add('job_parts_part', 'SELECT COUNT(*) FROM job_parts jp INNER JOIN job_cards j ON j.id=jp.job_card_id LEFT JOIN parts p ON p.id=jp.part_id WHERE j.company_id=:c AND p.id IS NULL', ['c' => $this->companyId]);
            }
        }
        if ($this->tableExists('job_labor') && $this->tableExists('job_cards')) {
            $add('job_labor_job', 'SELECT COUNT(*) FROM job_labor jl LEFT JOIN job_cards j ON j.id=jl.job_card_id WHERE j.id IS NULL', []);
        }
        if ($this->tableExists('invoices')) {
            if ($this->tableExists('job_cards')) {
                $add('invoices_job', 'SELECT COUNT(*) FROM invoices i LEFT JOIN job_cards j ON j.id=i.job_card_id WHERE i.company_id=:c AND j.id IS NULL', ['c' => $this->companyId]);
            }
            if ($this->tableExists('customers')) {
                $add('invoices_customer', 'SELECT COUNT(*) FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id WHERE i.company_id=:c AND c.id IS NULL', ['c' => $this->companyId]);
            }
            if ($this->tableExists('vehicles')) {
                $add('invoices_vehicle', 'SELECT COUNT(*) FROM invoices i LEFT JOIN vehicles v ON v.id=i.vehicle_id WHERE i.company_id=:c AND v.id IS NULL', ['c' => $this->companyId]);
            }
        }
        if ($this->tableExists('invoice_items') && $this->tableExists('invoices')) {
            $add('invoice_items_invoice', 'SELECT COUNT(*) FROM invoice_items ii LEFT JOIN invoices i ON i.id=ii.invoice_id WHERE i.id IS NULL', []);
        }
        if ($this->tableExists('payments') && $this->tableExists('invoices')) {
            $add('payments_invoice', 'SELECT COUNT(*) FROM payments p LEFT JOIN invoices i ON i.id=p.invoice_id WHERE i.id IS NULL', []);
        }
        if ($this->tableExists('job_advances') && $this->tableExists('job_cards')) {
            $add('job_advances_job', 'SELECT COUNT(*) FROM job_advances a LEFT JOIN job_cards j ON j.id=a.job_card_id WHERE a.company_id=:c AND j.id IS NULL', ['c' => $this->companyId]);
        }
        if ($this->tableExists('advance_adjustments')) {
            if ($this->tableExists('job_advances')) {
                $add('advance_adjustments_advance', 'SELECT COUNT(*) FROM advance_adjustments ad LEFT JOIN job_advances a ON a.id=ad.advance_id WHERE ad.company_id=:c AND a.id IS NULL', ['c' => $this->companyId]);
            }
            if ($this->tableExists('invoices')) {
                $add('advance_adjustments_invoice', 'SELECT COUNT(*) FROM advance_adjustments ad LEFT JOIN invoices i ON i.id=ad.invoice_id WHERE ad.company_id=:c AND i.id IS NULL', ['c' => $this->companyId]);
            }
        }
        if ($this->tableExists('purchases') && $this->tableExists('vendors')) {
            $add('purchases_vendor', 'SELECT COUNT(*) FROM purchases p LEFT JOIN vendors v ON v.id=p.vendor_id WHERE p.company_id=:c AND p.vendor_id IS NOT NULL AND v.id IS NULL', ['c' => $this->companyId]);
        }
        if ($this->tableExists('purchase_items') && $this->tableExists('purchases')) {
            $add('purchase_items_purchase', 'SELECT COUNT(*) FROM purchase_items pi LEFT JOIN purchases p ON p.id=pi.purchase_id WHERE p.id IS NULL', []);
            if ($this->tableExists('parts')) {
                $add('purchase_items_part', 'SELECT COUNT(*) FROM purchase_items pi LEFT JOIN parts p ON p.id=pi.part_id WHERE pi.part_id IS NOT NULL AND p.id IS NULL', []);
            }
        }
        if ($this->tableExists('return_items') && $this->tableExists('returns_rma')) {
            $add('return_items_return', 'SELECT COUNT(*) FROM return_items ri LEFT JOIN returns_rma r ON r.id=ri.return_id WHERE r.id IS NULL', []);
        }
        if ($this->tableExists('returns_rma')) {
            if ($this->tableExists('invoices')) {
                $add('returns_invoice', 'SELECT COUNT(*) FROM returns_rma r LEFT JOIN invoices i ON i.id=r.invoice_id WHERE r.company_id=:c AND r.invoice_id IS NOT NULL AND i.id IS NULL', ['c' => $this->companyId]);
            }
            if ($this->tableExists('purchases')) {
                $add('returns_purchase', 'SELECT COUNT(*) FROM returns_rma r LEFT JOIN purchases p ON p.id=r.purchase_id WHERE r.company_id=:c AND r.purchase_id IS NOT NULL AND p.id IS NULL', ['c' => $this->companyId]);
            }
        }
        if ($this->tableExists('outsourced_works') && $this->tableExists('job_cards')) {
            $add('outsourced_works_job', 'SELECT COUNT(*) FROM outsourced_works ow LEFT JOIN job_cards j ON j.id=ow.job_card_id WHERE ow.company_id=:c AND j.id IS NULL', ['c' => $this->companyId]);
            if ($this->tableExists('job_labor')) {
                $add('outsourced_works_job_labor', 'SELECT COUNT(*) FROM outsourced_works ow LEFT JOIN job_labor jl ON jl.id=ow.job_labor_id WHERE ow.company_id=:c AND ow.job_labor_id IS NOT NULL AND jl.id IS NULL', ['c' => $this->companyId]);
            }
        }
        if ($this->tableExists('payroll_salary_items') && $this->tableExists('payroll_salary_sheets')) {
            $add('payroll_salary_items_sheet', 'SELECT COUNT(*) FROM payroll_salary_items psi LEFT JOIN payroll_salary_sheets pss ON pss.id=psi.sheet_id WHERE pss.id IS NULL', []);
            if ($this->tableExists('users')) {
                $add('payroll_salary_items_user', 'SELECT COUNT(*) FROM payroll_salary_items psi LEFT JOIN users u ON u.id=psi.user_id WHERE u.id IS NULL', []);
            }
        }
        if ($this->tableExists('expenses') && $this->tableExists('expense_categories')) {
            $add('expenses_category', 'SELECT COUNT(*) FROM expenses e LEFT JOIN expense_categories ec ON ec.id=e.category_id WHERE e.company_id=:c AND e.category_id IS NOT NULL AND ec.id IS NULL', ['c' => $this->companyId]);
        }
        if ($this->tableExists('inventory_movements') && $this->tableExists('parts')) {
            $add('inventory_movements_part', 'SELECT COUNT(*) FROM inventory_movements im LEFT JOIN parts p ON p.id=im.part_id WHERE im.company_id=:c AND p.id IS NULL', ['c' => $this->companyId]);
        }
        if ($this->tableExists('garage_inventory') && $this->tableExists('parts')) {
            $add('garage_inventory_part', 'SELECT COUNT(*) FROM garage_inventory gi LEFT JOIN parts p ON p.id=gi.part_id WHERE gi.garage_id=:g AND p.id IS NULL', ['g' => $this->garageId]);
        }
        if ($this->tableExists('ledger_entries') && $this->tableExists('ledger_journals') && $this->tableExists('chart_of_accounts')) {
            $add('ledger_entries_journal', 'SELECT COUNT(*) FROM ledger_entries le LEFT JOIN ledger_journals lj ON lj.id=le.journal_id WHERE lj.id IS NULL', []);
            $add('ledger_entries_account', 'SELECT COUNT(*) FROM ledger_entries le LEFT JOIN chart_of_accounts coa ON coa.id=le.account_id WHERE coa.id IS NULL', []);
        }

        return ['name' => 'no_orphans', 'status' => $issues === [] ? 'PASS' : 'FAIL', 'details' => ['issue_count' => array_sum($issues)], 'sample' => $issues];
    }
}
