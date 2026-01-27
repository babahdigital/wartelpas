<?php
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

include('../../include/config.php');
include('../../include/readcfg.php');

$root_dir = dirname(__DIR__, 2);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
require_once($root_dir . '/report/laporan/helpers_audit.php');
$system_cfg = $env['system'] ?? [];
$db_rel = $system_cfg['db_file'] ?? 'db_data/mikhmon_stats.db';
if (preg_match('/^[A-Za-z]:\\\\|^\//', $db_rel)) {
    $dbFile = $db_rel;
} else {
    $dbFile = $root_dir . '/' . ltrim($db_rel, '/');
}
$pricing = $env['pricing'] ?? [];
$price10 = (int)($pricing['price_10'] ?? 0);
$price30 = (int)($pricing['price_30'] ?? 0);
$cur = isset($currency) ? $currency : 'Rp';
$session_id = $_GET['session'] ?? '';

$filter_date = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}$/', $filter_date)) {
    $filter_date = date('Y-m');
}
$month_start = $filter_date . '-01';
$days_in_month = (int)date('t', strtotime($month_start));

function esc($s){ return htmlspecialchars((string)$s); }


$rows = [];
$daily = [];
$phone = [];
$phone_units = [];
$audit_net = [];
$audit_selisih = [];
$audit_system = [];
$audit_expense = [];
$total_expenses_month = 0;
$daily_expense_logs = [];
$notes_map = [];

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
            WHERE report_date LIKE :m AND unit_type = 'TOTAL'
            GROUP BY report_date");
        $stmtPhone->execute([':m' => $filter_date . '%']);
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
            WHERE report_date LIKE :m AND unit_type IN ('WARTEL','KAMTIB')
            GROUP BY report_date, unit_type");
        $stmtUnit->execute([':m' => $filter_date . '%']);
        foreach ($stmtUnit->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $d = $row['report_date'];
            if (!isset($phone_units[$d])) $phone_units[$d] = ['WARTEL' => 0, 'KAMTIB' => 0];
            $ut = strtoupper((string)($row['unit_type'] ?? ''));
            $phone_units[$d][$ut] = (int)($row['total_units'] ?? 0);
        }

        $stmtAudit = $db->prepare("SELECT report_date, blok_name, expected_setoran, actual_setoran, user_evidence, expenses_amt, expenses_desc
            FROM audit_rekap_manual
            WHERE report_date LIKE :m");
        $stmtAudit->execute([':m' => $filter_date . '%']);
        foreach ($stmtAudit->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $d = $row['report_date'] ?? '';
            if ($d === '') continue;
            [$manual_setoran, $expected_adj_setoran] = calc_audit_adjusted_setoran($row);
            $expense = (int)($row['expenses_amt'] ?? 0);
            $desc = trim((string)($row['expenses_desc'] ?? ''));
            $blok = (string)($row['blok_name'] ?? '');
            $total_expenses_month += $expense;
            $net_cash_audit = (int)$manual_setoran - $expense;
            $audit_net[$d] = (int)($audit_net[$d] ?? 0) + $net_cash_audit;
            $audit_expense[$d] = (int)($audit_expense[$d] ?? 0) + $expense;
            $audit_system[$d] = (int)($audit_system[$d] ?? 0) + (int)$expected_adj_setoran;
            $audit_selisih[$d] = (int)($audit_selisih[$d] ?? 0) + ((int)$manual_setoran - (int)$expected_adj_setoran);
            if ($expense > 0) {
                $desc_text = $desc !== '' ? $desc : 'Tanpa Keterangan';
                $daily_expense_logs[$d][] = [
                    'blok' => $blok,
                    'desc' => $desc_text,
                    'amt' => $expense
                ];
            }
        }

        try {
            $stmtN = $db->prepare("SELECT report_date, note FROM daily_report_notes WHERE report_date LIKE :m");
            $stmtN->execute([':m' => $filter_date . '%']);
            foreach ($stmtN->fetchAll(PDO::FETCH_ASSOC) as $rn) {
                $notes_map[$rn['report_date']] = $rn['note'];
            }
        } catch (Exception $e) {}
    }
} catch (Exception $e) {
    $rows = [];
}

