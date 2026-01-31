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
});