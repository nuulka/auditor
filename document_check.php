<?php
ini_set('display_errors', 0);
error_reporting(0);
require_once __DIR__ . '/../ots/constant.php';
if (session_status() != PHP_SESSION_ACTIVE) { session_start(); }
$_SESSION[GN_LAST_ACTIVE] = time();
require_once __DIR__ . '/../ots/session_handler.php';
if (!isset($_SESSION[GC_LOGIN_COOKIE])) { header('Location: login.php'); exit; }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
define('REVIZOR_SESSION_DURATION', 1200);
if (!isset($_SESSION['revizor_expires_at'])) { $_SESSION['revizor_expires_at'] = time() + REVIZOR_SESSION_DURATION; }
if (time() >= $_SESSION['revizor_expires_at']) { session_destroy(); header('Location: login.php'); exit; }
$session_remaining = $_SESSION['revizor_expires_at'] - time();
$conn = new mysqli('localhost', 'root', '', 'revizor_db');
if ($conn->connect_error) { die("Database connection failed"); }
$conn->set_charset("utf8mb4");

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
// ensure user context built
build_user_context_from_ots();

$church_id = isset($_GET['church_id']) ? intval($_GET['church_id']) : 0;
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// AJAX: audit checklist mentése
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_audit') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['status' => 'ERROR', 'message' => 'CSRF token mismatch']); exit;
    }
    $bank_rec_id = intval($_POST['bank_reconciliation_id'] ?? 0);
    if ($bank_rec_id <= 0) { echo json_encode(['status' => 'ERROR', 'message' => 'Hiányzó ID']); exit; }
    // scope check - use prepared
    $stmt = $conn->prepare("SELECT church_id FROM bank_reconciliation WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $bank_rec_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc() ?? null;
        require_church_access(intval($row['church_id'] ?? 0));
    } else {
        require_church_access(0); // will fail
    }
    $fields = ['cash_voucher_ok','date_filled','amount_ok','description_ok','signature_treasurer','signature_receiver','signature_authorizer','invoice_ok','tithe_card_ok','receipt_number_ok','decision_number_ok','fund_designation_ok','supporting_doc_ok'];
    $set_parts = [];
    foreach ($fields as $f) {
        $v = isset($_POST[$f]) && $_POST[$f] === '1' ? 1 : 0;
        $set_parts[] = "$f = $v";
    }
    $inspector = $conn->real_escape_string($_POST['inspector_name'] ?? $_SESSION[GC_USER_FULL_NAME] ?? 'Ismeretlen');
    $notes = $conn->real_escape_string($_POST['notes'] ?? '');
    $checked_at = date('Y-m-d H:i:s');
    $vals = [];
    foreach ($fields as $f) {
        $vals[] = isset($_POST[$f]) && $_POST[$f] === '1' ? 1 : 0;
    }
    $sql = "INSERT INTO audit_checklist (bank_reconciliation_id, inspector_name, checked_at, " . implode(',', $fields) . ", notes)
            VALUES ($bank_rec_id, '$inspector', '$checked_at', " . implode(',', $vals) . ", '$notes')
            ON DUPLICATE KEY UPDATE inspector_name='$inspector', checked_at='$checked_at', notes='$notes', " . implode(',', $set_parts);
    $conn->query($sql);
    echo json_encode(['status' => 'OK', 'message' => 'Ellenőrzési lista mentve.']);
    exit;
}

// Gyülekezet lista
$churches = [];
$c_res = $conn->query("SELECT DISTINCT br.church_id, c.name FROM bank_reconciliation br LEFT JOIN ots.churches c ON br.church_id = c.id WHERE br.church_id > 0 ORDER BY c.name");
if ($c_res) { while ($c = $c_res->fetch_assoc()) { $churches[] = $c; } }

// Lekérdezés
$where = ["br.church_id > 0"];
if ($church_id > 0) { $where[] = 'br.church_id = ' . $church_id; }
if ($date_from) { $where[] = "br.bank_date >= '$date_from'"; }
if ($date_to) { $where[] = "br.bank_date <= '$date_to'"; }
$where_sql = implode(' AND ', $where);

