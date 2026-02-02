<?php
session_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

if (!isset($_SESSION["mikhmon"])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

$root_dir = dirname(__DIR__, 2);
require_once $root_dir . '/report/laporan/helpers.php';
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
    echo json_encode(['ok' => false, 'message' => 'Database file not found.']);
    exit;
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA query_only=1;");
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'message' => 'Database connection failed.']);
    exit;
}

$g_date = trim((string)($_GET['date'] ?? ''));
$g_blok = trim((string)($_GET['blok'] ?? ''));
$g_blok_norm = function_exists('normalize_block_name') ? normalize_block_name($g_blok) : $g_blok;
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
        if (!empty($row) && function_exists('parse_reported_users_from_audit')) {
            $reported_users = parse_reported_users_from_audit($row);
        }
    } catch (Exception $e) {}
}

$min_bytes_threshold = (int)($system_cfg['ghost_min_bytes'] ?? 51200);
$min_uptime_threshold = (int)($system_cfg['ghost_min_uptime'] ?? 0);
if ($min_uptime_threshold > 0 && $min_uptime_threshold <= 300) {
    $min_uptime_threshold = $min_uptime_threshold * 60;
}
$exclude_close_window = false;
if (isset($system_cfg['ghost_exclude_close_window'])) {
    $val = $system_cfg['ghost_exclude_close_window'];
    $exclude_close_window = ($val === true || $val === 1 || $val === '1' || $val === 'true');
}
$suspects = function_exists('get_ghost_suspects')
    ? get_ghost_suspects($db, $g_date, $g_blok_norm, $reported_users, $min_bytes_threshold, $min_uptime_threshold, $exclude_close_window)
    : [];

echo json_encode([
    'ok' => true,
    'meta' => [
        'date' => $g_date,
        'blok' => $g_blok_norm,
        'threshold' => format_bytes_short($min_bytes_threshold),
        'min_uptime' => $min_uptime_threshold,
        'exclude_close_window' => $exclude_close_window,
        'whitelist_count' => count($reported_users)
    ],
    'count' => count($suspects),
    'ghosts' => $suspects
]);
