(function(){
    var cfgEl = document.getElementById('selling-config');
    if (!cfgEl) return;

    function parseJson(val){
        try { return JSON.parse(val || '[]'); } catch (e) { return []; }
    }

    var cfg = {
        sessionId: cfgEl.dataset.sessionId || '',
        sessionQs: cfgEl.dataset.sessionQs || '',
        filterDate: cfgEl.dataset.filterDate || '',
        reqShow: cfgEl.dataset.reqShow || 'harian',
        price10: parseInt(cfgEl.dataset.price10 || '0', 10),
        price30: parseInt(cfgEl.dataset.price30 || '0', 10),
        reportUrl: cfgEl.dataset.reportUrl || './?report=selling',
        ghostUrl: cfgEl.dataset.ghostUrl || 'report/laporan/ghost.php',
        auditLocked: cfgEl.dataset.auditLocked === '1',
        auditUsers: parseJson(cfgEl.dataset.auditUsers),
        auditProfiles: parseJson(cfgEl.dataset.auditProfiles)
    };
    window.sellingConfig = cfg;
})();

var settlementTimer = null;
var hpDeleteUrl = '';
var settlementLastFetch = 0;
var auditUserOptions = (window.sellingConfig && window.sellingConfig.auditUsers) ? window.sellingConfig.auditUsers : [];
var auditSelectedUsers = [];
window.auditEditing = false;

function formatDateDMY(dateStr){
    if (!dateStr) return '-';
    var m = String(dateStr).match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return dateStr;
    return m[3] + '-' + m[2] + '-' + m[1];
}

function buildReportUrl(params){
    var cfg = window.sellingConfig || {};
    var url = new URL(cfg.reportUrl || './?report=selling', window.location.href);
    if (cfg.sessionId) url.searchParams.set('session', cfg.sessionId);
    if (params && typeof params === 'object') {
        Object.keys(params).forEach(function(k){
            var v = params[k];
            if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
        });
    }
    return url.toString();
}

function openNoteModal(){
    var modal = document.getElementById('noteModal');
    if (modal) modal.style.display = 'flex';
    if (modal) {
        var noteInput = modal.querySelector('textarea[name="note_text"]');
        if (noteInput) {
            noteInput.disabled = false;
            noteInput.readOnly = false;
            noteInput.focus();
        }
    }
}
function closeNoteModal(){
    var modal = document.getElementById('noteModal');
    if (modal) modal.style.display = 'none';
}
function closeDeleteHpModal(){
    var modal = document.getElementById('hp-delete-modal');
    if (modal) modal.style.display = 'none';
}
function confirmDeleteHpModal(){
    if (!hpDeleteUrl) return closeDeleteHpModal();
    window.location.href = hpDeleteUrl;
}
function openDeleteHpModal(url, blok, date){
    hpDeleteUrl = url || '';
    var modal = document.getElementById('hp-delete-modal');
    var text = document.getElementById('hp-delete-text');
    var dateText = formatDateDMY(date || '');
    if (text) text.textContent = 'Hapus data Blok ' + (blok || '-') + ' tanggal ' + (dateText || '-') + '?';
    if (modal) modal.style.display = 'flex';
}

