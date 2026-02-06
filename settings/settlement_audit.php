<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/db_helpers.php';
requireLogin('../admin.php?id=login');
requireSuperAdmin('../admin.php?id=sessions');

$stats_db = null;
$stats_db_error = '';
$rows = [];
$filter_date = trim((string)($_GET['date'] ?? ''));
if ($filter_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date)) {
    $filter_date = '';
}

try {
    $stats_db_path = get_stats_db_path();
    if ($stats_db_path !== '') {
        $stats_db = new PDO('sqlite:' . $stats_db_path);
        $stats_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stats_db->exec("PRAGMA journal_mode=WAL;");
        $stats_db->exec("PRAGMA busy_timeout=5000;");
        $stats_db->exec("CREATE TABLE IF NOT EXISTS settlement_actions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_date TEXT,
            action TEXT,
            actor TEXT,
            role TEXT,
            ip_address TEXT,
            result TEXT,
            message TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }
} catch (Exception $e) {
    $stats_db_error = $e->getMessage();
    $stats_db = null;
}

if ($stats_db) {
    try {
        if ($filter_date !== '') {
            $stmt = $stats_db->prepare("SELECT * FROM settlement_actions WHERE report_date = :d ORDER BY created_at DESC");
            $stmt->execute([':d' => $filter_date]);
        } else {
            $stmt = $stats_db->prepare("SELECT * FROM settlement_actions ORDER BY created_at DESC LIMIT 200");
            $stmt->execute();
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $stats_db_error = $e->getMessage();
    }
}
?>

<div class="card-modern" style="margin-bottom:16px;">
    <div class="card-header-modern" style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
        <h3><i class="fa fa-clipboard"></i> Audit Settlement</h3>
        <form method="get" action="" style="display:flex;gap:8px;align-items:center;">
            <input type="hidden" name="id" value="settlement-audit">
            <input type="date" name="date" value="<?= htmlspecialchars($filter_date) ?>" class="form-control" style="max-width:160px;">
            <button type="submit" class="btn-action">Filter</button>
            <?php if ($filter_date !== ''): ?>
                <a class="btn-action btn-outline" href="./admin.php?id=settlement-audit">Reset</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body-modern">
        <?php if ($stats_db_error !== ''): ?>
            <div class="admin-empty" style="color:#fca5a5;">DB error: <?= htmlspecialchars($stats_db_error); ?></div>
        <?php elseif (empty($rows)): ?>
            <div class="admin-empty">Belum ada log settlement.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table-dark-solid text-nowrap">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Waktu</th>
                            <th>Aksi</th>
                            <th>Hasil</th>
                            <th>Actor</th>
                            <th>Role</th>
                            <th>IP</th>
                            <th>Pesan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php
                                $created = (string)($r['created_at'] ?? '');
                                $time = $created !== '' ? date('H:i:s', strtotime($created)) : '-';
                                $date = (string)($r['report_date'] ?? '-');
                                $action = (string)($r['action'] ?? '-');
                                $result = (string)($r['result'] ?? '-');
                                $actor = (string)($r['actor'] ?? '-');
                                $role = (string)($r['role'] ?? '-');
                                $ip = (string)($r['ip_address'] ?? '-');
                                $msg = (string)($r['message'] ?? '');
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($date); ?></td>
                                <td><?= htmlspecialchars($time); ?></td>
                                <td><?= htmlspecialchars($action); ?></td>
                                <td><?= htmlspecialchars($result); ?></td>
                                <td><?= htmlspecialchars($actor); ?></td>
                                <td><?= htmlspecialchars($role); ?></td>
                                <td><?= htmlspecialchars($ip); ?></td>
                                <td><?= htmlspecialchars($msg); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
