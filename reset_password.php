<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$token = strtolower(trim((string) ($_GET['token'] ?? $_POST['token'] ?? '')));
$minPasswordLength = password_reset_min_password_length();
$errorMessage = '';
$tokenContext = $token !== '' ? password_reset_get_valid_request($token) : null;
$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($requestMethod === 'POST') {
    require_csrf();

    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if (!password_reset_is_valid_token_format($token)) {
        $errorMessage = 'This reset link is invalid.';
    } elseif ($tokenContext === null) {
        $errorMessage = 'This reset link is invalid or has expired. Request a new link.';
    } elseif ($password === '' || $confirmPassword === '') {
        $errorMessage = 'Enter and confirm your new password.';
    } elseif (mb_strlen($password) < $minPasswordLength) {
        $errorMessage = 'Password must be at least ' . $minPasswordLength . ' characters.';
    } elseif (!hash_equals($password, $confirmPassword)) {
        $errorMessage = 'Password and confirmation do not match.';
    } elseif (password_reset_apply_new_password($token, $password)) {
        flash_set('login_notice', 'Password reset successful. Please sign in with your new password.', 'success');
        redirect('login.php');
    } else {
        $errorMessage = 'This reset link is invalid or has expired. Request a new link.';
    }
}

if ($token !== '' && $tokenContext === null && $errorMessage === '') {
    $errorMessage = 'This reset link is invalid or has expired. Request a new link.';
}

if ($token === '' && $errorMessage === '') {
    $errorMessage = 'Reset link is missing. Request a new link.';
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Reset Password | <?= e(APP_SHORT_NAME); ?></title>
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
          <p class="login-box-msg">Set a new password</p>

          <?php if ($errorMessage !== ''): ?>
            <div class="alert alert-danger" role="alert">
              <?= e($errorMessage); ?>
            </div>
          <?php endif; ?>

          <?php if ($tokenContext !== null): ?>
            <p class="small text-muted">
              Resetting password for <?= e(password_reset_mask_email((string) ($tokenContext['email'] ?? ''))); ?>
            </p>
            <form action="<?= e(url('reset_password.php')); ?>" method="post" autocomplete="off">
              <?= csrf_field(); ?>
              <input type="hidden" name="token" value="<?= e($token); ?>" />
              <div class="input-group mb-3">
                <input type="password" name="password" class="form-control" placeholder="New Password" minlength="<?= e((string) $minPasswordLength); ?>" required autofocus />
                <div class="input-group-text"><span class="bi bi-lock-fill"></span></div>
              </div>
              <div class="input-group mb-3">
                <input type="password" name="confirm_password" class="form-control" placeholder="Confirm New Password" minlength="<?= e((string) $minPasswordLength); ?>" required />
                <div class="input-group-text"><span class="bi bi-shield-lock-fill"></span></div>
              </div>
              <div class="row">
                <div class="col-12 mb-3 text-end">
                  <a href="<?= e(url('login.php')); ?>" class="small text-decoration-none">Back to sign in</a>
                </div>
                <div class="col-12">
                  <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary">Update Password</button>
                  </div>
                </div>
              </div>
            </form>
          <?php else: ?>
            <div class="text-center">
              <a href="<?= e(url('forgot_password.php')); ?>" class="btn btn-primary">Request New Reset Link</a>
              <div class="mt-3">
                <a href="<?= e(url('login.php')); ?>" class="small text-decoration-none">Back to sign in</a>
              </div>
            </div>
          <?php endif; ?>
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
