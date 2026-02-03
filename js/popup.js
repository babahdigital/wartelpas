(function() {
  if (window.MikhmonPopup) return;

  var backdrop = null;
  var card = null;
  var titleEl = null;
  var iconEl = null;
  var bodyEl = null;
  var alertEl = null;
  var footerEl = null;

  function ensurePopup() {
    if (backdrop) return;
    backdrop = document.createElement('div');
    backdrop.className = 'm-popup-backdrop';
    backdrop.innerHTML = '' +
      '<div class="m-modal-card">' +
        '<div class="m-modal-header">' +
          '<h4 class="m-modal-title"><i class="fa fa-info-circle"></i><span></span></h4>' +
          '<button class="m-close-x" type="button">&times;</button>' +
        '</div>' +
        '<div class="m-modal-body">' +
          '<div class="m-status-info">' +
            '<i class="fa fa-info-circle"></i>' +
            '<div class="m-status-text"></div>' +
          '</div>' +
          '<div class="m-alert m-alert-info m-popup-hidden"></div>' +
        '</div>' +
        '<div class="m-modal-footer"></div>' +
      '</div>';
    document.body.appendChild(backdrop);
    card = backdrop.querySelector('.m-modal-card');
    titleEl = backdrop.querySelector('.m-modal-title span');
    iconEl = backdrop.querySelector('.m-modal-title i');
    bodyEl = backdrop.querySelector('.m-status-text');
    alertEl = backdrop.querySelector('.m-alert');
    footerEl = backdrop.querySelector('.m-modal-footer');

    backdrop.addEventListener('click', function(e) {
      if (e.target === backdrop) return;
    });
    backdrop.querySelector('.m-close-x').addEventListener('click', close);
  }

  function clearAlert() {
    alertEl.className = 'm-alert m-popup-hidden';
    alertEl.innerHTML = '';
  }

  function setAlert(alert) {
    if (!alert) {
      clearAlert();
      return;
    }
    var type = (alert.type || 'info').toLowerCase();
    alertEl.className = 'm-alert m-alert-' + type;
    alertEl.classList.remove('m-popup-hidden');
    alertEl.innerHTML = '<div>' + (alert.html || alert.text || '') + '</div>';
  }

  function setButtons(buttons) {
    footerEl.innerHTML = '';
    var btns = Array.isArray(buttons) ? buttons : [];
    if (!btns.length) {
      btns = [{ label: 'Tutup', className: 'm-btn m-btn-cancel', close: true }];
    }
    btns.forEach(function(btn) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = btn.className || 'm-btn m-btn-primary';
      b.innerHTML = btn.label || 'OK';
      b.addEventListener('click', function() {
        if (typeof btn.onClick === 'function') btn.onClick();
        if (btn.close !== false) close();
      });
      footerEl.appendChild(b);
    });
  }

  function setCardClass(cls) {
    if (!card) return;
    card.classList.remove('is-backup', 'is-restore', 'is-retur', 'is-medium', 'is-small');
    if (cls) card.classList.add(cls);
  }

  function open(options) {
    ensurePopup();
    var opts = options || {};
    var iconClass = opts.iconClass || 'fa fa-info-circle';
    var title = opts.title || 'Informasi';

    iconEl.className = iconClass;
    titleEl.textContent = title;

    if (opts.messageHtml) {
      bodyEl.innerHTML = opts.messageHtml;
    } else {
      bodyEl.textContent = opts.message || '';
    }

    var statusIcon = opts.statusIcon || iconClass;
    var statusEl = card.querySelector('.m-status-info i');
    statusEl.className = statusIcon;

    if (opts.statusColor) {
      statusEl.style.color = opts.statusColor;
    } else {
      statusEl.style.removeProperty('color');
    }

    setAlert(opts.alert);
    setButtons(opts.buttons);
    setCardClass(opts.cardClass || opts.sizeClass);

    backdrop.classList.add('show');
  }

  function close() {
    if (!backdrop) return;
    backdrop.classList.remove('show');
    clearAlert();
  }

  window.MikhmonPopup = {
    open: open,
    close: close
  };
})();

(function() {
  function getFlag(name, fallback) {
    if (typeof window[name] !== 'undefined') return !!window[name];
    return !!fallback;
  }

  function setPasswordPopupAlert(type, text) {
    var alertEl = document.querySelector('.m-popup-backdrop.show .m-alert');
    if (!alertEl) return;
    alertEl.className = 'm-alert m-alert-' + (type || 'info');
    alertEl.classList.remove('m-popup-hidden');
    alertEl.innerHTML = '<div>' + (text || '') + '</div>';
  }

  function clearPasswordPopupAlert() {
    var alertEl = document.querySelector('.m-popup-backdrop.show .m-alert');
    if (!alertEl) return;
    alertEl.className = 'm-alert m-popup-hidden';
    alertEl.innerHTML = '';
  }

  window.openPasswordPopup = function() {
    if (!window.MikhmonPopup) return;
    var isSuper = getFlag('__isSuperAdminFlag', false);
    var html = '';
    if (isSuper) {
      html = '' +
        '<div class="m-pass-form">' +
          '<div class="m-pass-row">' +
            '<label class="m-pass-label">Password Admin Saat Ini</label>' +
            '<input id="pw-current" type="password" class="m-pass-input" placeholder="Password saat ini" />' +
          '</div>' +
          '<div class="m-pass-row">' +
            '<label class="m-pass-label">Password Admin Baru</label>' +
            '<input id="pw-new" type="password" class="m-pass-input" placeholder="Minimal 6 karakter" />' +
          '</div>' +
          '<div class="m-pass-row">' +
            '<label class="m-pass-label">Konfirmasi Password Admin</label>' +
            '<input id="pw-confirm" type="password" class="m-pass-input" placeholder="Ulangi password admin" />' +
          '</div>' +
        '</div>';
    } else {
      html = '' +
        '<div class="m-pass-form">' +
          '<div class="m-pass-row">' +
            '<label class="m-pass-label">Password Operator Saat Ini</label>' +
            '<input id="pw-op-current" type="password" class="m-pass-input" placeholder="Password saat ini" />' +
          '</div>' +
          '<div class="m-pass-row">' +
            '<label class="m-pass-label">Password Operator Baru</label>' +
            '<input id="pw-op-new" type="password" class="m-pass-input" placeholder="Minimal 6 karakter" />' +
          '</div>' +
          '<div class="m-pass-row">' +
            '<label class="m-pass-label">Konfirmasi Password Operator</label>' +
            '<input id="pw-op-confirm" type="password" class="m-pass-input" placeholder="Ulangi password operator" />' +
          '</div>' +
        '</div>';
    }

    window.MikhmonPopup.open({
      title: 'Ubah Password',
      iconClass: 'fa fa-lock',
      statusIcon: 'fa fa-key',
      statusColor: '#f59e0b',
      cardClass: 'is-pass',
      messageHtml: html,
      buttons: [
        {
          label: 'Simpan',
          className: 'm-btn m-btn-success',
          close: false,
          onClick: function() {
            clearPasswordPopupAlert();
            var current = (document.getElementById('pw-current') || {}).value || '';
            var next = (document.getElementById('pw-new') || {}).value || '';
            var confirm = (document.getElementById('pw-confirm') || {}).value || '';
            var opNext = '';
            var opConfirm = '';
            var opCurrent = (document.getElementById('pw-op-current') || {}).value || '';
            var payload = new URLSearchParams();
            payload.append('current_password', current.trim());
            payload.append('new_password', next.trim());
            payload.append('confirm_password', confirm.trim());
            payload.append('operator_password', opNext.trim());
            payload.append('operator_confirm', opConfirm.trim());
            payload.append('operator_current', opCurrent.trim());

            fetch('./settings/password_update.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: payload.toString()
            })
            .then(function(r){ return r.json(); })
            .then(function(res){
              if (res && res.ok) {
                setPasswordPopupAlert('info', res.message || 'Password berhasil diperbarui.');
                setTimeout(function(){ window.MikhmonPopup.close(); }, 900);
              } else {
                setPasswordPopupAlert('danger', (res && res.message) ? res.message : 'Gagal memperbarui password.');
              }
            })
            .catch(function(){
              setPasswordPopupAlert('danger', 'Gagal memperbarui password.');
            });
          }
        },
        { label: 'Batal', className: 'm-btn m-btn-cancel' }
      ]
    });
  };
})();

