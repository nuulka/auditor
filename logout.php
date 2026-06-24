<?php
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../ots/constant.php';

if (session_status() != PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();

// clear revizor-specific session bits too
if (session_status() != PHP_SESSION_ACTIVE) { session_start(); }
unset($_SESSION['revizor_accessible_churches'], $_SESSION['revizor_app_role'], $_SESSION['revizor_selected_church']);

header('Location: login.php');
exit;
