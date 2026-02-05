<?php
if (empty($_POST['save_admin']) && empty($_POST['save_operator']) && !in_array(($_POST['operator_action'] ?? ''), ['delete', 'reset'], true) && !in_array(($_POST['admin_action'] ?? ''), ['delete', 'reset'], true)) {
    return;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../system/whatsapp/wa_helper.php';

$isAjax = false;

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

$save_admin = !empty($_POST['save_admin']);
$save_operator = !empty($_POST['save_operator']);

$admin_id = $_POST['admin_id'] ?? '';
$admin_user = trim((string)($_POST['admin_user'] ?? ''));
$admin_password = trim((string)($_POST['admin_password'] ?? ''));
$admin_password_confirm = trim((string)($_POST['admin_password_confirm'] ?? ''));
$admin_active = !empty($_POST['admin_active']);
$admin_full_name = format_full_name_title((string)($_POST['admin_full_name'] ?? ''));
$admin_phone_raw = trim((string)($_POST['admin_phone'] ?? ''));
$admin_phone = normalize_phone_to_62($admin_phone_raw);
$admin_action = $_POST['admin_action'] ?? '';

$suseradm = $_POST['useradm'] ?? '';
$sopuser = strtolower(trim((string)($_POST['operator_user'] ?? '')));
$op_id = $_POST['operator_id'] ?? '';
$op_action = $_POST['operator_action'] ?? '';
$op_active = !empty($_POST['operator_active']);
$op_password = trim((string)($_POST['operator_password'] ?? ''));
$op_full_name = format_full_name_title((string)($_POST['operator_full_name'] ?? ''));
$op_phone_raw = trim((string)($_POST['operator_phone'] ?? ''));
$op_phone = normalize_phone_to_62($op_phone_raw);
$perm_delete_user = !empty($_POST['access_delete_user']);
$perm_delete_block_router = !empty($_POST['access_delete_block_router']);
$perm_delete_block_full = !empty($_POST['access_delete_block_full']);
$perm_audit_manual = !empty($_POST['access_audit_manual']);
$perm_reset_settlement = !empty($_POST['access_reset_settlement']);
$perm_backup_only = !empty($_POST['access_backup_only']);
$perm_restore_only = !empty($_POST['access_restore_only']);
$new_passadm = trim((string)($_POST['passadm'] ?? ''));
$update_passadm = ($new_passadm !== '');

$current_admin_id = (int)($_SESSION['mikhmon_admin_id'] ?? 0);
$current_admin_user = strtolower((string)($_SESSION['mikhmon'] ?? ''));
$admin_source = (string)($_SESSION['mikhmon_admin_source'] ?? '');

function generate_numeric_password($length = 6)
{
    $max = (10 ** (int)$length) - 1;
    $num = random_int(0, max(0, $max));
    return str_pad((string)$num, (int)$length, '0', STR_PAD_LEFT);
}

function account_actor_context()
{
    $user = (string)($_SESSION['mikhmon'] ?? '');
    $role = isSuperAdmin() ? 'superadmin' : (isOperator() ? 'operator' : 'user');
    $display = $user;
    if (isOperator()) {
        $opId = (int)($_SESSION['mikhmon_operator_id'] ?? 0);
        if ($opId > 0) {
            $opRow = app_db_get_operator_by_id($opId);
            $fullName = (string)($opRow['full_name'] ?? '');
            if ($fullName !== '') $display = $fullName;
        }
    } else {
        $adminId = (int)($_SESSION['mikhmon_admin_id'] ?? 0);
        if ($adminId > 0) {
            $adminRow = app_db_get_admin_by_id($adminId);
            $fullName = (string)($adminRow['full_name'] ?? '');
            if ($fullName !== '') $display = $fullName;
        }
    }
    return [$display !== '' ? $display : $user, $role];
}

if ($admin_action === 'delete' && $admin_id !== '' && $admin_id !== 'new') {
    $del_id = (int)$admin_id;
    $adminRow = app_db_get_admin_by_id($del_id);
    $adminUser = (string)($adminRow['username'] ?? '');
    $adminName = (string)($adminRow['full_name'] ?? '');
    $adminPhone = (string)($adminRow['phone'] ?? '');
    if ($del_id === $current_admin_id) {
        if ($isAjax) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Tidak bisa menghapus admin yang sedang login.']);
            exit;
        }
        header('Location: ./admin.php?id=operator-access&error=forbidden');
        exit;
    }
    $admins = app_db_list_admins();
    $envAdmins = get_env_superadmins();
    $hasEnvAdmin = !empty($envAdmins);
    if (count($admins) <= 1 && !$hasEnvAdmin) {
        if ($isAjax) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Minimal harus ada 1 admin aktif.']);
            exit;
        }
        header('Location: ./admin.php?id=operator-access&error=minimum-admin');
        exit;
    }
    app_db_delete_admin($del_id);
    wa_delete_recipient($adminPhone, $adminName !== '' ? $adminName : $adminUser);
    if ($adminUser !== '') {
        $root_dir = dirname(__DIR__);
        $session_dirs = [
            $root_dir . '/session',
            $root_dir . '/sessions',
            $root_dir . '/cache',
            $root_dir . '/tmp'
        ];
        foreach ($session_dirs as $sdir) {
            if (!is_dir($sdir)) continue;
            $pattern = $sdir . '/*' . $adminUser . '*';
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'message' => 'Admin dihapus.']);
        exit;
    }
    header('Location: ./admin.php?id=operator-access&saved=1');
    exit;
}

