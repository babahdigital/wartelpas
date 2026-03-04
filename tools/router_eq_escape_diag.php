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
$diagName = 'zz_eq_escape_diag';
$emitName = 'zz_eq_emit';

$rows = $API->comm('/system/script/print', ['.proplist' => '.id,name']);
if (!is_array($rows)) {
    $rows = [];
}
foreach ($rows as $r) {
    $nm = (string)($r['name'] ?? '');
    if (strcasecmp($nm, $diagName) === 0 || strcasecmp($nm, $emitName) === 0) {
        if (!empty($r['.id'])) {
            $API->comm('/system/script/remove', ['.id' => (string)$r['.id']]);
        }
    }
}

$src = ':do {/system script add name\\3D"' . $emitName . '" comment\\3D"mikhmon" source\\3D"diag";} on-error={:log warning "diag-fail";}';
$addRes = $API->comm('/system/script/add', [
    'name' => $diagName,
    'source' => $src,
    'comment' => 'diag',
]);
$runRes = $API->comm('/system/script/run', ['number' => $diagName]);

$rows2 = $API->comm('/system/script/print', ['.proplist' => '.id,name,comment,source']);
if (!is_array($rows2)) {
    $rows2 = [];
}
$foundDiag = 0;
$foundEmit = 0;
$diagLen = 0;
foreach ($rows2 as $r) {
    $nm = (string)($r['name'] ?? '');
    if (strcasecmp($nm, $diagName) === 0) {
        $foundDiag = 1;
        $diagLen = strlen((string)($r['source'] ?? ''));
    }
    if (strcasecmp($nm, $emitName) === 0) {
        $foundEmit = 1;
    }
}

echo 'DIAG|add_type=' . gettype($addRes) . '|run_type=' . gettype($runRes) . '|diag_exists=' . $foundDiag . '|diag_src_len=' . $diagLen . '|emit_exists=' . $foundEmit . "\n";

$API->disconnect();
