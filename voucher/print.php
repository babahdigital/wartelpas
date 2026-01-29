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
  $session = $_GET['session'] ?? '';

  include('../include/config.php');
  if ($session === '' || !isset($data[$session])) {
    header("Location:../admin.php?id=login");
    exit;
  }
  include('../include/readcfg.php');
  include('../lib/formatbytesbites.php');

  $id = $_GET['id'] ?? '';       // Ini nama BLOK/GRUP yang dipilih
  $qr = $_GET['qr'] ?? '';
  $small = $_GET['small'] ?? '';
  $userp = $_GET['user'] ?? '';
  $profile = isset($_GET['profile']) ? strtolower(trim((string)$_GET['profile'])) : '';
  $status = isset($_GET['status']) ? strtolower(trim((string)$_GET['status'])) : '';
  $mode = isset($_GET['mode']) ? strtolower(trim((string)$_GET['mode'])) : '';
  $blok = isset($_GET['blok']) ? trim((string)$_GET['blok']) : '';
  $download = isset($_GET['download']) && $_GET['download'] == '1';
  $img = isset($_GET['img']) && $_GET['img'] == '1';

  $allowed_status = ['ready','online','terpakai','rusak','retur','used','all','vip'];
  if ($status !== '' && !in_array($status, $allowed_status, true)) {
    $status = '';
  }
  $allowed_mode = ['status',''];
  if (!in_array($mode, $allowed_mode, true)) {
    $mode = '';
  }
  if ($blok !== '' && !preg_match('/^[A-Za-z0-9_-]{1,20}$/', $blok)) {
    $blok = '';
  }
  if ($userp !== '' && !preg_match('/^[A-Za-z0-9._-]{2,32}$/', $userp)) {
    $userp = '';
  }
  if ($id !== '' && !preg_match('/^[A-Za-z0-9._-]{1,40}$/', $id)) {
    $id = '';
  }
  if ($profile !== '') {
    if (preg_match('/(\d+)/', $profile, $m)) {
      $profile = (string)((int)$m[1]);
    } else {
      $profile = '';
    }
  }
  if (!in_array($profile, ['', '10', '30', 'all'], true)) {
    $profile = '';
  }
  $qr = ($qr === 'yes') ? 'yes' : (($qr === 'no') ? 'no' : $qr);
  $small = ($small === 'yes') ? 'yes' : (($small === 'no') ? 'no' : $small);

  require('../lib/routeros_api.class.php');
  $API = new RouterosAPI();
  $API->debug = false;
  $API->timeout = 5;
  $API->attempts = 1;
  $api_connected = $API->connect($iphost, $userhost, decrypt($passwdhost));
  $router_user_map = null;
  $getRouterUserMap = function() use (&$router_user_map, $API, $api_connected) {
    if (!$api_connected) return [];
    if ($router_user_map !== null) return $router_user_map;
    $rows = $API->comm('/ip/hotspot/user/print', [
      '.proplist' => '.id,name,password,profile,comment,limit-uptime,limit-bytes-total,uptime,bytes-in,bytes-out,server'
    ]);
    $map = [];
    if (is_array($rows)) {
      foreach ($rows as $r) {
        if (!empty($r['name'])) $map[$r['name']] = $r;
      }
    }
    $router_user_map = $map;
    return $router_user_map;
  };

  // --- LOGIKA PENCARIAN & FILTER ---

  $profile_filter = ($profile !== '' && $profile !== 'all') ? $profile : '';

  function detect_profile_kind_voucher($profile_name, $comment, $limit_uptime) {
    $p = strtolower((string)$profile_name);
    if (preg_match('/\b30\s*(menit|m|min)\b|30menit/i', $p)) return '30';
    if (preg_match('/\b10\s*(menit|m|min)\b|10menit/i', $p)) return '10';
    $c = strtolower((string)$comment);
    if (preg_match('/profile\s*:\s*30\b|\b30\s*(menit|m|min)\b|30menit/i', $c)) return '30';
    if (preg_match('/profile\s*:\s*10\b|\b10\s*(menit|m|min)\b|10menit/i', $c)) return '10';
    $lu = strtolower((string)$limit_uptime);
    if (preg_match('/\b30m\b|\b30\s*min\b|\b00:30:00\b/', $lu)) return '30';
    if (preg_match('/\b10m\b|\b10\s*min\b|\b00:10:00\b/', $lu)) return '10';
    return 'other';
  }

  $count_hidden = 0; // Menghitung voucher yang disembunyikan

  if ($status != "" && $mode === 'status') {
    // C. PRINT BERDASARKAN STATUS (DB) - MODE STATUS TEMPLATE
    $target_status = strtolower($status);
    $filter_user_raw = $userp ?? '';
    $filter_user = '';
    if ($filter_user_raw !== '') {
      if (strpos($filter_user_raw, '-') !== false) {
        $parts = explode('-', $filter_user_raw);
        $cnt = count($parts);
        $filter_user = $cnt >= 3 ? ($parts[$cnt - 2] . '-' . $parts[$cnt - 1]) : $parts[$cnt - 1];
      } else {
        $filter_user = $filter_user_raw;
      }
    }
    $getuser = [];
    $routerMap = $getRouterUserMap();
    try {
      $db = new PDO('sqlite:../db_data/mikhmon_stats.db');
      $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      if ($blok != '') {
        $stmt = $db->prepare("SELECT username, blok_name, raw_comment FROM login_history WHERE lower(last_status)=:st");
        $stmt->execute([':st' => $target_status]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
          if ($filter_user !== '' && $r['username'] !== $filter_user) continue;
          $row_blok = $r['blok_name'] ?? '';
          $raw_comment = $r['raw_comment'] ?? '';
          $is_retur_comment = (stripos($raw_comment, '(Retur)') !== false) || (stripos($raw_comment, 'Retur Ref:') !== false);
          $cm_blok = extract_blok_from_comment($raw_comment);
          $match = (stripos($row_blok, $blok) !== false) || (stripos($cm_blok, $blok) !== false) || (stripos($raw_comment, $blok) !== false);
          if (!$match) continue;
          if ($target_status === 'retur' && !$is_retur_comment) continue;
          if ($target_status === 'rusak' && $is_retur_comment) continue;
          $u = isset($routerMap[$r['username']]) ? $routerMap[$r['username']] : null;
          if (!empty($u)) {
            $router_comment = $u['comment'] ?? '';
            $router_is_retur = (stripos($router_comment, '(Retur)') !== false) || (stripos($router_comment, 'Retur Ref:') !== false);
            if ($target_status === 'retur' && !$router_is_retur) continue;
            if ($target_status === 'rusak' && $router_is_retur) continue;
            $getuser[] = $u;
          } else {
            $profile_name = '';
            if (preg_match('/Profile:([^|]+)/i', $raw_comment, $m)) {
              $profile_name = trim($m[1]);
            }
            if ($target_status === 'retur' && !$is_retur_comment) continue;
            if ($target_status === 'rusak' && $is_retur_comment) continue;
            $getuser[] = [
              '.id' => 'db-'.$r['username'],
              'name' => $r['username'],
              'password' => $r['username'],
              'profile' => $profile_name,
              'comment' => $raw_comment,
              'limit-uptime' => '',
              'limit-bytes-total' => 0,
              'uptime' => '0s',
              'bytes-in' => 0,
              'bytes-out' => 0,
            ];
          }
        }
      } else {
        $stmt = $db->prepare("SELECT username, raw_comment FROM login_history WHERE lower(last_status)=:st");
        $stmt->execute([':st' => $target_status]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
          if ($filter_user !== '' && $r['username'] !== $filter_user) continue;
          $u = isset($routerMap[$r['username']]) ? $routerMap[$r['username']] : null;
          if (!empty($u)) {
            $router_comment = $u['comment'] ?? '';
            $router_is_retur = (stripos($router_comment, '(Retur)') !== false) || (stripos($router_comment, 'Retur Ref:') !== false);
            if ($target_status === 'retur' && !$router_is_retur) continue;
            if ($target_status === 'rusak' && $router_is_retur) continue;
            $getuser[] = $u;
          } else {
            $raw_comment = $r['raw_comment'] ?? '';
            $is_retur_comment = (stripos($raw_comment, '(Retur)') !== false) || (stripos($raw_comment, 'Retur Ref:') !== false);
            $profile_name = '';
            if (preg_match('/Profile:([^|]+)/i', $raw_comment, $m)) {
              $profile_name = trim($m[1]);
            }
            if ($target_status === 'retur' && !$is_retur_comment) continue;
            if ($target_status === 'rusak' && $is_retur_comment) continue;
            $getuser[] = [
              '.id' => 'db-'.$r['username'],
              'name' => $r['username'],
              'password' => $r['username'],
              'profile' => $profile_name,
              'comment' => $raw_comment,
              'limit-uptime' => '',
              'limit-bytes-total' => 0,
              'uptime' => '0s',
              'bytes-in' => 0,
              'bytes-out' => 0,
            ];
          }
        }
      }
    } catch (Exception $e) {
      $getuser = [];
    }
    if ($profile_filter !== '') {
      $filtered = [];
      foreach ($getuser as $u) {
        $kind = detect_profile_kind_voucher($u['profile'] ?? '', $u['comment'] ?? '', $u['limit-uptime'] ?? '');
        if ($kind !== $profile_filter) continue;
        $filtered[] = $u;
      }
      $getuser = $filtered;
    }
    $TotalReg = count($getuser);
    $usermode = "vc";

    if ($TotalReg == 0 && in_array($target_status, ['retur','rusak'])) {
      $key = $target_status === 'retur' ? 'Retur' : 'RUSAK';
      $raw_users = array_values($routerMap);
      foreach ($raw_users as $u) {
        if ($filter_user !== '' && ($u['name'] ?? '') !== $filter_user) continue;
        $u_comment = $u['comment'] ?? '';
        $is_retur_comment = (stripos($u_comment, '(Retur)') !== false) || (stripos($u_comment, 'Retur Ref:') !== false);
        if ($target_status === 'retur' && !$is_retur_comment) continue;
        if ($target_status === 'rusak' && $is_retur_comment) continue;
        if (stripos($u_comment, $key) === false) continue;
        if ($blok != '') {
          $cm_blok = extract_blok_from_comment($u_comment);
          $match = (stripos($u_comment, $blok) !== false) || (stripos($cm_blok, $blok) !== false);
          if (!$match) continue;
        }
        $getuser[] = $u;
      }
      $TotalReg = count($getuser);
    }
  } elseif ($userp != "") {
    // A. PRINT SATUAN (BY NAME) - Tidak di-filter karena user minta spesifik
    if (strpos($userp, '-') === false) {
      $user = $userp;
      $usermode = 'vc';
    } else {
      $pulluser = explode('-', $userp);
      $iuser = count($pulluser);
      $prefix = $pulluser[$iuser - 2];
      $user = $pulluser[$iuser - 1];
      if ($iuser >= 3) {
        $user = $prefix . "-" . $user;
      }
      $usermode = $pulluser[0];
    }

    $getuser = $API->comm("/ip/hotspot/user/print", array(
      "?name" => "$user",
      ".proplist" => ".id,name,password,profile,comment,limit-uptime,limit-bytes-total,uptime,bytes-in,bytes-out"
    ));
    if ($profile_filter !== '') {
      $filtered = [];
      foreach ($getuser as $u) {
        $kind = detect_profile_kind_voucher($u['profile'] ?? '', $u['comment'] ?? '', $u['limit-uptime'] ?? '');
        if ($kind !== $profile_filter) continue;
        $filtered[] = $u;
      }
      $getuser = $filtered;
    }
    $TotalReg = count($getuser);

    if ($TotalReg == 0) {
      try {
        $db = new PDO('sqlite:../db_data/mikhmon_stats.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $db->prepare("SELECT username, raw_comment FROM login_history WHERE username = :u LIMIT 1");
        $stmt->execute([':u' => $user]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
          $raw_comment = $row['raw_comment'] ?? '';
          $profile_name = '';
          if (preg_match('/Profile:([^|]+)/i', $raw_comment, $m)) {
            $profile_name = trim($m[1]);
          }
          $getuser = [[
            '.id' => 'db-'.$row['username'],
            'name' => $row['username'],
            'password' => $row['username'],
            'profile' => $profile_name,
            'comment' => $raw_comment,
            'limit-uptime' => '',
            'limit-bytes-total' => 0,
            'uptime' => '0s',
            'bytes-in' => 0,
            'bytes-out' => 0,
          ]];
          $TotalReg = count($getuser);
        }
      } catch (Exception $e) {
        $getuser = [];
        $TotalReg = 0;
      }
    }
  } elseif ($status != "") {
    // C. PRINT BERDASARKAN STATUS (DB)
    $target_status = strtolower($status);
    $getuser = [];
    $routerMap = $getRouterUserMap();
    if ($target_status === 'vip') {
      $raw_users = array_values($routerMap);
      foreach ($raw_users as $u) {
        $u_comment = $u['comment'] ?? '';
        if (!preg_match('/\bvip\b/i', $u_comment)) continue;
        $getuser[] = $u;
      }
    } else {
      try {
        $db = new PDO('sqlite:../db_data/mikhmon_stats.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($blok != '') {
          $stmt = $db->prepare("SELECT username, blok_name, raw_comment FROM login_history WHERE lower(last_status)=:st");
          $stmt->execute([':st' => $target_status]);
          $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
          foreach ($rows as $r) {
            $row_blok = $r['blok_name'] ?? '';
            $raw_comment = $r['raw_comment'] ?? '';
            $is_retur_comment = (stripos($raw_comment, '(Retur)') !== false) || (stripos($raw_comment, 'Retur Ref:') !== false);
            $cm_blok = extract_blok_from_comment($raw_comment);
            $match = (stripos($row_blok, $blok) !== false) || (stripos($cm_blok, $blok) !== false) || (stripos($raw_comment, $blok) !== false);
            if (!$match) continue;
            if ($target_status === 'retur' && !$is_retur_comment) continue;
            if ($target_status === 'rusak' && $is_retur_comment) continue;
            $u = isset($routerMap[$r['username']]) ? $routerMap[$r['username']] : null;
            if (!empty($u)) {
              $router_comment = $u['comment'] ?? '';
              $router_is_retur = (stripos($router_comment, '(Retur)') !== false) || (stripos($router_comment, 'Retur Ref:') !== false);
              if ($target_status === 'retur' && !$router_is_retur) continue;
              if ($target_status === 'rusak' && $router_is_retur) continue;
              $getuser[] = $u;
            } else {
              $profile_name = '';
              if (preg_match('/Profile:([^|]+)/i', $raw_comment, $m)) {
                $profile_name = trim($m[1]);
              }
              if ($target_status === 'retur' && !$is_retur_comment) continue;
              if ($target_status === 'rusak' && $is_retur_comment) continue;
              $getuser[] = [
                '.id' => 'db-'.$r['username'],
                'name' => $r['username'],
                'password' => $r['username'],
                'profile' => $profile_name,
                'comment' => $raw_comment,
                'limit-uptime' => '',
                'limit-bytes-total' => 0,
                'uptime' => '0s',
                'bytes-in' => 0,
                'bytes-out' => 0,
              ];
            }
          }
        } else {
          $stmt = $db->prepare("SELECT username, raw_comment FROM login_history WHERE lower(last_status)=:st");
          $stmt->execute([':st' => $target_status]);
          $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
          foreach ($rows as $r) {
            $u = isset($routerMap[$r['username']]) ? $routerMap[$r['username']] : null;
            if (!empty($u)) {
              $router_comment = $u['comment'] ?? '';
              $router_is_retur = (stripos($router_comment, '(Retur)') !== false) || (stripos($router_comment, 'Retur Ref:') !== false);
              if ($target_status === 'retur' && !$router_is_retur) continue;
              if ($target_status === 'rusak' && $router_is_retur) continue;
              $getuser[] = $u;
            } else {
              $raw_comment = $r['raw_comment'] ?? '';
              $is_retur_comment = (stripos($raw_comment, '(Retur)') !== false) || (stripos($raw_comment, 'Retur Ref:') !== false);
              $profile_name = '';
              if (preg_match('/Profile:([^|]+)/i', $raw_comment, $m)) {
                $profile_name = trim($m[1]);
              }
              if ($target_status === 'retur' && !$is_retur_comment) continue;
              if ($target_status === 'rusak' && $is_retur_comment) continue;
              $getuser[] = [
                '.id' => 'db-'.$r['username'],
                'name' => $r['username'],
                'password' => $r['username'],
                'profile' => $profile_name,
                'comment' => $raw_comment,
                'limit-uptime' => '',
                'limit-bytes-total' => 0,
                'uptime' => '0s',
                'bytes-in' => 0,
                'bytes-out' => 0,
              ];
            }
          }
        }
      } catch (Exception $e) {
        $getuser = [];
      }
    }
    if ($profile_filter !== '') {
      $filtered = [];
      foreach ($getuser as $u) {
        $kind = detect_profile_kind_voucher($u['profile'] ?? '', $u['comment'] ?? '', $u['limit-uptime'] ?? '');
        if ($kind !== $profile_filter) continue;
        $filtered[] = $u;
      }
      $getuser = $filtered;
    }
    $TotalReg = count($getuser);
    $usermode = "vc";

    // Fallback: jika DB kosong, cari langsung di router dari comment
    if ($TotalReg == 0 && in_array($target_status, ['retur','rusak'], true)) {
      $key = $target_status === 'retur' ? 'Retur' : 'RUSAK';
      $raw_users = array_values($routerMap);
      foreach ($raw_users as $u) {
        $u_comment = $u['comment'] ?? '';
        $is_retur_comment = (stripos($u_comment, '(Retur)') !== false) || (stripos($u_comment, 'Retur Ref:') !== false);
        if ($target_status === 'retur' && !$is_retur_comment) continue;
        if ($target_status === 'rusak' && $is_retur_comment) continue;
        if (stripos($u_comment, $key) === false) continue;
        if ($blok != '') {
          $cm_blok = extract_blok_from_comment($u_comment);
          $match = (stripos($u_comment, $blok) !== false) || (stripos($cm_blok, $blok) !== false);
          if (!$match) continue;
        }
        $getuser[] = $u;
      }
      $TotalReg = count($getuser);
    }
  } elseif ($id != "") {
    // B. PRINT PER BLOK (SMART SEARCH + FILTER)
    $usermode = "vc"; 

    // 1. Ambil Data Mentah (Cari Persis Dulu)
    $raw_users = [];
    $all_users = array_values($getRouterUserMap());
    foreach ($all_users as $u) {
      if (!isset($u['comment'])) continue;
      if ($u['comment'] === $id) {
        if (isset($u['server']) && strtolower((string)$u['server']) !== 'wartel') continue;
        $raw_users[] = $u;
      }
    }
    
    // 2. Jika Kosong, Cari Partial Match (yg mengandung kata)
    if (count($raw_users) == 0) {
      foreach ($all_users as $u) {
        if (isset($u['server']) && strtolower((string)$u['server']) !== 'wartel') continue;
        if (isset($u['comment']) && stripos($u['comment'], $id) !== false) {
          $raw_users[] = $u;
        }
      }
    }
    
    // 3. --- [FILTER PENTING] --- HAPUS VOUCHER BEKAS (Valid: / Exp)
    $getuser = array();
    foreach ($raw_users as $u) {
        $u_comment = isset($u['comment']) ? $u['comment'] : "";
        $u_uptime  = isset($u['uptime']) ? $u['uptime'] : (isset($u['limit-uptime']) ? $u['limit-uptime'] : "");
        $u_bytes = (isset($u['bytes-in']) ? $u['bytes-in'] : 0) + (isset($u['bytes-out']) ? $u['bytes-out'] : 0);
        
        // Cek Tanda-tanda Bekas Pakai
        $is_used = (stripos($u_comment, 'Valid:') !== false) || $u_bytes > 0 || ($u_uptime != '' && $u_uptime != '0s');
        $is_exp  = (stripos($u_comment, 'exp') !== false || $u_uptime == '1s');
        $is_rusak = (stripos($u_comment, 'RUSAK') !== false);
        $is_retur = (stripos($u_comment, '(Retur)') !== false || stripos($u_comment, 'Retur Ref:') !== false);
        $has_ipmac = (stripos($u_comment, 'IP:') !== false || stripos($u_comment, 'MAC:') !== false);
        
        if (!$is_used && !$is_exp && !$is_rusak && !$is_retur && !$has_ipmac) {
            $getuser[] = $u; // Masukkan ke daftar cetak jika BERSIH
        } else {
            $count_hidden++; // Hitung yang dibuang
        }
    }
    
    if ($profile_filter !== '') {
      $filtered = [];
      foreach ($getuser as $u) {
        $kind = detect_profile_kind_voucher($u['profile'] ?? '', $u['comment'] ?? '', $u['limit-uptime'] ?? '');
        if ($kind !== $profile_filter) continue;
        $filtered[] = $u;
      }
      $getuser = $filtered;
    }
    $TotalReg = count($getuser);
  }
  
  // --- AMBIL PROFILE ---
    if ($TotalReg > 0) {
      $getuprofile = $getuser[0]['profile'];
      $getprofile = $api_connected ? $API->comm("/ip/hotspot/user/profile/print", array(
        "?name" => "$getuprofile",
        ".proplist" => "name,on-login"
      )) : [];

        if (count($getprofile) > 0) {
          $ponlogin = isset($getprofile[0]['on-login']) ? $getprofile[0]['on-login'] : '';
          $parts = array_map('trim', explode(",", $ponlogin));
          $getprice = isset($parts[2]) ? $parts[2] : '0';
          $getsprice = isset($parts[4]) ? $parts[4] : '0';
          $validity = isset($parts[3]) ? $parts[3] : '';
        } else {
          $getprice = "0"; $getsprice = "0"; $validity = '';
        }
      } else {
        $getprice = "0"; $getsprice = "0"; $getuprofile = "Unknown"; $validity = '';
      }

  $profile_title = $getuprofile;
  if ($profile_filter !== '') {
    $profile_title = $profile_filter . ' Menit';
  }

  // --- FORMAT HARGA ---
  if($getsprice == "0" && $getprice != "0"){
      if (in_array($currency, $cekindo['indo'], true)) {
        $price = $currency . " " . number_format((float)$getprice, 0, ",", ".");
      } else {
        $price = $currency . " " . number_format((float)$getprice, 2);
      }
  }else if($getsprice != "0"){
      if (in_array($currency, $cekindo['indo'], true)) {
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

  if ($download && !$img) {
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="voucher-'.$session.'-'.date('Ymd-His').'.html"');
  }
}

function extract_blok_from_comment($comment) {
  if (empty($comment)) return '';
  if (preg_match('/\bblok\s*[-_]?\s*([A-Za-z0-9]+)/i', $comment, $m)) {
    return 'BLOK-' . strtoupper($m[1]);
  }
  return '';
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Voucher-<?= $hotspotname . "-" . $profile_title . "-" . $id; ?></title>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta http-equiv="pragma" content="no-cache" />
    <link rel="icon" href="../img/favicon.png" />
    <script src="../js/qrious.min.js"></script>
    <?php if ($download && $img): ?>
      <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <?php endif; ?>
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
<body<?php if (!$download) { echo ' onload="window.print()"'; } ?>>

<?php 
// INFO JIKA KOSONG ATAU ADA YANG DI-FILTER
if ($TotalReg == 0) {
    echo "<div style='text-align:center; padding-top:50px;'>";
    echo "<h3 style='color:red;'>Stok Habis / Data Kosong!</h3>";
    if ($count_hidden > 0) {
        echo "<p>Ditemukan <b>$count_hidden voucher</b> di grup <strong>$id</strong>, tetapi semuanya sudah <b>Terpakai/Expired</b>.</p>";
    } else {
        if ($status != "") {
          echo "<p>Tidak ditemukan data untuk status: <strong>$status</strong></p>";
        } else {
          echo "<p>Tidak ditemukan data dengan kata kunci: <strong>$id</strong></p>";
        }
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
  $profile_name = isset($regtable['profile']) ? $regtable['profile'] : $getuprofile;
  $is_vip = (!empty($comment) && preg_match('/\bvip\b/i', $comment));
  
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
        if ($userp != "" || $status != "") {
        include('./template-small.php');
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

<?php if ($download && $img): ?>
<script>
  window.addEventListener('load', async () => {
    const vouchers = Array.from(document.querySelectorAll('table.voucher'));
    if (!vouchers.length) return;
    const images = Array.from(document.images || []);
    await Promise.all(images.map(img => new Promise(resolve => {
      if (img.complete) return resolve();
      img.addEventListener('load', resolve, { once: true });
      img.addEventListener('error', resolve, { once: true });
    })));
    await new Promise(r => setTimeout(r, 300));
    for (let i = 0; i < vouchers.length; i++) {
      const canvas = await html2canvas(vouchers[i], {scale: 2, backgroundColor: '#fff'});
      const link = document.createElement('a');
      const now = new Date();
      const pad = (n) => String(n).padStart(2, '0');
      const ts = `${now.getFullYear()}${pad(now.getMonth()+1)}${pad(now.getDate())}_${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
      const uname = vouchers[i].getAttribute('data-username') || `voucher_${i+1}`;
      const safe = uname.replace(/[^A-Za-z0-9._-]/g, '_');
      link.download = `${safe}_${ts}.png`;
      link.href = canvas.toDataURL('image/png');
      link.click();
    }
  });
</script>
<?php endif; ?>