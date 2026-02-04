<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$root_dir = dirname(__DIR__);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}

$system_cfg = $env['system'] ?? [];
$api_key = trim((string)($system_cfg['api_key'] ?? $system_cfg['retur_key'] ?? ''));

$payload = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$key = trim((string)($payload['key'] ?? ''));
if ($api_key !== '' && $key !== $api_key) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

function stuck_sanitize_text($value, $maxLen = 120) {
    $value = trim((string)$value);
    if ($value === '') return '';
    $value = preg_replace('/[^\pL\pN\s\-_.:@\/]/u', '', $value);
    if (mb_strlen($value, 'UTF-8') > $maxLen) {
        $value = mb_substr($value, 0, $maxLen, 'UTF-8');
    }
    return $value;
}

$user = stuck_sanitize_text($payload['user'] ?? $payload['username'] ?? '', 64);
$ip = stuck_sanitize_text($payload['ip'] ?? '', 45);
$mac = stuck_sanitize_text($payload['mac'] ?? '', 30);
$uptime = stuck_sanitize_text($payload['uptime'] ?? '', 32);
$reason = stuck_sanitize_text($payload['reason'] ?? '', 32);
$profile = stuck_sanitize_text($payload['profile'] ?? '', 40);
$server = stuck_sanitize_text($payload['server'] ?? '', 40);

$bytes_in = (int)($payload['bytes_in'] ?? 0);
$bytes_out = (int)($payload['bytes_out'] ?? 0);
if ($bytes_in < 0) $bytes_in = 0;
if ($bytes_out < 0) $bytes_out = 0;

$log_dir = $root_dir . '/logs';
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0777, true);
}

$today = date('Y-m-d');
$log_file = $log_dir . '/stuck_kick_' . $today . '.log';

$entry = [
    'ts' => date('Y-m-d H:i:s'),
    'user' => $user,
    'ip' => $ip,
    'mac' => $mac,
    'uptime' => $uptime,
    'bytes_in' => $bytes_in,
    'bytes_out' => $bytes_out,
    'reason' => $reason,
    'profile' => $profile,
    'server' => $server
];

@file_put_contents($log_file, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

// Cleanup log harian (hapus selain hari ini)
try {
    foreach (glob($log_dir . '/stuck_kick_*.log') as $file) {
        if (!preg_match('/stuck_kick_(\d{4}-\d{2}-\d{2})\.log$/', $file, $m)) continue;
        $file_date = $m[1] ?? '';
        if ($file_date !== '' && $file_date < $today) {
            @unlink($file);
        }
    }
} catch (Exception $e) {}

echo json_encode(['ok' => true]);
