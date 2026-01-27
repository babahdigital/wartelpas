<?php
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

$session = $_GET['session'] ?? '';
if ($session === '') {
    echo "Session tidak valid.";
    exit;
}

if (empty($_GET['comment']) && !empty($_GET['blok'])) {
  $_GET['comment'] = $_GET['blok'];
}

$_GET['print'] = '1';
$_GET['readonly'] = '1';

require __DIR__ . '/../user/bootstrap.php';
require __DIR__ . '/../user/helpers.php';
require __DIR__ . '/../user/data.php';

function format_profile_label_print($req_prof) {
    $p = trim((string)$req_prof);
    if ($p === '' || $p === 'all') return '';
    if (preg_match('/^(10|30)$/', $p, $m)) {
        return $m[1] . ' Menit';
    }
    return $p;
}

function format_filter_date_print($show, $date) {
    if ($show === 'semua' || $date === '') return '';
    $ts = strtotime($date);
    if (!$ts) return $date;
    if ($show === 'harian') return date('d-m-Y', $ts);
    if ($show === 'bulanan') return date('m-Y', $ts);
    if ($show === 'tahunan') return date('Y', $ts);
    return $date;
}


function format_blok_label_print($blok) {
  $b = strtoupper(trim((string)$blok));
  $b = preg_replace('/^BLOK[-_\s]*/', '', $b);
  return $b;
}

