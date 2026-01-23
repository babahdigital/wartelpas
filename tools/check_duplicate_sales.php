<?php
// tools/check_duplicate_sales.php
// Cek transaksi ganda (tanpa hapus). Menampilkan username + tanggal + jumlah.
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: text/plain');

$secret_token = "WartelpasSecureKey";
$key = $_GET['key'] ?? ($_POST['key'] ?? '');
if ($key === '' && isset($_SERVER['HTTP_X_WARTELPAS_KEY'])) {
    $key = $_SERVER['HTTP_X_WARTELPAS_KEY'];
}
$key = trim((string)$key);
if ($key === '' || !hash_equals($secret_token, $key)) {
    http_response_code(403);
    die("Error: Token Salah.\n");
}

$session = $_GET['session'] ?? '';
if ($session === '') {
    http_response_code(403);
    die("Error: Session tidak valid.\n");
}

$root_dir = dirname(__DIR__);
require_once($root_dir . '/include/config.php');
if (!isset($data[$session])) {
    http_response_code(403);
    die("Error: Session tidak terdaftar.\n");
}
require_once($root_dir . '/include/readcfg.php');
if (!isset($hotspot_server) || $hotspot_server !== 'wartel') {
    http_response_code(403);
    die("Error: Hanya untuk server wartel.\n");
}

$dbFile = $root_dir . '/db_data/mikhmon_stats.db';
if (!file_exists($dbFile)) {
    http_response_code(500);
    die("DB not found\n");
}

$date = trim((string)($_GET['date'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$mode = strtolower(trim((string)($_GET['mode'] ?? ''))); // all|date|range

$where = "WHERE username != '' AND sale_date != ''";
$params = [];

if ($mode === 'all' || $date === 'all') {
    // no date filter
} elseif ($from !== '' && $to !== '') {
    $where .= " AND sale_date >= :from AND sale_date <= :to";
    $params[':from'] = $from;
    $params[':to'] = $to;
} else {
    if ($date === '') $date = date('Y-m-d');
    $where .= " AND sale_date = :d";
    $params[':d'] = $date;
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sections = [
        'sales_history' => 'sales_history',
        'live_sales' => 'live_sales'
    ];

    foreach ($sections as $label => $table) {
        echo "== DUPLICATE $label ==\n";
        $sql = "SELECT username, sale_date, COUNT(*) AS cnt, GROUP_CONCAT(id) AS ids, GROUP_CONCAT(sale_time) AS times
                FROM {$table}
                {$where}
                GROUP BY username, sale_date
                HAVING cnt > 1
                ORDER BY sale_date DESC, cnt DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            echo "(tidak ada)\n\n";
            continue;
        }
        foreach ($rows as $r) {
            $u = $r['username'] ?? '-';
            $d = $r['sale_date'] ?? '-';
            $c = $r['cnt'] ?? 0;
            $ids = $r['ids'] ?? '';
            $times = $r['times'] ?? '';
            echo "{$d} | {$u} | count={$c} | ids={$ids} | times={$times}\n";
        }
        echo "\n";
    }

    echo "== DUPLICATE COMBINED ==\n";
    $sqlCombo = "SELECT username, sale_date, COUNT(*) AS cnt,
                    GROUP_CONCAT(source || ':' || id) AS items
                FROM (
                    SELECT id, username, sale_date, 'sales' AS source FROM sales_history
                    UNION ALL
                    SELECT id, username, sale_date, 'live' AS source FROM live_sales
                ) t
                {$where}
                GROUP BY username, sale_date
                HAVING cnt > 1
                ORDER BY sale_date DESC, cnt DESC";
    $stmtC = $db->prepare($sqlCombo);
    $stmtC->execute($params);
    $rowsC = $stmtC->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rowsC)) {
        echo "(tidak ada)\n";
    } else {
        foreach ($rowsC as $r) {
            $u = $r['username'] ?? '-';
            $d = $r['sale_date'] ?? '-';
            $c = $r['cnt'] ?? 0;
            $items = $r['items'] ?? '';
            echo "{$d} | {$u} | count={$c} | items={$items}\n";
        }
    }

} catch (Exception $e) {
    http_response_code(500);
    echo "Error\n";
}
