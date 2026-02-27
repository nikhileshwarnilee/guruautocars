<?php
declare(strict_types=1);

/**
 * Double-entry ledger posting service and schema bootstrap.
 *
 * Notes:
 * - Ledger tables are bootstrapped at app startup to avoid DDL inside module transactions.
 * - Posting helpers are append-only; reversals create reversing journals.
 */

function ledger_round(float $amount): float
{
    return round($amount, 2);
}

function ledger_is_valid_date(?string $date): bool
{
    $value = trim((string) $date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }

    [$y, $m, $d] = array_map('intval', explode('-', $value));
    return checkdate($m, $d, $y);
}

function ledger_table_columns_uncached(PDO $pdo, string $tableName): array
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
        return [];
    }

    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . $tableName . '`');
        $columns = [];
        foreach ($stmt->fetchAll() as $row) {
            $field = trim((string) ($row['Field'] ?? ''));
            if ($field !== '') {
                $columns[] = $field;
            }
        }
        return $columns;
    } catch (Throwable $exception) {
        return [];
    }
}

function ledger_table_has_index(PDO $pdo, string $tableName, string $indexName): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName) || !preg_match('/^[a-zA-Z0-9_]+$/', $indexName)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('SHOW INDEX FROM `' . $tableName . '` WHERE Key_name = :key_name');
        $stmt->execute(['key_name' => $indexName]);
        return (bool) $stmt->fetch();
    } catch (Throwable $exception) {
        return false;
    }
}

function ledger_add_column_if_missing(PDO $pdo, string $tableName, string $columnName, string $alterSql): bool
{
    $columns = ledger_table_columns_uncached($pdo, $tableName);
    if ($columns === []) {
        return false;
    }
    if (in_array($columnName, $columns, true)) {
        return true;
    }

    try {
        $pdo->exec($alterSql);
    } catch (Throwable $exception) {
        // Ignore concurrent DDL races.
    }

    return in_array($columnName, ledger_table_columns_uncached($pdo, $tableName), true);
}