if ($admin_action === 'reset' && $admin_id !== '' && $admin_id !== 'new') {
    $aid = (int)$admin_id;
    $adminRow = app_db_get_admin_by_id($aid);
    $adminUser = $adminRow['username'] ?? '';
    $adminName = $admin_full_name !== '' ? $admin_full_name : ($adminRow['full_name'] ?? '');
    $adminPhone = $admin_phone !== '' ? $admin_phone : ($adminRow['phone'] ?? '');
    if ($adminUser === '') {
        if ($isAjax) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Admin tidak ditemukan.']);
            exit;
        }
        header('Location: ./admin.php?id=operator-access&error=not-found');
        exit;
    }
    if ($adminPhone === '') {
            if ($admin_phone_raw !== '' && !is_valid_phone_08($admin_phone_raw)) {
                if ($isAjax) {
                    http_response_code(400);
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'message' => 'Nomor WA admin harus 08xxxxxxxx dan 10-13 digit.']);
                    exit;
                }
                header('Location: ./admin.php?id=operator-access&error=invalid-phone');
                exit;
            }
        if ($isAjax) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Nomor WA admin belum diisi.']);
            exit;
        }
        header('Location: ./admin.php?id=operator-access&error=empty-phone');
        exit;
    }

    $newPass = generate_numeric_password(6);
    $newHash = hash_password_value($newPass);
    app_db_update_admin($aid, $adminUser, $newHash, !empty($adminRow['is_active']), $adminName, $adminPhone);
    wa_upsert_recipient($adminName !== '' ? $adminName : $adminUser, $adminPhone);
    [$actorUser, $actorRole] = account_actor_context();
    wa_send_template_message('account_reset', [
        'full_name' => $adminName !== '' ? $adminName : $adminUser,
        'username' => $adminUser,
        'role' => 'ADMIN',
        'password' => $newPass,
        'created_by' => $actorUser,
        'created_by_role' => strtoupper($actorRole),
        'date' => date('d-m-Y H:i'),
    ], $adminPhone);

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'message' => 'Password admin direset & dikirim WA.']);
        exit;
    }
    header('Location: ./admin.php?id=operator-access&saved=1');
    exit;
}

if ($op_action === 'delete' && $op_id !== '' && $op_id !== 'new') {
    $opRow = app_db_get_operator_by_id((int)$op_id);
    $opUser = (string)($opRow['username'] ?? '');
    $opName = (string)($opRow['full_name'] ?? '');
    $opPhone = (string)($opRow['phone'] ?? '');
    app_db_delete_operator((int)$op_id);
    wa_delete_recipient($opPhone, $opName !== '' ? $opName : $opUser);
    if (count(app_db_list_operators()) === 0) {
        try {
            $pdo = app_db();
            $pdo->exec('DELETE FROM operator_account');
            $pdo->exec('DELETE FROM operator_permissions');
        } catch (Exception $e) {}
    }
    if ($opUser !== '') {
        $root_dir = dirname(__DIR__);
        $session_dirs = [
            $root_dir . '/session',
            $root_dir . '/sessions',
            $root_dir . '/cache',
            $root_dir . '/tmp'
        ];
        foreach ($session_dirs as $sdir) {
            if (!is_dir($sdir)) continue;
            $pattern = $sdir . '/*' . $opUser . '*';
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'message' => 'Operator dihapus.']);
        exit;
    }
    header('Location: ./admin.php?id=operator-access&saved=1');
    exit;
}

