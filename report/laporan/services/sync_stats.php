<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../include/acl.php';
if (isset($_SESSION['mikhmon']) && isOperator()) {
    requireSuperAdmin('../../../admin.php?id=sessions');
}
// FILE: report/laporan/services/sync_stats.php
// Modified by Pak Dul & Gemini AI (2026)
// UPDATE: Sync statistik ke DB tanpa ubah komentar MikroTik

ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain');

$root_dir = dirname(__DIR__, 3);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
$system_cfg = $env['system'] ?? [];
$db_rel = $system_cfg['db_file'] ?? 'db_data/mikhmon_stats.db';
if (preg_match('/^[A-Za-z]:\\\\|^\//', $db_rel)) {
    $dbFile = $db_rel;
} else {
    $dbFile = $root_dir . '/' . ltrim($db_rel, '/');
}
$log_rel = $system_cfg['log_dir'] ?? 'logs';
$logDir = preg_match('/^[A-Za-z]:\\\\|^\//', $log_rel) ? $log_rel : ($root_dir . '/' . trim($log_rel, '/'));
$expected_hotspot = $system_cfg['hotspot_server'] ?? 'wartel';

// TOKEN PENGAMAN
$cfg_sync_stats = $env['security']['sync_stats'] ?? [];
$secret_token = $cfg_sync_stats['token'] ?? '';
if ($secret_token === '') {
    $secret_token = getenv('WARTELPAS_SYNC_STATS_TOKEN');
    if ($secret_token === false || trim((string)$secret_token) === '') {
        if (defined('WARTELPAS_SYNC_STATS_TOKEN')) {
            $secret_token = WARTELPAS_SYNC_STATS_TOKEN;
        } else {
            $secret_token = $env['backup']['secret'] ?? '';
        }
    }
}
if (!isset($_GET['key']) || $_GET['key'] !== $secret_token) {
    http_response_code(403); die("Error: Akses Ditolak. Token salah.");
}

$session = isset($_GET['session']) ? $_GET['session'] : '';
if ($session === '') {
    http_response_code(403); die("Error: Session tidak valid.");
}

// LIBRARY
$apiFile = $root_dir . '/lib/routeros_api.class.php';
if (file_exists($apiFile)) { require_once($apiFile); } 
else { die("CRITICAL ERROR: File library routeros_api.class.php tidak ditemukan."); }
require_once($root_dir . '/include/config.php');
require_once($root_dir . '/include/readcfg.php');

// Optional IP allowlist
$allowlist_raw = getenv('WARTELPAS_SYNC_STATS_ALLOWLIST');
$cfg_allowlist = $cfg_sync_stats['allowlist'] ?? [];
if (!empty($cfg_allowlist)) {
    $allowed = array_filter(array_map('trim', (array)$cfg_allowlist));
} elseif ($allowlist_raw !== false && trim((string)$allowlist_raw) !== '') {
    $allowed = array_filter(array_map('trim', explode(',', $allowlist_raw)));
} else {
    $allowed = [];
}
if (!empty($allowed)) {
    $remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($remote_ip === '' || !in_array($remote_ip, $allowed, true)) {
        http_response_code(403);
        die("Error: IP tidak diizinkan.");
    }
}

if (!isset($hotspot_server) || $hotspot_server !== $expected_hotspot) {
    http_response_code(403); die("Error: Hanya untuk server wartel.");
}

function extract_blok_name_sync_stats($comment) {
    if (empty($comment)) return '';
    if (preg_match('/\bblok\s*[-_]?\s*([A-Za-z0-9]+)/i', $comment, $m)) {
        return 'BLOK-' . strtoupper($m[1]);
    }
    return '';
}

function log_ready_skip_stats($message) {
    global $logDir;
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/ready_skip.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
}

// SETTING MIKROTIK DARI KONFIG
$use_ip   = $iphost;       
$use_user = $userhost;         
$use_pass = decrypt($passwdhost); 

