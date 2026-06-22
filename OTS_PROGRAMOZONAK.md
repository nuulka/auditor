# Revizor Asszisztens 1.0 — Műszaki Leírás az OTS Rendszergazda / Programozó Részére

## 1. Bevezetés

Jelen dokumentum célja, hogy az OTS rendszert fejlesztő és üzemeltető programozó számára **teljes körű műszaki áttekintést** adjon a Revizor bankegyeztető alkalmazás működéséről, adatelérési mintáiról, biztonsági modelljéről. A dokumentum minden olyan kérdésre kitér, amely az OTS integritásával, biztonságával és az adatok helyes használatával kapcsolatban felmerülhet.

---

## 2. Architektúra Áttekintés

```
┌─────────────────────────────────────────────────────────────┐
│                     Felhasználó (Revizor)                   │
│                    (Web böngésző)                           │
└──────────┬──────────────────────────────────────┬──────────┘
           │                                      │
           ▼                                      ▼
┌──────────────────────┐           ┌──────────────────────────┐
│   Revizor Web App    │           │   OTS Web App            │
│  (PHP 8.3 / Apache)  │◄─AJAX────│  (PHP 8.3 / Apache)      │
│                      │   poll    │                          │
│  localhost/revizor/  │           │  localhost/ots/          │
└──────────┬───────────┘           └──────────┬───────────────┘
           │                                  │
           │      Ugyanaz a MySQL szerver      │
           │      (localhost, root, nincs pw)   │
           ▼                                  ▼
┌──────────────────────┐           ┌──────────────────────────┐
│   revizor_db         │    CSAK   │   ots                    │
│   ─────────────      │   OLVAS   │   ───────────            │
│   bank_reconciliation│◄─────────│   TRANSACTIONS            │
│   bank_reconciliation│    │      │   churches               │
│   items              │    │      │   PERSONS                │
│   church_bank_accounts│   │      │   NAMES_OF_TRANSACTION   │
│   provider_keywords  │    │      │   funds                  │
│                      │    │      │   TRANSACTION_TYPE       │
│                      │    │      │   USERS                  │
│                      │    │      │   transfers_to_conference│
│                      │    │      │   (és további OTS táblák)│
└──────────────────────┘           └──────────────────────────┘
```

**Kulcsinformáció:** A Revizor és az OTS **ugyanazon a MySQL szerveren** fut (`localhost`). A Revizor **nem fér hozzá az OTS szerverhez hálózaton keresztül** — ugyanazt a MySQL kapcsolatot használja, mint az OTS.

---

## 3. Adatbázis Kapcsolat

A Revizor a PHP `mysqli` kapcsolaton keresztül éri el az adatbázist:

```php
$conn = new mysqli('localhost', 'root', '', 'revizor_db');  // Revizor saját DB
$conn->query("SELECT ... FROM ots.TRANSACTIONS T ...");     // OTS tábla olvasása
```

- A kapcsolat **localhost**, nincs hálózati kitettség
- Ugyanaz a `root` felhasználó, mint az OTS-é
- Az OTS táblákat a **`ots.` prefixxel** éri el (pl. `ots.TRANSACTIONS`)

---

## 4. OTS Táblák és Oszlopok — Teljes Lista

Az alábbi táblázat tartalmazza **minden OTS tábla** és oszlop teljes listáját, amelyet a Revizor olvas.

### 4.1. `ots.TRANSACTIONS` — Tranzakciók

