<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
requireLogin('../admin.php?id=login');
requireSuperAdmin('../admin.php?id=sessions');
// Check block existence across tables (protected)
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

$root_dir = dirname(__DIR__);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
require_once $root_dir . '/include/db_helpers.php';
$secret_token = $env['security']['tools']['token'] ?? ($env['backup']['secret'] ?? '');
$key = $_GET['key'] ?? ($_POST['key'] ?? '');
if ($key === '' && isset($_SERVER['HTTP_X_WARTELPAS_KEY'])) {
    $key = $_SERVER['HTTP_X_WARTELPAS_KEY'];
}
$key = trim((string)$key);
if ($key === '' || !hash_equals($secret_token, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Token Salah.']);
    exit;
}

$session = $_GET['session'] ?? '';
if ($session === '') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Session tidak valid.']);
    exit;
}

require_once($root_dir . '/include/config.php');
if (!isset($data[$session])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Session tidak terdaftar.']);
    exit;
}
require_once($root_dir . '/include/readcfg.php');
if (!isset($hotspot_server) || $hotspot_server !== 'wartel') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Hanya untuk server wartel.']);
    exit;
}

$blok = trim((string)($_GET['blok'] ?? ''));
if ($blok === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Blok kosong.']);
    exit;
}

$blok_upper = strtoupper($blok);
$dbFile = get_stats_db_path();
if (!file_exists($dbFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'DB not found.']);
    exit;
}
$use_glob = !preg_match('/\d$/', $blok_upper);
$glob_pattern = $use_glob ? ($blok_upper . '[0-9]*') : '';

$date = trim((string)($_GET['date'] ?? ''));

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $counts = [
        'login_history' => 0,
        'sales_history' => 0,
        'live_sales' => 0,
        'phone_block_daily' => 0
    ];

    $whereBlok = "UPPER(blok_name) = :b" . ($use_glob ? " OR UPPER(blok_name) GLOB :bg" : "");

    $stmt = $db->prepare("SELECT COUNT(*) FROM login_history WHERE " . $whereBlok);
    $stmt->execute($use_glob ? [':b' => $blok_upper, ':bg' => $glob_pattern] : [':b' => $blok_upper]);
    $counts['login_history'] = (int)$stmt->fetchColumn();

    $dateClause = $date !== '' ? ' AND sale_date = :d' : '';
    $params = [':b' => $blok];
    if ($date !== '') $params[':d'] = $date;

    $stmt = $db->prepare("SELECT COUNT(*) FROM sales_history WHERE (" . $whereBlok . ")" . $dateClause);
    $stmt->execute(array_merge($use_glob ? [':b' => $blok_upper, ':bg' => $glob_pattern] : [':b' => $blok_upper], $date !== '' ? [':d' => $date] : []));
    $counts['sales_history'] = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM live_sales WHERE (" . $whereBlok . ")" . $dateClause);
    $stmt->execute(array_merge($use_glob ? [':b' => $blok_upper, ':bg' => $glob_pattern] : [':b' => $blok_upper], $date !== '' ? [':d' => $date] : []));
    $counts['live_sales'] = (int)$stmt->fetchColumn();

    $dateClauseHp = $date !== '' ? ' AND report_date = :d' : '';
    $paramsHp = [':b' => $blok];
    if ($date !== '') $paramsHp[':d'] = $date;
    $stmt = $db->prepare("SELECT COUNT(*) FROM phone_block_daily WHERE (" . $whereBlok . ")" . $dateClauseHp);
    $stmt->execute(array_merge($use_glob ? [':b' => $blok_upper, ':bg' => $glob_pattern] : [':b' => $blok_upper], $date !== '' ? [':d' => $date] : []));
    $counts['phone_block_daily'] = (int)$stmt->fetchColumn();

    echo json_encode(['ok' => true, 'blok' => $blok, 'date' => $date, 'counts' => $counts]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'DB error']);
}