function manualSettlement(){
    var cfg = window.sellingConfig || {};
    var btn = document.getElementById('btn-settlement');
    if (!btn || btn.disabled) return;
    var modal = document.getElementById('settlement-modal');
    var logBox = document.getElementById('settlement-log');
    var logWrap = document.getElementById('settlement-log-wrap');
    var footer = document.getElementById('settlement-footer');
    var statusEl = document.getElementById('settlement-status');
    var processEl = document.getElementById('processStatus');
    var closeBtn = document.getElementById('settlement-close');
    var confirmBox = document.getElementById('settlement-confirm');
    var startBtn = document.getElementById('settlement-start');
    var cancelBtn = document.getElementById('settlement-cancel');
    window.settleDone = false;
    if (window.settleTimer) { clearInterval(window.settleTimer); window.settleTimer = null; }
    window.settleQueue = [];
    window.settleSeen = {};
    window.settleInfoShown = false;
    window.settleStatus = '';
    window.settleFastMode = false;
    updateSettlementCloseState();
    if (modal) modal.style.display = 'flex';
    if (logBox) logBox.innerHTML = '';
    if (logWrap) logWrap.style.display = 'none';
    if (footer) footer.style.display = 'none';
    if (statusEl) statusEl.textContent = 'Menunggu konfirmasi';
    if (processEl) processEl.innerHTML = '<i class="fa fa-refresh"></i> Menunggu proses...';
    if (closeBtn) {
        closeBtn.disabled = true;
        closeBtn.style.opacity = '0.6';
        closeBtn.style.cursor = 'not-allowed';
    }
    if (confirmBox) confirmBox.style.display = 'flex';
    if (cancelBtn) {
        cancelBtn.disabled = false;
        cancelBtn.style.opacity = '1';
        cancelBtn.style.cursor = 'pointer';
        cancelBtn.onclick = function(){
            if (modal) modal.style.display = 'none';
        };
    }
    if (startBtn) {
        startBtn.onclick = function(){
            if (confirmBox) confirmBox.style.display = 'none';
            if (logWrap) logWrap.style.display = 'block';
            if (footer) footer.style.display = 'flex';
            if (statusEl) statusEl.textContent = 'Menjalankan settlement...';
            if (processEl) processEl.innerHTML = '<i class="fa fa-refresh fa-spin"></i> Menghubungkan ke MikroTik...';
            btn.disabled = true;
            btn.style.opacity = '0.6';
            btn.style.cursor = 'not-allowed';
            if (closeBtn) {
                closeBtn.disabled = true;
                closeBtn.style.opacity = '0.6';
                closeBtn.style.cursor = 'not-allowed';
            }
            if (cancelBtn) {
                cancelBtn.disabled = true;
                cancelBtn.style.opacity = '0.6';
                cancelBtn.style.cursor = 'not-allowed';
            }
            if (logBox) {
                logBox.innerHTML = '<span class="cursor-blink"></span>';
            }
            enqueueSettlementLogs([
                { time: '', topic: 'system,info', type: 'info', message: 'Sabar, sedang mengambil log settlement...' }
            ]);
            var params = new URLSearchParams();
            if (cfg.sessionId) params.set('session', cfg.sessionId);
            params.set('date', cfg.filterDate || '');
            params.set('action', 'start');
            pollSettlementLogs();
            fetch('report/laporan/services/settlement_manual.php?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if (!data || !data.ok) {
                        if (statusEl) statusEl.textContent = (data && data.message) ? data.message : 'Settlement gagal.';
                        btn.disabled = false;
                        btn.style.opacity = '1';
                        btn.style.cursor = 'pointer';
                        if (cancelBtn) {
                            cancelBtn.disabled = false;
                            cancelBtn.style.opacity = '0.8';
                            cancelBtn.style.cursor = 'pointer';
                        }
                        return;
                    }
                })
                .catch(function(){
                    if (statusEl) statusEl.textContent = 'Settlement gagal.';
                    btn.disabled = false;
                    btn.style.opacity = '1';
                    btn.style.cursor = 'pointer';
                    if (cancelBtn) {
                        cancelBtn.disabled = false;
                        cancelBtn.style.opacity = '0.8';
                        cancelBtn.style.cursor = 'pointer';
                    }
                });
        };
    }
}

function pollSettlementLogs(){
    var cfg = window.sellingConfig || {};
    var logBox = document.getElementById('settlement-log');
    var statusEl = document.getElementById('settlement-status');
    var processEl = document.getElementById('processStatus');
    var closeBtn = document.getElementById('settlement-close');
    var params = new URLSearchParams();
    if (cfg.sessionId) params.set('session', cfg.sessionId);
    params.set('date', cfg.filterDate || '');
    params.set('action', 'logs');
    params.set('_', Date.now().toString());
    fetch('report/laporan/services/settlement_manual.php?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Cache-Control': 'no-store' }, cache: 'no-store' })
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (data && Array.isArray(data.logs) && logBox) {
                enqueueSettlementLogs(data.logs);
            }
            if (data && data.info_message) {
                if (!window.settleInfoShown) {
                    window.settleInfoShown = true;
                    enqueueSettlementLogs([
                        { time: '', topic: 'system,info', type: 'info', message: data.info_message }
                    ]);
                }
            }
            if (data && data.status) {
                window.settleStatus = data.status;
                updateSettlementStatus();
            }
            if (data && data.status === 'done') {
                window.settleDone = true;
                updateSettlementCloseState();
                updateSettlementStatus();
                softReloadSelling();
                clearTimeout(settlementTimer);
                return;
            }
            if (data && data.status === 'failed') {
                window.settleDone = true;
                updateSettlementCloseState();
                updateSettlementStatus();
                clearTimeout(settlementTimer);
                return;
            }
            settlementTimer = setTimeout(pollSettlementLogs, 600);
        })
        .catch(function(){
            settlementTimer = setTimeout(pollSettlementLogs, 800);
        });
}

