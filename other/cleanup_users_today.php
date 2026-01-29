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
$secret_token = $env['security']['tools']['token'] ?? ($env['backup']['secret'] ?? '');
if (!isset($_GET['key']) || $_GET['key'] !== $secret_token) {
    die("Error: Token Salah.\n");
}

$target_date = isset($_GET['date']) && $_GET['date'] !== '' ? $_GET['date'] : date('Y-m-d');

$dbFile = $root_dir . '/db_data/mikhmon_stats.db';
if (!file_exists($dbFile)) {
    die("Error: Database tidak ditemukan.\n");
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Dedup login_events by (username, date_key) keep smallest id
    $delEvents = $db->prepare("DELETE FROM login_events
        WHERE date_key = :d AND id NOT IN (
            SELECT MIN(id) FROM login_events WHERE date_key = :d GROUP BY username, date_key
        )");
    $delEvents->execute([':d' => $target_date]);
    $deleted_events = $delEvents->rowCount();

    // Dedup login_history by username for the target date (keep latest updated_at if available)
    $delHist = $db->prepare("DELETE FROM login_history
        WHERE username IN (
            SELECT username FROM login_history WHERE
                login_date = :d
                OR substr(first_login_real,1,10) = :d
                OR substr(last_login_real,1,10) = :d
                OR substr(login_time_real,1,10) = :d
                OR substr(logout_time_real,1,10) = :d
                OR substr(updated_at,1,10) = :d
        )
        AND id NOT IN (
            SELECT id FROM login_history lh2
            WHERE lh2.username = login_history.username
            ORDER BY CASE WHEN lh2.updated_at IS NULL THEN 0 ELSE 1 END DESC, lh2.updated_at DESC, lh2.id DESC
            LIMIT 1
        )
    ");
    $delHist->execute([':d' => $target_date]);
    $deleted_history = $delHist->rowCount();

    echo "OK: date={$target_date}, dedup_events={$deleted_events}, dedup_history={$deleted_history}\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
