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
requireSuperAdmin('../admin.php?id=sessions');


  if ($id == "settings" && explode("-",$router)[0] == "new") {
    $data = '$data';
    $f = fopen('./include/config.php', 'a');
    fwrite($f, "\n'$'data['".$router."'] = array ('1'=>'".$router."!','".$router."@|@','".$router."#|#','".$router."%','".$router."^','".$router."&Rp','".$router."*10','".$router."(1','".$router.")','".$router."=10','".$router."@!@disable','".$router."~wartel');");
    fclose($f);
    $search = "'$'data";
    $replace = (string)"$data";
    $file = file("./include/config.php");
    $content = file_get_contents("./include/config.php");
    $newcontent = str_replace((string)$search, (string)$replace, "$content");
    file_put_contents("./include/config.php", "$newcontent");
    echo "<script>window.location='./admin.php?id=settings&session=" . $router . "'</script>";
  }

  if (isset($_POST['save'])) {

    $siphost = (preg_replace('/\s+/', '', $_POST['ipmik']));
    $suserhost = ($_POST['usermik']);
    $spasswdhost = encrypt($_POST['passmik']);
    $shotspotname = str_replace("'","",$_POST['hotspotname']);
    $sdnsname = ($_POST['dnsname']);
    $scurrency = ($_POST['currency']);
    $sreload = ($_POST['areload']);
    if ($sreload < 10) {
      $sreload = 10;
    } else {
      $sreload = $sreload;
    }
    $siface = ($_POST['iface']);
    $sinfolp = implode(unpack("H*", $_POST['infolp']));
    //$sinfolp = encrypt($_POST['infolp']);
    //$sinfolp = ($_POST['infolp']);
    $sidleto = ($_POST['idleto']);
    $shotspotserver = ($_POST['hotspotserver'] ?? 'wartel');

    $sesname = (preg_replace('/\s+/', '-', $_POST['sessname']));
    $slivereport = ($_POST['livereport']);

    $search = array('1' => "$session!$iphost", "$session@|@$userhost", "$session#|#$passwdhost", "$session%$hotspotname", "$session^$dnsname", "$session&$currency", "$session*$areload", "$session($iface", "$session)$infolp", "$session=$idleto", "'$session'", "$session@!@$livereport", "$session~$hotspot_server");

    $replace = array('1' => "$sesname!$siphost", "$sesname@|@$suserhost", "$sesname#|#$spasswdhost", "$sesname%$shotspotname", "$sesname^$sdnsname", "$sesname&$scurrency", "$sesname*$sreload", "$sesname($siface", "$sesname)$sinfolp", "$sesname=$sidleto", "'$sesname'", "$sesname@!@$slivereport", "$sesname~$shotspotserver");

    for ($i = 1; $i < 15; $i++) {
      $file = file("./include/config.php");
      $content = file_get_contents("./include/config.php");
      $newcontent = str_replace((string)$search[$i], (string)$replace[$i], "$content");
      file_put_contents("./include/config.php", "$newcontent");
    }
    $_SESSION["connect"] = "";
    echo "<script>window.location='./admin.php?id=settings&session=" . $sesname . "'</script>";
  }
  if ($currency == "") {
    echo "<script>window.location='./admin.php?id=settings&session=" . $session . "'</script>";
  }
?>
<script>
  function PassMk(){
    var x = document.getElementById('passmk');
    if (x.type === 'password') {
    x.type = 'text';
    } else {
    x.type = 'password';
    }}
    function PassAdm(){
    var x = document.getElementById('passadm');
    if (x.type === 'password') {
    x.type = 'text';
    } else {
    x.type = 'password';
  }}
  
</script>

<form autocomplete="off" method="post" action="" name="settings">  
<div class="row">
	<div class="col-12">
  		<div class="card" >
  			<div class="card-header">
  				<h3 class="card-title"><i class="fa fa-gear"></i> <?= $_session_settings ?> &nbsp; | &nbsp;&nbsp;<i onclick="location.reload();" class="fa fa-refresh pointer " title="Reload data"></i></h3>
  			</div>
        <div class="card-body">
    	   <div class="row">
			     <div class="col-6">
            <div class="col-12">
              <div class="card">
                <div class="card-header">
                  <h3 class="card-title"><?= $_session ?></h3>
                </div>
                <div class="card-body">
                  <table class="table">
                    <tr>
                      <td><?= $_session_name ?></td>
                      <td><input class="form-control" id="sessname" type="text" name="sessname" title="Session Name" value="<?php if (explode("-",$session)[0] == "new") {
                                                                                                                              echo "";
                                                                                                                            } else {
                                                                                                                              echo $session;
                                                                                                                            } ?>" required="1"/></td>
                    </tr>
                  </table>
                </div>
              </div>
            </div>
            <div class="col-12">
				      <div class="card">
        	     <div class="card-header">
            	   <h3 class="card-title">MikroTik <?= $_SESSION["connect"]; ?></h3>
        	     </div>
        	     <div class="card-body">
				<table class="table table-sm">
					<tr>
	  					<td class="align-middle">IP MikroTik </td><td><input class="form-control" type="text" size="15" name="ipmik" title="IP MikroTik / IP Cloud MikroTik" value="<?= $iphost; ?>" required="1"/></td>
					</tr>
					<tr>
						<td class="align-middle">Username  </td><td><input class="form-control" id="usermk" type="text" size="10" name="usermik" title="User MikroTik" value="<?= $userhost; ?>" required="1"/></td>
					</tr>
					<tr>
						<td class="align-middle">Password  </td><td>
							<div class="input-group">
								<div class="input-group-11 col-box-10">
        						<input class="group-item group-item-l" id="passmk" type="password" name="passmik" title="Password MikroTik" value="<?= decrypt($passwdhost); ?>" required="1"/>
        						</div>
            					<div class="input-group-1 col-box-2">
            						<div class="group-item group-item-r pd-2p5 text-center align-middle">
                						<input title="Show/Hide Password" type="checkbox" onclick="PassMk()">
            						</div>
            					</div>
    						</div>
						</td>
					</tr>
					<tr>
						<td colspan="2">
								<div class="input-group-4">
									<input class="group-item group-item-md" type="submit" style="cursor: pointer;" name="save" value="Save"/>
								</div>
								<div class="input-group-4">	
                  <span class="connect pointer group-item group-item-md pd-2p5 text-center align-middle" id="<?= $session; ?>&c=settings">Connect</span>
								</div>
								<div class="input-group-3">	
                  <span class="pointer group-item group-item-md pd-2p5 text-center align-middle" id="ping_test">Ping</span>
              	</div>
              	<div class="input-group-1">	
									<div style="cursor: pointer;" class="group-item group-item-r pd-2p5 text-center" onclick="location.reload();" title="Reload Data"><i class="fa fa-refresh"></i></div>
								</div>
            		</div>	
    					</td>
    				</tr>
				</table>
			</div>
    </div>  	
    <div id="ping">
    </div>	
	</div>
