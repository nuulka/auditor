<?php
header('Content-Type: application/json; charset=utf-8');

$result_path = __DIR__ . DIRECTORY_SEPARATOR . 'result.json';
if (!file_exists($result_path)) {
    echo json_encode(['javaslatok' => [], 'stat' => [
        'banki_rekordok' => 0, 'penztari_rekordok' => 0,
        'talalt_javaslat' => 0, 'futasi_ido' => 0,
    ]]);
    exit;
}

$raw = file_get_contents($result_path);
if ($raw === false || $raw === '') {
    echo json_encode(['javaslatok' => [], 'stat' => [
        'banki_rekordok' => 0, 'penztari_rekordok' => 0,
        'talalt_javaslat' => 0, 'futasi_ido' => 0,
    ]]);
    exit;
}

echo $raw;
