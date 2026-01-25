<?php
// Restore SQLite DB from backup (protected)
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: text/html; charset=utf-8');

// Konfigurasi terpusat
$envFile = dirname(__DIR__) . '/include/env.php';
if (is_file($envFile)) {
    require_once $envFile;
}
$secret = isset($env['backup']['secret']) ? (string)$env['backup']['secret'] : 'WartelpasSecureKey';
$key = $_GET['key'] ?? '';
if ($key === '' && isset($_POST['key'])) {
    $key = (string)$_POST['key'];
}
if ($key === '' && isset($_SERVER['HTTP_X_BACKUP_KEY'])) {
    $key = (string)$_SERVER['HTTP_X_BACKUP_KEY'];
}
if (!hash_equals($secret, (string)$key)) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

$allowedIpList = isset($env['backup']['allowed_ips']) && is_array($env['backup']['allowed_ips'])
    ? $env['backup']['allowed_ips']
    : ['127.0.0.1', '::1', '10.10.83.1', '172.19.0.1'];
if (!empty($_SERVER['REMOTE_ADDR']) && !empty($allowedIpList)) {
    $clientIp = (string)$_SERVER['REMOTE_ADDR'];
    if (!in_array($clientIp, $allowedIpList, true)) {
        http_response_code(403);
        echo "IP not allowed";
        exit;
    }
}

$rateFile = sys_get_temp_dir() . '/restore_db.rate';
$rateWindow = isset($env['backup']['rate_window']) ? (int)$env['backup']['rate_window'] : 300;
$rateLimit = isset($env['backup']['rate_limit']) ? (int)$env['backup']['rate_limit'] : 1;
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
if (count($hits) >= $rateLimit) {
    http_response_code(429);
    echo "Rate limited";
    exit;
}
$hits[] = $now;
@file_put_contents($rateFile, json_encode($hits));

$backupDir = dirname(__DIR__) . '/db_data/backups';
$dbFile = dirname(__DIR__) . '/db_data/mikhmon_stats.db';

if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0777, true);
}

$files = array_values(array_filter(scandir($backupDir), function ($f) use ($backupDir) {
    return is_file($backupDir . '/' . $f) && preg_match('/\.db$/i', $f);
}));
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
        $files = array_values(array_filter(scandir($backupDir), function ($f) use ($backupDir) {
            return is_file($backupDir . '/' . $f) && preg_match('/\.db$/i', $f);
        }));
    }
}
if (empty($files)) {
    echo "No backup files";
    exit;
}

rsort($files);
$target = $_GET['file'] ?? $files[0];
$target = basename((string)$target);
$src = $backupDir . '/' . $target;

if (!file_exists($src)) {
    echo "Backup not found";
    exit;
}

if (!is_writable(dirname($dbFile))) {
    echo "DB folder not writable";
    exit;
}

if (!is_readable($src)) {
    echo "Backup not readable";
    exit;
}

$tmpRestore = $dbFile . '.restore-tmp';
if (!copy($src, $tmpRestore)) {
    echo "Restore failed";
    exit;
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
    echo "Restore integrity failed: " . htmlspecialchars($e->getMessage());
    exit;
}

if (!@rename($tmpRestore, $dbFile)) {
    @unlink($tmpRestore);
    echo "Failed to finalize restore";
    exit;
}

$logFile = dirname(__DIR__) . '/logs/restore_db.log';
$logLine = date('Y-m-d H:i:s') . "\t" . $target . "\t" . ($downloaded ? 'From Cloud' : 'Local') . "\n";
@file_put_contents($logFile, $logLine, FILE_APPEND);

echo "Restore OK: " . htmlspecialchars($target);
if ($downloaded) {
    echo " (Restored from Google Drive)";
}
