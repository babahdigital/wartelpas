<?php
if (!isset($_POST['save'])) {
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

$suseradm = $_POST['useradm'] ?? '';
$sopuser = $_POST['operator_user'] ?? '';
$new_passadm = trim((string)($_POST['passadm'] ?? ''));
$update_passadm = ($new_passadm !== '');

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

if ($sopuser !== '') {
    $op_row = app_db_get_operator();
    $op_pass = $op_row['password'] ?? '';
    if ($op_pass === '') {
        $env = getEnvConfig();
        $op_pass = $env['auth']['operator_pass'] ?? '';
    }
    app_db_set_operator($sopuser, $op_pass);
}

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'message' => 'Data admin & operator tersimpan.']);
    exit;
}
header('Location: ./admin.php?id=operator-access&saved=1');
exit;
