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
	$session_id = $_GET['session'] ?? '';
	$redirect = './?hotspot=users&profile=all';
	if ($session_id !== '') {
		$redirect .= '&session=' . urlencode($session_id);
	}
	header('Location: ' . $redirect);
	exit;


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
		:root {
				--bg-main: #1e2129;
				--bg-card: #262935;
				--bg-input: #323542;
				--border-c: #3e4252;
				--text-pri: #e6e6e6;
				--text-sec: #9ca3af;
				--accent: #3b82f6;
				--accent-hover: #2563eb;
				--danger: #ef4444;
		}

		.profile-wrapper { padding: 16px 18px; }
		@media (min-width: 992px) { .profile-wrapper { padding: 20px 26px; } }

		.card-modern {
				background-color: var(--bg-card);
				color: var(--text-pri);
				border: 1px solid var(--border-c);
				border-radius: 8px;
				box-shadow: 0 4px 6px rgba(0,0,0,0.2);
				display: flex;
				flex-direction: column;
				height: 100%;
				position: relative;
		}

		.card-header-mod {
				padding: 15px 20px;
				border-bottom: 1px solid var(--border-c);
				background: rgba(0,0,0,0.1);
				display: flex;
				justify-content: space-between;
				align-items: center;
		}

		.card-header-mod h3 { margin: 0; font-size: 1.1rem; font-weight: 600; color: var(--text-pri); }

		.card-body-mod { padding: 0; }

		.table-dark-mod { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
		.table-dark-mod th { text-align: left; color: var(--text-sec); padding: 12px; border-bottom: 1px solid var(--border-c); font-weight: 600; }
		.table-dark-mod td { padding: 12px; border-bottom: 1px solid #323542; color: var(--text-pri); vertical-align: middle; }
		.table-dark-mod tr:hover td { background: #2c2f3b; }

		.btn-act { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 4px; border: none; color: #fff; transition: all 0.2s; margin: 0 2px; }
		.btn-act-danger { background: var(--danger); }
		.btn-act-info { background: var(--accent); }
		.btn-act:hover { transform: translateY(-1px); }

		.badge-count { font-size: 12px; padding: 6px 10px; border-radius: 6px; background: rgba(255,255,255,0.08); color: var(--text-sec); }

		.btn-primary-modern {
				background: linear-gradient(to right, var(--accent), var(--accent-hover));
				color: #fff;
				border: none;
				padding: 8px 14px;
				border-radius: 6px;
				font-weight: 600;
				cursor: pointer;
				text-decoration: none;
		}
		.btn-primary-modern:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(59, 130, 246, 0.3); color: #fff; }
</style>

<div class="container-fluid profile-wrapper">
	<div class="row">
		<div class="col-12">
			<div class="card-modern">
				<div class="card-header-mod">
					<h3 class="m-0"><i class="fa fa-pie-chart mr-2"></i> User Profile</h3>
					<div class="d-flex align-items-center" style="gap:8px;">
						<span class="badge-count"><?php echo $countprofile; ?> Item<?php echo ($countprofile > 1) ? 's' : ''; ?></span>
						<a href="./?user-profile=add&session=<?= $session; ?>" class="btn-primary-modern" title="Tambah Profile Baru"><i class="fa fa-plus"></i> Tambah Baru</a>
					</div>
				</div>
				<div class="card-body-mod">
					<div class="table-responsive" style="height: 100%; padding-bottom: 20px;">
						<table id="tFilter" class="table-dark-mod text-nowrap">
  <thead>
  <tr> 
		<th style="min-width:80px; text-align:center;" class="text-center" >Aksi</th>
		<th class="align-middle"><?= $_name ?></th>
		<th class="align-middle">Shared</th>
		<th class="align-middle">Rate</th>
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
</div>
