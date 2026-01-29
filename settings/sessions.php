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
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
require_once __DIR__ . '/../include/acl.php';
requireLogin('../admin.php?id=login');

$is_operator = isOperator();

$maintenance_enabled = !empty($env['maintenance']['enabled']);


// array color
  $color = array('1' => 'bg-blue', 'bg-indigo', 'bg-purple', 'bg-pink', 'bg-red', 'bg-yellow', 'bg-green', 'bg-teal', 'bg-cyan', 'bg-grey', 'bg-light-blue');

  if (isset($_POST['save'])) {
    if ($is_operator) {
      echo "<script>alert('Akses ditolak. Hubungi Superadmin.'); window.location='./admin.php?id=sessions';</script>";
      exit;
    }

    if (!is_writable('./include/config.php')) {
      echo "<script>alert('Gagal menyimpan. File config.php tidak bisa ditulis.'); window.location='./admin.php?id=sessions';</script>";
      exit;
    }

    $suseradm = ($_POST['useradm']);
    $spassadm = encrypt($_POST['passadm']);
    $logobt = ($_POST['logobt']);
    $qrbt = ($_POST['qrbt']);

    $cari = array('1' => "mikhmon<|<$useradm", "mikhmon>|>$passadm");
    $ganti = array('1' => "mikhmon<|<$suseradm", "mikhmon>|>$spassadm");

    for ($i = 1; $i < 3; $i++) {
      $file = file("./include/config.php");
      $content = file_get_contents("./include/config.php");
      $newcontent = str_replace((string)$cari[$i], (string)$ganti[$i], "$content");
      $write_ok = file_put_contents("./include/config.php", "$newcontent");
      if ($write_ok === false) {
        echo "<script>alert('Gagal menyimpan. Periksa permission config.php.'); window.location='./admin.php?id=sessions';</script>";
        exit;
      }
    }

  
  $gen = '<?php $qrbt="' . $qrbt . '";?>';
          $key = './include/quickbt.php';
          if (!is_writable($key)) {
            echo "<script>alert('Gagal menyimpan. File quickbt.php tidak bisa ditulis.'); window.location='./admin.php?id=sessions';</script>";
            exit;
          }
          $handle = fopen($key, 'w') or die('Cannot open file:  ' . $key);
          $data = $gen;
          fwrite($handle, $data);

    $maintenance_enabled = isset($_POST['maintenance_enable']);
    $env_path = __DIR__ . '/../include/env.php';
    if (file_exists($env_path)) {
      if (!is_writable($env_path)) {
        echo "<script>alert('Gagal menyimpan. File env.php tidak bisa ditulis.'); window.location='./admin.php?id=sessions';</script>";
        exit;
      }
      $env_content = file_get_contents($env_path);
      $maintenance_value = $maintenance_enabled ? 'true' : 'false';
      $pattern = "/('maintenance'\\s*=>\\s*\\[\\s*'enabled'\\s*=>\\s*)(true|false)(\\s*\\])/";
      if (preg_match($pattern, $env_content)) {
        $env_content = preg_replace($pattern, "$1{$maintenance_value}$3", $env_content, 1);
      } else {
        $insert = "    'maintenance' => [\n        'enabled' => {$maintenance_value}\n    ],\n";
        if (strpos($env_content, "'rclone' => [") !== false) {
          $env_content = str_replace("    'rclone' => [", $insert . "    'rclone' => [", $env_content);
        } else {
          $env_content = str_replace("];", $insert . "];", $env_content);
        }
      }
      $env_write_ok = file_put_contents($env_path, $env_content);
      if ($env_write_ok === false) {
        echo "<script>alert('Gagal menyimpan konfigurasi maintenance.'); window.location='./admin.php?id=sessions';</script>";
        exit;
      }
    }
    echo "<script>window.location='./admin.php?id=sessions'</script>";
  }
?>
<script>
  function Pass(id){
    var x = document.getElementById(id);
    if (x.type === 'password') {
    x.type = 'text';
    } else {
    x.type = 'password';
    }}
</script>

