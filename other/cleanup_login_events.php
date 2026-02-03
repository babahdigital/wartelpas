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

$target_date = isset($_GET['date']) && $_GET['date'] !== '' ? $_GET['date'] : '';

$dbFile = get_stats_db_path();
if (!file_exists($dbFile)) {
    die("Error: Database tidak ditemukan.\n");
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $where = '';
    $params = [];
    if ($target_date !== '') {
        $where = 'WHERE date_key = :d';
        $params[':d'] = $target_date;
    }

    // Dedup login_events by username+date_key+login_time and username+date_key+logout_time (keep smallest id)
    $delLogin = $db->prepare("DELETE FROM login_events
        WHERE id NOT IN (
            SELECT MIN(id) FROM login_events
            WHERE login_time IS NOT NULL
            GROUP BY username, date_key, login_time
        )");
    if ($where !== '') {
        $delLogin = $db->prepare("DELETE FROM login_events
            WHERE date_key = :d AND id NOT IN (
                SELECT MIN(id) FROM login_events
                WHERE date_key = :d AND login_time IS NOT NULL
                GROUP BY username, date_key, login_time
            )");
    }
    $delLogin->execute($params);
    $deleted_login = $delLogin->rowCount();

    $delLogout = $db->prepare("DELETE FROM login_events
        WHERE id NOT IN (
            SELECT MIN(id) FROM login_events
            WHERE logout_time IS NOT NULL
            GROUP BY username, date_key, logout_time
        )");
    if ($where !== '') {
        $delLogout = $db->prepare("DELETE FROM login_events
            WHERE date_key = :d AND id NOT IN (
                SELECT MIN(id) FROM login_events
                WHERE date_key = :d AND logout_time IS NOT NULL
                GROUP BY username, date_key, logout_time
            )");
    }
    $delLogout->execute($params);
    $deleted_logout = $delLogout->rowCount();

    echo "OK: date=" . ($target_date !== '' ? $target_date : 'ALL') . ", dedup_login={$deleted_login}, dedup_logout={$deleted_logout}\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
