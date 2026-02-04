<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
requireLogin('../admin.php?id=login');
requireSuperAdmin('../admin.php?id=sessions');

ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: text/html; charset=utf-8');

$root_dir = dirname(__DIR__);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
$secret = $env['security']['tools']['token'] ?? ($env['backup']['secret'] ?? '');
$key = $_GET['key'] ?? '';
if ($key !== $secret) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

require_once __DIR__ . '/../include/db.php';
$appDbFile = app_db_path();
$appDbReal = realpath($appDbFile) ?: $appDbFile;
if (!file_exists($appDbFile)) {
    echo "App DB not found";
    exit;
}

if (!class_exists('PDO')) {
    echo "PDO not available";
    exit;
}
if (!extension_loaded('pdo_sqlite')) {
    echo "PDO SQLite extension not loaded";
    exit;
}

try {
    $db = new PDO('sqlite:' . $appDbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $quick = (string)$db->query('PRAGMA quick_check;')->fetchColumn();

    echo "<h3>app_db health</h3>";
    echo "<p>App DB Path: " . htmlspecialchars($appDbReal) . "</p>";
    echo "<p>Writable: " . (is_writable(dirname($appDbFile)) ? 'yes' : 'no') . " | File exists: yes</p>";
    echo "<p>Quick Check: " . htmlspecialchars($quick !== '' ? $quick : '-') . "</p>";
    if (strtolower($quick) !== 'ok') {
        echo "App DB error: quick_check failed";
    }
} catch (Exception $e) {
    http_response_code(500);
    echo "App DB error: " . htmlspecialchars($e->getMessage());
}
