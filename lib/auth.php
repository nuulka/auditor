<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../ots/constant.php';

function require_login() {
    if (!isset($_SESSION[GC_LOGIN_COOKIE])) {
        header('Location: /revizor/login.php');
        exit;
    }
}

function is_admin() {
    $rights = isset($_SESSION[GN_USER_RIGHTS]) ? intval($_SESSION[GN_USER_RIGHTS]) : 0;
    return ($rights & SDA_L_CONFERENCE_ROLES) != 0;
}

function is_revizor() {
    $rights = isset($_SESSION[GN_USER_RIGHTS]) ? intval($_SESSION[GN_USER_RIGHTS]) : 0;
    return ($rights & SDA_L_AUDITOR) != 0;
}

function get_accessible_church_ids() {
    if (isset($_SESSION['revizor_accessible_churches']) && is_array($_SESSION['revizor_accessible_churches'])) {
        return array_map('intval', $_SESSION['revizor_accessible_churches']);
    }
    // fallback: if admin, return empty array to indicate all
    if (is_admin()) return [];
    // demo fallback
    $cfg = load_app_config();
    if (!empty($cfg['demo_mode'])) return [(int)$cfg['demo_reviewer_church_id']];
    return [];
}

function require_church_access($church_id) {
    if (is_admin()) return true;
    $allowed = get_accessible_church_ids();
    if (empty($allowed)) {
        // no access
        header('HTTP/1.1 403 Forbidden');
        echo 'Forbidden';
        exit;
    }
    if (!in_array(intval($church_id), $allowed, true)) {
        header('HTTP/1.1 403 Forbidden');
        echo 'Forbidden';
        exit;
    }
    return true;
}

function build_user_context_from_ots() {
    // populate session accessible church ids from ots.ROLES
    if (!isset($_SESSION[GN_USER_ID])) return;
    $userId = intval($_SESSION[GN_USER_ID]);
    $ots = get_ots_conn();
    $stmt = $ots->prepare("SELECT CHURCH_ID FROM ROLES WHERE USER_ID = ? AND VALID_FROM <= NOW() AND (VALID_TO IS NULL OR VALID_TO >= NOW())");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $list = [];
        while ($r = $res->fetch_assoc()) { $list[] = intval($r['CHURCH_ID']); }
        $_SESSION['revizor_accessible_churches'] = $list;
    }
}

?>
