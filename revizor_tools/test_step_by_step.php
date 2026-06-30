<?php
// Step-by-step debug of reconciliation.php startup
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];
$_SESSION = [];
session_id('test002');
session_start();
$_SESSION['SDA_LOGGED'] = true;
$_SESSION['SDA_USER_ID'] = 1;
$_SESSION['SDA_USER_RIGHTS'] = 512;
$_SESSION['SDA_CHURCH_ID'] = 43;
$_SESSION['GN_USER_ID'] = 1;
$_SESSION['GN_USER_RIGHTS'] = 512;
$_SESSION['GN_CHURCH_ID'] = 43;
$_SESSION['GC_USER_FULL_NAME'] = 'Test';
$_SESSION['GC_LOGIN_COOKIE'] = true;
$_SESSION['SDA_LAST_ACTIVE'] = time();
$_SESSION['revizor_expires_at'] = time() + 3600;
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$_SESSION['revizor_app_role'] = 'admin';
session_write_close();

echo "Step 1: Loading OTS constant...\n";
require_once 'D:/laragon/www/ots/constant.php';

echo "Step 2: Session start...\n";
if (session_status() != PHP_SESSION_ACTIVE) session_start();

echo "Step 3: Set last active...\n";
$_SESSION['SDA_LAST_ACTIVE'] = time();

echo "Step 4: Include session_handler...\n";
require_once 'D:/laragon/www/ots/session_handler.php';

echo "Step 5: Check login...\n";
if (!isset($_SESSION['SDA_LOGGED'])) { echo "NOT LOGGED IN\n"; exit; }

echo "Step 6: CSRF token...\n";
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

echo "Step 7: Revizor session duration...\n";
define('REVIZOR_SESSION_DURATION', 1200);

echo "Step 8: Check expires_at...\n";
if (!isset($_SESSION['revizor_expires_at'])) { $_SESSION['revizor_expires_at'] = time() + REVIZOR_SESSION_DURATION; }

echo "Step 9: Time check...\n";
if (time() >= $_SESSION['revizor_expires_at']) { session_destroy(); echo "EXPIRED\n"; exit; }

echo "Step 10: DB connect...\n";
$conn = new mysqli('localhost', 'root', '', 'revizor_db');
if ($conn->connect_error) { die("DB error: " . $conn->connect_error); }
$conn->set_charset("utf8mb4");

echo "Step 11: Bootstrap + auth...\n";
require_once 'D:/laragon/www/revizor/lib/bootstrap.php';
require_once 'D:/laragon/www/revizor/lib/auth.php';

echo "Step 12: Build context...\n";
build_user_context_from_ots();

echo "Step 13: Check columns...\n";
$existing_columns = [];
$columns_res = $conn->query("SHOW COLUMNS FROM bank_reconciliation");
if ($columns_res) {
    while ($col_row = $columns_res->fetch_assoc()) { $existing_columns[] = $col_row['Field']; }
}
echo "  Existing columns: " . count($existing_columns) . "\n";

echo "Step 14: ALTER TABLE (if needed)...\n";
if (!in_array('bank_init_name', $existing_columns)) {
    echo "  Running ALTER TABLE...\n";
    $conn->query("ALTER TABLE bank_reconciliation ADD COLUMN bank_init_name VARCHAR(150)");
    echo "  ALTER done\n";
} else {
    echo "  No ALTER needed\n";
}

echo "Step 15: CREATE TABLE IF NOT EXISTS...\n";
$conn->query("CREATE TABLE IF NOT EXISTS bank_reconciliation (...) ENGINE=InnoDB");
echo "  CREATE done\n";

echo "Step 16: ALTER TABLE MODIFY...\n";
$conn->query("ALTER TABLE bank_reconciliation MODIFY COLUMN status VARCHAR(20) DEFAULT 'UNCHECKED'");
echo "  MODIFY done\n";

echo "ALL DONE\n";
