<?php
$_SERVER['REQUEST_URI'] = '/cli';
$session = 'S3c7x9_LB';
require '/var/www/html/include/config.php';
require '/var/www/html/include/readcfg.php';
require '/var/www/html/lib/routeros_api.class.php';
$env = [];
$envFile = '/var/www/html/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}

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

$allScripts = $API->comm('/system/script/print', ['.proplist' => '.id,name,source,owner,comment']);
if (!is_array($allScripts)) $allScripts = [];

$hookLoginName = 'wartelpas_onlogin';
$hookLogoutName = 'wartelpas_onlogout';
$hookLoginSrc = '';
$hookLogoutSrc = '';
foreach ($allScripts as $s) {
    $nm = trim((string)($s['name'] ?? ''));
    if (strcasecmp($nm, $hookLoginName) === 0) {
        $hookLoginSrc = (string)($s['source'] ?? '');
    }
    if (strcasecmp($nm, $hookLogoutName) === 0) {
        $hookLogoutSrc = (string)($s['source'] ?? '');
    }
}

$hookLoginOk = (strpos(strtolower($hookLoginSrc), '/system script add') !== false)
    && (strpos(strtolower($hookLoginSrc), 'live_ingest.php') !== false)
    && (strpos(strtolower($hookLoginSrc), 'usage_ingest.php') !== false);
$hookLogoutOk = (strpos(strtolower($hookLogoutSrc), 'usage_ingest.php') !== false);

echo 'HOOK_SCRIPT|onlogin|exists=' . ($hookLoginSrc !== '' ? '1' : '0') . '|len=' . strlen($hookLoginSrc) . '|ok=' . ($hookLoginOk ? '1' : '0') . "\n";
echo 'HOOK_SCRIPT|onlogout|exists=' . ($hookLogoutSrc !== '' ? '1' : '0') . '|len=' . strlen($hookLogoutSrc) . '|ok=' . ($hookLogoutOk ? '1' : '0') . "\n";

$sample = array_slice($scripts, 0, 5);
foreach ($sample as $i => $s) {
    $nm = str_replace(["\n", "\r", "|"], [' ', ' ', '/'], (string)($s['name'] ?? ''));
    echo 'SCRIPT_SAMPLE|' . ($i + 1) . '|' . $nm . "\n";
}

$profiles = $API->comm('/ip/hotspot/user/profile/print', ['.proplist' => '.id,name,on-login,on-logout']);
if (!is_array($profiles)) $profiles = [];
echo 'PROFILE_COUNT|' . count($profiles) . "\n";

$profile10 = trim((string)($env['profiles']['profile_10'] ?? '10Menit'));
$profile30 = trim((string)($env['profiles']['profile_30'] ?? '30Menit'));
$targetProfiles = array_values(array_unique(array_filter([$profile10, $profile30])));
$targetMap = [];
foreach ($targetProfiles as $tp) {
    $targetMap[strtolower($tp)] = true;
}
echo 'PROFILE_TARGETS|' . implode(',', $targetProfiles) . "\n";

$missing = [];

$getProfileValue = static function ($api, $id, $field) {
    $resp = $api->comm('/ip/hotspot/user/profile/get', [
        '.id' => (string)$id,
        'value-name' => (string)$field,
    ]);
    if (is_array($resp) && isset($resp['ret'])) {
        return (string)$resp['ret'];
    }
    if (is_array($resp) && isset($resp[0]['ret'])) {
        return (string)$resp[0]['ret'];
    }
    if (is_string($resp)) {
        return $resp;
    }
    return '';
};

foreach ($profiles as $p) {
    $name = (string)($p['name'] ?? '');
    $isTarget = isset($targetMap[strtolower(trim($name))]);
    $onLoginRaw = (string)($p['on-login'] ?? '');
    $onLogoutRaw = (string)($p['on-logout'] ?? '');
    if ($isTarget && !empty($p['.id'])) {
        $fullLogin = $getProfileValue($API, (string)$p['.id'], 'on-login');
        $fullLogout = $getProfileValue($API, (string)$p['.id'], 'on-logout');
        if ($fullLogin !== '') {
            $onLoginRaw = $fullLogin;
        }
        if ($fullLogout !== '') {
            $onLogoutRaw = $fullLogout;
        }
    }
    $onLogin = strtolower($onLoginRaw);
    $onLogout = strtolower($onLogoutRaw);
    $hasScriptAdd = (strpos($onLogin, '/system script add') !== false);
    $hasLiveIngest = (strpos($onLogin, 'live_ingest.php') !== false);
    $hasUsageIngestLogin = (strpos($onLogin, 'usage_ingest.php') !== false);
    $hasUsageIngestLogout = (strpos($onLogout, 'usage_ingest.php') !== false);
    $hasMikhmonTag = (strpos($onLogin, 'mikhmon') !== false);
    $hookRunLogin = (strpos($onLogin, '/system script run ' . strtolower($hookLoginName)) !== false);
    $hookRunLogout = (strpos($onLogout, '/system script run ' . strtolower($hookLogoutName)) !== false);
    $okLoginDirect = $hasScriptAdd && $hasLiveIngest && $hasUsageIngestLogin;
    $okLogoutDirect = $hasUsageIngestLogout;
    $okSalesMinimal = $hasScriptAdd && $hasMikhmonTag;
    $okLogin = $okLoginDirect || ($hookRunLogin && $hookLoginOk) || $okSalesMinimal;
    $okLogout = $okLogoutDirect || ($hookRunLogout && $hookLogoutOk) || $okSalesMinimal;
    if ($isTarget && !($okLogin && $okLogout)) {
        $missing[] = $name . '|script_add=' . ($hasScriptAdd ? '1' : '0')
            . '|live_ingest=' . ($hasLiveIngest ? '1' : '0')
            . '|usage_login=' . ($hasUsageIngestLogin ? '1' : '0')
            . '|usage_logout=' . ($hasUsageIngestLogout ? '1' : '0')
            . '|minimal=' . ($okSalesMinimal ? '1' : '0')
            . '|hook_login=' . ($hookRunLogin ? '1' : '0')
            . '|hook_logout=' . ($hookRunLogout ? '1' : '0')
            . '|login_len=' . strlen($onLoginRaw)
            . '|logout_len=' . strlen($onLogoutRaw);
    }
}

echo 'PROFILE_MISSING_COUNT|' . count($missing) . "\n";
foreach ($missing as $name) {
    echo 'PROFILE_MISSING|' . $name . "\n";
}

foreach ($profiles as $p) {
    $name = (string)($p['name'] ?? '');
    if ($name === '10Menit' || $name === '30Menit') {
        $olRaw = (string)($p['on-login'] ?? '');
        $ooRaw = (string)($p['on-logout'] ?? '');
        if (!empty($p['.id'])) {
            $f1 = $getProfileValue($API, (string)$p['.id'], 'on-login');
            $f2 = $getProfileValue($API, (string)$p['.id'], 'on-logout');
            if ($f1 !== '') {
                $olRaw = $f1;
            }
            if ($f2 !== '') {
                $ooRaw = $f2;
            }
        }
        $ol = str_replace(["\n", "\r", "|"], [' ', ' ', '/'], $olRaw);
        $oo = str_replace(["\n", "\r", "|"], [' ', ' ', '/'], $ooRaw);
        echo 'PROFILE_SNIPPET|' . $name . '|onlogin=' . $ol . "\n";
        echo 'PROFILE_SNIPPET|' . $name . '|onlogout=' . $oo . "\n";
    }
}

$API->disconnect();
