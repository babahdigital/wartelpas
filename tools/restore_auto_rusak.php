<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
requireLogin('../admin.php?id=login');
requireSuperAdmin('../admin.php?id=sessions');
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

$date = trim((string)($_GET['date'] ?? ''));
if ($date === '') {
    $date = date('Y-m-d');
}
$blok = trim((string)($_GET['blok'] ?? ''));
$blok_upper = strtoupper($blok);
$use_glob = $blok !== '' && !preg_match('/\d$/', $blok_upper);
$glob_pattern = $use_glob ? ($blok_upper . '[0-9]*') : '';

$dbFile = $root_dir . '/db_data/mikhmon_stats.db';
if (!file_exists($dbFile)) {
    die("DB not found");
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");
    $db->exec("PRAGMA busy_timeout=5000;");

    $dateClause = " AND (login_date = :d OR substr(first_login_real,1,10) = :d OR substr(last_login_real,1,10) = :d OR substr(login_time_real,1,10) = :d OR substr(logout_time_real,1,10) = :d OR substr(updated_at,1,10) = :d)";
    $params = [':d' => $date];
    $whereBlok = '';
    if ($blok !== '') {
        $whereBlok = " AND (UPPER(blok_name) = :b" . ($use_glob ? " OR UPPER(blok_name) GLOB :bg" : "") . ")";
        if ($use_glob) {
            $params[':b'] = $blok_upper;
            $params[':bg'] = $glob_pattern;
        } else {
            $params[':b'] = $blok_upper;
        }
    }

    $stmt = $db->prepare("SELECT username FROM login_history WHERE auto_rusak = 1 AND last_status = 'rusak'" . $dateClause . $whereBlok);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    if (empty($users)) {
        echo "OK restore=0 (tidak ada user auto rusak)";
        exit;
    }

    $stmtU = $db->prepare("UPDATE login_history SET last_status='terpakai', auto_rusak=0, updated_at=CURRENT_TIMESTAMP WHERE username = :u");
    $updated = 0;
    foreach ($users as $uname) {
        if ($uname === '') continue;
        try {
            $stmtU->execute([':u' => $uname]);
            $updated++;
        } catch (Exception $e) {}
    }

    $chunks = array_chunk($users, 200);
    foreach ($chunks as $chunk) {
        $placeholders = [];
        $p = [':d' => $date];
        foreach ($chunk as $i => $uname) {
            $ph = ':u' . $i;
            $placeholders[] = $ph;
            $p[$ph] = $uname;
        }
        $in = implode(',', $placeholders);
        try {
            $stmtS = $db->prepare("UPDATE sales_history SET status='terpakai', is_rusak=0, is_retur=0, is_invalid=0 WHERE username IN ($in) AND sale_date = :d");
            $stmtS->execute($p);
        } catch (Exception $e) {}
        try {
            $stmtL = $db->prepare("UPDATE live_sales SET status='terpakai', is_rusak=0, is_retur=0, is_invalid=0 WHERE username IN ($in) AND sale_date = :d");
            $stmtL->execute($p);
        } catch (Exception $e) {}
    }

    echo "OK restore=" . $updated;
} catch (Exception $e) {
    http_response_code(500);
    echo "Error";
}