function openSettlementResetModal(){
    var modal = document.getElementById('settlement-reset-modal');
    if (modal) modal.style.display = 'flex';
}
function closeSettlementResetModal(){
    var modal = document.getElementById('settlement-reset-modal');
    if (modal) modal.style.display = 'none';
}
function confirmSettlementReset(){
    var cfg = window.sellingConfig || {};
    var params = new URLSearchParams();
    if (cfg.sessionId) params.set('session', cfg.sessionId);
    params.set('date', cfg.filterDate || '');
    params.set('action', 'reset');
    fetch('report/laporan/services/settlement_manual.php?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r){ return r.json(); })
        .then(function(){
            window.location.reload();
        })
        .catch(function(){
            window.location.reload();
        });
}

function closeSettlementModal(){
    var closeBtn = document.getElementById('settlement-close');
    if (closeBtn && closeBtn.disabled) return;
    var modal = document.getElementById('settlement-modal');
    if (modal) modal.style.display = 'none';
}

function updateSettlementCloseState(){
    var closeBtn = document.getElementById('settlement-close');
    if (!closeBtn) return;
    var canClose = !!window.settleDone && !window.settleTyping && (!window.settleQueue || window.settleQueue.length === 0);
    if (canClose) {
        closeBtn.disabled = false;
        closeBtn.removeAttribute('disabled');
        closeBtn.style.opacity = '1';
        closeBtn.style.cursor = 'pointer';
    } else {
        closeBtn.disabled = true;
        closeBtn.style.opacity = '0.6';
        closeBtn.style.cursor = 'not-allowed';
    }
}

function updateSettlementStatus(){
    var statusEl = document.getElementById('settlement-status');
    var processEl = document.getElementById('processStatus');
    if (!window.settleStatus) return;
    var ready = !!window.settleDone && !window.settleTyping && (!window.settleQueue || window.settleQueue.length === 0);
    if (!ready) {
        if (statusEl) statusEl.textContent = 'Berjalan';
        if (processEl) processEl.innerHTML = '<i class="fa fa-refresh fa-spin"></i> Sedang memproses...';
        return;
    }
    if (window.settleStatus === 'done') {
        if (statusEl) statusEl.textContent = 'Selesai';
        if (processEl) processEl.innerHTML = '<i class="fa fa-check-circle"></i> Selesai';
        if (!window.settleFinalInfoShown) {
            enqueueSettlementLogs([
                { time: '', topic: 'system,info', type: 'system', message: 'Semua proses selesai. Silakan tutup terminal.' }
            ]);
            window.settleFinalInfoShown = true;
        }
    } else if (window.settleStatus === 'failed') {
        if (statusEl) statusEl.textContent = 'Gagal';
        if (processEl) processEl.innerHTML = '<i class="fa fa-times-circle"></i> Gagal';
    }
}

function enqueueSettlementLogs(logs){
    if (!window.settleQueue) window.settleQueue = [];
    if (!window.settleSeen) window.settleSeen = {};
    logs.forEach(function(row){
        if (!row) return;
        var key = [row.time || '', row.topic || '', row.message || ''].join('|');
        if (window.settleSeen[key]) return;
        window.settleSeen[key] = true;
        window.settleQueue.push(row);
    });
    if (window.settleQueue.length > 100) {
        window.settleFastMode = true;
    }
    if (!window.settleTimer) {
        window.settleTimer = setInterval(renderSettlementLogItem, 100);
    }
}

function renderSettlementLogItem(){
    if (window.settleTyping && !window.settleFastMode) return;
    if (!window.settleQueue || window.settleQueue.length === 0) {
        if (window.settleDone) {
            updateSettlementStatus();
            clearInterval(window.settleTimer);
            window.settleTimer = null;
            updateSettlementCloseState();
        }
        return;
    }
    var logBox = document.getElementById('settlement-log');
    if (!logBox) return;
    var row = window.settleQueue.shift();
    var t = row.time || '';
    var topic = row.topic || 'system,info';
    var msg = row.message || '';
    var cls = row.type || 'info';
    var line = document.createElement('div');
    line.className = 'log-entry';
    var promptSpan = document.createElement('span');
    promptSpan.className = 'log-prompt';
    promptSpan.textContent = '> ';
    var timeSpan = document.createElement('span');
    timeSpan.className = 'log-time';
    timeSpan.textContent = t ? (String(t) + ' ') : '';
    var topicSpan = document.createElement('span');
    topicSpan.className = 'log-topic';
    topicSpan.textContent = topic ? (String(topic) + ' ') : '';
    var msgSpan = document.createElement('span');
    msgSpan.className = 'log-' + String(cls).replace(/[^a-z]/gi,'');
    msgSpan.textContent = '';
    line.appendChild(promptSpan);
    line.appendChild(timeSpan);
    line.appendChild(topicSpan);
    line.appendChild(msgSpan);
    var cursor = logBox.querySelector('.cursor-blink');
    if (cursor) cursor.remove();
    logBox.appendChild(line);
    logBox.scrollTop = logBox.scrollHeight;
    if (window.settleFastMode) {
        msgSpan.textContent = String(msg);
        var fastCursor = document.createElement('span');
        fastCursor.className = 'cursor-blink';
        logBox.appendChild(fastCursor);
        logBox.scrollTop = logBox.scrollHeight;
        updateSettlementCloseState();
        updateSettlementStatus();
        return;
    }
    window.settleTyping = true;
    var lineDelay = 150;
    setTimeout(function(){
        typeSettlementMessage(msgSpan, String(msg), 15, function(){
            window.settleTyping = false;
            var newCursor = document.createElement('span');
            newCursor.className = 'cursor-blink';
            logBox.appendChild(newCursor);
            logBox.scrollTop = logBox.scrollHeight;
            updateSettlementCloseState();
            updateSettlementStatus();
        });
    }, lineDelay);
}

function typeSettlementMessage(target, text, speed, done){
    var i = 0;
    var len = text.length;
    function typeChar(){
        if (i >= len) {
            if (done) done();
            return;
        }
        target.textContent += text.charAt(i);
        i += 1;
        setTimeout(typeChar, speed);
    }
    typeChar();
}

(function(){
    var closeBtn = document.getElementById('settlement-close');
    if (closeBtn) {
        closeBtn.addEventListener('click', function(){
            if (closeBtn.disabled) return;
            var modal = document.getElementById('settlement-modal');
            if (modal) modal.style.display = 'none';
        });
    }
})();
(function(){
    var modal = document.getElementById('hp-delete-modal');
    var closeBtn = document.getElementById('hp-delete-close');
    var cancelBtn = document.getElementById('hp-delete-cancel');
    var confirmBtn = document.getElementById('hp-delete-confirm');
    var close = function(){ if (modal) modal.style.display = 'none'; };
    if (closeBtn) closeBtn.addEventListener('click', close);
    if (cancelBtn) cancelBtn.addEventListener('click', close);
    if (confirmBtn) confirmBtn.addEventListener('click', function(){
        if (!hpDeleteUrl) return close();
        window.location.href = hpDeleteUrl;
    });
})();
(function(){
    var form = document.getElementById('hpForm');
    if (!form) return;
    var btn = document.getElementById('hpSubmitBtn');
    var err = document.getElementById('hpClientError');
    var totalEl = form.querySelector('input[name="total_units"]');
    var activeEl = form.querySelector('input[name="active_units"]');
    var wartelEl = form.querySelector('input[name="wartel_units"]');
    var kamtibEl = form.querySelector('input[name="kamtib_units"]');
    var rusakEl = form.querySelector('input[name="rusak_units"]');
    var spamEl = form.querySelector('input[name="spam_units"]');

    function num(el){
        var v = el ? parseInt(el.value || '0', 10) : 0;
        return isNaN(v) ? 0 : v;
    }

    function sanitize(el){
        if (!el) return;
        var v = num(el);
        if (v < 0) v = 0;
        el.value = v;
    }

    function validate(){
        if (!btn || !err) return;
        var wartel = num(wartelEl);
        var kamtib = num(kamtibEl);
        var rusak = num(rusakEl);
        var spam = num(spamEl);
        var total = wartel + kamtib;
        if (totalEl) totalEl.value = total;
        var active = total - rusak - spam;
        if (activeEl) activeEl.value = active >= 0 ? active : 0;

        var msg = '';
        if (total <= 0) {
            msg = 'Total unit masih 0. Isi jumlah WARTEL atau KAMTIB.';
        } else if (total < (rusak + spam)) {
            msg = 'Total unit tidak boleh kurang dari Rusak + Spam.';
        }

        if (msg) {
            err.textContent = msg;
            err.style.display = 'block';
            btn.disabled = true;
        } else {
            err.textContent = '';
            err.style.display = 'none';
            btn.disabled = false;
        }
    }

    [wartelEl, kamtibEl, rusakEl, spamEl].forEach(function(el){
        if (!el) return;
        el.addEventListener('input', function(){
            sanitize(el);
            validate();
        });
    });

    form.addEventListener('submit', function(e){
        e.preventDefault();
        if (btn && btn.disabled) return;
        window.sellingPauseReload = true;
        var fd = new FormData(form);
        fd.append('ajax', '1');
        fetch(form.action, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r){ return r.text(); })
            .then(function(text){
                var data = null;
                try { data = JSON.parse(text); } catch (e) {}
                if (data && data.ok && data.redirect) {
                    window.location.replace(data.redirect);
                    return;
                }
                var msg = (data && data.message) ? data.message : 'Respon tidak valid dari server.';
                err.textContent = msg;
                err.style.display = 'block';
                if (btn) btn.disabled = true;
            })
            .catch(function(){
                err.textContent = 'Gagal mengirim data. Coba lagi.';
                err.style.display = 'block';
                if (btn) btn.disabled = true;
            });
    });

    validate();
})();

