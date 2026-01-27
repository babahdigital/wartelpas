<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../include/acl.php';
if (isset($_SESSION['mikhmon']) && isOperator()) {
    requireSuperAdmin('../../../admin.php?id=sessions');
}
session_start();
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

if (!isset($_SESSION["mikhmon"]) || !isset($_GET['session'])) {
    echo json_encode(['ok' => false, 'message' => 'Unauthorized.']);
    exit;
}

session_write_close();

$session = $_GET['session'] ?? '';
$date = $_GET['date'] ?? '';
$action = $_GET['action'] ?? 'start';
if ($session === '' || $date === '') {
    echo json_encode(['ok' => false, 'message' => 'Parameter tidak valid.']);
    exit;
}

function append_settlement_debug($file, $message) {
    $line = date('Y-m-d H:i:s') . "\t" . $message . "\n";
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

$root_dir = dirname(__DIR__, 3);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
$system_cfg = $env['system'] ?? [];
$log_rel = $system_cfg['log_dir'] ?? 'logs';
$logDir = preg_match('/^[A-Za-z]:\\\\|^\//', $log_rel) ? $log_rel : ($root_dir . '/' . trim($log_rel, '/'));
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$debugFile = $logDir . '/settlement_manual_debug.log';
$db_rel = $system_cfg['db_file'] ?? 'db_data/mikhmon_stats.db';
if (preg_match('/^[A-Za-z]:\\\\|^\//', $db_rel)) {
    $dbFile = $db_rel;
} else {
    $dbFile = $root_dir . '/' . ltrim($db_rel, '/');
}

require_once($root_dir . '/lib/routeros_api.class.php');
require_once($root_dir . '/include/config.php');
require_once($root_dir . '/include/readcfg.php');

$safe_session = preg_replace('/[^A-Za-z0-9_-]/', '', $session);
$safe_date = preg_replace('/[^0-9-]/', '', $date);
$logFile = $logDir . '/settlement_' . $safe_session . '_' . $safe_date . '.log';

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");
    $db->exec("PRAGMA busy_timeout=5000;");
    $db->exec("CREATE TABLE IF NOT EXISTS settlement_log (
        report_date TEXT PRIMARY KEY,
        status TEXT,
        triggered_at DATETIME,
        completed_at DATETIME,
        source TEXT,
        message TEXT
    )");
    try { $db->exec("ALTER TABLE settlement_log ADD COLUMN completed_at DATETIME"); } catch (Exception $e) {}
} catch (Exception $e) {
    append_settlement_debug($debugFile, 'db_error=' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'DB error.']);
    exit;
}

append_settlement_debug($debugFile, 'action=' . $action . ' session=' . $session . ' date=' . $date . ' db=' . $dbFile . ' log=' . $logFile);

