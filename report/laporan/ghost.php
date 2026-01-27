<?php
session_start();
error_reporting(0);
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

if (!isset($_SESSION["mikhmon"])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/helpers.php';

$root_dir = dirname(__DIR__, 2);
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

if (!file_exists($dbFile)) {
    echo json_encode(['ok' => false, 'message' => 'DB tidak tersedia.']);
    exit;
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA query_only=1;");
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'message' => 'DB tidak tersedia.']);
    exit;
}

$g_date = trim((string)($_GET['date'] ?? ''));
$g_blok = trim((string)($_GET['blok'] ?? ''));
$g_blok_norm = normalize_block_name($g_blok);
if ($g_date === '' || $g_blok === '') {
    echo json_encode(['ok' => false, 'message' => 'Tanggal atau blok tidak valid.']);
    exit;
}

$reported_users = [];
if (table_exists($db, 'audit_rekap_manual')) {
    try {
        $stmt = $db->prepare("SELECT audit_username, user_evidence FROM audit_rekap_manual WHERE report_date = :d AND UPPER(blok_name) = :b LIMIT 1");
        $stmt->execute([':d' => $g_date, ':b' => strtoupper($g_blok_norm)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!empty($row)) {
            $reported_users = parse_reported_users_from_audit($row);
        }
    } catch (Exception $e) {}
}

$min_bytes_threshold = (int)($system_cfg['ghost_min_bytes'] ?? 51200);
$suspects = get_ghost_suspects($db, $g_date, $g_blok_norm, $reported_users, $min_bytes_threshold);

echo json_encode([
    'ok' => true,
    'meta' => [
        'date' => $g_date,
        'blok' => $g_blok_norm,
        'threshold' => format_bytes_short($min_bytes_threshold)
    ],
    'count' => count($suspects),
    'ghosts' => $suspects
]);
