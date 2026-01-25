<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * Modified by Pak Dul & Gemini AI (2026) - Wartel Edition
 * UPDATE: Filter Active Users by IP (172.16.2.x) & Modern Dark UI
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

        // --- LOGIC: FILTER BY SERVER & PROFILE ---
        // 1. Tarik SEMUA user active
        $gethotspotactive = $API->comm("/ip/hotspot/active/print");

        // 2. Filter Array di PHP (hanya server wartel)
        $allowed_servers = array('wartel');
        $filtered_active = array();
        foreach ($gethotspotactive as $user) {
            $server = isset($user['server']) ? strtolower((string)$user['server']) : '';
            if (in_array($server, $allowed_servers, true)) {
                $filtered_active[] = $user;
            }
        }

        $TotalReg = count($filtered_active);

    } else {
		$TotalReg = 0;
		$filtered_active = array();
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
        letter-spacing: 0.5px;
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
                    <i class="fa fa-wifi mr-2"></i> User Aktif 
                    <span class="badge badge-pill badge-primary ml-2"><?= $TotalReg; ?></span>
                </h3> 
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="dataTable" class="table-dark-solid text-nowrap">
                        <thead>
                            <tr>
                                <th style="text-align:center; width: 50px;"><i class="fa fa-ban"></i></th>
                                <th><?= $_server ?></th>
                                <th><?= $_user ?></th>
                                <th><?= $_ip_address ?></th>
                                <th><?= $_mac_address ?></th>
                                <th><?= $_uptime ?></th>
                                <th><?= $_time_left ?></th>
                                <th><?= $_bytes_in ?></th>
                                <th><?= $_bytes_out ?></th>
                                <th>Login By</th>
                                <th><?= $_comment ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        // Loop menggunakan data yang sudah difilter ($filtered_active)
                        foreach ($filtered_active as $hotspotactive) {
                            $id = $hotspotactive['.id'];
                            $server = $hotspotactive['server'];
                            $user = $hotspotactive['user'];
                            $address = $hotspotactive['address'];
                            $mac = $hotspotactive['mac-address'];
                            $uptime = formatDTM($hotspotactive['uptime']);
                            
                            $usesstime = isset($hotspotactive['session-time-left']) ? formatDTM($hotspotactive['session-time-left']) : '';
                            
                            $bytesi = formatBytes($hotspotactive['bytes-in'], 2);
                            $byteso = formatBytes($hotspotactive['bytes-out'], 2);
                            $loginby = isset($hotspotactive['login-by']) ? $hotspotactive['login-by'] : '';
                            $comment = isset($hotspotactive['comment']) ? $hotspotactive['comment'] : '';
                            
                            $uriprocess = "'./?remove-user-active=" . $id . "&session=" . $session . "'";
                            
                            echo "<tr>";
                            // Kick Button Modern
                            echo "<td style='text-align:center;'>
                                    <span class='btn-icon-hover' title='Kick User " . $user . "' onclick=\"if(confirm('Disconnect User $user?')){loadpage(".$uriprocess.")}\">
                                        <i class='fa fa-times-circle text-danger' style='font-size: 1.2rem;'></i>
                                    </span>
                                  </td>";
                            echo "<td>" . $server . "</td>";
                            echo "<td><a title='Open User Details' href='./?hotspot-user=" . $user . "&session=" . $session . "' style='color: #64b5f6; font-weight: 500;'><i class='fa fa-edit'></i> " . $user . "</a></td>";
                            echo "<td style='font-family: monospace;'>" . $address . "</td>";
                            echo "<td style='font-family: monospace;'>" . $mac . "</td>";
                            echo "<td><i class='fa fa-clock-o text-muted mr-1'></i>" . $uptime . "</td>";
                            echo "<td><i class='fa fa-hourglass-half text-muted mr-1'></i>" . $usesstime . "</td>";
                            echo "<td class='text-success'><i class='fa fa-arrow-down mr-1'></i>" . $bytesi . "</td>";
                            echo "<td class='text-warning'><i class='fa fa-arrow-up mr-1'></i>" . $byteso . "</td>";
                            echo "<td>" . $loginby . "</td>";
                            
                            // --- LOGIC TAMPILAN KOMENTAR BERSIH ---
                            echo "<td>";
                            
                            // Cek apakah ini voucher?
                            $is_voucher = (stripos($comment, 'vc-') === 0 || stripos($comment, 'up-') === 0);
                            
                            if ($is_voucher) {
                                // Jika ada Blok- (contoh: Blok-F30 | IP:...)
                                if (strpos($comment, 'Blok-') !== false) {
                                    $clean_label = '';
                                    if (preg_match('/Blok-([A-Za-z]+)\s*([0-9]+)/i', $comment, $m)) {
                                        $blok = strtoupper($m[1]);
                                        $durasi = $m[2];
                                        $clean_label = 'Blok-' . $blok . ' - Profile ' . $durasi . ' Menit';
                                    } else {
                                        $parts = explode('Blok-', $comment);
                                        $clean_blok = 'Blok-' . trim(end($parts));
                                        $clean_label = $clean_blok;
                                    }
                                    echo "<span class='badge badge-primary badge-modern'>" . $clean_label . "</span>";

                                } elseif (strpos($comment, 'Kamar-') !== false) {
                                    $parts = explode('Kamar-', $comment);
                                    $clean_kamar = 'Kamar-' . trim(end($parts));
                                    echo "<span class='badge badge-success badge-modern'>" . $clean_kamar . "</span>";

                                } else {
                                    // Voucher biasa tanpa Blok -> Sembunyikan (tampil kosong)
                                    echo ""; 
                                }
                            } else {
                                // Komentar User Biasa (bukan voucher) -> Tampilkan Normal
                                echo "<span style='color: #ccc;'>" . $comment . "</span>";
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