<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('vis.view');

$page_title = 'VIS Compatibility Mapping';
$active_menu = 'vis.compatibility';
$canManage = has_permission('vis.manage');
$companyId = active_company_id();

$partsStmt = db()->prepare(
    'SELECT id, part_name, part_sku
     FROM parts
     WHERE company_id = :company_id
       AND status_code = "ACTIVE"
     ORDER BY part_name ASC'
);
$partsStmt->execute(['company_id' => $companyId]);
$parts = $partsStmt->fetchAll();

$servicesStmt = db()->prepare(
    'SELECT id, service_name, service_code
     FROM services
     WHERE company_id = :company_id
       AND status_code = "ACTIVE"
     ORDER BY service_name ASC'
);
$servicesStmt->execute(['company_id' => $companyId]);
$services = $servicesStmt->fetchAll();

$variants = db()->query(
    'SELECT v.id, v.variant_name, m.model_name, b.brand_name
     FROM vis_variants v
     INNER JOIN vis_models m ON m.id = v.model_id
     INNER JOIN vis_brands b ON b.id = m.brand_id
     WHERE v.status_code = "ACTIVE"
       AND m.status_code = "ACTIVE"
       AND b.status_code = "ACTIVE"
     ORDER BY b.brand_name ASC, m.model_name ASC, v.variant_name ASC'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!$canManage) {
        flash_set('vis_map_error', 'You do not have permission to modify VIS mappings.', 'danger');
        redirect('modules/vis/compatibility.php');
    }

    $action = (string) ($_POST['_action'] ?? '');

    if ($action === 'create_compatibility') {
        $variantId = post_int('variant_id');
        $partId = post_int('part_id');
        $note = post_string('compatibility_note', 255);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($variantId <= 0 || $partId <= 0) {
            flash_set('vis_map_error', 'Variant and part are required for compatibility mapping.', 'danger');
            redirect('modules/vis/compatibility.php');
        }

        try {
            $stmt = db()->prepare(
                'INSERT INTO vis_part_compatibility
                  (company_id, variant_id, part_id, compatibility_note, status_code, deleted_at)
                 VALUES
                  (:company_id, :variant_id, :part_id, :compatibility_note, :status_code, :deleted_at)'
            );
            $stmt->execute([
                'company_id' => $companyId,
                'variant_id' => $variantId,
                'part_id' => $partId,
                'compatibility_note' => $note !== '' ? $note : null,
                'status_code' => $statusCode,
                'deleted_at' => $statusCode === 'DELETED' ? date('Y-m-d H:i:s') : null,
            ]);

            log_audit('vis_mapping', 'create_part_compatibility', (int) db()->lastInsertId(), 'Created VIS part compatibility mapping');
            flash_set('vis_map_success', 'Part compatibility mapping created.', 'success');
        } catch (Throwable $exception) {
            flash_set('vis_map_error', 'Unable to create compatibility mapping. Duplicate mapping exists.', 'danger');
        }

        redirect('modules/vis/compatibility.php');
    }

    if ($action === 'update_compatibility') {
        $mappingId = post_int('mapping_id');
        $note = post_string('compatibility_note', 255);
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        $stmt = db()->prepare(
            'UPDATE vis_part_compatibility
             SET compatibility_note = :compatibility_note,
                 status_code = :status_code,
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'compatibility_note' => $note !== '' ? $note : null,
            'status_code' => $statusCode,
            'id' => $mappingId,
            'company_id' => $companyId,
        ]);

        log_audit('vis_mapping', 'update_part_compatibility', $mappingId, 'Updated VIS part compatibility mapping');
        flash_set('vis_map_success', 'Part compatibility mapping updated.', 'success');
        redirect('modules/vis/compatibility.php');
    }

    if ($action === 'create_service_part_map') {
        $serviceId = post_int('service_id');
        $partId = post_int('part_id');
        $isRequired = post_int('is_required', 1) === 1 ? 1 : 0;
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        if ($serviceId <= 0 || $partId <= 0) {
            flash_set('vis_map_error', 'Service and part are required for service-to-part mapping.', 'danger');
            redirect('modules/vis/compatibility.php');
        }

        try {
            $stmt = db()->prepare(
                'INSERT INTO vis_service_part_map
                  (company_id, service_id, part_id, is_required, status_code, deleted_at)
                 VALUES
                  (:company_id, :service_id, :part_id, :is_required, :status_code, :deleted_at)'
            );
            $stmt->execute([
                'company_id' => $companyId,
                'service_id' => $serviceId,
                'part_id' => $partId,
                'is_required' => $isRequired,
                'status_code' => $statusCode,
                'deleted_at' => $statusCode === 'DELETED' ? date('Y-m-d H:i:s') : null,
            ]);

            log_audit('vis_mapping', 'create_service_part_map', (int) db()->lastInsertId(), 'Created VIS service-to-part mapping');
            flash_set('vis_map_success', 'Service-to-part mapping created.', 'success');
        } catch (Throwable $exception) {
            flash_set('vis_map_error', 'Unable to create service mapping. Duplicate mapping exists.', 'danger');
        }

        redirect('modules/vis/compatibility.php');
    }

    if ($action === 'update_service_part_map') {
        $serviceMapId = post_int('service_map_id');
        $isRequired = post_int('is_required', 1) === 1 ? 1 : 0;
        $statusCode = normalize_status_code((string) ($_POST['status_code'] ?? 'ACTIVE'));

        $stmt = db()->prepare(
            'UPDATE vis_service_part_map
             SET is_required = :is_required,
                 status_code = :status_code,
                 deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'is_required' => $isRequired,
            'status_code' => $statusCode,
            'id' => $serviceMapId,
            'company_id' => $companyId,
        ]);

        log_audit('vis_mapping', 'update_service_part_map', $serviceMapId, 'Updated VIS service-to-part mapping');
        flash_set('vis_map_success', 'Service-to-part mapping updated.', 'success');
        redirect('modules/vis/compatibility.php');
    }

    if ($action === 'change_status') {
        $entity = (string) ($_POST['entity'] ?? '');
        $recordId = post_int('record_id');
        $nextStatus = normalize_status_code((string) ($_POST['next_status'] ?? 'INACTIVE'));

        if ($entity === 'compatibility') {
            $stmt = db()->prepare('UPDATE vis_part_compatibility SET status_code = :status_code, deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END WHERE id = :id AND company_id = :company_id');
            $stmt->execute([
                'status_code' => $nextStatus,
                'id' => $recordId,
                'company_id' => $companyId,
            ]);
            log_audit('vis_mapping', 'status_part_compatibility', $recordId, 'Changed compatibility status to ' . $nextStatus);
        }

        if ($entity === 'service_map') {
            $stmt = db()->prepare('UPDATE vis_service_part_map SET status_code = :status_code, deleted_at = CASE WHEN :status_code = "DELETED" THEN NOW() ELSE NULL END WHERE id = :id AND company_id = :company_id');
            $stmt->execute([
                'status_code' => $nextStatus,
                'id' => $recordId,
                'company_id' => $companyId,
            ]);
            log_audit('vis_mapping', 'status_service_part_map', $recordId, 'Changed service map status to ' . $nextStatus);
        }

        flash_set('vis_map_success', 'Mapping status updated.', 'success');
        redirect('modules/vis/compatibility.php');
    }
}

