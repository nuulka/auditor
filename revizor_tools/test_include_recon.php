<?php
// Test reconciliation.php directly via include (bypass Apache)
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];
$_SESSION = [];
session_id('test001');
session_start();
$_SESSION['SDA_LOGGED'] = true;
$_SESSION['SDA_USER_ID'] = 1;
$_SESSION['SDA_USER_RIGHTS'] = 512;
$_SESSION['SDA_CHURCH_ID'] = 43;
$_SESSION['GN_USER_ID'] = 1;
$_SESSION['GN_USER_RIGHTS'] = 512;
$_SESSION['GN_CHURCH_ID'] = 43;
$_SESSION['GC_USER_FULL_NAME'] = 'Test';
$_SESSION['GC_LOGIN_COOKIE'] = true;
$_SESSION['SDA_LAST_ACTIVE'] = time();
$_SESSION['revizor_expires_at'] = time() + 3600;
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$_SESSION['revizor_app_role'] = 'admin';
session_write_close();

echo "Starting reconciliation.php include...\n";
$start = microtime(true);
ob_start();
include 'D:/laragon/www/revizor/reconciliation.php';
$out = ob_get_clean();
echo "Done in " . round(microtime(true) - $start, 2) . "s\n";
echo "Output: " . strlen($out) . " chars\n";
echo "First 200 chars: " . substr($out, 0, 200) . "\n";
