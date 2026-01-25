<?php
// Clear server log files (protected)
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: text/plain');

$secret_token = "WartelpasSecureKey";
if (!isset($_GET['key']) || $_GET['key'] !== $secret_token) {
    http_response_code(403);
    die("Error: Token Salah.");
}

$scope = strtolower(trim($_GET['scope'] ?? 'basic'));
$purgeSettlement = isset($_GET['purge']) && $_GET['purge'] === '1';
$maxMb = isset($_GET['max_mb']) ? (int)$_GET['max_mb'] : 0;

$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    echo "No logs dir";
    exit;
}

$targets = [];
$targets[] = $logDir . '/usage_ingest.log';
$targets[] = $logDir . '/live_ingest.log';

if ($scope === 'all') {
    $extra = glob($logDir . '/*.log') ?: [];
    foreach ($extra as $file) {
        $targets[] = $file;
    }
    $archiveDir = $logDir . '/settlement_archive';
    if (is_dir($archiveDir)) {
        $archived = glob($archiveDir . '/*.log') ?: [];
        foreach ($archived as $file) {
            $targets[] = $file;
        }
    }
}

if ($purgeSettlement) {
    $settlementLogs = glob($logDir . '/settlement_*.log') ?: [];
    foreach ($settlementLogs as $file) {
        $targets[] = $file;
    }
}

$targets = array_values(array_unique($targets));

function truncate_file($file) {
    $fp = @fopen($file, 'c+');
    if (!$fp) return false;
    $ok = @ftruncate($fp, 0);
    @fclose($fp);
    return $ok;
}

$cleared = 0;
$skipped = 0;
$errors = 0;
foreach ($targets as $file) {
    if (!file_exists($file) || is_dir($file)) {
        $skipped++;
        continue;
    }
    if ($maxMb > 0) {
        $size = @filesize($file);
        if ($size !== false && $size < ($maxMb * 1024 * 1024)) {
            $skipped++;
            continue;
        }
    }
    if (truncate_file($file)) {
        $cleared++;
    } else {
        $errors++;
    }
}

echo "OK cleared=" . $cleared . " skipped=" . $skipped . " errors=" . $errors . " scope=" . $scope . " purge_settlement=" . ($purgeSettlement ? '1' : '0');