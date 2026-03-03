<?php
session_start();
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {
  $session = $_GET['session'] ?? '';

  include('../include/config.php');
  if ($session === '' || !isset($data[$session])) {
    http_response_code(400);
    exit;
  }

  $iphost = explode('!', $data[$session][1])[1];
  $userhost = explode('@|@', $data[$session][2])[1];
  $passwdhost = explode('#|#', $data[$session][3])[1];
  $curency = explode('&', $data[$session][6])[1];

  include('../include/lang.php');
  include('../lang/'.$langid.'.php');
  include_once('../lib/routeros_api.class.php');

  $API = new RouterosAPI();
  $API->debug = false;

  if (!$API->connect($iphost, $userhost, decrypt($passwdhost))) {
    http_response_code(502);
    exit;
  }

  $uprofname = $_GET['name'] ?? '';
  if ($uprofname !== '') {
    $getprofile = $API->comm('/ip/hotspot/user/profile/print', array('?name' => "$uprofname"));
    if (empty($getprofile) || !isset($getprofile[0]['on-login'])) {
      exit;
    }

    $ponlogin = $getprofile[0]['on-login'];
    $getvalid = $_validity . ' : ' . explode(',', $ponlogin)[3];
    $getprice = explode(',', $ponlogin)[2];
    $getsprice = explode(',', $ponlogin)[4];
    $getlock = '| ' . $_lock_user . ' : ' . explode(',', $ponlogin)[6];

    $price = '';
    if ((int)$getprice !== 0) {
      if ($curency == 'Rp' || $curency == 'rp' || $curency == 'IDR' || $curency == 'idr') {
        $price = '| ' . $_price . ' : ' . $curency . ' ' . number_format($getprice, 0, ',', '.');
      } else {
        $price = '| ' . $_price . ' : ' . $curency . ' ' . number_format($getprice);
      }
    }

    $sprice = '';
    if ((int)$getsprice !== 0) {
      if ($curency == 'Rp' || $curency == 'rp' || $curency == 'IDR' || $curency == 'idr') {
        $sprice = '| ' . $_selling_price . ' : ' . $curency . ' ' . number_format($getsprice, 0, ',', '.');
      } else {
        $sprice = '| ' . $_selling_price . ' : ' . $curency . ' ' . number_format($getsprice);
      }
    }

    echo '<b id="getdata">' . $getvalid . ' ' . $price . ' ' . $sprice . ' ' . $getlock . '</b>';
    echo '<span id="validity">' . explode(',', $ponlogin)[3] . '</span> ';
  }
}
?>
