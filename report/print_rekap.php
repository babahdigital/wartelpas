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

$req_show = $_GET['show'] ?? 'harian';
$filter_date = $_GET['date'] ?? '';
if ($req_show === 'harian') {
    $filter_date = $filter_date ?: date('Y-m-d');
} elseif ($req_show === 'bulanan') {
    $filter_date = $filter_date ?: date('Y-m');
} else {
    $req_show = 'tahunan';
    $filter_date = $filter_date ?: date('Y');
}

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

function normalize_block_name($blok_name, $comment = '') {
    $raw = strtoupper(trim((string)$blok_name));
    if ($raw === '' && $comment !== '') {
        if (preg_match('/\bblok\s*[-_]?\s*([A-Z0-9]+)\b/i', $comment, $m)) {
            $raw = strtoupper($m[1]);
        }
    }
    if ($raw === '') return 'BLOK-LAIN';
    $raw = preg_replace('/^BLOK[-_\s]*/', '', $raw);
    if (preg_match('/^([A-Z]+)/', $raw, $m)) {
        $raw = $m[1];
    }
    return 'BLOK-' . $raw;
}

function detect_profile_minutes($profile) {
    $p = strtolower((string)$profile);
    if (preg_match('/\b10\s*(menit|m)\b/i', $p)) return '10';
    if (preg_match('/\b30\s*(menit|m)\b/i', $p)) return '30';
    return 'OTHER';
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

function format_date_ddmmyyyy($dateStr) {
    $ts = strtotime((string)$dateStr);
    if ($ts === false) return $dateStr;
    return date('d-m-Y', $ts);
}

// Helper untuk membuat tabel bersarang di kolom audit
function generate_nested_table($items, $align = 'left') {
    if (empty($items)) return '-';
    $html = '<table style="width:100%; border-collapse:collapse; margin:0; padding:0; background:transparent;">';
    $count = count($items);
    foreach ($items as $i => $val) {
        $border = ($i < $count - 1) ? 'border-bottom:1px solid #999;' : ''; 
        $html .= '<tr><td style="border:none; padding:4px 2px; '.$border.' text-align: center; vertical-align:middle; line-height:1.2; word-wrap:break-word;">'.htmlspecialchars($val).'</td></tr>';
    }
    $html .= '</table>';
    return $html;
}

// Helper khusus untuk username audit (warna rusak/normal)
function generate_nested_table_user($items, $align = 'left') {
    if (empty($items)) return '-';
    $html = '<table style="width:100%; border-collapse:collapse; margin:0; padding:0; background:transparent;">';
    $count = count($items);
    foreach ($items as $i => $item) {
        $label = is_array($item) ? ($item['label'] ?? '-') : (string)$item;
        $status = is_array($item) ? strtolower((string)($item['status'] ?? '')) : '';
        
        // Logika Warna:
        // Merah = Rusak
        // Hijau = Retur
        // Kuning = Tidak Terlapor (Default evidence tapi tidak rusak/retur)
        // Transparan = Jika labelnya "-" atau kosong
        if ($label === '-' || trim($label) === '') {
            $bg = 'transparent';
        } elseif ($status === 'rusak') {
            $bg = '#fecaca'; // Merah
        } elseif ($status === 'retur') {
            $bg = '#dcfce7'; // Hijau
        } else {
            $bg = '#fef3c7'; // Kuning
        }

        $border = ($i < $count - 1) ? 'border-bottom:1px solid #999;' : '';
        $html .= '<tr><td style="border:none; padding:4px 2px; '.$border.' text-align:'.$align.'; vertical-align:middle; line-height:1.2; word-wrap:break-word; background:'.$bg.';">'.htmlspecialchars($label).'</td></tr>';
    }
    $html .= '</table>';
    return $html;
}

$rows = [];
$hp_total_units = 0;
$hp_active_units = 0;
$hp_rusak_units = 0;
$hp_spam_units = 0;
$hp_wartel_units = 0;
$hp_kamtib_units = 0;
$audit_rows = [];
$audit_total_expected_qty = 0;
$audit_total_reported_qty = 0;
$audit_total_expected_setoran = 0;
$audit_total_actual_setoran = 0;
$audit_total_selisih_qty = 0;
$audit_total_selisih_setoran = 0;
$hp_active_by_block = [];
$hp_stats_by_block = [];
$hp_units_by_block = [];
$block_summaries = [];
$valid_blocks = [];

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

        if ($req_show === 'harian') {
            $stmtHp = $db->prepare("SELECT
                    SUM(total_units) AS total_units,
                    SUM(active_units) AS active_units,
                    SUM(rusak_units) AS rusak_units,
                    SUM(spam_units) AS spam_units
                FROM phone_block_daily
                WHERE report_date = :d AND unit_type = 'TOTAL'");
            $stmtHp->execute([':d' => $filter_date]);
            $hp = $stmtHp->fetch(PDO::FETCH_ASSOC) ?: [];
            $hp_total_units = (int)($hp['total_units'] ?? 0);
            $hp_active_units = (int)($hp['active_units'] ?? 0);
            $hp_rusak_units = (int)($hp['rusak_units'] ?? 0);
            $hp_spam_units = (int)($hp['spam_units'] ?? 0);

            $stmtHpBlock = $db->prepare("SELECT blok_name, SUM(active_units) AS active_units
                FROM phone_block_daily
                WHERE report_date = :d AND unit_type = 'TOTAL'
                GROUP BY blok_name");
            $stmtHpBlock->execute([':d' => $filter_date]);
            $hpBlockRows = $stmtHpBlock->fetchAll(PDO::FETCH_ASSOC);
            foreach ($hpBlockRows as $hb) {
                $blk = normalize_block_name($hb['blok_name'] ?? '');
                $hp_active_by_block[$blk] = (int)($hb['active_units'] ?? 0);
                if ($blk !== '') $valid_blocks[$blk] = true;
            }

            $stmtHpStats = $db->prepare("SELECT blok_name,
                    SUM(total_units) AS total_units,
                    SUM(active_units) AS active_units,
                    SUM(rusak_units) AS rusak_units,
                    SUM(spam_units) AS spam_units
                FROM phone_block_daily
                WHERE report_date = :d AND unit_type = 'TOTAL'
                GROUP BY blok_name");
            $stmtHpStats->execute([':d' => $filter_date]);
            $hpStatsRows = $stmtHpStats->fetchAll(PDO::FETCH_ASSOC);
            foreach ($hpStatsRows as $hs) {
                $blk = normalize_block_name($hs['blok_name'] ?? '');
                $hp_stats_by_block[$blk] = [
                    'total' => (int)($hs['total_units'] ?? 0),
                    'active' => (int)($hs['active_units'] ?? 0),
                    'rusak' => (int)($hs['rusak_units'] ?? 0),
                    'spam' => (int)($hs['spam_units'] ?? 0)
                ];
            }

            $stmtHpUnitsBlock = $db->prepare("SELECT blok_name, unit_type, SUM(total_units) AS total_units
                FROM phone_block_daily
                WHERE report_date = :d AND unit_type IN ('WARTEL','KAMTIB')
                GROUP BY blok_name, unit_type");
            $stmtHpUnitsBlock->execute([':d' => $filter_date]);
            $hpUnitRows = $stmtHpUnitsBlock->fetchAll(PDO::FETCH_ASSOC);
            foreach ($hpUnitRows as $hu) {
                $blk = normalize_block_name($hu['blok_name'] ?? '');
                if (!isset($hp_units_by_block[$blk])) $hp_units_by_block[$blk] = ['WARTEL' => 0, 'KAMTIB' => 0];
                $ut = strtoupper((string)($hu['unit_type'] ?? ''));
                if ($ut === 'WARTEL') $hp_units_by_block[$blk]['WARTEL'] = (int)($hu['total_units'] ?? 0);
                if ($ut === 'KAMTIB') $hp_units_by_block[$blk]['KAMTIB'] = (int)($hu['total_units'] ?? 0);
            }

            $stmtHp2 = $db->prepare("SELECT unit_type, SUM(total_units) AS total_units
                FROM phone_block_daily
                WHERE report_date = :d AND unit_type IN ('WARTEL','KAMTIB')
                GROUP BY unit_type");
            $stmtHp2->execute([':d' => $filter_date]);
            $hpRows = $stmtHp2->fetchAll(PDO::FETCH_ASSOC);
            foreach ($hpRows as $hr) {
                $ut = strtoupper((string)($hr['unit_type'] ?? ''));
                if ($ut === 'WARTEL') $hp_wartel_units = (int)($hr['total_units'] ?? 0);
                if ($ut === 'KAMTIB') $hp_kamtib_units = (int)($hr['total_units'] ?? 0);
            }

            $stmtAudit = $db->prepare("SELECT * FROM audit_rekap_manual WHERE report_date = :d ORDER BY blok_name");
            $stmtAudit->execute([':d' => $filter_date]);
            $audit_rows = $stmtAudit->fetchAll(PDO::FETCH_ASSOC);
            foreach ($audit_rows as $ar) {
                $audit_total_expected_qty += (int)($ar['expected_qty'] ?? 0);
                $audit_total_reported_qty += (int)($ar['reported_qty'] ?? 0);
                $audit_total_expected_setoran += (int)($ar['expected_setoran'] ?? 0);
                $audit_total_actual_setoran += (int)($ar['actual_setoran'] ?? 0);
                $audit_total_selisih_qty += (int)($ar['selisih_qty'] ?? 0);
                $audit_total_selisih_setoran += (int)($ar['selisih_setoran'] ?? 0);
            }
        }
    }
} catch (Exception $e) {
    $rows = [];
}

$total_gross = 0;
$total_rusak = 0;
$total_invalid = 0;
$total_net = 0;
$total_qty = 0;
$total_qty_retur = 0;
$total_qty_rusak = 0;
$total_qty_invalid = 0;
$total_qty_laku = 0;
$rusak_10m = 0;
$rusak_30m = 0;
$total_qty_units = 0;
$total_net_units = 0;
$total_bandwidth = 0;

$seen_sales = [];
$seen_user_day = [];
$unique_laku_users = [];

foreach ($rows as $r) {
    $sale_date = $r['sale_date'] ?: norm_date_from_raw_report($r['raw_date'] ?? '');
    $sale_time = $r['sale_time'] ?? ($r['raw_time'] ?? '');
    $match = false;
    if ($req_show === 'harian') $match = ($sale_date === $filter_date);
    elseif ($req_show === 'bulanan') $match = (strpos((string)$sale_date, $filter_date) === 0);
    else $match = (strpos((string)$sale_date, $filter_date) === 0);
    if (!$match) continue;

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
        $unique_key = $username . '|' . ($r['sale_datetime'] ?? ($sale_date . ' ' . ($sale_time ?? '')));
        if ($unique_key === $username . '|') {
            $unique_key = $username . '|' . $sale_date . '|' . ($sale_time ?? '');
        }
    } elseif ($sale_date !== '') {
        $unique_key = 'date|' . $sale_date . '|' . ($sale_time ?? '');
    }
    if ($unique_key !== '') {
        if (isset($seen_sales[$unique_key])) continue;
        $seen_sales[$unique_key] = true;
    }

    $price = (int)($r['price_snapshot'] ?? $r['price'] ?? 0);
    $qty = (int)($r['qty'] ?? 0);
    if ($qty <= 0) $qty = 1;
    $line_price = $price * $qty;
    $comment = (string)($r['comment'] ?? '');
    $blok_row = (string)($r['blok_name'] ?? '');
    if ($blok_row === '' && !preg_match('/\bblok\s*[-_]?\s*[A-Za-z0-9]+/i', $comment)) {
        continue;
    }
    $block = normalize_block_name($r['blok_name'] ?? '', $comment);
    $status = strtolower((string)($r['status'] ?? ''));
    $lh_status = strtolower((string)($r['last_status'] ?? ''));
    $profile = $r['profile_snapshot'] ?? ($r['profile'] ?? '-');
    $cmt_low = strtolower($comment);
    $bytes = (int)($r['last_bytes'] ?? 0);
    if ($bytes < 0) $bytes = 0;

    if ($status === '' || $status === 'normal') {
        if ((int)($r['is_invalid'] ?? 0) === 1) $status = 'invalid';
        elseif ((int)($r['is_retur'] ?? 0) === 1) $status = 'retur';
        elseif ((int)($r['is_rusak'] ?? 0) === 1) $status = 'rusak';
        elseif (strpos($cmt_low, 'invalid') !== false) $status = 'invalid';
        elseif (strpos($cmt_low, 'retur') !== false || $lh_status === 'retur') $status = 'retur';
        elseif (strpos($cmt_low, 'rusak') !== false || $lh_status === 'rusak') $status = 'rusak';
        else $status = 'normal';
    }

    $gross_add = ($status === 'invalid') ? 0 : $line_price;
    $loss_rusak = ($status === 'rusak') ? $line_price : 0;
    $loss_invalid = ($status === 'invalid') ? $line_price : 0;
    $net_add = $gross_add - $loss_rusak - $loss_invalid;

    $total_bandwidth += $bytes;

    $is_laku = !in_array($status, ['rusak', 'retur', 'invalid'], true);
    if ($is_laku && $username !== '') {
        $unique_laku_users[$username] = true;
    }

    if ($req_show === 'harian') {
        $qty_count = 1;
        $gross_line = ($status === 'invalid') ? 0 : $line_price;
        $loss_rusak_line = ($status === 'rusak') ? $line_price : 0;
        $loss_invalid_line = ($status === 'invalid') ? $line_price : 0;
        $net_line = $gross_line - $loss_rusak_line - $loss_invalid_line;

        if ($is_laku) {
            $total_qty_units += $qty_count;
        }
        $total_net_units += $net_line;

        $bucket = detect_profile_minutes($profile);
        if (!isset($block_summaries[$block])) {
            $block_summaries[$block] = [
                'total_qty' => 0,
                'total_amount' => 0,
                'total_bw' => 0,
                'qty_10' => 0,
                'qty_30' => 0,
                'amt_10' => 0,
                'amt_30' => 0,
                'rs_10' => 0,
                'rt_10' => 0,
                'rs_30' => 0,
                'rt_30' => 0,
                'rs_total' => 0,
                'rt_total' => 0
            ];
        }
        $bw_line = $bytes;
        if ($bucket === '10') {
            if ($is_laku) {
                $block_summaries[$block]['qty_10'] += $qty_count;
                $block_summaries[$block]['amt_10'] += $net_line;
            }
            if ($status === 'rusak') $block_summaries[$block]['rs_10'] += $qty_count;
            if ($status === 'retur') $block_summaries[$block]['rt_10'] += $qty_count;
        }
        if ($bucket === '30') {
            if ($is_laku) {
                $block_summaries[$block]['qty_30'] += $qty_count;
                $block_summaries[$block]['amt_30'] += $net_line;
            }
            if ($status === 'rusak') $block_summaries[$block]['rs_30'] += $qty_count;
            if ($status === 'retur') $block_summaries[$block]['rt_30'] += $qty_count;
        }
        if ($is_laku) {
            $block_summaries[$block]['total_qty'] += $qty_count;
            $block_summaries[$block]['total_amount'] += $net_line;
            $block_summaries[$block]['total_bw'] += $bw_line;
        }
        if ($status === 'rusak') $block_summaries[$block]['rs_total'] += $qty_count;
        if ($status === 'retur') $block_summaries[$block]['rt_total'] += $qty_count;
    }

    $total_qty++;
    $laku_add = ($req_show === 'harian') ? ($qty_count ?? 1) : 1;
    if ($is_laku) $total_qty_laku += $laku_add;
    if ($status === 'retur') $total_qty_retur++;
    if ($status === 'rusak') {
        $total_qty_rusak++;
        $p = strtolower((string)$profile);
        if (preg_match('/\b10\s*(menit|m)\b/i', $p)) $rusak_10m++;
        elseif (preg_match('/\b30\s*(menit|m)\b/i', $p)) $rusak_30m++;
    }
    if ($status === 'invalid') $total_qty_invalid++;

    $total_gross += $gross_add;
    $total_rusak += $loss_rusak;
    $total_invalid += $loss_invalid;
    $total_net += $net_add;
}

$total_qty_laku = count($unique_laku_users);
$period_label = $req_show === 'harian' ? 'Harian' : ($req_show === 'bulanan' ? 'Bulanan' : 'Tahunan');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Print Rekap Laporan</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; color:#111; margin:20px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        h2 { margin:0 0 6px 0; }
        .meta { font-size:12px; color:#555; margin-bottom:12px; }
        .toolbar { margin-bottom:14px; display:flex; gap:8px; flex-wrap:wrap; }
        .btn { padding:6px 10px; border:1px solid #999; background:#f2f2f2; cursor:pointer; border-radius:4px; font-size:12px; }
        .grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:10px; }
        .card { border:1px solid #ddd; padding:10px; border-radius:6px; }
        .label { font-size:11px; color:#666; text-transform:uppercase; letter-spacing:.5px; }
        .value { font-size:18px; font-weight:700; margin-top:4px; }
        .small { font-size:12px; color:#555; margin-top:4px; }
        .rekap-table { width:100%; border-collapse:collapse; font-size:12px; margin-top:16px; }
        .rekap-table th, .rekap-table td { border:1px solid #000; padding:6px; vertical-align:middle; }
        .rekap-table th { background:#f0f3f7; text-align:center; vertical-align:middle; }
        .rekap-detail { width:100%; border-collapse:collapse; font-size:12px; }
        .rekap-detail th, .rekap-detail td { border:1px solid #000; padding:5px; }
        .rekap-detail th { background:#e9eef5; text-align:center; }
        .rekap-detail td { vertical-align: middle; }
        .rekap-detail tr:nth-child(even) td { background:#fbfbfd; }
        .rekap-total { background:#d7dee8; font-weight:700; }
        .rekap-subtotal { background:#e8edf4; font-weight:700; }
        .rekap-hp { text-align:center; vertical-align:middle; font-weight:700; }
        /* Style untuk nested table audit */
        .nested-cell-table td { border-bottom: 1px solid #ccc; }
        .nested-cell-table td:last-child { border-bottom: none; }
        /* Style untuk Summary Audit Box */
        .audit-summary-box { margin-top: 25px; border: 1px solid #000; padding: 15px; border-radius: 4px; background-color: #fdfdfd; }
        .audit-summary-header { font-weight: bold; font-size: 14px; margin-bottom: 10px; border-bottom: 2px solid #ddd; padding-bottom: 5px; }
        .audit-item { font-size: 12px; margin-bottom: 8px; line-height: 1.5; }
        .audit-item strong { color: #000; }
        .audit-details-list { margin: 4px 0 0 15px; padding: 0; list-style-type: none; color: #444; }
        .audit-details-list li::before { content: "- "; font-weight: bold; }
        
        @media print { 
            .toolbar { display:none; } 
            .audit-summary-box { page-break-inside: avoid; }
            .dul-gap { margin-top: 20% !important; padding-top:2%; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button class="btn" onclick="window.print()">Print / Download PDF</button>
        <button class="btn" onclick="shareReport()">Share</button>
    </div>

    <h2>Rekap Laporan Penjualan</h2>
    <div class="meta">Periode: <?= htmlspecialchars($period_label) ?> | Tanggal: <?= htmlspecialchars(format_date_ddmmyyyy($filter_date)) ?></div>

    <div class="grid">
        <div class="card">
            <div class="label">Pendapatan Kotor</div>
            <div class="value"><?= $cur ?> <?= number_format($total_gross,0,',','.') ?></div>
        </div>
        <div class="card">
            <div class="label">Pendapatan Bersih</div>
            <div class="value"><?= $cur ?> <?= number_format($total_net,0,',','.') ?></div>
        </div>
        <div class="card">
            <div class="label">Total Voucher Laku</div>
            <div class="value"><?= number_format($total_qty_laku,0,',','.') ?></div>
            <div class="small">Bandwith: <?= htmlspecialchars(format_bytes_short($total_bandwidth)) ?></div>
        </div>
        <div class="card">
            <div class="label">Voucher Rusak</div>
            <div class="value"><?= number_format($total_qty_rusak,0,',','.') ?></div>
            <div class="small">10 Menit: <?= number_format($rusak_10m,0,',','.') ?> | 30 Menit: <?= number_format($rusak_30m,0,',','.') ?></div>
        </div>
        <?php if ($req_show === 'harian'): ?>
        <div class="card">
            <div class="label">Total Handphone</div>
            <div class="value"><?= number_format($hp_total_units,0,',','.') ?></div>
            <div class="small">WARTEL: <?= number_format($hp_wartel_units,0,',','.') ?> | KAMTIB: <?= number_format($hp_kamtib_units,0,',','.') ?></div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($req_show === 'harian'): ?>
        <?php
            if (!empty($block_summaries)) {
                foreach ($block_summaries as $blk => $bdata) {
                    $has_data = ((int)($bdata['total_qty'] ?? 0) > 0)
                        || ((int)($bdata['total_amount'] ?? 0) > 0)
                        || ((int)($bdata['total_bw'] ?? 0) > 0)
                        || ((int)($bdata['qty_10'] ?? 0) > 0)
                        || ((int)($bdata['qty_30'] ?? 0) > 0)
                        || ((int)($bdata['rs_10'] ?? 0) > 0)
                        || ((int)($bdata['rs_30'] ?? 0) > 0)
                        || ((int)($bdata['rt_10'] ?? 0) > 0)
                        || ((int)($bdata['rt_30'] ?? 0) > 0)
                        || ((int)($bdata['rs_total'] ?? 0) > 0)
                        || ((int)($bdata['rt_total'] ?? 0) > 0);
                    if (!$has_data) {
                        unset($block_summaries[$blk]);
                    }
                }
            }
            ksort($block_summaries);
        ?>
        <table class="rekap-table">
            <thead>
                <tr>
                    <th>Rincian Penjualan</th>
                    <th style="width:90px;">QTY</th>
                    <th style="width:90px;">Pendapatan</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <table class="rekap-detail">
                            <thead>
                                <tr>
                                    <th rowspan="2" style="width:170px;">Jenis Blok</th>
                                    <th colspan="3" style="width:150px;">Qty</th>
                                    <th rowspan="2" style="width:90px;">Pendapatan</th>
                                    <th rowspan="2" style="width:80px;">Qty</th>
                                    <th rowspan="2" style="width:90px;">Total Blok</th>
                                    <th rowspan="2" style="width:90px;">Bytes</th>
                                    <th colspan="3" style="width:210px;">Device</th>
                                    <th colspan="2" style="width:140px;">Unit</th>
                                    <th rowspan="2" style="width:70px;">Aktif</th>
                                </tr>
                                <tr>
                                    <th style="width:50px;">Total</th>
                                    <th style="width:50px;">RS</th>
                                    <th style="width:50px;">RT</th>
                                    <th style="width:70px;">Total</th>
                                    <th style="width:70px;">RS</th>
                                    <th style="width:70px;">SP</th>
                                    <th style="width:70px;">WR</th>
                                    <th style="width:70px;">KM</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($block_summaries)): ?>
                                    <tr><td colspan="14" style="text-align:center;">Tidak ada data</td></tr>
                                <?php else: ?>
                                    <?php foreach ($block_summaries as $blk => $bdata): ?>
                                        <?php
                                            $hp_active_val = (int)($hp_active_by_block[$blk] ?? 0);
                                            $hp_stat = $hp_stats_by_block[$blk] ?? ['total' => 0, 'active' => 0, 'rusak' => 0, 'spam' => 0];
                                            $hp_unit = $hp_units_by_block[$blk] ?? ['WARTEL' => 0, 'KAMTIB' => 0];
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($blk) ?>10</td>
                                            <td style="text-align:center;"><?= number_format((int)$bdata['qty_10'],0,',','.') ?></td>
                                            <td style="text-align:center;"><?= number_format((int)$bdata['rs_10'],0,',','.') ?></td>
                                            <td style="text-align:center;"><?= number_format((int)$bdata['rt_10'],0,',','.') ?></td>
                                            <td style="text-align:right;"><?= number_format((int)$bdata['amt_10'],0,',','.') ?></td>
                                            <td style="text-align:center;" rowspan="3"><?= number_format((int)$bdata['total_qty'],0,',','.') ?></td>
                                            <td style="text-align:right;" rowspan="3"><?= number_format((int)$bdata['total_amount'],0,',','.') ?></td>
                                            <td style="text-align:right;" rowspan="3"><?= htmlspecialchars(format_bytes_short((int)$bdata['total_bw'])) ?></td>
                                            <td class="rekap-hp" rowspan="3"><?= number_format((int)$hp_stat['total'],0,',','.') ?></td>
                                            <td class="rekap-hp" rowspan="3"><?= number_format((int)$hp_stat['rusak'],0,',','.') ?></td>
                                            <td class="rekap-hp" rowspan="3"><?= number_format((int)$hp_stat['spam'],0,',','.') ?></td>
                                            <td class="rekap-hp" rowspan="3"><?= number_format((int)$hp_unit['WARTEL'],0,',','.') ?></td>
                                            <td class="rekap-hp" rowspan="3"><?= number_format((int)$hp_unit['KAMTIB'],0,',','.') ?></td>
                                            <td class="rekap-hp" rowspan="3"><?= number_format((int)$hp_stat['active'],0,',','.') ?></td>
                                        </tr>
                                        <tr>
                                            <td><?= htmlspecialchars($blk) ?>30</td>
                                            <td style="text-align:center;"><?= number_format((int)$bdata['qty_30'],0,',','.') ?></td>
                                            <td style="text-align:center;"><?= number_format((int)$bdata['rs_30'],0,',','.') ?></td>
                                            <td style="text-align:center;"><?= number_format((int)$bdata['rt_30'],0,',','.') ?></td>
                                            <td style="text-align:right;"><?= number_format((int)$bdata['amt_30'],0,',','.') ?></td>
                                        </tr>
                                        <tr class="rekap-subtotal">
                                            <td style="text-align:right;">Total <?= htmlspecialchars($blk) ?> :</td>
                                            <td style="text-align:center;"><?= number_format((int)$bdata['total_qty'],0,',','.') ?></td>
                                            <td style="text-align:center;"><?= number_format((int)$bdata['rs_total'],0,',','.') ?></td>
                                            <td style="text-align:center;"><?= number_format((int)$bdata['rt_total'],0,',','.') ?></td>
                                            <td style="text-align:right;"><?= number_format((int)$bdata['total_amount'],0,',','.') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </td>
                    <td style="text-align:center; font-weight:700; font-size:14px;"><?= number_format((int)$total_qty_laku,0,',','.') ?></td>
                    <td style="text-align:right; font-weight:700; font-size:14px;"><?= number_format((int)$total_net_units,0,',','.') ?></td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top:8px; font-size:11px; color:#444;">
            Keterangan: RS = Rusak, RT = Retur, SP = Spam, WR = Wartel, KM = Kamtib.
        </div>
        <div style="margin-top:4px; font-size:11px; color:#444;">
            Catatan: Data rekap adalah acuan resmi untuk keuangan karena berasal dari transaksi. Daftar user digunakan untuk memantau status user online/terpakai.
        </div> 

        <?php if (!empty($audit_rows)): ?>
            <?php 
                // Array untuk menampung data summary
                $audit_summary_report = []; 
            ?>
            <h2 class="dul-gap" style="margin-top:35px;">Rekap Audit Penjualan Lapangan</h2>
            <div class="meta">Periode: <?= htmlspecialchars($period_label) ?> | Tanggal: <?= htmlspecialchars(format_date_ddmmyyyy($filter_date)) ?></div>

            <table class="rekap-table" style="margin-top:15px;">
                <thead>
                    <tr>
                        <th colspan="13">Audit Manual Rekap Harian</th>
                    </tr>
                    <tr>
                        <th rowspan="2" style="width:90px;">Blok</th>
                        <th rowspan="2" style="width:70px;">QTY</th>
                        <th rowspan="2" style="width:70px;">Selisih</th>
                        <th rowspan="2" style="width:90px;">Setoran</th>
                        <th rowspan="2" style="width:80px;">Selisih</th>
                        <th colspan="4">Profil 10 Menit</th>
                        <th colspan="4">Profil 30 Menit</th>
                    </tr>
                    <tr>
                        <th style="width:90px;">User</th>
                        <th style="width:70px;">Up</th>
                        <th style="width:70px;">Byte</th>
                        <th style="width:70px;">QTY</th>
                        <th style="width:90px;">User</th>
                        <th style="width:70px;">Up</th>
                        <th style="width:70px;">Byte</th>
                        <th style="width:70px;">QTY</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                        $audit_total_profile_qty_10 = 0;
                        $audit_total_profile_qty_30 = 0;
                    ?>
                    <?php foreach ($audit_rows as $idx => $ar): ?>
                        <?php
                            $evidence = [];
                            $profile_qty = [];
                            $profile10 = ['user' => [], 'up' => [], 'byte' => [], 'login' => [], 'total' => []];
                            $profile30 = ['user' => [], 'up' => [], 'byte' => [], 'login' => [], 'total' => []];
                            $profile10_sum = 0;
                            $profile30_sum = 0;
                            $cnt_rusak_10 = 0;
                            $cnt_rusak_30 = 0;
                            $cnt_unreported_10 = 0;
                            $cnt_unreported_30 = 0;
                            $cnt_retur_10 = 0;
                            $cnt_retur_30 = 0;

                            if (!empty($ar['user_evidence'])) {
                                $evidence = json_decode((string)$ar['user_evidence'], true);
                                if (is_array($evidence)) {
                                    if (!empty($evidence['profile_qty']) && is_array($evidence['profile_qty'])) {
                                        $profile_qty = $evidence['profile_qty'];
                                    }
                                    if (!empty($evidence['users']) && is_array($evidence['users'])) {
                                        foreach ($evidence['users'] as $uname => $ud) {
                                            $cnt = isset($ud['events']) && is_array($ud['events']) ? count($ud['events']) : 0;
                                            $upt = trim((string)($ud['last_uptime'] ?? ''));
                                            $lb = format_bytes_short((int)($ud['last_bytes'] ?? 0));
                                            $price_val = (int)($ud['price'] ?? 0);
                                            $upt = $upt !== '' ? $upt : '-';
                                            $kind = (string)($ud['profile_kind'] ?? '10');
                                            $bucket = ($kind === '30') ? $profile30 : $profile10;
                                            // Collecting data
                                            $u_status = strtolower((string)($ud['last_status'] ?? ''));
                                            $is_unreported = ($uname !== '' && $uname !== '-') && ($u_status !== 'rusak') && ($u_status !== 'retur');
                                            $bucket['user'][] = ['label' => (string)$uname, 'status' => $u_status];
                                            $bucket['up'][] = $upt;
                                            $bucket['byte'][] = $lb;
                                            $bucket['login'][] = $cnt . 'x';
                                            $bucket['total'][] = number_format($price_val,0,',','.');
                                            
                                            if ($kind === '30') {
                                                $profile30_sum += $price_val;
                                                $profile30 = $bucket;
                                                if($u_status === 'rusak') $cnt_rusak_30++;
                                                if($u_status === 'retur') $cnt_retur_30++;
                                                if ($is_unreported) $cnt_unreported_30++;
                                            } else {
                                                $profile10_sum += $price_val;
                                                $profile10 = $bucket;
                                                if($u_status === 'rusak') $cnt_rusak_10++;
                                                if($u_status === 'retur') $cnt_retur_10++;
                                                if ($is_unreported) $cnt_unreported_10++;
                                            }
                                        }
                                    } else {
                                        // Fallback legacy format
                                        $cnt = isset($evidence['events']) && is_array($evidence['events']) ? count($evidence['events']) : 0;
                                        $upt = trim((string)($evidence['last_uptime'] ?? ''));
                                        $lb = format_bytes_short((int)($evidence['last_bytes'] ?? 0));
                                        $price_val = (int)($evidence['price'] ?? 0);
                                        $upt = $upt !== '' ? $upt : '-';
                                        $profile10['user'][] = ['label' => '-', 'status' => ''];
                                        $profile10['up'][] = $upt;
                                        $profile10['byte'][] = $lb;
                                        $profile10['login'][] = $cnt . 'x';
                                        $profile10['total'][] = number_format($price_val,0,',','.');
                                        $profile10_sum += $price_val;
                                    }
                                }
                            }
                            // Generate HTML Tables using helper
                            $p10_us = generate_nested_table_user($profile10['user'], 'center');
                            $p10_up = generate_nested_table($profile10['up'], 'right');
                            $p10_bt = generate_nested_table($profile10['byte'], 'right');
                            
                            $p30_us = generate_nested_table_user($profile30['user'], 'center');
                            $p30_up = generate_nested_table($profile30['up'], 'right');
                            $p30_bt = generate_nested_table($profile30['byte'], 'right');

                            $p10_qty = (int)($profile_qty['qty_10'] ?? 0);
                            $p30_qty = (int)($profile_qty['qty_30'] ?? 0);
                            if ($p10_qty <= 0) $p10_qty = count($profile10['user'] ?? []);
                            if ($p30_qty <= 0) $p30_qty = count($profile30['user'] ?? []);
                            $p10_tt = $p10_qty > 0 ? number_format($p10_qty,0,',','.') : '-';
                            $p30_tt = $p30_qty > 0 ? number_format($p30_qty,0,',','.') : '-';
                            $audit_total_profile_qty_10 += $p10_qty;
                            $audit_total_profile_qty_30 += $p30_qty;
                            $price10 = 5000;
                            $price30 = 20000;
                            $p10_sum_calc = $profile10_sum > 0 ? $profile10_sum : ($p10_qty > 0 ? $p10_qty * $price10 : null);
                            $p30_sum_calc = $profile30_sum > 0 ? $profile30_sum : ($p30_qty > 0 ? $p30_qty * $price30 : null);
                            
                            // Calculate Unreported Ghost Units (No User Info)
                            $selisih_qty_total = (int)($ar['selisih_qty'] ?? 0);
                            $identified_gap = $cnt_unreported_10 + $cnt_unreported_30;
                            $unknown_missing = 0;
                            // Jika ada selisih positif (kurang lapor) dan jumlah user yg teridentifikasi kurang dari total selisih
                            if ($selisih_qty_total > 0) {
                                $unknown_missing = $selisih_qty_total - $identified_gap;
                                if ($unknown_missing < 0) $unknown_missing = 0;
                            }

                            // Capture data for summary
                            $audit_summary_report[] = [
                                'blok' => $ar['blok_name'] ?? '-',
                                'selisih_setoran' => (int)($ar['selisih_setoran'] ?? 0),
                                'p10_qty' => $p10_qty,
                                'p10_sum' => $p10_sum_calc,
                                'p30_qty' => $p30_qty,
                                'p30_sum' => $p30_sum_calc,
                                'unreported_total' => (int)($cnt_unreported_10 + $cnt_unreported_30),
                                'unreported_10' => (int)$cnt_unreported_10,
                                'unreported_30' => (int)$cnt_unreported_30,
                                'unknown_missing' => $unknown_missing,
                                'rusak_10' => $cnt_rusak_10,
                                'rusak_30' => $cnt_rusak_30,
                                'retur_10' => (int)$cnt_retur_10,
                                'retur_30' => (int)$cnt_retur_30
                            ];
                        ?>
                        <tr>
                            <td style="text-align: center;"><?= htmlspecialchars($ar['blok_name'] ?? '-') ?></td>
                            <td style="text-align:center;"><?= number_format((int)($ar['reported_qty'] ?? 0),0,',','.') ?></td>
                            <td style="text-align:center;"><?= number_format((int)($ar['selisih_qty'] ?? 0),0,',','.') ?></td>
                            <td style="text-align:right;"><?= number_format((int)($ar['actual_setoran'] ?? 0),0,',','.') ?></td>
                            <td style="text-align:right;"><?= number_format((int)($ar['selisih_setoran'] ?? 0),0,',','.') ?></td>
                            
                            <td style="padding:0; text-align: center;"><?= $p10_us ?></td>
                            <td style="padding:0; text-align: center;"><?= $p10_up ?></td>
                            <td style="padding:0; text-align: center;"><?= $p10_bt ?></td>
                            <td style="text-align: center; font-weight:bold;"><?= $p10_tt ?></td>
                            
                            <td style="padding:0; text-align: center;"><?= $p30_us ?></td>
                            <td style="padding:0; text-align: center;"><?= $p30_up ?></td>
                            <td style="padding:0; text-align: center;"><?= $p30_bt ?></td>
                            <td style="text-align: center; font-weight:bold;"><?= $p30_tt ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td style="text-align:center;"><b>Total</b></td>
                        <td style="text-align:center;"><b><?= number_format($audit_total_reported_qty,0,',','.') ?></b></td>
                        <td style="text-align:center;"><b><?= number_format($audit_total_selisih_qty,0,',','.') ?></b></td>
                        <td style="text-align:right;"><b><?= number_format($audit_total_actual_setoran,0,',','.') ?></b></td>
                        <td style="text-align:right;"><b><?= number_format($audit_total_selisih_setoran,0,',','.') ?></b></td>
                        <td colspan="3" style="background:#eee;"></td>
                        <td style="text-align:center;"><b><?= number_format($audit_total_profile_qty_10,0,',','.') ?></b></td>
                        <td colspan="3" style="background:#eee;"></td>
                        <td style="text-align:center;"><b><?= number_format($audit_total_profile_qty_30,0,',','.') ?></b></td>
                    </tr>
                </tbody>
            </table>

            <div style="margin-top: 10px; font-size: 11px; display: flex; gap: 15px;">
                <div style="display:flex; align-items:center;">
                    <span style="display:inline-block; width:15px; height:15px; background:#fef3c7; border:1px solid #ccc; margin-right:5px;"></span> User yang Tidak Dilaporkan
                </div>
                <div style="display:flex; align-items:center;">
                    <span style="display:inline-block; width:15px; height:15px; background:#fecaca; border:1px solid #ccc; margin-right:5px;"></span> Voucer Rusak
                </div>
                <div style="display:flex; align-items:center;">
                    <span style="display:inline-block; width:15px; height:15px; background:#dcfce7; border:1px solid #ccc; margin-right:5px;"></span> Voucer Retur
                </div>
            </div>

            <div class="audit-summary-box">
                <div class="audit-summary-header">Kesimpulan Audit Harian</div>
                <div style="font-size:11px; color:#444; margin-bottom:6px; display:flex; flex-wrap:wrap; gap:12px; align-items:center;">
                    <span><strong>Status Voucher Global:</strong></span>
                    <span>Voucher Rusak: <?= number_format((int)$total_qty_rusak,0,',','.') ?></span>
                    <span>Retur: <?= number_format((int)$total_qty_retur,0,',','.') ?></span>
                    <span>Invalid: <?= number_format((int)$total_qty_invalid,0,',','.') ?></span>
                </div>
                
                <hr style="border:0; border-top:1px dashed #ccc; margin:8px 0;">

                <?php if (!empty($audit_summary_report)): ?>
                    <?php foreach ($audit_summary_report as $idx => $rep): ?>
                        <div class="audit-item">
                            <strong><?= ($idx + 1) ?>. Blok <?= htmlspecialchars($rep['blok']) ?></strong>
                            <?php 
                                if ($rep['selisih_setoran'] < 0) {
                                    echo "- <span style='color:red;'>Kurang Setor Rp " . number_format(abs($rep['selisih_setoran']), 0, ',', '.') . "</span>";
                                } elseif ($rep['selisih_setoran'] > 0) {
                                    echo "- <span style='color:green;'>Lebih Setor Rp " . number_format($rep['selisih_setoran'], 0, ',', '.') . "</span>";
                                } else {
                                    echo "- <span style='color:blue;'>Setoran Sesuai</span>";
                                }
                            ?>
                            <ul class="audit-details-list">
                                <?php if ($rep['p10_qty'] > 0): ?>
                                    <li>Profile 10 Menit: <?= $rep['p10_qty'] ?> Voucher / Rp <?= $rep['p10_sum'] !== null ? number_format($rep['p10_sum'], 0, ',', '.') : '-' ?></li>
                                <?php endif; ?>
                                
                                <?php if ($rep['p30_qty'] > 0): ?>
                                    <li>Profile 30 Menit: <?= $rep['p30_qty'] ?> Voucher / Rp <?= $rep['p30_sum'] !== null ? number_format($rep['p30_sum'], 0, ',', '.') : '-' ?></li>
                                <?php endif; ?>

                                <?php if ($rep['rusak_10'] > 0 || $rep['rusak_30'] > 0): ?>
                                    <li>
                                        <span style="color:red; font-weight:bold;">Voucher Rusak:</span>
                                        <?php 
                                            $rusak_parts = [];
                                            if($rep['rusak_10'] > 0) $rusak_parts[] = number_format((int)$rep['rusak_10'],0,',','.') . ' User (10 Menit)';
                                            if($rep['rusak_30'] > 0) $rusak_parts[] = number_format((int)$rep['rusak_30'],0,',','.') . ' User (30 Menit)';
                                            echo implode(' | ', $rusak_parts);
                                        ?>
                                    </li>
                                <?php endif; ?>

                                <?php if (!empty($rep['retur_10']) || !empty($rep['retur_30'])): ?>
                                    <li>
                                        <span style="color:green; font-weight:bold;">Voucher Retur:</span>
                                        <?php 
                                            $retur_parts = [];
                                            if(!empty($rep['retur_10'])) $retur_parts[] = number_format((int)$rep['retur_10'],0,',','.') . ' User (10 Menit)';
                                            if(!empty($rep['retur_30'])) $retur_parts[] = number_format((int)$rep['retur_30'],0,',','.') . ' User (30 Menit)';
                                            echo implode(' | ', $retur_parts);
                                        ?>
                                    </li>
                                <?php endif; ?>

                                <?php if (!empty($rep['unreported_total'])): ?>
                                    <li>
                                        <span style="color:#b45309; font-weight:bold;">User Tidak Dilaporkan:</span>
                                        <?php 
                                            $unrep_parts = [];
                                            if (!empty($rep['unreported_10'])) $unrep_parts[] = number_format((int)$rep['unreported_10'],0,',','.') . ' User (10 Menit)';
                                            if (!empty($rep['unreported_30'])) $unrep_parts[] = number_format((int)$rep['unreported_30'],0,',','.') . ' User (30 Menit)';
                                            echo implode(' | ', $unrep_parts);
                                        ?>
                                    </li>
                                <?php endif; ?>

                                <?php if ($rep['unknown_missing'] > 0): ?>
                                    <li>
                                        <span style="color:#b45309; font-weight:bold;">Voucer tidak dilaporkan:</span>
                                        <?= number_format($rep['unknown_missing'], 0, ',', '.') ?> Unit
                                    </li>
                                <?php endif; ?>

                                <?php if ($rep['p10_qty'] == 0 && $rep['p30_qty'] == 0): ?>
                                    <li>Tidak ada data detail profile audit.</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="audit-item">Tidak ada data audit yang perlu dilaporkan.</div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
    <?php endif; ?>

<script>
function shareReport(){
    if (navigator.share) {
        navigator.share({
            title: 'Rekap Laporan Penjualan',
            url: window.location.href
        });
    } else {
        window.prompt('Salin link laporan:', window.location.href);
    }
}

function setUniquePrintTitle(){
    var now = new Date();
    var pad = function(n){ return String(n).padStart(2, '0'); };
    var ts = now.getFullYear() + pad(now.getMonth()+1) + pad(now.getDate()) + '-' + pad(now.getHours()) + pad(now.getMinutes()) + pad(now.getSeconds());
    document.title = 'laporan-harian-' + ts;
}

window.addEventListener('beforeprint', setUniquePrintTitle);

</script>
</body>
</html>