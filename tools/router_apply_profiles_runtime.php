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

if (!function_exists('normalizeRouterValue')) {
    function normalizeRouterValue($value)
    {
        $value = (string)$value;
        $value = str_replace(["\r", "\n", "\t"], ' ', $value);
        return trim($value);
    }
}

$systemCfg = $env['system'] ?? [];
$baseUrl = rtrim(normalizeRouterValue($systemCfg['base_url'] ?? ''), '/');
$localBaseUrl = rtrim(normalizeRouterValue($systemCfg['local_base_url'] ?? ''), '/');
if ($localBaseUrl === '') {
    $localBaseUrl = $baseUrl;
}

$liveKey = normalizeRouterValue($env['security']['live_ingest']['token'] ?? '');
$usageKey = normalizeRouterValue($env['security']['usage_ingest']['token'] ?? '');
if ($liveKey === '') $liveKey = normalizeRouterValue($env['backup']['secret'] ?? '');
if ($usageKey === '') $usageKey = normalizeRouterValue($env['backup']['secret'] ?? '');
$liveKey = rtrim($liveKey, '=');
$usageKey = rtrim($usageKey, '=');
$session = normalizeRouterValue($session);

$profilesCfg = (isset($env['profiles']) && is_array($env['profiles'])) ? $env['profiles'] : [];
$pricingCfg = (isset($env['pricing']) && is_array($env['pricing'])) ? $env['pricing'] : [];
$profile10 = trim((string)($profilesCfg['profile_10'] ?? '10Menit'));
$profile30 = trim((string)($profilesCfg['profile_30'] ?? '30Menit'));
if ($profile10 === '') {
    $profile10 = '10Menit';
}
if ($profile30 === '') {
    $profile30 = '30Menit';
}
$price10 = (int)($pricingCfg['price_10'] ?? 5000);
$price30 = (int)($pricingCfg['price_30'] ?? 20000);
if ($price10 <= 0) {
    $price10 = 5000;
}
if ($price30 <= 0) {
    $price30 = 20000;
}
$profilePriceMap = (isset($pricingCfg['profile_prices']) && is_array($pricingCfg['profile_prices'])) ? $pricingCfg['profile_prices'] : [];
$targets = array_values(array_unique(array_filter([$profile10, $profile30])));

$normalizeProfileKey = static function ($value) {
    $value = strtolower(trim((string)$value));
    if ($value === '') {
        return '';
    }
    return preg_replace('/\s+/', '', $value);
};

$resolveProfilePrice = static function ($profileName) use ($normalizeProfileKey, $profilePriceMap, $profile10, $profile30, $price10, $price30) {
    $targetKey = $normalizeProfileKey($profileName);
    if ($targetKey === '') {
        return $price10;
    }

    foreach ($profilePriceMap as $mapKey => $mapPrice) {
        if ($normalizeProfileKey($mapKey) === $targetKey && (int)$mapPrice > 0) {
            return (int)$mapPrice;
        }
    }

    if ($targetKey === $normalizeProfileKey($profile30)) {
        return $price30;
    }
    if ($targetKey === $normalizeProfileKey($profile10)) {
        return $price10;
    }

    if (preg_match('/30(menit|m)?/i', $targetKey)) {
        return $price30;
    }
    if (preg_match('/10(menit|m)?/i', $targetKey)) {
        return $price10;
    }

    return $price10;
};

$tmplOnlogin = $root . '/tools/onlogin.rsc';
$tmplOnlogout = $root . '/tools/onlogout.rsc';
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
        $oneLine = trim((string)preg_replace('/\s+/', ' ', implode(' ', $parts)));
        return normalizeRouterValue($oneLine);
    }
}

$replace = [
    '{{BASE_URL}}' => $baseUrl,
    '{{LOCAL_BASE_URL}}' => $localBaseUrl,
    '{{LIVE_KEY}}' => $liveKey,
    '{{USAGE_KEY}}' => $usageKey,
    '{{SESSION}}' => $session,
    '{{PRICE_10}}' => (string)$price10,
    '{{PRICE_30}}' => (string)$price30,
    '{{PROFILE_10}}' => str_replace('"', '', $profile10),
    '{{PROFILE_30}}' => str_replace('"', '', $profile30),
];
$onlogin = renderRouterProfileScript($tmplOnlogin, $replace);
$onlogout = renderRouterProfileScript($tmplOnlogout, $replace);
$onloginScriptName = 'wartelpas_onlogin';
$onlogoutScriptName = 'wartelpas_onlogout';
$onloginHook = '/system script run ' . $onloginScriptName;
$onlogoutHook = '/system script run ' . $onlogoutScriptName;

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

