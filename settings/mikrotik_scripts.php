<?php
// hide all error
error_reporting(0);

if (!headers_sent()) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
}

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

$session = $_GET['session'] ?? '';
$session = trim((string)$session);
if ($session === '') {
    echo "<div class=\"alert alert-danger\">Session tidak ditemukan.</div>";
    return;
}

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

$script_onlogin = '';
$script_onlogout = '';
$tmpl_onlogin = __DIR__ . '/../tools/onlogin';
$tmpl_onlogout = __DIR__ . '/../tools/onlogout';
if (file_exists($tmpl_onlogin) && file_exists($tmpl_onlogout) && $base_url !== '') {
  clearstatcache(true, $tmpl_onlogin);
  clearstatcache(true, $tmpl_onlogout);
  if (function_exists('opcache_invalidate')) {
    @opcache_invalidate($tmpl_onlogin, true);
    @opcache_invalidate($tmpl_onlogout, true);
  }
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

<script>
  function copyScript(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.focus();
    el.select();
    try {
      document.execCommand('copy');
    } catch (e) {}
  }
</script>

<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="fa fa-code"></i> Script MikroTik (Auto)</h3>
      </div>
      <div class="card-body">
        <div class="row">
          <div class="col-6">
            <div class="card" style="border:1px solid #2c3e50;">
              <div class="card-header">
                <strong>On-Login</strong>
                <button type="button" class="btn btn-sm btn-primary float-right" onclick="copyScript('onlogin-output')">Copy</button>
              </div>
              <div class="card-body">
                <textarea id="onlogin-output" class="form-control" rows="14" readonly><?php echo htmlspecialchars($script_onlogin); ?></textarea>
              </div>
            </div>
          </div>
          <div class="col-6">
            <div class="card" style="border:1px solid #2c3e50;">
              <div class="card-header">
                <strong>On-Logout</strong>
                <button type="button" class="btn btn-sm btn-primary float-right" onclick="copyScript('onlogout-output')">Copy</button>
              </div>
              <div class="card-body">
                <textarea id="onlogout-output" class="form-control" rows="14" readonly><?php echo htmlspecialchars($script_onlogout); ?></textarea>
              </div>
            </div>
          </div>
        </div>
        <?php if ($script_onlogin === '' || $script_onlogout === ''): ?>
          <div class="alert alert-warning mt-2" role="alert" style="font-size:12px;">
            Script belum tersedia. Pastikan system.base_url dan token security di env terisi.
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
