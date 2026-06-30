<?php
function checkHandler($file, $lineNum) {
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $start = max(0, $lineNum-1);
    $end = min(count($lines)-1, $start + 60);
    $slice = array_slice($lines, $start, $end-$start+1);
    $text = implode("\n", $slice);
    $has = false;
    $keywords = ['require_church_access(', 'is_admin(', 'require_admin(', 'build_user_context_from_ots(', 'get_accessible_church_ids('];
    foreach ($keywords as $k) { if (strpos($text, $k) !== false) { $has = true; break; } }
    return $has;
}

$targets = [
    ['file'=>'reconciliation.php','lines'=>[46,59,76,290,346,397,429,908,1052,1162,1207,1282]],
    ['file'=>'upload.php','lines'=>[472,572]],
    ['file'=>'document_check.php','lines'=>[28]],
];

foreach ($targets as $t) {
    $path = __DIR__ . '/../' . $t['file'];
    if (!file_exists($path)) continue;
    echo "Checking $path\n";
    foreach ($t['lines'] as $ln) {
        $ok = checkHandler($path, $ln);
        echo "  handler at line $ln => " . ($ok ? 'OK' : 'MISSING') . "\n";
    }
    echo "\n";
}
