<?php

require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/session.php';
require_once __DIR__ . '/../../core/auth.php';

$config = require __DIR__ . '/../../config/config.php';
Session::start($config);

Auth::logout();

redirect('login.php');
