<?php
// Cleanup duplicate sales records caused by relogin
// Keeps the earliest row per (username, sale_date) in sales_history and live_sales

ini_set('display_errors', 0);
error_reporting(0);

$root_dir = dirname(__DIR__);
$dbFile = $root_dir . '/db_data/mikhmon_stats.db';
$logDir = $root_dir . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

if (!file_exists($dbFile)) {
    http_response_code(404);
    echo "DB tidak ditemukan.";
    exit;
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");
    $db->exec("PRAGMA busy_timeout=5000;");

    // Pastikan schema terbaru tersedia
    $db->exec("CREATE TABLE IF NOT EXISTS live_sales (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        raw_date TEXT,
        raw_time TEXT,
        sale_date TEXT,
        sale_time TEXT,
        sale_datetime TEXT,
        username TEXT,
        profile TEXT,
        profile_snapshot TEXT,
        price INTEGER,
        price_snapshot INTEGER,
        sprice_snapshot INTEGER,
        validity TEXT,
        comment TEXT,
        blok_name TEXT,
        status TEXT,
        is_rusak INTEGER,
        is_retur INTEGER,
        is_invalid INTEGER,
        qty INTEGER,
        full_raw_data TEXT UNIQUE,
        sync_status TEXT DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        synced_at DATETIME
    )");
    try { $db->exec("ALTER TABLE sales_history ADD COLUMN raw_time TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE sales_history ADD COLUMN sale_date TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE sales_history ADD COLUMN sale_time TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE sales_history ADD COLUMN sale_datetime TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE sales_history ADD COLUMN profile_snapshot TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE sales_history ADD COLUMN price_snapshot INTEGER"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE sales_history ADD COLUMN sprice_snapshot INTEGER"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE sales_history ADD COLUMN validity TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE sales_history ADD COLUMN status TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE sales_history ADD COLUMN is_rusak INTEGER"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE sales_history ADD COLUMN is_retur INTEGER"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE sales_history ADD COLUMN is_invalid INTEGER"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE sales_history ADD COLUMN qty INTEGER"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE sales_history ADD COLUMN blok_name TEXT"); } catch (Exception $e) {}

    $parse_raw = function($raw) {
        $out = [
            'raw_date' => '',
            'raw_time' => '',
            'username' => '',
            'price' => 0,
            'validity' => '',
            'profile' => '',
            'comment' => ''
        ];
        if (!$raw) return $out;
        $d = explode('-|-', $raw);
        if (count($d) < 4) return $out;
        $out['raw_date'] = $d[0] ?? '';
        $out['raw_time'] = $d[1] ?? '';
        $out['username'] = $d[2] ?? '';
        $out['price'] = (int)($d[3] ?? 0);
        $out['validity'] = $d[6] ?? '';
        $out['profile'] = $d[7] ?? '';
        $out['comment'] = $d[8] ?? '';
        return $out;
    };

    $normalize_sale_date = function($raw_date) {
        if (!$raw_date) return '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw_date)) return $raw_date;
        if (preg_match('/^[a-zA-Z]{3}\/\d{2}\/\d{4}$/', $raw_date)) {
            $mon = strtolower(substr($raw_date, 0, 3));
            $map = [
                'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04', 'may' => '05', 'jun' => '06',
                'jul' => '07', 'aug' => '08', 'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12'
            ];
            $mm = $map[$mon] ?? '';
            if ($mm !== '') {
                $parts = explode('/', $raw_date);
                return $parts[2] . '-' . $mm . '-' . $parts[1];
            }
        }
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $raw_date)) {
            $parts = explode('/', $raw_date);
            return $parts[2] . '-' . $parts[0] . '-' . $parts[1];
        }
        return '';
    };

    // Backfill data lama agar rekap tidak kosong
    $stmtSel = $db->prepare("SELECT id, raw_date, raw_time, username, profile, price, comment, full_raw_data,
        sale_date, sale_time, sale_datetime, profile_snapshot, price_snapshot, sprice_snapshot, validity,
        status, is_rusak, is_retur, is_invalid, qty, blok_name
        FROM sales_history
        WHERE sale_date IS NULL OR sale_date = ''
           OR sale_time IS NULL OR sale_time = ''
           OR profile_snapshot IS NULL OR profile_snapshot = ''
           OR price_snapshot IS NULL");
    $stmtUpd = $db->prepare("UPDATE sales_history SET
        raw_time = :raw_time,
        sale_date = :sale_date,
        sale_time = :sale_time,
        sale_datetime = :sale_datetime,
        profile_snapshot = :profile_snapshot,
        price_snapshot = :price_snapshot,
        sprice_snapshot = :sprice_snapshot,
        validity = :validity,
        status = :status,
        is_rusak = :is_rusak,
        is_retur = :is_retur,
        is_invalid = :is_invalid,
        qty = :qty,
        blok_name = :blok_name
        WHERE id = :id");
    $stmtSel->execute();
    while ($row = $stmtSel->fetch(PDO::FETCH_ASSOC)) {
        $raw = $row['full_raw_data'] ?? '';
        $parsed = $parse_raw($raw);
        $raw_date = $row['raw_date'] ?: ($parsed['raw_date'] ?? '');
        $raw_time = $row['raw_time'] ?: ($parsed['raw_time'] ?? '');
        $sale_date = $row['sale_date'] ?: $normalize_sale_date($raw_date);
        $sale_time = $row['sale_time'] ?: $raw_time;
        $sale_datetime = $row['sale_datetime'] ?: (($sale_date && $sale_time) ? ($sale_date . ' ' . $sale_time) : '');
        $profile_snapshot = $row['profile_snapshot'] ?: ($row['profile'] ?: ($parsed['profile'] ?? ''));
        $price_snapshot = isset($row['price_snapshot']) ? $row['price_snapshot'] : null;
        if ($price_snapshot === null || $price_snapshot === '') {
            $price_snapshot = (int)($row['price'] ?? ($parsed['price'] ?? 0));
        }
        $sprice_snapshot = isset($row['sprice_snapshot']) && $row['sprice_snapshot'] !== '' ? (int)$row['sprice_snapshot'] : 0;
        $validity = $row['validity'] ?: ($parsed['validity'] ?? '');
        $comment = $row['comment'] ?: ($parsed['comment'] ?? '');
        $cmt_low = strtolower((string)$comment);
        $status = strtolower((string)($row['status'] ?? ''));
        if ($status === '' || $status === 'normal') {
            if (strpos($cmt_low, 'invalid') !== false) $status = 'invalid';
            elseif (strpos($cmt_low, 'rusak') !== false) $status = 'rusak';
            elseif (strpos($cmt_low, 'retur') !== false) $status = 'retur';
            else $status = 'normal';
        }
        $is_rusak = ($status === 'rusak') ? 1 : 0;
        $is_retur = ($status === 'retur') ? 1 : 0;
        $is_invalid = ($status === 'invalid') ? 1 : 0;
        $qty = (int)($row['qty'] ?? 0);
        if ($qty <= 0) $qty = 1;
        $blok_name = $row['blok_name'] ?? '';
        if ($blok_name === '' && $comment && preg_match('/\bblok\s*[-_]?\s*([A-Za-z0-9]+)/i', $comment, $m)) {
            $blok_name = 'BLOK-' . strtoupper($m[1]);
        }
        $stmtUpd->execute([
            ':raw_time' => $raw_time,
            ':sale_date' => $sale_date,
            ':sale_time' => $sale_time,
            ':sale_datetime' => $sale_datetime,
            ':profile_snapshot' => $profile_snapshot,
            ':price_snapshot' => $price_snapshot,
            ':sprice_snapshot' => $sprice_snapshot,
            ':validity' => $validity,
            ':status' => $status,
            ':is_rusak' => $is_rusak,
            ':is_retur' => $is_retur,
            ':is_invalid' => $is_invalid,
            ':qty' => $qty,
            ':blok_name' => $blok_name,
            ':id' => $row['id']
        ]);
    }

    $deleted_history = 0;
    $deleted_live = 0;

    $db->exec("BEGIN IMMEDIATE TRANSACTION");

    $delHist = $db->prepare("DELETE FROM sales_history
        WHERE id NOT IN (
            SELECT MIN(id) FROM sales_history
            WHERE username IS NOT NULL AND username <> ''
              AND sale_date IS NOT NULL AND sale_date <> ''
            GROUP BY username, sale_date
        )
        AND username IS NOT NULL AND username <> ''
        AND sale_date IS NOT NULL AND sale_date <> ''");
    $delHist->execute();
    $deleted_history = (int)$delHist->rowCount();

    $delLive = $db->prepare("DELETE FROM live_sales
        WHERE id NOT IN (
            SELECT MIN(id) FROM live_sales
            WHERE username IS NOT NULL AND username <> ''
              AND sale_date IS NOT NULL AND sale_date <> ''
            GROUP BY username, sale_date
        )
        AND username IS NOT NULL AND username <> ''
        AND sale_date IS NOT NULL AND sale_date <> ''");
    $delLive->execute();
    $deleted_live = (int)$delLive->rowCount();

    $db->exec("COMMIT");

    $summary_msg = '';
    $helper = $root_dir . '/report/sales_summary_helper.php';
    if (file_exists($helper)) {
        require_once $helper;
        if (function_exists('rebuild_sales_summary')) {
            try {
                rebuild_sales_summary($db);
                $summary_msg = ' Rekap otomatis diperbarui.';
            } catch (Exception $e) {
                $summary_msg = ' Rekap otomatis gagal diperbarui.';
            }
        }
    }

    $msg = "Cleanup selesai. sales_history dihapus: {$deleted_history}, live_sales dihapus: {$deleted_live}." . $summary_msg;
    @file_put_contents($logDir . '/cleanup_duplicate_sales.log', date('c') . " | " . $msg . "\n", FILE_APPEND);
    echo $msg;
} catch (Exception $e) {
    try { $db->exec("ROLLBACK"); } catch (Exception $e2) {}
    @file_put_contents($logDir . '/cleanup_duplicate_sales.log', date('c') . " | ERROR | " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo "Cleanup gagal.";
}
