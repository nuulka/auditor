<?php
// Returns JSON progress for the progressive match run for current session
require_once __DIR__ . '/../ots/constant.php';
session_start();
require_once __DIR__ . '/../ots/session_handler.php';
$logged_in = isset($_SESSION[GC_LOGIN_COOKIE]);
$f = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'revizor_progress_' . session_id() . '.json';
header('Content-Type: application/json');
if (!$logged_in) {
    http_response_code(401);
    echo json_encode(['error' => 'Nincs bejelentkezve']);
    exit;
}
if (file_exists($f)) {
    $data = file_get_contents($f);
    echo $data ?: json_encode(['status'=>'UNKNOWN']);
} else {
    echo json_encode(['status'=>'NONE']);
}