// DATABASE SETUP
$dbDir = dirname($dbFile);
if (!is_dir($dbDir)) { mkdir($dbDir, 0777, true); }

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");
    $db->exec("PRAGMA busy_timeout=2000;");

    // Tabel login_history sesuai users.php (persisten)
    $db->exec("CREATE TABLE IF NOT EXISTS login_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE,
        customer_name TEXT,
        room_name TEXT,
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
    try { $db->exec("ALTER TABLE login_history ADD COLUMN last_uptime TEXT"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE login_history ADD COLUMN last_bytes INTEGER"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE login_history ADD COLUMN last_ip TEXT"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE login_history ADD COLUMN last_mac TEXT"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE login_history ADD COLUMN customer_name TEXT"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE login_history ADD COLUMN room_name TEXT"); } catch(Exception $e) {}
    
} catch (PDOException $e) { die("Error DB: " . $e->getMessage()); }

// EKSEKUSI UTAMA
$API = new RouterosAPI();
if ($API->connect($use_ip, $use_user, $use_pass)) {

    $active_list = $API->comm('/ip/hotspot/active/print', [
        '?server' => $hotspot_server,
        '.proplist' => 'user,uptime,bytes-in,bytes-out,address,mac-address'
    ]);
    $activeMap = [];
    foreach ($active_list as $a) {
        if (isset($a['user'])) $activeMap[$a['user']] = $a;
    }

    $users = $API->comm('/ip/hotspot/user/print', [
        '?server' => $hotspot_server,
        '.proplist' => '.id,name,uptime,bytes-in,bytes-out,comment,mac-address,disabled'
    ]);

    $stmt = $db->prepare("INSERT INTO login_history (
        username, ip_address, mac_address, last_uptime, last_bytes, last_ip, last_mac, last_status, updated_at
    ) VALUES (
        :u, :ip, :mac, :up, :lb, :lip, :lmac, :st, :upd
    ) ON CONFLICT(username) DO UPDATE SET
        ip_address = CASE WHEN excluded.ip_address != '-' AND excluded.ip_address != '' THEN excluded.ip_address ELSE COALESCE(login_history.ip_address, '-') END,
        mac_address = CASE WHEN excluded.mac_address != '-' AND excluded.mac_address != '' THEN excluded.mac_address ELSE COALESCE(login_history.mac_address, '-') END,
        last_uptime = COALESCE(NULLIF(excluded.last_uptime, ''), login_history.last_uptime),
        last_bytes = CASE WHEN excluded.last_bytes IS NOT NULL AND excluded.last_bytes > 0 THEN excluded.last_bytes ELSE COALESCE(login_history.last_bytes, 0) END,
        last_ip = CASE WHEN excluded.last_ip != '' THEN excluded.last_ip ELSE COALESCE(login_history.last_ip, '') END,
        last_mac = CASE WHEN excluded.last_mac != '' THEN excluded.last_mac ELSE COALESCE(login_history.last_mac, '') END,
        last_status = CASE WHEN excluded.last_status != '' THEN excluded.last_status ELSE login_history.last_status END,
        updated_at = excluded.updated_at");

    $count = 0;
    foreach ($users as $u) {
        $name = $u['name'] ?? '';
        if ($name === '') continue;

        $comment = $u['comment'] ?? '';
        if (extract_blok_name_sync_stats($comment) === '') {
            continue;
        }
        $is_active = isset($activeMap[$name]);

        $bytes_total = (int)($u['bytes-in'] ?? 0) + (int)($u['bytes-out'] ?? 0);
        $bytes_active = 0;
        if ($is_active) {
            $bytes_active = (int)($activeMap[$name]['bytes-in'] ?? 0) + (int)($activeMap[$name]['bytes-out'] ?? 0);
        }
        $bytes = max($bytes_total, $bytes_active);

        $uptime_user = $u['uptime'] ?? '';
        $uptime_active = $is_active ? ($activeMap[$name]['uptime'] ?? '') : '';
        $uptime = $uptime_user !== '' ? $uptime_user : $uptime_active;

        $ip = '-';
        $mac = $u['mac-address'] ?? '-';
        $last_ip = '';
        $last_mac = '';

        if ($is_active) {
            $ip = $activeMap[$name]['address'] ?? '-';
            $mac = $activeMap[$name]['mac-address'] ?? ($mac ?: '-');
            $last_ip = $ip !== '-' ? $ip : '';
            $last_mac = $mac !== '-' ? $mac : '';
        } else {
            if (preg_match('/\bIP\s*:\s*([^|\s]+)/i', $comment, $m)) $ip = trim($m[1]);
            if (preg_match('/\bMAC\s*:\s*([^|\s]+)/i', $comment, $m)) $mac = trim($m[1]);
        }

        $status = 'ready';
        $disabled = $u['disabled'] ?? 'false';

        if ($is_active) {
            $status = 'online';
        } elseif (stripos($comment, 'RETUR') !== false) {
            $status = 'retur';
        } elseif ($disabled === 'true' || stripos($comment, 'RUSAK') !== false) {
            $status = 'rusak';
        } else {
            $is_used = ($bytes > 50 || ($uptime != '' && $uptime != '0s') || ($ip != '-' && $ip != ''));
            if ($is_used) $status = 'terpakai';
        }

        // Skip READY users to avoid storing unused vouchers in DB
        if (!$is_active && ($bytes <= 0) && ($uptime === '' || $uptime === '0s')) {
            log_ready_skip_stats("sync_stats skip READY user={$name}");
            continue;
        }

        $stmt->execute([
            ':u' => $name,
            ':ip' => $ip ?: '-',
            ':mac' => $mac ?: '-',
            ':up' => $uptime ?: '',
            ':lb' => $bytes,
            ':lip' => $last_ip,
            ':lmac' => $last_mac,
            ':st' => $status,
            ':upd' => date('Y-m-d H:i:s')
        ]);
        $count++;
    }

    $API->disconnect();
    echo "Sukses: $count user disinkronkan ke DB.\n";

} else {
    echo "Error: Gagal Login ke MikroTik.";
}
?>