$extractTrap = static function ($res) {
    if (!is_array($res)) {
        return '';
    }
    if (isset($res['!trap'][0]['message'])) {
        return (string)$res['!trap'][0]['message'];
    }
    if (isset($res['!trap']['message'])) {
        return (string)$res['!trap']['message'];
    }
    foreach ($res as $chunk) {
        if (is_array($chunk) && isset($chunk['!trap'])) {
            if (isset($chunk['!trap'][0]['message'])) {
                return (string)$chunk['!trap'][0]['message'];
            }
            if (isset($chunk['!trap']['message'])) {
                return (string)$chunk['!trap']['message'];
            }
        }
    }
    return '';
};

$readScriptByName = static function ($api, $scriptName) {
    $scriptName = trim((string)$scriptName);
    if ($scriptName === '') {
        return [
            'id' => '',
            'source' => '',
            'len' => 0,
        ];
    }

    $rows = $api->comm('/system/script/print', ['.proplist' => '.id,name,source']);
    if (!is_array($rows)) {
        $rows = [];
    }

    foreach ($rows as $row) {
        if (strcasecmp((string)($row['name'] ?? ''), $scriptName) === 0) {
            $src = (string)($row['source'] ?? '');
            return [
                'id' => (string)($row['.id'] ?? ''),
                'source' => $src,
                'len' => strlen($src),
            ];
        }
    }

    return [
        'id' => '',
        'source' => '',
        'len' => 0,
    ];
};

$upsertScript = static function ($api, array $scripts, $scriptName, $scriptSource, $commentTag, $extractTrap, $readScriptByName) {
    $scriptName = trim((string)$scriptName);
    $found = null;
    foreach ($scripts as $s) {
        if (strcasecmp((string)($s['name'] ?? ''), $scriptName) === 0) {
            $found = $s;
            break;
        }
    }

    $expectedLen = strlen((string)$scriptSource);
    $needsLive = (stripos($scriptSource, 'live_ingest.php') !== false);
    $needsUsage = (stripos($scriptSource, 'usage_ingest.php') !== false);
    $minLen = max(120, (int)floor($expectedLen * 0.8));

    $variants = [
        ['mode' => 'raw', 'source' => $scriptSource],
        ['mode' => 'hex3d', 'source' => str_replace('=', '\\3D', $scriptSource)],
        ['mode' => 'bslash', 'source' => str_replace('=', '\\=', $scriptSource)],
    ];

    $lastTrap = '';
    $lastLen = 0;
    $lastMode = 'raw';

    foreach ($variants as $variant) {
        $variantMode = (string)$variant['mode'];
        $variantSource = (string)$variant['source'];

        $res = null;
        if ($found && !empty($found['.id'])) {
            $res = $api->comm('/system/script/set', [
                '.id' => (string)$found['.id'],
                'name' => $scriptName,
                'source' => $variantSource,
                'comment' => $commentTag,
            ]);
        } else {
            $res = $api->comm('/system/script/add', [
                'name' => $scriptName,
                'source' => $variantSource,
                'comment' => $commentTag,
            ]);
        }

        $trap = $extractTrap($res);
        $current = $readScriptByName($api, $scriptName);
        $currentSource = (string)($current['source'] ?? '');
        $currentLen = (int)($current['len'] ?? 0);
        $hasLive = (stripos($currentSource, 'live_ingest.php') !== false);
        $hasUsage = (stripos($currentSource, 'usage_ingest.php') !== false);
        $markerOk = (!$needsLive || $hasLive) && (!$needsUsage || $hasUsage);
        $lenOk = ($currentLen >= $minLen);

        $lastTrap = $trap;
        $lastLen = $currentLen;
        $lastMode = $variantMode;

        if ($trap === '' && $lenOk && $markerOk) {
            return [
                'status' => 'OK',
                'trap' => '',
                'mode' => $variantMode,
                'written_len' => strlen($variantSource),
                'verify_len' => $currentLen,
            ];
        }
    }

    $trap = $lastTrap;
    if ($trap === '') {
        $trap = 'verify_failed: source_len=' . $lastLen . ' expected>=' . $minLen;
    }

    return [
        'status' => 'TRAP',
        'trap' => $trap,
        'mode' => $lastMode,
        'written_len' => 0,
        'verify_len' => $lastLen,
    ];
};

$upLogin = $upsertScript($API, $scripts, $onloginScriptName, $onlogin, 'wartelpas-runtime', $extractTrap, $readScriptByName);
$upLogout = $upsertScript($API, $scripts, $onlogoutScriptName, $onlogout, 'wartelpas-runtime', $extractTrap, $readScriptByName);
echo 'HOOK_SCRIPT|updated|' . $onloginScriptName . '|status=' . $upLogin['status'] . '|mode=' . ($upLogin['mode'] ?? '-') . '|len=' . strlen($onlogin) . '|verify_len=' . (int)($upLogin['verify_len'] ?? 0) . '|trap=' . str_replace('|', '/', (string)$upLogin['trap']) . "\n";
echo 'HOOK_SCRIPT|updated|' . $onlogoutScriptName . '|status=' . $upLogout['status'] . '|mode=' . ($upLogout['mode'] ?? '-') . '|len=' . strlen($onlogout) . '|verify_len=' . (int)($upLogout['verify_len'] ?? 0) . '|trap=' . str_replace('|', '/', (string)$upLogout['trap']) . "\n";

