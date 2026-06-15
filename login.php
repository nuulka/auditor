<?php
session_start();
session_destroy();
?><!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <title>Revizor - Bejelentkezés</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-card { max-width: 420px; width: 100%; background: white; padding: 40px; border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); text-align: center; }
        .logo { font-size: 48px; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo">🕵️</div>
        <h4 class="mb-1">Revizori Panel</h4>
        <p class="text-muted small mb-4">Bankegyeztető rendszer</p>
        <hr>
        <p class="mb-3">A munkameneted lejárt. Jelentkezz be az OTS rendszerbe, majd gyere vissza!</p>
        <ol class="text-start small text-muted mb-4">
            <li>Kattints a <strong>Belépés az OTS-be</strong> gombra</li>
            <li>Jelentkezz be az OTS felületén (új lapon nyílik meg)</li>
            <li>Térj vissza ide és kattints a <strong>Beléptem, folytatom</strong> gombra</li>
        </ol>
        <div class="d-grid gap-2">
            <button class="btn btn-primary btn-lg" onclick="window.open('../ots/index.php','_blank')">🔑 Belépés az OTS-be</button>
            <a href="index.php" class="btn btn-success">✅ Beléptem, folytatom</a>
        </div>
        <p class="mt-3 text-muted small">Ha nincs OTS fiókod, kérj segítséget a rendszergazdától.</p>
    </div>
</body>
</html>
