<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();

$page_title = 'My Profile';
$active_menu = 'profile';

$profileUser = current_user(true);
if ($profileUser === null) {
    flash_set('auth', 'Please sign in to continue.', 'warning');
    redirect('login.php');
}

$userId = (int) ($profileUser['id'] ?? 0);
$companyId = active_company_id();

$profileStmt = db()->prepare(
    'SELECT u.id, u.name, u.email, u.username, u.phone, u.status_code, u.is_active,
            u.last_login_at, u.created_at, u.updated_at,
            u.primary_garage_id, r.role_name, r.role_key,
            c.name AS company_name,
            g.name AS primary_garage_name
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     INNER JOIN companies c ON c.id = u.company_id
     LEFT JOIN garages g ON g.id = u.primary_garage_id
     WHERE u.id = :user_id
       AND u.company_id = :company_id
     LIMIT 1'
);
$profileStmt->execute([
    'user_id' => $userId,
    'company_id' => $companyId,
]);
$profile = $profileStmt->fetch() ?: $profileUser;

$assignedGarages = is_array($profileUser['garages'] ?? null) ? $profileUser['garages'] : [];
$sessionIp = request_client_ip();
$sessionUserAgent = request_user_agent();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6"><h3 class="mb-0">My Profile</h3></div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item active">My Profile</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="row g-3">
        <div class="col-lg-7">
          <div class="card card-outline card-primary">
            <div class="card-header">
              <h3 class="card-title">User Information</h3>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-6">
                  <div class="small text-muted">Full Name</div>
                  <div class="fw-semibold"><?= e((string) ($profile['name'] ?? '-')); ?></div>
                </div>
                <div class="col-md-6">
                  <div class="small text-muted">Username</div>
                  <div class="fw-semibold"><?= e((string) ($profile['username'] ?? '-')); ?></div>
                </div>
                <div class="col-md-6">
                  <div class="small text-muted">Email</div>
                  <div class="fw-semibold"><?= e((string) ($profile['email'] ?? '-')); ?></div>
                </div>
                <div class="col-md-6">
                  <div class="small text-muted">Phone</div>
                  <div class="fw-semibold"><?= e((string) (($profile['phone'] ?? '') !== '' ? $profile['phone'] : '-')); ?></div>
                </div>
                <div class="col-md-6">
                  <div class="small text-muted">Role</div>
                  <div class="fw-semibold"><?= e((string) ($profile['role_name'] ?? '-')); ?></div>
                  <div class="small text-muted"><?= e((string) ($profile['role_key'] ?? '')); ?></div>
                </div>
                <div class="col-md-6">
                  <div class="small text-muted">Status</div>
                  <span class="badge text-bg-<?= e(status_badge_class((string) ($profile['status_code'] ?? 'ACTIVE'))); ?>">
                    <?= e(record_status_label((string) ($profile['status_code'] ?? 'ACTIVE'))); ?>
                  </span>
                </div>
                <div class="col-md-6">
                  <div class="small text-muted">Company</div>
                  <div class="fw-semibold"><?= e((string) ($profile['company_name'] ?? '-')); ?></div>
                </div>
                <div class="col-md-6">
                  <div class="small text-muted">Primary Garage</div>
                  <div class="fw-semibold"><?= e((string) (($profile['primary_garage_name'] ?? '') !== '' ? $profile['primary_garage_name'] : '-')); ?></div>
                </div>
                <div class="col-md-6">
                  <div class="small text-muted">Created At</div>
                  <div class="fw-semibold"><?= e((string) (($profile['created_at'] ?? '') !== '' ? $profile['created_at'] : '-')); ?></div>
                </div>
                <div class="col-md-6">
                  <div class="small text-muted">Last Login</div>
                  <div class="fw-semibold"><?= e((string) (($profile['last_login_at'] ?? '') !== '' ? $profile['last_login_at'] : '-')); ?></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-5">
          <div class="card card-outline card-info">
            <div class="card-header">
              <h3 class="card-title">Assigned Garages</h3>
            </div>
            <div class="card-body">
              <?php if ($assignedGarages === []): ?>
                <div class="text-muted">No garage assignments found.</div>
              <?php else: ?>
                <div class="d-flex flex-wrap gap-2">
                  <?php foreach ($assignedGarages as $garage): ?>
                    <span class="badge text-bg-light border">
                      <?= e((string) ($garage['name'] ?? '')); ?>
                      <?php if (!empty($garage['code'])): ?>
                        (<?= e((string) $garage['code']); ?>)
                      <?php endif; ?>
                    </span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="card card-outline card-secondary">
            <div class="card-header">
              <h3 class="card-title">Current Session</h3>
            </div>
            <div class="card-body">
              <div class="small text-muted mb-1">IP Address</div>
              <div class="fw-semibold text-break mb-3"><?= e($sessionIp !== '' ? $sessionIp : '-'); ?></div>
              <div class="small text-muted mb-1">User Agent</div>
              <div class="text-break small"><?= e($sessionUserAgent !== '' ? $sessionUserAgent : '-'); ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
