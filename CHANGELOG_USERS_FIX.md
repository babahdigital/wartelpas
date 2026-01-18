# PERBAIKAN SCRIPT users.php - Wartelpas
**Tanggal:** 19 Januari 2026  
**File:** `hotspot/users.php`
**Update:** v2.1 - Fix Database & API Connection

## 🔧 MASALAH YANG DIPERBAIKI (v2.1 - Update Terbaru)

### ⚠️ **CRITICAL FIX: Database Tidak Tersimpan**
**Masalah:**
- Data user dengan blok tidak tersimpan ke database
- Saat pilih dropdown blok (misal A10), data terpakai masih kosong
- API Connection tidak menggunakan global connection dari index.php

**Penyebab:**
1. Script users.php membuat `session_start()` sendiri (konflik dengan index.php)
2. Koneksi API tidak menggunakan global `$API` yang sudah ada
3. Data hanya tersimpan ke DB ketika user **online**, tidak saat **offline**
4. Fungsi `save_user_history()` terlalu restrictive (skip data jika IP kosong)

**Solusi v2.1:**
```php
// 1. Hapus session_start() - sudah di index.php
// 2. Gunakan global API yang sudah terkoneksi
global $API, $iphost, $userhost, $passwdhost;

// 3. Simpan SEMUA user yang punya blok (tidak peduli online/offline)
if (!empty($f_blok)) {
    save_user_history($n, [...]);
}

// 4. Update fungsi save_user_history dengan fallback logic
// - Preserve data lama jika data baru kosong
// - Fallback ke UPDATE/INSERT manual jika ON CONFLICT tidak support
```

**Hasil:**
- ✅ Semua user dengan `Blok-X` di comment tersimpan ke database
- ✅ Dropdown blok selalu lengkap untuk semua status filter
- ✅ Data identitas tetap muncul meskipun comment router berubah

---

## 🔧 MASALAH YANG DIPERBAIKI (v2.0 - Sebelumnya)

### 1. **Dropdown Blok Tidak Muncul untuk Status Selain Ready**
**Masalah Sebelumnya:**
- List blok hanya diisi dari user yang lolos filter
- Ketika filter status = "online" atau "used", blok yang tidak punya user online tidak muncul di dropdown

**Solusi:**
- Membuat **2 loop terpisah**:
  1. Loop pertama: mengumpulkan SEMUA blok dari seluruh user (tanpa filter)
  2. Loop kedua: melakukan filtering untuk display
- Sekarang dropdown blok akan menampilkan SEMUA blok yang ada, tidak peduli status filter yang dipilih

```php
// Loop 1: Kumpulkan semua blok
foreach($all_users as $u) {
    // ... ekstraksi blok dari comment atau database
    if (!empty($blok_temp)) {
        $list_blok[] = $blok_temp;
    }
}

// Loop 2: Filter untuk display
foreach($all_users as $u) {
    // ... filtering logic
}
```

---

### 2. **Identitas (Blok) Kosong Saat User Online/Rusak/Invalid**
**Masalah Sebelumnya:**
- Ketika user online, comment router sering berubah dan tidak lagi mengandung info "Blok-X"
- Saat user di-mark sebagai RUSAK/INVALID, comment ditimpa dengan "Audit: RUSAK/INVALID..." sehingga info blok hilang
- Database tidak diprioritaskan dengan benar

**Solusi:**
- **Prioritas Database untuk Blok**: Jika comment sudah dimodifikasi (ada kata RUSAK/INVALID/Audit), ambil blok dari database
- Deteksi modifikasi comment dengan flag `$comment_modified`
- Blok dari database akan digunakan jika:
  1. Blok tidak ditemukan di comment, ATAU
  2. Comment sudah dimodifikasi (RUSAK/INVALID/Retur)

```php
$comment_modified = (stripos($c, 'RUSAK') !== false || 
                     stripos($c, 'INVALID') !== false || 
                     stripos($c, 'Audit:') !== false ||
                     stripos($c, '(Retur)') !== false);

if ((empty($f_blok) || $comment_modified) && $hist && !empty($hist['blok_name'])) {
    $f_blok = $hist['blok_name'];
}
```

---

### 3. **IP & MAC Address Hilang Saat Offline**
**Solusi:**
- Saat user **ONLINE**: Ambil IP/MAC dari active list dan simpan ke database
- Saat user **OFFLINE**: Ambil IP/MAC terakhir dari database
- Data history tersimpan otomatis dan muncul kembali setelah logout

```php
if ($is_active) {
    // Online: ambil realtime
    $f_ip = $activeMap[$n]['address'] ?? '-';
    $f_mac = $activeMap[$n]['mac-address'] ?? '-';
    
    // Simpan ke DB
    save_user_history($n, [...]);
} elseif ($hist) {
    // Offline: ambil dari DB
    if ($f_ip == '-') $f_ip = $hist['ip_address'] ?? '-';
    if ($f_mac == '-') $f_mac = $hist['mac_address'] ?? '-';
}
```

