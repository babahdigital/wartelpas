<?php
session_start();
error_reporting(0);

$dbFile = dirname(__DIR__) . '/db_data/mikhmon_stats.db';
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

function table_exists($db, $name) {
    try {
        $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = :n LIMIT 1");
        $stmt->execute([':n' => $name]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function format_bytes_short($bytes) {
    $b = (float)$bytes;
    if ($b <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < count($units) - 1) {
        $b /= 1024;
        $i++;
    }
    $dec = $i >= 2 ? 2 : 0;
    return number_format($b, $dec, ',', '.') . ' ' . $units[$i];
}

$db = null;
$sales_summary = [
    'total' => 0,
    'gross' => 0,
    'rusak' => 0,
    'retur' => 0,
    'invalid' => 0,
    'net' => 0,
    'pending' => 0,
];
$dup_raw = [];
$dup_user_date = [];
$relogin_rows = [];
$bandwidth_rows = [];
$security_logs = [];
$sales_status_rows = [];

if (file_exists($dbFile)) {
    try {
        $db = new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $dateFilter = '';
        $dateParam = [];
        if ($req_show === 'harian') {
            $dateFilter = 'sale_date = :d';
            $dateParam[':d'] = $filter_date;
        } elseif ($req_show === 'bulanan') {
            $dateFilter = 'sale_date LIKE :d';
            $dateParam[':d'] = $filter_date . '%';
        } else {
            $dateFilter = 'sale_date LIKE :d';
            $dateParam[':d'] = $filter_date . '%';
        }

        if (table_exists($db, 'sales_history')) {
            $statusSql = "SELECT
                CASE
                    WHEN COALESCE(is_invalid,0)=1 THEN 'invalid'
                    WHEN COALESCE(is_retur,0)=1 THEN 'retur'
                    WHEN COALESCE(is_rusak,0)=1 THEN 'rusak'
                    WHEN lower(COALESCE(status,'')) IN ('invalid','retur','rusak') THEN lower(status)
                    ELSE 'normal'
                END AS st,
                COUNT(1) AS cnt,
                SUM(COALESCE(price_snapshot, price, 0) * COALESCE(qty,1)) AS total
                FROM sales_history
                WHERE $dateFilter
                GROUP BY st";
            $stmt = $db->prepare($statusSql);
            foreach ($dateParam as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
            $sales_status_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($sales_status_rows as $r) {
                $st = $r['st'] ?? 'normal';
                $cnt = (int)($r['cnt'] ?? 0);
                $sum = (int)($r['total'] ?? 0);
                if ($st === 'rusak') {
                    $sales_summary['rusak'] += $sum;
                } elseif ($st === 'invalid') {
                    $sales_summary['invalid'] += $sum;
                } elseif ($st === 'retur') {
                    $sales_summary['retur'] += $sum;
                } else {
                    $sales_summary['gross'] += $sum;
                }
                $sales_summary['total'] += $cnt;
            }
            $sales_summary['net'] = $sales_summary['gross'] - $sales_summary['rusak'] - $sales_summary['invalid'];

            $dupRawSql = "SELECT full_raw_data, sale_date, username, COUNT(*) AS cnt
                FROM sales_history
                WHERE full_raw_data IS NOT NULL AND full_raw_data != '' AND $dateFilter
                GROUP BY full_raw_data
                HAVING cnt > 1
                ORDER BY cnt DESC, sale_date DESC
                LIMIT 200";
            $stmt = $db->prepare($dupRawSql);
            foreach ($dateParam as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
            $dup_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $dupUserDateSql = "SELECT username, sale_date, COUNT(*) AS cnt
                FROM sales_history
                WHERE $dateFilter
                GROUP BY username, sale_date
                HAVING cnt > 1
                ORDER BY cnt DESC, sale_date DESC
                LIMIT 200";
            $stmt = $db->prepare($dupUserDateSql);
            foreach ($dateParam as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
            $dup_user_date = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (table_exists($db, 'live_sales')) {
            $pendingSql = "SELECT COUNT(*) FROM live_sales WHERE sync_status='pending'";
            $sales_summary['pending'] = (int)($db->query($pendingSql)->fetchColumn() ?: 0);
        }

        if (table_exists($db, 'login_events')) {
            $reloginSql = "SELECT username, date_key, COUNT(*) AS cnt
                FROM login_events
                WHERE date_key LIKE :d
                GROUP BY username, date_key
                HAVING cnt > 1
                ORDER BY cnt DESC, date_key DESC
                LIMIT 200";
            $dateKey = $req_show === 'harian' ? $filter_date : $filter_date . '%';
            $stmt = $db->prepare($reloginSql);
            $stmt->bindValue(':d', $dateKey);
            $stmt->execute();
            $relogin_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (table_exists($db, 'login_history')) {
            $bwSql = "SELECT username, last_bytes, last_uptime, last_status, last_login_real
                FROM login_history
                WHERE last_bytes IS NOT NULL
                ORDER BY last_bytes DESC
                LIMIT 50";
            $stmt = $db->prepare($bwSql);
            $stmt->execute();
            $bandwidth_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (table_exists($db, 'security_log')) {
            $secSql = "SELECT log_date, username, mac_address, ip_address, reason, comment
                FROM security_log
                ORDER BY log_date DESC
                LIMIT 200";
            $stmt = $db->query($secSql);
            $security_logs = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        }
    } catch (Exception $e) {
        $db = null;
    }
}
?>

<style>
    :root { --dark-bg: #1e2226; --dark-card: #2a3036; --border-col: #495057; --txt-main: #ecf0f1; --txt-muted: #adb5bd; --c-blue: #3498db; --c-green: #2ecc71; --c-orange: #f39c12; --c-red: #e74c3c; }
    .card-solid { background: var(--dark-card); color: var(--txt-main); border: none; box-shadow: 0 4px 10px rgba(0,0,0,0.3); border-radius: 8px; }
    .card-header-solid { background: #23272b; padding: 12px 20px; border-bottom: 2px solid var(--border-col); display: flex; justify-content: space-between; align-items: center; border-radius: 8px 8px 0 0; }
    .card-body { padding: 16px; }
    .table-dark-solid { width: 100%; border-collapse: separate; border-spacing: 0; }
    .table-dark-solid th { background: #1b1e21; padding: 10px; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--txt-muted); border-bottom: 2px solid var(--border-col); }
    .table-dark-solid td { padding: 10px; border-bottom: 1px solid #3a4046; vertical-align: middle; font-size: 0.9rem; }
    .summary-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(200px,1fr)); gap: 10px; margin-bottom: 14px; }
    .summary-card { background:#22272b;border:1px solid #3a4046;border-radius:6px;padding:12px; }
    .summary-title { font-size: 11px; color: var(--txt-muted); text-transform: uppercase; letter-spacing: .5px; }
    .summary-value { font-size: 18px; font-weight: 700; margin-top: 4px; }
    .pill { display:inline-block;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:700; }
    .pill-ok { background:#1f7a3f;color:#fff; }
    .pill-warn { background:#f39c12;color:#fff; }
    .pill-bad { background:#c0392b;color:#fff; }
    .section-title { font-weight:700; margin:14px 0 8px; font-size:14px; }
    .toolbar { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .toolbar select, .toolbar input { background:#343a40; color:#fff; border:1px solid var(--border-col); height:36px; padding:0 10px; border-radius:4px; }
    .btn-solid { background:#2d8cff;color:#fff;border:none;padding:6px 10px;border-radius:4px; cursor:pointer; }
    .muted { color: var(--txt-muted); }
</style>

<div class="card card-solid">
    <div class="card-header-solid">
        <h3 class="card-title m-0"><i class="fa fa-shield"></i> Audit Penjualan & Voucher</h3>
        <button class="btn-solid" onclick="window.print()"><i class="fa fa-print"></i> Print</button>
    </div>
    <div class="card-body">
        <form method="GET" class="toolbar" action="?">
            <input type="hidden" name="report" value="audit">
            <input type="hidden" name="session" value="<?= htmlspecialchars($session_id) ?>">
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
                <input type="number" name="date" min="2000" max="2100" value="<?= htmlspecialchars($filter_date); ?>" onchange="this.form.submit()" style="width:120px;">
            <?php endif; ?>
            <span class="muted">Filter: <?= htmlspecialchars($filter_date) ?></span>
        </form>

        <div class="summary-grid">
            <div class="summary-card"><div class="summary-title">Total Transaksi</div><div class="summary-value"><?= number_format($sales_summary['total'],0,',','.') ?></div></div>
            <div class="summary-card"><div class="summary-title">Pendapatan Kotor</div><div class="summary-value">Rp <?= number_format($sales_summary['gross'],0,',','.') ?></div></div>
            <div class="summary-card"><div class="summary-title">Potongan Rusak</div><div class="summary-value">Rp <?= number_format($sales_summary['rusak'],0,',','.') ?></div></div>
            <div class="summary-card"><div class="summary-title">Potongan Invalid</div><div class="summary-value">Rp <?= number_format($sales_summary['invalid'],0,',','.') ?></div></div>
            <div class="summary-card"><div class="summary-title">Pendapatan Bersih</div><div class="summary-value">Rp <?= number_format($sales_summary['net'],0,',','.') ?></div></div>
            <div class="summary-card"><div class="summary-title">Pending Live Sales</div><div class="summary-value"><?= number_format($sales_summary['pending'],0,',','.') ?></div></div>
        </div>

        <div class="section-title">Distribusi Status Penjualan</div>
        <table class="table-dark-solid">
            <thead><tr><th>Status</th><th>Jumlah</th><th>Total</th></tr></thead>
            <tbody>
                <?php if (empty($sales_status_rows)): ?>
                    <tr><td colspan="3" class="text-center muted">Tidak ada data</td></tr>
                <?php else: ?>
                    <?php foreach ($sales_status_rows as $r): ?>
                        <tr>
                            <td><?= strtoupper(htmlspecialchars($r['st'] ?? 'normal')) ?></td>
                            <td><?= number_format((int)($r['cnt'] ?? 0),0,',','.') ?></td>
                            <td>Rp <?= number_format((int)($r['total'] ?? 0),0,',','.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="section-title">Duplikasi Berdasar full_raw_data</div>
        <table class="table-dark-solid">
            <thead><tr><th>Sale Date</th><th>Username</th><th>Count</th><th>Raw</th></tr></thead>
            <tbody>
                <?php if (empty($dup_raw)): ?>
                    <tr><td colspan="4" class="text-center muted">Tidak ada duplikasi</td></tr>
                <?php else: ?>
                    <?php foreach ($dup_raw as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['sale_date'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($r['username'] ?? '-') ?></td>
                            <td><span class="pill pill-warn"><?= (int)($r['cnt'] ?? 0) ?></span></td>
                            <td style="max-width:360px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($r['full_raw_data'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="section-title">Duplikasi Berdasar Username + Tanggal</div>
        <table class="table-dark-solid">
            <thead><tr><th>Sale Date</th><th>Username</th><th>Count</th></tr></thead>
            <tbody>
                <?php if (empty($dup_user_date)): ?>
                    <tr><td colspan="3" class="text-center muted">Tidak ada duplikasi</td></tr>
                <?php else: ?>
                    <?php foreach ($dup_user_date as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['sale_date'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($r['username'] ?? '-') ?></td>
                            <td><span class="pill pill-warn"><?= (int)($r['cnt'] ?? 0) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="section-title">Relogin (login_events)</div>
        <table class="table-dark-solid">
            <thead><tr><th>Tanggal</th><th>Username</th><th>Jumlah Relogin</th></tr></thead>
            <tbody>
                <?php if (empty($relogin_rows)): ?>
                    <tr><td colspan="3" class="text-center muted">Tidak ada relogin</td></tr>
                <?php else: ?>
                    <?php foreach ($relogin_rows as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['date_key'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($r['username'] ?? '-') ?></td>
                            <td><span class="pill pill-ok"><?= (int)($r['cnt'] ?? 0) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="section-title">Top Bandwidth (login_history)</div>
        <table class="table-dark-solid">
            <thead><tr><th>Username</th><th>Last Bytes</th><th>Uptime</th><th>Status</th><th>Last Login</th></tr></thead>
            <tbody>
                <?php if (empty($bandwidth_rows)): ?>
                    <tr><td colspan="5" class="text-center muted">Tidak ada data</td></tr>
                <?php else: ?>
                    <?php foreach ($bandwidth_rows as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['username'] ?? '-') ?></td>
                            <td><?= htmlspecialchars(format_bytes_short($r['last_bytes'] ?? 0)) ?></td>
                            <td><?= htmlspecialchars($r['last_uptime'] ?? '-') ?></td>
                            <td><?= strtoupper(htmlspecialchars($r['last_status'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars($r['last_login_real'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="section-title">Security Log (latest 200)</div>
        <table class="table-dark-solid">
            <thead><tr><th>Waktu</th><th>User</th><th>MAC / IP</th><th>Alasan</th><th>Catatan</th></tr></thead>
            <tbody>
                <?php if (empty($security_logs)): ?>
                    <tr><td colspan="5" class="text-center muted">Tidak ada data</td></tr>
                <?php else: ?>
                    <?php foreach ($security_logs as $l): ?>
                        <tr>
                            <td><?= htmlspecialchars($l['log_date'] ?? '-') ?></td>
                            <td><strong><?= htmlspecialchars($l['username'] ?? '-') ?></strong></td>
                            <td><?= htmlspecialchars($l['mac_address'] ?? '-') ?><br><small class="muted"><?= htmlspecialchars($l['ip_address'] ?? '-') ?></small></td>
                            <td><?= htmlspecialchars($l['reason'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($l['comment'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>