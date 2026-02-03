<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../lib/routeros_api.class.php';
app_db_import_legacy_if_needed();
requireLogin('../admin.php?id=login');
header('Content-Type: application/json');

$current = trim((string)($_POST['current_password'] ?? ''));
$newPass = trim((string)($_POST['new_password'] ?? ''));
$confirmPass = trim((string)($_POST['confirm_password'] ?? ''));
$opPass = trim((string)($_POST['operator_password'] ?? ''));
$opConfirm = trim((string)($_POST['operator_confirm'] ?? ''));
$opCurrent = trim((string)($_POST['operator_current'] ?? ''));

if (isSuperAdmin() && $current === '') {
    echo json_encode(['ok' => false, 'message' => 'Password admin saat ini wajib diisi.']);
    exit;
}

if ($newPass === '' && $opPass === '') {
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
$adminRow = app_db_get_admin();
if (!empty($adminRow)) {
    $adminPassStored = $adminRow['password'] ?? '';
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
} else {
    if (!verify_password_compat($current, $adminPassStored)) {
        echo json_encode(['ok' => false, 'message' => 'Password admin saat ini salah.']);
        exit;
    }
}

$updated = [];

if (isSuperAdmin() && $newPass !== '') {
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

echo json_encode([
    'ok' => true,
    'message' => 'Password berhasil diperbarui: ' . implode(', ', $updated)
]);
