<?php
$sid = '1f08a6e436bec86a5febbfd4f5aec759';
$tests = ['reconciliation.php', 'search.php', 'document_check.php', 'index.php', 'upload.php'];

foreach ($tests as $t) {
    $url = "http://localhost/revizor/$t";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_COOKIE => "PHPSESSID=$sid",
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err = curl_error($ch);
    curl_close($ch);

    echo "$t: status={$info['http_code']} error='$err' len=" . strlen($resp) . "\n";
    if ($resp && $info['http_code'] === 0) {
        echo "  Response: " . substr($resp, 0, 200) . "\n";
    }
}