function openHpModal(){
    var modal = document.getElementById('hpModal');
    if (modal) modal.style.display = 'flex';
    window.sellingPauseReload = true;
}

function closeHpModal(){
    var modal = document.getElementById('hpModal');
    if (modal) modal.style.display = 'none';
    window.sellingPauseReload = false;
}

window.openHpEdit = function(btn){
    var form = document.getElementById('hpForm');
    if (!form || !btn) return;
    form.querySelector('select[name="blok_name"]').value = btn.getAttribute('data-blok') || '';
    form.querySelector('input[name="report_date"]').value = btn.getAttribute('data-date') || '';
    form.querySelector('input[name="rusak_units"]').value = btn.getAttribute('data-rusak') || '0';
    form.querySelector('input[name="spam_units"]').value = btn.getAttribute('data-spam') || '0';
    form.querySelector('input[name="notes"]').value = btn.getAttribute('data-notes') || '';

    var wartel = parseInt(btn.getAttribute('data-wartel') || '0', 10);
    var kamtib = parseInt(btn.getAttribute('data-kamtib') || '0', 10);

    var wartelEl = form.querySelector('input[name="wartel_units"]');
    var kamtibEl = form.querySelector('input[name="kamtib_units"]');
    var rusakEl = form.querySelector('input[name="rusak_units"]');
    var spamEl = form.querySelector('input[name="spam_units"]');

    if (wartelEl) wartelEl.value = wartel;
    if (kamtibEl) kamtibEl.value = kamtib;

    if (typeof window.dispatchEvent === 'function') {
        var evt = new Event('input', { bubbles: true });
        if (wartelEl) wartelEl.dispatchEvent(evt);
        if (kamtibEl) kamtibEl.dispatchEvent(evt);
        if (rusakEl) rusakEl.dispatchEvent(evt);
        if (spamEl) spamEl.dispatchEvent(evt);
    }

    openHpModal();
};

