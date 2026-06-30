<?php
// API végpont a tanítható szabályok mentéséhez
// A Webix felületről hívható, amikor a revizor manuális párosítás után
// meg akarja tanítani a rendszert egy új kulcsszóra
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json');

$_POST = json_decode(file_get_contents('php://input'), true);

$churchId = $_POST['church_id'] ?? null;
$keyword  = trim($_POST['keyword'] ?? '');
$personId = $_POST['person_id'] ?? null;
$type     = $_POST['type'] ?? null;

if (empty($keyword)) {
    echo json_encode(["status" => "error", "message" => "A kulcsszó megadása kötelező."]);
    exit;
}

$pdo = get_pdo_connection();

$sql = "INSERT INTO audit_learning_rules (church_id, bank_keyword, target_person_id, target_type)
        VALUES (:church_id, :keyword, :person_id, :type)";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    'church_id' => $churchId,
    'keyword'   => $keyword,
    'person_id' => $personId,
    'type'      => $type
]);

echo json_encode(["status" => "success", "message" => "Szabály elmentve: " . $keyword]);
