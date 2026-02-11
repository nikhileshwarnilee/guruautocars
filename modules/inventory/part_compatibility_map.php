<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('part_master.view');

$page_title = 'Part Compatibility Mapping';
$active_menu = 'inventory.parts_master';
$canManage = has_permission('part_master.manage');
$companyId = active_company_id();

$partId = get_int('part_id');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $partId = post_int('part_id');
}

if ($partId <= 0) {
    flash_set('parts_error', 'Part not found for compatibility mapping.', 'danger');
    redirect('modules/inventory/parts_master.php');
}

$partStmt = db()->prepare(
    'SELECT p.id, p.part_sku, p.part_name, p.status_code, p.unit, p.selling_price, pc.category_name
     FROM parts p
     LEFT JOIN part_categories pc ON pc.id = p.category_id
     WHERE p.id = :id
       AND p.company_id = :company_id
     LIMIT 1'
);
$partStmt->execute([
    'id' => $partId,
    'company_id' => $companyId,
]);
$part = $partStmt->fetch() ?: null;

if (!$part) {
    flash_set('parts_error', 'Part not found for active company.', 'danger');
    redirect('modules/inventory/parts_master.php');
}

$visCompatibilityEnabled = table_columns('vis_part_compatibility') !== []
    && table_columns('vis_variants') !== []
    && table_columns('vis_models') !== []
    && table_columns('vis_brands') !== [];

