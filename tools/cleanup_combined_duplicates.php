<?php
// tools/cleanup_combined_duplicates.php
// Hapus duplikasi lintas tabel: live_sales yang sudah ada di sales_history (per username+sale_date)
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: text/plain');

$secret_token = "WartelpasSecureKey";
$key = $_GET['key'] ?? ($_POST['key'] ?? '');
if ($key === '' && isset($_SERVER['HTTP_X_WARTELPAS_KEY'])) {
    $key = $_SERVER['HTTP_X_WARTELPAS_KEY'];
}
$key = trim((string)$key);
if ($key === '' || !hash_equals($secret_token, $key)) {
    http_response_code(403);
    die("Error: Token Salah.\n");
}

$session = $_GET['session'] ?? '';
if ($session === '') {
    http_response_code(403);
    die("Error: Session tidak valid.\n");
}

$root_dir = dirname(__DIR__);
require_once($root_dir . '/include/config.php');
if (!isset($data[$session])) {
    http_response_code(403);
    die("Error: Session tidak terdaftar.\n");
}
require_once($root_dir . '/include/readcfg.php');
if (!isset($hotspot_server) || $hotspot_server !== 'wartel') {
    http_response_code(403);
    die("Error: Hanya untuk server wartel.\n");
}

$dbFile = $root_dir . '/db_data/mikhmon_stats.db';
if (!file_exists($dbFile)) {
    http_response_code(500);
    die("DB not found\n");
}

$date = trim((string)($_GET['date'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$mode = strtolower(trim((string)($_GET['mode'] ?? ''))); // all|date|range
$dry_run = ($_GET['dry_run'] ?? '1') !== '0';
$confirm = strtoupper(trim((string)($_GET['confirm'] ?? ($_POST['confirm'] ?? ''))));
$include_synced = ($_GET['include_synced'] ?? '0') === '1';

$where = "WHERE live_sales.username != '' AND live_sales.sale_date != ''";
$params = [];

if ($mode === 'all' || $date === 'all') {
    // no date filter
} elseif ($from !== '' && $to !== '') {
    $where .= " AND live_sales.sale_date >= :from AND live_sales.sale_date <= :to";
    $params[':from'] = $from;
    $params[':to'] = $to;
} else {
    if ($date === '') $date = date('Y-m-d');
    $where .= " AND live_sales.sale_date = :d";
    $params[':d'] = $date;
}

if (!$include_synced) {
    $where .= " AND (live_sales.sync_status = 'pending' OR live_sales.sync_status IS NULL OR live_sales.sync_status = '')";
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sqlCount = "SELECT COUNT(*)
        FROM live_sales
        WHERE EXISTS (
            SELECT 1 FROM sales_history sh
            WHERE sh.username = live_sales.username
              AND sh.sale_date = live_sales.sale_date
        )
        " . substr($where, 5);

    $stmt = $db->prepare($sqlCount);
    $stmt->execute($params);
    $count = (int)$stmt->fetchColumn();

    if ($dry_run || $confirm !== 'YES') {
        echo "DRY RUN: candidates={$count}\n";
        echo "Tambahkan confirm=YES&dry_run=0 untuk menghapus.\n";
        exit;
    }

    $sqlDelete = "DELETE FROM live_sales
        WHERE EXISTS (
            SELECT 1 FROM sales_history sh
            WHERE sh.username = live_sales.username
              AND sh.sale_date = live_sales.sale_date
        )
        " . substr($where, 5);

    $stmt = $db->prepare($sqlDelete);
    $stmt->execute($params);
    $deleted = $stmt->rowCount();

    echo "OK deleted_live_sales={$deleted}\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "Error\n";
}
