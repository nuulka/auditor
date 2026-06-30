<?php
$m = new mysqli('localhost', 'root', '', 'revizor_db');

// Find process info for thread 320
$q = $m->query("SELECT THREAD_ID, PROCESSLIST_ID, PROCESSLIST_USER, PROCESSLIST_HOST, PROCESSLIST_DB, PROCESSLIST_COMMAND, PROCESSLIST_TIME, PROCESSLIST_INFO FROM performance_schema.threads WHERE THREAD_ID = 320");
if ($q && $r = $q->fetch_assoc()) {
    echo "Thread 320: PROCESSLIST_ID={$r['PROCESSLIST_ID']} USER={$r['PROCESSLIST_USER']} DB={$r['PROCESSLIST_DB']} CMD={$r['PROCESSLIST_COMMAND']} TIME={$r['PROCESSLIST_TIME']}s INFO=" . substr($r['PROCESSLIST_INFO'] ?? '', 0, 100) . "\n";
    
    // Kill corresponding MySQL connection
    if ($r['PROCESSLIST_ID']) {
        $m->query("KILL {$r['PROCESSLIST_ID']}");
        echo "Killed process {$r['PROCESSLIST_ID']}\n";
    }
} else {
    echo "Thread 320 not found: " . ($m->error ?: 'no results') . "\n";
}

// Check remaining locks
$q2 = $m->query("SELECT * FROM performance_schema.metadata_locks WHERE OBJECT_SCHEMA = 'revizor_db'");
if ($q2) {
    $remaining = $q2->fetch_all(MYSQLI_ASSOC);
    if (count($remaining) > 0) {
        echo "Remaining locks:\n";
        foreach ($remaining as $r) {
            echo "  {$r['OBJECT_NAME']} | {$r['LOCK_STATUS']} | {$r['OWNER_THREAD_ID']}\n";
        }
    } else {
        echo "No remaining locks\n";
    }
}
$m->close();