</div>
<div class="col-6">
<div class="col-12">
	<div class="card">
        <div class="card-header">
            <h3 class="card-title">Mikhmon Data</h3>
        </div>
    <div class="card-body">    
	<table class="table table-sm">
	<tr>
	<td class="align-middle"><?= $_hotspot_name ?>  </td><td><input class="form-control" type="text" size="15" maxlength="50" name="hotspotname" title="Hotspot Name" value="<?= $hotspotname; ?>" required="1"/></td>
	</tr>
  <tr>
  <td class="align-middle">Hotspot Server</td><td><input class="form-control" type="text" size="15" maxlength="50" name="hotspotserver" title="Nama server hotspot (contoh: wartel)" value="<?= $hotspot_server ?? 'wartel'; ?>" required="1"/></td>
  </tr>
	<tr>
	<td class="align-middle"><?= $_dns_name ?>  </td><td><input class="form-control" type="text" size="15" maxlength="500" name="dnsname" title="DNS Name [IP->Hotspot->Server Profiles->DNS Name]" value="<?= $dnsname; ?>" required="1"/></td>
	</tr>
	<tr>
	<td class="align-middle"><?= $_currency ?>  </td><td><input class="form-control" type="text" size="3" maxlength="4" name="currency" title="currency" value="<?= $currency; ?>" required="1"/></td>
	</tr>
	<tr> 
	<td class="align-middle"><?= $_auto_reload ?></td><td>
	<div class="input-group">
		<div class="input-group-10">
        	<input class="group-item group-item-l" type="number" min="10" max="3600" name="areload" title="Auto Reload in sec [min 10]" value="<?= $areload; ?>" required="1"/>
    	</div>
            <div class="input-group-2">
                <span class="group-item group-item-r pd-2p5 text-center align-middle"><?= $_sec ?></span>
            </div>
        </div>
	</td>
  </tr>
  <tr>
  <td class="align-middle"><?= $_idle_timeout ?></td>
  <td>
  <div class="input-group">
  <div class="input-group-9">
      <select class="group-item group-item-l" name="idleto" required="1">
          <option value="<?= $idleto; ?>"><?= $idleto; ?></option>
				  <option value="5">5</option>
          <option value="10">10</option>
          <option value="30">30</option>
          <option value="60">60</option>
          <option value="disable">disable</option>
      </select>
  </div>
  <div class="input-group-3">
                <span class="group-item group-item-r pd-3p5 text-center align-middle"><?= $_min ?></span>
            </div>
        </div>
    </td>
	</tr>
	<tr>
	<td class="align-middle"><?= $_traffic_interface ?></td><td><input class="form-control" type="number" min="1" max="99" name="iface" title="Traffic Interface" value="<?= $iface; ?>" required="1"/></td>
	</tr>
  <?php if (empty($livereport)) {
  } else { ?>
  <tr>
    <td><?= $_live_report ?></td>
    <td>
      <select class="form-control" name="livereport" >
          <option value="<?= $livereport; ?>"><?= ucfirst($livereport); ?></option>
				  <option value="enable">Enable</option>
				  <option value="disable">Disable</option>
		  </select>
    </td>
  </tr>
  <?php 
} ?>
</table>
</div>
</div>
</div>
</div>
</div>
</form>
<script type="text/javascript">
  var sessionName = "<?= htmlspecialchars($session); ?>";

  function pingTest() {
    var target = document.getElementById('ping');
    if (!target || !window.jQuery) return;
    $(target).load('./status/ping-test.php?ping&session=' + encodeURIComponent(sessionName));
  }

  function closeX() {
    if (window.jQuery) {
      $('#pingX').hide();
    }
  }

  var pingBtn = document.getElementById('ping_test');
  if (pingBtn) {
    pingBtn.onclick = pingTest;
  }

  var sesname = document.forms.settings ? document.forms.settings.sessname : null;
  function chksname() {
    if (!sesname) return;
    var v = (sesname.value || '').toLowerCase();
    if (v === 'mikhmon') {
      alert('You cannot use ' + sesname.value + ' as a session name.');
      sesname.value = '';
      window.location.reload();
    }
  }
  if (sesname) {
    sesname.onkeyup = chksname;
    sesname.onchange = chksname;
  }

</script>





