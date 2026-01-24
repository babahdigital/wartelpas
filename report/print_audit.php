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

function format_date_dmy($date) {
    if (!$date) return '';
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    if (preg_match('/^(\d{4})-(\d{2})$/', $date, $m)) {
        return $m[2] . '-' . $m[1];
    }
    return $date;
}

function format_blok_label($blok) {
    $blok = (string)$blok;
    if ($blok === '') return '';
    return preg_replace('/^BLOK-?/i', '', $blok);
}

function extract_profile_from_comment($comment) {
    $comment = (string)$comment;
    if (preg_match('/\bProfile\s*:\s*([^|]+)/i', $comment, $m)) {
        return trim($m[1]);
    }
    return '';
}

function infer_profile_from_blok($blok) {
    $blok = strtoupper((string)$blok);
    if (preg_match('/(10|30)\b/', $blok, $m)) {
        return $m[1] . ' Menit';
    }
    return '';
}

function calc_audit_adjusted_totals(array $ar) {
  $price10 = 5000;
  $price30 = 20000;
  $expected_qty = (int)($ar['expected_qty'] ?? 0);
  $expected_setoran = (int)($ar['expected_setoran'] ?? 0);
  $reported_qty = (int)($ar['reported_qty'] ?? 0);
  $actual_setoran = (int)($ar['actual_setoran'] ?? 0);

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
    $manual_net_qty_10 = max(0, $p10_qty - $cnt_rusak_10 - $cnt_invalid_10);
    $manual_net_qty_30 = max(0, $p30_qty - $cnt_rusak_30 - $cnt_invalid_30);
    $manual_display_qty = $manual_net_qty_10 + $manual_net_qty_30;
    $manual_display_setoran = ($manual_net_qty_10 * $price10) + ($manual_net_qty_30 * $price30);
    $expected_adj_qty = max(0, $expected_qty - $cnt_rusak_10 - $cnt_rusak_30 - $cnt_invalid_10 - $cnt_invalid_30);
    $expected_adj_setoran = max(0, $expected_setoran
      - (($cnt_rusak_10 + $cnt_invalid_10) * $price10)
      - (($cnt_rusak_30 + $cnt_invalid_30) * $price30));
  } else {
    $manual_display_qty = $reported_qty;
    $manual_display_setoran = $actual_setoran;
    $expected_adj_qty = $expected_qty;
    $expected_adj_setoran = $expected_setoran;
  }

  return [$manual_display_qty, $expected_adj_qty, $manual_display_setoran, $expected_adj_setoran];
}

