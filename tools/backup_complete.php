<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
require_once __DIR__ . '/../include/db.php';

// Combined backup for Main DB + App DB (manual scheduler)
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: text/plain; charset=utf-8');
ignore_user_abort(true);

function respond_full($ok, $message, $code = 200) {
    http_response_code($code);
    echo $message;
    exit;
}

$envFile = dirname(__DIR__) . '/include/env.php';
if (is_file($envFile)) {
    require_once $envFile;
}
$secret = isset($env['backup']['secret']) ? (string)$env['backup']['secret'] : '';
$key = $_GET['key'] ?? '';
if ($key === '' && isset($_POST['key'])) {
    $key = (string)$_POST['key'];
}
if ($key === '' && isset($_SERVER['HTTP_X_BACKUP_KEY'])) {
    $key = (string)$_SERVER['HTTP_X_BACKUP_KEY'];
}
if ($key === '' && isset($_SERVER['HTTP_X_TOOLS_KEY'])) {
    $key = (string)$_SERVER['HTTP_X_TOOLS_KEY'];
}
$is_valid_key = $secret !== '' && hash_equals($secret, (string)$key);

$allow_session = isset($_SESSION['mikhmon']) && (isSuperAdmin() || (isOperator() && operator_can('backup_only')));
if (!$is_valid_key && !$allow_session) {
    requireLogin('../admin.php?id=login');
    requireSuperAdmin('../admin.php?id=sessions');
}
if ($is_valid_key && !isset($_SESSION['mikhmon'])) {
    $_SESSION['mikhmon'] = 'tools';
    $_SESSION['mikhmon_level'] = 'superadmin';
}
if (!$is_valid_key && !$allow_session) {
    respond_full(false, "Forbidden\n", 403);
}

$allowedIpList = isset($env['backup']['allowed_ips']) && is_array($env['backup']['allowed_ips'])
    ? $env['backup']['allowed_ips']
    : ['127.0.0.1', '::1', '10.10.83.1', '172.19.0.1'];
if (!$allow_session && !empty($_SERVER['REMOTE_ADDR']) && !empty($allowedIpList)) {
    $clientIp = (string)$_SERVER['REMOTE_ADDR'];
    if (!in_array($clientIp, $allowedIpList, true)) {
        respond_full(false, "IP not allowed\n", 403);
    }
}

// Rate limit
$clientIp = !empty($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
$rateKey = $clientIp . '|' . (string)$key . '|full';
$rateFile = sys_get_temp_dir() . '/backup_full.rate.' . md5($rateKey);
$rateWindow = isset($env['backup']['rate_window']) ? (int)$env['backup']['rate_window'] : 300;
$rateLimit = isset($env['backup']['rate_limit']) ? (int)$env['backup']['rate_limit'] : 1;
$rateEnabled = ($rateWindow > 0 && $rateLimit > 0);
$forceRate = isset($_GET['force']) && $_GET['force'] === '1';
$now = time();
$hits = [];
if (is_file($rateFile)) {
    $raw = @file_get_contents($rateFile);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $hits = $decoded;
    }
}
$hits = array_values(array_filter($hits, function($t) use ($now, $rateWindow) {
    return is_int($t) && ($now - $t) <= $rateWindow;
}));
if ($rateEnabled && !$forceRate && count($hits) >= $rateLimit) {
    respond_full(false, "Rate limited\n", 429);
}
if ($rateEnabled) {
    $hits[] = $now;
    @file_put_contents($rateFile, json_encode($hits));
}

// Prevent parallel runs
$lockFile = sys_get_temp_dir() . '/backup_complete.lock';
$lockHandle = @fopen($lockFile, 'c+');
if ($lockHandle === false) {
    respond_full(false, "Lock file unavailable\n", 500);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    respond_full(false, "Backup already running\n", 409);
}
ftruncate($lockHandle, 0);
fwrite($lockHandle, (string)time());

$defer = isset($_GET['defer']) && $_GET['defer'] === '1';
if ($defer && function_exists('fastcgi_finish_request')) {
    echo "QUEUED\n";
    fastcgi_finish_request();
}

$root = dirname(__DIR__);
$keepDays = isset($_GET['keep_days']) ? (int)$_GET['keep_days'] : (int)($env['backup']['keep_days'] ?? 14);
$keepCount = isset($_GET['keep_count']) ? (int)$_GET['keep_count'] : (int)($env['backup']['keep_count'] ?? 30);
if ($keepDays <= 0) $keepDays = 14;
if ($keepCount <= 0) $keepCount = 30;

$rcloneEnable = isset($env['rclone']['enable']) ? (bool)$env['rclone']['enable'] : false;
$rcloneUpload = isset($env['rclone']['upload']) ? (bool)$env['rclone']['upload'] : false;
$rcloneBin = isset($env['rclone']['bin']) ? (string)$env['rclone']['bin'] : '';
$rcloneRemote = isset($env['rclone']['remote']) ? (string)$env['rclone']['remote'] : '';
$cloudParam = $_GET['cloud'] ?? '';
$asyncParam = $_GET['async'] ?? '';
$cloudEnabled = ($cloudParam === '' || $cloudParam === '1');
$cloudAsync = ($asyncParam === '1') || (!empty($env['backup']['rclone_async']));

