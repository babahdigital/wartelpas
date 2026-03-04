<?php
$_SERVER['REQUEST_URI'] = '/cli';
$root = '/var/www/html';

$env = [];
$envFile = $root . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
$pricing = $env['pricing'] ?? [];
$GLOBALS['price10'] = (int)($pricing['price_10'] ?? 0);
$GLOBALS['price30'] = (int)($pricing['price_30'] ?? 0);
$GLOBALS['profile_price_map'] = $pricing['profile_prices'] ?? [];

require_once $root . '/report/laporan/helpers.php';

$date = isset($argv[1]) ? trim((string)$argv[1]) : date('Y-m-d');
$targetBlock = isset($argv[2]) ? normalize_block_name((string)$argv[2]) : '';

$db = new PDO('sqlite:' . $root . '/db_data/babahdigital_main.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$rows = fetch_rows_for_audit($db, $date);
echo 'DATE|' . $date . "\n";
echo 'ROWS|' . count($rows) . "\n";

$blocks = [];
$stmt = $db->prepare('SELECT blok_name FROM audit_rekap_manual WHERE report_date = :d ORDER BY blok_name');
$stmt->execute([':d' => $date]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $b = normalize_block_name($r['blok_name'] ?? '');
    if ($b !== '') {
        $blocks[$b] = true;
    }
}
if ($targetBlock !== '') {
    $blocks = [$targetBlock => true];
}

foreach (array_keys($blocks) as $b) {
    $ex = calc_expected_for_block($rows, $date, $b);
    echo 'EXPECTED|' . $b
        . '|qty=' . (int)($ex['qty'] ?? 0)
        . '|raw_qty=' . (int)($ex['raw_qty'] ?? 0)
        . '|rusak=' . (int)($ex['rusak_qty'] ?? 0)
        . '|retur=' . (int)($ex['retur_qty'] ?? 0)
        . '|invalid=' . (int)($ex['invalid_qty'] ?? 0)
        . '|net=' . (int)($ex['net'] ?? 0)
        . "\n";
}

if ($targetBlock !== '') {
    $b = $targetBlock;
    $i = 0;
    foreach ($rows as $r) {
        $saleDate = $r['sale_date'] ?: norm_date_from_raw_report($r['raw_date'] ?? '');
        if ($saleDate !== $date) continue;
        $rawComment = (string)($r['comment'] ?? '');
        $blok = normalize_block_name($r['blok_name'] ?? '', $rawComment);
        if ($blok !== $b) continue;

        $status = resolve_status_from_sources(
            $r['status'] ?? '',
            $r['is_invalid'] ?? 0,
            $r['is_retur'] ?? 0,
            $r['is_rusak'] ?? 0,
            $rawComment,
            $r['last_status'] ?? ''
        );

        $price = (int)($r['price_snapshot'] ?? $r['price'] ?? 0);
        if ($price <= 0) $price = (int)($r['sprice_snapshot'] ?? 0);
        $profile = (string)($r['profile_snapshot'] ?? ($r['profile'] ?? ''));
        if ($price <= 0) {
            $price = resolve_price_from_profile($profile);
        }

        $qty = (int)($r['qty'] ?? 0);
        if ($qty <= 0) $qty = 1;

        $i++;
        echo 'ROW|' . $i
            . '|user=' . (string)($r['username'] ?? '')
            . '|status=' . $status
            . '|profile=' . $profile
            . '|price=' . $price
            . '|qty=' . $qty
            . '|line=' . ($price * $qty)
            . '|raw=' . (string)($r['full_raw_data'] ?? '')
            . "\n";
    }
}
