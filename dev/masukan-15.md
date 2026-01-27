Berikut adalah **Analisa dan Perbaikan Total** untuk script Anda (`render.php`, `laporan.js`, `laporan.css`).

### Analisa & Masukan

1. **Masalah UX pada Input HP:**
* **Redundansi:** Operator harus menghitung manual `Total = Wartel + Kamtib`. Ini rentan *human error* (salah hitung).
* **Logika Checkbox:** Mengharuskan operator mencentang "Wartel" atau "Kamtib" itu merepotkan. Seharusnya sistem mendeteksi otomatis: jika diisi angka, berarti ada.
* **Validasi:** Sering terjadi `Total` tidak sinkron dengan rinciannya.


2. **Solusi "Rich UI" (Sesuai gaya sebelumnya):**
* **Auto-Calculation:** `Total Unit` dijadikan **ReadOnly** (tidak bisa diedit manual). Kolom ini otomatis menjumlahkan input Wartel + Kamtib.
* **Visual Grouping:** Pisahkan area "Input Jumlah Fisik" dengan "Kondisi (Rusak/Spam)".
* **Informative Headers:** Gunakan gaya header yang tegas dan deskriptif.


3. **Audit Manual:**
* Area "Uang" harus terlihat berbeda dengan area "Qty" agar mata operator fokus.
* Perhitungan otomatis harus terlihat real-time dan jelas.



---

### 1. Update `laporan.css`

Tambahkan style ini di bagian bawah untuk mendukung tampilan form yang lebih informatif dan field *read-only* yang terlihat seperti hasil kalkulasi.

```css
/* --- Style Tambahan untuk Form Modal Rich UI --- */

/* Field Readonly yang terlihat seperti Display/Kalkulasi */
.form-input[readonly] {
  background: #1a1d21; /* Lebih gelap */
  border-color: #3a4046;
  color: #f39c12; /* Warna Orange agar mencolok sebagai hasil hitungan */
  font-weight: bold;
  font-family: 'Courier New', monospace;
  cursor: not-allowed;
}

/* Grouping Box di dalam Modal */
.form-group-box {
  background: rgba(255, 255, 255, 0.03);
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: 8px;
  padding: 12px;
  margin-bottom: 12px;
}

.form-group-title {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: #9aa0a6;
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 6px;
}

/* Info Banner di dalam modal */
.modal-info-banner {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  background: rgba(52, 152, 219, 0.1);
  border-left: 3px solid #3498db;
  padding: 10px 14px;
  border-radius: 4px;
  margin-bottom: 15px;
}

.modal-info-icon {
  font-size: 18px;
  color: #3498db;
  margin-top: 2px;
}

.modal-info-text {
  font-size: 12px;
  color: #d1d5db;
  line-height: 1.4;
}

/* Label field dengan ikon kecil */
.label-icon {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 4px;
  font-size: 12px;
  color: #ccc;
}

```

### 2. Update `render.php` (Bagian Modal)

Ganti seluruh blok `<div id="hpModal" ...>` dan `<div id="auditModal" ...>` dengan kode di bawah ini. Kode ini menerapkan struktur yang lebih logis, input `Total` dimatikan (readonly), dan checkbox disembunyikan (otomatis aktif).

#### A. Modal Input Handphone (Baru)

