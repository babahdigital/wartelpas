<?php
/*
 * WARTELPAS USER MANAGEMENT (REBUILD - STEP 1)
 * Basis: user-old.php
 * Fokus: koneksi database + kolom waktu (tanpa AJAX)
 */

session_start();
if (!isset($_SESSION["mikhmon"]) || !isset($_GET['session'])) {
    header("Location:../admin.php?id=login");
    exit();
}

$session = $_GET['session'];

$req_prof = isset($_GET['profile']) ? $_GET['profile'] : 'all';
$req_comm = isset($_GET['comment']) ? urldecode($_GET['comment']) : '';
$req_status = isset($_GET['status']) ? $_GET['status'] : 'all';
$req_search = isset($_GET['q']) ? $_GET['q'] : '';
$debug_mode = isset($_GET['debug']) && $_GET['debug'] == '1';
$enforce_rusak_rules = !(isset($_GET['rusak_free']) && $_GET['rusak_free'] == '1');

include('../include/config.php');
include('../include/readcfg.php');
include_once('../lib/routeros_api.class.php');
include_once('../lib/formatbytesbites.php');

if (!function_exists('formatBytes')) {
    function formatBytes($size, $precision = 2) {
        if ($size <= 0) return '0 B';
        $base = log($size, 1024);
        $suffixes = array('B', 'KB', 'MB', 'GB', 'TB');
        return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
    }
}

if (!function_exists('formatDateIndo')) {
    function formatDateIndo($dateStr) {
        if (empty($dateStr) || $dateStr == '-') return '-';
        $timestamp = strtotime($dateStr);
        if (!$timestamp) return $dateStr;
        return date('d-m-Y H:i:s', $timestamp);
    }
}

if (!function_exists('decrypt')) {
    function decrypt($string, $key=128) {
        $result = '';
        $string = base64_decode($string);
        for($i=0, $k=strlen($string); $i< $k ; $i++) {
            $char = substr($string, $i, 1);
            $keychar = substr($key, ($i % strlen($key))-1, 1);
            $char = chr(ord($char)-ord($keychar));
            $result .= $char;
        }
        return $result;
    }
}

// Helper: Format comment untuk display
function format_comment_display($comment) {
  if (empty($comment)) return '-';
  if (preg_match('/(\d{4})-(\d{2})-(\d{2})\s+(\d{2}:\d{2}:\d{2})\s*\|\s*([^|]+)/i', $comment, $m)) {
    $date_formatted = "{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}";
    $blok = trim($m[5]);
    return "Valid {$date_formatted} | {$blok}";
  }
  $short = preg_replace('/\|\s*IP:[^\|]+/', '', $comment);
  $short = preg_replace('/\|\s*MAC:[^\|]+/', '', $short);
  return trim($short);
}

// Helper: Ekstrak nama blok dari comment
function extract_blok_name($comment) {
  if (empty($comment)) return '';
  if (preg_match('/\bblok\s*[-_]?\s*([A-Za-z0-9]+)/i', $comment, $m)) {
    return 'BLOK-' . strtoupper($m[1]);
  }
  return '';
}

// Helper: Ekstrak IP/MAC dari comment (format: IP:... | MAC:...)
function extract_ip_mac_from_comment($comment) {
  $ip = '';
  $mac = '';
  if (!empty($comment)) {
    if (preg_match('/\bIP\s*:\s*([^|\s]+)/i', $comment, $m)) {
      $ip = trim($m[1]);
    }
    if (preg_match('/\bMAC\s*:\s*([^|\s]+)/i', $comment, $m)) {
      $mac = trim($m[1]);
    }
  }
  return ['ip' => $ip, 'mac' => $mac];
}

// Helper: Konversi uptime (1w2d3h4m5s) ke detik
function uptime_to_seconds($uptime) {
  if (empty($uptime)) return 0;
  if ($uptime === '0s') return 0;
  $total = 0;
  if (preg_match_all('/(\d+)(w|d|h|m|s)/i', $uptime, $m, PREG_SET_ORDER)) {
    foreach ($m as $part) {
      $val = (int)$part[1];
      switch (strtolower($part[2])) {
        case 'w': $total += $val * 7 * 24 * 3600; break;
        case 'd': $total += $val * 24 * 3600; break;
        case 'h': $total += $val * 3600; break;
        case 'm': $total += $val * 60; break;
        case 's': $total += $val; break;
      }
    }
  }
  return $total;
}

// Helper: Konversi detik ke uptime RouterOS
function seconds_to_uptime($seconds) {
  $seconds = (int)$seconds;
  if ($seconds <= 0) return '0s';
  $parts = [];
  $weeks = intdiv($seconds, 7 * 24 * 3600); $seconds %= 7 * 24 * 3600;
  $days = intdiv($seconds, 24 * 3600); $seconds %= 24 * 3600;
  $hours = intdiv($seconds, 3600); $seconds %= 3600;
  $mins = intdiv($seconds, 60); $seconds %= 60;
  if ($weeks) $parts[] = $weeks . 'w';
  if ($days) $parts[] = $days . 'd';
  if ($hours) $parts[] = $hours . 'h';
  if ($mins) $parts[] = $mins . 'm';
  if ($seconds || empty($parts)) $parts[] = $seconds . 's';
  return implode('', $parts);
}

// Helper: Ekstrak datetime dari comment (format umum MikroTik)
function extract_datetime_from_comment($comment) {
  if (empty($comment)) return '';
  // Support format: "Audit: RUSAK dd/mm/yy YYYY-mm-dd HH:MM:SS"
  if (preg_match('/RUSAK\s*\d{2}\/\d{2}\/\d{2}\s+(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/i', $comment, $m)) {
    return $m[1];
  }
  // Support format: "Valid dd-mm-YYYY HH:MM:SS" (or with colon)
  if (preg_match('/\bValid\s*:?(\d{2})-(\d{2})-(\d{4})\s+(\d{2}:\d{2}:\d{2})/i', $comment, $m)) {
    return $m[3] . '-' . $m[2] . '-' . $m[1] . ' ' . $m[4];
  }
  // Support format: "Valid dd-mm-YYYY" (tanpa jam)
  if (preg_match('/\bValid\s*:?(\d{2})-(\d{2})-(\d{4})\b/i', $comment, $m)) {
    return $m[3] . '-' . $m[2] . '-' . $m[1] . ' 00:00:00';
  }
  // Fallback: parse first segment before '|'
  $first = trim(explode('|', $comment)[0] ?? '');
  if ($first === '') return '';
  $ts = strtotime($first);
  if ($ts === false) return '';
  return date('Y-m-d H:i:s', $ts);
}

// Helper: Ekstrak sumber retur dari comment
function extract_retur_ref($comment) {
  if (empty($comment)) return '';
  if (preg_match('/Retur\s*Ref\s*:\s*([^|]+)/i', $comment, $m)) {
    return trim($m[1]);
  }
  return '';
}

// Helper: Ekstrak username asal retur dari comment
function extract_retur_user_from_ref($comment) {
  $ref = extract_retur_ref($comment);
  if ($ref === '') return '';
  if (preg_match('/\b(vc-[A-Za-z0-9._-]+)/', $ref, $m)) {
    return $m[1];
  }
  if (preg_match('/\b([a-z0-9]{6})\b/i', $ref, $m)) {
    return $m[1];
  }
  return '';
}

// Helper: Generator User Baru (retur)
function gen_user($profile, $comment_ref) {
  $blok = '';
  if (preg_match('/(Blok-[A-Za-z0-9]+)/i', $comment_ref, $m)) $blok = $m[1];
  if ($blok === '') {
    $blok = extract_blok_name($comment_ref);
  }
  // Hindari nested Retur Ref
  $clean_ref = $comment_ref;
  $clean_ref = preg_replace('/\(Retur\)/i', '', $clean_ref);
  $clean_ref = preg_replace('/Retur\s*Ref\s*:[^|]+/i', '', $clean_ref);
  $clean_ref = preg_replace('/\s+\|\s+/', ' | ', $clean_ref);
  $clean_ref = trim($clean_ref);
  $char = "abcdefghijklmnopqrstuvwxyz0123456789"; 
  $user = substr(str_shuffle($char), 0, 6);
  $pass = $user;
  $blok_part = $blok != '' ? $blok . ' ' : '';
  $new_comm = trim($blok_part . "(Retur) Valid: Retur Ref:$clean_ref | Profile:$profile");
  return ['u'=>$user, 'p'=>$pass, 'c'=>$new_comm];
}

