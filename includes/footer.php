<?php
$appJsPath = __DIR__ . '/../assets/js/app.js';
$appJsVersion = (string) @md5_file($appJsPath);
if ($appJsVersion === '') {
    $appJsVersion = (string) @filemtime($appJsPath);
}
if ($appJsVersion === '') {
    $appJsVersion = (string) time();
}
?>
      <footer class="app-footer">
        <div class="float-end d-none d-sm-inline">India GST Ready</div>
        <strong><?= e(APP_SHORT_NAME); ?></strong> &copy; <?= date('Y'); ?>
      </footer>
    </div>

    <div class="modal fade" id="inline-customer-modal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Add New Customer</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body" id="inline-customer-modal-body">
            <div class="text-muted">Loading customer form...</div>
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="inline-vehicle-modal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Add New Vehicle</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body" id="inline-vehicle-modal-body">
            <div class="text-muted">Loading vehicle form...</div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="gac-safe-delete-modal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Deletion Impact Summary</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="alert d-none" id="gac-safe-delete-alert"></div>

            <div id="gac-safe-delete-content" class="d-none">
              <div class="border rounded p-3 mb-3">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                  <div>
                    <div class="text-muted small">Main Record</div>
                    <div class="fw-semibold" id="gac-safe-delete-record-label">-</div>
                    <div class="small text-muted" id="gac-safe-delete-record-meta"></div>
                  </div>
                  <div class="text-end">
                    <div class="small text-muted">Total Dependencies</div>
                    <div class="fw-semibold" id="gac-safe-delete-total-deps">0</div>
                    <div class="small text-muted">Pending Dependency Actions: <span id="gac-safe-delete-pending-resolve">0</span></div>
                    <div class="small text-muted">Financial Impact: <span id="gac-safe-delete-fin-impact">INR 0.00</span></div>
                  </div>
                </div>
              </div>

              <div id="gac-safe-delete-blockers" class="d-none mb-3">
                <div class="fw-semibold text-danger small text-uppercase">Blockers</div>
                <ul class="mb-0 small text-danger" id="gac-safe-delete-blockers-list"></ul>
              </div>

              <div id="gac-safe-delete-warnings" class="d-none mb-3">
                <div class="fw-semibold text-danger-emphasis small text-uppercase">Warnings</div>
                <ul class="mb-0 small" id="gac-safe-delete-warnings-list"></ul>
              </div>

              <div class="mb-3">
                <div class="fw-semibold mb-2">Dependencies</div>
                <div id="gac-safe-delete-groups" class="d-grid gap-2"></div>
              </div>

              <div class="border rounded p-3">
                <div class="row g-3">
                  <div class="col-lg-6">
                    <label class="form-label">Type <code>CONFIRM</code> to continue</label>
                    <input type="text" id="gac-safe-delete-confirm-text" class="form-control" maxlength="16" placeholder="CONFIRM" />
                  </div>
                  <div class="col-lg-6 d-flex align-items-end">
                    <div class="form-check border rounded p-3 w-100">
                      <input class="form-check-input" type="checkbox" id="gac-safe-delete-confirm-check" />
                      <label class="form-check-label" for="gac-safe-delete-confirm-check">
                        I understand the dependency impact and want to continue.
                      </label>
                    </div>
                  </div>
                  <div class="col-12 d-none" id="gac-safe-delete-reason-wrap">
                    <label class="form-label">Deletion / Reversal Reason</label>
                    <textarea id="gac-safe-delete-reason" class="form-control" rows="2" maxlength="255"></textarea>
                    <small class="text-muted">This reason will be posted with the request.</small>
                  </div>
                </div>
              </div>
            </div>

            <div id="gac-safe-delete-loading" class="text-muted">Loading impact summary...</div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            <button type="button" class="btn btn-danger" id="gac-safe-delete-submit-btn" disabled>Confirm Action</button>
          </div>
        </div>
      </div>
    </div>

    <script>
      window.GacSafeDeleteConfig = {
        endpoint: <?= json_encode(url('modules/system/delete_impact_api.php')); ?>,
        dependencyActionEndpoint: <?= json_encode(url('modules/system/delete_dependency_action_api.php')); ?>
      };
    </script>

    <script src="<?= e(url('assets/plugins/bootstrap/bootstrap.bundle.min.js')); ?>"></script>
    <script src="<?= e(url('assets/plugins/overlayscrollbars/overlayscrollbars.browser.es6.min.js')); ?>"></script>
    <script src="<?= e(url('assets/js/adminlte.min.js')); ?>"></script>
    <script src="<?= e(url('assets/js/chart_helpers.js')); ?>"></script>
    <script src="<?= e(url('assets/js/app.js?v=' . $appJsVersion)); ?>"></script>
  </body>
</html>
