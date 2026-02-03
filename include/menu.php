<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

require_once __DIR__ . '/acl.php';
ensureRole();
require_once __DIR__ . '/db.php';
app_db_import_legacy_if_needed();

include('./include/version.php');

$env = [];
$envFile = __DIR__ . '/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
$backupKey = $env['backup']['secret'] ?? '';
$has_router_session = app_db_first_session_id() !== '';

$last_sync_sales = '-';
$last_sync_live = '-';
$last_sync_sales_full = '-';
$last_sync_live_full = '-';
$last_sync_sales_class = 'sync-ok';
$last_sync_live_class = 'sync-ok';
$last_sync_sales_blink = 'blink-slow';
$last_sync_live_blink = 'blink-slow';
try {
    $system_cfg = $env['system'] ?? [];
    $db_rel = $system_cfg['db_file'] ?? 'db_data/babahdigital_main.db';
    if (preg_match('/^[A-Za-z]:\\|^\//', $db_rel)) {
        $stats_db = $db_rel;
    } else {
        $stats_db = dirname(__DIR__) . '/' . ltrim($db_rel, '/');
    }
    if (is_file($stats_db)) {
        $db_sync = new PDO('sqlite:' . $stats_db);
        $db_sync->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $last_sync_sales_full = (string)$db_sync->query("SELECT MAX(sync_date) FROM sales_history")->fetchColumn();
        $last_sync_live_full = (string)$db_sync->query("SELECT MAX(sync_date) FROM live_sales")->fetchColumn();
        if ($last_sync_sales_full === '') $last_sync_sales_full = '-';
        if ($last_sync_live_full === '') $last_sync_live_full = '-';
    }
} catch (Exception $e) {
    $last_sync_sales_full = '-';
    $last_sync_live_full = '-';
}

if ($last_sync_sales_full !== '-' && strlen($last_sync_sales_full) >= 16) {
    $last_sync_sales = substr($last_sync_sales_full, 11, 5);
}
if ($last_sync_live_full !== '-' && strlen($last_sync_live_full) >= 16) {
    $last_sync_live = substr($last_sync_live_full, 11, 5);
}

$now_ts = time();
$warn_minutes = 15;
$late_minutes = 60;
if ($last_sync_sales_full !== '-') {
    $ts = strtotime($last_sync_sales_full);
    if ($ts) {
        $diff_min = (int)floor(($now_ts - $ts) / 60);
        if ($diff_min >= $late_minutes) {
            $last_sync_sales_class = 'sync-late';
            $last_sync_sales_blink = 'blink-fast';
        } elseif ($diff_min >= $warn_minutes) {
            $last_sync_sales_class = 'sync-warn';
        }
    } else {
        $last_sync_sales_class = 'sync-warn';
    }
} else {
    $last_sync_sales_class = 'sync-warn';
}

if ($last_sync_live_full !== '-') {
    $ts = strtotime($last_sync_live_full);
    if ($ts) {
        $diff_min = (int)floor(($now_ts - $ts) / 60);
        if ($diff_min >= $late_minutes) {
            $last_sync_live_class = 'sync-late';
            $last_sync_live_blink = 'blink-fast';
        } elseif ($diff_min >= $warn_minutes) {
            $last_sync_live_class = 'sync-warn';
        }
    } else {
        $last_sync_live_class = 'sync-warn';
    }
} else {
    $last_sync_live_class = 'sync-warn';
}

