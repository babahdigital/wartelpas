<?php
// FILE: report/migrate_sales_reporting.php
// MIGRASI DATABASE UNTUK LAPORAN PENJUALAN (WARTELPAS)
// Jalankan sekali untuk menambahkan kolom-kolom laporan baru pada sales_history.

ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain');

$root_dir = dirname(__DIR__);
$dbFile = $root_dir . '/db_data/mikhmon_stats.db';
if (!file_exists($dbFile)) {
    die("Error: Database tidak ditemukan. Jalankan sync_sales.php terlebih dahulu.\n");
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");
    $db->exec("PRAGMA busy_timeout=2000;");
} catch (PDOException $e) {
    die("Error DB: " . $e->getMessage() . "\n");
}

function column_exists(PDO $db, $table, $column) {
    $stmt = $db->prepare("PRAGMA table_info($table)");
    $stmt->execute();
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        if (isset($c['name']) && $c['name'] === $column) return true;
    }
    return false;
}

function norm_date_from_raw($raw_date) {
    $raw = trim((string)$raw_date);
    if ($raw === '') return '';

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

function status_from_comment($comment) {
    $c = strtolower((string)$comment);
    if (strpos($c, 'invalid') !== false) return 'invalid';
    if (strpos($c, 'rusak') !== false) return 'rusak';
    if (strpos($c, 'retur') !== false) return 'retur';
    return 'normal';
}

// Tambah kolom baru (jika belum ada)
$columns = [
    'raw_time' => 'TEXT',
    'sale_date' => 'TEXT',
    'sale_time' => 'TEXT',
    'sale_datetime' => 'TEXT',
    'validity' => 'TEXT',
    'price_snapshot' => 'INTEGER',
    'sprice_snapshot' => 'INTEGER',
    'profile_snapshot' => 'TEXT',
    'status' => 'TEXT',
    'is_rusak' => 'INTEGER',
    'is_retur' => 'INTEGER',
    'is_invalid' => 'INTEGER',
    'qty' => 'INTEGER'
];

foreach ($columns as $col => $type) {
    if (!column_exists($db, 'sales_history', $col)) {
        $db->exec("ALTER TABLE sales_history ADD COLUMN $col $type");
    }
}

// Index untuk laporan cepat
try { $db->exec("CREATE INDEX IF NOT EXISTS idx_sales_date ON sales_history(sale_date)"); } catch (Exception $e) {}
try { $db->exec("CREATE INDEX IF NOT EXISTS idx_sales_profile ON sales_history(profile_snapshot)"); } catch (Exception $e) {}
try { $db->exec("CREATE INDEX IF NOT EXISTS idx_sales_blok ON sales_history(blok_name)"); } catch (Exception $e) {}
try { $db->exec("CREATE INDEX IF NOT EXISTS idx_sales_status ON sales_history(status)"); } catch (Exception $e) {}

// Backfill data
$rows = $db->query("SELECT id, raw_date, username, profile, price, comment, blok_name, full_raw_data, sale_date, sale_time FROM sales_history");
$rows = $rows ? $rows->fetchAll(PDO::FETCH_ASSOC) : [];

$db->beginTransaction();
$update = $db->prepare("UPDATE sales_history SET
    raw_time = :raw_time,
    sale_date = :sale_date,
    sale_time = :sale_time,
    sale_datetime = :sale_datetime,
    validity = :validity,
    price_snapshot = :price_snapshot,
    sprice_snapshot = :sprice_snapshot,
    profile_snapshot = :profile_snapshot,
    status = :status,
    is_rusak = :is_rusak,
    is_retur = :is_retur,
    is_invalid = :is_invalid,
    qty = :qty
WHERE id = :id");

$count = 0;
foreach ($rows as $r) {
    $raw = $r['full_raw_data'] ?? '';
    $d = $raw ? explode('-|-', $raw) : [];

    $raw_date = $r['raw_date'] ?? ($d[0] ?? '');
    $raw_time = $d[1] ?? '';
    $sale_date = $r['sale_date'] ?: norm_date_from_raw($raw_date);
    $sale_time = $r['sale_time'] ?: ($raw_time ?: '');
    $sale_dt = ($sale_date && $sale_time) ? ($sale_date . ' ' . $sale_time) : '';

    $validity = $d[6] ?? '';
    $profile = $r['profile'] ?? '';
    $profile_snapshot = $profile ?: ($d[7] ?? '');

    $price = (int)($r['price'] ?? 0);
    $status = status_from_comment($r['comment'] ?? '');

    $update->execute([
        ':raw_time' => $raw_time,
        ':sale_date' => $sale_date,
        ':sale_time' => $sale_time,
        ':sale_datetime' => $sale_dt,
        ':validity' => $validity,
        ':price_snapshot' => $price,
        ':sprice_snapshot' => 0,
        ':profile_snapshot' => $profile_snapshot,
        ':status' => $status,
        ':is_rusak' => ($status === 'rusak') ? 1 : 0,
        ':is_retur' => ($status === 'retur') ? 1 : 0,
        ':is_invalid' => ($status === 'invalid') ? 1 : 0,
        ':qty' => 1,
        ':id' => $r['id']
    ]);
    $count++;
}
$db->commit();

echo "Migrasi selesai. Baris diperbarui: $count\n";
?>