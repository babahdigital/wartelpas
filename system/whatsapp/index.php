<?php
ob_start(); // TANGKAP SEMUA OUTPUT LIAR (SPASI/HTML DARI FILE INDUK)
session_start();
error_reporting(0); // Matikan error display agar tidak merusak JSON, cek error log server jika perlu.

// --- 1. CONFIG & DB CONNECTION ---
$root_dir = dirname(__DIR__, 2);
$config = include __DIR__ . '/config.php';
$dbFile = $config['db_file'] ?? $root_dir . '/db_data/mikhmon_stats.db';
$pdf_dir = $config['pdf_dir'] ?? ($root_dir . '/report/pdf');
$log_limit = (int)($config['log_limit'] ?? 50);
$timezone = $config['timezone'] ?? '';
$wa_cfg = $config['wa'] ?? [];

$db = null;
$db_error = '';

try {
    if ($timezone !== '') {
        date_default_timezone_set($timezone);
    }
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA busy_timeout=5000;");

    // Init Tables
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
    
    // Check columns update
    $cols = $db->query("PRAGMA table_info(whatsapp_recipients)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $col_names = array_map(function($c){ return $c['name'] ?? ''; }, $cols);
    if (!in_array('receive_retur', $col_names, true)) {
        $db->exec("ALTER TABLE whatsapp_recipients ADD COLUMN receive_retur INTEGER NOT NULL DEFAULT 1");
    }
    if (!in_array('receive_report', $col_names, true)) {
        $db->exec("ALTER TABLE whatsapp_recipients ADD COLUMN receive_report INTEGER NOT NULL DEFAULT 1");
    }

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

// --- 2. HELPER FUNCTIONS ---

// Fungsi Kritis: Membersihkan buffer sebelum kirim JSON
function json_output_and_exit($data) {
    ob_end_clean(); // Hapus semua output HTML/Spasi sebelumnya
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
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
            $error = 'Panjang nomor tidak valid (10-16 digit).';
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

function wa_validate_number($target, $countryCode, $token, &$error) {
    if ($token === '') {
        $error = 'Token WhatsApp belum diisi di Config.';
        return false;
    }
    if (!function_exists('curl_init')) {
        $error = 'Ekstensi PHP cURL tidak aktif di server.';
        return false;
    }
    $endpoint = 'https://api.fonnte.com/validate';
    $postFields = [
        'target' => (string)$target,
        'countryCode' => (string)$countryCode
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: ' . $token
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $err !== '' || $code >= 400) {
        $error = $err !== '' ? $err : ('HTTP Error ' . $code);
        return false;
    }
    
    // Cek apakah response valid JSON
    $json = json_decode($resp, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $error = 'Respon server WA bukan JSON valid.';
        return false;
    }

    if (!is_array($json) || !array_key_exists('status', $json)) {
        $error = 'Respon server WA tidak dikenali.';
        return false;
    }
    // API Fonnte kadang return status false tapi detailnya ada
    if (empty($json['status'])) {
        $error = isset($json['detail']) ? (string)$json['detail'] : 'Validasi gagal (Status False).';
        return false;
    }
    
    $registered = $json['registered'] ?? [];
    if (is_array($registered) && in_array((string)$target, $registered, true)) {
        return true;
    }
    
    // Jika masuk not_registered atau tidak ada di registered
    $error = 'Nomor tidak terdaftar di WhatsApp.';
    return false;
}

// --- 3. AJAX HANDLER (API) ---
// Logika ini dipindah ke ATAS agar tidak terkena output HTML Dashboard
if (isset($_GET['wa_action']) && $_GET['wa_action'] === 'validate' && isset($_GET['ajax'])) {
    
    // Cek Sesi (Strict JSON response)
    if (!isset($_SESSION["mikhmon"])) {
        json_output_and_exit(['ok' => false, 'message' => 'Sesi habis. Silakan login ulang.']);
    }

    $target_raw = sanitize_wa_target($_GET['target'] ?? '');
    $type = $_GET['type'] ?? 'number';
    $err = '';
    
    // Validasi Format Lokal
    $validated_target = validate_wa_target($target_raw, $type, $err);
    if ($validated_target === false) {
        json_output_and_exit(['ok' => false, 'message' => $err]);
    }

    // Jika Group, langsung OK (tidak bisa validasi ke server WA via API ini biasanya)
    if ($type === 'group') {
        json_output_and_exit(['ok' => true, 'message' => 'Format Group ID valid.']);
    }

    // Validasi ke Server WA (Fonnte)
    $token = trim((string)($wa_cfg['token'] ?? ''));
    $country = trim((string)($wa_cfg['country_code'] ?? '62'));
    $err = '';
    
    $ok = wa_validate_number($validated_target, $country, $token, $err);
    if ($ok) {
        json_output_and_exit(['ok' => true, 'message' => 'Nomor WhatsApp aktif & terdaftar.']);
    } else {
        json_output_and_exit(['ok' => false, 'message' => $err]);
    }
}

// --- 4. HTML PAGE HANDLER (DASHBOARD) ---

// Cek Sesi Halaman Biasa (Redirect)
if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

$form_error = '';
$form_success = '';
$edit_row = null;

// Handle Form POST (Save/Delete)
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
                    $form_success = 'Data penerima berhasil diperbarui.';
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
                    $form_success = 'Data penerima berhasil ditambahkan.';
                }
            } catch (Exception $e) {
                // Tangkap error duplicate entry
                if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
                    $form_error = 'Gagal: Target nomor/group ini sudah terdaftar.';
                } else {
                    $form_error = 'Gagal menyimpan: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = isset($_POST['wa_id']) ? (int)$_POST['wa_id'] : 0;
        if ($id > 0) {
            try {
                $stmt = $db->prepare("DELETE FROM whatsapp_recipients WHERE id=:id");
                $stmt->execute([':id' => $id]);
                $form_success = 'Data penerima berhasil dihapus.';
            } catch (Exception $e) {
                $form_error = 'Gagal menghapus: ' . $e->getMessage();
            }
        }
    }
}

