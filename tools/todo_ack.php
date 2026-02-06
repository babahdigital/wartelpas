<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
require_once __DIR__ . '/../include/db.php';
ensureRole();

if (!isset($_SESSION["mikhmon"])) {
    http_response_code(403);
    echo "Unauthorized.";
    exit;
}
if (isOperator() && !operator_can('todo_ack')) {
    http_response_code(403);
    echo "Forbidden.";
    exit;
}

$root_dir = dirname(__DIR__);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}

$system_cfg = $env['system'] ?? [];
$db_rel = $system_cfg['db_file'] ?? 'db_data/babahdigital_main.db';
if (preg_match('/^[A-Za-z]:\\\\|^\//', $db_rel)) {
    $dbFile = $db_rel;
} else {
    $dbFile = $root_dir . '/' . ltrim($db_rel, '/');
}

$key = trim((string)($_GET['key'] ?? ''));
$date = trim((string)($_GET['date'] ?? date('Y-m-d')));
$session = trim((string)($_GET['session'] ?? ''));
$next = trim((string)($_GET['next'] ?? ''));

if ($key === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $key)) {
    http_response_code(400);
    echo "Invalid key.";
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo "Invalid date.";
    exit;
}

if ($next === '' || preg_match('/^https?:/i', $next)) {
    $next = './?session=' . urlencode($session);
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS todo_ack (key TEXT, report_date TEXT, ack_at TEXT, PRIMARY KEY (key, report_date))");
    $stmt = $db->prepare("INSERT OR REPLACE INTO todo_ack (key, report_date, ack_at) VALUES (:k, :d, :t)");
    $stmt->execute([
        ':k' => $key,
        ':d' => $date,
        ':t' => date('Y-m-d H:i:s')
    ]);
    if (function_exists('app_audit_log')) {
        app_audit_log('todo_ack', $key, 'Todo diakui.', 'success', [
            'date' => $date,
            'session' => $session
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo "DB error.";
    exit;
}

header('Location: ' . $next);
exit;
