<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/acl.php';
requireLogin('../admin.php?id=login');
require_once __DIR__ . '/../include/db.php';
app_db_import_legacy_if_needed();

$vip_popup_html = '';
$vip_popup_autoshow = false;
if (isSuperAdmin()) {
    $vip_whitelist_no_render = true;
    $vip_whitelist_action = './admin.php?id=' . htmlspecialchars($id ?: 'sessions');
    ob_start();
    include __DIR__ . '/../tools/htaccess_vip.php';
    if (function_exists('vip_whitelist_render_form')) {
        vip_whitelist_render_form($status ?? '', $error ?? '', $ips ?? [], $ip_names ?? [], $htaccessPath ?? '', $vip_whitelist_action);
        $vip_popup_autoshow = (!empty($status) || !empty($error) || (!empty($_POST['vip_whitelist'])));
    }
    $vip_popup_html = ob_get_clean();
}

$active_tab = 'sessions';
if ($id === 'settings') {
    $active_tab = 'settings';
} elseif ($id === 'mikrotik-scripts') {
    $active_tab = 'scripts';
} elseif ($id === 'operator-access') {
    $active_tab = 'operator';
} elseif ($id === 'whatsapp') {
    $active_tab = 'whatsapp';
} elseif ($id === 'log-audit') {
    $active_tab = 'log-audit';
}

$active_session = $session ?? '';
if ($active_session === '') {
    $default_session = app_db_first_session_id();
    if ($default_session !== '') {
        $active_session = $default_session;
        $session = $default_session;
    }
}
$is_new_session = ($active_session !== '' && strpos($active_session, 'new-') === 0);
$session_label = $active_session !== '' ? htmlspecialchars($active_session) : '-';
?>

