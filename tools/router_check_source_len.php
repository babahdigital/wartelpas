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
$rows = $API->comm('/system/script/print', ['.proplist' => '.id,name,source']);
if (!is_array($rows)) {
    $rows = [];
}

foreach (['wartelpas_onlogin', 'wartelpas_onlogout'] as $name) {
    $id = '';
    $printLen = 0;
    foreach ($rows as $row) {
        if (strcasecmp((string)($row['name'] ?? ''), $name) === 0) {
            $id = (string)($row['.id'] ?? '');
            $printLen = strlen((string)($row['source'] ?? ''));
            break;
        }
    }

    if ($id === '') {
        echo "SCRIPT|$name|missing\n";
        continue;
    }

    $resp = $API->comm('/system/script/get', [
        '.id' => $id,
        'value-name' => 'source',
    ]);
    if (empty($resp)) {
        $resp = $API->comm('/system/script/get', [
            'numbers' => $id,
            'value-name' => 'source',
        ]);
    }

    $fullSource = '';
    if (is_array($resp) && isset($resp['ret'])) {
        $fullSource = (string)$resp['ret'];
    } elseif (is_array($resp) && isset($resp[0]['ret'])) {
        $fullSource = (string)$resp[0]['ret'];
    } elseif (is_string($resp)) {
        $fullSource = $resp;
    }

    $respType = is_array($resp) ? 'array' : gettype($resp);
    $trap = '';
    if (is_array($resp) && isset($resp['!trap'][0]['message'])) {
        $trap = (string)$resp['!trap'][0]['message'];
    }

    $snippet = str_replace(["\n", "\r", '|'], [' ', ' ', '/'], $fullSource);
    echo 'SCRIPT|' . $name . '|id=' . $id . '|print_len=' . $printLen . '|get_len=' . strlen($fullSource) . '|resp=' . $respType . '|trap=' . str_replace('|', '/', $trap) . '|src=' . $snippet . "\n";
}

$API->disconnect();
