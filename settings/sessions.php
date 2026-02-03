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
require_once __DIR__ . '/../include/db_helpers.php';

$is_operator = isOperator();
$version_raw = $_SESSION['v'] ?? '';
$version_parts = preg_split('/\s+/', trim($version_raw));
$version_label = $version_parts[0] ?? '-';
$build_label = $version_parts[1] ?? '-';
$server_mode = isMaintenanceEnabled() ? 'Maintenance' : 'Online';
$php_label = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.x';


$backup_status_label = 'Belum ada';
$backup_status_detail = '-';
$backup_status_badge = 'UNKNOWN';
$backup_status_class = 'text-secondary';
$backup_dir = __DIR__ . '/../db_data/backups';
$db_file = get_stats_db_path();
// App config backup status
$app_backup_label = 'Belum ada';
$app_backup_detail = '-';
$app_backup_badge = 'UNKNOWN';
$app_backup_class = 'text-secondary';
$app_backup_dir = __DIR__ . '/../db_data/backups_app';
$app_db_file = function_exists('app_db_path') ? app_db_path() : (__DIR__ . '/../db_data/mikhmon_app.db');
if (is_dir($backup_dir)) {
  $today = date('Ymd');
  $files = glob($backup_dir . '/mikhmon_stats_*.db') ?: [];
  $latest = '';
  if (!empty($files)) {
    usort($files, function ($a, $b) { return filemtime($b) <=> filemtime($a); });
    $latest = $files[0];
    $backup_status_detail = basename($latest);
    $backup_status_badge = 'ADA';
  }
  $today_files = [];
  foreach ($files as $f) {
    if (preg_match('/mikhmon_stats_' . $today . '_\d{6}\.db$/', basename($f))) {
      $today_files[] = $f;
    }
  }
  if (!empty($today_files)) {
    $src_size = file_exists($db_file) ? @filesize($db_file) : 0;
    $valid_today = false;
    foreach ($today_files as $tf) {
      $size = @filesize($tf) ?: 0;
      if ($size <= 0) continue;
      if ($src_size > 0 && $size < ($src_size * 0.8)) continue;
      if (class_exists('SQLite3')) {
        try {
          $chk = new SQLite3($tf, SQLITE3_OPEN_READONLY);
          $res = $chk->querySingle('PRAGMA quick_check;');
          $chk->close();
          if (strtolower((string)$res) === 'ok') {
            $valid_today = true;
            break;
          }
        } catch (Exception $e) {
          continue;
        }
      } else {
        $valid_today = true;
        break;
      }
    }
    if ($valid_today) {
      $backup_status_label = 'Backup';
      $backup_status_badge = 'OK';
      $backup_status_class = 'text-green';
    } else {
      $backup_status_label = 'Backup Ada (Perlu cek)';
      $backup_status_badge = 'CEK';
      $backup_status_class = 'text-secondary';
    }
  }
}

