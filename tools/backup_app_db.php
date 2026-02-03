<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
if (isset($_SESSION['mikhmon']) && isOperator()) {
    respond_backup(false, 'Forbidden', [], 403);
}
require_once __DIR__ . '/../include/db.php';
// Backup App Config DB (SQLite)
ini_set('display_errors', 0);
error_reporting(0);

$is_ajax = (isset($_GET['ajax']) && $_GET['ajax'] == '1') ||
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (isset($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if ($is_ajax) {
    header('Content-Type: application/json; charset=utf-8');
} else {
    header('Content-Type: text/plain; charset=utf-8');
}

function respond_app_backup($ok, $message, $data = [], $code = 200) {
    global $is_ajax;
    http_response_code($code);
    if ($is_ajax) {
        echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $data));
    } else {
        echo $message;
    }
    exit;
}

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
    respond_app_backup(false, 'Forbidden', [], 403);
}

$allowedIpList = isset($env['backup']['allowed_ips']) && is_array($env['backup']['allowed_ips'])
    ? $env['backup']['allowed_ips']
    : ['127.0.0.1', '::1', '10.10.83.1', '172.19.0.1'];
if (!empty($_SERVER['REMOTE_ADDR']) && !empty($allowedIpList)) {
    $clientIp = (string)$_SERVER['REMOTE_ADDR'];
    if (!in_array($clientIp, $allowedIpList, true)) {
        respond_app_backup(false, 'IP not allowed', [], 403);
    }
}

$clientIp = !empty($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
$rateKey = $clientIp . '|' . (string)$key . '|app';
$rateFile = sys_get_temp_dir() . '/backup_app_db.rate.' . md5($rateKey);
$rateWindow = isset($env['backup']['rate_window']) ? (int)$env['backup']['rate_window'] : 300;
$rateLimit = isset($env['backup']['rate_limit']) ? (int)$env['backup']['rate_limit'] : 1;
$forceRate = isset($_GET['force']) && $_GET['force'] === '1';
$now = time();
$hits = [];
if (is_file($rateFile)) {
    $raw = @file_get_contents($rateFile);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $hits = $decoded;
    }
}
$hits = array_values(array_filter($hits, function($t) use ($now, $rateWindow) {
    return is_int($t) && ($now - $t) <= $rateWindow;
}));
if (!$forceRate && count($hits) >= $rateLimit) {
    respond_app_backup(false, 'Rate limited', [], 429);
}
$hits[] = $now;
@file_put_contents($rateFile, json_encode($hits));

$root = dirname(__DIR__);
$dbFile = app_db_path();
if (!file_exists($dbFile)) {
    respond_app_backup(false, 'DB not found', [], 404);
}
$dbBase = pathinfo($dbFile, PATHINFO_FILENAME);

$backupDir = $root . '/db_data/backups_app';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0777, true);
}
if (!is_dir($backupDir) || !is_writable($backupDir)) {
    respond_app_backup(false, 'Backup dir not writable', [], 500);
}

$keepDays = isset($_GET['keep_days']) ? (int)$_GET['keep_days'] : (int)($env['backup']['keep_days'] ?? 14);
$keepCount = isset($_GET['keep_count']) ? (int)$_GET['keep_count'] : (int)($env['backup']['keep_count'] ?? 30);
if ($keepDays <= 0) $keepDays = 14;
if ($keepCount <= 0) $keepCount = 30;

$stamp = date('Ymd_His');
$backupFile = $backupDir . '/' . $dbBase . '_' . $stamp . '.db';
$tempFile = $backupFile . '.tmp';

$minDbSize = isset($env['backup']['min_db_size']) ? (int)$env['backup']['min_db_size'] : (1024 * 8);
$srcSize = @filesize($dbFile);
if (!$srcSize || $srcSize < $minDbSize) {
    respond_app_backup(false, 'Source DB too small or unreadable', [], 500);
}

$ok = false;
$message = '';
try {
    if (class_exists('SQLite3')) {
        $src = new SQLite3($dbFile, SQLITE3_OPEN_READONLY);
        $dest = new SQLite3($tempFile, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $src->backup($dest);
        $dest->close();
        $src->close();
        $ok = true;
    } else {
        $ok = @copy($dbFile, $tempFile);
    }
} catch (Exception $e) {
    $message = 'Backup failed.';
}

if (!$ok || !file_exists($tempFile)) {
    respond_app_backup(false, $message ?: 'Backup failed', [], 500);
}

$tmpSize = @filesize($tempFile);
if (!$tmpSize || $tmpSize < ($srcSize * 0.5)) {
    @unlink($tempFile);
    respond_app_backup(false, 'Backup size invalid', [], 500);
}

try {
    if (class_exists('SQLite3')) {
        $chk = new SQLite3($tempFile, SQLITE3_OPEN_READONLY);
        $res = $chk->querySingle('PRAGMA quick_check;');
        $chk->close();
        if (strtolower((string)$res) !== 'ok') {
            throw new Exception('quick_check failed');
        }
    }
} catch (Exception $e) {
    @unlink($tempFile);
    respond_app_backup(false, 'Backup integrity failed', [], 500);
}

if (!@rename($tempFile, $backupFile)) {
    @unlink($tempFile);
    respond_app_backup(false, 'Failed to finalize backup', [], 500);
}

$logFile = $root . '/logs/backup_app_db.log';
$logLine = date('Y-m-d H:i:s') . "\t" . basename($backupFile) . "\t" . ($tmpSize ?? 0) . "\n";
@file_put_contents($logFile, $logLine, FILE_APPEND);

$files = glob($backupDir . '/' . $dbBase . '_*.db') ?: [];
$deleteCount = 0;
$now = time();
if (!empty($files)) {
    usort($files, function($a, $b) { return filemtime($b) <=> filemtime($a); });
    foreach ($files as $idx => $f) {
        if ($idx >= $keepCount || ($now - filemtime($f)) > ($keepDays * 86400)) {
            if (@unlink($f)) $deleteCount++;
        }
    }
}

respond_app_backup(true, 'Backup OK', [
    'backup' => basename($backupFile),
    'deleted' => $deleteCount
]);
