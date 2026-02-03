<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../include/acl.php';
if (isset($_SESSION['mikhmon']) && isOperator()) {
    requireSuperAdmin('../../../admin.php?id=sessions');
}
// FILE: report/laporan/services/live_ingest.php
// Realtime ingest dari MikroTik (on-login) ke DB lokal

ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: text/plain');

$root_dir = dirname(__DIR__, 3);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
$helpersFile = $root_dir . '/report/laporan/helpers.php';
if (file_exists($helpersFile)) {
    require_once $helpersFile;
}
$system_cfg = $env['system'] ?? [];
$db_rel = $system_cfg['db_file'] ?? 'db_data/babahdigital_main.db';
if (preg_match('/^[A-Za-z]:\\\\|^\//', $db_rel)) {
    $dbFile = $db_rel;
} else {
    $dbFile = $root_dir . '/' . ltrim($db_rel, '/');
}
$log_rel = $system_cfg['log_dir'] ?? 'logs';
$logDir = preg_match('/^[A-Za-z]:\\\\|^\//', $log_rel) ? $log_rel : ($root_dir . '/' . trim($log_rel, '/'));
$expected_hotspot = $system_cfg['hotspot_server'] ?? 'wartel';

if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

// [SECURITY ADDITION] - allowlist IP untuk live_ingest
$allowlist_raw = getenv('WARTELPAS_SYNC_ALLOWLIST');
$cfg_live = $env['security']['live_ingest'] ?? [];
$cfg_allowlist = $cfg_live['allowlist'] ?? [];
if (!empty($cfg_allowlist)) {
    $allowed = array_filter(array_map('trim', (array)$cfg_allowlist));
} elseif ($allowlist_raw !== false && trim((string)$allowlist_raw) !== '') {
    $allowed = array_filter(array_map('trim', explode(',', $allowlist_raw)));
} else {
    $allowed = [];
}
if (!empty($allowed)) {
    $remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($remote_ip !== '' && !in_array($remote_ip, $allowed, true) && $remote_ip !== '127.0.0.1' && $remote_ip !== '::1') {
        http_response_code(403);
        die("Error: IP tidak diizinkan.");
    }
}

$logFile = $logDir . '/live_ingest.log';
$logWrite = function (string $line) use ($logFile) {
    $ok = @file_put_contents($logFile, $line, FILE_APPEND);
    if ($ok === false) {
        error_log('[live_ingest] ' . trim($line));
    }
};

$secret_token = $cfg_live['token'] ?? '';
if ($secret_token === '') {
    $secret_token = getenv('WARTELPAS_SYNC_TOKEN');
    if ($secret_token === false || trim((string)$secret_token) === '') {
        if (defined('WARTELPAS_SYNC_TOKEN')) {
            $secret_token = WARTELPAS_SYNC_TOKEN;
        } else {
            $secret_token = $env['backup']['secret'] ?? '';
        }
    }
}
$req_key = $_GET['key'] ?? ($_POST['key'] ?? '');
if ($req_key === '' || $req_key !== $secret_token) {
    http_response_code(403);
    die("Error: Token Salah.");
}

$session = $_GET['session'] ?? ($_POST['session'] ?? '');
if ($session === '') {
    http_response_code(403);
    die("Error: Session tidak valid.");
}

require_once($root_dir . '/include/config.php');
if (!isset($data[$session])) {
    http_response_code(403);
    die("Error: Session tidak terdaftar.");
}
require_once($root_dir . '/include/readcfg.php');
if (!isset($hotspot_server) || $hotspot_server !== $expected_hotspot) {
    http_response_code(403);
    die("Error: Hanya untuk server wartel.");
}

$raw = '';
$logWrite(date('c') . " | hit | ip=" . ($_SERVER['REMOTE_ADDR'] ?? '-') . " | qs=" . ($_SERVER['QUERY_STRING'] ?? '') . "\n");
if (isset($_POST['data'])) $raw = trim((string)$_POST['data']);
if ($raw === '' && isset($_GET['data'])) $raw = trim((string)$_GET['data']);

