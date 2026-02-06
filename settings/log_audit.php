<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
@date_default_timezone_set('Asia/Makassar');
require_once __DIR__ . '/../include/acl.php';
require_once __DIR__ . '/../include/db.php';
requireLogin('../admin.php?id=login');
if (!isSuperAdmin()) {
    echo '<div class="admin-empty">Akses ditolak. Log audit hanya untuk Superadmin.</div>';
    return;
}

$rows = [];
$db_error = '';

$filter_date = trim((string)($_GET['date'] ?? ''));
if ($filter_date === '') {
    $filter_date = date('Y-m-d');
}
if ($filter_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date)) {
    $filter_date = '';
}
$filter_action = trim((string)($_GET['action'] ?? ''));
$filter_actor = trim((string)($_GET['actor'] ?? ''));
$filter_result = trim((string)($_GET['result'] ?? ''));
$filter_q = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 200);
if ($limit < 1) $limit = 200;
if ($limit > 500) $limit = 500;

try {
    $pdo = app_db();
    $sql = "SELECT * FROM audit_actions WHERE 1=1";
    $params = [];
    if ($filter_date !== '') {
        $sql .= " AND created_at >= :d1 AND created_at <= :d2";
        $params[':d1'] = $filter_date . ' 00:00:00';
        $params[':d2'] = $filter_date . ' 23:59:59';
    }
    if ($filter_action !== '') {
        $sql .= " AND action = :action";
        $params[':action'] = $filter_action;
    }
    if ($filter_actor !== '') {
        $sql .= " AND actor LIKE :actor";
        $params[':actor'] = '%' . $filter_actor . '%';
    }
    if ($filter_result !== '') {
        $sql .= " AND result = :result";
        $params[':result'] = $filter_result;
    }
    if ($filter_q !== '') {
        $sql .= " AND (target LIKE :q OR message LIKE :q OR context LIKE :q)";
        $params[':q'] = '%' . $filter_q . '%';
    }
    $sql .= " ORDER BY created_at DESC LIMIT :lim";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $db_error = $e->getMessage();
}

function audit_read_log_tail($file, $maxLines = 500, $maxBytes = 200000)
{
    if (!is_file($file) || !is_readable($file)) return [];
    $size = @filesize($file);
    if ($size === false || $size <= 0) return [];
    $data = '';
    $fh = @fopen($file, 'rb');
    if ($fh === false) return [];
    if ($size > $maxBytes) {
        @fseek($fh, -$maxBytes, SEEK_END);
    }
    $data = @stream_get_contents($fh);
    @fclose($fh);
    if ($data === false || $data === '') return [];
    $lines = preg_split('/\r\n|\r|\n/', $data);
    if (!is_array($lines)) return [];
    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, -$maxLines);
    }
    $lines = array_values(array_filter(array_map('trim', $lines), function ($l) {
        return $l !== '';
    }));
    return $lines;
}

function audit_system_parse_time($raw)
{
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $raw, $m)) {
        return $m[1];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $raw)) {
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d H:i:s', $ts) : '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw)) {
        return $raw;
    }
    return '';
}

function audit_system_matches_filters($row, $filter_action, $filter_actor, $filter_result, $filter_q)
{
    if ($filter_action !== '' && (string)$row['action'] !== $filter_action) return false;
    if ($filter_actor !== '' && stripos((string)$row['actor'], $filter_actor) === false) return false;
    if ($filter_result !== '' && (string)$row['result'] !== $filter_result) return false;
    if ($filter_q !== '') {
        $hay = (string)$row['target'] . ' ' . (string)$row['message'] . ' ' . (string)$row['context'];
        if (stripos($hay, $filter_q) === false) return false;
    }
    return true;
}

function audit_collect_system_rows($filter_date, $filter_action, $filter_actor, $filter_result, $filter_q)
{
    $rows = [];
    $root_dir = dirname(__DIR__);
    $env = [];
    $envFile = $root_dir . '/include/env.php';
    if (file_exists($envFile)) {
        require $envFile;
    }
    $system_cfg = $env['system'] ?? [];
    $log_rel = $system_cfg['log_dir'] ?? 'logs';
    $logDir = preg_match('/^[A-Za-z]:\\\\|^\//', $log_rel) ? $log_rel : ($root_dir . '/' . trim($log_rel, '/'));

    $addRow = function ($created, $action, $target, $result, $message, $ip = '-') use (&$rows, $filter_date, $filter_action, $filter_actor, $filter_result, $filter_q) {
        if ($created === '') return;
        if ($filter_date !== '' && strpos($created, $filter_date) !== 0) return;
        $row = [
            'created_at' => $created,
            'action' => $action,
            'target' => $target,
            'result' => $result,
            'actor' => 'SYSTEM',
            'role' => 'system',
            'ip_address' => $ip,
            'message' => $message,
            'context' => ''
        ];
        if (audit_system_matches_filters($row, $filter_action, $filter_actor, $filter_result, $filter_q)) {
            $rows[] = $row;
        }
    };

    return $rows;
}

