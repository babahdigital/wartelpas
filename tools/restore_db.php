<?php
// Restore SQLite DB from backup (protected)
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: text/html; charset=utf-8');

$secret = 'WartelpasSecureKey';
$key = $_GET['key'] ?? '';
if ($key !== $secret) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

$backupDir = dirname(__DIR__) . '/db_data/backups';
$dbFile = dirname(__DIR__) . '/db_data/mikhmon_stats.db';

if (!is_dir($backupDir)) {
    echo "Backup folder not found";
    exit;
}

$files = array_values(array_filter(scandir($backupDir), function ($f) use ($backupDir) {
    return is_file($backupDir . '/' . $f) && preg_match('/\.db$/i', $f);
}));
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

if (!copy($src, $dbFile)) {
    echo "Restore failed";
    exit;
}

try {
    if (!class_exists('PDO') || !extension_loaded('pdo_sqlite')) {
        throw new Exception('PDO SQLite not available');
    }
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA quick_check;');
    $db->exec('VACUUM;');
} catch (Exception $e) {
    echo "Restored, but VACUUM failed: " . htmlspecialchars($e->getMessage());
    exit;
}

echo "Restore OK: " . htmlspecialchars($target);