```html
<div id="hpModal" class="modal-backdrop" onclick="if(event.target===this){closeHpModal();}">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-mobile" style="color:#4ea8ff; margin-right:8px;"></i> Input Handphone Harian</div>
            <button type="button" class="modal-close" onclick="closeHpModal()">&times;</button>
        </div>
        <form id="hpForm" method="post" action="report/laporan/services/hp_save.php">
            <?php if ($session_id !== ''): ?>
                <input type="hidden" name="session" value="<?= htmlspecialchars($session_id); ?>">
            <?php endif; ?>
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="show" value="<?= htmlspecialchars($req_show); ?>">
            <input type="hidden" name="date" value="<?= htmlspecialchars($filter_date); ?>">
            <input type="hidden" name="report" value="selling">
            
            <input type="checkbox" name="unit_wartel" value="1" checked style="display:none;">
            <input type="checkbox" name="unit_kamtib" value="1" checked style="display:none;">
            <input type="hidden" name="active_units" value="0">

            <div class="modal-body">
                <div class="modal-info-banner">
                    <div class="modal-info-icon"><i class="fa fa-info-circle"></i></div>
                    <div class="modal-info-text">
                        Data harian mengikuti data hari sebelumnya secara otomatis. Input hanya jika ada perubahan jumlah fisik HP.
                    </div>
                </div>

                <div class="form-grid-2">
                    <div>
                        <label class="label-icon"><i class="fa fa-th-large"></i> Blok</label>
                        <select class="form-input" name="blok_name" required>
                            <option value="" disabled selected>Pilih Blok</option>
                            <?php foreach ($blok_letters as $b): ?>
                                <option value="BLOK-<?= $b ?>">BLOK-<?= $b ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="label-icon"><i class="fa fa-calendar"></i> Tanggal</label>
                        <input class="form-input" type="date" name="report_date" value="<?= htmlspecialchars($filter_date); ?>" required>
                    </div>
                </div>

                <div class="form-group-box" style="margin-top:12px;">
                    <div class="form-group-title"><i class="fa fa-cubes"></i> Sumber Unit (Fisik)</div>
                    <div class="form-grid-2">
                        <div>
                            <label style="color:#7ee2a8;">Jumlah WARTEL</label>
                            <input class="form-input" type="number" name="wartel_units" min="0" value="0" placeholder="0">
                        </div>
                        <div>
                            <label style="color:#9cc7ff;">Jumlah KAMTIB</label>
                            <input class="form-input" type="number" name="kamtib_units" min="0" value="0" placeholder="0">
                        </div>
                    </div>
                </div>

                <div class="form-group-box">
                    <div class="form-group-title"><i class="fa fa-calculator"></i> Total & Kondisi</div>
                    <div class="form-grid-2">
                        <div style="grid-column: span 2;">
                            <label class="label-icon" style="color:#f39c12;"><i class="fa fa-check-circle"></i> Total Unit (Otomatis)</label>
                            <input class="form-input" type="number" name="total_units" min="0" value="0" readonly tabindex="-1">
                        </div>
                        <div>
                            <label>Rusak</label>
                            <input class="form-input" type="number" name="rusak_units" min="0" value="0">
                        </div>
                        <div>
                            <label>Spam</label>
                            <input class="form-input" type="number" name="spam_units" min="0" value="0">
                        </div>
                    </div>
                </div>

                <div id="hpClientError" style="display:none; margin-bottom:10px; color:#fca5a5; font-size:12px; background:rgba(220,38,38,0.2); padding:8px; border-radius:4px;"></div>
                
                <div>
                    <label class="label-icon"><i class="fa fa-pencil"></i> Catatan (Opsional)</label>
                    <input class="form-input" name="notes" placeholder="Keterangan tambahan...">
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-print btn-default-dark" onclick="closeHpModal()">Batal</button>
                <button type="submit" id="hpSubmitBtn" name="hp_submit" class="btn-print" style="background:#2ecc71;">Simpan Data</button>
            </div>
        </form>
    </div>
</div>

```

#### B. Modal Audit Manual (Baru)

