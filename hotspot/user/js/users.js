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
  const confirmModal = document.getElementById('confirm-modal');
  const confirmMessage = document.getElementById('confirm-message');
  const confirmOk = document.getElementById('confirm-ok');
  const confirmCancel = document.getElementById('confirm-cancel');
  const confirmClose = document.getElementById('confirm-close');
  const confirmPrint = document.getElementById('confirm-print');
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

  let lastFetchId = 0;
  let appliedQuery = (baseParams.get('q') || '').trim();

  window.showActionPopup = function(type, message) {
    if (!actionBanner) return;
    actionBanner.classList.remove('success', 'error');
    actionBanner.classList.add(type === 'error' ? 'error' : 'success');
    actionBanner.innerHTML = `<i class="fa ${type === 'error' ? 'fa-times-circle' : 'fa-check-circle'}"></i><span>${message}</span>`;
    actionBanner.style.display = 'flex';
    setTimeout(() => { actionBanner.style.display = 'none'; }, 2800);
  };

  function showConfirm(message) {
    return new Promise((resolve) => {
      if (!confirmModal || !confirmMessage || !confirmOk || !confirmCancel) {
        resolve(true);
        return;
      }
      confirmMessage.textContent = message || 'Lanjutkan aksi ini?';
      confirmModal.style.display = 'flex';
      const cleanup = (result) => {
        confirmModal.style.display = 'none';
        confirmOk.onclick = null;
        confirmCancel.onclick = null;
        try { document.activeElement && document.activeElement.blur(); } catch (e) {}
        try { document.body.focus(); } catch (e) {}
        resolve(result);
      };
      confirmOk.onclick = () => cleanup(true);
      confirmCancel.onclick = () => cleanup(false);
      if (confirmClose) confirmClose.onclick = () => cleanup(false);
    });
  }

  if (confirmPrint) {
    confirmPrint.addEventListener('click', (e) => {
      if (e && typeof e.preventDefault === 'function') e.preventDefault();
      const url = confirmPrint.dataset.url || '';
      if (!url) return;
      const w = window.open(url, '_blank');
      if (!w) {
        window.location.href = url;
      }
    });
  }

  let rusakPrintPayload = null;

  function showRusakChecklist(data) {
    return new Promise((resolve) => {
      if (!confirmModal || !confirmMessage || !confirmOk || !confirmCancel) {
        resolve(false);
        return;
      }
      const criteria = data.criteria || {};
      const values = data.values || {};
      const limits = data.limits || {};
      const headerMsg = data.message || '';
      const meta = data.meta || {};
      const items = [
        { label: `Offline (tidak sedang online)`, ok: !!criteria.offline, value: values.online || '-' },
        { label: `Bytes maksimal ${limits.bytes || '-'}`, ok: !!criteria.bytes_ok, value: values.bytes || '-' },
        { label: `Uptime (informasi)`, ok: true, value: values.total_uptime || '-' },
        { label: `Pernah login (first login ada)`, ok: !!criteria.first_login_ok, value: String(values.first_login ?? '-') }
      ];
      const rows = items.map(it => {
        const icon = it.ok ? 'fa-check-circle' : 'fa-times-circle';
        const color = it.ok ? '#16a34a' : '#dc2626';
        return `<tr>
          <td style="padding:6px 8px;border-bottom:1px solid #3d3d3d;"><i class="fa ${icon}" style="color:${color};margin-right:6px;"></i>${it.label}</td>
          <td style="padding:6px 8px;border-bottom:1px solid #3d3d3d;color:#cbd5e1;text-align:right;">${it.value}</td>
        </tr>`;
      }).join('');
      const msgHtml = headerMsg ? `<div style="margin-bottom:8px;color:#f3c969;">${headerMsg}</div>` : '';
      confirmMessage.innerHTML = `
        <div style="text-align:left;">
          <div style="margin-bottom:8px;font-weight:600;">Cek Kelayakan Rusak</div>
          ${msgHtml}
          <table style="width:100%;border-collapse:collapse;font-size:12px;">
            <thead>
              <tr>
                <th style="text-align:left;padding:6px 8px;border-bottom:1px solid #3d3d3d;">Kriteria</th>
                <th style="text-align:right;padding:6px 8px;border-bottom:1px solid #3d3d3d;">Nilai</th>
              </tr>
            </thead>
            <tbody>
              ${rows}
            </tbody>
          </table>
        </div>`;
      const isValid = !!data.ok;
      confirmOk.textContent = 'Ya, Lanjutkan';
      confirmOk.disabled = !isValid;
      confirmOk.style.opacity = isValid ? '1' : '0.5';
      confirmOk.style.cursor = isValid ? 'pointer' : 'not-allowed';
      if (confirmPrint) {
        confirmPrint.style.display = 'inline-flex';
        confirmPrint.disabled = false;
        confirmPrint.style.pointerEvents = 'auto';
        confirmPrint.style.opacity = '1';
        confirmPrint.style.cursor = 'pointer';
      }
      confirmModal.style.display = 'flex';
      rusakPrintPayload = { headerMsg, items, meta, fallbackUser: (meta && meta.username) ? meta.username : '' };
      if (confirmPrint) {
        confirmPrint.dataset.username = (meta && meta.username) ? meta.username : (rusakPrintPayload.fallbackUser || '');
        confirmPrint.dataset.session = usersSession || '';
      }
      const cleanup = (result) => {
        confirmModal.style.display = 'none';
        confirmOk.onclick = null;
        confirmCancel.onclick = null;
        if (confirmPrint) confirmPrint.onclick = null;
        confirmOk.disabled = false;
        confirmOk.style.opacity = '1';
        confirmOk.style.cursor = 'pointer';
        if (confirmPrint) confirmPrint.style.display = 'none';
        try { document.activeElement && document.activeElement.blur(); } catch (e) {}
        try { document.body.focus(); } catch (e) {}
        resolve(result);
      };
      confirmOk.onclick = () => cleanup(true);
      confirmCancel.onclick = () => cleanup(false);
      if (confirmClose) confirmClose.onclick = () => cleanup(false);
      if (confirmPrint) confirmPrint.onclick = (e) => {
        if (e && typeof e.preventDefault === 'function') e.preventDefault();
        const mt = rusakPrintPayload ? (rusakPrintPayload.meta || {}) : {};
        const uname = (mt.username || rusakPrintPayload?.fallbackUser || confirmPrint.dataset.username || '').toString();
        const sess = (usersSession || confirmPrint.dataset.session || '').toString();
        if (!uname) return;
        const url = './hotspot/print/print.detail.php?session=' + encodeURIComponent(sess) + '&user=' + encodeURIComponent(uname);
        const w = window.open(url, '_blank');
        if (!w) {
          window.location.href = url;
        }
      };
    });
  }

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
    if (confirmMsg) {
      const ok = await showConfirm(confirmMsg);
      if (!ok) return;
    }
    try {
      if (pageDim) pageDim.style.display = 'flex';
      const ajaxUrl = url + (url.includes('?') ? '&' : '?') + 'ajax=1&action_ajax=1&_=' + Date.now();
      const res = await fetch(ajaxUrl, { cache: 'no-store' });
      const text = await res.text();
      let data = null;
      try { data = JSON.parse(text); } catch (e) { data = null; }
      if (data && data.ok) {
        window.showActionPopup('success', data.message || 'Berhasil diproses.');
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
        if (url.includes('action=batch_delete')) {
          window.location.href = './?hotspot=users&session=' + encodeURIComponent(usersSession);
          return;
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
        window.showActionPopup('success', 'Berhasil diproses.');
        if (url.includes('action=batch_delete')) {
          window.location.href = './?hotspot=users&session=' + encodeURIComponent(usersSession);
          return;
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
        fetchUsers(true, false);
      } else {
        window.showActionPopup('error', (data && data.message) ? data.message : 'Gagal memproses.');
      }
    } catch (e) {
      window.showActionPopup('error', 'Gagal memproses.');
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
          if (confirmPrint) {
            const url = './hotspot/print/print.detail.php?session=' + encodeURIComponent(usersSession) + '&user=' + encodeURIComponent(uname);
            confirmPrint.dataset.url = url;
          }
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
      if (data && data.meta) {
        if (reloginEvents.length > 0) data.meta.relogin_events = reloginEvents;
        if (reloginEvents.length > 0) data.meta.relogin_count = reloginEvents.length;
        const firstLoginMeta = el ? (el.getAttribute('data-first-login') || '') : '';
        const dateKeyMeta = extractDateKey(firstLoginMeta);
        if (dateKeyMeta) data.meta.relogin_date = dateKeyMeta;
        if (!data.meta.username && el) {
          data.meta.username = el.getAttribute('data-user') || '';
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
