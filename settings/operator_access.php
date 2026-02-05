<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
require_once __DIR__ . '/../include/db.php';
app_db_import_legacy_if_needed();
requireLogin('../admin.php?id=login');

if (!empty($_GET['error']) || isset($_GET['saved'])) {
    $flashMap = [
        'empty-username' => ['danger', 'Gagal menyimpan. Username tidak boleh kosong.'],
        'forbidden' => ['danger', 'Akses ditolak. Hubungi Superadmin.'],
        'invalid-phone' => ['danger', 'Nomor telepon harus 08xxxxxxxx dan 10-13 digit.'],
        'duplicate-operator' => ['danger', 'Username operator sudah digunakan.'],
        'duplicate-admin' => ['danger', 'Username admin sudah digunakan.'],
        'invalid-username' => ['danger', 'Username hanya huruf kecil dan angka, tanpa spasi/simbol.'],
        'minimum-admin' => ['danger', 'Minimal harus ada 1 admin aktif.'],
        'not-found' => ['danger', 'Data tidak ditemukan.'],
        'empty-phone' => ['danger', 'Nomor WA belum diisi.'],
        'invalid-password' => ['danger', 'Konfirmasi password tidak cocok.'],
    ];
    if (isset($_GET['saved'])) {
        $_SESSION['operator_access_flash'] = ['success', 'Data admin & operator tersimpan.'];
    } elseif (!empty($_GET['error'])) {
        $code = (string)$_GET['error'];
        $_SESSION['operator_access_flash'] = $flashMap[$code] ?? ['danger', 'Terjadi kesalahan.'];
    }
    header('Location: ./admin.php?id=operator-access');
    exit;
}

$env = [];
$envFile = __DIR__ . '/../include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}

require_once __DIR__ . '/admin_account_logic.php';


$admins = app_db_list_admins();
$selected_admin_id = $_GET['adm'] ?? '';
$selected_admin = null;
$is_new_admin = ($selected_admin_id === 'new');
if (!$is_new_admin && $selected_admin_id !== '') {
    $selected_admin = app_db_get_admin_by_id((int)$selected_admin_id);
}
if (!$selected_admin && !$is_new_admin && !empty($admins)) {
    $selected_admin = app_db_get_admin_by_id((int)($admins[0]['id'] ?? 0));
}

$admin_id = $is_new_admin ? 'new' : (string)($selected_admin['id'] ?? '');
$admin_user = $selected_admin['username'] ?? '';
$admin_active = $is_new_admin ? true : !empty($selected_admin['is_active']);

$admin_payload = [];
foreach ($admins as $adm) {
    $aid = (int)($adm['id'] ?? 0);
    if (!$aid) {
        continue;
    }
    $phone_display = format_phone_display($adm['phone'] ?? '');
    $admin_payload[$aid] = [
        'id' => $aid,
        'username' => $adm['username'] ?? '',
        'full_name' => $adm['full_name'] ?? '',
        'phone' => $phone_display,
        'is_active' => !empty($adm['is_active']),
    ];
}

$admin_defaults = [
    'id' => 'new',
    'username' => '',
    'full_name' => '',
    'phone' => '',
    'is_active' => true,
];


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
    $phone_display = format_phone_display($op['phone'] ?? '');
    $operator_payload[$oid] = [
        'id' => $oid,
        'username' => $op['username'] ?? '',
        'full_name' => $op['full_name'] ?? '',
        'phone' => $phone_display,
        'is_active' => !empty($op['is_active']),
        'permissions' => app_db_get_operator_permissions_for($oid),
    ];
}

