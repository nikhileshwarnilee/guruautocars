<?php
declare(strict_types=1);

function job_insurance_add_column_if_missing(PDO $pdo, string $columnName, string $alterSql): bool
{
    $columns = table_columns('job_cards');
    if ($columns === [] || in_array($columnName, $columns, true)) {
        return in_array($columnName, $columns, true);
    }

    try {
        $pdo->exec($alterSql);
    } catch (Throwable $exception) {
        // Ignore race on ALTER TABLE.
    }

    return in_array($columnName, table_columns('job_cards'), true);
}

function job_insurance_feature_ready(bool $refresh = false): bool
{
    static $cached = null;
    if (!$refresh && $cached !== null) {
        return $cached;
    }

    $pdo = db();
    try {
        $requiredColumns = [
            'insurance_company_name' => 'ALTER TABLE job_cards ADD COLUMN insurance_company_name VARCHAR(150) NULL',
            'insurance_claim_number' => 'ALTER TABLE job_cards ADD COLUMN insurance_claim_number VARCHAR(80) NULL',
            'insurance_surveyor_name' => 'ALTER TABLE job_cards ADD COLUMN insurance_surveyor_name VARCHAR(120) NULL',
            'insurance_claim_amount_approved' => 'ALTER TABLE job_cards ADD COLUMN insurance_claim_amount_approved DECIMAL(12,2) NULL',
            'insurance_customer_payable_amount' => 'ALTER TABLE job_cards ADD COLUMN insurance_customer_payable_amount DECIMAL(12,2) NULL',
            'insurance_claim_status' => 'ALTER TABLE job_cards ADD COLUMN insurance_claim_status ENUM("PENDING","APPROVED","REJECTED","SETTLED") NOT NULL DEFAULT "PENDING"',
        ];

        $ready = true;
        foreach ($requiredColumns as $columnName => $alterSql) {
            if (!job_insurance_add_column_if_missing($pdo, $columnName, $alterSql)) {
                $ready = false;
            }
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS job_insurance_documents (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                garage_id INT UNSIGNED NOT NULL,
                job_card_id INT UNSIGNED NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                file_size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
                note VARCHAR(255) NULL,
                uploaded_by INT UNSIGNED NULL,
                status_code ENUM("ACTIVE","DELETED") NOT NULL DEFAULT "ACTIVE",
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                KEY idx_job_insurance_docs_scope (company_id, garage_id, job_card_id, status_code),
                KEY idx_job_insurance_docs_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $ready = $ready && table_columns('job_insurance_documents') !== [];

        $cached = $ready;
    } catch (Throwable $exception) {
        $cached = false;
    }

    return $cached;
}

function job_insurance_allowed_statuses(): array
{
    return ['PENDING', 'APPROVED', 'REJECTED', 'SETTLED'];
}

function job_insurance_normalize_status(?string $status): string
{
    $normalized = strtoupper(trim((string) $status));
    return in_array($normalized, job_insurance_allowed_statuses(), true) ? $normalized : 'PENDING';
}

function job_insurance_parse_amount(mixed $value): ?float
{
    $raw = trim(str_replace([',', ' '], '', (string) $value));
    if ($raw === '') {
        return null;
    }
    if (!is_numeric($raw)) {
        return null;
    }
    return round((float) $raw, 2);
}

function job_insurance_doc_base_relative_dir(): string
{
    return 'assets/uploads/job_insurance_docs';
}

function job_insurance_doc_allowed_mimes(): array
{
    return [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
}

function job_insurance_doc_max_upload_bytes(int $companyId, int $garageId): int
{
    $rawValue = system_setting_get_value($companyId, $garageId, 'job_insurance_doc_max_mb', '8');
    $maxMb = (float) str_replace(',', '.', trim((string) $rawValue));
    if (!is_finite($maxMb) || $maxMb <= 0) {
        $maxMb = 8.0;
    }
    $maxMb = min(25.0, max(1.0, $maxMb));
    return (int) round($maxMb * 1048576);
}

function job_insurance_doc_relative_dir(int $companyId, int $garageId, int $jobId): string
{
    return job_insurance_doc_base_relative_dir()
        . '/c' . max(0, $companyId)
        . '/g' . max(0, $garageId)
        . '/j' . max(0, $jobId);
}

function job_insurance_doc_full_path(?string $rawPath): ?string
{
    $path = ltrim(str_replace('\\', '/', trim((string) $rawPath)), '/');
    if ($path === '' || str_contains($path, '..')) {
        return null;
    }
    if (!str_starts_with($path, job_insurance_doc_base_relative_dir() . '/')) {
        return null;
    }
    return APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
}

function job_insurance_doc_url(?string $rawPath): ?string
{
    $path = ltrim(str_replace('\\', '/', trim((string) $rawPath)), '/');
    if ($path === '' || str_contains($path, '..')) {
        return null;
    }
    $fullPath = job_insurance_doc_full_path($path);
    if ($fullPath === null || !is_file($fullPath)) {
        return null;
    }
    return url($path);
}

function job_insurance_store_document_upload(
    array $file,
    int $companyId,
    int $garageId,
    int $jobId,
    int $actorUserId,
    ?string $note = null
): array {
    if (!job_insurance_feature_ready()) {
        return ['ok' => false, 'message' => 'Insurance document storage is not ready.'];
    }
    if ($companyId <= 0 || $garageId <= 0 || $jobId <= 0) {
        return ['ok' => false, 'message' => 'Invalid job scope for insurance upload.'];
    }

    $jobStmt = db()->prepare(
        'SELECT id
         FROM job_cards
         WHERE id = :id
           AND company_id = :company_id
           AND garage_id = :garage_id
           AND status_code <> "DELETED"
         LIMIT 1'
    );
    $jobStmt->execute([
        'id' => $jobId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
    if (!$jobStmt->fetch()) {
        return ['ok' => false, 'message' => 'Job card not found for insurance document upload.'];
    }

    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode !== UPLOAD_ERR_OK) {
        $message = match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Insurance document exceeds upload limit.',
            UPLOAD_ERR_NO_FILE => 'No insurance document selected.',
            default => 'Unable to process uploaded insurance document.',
        };
        return ['ok' => false, 'message' => $message];
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return ['ok' => false, 'message' => 'Uploaded insurance document is not valid.'];
    }

    $size = (int) ($file['size'] ?? 0);
    $maxBytes = job_insurance_doc_max_upload_bytes($companyId, $garageId);
    if ($size <= 0 || $size > $maxBytes) {
        return ['ok' => false, 'message' => 'Insurance document exceeds allowed size.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpPath) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    $allowedMimes = job_insurance_doc_allowed_mimes();
    if (!isset($allowedMimes[$mimeType])) {
        return ['ok' => false, 'message' => 'Only PDF, JPG, PNG, or WEBP insurance documents are allowed.'];
    }

    $relativeDir = job_insurance_doc_relative_dir($companyId, $garageId, $jobId);
    $targetDir = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        return ['ok' => false, 'message' => 'Unable to prepare insurance document directory.'];
    }

    $extension = $allowedMimes[$mimeType];
    $targetName = sprintf('ins-%d-%d-%s.%s', $jobId, $companyId, bin2hex(random_bytes(10)), $extension);
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $targetName;
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        return ['ok' => false, 'message' => 'Unable to save insurance document file.'];
    }

    $relativePath = $relativeDir . '/' . $targetName;
    try {
        $insertStmt = db()->prepare(
            'INSERT INTO job_insurance_documents
              (company_id, garage_id, job_card_id, file_name, file_path, mime_type, file_size_bytes, note, uploaded_by, status_code, deleted_at)
             VALUES
              (:company_id, :garage_id, :job_card_id, :file_name, :file_path, :mime_type, :file_size_bytes, :note, :uploaded_by, "ACTIVE", NULL)'
        );
        $insertStmt->execute([
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'job_card_id' => $jobId,
            'file_name' => mb_substr((string) ($file['name'] ?? $targetName), 0, 255),
            'file_path' => $relativePath,
            'mime_type' => mb_substr($mimeType, 0, 100),
            'file_size_bytes' => $size,
            'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
            'uploaded_by' => $actorUserId > 0 ? $actorUserId : null,
        ]);
        return [
            'ok' => true,
            'document_id' => (int) db()->lastInsertId(),
            'file_name' => (string) ($file['name'] ?? $targetName),
            'file_path' => $relativePath,
            'file_size_bytes' => $size,
            'mime_type' => $mimeType,
        ];
    } catch (Throwable $exception) {
        @unlink($targetPath);
        return ['ok' => false, 'message' => 'Unable to save insurance document metadata.'];
    }
}

function job_insurance_documents_by_job(int $companyId, int $garageId, int $jobId, int $limit = 100): array
{
    if (!job_insurance_feature_ready() || $companyId <= 0 || $garageId <= 0 || $jobId <= 0) {
        return [];
    }
    $limit = max(1, min(300, $limit));

    $stmt = db()->prepare(
        'SELECT d.*, u.name AS uploaded_by_name
         FROM job_insurance_documents d
         LEFT JOIN users u ON u.id = d.uploaded_by
         WHERE d.company_id = :company_id
           AND d.garage_id = :garage_id
           AND d.job_card_id = :job_card_id
           AND d.status_code = "ACTIVE"
         ORDER BY d.id DESC
         LIMIT ' . $limit
    );
    $stmt->execute([
        'company_id' => $companyId,
        'garage_id' => $garageId,
        'job_card_id' => $jobId,
    ]);
    return $stmt->fetchAll();
}

function job_insurance_delete_document(int $documentId, int $companyId, int $garageId): array
{
    if (!job_insurance_feature_ready() || $documentId <= 0 || $companyId <= 0 || $garageId <= 0) {
        return ['ok' => false, 'message' => 'Invalid insurance document reference.'];
    }

    $stmt = db()->prepare(
        'SELECT id, file_path
         FROM job_insurance_documents
         WHERE id = :id
           AND company_id = :company_id
           AND garage_id = :garage_id
           AND status_code = "ACTIVE"
         LIMIT 1'
    );
    $stmt->execute([
        'id' => $documentId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);
    $document = $stmt->fetch();
    if (!$document) {
        return ['ok' => false, 'message' => 'Insurance document not found.'];
    }

    $updateStmt = db()->prepare(
        'UPDATE job_insurance_documents
         SET status_code = "DELETED",
             deleted_at = NOW()
         WHERE id = :id
           AND company_id = :company_id
           AND garage_id = :garage_id'
    );
    $updateStmt->execute([
        'id' => $documentId,
        'company_id' => $companyId,
        'garage_id' => $garageId,
    ]);

    $filePath = job_insurance_doc_full_path((string) ($document['file_path'] ?? ''));
    $fileDeleted = false;
    if ($filePath !== null && is_file($filePath)) {
        $fileDeleted = @unlink($filePath);
    }

    return ['ok' => true, 'file_deleted' => $fileDeleted];
}

function job_insurance_dashboard_summary(int $companyId, int $selectedGarageId, array $garageIds): array
{
    if (!job_insurance_feature_ready() || $companyId <= 0) {
        return [
            'total_claim_jobs' => 0,
            'pending_count' => 0,
            'approved_count' => 0,
            'rejected_count' => 0,
            'settled_count' => 0,
            'pending_amount' => 0.0,
        ];
    }

    $params = ['company_id' => $companyId];
    $scopeSql = analytics_garage_scope_sql('jc.garage_id', $selectedGarageId, $garageIds, $params, 'ins_scope');
    $stmt = db()->prepare(
        'SELECT
            COUNT(*) AS total_claim_jobs,
            COALESCE(SUM(CASE WHEN jc.insurance_claim_status = "PENDING" THEN 1 ELSE 0 END), 0) AS pending_count,
            COALESCE(SUM(CASE WHEN jc.insurance_claim_status = "APPROVED" THEN 1 ELSE 0 END), 0) AS approved_count,
            COALESCE(SUM(CASE WHEN jc.insurance_claim_status = "REJECTED" THEN 1 ELSE 0 END), 0) AS rejected_count,
            COALESCE(SUM(CASE WHEN jc.insurance_claim_status = "SETTLED" THEN 1 ELSE 0 END), 0) AS settled_count,
            COALESCE(SUM(CASE WHEN jc.insurance_claim_status = "PENDING" THEN COALESCE(jc.insurance_claim_amount_approved, 0) ELSE 0 END), 0) AS pending_amount
         FROM job_cards jc
         WHERE jc.company_id = :company_id
           AND jc.status_code <> "DELETED"
           AND (COALESCE(jc.insurance_company_name, "") <> "" OR COALESCE(jc.insurance_claim_number, "") <> "")
           ' . $scopeSql
    );
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];

    return [
        'total_claim_jobs' => (int) ($row['total_claim_jobs'] ?? 0),
        'pending_count' => (int) ($row['pending_count'] ?? 0),
        'approved_count' => (int) ($row['approved_count'] ?? 0),
        'rejected_count' => (int) ($row['rejected_count'] ?? 0),
        'settled_count' => (int) ($row['settled_count'] ?? 0),
        'pending_amount' => round((float) ($row['pending_amount'] ?? 0), 2),
    ];
}
