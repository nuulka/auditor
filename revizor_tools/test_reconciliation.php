<?php
// Test reconciliation.php with fresh session
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
foreach ($session_data as $k => $v) {
    $encoded .= $k . '|' . serialize($v);
}
file_put_contents("D:/laragon/tmp/sess_$sid", $encoded);
echo "Session: $sid\n";

// Test reconciliation.php directly
$url = "http://localhost/revizor/reconciliation.php";
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_COOKIE => "PHPSESSID=$sid",
    CURLOPT_FOLLOWLOCATION => false,
]);
$start = microtime(true);
$resp = curl_exec($ch);
$info = curl_getinfo($ch);
echo "Status: {$info['http_code']}, Time: " . round(microtime(true)-$start,2) . "s\n";
if ($info['http_code'] == 0) {
    echo "Error: " . curl_error($ch) . "\n";
} else {
    echo "Response length: " . strlen($resp) . "\n";
}
curl_close($ch);

// Cleanup
if (file_exists("D:/laragon/tmp/sess_$sid")) unlink("D:/laragon/tmp/sess_$sid");
