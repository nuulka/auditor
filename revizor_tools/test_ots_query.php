<?php
// Test just the OTS connection and query needed by session_ping.php
session_start();
$_SESSION['SDA_USER_ID'] = 1;
$_SESSION['SDA_USER_RIGHTS'] = 512;
$_SESSION['GN_USER_ID'] = 1;
$_SESSION['GN_USER_RIGHTS'] = 512;
$_SESSION['GC_LOGIN_COOKIE'] = true;
$_SESSION['SDA_LAST_ACTIVE'] = time();
$_SESSION['revizor_expires_at'] = time() + 3600;

require_once 'D:/laragon/www/revizor/lib/bootstrap.php';
require_once 'D:/laragon/www/revizor/lib/auth.php';

echo "Testing OTS query...\n";
$start = microtime(true);
build_user_context_from_ots();
echo "Done in " . round(microtime(true) - $start, 3) . "s\n";
echo "Accessible churches: " . json_encode($_SESSION['revizor_accessible_churches'] ?? []) . "\n";
