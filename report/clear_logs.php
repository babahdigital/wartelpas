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

$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    echo "No logs dir";
    exit;
}

$targets = [
    $logDir . '/usage_ingest.log',
    $logDir . '/live_ingest.log'
];

$cleared = 0;
foreach ($targets as $file) {
    if (file_exists($file)) {
        @file_put_contents($file, '');
        $cleared++;
    }
}

echo "OK cleared=" . $cleared;
