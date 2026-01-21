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
            $rawLogs = $API->comm('/log/print', [
                '.proplist' => 'time,message',
                '?message~' => 'CLEANUP|SYNC|CUCI GUDANG|SUKSES|MAINT'
            ]);
            $API->disconnect();
            $rawLogs = is_array($rawLogs) ? array_slice($rawLogs, -80) : [];
            foreach ($rawLogs as $l) {
                $line = trim(($l['time'] ?? '') . ' ' . ($l['message'] ?? ''));
                if ($line !== '') $logs[] = $line;
                if (strpos($line, 'SUKSES: Cuci Gudang Selesai') !== false) {
                    $done = true;
                }
                if (strpos($line, 'CLEANUP: Dibatalkan') !== false) {
                    $fail = true;
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

    if ($done) $status = 'done';
    elseif ($fail) $status = 'failed';

    try {
        $stmt = $db->prepare("INSERT OR REPLACE INTO settlement_log (report_date, status, triggered_at, completed_at, source, message)
            VALUES (:d, :s, COALESCE((SELECT triggered_at FROM settlement_log WHERE report_date = :d), CURRENT_TIMESTAMP), :c, 'manual', :m)");
        $stmt->execute([
            ':d' => $date,
            ':s' => $status === 'done' ? 'done' : ($status === 'failed' ? 'failed' : 'running'),
            ':c' => $status === 'done' ? date('Y-m-d H:i:s') : null,
            ':m' => $message
        ]);
    } catch (Exception $e) {}

    echo json_encode(['ok' => true, 'status' => $status, 'logs' => $logs]);
    exit;
}

// action=start
$API = new RouterosAPI();
$API->debug = false;
$ok = false;
$message = '';
try {
    if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
        $sched = $API->comm('/system/scheduler/print', [
            '?name' => 'CuciGudang',
            '.proplist' => '.id,name'
        ]);
        $sid = $sched[0]['.id'] ?? '';
        if ($sid !== '') {
            $API->comm('/system/scheduler/run', ['.id' => $sid]);
            $ok = true;
        } else {
            $message = 'Scheduler CuciGudangManual tidak ditemukan.';
        }
        $API->disconnect();
    } else {
        $message = 'Gagal konek ke router.';
    }
} catch (Exception $e) {
    $message = 'Gagal menjalankan scheduler.';
}

try {
    $stmt = $db->prepare("INSERT OR REPLACE INTO settlement_log (report_date, status, triggered_at, completed_at, source, message)
        VALUES (:d, :s, CURRENT_TIMESTAMP, NULL, 'manual', :m)");
    $stmt->execute([
        ':d' => $date,
        ':s' => $ok ? 'running' : 'failed',
        ':m' => $message
    ]);
} catch (Exception $e) {}

echo json_encode(['ok' => $ok, 'message' => $ok ? 'OK' : ($message ?: 'Gagal')]);
