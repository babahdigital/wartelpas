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

$session = $_GET['session'] ?? '';
if ($session === '') {
    http_response_code(403);
    die("Error: Session tidak valid.");
}

$scope = strtolower(trim($_GET['scope'] ?? 'basic'));
$purgeSettlement = isset($_GET['purge']) && $_GET['purge'] === '1';

$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    echo "No logs dir";
    exit;
}

$targets = [
    $logDir . '/usage_ingest.log',
    $logDir . '/live_ingest.log'
];

if ($scope === 'all') {
    $targets[] = $logDir . '/settlement_ingest_debug.log';
    $targets[] = $logDir . '/ready_skip.log';
    $targets[] = $logDir . '/sync_usage.log';
}

if ($purgeSettlement) {
    $settlementLogs = glob($logDir . '/settlement_*.log');
    if (is_array($settlementLogs)) {
        foreach ($settlementLogs as $file) {
            $targets[] = $file;
        }
    }
}

$cleared = 0;
foreach ($targets as $file) {
    if (file_exists($file)) {
        @file_put_contents($file, '');
        $cleared++;
    }
}

echo "OK cleared=" . $cleared . " scope=" . $scope . " purge_settlement=" . ($purgeSettlement ? '1' : '0');