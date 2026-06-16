<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/../ots/session_handler.php';
require_once __DIR__ . '/../ots/constant.php';

if (!isset($_SESSION[GC_LOGIN_COOKIE])) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Revizor session timeout — 60 perc, OTS 10 perces idejét felülírja
define('REVIZOR_SESSION_DURATION', 3600);
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

// Manuális segédfájl betöltése (ha létezik)
$manual_accounts = file_exists(__DIR__ . '/szamlak.php') ? include(__DIR__ . '/szamlak.php') : [];
if (is_array($manual_accounts)) {
    foreach ($manual_accounts as $acc => $id) { $church_account_map[preg_replace('/[^0-9]/', '', $acc)] = $id; }
}

// Segédfüggvény az emberbarát név feloldásához a számlaszám alapján
$resolveFriendlyName = function($acc, $defaultName) use ($church_account_map, $church_names) {
    $cleanAcc = preg_replace('/[^0-9]/', '', $acc);
    if (isset($church_account_map[$cleanAcc])) {
        $cid = $church_account_map[$cleanAcc];
        if ($cid === 0) return "Tiszavidéki Egyházterület";
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

$message = "";

// Keepalive — session hosszabbítás AJAX-ból
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'keepalive') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['status' => 'ERROR']);
        exit;
    }
    $_SESSION['revizor_expires_at'] = time() + REVIZOR_SESSION_DURATION;
    $_SESSION[GN_LAST_ACTIVE] = time();
    echo json_encode(['status' => 'OK', 'remaining' => REVIZOR_SESSION_DURATION]);
    exit;
}