$menu_retur_pending = 0;
$menu_retur_list = [];
$menu_retur_visible = (isOperator() || isSuperAdmin());
if ($menu_retur_visible) {
    $root_dir = dirname(__DIR__);
    $system_cfg = $env['system'] ?? [];
    $db_rel = $system_cfg['db_file'] ?? 'db_data/babahdigital_main.db';
    if (preg_match('/^[A-Za-z]:\\|^\//', $db_rel)) {
        $db_file = $db_rel;
    } else {
        $db_file = $root_dir . '/' . ltrim($db_rel, '/');
    }
    try {
        $db_menu = new PDO('sqlite:' . $db_file);
        $db_menu->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $menu_retur_pending = (int)$db_menu->query("SELECT COUNT(*) FROM retur_requests WHERE status='pending'")->fetchColumn();
        $stmt = $db_menu->prepare("SELECT r.id, r.created_at, r.request_date, r.voucher_code, r.blok_name, r.request_type, r.customer_name, r.reason,
            r.status, r.reviewed_by, r.reviewed_at, r.review_note,
            COALESCE(
                (SELECT COALESCE(sh.profile_snapshot, sh.profile) FROM sales_history sh WHERE sh.username = r.voucher_code ORDER BY sh.sale_datetime DESC, sh.id DESC LIMIT 1),
                (SELECT lh.validity FROM login_history lh WHERE lh.username = r.voucher_code LIMIT 1)
            ) AS profile_name,
            COALESCE(
                (SELECT sh.blok_name FROM sales_history sh WHERE sh.username = r.voucher_code ORDER BY sh.sale_datetime DESC, sh.id DESC LIMIT 1),
                (SELECT lh.blok_name FROM login_history lh WHERE lh.username = r.voucher_code LIMIT 1)
            ) AS blok_guess
            FROM retur_requests r
            ORDER BY r.created_at DESC
            LIMIT 200");
        $stmt->execute();
        $menu_retur_list = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $menu_retur_pending = 0;
        $menu_retur_list = [];
    }
}

$btnmenuactive = "font-weight: bold;background-color: #f9f9f9; color: #000000";
if ($hotspot == "dashboard" || substr(end(explode("/", $url)), 0, 8) == "?session") {
    $shome = "active";
    $mpage = $_dashboard;
} elseif ($hotspot == "quick-print" || $hotspot == "list-quick-print") {
    $squick = "active";
    $mpage = $_quick_print;
} elseif ($hotspot == "users" || $userbyprofile != "" || $hotspot == "export-users" || $removehotspotuserbycomment != "" || $removehotspotuser != "" || $removehotspotusers != "" || $disablehotspotuser || $enablehotspotuser != "") {
    $susersl = "active";
    $susers = "active";
    $mpage = $_users;
    $umenu = "menu-open";
} elseif ($hotspotuser == "generate") {
    $sgenuser = "active";
    $mpage = $_users;
    $susers = "active";
    $umenu = "menu-open";
} elseif ($userbyname != "" || $resethotspotuser != "") {
    $susers = "active";
    $mpage = $_users;
    $umenu = "menu-open";
} elseif ($hotspot == "user-profiles") {
    $suserprofiles = "active";
    $suserprof = "active";
    $mpage = $_user_profile;
    $upmenu = "menu-open";
} elseif ($hotspot == "active" || $removeuseractive != "") {
    $sactive = "active";
    $mpage = $_hotspot_active;
    $hamenu = "menu-open";
} elseif ($hotspot == "hosts" || $hotspot == "hostp" || $hotspot == "hosta" || $removehost != "") {
    $shosts = "active";
    $mpage = $_hosts;
    $hmenu = "menu-open";
} elseif ($hotspot == "dhcp-leases") {
    $slease = "active";
    $mpage = $_dhcp_leases;
} elseif ($minterface == "traffic-monitor") {
    $strafficmonitor = "active";
    $mpage = $_traffic_monitor;
} elseif ($hotspot == "template-editor") {
    $ssett = "active";
    $teditor = "active";
    $mpage = $_template_editor;
    $settmenu = "menu-open";
} elseif ($hotspot == "uplogo") {
    $ssett = "active";
    $uplogo = "active";
    $mpage = $_upload_logo;
    $settmenu = "menu-open";
} elseif ($hotspot == "cookies" || $removecookie != "") {
    $scookies = "active";
    $mpage = $_hotspot_cookies;
    $cmenu = "menu-open";
} elseif ($ppp == "secrets" || $ppp == "addsecret" || $enablesecr != "" || $disablesecr != "" || $removesecr != "" || $secretbyname != "") {
    $mppp = "active";
    $ssecrets = "active";
    $mpage = $_ppp_secrets;
    $pppmenu = "menu-open";
} elseif ($ppp == "profiles" || $removepprofile != "" || $ppp == "add-profile" || $ppp == "edit-profile") {
    $mppp = "active";
    $spprofile = "active";
    $mpage = $_ppp_profiles;
    $pppmenu = "menu-open";
} elseif ($ppp == "active" || $removepactive != "") {
    $mppp = "active";
    $spactive = "active";
    $mpage = $_ppp_active;
    $pppmenu = "menu-open";
} elseif ($sys == "scheduler" || $enablesch != "" || $disablesch != "" || $removesch != "") {
    $sysmenu = "active";
    $ssch = "active";
    $mpage = $_system_scheduler;
    $schmenu = "menu-open";
} elseif ($report == "selling" || $report == "resume-report") {
    $sselling = "active";
    $mpage = $_report;
} elseif ($report == "audit_session") {
    $saudit = "active";
    $mpage = $_audit_log;
} elseif ($report == "whatsapp") {
    $swhatsapp = "active";
    $mpage = "WhatsApp";
} elseif ($userprofile == "add") {
    $suserprof = "active";
    $sadduserprof = "active";
    $mpage = $_user_profile;
    $upmenu = "menu-open";
} elseif ($userprofilebyname != "") {
    $suserprof = "active";
    $mpage = $_user_profile;
    $upmenu = "menu-open";
} elseif ($hotspot == "users-by-profile") {
    $susersbp = "active";
    $mpage = $_vouchers;
} elseif ($userbyname != "") {
    $mpage = $_users;
    $susers = "active";
} elseif ($hotspot == "about") {
    $mpage = $_about;
    $sabout = "active";
} elseif ($id == "sessions" || $id == "remove" || $router == "new") {
    $ssesslist = "active";
    $mpage = $_admin_settings;
} elseif ($id == "settings" && $session == "new") {
    $snsettings = "active";
    $mpage = $_add_router;
} elseif ($id == "settings" || $id == "connect") {
    $ssettings = "active";
    $mpage = $_session_settings;
} elseif ($id == "mikrotik-scripts") {
    $sscripts = "active";
    $mpage = 'Script MikroTik';
} elseif ($id == "about") {
    $sabout = "active";
    $mpage = $_about;
} elseif ($id == "uplogo") {
    $suplogo = "active";
    $mpage = $_upload_logo;
} elseif ($id == "editor") {
    $seditor = "active";
    $mpage = $_template_editor;
}

?>

<span style="display:none;" id="idto">disable</span>
<span style="display:none;" id="timer"></span>

<link rel="stylesheet" href="css/tooltips.css">
<script src="js/tooltips.js" defer></script>

<style>
    :root {
        --nav-bg: #1a2226;
        --nav-hover: #222d32;
        --nav-text: #9db2ba;
        --nav-active: #ffffff;
        --accent: #3c8dbc;
        --accent-audit: #d81b60;
        --header-shadow: 0 2px 10px rgba(0,0,0,0.4);
    }

    body { margin-top: 60px; }

    .top-navbar {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        height: 60px;
        background-color: var(--nav-bg);
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 20px;
        box-shadow: var(--header-shadow);
        z-index: 1030;
        font-family: 'Source Sans Pro', 'Helvetica Neue', Helvetica, Arial, sans-serif;
        border-bottom: 1px solid rgba(255,255,255,0.05);
    }

    .wartelpas-brand {
        display: flex;
        align-items: center;
        text-decoration: none;
        margin-right: 30px;
        height: 100%;
    }

    .brand-logo {
        height: 50px;
        width: auto;
        object-fit: contain;
    }

    .nav-item { position: relative; }

    .mobile-toggle { display: none; font-size: 24px; color: #fff; cursor: pointer; padding: 10px; }

    .dropdown-item {
        display: block;
        padding: 12px 20px;
        color: var(--nav-text);
        text-decoration: none;
        border-bottom: 1px solid #344248;
        font-size: 13px;
    }

    .dropdown-item:last-child { border-bottom: none; }

    .dropdown-item:hover {
        background-color: #222d32;
        color: #fff;
        padding-left: 25px;
        transition: 0.2s;
    }

    .dropdown-item.audit-item { color: #ffb6c1; }
    .dropdown-item.audit-item:hover { color: #fff; }

    .dropdown-item i { margin-right: 10px; width: 15px; text-align: center; }

    .nav-right { display: flex; align-items: center; gap: 12px; padding-left: 20px; }
    .retur-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 10px;
        border-radius: 12px;
        background: rgba(245, 158, 11, 0.2);
        color: #f59e0b;
        font-size: 11px;
        font-weight: 700;
        border: 1px solid rgba(245, 158, 11, 0.4);
        cursor: pointer;
        white-space: nowrap;
    }
    .retur-pill i { font-size: 11px; }
    .retur-pill-count {
        min-width: 22px;
        height: 22px;
        padding: 0 6px;
        border-radius: 10px;
        background: #f59e0b;
        color: #111827;
        font-weight: 800;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        line-height: 1;
    }
    .retur-pill.is-zero {
        background: rgba(148, 163, 184, 0.18);
        color: #94a3b8;
        border: 1px solid rgba(148, 163, 184, 0.4);
    }
    .retur-pill.is-zero .retur-pill-count {
        background: #94a3b8;
        color: #111827;
    }
    .timer-badge {
        background: rgba(255,255,255,0.05);
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        color: var(--nav-text);
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: 1px solid rgba(255,255,255,0.1);
        white-space: nowrap;
    }
    .db-tools {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        border-radius: 16px;
        background: rgba(255,255,255,0.04);
        border: 1px solid rgba(255,255,255,0.08);
        color: var(--nav-text);
        font-size: 12px;
        text-decoration: none;
        transition: 0.2s;
        white-space: nowrap;
    }
    .db-tools:hover {
        background: rgba(255,255,255,0.08);
        color: #fff;
        transform: translateY(-1px);
    }
    .db-tools i { opacity: 0.8; }
    .db-status {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: rgba(255,255,255,0.06);
        border: 1px solid rgba(255,255,255,0.1);
        color: #8aa2ad;
        font-size: 12px;
        transition: 0.2s;
    }
    .db-status.db-ok { color: #2ecc71; border-color: rgba(46,204,113,0.6); background: rgba(46,204,113,0.15); }
    .db-status.db-error { color: #e74c3c; border-color: rgba(231,76,60,0.6); background: rgba(231,76,60,0.15); }
    .db-status.db-ok i { animation: dbPulse 1.2s ease-in-out infinite; }
    @keyframes dbPulse {
        0% { transform: scale(1); opacity: 0.9; }
        50% { transform: scale(1.15); opacity: 1; }
        100% { transform: scale(1); opacity: 0.9; }
    }

    .logout-btn {
        height: 36px;
        width: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        color: var(--nav-text);
        transition: 0.3s;
        background: transparent;
    }
    .logout-btn:hover {
        background: rgba(216, 27, 96, 0.15);
        color: #ff4d4d;
        transform: scale(1.05);
    }

    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

    #main { margin-left: 0; padding: 20px; }
    .main-container-fluid { width: 100%; }

    /* Override legacy sidebar layout */
    #main { margin-left: 0 !important; margin-top: 60px !important; }
    .sidenav { width: 0 !important; border-right: 0 !important; }
    #openNav, #closeNav, #cpage { display: none !important; }

    @media (max-width: 750px) {
        #brand { display: flex !important; }
    }

    @media (min-width: 993px) {
        .nav-links {
            display: flex; gap: 0; flex-grow: 1; align-items: center;
            list-style: none; margin: 0; padding: 0; height: 100%;
        }
        .nav-item { position: relative; height: 100%; }
        .nav-link {
            display: flex; align-items: center; gap: 8px;
            color: var(--nav-text); text-decoration: none;
            padding: 0 15px; height: 100%;
            font-size: 13.5px; font-weight: 600;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            border-bottom: 3px solid transparent;
            white-space: nowrap;
        }
        .nav-link:hover { background-color: var(--nav-hover); color: var(--nav-active); }
        .nav-link.active { color: var(--nav-active); background-color: var(--nav-hover); border-bottom-color: var(--accent); }
        .nav-link.audit-link { color: #ffb6c1 !important; }
        .nav-link.audit-link.active { border-bottom-color: var(--accent-audit); }

        .dropdown-menu {
            display: none; position: absolute; top: 60px; left: 0;
            background-color: #2c3b41; min-width: 200px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.3);
            border-radius: 0 0 4px 4px; z-index: 1040;
        }
        .nav-item:hover .dropdown-menu { display: block; animation: fadeIn 0.2s; }
    }

    @media (max-width: 992px) {
        .mobile-toggle { display: block; }
        .wartelpas-brand { margin-right: auto; }
        .nav-links {
            display: none;
            flex-direction: column;
            position: absolute; top: 60px; left: 0; right: 0;
            background-color: var(--nav-bg);
            height: auto; padding-bottom: 10px;
            box-shadow: 0 5px 10px rgba(0,0,0,0.5);
            list-style: none; margin: 0; padding: 0;
        }
        .nav-links.show { display: flex !important; }
        .nav-link {
            display: flex; align-items: center; gap: 8px;
            color: var(--nav-text); text-decoration: none;
            padding: 0 20px; height: 45px;
            font-size: 14px; font-weight: 600;
            border-left: 3px solid transparent;
            width: 100%; box-sizing: border-box;
        }
        .nav-link.active { border-left-color: var(--accent); background: #1e282c; color: #fff; }
        .nav-link.audit-link.active { border-left-color: var(--accent-audit); }

        .dropdown-menu { display: none; position: static; background: #1a2226; box-shadow: none; width: 100%; }
        .nav-item.active-mobile .dropdown-menu { display: block; }
    }

</style>

<?php if ($id != "") { ?>
    <nav class="top-navbar">
        <a id="brand" class="wartelpas-brand" href="./admin.php?id=sessions">
            <img src="img/logo.png" alt="MIKHMON" class="brand-logo" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
            <span style="display:none; color:#fff; font-weight:bold; font-size:18px;">WARTELPAS</span>
        </a>

        <div class="mobile-toggle" onclick="toggleMenu()"><i class="fa fa-bars"></i></div>

        <ul class="nav-links" id="mainNav">
            <?php if (isSuperAdmin() && (($id == "settings" && $session == "new") || $id == "settings" || $id == "uplogo" || $id == "connect")): ?>
                <li class="nav-item">
                    <a class="nav-link connect <?= $shome; ?>" id="<?= $session; ?>&c=settings" href="javascript:void(0)">
                        <i class="fa fa-tachometer"></i> <?= $_dashboard ?> (<?= $session; ?>)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $ssettings; ?>" href="./admin.php?id=settings&session=<?= $session; ?>">
                        <i class="fa fa-gear"></i> <?= $_session_settings ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $suplogo; ?>" href="./admin.php?id=uplogo&session=<?= $session; ?>">
                        <i class="fa fa-upload"></i> <?= $_upload_logo ?>
                    </a>
                </li>
            <?php endif; ?>

            <?php if (isSuperAdmin()): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $ssesslist; ?>" href="./admin.php?id=sessions">
                        <i class="fa fa-list"></i> <?= $_admin_settings ?>
                    </a>
                </li>
            <?php endif; ?>
            <?php if (isSuperAdmin()): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $sscripts; ?>" href="./admin.php?id=mikrotik-scripts&session=<?= $session; ?>">
                        <i class="fa fa-code"></i> Script MikroTik
                    </a>
                </li>
            <?php endif; ?>
            <?php if (isSuperAdmin() && !$has_router_session): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $snsettings; ?>" href="./admin.php?id=settings&router=new-<?= rand(1111,9999) ?>">
                        <i class="fa fa-plus"></i> <?= $_add_router ?>
                    </a>
                </li>
            <?php endif; ?>
        </ul>

        <div class="nav-right">
            <?php $is_superadmin = isSuperAdmin(); ?>
            <?php $can_backup = (isOperator() && (operator_can('backup_only') || operator_can('restore_only'))); ?>
            <?php if ($can_backup): ?>
                <?php if (isOperator() && operator_can('backup_only')): ?>
                    <a id="db-backup" class="db-tools" style="display:none" href="javascript:void(0)" title="Backup Database Utama" onclick="runBackupAjax()">
                        <i class="fa fa-database"></i> Backup
                    </a>
                <?php endif; ?>
                <?php if (isOperator() && operator_can('restore_only')): ?>
                    <a id="db-restore" class="db-tools" href="javascript:void(0)" title="Restore Database Utama" onclick="runRestoreAjax()">
                        <i class="fa fa-history"></i> Restore
                    </a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($menu_retur_visible): ?>
                <a class="retur-pill <?= $menu_retur_pending > 0 ? '' : 'is-zero' ?>" id="retur-menu-pill" href="javascript:void(0)" onclick="return openReturMenuPopup(event);" title="Permintaan Retur Pending">
                    <i class="fa fa-undo"></i> Retur
                    <span class="retur-pill-count" id="retur-menu-count"><?= (int)$menu_retur_pending ?></span>
                </a>
            <?php endif; ?>
            <span class="timer-badge" title="Live: <?= htmlspecialchars($last_sync_live_full); ?> | Sales: <?= htmlspecialchars($last_sync_sales_full); ?>">
                <i class="fa fa-clock-o"></i>
                L: <span class="sync-pill <?= $last_sync_live_class; ?> <?= $last_sync_live_blink; ?>"><?= htmlspecialchars($last_sync_live); ?></span>
                S: <span class="sync-pill <?= $last_sync_sales_class; ?> <?= $last_sync_sales_blink; ?>"><?= htmlspecialchars($last_sync_sales); ?></span>
            </span>
            <span id="db-status" class="db-status" title="Kesehatan Database">
                <i class="fa fa-heart"></i>
            </span>
            <?php if ($is_superadmin): ?>
                <a class="logout-btn" href="./admin.php?id=sessions" title="<?= $_admin_settings ?>">
                    <i class="fa fa-gear"></i>
                </a>
            <?php elseif (isOperator()): ?>
                <a class="logout-btn" href="javascript:void(0)" title="Ubah Password" onclick="openPasswordPopup()">
                    <i class="fa fa-gear"></i>
                </a>
            <?php endif; ?>
            <a id="logout" href="./admin.php?id=logout" title="<?= $_logout ?>"><i class="fa fa-sign-out fa-lg"></i></a>
        </div>
    </nav>

<?php } else { ?>
    <nav class="top-navbar">
        <div class="mobile-toggle" onclick="toggleMenu()"><i class="fa fa-bars"></i></div>

        <a id="brand" class="wartelpas-brand" href="./?session=<?= $session; ?>">
            <img src="img/logo.png" alt="MIKHMON" class="brand-logo" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
            <span style="display:none; color:#fff; font-weight:bold; font-size:18px;">WARTELPAS</span>
        </a>

        <ul class="nav-links" id="mainNav">
            <li class="nav-item">
                <a class="nav-link <?= $shome; ?>" href="./?session=<?= $session; ?>">
                    <i class="fa fa-dashboard"></i> <?= $_dashboard ?>
                </a>
            </li>

            <li class="nav-item" onclick="toggleMobileSub(this)">
                <a class="nav-link <?= trim($susersl . ' ' . $sgenuser . ' ' . $suserprofiles . ' ' . $suserprof); ?>" href="javascript:void(0)">
                    <i class="fa fa-users"></i> <?= $_users ?> <i class="fa fa-caret-down" style="margin-left:auto;font-size:10px;"></i>
                </a>
                <div class="dropdown-menu">
                    <a class="dropdown-item" href="./?hotspot=users&profile=all&session=<?= $session; ?>"><i class="fa fa-list"></i> <?= $_user_list ?></a>
                    <a class="dropdown-item" href="./?hotspot-user=generate&session=<?= $session; ?>"><i class="fa fa-user-plus"></i> <?= $_generate ?></a>
                </div>
            </li>


            <li class="nav-item" onclick="toggleMobileSub(this)">
                <a class="nav-link <?= $sactive . $shosts . $scookies; ?>" href="javascript:void(0)">
                    <i class="fa fa-gear"></i> Perangkat <i class="fa fa-caret-down" style="margin-left:auto;font-size:10px;"></i>
                </a>
                <div class="dropdown-menu">
                    <a class="dropdown-item" href="./?hotspot=active&session=<?= $session; ?>"><i class="fa fa-gear"></i> <?= $_hotspot_active ?></a>
                    <a class="dropdown-item" href="./?hotspot=hosts&session=<?= $session; ?>"><i class="fa fa-laptop"></i> <?= $_hosts ?></a>
                    <a class="dropdown-item" href="./?hotspot=cookies&session=<?= $session; ?>"><i class="fa fa-hourglass"></i> <?= $_hotspot_cookies ?></a>
                    <a class="dropdown-item" href="./?hotspot=dhcp-leases&session=<?= $session; ?>"><i class="fa fa-sitemap"></i> <?= $_dhcp_leases ?></a>
                </div>
            </li>

            <li class="nav-item" onclick="toggleMobileSub(this)">
                <a class="nav-link <?= trim($sselling . ' ' . $saudit); ?>" href="javascript:void(0)">
                    <i class="fa fa-money"></i> <?= $_report ?> <i class="fa fa-caret-down" style="margin-left:auto;font-size:10px;"></i>
                </a>
                <div class="dropdown-menu">
                    <a class="dropdown-item" href="./?report=selling&idbl=<?= strtolower(date("M")) . date("Y"); ?>&session=<?= $session; ?>"><i class="fa fa-line-chart"></i> <?= $_report ?></a>
                    <a class="dropdown-item audit-item" href="./?report=audit_session&session=<?= $session; ?>"><i class="fa fa-check-square-o"></i> <?= $_audit_log ?></a>
                </div>
            </li>

            <li class="nav-item">
                <a class="nav-link <?= $swhatsapp; ?>" href="./?report=whatsapp&session=<?= $session; ?>">
                    <i class="fa fa-whatsapp"></i> WhatsApp
                </a>
            </li>
        </ul>

        <div class="nav-right">
            <?php $is_superadmin = isSuperAdmin(); ?>
            <?php $can_backup = (isOperator() && (operator_can('backup_only') || operator_can('restore_only'))); ?>
            <?php if ($can_backup): ?>
                <?php if (isOperator() && operator_can('backup_only')): ?>
                    <a id="db-backup" class="db-tools" style="display:none" href="javascript:void(0)" title="Backup Database Utama" onclick="runBackupAjax()">
                        <i class="fa fa-database"></i> Backup
                    </a>
                <?php endif; ?>
                <?php if (isOperator() && operator_can('restore_only')): ?>
                    <a id="db-restore" class="db-tools" href="javascript:void(0)" title="Restore Database Utama" onclick="runRestoreAjax()">
                        <i class="fa fa-history"></i> Restore
                    </a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($menu_retur_visible): ?>
                <a class="retur-pill <?= $menu_retur_pending > 0 ? '' : 'is-zero' ?>" id="retur-menu-pill" href="javascript:void(0)" onclick="return openReturMenuPopup(event);" title="Permintaan Retur Pending">
                    <i class="fa fa-undo"></i> Retur
                    <span class="retur-pill-count" id="retur-menu-count"><?= (int)$menu_retur_pending ?></span>
                </a>
            <?php endif; ?>
            <span class="timer-badge" title="Live: <?= htmlspecialchars($last_sync_live_full); ?> | Sales: <?= htmlspecialchars($last_sync_sales_full); ?>">
                <i class="fa fa-clock-o"></i> L: <span><?= htmlspecialchars($last_sync_live); ?></span> S: <span><?= htmlspecialchars($last_sync_sales); ?></span>
            </span>
            <span id="db-status" class="db-status" title="Kesehatan Database">
                <i class="fa fa-heart"></i>
            </span>
            <?php if ($is_superadmin): ?>
                <a class="logout-btn" href="./admin.php?id=sessions" title="<?= $_admin_settings ?>">
                    <i class="fa fa-gear"></i>
                </a>
            <?php elseif (isOperator()): ?>
                <a class="logout-btn" href="javascript:void(0)" title="Ubah Password" onclick="openPasswordPopup()">
                    <i class="fa fa-gear"></i>
                </a>
            <?php endif; ?>
            <a id="logout" class="logout-btn" href="./?hotspot=logout&session=<?= $session; ?>" title="<?= $_logout ?>">
                <i class="fa fa-sign-out fa-lg"></i>
            </a>
        </div>
    </nav>

    <?php include('./include/info.php'); ?>

<?php } ?>

<div id="sidenav" style="display:none"></div>
<a id="openNav" href="javascript:void(0)" style="display:none"></a>
<a id="closeNav" href="javascript:void(0)" style="display:none"></a>
<div id="overL" style="display:none"></div>

<style>
    .btn-print.btn-default-dark { background:#343a40; color:#fff; border:1px solid #4b5259; }
    .btn-print.btn-default-dark:hover { background:#3d434a; color:#fff; }
    .sync-pill { display:inline-block; padding:1px 6px; border-radius:10px; font-weight:700; font-size:11px; }
    .sync-ok { color:#16a34a; background:rgba(22,163,74,0.12); }
    .sync-warn { color:#f59e0b; background:rgba(245,158,11,0.12); }
    .sync-late { color:#ef4444; background:rgba(239,68,68,0.15); }
    .blink-slow { animation: syncBlink 2.4s ease-in-out infinite; }
    .blink-fast { animation: syncBlink 1.2s ease-in-out infinite; }
    @keyframes syncBlink { 50% { opacity: 0.35; } }
</style>

<script>
    function toggleMenu() {
        var x = document.getElementById("mainNav");
        if (x.className === "nav-links") {
            x.className += " show";
        } else {
            x.className = "nav-links";
        }
    }

    function toggleMobileSub(el) {
        if (window.innerWidth <= 992) {
            el.classList.toggle("active-mobile");
        }
    }

    window.addEventListener('resize', function() {
        if (window.innerWidth > 992) {
            var x = document.getElementById("mainNav");
            if (x) x.classList.remove("show");
            var items = document.querySelectorAll('.nav-item.active-mobile');
            items.forEach(function(item) {
                item.classList.remove('active-mobile');
            });
        }
    });

    function updateRealTimeBadge() {
        var el = document.getElementById('timer_val');
        var fallback = document.getElementById('timer');
        if (!el && !fallback) return;
        var now = new Date();
        var dateStr = now.toLocaleDateString('id-ID', {
            day: '2-digit',
            month: 'short',
            year: 'numeric'
        });
        var timeStr = now.toLocaleTimeString('id-ID', { hour12: false });
        var full = dateStr + ' ' + timeStr;
        if (el) el.textContent = full;
        if (fallback) fallback.textContent = full;
    }

    window.__isSuperAdminFlag = <?= json_encode($is_superadmin); ?>;
    window.__canBackupFlag = <?= json_encode($is_superadmin || (isOperator() && operator_can('backup_only'))); ?>;
    window.__canRestoreFlag = <?= json_encode($is_superadmin || (isOperator() && operator_can('restore_only'))); ?>;
    window.__returMenuData = <?= json_encode(['count' => $menu_retur_pending, 'items' => $menu_retur_list], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    window.__returBlokNames = <?= json_encode(($env['blok']['names'] ?? []), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    window.__returSession = <?= json_encode($session); ?>;
    window.__backupKey = <?= json_encode($is_superadmin ? $backupKey : '') ?>;


    document.addEventListener('DOMContentLoaded', function(){
        if (window.jQuery) {
            $(".connect").click(function(){
                notify("<?= $_connecting ?>");
                connect(this.id)
            });
            $(".slang").change(function(){
                notify("<?= $_loading ?>");
                stheme(this.value)
            });
        }
        updateRealTimeBadge();
        setInterval(updateRealTimeBadge, 1000);
        if (typeof window.updateDbStatus === 'function') {
            window.updateDbStatus();
            setInterval(window.updateDbStatus, 30000);
        }
        if (typeof window.updateBackupStatus === 'function') {
            window.updateBackupStatus();
            setInterval(window.updateBackupStatus, 60000);
        }
    });
</script>

<div id="notify"><div class="message"></div></div>
<div id="temp"></div>

<div id="ajax-overlay" class="overlay-backdrop">
    <div class="overlay-modal" id="ajax-modal-container">
        <div class="overlay-icon-box">
            <i id="ajax-overlay-icon" class="fa fa-circle-o-notch fa-spin"></i>
        </div>
        <div class="overlay-title" id="ajax-overlay-title">Processing</div>
        <div class="overlay-message" id="ajax-overlay-text">Mohon tunggu sebentar...</div>
        <button id="ajax-overlay-close" type="button" class="overlay-btn" onclick="hideOverlayNotice()">
            Tutup
        </button>
    </div>
</div>

<?php
if ($id != "") {
    include('./info.php');
}
?>

<div id="main">
<?php
    $force_show_main = ($hotspot == 'users');
    $loading_style = $force_show_main ? 'style="display:none"' : '';
    echo "<div id=\"loading\" class=\"lds-dual-ring\" $loading_style></div>";
    if ($hotspot == 'template-editor' || $id == 'editor' || $force_show_main) {
        echo '<div class="main-container main-container-fluid">';
    } else {
        echo '<div class="main-container main-container-fluid" style="display:none">';
    }
?>