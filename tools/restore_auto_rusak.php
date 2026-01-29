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
$force = isset($_GET['force']) && $_GET['force'] === '1';
$blok = trim((string)($_GET['blok'] ?? ''));
$blok_upper = strtoupper($blok);
$use_glob = $blok !== '' && !preg_match('/\d$/', $blok_upper);
$glob_pattern = $use_glob ? ($blok_upper . '[0-9]*') : '';

$dbFile = $root_dir . '/db_data/mikhmon_stats.db';
if (!file_exists($dbFile)) {
    die("DB not found");
}

$log_dir = $root_dir . '/logs';
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}
$log_file = $log_dir . '/restore_auto_rusak.log';
function restore_log($file, $message) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

function uptime_to_seconds_restore($uptime) {
    if (empty($uptime) || $uptime === '0s') return 0;
    $total = 0;
    if (preg_match_all('/(\d+)(w|d|h|m|s)/i', $uptime, $m, PREG_SET_ORDER)) {
        foreach ($m as $part) {
            $val = (int)$part[1];
            switch (strtolower($part[2])) {
                case 'w': $total += $val * 7 * 24 * 3600; break;
                case 'd': $total += $val * 24 * 3600; break;
                case 'h': $total += $val * 3600; break;
                case 'm': $total += $val * 60; break;
                case 's': $total += $val; break;
            }
        }
    }
    return $total;
}

function detect_profile_minutes_restore($validity, $raw_comment) {
    $src = strtolower(trim((string)$validity));
    $cmt = strtolower(trim((string)$raw_comment));
    $val = 0;
    if (preg_match('/\b(\d{1,2})\s*(menit|m)\b/', $src, $m)) {
        $val = (int)$m[1];
    } elseif (preg_match('/\b(10|30)\b/', $src, $m)) {
        $val = (int)$m[1];
    } elseif (preg_match('/\b(10|30)\s*(menit|m)\b/', $cmt, $m)) {
        $val = (int)$m[1];
    } elseif (preg_match('/\bblok[-\s]?[a-z]+(10|30)\b/i', $raw_comment, $m)) {
        $val = (int)$m[1];
    }
    if (!in_array($val, [10, 30], true)) return 0;
    return $val;
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");
    $db->exec("PRAGMA busy_timeout=5000;");
    try { $db->exec("ALTER TABLE login_history ADD COLUMN auto_rusak INTEGER DEFAULT 0"); } catch (Exception $e) {}

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

    $stmt = $db->prepare("SELECT username, last_uptime, last_bytes, validity, raw_comment FROM login_history WHERE auto_rusak = 1 AND last_status = 'rusak'" . $dateClause . $whereBlok);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $users = [];
    foreach ($rows as $row) {
        if (!empty($row['username'])) {
            $users[] = $row['username'];
        }
    }

    if ((empty($users) && $force) || empty($users)) {
        $stmt = $db->prepare("SELECT username, last_uptime, last_bytes, validity, raw_comment FROM login_history WHERE last_status = 'rusak'" . $dateClause . $whereBlok);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $users = [];
        foreach ($rows as $row) {
            $uname = trim((string)($row['username'] ?? ''));
            if ($uname === '') continue;
            $profile_minutes = detect_profile_minutes_restore($row['validity'] ?? '', $row['raw_comment'] ?? '');
            if ($profile_minutes <= 0) continue;
            $uptime = (string)($row['last_uptime'] ?? '');
            $uptime_sec = uptime_to_seconds_restore($uptime);
            $bytes_raw = (int)($row['last_bytes'] ?? 0);
            $bytes = $bytes_raw;
            if ($bytes > 0 && $bytes < 1024 * 1024 && $bytes <= 1024) {
                $bytes = $bytes * 1024 * 1024;
            }
            $bytes_threshold_short = 3 * 1024 * 1024;
            $short_uptime_limit = 5 * 60;
            $bytes_threshold_full = ($profile_minutes === 10) ? (3 * 1024 * 1024) : (5 * 1024 * 1024);
            $is_full_uptime = $uptime_sec >= ($profile_minutes * 60);
            $is_short_use = ($uptime_sec > 0 && $uptime_sec <= $short_uptime_limit);
            if (($is_full_uptime && $bytes < $bytes_threshold_full) || ($is_short_use && $bytes < $bytes_threshold_short)) {
                $users[] = $uname;
            }
        }
    }

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

    restore_log($log_file, 'ok restore=' . $updated . ' date=' . $date . ' blok=' . $blok);
    echo "OK restore=" . $updated;
} catch (Exception $e) {
    http_response_code(500);
    restore_log($log_file, 'error=' . $e->getMessage());
    echo "Error";
}
