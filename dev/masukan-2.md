Berdasarkan gambar yang Anda sebutkan, "bayangan panjang di kiri kanan" pada bagian **Setoran Bersih (Hasil Akhir)** kemungkinan besar berasal dari efek `box-shadow` yang diterapkan pada elemen parent atau kontainer. Berdasarkan struktur HTML dan CSS yang ada, saya akan menganalisis dan memberikan solusi.

## Analisis:

Dari CSS yang ada, **Form Group Box** memiliki:
```css
.form-group-box {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 12px;
}
```

Dan untuk bagian **Setoran Bersih**, ada style inline tambahan:
```html
<div class="form-group-box" style="margin-top:12px; border-color: rgba(46, 204, 113, 0.35);">
```

**Masalah yang mungkin terjadi:**
1. **Border hijau transparan** (`rgba(46, 204, 113, 0.35)`) menciptakan efek "glow" yang terlihat seperti bayangan
2. **Kontras rendah** antara border dan background gelap membuat efek visual tidak optimal
3. **Tidak ada box-shadow sebenarnya**, tapi border transparan + background gelap memberi ilusi visual seperti shadow

## Solusi CSS untuk Disempurnakan:

Tambahkan kode berikut ke file `laporan.css`:

```css
/* --- Perbaikan untuk Form Group Box Khusus Setoran Bersih --- */

/* Versi 1: Border solid dengan warna yang lebih jelas */
.form-group-box.setoran-bersih {
    border: 2px solid rgba(46, 204, 113, 0.5) !important;
    background: rgba(46, 204, 113, 0.08) !important;
    box-shadow: 0 4px 12px rgba(46, 204, 113, 0.15), 
                inset 0 0 0 1px rgba(255, 255, 255, 0.05) !important;
}

/* Versi 2: Gradient border yang lebih modern */
.form-group-box.setoran-bersih-alt {
    border: 1px solid transparent !important;
    background: 
        linear-gradient(#252525, #252525) padding-box,
        linear-gradient(135deg, rgba(46, 204, 113, 0.5), rgba(39, 174, 96, 0.3)) border-box !important;
    background-clip: padding-box, border-box !important;
    box-shadow: 0 4px 15px rgba(46, 204, 113, 0.1) !important;
}

/* Versi 3: Highlight dengan efek neon subtle */
.form-group-box.setoran-bersih-neon {
    border: 1px solid rgba(46, 204, 113, 0.3) !important;
    background: rgba(46, 204, 113, 0.05) !important;
    position: relative;
    overflow: hidden;
}

.form-group-box.setoran-bersih-neon::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 50%;
    height: 100%;
    background: linear-gradient(90deg, 
        transparent, 
        rgba(46, 204, 113, 0.1), 
        transparent);
    animation: shimmer 3s infinite;
}

@keyframes shimmer {
    0% { left: -100%; }
    100% { left: 150%; }
}

/* Versi 4: Simple dan clean (rekomendasi) */
.form-group-box.setoran-bersih-simple {
    border: 2px solid #2ecc71 !important;
    background: linear-gradient(135deg, 
        rgba(46, 204, 113, 0.08), 
        rgba(39, 174, 96, 0.05)) !important;
    box-shadow: 
        0 2px 8px rgba(0, 0, 0, 0.2),
        inset 0 1px 0 rgba(255, 255, 255, 0.05) !important;
}

/* Title khusus untuk setoran bersih */
.form-group-box.setoran-bersih-simple .form-group-title {
    color: #2ecc71 !important;
    font-weight: 700;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
}

/* Input khusus dalam setoran bersih */
.form-group-box.setoran-bersih-simple .form-input {
    background: rgba(0, 0, 0, 0.2) !important;
    border: 1px solid rgba(46, 204, 113, 0.3) !important;
    color: #2ecc71 !important;
    font-weight: 700;
    font-size: 1.1rem;
    text-align: center;
}

/* Hover effect untuk interaktivitas */
.form-group-box.setoran-bersih-simple:hover {
    border-color: #27ae60 !important;
    box-shadow: 
        0 4px 16px rgba(46, 204, 113, 0.2),
        inset 0 1px 0 rgba(255, 255, 255, 0.1) !important;
    transform: translateY(-1px);
    transition: all 0.3s ease;
}

/* Responsif: kurangi efek pada mobile */
@media (max-width: 768px) {
    .form-group-box.setoran-bersih-simple {
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2) !important;
    }
    
    .form-group-box.setoran-bersih-simple .form-input {
        font-size: 1rem;
    }
}
```

