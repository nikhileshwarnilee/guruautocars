<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('backup.manage');

$page_title = 'Backup & Recovery Readiness';
$active_menu = 'system.backup';

$companyId = active_company_id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

function backup_parse_datetime_local(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $normalized = str_replace('T', ' ', $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized)) {
        return null;
    }

    return $normalized . ':00';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'log_backup') {
        $backupLabel = post_string('backup_label', 140);
        $dumpFileName = post_string('dump_file_name', 255);
        $fileSizeMb = (float) ($_POST['file_size_mb'] ?? 0);
        $checksum = post_string('checksum_sha256', 128);
        $statusCode = strtoupper(post_string('status_code', 20));
        $notes = post_string('notes', 255);
        $backupType = strtoupper(post_string('backup_type', 20));
        $startedAt = backup_parse_datetime_local((string) ($_POST['dump_started_at'] ?? ''));
        $completedAt = backup_parse_datetime_local((string) ($_POST['dump_completed_at'] ?? ''));

        if ($backupLabel === '' || $dumpFileName === '') {
            flash_set('backup_error', 'Backup label and dump file name are required.', 'danger');
            redirect('modules/system/backup_recovery.php');
        }

        if (!in_array($statusCode, ['SUCCESS', 'FAILED'], true)) {
            $statusCode = 'SUCCESS';
        }
        if (!in_array($backupType, ['MANUAL', 'SCHEDULED', 'RESTORE_POINT'], true)) {
            $backupType = 'MANUAL';
        }

        log_backup_run([
            'company_id' => $companyId,
            'backup_type' => $backupType,
            'backup_label' => $backupLabel,
            'dump_file_name' => $dumpFileName,
            'file_size_bytes' => max(0, (int) round($fileSizeMb * 1024 * 1024)),
            'checksum_sha256' => $checksum !== '' ? $checksum : null,
            'dump_started_at' => $startedAt,
            'dump_completed_at' => $completedAt,
            'status_code' => $statusCode,
            'notes' => $notes !== '' ? $notes : null,
            'created_by' => $userId > 0 ? $userId : null,
        ]);

        log_audit('backup', 'metadata_log', null, 'Backup metadata recorded.', [
            'entity' => 'backup_run',
            'source' => 'UI',
            'before' => ['exists' => false],
            'after' => [
                'backup_label' => $backupLabel,
                'dump_file_name' => $dumpFileName,
                'status_code' => $statusCode,
                'file_size_mb' => round($fileSizeMb, 2),
            ],
        ]);

        flash_set('backup_success', 'Backup metadata recorded successfully.', 'success');
        redirect('modules/system/backup_recovery.php');
    }

    if ($action === 'run_integrity_check') {
        try {
            $issues = [];
            $criticalIssues = 0;
            $warningIssues = 0;

            $jobStatusStmt = db()->prepare(
                'SELECT COUNT(*)
                 FROM job_cards
                 WHERE company_id = :company_id
                   AND (status NOT IN ("OPEN", "IN_PROGRESS", "WAITING_PARTS", "READY_FOR_DELIVERY", "COMPLETED", "CLOSED", "CANCELLED")
                        OR status_code NOT IN ("ACTIVE", "INACTIVE", "DELETED"))'
            );
            $jobStatusStmt->execute(['company_id' => $companyId]);
            $invalidJobStatus = (int) $jobStatusStmt->fetchColumn();
            if ($invalidJobStatus > 0) {
                $criticalIssues++;
                $issues[] = ['code' => 'JOB_STATUS_INVALID', 'severity' => 'FAIL', 'count' => $invalidJobStatus];
            }

            $jobClosedAtStmt = db()->prepare(
                'SELECT COUNT(*)
                 FROM job_cards
                 WHERE company_id = :company_id
                   AND status = "CLOSED"
                   AND closed_at IS NULL'
            );
            $jobClosedAtStmt->execute(['company_id' => $companyId]);
            $closedWithoutTimestamp = (int) $jobClosedAtStmt->fetchColumn();
            if ($closedWithoutTimestamp > 0) {
                $warningIssues++;
                $issues[] = ['code' => 'JOB_CLOSED_AT_MISSING', 'severity' => 'WARN', 'count' => $closedWithoutTimestamp];
            }

            $invoiceStatusStmt = db()->prepare(
                'SELECT COUNT(*)
                 FROM invoices
                 WHERE company_id = :company_id
                   AND invoice_status NOT IN ("DRAFT", "FINALIZED", "CANCELLED")'
            );
            $invoiceStatusStmt->execute(['company_id' => $companyId]);
            $invalidInvoiceStatus = (int) $invoiceStatusStmt->fetchColumn();
            if ($invalidInvoiceStatus > 0) {
                $criticalIssues++;
                $issues[] = ['code' => 'INVOICE_STATUS_INVALID', 'severity' => 'FAIL', 'count' => $invalidInvoiceStatus];
            }

            $invoiceSequenceStmt = db()->prepare(
                'SELECT COUNT(*)
                 FROM (
                   SELECT i.garage_id,
                          MAX(COALESCE(i.sequence_number, CAST(SUBSTRING_INDEX(i.invoice_number, "-", -1) AS UNSIGNED))) AS max_invoice_no
                   FROM invoices i
                   WHERE i.company_id = :company_id
                   GROUP BY i.garage_id
                 ) x
                 INNER JOIN invoice_counters ic ON ic.garage_id = x.garage_id
                 WHERE x.max_invoice_no > ic.current_number'
            );
            $invoiceSequenceStmt->execute(['company_id' => $companyId]);
            $invoiceSequenceMismatch = (int) $invoiceSequenceStmt->fetchColumn();
            if ($invoiceSequenceMismatch > 0) {
                $criticalIssues++;
                $issues[] = ['code' => 'INVOICE_COUNTER_BEHIND', 'severity' => 'FAIL', 'count' => $invoiceSequenceMismatch];
            }

            $jobSequenceStmt = db()->prepare(
                'SELECT COUNT(*)
                 FROM (
                   SELECT jc.garage_id,
                          MAX(CAST(SUBSTRING_INDEX(jc.job_number, "-", -1) AS UNSIGNED)) AS max_job_no
                   FROM job_cards jc
                   WHERE jc.company_id = :company_id
                   GROUP BY jc.garage_id
                 ) x
                 INNER JOIN job_counters jc2 ON jc2.garage_id = x.garage_id
                 WHERE x.max_job_no > jc2.current_number'
            );
            $jobSequenceStmt->execute(['company_id' => $companyId]);
            $jobSequenceMismatch = (int) $jobSequenceStmt->fetchColumn();
            if ($jobSequenceMismatch > 0) {
                $criticalIssues++;
                $issues[] = ['code' => 'JOB_COUNTER_BEHIND', 'severity' => 'FAIL', 'count' => $jobSequenceMismatch];
            }

            $fyMismatchStmt = db()->prepare(
                'SELECT COUNT(*)
                 FROM invoices i
                 INNER JOIN financial_years fy ON fy.id = i.financial_year_id
                 WHERE i.company_id = :company_id
                   AND i.financial_year_id IS NOT NULL
                   AND (i.invoice_date < fy.start_date OR i.invoice_date > fy.end_date)'
            );
            $fyMismatchStmt->execute(['company_id' => $companyId]);
            $fyMismatch = (int) $fyMismatchStmt->fetchColumn();
            if ($fyMismatch > 0) {
                $criticalIssues++;
                $issues[] = ['code' => 'FY_SCOPE_MISMATCH', 'severity' => 'FAIL', 'count' => $fyMismatch];
            }

            $resultCode = 'PASS';
            if ($criticalIssues > 0) {
                $resultCode = 'FAIL';
            } elseif ($warningIssues > 0) {
                $resultCode = 'WARN';
            }

            $summary = [
                'checked_at' => date('c'),
                'company_id' => $companyId,
                'critical_issues' => $criticalIssues,
                'warning_issues' => $warningIssues,
                'issues' => $issues,
            ];

            log_backup_integrity_check([
                'company_id' => $companyId,
                'result_code' => $resultCode,
                'issues_count' => count($issues),
                'summary' => $summary,
                'checked_by' => $userId > 0 ? $userId : null,
            ]);

            log_audit('backup', 'integrity_check', null, 'Post-restore integrity check executed. Result: ' . $resultCode, [
                'entity' => 'backup_integrity',
                'source' => 'SYSTEM',
                'before' => ['integrity_state' => 'UNKNOWN'],
                'after' => ['integrity_state' => $resultCode, 'issues_count' => count($issues)],
                'metadata' => ['issues' => $issues],
            ]);

            if ($resultCode === 'PASS') {
                flash_set('backup_success', 'Integrity check completed: PASS.', 'success');
            } elseif ($resultCode === 'WARN') {
                flash_set('backup_warning', 'Integrity check completed with warnings.', 'warning');
            } else {
                flash_set('backup_error', 'Integrity check failed. Review issues immediately.', 'danger');
            }
        } catch (Throwable $exception) {
            flash_set('backup_error', 'Integrity check could not be completed: ' . $exception->getMessage(), 'danger');
        }

        redirect('modules/system/backup_recovery.php');
    }
}

