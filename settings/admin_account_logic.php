<?php
if (!isset($_POST['save'])) {
    return;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
require_once __DIR__ . '/../include/db.php';

if (isOperator()) {
    echo "<script>alert('Akses ditolak. Hubungi Superadmin.'); window.location='./admin.php?id=operator-access';</script>";
    exit;
}

$suseradm = $_POST['useradm'] ?? '';
$new_passadm = trim((string)($_POST['passadm'] ?? ''));
$update_passadm = ($new_passadm !== '');
$spassadm = $update_passadm ? hash_password_value($new_passadm) : $passadm;

if ($suseradm === '' && !$update_passadm) {
    echo "<script>alert('Gagal menyimpan. Username tidak boleh kosong.'); window.location='./admin.php?id=operator-access';</script>";
    exit;
}

if ($suseradm === '') {
    $suseradm = $useradm;
}

app_db_set_admin($suseradm, $spassadm);

echo "<script>window.location='./admin.php?id=operator-access'</script>";
exit;
