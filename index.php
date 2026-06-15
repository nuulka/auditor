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

$conn = new mysqli('localhost', 'root', '', 'revizor_db');
if ($conn->connect_error) { die("Database connection failed: " . $conn->connect_error); }
$conn->set_charset("utf8mb4");

// BIZTONSÁGI JAVÍTÁS: Mezők hozzáadása a részletes adatokhoz, ha még nem léteznek (MySQL 8 kompatibilis módon)
$existing_columns = [];
$columns_res = $conn->query("SHOW COLUMNS FROM bank_reconciliation");
if ($columns_res) {
    while ($col_row = $columns_res->fetch_assoc()) {
        $existing_columns[] = $col_row['Field'];
    }
}

if (!in_array('bank_init_name', $existing_columns)) {
    $conn->query("ALTER TABLE bank_reconciliation 
        ADD COLUMN bank_init_name VARCHAR(150),
        ADD COLUMN bank_init_acc VARCHAR(50),
        ADD COLUMN bank_ben_name VARCHAR(150),
        ADD COLUMN bank_ben_acc VARCHAR(50)");
}

// BIZTONSÁGI JAVÍTÁS: Módosítjuk a status oszlopot, hogy minden új státuszt (pl. CSUSZAS, OSSZEVONT) el tudjon menteni hiba nélkül!
$conn->query("ALTER TABLE bank_reconciliation MODIFY COLUMN status VARCHAR(20) DEFAULT 'UNCHECKED'");

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $status = isset($_POST['status']) ? $conn->real_escape_string(trim($_POST['status'])) : 'UNCHECKED';
    $comment = isset($_POST['comment']) ? $conn->real_escape_string(trim($_POST['comment'])) : '';
    $ots_doc_input = isset($_POST['ots_doc']) ? $conn->real_escape_string(trim($_POST['ots_doc'])) : '';
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo "CSRF token mismatch";
        exit;
    }
    $user = $_SESSION[GC_USER_FULL_NAME] ?? 'Ismeretlen';

    if ($id > 0) {
        if ($status === 'UNCHECKED' && empty($ots_doc_input)) {
            // Ha visszaállítják Feldolgozatlanra és nincs bizonylatszám, töröljük az OTS adatokat (Tiszta lap)
            $sql = "UPDATE bank_reconciliation SET status='$status', comment='$comment', updated_by='$user', ots_date=NULL, ots_doc='', ots_amount=NULL WHERE id=$id";
            $conn->query($sql);
        } else {
            if (!empty($ots_doc_input)) {
                // Kézi bizonylatszám párosítás
                $row_q = $conn->query("SELECT church_id FROM bank_reconciliation WHERE id=$id");
                if ($row_q && $row_q->num_rows > 0) {
                    $r = $row_q->fetch_assoc();
                    $c_id = $r['church_id'];

                    // Megkeressük az OTS-ben a bizonylatot (akár bank, akár pénztár)
                    $ots_query = "SELECT DATE(MAX(DATETIME)) as ots_date, SUM(IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT)) as ots_amount 
                                  FROM ots.TRANSACTIONS T
                                  WHERE CHURCH_ID = $c_id AND CASH_DOCUMENT_NUMBER = '$ots_doc_input'
                                  GROUP BY RECORD_ID LIMIT 1";
                    $ots_res = $conn->query($ots_query);
                    
                    if ($ots_res && $ots_res->num_rows > 0) {
                        $ots_data = $ots_res->fetch_assoc();
                        $o_date = $ots_data['ots_date'];
                        $o_amt = $ots_data['ots_amount'];
                        if ($status === 'UNCHECKED') { $status = 'OK'; }
                        $sql = "UPDATE bank_reconciliation SET status='$status', comment='$comment', updated_by='$user', ots_date='$o_date', ots_doc='$ots_doc_input', ots_amount=$o_amt WHERE id=$id";
                    } else {
                        $sql = "UPDATE bank_reconciliation SET status='$status', comment='$comment', updated_by='$user', ots_doc='$ots_doc_input' WHERE id=$id";
                    }
                    $conn->query($sql);
                }
            } else {
                $sql = "UPDATE bank_reconciliation SET status='$status', comment='$comment', updated_by='$user' WHERE id=$id";
                $conn->query($sql);
            }
        }
        echo "OK";
    }
    exit;
}

// KÉZI NYOMOZÁS KONKRÉT ÖSSZEGRE AZ OTS-BEN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'search_ots_amount') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['status' => 'ERROR', 'message' => 'CSRF token mismatch']);
        exit;
    }
    header('Content-Type: application/json');
    $amount = abs(floatval($_POST['amount']));
    
    $sql = "SELECT c.name as church_name, DATE(MAX(T.DATETIME)) as ots_date, T.CASH_DOCUMENT_NUMBER as ots_doc, 
                   SUM(IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT)) as total_amount, T.VIA_BANK,
                   TRIM(CONCAT(
                        IFNULL(CONCAT_WS(' ', MAX(p.NAME_PREFIX), MAX(p.NAME), MAX(p.NAME_SUFFIX)), ''), 
                        ' ', 
                        IFNULL(MAX(nt1.NAME), ''),
                        ' ',
                        IFNULL(MAX(nt2.NAME), '')
                   )) AS ots_desc
            FROM ots.TRANSACTIONS T
            LEFT JOIN ots.churches c ON T.CHURCH_ID = c.id
            LEFT JOIN ots.PERSONS p ON T.PERSON_ID = p.id
            LEFT JOIN ots.NAMES_OF_TRANSACTION nt1 ON T.NAME_ID = nt1.id
            LEFT JOIN ots.NAMES_OF_TRANSACTION nt2 ON T.NAME2_ID = nt2.id
            WHERE T.CASH_DOCUMENT_NUMBER != ''
            GROUP BY T.RECORD_ID, T.CHURCH_ID, T.CASH_DOCUMENT_NUMBER, T.VIA_BANK
            HAVING ABS(SUM(IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT))) = ?
            ORDER BY ots_date DESC LIMIT 25";
            
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("d", $amount);
        $stmt->execute();
        $res = $stmt->get_result();
        $results = [];
        while($row = $res->fetch_assoc()) { $results[] = $row; }
        echo json_encode(['status' => 'OK', 'data' => $results]);
    } else {
        echo json_encode(['status' => 'ERROR']);
    }
    exit;
}

// TÖMEGES JÓVÁHAGYÁS (Látható CSÚSZÁS tételek OK-ra állítása)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_approve') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['status' => 'ERROR', 'message' => 'CSRF token mismatch']);
        exit;
    }
    header('Content-Type: application/json');
    $ids = json_decode($_POST['ids'] ?? '[]', true);
    if (is_array($ids) && count($ids) > 0) {
        $ids_str = implode(',', array_map('intval', $ids));
        $user = $_SESSION[GC_USER_FULL_NAME] ?? 'Ismeretlen';
        $sql = "UPDATE bank_reconciliation SET status = 'OK', updated_by = '".$conn->real_escape_string($user)."' WHERE id IN ($ids_str) AND status = 'CSUSZAS'";
        if ($conn->query($sql)) {
            echo json_encode(['status' => 'OK', 'count' => $conn->affected_rows]);
            exit;
        }
    }
    echo json_encode(['status' => 'ERROR']);
    exit;
}

