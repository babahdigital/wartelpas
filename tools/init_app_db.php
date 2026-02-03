<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
require_once __DIR__ . '/../include/db.php';

ini_set('display_errors', 0);
error_reporting(0);

$is_ajax = (isset($_GET['ajax']) && $_GET['ajax'] == '1') ||
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (isset($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if ($is_ajax) {
    header('Content-Type: application/json; charset=utf-8');
} else {
    header('Content-Type: text/plain; charset=utf-8');
}

function respond_init($ok, $message, $data = [], $code = 200) {
    global $is_ajax;
    http_response_code($code);
    if ($is_ajax) {
        echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $data));
    } else {
        echo $message;
        if (!empty($data)) {
            echo "\n" . json_encode($data, JSON_PRETTY_PRINT);
        }
    }
    exit;
}

$env = [];
$envFile = dirname(__DIR__) . '/include/env.php';
if (is_file($envFile)) {
    require $envFile;
}
$secret = $env['security']['tools']['token'] ?? ($env['backup']['secret'] ?? '');
$secret = trim((string)$secret);
$key = $_GET['key'] ?? ($_GET['token'] ?? ($_GET['k'] ?? ''));
if ($key === '' && isset($_POST['key'])) {
    $key = (string)$_POST['key'];
}
if ($key === '' && isset($_SERVER['HTTP_X_TOOLS_KEY'])) {
    $key = (string)$_SERVER['HTTP_X_TOOLS_KEY'];
}
if ($key === '' && isset($_SERVER['HTTP_X_BACKUP_KEY'])) {
    $key = (string)$_SERVER['HTTP_X_BACKUP_KEY'];
}
$key = trim((string)$key);
$is_valid_key = $secret !== '' && $key !== '' && hash_equals($secret, $key);

$client_ip = !empty($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
$force_local = isset($_GET['force_local']) && $_GET['force_local'] === '1';
$is_local_ip = ($client_ip === '127.0.0.1' || $client_ip === '::1' || preg_match('/^10\.10\.83\./', $client_ip));
$allow_local = $force_local && $is_local_ip;

if (!$is_valid_key && !$allow_local) {
    respond_init(false, 'Forbidden', [], 403);
}

$force_reset = isset($_GET['reset']) && $_GET['reset'] === '1';
$admin_user = trim((string)($_GET['user'] ?? 'admin'));
$admin_pass = trim((string)($_GET['pass'] ?? 'admin123'));
if ($admin_user === '' || $admin_pass === '') {
    respond_init(false, 'Username/password kosong', [], 400);
}

try {
    $pdo = app_db();
    if ($force_reset) {
        $hash = function_exists('hash_password_value') ? hash_password_value($admin_pass) : $admin_pass;
        app_db_set_admin($admin_user, $hash);
    } else {
        app_db_import_legacy_if_needed();
        $admin = app_db_get_admin();
        if (empty($admin)) {
            app_db_seed_default_admin($pdo);
        }
    }
} catch (Exception $e) {
    respond_init(false, 'DB init gagal', ['error' => $e->getMessage()], 500);
}

$dbFile = app_db_path();
$admin = app_db_get_admin();
respond_init(true, 'OK', [
    'db' => $dbFile,
    'admin_user' => $admin['username'] ?? '',
    'reset' => $force_reset
]);
