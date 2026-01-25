<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * Modified by Pak Dul & Gemini AI (2026) - Wartel Edition
 * UPDATE: Filter Hosts by IP (172.16.2.x) & CLEAN COMMENT DISPLAY & Modern UI
 */
session_start();
// hide all error
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
	header("Location:../admin.php?id=login");
} else {

	// load session MikroTik
	$session = $_GET['session'];
	$hotspot = isset($_GET['hotspot']) ? $_GET['hotspot'] : '';

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

		// --- LOGIC: FILTER BY IP SEGMEN ---
		
		// 1. Tarik data (bisa bypassed atau all, lalu filter IP)
		if ($hotspot == "hostp") {
			$raw_hosts = $API->comm("/ip/hotspot/host/print", array("?bypassed" => "yes"));
			$titlename = "Bypassed Hosts (172.16.2.x)";
		} else {
			$raw_hosts = $API->comm("/ip/hotspot/host/print");
			$titlename = "Host List";
		}

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

        // 3. Filter Array di PHP (server wartel + profile wartelpas)
        $filtered_hosts = array();
        foreach ($raw_hosts as $h) {
            $server = isset($h['server']) ? strtolower((string)$h['server']) : '';
            $server_profile = isset($h['server-profile']) ? strtolower((string)$h['server-profile']) : '';
            if ($server_profile === '' && isset($server_profile_map[$server])) {
                $server_profile = $server_profile_map[$server];
            }
            if ($server === 'wartel' && $server_profile === 'wartelpas') {
                $filtered_hosts[] = $h;
            }
        }
		
		$TotalReg = count($filtered_hosts);
		$counthosts = $TotalReg;
		
	} else {
		$TotalReg = 0;
		$counthosts = 0;
		$filtered_hosts = array();
		$titlename = "Host List";
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
    /* Status Badge Modernization */
    .badge-modern {
        border-radius: 6px;
        padding: 5px 10px;
        font-weight: 500;
        letter-spacing: 0.5px;
        font-size: 0.75rem;
    }
    .btn-icon-hover:hover {
        transform: scale(1.2);
        transition: transform 0.2s;
        cursor: pointer;
    }
</style>

<div class="row">
    <div class="col-12">
        <div class="card card-modern">
            <div class="card-header card-header-modern">
                <h3 class="card-title mb-0">
                    <i class="fa fa-laptop mr-2"></i> <?= $titlename; ?> 
                    <span class="badge badge-pill badge-primary ml-2"><?= $counthosts; ?></span>
                </h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="dataTable" class="table-dark-solid text-nowrap">
                        <thead>
                            <tr>
                                <th style="text-align:center; width: 50px;"><i class="fa fa-trash"></i></th>
                                <th>Status</th>
                                <th><?= $_mac_address ?></th>
                                <th><?= $_ip_address ?></th>
                                <th>To Address</th>
                                <th><?= $_server ?></th>
                                <th><?= $_comment ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        // Loop menggunakan data yang sudah difilter ($filtered_hosts)
                        foreach ($filtered_hosts as $hosts) {
                            $id = $hosts['.id'];
                            $maca = $hosts['mac-address'];
                            $addr = $hosts['address'];
                            $toaddr = isset($hosts['to-address']) ? $hosts['to-address'] : '';
                            $server = $hosts['server'];
                            $commt = isset($hosts['comment']) ? $hosts['comment'] : '';

                            $uriprocess = "'./?remove-host=" . $id . "&session=" . $session . "'";

                            echo "<tr>";
                            // Tombol Hapus Modern
                            echo "<td style='text-align:center;'>
                                    <span class='btn-icon-hover' title='Remove " . $maca . "' onclick=\"if(confirm('Remove Host $maca?')){loadpage(".$uriprocess.")}\">
                                        <i class='fa fa-trash text-danger' style='font-size: 1.1rem;'></i>
                                    </span>
                                  </td>";
                            
                            // Status Badges Modern
                            echo "<td style='text-align:center;'>";
                            if ($hosts['authorized'] == "true" && $hosts['DHCP'] == "true") {
                                echo "<span class='badge badge-success badge-modern' title='Authorized & DHCP'>Auth | DHCP</span>";
                            } elseif ($hosts['authorized'] == "true") {
                                echo "<span class='badge badge-success badge-modern' title='Authorized'>Authorized</span>";
                            } elseif ($hosts['bypassed'] == "true") {
                                echo "<span class='badge badge-info badge-modern' title='Bypassed'>Bypassed</span>";
                            } elseif ($hosts['DHCP'] == "true") {
                                echo "<span class='badge badge-warning badge-modern' title='DHCP Only'>DHCP</span>";
                            } else {
                                echo "<span class='badge badge-secondary badge-modern'>-</span>";
                            }
                            echo "</td>";

                            echo "<td style='font-family: monospace; color: #81d4fa;'>" . $maca . "</td>";
                            echo "<td style='font-family: monospace;'>" . $addr . "</td>";
                            echo "<td style='font-family: monospace;'>" . $toaddr . "</td>";
                            echo "<td>" . $server . "</td>";
                            
                            // --- LOGIC TAMPILAN KOMENTAR BERSIH ---
                            echo "<td>";
                            
                            // Cek apakah ini voucher?
                            $is_voucher = (stripos($commt, 'vc-') === 0 || stripos($commt, 'up-') === 0);
                            
                            if ($is_voucher) {
                                // Jika ada Blok-
                                if (strpos($commt, 'Blok-') !== false) {
                                    $parts = explode('Blok-', $commt);
                                    $clean_blok = 'Blok-' . end($parts);
                                    echo "<span class='badge badge-primary badge-modern'>" . $clean_blok . "</span>";
                                    
                                } elseif (strpos($commt, 'Kamar-') !== false) {
                                    $parts = explode('Kamar-', $commt);
                                    $clean_kamar = 'Kamar-' . end($parts);
                                    echo "<span class='badge badge-success badge-modern'>" . $clean_kamar . "</span>";
                                    
                                } else {
                                    // Voucher biasa tanpa Blok -> Sembunyikan (tampil kosong)
                                    echo ""; 
                                }
                            } else {
                                // Komentar User Biasa (bukan voucher) -> Tampilkan Normal
                                echo "<span style='font-style: italic; color: #ccc;'>" . $commt . "</span>";
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