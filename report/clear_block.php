<?php
// Clear a block from login_history (protected)
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
    die("Error: Token Salah.");
}

$session = $_GET['session'] ?? '';
if ($session === '') {
    http_response_code(403);
    die("Error: Session tidak valid.");
}

$root_dir = dirname(__DIR__);
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

$blok_upper = strtoupper($blok);
$use_glob = !preg_match('/\d$/', $blok_upper);
$glob_pattern = $use_glob ? ($blok_upper . '[0-9]*') : '';

$date = trim((string)($_GET['date'] ?? ''));
$dateClause = '';
$dateParams = [];
if ($date !== '') {
    $dateClause = ' AND sale_date = :d';
    $dateParams[':d'] = $date;
}

$dbFile = $root_dir . '/db_data/mikhmon_stats.db';
if (!file_exists($dbFile)) {
    die("DB not found");
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $whereBlok = "UPPER(blok_name) = :b" . ($use_glob ? " OR UPPER(blok_name) GLOB :bg" : "");

    $stmt = $db->prepare("DELETE FROM login_history WHERE " . $whereBlok);
    $stmt->execute($use_glob ? [':b' => $blok_upper, ':bg' => $glob_pattern] : [':b' => $blok_upper]);
    $deleted_login = $stmt->rowCount();

    $stmt = $db->prepare("DELETE FROM sales_history WHERE (" . $whereBlok . ")" . $dateClause);
    $stmt->execute(array_merge($use_glob ? [':b' => $blok_upper, ':bg' => $glob_pattern] : [':b' => $blok_upper], $dateParams));
    $deleted_sales = $stmt->rowCount();

    $stmt = $db->prepare("DELETE FROM live_sales WHERE (" . $whereBlok . ")" . $dateClause);
    $stmt->execute(array_merge($use_glob ? [':b' => $blok_upper, ':bg' => $glob_pattern] : [':b' => $blok_upper], $dateParams));
    $deleted_live = $stmt->rowCount();

    $stmt = $db->prepare("DELETE FROM phone_block_daily WHERE (" . $whereBlok . ")" . ($date !== '' ? " AND report_date = :d" : ""));
    $stmt->execute(array_merge($use_glob ? [':b' => $blok_upper, ':bg' => $glob_pattern] : [':b' => $blok_upper], $date !== '' ? [':d' => $date] : []));
    $deleted_hp = $stmt->rowCount();

    echo "OK login=" . $deleted_login . ", sales=" . $deleted_sales . ", live=" . $deleted_live . ", hp=" . $deleted_hp;
} catch (Exception $e) {
    http_response_code(500);
    echo "Error";
}
