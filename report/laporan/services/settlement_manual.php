<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../include/acl.php';
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
$force = isset($_GET['force']) && $_GET['force'] === '1';
$cleanup = isset($_GET['cleanup']) && $_GET['cleanup'] === '1';
if ($session === '' || $date === '') {
    echo json_encode(['ok' => false, 'message' => 'Parameter tidak valid.']);
    exit;
}

function append_settlement_debug($file, $message) {
    $line = date('Y-m-d H:i:s') . "\t" . $message . "\n";
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

function append_settlement_log($file, $topic, $message) {
    $time = date('H:i:s');
    $line = $time . "\t" . $topic . "\t" . $message . "\n";
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

function uptime_to_seconds_manual($uptime) {
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

function detect_profile_minutes_manual($validity, $raw_comment) {
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

function apply_auto_rusak_settlement($db, $date, $logFile) {
    if (!$db || $date === '') return 0;
    $bytes_threshold_short = 3 * 1024 * 1024;
    $short_uptime_limit = 5 * 60;
    $dateClause = " AND (login_date = :d OR substr(first_login_real,1,10) = :d OR substr(last_login_real,1,10) = :d OR substr(login_time_real,1,10) = :d OR substr(logout_time_real,1,10) = :d OR substr(updated_at,1,10) = :d)";
    $params = [':d' => $date];
    $rows = [];
    try {
        $stmt = $db->prepare("SELECT username, last_uptime, last_bytes, last_status, validity, raw_comment FROM login_history WHERE last_status NOT IN ('rusak','retur','invalid','online') AND last_uptime IS NOT NULL AND last_uptime != '' " . $dateClause);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return 0;
    }

    $updated = 0;
    foreach ($rows as $row) {
        $uname = trim((string)($row['username'] ?? ''));
        if ($uname === '') continue;
        $profile_minutes = detect_profile_minutes_manual($row['validity'] ?? '', $row['raw_comment'] ?? '');
        if ($profile_minutes <= 0) continue;
        $uptime = (string)($row['last_uptime'] ?? '');
        $uptime_sec = uptime_to_seconds_manual($uptime);
        $bytes_raw = (int)($row['last_bytes'] ?? 0);
        $bytes = $bytes_raw;
        if ($bytes > 0 && $bytes < 1024 * 1024 && $bytes <= 1024) {
            $bytes = $bytes * 1024 * 1024;
        }
        $is_full_uptime = $uptime_sec >= ($profile_minutes * 60);
        $is_short_use = ($uptime_sec > 0 && $uptime_sec <= $short_uptime_limit);
        $bytes_threshold_full = ($profile_minutes === 10) ? (3 * 1024 * 1024) : (7 * 1024 * 1024);
        if (($is_full_uptime && $bytes < $bytes_threshold_full) || ($is_short_use && $bytes < $bytes_threshold_short)) {
            try {
            $stmtU = $db->prepare("UPDATE login_history SET last_status='rusak', auto_rusak=1, updated_at=CURRENT_TIMESTAMP WHERE username = :u");
                $stmtU->execute([':u' => $uname]);
                $stmtS = $db->prepare("UPDATE sales_history SET status='rusak', is_rusak=1, is_retur=0, is_invalid=0 WHERE username = :u AND sale_date = :d");
                $stmtS->execute([':u' => $uname, ':d' => $date]);
                $stmtL = $db->prepare("UPDATE live_sales SET status='rusak', is_rusak=1, is_retur=0, is_invalid=0 WHERE username = :u AND sale_date = :d");
                $stmtL->execute([':u' => $uname, ':d' => $date]);
                $updated++;
            } catch (Exception $e) {
            }
        }
    }
    if ($updated > 0) {
        append_settlement_log($logFile, 'system,info', 'AUTO RUSAK: ' . $updated . ' user diperbarui (profil 10/30, bytes kecil).');
    } else {
        append_settlement_log($logFile, 'system,info', 'AUTO RUSAK: tidak ada user yang memenuhi kriteria.');
    }
    return $updated;
}

function cleanup_history_by_router($db, $date, $router_rows, $logFile) {
        if (!$db || $date === '') return [0, 0, 0];
        $names = [];
        if (is_array($router_rows)) {
                foreach ($router_rows as $r) {
                        $name = trim((string)($r['name'] ?? ''));
                        if ($name !== '') $names[] = $name;
                }
        }
        if (empty($names)) {
                append_settlement_log($logFile, 'system,info', 'SETTLE: CLEANUP: daftar user router kosong, cleanup dilewati.');
                return [0, 0, 0];
        }

        $deleted_live = 0;

        try {
                $db->exec("DROP TABLE IF EXISTS temp_router_users");
                $db->exec("CREATE TEMP TABLE temp_router_users (name TEXT PRIMARY KEY)");
                $stmtIns = $db->prepare("INSERT OR IGNORE INTO temp_router_users (name) VALUES (:n)");
                foreach ($names as $n) {
                        $stmtIns->execute([':n' => $n]);
                }

                $stmtDelLive = $db->prepare("DELETE FROM live_sales
                        WHERE sync_status='pending'
                            AND (sale_date = :d OR raw_date LIKE :dlike)
                            AND username NOT IN (SELECT name FROM temp_router_users)");
                $stmtDelLive->execute([':d' => $date, ':dlike' => $date . '%']);
                $deleted_live = $stmtDelLive->rowCount();
        } catch (Exception $e) {
                append_settlement_log($logFile, 'system,error', 'SETTLE: CLEANUP gagal: ' . $e->getMessage());
                return [0, 0, 0];
        }

        append_settlement_log($logFile, 'system,info', 'SETTLE: CLEANUP selesai. live_sales=' . $deleted_live . '.');
        return [0, $deleted_live, 0];
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
    try { $db->exec("ALTER TABLE settlement_log ADD COLUMN auto_rusak_at DATETIME"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE login_history ADD COLUMN auto_rusak INTEGER DEFAULT 0"); } catch (Exception $e) {}
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
    if ($action === 'start' && $row && strtolower((string)$row['status']) === 'done' && !$force) {
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
        } else {
            $debugLines = [];
            if (is_file($debugFile)) {
                $debugLines = @file($debugFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (is_array($debugLines) && count($debugLines) > 200) {
                    $debugLines = array_slice($debugLines, -200);
                }
            }
            if (!empty($debugLines)) {
                foreach ($debugLines as $dl) {
                    $parts = explode("\t", $dl, 2);
                    $time = '';
                    $msg = $dl;
                    if (count($parts) === 2) {
                        $time = trim($parts[0]);
                        $msg = trim($parts[1]);
                    }
                    $logs[] = [
                        'time' => $time,
                        'topic' => 'system,debug',
                        'type' => 'system',
                        'message' => $msg
                    ];
                }
            }
            $rawLogs = [];
            $API = new RouterosAPI();
            $API->debug = false;
            $API->timeout = 5;
            $API->attempts = 1;
            if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
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
            } else {
                $logs[] = [
                    'time' => date('H:i:s'),
                    'topic' => 'system,error',
                    'type' => 'error',
                    'message' => 'Gagal konek ke MikroTik untuk mengambil log.'
                ];
            }
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

    if ($status === 'done') {
        try {
            $stmtAR = $db->prepare("SELECT auto_rusak_at FROM settlement_log WHERE report_date = :d LIMIT 1");
            $stmtAR->execute([':d' => $date]);
            $autoRan = (string)($stmtAR->fetchColumn() ?: '');
            if ($autoRan === '') {
                $autoCount = apply_auto_rusak_settlement($db, $date, $logFile);
                $stmtARU = $db->prepare("UPDATE settlement_log SET auto_rusak_at = CURRENT_TIMESTAMP, message = :m WHERE report_date = :d");
                $stmtARU->execute([
                    ':m' => 'AUTO RUSAK: ' . $autoCount . ' user',
                    ':d' => $date
                ]);
            }
        } catch (Exception $e) {}
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
    append_settlement_log($logFile, 'system,info', 'SETTLE: START manual settlement.');
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
    $API->timeout = 5;
    $API->attempts = 1;
    $connected = false;
    $errMsg = '';
    $router_rows = [];
    try {
        if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
            $connected = true;
            $scriptRows = $API->comm('/system/script/print', [
                '?name' => $script_name
            ]);
            if (!is_array($scriptRows) || count($scriptRows) === 0) {
                $fallback = 'CleanWartel';
                if ($script_name !== $fallback) {
                    $scriptRows = $API->comm('/system/script/print', [
                        '?name' => $fallback
                    ]);
                    if (is_array($scriptRows) && count($scriptRows) > 0) {
                        $script_name = $fallback;
                        $params = ['number' => $script_name];
                        append_settlement_debug($debugFile, 'script_fallback=' . $script_name);
                    }
                }
            }
            if (!is_array($scriptRows) || count($scriptRows) === 0) {
                append_settlement_debug($debugFile, 'script_not_found=' . $script_name);
                append_settlement_log($logFile, 'system,error', 'SETTLE: Script tidak ditemukan: ' . $script_name);
                $API->disconnect();
                echo json_encode(['ok' => false, 'message' => 'Script tidak ditemukan di MikroTik: ' . $script_name]);
                exit;
            }
            $API->comm($cmd, $params);
            if ($cleanup) {
                $router_rows = $API->comm('/ip/hotspot/user/print', [
                    '?server' => $hotspot_server,
                    '.proplist' => 'name'
                ]);
            }
            $API->disconnect();
            append_settlement_debug($debugFile, 'script_run=' . $script_name);
            append_settlement_log($logFile, 'system,info', 'SETTLE: Script dijalankan: ' . $script_name);
        }
    } catch (Exception $e) {
        $errMsg = $e->getMessage();
        append_settlement_debug($debugFile, 'api_error=' . $errMsg);
        append_settlement_log($logFile, 'system,error', 'SETTLE: API error ' . $errMsg);
    }

    if (!$connected) {
        if ($errMsg !== '') {
            append_settlement_debug($debugFile, 'connect_error=' . $errMsg);
        }
        append_settlement_log($logFile, 'system,error', 'SETTLE: Gagal konek ke MikroTik.');
        echo json_encode(['ok' => false, 'message' => 'Gagal konek ke MikroTik.']);
        exit;
    }

    if ($cleanup) {
        append_settlement_log($logFile, 'system,info', 'SETTLE: CLEANUP dimulai (sales/live/login).');
        cleanup_history_by_router($db, $date, $router_rows, $logFile);
    }

    echo json_encode(['ok' => true, 'message' => 'Settlement dijalankan.']);
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Aksi tidak valid.']);
