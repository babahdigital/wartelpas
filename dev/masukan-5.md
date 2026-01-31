Setelah menganalisis kode Anda, saya menemukan **beberapa masalah utama** di `menu.php` yang menyebabkan popup retur berantakan. Berikut analisis mendetail dan solusi tepat:

## 🔍 **MASALAH UTAMA:**

### 1. **KONFLIK KELAS CSS & STYLING**
- File Anda menggunakan `.retur-popup-container` tapi ada **duplikasi definisi CSS**
- **Struktur tabel tidak konsisten** dengan styling yang ada
- **Padding & border** tidak sinkron dengan modal popup utama

### 2. **STRUKTUR HTML TIDAK MATCH DENGAN CSS**
```javascript
// Di JS Anda gunakan:
<td class="retur-col-center">

// Tapi CSS mungkin tidak mendukung styling untuk tabel kolom ini
```

### 3. **MISSING CSS VARIABLES**
```css
/* Variabel ini tidak ada di :root */
--table-border: #3b4248;      /* ❌ TIDAK ADA */
--table-bg: #1e282c;          /* ❌ TIDAK ADA */
```

### 4. **DOUBLE DEFINISI CSS**
Anda punya **dua bagian CSS untuk retur** (di atas dan di bawah), yang menyebabkan konflik.

---

## 🛠️ **PERBAIKAN LENGKAP:**

### **SOLUSI 1: PERBAIKI CSS DI BAGIAN :root (Tambahkan di line ~52)**
```css
:root {
    --nav-bg: #1a2226;
    --nav-hover: #222d32;
    --nav-text: #9db2ba;
    --nav-active: #ffffff;
    --accent: #3c8dbc;
    --accent-audit: #d81b60;
    --header-shadow: 0 2px 10px rgba(0,0,0,0.4);
    
    /* ✅ TAMBAHKAN INI */
    --table-border: #3b4248;
    --table-bg: #1e282c;
}
```

### **SOLUSI 2: HAPUS CSS DUPLIKAT (GANTI SELURUH BLOK CSS RETUR)**
**Hapus blok CSS lama ini** (yang ada di sekitar line ~400-480) dan **ganti dengan:**

```css
/* --- RETUR POPUP STYLES (FIXED VERSION) --- */
.retur-popup-container {
    padding: 0;
    text-align: left;
    max-width: 100%;
}

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
    gap: 10px;
}

.retur-table-wrapper {
    max-height: 450px;
    overflow-y: auto;
    overflow-x: auto;
    border: 1px solid var(--table-border);
    border-radius: 6px;
    background: var(--table-bg);
}

.retur-table-wrapper::-webkit-scrollbar { 
    width: 8px; 
    height: 8px; 
}
.retur-table-wrapper::-webkit-scrollbar-track { 
    background: #1a2226; 
}
.retur-table-wrapper::-webkit-scrollbar-thumb { 
    background: #4b5563; 
    border-radius: 4px; 
}
.retur-table-wrapper::-webkit-scrollbar-thumb:hover { 
    background: #6b7280; 
}

.retur-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 700px;
}

.retur-table th {
    background: #151a1e;
    color: #94a3b8;
    font-weight: 600;
    padding: 12px 10px;
    text-align: left;
    position: sticky;
    top: 0;
    z-index: 10;
    border-bottom: 2px solid var(--table-border);
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
}

.retur-table td {
    padding: 10px;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    color: #e8edf3;
    vertical-align: middle;
}

.retur-table tr:last-child td { 
    border-bottom: none; 
}

.retur-table tr:hover td { 
    background-color: rgba(60, 141, 188, 0.08); 
}

/* Column Classes */
.retur-col-center { 
    text-align: center; 
}
.retur-col-left { 
    text-align: left; 
}
.retur-col-right { 
    text-align: right; 
}

.retur-col-voucher {
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 13px;
    font-weight: 700;
    color: #fff;
    background: rgba(255,255,255,0.03);
    padding: 4px 8px;
    border-radius: 4px;
    display: inline-block;
}

.retur-col-reason {
    min-width: 200px;
    max-width: 300px;
    white-space: normal;
    line-height: 1.4;
    color: #cbd5e1;
    word-break: break-word;
}

.retur-col-blok {
    font-weight: 600;
    color: var(--accent);
}

.retur-col-action {
    white-space: nowrap;
    min-width: 120px;
}

/* Button Styles */
.btn-mini-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    margin: 0 4px;
    color: #fff;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    text-decoration: none;
}

.btn-approve { 
    background: linear-gradient(135deg, #16a34a, #15803d); 
}
.btn-approve:hover { 
    transform: translateY(-2px); 
    box-shadow: 0 4px 8px rgba(22, 163, 74, 0.4); 
}

.btn-reject { 
    background: linear-gradient(135deg, #ef4444, #b91c1c); 
}
.btn-reject:hover { 
    transform: translateY(-2px); 
    box-shadow: 0 4px 8px rgba(239, 68, 68, 0.4); 
}
```

