<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../include/acl.php';
require_once __DIR__ . '/../include/db.php';
ensureRole();

if (!isset($_SESSION['mikhmon'])) {
    http_response_code(401);
    echo "Gagal: Unauthorized";
    exit;
}

if (isOperator() && !operator_can('audit_manual')) {
    http_response_code(403);
    echo "Gagal: Akses ditolak";
    exit;
}

$date = trim((string)($_GET['date'] ?? ''));
$session = trim((string)($_GET['session'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo "Gagal: Tanggal tidak valid";
    exit;
}

$root_dir = dirname(__DIR__);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}

$helpersFile = $root_dir . '/report/laporan/helpers.php';
if (!file_exists($helpersFile)) {
    http_response_code(500);
    echo "Gagal: Helper audit tidak ditemukan";
    exit;
}
require_once $helpersFile;

if (!function_exists('rebuild_audit_expected_for_date')) {
    http_response_code(500);
    echo "Gagal: Fitur rebuild tidak tersedia";
    exit;
}

$system_cfg = $env['system'] ?? [];
$db_rel = $system_cfg['db_file'] ?? 'db_data/babahdigital_main.db';
$dbFile = preg_match('/^[A-Za-z]:\\|^\//', $db_rel) ? $db_rel : ($root_dir . '/' . ltrim($db_rel, '/'));
if (!is_file($dbFile)) {
    http_response_code(500);
    echo "Gagal: DB tidak ditemukan";
    exit;
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if (function_exists('table_exists') && !table_exists($db, 'audit_rekap_manual')) {
        echo "Gagal: Tabel audit belum tersedia";
        exit;
    }

    $stmtCount = $db->prepare("SELECT COUNT(*) FROM audit_rekap_manual WHERE report_date = :d AND COALESCE(expected_setoran,0)=0 AND COALESCE(actual_setoran,0)>0");
    $stmtCount->execute([':d' => $date]);
    $before_zero = (int)$stmtCount->fetchColumn();

    $updated = (int)rebuild_audit_expected_for_date($db, $date);

    $stmtCount->execute([':d' => $date]);
    $after_zero = (int)$stmtCount->fetchColumn();

    $result = 'success';
    if ($after_zero > 0) {
        $result = 'failed';
        $message = 'Gagal: Rebuild selesai ' . $updated . ' blok, tetapi masih ada ' . $after_zero . ' blok target sistem 0.';
    } elseif ($before_zero > 0 && $updated <= 0) {
        $result = 'failed';
        $message = 'Gagal: Tidak ada data sumber untuk rebuild pada tanggal ini.';
    } else {
        $message = 'OK: Rebuild Target Sistem selesai (' . $updated . ' blok).';
    }

    if (function_exists('app_audit_log')) {
        app_audit_log('todo_action', 'audit_rebuild_' . $date, 'Rebuild target sistem via todo.', $result, [
            'session' => $session,
            'date' => $date,
            'updated_blocks' => $updated,
            'before_zero' => $before_zero,
            'after_zero' => $after_zero
        ]);
    }

    echo $message;
} catch (Exception $e) {
    http_response_code(500);
    echo 'Gagal: ' . $e->getMessage();
}
