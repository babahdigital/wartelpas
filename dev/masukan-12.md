Halo Pak Abdullah.

Ide Anda sangat tepat karena menambahkan konteks manajerial ke laporan angka. Ini akan menurunkan konflik saat omzet turun, karena ada ruang untuk menjelaskan kondisi lapangan.

**Masalah:** Grafik omzet turun, Owner bingung/marah.
**Solusi:** Tambahkan tombol **Catatan Harian/Insiden** agar Supervisor bisa memberi alasan valid (misal: *"Hujan deras seharian, pengunjung sepi"*, *"Listrik padam 4 jam"*).
**Hasil:** Owner membaca angka + konteks → paham dan lebih tenang.

Berikut rancangan implementasi rapi untuk **3 file** (+ 1 tabel DB):

1. **Database:** Tabel khusus catatan harian.
2. **selling.php:** Tombol Catatan Harian + modal input (menggantikan tombol Kunci Audit jika diinginkan).
3. **print_rekap_bulanan.php:** Kolom catatan (dipotong agar tabel tetap rapi).
4. **print_rekap.php:** Catatan penting berwarna merah di bawah footer.

---

## 1) Update Database (Otomatis via `selling.php`)

Catatan sifatnya **per tanggal** (global), bukan per blok.
Tambahkan pembuatan tabel `daily_report_notes` di `selling.php`.

**Kode PHP (taruh setelah koneksi DB):**

```php
// 1) Buat tabel catatan harian (sekali jalan)
$db->exec("CREATE TABLE IF NOT EXISTS daily_report_notes (
    report_date TEXT PRIMARY KEY,
    note TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// 2) Simpan/Hapus Catatan
if (isset($_POST['save_daily_note'])) {
    $note_date = $_POST['note_date'] ?? '';
    $note_text = trim($_POST['note_text'] ?? '');
    $note_text = mb_substr($note_text, 0, 500); // batas aman 500 char

    if ($note_date && $note_text !== '') {
        $stmt = $db->prepare("INSERT INTO daily_report_notes (report_date, note, updated_at)
            VALUES (:d, :n, CURRENT_TIMESTAMP)
            ON CONFLICT(report_date) DO UPDATE SET note=excluded.note, updated_at=CURRENT_TIMESTAMP");
        $stmt->execute([':d' => $note_date, ':n' => $note_text]);
    } elseif ($note_date && $note_text === '') {
        $db->prepare("DELETE FROM daily_report_notes WHERE report_date = :d")
           ->execute([':d' => $note_date]);
    }

    header("Location: ./?report=selling&session={$session_id}&show={$req_show}&date={$filter_date}");
    exit;
}

// 3) Ambil catatan hari ini (untuk ditampilkan di modal)
$current_daily_note = '';
if ($req_show === 'harian') {
    $stmtNote = $db->prepare("SELECT note FROM daily_report_notes WHERE report_date = :d");
    $stmtNote->execute([':d' => $filter_date]);
    $current_daily_note = $stmtNote->fetchColumn() ?: '';
}
```

---

## 2) Update `selling.php` (Input Catatan Harian)

**Tujuan:**
1) Tombol **Catatan Harian/Insiden** menggantikan tombol **Kunci Audit** (opsional).
2) Modal input singkat untuk alasan omzet naik/turun.

**HTML Tombol (gantikan tombol Kunci Audit):**

```php
<?php if ($req_show === 'harian'): ?>
    <button class="btn-print" type="button" onclick="openAuditModal()" <?= $audit_locked_today ? 'disabled style="opacity:.6;cursor:not-allowed;"' : '' ?>>Audit Manual</button>

    <button class="btn-print" type="button" onclick="openNoteModal()" style="background:#8e44ad; color:#fff;">
        <i class="fa fa-sticky-note-o"></i> Catatan / Insiden
    </button>
<?php endif; ?>
```

