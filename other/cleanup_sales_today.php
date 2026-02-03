<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
requireLogin('../admin.php?id=login');
requireSuperAdmin('../admin.php?id=sessions');
session_start();
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

$target_date = isset($_GET['date']) && $_GET['date'] !== '' ? $_GET['date'] : date('Y-m-d');

$dbFile = get_stats_db_path();
if (!file_exists($dbFile)) {
    die("Error: Database tidak ditemukan.\n");
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Dedup sales_history by username+sale_date (keep earliest id)
    $delHist = $db->prepare("DELETE FROM sales_history
        WHERE sale_date = :d AND id NOT IN (
            SELECT MIN(id) FROM sales_history WHERE sale_date = :d GROUP BY username, sale_date
        )");
    $delHist->execute([':d' => $target_date]);
    $deleted_history = $delHist->rowCount();

    // Dedup live_sales pending by username+sale_date (keep earliest id)
    $delLive = $db->prepare("DELETE FROM live_sales
        WHERE sale_date = :d AND sync_status = 'pending' AND id NOT IN (
            SELECT MIN(id) FROM live_sales WHERE sale_date = :d AND sync_status = 'pending' GROUP BY username, sale_date
        )");
    $delLive->execute([':d' => $target_date]);
    $deleted_live = $delLive->rowCount();

    echo "OK: date={$target_date}, deleted_history={$deleted_history}, deleted_live={$deleted_live}\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
