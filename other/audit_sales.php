<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
// Audit sales duplication & relogin impact (protected)
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: text/html; charset=utf-8');

$root = dirname(__DIR__);
$env = [];
$envFile = $root . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
require_once $root . '/include/db_helpers.php';
$secret = $env['security']['tools']['token'] ?? ($env['backup']['secret'] ?? '');
$key = $_GET['key'] ?? '';
if ($key === '' && isset($_POST['key'])) {
    $key = (string)$_POST['key'];
}
if ($key === '' && isset($_SERVER['HTTP_X_TOOLS_KEY'])) {
    $key = (string)$_SERVER['HTTP_X_TOOLS_KEY'];
}
if ($key === '' && isset($_SERVER['HTTP_X_BACKUP_KEY'])) {
    $key = (string)$_SERVER['HTTP_X_BACKUP_KEY'];
}
$is_valid_key = $secret !== '' && hash_equals($secret, (string)$key);

if (!$is_valid_key) {
    requireLogin('../admin.php?id=login');
    requireSuperAdmin('../admin.php?id=sessions');
} else {
    if (!isset($_SESSION['mikhmon'])) {
        $_SESSION['mikhmon'] = 'tools';
        $_SESSION['mikhmon_level'] = 'superadmin';
    }
}

if (!$is_valid_key) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$dbFile = get_stats_db_path();
if (!file_exists($dbFile)) {
    echo 'DB not found';
    exit;
}

$limit = isset($_GET['limit']) ? max(10, (int)$_GET['limit']) : 100;
$date = $_GET['date'] ?? '';
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $whereDate = $date !== '' ? 'WHERE sale_date = :d' : '';

    $dupRawSql = "SELECT sale_date, COUNT(*) AS cnt
        FROM sales_history
        WHERE full_raw_data IS NOT NULL AND full_raw_data != ''" . ($date !== '' ? " AND sale_date = :d" : "") .
        " GROUP BY full_raw_data
        HAVING cnt > 1
        ORDER BY cnt DESC, sale_date DESC
        LIMIT :lim";
    $stmt = $db->prepare($dupRawSql);
    if ($date !== '') $stmt->bindValue(':d', $date);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $dupRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $dupUserDateSql = "SELECT sale_date, username, COUNT(*) AS cnt
        FROM sales_history
        " . ($date !== '' ? "WHERE sale_date = :d" : "") .
        " GROUP BY sale_date, username
        HAVING cnt > 1
        ORDER BY cnt DESC, sale_date DESC
        LIMIT :lim";
    $stmt = $db->prepare($dupUserDateSql);
    if ($date !== '') $stmt->bindValue(':d', $date);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $dupUserDate = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $reloginSql = "SELECT lh.username, lh.last_bytes, lh.last_status, lh.login_count, lh.last_login_real
        FROM login_history lh
        WHERE lh.login_count > 1
        ORDER BY lh.login_count DESC
        LIMIT :lim";
    $stmt = $db->prepare($reloginSql);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $relogin = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $salesByUserSql = "SELECT sale_date, username, status, price, qty, full_raw_data
        FROM sales_history
        " . ($date !== '' ? "WHERE sale_date = :d" : "") .
        " ORDER BY sale_date DESC, username ASC
        LIMIT :lim";
    $stmt = $db->prepare($salesByUserSql);
    if ($date !== '') $stmt->bindValue(':d', $date);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $salesSample = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo '<h3>Audit Sales & Relogin</h3>';
    echo '<p>DB: ' . htmlspecialchars($dbFile) . '</p>';
    echo '<p>Filter date: ' . ($date !== '' ? htmlspecialchars($date) : 'ALL') . '</p>';

    echo '<h4>Duplicate full_raw_data (sales_history)</h4>';
    if (empty($dupRaw)) {
        echo '<p>OK (no duplicates)</p>';
    } else {
        echo '<table border="1" cellpadding="6" cellspacing="0"><tr><th>Sale Date</th><th>Count</th></tr>';
        foreach ($dupRaw as $r) {
            echo '<tr><td>' . htmlspecialchars($r['sale_date'] ?? '-') . '</td><td>' . (int)($r['cnt'] ?? 0) . '</td></tr>';
        }
        echo '</table>';
    }

    echo '<h4>Duplicate username+sale_date (sales_history)</h4>';
    if (empty($dupUserDate)) {
        echo '<p>OK (no duplicates)</p>';
    } else {
        echo '<table border="1" cellpadding="6" cellspacing="0"><tr><th>Sale Date</th><th>Username</th><th>Count</th></tr>';
        foreach ($dupUserDate as $r) {
            echo '<tr><td>' . htmlspecialchars($r['sale_date'] ?? '-') . '</td><td>' . htmlspecialchars($r['username'] ?? '-') . '</td><td>' . (int)($r['cnt'] ?? 0) . '</td></tr>';
        }
        echo '</table>';
    }

    echo '<h4>Relogin Users (login_count > 1)</h4>';
    if (empty($relogin)) {
        echo '<p>OK (no relogin)</p>';
    } else {
        echo '<table border="1" cellpadding="6" cellspacing="0"><tr><th>Username</th><th>Login Count</th><th>Last Status</th><th>Last Bytes</th><th>Last Login</th></tr>';
        foreach ($relogin as $r) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($r['username'] ?? '-') . '</td>';
            echo '<td>' . (int)($r['login_count'] ?? 0) . '</td>';
            echo '<td>' . htmlspecialchars($r['last_status'] ?? '-') . '</td>';
            echo '<td>' . (int)($r['last_bytes'] ?? 0) . '</td>';
            echo '<td>' . htmlspecialchars($r['last_login_real'] ?? '-') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    echo '<h4>Sales Sample</h4>';
    echo '<table border="1" cellpadding="6" cellspacing="0"><tr><th>Date</th><th>User</th><th>Status</th><th>Price</th><th>Qty</th><th>Raw</th></tr>';
    foreach ($salesSample as $r) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['sale_date'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($r['username'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($r['status'] ?? '-') . '</td>';
        echo '<td>' . (int)($r['price'] ?? 0) . '</td>';
        echo '<td>' . (int)($r['qty'] ?? 0) . '</td>';
        echo '<td style="max-width:420px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' . htmlspecialchars($r['full_raw_data'] ?? '-') . '</td>';
        echo '</tr>';
    }
    echo '</table>';

} catch (Exception $e) {
    http_response_code(500);
    echo 'DB error';
}
