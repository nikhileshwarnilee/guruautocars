<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('company.manage');

$page_title = 'Company Master';
$active_menu = 'organization.companies';
$canManage = has_permission('company.manage');
$isSuperAdmin = (string) ($_SESSION['role_key'] ?? '') === 'super_admin';
$activeCompanyId = active_company_id();

function company_logo_upload_relative_dir(): string
{
    return 'assets/uploads/company_logos';
}

function company_logo_allowed_mimes(): array
{
    return [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];
}

function company_logo_remove_file(?string $relativePath): void
{
    $path = trim((string) $relativePath);
    if ($path === '') {
        return;
    }

    $normalized = str_replace('\\', '/', $path);
    $normalized = ltrim($normalized, '/');
    if (!str_starts_with($normalized, company_logo_upload_relative_dir() . '/')) {
        return;
    }

    $fullPath = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function company_logo_store_upload(array $file, int $companyId, int $maxBytes = 2097152): array
{
    if ($companyId <= 0) {
        return ['ok' => false, 'message' => 'Invalid company selected for logo upload.'];
    }

    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode !== UPLOAD_ERR_OK) {
        $message = match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Logo exceeds upload size limit.',
            UPLOAD_ERR_NO_FILE => 'Choose a logo image file to upload.',
            default => 'Unable to process uploaded logo file.',
        };
        return ['ok' => false, 'message' => $message];
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return ['ok' => false, 'message' => 'Uploaded file is not valid.'];
    }

    $sizeBytes = (int) ($file['size'] ?? 0);
    if ($sizeBytes <= 0 || $sizeBytes > $maxBytes) {
        return ['ok' => false, 'message' => 'Logo size must be less than or equal to 2 MB.'];
    }

    $imageInfo = @getimagesize($tmpPath);
    if (!is_array($imageInfo)) {
        return ['ok' => false, 'message' => 'Uploaded file is not a valid image.'];
    }

    $width = (int) ($imageInfo[0] ?? 0);
    $height = (int) ($imageInfo[1] ?? 0);
    if ($width <= 0 || $height <= 0 || $width > 5000 || $height > 5000) {
        return ['ok' => false, 'message' => 'Logo dimensions are invalid or too large.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $tmpPath) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    $allowed = company_logo_allowed_mimes();
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'message' => 'Only PNG, JPG, and WEBP logos are allowed.'];
    }

    $relativeDir = company_logo_upload_relative_dir();
    $fullDir = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
    if (!is_dir($fullDir) && !mkdir($fullDir, 0755, true) && !is_dir($fullDir)) {
        return ['ok' => false, 'message' => 'Unable to prepare logo upload directory.'];
    }

    try {
        $suffix = bin2hex(random_bytes(4));
    } catch (Throwable $exception) {
        $suffix = substr(sha1((string) microtime(true) . (string) mt_rand()), 0, 8);
    }
    $extension = $allowed[$mime];
    $fileName = 'company_' . $companyId . '_logo_' . date('YmdHis') . '_' . $suffix . '.' . $extension;
    $targetPath = $fullDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        return ['ok' => false, 'message' => 'Unable to move uploaded logo file.'];
    }

    return [
        'ok' => true,
        'relative_path' => $relativeDir . '/' . $fileName,
        'width' => $width,
        'height' => $height,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!$canManage) {
        flash_set('company_error', 'You do not have permission to modify company master.', 'danger');
        redirect('modules/organization/companies.php');
    }

    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'upload_logo') {
        $targetCompanyId = post_int('company_id');
        if (!$isSuperAdmin) {
            $targetCompanyId = $activeCompanyId;
        }

        if ($targetCompanyId <= 0) {
            flash_set('company_error', 'Invalid company selected for logo upload.', 'danger');
            redirect('modules/organization/companies.php');
        }

        if (!$isSuperAdmin && $targetCompanyId !== $activeCompanyId) {
            flash_set('company_error', 'You can only update logo for your own company.', 'danger');
            redirect('modules/organization/companies.php');
        }

        $companyStmt = db()->prepare('SELECT id, name FROM companies WHERE id = :id LIMIT 1');
        $companyStmt->execute(['id' => $targetCompanyId]);
        $targetCompany = $companyStmt->fetch() ?: null;
        if (!$targetCompany) {
            flash_set('company_error', 'Company not found for logo update.', 'danger');
            redirect('modules/organization/companies.php');
        }

        $uploadResult = company_logo_store_upload($_FILES['company_logo_file'] ?? [], $targetCompanyId);
        if (!(bool) ($uploadResult['ok'] ?? false)) {
            flash_set('company_error', (string) ($uploadResult['message'] ?? 'Unable to upload logo.'), 'danger');
            $redirectPath = 'modules/organization/companies.php';
            if ($isSuperAdmin) {
                $redirectPath .= '?edit_id=' . $targetCompanyId;
            }
            redirect($redirectPath);
        }

        $newRelativePath = (string) ($uploadResult['relative_path'] ?? '');
        if ($newRelativePath === '') {
            flash_set('company_error', 'Logo upload failed. Please retry.', 'danger');
            redirect('modules/organization/companies.php');
        }

        $existingPath = company_logo_relative_path($targetCompanyId, 0);
        $settingId = system_setting_upsert_value(
            $targetCompanyId,
            null,
            'ORGANIZATION',
            'business_logo_path',
            $newRelativePath,
            'STRING',
            'ACTIVE',
            (int) ($_SESSION['user_id'] ?? 0)
        );
        if ($settingId <= 0) {
            company_logo_remove_file($newRelativePath);
            flash_set('company_error', 'Unable to save logo reference. Please retry.', 'danger');
            redirect('modules/organization/companies.php');
        }

        if ($existingPath !== null && $existingPath !== $newRelativePath) {
            company_logo_remove_file($existingPath);
        }

        log_audit('companies', 'upload_logo', $targetCompanyId, 'Uploaded / replaced business logo', [
            'entity' => 'company',
            'source' => 'UI',
            'before' => ['business_logo_path' => $existingPath],
            'after' => ['business_logo_path' => $newRelativePath],
            'company_id' => $targetCompanyId,
            'metadata' => [
                'setting_id' => $settingId,
                'image_width' => (int) ($uploadResult['width'] ?? 0),
                'image_height' => (int) ($uploadResult['height'] ?? 0),
            ],
        ]);
        flash_set('company_success', 'Business logo updated successfully.', 'success');

        $redirectPath = 'modules/organization/companies.php';
        if ($isSuperAdmin) {
            $redirectPath .= '?edit_id=' . $targetCompanyId;
        }
        redirect($redirectPath);
    }

    if ($action === 'create') {
        if (!$isSuperAdmin) {
            flash_set('company_error', 'Only Super Admin can create companies.', 'danger');
            redirect('modules/organization/companies.php');
        }

        $name = post_string('name', 120);
        $legalName = post_string('legal_name', 160);
        $gstin = strtoupper(post_string('gstin', 15));
        $pan = strtoupper(post_string('pan', 10));
        $phone = post_string('phone', 20);
        $email = strtolower(post_string('email', 120));
        $address1 = post_string('address_line1', 200);
        $address2 = post_string('address_line2', 200);
        $city = post_string('city', 80);
        $state = post_string('state', 80);
        $pincode = post_string('pincode', 10);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($name === '') {
            flash_set('company_error', 'Company name is required.', 'danger');
            redirect('modules/organization/companies.php');
        }

        if ($statusCode === 'DELETED') {
            $statusCode = 'ACTIVE';
        }

        $legacyStatus = $statusCode === 'ACTIVE' ? 'active' : 'inactive';

        try {
            $stmt = db()->prepare(
                'INSERT INTO companies
                  (name, legal_name, gstin, pan, phone, email, address_line1, address_line2, city, state, pincode, status, status_code, deleted_at)
                 VALUES
                  (:name, :legal_name, :gstin, :pan, :phone, :email, :address_line1, :address_line2, :city, :state, :pincode, :status, :status_code, NULL)'
            );
            $stmt->execute([
                'name' => $name,
                'legal_name' => $legalName !== '' ? $legalName : null,
                'gstin' => $gstin !== '' ? $gstin : null,
                'pan' => $pan !== '' ? $pan : null,
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? $email : null,
                'address_line1' => $address1 !== '' ? $address1 : null,
                'address_line2' => $address2 !== '' ? $address2 : null,
                'city' => $city !== '' ? $city : null,
                'state' => $state !== '' ? $state : null,
                'pincode' => $pincode !== '' ? $pincode : null,
                'status' => $legacyStatus,
                'status_code' => $statusCode,
            ]);

            $companyId = (int) db()->lastInsertId();
            log_audit('companies', 'create', $companyId, 'Created company ' . $name, [
                'entity' => 'company',
                'source' => 'UI',
                'before' => ['exists' => false],
                'after' => [
                    'id' => $companyId,
                    'name' => $name,
                    'gstin' => $gstin !== '' ? $gstin : null,
                    'status_code' => $statusCode,
                ],
            ]);
            flash_set('company_success', 'Company created successfully.', 'success');
        } catch (Throwable $exception) {
            flash_set('company_error', 'Unable to create company. GSTIN might already exist.', 'danger');
        }

        redirect('modules/organization/companies.php');
    }

    if ($action === 'update') {
        $companyId = post_int('company_id');
        $name = post_string('name', 120);
        $legalName = post_string('legal_name', 160);
        $gstin = strtoupper(post_string('gstin', 15));
        $pan = strtoupper(post_string('pan', 10));
        $phone = post_string('phone', 20);
        $email = strtolower(post_string('email', 120));
        $address1 = post_string('address_line1', 200);
        $address2 = post_string('address_line2', 200);
        $city = post_string('city', 80);
        $state = post_string('state', 80);
        $pincode = post_string('pincode', 10);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($companyId <= 0 || $name === '') {
            flash_set('company_error', 'Company ID and name are required.', 'danger');
            redirect('modules/organization/companies.php');
        }

        if (!$isSuperAdmin && $companyId !== $activeCompanyId) {
            flash_set('company_error', 'You can only update your own company.', 'danger');
            redirect('modules/organization/companies.php');
        }

        if (!$isSuperAdmin && $statusCode === 'DELETED') {
            flash_set('company_error', 'Only Super Admin can delete a company.', 'danger');
            redirect('modules/organization/companies.php?edit_id=' . $companyId);
        }

        $legacyStatus = $statusCode === 'ACTIVE' ? 'active' : 'inactive';
        $beforeStmt = db()->prepare(
            'SELECT id, name, gstin, city, state, status, status_code
             FROM companies
             WHERE id = :id
             LIMIT 1'
        );
        $beforeStmt->execute(['id' => $companyId]);
        $beforeCompany = $beforeStmt->fetch() ?: null;

        try {
            $stmt = db()->prepare(
                'UPDATE companies
                 SET name = :name,
                     legal_name = :legal_name,
                     gstin = :gstin,
                     pan = :pan,
                     phone = :phone,
                     email = :email,
                     address_line1 = :address_line1,
                     address_line2 = :address_line2,
                     city = :city,
                     state = :state,
                     pincode = :pincode,
                     status = :status,
                     status_code = :status_code,
                     deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
                 WHERE id = :id'
            );
            $stmt->execute([
                'name' => $name,
                'legal_name' => $legalName !== '' ? $legalName : null,
                'gstin' => $gstin !== '' ? $gstin : null,
                'pan' => $pan !== '' ? $pan : null,
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? $email : null,
                'address_line1' => $address1 !== '' ? $address1 : null,
                'address_line2' => $address2 !== '' ? $address2 : null,
                'city' => $city !== '' ? $city : null,
                'state' => $state !== '' ? $state : null,
                'pincode' => $pincode !== '' ? $pincode : null,
                'status' => $legacyStatus,
                'status_code' => $statusCode,
                'id' => $companyId,
            ]);

            log_audit('companies', 'update', $companyId, 'Updated company ' . $name, [
                'entity' => 'company',
                'source' => 'UI',
                'before' => is_array($beforeCompany) ? [
                    'name' => (string) ($beforeCompany['name'] ?? ''),
                    'gstin' => (string) ($beforeCompany['gstin'] ?? ''),
                    'city' => (string) ($beforeCompany['city'] ?? ''),
                    'state' => (string) ($beforeCompany['state'] ?? ''),
                    'status_code' => (string) ($beforeCompany['status_code'] ?? ''),
                ] : null,
                'after' => [
                    'name' => $name,
                    'gstin' => $gstin !== '' ? $gstin : null,
                    'city' => $city !== '' ? $city : null,
                    'state' => $state !== '' ? $state : null,
                    'status_code' => $statusCode,
                ],
            ]);
            flash_set('company_success', 'Company updated successfully.', 'success');
        } catch (Throwable $exception) {
            flash_set('company_error', 'Unable to update company. GSTIN might already exist.', 'danger');
        }

        redirect('modules/organization/companies.php');
    }

    if ($action === 'change_status') {
        $companyId = post_int('company_id');
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));

        if ($companyId <= 0) {
            flash_set('company_error', 'Invalid company selected.', 'danger');
            redirect('modules/organization/companies.php');
        }

        if (!$isSuperAdmin && $companyId !== $activeCompanyId) {
            flash_set('company_error', 'You can only change your own company status.', 'danger');
            redirect('modules/organization/companies.php');
        }

        if (!$isSuperAdmin && $nextStatus === 'DELETED') {
            flash_set('company_error', 'Only Super Admin can delete a company.', 'danger');
            redirect('modules/organization/companies.php');
        }

        $legacyStatus = $nextStatus === 'ACTIVE' ? 'active' : 'inactive';
        $beforeStatusStmt = db()->prepare(
            'SELECT status, status_code
             FROM companies
             WHERE id = :id
             LIMIT 1'
        );
        $beforeStatusStmt->execute(['id' => $companyId]);
        $beforeStatus = $beforeStatusStmt->fetch() ?: null;

        $stmt = db()->prepare(
            'UPDATE companies
             SET status = :status,
                 status_code = :status_code,
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $legacyStatus,
            'status_code' => $nextStatus,
            'id' => $companyId,
        ]);

        log_audit('companies', 'status', $companyId, 'Changed status to ' . $nextStatus, [
            'entity' => 'company',
            'source' => 'UI',
            'before' => is_array($beforeStatus) ? [
                'status' => (string) ($beforeStatus['status'] ?? ''),
                'status_code' => (string) ($beforeStatus['status_code'] ?? ''),
            ] : null,
            'after' => [
                'status' => $legacyStatus,
                'status_code' => $nextStatus,
            ],
        ]);
        flash_set('company_success', 'Company status updated.', 'success');
        redirect('modules/organization/companies.php');
    }
}

