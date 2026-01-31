<?php
if (!isset($_POST['save'])) {
    return;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';

if (isOperator()) {
    echo "<script>alert('Akses ditolak. Hubungi Superadmin.'); window.location='./admin.php?id=operator-access';</script>";
    exit;
}

if (!is_writable('./include/config.php')) {
    echo "<script>alert('Gagal menyimpan. File config.php tidak bisa ditulis.'); window.location='./admin.php?id=operator-access';</script>";
    exit;
}

$suseradm = $_POST['useradm'] ?? '';
$spassadm = encrypt($_POST['passadm'] ?? '');
$qrbt = $_POST['qrbt'] ?? '';

$cari = array('1' => "mikhmon<|<$useradm", "mikhmon>|>$passadm");
$ganti = array('1' => "mikhmon<|<$suseradm", "mikhmon>|>$spassadm");

for ($i = 1; $i < 3; $i++) {
    $content = file_get_contents("./include/config.php");
    $newcontent = str_replace((string)$cari[$i], (string)$ganti[$i], "$content");
    $write_ok = file_put_contents("./include/config.php", "$newcontent");
    if ($write_ok === false) {
        echo "<script>alert('Gagal menyimpan. Periksa permission config.php.'); window.location='./admin.php?id=operator-access';</script>";
        exit;
    }
}

$gen = '<?php $qrbt="' . $qrbt . '";?>';
$key = './include/quickbt.php';
if (!is_writable($key)) {
    echo "<script>alert('Gagal menyimpan. File quickbt.php tidak bisa ditulis.'); window.location='./admin.php?id=operator-access';</script>";
    exit;
}
$handle = fopen($key, 'w') or die('Cannot open file:  ' . $key);
$data = $gen;
fwrite($handle, $data);

echo "<script>window.location='./admin.php?id=operator-access'</script>";
exit;
