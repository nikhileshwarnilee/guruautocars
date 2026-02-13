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

    <script src="<?= e(url('assets/plugins/bootstrap/bootstrap.bundle.min.js')); ?>"></script>
    <script src="<?= e(url('assets/plugins/overlayscrollbars/overlayscrollbars.browser.es6.min.js')); ?>"></script>
    <script src="<?= e(url('assets/js/adminlte.min.js')); ?>"></script>
    <script src="<?= e(url('assets/js/chart_helpers.js')); ?>"></script>
    <script src="<?= e(url('assets/js/app.js')); ?>"></script>
  </body>
</html>
