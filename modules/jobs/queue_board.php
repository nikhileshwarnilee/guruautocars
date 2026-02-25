<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_login();
require_once __DIR__ . '/workflow.php';

if (!has_permission('job.view')) {
    flash_set('access_denied', 'You do not have permission to access the vehicle queue board.', 'danger');
    redirect('dashboard.php');
}

$page_title = 'Vehicle Queue Board';
$active_menu = 'jobs.queue';

$companyId = active_company_id();
$activeGarageId = active_garage_id();
$roleKey = (string) (($_SESSION['role_key'] ?? '') ?: ((current_user() ?: [])['role_key'] ?? ''));
$isOwnerScope = analytics_is_owner_role($roleKey);

$garageOptions = analytics_accessible_garages($companyId, $isOwnerScope);
$garageIds = array_values(array_filter(array_map(
    static fn (array $garage): int => (int) ($garage['id'] ?? 0),
    $garageOptions
), static fn (int $id): bool => $id > 0));
$allowAllGarages = $isOwnerScope && count($garageIds) > 1;

$requestedGarageId = isset($_GET['garage_id']) ? get_int('garage_id', $activeGarageId) : $activeGarageId;
$selectedGarageId = analytics_resolve_scope_garage_id($garageOptions, $activeGarageId, $requestedGarageId, $allowAllGarages);
$selectedGarageLabel = analytics_scope_garage_label($garageOptions, $selectedGarageId);

$canMove = has_permission('job.edit') || has_permission('job.update') || has_permission('job.manage') || has_permission('job.close');
$boardStatuses = ['OPEN', 'IN_PROGRESS', 'WAITING_PARTS', 'COMPLETED', 'CLOSED'];
$statusLabels = [
    'OPEN' => 'Open',
    'IN_PROGRESS' => 'In Progress',
    'WAITING_PARTS' => 'Waiting Parts',
    'COMPLETED' => 'Completed',
    'CLOSED' => 'Closed',
];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="app-main">
  <div class="app-content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-sm-6">
          <h3 class="mb-0">Vehicle Queue Board</h3>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-end">
            <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')); ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/jobs/index.php')); ?>">Job Cards</a></li>
            <li class="breadcrumb-item active">Queue Board</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="app-content">
    <div class="container-fluid">
      <div class="card card-outline card-primary mb-3">
        <div class="card-body d-flex flex-wrap align-items-end justify-content-between gap-2">
          <form method="get" class="row g-2 align-items-end">
            <?php if ($allowAllGarages || count($garageOptions) > 1): ?>
              <div class="col-md-8">
                <label class="form-label">Garage Scope</label>
                <select name="garage_id" class="form-select" data-searchable-select="1">
                  <?php if ($allowAllGarages): ?>
                    <option value="0" <?= $selectedGarageId === 0 ? 'selected' : ''; ?>>All Accessible Garages</option>
                  <?php endif; ?>
                  <?php foreach ($garageOptions as $garage): ?>
                    <option value="<?= (int) ($garage['id'] ?? 0); ?>" <?= ((int) ($garage['id'] ?? 0) === $selectedGarageId) ? 'selected' : ''; ?>>
                      <?= e((string) ($garage['name'] ?? 'Garage')); ?> (<?= e((string) ($garage['code'] ?? '-')); ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php else: ?>
              <div class="col-md-8">
                <label class="form-label">Garage Scope</label>
                <input type="text" class="form-control" value="<?= e($selectedGarageLabel); ?>" readonly />
                <input type="hidden" name="garage_id" value="<?= (int) $selectedGarageId; ?>" />
              </div>
            <?php endif; ?>
            <div class="col-md-4 d-flex gap-2">
              <button type="submit" class="btn btn-primary">Apply</button>
              <a href="<?= e(url('modules/jobs/queue_board.php')); ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
          </form>
          <div class="text-muted small d-flex align-items-center gap-2">
            <span class="badge text-bg-light border">Scope: <?= e($selectedGarageLabel); ?></span>
            <button type="button" class="btn btn-sm btn-outline-primary" id="queue-refresh-btn">Refresh</button>
          </div>
        </div>
      </div>

      <div class="alert alert-light border py-2 mb-3">
        <div class="d-flex justify-content-between flex-wrap gap-2">
          <span>Drag jobs across columns to update workflow status. Backend transition and close rules stay enforced.</span>
          <span class="text-muted" id="queue-last-updated">Last updated: -</span>
        </div>
      </div>

      <div class="queue-board-wrap" id="queue-board-root">
        <?php foreach ($boardStatuses as $statusKey): ?>
          <section class="queue-col" data-status="<?= e($statusKey); ?>">
            <header class="queue-col-head">
              <h4><?= e((string) ($statusLabels[$statusKey] ?? $statusKey)); ?></h4>
              <span class="badge text-bg-light border" data-queue-count="<?= e($statusKey); ?>">0</span>
            </header>
            <div class="queue-col-body" data-queue-body="<?= e($statusKey); ?>">
              <div class="queue-empty text-muted">Loading...</div>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</main>

<style>
  .queue-board-wrap {
    display: grid;
    grid-template-columns: repeat(5, minmax(240px, 1fr));
    gap: 12px;
    align-items: start;
    overflow-x: auto;
    padding-bottom: 4px;
  }

  .queue-col {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    min-height: 420px;
    display: flex;
    flex-direction: column;
  }

  .queue-col-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 12px;
    border-bottom: 1px solid #e2e8f0;
    background: #eff6ff;
    border-radius: 10px 10px 0 0;
  }

  .queue-col-head h4 {
    margin: 0;
    font-size: 0.92rem;
    color: #1e293b;
    font-weight: 600;
  }

  .queue-col-body {
    padding: 10px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-height: 370px;
  }

  .queue-col-body.is-drop-target {
    outline: 2px dashed #2563eb;
    outline-offset: -2px;
    background: #eff6ff;
    border-radius: 8px;
  }

  .queue-card {
    background: #ffffff;
    border: 1px solid #dbe3ef;
    border-left: 4px solid #93c5fd;
    border-radius: 8px;
    padding: 10px;
    cursor: grab;
  }

  .queue-card.is-dragging {
    opacity: 0.55;
  }

  .queue-card-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 6px;
  }

  .queue-card-title {
    font-size: 0.88rem;
    font-weight: 700;
    color: #0f172a;
    text-decoration: none;
  }

  .queue-card-title:hover {
    color: #1d4ed8;
  }

  .queue-meta,
  .queue-submeta {
    font-size: 0.78rem;
    color: #475569;
    line-height: 1.45;
  }

  .queue-submeta {
    color: #64748b;
  }

  .queue-empty {
    border: 1px dashed #d0dae7;
    border-radius: 8px;
    padding: 18px 10px;
    text-align: center;
    font-size: 0.84rem;
  }

  @media (max-width: 1399.98px) {
    .queue-board-wrap {
      grid-template-columns: repeat(5, minmax(230px, 1fr));
    }
  }