<div class="admin-shell" data-admin-shell data-active-tab="<?= htmlspecialchars($active_tab); ?>" data-session="<?= htmlspecialchars($active_session); ?>">
    <nav class="top-navbar">
        <div style="display: flex; align-items: center;">
            <div class="brand-logo">
                <img src="img/logo.png" onerror="this.src='https://via.placeholder.com/32x32/3b82f6/ffffff?text=W'" alt="Logo">
            </div>

            <div class="nav-tabs-custom">
                <a class="nav-btn <?= $active_tab === 'sessions' ? 'active' : ''; ?>" data-tab="sessions" href="./admin.php?id=sessions">
                    <i class="fa fa-server"></i> Sesi & Admin
                </a>
                <a class="nav-btn <?= $active_tab === 'settings' ? 'active' : ''; ?>" data-tab="settings" href="./admin.php?id=settings<?= $active_session !== '' ? '&session=' . htmlspecialchars($active_session) : ''; ?>">
                    <i class="fa fa-cogs"></i> Pengaturan Router
                </a>
                <a class="nav-btn <?= $active_tab === 'operator' ? 'active' : ''; ?>" data-tab="operator" href="./admin.php?id=operator-access">
                    <i class="fa fa-users"></i> Akses Operator
                </a>
                <a class="nav-btn <?= $active_tab === 'log-audit' ? 'active' : ''; ?>" data-tab="log-audit" href="./admin.php?id=log-audit">
                    <i class="fa fa-clipboard"></i> Log Audit
                </a>
                <a class="nav-btn <?= $active_tab === 'whatsapp' ? 'active' : ''; ?>" data-tab="whatsapp" href="./admin.php?id=whatsapp">
                    <i class="fa fa-whatsapp"></i> WhatsApp
                </a>
                <a class="nav-btn <?= $active_tab === 'scripts' ? 'active' : ''; ?>" data-tab="scripts" href="./admin.php?id=mikrotik-scripts<?= $active_session !== '' ? '&session=' . htmlspecialchars($active_session) : ''; ?>">
                    <i class="fa fa-code"></i> Script MikroTik
                </a>
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
                    <button type="button" class="btn-action btn-outline" style="font-size: 11px; padding: 6px 10px;" onclick="openVipWhitelistPopup()">
                        <i class="fa fa-shield"></i> Whitelist IP
                    </button>
                <?php endif; ?>
            </div>

            <a href="./admin.php?id=logout" title="Keluar" style="color: var(--danger); font-size: 16px; margin-left: 8px;">
                <i class="fa fa-power-off"></i>
            </a>
        </div>
    </nav>

    <div class="container">
        <div class="view-section <?= $active_tab === 'sessions' ? 'active' : ''; ?>" data-view="sessions" style="display: <?= $active_tab === 'sessions' ? 'block' : 'none'; ?>;">
            <?php include __DIR__ . '/sessions.php'; ?>
        </div>

        <div class="view-section <?= $active_tab === 'settings' ? 'active' : ''; ?>" data-view="settings" style="display: <?= $active_tab === 'settings' ? 'block' : 'none'; ?>;" <?= ($active_tab === 'settings' && $is_new_session) ? 'data-loaded="1" data-loaded-session="' . htmlspecialchars($active_session) . '"' : ''; ?>>
            <?php if ($active_tab === 'settings' && $active_session !== ''): ?>
                <?php include __DIR__ . '/settings.php'; ?>
            <?php elseif ($active_tab === 'settings'): ?>
                <div class="admin-empty">Pilih sesi terlebih dahulu untuk membuka pengaturan router.</div>
            <?php else: ?>
                <div class="admin-async" data-section="settings"></div>
            <?php endif; ?>
        </div>

        <div class="view-section <?= $active_tab === 'scripts' ? 'active' : ''; ?>" data-view="scripts" style="display: <?= $active_tab === 'scripts' ? 'block' : 'none'; ?>;">
            <?php if ($active_tab === 'scripts' && $active_session !== ''): ?>
                <?php if (file_exists(__DIR__ . '/mikrotik_scripts.php')): ?>
                    <?php include __DIR__ . '/mikrotik_scripts.php'; ?>
                <?php else: ?>
                    <div class="admin-empty">File script MikroTik tidak ditemukan.</div>
                <?php endif; ?>
            <?php elseif ($active_tab === 'scripts'): ?>
                <div class="admin-empty">Pilih sesi terlebih dahulu untuk membuka script MikroTik.</div>
            <?php else: ?>
                <div class="admin-async" data-section="scripts"></div>
            <?php endif; ?>
        </div>

        <div class="view-section <?= $active_tab === 'operator' ? 'active' : ''; ?>" data-view="operator" style="display: <?= $active_tab === 'operator' ? 'block' : 'none'; ?>;">
            <?php if ($active_tab === 'operator'): ?>
                <?php include __DIR__ . '/operator_access.php'; ?>
            <?php else: ?>
                <div class="admin-async" data-section="operator"></div>
            <?php endif; ?>
        </div>

        <div class="view-section <?= $active_tab === 'log-audit' ? 'active' : ''; ?>" data-view="log-audit" style="display: <?= $active_tab === 'log-audit' ? 'block' : 'none'; ?>;">
            <?php if ($active_tab === 'log-audit'): ?>
                <?php include __DIR__ . '/log_audit.php'; ?>
            <?php else: ?>
                <div class="admin-async" data-section="log-audit"></div>
            <?php endif; ?>
        </div>

        <div class="view-section <?= $active_tab === 'whatsapp' ? 'active' : ''; ?>" data-view="whatsapp" style="display: <?= $active_tab === 'whatsapp' ? 'block' : 'none'; ?>;">
            <?php if ($active_tab === 'whatsapp'): ?>
                <?php include __DIR__ . '/whatsapp_config.php'; ?>
            <?php else: ?>
                <div class="admin-async" data-section="whatsapp"></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (isSuperAdmin()): ?>
<script>
function openVipWhitelistPopup(){
    if (!window.MikhmonPopup) return;
    var html = <?= json_encode($vip_popup_html, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    window.MikhmonPopup.open({
        title: 'Whitelist IP',
        iconClass: 'fa fa-shield',
        statusIcon: 'fa fa-shield',
        statusColor: '#3b82f6',
        cardClass: 'is-large',
        messageHtml: html,
        buttons: [
            { label: 'Tutup', className: 'm-btn m-btn-cancel' }
        ]
    });
}
<?php if ($vip_popup_autoshow): ?>
setTimeout(openVipWhitelistPopup, 150);
<?php endif; ?>
</script>
<?php endif; ?>
