<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * Modified by Pak Dul & Gemini AI (2026) - Wartel Edition
 * UPDATE: AUTO-FILTER USED VOUCHERS (Valid/Expired Hidden)
 */
session_start();
error_reporting(0);
ob_start("ob_gzhandler");

if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {
  
  date_default_timezone_set($_SESSION['timezone']);
  $session = $_GET['session'];

  include('../include/config.php');
  include('../include/readcfg.php');
  include('../lib/formatbytesbites.php');

  $id = $_GET['id'];       // Ini nama BLOK/GRUP yang dipilih
  $qr = $_GET['qr'];
  $small = $_GET['small'];
  $userp = $_GET['user'];

  require('../lib/routeros_api.class.php');
  $API = new RouterosAPI();
  $API->debug = false;
  $API->connect($iphost, $userhost, decrypt($passwdhost));

  // --- LOGIKA PENCARIAN & FILTER ---

  $count_hidden = 0; // Menghitung voucher yang disembunyikan

  if ($userp != "") {
    // A. PRINT SATUAN (BY NAME) - Tidak di-filter karena user minta spesifik
    $pulluser = explode('-', $userp);
    $iuser = count($pulluser);
    $prefix = explode('-', $userp)[$iuser - 2];
    $user = explode('-', $userp)[$iuser - 1];
    
    if ($iuser >= 3) {
        $user = $prefix . "-" . $user;
    }
    
    $getuser = $API->comm("/ip/hotspot/user/print", array("?name" => "$user"));
    $TotalReg = count($getuser);
    $usermode = explode('-', $userp)[0];
    
  } elseif ($id != "") {
    // B. PRINT PER BLOK (SMART SEARCH + FILTER)
    $usermode = "vc"; 

    // 1. Ambil Data Mentah (Cari Persis Dulu)
    $raw_users = $API->comm('/ip/hotspot/user/print', array("?comment" => "$id"));
    
    // 2. Jika Kosong, Cari Partial Match (yg mengandung kata)
    if (count($raw_users) == 0) {
        $all_users = $API->comm('/ip/hotspot/user/print', array("?server" => "wartel"));
        foreach ($all_users as $u) {
            if (isset($u['comment']) && stripos($u['comment'], $id) !== false) {
                $raw_users[] = $u;
            }
        }
    }
    
    // 3. --- [FILTER PENTING] --- HAPUS VOUCHER BEKAS (Valid: / Exp)
    $getuser = array();
    foreach ($raw_users as $u) {
        $u_comment = isset($u['comment']) ? $u['comment'] : "";
        $u_uptime  = isset($u['limit-uptime']) ? $u['limit-uptime'] : "";
        
        // Cek Tanda-tanda Bekas Pakai
        $is_used = (stripos($u_comment, 'Valid:') !== false);
        $is_exp  = (stripos($u_comment, 'exp') !== false || $u_uptime == '1s');
        
        if (!$is_used && !$is_exp) {
            $getuser[] = $u; // Masukkan ke daftar cetak jika BERSIH
        } else {
            $count_hidden++; // Hitung yang dibuang
        }
    }
    
    $TotalReg = count($getuser);
  }
  
  // --- AMBIL PROFILE ---
  if ($TotalReg > 0) {
      $getuprofile = $getuser[0]['profile'];
      $getprofile = $API->comm("/ip/hotspot/user/profile/print", array("?name" => "$getuprofile"));
      
      if (count($getprofile) > 0) {
          $ponlogin = isset($getprofile[0]['on-login']) ? $getprofile[0]['on-login'] : '';
          $parts = explode(",", $ponlogin);
          $getprice = isset($parts[2]) ? $parts[2] : '0';
          $getsprice = isset($parts[4]) ? $parts[4] : '0';
      } else {
          $getprice = "0"; $getsprice = "0";
      }
  } else {
      $getprice = "0"; $getsprice = "0"; $getuprofile = "Unknown";
  }

  // --- FORMAT HARGA ---
  if($getsprice == "0" && $getprice != "0"){
      if ($currency == in_array($currency, $cekindo['indo'])) {
        $price = $currency . " " . number_format((float)$getprice, 0, ",", ".");
      } else {
        $price = $currency . " " . number_format((float)$getprice, 2);
      }
  }else if($getsprice != "0"){
      if ($currency == in_array($currency, $cekindo['indo'])) {
        $price = $currency . " " . number_format((float)$getsprice, 0, ",", ".");
      } else {
        $price = $currency . " " . number_format((float)$getsprice, 2);
      }
  }else {
      $price = "";
  }

  $logo = "../img/logo-" . $session . ".png";
  if (!file_exists($logo)) {
    $logo = "../img/logo.png";
  }
  $logo .= "?t=" . time(); 
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Voucher-<?= $hotspotname . "-" . $getuprofile . "-" . $id; ?></title>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta http-equiv="pragma" content="no-cache" />
    <link rel="icon" href="../img/favicon.png" />
    <script src="../js/qrious.min.js"></script>
    <style>
        body { color: #000; background: #fff; font-size: 14px; font-family: 'Helvetica', arial, sans-serif; margin: 0; }
        table.voucher { display: inline-block; border: 2px solid black; margin: 2px; }
        @page { size: auto; margin: 5mm; }
        @media print {
          table { page-break-after:auto }
          tr { page-break-inside:avoid; page-break-after:auto }
          td { page-break-inside:avoid; page-break-after:auto }
          .no-print { display: none !important; }
        }
        .qrc { width:30px; height:30px; margin-top:1px; }
        .info-header { background:#f8d7da; color:#721c24; padding:10px; text-align:center; margin-bottom:10px; border:1px solid #f5c6cb; }
    </style>
</head>
<body onload="window.print()">

<?php 
// INFO JIKA KOSONG ATAU ADA YANG DI-FILTER
if ($TotalReg == 0) {
    echo "<div style='text-align:center; padding-top:50px;'>";
    echo "<h3 style='color:red;'>Stok Habis / Data Kosong!</h3>";
    if ($count_hidden > 0) {
        echo "<p>Ditemukan <b>$count_hidden voucher</b> di grup <strong>$id</strong>, tetapi semuanya sudah <b>Terpakai/Expired</b>.</p>";
    } else {
        echo "<p>Tidak ditemukan data dengan kata kunci: <strong>$id</strong></p>";
    }
    echo "</div>";
} else {
    // Tampilkan info hidden jika ada (Hanya tampil di layar, tidak di print)
    if ($count_hidden > 0 && $id != "") {
        echo "<div class='no-print info-header'>";
        echo "Info: Mencetak <b>$TotalReg</b> Voucher. (Disembunyikan: <b>$count_hidden</b> voucher bekas/expired)";
        echo "</div>";
    }
}

// LOOP PENCETAKAN VOUCHER
for ($i = 0; $i < $TotalReg; $i++) {
  $regtable = $getuser[$i];
  $uid = str_replace("=","",base64_encode($regtable['.id']));
  $username = $regtable['name'];
  $password = $regtable['password'];
  $timelimit = isset($regtable['limit-uptime']) ? $regtable['limit-uptime'] : '';
  $getdatalimit = isset($regtable['limit-bytes-total']) ? $regtable['limit-bytes-total'] : 0;
  $comment = isset($regtable['comment']) ? $regtable['comment'] : '';
  
  if ($getdatalimit == 0) $datalimit = "";
  else $datalimit = formatBytes($getdatalimit, 2);
  
  $urilogin = "http://$dnsname/login?username=$username&password=$password";
  
  // Script QR Code Generator
  $qrcode = "
    <canvas class='qrcode' id='".$uid."'></canvas>
    <script>
      (function() {
        new QRious({
          element: document.getElementById('".$uid."'),
          value: '".$urilogin."',
          size:'256'
        });
      })();
    </script>";
 
  $num = $i + 1;

  // LOAD TEMPLATE SESUAI PILIHAN
  if ($userp != "") {
      include('./template-thermal.php');
  } else {
      if ($small == "yes") {
          include('./template-small.php');
      } else {
          include('./template.php');
      }
  }
} 
?>
</body>
</html>