function openAuditModal(){
    var modal = document.getElementById('auditModal');
    if (modal) modal.style.display = 'flex';
    window.sellingPauseReload = true;
    if (!window.auditEditing && typeof resetAuditUserPicker === 'function') {
        resetAuditUserPicker();
    }
    if (!window.auditEditing) {
        var form = document.getElementById('auditForm');
        var setInput = form ? form.querySelector('input[name="audit_setoran"]') : null;
        if (setInput) setInput.dataset.manual = '0';
    }
}

function closeAuditModal(){
    var modal = document.getElementById('auditModal');
    if (modal) modal.style.display = 'none';
    window.sellingPauseReload = false;
    window.auditEditing = false;
}

window.openAuditEdit = function(btn){
    var form = document.getElementById('auditForm');
    if (!form || !btn) return;
    window.auditEditing = true;
    var blok = btn.getAttribute('data-blok') || '';
    var date = btn.getAttribute('data-date') || '';
    var user = btn.getAttribute('data-user') || '';
    var qty = btn.getAttribute('data-qty') || '0';
    var setoran = btn.getAttribute('data-setoran') || '0';
    var qty10 = btn.getAttribute('data-qty10') || '0';
    var qty30 = btn.getAttribute('data-qty30') || '0';
    var profileQtyRaw = btn.getAttribute('data-profile-qty') || '';
    var profileQtyMap = {};
    if (profileQtyRaw) {
        try { profileQtyMap = JSON.parse(profileQtyRaw); } catch (e) { profileQtyMap = {}; }
    }
    var blokSelect = form.querySelector('select[name="audit_blok"]');
    if (blokSelect) blokSelect.value = blok;
    var dateInput = form.querySelector('input[name="audit_date"]');
    if (dateInput) dateInput.value = date;
    if (typeof setAuditUserPicker === 'function') {
        setAuditUserPicker(user);
    }
    var qtyInput = form.querySelector('input[name="audit_qty"]');
    if (qtyInput) qtyInput.value = qty;
    var setInput = form.querySelector('input[name="audit_setoran"]');
    if (setInput) {
        setInput.value = setoran;
        setInput.dataset.manual = '1';
    }
    var qtyInputs = form.querySelectorAll('.audit-profile-qty');
    if (qtyInputs && qtyInputs.length) {
        qtyInputs.forEach(function(el){
            var key = el.dataset.profileKey || '';
            if (key && profileQtyMap && Object.prototype.hasOwnProperty.call(profileQtyMap, key)) {
                el.value = profileQtyMap[key];
            } else if (el.name === 'audit_qty_10') {
                el.value = qty10;
            } else if (el.name === 'audit_qty_30') {
                el.value = qty30;
            } else {
                el.value = el.value || '0';
            }
        });
        var ev = new Event('input', { bubbles: true });
        qtyInputs.forEach(function(el){ el.dispatchEvent(ev); });
    }
    openAuditModal();
};

