<?php
// tools/build_sales_summary.php
header('Content-Type: application/json');

$dbFile = dirname(__DIR__) . '/db_data/mikhmon_stats.db';

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("CREATE TABLE IF NOT EXISTS sales_summary (
        summary_date TEXT PRIMARY KEY,
        total_sales INTEGER DEFAULT 0,
        total_retur INTEGER DEFAULT 0,
        total_rusak INTEGER DEFAULT 0,
        updated_at TEXT
    )");

    $rows = $db->query("
        SELECT
            DATE(login_time_real) AS summary_date,
            SUM(CASE WHEN status='RETUR' THEN 0 ELSE price END) AS total_sales,
            SUM(CASE WHEN status='RETUR' THEN price ELSE 0 END) AS total_retur,
            SUM(CASE WHEN status='RUSAK' THEN price ELSE 0 END) AS total_rusak
        FROM sales_history
        GROUP BY DATE(login_time_real)
    ")->fetchAll(PDO::FETCH_ASSOC);

    $db->beginTransaction();
    $stmt = $db->prepare("INSERT OR REPLACE INTO sales_summary
        (summary_date, total_sales, total_retur, total_rusak, updated_at)
        VALUES (?, ?, ?, ?, datetime('now'))
    ");

    foreach ($rows as $row) {
        $stmt->execute([
            $row['summary_date'],
            (int)$row['total_sales'],
            (int)$row['total_retur'],
            (int)$row['total_rusak']
        ]);
    }
    $db->commit();

    echo json_encode([
        'status' => 'success',
        'rows' => count($rows)
    ]);
} catch (Exception $e) {
    if ($db && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}