function ledger_bootstrap_ready(bool $refresh = false): bool
{
    static $cached = null;

    if (!$refresh && $cached !== null) {
        return $cached;
    }

    $pdo = db();
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS chart_of_accounts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                code VARCHAR(40) NOT NULL,
                name VARCHAR(120) NOT NULL,
                type ENUM("ASSET","LIABILITY","EQUITY","REVENUE","EXPENSE") NOT NULL,
                parent_id BIGINT UNSIGNED NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_coa_company_code (company_id, code),
                KEY idx_coa_company_type_active (company_id, type, is_active),
                KEY idx_coa_parent (parent_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ledger_journals (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                reference_type VARCHAR(40) NOT NULL,
                reference_id BIGINT UNSIGNED NOT NULL,
                journal_date DATE NOT NULL,
                narration VARCHAR(255) NULL,
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reversed_journal_id BIGINT UNSIGNED NULL,
                KEY idx_ledger_journal_scope_date (company_id, journal_date, id),
                KEY idx_ledger_journal_reference (company_id, reference_type, reference_id),
                KEY idx_ledger_journal_reversed (reversed_journal_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ledger_entries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                journal_id BIGINT UNSIGNED NOT NULL,
                account_id BIGINT UNSIGNED NOT NULL,
                debit_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                credit_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                garage_id INT UNSIGNED NULL,
                party_type VARCHAR(20) NULL,
                party_id BIGINT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_ledger_entries_journal (journal_id),
                KEY idx_ledger_entries_account (account_id),
                KEY idx_ledger_entries_garage (garage_id),
                KEY idx_ledger_entries_party (party_type, party_id),
                KEY idx_ledger_entries_account_party (account_id, party_type, party_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        try {
            $pdo->exec(
                'ALTER TABLE ledger_entries
                 ADD CONSTRAINT fk_ledger_entries_journal
                 FOREIGN KEY (journal_id) REFERENCES ledger_journals(id)
                 ON DELETE RESTRICT'
            );
        } catch (Throwable $exception) {
            // Ignore duplicate constraint / incompatible existing structure.
        }
        try {
            $pdo->exec(
                'ALTER TABLE ledger_entries
                 ADD CONSTRAINT fk_ledger_entries_account
                 FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id)
                 ON DELETE RESTRICT'
            );
        } catch (Throwable $exception) {
            // Ignore duplicate constraint / incompatible existing structure.
        }
        try {
            $pdo->exec(
                'ALTER TABLE chart_of_accounts
                 ADD CONSTRAINT fk_coa_parent
                 FOREIGN KEY (parent_id) REFERENCES chart_of_accounts(id)
                 ON DELETE SET NULL'
            );
        } catch (Throwable $exception) {
            // Ignore duplicate constraint / incompatible existing structure.
        }

        ledger_add_column_if_missing(
            $pdo,
            'ledger_entries',
            'party_type',
            'ALTER TABLE ledger_entries ADD COLUMN party_type VARCHAR(20) NULL AFTER garage_id'
        );
        ledger_add_column_if_missing(
            $pdo,
            'ledger_entries',
            'party_id',
            'ALTER TABLE ledger_entries ADD COLUMN party_id BIGINT UNSIGNED NULL AFTER party_type'
        );

        if (!ledger_table_has_index($pdo, 'ledger_entries', 'idx_ledger_entries_party')) {
            try {
                $pdo->exec('ALTER TABLE ledger_entries ADD INDEX idx_ledger_entries_party (party_type, party_id)');
            } catch (Throwable $exception) {
                // Ignore duplicate index races.
            }
        }

        $cached = true;
    } catch (Throwable $exception) {
        $cached = false;
    }

    return $cached;
}

function ledger_default_coa_blueprint(): array
{
    return [
        ['code' => '1000', 'name' => 'Current Assets', 'type' => 'ASSET', 'parent_code' => null],
        ['code' => '1100', 'name' => 'Cash In Hand', 'type' => 'ASSET', 'parent_code' => '1000'],
        ['code' => '1110', 'name' => 'Bank Account', 'type' => 'ASSET', 'parent_code' => '1000'],
        ['code' => '1200', 'name' => 'Accounts Receivable', 'type' => 'ASSET', 'parent_code' => '1000'],
        ['code' => '1210', 'name' => 'Input GST - CGST', 'type' => 'ASSET', 'parent_code' => '1000'],
        ['code' => '1211', 'name' => 'Input GST - SGST', 'type' => 'ASSET', 'parent_code' => '1000'],
        ['code' => '1212', 'name' => 'Input GST - IGST', 'type' => 'ASSET', 'parent_code' => '1000'],
        ['code' => '1215', 'name' => 'Input GST', 'type' => 'ASSET', 'parent_code' => '1000'],
        ['code' => '1300', 'name' => 'Inventory', 'type' => 'ASSET', 'parent_code' => '1000'],

        ['code' => '2000', 'name' => 'Current Liabilities', 'type' => 'LIABILITY', 'parent_code' => null],
        ['code' => '2100', 'name' => 'Accounts Payable', 'type' => 'LIABILITY', 'parent_code' => '2000'],
        ['code' => '2200', 'name' => 'Output GST - CGST', 'type' => 'LIABILITY', 'parent_code' => '2000'],
        ['code' => '2201', 'name' => 'Output GST - SGST', 'type' => 'LIABILITY', 'parent_code' => '2000'],
        ['code' => '2202', 'name' => 'Output GST - IGST', 'type' => 'LIABILITY', 'parent_code' => '2000'],
        ['code' => '2205', 'name' => 'Output GST', 'type' => 'LIABILITY', 'parent_code' => '2000'],
        ['code' => '2300', 'name' => 'Customer Advance Liability', 'type' => 'LIABILITY', 'parent_code' => '2000'],
        ['code' => '2400', 'name' => 'Payroll Payable', 'type' => 'LIABILITY', 'parent_code' => '2000'],
        ['code' => '2500', 'name' => 'Expense Payable', 'type' => 'LIABILITY', 'parent_code' => '2000'],

        ['code' => '3000', 'name' => 'Equity', 'type' => 'EQUITY', 'parent_code' => null],
        ['code' => '3100', 'name' => 'Retained Earnings', 'type' => 'EQUITY', 'parent_code' => '3000'],

        ['code' => '4000', 'name' => 'Revenue', 'type' => 'REVENUE', 'parent_code' => null],
        ['code' => '4100', 'name' => 'Sales Revenue', 'type' => 'REVENUE', 'parent_code' => '4000'],
        ['code' => '4200', 'name' => 'Purchase Return Recovery', 'type' => 'REVENUE', 'parent_code' => '4000'],
        ['code' => '4300', 'name' => 'Other Income', 'type' => 'REVENUE', 'parent_code' => '4000'],

        ['code' => '5000', 'name' => 'Expenses', 'type' => 'EXPENSE', 'parent_code' => null],
        ['code' => '5100', 'name' => 'Operating Expense', 'type' => 'EXPENSE', 'parent_code' => '5000'],
        ['code' => '5110', 'name' => 'Salary Expense', 'type' => 'EXPENSE', 'parent_code' => '5000'],
        ['code' => '5120', 'name' => 'Outsourced Work Expense', 'type' => 'EXPENSE', 'parent_code' => '5000'],
        ['code' => '5130', 'name' => 'Sales Return', 'type' => 'EXPENSE', 'parent_code' => '5000'],
    ];
}

function ledger_ensure_default_coa(PDO $pdo, int $companyId): array
{
    static $cache = [];

    if ($companyId <= 0) {
        throw new RuntimeException('Invalid company scope for ledger accounts.');
    }
    if (isset($cache[$companyId])) {
        return $cache[$companyId];
    }

    if (!ledger_bootstrap_ready()) {
        throw new RuntimeException('Ledger schema is not ready.');
    }

    $blueprint = ledger_default_coa_blueprint();
    $insertStmt = $pdo->prepare(
        'INSERT INTO chart_of_accounts (company_id, code, name, type, parent_id, is_active)
         VALUES (:company_id, :code, :name, :type, :parent_id, 1)
         ON DUPLICATE KEY UPDATE
           name = VALUES(name),
           type = VALUES(type),
           is_active = 1'
    );
    $selectAllStmt = $pdo->prepare(
        'SELECT id, code, name, type, parent_id, is_active
         FROM chart_of_accounts
         WHERE company_id = :company_id'
    );
    $updateParentStmt = $pdo->prepare(
        'UPDATE chart_of_accounts
         SET parent_id = :parent_id
         WHERE company_id = :company_id
           AND code = :code'
    );

    foreach ($blueprint as $account) {
        $insertStmt->execute([
            'company_id' => $companyId,
            'code' => (string) $account['code'],
            'name' => (string) $account['name'],
            'type' => (string) $account['type'],
            'parent_id' => null,
        ]);
    }

    $selectAllStmt->execute(['company_id' => $companyId]);
    $rows = $selectAllStmt->fetchAll();
    $byCode = [];
    foreach ($rows as $row) {
        $code = (string) ($row['code'] ?? '');
        if ($code !== '') {
            $byCode[$code] = $row;
        }
    }

    foreach ($blueprint as $account) {
        $code = (string) $account['code'];
        $parentCode = $account['parent_code'];
        $parentId = null;
        if (is_string($parentCode) && isset($byCode[$parentCode])) {
            $parentId = (int) ($byCode[$parentCode]['id'] ?? 0);
        }

        $updateParentStmt->execute([
            'parent_id' => $parentId > 0 ? $parentId : null,
            'company_id' => $companyId,
            'code' => $code,
        ]);
    }

    $selectAllStmt->execute(['company_id' => $companyId]);
    $rows = $selectAllStmt->fetchAll();
    $mapped = [];
    foreach ($rows as $row) {
        $mapped[(string) ($row['code'] ?? '')] = [
            'id' => (int) ($row['id'] ?? 0),
            'company_id' => (int) ($row['company_id'] ?? $companyId),
            'code' => (string) ($row['code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'type' => (string) ($row['type'] ?? ''),
            'parent_id' => isset($row['parent_id']) ? (int) $row['parent_id'] : null,
            'is_active' => (int) ($row['is_active'] ?? 1) === 1,
        ];
    }

    $cache[$companyId] = $mapped;
    return $mapped;
}

function ledger_find_account_by_code(PDO $pdo, int $companyId, string $code): ?array
{
    $accounts = ledger_ensure_default_coa($pdo, $companyId);
    $needle = strtoupper(trim($code));
    if ($needle === '') {
        return null;
    }

    foreach ($accounts as $acctCode => $account) {
        if (strtoupper((string) $acctCode) === $needle) {
            return $account;
        }
    }

    return null;
}

function ledger_fetch_account_row(PDO $pdo, int $accountId): ?array
{
    if ($accountId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, company_id, code, name, type, parent_id, is_active
         FROM chart_of_accounts
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $accountId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function ledger_normalize_party_type(?string $partyType): ?string
{
    $value = strtoupper(trim((string) $partyType));
    if ($value === '') {
        return null;
    }

    return in_array($value, ['CUSTOMER', 'VENDOR', 'EMPLOYEE'], true) ? $value : null;
}

function ledger_payment_mode_account_code(?string $paymentMode): string
{
    $mode = strtoupper(trim((string) $paymentMode));
    if ($mode === 'CASH') {
        return '1100';
    }

    // UPI/CARD/BANK_TRANSFER/CHEQUE/MIXED/ADJUSTMENT default to bank.
    return '1110';
}

function ledger_create_journal(PDO $pdo, array $journal, array $lines): int
{
    if (!ledger_bootstrap_ready()) {
        throw new RuntimeException('Ledger schema is not ready.');
    }

    $companyId = (int) ($journal['company_id'] ?? 0);
    $referenceType = mb_substr(strtoupper(trim((string) ($journal['reference_type'] ?? ''))), 0, 40);
    $referenceId = (int) ($journal['reference_id'] ?? 0);
    $journalDate = (string) ($journal['journal_date'] ?? date('Y-m-d'));
    $narration = trim((string) ($journal['narration'] ?? ''));
    $createdBy = (int) ($journal['created_by'] ?? 0);
    $reversedJournalId = (int) ($journal['reversed_journal_id'] ?? 0);

    if ($companyId <= 0) {
        throw new RuntimeException('Ledger journal company scope is required.');
    }
    if ($referenceType === '' || $referenceId <= 0) {
        throw new RuntimeException('Ledger journal reference_type/reference_id is required.');
    }
    if (!ledger_is_valid_date($journalDate)) {
        throw new RuntimeException('Invalid journal date for ledger posting.');
    }
    if ($lines === []) {
        throw new RuntimeException('Ledger journal requires at least one entry line.');
    }

    $insertableLines = [];
    $debitTotal = 0.0;
    $creditTotal = 0.0;
    ledger_ensure_default_coa($pdo, $companyId);

    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }

        $accountId = (int) ($line['account_id'] ?? 0);
        $accountCode = trim((string) ($line['account_code'] ?? ''));
        if ($accountId <= 0 && $accountCode !== '') {
            $account = ledger_find_account_by_code($pdo, $companyId, $accountCode);
            $accountId = (int) ($account['id'] ?? 0);
        }
        if ($accountId <= 0) {
            throw new RuntimeException('Ledger posting account is missing or invalid.');
        }

        $accountRow = ledger_fetch_account_row($pdo, $accountId);
        if (!$accountRow) {
            throw new RuntimeException('Ledger posting account not found.');
        }
        if ((int) ($accountRow['company_id'] ?? 0) !== $companyId) {
            throw new RuntimeException('Ledger posting account is outside company scope.');
        }
        if ((int) ($accountRow['is_active'] ?? 1) !== 1) {
            throw new RuntimeException('Ledger posting account is inactive.');
        }

        $debitAmount = ledger_round(max(0.0, (float) ($line['debit_amount'] ?? 0)));
        $creditAmount = ledger_round(max(0.0, (float) ($line['credit_amount'] ?? 0)));
        if ($debitAmount <= 0.0 && $creditAmount <= 0.0) {
            continue;
        }
        if ($debitAmount > 0.0 && $creditAmount > 0.0) {
            throw new RuntimeException('Ledger entry line cannot contain both debit and credit.');
        }

        $garageId = isset($line['garage_id']) ? (int) $line['garage_id'] : (isset($journal['garage_id']) ? (int) $journal['garage_id'] : 0);
        $partyType = ledger_normalize_party_type($line['party_type'] ?? null);
        $partyId = $partyType !== null ? (int) ($line['party_id'] ?? 0) : 0;

        $insertableLines[] = [
            'account_id' => $accountId,
            'debit_amount' => $debitAmount,
            'credit_amount' => $creditAmount,
            'garage_id' => $garageId > 0 ? $garageId : null,
            'party_type' => $partyType,
            'party_id' => $partyType !== null && $partyId > 0 ? $partyId : null,
        ];

        $debitTotal = ledger_round($debitTotal + $debitAmount);
        $creditTotal = ledger_round($creditTotal + $creditAmount);
    }

    if ($insertableLines === []) {
        throw new RuntimeException('Ledger journal has no valid debit/credit lines.');
    }
    if (abs($debitTotal - $creditTotal) > 0.009) {
        throw new RuntimeException('Unbalanced ledger journal (debit != credit).');
    }

    $ownsTx = !$pdo->inTransaction();
    if ($ownsTx) {
        $pdo->beginTransaction();
    }

    try {
        $journalInsert = $pdo->prepare(
            'INSERT INTO ledger_journals
              (company_id, reference_type, reference_id, journal_date, narration, created_by, reversed_journal_id)
             VALUES
              (:company_id, :reference_type, :reference_id, :journal_date, :narration, :created_by, :reversed_journal_id)'
        );
        $journalInsert->execute([
            'company_id' => $companyId,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'journal_date' => $journalDate,
            'narration' => $narration !== '' ? mb_substr($narration, 0, 255) : null,
            'created_by' => $createdBy > 0 ? $createdBy : null,
            'reversed_journal_id' => $reversedJournalId > 0 ? $reversedJournalId : null,
        ]);
        $journalId = (int) $pdo->lastInsertId();

        $entryInsert = $pdo->prepare(
            'INSERT INTO ledger_entries
              (journal_id, account_id, debit_amount, credit_amount, garage_id, party_type, party_id)
             VALUES
              (:journal_id, :account_id, :debit_amount, :credit_amount, :garage_id, :party_type, :party_id)'
        );
        foreach ($insertableLines as $line) {
            $entryInsert->execute([
                'journal_id' => $journalId,
                'account_id' => $line['account_id'],
                'debit_amount' => $line['debit_amount'],
                'credit_amount' => $line['credit_amount'],
                'garage_id' => $line['garage_id'],
                'party_type' => $line['party_type'],
                'party_id' => $line['party_id'],
            ]);
        }

        if ($ownsTx) {
            $pdo->commit();
        }

        return $journalId;
    } catch (Throwable $exception) {
        if ($ownsTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function ledger_fetch_journal(PDO $pdo, int $companyId, int $journalId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT *
         FROM ledger_journals
         WHERE id = :id
           AND company_id = :company_id
         LIMIT 1'
    );
    $stmt->execute([
        'id' => $journalId,
        'company_id' => $companyId,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function ledger_find_latest_journal_by_reference(PDO $pdo, int $companyId, string $referenceType, int $referenceId): ?array
{
    if ($companyId <= 0 || $referenceId <= 0) {
        return null;
    }

    $referenceType = mb_substr(strtoupper(trim($referenceType)), 0, 40);
    if ($referenceType === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT lj.*
         FROM ledger_journals lj
         WHERE lj.company_id = :company_id
           AND lj.reference_type = :reference_type
           AND lj.reference_id = :reference_id
         ORDER BY lj.id DESC
         LIMIT 1'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'reference_type' => $referenceType,
        'reference_id' => $referenceId,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function ledger_fetch_unreversed_journals_by_reference(PDO $pdo, int $companyId, string $referenceType, int $referenceId): array
{
    if ($companyId <= 0 || $referenceId <= 0) {
        return [];
    }

    $referenceType = mb_substr(strtoupper(trim($referenceType)), 0, 40);
    if ($referenceType === '') {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT lj.*
         FROM ledger_journals lj
         LEFT JOIN ledger_journals rev ON rev.reversed_journal_id = lj.id
         WHERE lj.company_id = :company_id
           AND lj.reference_type = :reference_type
           AND lj.reference_id = :reference_id
           AND rev.id IS NULL
         ORDER BY lj.id ASC'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'reference_type' => $referenceType,
        'reference_id' => $referenceId,
    ]);

    return $stmt->fetchAll();
}

function ledger_fetch_journal_entries(PDO $pdo, int $journalId): array
{
    $stmt = $pdo->prepare(
        'SELECT *
         FROM ledger_entries
         WHERE journal_id = :journal_id
         ORDER BY id ASC'
    );
    $stmt->execute(['journal_id' => $journalId]);
    return $stmt->fetchAll();
}

function ledger_reverse_journal(
    PDO $pdo,
    int $companyId,
    int $journalId,
    string $reversalReferenceType,
    int $reversalReferenceId,
    ?string $journalDate = null,
    ?string $narration = null,
    ?int $createdBy = null
): ?int {
    if (!ledger_bootstrap_ready()) {
        throw new RuntimeException('Ledger schema is not ready.');
    }
    if ($journalId <= 0 || $companyId <= 0) {
        return null;
    }

    $original = ledger_fetch_journal($pdo, $companyId, $journalId);
    if (!$original) {
        return null;
    }

    $existsStmt = $pdo->prepare(
        'SELECT id
         FROM ledger_journals
         WHERE reversed_journal_id = :journal_id
         LIMIT 1'
    );
    $existsStmt->execute(['journal_id' => $journalId]);
    if ($existsStmt->fetch()) {
        return null;
    }

    $entries = ledger_fetch_journal_entries($pdo, $journalId);
    if ($entries === []) {
        throw new RuntimeException('Cannot reverse ledger journal without entry lines.');
    }

    $reversalLines = [];
    foreach ($entries as $entry) {
        $reversalLines[] = [
            'account_id' => (int) ($entry['account_id'] ?? 0),
            'debit_amount' => (float) ($entry['credit_amount'] ?? 0),
            'credit_amount' => (float) ($entry['debit_amount'] ?? 0),
            'garage_id' => isset($entry['garage_id']) ? (int) $entry['garage_id'] : null,
            'party_type' => $entry['party_type'] ?? null,
            'party_id' => isset($entry['party_id']) ? (int) $entry['party_id'] : null,
        ];
    }

    $dateValue = $journalDate;
    if (!ledger_is_valid_date($dateValue)) {
        $dateValue = date('Y-m-d');
    }

    $baseNarration = trim((string) ($original['narration'] ?? ''));
    $narrationText = trim((string) $narration);
    if ($narrationText === '') {
        $narrationText = 'Ledger reversal of journal #' . $journalId;
        if ($baseNarration !== '') {
            $narrationText .= ' | ' . $baseNarration;
        }
    }

    return ledger_create_journal($pdo, [
        'company_id' => $companyId,
        'reference_type' => $reversalReferenceType,
        'reference_id' => $reversalReferenceId,
        'journal_date' => $dateValue,
        'narration' => $narrationText,
        'created_by' => $createdBy ?? null,
        'reversed_journal_id' => $journalId,
    ], $reversalLines);
}

function ledger_reverse_reference(
    PDO $pdo,
    int $companyId,
    string $originalReferenceType,
    int $originalReferenceId,
    string $reversalReferenceType,
    int $reversalReferenceId,
    ?string $journalDate = null,
    ?string $narration = null,
    ?int $createdBy = null,
    bool $allowMissing = true
): ?int {
    $journals = ledger_fetch_unreversed_journals_by_reference($pdo, $companyId, $originalReferenceType, $originalReferenceId);
    if ($journals === []) {
        if ($allowMissing) {
            return null;
        }
        throw new RuntimeException('Original ledger journal not found for reversal.');
    }

    $lastReversalId = null;
    foreach ($journals as $journal) {
        $lastReversalId = ledger_reverse_journal(
            $pdo,
            $companyId,
            (int) ($journal['id'] ?? 0),
            $reversalReferenceType,
            $reversalReferenceId,
            $journalDate,
            $narration,
            $createdBy
        ) ?? $lastReversalId;
    }

    return $lastReversalId;
}

function ledger_reverse_all_reference_types(
    PDO $pdo,
    int $companyId,
    array $referencePairs,
    string $reversalReferenceType,
    int $reversalReferenceId,
    ?string $journalDate = null,
    ?string $narration = null,
    ?int $createdBy = null
): array {
    $reversalIds = [];
    foreach ($referencePairs as $pair) {
        if (!is_array($pair)) {
            continue;
        }
        $origType = (string) ($pair['reference_type'] ?? '');
        $origId = (int) ($pair['reference_id'] ?? 0);
        if ($origType === '' || $origId <= 0) {
            continue;
        }

        $reversalId = ledger_reverse_reference(
            $pdo,
            $companyId,
            $origType,
            $origId,
            $reversalReferenceType,
            $reversalReferenceId,
            $journalDate,
            $narration,
            $createdBy,
            true
        );
        if ($reversalId !== null) {
            $reversalIds[] = (int) $reversalId;
        }
    }

    return $reversalIds;
}

function ledger_invoice_output_gst_lines(array $invoice, int $garageId, int $customerId): array
{
    $lines = [];
    $cgst = ledger_round((float) ($invoice['cgst_amount'] ?? 0));
    $sgst = ledger_round((float) ($invoice['sgst_amount'] ?? 0));
    $igst = ledger_round((float) ($invoice['igst_amount'] ?? 0));
    $totalTax = ledger_round((float) ($invoice['total_tax_amount'] ?? 0));
    $splitTotal = ledger_round($cgst + $sgst + $igst);

    foreach ([
        ['code' => '2200', 'amount' => $cgst],
        ['code' => '2201', 'amount' => $sgst],
        ['code' => '2202', 'amount' => $igst],
    ] as $row) {
        $amount = ledger_round((float) ($row['amount'] ?? 0));
        if ($amount <= 0.0) {
            continue;
        }
        $lines[] = [
            'account_code' => (string) $row['code'],
            'debit_amount' => 0.0,
            'credit_amount' => $amount,
            'garage_id' => $garageId > 0 ? $garageId : null,
            'party_type' => $customerId > 0 ? 'CUSTOMER' : null,
            'party_id' => $customerId > 0 ? $customerId : null,
        ];
    }

    if ($lines === [] && $totalTax > 0.0) {
        $lines[] = [
            'account_code' => '2205',
            'debit_amount' => 0.0,
            'credit_amount' => $totalTax,
            'garage_id' => $garageId > 0 ? $garageId : null,
            'party_type' => $customerId > 0 ? 'CUSTOMER' : null,
            'party_id' => $customerId > 0 ? $customerId : null,
        ];
    } elseif ($lines !== [] && abs($splitTotal - $totalTax) > 0.009 && $totalTax > 0.0) {
        $delta = ledger_round($totalTax - $splitTotal);
        if ($delta > 0.0) {
            $lines[] = [
                'account_code' => '2205',
                'debit_amount' => 0.0,
                'credit_amount' => $delta,
                'garage_id' => $garageId > 0 ? $garageId : null,
                'party_type' => $customerId > 0 ? 'CUSTOMER' : null,
                'party_id' => $customerId > 0 ? $customerId : null,
            ];
        }
    }

    return $lines;
}

function ledger_post_purchase_finalized(PDO $pdo, array $purchase, ?int $createdBy = null): ?int
{
    $purchaseId = (int) ($purchase['id'] ?? 0);
    $companyId = (int) ($purchase['company_id'] ?? 0);
    $garageId = (int) ($purchase['garage_id'] ?? 0);
    $vendorId = (int) ($purchase['vendor_id'] ?? 0);
    $purchaseDate = (string) ($purchase['purchase_date'] ?? date('Y-m-d'));
    $taxable = ledger_round((float) ($purchase['taxable_amount'] ?? 0));
    $gst = ledger_round((float) ($purchase['gst_amount'] ?? 0));
    $grand = ledger_round((float) ($purchase['grand_total'] ?? 0));
    $invoiceNumber = trim((string) ($purchase['invoice_number'] ?? ''));

    if ($purchaseId <= 0 || $companyId <= 0 || $garageId <= 0 || $grand <= 0.0) {
        return null;
    }

    $existing = ledger_find_latest_journal_by_reference($pdo, $companyId, 'PURCHASE_FINALIZE', $purchaseId);
    if ($existing) {
        return (int) ($existing['id'] ?? 0);
    }

    $lines = [];
    if ($taxable > 0.0) {
        $lines[] = [
            'account_code' => '1300',
            'debit_amount' => $taxable,
            'credit_amount' => 0.0,
            'garage_id' => $garageId,
        ];
    }
    if ($gst > 0.0) {
        $lines[] = [
            'account_code' => '1215',
            'debit_amount' => $gst,
            'credit_amount' => 0.0,
            'garage_id' => $garageId,
            'party_type' => $vendorId > 0 ? 'VENDOR' : null,
            'party_id' => $vendorId > 0 ? $vendorId : null,
        ];
    }
    $lines[] = [
        'account_code' => '2100',
        'debit_amount' => 0.0,
        'credit_amount' => $grand,
        'garage_id' => $garageId,
        'party_type' => $vendorId > 0 ? 'VENDOR' : null,
        'party_id' => $vendorId > 0 ? $vendorId : null,
    ];

    return ledger_create_journal($pdo, [
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'reference_type' => 'PURCHASE_FINALIZE',
        'reference_id' => $purchaseId,
        'journal_date' => ledger_is_valid_date($purchaseDate) ? $purchaseDate : date('Y-m-d'),
        'narration' => 'Purchase finalized #' . $purchaseId . ($invoiceNumber !== '' ? ' (' . $invoiceNumber . ')' : ''),
        'created_by' => $createdBy,
    ], $lines);
}

function ledger_post_invoice_finalized(PDO $pdo, array $invoice, ?int $createdBy = null): ?int
{
    $invoiceId = (int) ($invoice['id'] ?? 0);
    $companyId = (int) ($invoice['company_id'] ?? 0);
    $garageId = (int) ($invoice['garage_id'] ?? 0);
    $customerId = (int) ($invoice['customer_id'] ?? 0);
    $invoiceDate = (string) ($invoice['invoice_date'] ?? date('Y-m-d'));
    $invoiceNumber = trim((string) ($invoice['invoice_number'] ?? ''));
    $taxable = ledger_round((float) ($invoice['taxable_amount'] ?? 0));
    $grand = ledger_round((float) ($invoice['grand_total'] ?? 0));

    if ($invoiceId <= 0 || $companyId <= 0 || $garageId <= 0 || $grand <= 0.0) {
        return null;
    }

    $existing = ledger_find_latest_journal_by_reference($pdo, $companyId, 'INVOICE_FINALIZE', $invoiceId);
    if ($existing) {
        return (int) ($existing['id'] ?? 0);
    }

    $lines = [[
        'account_code' => '1200',
        'debit_amount' => $grand,
        'credit_amount' => 0.0,
        'garage_id' => $garageId,
        'party_type' => $customerId > 0 ? 'CUSTOMER' : null,
        'party_id' => $customerId > 0 ? $customerId : null,
    ]];

    if ($taxable > 0.0) {
        $lines[] = [
            'account_code' => '4100',
            'debit_amount' => 0.0,
            'credit_amount' => $taxable,
            'garage_id' => $garageId,
            'party_type' => $customerId > 0 ? 'CUSTOMER' : null,
            'party_id' => $customerId > 0 ? $customerId : null,
        ];
    }

    $lines = array_merge($lines, ledger_invoice_output_gst_lines($invoice, $garageId, $customerId));

    return ledger_create_journal($pdo, [
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'reference_type' => 'INVOICE_FINALIZE',
        'reference_id' => $invoiceId,
        'journal_date' => ledger_is_valid_date($invoiceDate) ? $invoiceDate : date('Y-m-d'),
        'narration' => 'Invoice finalized ' . ($invoiceNumber !== '' ? $invoiceNumber : ('#' . $invoiceId)),
        'created_by' => $createdBy,
    ], $lines);
}

function ledger_post_customer_payment(PDO $pdo, array $invoice, array $payment, ?int $createdBy = null): ?int
{
    $paymentId = (int) ($payment['id'] ?? 0);
    $companyId = (int) ($invoice['company_id'] ?? 0);
    $garageId = (int) ($invoice['garage_id'] ?? 0);
    $invoiceId = (int) ($invoice['id'] ?? ($payment['invoice_id'] ?? 0));
    $customerId = (int) ($invoice['customer_id'] ?? 0);
    $amount = ledger_round((float) ($payment['amount'] ?? 0));
    $paidOn = (string) ($payment['paid_on'] ?? date('Y-m-d'));
    $paymentMode = (string) ($payment['payment_mode'] ?? 'CASH');
    $invoiceNumber = trim((string) ($invoice['invoice_number'] ?? ''));

    if ($paymentId <= 0 || $companyId <= 0 || $garageId <= 0 || $invoiceId <= 0 || $amount <= 0.0) {
        return null;
    }

    $existing = ledger_find_latest_journal_by_reference($pdo, $companyId, 'INVOICE_PAYMENT', $paymentId);
    if ($existing) {
        return (int) ($existing['id'] ?? 0);
    }

    $counterpartyAccount = ledger_payment_mode_account_code($paymentMode);
    return ledger_create_journal($pdo, [
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'reference_type' => 'INVOICE_PAYMENT',
        'reference_id' => $paymentId,
        'journal_date' => ledger_is_valid_date($paidOn) ? $paidOn : date('Y-m-d'),
        'narration' => 'Customer payment for invoice ' . ($invoiceNumber !== '' ? $invoiceNumber : ('#' . $invoiceId)),
        'created_by' => $createdBy,
    ], [
        [
            'account_code' => $counterpartyAccount,
            'debit_amount' => $amount,
            'credit_amount' => 0.0,
            'garage_id' => $garageId,
        ],
        [
            'account_code' => '1200',
            'debit_amount' => 0.0,
            'credit_amount' => $amount,
            'garage_id' => $garageId,
            'party_type' => $customerId > 0 ? 'CUSTOMER' : null,
            'party_id' => $customerId > 0 ? $customerId : null,
        ],
    ]);
}

function ledger_post_vendor_payment(PDO $pdo, array $purchase, array $payment, ?int $createdBy = null): ?int
{
    $paymentId = (int) ($payment['id'] ?? 0);
    $companyId = (int) ($purchase['company_id'] ?? 0);
    $garageId = (int) ($purchase['garage_id'] ?? 0);
    $vendorId = (int) ($purchase['vendor_id'] ?? 0);
    $purchaseId = (int) ($purchase['id'] ?? ($payment['purchase_id'] ?? 0));
    $amount = ledger_round((float) ($payment['amount'] ?? 0));
    $paymentDate = (string) ($payment['payment_date'] ?? date('Y-m-d'));
    $paymentMode = (string) ($payment['payment_mode'] ?? 'BANK_TRANSFER');
    $invoiceNumber = trim((string) ($purchase['invoice_number'] ?? ''));

    if ($paymentId <= 0 || $companyId <= 0 || $garageId <= 0 || $purchaseId <= 0 || $amount <= 0.0) {
        return null;
    }

    $existing = ledger_find_latest_journal_by_reference($pdo, $companyId, 'PURCHASE_PAYMENT', $paymentId);
    if ($existing) {
        return (int) ($existing['id'] ?? 0);
    }

    $counterpartyAccount = ledger_payment_mode_account_code($paymentMode);
    return ledger_create_journal($pdo, [
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'reference_type' => 'PURCHASE_PAYMENT',
        'reference_id' => $paymentId,
        'journal_date' => ledger_is_valid_date($paymentDate) ? $paymentDate : date('Y-m-d'),
        'narration' => 'Vendor payment for purchase ' . ($invoiceNumber !== '' ? $invoiceNumber : ('#' . $purchaseId)),
        'created_by' => $createdBy,
    ], [
        [
            'account_code' => '2100',
            'debit_amount' => $amount,
            'credit_amount' => 0.0,
            'garage_id' => $garageId,
            'party_type' => $vendorId > 0 ? 'VENDOR' : null,
            'party_id' => $vendorId > 0 ? $vendorId : null,
        ],
        [
            'account_code' => $counterpartyAccount,
            'debit_amount' => 0.0,
            'credit_amount' => $amount,
            'garage_id' => $garageId,
        ],
    ]);
}

function ledger_post_advance_received(PDO $pdo, array $advance, ?int $createdBy = null): ?int
{
    $advanceId = (int) ($advance['id'] ?? 0);
    $companyId = (int) ($advance['company_id'] ?? 0);
    $garageId = (int) ($advance['garage_id'] ?? 0);
    $customerId = (int) ($advance['customer_id'] ?? 0);
    $amount = ledger_round((float) ($advance['advance_amount'] ?? 0));
    $receivedOn = (string) ($advance['received_on'] ?? date('Y-m-d'));
    $paymentMode = (string) ($advance['payment_mode'] ?? 'CASH');
    $receiptNumber = trim((string) ($advance['receipt_number'] ?? ''));

    if ($advanceId <= 0 || $companyId <= 0 || $garageId <= 0 || $amount <= 0.0) {
        return null;
    }

    $existing = ledger_find_latest_journal_by_reference($pdo, $companyId, 'ADVANCE_RECEIVED', $advanceId);
    if ($existing) {
        return (int) ($existing['id'] ?? 0);
    }

    $counterpartyAccount = ledger_payment_mode_account_code($paymentMode);
    return ledger_create_journal($pdo, [
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'reference_type' => 'ADVANCE_RECEIVED',
        'reference_id' => $advanceId,
        'journal_date' => ledger_is_valid_date($receivedOn) ? $receivedOn : date('Y-m-d'),
        'narration' => 'Customer advance received ' . ($receiptNumber !== '' ? $receiptNumber : ('#' . $advanceId)),
        'created_by' => $createdBy,
    ], [
        [
            'account_code' => $counterpartyAccount,
            'debit_amount' => $amount,
            'credit_amount' => 0.0,
            'garage_id' => $garageId,
        ],
        [
            'account_code' => '2300',
            'debit_amount' => 0.0,
            'credit_amount' => $amount,
            'garage_id' => $garageId,
            'party_type' => $customerId > 0 ? 'CUSTOMER' : null,
            'party_id' => $customerId > 0 ? $customerId : null,
        ],
    ]);
}

function ledger_post_advance_adjustment(PDO $pdo, array $adjustment, array $invoice, ?int $createdBy = null): ?int
{
    $adjustmentId = (int) ($adjustment['id'] ?? 0);
    $companyId = (int) ($adjustment['company_id'] ?? ($invoice['company_id'] ?? 0));
    $garageId = (int) ($adjustment['garage_id'] ?? ($invoice['garage_id'] ?? 0));
    $customerId = (int) ($invoice['customer_id'] ?? 0);
    $amount = ledger_round((float) ($adjustment['adjusted_amount'] ?? 0));
    $adjustedOn = (string) ($adjustment['adjusted_on'] ?? ($invoice['invoice_date'] ?? date('Y-m-d')));
    $invoiceId = (int) ($invoice['id'] ?? ($adjustment['invoice_id'] ?? 0));

    if ($adjustmentId <= 0 || $companyId <= 0 || $garageId <= 0 || $invoiceId <= 0 || $amount <= 0.0) {
        return null;
    }

    $existing = ledger_find_latest_journal_by_reference($pdo, $companyId, 'ADVANCE_ADJUSTMENT', $adjustmentId);
    if ($existing) {
        return (int) ($existing['id'] ?? 0);
    }

    return ledger_create_journal($pdo, [
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'reference_type' => 'ADVANCE_ADJUSTMENT',
        'reference_id' => $adjustmentId,
        'journal_date' => ledger_is_valid_date($adjustedOn) ? $adjustedOn : date('Y-m-d'),
        'narration' => 'Advance adjusted against invoice #' . $invoiceId,
        'created_by' => $createdBy,
    ], [
        [
            'account_code' => '2300',
            'debit_amount' => $amount,
            'credit_amount' => 0.0,
            'garage_id' => $garageId,
            'party_type' => $customerId > 0 ? 'CUSTOMER' : null,
            'party_id' => $customerId > 0 ? $customerId : null,
        ],
        [
            'account_code' => '1200',
            'debit_amount' => 0.0,
            'credit_amount' => $amount,
            'garage_id' => $garageId,
            'party_type' => $customerId > 0 ? 'CUSTOMER' : null,
            'party_id' => $customerId > 0 ? $customerId : null,
        ],
    ]);
}

function ledger_post_customer_return_approved(PDO $pdo, array $returnRow, ?int $createdBy = null): ?int
{
    $returnId = (int) ($returnRow['id'] ?? 0);
    $companyId = (int) ($returnRow['company_id'] ?? 0);
    $garageId = (int) ($returnRow['garage_id'] ?? 0);
    $customerId = (int) ($returnRow['customer_id'] ?? 0);
    $returnType = strtoupper(trim((string) ($returnRow['return_type'] ?? '')));
    $returnDate = (string) ($returnRow['return_date'] ?? date('Y-m-d'));
    $returnNumber = trim((string) ($returnRow['return_number'] ?? ''));
    $taxable = ledger_round((float) ($returnRow['taxable_amount'] ?? 0));
    $tax = ledger_round((float) ($returnRow['tax_amount'] ?? 0));
    $total = ledger_round((float) ($returnRow['total_amount'] ?? 0));

    if ($returnType !== 'CUSTOMER_RETURN' || $returnId <= 0 || $companyId <= 0 || $garageId <= 0 || $total <= 0.0) {
        return null;
    }

    $existing = ledger_find_latest_journal_by_reference($pdo, $companyId, 'CUSTOMER_RETURN_APPROVAL', $returnId);
    if ($existing) {
        return (int) ($existing['id'] ?? 0);
    }

    $lines = [];
    if ($taxable > 0.0) {
        $lines[] = [
            'account_code' => '5130',
            'debit_amount' => $taxable,
            'credit_amount' => 0.0,
            'garage_id' => $garageId,
            'party_type' => $customerId > 0 ? 'CUSTOMER' : null,
            'party_id' => $customerId > 0 ? $customerId : null,
        ];
    }
    if ($tax > 0.0) {
        $lines[] = [
            'account_code' => '2205',
            'debit_amount' => $tax,
            'credit_amount' => 0.0,
            'garage_id' => $garageId,
            'party_type' => $customerId > 0 ? 'CUSTOMER' : null,
            'party_id' => $customerId > 0 ? $customerId : null,
        ];
    }
    $lines[] = [
        'account_code' => '1200',
        'debit_amount' => 0.0,
        'credit_amount' => $total,
        'garage_id' => $garageId,
        'party_type' => $customerId > 0 ? 'CUSTOMER' : null,
        'party_id' => $customerId > 0 ? $customerId : null,
    ];

    return ledger_create_journal($pdo, [
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'reference_type' => 'CUSTOMER_RETURN_APPROVAL',
        'reference_id' => $returnId,
        'journal_date' => ledger_is_valid_date($returnDate) ? $returnDate : date('Y-m-d'),
        'narration' => 'Customer return approved ' . ($returnNumber !== '' ? $returnNumber : ('#' . $returnId)),
        'created_by' => $createdBy,
    ], $lines);
}

function ledger_finance_source_skips_gl(?string $sourceType): bool
{
    $source = strtoupper(trim((string) $sourceType));
    if ($source === '') {
        return false;
    }

    return in_array($source, ['PURCHASE_PAYMENT', 'PURCHASE_PAYMENT_REV'], true);
}

function ledger_finance_expense_account_code(array $expenseRow, ?string $categoryName = null): string
{
    $sourceType = strtoupper(trim((string) ($expenseRow['source_type'] ?? '')));
    $category = strtoupper(trim((string) $categoryName));

    if (str_starts_with($sourceType, 'PAYROLL_PAYMENT')) {
        return '5110';
    }
    if (str_starts_with($sourceType, 'OUTSOURCED_PAYMENT')) {
        return '5120';
    }
    if (str_starts_with($sourceType, 'RETURN_SETTLEMENT_RECEIVE')) {
        return '4200';
    }
    if (str_starts_with($sourceType, 'RETURN_SETTLEMENT_PAY')) {
        return '5130';
    }
    if (str_contains($category, 'SALARY')) {
        return '5110';
    }
    if (str_contains($category, 'OUTSOURCE')) {
        return '5120';
    }

    return '5100';
}

function ledger_post_finance_expense_entry(
    PDO $pdo,
    array $expenseRow,
    ?string $categoryName = null,
    ?int $createdBy = null,
    ?string $referenceTypeOverride = null
): ?int
{
    $expenseId = (int) ($expenseRow['id'] ?? 0);
    $companyId = (int) ($expenseRow['company_id'] ?? 0);
    $garageId = (int) ($expenseRow['garage_id'] ?? 0);
    $sourceType = (string) ($expenseRow['source_type'] ?? '');

    if (ledger_finance_source_skips_gl($sourceType)) {
        return null;
    }

    $amountSigned = ledger_round((float) ($expenseRow['amount'] ?? 0));
    if ($expenseId <= 0 || $companyId <= 0 || abs($amountSigned) <= 0.009) {
        return null;
    }

    $entryType = strtoupper(trim((string) ($expenseRow['entry_type'] ?? 'EXPENSE')));
    $referenceType = mb_substr(strtoupper(trim((string) $referenceTypeOverride)), 0, 40);
    if ($referenceType === '') {
        $referenceType = 'EXPENSE';
        if (str_starts_with(strtoupper($sourceType), 'PAYROLL_')) {
            $referenceType = 'PAYROLL_EXPENSE';
        } elseif (str_starts_with(strtoupper($sourceType), 'OUTSOURCED_')) {
            $referenceType = 'OUTSOURCE_EXPENSE';
        } elseif (str_starts_with(strtoupper($sourceType), 'RETURN_SETTLEMENT_')) {
            $referenceType = 'RETURN_SETTLEMENT';
        } elseif ($entryType === 'REVERSAL') {
            $referenceType = 'EXPENSE_REVERSAL';
        }
    }

    $existing = ledger_find_latest_journal_by_reference($pdo, $companyId, $referenceType, $expenseId);
    if ($existing) {
        return (int) ($existing['id'] ?? 0);
    }

    $expenseDate = (string) ($expenseRow['expense_date'] ?? date('Y-m-d'));
    $paymentMode = (string) ($expenseRow['payment_mode'] ?? 'CASH');
    $counterpartyAccount = ledger_payment_mode_account_code($paymentMode);
    $expenseAccount = ledger_finance_expense_account_code($expenseRow, $categoryName);
    $absAmount = ledger_round(abs($amountSigned));
    $narration = trim((string) ($expenseRow['notes'] ?? ''));
    if ($narration === '') {
        $narration = 'Expense posting #' . $expenseId;
    }

    $lines = [];
    if ($amountSigned > 0) {
        $lines[] = [
            'account_code' => $expenseAccount,
            'debit_amount' => $absAmount,
            'credit_amount' => 0.0,
            'garage_id' => $garageId > 0 ? $garageId : null,
        ];
        $lines[] = [
            'account_code' => $counterpartyAccount,
            'debit_amount' => 0.0,
            'credit_amount' => $absAmount,
            'garage_id' => $garageId > 0 ? $garageId : null,
        ];
    } else {
        $lines[] = [
            'account_code' => $counterpartyAccount,
            'debit_amount' => $absAmount,
            'credit_amount' => 0.0,
            'garage_id' => $garageId > 0 ? $garageId : null,
        ];
        $lines[] = [
            'account_code' => $expenseAccount,
            'debit_amount' => 0.0,
            'credit_amount' => $absAmount,
            'garage_id' => $garageId > 0 ? $garageId : null,
        ];
    }

    return ledger_create_journal($pdo, [
        'company_id' => $companyId,
        'garage_id' => $garageId > 0 ? $garageId : null,
        'reference_type' => $referenceType,
        'reference_id' => $expenseId,
        'journal_date' => ledger_is_valid_date($expenseDate) ? $expenseDate : date('Y-m-d'),
        'narration' => $narration,
        'created_by' => $createdBy,
    ], $lines);
}
