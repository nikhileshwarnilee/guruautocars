<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_permission('audit.view');

$page_title = 'Audit Logs';
$active_menu = 'system.audit';

$companyId = active_company_id();
$activeGarageId = active_garage_id();
$roleKey = (string) ($_SESSION['role_key'] ?? '');
$isOwnerScope = analytics_is_owner_role($roleKey);

$auditColumns = table_columns('audit_logs');
$hasGarageColumn = in_array('garage_id', $auditColumns, true);
$hasRoleColumn = in_array('role_key', $auditColumns, true);
$hasEntityColumn = in_array('entity_name', $auditColumns, true);
$hasSourceColumn = in_array('source_channel', $auditColumns, true);
$hasBeforeColumn = in_array('before_snapshot', $auditColumns, true);
$hasAfterColumn = in_array('after_snapshot', $auditColumns, true);

$garageOptions = analytics_accessible_garages($companyId, $isOwnerScope);
$garageIds = array_values(array_filter(array_map(static fn (array $garage): int => (int) ($garage['id'] ?? 0), $garageOptions), static fn (int $id): bool => $id > 0));
$allowAllGarages = $isOwnerScope && count($garageIds) > 1;
$garageRequested = isset($_GET['garage_id']) ? get_int('garage_id', $activeGarageId) : $activeGarageId;
$selectedGarageId = analytics_resolve_scope_garage_id($garageOptions, $activeGarageId, $garageRequested, $allowAllGarages);
$scopeGarageLabel = analytics_scope_garage_label($garageOptions, $selectedGarageId);

$fromDate = analytics_parse_iso_date($_GET['from'] ?? null, date('Y-m-01'));
$toDate = analytics_parse_iso_date($_GET['to'] ?? null, date('Y-m-d'));
if ($toDate < $fromDate) {
    $toDate = $fromDate;
}

$moduleFilter = trim((string) ($_GET['module_name'] ?? ''));
$entityFilter = trim((string) ($_GET['entity_name'] ?? ''));
$actionFilter = trim((string) ($_GET['action_name'] ?? ''));
$sourceFilter = strtoupper(trim((string) ($_GET['source_channel'] ?? '')));
$userFilter = get_int('user_id', 0);
$query = trim((string) ($_GET['q'] ?? ''));

$params = [
    'company_id' => $companyId,
    'from_at' => $fromDate . ' 00:00:00',
    'to_at' => $toDate . ' 23:59:59',
];

$where = [
    'al.company_id = :company_id',
    'al.created_at BETWEEN :from_at AND :to_at',
];

if ($hasGarageColumn) {
    if ($selectedGarageId > 0) {
        $where[] = 'al.garage_id = :garage_id';
        $params['garage_id'] = $selectedGarageId;
    } elseif (!empty($garageIds)) {
        $garagePlaceholders = [];
        foreach ($garageIds as $index => $garageId) {
            $paramKey = 'scope_garage_' . $index;
            $garagePlaceholders[] = ':' . $paramKey;
            $params[$paramKey] = $garageId;
        }
        $where[] = 'al.garage_id IN (' . implode(', ', $garagePlaceholders) . ')';
    }
}

if ($moduleFilter !== '') {
    $where[] = 'al.module_name = :module_name';
    $params['module_name'] = $moduleFilter;
}

if ($hasEntityColumn && $entityFilter !== '') {
    $where[] = 'al.entity_name = :entity_name';
    $params['entity_name'] = $entityFilter;
}

if ($actionFilter !== '') {
    $where[] = 'al.action_name = :action_name';
    $params['action_name'] = $actionFilter;
}

if ($hasSourceColumn && $sourceFilter !== '') {
    $where[] = 'al.source_channel = :source_channel';
    $params['source_channel'] = $sourceFilter;
}

if ($userFilter > 0) {
    $where[] = 'al.user_id = :user_id';
    $params['user_id'] = $userFilter;
}

if ($query !== '') {
    $where[] = '(al.details LIKE :query OR al.module_name LIKE :query OR al.action_name LIKE :query)';
    $params['query'] = '%' . $query . '%';
}