| Oszlop | Használat | Cél |
|---|---|---|
| `RECORD_ID` | JOIN kulcs, párosítás | Egyedi tranzakcióazonosító; a Revizor ezt tárolja `bank_reconciliation.ots_record_id`-ként és `bank_reconciliation_items.record_id`-ként |
| `CHURCH_ID` | WHERE szűrés | Gyülekezet azonosító; a banki tétel gyülekezetének megfelelő OTS tranzakciók szűrésére |
| `AMOUNT` | Összehasonlítás, SUM | Tranzakció összege. Kiadás típusoknál (`TYPE IN (7,9,20)`) `-1 * AMOUNT`-ként kezelve |
| `TYPE` | Feltétel | Tranzakció típusa; kiadás/bevétel előjelének meghatározásához |
| `DATETIME` | Dátum összehasonlítás | Tranzakció dátuma; banki dátumhoz viszonyított kereséshez (±5-70 nap) |
| `CASH_DOCUMENT_NUMBER` | Megjelenítés | Bizonylatszám (opcionális, lehet NULL) |
| `DECISION_NUMBER` | Megjelenítés | Határozati szám |
| `PERSON_ID` | JOIN → `ots.PERSONS` | Személy kapcsolat; a partner név előállításához |
| `NAME_ID` | JOIN → `ots.NAMES_OF_TRANSACTION` | Elsődleges tranzakciónév |
| `NAME2_ID` | JOIN → `ots.NAMES_OF_TRANSACTION` | Másodlagos tranzakciónév |
| `FUND_ID` | JOIN → `ots.funds` | Alap (pl. Tized, Szombatiskolai adomány) |
| `EDITED_BY` | JOIN → `ots.USERS` | Rögzítő nevének megjelenítéséhez |
| `VIA_BANK` | WHERE szűrés | Banki tranzakciók szűrése (`VIA_BANK <> 0`) |

**A Revizor soha nem módosítja a `TRANSACTIONS` tábla egyetlen sorát sem.**

### 4.2. `ots.churches` — Gyülekezetek

| Oszlop | Használat | Cél |
|---|---|---|
| `id` | JOIN kulcs | Gyülekezet azonosító — nincs írás |
| `name` | Megjelenítés | Gyülekezet neve a táblázatban |

### 4.3. `ots.PERSONS` — Személyek

| Oszlop | Használat | Cél |
|---|---|---|
| `id` | JOIN kulcs | Személy azonosító; a `TRANSACTIONS.PERSON_ID`-hoz JOIN-olva |
| `NAME_PREFIX` | Megjelenítés | Név előtag |
| `NAME` | Megjelenítés | Vezetéknév |
| `NAME_SUFFIX` | Megjelenítés | Név utótag |

### 4.4. `ots.NAMES_OF_TRANSACTION` — Tranzakciónév-jegyzék

| Oszlop | Használat | Cél |
|---|---|---|
| `id` | JOIN kulcs | A `TRANSACTIONS.NAME_ID` és `NAME2_ID`-hez JOIN-olva |
| `NAME` | Megjelenítés | A tranzakció szöveges leírása (pl. "Tized", "Szombatiskolai adomány") |

**Megjegyzés:** A `TRANSACTIONS` táblában **nincs** `REASON` oszlop. A szöveges leírás a `names_of_transaction.NAME` mezőből származik a fenti JOIN-on keresztül.

### 4.5. `ots.funds` — Alapok

| Oszlop | Használat | Cél |
|---|---|---|
| `id` | JOIN kulcs | A `TRANSACTIONS.FUND_ID`-hoz JOIN-olva |
| `NAME` | Megjelenítés | Alap neve (pl. "Tized", "Szombatiskolai adomány", "Egyházterületnek") |

### 4.6. `ots.TRANSACTION_TYPE` — Tranzakció típusok

| Oszlop | Használat | Cél |
|---|---|---|
| `id` | JOIN kulcs | A `TRANSACTIONS.TYPE`-hoz JOIN-olva |
| `NAME` | Megjelenítés | Típus megnevezése |

### 4.7. `ots.USERS` — Felhasználók

| Oszlop | Használat | Cél |
|---|---|---|
| `id` | JOIN kulcs | A `TRANSACTIONS.EDITED_BY`-hoz JOIN-olva |
| `NAME` | Megjelenítés | Rögzítő neve |

### 4.8. `ots.transfers_to_conference` — Konferencia utalások

| Oszlop | Használat | Cél |
|---|---|---|
| `CHURCH_ID` | WHERE | Gyülekezet szűrés |
| `AMOUNT` | Összehasonlítás | Összeg egyezés ellenőrzés |
| `YEAR, MONTH, DAY` | Dátum | Dátum összehasonlítás (`CONCAT(YEAR, '-', ...)`) |
| `VIA_BANK` | WHERE | Banki utalások szűrése (`VIA_BANK = 1`) |
| `CASH_DOCUMENT_NUMBER` | Megjelenítés | Bizonylatszám |

---

## 5. Revizor Saját Táblái (Külön Adatbázis: `revizor_db`)

Ezek a táblák **teljesen elkülönülnek** az OTS adatbázistól. A Revizor ezekbe **ír**, de ezeket a táblákat **az OTS soha nem éri el**.

