Berikut adalah analisa dan perbaikan untuk kedua popup tersebut (`Konfirmasi Hapus User` dan `Konfirmasi Rusak`).

### Analisa Masalah

1. **Popup Hapus User (`image_2430f3.png`):**
* **Tampilan terlalu polos:** Hanya ikon tanda tanya standar. Padahal "Hapus Total" adalah tindakan destruktif (menghapus data uang/history).
* **Tombol kurang tegas:** Tombol "Ya, Lanjutkan" berwarna biru (aman), seharusnya **Merah (Bahaya)** untuk memperingatkan admin.
* **Konteks:** Nama user (`zdrb2k`) menyatu dengan teks, kurang menonjol.


2. **Popup Rusak Checklist (`image_2430ae.png`):**
* **Visual Buttons Membingungkan:** Semua tombol berwarna Merah/Oranye. User bisa salah klik "Print" padahal ingin "Lanjutkan", atau sebaliknya.
* **Hirarki Aksi:** "Print" adalah opsi tambahan, bukan aksi utama. Seharusnya visualnya dibedakan.
* **Layout:** Tabel checklist bisa dibuat lebih modern agar terlihat seperti "Laporan Audit".



---

### Solusi: Penerapan "Rich UI" Konsisten

Saya akan menyempurnakan kode `users.js` dan `users.css` agar kedua popup ini menggunakan gaya **Rich Action Buttons** (Vertikal) yang sama dengan popup "Hapus Blok" sebelumnya, sehingga aplikasi terlihat sangat profesional dan konsisten.

### 1. Update `users.css`

Tambahkan style berikut di bagian paling bawah file (melanjutkan style sebelumnya):

```css
/* --- Tambahan untuk Tabel Checklist & Banner Status --- */

/* Style untuk tabel kriteria rusak agar lebih rapi */
.checklist-container {
  background: rgba(0, 0, 0, 0.2);
  border-radius: 6px;
  overflow: hidden;
  margin: 10px 0 15px 0;
  border: 1px solid rgba(255, 255, 255, 0.05);
}

.checklist-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.checklist-table th {
  text-align: left;
  background: rgba(255, 255, 255, 0.05);
  padding: 8px 12px;
  color: #9aa0a6;
  font-weight: 600;
  font-size: 11px;
  text-transform: uppercase;
}

.checklist-table td {
  padding: 8px 12px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.05);
  color: #e2e8f0;
}

.checklist-table tr:last-child td {
  border-bottom: none;
}

/* Banner status hasil pengecekan */
.status-banner {
  padding: 10px 12px;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 600;
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.status-banner.success {
  background: rgba(22, 163, 74, 0.15); /* Green tint */
  border: 1px solid #166534;
  color: #4ade80;
}

.status-banner.error {
  background: rgba(220, 38, 38, 0.15); /* Red tint */
  border: 1px solid #991b1b;
  color: #f87171;
}

/* Tombol Print (Biru/Secondary) */
.overlay-btn-info {
  background: #3b82f6; /* Blue-500 */
  color: white;
}
.overlay-btn-info:hover {
  background: #2563eb;
}

```

### 2. Update `users.js`

Ada dua bagian yang perlu diubah total: `showRusakChecklist` dan logika di dalam `actionRequest` untuk menangkap konfirmasi hapus user.

#### A. Ganti fungsi `showRusakChecklist` sepenuhnya:

