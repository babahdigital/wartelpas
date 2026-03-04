<?php
$_SERVER['REQUEST_URI'] = '/cli';
$session = 'S3c7x9_LB';

$root = '/var/www/html';
require $root . '/include/config.php';
require $root . '/include/readcfg.php';
require $root . '/lib/routeros_api.class.php';
$env = [];
$envFile = $root . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}

$systemCfg = $env['system'] ?? [];
$baseUrl = rtrim((string)($systemCfg['base_url'] ?? ''), '/');
$localBaseUrl = rtrim((string)($systemCfg['local_base_url'] ?? ''), '/');
if ($localBaseUrl === '') {
    $localBaseUrl = $baseUrl;
}

$liveKey = (string)($env['security']['live_ingest']['token'] ?? '');
$usageKey = (string)($env['security']['usage_ingest']['token'] ?? '');
if ($liveKey === '') $liveKey = (string)($env['backup']['secret'] ?? '');
if ($usageKey === '') $usageKey = (string)($env['backup']['secret'] ?? '');

$tmplOnlogin = $root . '/tools/onlogin';
$tmplOnlogout = $root . '/tools/onlogout';
if (!file_exists($tmplOnlogin) || !file_exists($tmplOnlogout)) {
    echo "TEMPLATE|MISSING\n";
    exit(1);
}

$replace = [
    '{{BASE_URL}}' => $baseUrl,
    '{{LOCAL_BASE_URL}}' => $localBaseUrl,
    '{{LIVE_KEY}}' => $liveKey,
    '{{USAGE_KEY}}' => $usageKey,
    '{{SESSION}}' => $session,
];
$onlogin = str_replace(array_keys($replace), array_values($replace), file_get_contents($tmplOnlogin));
$onlogout = str_replace(array_keys($replace), array_values($replace), file_get_contents($tmplOnlogout));

$profile10 = (string)($env['profiles']['profile_10'] ?? '10Menit');
$profile30 = (string)($env['profiles']['profile_30'] ?? '30Menit');
$targets = array_values(array_unique(array_filter([$profile10, $profile30])));

$API = new RouterosAPI();
$API->debug = false;
if (!$API->connect($iphost, $userhost, decrypt($passwdhost))) {
    echo "CONNECT|FAIL\n";
    exit(1);
}
echo "CONNECT|OK\n";
echo 'TARGETS|' . implode(',', $targets) . "\n";

foreach ($targets as $name) {
    $rows = $API->comm('/ip/hotspot/user/profile/print', ['?name' => $name, '.proplist' => '.id,name,on-login,on-logout']);
    if (!is_array($rows) || empty($rows[0]['.id'])) {
        echo 'PROFILE|NOT_FOUND|' . $name . "\n";
        continue;
    }

    $id = (string)$rows[0]['.id'];
    $oldLoginLen = strlen((string)($rows[0]['on-login'] ?? ''));
    $oldLogoutLen = strlen((string)($rows[0]['on-logout'] ?? ''));

    $API->comm('/ip/hotspot/user/profile/set', [
        '.id' => $id,
        'on-login' => $onlogin,
        'on-logout' => $onlogout,
    ]);

    $verify = $API->comm('/ip/hotspot/user/profile/print', ['?.id' => $id, '.proplist' => 'name,on-login,on-logout']);
    $newLoginLen = strlen((string)($verify[0]['on-login'] ?? ''));
    $newLogoutLen = strlen((string)($verify[0]['on-logout'] ?? ''));

    echo 'PROFILE|UPDATED|' . $name
        . '|old_login=' . $oldLoginLen
        . '|new_login=' . $newLoginLen
        . '|old_logout=' . $oldLogoutLen
        . '|new_logout=' . $newLogoutLen . "\n";
}

$API->disconnect();
