<?php
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

$session_id = $_GET['session'] ?? '';
$session_qs = $session_id !== '' ? '&session=' . urlencode($session_id) : '';
$dbFile = dirname(__DIR__, 2) . '/db_data/mikhmon_stats.db';
$db = null;
$db_error = '';
$form_error = '';
$form_success = '';
$edit_row = null;

try {
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
        $target_raw = sanitize_wa_target($_POST['wa_target'] ?? '');
        $err = '';
        $validated_target = validate_wa_target($target_raw, $target_type, $err);
        if ($validated_target === false) {
            $form_error = $err;
        } else {
            try {
                $now = date('Y-m-d H:i:s');
                if ($id > 0) {
                    $stmt = $db->prepare("UPDATE whatsapp_recipients SET label=:label, target=:target, target_type=:type, active=:active, updated_at=:updated WHERE id=:id");
                    $stmt->execute([
                        ':label' => $label,
                        ':target' => $validated_target,
                        ':type' => $target_type,
                        ':active' => $active,
                        ':updated' => $now,
                        ':id' => $id
                    ]);
                    $form_success = 'Data penerima diperbarui.';
                } else {
                    $stmt = $db->prepare("INSERT INTO whatsapp_recipients (label, target, target_type, active, created_at, updated_at) VALUES (:label,:target,:type,:active,:created,:updated)");
                    $stmt->execute([
                        ':label' => $label,
                        ':target' => $validated_target,
                        ':type' => $target_type,
                        ':active' => $active,
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

    $stmt = $db->query("SELECT * FROM whatsapp_logs ORDER BY id DESC LIMIT 50");
    $logs = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

$pdf_dir = dirname(__DIR__) . '/pdf';
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

<style>
    .wa-grid { display: grid; grid-template-columns: 1.1fr 1fr; gap: 16px; }
    .wa-card { background: #2b3138; border: 1px solid #3b424a; border-radius: 6px; padding: 16px; color: #e6edf3; }
    .wa-card h4 { margin: 0 0 12px; font-size: 14px; font-weight: 700; color: #f2f6f9; }
    .wa-form-row { display: grid; grid-template-columns: 140px 1fr; gap: 10px; align-items: center; margin-bottom: 10px; }
    .wa-form-row label { color: #cbd5db; }
    .wa-form-row input, .wa-form-row select {
        width: 100%; padding: 8px 10px; border: 1px solid #4b535c; border-radius: 4px;
        background: #1f242a; color: #e6edf3;
    }
    .wa-form-row input::placeholder { color: #7f8a93; }
    .wa-actions { display: flex; gap: 8px; margin-top: 12px; }
    .wa-badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 11px; }
    .wa-badge.on { background: rgba(46,204,113,0.15); color: #2ecc71; border: 1px solid rgba(46,204,113,0.4); }
    .wa-badge.off { background: rgba(231,76,60,0.12); color: #e74c3c; border: 1px solid rgba(231,76,60,0.35); }
    .wa-table { width: 100%; border-collapse: collapse; font-size: 12.5px; color: #d9e1e7; }
    .wa-table th, .wa-table td { border-bottom: 1px solid #3a4149; padding: 8px; text-align: left; }
    .wa-table th { font-weight: 700; background: #242a30; color: #f0f4f7; }
    .wa-empty { padding: 12px; color: #9aa6af; font-size: 12px; }
    .wa-help { font-size: 12px; color: #9aa6af; margin-top: 6px; }
    .wa-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55); align-items: center; justify-content: center; z-index: 9999; }
    .wa-modal.show { display: flex; }
    .wa-modal-card { background: #2b3138; padding: 18px; border-radius: 8px; width: 320px; color: #e6edf3; border: 1px solid #3b424a; }
    .wa-modal-card h5 { margin: 0 0 10px; }
    .wa-modal-actions { display: flex; gap: 8px; justify-content: flex-end; }
    @media (max-width: 980px) { .wa-grid { grid-template-columns: 1fr; } }
</style>

<div class="row">
    <div class="col-12">
        <div class="box box-solid">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-whatsapp"></i> WhatsApp Laporan</h3>
            </div>
            <div class="box-body">
                <?php if ($db_error !== ''): ?>
                    <div class="alert alert-danger">Database error: <?= htmlspecialchars($db_error); ?></div>
                <?php endif; ?>
                <?php if ($form_error !== ''): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($form_error); ?></div>
                <?php endif; ?>
                <?php if ($form_success !== ''): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($form_success); ?></div>
                <?php endif; ?>

                <div class="wa-grid">
                    <div class="wa-card">
                        <h4>Tambah / Edit Penerima</h4>
                        <form method="post" action="./?report=whatsapp<?= $session_qs; ?>">
                            <input type="hidden" name="wa_action" value="save">
                            <input type="hidden" name="wa_id" value="<?= htmlspecialchars($edit_row['id'] ?? ''); ?>">
                            <div class="wa-form-row">
                                <label>Label</label>
                                <input type="text" name="wa_label" value="<?= htmlspecialchars($edit_row['label'] ?? ''); ?>" placeholder="Contoh: Owner / Admin">
                            </div>
                            <div class="wa-form-row">
                                <label>Target</label>
                                <input type="text" name="wa_target" value="<?= htmlspecialchars($edit_row['target'] ?? ''); ?>" placeholder="62xxxxxxxxxx atau 123456@g.us">
                            </div>
                            <div class="wa-form-row">
                                <label>Tipe</label>
                                <select name="wa_type">
                                    <?php $cur_type = $edit_row['target_type'] ?? 'number'; ?>
                                    <option value="number" <?= $cur_type === 'number' ? 'selected' : ''; ?>>Nomor</option>
                                    <option value="group" <?= $cur_type === 'group' ? 'selected' : ''; ?>>Group</option>
                                </select>
                            </div>
                            <div class="wa-form-row">
                                <label>Aktif</label>
                                <div>
                                    <label style="font-weight:normal;">
                                        <input type="checkbox" name="wa_active" value="1" <?= ($edit_row && (int)$edit_row['active'] === 0) ? '' : 'checked'; ?>> Kirim laporan
                                    </label>
                                </div>
                            </div>
                            <div class="wa-actions">
                                <button type="submit" class="btn btn-primary">Simpan</button>
                                <?php if ($edit_row): ?>
                                    <a class="btn btn-default" href="./?report=whatsapp<?= $session_qs; ?>">Batal</a>
                                <?php endif; ?>
                            </div>
                            <div class="wa-help">Format nomor wajib 62xxx. Group ID harus diakhiri <strong>@g.us</strong>.</div>
                        </form>
                    </div>

                    <div class="wa-card">
                        <h4>File PDF Laporan</h4>
                        <?php if (empty($pdf_files)): ?>
                            <div class="wa-empty">Belum ada file PDF di folder report/pdf.</div>
                        <?php else: ?>
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
                        <?php endif; ?>
                        <div class="wa-help">File PDF akan dipakai sebagai attachment saat pengiriman WhatsApp.</div>
                    </div>
                </div>

                <div class="wa-card" style="margin-top:16px;">
                    <h4>Daftar Penerima</h4>
                    <?php if (empty($recipients)): ?>
                        <div class="wa-empty">Belum ada penerima terdaftar.</div>
                    <?php else: ?>
                        <table class="wa-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Label</th>
                                    <th>Target</th>
                                    <th>Tipe</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recipients as $i => $r): ?>
                                    <tr>
                                        <td><?= $i + 1; ?></td>
                                        <td><?= htmlspecialchars($r['label'] ?? '-'); ?></td>
                                        <td><?= htmlspecialchars($r['target'] ?? '-'); ?></td>
                                        <td><?= $r['target_type'] === 'group' ? 'Group' : 'Nomor'; ?></td>
                                        <td>
                                            <?php if ((int)$r['active'] === 1): ?>
                                                <span class="wa-badge on">Aktif</span>
                                            <?php else: ?>
                                                <span class="wa-badge off">Nonaktif</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a class="btn btn-xs btn-default" href="./?report=whatsapp<?= $session_qs; ?>&edit=<?= (int)$r['id']; ?>">Edit</a>
                                            <button class="btn btn-xs btn-danger" type="button" onclick="openDeleteModal(<?= (int)$r['id']; ?>,'<?= htmlspecialchars(addslashes($r['label'] ?? $r['target'])); ?>')">Hapus</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="wa-card" style="margin-top:16px;">
                    <h4>Log Pengiriman (Terbaru)</h4>
                    <?php if (empty($logs)): ?>
                        <div class="wa-empty">Belum ada log pengiriman.</div>
                    <?php else: ?>
                        <table class="wa-table">
                            <thead>
                                <tr>
                                    <th>Waktu</th>
                                    <th>Target</th>
                                    <th>Status</th>
                                    <th>PDF</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($log['created_at'] ?? '-'); ?></td>
                                        <td><?= htmlspecialchars($log['target'] ?? '-'); ?></td>
                                        <td><?= htmlspecialchars($log['status'] ?? '-'); ?></td>
                                        <td><?= htmlspecialchars($log['pdf_file'] ?? '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div style="margin-top:12px; color:#777; font-size:12px;">
                    Session aktif: <?= htmlspecialchars($session_id); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="wa-modal" id="waDeleteModal">
    <div class="wa-modal-card">
        <h5>Hapus Penerima</h5>
        <p id="waDeleteText">Yakin ingin menghapus?</p>
        <form method="post" action="./?report=whatsapp<?= $session_qs; ?>" style="margin-top:12px;">
            <input type="hidden" name="wa_action" value="delete">
            <input type="hidden" name="wa_id" id="waDeleteId" value="">
            <div class="wa-modal-actions">
                <button type="button" class="btn btn-default" onclick="closeDeleteModal()">Batal</button>
                <button type="submit" class="btn btn-danger">Hapus</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openDeleteModal(id, label) {
        var modal = document.getElementById('waDeleteModal');
        var text = document.getElementById('waDeleteText');
        var input = document.getElementById('waDeleteId');
        if (text) text.textContent = 'Hapus penerima: ' + label + '?';
        if (input) input.value = id;
        if (modal) modal.classList.add('show');
    }
    function closeDeleteModal() {
        var modal = document.getElementById('waDeleteModal');
        if (modal) modal.classList.remove('show');
    }
</script>
