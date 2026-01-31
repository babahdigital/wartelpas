<?php
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    if (isset($_GET['wa_action']) && $_GET['wa_action'] === 'validate' && isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Sesi habis. Silakan login ulang.']);
        exit;
    }
    header("Location:../admin.php?id=login");
    exit;
}

$session_id = $_GET['session'] ?? '';
$session_qs = $session_id !== '' ? '&session=' . urlencode($session_id) : '';
$config = include __DIR__ . '/config.php';
$dbFile = $config['db_file'] ?? dirname(__DIR__, 2) . '/db_data/mikhmon_stats.db';
$pdf_dir = $config['pdf_dir'] ?? (dirname(__DIR__, 2) . '/report/pdf');
$log_limit = (int)($config['log_limit'] ?? 50);
$timezone = $config['timezone'] ?? '';
$wa_cfg = $config['wa'] ?? [];
$db = null;
$db_error = '';
$form_error = '';
$form_success = '';
$edit_row = null;

try {
    if ($timezone !== '') {
        date_default_timezone_set($timezone);
    }
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA busy_timeout=5000;");

    $db->exec("CREATE TABLE IF NOT EXISTS whatsapp_recipients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        label TEXT,
        target TEXT NOT NULL,
        target_type TEXT NOT NULL DEFAULT 'number',
        active INTEGER NOT NULL DEFAULT 1,
        receive_retur INTEGER NOT NULL DEFAULT 1,
        receive_report INTEGER NOT NULL DEFAULT 1,
        created_at TEXT,
        updated_at TEXT
    )");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_whatsapp_recipients_target ON whatsapp_recipients(target)");

    $db->exec("CREATE TABLE IF NOT EXISTS whatsapp_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        target TEXT,
        message TEXT,
        pdf_file TEXT,
        status TEXT,
        response_json TEXT,
        created_at TEXT
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_whatsapp_logs_created ON whatsapp_logs(created_at)");
    $cols = $db->query("PRAGMA table_info(whatsapp_recipients)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $col_names = array_map(function($c){ return $c['name'] ?? ''; }, $cols);
    if (!in_array('receive_retur', $col_names, true)) {
        $db->exec("ALTER TABLE whatsapp_recipients ADD COLUMN receive_retur INTEGER NOT NULL DEFAULT 1");
    }
    if (!in_array('receive_report', $col_names, true)) {
        $db->exec("ALTER TABLE whatsapp_recipients ADD COLUMN receive_report INTEGER NOT NULL DEFAULT 1");
    }
} catch (Exception $e) {
    $db_error = $e->getMessage();
}

function sanitize_wa_label($label) {
    $label = trim((string)$label);
    $label = preg_replace('/\s+/', ' ', $label);
    return $label;
}

function sanitize_wa_target($target) {
    return trim((string)$target);
}

function validate_wa_target($target, $type, &$error) {
    $target = trim((string)$target);
    if ($target === '') {
        $error = 'Target wajib diisi.';
        return false;
    }
    if ($type === 'number') {
        $clean = preg_replace('/\D+/', '', $target);
        if ($clean === '') {
            $error = 'Nomor tidak valid.';
            return false;
        }
        if (strpos($clean, '0') === 0) {
            $clean = '62' . substr($clean, 1);
        }
        if (strpos($clean, '62') !== 0) {
            $error = 'Nomor harus diawali 62.';
            return false;
        }
        if (strlen($clean) < 10 || strlen($clean) > 16) {
            $error = 'Panjang nomor tidak valid.';
            return false;
        }
        return $clean;
    }
    if (!preg_match('/@g\.us$/i', $target)) {
        $error = 'Group ID harus diakhiri @g.us.';
        return false;
    }
    return $target;
}


if ($db instanceof PDO && isset($_POST['wa_action'])) {
    $action = $_POST['wa_action'];
    if ($action === 'save') {
        $id = isset($_POST['wa_id']) ? (int)$_POST['wa_id'] : 0;
        $label = sanitize_wa_label($_POST['wa_label'] ?? '');
        $target_type = $_POST['wa_type'] ?? 'number';
        $active = isset($_POST['wa_active']) ? 1 : 0;
        $receive_retur = isset($_POST['wa_receive_retur']) ? 1 : 0;
        $receive_report = isset($_POST['wa_receive_report']) ? 1 : 0;
        $target_raw = sanitize_wa_target($_POST['wa_target'] ?? '');
        $err = '';
        $validated_target = validate_wa_target($target_raw, $target_type, $err);
        if ($validated_target === false) {
            $form_error = $err;
        } else {
            try {
                $now = date('Y-m-d H:i:s');
                if ($id > 0) {
                    $stmt = $db->prepare("UPDATE whatsapp_recipients SET label=:label, target=:target, target_type=:type, active=:active, receive_retur=:rr, receive_report=:rp, updated_at=:updated WHERE id=:id");
                    $stmt->execute([
                        ':label' => $label,
                        ':target' => $validated_target,
                        ':type' => $target_type,
                        ':active' => $active,
                        ':rr' => $receive_retur,
                        ':rp' => $receive_report,
                        ':updated' => $now,
                        ':id' => $id
                    ]);
                    $form_success = 'Data penerima diperbarui.';
                } else {
                    $stmt = $db->prepare("INSERT INTO whatsapp_recipients (label, target, target_type, active, receive_retur, receive_report, created_at, updated_at) VALUES (:label,:target,:type,:active,:rr,:rp,:created,:updated)");
                    $stmt->execute([
                        ':label' => $label,
                        ':target' => $validated_target,
                        ':type' => $target_type,
                        ':active' => $active,
                        ':rr' => $receive_retur,
                        ':rp' => $receive_report,
                        ':created' => $now,
                        ':updated' => $now
                    ]);
                    $form_success = 'Data penerima ditambahkan.';
                }
            } catch (Exception $e) {
                $form_error = 'Gagal menyimpan: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $id = isset($_POST['wa_id']) ? (int)$_POST['wa_id'] : 0;
        if ($id > 0) {
            try {
                $stmt = $db->prepare("DELETE FROM whatsapp_recipients WHERE id=:id");
                $stmt->execute([':id' => $id]);
                $form_success = 'Data penerima dihapus.';
            } catch (Exception $e) {
                $form_error = 'Gagal menghapus: ' . $e->getMessage();
            }
        }
    }
}

if ($db instanceof PDO && isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    if ($edit_id > 0) {
        $stmt = $db->prepare("SELECT * FROM whatsapp_recipients WHERE id=:id");
        $stmt->execute([':id' => $edit_id]);
        $edit_row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

$recipients = [];
$logs = [];
$pdf_files = [];
if ($db instanceof PDO) {
    $stmt = $db->query("SELECT * FROM whatsapp_recipients ORDER BY active DESC, label ASC, id DESC");
    $recipients = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $limit = $log_limit > 0 ? $log_limit : 50;
    $stmt = $db->query("SELECT * FROM whatsapp_logs ORDER BY id DESC LIMIT " . (int)$limit);
    $logs = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

$pdf_dir = rtrim($pdf_dir, '/');
if (is_dir($pdf_dir)) {
    $items = scandir($pdf_dir);
    foreach ($items as $file) {
        if ($file === '.' || $file === '..' || $file === 'index.php') continue;
        $path = $pdf_dir . '/' . $file;
        if (is_file($path)) {
            $pdf_files[] = [
                'name' => $file,
                'size' => filesize($path),
                'mtime' => filemtime($path)
            ];
        }
    }
}
?>

<link rel="stylesheet" href="./system/whatsapp/whatsapp.css">

<div class="row">
    <div class="col-12">
        <div class="box box-solid">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-whatsapp"></i> WhatsApp Laporan</h3>
            </div>
            <div class="box-body">
                <?php if ($db_error !== ''): ?>
                    <div class="wa-alert wa-alert-danger"><i class="fa fa-times-circle"></i> <?= htmlspecialchars($db_error); ?></div>
                <?php endif; ?>
                <?php if ($form_error !== ''): ?>
                    <div class="wa-alert wa-alert-danger"><i class="fa fa-times-circle"></i> <?= htmlspecialchars($form_error); ?></div>
                <?php endif; ?>
                <?php if ($form_success !== ''): ?>
                    <div class="wa-alert wa-alert-success"><i class="fa fa-check-circle"></i> <?= htmlspecialchars($form_success); ?></div>
                <?php endif; ?>

                <div class="wa-grid">
                    <div class="wa-card">
                        <div class="wa-card-header">
                            <i class="fa fa-address-book"></i>
                            <h4>Tambah / Edit Penerima</h4>
                        </div>
                        <form method="post" action="./?report=whatsapp<?= $session_qs; ?>">
                            <input type="hidden" name="wa_action" value="save">
                            <input type="hidden" name="wa_id" value="<?= htmlspecialchars($edit_row['id'] ?? ''); ?>">
                            <div class="wa-form-group">
                                <label class="wa-form-label">Label</label>
                                <input class="wa-form-input" type="text" name="wa_label" value="<?= htmlspecialchars($edit_row['label'] ?? ''); ?>" placeholder="Contoh: Owner / Admin">
                            </div>
                            <div class="wa-form-group">
                                <label class="wa-form-label">Target</label>
                                <input class="wa-form-input" type="text" name="wa_target" value="<?= htmlspecialchars($edit_row['target'] ?? ''); ?>" placeholder="62xxxxxxxxxx atau 123456@g.us">
                                <div class="wa-help wa-validate-msg" id="waValidateMsg"></div>
                            </div>
                            <div class="wa-form-group">
                                <label class="wa-form-label">Tipe</label>
                                <select class="wa-form-select" name="wa_type">
                                    <?php $cur_type = $edit_row['target_type'] ?? 'number'; ?>
                                    <option value="number" <?= $cur_type === 'number' ? 'selected' : ''; ?>>Nomor</option>
                                    <option value="group" <?= $cur_type === 'group' ? 'selected' : ''; ?>>Group</option>
                                </select>
                            </div>
                            <div class="wa-form-group">
                                <label class="wa-checkbox">
                                    <input type="checkbox" name="wa_active" value="1" <?= ($edit_row && (int)$edit_row['active'] === 0) ? '' : 'checked'; ?>>
                                    Kirim laporan
                                </label>
                            </div>
                            <div class="wa-form-group">
                                <label class="wa-checkbox">
                                    <input type="checkbox" name="wa_receive_retur" value="1" <?= ($edit_row && (int)($edit_row['receive_retur'] ?? 1) === 0) ? '' : 'checked'; ?>>
                                    Notif Retur/Refund
                                </label>
                                <label class="wa-checkbox" style="margin-top:6px;">
                                    <input type="checkbox" name="wa_receive_report" value="1" <?= ($edit_row && (int)($edit_row['receive_report'] ?? 1) === 0) ? '' : 'checked'; ?>>
                                    Notif Laporan
                                </label>
                            </div>
                            <div class="wa-btn-group">
                                <button type="submit" class="wa-btn wa-btn-primary" id="waSaveBtn"><i class="fa fa-save"></i> Simpan</button>
                                <?php if ($edit_row): ?>
                                    <a class="wa-btn wa-btn-default" href="./?report=whatsapp<?= $session_qs; ?>"><i class="fa fa-times"></i> Batal</a>
                                <?php endif; ?>
                            </div>
                            <div class="wa-help"><i class="fa fa-info-circle"></i> Format nomor wajib 62xxx. Group ID harus diakhiri <strong>@g.us</strong>.</div>
                        </form>
                    </div>

                    <div class="wa-card">
                        <div class="wa-card-header">
                            <i class="fa fa-file-pdf-o"></i>
                            <h4>File PDF Laporan</h4>
                        </div>
                        <?php if (empty($pdf_files)): ?>
                            <div class="wa-empty">Belum ada file PDF di folder report/pdf.</div>
                        <?php else: ?>
                            <div class="wa-table-container">
                                <table class="wa-table">
                                    <thead>
                                        <tr>
                                            <th>File</th>
                                            <th>Ukuran</th>
                                            <th>Tanggal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pdf_files as $pf): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($pf['name']); ?></td>
                                                <td><?= number_format($pf['size'] / 1024, 1, ',', '.') ?> KB</td>
                                                <td><?= date('d-m-Y H:i', $pf['mtime']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                        <div class="wa-help"><i class="fa fa-paperclip"></i> File PDF akan dipakai sebagai attachment saat pengiriman WhatsApp.</div>
                    </div>
                </div>

                <div class="wa-card" style="margin-top:20px;">
                    <div class="wa-card-header">
                        <i class="fa fa-users"></i>
                        <h4>Daftar Penerima</h4>
                        <span class="wa-badge" style="background: var(--wa-primary); margin-left: auto; color:#fff;">
                            <?= count($recipients); ?>
                        </span>
                    </div>
                    <?php if (empty($recipients)): ?>
                        <div class="wa-empty">
                            <i class="fa fa-user-plus" style="font-size: 24px; margin-bottom: 10px;"></i><br>
                            Belum ada penerima terdaftar. Tambahkan penerima baru di form di atas.
                        </div>
                    <?php else: ?>
                        <div class="wa-table-container">
                            <table class="wa-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th><i class="fa fa-tag"></i> Label</th>
                                        <th><i class="fa fa-bullseye"></i> Target</th>
                                        <th><i class="fa fa-list"></i> Tipe</th>
                                        <th><i class="fa fa-power-off"></i> Status</th>
                                        <th><i class="fa fa-bell"></i> Notif</th>
                                        <th><i class="fa fa-cog"></i> Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recipients as $i => $r): ?>
                                        <tr>
                                            <td><?= $i + 1; ?></td>
                                            <td><strong><?= htmlspecialchars($r['label'] ?? '-'); ?></strong></td>
                                            <td><code><?= htmlspecialchars($r['target'] ?? '-'); ?></code></td>
                                            <td>
                                                <?php if ($r['target_type'] === 'group'): ?>
                                                    <span class="wa-badge" style="background: #3498db; color:#fff;">
                                                        <i class="fa fa-users"></i> Group
                                                    </span>
                                                <?php else: ?>
                                                    <span class="wa-badge" style="background: #9b59b6; color:#fff;">
                                                        <i class="fa fa-phone"></i> Nomor
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ((int)$r['active'] === 1): ?>
                                                    <span class="wa-badge on"><i class="fa fa-check-circle"></i> Aktif</span>
                                                <?php else: ?>
                                                    <span class="wa-badge off"><i class="fa fa-times-circle"></i> Nonaktif</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ((int)($r['receive_retur'] ?? 1) === 1): ?>
                                                    <span class="wa-badge" style="background:#2563eb; color:#fff;">Retur</span>
                                                <?php else: ?>
                                                    <span class="wa-badge off">Retur</span>
                                                <?php endif; ?>
                                                <?php if ((int)($r['receive_report'] ?? 1) === 1): ?>
                                                    <span class="wa-badge" style="background:#16a34a; color:#fff;">Laporan</span>
                                                <?php else: ?>
                                                    <span class="wa-badge off">Laporan</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <a class="wa-btn wa-btn-default wa-btn-sm" href="./?report=whatsapp<?= $session_qs; ?>&edit=<?= (int)$r['id']; ?>" title="Edit">
                                                        <i class="fa fa-edit"></i>
                                                    </a>
                                                    <button class="wa-btn wa-btn-danger wa-btn-sm" type="button" onclick="openDeleteModal(<?= (int)$r['id']; ?>,'<?= htmlspecialchars(addslashes($r['label'] ?? $r['target'])); ?>')" title="Hapus">
                                                        <i class="fa fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="wa-card" style="margin-top:20px;">
                    <div class="wa-card-header">
                        <i class="fa fa-history"></i>
                        <h4>Log Pengiriman (Terbaru)</h4>
                        <span class="wa-badge" style="background: var(--wa-warning); margin-left: auto; color:#fff;">
                            <?= count($logs); ?>
                        </span>
                    </div>
                    <?php if (empty($logs)): ?>
                        <div class="wa-empty">
                            <i class="fa fa-history" style="font-size: 24px; margin-bottom: 10px;"></i><br>
                            Belum ada log pengiriman.
                        </div>
                    <?php else: ?>
                        <div class="wa-table-container">
                            <table class="wa-table">
                                <thead>
                                    <tr>
                                        <th><i class="fa fa-clock-o"></i> Waktu</th>
                                        <th><i class="fa fa-bullseye"></i> Target</th>
                                        <th><i class="fa fa-info-circle"></i> Status</th>
                                        <th><i class="fa fa-file-pdf-o"></i> PDF</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($logs as $log): ?>
                                        <tr>
                                            <td><small><?= htmlspecialchars($log['created_at'] ?? '-'); ?></small></td>
                                            <td><code><?= htmlspecialchars($log['target'] ?? '-'); ?></code></td>
                                            <td>
                                                <?php
                                                    $status = $log['status'] ?? '';
                                                    $statusClass = strpos(strtolower($status), 'success') !== false ? 'on' : 'off';
                                                ?>
                                                <span class="wa-badge <?= $statusClass; ?>"><?= htmlspecialchars($status ?: '-'); ?></span>
                                            </td>
                                            <td>
                                                <?php if (!empty($log['pdf_file'])): ?>
                                                    <i class="fa fa-paperclip"></i>
                                                    <?= htmlspecialchars($log['pdf_file']); ?>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="wa-session-info">
                    <i class="fa fa-user-circle"></i>
                    Session aktif: <strong><?= htmlspecialchars($session_id); ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="wa-modal" id="waDeleteModal">
    <div class="wa-modal-card">
        <div class="wa-modal-header">
            <h5><i class="fa fa-trash" style="color: var(--wa-danger);"></i> Hapus Penerima</h5>
        </div>
        <div class="wa-modal-body">
            <p id="waDeleteText">Yakin ingin menghapus penerima ini?</p>
            <small>Data yang dihapus tidak dapat dikembalikan.</small>
        </div>
        <form method="post" action="./?report=whatsapp<?= $session_qs; ?>">
            <input type="hidden" name="wa_action" value="delete">
            <input type="hidden" name="wa_id" id="waDeleteId" value="">
            <div class="wa-modal-footer">
                <button type="button" class="wa-btn wa-btn-default" onclick="closeDeleteModal()">
                    <i class="fa fa-times"></i> Batal
                </button>
                <button type="submit" class="wa-btn wa-btn-danger">
                    <i class="fa fa-trash"></i> Hapus
                </button>
            </div>
        </form>
    </div>
</div>

<script src="./system/whatsapp/whatsapp.js"></script>