if ($op_action === 'reset' && $op_id !== '' && $op_id !== 'new') {
    $oid = (int)$op_id;
    $opRow = app_db_get_operator_by_id($oid);
    $opUserRow = $opRow['username'] ?? '';
    $opName = $op_full_name !== '' ? $op_full_name : ($opRow['full_name'] ?? '');
    $opPhone = $op_phone !== '' ? $op_phone : ($opRow['phone'] ?? '');
    if ($opUserRow === '') {
        if ($isAjax) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Operator tidak ditemukan.']);
            exit;
        }
        header('Location: ./admin.php?id=operator-access&error=not-found');
        exit;
    }
    if ($opPhone === '') {
            if ($op_phone_raw !== '' && !is_valid_phone_08($op_phone_raw)) {
                if ($isAjax) {
                    http_response_code(400);
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'message' => 'Nomor WA operator harus 08xxxxxxxx dan 10-13 digit.']);
                    exit;
                }
                header('Location: ./admin.php?id=operator-access&error=invalid-phone');
                exit;
            }
        if ($isAjax) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Nomor WA operator belum diisi.']);
            exit;
        }
        header('Location: ./admin.php?id=operator-access&error=empty-phone');
        exit;
    }

    $newPass = generate_numeric_password(6);
    $newHash = hash_password_value($newPass);
    app_db_update_operator($oid, $opUserRow, $newHash, !empty($opRow['is_active']), $opName, $opPhone);
    wa_upsert_recipient($opName !== '' ? $opName : $opUserRow, $opPhone);
    [$actorUser, $actorRole] = account_actor_context();
    wa_send_template_message('account_reset', [
        'full_name' => $opName !== '' ? $opName : $opUserRow,
        'username' => $opUserRow,
        'role' => 'OPERATOR',
        'password' => $newPass,
        'created_by' => $actorUser,
        'created_by_role' => strtoupper($actorRole),
        'date' => date('d-m-Y H:i'),
    ], $opPhone);

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'message' => 'Password operator direset & dikirim WA.']);
        exit;
    }
    header('Location: ./admin.php?id=operator-access&saved=1');
    exit;
}

if ($save_admin) {
        if ($admin_phone_raw !== '' && !is_valid_phone_08($admin_phone_raw)) {
            if ($isAjax) {
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'message' => 'Nomor WA admin harus 08xxxxxxxx dan 10-13 digit.']);
                exit;
            }
            header('Location: ./admin.php?id=operator-access&error=invalid-phone');
            exit;
        }
    $admin_user = strtolower($admin_user);
    if (!is_valid_simple_username($admin_user)) {
        if ($isAjax) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Username admin hanya huruf kecil dan angka, tanpa spasi/simbol.']);
            exit;
        }
        header('Location: ./admin.php?id=operator-access&error=invalid-username');
        exit;
    }
    if ($admin_user === '') {
        if ($isAjax) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Username admin wajib diisi.']);
            exit;
        }
        header('Location: ./admin.php?id=operator-access&error=empty-username');
        exit;
    }

    if ($admin_password !== '') {
        if ($admin_user !== $current_admin_user) {
            if ($isAjax) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'message' => 'Hanya pemilik akun yang bisa ubah password.']);
                exit;
            }
            header('Location: ./admin.php?id=operator-access&error=forbidden');
            exit;
        }
        if ($admin_source === 'env') {
            if ($isAjax) {
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'message' => 'Akun ENV hanya bisa diubah lewat file env.']);
                exit;
            }
            header('Location: ./admin.php?id=operator-access&error=forbidden');
            exit;
        }
        if ($admin_password_confirm === '' || $admin_password_confirm !== $admin_password) {
            if ($isAjax) {
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'message' => 'Konfirmasi password tidak cocok.']);
                exit;
            }
            header('Location: ./admin.php?id=operator-access&error=invalid-password');
            exit;
        }
        if (strlen($admin_password) < 6) {
            if ($isAjax) {
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'message' => 'Password minimal 6 karakter.']);
                exit;
            }
            header('Location: ./admin.php?id=operator-access&error=invalid-password');
            exit;
        }
    }

    if (($admin_id === 'new' || $admin_id === '') && $admin_phone !== '') {
        $admin_plain_pass = $admin_password !== '' ? $admin_password : generate_numeric_password(6);
        $admin_hash = hash_password_value($admin_plain_pass);
        try {
            app_db_create_admin($admin_user, $admin_hash, $admin_active, $admin_full_name, $admin_phone);
        } catch (Exception $e) {
            if ($isAjax) {
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'message' => 'Username admin sudah digunakan.']);
                exit;
            }
            header('Location: ./admin.php?id=operator-access&error=duplicate-admin');
            exit;
        }
    } else {
        $admin_hash = $admin_password !== '' ? hash_password_value($admin_password) : null;
        try {
            $adminRow = app_db_get_admin_by_id((int)$admin_id);
            $fullName = $admin_full_name !== '' ? $admin_full_name : ($adminRow['full_name'] ?? '');
            $phone = $admin_phone !== '' ? $admin_phone : ($adminRow['phone'] ?? '');
            app_db_update_admin((int)$admin_id, $admin_user, $admin_hash, $admin_active, $fullName, $phone);
            $admin_full_name = $fullName;
            $admin_phone = $phone;
        } catch (Exception $e) {
            if ($isAjax) {
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'message' => 'Username admin sudah digunakan.']);
                exit;
            }
            header('Location: ./admin.php?id=operator-access&error=duplicate-admin');
            exit;
        }
    }

    if ($admin_phone !== '') {
        wa_upsert_recipient($admin_full_name !== '' ? $admin_full_name : $admin_user, $admin_phone);
    }

    if ($admin_id === 'new' || $admin_id === '') {
        [$actorUser, $actorRole] = account_actor_context();
        wa_send_template_message('account_register', [
            'full_name' => $admin_full_name !== '' ? $admin_full_name : $admin_user,
            'username' => $admin_user,
            'role' => 'ADMIN',
            'password' => $admin_plain_pass,
            'created_by' => $actorUser,
            'created_by_role' => strtoupper($actorRole),
            'date' => date('d-m-Y H:i'),
        ], $admin_phone);
    }
}