</style>

<script>
  (function () {
    var root = document.getElementById('queue-board-root');
    if (!root) {
      return;
    }

    var canMove = <?= $canMove ? 'true' : 'false'; ?>;
    var selectedGarageId = <?= (int) $selectedGarageId; ?>;
    var refreshBtn = document.getElementById('queue-refresh-btn');
    var lastUpdatedNode = document.getElementById('queue-last-updated');
    var apiUrl = <?= json_encode(url('modules/jobs/queue_board_api.php'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    var jobViewBase = <?= json_encode(url('modules/jobs/view.php?id='), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    var csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    var activeDrag = null;
    var refreshHandle = null;
    var statusColors = {
      OPEN: '#94a3b8',
      IN_PROGRESS: '#2563eb',
      WAITING_PARTS: '#f59e0b',
      COMPLETED: '#16a34a',
      CLOSED: '#0f172a'
    };

    function esc(value) {
      return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function formatDate(value) {
      if (!value) {
        return '-';
      }
      var text = String(value);
      return text.length > 16 ? text.substring(0, 16).replace('T', ' ') : text.replace('T', ' ');
    }

    function formatStatusLabel(status) {
      return String(status || '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, function (char) { return char.toUpperCase(); });
    }

    function applyLastUpdated(timestamp) {
      if (!lastUpdatedNode) {
        return;
      }
      var stamp = timestamp ? new Date(timestamp) : new Date();
      if (isNaN(stamp.getTime())) {
        lastUpdatedNode.textContent = 'Last updated: just now';
        return;
      }
      lastUpdatedNode.textContent = 'Last updated: ' + stamp.toLocaleString();
    }

    function bindDragHandlers(card, status) {
      if (!canMove || !card) {
        return;
      }

      card.setAttribute('draggable', 'true');
      card.addEventListener('dragstart', function (event) {
        activeDrag = {
          jobId: parseInt(card.getAttribute('data-job-id') || '0', 10),
          fromStatus: status
        };
        card.classList.add('is-dragging');
        if (event.dataTransfer) {
          event.dataTransfer.effectAllowed = 'move';
          event.dataTransfer.setData('text/plain', String(activeDrag.jobId || ''));
        }
      });
      card.addEventListener('dragend', function () {
        card.classList.remove('is-dragging');
        activeDrag = null;
        var bodies = root.querySelectorAll('.queue-col-body');
        for (var i = 0; i < bodies.length; i++) {
          bodies[i].classList.remove('is-drop-target');
        }
      });
    }

    function renderColumn(status, jobs) {
      var body = root.querySelector('[data-queue-body="' + status + '"]');
      var counter = root.querySelector('[data-queue-count="' + status + '"]');
      if (!body || !counter) {
        return;
      }

      var safeJobs = Array.isArray(jobs) ? jobs : [];
      counter.textContent = String(safeJobs.length);

      if (safeJobs.length === 0) {
        body.innerHTML = '<div class="queue-empty text-muted">No jobs in ' + esc(formatStatusLabel(status)) + '.</div>';
        return;
      }

      body.innerHTML = '';
      for (var index = 0; index < safeJobs.length; index++) {
        var job = safeJobs[index] || {};
        var jobId = parseInt(job.id || '0', 10) || 0;
        var priority = String(job.priority || 'MEDIUM').toUpperCase();
        var color = statusColors[status] || '#93c5fd';
        var card = document.createElement('article');
        card.className = 'queue-card';
        card.setAttribute('data-job-id', String(jobId));
        card.style.borderLeftColor = color;
        card.innerHTML =
          '<div class="queue-card-top">' +
            '<a class="queue-card-title" href="' + esc(jobViewBase + jobId) + '">#' + esc(job.job_number || ('JOB-' + jobId)) + '</a>' +
            '<span class="badge text-bg-light border">' + esc(priority) + '</span>' +
          '</div>' +
          '<div class="queue-meta">' +
            '<div><strong>' + esc(job.registration_no || '-') + '</strong> | ' + esc(job.brand || '-') + ' ' + esc(job.model || '') + '</div>' +
            '<div>' + esc(job.customer_name || '-') + '</div>' +
          '</div>' +
          '<div class="queue-submeta mt-1">' +
            '<div>Mechanic: ' + esc(job.mechanic_names || 'Unassigned') + '</div>' +
            '<div>Opened: ' + esc(formatDate(job.opened_at || '')) + '</div>' +
            '<div>Promised: ' + esc(formatDate(job.promised_at || '')) + '</div>' +
          '</div>';
        bindDragHandlers(card, status);
        body.appendChild(card);
      }
    }

    function notify(message, type) {
      if (typeof window.gacNotify === 'function') {
        window.gacNotify(message, type || 'info');
        return;
      }
      if (!message) {
        return;
      }
      if (type === 'danger') {
        window.alert(message);
      }
    }

    function fetchBoard(silent) {
      var url = apiUrl + '?garage_id=' + encodeURIComponent(String(selectedGarageId || 0)) + '&_=' + Date.now();
      return fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      })
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        if (!payload || payload.ok !== true) {
          throw new Error(payload && payload.message ? payload.message : 'Unable to load queue board.');
        }

        var grouped = payload.grouped_jobs || {};
        var statuses = Array.isArray(payload.statuses) ? payload.statuses : ['OPEN', 'IN_PROGRESS', 'WAITING_PARTS', 'COMPLETED', 'CLOSED'];
        for (var i = 0; i < statuses.length; i++) {
          var status = String(statuses[i] || '');
          if (status === '') {
            continue;
          }
          renderColumn(status, grouped[status] || []);
        }
        applyLastUpdated(payload.generated_at || null);
      })
      .catch(function (error) {
        if (!silent) {
          notify(error && error.message ? error.message : 'Unable to refresh queue board.', 'danger');
        }
      });
    }

    function moveJob(jobId, fromStatus, toStatus) {
      if (!canMove) {
        notify('You do not have permission to move job cards.', 'warning');
        return Promise.resolve(false);
      }
      if (!jobId || !fromStatus || !toStatus || fromStatus === toStatus) {
        return Promise.resolve(false);
      }

      var formData = new FormData();
      formData.append('_csrf', csrfToken);
      formData.append('action', 'move');
      formData.append('garage_id', String(selectedGarageId || 0));
      formData.append('job_id', String(jobId));
      formData.append('to_status', String(toStatus));

      return fetch(apiUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        body: formData
      })
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        if (!payload || payload.ok !== true) {
          throw new Error(payload && payload.message ? payload.message : 'Unable to move job card.');
        }

        if (Array.isArray(payload.warnings) && payload.warnings.length > 0) {
          notify(payload.warnings.slice(0, 2).join(' | '), 'warning');
        } else {
          notify(payload.message || 'Job moved successfully.', 'success');
        }
        return fetchBoard(true).then(function () { return true; });
      })
      .catch(function (error) {
        notify(error && error.message ? error.message : 'Unable to move job card.', 'danger');
        return false;
      });
    }

    function bindColumnDropHandlers() {
      var bodies = root.querySelectorAll('.queue-col-body');
      for (var i = 0; i < bodies.length; i++) {
        (function (body) {
          var status = body.getAttribute('data-queue-body') || '';
          if (!status) {
            return;
          }
          body.addEventListener('dragover', function (event) {
            if (!canMove || !activeDrag) {
              return;
            }
            event.preventDefault();
            body.classList.add('is-drop-target');
          });
          body.addEventListener('dragleave', function () {
            body.classList.remove('is-drop-target');
          });
          body.addEventListener('drop', function (event) {
            body.classList.remove('is-drop-target');
            if (!canMove || !activeDrag) {
              return;
            }
            event.preventDefault();
            moveJob(activeDrag.jobId, activeDrag.fromStatus, status);
          });
        })(bodies[i]);
      }
    }

    if (refreshBtn) {
      refreshBtn.addEventListener('click', function () {
        fetchBoard(false);
      });
    }

    bindColumnDropHandlers();
    fetchBoard(false);

    refreshHandle = window.setInterval(function () {
      fetchBoard(true);
    }, 15000);

    window.addEventListener('beforeunload', function () {
      if (refreshHandle) {
        window.clearInterval(refreshHandle);
      }
    });
  })();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
