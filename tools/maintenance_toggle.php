<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
require_once __DIR__ . '/../include/db.php';
requireLogin('../admin.php?id=login');
requireSuperAdmin('../admin.php?id=sessions');

$desired = isset($_POST['maintenance_state']) ? (int)$_POST['maintenance_state'] : null;
if ($desired !== 0 && $desired !== 1) {
    header('Location: ../admin.php?id=sessions');
    exit;
}

if (!function_exists('maintenance_db_set_enabled') || !maintenance_db_set_enabled($desired === 1)) {
    $_SESSION['wa_save_message'] = 'Gagal mengubah maintenance (DB tidak tersedia).';
    $_SESSION['wa_save_type'] = 'danger';
    header('Location: ../admin.php?id=sessions');
    exit;
}

if (function_exists('app_audit_log')) {
    app_audit_log('maintenance_toggle', $desired === 1 ? 'enabled' : 'disabled', $desired === 1 ? 'Maintenance diaktifkan.' : 'Maintenance dimatikan.', 'success', [
        'enabled' => $desired === 1 ? 1 : 0
    ]);
}

$_SESSION['wa_save_message'] = $desired === 1 ? 'Maintenance diaktifkan.' : 'Maintenance dimatikan.';
$_SESSION['wa_save_type'] = 'success';
header('Location: ../admin.php?id=sessions');
exit;
