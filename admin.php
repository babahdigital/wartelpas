<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
session_start();
// hide all error
error_reporting(0);

// disable cache for admin pages
if (!headers_sent()) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Cache-Control: post-check=0, pre-check=0', false);
  header('Pragma: no-cache');
  header('Expires: 0');
}

require_once __DIR__ . '/include/acl.php';
require_once __DIR__ . '/include/db.php';
app_db_import_legacy_if_needed();

$env = [];
$envFile = __DIR__ . '/include/env.php';
if (file_exists($envFile)) {
  require $envFile;
}
$op_db = app_db_get_operator();
$op_user = $op_db['username'] ?? '';
$op_pass = $op_db['password'] ?? '';
if ($op_user === '' && $op_pass === '') {
  $op_user = $env['auth']['operator_user'] ?? '';
  $op_pass = $env['auth']['operator_pass'] ?? '';
}
$op_override = get_operator_password_override();
if ($op_override !== '') {
  $op_pass = $op_override;
}

ob_start("ob_gzhandler");

// check url
$url = $_SERVER['REQUEST_URI'];
$path = parse_url($url, PHP_URL_PATH);
$basename = $path ? basename($path) : '';

// load session MikroTik
$session = $_GET['session'];
if (!empty($session) && strpos($session, '~') !== false) {
  $session = explode('~', $session)[0];
}
$id = $_GET['id'];
$c = $_GET['c'];
$router = $_GET['router'];
$logo = $_GET['logo'];

if ($id === 'operator-access' && isset($_POST['save'])) {
  include_once('./settings/admin_account_logic.php');
}

if ($id === 'settings' && isset($_POST['save'])) {
  include_once('./settings/settings.php');
  exit;
}

if ($id === 'settings' && empty($session) && !empty($router) && explode('-', $router)[0] === 'new') {
  echo "<script>window.location='./admin.php?id=settings&session=" . $router . "'</script>";
  exit;
}

$ids = array(
  "editor",
  "uplogo",
  "settings",
  "mikrotik-scripts",
);

// lang
include('./lang/isocodelang.php');
include('./include/lang.php');
include('./lang/'.$langid.'.php');

// theme
include('./include/theme.php');
include('./settings/settheme.php');
include('./settings/setlang.php');
if ($_SESSION['theme'] == "") {
    $theme = $theme;
    $themecolor = $themecolor;
  } else {
    $theme = $_SESSION['theme'];
    $themecolor = $_SESSION['themecolor'];
}

// load config
include('./include/config.php');
if (!empty($session) && isset($data[$session]) && is_array($data[$session])) {
  include('./include/readcfg.php');
}

include_once('./lib/routeros_api.class.php');
include_once('./lib/formatbytesbites.php');

$is_admin_content = ($id === 'admin-content');
$is_admin_layout = in_array($id, array('sessions', 'settings', 'mikrotik-scripts', 'operator-access', 'whatsapp'), true);

if ($is_admin_content) {
  if (!isset($_SESSION["mikhmon"])) {
    http_response_code(401);
    exit;
  }
  if (isOperator()) {
    http_response_code(403);
    exit;
  }
  $section = $_GET['section'] ?? '';
  if ($section === 'sessions') {
    include_once('./settings/sessions.php');
  } elseif ($section === 'settings') {
    if (empty($session)) {
      echo "<div class='alert alert-warning'>Pilih sesi terlebih dahulu.</div>";
    } else {
      include_once('./settings/settings.php');
      echo '<script type="text/javascript">document.getElementById("sessname").onkeypress = function(e){var chr = String.fromCharCode(e.which);if (" _!@#$%^&*()+=;|?,~".indexOf(chr) >= 0) return false;};</script>';
    }
  } elseif ($section === 'scripts') {
    if (empty($session)) {
      echo "<div class='alert alert-warning'>Pilih sesi terlebih dahulu.</div>";
    } elseif (file_exists('./settings/mikrotik_scripts.php')) {
      include_once('./settings/mikrotik_scripts.php');
    } else {
      echo "<div class='alert alert-danger'>File settings/mikrotik_scripts.php tidak ditemukan.</div>";
    }
  } elseif ($section === 'operator') {
    include_once('./settings/operator_access.php');
  } elseif ($section === 'whatsapp') {
    include_once('./settings/whatsapp_config.php');
  } else {
    echo "<div class='alert alert-danger'>Konten admin tidak ditemukan.</div>";
  }
  exit;
}

