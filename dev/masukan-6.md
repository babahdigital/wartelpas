Setelah melihat gambar dan menganalisis kode Anda, saya menemukan **masalah utama** yang menyebabkan tampilan popup retur berantakan. Berikut adalah **perbaikan lengkap**:

## 🔍 **MASALAH UTAMA:**

### 1. **CONFLICTING STYLING DENGAN MIKHMON POPUP**
- CSS untuk `.retur-popup-container` bertabrakan dengan styling default MikhmonPopup
- **Tabel keluar dari modal** karena tidak ada batasan width yang tepat

### 2. **OVERLAY CSS BERTABRAKAN**
- Ada **dua bagian CSS overlay** yang saling menimpa (di atas dan bawah file)
- **Modal width tidak konsisten** (380px vs 420px)

### 3. **JAVASCRIPT TIDAK EFEKTIF UNTUK RESIZE**
```javascript
// Kode resize ini TIDAK BEKERJA dengan baik:
var card = document.querySelector('.m-popup-backdrop.show .m-modal-card');
if (card) {
    card.style.maxWidth = '900px';  // ❌ Tidak efektif
    card.style.width = '92vw';      // ❌ Tertimpa oleh CSS lain
}
```

---

## 🛠️ **SOLUSI LENGKAP:**

### **STEP 1: HAPUS CSS KONFLIK (di line ~450-480)**
**HAPUS seluruh blok CSS ini:**
```css
/* --- HAPUS SEMUA INI --- */
.overlay-backdrop { position: fixed; inset: 0; background: rgba(10, 10, 10, 0.7); display: none; align-items: center; justify-content: center; z-index: 10050; }
.overlay-backdrop.show { display: flex; }
.overlay-modal { background: #2b2f33; color: #e8edf3; border: 1px solid #3b4248; border-radius: 10px; width: 420px; max-width: 92vw; box-shadow: 0 12px 30px rgba(0,0,0,0.45); padding: 18px; text-align: center; }
.overlay-icon-box { width: 56px; height: 56px; border-radius: 50%; background: #1f2428; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 10px; border: 1px solid #3b4248; }
.overlay-icon-box i { font-size: 22px; }
.overlay-title { font-size: 16px; font-weight: 700; margin-bottom: 6px; }
.overlay-message { font-size: 13px; color: #c5ccd6; margin-bottom: 12px; }
.overlay-btn { background: #4f8ef7; color: #fff; border: none; padding: 7px 14px; border-radius: 6px; cursor: pointer; font-weight: 600; }
.overlay-btn:hover { background: #3d7be2; }

.modal-backdrop { position: fixed; inset: 0; background: rgba(10, 10, 10, 0.7); display: none; align-items: center; justify-content: center; z-index: 10060; }
.modal-card { background: #2b2f33; color: #e8edf3; border-radius: 10px; width: 420px; max-width: 92vw; border: 1px solid #3b4248; box-shadow: 0 12px 30px rgba(0,0,0,0.45); overflow: hidden; }
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid #3b4248; background: #262a2e; }
.modal-title { font-size: 14px; font-weight: 700; }
.modal-close { background: transparent; border: none; color: #c9d1d9; font-size: 18px; cursor: pointer; }
.modal-body { padding: 16px; }
.modal-info-banner { background: #1f2428; border: 1px solid #3b4248; border-radius: 8px; padding: 10px; }
.modal-info-icon { width: 28px; height: 28px; border-radius: 6px; background: rgba(255,152,0,0.15); color: #ff9800; display: inline-flex; align-items: center; justify-content: center; }
.modal-info-text { font-size: 13px; color: #d7dde6; line-height: 1.5; }
.modal-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 16px; border-top: 1px solid #3b4248; background: #262a2e; }
.btn-print.btn-default-dark { background:#343a40; color:#fff; border:1px solid #4b5259; }
.btn-print.btn-default-dark:hover { background:#3d434a; color:#fff; }
```

