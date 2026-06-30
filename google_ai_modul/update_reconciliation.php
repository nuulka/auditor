<?php
// AJAX végpont státusz/megjegyzés mentéséhez
// GET paraméterként is hívható: update_reconciliation.php?id=X&status=Y&comment=Z
require_once __DIR__ . '/db_connect.php';

// JSON POST fogadása
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input) {
        $_POST = $input;
    }
}

// GET paraméterek is elfogadva
$id = $_POST['id'] ?? $_GET['id'] ?? null;
$status = $_POST['status'] ?? $_GET['status'] ?? null;
$comment = $_POST['comment'] ?? $_GET['comment'] ?? '';

if ($id && $status) {
    $pdo = get_pdo_connection();
    $stmt = $pdo->prepare("UPDATE bank_statements SET status = ?, comment = ? WHERE id = ?");
    $stmt->execute([$status, $comment, (int)$id]);
}

// Átirányítás a főoldalra
header('Location: bank_reconciliation.php?msg=' . urlencode('Státusz frissítve.') . '&type=success');
exit;
