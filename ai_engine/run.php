<?php
header('Content-Type: application/json; charset=utf-8');
set_time_limit(0);

$script_dir = __DIR__;
$script_path = $script_dir . DIRECTORY_SEPARATOR . 'ai_combinatorics.py';
$result_path = $script_dir . DIRECTORY_SEPARATOR . 'result.json';

if (!file_exists($script_path)) {
    echo json_encode(['error' => 'Hiányzik: ai_combinatorics.py'], JSON_UNESCAPED_UNICODE);
    exit;
}

$python_cmd = 'python';
$escaped = escapeshellarg($script_path);
$command = "$python_cmd $escaped 2>&1";

$start = microtime(true);
$output = shell_exec($command);
$elapsed = round(microtime(true) - $start, 1);

if ($output === null) {
    echo json_encode([
        'error' => "shell_exec sikertelen. Python elérhető?",
        'futasi_ido' => $elapsed,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Python kiírta a result.json-t, onnan olvassuk vissza
if (!file_exists($result_path)) {
    echo json_encode([
        'error' => 'Python nem hozta létre a result.json-t.',
        'futasi_ido' => $elapsed,
        'raw' => mb_substr($output, 0, 500),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents($result_path);
if ($raw === false || $raw === '') {
    echo json_encode([
        'error' => 'result.json üres.',
        'futasi_ido' => $elapsed,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$decoded = json_decode($raw, true);
if ($decoded === null) {
    echo json_encode([
        'error' => 'result.json nem érvényes JSON.',
        'futasi_ido' => $elapsed,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($decoded['error'])) {
    echo json_encode([
        'error' => $decoded['error'],
        'futasi_ido' => $elapsed,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$decoded['futasi_ido'] = $elapsed;
echo json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