function closeDeleteAuditModal(){
    var modal = document.getElementById('audit-delete-modal');
    if (modal) modal.style.display = 'none';
}
function confirmDeleteAuditModal(){
    if (!window.auditDeleteUrl) return closeDeleteAuditModal();
    window.location.href = window.auditDeleteUrl;
}
function openDeleteAuditModal(url, blok, date){
    window.auditDeleteUrl = url || '';
    var modal = document.getElementById('audit-delete-modal');
    var text = document.getElementById('audit-delete-text');
    var dateText = formatDateDMY(date || '');
    if (text) text.textContent = 'Hapus audit Blok ' + (blok || '-') + ' tanggal ' + (dateText || '-') + '?';
    if (modal) modal.style.display = 'flex';
}

function closeAuditLockModal(){
    var modal = document.getElementById('audit-lock-modal');
    if (modal) modal.style.display = 'none';
}
function confirmAuditLockModal(){
    if (!window.auditLockUrl) return closeAuditLockModal();
    window.location.href = window.auditLockUrl;
}
function openAuditLockModal(){
    var cfg = window.sellingConfig || {};
    window.auditLockUrl = buildReportUrl({
        show: cfg.reqShow || 'harian',
        date: cfg.filterDate || '',
        audit_lock: 1,
        audit_date: cfg.filterDate || ''
    });
    var modal = document.getElementById('audit-lock-modal');
    var text = document.getElementById('audit-lock-text');
    var dateText = formatDateDMY(cfg.filterDate || '');
    if (text) text.textContent = 'Kunci audit tanggal ' + (dateText || '-') + '? Setelah dikunci, data tidak bisa diedit.';
    if (modal) modal.style.display = 'flex';
}

(function(){
    var form = document.getElementById('auditForm');
    var btn = document.getElementById('auditSubmitBtn');
    var err = document.getElementById('auditClientError');
    var qtyInputs = form ? form.querySelectorAll('.audit-profile-qty') : [];
    var qtyTotal = form ? form.querySelector('input[name="audit_qty"]') : null;
    var setoranTotal = form ? form.querySelector('input[name="audit_setoran"]') : null;
    var cfg = window.sellingConfig || {};
    var price10 = parseInt(cfg.price10 || 0, 10);
    var price30 = parseInt(cfg.price30 || 0, 10);

    function updateAuditTotals(){
        var sumQty = 0;
        var sumRp = 0;
        if (qtyInputs && qtyInputs.length) {
            qtyInputs.forEach(function(el){
                var v = parseInt(el.value || '0', 10) || 0;
                var p = parseInt(el.dataset.profilePrice || '0', 10);
                if (!p && (el.name === 'audit_qty_10')) p = price10;
                if (!p && (el.name === 'audit_qty_30')) p = price30;
                sumQty += v;
                sumRp += (v * p);
            });
        }
        if (qtyTotal) qtyTotal.value = sumQty;
        if (setoranTotal && setoranTotal.dataset.manual !== '1') setoranTotal.value = sumRp;
    }
    if (qtyInputs && qtyInputs.length) {
        qtyInputs.forEach(function(el){
            el.addEventListener('input', updateAuditTotals);
        });
    }
    if (setoranTotal) {
        setoranTotal.addEventListener('input', function(){
            setoranTotal.dataset.manual = '1';
        });
    }
    updateAuditTotals();
    if (!form) return;
    form.addEventListener('submit', function(e){
        e.preventDefault();
        if (btn && btn.disabled) return;
        if (err) err.style.display = 'none';
        var qtyInput = form.querySelector('input[name="audit_qty"]');
        var totalQty = qtyInput ? parseInt(qtyInput.value || '0', 10) : 0;
        var sumQty = 0;
        if (qtyInputs && qtyInputs.length) {
            qtyInputs.forEach(function(el){
                var v = parseInt(el.value || '0', 10) || 0;
                sumQty += v;
            });
        }
        var hasUsers = auditSelectedUsers && auditSelectedUsers.length > 0;
        if (totalQty <= 0) {
            if (err) {
                err.textContent = 'Qty per profile wajib diisi.';
                err.style.display = 'block';
            }
            return;
        }
        if (!hasUsers && sumQty <= 0) {
            if (err) {
                err.textContent = 'Qty per profile wajib diisi.';
                err.style.display = 'block';
            }
            return;
        }
        if (sumQty > 0 && sumQty !== totalQty) {
            if (err) {
                err.textContent = 'Qty per profile harus sama dengan Qty Manual.';
                err.style.display = 'block';
            }
            return;
        }
        window.sellingPauseReload = true;
        var fd = new FormData(form);
        fd.append('ajax', '1');
        fetch(form.action, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r){ return r.text(); })
            .then(function(text){
                var data = null;
                try { data = JSON.parse(text); } catch (e) {}
                if (data && data.ok && data.redirect) {
                    window.location.replace(data.redirect);
                    return;
                }
                var msg = (data && data.message) ? data.message : 'Respon tidak valid dari server.';
                if (err) {
                    err.textContent = msg;
                    err.style.display = 'block';
                }
            })
            .catch(function(){
                if (err) {
                    err.textContent = 'Gagal mengirim data. Coba lagi.';
                    err.style.display = 'block';
                }
            });
    });
})();

