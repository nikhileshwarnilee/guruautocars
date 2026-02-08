<?php

require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/session.php';

$config = require __DIR__ . '/../../config/config.php';
Session::start($config);

$error = Session::get('auth_error');
Session::forget('auth_error');

$tokenField = csrf_field();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= e(config('app.name', 'Guru Auto Cars')); ?></title>
</head>
<body>
    <h1>Login</h1>

    <?php if ($error): ?>
        <p style="color: #b91c1c;"><?php echo e($error); ?></p>
    <?php endif; ?>

    <form method="POST" action="login_action.php" autocomplete="off">
        <?php echo $tokenField; ?>
        <div>
            <label for="email">Email</label>
            <input type="email" name="email" id="email" required autofocus>
        </div>
        <div>
            <label for="password">Password</label>
            <input type="password" name="password" id="password" required>
        </div>
        <button type="submit">Sign in</button>
    </form>
</body>
</html>
