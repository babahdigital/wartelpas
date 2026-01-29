<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
requireLogin('../admin.php?id=login');
requireSuperAdmin('../admin.php?id=sessions');
// tools/clear_block_users.php
// Hapus user berdasarkan blok (DB + optional MikroTik)
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

$confirm = strtoupper(trim((string)($_GET['confirm'] ?? ($_POST['confirm'] ?? ''))));
if ($confirm !== 'YES') {
    http_response_code(400);
    die("Error: Tambahkan confirm=YES untuk menjalankan hapus.");
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

// Keamanan: hanya blok A (prefix BLOK-A)
if (strpos($blok_upper, 'BLOK-A') !== 0) {
    http_response_code(400);
    die("Error: Hanya diizinkan untuk BLOK-A.");
}

$use_prefix = ($blok_upper === 'BLOK-A');
$glob_pattern = $use_prefix ? 'BLOK-A*' : '';

$dbFile = $root_dir . '/db_data/mikhmon_stats.db';
if (!file_exists($dbFile)) {
    http_response_code(500);
    die("DB not found");
}

$deleted = [
    'login_history' => 0,
    'login_events' => 0,
    'sales_history' => 0,
    'live_sales' => 0,
    'mikrotik' => 0
];

$users = [];

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $whereBlok = "UPPER(blok_name) = :b" . ($use_prefix ? " OR UPPER(blok_name) GLOB :bg" : "");

    // Kumpulkan username dari blok+tanggal
    $params = $use_prefix ? [':b' => $blok_upper, ':bg' => $glob_pattern, ':d' => $target_date] : [':b' => $blok_upper, ':d' => $target_date];

    $stmt = $db->prepare("SELECT DISTINCT username FROM sales_history WHERE (" . $whereBlok . ") AND sale_date = :d AND username != ''");
    $stmt->execute($params);
    $users = array_merge($users, $stmt->fetchAll(PDO::FETCH_COLUMN));

    $stmt = $db->prepare("SELECT DISTINCT username FROM live_sales WHERE (" . $whereBlok . ") AND sale_date = :d AND username != ''");
    $stmt->execute($params);
    $users = array_merge($users, $stmt->fetchAll(PDO::FETCH_COLUMN));

    $stmt = $db->prepare("SELECT DISTINCT username FROM login_history WHERE (" . $whereBlok . ") AND (
        login_date = :d
        OR substr(first_login_real,1,10) = :d
        OR substr(last_login_real,1,10) = :d
        OR substr(login_time_real,1,10) = :d
        OR substr(logout_time_real,1,10) = :d
        OR substr(updated_at,1,10) = :d
    ) AND username != ''");
    $stmt->execute($params);
    $users = array_merge($users, $stmt->fetchAll(PDO::FETCH_COLUMN));

    $users = array_values(array_unique(array_filter($users)));

    // Hapus data DB untuk blok+tanggal
    $stmt = $db->prepare("DELETE FROM sales_history WHERE (" . $whereBlok . ") AND sale_date = :d");
    $stmt->execute($params);
    $deleted['sales_history'] = $stmt->rowCount();

    $stmt = $db->prepare("DELETE FROM live_sales WHERE (" . $whereBlok . ") AND sale_date = :d");
    $stmt->execute($params);
    $deleted['live_sales'] = $stmt->rowCount();

    $stmt = $db->prepare("DELETE FROM login_history WHERE (" . $whereBlok . ") AND (
        login_date = :d
        OR substr(first_login_real,1,10) = :d
        OR substr(last_login_real,1,10) = :d
        OR substr(login_time_real,1,10) = :d
        OR substr(logout_time_real,1,10) = :d
        OR substr(updated_at,1,10) = :d
    )");
    $stmt->execute($params);
    $deleted['login_history'] = $stmt->rowCount();

    if (!empty($users)) {
        $in = implode(',', array_fill(0, count($users), '?'));
        $stmt = $db->prepare("DELETE FROM login_events WHERE date_key = ? AND username IN ($in)");
        $stmt->execute(array_merge([$target_date], $users));
        $deleted['login_events'] = $stmt->rowCount();
    }

} catch (Exception $e) {
    http_response_code(500);
    die("Error");
}

// Optional: hapus di MikroTik
$do_mikrotik = isset($_GET['mikrotik']) && $_GET['mikrotik'] === '1';
if ($do_mikrotik) {
    require_once($root_dir . '/lib/routeros_api.class.php');
    $API = new RouterosAPI();
    $API->debug = false;

    if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
        $target_comment = $use_prefix ? 'Blok-A' : str_replace('BLOK-', 'Blok-', $blok_upper);
        $all_users = $API->comm("/ip/hotspot/user/print", ["?server" => "wartel"]);
        foreach ($all_users as $u) {
            $uid = $u['.id'] ?? '';
            $ucomment = (string)($u['comment'] ?? '');
            if ($uid !== '' && $target_comment !== '' && stripos($ucomment, $target_comment) !== false) {
                $API->comm("/ip/hotspot/user/remove", [".id" => $uid]);
                $deleted['mikrotik']++;
            }
        }
        $API->disconnect();
    }
}

echo "OK date={$target_date}, blok={$blok_upper}, sales={$deleted['sales_history']}, live={$deleted['live_sales']}, login={$deleted['login_history']}, events={$deleted['login_events']}, mikrotik=" . ($do_mikrotik ? $deleted['mikrotik'] : 'skipped');
