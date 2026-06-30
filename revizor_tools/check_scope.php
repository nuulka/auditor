<?php
// Scans PHP files for POST action handlers and reports whether they contain scope/admin checks
function scan_file($path) {
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    $res = [];
    foreach ($lines as $i => $line) {
        if (strpos($line, "\$_SERVER['REQUEST_METHOD'] === 'POST'") !== false && strpos($lines[$i+1] ?? '', "\$_POST['action']") !== false) {
            // extract next 40 lines
            $ctx = array_slice($lines, $i, 60);
            $joined = implode("\n", $ctx);
            $has_scope = (strpos($joined, 'require_church_access(') !== false) || (strpos($joined, 'is_admin(') !== false) || (strpos($joined, 'require_admin(') !== false);
            preg_match('/\$_POST\[\'action\'\]\s*===\s*\'([^\']+)\'/m', $joined, $m);
            $action = $m[1] ?? '(unknown)';
            $res[] = ['line' => $i+1, 'action' => $action, 'has_check' => $has_scope, 'context' => $ctx];
        }
    }
    return $res;
}

$files = glob(__DIR__ . '/../*.php');
foreach ($files as $f) {
    $result = scan_file($f);
    if (!empty($result)) {
        echo "File: $f\n";
        foreach ($result as $r) {
            echo "  Action: " . $r['action'] . " at line " . $r['line'] . " => " . ($r['has_check'] ? 'OK' : 'MISSING') . "\n";
        }
        echo "\n";
    }
}

// also scan subdir files
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../all_transactions'));
foreach ($iter as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
        $res = scan_file($file->getPathname());
        if (!empty($res)) {
            echo "File: " . $file->getPathname() . "\n";
            foreach ($res as $r) {
                echo "  Action: " . $r['action'] . " at line " . $r['line'] . " => " . ($r['has_check'] ? 'OK' : 'MISSING') . "\n";
            }
            echo "\n";
        }
    }
}
