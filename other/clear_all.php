<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
requireLogin('../admin.php?id=login');
requireSuperAdmin('../admin.php?id=sessions');
// FILE: tools/clear_all.php
// Clear semua data laporan & history

ini_set('display_errors', 1);
error_reporting(E_ALL);
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
    http_response_code(403);
    die("Error: Token Salah.");
}

$session = isset($_GET['session']) ? $_GET['session'] : '';
if ($session === '') {
    http_response_code(403);
    die("Error: Session tidak valid.");
}

$confirm = isset($_GET['confirm']) ? $_GET['confirm'] : '';
if ($confirm !== 'YES') {
    die("Error: Tambahkan confirm=YES untuk menjalankan reset total.\n");
}

$dbFile = get_stats_db_path();
if (!file_exists($dbFile)) {
    die("Error: Database tidak ditemukan.\n");
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");
    $db->exec("PRAGMA busy_timeout=2000;");

    $db->beginTransaction();
    $db->exec("DELETE FROM sales_history");
    $db->exec("DELETE FROM live_sales");
    $db->exec("DELETE FROM sales_summary_period");
    $db->exec("DELETE FROM sales_summary_block");
    $db->exec("DELETE FROM sales_summary_profile");
    $db->exec("DELETE FROM login_history");
    $db->commit();

    echo "OK: Semua data laporan, live, summary, dan login_history sudah dikosongkan.\n";
} catch (Exception $e) {
    if ($db && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo "Error: " . $e->getMessage();
}
?>