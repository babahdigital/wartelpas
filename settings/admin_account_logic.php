<?php
if (!isset($_POST['save_admin']) && !isset($_POST['save_operator']) && (($_POST['operator_action'] ?? '') !== 'delete')) {
    return;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
require_once __DIR__ . '/../include/db.php';

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (isOperator()) {
    if ($isAjax) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Akses ditolak. Hubungi Superadmin.']);
        exit;
    }
    header('Location: ./admin.php?id=operator-access&error=forbidden');
    exit;
}

$save_admin = isset($_POST['save_admin']);
$save_operator = isset($_POST['save_operator']);

$suseradm = $_POST['useradm'] ?? '';
$sopuser = $_POST['operator_user'] ?? '';
$op_id = $_POST['operator_id'] ?? '';
$op_action = $_POST['operator_action'] ?? '';
$op_active = !empty($_POST['operator_active']);
$op_password = trim((string)($_POST['operator_password'] ?? ''));
$perm_delete_user = !empty($_POST['access_delete_user']);
$perm_delete_block_router = !empty($_POST['access_delete_block_router']);
$perm_delete_block_full = !empty($_POST['access_delete_block_full']);
$perm_audit_manual = !empty($_POST['access_audit_manual']);
$perm_reset_settlement = !empty($_POST['access_reset_settlement']);
$perm_backup_only = !empty($_POST['access_backup_only']);
$perm_restore_only = !empty($_POST['access_restore_only']);
$new_passadm = trim((string)($_POST['passadm'] ?? ''));
$update_passadm = ($new_passadm !== '');

if ($op_action === 'delete' && $op_id !== '' && $op_id !== 'new') {
    app_db_delete_operator((int)$op_id);
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'message' => 'Operator dihapus.']);
        exit;
    }
    header('Location: ./admin.php?id=operator-access&saved=1');
    exit;
}

if ($save_admin) {
    $admin_row = app_db_get_admin();
    $current_admin_user = $admin_row['username'] ?? '';
    $current_admin_pass = $admin_row['password'] ?? '';

    $spassadm = $update_passadm ? hash_password_value($new_passadm) : $current_admin_pass;

    if ($suseradm === '' && !$update_passadm) {
        if ($isAjax) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan. Username tidak boleh kosong.']);
            exit;
        }
        header('Location: ./admin.php?id=operator-access&error=empty-username');
        exit;
    }

    if ($suseradm === '') {
        $suseradm = $current_admin_user;
    }

    app_db_set_admin($suseradm, $spassadm);
}

if ($save_operator) {
    if ($sopuser !== '') {
        if ($op_id === 'new' || $op_id === '') {
            if ($op_password === '') {
                if ($isAjax) {
                    http_response_code(400);
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'message' => 'Password operator baru wajib diisi.']);
                    exit;
                }
                header('Location: ./admin.php?id=operator-access&error=empty-username');
                exit;
            }
            $op_hash = hash_password_value($op_password);
            $new_id = app_db_create_operator($sopuser, $op_hash, $op_active);
            $op_id = (string)$new_id;
        } else {
            $op_hash = $op_password !== '' ? hash_password_value($op_password) : null;
            app_db_update_operator((int)$op_id, $sopuser, $op_hash, $op_active);
        }
    }

    if ($op_id !== '' && $op_id !== 'new') {
        app_db_set_operator_permissions_for((int)$op_id, [
            'delete_user' => $perm_delete_user,
            'delete_block_router' => $perm_delete_block_router,
            'delete_block_full' => $perm_delete_block_full,
            'audit_manual' => $perm_audit_manual,
            'reset_settlement' => $perm_reset_settlement,
            'backup_only' => $perm_backup_only,
            'restore_only' => $perm_restore_only,
        ]);
    }
}
if ($isAjax) {
    header('Content-Type: application/json');
    if ($save_admin && $save_operator) {
        echo json_encode(['ok' => true, 'message' => 'Data admin & operator tersimpan.']);
    } elseif ($save_admin) {
        echo json_encode(['ok' => true, 'message' => 'Data admin tersimpan.']);
    } elseif ($save_operator) {
        echo json_encode(['ok' => true, 'message' => 'Data operator tersimpan.']);
    } else {
        echo json_encode(['ok' => true, 'message' => 'Data tersimpan.']);
    }
    exit;
}
header('Location: ./admin.php?id=operator-access&saved=1');
exit;
