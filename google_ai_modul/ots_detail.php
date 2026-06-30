<?php
/**
 * OTS tétel részletek – összes sor egy RECORD_ID-hoz (tized cédula)
 * Paraméter: record_id (GET)
 * Válasz: JSON { items: [...], summary: {...} }
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db_connect.php';

$recordId = (int)($_GET['record_id'] ?? 0);
if ($recordId <= 0) {
    echo json_encode(['items' => [], 'summary' => null]);
    exit;
}

try {
    $pdo = get_ots_pdo();
    // Összes sor ehhez a RECORD_ID-hoz (tized cédula tételek)
    $sql = "SELECT T.RECORD_ID, T.CHURCH_ID, T.AMOUNT, T.TYPE, T.DATETIME,
                   T.CASH_DOCUMENT_NUMBER, T.DECISION_NUMBER,
                   T.PERSON_ID, T.NAME_ID, T.NAME2_ID, T.FUND_ID, T.EDITED_BY,
                   T.IBAN, T.ACCOUNT_NUMBER, T.VIA_BANK,
                   TRIM(CONCAT(IFNULL(p.NAME_PREFIX, ''), ' ', IFNULL(p.NAME, ''), ' ', IFNULL(p.NAME_SUFFIX, ''))) AS person_name,
                   nt1.NAME AS tx_name, nt1.NAME_INDEX AS tx_name_index,
                   nt2.NAME AS tx_name2, nt2.NAME_INDEX AS tx_name2_index,
                   tt.NAME AS type_name, tt.id AS type_id,
                   f.NAME AS fund_name,
                   u.NAME AS editor_name
            FROM ots.TRANSACTIONS T
            LEFT JOIN ots.PERSONS p ON T.PERSON_ID = p.id
            LEFT JOIN ots.names_of_transaction nt1 ON T.NAME_ID = nt1.id
            LEFT JOIN ots.names_of_transaction nt2 ON T.NAME2_ID = nt2.id
            LEFT JOIN ots.TRANSACTION_TYPE tt ON T.TYPE = tt.id
            LEFT JOIN ots.funds f ON T.FUND_ID = f.id
            LEFT JOIN ots.USERS u ON T.EDITED_BY = u.id
            WHERE T.RECORD_ID = ?
            ORDER BY T.TYPE, T.AMOUNT";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$recordId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        echo json_encode(['items' => [], 'summary' => null]);
        exit;
    }

    // Összesítés
    $totalAmount = 0;
    $incomeTotal = 0;
    $expenseTotal = 0;
    foreach ($items as &$it) {
        $amt = (float)$it['AMOUNT'];
        $isExpense = in_array((int)$it['TYPE'], [7, 9, 20]);
        $computed = $isExpense ? -$amt : $amt;
        $it['computed_amount'] = $computed;
        $totalAmount += $computed;
        if ($computed >= 0) $incomeTotal += $computed;
        else $expenseTotal += $computed;
    }
    unset($it);

    // Összesített nézet (rekord szintű)
    $first = $items[0];
    $summary = [
        'record_id' => $recordId,
        'church_id' => $first['CHURCH_ID'],
        'date' => $first['DATETIME'],
        'doc_number' => $first['CASH_DOCUMENT_NUMBER'],
        'decision_number' => $first['DECISION_NUMBER'],
        'total_amount' => $totalAmount,
        'income_total' => $incomeTotal,
        'expense_total' => $expenseTotal,
        'editor_name' => $first['editor_name'],
        'person_name' => $first['person_name'],
        'fund_name' => $first['fund_name'],
        'type_name' => $first['type_name'],
        'item_count' => count($items),
    ];

    echo json_encode([
        'items' => $items,
        'summary' => $summary,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['items' => [], 'summary' => null, 'error' => $e->getMessage()]);
}
