<?php
$_SERVER['REQUEST_URI'] = '/cli';
$session = 'S3c7x9_LB';
require '/var/www/html/include/config.php';
require '/var/www/html/include/readcfg.php';
require '/var/www/html/lib/routeros_api.class.php';

$API = new RouterosAPI();
$API->debug = false;
if (!$API->connect($iphost, $userhost, decrypt($passwdhost))) {
    echo "CONNECT|FAIL\n";
    exit(1);
}

$rows = $API->comm('/ip/hotspot/user/profile/print', ['.proplist' => '.id,name,on-login,on-logout']);
if (!is_array($rows)) {
    $rows = [];
}
foreach ($rows as $r) {
    $name = trim((string)($r['name'] ?? ''));
    if ($name !== '10Menit' && $name !== '30Menit') {
        continue;
    }
    $id = (string)($r['.id'] ?? '');
    $printLogin = (string)($r['on-login'] ?? '');
    $printLogout = (string)($r['on-logout'] ?? '');

    $g1 = $API->comm('/ip/hotspot/user/profile/get', ['.id' => $id, 'value-name' => 'on-login']);
    $g2 = $API->comm('/ip/hotspot/user/profile/get', ['.id' => $id, 'value-name' => 'on-logout']);

    $v1 = is_array($g1) && isset($g1['ret']) ? (string)$g1['ret'] : (is_array($g1) && isset($g1[0]['ret']) ? (string)$g1[0]['ret'] : (is_string($g1) ? $g1 : ''));
    $v2 = is_array($g2) && isset($g2['ret']) ? (string)$g2['ret'] : (is_array($g2) && isset($g2[0]['ret']) ? (string)$g2[0]['ret'] : (is_string($g2) ? $g2 : ''));

    echo 'PROFILE|' . $name . '|id=' . $id . '|print_login_len=' . strlen($printLogin) . '|get_login_len=' . strlen($v1) . "\n";
    echo 'PROFILE|' . $name . '|print_login=' . str_replace(["\n", "\r", '|'], [' ', ' ', '/'], $printLogin) . "\n";
    echo 'PROFILE|' . $name . '|get_login=' . str_replace(["\n", "\r", '|'], [' ', ' ', '/'], $v1) . "\n";
    echo 'PROFILE|' . $name . '|print_logout_len=' . strlen($printLogout) . '|get_logout_len=' . strlen($v2) . "\n";
    echo 'PROFILE|' . $name . '|get_logout=' . str_replace(["\n", "\r", '|'], [' ', ' ', '/'], $v2) . "\n";
}
$API->disconnect();