### `bank_reconciliation`

| Oszlop | Típus | Leírás |
|---|---|---|
| `id` | INT (PK, AUTO_INC) | Belső azonosító |
| `row_hash` | VARCHAR(64) UNIQUE | Duplikátum szűréshez (MD5 hash) |
| `church_id` | INT | OTS gyülekezet azonosító (csak referencia, nincs FK) |
| `bank_date` | DATE | Banki tranzakció dátuma |
| `bank_amount` | DECIMAL(12,2) | Banki tranzakció összege |
| `bank_desc` | TEXT | Banki közlemény |
| `bank_ext_acc` | VARCHAR(64) | Külső fél számlaszáma |
| `bank_ext_name` | VARCHAR(255) | Külső fél neve |
| `bank_ext_ref` | VARCHAR(64) | Tranzakció azonosító |
| `bank_init_name` | VARCHAR(255) | Kezdeményező neve |
| `bank_init_acc` | VARCHAR(64) | Kezdeményező számlaszáma |
| `bank_ben_name` | VARCHAR(255) | Kedvezményezett neve |
| `bank_ben_acc` | VARCHAR(64) | Kedvezményezett számlaszáma |
| `ots_record_id` | INT (NULL) | OTS `RECORD_ID` (ha párosítva van) — **csak referencia, nincs FK** |
| `ots_date` | DATE (NULL) | OTS tranzakció dátuma (cache) |
| `ots_doc` | VARCHAR(50) (NULL) | OTS bizonylatszám (cache) |
| `ots_amount` | DECIMAL(12,2) (NULL) | OTS tranzakció összege (cache) |
| `status` | VARCHAR(20) | `UNCHECKED`, `OK`, `CSUSZAS` |
| `comment` | TEXT | Párosítás megjegyzése |

### `bank_reconciliation_items`

| Oszlop | Típus | Leírás |
|---|---|---|
| `id` | INT (PK, AUTO_INC) | Belső azonosító |
| `reconciliation_id` | INT (FK → `bank_reconciliation.id`) | Szülő rekord |
| `record_id` | INT | OTS `RECORD_ID` — **csak referencia, nincs FK** |
| `amount` | DECIMAL(12,2) | OTS tranzakció összege (előjelhelyesen) |

**Több OTS tétel egy banki sorhoz:** Ez a tábla teszi lehetővé, hogy egy banki tételhez (pl. jutaléklevonás) több OTS tranzakciót lehessen párosítani.

### `church_bank_accounts`

| Oszlop | Típus | Leírás |
|---|---|---|
| `church_id` | INT | OTS gyülekezet azonosító |
| `bank_account_clean` | VARCHAR(64) | Bankszámlaszám (csak számok) |

A gyülekezetek bankszámlaszámait tárolja. Ezt az adatot az OTS-ből **exportáltuk** és a `revizor_db`-be töltöttük, hogy a Revizor felismerje, melyik banki tétel melyik gyülekezethez tartozik. Az **eredeti `accounts.php` fájlt** töröltük — a bankszámlaadatok már nem állnak rendelkezésre fájlrendszerben.

### `provider_keywords`

| Oszlop | Típus | Leírás |
|---|---|---|
| `bank_keyword` | VARCHAR(100) | Banki közleményben keresendő kulcsszó |
| `ots_keyword` | VARCHAR(100) | OTS leírásban keresendő kulcsszó |

14 előre meghatározott kulcsszópár a szöveges hasonlósági párosításhoz (pl. "tized" ↔ "TIZED", "szombatiskola" ↔ "SZOMBATISKOLAI ADOMÁNY").

---

## 6. Adatelérési Minták — Hogyan és Mikor Kérdezi Le a Revizor az OTS-t?

### 6.1. Feltöltéskori auto-match (`upload.php`)

- **Mikor:** Minden egyes CSV sor beszúrása után
- **Lekérdezés:** `SELECT ... FROM ots.TRANSACTIONS WHERE CHURCH_ID = ? AND DATETIME BETWEEN ? AND ? AND VIA_BANK <> 0 HAVING SUM(...) = ?`
- **Korlát:** Csak az adott gyülekezetre, 5 napos ablakban
- **Találat:** Maximum 1 sor (ha több, nem párosít)

### 6.2. Progresszív auto-match (`upload.php`)

