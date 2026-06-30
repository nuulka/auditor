<?php
// Google AI Modul — Bank Reconciliation Module
error_reporting(E_ALL);
ini_set('display_errors', 0);
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Google AI Modul</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; margin: 0; padding: 40px; background: #f5f5f5; color: #222; }
        .container { max-width: 600px; margin: 0 auto; }
        h1 { font-size: 1.5rem; color: #1565c0; }
        .card { background: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-top: 16px; }
        .card a { display: block; padding: 12px 16px; background: #e3f2fd; border-radius: 6px; color: #1565c0; text-decoration: none; margin-bottom: 8px; font-weight: 500; }
        .card a:hover { background: #bbdefb; }
        .card a small { font-weight: normal; color: #666; display: block; font-size: 0.8rem; margin-top: 2px; }
        .footer { margin-top: 20px; font-size: 0.8rem; color: #999; }
    </style>
</head>
<body>
<div class="container">
    <h1>🧾 Google AI Modul</h1>
    <p style="color:#666;">Bank Reconciliation — Banki egyeztető modul</p>

    <div class="card">
        <a href="bank_reconciliation.php">
            📊 Bankegyeztetés felület
            <small>CSV import, automatikus párosítás, kézi összekötés, státuszkezelés</small>
        </a>
        <a href="test.php">
            🔍 Diagnosztika
            <small>Adatbázis kapcsolat és fájlrendszer ellenőrzése</small>
        </a>
        <a href="database/schema.sql" target="_blank">
            🗄️ Adatbázis séma (SQL)
            <small>A modul adatbázis tábláinak DDL utasításai</small>
        </a>
    </div>

    <div class="footer">
        Google AI által generált terv alapján — <?= date('Y') ?>
    </div>
</div>
</body>
</html>
