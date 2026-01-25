<?php
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit();
}

$session = $_GET['session'] ?? '';
$user_param = trim((string)($_GET['user'] ?? ''));
$download = isset($_GET['download']) && $_GET['download'] == '1';
$img = isset($_GET['img']) && $_GET['img'] == '1';

if ($session === '' || $user_param === '') {
    echo "Parameter tidak lengkap.";
    exit;
}

include(__DIR__ . '/../../include/config.php');
if (!isset($data[$session])) {
    echo "Session tidak valid.";
    exit;
}
include(__DIR__ . '/../../include/readcfg.php');
include_once(__DIR__ . '/../../lib/routeros_api.class.php');
include_once(__DIR__ . '/../../lib/formatbytesbites.php');

if (stripos($user_param, 'vc-') === 0) {
    $user_param = substr($user_param, 3);
}

$API = new RouterosAPI();
$API->debug = false;
$API->timeout = 5;
$API->attempts = 1;
if (!$API->connect($iphost, $userhost, decrypt($passwdhost))) {
    echo "Tidak bisa konek ke router.";
    exit;
}

$hotspot_server = $hotspot_server ?? 'wartel';
$getuser = $API->comm("/ip/hotspot/user/print", array(
    "?server" => $hotspot_server,
    "?name" => $user_param,
    ".proplist" => ".id,name,password,profile,comment,limit-uptime,limit-bytes-total,uptime,bytes-in,bytes-out"
));

if (empty($getuser)) {
    echo "User tidak ditemukan.";
    $API->disconnect();
    exit;
}

$regtable = $getuser[0];
$username = $regtable['name'];
$password = $regtable['password'] ?? $regtable['name'];
$timelimit = $regtable['limit-uptime'] ?? '';
$comment = $regtable['comment'] ?? '';
$profile_name = $regtable['profile'] ?? '';

$getuprofile = $profile_name;
$getprofile = $API->comm("/ip/hotspot/user/profile/print", array("?name" => "$getuprofile"));
$validity = '';
$getprice = "0";
$getsprice = "0";
if (count($getprofile) > 0) {
    $ponlogin = isset($getprofile[0]['on-login']) ? $getprofile[0]['on-login'] : '';
    $parts = array_map('trim', explode(",", $ponlogin));
    $getprice = isset($parts[2]) ? $parts[2] : '0';
    $getsprice = isset($parts[4]) ? $parts[4] : '0';
    $validity = isset($parts[3]) ? $parts[3] : '';
}

if ($getsprice == "0" && $getprice != "0") {
    if (in_array($currency, $cekindo['indo'], true)) {
        $price = $currency . " " . number_format((float)$getprice, 0, ",", ".");
    } else {
        $price = $currency . " " . number_format((float)$getprice, 2);
    }
} elseif ($getsprice != "0") {
    if (in_array($currency, $cekindo['indo'], true)) {
        $price = $currency . " " . number_format((float)$getsprice, 0, ",", ".");
    } else {
        $price = $currency . " " . number_format((float)$getsprice, 2);
    }
} else {
    $price = "";
}

$logo = "../../img/logo-" . $session . ".png";
if (!file_exists(__DIR__ . "/../../img/logo-" . $session . ".png")) {
    $logo = "../../img/logo.png";
}
$logo .= "?t=" . time();

$API->disconnect();

$usermode = 'vc';
$num = 1;

if ($download && !$img) {
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="retur-'.$session.'-'.date('Ymd-His').'.html"');
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Voucher-Retur-<?= htmlspecialchars($username) ?></title>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta http-equiv="pragma" content="no-cache" />
    <link rel="icon" href="../../img/favicon.png" />
    <script src="../../js/qrious.min.js"></script>
    <?php if ($download && $img): ?>
      <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <?php endif; ?>
    <style>
        body { color: #000; background: #fff; font-size: 14px; font-family: 'Helvetica', arial, sans-serif; margin: 0; }
        table.voucher { display: inline-block; border: 2px solid black; margin: 2px; }
        @page { size: auto; margin: 5mm; }
        @media print {
          table { page-break-after:auto }
          tr { page-break-inside:avoid; page-break-after:auto }
          td { page-break-inside:avoid; page-break-after:auto }
          .no-print { display: none !important; }
        }
    </style>
</head>
<body<?php if (!$download) { echo ' onload="window.print()"'; } ?>>

<?php include(__DIR__ . '/../../voucher/template-small.php'); ?>

</body>
</html>

<?php if ($download && $img): ?>
<script>
  window.addEventListener('load', async () => {
    const vouchers = Array.from(document.querySelectorAll('table.voucher'));
    if (!vouchers.length) return;
    const images = Array.from(document.images || []);
    await Promise.all(images.map(img => new Promise(resolve => {
      if (img.complete) return resolve();
      img.addEventListener('load', resolve, { once: true });
      img.addEventListener('error', resolve, { once: true });
    })));
    await new Promise(r => setTimeout(r, 300));
    for (let i = 0; i < vouchers.length; i++) {
      const canvas = await html2canvas(vouchers[i], {scale: 2, backgroundColor: '#fff'});
      const link = document.createElement('a');
      const now = new Date();
      const pad = (n) => String(n).padStart(2, '0');
      const ts = `${now.getFullYear()}${pad(now.getMonth()+1)}${pad(now.getDate())}_${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
      const uname = vouchers[i].getAttribute('data-username') || `retur_${i+1}`;
      const safe = uname.replace(/[^A-Za-z0-9._-]/g, '_');
      link.download = `${safe}_${ts}.png`;
      link.href = canvas.toDataURL('image/png');
      link.click();
    }
  });
</script>
<?php endif; ?>
