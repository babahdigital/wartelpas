<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
ensureRole();

if (!isset($_SESSION["mikhmon"])) {
    http_response_code(403);
    echo "Unauthorized.";
    exit;
}

$todo_id = trim((string)($_GET['id'] ?? ''));
$action = trim((string)($_GET['action'] ?? ''));
$url = trim((string)($_GET['url'] ?? ''));
$session = trim((string)($_GET['session'] ?? ''));

if ($todo_id === '') $todo_id = 'unknown';
if ($action === '') $action = 'action';

if (function_exists('app_audit_log')) {
    app_audit_log('todo_action', $todo_id, 'Todo action: ' . $action, 'success', [
        'session' => $session,
        'url' => $url
    ]);
}

echo "OK";
