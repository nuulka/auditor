<?php
// Common bootstrap for Revizor
// Lightweight: start session if needed, provide helper DB connections
if (session_status() != PHP_SESSION_ACTIVE) {
    session_start();
}

function get_revizor_conn() {
    static $c = null;
    if ($c === null) {
        $c = new mysqli('localhost', 'root', '', 'revizor_db');
        if ($c->connect_error) { throw new Exception('Revizor DB connection failed: ' . $c->connect_error); }
        $c->set_charset('utf8mb4');
    }
    return $c;
}

function get_ots_conn() {
    static $o = null;
    if ($o === null) {
        $o = new mysqli('localhost', 'root', '', 'ots');
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
    }
    return $cfg;
}

?>
