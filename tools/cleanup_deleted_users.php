<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: text/plain');

$root_dir = dirname(__DIR__);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
require_once($root_dir . '/include/db_helpers.php');
$secret_token = $env['security']['tools']['token'] ?? ($env['backup']['secret'] ?? '');
$key = $_GET['key'] ?? ($_POST['key'] ?? '');
if ($key === '' && isset($_SERVER['HTTP_X_WARTELPAS_KEY'])) {
    $key = $_SERVER['HTTP_X_WARTELPAS_KEY'];
}
$key = trim((string)$key);
$has_valid_key = ($key !== '' && $secret_token !== '' && hash_equals($secret_token, $key));

if (!$has_valid_key) {
    requireLogin('../admin.php?id=login');
    requireSuperAdmin('../admin.php?id=sessions');
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

$date = trim((string)($_GET['date'] ?? ''));
if ($date === '') {
    $date = date('Y-m-d');
}
$status = strtolower(trim((string)($_GET['status'] ?? 'rusak')));
if (!in_array($status, ['rusak', 'retur', 'invalid', 'all'], true)) {
    $status = 'rusak';
}
$profile = strtolower(trim((string)($_GET['profile'] ?? '')));
$users_param = trim((string)($_GET['users'] ?? ''));
$force = isset($_GET['force']) && $_GET['force'] === '1';
$run = isset($_GET['run']) && $_GET['run'] === '1';

$dbFile = get_stats_db_path();
if (!file_exists($dbFile)) {
    die("DB not found");
}

$log_dir = $root_dir . '/logs';
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}
$log_file = $log_dir . '/cleanup_deleted_users.log';
function cleanup_log($file, $message) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

function infer_profile_kind($validity, $raw_comment) {
    $src = strtolower(trim((string)$validity));
    if ($src !== '') {
        if (strpos($src, '30') !== false) return '30';
        if (strpos($src, '10') !== false) return '10';
    }
    $comment = strtolower((string)$raw_comment);
    if (preg_match('/\b(10|30)\s*(menit|m)\b/', $comment, $m)) {
        return $m[1];
    }
    if (preg_match('/\bblok\s*[-_]?[a-z0-9]+\s*(10|30)\b/', $comment, $m)) {
        return $m[1];
    }
    if (preg_match('/\bblok\s*[-_]?[a-z0-9]+(10|30)\b/', $comment, $m)) {
        return $m[1];
    }
    return '';
}

require_once($root_dir . '/lib/routeros_api.class.php');
$router_map = [];
$has_router_data = false;
if ($users_param === '') {
    $API = new RouterosAPI();
    $API->debug = false;
    $API->timeout = 5;
    $API->attempts = 1;

    if (!$API->connect($iphost, $userhost, decrypt($passwdhost))) {
        http_response_code(500);
        die("Error: Gagal konek ke MikroTik.");
    }
    $router_rows = $API->comm('/ip/hotspot/user/print', [
        '?server' => $hotspot_server,
        '.proplist' => 'name'
    ]);
    $API->disconnect();

    if (is_array($router_rows)) {
        foreach ($router_rows as $r) {
            $name = trim((string)($r['name'] ?? ''));
            if ($name !== '') {
                $router_map[strtolower($name)] = true;
                $has_router_data = true;
            }
        }
    }
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");
    $db->exec("PRAGMA busy_timeout=5000;");
} catch (Exception $e) {
    http_response_code(500);
    die("DB error");
}

$statusClause = "(instr(lower(COALESCE(NULLIF(last_status,''), '')), 'rusak') > 0 OR instr(lower(COALESCE(NULLIF(last_status,''), '')), 'retur') > 0 OR instr(lower(COALESCE(NULLIF(last_status,''), '')), 'invalid') > 0)";
if ($status !== 'all') {
    $statusClause = "instr(lower(COALESCE(NULLIF(last_status,''), '')), :st) > 0";
}

$dateClause = "(login_date = :d OR substr(first_login_real,1,10) = :d OR substr(last_login_real,1,10) = :d OR substr(login_time_real,1,10) = :d OR substr(logout_time_real,1,10) = :d OR substr(updated_at,1,10) = :d)";
$params = [':d' => $date];
if ($status !== 'all') {
    $params[':st'] = $status;
}

$stmt = $db->prepare("SELECT username, validity, raw_comment, last_status FROM login_history WHERE username != '' AND {$statusClause} AND {$dateClause}");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$targets = [];
$manual_list = [];
if ($users_param !== '') {
    $parts = preg_split('/[\s,]+/', $users_param);
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') $manual_list[strtolower($p)] = $p;
    }
}

if (empty($manual_list) && !$has_router_data && !$force) {
    http_response_code(400);
    echo "Error: Data router kosong. Gunakan param users=... untuk manual cleanup atau force=1.\n";
    exit;
}

foreach ($rows as $row) {
    $uname = trim((string)($row['username'] ?? ''));
    if ($uname === '') continue;

    if (!empty($manual_list)) {
        if (!isset($manual_list[strtolower($uname)])) continue;
    } else {
        if (isset($router_map[strtolower($uname)])) continue;
    }

    $kind = infer_profile_kind($row['validity'] ?? '', $row['raw_comment'] ?? '');
    if ($profile !== '' && $profile !== $kind) {
        continue;
    }
    $targets[$uname] = $kind;
}

if (empty($targets)) {
    echo "OK targets=0\n";
    exit;
}

if (!$run) {
    echo "PREVIEW targets=" . count($targets) . " date={$date} status={$status} profile={$profile}\n";
    foreach ($targets as $u => $k) {
        echo $u . "\t" . $k . "\n";
    }
    exit;
}

$deleted = 0;
try {
    $db->beginTransaction();
    foreach ($targets as $uname => $kind) {
        $tables = ['login_history', 'login_events', 'sales_history', 'live_sales'];
        foreach ($tables as $table) {
            $stmt = $db->prepare("DELETE FROM {$table} WHERE username = :u");
            $stmt->execute([':u' => $uname]);
        }
        $deleted++;
    }
    $db->commit();
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    cleanup_log($log_file, 'error=' . $e->getMessage());
    http_response_code(500);
    echo "Error\n";
    exit;
}

cleanup_log($log_file, 'ok deleted=' . $deleted . ' date=' . $date . ' status=' . $status . ' profile=' . $profile);
echo "OK deleted=" . $deleted . "\n";