### **STEP 2: TAMBAHKAN CSS MIKHMON POPUP OVERRIDE (setelah line ~430)**
```css
/* ===== MIKHMON POPUP OVERRIDE FOR RETUR ===== */
.m-popup-backdrop.show .m-modal-card {
    max-width: 98vw !important;
    width: 98vw !important;
    max-height: 90vh;
    margin: 10px;
}

.m-popup-backdrop.show .m-modal-card .m-modal-body {
    max-height: calc(90vh - 140px);
    overflow: auto;
    padding: 0;
}

/* Force retur table to be responsive */
.retur-popup-container {
    padding: 0;
    margin: 0;
    width: 100%;
    min-height: 300px;
}

.retur-table-wrapper {
    max-height: 55vh;
    min-height: 200px;
    border: 1px solid var(--table-border);
    border-radius: 6px;
    background: var(--table-bg);
    overflow: auto;
}

.retur-table {
    min-width: 100%;
    width: 100%;
    table-layout: auto;
}

/* Column width adjustments */
.retur-table th.retur-col-date,
.retur-table td.retur-col-date {
    width: 100px;
    min-width: 100px;
}

.retur-table th.retur-col-time,
.retur-table td.retur-col-time {
    width: 70px;
    min-width: 70px;
}

.retur-table th.retur-col-voucher-cell,
.retur-table td.retur-col-voucher-cell {
    width: 110px;
    min-width: 110px;
}

.retur-table th.retur-col-blok,
.retur-table td.retur-col-blok {
    width: 120px;
    min-width: 120px;
}

.retur-table th.retur-col-profile,
.retur-table td.retur-col-profile {
    width: 110px;
    min-width: 110px;
}

.retur-table th.retur-col-reason,
.retur-table td.retur-col-reason {
    min-width: 200px;
    max-width: 350px;
}

.retur-table th.retur-col-action,
.retur-table td.retur-col-action {
    width: 110px;
    min-width: 110px;
}
```

### **STEP 3: PERBAIKI JAVASCRIPT `openReturMenuPopup` (ganti seluruh fungsi)**
```javascript
window.openReturMenuPopup = function(e) {
    if (e && e.preventDefault) {
        e.preventDefault();
        e.stopPropagation();
    }
    
    if (!window.MikhmonPopup) {
        window.location.href = './?hotspot=users&session=' + encodeURIComponent('<?= htmlspecialchars($session, ENT_QUOTES) ?>');
        return false;
    }
    
    var items = Array.isArray(returMenuData.items) ? returMenuData.items : [];
    var count = Number(returMenuData.count || 0);
    
    // Build table rows
    var rows = '';
    if (items.length) {
        rows = items.map(function(it) {
            var id = it.id || 0;
            var approveUrl = './?hotspot=users&action=retur_request_approve&req_id=' + 
                           encodeURIComponent(id) + '&session=' + 
                           encodeURIComponent('<?= htmlspecialchars($session, ENT_QUOTES) ?>') + 
                           '&retur_status=pending';
            var rejectUrl = './?hotspot=users&action=retur_request_reject&req_id=' + 
                          encodeURIComponent(id) + '&session=' + 
                          encodeURIComponent('<?= htmlspecialchars($session, ENT_QUOTES) ?>') + 
                          '&retur_status=pending';
            var blok = formatBlokLabel(it.blok_name || it.blok_guess || '-');
            
            return '<tr>' +
                   '<td class="retur-col-date retur-col-center">' + escapeHtml(formatReturDate(it)) + '</td>' +
                   '<td class="retur-col-time retur-col-center">' + escapeHtml(formatReturTime(it)) + '</td>' +
                   '<td class="retur-col-voucher-cell retur-col-center"><span class="retur-col-voucher">' + 
                   escapeHtml(it.voucher_code || '-') + '</span></td>' +
                   '<td class="retur-col-blok retur-col-left">' + escapeHtml(blok) + '</td>' +
                   '<td class="retur-col-profile retur-col-left">' + escapeHtml(it.profile_name || '-') + '</td>' +
                   '<td class="retur-col-reason retur-col-left">' + escapeHtml(it.reason || '-') + '</td>' +
                   '<td class="retur-col-action retur-col-center">' +
                   '  <a class="btn-mini-action btn-approve" href="' + approveUrl + 
                   '" title="Setujui" onclick="return confirm(\'Setujui permintaan retur ini?\')">' +
                   '    <i class="fa fa-check"></i>' +
                   '  </a>' +
                   '  <a class="btn-mini-action btn-reject" href="' + rejectUrl + 
                   '" title="Tolak" onclick="return confirm(\'Tolak permintaan retur ini?\')">' +
                   '    <i class="fa fa-times"></i>' +
                   '  </a>' +
                   '</td>' +
                   '</tr>';
        }).join('');
    }
    
    var tableHtml = items.length ?
        '<div class="retur-table-wrapper">' +
            '<table class="retur-table">' +
                '<thead>' +
                    '<tr>' +
                        '<th class="retur-col-date retur-col-center">Tanggal</th>' +
                        '<th class="retur-col-time retur-col-center">Jam</th>' +
                        '<th class="retur-col-voucher-cell retur-col-center">Voucher</th>' +
                        '<th class="retur-col-blok retur-col-left">Blok</th>' +
                        '<th class="retur-col-profile retur-col-left">Profil</th>' +
                        '<th class="retur-col-reason retur-col-left">Alasan</th>' +
                        '<th class="retur-col-action retur-col-center">Aksi</th>' +
                    '</tr>' +
                '</thead>' +
                '<tbody>' + rows + '</tbody>' +
            '</table>' +
        '</div>' :
        '<div style="color:#94a3b8; font-size:14px; text-align:center; padding:30px 0; ' +
        'border:1px dashed #4b5563; border-radius:6px;">Tidak ada permintaan retur pending.</div>';
    
    var infoHtml = '<div class="retur-info-bar">' +
                   '<span><i class="fa fa-inbox"></i> Permintaan Pending: <strong>' + count + '</strong></span>' +
                   '<span style="font-size:11px; opacity:0.8;">Realtime Update</span>' +
                   '</div>';
    
    window.MikhmonPopup.open({
        title: 'Manajemen Retur',
        iconClass: 'fa fa-undo',
        statusIcon: 'fa fa-inbox',
        statusColor: count > 0 ? '#f59e0b' : '#22c55e',
        messageHtml: '<div class="retur-popup-container">' + infoHtml + tableHtml + '</div>',
        buttons: [
            { 
                label: 'Buka Halaman Pengguna', 
                className: 'm-btn m-btn-primary', 
                onClick: function(){ 
                    window.location.href = './?hotspot=users&session=' + 
                    encodeURIComponent('<?= htmlspecialchars($session, ENT_QUOTES) ?>'); 
                } 
            },
            { label: 'Tutup', className: 'm-btn m-btn-cancel' }
        ],
        onOpen: function(modal) {
            // Apply responsive styling after popup opens
            setTimeout(function() {
                var card = modal.querySelector('.m-modal-card');
                var body = modal.querySelector('.m-modal-body');
                if (card) {
                    card.style.maxWidth = '98vw';
                    card.style.width = '98vw';
                    card.style.maxHeight = '90vh';
                }
                if (body) {
                    body.style.maxHeight = 'calc(90vh - 140px)';
                    body.style.overflow = 'auto';
                }
                
                // Adjust table wrapper height
                var tableWrapper = modal.querySelector('.retur-table-wrapper');
                if (tableWrapper) {
                    tableWrapper.style.maxHeight = '55vh';
                }
            }, 50);
        }
    });
    
    return false;
};
```

