<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * Modified by Pak Dul & Gemini AI (2026) - Wartel Edition
 * UPDATE: Filter Active Users by IP (172.16.2.x) & CLEAN COMMENT DISPLAY
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

		// --- LOGIC: FILTER BY IP SEGMEN ---
		// 1. Tarik SEMUA user active
		$gethotspotactive = $API->comm("/ip/hotspot/active/print");
		
		// 2. Filter Array di PHP
		$filtered_active = array();
		foreach ($gethotspotactive as $user) {
			$ip = isset($user['address']) ? $user['address'] : '';
			// Cek apakah IP diawali 172.16.2.
			if (strpos($ip, "172.16.2.") === 0) {
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

<div class="row">
<div class="col-12">
<div class="card">
<div class="card-header">
	<h3><i class="fa fa-wifi"></i> User Aktif (<span class="badge badge-primary"><?= $TotalReg; ?></span>)</h3> 
</div>
<div class="card-body">
<div class="table-responsive">
<table id="dataTable" class="table table-bordered table-hover text-nowrap">
	<thead>
		<tr>
			<th style="text-align:center; width: 40px;"><i class="fa fa-trash"></i></th>
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
	echo "<td style='text-align:center;'><span class='pointer' title='Kick User " . $user . "' onclick=\"if(confirm('Disconnect User $user?')){loadpage(".$uriprocess.")}\"><i class='fa fa-minus-square text-danger'></i></span></td>";
	echo "<td>" . $server . "</td>";
	echo "<td><a title='Open User Details' href='./?hotspot-user=" . $user . "&session=" . $session . "'><i class='fa fa-edit'></i> " . $user . "</a></td>";
	echo "<td>" . $address . "</td>";
	echo "<td>" . $mac . "</td>";
	echo "<td>" . $uptime . "</td>";
	echo "<td>" . $usesstime . "</td>";
	echo "<td>" . $bytesi . "</td>";
	echo "<td>" . $byteso . "</td>";
	echo "<td>" . $loginby . "</td>";
	
	// --- LOGIC TAMPILAN KOMENTAR BERSIH ---
	echo "<td>";
	
	// Cek apakah ini voucher?
	$is_voucher = (stripos($comment, 'vc-') === 0 || stripos($comment, 'up-') === 0);
	
	if ($is_voucher) {
		// Jika ada Blok-
		if (strpos($comment, 'Blok-') !== false) {
			$parts = explode('Blok-', $comment);
			$clean_blok = 'Blok-' . end($parts);
			echo "<span style='font-weight:bold; color:#007bff;'>" . $clean_blok . "</span>";
			
		} elseif (strpos($comment, 'Kamar-') !== false) {
			$parts = explode('Kamar-', $comment);
			$clean_kamar = 'Kamar-' . end($parts);
			echo "<span style='font-weight:bold; color:#28a745;'>" . $clean_kamar . "</span>";
			
		} else {
			// Voucher biasa tanpa Blok -> Sembunyikan (tampil kosong)
			echo ""; 
		}
	} else {
		// Komentar User Biasa (bukan voucher) -> Tampilkan Normal
		echo $comment;
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