<?php
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../ots/constant.php';

if (session_status() != PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['revizor_expires_at'])) {
    http_response_code(401);
    echo json_encode(['error' => 'nincs session']);
    exit;
}

// Minden ping hosszabbítja a sessiont
$_SESSION['revizor_expires_at'] = time() + 1200;
$_SESSION[GN_LAST_ACTIVE] = time();

$remaining = $_SESSION['revizor_expires_at'] - time();
header('Content-Type: application/json');
echo json_encode(['remaining' => $remaining]);
