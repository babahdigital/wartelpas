<?php
/*
 * AJAX LOADER - Lightweight Status Update
 * File: hotspot/aload_users.php
 * Purpose: Update user status tanpa reload full page
 */

session_start();
if (!isset($_SESSION["mikhmon"])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$session = trim((string)($_GET['session'] ?? ''));
$load = trim((string)($_GET['load'] ?? ''));

// Load config & API
include('../include/config.php');
include_once('../lib/routeros_api.class.php');
include_once('../lib/formatbytesbites.php');

if ($session === '' || !isset($data[$session])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Session tidak valid']);
    exit;
}

include('../include/readcfg.php');

if ($load === '') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Parameter tidak lengkap']);
    exit;
}

$API = new RouterosAPI();
$API->debug = false;
$API->timeout = 5; // hindari request gantung
$API->attempts = 1;
$hotspot_server = $hotspot_server ?? 'wartel'; // dari config, fallback wartel

if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
    if ($load === 'users_status') {
        // Get active users only
        $active = $API->comm("/ip/hotspot/active/print", array(
            "?server" => $hotspot_server,
            ".proplist" => "user,uptime,address,mac-address,bytes-in,bytes-out,server"
        ));

        // Total user tanpa tarik seluruh list
        $countRes = $API->comm("/ip/hotspot/user/print", array(
            "?server" => $hotspot_server,
            "count-only" => ""
        ));
        $total = 0;
        if (is_array($countRes) && isset($countRes[0]['ret'])) {
            $total = (int) $countRes[0]['ret'];
        } elseif (is_array($countRes) && isset($countRes['ret'])) {
            $total = (int) $countRes['ret'];
        }

        $result = [
            'active' => [],
            'total' => $total,
            'timestamp' => date('H:i:s')
        ];

        // Build active map
        $activeMap = [];
        foreach ($active as $a) {
            if (isset($a['user'])) {
                $activeMap[$a['user']] = [
                    'name' => $a['user'],
                    'uptime' => $a['uptime'] ?? '0s',
                    'address' => $a['address'] ?? '-',
                    'mac' => $a['mac-address'] ?? '-',
                    'bytes-in' => (int)($a['bytes-in'] ?? 0),
                    'bytes-out' => (int)($a['bytes-out'] ?? 0),
                    'online' => true,
                    'login_time' => ''
                ];
            }
        }

        // Bytes dari active jika tersedia
        foreach ($activeMap as $name => $row) {
            $bytes = ($row['bytes-in'] ?? 0) + ($row['bytes-out'] ?? 0);
            if ($bytes > 0) {
                $activeMap[$name]['bytes'] = formatBytes($bytes, 2);
            }
        }

        $result['active'] = array_values($activeMap);

        header('Content-Type: application/json');
        echo json_encode($result);
        $API->disconnect();
        exit;
    }
    $API->disconnect();
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Load tidak dikenal']);
    exit;
}

header('Content-Type: application/json');
echo json_encode(['error' => 'Connection failed']);
exit;
?>
