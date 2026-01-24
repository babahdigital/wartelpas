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

include('./include/version.php');

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

if ($idleto != "disable") {
    $didleto = 'display:inline-block;';
} else {
    $didleto = 'display:none;';
}
?>

<span style="display:none;" id="idto"><?= $idleto; ?></span>
<span style="display:none;" id="timer"></span>

<style>
    :root {
        --nav-bg: #222d32;
        --nav-hover: #1b2428;
        --nav-text: #b8c7ce;
        --nav-active: #fff;
        --accent: #3c8dbc;
        --accent-audit: #d81b60;
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
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        z-index: 1030;
        font-family: 'Source Sans Pro', sans-serif;
    }

    .wartelpas-brand {
        display: flex;
        align-items: center;
        font-weight: 700;
        font-size: 0;
        line-height: 0;
        color: transparent !important;
        text-decoration: none;
        margin-right: 40px;
    }

    .wartelpas-brand::before {
        content: "";
        display: block;
        height: 90px;
        width: 150px;
        background-image: url('img/logo.png');
        background-size: contain;
        background-repeat: no-repeat;
        background-position: center;
        margin-right: 10px;
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

    .dropdown-item i { margin-right: 10px; width: 15px; text-align: center; }

    .nav-right { display: flex; align-items: center; gap: 15px; }
    .nav-right a { color: var(--nav-text); text-decoration: none; font-size: 14px; transition: 0.3s; }
    .nav-right a:hover { color: #fff; }
    .timer-badge { background: #374850; padding: 5px 10px; border-radius: 4px; font-size: 12px; color: #fff; display: inline-flex; align-items: center; gap: 5px; }

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
            display: flex; gap: 5px; flex-grow: 1; align-items: center;
            list-style: none; margin: 0; padding: 0;
        }
        .nav-link {
            display: flex; align-items: center; gap: 8px;
            color: var(--nav-text); text-decoration: none;
            padding: 0 15px; height: 60px;
            font-size: 14px; font-weight: 600;
            transition: all 0.3s ease;
            border-top: 3px solid transparent;
        }
        .nav-link:hover { background-color: var(--nav-hover); color: #fff; }
        .nav-link.active { color: #fff; background-color: var(--nav-hover); border-top-color: var(--accent); }
        .nav-link.audit-link.active { border-top-color: var(--accent-audit); }

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
        <a id="brand" class="wartelpas-brand" href="javascript:void(0)">MIKHMON</a>

        <div class="mobile-toggle" onclick="toggleMenu()"><i class="fa fa-bars"></i></div>

        <ul class="nav-links" id="mainNav">
            <?php if (($id == "settings" && $session == "new") || $id == "settings" || $id == "editor" || $id == "uplogo" || $id == "connect"): ?>
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
                    <a class="nav-link <?= $seditor; ?>" href="./admin.php?id=editor&template=default&session=<?= $session; ?>">
                        <i class="fa fa-edit"></i> <?= $_template_editor ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $suplogo; ?>" href="./admin.php?id=uplogo&session=<?= $session; ?>">
                        <i class="fa fa-upload"></i> <?= $_upload_logo ?>
                    </a>
                </li>
            <?php endif; ?>

            <li class="nav-item">
                <a class="nav-link <?= $ssesslist; ?>" href="./admin.php?id=sessions">
                    <i class="fa fa-list"></i> <?= $_admin_settings ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $snsettings; ?>" href="./admin.php?id=settings&router=new-<?= rand(1111,9999) ?>">
                    <i class="fa fa-plus"></i> <?= $_add_router ?>
                </a>
            </li>
        </ul>

        <div class="nav-right">
            <select class="slang" onchange="stheme(this.value)" style="background:#374850;color:#fff;border:none;padding:5px;border-radius:4px;">
                <option><?= $language ?></option>
                <?php
                    $fileList = glob('lang/*');
                    foreach ($fileList as $filename) {
                        if (is_file($filename)) {
                            $fname = substr(explode("/", $filename)[1], 0, -4);
                            if ($fname != "isocodelang") {
                                echo '<option value="'.$url.'&setlang=' . $fname . '">'. $isocodelang[$fname]. '</option>';
                            }
                        }
                    }
                ?>
            </select>
            <a id="logout" href="./admin.php?id=logout" title="<?= $_logout ?>"><i class="fa fa-sign-out fa-lg"></i></a>
        </div>
    </nav>

<?php } else { ?>
    <nav class="top-navbar">
        <a id="brand" class="wartelpas-brand" href="./?session=<?= $session; ?>">MIKHMON</a>

        <div class="mobile-toggle" onclick="toggleMenu()"><i class="fa fa-bars"></i></div>

        <ul class="nav-links" id="mainNav">
            <li class="nav-item">
                <a class="nav-link <?= $shome; ?>" href="./?session=<?= $session; ?>">
                    <i class="fa fa-dashboard"></i> <?= $_dashboard ?>
                </a>
            </li>

            <li class="nav-item" onclick="toggleMobileSub(this)">
                <a class="nav-link <?= $susers; ?>" href="javascript:void(0)">
                    <i class="fa fa-users"></i> <?= $_users ?> <i class="fa fa-caret-down" style="margin-left:5px;font-size:10px;"></i>
                </a>
                <div class="dropdown-menu">
                    <a class="dropdown-item" href="./?hotspot=users&profile=all&session=<?= $session; ?>"><i class="fa fa-list"></i> <?= $_user_list ?></a>
                    <a class="dropdown-item" href="./?hotspot-user=generate&session=<?= $session; ?>"><i class="fa fa-user-plus"></i> <?= $_generate ?></a>
                </div>
            </li>

            <li class="nav-item" onclick="toggleMobileSub(this)">
                <a class="nav-link <?= $suserprof; ?>" href="javascript:void(0)">
                    <i class="fa fa-pie-chart"></i> <?= $_user_profile ?> <i class="fa fa-caret-down" style="margin-left:5px;font-size:10px;"></i>
                </a>
                <div class="dropdown-menu">
                    <a class="dropdown-item" href="./?hotspot=user-profiles&session=<?= $session; ?>"><i class="fa fa-list"></i> <?= $_user_profile_list ?></a>
                    <a class="dropdown-item" href="./?user-profile=add&session=<?= $session; ?>"><i class="fa fa-plus-square"></i> <?= $_add_user_profile ?></a>
                </div>
            </li>

            <li class="nav-item" onclick="toggleMobileSub(this)">
                <a class="nav-link <?= $sactive . $shosts . $scookies; ?>" href="javascript:void(0)">
                    <i class="fa fa-wifi"></i> Hotspot <i class="fa fa-caret-down" style="margin-left:5px;font-size:10px;"></i>
                </a>
                <div class="dropdown-menu">
                    <a class="dropdown-item" href="./?hotspot=active&session=<?= $session; ?>"><i class="fa fa-wifi"></i> <?= $_hotspot_active ?></a>
                    <a class="dropdown-item" href="./?hotspot=hosts&session=<?= $session; ?>"><i class="fa fa-laptop"></i> <?= $_hosts ?></a>
                    <a class="dropdown-item" href="./?hotspot=cookies&session=<?= $session; ?>"><i class="fa fa-hourglass"></i> <?= $_hotspot_cookies ?></a>
                    <a class="dropdown-item" href="./?hotspot=dhcp-leases&session=<?= $session; ?>"><i class="fa fa-sitemap"></i> <?= $_dhcp_leases ?></a>
                </div>
            </li>

            <li class="nav-item">
                <a class="nav-link <?= $sselling; ?>" href="./?report=selling&idbl=<?= strtolower(date("M")) . date("Y"); ?>&session=<?= $session; ?>">
                    <i class="fa fa-money"></i> <?= $_report ?>
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link audit-link <?= $saudit; ?>" href="./?report=audit_session&session=<?= $session; ?>" style="color:#ffb6c1;">
                    <i class="fa fa-check-square-o"></i> <?= $_audit_log ?>
                </a>
            </li>
        </ul>

        <div class="nav-right">
            <span class="timer-badge" style="<?= $didleto; ?>" title="Idle Timeout">
                <i class="fa fa-clock-o"></i> <span id="timer"></span>
            </span>
            <a id="logout" href="./?hotspot=logout&session=<?= $session; ?>" title="<?= $_logout ?>">
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

    $(document).ready(function(){
        $(".connect").click(function(){
            notify("<?= $_connecting ?>");
            connect(this.id)
        });
        $(".slang").change(function(){
            notify("<?= $_loading ?>");
            stheme(this.value)
        });
    });
</script>

<div id="notify"><div class="message"></div></div>
<div id="temp"></div>

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