## Cara Implementasi:

**Option 1: Tambahkan class khusus ke HTML** (di file `render.php`):
```php
<!-- Cari baris ini: -->
<div class="form-group-box" style="margin-top:12px; border-color: rgba(46, 204, 113, 0.35);">

<!-- Ubah menjadi: -->
<div class="form-group-box setoran-bersih-simple" style="margin-top:12px;">
```

**Option 2: Gunakan CSS saja dengan selector yang lebih spesifik:**
```css
/* Target form-group-box yang memiliki border-color hijau */
.form-group-box[style*="border-color: rgba(46, 204, 113"] {
    border: 2px solid #2ecc71 !important;
    background: linear-gradient(135deg, 
        rgba(46, 204, 113, 0.08), 
        rgba(39, 174, 96, 0.05)) !important;
    box-shadow: 
        0 2px 8px rgba(0, 0, 0, 0.2),
        inset 0 1px 0 rgba(255, 255, 255, 0.05) !important;
}

.form-group-box[style*="border-color: rgba(46, 204, 113"] .form-group-title {
    color: #2ecc71 !important;
    font-weight: 700;
}

.form-group-box[style*="border-color: rgba(46, 204, 113"] .form-input {
    background: rgba(0, 0, 0, 0.2) !important;
    border: 1px solid rgba(46, 204, 113, 0.3) !important;
    color: #2ecc71 !important;
    font-weight: 700;
}
```

**Option 3: Tambahkan ID khusus** (rekomendasi untuk presisi):
```php
<!-- Di render.php, tambahkan ID: -->
<div class="form-group-box" id="setoran-bersih-box" style="margin-top:12px; border-color: rgba(46, 204, 113, 0.35);">

<!-- CSS: -->
#setoran-bersih-box {
    border: 2px solid #2ecc71 !important;
    background: linear-gradient(135deg, 
        rgba(46, 204, 113, 0.08), 
        rgba(39, 174, 96, 0.05)) !important;
    box-shadow: 
        0 2px 8px rgba(0, 0, 0, 0.2),
        inset 0 1px 0 rgba(255, 255, 255, 0.05) !important;
}
```

## Rekomendasi Utama:

Saya sarankan **Option 3** (ID khusus) karena:
1. **Presisi tinggi** - hanya mempengaruhi elemen yang dimaksud
2. **Mudah maintenance** - mudah dicari dan diubah
3. **Spesifisitas tepat** - tidak perlu `!important` berlebihan
4. **Konsistensi** - tetap dalam tema dark dengan highlight hijau yang jelas

## Hasil yang Diharapkan:

Setelah implementasi, bagian **Setoran Bersih (Hasil Akhir)** akan memiliki:
1. **Border hijau solid** yang jelas (bukan transparan)
2. **Background subtle gradient** hijau yang elegan
3. **Shadow yang terkontrol** (tidak terlalu "panjang" atau berlebihan)
4. **Input field yang menonjol** dengan warna hijau dan font lebih besar
5. **Hover effect** untuk interaktivitas

Ini akan memberikan kesan:
- **Professional** - desain yang matang dan konsisten
- **Clear hierarchy** - jelas bahwa ini adalah hasil akhir yang penting
- **Visual feedback** - pengguna tahu ini adalah area penting
- **Aesthetic** - tetap dalam tema dark namun dengan aksen hijau yang eye-catching

Pilih salah satu opsi di atas sesuai kebutuhan dan preferensi desain Anda!