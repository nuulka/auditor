<?php
/**
 * Revizor AI Kombinatorikus Egyeztető — Modern UI
 * Kártya és táblázat nézet, szűrés, batch elfogadás
 */

ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'revizor_ai';

$success = null;
$error = null;



// POST: Elfogadás / elutasítás
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        $error = 'DB connection failed: ' . $conn->connect_error;
    } else {
        $conn->set_charset('utf8mb4');

        if ($action === 'accept' && isset($_POST['suggestion'])) {
            $s = json_decode($_POST['suggestion'], true);
            if ($s) {
                $updated = 0;
                if ($s['tipus'] === 'SOK_AZ_EGYHEZ') {
                    foreach ($s['banki_tetelek'] as $bt) {
                        $st = $conn->prepare("UPDATE bank_tabla SET status = 'egyeztetett' WHERE id = ?");
                        $st->bind_param('i', $bt['id']); $st->execute(); $updated += $st->affected_rows;
                    }
                    $st = $conn->prepare("UPDATE hazi_penztar SET status = 'egyeztetett' WHERE id = ?");
                    $st->bind_param('i', $s['hazi_penztar_tetel']['id']); $st->execute(); $updated += $st->affected_rows;
                } else {
                    $st = $conn->prepare("UPDATE bank_tabla SET status = 'egyeztetett' WHERE id = ?");
                    $st->bind_param('i', $s['banki_tetel']['id']); $st->execute(); $updated += $st->affected_rows;
                    foreach ($s['hazi_penztar_tetelek'] as $hp) {
                        $st = $conn->prepare("UPDATE hazi_penztar SET status = 'egyeztetett' WHERE id = ?");
                        $st->bind_param('i', $hp['id']); $st->execute(); $updated += $st->affected_rows;
                    }
                }
                $success = "$updated rekord státusza egyeztetett-re frissítve.";
            }
        }

        if ($action === 'batch_accept' && isset($_POST['ids'])) {
            $ids = json_decode($_POST['ids'], true);
            if (is_array($ids) && count($ids) > 0) {
                // Each id is: B{id} or C{id} indicating bank_tabla or hazi_penztar
                $b_ids = []; $c_ids = [];
                foreach ($ids as $id_str) {
                    if (str_starts_with($id_str, 'B')) $b_ids[] = intval(substr($id_str, 1));
                    elseif (str_starts_with($id_str, 'C')) $c_ids[] = intval(substr($id_str, 1));
                }
                $total = 0;
                if ($b_ids) {
                    $ids_list = implode(',', $b_ids);
                    $conn->query("UPDATE bank_tabla SET status = 'egyeztetett' WHERE id IN ($ids_list)");
                    $total += $conn->affected_rows;
                }
                if ($c_ids) {
                    $ids_list = implode(',', $c_ids);
                    $conn->query("UPDATE hazi_penztar SET status = 'egyeztetett' WHERE id IN ($ids_list)");
                    $total += $conn->affected_rows;
                }
                $success = "$total rekord egyeztetett státuszra állítva.";
            }
        }

        if ($action === 'reject' && isset($_POST['suggestion'])) {
            $s = json_decode($_POST['suggestion'], true);
            if ($s) {
                $updated = 0;
                if ($s['tipus'] === 'SOK_AZ_EGYHEZ') {
                    foreach ($s['banki_tetelek'] as $bt) {
                        $st = $conn->prepare("UPDATE bank_tabla SET status = 'elutasitva' WHERE id = ?");
                        $st->bind_param('i', $bt['id']); $st->execute(); $updated += $st->affected_rows;
                    }
                    $st = $conn->prepare("UPDATE hazi_penztar SET status = 'elutasitva' WHERE id = ?");
                    $st->bind_param('i', $s['hazi_penztar_tetel']['id']); $st->execute(); $updated += $st->affected_rows;
                } else {
                    $st = $conn->prepare("UPDATE bank_tabla SET status = 'elutasitva' WHERE id = ?");
                    $st->bind_param('i', $s['banki_tetel']['id']); $st->execute(); $updated += $st->affected_rows;
                    foreach ($s['hazi_penztar_tetelek'] as $hp) {
                        $st = $conn->prepare("UPDATE hazi_penztar SET status = 'elutasitva' WHERE id = ?");
                        $st->bind_param('i', $hp['id']); $st->execute(); $updated += $st->affected_rows;
                    }
                }
                $success = "$updated rekord elutasítva.";
            }
        }

        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Revizor AI Kombinatorikus Egyeztető</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', system-ui, sans-serif; padding-bottom: 60px; }
        .navbar-ai { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); padding: 12px 24px; }
        .navbar-ai .navbar-brand { color: #e94560; font-weight: 700; letter-spacing: -0.5px; }
        .navbar-ai .nav-link { color: rgba(255,255,255,0.7); }
        .navbar-ai .nav-link:hover { color: #fff; }
        .stat-card { background: white; border-radius: 12px; padding: 16px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); text-align: center; }
        .stat-card .num { font-size: 28px; font-weight: 700; }
        .stat-card .lbl { font-size: 12px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; }
        .filter-chip { display: inline-block; padding: 4px 16px; border-radius: 20px; font-size: 13px; font-weight: 500;
            cursor: pointer; border: 1px solid #dee2e6; background: white; transition: all 0.15s; margin: 2px; }
        .filter-chip:hover { border-color: #0d6efd; color: #0d6efd; }
        .filter-chip.active { background: #0d6efd; color: white; border-color: #0d6efd; }
        .view-btn { border-radius: 20px; padding: 4px 16px; font-size: 13px; }

        .suggestion-card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            margin-bottom: 16px; overflow: hidden; transition: box-shadow 0.2s; }
        .suggestion-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.1); }
        .card-header-merge { background: #e8f4fd; padding: 10px 16px; border-bottom: 2px solid #0d6efd; }
        .card-header-split { background: #e8f8ed; padding: 10px 16px; border-bottom: 2px solid #198754; }
        .card-header-rejected { background: #f8f0f0; padding: 10px 16px; border-bottom: 2px solid #dc3545; }
        .card-badge { font-size: 11px; padding: 3px 10px; border-radius: 12px; }
        .item-line { padding: 6px 10px; border-left: 3px solid #dee2e6; margin-bottom: 4px; border-radius: 0 4px 4px 0; }
        .item-line.bank { border-left-color: #0d6efd; background: #f8f9ff; }
        .item-line.cash { border-left-color: #198754; background: #f8fff9; }
        .item-amount { font-weight: 600; font-size: 14px; }
        .arrow-connector { display: flex; align-items: center; justify-content: center; font-size: 24px; color: #6c757d; padding: 8px 0; }
        .cat-tag { display: inline-block; font-size: 10px; padding: 2px 8px; border-radius: 10px;
            background: #e9ecef; color: #495057; margin-right: 4px; }

        .table-suggestions { font-size: 13px; }
        .table-suggestions th { position: sticky; top: 0; background: #212529; color: white; font-weight: 600; font-size: 12px; }

        #loadingOverlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.4); z-index: 9999; justify-content: center; align-items: center; }
        #loadingOverlay.show { display: flex; }
        #loadingBox { background: white; padding: 40px; border-radius: 16px; text-align: center; min-width: 280px; box-shadow: 0 8px 30px rgba(0,0,0,0.2); }

        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 99999; }
        .hidden { display: none !important; }
    </style>
</head>
<body>

<div id="loadingOverlay">
    <div id="loadingBox">
        <div class="spinner-border text-danger mb-3" style="width: 3rem; height: 3rem;"></div>
        <h5 class="mb-1">🤖 Kombinatorikus keresés</h5>
        <p class="text-muted small mb-2">Banki és pénztári tételek összehasonlítása...</p>
        <div id="runTimer" class="text-muted small">0s</div>
    </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<nav class="navbar navbar-ai">
    <div class="container-fluid">
        <span class="navbar-brand">🤖 Revizor AI Kombinatorikus Egyeztető</span>
        <div>
            <a href="../index.php" class="nav-link d-inline-block me-3">← Vissza a főoldalra</a>
            <span class="text-light small" id="clock"></span>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 py-3">

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="row mb-3" id="statsRow">
        <div class="col"><div class="stat-card"><div class="num" id="statBank">—</div><div class="lbl">Banki rekord</div></div></div>
        <div class="col"><div class="stat-card"><div class="num" id="statCash">—</div><div class="lbl">Pénztári rekord</div></div></div>
        <div class="col"><div class="stat-card"><div class="num" id="statSuggest">—</div><div class="lbl">Javaslat</div></div></div>
        <div class="col"><div class="stat-card"><div class="num" id="statTime">—</div><div class="lbl">Futási idő</div></div></div>
    </div>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <button class="btn btn-danger px-4" onclick="runAI()">🚀 Kombinatorikus AI egyeztetés indítása</button>

        <div class="vr mx-2"></div>

        <span class="text-muted small">Nézet:</span>
        <button class="btn btn-outline-secondary btn-sm view-btn active" data-view="card" onclick="switchView('card')">📇 Kártya</button>
        <button class="btn btn-outline-secondary btn-sm view-btn" data-view="table" onclick="switchView('table')">📋 Táblázat</button>

        <div class="vr mx-2"></div>

        <span class="text-muted small">Kategória:</span>
        <span class="filter-chip active" data-cat="all" onclick="filterCategory(this, 'all')">Összes</span>
        <span class="filter-chip" data-cat="rezsi" onclick="filterCategory(this, 'rezsi')">💡 Rezsi</span>
        <span class="filter-chip" data-cat="adomány" onclick="filterCategory(this, 'adomány')">🙏 Adomány</span>
        <span class="filter-chip" data-cat="bankdíj" onclick="filterCategory(this, 'bankdíj')">🏦 Bankdíj</span>
        <span class="filter-chip" data-cat="bér" onclick="filterCategory(this, 'bér')">👤 Bér</span>

        <div class="vr mx-2"></div>

        <span class="text-muted small">Típus:</span>
        <span class="filter-chip active" data-type="all" onclick="filterType(this, 'all')">Mind</span>
        <span class="filter-chip" data-type="merge" onclick="filterType(this, 'merge')">Összevonás</span>
        <span class="filter-chip" data-type="split" onclick="filterType(this, 'split')">Szétkönyvelés</span>

        <div class="ms-auto">
            <button class="btn btn-success btn-sm" onclick="batchAccept()" id="batchBtn" disabled>
                ✅ Kiválasztottak elfogadása (<span id="selectedCount">0</span>)
            </button>
        </div>
    </div>

    <!-- KÁRTYA NÉZET -->
    <div id="cardView">
        <div id="suggestionsContainer">
            <div class="text-center text-muted py-5">Nyomd meg a gombot az egyeztetés indításához</div>
        </div>
    </div>

    <!-- TÁBLÁZAT NÉZET -->
    <div id="tableView" class="hidden">
        <div class="table-responsive" style="max-height: 72vh; overflow-y: auto; border-radius: 8px; border: 1px solid #dee2e6; background: white;">
            <table class="table table-bordered table-hover table-suggestions align-middle mb-0" id="suggestionTable">
                <thead>
                    <tr>
                        <th style="width:36px;"><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
                        <th style="width:70px;">Típus</th>
                        <th style="width:70px;">TP</th>
                        <th style="width:60px;">Kategória</th>
                        <th>Banki tétel(ek)</th>
                        <th>Pénztári tétel(ek)</th>
                        <th style="width:100px;">Összeg</th>
                        <th style="width:80px;">Pont</th>
                        <th style="width:140px;">Művelet</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
let suggestions = [];
let currentFilter = { category: 'all', type: 'all' };

function showToast(msg, type) {
    const t = document.getElementById('toastContainer');
    const d = document.createElement('div');
    d.className = `alert alert-${type} alert-dismissible fade show py-2 px-3 mb-2`;
    d.innerHTML = msg + '<button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>';
    t.appendChild(d);
    setTimeout(() => d.remove(), 4000);
}

function runAI() {
    const overlay = document.getElementById('loadingOverlay');
    const timer = document.getElementById('runTimer');
    overlay.classList.add('show');
    const start = Date.now();
    const iv = setInterval(() => { timer.textContent = ((Date.now()-start)/1000).toFixed(1) + 's'; }, 100);

    fetch('run.php')
        .then(r => r.json())
        .then(data => {
            clearInterval(iv);
            overlay.classList.remove('show');
            if (data.error) { showToast(data.error, 'danger'); return; }
            suggestions = data.javaslatok || [];
            renderStats(data.stat || {});
            renderCards(suggestions);
            renderTable(suggestions);
            showToast(`✅ ${suggestions.length} javaslat található ${(data.futasi_ido||0).toFixed(1)}s alatt`, 'success');
        })
        .catch(err => {
            clearInterval(iv);
            overlay.classList.remove('show');
            showToast('Hiba: ' + err.message, 'danger');
        });
}

function renderStats(stat) {
    document.getElementById('statBank').textContent = stat.banki_rekordok ?? '—';
    document.getElementById('statCash').textContent = stat.penztari_rekordok ?? '—';
    document.getElementById('statSuggest').textContent = stat.talalt_javaslat ?? '—';
    document.getElementById('statTime').textContent = (stat.futasi_ido ?? stat.futasi_ido === 0) ? stat.futasi_ido + 's' : '—';
}

function renderCards(list) {
    const c = document.getElementById('suggestionsContainer');
    if (!list.length) { c.innerHTML = '<div class="text-center text-muted py-5">Nincs találat. Próbáld újra más paraméterekkel.</div>'; return; }

    let html = '';
    list.forEach((s, idx) => {
        const isMerge = s.tipus === 'SOK_AZ_EGYHEZ';
        const hClass = isMerge ? 'card-header-merge' : 'card-header-split';
        const typeLabel = isMerge ? 'ÖSSZEVONÁS' : 'SZÉTKÖNYVELÉS';
        const typeColor = isMerge ? '#0d6efd' : '#198754';

        let cats = '';
        if (s.kategoria && s.kategoria.length) {
            cats = s.kategoria.map(c => '<span class="cat-tag">' + c + '</span>').join('');
        } else { cats = '<span class="text-muted" style="font-size:11px;">—</span>'; }

        let bankItems = '', cashItems = '';
        if (isMerge) {
            s.banki_tetelek.forEach(b => {
                bankItems += `<div class="item-line bank"><div class="d-flex justify-content-between">
                    <span><strong>#${b.id}</strong> ${b.datum}</span>
                    <span class="item-amount">${(b.osszeg||0).toLocaleString('hu-HU',{minimumFractionDigits:0})} Ft</span>
                </div><div class="text-muted" style="font-size:11px;">${escapeHtml(b.leiras || '').substring(0,80)}</div></div>`;
            });
            const ch = s.hazi_penztar_tetel;
            cashItems = `<div class="item-line cash"><div class="d-flex justify-content-between">
                <span><strong>#${ch.id}</strong> ${ch.datum}</span>
                <span class="item-amount">${(ch.osszeg||0).toLocaleString('hu-HU',{minimumFractionDigits:0})} Ft</span>
            </div><div class="text-muted" style="font-size:11px;">${escapeHtml(ch.leiras||'').substring(0,80)}</div></div>`;
        } else {
            const bk = s.banki_tetel;
            bankItems = `<div class="item-line bank"><div class="d-flex justify-content-between">
                <span><strong>#${bk.id}</strong> ${bk.datum}</span>
                <span class="item-amount">${(bk.osszeg||0).toLocaleString('hu-HU',{minimumFractionDigits:0})} Ft</span>
            </div><div class="text-muted" style="font-size:11px;">${escapeHtml(bk.leiras||'').substring(0,80)}</div></div>`;
            s.hazi_penztar_tetelek.forEach(ch => {
                cashItems += `<div class="item-line cash"><div class="d-flex justify-content-between">
                    <span><strong>#${ch.id}</strong> ${ch.datum}</span>
                    <span class="item-amount">${(ch.osszeg||0).toLocaleString('hu-HU',{minimumFractionDigits:0})} Ft</span>
                </div><div class="text-muted" style="font-size:11px;">${escapeHtml(ch.leiras||'').substring(0,80)}</div></div>`;
            });
        }

        const jsonData = escapeHtml(JSON.stringify(s));
        html += `<div class="suggestion-card" data-idx="${idx}" data-category="${s.kategoria ? s.kategoria.join(',') : ''}" data-type="${isMerge ? 'merge' : 'split'}">
            <div class="${hClass} d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge" style="background:${typeColor};">${typeLabel}</span>
                    <span class="ms-2 fw-semibold">TP ${s.telephely}</span>
                    <span class="ms-2">${cats}</span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-secondary">Pont: ${s.pontszam ?? 0}</span>
                </div>
            </div>
            <div class="p-3">
                <div class="row align-items-start">
                    <div class="col-md-5">${bankItems}</div>
                    <div class="col-md-1 arrow-connector">${isMerge ? '→' : '←'}</div>
                    <div class="col-md-5">${cashItems}</div>
                </div>
            </div>
            <div class="px-3 pb-3 d-flex gap-2">
                <form method="POST" action="" onsubmit="return confirm('Elfogadod?')">
                    <input type="hidden" name="action" value="accept">
                    <input type="hidden" name="suggestion" value='${jsonData}'>
                    <button type="submit" class="btn btn-success btn-sm">✅ Elfogadás</button>
                </form>
                <form method="POST" action="" onsubmit="return confirm('Elutasítod?')">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="suggestion" value='${jsonData}'>
                    <button type="submit" class="btn btn-outline-danger btn-sm">❌ Elutasítás</button>
                </form>
            </div>
        </div>`;
    });
    c.innerHTML = html;
}

function renderTable(list) {
    const tbody = document.getElementById('tableBody');
    if (!list.length) { tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">Nincs találat</td></tr>'; return; }

    let html = '';
    list.forEach((s, idx) => {
        const isMerge = s.tipus === 'SOK_AZ_EGYHEZ';
        const typeLabel = isMerge ? 'Összevonás' : 'Szétkönyvelés';
        const badgeClass = isMerge ? 'bg-primary' : 'bg-success';

        let cats = '';
        if (s.kategoria && s.kategoria.length) {
            cats = s.kategoria.map(c => `<span class="badge bg-secondary me-1" style="font-size:10px;">${c}</span>`).join('');
        }

        let bankInfo = '', cashInfo = '';
        if (isMerge) {
            bankInfo = s.banki_tetelek.map(b => `#${b.id} ${b.datum}`).join('<br>');
            cashInfo = `#${s.hazi_penztar_tetel.id} ${s.hazi_penztar_tetel.datum}`;
        } else {
            bankInfo = `#${s.banki_tetel.id} ${s.banki_tetel.datum}`;
            cashInfo = s.hazi_penztar_tetelek.map(c => `#${c.id} ${c.datum}`).join('<br>');
        }

        const amount = (s.banki_osszeg || 0).toLocaleString('hu-HU', {minimumFractionDigits: 0}) + ' Ft';
        const jsonData = escapeHtml(JSON.stringify(s));

        html += `<tr data-idx="${idx}" data-category="${s.kategoria ? s.kategoria.join(',') : ''}" data-type="${isMerge ? 'merge' : 'split'}">
            <td><input type="checkbox" class="row-checkbox" value="${idx}" onchange="updateBatchBtn()"></td>
            <td><span class="badge ${badgeClass}">${typeLabel}</span></td>
            <td>${s.telephely}</td>
            <td>${cats || '<span class="text-muted">—</span>'}</td>
            <td style="font-size:12px;">${bankInfo}</td>
            <td style="font-size:12px;">${cashInfo}</td>
            <td class="fw-bold text-end">${amount}</td>
            <td class="text-center">${s.pontszam ?? 0}</td>
            <td>
                <form method="POST" action="" class="d-inline" onsubmit="return confirm('Elfogadod?')">
                    <input type="hidden" name="action" value="accept">
                    <input type="hidden" name="suggestion" value='${jsonData}'>
                    <button type="submit" class="btn btn-success btn-sm">✅</button>
                </form>
                <form method="POST" action="" class="d-inline" onsubmit="return confirm('Elutasítod?')">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="suggestion" value='${jsonData}'>
                    <button type="submit" class="btn btn-outline-danger btn-sm">❌</button>
                </form>
            </td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

function switchView(view) {
    document.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
    document.querySelector(`.view-btn[data-view="${view}"]`).classList.add('active');
    document.getElementById('cardView').classList.toggle('hidden', view !== 'card');
    document.getElementById('tableView').classList.toggle('hidden', view !== 'table');
}

function filterCategory(el, cat) {
    document.querySelectorAll('.filter-chip[data-cat]').forEach(c => c.classList.remove('active'));
    el.classList.add('active');
    currentFilter.category = cat;
    applyFilters();
}

function filterType(el, type) {
    document.querySelectorAll('.filter-chip[data-type]').forEach(c => c.classList.remove('active'));
    el.classList.add('active');
    currentFilter.type = type;
    applyFilters();
}

function applyFilters() {
    const cat = currentFilter.category;
    const type = currentFilter.type;

    document.querySelectorAll('.suggestion-card').forEach(card => {
        const cCat = card.dataset.category || '';
        const cType = card.dataset.type || '';
        const catOk = cat === 'all' || cCat.split(',').includes(cat);
        const typeOk = type === 'all' || cType === type;
        card.style.display = (catOk && typeOk) ? '' : 'none';
    });

    document.querySelectorAll('#suggestionTable tbody tr').forEach(row => {
        const cCat = row.dataset.category || '';
        const cType = row.dataset.type || '';
        const catOk = cat === 'all' || cCat.split(',').includes(cat);
        const typeOk = type === 'all' || cType === type;
        row.style.display = (catOk && typeOk) ? '' : 'none';
    });

    document.getElementById('selectAll').checked = false;
    updateBatchBtn();
}

function toggleSelectAll() {
    const checked = document.getElementById('selectAll').checked;
    document.querySelectorAll('#tableBody .row-checkbox').forEach(cb => cb.checked = checked);
    updateBatchBtn();
}

function updateBatchBtn() {
    const checked = document.querySelectorAll('#tableBody .row-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = checked;
    document.getElementById('batchBtn').disabled = checked === 0;
}

function batchAccept() {
    const checked = document.querySelectorAll('#tableBody .row-checkbox:checked');
    if (!checked.length) return;
    if (!confirm(`${checked.length} javaslat elfogadása?`)) return;

    const ids = [];
    checked.forEach(cb => {
        const idx = parseInt(cb.value);
        const s = suggestions[idx];
        if (!s) return;
        if (s.tipus === 'SOK_AZ_EGYHEZ') {
            s.banki_tetelek.forEach(b => ids.push('B' + b.id));
            ids.push('C' + s.hazi_penztar_tetel.id);
        } else {
            ids.push('B' + s.banki_tetel.id);
            s.hazi_penztar_tetelek.forEach(c => ids.push('C' + c.id));
        }
    });

    const form = document.createElement('form');
    form.method = 'POST';
    const a = document.createElement('input'); a.name = 'action'; a.value = 'batch_accept';
    const b = document.createElement('input'); b.name = 'ids'; b.value = JSON.stringify(ids);
    form.appendChild(a); form.appendChild(b);
    document.body.appendChild(form);
    form.submit();
}

function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

document.getElementById('clock').textContent = new Date().toLocaleString('hu-HU');

window.addEventListener('DOMContentLoaded', function() {
    fetch('load.php')
        .then(r => r.json())
        .then(data => {
            suggestions = data.javaslatok || [];
            renderStats(data.stat || {});
            renderCards(suggestions);
            renderTable(suggestions);
        })
        .catch(() => {});
});
</script>

</body>
</html>
