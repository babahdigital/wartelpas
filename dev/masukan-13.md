Berikut adalah perbaikan **users.css** dan **users.js**.

**Analisa Perbaikan:**

1. **Masalah:** Tombol "numpuk" karena label teks panjang ("Hapus Total (Router + DB)") dipaksa dalam layout horizontal (sebelah-menyebelah).
2. **Solusi:** Mengubah layout tombol menjadi **Vertikal (Stack)** khusus untuk popup ini. Setiap tombol akan mengambil lebar penuh (full width), sehingga teks panjang mudah dibaca.
3. **Visual:** Menambahkan warna pembeda yang tegas:
* **Orange:** Hapus Router (Peringatan/Warning).
* **Merah:** Hapus Total (Bahaya/Danger).
* **Abu-abu:** Batal.


4. **UX:** Menambahkan ikon pada tombol agar fungsi lebih cepat dipahami.

Silakan update file berikut:

### 1. Update `users.css`

Tambahkan class baru di bagian paling bawah file untuk menangani layout vertikal dan variasi warna tombol.

```css
/* --- Tambahan untuk Popup agar tidak numpuk --- */

/* Modifier untuk container tombol agar vertikal */
.overlay-actions.layout-vertical {
  flex-direction: column;
  width: 100%;
  gap: 10px;
}

/* Tombol full width saat mode vertikal */
.overlay-actions.layout-vertical .overlay-btn {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 12px 16px;
  font-size: 14px;
  border: 1px solid rgba(255,255,255,0.1);
}

.overlay-actions.layout-vertical .overlay-btn i {
  margin-right: 8px;
  font-size: 16px;
}

/* Warna khusus Warning (Orange) */
.overlay-btn-warning {
  background: #d97706; /* Amber-600 */
  color: #fff;
}
.overlay-btn-warning:hover {
  background: #b45309; /* Amber-700 */
}

/* Penyesuaian teks deskripsi pada popup */
.popup-desc-list {
  padding-left: 20px;
  margin: 10px 0;
  font-size: 13px;
  color: #cbd5e1;
  line-height: 1.6;
}
.popup-desc-list li {
  margin-bottom: 6px;
}
.popup-note {
  background: rgba(245, 158, 11, 0.1);
  border-left: 3px solid #f59e0b;
  padding: 8px 12px;
  margin-top: 12px;
  font-size: 12px;
  color: #e2e8f0;
}

```

### 2. Update `users.js`

Saya memodifikasi fungsi `showOverlayChoice` agar mendukung opsi layout vertikal, dan memperbarui fungsi `openDeleteBlockPopup` untuk menggunakan layout tersebut dengan ikon yang jelas.

Ganti/Update bagian kode berikut di dalam file `users.js`:

