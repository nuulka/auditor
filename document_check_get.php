<?php
ini_set('display_errors', 0);
error_reporting(0);
require_once __DIR__ . '/../ots/constant.php';
if (session_status() != PHP_SESSION_ACTIVE) { session_start(); }
$_SESSION[GN_LAST_ACTIVE] = time();
require_once __DIR__ . '/../ots/session_handler.php';
if (!isset($_SESSION[GC_LOGIN_COOKIE])) { header('Content-Type: application/json'); echo json_encode(['error' => 'Nincs bejelentkezve']); exit; }

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';

$conn = get_revizor_conn();
if ($conn->connect_error) { header('Content-Type: application/json'); echo json_encode(['error' => 'DB hiba']); exit; }
$conn->set_charset("utf8mb4");

$bank_id = intval($_GET['bank_reconciliation_id'] ?? 0);
if ($bank_id <= 0) { header('Content-Type: application/json'); echo json_encode(['error' => 'Hibás ID']); exit; }

// Bank rekord lekérése
$sql = "SELECT br.*, c.name AS church_name
        FROM bank_reconciliation br
        LEFT JOIN ots.churches c ON br.church_id = c.id
        WHERE br.id = $bank_id";
$res = $conn->query($sql);
if (!$res || $res->num_rows === 0) { header('Content-Type: application/json'); echo json_encode(['error' => 'Nem található']); exit; }
$row = $res->fetch_assoc();
// scope check: ensure user can access this record's church
require_church_access(intval($row['church_id'] ?? 0));

// Audit adatok lekérése
$audit = null;
$audit_res = $conn->query("SELECT * FROM audit_checklist WHERE bank_reconciliation_id = $bank_id");
if ($audit_res && $audit_res->num_rows > 0) { $audit = $audit_res->fetch_assoc(); }

$row['audit'] = $audit;
header('Content-Type: application/json');
echo json_encode($row);
