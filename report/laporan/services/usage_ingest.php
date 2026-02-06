<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../include/acl.php';
if (isset($_SESSION['mikhmon']) && isOperator()) {
    requireSuperAdmin('../../../admin.php?id=sessions');
}
// FILE: report/laporan/services/usage_ingest.php
// Realtime ingest login/logout dari MikroTik ke DB login_history

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
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

@file_put_contents($logDir . '/usage_ingest.log', date('c') . " | hit | ip=" . ($_SERVER['REMOTE_ADDR'] ?? '-') . " | qs=" . ($_SERVER['QUERY_STRING'] ?? '') . "\n", FILE_APPEND);

require_once($root_dir . '/include/config.php');

// Optional IP allowlist (comma-separated)
$allowlist_raw = getenv('WARTELPAS_INGEST_ALLOWLIST');
$cfg_ingest = $env['security']['usage_ingest'] ?? [];
$cfg_allowlist = $cfg_ingest['allowlist'] ?? [];
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
        @file_put_contents($logDir . '/usage_ingest.log', date('c') . " | reject | reason=ip_deny | ip=" . $remote_ip . " | qs=" . ($_SERVER['QUERY_STRING'] ?? '') . "\n", FILE_APPEND);
        http_response_code(403);
        die("Error: IP tidak diizinkan.");
    }
}

$secret_token = $cfg_ingest['token'] ?? '';
if ($secret_token === '') {
    $secret_token = getenv('WARTELPAS_INGEST_TOKEN');
    if ($secret_token === false || trim((string)$secret_token) === '') {
        if (defined('WARTELPAS_INGEST_TOKEN')) {
            $secret_token = WARTELPAS_INGEST_TOKEN;
        } else {
            $secret_token = $env['backup']['secret'] ?? '';
        }
    }
}
if (!isset($_GET['key']) || $_GET['key'] !== $secret_token) {
    @file_put_contents($logDir . '/usage_ingest.log', date('c') . " | reject | reason=bad_key | qs=" . ($_SERVER['QUERY_STRING'] ?? '') . "\n", FILE_APPEND);
    http_response_code(403);
    die("Error: Token Salah.");
}