```javascript
  // Cari fungsi showOverlayChoice dan update menjadi seperti ini:
  function showOverlayChoice(options) {
    return new Promise((resolve) => {
      if (!overlayBackdrop || !overlayContainer || !overlayTitle || !overlayText || !overlayIcon || !overlayActions) {
        resolve(null);
        return;
      }
      const opts = options || {};
      overlayTitle.textContent = opts.title || 'Konfirmasi';
      overlayText.innerHTML = opts.messageHtml || '';
      
      // Reset classes
      overlayContainer.classList.remove('status-loading', 'status-success', 'status-error');
      
      // Handle Icon & Type
      if (opts.type === 'danger') {
        overlayContainer.classList.add('status-error');
        overlayIcon.className = 'fa fa-exclamation-triangle';
      } else if (opts.type === 'warning') {
        // Support tipe warning (orange)
        overlayContainer.classList.remove('status-error'); 
        overlayIcon.className = 'fa fa-exclamation-circle';
        overlayIcon.style.color = '#f59e0b';
      } else {
        overlayContainer.classList.add('status-loading');
        overlayIcon.className = 'fa fa-question-circle';
        overlayIcon.style.removeProperty('color');
      }

      // Handle Layout Vertikal (Agar tombol tidak numpuk)
      if (opts.layout === 'vertical') {
        overlayActions.classList.add('layout-vertical');
      } else {
        overlayActions.classList.remove('layout-vertical');
      }

      overlayActions.innerHTML = '';
      const buttons = Array.isArray(opts.buttons) ? opts.buttons : [];
      
      const cleanup = (val) => {
        overlayBackdrop.classList.remove('show');
        setTimeout(() => {
          overlayBackdrop.style.display = 'none';
          // Reset layout saat close agar popup lain tidak terpengaruh
          overlayActions.classList.remove('layout-vertical'); 
        }, 250);
        overlayActions.innerHTML = '';
        resolve(val);
      };

      buttons.forEach((btn) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'overlay-btn' + (btn.className ? (' ' + btn.className) : '');
        // Support HTML label untuk ikon
        b.innerHTML = btn.label || 'OK'; 
        
        if (btn.disabled) {
          b.disabled = true;
          b.style.opacity = '0.6';
          b.style.cursor = 'not-allowed';
        }
        b.onclick = () => {
          if (btn.onClick) {
            btn.onClick();
          }
          if (btn.closeOnClick === false) return;
          cleanup(btn.value ?? null);
        };
        overlayActions.appendChild(b);
      });

      overlayBackdrop.style.display = 'flex';
      setTimeout(() => {
        overlayBackdrop.classList.add('show');
      }, 10);
      
      overlayBackdrop.onclick = (e) => {
        if (e.target === overlayBackdrop && !opts.lockClose) {
          cleanup('cancel');
        }
      };
    });
  }

  // Update fungsi openDeleteBlockPopup agar menggunakan layout baru
  window.openDeleteBlockPopup = async function(blok) {
    const blokLabel = (blok || '').toString().trim();
    if (!blokLabel) return;
    const isAdmin = !!window.isSuperAdmin;
    
    // HTML yang lebih rapi
    const firstMessage = `
      <div style="text-align:left;">
        <div style="font-weight:600; font-size:15px; margin-bottom:10px; color:#fff;">
          Blok Target: <span style="color:#f39c12">${blokLabel}</span>
        </div>
        <div style="font-size:13px; color:#b8c7ce; margin-bottom:10px;">
          Pilih metode penghapusan:
        </div>
        <ul class="popup-desc-list">
          <li><strong>Hapus Router Saja:</strong> User hilang di MikroTik agar tidak bisa login lagi. Data laporan (uang/history) AMAN.</li>
          <li><strong>Hapus Total:</strong> Hapus user di MikroTik DAN hapus semua jejak uang/history di database. <strong>Data hilang permanen.</strong></li>
        </ul>
        ${isAdmin ? '' : '<div class="popup-note"><i class="fa fa-lock"></i> Hapus Total hanya untuk Superadmin.</div>'}
      </div>`;

    const choice = await showOverlayChoice({
      title: 'Hapus Blok Voucher',
      messageHtml: firstMessage,
      type: 'warning',
      layout: 'vertical', // Mengaktifkan layout vertikal
      buttons: [
        { 
          label: '<i class="fa fa-server"></i> Hapus Router Saja (Aman)', 
          value: 'router', 
          className: 'overlay-btn-warning' // Warna Orange
        },
        { 
          label: '<i class="fa fa-trash"></i> Hapus Total (Router + DB)', 
          value: 'full', 
          className: 'overlay-btn-danger', // Warna Merah
          disabled: !isAdmin 
        },
        { 
          label: 'Batal', 
          value: 'cancel', 
          className: 'overlay-btn-muted' // Warna Abu-abu
        }
      ]
    });

    if (!choice || choice === 'cancel') return;

    if (choice === 'router') {
      const detail = `
        <div style="text-align:center;">
          <div style="font-size:16px; margin-bottom:10px;">Konfirmasi Akhir</div>
          <div style="color:#cbd5e1; margin-bottom:15px;">
            Hapus user di Router untuk <strong>${blokLabel}</strong>?<br>
            User online tidak akan terputus. Data penjualan tetap ada.
          </div>
        </div>`;
      
      const ok = await showOverlayChoice({
        title: 'Eksekusi Hapus Router',
        messageHtml: detail,
        type: 'warning',
        buttons: [
          { label: 'Batal', value: false, className: 'overlay-btn-muted' },
          { label: '<i class="fa fa-check"></i> Ya, Eksekusi', value: true, className: 'overlay-btn-warning' }
        ]
      });
      
      if (ok !== true) return;
      const url = './?hotspot=users&action=batch_delete&blok=' + encodeURIComponent(blokLabel) + '&session=' + encodeURIComponent(usersSession);
      actionRequest(url, null);
      return;
    }

    if (choice === 'full') {
      const detail = `
        <div style="text-align:center;">
          <div style="font-size:18px; color:#ef4444; margin-bottom:10px; font-weight:bold;">PERINGATAN KERAS!</div>
          <div style="color:#e2e8f0; margin-bottom:15px; line-height:1.5;">
            Anda akan menghapus <strong>${blokLabel}</strong> secara PERMANEN.<br><br>
            <span style="color:#fca5a5;">
            • Semua History Login Hilang<br>
            • Semua Data Penjualan Hilang<br>
            • User di Router Hilang
            </span>
          </div>
          <div style="font-size:12px; color:#cbd5e1;">Tindakan ini tidak bisa dibatalkan.</div>
        </div>`;
        
      const ok = await showOverlayChoice({
        title: 'Konfirmasi Hapus Total',
        messageHtml: detail,
        type: 'danger',
        buttons: [
          { label: 'Batal', value: false, className: 'overlay-btn-muted' },
          { label: '<i class="fa fa-bomb"></i> Ya, Hancurkan Data', value: true, className: 'overlay-btn-danger' }
        ]
      });
      
      if (ok !== true) return;
      const url = './?hotspot=users&action=delete_block_full&blok=' + encodeURIComponent(blokLabel) + '&session=' + encodeURIComponent(usersSession);
      actionRequest(url, null);
    }
  };

```