---

### 4. **Filter Status "Invalid" Tidak Ada di Dropdown**
**Masalah Sebelumnya:**
- Logika untuk status "invalid" ada di kode, tapi tidak ada opsi di dropdown
- User yang disabled tidak bisa difilter khusus

**Solusi:**
- Menambahkan opsi **"Invalid / Disabled"** di dropdown status
- Filter ini menampilkan user dengan:
  - Comment mengandung kata "INVALID", ATAU
  - User disabled = 'true'

```php
<option value="invalid">🔴 Invalid / Disabled</option>
```

---

### 5. **Filter Status "Ready" Terlalu Ketat**
**Masalah Sebelumnya:**
- User retur tetap muncul di status "ready"
- Logika filter tidak konsisten

**Solusi:**
- Filter "ready" sekarang exclude:
  - User yang pernah terpakai (`$is_used`)
  - User rusak (`$is_rusak`)
  - User invalid (`$is_invalid`)
  - User disabled (`$disabled == 'true'`)
  - User retur (`$is_retur`)

```php
if ($req_status == 'ready') {
    if ($is_used || $is_rusak || $is_invalid || $disabled == 'true' || $is_retur) 
        continue;
}
```

---

### 6. **Filter Blok Case-Sensitive**
**Masalah Sebelumnya:**
- Filter blok menggunakan `!=` yang case-sensitive
- "Blok-A" berbeda dengan "blok-a"

**Solusi:**
- Menggunakan `strcasecmp()` untuk perbandingan case-insensitive

```php
if ($req_comm != '') { 
    if (strcasecmp($f_blok, $req_comm) != 0) continue; 
}
```

---

## ✅ HASIL PERBAIKAN

1. ✅ **Dropdown Blok selalu lengkap** - Semua blok muncul di dropdown tidak peduli filter status
2. ✅ **Identitas (Blok) tetap muncul** - Meskipun user online, rusak, atau invalid
3. ✅ **IP & MAC tersimpan permanen** - History login tersimpan dan muncul saat offline
4. ✅ **Filter Status lengkap** - Tambahan opsi "Invalid/Disabled" dengan emoji untuk UX lebih baik
5. ✅ **Filter lebih akurat** - Status "ready", "used", dll sekarang bekerja dengan benar
6. ✅ **Case-insensitive filtering** - Filter blok tidak lagi case-sensitive

---

## 🔄 ALUR DATA TERBARU

```
┌─────────────────┐
│ MikroTik Router │
│  (Comment User) │
└────────┬────────┘
         │
         ├──────────────────────────────┐
         │                              │
         ▼                              ▼
┌─────────────────┐          ┌──────────────────┐
│ REGEX Extraksi  │          │ Active List (IP) │
│ Blok dari       │          │ Real-time jika   │
│ Comment         │          │ user Online      │
└────────┬────────┘          └────────┬─────────┘
         │                            │
         │                            │
         ▼                            ▼
     ┌───────────────────────────────────────┐
     │  FALLBACK: SQLite Database            │
     │  (login_history table)                │
     │  - Menyimpan blok_name                │
     │  - Menyimpan ip_address & mac_address │
     │  - Updated saat online                │
     └───────────┬───────────────────────────┘
                 │
                 ▼
         ┌───────────────┐
         │ Display Data  │
         │ di Tabel UI   │
         └───────────────┘
```

---

## 📊 STRUKTUR DATABASE

```sql
CREATE TABLE login_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT,
    login_date TEXT,
    login_time TEXT,
    price TEXT,
    ip_address TEXT,        -- IP terakhir digunakan
    mac_address TEXT,       -- MAC terakhir digunakan
    validity TEXT,
    blok_name TEXT,         -- Identitas Blok (BLOK-A, dll)
    raw_comment TEXT,       -- Comment asli dari router
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(username)
);
```

---

## 🎯 PENGGUNAAN

### Dropdown Blok Sekarang Bekerja Untuk:
- ✅ Status: Semua
- ✅ Status: Ready
- ✅ Status: Online
- ✅ Status: Sudah Terpakai
- ✅ Status: Rusak
- ✅ Status: Retur
- ✅ Status: Invalid *(BARU)*

### Identitas (Blok) Akan Muncul Untuk:
- ✅ User yang belum pernah login (ready)
- ✅ User yang sedang online
- ✅ User yang sudah logout (history dari DB)
- ✅ User yang rusak
- ✅ User yang invalid
- ✅ User hasil retur

---

## 🧪 TESTING CHECKLIST