$operator_defaults = [
    'id' => 'new',
    'username' => '',
    'full_name' => '',
    'phone' => '',
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
    window.__currentUser = <?= json_encode((string)($_SESSION['mikhmon'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    window.__currentAdminSource = <?= json_encode((string)($_SESSION['mikhmon_admin_source'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
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

    function normalizePhoneInput(el) {
        if (!el) return;
        var v = (el.value || '').replace(/\D/g, '');
        if (v.indexOf('62') === 0) {
            v = '0' + v.slice(2);
        }
        if (v.indexOf('8') === 0) {
            v = '0' + v;
        }
        if (v.indexOf('0') !== 0) {
            v = '0' + v;
        }
        if (v.length >= 2 && v.slice(0, 2) !== '08') {
            v = '08' + v.slice(2);
        }
        el.value = v.slice(0, 13);
    }

    function attachPhoneInput(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.setAttribute('inputmode', 'numeric');
        el.setAttribute('pattern', '[0-9]*');
        el.setAttribute('minlength', '10');
        el.setAttribute('maxlength', '13');
        el.addEventListener('input', function() { normalizePhoneInput(el); });
        normalizePhoneInput(el);
    }

    function clearOperatorSaveFlag() {
        var flag = document.getElementById('save-operator-input');
        if (flag) flag.value = '';
    }

    window.__operatorData = <?= json_encode($operator_payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    window.__operatorDefault = <?= json_encode($operator_defaults, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    window.__adminData = <?= json_encode($admin_payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    window.__adminDefault = <?= json_encode($admin_defaults, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    function submitAdminDelete(adminId) {
        if (!adminId) return;
        var form = document.getElementById('operator-access-form');
        if (!form) return;
        var idInput = document.getElementById('admin-id-input');
        if (idInput) {
            idInput.value = adminId;
        }
        var saveFlag = document.getElementById('save-admin-input');
        if (saveFlag) saveFlag.value = '';
        var actionInput = document.getElementById('admin-action-input');
        if (!actionInput) {
            actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'admin_action';
            actionInput.id = 'admin-action-input';
            form.appendChild(actionInput);
        }
        actionInput.value = 'delete';
        form.submit();
    }

    function submitAdminReset(adminId, phone) {
        if (!adminId) return;
        if (!phone || !phone.trim()) {
            setPopupAlert('danger', 'Nomor WA admin belum diisi.');
            return;
        }
        var form = document.getElementById('operator-access-form');
        if (!form) return;
        setHiddenInput('admin_id', adminId);
        setHiddenInput('admin_phone', phone.trim());
        setHiddenInput('save_admin', '');
        setHiddenInput('save_operator', '');
        setHiddenInput('admin_action', 'reset');
        form.submit();
    }

    function openAdminPopup(adminId) {
        if (!window.MikhmonPopup) return;
        clearPopupAlert();
        var isNew = !adminId || adminId === 'new';
        var data = isNew ? window.__adminDefault : (window.__adminData[adminId] || window.__adminDefault);
        var currentUser = (window.__currentUser || '').toString().toLowerCase();
        var isSelf = !isNew && data.username && data.username.toString().toLowerCase() === currentUser;

        var html = '' +
            '<div class="m-pass-form">' +
                '<div class="m-pass-row">' +
                    '<label class="m-pass-label">Nama Lengkap</label>' +
                    '<input id="adm-full-name" type="text" class="m-pass-input" value="' + escapeHtml(data.full_name || '') + '" placeholder="Nama lengkap" />' +
                '</div>' +
                '<div class="row">' +
                    '<div class="col-6">' +
                        '<div class="m-pass-row">' +
                            '<label class="m-pass-label">Username Admin</label>' +
                            '<input id="adm-username" type="text" class="m-pass-input" value="' + escapeHtml(data.username || '') + '" placeholder="Username admin" oninput="this.value=this.value.toLowerCase().replace(/[^a-z0-9]/g, \'\');" />' +
                        '</div>' +
                    '</div>' +
                    '<div class="col-6">' +
                        '<div class="m-pass-row">' +
                            '<label class="m-pass-label">Nomor Telepon</label>' +
                            '<input id="adm-phone" type="text" class="m-pass-input" value="' + escapeHtml(data.phone || '') + '" placeholder="08xxxxxxxxxx" />' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="m-pass-row" style="margin-top:6px;">' +
                    '<small style="color:var(--text-secondary);">Password dibuat otomatis dan dikirim via WhatsApp.</small>' +
                '</div>' +
                (isSelf ?
                '<div class="row" style="margin-top:10px;">' +
                    '<div class="col-6">' +
                        '<div class="m-pass-row">' +
                            '<label class="m-pass-label">Password Baru</label>' +
                            '<input id="adm-password" type="password" class="m-pass-input" placeholder="Minimal 6 karakter" />' +
                        '</div>' +
                    '</div>' +
                    '<div class="col-6">' +
                        '<div class="m-pass-row">' +
                            '<label class="m-pass-label">Konfirmasi Password</label>' +
                            '<input id="adm-password-confirm" type="password" class="m-pass-input" placeholder="Ulangi password" />' +
                        '</div>' +
                    '</div>' +
                '</div>'
                : '') +
                '<div class="m-pass-row">' +
                    '<label class="custom-check" style="margin-bottom:0;">' +
                        '<input id="adm-active" type="checkbox" ' + (data.is_active ? 'checked' : '') + '>' +
                        '<span class="checkmark"></span>' +
                        '<span class="check-label">Aktifkan Admin</span>' +
                    '</label>' +
                '</div>' +
            '</div>';

        window.MikhmonPopup.open({
            title: isNew ? 'Tambah Admin' : 'Edit Admin',
            iconClass: 'fa fa-user-secret',
            statusIcon: 'fa fa-user-secret',
            statusColor: '#3b82f6',
            cardClass: 'is-medium',
            messageHtml: html,
            buttons: [
                !isNew ? {
                    label: 'Reset Password',
                    className: 'm-btn m-btn-warning',
                    close: false,
                    onClick: function() {
                        var phone = (document.getElementById('adm-phone') || {}).value || '';
                        submitAdminReset(data.id, phone);
                    }
                } : null,
                { label: 'Batal', className: 'm-btn m-btn-cancel' },
                {
                    label: 'Simpan',
                    className: 'm-btn m-btn-success',
                    close: false,
                    onClick: function() {
                        clearPopupAlert();
                        var username = (document.getElementById('adm-username') || {}).value || '';
                        var fullName = (document.getElementById('adm-full-name') || {}).value || '';
                        var phone = (document.getElementById('adm-phone') || {}).value || '';
                        var active = (document.getElementById('adm-active') || {}).checked;
                        var pass = (document.getElementById('adm-password') || {}).value || '';
                        var passConfirm = (document.getElementById('adm-password-confirm') || {}).value || '';
                        username = username.trim().toLowerCase().replace(/[^a-z0-9]/g, '');
                        fullName = fullName.trim().toLowerCase().replace(/\b\w+/g, function(w){ return w.charAt(0).toUpperCase() + w.slice(1); });
                        if (!username) {
                            setPopupAlert('danger', 'Username admin wajib diisi.');
                            return;
                        }
                        if (!/^[a-z0-9]+$/.test(username)) {
                            setPopupAlert('danger', 'Username admin hanya huruf kecil dan angka, tanpa spasi/simbol.');
                            return;
                        }
                        if (isSelf && (pass.trim() !== '' || passConfirm.trim() !== '')) {
                            if (pass.trim().length < 6) {
                                setPopupAlert('danger', 'Password minimal 6 karakter.');
                                return;
                            }
                            if (pass.trim() !== passConfirm.trim()) {
                                setPopupAlert('danger', 'Konfirmasi password tidak cocok.');
                                return;
                            }
                        }

                        setHiddenInput('admin_id', isNew ? 'new' : data.id);
                        setHiddenInput('admin_user', username);
                        setHiddenInput('admin_full_name', fullName);
                        setHiddenInput('admin_phone', phone.trim());
                        setHiddenInput('admin_active', active ? '1' : '');
                        if (isSelf && pass.trim() !== '') {
                            setHiddenInput('admin_password', pass.trim());
                            setHiddenInput('admin_password_confirm', passConfirm.trim());
                        }
                        setHiddenInput('save_operator', '');
                        setHiddenInput('save_admin', '1');

                        var form = document.getElementById('operator-access-form');
                        if (form) form.submit();
                    }
                }
            ].filter(Boolean)
        });
        setTimeout(function(){ attachPhoneInput('adm-phone'); }, 0);
    }

    function openOperatorPopup(opId) {
        if (!window.MikhmonPopup) return;
        clearPopupAlert();
        var isNew = !opId || opId === 'new';
        var data = isNew ? window.__operatorDefault : (window.__operatorData[opId] || window.__operatorDefault);
        var perms = data.permissions || {};

        var html = '' +
            '<div class="m-pass-form">' +
                '<div class="m-pass-row">' +
                    '<label class="m-pass-label">Nama Lengkap</label>' +
                    '<input id="op-full-name" type="text" class="m-pass-input" value="' + escapeHtml(data.full_name || '') + '" placeholder="Nama lengkap" />' +
                '</div>' +
                '<div class="row">' +
                    '<div class="col-6">' +
                        '<div class="m-pass-row">' +
                            '<label class="m-pass-label">Username Operator</label>' +
                            '<input id="op-username" type="text" class="m-pass-input" value="' + escapeHtml(data.username || '') + '" placeholder="Username operator" oninput="this.value=this.value.toLowerCase().replace(/[^a-z0-9]/g, \'\');" />' +
                        '</div>' +
                    '</div>' +
                    '<div class="col-6">' +
                        '<div class="m-pass-row">' +
                            '<label class="m-pass-label">Nomor Telepon</label>' +
                            '<input id="op-phone" type="text" class="m-pass-input" value="' + escapeHtml(data.phone || '') + '" placeholder="08xxxxxxxxxx" />' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="m-pass-row" style="margin-top:6px;">' +
                    '<small style="color:var(--text-secondary);">Password dibuat otomatis dan dikirim via WhatsApp.</small>' +
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
                !isNew ? {
                    label: 'Reset Password',
                    className: 'm-btn m-btn-warning',
                    close: false,
                    onClick: function() {
                        var phone = (document.getElementById('op-phone') || {}).value || '';
                        submitOperatorReset(data.id, phone);
                    }
                } : null,
                { label: 'Batal', className: 'm-btn m-btn-cancel' },
                {
                    label: 'Simpan',
                    className: 'm-btn m-btn-success',
                    close: false,
                    onClick: function() {
                        clearPopupAlert();
                        var username = (document.getElementById('op-username') || {}).value || '';
                        var fullName = (document.getElementById('op-full-name') || {}).value || '';
                        var phone = (document.getElementById('op-phone') || {}).value || '';
                        var active = (document.getElementById('op-active') || {}).checked;
                        username = username.trim().toLowerCase().replace(/[^a-z0-9]/g, '');
                        fullName = fullName.trim().toLowerCase().replace(/\b\w+/g, function(w){ return w.charAt(0).toUpperCase() + w.slice(1); });
                        if (!username) {
                            setPopupAlert('danger', 'Username operator wajib diisi.');
                            return;
                        }
                        if (!/^[a-z0-9]+$/.test(username)) {
                            setPopupAlert('danger', 'Username operator hanya huruf kecil dan angka, tanpa spasi/simbol.');
                            return;
                        }

                        setHiddenInput('operator_id', isNew ? 'new' : data.id);
                        setHiddenInput('operator_user', username);
                        setHiddenInput('operator_full_name', fullName);
                        setHiddenInput('operator_phone', phone.trim());
                        setHiddenInput('operator_active', active ? '1' : '');
                        setHiddenInput('access_delete_user', document.getElementById('perm-delete-user').checked ? '1' : '');
                        setHiddenInput('access_delete_block_router', document.getElementById('perm-delete-block-router').checked ? '1' : '');
                        setHiddenInput('access_delete_block_full', document.getElementById('perm-delete-block-full').checked ? '1' : '');
                        setHiddenInput('access_audit_manual', document.getElementById('perm-audit-manual').checked ? '1' : '');
                        setHiddenInput('access_reset_settlement', document.getElementById('perm-reset-settlement').checked ? '1' : '');
                        setHiddenInput('access_backup_only', document.getElementById('perm-backup-only').checked ? '1' : '');
                        setHiddenInput('access_restore_only', document.getElementById('perm-restore-only').checked ? '1' : '');
                        setHiddenInput('save_admin', '');
                        setHiddenInput('save_operator', '1');

                        var form = document.getElementById('operator-access-form');
                        if (form) form.submit();
                    }
                }
            ].filter(Boolean)
        });
        setTimeout(function(){ attachPhoneInput('op-phone'); }, 0);
    }

    function submitOperatorReset(opId, phone) {
        if (!opId) return;
        if (!phone || !phone.trim()) {
            setPopupAlert('danger', 'Nomor WA operator belum diisi.');
            return;
        }
        var form = document.getElementById('operator-access-form');
        if (!form) return;
        setHiddenInput('operator_id', opId);
        setHiddenInput('operator_phone', phone.trim());
        setHiddenInput('save_operator', '');
        setHiddenInput('save_admin', '');
        setHiddenInput('operator_action', 'reset');
        form.submit();
    }
<?php
$flash = $_SESSION['operator_access_flash'] ?? null;
if ($flash) {
    unset($_SESSION['operator_access_flash']);
}
?>
</script>
<?php if (!empty($flash) && is_array($flash)): ?>
    <?php
        $flashType = ($flash[0] ?? 'info') === 'success' ? 'success' : 'danger';
        $flashText = (string)($flash[1] ?? '');
    ?>
    <div class="alert alert-<?= $flashType; ?>" data-auto-close="1" style="margin-bottom: 15px; padding: 15px; border-radius: 10px; position: relative;">
        <?= htmlspecialchars($flashText, ENT_QUOTES, 'UTF-8'); ?>
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
                    <h3><i class="fa fa-user-secret"></i> Akun Administrator</h3>
                    <small class="badge" style="background:var(--success); color:#0f172a;">Level: Superadmin</small>
                </div>
                <div class="card-body-modern">
                    <div class="form-group-modern" style="margin-bottom:12px;">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                            <label class="form-label" style="margin:0;">Daftar Admin</label>
                            <a class="btn-action btn-outline" href="javascript:void(0)" onclick="openAdminPopup('new')" style="padding:6px 10px; font-size:12px;">
                                <i class="fa fa-plus"></i> Tambah Admin
                            </a>
                        </div>
                        <div style="margin-top:8px;">
                            <?php if (empty($admins)): ?>
                                <div class="admin-empty" style="padding:10px;">Belum ada admin.</div>
                            <?php else: ?>
                                <?php foreach ($admins as $adm): ?>
                                    <?php
                                        $active = !empty($adm['is_active']);
                                        $is_selected = ((string)($adm['id'] ?? '') === (string)$admin_id);
                                        $meta = [];
                                        if (!empty($adm['full_name'])) {
                                            $meta[] = 'Nama: ' . $adm['full_name'];
                                        }
                                        if (!empty($adm['phone'])) {
                                            $meta[] = 'HP: ' . format_phone_display($adm['phone']);
                                        }
                                        $metaText = $meta ? ' | ' . implode(' | ', $meta) : '';
                                    ?>
                                    <div class="router-item <?= $is_selected ? 'active-router' : ''; ?>" style="margin-bottom:8px;">
                                        <div class="router-icon"><i class="fa <?= $active ? 'fa-user-secret' : 'fa-user-times'; ?>"></i></div>
                                        <div class="router-info">
                                            <span class="router-name"><?= htmlspecialchars($adm['username']); ?></span>
                                            <span class="router-session">ID: <?= (int)$adm['id']; ?> | <?= $active ? 'Aktif' : 'Nonaktif'; ?><?= htmlspecialchars($metaText); ?></span>
                                        </div>
                                        <div class="router-actions">
                                            <a href="javascript:void(0)" title="Edit" onclick="openAdminPopup(<?= (int)$adm['id']; ?>)"><i class="fa fa-pencil"></i></a>
                                            <a href="javascript:void(0)" title="Hapus" onclick="submitAdminDelete(<?= (int)$adm['id']; ?>);" style="margin-left:6px; color:#dc2626;"><i class="fa fa-trash"></i></a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="admin-empty" style="padding:10px;">
                        Admin adalah user untuk management lengkap "Tambah Admin".
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
                                        $meta = [];
                                        if (!empty($op['full_name'])) {
                                            $meta[] = 'Nama: ' . $op['full_name'];
                                        }
                                        if (!empty($op['phone'])) {
                                            $meta[] = 'HP: ' . format_phone_display($op['phone']);
                                        }
                                        $metaText = $meta ? ' | ' . implode(' | ', $meta) : '';
                                    ?>
                                    <div class="router-item <?= $is_selected ? 'active-router' : ''; ?>" style="margin-bottom:8px;">
                                        <div class="router-icon"><i class="fa <?= $active ? 'fa-user' : 'fa-user-times'; ?>"></i></div>
                                        <div class="router-info">
                                            <span class="router-name"><?= htmlspecialchars($op['username']); ?></span>
                                            <span class="router-session">ID: <?= (int)$op['id']; ?> | <?= $active ? 'Aktif' : 'Nonaktif'; ?><?= htmlspecialchars($metaText); ?></span>
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
                    <input type="hidden" id="operator-full-name-input" name="operator_full_name" value="">
                    <input type="hidden" id="operator-phone-input" name="operator_phone" value="">
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
                    <input id="admin-id-input" type="hidden" name="admin_id" value="<?= htmlspecialchars($admin_id); ?>">
                    <input type="hidden" id="admin-user-input" name="admin_user" value="">
                    <input type="hidden" id="admin-full-name-input" name="admin_full_name" value="">
                    <input type="hidden" id="admin-phone-input" name="admin_phone" value="">
                    <input type="hidden" id="admin-password-input" name="admin_password" value="">
                    <input type="hidden" id="admin-active-input" name="admin_active" value="">
                    <input type="hidden" id="save-admin-input" name="save_admin" value="">
                </div>
            </div>

        </div>
    </div>
</form>
<?php endif; ?>
