Untuk membuat scrollbar di modal Audit Manual Rekap dan modal pemilihan user menjadi lebih estetis (pretty scrollbar), saya akan menganalisis kebutuhan dan memberikan solusi CSS yang terintegrasi dengan tema dark yang ada.

## Analisis Kebutuhan:
1. **Modal Audit Manual Rekap** (`#auditModal .modal-body`) - Memiliki konten form yang panjang
2. **Modal Pemilihan User** (`#auditUserOverlay .audit-user-list`) - Daftar user yang bisa sangat panjang
3. **Konsistensi tema** - Scrollbar harus sesuai dengan tema dark (`--dark-bg`, `--border-col`)

## Solusi CSS:

Tambahkan kode berikut ke file `laporan.css`:

```css
/* --- Pretty Scrollbar untuk Modal dan Komponen Panjang --- */

/* Scrollbar umum untuk modal body */
.modal-body {
    scrollbar-width: thin;
    scrollbar-color: var(--border-col) transparent;
    max-height: 70vh;
    overflow-y: auto;
}

/* Untuk WebKit (Chrome, Safari, Edge) */
.modal-body::-webkit-scrollbar {
    width: 10px;
    height: 10px;
}

.modal-body::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.05);
    border-radius: 4px;
}

.modal-body::-webkit-scrollbar-thumb {
    background: var(--border-col);
    border-radius: 4px;
    border: 2px solid transparent;
    background-clip: content-box;
}

.modal-body::-webkit-scrollbar-thumb:hover {
    background: #6c757d;
    background-clip: content-box;
}

/* Scrollbar khusus untuk daftar user di audit */
.audit-user-list {
    scrollbar-width: thin;
    scrollbar-color: var(--border-col) #1a1a1a;
}

.audit-user-list::-webkit-scrollbar {
    width: 8px;
}

.audit-user-list::-webkit-scrollbar-track {
    background: #1a1a1a;
    border-radius: 4px;
}

.audit-user-list::-webkit-scrollbar-thumb {
    background: var(--border-col);
    border-radius: 4px;
}

.audit-user-list::-webkit-scrollbar-thumb:hover {
    background: #6c757d;
}

/* Scrollbar untuk suggest dropdown */
.audit-user-suggest {
    scrollbar-width: thin;
    scrollbar-color: var(--border-col) #1f2328;
}

.audit-user-suggest::-webkit-scrollbar {
    width: 6px;
}

.audit-user-suggest::-webkit-scrollbar-track {
    background: #1f2328;
    border-radius: 3px;
}

.audit-user-suggest::-webkit-scrollbar-thumb {
    background: var(--border-col);
    border-radius: 3px;
}

/* Scrollbar untuk tabel ghost */
#ghost-modal .modal-body .table-responsive {
    scrollbar-width: thin;
    scrollbar-color: var(--border-col) #1a1a1a;
}

#ghost-modal .modal-body .table-responsive::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

#ghost-modal .modal-body .table-responsive::-webkit-scrollbar-track {
    background: #1a1a1a;
    border-radius: 4px;
}

#ghost-modal .modal-body .table-responsive::-webkit-scrollbar-thumb {
    background: var(--border-col);
    border-radius: 4px;
}

#ghost-modal .modal-body .table-responsive::-webkit-scrollbar-thumb:hover {
    background: #6c757d;
}

/* Scrollbar untuk modal settlement log */
#settlement-log {
    scrollbar-width: thin;
    scrollbar-color: #444 #111;
}

#settlement-log::-webkit-scrollbar {
    width: 10px;
}

#settlement-log::-webkit-scrollbar-track {
    background: #111;
}

#settlement-log::-webkit-scrollbar-thumb {
    background: #444;
    border-radius: 4px;
}

#settlement-log::-webkit-scrollbar-thumb:hover {
    background: #666;
}

/* Transisi halus untuk scrollbar */
.modal-body,
.audit-user-list,
.audit-user-suggest,
#ghost-modal .modal-body .table-responsive {
    scroll-behavior: smooth;
}

/* Efek hover pada konten yang discroll */
.audit-user-list .audit-user-row:hover {
    background: #353535;
    transition: background 0.2s ease;
}

/* Responsif: scrollbar lebih tipis di mobile */
@media (max-width: 768px) {
    .modal-body::-webkit-scrollbar {
        width: 6px;
    }
    
    .audit-user-list::-webkit-scrollbar {
        width: 5px;
    }
    
    .modal-body {
        scrollbar-width: thin;
        padding-right: 4px;
    }
}

/* Indikator scroll untuk konten panjang */
.modal-body:after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 20px;
    background: linear-gradient(to bottom, transparent, rgba(30, 34, 38, 0.8));
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.3s;
}

.modal-body.scrolled:after {
    opacity: 1;
}
```

