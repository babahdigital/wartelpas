<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
requireLogin('../admin.php?id=login');
requireSuperAdmin('../admin.php?id=sessions');
// Simple DB check endpoint (protected)
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

$system_cfg = $env['system'] ?? [];
$db_rel = $system_cfg['db_file'] ?? 'db_data/mikhmon_stats.db';
if (preg_match('/^[A-Za-z]:\\\\|^\//', $db_rel)) {
    $dbFile = $db_rel;
} else {
    $dbFile = $root_dir . '/' . ltrim($db_rel, '/');
}
$dbReal = realpath($dbFile) ?: $dbFile;
if (!file_exists($dbFile)) {
    echo "DB not found";
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
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $cols = [];
    foreach ($db->query("PRAGMA table_info(login_history)") as $row) {
        $cols[] = $row['name'];
    }

    $wanted = [
        'username','login_time_real','logout_time_real','last_status','updated_at',
        'login_count','first_login_real','last_login_real','last_uptime','last_bytes','ip_address','mac_address'
    ];
    $selectCols = array_values(array_intersect($wanted, $cols));
    if (empty($selectCols)) {
        echo "login_history has no expected columns";
        exit;
    }

    $countRow = $db->query("SELECT COUNT(1) AS cnt FROM login_history")->fetch(PDO::FETCH_ASSOC);
    $sql = "SELECT " . implode(',', $selectCols) . " FROM login_history ORDER BY updated_at DESC LIMIT 50";
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    echo "<h3>login_history (latest 50)</h3>";
    echo "<p>DB Path: " . htmlspecialchars($dbReal) . "</p>";
    echo "<p>Writable: " . (is_writable(dirname($dbFile)) ? 'yes' : 'no') . " | File exists: " . (file_exists($dbFile) ? 'yes' : 'no') . "</p>";
    echo "<p>Total rows: " . htmlspecialchars((string)($countRow['cnt'] ?? '0')) . "</p>";
    echo "<p>Columns: " . htmlspecialchars(implode(', ', $cols)) . "</p>";
    echo "<table border='1' cellspacing='0' cellpadding='6'>";
    echo "<thead><tr>";
    foreach ($selectCols as $c) {
        echo "<th>" . htmlspecialchars($c) . "</th>";
    }
    echo "</tr></thead><tbody>";
    foreach ($rows as $r) {
        echo "<tr>";
        foreach ($selectCols as $c) {
            $val = $r[$c] ?? '';
            echo "<td>" . htmlspecialchars((string)$val) . "</td>";
        }
        echo "</tr>";
    }
    echo "</tbody></table>";
} catch (Exception $e) {
    http_response_code(500);
    echo "DB error: " . htmlspecialchars($e->getMessage());
}