function backup_run($dbFile, $backupDir, $dbBase, $minDbSize, $minRatio, $logFile, $cloudLogFile, $rcloneEnable, $rcloneUpload, $rcloneBin, $rcloneRemote, $cloudEnabled, $cloudAsync, $keepDays, $keepCount, array $env) {
    if (!file_exists($dbFile)) {
        return ['ok' => false, 'message' => 'DB not found'];
    }
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0777, true);
    }
    if (!is_dir($backupDir) || !is_writable($backupDir)) {
        return ['ok' => false, 'message' => 'Backup dir not writable'];
    }

    $srcSize = @filesize($dbFile);
    if ($srcSize === false || $srcSize === 0) {
        return ['ok' => false, 'message' => 'Source DB unreadable'];
    }
    if ($minDbSize > 0 && $srcSize < $minDbSize) {
        return ['ok' => false, 'message' => 'Source DB too small'];
    }

    $stamp = date('Ymd_His');
    $backupFile = $backupDir . '/' . $dbBase . '_' . $stamp . '.db';
    $tempFile = $backupFile . '.tmp';

    $ok = false;
    $message = '';
    $localRetries = isset($env['backup']['local_retries']) ? (int)$env['backup']['local_retries'] : 2;
    if ($localRetries < 0) $localRetries = 0;
    $attempts = $localRetries + 1;
    for ($i = 1; $i <= $attempts; $i++) {
        $ok = false;
        $message = '';
        try {
            if (class_exists('SQLite3')) {
                $src = new SQLite3($dbFile, SQLITE3_OPEN_READONLY);
                $dest = new SQLite3($tempFile, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
                $src->backup($dest);
                $dest->close();
                $src->close();
                $ok = true;
            } else {
                $ok = @copy($dbFile, $tempFile);
            }
        } catch (Exception $e) {
            $message = 'Backup failed.';
            $ok = false;
        }

        if (!$ok || !file_exists($tempFile)) {
            @unlink($tempFile);
            if ($i < $attempts) {
                usleep(200000);
                continue;
            }
        }
        break;
    }

    if (!$ok || !file_exists($tempFile)) {
        return ['ok' => false, 'message' => $message ?: 'Backup failed'];
    }

    $tmpSize = @filesize($tempFile);
    if (!$tmpSize || $tmpSize < ($srcSize * $minRatio)) {
        @unlink($tempFile);
        return ['ok' => false, 'message' => 'Backup size invalid'];
    }

    try {
        if (class_exists('SQLite3')) {
            $chk = new SQLite3($tempFile, SQLITE3_OPEN_READONLY);
            $res = $chk->querySingle('PRAGMA quick_check;');
            $chk->close();
            if (strtolower((string)$res) !== 'ok') {
                throw new Exception('quick_check failed');
            }
        }
    } catch (Exception $e) {
        @unlink($tempFile);
        return ['ok' => false, 'message' => 'Backup integrity failed'];
    }

    if (!@rename($tempFile, $backupFile)) {
        @unlink($tempFile);
        return ['ok' => false, 'message' => 'Failed to finalize backup'];
    }

    // Cloud upload
    $cloudStatus = 'Skipped';
    $cloudError = '';
    if (!$cloudEnabled) {
        $cloudStatus = 'Disabled (param)';
    } elseif (!$rcloneEnable || !$rcloneUpload) {
        $cloudStatus = 'Disabled (rclone off)';
    } elseif ($rcloneBin === '' || !file_exists($rcloneBin)) {
        $cloudStatus = 'Disabled (rclone missing)';
    } elseif ($rcloneRemote === '') {
        $cloudStatus = 'Disabled (remote empty)';
    } else {
        $dest = $rcloneRemote . '/' . basename($backupFile);
        if ($cloudAsync) {
            $cmd = sprintf('%s copyto "%s" "%s" >/dev/null 2>&1 &', $rcloneBin, $backupFile, $dest);
            exec($cmd);
            $cloudStatus = 'Queued';
        } else {
            $rcloneRetries = isset($env['backup']['rclone_retries']) ? (int)$env['backup']['rclone_retries'] : 2;
            if ($rcloneRetries < 0) $rcloneRetries = 0;
            $maxTry = $rcloneRetries + 1;
            for ($i = 1; $i <= $maxTry; $i++) {
                $cmd = sprintf('%s copyto "%s" "%s" 2>&1', $rcloneBin, $backupFile, $dest);
                exec($cmd, $output, $returnVar);
                if ($returnVar === 0) {
                    $cloudStatus = 'Uploaded to Drive';
                    $cloudError = '';
                    break;
                }
                $cloudStatus = 'Upload Failed';
                $cloudError = trim(implode("\n", $output));
                if ($i < $maxTry) {
                    usleep(300000);
                }
            }
        }
    }

    // Logs
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    $logLine = date('Y-m-d H:i:s') . "\t" . basename($backupFile) . "\t" . ($tmpSize ?? 0) . "\t" . $cloudStatus . "\n";
    @file_put_contents($logFile, $logLine, FILE_APPEND);
    if ($cloudError !== '') {
        $cloudLine = date('Y-m-d H:i:s') . "\t" . basename($backupFile) . "\t" . $cloudError . "\n";
        @file_put_contents($cloudLogFile, $cloudLine, FILE_APPEND);
    }

    // Cleanup by days
    $files = glob($backupDir . '/' . $dbBase . '_*.db') ?: [];
    $now = time();
    $deleted = 0;
    foreach ($files as $f) {
        $mtime = @filemtime($f);
        if ($mtime && $now - $mtime > ($keepDays * 86400)) {
            if (@unlink($f)) $deleted++;
        }
    }
    // Cleanup by count
    $files = glob($backupDir . '/' . $dbBase . '_*.db') ?: [];
    usort($files, function($a, $b) {
        return filemtime($b) <=> filemtime($a);
    });
    if (count($files) > $keepCount) {
        $toDelete = array_slice($files, $keepCount);
        foreach ($toDelete as $f) {
            if (@unlink($f)) $deleted++;
        }
    }
    // Cleanup sidecars
    $sidecars = array_merge(
        glob($backupDir . '/' . $dbBase . '_*.db-wal') ?: [],
        glob($backupDir . '/' . $dbBase . '_*.db-shm') ?: [],
        glob($backupDir . '/' . $dbBase . '_*.db.tmp-wal') ?: [],
        glob($backupDir . '/' . $dbBase . '_*.db.tmp-shm') ?: [],
        glob($backupDir . '/' . $dbBase . '_*.db.tmp') ?: []
    );
    foreach ($sidecars as $f) {
        if (@unlink($f)) $deleted++;
    }

    return [
        'ok' => true,
        'backup' => basename($backupFile),
        'cloud' => $cloudStatus,
        'cloud_error' => $cloudError,
        'deleted' => $deleted,
    ];
}

