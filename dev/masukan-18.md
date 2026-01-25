Berikut adalah versi **disempurnakan** dari popup notifikasi (`overlay`).

Perubahan utama meliputi:

1. **Desain Modern (Glassmorphism Lite):** Menggunakan backdrop blur, border radius yang lebih halus, dan bayangan yang elegan.
2. **Animasi Halus:** Efek *fade-in* dan *scale-up* agar terasa lebih responsif dan profesional.
3. **Indikator Warna Kontekstual:** Warna ikon dan border akan berubah otomatis sesuai status (Hijau untuk Sukses, Merah untuk Gagal, Biru untuk Loading).
4. **Tipografi:** Pemisahan antara Judul Status dan Pesan Detail agar lebih mudah dibaca.

Silakan ganti bagian bawah file `menu.php` Anda (dari baris CSS style overlay sampai fungsi JS terkait) dengan kode berikut:

### 1. Tambahkan/Ganti CSS (Letakkan di dalam tag `<style>`)

Hapus style inline pada `div` overlay lama, dan gunakan class CSS ini agar lebih rapi:

```css
    /* --- Styles untuk Overlay Popup Modern --- */
    .overlay-backdrop {
        position: fixed;
        inset: 0;
        z-index: 20000;
        background: rgba(0, 0, 0, 0.65);
        backdrop-filter: blur(4px); /* Efek blur di belakang */
        display: none; /* Default hidden */
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .overlay-backdrop.show {
        display: flex;
        opacity: 1;
    }

    .overlay-modal {
        background: #1e282c; /* Warna tema gelap Mikhmon */
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 12px;
        width: 100%;
        max-width: 380px;
        padding: 25px;
        text-align: center;
        box-shadow: 0 15px 35px rgba(0,0,0,0.5);
        transform: scale(0.9);
        transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    .overlay-backdrop.show .overlay-modal {
        transform: scale(1);
    }

    .overlay-icon-box {
        width: 70px;
        height: 70px;
        margin: 0 auto 15px auto;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 32px;
        background: rgba(255, 255, 255, 0.05);
        transition: all 0.3s;
    }

    /* Varian Warna Status */
    .status-loading .overlay-icon-box { color: #3c8dbc; border: 2px solid rgba(60, 141, 188, 0.3); background: rgba(60, 141, 188, 0.1); }
    .status-success .overlay-icon-box { color: #00a65a; border: 2px solid rgba(0, 166, 90, 0.3); background: rgba(0, 166, 90, 0.1); }
    .status-error   .overlay-icon-box { color: #dd4b39; border: 2px solid rgba(221, 75, 57, 0.3); background: rgba(221, 75, 57, 0.1); }

    .overlay-title {
        font-size: 18px;
        font-weight: 600;
        color: #fff;
        margin-bottom: 8px;
        letter-spacing: 0.5px;
    }

    .overlay-message {
        font-size: 14px;
        color: #b8c7ce;
        line-height: 1.5;
        margin-bottom: 20px;
    }

    .overlay-btn {
        background: #3c8dbc;
        color: white;
        border: none;
        padding: 10px 25px;
        border-radius: 50px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        outline: none;
        box-shadow: 0 4px 6px rgba(0,0,0,0.2);
    }

    .overlay-btn:hover {
        background: #367fa9;
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(0,0,0,0.3);
    }

    .status-error .overlay-btn { background: #d73925; }
    .status-error .overlay-btn:hover { background: #ac2925; }

```

### 2. Ganti Struktur HTML Overlay

Ganti blok HTML `<div id="ajax-overlay" ...>` yang lama dengan kode struktur baru ini (letakkan di posisi yang sama, sebelum `<?php if ($id != "")`):

```html
<div id="ajax-overlay" class="overlay-backdrop">
    <div class="overlay-modal" id="ajax-modal-container">
        <div class="overlay-icon-box">
            <i id="ajax-overlay-icon" class="fa fa-circle-o-notch fa-spin"></i>
        </div>
        
        <div class="overlay-title" id="ajax-overlay-title">Processing</div>
        <div class="overlay-message" id="ajax-overlay-text">Mohon tunggu sebentar...</div>
        
        <button id="ajax-overlay-close" type="button" class="overlay-btn" onclick="hideOverlayNotice()">
            Tutup
        </button>
    </div>
</div>

```

### 3. Update Fungsi JavaScript

Update fungsi `showOverlayNotice` dan `hideOverlayNotice` agar sesuai dengan animasi CSS baru.

```javascript
    function showOverlayNotice(msg, type, lockClose){
        var overlay = document.getElementById('ajax-overlay');
        var container = document.getElementById('ajax-modal-container');
        var titleEl = document.getElementById('ajax-overlay-title');
        var textEl = document.getElementById('ajax-overlay-text');
        var icon = document.getElementById('ajax-overlay-icon');
        var btn = document.getElementById('ajax-overlay-close');

        if (!overlay) return;

        // Reset Class
        container.classList.remove('status-loading', 'status-success', 'status-error');
        
        var t = (type || 'info').toLowerCase();
        
        // Setup UI berdasarkan Tipe
        if (t === 'error') {
            container.classList.add('status-error');
            icon.className = 'fa fa-times';
            titleEl.textContent = 'Gagal!';
        } else if (t === 'success') {
            container.classList.add('status-success');
            icon.className = 'fa fa-check';
            titleEl.textContent = 'Berhasil!';
        } else {
            container.classList.add('status-loading');
            icon.className = 'fa fa-circle-o-notch fa-spin'; // Spinner modern
            titleEl.textContent = 'Memproses...';
        }

        // Set Pesan
        textEl.textContent = msg || '';

        // Handle Tombol Tutup
        if (lockClose) {
            btn.style.display = 'none';
        } else {
            btn.style.display = 'inline-block';
            // Auto focus ke tombol jika bukan loading
            setTimeout(function(){ btn.focus(); }, 100); 
        }

        // Tampilkan dengan Animasi
        overlay.style.display = 'flex';
        // Sedikit delay agar transisi CSS berjalan (opacity 0 -> 1)
        setTimeout(function(){
            overlay.classList.add('show');
        }, 10);
    }

    function hideOverlayNotice(){
        var overlay = document.getElementById('ajax-overlay');
        if (overlay) {
            overlay.classList.remove('show');
            // Tunggu animasi CSS selesai baru display: none
            setTimeout(function(){
                overlay.style.display = 'none';
            }, 300);
        }
    }

```

### Hasil Akhir:

1. **Saat Loading:** Muncul spinner biru berputar, tombol tutup hilang, background gelap blur.
2. **Saat Sukses:** Ikon berubah jadi Centang Hijau, tombol tutup muncul.
3. **Saat Gagal:** Ikon berubah jadi Silang Merah, tombol tutup muncul dengan warna merah.

Tampilan ini jauh lebih "Clean & Tegas" serta selaras dengan tema Dark Mikhmon V3.