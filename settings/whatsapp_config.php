<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
require_once __DIR__ . '/../include/db.php';
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

if (isset($_POST['save_whatsapp'])) {
    $wh = app_db_get_whatsapp_config();
    $wh['endpoint_send'] = trim((string)($_POST['wa_endpoint_send'] ?? ($wh['endpoint_send'] ?? '')));
    $wh['token'] = trim((string)($_POST['wa_token'] ?? ($wh['token'] ?? '')));
    $wh['notify_target'] = trim((string)($_POST['wa_notify_target'] ?? ($wh['notify_target'] ?? '')));
    $wh['notify_request_enabled'] = !empty($_POST['wa_notify_request_enabled']);
    $wh['notify_retur_enabled'] = !empty($_POST['wa_notify_retur_enabled']);
    $wh['notify_refund_enabled'] = !empty($_POST['wa_notify_refund_enabled']);
    $wh['country_code'] = trim((string)($_POST['wa_country_code'] ?? ($wh['country_code'] ?? '62')));
    $wh['timezone'] = trim((string)($_POST['wa_timezone'] ?? ($wh['timezone'] ?? 'Asia/Makassar')));
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
$wa_notify_request_enabled = !empty($wa['notify_request_enabled']);
$wa_notify_retur_enabled = !empty($wa['notify_retur_enabled']);
$wa_notify_refund_enabled = !empty($wa['notify_refund_enabled']);
$wa_country_code = $wa['country_code'] ?? '62';
$wa_timezone = $wa['timezone'] ?? 'Asia/Makassar';
$wa_log_limit = isset($wa['log_limit']) ? (int)$wa['log_limit'] : 50;
?>

<div class="card-modern">
    <div class="card-header-modern">
        <h3><i class="fa fa-whatsapp text-green"></i> Konfigurasi WhatsApp</h3>
    </div>
    <div class="card-body-modern">
        <?php if ($save_message !== ''): ?>
            <div class="alert alert-<?= htmlspecialchars($save_type); ?>" style="margin-bottom: 12px;">
                <?= htmlspecialchars($save_message); ?>
            </div>
        <?php endif; ?>
        <form method="post" action="./admin.php?id=whatsapp" data-admin-form="whatsapp">
            <div class="row">
                <div class="col-6">
                    <div class="form-group-modern">
                        <label class="form-label">Endpoint Send</label>
                        <div class="input-group-modern">
                            <div class="input-icon"><i class="fa fa-link"></i></div>
                            <input class="form-control-modern" type="text" name="wa_endpoint_send" value="<?= htmlspecialchars($wa_endpoint_send); ?>" placeholder="https://api.fonnte.com/send">
                        </div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-group-modern">
                        <label class="form-label">Token</label>
                        <div class="input-group-modern">
                            <div class="input-icon"><i class="fa fa-key"></i></div>
                            <input class="form-control-modern" type="password" name="wa_token" value="<?= htmlspecialchars($wa_token); ?>" placeholder="Token Fonnte">
                        </div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-group-modern">
                        <label class="form-label">Nomor Notifikasi (opsional)</label>
                        <div class="input-group-modern">
                            <div class="input-icon"><i class="fa fa-phone"></i></div>
                            <input class="form-control-modern" type="text" name="wa_notify_target" value="<?= htmlspecialchars($wa_notify_target); ?>" placeholder="62xxxxxxxx">
                        </div>
                    </div>
                </div>
                <div class="col-3">
                    <div class="form-group-modern">
                        <label class="form-label">Country Code</label>
                        <div class="input-group-modern">
                            <div class="input-icon"><i class="fa fa-flag"></i></div>
                            <input class="form-control-modern" type="text" name="wa_country_code" value="<?= htmlspecialchars($wa_country_code); ?>" placeholder="62">
                        </div>
                    </div>
                </div>
                <div class="col-3">
                    <div class="form-group-modern">
                        <label class="form-label">Timezone</label>
                        <div class="input-group-modern">
                            <div class="input-icon"><i class="fa fa-clock-o"></i></div>
                            <input class="form-control-modern" type="text" name="wa_timezone" value="<?= htmlspecialchars($wa_timezone); ?>" placeholder="Asia/Makassar">
                        </div>
                    </div>
                </div>
                <div class="col-3">
                    <div class="form-group-modern">
                        <label class="form-label">Log Limit</label>
                        <div class="input-group-modern">
                            <div class="input-icon"><i class="fa fa-list"></i></div>
                            <input class="form-control-modern" type="number" min="1" max="500" name="wa_log_limit" value="<?= (int)$wa_log_limit; ?>">
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="checkbox-wrapper">
                        <div class="row">
                            <div>
                                <label class="custom-check">
                                    <input type="checkbox" name="wa_notify_request_enabled" <?= $wa_notify_request_enabled ? 'checked' : ''; ?>>
                                    <span class="checkmark"></span>
                                    <span class="check-label">Notif Request</span>
                                </label>
                            </div>
                            <div>
                                <label class="custom-check">
                                    <input type="checkbox" name="wa_notify_retur_enabled" <?= $wa_notify_retur_enabled ? 'checked' : ''; ?>>
                                    <span class="checkmark"></span>
                                    <span class="check-label">Notif Retur</span>
                                </label>
                            </div>
                            <div>
                                <label class="custom-check">
                                    <input type="checkbox" name="wa_notify_refund_enabled" <?= $wa_notify_refund_enabled ? 'checked' : ''; ?>>
                                    <span class="checkmark"></span>
                                    <span class="check-label">Notif Refund</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div style="display:flex; justify-content:flex-end; margin-top: 10px;">
                <button class="btn-action btn-primary-m" type="submit" name="save_whatsapp">
                    <i class="fa fa-save"></i> Simpan WhatsApp
                </button>
            </div>
        </form>
    </div>
</div>
