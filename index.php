<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * OPTIMIZED: AUTO-REDIRECT & SECURE SESSION (WartelPas)
 * Update: 2026 - Pak Dul Requests (FIX ROUTING & SEARCH BUG)
 */

// === SECURITY HEADER & COOKIE PARAMETERS ===
ini_set('session.cookie_httponly', 1); 
ini_set('session.use_only_cookies', 1);

session_start();
error_reporting(0);
if (isset($_SERVER['HTTP_ACCEPT_ENCODING']) && substr_count($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip')) {
    ob_start("ob_gzhandler"); 
} else {
    ob_start();
}

$url = $_SERVER['REQUEST_URI'];
$session = isset($_GET['session']) ? $_GET['session'] : "";

// load config
include('./include/config.php'); 

// === LOGIC REDIRECT & LOGIN ===
if (!isset($_SESSION["mikhmon"])) {
  header("Location:./admin.php?id=login");
  exit();

} elseif (empty($session)) {
  $target_session = "";
  foreach ($data as $key => $value) {
    if ($key !== 'mikhmon') {
      $target_session = $key;
      break; 
    }
  }

  if (!empty($target_session)) {
    $query = [];
    if (!empty($_SERVER['QUERY_STRING'])) {
      parse_str($_SERVER['QUERY_STRING'], $query);
      unset($query['session']);
    }
    $qs = http_build_query($query);
    $redirect = "./?session=" . $target_session;
    if (!empty($qs)) {
      $redirect .= "&" . $qs;
    }
    header("Location:" . $redirect);
    exit();
  } else {
    echo "<script>window.location='./admin.php?id=sessions'</script>";
    exit();
  }

} else {
  $_SESSION["$session"] = $session;
  $setsession = $_SESSION["$session"];
  $_SESSION["connect"] = "";

  date_default_timezone_set($_SESSION['timezone']);

  include('./include/lang.php');
  include('./lang/'.$langid.'.php');
  include('./include/quickbt.php');
  include('./include/readcfg.php');
  include('./include/theme.php');
  include('./settings/settheme.php');
  
  if ($_SESSION['theme'] == "") {
    $theme = $theme;
    $themecolor = $themecolor;
  } else {
    $theme = $_SESSION['theme'];
    $themecolor = $_SESSION['themecolor'];
  }

  // routeros api
  include_once('./lib/routeros_api.class.php');
  include_once('./lib/formatbytesbites.php');
  $API = new RouterosAPI();
  $API->debug = false;
  $API->timeout = 5; // hindari halaman menggantung jika router lambat
  $API->attempts = 1;
  if (!$API->connect($iphost, $userhost, decrypt($passwdhost))) {
    echo "<div style='padding:16px; background:#2b2f36; color:#e74c3c; font-family:monospace'>".
         "Gagal konek ke MikroTik ($iphost). Periksa IP/username/password atau koneksi jaringan.".
         "</div>";
    exit();
  }

  $getidentity = $API->comm("/system/identity/print");
  $identity = $getidentity[0]['name'];

  // get variable
  $hotspot = isset($_GET['hotspot']) ? $_GET['hotspot'] : "";
  $hotspotuser = isset($_GET['hotspot-user']) ? $_GET['hotspot-user'] : "";
  $userbyname = isset($_GET['hotspot-user']) ? $_GET['hotspot-user'] : "";
  $removeuseractive = isset($_GET['remove-user-active']) ? $_GET['remove-user-active'] : "";
  $removehost = isset($_GET['remove-host']) ? $_GET['remove-host'] : "";
  $removecookie = isset($_GET['remove-cookie']) ? $_GET['remove-cookie'] : "";
  $removeipbinding = isset($_GET['remove-ip-binding']) ? $_GET['remove-ip-binding'] : "";
  $removehotspotuser = isset($_GET['remove-hotspot-user']) ? $_GET['remove-hotspot-user'] : "";
  $removehotspotusers = isset($_GET['remove-hotspot-users']) ? $_GET['remove-hotspot-users'] : "";
  $removeuserprofile = isset($_GET['remove-user-profile']) ? $_GET['remove-user-profile'] : "";
  $resethotspotuser = isset($_GET['reset-hotspot-user']) ? $_GET['reset-hotspot-user'] : "";
  $removehotspotuserbycomment = isset($_GET['remove-hotspot-user-by-comment']) ? $_GET['remove-hotspot-user-by-comment'] : "";
  $removeexpiredhotspotuser = isset($_GET['remove-hotspot-user-expired']) ? $_GET['remove-hotspot-user-expired'] : "";
  $enablehotspotuser = isset($_GET['enable-hotspot-user']) ? $_GET['enable-hotspot-user'] : "";
  $disablehotspotuser = isset($_GET['disable-hotspot-user']) ? $_GET['disable-hotspot-user'] : "";
  $enableipbinding = isset($_GET['enable-ip-binding']) ? $_GET['enable-ip-binding'] : "";
  $disableipbinding = isset($_GET['disable-ip-binding']) ? $_GET['disable-ip-binding'] : "";
  $userprofile = isset($_GET['user-profile']) ? $_GET['user-profile'] : "";
  $userprofilebyname = isset($_GET['user-profile']) ? $_GET['user-profile'] : "";
  $sys = isset($_GET['system']) ? $_GET['system'] : "";
  $enablesch = isset($_GET['enable-scheduler']) ? $_GET['enable-scheduler'] : "";
  $disablesch = isset($_GET['disable-scheduler']) ? $_GET['disable-scheduler'] : "";
  $removesch = isset($_GET['remove-scheduler']) ? $_GET['remove-scheduler'] : "";
  $macbinding = isset($_GET['mac']) ? $_GET['mac'] : "";
  $ipbinding = isset($_GET['addr']) ? $_GET['addr'] : "";
  $ppp = isset($_GET['ppp']) ? $_GET['ppp'] : "";
  $secretbyname = isset($_GET['secret']) ? $_GET['secret'] : "";
  $enablesecr = isset($_GET['enable-pppsecret']) ? $_GET['enable-pppsecret'] : "";
  $disablesecr = isset($_GET['disable-pppsecret']) ? $_GET['disable-pppsecret'] : "";
  $removesecr = isset($_GET['remove-pppsecret']) ? $_GET['remove-pppsecret'] : "";
  $removepprofile = isset($_GET['remove-pprofile']) ? $_GET['remove-pprofile'] : "";
  $removepactive = isset($_GET['remove-pactive']) ? $_GET['remove-pactive'] : "";
  $srv = isset($_GET['srv']) ? $_GET['srv'] : "";
  $prof = isset($_GET['profile']) ? $_GET['profile'] : "";
  $comm = isset($_GET['comment']) ? $_GET['comment'] : "";
  $serveractive = isset($_GET['server']) ? $_GET['server'] : "";
  $report = isset($_GET['report']) ? $_GET['report'] : "";
  $removereport = isset($_GET['remove-report']) ? $_GET['remove-report'] : "";
  $minterface = isset($_GET['interface']) ? $_GET['interface'] : "";

  $pagehotspot = array('users','hosts','ipbinding','cookies','log','dhcp-leases');
  $pageppp = array('secrets','profiles','active',);
  $pagereport = array('userlog','selling','livereport','resume-report','export');


  include_once('./include/menu.php');

  $disable_sci = '<script>
  document.getElementById("comment").onkeypress = function(e) {
    var chr = String.fromCharCode(e.which);
    if (" _!@#$%^&*()+=;|?,.~".indexOf(chr) >= 0)
        return false;
};
</script>';

// logout
  if ($hotspot == "logout") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Logout...</b>";
    session_destroy();
    echo "<script>sessionStorage.clear();</script>";
    echo "<script>window.location='./admin.php?id=login'</script>";
  }
// redirect to home (Universal Fallback)
  elseif ($hotspot == "dashboard" || ($hotspot == "" && $hotspotuser == "" && $userprofile == "" && $removeuserprofile == "" && $report == "" && $removereport == "" && !empty($session))) {
    include_once('./dashboard/home.php');
    $_SESSION['ubn'] = "";
  }
// hotspot log
  elseif ($hotspot == "log") {
    include_once('./hotspot/log.php');
  }
// hotspot log
  elseif ($report == "userlog") {
    include_once('./report/userlog.php');
  }
// about
  elseif ($hotspot == "about") {
    include_once('./include/about.php');
  }
  
  // --- [PERBAIKAN] UNIFIED ROUTER UNTUK USERS ---
  // Menggabungkan semua logika Users menjadi satu pintu agar Filter/Search tidak error
  elseif ($hotspot == "users") {
    // Session filter hanya disimpan jika ada di GET, jika tidak, biarkan kosong
    // Ini memperbaiki konflik session lama
    if(isset($_GET['profile']) && $_GET['profile'] != 'all') { $_SESSION['ubp'] = $_GET['profile']; } else { $_SESSION['ubp'] = ""; }
    if(isset($_GET['comment'])) { $_SESSION['ubc'] = $_GET['comment']; } 
    
    $_SESSION['hua'] = "";
    $_SESSION['vcr'] = "";
    include_once('./hotspot/users.php');
  }
  // ---------------------------------------------

// hotspot by profile (View Only)
  elseif ($hotspot == "users-by-profile") {
    $_SESSION['ubp'] = "";
    $_SESSION['hua'] = "";
    $_SESSION['ubc'] = "";
    $_SESSION['vcr'] = "active";
    include_once('./hotspot/userbyprofile.php');
  }
// hotspot add users
  elseif ($hotspot == "add-user") {
    $_SESSION['hua'] = "";
    include_once('./hotspot/adduser.php');
  }
// export hotspot users
  elseif ($hotspot == "export-users") {
    include_once('./hotspot/exportusers.php');
  }
// quick print
  elseif ($hotspot == "quick-print") {
    include_once('./hotspot/quickprint.php');
  }
// quick print
  elseif ($hotspot == "list-quick-print") {
    include_once('./hotspot/listquickprint.php');
  }  
// add hotspot user
  elseif ($hotspotuser == "add") {
    include_once('./hotspot/adduser.php');
    echo $disable_sci;
  }
// add hotspot user
  elseif ($hotspotuser == "generate") {
    include_once('./hotspot/generateuser.php');
    echo $disable_sci;
  }
// hotspot users filter by name
  elseif (substr($hotspotuser, 0, 1) == "*") {
    $_SESSION['ubn'] = $hotspotuser;
    $_SESSION['hua'] = "";
    include_once('./hotspot/userbyname.php');
  } elseif ($hotspotuser != "") {
    $_SESSION['ubn'] = $hotspotuser;
    include_once('./hotspot/userbyname.php');
  }
// remove hotspot user
  elseif ($removehotspotuser != "" || $removehotspotusers != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";
    include_once('./process/removehotspotuser.php');
  }
// remove hotspot user by comment
  elseif ($removehotspotuserbycomment != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";
    include_once('./process/removehotspotuserbycomment.php');
  }
// remove expired hotspot user
  elseif ($removeexpiredhotspotuser != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";
    include_once('./process/removeexpiredhotspotuser.php');
  }  
// reset hotspot user
  elseif ($resethotspotuser != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";
    include_once('./process/resethotspotuser.php');
  }
// enable hotspot user
  elseif ($enablehotspotuser != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";
    include_once('./process/enablehotspotuser.php');
  }
// disable hotspot user
  elseif ($disablehotspotuser != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";
    include_once('./process/disablehotspotuser.php');
  }
// user profile
  elseif ($hotspot == "user-profiles") {
    include_once('./hotspot/userprofile.php');
  }
// add  user profile
  elseif ($userprofile == "add") {
    include_once('./hotspot/adduserprofile.php');
  }
// User profile by name
  elseif (substr($userprofile, 0, 1) == "*") {
    include_once('./hotspot/userprofilebyname.php');
  } elseif ($userprofile != "") {
    include_once('./hotspot/userprofilebyname.php');
  }
// remove user profile
  elseif ($removeuserprofile != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";
    include_once('./process/removeuserprofile.php');
  }
// hotspot active
  elseif ($hotspot == "active") {
    $_SESSION['ubp'] = "";
    $_SESSION['hua'] = "hotspotactive";
    $_SESSION['ubc'] = "";
    include_once('./hotspot/hotspotactive.php');
  }
// dhcp leases
  elseif ($hotspot == "dhcp-leases") {
    include_once('./dhcp/dhcpleases.php');
  }
// traffic monitor
  elseif ($minterface == "traffic-monitor") {
  include_once('./traffic/trafficmonitor.php');
}
// hotspot hosts
  elseif ($hotspot == "hosts" || $hotspot == "hostp" || $hotspot == "hosta") {
    include_once('./hotspot/hosts.php');
  }
// hotspot bindings
  elseif ($hotspot == "binding") {
    include_once('./hotspot/binding.php');
  }
// template editor
  elseif ($hotspot == "template-editor") {
    include_once('./settings/vouchereditor.php');
  }
// upload logo
  elseif ($hotspot == "uplogo") {
    include_once('./settings/uplogo.php');
  }
// hotspot Cookies
  elseif ($hotspot == "cookies") {
    include_once('./hotspot/cookies.php');
  }
// remove hotspot Cookies
  elseif ($removecookie != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";
    include_once('./process/removecookie.php');
  }
// hotspot Ip Bindings
  elseif ($hotspot == "ipbinding") {
    include_once('./hotspot/ipbinding.php');
  }
// remove enable disable ipbinding
  elseif ($removeipbinding != "" || $enableipbinding != "" || $disableipbinding != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";
    include_once('./process/pipbinding.php');
  }
// remove user active
  elseif ($removeuseractive != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";
    include_once('./process/removeuseractive.php');
  }
// remove host
  elseif ($removehost != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";
    include_once('./process/removehost.php');
  }
// makebinding
  elseif ($macbinding != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";
    include_once('./process/makebinding.php');
  }
// selling
  elseif ($report == "selling") {
    include_once('./report/selling.php');
  }
// live report
  elseif ($report == "livereport") {
    include_once('./report/livereport.php');
  }
// === AUDIT SESSION ===
  elseif ($report == "audit_session") {
      include_once('./report/audit.php');
  }
// =====================
// selling
elseif ($report == "resume-report") {
  include_once('./report/resumereport.php');
}
// selling
elseif ($report == "export") {
  include_once('./report/export.php');
}
// selling
  elseif ($removereport != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";
    include_once('./process/removereport.php');
  }
// ppp secret
  elseif ($ppp == "secrets") {
    include_once('./ppp/pppsecrets.php');
  }
// ppp addsecret
  elseif ($ppp == "addsecret") {
    include_once('./ppp/addsecret.php');
  }
// ppp secretbyname
  elseif ($secretbyname != "") {
    include_once('./ppp/secretbyname.php');
  }
// remove enable disable secret
  elseif ($removesecr != "" || $enablesecr != "" || $disablesecr != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";
    include_once('./process/psecret.php');
  }
// ppp profile
  elseif ($ppp == "profiles") {
    include_once('./ppp/pppprofile.php');
  }
// add ppp profile
  elseif ($ppp == "add-profile") {
    include_once('./ppp/addpppprofile.php');
  }
// add ppp profile
elseif ($ppp == "edit-profile") {
  include_once('./ppp/profilebyname.php');
}
// remove enable disable profile
  elseif ($removepprofile != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";
    include_once('./process/removepprofile.php');
  }
// ppp active connection
  elseif ($ppp == "active") {
    include_once('./ppp/pppactive.php');
  }
// remove ppp active connection
  elseif ($removepactive != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";
    include_once('./process/removepactive.php');
  }
// sys scheduler
  elseif ($sys == "scheduler") {
    include_once('./system/scheduler.php');
  }
// remove enable disable scheduler
  elseif ($removesch != "" || $enablesch != "" || $disablesch != "") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";
    include_once('./process/pscheduler.php');
  }
  ?>
