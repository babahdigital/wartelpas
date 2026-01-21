<?php
// Cleanup READY-only entries in login_history (protected)
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

$dbFile = $root_dir . '/db_data/mikhmon_stats.db';
if (!file_exists($dbFile)) {
    die("DB not found");
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "DELETE FROM login_history
        WHERE (last_status IS NULL OR LOWER(last_status) = 'ready' OR TRIM(last_status) = '')
          AND (last_bytes IS NULL OR last_bytes = 0)
          AND (last_uptime IS NULL OR last_uptime = '' OR last_uptime = '0s')
          AND (login_time_real IS NULL OR login_time_real = '')
          AND (logout_time_real IS NULL OR logout_time_real = '')
          AND (raw_comment IS NULL OR raw_comment = '' OR raw_comment NOT LIKE '%blok%')";

    $deleted = $db->exec($sql);
    echo "OK deleted=" . (int)$deleted;
} catch (Exception $e) {
    http_response_code(500);
    echo "Error";
}
