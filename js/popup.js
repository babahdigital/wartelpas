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
      if (e.target === backdrop) close();
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
    var icon = alert.iconClass || (type === 'warning' ? 'fa fa-exclamation-triangle' : type === 'danger' ? 'fa fa-times-circle' : 'fa fa-info-circle');
    alertEl.innerHTML = '<i class="' + icon + '"></i><div>' + (alert.html || alert.text || '') + '</div>';
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