**HTML Modal (taruh sebelum `</body>`):**

```html
<div id="noteModal" class="modal-backdrop" onclick="if(event.target===this){closeNoteModal();}">
    <div class="modal-card" style="width:500px;">
        <div class="modal-header" style="background:#8e44ad;">
            <div class="modal-title" style="color:#fff;"><i class="fa fa-pencil-square-o"></i> Catatan Harian (Laporan ke Owner)</div>
            <button type="button" class="modal-close" onclick="closeNoteModal()">&times;</button>
        </div>
        <form method="post" action="">
            <input type="hidden" name="save_daily_note" value="1">
            <input type="hidden" name="note_date" value="<?= esc($filter_date) ?>">
            <div class="modal-body">
                <div style="background:#f3e5f5; color:#4a148c; padding:10px; border-radius:4px; font-size:12px; margin-bottom:15px;">
                    <strong>Tips:</strong> Jelaskan alasan omzet hari ini naik/turun secara singkat.
                    Contoh: <em>"Hujan deras seharian, sepi"</em> atau <em>"Listrik padam 4 jam"</em>.
                </div>
                <label style="color:#ccc;">Isi Catatan / Keterangan:</label>
                <textarea name="note_text" rows="5" class="form-input" style="width:100%; margin-top:5px; line-height:1.5;" placeholder="Tulis keterangan di sini..."><?= esc($current_daily_note) ?></textarea>
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

## 3) Update `print_rekap_bulanan.php` (Catatan di Tabel, Dipotong)

**Tujuan:** Menampilkan catatan tanpa membuat tabel “melebar”. Catatan dipotong agar tetap 1 baris.

**PHP (ambil data catatan sebulan):**

```php
// Ambil Notes sebulan
$notes_map = [];
try {
    $stmtN = $db->prepare("SELECT report_date, note FROM daily_report_notes WHERE report_date LIKE :m");
    $stmtN->execute([':m' => $filter_date . '%']);
    foreach ($stmtN->fetchAll(PDO::FETCH_ASSOC) as $rn) {
        $notes_map[$rn['report_date']] = $rn['note'];
    }
} catch (Exception $e) {}
```

**HTML (tambahkan kolom):**

```php
<th style="border:1px solid #cbd5e1; padding:8px; width:20%;">Keterangan / Insiden</th>

<?php
    $day_note = $notes_map[$row['date']] ?? '';
    $day_note_short = mb_strimwidth($day_note, 0, 40, "...");
?>
<td style="border:1px solid #e2e8f0; padding:6px 8px; text-align:left; font-size:10px; color:#555;">
    <?= esc($day_note_short) ?>
</td>
```

---

## 4) Update `print_rekap.php` (Catatan Merah di Bawah)

**Tujuan:** Jika ada catatan hari itu, tampilkan menonjol agar Owner langsung membaca.

**PHP (ambil catatan harian):**

```php
// Ambil Note Hari Ini
$daily_note_alert = '';
try {
    $stmtN = $db->prepare("SELECT note FROM daily_report_notes WHERE report_date = :d");
    $stmtN->execute([':d' => $filter_date]);
    $daily_note_alert = $stmtN->fetchColumn() ?: '';
} catch (Exception $e) {}
```

**HTML (di bawah footer/legend):**

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
        <?= nl2br(esc($daily_note_alert)) ?>
    </div>
<?php endif; ?>
```

---

## Rangkuman Manfaat
1. **Psikologis:** Supervisor berani melaporkan omzet turun karena ada ruang untuk menjelaskan alasan.
2. **UI/UX:**
   - Selling: tombol ungu agar mudah dibedakan dari audit.
   - Rekap Harian: notifikasi merah agar Owner langsung membaca.
   - Rekap Bulanan: teks dipotong agar tabel tetap rapi.

Silakan diterapkan. Ini membuat komunikasi Lapangan ↔ Manajemen lebih sehat dan objektif.