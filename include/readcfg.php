<?php
/*
 *  Copyright (C) 2018 Laksamadi Guko.
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// hide all error
error_reporting(0);
if (substr($_SERVER["REQUEST_URI"], -11) == "readcfg.php") {
    header("Location:./");
};
// env config (opsional)
$env = [];
$envFile = __DIR__ . '/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
// read config

$useradm = '';
$passadm = '';
if (isset($data['mikhmon']) && is_array($data['mikhmon'])) {
    $useradm = isset($data['mikhmon'][1]) ? explode('<|<', $data['mikhmon'][1])[1] : '';
    $passadm = isset($data['mikhmon'][2]) ? explode('>|>', $data['mikhmon'][2])[1] : '';
}

if (empty($session) || !isset($data[$session]) || !is_array($data[$session])) {
    $iphost = '';
    $userhost = '';
    $passwdhost = '';
    $hotspotname = '';
    $dnsname = '';
    $currency = '';
    $areload = '';
    $iface = '';
    $infolp = '';
    $idleto = '';
    $sesname = '';
    $livereport = '';
    $hotspot_server = isset($env['system']['hotspot_server']) ? (string)$env['system']['hotspot_server'] : 'wartel';
    return;
}
$iphost = explode('!', $data[$session][1])[1];
$userhost = explode('@|@', $data[$session][2])[1];
$passwdhost = explode('#|#', $data[$session][3])[1];
$hotspotname = explode('%', $data[$session][4])[1];
$dnsname = explode('^', $data[$session][5])[1];
$currency = explode('&', $data[$session][6])[1];
$areload = explode('*', $data[$session][7])[1];
$iface = explode('(', $data[$session][8])[1];
$infolp = explode(')', $data[$session][9])[1];
$idleto = explode('=', $data[$session][10])[1];
$sesname = explode('+', $data[$session][10])[1];
$livereport = explode('@!@', $data[$session][11])[1];
$hotspot_server = isset($data[$session][12]) ? explode('~', $data[$session][12])[1] : 'wartel';
if (empty($hotspot_server)) {
    $hotspot_server = 'wartel';
}
if (!empty($env['system']['hotspot_server'])) {
    $hotspot_server = (string)$env['system']['hotspot_server'];
}

$cekindo['indo'] = array(
    'RP', 'Rp', 'rp', 'IDR', 'idr', 'RP.', 'Rp.', 'rp.', 'IDR.', 'idr.',
);


