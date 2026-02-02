<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../include/acl.php';
ini_set('display_errors', 0);
error_reporting(0);
ob_start();
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
    if (ob_get_length()) ob_clean();
    echo json_encode(['ok' => false, 'message' => 'Parameter tidak valid.']);
    exit;
}

function respond_json($payload) {
    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode($payload);
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

function normalize_log_timestamp($time, $date) {
    $time = trim((string)$time);
    $date = trim((string)$date);
    if ($time === '') return 0;
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
        $ts = strtotime($date . ' ' . $time);
        return $ts === false ? 0 : $ts;
    }
    $ts = strtotime($time);
    return $ts === false ? 0 : $ts;
}

function get_cached_router_logs($cacheFile, $ttlSeconds) {
    if (!is_file($cacheFile)) return null;
    $raw = @file_get_contents($cacheFile);
    if ($raw === false || $raw === '') return null;
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['fetched_at']) || !isset($data['logs'])) return null;
    if (!is_array($data['logs'])) return null;
    $age = time() - (int)$data['fetched_at'];
    if ($age > $ttlSeconds) return null;
    return $data['logs'];
}

function set_cached_router_logs($cacheFile, $logs) {
    $payload = [
        'fetched_at' => time(),
        'logs' => $logs
    ];
    @file_put_contents($cacheFile, json_encode($payload), LOCK_EX);
}

function fetch_router_settlement_logs($iphost, $userhost, $passwdhost, $date, $debugFile) {
    $API = new RouterosAPI();
    $API->debug = false;
    $API->timeout = 5;
    $API->attempts = 1;
    if (!$API->connect($iphost, $userhost, decrypt($passwdhost))) {
        append_settlement_debug($debugFile, 'router_log_connect_failed');
        return ['ok' => false, 'error' => 'Gagal konek ke MikroTik untuk mengambil log.'];
    }

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
    $API->disconnect();

    if (!is_array($rawLogs)) $rawLogs = [];
    return ['ok' => true, 'logs' => $rawLogs, 'date' => $date];
}