<div class="row">
	<div class="col-12">
  	<div class="card">
  		<div class="card-header">
  			<h3 class="card-title"><i class="fa fa-gear"></i> <?= $_admin_settings ?> &nbsp; | &nbsp;&nbsp;<i onclick="location.reload();" class="fa fa-refresh pointer " title="Reload data"></i></h3>
  		</div>
      <div class="card-body">
        <div class="row">
          <div class="col-6">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><i class="fa fa-server"></i> <?= $_router_list ?></h3>
              </div>
            <div class="card-body">
            <div class="row">
              <?php
              if (isset($data) && is_array($data)) {
                foreach ($data as $key => $row) {
                  if ($key == "mikhmon" || $key == "") {
                    continue;
                  }
                  $value = $key;
                  $hotspot_label = isset($row[4]) ? explode('%', $row[4])[1] : $value;
                  ?>
                    <div class="col-12">
                        <div class="box bmh-75 box-bordered <?= $color[rand(1, 11)]; ?>">
                                <div class="box-group">
                                  
                                  <div class="box-group-icon">
                                    <span class="connect pointer" id="<?= $value; ?>">
                                    <i class="fa fa-server"></i>
                                    </span>
                                  </div>
                                
                                  <div class="box-group-area">
                                    <span>
                                      <?= $_hotspot_name ?> : <?= htmlspecialchars($hotspot_label); ?><br>
                                      <?= $_session_name ?> : <?= htmlspecialchars($value); ?><br>
                                      <span class="connect pointer"  id="<?= htmlspecialchars($value); ?>"><i class="fa fa-external-link"></i> <?= $_open ?></span>&nbsp;
                                      <?php if (isSuperAdmin()): ?>
                                      <a href="./admin.php?id=settings&session=<?= htmlspecialchars($value); ?>"><i class="fa fa-edit"></i> <?= $_edit ?></a>&nbsp;
                                      <a href="javascript:void(0)" onclick="if(confirm('Are you sure to delete data <?= htmlspecialchars($value);
                                      echo " (" . htmlspecialchars($hotspot_label) . ")"; ?>?')){loadpage('./admin.php?id=remove-session&session=<?= htmlspecialchars($value); ?>')}else{}"><i class="fa fa-remove"></i> <?= $_delete ?></a>
                                      <?php endif; ?>
                                    </span>

                                  </div>
                                </div>
                              
                            </div>
                          </div>
              <?php
                }
              }
              ?>
              </div>
            </div>
          </div>
        </div>
			    <div class="col-6">
          <?php if ($is_operator): ?>
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><i class="fa fa-user-circle"></i> <?= $_admin ?></h3>
              </div>
              <div class="card-body">
                <div class="box bg-warning" style="padding:10px;border-radius:6px;">
                  Akses admin hanya untuk Superadmin.
                </div>
              </div>
            </div>
          <?php else: ?>
          <form autocomplete="off" method="post" action="">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><i class="fa fa-user-circle"></i> <?= $_admin ?></h3>
              </div>
            <div class="card-body">
      <table class="table table-sm">
        <tr>
          <td class="align-middle"><?= $_user_name ?> </td><td><input class="form-control" id="useradm" type="text" size="10" name="useradm" title="User Admin" value="<?= $useradm; ?>" required="1"/></td>
        </tr>
        <tr>
          <td class="align-middle"><?= $_password ?> </td>
          <td>
          <div class="input-group">
          <div class="input-group-11 col-box-10">
                <input class="group-item group-item-l" id="passadm" type="password" size="10" name="passadm" title="Password Admin" value="<?= decrypt($passadm); ?>" required="1"/>
              </div>
                <div class="input-group-1 col-box-2">
                  <div class="group-item group-item-r pd-2p5 text-center align-middle">
                      <input title="Show/Hide Password" type="checkbox" onclick="Pass('passadm')">
                  </div>
                </div>
            </div>
          </td>
        </tr>
        <tr>
          <td class="align-middle"><?= $_quick_print ?> QR</td>
          <td>
            <select class="form-control" name="qrbt">
            <option><?= $qrbt ?></option>
              <option>enable</option>
              <option>disable</option>
            </select>
          </td>
        </tr>
        <tr>
          <td class="align-middle">Maintenance</td>
          <td>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
              <input type="checkbox" name="maintenance_enable" value="1" <?= $maintenance_enabled ? 'checked' : '' ?>>
              <span>Aktifkan mode maintenance (Operator diarahkan ke maintenance)</span>
            </label>
          </td>
        </tr>
        <tr>
          <td></td><td class="text-right">
              <div class="input-group-4">
                  <input class="group-item group-item-l" type="submit" style="cursor: pointer;" name="save" value="<?= $_save ?>"/>
                </div>
                <div class="input-group-2">
                  <div style="cursor: pointer;" class="group-item group-item-r pd-2p5 text-center" onclick="location.reload();" title="Reload Data"><i class="fa fa-refresh"></i></div>
                </div>
                </div>
          </td>
        </tr>
        
      </table>
      <div id="loadV">v<?= $_SESSION['v']; ?> </div>
      <div><b id="newVer" class="text-green"></b></div>
    </div>
    </div>
        </form>
          <?php endif; ?>
  </div>
</div>
</div>
</div>
</div>
</div>