- **Mikor:** A feltöltés után, 7 passzban
- **Passzok:** [0, 3, 6, 12, 35, 60, 'text'] nap eltérés
- **Lekérdezés:** `SELECT ... FROM ots.TRANSACTIONS T ... WHERE T.CHURCH_ID = ? AND ABS(PERIOD_DIFF(...)) <= 1 ...`
- **Biztonsági szűrő:** `AND T.RECORD_ID NOT IN (SELECT ots_record_id FROM bank_reconciliation ... UNION SELECT record_id FROM bank_reconciliation_items)` — **már párosított RECORD_ID-k kizárva**
- **Lezárás:** Minden passz után UPDATE a banki rekordon + INSERT a bank_reconciliation_items táblába

### 6.3. Kézi OTS keresés a modálban (`reconciliation.php`)

- **Mikor:** A revizor rákattint egy banki tételre
- **Lekérdezés:** 60 napos ablak, összeg alapján, `RECORD_ID`-nként GROUP BY SUM
- **Kiegészítő lekérdezés:** Párosítatlan OTS rekordok kilistázása (70 napos ablak, előjel szerint szűrve)
- **Biztonsági szűrő:** Ugyanaz a `NOT IN` minta, mint fent

### 6.4. Fő tábla nézet (`reconciliation.php`)

- **Kétlépéses lekérdezés:**
  1. `SELECT ... FROM bank_reconciliation ... LIMIT 50` (saját DB, gyors)
  2. `SELECT ... FROM ots.TRANSACTIONS WHERE RECORD_ID IN (...) ` (csak a párosított rekordokhoz)
- **Idő:** ~14ms (a korábbi 2776ms helyett)

### 6.5. `all_transactions_multi.php` — OTS tranzakciók lekérdezése

- **Mikor:** A revizor kiválaszt egy gyülekezetet, dátumtartományt és forgalom típust
- **Lekérdezés:** OTS `TRANSACTIONS` + `PERSONS` + `NAMES_OF_TRANSACTION` + `transfers_to_conference`
- **Cél:** OTS tranzakciók exportálása/áttekintése — ugyanaz, mint amit az OTS natív felülete nyújt

---

## 7. Biztonsági Modell

### 7.1. Módosítási Tilalom

A Revizor **SEMMILYEN körülmények között nem módosítja az OTS adatbázist.**

| Művelet | OTS adatbázis | Revizor adatbázis |
|---|---|---|
| INSERT | ❌ | ✅ `bank_reconciliation`, `bank_reconciliation_items` |
| UPDATE | ❌ | ✅ `bank_reconciliation` (státusz, ots_record_id, ots_date...) |
| DELETE | ❌ | ✅ `bank_reconciliation` (csak `reset_db.php` fejlesztői eszköz) |
| SELECT | ✅ (csak olvas) | ✅ |

A PHP kódban **egyetlen `UPDATE` vagy `INSERT` utasítás sem hivatkozik `ots.*` táblára.** Ez a teljes kódban garantált.

### 7.2. Session-alapú Hitelesítés

- A Revizor **nem tárol jelszavakat**
- A felhasználó az **OTS saját bejelentkező felületén** (`ots/index.php`) keresztül azonosítja magát
- A Revizor az OTS session változóit olvassa: `$_SESSION[GC_LOGIN_COOKIE]`, `$_SESSION[GN_CHURCH_ID]`, `$_SESSION[GN_USER_RIGHTS]`
- Az OTS session 10 perc inaktivitás után lejár; a Revizor ezt 60 percre hosszabbítja meg a revizori munkamenet idejére
- A kilépés (`logout.php`) törli a session adatokat

### 7.3. CSRF Védelem

Minden írási műveletet (párosítás mentése, feltöltés) **CSRF token** véd:
- `$_SESSION['csrf_token']` generálása oldalbetöltéskor
- Minden AJAX/POST kérésben `csrf_token` paraméter elküldése
- Szerveroldali validáció: `$_POST['csrf_token'] !== $_SESSION['csrf_token']`

### 7.4. SQL Injection Védelem

