<?php
$r = new mysqli('localhost', 'revizor_rw', 'revizor_2024_rw', 'revizor_db');
echo 'revizor_rw: ' . ($r->connect_error ? 'FAIL ' . $r->connect_error : 'OK') . "\n";
if (!$r->connect_error) {
    $q = $r->query('SELECT COUNT(*) AS c FROM bank_reconciliation');
    if ($q) { $row = $q->fetch_assoc(); echo '  bank_reconciliation count: ' . $row['c'] . "\n"; }
    $r->close();
}

$o = new mysqli('localhost', 'ots_ro', 'ots_2024_ro', 'ots');
echo 'ots_ro: ' . ($o->connect_error ? 'FAIL ' . $o->connect_error : 'OK') . "\n";
if (!$o->connect_error) {
    $q2 = $o->query('SELECT COUNT(*) AS c FROM USERS');
    if ($q2) { $row2 = $q2->fetch_assoc(); echo '  USERS count: ' . $row2['c'] . "\n"; }
    $o->close();
}
