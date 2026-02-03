<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/db_helpers.php';
requireLogin('../admin.php?id=login');
requireSuperAdmin('../admin.php?id=sessions');

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$save_message = '';
$save_type = '';
if (!empty($_SESSION['wa_save_message'])) {
    $save_message = (string)$_SESSION['wa_save_message'];
    $save_type = (string)($_SESSION['wa_save_type'] ?? 'info');
    unset($_SESSION['wa_save_message'], $_SESSION['wa_save_type']);
}

$stats_db = null;
$stats_db_error = '';
$wa_recipients = [];
$wa_logs = [];

try {
    $stats_db_path = get_stats_db_path();
    if ($stats_db_path !== '') {
        $stats_db = new PDO('sqlite:' . $stats_db_path);
        $stats_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stats_db->exec("PRAGMA journal_mode=WAL;");
        $stats_db->exec("PRAGMA busy_timeout=5000;");
        $stats_db->exec("CREATE TABLE IF NOT EXISTS whatsapp_recipients (
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
        $stats_db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_whatsapp_recipients_target ON whatsapp_recipients(target)");
        $stats_db->exec("CREATE TABLE IF NOT EXISTS whatsapp_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            target TEXT,
            message TEXT,
            pdf_file TEXT,
            status TEXT,
            response_json TEXT,
            created_at TEXT
        )");
        $stats_db->exec("CREATE INDEX IF NOT EXISTS idx_whatsapp_logs_created ON whatsapp_logs(created_at)");
    }
} catch (Exception $e) {
    $stats_db_error = $e->getMessage();
    $stats_db = null;
}

if (isset($_POST['wa_action']) && $_POST['wa_action'] === 'delete_recipient') {
    $del_id = (int)($_POST['wa_id'] ?? 0);
    if ($stats_db && $del_id > 0) {
        try {
            $stmtDel = $stats_db->prepare("DELETE FROM whatsapp_recipients WHERE id = :id");
            $stmtDel->execute([':id' => $del_id]);
            $save_message = 'Penerima WhatsApp berhasil dihapus.';
            $save_type = 'success';
        } catch (Exception $e) {
            $save_message = 'Gagal menghapus penerima WhatsApp.';
            $save_type = 'danger';
        }
    } else {
        $save_message = 'Penerima WhatsApp tidak ditemukan.';
        $save_type = 'warning';
    }

    if (!$is_ajax) {
        $_SESSION['wa_save_message'] = $save_message;
        $_SESSION['wa_save_type'] = $save_type;
        if (!headers_sent()) {
            header('Location: ./admin.php?id=whatsapp');
        } else {
            echo "<script>window.location='./admin.php?id=whatsapp';</script>";
        }
        exit;
    }
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
        $error = 'Group ID harus berakhiran @g.us.';
        return false;
    }
    return $target;
}

if (isset($_POST['wa_action']) && $_POST['wa_action'] === 'add_recipient') {
    $label = sanitize_wa_label($_POST['wa_new_label'] ?? '');
    $target_raw = sanitize_wa_target($_POST['wa_new_target'] ?? '');
    $type = $_POST['wa_new_type'] ?? 'number';
    $err = '';
    $validated_target = validate_wa_target($target_raw, $type, $err);
    if ($validated_target === false) {
        $save_message = $err;
        $save_type = 'warning';
    } elseif (!$stats_db) {
        $save_message = 'DB WhatsApp tidak tersedia.';
        $save_type = 'danger';
    } else {
        try {
            $now = date('Y-m-d H:i:s');
            $stmtFind = $stats_db->prepare("SELECT id FROM whatsapp_recipients WHERE target = :target");
            $stmtFind->execute([':target' => $validated_target]);
            $existing = $stmtFind->fetch(PDO::FETCH_ASSOC);
            if ($existing && !empty($existing['id'])) {
                $stmtUp = $stats_db->prepare("UPDATE whatsapp_recipients SET label = :label, target_type = :type, active = 1, updated_at = :now WHERE id = :id");
                $stmtUp->execute([
                    ':label' => $label,
                    ':type' => $type,
                    ':now' => $now,
                    ':id' => (int)$existing['id'],
                ]);
                $save_message = 'Penerima WhatsApp diperbarui.';
            } else {
                $stmtAdd = $stats_db->prepare("INSERT INTO whatsapp_recipients (label, target, target_type, active, receive_retur, receive_report, created_at, updated_at) VALUES (:label, :target, :type, 1, 1, 1, :now, :now)");
                $stmtAdd->execute([
                    ':label' => $label,
                    ':target' => $validated_target,
                    ':type' => $type,
                    ':now' => $now,
                ]);
                $save_message = 'Penerima WhatsApp berhasil ditambahkan.';
            }
            $save_type = 'success';
        } catch (Exception $e) {
            $save_message = 'Gagal menambahkan penerima WhatsApp.';
            $save_type = 'danger';
        }
    }

    if (!$is_ajax) {
        $_SESSION['wa_save_message'] = $save_message;
        $_SESSION['wa_save_type'] = $save_type;
        if (!headers_sent()) {
            header('Location: ./admin.php?id=whatsapp');
        } else {
            echo "<script>window.location='./admin.php?id=whatsapp';</script>";
        }
        exit;
    }
}