$entitySelect = $hasEntityColumn ? 'al.entity_name' : 'al.module_name';
$sourceSelect = $hasSourceColumn ? 'al.source_channel' : 'NULL';
$roleSelect = $hasRoleColumn ? 'al.role_key' : 'NULL';
$garageSelect = $hasGarageColumn ? 'al.garage_id' : 'NULL';
$garageNameSelect = $hasGarageColumn ? 'g.name' : 'NULL';
$garageJoin = $hasGarageColumn ? 'LEFT JOIN garages g ON g.id = al.garage_id' : '';
$beforeSelect = $hasBeforeColumn ? 'al.before_snapshot' : 'NULL';
$afterSelect = $hasAfterColumn ? 'al.after_snapshot' : 'NULL';

$listSql =
    'SELECT al.id, al.created_at, al.module_name, al.action_name, al.reference_id, al.details,
            ' . $entitySelect . ' AS entity_name,
            ' . $sourceSelect . ' AS source_channel,
            ' . $roleSelect . ' AS role_key,
            ' . $garageSelect . ' AS garage_id,
            ' . $beforeSelect . ' AS before_snapshot,
            ' . $afterSelect . ' AS after_snapshot,
            u.name AS user_name, u.username AS username,
            ' . $garageNameSelect . ' AS garage_name
     FROM audit_logs al
     LEFT JOIN users u ON u.id = al.user_id
     ' . $garageJoin . '
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY al.id DESC
     LIMIT 300';

$listStmt = db()->prepare($listSql);
$listStmt->execute($params);
$auditRows = $listStmt->fetchAll();

$modulesStmt = db()->prepare(
    'SELECT DISTINCT module_name
     FROM audit_logs
     WHERE company_id = :company_id
     ORDER BY module_name ASC'
);
$modulesStmt->execute(['company_id' => $companyId]);
$moduleOptions = $modulesStmt->fetchAll();

$actionsStmt = db()->prepare(
    'SELECT DISTINCT action_name
     FROM audit_logs
     WHERE company_id = :company_id
     ORDER BY action_name ASC'
);
$actionsStmt->execute(['company_id' => $companyId]);
$actionOptions = $actionsStmt->fetchAll();

