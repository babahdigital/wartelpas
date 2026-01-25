<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * Modified by Pak Dul & Gemini AI (2026) - Wartel Edition
 * UPDATE: Filter DHCP Leases by IP Segment (172.16.2.x) & Modern Dark UI
 */
session_start();
// hide all error
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
	header("Location:../admin.php?id=login");
} else {

    // load session MikroTik
    $session = $_GET['session'];

    // load config
    include('../include/config.php');
    include('../include/readcfg.php');

    // lang
    include('../include/lang.php');
    include('../lang/'.$langid.'.php');

    // routeros api
    include_once('../lib/routeros_api.class.php');
    include_once('../lib/formatbytesbites.php');

    $API = new RouterosAPI();
    $API->debug = false;

    if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
        // 1. Tarik SEMUA data lease dulu
        $getlease = $API->comm("/ip/dhcp-server/lease/print");

        // 2. Mapping server -> profile
        $server_profile_map = array();
        $servers = $API->comm("/ip/hotspot/server/print");
        if (is_array($servers)) {
            foreach ($servers as $srv) {
                $srv_name = isset($srv['name']) ? strtolower((string)$srv['name']) : '';
                $srv_profile = isset($srv['profile']) ? strtolower((string)$srv['profile']) : '';
                if ($srv_name !== '') {
                    $server_profile_map[$srv_name] = $srv_profile;
                }
            }
        }

        // 3. Filter berdasarkan server wartel + profile wartelpas
        $filtered_leases = array();
        foreach ($getlease as $lease) {
            $server = isset($lease['server']) ? strtolower((string)$lease['server']) : '';
            $server_profile = isset($lease['server-profile']) ? strtolower((string)$lease['server-profile']) : '';
            if ($server_profile === '' && isset($server_profile_map[$server])) {
                $server_profile = $server_profile_map[$server];
            }

            if ($server === 'wartel' && $server_profile === 'wartelpas') {
                $filtered_leases[] = $lease;
            }
        }

        $TotalReg = count($filtered_leases);
        $countlease = $TotalReg;
    } else {
        $filtered_leases = array();
        $TotalReg = 0;
        $countlease = 0;
    }
}
?>

<style>
    .card-modern {
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
        backdrop-filter: blur(4px);
        border-radius: 12px;
    }
    .card-header-modern {
        background: transparent;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        padding: 1.5rem;
    }
    .table-dark-solid { width: 100%; border-collapse: separate; border-spacing: 0; color: #e0e0e0; }
    .table-dark-solid th { background: #1b1e21; padding: 12px; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; color: #adb5bd; border-bottom: 2px solid #495057; border-top: none; }
    .table-dark-solid td { padding: 12px; border-bottom: 1px solid #3a4046; vertical-align: middle; font-size: 0.9rem; }
    .table-dark-solid tr:hover td { background: #32383e; }
    .badge-modern {
        border-radius: 6px;
        padding: 5px 10px;
        font-weight: 500;
        font-size: 0.75rem;
    }
</style>

<div class="row">
    <div class="col-12">
        <div class="card card-modern">
            <div class="card-header card-header-modern">
                <h3 class="card-title mb-0">
                    <i class="fa fa-list mr-2"></i> DHCP Leases 
                    <span class="badge badge-pill badge-primary ml-2"><?= $countlease; ?></span>
                </h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="dataTable" class="table-dark-solid text-nowrap">
                        <thead>
                            <tr>
                                <th style="width: 50px" class="text-center"><i class="fa fa-info-circle"></i></th>
                                <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> Address</th>
                                <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> MAC Address</th>
                                <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> Server</th>
                                <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> Active Address</th>
                                <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> Active MAC</th>
                                <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> Host Name</th>
                                <th class="pointer text-center" title="Click to sort"><i class="fa fa-sort"></i> Status</th>
                            </tr>
                        </thead>
                        <tbody> 
                        <?php
                        // Loop data yang sudah difilter by IP
                        foreach ($filtered_leases as $lease) {
                            $id = $lease['.id'];

                            $addr = isset($lease['address']) ? $lease['address'] : '';
                            $maca = isset($lease['mac-address']) ? $lease['mac-address'] : '';
                            
                            // Tampilan Server
                            $server_raw = isset($lease['server']) ? $lease['server'] : '';
                            $server_display = ($server_raw == "") ? "<i class='text-muted'>All (Static)</i>" : $server_raw;
                            
                            $aaddr = isset($lease['active-address']) ? $lease['active-address'] : '';
                            $amaca = isset($lease['active-mac-address']) ? $lease['active-mac-address'] : '';
                            $ahostname = isset($lease['host-name']) ? $lease['host-name'] : '';
                            $status = isset($lease['status']) ? $lease['status'] : '';

                            echo "<tr>";
                            
                            // Kolom Tipe (Dynamic / Static)
                            echo "<td style='text-align:center;'>";
                            if (isset($lease['dynamic']) && $lease['dynamic'] == "true") {
                                echo "<span class='badge badge-info badge-modern' title='Dynamic'>D</span>";
                            } else {
                                echo "<span class='badge badge-success badge-modern' title='Static'>S</span>";
                            }
                            echo "</td>";

                            echo "<td style='font-family: monospace; color: #81d4fa;'>" . $addr . "</td>";
                            echo "<td style='font-family: monospace;'>" . $maca . "</td>";
                            echo "<td>" . $server_display . "</td>";
                            echo "<td style='font-family: monospace;'>" . $aaddr . "</td>";
                            echo "<td style='font-family: monospace;'>" . $amaca . "</td>";
                            echo "<td>" . $ahostname . "</td>";
                            
                            // Kolom Status dengan Badge Modern
                            echo "<td class='text-center'>";
                            if ($status == "bound") {
                                echo "<span class='badge badge-success badge-modern'>Bound</span>";
                            } elseif ($status == "waiting") {
                                echo "<span class='badge badge-warning badge-modern'>Waiting</span>";
                            } else {
                                echo "<span class='badge badge-secondary badge-modern'>" . $status . "</span>";
                            }
                            echo "</td>";
                            
                            echo "</tr>";
                        }
                        ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>