<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('garage.manage');

$page_title = 'Garage / Branch Master';
$active_menu = 'organization.garages';
$canManage = has_permission('garage.manage');
$isSuperAdmin = (string) ($_SESSION['role_key'] ?? '') === 'super_admin';
$activeCompanyId = active_company_id();

function garage_company_exists(PDO $pdo, int $companyId): bool
{
    if ($companyId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT id
         FROM companies
         WHERE id = :id
           AND status_code <> "DELETED"
         LIMIT 1'
    );
    $stmt->execute(['id' => $companyId]);

    return (bool) $stmt->fetchColumn();
}

function garage_normalize_name(string $name): string
{
    $collapsed = preg_replace('/\s+/', ' ', trim($name));

    return strtoupper((string) ($collapsed ?? ''));
}

function garage_name_exists(PDO $pdo, int $companyId, string $name, int $excludeGarageId = 0): bool
{
    if ($companyId <= 0 || trim($name) === '') {
        return false;
    }

    $sql = 'SELECT id, name
            FROM garages
            WHERE company_id = :company_id';
    $params = ['company_id' => $companyId];

    if ($excludeGarageId > 0) {
        $sql .= ' AND id <> :exclude_id';
        $params['exclude_id'] = $excludeGarageId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $normalizedName = garage_normalize_name($name);

    foreach ($stmt->fetchAll() as $row) {
        if ($normalizedName === garage_normalize_name((string) ($row['name'] ?? ''))) {
            return true;
        }
    }

    return false;
}

function garage_build_code_base(string $name): string
{
    $normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', ' ', $name));
    $words = array_values(array_filter(explode(' ', trim($normalized)), static fn (string $value): bool => $value !== ''));
    $base = '';

    foreach ($words as $word) {
        $remaining = 8 - strlen($base);
        if ($remaining <= 0) {
            break;
        }

        $base .= substr($word, 0, min(3, $remaining));
    }

    if ($base === '') {
        $compact = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $name));
        $base = substr($compact !== '' ? $compact : 'GARAGE', 0, 8);
    }

    if (strlen($base) < 3) {
        $compact = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $name));
        $base = substr(str_pad($compact !== '' ? $compact : 'GARAGE', 3, 'G'), 0, 8);
    }

    return $base;
}

function garage_code_exists(PDO $pdo, int $companyId, string $code, int $excludeGarageId = 0): bool
{
    if ($companyId <= 0 || trim($code) === '') {
        return false;
    }

    $sql = 'SELECT id
            FROM garages
            WHERE company_id = :company_id
              AND code = :code';
    $params = [
        'company_id' => $companyId,
        'code' => $code,
    ];

    if ($excludeGarageId > 0) {
        $sql .= ' AND id <> :exclude_id';
        $params['exclude_id'] = $excludeGarageId;
    }

    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (bool) $stmt->fetchColumn();
}

function garage_generate_unique_code(PDO $pdo, int $companyId, string $name, int $excludeGarageId = 0): string
{
    $base = garage_build_code_base($name);

    for ($index = 0; $index < 1000; $index++) {
        $suffix = $index === 0 ? '' : (string) ($index + 1);
        $candidate = substr($base, 0, 30 - strlen($suffix)) . $suffix;
        if (!garage_code_exists($pdo, $companyId, $candidate, $excludeGarageId)) {
            return $candidate;
        }
    }

    $fallback = substr($base, 0, 24) . date('His');
    if (!garage_code_exists($pdo, $companyId, $fallback, $excludeGarageId)) {
        return $fallback;
    }

    return substr($base, 0, 22) . date('His') . mt_rand(10, 99);
}

