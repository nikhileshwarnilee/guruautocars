<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/workflow.php';

if (!billing_can_view()) {
    flash_set('access_denied', 'You do not have permission to access invoice settings.', 'danger');
    redirect('dashboard.php');
}

$page_title = 'Invoice Settings';
$active_menu = 'billing.settings';
$companyId = active_company_id();
$garageId = active_garage_id();
$actorUserId = (int) ($_SESSION['user_id'] ?? 0);
$canManageSettings = billing_has_permission(['invoice.manage', 'settings.manage']);

function billing_invoice_logo_upload_relative_dir(): string
{
    return 'assets/uploads/invoice_logos';
}

function billing_invoice_logo_allowed_mimes(): array
{
    return [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];
}

function billing_invoice_logo_remove_file(?string $relativePath): void
{
    $path = trim((string) $relativePath);
    if ($path === '') {
        return;
    }

    $normalized = str_replace('\\', '/', $path);
    $normalized = ltrim($normalized, '/');
    if (!str_starts_with($normalized, billing_invoice_logo_upload_relative_dir() . '/')) {
        return;
    }

    $fullPath = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function billing_invoice_logo_store_upload(array $file, int $companyId, int $garageId, int $maxBytes = 2097152): array
{
    if ($companyId <= 0 || $garageId <= 0) {
        return ['ok' => false, 'message' => 'Active company/garage is required for logo upload.'];
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

    $allowed = billing_invoice_logo_allowed_mimes();
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'message' => 'Only PNG, JPG, and WEBP logos are allowed.'];
    }

    $relativeDir = billing_invoice_logo_upload_relative_dir();
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
    $fileName = 'invoice_logo_c' . $companyId . '_g' . $garageId . '_' . date('YmdHis') . '_' . $suffix . '.' . $extension;
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
    $action = (string) ($_POST['_action'] ?? '');

    if (!$canManageSettings) {
        flash_set('billing_error', 'You do not have permission to update invoice settings.', 'danger');
        redirect('modules/billing/invoice_settings.php');
    }

    if ($action === 'save_print_settings') {
        $defaults = billing_invoice_print_default_settings();
        $payload = [];
        foreach (array_keys($defaults) as $settingKey) {
            $payload[$settingKey] = isset($_POST[$settingKey]);
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            flash_set('billing_error', 'Unable to serialize invoice settings. Please retry.', 'danger');
            redirect('modules/billing/invoice_settings.php');
        }

        $settingId = system_setting_upsert_value(
            $companyId,
            $garageId,
            'BILLING',
            'invoice_print_settings_json',
            $encoded,
            'JSON',
            'ACTIVE',
            $actorUserId
        );
        if ($settingId <= 0) {
            flash_set('billing_error', 'Unable to save invoice settings right now.', 'danger');
            redirect('modules/billing/invoice_settings.php');
        }

        log_audit('billing', 'invoice_print_settings_update', $settingId, 'Updated invoice print visibility settings.', [
            'entity' => 'invoice_print_settings',
            'source' => 'UI',
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'metadata' => $payload,
        ]);
        flash_set('billing_success', 'Invoice display settings saved.', 'success');
        redirect('modules/billing/invoice_settings.php');
    }

    if ($action === 'upload_invoice_logo') {
        $uploadResult = billing_invoice_logo_store_upload($_FILES['invoice_logo_file'] ?? [], $companyId, $garageId);
        if (!(bool) ($uploadResult['ok'] ?? false)) {
            flash_set('billing_error', (string) ($uploadResult['message'] ?? 'Unable to upload invoice logo.'), 'danger');
            redirect('modules/billing/invoice_settings.php');
        }

        $newRelativePath = (string) ($uploadResult['relative_path'] ?? '');
        if ($newRelativePath === '') {
            flash_set('billing_error', 'Logo upload failed. Please retry.', 'danger');
            redirect('modules/billing/invoice_settings.php');
        }

        $existingPath = billing_invoice_logo_relative_path($companyId, $garageId);
        $settingId = system_setting_upsert_value(
            $companyId,
            $garageId,
            'BILLING',
            'invoice_logo_path',
            $newRelativePath,
            'STRING',
            'ACTIVE',
            $actorUserId
        );
        if ($settingId <= 0) {
            billing_invoice_logo_remove_file($newRelativePath);
            flash_set('billing_error', 'Unable to save invoice logo setting. Please retry.', 'danger');
            redirect('modules/billing/invoice_settings.php');
        }

        if ($existingPath !== null && $existingPath !== $newRelativePath) {
            billing_invoice_logo_remove_file($existingPath);
        }

        log_audit('billing', 'invoice_logo_upload', $settingId, 'Uploaded invoice print logo.', [
            'entity' => 'invoice_logo',
            'source' => 'UI',
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'before' => ['invoice_logo_path' => $existingPath],
            'after' => ['invoice_logo_path' => $newRelativePath],
            'metadata' => [
                'image_width' => (int) ($uploadResult['width'] ?? 0),
                'image_height' => (int) ($uploadResult['height'] ?? 0),
            ],
        ]);
        flash_set('billing_success', 'Invoice logo updated successfully.', 'success');
        redirect('modules/billing/invoice_settings.php');
    }

    if ($action === 'remove_invoice_logo') {
        $existingPath = billing_invoice_logo_relative_path($companyId, $garageId);
        if ($existingPath === null) {
            flash_set('billing_success', 'No custom invoice logo is configured for this garage.', 'success');
            redirect('modules/billing/invoice_settings.php');
        }

        $settingId = system_setting_upsert_value(
            $companyId,
            $garageId,
            'BILLING',
            'invoice_logo_path',
            null,
            'STRING',
            'ACTIVE',
            $actorUserId
        );
        if ($settingId <= 0) {
            flash_set('billing_error', 'Unable to remove invoice logo setting right now.', 'danger');
            redirect('modules/billing/invoice_settings.php');
        }

        billing_invoice_logo_remove_file($existingPath);
        log_audit('billing', 'invoice_logo_remove', $settingId, 'Removed invoice print logo.', [
            'entity' => 'invoice_logo',
            'source' => 'UI',
            'company_id' => $companyId,
            'garage_id' => $garageId,
            'before' => ['invoice_logo_path' => $existingPath],
            'after' => ['invoice_logo_path' => null],
        ]);
        flash_set('billing_success', 'Custom invoice logo removed. Company logo fallback will be used.', 'success');
        redirect('modules/billing/invoice_settings.php');
    }
}