// UTÓLAGOS AUTOMATIKUS PÁROSÍTÁS LOGIKA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'auto_match') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['status' => 'ERROR', 'message' => 'CSRF token mismatch']);
        exit;
    }
    header('Content-Type: application/json');
    
    $mode = $_POST['match_mode'] ?? 'progressive';
    $custom_days = isset($_POST['custom_days']) ? intval($_POST['custom_days']) : 0;
    
    // Ha progresszív, akkor 4 körben fut le. Ha egyedi, csak 1 körben az adott nappal.
    $passes = ($mode === 'progressive') ? [0, 3, 6, 12, 35, 60, 'text'] : [$custom_days];
    
    $unmatched = $conn->query("SELECT id, church_id, bank_date, bank_amount, bank_desc, bank_ext_name FROM bank_reconciliation WHERE status = 'UNCHECKED'");
    $stats = ['pass_0' => 0, 'pass_3' => 0, 'pass_6' => 0, 'pass_12' => 0, 'pass_35' => 0, 'pass_60' => 0, 'pass_text' => 0, 'custom' => 0];
    $total_matched = 0;
    
    if ($unmatched && $unmatched->num_rows > 0) {
        while ($row = $unmatched->fetch_assoc()) {
            $id = $row['id']; $church_id = $row['church_id']; $bank_date = $row['bank_date']; 
            $bank_amount = $row['bank_amount']; $b_desc = $row['bank_desc']; $b_name = $row['bank_ext_name'];
            
            foreach ($passes as $days) {
                if ($days === 'text') {
                    // SZÖVEGES KUTATÁS (Név, Közlemény, Szolgáltatók) +/- 30 napban
                    $start_date = date('Y-m-d', strtotime("$bank_date -30 days"));
                    $end_date = date('Y-m-d', strtotime("$bank_date +30 days"));
                    
                    $ots_query = "SELECT RECORD_ID, MAX(CASH_DOCUMENT_NUMBER) AS ots_doc, MAX(DATETIME) AS ots_date, SUM(IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT)) as ots_amount,
                                  TRIM(CONCAT(
                                      IFNULL((SELECT CONCAT_WS(' ', NAME_PREFIX, NAME, NAME_SUFFIX) FROM ots.PERSONS WHERE id = MAX(T.PERSON_ID)), ''), 
                                      ' ', 
                                      IFNULL((SELECT NAME FROM ots.NAMES_OF_TRANSACTION WHERE id = MAX(T.NAME_ID)), '')
                                  )) AS ots_desc
                                  FROM ots.TRANSACTIONS T
                                  WHERE CHURCH_ID = ? AND DATETIME BETWEEN ? AND ? AND VIA_BANK <> 0 
                                  AND ABS(PERIOD_DIFF(EXTRACT(YEAR_MONTH FROM ?), EXTRACT(YEAR_MONTH FROM T.DATETIME))) <= 1
                                  GROUP BY RECORD_ID";
                    
                    $stmt_ots = $conn->prepare($ots_query);
                    if ($stmt_ots) {
                        $stmt_ots->bind_param("isss", $church_id, $start_date, $end_date, $bank_date);
                        $stmt_ots->execute();
                        $ots_result = $stmt_ots->get_result();
                        
                        $b_text = mb_strtoupper($b_desc . ' ' . $b_name, 'UTF-8');
                        $b_words = preg_split('/[\s,\.\-\/]+/u', $b_text, -1, PREG_SPLIT_NO_EMPTY);
                        
                        $best_match = null;
                        $best_score = 0;
                        $text_score = 0;
                        $min_amt_diff = PHP_INT_MAX;
                        $same_amount_count = 0;
                        $is_large_amount = (abs($bank_amount) >= 100000); // 100.000 Ft feletti tételek
                        
                        if ($ots_result && $ots_result->num_rows > 0) {
                            while ($ots_row = $ots_result->fetch_assoc()) {
                                $ots_desc = mb_strtoupper($ots_row['ots_desc'], 'UTF-8');
                                $text_score = 0;
                                
                                foreach ($b_words as $word) {
                                    if (mb_strlen($word, 'UTF-8') >= 4 && mb_strpos($ots_desc, $word) !== false) {
                                        $text_score++;
                                    }
                                }
                                
                                // Közművek, szolgáltatók és adóhivatal specifikus egyezés (+3 pont)
                                if (preg_match('/(MVM|EON|NKM|TELEKOM|VODAFONE|YETTEL|DIGI|F[ŐO]GÁZ|VÍZM[ŰU]VEK|MÁK|NAV|CIGAM)/u', $b_text, $m)) {
                                    if (mb_strpos($ots_desc, $m[1]) !== false) {
                                        $text_score += 3;
                                    }
                                }
                                
                                $score = $text_score;
                                $amt_diff = abs(round((float)$bank_amount - (float)$ots_row['ots_amount'], 2));
                                if ($amt_diff < 1) {
                                    $score += 2; // Összeg pontosan stimmel
                                    $same_amount_count++;
                                }
                                
                                // KIZÁRÓLAG akkor fogadjuk el a szöveges találatot, ha a leírásban/névben legalább egy valós egyezés volt!
                                // KIVÉTEL: Ha ez egy nagyon nagy összeg (pl >= 100.000 Ft) és az összeg fillérre egyezik, akkor szöveges egyezés nélkül is elfogadjuk!
                                if (($text_score > 0 || ($is_large_amount && $amt_diff < 1)) && $score >= 2) {
                                    if ($score > $best_score || ($score == $best_score && $amt_diff < $min_amt_diff)) {
                                        $best_score = $score;
                                        $best_match = $ots_row;
                                        $min_amt_diff = $amt_diff;
                                    }
                                }
                            }
                        }
                        
                        if ($best_match) {
                            $ots_date_only = $best_match['ots_date'] ? substr($best_match['ots_date'], 0, 10) : null;
                            $ots_doc_clean = $best_match['ots_doc'] ?? '';
                            $ots_amt = $best_match['ots_amount'];
                            
                            $extra_info = "";
                            $comment = "";
                            
                            if ($ots_date_only === $bank_date && $min_amt_diff < 1) {
                                $new_status = 'OK';
                                $comment = '[Auto: 100% egyezés, 0 nap (szöveges találat)]';
                            } else {
                                $new_status = ($min_amt_diff < 1) ? 'CSUSZAS' : 'ELTERES';
                            if ($text_score == 0 && $is_large_amount) {
                                $extra_info = "összeg OK, nagy összegű egyedi tétel szöveges egyezés nélkül";
                            } else if ($min_amt_diff < 1) {
                                if ($same_amount_count > 1) {
                                    $extra_info = "összeg OK, $same_amount_count db azonos összegből pontozva (név alapján)";
                                } else {
                                    $extra_info = "összeg OK, egyetlen ilyen összeg 30 napon belül";
                                }
                            } else {
                                $extra_info = "eltérő összeg, név alapján";
                            }
                            
                            $comment = "[Auto: Szöveges, $extra_info]";
                            }
                            
                            $upd_stmt = $conn->prepare("UPDATE bank_reconciliation SET ots_date=?, ots_doc=?, ots_amount=?, status=?, comment=? WHERE id=?");
                            $upd_stmt->bind_param("ssdssi", $ots_date_only, $ots_doc_clean, $ots_amt, $new_status, $comment, $id);
                            $upd_stmt->execute();
                            
                            if ($mode === 'progressive') { $stats['pass_text']++; } else { $stats['custom']++; }
                            $total_matched++;
                            break;
                        }
                    }
                } else {
                    $start_date = date('Y-m-d', strtotime("$bank_date -$days days"));
                    $end_date = date('Y-m-d', strtotime("$bank_date +$days days"));
                    
                    $ots_query = "SELECT MAX(CASH_DOCUMENT_NUMBER) AS ots_doc, MAX(DATETIME) AS ots_date 
                                  FROM ots.TRANSACTIONS T WHERE CHURCH_ID = ? AND DATETIME BETWEEN ? AND ?
                                  AND VIA_BANK <> 0 AND ABS(PERIOD_DIFF(EXTRACT(YEAR_MONTH FROM ?), EXTRACT(YEAR_MONTH FROM T.DATETIME))) <= 1
                                  GROUP BY RECORD_ID HAVING SUM(IF(T.TYPE IN ($exp_types_str), -1 * T.AMOUNT, T.AMOUNT)) = ?";
                                  
                    $stmt_ots = $conn->prepare($ots_query);
                    if ($stmt_ots) {
                        $stmt_ots->bind_param("isssd", $church_id, $start_date, $end_date, $bank_date, $bank_amount);
                        $stmt_ots->execute();
                        $ots_result = $stmt_ots->get_result();
                        
                        if ($ots_result && $ots_result->num_rows === 1) {
                            $ots_row = $ots_result->fetch_assoc();
                            $ots_date_only = $ots_row['ots_date'] ? substr($ots_row['ots_date'], 0, 10) : null;
                            $ots_doc_clean = $ots_row['ots_doc'] ?? '';
                            
                            // 0 nap esetén OK (és írásvédett), egyébként CSÚSZÁS (jóváhagyásra vár)
                            $new_status = ($days === 0) ? 'OK' : 'CSUSZAS'; // Ha 0 napos az eltérés, akkor OK, különben CSUSZAS
                            $comment = ($days === 0) ? '[Auto: 100% egyezés, 0 nap]' : "[Auto: $days nap eltérésen belül csak ez az egyetlen találat volt.]";
                            
                            $upd_stmt = $conn->prepare("UPDATE bank_reconciliation SET ots_date=?, ots_doc=?, ots_amount=?, status=?, comment=? WHERE id=?");
                            $upd_stmt->bind_param("ssdssi", $ots_date_only, $ots_doc_clean, $bank_amount, $new_status, $comment, $id);
                            $upd_stmt->execute();
                            
                            if ($mode === 'progressive') { $stats["pass_$days"]++; } else { $stats['custom']++; }
                            $total_matched++;
                            break; // Átmegyünk a következő sorra, mert ez már párosítva lett!
                        }
                    }
                }
            }
        }
    }
    echo json_encode(['status' => 'OK', 'matched' => $total_matched, 'details' => $stats]);
    exit;
}

// LAPOZÁS ÉS SZŰRÉS INICIALIZÁLÁSA
$page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$allowed_limits = [50, 100, 500, 999999];
$limit = isset($_GET['limit']) && in_array(intval($_GET['limit']), $allowed_limits) ? intval($_GET['limit']) : 50;
if ($limit >= 999999) { $page = 1; $offset = 0; } else { $offset = ($page - 1) * $limit; }