$editId = get_int('edit_id');
$editCompany = null;
if ($editId > 0) {
    $editStmt = $isSuperAdmin
        ? db()->prepare('SELECT * FROM companies WHERE id = :id LIMIT 1')
        : db()->prepare('SELECT * FROM companies WHERE id = :id AND id = :company_id LIMIT 1');

    $params = ['id' => $editId];
    if (!$isSuperAdmin) {
        $params['company_id'] = $activeCompanyId;
    }

    $editStmt->execute($params);
    $editCompany = $editStmt->fetch() ?: null;
}

$logoTargetCompanyId = 0;
if ($editCompany) {
    $logoTargetCompanyId = (int) ($editCompany['id'] ?? 0);
} elseif (!$isSuperAdmin) {
    $logoTargetCompanyId = $activeCompanyId;
}
$logoTargetCompany = null;
if ($logoTargetCompanyId > 0) {
    $logoStmt = db()->prepare('SELECT id, name FROM companies WHERE id = :id LIMIT 1');
    $logoStmt->execute(['id' => $logoTargetCompanyId]);
    $logoTargetCompany = $logoStmt->fetch() ?: null;
}
$businessLogoUrl = $logoTargetCompanyId > 0 ? company_logo_url($logoTargetCompanyId, 0) : null;

