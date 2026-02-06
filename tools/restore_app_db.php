<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
if (isset($_SESSION['mikhmon']) && isOperator()) {
    respond_app_restore(false, 'Forbidden', [], 403);
}
require_once __DIR__ . '/../include/db.php';
// Restore App Config DB (SQLite)
ini_set('display_errors', 0);
error_reporting(0);

$is_ajax = (isset($_GET['ajax']) && $_GET['ajax'] == '1') ||
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (isset($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if ($is_ajax) {
    header('Content-Type: application/json; charset=utf-8');
} else {
    header('Content-Type: text/html; charset=utf-8');
}

function respond_app_restore($ok, $message, $data = [], $code = 200) {
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

$session_allowed = isset($_SESSION['mikhmon']) && isSuperAdmin();

if (!$is_valid_key && !$session_allowed) {
    requireLogin('../admin.php?id=login');
}
if ($is_valid_key && !isset($_SESSION['mikhmon'])) {
    $_SESSION['mikhmon'] = 'tools';
    $_SESSION['mikhmon_level'] = 'superadmin';
}

if (!$is_valid_key && !$session_allowed) {
    respond_app_restore(false, 'Forbidden', [], 403);
}

$allowedIpList = isset($env['backup']['allowed_ips']) && is_array($env['backup']['allowed_ips'])
    ? $env['backup']['allowed_ips']
    : ['127.0.0.1', '::1', '10.10.83.1', '172.19.0.1'];
if ($is_valid_key && !empty($_SERVER['REMOTE_ADDR']) && !empty($allowedIpList)) {
    $clientIp = (string)$_SERVER['REMOTE_ADDR'];
    if (!in_array($clientIp, $allowedIpList, true)) {
        respond_app_restore(false, 'IP not allowed', [], 403);
    }
}

$clientIp = !empty($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
$rateKey = $clientIp . '|' . (string)$key . '|app';
$rateFile = sys_get_temp_dir() . '/restore_app_db.rate.' . md5($rateKey);
$rateWindow = isset($env['backup']['rate_window']) ? (int)$env['backup']['rate_window'] : 300;
$rateLimit = isset($env['backup']['rate_limit']) ? (int)$env['backup']['rate_limit'] : 1;
$rateEnabled = ($rateWindow > 0 && $rateLimit > 0);
$forceRate = isset($_GET['force']) && $_GET['force'] === '1';
$noRate = isset($_GET['nolimit']) && $_GET['nolimit'] === '1';
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
if ($rateEnabled && !$forceRate && !$noRate && count($hits) >= $rateLimit) {
    respond_app_restore(false, 'Rate limited', [], 429);
}
if ($rateEnabled) {
    $hits[] = $now;
    @file_put_contents($rateFile, json_encode($hits));
}

$backupDir = dirname(__DIR__) . '/db_data/backups_app';
$dbFile = app_db_path();
$dbBase = pathinfo($dbFile, PATHINFO_FILENAME);

if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0777, true);
}

$files = array_values(array_filter(scandir($backupDir), function ($f) use ($backupDir, $dbBase) {
    if (!is_file($backupDir . '/' . $f)) return false;
    if (!preg_match('/\.db$/i', $f)) return false;
    return (bool)preg_match('/^' . preg_quote($dbBase, '/') . '_\d{8}_\d{6}\.db$/', $f);
}));
// fallback to any local .db before cloud
if (empty($files)) {
    $files = array_values(array_filter(scandir($backupDir), function ($f) use ($backupDir) {
        return is_file($backupDir . '/' . $f) && preg_match('/\.db$/i', $f);
    }));
}
// Jika kosong, coba download dari Cloud
$downloaded = false;
$rcloneEnable = isset($env['rclone']['enable']) ? (bool)$env['rclone']['enable'] : false;
$rcloneDownload = isset($env['rclone']['download']) ? (bool)$env['rclone']['download'] : false;
$rcloneBin = isset($env['rclone']['bin']) ? (string)$env['rclone']['bin'] : '';
$rcloneRemote = isset($env['rclone']['remote']) ? (string)$env['rclone']['remote'] : '';
if (empty($files) && $rcloneEnable && $rcloneDownload && $rcloneBin !== '' && $rcloneRemote !== '' && file_exists($rcloneBin)) {
    $cmd = sprintf('%s copy "%s" "%s" --include "*.db" 2>&1', $rcloneBin, $rcloneRemote, $backupDir);
    exec($cmd, $output, $returnVar);
    if ($returnVar === 0) {
        $downloaded = true;
        $files = array_values(array_filter(scandir($backupDir), function ($f) use ($backupDir, $dbBase) {
            if (!is_file($backupDir . '/' . $f)) return false;
            if (!preg_match('/\.db$/i', $f)) return false;
            return (bool)preg_match('/^' . preg_quote($dbBase, '/') . '_\d{8}_\d{6}\.db$/', $f);
        }));
        if (empty($files)) {
            $files = array_values(array_filter(scandir($backupDir), function ($f) use ($backupDir) {
                return is_file($backupDir . '/' . $f) && preg_match('/\.db$/i', $f);
            }));
        }
    }
}
if (empty($files)) {
    respond_app_restore(false, 'No backup files', [], 404);
}

rsort($files);
$target = $_GET['file'] ?? $files[0];
$target = basename((string)$target);
$src = $backupDir . '/' . $target;

if (!file_exists($src)) {
    respond_app_restore(false, 'Backup not found', [], 404);
}

if (!is_writable(dirname($dbFile))) {
    respond_app_restore(false, 'DB folder not writable', [], 500);
}

if (!is_readable($src)) {
    respond_app_restore(false, 'Backup not readable', [], 500);
}

$tmpRestore = $dbFile . '.restore-tmp';
if (!copy($src, $tmpRestore)) {
    respond_app_restore(false, 'Restore failed', [], 500);
}

try {
    if (!class_exists('PDO') || !extension_loaded('pdo_sqlite')) {
        throw new Exception('PDO SQLite not available');
    }
    $db = new PDO('sqlite:' . $tmpRestore);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA quick_check;');
    $db->exec('VACUUM;');
} catch (Exception $e) {
    @unlink($tmpRestore);
    respond_app_restore(false, 'Restore integrity failed: ' . htmlspecialchars($e->getMessage()), [], 500);
}

if (!@rename($tmpRestore, $dbFile)) {
    @unlink($tmpRestore);
    respond_app_restore(false, 'Failed to finalize restore', [], 500);
}

$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}
$logFile = $logDir . '/restore_app_db.log';
$logLine = date('Y-m-d H:i:s') . "\t" . $target . "\t" . ($downloaded ? 'From Cloud' : 'Local') . "\n";
@file_put_contents($logFile, $logLine, FILE_APPEND);

if (function_exists('app_audit_log')) {
    app_audit_log('restore_app_db', $target, 'Restore DB aplikasi.', 'success', [
        'source' => $downloaded ? 'cloud' : 'local'
    ]);
}

if ($is_ajax) {
    respond_app_restore(true, 'Restore OK', [
        'file' => $target,
        'source' => $downloaded ? 'cloud' : 'local'
    ], 200);
}
echo 'Restore OK: ' . htmlspecialchars($target);
