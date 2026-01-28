<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../include/acl.php';

header('Content-Type: application/json');

if (!isset($_SESSION["mikhmon"])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Session tidak valid.']);
    exit;
}

$root_dir = dirname(__DIR__, 3);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
$system_cfg = $env['system'] ?? [];
$db_rel = $system_cfg['db_file'] ?? 'db_data/mikhmon_stats.db';
if (preg_match('/^[A-Za-z]:\\\\|^\//', $db_rel)) {
    $dbFile = $db_rel;
} else {
    $dbFile = $root_dir . '/' . ltrim($db_rel, '/');
}

$date = trim($_GET['date'] ?? '');
if ($date === '') {
    echo json_encode(['ok' => false, 'message' => 'Tanggal tidak valid.']);
    exit;
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmtLast = $db->prepare("SELECT MAX(report_date) FROM phone_block_daily WHERE report_date < :d");
    $stmtLast->execute([':d' => $date]);
    $lastDate = (string)($stmtLast->fetchColumn() ?? '');
    if ($lastDate === '') {
        echo json_encode(['ok' => true, 'date' => $date, 'source_date' => '', 'data' => []]);
        exit;
    }

    $stmtSrc = $db->prepare("SELECT blok_name, unit_type, total_units, active_units, rusak_units, spam_units, notes
        FROM phone_block_daily WHERE report_date = :d AND unit_type IN ('TOTAL','WARTEL','KAMTIB')");
    $stmtSrc->execute([':d' => $lastDate]);
    $map = [];
    while ($row = $stmtSrc->fetch(PDO::FETCH_ASSOC)) {
        $bname = (string)($row['blok_name'] ?? '');
        if ($bname === '') continue;
        if (!isset($map[$bname])) {
            $map[$bname] = [
                'wartel_units' => 0,
                'kamtib_units' => 0,
                'total_units' => 0,
                'rusak_units' => 0,
                'spam_units' => 0,
                'notes' => ''
            ];
        }
        $ut = strtoupper((string)($row['unit_type'] ?? ''));
        if ($ut === 'TOTAL') {
            $map[$bname]['total_units'] = (int)($row['total_units'] ?? 0);
            $map[$bname]['rusak_units'] = (int)($row['rusak_units'] ?? 0);
            $map[$bname]['spam_units'] = (int)($row['spam_units'] ?? 0);
            $map[$bname]['notes'] = (string)($row['notes'] ?? '');
        } elseif ($ut === 'WARTEL') {
            $map[$bname]['wartel_units'] = (int)($row['total_units'] ?? 0);
        } elseif ($ut === 'KAMTIB') {
            $map[$bname]['kamtib_units'] = (int)($row['total_units'] ?? 0);
        }
    }

    echo json_encode([
        'ok' => true,
        'date' => $date,
        'source_date' => $lastDate,
        'data' => $map
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Gagal mengambil default.']);
}