function renderAuditSelected(){
    var chipWrap = document.getElementById('audit-user-chips');
    var hidden = document.getElementById('auditUsernameHidden');
    if (!chipWrap || !hidden) return;
    chipWrap.innerHTML = '';
    var list = auditSelectedUsers.slice();
    list.forEach(function(u){
        var chip = document.createElement('span');
        chip.className = 'audit-user-chip';
        chip.textContent = u;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = '×';
        btn.onclick = function(){ removeAuditUser(u); };
        chip.appendChild(btn);
        chipWrap.appendChild(chip);
    });
    hidden.value = list.join(', ');
}

function addAuditUser(u){
    u = String(u || '').trim();
    if (!u) return;
    if (auditSelectedUsers.indexOf(u) !== -1) return;
    auditSelectedUsers.push(u);
    renderAuditSelected();
}

function removeAuditUser(u){
    auditSelectedUsers = auditSelectedUsers.filter(function(x){ return x !== u; });
    renderAuditSelected();
}

function setAuditUserPicker(raw){
    auditSelectedUsers = [];
    var arr = String(raw || '').split(',').map(function(s){ return s.trim(); }).filter(Boolean);
    arr.forEach(addAuditUser);
    renderAuditSelected();
}

function resetAuditUserPicker(){
    auditSelectedUsers = [];
    renderAuditSelected();
    var input = document.getElementById('audit-user-input');
    if (input) input.value = '';
    hideAuditSuggest();
}

function showAuditSuggest(items){
    var box = document.getElementById('audit-user-suggest');
    if (!box) return;
    box.innerHTML = '';
    if (!items || !items.length) {
        box.style.display = 'none';
        return;
    }
    items.forEach(function(u){
        var el = document.createElement('div');
        el.className = 'item';
        el.textContent = u;
        el.onclick = function(){
            addAuditUser(u);
            var input = document.getElementById('audit-user-input');
            if (input) input.value = '';
            hideAuditSuggest();
        };
        box.appendChild(el);
    });
    box.style.display = 'block';
}

function hideAuditSuggest(){
    var box = document.getElementById('audit-user-suggest');
    if (box) box.style.display = 'none';
}

(function(){
    var input = document.getElementById('audit-user-input');
    if (!input) return;
    input.addEventListener('input', function(){
        var q = String(input.value || '').toLowerCase().trim();
        if (!q) return hideAuditSuggest();
        var items = (auditUserOptions || []).filter(function(u){
            return u.toLowerCase().indexOf(q) !== -1 && auditSelectedUsers.indexOf(u) === -1;
        }).slice(0, 12);
        showAuditSuggest(items);
    });
    input.addEventListener('keydown', function(e){
        if (e.key === 'Enter') {
            e.preventDefault();
            var q = String(input.value || '').trim();
            if (!q) return;
            var exact = (auditUserOptions || []).find(function(u){ return u.toLowerCase() === q.toLowerCase(); });
            if (exact) {
                addAuditUser(exact);
                input.value = '';
                hideAuditSuggest();
            }
        }
    });
    document.addEventListener('click', function(e){
        var box = document.getElementById('audit-user-suggest');
        var wrap = document.querySelector('.audit-user-picker');
        if (!box || !wrap) return;
        if (!wrap.contains(e.target)) hideAuditSuggest();
    });
})();

