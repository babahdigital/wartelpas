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
include_once __DIR__ . '/../include/version.php';

$is_operator = isOperator();
$version_raw = $_SESSION['v'] ?? '';
$version_parts = preg_split('/\s+/', trim($version_raw));
$version_label = $version_parts[0] ?? '-';
$build_label = $version_parts[1] ?? '-';
$server_mode = isMaintenanceEnabled() ? 'Maintenance' : 'Online';
$php_label = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.x';

$router_list = [];
if (isset($data) && is_array($data)) {
  foreach ($data as $key => $row) {
    if ($key == "mikhmon" || $key == "") {
      continue;
    }
    $ip_value = '';
    if (isset($row[1]) && strpos($row[1], '!') !== false) {
      $ip_value = explode('!', $row[1])[1];
    }
    $hotspot_label = isset($row[4]) ? explode('%', $row[4])[1] : $key;
    $router_list[] = [
      'session' => $key,
      'label' => $hotspot_label,
      'ip' => $ip_value,
      'active' => $ip_value !== ''
    ];
  }
}
$active_count = 0;
foreach ($router_list as $router_item) {
  if (!empty($router_item['active'])) {
    $active_count++;
  }
}
?>

<div class="row" style="margin-bottom: 10px;">
  <div class="col-12" style="display:flex; align-items:center; justify-content:space-between;">
    <h2 style="margin:0; font-weight:300;">Dashboard <strong style="font-weight:700;">Utama</strong></h2>
    <?php if (isSuperAdmin()): ?>
      <a class="btn-action btn-primary-m" data-no-ajax="1" href="./admin.php?id=settings&router=new-<?= rand(1111,9999); ?>">
        <i class="fa fa-plus-circle"></i> Tambah Router Baru
      </a>
    <?php endif; ?>
  </div>
</div>

<div class="row">
  <div class="col-6">
    <div class="card-modern">
      <div class="card-header-modern">
        <h3><i class="fa fa-server text-blue"></i> Daftar Router Tersedia</h3>
        <span class="badge"><?= $active_count; ?> ACTIVE</span>
        <?php if (isSuperAdmin()): ?>
          <span class="pointer" title="Tambah Router" onclick="window.location='./admin.php?id=settings&router=new-<?= rand(1111,9999); ?>'" style="margin-left:auto; color:var(--text-secondary);">
            <i class="fa fa-refresh"></i>
          </span>
        <?php endif; ?>
      </div>
      <div class="card-body-modern">
        <?php if (empty($router_list)): ?>
          <div class="admin-empty">Belum ada router.</div>
        <?php else: ?>
          <?php foreach ($router_list as $router_item): ?>
            <div class="router-item <?= $router_item['active'] ? 'active-router' : 'inactive-router'; ?>">
              <div class="router-icon"><i class="fa <?= $router_item['active'] ? 'fa-wifi' : 'fa-hdd-o'; ?>"></i></div>
              <div class="router-info">
                <span class="router-name"><?= htmlspecialchars($router_item['label']); ?></span>
                <span class="router-session">Sesi: <?= htmlspecialchars($router_item['session']); ?> | IP: <?= htmlspecialchars($router_item['ip'] !== '' ? $router_item['ip'] : 'Unset'); ?></span>
              </div>
              <div class="router-actions">
                <span class="connect" id="<?= htmlspecialchars($router_item['session']); ?>" title="Quick Connect"><i class="fa fa-bolt"></i></span>
                <?php if (isSuperAdmin()): ?>
                  <a href="./admin.php?id=settings&session=<?= htmlspecialchars($router_item['session']); ?>" title="Edit"><i class="fa fa-pencil"></i></a>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-6">
    <div class="card-modern">
      <div class="card-header-modern">
        <h3><i class="fa fa-info-circle text-green"></i> Status Sistem Mikhmon</h3>
      </div>
      <div class="card-body-modern">
        <div class="row">
          <div class="col-6">
            <div class="card-modern" style="margin-bottom:12px;">
              <div class="card-body-modern">
                <div class="text-secondary" style="font-size:12px;">Versi Sistem</div>
                <div style="font-size:18px; font-weight:700;">v<?= htmlspecialchars($version_label); ?> <span class="badge" style="margin-left:8px;">LATEST</span></div>
              </div>
            </div>
          </div>
          <div class="col-6">
            <div class="card-modern" style="margin-bottom:12px;">
              <div class="card-body-modern">
                <div class="text-secondary" style="font-size:12px;">Build Date</div>
                <div style="font-size:18px; font-weight:700;"><?= htmlspecialchars($build_label); ?></div>
              </div>
            </div>
          </div>
          <div class="col-6">
            <div class="card-modern">
              <div class="card-body-modern">
                <div class="text-secondary" style="font-size:12px;">Mode Server</div>
                <div style="font-size:18px; font-weight:700;"><?= htmlspecialchars($server_mode); ?></div>
              </div>
            </div>
          </div>
          <div class="col-6">
            <div class="card-modern">
              <div class="card-body-modern">
                <div class="text-secondary" style="font-size:12px;">PHP Version</div>
                <div style="font-size:18px; font-weight:700;"><?= htmlspecialchars($php_label); ?></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>









