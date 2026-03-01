<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($requestMethod === 'POST') {
    require_csrf();

    $email = password_reset_normalize_email((string) ($_POST['email'] ?? ''));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        flash_set('forgot_password_error', 'Enter a valid email address.', 'danger');
        redirect('forgot_password.php');
    }

    password_reset_request($email);
    flash_set(
        'forgot_password_status',
        'If the email is registered, a password reset link has been sent.',
        'success'
    );
    redirect('forgot_password.php');
}

$status = flash_get('forgot_password_status');
$error = flash_get('forgot_password_error');
?>
<!doctype html>
<html lang="en">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Forgot Password | <?= e(APP_SHORT_NAME); ?></title>
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
          <p class="login-box-msg">Reset your password via email</p>

          <?php if ($error !== null): ?>
            <div class="alert alert-<?= e((string) ($error['type'] ?? 'danger')); ?>" role="alert">
              <?= e((string) ($error['message'] ?? 'Unable to process request.')); ?>
            </div>
          <?php endif; ?>

          <?php if ($status !== null): ?>
            <div class="alert alert-<?= e((string) ($status['type'] ?? 'info')); ?>" role="alert">
              <?= e((string) ($status['message'] ?? 'Request accepted.')); ?>
            </div>
          <?php endif; ?>

          <form action="<?= e(url('forgot_password.php')); ?>" method="post" autocomplete="off">
            <?= csrf_field(); ?>
            <div class="input-group mb-3">
              <input type="email" name="email" class="form-control" placeholder="Registered Email" required autofocus />
              <div class="input-group-text"><span class="bi bi-envelope-fill"></span></div>
            </div>
            <div class="row">
              <div class="col-12 mb-3 text-end">
                <a href="<?= e(url('login.php')); ?>" class="small text-decoration-none">Back to sign in</a>
              </div>
              <div class="col-12">
                <div class="d-grid gap-2">
                  <button type="submit" class="btn btn-primary">Send Reset Link</button>
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
