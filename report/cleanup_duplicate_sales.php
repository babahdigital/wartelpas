<?php
// Cleanup duplicate sales records caused by relogin
// Keeps the earliest row per (username, sale_date) in sales_history and live_sales

ini_set('display_errors', 0);
error_reporting(0);

$root_dir = dirname(__DIR__);
$dbFile = $root_dir . '/db_data/mikhmon_stats.db';
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

    $deleted_history = 0;
    $deleted_live = 0;

    $db->exec("BEGIN IMMEDIATE TRANSACTION");

    $delHist = $db->prepare("DELETE FROM sales_history
        WHERE id NOT IN (
            SELECT MIN(id) FROM sales_history
            WHERE username IS NOT NULL AND username <> ''
              AND sale_date IS NOT NULL AND sale_date <> ''
            GROUP BY username, sale_date
        )
        AND username IS NOT NULL AND username <> ''
        AND sale_date IS NOT NULL AND sale_date <> ''");
    $delHist->execute();
    $deleted_history = (int)$delHist->rowCount();

    $delLive = $db->prepare("DELETE FROM live_sales
        WHERE id NOT IN (
            SELECT MIN(id) FROM live_sales
            WHERE username IS NOT NULL AND username <> ''
              AND sale_date IS NOT NULL AND sale_date <> ''
            GROUP BY username, sale_date
        )
        AND username IS NOT NULL AND username <> ''
        AND sale_date IS NOT NULL AND sale_date <> ''");
    $delLive->execute();
    $deleted_live = (int)$delLive->rowCount();

    $db->exec("COMMIT");

    $msg = "Cleanup selesai. sales_history dihapus: {$deleted_history}, live_sales dihapus: {$deleted_live}.";
    @file_put_contents($logDir . '/cleanup_duplicate_sales.log', date('c') . " | " . $msg . "\n", FILE_APPEND);
    echo $msg;
} catch (Exception $e) {
    try { $db->exec("ROLLBACK"); } catch (Exception $e2) {}
    @file_put_contents($logDir . '/cleanup_duplicate_sales.log', date('c') . " | ERROR | " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo "Cleanup gagal.";
}