$backupRuns = [];
if (table_columns('backup_runs') !== []) {
    $runsStmt = db()->prepare(
        'SELECT br.*, u.name AS created_by_name
         FROM backup_runs br
         LEFT JOIN users u ON u.id = br.created_by
         WHERE br.company_id = :company_id
         ORDER BY br.id DESC
         LIMIT 50'
    );
    $runsStmt->execute(['company_id' => $companyId]);
    $backupRuns = $runsStmt->fetchAll();
}

$integrityChecks = [];
if (table_columns('backup_integrity_checks') !== []) {
    $checksStmt = db()->prepare(
        'SELECT bic.*, u.name AS checked_by_name
         FROM backup_integrity_checks bic
         LEFT JOIN users u ON u.id = bic.checked_by
         WHERE bic.company_id = :company_id
         ORDER BY bic.id DESC
         LIMIT 30'
    );
    $checksStmt->execute(['company_id' => $companyId]);
    $integrityChecks = $checksStmt->fetchAll();
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-7">
          <h3 class="mb-0">Backup & Recovery Readiness</h3>
          <small class="text-muted">Data-only backup process, metadata audit, and restore safety validation.</small>
        </div>
        <div class="col-sm-5">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Backup & Recovery</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="card card-outline card-primary mb-3">
        <div class="card-header"><h3 class="card-title">Backup Flow (mysqldump Compatible)</h3></div>
        <div class="card-body">
          <ol class="mb-2">
            <li>Create a timestamped SQL dump for database data and structures only (no cache folders/files).</li>
            <li>Store dump in controlled storage with checksum and retention policy.</li>
            <li>Log metadata below for accountability and audit trace.</li>
            <li>For recovery, restore into staging first, run integrity checks, then promote.</li>
          </ol>
          <div class="small text-muted">Suggested dump command (Windows):</div>
          <pre class="mb-2"><code>mysqldump -u root --single-transaction --routines --triggers --events --set-gtid-purged=OFF guruautocars &gt; backups\guruautocars_YYYYMMDD_HHMMSS.sql</code></pre>
          <div class="small text-muted">Suggested restore command (staging-first):</div>
          <pre class="mb-0"><code>mysql -u root guruautocars_restore &lt; backups\guruautocars_YYYYMMDD_HHMMSS.sql</code></pre>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-lg-7">
          <div class="card card-primary">
            <div class="card-header"><h3 class="card-title">Record Backup Metadata</h3></div>
            <form method="post">
              <div class="card-body row g-2">
                <?= csrf_field(); ?>
                <input type="hidden" name="_action" value="log_backup">
                <div class="col-md-6">
                  <label class="form-label">Backup Label</label>
                  <input type="text" name="backup_label" class="form-control" placeholder="Nightly backup / Pre-upgrade checkpoint" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Dump File Name</label>
                  <input type="text" name="dump_file_name" class="form-control" placeholder="guruautocars_20260210_210000.sql" required>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Type</label>
                  <select name="backup_type" class="form-select">
                    <option value="MANUAL">MANUAL</option>
                    <option value="SCHEDULED">SCHEDULED</option>
                    <option value="RESTORE_POINT">RESTORE_POINT</option>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Status</label>
                  <select name="status_code" class="form-select">
                    <option value="SUCCESS">SUCCESS</option>
                    <option value="FAILED">FAILED</option>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label">File Size (MB)</label>
                  <input type="number" name="file_size_mb" step="0.01" min="0" class="form-control" value="0">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Checksum (SHA256)</label>
                  <input type="text" name="checksum_sha256" class="form-control" maxlength="128">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Dump Started At</label>
                  <input type="datetime-local" name="dump_started_at" class="form-control">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Dump Completed At</label>
                  <input type="datetime-local" name="dump_completed_at" class="form-control">
                </div>
                <div class="col-md-12">
                  <label class="form-label">Notes</label>
                  <input type="text" name="notes" class="form-control" maxlength="255" placeholder="Storage path, ticket id, operator notes">
                </div>
              </div>
              <div class="card-footer">
                <button type="submit" class="btn btn-primary">Record Backup Metadata</button>
              </div>
            </form>
          </div>
        </div>

        <div class="col-lg-5">
          <div class="card card-warning h-100">
            <div class="card-header"><h3 class="card-title">Post-Restore Integrity Check</h3></div>
            <div class="card-body">
              <p class="mb-2">Runs non-destructive checks for:</p>
              <ul class="mb-3">
                <li>Job and invoice status workflow validity</li>
                <li>Counter/numbering continuity (job + invoice)</li>
                <li>Invoice date alignment to assigned financial year</li>
              </ul>
              <form method="post">
                <?= csrf_field(); ?>
                <input type="hidden" name="_action" value="run_integrity_check">
                <button type="submit" class="btn btn-warning">Run Integrity Check</button>
              </form>
            </div>
          </div>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header"><h3 class="card-title">Backup Metadata Log</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th>Created At</th>
                <th>Label</th>
                <th>File</th>
                <th>Type</th>
                <th>Status</th>
                <th>Size (MB)</th>
                <th>By</th>
                <th>Notes</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($backupRuns)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No backup metadata entries found.</td></tr>
              <?php else: ?>
                <?php foreach ($backupRuns as $run): ?>
                  <tr>
                    <td><?= e((string) $run['created_at']); ?></td>
                    <td><?= e((string) $run['backup_label']); ?></td>
                    <td><code><?= e((string) $run['dump_file_name']); ?></code></td>
                    <td><?= e((string) $run['backup_type']); ?></td>
                    <td><span class="badge text-bg-<?= ((string) $run['status_code'] === 'SUCCESS') ? 'success' : 'danger'; ?>"><?= e((string) $run['status_code']); ?></span></td>
                    <td><?= e(number_format(((float) ($run['file_size_bytes'] ?? 0)) / 1048576, 2)); ?></td>
                    <td><?= e((string) ($run['created_by_name'] ?? '-')); ?></td>
                    <td><?= e((string) ($run['notes'] ?? '-')); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header"><h3 class="card-title">Integrity Check History</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th>Checked At</th>
                <th>Result</th>
                <th>Issues</th>
                <th>Checked By</th>
                <th>Summary</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($integrityChecks)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No integrity checks recorded.</td></tr>
              <?php else: ?>
                <?php foreach ($integrityChecks as $check): ?>
                  <?php
                    $summary = json_decode((string) ($check['summary_json'] ?? ''), true);
                    $summaryText = is_array($summary) ? json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '';
                    $badge = 'success';
                    if ((string) $check['result_code'] === 'WARN') {
                        $badge = 'warning';
                    }
                    if ((string) $check['result_code'] === 'FAIL') {
                        $badge = 'danger';
                    }
                  ?>
                  <tr>
                    <td><?= e((string) $check['checked_at']); ?></td>
                    <td><span class="badge text-bg-<?= e($badge); ?>"><?= e((string) $check['result_code']); ?></span></td>
                    <td><?= (int) ($check['issues_count'] ?? 0); ?></td>
                    <td><?= e((string) ($check['checked_by_name'] ?? '-')); ?></td>
                    <td>
                      <?php if ($summaryText !== '' && $summaryText !== 'null'): ?>
                        <details>
                          <summary class="small">View</summary>
                          <pre class="small mb-0"><?= e((string) $summaryText); ?></pre>
                        </details>
                      <?php else: ?>
                        <span class="text-muted">-</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
