<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../include/acl.php';
if (isset($_SESSION['mikhmon']) && isOperator()) {
    requireSuperAdmin('../../../admin.php?id=sessions');
}

error_reporting(0);
set_time_limit(0);
header('Content-Type: application/json');

$session = $_GET['session'] ?? '';
if ($session === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Missing session']);
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

include($root_dir . '/include/config.php');
if (!isset($data[$session])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Invalid session']);
    exit;
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");

    $db->exec("CREATE TABLE IF NOT EXISTS login_meta_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        voucher_code TEXT,
        customer_name TEXT,
        room_name TEXT,
        blok_name TEXT,
        profile_name TEXT,
        price INTEGER,
        session_id TEXT,
        client_ip TEXT,
        user_agent TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        consumed_at DATETIME,
        consumed_by TEXT
    )");

    try { $db->exec("ALTER TABLE sales_history ADD COLUMN customer_name TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE sales_history ADD COLUMN room_name TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE live_sales ADD COLUMN customer_name TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE live_sales ADD COLUMN room_name TEXT"); } catch (Exception $e) {}

    $stmtMeta = $db->query("SELECT voucher_code, customer_name, room_name FROM login_meta_queue WHERE voucher_code != '' AND (customer_name != '' OR room_name != '') ORDER BY created_at DESC");
    $meta_rows = $stmtMeta->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $updated_login = 0;
    $updated_sales = 0;
    $updated_live = 0;

    $stmtLogin = $db->prepare("UPDATE login_history SET
        customer_name = CASE WHEN (customer_name IS NULL OR customer_name = '') AND :cn != '' THEN :cn ELSE customer_name END,
        room_name = CASE WHEN (room_name IS NULL OR room_name = '') AND :rn != '' THEN :rn ELSE room_name END
        WHERE username = :u");

    $stmtSales = $db->prepare("UPDATE sales_history SET
        customer_name = CASE WHEN (customer_name IS NULL OR customer_name = '') AND :cn != '' THEN :cn ELSE customer_name END,
        room_name = CASE WHEN (room_name IS NULL OR room_name = '') AND :rn != '' THEN :rn ELSE room_name END
        WHERE username = :u");

    $stmtLive = $db->prepare("UPDATE live_sales SET
        customer_name = CASE WHEN (customer_name IS NULL OR customer_name = '') AND :cn != '' THEN :cn ELSE customer_name END,
        room_name = CASE WHEN (room_name IS NULL OR room_name = '') AND :rn != '' THEN :rn ELSE room_name END
        WHERE username = :u");

    foreach ($meta_rows as $row) {
        $u = trim((string)($row['voucher_code'] ?? ''));
        if ($u === '') continue;
        $cn = trim((string)($row['customer_name'] ?? ''));
        $rn = trim((string)($row['room_name'] ?? ''));

        $stmtLogin->execute([':u' => $u, ':cn' => $cn, ':rn' => $rn]);
        $updated_login += $stmtLogin->rowCount();

        $stmtSales->execute([':u' => $u, ':cn' => $cn, ':rn' => $rn]);
        $updated_sales += $stmtSales->rowCount();

        $stmtLive->execute([':u' => $u, ':cn' => $cn, ':rn' => $rn]);
        $updated_live += $stmtLive->rowCount();
    }

    echo json_encode([
        'ok' => true,
        'meta_rows' => count($meta_rows),
        'updated_login' => $updated_login,
        'updated_sales' => $updated_sales,
        'updated_live' => $updated_live
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Gagal backfill.', 'detail' => $e->getMessage()]);
}