```javascript
  function showRusakChecklist(data) {
    return new Promise((resolve) => {
      if (!overlayBackdrop || !overlayText) {
        resolve(false);
        return;
      }
      const criteria = data.criteria || {};
      const values = data.values || {};
      const limits = data.limits || {};
      const headerMsg = data.message || '';
      const meta = data.meta || {};
      const isValid = !!data.ok;

      // 1. Buat Banner Status
      const statusIcon = isValid ? 'fa-check-circle' : 'fa-times-circle';
      const statusClass = isValid ? 'success' : 'error';
      const statusText = isValid ? 'Syarat terpenuhi. User Layak diganti.' : 'Syarat TIDAK terpenuhi.';
      
      const bannerHtml = `
        <div class="status-banner ${statusClass}">
          <i class="fa ${statusIcon}" style="font-size:18px;"></i>
          <span>${statusText}</span>
        </div>`;

      // 2. Buat Tabel Kriteria
      const items = [
        { label: `Offline (Tidak aktif)`, ok: !!criteria.offline, value: values.online === 'Tidak' ? 'Offline' : 'Online' },
        { label: `Usage < ${limits.bytes || '-'}`, ok: !!criteria.bytes_ok, value: values.bytes || '-' },
        { label: `Uptime (Info)`, ok: true, value: values.total_uptime || '-' },
        { label: `History Login`, ok: !!criteria.first_login_ok, value: values.first_login !== '-' ? 'Ada' : 'Kosong' }
      ];

      const rows = items.map(it => {
        const icon = it.ok ? 'fa-check' : 'fa-times';
        const color = it.ok ? '#4ade80' : '#f87171'; // Green : Red
        return `
          <tr>
            <td><i class="fa ${icon}" style="color:${color}; width:16px;"></i> ${it.label}</td>
            <td style="text-align:right; font-family:monospace;">${it.value}</td>
          </tr>`;
      }).join('');

      const tableHtml = `
        <div class="checklist-container">
          <table class="checklist-table">
            <thead><tr><th>Kriteria</th><th style="text-align:right;">Nilai Aktual</th></tr></thead>
            <tbody>${rows}</tbody>
          </table>
        </div>`;

      // 3. Susun Pesan Utama
      const targetUser = (meta && meta.username) ? meta.username : 'Unknown';
      const messageHtml = `
        <div style="text-align:left;">
          <div style="font-size:14px; color:#cbd5e1; margin-bottom:4px;">Audit User:</div>
          <div style="font-size:18px; font-weight:bold; color:#fff; margin-bottom:12px;">${targetUser}</div>
          ${bannerHtml}
          ${tableHtml}
        </div>`;

      // 4. Siapkan URL Print
      const sess = (usersSession || '').toString();
      const printUrl = targetUser ? ('./hotspot/print/print.detail.php?session=' + encodeURIComponent(sess) + '&user=' + encodeURIComponent(targetUser)) : '';

      // 5. Tampilkan Popup dengan Rich Buttons
      showOverlayChoice({
        title: 'Verifikasi Kondisi Rusak',
        messageHtml,
        type: isValid ? 'warning' : 'danger', // Warning (Orange) jika valid, Danger (Merah) jika maksa
        layout: 'vertical',
        buttons: [
          {
            // Tombol Utama: Eksekusi
            label: `
              <i class="fa fa-gavel"></i>
              <div class="btn-rich-text">
                <span class="btn-rich-title">Tetapkan Status RUSAK</span>
                <span class="btn-rich-desc">User akan diblokir & laporan disesuaikan.</span>
              </div>`,
            value: true,
            className: 'overlay-btn-warning', // Orange
            disabled: !isValid
          },
          {
            // Tombol Sekunder: Print
            label: `
              <i class="fa fa-print"></i>
              <div class="btn-rich-text">
                <span class="btn-rich-title">Print Rincian</span>
                <span class="btn-rich-desc">Cetak bukti diagnosa sebelum eksekusi.</span>
              </div>`,
            className: 'overlay-btn-info', // Biru
            closeOnClick: false,
            onClick: () => {
              if (!printUrl) return;
              const w = window.open(printUrl, '_blank');
              if (!w) window.location.href = printUrl;
            }
          },
          {
            // Tombol Batal
            label: `
              <i class="fa fa-times"></i>
              <div class="btn-rich-text"><span class="btn-rich-title">Batal</span></div>`,
            value: false,
            className: 'overlay-btn-muted'
          }
        ]
      }).then((val) => resolve(val === true));
    });
  }

```

#### B. Update `actionRequest` untuk menangkap pesan "Hapus total..."

Daripada mengubah semua file PHP, kita bisa melakukan *intercept* pada pesan konfirmasi di JS. Jika pesannya mengandung kata kunci tertentu, kita tampilkan popup yang bagus.