$selected_church_name = isset($_GET['church_filter']) ? trim($_GET['church_filter']) : '';
$selected_church_id = -1;

// Betöltjük a számlaszám térképet, hogy tudjuk, kinek van bankszámlája
$manual_accounts = file_exists(__DIR__ . '/szamlak.php') ? include(__DIR__ . '/szamlak.php') : [];
$mapped_ids = array_unique(array_values($manual_accounts));
$mapped_ids_str = implode(',', array_map('intval', array_filter($mapped_ids, function($id) { return $id > 0; })));
if (empty($mapped_ids_str)) $mapped_ids_str = "0"; // Biztonsági fallback

// Gyülekezeti lista és szűrési paraméterek meghatározása
$churches = [];
$church_names_map = [];
$churches_query = $conn->query("SELECT id, name FROM ots.churches WHERE id IN ($mapped_ids_str) AND name IS NOT NULL AND name != '' ORDER BY name ASC");
if ($churches_query) {
    while ($c_row = $churches_query->fetch_assoc()) {
        $churches[] = $c_row['name'];
        $church_names_map[$c_row['id']] = $c_row['name'];
        if ($c_row['name'] === $selected_church_name) {
            $selected_church_id = $c_row['id'];
        }
    }
}

$where_sql = ($selected_church_id !== -1) ? " WHERE b.church_id = $selected_church_id " : " WHERE b.church_id IN ($mapped_ids_str) ";
$ots_where_sql = ($selected_church_id !== -1) ? " AND CHURCH_ID = $selected_church_id " : "";
$url_params = !empty($selected_church_name) ? "church_filter=" . urlencode($selected_church_name) : "";

// Teljes rekordszám lekérése a lapozáshoz
$count_res = $conn->query("SELECT COUNT(*) as cnt FROM bank_reconciliation b $where_sql");
$total_db_rows = ($count_res) ? $count_res->fetch_assoc()['cnt'] : 0;
$total_pages = $limit > 0 ? ceil($total_db_rows / $limit) : 1;

