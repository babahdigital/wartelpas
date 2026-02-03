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

$operators = app_db_list_operators();
$selected_id = $_GET['op'] ?? '';
$selected_operator = null;
$is_new_operator = ($selected_id === 'new');
if (!$is_new_operator && $selected_id !== '') {
    $selected_operator = app_db_get_operator_by_id((int)$selected_id);
}
if (!$selected_operator && !$is_new_operator && !empty($operators)) {
    $selected_operator = app_db_get_operator_by_id((int)($operators[0]['id'] ?? 0));
}

$op_id = $is_new_operator ? 'new' : (string)($selected_operator['id'] ?? '');
$op_user = $selected_operator['username'] ?? '';
$op_active = $is_new_operator ? true : !empty($selected_operator['is_active']);

$op_perms = ($op_id !== '' && $op_id !== 'new')
    ? app_db_get_operator_permissions_for((int)$op_id)
    : [
        'delete_user' => false,
        'delete_block_router' => false,
        'delete_block_full' => false,
        'audit_manual' => false,
        'reset_settlement' => false,
        'backup_only' => false,
        'restore_only' => false,
    ];
$perm_delete_user = !empty($op_perms['delete_user']);
$perm_delete_block_router = !empty($op_perms['delete_block_router']);
$perm_delete_block_full = !empty($op_perms['delete_block_full']);
$perm_audit_manual = !empty($op_perms['audit_manual']);
$perm_reset_settlement = !empty($op_perms['reset_settlement']);
$perm_backup_only = !empty($op_perms['backup_only']);
$perm_restore_only = !empty($op_perms['restore_only']);
?>

<?php if (!isSuperAdmin()): ?>
    <div class="admin-empty">Akses ditolak. Hubungi Superadmin.</div>
<?php else: ?>
<script>
    window.__isSuperAdminFlag = true;
</script>
<script>
    function submitOperatorDelete(opId) {
        if (!opId) return;
        if (!confirm('Hapus operator ini?')) return;
        var form = document.getElementById('operator-access-form');
        if (!form) return;
        var idInput = document.getElementById('operator-id-input');
        if (idInput) {
            idInput.value = opId;
        }
        var actionInput = document.getElementById('operator-action-input');
        if (!actionInput) {
            actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'operator_action';
            actionInput.id = 'operator-action-input';
            form.appendChild(actionInput);
        }
        actionInput.value = 'delete';
        form.submit();
    }
</script>
<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success" data-auto-close="1" style="margin-bottom: 15px; padding: 15px; border-radius: 10px; position: relative;">
        Data admin & operator tersimpan.
        <button type="button" aria-label="Close" onclick="this.parentElement.style.display='none';" style="position:absolute; right:8px; background:transparent; border:none; color:inherit; font-size:16px; cursor:pointer;">×</button>
    </div>
<?php elseif (isset($_GET['error']) && $_GET['error'] === 'empty-username'): ?>
    <div class="alert alert-danger" data-auto-close="1" style="margin-bottom: 15px; position: relative;">
        Gagal menyimpan. Username tidak boleh kosong.
        <button type="button" aria-label="Close" onclick="this.parentElement.style.display='none';" style="position:absolute; right:8px; background:transparent; border:none; color:inherit; font-size:16px; cursor:pointer;">×</button>
    </div>
