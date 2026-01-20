<?php
// FILE: process/usage_ingest.php
// Realtime ingest login/logout dari MikroTik ke DB login_history

ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: text/plain');

$secret_token = "WartelpasSecureKey";
if (!isset($_GET['key']) || $_GET['key'] !== $secret_token) {
    http_response_code(403);
    die("Error: Token Salah.");
}

$session = $_GET['session'] ?? '';
if ($session === '') {
    http_response_code(403);
    die("Error: Session tidak valid.");
}

$event = strtolower(trim($_GET['event'] ?? 'login'));
$user = trim($_GET['user'] ?? ($_GET['username'] ?? ($_GET['u'] ?? '')));
$date = trim($_GET['date'] ?? '');
$time = trim($_GET['time'] ?? '');
$ip = trim($_GET['ip'] ?? '');
$mac = trim($_GET['mac'] ?? '');
$uptime = trim($_GET['uptime'] ?? '');
$comment = trim($_GET['comment'] ?? '');

if ($event !== 'login' && $event !== 'logout') {
    $event = 'login';
}
if ($user === '') {
    // jangan hard-fail agar MikroTik tidak error, tetapi catat log
    @file_put_contents(dirname(__DIR__) . '/logs/usage_ingest.log', date('c') . " | missing user | " . $_SERVER['QUERY_STRING'] . "\n", FILE_APPEND);
    echo "OK";
    exit;
}

function normalize_date($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    if (preg_match('/^[a-zA-Z]{3}\/\d{2}\/\d{4}$/', $raw)) {
        $mon = strtolower(substr($raw, 0, 3));
        $map = [
            'jan'=>'01','feb'=>'02','mar'=>'03','apr'=>'04','may'=>'05','jun'=>'06',
            'jul'=>'07','aug'=>'08','sep'=>'09','oct'=>'10','nov'=>'11','dec'=>'12'
        ];
        $mm = $map[$mon] ?? '';
        if ($mm !== '') {
            $parts = explode('/', $raw);
            return $parts[2] . '-' . $mm . '-' . $parts[1];
        }
    }
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $raw)) {
        $parts = explode('/', $raw);
        return $parts[2] . '-' . $parts[0] . '-' . $parts[1];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;
    return '';
}

$norm_date = normalize_date($date);
$dt = ($norm_date !== '' && $time !== '') ? ($norm_date . ' ' . $time) : '';
$now = date('Y-m-d H:i:s');
if ($dt === '') $dt = $now;

$dbDir = dirname(__DIR__) . '/db_data';
if (!is_dir($dbDir)) mkdir($dbDir, 0755, true);
$dbFile = $dbDir . '/mikhmon_stats.db';

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");
    $db->exec("PRAGMA busy_timeout=5000;");

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

    $status = $event === 'login' ? 'online' : 'terpakai';

    $stmt = $db->prepare("INSERT INTO login_history (
        username, ip_address, mac_address, last_uptime, raw_comment,
        login_time_real, logout_time_real, last_status, updated_at
    ) VALUES (
        :u, :ip, :mac, :up, :raw, :ltr, :lor, :st, :upd
    ) ON CONFLICT(username) DO UPDATE SET
        ip_address = CASE WHEN excluded.ip_address != '' AND excluded.ip_address != '-' THEN excluded.ip_address ELSE login_history.ip_address END,
        mac_address = CASE WHEN excluded.mac_address != '' AND excluded.mac_address != '-' THEN excluded.mac_address ELSE login_history.mac_address END,
        last_uptime = COALESCE(NULLIF(excluded.last_uptime, ''), login_history.last_uptime),
        raw_comment = CASE WHEN excluded.raw_comment != '' THEN excluded.raw_comment ELSE login_history.raw_comment END,
        login_time_real = COALESCE(excluded.login_time_real, login_history.login_time_real),
        logout_time_real = COALESCE(excluded.logout_time_real, login_history.logout_time_real),
        last_status = COALESCE(excluded.last_status, login_history.last_status),
        updated_at = excluded.updated_at
    ");

    $stmt->execute([
        ':u' => $user,
        ':ip' => $ip,
        ':mac' => $mac,
        ':up' => $uptime,
        ':raw' => $comment,
        ':ltr' => $event === 'login' ? $dt : null,
        ':lor' => $event === 'logout' ? $dt : null,
        ':st' => $status,
        ':upd' => $now
    ]);

    echo "OK";
} catch (Exception $e) {
    @file_put_contents(dirname(__DIR__) . '/logs/usage_ingest.log', date('c') . " | error | " . $e->getMessage() . " | " . $_SERVER['QUERY_STRING'] . "\n", FILE_APPEND);
    http_response_code(500);
    echo "Error";
}
?>
