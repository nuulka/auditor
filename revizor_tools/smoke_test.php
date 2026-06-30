<?php
/**
 * Revizor Smoke Test — CLI-only, tests DB, auth layer, and key queries.
 * Run: php tools/smoke_test.php
 */

// Prevent header() calls from aborting
$test_output = '';
function test_log($msg) { global $test_output; $test_output .= $msg . "\n"; echo $msg . "\n"; }

// Override header() to not actually send
if (!function_exists('header')) {
    function header($h, $r = false) { /* noop */ }
}
if (!function_exists('setcookie')) {
    function setcookie(...$a) { /* noop */ }
}

$exit_code = 0;
function fail($msg) { global $exit_code; $exit_code = 1; test_log("  FAIL: $msg"); }
function pass($msg) { test_log("  PASS: $msg"); }

// --- Step 1: DB connections ---
test_log("=== 1. DB Connections ===");
try {
    $r = new mysqli('localhost', 'root', '', 'revizor_db');
    if ($r->connect_error) { fail('revizor_db: ' . $r->connect_error); } else { pass('revizor_db OK'); $r->close(); }
} catch (Throwable $e) { fail('revizor_db exception: ' . $e->getMessage()); }

try {
    $o = new mysqli('localhost', 'root', '', 'ots');
    if ($o->connect_error) { fail('ots: ' . $o->connect_error); } else { pass('ots DB OK'); $o->close(); }
} catch (Throwable $e) { fail('ots exception: ' . $e->getMessage()); }

// --- Step 2: Config loading ---
test_log("\n=== 2. Config ===");
try {
    $cfg_file = __DIR__ . '/../config/app.php';
    if (!file_exists($cfg_file)) { fail('config/app.php missing'); } else {
        $cfg = include $cfg_file;
        pass('config/app.php loaded');
    }
} catch (Throwable $e) { fail('config load: ' . $e->getMessage()); }

// --- Step 3: Auth functions (simulate session) ---
test_log("\n=== 3. Auth layer (simulated) ===");
try {
    require_once __DIR__ . '/../lib/bootstrap.php';
    require_once __DIR__ . '/../lib/auth.php';
    pass('auth.php loaded without error');
} catch (Throwable $e) { fail('auth load: ' . $e->getMessage()); }

// Test is_admin with various flags
$testCases = [
    ['rights' => 0,   'expected_admin' => false, 'expected_revizor' => false],
    ['rights' => 512, 'expected_admin' => true,  'expected_revizor' => false],
    ['rights' => 1024,'expected_admin' => false, 'expected_revizor' => true],
    ['rights' => 1536,'expected_admin' => true,  'expected_revizor' => true],
];
if (!isset($_SESSION)) session_start();
foreach ($testCases as $tc) {
    $_SESSION[GN_USER_RIGHTS] = $tc['rights'];
    $a = is_admin();
    $rev = is_revizor();
    $aOk = ($a === $tc['expected_admin']);
    $rOk = ($rev === $tc['expected_revizor']);
    if ($aOk && $rOk) {
        pass("rights={$tc['rights']}: admin=" . ($a?'Y':'N') . " revizor=" . ($rev?'Y':'N'));
    } else {
        fail("rights={$tc['rights']}: expected admin={$tc['expected_admin']}/revizor={$tc['expected_revizor']}, got admin=" . ($a?'Y':'N') . " revizor=" . ($rev?'Y':'N'));
    }
}

// Test get_accessible_church_ids for admin (should return null)
$_SESSION[GN_USER_RIGHTS] = 512;
$ids = get_accessible_church_ids();
if ($ids === null) { pass('admin get_accessible_church_ids returns null (all churches)'); }
else { fail('admin get_accessible_church_ids should be null, got ' . json_encode($ids)); }

// Test require_church_access for admin (should not exit)
try {
    require_church_access(999);
    pass('admin require_church_access(999) OK');
} catch (Throwable $e) {
    fail('admin require_church_access(999) threw: ' . $e->getMessage());
}

// Cleanup session
$_SESSION = [];

// --- Step 4: Key queries ---
test_log("\n=== 4. Key DB queries ===");
$rev = new mysqli('localhost', 'root', '', 'revizor_db');
$tables = ['bank_reconciliation', 'bank_reconciliation_items', 'custom_patterns', 'audit_checklist', 'church_bank_accounts'];
foreach ($tables as $tbl) {
    $q = $rev->query("SHOW TABLES LIKE '$tbl'");
    if ($q && $q->num_rows > 0) { pass("table `$tbl` exists"); }
    else { fail("table `$tbl` missing or query error: " . ($rev->error ?: 'none')); }
}

$ots = new mysqli('localhost', 'root', '', 'ots');
$q = $ots->query("SHOW TABLES LIKE 'ROLES'");
if ($q && $q->num_rows > 0) {
    pass('OTS ROLES table exists');
    $q2 = $ots->query("SELECT COUNT(*) as cnt FROM ROLES WHERE USER_ID = 2 AND VALID_FROM <= NOW() AND (VALID_TO IS NULL OR VALID_TO >= NOW())");
    if ($q2) { $row = $q2->fetch_assoc(); pass("ROLES for user 2: {$row['cnt']} active rows"); }
    else { fail("ROLES query error: " . $ots->error); }
} else {
    fail('OTS ROLES table missing');
}
$ots->close();
$rev->close();

// --- Step 5: File checks ---
test_log("\n=== 5. Critical files exist ===");
$criticalFiles = [
    'index.php', 'login.php', 'logout.php', 'upload.php', 'reconciliation.php',
    'search.php', 'document_check.php', 'document_check_get.php', 'session_ping.php',
    'lib/bootstrap.php', 'lib/auth.php', 'config/app.php',
    'all_transactions/all_transactions_multi.php',
];
foreach ($criticalFiles as $f) {
    $path = __DIR__ . '/../' . $f;
    if (file_exists($path)) { pass("$f exists"); }
    else { fail("$f MISSING"); }
}

// --- Summary ---
test_log("\n========================================");
if ($exit_code === 0) {
    test_log("ALL TESTS PASSED");
} else {
    test_log("SOME TESTS FAILED (exit code $exit_code)");
}
exit($exit_code);