if (isset($_POST['wa_action']) && $_POST['wa_action'] === 'update_recipient') {
    $id = (int)($_POST['wa_id'] ?? 0);
    if ($stats_db && $id > 0) {
        $label = sanitize_wa_label($_POST['wa_label'] ?? '');
        $active = isset($_POST['wa_active']) ? 1 : 0;
        $receive_retur = isset($_POST['wa_receive_retur']) ? 1 : 0;
        $receive_report = isset($_POST['wa_receive_report']) ? 1 : 0;
        try {
            $stmtUp = $stats_db->prepare("UPDATE whatsapp_recipients SET label = :label, active = :active, receive_retur = :retur, receive_report = :report, updated_at = :now WHERE id = :id");
            $stmtUp->execute([
                ':label' => $label,
                ':active' => $active,
                ':retur' => $receive_retur,
                ':report' => $receive_report,
                ':now' => date('Y-m-d H:i:s'),
                ':id' => $id,
            ]);
            $save_message = 'Pengaturan penerima diperbarui.';
            $save_type = 'success';
        } catch (Exception $e) {
            $save_message = 'Gagal memperbarui penerima.';
            $save_type = 'danger';
        }
    } else {
        $save_message = 'Penerima tidak ditemukan.';
        $save_type = 'warning';
    }

    if (!$is_ajax) {
        $_SESSION['wa_save_message'] = $save_message;
        $_SESSION['wa_save_type'] = $save_type;
        if (!headers_sent()) {
            header('Location: ./admin.php?id=whatsapp');
        } else {
            echo "<script>window.location='./admin.php?id=whatsapp';</script>";
        }
        exit;
    }
}

if (isset($_POST['wa_action']) && $_POST['wa_action'] === 'test_send') {
    $test_message = trim((string)($_POST['wa_test_message'] ?? ''));
    $test_target = trim((string)($_POST['wa_test_target'] ?? ''));
    if ($test_target === '' && !empty($_POST['wa_test_target_select'])) {
        $test_target = trim((string)$_POST['wa_test_target_select']);
    }
    if ($test_message === '') {
        $save_message = 'Pesan test wajib diisi.';
        $save_type = 'warning';
    } elseif ($test_target === '') {
        $save_message = 'Target test wajib diisi.';
        $save_type = 'warning';
    } else {
        $wa_helper_file = __DIR__ . '/../system/whatsapp/wa_helper.php';
        if (file_exists($wa_helper_file)) {
            require_once $wa_helper_file;
        }
        if (function_exists('wa_send_text')) {
            $res = wa_send_text($test_message, $test_target, 'test');
            if (!empty($res['ok'])) {
                $save_message = 'Test WhatsApp terkirim.';
                $save_type = 'success';
            } else {
                $save_message = 'Test WhatsApp gagal: ' . ($res['message'] ?? 'error');
                $save_type = 'danger';
            }
        } else {
            $save_message = 'WA helper tidak tersedia.';
            $save_type = 'danger';
        }
    }

    if (!$is_ajax) {
        $_SESSION['wa_save_message'] = $save_message;
        $_SESSION['wa_save_type'] = $save_type;
        if (!headers_sent()) {
            header('Location: ./admin.php?id=whatsapp');
        } else {
            echo "<script>window.location='./admin.php?id=whatsapp';</script>";
        }
        exit;
    }
}