function garage_save_error_message(Throwable $exception, string $operation): string
{
    $defaultMessage = $operation === 'update'
        ? 'Unable to update garage right now. Please try again.'
        : 'Unable to create garage right now. Please try again.';

    $driverCode = 0;
    if ($exception instanceof PDOException && isset($exception->errorInfo[1])) {
        $driverCode = (int) $exception->errorInfo[1];
    }

    if (
        $driverCode === 1062
        || stripos($exception->getMessage(), 'duplicate') !== false
        || stripos($exception->getMessage(), 'unique') !== false
    ) {
        return $defaultMessage;
    }

    if (
        $driverCode === 1452
        || stripos($exception->getMessage(), 'fk_garages_company') !== false
        || stripos($exception->getMessage(), 'foreign key constraint fails') !== false
    ) {
        return $operation === 'update'
            ? 'Unable to update garage. The selected company was not found.'
            : 'Unable to create garage. Create or select a valid company first.';
    }

    return $defaultMessage;
}

function garage_log_save_error(string $operation, Throwable $exception, int $companyId, string $code): void
{
    error_log(sprintf(
        'Garage %s failed. company_id=%d code=%s error=%s',
        $operation,
        $companyId,
        $code,
        $exception->getMessage()
    ));
}

$companyOptions = [];
if ($isSuperAdmin) {
    $companyOptions = db()->query('SELECT id, name FROM companies WHERE status_code <> "DELETED" ORDER BY name ASC')->fetchAll();
}

