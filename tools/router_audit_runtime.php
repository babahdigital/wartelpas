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

echo "CONNECT|OK\n";
$scripts = $API->comm('/system/script/print', ['?comment' => 'mikhmon', '.proplist' => '.id,name,source,owner,comment']);
if (!is_array($scripts)) $scripts = [];
echo 'SCRIPT_MIKHMON_COUNT|' . count($scripts) . "\n";

$sample = array_slice($scripts, 0, 5);
foreach ($sample as $i => $s) {
    $nm = str_replace(["\n", "\r", "|"], [' ', ' ', '/'], (string)($s['name'] ?? ''));
    echo 'SCRIPT_SAMPLE|' . ($i + 1) . '|' . $nm . "\n";
}

$profiles = $API->comm('/ip/hotspot/user/profile/print', ['.proplist' => '.id,name,on-login,on-logout']);
if (!is_array($profiles)) $profiles = [];
echo 'PROFILE_COUNT|' . count($profiles) . "\n";

$missing = [];
foreach ($profiles as $p) {
    $name = (string)($p['name'] ?? '');
    $onLogin = strtolower((string)($p['on-login'] ?? ''));
    $onLogout = strtolower((string)($p['on-logout'] ?? ''));
    $okLogin = (strpos($onLogin, '/system script add') !== false)
        && (strpos($onLogin, 'live_ingest.php') !== false)
        && (strpos($onLogin, 'usage_ingest.php') !== false);
    $okLogout = (strpos($onLogout, 'usage_ingest.php') !== false);
    if (!($okLogin && $okLogout)) {
        $missing[] = $name;
    }
}

echo 'PROFILE_MISSING_COUNT|' . count($missing) . "\n";
foreach ($missing as $name) {
    echo 'PROFILE_MISSING|' . $name . "\n";
}

$API->disconnect();
