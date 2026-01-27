<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
requireLogin('../admin.php?id=login');
requireSuperAdmin('../admin.php?id=sessions');
// FILE: tools/build_sales_summary.php
// Build materialized sales summary tables (harian/bulanan/tahunan)

ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain');

$root_dir = dirname(__DIR__);
$dbFile = $root_dir . '/db_data/mikhmon_stats.db';
if (!file_exists($dbFile)) {
    die("Error: Database tidak ditemukan.\n");
}

require_once($root_dir . '/report/laporan/sales_summary_helper.php');

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");
    $db->exec("PRAGMA busy_timeout=2000;");
} catch (PDOException $e) {
    die("Error DB: " . $e->getMessage() . "\n");
}

try {
    rebuild_sales_summary($db);
    echo "Rekap penjualan berhasil diperbarui.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>