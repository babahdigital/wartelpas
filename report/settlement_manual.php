<?php
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

$root_dir = dirname(__DIR__);
require_once($root_dir . '/lib/routeros_api.class.php');
require_once($root_dir . '/include/config.php');
require_once($root_dir . '/include/readcfg.php');

$logDir = $root_dir . '/logs';
$safe_session = preg_replace('/[^A-Za-z0-9_-]/', '', $session);
$safe_date = preg_replace('/[^0-9-]/', '', $date);
$logFile = $logDir . '/settlement_' . $safe_session . '_' . $safe_date . '.log';

$dbFile = $root_dir . '/db_data/mikhmon_stats.db';
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
    echo json_encode(['ok' => false, 'message' => 'DB error.']);
    exit;
}

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
    $useFileLogs = is_file($logFile) && filesize($logFile) > 0;
    $API = new RouterosAPI();
    $API->debug = false;
    try {
        if ($useFileLogs) {
            $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $parts = explode("\t", $line, 3);
                    if (count($parts) < 3) continue;
                    $time = trim($parts[0]);
                    $topics = trim($parts[1]);
                    $msg = trim($parts[2]);
                    if ($msg === '') continue;

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
                // Pastikan logging rule untuk script ada (mirip hotspot/log.php)
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
                    $rawLogs = $API->comm('/log/print', [
                        '.proplist' => 'time,topics,message'
                    ]);
                }
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
            $sawSettle = false;
            $sawFetch = false;
            $capture = false;
            $skipReadyCount = 0;
            $skipReadyTime = '';
            $skipReadyMax = 0;
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

                $msgTrim = trim($msg);
                if (strpos($msgTrim, "\r") !== false || strpos($msgTrim, "\n") !== false || strpos($msgTrim, "tool fetch url") !== false) {
                    continue;
                }

                if (strpos($msgTrim, "\\r") !== false || strpos($msgTrim, "\\n") !== false) {
                    continue;
                }

                if (stripos($msgTrim, "on-error") !== false && stripos($msgTrim, ":log info") !== false) {
                    continue;
                }

                if (stripos($msgTrim, '[ARP Cleanup]') !== false || stripos($msgTrim, '[DHCP Cleanup]') !== false || stripos($msgTrim, 'SYNC USAGE: OK') !== false) {
                    continue;
                }

                $msgUpper = strtoupper($msgTrim);
                $topicUpper = strtoupper($topics);
                $startsOk = preg_match('/^(SETTLE:|CLEANUP:|SYNC:|MAINT:|SUKSES:)/i', $msgTrim);
                $isScriptTopic = (strpos($topicUpper, 'SCRIPT') !== false);
                $isFetchTopic = (strpos($topicUpper, 'FETCH') !== false) && (strpos($msgUpper, 'WARTELPAS') !== false || strpos($msgUpper, 'SOBIGIDUL') !== false);
                $isSettleMsg = ($startsOk || stripos($msgTrim, 'SETTLE:') !== false || stripos($msgTrim, 'CLEANUP:') !== false || stripos($msgTrim, 'SYNC:') !== false || stripos($msgTrim, 'MAINT:') !== false);

                if (stripos($msgTrim, 'SETTLE: CLEANUP: Mulai') !== false || stripos($msgTrim, 'SETTLE: SYNC:') !== false) {
                    $capture = true;
                }
                if (!$capture && $startsOk) {
                    if (stripos($msgTrim, 'SETTLE:') === 0 || stripos($msgTrim, 'CLEANUP:') === 0 || stripos($msgTrim, 'SYNC:') === 0 || stripos($msgTrim, 'MAINT:') === 0 || stripos($msgTrim, 'SUKSES:') === 0) {
                        $capture = true;
                    }
                }

                if ($startsOk || $isScriptTopic) {
                    $sawSettle = true;
                }
                if ($isFetchTopic) {
                    $sawFetch = true;
                }

                if (!$isSettleMsg && !($isScriptTopic && $sawSettle)) {
                    continue;
                }

                if (stripos($msgTrim, 'SETTLE: CLEANUP: Skip READY user') === 0) {
                    $skipReadyCount++;
                    if ($time !== '') $skipReadyTime = $time;
                    if (preg_match('/x(\d+)/i', $msgTrim, $m)) {
                        $val = (int)$m[1];
                        if ($val > $skipReadyMax) $skipReadyMax = $val;
                    }
                    continue;
                }

                $type = 'info';
                $topicsLower = strtolower($topics);
                if (strpos($topicsLower, 'error') !== false) {
                    $type = 'error';
                } elseif (strpos($topicsLower, 'warning') !== false) {
                    $type = 'warning';
                } elseif (strpos($topicsLower, 'system') !== false || strpos($topicsLower, 'script') !== false) {
                    $type = 'system';
                }
                if (strpos($msgUpper, 'SUKSES') !== false || strpos($msgUpper, 'BERHASIL') !== false) {
                    $type = 'success';
                } elseif (strpos($msgUpper, 'GAGAL') !== false || strpos($msgUpper, 'ERROR') !== false || strpos($msgUpper, 'DIBATALKAN') !== false) {
                    $type = 'error';
                } elseif (strpos($msgUpper, 'WARNING') !== false || strpos($msgUpper, 'WARN') !== false) {
                    $type = 'warning';
                } elseif (strpos($msgUpper, 'SYNC') !== false || strpos($msgUpper, 'CLEANUP') !== false || strpos($msgUpper, 'MAINT') !== false) {
                    $type = 'system';
                }

                if (stripos($msgTrim, 'SYNC USAGE: Gagal koneksi') !== false) {
                    $type = 'warning';
                }

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
                    if (stripos($msgTrim, 'SYNC USAGE: Gagal koneksi') === false) {
                        $fail = true;
                    }
                }
            }
            // Skip READY detail intentionally hidden from popup
        } else {
            $message = 'Gagal konek ke router.';
            $fail = true;
        }
    } catch (Exception $e) {
        $message = 'Gagal membaca log.';
        $fail = true;
    }

    $infoMessage = '';
    if (empty($logs)) {
            if ($elapsed > 15) {
                if ($sawFetch) {
                    $infoMessage = 'Log settlement belum muncul. Pastikan script Cuci Gudang sudah terpasang.';
                } else {
                    $infoMessage = $message !== '' ? $message : 'Menunggu log dari MikroTik...';
                }
            } else {
                $infoMessage = 'Menunggu log dari MikroTik...';
            }
    }

    if ($done) $status = 'done';
    elseif ($fail) $status = 'failed';

    $now = date('Y-m-d H:i:s');
    try {
        $stmt = $db->prepare("INSERT OR REPLACE INTO settlement_log (report_date, status, triggered_at, completed_at, source, message)
            VALUES (:d, :s, COALESCE((SELECT triggered_at FROM settlement_log WHERE report_date = :d), :t), :c, 'manual', :m)");
        $stmt->execute([
            ':d' => $date,
            ':s' => $status === 'done' ? 'done' : ($status === 'failed' ? 'failed' : 'running'),
            ':c' => $status === 'done' ? $now : null,
            ':t' => $now,
            ':m' => $message
        ]);
    } catch (Exception $e) {}

    echo json_encode(['ok' => true, 'status' => $status, 'logs' => $logs, 'info_message' => $infoMessage]);
    exit;
}

