# Dokumentasi Pengembangan WhatsApp (Fonnte) – WartelPas

Dokumen ini merangkum detail API Fonnte berdasarkan URL yang diberikan untuk kebutuhan pengembangan pengiriman laporan harian (PDF) via WhatsApp. Fokus pada parameter, batasan, dan perilaku penting agar implementasi stabil dan aman.

## 1) Ringkasan Endpoint Utama

### 1.1 Send Message (POST)
**URL:** https://api.fonnte.com/send

**Kegunaan:** Mengirim pesan teks/attachment ke nomor, grup, atau rotator.

**Header:**
- `Authorization: <TOKEN>` (tanpa “Bearer”)

**Parameter penting (POST multipart/form-data):**
- `target` (string, wajib)
  - Bisa berupa nomor WA, group id, atau rotator id.
  - Multi-target dipisahkan koma. **Wajib string**, bukan angka.
- `message` (string, opsional)
  - Maks 60.000 karakter. Emoji/karakter multibyte dihitung lebih dari 1.
  - Jika `url` atau `file` diisi, `message` menjadi deskripsi.
- `url` (string, opsional – paket tertentu)
  - URL publik file langsung (bukan halaman). Tidak boleh localhost.
- `filename` (string, opsional)
  - Nama file yang tampil (hanya non-image/video).
- `file` (binary, opsional – paket tertentu)
  - Upload file lokal (berguna untuk private IP).
- `schedule` (int, opsional)
  - Unix timestamp. Perhatikan timezone saat konversi.
- `delay` (string, opsional)
  - `"5"` atau `"5-100"` (detik). Hanya bekerja pada multi-target.
- `countryCode` (string, opsional)
  - Default `62`. Set `0` untuk bypass normalisasi (harus full country code).
- `location` (string, opsional)
  - Format: `lat,long`.
- `typing` (bool, opsional)
  - Tampilkan typing indicator.
- `choices`, `select`, `pollname` (opsional)
  - Untuk pesan polling (min 2, max 12).
- `connectOnly` (bool, opsional)
  - Default true. Jika device offline, request ditolak. Set false untuk antri.
- `data` (string, opsional)
  - JSON string berisi batch request (mendukung delay & multi attachment). **Bukan array**.
- `sequence` (bool, opsional)
  - Menjamin urutan, tetapi **menonaktifkan delay/schedule/followup**.
- `preview` (bool, opsional)
  - Default true; set false untuk menonaktifkan preview link.
- `inboxid` (int, opsional)
  - Untuk reply berdasarkan webhook inbox.
- `duration` (int, opsional)
  - Durasi typing indicator.

**Respons sukses contoh:**
```json
{
  "detail": "success! message in queue",
  "id": ["80367170"],
  "process": "pending",
  "requestid": 2937124,
  "status": true,
  "target": ["6282227097005"]
}
```

**Respons gagal umum:**
- `token invalid`
- `input invalid`
- `url invalid` / `url unreachable`
- `file format not supported` / `file size must under 4MB`
- `target invalid`
- `JSON format invalid`
- `insufficient quota`

> Catatan: Fonnte mendukung **multiple token** sebagai rotator (dipisah koma), tetapi harus dalam **akun yang sama**.

---

### 1.2 Send Message (GET) – Alternatif
**URL:** https://api.fonnte.com/send/?<query>

**Kegunaan:** Alternatif jika lingkungan tidak mendukung POST. **Tidak dianjurkan untuk frontend** karena token terbuka.

**Parameter:** mirip POST, namun:
- `token` dikirim sebagai query (wajib)
- `file` tidak tersedia; gunakan `url`
- Wajib **URL-encode** semua nilai
- Ada `redirect` opsional (tidak disarankan tanpa logging hasil)

**Respons:** mengikuti respons POST.

---

### 1.3 Check Message Status (Deprecated)
**URL:** https://api.fonnte.com/status

**Kegunaan:** Cek status pesan berdasarkan `id` (dari response send).

**Catatan:** Endpoint ini **deprecated**. Disarankan gunakan **Webhook Update Message Status**.