$sales_summary = [
    'total' => 0,
    'gross' => 0,
    'rusak' => 0,
    'retur' => 0,
    'invalid' => 0,
    'net' => 0,
    'pending' => 0,
];
$pending_summary = [
    'total' => 0,
    'gross' => 0,
    'rusak' => 0,
    'retur' => 0,
    'invalid' => 0,
    'net' => 0,
];
$audit_manual_summary = [
  'rows' => 0,
  'manual_qty' => 0,
  'expected_qty' => 0,
  'manual_setoran' => 0,
  'expected_setoran' => 0,
  'selisih_qty' => 0,
  'selisih_setoran' => 0,
];
$relogin_limit = 25;
$bandwidth_limit = 25;
$dup_raw = [];
$dup_user_date = [];
$relogin_rows = [];
$bandwidth_rows = [];

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
            $sumSql = "SELECT
                SUM(CASE WHEN COALESCE(is_invalid,0)=1 THEN COALESCE(price_snapshot, price, 0) * COALESCE(qty,1) ELSE 0 END) AS invalid_sum,
                SUM(CASE WHEN COALESCE(is_rusak,0)=1 THEN COALESCE(price_snapshot, price, 0) * COALESCE(qty,1) ELSE 0 END) AS rusak_sum,
                SUM(CASE WHEN COALESCE(is_retur,0)=1 THEN COALESCE(price_snapshot, price, 0) * COALESCE(qty,1) ELSE 0 END) AS retur_sum,
                SUM(CASE WHEN COALESCE(is_invalid,0)=1 THEN 0 ELSE COALESCE(price_snapshot, price, 0) * COALESCE(qty,1) END) AS gross_sum,
                COUNT(1) AS total_cnt
                FROM sales_history
                WHERE $dateFilter";
            $stmt = $db->prepare($sumSql);
            foreach ($dateParam as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
            $sumRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $sales_summary['invalid'] = (int)($sumRow['invalid_sum'] ?? 0);
            $sales_summary['rusak'] = (int)($sumRow['rusak_sum'] ?? 0);
            $sales_summary['retur'] = (int)($sumRow['retur_sum'] ?? 0);
            $sales_summary['gross'] = (int)($sumRow['gross_sum'] ?? 0);
            $sales_summary['total'] = (int)($sumRow['total_cnt'] ?? 0);
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
            $pendingSql = "SELECT COUNT(*) FROM live_sales WHERE sync_status='pending' AND $dateFilter";
            $stmt = $db->prepare($pendingSql);
            foreach ($dateParam as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
            $sales_summary['pending'] = (int)($stmt->fetchColumn() ?: 0);

            $pendingSumSql = "SELECT
                SUM(CASE WHEN COALESCE(is_invalid,0)=1 THEN COALESCE(price_snapshot, price, 0) * COALESCE(qty,1) ELSE 0 END) AS invalid_sum,
                SUM(CASE WHEN COALESCE(is_rusak,0)=1 THEN COALESCE(price_snapshot, price, 0) * COALESCE(qty,1) ELSE 0 END) AS rusak_sum,
                SUM(CASE WHEN COALESCE(is_retur,0)=1 THEN COALESCE(price_snapshot, price, 0) * COALESCE(qty,1) ELSE 0 END) AS retur_sum,
                SUM(CASE WHEN COALESCE(is_invalid,0)=1 THEN 0 ELSE COALESCE(price_snapshot, price, 0) * COALESCE(qty,1) END) AS gross_sum,
                COUNT(1) AS total_cnt
                FROM live_sales
                WHERE sync_status='pending' AND $dateFilter";
            $stmt = $db->prepare($pendingSumSql);
            foreach ($dateParam as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
            $p = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $pending_summary['invalid'] = (int)($p['invalid_sum'] ?? 0);
            $pending_summary['rusak'] = (int)($p['rusak_sum'] ?? 0);
            $pending_summary['retur'] = (int)($p['retur_sum'] ?? 0);
            $pending_summary['gross'] = (int)($p['gross_sum'] ?? 0);
            $pending_summary['total'] = (int)($p['total_cnt'] ?? 0);
            $pending_summary['net'] = $pending_summary['gross'] - $pending_summary['rusak'] - $pending_summary['invalid'];
        }

        if (table_exists($db, 'login_events')) {
            $reloginSql = "SELECT le.username, le.date_key, COUNT(*) AS cnt, lh.blok_name, lh.raw_comment
                FROM login_events le
                LEFT JOIN login_history lh ON lh.username = le.username
                WHERE le.date_key LIKE :d
                GROUP BY le.username, le.date_key
                HAVING cnt > 1
                ORDER BY cnt DESC, le.date_key DESC
              LIMIT $relogin_limit";
            $dateKey = $req_show === 'harian' ? $filter_date : $filter_date . '%';
            $stmt = $db->prepare($reloginSql);
            $stmt->bindValue(':d', $dateKey);
            $stmt->execute();
            $relogin_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (table_exists($db, 'login_history')) {
            $bwSql = "SELECT username, last_bytes, last_uptime, last_status, last_login_real, blok_name, raw_comment
                FROM login_history
                WHERE last_bytes IS NOT NULL
                ORDER BY last_bytes DESC
              LIMIT $bandwidth_limit";
            $stmt = $db->prepare($bwSql);
            $stmt->execute();
            $bandwidth_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (table_exists($db, 'audit_rekap_manual')) {
          $auditSql = "SELECT expected_qty, expected_setoran, reported_qty, actual_setoran, user_evidence
            FROM audit_rekap_manual WHERE $dateFilter";
          $stmt = $db->prepare($auditSql);
          foreach ($dateParam as $k => $v) $stmt->bindValue($k, $v);
          $stmt->execute();
          $audit_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
          $audit_manual_summary['rows'] = count($audit_rows);
          foreach ($audit_rows as $ar) {
            [$manual_qty, $expected_qty, $manual_setoran, $expected_setoran] = calc_audit_adjusted_totals($ar);
            $audit_manual_summary['manual_qty'] += (int)$manual_qty;
            $audit_manual_summary['expected_qty'] += (int)$expected_qty;
            $audit_manual_summary['manual_setoran'] += (int)$manual_setoran;
            $audit_manual_summary['expected_setoran'] += (int)$expected_setoran;
          }
          $audit_manual_summary['selisih_qty'] = (int)$audit_manual_summary['manual_qty'] - (int)$audit_manual_summary['expected_qty'];
          $audit_manual_summary['selisih_setoran'] = (int)$audit_manual_summary['manual_setoran'] - (int)$audit_manual_summary['expected_setoran'];
        }
    } catch (Exception $e) {
        $db = null;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Audit Penjualan & Voucher</title>
<style>
    body { font-family: Arial, sans-serif; color: #111; margin: 0; padding: 12mm; }
    h1 { margin: 0 0 4mm 0; font-size: 16px; }
    .sub { font-size: 11px; color: #666; margin-bottom: 6mm; }
    .summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; margin-bottom: 8mm; }
    .summary-card { border: 1px solid #ddd; border-radius: 4px; padding: 6px; }
    .summary-title { font-size: 10px; color: #666; text-transform: uppercase; letter-spacing: .4px; }
    .summary-value { font-size: 13px; font-weight: bold; margin-top: 2px; }
    .section { margin-bottom: 6mm; }
    .section-title { font-weight: 700; font-size: 12px; margin: 4mm 0 2mm; }
    table { width: 100%; border-collapse: collapse; font-size: 11px; }
    th, td { border: 1px solid #ddd; padding: 5px 6px; text-align: left; }
    th { background: #f0f0f0; }
    .muted { color: #666; }
    @page { margin: 8mm; }
</style>
</head>
<body>
  <h1>Audit Penjualan & Voucher</h1>
  <div class="sub">Filter: <?= htmlspecialchars(format_date_dmy($filter_date)) ?> · Mode: <?= htmlspecialchars($req_show) ?></div>

  <div class="summary-grid">
    <div class="summary-card"><div class="summary-title">Total Transaksi</div><div class="summary-value"><?= number_format($sales_summary['total'],0,',','.') ?></div></div>
    <div class="summary-card"><div class="summary-title">Pendapatan Kotor</div><div class="summary-value">Rp <?= number_format($sales_summary['gross'],0,',','.') ?></div></div>
    <div class="summary-card"><div class="summary-title">Potongan Rusak</div><div class="summary-value">Rp <?= number_format($sales_summary['rusak'],0,',','.') ?></div></div>
    <div class="summary-card"><div class="summary-title">Pendapatan Bersih</div><div class="summary-value">Rp <?= number_format($sales_summary['net'],0,',','.') ?></div></div>
    <div class="summary-card"><div class="summary-title">Pending Live Sales</div><div class="summary-value"><?= number_format($sales_summary['pending'],0,',','.') ?></div></div>
    <div class="summary-card"><div class="summary-title">Pending Gross (Live)</div><div class="summary-value">Rp <?= number_format($pending_summary['gross'],0,',','.') ?></div></div>
  </div>

  <div class="section">
    <div class="section-title">Ringkasan Audit Manual</div>
    <?php if ($audit_manual_summary['rows'] === 0): ?>
      <div class="summary-card"><div class="summary-title">Audit Manual</div><div class="summary-value" style="font-size:11px;font-weight:normal;">Belum ada audit manual pada periode ini.</div></div>
    <?php else: ?>
      <div class="summary-grid">
        <div class="summary-card"><div class="summary-title">Net Audit (Manual)</div><div class="summary-value">Rp <?= number_format($audit_manual_summary['manual_setoran'],0,',','.') ?></div></div>
        <div class="summary-card"><div class="summary-title">Net System (Expected)</div><div class="summary-value">Rp <?= number_format($audit_manual_summary['expected_setoran'],0,',','.') ?></div></div>
        <div class="summary-card"><div class="summary-title">Selisih Setoran</div><div class="summary-value">Rp <?= number_format($audit_manual_summary['selisih_setoran'],0,',','.') ?></div></div>
        <div class="summary-card"><div class="summary-title">Selisih Qty</div><div class="summary-value"><?= number_format($audit_manual_summary['selisih_qty'],0,',','.') ?></div></div>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($sales_summary['pending'] > 0 && $sales_summary['total'] === 0): ?>
  <div class="section">
    <div class="summary-card"><div class="summary-title">Catatan</div><div class="summary-value" style="font-size:11px;font-weight:normal;">Transaksi final kosong. Data sementara ada di live_sales (pending).</div></div>
  </div>
  <?php endif; ?>

  <div class="section">
    <div class="section-title">Voucher Double (full_raw_data)</div>
    <table>
      <thead><tr><th>Sale Date</th><th>Username</th><th>Count</th><th>Raw</th></tr></thead>
      <tbody>
      <?php if (empty($dup_raw)): ?>
        <tr><td colspan="4" class="muted" style="text-align:center;">Tidak ada duplikasi</td></tr>
      <?php else: ?>
        <?php foreach ($dup_raw as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['sale_date'] ?? '-') ?></td>
            <td><?= htmlspecialchars($r['username'] ?? '-') ?></td>
            <td><?= (int)($r['cnt'] ?? 0) ?></td>
            <td><?= htmlspecialchars($r['full_raw_data'] ?? '-') ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="section">
    <div class="section-title">Voucher Double (Username + Tanggal)</div>
    <table>
      <thead><tr><th>Sale Date</th><th>Username</th><th>Count</th></tr></thead>
      <tbody>
      <?php if (empty($dup_user_date)): ?>
        <tr><td colspan="3" class="muted" style="text-align:center;">Tidak ada duplikasi</td></tr>
      <?php else: ?>
        <?php foreach ($dup_user_date as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['sale_date'] ?? '-') ?></td>
            <td><?= htmlspecialchars($r['username'] ?? '-') ?></td>
            <td><?= (int)($r['cnt'] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="section">
    <div class="section-title">Relogin (login_events) - Top <?= (int)$relogin_limit ?></div>
    <table>
      <thead><tr><th>Tanggal</th><th>Username</th><th>Blok</th><th>Profile</th><th>Jumlah Relogin</th></tr></thead>
      <tbody>
      <?php if (empty($relogin_rows)): ?>
        <tr><td colspan="5" class="muted" style="text-align:center;">Tidak ada relogin</td></tr>
      <?php else: ?>
        <?php foreach ($relogin_rows as $r): ?>
          <?php
            $p = extract_profile_from_comment($r['raw_comment'] ?? '');
            if ($p === '') $p = infer_profile_from_blok($r['blok_name'] ?? '');
            $blokLabel = format_blok_label($r['blok_name'] ?? '');
          ?>
          <tr>
            <td><?= htmlspecialchars(format_date_dmy($r['date_key'] ?? '-')) ?></td>
            <td><?= htmlspecialchars($r['username'] ?? '-') ?></td>
            <td><?= htmlspecialchars($blokLabel ?: '-') ?></td>
            <td><?= htmlspecialchars($p ?: '-') ?></td>
            <td><?= (int)($r['cnt'] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="section">
    <div class="section-title">Top Bandwidth (login_history) - Top <?= (int)$bandwidth_limit ?></div>
    <table>
      <thead><tr><th>Username</th><th>Blok</th><th>Profile</th><th>Last Bytes</th><th>Uptime</th><th>Status</th><th>Last Login</th></tr></thead>
      <tbody>
      <?php if (empty($bandwidth_rows)): ?>
        <tr><td colspan="7" class="muted" style="text-align:center;">Tidak ada data</td></tr>
      <?php else: ?>
        <?php foreach ($bandwidth_rows as $r): ?>
          <?php
            $p = extract_profile_from_comment($r['raw_comment'] ?? '');
            if ($p === '') $p = infer_profile_from_blok($r['blok_name'] ?? '');
            $blokLabel = format_blok_label($r['blok_name'] ?? '');
            $lastLogin = (string)($r['last_login_real'] ?? '');
            $lastDate = substr($lastLogin, 0, 10);
            $lastTime = substr($lastLogin, 11, 8);
          ?>
          <tr>
            <td><?= htmlspecialchars($r['username'] ?? '-') ?></td>
            <td><?= htmlspecialchars($blokLabel ?: '-') ?></td>
            <td><?= htmlspecialchars($p ?: '-') ?></td>
            <td><?= htmlspecialchars(format_bytes_short($r['last_bytes'] ?? 0)) ?></td>
            <td><?= htmlspecialchars($r['last_uptime'] ?? '-') ?></td>
            <td><?= strtoupper(htmlspecialchars($r['last_status'] ?? '-')) ?></td>
            <td><?= htmlspecialchars(format_date_dmy($lastDate)) ?> <?= htmlspecialchars($lastTime) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</body>
</html>
