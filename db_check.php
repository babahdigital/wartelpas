<?php
// Simple DB check endpoint (protected)
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: text/html; charset=utf-8');

$secret = 'WartelpasSecureKey';
$key = $_GET['key'] ?? '';
if ($key !== $secret) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

$dbFile = __DIR__ . '/db_data/mikhmon_stats.db';
if (!file_exists($dbFile)) {
    echo "DB not found";
    exit;
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $cols = [];
    foreach ($db->query("PRAGMA table_info(login_history)") as $row) {
        $cols[] = $row['name'];
    }

    $wanted = [
        'username','login_time_real','logout_time_real','last_status','updated_at',
        'login_count','first_login_real','last_login_real','last_uptime','last_bytes','ip_address','mac_address'
    ];
    $selectCols = array_values(array_intersect($wanted, $cols));
    if (empty($selectCols)) {
        echo "login_history has no expected columns";
        exit;
    }

    $sql = "SELECT " . implode(',', $selectCols) . " FROM login_history ORDER BY updated_at DESC LIMIT 50";
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    echo "<h3>login_history (latest 50)</h3>";
    echo "<p>Columns: " . htmlspecialchars(implode(', ', $cols)) . "</p>";
    echo "<table border='1' cellspacing='0' cellpadding='6'>";
    echo "<thead><tr>";
    foreach ($selectCols as $c) {
        echo "<th>" . htmlspecialchars($c) . "</th>";
    }
    echo "</tr></thead><tbody>";
    foreach ($rows as $r) {
        echo "<tr>";
        foreach ($selectCols as $c) {
            $val = $r[$c] ?? '';
            echo "<td>" . htmlspecialchars((string)$val) . "</td>";
        }
        echo "</tr>";
    }
    echo "</tbody></table>";
} catch (Exception $e) {
    http_response_code(500);
    echo "DB error";
}
