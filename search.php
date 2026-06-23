<?php
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../ots/constant.php';

if (session_status() != PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION[GN_LAST_ACTIVE] = time();

require_once __DIR__ . '/../ots/session_handler.php';

if (!isset($_SESSION[GC_LOGIN_COOKIE])) {
    header('Location: login.php');
    exit;
}

// load common auth helpers and build user context
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
build_user_context_from_ots();

define('REVIZOR_SESSION_DURATION', 1200);
if (!isset($_SESSION['revizor_expires_at'])) {
    $_SESSION['revizor_expires_at'] = time() + REVIZOR_SESSION_DURATION;
}
if (time() >= $_SESSION['revizor_expires_at']) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'revizor_db');
if ($conn->connect_error) {
    die('Adatbázis kapcsolódási hiba');
}
$conn->set_charset('utf8mb4');

$ots_db = new mysqli('localhost', 'root', '', 'ots');
if ($ots_db->connect_error) {
    die('OTS adatbázis kapcsolódási hiba');
}
$ots_db->set_charset('utf8mb4');

// Kiadás típusok meghatározása
$exp_types = [];
@include_once(__DIR__ . "/../constant.php");
if (defined('GN_TRANSACTION_TYPE_PAYMENT')) $exp_types[] = GN_TRANSACTION_TYPE_PAYMENT;
if (defined('GN_TRANSACTION_TYPE_SPECIAL_TARGET_VIA_CONFERENCE')) $exp_types[] = GN_TRANSACTION_TYPE_SPECIAL_TARGET_VIA_CONFERENCE;
if (defined('GN_TRANSACTION_TYPE_ACCEPTED_SUBTRACTION')) $exp_types[] = GN_TRANSACTION_TYPE_ACCEPTED_SUBTRACTION;
if (empty($exp_types)) {
    $tt_res = $conn->query("SELECT id, NAME FROM ots.TRANSACTION_TYPE");
    if ($tt_res) {
        while ($tt = $tt_res->fetch_assoc()) {
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

// Church list - restrict to accessible churches for non-admin
$churches = [];
if (is_admin()) {
    $ch_res = $conn->query("SELECT id, name FROM ots.churches WHERE name IS NOT NULL AND name != '' ORDER BY name ASC");
    if ($ch_res) {
        while ($ch = $ch_res->fetch_assoc()) { $churches[] = $ch; }
    }
} else {
    $allowed = get_accessible_church_ids();
    if (!empty($allowed)) {
        $ids = implode(',', array_map('intval', $allowed));
        $ch_res = $conn->query("SELECT id, name FROM ots.churches WHERE id IN ($ids) ORDER BY name ASC");
        if ($ch_res) { while ($ch = $ch_res->fetch_assoc()) { $churches[] = $ch; } }
    }
}

// Search params
$source = isset($_GET['source']) ? $_GET['source'] : 'bank';
$church_id = isset($_GET['church_id']) ? intval($_GET['church_id']) : 0;
// if a church is requested, ensure the user has access
if ($church_id > 0) {
    require_church_access($church_id);
}
$amount_min = isset($_GET['amount_min']) && $_GET['amount_min'] !== '' ? floatval($_GET['amount_min']) : null;
$amount_max = isset($_GET['amount_max']) && $_GET['amount_max'] !== '' ? floatval($_GET['amount_max']) : null;
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$description = isset($_GET['description']) ? trim($_GET['description']) : '';
$doc_number = isset($_GET['doc_number']) ? trim($_GET['doc_number']) : '';
$flow = isset($_GET['flow']) ? $_GET['flow'] : 'bank';
$status_filter = isset($_GET['status_filter']) ? $_GET['status_filter'] : 'all';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;
$session_remaining = $_SESSION['revizor_expires_at'] - time();
$export = isset($_GET['export']) && $_GET['export'] === 'csv';
$exact_word = isset($_GET['exact_word']) && $_GET['exact_word'] === '1';

$has_search = $church_id > 0 || $amount_min !== null || $amount_max !== null || $date_from !== '' || $date_to !== '' || $description !== '' || $doc_number !== '';

function build_where($prefix, $params) {
    $w = [];
    $vals = [];
    if ($params['church_id'] > 0) {
        $w[] = "$prefix.church_id = " . intval($params['church_id']);
    }
    if ($params['amount_min'] !== null) {
        $w[] = "$prefix.amount >= " . floatval($params['amount_min']);
    }
    if ($params['amount_max'] !== null) {
        $w[] = "$prefix.amount <= " . floatval($params['amount_max']);
    }
    if ($params['date_from']) {
        $w[] = "$prefix.date >= '" . $params['date_from'] . "'";
    }
    if ($params['date_to']) {
        $w[] = "$prefix.date <= '" . $params['date_to'] . "'";
    }
    if ($params['description']) {
        $esc = $params['conn']->real_escape_string($params['description']);
        $w[] = "$prefix.description LIKE '%$esc%'";
    }
    return [implode(' AND ', $w), $vals];
}

$results = [];
$total = 0;
$query_time = 0;
$error_msg = '';

if ($has_search) {
try {
    $start_time = microtime(true);

    if ($source === 'bank' || $source === 'both') {
        $b_where = [];
        $b_params = [];

        if ($church_id > 0) {
            $b_where[] = 'br.church_id = ' . intval($church_id);
        }
        if ($amount_min !== null) {
            $b_where[] = 'br.bank_amount >= ' . floatval($amount_min);
        }
        if ($amount_max !== null) {
            $b_where[] = 'br.bank_amount <= ' . floatval($amount_max);
        }
        if ($date_from) {
            $b_where[] = "br.bank_date >= '$date_from'";
        }
        if ($date_to) {
            $b_where[] = "br.bank_date <= '$date_to'";
        }
        if ($description) {
            $desc_esc = $conn->real_escape_string($description);
            if ($exact_word) {
                $desc_re = $conn->real_escape_string(preg_quote($description, '/'));
                $b_where[] = "(br.bank_desc REGEXP '[[:<:]]{$desc_re}[[:>:]]' OR br.bank_ext_name REGEXP '[[:<:]]{$desc_re}[[:>:]]' OR br.bank_init_name REGEXP '[[:<:]]{$desc_re}[[:>:]]' OR br.bank_ben_name REGEXP '[[:<:]]{$desc_re}[[:>:]]')";
            } else {
                $b_where[] = "(br.bank_desc LIKE '%$desc_esc%' OR br.bank_ext_name LIKE '%$desc_esc%' OR br.bank_init_name LIKE '%$desc_esc%' OR br.bank_ben_name LIKE '%$desc_esc%')";
            }
        }
        if ($doc_number) {
            $doc_esc = $conn->real_escape_string($doc_number);
            $b_where[] = "(br.ots_doc LIKE '%$doc_esc%' OR br.bank_ext_ref LIKE '%$doc_esc%')";
        }
        if ($status_filter === 'matched') {
            $b_where[] = "(br.ots_record_id IS NOT NULL OR br.id IN (SELECT reconciliation_id FROM bank_reconciliation_items))";
        } elseif ($status_filter === 'unmatched') {
            $b_where[] = "br.ots_record_id IS NULL AND br.id NOT IN (SELECT reconciliation_id FROM bank_reconciliation_items)";
        }

        $b_where_sql = $b_where ? 'WHERE ' . implode(' AND ', $b_where) : '';

        // Dedup subquery: collapse rows with same church_id, bank_date, bank_amount, bank_desc
        $dedup_sub = "INNER JOIN (SELECT MIN(id) AS dedup_id FROM bank_reconciliation GROUP BY church_id, bank_date, bank_amount, bank_desc) d ON br.id = d.dedup_id";

        // Count
        $count_res = $conn->query("SELECT COUNT(*) as cnt FROM bank_reconciliation br $dedup_sub $b_where_sql");
        if ($count_res) {
            $total += intval($count_res->fetch_assoc()['cnt']);
        }

        if (!$export) {
            $b_sql = "SELECT br.*, c.name AS church_name FROM bank_reconciliation br $dedup_sub LEFT JOIN ots.churches c ON br.church_id = c.id $b_where_sql ORDER BY br.bank_date DESC LIMIT $per_page OFFSET $offset";
        } else {
            $b_sql = "SELECT br.*, c.name AS church_name FROM bank_reconciliation br $dedup_sub LEFT JOIN ots.churches c ON br.church_id = c.id $b_where_sql ORDER BY br.bank_date DESC";
        }
        $b_res = $conn->query($b_sql);
        if ($b_res) {
            while ($row = $b_res->fetch_assoc()) {
                $row['_source'] = 'Bank';
                $results[] = $row;
            }
        }
    }

    if ($source === 'ots' || $source === 'both') {
        $adjusted_sql = "IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT)";

        $o_where = ["T.CHURCH_ID > 0"];
        $o_params = [];

        if ($church_id > 0) {
            $o_where[] = 'T.CHURCH_ID = ' . intval($church_id);
        }
        if ($date_from) {
            $o_where[] = "T.DATETIME >= '$date_from'";
        }
        if ($date_to) {
            $o_where[] = "T.DATETIME <= '$date_to 23:59:59'";
        }
        if ($doc_number) {
            $doc_esc = $ots_db->real_escape_string($doc_number);
            $o_where[] = "(T.CASH_DOCUMENT_NUMBER LIKE '%$doc_esc%' OR T.DECISION_NUMBER LIKE '%$doc_esc%')";
        }

        // Flow filter (VIA_BANK)
        if ($flow === 'bank') {
            $o_where[] = 'T.VIA_BANK <> 0';
        } elseif ($flow === 'cash') {
            $o_where[] = 'T.VIA_BANK = 0';
        }

        // When source=both, exclude already-paired OTS records to avoid duplicates
        if ($source === 'both') {
            $o_where[] = "(T.RECORD_ID NOT IN (SELECT ots_record_id FROM revizor_db.bank_reconciliation WHERE ots_record_id IS NOT NULL) AND T.RECORD_ID NOT IN (SELECT record_id FROM revizor_db.bank_reconciliation_items))";
        }

        // Amount filter on adjusted_amount
        $o_having = [];
        if ($amount_min !== null) {
            $o_having[] = 'adjusted_amount >= ' . floatval($amount_min);
        }
        if ($amount_max !== null) {
            $o_having[] = 'adjusted_amount <= ' . floatval($amount_max);
        }

        // Description filter (PERSONS.NAME, NAMES_OF_TRANSACTION.NAME)
        $o_desc_join = '';
        if ($description) {
            $desc_esc = $ots_db->real_escape_string($description);
            if ($exact_word) {
                $desc_re = $ots_db->real_escape_string(preg_quote($description, '/'));
                $o_where[] = "(p.NAME REGEXP '[[:<:]]{$desc_re}[[:>:]]' OR p.NAME_PREFIX REGEXP '[[:<:]]{$desc_re}[[:>:]]' OR p.NAME_SUFFIX REGEXP '[[:<:]]{$desc_re}[[:>:]]' OR nt1.NAME REGEXP '[[:<:]]{$desc_re}[[:>:]]' OR nt2.NAME REGEXP '[[:<:]]{$desc_re}[[:>:]]' OR f.NAME REGEXP '[[:<:]]{$desc_re}[[:>:]]')";
            } else {
                $o_where[] = "(p.NAME LIKE '%$desc_esc%' OR p.NAME_PREFIX LIKE '%$desc_esc%' OR p.NAME_SUFFIX LIKE '%$desc_esc%' OR nt1.NAME LIKE '%$desc_esc%' OR nt2.NAME LIKE '%$desc_esc%' OR f.NAME LIKE '%$desc_esc%')";
            }
        }

        $o_where_sql = implode(' AND ', $o_where);
        $o_having_sql = $o_having ? 'HAVING ' . implode(' AND ', $o_having) : '';

        $base_joins = "FROM ots.TRANSACTIONS T
                 LEFT JOIN ots.PERSONS p ON T.PERSON_ID = p.id
                 LEFT JOIN ots.NAMES_OF_TRANSACTION nt1 ON T.NAME_ID = nt1.id
                 LEFT JOIN ots.NAMES_OF_TRANSACTION nt2 ON T.NAME2_ID = nt2.id
                 LEFT JOIN ots.TRANSACTION_TYPE tt ON T.TYPE = tt.id
                 LEFT JOIN ots.USERS u ON T.EDITED_BY = u.id
                 LEFT JOIN ots.funds f ON T.FUND_ID = f.id
                 LEFT JOIN ots.churches c ON T.CHURCH_ID = c.id";

        // Count for OTS
        if ($source !== 'both') {
            $o_count_sql = "SELECT COUNT(*) as cnt FROM (SELECT T.RECORD_ID $base_joins WHERE $o_where_sql GROUP BY T.RECORD_ID $o_having_sql) sub";
            $count_res = $ots_db->query($o_count_sql);
            if ($count_res) {
                $total += intval($count_res->fetch_assoc()['cnt']);
            }
        }

        $select_cols = "T.RECORD_ID, T.CASH_DOCUMENT_NUMBER, T.DECISION_NUMBER, T.DATETIME,
                        $adjusted_sql AS adjusted_amount,
                        TRIM(CONCAT(IFNULL(CONCAT_WS(' ', p.NAME_PREFIX, p.NAME, p.NAME_SUFFIX), ''),
                            ' ', IFNULL(nt1.NAME, ''), ' ', IFNULL(nt2.NAME, ''))) AS ots_desc_full,
                        tt.NAME AS ots_type_name, u.NAME AS ots_editor_name,
                        f.NAME AS fund_name, c.name AS church_name,
                        IF(T.VIA_BANK <> 0, 'Bank', 'Készpénz') AS flow_label,
                        T.VIA_BANK";

        if (!$export) {
            $o_sql = "SELECT $select_cols $base_joins WHERE $o_where_sql GROUP BY T.RECORD_ID $o_having_sql ORDER BY T.DATETIME DESC LIMIT $per_page OFFSET $offset";
        } else {
            $o_sql = "SELECT $select_cols $base_joins WHERE $o_where_sql GROUP BY T.RECORD_ID $o_having_sql ORDER BY T.DATETIME DESC";
        }
        $o_res = $ots_db->query($o_sql);
        // Párosított OTS record_id-k lekérése
        $paired_ots_ids = [];
        $pair_res = $conn->query("SELECT DISTINCT ots_record_id FROM bank_reconciliation WHERE ots_record_id IS NOT NULL UNION SELECT DISTINCT record_id FROM bank_reconciliation_items");
        if ($pair_res) {
            while ($p = $pair_res->fetch_assoc()) {
                $paired_ots_ids[] = $p['ots_record_id'] ?? $p['record_id'];
            }
        }
        $paired_map = array_flip($paired_ots_ids);

        if ($o_res) {
            while ($row = $o_res->fetch_assoc()) {
                $row['_source'] = 'OTS';
                $row['bank_amount'] = $row['adjusted_amount'];
                $row['bank_date'] = $row['DATETIME'] ? substr($row['DATETIME'], 0, 10) : '';
                $row['bank_desc'] = $row['ots_desc_full'];
                $row['status'] = '';
                $row['_is_paired'] = isset($paired_map[$row['RECORD_ID']]);
                $results[] = $row;
            }
        }
    }

    // Sort combined results by date desc
    if ($source === 'both') {
        usort($results, function ($a, $b) {
            $da = $a['bank_date'] ?? '';
            $db = $b['bank_date'] ?? '';
            return strcmp($db, $da);
        });
    }

    $query_time = round((microtime(true) - $start_time) * 1000);
} catch (Exception $e) {
    $error_msg = 'Lekérdezési hiba: ' . $e->getMessage();
    $query_time = round((microtime(true) - $start_time) * 1000);
}
}

// === EXPORT CSV ===
if ($export && $has_search) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tranzakcio_kereses.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM

    if ($source === 'bank' || $source === 'both') {
        fputcsv($out, ['Forrás', 'Dátum', 'Összeg', 'Közlemény', 'Gyülekezet', 'Kezdeményező', 'Státusz', 'OTS Bizonylat', 'Banki azonosító']);
        foreach ($results as $r) {
            if ($r['_source'] !== 'Bank') continue;
            fputcsv($out, [
                'Bank',
                $r['bank_date'] ?? '',
                number_format(floatval($r['bank_amount'] ?? 0), 0, ',', ' ') . ' Ft',
                $r['bank_desc'] ?? '',
                $r['church_name'] ?? '',
                $r['bank_ext_name'] ?? '',
                $r['status'] ?? '',
                $r['ots_doc'] ?? '',
                $r['bank_ext_ref'] ?? '',
            ]);
        }
    }
    if ($source === 'ots' || $source === 'both') {
        if ($source === 'both') {
            fputcsv($out, []);
        }
        fputcsv($out, ['Forrás', 'Dátum', 'Összeg', 'Leírás', 'Gyülekezet', 'Forgalom', 'Típus', 'Bizonylatszám', 'Határozati szám']);
        foreach ($results as $r) {
            if ($r['_source'] !== 'OTS') continue;
            fputcsv($out, [
                'OTS',
                $r['bank_date'] ?? '',
                number_format(floatval($r['adjusted_amount'] ?? 0), 0, ',', ' ') . ' Ft',
                $r['ots_desc_full'] ?? '',
                $r['church_name'] ?? '',
                $r['flow_label'] ?? '',
                $r['ots_type_name'] ?? '',
                $r['CASH_DOCUMENT_NUMBER'] ?? '',
                $r['DECISION_NUMBER'] ?? '',
            ]);
        }
    }
    fclose($out);
    exit;
}

// === HTML ===
$source_options = ['bank' => 'Bank', 'ots' => 'OTS', 'both' => 'Mindkettő'];
$flow_options = ['bank' => 'Bank', 'cash' => 'Készpénz', 'both' => 'Mindkettő'];
$status_options = ['all' => 'Mind', 'matched' => 'Párosított', 'unmatched' => 'Párosítatlan'];

$total_pages = $total > 0 ? ceil($total / $per_page) : 0;
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <title>Revizor Asszisztens 1.0 – Tranzakció Kereső</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; padding: 15px; font-size: 14px; }
        .card { box-shadow: 0 2px 6px rgba(0,0,0,0.08); }
        .table th { white-space: nowrap; background: #e9ecef; }
        .result-count { font-size: 13px; }
        .status-OK { color: #198754; font-weight: bold; }
        .status-UNCHECKED { color: #6c757d; }
        .status-HIÁNY, .status-ELTÉRÉS { color: #dc3545; }
        .status-ÖSSZEVONT { color: #0d6efd; }
        @media print { .card-header .btn, .pagination { display: none; } }
        .sort-asc::after { content: " ▲"; font-size: 10px; }
        .sort-desc::after { content: " ▼"; font-size: 10px; }
        th[onclick] { cursor: pointer; user-select: none; }
        th[onclick]:hover { background: #d0d5dd !important; }
    </style>
</head>
<body>

<div class="container-fluid" style="max-width:1400px;">

    <div class="d-flex justify-content-between align-items-center mb-3 px-3 py-2 bg-white rounded border shadow-sm">
        <div class="d-flex align-items-center gap-2">
            <span class="fw-bold">🕵️ Revizor Asszisztens 1.0</span>
            <span class="text-muted mx-1">|</span>
            <span class="text-muted">Tranzakció Kereső</span>
        </div>
        <div class="d-flex align-items-center gap-1">
            <a href="index.php" class="btn btn-outline-secondary btn-sm">🏠 Kezdőlap</a>
            <a href="help.php" class="btn btn-outline-primary btn-sm">❓ Súgó</a>
            <a href="logout.php" class="btn btn-outline-danger btn-sm ms-1">Kilépés</a>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-dark text-white py-2">
            <h5 class="mb-0">🔍 Tranzakció Kereső</h5>
        </div>
    <div class="card-body">
        <form method="GET" action="search.php" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small mb-0">Forrás</label>
                <select name="source" class="form-select form-select-sm">
                    <?php foreach ($source_options as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $source === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-0">Gyülekezet</label>
                <select name="church_id" class="form-select form-select-sm">
                    <option value="0">Összes</option>
                    <?php foreach ($churches as $ch): ?>
                    <option value="<?= $ch['id'] ?>" <?= $church_id === intval($ch['id']) ? 'selected' : '' ?>><?= htmlspecialchars($ch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">Összeg (min)</label>
                <input type="number" name="amount_min" class="form-control form-control-sm" value="<?= $amount_min !== null ? htmlspecialchars($amount_min) : '' ?>" step="1" placeholder="pl. 1000">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">Összeg (max)</label>
                <input type="number" name="amount_max" class="form-control form-control-sm" value="<?= $amount_max !== null ? htmlspecialchars($amount_max) : '' ?>" step="1" placeholder="pl. 500000">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-0">Közlemény / Leírás</label>
                <div class="d-flex gap-1">
                    <input type="text" name="description" class="form-control form-control-sm" value="<?= htmlspecialchars($description) ?>" placeholder="pl. tized, adomány" style="flex:1;">
                    <div class="form-check form-check-inline d-flex align-items-center mt-1">
                        <input class="form-check-input" type="checkbox" id="exact_word" name="exact_word" value="1" <?= $exact_word ? 'checked' : '' ?> style="margin-top:0;">
                        <label class="form-check-label small text-nowrap ms-1" for="exact_word" title="Csak önálló szóként keres (pl. 'könyv' nem talál 'könyvelési'-re)">🔤</label>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">Dátum tól</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($date_from) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">Dátum ig</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($date_to) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">Bizonylatszám</label>
                <input type="text" name="doc_number" class="form-control form-control-sm" value="<?= htmlspecialchars($doc_number) ?>" placeholder="OTS bizonylat">
            </div>
            <div class="col-md-2" id="flow_col">
                <label class="form-label small mb-0">Forgalom (OTS)</label>
                <select name="flow" class="form-select form-select-sm">
                    <?php foreach ($flow_options as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $flow === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2" id="status_col">
                <label class="form-label small mb-0">Státusz (Bank)</label>
                <select name="status_filter" class="form-select form-select-sm">
                    <?php foreach ($status_options as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $status_filter === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-12 mt-2">
                <button type="submit" class="btn btn-primary btn-sm">🔎 Keresés</button>
                <a href="search.php" class="btn btn-outline-secondary btn-sm">✕ Szűrők törlése</a>
                <?php if ($has_search && $total > 0): ?>
                <a href="search.php?<?= http_build_query(array_merge($_GET, ['export' => 'csv', 'page' => 1])) ?>" class="btn btn-success btn-sm">📥 Excel export</a>
                <?php endif; ?>
                <span class="text-muted small ms-2" id="query_info"></span>
            </div>
        </form>
    </div>
</div>

<script>
// Show/hide flow and status columns based on source
document.querySelector('[name="source"]').addEventListener('change', function() {
    var v = this.value;
    document.getElementById('flow_col').style.display = (v === 'bank') ? 'none' : '';
    document.getElementById('status_col').style.display = (v === 'ots') ? 'none' : '';
});
document.querySelector('[name="source"]').dispatchEvent(new Event('change'));
document.getElementById('query_info').textContent = '<?= $has_search ? ($error_msg ? "Hiba" : "Lekérdezés ideje: {$query_time} ms") : "" ?>';
</script>

<?php if ($has_search): ?>
<div class="card">
    <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
        <span class="fw-bold result-count">Találatok: <span class="text-primary"><?= number_format($total, 0, ',', ' ') ?></span> db<?= $total_pages > 0 ? " ({$page}/{$total_pages} oldal)" : '' ?></span>
    </div>
    <div class="card-body p-0">
        <?php if ($error_msg): ?>
        <div class="alert alert-danger m-3"><?= htmlspecialchars($error_msg) ?></div>
        <?php elseif (empty($results)): ?>
        <div class="alert alert-warning m-3">Nincs találat a megadott feltételekkel.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-striped table-bordered mb-0" style="font-size:13px;">
                <thead>
                    <tr>
                        <th onclick="sortTableBy(this)">#</th>
                        <th></th>
                        <?php if ($church_id === 0): ?><th onclick="sortTableBy(this)" data-sort-type="string">Gyülekezet</th><?php endif; ?>
                        <th onclick="sortTableBy(this)" data-sort-type="string">Forrás</th>
                        <th onclick="sortTableBy(this)" data-sort-type="date">Dátum</th>
                        <th onclick="sortTableBy(this)" data-sort-type="number" style="text-align:right;">Összeg</th>
                        <th onclick="sortTableBy(this)" data-sort-type="string">Közlemény / Leírás</th>
                        <?php if ($source === 'bank' || $source === 'both'): ?>
                        <th onclick="sortTableBy(this)" data-sort-type="string">Státusz</th>
                        <th onclick="sortTableBy(this)" data-sort-type="string">OTS bizonylat</th>
                        <?php endif; ?>
                        <?php if ($source === 'ots' || $source === 'both'): ?>
                        <th onclick="sortTableBy(this)" data-sort-type="string">Forgalom</th>
                        <th onclick="sortTableBy(this)" data-sort-type="string">Típus</th>
                        <th onclick="sortTableBy(this)" data-sort-type="string">Bizonylatszám</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php $idx = $offset + 1; ?>
                    <?php foreach ($results as $r): ?>
                    <tr>
                        <td><?= $idx++ ?></td>
                        <td>
                            <?php if ($r['_source'] === 'Bank'): ?>
                                <a href="reconciliation.php?bank_id=<?= intval($r['id']) ?>" class="btn btn-outline-primary btn-sm py-0 px-1" title="Egyeztetés">⚡</a>
                            <?php elseif ($r['_source'] === 'OTS'): ?>
                                <a href="all_transactions/all_transactions_multi.php?record_id=<?= intval($r['RECORD_ID']) ?>" class="btn btn-outline-secondary btn-sm py-0 px-1" target="_blank" title="OTS megnyitása">🔗</a>
                            <?php endif; ?>
                        </td>
                        <?php if ($church_id === 0): ?><td><?= htmlspecialchars($r['church_name'] ?? '-') ?></td><?php endif; ?>
                        <td>
                            <span class="badge bg-<?= $r['_source'] === 'Bank' ? 'primary' : 'secondary' ?>"><?= $r['_source'] ?></span>
                            <?php if ($r['_source'] === 'OTS'): ?>
                                <?php if ($r['_is_paired'] ?? false): ?>
                                    <span class="badge bg-success ms-1" title="Párosítva">✅</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted ms-1" title="Nincs párosítva">⚪</span>
                                <?php endif; ?>
                            <?php elseif ($r['_source'] === 'Bank'): ?>
                                <?php if (!empty($r['ots_record_id'])): ?>
                                    <span class="badge bg-success ms-1" title="OTS #<?= intval($r['ots_record_id']) ?> párosítva">✅</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted ms-1" title="Nincs párosítva">⚪</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($r['bank_date'] ?? '') ?></td>
                        <td style="text-align:right; white-space:nowrap;" class="<?= (floatval($r['bank_amount'] ?? 0) < 0) ? 'text-danger' : 'text-success' ?> fw-bold">
                            <?= number_format(floatval($r['bank_amount'] ?? 0), 0, ',', ' ') ?> Ft
                        </td>
                        <td><?= htmlspecialchars(mb_substr($r['bank_desc'] ?? '-', 0, 120)) ?></td>

                        <?php if ($source === 'bank' || $source === 'both'): ?>
                        <td>
                            <?php if ($r['_source'] === 'Bank'): ?>
                                <?php
                                $st = $r['status'] ?? 'UNCHECKED';
                                $st_labels = ['OK' => 'OK', 'UNCHECKED' => '☐', 'HIÁNY' => 'HIÁNY', 'ELTÉRÉS' => 'ELT', 'ÖSSZEVONT' => 'ÖV', 'CSUSZAS' => 'CSUSZAS'];
                                $st_class = 'status-' . $st;
                                ?>
                                <span class="<?= $st_class ?>"><?= $st_labels[$st] ?? $st ?></span>
                                <?php if (!empty($r['ots_date']) && ($st === 'OK' || $st === 'CSUSZAS')): ?>
                                    <br><small class="text-muted">OTS: <?= $r['ots_date'] ?><br><?= number_format(floatval($r['ots_amount'] ?? 0), 0, ',', ' ') ?> Ft</small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['_source'] === 'Bank'): ?>
                                <?php if (!empty($r['ots_record_id'])): ?>
                                    <a href="all_transactions/all_transactions_multi.php?record_id=<?= intval($r['ots_record_id']) ?>" target="_blank" class="text-decoration-none">
                                        #<?= intval($r['ots_record_id']) ?>
                                    </a>
                                    <?php if (!empty($r['ots_doc'])): ?>
                                        <small class="text-muted d-block"><?= htmlspecialchars($r['ots_doc']) ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>

                        <?php if ($source === 'ots' || $source === 'both'): ?>
                        <td>
                            <?php if ($r['_source'] === 'OTS'): ?>
                                <?= htmlspecialchars($r['flow_label'] ?? '-') ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['_source'] === 'OTS'): ?>
                                <?= htmlspecialchars($r['ots_type_name'] ?? '-') ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['_source'] === 'OTS'): ?>
                                <?= htmlspecialchars($r['CASH_DOCUMENT_NUMBER'] ?? '-') ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="d-flex justify-content-center py-2">
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">«</a>
                    </li>
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">‹</a>
                    </li>
                    <?php
                    $start_p = max(1, $page - 2);
                    $end_p = min($total_pages, $page + 2);
                    if ($start_p > 1): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                    <?php endif; ?>
                    <?php for ($i = $start_p; $i <= $end_p; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>
                    <?php if ($end_p < $total_pages): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                    <?php endif; ?>
                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">›</a>
                    </li>
                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>">»</a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

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
// Táblázat rendezés
function sortTableBy(el) {
    var table = el.closest('table');
    var tbody = table.querySelector('tbody');
    var rows = Array.from(tbody.querySelectorAll('tr'));
    var col = Array.from(el.parentNode.children).indexOf(el);
    var type = el.getAttribute('data-sort-type') || 'string';
    var dir = el.getAttribute('data-sort-dir') || 'asc';

    rows.sort(function(a, b) {
        var va = a.children[col].textContent.trim();
        var vb = b.children[col].textContent.trim();
        if (type === 'number') {
            va = parseFloat(va.replace(/\s/g, '').replace('Ft', '').replace(',', '.')) || 0;
            vb = parseFloat(vb.replace(/\s/g, '').replace('Ft', '').replace(',', '.')) || 0;
        } else if (type === 'date') {
            va = va.replace(/\./g, '-');
            vb = vb.replace(/\./g, '-');
        }
        if (va < vb) return dir === 'asc' ? -1 : 1;
        if (va > vb) return dir === 'asc' ? 1 : -1;
        return 0;
    });

    el.setAttribute('data-sort-dir', dir === 'asc' ? 'desc' : 'asc');

    // Feltöltés új sorrendben
    rows.forEach(function(row) { tbody.appendChild(row); });

    // Nyilak frissítése
    el.closest('tr').querySelectorAll('th').forEach(function(th) {
        th.classList.remove('sort-asc', 'sort-desc');
    });
    el.classList.add(dir === 'asc' ? 'sort-asc' : 'sort-desc');
}

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

// Poll server every 30s → extends session automatically
setInterval(extendSession, 30000);

// Countdown update every second
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
<?php
$conn->close();
$ots_db->close();
?>
