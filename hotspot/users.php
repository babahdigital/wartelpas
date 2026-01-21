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
$read_only = isset($_GET['readonly']) && $_GET['readonly'] == '1';
$default_show = in_array($req_status, ['used', 'rusak', 'retur']) ? 'semua' : 'harian';
$req_show = $_GET['show'] ?? $default_show;
$filter_date = $_GET['date'] ?? '';
$req_show = in_array($req_show, ['harian', 'bulanan', 'tahunan', 'semua']) ? $req_show : 'harian';
if ($req_show === 'semua') {
  $filter_date = '';
} elseif ($req_show === 'harian') {
  $filter_date = $filter_date ?: date('Y-m-d');
} elseif ($req_show === 'bulanan') {
  $filter_date = $filter_date ?: date('Y-m');
} else {
  $req_show = 'tahunan';
  $filter_date = $filter_date ?: date('Y');
}
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
  // Support format: mm.dd.yy (contoh: 01.20.26)
  if (preg_match('/\b(\d{2})[\.\/-](\d{2})[\.\/-](\d{2})\b/', $comment, $m)) {
    $yy = (int)$m[3];
    $year = $yy < 70 ? (2000 + $yy) : (1900 + $yy);
    return sprintf('%04d-%02d-%02d 00:00:00', $year, (int)$m[1], (int)$m[2]);
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

// Helper: gabungkan tanggal dari $dateStr dengan jam dari $timeStr
function merge_date_time($dateStr, $timeStr) {
  if (empty($dateStr) || empty($timeStr)) return $dateStr;
  $date = date('Y-m-d', strtotime($dateStr));
  $time = date('H:i:s', strtotime($timeStr));
  return $date . ' ' . $time;
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

// Helper: Normalisasi param blok dari dropdown (hapus suffix count seperti ":1")
function normalize_blok_param($blok) {
  if (empty($blok)) return $blok;
  if (preg_match('/^(.+?):\d+$/', $blok, $m)) {
    return $m[1];
  }
  return $blok;
}

// Helper: Normalisasi tanggal untuk filter (harian/bulanan/tahunan)
function normalize_date_key($dateTime, $mode) {
  if (empty($dateTime)) return '';
  $ts = strtotime($dateTime);
  if ($ts === false) return '';
  if ($mode === 'bulanan') return date('Y-m', $ts);
  if ($mode === 'tahunan') return date('Y', $ts);
  return date('Y-m-d', $ts);
}

// Helper: Generator User Baru (retur)
function gen_user($profile, $comment_ref, $orig_user = '') {
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
  $ref_user = trim((string)$orig_user);
  $ref_label = $ref_user !== '' ? "Retur Ref:$ref_user" : "Retur Ref:$clean_ref";
  if ($ref_user !== '' && stripos($clean_ref, $ref_user) === false) {
    $ref_label = "Retur Ref:$ref_user | $clean_ref";
  }
  $new_comm = trim($blok_part . "(Retur) Valid: $ref_label | Profile:$profile");
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
        updated_at DATETIME,
        login_count INTEGER DEFAULT 0
    )");
      $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_login_history_username ON login_history(username)");
    $requiredCols = [
      'ip_address' => 'TEXT',
      'mac_address' => 'TEXT',
      'last_uptime' => 'TEXT',
      'last_bytes' => 'INTEGER',
      'first_ip' => 'TEXT',
      'first_mac' => 'TEXT',
      'last_ip' => 'TEXT',
      'last_mac' => 'TEXT',
      'first_login_real' => 'DATETIME',
      'last_login_real' => 'DATETIME',
      'validity' => 'TEXT',
      'blok_name' => 'TEXT',
      'raw_comment' => 'TEXT',
      'login_time_real' => 'DATETIME',
      'logout_time_real' => 'DATETIME',
      'last_status' => "TEXT DEFAULT 'ready'",
      'updated_at' => 'DATETIME',
      'login_count' => 'INTEGER DEFAULT 0'
    ];
    $existingCols = [];
    foreach ($db->query("PRAGMA table_info(login_history)") as $row) {
      $existingCols[$row['name']] = true;
    }
    foreach ($requiredCols as $col => $type) {
      if (!isset($existingCols[$col])) {
        try { $db->exec("ALTER TABLE login_history ADD COLUMN $col $type"); } catch(Exception $e) {}
      }
    }
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
            login_time_real = CASE WHEN excluded.last_status = 'online' THEN excluded.login_time_real ELSE COALESCE(login_history.login_time_real, excluded.login_time_real) END,
            logout_time_real = CASE WHEN excluded.last_status = 'online' THEN NULL ELSE COALESCE(login_history.logout_time_real, excluded.logout_time_real) END,
            last_status = COALESCE(excluded.last_status, login_history.last_status),
            raw_comment = excluded.raw_comment,
            updated_at = CASE WHEN excluded.last_status = 'online' THEN excluded.updated_at ELSE login_history.updated_at END");
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
  $stmt = $db->prepare("SELECT username, login_time_real, logout_time_real, blok_name, ip_address, mac_address, last_uptime, last_bytes, last_status, first_ip, first_mac, last_ip, last_mac, first_login_real, last_login_real, updated_at, login_count FROM login_history WHERE username = :u LIMIT 1");
        $stmt->execute([':u' => $name]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch(Exception $e){
        return null;
    }
}

function is_wartel_client($comment, $hist_blok = '') {
  if (!empty($hist_blok)) return true;
  $blok = extract_blok_name($comment);
  if (!empty($blok)) return true;
  if (!empty($comment) && stripos($comment, 'blok-') !== false) return true;
  return false;
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
$only_wartel = true;
if (isset($_GET['only_wartel']) && $_GET['only_wartel'] === '0') {
  $only_wartel = false;
}

// Action handler sederhana (invalid/retur/delete)
if (isset($_GET['action']) || isset($_POST['action'])) {
  $is_action_ajax = isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['action_ajax']);
  $act = $_POST['action'] ?? $_GET['action'];
  if ($act == 'invalid' || $act == 'retur' || $act == 'rollback' || $act == 'delete' || $act == 'batch_delete' || $act == 'delete_status') {
    $uid = $_GET['uid'] ?? '';
    $name = $_GET['name'] ?? '';
    $comm = $_GET['c'] ?? '';
    $prof = $_GET['p'] ?? '';
    $blok = normalize_blok_param($_GET['blok'] ?? '');
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
      $bytes_limit = 1 * 1024 * 1024; // 1 MB
      $is_active = isset($arow['user']);
      if (!($act == 'retur' && $is_rusak_target) && ($is_active || $bytes > $bytes_limit || $uptime_sec > 180)) {
        $action_blocked = true;
        $action_error = 'Gagal: data sudah terpakai (online / bytes > 1MB / uptime > 3 menit).';
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
        $blok_raw = $blok;
        $blok_cmp = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $blok_norm ?: $blok_raw));
        $list = $API->comm("/ip/hotspot/user/print", array(
          "?server" => $hotspot_server,
          ".proplist" => ".id,name,comment"
        ));
        $block_users = [];
        foreach ($list as $usr) {
          $c = $usr['comment'] ?? '';
          $uname = $usr['name'] ?? '';
          if ($uname === '') continue;
          $cblok = extract_blok_name($c);
          $cblok_cmp = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $cblok));
          if (($cblok != '' && strcasecmp($cblok, $blok_norm) == 0) || ($cblok_cmp != '' && $cblok_cmp == $blok_cmp) || ($blok_raw != '' && stripos($c, $blok_raw) !== false)) {
            $block_users[] = $uname;
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
          // if (!empty($missing)) {
          //   $action_blocked = true;
          //   $action_error = 'Gagal: DB belum sync status (retur/rusak/terpakai) untuk ' . count($missing) . ' user. Refresh/sync dulu sebelum hapus blok.';
          // }
        }
      }
    }

    if (!$action_blocked && $act == 'delete_status' && !$db) {
      $action_blocked = true;
      $action_error = 'Gagal: database belum siap. Sync dulu sebelum hapus status.';
    }

    if (!$action_blocked && $act == 'delete' && $name != '') {
      $active_check = $API->comm('/ip/hotspot/active/print', [
        '?server' => $hotspot_server,
        '?user' => $name,
        '.proplist' => 'user'
      ]);
      if (!empty($active_check) && isset($active_check[0]['user'])) {
        $action_blocked = true;
        $action_error = 'Gagal: user sedang online.';
      }
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
          if (!$deleted_any && $target_status !== '') {
            $list = $API->comm("/ip/hotspot/user/print", array(
              "?server" => $hotspot_server,
              ".proplist" => ".id,name,comment,disabled,bytes-in,bytes-out,uptime"
            ));
            foreach ($list as $usr) {
              $uname = $usr['name'] ?? '';
              if ($uname === '' || isset($active_names[$uname])) continue;
              $cmt = $usr['comment'] ?? '';
              if ($blok_norm != '') {
                $cblok = extract_blok_name($cmt);
                if (strcasecmp($cblok, $blok_norm) !== 0) continue;
              }
              $is_rusak = (stripos($cmt, 'RUSAK') !== false) || (($usr['disabled'] ?? 'false') === 'true');
              $is_retur = stripos($cmt, '(Retur)') !== false || stripos($cmt, 'Retur Ref:') !== false;
              if ($is_retur && !$is_rusak && $target_status === 'retur') {
                $API->write('/ip/hotspot/user/remove', false);
                $API->write('=.id=' . $usr['.id']);
                $API->read();
                $del = $db->prepare("DELETE FROM login_history WHERE username = :u");
                $del->execute([':u' => $uname]);
                $deleted_any = true;
                continue;
              }
              if ($is_rusak && !$is_retur && $target_status === 'rusak') {
                $API->write('/ip/hotspot/user/remove', false);
                $API->write('=.id=' . $usr['.id']);
                $API->read();
                $del = $db->prepare("DELETE FROM login_history WHERE username = :u");
                $del->execute([':u' => $uname]);
                $deleted_any = true;
                continue;
              }
              if ($target_status === 'terpakai' && !$is_rusak && !$is_retur) {
                $bytes = (int)(($usr['bytes-in'] ?? 0) + ($usr['bytes-out'] ?? 0));
                $uptime = $usr['uptime'] ?? '';
                $cm = extract_ip_mac_from_comment($cmt);
                $is_used = ($bytes > 50 || ($uptime != '' && $uptime != '0s') || ($cm['ip'] ?? '') != '');
                if ($is_used) {
                  $API->write('/ip/hotspot/user/remove', false);
                  $API->write('=.id=' . $usr['.id']);
                  $API->read();
                  $del = $db->prepare("DELETE FROM login_history WHERE username = :u");
                  $del->execute([':u' => $uname]);
                  $deleted_any = true;
                }
              }
            }
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
              $is_retur_router = stripos($cmt, '(Retur)') !== false || stripos($cmt, 'Retur Ref:') !== false;
              if (!$is_rusak_router) continue;
              if ($is_retur_router) continue;
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
      $blok_norm = extract_blok_name($blok);
      $blok_raw = $blok;
      $blok_cmp = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $blok_norm ?: $blok_raw));
      $to_delete = [];
      foreach ($list as $usr) {
        $c = $usr['comment'] ?? '';
        $uname = $usr['name'] ?? '';
        if ($uname !== '' && isset($active_names[$uname])) {
          continue; // jangan hapus user online
        }
        $cblok = extract_blok_name($c);
        $cblok_cmp = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $cblok));
        if (($cblok != '' && strcasecmp($cblok, $blok_norm) == 0) || ($cblok_cmp != '' && $cblok_cmp == $blok_cmp) || ($blok_raw != '' && stripos($c, $blok_raw) !== false)) {
          $to_delete[] = ['id' => $usr['.id'], 'name' => $uname];
        }
      }
      foreach ($to_delete as $d) {
        if (!empty($d['id'])) {
          $API->write('/ip/hotspot/user/remove', false);
          $API->write('=.id=' . $d['id']);
          $API->read();
        }
        // Jangan hapus histori pemakaian (login_history) saat hapus blok
      }
      // Jangan hapus histori pemakaian per blok di login_history
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
          $ainfo = $API->comm('/ip/hotspot/active/print', [
            '?server' => $hotspot_server,
            '?user' => $name,
            '.proplist' => 'user,uptime,address,mac-address,bytes-in,bytes-out'
          ]);
          $urow = $uinfo[0] ?? [];
          $arow = $ainfo[0] ?? [];
          $bytes_total = ($urow['bytes-in'] ?? 0) + ($urow['bytes-out'] ?? 0);
          $bytes_active = ($arow['bytes-in'] ?? 0) + ($arow['bytes-out'] ?? 0);
          $bytes_hist = (int)($hist['last_bytes'] ?? 0);
          $bytes_final = max((int)$bytes_total, (int)$bytes_active, $bytes_hist);
          $uptime_user = $urow['uptime'] ?? '';
          $uptime_active = $arow['uptime'] ?? '';
          $uptime_hist = $hist['last_uptime'] ?? '';
          $base_total = max(uptime_to_seconds($uptime_user), uptime_to_seconds($uptime_hist));
          if (!empty($uptime_active)) {
            $uptime_final = seconds_to_uptime($base_total + uptime_to_seconds($uptime_active));
          } else {
            $uptime_final = $uptime_user != '' ? $uptime_user : ($uptime_hist != '' ? $uptime_hist : '0s');
          }
          $comment_src = $urow['comment'] ?? $comm;
          $cm = extract_ip_mac_from_comment($comment_src);
          $ip_final = $arow['address'] ?? ($cm['ip'] ?? ($hist['last_ip'] ?? ($hist['ip_address'] ?? '-')));
          $mac_final = $arow['mac-address'] ?? ($urow['mac-address'] ?? ($cm['mac'] ?? ($hist['last_mac'] ?? ($hist['mac_address'] ?? '-'))));
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
            'first_ip' => (!empty($hist['first_ip']) ? $hist['first_ip'] : ($ip_final ?: '')),
            'first_mac' => (!empty($hist['first_mac']) ? $hist['first_mac'] : ($mac_final ?: '')),
            'last_ip' => ($ip_final && $ip_final != '-') ? $ip_final : ($hist['last_ip'] ?? ''),
            'last_mac' => ($mac_final && $mac_final != '-') ? $mac_final : ($hist['last_mac'] ?? ''),
            'blok' => extract_blok_name($comm),
            'raw' => $new_c,
            'login_time_real' => $login_time_real,
            'logout_time_real' => $logout_time_real,
            'status' => 'rusak'
          ];
          save_user_history($name, $save_data);

          // Update status transaksi agar laporan live berkurang
          if ($db && $name != '') {
            try {
              $stmt = $db->prepare("UPDATE sales_history SET status='rusak', is_rusak=1, is_retur=0, is_invalid=0 WHERE username = :u");
              $stmt->execute([':u' => $name]);
            } catch(Exception $e) {}
            try {
              $stmt = $db->prepare("UPDATE live_sales SET status='rusak', is_rusak=1, is_retur=0, is_invalid=0 WHERE username = :u AND sync_status = 'pending'");
              $stmt->execute([':u' => $name]);
            } catch(Exception $e) {}
          }
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

          // Kembalikan pendapatan yang sempat berkurang karena RUSAK
          try {
            $stmt = $db->prepare("UPDATE sales_history SET status='normal', is_rusak=0, is_retur=0, is_invalid=0 WHERE username = :u");
            $stmt->execute([':u' => $name]);
          } catch(Exception $e) {}
          try {
            $stmt = $db->prepare("UPDATE live_sales SET status='normal', is_rusak=0, is_retur=0, is_invalid=0 WHERE username = :u AND sync_status = 'pending'");
            $stmt->execute([':u' => $name]);
          } catch(Exception $e) {}
        }

        // Hapus voucher lama
        $del_id = $uid ?: ($uinfo['.id'] ?? '');
        if ($del_id != '') {
          $API->write('/ip/hotspot/user/remove', false);
          $API->write('=.id=' . $del_id);
          $API->read();
        }

        // Generate voucher baru
        $gen = gen_user($prof ?: 'default', $comm ?: $name, $name);
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
  if (isset($_GET['show'])) $redir_params['show'] = $_GET['show'];
  if (isset($_GET['date'])) $redir_params['date'] = $_GET['date'];
  if (isset($_GET['debug'])) $redir_params['debug'] = $_GET['debug'];
  if (isset($_GET['only_wartel'])) $redir_params['only_wartel'] = $_GET['only_wartel'];
  $redir = './?' . http_build_query($redir_params);
  if ($is_action_ajax) {
    header('Content-Type: application/json');
    echo json_encode([
      'ok' => !$action_blocked,
      'message' => $action_blocked ? $action_error : 'Berhasil diproses.',
      'redirect' => $action_blocked ? '' : $redir
    ]);
    exit();
  }
  if ($action_blocked) {
    echo "<script>if(window.showActionPopup){window.showActionPopup('error','" . addslashes($action_error) . "');}else{alert('" . addslashes($action_error) . "');}</script>";
  } else {
    echo "<script>if(window.showActionPopup){window.showActionPopup('success','Berhasil diproses.','{$redir}');}else{window.location.href='{$redir}';}</script>";
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

$is_ajax = isset($_GET['ajax']) && $_GET['ajax'] == '1';

// Tambahkan data history-only agar TERPAKAI/RUSAK/RETUR tetap tampil
if ($db) {
  try {
    $need_history = in_array(strtolower($req_status), ['used','rusak','retur','all']) || trim($req_search) !== '';
    if ($need_history) {
      $res = $db->query("SELECT username, raw_comment, last_status, last_bytes, last_uptime, ip_address, mac_address, blok_name FROM login_history WHERE username IS NOT NULL AND username != ''");
      $existing = [];
      foreach ($all_users as $u) {
        if (!empty($u['name'])) $existing[$u['name']] = true;
      }
      foreach ($res->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $uname = $row['username'] ?? '';
        if ($uname === '' || isset($existing[$uname])) continue;
        $comment = (string)($row['raw_comment'] ?? '');
        $hist_blok = (string)($row['blok_name'] ?? '');
        if ($only_wartel && !is_wartel_client($comment, $hist_blok)) continue;
        $st = strtolower((string)($row['last_status'] ?? ''));
        $bytes_hist = (int)($row['last_bytes'] ?? 0);
        $uptime_hist = (string)($row['last_uptime'] ?? '');
        $ip_hist = (string)($row['ip_address'] ?? '');
        $mac_hist = (string)($row['mac_address'] ?? '');
        $cm = extract_ip_mac_from_comment($comment);
        $ip_use = $ip_hist !== '' && $ip_hist !== '-' ? $ip_hist : ($cm['ip'] ?? '');
        $mac_use = $mac_hist !== '' && $mac_hist !== '-' ? $mac_hist : ($cm['mac'] ?? '');

        $h_comment_rusak = preg_match('/\bAudit:\s*RUSAK\b/i', $comment) || preg_match('/^\s*RUSAK\b/i', $comment);
        $h_is_rusak = ($st === 'rusak') || $h_comment_rusak;
        $h_is_retur = ($st === 'retur') || (stripos($comment, '(Retur)') !== false) || (stripos($comment, 'Retur Ref:') !== false);
        if ($st === 'retur') {
          $h_is_retur = true;
          $h_is_rusak = false;
        } elseif ($st === 'rusak') {
          $h_is_rusak = true;
          $h_is_retur = false;
        } elseif ($h_is_rusak) {
          $h_is_retur = false;
        }
        $h_is_used = (!$h_is_rusak && !$h_is_retur) && (
          $bytes_hist > 50 ||
          ($uptime_hist !== '' && $uptime_hist !== '0s') ||
          ($ip_use !== '' && $ip_use !== '-')
        );
        $h_status = 'READY';
        if ($h_is_rusak) $h_status = 'RUSAK';
        elseif ($h_is_retur) $h_status = 'RETUR';
        elseif ($h_is_used) $h_status = 'TERPAKAI';

        if ($req_status === 'used' && $h_status !== 'TERPAKAI') continue;
        if ($req_status === 'rusak' && $h_status !== 'RUSAK') continue;
        if ($req_status === 'retur' && $h_status !== 'RETUR') continue;
        if ($req_status === 'all' && $h_status === 'READY') continue;
        $all_users[] = [
          'name' => $uname,
          'comment' => $comment,
          'profile' => '',
          'disabled' => $h_status === 'RUSAK' ? 'true' : 'false',
          'bytes-in' => $bytes_hist,
          'bytes-out' => 0,
          'uptime' => $uptime_hist
        ];
        $existing[$uname] = true;
      }
    }
  } catch (Exception $e) {}
}

// List blok untuk dropdown (DB + data router agar langsung muncul)
$list_blok = [];
if (!$is_ajax) {
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
    if ($only_wartel && !is_wartel_client($comment, $f_blok)) {
      continue;
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

    $comment_rusak = preg_match('/\bAudit:\s*RUSAK\b/i', $comment) || preg_match('/^\s*RUSAK\b/i', $comment);
    $is_rusak = $comment_rusak;
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
    if ($hist_status === 'retur') {
      $is_retur = true;
      $is_rusak = false;
    } elseif ($hist_status === 'rusak') {
      $is_rusak = true;
      $is_retur = false;
    }
    // Retur harus tetap retur meski Retur Ref memuat kata RUSAK
    if ($is_retur && $hist_status !== 'rusak' && $disabled !== 'true') {
      $is_rusak = false;
    }
    if ($is_rusak || $hist_status === 'rusak') {
      $is_retur = false;
    }
    $hist_used = $hist && (
      in_array($hist_status, ['online','terpakai','rusak','retur']) ||
      !empty($hist['login_time_real']) ||
      !empty($hist['logout_time_real']) ||
      (!empty($hist['last_uptime']) && $hist['last_uptime'] != '0s') ||
      (int)($hist['last_bytes'] ?? 0) > 0
    );
    $is_used = (!$is_retur && !$is_rusak && $disabled !== 'true') &&
      ($is_active || $bytes > 50 || $uptime != '0s' || $hist_used);

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

    // Simpan waktu login/logout dan status ke DB (back-calculation)
    $now = date('Y-m-d H:i:s');
    $login_time_real = $hist['login_time_real'] ?? null;
    $logout_time_real = $hist['logout_time_real'] ?? null;
    $last_status_db = strtolower($hist['last_status'] ?? 'ready');
    $db_updated_at = $hist['updated_at'] ?? null;
    $u_sec = uptime_to_seconds($uptime);

    if ($is_active) {
      $logout_time_real = null;
      if (empty($login_time_real)) {
        $login_time_real = ($u_sec > 0) ? date('Y-m-d H:i:s', time() - $u_sec) : $now;
      }
    } else {
      if (!empty($hist['login_time_real']) && !empty($hist['logout_time_real'])) {
        $login_time_real = $hist['login_time_real'];
        $logout_time_real = $hist['logout_time_real'];
      } else {
        if (empty($logout_time_real)) {
          if ($last_status_db === 'online') {
            $logout_time_real = $now;
          } elseif (!empty($db_updated_at) && ($status === 'TERPAKAI' || $status === 'RUSAK')) {
            $logout_time_real = $db_updated_at;
          } else {
            $comment_dt = extract_datetime_from_comment($comment);
            if ($comment_dt != '') {
              if (!($status === 'TERPAKAI' && substr($comment_dt, -8) === '00:00:00')) {
                $logout_time_real = $comment_dt;
              }
            }
          }
        }
        if (!empty($logout_time_real) && substr($logout_time_real, -8) === '00:00:00' && !empty($db_updated_at)) {
          $logout_time_real = merge_date_time($logout_time_real, $db_updated_at);
        }
        if (empty($login_time_real) && !empty($logout_time_real) && $u_sec > 0) {
          $login_time_real = date('Y-m-d H:i:s', strtotime($logout_time_real) - $u_sec);
        }
        if (!empty($login_time_real) && empty($logout_time_real) && $u_sec > 0) {
          $logout_time_real = date('Y-m-d H:i:s', strtotime($login_time_real) + $u_sec);
        }
        if ($status === 'TERPAKAI' && empty($login_time_real) && empty($logout_time_real)) {
          if (!empty($db_updated_at) && $u_sec > 0) {
            $logout_time_real = $db_updated_at;
            $login_time_real = date('Y-m-d H:i:s', strtotime($db_updated_at) - $u_sec);
          }
        }
      }
    }
    if ($status === 'READY') {
      $login_time_real = null;
      $logout_time_real = null;
    }

      // Filter tanggal (harian/bulanan/tahunan) - abaikan untuk READY
      if ($req_status !== 'used' && $req_show !== 'semua' && !empty($filter_date) && $status !== 'READY' && $status !== 'TERPAKAI' && $status !== 'ONLINE') {
        $comment_dt = extract_datetime_from_comment($comment);
        $hist_dt = $hist['last_login_real'] ?? ($hist['first_login_real'] ?? ($hist['updated_at'] ?? ''));
        $date_candidate = $comment_dt !== '' ? $comment_dt : ($login_time_real ?: $logout_time_real ?: $hist_dt);
        $date_key = normalize_date_key($date_candidate, $req_show);
        if ($date_key !== '' && $date_key !== $filter_date) {
          continue;
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
    if (empty($first_login_real)) {
      if (!empty($login_time_real)) $first_login_real = $login_time_real;
      elseif (!empty($hist['login_time_real'])) $first_login_real = $hist['login_time_real'];
      elseif (!empty($hist['first_login_real'])) $first_login_real = $hist['first_login_real'];
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

    if ($db && !$read_only && $name != '') {
        $should_save = false;
        if (!$hist) {
          $should_save = true;
        } else {
          $should_save = (
            strtolower((string)($hist['last_status'] ?? '')) !== $next_status ||
            (string)($hist['last_uptime'] ?? '') !== (string)$uptime ||
            (int)($hist['last_bytes'] ?? 0) !== (int)$bytes ||
            (string)($hist['ip_address'] ?? '') !== (string)$f_ip ||
            (string)($hist['mac_address'] ?? '') !== (string)$f_mac ||
            (string)($hist['login_time_real'] ?? '') !== (string)($login_time_real ?? '') ||
            (string)($hist['logout_time_real'] ?? '') !== (string)($logout_time_real ?? '')
          );
        }
        if ($should_save) {
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
              'status' => $next_status,
              'updated_at' => ($next_status === 'online') ? date('Y-m-d H:i:s') : null
          ];
          save_user_history($name, $save_data);
          $hist = get_user_history($name);
        }
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
    if ($req_status == 'online' && $f_blok == '') continue;
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

    $login_disp = $login_time_real ?? ($hist['login_time_real'] ?? ($hist['first_login_real'] ?? '-'));
    $logout_disp = $logout_time_real ?? ($hist['logout_time_real'] ?? ($hist['last_login_real'] ?? '-'));
    if ($status === 'READY') {
      $login_disp = '-';
      $logout_disp = '-';
    }
    if ($status === 'RETUR') {
      $login_disp = '-';
      $logout_disp = '-';
    }
    if ($status === 'TERPAKAI' && $logout_disp !== '-' && substr($logout_disp, -8) === '00:00:00') {
      // fallback: jika logout masih jam 00:00:00 dan ada login+uptime, hitung ulang logout
      $base_uptime = $uptime_hist != '' ? $uptime_hist : $uptime_user;
      $u_sec = uptime_to_seconds($base_uptime);
      if ($login_disp !== '-' && $u_sec > 0) {
        $logout_disp = date('Y-m-d H:i:s', strtotime($login_disp) + $u_sec);
      }
    }
    if ($status === 'RUSAK') {
      $uptime_sec = uptime_to_seconds($uptime);
      $show_rusak_times = ($uptime_sec > 0 || $bytes > 0) && $uptime_sec <= 180 && $bytes <= (1 * 1024 * 1024);
      if (!$show_rusak_times) {
        $login_disp = '-';
        $logout_disp = '-';
      }
    }
    if ($login_disp !== '-' && strtotime($login_disp) !== false && date('Y', strtotime($login_disp)) < 2000) {
      $login_disp = '-';
    }
    if ($logout_disp !== '-' && strtotime($logout_disp) !== false && date('Y', strtotime($logout_disp)) < 2000) {
      $logout_disp = '-';
    }
    if ($logout_disp !== '-' && substr($logout_disp, -8) === '00:00:00' && !empty($hist['updated_at'])) {
      $logout_disp = merge_date_time($logout_disp, $hist['updated_at']);
    }

    $relogin_flag = ((int)($hist['login_count'] ?? 0) > 1);
    $first_login_disp = $first_login_real ?? ($hist['first_login_real'] ?? '-');
    $display_data[] = [
      'uid' => $u['.id'] ?? '',
        'name' => $name,
        'profile' => $u['profile'] ?? '',
        'blok' => $f_blok,
        'ip' => $f_ip,
        'mac' => $f_mac,
      'comment' => $comment,
        'first_login' => $first_login_disp,
        'retur_ref' => $is_retur ? extract_retur_ref($comment) : '',
        'uptime' => $uptime,
        'bytes' => $bytes,
        'status' => $status,
        'login_time' => $login_disp,
        'logout_time' => $logout_disp,
        'relogin' => $relogin_flag
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

if ($is_ajax) {
  ob_start();
  if (count($display_data) > 0) {
    foreach ($display_data as $u) {
      $keep_params = '&profile=' . urlencode($req_prof) .
        '&comment=' . urlencode($req_comm) .
        '&status=' . urlencode($req_status) .
        '&q=' . urlencode($req_search) .
        '&show=' . urlencode($req_show) .
        '&date=' . urlencode($filter_date);
      ?>
      <tr>
        <td>
          <div style="font-size:15px; font-weight:bold; color:var(--txt-main)">
            <?= htmlspecialchars($u['name']) ?>
            <?php if(!empty($u['relogin'])): ?><span class="status-badge st-relogin" style="margin-left:6px;">RELOGIN</span><?php endif; ?>
          </div>
          <div style="font-size:11px; color:var(--txt-muted); max-width:200px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($u['comment']) ?>">
            First login: <?= formatDateIndo($u['first_login'] ?? '-') ?>
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
          <?php if (in_array($req_status, ['all','used','rusak','online'], true)): ?>
            <?php if (strtoupper($u['status']) === 'TERPAKAI' && in_array($req_status, ['all','used'], true)): ?>
              <button type="button" class="btn-act btn-act-print" onclick="window.open('./report/print_rincian.php?mode=usage&status=used&user=<?= urlencode($u['name']) ?>&session=<?= $session ?>','_blank').print()" title="Print Bukti Pemakaian"><i class="fa fa-print"></i></button>
            <?php elseif (strtoupper($u['status']) === 'ONLINE' && in_array($req_status, ['all','online'], true)): ?>
              <button type="button" class="btn-act btn-act-print" onclick="window.open('./report/print_rincian.php?mode=usage&status=online&user=<?= urlencode($u['name']) ?>&session=<?= $session ?>','_blank').print()" title="Print Rincian Online"><i class="fa fa-print"></i></button>
            <?php elseif (strtoupper($u['status']) === 'RUSAK' && in_array($req_status, ['all','rusak'], true)): ?>
              <button type="button" class="btn-act btn-act-print" onclick="window.open('./report/print_rincian.php?mode=usage&status=rusak&user=<?= urlencode($u['name']) ?>&session=<?= $session ?>','_blank').print()" title="Print Rincian Rusak"><i class="fa fa-print"></i></button>
            <?php elseif ($req_status === 'all'): ?>
              <button type="button" class="btn-act btn-act-print" onclick="window.open('./voucher/print.php?user=vc-<?= htmlspecialchars($u['name']) ?>&small=yes&session=<?= $session ?>','_blank').print()" title="Print Voucher"><i class="fa fa-print"></i></button>
            <?php endif; ?>
          <?php endif; ?>
          <?php if($u['uid']): ?>
            <?php if (strtoupper($u['status']) === 'RETUR'): ?>
              <button type="button" class="btn-act btn-act-print" onclick="window.open('./voucher/print.php?user=vc-<?= htmlspecialchars($u['name']) ?>&small=yes&download=1&img=1&session=<?= $session ?>','_blank')" title="Download Voucher (PNG)"><i class="fa fa-download"></i></button>
            <?php elseif (strtoupper($u['status']) === 'RUSAK'): ?>
              <button type="button" class="btn-act btn-act-retur" onclick="actionRequest('./?hotspot=users&action=retur&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&p=<?= urlencode($u['profile']) ?>&c=<?= urlencode($u['comment']) ?>&session=<?= $session ?><?= $keep_params ?>','RETUR Voucher <?= htmlspecialchars($u['name']) ?>?')" title="Retur"><i class="fa fa-exchange"></i></button>
              <button type="button" class="btn-act btn-act-invalid" onclick="actionRequest('./?hotspot=users&action=rollback&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&c=<?= urlencode($u['comment']) ?>&session=<?= $session ?><?= $keep_params ?>','Rollback RUSAK <?= htmlspecialchars($u['name']) ?>?')" title="Rollback"><i class="fa fa-undo"></i></button>
            <?php else: ?>
              <button type="button" class="btn-act btn-act-invalid" onclick="actionRequest('./?hotspot=users&action=invalid&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&c=<?= urlencode($u['comment']) ?>&session=<?= $session ?><?= $keep_params ?>','SET RUSAK <?= htmlspecialchars($u['name']) ?>?')" title="Rusak"><i class="fa fa-ban"></i></button>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php
    }
  } else {
    ?><tr><td colspan="8" class="text-center py-4 text-muted">Tidak ada data.</td></tr><?php
  }
  $rows_html = ob_get_clean();

  ob_start();
  if ($total_pages > 1) {
    ?>
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
    <?php
  }
  $pagination_html = ob_get_clean();

  header('Content-Type: application/json');
  echo json_encode([
    'rows_html' => $rows_html,
    'pagination_html' => $pagination_html,
    'total_label' => 'Total: ' . $total_items . ' Items'
  ]);
  exit();
}

if ($debug_mode && !$is_ajax) {
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
    .st-relogin { background: #9b59b6; color: #fff; }
    .id-badge { font-family: 'Courier New', monospace; background: #3d454d; color: #fff; padding: 3px 6px; border-radius: 4px; font-weight: bold; border: 1px solid #56606a; }
    .btn-act { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 4px; border: none; color: white; transition: all 0.2s; margin: 0 2px; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
    .btn-act, .btn, button, .search-clear-btn, .custom-select-solid { cursor: pointer; }
    .btn-act-print { background: var(--c-blue); } .btn-act-retur { background: var(--c-orange); } .btn-act-invalid { background: var(--c-red); }
    .toolbar-container { padding: 15px; background: rgba(0,0,0,0.15); border-bottom: 1px solid var(--border-col); }
    .toolbar-row { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; justify-content: space-between; }
    .toolbar-left { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; flex: 1 1 auto; }
    .toolbar-right { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; justify-content: flex-end; flex: 0 0 auto; margin-left: auto; }
    .input-group-solid { display: flex; flex-grow: 1; max-width: 100%; gap: 0; }
    .input-group-solid .form-control, .input-group-solid .custom-select-solid { height: 40px; background: #343a40; border: 1px solid var(--border-col); color: white; padding: 0 12px; font-size: 0.9rem; border-radius: 0; }
    .input-group-solid .form-control:focus, .input-group-solid .custom-select-solid:focus {
      outline: none; box-shadow: none; border-color: var(--border-col);
    }
    .input-group-solid .first-el { border-top-left-radius: 6px; border-bottom-left-radius: 6px; }
    .input-group-solid .last-el { border-top-right-radius: 6px; border-bottom-right-radius: 6px; border-left: none; }
    .input-group-solid .mid-el { border-left: none; border-right: none; }
    .input-group-solid .no-sep-right { border-right: none; border-top-right-radius: 0; border-bottom-right-radius: 0; }
    .input-group-solid .no-sep-left { border-left: none; border-top-left-radius: 0; border-bottom-left-radius: 0; }
    .period-group { margin-left: auto; gap: 0; flex-wrap: nowrap; }
    .period-group .custom-select-solid, .period-group .form-control { margin: 0; }
    .search-wrap { position: relative; display: flex; align-items: center; flex: 1 1 420px; min-width: 280px; }
    .search-wrap .form-control { padding-right: 36px; width: 100%; }
    .search-clear-btn { position: absolute; right: 8px; width: 22px; height: 22px; border-radius: 50%; border: none; background: #495057; color: #fff; font-size: 12px; line-height: 22px; display: none; }
    .search-clear-btn:hover { background: #6c757d; }
    .page-dim { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 9999; }
    .page-dim .spinner { color: #ecf0f1; font-size: 14px; display: flex; align-items: center; gap: 8px; }
    .action-banner { position: fixed; top: 0; left: 0; right: 0; display: none; align-items: center; justify-content: center; gap: 10px; padding: 12px 16px; z-index: 10000; font-weight: 600; }
    .action-banner.success { background: #16a34a; color: #fff; }
    .action-banner.error { background: #dc2626; color: #fff; }
    .confirm-modal { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,0.55); z-index: 10001; }
    .confirm-card { background: #1f2937; color: #e5e7eb; border-radius: 10px; padding: 16px; width: 360px; box-shadow: 0 8px 20px rgba(0,0,0,0.4); }
    .confirm-title { font-weight: 700; margin-bottom: 8px; }
    .confirm-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 12px; }
  </style>

<div class="row">
  <div id="page-dim" class="page-dim" aria-hidden="true">
    <div class="spinner"><i class="fa fa-circle-o-notch fa-spin"></i> Memproses...</div>
  </div>
  <div id="action-banner" class="action-banner" aria-live="polite"></div>
  <div id="confirm-modal" class="confirm-modal">
    <div class="confirm-card">
      <div class="confirm-title">Konfirmasi</div>
      <div id="confirm-message"></div>
      <div class="confirm-actions">
        <button type="button" class="btn btn-secondary" id="confirm-cancel">Batal</button>
        <button type="button" class="btn btn-danger" id="confirm-ok">Ya, Lanjutkan</button>
      </div>
    </div>
  </div>
  <div class="col-12">
    <div class="card card-solid">
      <div class="card-header-solid">
        <h3 class="card-title m-0"><i class="fa fa-users mr-2"></i> Manajemen Voucher</h3>
        <span id="users-total" class="badge badge-secondary p-2" style="font-size:14px">Total: <?= $total_items ?> Items</span>
      </div>
      <div class="toolbar-container">
        <form action="?" method="GET" class="toolbar-row m-0" id="users-toolbar-form">
          <input type="hidden" name="hotspot" value="users">
          <input type="hidden" name="session" value="<?= $session ?>">

          <div class="toolbar-left">
            <div class="input-group-solid">
              <div class="search-wrap">
                <input type="text" name="q" value="<?= htmlspecialchars($req_search) ?>" class="form-control first-el" placeholder="Cari User... (pisah dengan koma / spasi)" autocomplete="off">
                <button type="button" class="search-clear-btn" id="search-clear" title="Clear">×</button>
              </div>

              <select name="status" class="custom-select-solid mid-el" onchange="this.form.submit()" style="flex: 0 0 220px;">
                <option value="all" <?=($req_status=='all'?'selected':'')?>>Status: Semua</option>
                <option value="ready" <?=($req_status=='ready'?'selected':'')?>>🟢 Hanya Ready</option>
                <option value="online" <?=($req_status=='online'?'selected':'')?>>🔵 Sedang Online</option>
                <option value="used" <?=($req_status=='used'?'selected':'')?>>⚪ Sudah Terpakai</option>
                <option value="rusak" <?=($req_status=='rusak'?'selected':'')?>>🟠 Rusak / Error</option>
                <option value="retur" <?=($req_status=='retur'?'selected':'')?>>🟣 Hasil Retur</option>
              </select>

              <select name="comment" class="custom-select-solid last-el" onchange="this.form.submit()" style="flex: 0 0 220px;">
                <option value="">Semua Blok</option>
                  <?php foreach($list_blok as $b) {
                    $label = preg_replace('/^BLOK-/i', 'BLOK ', $b);
                    $sel = (strcasecmp($req_comm, $b) == 0) ? 'selected' : '';
                    echo "<option value='$b' $sel>$label</option>";
                } ?>
              </select>
            </div>
            <div class="input-group-solid period-group">
              <select name="show" class="custom-select-solid first-el no-sep-right" onchange="this.form.submit()" style="flex: 0 0 140px;">
                <option value="semua" <?= $req_show==='semua'?'selected':''; ?> style="display:none;">Semua</option>
                <option value="harian" <?= $req_show==='harian'?'selected':''; ?>>Harian</option>
                <option value="bulanan" <?= $req_show==='bulanan'?'selected':''; ?>>Bulanan</option>
                <option value="tahunan" <?= $req_show==='tahunan'?'selected':''; ?>>Tahunan</option>
              </select>
              <?php if ($req_show === 'harian'): ?>
                <input type="date" name="date" value="<?= htmlspecialchars($filter_date); ?>" onchange="this.form.submit()" class="form-control last-el no-sep-left" style="flex:0 0 170px;">
              <?php elseif ($req_show === 'bulanan'): ?>
                <input type="month" name="date" value="<?= htmlspecialchars($filter_date); ?>" onchange="this.form.submit()" class="form-control last-el no-sep-left" style="flex:0 0 170px;">
              <?php else: ?>
                <?php if ($req_show === 'tahunan'): ?>
                  <input type="number" name="date" min="2000" max="2100" value="<?= htmlspecialchars($filter_date); ?>" onchange="this.form.submit()" class="form-control last-el no-sep-left" style="flex:0 0 120px;">
                <?php endif; ?>
              <?php endif; ?>
            </div>
            <span id="search-loading" style="display:none;font-size:12px;color:var(--txt-muted);margin-left:6px;">
              <i class="fa fa-circle-o-notch fa-spin"></i> Mencari...
            </span>
          </div>
          <?php
            $status_labels = ['used' => 'Terpakai', 'retur' => 'Retur', 'rusak' => 'Rusak'];
            $can_delete_status = in_array($req_status, array_keys($status_labels));
            $status_label = $status_labels[$req_status] ?? '';
            $can_print_block = ($req_comm != '' && $req_status === 'ready');
            $can_print_status = ($req_comm != '' && $req_status === 'retur');
            $can_print_used = ($req_status === 'used');
            $can_print_online = ($req_status === 'online');
            $can_print_rusak = ($req_status === 'rusak');
            $reset_params = $_GET;
            $reset_params['status'] = 'all';
            unset($reset_params['page']);
            $reset_url = './?' . http_build_query($reset_params);
          ?>
          <div class="toolbar-right">
            <?php if ($req_status !== 'all'): ?>
              <button type="button" class="btn btn-outline-light" style="height:40px;" onclick="location.href='<?= $reset_url ?>'">
                <i class="fa fa-undo"></i> Reset Status
              </button>
            <?php endif; ?>
            <?php if ($req_status === 'all' && $req_comm == ''): ?>
              <?php
                $today_params = $_GET;
                $today_params['show'] = 'harian';
                $today_params['date'] = date('Y-m-d');
                unset($today_params['page']);
                $today_url = './?' . http_build_query($today_params);
              ?>
              <button type="button" class="btn btn-outline-light" style="height:40px;" onclick="location.href='<?= $today_url ?>'">
                <i class="fa fa-calendar"></i> Reset Tanggal
              </button>
            <?php endif; ?>
            <?php if ($can_print_used): ?>
              <?php
                $usage_params = [
                  'mode' => 'usage',
                  'status' => 'used',
                  'session' => $session
                ];
                if ($req_comm != '') $usage_params['blok'] = $req_comm;
                $usage_url = './report/print_rincian.php?' . http_build_query($usage_params);
              ?>
              <button type="button" class="btn btn-secondary" style="height:40px;" onclick="window.open('<?= $usage_url ?>','_blank').print()">
                <i class="fa fa-print"></i> Print Terpakai
              </button>
            <?php endif; ?>
            <?php if ($can_print_online): ?>
              <?php
                $usage_params = [
                  'mode' => 'usage',
                  'status' => 'online',
                  'session' => $session
                ];
                if ($req_comm != '') $usage_params['blok'] = $req_comm;
                $usage_url = './report/print_rincian.php?' . http_build_query($usage_params);
              ?>
              <button type="button" class="btn btn-secondary" style="height:40px;" onclick="window.open('<?= $usage_url ?>','_blank').print()">
                <i class="fa fa-print"></i> Print Online
              </button>
            <?php endif; ?>
            <?php if ($can_print_rusak): ?>
              <?php
                $usage_params = [
                  'mode' => 'usage',
                  'status' => 'rusak',
                  'session' => $session
                ];
                if ($req_comm != '') $usage_params['blok'] = $req_comm;
                $usage_url = './report/print_rincian.php?' . http_build_query($usage_params);
              ?>
              <button type="button" class="btn btn-secondary" style="height:40px;" onclick="window.open('<?= $usage_url ?>','_blank').print()">
                <i class="fa fa-print"></i> Print Rusak
              </button>
            <?php endif; ?>
            <?php if ($req_comm == '' && $can_delete_status): ?>
              <button type="button" class="btn btn-warning" style="height:40px;" onclick="actionRequest('./?hotspot=users&action=delete_status&status=<?= $req_status ?>&session=<?= $session ?>','Hapus semua voucher <?= $status_label ?> (tidak online)?')">
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
              <?php
                $print_all_params = [
                  'mode' => 'usage',
                  'status' => 'all',
                  'session' => $session,
                  'blok' => $req_comm
                ];
                $print_all_url = './report/print_rincian.php?' . http_build_query($print_all_params);
              ?>
              <?php if ($req_status === 'all'): ?>
                <button type="button" class="btn btn-secondary" style="height:40px;" onclick="window.open('<?= $print_all_url ?>','_blank').print()">
                  <i class="fa fa-print"></i> Print Bukti
                </button>
              <?php endif; ?>
              <?php if ($can_delete_status): ?>
                <button type="button" class="btn btn-warning" style="height:40px;" onclick="actionRequest('./?hotspot=users&action=delete_status&status=<?= $req_status ?>&blok=<?= urlencode($req_comm) ?>&session=<?= $session ?>','Hapus semua voucher <?= $status_label ?> di <?= htmlspecialchars($req_comm) ?> (tidak online)?')">
                  <i class="fa fa-trash"></i> Hapus <?= $status_label ?>
                </button>
              <?php endif; ?>
              <?php if ($req_status == 'all'): ?>
                <button type="button" class="btn btn-danger" style="height:40px;" onclick="actionRequest('./?hotspot=users&action=batch_delete&blok=<?= urlencode($req_comm) ?>&session=<?= $session ?>','Hapus semua voucher di <?= htmlspecialchars($req_comm) ?>?')">
                  <i class="fa fa-trash"></i> Hapus Blok
                </button>
              <?php endif; ?>
            <?php endif; ?>
          </div>
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
            <tbody id="users-table-body">
              <?php if(count($display_data) > 0): ?>
                <?php foreach($display_data as $u): ?>
                  <tr>
                    <td>
                      <div style="font-size:15px; font-weight:bold; color:var(--txt-main)">
                        <?= htmlspecialchars($u['name']) ?>
                        <?php if(!empty($u['relogin'])): ?><span class="status-badge st-relogin" style="margin-left:6px;">RELOGIN</span><?php endif; ?>
                      </div>
                      <div style="font-size:11px; color:var(--txt-muted); max-width:200px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($u['comment']) ?>">
                        First login: <?= formatDateIndo($u['first_login'] ?? '-') ?>
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
                      <?php if (in_array($req_status, ['all','used','rusak','online'], true)): ?>
                        <?php if (strtoupper($u['status']) === 'TERPAKAI' && in_array($req_status, ['all','used'], true)): ?>
                          <button type="button" class="btn-act btn-act-print" onclick="window.open('./report/print_rincian.php?mode=usage&status=used&user=<?= urlencode($u['name']) ?>&session=<?= $session ?>','_blank').print()" title="Print Bukti Pemakaian"><i class="fa fa-print"></i></button>
                        <?php elseif (strtoupper($u['status']) === 'ONLINE' && in_array($req_status, ['all','online'], true)): ?>
                          <button type="button" class="btn-act btn-act-print" onclick="window.open('./report/print_rincian.php?mode=usage&status=online&user=<?= urlencode($u['name']) ?>&session=<?= $session ?>','_blank').print()" title="Print Rincian Online"><i class="fa fa-print"></i></button>
                        <?php elseif (strtoupper($u['status']) === 'RUSAK' && in_array($req_status, ['all','rusak'], true)): ?>
                          <button type="button" class="btn-act btn-act-print" onclick="window.open('./report/print_rincian.php?mode=usage&status=rusak&user=<?= urlencode($u['name']) ?>&session=<?= $session ?>','_blank').print()" title="Print Rincian Rusak"><i class="fa fa-print"></i></button>
                        <?php elseif ($req_status === 'all'): ?>
                          <button type="button" class="btn-act btn-act-print" onclick="window.open('./voucher/print.php?user=vc-<?= htmlspecialchars($u['name']) ?>&small=yes&session=<?= $session ?>','_blank').print()" title="Print Voucher"><i class="fa fa-print"></i></button>
                        <?php endif; ?>
                      <?php endif; ?>
                      <?php if($u['uid']): ?>
                        <?php
                          $keep_params = '&profile=' . urlencode($req_prof) .
                            '&comment=' . urlencode($req_comm) .
                            '&status=' . urlencode($req_status) .
                            '&q=' . urlencode($req_search) .
                            '&show=' . urlencode($req_show) .
                            '&date=' . urlencode($filter_date);
                        ?>
                        <?php if (strtoupper($u['status']) === 'RETUR'): ?>
                          <button type="button" class="btn-act btn-act-print" onclick="window.open('./voucher/print.php?user=vc-<?= htmlspecialchars($u['name']) ?>&small=yes&download=1&img=1&session=<?= $session ?>','_blank')" title="Download Voucher (PNG)"><i class="fa fa-download"></i></button>
                        <?php elseif (strtoupper($u['status']) === 'RUSAK'): ?>
                          <button type="button" class="btn-act btn-act-retur" onclick="actionRequest('./?hotspot=users&action=retur&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&p=<?= urlencode($u['profile']) ?>&c=<?= urlencode($u['comment']) ?>&session=<?= $session ?><?= $keep_params ?>','RETUR Voucher <?= htmlspecialchars($u['name']) ?>?')" title="Retur"><i class="fa fa-exchange"></i></button>
                          <button type="button" class="btn-act btn-act-invalid" onclick="actionRequest('./?hotspot=users&action=rollback&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&c=<?= urlencode($u['comment']) ?>&session=<?= $session ?><?= $keep_params ?>','Rollback RUSAK <?= htmlspecialchars($u['name']) ?>?')" title="Rollback"><i class="fa fa-undo"></i></button>
                        <?php else: ?>
                          <button type="button" class="btn-act btn-act-invalid" onclick="actionRequest('./?hotspot=users&action=invalid&uid=<?= $u['uid'] ?>&name=<?= urlencode($u['name']) ?>&c=<?= urlencode($u['comment']) ?>&session=<?= $session ?><?= $keep_params ?>','SET RUSAK <?= htmlspecialchars($u['name']) ?>?')" title="Rusak"><i class="fa fa-ban"></i></button>
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
        <div id="users-pagination">
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
</div>

<script>
(function(){
  const form = document.getElementById('users-toolbar-form');
  const searchInput = document.querySelector('input[name="q"]');
  const statusSelect = document.querySelector('select[name="status"]');
  const commentSelect = document.querySelector('select[name="comment"]');
  const showSelect = document.querySelector('select[name="show"]');
  const dateInput = document.querySelector('input[name="date"]');
  const tbody = document.getElementById('users-table-body');
  const totalBadge = document.getElementById('users-total');
  const paginationWrap = document.getElementById('users-pagination');
  const searchLoading = document.getElementById('search-loading');
  const clearBtn = document.getElementById('search-clear');
  const pageDim = document.getElementById('page-dim');
  const actionBanner = document.getElementById('action-banner');
  const confirmModal = document.getElementById('confirm-modal');
  const confirmMessage = document.getElementById('confirm-message');
  const confirmOk = document.getElementById('confirm-ok');
  const confirmCancel = document.getElementById('confirm-cancel');
  if (!searchInput || !tbody || !totalBadge || !paginationWrap) return;

  if (clearBtn) {
    clearBtn.style.display = searchInput.value.trim() !== '' ? 'inline-block' : 'none';
  }

  const ajaxBase = './hotspot/users.php';
  const baseParams = new URLSearchParams(window.location.search);

  let lastFetchId = 0;
  let appliedQuery = (baseParams.get('q') || '').trim();

  window.showActionPopup = function(type, message) {
    if (!actionBanner) return;
    actionBanner.classList.remove('success', 'error');
    actionBanner.classList.add(type === 'error' ? 'error' : 'success');
    actionBanner.innerHTML = `<i class="fa ${type === 'error' ? 'fa-times-circle' : 'fa-check-circle'}"></i><span>${message}</span>`;
    actionBanner.style.display = 'flex';
    setTimeout(() => { actionBanner.style.display = 'none'; }, 2800);
  };

  function showConfirm(message) {
    return new Promise((resolve) => {
      if (!confirmModal || !confirmMessage || !confirmOk || !confirmCancel) {
        resolve(true);
        return;
      }
      confirmMessage.textContent = message || 'Lanjutkan aksi ini?';
      confirmModal.style.display = 'flex';
      const cleanup = (result) => {
        confirmModal.style.display = 'none';
        confirmOk.onclick = null;
        confirmCancel.onclick = null;
        try { document.activeElement && document.activeElement.blur(); } catch (e) {}
        try { document.body.focus(); } catch (e) {}
        resolve(result);
      };
      confirmOk.onclick = () => cleanup(true);
      confirmCancel.onclick = () => cleanup(false);
    });
  }

  window.actionRequest = async function(url, confirmMsg) {
    if (confirmMsg) {
      const ok = await showConfirm(confirmMsg);
      if (!ok) return;
    }
    try {
      if (pageDim) pageDim.style.display = 'flex';
      const ajaxUrl = url + (url.includes('?') ? '&' : '?') + 'ajax=1&action_ajax=1&_=' + Date.now();
      const res = await fetch(ajaxUrl, { cache: 'no-store' });
      const text = await res.text();
      let data = null;
      try { data = JSON.parse(text); } catch (e) { data = null; }
      if (data && data.ok) {
        window.showActionPopup('success', data.message || 'Berhasil diproses.');
        if (url.includes('action=batch_delete')) {
          window.location.href = './?hotspot=users&session=<?= $session ?>';
          return;
        }
        if (url.includes('action=delete_status')) {
          const params = new URLSearchParams(window.location.search);
          params.set('hotspot', 'users');
          params.set('session', '<?= $session ?>');
          params.set('status', 'all');
          if (commentSelect) params.set('comment', commentSelect.value);
          params.delete('page');
          window.location.href = './?' + params.toString();
          return;
        }
        if (data.redirect) {
          try { history.replaceState(null, '', data.redirect); } catch (e) {}
        }
        fetchUsers(true, false);
      } else if (!data) {
        window.showActionPopup('success', 'Berhasil diproses.');
        if (url.includes('action=batch_delete')) {
          window.location.href = './?hotspot=users&session=<?= $session ?>';
          return;
        }
        if (url.includes('action=delete_status')) {
          const params = new URLSearchParams(window.location.search);
          params.set('hotspot', 'users');
          params.set('session', '<?= $session ?>');
          params.set('status', 'all');
          if (commentSelect) params.set('comment', commentSelect.value);
          params.delete('page');
          window.location.href = './?' + params.toString();
          return;
        }
        fetchUsers(true, false);
      } else {
        window.showActionPopup('error', (data && data.message) ? data.message : 'Gagal memproses.');
      }
    } catch (e) {
      window.showActionPopup('error', 'Gagal memproses.');
    } finally {
      if (pageDim) pageDim.style.display = 'none';
    }
  };

  function buildUrl(isSearch) {
    const params = new URLSearchParams();
    params.set('session', '<?= $session ?>');
    params.set('ajax', '1');
    const qValue = isSearch ? searchInput.value.trim() : appliedQuery;
    params.set('q', qValue);
    if (statusSelect) params.set('status', statusSelect.value);
    if (commentSelect) params.set('comment', commentSelect.value);
    if (showSelect) params.set('show', showSelect.value);
    if (dateInput) params.set('date', dateInput.value);
    const profile = baseParams.get('profile');
    if (profile) params.set('profile', profile);
    const perPage = baseParams.get('per_page');
    if (perPage) params.set('per_page', perPage);
    const debug = baseParams.get('debug');
    if (debug) params.set('debug', debug);
    const ro = baseParams.get('readonly');
    if (ro) params.set('readonly', ro);
    if (isSearch) params.set('page', '1');
    params.set('_', Date.now().toString());
    return ajaxBase + '?' + params.toString();
  }

  async function fetchUsers(isSearch, showLoading) {
    const fetchId = ++lastFetchId;
    try {
      if (isSearch) appliedQuery = searchInput.value.trim();
      if (showLoading && searchLoading) searchLoading.style.display = 'inline-block';
      if (showLoading && pageDim) pageDim.style.display = 'flex';
      const res = await fetch(buildUrl(isSearch), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });
      if (!res.ok) return;
      const data = await res.json();
      if (fetchId !== lastFetchId) return;
      if (typeof data.rows_html === 'string') tbody.innerHTML = data.rows_html;
      if (typeof data.pagination_html === 'string') paginationWrap.innerHTML = data.pagination_html;
      if (typeof data.total_label === 'string') totalBadge.textContent = data.total_label;
    } catch (e) {}
    finally {
      if (showLoading && searchLoading) searchLoading.style.display = 'none';
      if (showLoading && pageDim) pageDim.style.display = 'none';
    }
  }

  // Search hanya saat Enter
  searchInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      const hasQuery = searchInput.value.trim() !== '';
      fetchUsers(true, hasQuery);
    }
  });
  searchInput.addEventListener('input', () => {
    if (clearBtn) {
      clearBtn.style.display = searchInput.value.trim() !== '' ? 'inline-block' : 'none';
    }
  });
  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      if (searchInput.value !== '') {
        searchInput.value = '';
        clearBtn.style.display = 'none';
        fetchUsers(true, true);
      }
    });
  }
  if (form) {
    form.addEventListener('submit', (e) => {
      if (document.activeElement === searchInput) {
        e.preventDefault();
        const hasQuery = searchInput.value.trim() !== '';
        fetchUsers(true, hasQuery);
      }
    });
  }

  setInterval(() => {
    if (document.hidden) return;
    const currentInput = searchInput.value.trim();
    if (currentInput !== appliedQuery) return;
    fetchUsers(false, false);
  }, 15000);
})();
</script>