// --- DATABASE ---
$dbDir = dirname(__DIR__) . '/db_data';
if (!is_dir($dbDir)) mkdir($dbDir, 0755, true);
$dbFile = $dbDir . '/mikhmon_stats.db';

$db = null;
try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_TIMEOUT, 2);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");
    $db->exec("PRAGMA busy_timeout=2000;");

    $db->exec("CREATE TABLE IF NOT EXISTS login_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE,
        login_date TEXT,
        login_time TEXT,
        price TEXT,
        ip_address TEXT,
        mac_address TEXT,
      last_uptime TEXT,
      last_bytes INTEGER,
      first_ip TEXT,
      first_mac TEXT,
      last_ip TEXT,
      last_mac TEXT,
      first_login_real DATETIME,
      last_login_real DATETIME,
        validity TEXT,
        blok_name TEXT,
        raw_comment TEXT,
        login_time_real DATETIME,
        logout_time_real DATETIME,
        last_status TEXT DEFAULT 'ready',
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // Pastikan kolom last_uptime ada
    try { $db->exec("ALTER TABLE login_history ADD COLUMN last_uptime TEXT"); } catch(Exception $e) {}
    // Pastikan kolom last_bytes ada
    try { $db->exec("ALTER TABLE login_history ADD COLUMN last_bytes INTEGER"); } catch(Exception $e) {}
    // Pastikan kolom device/login tracking ada
    try { $db->exec("ALTER TABLE login_history ADD COLUMN first_ip TEXT"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE login_history ADD COLUMN first_mac TEXT"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE login_history ADD COLUMN last_ip TEXT"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE login_history ADD COLUMN last_mac TEXT"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE login_history ADD COLUMN first_login_real DATETIME"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE login_history ADD COLUMN last_login_real DATETIME"); } catch(Exception $e) {}
} catch(Exception $e){
    $db = null;
}

function save_user_history($name, $data) {
    global $db;
    if(!$db || empty($name)) return false;
    try {
        $stmt = $db->prepare("INSERT INTO login_history (
          username, login_date, login_time, price, ip_address, mac_address, last_uptime, last_bytes,
          first_ip, first_mac, last_ip, last_mac, first_login_real, last_login_real,
          validity, blok_name, raw_comment, login_time_real, logout_time_real, last_status, updated_at
        ) VALUES (
          :u, :ld, :lt, :p, :ip, :mac, :up, :lb, :fip, :fmac, :lip, :lmac, :flr, :llr, :val, :bl, :raw, :ltr, :lor, :st, :upd
        ) ON CONFLICT(username) DO UPDATE SET 
            login_date = COALESCE(NULLIF(excluded.login_date, ''), login_history.login_date),
            login_time = COALESCE(NULLIF(excluded.login_time, ''), login_history.login_time),
            price = COALESCE(NULLIF(excluded.price, ''), login_history.price),
            validity = COALESCE(NULLIF(excluded.validity, ''), login_history.validity),
            blok_name = CASE WHEN excluded.blok_name != '' THEN excluded.blok_name ELSE COALESCE(login_history.blok_name, '') END,
            ip_address = CASE WHEN excluded.ip_address != '-' AND excluded.ip_address != '' THEN excluded.ip_address ELSE COALESCE(login_history.ip_address, '-') END,
            mac_address = CASE WHEN excluded.mac_address != '-' AND excluded.mac_address != '' THEN excluded.mac_address ELSE COALESCE(login_history.mac_address, '-') END,
          last_uptime = COALESCE(NULLIF(excluded.last_uptime, ''), login_history.last_uptime),
            last_bytes = CASE WHEN excluded.last_bytes IS NOT NULL AND excluded.last_bytes > 0 THEN excluded.last_bytes ELSE COALESCE(login_history.last_bytes, 0) END,
            first_ip = CASE WHEN COALESCE(login_history.first_ip, '') = '' AND excluded.first_ip != '' THEN excluded.first_ip ELSE login_history.first_ip END,
            first_mac = CASE WHEN COALESCE(login_history.first_mac, '') = '' AND excluded.first_mac != '' THEN excluded.first_mac ELSE login_history.first_mac END,
            last_ip = CASE WHEN excluded.last_ip != '' THEN excluded.last_ip ELSE COALESCE(login_history.last_ip, '') END,
            last_mac = CASE WHEN excluded.last_mac != '' THEN excluded.last_mac ELSE COALESCE(login_history.last_mac, '') END,
            first_login_real = COALESCE(login_history.first_login_real, excluded.first_login_real),
            last_login_real = COALESCE(excluded.last_login_real, login_history.last_login_real),
            login_time_real = COALESCE(excluded.login_time_real, login_history.login_time_real),
            logout_time_real = COALESCE(excluded.logout_time_real, login_history.logout_time_real),
            last_status = COALESCE(excluded.last_status, login_history.last_status),
            raw_comment = excluded.raw_comment,
            updated_at = excluded.updated_at");
        $stmt->execute([
            ':u' => $name,
            ':ld' => $data['date'] ?? '',
            ':lt' => $data['time'] ?? '',
            ':p'  => $data['price'] ?? '',
            ':ip' => $data['ip'] ?? '-',
            ':mac'=> $data['mac'] ?? '-',
          ':up' => $data['uptime'] ?? '',
            ':lb' => $data['bytes'] ?? null,
            ':fip' => $data['first_ip'] ?? '',
            ':fmac' => $data['first_mac'] ?? '',
            ':lip' => $data['last_ip'] ?? '',
            ':lmac' => $data['last_mac'] ?? '',
            ':flr' => $data['first_login_real'] ?? null,
            ':llr' => $data['last_login_real'] ?? null,
            ':val'=> $data['validity'] ?? '',
            ':bl' => $data['blok'] ?? '',
            ':raw'=> $data['raw'] ?? '',
            ':ltr'=> $data['login_time_real'] ?? null,
            ':lor'=> $data['logout_time_real'] ?? null,
            ':st' => $data['status'] ?? 'ready',
            ':upd'=> date("Y-m-d H:i:s")
        ]);
        return true;
    } catch(Exception $e) {
        return false;
    }
}

function get_user_history($name) {
    global $db;
    if(!$db) return null;
    try {
    $stmt = $db->prepare("SELECT username, login_time_real, logout_time_real, blok_name, ip_address, mac_address, last_uptime, last_bytes, last_status, first_ip, first_mac, last_ip, last_mac, first_login_real, last_login_real FROM login_history WHERE username = :u LIMIT 1");
        $stmt->execute([':u' => $name]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch(Exception $e){
        return null;
    }
}

// --- ROUTEROS ---
$API = new RouterosAPI();
$API->debug = false;
$API->timeout = 5;
$API->attempts = 1;
if (!$API->connect($iphost, $userhost, decrypt($passwdhost))) {
  die("<div class='alert alert-danger'>ERROR: Cannot connect to router $iphost</div>");
}

$hotspot_server = $hotspot_server ?? 'wartel';

// Action handler sederhana (invalid/retur/delete)
if (isset($_GET['action']) || isset($_POST['action'])) {
  $act = $_POST['action'] ?? $_GET['action'];
  if ($act == 'invalid' || $act == 'retur' || $act == 'rollback' || $act == 'delete' || $act == 'batch_delete' || $act == 'delete_status') {
    $uid = $_GET['uid'] ?? '';
    $name = $_GET['name'] ?? '';
    $comm = $_GET['c'] ?? '';
    $prof = $_GET['p'] ?? '';
    $blok = $_GET['blok'] ?? '';
    $status = $_GET['status'] ?? '';
    $action_blocked = false;
    $action_error = '';
    $hist_action = null;
    $is_rusak_target = false;
    if ($name != '') {
      $hist_action = get_user_history($name);
      if ($hist_action && strtolower($hist_action['last_status'] ?? '') === 'rusak') {
        $is_rusak_target = true;
      }
    }
    if (!$is_rusak_target && $comm != '' && stripos($comm, 'RUSAK') !== false) {
      $is_rusak_target = true;
    }

    if ($uid == '' && $name != '' && in_array($act, ['invalid','retur','rollback','delete'])) {
      $uget = $API->comm('/ip/hotspot/user/print', [
        '?server' => $hotspot_server,
        '?name' => $name,
        '.proplist' => '.id'
      ]);
      if (isset($uget[0]['.id'])) {
        $uid = $uget[0]['.id'];
      }
    }

    if ($enforce_rusak_rules && ($act == 'invalid' || $act == 'retur')) {
      $uinfo = $API->comm('/ip/hotspot/user/print', [
        '?server' => $hotspot_server,
        '?name' => $name,
        '.proplist' => '.id,name,comment,disabled,bytes-in,bytes-out,uptime'
      ]);
      $ainfo = $API->comm('/ip/hotspot/active/print', [
        '?server' => $hotspot_server,
        '?user' => $name,
        '.proplist' => 'user,uptime,bytes-in,bytes-out'
      ]);
      $urow = $uinfo[0] ?? [];
      $arow = $ainfo[0] ?? [];
      if (!$is_rusak_target) {
        $uc = $urow['comment'] ?? '';
        $ud = $urow['disabled'] ?? 'false';
        if (stripos($uc, 'RUSAK') !== false || $ud === 'true') {
          $is_rusak_target = true;
        }
      }
      $bytes_total = ($urow['bytes-in'] ?? 0) + ($urow['bytes-out'] ?? 0);
      $bytes_active = ($arow['bytes-in'] ?? 0) + ($arow['bytes-out'] ?? 0);
      $bytes = max((int)$bytes_total, (int)$bytes_active);
      $uptime = $urow['uptime'] ?? ($arow['uptime'] ?? '0s');
      $uptime_sec = uptime_to_seconds($uptime);
      $bytes_limit = 10 * 1024 * 1024; // 10 MB
      $is_active = isset($arow['user']);
      if (!($act == 'retur' && $is_rusak_target) && ($is_active || $bytes > $bytes_limit || $uptime_sec > 300)) {
        $action_blocked = true;
        $action_error = 'Gagal: data sudah terpakai (online / bytes > 10MB / uptime > 5 menit).';
      }
    }
    if (!$action_blocked && $act == 'retur') {
      $hist = $hist_action ?: get_user_history($name);
      $last_status = strtolower($hist['last_status'] ?? '');
      $comment_rusak = false;
      if (!empty($comm) && stripos($comm, 'RUSAK') !== false) {
        $comment_rusak = true;
      }
      if (!$comment_rusak && isset($urow) && !empty($urow['comment']) && stripos($urow['comment'], 'RUSAK') !== false) {
        $comment_rusak = true;
      }
      if ($last_status !== 'rusak' && !$comment_rusak) {
        $ucheck = $API->comm('/ip/hotspot/user/print', [
          '?server' => $hotspot_server,
          '?name' => $name,
          '.proplist' => 'comment,disabled'
        ]);
        $uc = $ucheck[0]['comment'] ?? '';
        $ud = $ucheck[0]['disabled'] ?? 'false';
        if (stripos($uc, 'RUSAK') !== false || $ud === 'true') {
          $comment_rusak = true;
        }
      }
      if ($last_status !== 'rusak' && !$comment_rusak) {
        $action_blocked = true;
        $action_error = 'Gagal: voucher harus status RUSAK dulu sebelum RETUR.';
      }
    }

    if (!$action_blocked && $act == 'batch_delete' && $blok != '') {
      if (!$db) {
        $action_blocked = true;
        $action_error = 'Gagal: database belum siap. Sync dulu sebelum hapus blok.';
      } else {
        $blok_norm = extract_blok_name($blok);
        $list = $API->comm("/ip/hotspot/user/print", array(
          "?server" => $hotspot_server,
          ".proplist" => ".id,name,comment"
        ));
        $block_users = [];
        foreach ($list as $usr) {
          $c = $usr['comment'] ?? '';
          $uname = $usr['name'] ?? '';
          if ($uname === '') continue;
          if (preg_match('/(Blok-[A-Za-z0-9]+)/i', $c, $bm)) {
            if (strcasecmp($bm[1], $blok) == 0) {
              $block_users[] = $uname;
            }
          }
        }
        if (!empty($block_users)) {
          $stmt = $db->prepare("SELECT username, last_status FROM login_history WHERE blok_name = :b");
          $stmt->execute([':b' => $blok_norm]);
          $db_users = [];
          foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $db_users[$row['username']] = strtolower(trim($row['last_status'] ?? ''));
          }
          $missing = [];
          foreach ($block_users as $uname) {
            if (!isset($db_users[$uname]) || $db_users[$uname] === '') {
              $missing[] = $uname;
            }
          }
          if (!empty($missing)) {
            $action_blocked = true;
            $action_error = 'Gagal: DB belum sync status (retur/rusak/terpakai) untuk ' . count($missing) . ' user. Refresh/sync dulu sebelum hapus blok.';
          }
        }
      }
    }

    if (!$action_blocked && $act == 'delete_status' && !$db) {
      $action_blocked = true;
      $action_error = 'Gagal: database belum siap. Sync dulu sebelum hapus status.';
    }

    if ($action_blocked) {
      // skip action
    } elseif ($act == 'delete_status') {
      $status_map = [
        'used' => 'terpakai',
        'retur' => 'retur',
        'rusak' => 'rusak'
      ];
      $target_status = $status_map[$status] ?? '';
      if ($target_status != '' && $db) {
        $blok_norm = $blok != '' ? extract_blok_name($blok) : '';
        $active_list = $API->comm("/ip/hotspot/active/print", array(
          "?server" => $hotspot_server,
          ".proplist" => "user"
        ));
        $active_names = [];
        foreach ($active_list as $a) {
          if (isset($a['user'])) $active_names[$a['user']] = true;
        }
        try {
          if ($blok_norm != '') {
            $stmt = $db->prepare("SELECT username, blok_name FROM login_history WHERE lower(last_status) = :st AND blok_name IS NOT NULL AND blok_name != ''");
            $stmt->execute([':st' => $target_status]);
          } else {
            $stmt = $db->prepare("SELECT username, blok_name FROM login_history WHERE lower(last_status) = :st");
            $stmt->execute([':st' => $target_status]);
          }
          $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
          $deleted_any = false;
          foreach ($rows as $row) {
            $uname = $row['username'] ?? '';
            if ($uname === '' || isset($active_names[$uname])) continue;
            if ($blok_norm != '') {
              $row_blok = extract_blok_name($row['blok_name'] ?? '');
              if (strcasecmp($row_blok, $blok_norm) !== 0) continue;
            }
            $u = $API->comm("/ip/hotspot/user/print", array(
              "?server" => $hotspot_server,
              "?name" => $uname,
              ".proplist" => ".id"
            ));
            if (isset($u[0]['.id'])) {
              $API->write('/ip/hotspot/user/remove', false);
              $API->write('=.id=' . $u[0]['.id']);
              $API->read();
            }
            $del = $db->prepare("DELETE FROM login_history WHERE username = :u");
            $del->execute([':u' => $uname]);
            $deleted_any = true;
          }
          if ($target_status === 'rusak') {
            $list = $API->comm("/ip/hotspot/user/print", array(
              "?server" => $hotspot_server,
              ".proplist" => ".id,name,comment,disabled"
            ));
            foreach ($list as $usr) {
              $uname = $usr['name'] ?? '';
              if ($uname === '' || isset($active_names[$uname])) continue;
              $cmt = $usr['comment'] ?? '';
              $disabled = $usr['disabled'] ?? 'false';
              $is_rusak_router = (stripos($cmt, 'RUSAK') !== false) || ($disabled === 'true');
              if (!$is_rusak_router) continue;
              if ($blok_norm != '') {
                $cblok = extract_blok_name($cmt);
                if (strcasecmp($cblok, $blok_norm) !== 0) continue;
              }
              if (isset($usr['.id'])) {
                $API->write('/ip/hotspot/user/remove', false);
                $API->write('=.id=' . $usr['.id']);
                $API->read();
              }
              $del = $db->prepare("DELETE FROM login_history WHERE username = :u");
              $del->execute([':u' => $uname]);
            }
          }
        } catch(Exception $e) {}
      }
    } elseif ($act == 'batch_delete' && $blok != '') {
      $active_list = $API->comm("/ip/hotspot/active/print", array(
        "?server" => $hotspot_server,
        ".proplist" => "user"
      ));
      $active_names = [];
      foreach ($active_list as $a) {
        if (isset($a['user'])) $active_names[$a['user']] = true;
      }
      $list = $API->comm("/ip/hotspot/user/print", array(
        "?server" => $hotspot_server,
        ".proplist" => ".id,name,comment"
      ));
      foreach ($list as $usr) {
        $c = $usr['comment'] ?? '';
        $uname = $usr['name'] ?? '';
        if ($uname !== '' && isset($active_names[$uname])) {
          continue; // jangan hapus user online
        }
        if (preg_match('/(Blok-[A-Za-z0-9]+)/i', $c, $bm)) {
          if (strcasecmp($bm[1], $blok) == 0) {
            $API->write('/ip/hotspot/user/remove', false);
            $API->write('=.id=' . $usr['.id']);
            $API->read();
            if ($db && !empty($usr['name'])) {
              try {
                $stmt = $db->prepare("DELETE FROM login_history WHERE username = :u");
                $stmt->execute([':u' => $usr['name']]);
              } catch(Exception $e) {}
            }
          }
        }
      }
      if ($db) {
        try {
          $stmt = $db->prepare("DELETE FROM login_history WHERE blok_name = :b");
          $stmt->execute([':b' => $blok]);
        } catch(Exception $e) {}
      }
    } elseif ($uid != '') {
      if ($act == 'delete') {
        $API->write('/ip/hotspot/user/remove', false);
        $API->write('=.id=' . $uid);
        $API->read();
        if ($db && $name != '') {
          try {
            $stmt = $db->prepare("DELETE FROM login_history WHERE username = :u");
            $stmt->execute([':u' => $name]);
          } catch(Exception $e) {}
        }
      } elseif ($act == 'invalid') {
        $new_c = "Audit: RUSAK " . date("d/m/y") . " " . $comm;
        $API->write('/ip/hotspot/user/set', false);
        $API->write('=.id='.$uid, false);
        $API->write('=disabled=yes', false);
        $API->write('=comment='.$new_c);
        $API->read();
        if ($db && $name != '') {
          $hist = get_user_history($name);
          $uinfo = $API->comm('/ip/hotspot/user/print', [
            '?server' => $hotspot_server,
            '?name' => $name,
            '.proplist' => 'comment,bytes-in,bytes-out,uptime,mac-address'
          ]);
          $urow = $uinfo[0] ?? [];
          $bytes_total = ($urow['bytes-in'] ?? 0) + ($urow['bytes-out'] ?? 0);
          $bytes_hist = (int)($hist['last_bytes'] ?? 0);
          $bytes_final = max((int)$bytes_total, $bytes_hist);
          $uptime_user = $urow['uptime'] ?? '';
          $uptime_hist = $hist['last_uptime'] ?? '';
          $uptime_final = $uptime_user != '' ? $uptime_user : ($uptime_hist != '' ? $uptime_hist : '0s');
          $cm = extract_ip_mac_from_comment($comm);
          $ip_final = $hist['ip_address'] ?? ($cm['ip'] ?? '-');
          $mac_final = $hist['mac_address'] ?? ($urow['mac-address'] ?? ($cm['mac'] ?? '-'));
          $login_time_real = $hist['login_time_real'] ?? null;
          $logout_time_real = $hist['logout_time_real'] ?? null;
          if (empty($logout_time_real)) {
            $comment_dt = extract_datetime_from_comment($comm);
            $logout_time_real = $comment_dt != '' ? $comment_dt : date('Y-m-d H:i:s');
          }
          if (empty($login_time_real) && !empty($logout_time_real)) {
            $login_time_real = $logout_time_real;
          }
          $save_data = [
            'ip' => $ip_final ?: '-',
            'mac' => $mac_final ?: '-',
            'uptime' => $uptime_final,
            'bytes' => $bytes_final,
            'blok' => extract_blok_name($comm),
            'raw' => $new_c,
            'login_time_real' => $login_time_real,
            'logout_time_real' => $logout_time_real,
            'status' => 'rusak'
          ];
          save_user_history($name, $save_data);
        }
      } elseif ($act == 'rollback') {
        // Kembalikan status RUSAK
        $uinfo = $API->comm('/ip/hotspot/user/print', [
          '?server' => $hotspot_server,
          '?name' => $name,
          '.proplist' => '.id,comment,disabled'
        ]);
        $urow = $uinfo[0] ?? [];
        $old_comment = $urow['comment'] ?? $comm;
        $clean_comment = preg_replace('/\bAudit:\s*RUSAK\s*\d{2}\/\d{2}\/\d{2}\s*/i', '', $old_comment);
        $clean_comment = preg_replace('/\bRUSAK\b\s*/i', '', $clean_comment);
        $clean_comment = preg_replace('/\(Retur\)\s*/i', '', $clean_comment);
        $clean_comment = preg_replace('/Retur\s*Ref\s*:[^|]+/i', '', $clean_comment);
        $clean_comment = preg_replace('/\s+\|\s+/', ' | ', $clean_comment);
        $clean_comment = trim($clean_comment);
        $API->write('/ip/hotspot/user/set', false);
        $API->write('=.id='.$uid, false);
        $API->write('=disabled=no', false);
        $API->write('=comment='.$clean_comment);
        $API->read();
        if ($db && $name != '') {
          $save_data = [
            'raw' => $clean_comment,
            'status' => 'ready'
          ];
          save_user_history($name, $save_data);
        }
      } elseif ($act == 'retur') {
        // Simpan data voucher lama ke DB sebelum dihapus
        $user_info = $API->comm("/ip/hotspot/user/print", array(
          "?server" => $hotspot_server,
          "?name" => $name,
          ".proplist" => ".id,name,comment,profile,bytes-in,bytes-out,uptime,mac-address"
        ));
        $uinfo = $user_info[0] ?? [];
        if (empty($uinfo) && $uid != '') {
          $user_info = $API->comm("/ip/hotspot/user/print", array(
            "?server" => $hotspot_server,
            "?.id" => $uid,
            ".proplist" => ".id,name,comment,profile,bytes-in,bytes-out,uptime,mac-address"
          ));
          $uinfo = $user_info[0] ?? [];
        }
        $active_info = $API->comm("/ip/hotspot/active/print", array(
          "?server" => $hotspot_server,
          "?user" => $name,
          ".proplist" => "user,uptime,address,mac-address,bytes-in,bytes-out"
        ));
        $ainfo = $active_info[0] ?? [];

        $cmt = $uinfo['comment'] ?? $comm;
        $blok = extract_blok_name($cmt);
        $prof = $uinfo['profile'] ?? $prof;
        $cm = extract_ip_mac_from_comment($cmt);
        $f_ip = $ainfo['address'] ?? ($cm['ip'] ?? '-');
        $f_mac = $ainfo['mac-address'] ?? ($uinfo['mac-address'] ?? ($cm['mac'] ?? '-'));

        $bytes_total = ($uinfo['bytes-in'] ?? 0) + ($uinfo['bytes-out'] ?? 0);
        $bytes_active = ($ainfo['bytes-in'] ?? 0) + ($ainfo['bytes-out'] ?? 0);
        $bytes = max($bytes_total, $bytes_active);

        $uptime_user = $uinfo['uptime'] ?? '';
        $uptime_active = $ainfo['uptime'] ?? '';
        $uptime = $uptime_user != '' ? $uptime_user : $uptime_active;

        $hist = get_user_history($name);
        $login_time_real = $hist['login_time_real'] ?? null;
        $logout_time_real = $hist['logout_time_real'] ?? null;
        if (empty($logout_time_real)) {
          $comment_dt = extract_datetime_from_comment($cmt);
          if ($comment_dt != '') $logout_time_real = $comment_dt;
        }

        if ($db) {
          $save_data = [
            'ip' => $f_ip,
            'mac' => $f_mac,
            'uptime' => $uptime,
            'bytes' => $bytes,
            'blok' => $blok,
            'raw' => $cmt,
            'login_time_real' => $login_time_real,
            'logout_time_real' => $logout_time_real,
            'status' => 'retur'
          ];
          save_user_history($name, $save_data);
        }

        // Hapus voucher lama
        $del_id = $uid ?: ($uinfo['.id'] ?? '');
        if ($del_id != '') {
          $API->write('/ip/hotspot/user/remove', false);
          $API->write('=.id=' . $del_id);
          $API->read();
        }

        // Generate voucher baru
        $gen = gen_user($prof ?: 'default', $comm ?: $name);
        $API->write('/ip/hotspot/user/add', false);
        $API->write('=server='.$hotspot_server, false);
        $API->write('=name='.$gen['u'], false);
        $API->write('=password='.$gen['p'], false);
        $API->write('=profile='.($prof ?: 'default'), false);
        $API->write('=comment='.$gen['c']);
        $API->read();

        // Simpan status retur untuk user baru
        if ($db) {
          $new_blok = extract_blok_name($gen['c']);
          $save_new = [
            'ip' => '-',
            'mac' => '-',
            'uptime' => '0s',
            'bytes' => 0,
            'blok' => $new_blok,
            'raw' => $gen['c'],
            'login_time_real' => null,
            'logout_time_real' => null,
            'status' => 'retur'
          ];
          save_user_history($gen['u'], $save_new);
        }
      }
    }
  }
  $redir_params = [
    'hotspot' => 'users',
    'session' => $session,
  ];
  if (isset($_GET['profile'])) $redir_params['profile'] = $_GET['profile'];
  if (isset($_GET['comment'])) $redir_params['comment'] = $_GET['comment'];
  if (isset($_GET['status'])) $redir_params['status'] = $_GET['status'];
  if (isset($_GET['q'])) $redir_params['q'] = $_GET['q'];
  if (isset($_GET['debug'])) $redir_params['debug'] = $_GET['debug'];
  $redir = './?' . http_build_query($redir_params);
  if ($action_blocked) {
    echo "<script>alert('" . addslashes($action_error) . "'); window.location.href='{$redir}';</script>";
  } else {
    echo "<script>window.location.href='{$redir}';</script>";
  }
  exit();
}
$all_users = $API->comm("/ip/hotspot/user/print", array(
    "?server" => $hotspot_server,
  ".proplist" => ".id,name,comment,profile,disabled,bytes-in,bytes-out,uptime"
));
$active = $API->comm("/ip/hotspot/active/print", array(
  "?server" => $hotspot_server,
  ".proplist" => "user,uptime,address,mac-address,bytes-in,bytes-out"
));

$activeMap = [];
foreach($active as $a) {
    if(isset($a['user'])) $activeMap[$a['user']] = $a;
}

// List blok untuk dropdown (DB + data router agar langsung muncul)
$list_blok = [];
if ($db) {
  try {
    $res = $db->query("SELECT DISTINCT blok_name FROM login_history WHERE blok_name IS NOT NULL AND blok_name != ''");
    if ($res) {
      foreach ($res as $row) {
        $bn = extract_blok_name($row['blok_name']);
        if ($bn && !in_array($bn, $list_blok)) $list_blok[] = $bn;
      }
    }
  } catch(Exception $e) {}
}
if (!empty($all_users)) {
  foreach ($all_users as $u) {
    $bn = extract_blok_name($u['comment'] ?? '');
    if ($bn && !in_array($bn, $list_blok)) $list_blok[] = $bn;
  }
}
if (!empty($list_blok)) {
  sort($list_blok, SORT_NATURAL | SORT_FLAG_CASE);
}

$display_data = [];
$debug_rows = [];
$search_terms = array_filter(array_map('trim', preg_split('/[,\s]+/', $req_search)));
foreach($all_users as $u) {
    $name = $u['name'] ?? '';
    $comment = $u['comment'] ?? '';
    $disabled = $u['disabled'] ?? 'false';
    $is_active = isset($activeMap[$name]);

    $f_ip = $is_active ? ($activeMap[$name]['address'] ?? '-') : '-';
    $f_mac = $is_active ? ($activeMap[$name]['mac-address'] ?? '-') : '-';
    if ($f_ip == '-' || $f_mac == '-') {
      $cm = extract_ip_mac_from_comment($comment);
      if ($f_ip == '-' && !empty($cm['ip'])) $f_ip = $cm['ip'];
      if ($f_mac == '-' && !empty($cm['mac'])) $f_mac = $cm['mac'];
    }

    $f_blok = extract_blok_name($comment);

    $hist = get_user_history($name);
    if (empty($f_blok) && $hist && !empty($hist['blok_name'])) {
      $f_blok = $hist['blok_name'];
    }
    if (!$is_active && $hist) {
      if ($f_ip == '-') $f_ip = $hist['ip_address'] ?? '-';
      if ($f_mac == '-') $f_mac = $hist['mac_address'] ?? '-';
    }

    $bytes_total = ($u['bytes-in'] ?? 0) + ($u['bytes-out'] ?? 0);
    $bytes_active = 0;
    if ($is_active) {
      $bytes_active = ($activeMap[$name]['bytes-in'] ?? 0) + ($activeMap[$name]['bytes-out'] ?? 0);
    }
    $bytes_hist = (int)($hist['last_bytes'] ?? 0);
    if ($is_active) {
      $candidate = $bytes_hist + $bytes_active;
      $bytes = max($bytes_total, $candidate, $bytes_hist);
    } else {
      $bytes = max($bytes_total, $bytes_hist);
    }
    $uptime_user = $u['uptime'] ?? '';
    $uptime_active = $is_active ? ($activeMap[$name]['uptime'] ?? '') : '';
    $uptime_hist = $hist['last_uptime'] ?? '';
    $base_total = max(uptime_to_seconds($uptime_user), uptime_to_seconds($uptime_hist));
    if ($is_active) {
      $uptime = seconds_to_uptime($base_total + uptime_to_seconds($uptime_active));
    } else {
      $uptime = $uptime_user != '' ? $uptime_user : ($uptime_hist != '' ? $uptime_hist : '0s');
    }

    // Jika RUSAK dan data kosong, pakai history
    if (!$is_active && $bytes == 0 && $bytes_hist > 0) {
      $bytes = $bytes_hist;
    }
    if (!$is_active && ($uptime == '0s' || $uptime == '') && $uptime_hist != '') {
      $uptime = $uptime_hist;
    }

    $is_rusak = stripos($comment, 'RUSAK') !== false;
    $is_invalid = false;
    $is_retur = stripos($comment, '(Retur)') !== false || stripos($comment, 'Retur Ref:') !== false;
    $hist_status = strtolower($hist['last_status'] ?? '');
    if (!$is_retur && $hist && $hist_status === 'retur') {
      $is_retur = true;
    }
    if ($hist && $hist_status === 'rusak') {
      $is_rusak = true;
    }
    if ($disabled === 'true') {
      $is_rusak = true;
    }
    // Retur harus tetap retur meski Retur Ref memuat kata RUSAK
    if ($is_retur && $hist_status !== 'rusak' && $disabled !== 'true') {
      $is_rusak = false;
    }
    if ($is_rusak || $hist_status === 'rusak') {
      $is_retur = false;
    }
    $is_used = (!$is_retur && !$is_rusak && $disabled !== 'true') &&
      ($is_active || $bytes > 50 || $uptime != '0s' || ($f_ip != '-' && stripos($comment, '-|-') === false));

    $status = 'READY';
    if ($is_active) $status = 'ONLINE';
    elseif ($is_rusak) $status = 'RUSAK';
    elseif ($disabled == 'true') $status = 'RUSAK';
    elseif ($is_retur) $status = 'RETUR';
    elseif ($is_used) $status = 'TERPAKAI';

    // Pastikan data usage tampil saat RUSAK
    if ($status === 'RUSAK' && $hist) {
      if ($bytes == 0 && (int)($hist['last_bytes'] ?? 0) > 0) {
        $bytes = (int)$hist['last_bytes'];
      }
      if (($uptime == '0s' || $uptime == '') && !empty($hist['last_uptime'])) {
        $uptime = $hist['last_uptime'];
      }
      if (($f_ip == '-' || $f_ip == '') && !empty($hist['ip_address'])) {
        $f_ip = $hist['ip_address'];
      }
      if (($f_mac == '-' || $f_mac == '') && !empty($hist['mac_address'])) {
        $f_mac = $hist['mac_address'];
      }
    }

    // Simpan waktu login/logout dan status ke DB
    $now = date('Y-m-d H:i:s');
    $login_time_real = $hist['login_time_real'] ?? null;
    $logout_time_real = $hist['logout_time_real'] ?? null;
    $last_status = $hist['last_status'] ?? 'ready';

    if ($is_active) {
      if (empty($login_time_real) || $last_status !== 'online') {
        $login_time_real = $now;
      }
    } else {
      if ($last_status === 'online' || (!empty($login_time_real) && empty($logout_time_real))) {
        $logout_time_real = $now;
      }
      if (empty($logout_time_real)) {
        $comment_dt = extract_datetime_from_comment($comment);
        if ($comment_dt != '') {
          $logout_time_real = $comment_dt;
        }
      }
      if (empty($login_time_real) && !empty($logout_time_real)) {
        $base_uptime = $uptime_user != '' ? $uptime_user : $uptime_hist;
        $u_sec = uptime_to_seconds($base_uptime);
        if ($u_sec > 0) {
          $login_time_real = date('Y-m-d H:i:s', strtotime($logout_time_real) - $u_sec);
        }
      }
      if (empty($login_time_real) && !empty($logout_time_real)) {
        $login_time_real = $logout_time_real;
      }
    }

    // Jika voucher pernah login (ada IP/MAC) namun waktu/uptime masih kosong, coba isi dari selisih login/logout
    if (!$is_active && $uptime == '0s' && !empty($logout_time_real) && !empty($login_time_real)) {
      $diff = strtotime($logout_time_real) - strtotime($login_time_real);
      if ($diff > 0) {
        $uptime = seconds_to_uptime($diff);
      }
    }

    $next_status = strtolower($status);
    $first_login_real = $hist['first_login_real'] ?? null;
    $last_login_real = $hist['last_login_real'] ?? null;
    $first_ip = $hist['first_ip'] ?? '';
    $first_mac = $hist['first_mac'] ?? '';
    $last_ip = $hist['last_ip'] ?? '';
    $last_mac = $hist['last_mac'] ?? '';
    if ($is_active) {
      if (empty($first_login_real)) $first_login_real = $now;
      $last_login_real = $now;
      if ($first_ip == '' && $f_ip != '-' && $f_ip != '') $first_ip = $f_ip;
      if ($first_mac == '' && $f_mac != '-' && $f_mac != '') $first_mac = $f_mac;
      if ($f_ip != '-' && $f_ip != '') $last_ip = $f_ip;
      if ($f_mac != '-' && $f_mac != '') $last_mac = $f_mac;
    }
    // Untuk RETUR: gunakan data dari voucher asal jika tersedia dan data saat ini masih kosong
    $retur_ref_user = '';
    $retur_hist = null;
    if ($is_retur) {
      $retur_ref_user = extract_retur_user_from_ref($comment);
      if ($retur_ref_user != '') {
        $retur_hist = get_user_history($retur_ref_user);
      }
      if ($retur_hist) {
        if ($bytes == 0 && (int)($retur_hist['last_bytes'] ?? 0) > 0) {
          $bytes = (int)$retur_hist['last_bytes'];
        }
        if (($uptime == '0s' || $uptime == '') && !empty($retur_hist['last_uptime'])) {
          $uptime = $retur_hist['last_uptime'];
        }
        if (empty($login_time_real) && !empty($retur_hist['login_time_real'])) {
          $login_time_real = $retur_hist['login_time_real'];
        }
        if (empty($logout_time_real) && !empty($retur_hist['logout_time_real'])) {
          $logout_time_real = $retur_hist['logout_time_real'];
        }
        if (($f_ip == '-' || $f_ip == '') && !empty($retur_hist['ip_address'])) {
          $f_ip = $retur_hist['ip_address'];
        }
        if (($f_mac == '-' || $f_mac == '') && !empty($retur_hist['mac_address'])) {
          $f_mac = $retur_hist['mac_address'];
        }
      }
    }

    if ($db && $name != '') {
        $save_data = [
            'ip' => $f_ip,
            'mac' => $f_mac,
          'uptime' => $uptime,
            'bytes' => $bytes,
            'first_ip' => $first_ip,
            'first_mac' => $first_mac,
            'last_ip' => $last_ip,
            'last_mac' => $last_mac,
            'first_login_real' => $first_login_real,
            'last_login_real' => $last_login_real,
            'blok' => $f_blok,
            'raw' => $comment,
            'login_time_real' => $login_time_real,
            'logout_time_real' => $logout_time_real,
            'status' => $next_status
        ];
        save_user_history($name, $save_data);
        $hist = get_user_history($name);
    }

    if ($debug_mode) {
      $debug_rows[] = [
        'name' => $name,
        'status' => $status,
        'bytes_total' => $bytes_total,
        'bytes_active' => $bytes_active,
        'bytes_hist' => $hist['last_bytes'] ?? 0,
        'uptime_user' => $uptime_user,
        'uptime_active' => $uptime_active,
        'uptime_hist' => $hist['last_uptime'] ?? '',
        'login' => $login_time_real,
        'logout' => $logout_time_real
      ];
    }

    // Filter status
    if ($req_status == 'ready') {
      if ($is_used || $is_rusak || $disabled == 'true' || $is_retur) continue;
    }
    if ($req_status == 'online' && !$is_active) continue;
    if ($req_status == 'used') {
      if (!$is_used || $is_active || $is_rusak) continue;
    }
    if ($req_status == 'rusak' && !$is_rusak) continue;
    if ($req_status == 'retur' && !$is_retur) continue;
    if ($req_status == 'invalid') continue;

    // Filter blok
    if ($req_comm != '') {
      if (strcasecmp($f_blok, $req_comm) != 0) continue;
    }

    // Search
    if (!empty($search_terms)) {
      $found = false;
      foreach($search_terms as $term) {
        if (stripos($name, $term) !== false ||
          stripos($comment, $term) !== false ||
          stripos($f_ip, $term) !== false ||
          stripos($f_blok, $term) !== false) {
          $found = true; break;
        }
      }
      if (!$found) continue;
    }

    $login_disp = $login_time_real ?? ($hist['login_time_real'] ?? '-');
    $logout_disp = $logout_time_real ?? ($hist['logout_time_real'] ?? '-');

    $display_data[] = [
      'uid' => $u['.id'] ?? '',
        'name' => $name,
        'profile' => $u['profile'] ?? '',
        'blok' => $f_blok,
        'ip' => $f_ip,
        'mac' => $f_mac,
      'comment' => $comment,
        'retur_ref' => $is_retur ? extract_retur_ref($comment) : '',
        'uptime' => $uptime,
        'bytes' => $bytes,
        'status' => $status,
        'login_time' => $login_disp,
        'logout_time' => $logout_disp
    ];
}
$API->disconnect();

// Pagination (after filtering)
$total_items = count($display_data);
$per_page = isset($_GET['per_page']) ? max(10, min(200, (int)$_GET['per_page'])) : 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$total_pages = $per_page > 0 ? (int)ceil($total_items / $per_page) : 1;
if ($page < 1) $page = 1;
if ($total_pages > 0 && $page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;
$display_data = array_slice($display_data, $offset, $per_page);

$pagination_params = $_GET;
unset($pagination_params['page']);
$pagination_base = './?' . http_build_query($pagination_params);

if ($debug_mode) {
  $logDir = dirname(__DIR__) . '/logs';
  if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
  $logFile = $logDir . '/users_debug.log';
  foreach ($debug_rows as $row) {
    $line = date('Y-m-d H:i:s') . " | {$row['name']} | {$row['status']} | bytes_total={$row['bytes_total']} bytes_active={$row['bytes_active']} bytes_hist={$row['bytes_hist']} | uptime_user={$row['uptime_user']} uptime_active={$row['uptime_active']} uptime_hist={$row['uptime_hist']} | login={$row['login']} logout={$row['logout']}\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
  }
}
?>

  <style>
    :root { --dark-bg: #1e2226; --dark-card: #2a3036; --input-bg: #343a40; --border-col: #495057; --txt-main: #ecf0f1; --txt-muted: #adb5bd; --c-blue: #3498db; --c-green: #2ecc71; --c-orange: #f39c12; --c-red: #e74c3c; }
    .card-solid { background: var(--dark-card); color: var(--txt-main); border: none; box-shadow: 0 4px 10px rgba(0,0,0,0.3); border-radius: 8px; }
    .card-header-solid { background: #23272b; padding: 12px 20px; border-bottom: 2px solid var(--border-col); display: flex; justify-content: space-between; align-items: center; border-radius: 8px 8px 0 0; }
    .table-dark-solid { width: 100%; border-collapse: separate; border-spacing: 0; }
    .table-dark-solid th { background: #1b1e21; padding: 12px; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; color: var(--txt-muted); border-bottom: 2px solid var(--border-col); }
    .table-dark-solid td { padding: 12px; border-bottom: 1px solid #3a4046; vertical-align: middle; font-size: 0.9rem; }
    .table-dark-solid tr:hover td { background: #32383e; }
    .status-badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
    .st-online { background: #3498db; color: white; }
    .st-ready { background: #4b545c; color: #ccc; border: 1px solid #6c757d; }
    .st-rusak { background: var(--c-orange); color: #fff; }
    .st-invalid { background: var(--c-red); color: #fff; }
    .st-retur { background: #8e44ad; color: #fff; }
    .st-used { background: #17a2b8; color: #fff; }
    .id-badge { font-family: 'Courier New', monospace; background: #3d454d; color: #fff; padding: 3px 6px; border-radius: 4px; font-weight: bold; border: 1px solid #56606a; }
    .btn-act { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 4px; border: none; color: white; transition: all 0.2s; margin: 0 2px; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
    .btn-act-print { background: var(--c-blue); } .btn-act-retur { background: var(--c-orange); } .btn-act-invalid { background: var(--c-red); }
    .toolbar-container { padding: 15px; background: rgba(0,0,0,0.15); border-bottom: 1px solid var(--border-col); }
    .toolbar-row { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; justify-content: flex-start; }
    .input-group-solid { display: flex; flex-grow: 1; max-width: 700px; }
    .input-group-solid .form-control, .input-group-solid .custom-select-solid { height: 40px; background: #343a40; border: 1px solid var(--border-col); color: white; padding: 0 12px; font-size: 0.9rem; border-radius: 0; }
    .input-group-solid .first-el { border-top-left-radius: 6px; border-bottom-left-radius: 6px; }
    .input-group-solid .last-el { border-top-right-radius: 6px; border-bottom-right-radius: 6px; border-left: none; }
    .input-group-solid .mid-el { border-left: none; border-right: none; }
  </style>

<div class="row">
  <div class="col-12">
    <div class="card card-solid">
      <div class="card-header-solid">
        <h3 class="card-title m-0"><i class="fa fa-users mr-2"></i> Manajemen Voucher</h3>
        <span class="badge badge-secondary p-2" style="font-size:14px">Total: <?= $total_items ?> Items</span>
      </div>
      <div class="toolbar-container">
        <form action="?" method="GET" class="toolbar-row m-0">
          <input type="hidden" name="hotspot" value="users">
          <input type="hidden" name="session" value="<?= $session ?>">

          <div class="input-group-solid">
            <input type="text" name="q" value="<?= htmlspecialchars($req_search) ?>" class="form-control first-el" placeholder="Cari User..." autocomplete="off">

            <select name="status" class="custom-select-solid mid-el" onchange="this.form.submit()" style="flex-basis: 30%;">
              <option value="all" <?=($req_status=='all'?'selected':'')?>>Status: Semua</option>
              <option value="ready" <?=($req_status=='ready'?'selected':'')?>>🟢 Hanya Ready</option>
              <option value="online" <?=($req_status=='online'?'selected':'')?>>🔵 Sedang Online</option>
              <option value="used" <?=($req_status=='used'?'selected':'')?>>⚪ Sudah Terpakai</option>
              <option value="rusak" <?=($req_status=='rusak'?'selected':'')?>>🟠 Rusak / Error</option>
              <option value="retur" <?=($req_status=='retur'?'selected':'')?>>🟣 Hasil Retur</option>
            </select>

            <select name="comment" class="custom-select-solid last-el" onchange="this.form.submit()" style="flex-basis: 30%;">
              <option value="">Semua Blok</option>
                <?php foreach($list_blok as $b) {
                  $label = preg_replace('/^BLOK-/i', 'BLOK ', $b);
                  $sel = (strcasecmp($req_comm, $b) == 0) ? 'selected' : '';
                  echo "<option value='$b' $sel>$label</option>";
              } ?>
            </select>
          </div>
          <?php
            $status_labels = ['used' => 'Terpakai', 'retur' => 'Retur', 'rusak' => 'Rusak'];
            $can_delete_status = in_array($req_status, array_keys($status_labels));
            $status_label = $status_labels[$req_status] ?? '';
            $can_print_block = ($req_comm != '' && $req_status === 'ready');
            $can_print_status = ($req_comm != '' && $req_status === 'retur');
          ?>
          <?php if ($req_comm == '' && $can_delete_status): ?>
            <button type="button" class="btn btn-warning" style="height:40px;" onclick="if(confirm('Hapus semua voucher <?= $status_label ?> (tidak online)?')) location.href='./?hotspot=users&action=delete_status&status=<?= $req_status ?>&session=<?= $session ?>'">
              <i class="fa fa-trash"></i> Hapus <?= $status_label ?>
            </button>
          <?php endif; ?>
          <?php if ($req_comm != ''): ?>
            <?php if ($can_print_status): ?>
              <button type="button" class="btn btn-secondary" style="height:40px;" onclick="window.open('./voucher/print.php?status=<?= $req_status ?>&blok=<?= urlencode($req_comm) ?>&small=yes&session=<?= $session ?>','_blank').print()">
                <i class="fa fa-print"></i> Print Status
              </button>
            <?php elseif ($can_print_block): ?>
              <button type="button" class="btn btn-secondary" style="height:40px;" onclick="window.open('./voucher/print.php?id=<?= urlencode($req_comm) ?>&small=yes&session=<?= $session ?>','_blank').print()">
                <i class="fa fa-print"></i> Print Blok
              </button>
            <?php endif; ?>
            <?php if ($can_delete_status): ?>
              <button type="button" class="btn btn-warning" style="height:40px;" onclick="if(confirm('Hapus semua voucher <?= $status_label ?> di <?= htmlspecialchars($req_comm) ?> (tidak online)?')) location.href='./?hotspot=users&action=delete_status&status=<?= $req_status ?>&blok=<?= urlencode($req_comm) ?>&session=<?= $session ?>'">
                <i class="fa fa-trash"></i> Hapus <?= $status_label ?>
              </button>
            <?php endif; ?>
            <?php if ($req_status == 'all'): ?>
              <button type="button" class="btn btn-danger" style="height:40px;" onclick="if(confirm('Hapus semua voucher di <?= htmlspecialchars($req_comm) ?>?')) location.href='./?hotspot=users&action=batch_delete&blok=<?= urlencode($req_comm) ?>&session=<?= $session ?>'">
                <i class="fa fa-trash"></i> Hapus Blok
              </button>
            <?php endif; ?>
          <?php endif; ?>
        </form>
      </div>
      <div class="card-body p-0">
        <?php if ($debug_mode): ?>
          <div style="background:#111827;color:#e5e7eb;padding:10px 14px;border-bottom:1px solid #374151;font-family:monospace;font-size:12px;">
            <div style="margin-bottom:6px;">DEBUG DB/ROUTER (showing first 10 rows)</div>
            <table style="width:100%;border-collapse:collapse;">
              <thead>
                <tr>
                  <th style="text-align:left;padding:4px;border-bottom:1px solid #374151;">User</th>
                  <th style="text-align:left;padding:4px;border-bottom:1px solid #374151;">Status</th>
                  <th style="text-align:left;padding:4px;border-bottom:1px solid #374151;">Bytes T/A/H</th>
                  <th style="text-align:left;padding:4px;border-bottom:1px solid #374151;">Uptime U/A/H</th>
                  <th style="text-align:left;padding:4px;border-bottom:1px solid #374151;">Login</th>
                  <th style="text-align:left;padding:4px;border-bottom:1px solid #374151;">Logout</th>
                </tr>
              </thead>
              <tbody>
                <?php $dbg = array_slice($debug_rows, 0, 10); foreach ($dbg as $d): ?>
                  <tr>
                    <td style="padding:4px;border-bottom:1px solid #374151;"><?= htmlspecialchars($d['name']) ?></td>
                    <td style="padding:4px;border-bottom:1px solid #374151;"><?= htmlspecialchars($d['status']) ?></td>
                    <td style="padding:4px;border-bottom:1px solid #374151;"><?= (int)$d['bytes_total'] ?>/<?= (int)$d['bytes_active'] ?>/<?= (int)$d['bytes_hist'] ?></td>
                    <td style="padding:4px;border-bottom:1px solid #374151;"><?= htmlspecialchars($d['uptime_user']) ?>/<?= htmlspecialchars($d['uptime_active']) ?>/<?= htmlspecialchars($d['uptime_hist']) ?></td>
                    <td style="padding:4px;border-bottom:1px solid #374151;"><?= htmlspecialchars($d['login'] ?: '-') ?></td>
                    <td style="padding:4px;border-bottom:1px solid #374151;"><?= htmlspecialchars($d['logout'] ?: '-') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <div class="table-responsive">
          <table class="table table-dark-solid table-hover text-nowrap">
            <thead>
              <tr>
                <th>Username <span class="text-muted">/ Ket</span></th>
                <th>Profile</th>
                <th>Identitas</th>
                <th>Koneksi (MAC/IP)</th>
                <th>Waktu (Login/Logout)</th>
                <th class="text-right">Usage</th>
                <th class="text-center">Status</th>
                <th class="text-center" width="120">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php if(count($display_data) > 0): ?>
                <?php foreach($display_data as $u): ?>
                  <tr>
                    <td>
                      <div style="font-size:15px; font-weight:bold; color:var(--txt-main)"><?= htmlspecialchars($u['name']) ?></div>
                      <div style="font-size:11px; color:var(--txt-muted); max-width:200px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($u['comment']) ?>">
                        <?= htmlspecialchars(format_comment_display($u['comment'])) ?>
                      </div>
                      <?php if (!empty($u['retur_ref'])): ?>
                        <div style="font-size:10px;color:#b2bec3;max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($u['retur_ref']) ?>">
                          Retur dari: <?= htmlspecialchars($u['retur_ref']) ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td><span class="badge badge-dark border border-secondary p-1"><?= htmlspecialchars($u['profile']) ?></span></td>
                    <td><span class="id-badge"><?= htmlspecialchars($u['blok'] ?: '-') ?></span></td>
                    <td>
                      <div style="font-family:monospace; font-size:12px; color:#aeb6bf"><?= htmlspecialchars($u['mac']) ?></div>
                      <div style="font-family:monospace; font-size:11px; color:#85929e"><?= htmlspecialchars($u['ip']) ?></div>
                    </td>
                    <td>
                      <div style="font-family:monospace; font-size:11px; color:#52c41a" title="Login Time"><?= formatDateIndo($u['login_time']) ?></div>
                      <div style="font-family:monospace; font-size:11px; color:#ff4d4f" title="Logout Time"><?= formatDateIndo($u['logout_time']) ?></div>
                    </td>
                    <td class="text-right">
                      <span style="font-size:13px; font-weight:600"><?= htmlspecialchars($u['uptime']) ?></span><br>
                      <span style="font-size:11px; color:var(--txt-muted)"><?= formatBytes($u['bytes'],2) ?></span>
                    </td>
                    <td class="text-center">
                      <?php if($u['status'] === 'ONLINE'): ?><span class="status-badge st-online">ONLINE</span>
                      <?php elseif($u['status'] === 'RUSAK'): ?><span class="status-badge st-rusak">RUSAK</span>
                      <?php elseif($u['status'] === 'INVALID'): ?><span class="status-badge st-invalid">INVALID</span>
                      <?php elseif($u['status'] === 'RETUR'): ?><span class="status-badge st-retur">RETUR</span>
                      <?php elseif($u['status'] === 'TERPAKAI'): ?><span class="status-badge st-used">TERPAKAI</span>
                      <?php else: ?><span class="status-badge st-ready">READY</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-center">
                      <?php if (strtoupper($u['status']) !== 'RUSAK'): ?>
                        <button type="button" class="btn-act btn-act-print" onclick="window.open('./voucher/print.php?user=vc-<?= htmlspecialchars($u['name']) ?>&small=yes&session=<?= $session ?>','_blank').print()" title="Print Voucher"><i class="fa fa-print"></i></button>
                      <?php endif; ?>
                      <?php if($u['uid']): ?>
                        <?php
                          $keep_params = '&profile=' . urlencode($req_prof) .
                            '&comment=' . urlencode($req_comm) .
                            '&status=' . urlencode($req_status) .
                            '&q=' . urlencode($req_search);
                        ?>
                        <?php if (strtoupper($u['status']) === 'RETUR'): ?>
                          <button type="button" class="btn-act btn-act-print" onclick="window.open('./voucher/print.php?user=vc-<?= htmlspecialchars($u['name']) ?>&small=yes&download=1&img=1&session=<?= $session ?>','_blank')" title="Download Voucher (PNG)"><i class="fa fa-download"></i></button>
                        <?php elseif (strtoupper($u['status']) === 'RUSAK'): ?>
                          <button type="button" class="btn-act btn-act-retur" onclick="if(confirm('RETUR Voucher <?= htmlspecialchars($u['name']) ?>?')) location.href='./?hotspot=users&action=retur&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&p=<?= urlencode($u['profile']) ?>&c=<?= urlencode($u['comment']) ?>&session=<?= $session ?><?= $keep_params ?>'" title="Retur"><i class="fa fa-exchange"></i></button>
                          <button type="button" class="btn-act btn-act-invalid" onclick="if(confirm('Rollback RUSAK <?= htmlspecialchars($u['name']) ?>?')) location.href='./?hotspot=users&action=rollback&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&c=<?= urlencode($u['comment']) ?>&session=<?= $session ?><?= $keep_params ?>'" title="Rollback"><i class="fa fa-undo"></i></button>
                        <?php else: ?>
                          <button type="button" class="btn-act btn-act-invalid" onclick="if(confirm('SET RUSAK <?= htmlspecialchars($u['name']) ?>?')) location.href='./?hotspot=users&action=invalid&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&c=<?= urlencode($u['comment']) ?>&session=<?= $session ?><?= $keep_params ?>'" title="Rusak"><i class="fa fa-ban"></i></button>
                        <?php endif; ?>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="8" class="text-center py-4 text-muted">Tidak ada data.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php if ($total_pages > 1): ?>
          <div class="p-3" style="border-top:1px solid var(--border-col);">
            <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;justify-content:center;">
              <?php
                $base = $pagination_base;
                $link = function($p) use ($base) {
                  return $base . (strpos($base, '?') !== false ? '&' : '?') . 'page=' . $p;
                };
                $window = 2;
                $start = max(1, $page - $window);
                $end = min($total_pages, $page + $window);
              ?>
              <?php if ($page > 1): ?>
                <a class="btn btn-sm btn-secondary" href="<?= $link(1) ?>">« First</a>
                <a class="btn btn-sm btn-secondary" href="<?= $link($page - 1) ?>">‹ Prev</a>
              <?php endif; ?>
              <?php for ($p = $start; $p <= $end; $p++): ?>
                <?php if ($p == $page): ?>
                  <span class="btn btn-sm btn-primary" style="pointer-events:none;opacity:.9;">Page <?= $p ?></span>
                <?php else: ?>
                  <a class="btn btn-sm btn-outline-light" href="<?= $link($p) ?>"><?= $p ?></a>
                <?php endif; ?>
              <?php endfor; ?>
              <?php if ($page < $total_pages): ?>
                <a class="btn btn-sm btn-secondary" href="<?= $link($page + 1) ?>">Next ›</a>
                <a class="btn btn-sm btn-secondary" href="<?= $link($total_pages) ?>">Last »</a>
              <?php endif; ?>
            </div>
            <div class="text-center mt-2" style="font-size:12px;color:var(--txt-muted);">
              Menampilkan <?= ($total_items == 0) ? 0 : ($offset + 1) ?> - <?= min($offset + $per_page, $total_items) ?> dari <?= $total_items ?> data
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
