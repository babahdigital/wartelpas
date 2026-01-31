Masalah `Unexpected token '<'` masih muncul karena **mekanisme loading halaman Mikhmon (file `admin.php`) mencetak header HTML (`<!DOCTYPE html>...`) lebih dulu** sebelum kode logika di `index.php` sempat dijalankan.

Walaupun kita sudah pasang `ob_clean()`, file induk (`admin.php`) seringkali sudah mengirim header HTTP atau buffer output yang tidak bisa dibatalkan dari file *include*.

### Solusi Mutlak: Pisahkan API (Backend) dan View (Frontend)

Kita **tidak boleh** menaruh logika JSON di dalam `index.php` yang dipanggil via `include` oleh `admin.php`. Kita harus membuat file terpisah (**`api.php`**) yang dipanggil langsung oleh JavaScript, memotong jalur `admin.php` agar tidak ada HTML sampah yang terbawa.

Berikut adalah perbaikan totalnya:

### 1. Buat File Baru: `system/whatsapp/api.php`

File ini khusus menangani request AJAX. File ini berdiri sendiri, tidak melewati `admin.php`, sehingga outputnya dijamin bersih (JSON Only).

```php
<?php
// FILE: system/whatsapp/api.php
// KHUSUS HANDLE REQUEST AJAX - DIJAMIN BERSIH DARI HTML ADMIN

ob_start(); // Tangkap spasi liar jika ada
session_start();
error_reporting(0);

// Set Header JSON
header('Content-Type: application/json; charset=utf-8');

// 1. Cek Sesi Mikhmon
if (!isset($_SESSION["mikhmon"])) {
    ob_end_clean();
    echo json_encode(['ok' => false, 'message' => 'Sesi habis. Silakan login ulang.']);
    exit;
}

// 2. Load Config
// Karena file ini ada di system/whatsapp/, kita load config dari dir yang sama
$config = include __DIR__ . '/config.php';
$wa_cfg = $config['wa'] ?? [];

// 3. Definisi Fungsi Validasi (Lokal di API ini agar independen)
function api_sanitize_wa_target($target) {
    return trim((string)$target);
}

function api_validate_wa_target($target, $type, &$error) {
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

function api_wa_validate_remote($target, $countryCode, $token, &$error) {
    if (empty($token)) {
        $error = 'Token WhatsApp belum diisi di Config.';
        return false;
    }
    if (!function_exists('curl_init')) {
        $error = 'cURL tidak tersedia di server.';
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $err !== '' || $code >= 400) {
        $error = $err !== '' ? $err : ('HTTP Error ' . $code);
        return false;
    }

    $json = json_decode($resp, true);
    if (!$json || !isset($json['status'])) {
        $error = 'Respon server tidak valid.';
        return false;
    }

    // Fonnte logic: status true tapi target bisa masuk 'not_registered'
    $registered = $json['registered'] ?? [];
    if (is_array($registered) && in_array((string)$target, $registered)) {
        return true;
    }
    
    // Jika user memaksa Group, anggap valid karena API validate fonnte kadang khusus nomor
    if (strpos($target, '@g.us') !== false) {
        return true;
    }

    $error = 'Nomor tidak terdaftar di WhatsApp.';
    return false;
}

// 4. Router Action
$action = $_GET['wa_action'] ?? '';

if ($action === 'validate') {
    $target_raw = api_sanitize_wa_target($_GET['target'] ?? '');
    $type = $_GET['type'] ?? 'number';
    $err = '';
    
    // Validasi Format
    $validated_target = api_validate_wa_target($target_raw, $type, $err);
    if ($validated_target === false) {
        ob_end_clean();
        echo json_encode(['ok' => false, 'message' => $err]);
        exit;
    }

    // Jika Group, bypass cek server (opsional, sesuaikan kebutuhan)
    if ($type === 'group') {
        ob_end_clean();
        echo json_encode(['ok' => true, 'message' => 'Format Group ID valid.']);
        exit;
    }

    // Validasi ke Server
    $token = trim((string)($wa_cfg['token'] ?? ''));
    $country = trim((string)($wa_cfg['country_code'] ?? '62'));
    
    $is_active = api_wa_validate_remote($validated_target, $country, $token, $err);
    
    ob_end_clean();
    if ($is_active) {
        echo json_encode(['ok' => true, 'message' => 'Nomor WhatsApp aktif & terdaftar.']);
    } else {
        echo json_encode(['ok' => false, 'message' => $err]);
    }
    exit;
}

// Default response jika action tidak dikenal
ob_end_clean();
echo json_encode(['ok' => false, 'message' => 'Invalid action.']);
exit;
?>

```