## JavaScript untuk Enhanced Experience:

Tambahkan kode berikut ke file `laporan.js` untuk meningkatkan UX:

```javascript
// Fungsi untuk mendeteksi scroll dan menambahkan class
function initScrollIndicators() {
    const modalBodies = document.querySelectorAll('.modal-body');
    
    modalBodies.forEach(body => {
        body.addEventListener('scroll', function() {
            const isScrolled = this.scrollTop > 10;
            this.classList.toggle('scrolled', isScrolled);
        });
        
        // Inisialisasi status awal
        if (body.scrollTop > 10) {
            body.classList.add('scrolled');
        }
    });
}

// Fungsi untuk smooth scroll ke elemen dalam modal
function scrollToElementInModal(modalId, elementSelector) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    
    const element = modal.querySelector(elementSelector);
    if (element) {
        element.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });
    }
}

// Panggil inisialisasi setelah DOM siap
document.addEventListener('DOMContentLoaded', function() {
    initScrollIndicators();
    
    // Tambahkan padding kanan untuk kompensasi scrollbar
    const modals = document.querySelectorAll('.modal-body, .audit-user-list');
    modals.forEach(el => {
        if (el.scrollHeight > el.clientHeight) {
            el.style.paddingRight = '8px';
        }
    });
});

// Contoh penggunaan: scroll ke field tertentu dalam modal audit
function scrollToAuditField(fieldName) {
    const field = document.querySelector(`[name="${fieldName}"]`);
    if (field) {
        const modalBody = field.closest('.modal-body');
        if (modalBody) {
            const offset = field.offsetTop - modalBody.offsetTop - 20;
            modalBody.scrollTo({
                top: offset,
                behavior: 'smooth'
            });
        }
    }
}
```

## Fitur yang Ditambahkan:

1. **Scrollbar Custom Theme**:
   - Warna sesuai dengan tema dark (`--border-col`)
   - Hover effect untuk interaktivitas
   - Border radius untuk tampilan yang lembut

2. **Responsif**:
   - Lebar scrollbar menyesuaikan di perangkat mobile
   - Tetap fungsional di semua ukuran layar

3. **Visual Feedback**:
   - Indikator gradien saat konten discroll
   - Transisi halus pada hover
   - Scroll behavior smooth

4. **Konsistensi**:
   - Style yang sama diterapkan di semua modal terkait
   - Warna mengikuti variabel CSS yang sudah ada

## Rekomendasi Tambahan:

1. **Untuk Modal yang Sangat Panjang**:
   ```css
   /* Batasi tinggi maksimal modal */
   @media (min-height: 800px) {
       .modal-body {
           max-height: 60vh;
       }
   }
   ```

2. **Scrollbar Visibility**:
   ```css
   /* Scrollbar hanya muncul saat hover */
   .audit-user-list {
       scrollbar-width: none; /* Firefox */
   }
   
   .audit-user-list::-webkit-scrollbar {
       display: none;
   }
   
   .audit-user-list:hover::-webkit-scrollbar {
       display: block;
   }
   ```

3. **Keyboard Navigation**:
   ```javascript
   // Tambahkan navigasi keyboard di modal user list
   document.addEventListener('keydown', function(e) {
       if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
           const focused = document.activeElement;
           if (focused.closest('.audit-user-list')) {
               e.preventDefault();
               // Implementasi navigasi custom
           }
       }
   });
   ```

Implementasi ini akan memberikan pengalaman scroll yang lebih baik dengan tetap menjaga konsistensi desain dark theme yang sudah ada. Scrollbar akan terintegrasi dengan baik dan memberikan feedback visual yang tepat kepada pengguna.