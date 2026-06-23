<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/../ots/constant.php';

// Indítsuk a session-t a session_handler.php előtt, és frissítsük a last active time-ot,
// hogy a 10 perces OTS timeout ne üsse ki a GC_LOGIN_COOKIE-t miközben a revízort használjuk (60 perces timeout)
if (session_status() != PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION[GN_LAST_ACTIVE] = time();

require_once __DIR__ . '/../ots/session_handler.php';

if (!isset($_SESSION[GC_LOGIN_COOKIE])) {
    header('Location: login.php');
    exit;
}

// Access control: only admin can use upload interface
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
if (!is_admin()) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Revizor Asszisztens session timeout — 20 perc, OTS 10 perces idejét felülírja
define('REVIZOR_SESSION_DURATION', 1200);
$_SESSION[GN_LAST_ACTIVE] = time();
if (!isset($_SESSION['revizor_expires_at'])) {
    $_SESSION['revizor_expires_at'] = time() + REVIZOR_SESSION_DURATION;
}
if (time() >= $_SESSION['revizor_expires_at']) {
    session_destroy();
    header('Location: login.php');
    exit;
}
$session_remaining = $_SESSION['revizor_expires_at'] - time();

$conn = new mysqli('localhost', 'root', '', 'revizor_db');
if ($conn->connect_error) { die("Adatbázis hiba: " . $conn->connect_error); }
$conn->set_charset("utf8mb4");

$church_options = [];
$church_result = $conn->query("SELECT id, name FROM ots.churches ORDER BY name ASC");
if ($church_result) {
    while ($row = $church_result->fetch_assoc()) {
        $church_options[] = $row;
    }
}

// Név kereső térkép építése a church_id alapján
$church_names = [];
foreach ($church_options as $c) {
    $church_names[$c['id']] = $c['name'];
}

$church_account_map = [];

// Gyülekezeti bankszámlák betöltése az adatbázisból
$cba_result = $conn->query("SELECT church_id, bank_account_clean, bank_name FROM church_bank_accounts WHERE bank_account_clean IS NOT NULL AND bank_account_clean != ''");
if ($cba_result) {
    while ($cba = $cba_result->fetch_assoc()) {
        $church_account_map[$cba['bank_account_clean']] = (int)$cba['church_id'];
    }
}

// Segédfüggvény az emberbarát név feloldásához a számlaszám alapján
$resolveFriendlyName = function($acc, $defaultName) use ($church_account_map, $church_names, $conn) {
    $cleanAcc = preg_replace('/[^0-9]/', '', $acc);
    if (isset($church_account_map[$cleanAcc])) {
        $cid = $church_account_map[$cleanAcc];
        if ($cid === 0) {
            // Egyházterületi számla: név a church_bank_accounts táblából
            $r = $conn->query("SELECT bank_name FROM church_bank_accounts WHERE bank_account_clean = '$cleanAcc' LIMIT 1");
            if ($r && $r = $r->fetch_assoc()) {
                return $r['bank_name'] ?: $defaultName;
            }
        }
        return $church_names[$cid] ?? $defaultName;
    }
    return $defaultName;
};

// OTS Kiadás típusok meghatározása (az előjel helyes számításához)
$exp_types = [];
@include_once(__DIR__ . "/../constant.php");
if (defined('GN_TRANSACTION_TYPE_PAYMENT')) $exp_types[] = GN_TRANSACTION_TYPE_PAYMENT;
if (defined('GN_TRANSACTION_TYPE_SPECIAL_TARGET_VIA_CONFERENCE')) $exp_types[] = GN_TRANSACTION_TYPE_SPECIAL_TARGET_VIA_CONFERENCE;
if (defined('GN_TRANSACTION_TYPE_ACCEPTED_SUBTRACTION')) $exp_types[] = GN_TRANSACTION_TYPE_ACCEPTED_SUBTRACTION;

if (empty($exp_types)) {
    $tt_res = $conn->query("SELECT id, NAME FROM ots.TRANSACTION_TYPE");
    if ($tt_res) {
        while($tt = $tt_res->fetch_assoc()) {
            $name = mb_strtolower($tt['NAME'], 'UTF-8');
            if (strpos($name, 'kiadás') !== false || strpos($name, 'kifizetés') !== false || strpos($name, 'költség') !== false || strpos($name, 'levonás') !== false) {
                $exp_types[] = $tt['id'];
            }
        }
    }
}
if (empty($exp_types)) { $exp_types = [-1]; }
$exp_types_str = implode(',', array_map('intval', array_filter($exp_types, 'is_numeric')));
if (empty($exp_types_str)) { $exp_types_str = '-1'; }

// Segédtábla a több OTS tételes párosításhoz
$conn->query("CREATE TABLE IF NOT EXISTS bank_reconciliation (
    id INT AUTO_INCREMENT PRIMARY KEY,
    row_hash VARCHAR(32) DEFAULT NULL,
    church_id INT NOT NULL,
    bank_date DATE DEFAULT NULL,
    bank_amount DECIMAL(12,2) DEFAULT NULL,
    bank_desc TEXT DEFAULT NULL,
    bank_ext_acc VARCHAR(50) DEFAULT NULL,
    bank_ext_name VARCHAR(255) DEFAULT NULL,
    bank_ext_ref VARCHAR(100) DEFAULT NULL,
    bank_init_name VARCHAR(255) DEFAULT NULL,
    bank_init_acc VARCHAR(50) DEFAULT NULL,
    bank_ben_name VARCHAR(255) DEFAULT NULL,
    bank_ben_acc VARCHAR(50) DEFAULT NULL,
    ots_date DATE DEFAULT NULL,
    ots_doc VARCHAR(50) DEFAULT NULL,
    ots_record_id INT DEFAULT NULL,
    ots_amount DECIMAL(12,2) DEFAULT NULL,
    status VARCHAR(20) DEFAULT 'UNCHECKED',
    comment TEXT DEFAULT NULL,
    updated_by VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_row_hash (row_hash),
    INDEX idx_church_id (church_id),
    INDEX idx_status (status),
    INDEX idx_ots_record_id (ots_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS bank_reconciliation_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reconciliation_id INT NOT NULL,
    record_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    INDEX idx_reconciliation (reconciliation_id),
    FOREIGN KEY (reconciliation_id) REFERENCES bank_reconciliation(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "<div class='alert alert-danger'>CSRF token mismatch!</div>";
    } else {
    // --- EGYEDI GYÜLEKEZET FELTÖLTÉS ---
    if (isset($_POST['single_upload']) && isset($_FILES['bank_file'])) {
    // Mivel datalist-et használunk, a beírt szövegből (pl: "Kiskunhalas (ID: 12)") kibányásszuk az ID-t
    $church_input = $_POST['church_search'] ?? '';
    preg_match('/\(ID:\s*(\d+)\)/', $church_input, $matches);
    $church_id = isset($matches[1]) ? intval($matches[1]) : 0;
    
    $file = $_FILES['bank_file'];

    if ($church_id === 0) {
        $message = "<div class='alert alert-danger'>Hiba: Érvénytelen vagy nem választott gyülekezet!</div>";
    } elseif ($file['error'] === UPLOAD_ERR_OK) {
        $file_content = file_get_contents($file['tmp_name']);
        
        if (substr($file_content, 0, 3) == pack("CCC", 0xef, 0xbb, 0xbf)) { $file_content = substr($file_content, 3); }
        if (!mb_check_encoding($file_content, 'UTF-8')) { $file_content = iconv('CP1250', 'UTF-8//IGNORE', $file_content); }

        $lines = explode("\n", str_replace("\r", "", $file_content));
        if (count($lines) > 1) {
            $separator = ";";
            $header = str_getcsv(trim($lines[0]), $separator);
            $header = array_map(function($val) { return trim($val, " \t\n\r\0\x0B\""); }, $header);
            
            $idx_date = array_search('Értéknap', $header);
            $idx_amount = array_search('Összeg', $header);
            $idx_desc = array_search('Közlemény', $header);
            $idx_partner_name = array_search('Kedvezményezett neve', $header);
            $idx_partner_acc = array_search('Kedvezményezett számlaszáma', $header);
            $idx_init_name = array_search('Kezdeményező neve', $header);
            $idx_init_acc = array_search('Kezdeményező számlaszáma', $header);
            $idx_tx_id = array_search('Tranzakcióazonosító', $header);

            if ($idx_date === FALSE || $idx_amount === FALSE) {
                $message = "<div class='alert alert-danger'>Hiba: Hibás fájlstruktúra!</div>";
            } else {
                $inserted_rows = 0;
                $skipped_rows = 0;
                $auto_matched = 0;
                $duplicate_count = 0;
                $seen_in_file = [];
                $conn->begin_transaction();

                try {
                    for ($i = 1; $i < count($lines); $i++) {
                        if (empty(trim($lines[$i]))) continue;
                        $row = str_getcsv($lines[$i], $separator);
                        if (!isset($row[$idx_date]) || empty(trim($row[$idx_date]))) continue;

                        $raw_date = trim($row[$idx_date], " \"");
                        $bank_date = (preg_match('/^\d{8}$/', $raw_date)) 
                            ? substr($raw_date, 0, 4) . '-' . substr($raw_date, 4, 2) . '-' . substr($raw_date, 6, 2)
                            : date('Y-m-d', strtotime(str_replace(['.', '/'], '-', rtrim($raw_date, '.'))));

                        $clean_amount = str_replace([' ', "\xA0", 'Ft', 'HUF'], '', trim($row[$idx_amount], " \""));
                        $bank_amount = floatval(str_replace(',', '.', $clean_amount));

                        // Partner azonosítása az irány alapján (Bejövő: Kezdeményező, Kimenő: Kedvezményezett)
                        $is_incoming = ($bank_amount > 0);
                        
                        // Részletes adatok és emberbarát nevek feloldása
                        $init_acc_raw = trim($row[$idx_init_acc] ?? '', " \"'");
                        $ben_acc_raw = trim($row[$idx_partner_acc] ?? '', " \"'");
                        
                        $init_name_raw = $resolveFriendlyName($init_acc_raw, trim($row[$idx_init_name] ?? '', " \""));
                        $ben_name_raw = $resolveFriendlyName($ben_acc_raw, trim($row[$idx_partner_name] ?? '', " \""));

                        $p_name = $is_incoming ? $init_name_raw : $ben_name_raw;
                        $p_acc = $is_incoming ? $init_acc_raw : $ben_acc_raw;

                        $p_acc = trim($p_acc, " \"'");
                        if (strpos(strtoupper($p_acc), 'E+') !== FALSE) { $p_acc = sprintf("%.0f", floatval(str_replace(',', '.', $p_acc))); }
                        $init_acc_raw = trim($init_acc_raw, " \"'");
                        if (strpos(strtoupper($init_acc_raw), 'E+') !== FALSE) { $init_acc_raw = sprintf("%.0f", floatval(str_replace(',', '.', $init_acc_raw))); }
                        $ben_acc_raw = trim($ben_acc_raw, " \"'");
                        if (strpos(strtoupper($ben_acc_raw), 'E+') !== FALSE) { $ben_acc_raw = sprintf("%.0f", floatval(str_replace(',', '.', $ben_acc_raw))); }

                        $bank_desc = isset($row[$idx_desc]) ? trim($row[$idx_desc], " \"") : '';
                        $bank_ext_name = trim($p_name, " \"");
                        
                        $clean_acc = preg_replace('/[^0-9]/', '', $p_acc);
                        if (empty($bank_desc)) {
                            $bank_desc = $bank_ext_name;
                        }
                        
                        $bank_ext_acc = $p_acc;
                        $bank_ext_ref = isset($row[$idx_tx_id]) ? trim($row[$idx_tx_id], " \"") : '';
                        if (strpos(strtoupper($bank_ext_ref), 'E+') !== FALSE) { $bank_ext_ref = sprintf("%.0f", floatval(str_replace(',', '.', $bank_ext_ref))); }

                        $base_fingerprint = $church_id . '_' . $bank_date . '_' . $bank_amount . '_' . $bank_desc . '_' . $bank_ext_acc;
                        $row_hash = md5($base_fingerprint);

                        try {
                            $stmt = $conn->prepare("INSERT INTO bank_reconciliation (row_hash, church_id, bank_date, bank_amount, bank_desc, bank_ext_acc, bank_ext_name, bank_ext_ref, status, bank_init_name, bank_init_acc, bank_ben_name, bank_ben_acc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'UNCHECKED', ?, ?, ?, ?)");
                            $stmt->bind_param("sisdssssssss", $row_hash, $church_id, $bank_date, $bank_amount, $bank_desc, $bank_ext_acc, $bank_ext_name, $bank_ext_ref, $init_name_raw, $init_acc_raw, $ben_name_raw, $ben_acc_raw);
                            $stmt->execute();
                        } catch (mysqli_sql_exception $e) {
                            if ($e->getCode() === 1062) { $skipped_rows++; $duplicate_count++; continue; }
                            throw $e;
                        }

                        $new_id = $conn->insert_id;
                        $inserted_rows++;

                        // --- AUTO-MATCH ALGORITMUS KEZDETE ---
                        try {
                            // Az OTS TRANSACTIONS táblájában keresünk egy egyező banki tételt (+/- 5 nap)
                            $ots_query = "SELECT MAX(CASH_DOCUMENT_NUMBER) AS ots_doc, MAX(DATETIME) AS ots_date 
                                           FROM ots.TRANSACTIONS T
                                           WHERE CHURCH_ID = ? 
                                             AND DATETIME BETWEEN DATE_SUB(?, INTERVAL 5 DAY) AND DATE_ADD(?, INTERVAL 5 DAY)
                                             AND VIA_BANK <> 0
                                             AND ABS(PERIOD_DIFF(EXTRACT(YEAR_MONTH FROM ?), EXTRACT(YEAR_MONTH FROM T.DATETIME))) <= 1
                                           GROUP BY RECORD_ID 
                                           HAVING SUM(IF(T.TYPE IN ($exp_types_str), -1 * AMOUNT, AMOUNT)) = ?";
                            
                            $stmt_ots = $conn->prepare($ots_query);
                            if ($stmt_ots) {
                                $stmt_ots->bind_param("isssd", $church_id, $bank_date, $bank_date, $bank_date, $bank_amount);
                                $stmt_ots->execute();
                                $ots_result = $stmt_ots->get_result();
                                
                                 // Ha pontosan egy találat van, akkor biztosak lehetünk az egyezésben
                                if ($ots_result && $ots_result->num_rows === 1) {
                                    $ots_row = $ots_result->fetch_assoc();
                                    $ots_date_only = $ots_row['ots_date'] ? substr($ots_row['ots_date'], 0, 10) : null;
                                    $ots_doc_clean = $ots_row['ots_doc'] ?? '';
                                    if ($ots_doc_clean === '0000') $ots_doc_clean = '';
                                    $ots_record_id = $ots_row['RECORD_ID'] ?? 0;
                                    
                                    $new_status = ($ots_date_only === $bank_date) ? 'OK' : 'CSUSZAS';
                                    $comment = ($ots_date_only === $bank_date) ? '[Auto: 100% egyezés, 0 nap]' : '[Auto: +/- 5 nap csúszás, egyetlen találat]';
                                    
                                    $upd_stmt = $conn->prepare("UPDATE bank_reconciliation SET ots_date=?, ots_doc=?, ots_record_id=?, ots_amount=?, status=?, comment=? WHERE id=?");
                                    $upd_stmt->bind_param("ssidssi", $ots_date_only, $ots_doc_clean, $ots_record_id, $bank_amount, $new_status, $comment, $new_id);
                                    $upd_stmt->execute();
                                    if ($ots_record_id > 0) {
                                        $ins_item = $conn->prepare("INSERT INTO bank_reconciliation_items (reconciliation_id, record_id, amount) VALUES (?, ?, ?)");
                                        $ins_item->bind_param("iid", $new_id, $ots_record_id, $bank_amount);
                                        $ins_item->execute();
                                    }
                                    $auto_matched++;
                                } elseif (!$ots_result || $ots_result->num_rows !== 1) {
                                    // Nem talált OTS-t, próbáljuk a transfers_to_conference-t
                                    $tc_stmt = $conn->prepare("SELECT AMOUNT, CONCAT(YEAR, '-', LPAD(MONTH, 2, '0'), '-', LPAD(DAY, 2, '0')) AS ots_date, CASH_DOCUMENT_NUMBER AS ots_doc FROM ots.transfers_to_conference WHERE CHURCH_ID = ? AND VIA_BANK = 1 AND AMOUNT = ? AND CONCAT(YEAR, '-', LPAD(MONTH, 2, '0'), '-', LPAD(DAY, 2, '0')) BETWEEN DATE_SUB(?, INTERVAL 5 DAY) AND DATE_ADD(?, INTERVAL 5 DAY) LIMIT 1");
                                    if ($tc_stmt) {
                                        $tc_stmt->bind_param("idss", $church_id, $bank_amount, $bank_date, $bank_date);
                                        $tc_stmt->execute();
                                        $tc_res = $tc_stmt->get_result();
                                        if ($tc_res && $tc_res->num_rows === 1) {
                                            $tc_row = $tc_res->fetch_assoc();
                                            $ots_date_only = $tc_row['ots_date'] ?? null;
                                            $ots_doc_clean = $tc_row['ots_doc'] ?? '';
                                            if ($ots_doc_clean === '0000') $ots_doc_clean = '';
                                            $new_status = 'CSUSZAS';
                                            $comment = '[Auto: Konferencia utalás, feltöltéskor]';
                                            
                                            $upd_stmt = $conn->prepare("UPDATE bank_reconciliation SET ots_date=?, ots_doc=?, ots_amount=?, status=?, comment=? WHERE id=?");
                                            $upd_stmt->bind_param("ssdssi", $ots_date_only, $ots_doc_clean, $bank_amount, $new_status, $comment, $new_id);
                                            $upd_stmt->execute();
                                            $auto_matched++;
                                        }
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            // Néma kivételkezelés: ha hiba lenne, simán feltölti UNCHECKED-ként
                        }
                        // --- AUTO-MATCH VÉGE ---
                    }
                    $conn->commit();
                    $message = "<div class='alert alert-success'>Beolvasva: <strong>$inserted_rows</strong>, Átugorva: <strong>$skipped_rows</strong> (Duplikált: $duplicate_count) tétel. Automatikusan párosítva (OK): <strong>$auto_matched</strong>.</div>";
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = "<div class='alert alert-danger'>Hiba: " . $e->getMessage() . "</div>";
                }
            }
        }
    }
    }
    // --- TÖBB GYÜLEKEZETES AUTOMATIKUS FELTÖLTÉS ---
    elseif (isset($_POST['multi_upload']) && isset($_FILES['multi_bank_file'])) {
        $file = $_FILES['multi_bank_file'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $file_content = file_get_contents($file['tmp_name']);
            if (substr($file_content, 0, 3) == pack("CCC", 0xef, 0xbb, 0xbf)) { $file_content = substr($file_content, 3); }
            if (!mb_check_encoding($file_content, 'UTF-8')) { $file_content = iconv('CP1250', 'UTF-8//IGNORE', $file_content); }

            $lines = explode("\n", str_replace("\r", "", $file_content));
            if (count($lines) > 1) {
                $separator = ";";
                $header = str_getcsv(trim($lines[0]), $separator);
                $header = array_map(function($val) { return trim($val, " \t\n\r\0\x0B\""); }, $header);
                
                $idx_date = array_search('Értéknap', $header);
                $idx_amount = array_search('Összeg', $header);
                $idx_desc = array_search('Közlemény', $header);
                $idx_partner_name = array_search('Kedvezményezett neve', $header);
                $idx_partner_acc = array_search('Kedvezményezett számlaszáma', $header);
                $idx_init_name = array_search('Kezdeményező neve', $header);
                $idx_init_acc = array_search('Kezdeményező számlaszáma', $header);
                $idx_tx_id = array_search('Tranzakcióazonosító', $header);
                $idx_own_acc = array_search('Számlaszám', $header); // K&H specifikus saját számla

                if ($idx_date === FALSE || $idx_amount === FALSE) {
                    $message = "<div class='alert alert-danger'>Hiba: Hibás fájlstruktúra!</div>";
                } else {
                    $inserted_rows = 0; $skipped_rows = 0; $auto_matched = 0; $duplicate_count = 0; $seen_in_file = [];
                    $conn->begin_transaction();
                    try {
                        for ($i = 1; $i < count($lines); $i++) {
                            if (empty(trim($lines[$i]))) continue;
                            $row = str_getcsv($lines[$i], $separator);
                            if (!isset($row[$idx_date]) || empty(trim($row[$idx_date]))) continue;

                            $raw_date = trim($row[$idx_date], " \"");
                            $bank_date = (preg_match('/^\d{8}$/', $raw_date)) 
                                ? substr($raw_date, 0, 4) . '-' . substr($raw_date, 4, 2) . '-' . substr($raw_date, 6, 2)
                                : date('Y-m-d', strtotime(str_replace(['.', '/'], '-', rtrim($raw_date, '.'))));

                            $bank_amount = floatval(str_replace(',', '.', str_replace([' ', "\xA0", 'Ft'], '', $row[$idx_amount])));

                            $is_incoming = ($bank_amount > 0);
                            
                            // Számlaszámok kinyerése
                            $init_acc_raw = trim($row[$idx_init_acc] ?? '', " \"'");
                            $ben_acc_raw = trim($row[$idx_partner_acc] ?? '', " \"'");
                            if (strpos(strtoupper($init_acc_raw), 'E+') !== FALSE) { $init_acc_raw = sprintf("%.0f", floatval(str_replace(',', '.', $init_acc_raw))); }
                            if (strpos(strtoupper($ben_acc_raw), 'E+') !== FALSE) { $ben_acc_raw = sprintf("%.0f", floatval(str_replace(',', '.', $ben_acc_raw))); }

                            $lookup_acc_raw = $is_incoming ? $ben_acc_raw : $init_acc_raw;

                            $clean_lookup_acc = preg_replace('/[^0-9]/', '', $lookup_acc_raw);
                            $church_id = $church_account_map[$clean_lookup_acc] ?? 0;

                            // Ha az irányított keresés nem talált gyülekezetet, próbálkozzunk a saját számla oszloppal is
                            if ($church_id === 0 && $idx_own_acc !== FALSE) {
                                $own_acc = preg_replace('/[^0-9]/', '', $row[$idx_own_acc] ?? '');
                                $church_id = $church_account_map[$own_acc] ?? 0;
                            }

                            if ($church_id === 0) { continue; }

                            $bank_desc = trim($row[$idx_desc] ?? '', " \"");
                            
                            // Emberbarát nevek feloldása
                            $init_name_raw = $resolveFriendlyName($init_acc_raw, trim($row[$idx_init_name] ?? '', " \""));
                            $ben_name_raw = $resolveFriendlyName($ben_acc_raw, trim($row[$idx_partner_name] ?? '', " \""));

                            $p_name = $is_incoming ? $init_name_raw : $ben_name_raw;
                            $p_acc = $is_incoming ? $init_acc_raw : $ben_acc_raw;

                            if (strpos(strtoupper($p_acc), 'E+') !== FALSE) { $p_acc = sprintf("%.0f", floatval(str_replace(',', '.', $p_acc))); }
                            
                            $bank_ext_name = trim($p_name, " \"");
                            $bank_ext_acc = $p_acc;
                            $bank_ext_ref = ($idx_tx_id !== FALSE) ? trim($row[$idx_tx_id] ?? '', " \"") : '';
                            if (strpos(strtoupper($bank_ext_ref), 'E+') !== FALSE) { $bank_ext_ref = sprintf("%.0f", floatval(str_replace(',', '.', $bank_ext_ref))); }

                            $base_fingerprint = $church_id . '_' . $bank_date . '_' . $bank_amount . '_' . $bank_desc . '_' . $bank_ext_acc;
                            $row_hash = md5($base_fingerprint);

                            try {
                                $stmt = $conn->prepare("INSERT INTO bank_reconciliation (row_hash, church_id, bank_date, bank_amount, bank_desc, bank_ext_acc, bank_ext_name, bank_ext_ref, status, bank_init_name, bank_init_acc, bank_ben_name, bank_ben_acc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'UNCHECKED', ?, ?, ?, ?)");
                                $stmt->bind_param("sisdssssssss", $row_hash, $church_id, $bank_date, $bank_amount, $bank_desc, $bank_ext_acc, $bank_ext_name, $bank_ext_ref, $init_name_raw, $init_acc_raw, $ben_name_raw, $ben_acc_raw);
                                $stmt->execute();
                                $new_id = $conn->insert_id;
                                $inserted_rows++;

                                // Automatikus párosítás
                                $ots_query = "SELECT MAX(CASH_DOCUMENT_NUMBER) AS ots_doc, MAX(DATETIME) AS ots_date, RECORD_ID FROM ots.TRANSACTIONS WHERE CHURCH_ID = ? AND DATETIME BETWEEN DATE_SUB(?, INTERVAL 5 DAY) AND DATE_ADD(?, INTERVAL 5 DAY) AND VIA_BANK <> 0 GROUP BY RECORD_ID HAVING SUM(IF(TYPE IN ($exp_types_str), -1 * AMOUNT, AMOUNT)) = ?";
                                $stmt_ots = $conn->prepare($ots_query);
                                if ($stmt_ots) {
                                    $stmt_ots->bind_param("issd", $church_id, $bank_date, $bank_date, $bank_amount);
                                    $stmt_ots->execute();
                                    $ots_result = $stmt_ots->get_result();
                                    if ($ots_result && $ots_result->num_rows === 1) {
                                        $ots_row = $ots_result->fetch_assoc();
                                        $ots_doc_final = $ots_row['ots_doc'] ?? '';
                                        if ($ots_doc_final === '0000') $ots_doc_final = '';
                                        $new_status = ($ots_row['ots_date'] == $bank_date) ? 'OK' : 'CSUSZAS';
                                        $ots_record_id = $ots_row['RECORD_ID'] ?? null;
                                        $upd = $conn->prepare("UPDATE bank_reconciliation SET ots_date=?, ots_doc=?, ots_record_id=?, ots_amount=?, status=? WHERE id=?");
                                        $upd->bind_param("ssidsi", $ots_row['ots_date'], $ots_doc_final, $ots_record_id, $bank_amount, $new_status, $new_id);
                                        $upd->execute();
                                        if (!empty($ots_row['RECORD_ID'])) {
                                            $ins_item = $conn->prepare("INSERT INTO bank_reconciliation_items (reconciliation_id, record_id, amount) VALUES (?, ?, ?)");
                                            $ins_item->bind_param("iid", $new_id, $ots_row['RECORD_ID'], $bank_amount);
                                            $ins_item->execute();
                                        }
                                        $auto_matched++;
                                    }
                                }
                            } catch (mysqli_sql_exception $e) {
                                if ($e->getCode() === 1062) { $skipped_rows++; $duplicate_count++; continue; }
                                throw $e;
                            }
                        }
                        $conn->commit();
                        $message = "<div class='alert alert-success'>Többes feltöltés kész. Beolvasva: <strong>$inserted_rows</strong> tétel.<br><small>Már korábban feltöltött (duplikált): $duplicate_count.</small></div>";
                    } catch (Exception $e) {
                        $conn->rollback();
                        $message = "<div class='alert alert-danger'>Hiba: " . $e->getMessage() . "</div>";
                    }
                }
            }
        }
    }
    } // CSRF else block vége
}

//
// AJAX multi-upload handler (insert only, no auto-match)
//
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'multi_upload_ajax') {
    // only admin allowed for multi-upload
    if (!is_admin()) { echo json_encode(['status'=>'ERROR','message'=>'Only admin allowed']); exit; }
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['status' => 'ERROR', 'message' => 'CSRF mismatch']);
        exit;
    }
    if (!isset($_FILES['multi_bank_file'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nincs fájl']);
        exit;
    }
    $file = $_FILES['multi_bank_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Fájl hiba: ' . $file['error']]);
        exit;
    }
    $start_upload = microtime(true);
    $file_content = file_get_contents($file['tmp_name']);
    if (substr($file_content, 0, 3) == pack("CCC", 0xef, 0xbb, 0xbf)) { $file_content = substr($file_content, 3); }
    if (!mb_check_encoding($file_content, 'UTF-8')) { $file_content = iconv('CP1250', 'UTF-8//IGNORE', $file_content); }
    $lines = explode("\n", str_replace("\r", "", $file_content));
    if (count($lines) <= 1) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Üres fájl']);
        exit;
    }
    $separator = ";";
    $header = str_getcsv(trim($lines[0]), $separator);
    $header = array_map(function($v) { return trim($v, " \t\n\r\0\x0B\""); }, $header);
    $idx_date = array_search('Értéknap', $header);
    $idx_amount = array_search('Összeg', $header);
    $idx_desc = array_search('Közlemény', $header);
    $idx_partner_name = array_search('Kedvezményezett neve', $header);
    $idx_partner_acc = array_search('Kedvezményezett számlaszáma', $header);
    $idx_init_name = array_search('Kezdeményező neve', $header);
    $idx_init_acc = array_search('Kezdeményező számlaszáma', $header);
    $idx_tx_id = array_search('Tranzakcióazonosító', $header);
    $idx_own_acc = array_search('Számlaszám', $header);
    if ($idx_date === FALSE || $idx_amount === FALSE) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Hibás fájlstruktúra']);
        exit;
    }
    $inserted = 0; $skipped = 0; $duplicate = 0; $seen_in_file = [];
    $conn->begin_transaction();
    try {
        for ($i = 1; $i < count($lines); $i++) {
            if (empty(trim($lines[$i]))) continue;
            $row = str_getcsv($lines[$i], $separator);
            if (!isset($row[$idx_date]) || empty(trim($row[$idx_date]))) continue;
            $raw_date = trim($row[$idx_date], " \"");
            $bank_date = (preg_match('/^\d{8}$/', $raw_date))
                ? substr($raw_date, 0, 4) . '-' . substr($raw_date, 4, 2) . '-' . substr($raw_date, 6, 2)
                : date('Y-m-d', strtotime(str_replace(['.', '/'], '-', rtrim($raw_date, '.'))));
            $bank_amount = floatval(str_replace(',', '.', str_replace([' ', "\xA0", 'Ft'], '', $row[$idx_amount])));
            $is_incoming = ($bank_amount > 0);
            $init_acc_raw = trim($row[$idx_init_acc] ?? '', " \"'");
            $ben_acc_raw = trim($row[$idx_partner_acc] ?? '', " \"'");
            if (strpos(strtoupper($init_acc_raw), 'E+') !== FALSE) { $init_acc_raw = sprintf("%.0f", floatval(str_replace(',', '.', $init_acc_raw))); }
            if (strpos(strtoupper($ben_acc_raw), 'E+') !== FALSE) { $ben_acc_raw = sprintf("%.0f", floatval(str_replace(',', '.', $ben_acc_raw))); }
            $lookup_acc_raw = $is_incoming ? $ben_acc_raw : $init_acc_raw;
            $clean_lookup_acc = preg_replace('/[^0-9]/', '', $lookup_acc_raw);
            $church_id = $church_account_map[$clean_lookup_acc] ?? 0;
            if ($church_id === 0 && $idx_own_acc !== FALSE) {
                $own_acc = preg_replace('/[^0-9]/', '', $row[$idx_own_acc] ?? '');
                $church_id = $church_account_map[$own_acc] ?? 0;
            }
            if ($church_id === 0) { $skipped++; continue; }
            $bank_desc = trim($row[$idx_desc] ?? '', " \"");
            $init_name_raw = $resolveFriendlyName($init_acc_raw, trim($row[$idx_init_name] ?? '', " \""));
            $ben_name_raw = $resolveFriendlyName($ben_acc_raw, trim($row[$idx_partner_name] ?? '', " \""));
            $p_name = $is_incoming ? $init_name_raw : $ben_name_raw;
            $p_acc = $is_incoming ? $init_acc_raw : $ben_acc_raw;
            if (strpos(strtoupper($p_acc), 'E+') !== FALSE) { $p_acc = sprintf("%.0f", floatval(str_replace(',', '.', $p_acc))); }
            $bank_ext_name = trim($p_name, " \"");
            $bank_ext_acc = $p_acc;
            $bank_ext_ref = ($idx_tx_id !== FALSE) ? trim($row[$idx_tx_id] ?? '', " \"") : '';
            if (strpos(strtoupper($bank_ext_ref), 'E+') !== FALSE) { $bank_ext_ref = sprintf("%.0f", floatval(str_replace(',', '.', $bank_ext_ref))); }
            $base_fingerprint = $church_id . '_' . $bank_date . '_' . $bank_amount . '_' . $bank_desc . '_' . $bank_ext_acc;
            $row_hash = md5($base_fingerprint);
            try {
                $stmt = $conn->prepare("INSERT INTO bank_reconciliation (row_hash, church_id, bank_date, bank_amount, bank_desc, bank_ext_acc, bank_ext_name, bank_ext_ref, status, bank_init_name, bank_init_acc, bank_ben_name, bank_ben_acc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'UNCHECKED', ?, ?, ?, ?)");
                $stmt->bind_param("sisdssssssss", $row_hash, $church_id, $bank_date, $bank_amount, $bank_desc, $bank_ext_acc, $bank_ext_name, $bank_ext_ref, $init_name_raw, $init_acc_raw, $ben_name_raw, $ben_acc_raw);
                $stmt->execute();
                $inserted++;
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() === 1062) { $skipped++; $duplicate++; continue; }
                throw $e;
            }
        }
        $conn->commit();
        $elapsed = round(microtime(true) - $start_upload, 2);
        echo json_encode(['status' => 'OK', 'inserted' => $inserted, 'skipped' => $skipped, 'duplicate' => $duplicate, 'time_sec' => $elapsed]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
    }
    exit;
}