if (is_dir($app_backup_dir)) {
  $today = date('Ymd');
  $files = glob($app_backup_dir . '/mikhmon_app_*.db') ?: [];
  $latest = '';
  if (!empty($files)) {
    usort($files, function ($a, $b) { return filemtime($b) <=> filemtime($a); });
    $latest = $files[0];
    $app_backup_detail = basename($latest);
    $app_backup_badge = 'ADA';
  }
  $today_files = [];
  foreach ($files as $f) {
    if (preg_match('/mikhmon_app_' . $today . '_\d{6}\.db$/', basename($f))) {
      $today_files[] = $f;
    }
  }
  if (!empty($today_files)) {
    $src_size = file_exists($app_db_file) ? @filesize($app_db_file) : 0;
    $valid_today = false;
    foreach ($today_files as $tf) {
      $size = @filesize($tf) ?: 0;
      if ($size <= 0) continue;
      if ($src_size > 0 && $size < ($src_size * 0.5)) continue;
      if (class_exists('SQLite3')) {
        try {
          $chk = new SQLite3($tf, SQLITE3_OPEN_READONLY);
          $res = $chk->querySingle('PRAGMA quick_check;');
          $chk->close();
          if (strtolower((string)$res) === 'ok') {
            $valid_today = true;
            break;
          }
        } catch (Exception $e) {
          continue;
        }
      } else {
        $valid_today = true;
        break;
      }
    }
    if ($valid_today) {
      $app_backup_label = 'Backup';
      $app_backup_badge = 'OK';
      $app_backup_class = 'text-green';
    } else {
      $app_backup_label = 'Backup Ada (Perlu cek)';
      $app_backup_badge = 'CEK';
      $app_backup_class = 'text-secondary';
    }
  }
}

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
      <a class="btn-action btn-primary-m" data-no-ajax="1" href="./admin.php?id=settings&session=new-<?= rand(1111,9999); ?>">
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
        <span class="pointer" title="Muat ulang daftar" onclick="window.location.reload();" style="margin-left:auto; color:var(--text-secondary);">
          <i class="fa fa-refresh"></i>
        </span>
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
                  <a class="delete-btn" href="./admin.php?id=remove-session&session=<?= htmlspecialchars($router_item['session']); ?>" title="Hapus" data-delete-session="<?= htmlspecialchars($router_item['session']); ?>"><i class="fa fa-trash"></i></a>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php if (isSuperAdmin()): ?>
      <div class="card-modern" style="margin-top: 20px;">
        <div class="card-header-modern">
          <h3><i class="fa fa-database text-blue"></i> Backup & Restore</h3>
        </div>
        <div class="card-body-modern">
          <div class="text-secondary" style="font-size:12px; margin-bottom:8px;">Status Terakhir</div>
          <div style="font-size:16px; font-weight:700;" class="<?= $backup_status_class; ?>"><?= htmlspecialchars($backup_status_label); ?> <span class="badge" style="margin-left:6px;"><?= htmlspecialchars($backup_status_badge); ?></span></div>
          <div style="font-size:11px; color: var(--text-muted); margin:6px 0 14px;">File: <?= htmlspecialchars($backup_status_detail); ?></div>
          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button id="db-backup" class="btn-action btn-outline" onclick="runBackupAjax()">
              <i class="fa fa-database"></i> Backup Sekarang
            </button>
            <button id="db-restore" class="btn-action btn-outline" onclick="runRestoreAjax()">
              <i class="fa fa-history"></i> Restore
            </button>
          </div>
          <div style="height:12px;"></div>
          <div class="text-secondary" style="font-size:12px; margin-bottom:8px;">Konfigurasi (App DB)</div>
          <div style="font-size:16px; font-weight:700;" class="<?= $app_backup_class; ?>"><?= htmlspecialchars($app_backup_label); ?> <span class="badge" style="margin-left:6px;"><?= htmlspecialchars($app_backup_badge); ?></span></div>
          <div style="font-size:11px; color: var(--text-muted); margin:6px 0 14px;">File: <?= htmlspecialchars($app_backup_detail); ?></div>
          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button id="db-app-backup" class="btn-action btn-outline" onclick="runAppBackupAjax()">
              <i class="fa fa-database"></i> Backup Konfigurasi
            </button>
            <button id="db-app-restore" class="btn-action btn-outline" onclick="runAppRestoreAjax()">
              <i class="fa fa-history"></i> Restore Konfigurasi
            </button>
          </div>
        </div>
      </div>
    <?php endif; ?>
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
                <div class="text-secondary" style="font-size:12px;">Backup Status</div>
                <div style="font-size:18px; font-weight:700;" class="<?= $backup_status_class; ?>"><?= htmlspecialchars($backup_status_label); ?> <span class="badge"><?= htmlspecialchars($backup_status_badge); ?></span></div>
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
    <?php if (isSuperAdmin()): ?>
      <?php
        $log_file = __DIR__ . '/../logs/admin_errors.log';
        $log_lines = [];
        if (is_file($log_file) && is_readable($log_file)) {
          $fp = fopen($log_file, 'r');
          if ($fp) {
            $buffer = '';
            $chunk_size = 8192;
            fseek($fp, 0, SEEK_END);
            $pos = ftell($fp);
            while ($pos > 0 && substr_count($buffer, "\n") < 30) {
              $seek = max($pos - $chunk_size, 0);
              $read = $pos - $seek;
              fseek($fp, $seek);
              $buffer = fread($fp, $read) . $buffer;
              $pos = $seek;
            }
            fclose($fp);
            $log_lines = array_filter(explode("\n", trim($buffer)));
            $log_lines = array_slice($log_lines, -10);
          }
        }
      ?>
      <div class="card-modern" style="margin-top: 20px;">
        <div class="card-header-modern">
          <h3><i class="fa fa-exclamation-triangle text-secondary"></i> Log Error Admin (Terakhir)</h3>
          <span class="badge"><?= count($log_lines); ?> baris</span>
        </div>
        <div class="card-body-modern">
          <?php if (empty($log_lines)): ?>
            <div class="admin-empty">Belum ada error.</div>
          <?php else: ?>
            <pre class="script-area" style="height: 220px; margin: 0;"><?php foreach ($log_lines as $line) { echo htmlspecialchars($line) . "\n"; } ?></pre>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>









