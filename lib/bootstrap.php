<?php
// Common bootstrap for Revizor
// Lightweight: start session if needed, provide helper DB connections
if (session_status() != PHP_SESSION_ACTIVE) {
    session_start();
}

function get_revizor_conn() {
    static $c = null;
    if ($c === null) {
        $cfg = load_app_config();
        $db = $cfg['db']['revizor'];
        $c = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
        if ($c->connect_error) { throw new Exception('Revizor DB connection failed: ' . $c->connect_error); }
        $c->set_charset('utf8mb4');
    }
    return $c;
}

function get_ots_conn() {
    static $o = null;
    if ($o === null) {
        $cfg = load_app_config();
        $db = $cfg['db']['ots'];
        $o = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
        if ($o->connect_error) { throw new Exception('OTS DB connection failed: ' . $o->connect_error); }
        $o->set_charset('utf8mb4');
    }
    return $o;
}

function load_app_config() {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [
            'demo_mode' => false,
            'demo_reviewer_church_id' => 43
        ];
        // attempt to load config/app.php if present
        $cfg_file = __DIR__ . '/../config/app.php';
        if (file_exists($cfg_file)) {
            $user_cfg = include $cfg_file;
            if (is_array($user_cfg)) {
                $cfg = array_replace_recursive($cfg, $user_cfg);
            }
        }
        $local_cfg_file = __DIR__ . '/../config/app.local.php';
        if (file_exists($local_cfg_file)) {
            $local_cfg = include $local_cfg_file;
            if (is_array($local_cfg)) {
                $cfg = array_replace_recursive($cfg, $local_cfg);
            }
        }
    }
    return $cfg;
}

?>
