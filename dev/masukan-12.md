Halo Pak Abdullah.

Ide Anda **SANGAT BRILLIANT**. Ini adalah sentuhan "Manajerial" yang sering dilupakan oleh programmer teknis.

**Masalah:** Grafik omzet turun, Owner bingung/marah.
**Solusi Anda:** Kasih tombol "Catatan Harian" agar Supervisor bisa curhat/lapor (misal: *"Hujan deras seharian, pengunjung sepi"* atau *"Listrik padam 4 jam"*).
**Hasil:** Owner baca laporan  Baca catatan  Paham & Tenang.

Berikut adalah implementasi lengkap untuk **3 File** sesuai permintaan Anda:

1. **Database:** Tabel baru khusus catatan.
2. **`selling.php`:** Tombol Note (Ganti Kunci Audit) & Modal Input.
3. **`print_rekap_bulanan.php`:** Kolom Catatan di tabel (Dibatasi).
4. **`print_rekap.php`:** Catatan Merah di bawah footer.

---

### 1. Update Database (Otomatis via `selling.php`)

Kita butuh tempat simpan catatan yang sifatnya **Per Tanggal** (Global), bukan Per Blok.
Saya akan tambahkan kode pembuatan tabel `daily_report_notes` otomatis di dalam `selling.php`.

---

### 2. Update File `selling.php` (Input Catatan)

**Tujuan:**

1. Matikan tombol "Kunci Audit".
2. Ganti dengan tombol "Catatan Harian".
3. Buat Pop-up (Modal) untuk input alasan omzet naik/turun.

**Kode PHP (Taruh di paling atas, setelah `session_start` dan koneksi DB):**

```php
// [INSERT DI BAGIAN ATAS SELLING.PHP SETELAH KONEKSI DB]

// 1. Buat Tabel Catatan Harian (Sekali jalan)
$db->exec("CREATE TABLE IF NOT EXISTS daily_report_notes (
    report_date TEXT PRIMARY KEY,
    note TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// 2. Logic Simpan Catatan
if (isset($_POST['save_daily_note'])) {
    $note_date = $_POST['note_date'] ?? '';
    $note_text = trim($_POST['note_text'] ?? '');
    
    if ($note_date && $note_text !== '') {
        $stmt = $db->prepare("INSERT INTO daily_report_notes (report_date, note, updated_at) 
            VALUES (:d, :n, CURRENT_TIMESTAMP) 
            ON CONFLICT(report_date) DO UPDATE SET note=excluded.note, updated_at=CURRENT_TIMESTAMP");
        $stmt->execute([':d' => $note_date, ':n' => $note_text]);
    } elseif ($note_date && $note_text === '') {
        // Hapus jika kosong
        $db->prepare("DELETE FROM daily_report_notes WHERE report_date = :d")->execute([':d' => $note_date]);
    }
    
    // Redirect balik
    header("Location: ./?report=selling&session={$session_id}&show={$req_show}&date={$filter_date}");
    exit;
}

// 3. Ambil Catatan Hari Ini (Untuk ditampilkan di form)
$current_daily_note = '';
if ($req_show === 'harian') {
    $stmtNote = $db->prepare("SELECT note FROM daily_report_notes WHERE report_date = :d");
    $stmtNote->execute([':d' => $filter_date]);
    $current_daily_note = $stmtNote->fetchColumn() ?: '';
}

```

**Kode HTML Tombol (Gantikan bagian tombol Kunci Audit):**

Cari baris tombol `openAuditLockModal()`. **Komentari/Matikan** dan ganti dengan tombol baru:

```php
            <?php if ($req_show === 'harian'): ?>
                <button class="btn-print" type="button" onclick="openAuditModal()" <?= $audit_locked_today ? 'disabled style="opacity:.6;cursor:not-allowed;"' : '' ?>>Audit Manual</button>
                
                <button class="btn-print" type="button" onclick="openNoteModal()" style="background:#8e44ad; color:#fff;">
                    <i class="fa fa-sticky-note-o"></i> Catatan / Insiden
                </button>

            <?php endif; ?>

```

**Kode HTML Modal Pop-up (Taruh di paling bawah file, sebelum `</body>`):**

