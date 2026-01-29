(function(){
  const usersSession = (window.usersSession || '').toString();
  const form = document.getElementById('users-toolbar-form');
  const searchInput = document.querySelector('input[name="q"]');
  const statusSelect = document.querySelector('select[name="status"]');
  const commentSelect = document.querySelector('select[name="comment"]');
  const showSelect = document.querySelector('select[name="show"]');
  const dateInput = document.querySelector('input[name="date"]');
  const tbody = document.getElementById('users-table-body');
  const totalBadge = document.getElementById('users-total');
  const activeBadge = document.getElementById('users-active');
  const paginationWrap = document.getElementById('users-pagination');
  const searchLoading = document.getElementById('search-loading');
  const clearBtn = document.getElementById('search-clear');
  const pageDim = document.getElementById('page-dim');
  const actionBanner = document.getElementById('action-banner');
  const overlayBackdrop = document.getElementById('users-overlay');
  const overlayContainer = document.getElementById('users-overlay-container');
  const overlayTitle = document.getElementById('users-overlay-title');
  const overlayText = document.getElementById('users-overlay-text');
  const overlayIcon = document.getElementById('users-overlay-icon');
  const overlayActions = document.getElementById('users-overlay-actions');
  let overlayFadeTimer = null;
  const reloginModal = document.getElementById('relogin-modal');
  const reloginBody = document.getElementById('relogin-body');
  const reloginTitle = document.getElementById('relogin-title');
  const reloginSub = document.getElementById('relogin-sub');
  const reloginClose = document.getElementById('relogin-close');
  const reloginPrint = document.getElementById('relogin-print');
  const summaryModal = document.getElementById('summary-modal');
  const summaryOpen = document.getElementById('summary-open');
  const summaryClose = document.getElementById('summary-close');
  if (!searchInput || !tbody || !totalBadge || !paginationWrap) return;

  if (summaryOpen && summaryModal) {
    summaryOpen.addEventListener('click', () => {
      summaryModal.style.display = 'flex';
    });
  }
  if (summaryClose && summaryModal) {
    summaryClose.addEventListener('click', () => {
      summaryModal.style.display = 'none';
    });
  }
  if (summaryModal) {
    summaryModal.addEventListener('click', (e) => {
      if (e.target === summaryModal) summaryModal.style.display = 'none';
    });
  }
  function renderLowStockAlert() {
    const info = window.lowStockInfo || null;
    if (!info || !info.show) return;
    if (document.getElementById('low-stock-alert')) return;
    const alert = document.createElement('div');
    alert.id = 'low-stock-alert';
    alert.className = 'low-stock-alert';
    const label = info.label && info.label !== 'total' ? ' ' + info.label : '';
    alert.innerHTML = `
      <div class="low-stock-text">
        <i class="fa fa-exclamation-triangle"></i>
        Stok voucher ready${label} tinggal <strong>${info.total}</strong>. Segera tambah stok.
      </div>
      <button type="button" class="low-stock-close" aria-label="Tutup">&times;</button>
    `;
    document.body.appendChild(alert);
    const closeBtn = alert.querySelector('.low-stock-close');
    if (closeBtn) {
      closeBtn.addEventListener('click', () => {
        alert.style.display = 'none';
      });
    }
  }
  renderLowStockAlert();

  function toggleClearBtn() {
    if (!clearBtn) return;
    const hasValue = searchInput.value.trim() !== '';
    clearBtn.classList.toggle('is-visible', hasValue);
  }

  toggleClearBtn();
  window.addEventListener('focus', toggleClearBtn);
  document.addEventListener('visibilitychange', toggleClearBtn);

  const ajaxBase = './hotspot/users.php';
  const baseParams = new URLSearchParams(window.location.search);

  let suspendAutoRefresh = false;

  let lastFetchId = 0;
  let appliedQuery = (baseParams.get('q') || '').trim();

  window.showActionPopup = function(type, message) {
    if (!actionBanner) return;
    actionBanner.classList.remove('success', 'error');
    actionBanner.classList.add(type === 'error' ? 'error' : 'success');
    actionBanner.innerHTML = `<i class="fa ${type === 'error' ? 'fa-times-circle' : 'fa-check-circle'}"></i><span>${message}</span>`;
    actionBanner.style.display = 'flex';
    const msgLen = (message || '').length;
    const delay = Math.min(8000, Math.max(4500, msgLen * 55));
    setTimeout(() => { actionBanner.style.display = 'none'; }, delay);
  };

  function showOverlayChoice(options) {
    return new Promise((resolve) => {
      if (!overlayBackdrop || !overlayContainer || !overlayTitle || !overlayText || !overlayIcon || !overlayActions) {
        resolve(null);
        return;
      }
      if (overlayFadeTimer) {
        clearTimeout(overlayFadeTimer);
        overlayFadeTimer = null;
      }
      const opts = options || {};
      overlayTitle.textContent = opts.title || 'Konfirmasi';
      overlayText.innerHTML = opts.messageHtml || '';
      overlayContainer.classList.remove('status-loading', 'status-success', 'status-error', 'status-warning');
      if (opts.type === 'danger') {
        overlayContainer.classList.add('status-error');
        overlayIcon.className = 'fa fa-exclamation-triangle';
        overlayIcon.style.removeProperty('color');
      } else if (opts.type === 'warning') {
        overlayContainer.classList.remove('status-error');
        overlayIcon.className = 'fa fa-exclamation-circle';
        overlayIcon.style.color = '#f59e0b';
      } else if (opts.type === 'info') {
        overlayContainer.classList.remove('status-error');
        overlayIcon.className = 'fa fa-info-circle';
        overlayIcon.style.color = '#3b82f6';
      } else {
        overlayContainer.classList.add('status-loading');
        overlayIcon.className = 'fa fa-question-circle';
        overlayIcon.style.removeProperty('color');
      }
      if (opts.layout === 'vertical') {
        overlayActions.classList.add('layout-vertical');
      } else {
        overlayActions.classList.remove('layout-vertical');
      }
      overlayActions.innerHTML = '';
      const buttons = Array.isArray(opts.buttons) ? opts.buttons : [];
      const cleanup = (val) => {
        overlayBackdrop.classList.remove('show');
        overlayFadeTimer = setTimeout(() => {
          overlayBackdrop.style.display = 'none';
          overlayActions.classList.remove('layout-vertical');
          overlayFadeTimer = null;
        }, 250);
        overlayActions.innerHTML = '';
        resolve(val);
      };
      buttons.forEach((btn) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'overlay-btn' + (btn.className ? (' ' + btn.className) : '');
        b.innerHTML = btn.label || 'OK';
        if (btn.disabled) {
          b.disabled = true;
          b.style.opacity = '0.6';
          b.style.cursor = 'not-allowed';
        }
        b.onclick = () => {
          if (btn.onClick) {
            btn.onClick();
          }
          if (btn.closeOnClick === false) return;
          cleanup(btn.value ?? null);
        };
        overlayActions.appendChild(b);
      });
      overlayBackdrop.style.display = 'flex';
      void overlayBackdrop.offsetWidth;
      overlayBackdrop.classList.add('show');
      overlayBackdrop.onclick = (e) => {
        if (e.target === overlayBackdrop && !opts.lockClose) {
          cleanup('cancel');
        }
      };
    });
  }

  function showConfirm(message, type) {
    const msg = message || 'Lanjutkan aksi ini?';
    return showOverlayChoice({
      title: 'Konfirmasi',
      messageHtml: `<div style="text-align:left;">${msg}</div>`,
      type: type === 'danger' ? 'danger' : 'info',
      buttons: [
        { label: 'Batal', value: false, className: 'overlay-btn-muted' },
        { label: 'Ya, Lanjutkan', value: true }
      ]
    }).then((val) => val === true);
  }


  let rusakPrintPayload = null;

  function showRusakChecklist(data) {
    return new Promise((resolve) => {
      if (!overlayBackdrop || !overlayText) {
        resolve(false);
        return;
      }
      const criteria = data.criteria || {};
      const values = data.values || {};
      const limits = data.limits || {};
      const headerMsg = data.message || '';
      const meta = data.meta || {};
      const isValid = !!data.ok;

      const statusIcon = isValid ? 'fa-check-circle' : 'fa-times-circle';
      const statusClass = isValid ? 'success' : 'error';
      const statusText = isValid ? 'Syarat terpenuhi. User Layak diganti.' : 'Syarat TIDAK terpenuhi.';
      const bannerHtml = `
        <div class="status-banner ${statusClass}">
          <i class="fa ${statusIcon}" style="font-size:18px;"></i>
          <span>${statusText}</span>
        </div>`;

      const items = [
        { label: `Offline (Tidak aktif)`, ok: !!criteria.offline, value: values.online === 'Tidak' ? 'Offline' : 'Online' },
        { label: `Usage < ${limits.bytes || '-'}`, ok: !!criteria.bytes_ok, value: values.bytes || '-' },
        { label: `Uptime (Info)`, ok: true, value: values.total_uptime || '-' },
        { label: `History Login`, ok: !!criteria.first_login_ok, value: values.first_login !== '-' ? 'Ada' : 'Kosong' }
      ];

      const rows = items.map(it => {
        const icon = it.ok ? 'fa-check' : 'fa-times';
        const color = it.ok ? '#4ade80' : '#f87171';
        return `
          <tr>
            <td><i class="fa ${icon}" style="color:${color}; width:16px;"></i> ${it.label}</td>
            <td style="text-align:right; font-family:monospace;">${it.value}</td>
          </tr>`;
      }).join('');

      const tableHtml = `
        <div class="checklist-container">
          <table class="checklist-table">
            <thead><tr><th>Kriteria</th><th style="text-align:right;">Nilai Aktual</th></tr></thead>
            <tbody>${rows}</tbody>
          </table>
        </div>`;

      const targetUser = (meta && meta.username) ? meta.username : 'Unknown';
      const messageHtml = `
        <div style="text-align:left;">
          <div style="font-size:14px; color:#cbd5e1; margin-bottom:4px;">Audit User:</div>
          <div style="font-size:18px; font-weight:bold; color:#fff; margin-bottom:12px;">${targetUser}</div>
          ${headerMsg ? `<div style="margin-bottom:8px;color:#f3c969;">${headerMsg}</div>` : ''}
          ${bannerHtml}
          ${tableHtml}
        </div>`;

      const sess = (usersSession || '').toString();
      const printUrl = targetUser ? ('./hotspot/print/print.detail.php?session=' + encodeURIComponent(sess) + '&user=' + encodeURIComponent(targetUser)) : '';

      showOverlayChoice({
        title: 'Verifikasi Kondisi Rusak',
        messageHtml,
        type: isValid ? 'warning' : 'danger',
        layout: 'vertical',
        buttons: [
          {
            label: `
              <i class="fa fa-gavel"></i>
              <div class="btn-rich-text">
                <span class="btn-rich-title">Tetapkan Status RUSAK</span>
                <span class="btn-rich-desc">User akan diblokir & laporan disesuaikan.</span>
              </div>`,
            value: true,
            className: 'overlay-btn-warning',
            disabled: !isValid
          },
          {
            label: `
              <i class="fa fa-print"></i>
              <div class="btn-rich-text">
                <span class="btn-rich-title">Print Rincian</span>
                <span class="btn-rich-desc">Cetak bukti diagnosa sebelum eksekusi.</span>
              </div>`,
            className: 'overlay-btn-info',
            closeOnClick: false,
            onClick: () => {
              if (!printUrl) return;
              const w = window.open(printUrl, '_blank');
              if (!w) window.location.href = printUrl;
            }
          },
          {
            label: `
              <i class="fa fa-times"></i>
              <div class="btn-rich-text"><span class="btn-rich-title">Batal</span></div>`,
            value: false,
            className: 'overlay-btn-muted'
          }
        ]
      }).then((val) => resolve(val === true));
    });
  }

  window.openDeleteBlockPopup = async function(blok) {
    const blokLabel = (blok || '').toString().trim();
    if (!blokLabel) return;
    suspendAutoRefresh = true;
    const isAdmin = !!window.isSuperAdmin;
    const firstMessage = `
      <div style="text-align:left;">
        <div style="font-weight:600; font-size:15px; margin-bottom:8px; color:#fff;">
          Target Penghapusan: <span style="color:#f39c12; font-size:16px;">${blokLabel}</span>
        </div>
        <div style="font-size:13px; color:#b8c7ce; margin-bottom:15px;">
          Silakan pilih metode penghapusan di bawah ini:
        </div>
        ${isAdmin ? '' : '<div class="popup-note"><i class="fa fa-lock"></i> Hapus Total dikunci (Khusus Superadmin).</div>'}
      </div>`;
    let choice = null;
    try {
      choice = await showOverlayChoice({
        title: 'Hapus Blok Voucher',
        messageHtml: firstMessage,
        type: 'warning',
        layout: 'vertical',
        buttons: [
          { 
            label: `
              <i class="fa fa-server"></i>
              <div class="btn-rich-text">
                <span class="btn-rich-title">Hapus Router Saja (Aman)</span>
                <span class="btn-rich-desc">User offline, Laporan/Uang TETAP ADA.</span>
              </div>`,
            value: 'router', 
            className: 'overlay-btn-warning' 
          },
          { 
            label: `
              <i class="fa fa-trash"></i>
              <div class="btn-rich-text">
                <span class="btn-rich-title">Hapus Total (Router + DB)</span>
                <span class="btn-rich-desc">Hapus user & HAPUS SEMUA JEJAK UANG.</span>
              </div>`,
            value: 'full', 
            className: 'overlay-btn-danger', 
            disabled: !isAdmin 
          },
          { 
            label: `
              <i class="fa fa-times"></i>
              <div class="btn-rich-text">
                <span class="btn-rich-title">Batal</span>
              </div>`,
            value: 'cancel', 
            className: 'overlay-btn-muted' 
          }
        ]
      });
    } finally {
      if (!choice || choice === 'cancel') suspendAutoRefresh = false;
    }
    if (!choice || choice === 'cancel') return;
    if (choice === 'router') {
      const detail = `
        <div style="text-align:center;">
          <div style="font-size:16px; margin-bottom:10px;">Konfirmasi Eksekusi</div>
          <div style="color:#cbd5e1; margin-bottom:15px; font-size:13px;">
            Anda yakin menghapus user <strong>Router</strong> untuk blok <strong>${blokLabel}</strong>?<br>
            <span style="color:#34d399; font-size:12px;">(Laporan Keuangan Aman)</span>
          </div>
        </div>`;
      const ok = await showOverlayChoice({
        title: 'Eksekusi Hapus Router',
        messageHtml: detail,
        type: 'warning',
        buttons: [
          { label: 'Batal', value: false, className: 'overlay-btn-muted' },
          { label: 'Ya, Eksekusi', value: true, className: 'overlay-btn-warning' }
        ]
      });
      if (ok !== true) {
        suspendAutoRefresh = false;
        return;
      }
      const url = './?hotspot=users&action=batch_delete&blok=' + encodeURIComponent(blokLabel) + '&session=' + encodeURIComponent(usersSession);
      actionRequest(url, null);
      return;
    }
    if (choice === 'full') {
      const detail = `
        <div style="text-align:center;">
          <div style="font-size:18px; color:#ef4444; margin-bottom:10px; font-weight:bold;">PERINGATAN BAHAYA!</div>
          <div style="color:#e2e8f0; margin-bottom:15px; line-height:1.5;">
            Anda akan menghapus <strong>${blokLabel}</strong> secara PERMANEN.<br><br>
            <div style="background:rgba(220, 38, 38, 0.2); border:1px solid #dc2626; padding:10px; border-radius:6px; text-align:left; font-size:13px;">
              <i class="fa fa-exclamation-triangle" style="color:#fca5a5"></i> <strong>Efek Hapus Total:</strong><br>
              1. User hilang dari Router.<br>
              2. History Login hilang dari Database.<br>
              3. <strong>Data Penjualan/Uang HILANG.</strong><br>
              4. Settlement log ikut dihapus (sesuai tanggal filter jika ada).
            </div>
          </div>
          <div style="font-size:12px; color:#cbd5e1;">Tindakan ini tidak bisa dibatalkan.</div>
        </div>`;
      const ok = await showOverlayChoice({
        title: 'Konfirmasi Hapus Total',
        messageHtml: detail,
        type: 'danger',
        buttons: [
          { label: 'Batal', value: false, className: 'overlay-btn-muted' },
          { label: 'Ya, HAPUS PERMANEN', value: true, className: 'overlay-btn-danger' }
        ]
      });
      if (ok !== true) {
        suspendAutoRefresh = false;
        return;
      }
      const dateValue = (dateInput && dateInput.value ? dateInput.value.trim() : '') || (baseParams.get('date') || '').trim();
      let url = './?hotspot=users&action=delete_block_full&blok=' + encodeURIComponent(blokLabel) + '&session=' + encodeURIComponent(usersSession) + '&delete_settlement=1';
      if (dateValue) url += '&date=' + encodeURIComponent(dateValue);
      actionRequest(url, null);
    }
  };

  window.openUnifiedPrintPopup = async function(payload) {
    const listUrl = payload && payload.listUrl ? payload.listUrl : '';
    const codeUrl = payload && payload.codeUrl ? payload.codeUrl : '';
    const blokLabel = payload && payload.blok ? payload.blok : '-';
    if (!listUrl && !codeUrl) return;
    const hasData = window.hasUserData !== false;
    const isVip = (listUrl && /status=vip/i.test(listUrl)) || (codeUrl && /status=vip/i.test(codeUrl));
    const blokText = isVip ? 'Pengelola' : (blokLabel || '-');

    const detailMsg = `
      <div style="text-align:left;">
        <div style="font-weight:600; font-size:15px; margin-bottom:6px; color:#fff;">Pilih Jenis Print</div>
        <div style="font-size:13px; color:#cbd5e1; margin-bottom:12px;">Pilih salah satu opsi di bawah ini.</div>
        <div style="background:rgba(59, 130, 246, 0.12); border:1px solid rgba(59, 130, 246, 0.3); padding:10px; border-radius:6px; font-size:12px; color:#e2e8f0;">
          <strong>Blok:</strong> ${blokText}
        </div>
      </div>
    `;

    const ok = await showOverlayChoice({
      title: 'Print',
      messageHtml: detailMsg,
      type: 'info',
      layout: 'vertical',
      buttons: [
        {
          label: `
            <i class="fa fa-list"></i>
            <div class="btn-rich-text">
              <span class="btn-rich-title">Print List</span>
              <span class="btn-rich-desc">Cetak daftar sesuai filter saat ini.</span>
            </div>`,
          value: 'list',
          className: 'overlay-btn-info',
          disabled: !listUrl || !hasData || isVip
        },
        {
          label: `
            <i class="fa fa-ticket"></i>
            <div class="btn-rich-text">
              <span class="btn-rich-title">Print Kode</span>
              <span class="btn-rich-desc">Cetak voucher sesuai blok terpilih.</span>
            </div>`,
          value: 'code',
          className: 'overlay-btn-info',
          disabled: !codeUrl
        },
        {
          label: `
            <i class="fa fa-times"></i>
            <div class="btn-rich-text"><span class="btn-rich-title">Batal</span></div>`,
          value: 'cancel',
          className: 'overlay-btn-muted'
        }
      ]
    });

    if (ok === 'list') {
      const w = window.open(listUrl, '_blank');
      if (!w) window.location.href = listUrl;
    } else if (ok === 'code') {
      const w = window.open(codeUrl, '_blank');
      if (w) {
        try {
          w.onload = function() {
            setTimeout(() => { try { w.print(); } catch (e) {} }, 400);
          };
        } catch (e) {}
      } else {
        window.location.href = codeUrl;
      }
    }
  };

  function uptimeToSeconds(uptime) {
    if (!uptime) return 0;
    const re = /(\d+)(w|d|h|m|s)/gi;
    let total = 0;
    let m;
    while ((m = re.exec(uptime)) !== null) {
      const val = parseInt(m[1], 10) || 0;
      const unit = m[2].toLowerCase();
      if (unit === 'w') total += val * 7 * 24 * 3600;
      if (unit === 'd') total += val * 24 * 3600;
      if (unit === 'h') total += val * 3600;
      if (unit === 'm') total += val * 60;
      if (unit === 's') total += val;
    }
    return total;
  }

  function formatBytesSimple(bytes) {
    const b = Number(bytes) || 0;
    if (b <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let idx = 0;
    let val = b;
    while (val >= 1024 && idx < units.length - 1) {
      val /= 1024;
      idx++;
    }
    const dec = idx >= 2 ? 2 : 0;
    return val.toFixed(dec).replace('.', ',') + ' ' + units[idx];
  }

  function resolveRusakLimits(profile) {
    const p = (profile || '').toLowerCase();
    if (/(\b10\s*(menit|m)\b|10menit)/i.test(p)) {
      return { uptime: 180, bytes: 5 * 1024 * 1024, uptimeLabel: '3 menit', bytesLabel: '5MB' };
    }
    if (/(\b30\s*(menit|m)\b|30menit)/i.test(p)) {
      return { uptime: 300, bytes: 5 * 1024 * 1024, uptimeLabel: '5 menit', bytesLabel: '5MB' };
    }
    return { uptime: 300, bytes: 5 * 1024 * 1024, uptimeLabel: '5 menit', bytesLabel: '5MB' };
  }

  function buildRusakDataFromElement(el) {
    if (!el) return null;
    const bytes = Number(el.getAttribute('data-bytes') || 0);
    const uptime = el.getAttribute('data-uptime') || '0s';
    const status = el.getAttribute('data-status') || '';
    const profile = el.getAttribute('data-profile') || '';
    const username = el.getAttribute('data-user') || '';
    const blok = el.getAttribute('data-blok') || '';
    const firstLogin = el.getAttribute('data-first-login') || '';
    const loginTime = el.getAttribute('data-login') || '';
    const logoutTime = el.getAttribute('data-logout') || '';
    const limits = resolveRusakLimits(profile);
    const uptimeSec = uptimeToSeconds(uptime);
    const offline = status !== 'ONLINE';
    const dateBase = loginTime && loginTime !== '-' ? loginTime : (logoutTime && logoutTime !== '-' ? logoutTime : '');
    const headerDate = dateBase ? formatDateHeader(dateBase) : formatDateNow();
    const totalUptimeSec = uptimeSec;
    const criteria = {
      offline,
      bytes_ok: bytes <= limits.bytes,
      total_uptime_ok: true,
      first_login_ok: !!firstLogin
    };
    const ok = criteria.offline && criteria.bytes_ok && criteria.first_login_ok;
    return {
      ok,
      message: ok ? 'Syarat rusak terpenuhi.' : 'Syarat rusak belum terpenuhi.',
      meta: {
        username,
        blok: formatBlokLabel(blok),
        profile: formatProfileLabel(profile),
        date: headerDate,
        first_login: firstLogin || '-',
        login: loginTime || '-',
        logout: logoutTime || '-'
      },
      criteria,
      values: {
        online: offline ? 'Tidak' : 'Ya',
        bytes: formatBytesSimple(bytes),
        uptime,
        total_uptime: uptime,
        first_login: firstLogin || '-'
      },
      limits: {
        bytes: limits.bytesLabel,
        uptime: limits.uptimeLabel
      }
    };
  }

  window.actionRequest = async function(url, confirmMsg) {
    suspendAutoRefresh = true;
    if (confirmMsg) {
      const msgLower = confirmMsg.toLowerCase();
      if (msgLower.includes('hapus total user')) {
        const match = confirmMsg.match(/user\s+([^\s]+)/i);
        const userName = match ? match[1] : 'Target';
        const hasReturPair = confirmMsg.includes('[RETUR_PAIR]');
        const returNote = hasReturPair ? `
             <div class="popup-info-box purple" style="margin-top:12px;">
               <i class="fa fa-exchange" style="font-size:18px; color:#a569bd; margin-top:2px;"></i>
               <div>
                 <strong>Terindikasi Retur:</strong><br>
                 Sistem akan menghapus <strong>user lama</strong> dan <strong>user hasil retur</strong> sekaligus.
               </div>
             </div>
        ` : '';
        const detailMsg = `
          <div style="text-align:left;">
             <div style="margin-bottom:10px; color:#cbd5e1;">Anda akan menghapus user:</div>
             <div style="font-size:20px; font-weight:bold; color:#fff; margin-bottom:15px; border-left:4px solid #ef4444; padding-left:12px;">
               ${userName}
             </div>
             <div style="background:rgba(239, 68, 68, 0.1); border:1px solid rgba(239, 68, 68, 0.3); padding:10px; border-radius:6px; font-size:13px; color:#fca5a5;">
               <i class="fa fa-exclamation-triangle"></i> <strong>Peringatan:</strong><br>
               Tindakan ini akan menghapus user dari MikroTik DAN menghapus seluruh riwayat keuangan/login di database. Data tidak bisa dikembalikan.
             </div>
             ${returNote}
          </div>
        `;
        const ok = await showOverlayChoice({
          title: 'Hapus User Permanen',
          messageHtml: detailMsg,
          type: 'danger',
          layout: 'vertical',
          buttons: [
            {
              label: `
                <i class="fa fa-trash"></i>
                <div class="btn-rich-text">
                  <span class="btn-rich-title">Ya, Hapus Total</span>
                  <span class="btn-rich-desc">Hapus Router + Database Permanen.</span>
                </div>`,
              value: true,
              className: 'overlay-btn-danger'
            },
            {
              label: 'Batal',
              value: false,
              className: 'overlay-btn-muted'
            }
          ]
        });
        if (!ok) {
          suspendAutoRefresh = false;
          return;
        }
        confirmMsg = null;
      } else if (msgLower.includes('disable voucher')) {
        const match = confirmMsg.match(/Voucher\s+([^\?]+)/i);
        const voucherName = match ? match[1].trim() : 'Target';
        const detailMsg = `
          <div style="text-align:left;">
             <div style="margin-bottom:8px; color:#cbd5e1; font-size:13px;">Target Nonaktif:</div>
             <div style="font-size:20px; font-weight:bold; color:#fff; margin-bottom:15px; border-left:4px solid #f59e0b; padding-left:12px;">
               ${voucherName}
             </div>
             <div style="background:rgba(245, 158, 11, 0.1); border:1px solid rgba(245, 158, 11, 0.3); padding:12px; border-radius:6px; font-size:13px; color:#e2e8f0; line-height:1.5;">
               <div style="display:flex; gap:10px;">
                 <i class="fa fa-lock" style="font-size:16px; color:#fca5a5; margin-top:2px;"></i>
                 <div>
                   <strong>Efek Disable:</strong><br>
                   <span style="color:#cbd5e1;">User tidak akan bisa login hotspot.</span><br>
                   <span style="color:#34d399; font-weight:600;"><i class="fa fa-check"></i> Data & Uang TETAP AMAN.</span>
                 </div>
               </div>
             </div>
          </div>
        `;
        const ok = await showOverlayChoice({
          title: 'Konfirmasi Disable',
          messageHtml: detailMsg,
          type: 'warning',
          layout: 'vertical',
          buttons: [
            {
              label: `
                <i class="fa fa-ban"></i>
                <div class="btn-rich-text">
                  <span class="btn-rich-title">Ya, Disable Voucher</span>
                  <span class="btn-rich-desc">Matikan akses login user ini sekarang.</span>
                </div>`,
              value: true,
              className: 'overlay-btn-danger'
            },
            {
              label: `
                <i class="fa fa-times"></i>
                <div class="btn-rich-text"><span class="btn-rich-title">Batal</span></div>`,
              value: false,
              className: 'overlay-btn-muted'
            }
          ]
        });
        if (!ok) {
          suspendAutoRefresh = false;
          return;
        }
        confirmMsg = null;
      } else if (msgLower.includes('retur voucher')) {
        const match = confirmMsg.match(/RETUR Voucher\s+([^\?]+)/i);
        const voucherName = match ? match[1].trim() : 'Target';
        const detailMsg = `
          <div style="text-align:left;">
             <div style="margin-bottom:8px; color:#cbd5e1; font-size:13px;">Target Retur:</div>
             <div style="font-size:20px; font-weight:bold; color:#fff; margin-bottom:15px; border-left:4px solid #8e44ad; padding-left:12px;">
               ${voucherName}
             </div>
             
             <div class="popup-info-box purple">
               <i class="fa fa-exchange" style="font-size:18px; color:#a569bd; margin-top:2px;"></i>
               <div>
                 <strong>Mekanisme Retur:</strong><br>
                 1. Voucher lama (${voucherName}) akan dihapus/diarsipkan.<br>
                 2. <strong>Voucher BARU</strong> akan otomatis dibuat sebagai pengganti.<br>
                 <span style="color:#d2b4de; font-size:12px;">(Saldo/Laporan tidak berubah, hanya tukar voucher).</span>
               </div>
             </div>
          </div>
        `;

        const ok = await showOverlayChoice({
          title: 'Konfirmasi Retur',
          messageHtml: detailMsg,
          type: 'warning',
          layout: 'vertical',
          buttons: [
            {
              label: `
                <i class="fa fa-random"></i>
                <div class="btn-rich-text">
                  <span class="btn-rich-title">Ya, Proses Retur</span>
                  <span class="btn-rich-desc">Generate voucher pengganti sekarang.</span>
                </div>`,
              value: true,
              className: 'overlay-btn-purple'
            },
            {
              label: `
                <i class="fa fa-times"></i>
                <div class="btn-rich-text"><span class="btn-rich-title">Batal</span></div>`,
              value: false,
              className: 'overlay-btn-muted'
            }
          ]
        });
        if (!ok) {
          suspendAutoRefresh = false;
          return;
        }
        confirmMsg = null;
      } else if (msgLower.includes('rollback rusak')) {
        const match = confirmMsg.match(/Rollback RUSAK\s+([^\?]+)/i);
        const voucherName = match ? match[1].trim() : 'Target';
        const detailMsg = `
          <div style="text-align:left;">
             <div style="margin-bottom:8px; color:#cbd5e1; font-size:13px;">Target Pemulihan:</div>
             <div style="font-size:20px; font-weight:bold; color:#fff; margin-bottom:15px; border-left:4px solid #2ecc71; padding-left:12px;">
               ${voucherName}
             </div>
             
             <div class="popup-info-box green">
               <i class="fa fa-undo" style="font-size:18px; color:#4ade80; margin-top:2px;"></i>
               <div>
                 <strong>Efek Rollback:</strong><br>
                 Status voucher akan dikembalikan dari <strong>RUSAK</strong> menjadi <strong>READY/AKTIF</strong>.<br>
                 <span style="color:#86efac; font-weight:600;"><i class="fa fa-check"></i> User bisa login kembali.</span>
               </div>
             </div>
          </div>
        `;

        const ok = await showOverlayChoice({
          title: 'Batalkan Status Rusak',
          messageHtml: detailMsg,
          type: 'info',
          layout: 'vertical',
          buttons: [
            {
              label: `
                <i class="fa fa-history"></i>
                <div class="btn-rich-text">
                  <span class="btn-rich-title">Ya, Pulihkan Voucher</span>
                  <span class="btn-rich-desc">Kembalikan akses user ini.</span>
                </div>`,
              value: true,
              className: 'overlay-btn-success'
            },
            {
              label: `
                <i class="fa fa-times"></i>
                <div class="btn-rich-text"><span class="btn-rich-title">Batal</span></div>`,
              value: false,
              className: 'overlay-btn-muted'
            }
          ]
        });
        if (!ok) {
          suspendAutoRefresh = false;
          return;
        }
        confirmMsg = null;
      } else {
        const ok = await showOverlayChoice({
          title: 'Konfirmasi',
          messageHtml: `<div style="text-align:left;font-size:14px;">${confirmMsg}</div>`,
          type: 'info',
          buttons: [
            { label: 'Batal', value: false, className: 'overlay-btn-muted' },
            { label: 'Ya, Lanjutkan', value: true, className: 'overlay-btn-secondary' }
          ]
        });
        if (!ok) {
          suspendAutoRefresh = false;
          return;
        }
        confirmMsg = null;
      }
    }
    try {
      if (pageDim) pageDim.style.display = 'flex';
      const ajaxUrl = url + (url.includes('?') ? '&' : '?') + 'ajax=1&action_ajax=1&_=' + Date.now();
      const res = await fetch(ajaxUrl, { cache: 'no-store' });
      const text = await res.text();
      let data = null;
      try { data = JSON.parse(text); } catch (e) { data = null; }
      if (data && data.ok) {
        const isBlockDelete = url.includes('action=batch_delete') || url.includes('action=delete_block_full');
        if (isBlockDelete) {
          await new Promise((resolve) => setTimeout(resolve, 300));
          const msg = data.message || 'Blok berhasil dihapus.';
          await showOverlayChoice({
            title: 'Sukses Hapus Blok',
            messageHtml: `
              <div style="text-align:center;">
                <div style="font-size:50px; color:#10b981; margin-bottom:15px;"><i class="fa fa-check-circle"></i></div>
                <div style="font-size:18px; font-weight:bold; color:#fff; margin-bottom:10px;">Penghapusan Selesai!</div>
                <div style="color:#e2e8f0; margin-bottom:20px; font-size:14px; line-height:1.5;">${msg}</div>
                <div style="background:rgba(16, 185, 129, 0.1); border:1px solid rgba(16, 185, 129, 0.3); padding:10px; border-radius:6px; font-size:12px; color:#a7f3d0;">
                    Database dan Router telah disinkronisasi.
                </div>
              </div>`,
            type: 'info',
            buttons: [
              {
                label: 'Tutup & Reload',
                value: true,
                className: 'overlay-btn-success',
                onClick: () => {
                  window.location.href = './?hotspot=users&session=' + encodeURIComponent(usersSession);
                }
              }
            ],
            lockClose: true
          });
          return;
        }
        window.showActionPopup('success', data.message || 'Berhasil diproses.');
        suspendAutoRefresh = false;
        if (data.new_user) {
          const printUrl = './voucher/print.php?user=vc-' + encodeURIComponent(data.new_user) + '&small=yes&session=' + encodeURIComponent(usersSession);
          setTimeout(() => {
            const w = window.open(printUrl, '_blank');
            if (w) {
              try {
                w.onload = function() {
                  setTimeout(() => { try { w.print(); } catch (e) {} }, 400);
                };
              } catch (e) {}
            }
          }, 500);
        }
        if (url.includes('action=delete_status')) {
          const params = new URLSearchParams(window.location.search);
          params.set('hotspot', 'users');
          params.set('session', usersSession);
          params.set('status', 'all');
          if (commentSelect) params.set('comment', commentSelect.value);
          params.delete('page');
          window.location.href = './?' + params.toString();
          return;
        }
        if (data.redirect) {
          try { history.replaceState(null, '', data.redirect); } catch (e) {}
        }
        fetchUsers(true, false);
      } else if (!data) {
        const isBlockDelete = url.includes('action=batch_delete') || url.includes('action=delete_block_full');
        if (isBlockDelete) {
          const msg = 'Blok berhasil dihapus.';
          await showOverlayChoice({
            title: 'Sukses Hapus Blok',
            messageHtml: `
              <div style="text-align:center;">
                <div style="font-size:50px; color:#10b981; margin-bottom:15px;"><i class="fa fa-check-circle"></i></div>
                <div style="font-size:18px; font-weight:bold; color:#fff; margin-bottom:10px;">Penghapusan Selesai!</div>
                <div style="color:#e2e8f0; margin-bottom:20px; font-size:14px; line-height:1.5;">${msg}</div>
              </div>`,
            type: 'info',
            buttons: [
              {
                label: 'Tutup & Reload',
                value: true,
                className: 'overlay-btn-success',
                onClick: () => {
                  window.location.href = './?hotspot=users&session=' + encodeURIComponent(usersSession);
                }
              }
            ],
            lockClose: true
          });
          return;
        }
        window.showActionPopup('success', 'Berhasil diproses.');
        suspendAutoRefresh = false;
        if (url.includes('action=delete_status')) {
          const params = new URLSearchParams(window.location.search);
          params.set('hotspot', 'users');
          params.set('session', usersSession);
          params.set('status', 'all');
          if (commentSelect) params.set('comment', commentSelect.value);
          params.delete('page');
          window.location.href = './?' + params.toString();
          return;
        }
        fetchUsers(true, false);
      } else {
        window.showActionPopup('error', (data && data.message) ? data.message : 'Gagal memproses.');
        suspendAutoRefresh = false;
      }
    } catch (e) {
      window.showActionPopup('error', 'Gagal memproses.');
      suspendAutoRefresh = false;
    } finally {
      if (pageDim) pageDim.style.display = 'none';
    }
  };

  window.actionRequestRusak = async function(elOrUrl, urlMaybe, confirmMsg) {
    let el = null;
    let url = '';
    let reloginEvents = [];
    if (typeof elOrUrl === 'string') {
      url = elOrUrl;
      confirmMsg = urlMaybe;
    } else {
      el = elOrUrl;
      url = urlMaybe;
    }
    try {
      if (el) {
        const uname = el.getAttribute('data-user') || '';
        if (uname) {
          try {
            const params = new URLSearchParams();
            params.set('action', 'login_events');
            params.set('name', uname);
            params.set('session', usersSession);
            const firstLogin = el.getAttribute('data-first-login') || '';
            const dateKey = extractDateKey(firstLogin);
            if (dateKey) {
              params.set('show', 'harian');
              params.set('date', dateKey);
            }
            params.set('ajax', '1');
            params.set('_', Date.now().toString());
            const resp = await fetch(ajaxBase + '?' + params.toString(), { cache: 'no-store' });
            const evData = await resp.json();
            if (evData && Array.isArray(evData.events)) {
              reloginEvents = evData.events;
            }
          } catch (e) {}
        }
      }
      const checkUrl = url.replace('action=invalid', 'action=check_rusak');
      const ajaxCheck = checkUrl + (checkUrl.includes('?') ? '&' : '?') + 'ajax=1&action_ajax=1&_=' + Date.now();
      const res = await fetch(ajaxCheck, { cache: 'no-store' });
      const text = await res.text();
      let data = null;
      try { data = JSON.parse(text); } catch (e) { data = null; }
      if (!data) {
        data = buildRusakDataFromElement(el) || {
          ok: false,
          message: 'Gagal memproses. Data validasi tidak terbaca.',
          criteria: {},
          values: {},
          limits: {},
          meta: {}
        };
      }
      if (data && !data.meta) {
        data.meta = {};
      }
      if (data && data.meta) {
        if (reloginEvents.length > 0) data.meta.relogin_events = reloginEvents;
        if (reloginEvents.length > 0) data.meta.relogin_count = reloginEvents.length;
        const firstLoginMeta = el ? (el.getAttribute('data-first-login') || '') : '';
        const dateKeyMeta = extractDateKey(firstLoginMeta);
        if (dateKeyMeta) data.meta.relogin_date = dateKeyMeta;
        if (!data.meta.username) {
          const elUser = el ? (el.getAttribute('data-user') || '') : '';
          const urlUserMatch = url.match(/name=([^&]+)/i);
          const urlUser = urlUserMatch ? decodeURIComponent(urlUserMatch[1]) : '';
          data.meta.username = elUser || urlUser || '';
        }
      }
      const ok = await showRusakChecklist(data);
      if (!ok) return;
      window.actionRequest(url, null);
    } catch (e) {
      const data = buildRusakDataFromElement(el) || {
        ok: false,
        message: 'Gagal memproses. Tidak bisa mengambil data validasi.',
        criteria: {},
        values: {},
        limits: {}
      };
      await showRusakChecklist(data);
    }
  };

  function closeReloginModal() {
    if (reloginModal) reloginModal.style.display = 'none';
  }

  let reloginPrintPayload = null;

  function formatDateHeader(dt) {
    if (!dt) return '';
    const parts = dt.split(' ');
    if (!parts[0]) return '';
    const d = parts[0].split('-');
    if (d.length !== 3) return '';
    return `${d[2]}-${d[1]}-${d[0]}`;
  }
  function extractDateKey(dt) {
    if (!dt) return '';
    const part = dt.split(' ')[0] || '';
    return /^\d{4}-\d{2}-\d{2}$/.test(part) ? part : '';
  }
  function formatDateNow() {
    const d = new Date();
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const yy = d.getFullYear();
    return `${dd}-${mm}-${yy}`;
  }

  function formatBlokLabel(blok) {
    if (!blok) return '';
    const raw = blok.replace(/^BLOK-?/i, '').trim();
    const m = raw.match(/^([A-Z]+)/i);
    return m ? m[1].toUpperCase() : raw.toUpperCase();
  }

  function formatProfileLabel(profile) {
    if (!profile) return '';
    return profile.replace(/(\d+)\s*(menit)/i, '$1 Menit');
  }

  function formatTimeOnly(dt) {
    if (!dt) return '-';
    const parts = dt.split(' ');
    return parts[1] || '-';
  }

  async function openReloginModal(username, blok, profile) {
    if (!reloginModal || !reloginBody) return;
    if (reloginTitle) reloginTitle.textContent = `Detail Relogin - ${username}`;
    if (reloginSub) reloginSub.textContent = '';
    reloginBody.innerHTML = '<div style="text-align:center;color:#9aa0a6;">Memuat...</div>';
    reloginModal.style.display = 'flex';
    reloginPrintPayload = null;
    try {
      const params = new URLSearchParams();
      params.set('action', 'login_events');
      params.set('name', username);
      params.set('session', usersSession);
      if (showSelect) params.set('show', showSelect.value);
      if (dateInput) params.set('date', dateInput.value);
      params.set('ajax', '1');
      params.set('_', Date.now().toString());
      const res = await fetch(ajaxBase + '?' + params.toString(), { cache: 'no-store' });
      const data = await res.json();
      if (!data || !data.ok) {
        reloginBody.innerHTML = '<div style="text-align:center;color:#ff6b6b;">Gagal memuat data.</div>';
        return;
      }
      const events = Array.isArray(data.events) ? data.events : [];
      if (events.length === 0) {
        reloginBody.innerHTML = '<div style="text-align:center;color:#9aa0a6;">Tidak ada data relogin.</div>';
        return;
      }
      const firstDateTime = events.find(ev => ev.login_time || ev.logout_time);
      const headerDate = firstDateTime ? formatDateHeader(firstDateTime.login_time || firstDateTime.logout_time) : '';
      if (reloginSub) {
        const parts = [];
        if (headerDate) parts.push(headerDate);
        if (blok) parts.push(`Blok ${formatBlokLabel(blok)}`);
        if (profile) parts.push(formatProfileLabel(profile));
        reloginSub.textContent = parts.join(' · ');
      }
      let html = '<table class="relogin-table"><thead><tr><th>#</th><th>Login</th><th>Logout</th></tr></thead><tbody>';
      events.forEach((ev, idx) => {
        const seq = ev.seq || (idx + 1);
        const loginLabel = formatTimeOnly(ev.login_time);
        const logoutLabel = formatTimeOnly(ev.logout_time);
        const note = (!ev.login_time && ev.logout_time) ? '<span class="relogin-note">logout tanpa login</span>' : '';
        html += `<tr><td>#${seq}</td><td>${loginLabel}${note}</td><td>${logoutLabel}</td></tr>`;
      });
      html += '</tbody></table>';
      if ((data.total || 0) > events.length) {
        html += '<div class="relogin-more">lebih...</div>';
      }
      reloginBody.innerHTML = html;
      reloginPrintPayload = { username, events, headerDate, blok, profile };
    } catch (e) {
      reloginBody.innerHTML = '<div style="text-align:center;color:#ff6b6b;">Gagal memuat data.</div>';
    }
  }

  function buildUrl(isSearch) {
    const params = new URLSearchParams();
    params.set('session', usersSession);
    params.set('ajax', '1');
    const qValue = isSearch ? searchInput.value.trim() : appliedQuery;
    params.set('q', qValue);
    if (statusSelect) params.set('status', statusSelect.value);
    if (commentSelect) params.set('comment', commentSelect.value);
    if (showSelect) params.set('show', showSelect.value);
    if (dateInput) params.set('date', dateInput.value);
    const profile = baseParams.get('profile');
    if (profile) params.set('profile', profile);
    const perPage = baseParams.get('per_page');
    if (perPage) params.set('per_page', perPage);
    const debug = baseParams.get('debug');
    if (debug) params.set('debug', debug);
    const ro = baseParams.get('readonly');
    if (ro) params.set('readonly', ro);
    if (isSearch) params.set('page', '1');
    params.set('_', Date.now().toString());
    return ajaxBase + '?' + params.toString();
  }

  function updateSearchUrl(qValue) {
    const url = new URL(window.location.href);
    if (qValue) {
      url.searchParams.set('q', qValue);
      url.searchParams.set('page', '1');
    } else {
      url.searchParams.delete('q');
      url.searchParams.delete('page');
    }
    try { history.replaceState(null, '', url.toString()); } catch (e) {}
  }

  async function refreshActiveStatus() {
    if (!activeBadge) return;
    try {
      const url = './hotspot/user/aload_users.php?session=' + encodeURIComponent(usersSession) + '&load=users_status&_=' + Date.now();
      const res = await fetch(url, { cache: 'no-store' });
      if (!res.ok) return;
      const data = await res.json();
      if (data && Array.isArray(data.active)) {
        activeBadge.textContent = `Online: ${data.active.length} User`;
      }
    } catch (e) {}
  }

  async function fetchUsers(isSearch, showLoading) {
    const fetchId = ++lastFetchId;
    try {
      if (isSearch) appliedQuery = searchInput.value.trim();
      if (showLoading && searchLoading) searchLoading.style.display = 'inline-block';
      if (showLoading && pageDim) pageDim.style.display = 'flex';
      const res = await fetch(buildUrl(isSearch), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });
      if (!res.ok) return;
      const data = await res.json();
      if (fetchId !== lastFetchId) return;
      if (typeof data.rows_html === 'string') tbody.innerHTML = data.rows_html;
      if (typeof data.pagination_html === 'string') paginationWrap.innerHTML = data.pagination_html;
      if (typeof data.total_label === 'string') totalBadge.textContent = data.total_label;
    } catch (e) {}
    finally {
      if (showLoading && searchLoading) searchLoading.style.display = 'none';
      if (showLoading && pageDim) pageDim.style.display = 'none';
    }
  }

  searchInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      const hasQuery = searchInput.value.trim() !== '';
      updateSearchUrl(searchInput.value.trim());
      fetchUsers(true, hasQuery);
    }
  });
  searchInput.addEventListener('input', () => {
    toggleClearBtn();
  });
  searchInput.addEventListener('blur', () => {
    toggleClearBtn();
  });
  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      if (searchInput.value !== '') {
        searchInput.value = '';
        toggleClearBtn();
        updateSearchUrl('');
        fetchUsers(true, true);
        searchInput.focus();
      }
    });
  }
  if (form) {
    form.addEventListener('submit', (e) => {
      if (document.activeElement === searchInput) {
        e.preventDefault();
        const hasQuery = searchInput.value.trim() !== '';
        fetchUsers(true, hasQuery);
      }
    });
  }

  if (tbody) {
    tbody.addEventListener('click', (e) => {
      const badge = e.target.closest('.st-relogin');
      if (!badge) return;
      const username = badge.getAttribute('data-user') || '';
      const blok = badge.getAttribute('data-blok') || '';
      const profile = badge.getAttribute('data-profile') || '';
      if (username) openReloginModal(username, blok, profile);
    });
  }
  if (reloginClose) {
    reloginClose.addEventListener('click', closeReloginModal);
  }
  if (reloginPrint) {
    reloginPrint.addEventListener('click', () => {
      if (!reloginPrintPayload || !reloginPrintPayload.events) return;
      const { username, events, headerDate, blok, profile } = reloginPrintPayload;
      const rows = events.map((ev, idx) => {
        const seq = ev.seq || (idx + 1);
        const loginLabel = formatTimeOnly(ev.login_time);
        const logoutLabel = formatTimeOnly(ev.logout_time);
        const note = (!ev.login_time && ev.logout_time) ? ' (logout tanpa login)' : '';
        return `<tr><td>#${seq}</td><td>${loginLabel}${note}</td><td>${logoutLabel}</td></tr>`;
      }).join('');
      const metaParts = [];
      if (headerDate) metaParts.push(headerDate);
      if (blok) metaParts.push(`Blok ${formatBlokLabel(blok)}`);
      if (profile) metaParts.push(formatProfileLabel(profile));
      const metaLine = metaParts.length ? `<div style="margin:6px 0 10px 0;font-size:12px;color:#444;">${metaParts.join(' · ')}</div>` : '';
      const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Detail Relogin</title>
        <style>
          body{font-family:Arial,sans-serif;color:#111;margin:20px;}
          h3{margin:0 0 10px 0;}
          table{width:100%;border-collapse:collapse;font-size:12px;}
          th,td{border:1px solid #444;padding:6px 8px;}
          th{background:#f0f0f0;text-align:left;}
        </style>
      </head><body>
        <h3>Detail Relogin - ${username}</h3>
        ${metaLine}
        <table><thead><tr><th>#</th><th>Login</th><th>Logout</th></tr></thead><tbody>${rows}</tbody></table>
      </body></html>`;
      const w = window.open('', '_blank');
      if (!w) return;
      w.document.open();
      w.document.write(html);
      w.document.close();
      w.focus();
      w.print();
    });
  }
  if (reloginModal) {
    reloginModal.addEventListener('click', (e) => {
      if (e.target === reloginModal) closeReloginModal();
    });
  }

  function canAutoRefresh() {
    if (suspendAutoRefresh) return false;
    if (overlayBackdrop && overlayBackdrop.classList.contains('show')) return false;
    const statusOk = !statusSelect || statusSelect.value === 'all';
    const blokOk = !commentSelect || commentSelect.value === '';
    return statusOk && blokOk;
  }

  setInterval(() => {
    if (document.hidden) return;
    if (!canAutoRefresh()) return;
    const currentInput = searchInput.value.trim();
    if (currentInput !== appliedQuery) return;
    fetchUsers(false, false);
    refreshActiveStatus();
  }, 15000);

  if (canAutoRefresh()) {
    refreshActiveStatus();
  }
})();