(function() {
  function getSession() {
    return window.__returSession || '';
  }

  function escapeHtml(str) {
    return String(str || '').replace(/[&<>"]/g, function(s) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[s];
    });
  }

  function formatReturDate(item) {
    var d = item && (item.request_date || item.created_at) ? String(item.request_date || item.created_at) : '';
    if (!d) return '-';
    var datePart = d.substring(0, 10);
    if (!datePart || datePart.indexOf('-') === -1) return datePart || '-';
    var parts = datePart.split('-');
    if (parts.length !== 3) return datePart;
    return [parts[2], parts[1], parts[0]].join('-');
  }

  function formatReturTime(item) {
    var d = item && item.created_at ? String(item.created_at) : '';
    if (!d) return '-';
    var timePart = d.length >= 19 ? d.substring(11, 16) : '';
    return timePart || '-';
  }

  function formatBlokLabel(raw) {
    var val = String(raw || '').toUpperCase();
    if (!val) return '-';
    var match = val.match(/BLOK[-\s]*([A-Z0-9])/i);
    var key = match ? match[1] : '';
    var names = window.__returBlokNames || {};
    if (key && names && names[key]) {
      return names[key];
    }
    return val;
  }

  function getReturData() {
    var data = window.__returMenuData || { count: 0, items: [] };
    if (!Array.isArray(data.items)) data.items = [];
    if (typeof data.count === 'undefined' || data.count === null) {
      var counts = getReturCounts(data.items);
      data.count = counts.pending || 0;
    } else {
      data.count = Number(data.count || 0);
    }
    return data;
  }

  function setReturData(data) {
    window.__returMenuData = data;
  }

  function getReturFilter() {
    return window.__returFilter || 'pending';
  }

  function setReturFilter(val) {
    window.__returFilter = val;
  }

  function normalizeReturStatus(val) {
    var st = String(val || '').toLowerCase();
    if (st === 'approved' || st === 'rejected' || st === 'pending') return st;
    return 'pending';
  }

  function getReturCounts(items) {
    var counts = { all: 0, pending: 0, approved: 0, rejected: 0, refund: 0, retur: 0 };
    (items || []).forEach(function(it) {
      var st = normalizeReturStatus(it.status);
      var typeRaw = String(it.request_type || '').toLowerCase();
      counts.all++;
      counts[st] = (counts[st] || 0) + 1;
      if (typeRaw === 'pengembalian') {
        counts.refund++;
      } else {
        counts.retur++;
      }
    });
    return counts;
  }

  function updateReturItemStatus(id, status) {
    var data = getReturData();
    data.items = (data.items || []).map(function(it) {
      if (String(it.id || '') === String(id || '')) {
        it.status = status;
      }
      return it;
    });
    var counts = getReturCounts(data.items);
    data.count = counts.pending || 0;
    setReturData(data);
    updateReturCountUI(data.count);
  }

  function updateReturCountUI(count) {
    var pill = document.getElementById('retur-menu-pill');
    var countEl = document.getElementById('retur-menu-count');
    if (countEl) countEl.textContent = String(count);
    if (pill) {
      if (count > 0) {
        pill.classList.remove('is-zero');
      } else {
        pill.classList.add('is-zero');
      }
    }
  }

  function removeReturItem(id) {
    var data = getReturData();
    data.items = data.items.filter(function(it) {
      return String(it.id || '') !== String(id || '');
    });
    var counts = getReturCounts(data.items);
    data.count = counts.pending || 0;
    setReturData(data);
    updateReturCountUI(data.count);
  }

  function findReturItem(id) {
    var data = getReturData();
    var items = data.items || [];
    for (var i = 0; i < items.length; i++) {
      if (String(items[i].id || '') === String(id || '')) return items[i];
    }
    return null;
  }

  function renderStatusBadge(status) {
    var st = normalizeReturStatus(status);
    var label = st === 'approved' ? 'APPROVED' : st === 'rejected' ? 'REJECTED' : 'PENDING';
    var cls = st === 'approved' ? 'retur-status-approved' : st === 'rejected' ? 'retur-status-rejected' : 'retur-status-pending';
    return '<span class="retur-status ' + cls + '">' + label + '</span>';
  }

  function buildReturActionUrl(action, id, note) {
    var session = getSession();
    var base = './?hotspot=users&action=' + encodeURIComponent(action) +
      '&req_id=' + encodeURIComponent(id) +
      '&session=' + encodeURIComponent(session) +
      '&retur_status=pending' +
      '&ajax=1&action_ajax=1';
    if (note) {
      base += '&note=' + encodeURIComponent(note);
    }
    return base;
  }

  function buildCheckRusakUrl(voucher, reason) {
    var session = getSession();
    var base = './?hotspot=users&action=check_rusak' +
      '&name=' + encodeURIComponent(voucher || '') +
      '&session=' + encodeURIComponent(session) +
      '&ajax=1&action_ajax=1';
    if (reason) {
      base += '&c=' + encodeURIComponent(reason);
    }
    return base;
  }

  function buildRusakUrl(voucher, reason) {
    var session = getSession();
    var base = './?hotspot=users&action=invalid' +
      '&name=' + encodeURIComponent(voucher || '') +
      '&session=' + encodeURIComponent(session) +
      '&ajax=1&action_ajax=1';
    if (reason) {
      base += '&c=' + encodeURIComponent(reason);
    }
    return base;
  }

  function renderCheckRow(label, ok) {
    var icon = ok ? 'fa fa-check-circle' : 'fa fa-times-circle';
    var cls = ok ? 'eligibility-row is-ok' : 'eligibility-row is-bad';
    return '<div class="' + cls + '">' +
      '<i class="' + icon + '"></i>' +
      '<span>' + escapeHtml(label) + '</span>' +
    '</div>';
  }

  function showReturEligibility(id) {
    var item = findReturItem(id) || {};
    var voucher = item.voucher_code || '';
    var reason = item.reason || '';
    var typeRaw = String(item.request_type || '').toLowerCase();
    var isRefund = typeRaw === 'pengembalian';
    var typeLabel = isRefund ? 'Refund' : 'Retur';
    var typeClass = isRefund ? 'retur-confirm-refund' : 'retur-confirm-retur';
    if (!voucher) {
      window.__returLastMessage = { type: 'danger', text: 'Kode voucher tidak ditemukan.' };
      window.openReturMenuPopup();
      return;
    }

    window.MikhmonPopup.open({
      title: 'Cek Kelayakan Rusak',
      iconClass: 'fa fa-search',
      statusIcon: 'fa fa-circle-o-notch fa-spin',
      statusColor: '#2f81f7',
      message: 'Memeriksa kelayakan voucher...',
      buttons: [
        { label: 'Tunggu...', className: 'm-btn m-btn-cancel', close: false }
      ]
    });

    fetch(buildCheckRusakUrl(voucher, reason), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function(resp){ return resp.json(); })
      .then(function(data){
        var ok = data && data.ok === true;
        var criteria = (data && data.criteria) ? data.criteria : {};
        var values = (data && data.values) ? data.values : {};
        var limits = (data && data.limits) ? data.limits : {};
        var criteriaHtml = '' +
          renderCheckRow('Offline: ' + (criteria.offline ? 'Ya' : 'Tidak'), !!criteria.offline) +
          renderCheckRow('Bytes OK (<= ' + (limits.bytes || '-') + ')', !!criteria.bytes_ok) +
          renderCheckRow('Total uptime OK', !!criteria.total_uptime_ok) +
          renderCheckRow('First login tercatat', !!criteria.first_login_ok);

        var rusakHtml = isRefund ? '<div class="retur-confirm-row">Proses: <span class="retur-confirm-rusak">RUSAK</span></div>' : '';
        var msgHtml = '<div class="eligibility-wrap">' +
          '<div class="eligibility-summary">' +
            '<div class="eligibility-voucher">Voucher: ' + escapeHtml(voucher) + '</div>' +
            '<div class="eligibility-reason">Alasan: ' + escapeHtml(reason || '-') + '</div>' +
            '<div class="retur-confirm-row">Jenis: <span class="retur-confirm-type ' + typeClass + '">' + escapeHtml(typeLabel) + '</span></div>' +
            rusakHtml +
          '</div>' +
          '<div class="eligibility-list">' + criteriaHtml + '</div>' +
        '</div>';

        var approveLabel = isRefund ? 'Setujui Refund' : 'Setujui Retur';
        var buttons = ok ? [
          { label: 'Batalkan', className: 'm-btn m-btn-cancel' },
          { label: approveLabel, className: 'm-btn m-btn-success', close: false, onClick: function(){
              runReturApproveFlow(id, voucher, reason, typeRaw);
            }
          }
        ] : [
          { label: 'Tutup', className: 'm-btn m-btn-cancel' }
        ];

        window.MikhmonPopup.open({
          title: ok ? (isRefund ? 'Layak Refund' : 'Layak Retur') : (isRefund ? 'Belum Layak Refund' : 'Belum Layak Retur'),
          iconClass: ok ? 'fa fa-check-circle' : 'fa fa-times-circle',
          statusIcon: ok ? 'fa fa-check-circle' : 'fa fa-times-circle',
          statusColor: ok ? '#22c55e' : '#ef4444',
          messageHtml: msgHtml,
          alert: ok ? null : { type: 'danger', text: (data && data.message) ? data.message : 'Voucher belum memenuhi syarat rusak.' },
          buttons: buttons
        });
      })
      .catch(function(){
        window.MikhmonPopup.open({
          title: 'Gagal Mengecek',
          iconClass: 'fa fa-times-circle',
          statusIcon: 'fa fa-times-circle',
          statusColor: '#ef4444',
          message: 'Gagal mengecek kelayakan rusak. Coba lagi.',
          buttons: [
            { label: 'Tutup', className: 'm-btn m-btn-cancel' }
          ]
        });
      });
  }

  function runReturApproveFlow(id, voucher, reason, requestType) {
    var isRefund = String(requestType || '').toLowerCase() === 'pengembalian';
    if (!isRefund) {
      runReturAction('retur_request_approve', id, '');
      return;
    }

    var rusakUrl = buildRusakUrl(voucher, 'Retur Request: ' + (reason || '-'));
    window.MikhmonPopup.open({
      title: 'Menandai Rusak',
      iconClass: 'fa fa-circle-o-notch fa-spin',
      statusIcon: 'fa fa-circle-o-notch fa-spin',
      statusColor: '#2f81f7',
      message: 'Menandai voucher sebagai RUSAK...',
      buttons: [
        { label: 'Tunggu...', className: 'm-btn m-btn-cancel', close: false }
      ]
    });
    fetch(rusakUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function(resp){ return resp.json(); })
      .then(function(data){
        if (data && data.ok) {
          runReturAction('retur_request_mark_rusak', id, '');
        } else {
          var msg = (data && data.message) ? data.message : 'Gagal set RUSAK.';
          window.MikhmonPopup.open({
            title: 'Gagal',
            iconClass: 'fa fa-times-circle',
            statusIcon: 'fa fa-times-circle',
            statusColor: '#ef4444',
            message: msg,
            buttons: [
              { label: 'Tutup', className: 'm-btn m-btn-cancel' }
            ]
          });
        }
      })
      .catch(function(){
        window.MikhmonPopup.open({
          title: 'Gagal',
          iconClass: 'fa fa-times-circle',
          statusIcon: 'fa fa-times-circle',
          statusColor: '#ef4444',
          message: 'Gagal set RUSAK karena koneksi.',
          buttons: [
            { label: 'Tutup', className: 'm-btn m-btn-cancel' }
          ]
        });
      });
  }

  function showReturConfirm(action, id) {
    var item = findReturItem(id) || {};
    var voucher = item.voucher_code || '-';
    var reason = item.reason || '-';
    var typeRaw = String(item.request_type || '').toLowerCase();
    var isRefund = typeRaw === 'pengembalian';
    var typeLabel = isRefund ? 'Refund' : 'Retur';
    var typeClass = isRefund ? 'retur-confirm-refund' : 'retur-confirm-retur';
    var title = action === 'retur_request_approve' ? 'Setujui Retur' : 'Tolak Retur';
    var icon = action === 'retur_request_approve' ? 'fa fa-check-circle' : 'fa fa-times-circle';
    var color = action === 'retur_request_approve' ? '#238636' : '#da3633';
    var noteId = 'retur-note-' + String(id || '0');
    var noteHtml = '';

    if (action === 'retur_request_reject') {
      noteHtml = '<div style="margin-top:10px;">' +
        '<div style="font-size:12px; color:#b8c7ce; margin-bottom:6px;">Alasan penolakan</div>' +
        '<textarea id="' + noteId + '" style="width:100%; min-height:70px; padding:8px 10px; border-radius:6px; border:1px solid #3b4248; background:#1f2428; color:#e6edf3; resize:vertical;" placeholder="Contoh: voucher sudah dipakai"></textarea>' +
      '</div>';
    }

    var msgHtml = '<div style="font-size:13px; color:#e6edf3; line-height:1.6;">' +
      '<div><strong>Voucher:</strong> ' + escapeHtml(voucher) + '</div>' +
      '<div><strong>Jenis:</strong> <span class="retur-confirm-type ' + typeClass + '">' + escapeHtml(typeLabel) + '</span></div>' +
      '<div><strong>Alasan:</strong> ' + escapeHtml(reason) + '</div>' +
      noteHtml +
      '</div>';

    window.MikhmonPopup.open({
      title: title,
      iconClass: icon,
      statusIcon: icon,
      statusColor: color,
      messageHtml: msgHtml,
      buttons: [
        { label: 'Batalkan', className: 'm-btn m-btn-cancel' },
        { label: action === 'retur_request_approve' ? 'Setujui' : 'Tolak', className: action === 'retur_request_approve' ? 'm-btn m-btn-success' : 'm-btn m-btn-danger', close: false, onClick: function(){
            var note = '';
            if (action === 'retur_request_reject') {
              var noteEl = document.getElementById(noteId);
              note = noteEl ? noteEl.value.trim() : '';
            }
            runReturAction(action, id, note);
          }
        }
      ]
    });
  }

  function runReturAction(action, id, note) {
    var url = buildReturActionUrl(action, id, note || '');
    window.MikhmonPopup.open({
      title: 'Memproses',
      iconClass: 'fa fa-circle-o-notch fa-spin',
      statusIcon: 'fa fa-circle-o-notch fa-spin',
      statusColor: '#2f81f7',
      message: 'Sedang memproses permintaan. Mohon tunggu...',
      buttons: [
        { label: 'Tunggu...', className: 'm-btn m-btn-cancel', close: false }
      ]
    });

    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function(resp){ return resp.json(); })
      .then(function(data){
        if (data && data.ok) {
          var newStatus = (action === 'retur_request_approve' || action === 'retur_request_mark_rusak') ? 'approved' : 'rejected';
          updateReturItemStatus(id, newStatus);
          window.__returLastMessage = { type: 'success', text: data.message || 'Berhasil diproses.' };
          window.openReturMenuPopup();
        } else {
          window.__returLastMessage = { type: 'danger', text: (data && data.message) ? data.message : 'Gagal diproses.' };
          window.openReturMenuPopup();
        }
      })
      .catch(function(){
        window.__returLastMessage = { type: 'danger', text: 'Gagal memproses permintaan.' };
        window.openReturMenuPopup();
      });
  }

  function buildReturPrintList(items, titleText) {
    var rows = (items || []).map(function(it, idx) {
      var typeRaw = String(it.request_type || '').toLowerCase();
      var typeLabel = (typeRaw === 'pengembalian') ? 'Refund' : 'Retur';
      var st = normalizeReturStatus(it.status);
      var statusLabel = st === 'approved' ? 'APPROVED' : st === 'rejected' ? 'REJECTED' : 'PENDING';
      var blok = formatBlokLabel(it.blok_name || it.blok_guess || '-');
      return '' +
        '<li class="print-item">' +
          '<div class="print-head">#' + (idx + 1) + ' - ' + escapeHtml(formatReturDate(it)) + ' ' + escapeHtml(formatReturTime(it)) + '</div>' +
          '<div class="print-line"><strong>Voucher:</strong> ' + escapeHtml(it.voucher_code || '-') + '</div>' +
          '<div class="print-line"><strong>Blok:</strong> ' + escapeHtml(blok) + '</div>' +
          '<div class="print-line"><strong>Profil:</strong> ' + escapeHtml(it.profile_name || '-') + '</div>' +
          '<div class="print-line"><strong>Jenis:</strong> ' + escapeHtml(typeLabel) + '</div>' +
          '<div class="print-line"><strong>Nama:</strong> ' + escapeHtml(it.customer_name || '-') + '</div>' +
          '<div class="print-line"><strong>Alasan:</strong> ' + escapeHtml(it.reason || '-') + '</div>' +
          '<div class="print-line"><strong>Status:</strong> ' + escapeHtml(statusLabel) + '</div>' +
        '</li>';
    }).join('');

    var emptyHtml = '<div class="print-empty">Tidak ada data untuk dicetak.</div>';
    return '' +
      '<!DOCTYPE html>' +
      '<html><head><meta charset="utf-8" />' +
      '<title>' + escapeHtml(titleText) + '</title>' +
      '<style>' +
        'body{font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#111;margin:20px;}' +
        'h2{margin:0 0 10px 0;font-size:16px;}' +
        '.print-meta{margin-bottom:12px;font-size:11px;color:#555;}' +
        '.print-list{list-style:none;padding:0;margin:0;}' +
        '.print-item{border:1px solid #ddd;border-radius:6px;padding:10px;margin-bottom:8px;}' +
        '.print-head{font-weight:700;margin-bottom:6px;}' +
        '.print-line{margin:2px 0;}' +
        '.print-empty{padding:12px;border:1px dashed #ccc;border-radius:6px;color:#666;}' +
        '@media print{body{margin:0;} .print-item{page-break-inside:avoid;}}' +
      '</style></head><body>' +
      '<h2>' + escapeHtml(titleText) + '</h2>' +
      '<div class="print-meta">Tanggal cetak: ' + escapeHtml(new Date().toLocaleString()) + '</div>' +
      (rows ? '<ul class="print-list">' + rows + '</ul>' : emptyHtml) +
      '</body></html>';
  }

  function openReturPrint(type) {
    var data = getReturData();
    var items = data.items || [];
    var list = items.filter(function(it) {
      var typeRaw = String(it.request_type || '').toLowerCase();
      if (type === 'refund') return typeRaw === 'pengembalian';
      if (type === 'retur') return typeRaw !== 'pengembalian';
      return true;
    });

    var titleText = type === 'refund' ? 'Daftar Refund' : type === 'retur' ? 'Daftar Retur' : 'Daftar Retur & Refund';
    var html = buildReturPrintList(list, titleText);
    var win = window.open('', '_blank');
    if (!win) {
      window.__returLastMessage = { type: 'danger', text: 'Popup diblokir. Izinkan pop-up untuk print.' };
      window.openReturMenuPopup();
      return;
    }
    win.document.open();
    win.document.write(html);
    win.document.close();
    win.focus();
    win.print();
  }

  var returHandlersBound = false;
  function bindReturHandlers() {
    if (returHandlersBound) return;
    returHandlersBound = true;
    document.addEventListener('click', function(e) {
      var target = e.target;
      while (target && target !== document) {
        if (target.getAttribute && target.getAttribute('data-retur-filter')) {
          e.preventDefault();
          e.stopPropagation();
          var filter = target.getAttribute('data-retur-filter') || 'pending';
          setReturFilter(filter);
          window.openReturMenuPopup();
          return;
        }
        if (target.getAttribute && target.getAttribute('data-retur-print')) {
          e.preventDefault();
          e.stopPropagation();
          var type = target.getAttribute('data-retur-print') || 'all';
          openReturPrint(type);
          return;
        }
        if (target.getAttribute && target.getAttribute('data-retur-action')) {
          e.preventDefault();
          e.stopPropagation();
          var action = target.getAttribute('data-retur-action');
          var id = target.getAttribute('data-retur-id');
          if (action === 'retur_request_approve') {
            showReturEligibility(id);
          } else {
            showReturConfirm(action, id);
          }
          return;
        }
        target = target.parentNode;
      }
    });
  }

  window.openReturMenuPopup = function(e) {
    if (e && e.preventDefault) {
      e.preventDefault();
      e.stopPropagation();
    }

    if (!window.MikhmonPopup) {
      var session = getSession();
      window.location.href = './?hotspot=users&session=' + encodeURIComponent(session);
      return false;
    }

    bindReturHandlers();
    var data = getReturData();
    var items = data.items || [];
    var count = data.count;
    var session = getSession();
    var filter = getReturFilter();
    var counts = getReturCounts(items);
    var actionMsg = window.__returLastMessage || null;
    window.__returLastMessage = null;

    var filteredItems = items.filter(function(it) {
      var st = normalizeReturStatus(it.status);
      var typeRaw = String(it.request_type || '').toLowerCase();
      if (filter === 'all') return true;
      if (filter === 'refund') return typeRaw === 'pengembalian';
      if (filter === 'retur') return typeRaw !== 'pengembalian';
      return st === filter;
    });
    var showActionColumn = (filter === 'pending' || filter === 'refund');

    var rows = '';
    if (filteredItems.length) {
      rows = filteredItems.map(function(it) {
        var id = it.id || 0;
        var blok = formatBlokLabel(it.blok_name || it.blok_guess || '-');
        var st = normalizeReturStatus(it.status);
        var typeRaw = String(it.request_type || '').toLowerCase();
        var typeLabel = (typeRaw === 'pengembalian') ? 'Refund' : 'Retur';
        var typeClass = (typeRaw === 'pengembalian') ? 'retur-type-refund' : 'retur-type-retur';
        var custName = it.customer_name || '-';

        var actionHtml = st === 'pending' ?
          '  <a class="btn-act btn-act-approve" href="#" title="Setujui" data-retur-action="retur_request_approve" data-retur-id="' + escapeHtml(id) + '">' +
          '    <span class="btn-act-symbol">&#10003;</span>' +
          '  </a>' +
          '  <a class="btn-act btn-act-reject" href="#" title="Tolak" data-retur-action="retur_request_reject" data-retur-id="' + escapeHtml(id) + '">' +
          '    <span class="btn-act-symbol">&#10005;</span>' +
          '  </a>' :
          '  <span class="retur-action-dash">-</span>';

        return '<tr>' +
          '<td class="retur-col-date retur-col-center">' + escapeHtml(formatReturDate(it)) + '</td>' +
          '<td class="retur-col-time retur-col-center">' + escapeHtml(formatReturTime(it)) + '</td>' +
          '<td class="retur-col-voucher-cell retur-col-center"><span class="retur-col-voucher">' + escapeHtml(it.voucher_code || '-') + '</span></td>' +
          '<td class="retur-col-blok retur-col-center">' + escapeHtml(blok) + '</td>' +
          '<td class="retur-col-profile retur-col-center">' + escapeHtml(it.profile_name || '-') + '</td>' +
          '<td class="retur-col-type retur-col-center"><span class="retur-type ' + typeClass + '">' + escapeHtml(typeLabel) + '</span></td>' +
          '<td class="retur-col-name retur-col-center">' + escapeHtml(custName) + '</td>' +
          '<td class="retur-col-reason retur-col-left">' + escapeHtml(it.reason || '-') + '</td>' +
          '<td class="retur-col-status retur-col-center">' + renderStatusBadge(st) + '</td>' +
          (showActionColumn ? ('<td class="retur-col-action retur-col-center">' + actionHtml + '</td>') : '') +
          '</tr>';
      }).join('');
    }

    var tableHtml = filteredItems.length ?
      '<div class="retur-table-wrapper">' +
        '<table class="retur-table">' +
          '<thead>' +
            '<tr>' +
              '<th class="retur-col-date retur-col-center">Tanggal</th>' +
              '<th class="retur-col-time retur-col-center">Jam</th>' +
              '<th class="retur-col-voucher-cell retur-col-center">Voucher</th>' +
              '<th class="retur-col-blok retur-col-center">Blok</th>' +
              '<th class="retur-col-profile retur-col-left">Profil</th>' +
              '<th class="retur-col-type retur-col-center">Jenis</th>' +
              '<th class="retur-col-name retur-col-left">Nama</th>' +
              '<th class="retur-col-reason retur-col-left">Alasan</th>' +
              '<th class="retur-col-status retur-col-center">Status</th>' +
              (showActionColumn ? '<th class="retur-col-action retur-col-center">Aksi</th>' : '') +
            '</tr>' +
          '</thead>' +
          '<tbody>' + rows + '</tbody>' +
        '</table>' +
      '</div>' :
      '<div class="retur-empty">Tidak ada permintaan retur.</div>';

    var msgHtml = '';
    if (actionMsg && actionMsg.text) {
      var cls = actionMsg.type === 'danger' ? 'm-alert-danger' : 'm-alert-info';
      msgHtml = '<div class="m-alert ' + cls + '" style="margin-bottom:10px;"><div>' + escapeHtml(actionMsg.text) + '</div></div>';
    }
    var printHtml = '<div class="retur-print-actions">' +
      '<button type="button" class="retur-print-btn-inline" data-retur-print="refund">Print Refund</button>' +
      '<button type="button" class="retur-print-btn-inline" data-retur-print="retur">Print Retur</button>' +
      '<button type="button" class="retur-print-btn-inline" data-retur-print="all">Print Semua</button>' +
    '</div>';

    var infoHtml = '<div class="retur-info-bar">' +
      '<span>Permintaan Pending: <strong>' + count + '</strong></span>' +
      '<span class="retur-info-right">Realtime Update</span>' +
      '</div>' +
      '<div class="retur-header">' +
        '<div class="retur-tabs">' +
          '<button type="button" class="retur-tab ' + (filter === 'pending' ? 'is-active' : '') + '" data-retur-filter="pending">Pending (' + (counts.pending || 0) + ')</button>' +
          '<button type="button" class="retur-tab ' + (filter === 'approved' ? 'is-active' : '') + '" data-retur-filter="approved">Approved (' + (counts.approved || 0) + ')</button>' +
          '<button type="button" class="retur-tab ' + (filter === 'rejected' ? 'is-active' : '') + '" data-retur-filter="rejected">Rejected (' + (counts.rejected || 0) + ')</button>' +
          '<button type="button" class="retur-tab ' + (filter === 'retur' ? 'is-active' : '') + '" data-retur-filter="retur">Retur (' + (counts.retur || 0) + ')</button>' +
          '<button type="button" class="retur-tab ' + (filter === 'refund' ? 'is-active' : '') + '" data-retur-filter="refund">Refund (' + (counts.refund || 0) + ')</button>' +
          '<button type="button" class="retur-tab ' + (filter === 'all' ? 'is-active' : '') + '" data-retur-filter="all">Semua (' + (counts.all || 0) + ')</button>' +
        '</div>' +
        printHtml +
      '</div>' + msgHtml;

    window.MikhmonPopup.open({
      title: 'Manajemen Retur',
      iconClass: 'fa fa-undo',
      statusIcon: 'fa fa-inbox',
      statusColor: count > 0 ? '#f59e0b' : '#22c55e',
      messageHtml: '<div class="retur-popup-container">' + infoHtml + tableHtml + '</div>',
      buttons: [
        { label: 'Buka Halaman Pengguna', className: 'm-btn m-btn-primary', onClick: function(){ window.location.href = './?hotspot=users&session=' + encodeURIComponent(session); } },
        { label: 'Tutup', className: 'm-btn m-btn-cancel' }
      ],
      cardClass: 'is-retur'
    });

    return false;
  };

  window.updateDbStatus = function() {
    var el = document.getElementById('db-status');
    var restoreBtn = document.getElementById('db-restore');
    if (!el) return;
    var canRestoreFlag = (typeof window.__canRestoreFlag === 'undefined') ? !!window.__isSuperAdminFlag : !!window.__canRestoreFlag;
    fetch('./tools/db_check.php?key=' + encodeURIComponent(window.__backupKey || ''))
      .then(function(resp) {
        if (!resp.ok) throw new Error('bad');
        return resp.text();
      })
      .then(function(txt) {
        var low = txt ? txt.toLowerCase() : '';
        var ok = low.indexOf('db error') === -1 && low.indexOf('not found') === -1 && low.indexOf('forbidden') === -1;
        el.classList.remove('db-ok', 'db-error');
        el.classList.add(ok ? 'db-ok' : 'db-error');
        if (restoreBtn) {
          if (!canRestoreFlag) {
            restoreBtn.style.display = 'none';
          } else if (!window.__isSuperAdminFlag) {
            restoreBtn.style.display = ok ? 'inline-flex' : 'inline-flex';
          }
        }
      })
      .catch(function() {
        el.classList.remove('db-ok');
        el.classList.add('db-error');
        if (restoreBtn) {
          restoreBtn.style.display = canRestoreFlag ? 'inline-flex' : 'none';
        }
      });
  };

  window.updateBackupStatus = function() {
    var backupBtn = document.getElementById('db-backup');
    if (!backupBtn) return;
    fetch('./tools/backup_status.php?key=' + encodeURIComponent(window.__backupKey || ''))
      .then(function(resp) {
        if (!resp.ok) throw new Error('bad');
        return resp.json();
      })
      .then(function(data) {
        var validToday = data && data.valid_today === true;
        backupBtn.style.display = validToday ? 'none' : 'inline-flex';
      })
      .catch(function() {
        backupBtn.style.display = 'inline-flex';
      });
  };

  window.runBackupAjax = function() {
    var btn = document.getElementById('db-backup');
    if (!btn) return;

    var doBackup = function(){
      btn.style.pointerEvents = 'none';
      btn.style.opacity = '0.6';
      if (window.MikhmonPopup) {
        window.MikhmonPopup.open({
          title: 'Backup Database',
          iconClass: 'fa fa-database',
          statusIcon: 'fa fa-circle-o-notch fa-spin',
          statusColor: '#2f81f7',
          message: 'Proses backup sedang berjalan. Mohon tunggu...',
          buttons: [
            { label: 'Tunggu...', className: 'm-btn m-btn-cancel', close: false }
          ],
          cardClass: 'is-backup'
        });
      }
      fetch('./tools/backup_db.php?ajax=1&nolimit=1&key=' + encodeURIComponent(window.__backupKey || ''), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function(resp){ return resp.json(); })
      .then(function(data){
        btn.style.pointerEvents = '';
        btn.style.opacity = '';
        if (window.MikhmonPopup) {
          if (data && data.ok) {
            window.MikhmonPopup.open({
              title: 'Backup Sukses',
              iconClass: 'fa fa-check-circle',
              statusIcon: 'fa fa-cloud',
              statusColor: '#2f81f7',
              message: 'Backup selesai.',
              alert: { type: 'info', text: 'File: ' + (data.backup || '-') + ' | Cloud: ' + (data.cloud || '-') + ' | Hapus: ' + (data.deleted || 0) },
              buttons: [
                { label: 'Tutup', className: 'm-btn m-btn-primary' }
              ],
              cardClass: 'is-backup'
            });
            if (typeof window.updateBackupStatus === 'function') window.updateBackupStatus();
          } else {
            window.MikhmonPopup.open({
              title: 'Backup Gagal',
              iconClass: 'fa fa-times-circle',
              statusIcon: 'fa fa-times-circle',
              statusColor: '#da3633',
              message: 'Backup gagal.',
              alert: { type: 'danger', text: (data && data.message) ? data.message : 'Unknown' },
              buttons: [
                { label: 'Tutup', className: 'm-btn m-btn-cancel' }
              ],
              cardClass: 'is-backup'
            });
          }
        }
      })
      .catch(function(){
        btn.style.pointerEvents = '';
        btn.style.opacity = '';
        if (window.MikhmonPopup) {
          window.MikhmonPopup.open({
            title: 'Backup Gagal',
            iconClass: 'fa fa-times-circle',
            statusIcon: 'fa fa-times-circle',
            statusColor: '#da3633',
            message: 'Backup gagal.',
            alert: { type: 'danger', text: 'Tidak dapat menghubungi server.' },
            buttons: [
              { label: 'Tutup', className: 'm-btn m-btn-cancel' }
            ],
            cardClass: 'is-backup'
          });
        }
      });
    };

    if (window.MikhmonPopup) {
      window.MikhmonPopup.open({
        title: 'Backup Database',
        iconClass: 'fa fa-database',
        statusIcon: 'fa fa-cloud-download',
        statusColor: '#238636',
        message: 'Sistem akan mencadangkan seluruh data ke server utama.',
        alert: { type: 'info', text: 'Mencakup transaksi, log aktivitas, dan konfigurasi terbaru.' },
        buttons: [
          { label: 'Batalkan', className: 'm-btn m-btn-cancel' },
          { label: 'Jalankan Backup', className: 'm-btn m-btn-success', close: false, onClick: doBackup }
        ],
        cardClass: 'is-backup'
      });
      return;
    }
    if (confirm('Jalankan backup sekarang?')) doBackup();
  };

  window.runRestoreAjax = function() {
    var btn = document.getElementById('db-restore');
    if (!btn) return;

    var doRestore = function(){
      btn.style.pointerEvents = 'none';
      btn.style.opacity = '0.6';
      if (window.MikhmonPopup) {
        window.MikhmonPopup.open({
          title: 'Pemulihan Database',
          iconClass: 'fa fa-history',
          statusIcon: 'fa fa-circle-o-notch fa-spin',
          statusColor: '#2f81f7',
          message: 'Proses restore sedang berjalan. Mohon tunggu...',
          buttons: [
            { label: 'Tunggu...', className: 'm-btn m-btn-cancel', close: false }
          ],
          cardClass: 'is-restore'
        });
      }
      fetch('./tools/restore_db.php?ajax=1&nolimit=1&key=' + encodeURIComponent(window.__backupKey || ''), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function(resp){ return resp.json(); })
      .then(function(data){
        if (window.MikhmonPopup) {
          if (data && data.ok) {
            window.MikhmonPopup.open({
              title: 'Restore Sukses',
              iconClass: 'fa fa-check-circle',
              statusIcon: 'fa fa-refresh',
              statusColor: '#2f81f7',
              message: 'Restore selesai.',
              alert: { type: 'info', text: 'File: ' + (data.file || '-') + ' | Sumber: ' + (data.source || '-') },
              buttons: [
                { label: 'Tutup', className: 'm-btn m-btn-primary' }
              ],
              cardClass: 'is-restore'
            });
            if (typeof window.updateDbStatus === 'function') window.updateDbStatus();
          } else {
            window.MikhmonPopup.open({
              title: 'Restore Gagal',
              iconClass: 'fa fa-times-circle',
              statusIcon: 'fa fa-times-circle',
              statusColor: '#da3633',
              message: 'Restore gagal.',
              alert: { type: 'danger', text: (data && data.message) ? data.message : 'Unknown' },
              buttons: [
                { label: 'Tutup', className: 'm-btn m-btn-cancel' }
              ],
              cardClass: 'is-restore'
            });
          }
        }
      })
      .catch(function(){
        if (window.MikhmonPopup) {
          window.MikhmonPopup.open({
            title: 'Restore Gagal',
            iconClass: 'fa fa-times-circle',
            statusIcon: 'fa fa-times-circle',
            statusColor: '#da3633',
            message: 'Restore gagal karena koneksi.',
            buttons: [
              { label: 'Tutup', className: 'm-btn m-btn-cancel' }
            ],
            cardClass: 'is-restore'
          });
        }
      })
      .finally(function(){
        btn.style.pointerEvents = 'auto';
        btn.style.opacity = '1';
      });
    };

    if (window.MikhmonPopup) {
      window.MikhmonPopup.open({
        title: 'Pemulihan Database',
        iconClass: 'fa fa-history',
        statusIcon: 'fa fa-exclamation-triangle',
        statusColor: '#d29922',
        message: 'Database saat ini akan digantikan dengan backup terbaru.',
        alert: { type: 'warning', html: '<strong>Peringatan:</strong> Tindakan ini tidak dapat dibatalkan.' },
        buttons: [
          { label: 'Batalkan', className: 'm-btn m-btn-cancel' },
          { label: 'Mulai Restore', className: 'm-btn m-btn-primary', close: false, onClick: doRestore }
        ],
        cardClass: 'is-restore'
      });
      return;
    }
    if (confirm('Restore akan menimpa database. Lanjutkan?')) doRestore();
  };

  window.runAppBackupAjax = function() {
    var btn = document.getElementById('db-app-backup');
    if (!btn) return;

    var doBackup = function(){
      btn.style.pointerEvents = 'none';
      btn.style.opacity = '0.6';
      if (window.MikhmonPopup) {
        window.MikhmonPopup.open({
          title: 'Backup Konfigurasi',
          iconClass: 'fa fa-database',
          statusIcon: 'fa fa-circle-o-notch fa-spin',
          statusColor: '#2f81f7',
          message: 'Proses backup konfigurasi sedang berjalan. Mohon tunggu...'
        });
      }
      fetch('./tools/backup_app_db.php?ajax=1&nolimit=1&key=' + encodeURIComponent(window.__backupKey || ''), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function(resp){ return resp.json(); })
      .then(function(data){
        btn.style.pointerEvents = '';
        btn.style.opacity = '';
        if (window.MikhmonPopup) {
          if (data && data.ok) {
            window.MikhmonPopup.open({
              title: 'Backup Sukses',
              iconClass: 'fa fa-check-circle',
              statusIcon: 'fa fa-database',
              statusColor: '#2f81f7',
              message: 'Backup konfigurasi selesai.',
              alert: { type: 'info', text: 'File: ' + (data.backup || '-') + ' | Cloud: ' + (data.cloud || '-') + ' | Hapus: ' + (data.deleted || 0) },
              buttons: [
                { label: 'Tutup', className: 'm-btn m-btn-primary' }
              ]
            });
          } else {
            window.MikhmonPopup.open({
              title: 'Backup Gagal',
              iconClass: 'fa fa-times-circle',
              statusIcon: 'fa fa-times-circle',
              statusColor: '#da3633',
              message: 'Backup konfigurasi gagal.',
              alert: { type: 'danger', text: (data && data.message) ? data.message : 'Unknown' },
              buttons: [
                { label: 'Tutup', className: 'm-btn m-btn-cancel' }
              ]
            });
          }
        }
      })
      .catch(function(){
        btn.style.pointerEvents = '';
        btn.style.opacity = '';
        if (window.MikhmonPopup) {
          window.MikhmonPopup.open({
            title: 'Backup Gagal',
            iconClass: 'fa fa-times-circle',
            statusIcon: 'fa fa-times-circle',
            statusColor: '#da3633',
            message: 'Backup konfigurasi gagal.',
            alert: { type: 'danger', text: 'Tidak dapat menghubungi server.' },
            buttons: [
              { label: 'Tutup', className: 'm-btn m-btn-cancel' }
            ]
          });
        }
      });
    };

    if (window.MikhmonPopup) {
      window.MikhmonPopup.open({
        title: 'Backup Konfigurasi',
        iconClass: 'fa fa-database',
        statusIcon: 'fa fa-cloud-download',
        statusColor: '#238636',
        message: 'Sistem akan mencadangkan konfigurasi aplikasi.',
        alert: { type: 'info', text: 'Mencakup akun admin/operator dan sesi router.' },
        buttons: [
          { label: 'Batalkan', className: 'm-btn m-btn-cancel' },
          { label: 'Jalankan Backup', className: 'm-btn m-btn-success', close: false, onClick: doBackup }
        ]
      });
      return;
    }
    if (confirm('Jalankan backup konfigurasi sekarang?')) doBackup();
  };

  window.runAppRestoreAjax = function() {
    var btn = document.getElementById('db-app-restore');
    if (!btn) return;

    var doRestore = function(){
      btn.style.pointerEvents = 'none';
      btn.style.opacity = '0.6';
      if (window.MikhmonPopup) {
        window.MikhmonPopup.open({
          title: 'Restore Konfigurasi',
          iconClass: 'fa fa-history',
          statusIcon: 'fa fa-circle-o-notch fa-spin',
          statusColor: '#2f81f7',
          message: 'Proses restore konfigurasi sedang berjalan. Mohon tunggu...'
        });
      }
      fetch('./tools/restore_app_db.php?ajax=1&nolimit=1&key=' + encodeURIComponent(window.__backupKey || ''), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function(resp){
        return resp.text().then(function(text){
          var data = null;
          try { data = JSON.parse(text); } catch (e) { data = null; }
          return { ok: resp.ok, status: resp.status, data: data, raw: text };
        });
      })
      .then(function(result){
        var data = result.data || null;
        if (!result.ok && !data) {
          throw new Error('HTTP ' + result.status + (result.raw ? ' - ' + result.raw : ''));
        }
        if (window.MikhmonPopup) {
          if (data && data.ok) {
            window.MikhmonPopup.open({
              title: 'Restore Sukses',
              iconClass: 'fa fa-check-circle',
              statusIcon: 'fa fa-refresh',
              statusColor: '#2f81f7',
              message: 'Restore konfigurasi selesai.',
              alert: { type: 'info', text: 'File: ' + (data.file || '-') + ' | Sumber: ' + (data.source || '-') },
              buttons: [
                { label: 'Tutup', className: 'm-btn m-btn-primary' }
              ]
            });
          } else {
            window.MikhmonPopup.open({
              title: 'Restore Gagal',
              iconClass: 'fa fa-times-circle',
              statusIcon: 'fa fa-times-circle',
              statusColor: '#da3633',
              message: 'Restore konfigurasi gagal.',
              alert: { type: 'danger', text: (data && data.message) ? data.message : (result.raw || 'Unknown') },
              buttons: [
                { label: 'Tutup', className: 'm-btn m-btn-cancel' }
              ]
            });
          }
        }
      })
      .catch(function(){
        if (window.MikhmonPopup) {
          window.MikhmonPopup.open({
            title: 'Restore Gagal',
            iconClass: 'fa fa-times-circle',
            statusIcon: 'fa fa-times-circle',
            statusColor: '#da3633',
            message: 'Restore konfigurasi gagal.',
            alert: { type: 'danger', text: 'Tidak dapat memproses respons server.' },
            buttons: [
              { label: 'Tutup', className: 'm-btn m-btn-cancel' }
            ]
          });
        }
      })
      .finally(function(){
        btn.style.pointerEvents = 'auto';
        btn.style.opacity = '1';
      });
    };

    if (window.MikhmonPopup) {
      window.MikhmonPopup.open({
        title: 'Restore Konfigurasi',
        iconClass: 'fa fa-history',
        statusIcon: 'fa fa-exclamation-triangle',
        statusColor: '#d29922',
        message: 'Konfigurasi saat ini akan digantikan dengan backup terbaru.',
        alert: { type: 'warning', html: '<strong>Peringatan:</strong> Tindakan ini tidak dapat dibatalkan.' },
        buttons: [
          { label: 'Batalkan', className: 'm-btn m-btn-cancel' },
          { label: 'Mulai Restore', className: 'm-btn m-btn-primary', close: false, onClick: doRestore }
        ]
      });
      return;
    }
    if (confirm('Restore akan menimpa konfigurasi. Lanjutkan?')) doRestore();
  };

  window.showOverlayNotice = function(msg, type, lockClose){
    var overlay = document.getElementById('ajax-overlay');
    var container = document.getElementById('ajax-modal-container');
    var titleEl = document.getElementById('ajax-overlay-title');
    var textEl = document.getElementById('ajax-overlay-text');
    var icon = document.getElementById('ajax-overlay-icon');
    var btn = document.getElementById('ajax-overlay-close');

    if (!overlay || !container || !titleEl || !textEl || !icon || !btn) return;

    container.classList.remove('status-loading', 'status-success', 'status-error');
    var t = (type || 'info').toLowerCase();
    if (t === 'error') {
      container.classList.add('status-error');
      icon.className = 'fa fa-times';
      titleEl.textContent = 'Gagal!';
    } else if (t === 'success') {
      container.classList.add('status-success');
      icon.className = 'fa fa-check';
      titleEl.textContent = 'Berhasil!';
    } else {
      container.classList.add('status-loading');
      icon.className = 'fa fa-circle-o-notch fa-spin';
      titleEl.textContent = 'Memproses...';
    }

    textEl.textContent = msg || '';
    if (lockClose) {
      btn.style.display = 'none';
    } else {
      btn.style.display = 'inline-block';
      setTimeout(function(){ btn.focus(); }, 100);
    }

    overlay.style.display = 'flex';
    setTimeout(function(){
      overlay.classList.add('show');
    }, 10);
  };

  window.hideOverlayNotice = function(){
    var overlay = document.getElementById('ajax-overlay');
    if (overlay) {
      overlay.classList.remove('show');
      setTimeout(function(){
        overlay.style.display = 'none';
      }, 300);
    }
  };

  window.notifyLocal = function(msg, type, lockClose){
    window.showOverlayNotice(msg, type, !!lockClose);
  };
})();