$selectedCompanyId = $isSuperAdmin ? get_int('company_id', 0) : $activeCompanyId;
$selectedCompanyExists = garage_company_exists(db(), $selectedCompanyId);
$currentUser = current_user();
$activeCompanyName = trim((string) ($currentUser['company_name'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!$canManage) {
        flash_set('garage_error', 'You do not have permission to modify garage master.', 'danger');
        redirect('modules/organization/garages.php');
    }

    $action = (string) ($_POST['_action'] ?? '');
    $companyId = $isSuperAdmin ? post_int('company_id', $activeCompanyId) : $activeCompanyId;

    if ($action === 'create') {
        $name = trim((string) preg_replace('/\s+/', ' ', post_string('name', 140)));
        $phone = post_string('phone', 20);
        $email = strtolower(post_string('email', 120));
        $gstin = strtoupper(post_string('gstin', 15));
        $address1 = post_string('address_line1', 200);
        $address2 = post_string('address_line2', 200);
        $city = post_string('city', 80);
        $state = post_string('state', 80);
        $pincode = post_string('pincode', 10);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($companyId <= 0 || $name === '') {
            flash_set('garage_error', 'Company and garage name are required.', 'danger');
            redirect('modules/organization/garages.php?company_id=' . ($companyId > 0 ? $companyId : $selectedCompanyId));
        }

        if ($statusCode === 'DELETED') {
            $statusCode = 'ACTIVE';
        }

        $legacyStatus = $statusCode === 'ACTIVE' ? 'active' : 'inactive';

        $pdo = db();
        if (!garage_company_exists($pdo, $companyId)) {
            flash_set('garage_error', 'Select a valid company first, then add the garage.', 'danger');
            redirect('modules/organization/garages.php?company_id=' . ($companyId > 0 ? $companyId : $selectedCompanyId));
        }

        if (garage_name_exists($pdo, $companyId, $name)) {
            flash_set('garage_error', 'Garage name must be unique per company.', 'danger');
            redirect('modules/organization/garages.php?company_id=' . $companyId);
        }

        $code = garage_generate_unique_code($pdo, $companyId, $name);
        $pdo->beginTransaction();

        try {
            $insertStmt = $pdo->prepare(
                'INSERT INTO garages
                  (company_id, name, code, phone, email, gstin, address_line1, address_line2, city, state, pincode, status, status_code, deleted_at)
                 VALUES
                  (:company_id, :name, :code, :phone, :email, :gstin, :address_line1, :address_line2, :city, :state, :pincode, :status, :status_code, NULL)'
            );
            $insertStmt->execute([
                'company_id' => $companyId,
                'name' => $name,
                'code' => $code,
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? $email : null,
                'gstin' => $gstin !== '' ? $gstin : null,
                'address_line1' => $address1 !== '' ? $address1 : null,
                'address_line2' => $address2 !== '' ? $address2 : null,
                'city' => $city !== '' ? $city : null,
                'state' => $state !== '' ? $state : null,
                'pincode' => $pincode !== '' ? $pincode : null,
                'status' => $legacyStatus,
                'status_code' => $statusCode,
            ]);

            $garageId = (int) $pdo->lastInsertId();

            $counterStmt = $pdo->prepare('INSERT IGNORE INTO job_counters (garage_id, prefix, current_number) VALUES (:garage_id, "JOB", 1000)');
            $counterStmt->execute(['garage_id' => $garageId]);

            $invoiceCounterStmt = $pdo->prepare('INSERT IGNORE INTO invoice_counters (garage_id, prefix, current_number) VALUES (:garage_id, "INV", 5000)');
            $invoiceCounterStmt->execute(['garage_id' => $garageId]);

            $pdo->commit();
            log_audit('garages', 'create', $garageId, 'Created garage ' . $code, [
                'entity' => 'garage',
                'source' => 'UI',
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'before' => ['exists' => false],
                'after' => [
                    'id' => $garageId,
                    'code' => $code,
                    'name' => $name,
                    'status_code' => $statusCode,
                ],
            ]);
            flash_set('garage_success', 'Garage created successfully. Code ' . $code . ' was generated automatically.', 'success');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            garage_log_save_error('create', $exception, $companyId, $code);
            flash_set('garage_error', garage_save_error_message($exception, 'create'), 'danger');
        }

        redirect('modules/organization/garages.php?company_id=' . $companyId);
    }

    if ($action === 'update') {
        $garageId = post_int('garage_id');
        $name = trim((string) preg_replace('/\s+/', ' ', post_string('name', 140)));
        $phone = post_string('phone', 20);
        $email = strtolower(post_string('email', 120));
        $gstin = strtoupper(post_string('gstin', 15));
        $address1 = post_string('address_line1', 200);
        $address2 = post_string('address_line2', 200);
        $city = post_string('city', 80);
        $state = post_string('state', 80);
        $pincode = post_string('pincode', 10);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($garageId <= 0 || $companyId <= 0 || $name === '') {
            flash_set('garage_error', 'Invalid garage payload.', 'danger');
            redirect('modules/organization/garages.php?company_id=' . ($companyId > 0 ? $companyId : $selectedCompanyId));
        }

        if (!$isSuperAdmin && $companyId !== $activeCompanyId) {
            flash_set('garage_error', 'You can only update garages in your company.', 'danger');
            redirect('modules/organization/garages.php?company_id=' . $selectedCompanyId);
        }

        if (!$isSuperAdmin && $statusCode === 'DELETED') {
            flash_set('garage_error', 'Only Super Admin can delete garages.', 'danger');
            redirect('modules/organization/garages.php?company_id=' . $companyId . '&edit_id=' . $garageId);
        }

        $legacyStatus = $statusCode === 'ACTIVE' ? 'active' : 'inactive';
        $pdo = db();
        if (!garage_company_exists($pdo, $companyId)) {
            flash_set('garage_error', 'The selected company was not found.', 'danger');
            redirect('modules/organization/garages.php?company_id=' . $selectedCompanyId);
        }

        $beforeStmt = $pdo->prepare(
            'SELECT id, name, code, status, status_code
             FROM garages
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1'
        );
        $beforeStmt->execute([
            'id' => $garageId,
            'company_id' => $companyId,
        ]);
        $beforeGarage = $beforeStmt->fetch() ?: null;
        if (!is_array($beforeGarage)) {
            flash_set('garage_error', 'The selected garage was not found.', 'danger');
            redirect('modules/organization/garages.php?company_id=' . $companyId);
        }

        $beforeNormalizedName = garage_normalize_name((string) ($beforeGarage['name'] ?? ''));
        $afterNormalizedName = garage_normalize_name($name);
        if ($afterNormalizedName !== $beforeNormalizedName && garage_name_exists($pdo, $companyId, $name, $garageId)) {
            flash_set('garage_error', 'Garage name must be unique per company.', 'danger');
            redirect('modules/organization/garages.php?company_id=' . $companyId . '&edit_id=' . $garageId);
        }

        $code = trim((string) ($beforeGarage['code'] ?? ''));
        if ($code === '') {
            $code = garage_generate_unique_code($pdo, $companyId, $name, $garageId);
        }

        try {
            $stmt = $pdo->prepare(
                'UPDATE garages
                 SET name = :name,
                     code = :code,
                     phone = :phone,
                     email = :email,
                     gstin = :gstin,
                     address_line1 = :address_line1,
                     address_line2 = :address_line2,
                     city = :city,
                     state = :state,
                     pincode = :pincode,
                     status = :status,
                     status_code = :status_code,
                     deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
                 WHERE id = :id
                   AND company_id = :company_id'
            );
            $stmt->execute([
                'name' => $name,
                'code' => $code,
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? $email : null,
                'gstin' => $gstin !== '' ? $gstin : null,
                'address_line1' => $address1 !== '' ? $address1 : null,
                'address_line2' => $address2 !== '' ? $address2 : null,
                'city' => $city !== '' ? $city : null,
                'state' => $state !== '' ? $state : null,
                'pincode' => $pincode !== '' ? $pincode : null,
                'status' => $legacyStatus,
                'status_code' => $statusCode,
                'id' => $garageId,
                'company_id' => $companyId,
            ]);

            log_audit('garages', 'update', $garageId, 'Updated garage ' . $code, [
                'entity' => 'garage',
                'source' => 'UI',
                'company_id' => $companyId,
                'garage_id' => $garageId,
                'before' => is_array($beforeGarage) ? [
                    'name' => (string) ($beforeGarage['name'] ?? ''),
                    'code' => (string) ($beforeGarage['code'] ?? ''),
                    'status_code' => (string) ($beforeGarage['status_code'] ?? ''),
                ] : null,
                'after' => [
                    'name' => $name,
                    'code' => $code,
                    'status_code' => $statusCode,
                ],
            ]);
            flash_set('garage_success', 'Garage updated successfully.', 'success');
        } catch (Throwable $exception) {
            garage_log_save_error('update', $exception, $companyId, $code);
            flash_set('garage_error', garage_save_error_message($exception, 'update'), 'danger');
        }

        redirect('modules/organization/garages.php?company_id=' . $companyId);
    }

    if ($action === 'change_status') {
        $garageId = post_int('garage_id');
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));
        $safeDeleteValidation = null;

        if ($garageId <= 0 || $companyId <= 0) {
            flash_set('garage_error', 'Invalid garage selected.', 'danger');
            redirect('modules/organization/garages.php?company_id=' . $selectedCompanyId);
        }

        if (!$isSuperAdmin && $companyId !== $activeCompanyId) {
            flash_set('garage_error', 'You can only change garages in your company.', 'danger');
            redirect('modules/organization/garages.php?company_id=' . $selectedCompanyId);
        }

        if (!$isSuperAdmin && $nextStatus === 'DELETED') {
            flash_set('garage_error', 'Only Super Admin can delete garages.', 'danger');
            redirect('modules/organization/garages.php?company_id=' . $companyId);
        }
        if ($nextStatus === 'DELETED') {
            $safeDeleteValidation = safe_delete_validate_post_confirmation('org_garage', $garageId, [
                'operation' => 'delete',
                'reason_field' => 'deletion_reason',
            ]);
        }

        $legacyStatus = $nextStatus === 'ACTIVE' ? 'active' : 'inactive';
        $beforeStatusStmt = db()->prepare(
            'SELECT status, status_code
             FROM garages
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1'
        );
        $beforeStatusStmt->execute([
            'id' => $garageId,
            'company_id' => $companyId,
        ]);
        $beforeStatus = $beforeStatusStmt->fetch() ?: null;

        $stmt = db()->prepare(
            'UPDATE garages
             SET status = :status,
                 status_code = :status_code,
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'status' => $legacyStatus,
            'status_code' => $nextStatus,
            'id' => $garageId,
            'company_id' => $companyId,
        ]);

        log_audit('garages', 'status', $garageId, 'Changed status to ' . $nextStatus, [
            'entity' => 'garage',
            'source' => 'UI',
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'before' => is_array($beforeStatus) ? [
                'status' => (string) ($beforeStatus['status'] ?? ''),
                'status_code' => (string) ($beforeStatus['status_code'] ?? ''),
            ] : null,
            'after' => [
                'status' => $legacyStatus,
                'status_code' => $nextStatus,
            ],
        ]);
        if ($nextStatus === 'DELETED' && is_array($safeDeleteValidation)) {
            safe_delete_log_cascade('org_garage', 'delete', $garageId, $safeDeleteValidation, [
                'metadata' => [
                    'company_id' => $companyId,
                    'requested_status' => 'DELETED',
                    'applied_status' => $nextStatus,
                ],
            ]);
        }
        flash_set('garage_success', 'Garage status updated.', 'success');
        redirect('modules/organization/garages.php?company_id=' . $companyId);
    }
}