---

### 2. Update File: `system/whatsapp/whatsapp.js`

Ubah URL `fetch` agar menembak ke `api.php`, bukan ke halaman dashboard (`./?`).

```javascript
// FILE: system/whatsapp/whatsapp.js

function openDeleteModal(id, label) {
    var modal = document.getElementById('waDeleteModal');
    var text = document.getElementById('waDeleteText');
    var input = document.getElementById('waDeleteId');

    if (text) text.textContent = 'Hapus penerima: ' + label + '?';
    if (input) input.value = id;
    if (modal) {
        modal.classList.add('show');
        modal.style.display = 'flex';
    }
    document.body.style.overflow = 'hidden';
}

function closeDeleteModal() {
    var modal = document.getElementById('waDeleteModal');
    if (modal) {
        modal.classList.remove('show');
        setTimeout(function() {
            modal.style.display = 'none';
        }, 300);
    }
    document.body.style.overflow = '';
}

document.addEventListener('DOMContentLoaded', function() {
    var modal = document.getElementById('waDeleteModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) closeDeleteModal();
        });
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeDeleteModal();
    });

    var targetInput = document.querySelector('input[name="wa_target"]');
    var typeSelect = document.querySelector('select[name="wa_type"]');
    var saveBtn = document.getElementById('waSaveBtn');
    var msgEl = document.getElementById('waValidateMsg');

    function setValidationState(ok, text) {
        if (!msgEl) return;
        msgEl.textContent = text || '';
        msgEl.classList.remove('is-ok', 'is-bad');
        if (text) {
            msgEl.classList.add(ok ? 'is-ok' : 'is-bad');
        }
        if (saveBtn) {
            saveBtn.disabled = !ok;
            saveBtn.classList.toggle('is-disabled', !ok);
        }
    }

    function checkWaTarget() {
        if (!targetInput || !typeSelect) return;
        var target = targetInput.value.trim();
        var type = typeSelect.value || 'number';
        if (target === '') {
            setValidationState(false, 'Target wajib diisi.');
            return;
        }

        setValidationState(false, 'Mengecek validitas...');
        
        // PERUBAHAN UTAMA DI SINI:
        // Arahkan langsung ke api.php di dalam folder system/whatsapp
        var apiUrl = 'system/whatsapp/api.php'; 
        
        var params = new URLSearchParams();
        params.append('wa_action', 'validate');
        params.append('target', target);
        params.append('type', type);
        
        // Fetch langsung ke file API, bukan ke dashboard
        fetch(apiUrl + '?' + params.toString())
            .then(function(resp){
                if (!resp.ok) {
                    throw new Error('HTTP ' + resp.status);
                }
                // Cek contentType untuk memastikan JSON
                var contentType = resp.headers.get("content-type");
                if (contentType && contentType.indexOf("application/json") !== -1) {
                    return resp.json();
                } else {
                    // Jika masih dapat HTML, berarti path apiUrl salah
                    throw new Error('Respon bukan JSON. Path API mungkin salah.');
                }
            })
            .then(function(data){
                if (data && data.ok) {
                    setValidationState(true, data.message || 'Valid.');
                } else {
                    setValidationState(false, (data && data.message) ? data.message : 'Nomor tidak valid.');
                }
            })
            .catch(function(err){
                console.error(err);
                var msg = (err && err.message) ? err.message : 'Gagal validasi.';
                // Fallback handling jika path salah
                if (msg.includes('JSON')) {
                    msg = 'Error: Gagal menghubungi API server.';
                }
                setValidationState(false, msg);
            });
    }

    if (targetInput) {
        targetInput.addEventListener('blur', checkWaTarget);
    }
    if (typeSelect) {
        typeSelect.addEventListener('change', function(){
            if (targetInput && targetInput.value.trim() !== '') checkWaTarget();
        });
    }
});

```

