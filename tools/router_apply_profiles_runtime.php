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

if (!function_exists('renderRouterProfileScript')) {
    function renderRouterProfileScript($templatePath, array $replace)
    {
        $raw = (string)file_get_contents($templatePath);
        $rendered = str_replace(array_keys($replace), array_values($replace), $raw);
        $rendered = str_replace(["\r\n", "\r"], "\n", $rendered);
        $lines = explode("\n", $rendered);
        $parts = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }
            if (strpos($line, '#') === 0) {
                continue;
            }
            $parts[] = $line;
        }
        $oneLine = trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
        return $oneLine;
    }
}

$replace = [
    '{{BASE_URL}}' => $baseUrl,
    '{{LOCAL_BASE_URL}}' => $localBaseUrl,
    '{{LIVE_KEY}}' => $liveKey,
    '{{USAGE_KEY}}' => $usageKey,
    '{{SESSION}}' => $session,
];
$onlogin = renderRouterProfileScript($tmplOnlogin, $replace);
$onlogout = renderRouterProfileScript($tmplOnlogout, $replace);
$onloginScriptName = 'wartelpas_onlogin';
$onlogoutScriptName = 'wartelpas_onlogout';
$onloginHook = '/system script run ' . $onloginScriptName;
$onlogoutHook = '/system script run ' . $onlogoutScriptName;

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
echo 'SCRIPT_LEN|onlogin=' . strlen($onlogin) . '|onlogout=' . strlen($onlogout) . "\n";

$scripts = $API->comm('/system/script/print', ['.proplist' => '.id,name,source,comment']);
if (!is_array($scripts)) {
    $scripts = [];
}

$upsertScript = static function ($api, array $scripts, $scriptName, $scriptSource, $commentTag) {
    $scriptName = trim((string)$scriptName);
    $found = null;
    foreach ($scripts as $s) {
        if (strcasecmp((string)($s['name'] ?? ''), $scriptName) === 0) {
            $found = $s;
            break;
        }
    }

    $res = null;
    if ($found && !empty($found['.id'])) {
        $res = $api->comm('/system/script/set', [
            '.id' => (string)$found['.id'],
            'name' => $scriptName,
            'source' => $scriptSource,
            'comment' => $commentTag,
        ]);
    } else {
        $res = $api->comm('/system/script/add', [
            'name' => $scriptName,
            'source' => $scriptSource,
            'comment' => $commentTag,
        ]);
    }

    $status = 'OK';
    if (is_array($res)) {
        foreach ($res as $chunk) {
            if (is_array($chunk) && isset($chunk['!trap'])) {
                $status = 'TRAP';
                break;
            }
        }
    }
    return $status;
};

$upLogin = $upsertScript($API, $scripts, $onloginScriptName, $onlogin, 'wartelpas-runtime');
$upLogout = $upsertScript($API, $scripts, $onlogoutScriptName, $onlogout, 'wartelpas-runtime');
echo 'HOOK_SCRIPT|updated|' . $onloginScriptName . '|status=' . $upLogin . '|len=' . strlen($onlogin) . "\n";
echo 'HOOK_SCRIPT|updated|' . $onlogoutScriptName . '|status=' . $upLogout . '|len=' . strlen($onlogout) . "\n";

$scriptsVerify = $API->comm('/system/script/print', ['.proplist' => '.id,name,source,comment']);
if (!is_array($scriptsVerify)) {
    $scriptsVerify = [];
}
foreach ($scriptsVerify as $sv) {
    $nm = (string)($sv['name'] ?? '');
    if (strcasecmp($nm, $onloginScriptName) === 0 || strcasecmp($nm, $onlogoutScriptName) === 0) {
        echo 'HOOK_SCRIPT|verify|' . $nm . '|source_len=' . strlen((string)($sv['source'] ?? '')) . "\n";
    }
}

$profiles = $API->comm('/ip/hotspot/user/profile/print', ['.proplist' => '.id,name,on-login,on-logout']);
if (!is_array($profiles)) {
    $profiles = [];
}

$findProfile = static function (array $profiles, $targetName) {
    $targetName = trim((string)$targetName);
    if ($targetName === '') {
        return null;
    }
    $targetNorm = strtolower(preg_replace('/\s+/', '', $targetName));
    foreach ($profiles as $row) {
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        if (strcasecmp($name, $targetName) === 0) {
            return $row;
        }
        $nameNorm = strtolower(preg_replace('/\s+/', '', $name));
        if ($nameNorm === $targetNorm) {
            return $row;
        }
    }
    return null;
};

foreach ($targets as $name) {
    $profileRow = $findProfile($profiles, $name);
    if (!$profileRow || empty($profileRow['.id'])) {
        echo 'PROFILE|NOT_FOUND|' . $name . "\n";
        continue;
    }

    $id = (string)$profileRow['.id'];
    $profileName = (string)($profileRow['name'] ?? $name);
    $oldLoginLen = strlen((string)($profileRow['on-login'] ?? ''));
    $oldLogoutLen = strlen((string)($profileRow['on-logout'] ?? ''));

    $setRes = $API->comm('/ip/hotspot/user/profile/set', [
        '.id' => $id,
        'on-login' => $onloginHook,
        'on-logout' => $onlogoutHook,
    ]);

    $setStatus = 'OK';
    if (is_array($setRes)) {
        foreach ($setRes as $chunk) {
            if (is_array($chunk) && isset($chunk['!trap'])) {
                $setStatus = 'TRAP';
                break;
            }
        }
    }

    $verifyRows = $API->comm('/ip/hotspot/user/profile/print', ['.proplist' => '.id,name,on-login,on-logout']);
    if (!is_array($verifyRows)) {
        $verifyRows = [];
    }
    $verifyRow = null;
    foreach ($verifyRows as $vr) {
        if ((string)($vr['.id'] ?? '') === $id) {
            $verifyRow = $vr;
            break;
        }
    }
    if (!$verifyRow) {
        $verifyRow = ['on-login' => '', 'on-logout' => ''];
    }

    $newLoginLen = strlen((string)($verifyRow['on-login'] ?? ''));
    $newLogoutLen = strlen((string)($verifyRow['on-logout'] ?? ''));

    echo 'PROFILE|UPDATED|' . $profileName
        . '|set=' . $setStatus
        . '|old_login=' . $oldLoginLen
        . '|new_login=' . $newLoginLen
        . '|old_logout=' . $oldLogoutLen
        . '|new_logout=' . $newLogoutLen . "\n";
}

$API->disconnect();
