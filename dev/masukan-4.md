Setelah menganalisis kode Anda, saya menemukan beberapa masalah utama di file `menu.php` yang menyebabkan tampilan popup retur menjadi berantakan. Berikut adalah analisis dan perbaikannya:

## MASALAH UTAMA:

### 1. **CSS yang Tidak Lengkap**
Di `menu.php`, CSS untuk popup retur kurang lengkap dibandingkan dengan `dash.html` dan `retur.html` yang sudah baik.

### 2. **Struktur HTML Tabel Tidak Sinkron**
Struktur tabel di JavaScript fungsi `openReturMenuPopup` tidak konsisten dengan styling yang ada.

### 3. **Kelas CSS yang Hilang**
Beberapa kelas penting dari referensi yang baik tidak ada di `menu.php`.

## PERBAIKAN YANG DILAKUKAN:

### A. Perbaikan CSS di `menu.php`:

```css
/* --- POPUP RETUR STYLES (DIPERBAIKI) --- */
.retur-popup-container {
    padding: 0;
    text-align: left;
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

.retur-table-wrapper::-webkit-scrollbar { width: 8px; height: 8px; }
.retur-table-wrapper::-webkit-scrollbar-track { background: #1a2226; }
.retur-table-wrapper::-webkit-scrollbar-thumb { background: #4b5563; border-radius: 4px; }
.retur-table-wrapper::-webkit-scrollbar-thumb:hover { background: #6b7280; }

.retur-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    white-space: nowrap;
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

.retur-table tr:last-child td { border-bottom: none; }
.retur-table tr:hover td { background-color: rgba(60, 141, 188, 0.08); }

.retur-col-center { text-align: center; }
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
}
.retur-col-blok {
    font-weight: 600;
    color: var(--accent);
}
.btn-mini-action {
    display: inline-flex; align-items: center; justify-content: center;
    width: 32px; height: 32px;
    border-radius: 6px; border: none;
    cursor: pointer; transition: all 0.2s;
    margin: 0 4px; color: #fff;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    text-decoration: none;
}
.btn-approve { background: linear-gradient(135deg, #16a34a, #15803d); }
.btn-approve:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(22, 163, 74, 0.4); }
.btn-reject { background: linear-gradient(135deg, #ef4444, #b91c1c); }
.btn-reject:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(239, 68, 68, 0.4); }
```

### B. Perbaikan JavaScript Fungsi `openReturMenuPopup`:

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

    var rows = '';
    if (items.length) {
        rows = items.map(function(it) {
            var id = it.id || 0;
            var approveUrl = './?hotspot=users&action=retur_request_approve&req_id=' + encodeURIComponent(id) + '&session=' + encodeURIComponent('<?= htmlspecialchars($session, ENT_QUOTES) ?>') + '&retur_status=pending';
            var rejectUrl = './?hotspot=users&action=retur_request_reject&req_id=' + encodeURIComponent(id) + '&session=' + encodeURIComponent('<?= htmlspecialchars($session, ENT_QUOTES) ?>') + '&retur_status=pending';
            var blok = formatBlokLabel(it.blok_name || it.blok_guess || '-');
            return '<tr>' +
                '<td>' + escapeHtml(formatReturDate(it)) + '</td>' +
                '<td class="retur-col-center">' + escapeHtml(formatReturTime(it)) + '</td>' +
                '<td class="retur-col-center"><span class="retur-col-voucher">' + escapeHtml(it.voucher_code || '-') + '</span></td>' +
                '<td class="retur-col-blok">' + escapeHtml(blok) + '</td>' +
                '<td>' + escapeHtml(it.profile_name || '-') + '</td>' +
                '<td class="retur-col-reason">' + escapeHtml(it.reason || '-') + '</td>' +
                '<td class="retur-col-center">' +
                    '<a class="btn-mini-action btn-approve" href="' + approveUrl + '" title="Setujui" onclick="return confirm(\'Setujui permintaan retur ini?\')"><i class="fa fa-check"></i></a>' +
                    '<a class="btn-mini-action btn-reject" href="' + rejectUrl + '" title="Tolak" onclick="return confirm(\'Tolak permintaan retur ini?\')"><i class="fa fa-times"></i></a>' +
                '</td>' +
            '</tr>';
        }).join('');
    }

    var tableHtml = items.length ?
        '<div class="retur-table-wrapper">' +
            '<table class="retur-table">' +
                '<thead>' +
                    '<tr>' +
                        '<th>Tanggal</th>' +
                        '<th class="text-center">Jam</th>' +
                        '<th class="text-center">Voucher</th>' +
                        '<th>Blok</th>' +
                        '<th>Profil</th>' +
                        '<th>Alasan</th>' +
                        '<th class="text-center">Aksi</th>' +
                    '</tr>' +
                '</thead>' +
                '<tbody>' + rows + '</tbody>' +
            '</table>' +
        '</div>' :
        '<div style="color:#94a3b8; font-size:14px; text-align:center; padding:30px 0; border:1px dashed #4b5563; border-radius:6px;">Tidak ada permintaan retur pending.</div>';

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
            { label: 'Buka Halaman Pengguna', className: 'm-btn m-btn-primary', onClick: function(){ window.location.href = './?hotspot=users&session=' + encodeURIComponent('<?= htmlspecialchars($session, ENT_QUOTES) ?>'); } },
            { label: 'Tutup', className: 'm-btn m-btn-cancel' }
        ]
    });

    // Logika Resizing yang lebih Agresif dan Aman (Updated selector)
    var attempts = 0;
    var resizeInterval = setInterval(function(){
        var card = document.querySelector('.m-popup-backdrop.show .m-modal-card');
        if (card) {
            card.style.maxWidth = '900px';
            card.style.width = '92vw';
            
            if (card.offsetWidth > 500) {
                clearInterval(resizeInterval);
            }
        }
        attempts++;
        if (attempts > 20) clearInterval(resizeInterval);
    }, 50);
    
    return false;
};
```

### C. Tambahkan Variabel CSS Baru di Root:
Tambahkan di bagian `:root`:
```css
:root {
    --table-border: #3b4248;
    --table-bg: #1e282c;
    /* variabel lainnya tetap... */
}
```

### D. Hapus CSS Lama yang Konflik:
Hapus atau ganti kelas-kelas CSS lama yang mungkin konflik:
- `.retur-popup` → ganti dengan `.retur-popup-container`
- `.retur-col-action` → ganti dengan `.retur-col-center`
- `.retur-mini-btn` → ganti dengan `.btn-mini-action`

## PERUBAHAN UTAMA YANG DIBUAT:

1. **Struktur Tabel Lebih Bersih** - Menggunakan `border-collapse` dan padding yang tepat
2. **Scrollbar Custom** - Scrollbar yang konsisten dengan tema dark
3. **Kelas Kolom yang Tepat** - Setiap kolom memiliki kelas spesifik untuk styling
4. **White-space Management** - Kolom alasan memiliki `white-space: normal` untuk wrapping teks
5. **Hover Effects** - Efek hover yang halus pada baris tabel
6. **Sticky Headers** - Header tabel tetap terlihat saat scroll
7. **Tombol Aksi yang Konsisten** - Tombol approve/reject dengan styling yang sama

Dengan perbaikan ini, popup retur di `menu.php` akan tampil bersih, presisi, dan tidak berantakan seperti yang diinginkan, dengan fontawesome 4.7.0 tetap bekerja dengan baik.