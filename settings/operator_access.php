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

$operator_payload = [];
foreach ($operators as $op) {
    $oid = (int)($op['id'] ?? 0);
    if (!$oid) {
        continue;
    }
    $operator_payload[$oid] = [
        'id' => $oid,
        'username' => $op['username'] ?? '',
        'is_active' => !empty($op['is_active']),
        'permissions' => app_db_get_operator_permissions_for($oid),
    ];
}

$operator_defaults = [
    'id' => 'new',
    'username' => '',
    'is_active' => true,
    'permissions' => [
        'delete_user' => false,
        'delete_block_router' => false,
        'delete_block_full' => false,
        'audit_manual' => false,
        'reset_settlement' => false,
        'backup_only' => false,
        'restore_only' => false,
    ],
];
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
        var form = document.getElementById('operator-access-form');
        if (!form) return;
        var idInput = document.getElementById('operator-id-input');
        if (idInput) {
            idInput.value = opId;
        }
        var saveFlag = document.getElementById('save-operator-input');
        if (saveFlag) saveFlag.value = '';
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

    function setPopupAlert(type, text) {
        var alertEl = document.querySelector('.m-popup-backdrop.show .m-alert');
        if (!alertEl) return;
        alertEl.className = 'm-alert m-alert-' + (type || 'info');
        alertEl.classList.remove('m-popup-hidden');
        alertEl.innerHTML = '<div>' + (text || '') + '</div>';
    }

    function clearPopupAlert() {
        var alertEl = document.querySelector('.m-popup-backdrop.show .m-alert');
        if (!alertEl) return;
        alertEl.className = 'm-alert m-popup-hidden';
        alertEl.innerHTML = '';
    }

    function setHiddenInput(name, value) {
        var input = document.querySelector('input[name="' + name + '"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            document.getElementById('operator-access-form').appendChild(input);
        }
        input.value = value;
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>'"]/g, function(ch) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[ch];
        });
    }

    function clearOperatorSaveFlag() {
        var flag = document.getElementById('save-operator-input');
        if (flag) flag.value = '';
    }

    window.__operatorData = <?= json_encode($operator_payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    window.__operatorDefault = <?= json_encode($operator_defaults, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    function openOperatorPopup(opId) {
        if (!window.MikhmonPopup) return;
        clearPopupAlert();
        var isNew = !opId || opId === 'new';
        var data = isNew ? window.__operatorDefault : (window.__operatorData[opId] || window.__operatorDefault);
        var perms = data.permissions || {};

        var html = '' +
            '<div class="m-pass-form">' +
                '<div class="m-pass-row">' +
                    '<label class="m-pass-label">Username Operator</label>' +
                    '<input id="op-username" type="text" class="m-pass-input" value="' + escapeHtml(data.username || '') + '" placeholder="Username operator" />' +
                '</div>' +
                '<div class="m-pass-row">' +
                    '<label class="m-pass-label">Password Operator</label>' +
                    '<input id="op-password" type="password" class="m-pass-input" placeholder="' + (isNew ? 'Wajib untuk operator baru' : 'Kosongkan jika tidak diubah') + '" />' +
                '</div>' +
                '<div class="m-pass-row">' +
                    '<label class="custom-check" style="margin-bottom:0;">' +
                        '<input id="op-active" type="checkbox" ' + (data.is_active ? 'checked' : '') + '>' +
                        '<span class="checkmark"></span>' +
                        '<span class="check-label">Aktifkan Operator</span>' +
                    '</label>' +
                '</div>' +
                '<div class="m-pass-divider"></div>' +
                '<div class="checkbox-wrapper">' +
                    '<div class="row">' +
                        '<div class="col-6">' +
                            '<label class="custom-check">' +
                                '<input id="perm-delete-user" type="checkbox" ' + (perms.delete_user ? 'checked' : '') + '>' +
                                '<span class="checkmark"></span>' +
                                '<span class="check-label">Deleted User</span>' +
                            '</label>' +
                            '<label class="custom-check">' +
                                '<input id="perm-delete-block-router" type="checkbox" ' + (perms.delete_block_router ? 'checked' : '') + '>' +
                                '<span class="checkmark"></span>' +
                                '<span class="check-label">Hapus Blok (Router)</span>' +
                            '</label>' +
                            '<label class="custom-check">' +
                                '<input id="perm-delete-block-full" type="checkbox" ' + (perms.delete_block_full ? 'checked' : '') + '>' +
                                '<span class="checkmark"></span>' +
                                '<span class="check-label">Hapus Blok (Router + DB)</span>' +
                            '</label>' +
                            '<label class="custom-check">' +
                                '<input id="perm-audit-manual" type="checkbox" ' + (perms.audit_manual ? 'checked' : '') + '>' +
                                '<span class="checkmark"></span>' +
                                '<span class="check-label">Edit Audit Manual</span>' +
                            '</label>' +
                        '</div>' +
                        '<div class="col-6">' +
                            '<label class="custom-check">' +
                                '<input id="perm-reset-settlement" type="checkbox" ' + (perms.reset_settlement ? 'checked' : '') + '>' +
                                '<span class="checkmark"></span>' +
                                '<span class="check-label">Reset Settlement</span>' +
                            '</label>' +
                            '<label class="custom-check">' +
                                '<input id="perm-backup-only" type="checkbox" ' + (perms.backup_only ? 'checked' : '') + '>' +
                                '<span class="checkmark"></span>' +
                                '<span class="check-label">Backup (DB Utama)</span>' +
                            '</label>' +
                            '<label class="custom-check">' +
                                '<input id="perm-restore-only" type="checkbox" ' + (perms.restore_only ? 'checked' : '') + '>' +
                                '<span class="checkmark"></span>' +
                                '<span class="check-label">Restore (DB Utama)</span>' +
                            '</label>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>';

        window.MikhmonPopup.open({
            title: isNew ? 'Tambah Operator' : 'Edit Operator',
            iconClass: 'fa fa-user',
            statusIcon: 'fa fa-user',
            statusColor: '#10b981',
            cardClass: 'is-medium',
            messageHtml: html,
            buttons: [
                { label: 'Batal', className: 'm-btn m-btn-cancel' },
                {
                    label: 'Simpan',
                    className: 'm-btn m-btn-success',
                    close: false,
                    onClick: function() {
                        clearPopupAlert();
                        var username = (document.getElementById('op-username') || {}).value || '';
                        var password = (document.getElementById('op-password') || {}).value || '';
                        var active = (document.getElementById('op-active') || {}).checked;
                        if (!username.trim()) {
                            setPopupAlert('danger', 'Username operator wajib diisi.');
                            return;
                        }
                        if (isNew && !password.trim()) {
                            setPopupAlert('danger', 'Password operator baru wajib diisi.');
                            return;
                        }

                        setHiddenInput('operator_id', isNew ? 'new' : data.id);
                        setHiddenInput('operator_user', username.trim());
                        setHiddenInput('operator_password', password);
                        setHiddenInput('operator_active', active ? '1' : '');
                        setHiddenInput('access_delete_user', document.getElementById('perm-delete-user').checked ? '1' : '');
                        setHiddenInput('access_delete_block_router', document.getElementById('perm-delete-block-router').checked ? '1' : '');
                        setHiddenInput('access_delete_block_full', document.getElementById('perm-delete-block-full').checked ? '1' : '');
                        setHiddenInput('access_audit_manual', document.getElementById('perm-audit-manual').checked ? '1' : '');
                        setHiddenInput('access_reset_settlement', document.getElementById('perm-reset-settlement').checked ? '1' : '');
                        setHiddenInput('access_backup_only', document.getElementById('perm-backup-only').checked ? '1' : '');
                        setHiddenInput('access_restore_only', document.getElementById('perm-restore-only').checked ? '1' : '');
                        setHiddenInput('save_operator', '1');

                        var form = document.getElementById('operator-access-form');
                        if (form) form.submit();
                    }
                }
            ]
        });
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
                <button type="submit" name="save_admin" class="btn-action btn-primary-m" style="width: 100%; justify-content: center; padding: 12px;" onclick="clearOperatorSaveFlag()">
                    <i class="fa fa-save"></i> Simpan Admin
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
                            <a class="btn-action btn-outline" href="javascript:void(0)" onclick="openOperatorPopup('new')" style="padding:6px 10px; font-size:12px;">
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
                                            <a href="javascript:void(0)" title="Edit" onclick="openOperatorPopup(<?= (int)$op['id']; ?>)"><i class="fa fa-pencil"></i></a>
                                            <a href="javascript:void(0)" title="Hapus" onclick="submitOperatorDelete(<?= (int)$op['id']; ?>);" style="margin-left:6px; color:#dc2626;"><i class="fa fa-trash"></i></a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="admin-empty" style="padding:10px;">
                        Pilih operator untuk edit atau klik "Tambah Operator".
                    </div>

                    <input id="operator-id-input" type="hidden" name="operator_id" value="<?= htmlspecialchars($op_id); ?>">
                    <input type="hidden" id="operator-user-input" name="operator_user" value="">
                    <input type="hidden" id="operator-password-input" name="operator_password" value="">
                    <input type="hidden" id="operator-active-input" name="operator_active" value="">
                    <input type="hidden" id="operator-perm-delete-user" name="access_delete_user" value="">
                    <input type="hidden" id="operator-perm-delete-block-router" name="access_delete_block_router" value="">
                    <input type="hidden" id="operator-perm-delete-block-full" name="access_delete_block_full" value="">
                    <input type="hidden" id="operator-perm-audit-manual" name="access_audit_manual" value="">
                    <input type="hidden" id="operator-perm-reset-settlement" name="access_reset_settlement" value="">
                    <input type="hidden" id="operator-perm-backup-only" name="access_backup_only" value="">
                    <input type="hidden" id="operator-perm-restore-only" name="access_restore_only" value="">
                    <input type="hidden" id="save-operator-input" name="save_operator" value="">
                </div>
            </div>

        </div>
    </div>
</form>
<?php endif; ?>
