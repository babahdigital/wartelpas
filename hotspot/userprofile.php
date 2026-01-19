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

// hide all error
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
	echo '
<html>
<head><title>403 Forbidden</title></head>
<body bgcolor="white">
<center><h1>403 Forbidden</h1></center>
<hr><center>nginx/1.14.0</center>
</body>
</html>
';
} else {


// get user profile
	$getprofile = $API->comm("/ip/hotspot/user/profile/print");
	$TotalReg = count($getprofile);
// count user profile
	$countprofile = $API->comm("/ip/hotspot/user/profile/print", array(
		"count-only" => "",
	));
}
?>
<style>
	:root { --dark-card: #2a3036; --border-col: #495057; --txt-main: #ecf0f1; --txt-muted: #adb5bd; --c-blue: #3498db; --c-red: #e74c3c; }
	.card-solid { background: var(--dark-card); color: var(--txt-main); border: none; box-shadow: 0 4px 10px rgba(0,0,0,0.3); border-radius: 8px; }
	.card-header-solid { background: #23272b; padding: 12px 20px; border-bottom: 2px solid var(--border-col); display: flex; justify-content: space-between; align-items: center; border-radius: 8px 8px 0 0; }
	.table-dark-solid { width: 100%; border-collapse: separate; border-spacing: 0; }
	.table-dark-solid th { background: #1b1e21; padding: 12px; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; color: var(--txt-muted); border-bottom: 2px solid var(--border-col); }
	.table-dark-solid td { padding: 12px; border-bottom: 1px solid #3a4046; vertical-align: middle; font-size: 0.9rem; }
	.table-dark-solid tr:hover td { background: #32383e; }
	.btn-act { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 4px; border: none; color: #fff; transition: all 0.2s; margin: 0 2px; }
	.btn-act-danger { background: var(--c-red); }
	.btn-act-info { background: var(--c-blue); }
	.btn-act:hover { transform: translateY(-1px); }
	.badge-count { font-size: 13px; padding: 6px 10px; }
</style>

<div class="row">
<div class="col-12">
<div class="card card-solid">
<div class="card-header-solid">
		<h3 class="card-title m-0"><i class="fa fa-pie-chart mr-2"></i> User Profile</h3>
		<div class="d-flex align-items-center" style="gap:8px;">
			<span class="badge badge-secondary badge-count"><?php echo $countprofile; ?> Item<?php echo ($countprofile > 1) ? 's' : ''; ?></span>
			<a href="./?user-profile=add&session=<?= $session; ?>" class="btn btn-primary" title="Tambah Profile Baru"><i class="fa fa-plus"></i> Tambah Baru</a>
		</div>
</div>
<!-- /.card-header -->
<div class="card-body p-0">
<div class="table-responsive" style="max-height: 75vh"> 			   
<table id="tFilter" class="table table-dark-solid table-hover text-nowrap">
  <thead>
  <tr> 
		<th style="min-width:80px;" class="text-center" >Aksi</th>
		<th class="align-middle"><?= $_name ?></th>
		<th class="align-middle">Shared<br>Users</th>
		<th class="align-middle">Rate<br>Limit</th>
		<th class="align-middle"><?= $_expired_mode ?></th>
		<th class="align-middle"><?= $_validity ?></th>
		<th class="text-right align-middle" > <?= $_price." ".$currency; ?></th>
		<th class="text-right align-middle" > <?= $_selling_price." ".$currency; ?></th>
		<th class="align-middle"><?= $_lock_user ?></th>
    </tr>
  </thead>
  <tbody>
<?php

for ($i = 0; $i < $TotalReg; $i++) {

	$profiledetalis = $getprofile[$i];
	$pid = $profiledetalis['.id'];
	$pname = $profiledetalis['name'];
	$psharedu = $profiledetalis['shared-users'];
	$pratelimit = $profiledetalis['rate-limit'];
	$ponlogin = $profiledetalis['on-login'];
	$getmonexpired = $API->comm("/system/scheduler/print", array(
    "?name" => "$pname",
  ));
  $monexpired = $getmonexpired[0];
  $monid = $monexpired['.id'];
	$pmon = $monexpired['name'];
	$chkpmon = $monexpired['disabled'];
	if(empty($pmon) || $chkpmon == "true"){$moncolor = "text-orange";}else{$moncolor = "text-green";}
	echo "<tr>";
	?>
	<td style='text-align:center;'>
		<button type='button' class='btn-act btn-act-danger' title='Hapus <?= $pname; ?>' onclick="if(confirm('Hapus profile <?= $pname; ?>?')){loadpage('./?remove-user-profile=<?= $pid; ?>&pname=<?= $pname ?>&session=<?= $session; ?>')}">
			<i class='fa fa-trash'></i>
		</button>
		<a class='btn-act btn-act-info' title='Lihat user profile <?= $pname; ?>' href='./?hotspot=users&profile=<?= $pname; ?>&session=<?= $session; ?>'>
			<i class='fa fa-users'></i>
		</a>
	</td>
	<?php
	echo "<td><a title='Open User Profile " . $pname . "' href='./?user-profile=" . $pid . "&session=" . $session . "' style='color:inherit; text-decoration:none;'><i class='fa fa-edit'></i> <i class='fa fa-ci fa-circle ".$moncolor."'></i> $pname</a></td>";
//$profiledetalis = $ARRAY[$i];echo "<td>" . $profiledetalis['name'];echo "</td>";
	echo "<td>" . $psharedu;
	echo "</td>";
	echo "<td>" . $pratelimit;
	echo "</td>";

	echo "<td>";
	$getexpmode = explode(",", $ponlogin);
// get expired mode
	$expmode = $getexpmode[1];
	if ($expmode == "rem") {
		echo "Remove";
	} elseif ($expmode == "ntf") {
		echo "Notice";
	} elseif ($expmode == "remc") {
		echo "Remove & Record";
	} elseif ($expmode == "ntfc") {
		echo "Notice & Record";
	} else {

	}
	echo "</td>";
	echo "<td>";
// get validity
	$getvalid = explode(",", $ponlogin);
	echo $getvalid[3];

	echo "</td>";

	echo "<td style='text-align:right;'>";
// get price
	$getprice = explode(",", $ponlogin);
	$price = trim($getprice[2]);
	if ($price == "" || $price == "0") {
		echo "";
	} else {
		if ($currency == in_array($currency, $cekindo['indo'])) {
			echo number_format((float)$price, 0, ",", ".");
		} else {
			echo number_format((float)$price, 2);
		}
	}

	echo "</td>";
	echo "<td style='text-align:right;'>";
// get price
	$getsprice = explode(",", $ponlogin);
	$price = trim($getsprice[4]);
	if ($price == "" || $price == "0") {
		echo "";
	} else {
		if ($currency == in_array($currency, $cekindo['indo'])) {
			echo number_format((float)$price, 0, ",", ".");
		} else {
			echo number_format((float)$price, 2);
		}
	}

	echo "</td>";
	echo "<td>";

	$getgracep = explode(",", $ponlogin);
	echo $getgracep[6];
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
