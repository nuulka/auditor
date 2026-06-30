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

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/session.php';
build_user_context_from_ots();

$session_remaining = ensure_revizor_session_timeout();
ensure_revizor_csrf_token();

$accessible = get_accessible_church_ids();
$ots = get_ots_conn();

/** Csak relatív (biztonságos) átirányítást engedélyez */
function safe_redirect(string $url): string {
    $parsed = parse_url($url);
    if ($parsed === false) return 'index.php';
    // Ha van scheme vagy host, eldobjuk – csak relatív URL-t engedünk
    if (isset($parsed['scheme']) || isset($parsed['host'])) return 'index.php';
    // Csak a megengedett fájlokra irányíthatunk
    $allowed = ['index.php', 'help.php', 'upload.php', 'document_check.php', 'search.php', 'reconciliation.php', 'select-church.php', 'all_transactions/all_transactions_multi.php', 'match_progress.php'];
    $path = $parsed['path'] ?? '';
    $path = ltrim($path, '/');
    return in_array($path, $allowed, true) ? $url : 'index.php';
}

// Ha már van kiválasztva gyülekezet, és itt a change param, töröljük
if (isset($_GET['change']) && isset($_SESSION['revizor_selected_church'])) {
    unset($_SESSION['revizor_selected_church'], $_SESSION['revizor_selected_church_name']);
}

// Mentés
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['church_id'])) {
    $cid = intval($_POST['church_id']);
    if ($cid > 0 && (is_admin() || (is_array($accessible) && in_array($cid, $accessible)))) {
        $stmt = $ots->prepare("SELECT name FROM ots.churches WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $cid);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $_SESSION['revizor_selected_church'] = $cid;
                $_SESSION['revizor_selected_church_name'] = $row['name'];
            }
        }
    }
    $redirect = isset($_POST['redirect']) ? safe_redirect($_POST['redirect']) : 'index.php';
    header("Location: $redirect");
    exit;
}

// Gyülekezet lista lekérése
$churches = [];
if (is_admin()) {
    $all = $ots->query("SELECT id, name FROM ots.churches WHERE name IS NOT NULL AND name != '' ORDER BY name ASC");
    if ($all) {
        while ($r = $all->fetch_assoc()) {
            $churches[] = $r;
        }
    }
} elseif (is_array($accessible) && !empty($accessible)) {
    $placeholders = implode(',', array_fill(0, count($accessible), '?'));
    $types = str_repeat('i', count($accessible));
    $stmt = $ots->prepare("SELECT id, name FROM ots.churches WHERE id IN ($placeholders) AND name IS NOT NULL AND name != '' ORDER BY name ASC");
    if ($stmt) {
        $stmt->bind_param($types, ...$accessible);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $churches[] = $r;
        }
    }
}

// Ha nincs elérhető gyülekezet, irány a főoldal
if (empty($churches)) {
    header('Location: index.php');
    exit;
}

$redirect_to = isset($_GET['redirect']) ? safe_redirect($_GET['redirect']) : 'index.php';
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>🕵️ Revizor - Gyülekezet választás</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f5f7fb; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { border: none; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,0.1); max-width: 500px; width: 100%; }
        .church-btn { display: block; width: 100%; padding: 12px 16px; border: 1px solid #dee2e6; border-radius: 8px; background: white; text-align: left; font-size: 1rem; transition: all .15s; cursor: pointer; }
        .church-btn:hover { border-color: #0d6efd; background: #f0f4ff; }
        .church-btn:focus { outline: 2px solid #0d6efd; outline-offset: 2px; }
    </style>
</head>
<body>
    <div class="card p-4">
        <div class="text-center mb-3">
            <div style="font-size:48px;">🏛️</div>
            <h4 class="mb-1">Válassz gyülekezetet</h4>
            <p class="text-muted small mb-0">Melyik gyülekezettel szeretnél dolgozni? <kbd class="bg-light text-dark border px-1" style="font-size:11px;border-radius:3px;">Betű</kbd> billentyűvel ugrás</p>
        </div>
        <form method="POST" id="churchForm">
            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect_to); ?>">
            <div class="d-flex flex-column gap-2 mb-2" id="churchList">
                <?php foreach ($churches as $c): ?>
                <button type="submit" name="church_id" value="<?php echo $c['id']; ?>" class="church-btn"
                    data-name="<?php echo htmlspecialchars($c['name']); ?>"
                    <?php if (isset($_SESSION['revizor_selected_church']) && $_SESSION['revizor_selected_church'] == $c['id']) echo 'style="border-color:#0d6efd;background:#e7f1ff;font-weight:600;"'; ?>>
                    🏛 <?php echo htmlspecialchars($c['name']); ?>
                </button>
                <?php endforeach; ?>
            </div>
        </form>
        <div class="text-center mt-2">
            <a href="<?php echo htmlspecialchars($redirect_to); ?>" class="btn btn-outline-secondary btn-sm">Később</a>
        </div>
    </div>
    <script>
    var lastKeyTime = 0;
    var keyBuffer = '';
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey || e.altKey || e.metaKey) return;
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        var key = e.key;
        if (key.length !== 1) return;
        var now = Date.now();
        if (now - lastKeyTime > 800) keyBuffer = '';
        lastKeyTime = now;
        keyBuffer += key.toLowerCase();
        var buttons = document.querySelectorAll('#churchList .church-btn');
        var found = null;
        var bestIndex = -1;
        if (keyBuffer.length === 1) {
            for (var i = 0; i < buttons.length; i++) {
                var name = buttons[i].getAttribute('data-name') || '';
                if (name.charAt(0).toLowerCase() === key) { found = buttons[i]; bestIndex = i; break; }
            }
        } else {
            for (var i = 0; i < buttons.length; i++) {
                var name = buttons[i].getAttribute('data-name') || '';
                if (name.toLowerCase().substring(0, keyBuffer.length) === keyBuffer) { found = buttons[i]; bestIndex = i; break; }
            }
        }
        if (found) {
            e.preventDefault();
            found.scrollIntoView({ behavior: 'smooth', block: 'center' });
            found.focus({ preventScroll: true });
            document.querySelectorAll('.church-btn').forEach(function(b) { b.style.borderColor = '#dee2e6'; b.style.background = 'white'; });
            found.style.borderColor = '#0d6efd';
            found.style.background = '#f0f4ff';
        }
    });
    </script>
</body>
</html>
