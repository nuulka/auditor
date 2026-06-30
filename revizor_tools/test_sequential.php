<?php
// Test: index.php then reconciliation.php with same PHP process
function create_session() {
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
    file_put_contents("D:/laragon/tmp/sess_$sid", $encoded);
    return $sid;
}

function fetch($url, $sid) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIE => "PHPSESSID=$sid",
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $start = microtime(true);
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return [
        'status' => $info['http_code'],
        'time' => round(microtime(true) - $start, 2),
        'error' => $err,
        'body' => substr($resp, $info['header_size']),
    ];
}

$tests = ['index.php', 'reconciliation.php', 'search.php', 'document_check.php', 'session_ping.php'];

foreach ($tests as $t) {
    $sid = create_session();
    $r = fetch("http://localhost/revizor/$t", $sid);
    unlink("D:/laragon/tmp/sess_$sid");
    echo "$t: status={$r['status']} time={$r['time']}s err='{$r['error']}' len=" . strlen($r['body']) . "\n";
}