if (isset($_POST['save_whatsapp'])) {
    $wh = app_db_get_whatsapp_config();
    $wh['endpoint_send'] = trim((string)($_POST['wa_endpoint_send'] ?? ($wh['endpoint_send'] ?? '')));
    $wh['token'] = trim((string)($_POST['wa_token'] ?? ($wh['token'] ?? '')));
    $raw_targets = trim((string)($_POST['wa_notify_target'] ?? ($wh['notify_target'] ?? '')));
    $targets = preg_split('/[\r\n,;]+/', $raw_targets);
    $targets = array_filter(array_map('trim', (array)$targets), function ($val) {
        return $val !== '';
    });
    $wh['notify_target'] = implode(',', array_values($targets));
    $wh['notify_request_enabled'] = !empty($_POST['wa_notify_request_enabled']);
    $wh['notify_retur_enabled'] = !empty($_POST['wa_notify_retur_enabled']);
    $wh['notify_refund_enabled'] = !empty($_POST['wa_notify_refund_enabled']);
    $wh['notify_ls_enabled'] = !empty($_POST['wa_notify_ls_enabled']);
    $wh['country_code'] = trim((string)($wh['country_code'] ?? '62'));
    if ($wh['country_code'] === '') {
        $wh['country_code'] = '62';
    }
    $wh['timezone'] = trim((string)($wh['timezone'] ?? 'Asia/Makassar'));
    if ($wh['timezone'] === '') {
        $wh['timezone'] = 'Asia/Makassar';
    }
    $wh['log_limit'] = (int)($_POST['wa_log_limit'] ?? ($wh['log_limit'] ?? 50));

    try {
        app_db_set_whatsapp_config($wh);
        $save_message = 'Konfigurasi WhatsApp berhasil disimpan.';
        $save_type = 'success';
    } catch (Exception $e) {
        @error_log(date('c') . " [admin][whatsapp] db save failed: " . $e->getMessage() . "\n", 3, __DIR__ . '/../logs/admin_errors.log');
        $save_message = 'Gagal menyimpan WhatsApp. Penyimpanan database gagal.';
        $save_type = 'danger';
    }

    if ($is_ajax) {
        if ($save_message === '') {
            $save_message = 'Konfigurasi WhatsApp berhasil disimpan.';
            $save_type = 'success';
        }
    } else {
        $_SESSION['wa_save_message'] = $save_message;
        $_SESSION['wa_save_type'] = $save_type;
        if (!headers_sent()) {
            header('Location: ./admin.php?id=whatsapp');
        } else {
            echo "<script>window.location='./admin.php?id=whatsapp';</script>";
        }
        exit;
    }
}

$wa = app_db_get_whatsapp_config();
$wa_endpoint_send = $wa['endpoint_send'] ?? '';
$wa_token = $wa['token'] ?? '';
$wa_notify_target = $wa['notify_target'] ?? '';
$wa_notify_target_list = '';
if ($wa_notify_target !== '') {
    $tmp_targets = preg_split('/[\r\n,;]+/', (string)$wa_notify_target);
    $tmp_targets = array_filter(array_map('trim', (array)$tmp_targets), function ($val) {
        return $val !== '';
    });
    $wa_notify_target_list = implode("\n", array_values($tmp_targets));
}
$wa_notify_request_enabled = !empty($wa['notify_request_enabled']);
$wa_notify_retur_enabled = !empty($wa['notify_retur_enabled']);
$wa_notify_refund_enabled = !empty($wa['notify_refund_enabled']);
$wa_notify_ls_enabled = !empty($wa['notify_ls_enabled']);
$wa_log_limit = isset($wa['log_limit']) ? (int)$wa['log_limit'] : 50;

