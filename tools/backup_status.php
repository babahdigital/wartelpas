<?php
// Backup status endpoint (protected)
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

$secret = 'WartelpasSecureKey';
$key = $_GET['key'] ?? '';
if ($key !== $secret) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Forbidden']);
    exit;
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
