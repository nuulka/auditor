<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <title>🕵️ Revizor Asszisztens 1.0 – Súgó</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; padding: 40px; }
        .help-card { max-width: 900px; margin: 0 auto; background: white; padding: 40px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        h2 { color: #0d6efd; border-bottom: 2px solid #0d6efd; padding-bottom: 10px; margin-bottom: 25px; }
        h4 { margin-top: 30px; color: #212529; }
        .step-box { background-color: #f1f3f5; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 5px solid #0d6efd; }
        .tip-box { background-color: #fff3cd; padding: 15px; border-radius: 6px; border-left: 5px solid #ffc107; margin-top: 30px; }
        code { background-color: #eee; padding: 2px 4px; border-radius: 4px; }
    </style>
</head>
<body>
<div class="container">

    <div class="d-flex justify-content-between align-items-center mb-3 px-3 py-2 bg-white rounded border shadow-sm">
        <div class="d-flex align-items-center gap-2">
            <a href="index.php" class="btn btn-outline-secondary btn-sm">🏠 Kezdőlap</a>
            <span class="fw-bold">🕵️ Revizor Asszisztens 1.0</span>
            <span class="text-muted mx-1">|</span>
            <span class="text-muted">Súgó</span>
        </div>
        <div class="d-flex align-items-center gap-1">
            <a href="logout.php" class="btn btn-outline-danger btn-sm">Kilépés</a>
        </div>
    </div>

    <div class="help-card">
        <h2>📖 🕵️ Revizor Asszisztens 1.0 - Használati Útmutató</h2>
        <p class="lead">Ez a bankegyeztető rendszer kezdetben a banki kivonatok és az OTS könyvelési adatok gyors, precíz összevetésére készült, később kiegészült egyéb funkciókkal is: automatikus progresszív párosítás (7 lépésben), tranzakció kereső (bank/OTS/ mindkettő), OTS tranzakciók lekérdezése és exportálása, egyházterületi (TET) számlák kezelése, több tételes párosítás, szöveges aggregációs keresés, valamint automatikus session hosszabbítás.</p>

        <div class="mb-4 p-3 bg-light rounded border">
            <h6 class="mb-2">🔗 Ugrás a fejezetekhez:</h6>
            <a href="#feltoltes" class="btn btn-sm btn-outline-primary me-1">1. Adatfeltöltés</a>
            <a href="#egyeztetes" class="btn btn-sm btn-outline-primary me-1">2. Ellenőrzés/Egyeztetés</a>
            <a href="#admin" class="btn btn-sm btn-outline-primary me-1">3. Jóváhagyás/Admin</a>
            <a href="#tippek" class="btn btn-sm btn-outline-primary">💡 Tippek</a>
        </div>

        <h4 id="feltoltes">1. Adatfeltöltési fázis <small class="text-muted">(upload.php)</small> <a href="#feltoltes" class="text-decoration-none ms-1" style="font-size:14px;">🔗</a></h4>
        <div class="step-box">
            <ul>
                <li><strong>Egyedi gyülekezet feltöltése:</strong> Ha egy konkrét egység kivonatát kaptad meg. Válaszd ki a nevet/ID-t a listából, és töltsd fel a CSV-t.</li>
                <li><strong>Tömeges (Automatikus) feltöltés:</strong> A leghatékonyabb mód. A rendszer az adatbázisban rögzített bankszámlaadatok alapján automatikusan felismeri, melyik tétel melyik gyülekezethez tartozik.</li>
                <li><strong>Emberbarát nevek:</strong> A kód a banki kódokat és generikus neveket (pl. HETEDNAPI ADVENTISTA EGYHÁZ) automatikusan lecseréli a valódi gyülekezetnevekre a tudástár alapján.</li>
            </ul>
            <div class="text-end"><a href="#" class="small text-muted">↑ Vissza a tetejére</a></div>
        </div>

        <h4 id="egyeztetes">2. Ellenőrzés és Egyeztetés <small class="text-muted">(reconciliation.php)</small> <a href="#egyeztetes" class="text-decoration-none ms-1" style="font-size:14px;">🔗</a></h4>
        <div class="step-box">
            <ul>
                <li><strong>Gyülekezet választás:</strong> A gyorsaság érdekében először válassz egy gyülekezetet a fejlécben. Ekkor csak az adott egység adatai töltődnek be (szerveroldali optimalizálás).</li>
                <li><strong>Automata Robot:</strong> A <strong>Progresszív mód</strong> először a 100%-os (0 napos) egyezéseket keresi és zárja le, majd tágítja az időablakot, végül intelligens szöveges keresővel párosít.</li>
                <li><strong>Kézi nyomozás:</strong> Ha egy összegnek nincs párja, a Modal ablakban rákereshetsz az összegre a teljes OTS adatbázisban (minden gyülekezetnél), hátha máshova könyvelték.</li>
            </ul>
            <div class="text-end"><a href="#" class="small text-muted">↑ Vissza a tetejére</a></div>
        </div>

        <h4 id="admin">3. Jóváhagyás és Adminisztráció <a href="#admin" class="text-decoration-none ms-1" style="font-size:14px;">🔗</a></h4>
        <div class="step-box">
            <ul>
                <li><strong>Párhuzamos nézet (Modal):</strong> Bármely tételre kattintva egymás mellett látod a banki és OTS adatokat (Dátum, Összeg, Megjegyzés, Rögzítő neve).</li>
                <li><strong>Tömeges OKézés:</strong> A képernyőn látható [IDŐ CSÚSZÁS] státuszú tételeket egyetlen gombbal elfogadhatod.</li>
                <li><strong>Excel Export:</strong> A leszűrt listát bármikor letöltheted CSV formátumban további elemzéshez.</li>
            </ul>
            <div class="text-end"><a href="#" class="small text-muted">↑ Vissza a tetejére</a></div>
        </div>

        <div id="tippek" class="tip-box">

        <div class="tip-box">
            <h5>💡 Gyors-tippek:</h5>
            <ul>
                <li>Használd a fejléc szűrőit! Pl. <code>-</code> jel az összegnél csak a kiadásokat mutatja.</li>
                <li>Az <code>OK</code> beírása a státusz szűrőbe csak a kész tételeket listázza ki.</li>
                <li>A 0-s ID-val rendelkező tételek a TET központi alapszámláihoz tartoznak.</li>
            </ul>
        </div>

        <div class="mt-5 text-center">
            <a href="index.php" class="btn btn-primary px-5">🏠 Vissza a főoldalra</a>
            <a href="logout.php" class="btn btn-outline-danger px-4 ms-2">Kilépés</a>
        </div>
    </div>
</div>
</body>
</html>