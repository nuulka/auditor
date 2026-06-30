<?php
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json');

$pdo = get_pdo_connection();
$stmt = $pdo->query("SELECT id, value_date, amount, description, beneficiary_name, status, comment FROM bank_statements ORDER BY value_date DESC");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(["data" => $rows, "total_count" => count($rows)]);
