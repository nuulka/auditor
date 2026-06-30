<?php
// 1. Create a fresh session
$ch = curl_init('http://localhost/revizor/tools/test_session_init.php?json=1');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HEADER => true]);
$resp = curl_exec($ch);
curl_close($ch);

preg_match('/PHPSESSID=([a-zA-Z0-9]+);/', $resp, $m);
$sid = $m[1] ?? '';
echo "Session: $sid\n";

// 2. Test each page
$tests = [
    'index.php',
    'upload.php',
    'reconciliation.php',
    'search.php',
    'document_check.php',
    'help.php',
    'session_ping.php',
];

foreach ($tests as $t) {
    $url = "http://localhost/revizor/$t";
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
    $elapsed = round(microtime(true) - $start, 2);
    curl_close($ch);

    echo "$t: status={$info['http_code']} time={$elapsed}s err='$err' len=" . strlen($resp) . "\n";
    if ($info['http_code'] === 302 && $resp) {
        preg_match('/Location: (.+)/i', substr($resp, 0, strpos($resp, "\r\n\r\n")), $loc);
        echo "  -> " . ($loc[1] ?? '?') . "\n";
    }
}

// Cleanup
$file = "D:/laragon/tmp/sess_$sid";
if (file_exists($file)) unlink($file);