$printSettings = billing_invoice_print_settings($companyId, $garageId);
$customInvoiceLogoPath = billing_invoice_logo_relative_path($companyId, $garageId);
$customInvoiceLogoUrl = billing_invoice_logo_url($companyId, $garageId);
$fallbackCompanyLogoUrl = company_logo_url($companyId, $garageId);
$effectiveLogoUrl = $customInvoiceLogoUrl ?? $fallbackCompanyLogoUrl;

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Invoice Settings</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/billing/index.php')); ?>">Billing</a></li>
            <li class="breadcrumb-item active">Invoice Settings</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="card card-outline card-info">
        <div class="card-header"><h3 class="card-title">Invoice Display Controls</h3></div>
        <form method="post">
          <div class="card-body row g-3">
            <?= csrf_field(); ?>
            <input type="hidden" name="_action" value="save_print_settings" />

            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="show_company_logo" name="show_company_logo" <?= !empty($printSettings['show_company_logo']) ? 'checked' : ''; ?> <?= $canManageSettings ? '' : 'disabled'; ?> />
                <label class="form-check-label" for="show_company_logo">Show company/invoice logo in header</label>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="show_company_gstin" name="show_company_gstin" <?= !empty($printSettings['show_company_gstin']) ? 'checked' : ''; ?> <?= $canManageSettings ? '' : 'disabled'; ?> />
                <label class="form-check-label" for="show_company_gstin">Show company GSTIN</label>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="show_customer_gstin" name="show_customer_gstin" <?= !empty($printSettings['show_customer_gstin']) ? 'checked' : ''; ?> <?= $canManageSettings ? '' : 'disabled'; ?> />
                <label class="form-check-label" for="show_customer_gstin">Show customer GSTIN</label>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="show_recommendation_note" name="show_recommendation_note" <?= !empty($printSettings['show_recommendation_note']) ? 'checked' : ''; ?> <?= $canManageSettings ? '' : 'disabled'; ?> />
                <label class="form-check-label" for="show_recommendation_note">Show recommendation note section</label>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="show_next_service_reminders" name="show_next_service_reminders" <?= !empty($printSettings['show_next_service_reminders']) ? 'checked' : ''; ?> <?= $canManageSettings ? '' : 'disabled'; ?> />
                <label class="form-check-label" for="show_next_service_reminders">Show next recommended service section</label>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="show_paid_outstanding" name="show_paid_outstanding" <?= !empty($printSettings['show_paid_outstanding']) ? 'checked' : ''; ?> <?= $canManageSettings ? '' : 'disabled'; ?> />
                <label class="form-check-label" for="show_paid_outstanding">Show Paid and Outstanding in final totals</label>
              </div>
            </div>

            <div class="col-12">
              <small class="text-muted">Controls apply to invoice print/PDF in the currently active garage.</small>
            </div>
          </div>
          <?php if ($canManageSettings): ?>
            <div class="card-footer">
              <button type="submit" class="btn btn-primary">Save Invoice Display Settings</button>
            </div>
          <?php else: ?>
            <div class="card-footer text-muted">View only. Contact admin to update invoice settings.</div>
          <?php endif; ?>
        </form>
      </div>

      <div class="card card-outline card-secondary">
        <div class="card-header"><h3 class="card-title">Invoice Logo</h3></div>
        <div class="card-body">
          <div class="row g-3 align-items-start">
            <div class="col-md-4">
              <div class="border rounded p-3 bg-light text-center">
                <?php if ($effectiveLogoUrl !== null): ?>
                  <img src="<?= e($effectiveLogoUrl); ?>" alt="Invoice Logo" style="max-width: 100%; max-height: 120px;" />
                <?php else: ?>
                  <div class="text-muted small py-4">No logo configured</div>
                <?php endif; ?>
              </div>
              <div class="small mt-2 text-muted">
                <?php if ($customInvoiceLogoPath !== null): ?>
                  Custom invoice logo is active for this garage.
                <?php elseif ($fallbackCompanyLogoUrl !== null): ?>
                  Using company logo fallback.
                <?php else: ?>
                  Upload a logo to show branding on invoices.
                <?php endif; ?>
              </div>
            </div>

            <div class="col-md-8">
              <?php if ($canManageSettings): ?>
                <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="upload_invoice_logo" />
                  <div class="col-md-8">
                    <label class="form-label">Upload / Replace Invoice Logo</label>
                    <input type="file" name="invoice_logo_file" class="form-control" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" required />
                    <small class="text-muted">Allowed: PNG, JPG, WEBP. Max size: 2 MB.</small>
                  </div>
                  <div class="col-md-4">
                    <button type="submit" class="btn btn-info"><?= $customInvoiceLogoPath !== null ? 'Replace Logo' : 'Upload Logo'; ?></button>
                  </div>
                </form>

                <?php if ($customInvoiceLogoPath !== null): ?>
                  <form method="post" class="mt-3" data-confirm="Remove custom invoice logo and use company logo fallback?">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="_action" value="remove_invoice_logo" />
                    <button type="submit" class="btn btn-outline-danger btn-sm">Remove Custom Invoice Logo</button>
                  </form>
                <?php endif; ?>
              <?php else: ?>
                <div class="text-muted">View only. Contact admin to upload or replace invoice logo.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