Cari fungsi `window.actionRequest` dan ubah bagian awalnya menjadi seperti ini:

```javascript
  window.actionRequest = async function(url, confirmMsg) {
    // --- INTERCEPT LOGIC UNTUK POPUP BAGUS ---
    if (confirmMsg) {
      // Cek apakah ini request Hapus Total User (single)
      if (confirmMsg.toLowerCase().includes('hapus total user')) {
        // Ekstrak nama user dari pesan (misal: "Hapus total user zdrb2k (Router + DB)?")
        const match = confirmMsg.match(/user\s+([^\s]+)/i);
        const userName = match ? match[1] : 'Target';

        const detailMsg = `
          <div style="text-align:left;">
             <div style="margin-bottom:10px; color:#cbd5e1;">Anda akan menghapus user:</div>
             <div style="font-size:20px; font-weight:bold; color:#fff; margin-bottom:15px; border-left:4px solid #ef4444; padding-left:12px;">
               ${userName}
             </div>
             <div style="background:rgba(239, 68, 68, 0.1); border:1px solid rgba(239, 68, 68, 0.3); padding:10px; border-radius:6px; font-size:13px; color:#fca5a5;">
               <i class="fa fa-exclamation-triangle"></i> <strong>Peringatan:</strong><br>
               Tindakan ini akan menghapus user dari MikroTik DAN menghapus seluruh riwayat keuangan/login di database. Data tidak bisa dikembalikan.
             </div>
          </div>
        `;

        const ok = await showOverlayChoice({
          title: 'Hapus User Permanen',
          messageHtml: detailMsg,
          type: 'danger',
          layout: 'vertical',
          buttons: [
            {
              label: `
                <i class="fa fa-trash"></i>
                <div class="btn-rich-text">
                  <span class="btn-rich-title">Ya, Hapus Total</span>
                  <span class="btn-rich-desc">Hapus Router + Database Permanen.</span>
                </div>`,
              value: true,
              className: 'overlay-btn-danger' // Merah
            },
            {
              label: 'Batal',
              value: false,
              className: 'overlay-btn-muted'
            }
          ]
        });
        if (!ok) return;
        confirmMsg = null; // Sudah dikonfirmasi, jangan tanya lagi di bawah
      } 
      // Cek action standar lainnya (Rollback/Enable/Disable/Retur)
      else if (confirmMsg) {
        // Gunakan showConfirm standar tapi yang sudah di-upgrade style-nya (jika ada)
        // atau gunakan showOverlayChoice sederhana
        const ok = await showOverlayChoice({
           title: 'Konfirmasi',
           messageHtml: `<div style="text-align:left;font-size:14px;">${confirmMsg}</div>`,
           type: 'info',
           buttons: [
             { label: 'Batal', value: false, className: 'overlay-btn-muted' },
             { label: 'Ya, Lanjutkan', value: true, className: 'overlay-btn-secondary' }
           ]
        });
        if (!ok) return;
        confirmMsg = null;
      }
    }
    // --- END INTERCEPT ---

    // ... (Sisa kode actionRequest asli tetap sama di bawah ini) ...
    try {
      if (pageDim) pageDim.style.display = 'flex';
      // ... dst ...

```

### Rangkuman Hasil Perbaikan:

1. **Hapus User Single (`actionRequest` intercept):**
* Sekarang mendeteksi jika admin ingin menghapus total user.
* Menampilkan nama user dengan font besar.
* Memberikan kotak peringatan merah tentang hilangnya data uang.
* Tombol menjadi **Merah (Danger)** dengan deskripsi jelas.


2. **Konfirmasi Rusak (`showRusakChecklist`):**
* Menggunakan layout **Rich Buttons Vertikal**.
* Tombol "Print" dipisahkan warnanya (Biru) agar tidak tertukar dengan eksekusi (Oranye/Merah).
* Tabel checklist dipercantik dengan border tipis dan background gelap transparan.
* Menambahkan **Banner Status** (Hijau/Merah) di atas tabel agar hasil diagnosa langsung terlihat jelas.