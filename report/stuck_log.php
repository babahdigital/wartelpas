<?php
session_start();
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

$root_dir = dirname(__DIR__);
$log_dir = $root_dir . '/logs';
$session = isset($_GET['session']) ? (string)$_GET['session'] : '';
$date = trim((string)($_GET['date'] ?? ''));
if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}
$q = trim((string)($_GET['q'] ?? ''));

$rows = [];
$log_file = $log_dir . '/stuck_kick_' . $date . '.log';
if (is_file($log_file)) {
    $lines = @file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $item = json_decode($line, true);
        if (!is_array($item)) continue;
        $rows[] = $item;
    }
}

if ($q !== '') {
    $needle = mb_strtolower($q, 'UTF-8');
    $rows = array_values(array_filter($rows, function($row) use ($needle) {
        $fields = [
            $row['user'] ?? '',
            $row['ip'] ?? '',
            $row['mac'] ?? '',
            $row['reason'] ?? '',
            $row['profile'] ?? '',
            $row['server'] ?? ''
        ];
        foreach ($fields as $f) {
            if ($f !== '' && mb_stripos((string)$f, $needle, 0, 'UTF-8') !== false) {
                return true;
            }
        }
        return false;
    }));
}

require_once $root_dir . '/lib/formatbytesbites.php';

function stuck_fmt_bytes($bytes) {
    if (!function_exists('formatBytes')) return (string)$bytes;
    $label = formatBytes((int)$bytes, 2);
    $label = str_replace(' ', '', $label);
    $label = str_replace('Byte', 'B', $label);
    return $label;
}

?>
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3><i class="fa fa-exclamation-triangle"></i> Stuck Kick Log</h3>
            </div>
            <div class="card-body">
                <form method="get" action="./" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:12px;">
                    <input type="hidden" name="report" value="stuck_log">
                    <input type="hidden" name="session" value="<?= htmlspecialchars($session); ?>">
                    <input type="date" name="date" value="<?= htmlspecialchars($date); ?>" class="form-control" style="max-width:180px;">
                    <input type="text" name="q" value="<?= htmlspecialchars($q); ?>" class="form-control" placeholder="Cari voucher/IP/MAC..." style="max-width:240px;">
                    <button type="submit" class="btn bg-primary"><i class="fa fa-search"></i> Filter</button>
                    <span style="font-size:12px;color:#aaa;">Total: <?= count($rows); ?> data</span>
                </form>

                <div class="overflow box-bordered" style="max-height: 75vh;">
                    <table class="table table-bordered table-hover text-nowrap">
                        <thead>
                            <tr>
                                <th>Waktu</th>
                                <th>Voucher</th>
                                <th>IP</th>
                                <th>MAC</th>
                                <th>Uptime</th>
                                <th>Bytes In</th>
                                <th>Bytes Out</th>
                                <th>Total</th>
                                <th>Reason</th>
                                <th>Profile</th>
                                <th>Server</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="11" style="text-align:center;color:#9aa0a6;">Tidak ada data untuk tanggal ini.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $r): ?>
                                    <?php
                                        $bin = (int)($r['bytes_in'] ?? 0);
                                        $bout = (int)($r['bytes_out'] ?? 0);
                                        $total = $bin + $bout;
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($r['ts'] ?? '-'); ?></td>
                                        <td><?= htmlspecialchars($r['user'] ?? '-'); ?></td>
                                        <td><?= htmlspecialchars($r['ip'] ?? '-'); ?></td>
                                        <td><?= htmlspecialchars($r['mac'] ?? '-'); ?></td>
                                        <td><?= htmlspecialchars($r['uptime'] ?? '-'); ?></td>
                                        <td><?= htmlspecialchars(stuck_fmt_bytes($bin)); ?></td>
                                        <td><?= htmlspecialchars(stuck_fmt_bytes($bout)); ?></td>
                                        <td><?= htmlspecialchars(stuck_fmt_bytes($total)); ?></td>
                                        <td><?= htmlspecialchars($r['reason'] ?? '-'); ?></td>
                                        <td><?= htmlspecialchars($r['profile'] ?? '-'); ?></td>
                                        <td><?= htmlspecialchars($r['server'] ?? '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
