<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
requireLogin('../admin.php?id=login');
requireSuperAdmin('../admin.php?id=sessions');
require_once __DIR__ . '/../include/app_settings.php';

$desired = isset($_POST['todo_wa_state']) ? (int)$_POST['todo_wa_state'] : null;
if ($desired !== 0 && $desired !== 1) {
    header('Location: ../admin.php?id=sessions');
    exit;
}

app_setting_set('todo_wa_enabled', $desired === 1 ? '1' : '0');
$_SESSION['wa_save_message'] = $desired === 1 ? 'Notifikasi WA Todo diaktifkan.' : 'Notifikasi WA Todo dimatikan.';
$_SESSION['wa_save_type'] = 'success';
header('Location: ../admin.php?id=sessions');
exit;