```html
<div id="auditModal" class="modal-backdrop" onclick="if(event.target===this){closeAuditModal();}">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-check-square-o" style="color:#f39c12; margin-right:8px;"></i> Audit Manual Rekap</div>
            <button type="button" class="modal-close" onclick="closeAuditModal()">&times;</button>
        </div>
        <form id="auditForm" method="post" action="report/selling.php">
            <?php if ($session_id !== ''): ?>
                <input type="hidden" name="session" value="<?= htmlspecialchars($session_id); ?>">
            <?php endif; ?>
            <input type="hidden" name="show" value="<?= htmlspecialchars($req_show); ?>">
            <input type="hidden" name="date" value="<?= htmlspecialchars($filter_date); ?>">
            <input type="hidden" name="report" value="selling">
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="audit_submit" value="1">
            
            <div class="modal-body">
                <?php if ($audit_locked_today): ?>
                    <div style="margin-bottom:15px; padding:10px; border:1px solid #c0392b; background:rgba(192, 57, 43, 0.2); color:#fca5a5; font-size:12px; border-radius:4px; display:flex; align-items:center; gap:8px;">
                        <i class="fa fa-lock"></i> Audit hari ini sudah dikunci. Tidak dapat diubah.
                    </div>
                <?php endif; ?>

                <div class="form-grid-2">
                    <div>
                        <label class="label-icon">Blok</label>
                        <select class="form-input" name="audit_blok" required>
                            <option value="" disabled selected>Pilih Blok</option>
                            <?php foreach ($blok_letters as $b): ?>
                                <option value="BLOK-<?= $b ?>">BLOK-<?= $b ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="label-icon">Tanggal</label>
                        <input class="form-input" type="date" name="audit_date" value="<?= htmlspecialchars($filter_date); ?>" required>
                    </div>
                </div>

                <div class="form-group-box" style="margin-top:12px;">
                    <div class="form-group-title"><i class="fa fa-ticket"></i> Fisik Voucher (Lapangan)</div>
                    <div class="form-grid-2">
                        <div>
                            <label>Profil 10 Menit</label>
                            <input class="form-input" type="number" id="audit_prof10_qty" name="audit_qty_10" min="0" value="0" required placeholder="0">
                        </div>
                        <div>
                            <label>Profil 30 Menit</label>
                            <input class="form-input" type="number" id="audit_prof30_qty" name="audit_qty_30" min="0" value="0" required placeholder="0">
                        </div>
                    </div>
                </div>

                <div class="form-grid-2">
                    <div>
                        <label class="label-icon" style="color:#ccc;">Total Qty (Otomatis)</label>
                        <input class="form-input" type="number" name="audit_qty" min="0" value="0" readonly tabindex="-1">
                    </div>
                    <div>
                        <label class="label-icon" style="color:#f39c12;">Total Setoran (Otomatis)</label>
                        <input class="form-input" type="number" name="audit_setoran" min="0" value="0" readonly tabindex="-1">
                    </div>
                </div>

                <div class="form-group-box" style="margin-top:12px; border-color: rgba(231, 76, 60, 0.3);">
                    <div class="form-group-title" style="color:#e74c3c;"><i class="fa fa-minus-circle"></i> Pengeluaran / Bon (Opsional)</div>
                    <div class="form-grid-2">
                        <div>
                            <label>Nominal (Rp)</label>
                            <input class="form-input" type="number" name="audit_expense_amt" min="0" value="0" placeholder="0">
                        </div>
                        <div>
                            <label>Keterangan</label>
                            <input class="form-input" type="text" name="audit_expense_desc" placeholder="Contoh: Beli Kertas Thermal">
                        </div>
                    </div>
                </div>

                <div style="margin-top:10px;">
                    <label class="label-icon"><i class="fa fa-user-times"></i> Username Tak Terlapor (Retur/Rusak System)</label>
                    <input type="hidden" name="audit_username" id="auditUsernameHidden">
                    <div class="audit-user-picker">
                        <div id="audit-user-chips" class="audit-user-chips"></div>
                        <input class="form-input" type="text" id="audit-user-input" placeholder="Ketik username...">
                        <div id="audit-user-suggest" class="audit-user-suggest"></div>
                    </div>
                </div>
                
                <div id="auditClientError" style="display:none; margin-top:10px; color:#fca5a5; font-size:12px;"></div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-print btn-default-dark" onclick="closeAuditModal()">Batal</button>
                <button type="submit" id="auditSubmitBtn" name="audit_submit" class="btn-print" style="background:#f39c12;" <?= $audit_locked_today ? 'disabled' : '' ?>>Simpan Audit</button>
            </div>
        </form>
    </div>
</div>

```

### 3. Update `laporan.js` (Bagian Logika Auto-Calculation)

Ganti *IIFE* yang menangani `hpForm` (sekitar baris 430-an pada file asli Anda) dengan kode baru ini. Kode ini membuang logika checkbox yang rumit dan menggantinya dengan logika penjumlahan otomatis yang diminta.

