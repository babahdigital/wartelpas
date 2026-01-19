<?php
/*
 * LAPORAN PENJUALAN (WARTELPAS)
 * Aturan: RUSAK = pendapatan berkurang, RETUR = pendapatan tetap.
 */
session_start();
error_reporting(0);

$dbFile = dirname(__DIR__) . '/db_data/mikhmon_stats.db';
$rows = [];
$cur = isset($currency) ? $currency : 'Rp';

// Filter periode
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

if (file_exists($dbFile)) {
        try {
                $db = new PDO('sqlite:' . $dbFile);
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $res = $db->query("SELECT sh.*, lh.last_status FROM sales_history sh LEFT JOIN login_history lh ON lh.username = sh.username ORDER BY sh.id DESC");
                if ($res) $rows = $res->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
                $rows = [];
        }
}

// Olah data
$list = [];
$total_gross = 0;
$total_rusak = 0;
$total_invalid = 0;
$total_net = 0;
$total_qty = 0;
$total_qty_retur = 0;
$total_qty_rusak = 0;
$total_qty_invalid = 0;

$by_block = [];
$by_profile = [];

foreach ($rows as $r) {
        $sale_date = $r['sale_date'] ?: norm_date_from_raw_report($r['raw_date'] ?? '');
        $sale_time = $r['sale_time'] ?? ($r['raw_time'] ?? '');
        $sale_dt = $sale_date && $sale_time ? ($sale_date . ' ' . $sale_time) : ($sale_date ?: ($r['raw_date'] ?? '-'));

        $match = false;
        if ($req_show === 'harian') $match = ($sale_date === $filter_date);
        elseif ($req_show === 'bulanan') $match = (strpos((string)$sale_date, $filter_date) === 0);
        else $match = (strpos((string)$sale_date, $filter_date) === 0);
        if (!$match) continue;

        $price = (int)($r['price_snapshot'] ?? $r['price'] ?? 0);
        $comment = (string)($r['comment'] ?? '');
        $status = strtolower((string)($r['status'] ?? ''));
        $lh_status = strtolower((string)($r['last_status'] ?? ''));
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

        $total_qty++;
        if ($status === 'retur') $total_qty_retur++;
        if ($status === 'rusak') $total_qty_rusak++;
        if ($status === 'invalid') $total_qty_invalid++;

        $total_gross += $gross_add;
        $total_rusak += $loss_rusak;
        $total_invalid += $loss_invalid;
        $total_net += $net_add;

        $blok = $r['blok_name'] ?? '-';
        $profile = $r['profile_snapshot'] ?? ($r['profile'] ?? '-');

        if (!isset($by_block[$blok])) {
                $by_block[$blok] = ['qty'=>0,'gross'=>0,'rusak'=>0,'invalid'=>0,'net'=>0,'retur'=>0];
        }
        if (!isset($by_profile[$profile])) {
                $by_profile[$profile] = ['qty'=>0,'gross'=>0,'rusak'=>0,'invalid'=>0,'net'=>0,'retur'=>0];
        }

        $by_block[$blok]['qty'] += 1;
        $by_block[$blok]['gross'] += $gross_add;
        $by_block[$blok]['rusak'] += $loss_rusak;
        $by_block[$blok]['invalid'] += $loss_invalid;
        $by_block[$blok]['net'] += $net_add;
        if ($status === 'retur') $by_block[$blok]['retur'] += 1;

        $by_profile[$profile]['qty'] += 1;
        $by_profile[$profile]['gross'] += $gross_add;
        $by_profile[$profile]['rusak'] += $loss_rusak;
        $by_profile[$profile]['invalid'] += $loss_invalid;
        $by_profile[$profile]['net'] += $net_add;
        if ($status === 'retur') $by_profile[$profile]['retur'] += 1;

        $list[] = [
                'dt' => $sale_dt,
                'user' => $r['username'] ?? '-',
                'profile' => $profile,
                'blok' => $blok,
                'status' => strtoupper($status),
                'price' => $price,
                'net' => $net_add,
                'comment' => $comment
        ];
}

ksort($by_block, SORT_NATURAL | SORT_FLAG_CASE);
ksort($by_profile, SORT_NATURAL | SORT_FLAG_CASE);
?>