- Minden dinamikus paraméter **előkészített utasításokkal** (`bind_param`) kerül átadásra
- Kivétel: az `ots.TRANSACTIONS` lekérdezésekben a típus-azonosítók (`$exp_types_str`) és más fix értékek, amelyek **nem felhasználói inputból** származnak, hanem a kódban hardcode-olt konstansok (`GN_TRANSACTION_TYPE_PAYMENT=20`, `GN_TRANSACTION_TYPE_SPECIAL_TARGET_VIA_CONFERENCE=7`, `GN_TRANSACTION_TYPE_ACCEPTED_SUBTRACTION=9`)
- A `$where_sql` és `$order_sql` változók csak belső vezérlésű értékeket tartalmaznak (pl. `WHERE b.church_id = X` ahol X intval()-al van biztosítva)

### 7.5. Adatok Megjelenítése

- A bankszámlaszámok, nevek és személyes adatok **nem kerülnek naplózásra**
- A megjelenített adatokat **HTML escape** (`webix.template.escape()`, PHP oldalon nincs közvetlen `echo`)

### 7.6. Hozzáférési Kör

- A Revizor csak azokhoz a gyülekezetekhez fér hozzá, **amelyeknek a bankszámlaszáma a `church_bank_accounts` táblában szerepel**
- A **TET (Egyházterületi) számlák** (`church_id = 0`) **automatikusan kiszűrésre kerülnek** a feltöltés során, mert ezek nem tartoznak egyetlen gyülekezethez sem

---

## 8. Adatintegritás — Hogyan Előzi Meg a Revizor az Adatok Keveredését?

### 8.1. Két Külön Adatbázis

- OTS adatok: `ots.*` táblák (csak olvasott)
- Revizor adatok: `revizor_db.*` táblák (írt és olvasott)
- A két adatbázis között **nincs idegen kulcs kapcsolat**

### 8.2. Referenciális Integritás

Habár a Revizor az OTS `RECORD_ID`-t tárolja, **nincs FOREIGN KEY constraint** az OTS táblák felé. Ez szándékos:
- Az OTS adatbázis strukturális változásai (pl. törölt rekordok) **nem okoznak hibát** a Revizorban
- A Revizor működésének **nem előfeltétele** az OTS adatok séma szerinti integritása
- Egy `ots_record_id` csak információs célú — ha az OTS-ben a megfelelő rekord már nem létezik, csupán üres adat jelenik meg a felületen

### 8.3. Church ID Leképezés

A gyülekezetek azonosítása a bankszámlaszám alapján történik a `church_bank_accounts` táblán keresztül:
```
CSV sor → bankszámlaszám → church_bank_accounts.bank_account_clean → church_id
```

Ez biztosítja, hogy:
- Minden tétel a **megfelelő gyülekezethez** kerül
- A TET számlák automatikusan kiszűrésre kerülnek (`church_id = 0 → continue`)
- Egy gyülekezet tételei **nem keverednek** más gyülekezet tételeivel

### 8.4. Duplikátum Védelem

- A CSV feltöltés során minden sor egyedi `row_hash` (MD5) alapján kerül beszúrásra
- A `row_hash` oszlop UNIQUE index-szel van ellátva — a másodszori feltöltés duplikátumait automatikusan kiszűri a `1062` MySQL hiba elkapásával

### 8.5. RECORD_ID Duplikáció Megakadályozása

Minden OTS keresés tartalmazza a következő szűrést:
```sql
AND T.RECORD_ID NOT IN (
    SELECT ots_record_id FROM bank_reconciliation WHERE ots_record_id IS NOT NULL ...
    UNION
    SELECT record_id FROM bank_reconciliation_items
)
```
Ez garantálja, hogy **egy OTS tranzakció csak egyszer** legyen párosítva.

---

## 9. Teljesítmény — Hatás az OTS Adatbázisra

### 9.1. Lekérdezési Minták

| Művelet | Gyakoriság | Hatás |
|---|---|---|
| Feltöltéskori auto-match | Ritka (heti 1-2) | 1 lekérdezés / CSV sor (~6000 sor = 6000 lekérdezés) |
| Progresszív auto-match | Ritka (heti 1-2) | ~7 lekérdezés / alkalom |
| Kézi OTS keresés | Revizori munka során | 1-2 lekérdezés / gombnyomás |
| Fő tábla nézet | Oldalbetöltéskor | 2 lekérdezés (1 a saját DB-be, 1 az OTS-be) |
| Tranzakció lekérdezés | Ritka (exportáláskor) | 1 lekérdezés / alkalom |

### 9.2. Optimalizációk