function audit_action_label($action)
{
    $action = (string)$action;
    $map = [
        'operator_profile_update' => 'Profil Operator',
        'password_change_operator' => 'Pass Operator',
        'password_change_admin' => 'Pass Admin',
        'operator_permissions_update' => 'Izin Operator',
        'admin_create' => 'Admin Baru',
        'admin_update' => 'Admin Update',
        'admin_delete' => 'Admin Hapus',
        'admin_reset_password' => 'Reset Admin',
        'operator_create' => 'Operator Baru',
        'operator_update' => 'Operator Update',
        'operator_delete' => 'Operator Hapus',
        'operator_reset_password' => 'Reset Operator',
        'retur_request_reject' => 'Retur Ditolak',
        'retur_request_mark_rusak' => 'Refund Rusak',
        'retur_request_approve' => 'Retur Disetujui',
        'retur_request_reopen' => 'Retur Dibuka Kembali',
        'retur' => 'Retur',
        'invalid' => 'Rusak',
        'rollback' => 'Rollback',
        'delete' => 'Hapus',
        'delete_user_full' => 'Hapus Total',
        'delete_block_full' => 'Hapus Blok',
        'batch_delete' => 'Hapus Blok (Rtr)',
        'delete_status' => 'Hapus Status',
        'vip' => 'Set VIP',
        'unvip' => 'Unset VIP',
        'disable' => 'Disable',
        'todo_ack' => 'Todo OK',
        'audit_manual_save' => 'Audit Manual',
        'backup_db' => 'Backup DB',
        'restore_db' => 'Restore DB',
        'restore_app_db' => 'Restore DB App',
        'maintenance_toggle' => 'Maintenance',
        'settlement_reset' => 'Reset Settlement',
        'settlement_force' => 'Force Settlement',
        'hp_save' => 'HP Laporan',
        'generate_user' => 'Generate Voucher',
        'wa_recipient_add' => 'WA Penerima +',
        'wa_recipient_update' => 'WA Penerima Edit',
        'wa_recipient_delete' => 'WA Penerima Hapus',
        'wa_template_add' => 'WA Template +',
        'wa_template_update' => 'WA Template Edit',
        'wa_template_delete' => 'WA Template Hapus',
        'wa_config_update' => 'WA Konfigurasi',
        'session_add' => 'Sesi +',
        'session_update' => 'Sesi Update',
        'session_delete' => 'Sesi Hapus',
        'logo_delete' => 'Logo Hapus',
    ];
    if (isset($map[$action])) return $map[$action];
    $label = trim(str_replace('_', ' ', $action));
    return $label !== '' ? ucwords($label) : '-';
}

