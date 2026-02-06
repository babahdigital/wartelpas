<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../lib/routeros_api.class.php';
require_once __DIR__ . '/../system/whatsapp/wa_helper.php';
app_db_import_legacy_if_needed();
requireLogin('../admin.php?id=login');
header('Content-Type: application/json');

$current = trim((string)($_POST['current_password'] ?? ''));
$newPass = trim((string)($_POST['new_password'] ?? ''));
$confirmPass = trim((string)($_POST['confirm_password'] ?? ''));
$opPass = trim((string)($_POST['operator_password'] ?? ''));
$opConfirm = trim((string)($_POST['operator_confirm'] ?? ''));
$opCurrent = trim((string)($_POST['operator_current'] ?? ''));
$opFullName = format_full_name_title((string)($_POST['operator_full_name'] ?? ''));
$opPhoneRaw = trim((string)($_POST['operator_phone'] ?? ''));
$opPhone = normalize_phone_to_62($opPhoneRaw);

if (isSuperAdmin() && $current === '') {
    echo json_encode(['ok' => false, 'message' => 'Password admin saat ini wajib diisi.']);
    exit;
}

if ($newPass === '' && $opPass === '' && $opFullName === '' && $opPhone === '') {
    echo json_encode(['ok' => false, 'message' => 'Tidak ada perubahan password.']);
    exit;
}

if ($newPass !== '' && $newPass !== $confirmPass) {
    echo json_encode(['ok' => false, 'message' => 'Konfirmasi password admin tidak cocok.']);
    exit;
}

if ($opPass !== '' && $opPass !== $opConfirm) {
    echo json_encode(['ok' => false, 'message' => 'Konfirmasi password operator tidak cocok.']);
    exit;
}

if ($newPass !== '' && strlen($newPass) < 6) {
    echo json_encode(['ok' => false, 'message' => 'Password admin minimal 6 karakter.']);
    exit;
}

if ($opPass !== '' && strlen($opPass) < 6) {
    echo json_encode(['ok' => false, 'message' => 'Password operator minimal 6 karakter.']);
    exit;
}

$adminPassStored = '';
$adminSource = $_SESSION['mikhmon_admin_source'] ?? '';
$adminId = (int)($_SESSION['mikhmon_admin_id'] ?? 0);
$adminUser = $_SESSION['mikhmon_admin_user'] ?? '';
if ($adminSource === 'env') {
    $envAdmin = find_env_superadmin($adminUser);
    $adminPassStored = $envAdmin['pass'] ?? '';
} elseif ($adminId > 0) {
    $adminRow = app_db_get_admin_by_id($adminId);
    $adminPassStored = $adminRow['password'] ?? '';
} else {
    $adminRow = app_db_get_admin();
    if (!empty($adminRow)) {
        $adminPassStored = $adminRow['password'] ?? '';
    }
}

$env = [];
$envFile = __DIR__ . '/../include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
$opUser = '';
$opPassStored = '';
$opId = (int)($_SESSION['mikhmon_operator_id'] ?? 0);
$opRow = $opId > 0 ? app_db_get_operator_by_id($opId) : [];
if (!empty($opRow)) {
    $opUser = $opRow['username'] ?? '';
    $opPassStored = $opRow['password'] ?? '';
}
if ($opUser === '' && $opPassStored === '') {
    $opRow = app_db_get_operator();
    if (!empty($opRow)) {
        $opUser = $opRow['username'] ?? '';
        $opPassStored = $opRow['password'] ?? '';
    }
}
if ($opUser === '' && $opPassStored === '') {
    $opUser = $env['auth']['operator_user'] ?? '';
    $opPassStored = $env['auth']['operator_pass'] ?? '';
}
$opOverride = get_operator_password_override();
if ($opOverride !== '') {
    $opPassStored = $opOverride;
}

if (!isSuperAdmin()) {
    if ($opUser === '' || $opPassStored === '') {
        echo json_encode(['ok' => false, 'message' => 'Operator belum dikonfigurasi.']);
        exit;
    }
    if (!verify_password_compat($opCurrent, $opPassStored)) {
        echo json_encode(['ok' => false, 'message' => 'Password operator saat ini salah.']);
        exit;
    }
    if ($opPhoneRaw !== '' && !is_valid_phone_08($opPhoneRaw)) {
        echo json_encode(['ok' => false, 'message' => 'Nomor telepon harus 08xxxxxxxx dan 10-13 digit.']);
        exit;
    }
} else {
    if ($adminSource === 'env') {
        if (!verify_password_compat($current, $adminPassStored)) {
            echo json_encode(['ok' => false, 'message' => 'Password admin saat ini salah.']);
            exit;
        }
        if ($newPass !== '') {
            echo json_encode(['ok' => false, 'message' => 'Akun superadmin ENV hanya bisa diubah lewat include/env.php.']);
            exit;
        }
    }
    if (!verify_password_compat($current, $adminPassStored)) {
        echo json_encode(['ok' => false, 'message' => 'Password admin saat ini salah.']);
        exit;
    }
}

$updated = [];

if (isSuperAdmin() && $newPass !== '' && $adminSource !== 'env') {
    $newHash = hash_password_value($newPass);
    $ok = update_admin_password_hash($adminPassStored, $newHash);
    if (!$ok) {
        echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan password admin.']);
        exit;
    }
    $updated[] = 'admin';
    $adminPassStored = $newHash;
}

if ($opPass !== '') {
    $opHash = hash_password_value($opPass);
    $ok = update_operator_password_hash($opHash, $opId > 0 ? $opId : null);
    if (!$ok) {
        echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan password operator.']);
        exit;
    }
    $updated[] = 'operator';
}

if (!isSuperAdmin()) {
    $opId = (int)($_SESSION['mikhmon_operator_id'] ?? 0);
    if ($opId > 0) {
        $opRow = app_db_get_operator_by_id($opId);
        if (!empty($opRow)) {
            $fullName = $opFullName !== '' ? $opFullName : ($opRow['full_name'] ?? '');
            $phone = $opPhone !== '' ? $opPhone : ($opRow['phone'] ?? '');
            app_db_update_operator($opId, $opRow['username'] ?? '', null, !empty($opRow['is_active']), $fullName, $phone);
            if ($phone !== '') {
                wa_upsert_recipient($fullName !== '' ? $fullName : ($opRow['username'] ?? ''), $phone);
            }
            if ($opFullName !== '' || $opPhone !== '') {
                $updated[] = 'profil';
            }
        }
    }
}

if (function_exists('app_audit_log')) {
    if (in_array('admin', $updated, true)) {
        app_audit_log('password_change_admin', (string)($adminUser ?? ''), 'Password admin diperbarui.', 'success', [
            'admin_id' => (int)($adminId ?? 0)
        ]);
    }
    if (in_array('operator', $updated, true)) {
        app_audit_log('password_change_operator', (string)($opUser ?? ''), 'Password operator diperbarui.', 'success', [
            'operator_id' => (int)($opId ?? 0)
        ]);
    }
    if (in_array('profil', $updated, true)) {
        app_audit_log('operator_profile_update', (string)($opUser ?? ''), 'Profil operator diperbarui.', 'success', [
            'operator_id' => (int)($opId ?? 0)
        ]);
    }
}

echo json_encode([
    'ok' => true,
    'message' => 'Password berhasil diperbarui: ' . implode(', ', $updated)
]);