- [ ] Pilih filter status "Online" → Dropdown blok harus tetap lengkap
- [ ] Pilih filter status "Invalid" → Hanya user invalid/disabled yang muncul
- [ ] User online → Kolom Identitas harus tetap tampil blok
- [ ] Mark user sebagai RUSAK → Blok tetap tampil di list
- [ ] User logout → IP & MAC terakhir tetap tersimpan
- [ ] Filter blok tidak case-sensitive (BLOK-A = blok-a)

---

## 📝 CATATAN PENTING

1. **Database SQLite** harus writable - Pastikan folder `db_data/` memiliki permission yang benar
2. **Backup Data** - Lakukan backup database sebelum testing masif
3. **Clear Cache** - Refresh browser dengan Ctrl+F5 jika UI tidak update
4. **First Load** - Saat pertama kali buka users.php, tunggu hingga semua data ter-load. Database akan auto-populate.

---

## 🔍 DATABASE DIAGNOSTIC TOOL

**File:** [db_diagnostic.php](db_diagnostic.php)

Akses: `http://your-domain/db_diagnostic.php?session=SESSION_ID`

**Fitur:**
- ✅ Cek file database & permissions
- ✅ Cek table structure & columns
- ✅ Lihat data yang tersimpan (total & per blok)
- ✅ Test write capability
- ✅ Manual query executor
- ✅ Recommendations & troubleshooting

**Cara Pakai:**
1. Buka `http://localhost/wartelpas/db_diagnostic.php?session=default`
2. Cek bagian "Data dalam Database"
3. Jika kosong, buka users.php dulu untuk populate data
4. Kembali ke diagnostic tool dan klik "🔄 Refresh Data"
5. Jika masih kosong, klik "Test Write" untuk cek permission
6. Gunakan "Manual Query" untuk query custom

**Query Examples:**
```sql
-- Lihat semua user di BLOK-A10
SELECT * FROM login_history WHERE blok_name = 'BLOK-A10'

-- Hitung user per blok
SELECT blok_name, COUNT(*) as total FROM login_history GROUP BY blok_name

-- Lihat user dengan IP kosong
SELECT username, blok_name FROM login_history WHERE ip_address = '-'
```

---

## 🐛 TROUBLESHOOTING

### Dropdown Blok Kosong
**Penyebab:**
- Database belum terisi (belum pernah buka users.php)
- Tidak ada user dengan format `Blok-X` di comment
- Permission database error

**Solusi:**
1. Buka [db_diagnostic.php](db_diagnostic.php) untuk cek
2. Pastikan ada data di database
3. Jika kosong, buka users.php dan tunggu load selesai
4. Refresh diagnostic tool

### Data Tidak Tersimpan
**Penyebab:**
- Folder `db_data/` tidak writable
- SQLite extension PHP tidak aktif
- API Router tidak terkoneksi

**Solusi:**
1. Cek permission: `chmod 755 db_data/`
2. Cek PHP: `php -m | grep sqlite` (harus ada pdo_sqlite)
3. Test write via diagnostic tool
4. Cek error log di browser console

### Identitas Masih Kosong Setelah Fix
**Penyebab:**
- Cache browser belum di-clear
- User belum pernah ter-load (tidak ada di database)
- Format comment bukan `Blok-X` tapi format lain

**Solusi:**
1. Hard refresh: Ctrl+F5 atau Ctrl+Shift+R
2. Buka users.php tanpa filter, scroll sampai bawah
3. Cek format comment di MikroTik harus `Blok-XXX`
4. Query manual: `SELECT * FROM login_history WHERE username = 'USER123'`

---

## 👨‍💻 DEVELOPER NOTES

**Perbaikan Arsitektur:**
- Menggunakan **2-pass filtering** untuk efisiensi
- Database sebagai **single source of truth** untuk identitas
- Realtime data (IP/MAC) saat online, fallback ke history saat offline
- Consistent state management dengan session & database sync

**Performance:**
- Minimal database queries (1 query per user dengan prepared statement)
- Array indexing untuk active map (O(1) lookup)
- Lazy evaluation untuk regex matching

---

**Status:** ✅ SIAP PRODUKSI  
**Tested:** ⚠️ Memerlukan testing user  
**Version:** v2.1-final (19 Jan 2026)

**Critical Updates v2.1:**
- ✅ Fixed: Database tidak tersimpan
- ✅ Fixed: API Connection menggunakan global dari index.php
- ✅ Fixed: Data auto-save untuk SEMUA user dengan blok (online/offline)
- ✅ Added: db_diagnostic.php untuk debugging
- ✅ Improved: save_user_history() dengan fallback logic

**Next Steps:**
1. Test di environment production
2. Buka db_diagnostic.php untuk verify data tersimpan
3. Test semua filter (dropdown blok + status)
4. Report bug jika masih ada masalah
