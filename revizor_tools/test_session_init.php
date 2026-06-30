<?php
/**
 * Dev-only: Initialize a test admin session.
 * Only accessible from localhost.
 * 
 * Usage:   curl -c cookies.txt http://localhost/revizor/tools/test_session_init.php
 * After:   curl -b cookies.txt http://localhost/revizor/index.php
 */
$allowed_ips = ['127.0.0.1', '::1', 'localhost'];
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', $allowed_ips) && php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden');
}

// Compact session encoding: serialize() without session_start
$session_data = [
    'SDA_LOGGED' => true,
    'SDA_USER_ID' => 1,
    'SDA_USER_RIGHTS' => 512,
    'SDA_CHURCH_ID' => 43,
    'GN_USER_ID' => 1,
    'GN_USER_RIGHTS' => 512,
    'GN_CHURCH_ID' => 43,
    'GC_USER_FULL_NAME' => 'E2E Admin Tester',
    'GC_LOGIN_COOKIE' => true,
    'SDA_LAST_ACTIVE' => time(),
    'revizor_expires_at' => time() + 3600,
    'csrf_token' => bin2hex(random_bytes(32)),
    'revizor_app_role' => 'admin',
];

// Write session file directly
$sid = bin2hex(random_bytes(16));
$session_path = 'D:/laragon/tmp/sess_' . $sid;
$encoded = '';
foreach ($session_data as $key => $val) {
    $encoded .= $key . '|' . serialize($val);
}
file_put_contents($session_path, $encoded);

// Set cookie
setcookie('PHPSESSID', $sid, 0, '/', '', false, true);

if (isset($_GET['json'])) {
    header('Content-Type: application/json');
    echo json_encode(['session_id' => $sid, 'status' => 'OK']);
    exit;
}

header('Content-Type: text/plain');
echo "Session initialized: $sid\n";
echo "Cookie has been set. Use this session for subsequent requests.\n";
