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
session_start();
// hide all error
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {

  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }

  $getallqueue = $API->comm("/queue/simple/print", array(
    "?dynamic" => "false",
  ));

  $getpool = $API->comm("/ip/pool/print");

  if (isset($_POST['name'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
      echo "<script>window.location='./error.php';</script>";
      exit;
    }
    $name = (preg_replace('/\s+/', '-',$_POST['name']));
    $sharedusers = ($_POST['sharedusers']);
    $ratelimit = ($_POST['ratelimit']);
    $expmode = ($_POST['expmode']);
    $validity = ($_POST['validity']);
    $graceperiod = ($_POST['graceperiod']);
    $getprice = ($_POST['price']);
    $getsprice = ($_POST['sprice']);
    $addrpool = ($_POST['ppool']);
    if ($getprice == "") {
      $price = "0";
    } else {
      $price = $getprice;
    }
    if ($getsprice == "") {
      $sprice = "0";
    } else {
      $sprice = $getsprice;
    }
    $getlock = ($_POST['lockunlock']);
    if ($getlock == "Enable") {
      $lock = '; [:local mac $"mac-address"; /ip hotspot user set mac-address=$mac [find where name=$user]]';
    } else {
      $lock = "";
    }

    $randstarttime = "0".rand(1,5).":".rand(10,59).":".rand(10,59);
    $randinterval = "00:02:".rand(10,59);

    $parent = ($_POST['parent']);
    
    $record = '; :local mac $"mac-address"; :local time [/system clock get time ]; /system script add name="$date-|-$time-|-$user-|-'.$price.'-|-$address-|-$mac-|-' . $validity . '-|-'.$name.'-|-$comment" owner="$month$year" source="$date" comment="mikhmon"';
    
    $onlogin = ':put (",'.$expmode.',' . $price . ',' . $validity . ','.$sprice.',,' . $getlock . ',"); {:local comment [ /ip hotspot user get [/ip hotspot user find where name="$user"] comment]; :local ucode [:pic $comment 0 2]; :if ($ucode = "vc" or $ucode = "up" or $comment = "") do={ :local date [ /system clock get date ];:local year [ :pick $date 7 11 ];:local month [ :pick $date 0 3 ]; /sys sch add name="$user" disable=no start-date=$date interval="' . $validity . '"; :delay 5s; :local exp [ /sys sch get [ /sys sch find where name="$user" ] next-run]; :local getxp [len $exp]; :if ($getxp = 15) do={ :local d [:pic $exp 0 6]; :local t [:pic $exp 7 16]; :local s ("/"); :local exp ("$d$s$year $t"); /ip hotspot user set comment="$exp" [find where name="$user"];}; :if ($getxp = 8) do={ /ip hotspot user set comment="$date $exp" [find where name="$user"];}; :if ($getxp > 15) do={ /ip hotspot user set comment="$exp" [find where name="$user"];};:delay 5s; /sys sch remove [find where name="$user"]';
    

    if ($expmode == "rem") {
      $onlogin = $onlogin . $lock . "}}";
      $mode = "remove";
    } elseif ($expmode == "ntf") {
      $onlogin = $onlogin . $lock . "}}";
      $mode = "set limit-uptime=1s";
    } elseif ($expmode == "remc") {
      $onlogin = $onlogin . $record . $lock . "}}";
      $mode = "remove";
    } elseif ($expmode == "ntfc") {
      $onlogin = $onlogin . $record . $lock . "}}";
      $mode = "set limit-uptime=1s";
    } elseif ($expmode == "0" && $price != "") {
      $onlogin = ':put (",,' . $price . ',,,noexp,' . $getlock . ',")' . $lock;
    } else {
      $onlogin = "";
    }

    $bgservice = ':local dateint do={:local montharray ( "jan","feb","mar","apr","may","jun","jul","aug","sep","oct","nov","dec" );:local days [ :pick $d 4 6 ];:local month [ :pick $d 0 3 ];:local year [ :pick $d 7 11 ];:local monthint ([ :find $montharray $month]);:local month ($monthint + 1);:if ( [len $month] = 1) do={:local zero ("0");:return [:tonum ("$year$zero$month$days")];} else={:return [:tonum ("$year$month$days")];}}; :local timeint do={ :local hours [ :pick $t 0 2 ]; :local minutes [ :pick $t 3 5 ]; :return ($hours * 60 + $minutes) ; }; :local date [ /system clock get date ]; :local time [ /system clock get time ]; :local today [$dateint d=$date] ; :local curtime [$timeint t=$time] ; :foreach i in [ /ip hotspot user find where profile="'.$name.'" ] do={ :local comment [ /ip hotspot user get $i comment]; :local name [ /ip hotspot user get $i name]; :local gettime [:pic $comment 12 20]; :if ([:pic $comment 3] = "/" and [:pic $comment 6] = "/") do={:local expd [$dateint d=$comment] ; :local expt [$timeint t=$gettime] ; :if (($expd < $today and $expt < $curtime) or ($expd < $today and $expt > $curtime) or ($expd = $today and $expt < $curtime)) do={ [ /ip hotspot user '.$mode.' $i ]; [ /ip hotspot active remove [find where user=$name] ];}}}';

    $API->comm("/ip/hotspot/user/profile/add", array(
			  		  /*"add-mac-cookie" => "yes",*/
      "name" => "$name",
      "address-pool" => "$addrpool",
      "rate-limit" => "$ratelimit",
      "shared-users" => "$sharedusers",
      "status-autorefresh" => "1m",
      //"transparent-proxy" => "yes",
      "on-login" => "$onlogin",
      "parent-queue" => "$parent",
    ));

    if($expmode != "0"){
      if (empty($monid)){
        $API->comm("/system/scheduler/add", array(
          "name" => "$name",
          "start-time" => "$randstarttime",
          "interval" => "$randinterval",
          "on-event" => "$bgservice",
          "disabled" => "no",
          "comment" => "Monitor Profile $name",
          ));
      }else{
      $API->comm("/system/scheduler/set", array(
        ".id" => "$monid",
        "name" => "$name",
        "start-time" => "$randstarttime",
        "interval" => "$randinterval",
        "on-event" => "$bgservice",
        "disabled" => "no",
        "comment" => "Monitor Profile $name",
        ));
      }}else{
        $API->comm("/system/scheduler/remove", array(
          ".id" => "$monid"));
      }

    $getprofile = $API->comm("/ip/hotspot/user/profile/print", array(
      "?name" => "$name",
    ));
    $pid = $getprofile[0]['.id'];
    echo "<script>window.location='./?user-profile=" . $pid . "&session=" . $session . "'</script>";
  }
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
        --warning: #f59e0b;
    }

    .profile-wrapper { padding: 16px 18px; }
    @media (min-width: 992px) {
        .profile-wrapper { padding: 20px 26px; }
    }

    .row-eq-height { display: flex; flex-wrap: wrap; }

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
        margin-left: 30px;
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

    .card-body-mod {
        padding: 20px;
        flex-grow: 1;
        display: flex;
        flex-direction: column;
    }

    .form-group label {
        color: var(--text-sec);
        font-size: 0.85rem;
        margin-bottom: 5px;
        display: block;
    }

    .form-control-mod {
        width: 100%;
        background-color: var(--bg-input);
        border: 1px solid var(--border-c);
        color: var(--text-pri);
        padding: 10px 12px;
        border-radius: 6px;
        font-size: 0.95rem;
        transition: border 0.2s;
        margin-bottom: 5px;
        min-height: 42px;
    }

    .form-control-mod:focus {
        border-color: var(--accent);
        outline: none;
        box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
    }

    .btn-row {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        margin-bottom: 15px;
    }

    .btn-primary-modern {
        background: linear-gradient(to right, var(--accent), var(--accent-hover));
        color: #fff;
        border: none;
        padding: 10px 16px;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        transition: transform 0.1s;
    }

    .btn-primary-modern:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(59, 130, 246, 0.3); }

    .btn-warning-modern {
        background: rgba(245, 158, 11, 0.15);
        color: var(--warning);
        border: 1px solid rgba(245, 158, 11, 0.35);
        padding: 10px 16px;
        border-radius: 6px;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .btn-warning-modern:hover { background: rgba(245, 158, 11, 0.25); color: #fff; }

    .info-box {
        color: var(--text-pri);
        font-size: 0.9rem;
        line-height: 1.5;
    }

    .form-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 14px 16px;
    }

    @media (min-width: 768px) {
      .form-grid { grid-template-columns: 1fr 1fr; }
    }
</style>

<div class="container-fluid profile-wrapper">
  <div class="row row-eq-height g-3">
    <div class="col-8" style="margin-left: -30px;">
      <div class="card-modern">
        <div class="card-header-mod">
          <h3><i class="fa fa-plus"></i> <?= $_add.' '.$_user_profile ?> <small id="loader" style="display: none;"><i><i class='fa fa-circle-o-notch fa-spin'></i> Processing... </i></small></h3>
        </div>
        <div class="card-body-mod">
          <form autocomplete="off" method="post" action="" style="display:flex; flex-direction:column; height:100%;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES); ?>">
            <div class="btn-row">
              <a class="btn-warning-modern" href="./?hotspot=user-profiles&session=<?= $session; ?>">
                <i class="fa fa-close"></i> <?= $_close ?>
              </a>
              <button type="submit" name="save" class="btn-primary-modern">
                <i class="fa fa-save"></i> <?= $_save ?>
              </button>
            </div>

            <div class="form-grid">
              <div class="form-group">
                <label><?= $_name ?></label>
                <input class="form-control-mod" type="text" onchange="remSpace();" autocomplete="off" name="name" value="" required="1" autofocus>
              </div>
              <div class="form-group">
                <label>Address Pool</label>
                <select class="form-control-mod" name="ppool">
                  <option>none</option>
                  <?php $TotalReg = count($getpool);
                  for ($i = 0; $i < $TotalReg; $i++) {
                    echo "<option>" . $getpool[$i]['name'] . "</option>";
                  }
                  ?>
                </select>
              </div>
              <div class="form-group">
                <label>Shared Users</label>
                <input class="form-control-mod" type="text" autocomplete="off" name="sharedusers" value="1" required="1">
              </div>
              <div class="form-group">
                <label>Rate limit [up/down]</label>
                <input class="form-control-mod" type="text" name="ratelimit" autocomplete="off" value="" placeholder="Example : 512k/1M">
              </div>
              <div class="form-group">
                <label><?= $_expired_mode ?></label>
                <select class="form-control-mod" onchange="RequiredV();" id="expmode" name="expmode" required="1">
                  <option value="">Select...</option>
                  <option value="0">None</option>
                  <option value="rem">Remove</option>
                  <option value="ntf">Notice</option>
                  <option value="remc">Remove & Record</option>
                  <option value="ntfc">Notice & Record</option>
                </select>
              </div>
              <div id="validity" style="display:none;" class="form-group">
                <label><?= $_validity ?></label>
                <input class="form-control-mod" type="text" id="validi" autocomplete="off" name="validity" value="" required="1">
              </div>
              <div id="graceperiod" style="display:none;" class="form-group">
                <label><?= $_grace_period ?></label>
                <input class="form-control-mod" type="text" id="gracepi" autocomplete="off" name="graceperiod" placeholder="5m" value="5m" required="1">
              </div>
              <div class="form-group">
                <label><?= $_price.' '.$currency; ?></label>
                <input class="form-control-mod" type="text" min="0" name="price" value="">
              </div>
              <div class="form-group">
                <label><?= $_selling_price.' '.$currency; ?></label>
                <input class="form-control-mod" type="text" min="0" name="sprice" value="">
              </div>
              <div class="form-group">
                <label><?= $_lock_user ?></label>
                <select class="form-control-mod" id="lockunlock" name="lockunlock" required="1">
                  <option value="Disable">Disable</option>
                  <option value="Enable">Enable</option>
                </select>
              </div>
              <div class="form-group">
                <label>Parent Queue</label>
                <select class="form-control-mod" name="parent">
                  <option>none</option>
                  <?php $TotalReg = count($getallqueue);
                  for ($i = 0; $i < $TotalReg; $i++) {
                    echo "<option>" . $getallqueue[$i]['name'] . "</option>";
                  }
                  ?>
                </select>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-4" style="margin-left: 30px;">
      <div class="card-modern">
        <div class="card-header-mod">
          <h3><i class="fa fa-book"></i> <?= $_readme ?></h3>
        </div>
        <div class="card-body-mod">
          <div class="info-box">
            <p><?= $_details_user_profile ?></p>
            <p><?= $_format_validity ?></p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script type="text/javascript">
function remSpace() {
  var upName = document.getElementsByName("name")[0];
  var newUpName = upName.value.replace(/\s/g, "-");
  //alert("<?php if ($currency == in_array($currency, $cekindo['indo'])) {
            echo "Nama Profile tidak boleh berisi spasi";
          } else {
            echo "Profile name can't containing white space!";
          } ?>");
  upName.value = newUpName;
  upName.focus();
}
</script>
