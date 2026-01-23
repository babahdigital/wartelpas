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

$filter_date = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}$/', $filter_date)) {
    $filter_date = date('Y-m');
}
$month_start = $filter_date . '-01';
$days_in_month = (int)date('t', strtotime($month_start));

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

function month_label_id($ym) {
    $months = [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
        '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
        '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
    ];
    $y = substr($ym, 0, 4);
    $m = substr($ym, 5, 2);
    return ($months[$m] ?? $m) . ' ' . $y;
}

$rows = [];
$daily = [];
$phone = [];
$phone_units = [];
$audit_net = [];

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

        $stmtAudit = $db->prepare("SELECT report_date, SUM(actual_setoran) AS actual_setoran
            FROM audit_rekap_manual
            WHERE report_date LIKE :m
            GROUP BY report_date");
        $stmtAudit->execute([':m' => $filter_date . '%']);
        foreach ($stmtAudit->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $audit_net[$row['report_date']] = (int)($row['actual_setoran'] ?? 0);
        }
    }
} catch (Exception $e) {
    $rows = [];
}

$seen_sales = [];
foreach ($rows as $r) {
    $sale_date = $r['sale_date'] ?: norm_date_from_raw_report($r['raw_date'] ?? '');
    if ($sale_date === '' || strpos((string)$sale_date, $filter_date) !== 0) continue;

    $username = $r['username'] ?? '';
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

    $gross_add = ($status === 'retur' || $status === 'invalid') ? 0 : $line_price;
    $loss_rusak = ($status === 'rusak') ? $line_price : 0;
    $loss_invalid = ($status === 'invalid') ? $line_price : 0;
    $net_add = $gross_add - $loss_rusak - $loss_invalid;

    if (!isset($daily[$sale_date])) {
        $daily[$sale_date] = [
            'gross' => 0,
            'net' => 0,
            'rusak_qty' => 0,
            'laku_users' => [],
            'bytes_by_user' => []
        ];
    }

    $daily[$sale_date]['gross'] += $gross_add;
    $daily[$sale_date]['net'] += $net_add;
    if ($status === 'rusak') $daily[$sale_date]['rusak_qty'] += 1;

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

$all_dates = [];
for ($d = 1; $d <= $days_in_month; $d++) {
    $date = $filter_date . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
    if (isset($daily[$date]) || isset($phone[$date]) || isset($audit_net[$date])) {
        $all_dates[] = $date;
    }
}

$total_gross = 0;
$total_net_audit = 0;
$total_qty_laku = 0;
$total_voucher_rusak = 0;
$total_spam = 0;
$total_bandwidth = 0;
$max_wr = 0;
$max_km = 0;
$max_active = 0;
$total_rusak_device = 0;

$rows_out = [];
foreach ($all_dates as $date) {
    $gross = (int)($daily[$date]['gross'] ?? 0);
    $net = (int)($daily[$date]['net'] ?? 0);
    $audit = $audit_net[$date] ?? null;
    $net_audit = $audit !== null ? (int)$audit : $net;

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
    $total_net_audit += $net_audit;
    $total_qty_laku += $qty_laku;
    $total_voucher_rusak += $rusak_qty;
    $total_spam += (int)($ph['spam'] ?? 0);
    $total_rusak_device += (int)($ph['rusak'] ?? 0);
    $total_bandwidth += $bandwidth;
    $max_wr = max($max_wr, $wr);
    $max_km = max($max_km, $km);
    $max_active = max($max_active, (int)($ph['active'] ?? 0));
}

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
        .summary-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin:12px 0 16px; }
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
        <button class="btn" onclick="window.print()">Print / Download PDF</button>
        <button class="btn" onclick="shareReport()">Share</button>
    </div>

    <div>
        <h2>Rekap Laporan Penjualan (Bulanan)</h2>
        <div class="meta">Periode: <?= esc($month_label) ?> | Dicetak: <?= esc($print_time) ?></div>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-title">Total Voucher Terjual</div>
            <div class="summary-value"><?= number_format((int)$total_qty_laku,0,',','.') ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-title">Total Bandwidth</div>
            <div class="summary-value"><?= esc(format_bytes_short((int)$total_bandwidth)) ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-title">Insiden (Rusak/Spam)</div>
            <div class="summary-value"><?= number_format((int)$total_rusak_device,0,',','.') ?> / <?= number_format((int)$total_spam,0,',','.') ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-title">Total Device (WR/KM)</div>
            <div class="summary-value"><?= number_format((int)$max_wr,0,',','.') ?> / <?= number_format((int)$max_km,0,',','.') ?></div>
        </div>
    </div>

    <table>
        <thead>
            <tr style="background:#333;color:#fff;">
                <th rowspan="2" style="color: #000;">Tgl</th>
                <th colspan="2" style="color: #000;">Keuangan</th>
                <th colspan="2" style="color: #000;">Voucher</th>
                <th colspan="5" style="color: #000;">Rincian Device & Status</th>
                <th rowspan="2" style="color: #000;">Bandwidth</th>
            </tr>
            <tr>
                <th>Gross System</th>
                <th>Net Audit</th>
                <th>Qty</th>
                <th>Rusak</th>
                <th>RS</th>
                <th>SP</th>
                <th>WR</th>
                <th>KM</th>
                <th>HP Aktif</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows_out)): ?>
                <tr><td colspan="11">Tidak ada data untuk periode ini.</td></tr>
            <?php else: foreach ($rows_out as $row): ?>
                <tr>
                    <td><?= esc(substr($row['date'], 8, 2)) ?></td>
                    <td class="currency"><?= number_format((int)$row['gross'],0,',','.') ?></td>
                    <td class="currency"><b><?= number_format((int)$row['net_audit'],0,',','.') ?></b></td>
                    <td><?= number_format((int)$row['qty'],0,',','.') ?></td>
                    <td style="color:#c0392b;">
                        <?= number_format((int)$row['rusak_qty'],0,',','.') ?>
                    </td>
                    <td><?= number_format((int)$row['rs'],0,',','.') ?></td>
                    <td><?= number_format((int)$row['sp'],0,',','.') ?></td>
                    <td><?= number_format((int)$row['wr'],0,',','.') ?></td>
                    <td><?= number_format((int)$row['km'],0,',','.') ?></td>
                    <td><b><?= number_format((int)$row['active'],0,',','.') ?></b></td>
                    <td style="font-size:11px;"><?= esc(format_bytes_short((int)$row['bandwidth'])) ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
        <tfoot>
            <tr style="background:#333;color:#fff;font-weight:bold;">
                <td>TOTAL</td>
                <td class="currency"><?= number_format((int)$total_gross,0,',','.') ?></td>
                <td class="currency"><?= number_format((int)$total_net_audit,0,',','.') ?></td>
                <td><?= number_format((int)$total_qty_laku,0,',','.') ?></td>
                <td style="color:#ffb3b3;"><?= number_format((int)$total_voucher_rusak,0,',','.') ?></td>
                <td><?= number_format((int)$total_rusak_device,0,',','.') ?></td>
                <td><?= number_format((int)$total_spam,0,',','.') ?></td>
                <td><?= number_format((int)$max_wr,0,',','.') ?></td>
                <td><?= number_format((int)$max_km,0,',','.') ?></td>
                <td><?= number_format((int)$max_active,0,',','.') ?></td>
                <td><?= esc(format_bytes_short((int)$total_bandwidth)) ?></td>
            </tr>
        </tfoot>
    </table>

    <div class="footnote">
        <strong>Keterangan:</strong> RS = Rusak, SP = Spam, WR = Wartel, KM = Kamtib. Net Audit mengambil data audit manual jika ada, jika tidak memakai net system.
    </div>

<script>
function shareReport(){
    if (navigator.share) {
        navigator.share({ title: 'Rekap Bulanan', url: window.location.href });
    } else {
        window.prompt('Salin link laporan:', window.location.href);
    }
}
</script>
</body>
</html>
