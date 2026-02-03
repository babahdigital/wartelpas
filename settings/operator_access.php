<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
require_once __DIR__ . '/../include/db.php';
app_db_import_legacy_if_needed();
requireLogin('../admin.php?id=login');

$env = [];
$envFile = __DIR__ . '/../include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}

require_once __DIR__ . '/admin_account_logic.php';

$admin_row = app_db_get_admin();
$useradm = $admin_row['username'] ?? '';

$op_db = app_db_get_operator();
$op_user = $op_db['username'] ?? '';
$op_pass = $op_db['password'] ?? '';
if ($op_user === '' && $op_pass === '') {
    $op_user = $env['auth']['operator_user'] ?? '';
    $op_pass = $env['auth']['operator_pass'] ?? '';
}
?>

<?php if (!isSuperAdmin()): ?>
    <div class="admin-empty">Akses ditolak. Hubungi Superadmin.</div>
<?php else: ?>
<script>
    window.__isSuperAdminFlag = true;
</script>
<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success" data-auto-close="1" style="margin-bottom: 15px; padding: 15px; border-radius: 10px; position: relative;">
        Data admin & operator tersimpan.
        <button type="button" aria-label="Close" onclick="this.parentElement.style.display='none';" style="position:absolute; right:8px; top:8px; background:transparent; border:none; color:inherit; font-size:16px; cursor:pointer;">×</button>
    </div>
<?php elseif (isset($_GET['error']) && $_GET['error'] === 'empty-username'): ?>
    <div class="alert alert-danger" data-auto-close="1" style="margin-bottom: 15px; position: relative;">
        Gagal menyimpan. Username tidak boleh kosong.
        <button type="button" aria-label="Close" onclick="this.parentElement.style.display='none';" style="position:absolute; right:8px; top:8px; background:transparent; border:none; color:inherit; font-size:16px; cursor:pointer;">×</button>
    </div>
<?php elseif (isset($_GET['error']) && $_GET['error'] === 'forbidden'): ?>
    <div class="alert alert-danger" data-auto-close="1" style="margin-bottom: 15px; position: relative;">
        Akses ditolak. Hubungi Superadmin.
        <button type="button" aria-label="Close" onclick="this.parentElement.style.display='none';" style="position:absolute; right:8px; top:8px; background:transparent; border:none; color:inherit; font-size:16px; cursor:pointer;">×</button>
    </div>
<?php endif; ?>
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
</script>
<form autocomplete="off" method="post" action="./admin.php?id=operator-access" data-no-ajax="1">
    <div class="row">
        <div class="col-6">
            <div class="card-modern">
                <div class="card-header-modern">
                    <h3><i class="fa fa-user-secret"></i> Data Akun Administrator</h3>
                </div>
                <div class="card-body-modern">
                    <div class="form-group-modern">
                        <label class="form-label">Username Admin</label>
                        <div class="input-group-modern">
                            <div class="input-icon"><i class="fa fa-user-circle"></i></div>
                            <input class="form-control-modern" type="text" name="useradm" value="<?= htmlspecialchars($useradm ?? ''); ?>" required>
                        </div>
                    </div>

                    <div class="form-group-modern">
                        <label class="form-label">Password Admin</label>
                        <div class="input-group-modern">
                            <div class="input-icon"><i class="fa fa-lock"></i></div>
                            <input class="form-control-modern" type="text" value="Disembunyikan" readonly>
                        </div>
                        <small style="display:block; margin-top:6px; color:var(--text-secondary);">
                            Ubah password lewat tombol "Ubah Password" di bawah.
                        </small>
                    </div>

                </div>
            </div>
        </div>

        <div class="col-6">
            <div class="card-modern">
                <div class="card-header-modern">
                    <h3><i class="fa fa-users"></i> Akses Operator</h3>
                    <small class="badge" style="background:var(--warning); color:#000;">Level: Support</small>
                </div>
                <div class="card-body-modern">
                    <p style="font-size:12px; color:var(--text-secondary); margin-bottom:15px;">
                        Tentukan apa saja yang boleh dilakukan oleh user Operator selain Admin Utama.
                    </p>

                    <div class="form-group-modern">
                        <label class="form-label">Username Operator</label>
                        <div class="input-group-modern">
                            <div class="input-icon"><i class="fa fa-user"></i></div>
                            <input class="form-control-modern" type="text" name="operator_user" value="<?= htmlspecialchars($op_user); ?>" required>
                        </div>
                    </div>

                    <div class="form-group-modern">
                        <label class="form-label">Password Operator</label>
                        <div class="input-group-modern">
                            <div class="input-icon"><i class="fa fa-lock"></i></div>
                            <input class="form-control-modern" type="text" value="Disembunyikan" readonly>
                        </div>
                        <small style="display:block; margin-top:6px; color:var(--text-secondary);">
                            Operator bisa ubah lewat tombol gear di samping logout.
                        </small>
                    </div>

                    <div class="checkbox-wrapper">
                        <div class="row">
                            <div class="col-6">
                                <label class="custom-check">
                                    <input type="checkbox" name="access_delete_user">
                                    <span class="checkmark"></span>
                                    <span class="check-label">Deleted User</span>
                                </label>
                                <label class="custom-check">
                                    <input type="checkbox" name="access_delete_block">
                                    <span class="checkmark"></span>
                                    <span class="check-label">Delete Blok</span>
                                </label>
                                <label class="custom-check">
                                    <input type="checkbox" name="access_audit_manual">
                                    <span class="checkmark"></span>
                                    <span class="check-label">Edit Audit Manual</span>
                                </label>
                            </div>
                            <div class="col-6">
                                <label class="custom-check">
                                    <input type="checkbox" name="access_reset_settlement">
                                    <span class="checkmark"></span>
                                    <span class="check-label">Reset Settlement</span>
                                </label>
                                <label class="custom-check">
                                    <input type="checkbox" name="access_backup_restore">
                                    <span class="checkmark"></span>
                                    <span class="check-label">Backup & Restore</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div style="text-align: right;">
                <button type="button" class="btn-action" style="width: 100%; justify-content: center; padding: 12px; margin-bottom: 10px;" onclick="openPasswordPopup()">
                    <i class="fa fa-lock"></i> Ubah Password
                </button>
                <button type="submit" name="save" class="btn-action btn-primary-m" style="width: 100%; justify-content: center; padding: 12px;">
                    <i class="fa fa-save"></i> Simpan Data Admin & Operator
                </button>
            </div>
        </div>
    </div>
</form>
<?php endif; ?>