- A **fő tábla lekérdezése** két lépésben történik: először a saját DB-ből (LIMIT 50), utána az OTS-ből csak a párosított rekordok ID-i alapján
- Az OTS lekérdezések mindig **gyülekezetre szűrve** (`CHURCH_ID = ?`) futnak, sosem a teljes táblán
- A dátumablak minden esetben korlátozott (max ±70 nap)
- A progresszív passzok fokozatosan bővítik a keresési ablakot, nem egyszerre terhelik az adatbázist

### 9.3. Terhelés

- Az **összes Revizor lekérdezés együttes terhelése** elenyésző az OTS napi használatához képest
- Becslés: egy teljes feltöltési kör (6000 sor + progresszív auto-match) ~**1-2 másodperc** összesített MySQL időt vesz igénybe

---

## 10. Session és Autentikáció — Részletes Működés

### 10.1. Belépési Folyamat

1. A revizor a `login.php` oldalon a "Belépés az OTS-be" gombra kattint
2. Egy **popup ablakban** megnyílik `http://localhost/ots/index.php` (az OTS bejelentkező oldala)
3. A revizor beírja az OTS **felhasználónevét és jelszavát** az OTS saját felületén
4. A `login.php` **1.5 másodpercenként pollolja** a session státuszt (`login.php?check=1`)
5. Ha az OTS session érvényes (`GC_LOGIN_COOKIE` be van állítva), a Revizor átirányít a főoldalra

### 10.2. Session Élettartam

- **OTS timeout:** 10 perc (`GN_SESSION_TIMEOUT = 600`)
- **Revizor timeout:** 20 perc (`REVIZOR_SESSION_DURATION = 1200`)
- A Revizor minden oldalbetöltéskor frissíti a `$_SESSION[GN_LAST_ACTIVE]` értéket **a `session_handler.php` meghívása előtt**, ami megakadályozza, hogy az OTS 10 perces timeoutja idő előtt kiléptesse a revizort

### 10.3. Biztonsági Megfontolások

- **Két külön session:** Az OTS és a Revizor ugyanazt a PHP session-t használja (közös `PHPSESSID` cookie)
- **Lejárat kezelése:**
  - Ha az OTS session lejár: `session_handler.php` eltávolítja a `GC_LOGIN_COOKIE`-t
  - Ha a Revizor session lejár: `session_destroy()` → átirányítás `login.php`-re
- A kilépés (`logout.php`) mindkét rendszerből kiléptet

---

## 11. OTS Lekérdezési Jogosultság — Pontosan Mi Szükséges?

### Minimálisan szükséges jogosultságok az OTS adatbázisban:

```sql
-- Csak olvasási jog az alábbi táblákra:
GRANT SELECT ON ots.TRANSACTIONS TO 'revizor_user'@'localhost';
GRANT SELECT ON ots.churches TO 'revizor_user'@'localhost';
GRANT SELECT ON ots.PERSONS TO 'revizor_user'@'localhost';
GRANT SELECT ON ots.NAMES_OF_TRANSACTION TO 'revizor_user'@'localhost';
GRANT SELECT ON ots.funds TO 'revizor_user'@'localhost';
GRANT SELECT ON ots.TRANSACTION_TYPE TO 'revizor_user'@'localhost';
GRANT SELECT ON ots.USERS TO 'revizor_user'@'localhost';
GRANT SELECT ON ots.transfers_to_conference TO 'revizor_user'@'localhost';
```

Éles környezetben egy **dedikált, csak olvasási jogosultságokkal rendelkező** MySQL felhasználó használata javasolt, amely kizárólag a szükséges OTS táblákra vonatkozik.

---

## 12. Revizor Adatbázis Létrehozása (Éles Környezethez)

A Revizor saját adatbázisát az alábbi DDL hozza létre:

```sql
CREATE DATABASE IF NOT EXISTS revizor_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revizor_db.bank_reconciliation (
    id INT AUTO_INCREMENT PRIMARY KEY,
    row_hash VARCHAR(64) UNIQUE,
    church_id INT NOT NULL,
    bank_date DATE,
    bank_amount DECIMAL(12,2),
    bank_desc TEXT,
    bank_ext_acc VARCHAR(64),
    bank_ext_name VARCHAR(255),
    bank_ext_ref VARCHAR(64),
    bank_init_name VARCHAR(255),
    bank_init_acc VARCHAR(64),
    bank_ben_name VARCHAR(255),
    bank_ben_acc VARCHAR(64),
    ots_record_id INT NULL,
    ots_date DATE NULL,
    ots_doc VARCHAR(50) NULL,
    ots_amount DECIMAL(12,2) NULL,
    status VARCHAR(20) DEFAULT 'UNCHECKED',
    comment TEXT,
    INDEX idx_church_id (church_id),
    INDEX idx_status (status),
    INDEX idx_bank_date (bank_date)
);

CREATE TABLE IF NOT EXISTS revizor_db.bank_reconciliation_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reconciliation_id INT NOT NULL,
    record_id INT NOT NULL,
    amount DECIMAL(12,2),
    FOREIGN KEY (reconciliation_id) REFERENCES bank_reconciliation(id) ON DELETE CASCADE
);
```

---

## 13. Gyakori Kérdések és Kételyek Megválaszolása

### K: A Revizor módosíthatja az OTS adatait?
**V: Nem.** A Revizor egyetlen `UPDATE` vagy `INSERT` sem hivatkozik `ots.*` táblára. Minden módosítás a `revizor_db` saját tábláira korlátozódik. Ez forráskód-szinten is ellenőrizhető.

### K: Mi történik, ha az OTS adatbázis sémája változik?
**V:** A Revizor csak olvasási hozzáféréssel rendelkezik. Ha egy oszlop neve vagy elérhetősége megváltozik, a Revizor egyszerűen nem kap adatot — nem okoz adatvesztést vagy sérülést. A hiba (pl. hiányzó oszlop) a felületen jelenik meg, és javítható.

### K: A Revizor hozzáférhet érzékeny OTS adatokhoz (pl. jelszavak)?
**V:** Nem. A Revizor kizárólag a fent felsorolt táblák és oszlopok adatait olvassa. Az OTS jelszavak (ha léteznek) kódolt formában vannak tárolva, és a Revizor soha nem kérdezi le a felhasználói hitelesítéssel kapcsolatos táblákat.

### K: Mi történik, ha egy rekordot törölnek az OTS-ből, amire a Revizor hivatkozik?
**V:** A Revizor kizárólag az `ots_record_id` számot tárolja — nincs FOREIGN KEY constraint. Ha a hivatkozott OTS rekordot törlik, a Revizor felületen a "Nincs OTS adat" vagy üres mező jelenik meg. Ez **nem okoz hibát vagy összeomlást.**

### K: A Revizor lassíthatja az OTS működését?
**V:** A Revizor lekérdezései mindig gyülekezetre szűrtek és dátum-ablakkal korlátozottak. A teljes feltöltési ciklus (ami ritkán, hetente 1-2 alkalommal történik) összesített MySQL ideje ~1-2 másodperc. Ez elenyésző az OTS napi terheléséhez képest.

### K: Hogyan biztosított, hogy a Revizor ne férjen hozzá olyan gyülekezetekhez, amelyekhez nem kellene?
**V:** A Revizor csak azokat a gyülekezeteket kezeli, amelyeknek a bankszámlaszáma a `church_bank_accounts` táblában szerepel. A hozzáférés az OTS session jogosultsági szintjétől is függ (`GN_USER_RIGHTS`). A TET számlák (`church_id = 0`) automatikusan kiszűrésre kerülnek.

---

## 14. Összefoglalás

A Revizor egy **olvasási jogosultságú, session-alapú, CSRF-védett** webalkalmazás, amely:

- **Kizárólag olvassa** az OTS adatokat (INSERT/UPDATE/DELETE csak a saját `revizor_db` adatbázisában)
- **Csak a revizor OTS session-jén keresztül** fér hozzá az adatokhoz
- **Előkészített SQL utasításokat** használ, kivéve a beégetett konstansokat
- **Két külön adatbázisban** tárolja a saját adatait (nincs adatkeveredés)
- **Nem tárol jelszavakat**
- **Nem igényel** adatbázis-séma változtatást az OTS-ben
- **Teljes mértékben auditálható** — a forráskód bármikor ellenőrizhető

A Revizor **nem fenyegeti** az OTS rendszer biztonságát, integritását vagy teljesítményét. Az OTS adatai érintetlenek maradnak, a Revizor kizárólag a bankszámlaegyeztetés hatékonyságát növeli.
