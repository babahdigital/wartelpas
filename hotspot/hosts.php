<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * Modified by Pak Dul & Gemini AI (2026) - Wartel Edition
 * UPDATE: Filter Hosts by IP (172.16.2.x) & CLEAN COMMENT DISPLAY
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
			$titlename = "Host List -";
		}
		
		// 2. Filter Array di PHP (Hanya 172.16.2.x)
		$filtered_hosts = array();
		foreach ($raw_hosts as $h) {
			$ip = isset($h['address']) ? $h['address'] : '';
			if (strpos($ip, "172.16.2.") === 0) {
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

<div class="row">
<div class="col-12">
<div class="card">
<div class="card-header">
	<h3><i class="fa fa-laptop"></i> <?= $titlename; ?> <span class="badge badge-primary"><?= $counthosts; ?></span>
	</h3>
</div>
<div class="card-body">

<div class="table-responsive">
<table id="dataTable" class="table table-bordered table-hover text-nowrap">
	<thead>
		<tr>
			<th style="text-align:center; width: 40px;"><i class="fa fa-trash"></i></th>
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
	echo "<td style='text-align:center;'><span class='pointer' title='Remove " . $maca . "' onclick=\"if(confirm('Remove Host $maca?')){loadpage(".$uriprocess.")}\"><i class='fa fa-minus-square text-danger'></i></span></td>";
	
	echo "<td style='text-align:center;'>";
	if ($hosts['authorized'] == "true" && $hosts['DHCP'] == "true") {
		echo "<span class='badge badge-success' title='Authorized & DHCP'>Auth | DHCP</span>";
	} elseif ($hosts['authorized'] == "true") {
		echo "<span class='badge badge-success' title='Authorized'>Authorized</span>";
	} elseif ($hosts['bypassed'] == "true") {
		echo "<span class='badge badge-info' title='Bypassed'>Bypassed</span>";
	} elseif ($hosts['DHCP'] == "true") {
		echo "<span class='badge badge-warning' title='DHCP Only'>DHCP</span>";
	} else {
		echo "<span class='badge badge-secondary'>-</span>";
	}
	echo "</td>";

	echo "<td>" . $maca . "</td>";
	echo "<td>" . $addr . "</td>";
	echo "<td>" . $toaddr . "</td>";
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
			echo "<span style='font-weight:bold; color:#007bff;'>" . $clean_blok . "</span>";
			
		} elseif (strpos($commt, 'Kamar-') !== false) {
			$parts = explode('Kamar-', $commt);
			$clean_kamar = 'Kamar-' . end($parts);
			echo "<span style='font-weight:bold; color:#28a745;'>" . $clean_kamar . "</span>";
			
		} else {
			// Voucher biasa tanpa Blok -> Sembunyikan (tampil kosong)
			echo ""; 
		}
	} else {
		// Komentar User Biasa (bukan voucher) -> Tampilkan Normal
		echo $commt;
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