$editCompatibilityId = get_int('edit_compatibility_id');
$editCompatibility = null;
if ($editCompatibilityId > 0) {
    $editCompatibilityStmt = db()->prepare(
        'SELECT *
         FROM vis_part_compatibility
         WHERE id = :id
           AND company_id = :company_id
         LIMIT 1'
    );
    $editCompatibilityStmt->execute([
        'id' => $editCompatibilityId,
        'company_id' => $companyId,
    ]);
    $editCompatibility = $editCompatibilityStmt->fetch() ?: null;
}

$editServiceMapId = get_int('edit_service_map_id');
$editServiceMap = null;
if ($editServiceMapId > 0) {
    $editServiceMapStmt = db()->prepare(
        'SELECT *
         FROM vis_service_part_map
         WHERE id = :id
           AND company_id = :company_id
         LIMIT 1'
    );
    $editServiceMapStmt->execute([
        'id' => $editServiceMapId,
        'company_id' => $companyId,
    ]);
    $editServiceMap = $editServiceMapStmt->fetch() ?: null;
}

$compatibilityList = db()->prepare(
    'SELECT c.*, v.variant_name, m.model_name, b.brand_name, p.part_name, p.part_sku
     FROM vis_part_compatibility c
     INNER JOIN vis_variants v ON v.id = c.variant_id
     INNER JOIN vis_models m ON m.id = v.model_id
     INNER JOIN vis_brands b ON b.id = m.brand_id
     INNER JOIN parts p ON p.id = c.part_id
     WHERE c.company_id = :company_id
     ORDER BY c.id DESC'
);
$compatibilityList->execute(['company_id' => $companyId]);
$compatibilities = $compatibilityList->fetchAll();

