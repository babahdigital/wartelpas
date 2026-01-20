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

$filter_date = $_GET['date'] ?? date('Y-m-d');

function norm_date_from_raw_report($raw_date) {
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

$rows = [];
$list = [];

try {
    if (file_exists($dbFile)) {
        $db = new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $res = $db->query("SELECT 
                sh.raw_date, sh.raw_time, sh.sale_date, sh.sale_time, sh.sale_datetime,
                sh.username, sh.profile, sh.profile_snapshot,
                sh.price, sh.price_snapshot, sh.sprice_snapshot, sh.validity,
                sh.comment, sh.blok_name, sh.status, sh.is_rusak, sh.is_retur, sh.is_invalid, sh.qty,
                sh.full_raw_data, lh.last_status
            FROM sales_history sh
            LEFT JOIN login_history lh ON lh.username = sh.username
            UNION ALL
            SELECT 
                ls.raw_date, ls.raw_time, ls.sale_date, ls.sale_time, ls.sale_datetime,
                ls.username, ls.profile, ls.profile_snapshot,
                ls.price, ls.price_snapshot, ls.sprice_snapshot, ls.validity,
                ls.comment, ls.blok_name, ls.status, ls.is_rusak, ls.is_retur, ls.is_invalid, ls.qty,
                ls.full_raw_data, lh2.last_status
            FROM live_sales ls
            LEFT JOIN login_history lh2 ON lh2.username = ls.username
            WHERE ls.sync_status = 'pending'
            ORDER BY sale_datetime DESC, raw_date DESC");
        if ($res) $rows = $res->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $rows = [];
}

foreach ($rows as $r) {
    $sale_date = $r['sale_date'] ?: norm_date_from_raw_report($r['raw_date'] ?? '');
    if ($sale_date !== $filter_date) continue;

    $price = (int)($r['price_snapshot'] ?? $r['price'] ?? 0);
    $comment = (string)($r['comment'] ?? '');
    $status = strtolower((string)($r['status'] ?? ''));
    $lh_status = strtolower((string)($r['last_status'] ?? ''));
    $profile = $r['profile_snapshot'] ?? ($r['profile'] ?? '-');
    $cmt_low = strtolower($comment);

    if ($status === '' || $status === 'normal') {
        if (strpos($cmt_low, 'invalid') !== false) $status = 'invalid';
        elseif (strpos($cmt_low, 'rusak') !== false || $lh_status === 'rusak') $status = 'rusak';
        elseif (strpos($cmt_low, 'retur') !== false || $lh_status === 'retur') $status = 'retur';
        else $status = 'normal';
    }

    $gross_add = ($status === 'retur' || $status === 'invalid') ? 0 : $price;
    $loss_rusak = ($status === 'rusak') ? $price : 0;
    $loss_invalid = ($status === 'invalid') ? $price : 0;
    $net_add = $gross_add - $loss_rusak - $loss_invalid;

    $list[] = [
        'time' => $r['sale_time'] ?: ($r['raw_time'] ?? ''),
        'username' => $r['username'] ?? '-',
        'profile' => $profile,
        'comment' => $comment,
        'status' => $status,
        'price' => $price,
        'gross' => $gross_add,
        'net' => $net_add
    ];
}

function esc($s){ return htmlspecialchars((string)$s); }
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Print Rincian Harian</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; color:#111; margin:20px; }
        h2 { margin:0 0 6px 0; }
        .meta { font-size:12px; color:#555; margin-bottom:12px; }
        .toolbar { margin-bottom:14px; display:flex; gap:8px; flex-wrap:wrap; }
        .btn { padding:6px 10px; border:1px solid #999; background:#f2f2f2; cursor:pointer; border-radius:4px; font-size:12px; }
        table { width:100%; border-collapse:collapse; font-size:12px; }
        th, td { border:1px solid #ddd; padding:6px; text-align:left; vertical-align:top; }
        th { background:#f5f5f5; }
        .status-normal { color:#0a7f2e; font-weight:700; }
        .status-rusak { color:#d35400; font-weight:700; }
        .status-retur { color:#7f8c8d; font-weight:700; }
        .status-invalid { color:#c0392b; font-weight:700; }
        @media print { .toolbar { display:none; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <button class="btn" onclick="window.print()">Print / Download PDF</button>
        <button class="btn" onclick="shareReport()">Share</button>
    </div>

    <h2>Rincian Transaksi Harian</h2>
    <div class="meta">Tanggal: <?= esc($filter_date) ?></div>

    <table>
        <thead>
            <tr>
                <th>Jam</th>
                <th>Username</th>
                <th>Profile</th>
                <th>Catatan</th>
                <th>Status</th>
                <th>Harga</th>
                <th>Bruto</th>
                <th>Netto</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($list)): ?>
                <tr><td colspan="8" style="text-align:center;">Tidak ada data</td></tr>
            <?php else: ?>
                <?php foreach ($list as $it): ?>
                <tr>
                    <td><?= esc($it['time']) ?></td>
                    <td><?= esc($it['username']) ?></td>
                    <td><?= esc($it['profile']) ?></td>
                    <td><?= esc($it['comment']) ?></td>
                    <td class="status-<?= esc($it['status']) ?>"><?= strtoupper(esc($it['status'])) ?></td>
                    <td><?= $cur ?> <?= number_format((int)$it['price'],0,',','.') ?></td>
                    <td><?= $cur ?> <?= number_format((int)$it['gross'],0,',','.') ?></td>
                    <td><?= $cur ?> <?= number_format((int)$it['net'],0,',','.') ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

<script>
function shareReport(){
    if (navigator.share) {
        navigator.share({
            title: 'Rincian Transaksi Harian',
            url: window.location.href
        });
    } else {
        window.prompt('Salin link laporan:', window.location.href);
    }
}
</script>
</body>
</html>