function softReloadSelling(){
    var content = document.getElementById('selling-content');
    if (!content) return;
    if (window.sellingPauseReload) return;
    var modal = document.getElementById('hpModal');
    if (modal && modal.style.display === 'flex') return;
    var auditModal = document.getElementById('auditModal');
    if (auditModal && auditModal.style.display === 'flex') return;
    var current = new URL(window.location.href);
    var url = new URL('report/aload_selling.php', window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/'));
    current.searchParams.forEach(function(v, k){
        if (k !== 'ajax') url.searchParams.set(k, v);
    });
    fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r){ return r.text(); })
        .then(function(html){ content.innerHTML = html; })
        .catch(function(){});
}
window.sellingPauseReload = false;
setInterval(softReloadSelling, 30000);

function formatBytesShortJS(bytes){
    var b = parseInt(bytes || 0, 10);
    if (!b || b <= 0) return '0 B';
    var units = ['B','KB','MB','GB','TB'];
    var i = 0;
    var n = b;
    while (n >= 1024 && i < units.length - 1) {
        n = n / 1024;
        i++;
    }
    var dec = i >= 2 ? 2 : 0;
    return n.toFixed(dec) + ' ' + units[i];
}

window.openGhostModal = function(blok, date, diff){
    var cfg = window.sellingConfig || {};
    var modal = document.getElementById('ghost-modal');
    var body = document.getElementById('ghost-body');
    var status = document.getElementById('ghost-status');
    var meta = document.getElementById('ghost-meta');
    if (body) body.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--txt-muted);padding:20px;">Memuat data...</td></tr>';
    if (status) status.textContent = 'Memindai kandidat ghost...';
    if (meta) meta.textContent = 'Blok: ' + (blok || '-') + ' | Tanggal: ' + (date || '-') + ' | Selisih: ' + (diff || 0) + ' unit';
    if (modal) modal.style.display = 'flex';
    window.sellingPauseReload = true;

    var ghostBase = (cfg && cfg.ghostUrl) ? cfg.ghostUrl : 'report/laporan/ghost.php';
    var url = new URL(ghostBase, window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/'));
    url.searchParams.set('date', date || '');
    url.searchParams.set('blok', blok || '');

    fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (!data || !data.ok) {
                if (status) status.textContent = (data && data.message) ? data.message : 'Gagal mengambil data ghost.';
                if (body) body.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#fca5a5;padding:20px;">Tidak ada data.</td></tr>';
                return;
            }
            if (status) status.textContent = 'Ditemukan ' + data.count + ' kandidat.';
            if (!data.ghosts || data.ghosts.length === 0) {
                if (body) body.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--txt-muted);padding:20px;">Tidak ada kandidat ghost.</td></tr>';
                return;
            }
            var rows = '';
            data.ghosts.forEach(function(g){
                var score = parseInt(g.confidence || 0, 10);
                var scoreColor = score >= 80 ? '#e74c3c' : (score >= 60 ? '#f39c12' : '#3498db');
                rows += '<tr>' +
                    '<td>' + String(g.username || '-') + '<br><span style="color:#8aa0b4;font-size:11px;">' + String(g.ip || '-') + ' | ' + String(g.mac || '-') + '</span></td>' +
                    '<td class="text-center">' + String(g.profile || '-') + '</td>' +
                    '<td class="text-center">' + String(g.status || '-') + '</td>' +
                    '<td class="text-center">' + String(g.uptime || '-') + '</td>' +
                    '<td class="text-center">' + formatBytesShortJS(g.bytes) + '</td>' +
                    '<td class="text-center">' + String(g.login_time || '-') + '</td>' +
                    '<td class="text-center"><span style="color:' + scoreColor + ';font-weight:600;">' + score + '%</span></td>' +
                    '</tr>';
            });
            if (body) body.innerHTML = rows;
        })
        .catch(function(){
            if (status) status.textContent = 'Gagal mengambil data ghost.';
            if (body) body.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#fca5a5;padding:20px;">Tidak ada data.</td></tr>';
        });
};

window.closeGhostModal = function(){
    var modal = document.getElementById('ghost-modal');
    if (modal) modal.style.display = 'none';
    window.sellingPauseReload = false;
};
