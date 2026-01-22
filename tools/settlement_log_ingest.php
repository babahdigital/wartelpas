<?php
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

$root_dir = dirname(__DIR__);
require_once($root_dir . '/include/config.php');

$logDir = $root_dir . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$debugLog = $logDir . '/settlement_ingest_debug.log';
function append_debug_log($file, $message) {
    $line = date('Y-m-d H:i:s') . "\t" . $message . "\n";
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

$key = $_GET['key'] ?? '';
$session = $_GET['session'] ?? '';
$raw_date = $_GET['date'] ?? '';
$raw_time = $_GET['time'] ?? '';
$topic = $_GET['topic'] ?? 'script,info';
$msg = $_GET['msg'] ?? '';

$secret = getenv('WARTELPAS_SETTLE_LOG_TOKEN');
if ($secret === false || trim((string)$secret) === '') {
    if (defined('WARTELPAS_SETTLE_LOG_TOKEN')) {
        $secret = WARTELPAS_SETTLE_LOG_TOKEN;
    } else {
        $secret = 'WartelpasSecureKey';
    }
}

if ($key === '' || $key !== $secret) {
    append_debug_log($debugLog, 'reject=invalid_key ip=' . ($_SERVER['REMOTE_ADDR'] ?? '-') . ' session=' . $session);
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Invalid key']);
    exit;
}

if ($session === '' || !isset($data[$session])) {
    append_debug_log($debugLog, 'reject=invalid_session ip=' . ($_SERVER['REMOTE_ADDR'] ?? '-') . ' session=' . $session);
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Invalid session']);
    exit;
}

function norm_date_from_router($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return date('Y-m-d');
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
        return substr($raw, 0, 10);
    }
    if (preg_match('/^[a-zA-Z]{3}\/\d{2}\/\d{4}$/', $raw)) {
        $mon = strtolower(substr($raw, 0, 3));
        $map = [
            'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04', 'may' => '05', 'jun' => '06',
            'jul' => '07', 'aug' => '08', 'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12'
        ];
        $mm = $map[$mon] ?? '';
        if ($mm !== '') {
            $parts = explode('/', $raw);
            return $parts[2] . '-' . $mm . '-' . $parts[1];
        }
    }
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $raw)) {
        $parts = explode('/', $raw);
        return $parts[2] . '-' . $parts[0] . '-' . $parts[1];
    }
    return date('Y-m-d');
}

$date = norm_date_from_router($raw_date);
$time = trim((string)$raw_time);
if ($time === '' || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
    $time = date('H:i:s');
}

$topic = trim((string)$topic);
if ($topic === '') $topic = 'script,info';
$msg = trim((string)$msg);
if ($msg === '') {
    $query = $_SERVER['QUERY_STRING'] ?? '';
    $keys = implode(',', array_keys($_GET));
    append_debug_log($debugLog, 'skip=empty_msg ip=' . ($_SERVER['REMOTE_ADDR'] ?? '-') . ' session=' . $session . ' keys=' . $keys . ' qs=' . $query);
    echo json_encode(['ok' => true, 'message' => 'Empty']);
    exit;
}

$msg = preg_replace('/[\r\n\t]+/', ' ', $msg);
$topic = preg_replace('/[\r\n\t]+/', ' ', $topic);

$safe_session = preg_replace('/[^A-Za-z0-9_-]/', '', $session);
$safe_date = preg_replace('/[^0-9-]/', '', $date);
if ($safe_date === '') $safe_date = date('Y-m-d');
$logFile = $logDir . '/settlement_' . $safe_session . '_' . $safe_date . '.log';

$line = $time . "\t" . $topic . "\t" . $msg . "\n";
@file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
append_debug_log($debugLog, 'ok ip=' . ($_SERVER['REMOTE_ADDR'] ?? '-') . ' session=' . $session . ' file=' . basename($logFile));

echo json_encode(['ok' => true]);
