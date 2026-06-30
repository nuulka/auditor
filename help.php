<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Revizor Asszisztens 1.0 – Súgó</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; padding: 40px; }
        .help-card { max-width: 960px; margin: 0 auto; background: white; padding: 40px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
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
            <a href="index.php" class="btn btn-outline-secondary btn-sm">Kezdőlap</a>
            <span class="fw-bold">Revizor Asszisztens 1.0</span>
            <span class="text-muted mx-1">|</span>
            <span class="text-muted">Súgó</span>
        </div>
        <div class="d-flex align-items-center gap-1">
            <a href="logout.php" class="btn btn-outline-danger btn-sm">Kilépés</a>
        </div>
    </div>

    <div class="help-card">
        <h2>Revizor Asszisztens 1.0 – Használati Útmutató</h2>
        <p class="lead">Bankegyeztető rendszer, amely a banki kivonatok és az OTS könyvelési adatok gyors, precíz összevetésére készült. Funkciói: automatikus progresszív párosítás (7 lépésben), tranzakció kereső, OTS tranzakciók lekérdezése és exportálása, egyházterületi (TET) számlák kezelése, több tételes párosítás, bizonylatellenőrzés, egyház-szintű hozzáférés-szabályozás.</p>

        <div class="mb-4 p-3 bg-light rounded border">
            <h6 class="mb-2">Ugrás a fejezetekhez:</h6>
            <a href="#feltoltes" class="btn btn-sm btn-outline-primary me-1">1. Adatfeltöltés</a>
            <a href="#egyeztetes" class="btn btn-sm btn-outline-primary me-1">2. Bankegyeztetés</a>
            <a href="#bizonylat" class="btn btn-sm btn-outline-primary me-1">3. Bizonylat Ellenőrzés</a>
            <a href="#kereso" class="btn btn-sm btn-outline-primary me-1">4. Tranzakció Kereső</a>
            <a href="#otsletolto" class="btn btn-sm btn-outline-primary me-1">5. OTS Letöltő</a>
            <a href="#admin" class="btn btn-sm btn-outline-primary me-1">6. Admin</a>
            <a href="#gyulekezet" class="btn btn-sm btn-outline-primary me-1">7. Gyülekezet Váltás</a>
            <a href="#tippek" class="btn btn-sm btn-outline-primary">Tippek</a>
        </div>

        <h4 id="feltoltes">1. Adatfeltöltés <small class="text-muted">(upload.php)</small></h4>
        <div class="step-box">
            <ul>
                <li><strong>Egyedi gyülekezet feltöltése:</strong> Válaszd ki a gyülekezetet a listából, és töltsd fel a CSV-t. A rendszer automatikusan párosítja a banki tételeket az OTS adatokkal.</li>
                <li><strong>Tömeges (Automatikus) feltöltés:</strong> A leghatékonyabb mód. A rendszer a bankszámlaszámok alapján automatikusan felismeri, melyik tétel melyik gyülekezethez tartozik. Egyetlen CSV-ben akár több gyülekezet adata is lehet.</li>
                <li><strong>Emberbarát nevek:</strong> A kód a banki kódokat és generikus neveket (pl. HETEDNAPI ADVENTISTA EGYHÁZ) automatikusan lecseréli a valódi gyülekezetnevekre.</li>
                <li><strong>Feltöltési előzmények:</strong> Minden feltöltés naplózásra kerül a feltöltési előzmények táblában, ahol a rekordszám, duplikátumok, hibák is láthatók.</li>
                <li><strong>AJAX tömeges feltöltés:</strong> A tömeges feltöltés háttérben, JavaScript segítségével történik, valós idejű progressziójelzővel.</li>
            </ul>
        </div>

        <h4 id="egyeztetes">2. Bankegyeztetés <small class="text-muted">(reconciliation.php)</small></h4>
        <div class="step-box">
            <ul>
                <li><strong>Gyülekezet szűrés:</strong> Válassz gyülekezetet a fejlécben. Adminok bármelyik gyülekezetet kiválaszthatják, nem-adminok a bejelentkezéskor kiválasztott gyülekezetben dolgoznak.</li>
                <li><strong>Automata párosítás (Progresszív mód):</strong> Először a 100%-os (0 napos) egyezéseket keresi és zárja le, majd tágítja az időablakot, végül intelligens szöveges keresővel párosít. 7 lépésben halad.</li>
                <li><strong>Kézi nyomozás:</strong> Ha egy összegnek nincs párja, a Modal ablakban rákereshetsz az összegre a teljes OTS adatbázisban (minden gyülekezetnél).</li>
                <li><strong>Több tételes párosítás:</strong> Egy banki tételhez több OTS tétel is rendelhető (pl. gyűjtőutalások). A részletek a bizonylat-ellenőrző modulban, az összegre kattintva tekinthetők meg.</li>
                <li><strong>Szöveges aggregációs keresés:</strong> A rendszer intelligensen csoportosítja a hasonló közleményű tételeket.</li>
                <li><strong>Státuszok:</strong> <code>UNCHECKED</code> (nem ellenőrzött), <code>STALE</code> (idő csúszás), <code>OK</code> (ellenőrzött). A státuszok tömegesen is módosíthatók.</li>
                <li><strong>Excel Export:</strong> A leszűrt lista CSV formátumban letölthető további elemzéshez.</li>
            </ul>
        </div>

        <h4 id="bizonylat">3. Bizonylat Ellenőrzés <small class="text-muted">(document_check.php)</small></h4>
        <div class="step-box">
            <ul>
                <li><strong>Ellenőrző lista:</strong> Banki tételek dokumentum-ellenőrzése: bizonylatok, aláírások, mellékletek meglétének rögzítése.</li>
                <li><strong>Összeg szerinti szűrés:</strong> A táblázat feletti <code>Összeg min</code> és <code>Összeg max</code> mezőkkel szűrhetsz összegtartományra.</li>
                <li><strong>Részletek megtekintése:</strong> Kattints bármely tétel összegére a részletes nézethez. Banki tételeknél két panel jelenik meg (banki adatok balra, OTS adatok jobbra). Készpénzes tételeknél csak az OTS panel jelenik meg.</li>
                <li><strong>OTS egyeztetés ellenőrzés:</strong> Ha az OTS tételek összege nem egyezik a banki összeggel ±1 Ft-on belül, a rendszer jelzi, hogy nincs hozzárendelt OTS tétel.</li>
                <li><strong>Ellenőrzési adatok mentése:</strong> Pipálható mezők (pénztárbizonylat, aláírások, számla, döntésszám, stb.), ellenőr neve, megjegyzések. Minden mentés naplózva az audit táblában.</li>
                <li><strong>Tömeges OK gomb:</strong> A [IDŐ CSÚSZÁS] státuszú tételek egy gombbal elfogadhatók.</li>
                <li><strong>Megjelenítési korlát:</strong> Maximum 2000 sor jeleníthető meg egyszerre – a találati szám a szűrőpanel alatt látható.</li>
            </ul>
        </div>

        <h4 id="kereso">4. Tranzakció Kereső <small class="text-muted">(search.php)</small></h4>
        <div class="step-box">
            <ul>
                <li><strong>Célzott keresés:</strong> Keresés banki és/vagy OTS tranzakciókban összeg, dátum, szöveges közlemény alapján.</li>
                <li><strong>Keresési módok:</strong> Banki adatokban, OTS adatokban, vagy mindkettőben egyszerre.</li>
                <li><strong>Gyors visszakeresés:</strong> Hasznos, ha egy konkrét tételt keresel több ezer rekord között.</li>
                <li><strong>Egyházszűrés:</strong> Adminok választhatnak gyülekezetet, nem-adminok a bejelentkezett gyülekezetükben keresnek.</li>
            </ul>
        </div>

        <h4 id="otsletolto">5. OTS Letöltő <small class="text-muted">(all_transactions/all_transactions_multi.php)</small></h4>
        <div class="step-box">
            <ul>
                <li><strong>OTS tranzakciók lekérése:</strong> OTS tranzakciók böngészése, szűrése, letöltése CSV formátumban.</li>
                <li><strong>Több gyülekezet együtt:</strong> TET (Tiszavidéki Egyházterület) számlák és gyülekezetek közötti váltás kombó dobozból.</li>
                <li><strong>Nem-adminok:</strong> Csak a bejelentkezett gyülekezet adatait látják, a kombó read-only.</li>
            </ul>
        </div>

        <h4 id="admin">6. Admin funkciók</h4>
        <div class="step-box">
            <ul>
                <li><strong>Feltöltés:</strong> Csak adminok tölthetnek fel CSV fájlokat. A felület automatikusan elrejti a Feltöltés menüpontot nem-adminok elől.</li>
                <li><strong>Teljes körű hozzáférés:</strong> Adminok minden gyülekezet adatait látják, váltogathatnak a gyülekezetek között.</li>
                <li><strong>Adatbázis reset:</strong> Az upload.php alján található linkkel az összes banki rekord törölhető (CSRF védelemmel).</li>
                <li><strong>Kihagyandó bankszámlák:</strong> Adminok kiválaszthatják, hogy mely bankszámlákat hagyja figyelmen kívül a tömeges import.</li>
                <li><strong>Debug mód:</strong> Fejlesztői kapcsolóval további információs panelek jeleníthetők meg.</li>
            </ul>
        </div>

        <h4 id="gyulekezet">7. Gyülekezet Váltás <small class="text-muted">(select-church.php)</small></h4>
        <div class="step-box">
            <ul>
                <li><strong>Első bejelentkezés:</strong> Minden felhasználónak választania kell egy gyülekezetet az első bejelentkezéskor.</li>
                <li><strong>Billentyűzetes navigáció:</strong> Gépelj be egy betűt a gyülekezet nevéből, a lista automatikusan ugrik az első találathoz. Több karakter egymás után pontosabb keresést tesz lehetővé (800ms időablak).</li>
                <li><strong>Váltás:</strong> A fejlécben lévő ikonnal bármikor másik gyülekezetre válthatsz.</li>
                <li><strong>Nem-adminok:</strong> Csak azok a gyülekezetek jelennek meg, amelyekhez a felhasználónak hozzáférése van.</li>
            </ul>
        </div>

        <div id="tippek" class="tip-box">
            <h5>Gyors-tippek:</h5>
            <ul>
                <li>Használd a fejléc szűrőit! Pl. <code>-</code> jel az összegnél csak a kiadásokat mutatja.</li>
                <li>Az <code>OK</code> beírása a státusz szűrőbe csak a kész tételeket listázza.</li>
                <li>A 0-s ID-val rendelkező tételek a TET központi alapszámláihoz tartoznak.</li>
                <li>A bizonylat-ellenőrzőben az összegre kattintva láthatod az OTS pár részleteit.</li>
                <li>A gyülekezet-választóban egyszerűen gépelj be egy betűt a gyors ugráshoz.</li>
            </ul>
        </div>

        <div class="mt-5 text-center">
            <a href="index.php" class="btn btn-primary px-5">Vissza a főoldalra</a>
            <a href="logout.php" class="btn btn-outline-danger px-4 ms-2">Kilépés</a>
        </div>
    </div>
</div>
</body>
</html>