```javascript
/* --- LOGIKA BARU UNTUK HP MODAL (Auto Calculate) --- */
(function(){
    var form = document.getElementById('hpForm');
    var btn = document.getElementById('hpSubmitBtn');
    var err = document.getElementById('hpClientError');
    
    // Elements
    var totalEl = form ? form.querySelector('input[name="total_units"]') : null;
    var wartelEl = form ? form.querySelector('input[name="wartel_units"]') : null;
    var kamtibEl = form ? form.querySelector('input[name="kamtib_units"]') : null;
    var rusakEl = form ? form.querySelector('input[name="rusak_units"]') : null;
    var spamEl = form ? form.querySelector('input[name="spam_units"]') : null;
    var activeEl = form ? form.querySelector('input[name="active_units"]') : null; // Hidden input

    function calculateTotal() {
        if (!wartelEl || !kamtibEl || !totalEl) return;
        
        // 1. Ambil nilai Wartel & Kamtib
        var w = parseInt(wartelEl.value || '0', 10);
        var k = parseInt(kamtibEl.value || '0', 10);
        
        // 2. Pastikan tidak negatif
        if (w < 0) w = 0;
        if (k < 0) k = 0;

        // 3. Hitung Total
        var total = w + k;
        totalEl.value = total;

        validate(); // Jalankan validasi setelah hitung
    }

    function validate(){
        if (!form || !btn || !err) return;
        
        var total = totalEl ? parseInt(totalEl.value || '0', 10) : 0;
        var rusak = rusakEl ? parseInt(rusakEl.value || '0', 10) : 0;
        var spam = spamEl ? parseInt(spamEl.value || '0', 10) : 0;
        
        // Hitung Unit Aktif (untuk backend)
        if (activeEl) {
            var calcActive = total - rusak - spam;
            activeEl.value = calcActive >= 0 ? calcActive : 0;
        }

        var msg = '';
        
        // Validasi: Total 0 masih boleh (mungkin hari libur/kosong), tapi peringatkan jika input negatif
        if (total < 0) msg = 'Total unit tidak valid.';
        else if (total < (rusak + spam)) {
            // Validasi Utama: Rusak + Spam tidak boleh melebihi Total Fisik
            msg = 'Jumlah Rusak (' + rusak + ') + Spam (' + spam + ') melebihi Total Unit (' + total + ').';
        }

        if (msg) {
            err.innerHTML = '<i class="fa fa-exclamation-triangle"></i> ' + msg;
            err.style.display = 'block';
            btn.disabled = true;
            btn.style.opacity = '0.5';
            btn.style.cursor = 'not-allowed';
        } else {
            err.textContent = '';
            err.style.display = 'none';
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.style.cursor = 'pointer';
        }
    }

    // Attach Listeners
    if (wartelEl) wartelEl.addEventListener('input', calculateTotal);
    if (kamtibEl) kamtibEl.addEventListener('input', calculateTotal);
    
    if (rusakEl) rusakEl.addEventListener('input', validate);
    if (spamEl) spamEl.addEventListener('input', validate);

    // Initial Run
    if (form) {
        // Handle Submit via AJAX
        form.addEventListener('submit', function(e){
            e.preventDefault();
            if (btn && btn.disabled) return;
            
            window.sellingPauseReload = true;
            var fd = new FormData(form);
            // Paksa unit_wartel & unit_kamtib dikirim sebagai "1" (checked) 
            // karena logic backend data.php membutuhkannya jika nilai > 0.
            // Di HTML sudah ada hidden input checked, jadi aman.
            
            btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Menyimpan...';
            
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
                    err.innerHTML = msg;
                    err.style.display = 'block';
                    btn.innerHTML = 'Simpan Data';
                })
                .catch(function(){
                    err.innerHTML = 'Gagal mengirim data. Coba lagi.';
                    err.style.display = 'block';
                    btn.innerHTML = 'Simpan Data';
                    btn.disabled = false;
                });
        });
    }
})();

// Perbarui juga fungsi openHpEdit agar mengisi data ke field baru dengan benar
window.openHpEdit = function(btn){
    var form = document.getElementById('hpForm');
    if (!form || !btn) return;

    // Isi field dasar
    form.querySelector('select[name="blok_name"]').value = btn.getAttribute('data-blok') || '';
    form.querySelector('input[name="report_date"]').value = btn.getAttribute('data-date') || '';
    form.querySelector('input[name="rusak_units"]').value = btn.getAttribute('data-rusak') || '0';
    form.querySelector('input[name="spam_units"]').value = btn.getAttribute('data-spam') || '0';
    form.querySelector('input[name="notes"]').value = btn.getAttribute('data-notes') || '';

    // Isi Wartel & Kamtib
    var wVal = btn.getAttribute('data-wartel') || '0';
    var kVal = btn.getAttribute('data-kamtib') || '0';
    
    var wInput = form.querySelector('input[name="wartel_units"]');
    var kInput = form.querySelector('input[name="kamtib_units"]');
    
    if (wInput) wInput.value = wVal;
    if (kInput) kInput.value = kVal;

    // Trigger input event untuk hitung total otomatis saat edit dibuka
    var evt = new Event('input');
    if (wInput) wInput.dispatchEvent(evt);

    openHpModal();
};

```

### Kesimpulan Perubahan

1. **Total Disable & Auto-Hitung:** Saya membuat field `Total Unit` menjadi `readonly`. Operator sekarang hanya mengisi **Jml Wartel** dan **Jml Kamtib**. Total akan terisi otomatis. Ini menjawab permintaan Anda dan menghilangkan risiko salah hitung.
2. **Sederhana & Aman:** Checkbox yang membingungkan telah disembunyikan (selalu terkirim sebagai "ada"). Backend (`data.php`) akan menerima data seolah-olah user mencentang keduanya, namun jika jumlahnya 0, itu tidak masalah.
3. **Tampilan Profesional:** Modal sekarang menggunakan ikon, grouping box, dan warna status (Orange untuk kalkulasi, Hijau/Biru untuk input) yang memudahkan mata operator membedakan mana input fisik dan mana hasil sistem.