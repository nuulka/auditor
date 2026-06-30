<?php
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json');

$pdo = get_pdo_connection();

// Még nem párosított OTS tételek lekérése (amik banki fizetésűek)
// A bank_reconciliation tábla a revizor_db-ben van, az ots.TRANSACTIONS az ots db-ben
$sql = "SELECT T.RECORD_ID,
               T.DATETIME,
               T.CASH_DOCUMENT_NUMBER,
               SUM(CASE WHEN T.TYPE IN (20, 9) THEN -1 * T.AMOUNT ELSE T.AMOUNT END) as computed_amount,
               T.CHURCH_ID
        FROM ots.TRANSACTIONS T
        WHERE T.VIA_BANK <> 0
        AND T.RECORD_ID NOT IN (SELECT ots_record_id FROM bank_reconciliation)
        GROUP BY T.RECORD_ID, T.DATETIME, T.CASH_DOCUMENT_NUMBER, T.CHURCH_ID
        ORDER BY T.DATETIME DESC";

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(["data" => $rows, "total_count" => count($rows)]);