<style>
    :root { --dark-bg: #1e2226; --dark-card: #2a3036; --border-col: #495057; --txt-main: #ecf0f1; --txt-muted: #adb5bd; --c-blue: #3498db; --c-green: #2ecc71; --c-orange: #f39c12; --c-red: #e74c3c; }
    .card-solid { background: var(--dark-card); color: var(--txt-main); border: none; box-shadow: 0 4px 10px rgba(0,0,0,0.3); border-radius: 8px; }
    .card-header-solid { background: #23272b; padding: 12px 20px; border-bottom: 2px solid var(--border-col); display: flex; justify-content: space-between; align-items: center; border-radius: 8px 8px 0 0; }
    .table-dark-solid { width: 100%; border-collapse: separate; border-spacing: 0; }
    .table-dark-solid th { background: #1b1e21; padding: 12px; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; color: var(--txt-muted); border-bottom: 2px solid var(--border-col); }
    .table-dark-solid td { padding: 12px; border-bottom: 1px solid #3a4046; vertical-align: middle; font-size: 0.9rem; }
    .table-dark-solid tr:hover td { background: #32383e; }
    .summary-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
    .summary-card { background: #23272b; border: 1px solid var(--border-col); border-radius: 8px; padding: 14px; }
    .summary-title { font-size: 0.8rem; color: var(--txt-muted); text-transform: uppercase; letter-spacing: 1px; }
    .summary-value { font-size: 1.4rem; font-weight: 700; margin-top: 6px; }
    .status-badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
    .st-normal { background: #4b545c; color: #ccc; border: 1px solid #6c757d; }
    .st-retur { background: #8e44ad; color: #fff; }
    .st-rusak { background: var(--c-orange); color: #fff; }
    .st-invalid { background: var(--c-red); color: #fff; }
    .filter-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .filter-bar select, .filter-bar input { background: #343a40; border: 1px solid var(--border-col); color: #fff; padding: 6px 10px; border-radius: 6px; }
    .btn-print { background: var(--c-blue); color: #fff; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; }
</style>

<div class="card-solid mb-3">
    <div class="card-header-solid">
        <h3 class="m-0"><i class="fa fa-line-chart mr-2"></i> Laporan Penjualan</h3>
        <div class="filter-bar">
            <form method="get" action="" class="filter-bar">
                <input type="hidden" name="report" value="selling">
                <select name="show" onchange="this.form.submit()">
                    <option value="harian" <?= $req_show==='harian'?'selected':''; ?>>Harian</option>
                    <option value="bulanan" <?= $req_show==='bulanan'?'selected':''; ?>>Bulanan</option>
                    <option value="tahunan" <?= $req_show==='tahunan'?'selected':''; ?>>Tahunan</option>
                </select>
                <?php if ($req_show === 'harian'): ?>
                    <input type="date" name="date" value="<?= htmlspecialchars($filter_date); ?>" onchange="this.form.submit()">
                <?php elseif ($req_show === 'bulanan'): ?>
                    <input type="month" name="date" value="<?= htmlspecialchars($filter_date); ?>" onchange="this.form.submit()">
                <?php else: ?>
                    <input type="number" name="date" min="2000" max="2100" value="<?= htmlspecialchars($filter_date); ?>" onchange="this.form.submit()" style="width:100px;">
                <?php endif; ?>
            </form>
            <button class="btn-print" onclick="window.print()"><i class="fa fa-print"></i></button>
        </div>
    </div>
    <div class="card-body" style="padding:16px;">
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-title">Pendapatan Kotor</div>
                <div class="summary-value"><?= $cur ?> <?= number_format($total_gross,0,',','.') ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-title">Koreksi Rusak</div>
                <div class="summary-value" style="color:#f39c12;">-<?= $cur ?> <?= number_format($total_rusak,0,',','.') ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-title">Koreksi Invalid</div>
                <div class="summary-value" style="color:#e74c3c;">-<?= $cur ?> <?= number_format($total_invalid,0,',','.') ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-title">Pendapatan Bersih</div>
                <div class="summary-value" style="color:#2ecc71;"><?= $cur ?> <?= number_format($total_net,0,',','.') ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-title">Total Quota (Qty)</div>
                <div class="summary-value"><?= number_format($total_qty,0,',','.') ?></div>
                <div style="font-size:12px;color:var(--txt-muted)">Retur: <?= number_format($total_qty_retur,0,',','.') ?> | Rusak: <?= number_format($total_qty_rusak,0,',','.') ?> | Invalid: <?= number_format($total_qty_invalid,0,',','.') ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card-solid mb-3">
    <div class="card-header-solid">
        <h3 class="m-0"><i class="fa fa-list-alt mr-2"></i> Rincian Transaksi</h3>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height: 420px;">
            <table class="table-dark-solid text-nowrap">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>User</th>
                        <th>Profile</th>
                        <th>Blok</th>
                        <th>Status</th>
                        <th class="text-right">Harga</th>
                        <th class="text-right">Efektif</th>
                        <th>Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($list)): ?>
                        <tr><td colspan="8" style="text-align:center;color:var(--txt-muted);padding:30px;">Tidak ada data pada periode ini.</td></tr>
                    <?php else: foreach ($list as $it): ?>
                        <tr>
                            <td><?= htmlspecialchars($it['dt']) ?></td>
                            <td><?= htmlspecialchars($it['user']) ?></td>
                            <td><?= htmlspecialchars($it['profile']) ?></td>
                            <td><?= htmlspecialchars($it['blok']) ?></td>
                            <td>
                                <?php
                                    $st = strtolower($it['status']);
                                    $cls = $st === 'rusak' ? 'st-rusak' : ($st === 'retur' ? 'st-retur' : ($st === 'invalid' ? 'st-invalid' : 'st-normal'));
                                ?>
                                <span class="status-badge <?= $cls; ?>"><?= htmlspecialchars($it['status']) ?></span>
                            </td>
                            <td class="text-right"><?= number_format($it['price'],0,',','.') ?></td>
                            <td class="text-right"><?= number_format($it['net'],0,',','.') ?></td>
                            <td><small><?= htmlspecialchars($it['comment']) ?></small></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card-solid mb-3">
            <div class="card-header-solid">
                <h3 class="m-0"><i class="fa fa-th-large mr-2"></i> Pendapatan per Blok</h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 360px;">
                    <table class="table-dark-solid text-nowrap">
                        <thead>
                            <tr><th>Blok</th><th class="text-right">Qty</th><th class="text-right">Kotor</th><th class="text-right">Rusak</th><th class="text-right">Invalid</th><th class="text-right">Net</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($by_block)): ?>
                                <tr><td colspan="6" style="text-align:center;color:var(--txt-muted);padding:30px;">Tidak ada data.</td></tr>
                            <?php else: foreach ($by_block as $b => $v): ?>
                                <tr>
                                    <td><?= htmlspecialchars($b) ?></td>
                                    <td class="text-right"><?= number_format($v['qty'],0,',','.') ?></td>
                                    <td class="text-right"><?= number_format($v['gross'],0,',','.') ?></td>
                                    <td class="text-right"><?= number_format($v['rusak'],0,',','.') ?></td>
                                    <td class="text-right"><?= number_format($v['invalid'],0,',','.') ?></td>
                                    <td class="text-right"><?= number_format($v['net'],0,',','.') ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card-solid mb-3">
            <div class="card-header-solid">
                <h3 class="m-0"><i class="fa fa-tags mr-2"></i> Pendapatan per Profile</h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 360px;">
                    <table class="table-dark-solid text-nowrap">
                        <thead>
                            <tr><th>Profile</th><th class="text-right">Qty</th><th class="text-right">Kotor</th><th class="text-right">Rusak</th><th class="text-right">Invalid</th><th class="text-right">Net</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($by_profile)): ?>
                                <tr><td colspan="6" style="text-align:center;color:var(--txt-muted);padding:30px;">Tidak ada data.</td></tr>
                            <?php else: foreach ($by_profile as $p => $v): ?>
                                <tr>
                                    <td><?= htmlspecialchars($p) ?></td>
                                    <td class="text-right"><?= number_format($v['qty'],0,',','.') ?></td>
                                    <td class="text-right"><?= number_format($v['gross'],0,',','.') ?></td>
                                    <td class="text-right"><?= number_format($v['rusak'],0,',','.') ?></td>
                                    <td class="text-right"><?= number_format($v['invalid'],0,',','.') ?></td>
                                    <td class="text-right"><?= number_format($v['net'],0,',','.') ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>