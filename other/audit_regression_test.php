<?php
// Simple regression test for audit vs system net.
// Usage (CLI): php other/audit_regression_test.php 2026-01-30

$root_dir = dirname(__DIR__);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
$system_cfg = $env['system'] ?? [];
$db_rel = $system_cfg['db_file'] ?? 'db_data/babahdigital_main.db';
if (preg_match('/^[A-Za-z]:\\\\|^\//', $db_rel)) {
    $dbFile = $db_rel;
} else {
    $dbFile = $root_dir . '/' . ltrim($db_rel, '/');
}

$date = $argv[1] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo "Tanggal tidak valid. Gunakan format YYYY-MM-DD\n";
    exit(1);
}
if (!file_exists($dbFile)) {
    echo "DB tidak ditemukan: {$dbFile}\n";
    exit(1);
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo "DB error: " . $e->getMessage() . "\n";
    exit(1);
}

$raw1 = $date . '%';
$raw2 = date('m/d/Y', strtotime($date)) . '%';
$raw3 = date('d/m/Y', strtotime($date)) . '%';
$raw4 = date('M/d/Y', strtotime($date)) . '%';
$raw5 = $date . '%';

$sumSql = "SELECT
    SUM(CASE WHEN eff_status='invalid' THEN eff_price * eff_qty ELSE 0 END) AS invalid_sum,
    SUM(CASE WHEN eff_status='rusak' THEN eff_price * eff_qty ELSE 0 END) AS rusak_sum,
    SUM(CASE WHEN eff_status='retur' THEN eff_price * eff_qty ELSE 0 END) AS retur_sum,
    SUM(eff_price * eff_qty) AS gross_sum,
    COUNT(1) AS total_cnt
    FROM (
        SELECT
            CASE
                WHEN COALESCE(sh.is_retur,0)=1
                    OR LOWER(COALESCE(sh.status,''))='retur'
                    OR LOWER(COALESCE(sh.comment,'')) LIKE '%retur%'
                    THEN 'retur'
                WHEN COALESCE(sh.is_rusak,0)=1
                    OR LOWER(COALESCE(sh.status,''))='rusak'
                    OR LOWER(COALESCE(sh.comment,'')) LIKE '%rusak%'
                    THEN 'rusak'
                WHEN COALESCE(sh.is_invalid,0)=1
                    OR LOWER(COALESCE(sh.status,''))='invalid'
                    OR LOWER(COALESCE(sh.comment,'')) LIKE '%invalid%'
                    THEN 'invalid'
                ELSE 'normal'
            END AS eff_status,
            COALESCE(sh.price_snapshot, sh.price, 0) AS eff_price,
            COALESCE(sh.qty,1) AS eff_qty
        FROM sales_history sh
        WHERE (sh.sale_date = :d OR sh.raw_date LIKE :raw1 OR sh.raw_date LIKE :raw2 OR sh.raw_date LIKE :raw3 OR sh.raw_date LIKE :raw4 OR sh.raw_date LIKE :raw5)
          AND instr(lower(COALESCE(sh.comment,'')), 'vip') = 0
    ) t";

$stmt = $db->prepare($sumSql);
$stmt->execute([
    ':d' => $date,
    ':raw1' => $raw1,
    ':raw2' => $raw2,
    ':raw3' => $raw3,
    ':raw4' => $raw4,
    ':raw5' => $raw5
]);
$sh = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$raw_gross = (int)($sh['gross_sum'] ?? 0);
$invalid_rp = (int)($sh['invalid_sum'] ?? 0);
$rusak_rp = (int)($sh['rusak_sum'] ?? 0);
$net_sh = $raw_gross - $invalid_rp - $rusak_rp;

$sumSqlLive = str_replace('sales_history sh', 'live_sales ls', $sumSql);
$sumSqlLive = str_replace('sh.', 'ls.', $sumSqlLive);
$stmt = $db->prepare($sumSqlLive);
$stmt->execute([
    ':d' => $date,
    ':raw1' => $raw1,
    ':raw2' => $raw2,
    ':raw3' => $raw3,
    ':raw4' => $raw4,
    ':raw5' => $raw5
]);
$ls = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$raw_gross_l = (int)($ls['gross_sum'] ?? 0);
$invalid_l = (int)($ls['invalid_sum'] ?? 0);
$rusak_l = (int)($ls['rusak_sum'] ?? 0);
$net_ls = $raw_gross_l - $invalid_l - $rusak_l;

$system_net = $net_sh + $net_ls;

$stmt = $db->prepare("SELECT SUM(expected_setoran) AS total FROM audit_rekap_manual WHERE report_date = :d");
$stmt->execute([':d' => $date]);
$expected = (int)($stmt->fetchColumn() ?: 0);

$diff = $expected - $system_net;
$line = date('Y-m-d H:i:s') . "\t" . $date . "\t" . $system_net . "\t" . $expected . "\t" . $diff . "\n";

$logDir = $root_dir . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
@file_put_contents($logDir . '/audit_regression.log', $line, FILE_APPEND | LOCK_EX);

echo "Tanggal: {$date}\n";
echo "System Net (Final+Live): {$system_net}\n";
echo "Audit Expected (Total): {$expected}\n";
echo "Selisih (Audit - System): {$diff}\n";
?>
