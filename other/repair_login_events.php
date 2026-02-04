<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
requireLogin('../admin.php?id=login');
requireSuperAdmin('../admin.php?id=sessions');
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: text/plain');

$root_dir = dirname(__DIR__);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
require_once $root_dir . '/include/db_helpers.php';

$secret_token = $env['security']['tools']['token'] ?? ($env['backup']['secret'] ?? '');
if (!isset($_GET['key']) || $_GET['key'] !== $secret_token) {
    die("Error: Token Salah.\n");
}

$target_date = isset($_GET['date']) && $_GET['date'] !== '' ? $_GET['date'] : '';
$dry_run = isset($_GET['dry']) && $_GET['dry'] === '1';

$dbFile = get_stats_db_path();
if (!file_exists($dbFile)) {
    die("Error: Database tidak ditemukan.\n");
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $where = "WHERE login_time IS NOT NULL AND (logout_time IS NULL OR trim(logout_time) = '')";
    $params = [];
    if ($target_date !== '') {
        $where .= " AND date_key = :d";
        $params[':d'] = $target_date;
    }

    $sqlUpdate = "UPDATE login_events
        SET logout_time = (
            SELECT le2.login_time FROM login_events le2
            WHERE le2.username = login_events.username
              AND le2.date_key = login_events.date_key
              AND le2.login_time IS NOT NULL
              AND le2.login_time > login_events.login_time
            ORDER BY le2.login_time ASC
            LIMIT 1
        )
        $where";

    if ($dry_run) {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM login_events $where");
        $countStmt->execute($params);
        $count = (int)$countStmt->fetchColumn();
        echo "DRY RUN: calon diperbaiki={$count}" . ($target_date ? " (date={$target_date})" : "") . "\n";
        exit;
    }

    $stmt = $db->prepare($sqlUpdate);
    $stmt->execute($params);
    $affected = $stmt->rowCount();

    echo "OK: repaired={$affected}" . ($target_date ? " (date={$target_date})" : "") . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