</div>
</div>
</div>
<script src="./js/highcharts/highcharts.js"></script>
<script src="./js/highcharts/themes/hc.<?= $theme; ?>.js"></script>
<script src="./js/mikhmon-ui.<?= $theme; ?>.min.js"></script>
<script src="./js/mikhmon.js?t=<?= str_replace(" ","_",date("Y-m-d H:i:s")); ?>"></script>

<?php
if ($hotspot == "dashboard" || ($hotspot == "" && $hotspotuser == "" && $userprofile == "" && $removeuserprofile == "" && $report == "" && $removereport == "" && !empty($session))) {
  echo '<script>
    if (window.history.replaceState) { window.history.replaceState(null, null, "./?session=' . $session . '"); }
    $("#r_3").load("./dashboard/aload.php?session=' . $session . '&load=logs #r_3");  
    var interval1 = "' . ($areload * 1000) . '";
    var dashboard = setInterval(function() {
    $("#r_1").load("./dashboard/aload.php?session=' . $session . '&load=sysresource #r_1"); 
    $("#r_2").load("./dashboard/aload.php?session=' . $session . '&load=hotspot #r_2"); 
    $("#r_3").load("./dashboard/aload.php?session=' . $session . '&load=logs #r_3"); 
  }, interval1);
';
if ($livereport == "enable" || $livereport == "") {
  if(isset($_SESSION[$session.'sdate']) && isset($_SESSION[$session.'idhr']) && $_SESSION[$session.'sdate'] != $_SESSION[$session.'idhr']){
    $_SESSION[$session.'totalHr'] = "0";
    echo '$("#r_4").load("./report/livereport.php?session=' . $session . ' #r_4");';
    }else if (isset($_SESSION[$session.'sdate']) && isset($_SESSION[$session.'idhr']) && $_SESSION[$session.'sdate'] == $_SESSION[$session.'idhr']){  
    }else{ echo '$("#r_4").load("./report/livereport.php?session=' . $session . ' #r_4");'; }
  echo  'var interval2 = "65432"; var livereport = setInterval(function() { $("#r_4").load("./report/livereport.php?session=' . $session . ' #r_4"); }, interval2);';}
  echo 'function cancelPage(){ window.stop(); clearInterval(dashboard);';
    if ($livereport == "enable" || $livereport == "") { echo 'clearInterval(livereport);'; }
  echo '} </script>';
} elseif ($hotspot == "active" && $serveractive != "") {
  echo '<script> $(document).ready(function(){ var interval = "' . ($areload * 1000) . '"; setInterval(function() { $("#reloadHotspotActive").load("./hotspot/hotspotactive.php?server=' . $serveractive . '&session=' . $session . '"); }, interval);}) </script>';
} elseif ($hotspot == "active" && $serveractive == "") {
  echo '<script> $(document).ready(function(){ var interval = "' . ($areload * 1000) . '"; setInterval(function() { $("#reloadHotspotActive").load("./hotspot/hotspotactive.php?session=' . $session . '"); }, interval);}) </script>';
} elseif ($userprofile == "add" || substr($userprofile, 0, 1) == "*" || $userprofile != "") {
  echo "<script> $(document).ready(function(){ var exp = document.getElementById('expmode').value; var val = document.getElementById('validity').style; var vali = document.getElementById('validi'); if (exp === 'rem' || exp === 'remc') { val.display= 'table-row'; vali.type = 'text'; $('#validi').focus(); } else if (exp === 'ntf' || exp === 'ntfc') { val.display = 'table-row'; vali.type = 'text'; $('#validi').focus(); } else { val.display = 'none'; vali.type = 'hidden'; } }); </script>";
} elseif (in_array($hotspot, $pagehotspot) || in_array($ppp, $pageppp) || in_array($report, $pagereport) || $sys == "scheduler") {
echo '<script> $(document).ready(function(){ makeAllSortable(); $("#filterTable").on("keyup", function() { var value = $(this).val().toLowerCase(); $("#dataTable tbody tr").filter(function() { $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1) }); }); }); </script>';
}
}
?>
</body>
</html>