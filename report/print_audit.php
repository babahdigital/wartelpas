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
  $total_ghost = $ghost_10 + $ghost_30;
  return 'Analisa dari selisih ' . number_format($total_ghost, 0, ',', '.') . ' lembar: ' . implode(' + ', $parts);
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
  'total_rusak_rp' => 0,
];

if (file_exists($dbFile)) {
    try {
        $db = new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $dateFilter = '';
        $dateParam = [];
        $auditDateFilter = '';
        $auditDateParam = [];
        if ($req_show === 'harian') {
          $dateFilter = '(sale_date = :d OR raw_date LIKE :raw1 OR raw_date LIKE :raw2 OR raw_date LIKE :raw3 OR raw_date LIKE :raw4)';
          $dateParam[':d'] = $filter_date;
          $dateParam[':raw1'] = $filter_date . '%';
          $dateParam[':raw2'] = date('m/d/Y', strtotime($filter_date)) . '%';
          $dateParam[':raw3'] = date('d/m/Y', strtotime($filter_date)) . '%';
          $dateParam[':raw4'] = date('M/d/Y', strtotime($filter_date)) . '%';
          $auditDateFilter = 'report_date = :d';
          $auditDateParam[':d'] = $filter_date;
        } elseif ($req_show === 'bulanan') {
          $ym = $filter_date;
          $year = substr($ym, 0, 4);
          $month = substr($ym, 5, 2);
          $monthShort = date('M', strtotime($ym . '-01'));
          $dateFilter = '(sale_date LIKE :d OR raw_date LIKE :raw1 OR raw_date LIKE :raw2 OR raw_date LIKE :raw3)';
          $dateParam[':d'] = $ym . '%';
          $dateParam[':raw1'] = $month . '/%/' . $year;
          $dateParam[':raw2'] = '%/' . $month . '/' . $year;
          $dateParam[':raw3'] = $monthShort . '/%/' . $year;
          $auditDateFilter = 'report_date LIKE :d';
          $auditDateParam[':d'] = $ym . '%';
        } else {
          $year = $filter_date;
          $dateFilter = '(sale_date LIKE :d OR raw_date LIKE :raw1)';
          $dateParam[':d'] = $year . '%';
          $dateParam[':raw1'] = '%/' . $year;
          $auditDateFilter = 'report_date LIKE :d';
          $auditDateParam[':d'] = $year . '%';
        }

        if (table_exists($db, 'sales_history')) {
            $sumSql = "SELECT
              SUM(CASE WHEN COALESCE(is_invalid,0)=1
                OR LOWER(COALESCE(status,''))='invalid'
                OR (COALESCE(status,'')='' AND LOWER(COALESCE(comment,'')) LIKE '%invalid%')
              THEN COALESCE(price_snapshot, price, 0) * COALESCE(qty,1) ELSE 0 END) AS invalid_sum,
              SUM(CASE WHEN COALESCE(is_rusak,0)=1
                OR LOWER(COALESCE(status,''))='rusak'
                OR (COALESCE(status,'')='' AND LOWER(COALESCE(comment,'')) LIKE '%rusak%')
              THEN COALESCE(price_snapshot, price, 0) * COALESCE(qty,1) ELSE 0 END) AS rusak_sum,
              SUM(CASE WHEN COALESCE(is_retur,0)=1
                OR LOWER(COALESCE(status,''))='retur'
                OR (COALESCE(status,'')='' AND LOWER(COALESCE(comment,'')) LIKE '%retur%')
              THEN COALESCE(price_snapshot, price, 0) * COALESCE(qty,1) ELSE 0 END) AS retur_sum,
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

        }

        if (table_exists($db, 'live_sales')) {
            $pendingSql = "SELECT COUNT(*) FROM live_sales WHERE sync_status='pending' AND $dateFilter";
            $stmt = $db->prepare($pendingSql);
            foreach ($dateParam as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
            $sales_summary['pending'] = (int)($stmt->fetchColumn() ?: 0);

            $pendingSumSql = "SELECT
              SUM(CASE WHEN COALESCE(is_invalid,0)=1
                OR LOWER(COALESCE(status,''))='invalid'
                OR (COALESCE(status,'')='' AND LOWER(COALESCE(comment,'')) LIKE '%invalid%')
              THEN COALESCE(price_snapshot, price, 0) * COALESCE(qty,1) ELSE 0 END) AS invalid_sum,
              SUM(CASE WHEN COALESCE(is_rusak,0)=1
                OR LOWER(COALESCE(status,''))='rusak'
                OR (COALESCE(status,'')='' AND LOWER(COALESCE(comment,'')) LIKE '%rusak%')
              THEN COALESCE(price_snapshot, price, 0) * COALESCE(qty,1) ELSE 0 END) AS rusak_sum,
              SUM(CASE WHEN COALESCE(is_retur,0)=1
                OR LOWER(COALESCE(status,''))='retur'
                OR (COALESCE(status,'')='' AND LOWER(COALESCE(comment,'')) LIKE '%retur%')
              THEN COALESCE(price_snapshot, price, 0) * COALESCE(qty,1) ELSE 0 END) AS retur_sum,
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

        if (table_exists($db, 'audit_rekap_manual')) {
          $auditSql = "SELECT expected_qty, expected_setoran, reported_qty, actual_setoran, user_evidence
          FROM audit_rekap_manual WHERE $auditDateFilter";
          $stmt = $db->prepare($auditSql);
          foreach ($auditDateParam as $k => $v) $stmt->bindValue($k, $v);
          $stmt->execute();
          $audit_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
          $audit_manual_summary['rows'] = count($audit_rows);
          $audit_manual_summary['total_rusak_rp'] = 0;
          foreach ($audit_rows as $ar) {
            [$manual_qty, $expected_qty, $manual_setoran, $expected_setoran] = calc_audit_adjusted_totals($ar);
            $audit_manual_summary['manual_qty'] += (int)$manual_qty;
            $audit_manual_summary['expected_qty'] += (int)$expected_qty;
            $audit_manual_summary['manual_setoran'] += (int)$manual_setoran;
            $audit_manual_summary['expected_setoran'] += (int)$expected_setoran;

            $curr_rusak_rp = 0;
            if (!empty($ar['user_evidence'])) {
              $ev = json_decode((string)$ar['user_evidence'], true);
              if (is_array($ev) && !empty($ev['users'])) {
                foreach ($ev['users'] as $u) {
                  $st = strtolower((string)($u['last_status'] ?? ''));
                  $k = (string)($u['profile_kind'] ?? '10');
                  if ($st === 'rusak' || $st === 'invalid') {
                    $price = ($k === '30') ? 20000 : 5000;
                    $curr_rusak_rp += $price;
                  }
                }
              }
            }
            $audit_manual_summary['total_rusak_rp'] += $curr_rusak_rp;
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
      <?php
        $use_pending_stats = ($sales_summary['total'] === 0 && $sales_summary['pending'] > 0);
        $target_est = $use_pending_stats ? $pending_summary['net'] : $sales_summary['net'];
      ?>
      <div style="border: 2px dashed #ccc; background-color: #fafafa; padding: 20px; border-radius: 4px; margin-bottom: 20px; text-align:center;">
        <h3 style="margin:0 0 10px 0; color:#555;">BELUM ADA AUDIT MANUAL</h3>
        <p style="margin:0; font-size:12px; color:#666;">Operator belum melakukan input fisik uang dan voucher.</p>
        <div style="margin-top:20px; border-top:1px solid #eee; padding-top:15px;">
          <div style="font-size:11px; text-transform:uppercase; color:#888;">Target Setoran Sistem (Estimasi)</div>
          <div style="font-size:24px; font-weight:bold; color:#333;">Rp <?= number_format($target_est, 0, ',', '.') ?></div>
        </div>
      </div>
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

    <div class="section-title">Statistik Keuangan & Insiden</div>
    <?php
      $stat_total = (int)$sales_summary['total'] + (int)$sales_summary['pending'];
      $stat_gross = (int)$sales_summary['gross'] + (int)$pending_summary['gross'];
      $stat_rusak_system = (int)$sales_summary['rusak'] + (int)$pending_summary['rusak'];
      $stat_rusak_manual = (int)($audit_manual_summary['total_rusak_rp'] ?? 0);
      $total_loss_real = $stat_rusak_system + $stat_rusak_manual;
    ?>
    <div class="summary-grid" style="grid-template-columns: repeat(3, 1fr);">
    <div class="summary-card"><div class="summary-title">Total Transaksi</div><div class="summary-value"><?= number_format($stat_total,0,',','.') ?></div><div style="font-size:10px;color:#888;">(Final + Live)</div></div>
    <div class="summary-card"><div class="summary-title">Pendapatan Kotor (Gross)</div><div class="summary-value">Rp <?= number_format($stat_gross,0,',','.') ?></div></div>
    <div class="summary-card"><div class="summary-title" style="color:#c0392b;">Total Voucher Rusak</div><div class="summary-value" style="color:#c0392b;">Rp <?= number_format($total_loss_real,0,',','.') ?></div><div style="font-size:10px;color:#b91c1c;">(Mengurangi Setoran)</div></div>
    </div>

    <?php if ($sales_summary['pending'] > 0): ?>
      <div style="margin-top:10px; padding:8px; border:1px solid #e2e8f0; background:#f8fafc; font-size:11px; color:#64748b;">
        <strong>Catatan Sistem:</strong> Angka di atas mencakup <?= number_format($sales_summary['pending']) ?> transaksi Live (Pending) yang belum dilakukan settlement.
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
