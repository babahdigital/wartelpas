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

$env = [];
$envFile = __DIR__ . '/../include/env.php';
if (file_exists($envFile)) {
  require $envFile;
}

if ($id == "settings" && explode("-", $router)[0] == "new") {
  $data = '$data';
  $f = fopen('./include/config.php', 'a');
  fwrite($f, "\n'$'data['" . $router . "'] = array ('1'=>'" . $router . "!','" . $router . "@|@','" . $router . "#|#','" . $router . "%','" . $router . "^','" . $router . "&Rp','" . $router . "*10','" . $router . "(1','" . $router . ")','" . $router . "=10','" . $router . "@!@disable','" . $router . "~wartel');");
  fclose($f);
  $search = "'$'data";
  $replace = (string)$data;
  $content = file_get_contents("./include/config.php");
  $newcontent = str_replace((string)$search, (string)$replace, "$content");
  file_put_contents("./include/config.php", "$newcontent");
  echo "<script>window.location='./admin.php?id=settings&session=" . $router . "'</script>";
}

if (isset($_POST['save'])) {
  $siphost = (preg_replace('/\s+/', '', $_POST['ipmik']));
  $suserhost = ($_POST['usermik']);
  $spasswdhost = encrypt($_POST['passmik']);
  $shotspotname = str_replace("'", "", $_POST['hotspotname']);
  $sdnsname = ($_POST['dnsname']);
  $scurrency = ($_POST['currency']);
  $sreload = ($_POST['areload']);
  if ($sreload < 10) {
    $sreload = 10;
  }
  $siface = ($_POST['iface']);
  $sinfolp = implode(unpack("H*", $_POST['infolp']));
  $sidleto = ($_POST['idleto']);
  $shotspotserver = ($_POST['hotspotserver'] ?? 'wartel');

  $sesname = (preg_replace('/\s+/', '-', $_POST['sessname']));
  $slivereport = ($_POST['livereport']);

  $search = array('1' => "$session!$iphost", "$session@|@$userhost", "$session#|#$passwdhost", "$session%$hotspotname", "$session^$dnsname", "$session&$currency", "$session*$areload", "$session($iface", "$session)$infolp", "$session=$idleto", "'$session'", "$session@!@$livereport", "$session~$hotspot_server");

  $replace = array('1' => "$sesname!$siphost", "$sesname@|@$suserhost", "$sesname#|#$spasswdhost", "$sesname%$shotspotname", "$sesname^$sdnsname", "$sesname&$scurrency", "$sesname*$sreload", "$sesname($siface", "$sesname)$sinfolp", "$sesname=$sidleto", "'$sesname'", "$sesname@!@$slivereport", "$sesname~$shotspotserver");

  for ($i = 1; $i < 15; $i++) {
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

$script_onlogin = '';
$script_onlogout = '';
$base_url = '';
$system_cfg = $env['system'] ?? [];
if (!empty($system_cfg['base_url'])) {
  $base_url = rtrim((string)$system_cfg['base_url'], '/');
} else {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? '';
  $base_url = $host !== '' ? ($scheme . '://' . $host) : '';
}
$live_key = $env['security']['live_ingest']['token'] ?? '';
$usage_key = $env['security']['usage_ingest']['token'] ?? '';
if ($live_key === '') $live_key = $env['backup']['secret'] ?? '';
if ($usage_key === '') $usage_key = $env['backup']['secret'] ?? '';
$tmpl_onlogin = __DIR__ . '/../tools/onlogin';
$tmpl_onlogout = __DIR__ . '/../tools/onlogout';
if (file_exists($tmpl_onlogin) && file_exists($tmpl_onlogout) && $base_url !== '') {
  $replace = [
    '{{BASE_URL}}' => $base_url,
    '{{LIVE_KEY}}' => $live_key,
    '{{USAGE_KEY}}' => $usage_key,
    '{{SESSION}}' => $session
  ];
  $script_onlogin = str_replace(array_keys($replace), array_values($replace), file_get_contents($tmpl_onlogin));
  $script_onlogout = str_replace(array_keys($replace), array_values($replace), file_get_contents($tmpl_onlogout));
}
?>

<form autocomplete="off" method="post" action="" name="settings">
  <div class="row">
    <div class="col-12">
      <div class="card-modern">
          <div class="card-header-modern">
          <h3><i class="fa fa-sliders"></i> Konfigurasi Router: <?= htmlspecialchars($session); ?></h3>
          <div style="display:flex; gap:10px;">
            <button type="button" class="btn-action btn-success-m connect" id="<?= $session; ?>&c=settings">
              <i class="fa fa-plug"></i> Connect
            </button>
            <button type="button" class="btn-action btn-outline" id="ping_test">
              <i class="fa fa-exchange"></i> Ping Router
            </button>
          </div>
        </div>
        <div class="card-body-modern">
          <div class="row">
            <div class="col-6">
              <div class="card-modern">
                <div class="card-header-modern">
                  <h3><i class="fa fa-link"></i> Koneksi MikroTik</h3>
                </div>
                <div class="card-body-modern">
                  <div class="row">
                    <div class="col-6">
                      <div class="form-group-modern">
                        <label class="form-label">Nama Sesi (ID Unik)</label>
                        <div class="input-group-modern">
                          <div class="input-icon"><i class="fa fa-tag"></i></div>
                          <input class="form-control-modern" id="sessname" type="text" name="sessname" title="Session Name" value="<?php if (explode("-",$session)[0] == "new") {
                                                                                                                              echo "";
                                                                                                                            } else {
                                                                                                                              echo $session;
                                                                                                                            } ?>" required="1"/>
                        </div>
                      </div>
                    </div>
                    <div class="col-6">
                      <div class="form-group-modern">
                        <label class="form-label">IP MikroTik / Cloud ID</label>
                        <div class="input-group-modern">
                          <div class="input-icon"><i class="fa fa-globe"></i></div>
                          <input class="form-control-modern" type="text" name="ipmik" title="IP MikroTik / IP Cloud MikroTik" value="<?= $iphost; ?>" required="1"/>
                        </div>
                      </div>
                    </div>
                    <div class="col-6">
                      <div class="form-group-modern">
                        <label class="form-label">Username Router</label>
                        <div class="input-group-modern">
                          <div class="input-icon"><i class="fa fa-user"></i></div>
                          <input class="form-control-modern" id="usermk" type="text" name="usermik" title="User MikroTik" value="<?= $userhost; ?>" required="1"/>
                        </div>
                      </div>
                    </div>
                    <div class="col-6">
                      <div class="form-group-modern">
                        <label class="form-label">Password Router</label>
                        <div class="input-group-modern">
                          <div class="input-icon"><i class="fa fa-lock"></i></div>
                          <input class="form-control-modern" id="passmk" type="password" name="passmik" title="Password MikroTik" value="<?= decrypt($passwdhost); ?>" required="1"/>
                          <div class="toggle-pass" onclick="PassMk()"><i class="fa fa-eye"></i></div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-6">
              <div class="card-modern">
                <div class="card-header-modern">
                  <h3><i class="fa fa-wifi"></i> Data Hotspot</h3>
                </div>
                <div class="card-body-modern">
                  <div class="row">
                    <div class="col-6">
                      <div class="form-group-modern">
                        <label class="form-label">Nama Hotspot</label>
                        <div class="input-group-modern">
                          <div class="input-icon"><i class="fa fa-wifi"></i></div>
                          <input class="form-control-modern" type="text" maxlength="50" name="hotspotname" title="Hotspot Name" value="<?= $hotspotname; ?>" required="1"/>
                        </div>
                      </div>
                    </div>
                    <div class="col-6">
                      <div class="form-group-modern">
                        <label class="form-label">Hotspot Server</label>
                        <div class="input-group-modern">
                          <div class="input-icon"><i class="fa fa-server"></i></div>
                          <input class="form-control-modern" type="text" maxlength="50" name="hotspotserver" title="Nama server hotspot (contoh: wartel)" value="<?= $hotspot_server ?? 'wartel'; ?>" required="1"/>
                        </div>
                      </div>
                    </div>
                    <div class="col-6">
                      <div class="form-group-modern">
                        <label class="form-label">DNS Name</label>
                        <div class="input-group-modern">
                          <div class="input-icon"><i class="fa fa-globe"></i></div>
                          <input class="form-control-modern" type="text" maxlength="500" name="dnsname" title="DNS Name [IP->Hotspot->Server Profiles->DNS Name]" value="<?= $dnsname; ?>" required="1"/>
                        </div>
                      </div>
                    </div>
                    <div class="col-6">
                      <div class="form-group-modern">
                        <label class="form-label">Mata Uang</label>
                        <div class="input-group-modern">
                          <div class="input-icon"><i class="fa fa-money"></i></div>
                          <input class="form-control-modern" type="text" maxlength="4" name="currency" title="currency" value="<?= $currency; ?>" required="1"/>
                        </div>
                      </div>
                    </div>
                    <div class="col-4">
                      <div class="form-group-modern">
                        <label class="form-label">Auto Reload (dtk)</label>
                        <div class="input-group-modern">
                          <div class="input-icon"><i class="fa fa-refresh"></i></div>
                          <input class="form-control-modern" type="number" min="10" max="3600" name="areload" title="Auto Reload in sec [min 10]" value="<?= $areload; ?>" required="1"/>
                        </div>
                      </div>
                    </div>
                    <div class="col-4">
                      <div class="form-group-modern">
                        <label class="form-label">Idle Timeout</label>
                        <div class="input-group-modern">
                          <div class="input-icon"><i class="fa fa-clock-o"></i></div>
                          <select class="form-control-modern" name="idleto" required="1">
                            <option value="<?= $idleto; ?>"><?= $idleto; ?></option>
                            <option value="5">5</option>
                            <option value="10">10</option>
                            <option value="30">30</option>
                            <option value="60">60</option>
                            <option value="disable">disable</option>
                          </select>
                        </div>
                      </div>
                    </div>
                    <div class="col-4">
                      <div class="form-group-modern">
                        <label class="form-label">Traffic Interface</label>
                        <div class="input-group-modern">
                          <div class="input-icon"><i class="fa fa-exchange"></i></div>
                          <input class="form-control-modern" type="number" min="1" max="99" name="iface" title="Traffic Interface" value="<?= $iface; ?>" required="1"/>
                        </div>
                      </div>
                    </div>
                    <?php if (!empty($livereport)) { ?>
                    <div class="col-12">
                      <div class="form-group-modern">
                        <label class="form-label">Laporan Live</label>
                        <div class="input-group-modern">
                          <div class="input-icon"><i class="fa fa-line-chart"></i></div>
                          <select class="form-control-modern" name="livereport">
                            <option value="<?= $livereport; ?>"><?= ucfirst($livereport); ?></option>
                            <option value="enable">Enable</option>
                            <option value="disable">Disable</option>
                          </select>
                        </div>
                      </div>
                    </div>
                    <?php } ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div style="display:flex; justify-content:flex-end; margin-top: 10px;">
            <button class="btn-action btn-primary-m" type="submit" name="save">
              <i class="fa fa-save"></i> Simpan Konfigurasi Sesi
            </button>
          </div>
          <div id="ping" style="margin-top: 10px;"></div>
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