$sql = "SELECT br.*, c.name AS church_name,
               ac.id AS audit_id, ac.inspector_name, ac.checked_at,
               ac.cash_voucher_ok, ac.date_filled, ac.amount_ok, ac.description_ok,
               ac.signature_treasurer, ac.signature_receiver, ac.signature_authorizer,
               ac.invoice_ok, ac.tithe_card_ok, ac.receipt_number_ok, ac.decision_number_ok,
               ac.fund_designation_ok, ac.supporting_doc_ok, ac.notes
        FROM bank_reconciliation br
        LEFT JOIN ots.churches c ON br.church_id = c.id
        LEFT JOIN audit_checklist ac ON br.id = ac.bank_reconciliation_id
        WHERE $where_sql
        ORDER BY br.bank_date DESC
        LIMIT 500";
$result = $conn->query($sql);
$rows = [];
$total_count = 0;
$checked_count = 0;
$field_counts = [];
if ($result) {
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
        $total_count++;
        if ($r['audit_id']) { $checked_count++; }
    }
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <title>Revizor Asszisztens 1.0 – Bizonylat Ellenőrzés</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; padding: 15px; font-size: 14px; }
        .card { box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .stat-card { text-align: center; padding: 15px; border-radius: 8px; }
        .stat-card h3 { margin: 0; font-size: 28px; font-weight: 700; }
        .stat-card small { color: #6c757d; }
        .checklist-item { padding: 6px 0; border-bottom: 1px solid #eee; }
        .checklist-item:last-child { border-bottom: none; }
        .progress-thin { height: 6px; margin-top: 4px; }
        .audit-yes { color: #198754; }
        .audit-no { color: #dc3545; }
        .audit-na { color: #6c757d; }
        .summary-badge { font-size: 11px; padding: 2px 6px; }
        .sort-asc::after { content: " ▲"; font-size: 10px; }
        .sort-desc::after { content: " ▼"; font-size: 10px; }
        th[onclick] { cursor: pointer; user-select: none; }
        th[onclick]:hover { background: #d0d5dd !important; }
    </style>
</head>
<body>
<div class="container-fluid">
    <!-- Fejléc -->
    <div class="d-flex justify-content-between align-items-center mb-3 px-3 py-2 bg-white rounded border shadow-sm">
        <div class="d-flex align-items-center gap-2">
            <span class="fw-bold">🕵️ Revizor Asszisztens 1.0</span>
            <span class="text-muted mx-1">|</span>
            <span class="text-muted">Bizonylat Ellenőrzés</span>
        </div>
        <div class="d-flex align-items-center gap-1">
            <a href="index.php" class="btn btn-outline-secondary btn-sm">🏠 Kezdőlap</a>
            <a href="help.php" class="btn btn-outline-primary btn-sm">❓ Súgó</a>
            <a href="logout.php" class="btn btn-outline-danger btn-sm ms-1">Kilépés</a>
        </div>
    </div>

    <!-- Statisztika -->
    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="stat-card bg-light border">
                <h3 class="text-primary"><?= $total_count ?></h3>
                <small>Összes tétel</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg-light border">
                <h3 class="text-success"><?= $checked_count ?></h3>
                <small>Ellenőrzött</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg-light border">
                <h3 class="<?= $total_count > 0 ? ($checked_count / $total_count * 100 >= 80 ? 'text-success' : 'text-warning') : 'text-muted' ?>">
                    <?= $total_count > 0 ? number_format($checked_count / $total_count * 100, 0) : 0 ?>%
                </h3>
                <small>Készültség</small>
                <div class="progress progress-thin"><div class="progress-bar <?= $checked_count / max(1, $total_count) * 100 >= 80 ? 'bg-success' : 'bg-warning' ?>" style="width:<?= $total_count > 0 ? ($checked_count / $total_count * 100) : 0 ?>%"></div></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg-light border">
                <h3 class="text-muted"><?= $total_count - $checked_count ?></h3>
                <small>Nem ellenőrzött</small>
            </div>
        </div>
    </div>

    <!-- Szűrők -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="small mb-0">Gyülekezet</label>
                    <select name="church_id" class="form-select form-select-sm" style="width:200px;">
                        <option value="0">Összes</option>
                        <?php foreach ($churches as $c): ?>
                        <option value="<?= $c['church_id'] ?>" <?= $church_id === (int)$c['church_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name'] ?? '#' . $c['church_id']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="small mb-0">Dátum tól</label>
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?= $date_from ?>" style="width:150px;">
                </div>
                <div class="col-auto">
                    <label class="small mb-0">Dátum ig</label>
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?= $date_to ?>" style="width:150px;">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">🔎 Szűrés</button>
                    <a href="document_check.php" class="btn btn-outline-secondary btn-sm">✕</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Táblázat -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-bordered mb-0" style="font-size:13px;">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th onclick="sortAuditTable(this)" data-sort-type="string">Gyülekezet</th>
                            <th onclick="sortAuditTable(this)" data-sort-type="date">Dátum</th>
                            <th onclick="sortAuditTable(this)" data-sort-type="number" style="text-align:right;">Összeg</th>
                            <th onclick="sortAuditTable(this)" data-sort-type="string">Közlemény</th>
                            <th onclick="sortAuditTable(this)" data-sort-type="string">Státusz</th>
                            <th onclick="sortAuditTable(this)" data-sort-type="string">Ellenőrizte</th>
                            <th onclick="sortAuditTable(this)" data-sort-type="date">Ell. idő</th>
                            <th onclick="sortAuditTable(this)" data-sort-type="number">Megfelelés</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $idx = 1; foreach ($rows as $r): 
                            $audit_fields = ['cash_voucher_ok','date_filled','amount_ok','description_ok','signature_treasurer','signature_receiver','signature_authorizer','invoice_ok','tithe_card_ok','receipt_number_ok','decision_number_ok','fund_designation_ok','supporting_doc_ok'];
                            $ok_count = 0;
                            $total_audit = count($audit_fields);
                            if ($r['audit_id']) {
                                foreach ($audit_fields as $f) { if ((int)$r[$f] === 1) $ok_count++; }
                            }
                        ?>
                        <tr class="<?= $r['audit_id'] ? ($ok_count === $total_audit ? 'table-success' : 'table-warning') : '' ?>">
                            <td><?= $idx++ ?></td>
                            <td><?= htmlspecialchars($r['church_name'] ?? '-') ?></td>
                            <td><?= $r['bank_date'] ?></td>
                            <td style="text-align:right;" class="<?= (float)$r['bank_amount'] < 0 ? 'text-danger' : 'text-success' ?> fw-bold"><?= number_format((float)$r['bank_amount'], 0, ',', ' ') ?> Ft</td>
                            <td><?= htmlspecialchars(mb_substr($r['bank_desc'] ?? '-', 0, 60)) ?></td>
                            <td><span class="badge bg-<?= $r['status'] === 'OK' ? 'success' : ($r['status'] === 'UNCHECKED' ? 'secondary' : 'warning') ?>"><?= $r['status'] ?? 'UNCHECKED' ?></span></td>
                            <td><?= htmlspecialchars($r['inspector_name'] ?? '-') ?></td>
                            <td><?= $r['checked_at'] ? substr($r['checked_at'], 0, 10) : '-' ?></td>
                            <td>
                                <?php if ($r['audit_id']): ?>
                                    <span class="fw-bold <?= $ok_count === $total_audit ? 'text-success' : 'text-warning' ?>" data-sort-value="<?= $total_audit > 0 ? $ok_count / $total_audit : 0 ?>"><?= $ok_count ?>/<?= $total_audit ?></span>
                                    <div class="progress progress-thin"><div class="progress-bar <?= $ok_count === $total_audit ? 'bg-success' : 'bg-warning' ?>" style="width:<?= $total_audit > 0 ? ($ok_count / $total_audit * 100) : 0 ?>%"></div></div>
                                <?php else: ?>
                                    <span class="text-muted" data-sort-value="-1">-</span>
                                <?php endif; ?>
                            </td>
                            <td><button class="btn btn-outline-primary btn-sm py-0 px-1" onclick="openAudit(<?= $r['id'] ?>)" title="Ellenőrzés">🔍</button></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rows)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-3">Nincs találat</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Audit modal -->
<div class="modal fade" id="auditModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h6 class="modal-title">📋 Ellenőrző lista</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="auditBankInfo" class="mb-3 p-2 bg-light rounded small"></div>
                <form id="auditForm">
                    <input type="hidden" name="bank_reconciliation_id" id="auditBankRecId">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-1">💰 Pénztári bizonylatok</h6>
                            <?php 
                            $left_items = [
                                'cash_voucher_ok' => 'Pénztárbizonylat rendben',
                                'date_filled' => 'Dátum kitöltve',
                                'amount_ok' => 'Összeg pontos',
                                'description_ok' => 'Megnevezés pontos',
                                'receipt_number_ok' => 'Bizonylatszám szerepel',
                                'decision_number_ok' => 'Határozati szám (ha releváns)',
                            ];
                            foreach ($left_items as $key => $label): ?>
                            <div class="checklist-item">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="<?= $key ?>" value="1" id="chk_<?= $key ?>">
                                    <label class="form-check-label" for="chk_<?= $key ?>"><?= $label ?></label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-1">✍️ Aláírások / Mellékletek</h6>
                            <?php 
                            $right_items = [
                                'signature_treasurer' => 'Pénztáros aláírás',
                                'signature_receiver' => 'Felvevő aláírása',
                                'signature_authorizer' => 'Utalványozó/engedélyező',
                                'invoice_ok' => 'Számla megvan',
                                'tithe_card_ok' => 'Tizedcédula megvan',
                                'fund_designation_ok' => 'Alap megjelölés helyes',
                                'supporting_doc_ok' => 'Egyéb melléklet (szerződés, stb.)',
                            ];
                            foreach ($right_items as $key => $label): ?>
                            <div class="checklist-item">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="<?= $key ?>" value="1" id="chk_<?= $key ?>">
                                    <label class="form-check-label" for="chk_<?= $key ?>"><?= $label ?></label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label small fw-bold">Ellenőr neve</label>
                        <input type="text" name="inspector_name" class="form-control form-control-sm" value="<?= htmlspecialchars($_SESSION[GC_USER_FULL_NAME] ?? '') ?>">
                    </div>
                    <div class="mt-2">
                        <label class="form-label small fw-bold">Megjegyzés</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <span id="auditSaveMsg" class="small me-2"></span>
                <button class="btn btn-success btn-sm" onclick="saveAudit()">💾 Mentés</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
var CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';
var auditModal = null;
document.addEventListener("DOMContentLoaded", function() {
    auditModal = new bootstrap.Modal(document.getElementById('auditModal'));
});

var _auditData = {};

function openAudit(bankRecId) {
    // Adatok betöltése a sorból
    var modal = document.getElementById('auditModal');
    document.getElementById('auditBankRecId').value = bankRecId;
    
    // AJAX: adatok lekérése
    fetch('document_check_get.php?bank_reconciliation_id=' + bankRecId)
    .then(function(r) { return r.json(); })
    .then(function(data) {
        document.getElementById('auditBankInfo').innerHTML = '<strong>' + data.church_name + '</strong> &middot; ' + data.bank_date + ' &middot; ' + Number(data.bank_amount).toLocaleString('hu-HU') + ' Ft<br><small>' + (data.bank_desc || '') + '</small>';
        
        // Checkboxes beállítása
        var fields = ['cash_voucher_ok','date_filled','amount_ok','description_ok','signature_treasurer','signature_receiver','signature_authorizer','invoice_ok','tithe_card_ok','receipt_number_ok','decision_number_ok','fund_designation_ok','supporting_doc_ok'];
        fields.forEach(function(f) {
            var cb = document.getElementById('chk_' + f);
            if (cb) cb.checked = data.audit && data.audit[f] == 1;
        });
        document.querySelector('[name="inspector_name"]').value = data.audit ? data.audit.inspector_name : '<?= htmlspecialchars($_SESSION[GC_USER_FULL_NAME] ?? '') ?>';
        document.querySelector('[name="notes"]').value = data.audit ? data.audit.notes : '';
        
        auditModal.show();
    })
    .catch(function() {
        alert('Hiba az adatok betöltésekor');
    });
}

function saveAudit() {
    var form = document.getElementById('auditForm');
    var data = new FormData(form);
    data.append('action', 'save_audit');
    data.append('csrf_token', CSRF_TOKEN);
    
    document.getElementById('auditSaveMsg').innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    fetch('document_check.php', { method: 'POST', body: data })
    .then(function(r) { return r.json(); })
    .then(function(result) {
        if (result.status === 'OK') {
            document.getElementById('auditSaveMsg').innerHTML = '<span class="text-success">✓ Mentve</span>';
            setTimeout(function() { window.location.reload(); }, 600);
        } else {
            document.getElementById('auditSaveMsg').innerHTML = '<span class="text-danger">✗ ' + result.message + '</span>';
        }
    })
    .catch(function() {
        document.getElementById('auditSaveMsg').innerHTML = '<span class="text-danger">✗ Hiba</span>';
    });
}

function sortAuditTable(th) {
    var tbody = th.closest('table').querySelector('tbody');
    var rows = Array.from(tbody.querySelectorAll('tr'));
    var col = Array.from(th.parentNode.children).indexOf(th);
    var type = th.getAttribute('data-sort-type') || 'string';
    var asc = !th.classList.contains('sort-asc');
    
    // Reset arrows
    th.parentNode.querySelectorAll('th').forEach(function(h) { h.classList.remove('sort-asc', 'sort-desc'); });
    th.classList.add(asc ? 'sort-asc' : 'sort-desc');
    
    // Exclude footer rows (e.g. "Nincs találat")
    var dataRows = rows.filter(function(r) { return r.querySelector('td'); });
    var nonData = rows.filter(function(r) { return !r.querySelector('td'); });
    
    dataRows.sort(function(a, b) {
        var ac = a.querySelectorAll('td')[col];
        var bc = b.querySelectorAll('td')[col];
        if (!ac || !bc) return 0;
        var va = ac.textContent.trim();
        var vb = bc.textContent.trim();
        
        // Check data-sort-value
        if (ac.querySelector('[data-sort-value]')) va = ac.querySelector('[data-sort-value]').getAttribute('data-sort-value');
        if (bc.querySelector('[data-sort-value]')) vb = bc.querySelector('[data-sort-value]').getAttribute('data-sort-value');
        
        if (type === 'number') {
            var na = parseFloat(va.replace(/[^\d,.-]/g, '').replace(',', '.')) || 0;
            var nb = parseFloat(vb.replace(/[^\d,.-]/g, '').replace(',', '.')) || 0;
            return asc ? na - nb : nb - na;
        } else if (type === 'date') {
            return asc ? va.localeCompare(vb) : vb.localeCompare(va);
        } else {
            return asc ? va.localeCompare(vb) : vb.localeCompare(va);
        }
    });
    
    tbody.innerHTML = '';
    dataRows.forEach(function(r) { tbody.appendChild(r); });
    nonData.forEach(function(r) { tbody.appendChild(r); });
}

// Szűrő beállítások mentése localStorage-ba
(function() {
    // Ha nincsenek GET paraméterek, töltsük a mentettekből
    if (window.location.search.length === 0) {
        var saved = localStorage.getItem('audit_filters');
        if (saved) {
            try {
                var f = JSON.parse(saved);
                var form = document.querySelector('form');
                if (form) {
                    if (f.church_id) form.church_id.value = f.church_id;
                    if (f.date_from) form.date_from.value = f.date_from;
                    if (f.date_to) form.date_to.value = f.date_to;
                }
            } catch(e) {}
        }
    }
    // Form submit-kor mentsük el
    document.querySelector('form')?.addEventListener('submit', function() {
        localStorage.setItem('audit_filters', JSON.stringify({
            church_id: this.church_id.value,
            date_from: this.date_from.value,
            date_to: this.date_to.value
        }));
    });
})();
</script>
</body>
</html>