$servicePartMapList = db()->prepare(
    'SELECT sm.*, s.service_name, s.service_code, p.part_name, p.part_sku
     FROM vis_service_part_map sm
     INNER JOIN services s ON s.id = sm.service_id
     INNER JOIN parts p ON p.id = sm.part_id
     WHERE sm.company_id = :company_id
     ORDER BY sm.id DESC'
);
$servicePartMapList->execute(['company_id' => $companyId]);
$serviceMappings = $servicePartMapList->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">VIS Compatibility Mapping</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">VIS Mapping</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <?php if (!$canManage): ?>
        <div class="alert alert-info">VIS mapping is in read-only mode for your role.</div>
      <?php endif; ?>

      <?php if ($canManage): ?>
        <div class="row g-3">
          <div class="col-lg-6">
            <div class="card card-primary">
              <div class="card-header"><h3 class="card-title"><?= $editCompatibility ? 'Edit Part Compatibility' : 'Map Part Compatibility'; ?></h3></div>
              <form method="post">
                <div class="card-body row g-2">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="<?= $editCompatibility ? 'update_compatibility' : 'create_compatibility'; ?>" />
                  <input type="hidden" name="mapping_id" value="<?= (int) ($editCompatibility['id'] ?? 0); ?>" />

                  <div class="col-md-6">
                    <label class="form-label">Vehicle Variant</label>
                    <select name="variant_id" class="form-select" required <?= $editCompatibility ? 'disabled' : ''; ?>>
                      <option value="">Select Variant</option>
                      <?php foreach ($variants as $variant): ?>
                        <option value="<?= (int) $variant['id']; ?>" <?= ((int) ($editCompatibility['variant_id'] ?? 0) === (int) $variant['id']) ? 'selected' : ''; ?>>
                          <?= e((string) $variant['brand_name']); ?> / <?= e((string) $variant['model_name']); ?> / <?= e((string) $variant['variant_name']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <?php if ($editCompatibility): ?><input type="hidden" name="variant_id" value="<?= (int) $editCompatibility['variant_id']; ?>" /><?php endif; ?>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Part</label>
                    <select name="part_id" class="form-select" required <?= $editCompatibility ? 'disabled' : ''; ?>>
                      <option value="">Select Part</option>
                      <?php foreach ($parts as $part): ?>
                        <option value="<?= (int) $part['id']; ?>" <?= ((int) ($editCompatibility['part_id'] ?? 0) === (int) $part['id']) ? 'selected' : ''; ?>>
                          <?= e((string) $part['part_name']); ?> (<?= e((string) $part['part_sku']); ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <?php if ($editCompatibility): ?><input type="hidden" name="part_id" value="<?= (int) $editCompatibility['part_id']; ?>" /><?php endif; ?>
                  </div>

                  <div class="col-md-8">
                    <label class="form-label">Compatibility Note</label>
                    <input type="text" name="compatibility_note" class="form-control" value="<?= e((string) ($editCompatibility['compatibility_note'] ?? '')); ?>" />
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select name="status_code" class="form-select">
                      <?php foreach (status_options((string) ($editCompatibility['status_code'] ?? 'ACTIVE')) as $option): ?>
                        <option value="<?= e($option['value']); ?>" <?= $option['selected'] ? 'selected' : ''; ?>><?= e($option['value']); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="card-footer d-flex gap-2">
                  <button type="submit" class="btn btn-primary"><?= $editCompatibility ? 'Update Mapping' : 'Create Mapping'; ?></button>
                  <?php if ($editCompatibility): ?>
                    <a href="<?= e(url('modules/vis/compatibility.php')); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
                  <?php endif; ?>
                </div>
              </form>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="card card-info">
              <div class="card-header"><h3 class="card-title"><?= $editServiceMap ? 'Edit Service-to-Part Mapping' : 'Map Service-to-Part'; ?></h3></div>
              <form method="post">
                <div class="card-body row g-2">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="<?= $editServiceMap ? 'update_service_part_map' : 'create_service_part_map'; ?>" />
                  <input type="hidden" name="service_map_id" value="<?= (int) ($editServiceMap['id'] ?? 0); ?>" />

                  <div class="col-md-6">
                    <label class="form-label">Service</label>
                    <select name="service_id" class="form-select" required <?= $editServiceMap ? 'disabled' : ''; ?>>
                      <option value="">Select Service</option>
                      <?php foreach ($services as $service): ?>
                        <option value="<?= (int) $service['id']; ?>" <?= ((int) ($editServiceMap['service_id'] ?? 0) === (int) $service['id']) ? 'selected' : ''; ?>>
                          <?= e((string) $service['service_name']); ?> (<?= e((string) $service['service_code']); ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <?php if ($editServiceMap): ?><input type="hidden" name="service_id" value="<?= (int) $editServiceMap['service_id']; ?>" /><?php endif; ?>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Part</label>
                    <select name="part_id" class="form-select" required <?= $editServiceMap ? 'disabled' : ''; ?>>
                      <option value="">Select Part</option>
                      <?php foreach ($parts as $part): ?>
                        <option value="<?= (int) $part['id']; ?>" <?= ((int) ($editServiceMap['part_id'] ?? 0) === (int) $part['id']) ? 'selected' : ''; ?>>
                          <?= e((string) $part['part_name']); ?> (<?= e((string) $part['part_sku']); ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <?php if ($editServiceMap): ?><input type="hidden" name="part_id" value="<?= (int) $editServiceMap['part_id']; ?>" /><?php endif; ?>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Required?</label>
                    <select name="is_required" class="form-select">
                      <option value="1" <?= ((int) ($editServiceMap['is_required'] ?? 1) === 1) ? 'selected' : ''; ?>>Required</option>
                      <option value="0" <?= ((int) ($editServiceMap['is_required'] ?? 1) === 0) ? 'selected' : ''; ?>>Optional</option>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select name="status_code" class="form-select">
                      <?php foreach (status_options((string) ($editServiceMap['status_code'] ?? 'ACTIVE')) as $option): ?>
                        <option value="<?= e($option['value']); ?>" <?= $option['selected'] ? 'selected' : ''; ?>><?= e($option['value']); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="card-footer d-flex gap-2">
                  <button type="submit" class="btn btn-info"><?= $editServiceMap ? 'Update Mapping' : 'Create Mapping'; ?></button>
                  <?php if ($editServiceMap): ?>
                    <a href="<?= e(url('modules/vis/compatibility.php')); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
                  <?php endif; ?>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="card mt-3">
        <div class="card-header"><h3 class="card-title">Part Compatibility Mapping</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>Variant</th>
                <th>Part</th>
                <th>Note</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($compatibilities)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No compatibility mappings.</td></tr>
              <?php else: ?>
                <?php foreach ($compatibilities as $compatibility): ?>
                  <tr>
                    <td><?= e((string) $compatibility['brand_name']); ?> / <?= e((string) $compatibility['model_name']); ?> / <?= e((string) $compatibility['variant_name']); ?></td>
                    <td><?= e((string) $compatibility['part_name']); ?> (<?= e((string) $compatibility['part_sku']); ?>)</td>
                    <td><?= e((string) ($compatibility['compatibility_note'] ?? '-')); ?></td>
                    <td><span class="badge text-bg-<?= e(status_badge_class((string) $compatibility['status_code'])); ?>"><?= e((string) $compatibility['status_code']); ?></span></td>
                    <td class="d-flex gap-1">
                      <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/vis/compatibility.php?edit_compatibility_id=' . (int) $compatibility['id'])); ?>">Edit</a>
                      <?php if ($canManage): ?>
                        <form method="post" class="d-inline" data-confirm="Change compatibility status?">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="_action" value="change_status" />
                          <input type="hidden" name="entity" value="compatibility" />
                          <input type="hidden" name="record_id" value="<?= (int) $compatibility['id']; ?>" />
                          <input type="hidden" name="next_status" value="<?= e(((string) $compatibility['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>" />
                          <button type="submit" class="btn btn-sm btn-outline-secondary">Toggle</button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header"><h3 class="card-title">Service-to-Part Mapping</h3></div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped mb-0">
            <thead>
              <tr>
                <th>Service</th>
                <th>Part</th>
                <th>Required</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($serviceMappings)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No service-to-part mappings.</td></tr>
              <?php else: ?>
                <?php foreach ($serviceMappings as $mapping): ?>
                  <tr>
                    <td><?= e((string) $mapping['service_name']); ?> (<?= e((string) $mapping['service_code']); ?>)</td>
                    <td><?= e((string) $mapping['part_name']); ?> (<?= e((string) $mapping['part_sku']); ?>)</td>
                    <td><?= ((int) $mapping['is_required'] === 1) ? 'Yes' : 'No'; ?></td>
                    <td><span class="badge text-bg-<?= e(status_badge_class((string) $mapping['status_code'])); ?>"><?= e((string) $mapping['status_code']); ?></span></td>
                    <td class="d-flex gap-1">
                      <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/vis/compatibility.php?edit_service_map_id=' . (int) $mapping['id'])); ?>">Edit</a>
                      <?php if ($canManage): ?>
                        <form method="post" class="d-inline" data-confirm="Change service map status?">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="_action" value="change_status" />
                          <input type="hidden" name="entity" value="service_map" />
                          <input type="hidden" name="record_id" value="<?= (int) $mapping['id']; ?>" />
                          <input type="hidden" name="next_status" value="<?= e(((string) $mapping['status_code'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE'); ?>" />
                          <button type="submit" class="btn btn-sm btn-outline-secondary">Toggle</button>
                        </form>
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