$system_rows = audit_collect_system_rows($filter_date, $filter_action, $filter_actor, $filter_result, $filter_q);
if (!empty($system_rows)) {
    $rows = array_merge($rows, $system_rows);
    usort($rows, function ($a, $b) {
        $ta = strtotime((string)($a['created_at'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
        if ($ta === $tb) return 0;
        return $ta > $tb ? -1 : 1;
    });
    if (count($rows) > $limit) {
        $rows = array_slice($rows, 0, $limit);
    }
}
?>

<div class="card-modern" style="margin-bottom:16px;">
    <div class="card-header-modern" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <div>
            <h3 style="margin-bottom:4px;"><i class="fa fa-clipboard"></i> Log Audit Aktivitas</h3>
            <div class="text-secondary" style="font-size:12px;">Mencatat aktivitas admin/operator. Aksi akun ENV tidak dicatat.</div>
        </div>
        <form method="get" action="" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="id" value="log-audit">
            <input type="date" name="date" value="<?= htmlspecialchars($filter_date) ?>" class="form-control" style="max-width:160px;" onchange="this.form.submit()">
            <input type="text" name="action" placeholder="Aksi" value="<?= htmlspecialchars($filter_action) ?>" class="form-control" style="max-width:140px;">
            <input type="text" name="actor" placeholder="Actor" value="<?= htmlspecialchars($filter_actor) ?>" class="form-control" style="max-width:140px;">
            <select name="result" class="form-control" style="max-width:130px;">
                <option value="">Semua</option>
                <option value="success" <?= $filter_result === 'success' ? 'selected' : ''; ?>>Success</option>
                <option value="failed" <?= $filter_result === 'failed' ? 'selected' : ''; ?>>Failed</option>
            </select>
            <input type="text" name="q" placeholder="Cari target/pesan" value="<?= htmlspecialchars($filter_q) ?>" class="form-control" style="max-width:180px;">
            <input type="number" name="limit" min="1" max="500" value="<?= (int)$limit ?>" class="form-control" style="max-width:90px;">
            <button type="submit" class="btn-action">Filter</button>
            <a class="btn-action btn-outline" href="./admin.php?id=log-audit">Reset</a>
        </form>
    </div>
    <div class="card-body-modern">
        <style>
            .audit-log-table { border-collapse: collapse; width: 100%; }
            .audit-log-table th, .audit-log-table td { padding: 10px 12px; vertical-align: top; }
            .audit-log-table thead th { background: rgba(15, 23, 42, 0.35); border-bottom: 1px solid rgba(148,163,184,0.18); white-space: nowrap; }
            .audit-log-table tbody tr { border-bottom: 1px solid rgba(148,163,184,0.12); }
            .audit-log-table tbody tr:last-child { border-bottom: none; }
            .audit-log-stack { display: flex; flex-direction: column; gap: 2px; }
            .audit-log-sub { font-size: 11px; color: #8b98a7; }
            .audit-log-message, .audit-log-target { white-space: normal; word-break: break-word; }
            .audit-badge { display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; letter-spacing: 0.2px; }
            .audit-badge-green { background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.4); }
            .audit-badge-red { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.4); }
            .audit-badge-blue { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.35); }
            .audit-log-table td.col-time { width: 110px; }
            .audit-log-table td.col-result { width: 90px; }
            .audit-log-table td.col-ip { width: 110px; }
            @media (max-width: 900px) {
                .audit-log-table th, .audit-log-table td { font-size: 12px; padding: 6px 8px; }
                .audit-log-table td.col-ip { display: none; }
                .audit-log-table th.col-ip { display: none; }
            }
        </style>
        <?php if ($db_error !== ''): ?>
            <div class="admin-empty" style="color:#fca5a5;">DB error: <?= htmlspecialchars($db_error); ?></div>
        <?php elseif (empty($rows)): ?>
            <div class="admin-empty">Belum ada log audit.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table-dark-solid audit-log-table" style="min-width: 860px;">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Aksi</th>
                            <th>Target</th>
                            <th>Hasil</th>
                            <th>Actor</th>
                            <th class="col-ip">IP</th>
                            <th>Pesan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php
                                $created = (string)($r['created_at'] ?? '');
                                $time = $created !== '' ? date('H:i', strtotime($created)) : '-';
                                $date = $created !== '' ? date('d-m-Y', strtotime($created)) : '-';
                                $action = (string)($r['action'] ?? '-');
                                $action_label = audit_action_label($action);
                                $action_sub = $action_label !== $action ? $action : '';
                                $target = (string)($r['target'] ?? '-');
                                $result = (string)($r['result'] ?? '-');
                                $result_lower = strtolower($result);
                                $result_badge = strpos($result_lower, 'success') !== false ? 'audit-badge audit-badge-green' : (strpos($result_lower, 'fail') !== false ? 'audit-badge audit-badge-red' : 'audit-badge audit-badge-blue');
                                $actor = (string)($r['actor'] ?? '-');
                                $role = (string)($r['role'] ?? '-');
                                $ip = (string)($r['ip_address'] ?? '-');
                                $msg = (string)($r['message'] ?? '');
                            ?>
                            <tr>
                                <td class="col-time">
                                    <div class="audit-log-stack">
                                        <span><?= htmlspecialchars($date); ?></span>
                                        <span class="audit-log-sub"><?= htmlspecialchars($time); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="audit-log-stack">
                                        <span><?= htmlspecialchars($action_label); ?></span>
                                        <?php if ($action_sub !== ''): ?>
                                            <span class="audit-log-sub"><?= htmlspecialchars($action_sub); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="audit-log-target"><?= htmlspecialchars($target); ?></td>
                                <td class="col-result"><span class="<?= $result_badge; ?>"><?= htmlspecialchars($result); ?></span></td>
                                <td>
                                    <div class="audit-log-stack">
                                        <span><?= htmlspecialchars($actor); ?></span>
                                        <span class="audit-log-sub"><?= htmlspecialchars(strtoupper($role)); ?></span>
                                    </div>
                                </td>
                                <td class="col-ip"><?= htmlspecialchars($ip); ?></td>
                                <td class="audit-log-message" title="<?= htmlspecialchars($msg); ?>"><?= htmlspecialchars($msg); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>