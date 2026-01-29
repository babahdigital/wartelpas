<?php
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
  exit;
}

require_once(__DIR__ . '/../include/acl.php');
if (!isSuperAdmin()) {
  echo "<div style='padding:14px;font-family:Arial;'>Akses ditolak.</div>";
  exit;
}

$env = [];
$envFile = __DIR__ . '/../include/env.php';
if (file_exists($envFile)) {
  require $envFile;
}
$system_cfg = $env['system'] ?? [];
$db_rel = $system_cfg['db_file'] ?? 'db_data/mikhmon_stats.db';
if (preg_match('/^[A-Za-z]:\\|^\//', $db_rel)) {
  $dbFile = $db_rel;
} else {
  $dbFile = __DIR__ . '/../' . ltrim($db_rel, '/');
}

function normalize_vip_comment($comment) {
  $c = (string)$comment;
  if ($c === '') return '';
  $c = preg_replace('/\bvip\b/i', '', $c);
  $c = preg_replace('/\s{2,}/', ' ', $c);
  $c = preg_replace('/\s*\|\s*/', ' | ', $c);
  $c = preg_replace('/(\s*\|\s*){2,}/', ' | ', $c);
  $c = trim($c);
  $c = trim($c, "| ");
  return $c;
}

$db = null;
try {
  $db = new PDO('sqlite:' . $dbFile);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
  $db = null;
}

$action = $_POST['action'] ?? '';
$done = false;
$affected = 0;
$errors = '';

if ($db && $action === 'cleanup') {
  try {
    $stmt = $db->prepare("SELECT id, username, raw_comment, last_status FROM login_history WHERE lower(raw_comment) LIKE '%vip%' OR lower(last_status) = 'vip'");
    $rows = $stmt->execute() ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $db->beginTransaction();
    $upd = $db->prepare("UPDATE login_history SET raw_comment = :raw_comment, last_status = :last_status, updated_at = :updated_at WHERE id = :id");
    foreach ($rows as $r) {
      $raw = $r['raw_comment'] ?? '';
      $new_raw = normalize_vip_comment($raw);
      $new_status = strtolower((string)($r['last_status'] ?? '')) === 'vip' ? 'ready' : (string)($r['last_status'] ?? 'ready');
      if ($new_raw !== $raw || $new_status !== $r['last_status']) {
        $upd->execute([
          ':raw_comment' => $new_raw,
          ':last_status' => $new_status,
          ':updated_at' => date('Y-m-d H:i:s'),
          ':id' => (int)$r['id']
        ]);
        $affected++;
      }
    }
    $db->commit();
    $done = true;
  } catch (Exception $e) {
    if ($db && $db->inTransaction()) $db->rollBack();
    $errors = $e->getMessage();
  }
}

$total_vip = 0;
$sample_rows = [];
if ($db) {
  try {
    $stmt = $db->prepare("SELECT id, username, raw_comment, last_status FROM login_history WHERE lower(raw_comment) LIKE '%vip%' OR lower(last_status) = 'vip' ORDER BY username ASC LIMIT 30");
    $stmt->execute();
    $sample_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt2 = $db->prepare("SELECT COUNT(1) AS cnt FROM login_history WHERE lower(raw_comment) LIKE '%vip%' OR lower(last_status) = 'vip'");
    $stmt2->execute();
    $total_vip = (int)($stmt2->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
  } catch (Exception $e) {
    $sample_rows = [];
  }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>VIP Cleanup</title>
  <style>
    body { font-family: Arial, Helvetica, sans-serif; background:#0f172a; color:#e5e7eb; padding:20px; }
    .card { background:#111827; border:1px solid #1f2937; border-radius:10px; padding:16px; max-width:900px; margin:0 auto; }
    .title { font-size:18px; font-weight:700; margin-bottom:10px; }
    .meta { font-size:12px; color:#9ca3af; margin-bottom:12px; }
    .btn { padding:10px 14px; border:0; border-radius:6px; background:#ef4444; color:#fff; cursor:pointer; font-weight:600; }
    .btn:disabled { opacity:0.6; cursor:not-allowed; }
    table { width:100%; border-collapse: collapse; margin-top:12px; }
    th, td { border:1px solid #1f2937; padding:8px; font-size:12px; }
    th { background:#1f2937; text-align:left; }
    .ok { background:#14532d; color:#bbf7d0; padding:8px 10px; border-radius:6px; margin-bottom:10px; }
    .err { background:#7f1d1d; color:#fecaca; padding:8px 10px; border-radius:6px; margin-bottom:10px; }
    .note { font-size:12px; color:#cbd5e1; margin-top:8px; }
  </style>
</head>
<body>
  <div class="card">
    <div class="title">VIP Cleanup (Database)</div>
    <div class="meta">DB: <?= htmlspecialchars($dbFile) ?></div>

    <?php if (!$db): ?>
      <div class="err">Gagal konek database.</div>
    <?php endif; ?>

    <?php if ($done): ?>
      <div class="ok">Selesai. Data terupdate: <?= (int)$affected ?> baris.</div>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="err">Error: <?= htmlspecialchars($errors) ?></div>
    <?php endif; ?>

    <div class="meta">Total baris VIP terdeteksi: <strong><?= (int)$total_vip ?></strong></div>

    <form method="post" onsubmit="return confirm('Yakin hapus tag VIP di database?');">
      <input type="hidden" name="action" value="cleanup">
      <button type="submit" class="btn" <?= (!$db || $total_vip === 0) ? 'disabled' : '' ?>>Hapus Tag VIP (DB)</button>
    </form>

    <div class="note">Catatan: Tool ini hanya menghapus tag VIP di kolom <strong>raw_comment</strong> dan mengubah <strong>last_status</strong> dari VIP ke READY.</div>

    <?php if (!empty($sample_rows)): ?>
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Status</th>
            <th>Raw Comment</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sample_rows as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= htmlspecialchars($r['username'] ?? '') ?></td>
              <td><?= htmlspecialchars($r['last_status'] ?? '') ?></td>
              <td><?= htmlspecialchars($r['raw_comment'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</body>
</html>
