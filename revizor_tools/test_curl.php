<?php
$sid = bin2hex(random_bytes(16));
$session_data = [
    'SDA_LOGGED' => true,
    'SDA_USER_ID' => 1,
    'SDA_USER_RIGHTS' => 512,
    'SDA_CHURCH_ID' => 43,
    'GN_USER_ID' => 1,
    'GN_USER_RIGHTS' => 512,
    'GN_CHURCH_ID' => 43,
    'GC_USER_FULL_NAME' => 'Test',
    'GC_LOGIN_COOKIE' => true,
    'SDA_LAST_ACTIVE' => time(),
    'revizor_expires_at' => time() + 3600,
    'csrf_token' => bin2hex(random_bytes(32)),
    'revizor_app_role' => 'admin',
];
$encoded = '';
foreach ($session_data as $k => $v) { $encoded .= $k . '|' . serialize($v); }
file_put_contents('D:/laragon/tmp/sess_' . $sid, $encoded);
echo "Session: $sid\n";

// Try with curl command
$cmd = 'curl -s -o D:\laragon\tmp\curl_out.txt -w "%%{http_code}" --cookie "PHPSESSID=' . $sid . '" --max-time 10 http://localhost/revizor/reconciliation.php 2>&1';
exec($cmd, $out, $code);
echo "Curl exit: $code, Output: " . implode('', $out) . "\n";

// Check curl output
$f = 'D:\laragon\tmp\curl_out.txt';
if (file_exists($f)) {
    $content = file_get_contents($f);
    echo "Response: " . strlen($content) . " bytes\n";
    echo substr($content, 0, 500) . "\n";
    unlink($f);
}