$system_cfg = $env['system'] ?? [];
$db_rel = $system_cfg['db_file'] ?? 'db_data/babahdigital_main.db';
if (preg_match('/^[A-Za-z]:\\\\|^\//', $db_rel)) {
    $mainDb = $db_rel;
} else {
    $mainDb = $root . '/' . ltrim($db_rel, '/');
}
$mainBase = pathinfo($mainDb, PATHINFO_FILENAME);
$mainBackupDir = $root . '/db_data/backups';
$minMainSize = isset($env['backup']['min_db_size']) ? (int)$env['backup']['min_db_size'] : (1024 * 64);

$appDb = app_db_path();
$appBase = pathinfo($appDb, PATHINFO_FILENAME);
$appBackupDir = $root . '/db_data/backups_app';
$minAppSize = isset($env['backup']['min_app_db_size']) ? (int)$env['backup']['min_app_db_size'] : 0;

$mainResult = backup_run(
    $mainDb,
    $mainBackupDir,
    $mainBase,
    $minMainSize,
    0.8,
    $root . '/logs/backup_db.log',
    $root . '/logs/backup_db_cloud.log',
    $rcloneEnable,
    $rcloneUpload,
    $rcloneBin,
    $rcloneRemote,
    $cloudEnabled,
    $cloudAsync,
    $keepDays,
    $keepCount,
    $env
);

$appResult = backup_run(
    $appDb,
    $appBackupDir,
    $appBase,
    $minAppSize,
    0.5,
    $root . '/logs/backup_app_db.log',
    $root . '/logs/backup_app_db_cloud.log',
    $rcloneEnable,
    $rcloneUpload,
    $rcloneBin,
    $rcloneRemote,
    $cloudEnabled,
    $cloudAsync,
    $keepDays,
    $keepCount,
    $env
);

if (!$mainResult['ok'] || !$appResult['ok']) {
    $msg = "FAILED\n";
    $msg .= "Main: " . ($mainResult['ok'] ? 'OK' : 'ERROR') . " - " . ($mainResult['message'] ?? '') . "\n";
    $msg .= "App : " . ($appResult['ok'] ? 'OK' : 'ERROR') . " - " . ($appResult['message'] ?? '') . "\n";
    respond_full(false, $msg, 500);
}

$msg = "OK\n";
$msg .= "Main: " . $mainResult['backup'] . " | Cloud: " . $mainResult['cloud'] . " | Deleted: " . $mainResult['deleted'] . "\n";
if (!empty($mainResult['cloud_error'])) {
    $msg .= "MainCloudError: " . $mainResult['cloud_error'] . "\n";
}
$msg .= "App : " . $appResult['backup'] . " | Cloud: " . $appResult['cloud'] . " | Deleted: " . $appResult['deleted'] . "\n";
if (!empty($appResult['cloud_error'])) {
    $msg .= "AppCloudError: " . $appResult['cloud_error'] . "\n";
}
respond_full(true, $msg, 200);
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
