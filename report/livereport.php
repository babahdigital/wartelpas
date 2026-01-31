<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * Modified by Pak Dul (2026) - FILTER HANYA USER BLOK
 */
session_start();
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {
  $session = $_GET['session'];
  date_default_timezone_set($_SESSION['timezone']);
  include('../include/lang.php');
  include('../lang/'.$langid.'.php');
  include('../include/config.php');
  include('../include/readcfg.php');
  if (file_exists('../report/laporan/helpers.php')) {
    require_once('../report/laporan/helpers.php');
  }
  include_once('../lib/routeros_api.class.php');
  include_once('../lib/formatbytesbites.php');
  
  $API = new RouterosAPI();
  $API->debug = false;
  $API->connect($iphost, $userhost, decrypt($passwdhost));


  if ($livereport == "disable") {
    $logh = "457px";
    $lreport = "style='display:none;'";
  } else {
    $logh = "350px";
    $lreport = "style='display:block;'";
    
    $thisD = date("d");
    $thisM = strtolower(date("M"));
    $thisY = date("Y");
    $thisD = (strlen($thisD) == 1) ? "0" . $thisD : $thisD;

    $idhr = $thisM . "/" . $thisD . "/" . $thisY;
    $idbl = $thisM . $thisY;

    $_SESSION[$session.'idhr'] = $idhr;

    // Ambil Data Bulan Ini
    $getSRBl = $API->comm("/system/script/print", array("?owner" => "$idbl"));
    $TotalRBl = 0; // Reset Count
    $TotalRHr = 0; // Reset Count
    $tHr = 0;      // Reset Rupiah
    $tBl = 0;      // Reset Rupiah

    foreach($getSRBl as $row){
      $parts = function_exists('split_sales_raw') ? split_sales_raw($row['name'] ?? '') : explode('-|-', $row['name']);
      
      // === FILTER SECURITY: CEK KOMENTAR ===
      $comment = isset($parts[8]) ? $parts[8] : "";
      if (stripos($comment, 'Blok-') === false) {
          // Skip user illegal/non-blok dari perhitungan dashboard
          continue; 
      }
      
      $harga = (int)$parts[3];

      // Hitung Harian
      if($parts[0] == $idhr){
         $tHr += $harga;
         $TotalRHr++;
       }
       
       // Hitung Bulanan
       $tBl += $harga;
       $TotalRBl++;
    }
    
    // Update Session Data
    $_SESSION[$session.'totalHr'] = ($TotalRHr == "") ? "0" : $TotalRHr;
  }
}
?>

<div id="r_4" class="row">
  <div <?= $lreport; ?> class="box bmh-75 box-bordered">
    <div class="box-group">
      <div class="box-group-icon"><i class="fa fa-money"></i></div>
        <div class="box-group-area">
          <span >
            <div id="reloadLreport">
            <?php 
              if ($currency == in_array($currency, $cekindo['indo'])) {
                $dincome = number_format((float)$tHr, 0, ",", ".");
                $mincome = number_format((float)$tBl, 0, ",", ".");
              }else{
                $dincome = number_format((float)$tHr, 2);
                $mincome = number_format((float)$tBl, 2);
              }
              $_SESSION[$session.'dincome'] = $dincome;
              $_SESSION[$session.'mincome'] = $mincome;

              echo $_income."<br/>" . "
              ".$_today." " . $TotalRHr . "vcr : " . $currency . " " . $dincome . "<br/>
              ".$_this_month." " . $TotalRBl . "vcr : " . $currency . " " . $mincome;
              ?>
            </div>
        </span>
    </div>
  </div>
</div>
</div>