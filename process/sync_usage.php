<?php
session_start();
error_reporting(0);

if (!isset($_GET['session'])) {
    http_response_code(400);
    echo "Missing session";
    exit;
}

$session = $_GET['session'];

include('../include/config.php');
include('../include/readcfg.php');
include_once('../lib/routeros_api.class.php');

// --- Helpers ---
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

function extract_blok_name_sync($comment) {
    if (empty($comment)) return '';
    if (preg_match('/\bblok\s*[-_]?\s*([A-Za-z0-9]+)/i', $comment, $m)) {
        return 'BLOK-' . strtoupper($m[1]);
    }
    return '';
}

function extract_ip_mac_from_comment_sync($comment) {
    $ip = '';
    $mac = '';
    if (!empty($comment)) {
        if (preg_match('/\bIP\s*:\s*([^|\s]+)/i', $comment, $m)) $ip = trim($m[1]);
        if (preg_match('/\bMAC\s*:\s*([^|\s]+)/i', $comment, $m)) $mac = trim($m[1]);
    }
    return ['ip'=>$ip, 'mac'=>$mac];
}

function uptime_to_seconds_sync($uptime) {
    if (empty($uptime) || $uptime === '0s') return 0;
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

// --- Database ---
$dbDir = dirname(__DIR__) . '/db_data';
if (!is_dir($dbDir)) mkdir($dbDir, 0755, true);
$dbFile = $dbDir . '/mikhmon_stats.db';

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
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
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        login_count INTEGER DEFAULT 0
    )");
    try { $db->exec("ALTER TABLE login_history ADD COLUMN login_count INTEGER DEFAULT 0"); } catch(Exception $e) {}
} catch (Exception $e) {
    http_response_code(500);
    echo "DB error";
    exit;
}

// --- RouterOS ---
$API = new RouterosAPI();
$API->debug = false;
$API->timeout = 5;
$API->attempts = 1;
if (!$API->connect($iphost, $userhost, decrypt($passwdhost))) {
    http_response_code(500);
    echo "Router connect failed";
    exit;
}

$hotspot_server = $hotspot_server ?? 'wartel';
$all_users = $API->comm('/ip/hotspot/user/print', [
    '?server' => $hotspot_server,
    '.proplist' => '.id,name,comment,profile,disabled,bytes-in,bytes-out,uptime'
]);
$active = $API->comm('/ip/hotspot/active/print', [
    '?server' => $hotspot_server,
    '.proplist' => 'user,uptime,address,mac-address,bytes-in,bytes-out'
]);
$API->disconnect();

$activeMap = [];
foreach ($active as $a) {
    if (!empty($a['user'])) $activeMap[$a['user']] = $a;
}

// --- Sync ---
$now = date('Y-m-d H:i:s');
$updated = 0;
foreach ($all_users as $u) {
    $name = $u['name'] ?? '';
    if ($name === '') continue;

    $comment = (string)($u['comment'] ?? '');
    $disabled = $u['disabled'] ?? 'false';
    $is_active = isset($activeMap[$name]);

    $bytes_total = (int)(($u['bytes-in'] ?? 0) + ($u['bytes-out'] ?? 0));
    $bytes_active = $is_active ? (int)(($activeMap[$name]['bytes-in'] ?? 0) + ($activeMap[$name]['bytes-out'] ?? 0)) : 0;
    $bytes = max($bytes_total, $bytes_active);

    $uptime_user = $u['uptime'] ?? '';
    $uptime_active = $is_active ? ($activeMap[$name]['uptime'] ?? '') : '';
    $uptime = $uptime_active != '' ? $uptime_active : $uptime_user;

    $cm = extract_ip_mac_from_comment_sync($comment);
    $ip = $is_active ? ($activeMap[$name]['address'] ?? '-') : ($cm['ip'] ?: '-');
    $mac = $is_active ? ($activeMap[$name]['mac-address'] ?? '-') : ($cm['mac'] ?: '-');
    $blok = extract_blok_name_sync($comment);

    $status = 'ready';
    if ($is_active) $status = 'online';
    elseif ($disabled === 'true' || stripos($comment, 'Audit: RUSAK') === 0) $status = 'rusak';
    else {
        $is_used = ($bytes > 50 || ($uptime != '' && $uptime != '0s') || ($ip != '-' && $ip != ''));
        if ($is_used) $status = 'terpakai';
    }

    // Ambil history untuk locking
    $hist = null;
    try {
        $stmtHist = $db->prepare("SELECT login_time_real, logout_time_real, login_count FROM login_history WHERE username = :u LIMIT 1");
        $stmtHist->execute([':u'=>$name]);
        $hist = $stmtHist->fetch(PDO::FETCH_ASSOC);
    } catch(Exception $e) {}

    $login_time_real = $hist['login_time_real'] ?? null;
    $logout_time_real = $hist['logout_time_real'] ?? null;

    if ($is_active) {
        if (empty($login_time_real)) {
            $u_sec = uptime_to_seconds_sync($uptime);
            $login_time_real = $u_sec > 0 ? date('Y-m-d H:i:s', time() - $u_sec) : $now;
        }
        $logout_time_real = null;
    }

        $stmt = $db->prepare("INSERT INTO login_history (
                username, ip_address, mac_address, last_uptime, last_bytes, blok_name, raw_comment,
                login_time_real, logout_time_real, last_status, updated_at
            ) VALUES (
                :u, :ip, :mac, :up, :lb, :bl, :raw, :ltr, :lor, :st, :upd
            ) ON CONFLICT(username) DO UPDATE SET
                ip_address = CASE WHEN excluded.ip_address != '-' AND excluded.ip_address != '' THEN excluded.ip_address ELSE login_history.ip_address END,
                mac_address = CASE WHEN excluded.mac_address != '-' AND excluded.mac_address != '' THEN excluded.mac_address ELSE login_history.mac_address END,
                last_uptime = COALESCE(NULLIF(excluded.last_uptime,''), login_history.last_uptime),
                last_bytes = CASE WHEN excluded.last_bytes IS NOT NULL AND excluded.last_bytes > 0 THEN excluded.last_bytes ELSE login_history.last_bytes END,
                blok_name = CASE WHEN excluded.blok_name != '' THEN excluded.blok_name ELSE login_history.blok_name END,
                raw_comment = CASE WHEN excluded.raw_comment != '' THEN excluded.raw_comment ELSE login_history.raw_comment END,
                login_time_real = COALESCE(login_history.login_time_real, excluded.login_time_real),
                logout_time_real = COALESCE(login_history.logout_time_real, excluded.logout_time_real),
                last_status = COALESCE(excluded.last_status, login_history.last_status),
                updated_at = CASE WHEN excluded.last_status = 'online' THEN excluded.updated_at ELSE login_history.updated_at END
        ");

    $stmt->execute([
        ':u' => $name,
        ':ip' => $ip,
        ':mac' => $mac,
        ':up' => $uptime,
        ':lb' => $bytes,
        ':bl' => $blok,
        ':raw' => $comment,
        ':ltr' => $login_time_real,
        ':lor' => $logout_time_real,
        ':st' => $status,
        ':upd' => $now
    ]);
    $updated++;
}

echo json_encode([
    'ok' => true,
    'updated' => $updated,
    'time' => $now
]);