try {
    $stmt = $db->prepare("SELECT status FROM settlement_log WHERE report_date = :d LIMIT 1");
    $stmt->execute([':d' => $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($action === 'start' && $row && strtolower((string)$row['status']) === 'done') {
        echo json_encode(['ok' => true, 'message' => 'Sudah settlement.']);
        exit;
    }
} catch (Exception $e) {}

if ($action === 'logs') {
    $triggeredAt = '';
    try {
        $stmtT = $db->prepare("SELECT triggered_at FROM settlement_log WHERE report_date = :d LIMIT 1");
        $stmtT->execute([':d' => $date]);
        $triggeredAt = (string)($stmtT->fetchColumn() ?: '');
    } catch (Exception $e) {}

    $triggeredTs = $triggeredAt !== '' ? strtotime($triggeredAt) : 0;
    $nowTs = time();
    $elapsed = $triggeredTs > 0 ? max(0, $nowTs - $triggeredTs) : 0;
    $logs = [];
    $status = 'running';
    $done = false;
    $fail = false;
    $message = '';
    $effectiveLogFile = $logFile;
    $log_hint = '';
    clearstatcache(true, $effectiveLogFile);
    if (!is_file($effectiveLogFile) || filesize($effectiveLogFile) === 0) {
        $pattern = $logDir . '/settlement_' . $safe_session . '_*.log';
        $candidates = glob($pattern);
        if (is_array($candidates) && count($candidates) > 0) {
            usort($candidates, function($a, $b){ return filemtime($b) <=> filemtime($a); });
            $effectiveLogFile = $candidates[0];
            if ($effectiveLogFile !== $logFile) {
                $log_hint = 'Log settlement ditemukan di tanggal berbeda: ' . basename($effectiveLogFile);
            }
        }
    }
    clearstatcache(true, $effectiveLogFile);
    $useFileLogs = is_file($effectiveLogFile) && filesize($effectiveLogFile) > 0;
    $API = new RouterosAPI();
    $API->debug = false;
    try {
        if ($useFileLogs) {
            $lines = @file($effectiveLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                if (count($lines) > 400) {
                    $lines = array_slice($lines, -400);
                }
                $effectiveDate = $date;
                $baseName = basename($effectiveLogFile);
                if (preg_match('/_(\d{4}-\d{2}-\d{2})\.log$/', $baseName, $m)) {
                    $effectiveDate = $m[1];
                }
                $startIndex = 0;
                for ($i = count($lines) - 1; $i >= 0; $i--) {
                    if (strpos($lines[$i], 'SETTLE: CLEANUP: Mulai proses cuci gudang.') !== false) {
                        $startIndex = $i;
                        break;
                    }
                }
                if ($startIndex > 0) {
                    $lines = array_slice($lines, $startIndex);
                }
                foreach ($lines as $line) {
                    $parts = explode("\t", $line, 3);
                    if (count($parts) < 3) continue;
                    $time = trim($parts[0]);
                    $topics = trim($parts[1]);
                    $msg = trim($parts[2]);
                    if ($msg === '') continue;

                    if ($triggeredTs > 0 && $time !== '') {
                        $logTs = strtotime($effectiveDate . ' ' . $time);
                        if ($logTs !== false && $logTs < ($triggeredTs - 5)) {
                            continue;
                        }
                    }

                    $msgUpper = strtoupper($msg);
                    $type = 'info';
                    $topicsLower = strtolower($topics);
                    if (strpos($topicsLower, 'error') !== false) $type = 'error';
                    elseif (strpos($topicsLower, 'warning') !== false) $type = 'warning';
                    elseif (strpos($topicsLower, 'system') !== false || strpos($topicsLower, 'script') !== false) $type = 'system';
                    if (strpos($msgUpper, 'SUKSES') !== false || strpos($msgUpper, 'BERHASIL') !== false) $type = 'success';
                    elseif (strpos($msgUpper, 'GAGAL') !== false || strpos($msgUpper, 'ERROR') !== false || strpos($msgUpper, 'DIBATALKAN') !== false) $type = 'error';
                    elseif (strpos($msgUpper, 'WARNING') !== false || strpos($msgUpper, 'WARN') !== false) $type = 'warning';
                    elseif (strpos($msgUpper, 'SYNC') !== false || strpos($msgUpper, 'CLEANUP') !== false || strpos($msgUpper, 'MAINT') !== false) $type = 'system';

                    $logs[] = [
                        'time' => $time,
                        'topic' => $topics,
                        'type' => $type,
                        'message' => $msg
                    ];

                    if (strpos($msg, 'SUKSES: Cuci Gudang Selesai') !== false) {
                        $done = true;
                        break;
                    }
                    if (strpos($msg, 'CLEANUP: Dibatalkan') !== false || strpos($msgUpper, 'ERROR') !== false) {
                        $fail = true;
                    } elseif (strpos($msgUpper, 'GAGAL') !== false) {
                        if (stripos($msg, 'SYNC USAGE: Gagal koneksi') === false) {
                            $fail = true;
                        }
                    }
                }
            }
        } elseif ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
            $logging = $API->comm('/system/logging/print', [
                '?prefix' => 'SETTLE'
            ]);
            if (!is_array($logging) || count($logging) === 0) {
                $API->comm('/system/logging/add', [
                    'action' => 'memory',
                    'prefix' => 'SETTLE',
                    'topics' => 'script'
                ]);
            }

            $rawLogs = $API->comm('/log/print', [
                '.proplist' => 'time,topics,message',
                '?message~' => 'SETTLE'
            ]);
            if (!is_array($rawLogs) || count($rawLogs) === 0) {
                $rawLogs = [];
            }
            $API->disconnect();
            $rawLogs = is_array($rawLogs) ? array_slice($rawLogs, -1000) : [];
            if (is_array($rawLogs)) {
                $seq = 0;
                $logItems = [];
                foreach ($rawLogs as $row) {
                    $seq++;
                    $t = trim((string)($row['time'] ?? ''));
                    $sortKey = 0;
                    if ($t !== '') {
                        $ts = strtotime($t);
                        if ($ts === false && preg_match('/\d{2}:\d{2}:\d{2}/', $t)) {
                            $ts = strtotime($date . ' ' . $t);
                        }
                        if ($ts !== false) {
                            $sortKey = $ts;
                        }
                    }
                    $logItems[] = ['row' => $row, 'sort' => $sortKey, 'seq' => $seq];
                }
                usort($logItems, function($a, $b){
                    if ($a['sort'] === $b['sort']) return $a['seq'] <=> $b['seq'];
                    if ($a['sort'] === 0) return 1;
                    if ($b['sort'] === 0) return -1;
                    return $a['sort'] <=> $b['sort'];
                });
                $rawLogs = array_map(function($i){ return $i['row']; }, $logItems);
            }
            foreach ($rawLogs as $l) {
                $time = trim((string)($l['time'] ?? ''));
                $topics = trim((string)($l['topics'] ?? 'system,info'));
                $msg = trim((string)($l['message'] ?? ''));
                if ($msg === '' && $time === '') continue;

                if ($triggeredTs > 0 && $time !== '') {
                    $logTs = strtotime($time);
                    if ($logTs === false && preg_match('/\d{2}:\d{2}:\d{2}/', $time)) {
                        $logTs = strtotime($date . ' ' . $time);
                    }
                    if ($logTs !== false && $logTs < ($triggeredTs - 5)) {
                        continue;
                    }
                }

                $type = 'info';
                $topicsLower = strtolower($topics);
                $msgUpper = strtoupper($msg);
                if (strpos($topicsLower, 'error') !== false) $type = 'error';
                elseif (strpos($topicsLower, 'warning') !== false) $type = 'warning';
                elseif (strpos($topicsLower, 'system') !== false || strpos($topicsLower, 'script') !== false) $type = 'system';
                if (strpos($msgUpper, 'SUKSES') !== false || strpos($msgUpper, 'BERHASIL') !== false) $type = 'success';
                elseif (strpos($msgUpper, 'GAGAL') !== false || strpos($msgUpper, 'ERROR') !== false || strpos($msgUpper, 'DIBATALKAN') !== false) $type = 'error';
                elseif (strpos($msgUpper, 'WARNING') !== false || strpos($msgUpper, 'WARN') !== false) $type = 'warning';
                elseif (strpos($msgUpper, 'SYNC') !== false || strpos($msgUpper, 'CLEANUP') !== false || strpos($msgUpper, 'MAINT') !== false) $type = 'system';

                $logs[] = [
                    'time' => $time,
                    'topic' => $topics,
                    'type' => $type,
                    'message' => $msg
                ];

                if (strpos($msg, 'SUKSES: Cuci Gudang Selesai') !== false) {
                    $done = true;
                }
                if (strpos($msg, 'CLEANUP: Dibatalkan') !== false || strpos($msgUpper, 'ERROR') !== false) {
                    $fail = true;
                } elseif (strpos($msgUpper, 'GAGAL') !== false) {
                    if (stripos($msg, 'SYNC USAGE: Gagal koneksi') === false) {
                        $fail = true;
                    }
                }
            }
        }
    } catch (Exception $e) {
    }

    if ($done) {
        $status = 'done';
    } elseif ($fail) {
        $status = 'failed';
    }

    if ($status !== 'running') {
        try {
            $stmtU = $db->prepare("UPDATE settlement_log SET status = :s, completed_at = CURRENT_TIMESTAMP WHERE report_date = :d");
            $stmtU->execute([':s' => $status, ':d' => $date]);
        } catch (Exception $e) {}
    }

    $info_message = '';
    if ($elapsed > 120 && !$done && !$fail) {
        $info_message = 'Proses settlement berjalan. Jika lama, cek log MikroTik.';
    }
    if ($log_hint !== '') {
        $info_message = $info_message !== '' ? ($info_message . ' ' . $log_hint) : $log_hint;
    }

    echo json_encode([
        'ok' => true,
        'status' => $status,
        'logs' => $logs,
        'elapsed' => $elapsed,
        'info_message' => $info_message
    ]);
    exit;
}

if ($action === 'reset') {
    try {
        $stmtR = $db->prepare("DELETE FROM settlement_log WHERE report_date = :d");
        $stmtR->execute([':d' => $date]);
    } catch (Exception $e) {}
    echo json_encode(['ok' => true, 'message' => 'Reset berhasil.']);
    exit;
}

if ($action === 'start') {
    $latestLogFile = '';
    $pattern = $logDir . '/settlement_' . $safe_session . '_*.log';
    $candidates = glob($pattern);
    if (is_array($candidates) && count($candidates) > 0) {
        usort($candidates, function($a, $b){ return filemtime($b) <=> filemtime($a); });
        $latestLogFile = $candidates[0];
    }
    if ($latestLogFile !== '' && is_file($latestLogFile)) {
        @file_put_contents($latestLogFile, "");
        append_settlement_debug($debugFile, 'truncate_latest_log=' . basename($latestLogFile));
    }
    if (is_file($logFile)) {
        @file_put_contents($logFile, "");
        append_settlement_debug($debugFile, 'truncate_log=' . basename($logFile));
    }
    try {
        $stmtI = $db->prepare("INSERT OR IGNORE INTO settlement_log (report_date, status, triggered_at, source, message) VALUES (:d, 'running', CURRENT_TIMESTAMP, 'manual', '')");
        $stmtI->execute([':d' => $date]);
        $stmtU = $db->prepare("UPDATE settlement_log SET status = 'running', triggered_at = CURRENT_TIMESTAMP WHERE report_date = :d");
        $stmtU->execute([':d' => $date]);
    } catch (Exception $e) {
        append_settlement_debug($debugFile, 'db_write_error=' . $e->getMessage());
    }

    $cmd = '/system/script/run';
    $script_name = trim((string)($system_cfg['settlement_script'] ?? ''));
    if ($script_name === '') {
        $script_name = 'CleanWartel';
    }
    $params = [
        'number' => $script_name
    ];
    $API = new RouterosAPI();
    $API->debug = false;
    $connected = false;
    $errMsg = '';
    try {
        if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
            $connected = true;
            $scriptRows = $API->comm('/system/script/print', [
                '?name' => $script_name
            ]);
            if (!is_array($scriptRows) || count($scriptRows) === 0) {
                append_settlement_debug($debugFile, 'script_not_found=' . $script_name);
                $API->disconnect();
                echo json_encode(['ok' => false, 'message' => 'Script tidak ditemukan di MikroTik: ' . $script_name]);
                exit;
            }
            $API->comm($cmd, $params);
            $API->disconnect();
            append_settlement_debug($debugFile, 'script_run=' . $script_name);
        }
    } catch (Exception $e) {
        $errMsg = $e->getMessage();
        append_settlement_debug($debugFile, 'api_error=' . $errMsg);
    }

    if (!$connected) {
        if ($errMsg !== '') {
            append_settlement_debug($debugFile, 'connect_error=' . $errMsg);
        }
        echo json_encode(['ok' => false, 'message' => 'Gagal konek ke MikroTik.']);
        exit;
    }

    echo json_encode(['ok' => true, 'message' => 'Settlement dijalankan.']);
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Aksi tidak valid.']);