if ($save_operator) {
    if ($op_phone_raw !== '' && !is_valid_phone_08($op_phone_raw)) {
        if ($isAjax) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Nomor WA operator harus 08xxxxxxxx dan 10-13 digit.']);
            exit;
        }
        header('Location: ./admin.php?id=operator-access&error=invalid-phone');
        exit;
    }
    if ($sopuser !== '' && !is_valid_simple_username($sopuser)) {
        if ($isAjax) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Username operator hanya huruf kecil dan angka, tanpa spasi/simbol.']);
            exit;
        }
        header('Location: ./admin.php?id=operator-access&error=invalid-username');
        exit;
    }
    if ($sopuser !== '') {
        if ($op_id === 'new' || $op_id === '') {
            $op_plain_pass = $op_password !== '' ? $op_password : generate_numeric_password(6);
            $op_hash = hash_password_value($op_plain_pass);
            try {
                $new_id = app_db_create_operator($sopuser, $op_hash, $op_active, $op_full_name, $op_phone);
                $op_id = (string)$new_id;
            } catch (Exception $e) {
                if ($isAjax) {
                    http_response_code(400);
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'message' => 'Username operator sudah digunakan.']);
                    exit;
                }
                header('Location: ./admin.php?id=operator-access&error=duplicate-operator');
                exit;
            }
        } else {
            $op_hash = $op_password !== '' ? hash_password_value($op_password) : null;
            try {
                $opRow = app_db_get_operator_by_id((int)$op_id);
                $fullName = $op_full_name !== '' ? $op_full_name : ($opRow['full_name'] ?? '');
                $phone = $op_phone !== '' ? $op_phone : ($opRow['phone'] ?? '');
                app_db_update_operator((int)$op_id, $sopuser, $op_hash, $op_active, $fullName, $phone);
                $op_full_name = $fullName;
                $op_phone = $phone;
            } catch (Exception $e) {
                if ($isAjax) {
                    http_response_code(400);
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'message' => 'Username operator sudah digunakan.']);
                    exit;
                }
                header('Location: ./admin.php?id=operator-access&error=duplicate-operator');
                exit;
            }
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

    if ($op_phone !== '') {
        wa_upsert_recipient($op_full_name !== '' ? $op_full_name : $sopuser, $op_phone);
    }

    if ($op_phone !== '' && $op_id !== '' && $op_id !== 'new' && isset($op_plain_pass) && $op_plain_pass !== '') {
        [$actorUser, $actorRole] = account_actor_context();
        wa_send_template_message('account_register', [
            'full_name' => $op_full_name !== '' ? $op_full_name : $sopuser,
            'username' => $sopuser,
            'role' => 'OPERATOR',
            'password' => $op_plain_pass,
            'created_by' => $actorUser,
            'created_by_role' => strtoupper($actorRole),
            'date' => date('d-m-Y H:i'),
        ], $op_phone);
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