<?php elseif (isset($_GET['error']) && $_GET['error'] === 'forbidden'): ?>
    <div class="alert alert-danger" data-auto-close="1" style="margin-bottom: 15px; position: relative;">
        Akses ditolak. Hubungi Superadmin.
        <button type="button" aria-label="Close" onclick="this.parentElement.style.display='none';" style="position:absolute; right:8px; background:transparent; border:none; color:inherit; font-size:16px; cursor:pointer;">×</button>
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
<form id="operator-access-form" autocomplete="off" method="post" action="./admin.php?id=operator-access" data-no-ajax="1">
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

            <div style="text-align: right; margin-top: 10px;">
                <button type="button" class="btn-action" style="width: 100%; justify-content: center; padding: 12px; margin-bottom: 10px;" onclick="openPasswordPopup()">
                    <i class="fa fa-lock"></i> Ubah Password
                </button>
                <?php if ($op_id !== '' && $op_id !== 'new'): ?>
                    <button type="submit" name="operator_action" value="delete" class="btn-action btn-outline" style="width: 100%; justify-content: center; padding: 12px; margin-bottom: 10px; border-color:#dc2626; color:#dc2626;">
                        <i class="fa fa-trash"></i> Hapus Operator
                    </button>
                <?php endif; ?>
                <button type="submit" name="save" class="btn-action btn-primary-m" style="width: 100%; justify-content: center; padding: 12px;">
                    <i class="fa fa-save"></i> Simpan Data Admin & Operator
                </button>
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

                    <div class="form-group-modern" style="margin-bottom:12px;">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                            <label class="form-label" style="margin:0;">Daftar Operator</label>
                            <a class="btn-action btn-outline" href="./admin.php?id=operator-access&op=new" style="padding:6px 10px; font-size:12px;">
                                <i class="fa fa-plus"></i> Tambah Operator
                            </a>
                        </div>
                        <div style="margin-top:8px;">
                            <?php if (empty($operators)): ?>
                                <div class="admin-empty" style="padding:10px;">Belum ada operator.</div>
                            <?php else: ?>
                                <?php foreach ($operators as $op): ?>
                                    <?php
                                        $active = !empty($op['is_active']);
                                        $is_selected = ((string)($op['id'] ?? '') === (string)$op_id);
                                    ?>
                                    <div class="router-item <?= $is_selected ? 'active-router' : ''; ?>" style="margin-bottom:8px;">
                                        <div class="router-icon"><i class="fa <?= $active ? 'fa-user' : 'fa-user-times'; ?>"></i></div>
                                        <div class="router-info">
                                            <span class="router-name"><?= htmlspecialchars($op['username']); ?></span>
                                            <span class="router-session">ID: <?= (int)$op['id']; ?> | <?= $active ? 'Aktif' : 'Nonaktif'; ?></span>
                                        </div>
                                        <div class="router-actions">
                                            <a href="./admin.php?id=operator-access&op=<?= (int)$op['id']; ?>" title="Edit"><i class="fa fa-pencil"></i></a>
                                            <a href="#" title="Hapus" onclick="submitOperatorDelete(<?= (int)$op['id']; ?>); return false;" style="margin-left:6px; color:#dc2626;"><i class="fa fa-trash"></i></a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <input id="operator-id-input" type="hidden" name="operator_id" value="<?= htmlspecialchars($op_id); ?>">

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
                            <input class="form-control-modern" type="password" name="operator_password" placeholder="<?= $op_id === 'new' ? 'Wajib untuk operator baru' : 'Kosongkan jika tidak diubah'; ?>">
                        </div>
                        <small style="display:block; margin-top:6px; color:var(--text-secondary);">
                            Operator bisa ubah lewat tombol gear di samping logout.
                        </small>
                    </div>

                    <div class="form-group-modern" style="margin-top:6px;">
                        <label class="custom-check" style="margin-bottom:0;">
                            <input type="checkbox" name="operator_active" value="1" <?= $op_active ? 'checked' : ''; ?>>
                            <span class="checkmark"></span>
                            <span class="check-label">Aktifkan Operator</span>
                        </label>
                    </div>

                    <div class="checkbox-wrapper" style="margin-top:12px;">
                        <div class="row">
                            <div class="col-6">
                                <label class="custom-check">
                                    <input type="checkbox" name="access_delete_user" <?= $perm_delete_user ? 'checked' : ''; ?>>
                                    <span class="checkmark"></span>
                                    <span class="check-label">Deleted User</span>
                                </label>
                                <label class="custom-check">
                                    <input type="checkbox" name="access_delete_block_router" <?= $perm_delete_block_router ? 'checked' : ''; ?>>
                                    <span class="checkmark"></span>
                                    <span class="check-label">Hapus Blok (Router)</span>
                                </label>
                                <label class="custom-check">
                                    <input type="checkbox" name="access_delete_block_full" <?= $perm_delete_block_full ? 'checked' : ''; ?>>
                                    <span class="checkmark"></span>
                                    <span class="check-label">Hapus Blok (Router + DB)</span>
                                </label>
                                <label class="custom-check">
                                    <input type="checkbox" name="access_audit_manual" <?= $perm_audit_manual ? 'checked' : ''; ?>>
                                    <span class="checkmark"></span>
                                    <span class="check-label">Edit Audit Manual</span>
                                </label>
                            </div>
                            <div class="col-6">
                                <label class="custom-check">
                                    <input type="checkbox" name="access_reset_settlement" <?= $perm_reset_settlement ? 'checked' : ''; ?>>
                                    <span class="checkmark"></span>
                                    <span class="check-label">Reset Settlement</span>
                                </label>
                                <label class="custom-check">
                                    <input type="checkbox" name="access_backup_only" <?= $perm_backup_only ? 'checked' : ''; ?>>
                                    <span class="checkmark"></span>
                                    <span class="check-label">Backup (DB Utama)</span>
                                </label>
                                <label class="custom-check">
                                    <input type="checkbox" name="access_restore_only" <?= $perm_restore_only ? 'checked' : ''; ?>>
                                    <span class="checkmark"></span>
                                    <span class="check-label">Restore (DB Utama)</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</form>
<?php endif; ?>