$seen_sales = [];
$seen_user_day = [];
foreach ($rows as $r) {
    $sale_date = $r['sale_date'] ?: norm_date_from_raw_report($r['raw_date'] ?? '');
    if ($sale_date === '' || strpos((string)$sale_date, $filter_date) !== 0) continue;

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

    $gross_add = 0;
    $loss_rusak = 0;
    $loss_invalid = 0;
    $net_add = 0;

    if ($status === 'invalid') {
        $gross_add = 0;
        $net_add = 0;
    } elseif ($status === 'retur') {
        $gross_add = 0;
        $net_add = $line_price;
    } elseif ($status === 'rusak') {
        $gross_add = $line_price;
        $loss_rusak = $line_price;
        $net_add = 0;
    } else {
        $gross_add = $line_price;
        $net_add = $line_price;
    }

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

    if (!in_array($status, ['rusak','invalid'], true) && $username !== '') {
        $daily[$sale_date]['laku_users'][$username] = true;
    }

    $bytes = (int)($r['last_bytes'] ?? 0);
    if ($bytes < 0) $bytes = 0;
    if ($username !== '') {
        $prev = (int)($daily[$sale_date]['bytes_by_user'][$username] ?? 0);
        if ($bytes > $prev) $daily[$sale_date]['bytes_by_user'][$username] = $bytes;
    }
}

$all_dates = [];
for ($d = 1; $d <= $days_in_month; $d++) {
    $date = $filter_date . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
    if (isset($daily[$date]) || isset($phone[$date]) || isset($audit_net[$date])) {
        $all_dates[] = $date;
    }
}

$total_gross = 0;
$total_omzet_gross = 0;
$total_net_audit = 0;
$total_selisih = 0;
$total_qty_laku = 0;
$total_voucher_rusak = 0;
$total_voucher_loss = 0;
$total_setoran_loss = 0;
$total_spam = 0;
$total_bandwidth = 0;
$max_wr = 0;
$max_km = 0;
$max_active = 0;
$total_rusak_device = 0;
$total_expense_table = 0;
$total_expenses_month = $total_expenses_month ?? 0;

$rows_out = [];
foreach ($all_dates as $date) {
    $net = (int)($daily[$date]['net'] ?? 0);
    $system_net = $net;
    $gross = $system_net;
    $gross_all = (int)($daily[$date]['gross'] ?? 0);
    $audit = $audit_net[$date] ?? null;
    $net_audit = $audit !== null ? (int)$audit : $net;
    $selisih = $audit !== null ? (int)($audit_selisih[$date] ?? 0) : 0;
    $expense_day = (int)($audit_expense[$date] ?? 0);
    $day_voucher_loss = (int)($daily[$date]['loss_rusak'] ?? 0) + (int)($daily[$date]['loss_invalid'] ?? 0);

    $qty_laku = isset($daily[$date]['laku_users']) ? count($daily[$date]['laku_users']) : 0;
    $rusak_qty = (int)($daily[$date]['rusak_qty'] ?? 0);
    $bandwidth = isset($daily[$date]['bytes_by_user']) ? array_sum($daily[$date]['bytes_by_user']) : 0;

    $ph = $phone[$date] ?? ['active' => 0, 'rusak' => 0, 'spam' => 0, 'total' => 0];
    $wr = (int)($phone_units[$date]['WARTEL'] ?? 0);
    $km = (int)($phone_units[$date]['KAMTIB'] ?? 0);

    $rows_out[] = [
        'date' => $date,
        'gross' => $gross,
        'net_audit' => $net_audit,
        'expense' => $expense_day,
        'selisih' => $selisih,
        'qty' => $qty_laku,
        'rusak_qty' => $rusak_qty,
        'rs' => (int)($ph['rusak'] ?? 0),
        'sp' => (int)($ph['spam'] ?? 0),
        'wr' => $wr,
        'km' => $km,
        'active' => (int)($ph['active'] ?? 0),
        'bandwidth' => $bandwidth
    ];

    $total_gross += $gross;
    $total_omzet_gross += $gross_all;
    $total_net_audit += $net_audit;
    $total_expense_table += $expense_day;
    $total_selisih += $selisih;
    $total_qty_laku += $qty_laku;
    $total_voucher_rusak += $rusak_qty;
    $total_voucher_loss += $day_voucher_loss;
    if ($selisih < 0) $total_setoran_loss += abs($selisih);
    $total_spam += (int)($ph['spam'] ?? 0);
    $total_rusak_device += (int)($ph['rusak'] ?? 0);
    $total_bandwidth += $bandwidth;
    $max_wr = max($max_wr, $wr);
    $max_km = max($max_km, $km);
    $max_active = max($max_active, (int)($ph['active'] ?? 0));
}

