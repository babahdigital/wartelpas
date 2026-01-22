<?php
// Simple DB backup endpoint (protected)
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: text/plain; charset=utf-8');

$secret = 'WartelpasSecureKey';
$key = $_GET['key'] ?? '';
if ($key !== $secret) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

$root = dirname(__DIR__);
$dbFile = $root . '/db_data/mikhmon_stats.db';
if (!file_exists($dbFile)) {
    http_response_code(404);
    echo "DB not found";
    exit;
}

$backupDir = $root . '/db_data/backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0777, true);
}
if (!is_dir($backupDir) || !is_writable($backupDir)) {
    http_response_code(500);
    echo "Backup dir not writable";
    exit;
}

$keepDays = isset($_GET['keep_days']) ? (int)$_GET['keep_days'] : 14;
$keepCount = isset($_GET['keep_count']) ? (int)$_GET['keep_count'] : 30;
if ($keepDays <= 0) $keepDays = 14;
if ($keepCount <= 0) $keepCount = 30;

$stamp = date('Ymd_His');
$backupFile = $backupDir . '/mikhmon_stats_' . $stamp . '.db';

$ok = false;
$message = '';
try {
    if (class_exists('SQLite3')) {
        $src = new SQLite3($dbFile, SQLITE3_OPEN_READONLY);
        $dest = new SQLite3($backupFile, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $src->backup($dest);
        $dest->close();
        $src->close();
        $ok = true;
    } else {
        $ok = @copy($dbFile, $backupFile);
    }
} catch (Exception $e) {
    $message = 'Backup failed.';
}

if (!$ok || !file_exists($backupFile)) {
    http_response_code(500);
    echo $message ?: 'Backup failed';
    exit;
}

// Cleanup old backups by days
$files = glob($backupDir . '/mikhmon_stats_*.db') ?: [];
$now = time();
$deleted = 0;
foreach ($files as $f) {
    $mtime = @filemtime($f);
    if ($mtime && $now - $mtime > ($keepDays * 86400)) {
        if (@unlink($f)) $deleted++;
    }
}

// Cleanup old backups by count (keep newest)
$files = glob($backupDir . '/mikhmon_stats_*.db') ?: [];
usort($files, function($a, $b) {
    return filemtime($b) <=> filemtime($a);
});
if (count($files) > $keepCount) {
    $toDelete = array_slice($files, $keepCount);
    foreach ($toDelete as $f) {
        if (@unlink($f)) $deleted++;
    }
}

echo "OK\n";
echo "Backup: " . basename($backupFile) . "\n";
echo "Deleted: " . $deleted . "\n";
