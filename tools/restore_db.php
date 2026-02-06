<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
require_once __DIR__ . '/../include/db.php';
if (isset($_SESSION['mikhmon']) && isOperator() && !operator_can('restore_only')) {
    respond_restore(false, 'Forbidden', [], 403);
}
// Restore SQLite DB from backup (protected)
ini_set('display_errors', 0);
error_reporting(0);
// Mode respons
$is_ajax = (isset($_GET['ajax']) && $_GET['ajax'] == '1') ||
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (isset($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if ($is_ajax) {
    header('Content-Type: application/json; charset=utf-8');
} else {
    header('Content-Type: text/html; charset=utf-8');
}

function respond_restore($ok, $message, $data = [], $code = 200) {
    global $is_ajax;
    http_response_code($code);
    if ($is_ajax) {
        echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $data));
    } else {
        echo $message;
    }
    exit;
}

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

$allow_session = isset($_SESSION['mikhmon']) && (isSuperAdmin() || (isOperator() && operator_can('restore_only')));

if (!$is_valid_key && !$allow_session) {
    requireLogin('../admin.php?id=login');
} elseif ($is_valid_key) {
    if (!isset($_SESSION['mikhmon'])) {
        $_SESSION['mikhmon'] = 'tools';
        $_SESSION['mikhmon_level'] = 'superadmin';
    }
}

if (!$is_valid_key && !$allow_session) {
    respond_restore(false, 'Forbidden', [], 403);
}

$allowedIpList = isset($env['backup']['allowed_ips']) && is_array($env['backup']['allowed_ips'])
    ? $env['backup']['allowed_ips']
    : ['127.0.0.1', '::1', '10.10.83.1', '172.19.0.1'];
if (!$allow_session && !empty($_SERVER['REMOTE_ADDR']) && !empty($allowedIpList)) {
    $clientIp = (string)$_SERVER['REMOTE_ADDR'];
    if (!in_array($clientIp, $allowedIpList, true)) {
        respond_restore(false, 'IP not allowed', [], 403);
    }
}

$clientIp = !empty($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
$rateKey = $clientIp . '|' . (string)$key;
$rateFile = sys_get_temp_dir() . '/restore_db.rate.' . md5($rateKey);
$rateWindow = isset($env['backup']['rate_window']) ? (int)$env['backup']['rate_window'] : 300;
$rateLimit = isset($env['backup']['rate_limit']) ? (int)$env['backup']['rate_limit'] : 1;
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
if (!$forceRate && !$noRate && count($hits) >= $rateLimit) {
    respond_restore(false, 'Rate limited', [], 429);
}
$hits[] = $now;
@file_put_contents($rateFile, json_encode($hits));

$root = dirname(__DIR__);
$system_cfg = $env['system'] ?? [];
$db_rel = $system_cfg['db_file'] ?? 'db_data/babahdigital_main.db';
if (preg_match('/^[A-Za-z]:\\\\|^\//', $db_rel)) {
    $dbFile = $db_rel;
} else {
    $dbFile = $root . '/' . ltrim($db_rel, '/');
}
$dbBase = pathinfo($dbFile, PATHINFO_FILENAME);
$backupDir = $root . '/db_data/backups';

if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0777, true);
}

$files = array_values(array_filter(scandir($backupDir), function ($f) use ($backupDir, $dbBase) {
    if (!is_file($backupDir . '/' . $f)) return false;
    if (!preg_match('/\.db$/i', $f)) return false;
    if (preg_match('/^' . preg_quote($dbBase, '/') . '_\d{8}_\d{6}\.db$/', $f)) return true;
    return false;
}));
// fallback to any local .db before cloud
if (empty($files)) {
    $files = array_values(array_filter(scandir($backupDir), function ($f) use ($backupDir) {
        return is_file($backupDir . '/' . $f) && preg_match('/\.db$/i', $f);
    }));
}
// Jika kosong, coba download dari Google Drive
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
            if (preg_match('/^' . preg_quote($dbBase, '/') . '_\d{8}_\d{6}\.db$/', $f)) return true;
            return false;
        }));
        if (empty($files)) {
            $files = array_values(array_filter(scandir($backupDir), function ($f) use ($backupDir) {
                return is_file($backupDir . '/' . $f) && preg_match('/\.db$/i', $f);
            }));
        }
    }
}
if (empty($files)) {
    respond_restore(false, 'No backup files', [], 404);
}

rsort($files);
$target = $_GET['file'] ?? $files[0];
$target = basename((string)$target);
$src = $backupDir . '/' . $target;

if (!file_exists($src)) {
    respond_restore(false, 'Backup not found', [], 404);
}

if (!is_writable(dirname($dbFile))) {
    respond_restore(false, 'DB folder not writable', [], 500);
}

if (!is_readable($src)) {
    respond_restore(false, 'Backup not readable', [], 500);
}

$tmpRestore = $dbFile . '.restore-tmp';
if (!copy($src, $tmpRestore)) {
    respond_restore(false, 'Restore failed', [], 500);
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
    respond_restore(false, 'Restore integrity failed: ' . htmlspecialchars($e->getMessage()), [], 500);
}

if (!@rename($tmpRestore, $dbFile)) {
    @unlink($tmpRestore);
    respond_restore(false, 'Failed to finalize restore', [], 500);
}

$logFile = dirname(__DIR__) . '/logs/restore_db.log';
$logLine = date('Y-m-d H:i:s') . "\t" . $target . "\t" . ($downloaded ? 'From Cloud' : 'Local') . "\n";
@file_put_contents($logFile, $logLine, FILE_APPEND);

if (function_exists('app_audit_log')) {
    app_audit_log('restore_db', $target, 'Restore DB utama.', 'success', [
        'source' => $downloaded ? 'cloud' : 'local'
    ]);
}

if ($is_ajax) {
    respond_restore(true, 'Restore OK', [
        'file' => $target,
        'source' => $downloaded ? 'cloud' : 'local'
    ], 200);
}
echo "Restore OK: " . htmlspecialchars($target);
if ($downloaded) {
    echo " (Restored from Google Drive)";
}