//
// AJAX progressive match pass handler
//
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'progressive_match_pass') {
    // only admin allowed
    if (!is_admin()) { echo json_encode(['status'=>'ERROR','message'=>'Only admin allowed']); exit; }
    header('Content-Type: application/json');
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            echo json_encode(['status' => 'ERROR', 'message' => 'CSRF mismatch']);
            exit;
        }
        $pass_index = isset($_POST['pass_index']) ? intval($_POST['pass_index']) : 0;
        $pass_days = [0, 3, 6, 12, 35, 60];
        $pass_names = ['0 napos', '3 napos', '6 napos', '12 napos', '35 napos', '60 napos', 'Szöveges'];
        $start_pass = microtime(true);
        $total_unchecked = 0;
        $unc_res = $conn->query("SELECT COUNT(*) AS cnt FROM bank_reconciliation WHERE status = 'UNCHECKED'");
        if ($unc_res) {
            $total_unchecked = (int)$unc_res->fetch_assoc()['cnt'];
        }
        $matched = 0;
        if ($pass_index < 6) {
        $days = $pass_days[$pass_index];
        $church_res = $conn->query("SELECT DISTINCT church_id FROM bank_reconciliation WHERE status = 'UNCHECKED'");
        while ($c = $church_res->fetch_assoc()) {
            $cid = $c['church_id'];
            $rec_res = $conn->query("SELECT id, bank_date, bank_amount FROM bank_reconciliation WHERE church_id = $cid AND status = 'UNCHECKED'");
            while ($rec = $rec_res->fetch_assoc()) {
                $used_sub = "(SELECT ots_record_id FROM bank_reconciliation WHERE ots_record_id IS NOT NULL UNION SELECT record_id FROM bank_reconciliation_items)";
                $ots_query = "SELECT MAX(CASH_DOCUMENT_NUMBER) AS ots_doc, MAX(DATETIME) AS ots_date, RECORD_ID FROM ots.TRANSACTIONS WHERE CHURCH_ID = ? AND DATETIME BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND DATE_ADD(?, INTERVAL ? DAY) AND VIA_BANK <> 0 AND RECORD_ID NOT IN $used_sub GROUP BY RECORD_ID HAVING SUM(IF(TYPE IN ($exp_types_str), -1 * AMOUNT, AMOUNT)) = ?";
                $stmt = $conn->prepare($ots_query);
                if ($stmt) {
                    $stmt->bind_param("isidd", $cid, $rec['bank_date'], $days, $rec['bank_date'], $rec['bank_amount']);
                    $stmt->execute();
                    $ots_res = $stmt->get_result();
                    if ($ots_res && $ots_res->num_rows === 1) {
                        $ots_row = $ots_res->fetch_assoc();
                        $ots_date = $ots_row['ots_date'] ? substr($ots_row['ots_date'], 0, 10) : null;
                        $ots_doc = $ots_row['ots_doc'] ?? '';
                        if ($ots_doc === '0000') $ots_doc = '';
                        $rid = $ots_row['RECORD_ID'] ?? 0;
                        $ns = ($ots_date == $rec['bank_date']) ? 'OK' : 'CSUSZAS';
                        $cm = ($days == 0) ? '[Auto: 0 napos]' : "[Auto: {$days} napos]";
                        $upd = $conn->prepare("UPDATE bank_reconciliation SET ots_date=?, ots_doc=?, ots_record_id=?, ots_amount=?, status=?, comment=? WHERE id=?");
                        $upd->bind_param("ssidssi", $ots_date, $ots_doc, $rid, $rec['bank_amount'], $ns, $cm, $rec['id']);
                        $upd->execute();
                        if ($rid > 0) {
                            $ii = $conn->prepare("INSERT INTO bank_reconciliation_items (reconciliation_id, record_id, amount) VALUES (?, ?, ?)");
                            $ii->bind_param("iid", $rec['id'], $rid, $rec['bank_amount']);
                            $ii->execute();
                        }
                        $matched++;
                    }
                }
            }
        }
    } else {
        $kw_res = $conn->query("SELECT bank_keyword, ots_keyword FROM provider_keywords ORDER BY id");
        $kws = [];
        while ($kw = $kw_res->fetch_assoc()) {
            $kws[] = ['b' => mb_strtolower($kw['bank_keyword'], 'UTF-8'), 'o' => mb_strtolower($kw['ots_keyword'], 'UTF-8')];
        }

        // Load custom_patterns by church
        $custom_patterns_map = [];
        $cp_res = $conn->query("SELECT church_id, bank_pattern, ots_pattern FROM custom_patterns ORDER BY church_id, id");
        if ($cp_res) {
            while ($cp = $cp_res->fetch_assoc()) {
                $cid = $cp['church_id'];
                if (!isset($custom_patterns_map[$cid])) $custom_patterns_map[$cid] = [];
                $custom_patterns_map[$cid][] = [
                    'b' => mb_strtolower($cp['bank_pattern'], 'UTF-8'),
                    'o' => mb_strtolower($cp['ots_pattern'], 'UTF-8')
                ];
            }
        }

        $church_res = $conn->query("SELECT DISTINCT church_id FROM bank_reconciliation WHERE status = 'UNCHECKED'");
        while ($c = $church_res->fetch_assoc()) {
            $cid = $c['church_id'];
            $rec_res = $conn->query("SELECT id, bank_date, bank_amount, bank_desc FROM bank_reconciliation WHERE church_id = $cid AND status = 'UNCHECKED'");
            while ($rec = $rec_res->fetch_assoc()) {
                $bd = mb_strtolower(trim($rec['bank_desc'] ?? ''), 'UTF-8');
                if (empty($bd)) continue;
                $used_sub = "(SELECT ots_record_id FROM bank_reconciliation WHERE ots_record_id IS NOT NULL UNION SELECT record_id FROM bank_reconciliation_items)";
                $os = $conn->prepare("SELECT t.RECORD_ID, MAX(t.DATETIME) AS ots_date, MAX(t.CASH_DOCUMENT_NUMBER) AS ots_doc, MAX(COALESCE(notn.NAME, '')) AS ots_reason FROM ots.TRANSACTIONS t LEFT JOIN ots.names_of_transaction notn ON t.NAME_ID = notn.id WHERE t.CHURCH_ID = ? AND t.RECORD_ID NOT IN $used_sub GROUP BY t.RECORD_ID HAVING SUM(IF(t.TYPE IN ($exp_types_str), -1 * t.AMOUNT, t.AMOUNT)) = ?");
                if (!$os) continue;
                $os->bind_param("id", $cid, $rec['bank_amount']);
                $os->execute();
                $or = $os->get_result();
                $best_score = 0; $best_ots = null;
                while ($o = $or->fetch_assoc()) {
                    $reason = mb_strtolower(trim($o['ots_reason'] ?? ''), 'UTF-8');
                    $score = 0;
                    if (preg_match('/\d{6,}/', $bd)) $score += 2;
                    foreach ($kws as $kw) {
                        if (strpos($bd, $kw['b']) !== false && strpos($reason, $kw['o']) !== false) $score += 2;
                    }
                    // Church-specific custom_patterns
                    if (isset($custom_patterns_map[$cid])) {
                        foreach ($custom_patterns_map[$cid] as $cp) {
                            if (strpos($bd, $cp['b']) !== false && strpos($reason, $cp['o']) !== false) $score += 3;
                        }
                    }
                    $bw = preg_split('/\s+/', $bd);
                    $ow = preg_split('/\s+/', $reason);
                    $wm = 0;
                    foreach ($bw as $w) {
                        if (mb_strlen($w, 'UTF-8') < 4) continue;
                        if (in_array($w, $ow)) $wm++;
                    }
                    $score += min($wm, 3);
                    if ($score > $best_score) { $best_score = $score; $best_ots = $o; }
                }
                $min_score = 10;
                if ($best_ots && $best_score >= $min_score) {
                    $ots_date = $best_ots['ots_date'] ? substr($best_ots['ots_date'], 0, 10) : null;
                    $ots_doc = $best_ots['ots_doc'] ?? '';
                    if ($ots_doc === '0000') $ots_doc = '';
                    $rid = $best_ots['RECORD_ID'] ?? 0;
                    $ns = ($ots_date == $rec['bank_date']) ? 'OK' : 'CSUSZAS';
                    $cm = "[Auto: Szöveges, pont:{$best_score}]";
                    $upd = $conn->prepare("UPDATE bank_reconciliation SET ots_date=?, ots_doc=?, ots_record_id=?, ots_amount=?, status=?, comment=? WHERE id=?");
                    $upd->bind_param("ssidssi", $ots_date, $ots_doc, $rid, $rec['bank_amount'], $ns, $cm, $rec['id']);
                    $upd->execute();
                    if ($rid > 0) {
                        $ii = $conn->prepare("INSERT INTO bank_reconciliation_items (reconciliation_id, record_id, amount) VALUES (?, ?, ?)");
                        $ii->bind_param("iid", $rec['id'], $rid, $rec['bank_amount']);
                        $ii->execute();
                    }
                    $matched++;
                }
            }
        }
    }
    $elapsed = round(microtime(true) - $start_pass, 2);
    echo json_encode(['status' => 'OK', 'pass_name' => $pass_names[$pass_index], 'matched' => $matched, 'total_unchecked' => $total_unchecked, 'time_sec' => $elapsed]);
    exit;
    } catch (Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <title>Revizor Asszisztens 1.0 – Feltöltés</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; padding: 40px; }
        .upload-card { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        /* Loading overlay */
        #loadingOverlay {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center;
        }
        #loadingOverlay.show { display: flex; }
        #loadingBox {
            background: white; padding: 40px; border-radius: 12px; text-align: center;
            box-shadow: 0 8px 30px rgba(0,0,0,0.3); min-width: 300px;
        }
        #loadingTimer { font-size: 36px; font-weight: 700; color: #0d6efd; margin: 10px 0; }
    </style>