if ($raw === '') {
    // fallback dari parameter terpisah
    $date = $_GET['date'] ?? '';
    $time = $_GET['time'] ?? '';
    $user = $_GET['user'] ?? '';
    $price = $_GET['price'] ?? '';
    $ip = $_GET['ip'] ?? '';
    $mac = $_GET['mac'] ?? '';
    $valid = $_GET['valid'] ?? '';
    $profile = $_GET['profile'] ?? '';
    $comment = $_GET['comment'] ?? '';
    if ($date !== '' && $time !== '' && $user !== '') {
        $raw = $date . "-|-" . $time . "-|-" . $user . "-|-" . $price . "-|-" . $ip . "-|-" . $mac . "-|-" . $valid . "-|-" . $profile . "-|-" . $comment;
    }
}

if ($raw === '') {
    $logWrite(date('c') . " | empty data | " . ($_SERVER['QUERY_STRING'] ?? '') . "\n");
    echo "OK";
    exit;
}

$dbDir = dirname($dbFile);
if (!is_dir($dbDir)) mkdir($dbDir, 0777, true);

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");
    $db->exec("PRAGMA busy_timeout=5000;");

    $db->exec("CREATE TABLE IF NOT EXISTS live_sales (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        raw_date TEXT,
        raw_time TEXT,
        sale_date TEXT,
        sale_time TEXT,
        sale_datetime TEXT,
        username TEXT,
        profile TEXT,
        profile_snapshot TEXT,
        price INTEGER,
        price_snapshot INTEGER,
        sprice_snapshot INTEGER,
        validity TEXT,
        comment TEXT,
        blok_name TEXT,
        status TEXT,
        is_rusak INTEGER,
        is_retur INTEGER,
        is_invalid INTEGER,
        qty INTEGER,
        full_raw_data TEXT UNIQUE,
        sync_status TEXT DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        synced_at DATETIME
    )");
    try { $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_live_user_date ON live_sales(username, sale_date)"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE live_sales ADD COLUMN sprice_snapshot INTEGER"); } catch (Exception $e) {}

    $d = function_exists('split_sales_raw') ? split_sales_raw($raw) : explode('-|-', $raw);
    if (count($d) < 4) {
        if (function_exists('log_audit_warning')) {
            log_audit_warning($db, date('Y-m-d'), 'live_ingest', 'Format raw tidak terbaca (live): 1 item.');
        }
        $logWrite(date('c') . " | invalid format | " . $raw . "\n");
        echo "OK";
        exit;
    }

    $raw_date = $d[0] ?? '';
    $raw_time = $d[1] ?? '';
    $username = $d[2] ?? '';
    $price = (int)($d[3] ?? 0);
    $validity = $d[6] ?? '';
    $profile = $d[7] ?? '';
    $comment = $d[8] ?? '';

    $blok_name = '';
    if ($comment && preg_match('/\bblok\s*[-_]?\s*([A-Za-z0-9]+)/i', $comment, $m)) {
        $blok_name = 'BLOK-' . strtoupper($m[1]);
    }
    if ($blok_name === '' && $username !== '' && preg_match('/\bblok\s*[-_]?\s*([A-Za-z0-9]+)/i', $username, $m)) {
        $blok_name = 'BLOK-' . strtoupper($m[1]);
    }
    if ($blok_name === '') {
        $blok_name = 'BLOK-UNKNOWN';
        if (function_exists('log_audit_warning')) {
            log_audit_warning($db, date('Y-m-d'), 'live_ingest', 'Transaksi tanpa BLOK (live) di-set UNKNOWN: 1 item.');
        }
        $logWrite(date('c') . " | blok empty | set UNKNOWN | " . $raw . "\n");
    }

    $sale_date = '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw_date)) {
        $sale_date = substr($raw_date, 0, 10);
    } elseif (preg_match('/^[a-zA-Z]{3}\/\d{2}\/\d{4}$/', $raw_date)) {
        $mon = strtolower(substr($raw_date, 0, 3));
        $map = [
            'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04', 'may' => '05', 'jun' => '06',
            'jul' => '07', 'aug' => '08', 'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12'
        ];
        $mm = $map[$mon] ?? '';
        if ($mm !== '') {
            $parts = explode('/', $raw_date);
            $sale_date = $parts[2] . '-' . $mm . '-' . $parts[1];
        }
    } elseif (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $raw_date)) {
        $parts = explode('/', $raw_date);
        $sale_date = $parts[2] . '-' . $parts[0] . '-' . $parts[1];
    }

    $sale_time = $raw_time ?: '';
    $sale_datetime = ($sale_date && $sale_time) ? ($sale_date . ' ' . $sale_time) : '';

    $cmt_low = strtolower($comment);
    $status = 'normal';
    if (strpos($cmt_low, 'retur') !== false) $status = 'retur';
    elseif (strpos($cmt_low, 'rusak') !== false) $status = 'rusak';
    elseif (strpos($cmt_low, 'invalid') !== false) $status = 'invalid';

    if ($username !== '' && $sale_date !== '') {
        $dupStmt = $db->prepare("SELECT 1 FROM sales_history WHERE username = :u AND sale_date = :d LIMIT 1");
        $dupStmt->execute([':u' => $username, ':d' => $sale_date]);
        if ($dupStmt->fetchColumn()) {
            $logWrite(date('c') . " | duplicate sales_history | " . $raw . "\n");
            echo "OK";
            exit;
        }
        $dupStmt = $db->prepare("SELECT 1 FROM live_sales WHERE username = :u AND sale_date = :d LIMIT 1");
        $dupStmt->execute([':u' => $username, ':d' => $sale_date]);
        if ($dupStmt->fetchColumn()) {
            $logWrite(date('c') . " | duplicate live_sales | " . $raw . "\n");
            echo "OK";
            exit;
        }
    }

    $stmt = $db->prepare("INSERT OR IGNORE INTO live_sales (
        raw_date, raw_time, sale_date, sale_time, sale_datetime,
        username, profile, profile_snapshot, price, price_snapshot, sprice_snapshot, validity,
        comment, blok_name, status, is_rusak, is_retur, is_invalid, qty, full_raw_data
    ) VALUES (
        :rd, :rt, :sd, :st, :sdt,
        :usr, :prof, :prof_snap, :prc, :prc_snap, :sprc_snap, :valid,
        :cmt, :blok, :status, :is_rusak, :is_retur, :is_invalid, :qty, :raw
    )");

    $stmt->execute([
        ':rd' => $raw_date,
        ':rt' => $raw_time,
        ':sd' => $sale_date,
        ':st' => $sale_time,
        ':sdt' => $sale_datetime,
        ':usr' => $username,
        ':prof' => $profile,
        ':prof_snap' => $profile,
        ':prc' => $price,
        ':prc_snap' => $price,
        ':sprc_snap' => 0,
        ':valid' => $validity,
        ':cmt' => $comment,
        ':blok' => $blok_name,
        ':status' => $status,
        ':is_rusak' => ($status === 'rusak') ? 1 : 0,
        ':is_retur' => ($status === 'retur') ? 1 : 0,
        ':is_invalid' => ($status === 'invalid') ? 1 : 0,
        ':qty' => 1,
        ':raw' => $raw
    ]);
    $logWrite(date('c') . " | inserted | user=" . $username . " | date=" . $sale_date . " | blok=" . $blok_name . "\n");

    echo "OK";
} catch (Exception $e) {
    $logWrite(date('c') . " | error | " . $e->getMessage() . " | " . ($raw ?? '') . " | " . ($_SERVER['QUERY_STRING'] ?? '') . "\n");
    echo "OK";
}
?>
