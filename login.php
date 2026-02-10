<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$loginError = flash_get('login_error');
?>
<!doctype html>
<html lang="en">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Login | <?= e(APP_SHORT_NAME); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />

    <link rel="stylesheet" href="<?= e(url('assets/plugins/bootstrap-icons/bootstrap-icons.min.css')); ?>" />
    <link rel="stylesheet" href="<?= e(url('assets/plugins/overlayscrollbars/overlayscrollbars.min.css')); ?>" />
    <link rel="stylesheet" href="<?= e(url('assets/css/adminlte.min.css')); ?>" />
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')); ?>" />
  </head>
  <body class="login-page bg-body-secondary">
    <div class="login-box">
      <div class="login-logo">
        <a href="<?= e(url('login.php')); ?>"><b>Guru</b> Auto Cars</a>
      </div>
      <div class="card">
        <div class="card-body login-card-body">
          <p class="login-box-msg">Sign in to access Garage ERP</p>

          <?php if ($loginError !== null): ?>
            <div class="alert alert-danger" role="alert">
              <?= e((string) $loginError['message']); ?>
            </div>
          <?php endif; ?>

          <form action="<?= e(url('authenticate.php')); ?>" method="post" autocomplete="off">
            <?= csrf_field(); ?>
            <div class="input-group mb-3">
              <input type="text" name="identifier" class="form-control" placeholder="Email or Username" required autofocus />
              <div class="input-group-text"><span class="bi bi-person"></span></div>
            </div>
            <div class="input-group mb-3">
              <input type="password" name="password" class="form-control" placeholder="Password" required />
              <div class="input-group-text"><span class="bi bi-lock-fill"></span></div>
            </div>
            <div class="row">
              <div class="col-12">
                <div class="d-grid gap-2">
                  <button type="submit" class="btn btn-primary">Sign In</button>
                </div>
              </div>
            </div>
          </form>
        </div>
      </div>
      <p class="text-center small text-muted mt-3 mb-0">India-ready ERP for multi-branch garages</p>
    </div>

    <script src="<?= e(url('assets/plugins/bootstrap/bootstrap.bundle.min.js')); ?>"></script>
    <script src="<?= e(url('assets/plugins/overlayscrollbars/overlayscrollbars.browser.es6.min.js')); ?>"></script>
    <script src="<?= e(url('assets/js/adminlte.min.js')); ?>"></script>
    <script src="<?= e(url('assets/js/app.js')); ?>"></script>
  </body>
</html>
