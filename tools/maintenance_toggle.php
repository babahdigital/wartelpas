<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
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

$_SESSION['wa_save_message'] = $desired === 1 ? 'Maintenance diaktifkan.' : 'Maintenance dimatikan.';
$_SESSION['wa_save_type'] = 'success';
header('Location: ../admin.php?id=sessions');
exit;