// Load Data for Edit
if ($db instanceof PDO && isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    if ($edit_id > 0) {
        $stmt = $db->prepare("SELECT * FROM whatsapp_recipients WHERE id=:id");
        $stmt->execute([':id' => $edit_id]);
        $edit_row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

// Load Lists
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

// Scan PDF Dir
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

$session_id = $_GET['session'] ?? '';
$session_qs = $session_id !== '' ? '&session=' . urlencode($session_id) : '';

// --- 5. RENDER HTML VIEW ---
// Mulai output HTML dari sini ke bawah.
ob_flush(); // Keluarkan buffer yang aman
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
                    <div class="wa-alert wa-alert-danger"><i class="fa fa-times-circle"></i> DB Error: <?= htmlspecialchars($db_error); ?></div>
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
                            <h4><?= $edit_row ? 'Edit Penerima' : 'Tambah Penerima Baru'; ?></h4>
                        </div>
                        <form method="post" action="./?report=whatsapp<?= $session_qs; ?>">
                            <input type="hidden" name="wa_action" value="save">
                            <input type="hidden" name="wa_id" value="<?= htmlspecialchars($edit_row['id'] ?? ''); ?>">
                            <div class="wa-form-group">
                                <label class="wa-form-label">Label / Nama</label>
                                <input class="wa-form-input" type="text" name="wa_label" value="<?= htmlspecialchars($edit_row['label'] ?? ''); ?>" placeholder="Contoh: Owner / Admin" required>
                            </div>
                            <div class="wa-form-group">
                                <label class="wa-form-label">Target (Nomor/Group)</label>
                                <input class="wa-form-input" type="text" name="wa_target" value="<?= htmlspecialchars($edit_row['target'] ?? ''); ?>" placeholder="62xxxxxxxxxx atau 123456@g.us" autocomplete="off">
                                <div class="wa-help wa-validate-msg" id="waValidateMsg"></div>
                            </div>
                            <div class="wa-form-group">
                                <label class="wa-form-label">Tipe Target</label>
                                <select class="wa-form-select" name="wa_type">
                                    <?php $cur_type = $edit_row['target_type'] ?? 'number'; ?>
                                    <option value="number" <?= $cur_type === 'number' ? 'selected' : ''; ?>>Nomor Personal</option>
                                    <option value="group" <?= $cur_type === 'group' ? 'selected' : ''; ?>>Group WhatsApp</option>
                                </select>
                            </div>
                            <div class="wa-form-group">
                                <label class="wa-checkbox">
                                    <input type="checkbox" name="wa_active" value="1" <?= ($edit_row && (int)$edit_row['active'] === 0) ? '' : 'checked'; ?>>
                                    Status Aktif
                                </label>
                            </div>
                            <div class="wa-form-group" style="border-top: 1px solid #3b424a; padding-top:10px;">
                                <label class="wa-form-label" style="margin-bottom:8px;">Langganan Notifikasi:</label>
                                <label class="wa-checkbox">
                                    <input type="checkbox" name="wa_receive_retur" value="1" <?= ($edit_row && (int)($edit_row['receive_retur'] ?? 1) === 0) ? '' : 'checked'; ?>>
                                    Retur / Refund
                                </label>
                                <label class="wa-checkbox" style="margin-top:6px;">
                                    <input type="checkbox" name="wa_receive_report" value="1" <?= ($edit_row && (int)($edit_row['receive_report'] ?? 1) === 0) ? '' : 'checked'; ?>>
                                    Laporan Harian (PDF)
                                </label>
                            </div>
                            <div class="wa-btn-group">
                                <button type="submit" class="wa-btn wa-btn-primary" id="waSaveBtn"><i class="fa fa-save"></i> Simpan Data</button>
                                <?php if ($edit_row): ?>
                                    <a class="wa-btn wa-btn-default" href="./?report=whatsapp<?= $session_qs; ?>"><i class="fa fa-times"></i> Batal</a>
                                <?php endif; ?>
                            </div>
                            <div class="wa-help"><i class="fa fa-info-circle"></i> Gunakan kode negara 62. Contoh: 62812345678.</div>
                        </form>
                    </div>

                    <div class="wa-card">
                        <div class="wa-card-header">
                            <i class="fa fa-file-pdf-o"></i>
                            <h4>File Laporan Tersedia</h4>
                        </div>
                        <?php if (empty($pdf_files)): ?>
                            <div class="wa-empty">Belum ada file PDF di folder report/pdf.</div>
                        <?php else: ?>
                            <div class="wa-table-container">
                                <table class="wa-table">
                                    <thead>
                                        <tr>
                                            <th>Nama File</th>
                                            <th>Ukuran</th>
                                            <th>Tanggal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pdf_files as $pf): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($pf['name']); ?></td>
                                                <td><?= number_format($pf['size'] / 1024, 1, ',', '.') ?> KB</td>
                                                <td><?= date('d/m/Y H:i', $pf['mtime']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                        <div class="wa-help"><i class="fa fa-paperclip"></i> File PDF terbaru otomatis dikirim saat cronjob berjalan.</div>
                    </div>
                </div>

                <div class="wa-card" style="margin-top:20px;">
                    <div class="wa-card-header">
                        <i class="fa fa-users"></i>
                        <h4>Daftar Penerima</h4>
                        <span class="wa-badge" style="background: var(--wa-primary); margin-left: auto; color:#fff;">
                            Total: <?= count($recipients); ?>
                        </span>
                    </div>
                    <?php if (empty($recipients)): ?>
                        <div class="wa-empty">
                            <i class="fa fa-user-plus" style="font-size: 24px; margin-bottom: 10px;"></i><br>
                            Belum ada penerima. Silakan tambah data baru.
                        </div>
                    <?php else: ?>
                        <div class="wa-table-container">
                            <table class="wa-table">
                                <thead>
                                    <tr>
                                        <th width="40">#</th>
                                        <th>Label</th>
                                        <th>Target</th>
                                        <th>Tipe</th>
                                        <th>Status</th>
                                        <th>Notifikasi</th>
                                        <th width="100">Aksi</th>
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
                                                    <span class="wa-badge" style="background: #3498db; color:#fff;">Group</span>
                                                <?php else: ?>
                                                    <span class="wa-badge" style="background: #9b59b6; color:#fff;">Nomor</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ((int)$r['active'] === 1): ?>
                                                    <span class="wa-badge on">Aktif</span>
                                                <?php else: ?>
                                                    <span class="wa-badge off">Nonaktif</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="display:flex; gap:4px; flex-wrap:wrap;">
                                                <?php if ((int)($r['receive_retur'] ?? 1) === 1): ?>
                                                    <span class="wa-badge" style="background:#2563eb; color:#fff; font-size:10px;">Retur</span>
                                                <?php endif; ?>
                                                <?php if ((int)($r['receive_report'] ?? 1) === 1): ?>
                                                    <span class="wa-badge" style="background:#16a34a; color:#fff; font-size:10px;">Laporan</span>
                                                <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <a class="wa-btn wa-btn-default wa-btn-sm" href="./?report=whatsapp<?= $session_qs; ?>&edit=<?= (int)$r['id']; ?>" title="Edit">
                                                        <i class="fa fa-pencil"></i>
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
                        <h4>Log Pengiriman Terakhir</h4>
                        <span class="wa-badge" style="background: var(--wa-warning); margin-left: auto; color:#fff;">
                            <?= count($logs); ?>
                        </span>
                    </div>
                    <?php if (empty($logs)): ?>
                        <div class="wa-empty">
                            Belum ada riwayat pengiriman.
                        </div>
                    <?php else: ?>
                        <div class="wa-table-container">
                            <table class="wa-table">
                                <thead>
                                    <tr>
                                        <th>Waktu</th>
                                        <th>Tujuan</th>
                                        <th>Status</th>
                                        <th>Lampiran</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($logs as $log): ?>
                                        <tr>
                                            <td><small><?= htmlspecialchars($log['created_at'] ?? '-'); ?></small></td>
                                            <td><?= htmlspecialchars($log['target'] ?? '-'); ?></td>
                                            <td>
                                                <?php
                                                    $status = $log['status'] ?? '';
                                                    $statusClass = (stripos($status, 'success') !== false || stripos($status, 'sent') !== false) ? 'on' : 'off';
                                                ?>
                                                <span class="wa-badge <?= $statusClass; ?>"><?= htmlspecialchars($status ?: '-'); ?></span>
                                            </td>
                                            <td>
                                                <?php if (!empty($log['pdf_file'])): ?>
                                                    <i class="fa fa-file-pdf-o"></i> <?= htmlspecialchars($log['pdf_file']); ?>
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
                    Session ID: <?= htmlspecialchars($session_id); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="wa-modal" id="waDeleteModal">
    <div class="wa-modal-card">
        <div class="wa-modal-header">
            <h5><i class="fa fa-trash" style="color: var(--wa-danger);"></i> Hapus Data</h5>
        </div>
        <div class="wa-modal-body">
            <p id="waDeleteText">Yakin ingin menghapus?</p>
            <small>Tindakan ini tidak dapat dibatalkan.</small>
        </div>
        <form method="post" action="./?report=whatsapp<?= $session_qs; ?>">
            <input type="hidden" name="wa_action" value="delete">
            <input type="hidden" name="wa_id" id="waDeleteId" value="">
            <div class="wa-modal-footer">
                <button type="button" class="wa-btn wa-btn-default" onclick="closeDeleteModal()">
                    Batal
                </button>
                <button type="submit" class="wa-btn wa-btn-danger">
                    Ya, Hapus
                </button>
            </div>
        </form>
    </div>
</div>

<script src="./system/whatsapp/whatsapp.js"></script>