// Server-side session check AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'check_session') {
    header('Content-Type: application/json');
    $remaining = isset($_SESSION['revizor_expires_at']) ? $_SESSION['revizor_expires_at'] - time() : 0;
    if ($remaining <= 0) {
        session_destroy();
        echo json_encode(['status' => 'EXPIRED']);
    } else {
        echo json_encode(['status' => 'OK', 'remaining' => $remaining]);
    }
    exit;
}

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

                        $bank_desc = isset($row[$idx_desc]) ? trim($row[$idx_desc], " \"") : '';
                        $bank_ext_name = trim($p_name, " \"");
                        
                        $clean_acc = preg_replace('/[^0-9]/', '', $p_acc);
                        if (empty($bank_desc)) {
                            $bank_desc = $bank_ext_name;
                        }
                        
                        $bank_ext_acc = $p_acc;
                        $bank_ext_ref = isset($row[$idx_tx_id]) ? trim($row[$idx_tx_id], " \"") : '';

                        $base_fingerprint = $church_id . '_' . $bank_date . '_' . $bank_amount . '_' . $bank_desc . '_' . $bank_ext_acc;
                        if (!isset($seen_in_file[$base_fingerprint])) { $seen_in_file[$base_fingerprint] = 0; }
                        $seen_in_file[$base_fingerprint]++;
                        
                        $row_hash = md5($base_fingerprint . '_' . $seen_in_file[$base_fingerprint]);

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
                                    
                                    $new_status = ($ots_date_only === $bank_date) ? 'OK' : 'CSUSZAS';
                                    $comment = ($ots_date_only === $bank_date) ? '[Auto: 100% egyezés, 0 nap]' : '[Auto: +/- 5 nap csúszás, egyetlen találat]';
                                    
                                    $upd_stmt = $conn->prepare("UPDATE bank_reconciliation SET ots_date=?, ots_doc=?, ots_amount=?, status=?, comment=? WHERE id=?");
                                    $upd_stmt->bind_param("ssdssi", $ots_date_only, $ots_doc_clean, $bank_amount, $new_status, $comment, $new_id);
                                    $upd_stmt->execute();
                                    $auto_matched++;
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
                    $inserted_rows = 0; $skipped_rows = 0; $auto_matched = 0; $unknown_church_count = 0; $duplicate_count = 0; $seen_in_file = [];
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

                            $lookup_acc_raw = $is_incoming ? $ben_acc_raw : $init_acc_raw;

                            $clean_lookup_acc = preg_replace('/[^0-9]/', '', $lookup_acc_raw);
                            $church_id = $church_account_map[$clean_lookup_acc] ?? 0;

                            // Ha az irányított keresés nem talált gyülekezetet, próbálkozzunk a saját számla oszloppal is
                            if ($church_id === 0 && $idx_own_acc !== FALSE) {
                                $own_acc = preg_replace('/[^0-9]/', '', $row[$idx_own_acc] ?? '');
                                $church_id = $church_account_map[$own_acc] ?? 0;
                            }

                            if ($church_id === 0) { $unknown_church_count++; }

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

                            $base_fingerprint = $church_id . '_' . $bank_date . '_' . $bank_amount . '_' . $bank_desc . '_' . $bank_ext_acc;
                            if (!isset($seen_in_file[$base_fingerprint])) { $seen_in_file[$base_fingerprint] = 0; }
                            $seen_in_file[$base_fingerprint]++;
                            
                            $row_hash = md5($base_fingerprint . '_' . $seen_in_file[$base_fingerprint]);

                            try {
                                $stmt = $conn->prepare("INSERT INTO bank_reconciliation (row_hash, church_id, bank_date, bank_amount, bank_desc, bank_ext_acc, bank_ext_name, bank_ext_ref, status, bank_init_name, bank_init_acc, bank_ben_name, bank_ben_acc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'UNCHECKED', ?, ?, ?, ?)");
                                $stmt->bind_param("sisdssssssss", $row_hash, $church_id, $bank_date, $bank_amount, $bank_desc, $bank_ext_acc, $bank_ext_name, $bank_ext_ref, $init_name_raw, $init_acc_raw, $ben_name_raw, $ben_acc_raw);
                                $stmt->execute();
                                $new_id = $conn->insert_id;
                                $inserted_rows++;

                                // Automatikus párosítás
                                $ots_query = "SELECT MAX(CASH_DOCUMENT_NUMBER) AS ots_doc, MAX(DATETIME) AS ots_date FROM ots.TRANSACTIONS WHERE CHURCH_ID = ? AND DATETIME BETWEEN DATE_SUB(?, INTERVAL 5 DAY) AND DATE_ADD(?, INTERVAL 5 DAY) AND VIA_BANK <> 0 GROUP BY RECORD_ID HAVING SUM(IF(TYPE IN ($exp_types_str), -1 * AMOUNT, AMOUNT)) = ?";
                                $stmt_ots = $conn->prepare($ots_query);
                                if ($stmt_ots) {
                                    $stmt_ots->bind_param("issd", $church_id, $bank_date, $bank_date, $bank_amount);
                                    $stmt_ots->execute();
                                    $ots_result = $stmt_ots->get_result();
                                    if ($ots_result && $ots_result->num_rows === 1) {
                                        $ots_row = $ots_result->fetch_assoc();
                                        $new_status = ($ots_row['ots_date'] == $bank_date) ? 'OK' : 'CSUSZAS';
                                        $upd = $conn->prepare("UPDATE bank_reconciliation SET ots_date=?, ots_doc=?, ots_amount=?, status=? WHERE id=?");
                                        $upd->bind_param("ssdsi", $ots_row['ots_date'], $ots_row['ots_doc'], $bank_amount, $new_status, $new_id);
                                        $upd->execute();
                                        $auto_matched++;
                                    }
                                }
                            } catch (mysqli_sql_exception $e) {
                                if ($e->getCode() === 1062) { $skipped_rows++; $duplicate_count++; continue; }
                                throw $e;
                            }
                        }
                        $conn->commit();
                        $message = "<div class='alert alert-success'>Többes feltöltés kész. Beolvasva: <strong>$inserted_rows</strong>, Átugorva összesen: <strong>$skipped_rows</strong>.<br><small>Ebből ismeretlen gyülekezet: $unknown_church_count, már korábban feltöltött (duplikált): $duplicate_count.</small></div>";
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
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <title>K&H Banki Fájl Feltöltése</title>
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
    document.querySelectorAll('form').forEach(function(form) {
        form.addEventListener('submit', function() {
            document.getElementById('loadingOverlay').classList.add('show');
            var start = Date.now();
            setInterval(function() {
                document.getElementById('loadingTimer').innerText = ((Date.now() - start) / 1000).toFixed(1) + 's';
            }, 100);
        });
    });
});
</script>
<div class="container">
    <div class="upload-card mb-4">
        <h4 class="mb-3">📥 Egyedi Gyülekezet Feltöltése</h4>
        <?php echo $message; ?>
        <form action="feltolto.php" method="POST" enctype="multipart/form-data" class="mt-4" autocomplete="off">
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
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100">Feltöltés</button>
                <a href="index.php" class="btn btn-outline-secondary">Vissza</a>
            </div>
        </form>
    </div>

    <div class="upload-card">
        <h4 class="mb-3">📥 Automatikus (Több Gyülekezet) Feltöltés</h4>
        <p class="small text-muted">A rendszer a számlaszámok alapján automatikusan azonosítja a gyülekezeteket a beépített tudástár (szamlak.php) segítségével.</p>
        <form action="feltolto.php" method="POST" enctype="multipart/form-data" class="mt-4">
            <input type="hidden" name="multi_upload" value="1">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="mb-4">
                <label class="form-label fw-bold">K&H CSV Fájl:</label>
                <input class="form-control" type="file" name="multi_bank_file" required>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success w-100">Összesített Feltöltés</button>
                <a href="index.php" class="btn btn-outline-secondary">Vissza</a>
            </div>
        </form>
    </div>