</head>
<body>
<script>var CSRF_TOKEN = '<?php echo $_SESSION['csrf_token']; ?>';</script>
<div id="loadingOverlay">
    <div id="loadingBox">
        <div class="spinner-border text-primary mb-3" style="width: 3rem; height: 3rem;"></div>
        <h5 class="mb-2">Feldolgozás folyamatban...</h5>
        <p class="text-muted small mb-2">A banki fájl feldolgozása eltarthat néhány másodpercig.</p>
        <div id="loadingTimer">0.0s</div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Single upload form — existing loading overlay
    document.querySelectorAll('form:not(#multiUploadForm)').forEach(function(form) {
        form.addEventListener('submit', function() {
            document.getElementById('loadingOverlay').classList.add('show');
            var start = Date.now();
            setInterval(function() {
                document.getElementById('loadingTimer').innerText = ((Date.now() - start) / 1000).toFixed(1) + 's';
            }, 100);
        });
    });

    // Multi-upload AJAX flow
    document.getElementById('multiUploadForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var panel = document.getElementById('progressPanel');
        panel.style.display = 'block';
        panel.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-primary mb-2"></div><div>Fájl feltöltése...</div></div>';

        var formData = new FormData(this);
        formData.set('action', 'multi_upload_ajax');

        var uploadStart = Date.now();
        var uploadTimer = setInterval(function() {
            var el = document.getElementById('uploadTimerDisplay');
            if (el) el.innerText = ((Date.now() - uploadStart) / 1000).toFixed(1) + 's';
        }, 100);

        fetch('upload.php', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(result) {
            clearInterval(uploadTimer);
            if (result.status !== 'OK') { panel.innerHTML = '<div class="alert alert-danger">' + result.message + '</div>'; return; }

            var skipInfo = result.skipped > 0 ? ' (' + result.skipped + ' kihagyva, ebből ' + result.duplicate + ' duplikált)' : '';
            panel.innerHTML = '<div class="alert alert-success mb-2">✅ Feltöltve: <strong>' + result.inserted + '</strong> sor' + skipInfo + ' — <strong>' + result.time_sec + ' mp</strong></div>';

            if (result.inserted > 0) {
                panel.innerHTML += '<div class="text-center py-2"><div class="spinner-border spinner-border-sm text-info me-2"></div>Automatikus párosítás indítása...</div>';
                return runProgressiveMatch();
            } else {
                panel.innerHTML += '<div class="alert alert-info text-center py-2 mb-0">ℹ️ Nincs új tétel, automatikus párosítás nem szükséges.</div>';
            }
        })
        .catch(function(err) {
            clearInterval(uploadTimer);
            panel.innerHTML = '<div class="alert alert-danger">Hiba: ' + err.message + '</div>';
        });
    });

    function runProgressiveMatch() {
        var panel = document.getElementById('progressPanel');
        var passNames = ['0 napos', '3 napos', '6 napos', '12 napos', '35 napos', '60 napos', 'Szöveges'];
        var totalMatched = 0;
        var passIndex = 0;
        var initialTotal = 0;
        var overallStart = Date.now();

        // Progress bar container
        var progressHtml = '<div class="mb-2"><div class="progress" style="height:18px;">' +
            '<div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div></div>' +
            '<div class="small text-muted mt-1" id="progressStats">Feldolgozás előkészítése...</div></div>';
        panel.insertAdjacentHTML('beforeend', progressHtml);

        function nextPass() {
            if (passIndex >= passNames.length) {
                var elapsed = ((Date.now() - overallStart) / 1000).toFixed(1);
                var speed = elapsed > 0 ? (totalMatched / elapsed).toFixed(1) : 0;
                document.getElementById('progressBar').style.width = '100%';
                document.getElementById('progressBar').classList.replace('progress-bar-animated', 'bg-success');
                document.getElementById('progressStats').innerHTML = '✅ Kész — <strong>' + totalMatched + '</strong> párosítás, <strong>' + elapsed + '</strong> mp (⌀' + speed + '/mp)';
                panel.innerHTML += '<button class="btn btn-primary mt-2" onclick="document.getElementById(\'progressPanel\').style.display=\'none\'">Rendben</button>';
                return;
            }

            var data = new FormData();
            data.append('action', 'progressive_match_pass');
            data.append('pass_index', passIndex);
            data.append('csrf_token', CSRF_TOKEN);

            panel.innerHTML += '<div class="d-flex align-items-center gap-2 text-muted small" id="passRow' + passIndex + '"><div class="spinner-border spinner-border-sm"></div><span>' + passNames[passIndex] + '...</span></div>';

            fetch('upload.php', { method: 'POST', body: data })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                if (result.status === 'OK') {
                    totalMatched += result.matched;
                    if (passIndex === 0) {
                        initialTotal = result.total_unchecked;
                    }
                    document.getElementById('passRow' + passIndex).innerHTML = '✅ ' + passNames[passIndex] + ': <strong>' + result.matched + '</strong> párosítás (' + result.time_sec + ' mp)';

                    // Update progress bar
                    var elapsed = (Date.now() - overallStart) / 1000;
                    var remainAfter = (result.total_unchecked !== undefined && passIndex < passNames.length - 1) ? result.total_unchecked : 0;
                    var processed = initialTotal > 0 ? initialTotal - remainAfter : totalMatched;
                    var pct = initialTotal > 0 ? Math.round(processed / initialTotal * 100) : 0;
                    var speed = elapsed > 0 ? (processed / elapsed).toFixed(1) : 0;
                    var remaining = initialTotal - processed;
                    var etaSec = speed > 0 ? (remaining / speed) : 0;
                    var etaStr = etaSec > 90 ? Math.ceil(etaSec / 60) + ' perc' : Math.ceil(etaSec) + ' mp';

                    document.getElementById('progressBar').style.width = Math.min(pct, 100) + '%';
                    document.getElementById('progressStats').innerHTML =
                        'Haladás: ' + processed + '/' + initialTotal + ' (' + pct + '%)' +
                        ' | ⌀' + speed + '/mp' +
                        ' | ~' + etaStr + ' hátra';
                } else {
                    var errMsg = result.message || 'ismeretlen hiba';
                    document.getElementById('passRow' + passIndex).innerHTML = '❌ ' + passNames[passIndex] + ': ' + errMsg;
                }
                passIndex++;
                nextPass();
            })
            .catch(function(err) {
                document.getElementById('passRow' + passIndex).innerHTML = '❌ ' + passNames[passIndex] + ': ' + err.message;
                passIndex++;
                nextPass();
            });
        }

        nextPass();
    }
});
</script>
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3 px-3 py-2 bg-white rounded border shadow-sm">
        <div class="d-flex align-items-center gap-2">
            <span class="fw-bold">🕵️ Revizor Asszisztens 1.0</span>
            <span class="text-muted mx-1">|</span>
            <span class="text-muted">Feltöltés</span>
        </div>
        <div class="d-flex align-items-center gap-1">
            <a href="index.php" class="btn btn-outline-secondary btn-sm">🏠 Kezdőlap</a>
            <a href="help.php" class="btn btn-outline-primary btn-sm">❓ Súgó</a>
            <a href="logout.php" class="btn btn-outline-danger btn-sm ms-1">Kilépés</a>
        </div>
    </div>

    <div class="upload-card mb-4">
        <h4 class="mb-3">📥 Egyedi Gyülekezet Feltöltése</h4>
        <?php echo $message; ?>
        <form action="upload.php" method="POST" enctype="multipart/form-data" class="mt-4" autocomplete="off">
            <input type="hidden" name="single_upload" value="1">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="mb-4">
                <label class="form-label fw-bold">1. Gyülekezet (Gépelj a kereséshez):</label>
                <input list="churches" name="church_search" id="church_search" class="form-control form-control-lg" placeholder="Kezdj el gépelni egy nevet vagy ID-t..." required>
                <datalist id="churches">
                    <?php foreach ($church_options as $church): ?>
                        <option value="<?php echo htmlspecialchars($church['name']); ?> (ID: <?php echo $church['id']; ?>)">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="mb-4">
                <label class="form-label fw-bold">2. K&H CSV Fájl:</label>
                <input class="form-control" type="file" name="bank_file" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Feltöltés</button>
        </form>
    </div>

    <div class="upload-card">
        <h4 class="mb-3">📥 Automatikus (Több Gyülekezet) Feltöltés</h4>
        <p class="small text-muted">A rendszer a számlaszámok alapján automatikusan azonosítja a gyülekezeteket az adatbázisban tárolt bankszámla-nyilvántartás segítségével.</p>
        <form id="multiUploadForm" action="upload.php" method="POST" enctype="multipart/form-data" class="mt-4">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="mb-4">
                <label class="form-label fw-bold">K&H CSV Fájl:</label>
                <input class="form-control" type="file" name="multi_bank_file" required>
            </div>
            <button type="submit" class="btn btn-success w-100">Összesített Feltöltés</button>
        </form>
        <div id="multiProgress" class="mt-3" style="display:none;"></div>
    </div>

        <!-- Progress Panel -->
        <div id="progressPanel" class="mt-3 border rounded p-3 bg-light" style="display:none;"></div>
    </div>