$editId = get_int('edit_id');
$editGarage = null;
if ($editId > 0 && $selectedCompanyId > 0) {
    $editStmt = db()->prepare(
        'SELECT *
         FROM garages
         WHERE id = :id
           AND company_id = :company_id
         LIMIT 1'
    );
    $editStmt->execute([
        'id' => $editId,
        'company_id' => $selectedCompanyId,
    ]);
    $editGarage = $editStmt->fetch() ?: null;
}

$garageRows = [];
if ($isSuperAdmin) {
    $garageListStmt = db()->query(
        'SELECT g.*, c.name AS company_name
         FROM garages g
         INNER JOIN companies c ON c.id = g.company_id
         ORDER BY g.id DESC'
    );
    $garageRows = $garageListStmt->fetchAll();
} elseif ($selectedCompanyId > 0) {
    $garageListStmt = db()->prepare(
        'SELECT g.*, c.name AS company_name
         FROM garages g
         INNER JOIN companies c ON c.id = g.company_id
         WHERE g.company_id = :company_id
         ORDER BY g.id DESC'
    );
    $garageListStmt->execute(['company_id' => $selectedCompanyId]);
    $garageRows = $garageListStmt->fetchAll();
}

$statusChoices = ['ACTIVE', 'INACTIVE', 'DELETED'];
if (!$isSuperAdmin) {
    $statusChoices = ['ACTIVE', 'INACTIVE'];
}

