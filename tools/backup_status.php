<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
// Backup status endpoint (protected)
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

// Konfigurasi terpusat
$envFile = dirname(__DIR__) . '/include/env.php';
if (is_file($envFile)) {
    require_once $envFile;
}
$secret = isset($env['backup']['secret']) ? (string)$env['backup']['secret'] : '';
$key = $_GET['key'] ?? '';
if ($key === '' && isset($_POST['key'])) {
    $key = (string)$_POST['key'];
}
if ($key === '' && isset($_SERVER['HTTP_X_BACKUP_KEY'])) {
    $key = (string)$_SERVER['HTTP_X_BACKUP_KEY'];
}
if ($key === '' && isset($_SERVER['HTTP_X_TOOLS_KEY'])) {
    $key = (string)$_SERVER['HTTP_X_TOOLS_KEY'];
}
$is_valid_key = $secret !== '' && hash_equals($secret, (string)$key);

if (!$is_valid_key) {
    requireLogin('../admin.php?id=login');
    requireSuperAdmin('../admin.php?id=sessions');
} else {
    if (!isset($_SESSION['mikhmon'])) {
        $_SESSION['mikhmon'] = 'tools';
        $_SESSION['mikhmon_level'] = 'superadmin';
    }
}
if (!$is_valid_key) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Forbidden']);
    exit;
}

$allowedIpList = isset($env['backup']['allowed_ips']) && is_array($env['backup']['allowed_ips'])
    ? $env['backup']['allowed_ips']
    : ['127.0.0.1', '::1', '10.10.83.1', '172.19.0.1'];
if (!empty($_SERVER['REMOTE_ADDR']) && !empty($allowedIpList)) {
    $clientIp = (string)$_SERVER['REMOTE_ADDR'];
    if (!in_array($clientIp, $allowedIpList, true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'IP not allowed']);
        exit;
    }
}

$root = dirname(__DIR__);
$dbFile = $root . '/db_data/mikhmon_stats.db';
$backupDir = $root . '/db_data/backups';

if (!is_dir($backupDir)) {
    echo json_encode(['ok' => true, 'has_today' => false, 'valid_today' => false, 'latest' => '']);
    exit;
}

$today = date('Ymd');
$files = glob($backupDir . '/mikhmon_stats_*.db') ?: [];
$todayFiles = [];
foreach ($files as $f) {
    if (preg_match('/mikhmon_stats_' . $today . '_\d{6}\.db$/', basename($f))) {
        $todayFiles[] = $f;
    }
}

$latest = '';
if (!empty($files)) {
    usort($files, function($a, $b) { return filemtime($b) <=> filemtime($a); });
    $latest = basename($files[0]);
}

if (empty($todayFiles)) {
    echo json_encode(['ok' => true, 'has_today' => false, 'valid_today' => false, 'latest' => $latest]);
    exit;
}

$srcSize = file_exists($dbFile) ? @filesize($dbFile) : 0;
$validToday = false;
foreach ($todayFiles as $tf) {
    $size = @filesize($tf) ?: 0;
    if ($size <= 0) continue;
    if ($srcSize > 0 && $size < ($srcSize * 0.8)) continue;
    if (class_exists('SQLite3')) {
        try {
            $chk = new SQLite3($tf, SQLITE3_OPEN_READONLY);
            $res = $chk->querySingle('PRAGMA quick_check;');
            $chk->close();
            if (strtolower((string)$res) === 'ok') {
                $validToday = true;
                break;
            }
        } catch (Exception $e) {
            continue;
        }
    } else {
        $validToday = true;
        break;
    }
}

echo json_encode([
    'ok' => true,
    'has_today' => true,
    'valid_today' => $validToday,
    'latest' => $latest
]);
