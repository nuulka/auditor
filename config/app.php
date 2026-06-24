<?php
// Revizor application configuration.
// Keep secrets out of this file; use config/app.local.php or environment variables.
return [
    'demo_mode' => false,
    'demo_reviewer_church_id' => 43,
    'db' => [
        'revizor' => [
            'host' => getenv('REVIZOR_DB_HOST') ?: 'localhost',
            'user' => getenv('REVIZOR_DB_USER') ?: 'revizor_rw',
            'pass' => getenv('REVIZOR_DB_PASS') ?: '',
            'name' => getenv('REVIZOR_DB_NAME') ?: 'revizor_db',
        ],
        'ots' => [
            'host' => getenv('OTS_DB_HOST') ?: 'localhost',
            'user' => getenv('OTS_DB_USER') ?: 'ots_ro',
            'pass' => getenv('OTS_DB_PASS') ?: '',
            'name' => getenv('OTS_DB_NAME') ?: 'ots',
        ],
    ],
    'storage' => [
        'documents_path' => __DIR__ . '/../_storage/documents',
    ],
];