// load html head
include_once('./include/headhtml.php');

ensureRole();
?>
    
<?php
if ($id == "login" || ($basename === 'admin.php' && empty($id))) {

  if (isset($_POST['login'])) {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
      $error = '<div style="width: 100%; padding:5px 0px 5px 0px; border-radius:5px;" class="bg-danger"><i class="fa fa-ban"></i> Alert!<br>Invalid CSRF Token. Refresh halaman dan coba lagi.</div>';
    } else {
    if (function_exists('opcache_invalidate')) {
      @opcache_invalidate(__DIR__ . '/include/config.php', true);
    }
    include('./include/config.php');
    include('./include/readcfg.php');
    $user = $_POST['user'];
    $pass = $_POST['pass'];
    if ($user == $useradm && verify_password_compat($pass, $passadm)) {
      if (!is_password_hash($passadm)) {
        $newHash = hash_password_value($pass);
        update_admin_password_hash($passadm, $newHash);
      }
      $_SESSION["mikhmon"] = $user;
      $_SESSION["mikhmon_level"] = "superadmin";

        // MODIFIKASI: Deteksi Router Otomatis
        $first_session = app_db_first_session_id();

      if ($first_session != "") {
          // Jika ada router, langsung connect ke Dashboard
          echo "<script>window.location='./admin.php?id=connect&session=" . $first_session . "'</script>";
      } else {
          // Jika belum ada router, tetap masuk ke menu Settings
          echo "<script>window.location='./admin.php?id=sessions'</script>";
      }
      // AKHIR MODIFIKASI
    
    } elseif ($op_user !== "" && $op_pass !== "" && $user == $op_user) {
      $op_ok = false;
      if (is_password_hash($op_pass)) {
        $op_ok = password_verify((string)$pass, (string)$op_pass);
      } else {
        $op_ok = hash_equals((string)$op_pass, (string)$pass);
      }
      if ($op_ok && !is_password_hash($op_pass)) {
        $newHash = hash_password_value($pass);
        update_operator_password_hash($newHash);
      }
      if ($op_ok) {
      $_SESSION["mikhmon"] = $user;
      $_SESSION["mikhmon_level"] = "operator";

      $first_session = app_db_first_session_id();

      if ($first_session != "") {
        echo "<script>window.location='./?session=" . $first_session . "'</script>";
      } else {
        echo "<script>window.location='./error.php?code=403'</script>";
      }
      }
    } else {
      $error = '<div style="width: 100%; padding:5px 0px 5px 0px; border-radius:5px;" class="bg-danger"><i class="fa fa-ban"></i> Alert!<br>Invalid username or password.</div>';
    }
    }
  }
  

  include_once('./include/login.php');
} elseif (!isset($_SESSION["mikhmon"])) {
  echo "<script>window.location='./admin.php?id=login'</script>";
} elseif (isOperator() && isMaintenanceEnabled()) {
  aclRedirect(getMaintenanceUrl());
} elseif (isOperator() && $id == "sessions") {
  echo "<script>window.location='./error.php?code=403'</script>";
  exit;
} elseif (isOperator() && in_array($id, array("settings", "uplogo", "editor", "reboot", "shutdown", "remove-session", "remove-logo", "mikrotik-scripts"), true)) {
  echo "<script>window.location='./error.php?code=403'</script>";
  exit;
} elseif (isOperator() && !empty($router) && strpos($router, 'new') !== false) {
  echo "<script>window.location='./error.php?code=403'</script>";
  exit;
} elseif (substr($url, -1) == "/" || substr($url, -4) == ".php") {
  echo "<script>window.location='./admin.php?id=sessions'</script>";

} elseif ($id == "sessions") {
  $_SESSION["connect"] = "";
  include_once('./settings/admin_single.php');
  /*echo '
  <script type="text/javascript">
    document.getElementById("sessname").onkeypress = function(e) {
    var chr = String.fromCharCode(e.which);
    if (" _!@#$%^&*()+=;|?,~".indexOf(chr) >= 0)
        return false;
    };
    </script>';*/
} elseif ($id == "settings" && !empty($session) || $id == "settings" && !empty($router)) {
  if (!empty($router) && explode("-", $router)[0] == "new") {
    include_once('./settings/settings.php');
  } else {
    include_once('./settings/admin_single.php');
  }
} elseif ($id == "mikrotik-scripts" && !empty($session)) {
  include_once('./settings/admin_single.php');
} elseif ($id == "operator-access") {
  include_once('./settings/admin_single.php');
} elseif ($id == "whatsapp") {
  include_once('./settings/admin_single.php');
} elseif ($id == "connect"  && !empty($session)) {
  ini_set("max_execution_time",5);  
  include_once('./include/menu.php');
  $API = new RouterosAPI();
  $API->debug = false;
  if ($API->connect($iphost, $userhost, decrypt($passwdhost))){
    $_SESSION["connect"] = "<b class='text-green'>Connected</b>";
    echo "<script>window.location='./?session=" . $session . "'</script>";
  } else {
    $_SESSION["connect"] = "<b class='text-red'>Not Connected</b>";
    $nl = '\n';
    if ($currency == in_array($currency, $cekindo['indo'])) {
      echo "<script>alert('Mikhmon not connected!".$nl."Silakan periksa kembali IP, User, Password dan port API harus enable.".$nl."Jika menggunakan koneksi VPN, pastikan VPN tersebut terkoneksi.')</script>";
    }else{
      echo "<script>alert('Mikhmon not connected!".$nl."Please check the IP, User, Password and port API must be enabled.')</script>";
    }
    if($c == "settings"){
      echo "<script>window.location='./admin.php?id=settings&session=" . $session . "'</script>";
    }else{
      echo "<script>window.location='./admin.php?id=sessions'</script>";
    }
  }
} elseif ($id == "uplogo"  && !empty($session)) {
  include_once('./include/menu.php');
  include_once('./settings/uplogo.php');
} elseif ($id == "reboot"  && !empty($session)) {
  include_once('./process/reboot.php');
} elseif ($id == "shutdown"  && !empty($session)) {
  include_once('./process/shutdown.php');
} elseif ($id == "remove-session" && $session != "") {
  include_once('./include/menu.php');
  app_db_delete_session($session);
  echo "<script>window.location='./admin.php?id=sessions'</script>";
} elseif ($id == "about") {
  include_once('./include/menu.php');
  include_once('./include/about.php');
} elseif ($id == "logout") {
  include_once('./include/menu.php');
  echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Logout...</b>";
  session_destroy();
  echo "<script>window.location='./admin.php?id=login'</script>";
} elseif ($id == "remove-logo" && $logo != ""  && !empty($session)) {
  include_once('./include/menu.php');
  $logopath = "./img/";
  $remlogo = $logopath . $logo;
  unlink("$remlogo");
  echo "<script>window.location='./admin.php?id=uplogo&session=" . $session . "'</script>";
} elseif ($id == "editor"  && !empty($session)) {
  echo "<script>window.location='./error.php?code=404'</script>";
  exit;
} elseif (empty($id)) {
  echo "<script>window.location='./admin.php?id=sessions'</script>";
} elseif(in_array($id, $ids) && empty($session)){
  echo "<script>window.location='./admin.php?id=sessions'</script>";
}
?>
<?php if (empty($is_admin_layout)): ?>
<script src="js/mikhmon-ui.<?= $theme; ?>.min.js"></script>
<script src="js/mikhmon.js?t=<?= str_replace(" ","_",date("Y-m-d H:i:s")); ?>"></script>
<script src="js/ajax_helper.js"></script>
<?php endif; ?>
<?php include('./include/info.php'); ?>
</body>
</html>