</div>

<!-- Session warning modal -->
<div class="modal fade" id="sessionWarningModal" data-bs-backdrop="static" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-warning">
            <div class="modal-header bg-warning text-dark">
                <h6 class="modal-title">⏰ Session lejár</h6>
            </div>
            <div class="modal-body text-center">
                <p class="mb-2">A munkamenet lejár:</p>
                <div class="display-6 fw-bold text-danger mb-2" id="sessionCountdown">--</div>
                <p class="small text-muted">Kattints a hosszabbításra, hogy ne veszítsd el a munkádat.</p>
            </div>
            <div class="modal-footer justify-content-center">
                <button class="btn btn-warning fw-bold" onclick="extendSession()">🔄 Hosszabbítás</button>
            </div>
        </div>
    </div>
</div>

<script>
let sessionRemaining = <?= max(0, $session_remaining) ?>;
let sessionWarningShown = false;
let sessionExtending = false;

function updateSessionDisplay() {
    if (sessionRemaining <= 0) {
        document.getElementById('sessionCountdown').textContent = '0:00';
        window.location.href = 'logout.php';
        return;
    }
    const mins = Math.floor(sessionRemaining / 60);
    const secs = sessionRemaining % 60;
    document.getElementById('sessionCountdown').textContent = mins + ':' + String(secs).padStart(2, '0');
}

function extendSession() {
    if (sessionExtending) return;
    sessionExtending = true;
    fetch('session_ping.php')
    .then(r => r.json())
    .then(data => {
        if (data.remaining) {
            sessionRemaining = data.remaining;
            updateSessionDisplay();
            if (sessionWarningShown) {
                const modal = bootstrap.Modal.getInstance(document.getElementById('sessionWarningModal'));
                if (modal) modal.hide();
                sessionWarningShown = false;
            }
        }
    })
    .catch(() => {})
    .finally(() => { sessionExtending = false; });
}

setInterval(extendSession, 30000);

setInterval(() => {
    sessionRemaining--;
    updateSessionDisplay();
    if (sessionRemaining < 120 && !sessionWarningShown) {
        sessionWarningShown = true;
        const modal = new bootstrap.Modal(document.getElementById('sessionWarningModal'));
        modal.show();
    }
    if (sessionRemaining <= 0) {
        window.location.href = 'logout.php';
    }
}, 1000);
</script>

</body>
</html>