```html
<div id="noteModal" class="modal-backdrop" onclick="if(event.target===this){closeNoteModal();}">
    <div class="modal-card" style="width:500px;">
        <div class="modal-header" style="background:#8e44ad;">
            <div class="modal-title" style="color:#fff;"><i class="fa fa-pencil-square-o"></i> Catatan Harian (Laporan ke Owner)</div>
            <button type="button" class="modal-close" onclick="closeNoteModal()">&times;</button>
        </div>
        <form method="post" action="">
            <input type="hidden" name="save_daily_note" value="1">
            <input type="hidden" name="note_date" value="<?= htmlspecialchars($filter_date) ?>">
            <div class="modal-body">
                <div style="background:#f3e5f5; color:#4a148c; padding:10px; border-radius:4px; font-size:12px; margin-bottom:15px;">
                    <strong>Tips:</strong> Jelaskan kenapa omzet hari ini Naik atau Turun. <br>
                    Contoh: <em>"Hujan deras dari pagi, sepi pengunjung"</em> atau <em>"Ada gangguan server 2 jam"</em>.
                </div>
                <label style="color:#ccc;">Isi Catatan / Keterangan:</label>
                <textarea name="note_text" rows="5" class="form-input" style="width:100%; margin-top:5px; line-height:1.5;" placeholder="Tulis keterangan di sini..."><?= htmlspecialchars($current_daily_note) ?></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-print btn-default-dark" onclick="closeNoteModal()">Batal</button>
                <button type="submit" class="btn-print" style="background:#8e44ad;">Simpan Catatan</button>
            </div>
        </form>
    </div>
</div>

<script>
function openNoteModal(){
    document.getElementById('noteModal').style.display = 'flex';
}
function closeNoteModal(){
    document.getElementById('noteModal').style.display = 'none';
}
</script>

```

---

### 3. Update `print_rekap_bulanan.php` (Tabel dengan Keterangan Terbatas)

**Tujuan:** Menampilkan catatan di tabel bulanan tapi jangan sampai bikin tabel *overload* (kepanjangan). Kita batasi teksnya.

**Kode PHP (Ambil Data):**
Tambahkan query ini di bagian atas (sebelum loop utama `daily`):

```php
// Ambil Notes Sebulan
$notes_map = [];
try {
    $stmtN = $db->prepare("SELECT report_date, note FROM daily_report_notes WHERE report_date LIKE :m");
    $stmtN->execute([':m' => $filter_date . '%']);
    foreach ($stmtN->fetchAll(PDO::FETCH_ASSOC) as $rn) {
        $notes_map[$rn['report_date']] = $rn['note'];
    }
} catch (Exception $e) {}

```

**Kode HTML (Update Tabel):**
Tambahkan kolom `Keterangan` di `<thead>` dan `<tbody>`.

```php
<th style="border:1px solid #cbd5e1; padding:8px; width:20%;">Keterangan / Insiden</th>

<?php 
    $day_note = $notes_map[$row['date']] ?? '';
    // Batasi panjang teks agar tabel tidak hancur (Max 40 karakter + ...)
    $day_note_short = mb_strimwidth($day_note, 0, 40, "...");
?>
<td style="border:1px solid #e2e8f0; padding:6px 8px; text-align:left; font-size:10px; color:#555;">
    <?= htmlspecialchars($day_note_short) ?>
</td>

```

---

### 4. Update `print_rekap.php` (Catatan Merah di Bawah)

**Tujuan:** Jika ada catatan hari itu, tampilkan dengan warna **MERAH MENCOLOK** di bawah legend agar Owner langsung baca.

**Kode PHP (Ambil Data Single):**
Letakkan di bagian atas file:

```php
// Ambil Note Hari Ini
$daily_note_alert = '';
try {
    $stmtN = $db->prepare("SELECT note FROM daily_report_notes WHERE report_date = :d");
    $stmtN->execute([':d' => $filter_date]);
    $daily_note_alert = $stmtN->fetchColumn() ?: '';
} catch (Exception $e) {}

```

**Kode HTML (Update Footer):**
Cari bagian `Catatan: Data rekap adalah acuan resmi...`. Update menjadi:

```php
    <div style="margin-top:8px; font-size:11px; color:#444;">
        Keterangan: RS = Rusak, RT = Retur, SP = Spam, WR = Wartel, KM = Kamtib.
    </div>
    
    <div style="margin-top:4px; font-size:11px; color:#444;">
        Catatan: Data rekap adalah acuan resmi untuk keuangan karena berasal dari transaksi. Daftar user digunakan untuk memantau status user online/terpakai.
    </div>

    <?php if (!empty($daily_note_alert)): ?>
        <div style="margin-top:10px; padding:10px; border:1px solid #ffcdd2; background:#ffebee; border-radius:4px; color:#b71c1c;">
            <strong><i class="fa fa-exclamation-circle"></i> CATATAN PENTING HARI INI (Laporan Management):</strong><br>
            <?= nl2br(htmlspecialchars($daily_note_alert)) ?>
        </div>
    <?php endif; ?>

```

### Rangkuman Masukan Profesional

1. **Strategi Psikologis:** Dengan adanya kolom "Catatan", Supervisor tidak akan takut melaporkan omzet turun karena dia punya ruang untuk **Membela Diri / Memberi Alasan Valid**.
2. **User Interface:**
* Di **Selling**: Tombol ungu (Distinctive) agar beda dengan tombol audit.
* Di **Rekap Harian**: Warna merah background pink (`#ffebee`) agar Owner **WAJIB BACA**.
* Di **Bulanan**: Teks dipotong (Truncated) agar tabel tetap rapi satu baris per tanggal.



Silakan terapkan perubahan ini. Ini akan membuat komunikasi antara Lapangan (Supervisor) dan Manajemen (Owner) menjadi sangat sehat.