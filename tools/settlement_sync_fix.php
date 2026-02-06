<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';

if (!isset($_SESSION['mikhmon'])) {
    http_response_code(401);
    echo "Gagal: Unauthorized";
    exit;
}
if (isOperator() && !operator_can('sync_sales_force')) {
    http_response_code(403);
    echo "Gagal: Akses ditolak";
    exit;
}

$force = isset($_GET['force']) && $_GET['force'] === '1';

$root_dir = dirname(__DIR__);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
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

    $sales_cols = $db->query("PRAGMA table_info(sales_history)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $sales_names = array_map(function($c){ return $c['name'] ?? ''; }, $sales_cols);
    $sales_col = in_array('sync_date', $sales_names, true)
        ? 'sync_date'
        : (in_array('created_at', $sales_names, true) ? 'created_at' : 'sale_datetime');

    $max_sales = (string)$db->query("SELECT MAX($sales_col) FROM sales_history")->fetchColumn();

    $rows = $db->query("SELECT report_date, sales_sync_at, message FROM settlement_log WHERE instr(message, 'SYNC SALES: GAGAL') > 0 ORDER BY report_date")
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $updated = 0;
    foreach ($rows as $r) {
        $report_date = (string)($r['report_date'] ?? '');
        $sales_sync_at = (string)($r['sales_sync_at'] ?? '');
        $message = (string)($r['message'] ?? '');

        $should_update = false;
        if ($force) {
            $should_update = true;
        } elseif ($max_sales !== '' && $sales_sync_at === '') {
            $should_update = true;
        } elseif ($max_sales !== '' && $sales_sync_at !== '') {
            $ts_sales = strtotime($max_sales);
            $ts_log = strtotime($sales_sync_at);
            if ($ts_sales && $ts_log && $ts_sales > $ts_log) {
                $should_update = true;
            }
        }

        if ($should_update && $report_date !== '') {
            $suffix = $force ? 'SYNC SALES: OK (FORCE)' : 'SYNC SALES: OK';
            $new_message = str_replace('SYNC SALES: GAGAL', $suffix, $message);
            $stmt = $db->prepare("UPDATE settlement_log
                SET sales_sync_at = COALESCE(sales_sync_at, :max_sales, CURRENT_TIMESTAMP),
                    message = :msg
                WHERE report_date = :d");
            $stmt->execute([
                ':max_sales' => $max_sales,
                ':msg' => $new_message,
                ':d' => $report_date
            ]);
            $updated += $stmt->rowCount();
        }
    }

    if ($updated > 0) {
        echo "OK";
    } else {
        echo "Gagal: Belum ada sync sales baru";
    }
} catch (Exception $e) {
    http_response_code(500);
    echo "Gagal: " . $e->getMessage();
}