if ($stats_db) {
    try {
        $stmtRec = $stats_db->query("SELECT id, label, target, target_type, active, receive_retur, receive_report, created_at, updated_at FROM whatsapp_recipients ORDER BY id DESC");
        $wa_recipients = $stmtRec ? $stmtRec->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Exception $e) {
        $wa_recipients = [];
    }
    try {
        $limit = $wa_log_limit > 0 ? $wa_log_limit : 50;
        $stmtLog = $stats_db->prepare("SELECT id, target, message, pdf_file, status, created_at FROM whatsapp_logs ORDER BY id DESC LIMIT :lim");
        $stmtLog->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmtLog->execute();
        $wa_logs = $stmtLog->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $wa_logs = [];
    }
}
?>

<style>
    .wa-section-title {
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 8px;
        color: #cbd5db;
    }
    .wa-muted {
        color: #7f8a96;
        font-size: 12px;
    }
    .wa-badge {
        display: inline-flex;
        align-items: center;
        padding: 3px 8px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.2px;
    }
    .wa-badge-blue { background: rgba(59,130,246,0.2); color: #93c5fd; }
    .wa-badge-purple { background: rgba(168,85,247,0.2); color: #d8b4fe; }
    .wa-badge-green { background: rgba(34,197,94,0.2); color: #86efac; }
    .wa-badge-red { background: rgba(239,68,68,0.2); color: #fca5a5; }
    .wa-switch {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
    }
    .wa-switch input { position: absolute; opacity: 0; width: 0; height: 0; }
    .wa-switch-slider {
        position: relative;
        width: 36px;
        height: 18px;
        background: #3b424a;
        border-radius: 999px;
        transition: 0.2s;
        box-shadow: inset 0 0 0 1px rgba(148,163,184,0.35);
    }
    .wa-switch-slider::after {
        content: '';
        position: absolute;
        top: 2px;
        left: 2px;
        width: 14px;
        height: 14px;
        background: #d9e1e7;
        border-radius: 50%;
        transition: 0.2s;
    }
    .wa-switch input:checked + .wa-switch-slider {
        background: #1da851;
        box-shadow: inset 0 0 0 1px rgba(37, 211, 102, 0.6);
    }
    .wa-switch input:checked + .wa-switch-slider::after {
        transform: translateX(18px);
        background: #ffffff;
    }
    .wa-cell-stack {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
</style>

<div class="card-modern">
    <div class="card-header-modern">
        <h3><i class="fa fa-whatsapp text-green"></i> Konfigurasi WhatsApp</h3>
    </div>
    <div class="card-body-modern">
        <?php if ($save_message !== ''): ?>
            <div class="alert alert-<?= htmlspecialchars($save_type); ?>" data-auto-close="1" style="margin-bottom: 15px; padding: 15px; border-radius: 10px; position: relative;">
                <?= htmlspecialchars($save_message); ?>
                <button type="button" aria-label="Close" onclick="this.parentElement.style.display='none';" style="position:absolute; right:8px; background:transparent; border:none; color:inherit; font-size:16px; cursor:pointer;">×</button>
            </div>
        <?php endif; ?>
        <form method="post" action="./admin.php?id=whatsapp" data-admin-form="whatsapp">
            <input type="hidden" name="wa_notify_request_enabled" value="<?= $wa_notify_request_enabled ? '1' : '0'; ?>">
            <input type="hidden" name="wa_notify_ls_enabled" value="<?= $wa_notify_ls_enabled ? '1' : '0'; ?>">
            <input type="hidden" name="wa_notify_retur_enabled" value="<?= $wa_notify_retur_enabled ? '1' : '0'; ?>">
            <input type="hidden" name="wa_notify_refund_enabled" value="<?= $wa_notify_refund_enabled ? '1' : '0'; ?>">
            <div class="row">
                <div class="col-6">
                    <div class="form-group-modern">
                        <div class="wa-section-title">Konfigurasi Utama</div>
                        <div class="wa-muted">Endpoint, token, dan limit log.</div>
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label">Endpoint Send</label>
                        <div class="input-group-modern">
                            <div class="input-icon"><i class="fa fa-link"></i></div>
                            <input class="form-control-modern" type="text" name="wa_endpoint_send" value="<?= htmlspecialchars($wa_endpoint_send); ?>" placeholder="https://api.fonnte.com/send">
                        </div>
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label">Token</label>
                        <div class="input-group-modern">
                            <div class="input-icon"><i class="fa fa-key"></i></div>
                            <input class="form-control-modern" type="password" name="wa_token" value="<?= htmlspecialchars($wa_token); ?>" placeholder="Token Fonnte">
                        </div>
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label">Log Limit</label>
                        <div class="input-group-modern">
                            <div class="input-icon"><i class="fa fa-list"></i></div>
                            <input class="form-control-modern" type="number" min="1" max="500" name="wa_log_limit" value="<?= (int)$wa_log_limit; ?>">
                        </div>
                        <small class="wa-muted" style="display:block; margin-top:6px;">Jumlah log terakhir yang ditampilkan di bawah.</small>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-group-modern">
                        <div class="wa-section-title">Target Notifikasi</div>
                        <div class="wa-muted">Pilih nomor/group untuk menerima notifikasi global.</div>
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label">Target Notifikasi (nomor / group)</label>
                        <div class="input-group-modern" style="align-items:flex-start;">
                            <div class="input-icon"><i class="fa fa-phone"></i></div>
                            <textarea class="form-control-modern" name="wa_notify_target" rows="4" placeholder="62812xxxxxxx&#10;120363xxxx@g.us&#10;(pisahkan dengan enter atau koma)"><?= htmlspecialchars($wa_notify_target_list); ?></textarea>
                        </div>
                        <small class="wa-muted" style="display:block; margin-top:6px;">Kosongkan untuk memakai daftar penerima di menu WhatsApp.</small>
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label">Ambil dari daftar penerima</label>
                        <div class="input-group-modern">
                            <div class="input-icon"><i class="fa fa-users"></i></div>
                            <select class="form-control-modern" id="waTargetSelect">
                                <option value="">-- Pilih Nomor / Group --</option>
                                <?php if (!empty($wa_recipients)): ?>
                                    <?php foreach ($wa_recipients as $rec): ?>
                                        <?php
                                            $label = trim((string)($rec['label'] ?? ''));
                                            $target = trim((string)($rec['target'] ?? ''));
                                            $type = (string)($rec['target_type'] ?? 'number');
                                            $show = $label !== '' ? ($label . ' - ' . $target) : $target;
                                        ?>
                                        <option value="<?= htmlspecialchars($target); ?>">[<?= htmlspecialchars($type); ?>] <?= htmlspecialchars($show); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <button class="btn-action btn-default-m" type="button" id="waAddTargetBtn" style="margin-top:8px;">
                            <i class="fa fa-plus"></i> Tambah ke Target Notifikasi
                        </button>
                    </div>
                </div>
            </div>
            <div style="display:flex; justify-content:flex-end; margin-top: 10px;">
                <button class="btn-action btn-primary-m" type="submit" name="save_whatsapp">
                    <i class="fa fa-save"></i> Simpan WhatsApp
                </button>
            </div>
        </form>

        <div class="row" style="margin-top: 20px;">
            <div class="col-6">
                <div class="form-group-modern">
                    <label class="form-label">Daftarkan Nomor / Group</label>
                    <small class="wa-muted" style="display:block; margin-bottom:6px;">Isi label dan nomor/group baru untuk daftar penerima.</small>
                    <form method="post" action="./admin.php?id=whatsapp">
                        <input type="hidden" name="wa_action" value="add_recipient">
                        <div class="input-group-modern" style="margin-bottom:8px;">
                            <div class="input-icon"><i class="fa fa-user"></i></div>
                            <input class="form-control-modern" type="text" name="wa_new_label" placeholder="Nama / Label">
                        </div>
                        <div class="input-group-modern" style="margin-bottom:8px;">
                            <div class="input-icon"><i class="fa fa-phone"></i></div>
                            <input class="form-control-modern" type="text" name="wa_new_target" placeholder="62812xxxxxxx atau 1203xxxx@g.us">
                        </div>
                        <div class="input-group-modern" style="margin-bottom:8px;">
                            <div class="input-icon"><i class="fa fa-tag"></i></div>
                            <select class="form-control-modern" name="wa_new_type">
                                <option value="number">Nomor</option>
                                <option value="group">Group</option>
                            </select>
                        </div>
                        <div style="display:flex; justify-content:flex-end;">
                            <button class="btn-action btn-primary-m" type="submit">
                                <i class="fa fa-plus"></i> Tambah
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="col-6">
                <div class="form-group-modern">
                    <label class="form-label">Test Sender WhatsApp</label>
                    <small class="wa-muted" style="display:block; margin-bottom:6px;">Kirim pesan uji ke target manual atau pilih dari daftar.</small>
                    <form method="post" action="./admin.php?id=whatsapp">
                        <input type="hidden" name="wa_action" value="test_send">
                        <div class="input-group-modern" style="margin-bottom:8px;">
                            <div class="input-icon"><i class="fa fa-phone"></i></div>
                            <input class="form-control-modern" type="text" name="wa_test_target" placeholder="Target (opsional, jika tidak pilih dropdown)">
                        </div>
                        <div class="input-group-modern" style="margin-bottom:8px;">
                            <div class="input-icon"><i class="fa fa-list"></i></div>
                            <select class="form-control-modern" name="wa_test_target_select">
                                <option value="">-- Pilih dari daftar penerima --</option>
                                <?php if (!empty($wa_recipients)): ?>
                                    <?php foreach ($wa_recipients as $rec): ?>
                                        <?php
                                            $label = trim((string)($rec['label'] ?? ''));
                                            $target = trim((string)($rec['target'] ?? ''));
                                            $type = (string)($rec['target_type'] ?? 'number');
                                            $show = $label !== '' ? ($label . ' - ' . $target) : $target;
                                        ?>
                                        <option value="<?= htmlspecialchars($target); ?>">[<?= htmlspecialchars($type); ?>] <?= htmlspecialchars($show); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="input-group-modern" style="margin-bottom:8px; align-items:flex-start;">
                            <div class="input-icon"><i class="fa fa-comment"></i></div>
                            <textarea class="form-control-modern" name="wa_test_message" rows="3" placeholder="Pesan test"></textarea>
                        </div>
                        <div style="display:flex; justify-content:flex-end;">
                            <button class="btn-action btn-primary-m" type="submit">
                                <i class="fa fa-paper-plane"></i> Kirim Test
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="row" style="margin-top: 10px;">
            <div class="col-12">
                <div class="form-group-modern">
                    <label class="form-label">Daftar Penerima (Opsi per Member)</label>
                    <small class="wa-muted" style="display:block; margin-bottom:8px;">Setiap penerima memiliki izin notifikasi masing-masing.</small>
                    <?php if ($stats_db_error !== ''): ?>
                        <div class="alert alert-danger" style="margin-bottom:10px;">Gagal membaca DB WhatsApp: <?= htmlspecialchars($stats_db_error); ?></div>
                    <?php endif; ?>
                    <div style="overflow:auto;">
                        <table class="table" style="min-width:640px;">
                            <thead>
                                <tr>
                                    <th>Label</th>
                                    <th>Target</th>
                                    <th>Tipe</th>
                                    <th>Aktif</th>
                                    <th>Notif Retur</th>
                                    <th>Notif Laporan</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($wa_recipients)): ?>
                                    <tr><td colspan="7" style="text-align:center;">Belum ada penerima.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($wa_recipients as $rec): ?>
                                        <?php
                                            $type = (string)($rec['target_type'] ?? 'number');
                                            $type_badge = strtolower($type) === 'group' ? 'wa-badge wa-badge-purple' : 'wa-badge wa-badge-blue';
                                        ?>
                                        <tr>
                                            <td>
                                                <form method="post" action="./admin.php?id=whatsapp" style="display:flex; gap:8px; align-items:center;">
                                                    <input type="hidden" name="wa_action" value="update_recipient">
                                                    <input type="hidden" name="wa_id" value="<?= (int)($rec['id'] ?? 0); ?>">
                                                    <input class="form-control-modern" type="text" name="wa_label" value="<?= htmlspecialchars($rec['label'] ?? ''); ?>" style="min-width:140px;">
                                            </td>
                                            <td>
                                                <div class="wa-cell-stack">
                                                    <span><?= htmlspecialchars($rec['target'] ?? '-'); ?></span>
                                                </div>
                                            </td>
                                            <td><span class="<?= $type_badge; ?>"><?= htmlspecialchars($type); ?></span></td>
                                            <td>
                                                <label class="wa-switch">
                                                    <input type="checkbox" name="wa_active" <?= ((int)($rec['active'] ?? 1) === 1) ? 'checked' : ''; ?>>
                                                    <span class="wa-switch-slider"></span>
                                                </label>
                                            </td>
                                            <td>
                                                <label class="wa-switch">
                                                    <input type="checkbox" name="wa_receive_retur" <?= ((int)($rec['receive_retur'] ?? 1) === 1) ? 'checked' : ''; ?>>
                                                    <span class="wa-switch-slider"></span>
                                                </label>
                                            </td>
                                            <td>
                                                <label class="wa-switch">
                                                    <input type="checkbox" name="wa_receive_report" <?= ((int)($rec['receive_report'] ?? 1) === 1) ? 'checked' : ''; ?>>
                                                    <span class="wa-switch-slider"></span>
                                                </label>
                                            </td>
                                            <td>
                                                <div style="display:flex; gap:6px;">
                                                    <button class="btn-action btn-primary-m" type="submit" title="Simpan" style="padding:6px 10px;">
                                                        <i class="fa fa-save"></i>
                                                    </button>
                                                    <button class="btn-action btn-danger-m" type="submit" name="wa_action" value="delete_recipient" title="Hapus" style="padding:6px 10px;" onclick="return confirm('Hapus penerima ini?');">
                                                        <input type="hidden" name="wa_id" value="<?= (int)($rec['id'] ?? 0); ?>">
                                                        <i class="fa fa-trash"></i>
                                                    </button>
                                                </div>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="row" style="margin-top: 10px;">
            <div class="col-12">
                <div class="form-group-modern">
                    <label class="form-label">Log WhatsApp Terkirim</label>
                    <div style="overflow:auto;">
                        <table class="table" style="min-width:640px;">
                            <thead>
                                <tr>
                                    <th>Waktu</th>
                                    <th>Target</th>
                                    <th>Status</th>
                                    <th>Pesan</th>
                                    <th>File</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($wa_logs)): ?>
                                    <tr><td colspan="5" style="text-align:center;">Belum ada log.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($wa_logs as $log): ?>
                                        <?php
                                            $msg = (string)($log['message'] ?? '');
                                            if (strlen($msg) > 120) $msg = substr($msg, 0, 117) . '...';
                                            $status = strtolower((string)($log['status'] ?? ''));
                                            $status_badge = strpos($status, 'success') !== false ? 'wa-badge wa-badge-green' : (strpos($status, 'fail') !== false || strpos($status, 'error') !== false ? 'wa-badge wa-badge-red' : 'wa-badge wa-badge-blue');
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($log['created_at'] ?? '-'); ?></td>
                                            <td><?= htmlspecialchars($log['target'] ?? '-'); ?></td>
                                            <td><span class="<?= $status_badge; ?>"><?= htmlspecialchars($log['status'] ?? '-'); ?></span></td>
                                            <td><?= htmlspecialchars($msg); ?></td>
                                            <td><?= htmlspecialchars($log['pdf_file'] ?? '-'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <script>
            (function () {
                var alertBox = document.querySelector('[data-auto-close="1"]');
                if (!alertBox) return;
                setTimeout(function () {
                    if (alertBox && alertBox.style.display !== 'none') {
                        alertBox.style.display = 'none';
                    }
                }, 3000);
            })();

            (function(){
                var addBtn = document.getElementById('waAddTargetBtn');
                var select = document.getElementById('waTargetSelect');
                var textarea = document.querySelector('textarea[name="wa_notify_target"]');
                if (!addBtn || !select || !textarea) return;
                addBtn.addEventListener('click', function(){
                    var val = (select.value || '').trim();
                    if (!val) return;
                    var current = textarea.value || '';
                    var parts = current.split(/\r?\n|,|;/).map(function(v){ return v.trim(); }).filter(function(v){ return v; });
                    if (parts.indexOf(val) === -1) {
                        parts.push(val);
                    }
                    textarea.value = parts.join("\n");
                });
            })();
        </script>
    </div>
</div>
