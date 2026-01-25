Setelah menganalisis kode `users.php`, saya menemukan beberapa masalah dalam implementasi filter status, profile, dan blok. Berikut adalah hasil audit dan rekomendasi perbaikan:

## MASALAH YANG DIIDENTIFIKASI:

### 1. **Filter Status Tidak Konsisten**
- Filter status `ready` menggunakan logika kompleks `$is_ready_now` yang tidak selalu sesuai
- Status `online` memerlukan `$f_blok != ''` (baris 1221) - ini bisa menyebabkan user online tanpa blok tidak muncul
- Filter `used` mengecualikan user yang `$is_active || $is_rusak` (baris 1225) - seharusnya hanya berdasarkan penggunaan

### 2. **Filter Profile Tidak Akurat**
- Fungsi `detect_profile_kind_summary()` dan `detect_profile_kind_from_comment()` memiliki pola regex yang terbatas
- Hanya mendeteksi "10 menit" dan "30 menit" secara eksplisit
- Profile lain dianggap "other" dan mungkin tidak terfilter dengan benar

### 3. **Filter Blok Bermasalah**
- Dropdown blok hanya mengambil dari `$router_users` (baris 1320-1329), tidak termasuk data historis
- Filter blok menggunakan `strcasecmp()` (baris 1228) yang sensitif terhadap format
- Ekstraksi blok dari komentar bisa gagal untuk format non-standar

### 4. **Logika Filter Bertumpuk dan Overkompleks**
- Terlalu banyak kondisi bertingkat dalam loop foreach utama
- Penentuan status dilakukan berulang kali dengan logika yang tumpang tindih
- Filter tanggal menggunakan `last_used_filter` yang bisa ambigu

## REKOMENDASI PERBAIKAN:

### 1. **Sederhanakan Logika Status**
```php
// Ganti bagian penentuan status dengan pendekatan lebih sederhana
function determine_user_status($user_data, $active_map, $hist_data) {
    $name = $user_data['name'] ?? '';
    $comment = $user_data['comment'] ?? '';
    $disabled = $user_data['disabled'] ?? 'false';
    $bytes = (int)($user_data['bytes-in'] ?? 0) + (int)($user_data['bytes-out'] ?? 0);
    $uptime = $user_data['uptime'] ?? '';
    
    // 1. Cek online terlebih dahulu
    if (isset($active_map[$name])) {
        return 'ONLINE';
    }
    
    // 2. Cek rusak (disabled atau ada kata RUSAK)
    if ($disabled === 'true' || stripos($comment, 'RUSAK') !== false) {
        return 'RUSAK';
    }
    
    // 3. Cek retur
    if (stripos($comment, '(Retur)') !== false || stripos($comment, 'Retur Ref:') !== false) {
        return 'RETUR';
    }
    
    // 4. Cek terpakai (ada usage atau pernah login)
    $has_usage = $bytes > 50 || ($uptime !== '' && $uptime !== '0s');
    $hist_has_data = !empty($hist_data['login_time_real']) || !empty($hist_data['logout_time_real']);
    
    if ($has_usage || $hist_has_data) {
        return 'TERPAKAI';
    }
    
    // 5. Default: ready
    return 'READY';
}
```

### 2. **Perbaiki Deteksi Profile**
```php
function detect_profile_kind_improved($profile, $comment) {
    $text = strtolower($profile . ' ' . $comment);
    
    // Cari pola durasi
    if (preg_match('/(\d+)\s*(?:menit|m|min)/', $text, $matches)) {
        $minutes = (int)$matches[1];
        if ($minutes == 10) return '10';
        if ($minutes == 30) return '30';
        if ($minutes == 60) return '60';
        return (string)$minutes;
    }
    
    // Cari dalam format "10menit", "30m", dll
    if (preg_match('/(\d+)(?:menit|m)\b/', $text, $matches)) {
        $minutes = (int)$matches[1];
        if ($minutes == 10) return '10';
        if ($minutes == 30) return '30';
        return (string)$minutes;
    }
    
    return 'other';
}
```

### 3. **Perbaiki Ekstraksi dan Filter Blok**
```php
// Fungsi ekstraksi blok yang lebih robust
function extract_blok_name_improved($comment) {
    if (empty($comment)) return '';
    
    // Pattern 1: "BLOK-A", "Blok B", "blok-12"
    if (preg_match('/\b(blok[-\s]*[a-z0-9]+)\b/i', $comment, $matches)) {
        $blok = strtoupper(trim($matches[1]));
        // Normalisasi format
        $blok = preg_replace('/^BLOK\s*/', 'BLOK-', $blok);
        $blok = preg_replace('/^BLOK\-*/', 'BLOK-', $blok);
        return $blok;
    }
    
    // Pattern 2: "A", "B12", "C-1" dalam konteks tertentu
    if (preg_match('/\b([A-Z](?:[-\s]?\d+)?)\b/', $comment, $matches)) {
        $potential = strtoupper($matches[1]);
        // Jika panjang pendek dan terlihat seperti kode blok
        if (strlen($potential) <= 5 && preg_match('/^[A-Z]/', $potential)) {
            return 'BLOK-' . $potential;
        }
    }
    
    return '';
}

// Di bagian pengumpulan list blok:
$list_blok = [];
$all_sources = array_merge($router_users, $display_data); // Gabung dengan data tampilan
foreach ($all_sources as $item) {
    $blok = extract_blok_name_improved($item['comment'] ?? '');
    if ($blok && !in_array($blok, $list_blok)) {
        $list_blok[] = $blok;
    }
}
sort($list_blok, SORT_NATURAL | SORT_FLAG_CASE);
```