$session = $_GET['session'] ?? '';
if ($session === '' || !isset($data[$session])) {
    @file_put_contents($logDir . '/usage_ingest.log', date('c') . " | reject | reason=missing_session | qs=" . ($_SERVER['QUERY_STRING'] ?? '') . "\n", FILE_APPEND);
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
$bytes_in_raw = $_GET['bytes_in'] ?? ($_GET['bytes-in'] ?? ($_GET['bi'] ?? ''));
$bytes_out_raw = $_GET['bytes_out'] ?? ($_GET['bytes-out'] ?? ($_GET['bo'] ?? ''));
$bytes_total_raw = $_GET['bytes'] ?? ($_GET['bytes_total'] ?? ($_GET['total_bytes'] ?? ''));
$bytes_in = is_numeric($bytes_in_raw) ? (int)$bytes_in_raw : 0;
$bytes_out = is_numeric($bytes_out_raw) ? (int)$bytes_out_raw : 0;
$bytes_total = is_numeric($bytes_total_raw) ? (int)$bytes_total_raw : 0;
$last_bytes = $bytes_total > 0 ? $bytes_total : ($bytes_in + $bytes_out);
$comment = trim($_GET['comment'] ?? '');
$customer_name = trim($_GET['customer_name'] ?? ($_GET['nama'] ?? ''));
$room_name = trim($_GET['room'] ?? ($_GET['kamar'] ?? ''));
$meta_blok_name = trim($_GET['blok_name'] ?? ($_GET['blok'] ?? ''));
$meta_profile_name = trim($_GET['profile_name'] ?? ($_GET['profile'] ?? ''));
$meta_price_raw = trim($_GET['price'] ?? ($_GET['harga'] ?? ''));
$meta_price = is_numeric($meta_price_raw) ? (int)$meta_price_raw : 0;
if ($customer_name !== '') $customer_name = mb_substr($customer_name, 0, 80);
if ($room_name !== '') $room_name = mb_substr($room_name, 0, 40);
if ($meta_blok_name !== '') $meta_blok_name = mb_substr($meta_blok_name, 0, 40);
if ($meta_profile_name !== '') $meta_profile_name = mb_substr($meta_profile_name, 0, 40);
if ($meta_price < 0) $meta_price = 0;

if ($event !== 'login' && $event !== 'logout') {
    $event = 'login';
}
if ($user === '') {
    // jangan hard-fail agar MikroTik tidak error, tetapi catat log
    @file_put_contents($logDir . '/usage_ingest.log', date('c') . " | missing user | " . $_SERVER['QUERY_STRING'] . "\n", FILE_APPEND);
    echo "OK";
    exit;
}

$vip_flag = trim((string)($_GET['vip'] ?? ''));
if ($vip_flag === '1' || (function_exists('is_vip_comment') && is_vip_comment($comment))) {
    @file_put_contents($logDir . '/usage_ingest.log', date('c') . " | vip skip | user=" . $user . " | qs=" . ($_SERVER['QUERY_STRING'] ?? '') . "\n", FILE_APPEND);
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

$dbDir = dirname($dbFile);
if (!is_dir($dbDir)) mkdir($dbDir, 0755, true);

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");
    $db->exec("PRAGMA busy_timeout=5000;");

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
        updated_at DATETIME,
        login_count INTEGER DEFAULT 0
    )");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_login_history_username ON login_history(username)");

    $db->exec("CREATE TABLE IF NOT EXISTS login_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT,
        customer_name TEXT,
        room_name TEXT,
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
        'updated_at' => 'DATETIME',
        'login_count' => 'INTEGER DEFAULT 0',
        'customer_name' => 'TEXT',
        'room_name' => 'TEXT'
    ];
    $existingCols = [];
    foreach ($db->query("PRAGMA table_info(login_history)") as $row) {
        $existingCols[$row['name']] = true;
    }
    foreach ($requiredCols as $col => $type) {
        if (!isset($existingCols[$col])) {
            try { $db->exec("ALTER TABLE login_history ADD COLUMN $col $type"); } catch (Exception $e) {}
        }
    }

    try { $db->exec("ALTER TABLE login_events ADD COLUMN customer_name TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE login_events ADD COLUMN room_name TEXT"); } catch (Exception $e) {}

    $db->exec("CREATE TABLE IF NOT EXISTS login_meta_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        voucher_code TEXT,
        customer_name TEXT,
        room_name TEXT,
        blok_name TEXT,
        profile_name TEXT,
        price INTEGER,
        session_id TEXT,
        client_ip TEXT,
        user_agent TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        consumed_at DATETIME,
        consumed_by TEXT
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_login_meta_queue_voucher ON login_meta_queue(voucher_code)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_login_meta_queue_created ON login_meta_queue(created_at)");

    try { $db->exec("ALTER TABLE login_meta_queue ADD COLUMN blok_name TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE login_meta_queue ADD COLUMN profile_name TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE login_meta_queue ADD COLUMN price INTEGER"); } catch (Exception $e) {}

    if ($event === 'login' && ($customer_name === '' || $room_name === '' || $meta_blok_name === '' || $meta_profile_name === '' || $meta_price <= 0)) {
        try {
            $stmtMeta = $db->prepare("SELECT id, customer_name, room_name, blok_name, profile_name, price FROM login_meta_queue
                WHERE voucher_code = :u AND consumed_at IS NULL
                AND (client_ip = :ip OR client_ip = '' OR :ip = '')
                AND created_at >= datetime('now','-30 minutes')
                ORDER BY created_at DESC LIMIT 1");
            $stmtMeta->execute([':u' => $user, ':ip' => $ip]);
            $meta = $stmtMeta->fetch(PDO::FETCH_ASSOC);
            if ($meta) {
                if ($customer_name === '') {
                    $customer_name = trim((string)($meta['customer_name'] ?? ''));
                }
                if ($room_name === '') {
                    $room_name = trim((string)($meta['room_name'] ?? ''));
                }
                if ($meta_blok_name === '') {
                    $meta_blok_name = trim((string)($meta['blok_name'] ?? ''));
                }
                if ($meta_profile_name === '') {
                    $meta_profile_name = trim((string)($meta['profile_name'] ?? ''));
                }
                if ($meta_price <= 0) {
                    $meta_price = (int)($meta['price'] ?? 0);
                }
                $stmtMetaUpd = $db->prepare("UPDATE login_meta_queue SET consumed_at = CURRENT_TIMESTAMP, consumed_by = :u WHERE id = :id");
                $stmtMetaUpd->execute([':u' => $user, ':id' => (int)$meta['id']]);
            }
        } catch (Exception $e) {}
    }

    $status = $event === 'login' ? 'online' : 'terpakai';

    $stmt = $db->prepare("INSERT INTO login_history (
        username, customer_name, room_name, blok_name, validity, price, ip_address, mac_address, last_uptime, last_bytes, raw_comment,
        login_time_real, logout_time_real, first_login_real, last_login_real, last_status, updated_at, login_count
    ) VALUES (
        :u, :cn, :rn, :bn, :vf, :pr, :ip, :mac, :up, :lb, :raw, :ltr, :lor, :flr, :llr, :st, :upd, :cnt
    ) ON CONFLICT(username) DO UPDATE SET
        ip_address = CASE WHEN excluded.ip_address != '' AND excluded.ip_address != '-' THEN excluded.ip_address ELSE login_history.ip_address END,
        mac_address = CASE WHEN excluded.mac_address != '' AND excluded.mac_address != '-' THEN excluded.mac_address ELSE login_history.mac_address END,
        last_uptime = COALESCE(NULLIF(excluded.last_uptime, ''), login_history.last_uptime),
        last_bytes = CASE WHEN excluded.last_bytes IS NOT NULL AND excluded.last_bytes > 0 THEN excluded.last_bytes ELSE COALESCE(login_history.last_bytes, 0) END,
        raw_comment = CASE WHEN excluded.raw_comment != '' THEN excluded.raw_comment ELSE login_history.raw_comment END,
        customer_name = CASE WHEN excluded.customer_name != '' THEN excluded.customer_name ELSE login_history.customer_name END,
        room_name = CASE WHEN excluded.room_name != '' THEN excluded.room_name ELSE login_history.room_name END,
        blok_name = CASE WHEN excluded.blok_name != '' THEN excluded.blok_name ELSE login_history.blok_name END,
        validity = CASE WHEN excluded.validity != '' THEN excluded.validity ELSE login_history.validity END,
        price = CASE WHEN excluded.price != '' AND excluded.price != '0' THEN excluded.price ELSE login_history.price END,
        first_login_real = COALESCE(login_history.first_login_real, excluded.first_login_real),
        last_login_real = CASE WHEN excluded.last_status = 'online' THEN excluded.last_login_real ELSE COALESCE(login_history.last_login_real, excluded.last_login_real) END,
        login_time_real = CASE WHEN excluded.last_status = 'online' THEN excluded.login_time_real ELSE COALESCE(login_history.login_time_real, excluded.login_time_real) END,
        logout_time_real = CASE WHEN excluded.last_status = 'terpakai' THEN excluded.logout_time_real ELSE login_history.logout_time_real END,
        login_count = CASE WHEN excluded.last_status = 'online' THEN COALESCE(login_history.login_count,0) + 1 ELSE COALESCE(login_history.login_count,0) END,
        last_status = COALESCE(excluded.last_status, login_history.last_status),
        updated_at = excluded.updated_at
    ");

    $stmt->execute([
        ':u' => $user,
        ':cn' => $customer_name,
        ':rn' => $room_name,
        ':bn' => $meta_blok_name,
        ':vf' => $meta_profile_name,
        ':pr' => $meta_price > 0 ? (string)$meta_price : '',
        ':ip' => $ip,
        ':mac' => $mac,
        ':up' => $uptime,
        ':lb' => $last_bytes > 0 ? $last_bytes : null,
        ':raw' => $comment,
        ':ltr' => $event === 'login' ? $dt : null,
        ':lor' => $event === 'logout' ? $dt : null,
        ':flr' => $event === 'login' ? $dt : null,
        ':llr' => $event === 'login' ? $dt : null,
        ':st' => $status,
        ':upd' => $now,
        ':cnt' => $event === 'login' ? 1 : null
    ]);

    $date_key = substr($dt, 0, 10);
    if ($event === 'login') {
        $dupStmt = $db->prepare("SELECT 1 FROM login_events WHERE username = :u AND date_key = :dk AND login_time = :lt LIMIT 1");
        $dupStmt->execute([':u' => $user, ':dk' => $date_key, ':lt' => $dt]);
        if ($dupStmt->fetchColumn()) {
            @file_put_contents($logDir . '/usage_ingest.log', date('c') . " | skip_dup_login | user=" . $user . " | dt=" . $dt . "\n", FILE_APPEND);
            echo "OK";
            exit;
        }
        $seq = 1;
        $stmtSeq = $db->prepare("SELECT COALESCE(MAX(seq),0) FROM login_events WHERE username = :u AND date_key = :dk");
        $stmtSeq->execute([':u' => $user, ':dk' => $date_key]);
        $seq = (int)$stmtSeq->fetchColumn() + 1;
        $stmtIns = $db->prepare("INSERT INTO login_events (username, customer_name, room_name, login_time, logout_time, seq, date_key, created_at, updated_at)
            VALUES (:u, :cn, :rn, :lt, NULL, :seq, :dk, :now, :now)");
        $stmtIns->execute([
            ':u' => $user,
            ':cn' => $customer_name,
            ':rn' => $room_name,
            ':lt' => $dt,
            ':seq' => $seq,
            ':dk' => $date_key,
            ':now' => $now
        ]);
    } else {
        $stmtUpd = $db->prepare("UPDATE login_events SET logout_time = :lt, updated_at = :now
            WHERE id = (SELECT id FROM login_events WHERE username = :u AND logout_time IS NULL ORDER BY id DESC LIMIT 1)");
        $stmtUpd->execute([':lt' => $dt, ':now' => $now, ':u' => $user]);
        if ($stmtUpd->rowCount() === 0) {
            $dupStmt = $db->prepare("SELECT 1 FROM login_events WHERE username = :u AND date_key = :dk AND logout_time = :lt LIMIT 1");
            $dupStmt->execute([':u' => $user, ':dk' => $date_key, ':lt' => $dt]);
            if ($dupStmt->fetchColumn()) {
                @file_put_contents($logDir . '/usage_ingest.log', date('c') . " | skip_dup_logout | user=" . $user . " | dt=" . $dt . "\n", FILE_APPEND);
                echo "OK";
                exit;
            }
            @file_put_contents($logDir . '/usage_ingest.log', date('c') . " | orphan_logout_skip | user=" . $user . " | dt=" . $dt . "\n", FILE_APPEND);
            echo "OK";
            exit;
        }
    }

    @file_put_contents($logDir . '/usage_ingest.log', date('c') . " | ok | user=" . $user . " | event=" . $status . " | dt=" . $dt . " | ip=" . $ip . " | mac=" . $mac . "\n", FILE_APPEND);

    echo "OK";
} catch (Exception $e) {
    @file_put_contents($logDir . '/usage_ingest.log', date('c') . " | error | " . $e->getMessage() . " | " . $_SERVER['QUERY_STRING'] . "\n", FILE_APPEND);
    echo "OK";
}
?>