$scriptsVerify = $API->comm('/system/script/print', ['.proplist' => '.id,name,source,comment']);
if (!is_array($scriptsVerify)) {
    $scriptsVerify = [];
}
$hookLoginVerifyLen = 0;
$hookLogoutVerifyLen = 0;
$hookLoginVerifySrc = '';
$hookLogoutVerifySrc = '';
foreach ($scriptsVerify as $sv) {
    $nm = (string)($sv['name'] ?? '');
    if (strcasecmp($nm, $onloginScriptName) === 0 || strcasecmp($nm, $onlogoutScriptName) === 0) {
        $srcRaw = (string)($sv['source'] ?? '');
        $srcLen = strlen($srcRaw);
        if (strcasecmp($nm, $onloginScriptName) === 0) {
            $hookLoginVerifyLen = $srcLen;
            $hookLoginVerifySrc = $srcRaw;
        }
        if (strcasecmp($nm, $onlogoutScriptName) === 0) {
            $hookLogoutVerifyLen = $srcLen;
            $hookLogoutVerifySrc = $srcRaw;
        }
        echo 'HOOK_SCRIPT|verify|' . $nm . '|source_len=' . $srcLen . "\n";
    }
}

$hookLoginHasMarkers = (stripos($hookLoginVerifySrc, 'live_ingest.php') !== false)
    && (stripos($hookLoginVerifySrc, 'usage_ingest.php') !== false);
$hookLogoutHasMarkers = (stripos($hookLogoutVerifySrc, 'usage_ingest.php') !== false);
echo 'HOOK_MARKER|onlogin=' . ($hookLoginHasMarkers ? '1' : '0') . '|onlogout=' . ($hookLogoutHasMarkers ? '1' : '0') . "\n";

$hookReady = ($hookLoginVerifyLen >= 300 && $hookLogoutVerifyLen >= 300 && $hookLoginHasMarkers && $hookLogoutHasMarkers);
echo 'HOOK_MODE|ready=' . ($hookReady ? '1' : '0') . "\n";

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
    $currentOnLogin = (string)($profileRow['on-login'] ?? '');
    $currentOnLogout = (string)($profileRow['on-logout'] ?? '');
    $oldLoginLen = strlen($currentOnLogin);
    $oldLogoutLen = strlen($currentOnLogout);

    $priceByProfile = (int)$resolveProfilePrice($profileName);
    if ($priceByProfile <= 0) {
        $priceByProfile = $price10;
    }
    $profileLabel = trim($profileName);
    if ($profileLabel === '') {
        $profileLabel = trim((string)$name);
    }
    $profileLabel = str_replace('"', '', $profileLabel);

    $profileOnloginHook = normalizeRouterValue(
        ':global wartelpasprice; :global wartelpasplabel; '
        . ':set wartelpasprice "' . $priceByProfile . '"; '
        . ':set wartelpasplabel "' . $profileLabel . '"; '
        . $onloginHook
    );
    $compactOnLogin = '/system script add name=([/system clock get date]."-|-0-|-".$user."-|-' . $priceByProfile . '") comment=mikhmon';
    $compactOnLogout = ':return;';

    $mode = 'hook';
    if ($hookReady) {
        $finalOnLogin = $profileOnloginHook;
        $finalOnLogout = $onlogoutHook;
    } else {
        $hasExistingHooks = (trim($currentOnLogin) !== '' || trim($currentOnLogout) !== '');
        if ($hasExistingHooks) {
            $finalOnLogin = $currentOnLogin;
            $finalOnLogout = $currentOnLogout;
            $mode = 'preserve';
        } else {
            $finalOnLogin = $compactOnLogin;
            $finalOnLogout = $compactOnLogout;
            $mode = 'compact';
        }
    }
    echo 'PROFILE_PRICE|' . $profileName . '|price=' . $priceByProfile . '|mode=' . $mode . "\n";

    $needsUpdate = ($finalOnLogin !== $currentOnLogin) || ($finalOnLogout !== $currentOnLogout);
    if ($needsUpdate) {
        $setRes = $API->comm('/ip/hotspot/user/profile/set', [
            '.id' => $id,
            'on-login' => $finalOnLogin,
            'on-logout' => $finalOnLogout,
        ]);
        $setTrap = $extractTrap($setRes);
        $setStatus = ($setTrap === '' ? 'OK' : 'TRAP');
    } else {
        $setTrap = '';
        $setStatus = 'SKIP';
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
        . '|mode=' . $mode
        . '|trap=' . str_replace('|', '/', $setTrap)
        . '|old_login=' . $oldLoginLen
        . '|new_login=' . $newLoginLen
        . '|old_logout=' . $oldLogoutLen
        . '|new_logout=' . $newLogoutLen . "\n";
}

$API->disconnect();
