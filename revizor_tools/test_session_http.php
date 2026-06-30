<?php
// Simulate logging in with the session file and then making an HTTP request
// Step 1: Create session file
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

// Step 2: Make HTTP request
$url = "http://localhost/revizor/session_ping.php";
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_COOKIE => "PHPSESSID=$sid",
    CURLOPT_FOLLOWLOCATION => false,
]);
$start = microtime(true);
$resp = curl_exec($ch);
$info = curl_getinfo($ch);
$err = curl_error($ch);
echo "Status: {$info['http_code']}, Time: " . round(microtime(true)-$start,2) . "s, Error: '$err'\n";
echo "Response: " . substr($resp, 0, 300) . "\n";
curl_close($ch);

// Cleanup
unlink("D:/laragon/tmp/sess_$sid");