</div>

<!-- SESSION COUNTDOWN JS -->
<script>
var sessionRemaining = <?php echo $session_remaining; ?>;
var sessionWarningShown = false;

function formatTime(sec) {
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return m + ' perc ' + s + ' mp';
}

function checkSession() {
    sessionRemaining--;
    if (sessionRemaining <= 300 && !sessionWarningShown) {
        sessionWarningShown = true;
        document.getElementById('sessionWarnTime').textContent = formatTime(sessionRemaining);
        new bootstrap.Modal(document.getElementById('sessionWarnModal')).show();
    }
    if (sessionRemaining <= 0) {
        window.location.href = 'login.php';
    }
}

function checkSessionServer() {
    var data = new FormData();
    data.append('action', 'check_session');
    fetch('feltolto.php', { method: 'POST', body: data })
    .then(function(res) { return res.json(); })
    .then(function(result) {
        if (result.status === 'EXPIRED') {
            window.location.href = 'login.php';
        } else if (result.status === 'OK') {
            sessionRemaining = result.remaining;
            if (sessionRemaining > 300 && sessionWarningShown) {
                sessionWarningShown = false;
                var modal = bootstrap.Modal.getInstance(document.getElementById('sessionWarnModal'));
                if (modal) modal.hide();
            }
        }
    })
    .catch(function() {});
}

function extendSession() {
    var data = new FormData();
    data.append('action', 'keepalive');
    data.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
    fetch('feltolto.php', { method: 'POST', body: data })
    .then(function(res) { return res.json(); })
    .then(function(result) {
        if (result.status === 'OK') {
            sessionRemaining = result.remaining;
            sessionWarningShown = false;
            bootstrap.Modal.getInstance(document.getElementById('sessionWarnModal')).hide();
        }
    })
    .catch(function() {});
}

setInterval(checkSession, 1000);
setInterval(checkSessionServer, 10000);
</script>

<!-- SESSION WARNING MODAL -->
<div class="modal fade" id="sessionWarnModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content border-warning">
      <div class="modal-header bg-warning text-dark">
        <h6 class="modal-title">⏰ Session lejár</h6>
      </div>
      <div class="modal-body text-center">
        <p class="mb-2">A munkamenet <strong>5 percen belül</strong> lejár!</p>
        <p class="text-muted small mb-0">Hátralévő idő: <strong id="sessionWarnTime">-</strong></p>
      </div>
      <div class="modal-footer justify-content-center">
        <button type="button" class="btn btn-warning fw-bold" onclick="extendSession()">🔁 Hosszabbítás +60 perc</button>
      </div>
    </div>
  </div>
</div>

</body>
</html>