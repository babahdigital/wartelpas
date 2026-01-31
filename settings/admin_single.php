<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
requireLogin('../admin.php?id=login');

$active_tab = 'sessions';
if ($id === 'settings') {
    $active_tab = 'settings';
} elseif ($id === 'mikrotik-scripts') {
    $active_tab = 'scripts';
} elseif ($id === 'operator-access') {
    $active_tab = 'operator';
}

$active_session = $session ?? '';
$session_label = $active_session !== '' ? htmlspecialchars($active_session) : '-';
?>

<div class="admin-shell" data-admin-shell data-active-tab="<?= htmlspecialchars($active_tab); ?>" data-session="<?= htmlspecialchars($active_session); ?>">
    <nav class="top-navbar">
        <div style="display: flex; align-items: center;">
            <div class="brand-logo">
                <img src="img/logo.png" onerror="this.src='https://via.placeholder.com/32x32/3b82f6/ffffff?text=W'" alt="Logo">
            </div>

            <div class="nav-tabs-custom">
                <button class="nav-btn <?= $active_tab === 'sessions' ? 'active' : ''; ?>" data-tab="sessions">
                    <i class="fa fa-server"></i> Sesi & Admin
                </button>
                <button class="nav-btn <?= $active_tab === 'settings' ? 'active' : ''; ?>" data-tab="settings">
                    <i class="fa fa-cogs"></i> Pengaturan Router
                </button>
                <button class="nav-btn <?= $active_tab === 'scripts' ? 'active' : ''; ?>" data-tab="scripts">
                    <i class="fa fa-code"></i> Script MikroTik
                </button>
                <button class="nav-btn <?= $active_tab === 'operator' ? 'active' : ''; ?>" data-tab="operator">
                    <i class="fa fa-users"></i> Akses Operator
                </button>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 12px;">
            <span class="badge" data-session-badge>Sesi: <?= $session_label; ?></span>
            <span class="badge" title="Waktu Saat Ini"><i class="fa fa-clock-o"></i> <span id="timer_val">--:--</span></span>

            <div style="display:flex; gap:8px;">
                <a class="btn-action btn-outline" style="font-size: 11px; padding: 6px 10px;" data-no-ajax="1" href="<?= $active_session !== '' ? './?session=' . htmlspecialchars($active_session) : './'; ?>">
                    <i class="fa fa-home"></i> Halaman Utama
                </a>
                <?php if (isSuperAdmin()): ?>
                    <button class="btn-action btn-outline" style="font-size: 11px; padding: 6px 10px;" onclick="runBackupAjax()">
                        <i class="fa fa-database"></i> Backup
                    </button>
                    <button class="btn-action btn-outline" style="font-size: 11px; padding: 6px 10px;" onclick="runRestoreAjax()">
                        <i class="fa fa-history"></i> Restore
                    </button>
                    <a class="btn-action btn-primary-m" style="font-size: 11px; padding: 6px 10px;" data-no-ajax="1" href="./admin.php?id=settings&router=new-<?= rand(1111,9999); ?>">
                        <i class="fa fa-plus"></i> Tambah Router
                    </a>
                <?php endif; ?>
            </div>

            <a href="./admin.php?id=logout" title="Keluar" style="color: var(--danger); font-size: 16px; margin-left: 8px;">
                <i class="fa fa-power-off"></i>
            </a>
        </div>
    </nav>

    <div class="container">
        <div class="view-section <?= $active_tab === 'sessions' ? 'active' : ''; ?>" data-view="sessions">
            <?php include __DIR__ . '/sessions.php'; ?>
        </div>

        <div class="view-section <?= $active_tab === 'settings' ? 'active' : ''; ?>" data-view="settings">
            <?php if ($active_tab === 'settings' && $active_session !== ''): ?>
                <div class="admin-async" data-section="settings"></div>
            <?php elseif ($active_tab === 'settings'): ?>
                <div class="admin-empty">Pilih sesi terlebih dahulu untuk membuka pengaturan router.</div>
            <?php else: ?>
                <div class="admin-async" data-section="settings"></div>
            <?php endif; ?>
        </div>

        <div class="view-section <?= $active_tab === 'scripts' ? 'active' : ''; ?>" data-view="scripts">
            <?php if ($active_tab === 'scripts' && $active_session !== ''): ?>
                <div class="admin-async" data-section="scripts"></div>
            <?php elseif ($active_tab === 'scripts'): ?>
                <div class="admin-empty">Pilih sesi terlebih dahulu untuk membuka script MikroTik.</div>
            <?php else: ?>
                <div class="admin-async" data-section="scripts"></div>
            <?php endif; ?>
        </div>

        <div class="view-section <?= $active_tab === 'operator' ? 'active' : ''; ?>" data-view="operator">
            <?php include __DIR__ . '/operator_access.php'; ?>
        </div>
    </div>
</div>
