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
        source TEXT,
        message TEXT
    )");
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'message' => 'DB error.']);
    exit;
}

try {
    $stmt = $db->prepare("SELECT status FROM settlement_log WHERE report_date = :d LIMIT 1");
    $stmt->execute([':d' => $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && strtolower((string)$row['status']) === 'done') {
        echo json_encode(['ok' => true, 'message' => 'Sudah settlement.']);
        exit;
    }
} catch (Exception $e) {}

$API = new RouterosAPI();
$API->debug = false;
$ok = false;
$message = '';
try {
    if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
        $sched = $API->comm('/system/scheduler/print', [
            '?name' => 'CuciGudangManual',
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
    $stmt = $db->prepare("INSERT OR REPLACE INTO settlement_log (report_date, status, triggered_at, source, message) VALUES (:d, :s, CURRENT_TIMESTAMP, 'manual', :m)");
    $stmt->execute([
        ':d' => $date,
        ':s' => $ok ? 'done' : 'failed',
        ':m' => $message
    ]);
} catch (Exception $e) {}

echo json_encode(['ok' => $ok, 'message' => $ok ? 'OK' : ($message ?: 'Gagal')]);
