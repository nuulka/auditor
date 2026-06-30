<?php
$m = new mysqli('localhost', 'root', '', 'revizor_db');
// Kill the waiting processes
$m->query("KILL 274");
$m->query("KILL 276");
echo "Killed waiting processes 274 and 276\n";

// Check what's holding locks
$q = $m->query("SELECT * FROM performance_schema.metadata_locks WHERE OBJECT_SCHEMA = 'revizor_db'");
if ($q) {
    echo "=== Metadata Locks ===\n";
    while ($r = $q->fetch_assoc()) {
        echo "  {$r['OBJECT_NAME']} | {$r['LOCK_STATUS']} | {$r['OWNER_THREAD_ID']}\n";
    }
} else {
    echo "performance_schema not available: " . $m->error . "\n";
    // Alternative: SHOW OPEN TABLES
    $q2 = $m->query("SHOW OPEN TABLES FROM revizor_db WHERE In_use > 0");
    if ($q2) {
        echo "=== Open tables in use ===\n";
        while ($r = $q2->fetch_assoc()) {
            echo "  {$r['Table']} | {$r['In_use']}\n";
        }
    }
}
$m->close();
