<?php
session_start();
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: text/plain');

$secret_token = 'WartelpasSecureKey';
if (!isset($_GET['key']) || $_GET['key'] !== $secret_token) {
    die("Error: Token Salah.\n");
}

$root_dir = dirname(__DIR__);
$dbFile = $root_dir . '/db_data/mikhmon_stats.db';
if (!file_exists($dbFile)) {
    die("Error: Database tidak ditemukan.\n");
}

function normalize_sale_date($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m)) {
        return $m[1];
    }
    if (preg_match('/^[a-zA-Z]{3}\/\d{2}\/\d{4}$/', $raw)) {
        $mon = strtolower(substr($raw, 0, 3));
        $map = [
            'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04', 'may' => '05', 'jun' => '06',
            'jul' => '07', 'aug' => '08', 'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12'
        ];
        $mm = $map[$mon] ?? '';
        if ($mm !== '') {
            $parts = explode('/', $raw);
            return $parts[2] . '-' . $mm . '-' . $parts[1];
        }
    }
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $raw)) {
        $parts = explode('/', $raw);
        return $parts[2] . '-' . $parts[0] . '-' . $parts[1];
    }
    return '';
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $db->query("SELECT id, raw_date, sale_date FROM sales_history WHERE sale_date IS NULL OR sale_date = ''");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $updated = 0;
    $skipped = 0;

    $upd = $db->prepare("UPDATE sales_history SET sale_date = :sd WHERE id = :id");

    foreach ($rows as $r) {
        $raw = $r['raw_date'] ?? '';
        $sd = normalize_sale_date($raw);
        if ($sd === '') {
            $skipped++;
            continue;
        }
        $upd->execute([
            ':sd' => $sd,
            ':id' => (int)$r['id']
        ]);
        $updated++;
    }

    echo "OK: updated={$updated}, skipped={$skipped}\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
