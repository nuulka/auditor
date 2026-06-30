<?php
// Exact same session data as E2E test
$sid = bin2hex(random_bytes(16));
$session_data = [
    'SDA_LOGGED' => true,
    'SDA_USER_ID' => 1,
    'SDA_USER_RIGHTS' => 512,
    'SDA_CHURCH_ID' => 43,
    'GN_USER_ID' => 1,
    'GN_USER_RIGHTS' => 512,
    'GN_CHURCH_ID' => 43,
    'GC_USER_FULL_NAME' => 'E2E Tester',
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

$url = "http://localhost/revizor/reconciliation.php";
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_COOKIE => "PHPSESSID=$sid",
    CURLOPT_FOLLOWLOCATION => false,
]);
$start = microtime(true);
$resp = curl_exec($ch);
$info = curl_getinfo($ch);
$err = curl_error($ch);
echo "Status: {$info['http_code']}, Time: " . round(microtime(true)-$start, 2) . "s\n";
if ($info['http_code'] == 0) {
    echo "Error: $err\n";
    $header_size = $info['header_size'];
    $body = substr($resp, $header_size);
    echo "Body length: " . strlen($body) . "\n";
    if (strlen($body) > 0) {
        echo "First 500 chars: " . substr($body, 0, 500) . "\n";
    }
}
curl_close($ch);

// Check if session file still exists
$f = "D:/laragon/tmp/sess_$sid";
if (file_exists($f)) {
    echo "Session file exists: " . filesize($f) . " bytes\n";
    unlink($f);
} else {
    echo "Session file GONE\n";
}
