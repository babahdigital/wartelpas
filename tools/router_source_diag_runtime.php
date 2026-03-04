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
$name = 'zz_diag_source';
$rows = $API->comm('/system/script/print', ['.proplist' => '.id,name']);
if (!is_array($rows)) {
    $rows = [];
}
$existingId = '';
foreach ($rows as $row) {
    if (strcasecmp((string)($row['name'] ?? ''), $name) === 0) {
        $existingId = (string)($row['.id'] ?? '');
        break;
    }
}

$srcNoEq = ':put "' . str_repeat('A', 260) . '"; :put "' . str_repeat('B', 220) . '";';
$srcWithEq = ':local k "TOKEN_ABC="; :put "' . str_repeat('C', 220) . '";';

$setScript = function($source) use ($API, $name, $existingId) {
    if ($existingId !== '') {
        return $API->comm('/system/script/set', [
            '.id' => $existingId,
            'name' => $name,
            'source' => $source,
            'comment' => 'diag',
        ]);
    }
    return $API->comm('/system/script/add', [
        'name' => $name,
        'source' => $source,
        'comment' => 'diag',
    ]);
};

$readLen = function() use ($API, $name) {
    $rows2 = $API->comm('/system/script/print', ['.proplist' => '.id,name,source']);
    if (!is_array($rows2)) {
        $rows2 = [];
    }
    $id = '';
    $printLen = 0;
    foreach ($rows2 as $row) {
        if (strcasecmp((string)($row['name'] ?? ''), $name) === 0) {
            $id = (string)($row['.id'] ?? '');
            $printLen = strlen((string)($row['source'] ?? ''));
            break;
        }
    }
    $full = '';
    if ($id !== '') {
        $resp = $API->comm('/system/script/get', ['.id' => $id, 'value-name' => 'source']);
        if (is_array($resp) && isset($resp['ret'])) {
            $full = (string)$resp['ret'];
        } elseif (is_array($resp) && isset($resp[0]['ret'])) {
            $full = (string)$resp[0]['ret'];
        } elseif (is_string($resp)) {
            $full = $resp;
        }
    }
    return [$id, $printLen, strlen($full), substr(str_replace(["\n", "\r"], [' ', ' '], $full), 0, 120)];
};

$setScript($srcNoEq);
list($id1, $pl1, $gl1, $sn1) = $readLen();
echo 'TEST|no_eq|want=' . strlen($srcNoEq) . '|id=' . $id1 . '|print=' . $pl1 . '|get=' . $gl1 . '|sn=' . $sn1 . "\n";

$setScript($srcWithEq);
list($id2, $pl2, $gl2, $sn2) = $readLen();
echo 'TEST|with_eq|want=' . strlen($srcWithEq) . '|id=' . $id2 . '|print=' . $pl2 . '|get=' . $gl2 . '|sn=' . $sn2 . "\n";

$API->disconnect();
