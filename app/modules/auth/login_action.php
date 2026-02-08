<?php

require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/session.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../config/database.php';

$config = require __DIR__ . '/../../config/config.php';
Session::start($config);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('login.php');
}

$tokenKey = config('security.csrf_token_key', '_token');
$requestToken = $_POST[$tokenKey] ?? '';
if (!$requestToken || !hash_equals((string) Session::get($tokenKey), (string) $requestToken)) {
    Session::set('auth_error', 'Invalid session token. Please try again.');
    redirect('login.php');
}

$email = trim($_POST['email'] ?? '');
$password = (string) ($_POST['password'] ?? '');

if ($email === '' || $password === '') {
    Session::set('auth_error', 'Email and password are required.');
    redirect('login.php');
}

$stmt = Database::query(
    'SELECT id, password_hash, role FROM users WHERE email = :email AND status = :status LIMIT 1',
    [
        'email' => $email,
        'status' => 'active',
    ]
);

$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    Session::set('auth_error', 'Invalid credentials.');
    redirect('login.php');
}

$allowedRoles = ['admin', 'staff'];
if (!in_array($user['role'], $allowedRoles, true)) {
    Session::set('auth_error', 'Access denied for this account.');
    redirect('login.php');
}

Auth::login((int) $user['id']);
Session::set('user_role', $user['role']);
Session::set('user_email', $email);

redirect('/');
