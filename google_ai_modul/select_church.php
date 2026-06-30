<?php
session_start();
require_once __DIR__ . '/db_connect.php';

// Gyülekezet lista lekérése
$churches = [];
try {
    $otsPdo = get_ots_pdo();
    $allChurches = $otsPdo->query("SELECT id, NAME FROM ots.churches WHERE id > 0 ORDER BY NAME")->fetchAll(PDO::FETCH_ASSOC);

    // Csak azok a gyülekezetek, amelyeknek van banki tétele vagy bankszámlája
    $pdo = get_pdo_connection();

    // church_bank_accounts-ban szereplő gyülekezet ID-k
    $localChurchIds = $pdo->query("SELECT DISTINCT church_id FROM church_bank_accounts WHERE church_id > 0")->fetchAll(PDO::FETCH_COLUMN);

    // bank_reconciliation-ban szereplő OTS CHURCH_ID-k
    $matchedChurchIds = $pdo->query("SELECT DISTINCT T.CHURCH_ID FROM bank_reconciliation br JOIN ots.TRANSACTIONS T ON T.RECORD_ID = br.ots_record_id")->fetchAll(PDO::FETCH_COLUMN);

    $activeIds = array_unique(array_merge($localChurchIds, $matchedChurchIds));

    foreach ($allChurches as $ch) {
        $id = (int)$ch['id'];
        if (in_array($id, $activeIds)) {
            $churches[$id] = $ch['NAME'];
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Form feldolgozás
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['church_id'])) {
    $cid = (int)$_POST['church_id'];
    if ($cid > 0 && isset($churches[$cid])) {
        $_SESSION['selected_church_id'] = $cid;
        $_SESSION['selected_church_name'] = $churches[$cid];
        header('Location: bank_reconciliation.php');
        exit;
    }
}

// Ha már ki van választva és NEM váltás, egyből tovább
if (isset($_SESSION['selected_church_id']) && !isset($_GET['change'])) {
    header('Location: bank_reconciliation.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revizor - Gyülekezet kiválasztása</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #fff; border-radius: 12px; padding: 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); max-width: 450px; width: 100%; }
        h1 { font-size: 1.4rem; color: #1565c0; margin-bottom: 8px; }
        p { color: #666; font-size: 0.9rem; margin-bottom: 20px; }
        label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9rem; color: #333; }
        select { width: 100%; padding: 10px 12px; border: 2px solid #ddd; border-radius: 6px; font-size: 0.95rem; background: #fff; }
        select:focus { border-color: #1565c0; outline: none; }
        button { width: 100%; padding: 12px; background: #1565c0; color: #fff; border: none; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer; margin-top: 16px; }
        button:hover { background: #0d47a1; }
        .error { color: #c62828; margin-bottom: 12px; font-size: 0.85rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>🏦 Revizor - Banki egyeztetés</h1>
        <p>Válaszd ki, melyik gyülekezettel szeretnél dolgozni:</p>

        <?php if (!empty($error)): ?>
            <div class="error">Hiba: <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <label for="church_id">Gyülekezet</label>
            <select name="church_id" id="church_id" required autofocus>
                <option value="">-- Válassz gyülekezetet --</option>
                <?php foreach ($churches as $id => $name): ?>
                    <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Tovább a banki egyeztetéshez →</button>
        </form>
    </div>
    <script>
    document.getElementById('church_id').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (this.value) this.form.submit();
        }
    });
    </script>
</body>
</html>