### 4. **Sederhanakan Logika Filter Utama**
```php
// Pisahkan logika filter ke fungsi terpisah
function apply_filters($user_data, $filters) {
    // Filter status
    if ($filters['status'] !== 'all') {
        $user_status = $user_data['status'];
        if ($filters['status'] === 'ready' && $user_status !== 'READY') return false;
        if ($filters['status'] === 'online' && $user_status !== 'ONLINE') return false;
        if ($filters['status'] === 'used' && $user_status !== 'TERPAKAI') return false;
        if ($filters['status'] === 'rusak' && $user_status !== 'RUSAK') return false;
        if ($filters['status'] === 'retur' && $user_status !== 'RETUR') return false;
    }
    
    // Filter profile
    if ($filters['profile'] !== 'all') {
        $profile_kind = detect_profile_kind_improved(
            $user_data['profile'] ?? '',
            $user_data['comment'] ?? ''
        );
        if ($profile_kind !== $filters['profile']) return false;
    }
    
    // Filter blok
    if (!empty($filters['blok'])) {
        $user_blok = extract_blok_name_improved($user_data['comment'] ?? '');
        if (strcasecmp($user_blok, $filters['blok']) !== 0) return false;
    }
    
    // Filter search
    if (!empty($filters['search'])) {
        $search_terms = array_filter(array_map('trim', preg_split('/[,\s]+/', $filters['search'])));
        $found = false;
        foreach ($search_terms as $term) {
            if (stripos($user_data['name'], $term) !== false ||
                stripos($user_data['comment'], $term) !== false ||
                stripos($user_data['ip'] ?? '', $term) !== false ||
                stripos($user_data['blok'] ?? '', $term) !== false) {
                $found = true;
                break;
            }
        }
        if (!$found) return false;
    }
    
    return true;
}
```

### 5. **Perbaiki Struktur Loop Utama**
```php
// Restrukturisasi loop utama:
$display_data = [];
foreach($all_users as $u) {
    // 1. Ambil data dasar
    $name = $u['name'] ?? '';
    $comment = $u['comment'] ?? '';
    // ... (ambil data lainnya)
    
    // 2. Tentukan status menggunakan fungsi sederhana
    $status = determine_user_status($u, $activeMap, $hist);
    
    // 3. Siapkan array data user
    $user_item = [
        'name' => $name,
        'status' => $status,
        'profile' => $u['profile'] ?? '',
        'blok' => extract_blok_name_improved($comment),
        'comment' => $comment,
        // ... data lainnya
    ];
    
    // 4. Apply filters
    $filters = [
        'status' => $req_status,
        'profile' => $req_prof,
        'blok' => $req_comm,
        'search' => $req_search
    ];
    
    if (!apply_filters($user_item, $filters)) {
        continue;
    }
    
    // 5. Tambahkan ke display_data
    $display_data[] = $user_item;
}
```

### 6. **Tambahkan Debugging yang Lebih Baik**
```php
// Di bagian atas, setelah parameter diterima:
if ($debug_mode) {
    error_log("Filter Parameters:");
    error_log("  Status: " . $req_status);
    error_log("  Profile: " . $req_prof);
    error_log("  Blok: " . $req_comm);
    error_log("  Search: " . $req_search);
    error_log("  Show: " . $req_show . ", Date: " . $filter_date);
}

// Di dalam loop, tambahkan log untuk user yang difilter:
if ($debug_mode && !apply_filters($user_item, $filters)) {
    error_log("User filtered out: " . $name);
    error_log("  User status: " . $status);
    error_log("  User profile: " . $user_item['profile']);
    error_log("  User blok: " . $user_item['blok']);
}
```

## IMPLEMENTASI PRIORITAS:

1. **FIX CRITICAL**: Perbaiki fungsi `extract_blok_name()` untuk menangani lebih banyak format
2. **FIX HIGH**: Sederhanakan logika penentuan status dengan pendekatan berurutan
3. **FIX MEDIUM**: Perbaiki deteksi profile dengan regex yang lebih komprehensif
4. **FIX LOW**: Pisahkan logika filter ke fungsi terpisah untuk maintainability

Dengan perbaikan ini, filter status, profile, dan blok seharusnya bekerja lebih konsisten dan akurat.