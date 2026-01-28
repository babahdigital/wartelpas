<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
requireLogin('../admin.php?id=login');
requireSuperAdmin('../admin.php?id=sessions');

header('Content-Type: text/plain');

$root_dir = dirname(__DIR__);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}

$session = trim((string)($_GET['session'] ?? ''));
if ($session === '') {
    echo "Error: session wajib diisi.\n";
    exit;
}

$baseUrl = '';
$system_cfg = $env['system'] ?? [];
if (!empty($system_cfg['base_url'])) {
    $baseUrl = rtrim((string)$system_cfg['base_url'], '/');
}
if ($baseUrl === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $baseUrl = ($host !== '') ? ($scheme . '://' . $host) : '';
}
if ($baseUrl === '' && !empty($_GET['base_url'])) {
    $baseUrl = rtrim((string)$_GET['base_url'], '/');
}
if ($baseUrl === '') {
    echo "Error: base_url tidak ditemukan. Tambahkan system.base_url di env atau kirim ?base_url=.\n";
    exit;
}

$live_key = $env['security']['live_ingest']['token'] ?? '';
$usage_key = $env['security']['usage_ingest']['token'] ?? '';
if ($live_key === '') $live_key = $env['backup']['secret'] ?? '';
if ($usage_key === '') $usage_key = $env['backup']['secret'] ?? '';

$tmpl_onlogin = $root_dir . '/mikrotik/onlogin';
$tmpl_onlogout = $root_dir . '/mikrotik/onlogout';
if (!file_exists($tmpl_onlogin) || !file_exists($tmpl_onlogout)) {
    echo "Error: template mikrotik/onlogin atau mikrotik/onlogout tidak ditemukan.\n";
    exit;
}

$replace = [
    '{{BASE_URL}}' => $baseUrl,
    '{{LIVE_KEY}}' => $live_key,
    '{{USAGE_KEY}}' => $usage_key,
    '{{SESSION}}' => $session
];

$onlogin = str_replace(array_keys($replace), array_values($replace), file_get_contents($tmpl_onlogin));
$onlogout = str_replace(array_keys($replace), array_values($replace), file_get_contents($tmpl_onlogout));

echo "# === MikroTik On-Login (Dynamic) ===\n";
echo $onlogin . "\n\n";
echo "# === MikroTik On-Logout (Dynamic) ===\n";
echo $onlogout . "\n";