$status_labels = [
  'used' => 'Terpakai',
  'terpakai' => 'Terpakai',
  'online' => 'Online',
  'rusak' => 'Rusak',
  'retur' => 'Retur',
  'ready' => 'Ready',
  'all' => 'Terpakai'
];
$status_label = $status_labels[$req_status] ?? 'Terpakai';
$title_labels = [
  'used' => 'List Voucher Terpakai',
  'terpakai' => 'List Voucher Terpakai',
  'online' => 'List User Online',
  'rusak' => 'List Voucher Rusak',
  'retur' => 'List Voucher Retur',
  'ready' => 'List Voucher Ready',
  'all' => 'List Voucher Terpakai'
];
$title_text = $title_labels[$req_status] ?? 'List Voucher Terpakai';
$profile_label = format_profile_label_print($req_prof);
$date_label = format_filter_date_print($req_show, $filter_date);
$hide_logout_col = in_array($req_status, ['used', 'terpakai', 'all', 'online', 'rusak', 'retur'], true);
$is_ready_print = ($req_status === 'ready');
$retur_ref_map = [];
if (!empty($display_data)) {
  foreach ($display_data as $row) {
    $ref_u = extract_retur_user_from_ref($row['comment'] ?? '');
    if ($ref_u !== '') {
      $retur_ref_map[strtolower($ref_u)] = true;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Print List Hotspot</title>
  <style>
    body { font-family: Arial, Helvetica, sans-serif; color: #111827; margin: 20px; }
    .wrap { padding: 0; }
    .toolbar { margin-bottom: 12px; display:flex; gap:8px; flex-wrap:wrap; }
    .btn { padding:6px 10px; border:1px solid #999; background:#f2f2f2; cursor:pointer; border-radius:4px; font-size:12px; }
    .title { font-size: 18px; font-weight: 700; margin-bottom: 2px; }
    .meta { font-size: 12px; color: #374151; margin-bottom: 8px; }
    .meta span { margin-right: 12px; display: inline-block; }
    table { width: 100%; border-collapse: collapse; font-size: 11px; }
    th, td { border: 1px solid #d1d5db; padding: 4px 6px; vertical-align: top; }
    th { background: #f3f4f6; text-align: left; }
    .text-right { text-align: right; }
    .text-center { text-align: center; }
    .muted { color: #6b7280; }
    @media print {
      .toolbar { display:none; }
      * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
  </style>
</head>
<body>
<div class="wrap">
  <div class="toolbar">
    <button class="btn" type="button" onclick="window.print()">Print / Download PDF</button>
    <button class="btn" type="button" onclick="shareReport()">Share</button>
  </div>
  <div class="title"><?= htmlspecialchars($title_text) ?></div>
  <div class="meta">
    <span>Status: <strong><?= htmlspecialchars($status_label) ?></strong></span>
    <?php if (!empty($req_comm)): ?>
      <span>Blok: <strong><?= htmlspecialchars(format_blok_label_print($req_comm)) ?></strong></span>
    <?php endif; ?>
    <?php if (!empty($profile_label)): ?>
      <span>Profil: <strong><?= htmlspecialchars($profile_label) ?></strong></span>
    <?php endif; ?>
    <?php if (!empty($date_label)): ?>
      <span>Tanggal: <strong><?= htmlspecialchars($date_label) ?></strong></span>
    <?php endif; ?>
    <span>Total: <strong><?= (int)$total_items ?></strong></span>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:30px;" class="text-center">No</th>
        <th class="text-center">Username</th>
        <?php if (!$is_ready_print): ?>
          <th class="text-center">MAC</th>
          <th class="text-center">IP</th>
          <th class="text-center">Login</th>
          <?php if (!$hide_logout_col): ?>
            <th>Logout</th>
          <?php endif; ?>
          <th class="text-center">Uptime</th>
          <th class="text-center">Bytes</th>
        <?php endif; ?>
        <?php if (!$is_ready_print): ?>
          <th class="text-center">Status</th>
        <?php endif; ?>
        <?php if ($is_ready_print): ?>
          <th class="text-center" style="width:180px;">Nama</th>
          <th class="text-center" style="width:90px;">Tujuan</th>
          <th class="text-center" style="width:120px;">Hubungan</th>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (!empty($display_data)): ?>
        <?php $i = 1; foreach ($display_data as $u): ?>
          <tr>
            <td class="text-center"><?= $i++ ?></td>
            <td>
              <div class="text-center"><strong><?= htmlspecialchars($u['name'] ?? '-') ?></strong></div>
              <?php
                $retur_ref_user = extract_retur_user_from_ref($u['comment'] ?? '');
                if ($retur_ref_user !== ''):
              ?>
                <div class="muted" style="font-size:10px; text-align:center;">Ref: <?= htmlspecialchars($retur_ref_user) ?></div>
              <?php endif; ?>
            </td>
            <?php if (!$is_ready_print): ?>
              <td class="text-center" style="font-family:monospace; font-size:10px;">
                <?= htmlspecialchars($u['mac'] ?? '-') ?>
              </td>
              <td class="text-center" style="font-family:monospace; font-size:10px;">
                <?= htmlspecialchars($u['ip'] ?? '-') ?>
              </td>
              <td class="text-center"><?= htmlspecialchars(formatDateIndo($u['login_time'] ?? '-')) ?></td>
              <?php if (!$hide_logout_col): ?>
                <td class="text-center"><?= htmlspecialchars(formatDateIndo($u['logout_time'] ?? '-')) ?></td>
              <?php endif; ?>
              <td class="text-center"><?= htmlspecialchars($u['uptime'] ?? '-') ?></td>
              <td class="text-center"><?= htmlspecialchars(formatBytes((int)($u['bytes'] ?? 0), 2)) ?></td>
            <?php endif; ?>
            <?php
              $st = strtolower((string)($u['status'] ?? ''));
              $st_label = strtoupper($st);
              $is_retur_source = !empty($u['name']) && isset($retur_ref_map[strtolower((string)$u['name'])]);
              if ($st === 'rusak' && $is_retur_source) {
                $st_label = 'RUSAK (DIGANTI)';
              } elseif ($st === 'retur') {
                $st_label = 'RETUR (PENGGANTI)';
              }
            ?>
            <?php if (!$is_ready_print): ?>
              <td class="text-center"><?= htmlspecialchars($st_label) ?></td>
            <?php endif; ?>
            <?php if ($is_ready_print): ?>
              <td class="text-center"></td>
              <td class="text-center"></td>
              <td class="text-center"></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr>
          <td colspan="<?= $is_ready_print ? 7 : ($hide_logout_col ? 9 : 10) ?>" class="text-center">Data tidak ditemukan.</td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<script>
  (function(){
    try {
      const params = new URLSearchParams(window.location.search);
      if (params.get('auto_print') === '1') {
        setTimeout(() => window.print(), 300);
      }
    } catch (e) {}
  })();
  function shareReport() {
    const url = window.location.href;
    if (navigator.share) {
      navigator.share({ title: document.title, url: url }).catch(function(){});
      return;
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(function() {
        alert('Link disalin.');
      }).catch(function() {
        prompt('Salin link:', url);
      });
      return;
    }
    prompt('Salin link:', url);
  }
</script>
</body>
</html>
