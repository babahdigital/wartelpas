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
require_once __DIR__ . '/../include/db.php';
app_db_import_legacy_if_needed();
requireLogin('../admin.php?id=login');
requireSuperAdmin('../admin.php?id=sessions');

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$save_message = '';
$save_type = '';
$new_session_name = '';

function render_admin_error($message, $backUrl)
{
  $safeMessage = htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8');
  $safeUrl = htmlspecialchars((string)$backUrl, ENT_QUOTES, 'UTF-8');
  echo "<div class='alert alert-danger'>" . $safeMessage . "</div>";
  echo "<div style='margin-top:10px;'><a class='btn-action btn-outline' data-no-ajax='1' href='{$safeUrl}'>Kembali</a></div>";
  exit;
}

$env = [];
$envFile = __DIR__ . '/../include/env.php';
if (file_exists($envFile)) {
  require $envFile;
}

$session_is_new = (!empty($session) && explode('-', $session)[0] === 'new');
$router_is_new = (!empty($router) && explode('-', $router)[0] === 'new');
$is_new_router = ($id === "settings" && ($router_is_new || ($session_is_new && (!isset($data[$session]) || !is_array($data[$session])))));
if ($is_new_router) {
  if (empty($session)) {
    $session = $router;
  }
  $iphost = '';
  $userhost = '';
  $passwdhost = '';
  $hotspotname = '';
  $dnsname = '';
  $currency = '';
  $areload = 10;
  $iface = '';
  $infolp = '';
  $idleto = '10';
  $livereport = 'disable';
  if (empty($hotspot_server)) {
    $hotspot_server = 'wartel';
  }
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

  $is_new_save = ($is_new_router || (isset($session) && $session !== '' && explode('-', $session)[0] === 'new'));
  if ($is_new_save) {
    if (app_db_session_exists($sesname)) {
      render_admin_error('Gagal menambah router. Nama sesi sudah digunakan.', './admin.php?id=sessions');
    }
    $save = app_db_upsert_session($sesname, $sesname, [
      'iphost' => $siphost,
      'userhost' => $suserhost,
      'passwdhost' => $spasswdhost,
      'hotspotname' => $shotspotname,
      'dnsname' => $sdnsname,
      'currency' => $scurrency,
      'areload' => $sreload,
      'iface' => $siface,
      'infolp' => $sinfolp,
      'idleto' => $sidleto,
      'livereport' => $slivereport,
      'hotspot_server' => $shotspotserver,
    ]);
    if (empty($save['ok'])) {
      @error_log(date('c') . " [admin][settings] db insert failed (new router save)\n", 3, __DIR__ . '/../logs/admin_errors.log');
      render_admin_error($save['message'] ?? 'Gagal menambah router. Database tidak bisa ditulis.', './admin.php?id=sessions');
    }
    $save_message = 'Sesi baru berhasil disimpan: ' . $sesname;
    $save_type = 'success';
    $new_session_name = $sesname;
    $session = $sesname;
    $iphost = $siphost;
    $userhost = $suserhost;
    $passwdhost = $spasswdhost;
    $hotspotname = $shotspotname;
    $dnsname = $sdnsname;
    $currency = $scurrency;
    $areload = $sreload;
    $iface = $siface;
    $infolp = $sinfolp;
    $idleto = $sidleto;
    $livereport = $slivereport;
    $hotspot_server = $shotspotserver;
    $is_new_router = false;
    if (!$is_ajax) {
      echo "<script>window.location='./admin.php?id=settings&session=" . $sesname . "'</script>";
      exit;
    }
  }

  $save = app_db_upsert_session($session, $sesname, [
    'iphost' => $siphost,
    'userhost' => $suserhost,
    'passwdhost' => $spasswdhost,
    'hotspotname' => $shotspotname,
    'dnsname' => $sdnsname,
    'currency' => $scurrency,
    'areload' => $sreload,
    'iface' => $siface,
    'infolp' => $sinfolp,
    'idleto' => $sidleto,
    'livereport' => $slivereport,
    'hotspot_server' => $shotspotserver,
  ]);
  if (empty($save['ok'])) {
    @error_log(date('c') . " [admin][settings] db update failed (save session)\n", 3, __DIR__ . '/../logs/admin_errors.log');
    render_admin_error($save['message'] ?? 'Gagal menyimpan. Database tidak bisa ditulis.', './admin.php?id=settings&session=' . $session);
  }
  $_SESSION["connect"] = "";
  $save_message = 'Konfigurasi router berhasil disimpan.';
  $save_type = 'success';
  @error_log(date('c') . " [admin][settings] saved session=" . $sesname . "\n", 3, __DIR__ . '/../logs/admin_errors.log');
  $session = $sesname;
  $iphost = $siphost;
  $userhost = $suserhost;
  $passwdhost = $spasswdhost;
  $hotspotname = $shotspotname;
  $dnsname = $sdnsname;
  $currency = $scurrency;
  $areload = $sreload;
  $iface = $siface;
  $infolp = $sinfolp;
  $idleto = $sidleto;
  $livereport = $slivereport;
  $hotspot_server = $shotspotserver;
  if (!$is_ajax) {
    echo "<script>window.location='./admin.php?id=settings&session=" . $sesname . "'</script>";
  }
}
if ($currency == "" && !$is_new_router) {
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

<form autocomplete="off" method="post" action="" name="settings" data-admin-form="settings">
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
          <?php if ($save_message !== ''): ?>
            <div class="alert alert-<?= htmlspecialchars($save_type ?: 'info'); ?>" style="margin-bottom: 12px;" <?= $new_session_name !== '' ? 'data-new-session="' . htmlspecialchars($new_session_name) . '"' : ''; ?>>
              <?= htmlspecialchars($save_message); ?>
            </div>
          <?php endif; ?>
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
                          <input class="form-control-modern" id="usermk" type="text" name="usermik" title="User MikroTik" value="<?= $userhost; ?>" required="1" autocomplete="username"/>
                        </div>
                      </div>
                    </div>
                    <div class="col-6">
                      <div class="form-group-modern">
                        <label class="form-label">Password Router</label>
                        <div class="input-group-modern">
                          <div class="input-icon"><i class="fa fa-lock"></i></div>
                          <input class="form-control-modern" id="passmk" type="password" name="passmik" title="Password MikroTik" value="<?= decrypt($passwdhost); ?>" required="1" autocomplete="current-password"/>
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
          <div style="display:flex; justify-content:flex-end; gap:10px; margin-top: 10px;">
            <?php if (!empty($is_new_router)): ?>
              <a class="btn-action btn-outline" data-no-ajax="1" href="./admin.php?id=sessions">
                <i class="fa fa-times"></i> Batal
              </a>
            <?php endif; ?>
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
