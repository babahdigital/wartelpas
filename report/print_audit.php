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
  if (strlen($filter_date) > 7) $filter_date = substr($filter_date, 0, 7);
} else {
    $req_show = 'tahunan';
  $filter_date = $filter_date ?: date('Y');
  if (strlen($filter_date) > 4) $filter_date = substr($filter_date, 0, 4);
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

function build_ghost_hint($selisih_qty, $selisih_rp) {
  $ghost_qty = abs((int)$selisih_qty);
  $ghost_rp = abs((int)$selisih_rp);
  if ($ghost_qty <= 0 || $ghost_rp <= 0) return '';

  $price10 = 5000;
  $price30 = 20000;
  $divisor = $price30 - $price10;
  $ghost_10 = 0;
  $ghost_30 = 0;

  if ($ghost_rp >= ($ghost_qty * $price10) && $divisor > 0) {
    $numerator = $ghost_rp - ($ghost_qty * $price10);
    if ($numerator % $divisor === 0) {
      $ghost_30 = (int)($numerator / $divisor);
      $ghost_10 = $ghost_qty - $ghost_30;
    }
  }

  if ($ghost_10 < 0 || $ghost_30 < 0) {
    $ghost_10 = 0;
    $ghost_30 = 0;
  }

  if ($ghost_10 === 0 && $ghost_30 === 0) {
    if ($ghost_rp === ($price30 * $ghost_qty)) {
      $ghost_30 = $ghost_qty;
    } elseif ($ghost_rp === ($price10 * $ghost_qty)) {
      $ghost_10 = $ghost_qty;
    }
  }

  if ($ghost_10 <= 0 && $ghost_30 <= 0) return '';
  $parts = [];
  if ($ghost_10 > 0) $parts[] = number_format($ghost_10, 0, ',', '.') . ' unit 10 menit';
  if ($ghost_30 > 0) $parts[] = number_format($ghost_30, 0, ',', '.') . ' unit 30 menit';
  return 'Kemungkinan: ' . implode(' + ', $parts) . '.';
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
    $expected_adj_qty = $expected_qty;
    $expected_adj_setoran = $expected_setoran;
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
              SUM(COALESCE(price_snapshot, price, 0) * COALESCE(qty,1)) AS gross_sum,
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
              SUM(COALESCE(price_snapshot, price, 0) * COALESCE(qty,1)) AS gross_sum,
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
    .toolbar { margin-bottom: 10px; display:flex; gap:8px; flex-wrap:wrap; }
    .btn { padding:6px 10px; border:1px solid #999; background:#f2f2f2; cursor:pointer; border-radius:4px; font-size:12px; }
    @page { margin: 8mm; }
</style>
</head>
<body>
  <div class="toolbar">
      <button class="btn" onclick="window.print()">Print / Download PDF</button>
      <button class="btn" onclick="shareReport()">Share</button>
  </div>

  <div style="border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px;">
      <h1 style="margin:0;">Laporan Audit Keuangan</h1>
      <div class="sub" style="margin-top:5px;">
          Periode: <?= htmlspecialchars(format_date_dmy($filter_date)) ?> |
          Mode: <?= strtoupper(htmlspecialchars($req_show)) ?>
      </div>
  </div>

  <?php
      $selisih = $audit_manual_summary['selisih_setoran'];
      $ghost_hint = build_ghost_hint($audit_manual_summary['selisih_qty'], $selisih);
      $bg_status = $selisih < 0 ? '#fee2e2' : ($selisih > 0 ? '#dcfce7' : '#f3f4f6');
      $border_status = $selisih < 0 ? '#b91c1c' : ($selisih > 0 ? '#15803d' : '#ccc');
      $text_color = $selisih < 0 ? '#b91c1c' : ($selisih > 0 ? '#15803d' : '#333');
      $label_status = $selisih < 0 ? 'KURANG SETOR (LOSS)' : ($selisih > 0 ? 'LEBIH SETOR' : 'SETORAN SESUAI / AMAN');
  ?>

  <?php if ($audit_manual_summary['rows'] === 0): ?>
      <div class="summary-card"><div class="summary-title">Audit Manual</div><div class="summary-value" style="font-size:11px;font-weight:normal;">Belum ada audit manual pada periode ini.</div></div>
  <?php else: ?>
  <div style="border: 2px solid <?= $border_status ?>; background-color: <?= $bg_status ?>; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
      <table style="width:100%; border:none;">
          <tr>
              <td style="border:none; padding:0;">
                  <div style="font-size:10px; color:#555; text-transform:uppercase;">Status Audit</div>
                  <div style="font-size:18px; font-weight:bold; color:<?= $text_color ?>; margin-top:4px;">
                      <?= $label_status ?>
                  </div>
              </td>
              <td style="border:none; padding:0; text-align:right;">
                  <div style="font-size:10px; color:#555; text-transform:uppercase;">Total Selisih</div>
                  <div style="font-size:18px; font-weight:bold; color:<?= $text_color ?>; margin-top:4px;">
                      Rp <?= number_format($selisih, 0, ',', '.') ?>
                  </div>
              </td>
          </tr>
      </table>

      <?php if (!empty($ghost_hint)): ?>
          <div style="margin-top:10px; padding-top:10px; border-top:1px dashed <?= $border_status ?>; font-size:12px; color:<?= $text_color ?>;">
              <strong>Indikasi (Ghost Hunter):</strong> <?= htmlspecialchars($ghost_hint) ?>
          </div>
      <?php endif; ?>
  </div>

  <div class="section-title">Rincian Perhitungan</div>
  <div class="summary-grid">
    <div class="summary-card">
        <div class="summary-title">Uang Fisik (Manual)</div>
        <div class="summary-value">Rp <?= number_format($audit_manual_summary['manual_setoran'],0,',','.') ?></div>
    </div>
    <div class="summary-card">
        <div class="summary-title">Target Sistem (Net)</div>
        <div class="summary-value">Rp <?= number_format($audit_manual_summary['expected_setoran'],0,',','.') ?></div>
    </div>
    <div class="summary-card">
        <div class="summary-title">Selisih Uang</div>
        <div class="summary-value" style="color:<?= $text_color ?>;">Rp <?= number_format($selisih,0,',','.') ?></div>
    </div>
    <div class="summary-card">
        <div class="summary-title">Selisih Qty</div>
        <div class="summary-value" style="color:<?= $text_color ?>;"><?= number_format($audit_manual_summary['selisih_qty'],0,',','.') ?></div>
    </div>
  </div>
  <?php endif; ?>

  <div class="section-title">Data Sistem</div>
  <div class="summary-grid" style="grid-template-columns: repeat(3, 1fr);">
    <div class="summary-card"><div class="summary-title">Total Transaksi</div><div class="summary-value"><?= number_format($sales_summary['total'],0,',','.') ?></div></div>
    <div class="summary-card"><div class="summary-title">Pendapatan Kotor</div><div class="summary-value">Rp <?= number_format($sales_summary['gross'],0,',','.') ?></div></div>
    <div class="summary-card"><div class="summary-title">Potongan Rusak</div><div class="summary-value" style="color:#c0392b;">Rp <?= number_format($sales_summary['rusak'],0,',','.') ?></div></div>
  </div>

  <?php if ($sales_summary['pending'] > 0): ?>
      <div style="margin-top:15px; padding:10px; border:1px solid #ffcc00; background:#fffbe6; font-size:11px;">
          <strong>Catatan Teknis:</strong> Terdapat <?= number_format($sales_summary['pending']) ?> transaksi status "Pending" (Live Sales) yang belum masuk rekap final.
      </div>
  <?php endif; ?>

  <div style="margin-top:30px; font-size:10px; color:#999; text-align:center;">
      Dicetak oleh Sistem Wartelpas pada <?= date('d-m-Y H:i:s') ?>
  </div>

  <script>
    function shareReport(){
        if (navigator.share) {
            navigator.share({ title: 'Laporan Audit Keuangan', url: window.location.href });
        } else {
            window.prompt('Salin link laporan:', window.location.href);
        }
    }
  </script>
</body>
</html>
