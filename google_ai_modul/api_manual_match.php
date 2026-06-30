<?php
// AJAX végpont kézi párosításhoz
// GET paraméterként is hívható: api_manual_match.php?bank_id=X&ots_ids[]=1&ots_ids[]=2
require_once __DIR__ . '/db_connect.php';

// JSON POST fogadása
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input) {
        $_POST = $input;
    }
}

$bankId = $_POST['bank_id'] ?? $_GET['bank_id'] ?? null;
$otsIds = $_POST['ots_ids'] ?? $_GET['ots_ids'] ?? [];

if (!is_array($otsIds)) {
    $otsIds = [$otsIds];
}
$otsIds = array_map('intval', $otsIds);

$message = 'Hiányzó adatok.';
$type = 'error';

if ($bankId && !empty($otsIds)) {
    try {
        $pdo = get_pdo_connection();
        $pdo->beginTransaction();
        $status = count($otsIds) > 1 ? 'MANY_TO_ONE' : 'MATCHED';
        $stmt1 = $pdo->prepare("INSERT INTO bank_reconciliation (bank_statement_id, ots_record_id) VALUES (?, ?)");
        foreach ($otsIds as $oid) {
            $stmt1->execute([(int)$bankId, $oid]);
        }
        $stmt2 = $pdo->prepare("UPDATE bank_statements SET status = ? WHERE id = ?");
        $stmt2->execute([$status, (int)$bankId]);
        $pdo->commit();
        $message = count($otsIds) . ' OTS tétel párosítva.';
        $type = 'success';
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = 'Hiba: ' . $e->getMessage();
    }
}

header('Location: bank_reconciliation.php?msg=' . urlencode($message) . '&type=' . urlencode($type));
exit;