---

### 3. Bersihkan File: `system/whatsapp/index.php`

Hapus semua logika JSON (AJAX handler) di file ini agar bersih dan fokus ke tampilan saja.

```php
<?php
// FILE: system/whatsapp/index.php
// KHUSUS TAMPILAN (VIEW) - LOGIKA API DIPINDAH KE api.php

// Pastikan session aktif (biasanya sudah di-start oleh admin.php, tapi untuk keamanan)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Cek Sesi Mikhmon
if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

$root_dir = dirname(__DIR__, 2);
$config = include __DIR__ . '/config.php';
$dbFile = $config['db_file'] ?? $root_dir . '/db_data/mikhmon_stats.db';
$pdf_dir = $config['pdf_dir'] ?? ($root_dir . '/report/pdf');
$log_limit = (int)($config['log_limit'] ?? 50);
$timezone = $config['timezone'] ?? '';

$db = null;
$db_error = '';
$form_error = '';
$form_success = '';
$edit_row = null;

// Koneksi DB & Init Table (sama seperti sebelumnya)
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
    
    // Update kolom jika belum ada
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

} catch (Exception $e) {
    $db_error = $e->getMessage();
}

// FUNGSI PHP untuk View (Sanitasi Input Form)
function view_sanitize_wa_label($label) {
    return preg_replace('/\s+/', ' ', trim((string)$label));
}
function view_sanitize_wa_target($target) {
    return trim((string)$target);
}

// HANDLER FORM POST (Save/Delete) - Tetap di sini karena ini form submit biasa (bukan AJAX)
if ($db instanceof PDO && isset($_POST['wa_action'])) {
    $action = $_POST['wa_action'];
    
    if ($action === 'save') {
        $id = isset($_POST['wa_id']) ? (int)$_POST['wa_id'] : 0;
        $label = view_sanitize_wa_label($_POST['wa_label'] ?? '');
        $target_type = $_POST['wa_type'] ?? 'number';
        $active = isset($_POST['wa_active']) ? 1 : 0;
        $receive_retur = isset($_POST['wa_receive_retur']) ? 1 : 0;
        $receive_report = isset($_POST['wa_receive_report']) ? 1 : 0;
        $target_raw = view_sanitize_wa_target($_POST['wa_target'] ?? '');
        
        // Validasi sederhana sisi server (untuk keamanan ganda)
        if ($target_raw === '') {
            $form_error = 'Target wajib diisi.';
        } else {
            // Kita percaya validasi JS/API, tapi sanitize target sesuai tipe
            if ($target_type === 'number') {
                $target_clean = preg_replace('/\D+/', '', $target_raw);
                if (strpos($target_clean, '0') === 0) $target_clean = '62' . substr($target_clean, 1);
            } else {
                $target_clean = $target_raw;
            }

            try {
                $now = date('Y-m-d H:i:s');
                if ($id > 0) {
                    $stmt = $db->prepare("UPDATE whatsapp_recipients SET label=:label, target=:target, target_type=:type, active=:active, receive_retur=:rr, receive_report=:rp, updated_at=:updated WHERE id=:id");
                    $stmt->execute([
                        ':label' => $label, ':target' => $target_clean, ':type' => $target_type,
                        ':active' => $active, ':rr' => $receive_retur, ':rp' => $receive_report,
                        ':updated' => $now, ':id' => $id
                    ]);
                    $form_success = 'Data diperbarui.';
                } else {
                    $stmt = $db->prepare("INSERT INTO whatsapp_recipients (label, target, target_type, active, receive_retur, receive_report, created_at, updated_at) VALUES (:label,:target,:type,:active,:rr,:rp,:created,:updated)");
                    $stmt->execute([
                        ':label' => $label, ':target' => $target_clean, ':type' => $target_type,
                        ':active' => $active, ':rr' => $receive_retur, ':rp' => $receive_report,
                        ':created' => $now, ':updated' => $now
                    ]);
                    $form_success = 'Data ditambahkan.';
                }
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'UNIQUE') !== false) {
                    $form_error = 'Nomor/Target ini sudah terdaftar.';
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
                $form_success = 'Data dihapus.';
            } catch (Exception $e) {
                $form_error = 'Gagal menghapus: ' . $e->getMessage();
            }
        }
    }
}

// Ambil data untuk Edit
if ($db instanceof PDO && isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    if ($edit_id > 0) {
        $stmt = $db->prepare("SELECT * FROM whatsapp_recipients WHERE id=:id");
        $stmt->execute([':id' => $edit_id]);
        $edit_row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

// Ambil data List
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

// Scan PDF
$pdf_dir = rtrim($pdf_dir, '/');
if (is_dir($pdf_dir)) {
    $items = scandir($pdf_dir);
    foreach ($items as $file) {
        if ($file === '.' || $file === '..' || $file === 'index.php') continue;
        $path = $pdf_dir . '/' . $file;
        if (is_file($path)) {
            $pdf_files[] = [
                'name' => $file, 'size' => filesize($path), 'mtime' => filemtime($path)
            ];
        }
    }
}

$session_id = $_GET['session'] ?? '';
$session_qs = $session_id !== '' ? '&session=' . urlencode($session_id) : '';
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
                            <h4><?= $edit_row ? 'Edit Penerima' : 'Tambah Penerima'; ?></h4>
                        </div>
                        <form method="post" action="./?report=whatsapp<?= $session_qs; ?>">
                            <input type="hidden" name="wa_action" value="save">
                            <input type="hidden" name="wa_id" value="<?= htmlspecialchars($edit_row['id'] ?? ''); ?>">
                            <div class="wa-form-group">
                                <label class="wa-form-label">Label</label>
                                <input class="wa-form-input" type="text" name="wa_label" value="<?= htmlspecialchars($edit_row['label'] ?? ''); ?>" placeholder="Nama Penerima" required>
                            </div>
                            <div class="wa-form-group">
                                <label class="wa-form-label">Target (WA)</label>
                                <input class="wa-form-input" type="text" name="wa_target" value="<?= htmlspecialchars($edit_row['target'] ?? ''); ?>" placeholder="628xxx atau ID Group" autocomplete="off">
                                <div class="wa-help wa-validate-msg" id="waValidateMsg"></div>
                            </div>
                            <div class="wa-form-group">
                                <label class="wa-form-label">Tipe</label>
                                <select class="wa-form-select" name="wa_type">
                                    <option value="number" <?= ($edit_row['target_type']??'') === 'number' ? 'selected' : ''; ?>>Nomor</option>
                                    <option value="group" <?= ($edit_row['target_type']??'') === 'group' ? 'selected' : ''; ?>>Group</option>
                                </select>
                            </div>
                            <div class="wa-form-group">
                                <label class="wa-checkbox">
                                    <input type="checkbox" name="wa_active" value="1" <?= ($edit_row && (int)$edit_row['active'] === 0) ? '' : 'checked'; ?>>
                                    Status Aktif
                                </label>
                            </div>
                            <div class="wa-form-group" style="border-top: 1px solid #3b424a; padding-top:10px;">
                                <label class="wa-form-label" style="margin-bottom:8px;">Notifikasi:</label>
                                <label class="wa-checkbox">
                                    <input type="checkbox" name="wa_receive_retur" value="1" <?= ($edit_row && (int)($edit_row['receive_retur'] ?? 1) === 0) ? '' : 'checked'; ?>>
                                    Retur / Refund
                                </label>
                                <label class="wa-checkbox" style="margin-top:6px;">
                                    <input type="checkbox" name="wa_receive_report" value="1" <?= ($edit_row && (int)($edit_row['receive_report'] ?? 1) === 0) ? '' : 'checked'; ?>>
                                    Laporan Harian
                                </label>
                            </div>
                            <div class="wa-btn-group">
                                <button type="submit" class="wa-btn wa-btn-primary" id="waSaveBtn"><i class="fa fa-save"></i> Simpan</button>
                                <?php if ($edit_row): ?>
                                    <a class="wa-btn wa-btn-default" href="./?report=whatsapp<?= $session_qs; ?>">Batal</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>

                    <div class="wa-card">
                        <div class="wa-card-header">
                            <i class="fa fa-file-pdf-o"></i>
                            <h4>File Laporan</h4>
                        </div>
                        <?php if (empty($pdf_files)): ?>
                            <div class="wa-empty">Tidak ada file PDF.</div>
                        <?php else: ?>
                            <div class="wa-table-container">
                                <table class="wa-table">
                                    <thead><tr><th>File</th><th>Size</th><th>Date</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($pdf_files as $pf): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($pf['name']); ?></td>
                                                <td><?= number_format($pf['size'] / 1024, 1, ',', '.') ?> KB</td>
                                                <td><?= date('d/m H:i', $pf['mtime']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="wa-card" style="margin-top:20px;">
                    <div class="wa-card-header">
                        <i class="fa fa-users"></i>
                        <h4>Daftar Penerima</h4>
                    </div>
                    <?php if (empty($recipients)): ?>
                        <div class="wa-empty">Belum ada data.</div>
                    <?php else: ?>
                        <div class="wa-table-container">
                            <table class="wa-table">
                                <thead>
                                    <tr>
                                        <th>#</th><th>Label</th><th>Target</th><th>Tipe</th><th>Status</th><th>Notif</th><th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recipients as $i => $r): ?>
                                        <tr>
                                            <td><?= $i + 1; ?></td>
                                            <td><strong><?= htmlspecialchars($r['label'] ?? ''); ?></strong></td>
                                            <td><?= htmlspecialchars($r['target'] ?? ''); ?></td>
                                            <td><?= htmlspecialchars($r['target_type'] ?? ''); ?></td>
                                            <td>
                                                <span class="wa-badge <?= (int)$r['active']===1?'on':'off'; ?>">
                                                    <?= (int)$r['active']===1?'Aktif':'Nonaktif'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ((int)($r['receive_retur']??1)===1) echo '<span class="wa-badge" style="background:#2563eb;color:#fff;font-size:10px;margin-right:2px;">Retur</span>'; ?>
                                                <?php if ((int)($r['receive_report']??1)===1) echo '<span class="wa-badge" style="background:#16a34a;color:#fff;font-size:10px;">Laporan</span>'; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <a class="wa-btn wa-btn-default wa-btn-sm" href="./?report=whatsapp<?= $session_qs; ?>&edit=<?= $r['id']; ?>"><i class="fa fa-edit"></i></a>
                                                    <button class="wa-btn wa-btn-danger wa-btn-sm" onclick="openDeleteModal(<?= $r['id']; ?>,'<?= htmlspecialchars(addslashes($r['label'])); ?>')"><i class="fa fa-trash"></i></button>
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
                        <h4>Log Terakhir</h4>
                    </div>
                    <?php if (empty($logs)): ?>
                        <div class="wa-empty">Belum ada log.</div>
                    <?php else: ?>
                        <div class="wa-table-container">
                            <table class="wa-table">
                                <thead><tr><th>Waktu</th><th>Target</th><th>Status</th></tr></thead>
                                <tbody>
                                    <?php foreach ($logs as $log): ?>
                                        <tr>
                                            <td><small><?= htmlspecialchars($log['created_at']); ?></small></td>
                                            <td><?= htmlspecialchars($log['target']); ?></td>
                                            <td>
                                                <?php $st = strtolower($log['status']??''); ?>
                                                <span class="wa-badge <?= (strpos($st,'success')!==false || strpos($st,'sent')!==false)?'on':'off'; ?>">
                                                    <?= htmlspecialchars($log['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="wa-session-info">ID: <?= htmlspecialchars($session_id); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="wa-modal" id="waDeleteModal">
    <div class="wa-modal-card">
        <div class="wa-modal-header"><h5>Hapus Data</h5></div>
        <div class="wa-modal-body"><p id="waDeleteText">Hapus?</p></div>
        <form method="post" action="./?report=whatsapp<?= $session_qs; ?>">
            <input type="hidden" name="wa_action" value="delete">
            <input type="hidden" name="wa_id" id="waDeleteId" value="">
            <div class="wa-modal-footer">
                <button type="button" class="wa-btn wa-btn-default" onclick="closeDeleteModal()">Batal</button>
                <button type="submit" class="wa-btn wa-btn-danger">Hapus</button>
            </div>
        </form>
    </div>
</div>

<script src="./system/whatsapp/whatsapp.js"></script>

```