<?php
// FILE: report/live_ingest.php
// Realtime ingest dari MikroTik (on-login) ke DB lokal

ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: text/plain');

$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

$secret_token = "WartelpasSecureKey";
if (!isset($_GET['key']) || $_GET['key'] !== $secret_token) {
    http_response_code(403);
    die("Error: Token Salah.");
}

$session = isset($_GET['session']) ? $_GET['session'] : '';
if ($session === '') {
    http_response_code(403);
    die("Error: Session tidak valid.");
}

$root_dir = dirname(__DIR__);
require_once($root_dir . '/include/config.php');
if (!isset($data[$session])) {
    http_response_code(403);
    die("Error: Session tidak terdaftar.");
}
require_once($root_dir . '/include/readcfg.php');
if (!isset($hotspot_server) || $hotspot_server !== 'wartel') {
    http_response_code(403);
    die("Error: Hanya untuk server wartel.");
}

$raw = '';
if (isset($_POST['data'])) $raw = trim($_POST['data']);
if ($raw === '' && isset($_GET['data'])) $raw = trim($_GET['data']);

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
    @file_put_contents($logDir . '/live_ingest.log', date('c') . " | empty data | " . ($_SERVER['QUERY_STRING'] ?? '') . "\n", FILE_APPEND);
    echo "OK";
    exit;
}

$dbFile = $root_dir . '/db_data/mikhmon_stats.db';
if (!is_dir($root_dir . '/db_data')) mkdir($root_dir . '/db_data', 0777, true);

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

    $d = explode('-|-', $raw);
    if (count($d) < 4) {
        @file_put_contents($logDir . '/live_ingest.log', date('c') . " | invalid format | " . $raw . "\n", FILE_APPEND);
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
    if ($blok_name === '') {
        echo "OK";
        exit;
    }

    $sale_date = '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw_date)) {
        $sale_date = $raw_date;
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
    if (strpos($cmt_low, 'invalid') !== false) $status = 'invalid';
    elseif (strpos($cmt_low, 'rusak') !== false) $status = 'rusak';
    elseif (strpos($cmt_low, 'retur') !== false) $status = 'retur';

    if ($username !== '' && $sale_date !== '') {
        $dupStmt = $db->prepare("SELECT 1 FROM sales_history WHERE username = :u AND sale_date = :d LIMIT 1");
        $dupStmt->execute([':u' => $username, ':d' => $sale_date]);
        if ($dupStmt->fetchColumn()) {
            echo "OK";
            exit;
        }
        $dupStmt = $db->prepare("SELECT 1 FROM live_sales WHERE username = :u AND sale_date = :d LIMIT 1");
        $dupStmt->execute([':u' => $username, ':d' => $sale_date]);
        if ($dupStmt->fetchColumn()) {
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

    echo "OK";
} catch (Exception $e) {
    @file_put_contents($logDir . '/live_ingest.log', date('c') . " | error | " . $e->getMessage() . " | " . ($raw ?? '') . " | " . ($_SERVER['QUERY_STRING'] ?? '') . "\n", FILE_APPEND);
    echo "OK";
}
?>