$variants = [];
$variantLookup = [];
if ($visCompatibilityEnabled) {
    $variants = db()->query(
        'SELECT vv.id, vv.variant_name, vm.model_name, vb.brand_name
         FROM vis_variants vv
         INNER JOIN vis_models vm ON vm.id = vv.model_id
         INNER JOIN vis_brands vb ON vb.id = vm.brand_id
         WHERE vv.status_code = "ACTIVE"
           AND vm.status_code = "ACTIVE"
           AND vb.status_code = "ACTIVE"
         ORDER BY vb.brand_name ASC, vm.model_name ASC, vv.variant_name ASC'
    )->fetchAll();

    foreach ($variants as $variant) {
        $variantId = (int) ($variant['id'] ?? 0);
        if ($variantId > 0) {
            $variantLookup[$variantId] = true;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!$canManage) {
        flash_set('parts_error', 'You do not have permission to modify compatibility mappings.', 'danger');
        redirect('modules/inventory/part_compatibility_map.php?part_id=' . $partId);
    }

    if (!$visCompatibilityEnabled) {
        flash_set('parts_error', 'VIS compatibility is unavailable. Mapping was not changed.', 'danger');
        redirect('modules/inventory/part_compatibility_map.php?part_id=' . $partId);
    }

    $action = (string) ($_POST['_action'] ?? '');
    if ($action === 'save_mappings') {
        $rawVariantIds = $_POST['variant_ids'] ?? [];
        $compatibilityNote = post_string('compatibility_note', 255);

        $selectedVariantIds = [];
        if (is_array($rawVariantIds)) {
            foreach ($rawVariantIds as $rawVariantId) {
                $variantId = (int) $rawVariantId;
                if ($variantId > 0 && isset($variantLookup[$variantId])) {
                    $selectedVariantIds[$variantId] = true;
                }
            }
        }
        $selectedVariantIds = array_keys($selectedVariantIds);
        $selectedVariantSet = [];
        foreach ($selectedVariantIds as $variantId) {
            $selectedVariantSet[(int) $variantId] = true;
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $existingStmt = $pdo->prepare(
                'SELECT id, variant_id, status_code, compatibility_note
                 FROM vis_part_compatibility
                 WHERE company_id = :company_id
                   AND part_id = :part_id'
            );
            $existingStmt->execute([
                'company_id' => $companyId,
                'part_id' => $partId,
            ]);
            $existingMappings = $existingStmt->fetchAll();

            $existingByVariant = [];
            $activeVariantIds = [];
            foreach ($existingMappings as $mapping) {
                $variantId = (int) ($mapping['variant_id'] ?? 0);
                if ($variantId <= 0) {
                    continue;
                }
                $existingByVariant[$variantId] = $mapping;
                if ((string) ($mapping['status_code'] ?? '') === 'ACTIVE') {
                    $activeVariantIds[$variantId] = true;
                }
            }

            $insertStmt = $pdo->prepare(
                'INSERT INTO vis_part_compatibility
                  (company_id, variant_id, part_id, compatibility_note, status_code, deleted_at)
                 VALUES
                  (:company_id, :variant_id, :part_id, :compatibility_note, "ACTIVE", NULL)'
            );
            $updateStmt = $pdo->prepare(
                'UPDATE vis_part_compatibility
                 SET status_code = "ACTIVE",
                     compatibility_note = :compatibility_note,
                     deleted_at = NULL
                 WHERE id = :id
                   AND company_id = :company_id'
            );
            $inactivateStmt = $pdo->prepare(
                'UPDATE vis_part_compatibility
                 SET status_code = "INACTIVE",
                     deleted_at = NULL
                 WHERE id = :id
                   AND company_id = :company_id'
            );

            foreach ($selectedVariantIds as $variantId) {
                $variantId = (int) $variantId;
                if (isset($existingByVariant[$variantId])) {
                    $existing = $existingByVariant[$variantId];
                    $noteToSave = $compatibilityNote !== ''
                        ? $compatibilityNote
                        : (string) ($existing['compatibility_note'] ?? '');

                    $updateStmt->execute([
                        'compatibility_note' => $noteToSave !== '' ? $noteToSave : null,
                        'id' => (int) $existing['id'],
                        'company_id' => $companyId,
                    ]);
                    continue;
                }

                $insertStmt->execute([
                    'company_id' => $companyId,
                    'variant_id' => $variantId,
                    'part_id' => $partId,
                    'compatibility_note' => $compatibilityNote !== '' ? $compatibilityNote : null,
                ]);
            }

            foreach ($activeVariantIds as $variantId => $_active) {
                if (isset($selectedVariantSet[(int) $variantId])) {
                    continue;
                }

                $existing = $existingByVariant[(int) $variantId] ?? null;
                if (!$existing) {
                    continue;
                }

                $inactivateStmt->execute([
                    'id' => (int) $existing['id'],
                    'company_id' => $companyId,
                ]);
            }

            $pdo->commit();

            log_audit('parts_master', 'map_compatibility', $partId, 'Updated part compatibility mapping', [
                'entity' => 'part',
                'source' => 'UI',
                'after' => [
                    'part_id' => $partId,
                    'selected_variant_count' => count($selectedVariantIds),
                ],
                'metadata' => [
                    'selected_variant_ids' => $selectedVariantIds,
                ],
            ]);

            flash_set('parts_success', 'Compatibility mappings saved for this part.', 'success');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            flash_set('parts_error', 'Unable to save compatibility mappings.', 'danger');
        }

        redirect('modules/inventory/part_compatibility_map.php?part_id=' . $partId);
    }
}

$mappings = [];
$activeMappedVariantIds = [];
if ($visCompatibilityEnabled) {
    $mappingStmt = db()->prepare(
        'SELECT c.id, c.variant_id, c.compatibility_note, c.status_code, c.created_at,
                vv.variant_name, vm.model_name, vb.brand_name
         FROM vis_part_compatibility c
         INNER JOIN vis_variants vv ON vv.id = c.variant_id
         INNER JOIN vis_models vm ON vm.id = vv.model_id
         INNER JOIN vis_brands vb ON vb.id = vm.brand_id
         WHERE c.company_id = :company_id
           AND c.part_id = :part_id
         ORDER BY vb.brand_name ASC, vm.model_name ASC, vv.variant_name ASC'
    );
    $mappingStmt->execute([
        'company_id' => $companyId,
        'part_id' => $partId,
    ]);
    $mappings = $mappingStmt->fetchAll();

    foreach ($mappings as $mapping) {
        if ((string) ($mapping['status_code'] ?? '') !== 'ACTIVE') {
            continue;
        }
        $variantId = (int) ($mapping['variant_id'] ?? 0);
        if ($variantId > 0) {
            $activeMappedVariantIds[$variantId] = true;
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">Map Part Compatibility</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/inventory/parts_master.php')); ?>">Parts Master</a></li>
            <li class="breadcrumb-item active">Compatibility Mapping</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="card card-outline card-primary">
        <div class="card-body">
          <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
            <div>
              <div class="fw-semibold"><?= e((string) $part['part_name']); ?> (<code><?= e((string) $part['part_sku']); ?></code>)</div>
              <div class="text-muted small">
                Category: <?= e((string) ($part['category_name'] ?? '-')); ?> |
                Price: <?= e(format_currency((float) ($part['selling_price'] ?? 0))); ?> |
                Status: <?= e((string) ($part['status_code'] ?? '-')); ?>
              </div>
            </div>
            <div class="d-flex gap-2">
              <a href="<?= e(url('modules/inventory/parts_master.php?edit_id=' . (int) $part['id'])); ?>" class="btn btn-outline-primary btn-sm">Edit Part</a>
              <a href="<?= e(url('modules/inventory/parts_master.php')); ?>" class="btn btn-outline-secondary btn-sm">Back to Parts</a>
            </div>
          </div>
        </div>
      </div>

      <?php if (!$canManage): ?>
        <div class="alert alert-info">Compatibility mapping is read-only for your role.</div>
      <?php endif; ?>

      <?php if (!$visCompatibilityEnabled): ?>
        <div class="alert alert-light border">
          VIS is optional and currently unavailable in this setup. Existing part usage continues unchanged.
        </div>
      <?php else: ?>
        <div class="alert alert-info">
          Mappings saved here are stored in VIS compatibility and directly used for Job Card compatible-part suggestions.
          <a href="<?= e(url('modules/vis/compatibility.php')); ?>" class="alert-link">Open VIS Compatibility</a>
        </div>

        <div class="row g-3">
          <div class="col-lg-5">
            <div class="card card-info">
              <div class="card-header"><h3 class="card-title">Select Compatible VIS Variants</h3></div>
              <form method="post">
                <div class="card-body">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="_action" value="save_mappings" />
                  <input type="hidden" name="part_id" value="<?= (int) $part['id']; ?>" />

                  <div class="mb-2">
                    <label for="variant-search" class="form-label">Search Variant</label>
                    <input id="variant-search" type="text" class="form-control" placeholder="Filter by brand / model / variant" />
                  </div>

                  <div class="mb-2">
                    <label for="variant-select" class="form-label">Vehicle Variants (Multiple)</label>
                    <select id="variant-select" name="variant_ids[]" class="form-select" multiple size="16" <?= $canManage ? '' : 'disabled'; ?>>
                      <?php foreach ($variants as $variant): ?>
                        <?php $variantId = (int) $variant['id']; ?>
                        <?php $variantText = (string) $variant['brand_name'] . ' / ' . (string) $variant['model_name'] . ' / ' . (string) $variant['variant_name']; ?>
                        <option
                          value="<?= $variantId; ?>"
                          data-search="<?= e(strtolower($variantText)); ?>"
                          <?= isset($activeMappedVariantIds[$variantId]) ? 'selected' : ''; ?>
                        >
                          <?= e($variantText); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="mb-0">
                    <label for="compatibility-note" class="form-label">Compatibility Note (Optional)</label>
                    <input
                      id="compatibility-note"
                      type="text"
                      name="compatibility_note"
                      class="form-control"
                      maxlength="255"
                      placeholder="Applied to selected mappings (leave blank to keep existing notes)"
                      <?= $canManage ? '' : 'disabled'; ?>
                    />
                    <small class="text-muted">Saving updates active mappings to exactly match selected variants.</small>
                  </div>
                </div>
                <?php if ($canManage): ?>
                  <div class="card-footer d-flex gap-2">
                    <button type="submit" class="btn btn-info">Save Mapping</button>
                    <a href="<?= e(url('modules/inventory/parts_master.php')); ?>" class="btn btn-outline-secondary">Done</a>
                  </div>
                <?php endif; ?>
              </form>
            </div>
          </div>

          <div class="col-lg-7">
            <div class="card">
              <div class="card-header"><h3 class="card-title">Current Compatibility Rows</h3></div>
              <div class="card-body table-responsive p-0">
                <table class="table table-striped mb-0">
                  <thead>
                    <tr>
                      <th>Variant</th>
                      <th>Note</th>
                      <th>Status</th>
                      <th>Mapped On</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($mappings)): ?>
                      <tr><td colspan="4" class="text-center text-muted py-4">No compatibility rows for this part.</td></tr>
                    <?php else: ?>
                      <?php foreach ($mappings as $mapping): ?>
                        <tr>
                          <td><?= e((string) $mapping['brand_name']); ?> / <?= e((string) $mapping['model_name']); ?> / <?= e((string) $mapping['variant_name']); ?></td>
                          <td><?= e((string) ($mapping['compatibility_note'] ?? '-')); ?></td>
                          <td><span class="badge text-bg-<?= e(status_badge_class((string) $mapping['status_code'])); ?>"><?= e((string) $mapping['status_code']); ?></span></td>
                          <td><?= e((string) ($mapping['created_at'] ?? '-')); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<script>
  (function () {
    var searchInput = document.getElementById('variant-search');
    var selectNode = document.getElementById('variant-select');
    if (!searchInput || !selectNode) {
      return;
    }

    searchInput.addEventListener('input', function () {
      var query = searchInput.value.trim().toLowerCase();
      Array.prototype.forEach.call(selectNode.options, function (option) {
        var haystack = (option.getAttribute('data-search') || option.text || '').toLowerCase();
        option.hidden = query !== '' && haystack.indexOf(query) === -1;
      });
    });
  })();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