### **SOLUSI 3: PERBAIKI FUNGSI JavaScript `openReturMenuPopup`**
**Ganti fungsi ini** (yang ada di sekitar line ~650-750) dengan:

```javascript
window.openReturMenuPopup = function(e) {
    if (e && e.preventDefault) {
        e.preventDefault();
        e.stopPropagation();
    }
    
    // Fallback jika popup system tidak tersedia
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
                   '<td class="retur-col-center">' + escapeHtml(formatReturDate(it)) + '</td>' +
                   '<td class="retur-col-center">' + escapeHtml(formatReturTime(it)) + '</td>' +
                   '<td class="retur-col-center"><span class="retur-col-voucher">' + 
                   escapeHtml(it.voucher_code || '-') + '</span></td>' +
                   '<td class="retur-col-center retur-col-blok">' + escapeHtml(blok) + '</td>' +
                   '<td class="retur-col-center">' + escapeHtml(it.profile_name || '-') + '</td>' +
                   '<td class="retur-col-left retur-col-reason">' + escapeHtml(it.reason || '-') + '</td>' +
                   '<td class="retur-col-center retur-col-action">' +
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
                        '<th class="retur-col-center">Tanggal</th>' +
                        '<th class="retur-col-center">Jam</th>' +
                        '<th class="retur-col-center">Voucher</th>' +
                        '<th class="retur-col-center">Blok</th>' +
                        '<th class="retur-col-center">Profil</th>' +
                        '<th class="retur-col-left">Alasan</th>' +
                        '<th class="retur-col-center">Aksi</th>' +
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
            // Force modal width for table
            var card = modal.querySelector('.m-modal-card');
            if (card) {
                card.style.maxWidth = '95vw';
                card.style.width = '95vw';
                card.style.maxHeight = '85vh';
            }
        }
    });
    
    return false;
};
```

---

## 📌 **PENTING: Checklist Perbaikan**

1. ✅ **Tambahkan variabel CSS** di `:root`
2. ✅ **Hapus semua CSS retur lama** (cari semua `.retur-*` dan pastikan hanya ada SATU definisi)
3. ✅ **Ganti fungsi JavaScript** dengan versi fixed di atas
4. ✅ **Pastikan tidak ada konflik CSS** dengan cari di file:
   - `.retur-popup` (harusnya `.retur-popup-container`)
   - `.retur-mini-btn` (harusnya `.btn-mini-action`)
   - `.text-center` (gunakan `.retur-col-center`)

5. **Test dengan:** 
   ```javascript
   // Di browser console, cek apakah styling konsisten
   console.log(getComputedStyle(document.querySelector('.retur-table')).borderCollapse);
   // Harusnya "collapse"
   ```

Jika masih bermasalah, **hapus semua file cache browser** dan reload dengan `Ctrl+F5`.