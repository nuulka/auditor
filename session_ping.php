<?php
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../ots/constant.php';

if (session_status() != PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
build_user_context_from_ots();

header('Content-Type: application/json');

if (!isset($_SESSION[GC_LOGIN_COOKIE])) {
    http_response_code(401);
    echo json_encode(['error' => 'nincs session']);
    exit;
}

$remaining = isset($_SESSION['revizor_expires_at']) ? max(0, $_SESSION['revizor_expires_at'] - time()) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['action']) || $_POST['action'] !== 'keepalive') {
        http_response_code(400);
        echo json_encode(['error' => 'hibas keres']);
        exit;
    }
    if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['error' => 'csrf']);
        exit;
    }

    $_SESSION['revizor_expires_at'] = time() + 1200;
    $_SESSION[GN_LAST_ACTIVE] = time();
    $remaining = $_SESSION['revizor_expires_at'] - time();
}

echo json_encode(['remaining' => $remaining, 'logged_in' => true]);