$companyNamesById = [];
foreach ($companyOptions as $companyOption) {
    $companyNamesById[(int) ($companyOption['id'] ?? 0)] = (string) ($companyOption['name'] ?? '');
}

$formCompanyId = $editGarage
    ? (int) ($editGarage['company_id'] ?? 0)
    : ($isSuperAdmin ? ($selectedCompanyExists ? $selectedCompanyId : 0) : $activeCompanyId);
$canRenderGarageForm = $canManage && ($isSuperAdmin ? !empty($companyOptions) : $selectedCompanyExists);
$showMissingCompanySetup = $isSuperAdmin && empty($companyOptions);
$showInvalidSelectedCompany = $selectedCompanyId > 0 && !$selectedCompanyExists;

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Garage / Branch Master</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Garage Master</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if ($showMissingCompanySetup): ?>
        <div class="alert alert-warning">
          Create a company in Company Master before adding garages or branches.
        </div>
      <?php elseif ($showInvalidSelectedCompany): ?>
        <div class="alert alert-warning">
          The selected company was not found. Choose a valid company in the garage form below.
        </div>
      <?php elseif (!$isSuperAdmin && !$selectedCompanyExists): ?>
        <div class="alert alert-warning">
          Your account does not have a valid company assigned. Contact an administrator before creating garages.
        </div>
      <?php endif; ?>

      <?php if ($canRenderGarageForm): ?>
        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title"><?= $editGarage ? 'Edit Garage / Branch' : 'Add Garage / Branch'; ?></h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="<?= $editGarage ? 'update' : 'create'; ?>" />
              <input type="hidden" name="garage_id" value="<?= (int) ($editGarage['id'] ?? 0); ?>" />

              <?php if ($isSuperAdmin): ?>
                <?php if ($editGarage): ?>
                  <input type="hidden" name="company_id" value="<?= $formCompanyId; ?>" />
                  <div class="col-md-4">
                    <label class="form-label">Company</label>
                    <input type="text" class="form-control" value="<?= e((string) ($companyNamesById[$formCompanyId] ?? ('Company #' . $formCompanyId))); ?>" readonly />
                  </div>
                <?php else: ?>
                  <div class="col-md-4">
                    <label class="form-label">Company</label>
                    <select name="company_id" class="form-select" required>
                      <option value="0" <?= $formCompanyId <= 0 ? 'selected' : ''; ?>>Select Company</option>
                      <?php foreach ($companyOptions as $company): ?>
                        <option value="<?= (int) $company['id']; ?>" <?= ((int) $company['id'] === $formCompanyId) ? 'selected' : ''; ?>>
                          <?= e((string) $company['name']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                <?php endif; ?>
              <?php else: ?>
                <input type="hidden" name="company_id" value="<?= (int) $activeCompanyId; ?>" />
                <div class="col-md-4">
                  <label class="form-label">Company</label>
                  <input type="text" class="form-control" value="<?= e($activeCompanyName !== '' ? $activeCompanyName : ('Company #' . $activeCompanyId)); ?>" readonly />
                </div>
              <?php endif; ?>

              <div class="col-md-4">
                <label class="form-label">Garage Name</label>
                <input type="text" name="name" class="form-control" required value="<?= e((string) ($editGarage['name'] ?? '')); ?>" placeholder="Use a unique garage / branch name" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" value="<?= e((string) ($editGarage['phone'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= e((string) ($editGarage['email'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">GSTIN</label>
                <input type="text" name="gstin" class="form-control" maxlength="15" value="<?= e((string) ($editGarage['gstin'] ?? '')); ?>" />
              </div>
              <div class="col-md-4">
                <label class="form-label">Address Line 1</label>
                <input type="text" name="address_line1" class="form-control" value="<?= e((string) ($editGarage['address_line1'] ?? '')); ?>" />
              </div>
              <div class="col-md-4">
                <label class="form-label">Address Line 2</label>
                <input type="text" name="address_line2" class="form-control" value="<?= e((string) ($editGarage['address_line2'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">City</label>
                <input type="text" name="city" class="form-control" value="<?= e((string) ($editGarage['city'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">State</label>
                <input type="text" name="state" class="form-control" value="<?= e((string) ($editGarage['state'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Pincode</label>
                <input type="text" name="pincode" class="form-control" maxlength="10" value="<?= e((string) ($editGarage['pincode'] ?? '')); ?>" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status_code" class="form-select" required>
                  <?php foreach (status_options((string) ($editGarage['status_code'] ?? 'ACTIVE')) as $option): ?>
                    <?php if (in_array($option['value'], $statusChoices, true)): ?>
                      <option value="<?= e($option['value']); ?>" <?= $option['selected'] ? 'selected' : ''; ?>><?= e($option['value']); ?></option>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12">
                <div class="alert alert-light border mb-0 py-2">
                  Use a unique garage / branch name within the company. Garage code is generated automatically in the background.
                  <?php if ($editGarage && trim((string) ($editGarage['code'] ?? '')) !== ''): ?>
                    Current code: <span class="font-monospace fw-semibold"><?= e((string) $editGarage['code']); ?></span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="card-footer d-flex gap-2">
              <button type="submit" class="btn btn-primary"><?= $editGarage ? 'Update Garage' : 'Create Garage'; ?></button>
              <?php if ($editGarage): ?>
                <a href="<?= e(url('modules/organization/garages.php?company_id=' . $selectedCompanyId)); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header"><h3 class="card-title">Garage List</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>ID</th>
                <th>Garage</th>
                <th>Code</th>
                <th>Location</th>
                <th>Contact</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($garageRows)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No garages found.</td></tr>
              <?php else: ?>
                <?php foreach ($garageRows as $garage): ?>
                  <?php
                    $garageCompanyId = (int) ($garage['company_id'] ?? 0);
                    $garageCode = trim((string) ($garage['code'] ?? ''));
                    $city = trim((string) ($garage['city'] ?? ''));
                    $state = trim((string) ($garage['state'] ?? ''));
                    $phone = trim((string) ($garage['phone'] ?? ''));
                    $email = trim((string) ($garage['email'] ?? ''));
                    $locationParts = array_values(array_filter([$city, $state], static fn (string $value): bool => $value !== ''));
                    $locationLabel = $locationParts !== [] ? implode(', ', $locationParts) : '-';
                    $garageStatusCode = normalize_status_code((string) ($garage['status_code'] ?? 'ACTIVE'));
                  ?>
                  <tr>
                    <td><?= (int) $garage['id']; ?></td>
                    <td>
                      <div class="fw-semibold"><?= e((string) ($garage['name'] ?? '')); ?></div>
                      <small class="text-muted"><?= e((string) ($garage['company_name'] ?? '')); ?></small>
                    </td>
                    <td>
                      <span class="badge text-bg-light border font-monospace"><?= e($garageCode !== '' ? $garageCode : '-'); ?></span>
                    </td>
                    <td><?= e($locationLabel); ?></td>
                    <td>
                      <div><?= e($phone !== '' ? $phone : '-'); ?></div>
                      <?php if ($email !== ''): ?>
                        <small class="text-muted"><?= e($email); ?></small>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="badge text-bg-<?= e(status_badge_class($garageStatusCode)); ?>"><?= e(record_status_label($garageStatusCode)); ?></span>
                    </td>
                    <td class="text-nowrap">
                      <?php if ($canManage): ?>
                        <div class="d-flex flex-wrap gap-1 justify-content-end">
                          <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/organization/garages.php?company_id=' . $garageCompanyId . '&edit_id=' . (int) $garage['id'])); ?>">Edit</a>
                          <?php if ($garageStatusCode !== 'DELETED'): ?>
                            <form method="post" class="d-inline" data-confirm="Change garage status?">
                              <?= csrf_field(); ?>
                              <input type="hidden" name="_action" value="change_status" />
                              <input type="hidden" name="company_id" value="<?= $garageCompanyId; ?>" />
                              <input type="hidden" name="garage_id" value="<?= (int) $garage['id']; ?>" />
                              <input type="hidden" name="next_status" value="<?= e($garageStatusCode === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'); ?>" />
                              <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $garageStatusCode === 'ACTIVE' ? 'Inactivate' : 'Activate'; ?></button>
                            </form>
                          <?php endif; ?>
                          <?php if ($isSuperAdmin && $garageStatusCode !== 'DELETED'): ?>
                            <form method="post"
                                  class="d-inline"
                                  data-safe-delete
                                  data-safe-delete-entity="org_garage"
                                  data-safe-delete-record-field="garage_id"
                                  data-safe-delete-operation="delete"
                              data-safe-delete-reason-field="deletion_reason">
                              <?= csrf_field(); ?>
                              <input type="hidden" name="_action" value="change_status" />
                              <input type="hidden" name="company_id" value="<?= $garageCompanyId; ?>" />
                              <input type="hidden" name="garage_id" value="<?= (int) $garage['id']; ?>" />
                              <input type="hidden" name="next_status" value="DELETED" />
                              <button type="submit" class="btn btn-sm btn-outline-danger">Soft Delete</button>
                            </form>
                          <?php endif; ?>
                        </div>
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
