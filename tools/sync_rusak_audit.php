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
    die("Error: Session tidak valid.\n");
}

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

$start = trim((string)($_GET['start'] ?? ''));
$end = trim((string)($_GET['end'] ?? ''));
$date = trim((string)($_GET['date'] ?? ''));
$status = strtolower(trim((string)($_GET['status'] ?? 'all')));
$run = isset($_GET['run']) && $_GET['run'] === '1';

if ($date !== '') {
    $start = $date;
    $end = $date;
}

if ($start === '' || $end === '') {
    $start = date('Y-m-d');
    $end = $start;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    http_response_code(400);
    die("Error: Format tanggal tidak valid.\n");
}

if (!in_array($status, ['all', 'rusak', 'invalid'], true)) {
    $status = 'all';
}

$dbFile = get_stats_db_path();
if (!file_exists($dbFile)) {
    die("DB not found\n");
}

function build_raw_date_patterns($date) {
    $ts = strtotime($date);
    if (!$ts) return [$date . '%'];
    $ymd = date('Y-m-d', $ts);
    $mdy = date('m/d/Y', $ts);
    $dmy = date('d/m/Y', $ts);
    $mdy_short = date('m/d/y', $ts);
    $dmy_short = date('d/m/y', $ts);
    $mon = date('M/d/Y', $ts);
    return [
        $ymd . '%',
        $mdy . '%',
        $dmy . '%',
        $mdy_short . '%',
        $dmy_short . '%',
        $mon . '%'
    ];
}

function detect_status_from_row($row, $status_filter) {
    $ls = strtolower(trim((string)($row['last_status'] ?? '')));
    $cm = strtolower((string)($row['raw_comment'] ?? ''));
    $is_invalid = (strpos($ls, 'invalid') !== false) || (strpos($cm, 'invalid') !== false);
    $is_rusak = (strpos($ls, 'rusak') !== false) || (strpos($cm, 'rusak') !== false);

    if ($status_filter === 'invalid') return $is_invalid ? 'invalid' : '';
    if ($status_filter === 'rusak') return $is_rusak ? 'rusak' : '';

    if ($is_invalid) return 'invalid';
    if ($is_rusak) return 'rusak';
    return '';
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");
    $db->exec("PRAGMA busy_timeout=5000;");
} catch (Exception $e) {
    http_response_code(500);
    die("DB error\n");
}

$start_ts = strtotime($start);
$end_ts = strtotime($end);
if ($start_ts === false || $end_ts === false || $start_ts > $end_ts) {
    http_response_code(400);
    die("Error: Rentang tanggal tidak valid.\n");
}

$total_targets = 0;
$total_updated_sales = 0;
$total_updated_live = 0;
$log_lines = [];

