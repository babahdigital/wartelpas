<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
if (!isset($_SESSION["mikhmon"]) || !isset($_GET['session'])) {
    header("Location:../admin.php?id=login");
    exit();
}

require_once(__DIR__ . '/../../include/acl.php');
$is_superadmin = isSuperAdmin();

$env = [];
$envFile = __DIR__ . '/../../include/env.php';
if (file_exists($envFile)) {
  require $envFile;
}

$session = $_GET['session'];

$req_prof = isset($_GET['profile']) ? $_GET['profile'] : 'all';
$req_prof = strtolower(trim((string)$req_prof));
if ($req_prof !== 'all') {
  if (preg_match('/(\d+)/', $req_prof, $m)) {
    $req_prof = (string)((int)$m[1]);
  } else {
    $req_prof = 'all';
  }
}
$req_comm = isset($_GET['comment']) ? urldecode($_GET['comment']) : '';
$req_status = isset($_GET['status']) ? strtolower(trim((string)$_GET['status'])) : 'all';
if ($req_status === '') $req_status = 'all';
$req_search = isset($_GET['q']) ? $_GET['q'] : '';
$read_only = isset($_GET['readonly']) && $_GET['readonly'] == '1';
$default_show = in_array($req_status, ['used', 'rusak', 'retur']) ? 'semua' : 'harian';
$req_show = $_GET['show'] ?? $default_show;
$filter_date = $_GET['date'] ?? '';
$req_show = in_array($req_show, ['harian', 'bulanan', 'tahunan', 'semua']) ? $req_show : 'harian';
if ($req_show === 'semua') {
  $filter_date = '';
} elseif ($req_show === 'harian') {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date)) {
    $filter_date = date('Y-m-d');
  }
} elseif ($req_show === 'bulanan') {
  if (!preg_match('/^\d{4}-\d{2}$/', $filter_date)) {
    $filter_date = date('Y-m');
  }
} else {
  $req_show = 'tahunan';
  if (!preg_match('/^\d{4}$/', $filter_date)) {
    $filter_date = date('Y');
  }
}
$debug_mode = isset($_GET['debug']) && $_GET['debug'] == '1';
$enforce_rusak_rules = !(isset($_GET['rusak_free']) && $_GET['rusak_free'] == '1');

include(__DIR__ . '/../../include/config.php');
if (!isset($data[$session])) {
  header("Location:../admin.php?id=login");
  exit();
}
include(__DIR__ . '/../../include/readcfg.php');
include_once(__DIR__ . '/../../lib/routeros_api.class.php');
include_once(__DIR__ . '/../../lib/formatbytesbites.php');

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

// --- DATABASE ---
$system_cfg = $env['system'] ?? [];
$db_rel = $system_cfg['db_file'] ?? 'db_data/mikhmon_stats.db';
if (preg_match('/^[A-Za-z]:\\|^\//', $db_rel)) {
  $dbFile = $db_rel;
} else {
  $dbFile = __DIR__ . '/../../' . ltrim($db_rel, '/');
}
$dbDir = dirname($dbFile);
if (!is_dir($dbDir)) mkdir($dbDir, 0755, true);

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

    $db->exec("CREATE TABLE IF NOT EXISTS login_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT,
        login_time DATETIME,
        logout_time DATETIME,
        seq INTEGER DEFAULT 1,
        date_key TEXT,
        created_at DATETIME,
        updated_at DATETIME
      )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_login_events_user_date_seq ON login_events(username, date_key, seq)");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_login_events_unique_login ON login_events(username, date_key, login_time)");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_login_events_unique_logout ON login_events(username, date_key, logout_time)");
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
      'auto_rusak' => 'INTEGER DEFAULT 0',
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

    $db->exec("CREATE TABLE IF NOT EXISTS retur_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        request_date TEXT,
        voucher_code TEXT,
        blok_name TEXT,
        reason TEXT,
        contact_phone TEXT,
        status TEXT DEFAULT 'pending',
        reviewed_by TEXT,
        reviewed_at DATETIME,
        review_note TEXT,
        router_name TEXT,
        source TEXT DEFAULT 'portal'
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_retur_requests_status_date ON retur_requests(status, request_date)");
} catch(Exception $e){
    $db = null;
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
