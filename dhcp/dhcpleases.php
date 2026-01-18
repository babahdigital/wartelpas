<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * Modified by Pak Dul & Gemini AI (2026) - Wartel Edition
 * UPDATE: Filter DHCP Leases by IP Segment (172.16.2.x)
 */
session_start();
// hide all error
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
	header("Location:../admin.php?id=login");
} else {

	// --- UPDATE LOGIC: FILTER BY IP SEGMEN ---
	// 1. Tarik SEMUA data lease dulu
	$getlease = $API->comm("/ip/dhcp-server/lease/print");
	
	// 2. Siapkan array penampung
	$filtered_leases = array();
	
	// 3. Filter berdasarkan IP Kepala "172.16.2."
	foreach ($getlease as $lease) {
		$addr = isset($lease['address']) ? $lease['address'] : '';
		
		// Cek apakah IP diawali dengan "172.16.2."
		// Jika iya, masukkan ke daftar
		if (strpos($addr, "172.16.2.") === 0) {
			$filtered_leases[] = $lease;
		}
	}
	
	$TotalReg = count($filtered_leases);
	$countlease = $TotalReg;
}
?>
<div class="row">
<div class="col-12">
<div class="card">
<div class="card-header">
	<h3><i class="fa fa-list"></i> DHCP Leases (<span class="badge badge-primary"><?= $countlease; ?></span>)</h3>
</div>
<div class="card-body">
<div class="table-responsive">
<table id="dataTable" class="table table-bordered table-hover text-nowrap">
  <thead>
  <tr>
    <th style="width: 40px" class="text-center"><i class="fa fa-info-circle"></i></th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> Address</th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> MAC Address</th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> Server</th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> Active Address</th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> Active MAC Address</th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> Active Host Name</th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> Status</th>
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
	$server_display = ($server_raw == "") ? "<i>All (Static)</i>" : $server_raw;
	
	$aaddr = isset($lease['active-address']) ? $lease['active-address'] : '';
	$amaca = isset($lease['active-mac-address']) ? $lease['active-mac-address'] : '';
	$ahostname = isset($lease['host-name']) ? $lease['host-name'] : '';
	$status = isset($lease['status']) ? $lease['status'] : '';

	echo "<tr>";
	
	// Kolom Tipe (Dynamic / Static)
	echo "<td style='text-align:center;'>";
	if (isset($lease['dynamic']) && $lease['dynamic'] == "true") {
		echo "<span class='badge badge-info' title='Dynamic'>D</span>";
	} else {
		echo "<span class='badge badge-success' title='Static'>S</span>";
	}
	echo "</td>";

	echo "<td>" . $addr . "</td>";
	echo "<td>" . $maca . "</td>";
	echo "<td>" . $server_display . "</td>";
	echo "<td>" . $aaddr . "</td>";
	echo "<td>" . $amaca . "</td>";
	echo "<td>" . $ahostname . "</td>";
	
	// Kolom Status
	echo "<td class='text-center'>";
	if ($status == "bound") {
		echo "<span class='badge badge-success'>Bound</span>";
	} elseif ($status == "waiting") {
		echo "<span class='badge badge-warning'>Waiting</span>";
	} else {
		echo "<span class='badge badge-secondary'>" . $status . "</span>";
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