<?php
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit();
}

$session = $_GET['session'] ?? '';
$user = trim((string)($_GET['user'] ?? ''));
$download = isset($_GET['download']) && $_GET['download'] == '1';
$img = isset($_GET['img']) && $_GET['img'] == '1';
$query_params = $_GET;
$query_params['download'] = '1';
$query_params['img'] = '1';
$download_url = basename(__FILE__) . '?' . http_build_query($query_params);
if ($user !== '' && strpos($user, '-') !== false) {
    $parts = explode('-', $user);
    $cnt = count($parts);
    $user = $cnt >= 3 ? ($parts[$cnt - 2] . '-' . $parts[$cnt - 1]) : $parts[$cnt - 1];
}

if ($session === '' || $user === '') {
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

$API = new RouterosAPI();
$API->debug = false;
$API->timeout = 5;
$API->attempts = 1;
if (!$API->connect($iphost, $userhost, decrypt($passwdhost))) {
    echo "Tidak bisa konek ke router.";
    exit;
}

$uinfo = $API->comm('/ip/hotspot/user/print', [
    '?name' => $user,
    '.proplist' => '.id,name,password,profile,comment,limit-uptime,limit-bytes-total'
]);

$urow = $uinfo[0] ?? [];
$username = $urow['name'] ?? $user;
$password = $urow['password'] ?? $user;
$profile_name = $urow['profile'] ?? '';
$comment = $urow['comment'] ?? '';
$timelimit = $urow['limit-uptime'] ?? '';

$db_comment = '';
$db_profile = '';
$db_validity = '';
$db_dir = __DIR__ . '/../../db_data/mikhmon_stats.db';
if ($user !== '' && file_exists($db_dir)) {
    try {
        $db = new PDO('sqlite:' . $db_dir);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $db->prepare("SELECT raw_comment, validity FROM login_history WHERE username = :u LIMIT 1");
        $stmt->execute([':u' => $user]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $db_comment = (string)($row['raw_comment'] ?? '');
            $db_validity = (string)($row['validity'] ?? '');
            if (preg_match('/Profile:([^|]+)/i', $db_comment, $m)) {
                $db_profile = trim($m[1]);
            }
        }
    } catch (Exception $e) {}
}

if ($comment === '' && $db_comment !== '') {
    $comment = $db_comment;
}
if ($profile_name === '' && $db_profile !== '') {
    $profile_name = $db_profile;
}

if ($comment !== '' && stripos($comment, '(Retur)') === false && stripos($comment, 'Retur Ref:') === false) {
    $comment = trim($comment) . ' (Retur)';
}

$validity = '';
$getprice = '0';
$getsprice = '0';

if ($profile_name !== '') {
    $getprofile = $API->comm('/ip/hotspot/user/profile/print', ['?name' => $profile_name]);
    if (!empty($getprofile[0]['on-login'])) {
        $ponlogin = $getprofile[0]['on-login'];
        $parts = array_map('trim', explode(',', $ponlogin));
        $getprice = $parts[2] ?? '0';
        $getsprice = $parts[4] ?? '0';
        $validity = $parts[3] ?? '';
    }
}

if ($validity === '' && $db_validity !== '') {
    $validity = $db_validity;
}

$price = '';
if ($getsprice == '0' && $getprice != '0') {
    if (in_array($currency, $cekindo['indo'], true)) {
        $price = $currency . " " . number_format((float)$getprice, 0, ",", ".");
    } else {
        $price = $currency . " " . number_format((float)$getprice, 2);
    }
} elseif ($getsprice != '0') {
    if (in_array($currency, $cekindo['indo'], true)) {
        $price = $currency . " " . number_format((float)$getsprice, 0, ",", ".");
    } else {
        $price = $currency . " " . number_format((float)$getsprice, 2);
    }
}

$logo = "../../img/logo-" . $session . ".png";
if (!file_exists(__DIR__ . '/' . $logo)) {
    $logo = "../../img/logo.png";
}
$logo .= "?t=" . time();

$usermode = 'vc';
$num = 1;
$API->disconnect();

if ($download && !$img) {
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="voucher-retur-'.$session.'-'.date('Ymd-His').'.html"');
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Voucher-<?= htmlspecialchars($hotspotname . '-' . ($profile_name ?: 'Retur')) ?></title>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta http-equiv="pragma" content="no-cache" />
        <link rel="icon" href="../../img/favicon.png" />
        <?php if ($download && $img): ?>
            <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
        <?php endif; ?>
    <style>
        body { color: #000; background: #fff; font-size: 14px; font-family: 'Helvetica', arial, sans-serif; margin: 0; }
        table.voucher { display: inline-block; border: 2px solid black; margin: 2px; }
                .toolbar { margin: 8px; display: flex; gap: 8px; }
                .btn { padding: 6px 10px; border: 1px solid #999; background: #f2f2f2; cursor: pointer; border-radius: 4px; font-size: 12px; text-decoration: none; color: #000; }
        @page { size: auto; margin: 5mm; }
        @media print {
          table { page-break-after:auto }
          tr { page-break-inside:avoid; page-break-after:auto }
          td { page-break-inside:avoid; page-break-after:auto }
                    .toolbar { display: none; }
        }
    </style>
</head>
<body<?php if (!$download) { echo ' onload="window.print()"'; } ?>>
<?php if (!$download): ?>
    <div class="toolbar">
        <button class="btn" onclick="window.print()">Print / Download PDF</button>
        <a class="btn" href="<?= htmlspecialchars($download_url); ?>">Download PNG</a>
    </div>
<?php endif; ?>
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
            const uname = vouchers[i].getAttribute('data-username') || `voucher_${i+1}`;
            const safe = uname.replace(/[^A-Za-z0-9._-]/g, '_');
            link.download = `${safe}_${ts}.png`;
            link.href = canvas.toDataURL('image/png');
            link.click();
        }
    });
</script>
<?php endif; ?>