$usersStmt = db()->prepare(
    'SELECT DISTINCT u.id, u.name
     FROM audit_logs al
     INNER JOIN users u ON u.id = al.user_id
     WHERE al.company_id = :company_id
     ORDER BY u.name ASC'
);
$usersStmt->execute(['company_id' => $companyId]);
$userOptions = $usersStmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-7">
          <h3 class="mb-0">Audit Logs</h3>
          <small class="text-muted">Immutable, read-only compliance stream. Scope: <?= e($scopeGarageLabel); ?></small>
        </div>
        <div class="col-sm-5">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">Audit Logs</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="card card-outline card-primary mb-3">
        <div class="card-header"><h3 class="card-title">Filter Scope</h3></div>
        <form method="get">
          <div class="card-body row g-2">
            <div class="col-md-2">
              <label class="form-label">Garage</label>
              <select name="garage_id" class="form-select">
                <?php if ($allowAllGarages): ?>
                  <option value="0" <?= $selectedGarageId === 0 ? 'selected' : ''; ?>>All Accessible</option>
                <?php endif; ?>
                <?php foreach ($garageOptions as $garage): ?>
                  <option value="<?= (int) $garage['id']; ?>" <?= ((int) $garage['id'] === $selectedGarageId) ? 'selected' : ''; ?>>
                    <?= e((string) $garage['name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">From</label>
              <input type="date" name="from" class="form-control" value="<?= e($fromDate); ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label">To</label>
              <input type="date" name="to" class="form-control" value="<?= e($toDate); ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label">Module</label>
              <select name="module_name" class="form-select">
                <option value="">All</option>
                <?php foreach ($moduleOptions as $module): ?>
                  <?php $moduleName = (string) ($module['module_name'] ?? ''); ?>
                  <option value="<?= e($moduleName); ?>" <?= $moduleFilter === $moduleName ? 'selected' : ''; ?>><?= e($moduleName); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Action</label>
              <select name="action_name" class="form-select">
                <option value="">All</option>
                <?php foreach ($actionOptions as $action): ?>
                  <?php $actionName = (string) ($action['action_name'] ?? ''); ?>
                  <option value="<?= e($actionName); ?>" <?= $actionFilter === $actionName ? 'selected' : ''; ?>><?= e($actionName); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">User</label>
              <select name="user_id" class="form-select">
                <option value="0">All</option>
                <?php foreach ($userOptions as $user): ?>
                  <option value="<?= (int) $user['id']; ?>" <?= ((int) $user['id'] === $userFilter) ? 'selected' : ''; ?>>
                    <?= e((string) $user['name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Entity</label>
              <input type="text" name="entity_name" class="form-control" value="<?= e($entityFilter); ?>" placeholder="invoice, job_card">
            </div>
            <div class="col-md-2">
              <label class="form-label">Source</label>
              <select name="source_channel" class="form-select">
                <option value="">All</option>
                <option value="UI" <?= $sourceFilter === 'UI' ? 'selected' : ''; ?>>UI</option>
                <option value="SYSTEM" <?= $sourceFilter === 'SYSTEM' ? 'selected' : ''; ?>>SYSTEM</option>
                <option value="JOB-CLOSE" <?= $sourceFilter === 'JOB-CLOSE' ? 'selected' : ''; ?>>JOB-CLOSE</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Search</label>
              <input type="text" name="q" class="form-control" value="<?= e($query); ?>" placeholder="Details, module, action">
            </div>
          </div>
          <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-primary">Apply Filters</button>
            <a href="<?= e(url('modules/system/audit_logs.php')); ?>" class="btn btn-outline-secondary">Reset</a>
          </div>
        </form>
      </div>

      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Audit Entries (Latest 300)</h3>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th>When</th>
                <th>Who</th>
                <th>Role</th>
                <th>Where</th>
                <th>Module</th>
                <th>Entity</th>
                <th>Action</th>
                <th>Source</th>
                <th>Reference</th>
                <th>Details</th>
                <th>Snapshots</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($auditRows)): ?>
                <tr><td colspan="11" class="text-center text-muted py-4">No audit entries for selected scope.</td></tr>
              <?php else: ?>
                <?php foreach ($auditRows as $row): ?>
                  <?php
                    $beforeJson = (string) ($row['before_snapshot'] ?? '');
                    $afterJson = (string) ($row['after_snapshot'] ?? '');
                    $beforeText = $beforeJson !== '' ? json_encode(json_decode($beforeJson, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '';
                    $afterText = $afterJson !== '' ? json_encode(json_decode($afterJson, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '';
                    $who = (string) ($row['user_name'] ?? '');
                    if ($who === '') {
                        $who = 'System';
                    }
                  ?>
                  <tr>
                    <td><?= e((string) $row['created_at']); ?></td>
                    <td>
                      <?= e($who); ?>
                      <?php if (!empty($row['username'])): ?>
                        <div class="small text-muted">@<?= e((string) $row['username']); ?></div>
                      <?php endif; ?>
                    </td>
                    <td><?= e((string) ($row['role_key'] ?? '-')); ?></td>
                    <td><?= e((string) ($row['garage_name'] ?? 'Company')); ?></td>
                    <td><code><?= e((string) $row['module_name']); ?></code></td>
                    <td><?= e((string) ($row['entity_name'] ?? '-')); ?></td>
                    <td><span class="badge text-bg-secondary"><?= e((string) $row['action_name']); ?></span></td>
                    <td><?= e((string) ($row['source_channel'] ?? '-')); ?></td>
                    <td><?= $row['reference_id'] !== null ? (int) $row['reference_id'] : '-'; ?></td>
                    <td><?= e((string) ($row['details'] ?? '-')); ?></td>
                    <td>
                      <?php if ($beforeText === '' && $afterText === ''): ?>
                        <span class="text-muted">-</span>
                      <?php else: ?>
                        <details>
                          <summary class="small">View</summary>
                          <?php if ($beforeText !== '' && $beforeText !== 'null'): ?>
                            <div class="small text-muted mt-1">Before</div>
                            <pre class="small mb-2"><?= e((string) $beforeText); ?></pre>
                          <?php endif; ?>
                          <?php if ($afterText !== '' && $afterText !== 'null'): ?>
                            <div class="small text-muted">After</div>
                            <pre class="small mb-0"><?= e((string) $afterText); ?></pre>
                          <?php endif; ?>
                        </details>
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
