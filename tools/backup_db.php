<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
// Simple DB backup endpoint (protected)
ini_set('display_errors', 0);
error_reporting(0);
// Mode respons
$is_ajax = (isset($_GET['ajax']) && $_GET['ajax'] == '1') ||
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (isset($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if ($is_ajax) {
    header('Content-Type: application/json; charset=utf-8');
} else {
    header('Content-Type: text/plain; charset=utf-8');
}

function respond_backup($ok, $message, $data = [], $code = 200) {
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
    respond_backup(false, 'Forbidden', [], 403);
}

$allowedIpList = isset($env['backup']['allowed_ips']) && is_array($env['backup']['allowed_ips'])
    ? $env['backup']['allowed_ips']
    : ['127.0.0.1', '::1', '10.10.83.1', '172.19.0.1'];
if (!empty($_SERVER['REMOTE_ADDR']) && !empty($allowedIpList)) {
    $clientIp = (string)$_SERVER['REMOTE_ADDR'];
    if (!in_array($clientIp, $allowedIpList, true)) {
        respond_backup(false, 'IP not allowed', [], 403);
    }
}

$clientIp = !empty($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
$rateKey = $clientIp . '|' . (string)$key;
$rateFile = sys_get_temp_dir() . '/backup_db.rate.' . md5($rateKey);
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
    respond_backup(false, 'Rate limited', [], 429);
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
if (!file_exists($dbFile)) {
    respond_backup(false, 'DB not found', [], 404);
}

$backupDir = $root . '/db_data/backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0777, true);
}
if (!is_dir($backupDir) || !is_writable($backupDir)) {
    respond_backup(false, 'Backup dir not writable', [], 500);
}

$keepDays = isset($_GET['keep_days']) ? (int)$_GET['keep_days'] : (int)($env['backup']['keep_days'] ?? 14);
$keepCount = isset($_GET['keep_count']) ? (int)$_GET['keep_count'] : (int)($env['backup']['keep_count'] ?? 30);
if ($keepDays <= 0) $keepDays = 14;
if ($keepCount <= 0) $keepCount = 30;

$stamp = date('Ymd_His');
$backupFile = $backupDir . '/' . $dbBase . '_' . $stamp . '.db';
$tempFile = $backupFile . '.tmp';

$minDbSize = isset($env['backup']['min_db_size']) ? (int)$env['backup']['min_db_size'] : (1024 * 64);
$srcSize = @filesize($dbFile);
if (!$srcSize || $srcSize < $minDbSize) {
    respond_backup(false, 'Source DB too small or unreadable', [], 500);
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
    respond_backup(false, $message ?: 'Backup failed', [], 500);
}

$tmpSize = @filesize($tempFile);
if (!$tmpSize || $tmpSize < ($srcSize * 0.8)) {
    @unlink($tempFile);
    respond_backup(false, 'Backup size invalid', [], 500);
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
    respond_backup(false, 'Backup integrity failed', [], 500);
}

if (!@rename($tempFile, $backupFile)) {
    @unlink($tempFile);
    respond_backup(false, 'Failed to finalize backup', [], 500);
}

// Sync ke Google Drive via rclone (opsional)
$cloudStatus = 'Skipped';
$rcloneEnable = isset($env['rclone']['enable']) ? (bool)$env['rclone']['enable'] : false;
$rcloneUpload = isset($env['rclone']['upload']) ? (bool)$env['rclone']['upload'] : false;
$rcloneBin = isset($env['rclone']['bin']) ? (string)$env['rclone']['bin'] : '';
$rcloneRemote = isset($env['rclone']['remote']) ? (string)$env['rclone']['remote'] : '';
$cloudParam = $_GET['cloud'] ?? '';
$asyncParam = $_GET['async'] ?? '';
$cloudEnabled = ($cloudParam === '' || $cloudParam === '1');
$cloudAsync = ($asyncParam === '1') || (!empty($env['backup']['rclone_async']));
if ($rcloneEnable && $rcloneUpload && $cloudEnabled && $rcloneBin !== '' && $rcloneRemote !== '' && file_exists($rcloneBin)) {
    $dest = $rcloneRemote . '/' . basename($backupFile);
    if ($cloudAsync) {
        $cmd = sprintf('%s copyto "%s" "%s" >/dev/null 2>&1 &', $rcloneBin, $backupFile, $dest);
        exec($cmd);
        $cloudStatus = 'Queued';
    } else {
        $cmd = sprintf('%s copyto "%s" "%s" 2>&1', $rcloneBin, $backupFile, $dest);
        exec($cmd, $output, $returnVar);
        $cloudStatus = ($returnVar === 0) ? 'Uploaded to Drive' : 'Upload Failed';
    }
}

$logFile = $root . '/logs/backup_db.log';
$logLine = date('Y-m-d H:i:s') . "\t" . basename($backupFile) . "\t" . ($tmpSize ?? 0) . "\t" . $cloudStatus . "\n";
@file_put_contents($logFile, $logLine, FILE_APPEND);

// Cleanup old backups by days
$files = glob($backupDir . '/' . $dbBase . '_*.db') ?: [];
$now = time();
$deleted = 0;
foreach ($files as $f) {
    $mtime = @filemtime($f);
    if ($mtime && $now - $mtime > ($keepDays * 86400)) {
        if (@unlink($f)) $deleted++;
    }
}

// Cleanup old backups by count (keep newest)
$files = glob($backupDir . '/' . $dbBase . '_*.db') ?: [];
usort($files, function($a, $b) {
    return filemtime($b) <=> filemtime($a);
});
if (count($files) > $keepCount) {
    $toDelete = array_slice($files, $keepCount);
    foreach ($toDelete as $f) {
        if (@unlink($f)) $deleted++;
    }
}

// Cleanup WAL/SHM/temp artifacts in backup folder
$sidecars = array_merge(
    glob($backupDir . '/' . $dbBase . '_*.db-wal') ?: [],
    glob($backupDir . '/' . $dbBase . '_*.db-shm') ?: [],
    glob($backupDir . '/' . $dbBase . '_*.db.tmp-wal') ?: [],
    glob($backupDir . '/' . $dbBase . '_*.db.tmp-shm') ?: [],
    glob($backupDir . '/' . $dbBase . '_*.db.tmp') ?: []
);
foreach ($sidecars as $f) {
    if (@unlink($f)) $deleted++;
}

if ($is_ajax) {
    respond_backup(true, 'Backup success', [
        'backup' => basename($backupFile),
        'cloud' => $cloudStatus,
        'deleted' => $deleted
    ], 200);
}
echo "OK\n";
echo "Backup: " . basename($backupFile) . "\n";
echo "Cloud: " . $cloudStatus . "\n";
echo "Deleted: " . $deleted . "\n";