if ($isSuperAdmin) {
    $companiesStmt = db()->query(
        'SELECT c.*,
                (SELECT COUNT(*) FROM garages g WHERE g.company_id = c.id AND g.status_code <> "DELETED") AS garage_count,
                (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id AND u.status_code <> "DELETED") AS staff_count
         FROM companies c
         ORDER BY c.id DESC'
    );
    $companies = $companiesStmt->fetchAll();
} else {
    $companiesStmt = db()->prepare(
        'SELECT c.*,
                (SELECT COUNT(*) FROM garages g WHERE g.company_id = c.id AND g.status_code <> "DELETED") AS garage_count,
                (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id AND u.status_code <> "DELETED") AS staff_count
         FROM companies c
         WHERE c.id = :company_id
         LIMIT 1'
    );
    $companiesStmt->execute(['company_id' => $activeCompanyId]);
    $companies = $companiesStmt->fetchAll();
}

$statusChoices = ['ACTIVE', 'INACTIVE', 'DELETED'];
if (!$isSuperAdmin) {
    $statusChoices = ['ACTIVE', 'INACTIVE'];
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Company Master</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Company Master</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if ($canManage && ($isSuperAdmin || $editCompany !== null)): ?>
        <div class="card card-primary">
          <div class="card-header"><h3 class="card-title"><?= $editCompany ? 'Edit Company' : 'Add Company'; ?></h3></div>
          <form method="post">
            <div class="card-body row g-3">
              <?= csrf_field(); ?>
              <input type="hidden" name="_action" value="<?= $editCompany ? 'update' : 'create'; ?>" />
              <input type="hidden" name="company_id" value="<?= (int) ($editCompany['id'] ?? 0); ?>" />

              <div class="col-md-4">
                <label class="form-label">Company Name</label>
                <input type="text" name="name" class="form-control" required value="<?= e((string) ($editCompany['name'] ?? '')); ?>" />
              </div>
              <div class="col-md-4">
                <label class="form-label">Legal Name</label>
                <input type="text" name="legal_name" class="form-control" value="<?= e((string) ($editCompany['legal_name'] ?? '')); ?>" />
              </div>
              <div class="col-md-4">
                <label class="form-label">GSTIN</label>
                <input type="text" name="gstin" class="form-control" maxlength="15" value="<?= e((string) ($editCompany['gstin'] ?? '')); ?>" />
              </div>
              <div class="col-md-3">
                <label class="form-label">PAN</label>
                <input type="text" name="pan" class="form-control" maxlength="10" value="<?= e((string) ($editCompany['pan'] ?? '')); ?>" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" value="<?= e((string) ($editCompany['phone'] ?? '')); ?>" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= e((string) ($editCompany['email'] ?? '')); ?>" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Pincode</label>
                <input type="text" name="pincode" class="form-control" maxlength="10" value="<?= e((string) ($editCompany['pincode'] ?? '')); ?>" />
              </div>
              <div class="col-md-6">
                <label class="form-label">Address Line 1</label>
                <input type="text" name="address_line1" class="form-control" value="<?= e((string) ($editCompany['address_line1'] ?? '')); ?>" />
              </div>
              <div class="col-md-6">
                <label class="form-label">Address Line 2</label>
                <input type="text" name="address_line2" class="form-control" value="<?= e((string) ($editCompany['address_line2'] ?? '')); ?>" />
              </div>
              <div class="col-md-4">
                <label class="form-label">City</label>
                <input type="text" name="city" class="form-control" value="<?= e((string) ($editCompany['city'] ?? '')); ?>" />
              </div>
              <div class="col-md-4">
                <label class="form-label">State</label>
                <input type="text" name="state" class="form-control" value="<?= e((string) ($editCompany['state'] ?? '')); ?>" />
              </div>
              <div class="col-md-4">
                <label class="form-label">Status</label>
                <select name="status_code" class="form-select" required>
                  <?php foreach (status_options((string) ($editCompany['status_code'] ?? 'ACTIVE')) as $option): ?>
                    <?php if (in_array($option['value'], $statusChoices, true)): ?>
                      <option value="<?= e($option['value']); ?>" <?= $option['selected'] ? 'selected' : ''; ?>><?= e($option['value']); ?></option>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="card-footer d-flex gap-2">
              <button type="submit" class="btn btn-primary"><?= $editCompany ? 'Update Company' : 'Create Company'; ?></button>
              <?php if ($editCompany): ?>
                <a href="<?= e(url('modules/organization/companies.php')); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <?php if ($canManage): ?>
        <div class="card card-outline card-info">
          <div class="card-header"><h3 class="card-title">Business Logo</h3></div>
          <div class="card-body">
            <?php if ($logoTargetCompany === null): ?>
              <div class="text-muted">
                <?php if ($isSuperAdmin): ?>
                  Open a company in edit mode to upload or replace the business logo.
                <?php else: ?>
                  Company profile is not available for logo update.
                <?php endif; ?>
              </div>
            <?php else: ?>
              <div class="row g-3 align-items-start">
                <div class="col-md-3">
                  <div class="border rounded p-2 text-center bg-light">
                    <?php if ($businessLogoUrl !== null): ?>
                      <img src="<?= e($businessLogoUrl); ?>" alt="Business Logo" style="max-width: 100%; max-height: 120px;" />
                    <?php else: ?>
                      <div class="text-muted small py-4">No logo uploaded</div>
                    <?php endif; ?>
                  </div>
                  <small class="text-muted d-block mt-1">
                    Company: <?= e((string) ($logoTargetCompany['name'] ?? '')); ?>
                  </small>
                </div>
                <div class="col-md-9">
                  <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="_action" value="upload_logo" />
                    <input type="hidden" name="company_id" value="<?= (int) ($logoTargetCompany['id'] ?? 0); ?>" />
                    <div class="col-md-8">
                      <label class="form-label">Upload / Replace Logo</label>
                      <input type="file" name="company_logo_file" class="form-control" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" required />
                      <small class="text-muted">Allowed: PNG, JPG, WEBP. Max size: 2 MB.</small>
                    </div>
                    <div class="col-md-4">
                      <button type="submit" class="btn btn-info"><?= $businessLogoUrl !== null ? 'Replace Logo' : 'Upload Logo'; ?></button>
                    </div>
                  </form>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header"><h3 class="card-title">Company List</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>ID</th>
                <th>Company</th>
                <th>GSTIN</th>
                <th>Location</th>
                <th>Garages</th>
                <th>Staff</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($companies)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No companies found.</td></tr>
              <?php else: ?>
                <?php foreach ($companies as $company): ?>
                  <?php $canEditRecord = $isSuperAdmin || ((int) $company['id'] === $activeCompanyId); ?>
                  <tr>
                    <td><?= (int) $company['id']; ?></td>
                    <td>
                      <?= e((string) $company['name']); ?><br>
                      <small class="text-muted"><?= e((string) ($company['legal_name'] ?? '-')); ?></small>
                    </td>
                    <td><?= e((string) ($company['gstin'] ?? '-')); ?></td>
                    <td><?= e((string) (($company['city'] ?? '-') . ', ' . ($company['state'] ?? '-'))); ?></td>
                    <td><?= (int) ($company['garage_count'] ?? 0); ?></td>
                    <td><?= (int) ($company['staff_count'] ?? 0); ?></td>
                    <td><span class="badge text-bg-<?= e(status_badge_class((string) $company['status_code'])); ?>"><?= e(record_status_label((string) $company['status_code'])); ?></span></td>
                    <td class="d-flex gap-1">
                      <?php if ($canManage && $canEditRecord): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/organization/companies.php?edit_id=' . (int) $company['id'])); ?>">Edit</a>
                        <?php if ((string) $company['status_code'] !== 'DELETED'): ?>
                          <form method="post" class="d-inline" data-confirm="Change company status?">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="company_id" value="<?= (int) $company['id']; ?>" />
                            <input type="hidden" name="next_status" value="<?= e(((string) $company['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>" />
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><?= ((string) $company['status_code'] === 'ACTIVE') ? 'Inactivate' : 'Activate'; ?></button>
                          </form>
                        <?php endif; ?>
                        <?php if ($isSuperAdmin && (string) $company['status_code'] !== 'DELETED'): ?>
                          <form method="post" class="d-inline" data-confirm="Soft delete this company?">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="_action" value="change_status" />
                            <input type="hidden" name="company_id" value="<?= (int) $company['id']; ?>" />
                            <input type="hidden" name="next_status" value="DELETED" />
                            <button type="submit" class="btn btn-sm btn-outline-danger">Soft Delete</button>
                          </form>
                        <?php endif; ?>
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
