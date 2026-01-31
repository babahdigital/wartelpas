function openDeleteModal(id, label) {
    var modal = document.getElementById('waDeleteModal');
    var text = document.getElementById('waDeleteText');
    var input = document.getElementById('waDeleteId');

    if (text) text.textContent = 'Hapus penerima: ' + label + '?';
    if (input) input.value = id;
    if (modal) {
        modal.classList.add('show');
        modal.style.display = 'flex';
    }

    document.body.style.overflow = 'hidden';
}

function closeDeleteModal() {
    var modal = document.getElementById('waDeleteModal');
    if (modal) {
        modal.classList.remove('show');
        setTimeout(function() {
            modal.style.display = 'none';
        }, 300);
    }

    document.body.style.overflow = '';
}

document.addEventListener('DOMContentLoaded', function() {
    var modal = document.getElementById('waDeleteModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteModal();
            }
        });
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeDeleteModal();
        }
    });

    var targetInput = document.querySelector('input[name="wa_target"]');
    var typeSelect = document.querySelector('select[name="wa_type"]');
    var saveBtn = document.getElementById('waSaveBtn');
    var msgEl = document.getElementById('waValidateMsg');

    function setValidationState(ok, text) {
        if (!msgEl) return;
        msgEl.textContent = text || '';
        msgEl.classList.remove('is-ok', 'is-bad');
        if (text) {
            msgEl.classList.add(ok ? 'is-ok' : 'is-bad');
        }
        if (saveBtn) {
            saveBtn.disabled = !ok;
            saveBtn.classList.toggle('is-disabled', !ok);
        }
    }

    function checkWaTarget() {
        if (!targetInput || !typeSelect) return;
        var target = targetInput.value.trim();
        var type = typeSelect.value || 'number';
        if (target === '') {
            setValidationState(false, 'Target wajib diisi.');
            return;
        }
        setValidationState(false, 'Mengecek nomor WhatsApp...');
        var params = new URLSearchParams();
        params.append('wa_action', 'validate');
        params.append('target', target);
        params.append('type', type);

        fetch('./system/whatsapp/api.php?' + params.toString(), { credentials: 'same-origin' })
            .then(function(resp){
                if (!resp.ok) {
                    return resp.text().then(function(txt){
                        throw new Error('HTTP ' + resp.status + ' ' + (txt || ''));
                    });
                }
                return resp.json();
            })
            .then(function(data){
                if (data && data.ok) {
                    setValidationState(true, data.message || 'Valid.');
                } else {
                    setValidationState(false, (data && data.message) ? data.message : 'Nomor tidak valid.');
                }
            })
            .catch(function(err){
                var msg = (err && err.message) ? err.message : 'Gagal validasi. Coba lagi.';
                setValidationState(false, msg);
            });
    }

    if (targetInput) {
        targetInput.addEventListener('blur', checkWaTarget);
    }
    if (typeSelect) {
        typeSelect.addEventListener('change', function(){
            if (targetInput && targetInput.value.trim() !== '') checkWaTarget();
        });
    }

    var pdfForm = document.getElementById('waPdfForm');
    var pdfInput = document.getElementById('waPdfInput');
    var pdfButton = document.getElementById('waPdfButton');
    var uploadText = document.getElementById('waUploadText');
    var uploadBox = document.getElementById('waUploadBox');

    function updatePdfButtonState(){
        if (!pdfInput) return;
        var hasFile = pdfInput.files && pdfInput.files.length > 0;
        if (pdfButton) {
            pdfButton.innerHTML = hasFile ? '<i class="fa fa-upload"></i> Upload PDF' : '<i class="fa fa-upload"></i> Pilih PDF';
        }
        if (uploadText) {
            uploadText.textContent = hasFile ? (pdfInput.files[0].name || 'File siap diupload') : 'Belum ada file dipilih';
        }
        if (uploadBox) {
            uploadBox.classList.toggle('has-file', hasFile);
        }
    }

    if (pdfButton && pdfInput) {
        pdfButton.addEventListener('click', function(){
            var hasFile = pdfInput.files && pdfInput.files.length > 0;
            if (!hasFile) {
                pdfInput.click();
            } else if (pdfForm) {
                pdfForm.submit();
            }
        });
    }
    if (pdfInput) {
        pdfInput.addEventListener('change', function(){
            updatePdfButtonState();
        });
    }
    updatePdfButtonState();

    var reportBtn = document.getElementById('waReportSend');
    var reportDate = document.getElementById('waReportDate');
    var reportStatus = document.getElementById('waReportStatus');

    function setReportStatus(text, ok){
        if (!reportStatus) return;
        reportStatus.textContent = text || 'Status: -';
        reportStatus.classList.remove('is-ok', 'is-bad');
        if (ok === true) reportStatus.classList.add('is-ok');
        if (ok === false) reportStatus.classList.add('is-bad');
    }

    if (reportBtn && reportDate) {
        reportBtn.addEventListener('click', function(){
            var dateVal = reportDate.value || '';
            if (dateVal === '') {
                setReportStatus('Status: Tanggal belum dipilih', false);
                return;
            }
            var params = new URLSearchParams();
            var session = reportBtn.getAttribute('data-session') || '';
            if (session) params.set('session', session);
            params.set('date', dateVal);
            params.set('action', 'wa_report');
            setReportStatus('Status: Mengirim...', null);
            reportBtn.disabled = true;
            reportBtn.classList.add('is-disabled');

            fetch('report/laporan/services/settlement_manual.php?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r){ return r.json(); })
                .then(function(data){
                    reportBtn.disabled = false;
                    reportBtn.classList.remove('is-disabled');
                    if (!data || !data.ok) {
                        var msg = (data && data.message) ? data.message : 'Gagal mengirim.';
                        setReportStatus('Status: Gagal - ' + msg, false);
                        return;
                    }
                    setReportStatus('Status: Terkirim (' + (data.sent_at || '-') + ')', true);
                })
                .catch(function(){
                    reportBtn.disabled = false;
                    reportBtn.classList.remove('is-disabled');
                    setReportStatus('Status: Gagal mengirim.', false);
                });
        });
    }
});