**Parameter:**
- `id` (int, wajib)

**Respons sukses:**
```json
{
  "id": 2,
  "message_status": "sent",
  "status": true
}
```

**Status yang mungkin:** `sent`, `pending`, `waiting`, `invalid`, `expired`, `processing`, `url unreachable`.

---

### 1.4 Update WhatsApp Group List
**URL:** https://api.fonnte.com/fetch-group

**Kegunaan:** Sinkronisasi daftar grup WA yang diikuti.

**Catatan penting:**
- Dipanggil **hanya saat** join grup baru atau belum pernah dipanggil.
- **Jangan sering dipanggil** (risiko banned).

**Respons sukses:**
```json
{
  "detail": "update whatsapp group list finished",
  "status": true
}
```

---

### 1.5 Validate Number
**URL:** https://api.fonnte.com/validate

**Kegunaan:** Validasi apakah nomor terdaftar di WhatsApp.

**Parameter:**
- `target` (string, wajib, dipisah koma)
- `countryCode` (int/string, opsional, default 62)

**Batasan:**
- Maks 500 nomor per request.
- Jangan jalankan check bersamaan (dapat mengganggu proses sebelumnya).

**Respons sukses:**
```json
{
  "not_registered": [],
  "registered": [],
  "status": true
}
```

---

## 2) Rekomendasi Desain Integrasi di WartelPas

### 2.1 Kebutuhan Fitur
1) **Manajemen Nomor Tujuan**
   - CRUD nomor (format `62xxx`) + validasi via API validate.
2) **Template Pesan**
   - Template teks + attachment PDF (rekap harian).
3) **Queue/Log Pengiriman**
   - Status: pending, sent, failed, invalid, expired.
4) **Trigger**
   - Otomatis setelah settlement selesai atau jadwal harian.

### 2.2 Strategi Pengiriman Laporan PDF
- Gunakan **POST send** dengan `url` menuju file PDF rekap harian (public/accessible).
- Pastikan file memenuhi **file limitation** Fonnte (ukuran/format).
- Simpan `requestid`, `id`, dan response ke log DB untuk audit.

### 2.3 Struktur Folder Implementasi
- Halaman konfigurasi/monitoring WhatsApp: `report/whatsapp/index.php` (akses via `?report=whatsapp`).
- Folder penyimpanan PDF laporan: `report/pdf/` (akses langsung dibatasi).

### 2.3 Keamanan Token
- Token **jangan disimpan di UI**.
- Gunakan konfigurasi server (ENV/ini) dan baca di backend.
- Hindari pemakaian endpoint GET karena token terekspos.

---

## 3) Checklist Teknis Implementasi
1) **Config**
   - Simpan token di env/ini (server-side).
2) **DB**
   - Tabel nomor, template, log pengiriman, status pengiriman.
3) **Endpoint**
   - Endpoint internal untuk trigger send (POST-only).
4) **Generate PDF**
   - Pastikan link publik/reachable (bisa via folder temp + tokenized link).
5) **Retry**
   - Jika `connectOnly=false`, antre otomatis saat device connect.
6) **Monitoring**
   - Log response JSON lengkap + requestid.

---

## 4) Catatan Penting dari Dokumentasi Fonnte
- `target`, `delay`, `countryCode` **harus string** (bukan angka).
- `delay` hanya bekerja pada **multi-target**.
- `sequence=true` **menonaktifkan** delay/schedule/followup.
- `url` harus **link file langsung** dan publik.
- `validate number` maksimal **500 nomor** per request.

---

## 5) Next Step (Jika Disetujui)
1) Buat skema DB nomor + log pengiriman.
2) Buat service PHP: `send_whatsapp_report($date, $targets)`.
3) Integrasi ke proses settlement harian dan/atau scheduler.
4) Tambahkan UI manajemen nomor + status pengiriman.

---

Dokumen ini siap dipakai sebagai acuan implementasi dan akan diperbarui jika ada perubahan kebutuhan bisnis atau API Fonnte.