// A főtáblát kiegészítjük az OTS rendszer valós idejű adataival (Optimalizált, szupergyors LEFT JOIN módszer)
$result = $conn->query("SELECT 
                            b.*, 
                            c.name AS church_name,
                            TRIM(CONCAT(
                                IFNULL(CONCAT_WS(' ', p.NAME_PREFIX, p.NAME, p.NAME_SUFFIX), ''), 
                                ' ', 
                                IFNULL(nt1.NAME, ''),
                                ' ',
                                IFNULL(nt2.NAME, '')
                            )) AS ots_desc_full,
                            t_agg.ots_decision,
                            tt.NAME AS ots_type,
                            u.NAME AS ots_editor
                        FROM bank_reconciliation b
                        LEFT JOIN ots.churches c ON b.church_id = c.id
                        LEFT JOIN (
                            SELECT 
                                RECORD_ID, RECORD_ID AS ots_record_id, CHURCH_ID, CASH_DOCUMENT_NUMBER, 
                                DATE(MAX(DATETIME)) AS ots_date, SUM(IF(TYPE IN ($exp_types_str), -1 * AMOUNT, AMOUNT)) AS total_amount,
                                MAX(PERSON_ID) AS PERSON_ID, MAX(NAME_ID) AS NAME_ID, MAX(NAME2_ID) AS NAME2_ID,
                                MAX(DECISION_NUMBER) AS ots_decision, MAX(TYPE) AS TYPE, MAX(EDITED_BY) AS EDITED_BY,
                                DATE(MAX(MODIFIED)) AS ots_edit_date, MAX(VIA_BANK) AS ots_via_bank
                            FROM ots.TRANSACTIONS 
                            WHERE VIA_BANK <> 0 AND CASH_DOCUMENT_NUMBER != '' 
                              $ots_where_sql
                              AND CASH_DOCUMENT_NUMBER IN (SELECT ots_doc FROM bank_reconciliation b $where_sql AND b.ots_doc != '')
                            GROUP BY RECORD_ID, CHURCH_ID, CASH_DOCUMENT_NUMBER
                        ) t_agg ON b.church_id = t_agg.CHURCH_ID AND b.ots_doc = t_agg.CASH_DOCUMENT_NUMBER 
                               AND b.ots_date = t_agg.ots_date AND b.ots_amount = t_agg.total_amount AND b.ots_doc != ''
                        LEFT JOIN ots.PERSONS p ON t_agg.PERSON_ID = p.id
                        LEFT JOIN ots.NAMES_OF_TRANSACTION nt1 ON t_agg.NAME_ID = nt1.id
                        LEFT JOIN ots.NAMES_OF_TRANSACTION nt2 ON t_agg.NAME2_ID = nt2.id
                        LEFT JOIN ots.TRANSACTION_TYPE tt ON t_agg.TYPE = tt.id
                        LEFT JOIN ots.USERS u ON t_agg.EDITED_BY = u.id
                        $where_sql
                        ORDER BY b.bank_date ASC
                        LIMIT $limit OFFSET $offset");
$total_rows = $result ? $result->num_rows : 0;
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <title>Bankegyeztető Felület</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; padding: 10px; padding-bottom: 45px; }
        .table-container { background: white; padding: 15px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        
        .table-responsive-scroll {
            max-height: 82vh;
            overflow-y: auto;
            overflow-x: hidden;
            border: 1px solid #dee2e6;
        }
        
        #sortableTable {
            table-layout: fixed;
            width: 100%;
        }

        .truncate {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .main-header th {
            position: sticky; top: 0; z-index: 10;
            background-color: #212529 !important; color: white !important;
            padding: 4px 10px; font-size: 13px; text-align: center;
        }
        
        .sub-header th {
            position: sticky; top: 29px; z-index: 10;
            background-color: #e9ecef !important; color: #212529 !important;
            cursor: pointer; user-select: none; padding: 4px 6px; font-size: 12px;
            text-align: center; vertical-align: top;
        }
        
        .sub-header th:hover { background-color: #dee2e6 !important; }
        .bg-bank { background-color: #f1f3f5; }
        .bg-ots { background-color: #ffffff; }
        .clickable-amount { cursor: pointer; text-decoration: underline; text-decoration-style: dotted; }
        .clickable-amount:hover { color: #0d6efd !important; background-color: #e9ecef; }
        .status-unchecked { color: #6c757d; font-style: italic; }
        .sort-asc::after { content: " ↑"; font-size: 10px; color: #0d6efd; }
        .sort-desc::after { content: " ↓"; font-size: 10px; color: #0d6efd; }

        .status-bar-fixed {
            position: fixed; bottom: 0; left: 0; width: 100%;
            background-color: #212529; color: #f8f9fa;
            padding: 4px 20px; font-size: 12px; font-weight: 500;
            box-shadow: 0 -2px 5px rgba(0,0,0,0.1); z-index: 1030;
            display: flex; justify-content: space-between; align-items: center;
        }

        .filter-input {
            width: 100%; display: block; margin-top: 4px; padding: 2px 4px;
            font-size: 11px; font-weight: normal; border: 1px solid #ccc; border-radius: 3px;
        }
        .filter-input:focus { border-color: #0d6efd; outline: 0; box-shadow: 0 0 3px rgba(13,110,253,0.3); }
        .info-dot { font-size: 10px; color: #0d6efd; vertical-align: super; margin-left: 2px; }

        /* Keresőmező design igazítása az új feltöltés gomb stílusához */
        .church-search-box {
            width: 280px;
            height: 31px;
            font-size: 13px;
            padding: 0 10px;
            border-radius: 4px;
            border: 1px solid #0d6efd;
            color: #0d6efd;
            font-weight: 500;
            background-color: #ffffff;
        }
        .church-search-box::placeholder { color: #6c757d; font-weight: normal; }
        .church-search-box:focus { outline: none; box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25); }

        /* Dinamikus Statisztika Buborék (Hover Tooltip) */
        .custom-tooltip-container {
            position: relative; display: inline-block; cursor: help; background-color: rgba(255,255,255,0.1); padding: 2px 8px; border-radius: 4px;
        }
        .custom-tooltip-container:hover { background-color: rgba(255,255,255,0.2); }
        .custom-tooltip-text {
            visibility: hidden; width: 220px; background-color: #343a40; color: #fff; text-align: left;
            border-radius: 6px; padding: 10px; position: absolute; z-index: 1050;
            bottom: 130%; left: 50%; margin-left: -110px; opacity: 0; transition: opacity 0.2s;
            box-shadow: 0 4px 8px rgba(0,0,0,0.3); font-size: 13px; line-height: 1.6; border: 1px solid #495057;
        }
        .custom-tooltip-container:hover .custom-tooltip-text { visibility: visible; opacity: 1; }
        .custom-tooltip-text::after {
            content: ""; position: absolute; top: 100%; left: 50%; margin-left: -5px;
            border-width: 5px; border-style: solid; border-color: #343a40 transparent transparent transparent;
        }
        .stat-row { display: flex; justify-content: space-between; }
    </style>
</head>
<body>

<div class="container-fluid table-container">
    
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="d-flex align-items-center gap-2">
            <h5 class="m-0 me-2">🕵️‍♂️ Revizori Panel</h5>
            <!-- SZERVEROLDALI GYÜLEKEZET SZŰRŐ -->
            <form method="GET" action="index.php" class="d-flex gap-2">
                <input type="hidden" name="limit" value="<?php echo $limit; ?>">
                <input list="churchesList" name="church_filter" id="churchSelect" class="form-control church-search-box" placeholder="Válassz gyülekezetet..." value="<?php echo htmlspecialchars($selected_church_name); ?>" onchange="this.form.submit()">
                <datalist id="churchesList">
                    <?php foreach ($churches as $church): ?>
                        <option value="<?php echo htmlspecialchars($church); ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <?php if($selected_church_id !== -1): ?><a href="index.php" class="btn btn-sm btn-outline-danger" title="Szűrés törlése">✕</a><?php endif; ?>
            </form>
        </div>
        <div>
            <button class="btn btn-outline-secondary btn-sm fw-bold me-2" onclick="exportTableToCSV()">📥 Excel Export</button>
            <button class="btn btn-outline-info btn-sm fw-bold me-2" onclick="bulkApproveCsuszas()">✅ Csúszások OKézása</button>
            <button class="btn btn-outline-success btn-sm fw-bold me-2" onclick="new bootstrap.Modal(document.getElementById('autoMatchModal')).show()">🤖 Automatikus Párosítás</button>
            <a href="feltolto.php" class="btn btn-primary btn-sm">Új banki fájl feltöltése</a>
            <a href="sugo.php" class="btn btn-outline-primary btn-sm ms-2" title="Súgó megnyitása">❓ Súgó</a>
        </div>
    </div>
    
    <div class="table-responsive-scroll">
        <table class="table table-bordered align-middle m-0" id="sortableTable">
            <thead>
                <tr class="main-header">
                    <th style="background-color: #495057 !important;">ADMIN</th>
                    <th colspan="3">BANKI ADATOK (Fix)</th>
                    <th colspan="4" style="background-color: #495057 !important;">KÖNYVELÉS / OTS (Fix)</th>
                    <th colspan="3" style="background-color: #0d6efd !important;">REVIZOR INTÉZKEDÉS</th>
                </tr>
                <tr class="sub-header">
                    <th style="width: 9%;" onclick="sortTable(0, 'string')">ID / Gyülekezet <input type="text" class="filter-input" placeholder="Szűr..." onclick="event.stopPropagation();" onkeyup="filterTable()"></th>
                    <th style="width: 7%;" onclick="sortTable(1, 'date')">Dátum <input type="text" class="filter-input" placeholder="Szűr..." onclick="event.stopPropagation();" onkeyup="filterTable()"></th>
                    
                    <th style="width: 9%;" onclick="sortTable(2, 'amount')" 
                        data-bs-toggle="tooltip" 
                        data-bs-placement="top" 
                        data-bs-html="true"
                        title="<b>Összeg szűrési tippek:</b><br>- kimenő<br>+ bejövő<br>-tól [szóköz] -ig">
                        Összeg <span class="info-dot">ℹ</span>
                        <input type="text" class="filter-input" placeholder="Kimenő, bejövő..." onclick="event.stopPropagation();" onkeyup="filterTable()">
                    </th>
                    
                    <th style="width: 16%;">Közlemény <input type="text" class="filter-input" placeholder="Szűr..." onclick="event.stopPropagation();" onkeyup="filterTable()"></th>
                    <th style="width: 7%;" onclick="sortTable(4, 'date')">OTS Dátum <input type="text" class="filter-input" placeholder="Szűr..." onclick="event.stopPropagation();" onkeyup="filterTable()"></th>
                    <th style="width: 8%;">Bizonylat <input type="text" class="filter-input" placeholder="Szűr..." onclick="event.stopPropagation();" onkeyup="filterTable()"></th>
                    
                    <th style="width: 14%;" onclick="sortTable(6, 'string')">OTS Leírás <input type="text" class="filter-input" placeholder="Szűr..." onclick="event.stopPropagation();" onkeyup="filterTable()"></th>
                    
                    <th style="width: 9%;" onclick="sortTable(7, 'amount')"
                        data-bs-toggle="tooltip" 
                        data-bs-placement="top" 
                        data-bs-html="true"
                        title="<b>Összeg szűrési tippek:</b><br>- kimenő<br>+ bejövő<br>-tól [szóköz] -ig">
                        OTS Összeg <span class="info-dot">ℹ</span>
                        <input type="text" class="filter-input" placeholder="Kimenő, bejövő..." onclick="event.stopPropagation();" onkeyup="filterTable()">
                    </th>
                    
                    <th style="width: 9%;" onclick="sortTable(8, 'string')">Státusz 
                        <select class="filter-input" onclick="event.stopPropagation();" onchange="filterTable()">
                            <option value="">Mind</option>
                            <option value="[Feldolgozatlan]">[Feldolgozatlan]</option>
                            <option value="[OK]">[OK]</option>
                            <option value="[HIÁNY]">[HIÁNY]</option>
                            <option value="[ELTÉRÉS]">[ELTÉRÉS]</option>
                            <option value="[IDŐ CSÚSZÁS]">[IDŐ CSÚSZÁS]</option>
                            <option value="[ÖSSZEVONT]">[ÖSSZEVONT]</option>
                        </select>
                    </th>
                    <th style="width: 11%;">Megjegyzés rovat <input type="text" class="filter-input" placeholder="Szűr..." onclick="event.stopPropagation();" onkeyup="filterTable()"></th>
                    <th style="width: 5%;">Akció</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result && $result->num_rows > 0): ?>
                    <?php while($row = $result->fetch_assoc()): ?>
                    <?php 
                        // Tudástár alkalmazása menet közben a megjelenítéshez (ha a DB-ben még a régi lenne)
                        $known_accounts = [
                            '1178400922224138' => 'TET OTP (Főszámla)',
                            '117840092222413800000000' => 'TET OTP (Főszámla)',
                            '104003395049575053561009' => 'TET K&H (Főszámla)',
                            '104003395049575053561030' => 'TET Építési Kápolna Alap',
                            '104003395049575053561054' => 'TET Műtéti Támogatás',
                            '104027645049575053561009' => 'MiskolcA Gyülekezet',
                        ];
                        $clean_acc = preg_replace('/[^0-9]/', '', $row['bank_ext_acc'] ?? '');
                        if (isset($known_accounts[$clean_acc])) {
                            $existing_name = trim($row['bank_ext_name'] ?? '');
                            $row['bank_ext_name'] = $known_accounts[$clean_acc] . ($existing_name && strpos($existing_name, $known_accounts[$clean_acc]) === false ? " ($existing_name)" : "");
                        }

                        // Ha a közlemény üres, akkor vizuálisan kicseréljük a Partner (célszámla) nevére
                        if (empty(trim($row['bank_desc'] ?? ''))) {
                            $row['bank_desc'] = $row['bank_ext_name'] ?? '';
                        }
                        // Ellenőrizzük, hogy van-e 100%-os egyezés írásvédelme
                        $is_locked = (strpos($row['comment'] ?? '', '[Auto: 100% egyezés, 0 nap]') !== false);
                    ?>
                    <tr id="row-<?php echo $row['id']; ?>" class="data-row" data-status="<?php echo $row['status']; ?>" data-church="<?php echo htmlspecialchars($row['church_name'] ?? ''); ?>" data-church-id="<?php echo $row['church_id']; ?>">
                        <td class="bg-light text-muted" style="font-size: 11px;" data-val="<?php echo htmlspecialchars($row['church_name'] ?? ''); ?>">
                            <strong>ID: <?php echo $row['church_id']; ?></strong><br>
                            <?php echo htmlspecialchars($row['church_name'] ?? 'ISMERETLEN'); ?>
                        </td>
                        <td class="bg-bank text-center" data-val="<?php echo $row['bank_date']; ?>"><?php echo $row['bank_date']; ?></td>
                        
                        <td class="bg-bank text-end fw-bold clickable-amount <?php echo $row['bank_amount'] < 0 ? 'text-danger' : 'text-success'; ?>"
                            data-val="<?php echo $row['bank_amount']; ?>" onclick="mutatKombinaltReszleteket(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                            <?php echo number_format($row['bank_amount'], 0, ',', ' '); ?> Ft
                        </td>
                        
                        <td class="bg-bank text-muted truncate" title="<?php echo htmlspecialchars($row['bank_desc'] ?? ''); ?>" data-val="<?php echo htmlspecialchars($row['bank_desc'] ?? ''); ?>" data-partner="<?php echo htmlspecialchars($row['bank_ext_name'] ?? ''); ?>" data-ref="<?php echo htmlspecialchars($row['bank_ext_ref'] ?? ''); ?>">
                            <small><?php echo htmlspecialchars($row['bank_desc'] ?? ''); ?></small>
                        </td>
                        <?php 
                            $tooltip_attr = "";
                            if (!empty($row['ots_date']) && $row['ots_date'] !== '-') {
                                try {
                                    $b_date = new DateTime($row['bank_date']);
                                    $o_date = new DateTime($row['ots_date']);
                                    $diff = $b_date->diff($o_date);
                                    $days = (int)$diff->format('%R%a');
                                    $diff_text = ($days == 0) ? "0 nap (pontos egyezés)" : abs($days) . " nap eltérés";
                                    $tooltip_attr = 'data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" title="<b>Banki dátum:</b> ' . htmlspecialchars($row['bank_date']) . '<br><b>Eltérés:</b> ' . $diff_text . '" style="cursor:help; border-bottom:1px dotted #000;"';
                                } catch (Exception $e) {}
                            }
                        ?>
                        <td class="bg-ots text-center" data-val="<?php echo $row['ots_date'] ?? '-'; ?>"><span <?php echo $tooltip_attr; ?>><?php echo $row['ots_date'] ?? '-'; ?></span></td>
                        <td class="bg-ots text-center" data-val="<?php echo $row['ots_doc'] ?? '-'; ?>">
                            <?php if ($row['status'] === 'UNCHECKED' || empty($row['ots_doc'])): ?>
                                <input type="text" id="manual-doc-<?php echo $row['id']; ?>" class="form-control form-control-sm text-center px-1" style="width: 70px; margin: 0 auto;" value="<?php echo htmlspecialchars($row['ots_doc'] ?? ''); ?>" placeholder="Biz.szám" title="Kézi bizonylatszám megadása">
                            <?php else: ?>
                                <?php echo htmlspecialchars($row['ots_doc']); ?>
                            <?php endif; ?>
                        </td>
                        <td class="bg-ots text-muted truncate" title="<?php echo htmlspecialchars($row['ots_desc_full'] ?? ''); ?>" data-val="<?php echo htmlspecialchars($row['ots_desc_full'] ?? ''); ?>">
                            <small><?php echo htmlspecialchars($row['ots_desc_full'] ?? ''); ?></small>
                        </td>
                        <td class="bg-ots text-end <?php echo !empty($row['ots_amount']) ? 'clickable-amount fw-bold ' . ($row['ots_amount'] < 0 ? 'text-danger' : 'text-success') : ''; ?>" data-val="<?php echo $row['ots_amount'] ?? 0; ?>" <?php if(!empty($row['ots_amount'])): ?> onclick="mutatKombinaltReszleteket(<?php echo htmlspecialchars(json_encode($row)); ?>)" <?php endif; ?>>
                            <?php echo $row['ots_amount'] ? number_format($row['ots_amount'], 0, ',', ' ') . ' Ft' : '-'; ?>
                        </td>
                        
                        <td>
                            <select id="status-<?php echo $row['id']; ?>" class="form-select form-select-sm fw-bold <?php echo $row['status'] == 'UNCHECKED' ? 'text-secondary bg-light' : ''; ?>" onchange="updateRowStatusData(<?php echo $row['id']; ?>, this.value)" <?php echo $is_locked ? 'disabled' : ''; ?>>
                                <option value="UNCHECKED" class="status-unchecked" <?php if($row['status'] == 'UNCHECKED') echo 'selected'; ?>>[Feldolgozatlan]</option>
                                <option value="OK" class="text-success" <?php if($row['status'] == 'OK') echo 'selected'; ?>>[OK]</option>
                                <option value="HIANY" class="text-danger" <?php if($row['status'] == 'HIANY') echo 'selected'; ?>>[HIÁNY]</option>
                                <option value="ELTERES" class="text-warning" <?php if($row['status'] == 'ELTERES') echo 'selected'; ?>>[ELTÉRÉS]</option>
                                <option value="CSUSZAS" class="text-info" <?php if($row['status'] == 'CSUSZAS') echo 'selected'; ?>>[IDŐ CSÚSZÁS]</option>
                                <option value="OSSZEVONT" class="text-primary" <?php if($row['status'] == 'OSSZEVONT') echo 'selected'; ?>>[ÖSSZEVONT]</option>
                            </select>
                        </td>
                        <td><input type="text" id="comment-<?php echo $row['id']; ?>" class="form-control form-control-sm <?php echo $is_locked ? 'bg-light text-muted' : ''; ?>" value="<?php echo htmlspecialchars($row['comment'] ?? ''); ?>" <?php echo $is_locked ? 'readonly' : ''; ?>></td>
                        <td class="text-center">
                            <?php if ($is_locked): ?>
                                <button class="btn btn-secondary btn-sm" disabled title="Tökéletes egyezés, írásvédett!">🔒 Kész</button>
                            <?php else: ?>
                                <button class="btn btn-success btn-sm" onclick="saveData(<?php echo $row['id']; ?>)">Mentés</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="9" class="text-center text-danger">Nincs adat!</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="status-bar-fixed">
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <div>📊 <span id="counter-visible"><?php echo $total_rows; ?></span> / <span id="counter-total"><?php echo $total_db_rows; ?></span> rekord</div>

        <form method="GET" action="index.php" class="d-flex align-items-center gap-1">
            <span class="small" style="font-size:11px;">Sor/Oldal:</span>
            <input type="hidden" name="p" value="1">
            <?php if ($selected_church_name): ?>
            <input type="hidden" name="church_filter" value="<?php echo htmlspecialchars($selected_church_name); ?>">
            <?php endif; ?>
            <select name="limit" class="form-select form-select-sm" style="width:75px; font-size:11px;" onchange="this.form.submit()">
                <option value="50"<?php echo $limit==50?' selected':''; ?>>50</option>
                <option value="100"<?php echo $limit==100?' selected':''; ?>>100</option>
                <option value="500"<?php echo $limit==500?' selected':''; ?>>500</option>
                <option value="999999"<?php echo $limit==999999?' selected':''; ?>>Összes</option>
            </select>
        </form>

        <?php if ($total_pages > 1 && $limit < 999999): $qs = ($selected_church_name ? 'church_filter='.urlencode($selected_church_name).'&' : '').'limit='.$limit; ?>
        <nav>
            <ul class="pagination pagination-sm mb-0" style="font-size:11px;">
                <li class="page-item<?php echo $page<=1?' disabled':''; ?>">
                    <a class="page-link" href="?p=1&<?php echo $qs; ?>">«</a>
                </li>
                <li class="page-item<?php echo $page<=1?' disabled':''; ?>">
                    <a class="page-link" href="?p=<?php echo max(1,$page-1); ?>&<?php echo $qs; ?>">‹</a>
                </li>
                <?php
                $start_p = max(1, $page-2);
                $end_p = min($total_pages, $page+2);
                if ($start_p > 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                for ($i = $start_p; $i <= $end_p; $i++):
                ?>
                <li class="page-item<?php echo $i==$page?' active':''; ?>">
                    <a class="page-link" href="?p=<?php echo $i; ?>&<?php echo $qs; ?>"><?php echo $i; ?></a>
                </li>
                <?php endfor;
                if ($end_p < $total_pages) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; ?>
                <li class="page-item<?php echo $page>=$total_pages?' disabled':''; ?>">
                    <a class="page-link" href="?p=<?php echo min($total_pages,$page+1); ?>&<?php echo $qs; ?>">›</a>
                </li>
                <li class="page-item<?php echo $page>=$total_pages?' disabled':''; ?>">
                    <a class="page-link" href="?p=<?php echo $total_pages; ?>&<?php echo $qs; ?>">»</a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>

        <div class="custom-tooltip-container">
            📈 Kész (OK): <span class="text-success fw-bold" id="stats-ok">0</span> | Hátravan: <span class="text-warning fw-bold" id="stats-unchecked">0</span> <span class="info-dot">ℹ</span>
            <div class="custom-tooltip-text">
                <div class="fw-bold mb-1 border-bottom border-secondary pb-1 text-center">Statisztika (Látható tételek)</div>
                <div class="stat-row"><span class="text-success">[OK]:</span> <span class="fw-bold" id="bubble-ok">0</span></div>
                <div class="stat-row"><span class="text-light">[Feldolgozatlan]:</span> <span class="fw-bold" id="bubble-unchecked">0</span></div>
                <div class="stat-row"><span class="text-danger">[HIÁNY]:</span> <span class="fw-bold" id="bubble-hiany">0</span></div>
                <div class="stat-row"><span class="text-warning">[ELTÉRÉS]:</span> <span class="fw-bold" id="bubble-elteres">0</span></div>
                <div class="stat-row"><span class="text-info">[IDŐ CSÚSZÁS]:</span> <span class="fw-bold" id="bubble-csuszas">0</span></div>
                <div class="stat-row"><span class="text-primary">[ÖSSZEVONT]:</span> <span class="fw-bold" id="bubble-osszevont">0</span></div>
            </div>
        </div>
    </div>
    <div class="text-muted" style="font-size: 11px;">Minden Bankos Egyeztető Modul v3.6</div>
</div>

<!-- KOMBINÁLT ÖSSZEHASONLÍTÓ MODAL -->
<div class="modal fade" id="combinedDetailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title">🏦 Banki és 🏛 OTS Könyvelési Részletek (Összehasonlítás)</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div class="row g-0">
          <!-- Bank Side -->
          <div class="col-md-6 p-4 border-end">
            <h6 class="text-primary mb-3 border-bottom pb-2"><strong>Banki Adatok</strong></h6>
            <table class="table table-sm table-striped table-bordered">
              <tr><th style="width: 35%;">Gyülekezet Neve:</th><td id="cb_church_name">-</td></tr>
              <tr><th>Dátum:</th><td id="cb_date">-</td></tr>
              <tr><th>Összeg:</th><td id="cb_amount" class="fw-bold">-</td></tr>
              <tr><th>Közlemény:</th><td id="cb_desc">-</td></tr>
              <tr class="table-info"><th>Kezdeményező neve:</th><td id="cb_init_name">-</td></tr>
              <tr class="table-info"><th>Kezdeményező számla:</th><td id="cb_init_acc">-</td></tr>
              <tr class="table-light"><th>Kedvezményezett neve:</th><td id="cb_ben_name">-</td></tr>
              <tr class="table-light"><th>Kedvezményezett számla:</th><td id="cb_ben_acc">-</td></tr>
              <tr><th>Tranzakció ID:</th><td id="cb_ext_ref">-</td></tr>
            </table>
          </div>
          <!-- OTS Side -->
          <div class="col-md-6 p-4 bg-light">
            <h6 class="text-secondary mb-3 border-bottom pb-2"><strong>OTS Könyvelési Adatok</strong></h6>
            <div id="c_ots_content">
                <!-- Dinamikusan generált táblázat helye -->
                <div class="alert alert-info mt-3 mb-0 text-center py-2"><small>További részletekért keresd meg a fenti bizonylatszámot az OTS rendszerben.</small></div>
            </div>
            <div id="c_ots_empty" class="alert alert-warning text-center mt-4" style="display:none;">
                <strong>[Feldolgozatlan]</strong><br>Ehhez a banki tételhez még nem lett párosítva OTS könyvelési adat!
            </div>
          </div>
        </div>
        <div class="row g-0 border-top bg-light p-2">
            <div class="col-12 text-center text-muted">
                <small><strong>Megjegyzés rovat (Auto-infó):</strong> <span id="c_comment" class="fst-italic">-</span></small>
            </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- AUTO MATCH MODAL -->
<div class="modal fade" id="autoMatchModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title">🤖 Utólagos Automatikus Párosítás</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-3">Ezzel a funkcióval a még <strong>[Feldolgozatlan]</strong> tételekre kereshetsz rá az OTS adatbázisban, hogy neked már csak a problémás tételekkel kelljen foglalkoznod.</p>
        
        <div class="form-check mb-3 p-3 bg-light border rounded">
          <input class="form-check-input" type="radio" name="matchMode" id="modeProgressive" value="progressive" checked>
          <label class="form-check-label fw-bold" for="modeProgressive">
            Progresszív mód (Ajánlott)
            <span id="last-progressive" class="ms-2 badge bg-white text-dark border fw-normal" style="display:none; font-size: 10px;">Legutóbb: -</span>
          </label>
          <div class="text-muted small mt-1">Először 100%-os (0 nap) egyezéseket keres (ezek írásvédelmet kapnak). Utána a maradékot próbálja 3, 6, 12, 35, majd 60 nap csúszásos toleranciával, a végén pedig egy <strong>intelligens szöveges keresővel (Nagy összegek, MVM, Közlemény, stb.)</strong> párosítani.</div>
        </div>
        
        <div class="form-check mb-2 p-3 bg-light border rounded">
          <input class="form-check-input" type="radio" name="matchMode" id="modeCustom" value="custom">
          <label class="form-check-label fw-bold" for="modeCustom">
            Egyedi nap tolerancia (Engedmény)
            <span id="last-custom" class="ms-2 badge bg-white text-dark border fw-normal" style="display:none; font-size: 10px;">Legutóbb: -</span>
          </label>
          <div class="d-flex align-items-center mt-2">
            <input type="number" id="customDays" class="form-control form-control-sm me-2" value="5" min="0" max="30" style="width: 70px;"> <span class="small text-muted">nap csúszás engedélyezése</span>
          </div>
        </div>
        
        <div class="form-check mb-2 p-3 bg-light border rounded">
          <input class="form-check-input" type="radio" name="matchMode" id="modeSearch" value="search">
          <label class="form-check-label fw-bold" for="modeSearch">
            🔎 Kézi nyomozás konkrét összegre az OTS-ben
            <span id="last-search" class="ms-2 badge bg-white text-dark border fw-normal" style="display:none; font-size: 10px;">Legutóbb: -</span>
          </label>
          <div class="d-flex align-items-center mt-2">
            <input type="number" id="searchAmount" class="form-control form-control-sm me-2" placeholder="pl. 4986" style="width: 120px;"> <span class="small text-muted">Ft keresése az adatbázisban</span>
          </div>
        </div>
      </div>
      <div class="modal-footer d-flex justify-content-between align-items-center">
        <div id="autoMatchLoader" class="text-success fw-bold" style="display:none; font-size:14px;">
            <span class="spinner-border spinner-border-sm me-1"></span> Keresés folyamatban...
            <span id="autoMatchTimer" class="ms-2 badge bg-secondary">0.0s</span>
        </div>
        <div>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mégse</button>
            <button type="button" class="btn btn-success fw-bold" onclick="runAutoMatch()" id="btnRunMatch">🚀 Futtatás</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
var CSRF_TOKEN = '<?php echo $_SESSION['csrf_token']; ?>';
document.addEventListener("DOMContentLoaded", function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.forEach(function (tooltipTriggerEl) {
        new bootstrap.Tooltip(tooltipTriggerEl);
    });
    frissitSzamlalot();
});

function filterTable() {
    const table = document.getElementById("sortableTable");
    const inputs = table.querySelectorAll(".filter-input");
    const rows = table.querySelectorAll("tbody .data-row");
    
    const selectedChurch = document.getElementById("churchSelect").value.trim().toLowerCase();

    rows.forEach(row => {
        let shouldShow = true;

        if (selectedChurch !== "") {
            const rowChurch = (row.getAttribute("data-church") || "").trim().toLowerCase();
            const rowId = (row.getAttribute("data-church-id") || "").trim().toLowerCase();
            // Ha a választott gyülekezet neve vagy ID-ja nem tartalmazza a keresett szöveget
            if (!rowChurch.includes(selectedChurch) && !rowId.includes(selectedChurch)) {
                shouldShow = false;
            }
        }

        if (shouldShow) {
            // A colIndex most már elcsúszott az új ADMIN oszlop miatt, figyelni kell az eltolásra!
            inputs.forEach((input, inputIdx) => {
                const query = input.value.trim();
                if (query === "") return;

                let colIndex = inputIdx; // Mivel minden oszlopnak van inputja, az indexek stimmelnek
                let cellValue = "";
                if (colIndex === 8) { // Státusz oszlop (select)
                    const select = row.children[colIndex].querySelector("select");
                    cellValue = select.options[select.selectedIndex].text;
                } else if (colIndex === 9) { // Megjegyzés oszlop (input)
                    cellValue = row.children[colIndex].querySelector("input").value;
                } else {
                    cellValue = row.children[colIndex].textContent || ""; // textContent sokkal gyorsabb, mint az innerText!
                }

                if (colIndex === 2 || colIndex === 7) { // Összeg oszlopok
                    let numValue = parseFloat(cellValue.replace(/[^0-9.-]/g, ''));
                    if (isNaN(numValue)) numValue = 0;

                    if (query === "-") {
                        if (numValue >= 0) shouldShow = false;
                    } 
                    else if (query === "+") {
                        if (numValue <= 0) shouldShow = false;
                    } 
                    else {
                        const parts = query.split(/\s+/);

                        if (parts.length === 2) {
                            let val1 = parseFloat(parts[0]);
                            let val2 = parseFloat(parts[1]);

                            if (!isNaN(val1) && !isNaN(val2)) {
                                let min = Math.min(val1, val2);
                                let max = Math.max(val1, val2);

                                if (numValue < min || numValue > max) shouldShow = false;
                            } else {
                                shouldShow = false;
                            }
                        } else {
                            let cleanQuery = query.replace(/[^0-9.-]/g, '');
                            if (cleanQuery !== "") {
                                let queryNum = parseFloat(cleanQuery);
                                if (!isNaN(queryNum) && numValue !== queryNum) {
                                    shouldShow = false;
                                }
                            }
                        }
                    }
                } 
                else {
                    let searchableText = cellValue.toLowerCase();
                    
                    const partnerName = row.children[colIndex].getAttribute("data-partner");
                    if (partnerName) searchableText += " " + partnerName.toLowerCase();
                    
                    const refData = row.children[colIndex].getAttribute("data-ref");
                    if (refData) searchableText += " " + refData.toLowerCase();
                    
                    if (!searchableText.includes(query.toLowerCase())) shouldShow = false;
                }
            });
        }

        row.style.display = shouldShow ? "" : "none";
    });

    frissitSzamlalot();
}

function updateRowStatusData(rowId, newStatus) {
    document.getElementById('row-' + rowId).setAttribute('data-status', newStatus);
    filterTable();
}

function frissitSzamlalot() {
    const rows = document.querySelectorAll('.data-row');
    const totalRows = rows.length;
    let visibleRows = 0;
    let stats = { 'UNCHECKED': 0, 'OK': 0, 'HIANY': 0, 'ELTERES': 0, 'CSUSZAS': 0, 'OSSZEVONT': 0 };

    rows.forEach(row => {
        if (row.style.display !== 'none') {
            visibleRows++;
            let s = row.getAttribute('data-status');
            if (stats[s] !== undefined) stats[s]++;
        }
    });

    document.getElementById('counter-visible').innerText = visibleRows;
    document.getElementById('counter-total').innerText = totalRows;

    document.getElementById('stats-ok').innerText = stats['OK'];
    document.getElementById('stats-unchecked').innerText = stats['UNCHECKED'];
    
    document.getElementById('bubble-ok').innerText = stats['OK'];
    document.getElementById('bubble-unchecked').innerText = stats['UNCHECKED'];
    document.getElementById('bubble-hiany').innerText = stats['HIANY'];
    document.getElementById('bubble-elteres').innerText = stats['ELTERES'];
    document.getElementById('bubble-csuszas').innerText = stats['CSUSZAS'];
    document.getElementById('bubble-osszevont').innerText = stats['OSSZEVONT'];
}

let currentSortCol = -1; let sortAscending = true;
function sortTable(colIndex, type) {
    const table = document.getElementById("sortableTable"); const tbody = table.querySelector("tbody"); const rows = Array.from(tbody.querySelectorAll(".data-row"));
    const headers = table.querySelectorAll(".sub-header th"); headers.forEach(h => h.classList.remove("sort-asc", "sort-desc"));
    if (currentSortCol === colIndex) { sortAscending = !sortAscending; } else { sortAscending = true; currentSortCol = colIndex; }
    headers[colIndex].classList.add(sortAscending ? "sort-asc" : "sort-desc");
    rows.sort((a, b) => {
        let valA = a.children[colIndex].getAttribute("data-val") || a.children[colIndex].innerText || ""; 
        let valB = b.children[colIndex].getAttribute("data-val") || b.children[colIndex].innerText || "";
        if (type === 'amount') { return sortAscending ? parseFloat(valA) - parseFloat(valB) : parseFloat(valB) - parseFloat(valA); }
        else if (type === 'date') { return sortAscending ? new Date(valA) - new Date(valB) : new Date(valB) - new Date(valA); }
        else { return sortAscending ? valA.localeCompare(valB) : valB.localeCompare(valA); }
    });
    rows.forEach(row => tbody.appendChild(row));
    frissitSzamlalot();
}

function saveData(rowId) {
    var statusValue = document.getElementById('status-' + rowId).value;
    var commentValue = document.getElementById('comment-' + rowId).value;
    var docInput = document.getElementById('manual-doc-' + rowId);
    var docValue = docInput ? docInput.value : '';
    
    var data = new FormData(); data.append('action', 'save'); data.append('id', rowId); data.append('status', statusValue); data.append('comment', commentValue); data.append('ots_doc', docValue); data.append('csrf_token', CSRF_TOKEN);
    fetch('index.php', { method: 'POST', body: data }).then(response => response.text()).then(text => { 
        if(text.trim() === "OK") { 
            if (statusValue === 'UNCHECKED' || docValue !== '') { window.location.reload(); } else { filterTable(); }
        } 
    });
}

function mutatKombinaltReszleteket(adatok) {
    try {
    // Bank adatok
    document.getElementById('cb_church_name').textContent = adatok.church_name ? adatok.church_name : '-';
    document.getElementById('cb_date').textContent = adatok.bank_date;
    let bankAmtEl = document.getElementById('cb_amount');
    bankAmtEl.textContent = Number(adatok.bank_amount).toLocaleString('hu-HU') + ' Ft';
    bankAmtEl.className = adatok.bank_amount < 0 ? 'fw-bold text-danger' : 'fw-bold text-success';
    
    document.getElementById('cb_desc').textContent = adatok.bank_desc ? adatok.bank_desc : '-';
    document.getElementById('cb_init_name').textContent = adatok.bank_init_name ? adatok.bank_init_name : '-';
    document.getElementById('cb_init_acc').textContent = adatok.bank_init_acc ? adatok.bank_init_acc : '-';
    document.getElementById('cb_ben_name').textContent = adatok.bank_ben_name ? adatok.bank_ben_name : '-';
    document.getElementById('cb_ben_acc').textContent = adatok.bank_ben_acc ? adatok.bank_ben_acc : '-';
    document.getElementById('cb_ext_ref').textContent = adatok.bank_ext_ref ? adatok.bank_ext_ref : '-';

    // OTS adatok
    if (adatok.ots_amount || adatok.ots_doc) {
        const otsContainer = document.getElementById('c_ots_content');
        otsContainer.style.display = 'block';
        document.getElementById('c_ots_empty').style.display = 'none';
        
        let tableHtml = '<table class="table table-sm table-striped table-bordered">';
        const labels = {
            'church_name': 'Gyülekezet',
            'ots_date': 'Dátum',
            'ots_amount': 'Összeg',
            'ots_desc_full': 'Partner / Megjegyzés',
            'ots_doc': 'Bizonylatszám',
            'ots_decision': 'Határozati szám',
            'ots_type': 'Típus',
            'ots_editor': 'Rögzítette',
            'ots_edit_date': 'Rögzítés ideje',
            'ots_via_bank': 'VIA Bank kód',
            'ots_record_id': 'Record ID'
        };

        // Első 4 sor párhuzamosítása
        const parallelOrder = ['church_name', 'ots_date', 'ots_amount', 'ots_desc_full'];
        parallelOrder.forEach(key => {
            let val = adatok[key] || '-';
            let label = labels[key] || key;
            let extra = "";
            let style = "";

            if (key === 'ots_amount') {
                val = Number(val).toLocaleString('hu-HU') + ' Ft';
                style = val.includes('-') ? 'class="fw-bold text-danger"' : 'class="fw-bold text-success"';
            }

            if (key === 'ots_date' && adatok.bank_date && adatok.ots_date) {
                let bDate = new Date(adatok.bank_date + "T00:00:00Z");
                let oDate = new Date(adatok.ots_date + "T00:00:00Z");
                if (!isNaN(bDate) && !isNaN(oDate)) {
                    let diffDays = Math.round((oDate - bDate) / (1000 * 60 * 60 * 24));
                    let badgeClass = diffDays === 0 ? 'bg-success' : 'bg-warning text-dark';
                    let badgeText = diffDays === 0 ? '0 nap' : Math.abs(diffDays) + ' nap eltérés';
                    extra = ` <span class="badge ${badgeClass}">${badgeText}</span>`;
                }
            }
            tableHtml += `<tr><th style="width: 35%;">${label}:</th><td ${style}>${val}${extra}</td></tr>`;
        });

        // Minden egyéb OTS adat listázása
        Object.keys(adatok).forEach(key => {
            if (key.startsWith('ots_') && !parallelOrder.includes(key)) {
                let val = adatok[key] || '-';
                let label = labels[key] || key.replace('ots_', '').replace('_', ' ');
                tableHtml += `<tr><th>${label}:</th><td>${val}</td></tr>`;
            }
        });
        tableHtml += '</table>';
        otsContainer.innerHTML = tableHtml + '<div class="alert alert-info mt-3 mb-0 text-center py-2"><small>További részletekért keresd meg a fenti bizonylatszámot az OTS rendszerben.</small></div>';
    } else {
        document.getElementById('c_ots_content').style.display = 'none';
        document.getElementById('c_ots_empty').style.display = 'block';
    }
    
    document.getElementById('c_comment').textContent = adatok.comment ? adatok.comment : '-';
    new bootstrap.Modal(document.getElementById('combinedDetailsModal')).show();
    } catch (e) { console.error("Hiba a részletek megjelenítésekor:", e); }
}

function runAutoMatch() {
    const mode = document.querySelector('input[name="matchMode"]:checked').value;
    const customDays = document.getElementById('customDays').value;

    const btn = document.getElementById('btnRunMatch');
    const loader = document.getElementById('autoMatchLoader');
    const timerEl = document.getElementById('autoMatchTimer');
    
    btn.disabled = true; 
    loader.style.display = 'block';
    timerEl.innerText = "0.0s";

    let startTime = Date.now();
    let timerInterval = setInterval(() => {
        timerEl.innerText = ((Date.now() - startTime) / 1000).toFixed(1) + 's';
    }, 100);

    const finishTimer = () => {
        const finalTime = timerEl.innerText;
        clearInterval(timerInterval);
        btn.disabled = false; 
        loader.style.display = 'none';
        
        const targetId = 'last-' + mode;
        const targetEl = document.getElementById(targetId);
        if (targetEl) {
            targetEl.innerText = 'Legutóbb: ' + finalTime;
            targetEl.style.display = 'inline-block';
        }
    };

    if (mode === 'search') {
        const amount = document.getElementById('searchAmount').value;
        if (!amount) { alert('Kérlek add meg a keresett összeget!'); finishTimer(); return; }
        
        const data = new FormData();
        data.append('action', 'search_ots_amount');
        data.append('amount', amount);
        data.append('csrf_token', CSRF_TOKEN);
        
        fetch('index.php', { method: 'POST', body: data })
        .then(res => res.json())
        .then(result => {
            if (result.status === 'OK') {
                if (result.data.length === 0) {
                    alert(`Nincs találat az OTS-ben a(z) ${amount} Ft összegre (Bankos tételként).`);
                } else {
                    let msg = `🔎 TALÁLATOK A(Z) ${amount} FT ÖSSZEGRE:\n\n`;
                    result.data.forEach(r => {
                        let type = r.VIA_BANK != 0 ? '🏦 BANK' : '💵 KÉSZPÉNZ';
                        msg += `[${type}] 🏛 ${r.church_name} | 📅 ${r.ots_date} | 📄 Biz: ${r.ots_doc}\n`;
                        msg += `📝 ${r.ots_desc}\n\n`;
                    });
                    alert(msg);
                }
            } else { alert('Hiba a keresés során!'); }
        })
        .finally(() => { finishTimer(); });
        return;
    }
    
    const data = new FormData();
    data.append('action', 'auto_match'); data.append('match_mode', mode); data.append('custom_days', customDays); data.append('csrf_token', CSRF_TOKEN);
    
    fetch('index.php', { method: 'POST', body: data })
    .then(res => res.json())
    .then(result => {
        if (result.status === 'OK') {
            let msg = `🎉 Kész! Összesen ${result.matched} új tételt sikerült automatikusan párosítani az OTS-el.\n\n`;
            if (mode === 'progressive') {
                msg += `Részletek:\n`;
                msg += `🔒 0 napos (Írásvédett): ${result.details.pass_0} db\n`;
                msg += `⏱️ 3 napos: ${result.details.pass_3} db\n`;
                msg += `⏱️ 6 napos: ${result.details.pass_6} db\n`;
                msg += `⏱️ 12 napos: ${result.details.pass_12} db`;
                msg += `\n⏱️ 35 napos: ${result.details.pass_35} db`;
                msg += `\n⏱️ 60 napos: ${result.details.pass_60} db`;
                msg += `\n🔎 Szöveges (Név/Közlemény): ${result.details.pass_text} db`;
            } else {
                msg += `Az egyedi ${customDays} napos ráhagyással: ${result.details.custom} db.`;
            }
            alert(msg);
            window.location.reload(); // Az oldal újratöltésével frissülnek az új adatok és az írásvédelem
        } else {
            alert('Hiba történt a futtatás során!');
        }
    })
    .finally(() => { finishTimer(); });
}

function bulkApproveCsuszas() {
    const tableContainer = document.querySelector('.table-responsive-scroll');
    if (!tableContainer) return;
    
    const containerRect = tableContainer.getBoundingClientRect();
    const headerHeight = tableContainer.querySelector('thead').getBoundingClientRect().height || 60;
    const visibleTop = containerRect.top + headerHeight; // A rögzített fejléc alatti ténylegesen látható rész
    
    const rows = document.querySelectorAll('.data-row');
    let idsToApprove = [];
    
    rows.forEach(row => {
        if (row.style.display !== 'none' && row.getAttribute('data-status') === 'CSUSZAS') {
            const rect = row.getBoundingClientRect();
            // Csak akkor vesszük fel, ha a sor fizikailag látszik az éppen görgetett képernyőrészen
            if (rect.top <= containerRect.bottom && rect.bottom >= visibleTop) {
                const id = row.id.replace('row-', '');
                idsToApprove.push(id);
            }
        }
    });
    
    if (idsToApprove.length === 0) {
        alert('A jelenleg a képernyőn (viewportban) LÁTHATÓ sorok között nincs [IDŐ CSÚSZÁS] állapotú tétel!');
        return;
    }
    
    if (!confirm(`Biztosan jóváhagysz (OK-ra állítasz) ${idsToApprove.length} db, JELENLEG A KÉPERNYŐN LÁTHATÓ [IDŐ CSÚSZÁS] tételt?`)) {
        return;
    }
    
    const data = new FormData();
    data.append('action', 'bulk_approve');
    data.append('ids', JSON.stringify(idsToApprove));
    data.append('csrf_token', CSRF_TOKEN);
    
    fetch('index.php', { method: 'POST', body: data })
    .then(res => res.json())
    .then(result => {
        if (result.status === 'OK') {
            window.location.reload();
        } else { alert('Hiba történt a tömeges jóváhagyás során!'); }
    })
    .catch(err => alert('Hiba történt a kérés során: ' + err));
}

function exportTableToCSV() {
    let csv = [];
    csv.push('\uFEFF'); // UTF-8 BOM kódolás a hibátlan magyar ékezetekhez az Excelben

    const rows = document.querySelectorAll("#sortableTable tr");
    
    for (let i = 0; i < rows.length; i++) {
        let row = rows[i];
        
        // Kihagyjuk a rejtett sorokat és a dupla fejléc legfelső sorát
        if (row.style.display === 'none' || row.classList.contains('main-header')) continue;
        
        let rowData = [];
        let cols = row.querySelectorAll("td, th");
        
        // Az utolsó oszlopot (Akció gombok) kihagyjuk
        for (let j = 0; j < cols.length - 1; j++) {
            let col = cols[j];
            let text = "";
            
            if (row.classList.contains('data-row')) {
                if (j === 4) { let input = col.querySelector('input'); text = input ? input.value : col.innerText; }
                else if (j === 7) { let select = col.querySelector('select'); text = select ? select.options[select.selectedIndex].text : col.innerText; }
                else if (j === 8) { let input = col.querySelector('input'); text = input ? input.value : col.innerText; }
                else { text = col.innerText; }
            } else if (row.classList.contains('sub-header')) {
                let clone = col.cloneNode(true);
                clone.querySelectorAll('input, span, select').forEach(el => el.remove());
                text = clone.innerText.trim();
            }
            
            text = text.replace(/"/g, '""').trim(); // Excel idézőjel escape
            rowData.push('"' + text + '"');
        }
        if(rowData.length > 0) csv.push(rowData.join(";"));
    }

    let csvFile = new Blob([csv.join("\n")], {type: "text/csv;charset=utf-8;"});
    let downloadLink = document.createElement("a");
    downloadLink.download = "Bankegyezteto_Export_" + new Date().toISOString().slice(0,10) + ".csv";
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

// --- SESSION COUNTDOWN ---
var sessionRemaining = <?php echo $session_remaining; ?>;
var sessionWarningShown = false;

function formatTime(sec) {
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return m + ' perc ' + s + ' mp';
}

function checkSession() {
    sessionRemaining--;
    if (sessionRemaining <= 60 && !sessionWarningShown) {
        sessionWarningShown = true;
        document.getElementById('sessionWarnTime').textContent = formatTime(sessionRemaining);
        new bootstrap.Modal(document.getElementById('sessionWarnModal')).show();
    }
    if (sessionRemaining <= 0) {
        window.location.href = 'login.php';
    }
}

function extendSession() {
    var data = new FormData();
    data.append('action', 'keepalive');
    data.append('csrf_token', CSRF_TOKEN);
    fetch('index.php', { method: 'POST', body: data })
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
</script>

<!-- SESSION WARNING MODAL -->
<div class="modal fade" id="sessionWarnModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content border-warning">
      <div class="modal-header bg-warning text-dark">
        <h6 class="modal-title">⏰ Session lejár</h6>
      </div>
      <div class="modal-body text-center">
        <p class="mb-2">A munkamenet <strong>1 percen belül</strong> lejár!</p>
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