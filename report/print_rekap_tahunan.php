<?php
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

include('../include/config.php');
include('../include/readcfg.php');

$dbFile = dirname(__DIR__) . '/db_data/mikhmon_stats.db';
$cur = isset($currency) ? $currency : 'Rp';
$session_id = $_GET['session'] ?? '';

$filter_year = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}$/', $filter_year)) {
    $filter_year = date('Y');
}

function esc($s){ return htmlspecialchars((string)$s); }

function norm_date_from_raw_report($raw_date) {
    $raw = trim((string)$raw_date);
    if ($raw === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
        return substr($raw, 0, 10);
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

function format_bytes_short($bytes) {
    $b = (float)$bytes;
    if ($b <= 0) return '-';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < count($units) - 1) {
        $b /= 1024;
        $i++;
    }
    $dec = $i >= 2 ? 2 : 0;
    return number_format($b, $dec, ',', '.') . ' ' . $units[$i];
}

function month_label_id($mm) {
    $months = [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
        '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
        '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
    ];
    return $months[$mm] ?? $mm;
}

function calc_audit_adjusted_setoran(array $ar) {
    $price10 = 5000;
    $price30 = 20000;
    $expected_setoran = (int)($ar['expected_setoran'] ?? 0);
    $actual_setoran_raw = (int)($ar['actual_setoran'] ?? 0);

    $p10_qty = 0;
    $p30_qty = 0;
    $cnt_rusak_10 = 0;
    $cnt_rusak_30 = 0;
    $cnt_retur_10 = 0;
    $cnt_retur_30 = 0;
    $cnt_invalid_10 = 0;
    $cnt_invalid_30 = 0;
    $profile10_users = 0;
    $profile30_users = 0;
    $has_manual_evidence = false;

    if (!empty($ar['user_evidence'])) {
        $evidence = json_decode((string)$ar['user_evidence'], true);
        if (is_array($evidence)) {
            $has_manual_evidence = true;
            if (!empty($evidence['profile_qty']) && is_array($evidence['profile_qty'])) {
                $p10_qty = (int)($evidence['profile_qty']['qty_10'] ?? 0);
                $p30_qty = (int)($evidence['profile_qty']['qty_30'] ?? 0);
            }
            if (!empty($evidence['users']) && is_array($evidence['users'])) {
                foreach ($evidence['users'] as $ud) {
                    $kind = (string)($ud['profile_kind'] ?? '10');
                    $status = strtolower((string)($ud['last_status'] ?? ''));
                    if ($kind === '30') {
                        $profile30_users++;
                        if ($status === 'invalid') $cnt_invalid_30++;
                        elseif ($status === 'retur') $cnt_retur_30++;
                        elseif ($status === 'rusak') $cnt_rusak_30++;
                    } else {
                        $profile10_users++;
                        if ($status === 'invalid') $cnt_invalid_10++;
                        elseif ($status === 'retur') $cnt_retur_10++;
                        elseif ($status === 'rusak') $cnt_rusak_10++;
                    }
                }
            }
        }
    }

    if ($p10_qty <= 0) $p10_qty = $profile10_users;
    if ($p30_qty <= 0) $p30_qty = $profile30_users;

    if ($has_manual_evidence) {
        $manual_net_qty_10 = max(0, $p10_qty - $cnt_rusak_10 - $cnt_invalid_10 + $cnt_retur_10);
        $manual_net_qty_30 = max(0, $p30_qty - $cnt_rusak_30 - $cnt_invalid_30 + $cnt_retur_30);
        $manual_display_setoran = ($manual_net_qty_10 * $price10) + ($manual_net_qty_30 * $price30);
        $expected_adj_setoran = $expected_setoran;
    } else {
        $manual_display_setoran = $actual_setoran_raw;
        $expected_adj_setoran = $expected_setoran;
    }

    return [$manual_display_setoran, $expected_adj_setoran];
}

$rows = [];
$daily = [];
$audit_dates_in_daily = [];
$phone = [];
$phone_units = [];
$audit_net = [];
$audit_selisih = [];
$audit_system = [];
$total_expenses_year = 0;

try {
    if (file_exists($dbFile)) {
        $db = new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $res = $db->query("SELECT 
                sh.raw_date, sh.raw_time, sh.sale_date, sh.sale_time, sh.sale_datetime,
                sh.username, sh.profile, sh.profile_snapshot,
                sh.price, sh.price_snapshot, sh.sprice_snapshot, sh.validity,
                sh.comment, sh.blok_name, sh.status, sh.is_rusak, sh.is_retur, sh.is_invalid, sh.qty,
                sh.full_raw_data, lh.last_status, lh.last_bytes
            FROM sales_history sh
            LEFT JOIN login_history lh ON lh.username = sh.username
            UNION ALL
            SELECT 
                ls.raw_date, ls.raw_time, ls.sale_date, ls.sale_time, ls.sale_datetime,
                ls.username, ls.profile, ls.profile_snapshot,
                ls.price, ls.price_snapshot, ls.sprice_snapshot, ls.validity,
                ls.comment, ls.blok_name, ls.status, ls.is_rusak, ls.is_retur, ls.is_invalid, ls.qty,
                ls.full_raw_data, lh2.last_status, lh2.last_bytes
            FROM live_sales ls
            LEFT JOIN login_history lh2 ON lh2.username = ls.username
            WHERE ls.sync_status = 'pending'
            ORDER BY sale_datetime DESC, raw_date DESC");
        if ($res) $rows = $res->fetchAll(PDO::FETCH_ASSOC);

        $stmtPhone = $db->prepare("SELECT report_date,
                SUM(total_units) AS total_units,
                SUM(active_units) AS active_units,
                SUM(rusak_units) AS rusak_units,
                SUM(spam_units) AS spam_units
            FROM phone_block_daily
            WHERE report_date LIKE :y AND unit_type = 'TOTAL'
            GROUP BY report_date");
        $stmtPhone->execute([':y' => $filter_year . '%']);
        foreach ($stmtPhone->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $phone[$row['report_date']] = [
                'total' => (int)($row['total_units'] ?? 0),
                'active' => (int)($row['active_units'] ?? 0),
                'rusak' => (int)($row['rusak_units'] ?? 0),
                'spam' => (int)($row['spam_units'] ?? 0)
            ];
        }

        $stmtUnit = $db->prepare("SELECT report_date, unit_type, SUM(total_units) AS total_units
            FROM phone_block_daily
            WHERE report_date LIKE :y AND unit_type IN ('WARTEL','KAMTIB')
            GROUP BY report_date, unit_type");
        $stmtUnit->execute([':y' => $filter_year . '%']);
        foreach ($stmtUnit->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $d = $row['report_date'];
            if (!isset($phone_units[$d])) $phone_units[$d] = ['WARTEL' => 0, 'KAMTIB' => 0];
            $ut = strtoupper((string)($row['unit_type'] ?? ''));
            $phone_units[$d][$ut] = (int)($row['total_units'] ?? 0);
        }

        $stmtAudit = $db->prepare("SELECT report_date, expected_setoran, actual_setoran, user_evidence, expenses_amt
            FROM audit_rekap_manual
            WHERE report_date LIKE :y");
            $stmtAudit->execute([':y' => $filter_year . '%']);
            foreach ($stmtAudit->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $d = $row['report_date'] ?? '';
                if ($d === '') continue;
                [$manual_setoran, $expected_adj_setoran] = calc_audit_adjusted_setoran($row);
                $expense = (int)($row['expenses_amt'] ?? 0);
                $mm = substr($d, 5, 2);
                if (isset($months[$mm])) {
                    $months[$mm]['expenses'] += $expense;
                }
                $total_expenses_year += $expense;
                $net_cash_audit = (int)$manual_setoran - $expense;
                $audit_net[$d] = (int)($audit_net[$d] ?? 0) + $net_cash_audit;
                $audit_system[$d] = (int)($audit_system[$d] ?? 0) + (int)$expected_adj_setoran;
                $audit_selisih[$d] = (int)($audit_selisih[$d] ?? 0) + ((int)$manual_setoran - (int)$expected_adj_setoran);
        }
    }
} catch (Exception $e) {
    $rows = [];
}

$seen_sales = [];
$seen_user_day = [];
foreach ($rows as $r) {
    $sale_date = $r['sale_date'] ?: norm_date_from_raw_report($r['raw_date'] ?? '');
    if ($sale_date === '' || strpos((string)$sale_date, $filter_year) !== 0) continue;

    $username = $r['username'] ?? '';
    if ($username !== '' && $sale_date !== '') {
        $user_day_key = $username . '|' . $sale_date;
        if (isset($seen_user_day[$user_day_key])) continue;
        $seen_user_day[$user_day_key] = true;
    }
    $raw_key = trim((string)($r['full_raw_data'] ?? ''));
    $unique_key = '';
    if ($raw_key !== '') {
        $unique_key = 'raw|' . $raw_key;
    } elseif ($username !== '' && $sale_date !== '') {
        $unique_key = $username . '|' . ($r['sale_datetime'] ?? ($sale_date . ' ' . ($r['sale_time'] ?? '')));
        if ($unique_key === $username . '|') {
            $unique_key = $username . '|' . $sale_date . '|' . ($r['sale_time'] ?? '');
        }
    }
    if ($unique_key !== '' && isset($seen_sales[$unique_key])) continue;
    if ($unique_key !== '') $seen_sales[$unique_key] = true;

    $status = strtolower((string)($r['status'] ?? ''));
    $lh_status = strtolower((string)($r['last_status'] ?? ''));
    $comment = strtolower((string)($r['comment'] ?? ''));
    if ($status === '' || $status === 'normal') {
        if ((int)($r['is_invalid'] ?? 0) === 1) $status = 'invalid';
        elseif ((int)($r['is_retur'] ?? 0) === 1) $status = 'retur';
        elseif ((int)($r['is_rusak'] ?? 0) === 1) $status = 'rusak';
        elseif (strpos($comment, 'invalid') !== false) $status = 'invalid';
        elseif (strpos($comment, 'retur') !== false) $status = 'retur';
        elseif (strpos($comment, 'rusak') !== false || $lh_status === 'rusak') $status = 'rusak';
        else $status = 'normal';
    }

    $price = (int)($r['price_snapshot'] ?? $r['price'] ?? 0);
    if ($price <= 0) $price = (int)($r['sprice_snapshot'] ?? 0);
    $qty = (int)($r['qty'] ?? 0);
    if ($qty <= 0) $qty = 1;
    $line_price = $price * $qty;

    $gross_add = $line_price;
    $loss_rusak = ($status === 'rusak') ? $line_price : 0;
    $loss_invalid = ($status === 'invalid') ? $line_price : 0;
    $net_add = $gross_add - $loss_rusak - $loss_invalid;

    if (!isset($daily[$sale_date])) {
        $daily[$sale_date] = [
            'gross' => 0,
            'net' => 0,
            'rusak_qty' => 0,
            'loss_rusak' => 0,
            'loss_invalid' => 0,
            'laku_users' => [],
            'bytes_by_user' => []
        ];
    }

    $daily[$sale_date]['gross'] += $gross_add;
    $daily[$sale_date]['net'] += $net_add;
    if ($status === 'rusak') $daily[$sale_date]['rusak_qty'] += 1;
    $daily[$sale_date]['loss_rusak'] += $loss_rusak;
    $daily[$sale_date]['loss_invalid'] += $loss_invalid;

    if (!in_array($status, ['rusak','retur','invalid'], true) && $username !== '') {
        $daily[$sale_date]['laku_users'][$username] = true;
    }

    $bytes = (int)($r['last_bytes'] ?? 0);
    if ($bytes < 0) $bytes = 0;
    if ($username !== '') {
        $prev = (int)($daily[$sale_date]['bytes_by_user'][$username] ?? 0);
        if ($bytes > $prev) $daily[$sale_date]['bytes_by_user'][$username] = $bytes;
    }
}

$months = [];
for ($m = 1; $m <= 12; $m++) {
    $mm = str_pad((string)$m, 2, '0', STR_PAD_LEFT);
    $months[$mm] = [
        'gross' => 0,
        'net' => 0,
        'net_audit' => 0,
        'has_audit' => false,
        'selisih' => 0,
        'qty' => 0,
        'bandwidth' => 0,
        'days' => 0,
        'rs' => 0,
        'sp' => 0,
        'wr_sum' => 0,
        'km_sum' => 0,
        'active_sum' => 0,
        'phone_days' => 0,
        'loss_voucher' => 0,
        'loss_setoran' => 0,
        'expenses' => 0
    ];
}

foreach ($daily as $date => $val) {
    $mm = substr($date, 5, 2);
    if (!isset($months[$mm])) continue;
    $qty = count($val['laku_users'] ?? []);
    $system_net = (int)($val['net'] ?? 0);
    $months[$mm]['net'] += $system_net;
    $months[$mm]['gross'] += $system_net;
    $months[$mm]['loss_voucher'] += (int)($val['loss_rusak'] ?? 0) + (int)($val['loss_invalid'] ?? 0);
    $has_audit_day = isset($audit_net[$date]);
    $day_audit = $has_audit_day ? (int)$audit_net[$date] : (int)($val['net'] ?? 0);
    $months[$mm]['net_audit'] += $day_audit;
    if ($has_audit_day) {
        $months[$mm]['has_audit'] = true;
        $audit_dates_in_daily[$date] = true;
        $day_selisih = (int)($audit_selisih[$date] ?? 0);
        $months[$mm]['selisih'] += $day_selisih;
        if ($day_selisih < 0) $months[$mm]['loss_setoran'] += abs($day_selisih);
    }
    $months[$mm]['qty'] += $qty;
    $months[$mm]['bandwidth'] += isset($val['bytes_by_user']) ? array_sum($val['bytes_by_user']) : 0;
    if ($qty > 0 || (int)($val['gross'] ?? 0) > 0) $months[$mm]['days'] += 1;
}

foreach ($audit_net as $date => $val) {
    if (isset($audit_dates_in_daily[$date])) continue;
    $mm = substr($date, 5, 2);
    if (!isset($months[$mm])) continue;
    $months[$mm]['net_audit'] += (int)$val;
    $months[$mm]['has_audit'] = true;
    $day_selisih = (int)($audit_selisih[$date] ?? 0);
    $months[$mm]['selisih'] += $day_selisih;
    if ($day_selisih < 0) $months[$mm]['loss_setoran'] += abs($day_selisih);
    // Net System tetap murni transaksi; audit hanya untuk Net Audit.
}

foreach ($phone as $date => $val) {
    $mm = substr($date, 5, 2);
    if (!isset($months[$mm])) continue;
    $months[$mm]['rs'] += (int)($val['rusak'] ?? 0);
    $months[$mm]['sp'] += (int)($val['spam'] ?? 0);
    $months[$mm]['active_sum'] += (int)($val['active'] ?? 0);
    $months[$mm]['phone_days'] += 1;
}

foreach ($phone_units as $date => $val) {
    $mm = substr($date, 5, 2);
    if (!isset($months[$mm])) continue;
    $months[$mm]['wr_sum'] += (int)($val['WARTEL'] ?? 0);
    $months[$mm]['km_sum'] += (int)($val['KAMTIB'] ?? 0);
}

$total_qty = 0;
$total_gross = 0;
$total_net = 0;
$total_system_net = 0;
$total_selisih = 0;
$total_rs = 0;
$total_sp = 0;
$total_wr = 0;
$total_km = 0;
$total_avg_days = 0;
$total_bandwidth = 0;
$total_voucher_loss = 0;
$total_setoran_loss = 0;
$months_with_data = 0;

$net_for_chart = [];
$insiden_for_chart = [];
$max_net = 0;
$max_insiden = 0;

foreach ($months as $mm => &$mrow) {
    $net_audit = $mrow['has_audit'] ? $mrow['net_audit'] : $mrow['net'];
    $selisih = (int)($mrow['selisih'] ?? 0);
    $avg = $mrow['days'] > 0 ? round($mrow['qty'] / $mrow['days']) : 0;
    $wr_avg = $mrow['phone_days'] > 0 ? round($mrow['wr_sum'] / $mrow['phone_days']) : 0;
    $km_avg = $mrow['phone_days'] > 0 ? round($mrow['km_sum'] / $mrow['phone_days']) : 0;

    $mrow['net_audit'] = $net_audit;
    $mrow['selisih'] = $selisih;
    $mrow['avg'] = $avg;
    $mrow['wr_avg'] = $wr_avg;
    $mrow['km_avg'] = $km_avg;

    if ($mrow['gross'] > 0 || $mrow['net'] > 0 || $mrow['qty'] > 0) {
        $months_with_data++;
    }

    $total_qty += $mrow['qty'];
    $total_gross += $mrow['gross'];
    $total_net += $net_audit;
    $total_system_net += $mrow['net'];
    $total_selisih += $selisih;
    $total_rs += $mrow['rs'];
    $total_sp += $mrow['sp'];
    $total_wr += $wr_avg;
    $total_km += $km_avg;
    if ($mrow['days'] > 0) $total_avg_days += $avg;
    $total_bandwidth += (int)($mrow['bandwidth'] ?? 0);
    $total_voucher_loss += (int)($mrow['loss_voucher'] ?? 0);
    $total_setoran_loss += (int)($mrow['loss_setoran'] ?? 0);

    $net_million = $net_audit > 0 ? ($net_audit / 1000000) : 0;
    $insiden = $mrow['rs'] + $mrow['sp'];
    $net_for_chart[$mm] = $net_million;
    $insiden_for_chart[$mm] = $insiden;
    if ($net_million > $max_net) $max_net = $net_million;
    if ($insiden > $max_insiden) $max_insiden = $insiden;
}
unset($mrow);

$total_selisih = $total_selisih;
$total_kerugian = $total_voucher_loss + $total_setoran_loss;
$avg_all = $months_with_data > 0 ? round($total_qty / (int)(array_sum(array_column($months, 'days')) ?: 1)) : 0;
$print_time = date('d-m-Y H:i:s');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Tahunan</title>
    <style>
        body { font-family: Arial, sans-serif; color:#111; margin:20px; }
        h2 { margin:0 0 6px 0; }
        .meta { font-size:12px; color:#555; margin-bottom:12px; }
        .toolbar { margin-bottom:14px; display:flex; gap:8px; flex-wrap:wrap; }
        .btn { padding:6px 10px; border:1px solid #999; background:#f2f2f2; cursor:pointer; border-radius:4px; font-size:12px; }
        table { width:100%; border-collapse:collapse; font-size:12px; }
        th, td { border:1px solid #000; padding:6px; text-align:center; }
        th { background:#f5f5f5; }
        .currency { text-align:right; white-space:nowrap; }
        .bar-mini { background:#e2e8f0; height:6px; width:80px; border-radius:3px; overflow:hidden; margin:0 auto; }
        .bar-mini > span { display:block; height:100%; background:#3b82f6; }
        .bar-mini.red > span { background:#ef4444; }
        @media print {
            @page { size: A4 landscape; margin: 10mm; }
            .toolbar { display:none; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button class="btn" onclick="window.print()">Print / PDF</button>
    </div>

    <div style="border-bottom:2px solid #000; padding-bottom:10px; margin-bottom:20px;">
        <h2 style="margin:0;">Laporan Keuangan Tahunan</h2>
        <div class="meta">Tahun: <?= esc($filter_year) ?> | Dicetak: <?= esc($print_time) ?></div>
    </div>

    <table style="width:100%; border-collapse:collapse; font-size:12px; margin-top:20px;">
        <thead>
            <tr style="background:#334155; color:#000;">
                <th style="padding:10px;">Bulan</th>
                <th style="padding:10px; text-align:center;">Total Transaksi</th>
                <th style="padding:10px; text-align:center;">Target Sistem</th>
                <th style="padding:10px; text-align:center;">Pengeluaran (Ops)</th>
                <th style="padding:10px; text-align:center;">Setoran Bersih</th>
                <th style="padding:10px; text-align:center;">Selisih / Loss</th>
                <th style="padding:10px; text-align:center;">Kinerja</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($months as $mm => $row): ?>
                <?php
                    $has_data = ($row['gross'] > 0 || $row['qty'] > 0 || $row['net'] > 0);
                    $selisih = (int)($row['selisih'] ?? 0);
                    $row_color = $selisih < 0 ? '#fee2e2' : '#fff';
                    $net_percent = 0;
                    if ($max_net > 0) {
                        $net_percent = (int)round(($net_for_chart[$mm] / $max_net) * 100);
                        if ($net_percent < 5 && $net_for_chart[$mm] > 0) $net_percent = 5;
                        if ($net_percent > 100) $net_percent = 100;
                    }
                ?>
                <tr style="background:<?= $row_color ?>; border-bottom:1px solid #e2e8f0;">
                    <td style="padding:8px; font-weight:bold; text-align:left;"><?= esc(month_label_id($mm)) ?></td>
                    <td style="padding:8px; text-align:center;"><?= $has_data ? number_format((int)$row['qty'],0,',','.') : '-' ?></td>
                    <td style="padding:8px; text-align:right;"><?= $has_data ? number_format((int)$row['net'],0,',','.') : '-' ?></td>
                    <td style="padding:8px; text-align:right;">Rp <?= $has_data ? number_format((int)($row['expenses'] ?? 0),0,',','.') : '-' ?></td>
                    <td style="padding:8px; text-align:right; font-weight:bold;">Rp <?= $has_data ? number_format((int)$row['net_audit'],0,',','.') : '-' ?></td>
                    <td style="padding:8px; text-align:right; color:<?= $selisih < 0 ? '#dc2626' : '#16a34a' ?>;">
                        <?= $has_data ? number_format($selisih,0,',','.') : '-' ?>
                    </td>
                    <td style="padding:8px; text-align:center;">
                        <?php if ($has_data): ?>
                            <div class="bar-mini <?= $selisih < 0 ? 'red' : '' ?>">
                                <span style="width: <?= $net_percent ?>%"></span>
                            </div>
                        <?php else: ?>
                            <span style="color:#ccc; text-align:center;">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background:#e2e8f0; font-weight:bold;">
                <td style="padding:8px; text-align:left;">TOTAL YTD</td>
                <td style="padding:8px; text-align:center;"><?= number_format((int)$total_qty,0,',','.') ?></td>
                <td style="padding:8px; text-align:right;"><?= number_format((int)$total_system_net,0,',','.') ?></td>
                <td style="padding:8px; text-align:right;">Rp <?= number_format((int)$total_expenses_year,0,',','.') ?></td>
                <td style="padding:8px; text-align:right;">Rp <?= number_format((int)$total_net,0,',','.') ?></td>
                <td style="padding:8px; text-align:right; color:<?= $total_selisih < 0 ? '#dc2626' : '#16a34a' ?>;">
                    <?= number_format((int)$total_selisih,0,',','.') ?>
                </td>
                <td></td>
            </tr>
            <tr style="background:#fff; font-size:11px; color:#666;">
                <td colspan="7" style="padding:8px; text-align:right;">
                    * Angka Setoran Bersih sudah dikurangi Total Pengeluaran Operasional tahun ini sebesar:
                    <b>Rp <?= number_format((int)$total_expenses_year, 0, ',', '.') ?></b>
                </td>
            </tr>
        </tfoot>
    </table>

<script>
function setUniquePrintTitle(){
    var now = new Date();
    var pad = function(n){ return String(n).padStart(2, '0'); };
    var dayNames = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    var reportYear = <?= json_encode((string)$filter_year) ?> || String(now.getFullYear());
    var dayLabel = dayNames[now.getDay()];
    var dateLabel = pad(now.getDate()) + '-' + pad(now.getMonth() + 1) + '-' + reportYear;
    var timeLabel = pad(now.getHours()) + pad(now.getMinutes()) + pad(now.getSeconds());
    document.title = 'LaporanTahunan-' + dayLabel + '-' + dateLabel + '-' + timeLabel;
}

window.addEventListener('beforeprint', setUniquePrintTitle);
</script>
</body>
</html>
