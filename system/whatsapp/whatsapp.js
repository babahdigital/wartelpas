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
        params.append('report', 'whatsapp');
        params.append('wa_action', 'validate');
        params.append('ajax', '1');
        params.append('target', target);
        params.append('type', type);
        var sessionParam = (new URLSearchParams(window.location.search)).get('session');
        if (sessionParam) params.append('session', sessionParam);

        fetch('./?' + params.toString(), { credentials: 'same-origin' })
            .then(function(resp){ return resp.json(); })
            .then(function(data){
                if (data && data.ok) {
                    setValidationState(true, data.message || 'Valid.');
                } else {
                    setValidationState(false, (data && data.message) ? data.message : 'Nomor tidak valid.');
                }
            })
            .catch(function(){
                setValidationState(false, 'Gagal validasi. Coba lagi.');
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
});