$total_kerugian = $total_voucher_loss + $total_setoran_loss;

$month_label = month_label_id($filter_date);
$print_time = date('d-m-Y H:i:s');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Bulanan</title>
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
        .summary-grid { display:grid; grid-template-columns:repeat(6,1fr); gap:10px; margin:12px 0 16px; }
        .summary-card { border:1px solid #ccc; padding:8px 10px; background:#f9f9f9; }
        .summary-title { font-size:10px; text-transform:uppercase; color:#666; }
        .summary-value { font-weight:700; font-size:14px; margin-top:4px; }
        .footnote { font-size:11px; color:#555; margin-top:8px; }
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
        <h2 style="margin:0;">Laporan Keuangan Bulanan</h2>
        <div class="meta">Periode: <?= esc($month_label) ?> | Dicetak: <?= esc($print_time) ?></div>
    </div>

    <div class="summary-grid" style="grid-template-columns: repeat(5, 1fr); gap:15px; margin-bottom:25px;">
        <div class="summary-card" style="border:1px solid #ddd; padding:15px; border-radius:4px; background:#fff;">
            <div class="summary-title" style="color:#666; font-size:11px; text-transform:uppercase;">Total Omzet (Gross)</div>
            <div class="summary-value" style="font-size:20px; font-weight:bold;"><?= $cur ?> <?= number_format((int)$total_omzet_gross,0,',','.') ?></div>
        </div>
        <div class="summary-card" style="border:1px solid #fca5a5; background:#fff1f2; padding:15px; border-radius:4px;">
            <div class="summary-title" style="color:#991b1b; font-size:11px; text-transform:uppercase;">Voucher Loss</div>
            <div class="summary-value" style="font-size:20px; font-weight:bold; color:#991b1b;">- <?= $cur ?> <?= number_format((int)$total_voucher_loss,0,',','.') ?></div>
            <div style="font-size:10px; color:#b91c1c;">(Rusak & Invalid)</div>
        </div>
        <div class="summary-card" style="border:1px solid #f39c12; background:#fffbf0; padding:15px; border-radius:4px;">
            <div class="summary-title" style="color:#d35400; font-size:11px; text-transform:uppercase;">Pengeluaran Ops (Bon)</div>
            <div class="summary-value" style="font-size:20px; font-weight:bold; color:#d35400;">- <?= $cur ?> <?= number_format((int)$total_expenses_month,0,',','.') ?></div>
            <div style="font-size:10px; color:#e67e22;">(Belanja Toko)</div>
        </div>
        <div class="summary-card" style="border:1px solid #ddd; padding:15px; border-radius:4px; background:#fff;">
            <div class="summary-title" style="color:#666; font-size:11px; text-transform:uppercase;">Setoran Fisik</div>
            <div class="summary-value" style="font-size:20px; font-weight:bold; color:#1e3a8a;"><?= $cur ?> <?= number_format((int)$total_net_audit,0,',','.') ?></div>
        </div>
        <div class="summary-card" style="border:1px solid <?= $total_selisih < 0 ? '#fca5a5' : ($total_selisih > 0 ? '#86efac' : '#ddd') ?>; background: <?= $total_selisih < 0 ? '#fee2e2' : ($total_selisih > 0 ? '#dcfce7' : '#fff') ?>; padding:15px; border-radius:4px;">
            <div class="summary-title" style="color:#444; font-size:11px; text-transform:uppercase;">Akumulasi Selisih</div>
            <div class="summary-value" style="font-size:20px; font-weight:bold; color: <?= $total_selisih < 0 ? '#c0392b' : ($total_selisih > 0 ? '#166534' : '#333') ?>;">
                <?= $cur ?> <?= number_format((int)$total_selisih,0,',','.') ?>
            </div>
            <div style="font-size:10px; color:#555;">
                <?= $total_selisih < 0 ? 'Total Kurang Setor' : ($total_selisih > 0 ? 'Total Lebih Setor' : 'Balance / Sesuai') ?>
            </div>
        </div>
    </div>

    <div style="margin-bottom:10px; font-weight:bold; font-size:14px; border-bottom:1px solid #eee; padding-bottom:5px;">Rincian Kinerja Harian</div>
    <table style="width:100%; border-collapse:collapse; font-size:11px;">
        <thead>
            <tr style="background:#f1f5f9; color:#333;">
                <th style="border:1px solid #cbd5e1; padding:8px;">Tanggal</th>
                <th style="border:1px solid #cbd5e1; padding:8px; text-align:center;">Terjual</th>
                <th style="border:1px solid #cbd5e1; padding:8px; text-align:center;">Rusak</th>
                <th style="border:1px solid #cbd5e1; padding:8px; text-align:right;">Target Sistem (Net)</th>
                <th style="border:1px solid #cbd5e1; padding:8px; text-align:right;">Setoran Fisik (Audit)</th>
                <th style="border:1px solid #cbd5e1; padding:8px; text-align:right;">Pengeluaran</th>
                <th style="border:1px solid #cbd5e1; padding:8px; text-align:right;">Selisih</th>
                   <th style="border:1px solid #cbd5e1; padding:8px; text-align:left;">Keterangan / Insiden</th>
                <th style="border:1px solid #cbd5e1; padding:8px; text-align:center;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows_out)): ?>
                   <tr><td colspan="9" style="text-align:center; padding:20px;">Tidak ada data transaksi bulan ini.</td></tr>
            <?php else: $idx = 0; foreach ($rows_out as $row): $idx++; ?>
                <?php
                    $daily_selisih = (int)($row['selisih'] ?? 0);
                    $bg_row = $idx % 2 === 0 ? '#fff' : '#f8fafc';
                    $status_label = 'AMAN';
                    $status_color = '#2563eb';
                    if ($daily_selisih < 0) {
                        $status_label = 'KURANG';
                        $status_color = '#dc2626';
                        $bg_row = '#fef2f2';
                    } elseif ($daily_selisih > 0) {
                        $status_label = 'LEBIH';
                        $status_color = '#16a34a';
                    }
                       $day_note = $notes_map[$row['date']] ?? '';
                       $day_note_short = $day_note !== '' ? mb_strimwidth($day_note, 0, 40, '...') : '';
                ?>
                <tr style="background:<?= $bg_row ?>;">
                    <td style="border:1px solid #e2e8f0; padding:6px 8px; text-align:center;"><?= esc(substr($row['date'], 8, 2)) ?></td>
                    <td style="border:1px solid #e2e8f0; padding:6px 8px; text-align:center;"><?= number_format((int)$row['qty'],0,',','.') ?></td>
                    <td style="border:1px solid #e2e8f0; padding:6px 8px; text-align:center; color:#dc2626;">
                        <?= ((int)$row['rusak_qty'] > 0) ? number_format((int)$row['rusak_qty'],0,',','.') : '-' ?>
                    </td>
                    <td style="border:1px solid #e2e8f0; padding:6px 8px; text-align:right;"><?= number_format((int)$row['gross'],0,',','.') ?></td>
                    <td style="border:1px solid #e2e8f0; padding:6px 8px; text-align:right; font-weight:bold;"><?= number_format((int)$row['net_audit'],0,',','.') ?></td>
                    <td style="border:1px solid #e2e8f0; padding:6px 8px; text-align:right; color:#d35400;">
                        <?= (int)$row['expense'] > 0 ? number_format((int)$row['expense'],0,',','.') : '-' ?>
                    </td>
                    <td style="border:1px solid #e2e8f0; padding:6px 8px; text-align:right; font-weight:bold; color:<?= $status_color ?>;">
                        <?= $daily_selisih == 0 ? '-' : number_format($daily_selisih,0,',','.') ?>
                    </td>
                       <td style="border:1px solid #e2e8f0; padding:6px 8px; text-align:left; font-size:10px; color:#555;">
                           <?= $day_note_short !== '' ? esc($day_note_short) : '-' ?>
                       </td>
                    <td style="border:1px solid #e2e8f0; padding:6px 8px; text-align:center; font-size:10px; font-weight:bold; color:<?= $status_color ?>;">
                        <?= $status_label ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
        <tfoot style="background:#e2e8f0; font-weight:bold;">
            <tr>
                <td style="border:1px solid #cbd5e1; padding:8px; text-align:right;" colspan="3">TOTAL BULAN INI</td>
                <td style="border:1px solid #cbd5e1; padding:8px; text-align:right;"><?= number_format((int)$total_gross,0,',','.') ?></td>
                <td style="border:1px solid #cbd5e1; padding:8px; text-align:right;"><?= number_format((int)$total_net_audit,0,',','.') ?></td>
                <td style="border:1px solid #cbd5e1; padding:8px; text-align:right; color:#d35400;"><?= $total_expense_table > 0 ? number_format((int)$total_expense_table,0,',','.') : '-' ?></td>
                <td style="border:1px solid #cbd5e1; padding:8px; text-align:right; color:<?= $total_selisih < 0 ? '#dc2626' : ($total_selisih > 0 ? '#16a34a' : '#333') ?>;">
                    <?= number_format((int)$total_selisih,0,',','.') ?>
                </td>
                   <td style="border:1px solid #cbd5e1;"></td>
                   <td style="border:1px solid #cbd5e1;"></td>
            </tr>
        </tfoot>
    </table>

    <?php if (!empty($daily_expense_logs)): ?>
        <div style="margin-top:25px; page-break-inside:avoid;">
            <div style="margin-bottom:8px; font-weight:bold; font-size:13px; border-bottom:1px solid #ddd; padding-bottom:5px; color:#d35400;">
                <i class="fa fa-shopping-cart"></i> Rincian Pengeluaran Operasional
            </div>
            <table style="width:100%; border-collapse:collapse; font-size:11px;">
                <thead>
                    <tr style="background:#fffbf0; color:#d35400;">
                        <th style="border:1px solid #f39c12; padding:6px; width:15%;">Tanggal</th>
                        <th style="border:1px solid #f39c12; padding:6px; width:15%;">Blok</th>
                        <th style="border:1px solid #f39c12; padding:6px; text-align:left;">Keterangan Belanja</th>
                        <th style="border:1px solid #f39c12; padding:6px; text-align:right; width:20%;">Nominal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                        ksort($daily_expense_logs);
                        foreach ($daily_expense_logs as $d => $items):
                            foreach ($items as $item):
                    ?>
                    <tr>
                        <td style="border:1px solid #e67e22; padding:5px; text-align:center;">
                            <?= esc(substr($d, 8, 2)) . ' ' . esc(month_label_id(substr($d, 5, 2))) ?>
                        </td>
                        <td style="border:1px solid #e67e22; padding:5px; text-align:center;">
                            <?= esc($item['blok']) ?>
                        </td>
                        <td style="border:1px solid #e67e22; padding:5px; text-align:left;">
                            <?= esc($item['desc']) ?>
                        </td>
                        <td style="border:1px solid #e67e22; padding:5px; text-align:right; font-weight:bold; color:#d35400;">
                            <?= $cur ?> <?= number_format((int)$item['amt'], 0, ',', '.') ?>
                        </td>
                    </tr>
                    <?php endforeach; endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#fffbf0; font-weight:bold; color:#d35400;">
                        <td colspan="3" style="border:1px solid #f39c12; padding:6px; text-align:right;">TOTAL PENGELUARAN BULAN INI</td>
                        <td style="border:1px solid #f39c12; padding:6px; text-align:right;">
                            <?= $cur ?> <?= number_format((int)$total_expenses_month, 0, ',', '.') ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>

<script>
function setUniquePrintTitle(){
    var now = new Date();
    var pad = function(n){ return String(n).padStart(2, '0'); };
    var dayNames = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    var reportYm = <?= json_encode((string)$filter_date) ?>;
    var parts = reportYm.split('-');
    var year = parts[0] || String(now.getFullYear());
    var month = parts[1] || pad(now.getMonth() + 1);
    var dayLabel = dayNames[now.getDay()];
    var dateLabel = pad(now.getDate()) + '-' + month + '-' + year;
    var timeLabel = pad(now.getHours()) + pad(now.getMinutes()) + pad(now.getSeconds());
    document.title = 'LaporanBulanan-' + dayLabel + '-' + dateLabel + '-' + timeLabel;
}

window.addEventListener('beforeprint', setUniquePrintTitle);
</script>
</body>
</html>
