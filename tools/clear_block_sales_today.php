<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
requireLogin('../admin.php?id=login');
requireSuperAdmin('../admin.php?id=sessions');
// tools/clear_block_sales_today.php
// Hapus data penjualan (sales_history + live_sales) untuk 1 blok di tanggal tertentu (aman)
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
$key = $_GET['key'] ?? ($_POST['key'] ?? '');
if ($key === '' && isset($_SERVER['HTTP_X_WARTELPAS_KEY'])) {
    $key = $_SERVER['HTTP_X_WARTELPAS_KEY'];
}
$key = trim((string)$key);
if ($key === '' || !hash_equals($secret_token, $key)) {
    http_response_code(403);
    die("Error: Token Salah.");
}

$session = $_GET['session'] ?? '';
if ($session === '') {
    http_response_code(403);
    die("Error: Session tidak valid.");
}

require_once($root_dir . '/include/config.php');
if (!isset($data[$session])) {
    http_response_code(403);
    die("Error: Session tidak terdaftar.");
}
require_once($root_dir . '/include/readcfg.php');
if (!isset($hotspot_server) || $hotspot_server !== 'wartel') {
    http_response_code(403);
    die("Error: Hanya untuk server wartel.");
}

$blok = trim((string)($_GET['blok'] ?? ''));
if ($blok === '') {
    http_response_code(400);
    die("Error: Blok kosong.");
}

$target_date = trim((string)($_GET['date'] ?? ''));
if ($target_date === '') {
    $target_date = date('Y-m-d');
}

$blok_upper = strtoupper($blok);
if (strpos($blok_upper, 'BLOK-') !== 0) {
    $blok_upper = 'BLOK-' . $blok_upper;
}
$use_glob = !preg_match('/\d$/', $blok_upper);
$glob_pattern = $use_glob ? ($blok_upper . '[0-9]*') : '';

$dbFile = $root_dir . '/db_data/mikhmon_stats.db';
if (!file_exists($dbFile)) {
    http_response_code(500);
    die("DB not found");
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $whereBlok = "UPPER(blok_name) = :b" . ($use_glob ? " OR UPPER(blok_name) GLOB :bg" : "");

    $stmt = $db->prepare("DELETE FROM sales_history WHERE (" . $whereBlok . ") AND sale_date = :d");
    $stmt->execute(array_merge($use_glob ? [':b' => $blok_upper, ':bg' => $glob_pattern] : [':b' => $blok_upper], [':d' => $target_date]));
    $deleted_sales = $stmt->rowCount();

    $stmt = $db->prepare("DELETE FROM live_sales WHERE (" . $whereBlok . ") AND sale_date = :d");
    $stmt->execute(array_merge($use_glob ? [':b' => $blok_upper, ':bg' => $glob_pattern] : [':b' => $blok_upper], [':d' => $target_date]));
    $deleted_live = $stmt->rowCount();

    echo "OK date={$target_date}, blok={$blok_upper}, sales={$deleted_sales}, live={$deleted_live}";
} catch (Exception $e) {
    http_response_code(500);
    echo "Error";
}