if ($action === 'reset') {
    try {
        $stmt = $db->prepare("DELETE FROM settlement_log WHERE report_date = :d");
        $stmt->execute([':d' => $date]);
        echo json_encode(['ok' => true, 'message' => 'Reset berhasil.']);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'message' => 'Reset gagal.']);
    }
    exit;
}

// action=start
$API = new RouterosAPI();
$API->debug = false;
$ok = false;
$message = '';
$earlyResponded = false;

try {
    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare("INSERT OR REPLACE INTO settlement_log (report_date, status, triggered_at, completed_at, source, message)
        VALUES (:d, :s, :t, NULL, 'manual', :m)");
    $stmt->execute([
        ':d' => $date,
        ':s' => 'running',
        ':t' => $now,
        ':m' => 'Menjalankan settlement...'
    ]);
} catch (Exception $e) {}

if (is_file($logFile)) {
    $archiveDir = $logDir . '/settlement_archive';
    if (!is_dir($archiveDir)) {
        @mkdir($archiveDir, 0755, true);
    }
    $archiveName = $archiveDir . '/settlement_' . $safe_session . '_' . $safe_date . '_' . date('Ymd_His') . '.log';
    if (!@rename($logFile, $archiveName)) {
        @unlink($logFile);
    }
}

if (!headers_sent()) {
    echo json_encode(['ok' => true, 'message' => 'OK']);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_flush();
        @flush();
    }
    $earlyResponded = true;
}
ignore_user_abort(true);
try {
    if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
        $scriptName = 'CuciGudangManual';
        $sid = '';

        $script = $API->comm('/system/script/print', [
            '?name' => $scriptName,
            '.proplist' => '.id,name,comment,source'
        ]);
        $sid = $script[0]['.id'] ?? '';

        if ($sid === '') {
            $all = $API->comm('/system/script/print', [
                '.proplist' => '.id,name,comment,source'
            ]);
            if (is_array($all)) {
                foreach ($all as $row) {
                    $nm = (string)($row['name'] ?? '');
                    $src = (string)($row['source'] ?? '');
                    if ($nm === '') continue;
                    if (preg_match('/cucigudang|cleanwartel/i', $nm) || stripos($src, 'SETTLE: CLEANUP') !== false || stripos($src, 'Cuci Gudang') !== false) {
                        $scriptName = $nm;
                        $sid = $row['.id'] ?? '';
                        break;
                    }
                }
            }
        }

        if ($sid !== '') {
            $API->comm('/log/info', ['message' => 'SETTLE: MANUAL: Mulai']);
            try {
                $API->comm('/system/script/run', [
                    '.id' => $sid
                ]);
                $ok = true;
            } catch (Exception $e) {
                $message = 'Gagal menjalankan script Cuci Gudang.';
                $ok = false;
            }
        } else {
            $message = 'Script Cuci Gudang tidak ditemukan. Pastikan ada script bernama CuciGudang atau CuciGudangManual.';
        }
        $API->disconnect();
    } else {
        $message = 'Gagal konek ke router.';
    }
} catch (Exception $e) {
    $message = 'Gagal menjalankan scheduler.';
}

try {
    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare("INSERT OR REPLACE INTO settlement_log (report_date, status, triggered_at, completed_at, source, message)
        VALUES (:d, :s, :t, NULL, 'manual', :m)");
    $stmt->execute([
        ':d' => $date,
        ':s' => $ok ? 'running' : 'failed',
        ':t' => $now,
        ':m' => $message
    ]);
} catch (Exception $e) {}

if (!$earlyResponded) {
    echo json_encode(['ok' => $ok, 'message' => $ok ? 'OK' : ($message ?: 'Gagal')]);
}