for ($ts = $start_ts; $ts <= $end_ts; $ts += 86400) {
    $d = date('Y-m-d', $ts);
    $raw_patterns = build_raw_date_patterns($d);

    $dateClause = "(login_date = :d OR substr(first_login_real,1,10) = :d OR substr(last_login_real,1,10) = :d OR substr(login_time_real,1,10) = :d OR substr(logout_time_real,1,10) = :d OR substr(updated_at,1,10) = :d";
    $dateClause .= " OR raw_comment LIKE :raw1 OR raw_comment LIKE :raw2 OR raw_comment LIKE :raw3 OR raw_comment LIKE :raw4 OR raw_comment LIKE :raw5 OR raw_comment LIKE :raw6)";

    $statusClause = "(instr(lower(COALESCE(NULLIF(last_status,''), '')), 'rusak') > 0 OR instr(lower(COALESCE(NULLIF(last_status,''), '')), 'invalid') > 0 OR instr(lower(COALESCE(NULLIF(raw_comment,''), '')), 'rusak') > 0 OR instr(lower(COALESCE(NULLIF(raw_comment,''), '')), 'invalid') > 0)";
    if ($status === 'rusak') {
        $statusClause = "(instr(lower(COALESCE(NULLIF(last_status,''), '')), 'rusak') > 0 OR instr(lower(COALESCE(NULLIF(raw_comment,''), '')), 'rusak') > 0)";
    } elseif ($status === 'invalid') {
        $statusClause = "(instr(lower(COALESCE(NULLIF(last_status,''), '')), 'invalid') > 0 OR instr(lower(COALESCE(NULLIF(raw_comment,''), '')), 'invalid') > 0)";
    }

    $stmt = $db->prepare("SELECT username, last_status, raw_comment FROM login_history WHERE username != '' AND {$statusClause} AND {$dateClause}");
    $stmt->bindValue(':d', $d);
    $stmt->bindValue(':raw1', $raw_patterns[0]);
    $stmt->bindValue(':raw2', $raw_patterns[1]);
    $stmt->bindValue(':raw3', $raw_patterns[2]);
    $stmt->bindValue(':raw4', $raw_patterns[3]);
    $stmt->bindValue(':raw5', $raw_patterns[4]);
    $stmt->bindValue(':raw6', $raw_patterns[5]);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        $log_lines[] = $d . " targets=0";
        continue;
    }

    $targets = [];
    foreach ($rows as $row) {
        $uname = trim((string)($row['username'] ?? ''));
        if ($uname === '') continue;
        $st = detect_status_from_row($row, $status);
        if ($st === '') continue;
        $targets[$uname] = $st;
    }

    $total_targets += count($targets);
    if (empty($targets)) {
        $log_lines[] = $d . " targets=0";
        continue;
    }

    if (!$run) {
        $log_lines[] = $d . " targets=" . count($targets) . " (preview)";
        continue;
    }

    $updated_sales = 0;
    $updated_live = 0;

    $saleWhere = "(sale_date = :d OR raw_date LIKE :raw1 OR raw_date LIKE :raw2 OR raw_date LIKE :raw3 OR raw_date LIKE :raw4 OR raw_date LIKE :raw5 OR raw_date LIKE :raw6)";

    $stmtSalesRusak = $db->prepare("UPDATE sales_history SET status='rusak', is_rusak=1, is_invalid=0, is_retur=0 WHERE username = :u AND {$saleWhere}");
    $stmtSalesInvalid = $db->prepare("UPDATE sales_history SET status='invalid', is_invalid=1, is_rusak=0, is_retur=0 WHERE username = :u AND {$saleWhere}");
    $stmtLiveRusak = $db->prepare("UPDATE live_sales SET status='rusak', is_rusak=1, is_invalid=0, is_retur=0 WHERE username = :u AND {$saleWhere}");
    $stmtLiveInvalid = $db->prepare("UPDATE live_sales SET status='invalid', is_invalid=1, is_rusak=0, is_retur=0 WHERE username = :u AND {$saleWhere}");

    foreach ($targets as $uname => $st) {
        $bind = [
            ':u' => $uname,
            ':d' => $d,
            ':raw1' => $raw_patterns[0],
            ':raw2' => $raw_patterns[1],
            ':raw3' => $raw_patterns[2],
            ':raw4' => $raw_patterns[3],
            ':raw5' => $raw_patterns[4],
            ':raw6' => $raw_patterns[5]
        ];
        if ($st === 'invalid') {
            $stmtSalesInvalid->execute($bind);
            $updated_sales += $stmtSalesInvalid->rowCount();
            $stmtLiveInvalid->execute($bind);
            $updated_live += $stmtLiveInvalid->rowCount();
        } else {
            $stmtSalesRusak->execute($bind);
            $updated_sales += $stmtSalesRusak->rowCount();
            $stmtLiveRusak->execute($bind);
            $updated_live += $stmtLiveRusak->rowCount();
        }
    }

    $total_updated_sales += $updated_sales;
    $total_updated_live += $updated_live;
    $log_lines[] = $d . " targets=" . count($targets) . " updated_sales=" . $updated_sales . " updated_live=" . $updated_live;
}

echo "OK run=" . ($run ? '1' : '0') . " status=" . $status . " start=" . $start . " end=" . $end . "\n";
foreach ($log_lines as $line) {
    echo $line . "\n";
}
if ($run) {
    echo "TOTAL targets=" . $total_targets . " updated_sales=" . $total_updated_sales . " updated_live=" . $total_updated_live . "\n";
}
