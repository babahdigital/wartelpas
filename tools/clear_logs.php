<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
// Clear server log files (protected)
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
$key = $_GET['key'] ?? '';
if ($key === '' && isset($_POST['key'])) {
    $key = (string)$_POST['key'];
}
if ($key === '' && isset($_SERVER['HTTP_X_TOOLS_KEY'])) {
    $key = (string)$_SERVER['HTTP_X_TOOLS_KEY'];
}
if ($key === '' && isset($_SERVER['HTTP_X_BACKUP_KEY'])) {
    $key = (string)$_SERVER['HTTP_X_BACKUP_KEY'];
}
$key = trim((string)$key);
if ($key === '' && isset($_SERVER['HTTP_X_WARTELPAS_KEY'])) {
    $key = trim((string)$_SERVER['HTTP_X_WARTELPAS_KEY']);
}
$is_valid_key = $secret_token !== '' && hash_equals($secret_token, (string)$key);

if (!$is_valid_key) {
    requireLogin('../admin.php?id=login');
    requireSuperAdmin('../admin.php?id=sessions');
    $is_valid_key = true;
} else {
    if (!isset($_SESSION['mikhmon'])) {
        $_SESSION['mikhmon'] = 'tools';
        $_SESSION['mikhmon_level'] = 'superadmin';
    }
}

if (!$is_valid_key) {
    http_response_code(403);
    die("Error: Token Salah.");
}

$scope = strtolower(trim($_GET['scope'] ?? 'basic'));
$purgeSettlement = isset($_GET['purge']) && $_GET['purge'] === '1';
$keepLatestSettlement = isset($_GET['keep_latest']) && $_GET['keep_latest'] === '1';
$maxMb = isset($_GET['max_mb']) ? (int)$_GET['max_mb'] : 0;

$logDir = $root_dir . '/logs';
if (!is_dir($logDir)) {
    echo "No logs dir";
    exit;
}

$targets = [];
$deleteTargets = [];
$targets[] = $logDir . '/usage_ingest.log';
$targets[] = $logDir . '/live_ingest.log';

if ($scope === 'all') {
    $extra = glob($logDir . '/*.log') ?: [];
    foreach ($extra as $file) {
        $targets[] = $file;
    }
    $archiveDir = $logDir . '/settlement_archive';
    if (is_dir($archiveDir)) {
        $archived = glob($archiveDir . '/*.log') ?: [];
        foreach ($archived as $file) {
            $targets[] = $file;
        }
    }
}

if ($purgeSettlement) {
    $settlementLogs = glob($logDir . '/settlement_*.log') ?: [];
    if ($keepLatestSettlement && !empty($settlementLogs)) {
        $latest = '';
        $latestTime = 0;
        foreach ($settlementLogs as $file) {
            if (!is_file($file)) continue;
            $mtime = @filemtime($file) ?: 0;
            if ($mtime >= $latestTime) {
                $latestTime = $mtime;
                $latest = $file;
            }
        }
        if ($latest !== '') {
            $settlementLogs = array_values(array_filter($settlementLogs, function($f) use ($latest) {
                return $f !== $latest;
            }));
        }
    }
    foreach ($settlementLogs as $file) {
        $deleteTargets[] = $file;
    }
}

$targets = array_values(array_unique($targets));
$deleteTargets = array_values(array_unique($deleteTargets));

$debugTargets = [];
if ($scope === 'all') {
    $debugTargets = glob($logDir . '/*debug*.log') ?: [];
    $archiveDir = $logDir . '/settlement_archive';
    if (is_dir($archiveDir)) {
        $debugTargets = array_merge($debugTargets, glob($archiveDir . '/*debug*.log') ?: []);
    }
    $debugTargets = array_values(array_unique($debugTargets));
}

function truncate_file($file) {
    $fp = @fopen($file, 'c+');
    if (!$fp) return false;
    $ok = @ftruncate($fp, 0);
    @fclose($fp);
    return $ok;
}

$cleared = 0;
$skipped = 0;
$errors = 0;
$deleted = 0;
foreach ($targets as $file) {
    if (!file_exists($file) || is_dir($file)) {
        $skipped++;
        continue;
    }
    if ($maxMb > 0) {
        $size = @filesize($file);
        if ($size !== false && $size < ($maxMb * 1024 * 1024)) {
            $skipped++;
            continue;
        }
    }
    if (truncate_file($file)) {
        $cleared++;
    } else {
        $errors++;
    }
}

foreach ($deleteTargets as $file) {
    if (!file_exists($file) || is_dir($file)) {
        $skipped++;
        continue;
    }
    if ($maxMb > 0) {
        $size = @filesize($file);
        if ($size !== false && $size < ($maxMb * 1024 * 1024)) {
            $skipped++;
            continue;
        }
    }
    if (@unlink($file)) {
        $deleted++;
    } else {
        $errors++;
    }
}

foreach ($debugTargets as $file) {
    if (!file_exists($file) || is_dir($file)) {
        continue;
    }
    if (@unlink($file)) {
        $deleted++;
    } else {
        $errors++;
    }
}

echo "OK cleared=" . $cleared . " skipped=" . $skipped . " deleted=" . $deleted . " errors=" . $errors . " scope=" . $scope . " purge_settlement=" . ($purgeSettlement ? '1' : '0') . " keep_latest=" . ($keepLatestSettlement ? '1' : '0');