function append_parsed_router_logs($rawLogs, $date, $triggeredTs, &$logs, &$done, &$fail) {
    if (!is_array($rawLogs)) return;
    $rawLogs = array_slice($rawLogs, -1000);
    $seq = 0;
    $logItems = [];
    foreach ($rawLogs as $row) {
        $seq++;
        $t = trim((string)($row['time'] ?? ''));
        $sortKey = normalize_log_timestamp($t, $date);
        $logItems[] = ['row' => $row, 'sort' => $sortKey, 'seq' => $seq];
    }
    usort($logItems, function($a, $b){
        if ($a['sort'] === $b['sort']) return $a['seq'] <=> $b['seq'];
        if ($a['sort'] === 0) return 1;
        if ($b['sort'] === 0) return -1;
        return $a['sort'] <=> $b['sort'];
    });
    $sorted = array_map(function($i){ return $i['row']; }, $logItems);

    foreach ($sorted as $l) {
        $time = trim((string)($l['time'] ?? ''));
        $topics = trim((string)($l['topics'] ?? 'system,info'));
        $msg = trim((string)($l['message'] ?? ''));
        if ($msg === '' && $time === '') continue;

        if ($triggeredTs > 0 && $time !== '') {
            $logTs = normalize_log_timestamp($time, $date);
            if ($logTs !== 0) {
                $diff = abs($triggeredTs - $logTs);
                if ($diff <= 21600 && $logTs < ($triggeredTs - 5)) {
                    continue;
                }
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

function uptime_to_seconds_manual($uptime) {
    if (function_exists('auto_rusak_uptime_to_seconds')) {
        return auto_rusak_uptime_to_seconds($uptime);
    }
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
    if (function_exists('auto_rusak_profile_minutes')) {
        return auto_rusak_profile_minutes($validity, $raw_comment);
    }
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

function extract_login_minutes_manual(array $row, $date) {
    if (function_exists('auto_rusak_login_minutes')) {
        return auto_rusak_login_minutes($row, $date);
    }
    $fields = ['login_time_real', 'first_login_real', 'last_login_real', 'logout_time_real', 'updated_at'];
    foreach ($fields as $f) {
        $v = trim((string)($row[$f] ?? ''));
        if ($v === '') continue;
        $ts = strtotime($v);
        if ($ts === false) continue;
        if (date('Y-m-d', $ts) !== $date) continue;
        return ((int)date('H', $ts)) * 60 + (int)date('i', $ts);
    }
    return null;
}

function apply_auto_rusak_settlement($db, $date, $logFile) {
    if (!$db || $date === '') return 0;
    $dateClause = " AND (login_date = :d OR substr(first_login_real,1,10) = :d OR substr(last_login_real,1,10) = :d OR substr(login_time_real,1,10) = :d OR substr(logout_time_real,1,10) = :d OR substr(updated_at,1,10) = :d)";
    $params = [':d' => $date];
    $rows = [];
    try {
        $stmt = $db->prepare("SELECT username, last_uptime, last_bytes, last_status, validity, raw_comment, login_time_real, first_login_real, last_login_real, logout_time_real, updated_at FROM login_history WHERE last_status NOT IN ('rusak','retur','invalid','online') AND last_uptime IS NOT NULL AND last_uptime != '' " . $dateClause);
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
        $bytes_raw = (int)($row['last_bytes'] ?? 0);
        $login_minutes = extract_login_minutes_manual($row, $date);
        $should_rusak = function_exists('auto_rusak_should_rusak')
            ? auto_rusak_should_rusak($profile_minutes, $uptime, $bytes_raw, $login_minutes)
            : (($uptime !== '' && $bytes_raw > 0 && $profile_minutes > 0) ? false : false);
        if ($should_rusak) {
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
if (file_exists($root_dir . '/report/laporan/helpers.php')) {
    require_once($root_dir . '/report/laporan/helpers.php');
}
if (file_exists($root_dir . '/system/whatsapp/wa_helper.php')) {
    require_once($root_dir . '/system/whatsapp/wa_helper.php');
}

function find_report_pdf_for_date($dir, $date) {
    if ($dir === '' || !is_dir($dir)) return '';
    $files = glob(rtrim($dir, '/') . '/*.pdf') ?: [];
    if (empty($files)) return '';
    $date_ymd = $date;
    $date_dmy = '';
    $ts = strtotime($date);
    if ($ts) {
        $date_dmy = date('d-m-Y', $ts);
    }
    $candidates = [];
    foreach ($files as $f) {
        $base = basename($f);
        if (($date_ymd !== '' && strpos($base, $date_ymd) !== false) || ($date_dmy !== '' && strpos($base, $date_dmy) !== false)) {
            $candidates[] = $f;
        }
    }
    $list = !empty($candidates) ? $candidates : $files;
    usort($list, function($a, $b){ return filemtime($b) <=> filemtime($a); });
    return $list[0] ?? '';
}

function format_report_date_dmy($date) {
    $ts = strtotime($date);
    if ($ts) return date('d-m-Y', $ts);
    return (string)$date;
}

$safe_session = preg_replace('/[^A-Za-z0-9_-]/', '', $session);
$safe_date = preg_replace('/[^0-9-]/', '', $date);
$logFile = $logDir . '/settlement_' . $safe_session . '_' . $safe_date . '.log';
$cacheFile = $logDir . '/settlement_router_cache_' . $safe_session . '_' . $safe_date . '.json';
$activeDateFile = $logDir . '/settlement_active_' . $safe_session . '.txt';

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
    try { $db->exec("ALTER TABLE settlement_log ADD COLUMN wa_report_sent_at DATETIME"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE settlement_log ADD COLUMN wa_report_status TEXT"); } catch (Exception $e) {}
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
        respond_json(['ok' => true, 'message' => 'Sudah settlement.']);
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
    $lineCount = 0;
    $forced_done = false;
    $effectiveLogFile = $logFile;
    $log_hint = '';
    clearstatcache(true, $effectiveLogFile);
    if (!is_file($effectiveLogFile) || filesize($effectiveLogFile) === 0) {
        $log_hint = 'Log untuk tanggal ini belum tersedia.';
    }
    clearstatcache(true, $effectiveLogFile);
    $useFileLogs = is_file($effectiveLogFile) && filesize($effectiveLogFile) > 0;
    try {
        if ($useFileLogs) {
            $lines = @file($effectiveLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                $lineCount = count($lines);
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
                        $logTs = normalize_log_timestamp($time, $effectiveDate);
                        if ($logTs !== 0) {
                            $diff = abs($triggeredTs - $logTs);
                            if ($diff <= 21600 && $logTs < ($triggeredTs - 5)) {
                                continue;
                            }
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

            $lineCount = is_array($lines) ? $lineCount : 0;
            $needRouterLogs = (!$done && !$fail && ($lineCount <= 2 || $elapsed > 30));
            if ($needRouterLogs) {
                $cached = get_cached_router_logs($cacheFile, 8);
                if ($cached === null) {
                    $res = fetch_router_settlement_logs($iphost, $userhost, $passwdhost, $date, $debugFile);
                    if ($res['ok']) {
                        $cached = $res['logs'];
                        set_cached_router_logs($cacheFile, $cached);
                    } else {
                        $logs[] = [
                            'time' => date('H:i:s'),
                            'topic' => 'system,error',
                            'type' => 'error',
                            'message' => $res['error']
                        ];
                    }
                }
                if (is_array($cached)) {
                    $log_hint = $log_hint !== '' ? $log_hint : 'Log diambil langsung dari router agar informasi tetap lengkap.';
                    append_parsed_router_logs($cached, $date, $triggeredTs, $logs, $done, $fail);
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
            $cached = get_cached_router_logs($cacheFile, 8);
            if ($cached === null) {
                $res = fetch_router_settlement_logs($iphost, $userhost, $passwdhost, $date, $debugFile);
                if ($res['ok']) {
                    $cached = $res['logs'];
                    set_cached_router_logs($cacheFile, $cached);
                } else {
                    $logs[] = [
                        'time' => date('H:i:s'),
                        'topic' => 'system,error',
                        'type' => 'error',
                        'message' => $res['error']
                    ];
                }
            }
            append_parsed_router_logs($cached, $date, $triggeredTs, $logs, $done, $fail);
        }
    } catch (Exception $e) {
    }

    if (!$done && !$fail && $elapsed > 120) {
        $has_start = false;
        foreach ($logs as $lg) {
            $msg = strtolower((string)($lg['message'] ?? ''));
            if (strpos($msg, 'start manual settlement') !== false || strpos($msg, 'script dijalankan') !== false) {
                $has_start = true;
                break;
            }
        }
        if ($has_start || $lineCount >= 2) {
            $done = true;
            $forced_done = true;
            $status = 'done';
            $warn = 'SETTLE: Log lengkap belum tersedia. Proses dianggap selesai demi kelancaran laporan.';
            append_settlement_log($logFile, 'system,warning', $warn);
            $logs[] = [
                'time' => date('H:i:s'),
                'topic' => 'system,warning',
                'type' => 'warning',
                'message' => $warn
            ];
        }
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

    // Auto send WhatsApp report removed (manual send still available via wa_report action).

    $info_message = '';
    if ($elapsed > 120 && !$done && !$fail) {
        $info_message = 'Mohon tunggu, log settlement sedang disiapkan.';
    }
    if ($forced_done) {
        $info_message = $info_message !== '' ? ($info_message . ' Status diselesaikan agar proses dapat dilanjutkan.') : 'Status diselesaikan agar proses dapat dilanjutkan.';
    }
    if ($log_hint !== '') {
        $info_message = $info_message !== '' ? ($info_message . ' ' . $log_hint) : $log_hint;
    }

    respond_json([
        'ok' => true,
        'status' => $status,
        'logs' => $logs,
        'elapsed' => $elapsed,
        'info_message' => $info_message
    ]);
}

if ($action === 'wa_report') {
    if (!function_exists('wa_send_file')) {
        respond_json(['ok' => false, 'message' => 'WA helper tidak tersedia.']);
    }
    try {
        $stmtS = $db->prepare("SELECT status FROM settlement_log WHERE report_date = :d LIMIT 1");
        $stmtS->execute([':d' => $date]);
        $statusRow = $stmtS->fetch(PDO::FETCH_ASSOC);
        if (!$statusRow || strtolower((string)$statusRow['status']) !== 'done') {
            respond_json(['ok' => false, 'message' => 'Settlement belum selesai.']);
        }
    } catch (Exception $e) {
        respond_json(['ok' => false, 'message' => 'Gagal membaca status settlement.']);
    }

    $pdfDir = $root_dir . '/report/pdf';
    $pdfFile = find_report_pdf_for_date($pdfDir, $date);
    $msgDate = format_report_date_dmy($date);
    $msg = 'Laporan Settlement Harian ' . $msgDate;
    $statusText = '';
    $ok = false;
    if ($pdfFile !== '') {
        $res = wa_send_file($msg, $pdfFile, '', 'report');
        $ok = !empty($res['ok']);
        $statusText = $ok ? 'success' : ('failed: ' . ($res['message'] ?? 'error'));
    } else {
        $statusText = 'failed: file PDF tidak ditemukan';
    }

    try {
        $stmtWU = $db->prepare("UPDATE settlement_log SET wa_report_status = :s, wa_report_sent_at = CASE WHEN :ok = 1 THEN CURRENT_TIMESTAMP ELSE wa_report_sent_at END WHERE report_date = :d");
        $stmtWU->execute([
            ':s' => $statusText,
            ':ok' => $ok ? 1 : 0,
            ':d' => $date
        ]);
    } catch (Exception $e) {}

    if ($ok) {
        append_settlement_log($logFile, 'system,info', 'WA REPORT: terkirim ' . basename($pdfFile));
    } else {
        append_settlement_log($logFile, 'system,warning', 'WA REPORT: ' . $statusText);
    }

    $sentAt = '';
    if ($ok) {
        $sentAt = date('d-m-Y H:i:s');
    }
    $label = $ok ? 'Terkirim' : 'Gagal';
    respond_json([
        'ok' => $ok,
        'status' => $statusText,
        'status_label' => $label,
        'sent_at' => $sentAt,
        'message' => $ok ? 'OK' : $statusText
    ]);
}

if ($action === 'reset') {
    try {
        $stmtR = $db->prepare("DELETE FROM settlement_log WHERE report_date = :d");
        $stmtR->execute([':d' => $date]);
    } catch (Exception $e) {}
    respond_json(['ok' => true, 'message' => 'Reset berhasil.']);
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
    if ($safe_date !== '') {
        @file_put_contents($activeDateFile, $safe_date, LOCK_EX);
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
                respond_json(['ok' => false, 'message' => 'Script tidak ditemukan di MikroTik: ' . $script_name]);
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
        respond_json(['ok' => false, 'message' => 'Gagal konek ke MikroTik.']);
    }

    if ($cleanup) {
        append_settlement_log($logFile, 'system,info', 'SETTLE: CLEANUP dimulai (sales/live/login).');
        cleanup_history_by_router($db, $date, $router_rows, $logFile);
    }

    if (function_exists('rebuild_audit_expected_for_date') && $date !== '') {
        $updated = rebuild_audit_expected_for_date($db, $date);
        if ($updated > 0) {
            append_settlement_log($logFile, 'system,info', 'SETTLE: Rebuild audit expected selesai: ' . $updated . ' blok.');
        } else {
            append_settlement_log($logFile, 'system,info', 'SETTLE: Rebuild audit expected tidak ada perubahan.');
        }
    }

    respond_json(['ok' => true, 'message' => 'Settlement dijalankan.']);
}

respond_json(['ok' => false, 'message' => 'Aksi tidak valid.']);