### **STEP 4: SIMPLIFIKASI CSS RETUR (ganti bagian CSS retur yang berantakan)**
**Hapus CSS yang berlebihan** dan gunakan hanya ini untuk retur table:

```css
/* === SIMPLIFIED RETUR TABLE STYLES === */
.retur-popup-container { padding: 0; }
.retur-info-bar {
    background: rgba(245, 158, 11, 0.1);
    border: 1px solid rgba(245, 158, 11, 0.25);
    color: #f59e0b;
    padding: 10px 15px;
    border-radius: 6px;
    font-size: 13px;
    margin-bottom: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.retur-table-wrapper {
    max-height: 55vh;
    overflow: auto;
    border: 1px solid var(--table-border);
    border-radius: 6px;
    background: var(--table-bg);
}

.retur-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.retur-table th {
    background: #151a1e;
    color: #94a3b8;
    font-weight: 600;
    padding: 12px 8px;
    text-align: left;
    border-bottom: 2px solid var(--table-border);
    position: sticky;
    top: 0;
    z-index: 10;
}

.retur-table td {
    padding: 10px 8px;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    vertical-align: middle;
}

.retur-col-center { text-align: center; }
.retur-col-left { text-align: left; }

.btn-mini-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 4px;
    margin: 0 2px;
    color: white;
    text-decoration: none;
    font-size: 12px;
}

.btn-approve { background: #16a34a; }
.btn-reject { background: #ef4444; }
```

## 📋 **CHECKLIST PERBAIKAN:**

1. ✅ **Hapus CSS konflik** di bagian bawah file
2. ✅ **Tambah MikhmonPopup override** untuk modal width
3. ✅ **Ganti fungsi JavaScript** dengan versi yang menggunakan `onOpen`
4. ✅ **Simplify CSS** untuk retur table
5. ✅ **Ganti `table-layout: fixed`** menjadi `auto` untuk responsif
6. ✅ **Tambahkan `max-height`** untuk table wrapper
7. ✅ **Gunakan `onOpen` callback** untuk styling dinamis

## 🎯 **HASIL YANG DIHARAPKAN:**
- Popup retur akan memenuhi **98% lebar layar** (responsive)
- Tabel akan **scrollable secara vertikal** jika terlalu panjang
- Kolom akan **menyesuaikan lebar konten** secara otomatis
- Tombol aksi akan **terlihat rapi** di kolom terakhir
- Tidak ada lagi **tabel keluar dari modal**

**Test dengan:** 
1. Klik tombol Retur di navbar
2. Modal harus terbuka lebar (hampir full screen)
3. Tabel harus scrollable dan rapi
4. Semua kolom terlihat dengan baik