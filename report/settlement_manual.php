<?php
session_start();
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

if (!isset($_SESSION["mikhmon"]) || !isset($_GET['session'])) {
    echo json_encode(['ok' => false, 'message' => 'Unauthorized.']);
    exit;
}

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
    $logs = [];
    $status = 'running';
    $done = false;
    $fail = false;
    $message = '';
    $API = new RouterosAPI();
    $API->debug = false;
    try {
        if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
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
                    '.proplist' => 'time,topics,message'
                ]);
                if (!is_array($rawLogs) || count($rawLogs) === 0) {
                    $rawLogs = [];
                }
                $API->disconnect();
                $rawLogs = is_array($rawLogs) ? array_slice($rawLogs, -400) : [];
                if (is_array($rawLogs)) {
                    $rawLogs = array_reverse($rawLogs);
                }
            $sawSettle = false;
            $sawFetch = false;
            foreach ($rawLogs as $l) {
                $time = trim((string)($l['time'] ?? ''));
                $topics = trim((string)($l['topics'] ?? 'system,info'));
                $msg = trim((string)($l['message'] ?? ''));
                if ($msg === '' && $time === '') continue;

                $msgTrim = trim($msg);
                if (strpos($msgTrim, "\r") !== false || strpos($msgTrim, "\n") !== false || strpos($msgTrim, "tool fetch url") !== false) {
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

                if ($startsOk || $isScriptTopic) {
                    $sawSettle = true;
                }
                if ($isFetchTopic) {
                    $sawFetch = true;
                }

                if (!($startsOk || $isScriptTopic || ($sawSettle && $isFetchTopic))) {
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

                $logs[] = [
                    'time' => $time,
                    'topic' => $topics,
                    'type' => $type,
                    'message' => $msg
                ];

                if (strpos($msg, 'SUKSES: Cuci Gudang Selesai') !== false) {
                    $done = true;
                }
                if (strpos($msg, 'CLEANUP: Dibatalkan') !== false || strpos($msgUpper, 'GAGAL') !== false || strpos($msgUpper, 'ERROR') !== false) {
                    $fail = true;
                }

                if ($done) {
                    break;
                }
            }
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
        if ($sawFetch) {
            $infoMessage = 'Log settlement belum muncul. Pastikan script Cuci Gudang sudah terpasang dan scheduler berjalan.';
        } else {
            $infoMessage = $message !== '' ? $message : 'Menunggu log dari MikroTik...';
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
try {
    if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
        $candidates = ['CuciGudang', 'CuciGudangManual'];
        $sid = '';
        $foundName = '';
        foreach ($candidates as $cand) {
            $sched = $API->comm('/system/scheduler/print', [
                '?name' => $cand,
                '.proplist' => '.id,name'
            ]);
            if (!empty($sched) && isset($sched[0]['.id'])) {
                $sid = $sched[0]['.id'];
                $foundName = $sched[0]['name'] ?? $cand;
                break;
            }
        }
        if ($sid !== '') {
            $API->comm('/system/scheduler/run', ['.id' => $sid]);
            $ok = true;
        } else {
            $message = 'Scheduler CuciGudang/CuciGudangManual tidak ditemukan.';
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

echo json_encode(['ok' => $ok, 'message' => $ok ? 'OK' : ($message ?: 'Gagal')]);
