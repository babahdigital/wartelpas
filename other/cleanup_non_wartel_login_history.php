<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
requireLogin('../admin.php?id=login');
requireSuperAdmin('../admin.php?id=sessions');
// Cleanup non-wartel entries in login_history (no BLOK info)

ini_set('display_errors', 0);
error_reporting(0);

$root_dir = dirname(__DIR__);
require_once $root_dir . '/include/db_helpers.php';
$dbFile = get_stats_db_path();
$logDir = $root_dir . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

if (!file_exists($dbFile)) {
    http_response_code(404);
    echo "DB tidak ditemukan.";
    exit;
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");
    $db->exec("PRAGMA busy_timeout=5000;");

    $db->exec("BEGIN IMMEDIATE TRANSACTION");

    $del = $db->prepare("DELETE FROM login_history
        WHERE (blok_name IS NULL OR blok_name = '')
          AND (raw_comment IS NULL OR raw_comment = '' OR raw_comment NOT LIKE '%blok%')");
    $del->execute();
    $deleted = (int)$del->rowCount();

    $db->exec("COMMIT");

    $msg = "Cleanup login_history selesai. Terhapus: {$deleted}.";
    @file_put_contents($logDir . '/cleanup_non_wartel_login_history.log', date('c') . " | " . $msg . "\n", FILE_APPEND);
    echo $msg;
} catch (Exception $e) {
    try { $db->exec("ROLLBACK"); } catch (Exception $e2) {}
    @file_put_contents($logDir . '/cleanup_non_wartel_login_history.log', date('c') . " | ERROR | " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo "Cleanup gagal.";
}