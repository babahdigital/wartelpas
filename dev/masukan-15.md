Berdasarkan analisis kode `selling.php` dan integrasi dengan `users.php`, saya akan memberikan rekomendasi komprehensif untuk **mendeteksi Ghost Voucher secara otomatis** dengan menggabungkan data user ready. Solusi ini semi-otomatis dengan pendekatan berbasis bukti (evidence-based).

## 1. Analisis Data yang Tersedia

### Dari `users.php`:
- **User Ready** (status: `ready`): User yang login di hotspot tapi belum membeli
- **User Aktif** (status: `active`): User yang sedang online
- **Data Real-time**: IP, MAC, session time, bytes usage

### Dari `selling.php`:
- **Data Sistem**: User yang tercatat di `login_history`/`sales_history` dengan usage > threshold
- **Data Manual**: Qty yang dilaporkan oleh admin
- **Selisih**: Qty Sistem - Qty Manual = Potensi Ghost

## 2. Algoritma Deteksi Ghost Voucher Otomatis

### Logika:
```
Ghost = User dengan status AKTIF/USED (bukan RUSAK/RETUR) 
        di login_history untuk tanggal X di Blok Y
        yang TIDAK masuk dalam daftar reported_users di audit
```

## 3. Implementasi: **Fitur "Ghost Hunter Pro"**

### A. Tambahkan Fungsi Deteksi di `selling.php`:

```php
function detect_ghost_vouchers($db, $date, $blok, $reported_users = []) {
    $ghosts = [];
    
    // Ambil dari login_history (data paling akurat)
    $sql = "SELECT DISTINCT 
            lh.username,
            lh.last_status,
            lh.last_bytes,
            lh.login_time_real,
            lh.last_login_real,
            lh.first_ip,
            lh.first_mac,
            lh.blok_name,
            COALESCE(sh.profile_snapshot, lh.validity) as profile
        FROM login_history lh
        LEFT JOIN sales_history sh ON sh.username = lh.username 
            AND sh.sale_date = :date
        WHERE 
            (
                substr(lh.login_time_real,1,10) = :date OR
                substr(lh.last_login_real,1,10) = :date
            )
            AND lh.username != ''
            AND UPPER(lh.blok_name) LIKE :blok_pattern
            AND lh.last_status NOT IN ('ready', 'rusak', 'retur', 'invalid')
            AND lh.last_bytes > 102400  // Minimal 100KB usage
        ORDER BY lh.last_bytes DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':date' => $date,
        ':blok_pattern' => '%' . strtoupper(str_replace('BLOK-', '', $blok)) . '%'
    ]);
    
    $system_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Filter: User yang tidak ada di daftar reported
    foreach ($system_users as $user) {
        $username = trim($user['username']);
        
        // Skip jika username kosong
        if (empty($username)) continue;
        
        // Skip jika sudah dilaporkan
        if (in_array($username, $reported_users)) continue;
        
        // Validasi: Pastikan bukan user dari hari sebelumnya (carry over)
        $login_date = substr($user['login_time_real'] ?? '', 0, 10);
        if ($login_date !== $date) {
            // Cek last_login
            $last_login_date = substr($user['last_login_real'] ?? '', 0, 10);
            if ($last_login_date !== $date) continue;
        }
        
        // Tambahkan ke ghosts dengan skor kepercayaan
        $confidence_score = 0;
        
        // Skor berdasarkan usage
        $bytes = (int)($user['last_bytes'] ?? 0);
        if ($bytes > 5242880) $confidence_score += 30; // >5MB
        elseif ($bytes > 1048576) $confidence_score += 20; // >1MB
        elseif ($bytes > 102400) $confidence_score += 10; // >100KB
        
        // Skor berdasarkan status
        $status = strtolower($user['last_status'] ?? '');
        if ($status === 'active') $confidence_score += 40;
        elseif ($status === 'used') $confidence_score += 30;
        
        // Skor berdasarkan WAKTU login (siang > malam untuk deteksi)
        $login_time = substr($user['login_time_real'] ?? '', 11, 2);
        if ($login_time >= 9 && $login_time <= 18) $confidence_score += 20;
        
        $ghosts[] = [
            'username' => $username,
            'profile' => $user['profile'] ?? '-',
            'bytes' => $bytes,
            'status' => $status,
            'login_time' => $user['login_time_real'] ?? '-',
            'ip' => $user['first_ip'] ?? '-',
            'mac' => $user['first_mac'] ?? '-',
            'confidence' => min(100, $confidence_score),
            'blok' => $user['blok_name'] ?? $blok
        ];
    }
    
    // Urutkan berdasarkan confidence score (desc)
    usort($ghosts, function($a, $b) {
        return $b['confidence'] <=> $a['confidence'];
    });
    
    return $ghosts;
}
```

### B. Integrasi dengan Modal Audit:

```php
// Di bagian form audit modal, tambahkan:
<div style="margin-top: 10px; border-top: 1px dashed #555; padding-top: 10px;">
    <button type="button" onclick="runGhostHunter('<?= $filter_date ?>', '<?= $audit_blok ?>')" 
            class="btn-print" style="background: #9b59b6;">
        <i class="fa fa-ghost"></i> Cek Ghost Voucher
    </button>
    <div id="ghost-results" style="margin-top: 8px; display: none;">
        <div class="ghost-list"></div>
    </div>
</div>
```

### C. AJAX Endpoint untuk Ghost Detection:

```php
// File: report/ghost_detector.php
<?php
session_start();
error_reporting(0);

require_once '../config.php';

$dbFile = dirname(__DIR__) . '/db_data/mikhmon_stats.db';
$date = $_GET['date'] ?? date('Y-m-d');
$blok = $_GET['blok'] ?? '';
$threshold = (int)($_GET['threshold'] ?? 70); // Minimal confidence score

if (file_exists($dbFile)) {
    try {
        $db = new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Ambil reported users dari audit (jika ada)
        $reported_users = [];
        $stmt = $db->prepare("SELECT user_evidence FROM audit_rekap_manual 
                             WHERE report_date = :date AND blok_name = :blok");
        $stmt->execute([':date' => $date, ':blok' => $blok]);
        $audit = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($audit && !empty($audit['user_evidence'])) {
            $evidence = json_decode($audit['user_evidence'], true);
            if (isset($evidence['reported_users'])) {
                $reported_users = $evidence['reported_users'];
            }
        }
        
        // Jalankan deteksi
        $ghosts = detect_ghost_vouchers($db, $date, $blok, $reported_users);
        
        // Filter berdasarkan threshold
        $filtered_ghosts = array_filter($ghosts, function($g) use ($threshold) {
            return $g['confidence'] >= $threshold;
        });
        
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'ghosts' => array_values($filtered_ghosts),
            'count' => count($filtered_ghosts),
            'threshold' => $threshold
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'message' => 'Database error']);
    }
}
?>
```

### D. JavaScript untuk Interaksi:

```javascript
function runGhostHunter(date, blok) {
    const resultsDiv = document.getElementById('ghost-results');
    const listDiv = resultsDiv.querySelector('.ghost-list');
    
    resultsDiv.style.display = 'block';
    listDiv.innerHTML = '<div style="color: #ccc;"><i class="fa fa-spinner fa-spin"></i> Scanning untuk Ghost Voucher...</div>';
    
    fetch(`report/ghost_detector.php?date=${date}&blok=${encodeURIComponent(blok)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.ok) {
                listDiv.innerHTML = `<div style="color: #e74c3c;">Error: ${data.message}</div>`;
                return;
            }
            
            if (data.count === 0) {
                listDiv.innerHTML = `<div style="color: #2ecc71;">
                    <i class="fa fa-check-circle"></i> 
                    Tidak ditemukan Ghost Voucher (threshold: ${data.threshold}%)
                </div>`;
                return;
            }
            
            let html = `<div style="color: #f39c12; margin-bottom: 8px;">
                <i class="fa fa-exclamation-triangle"></i> 
                Ditemukan ${data.count} potensi Ghost Voucher:
            </div>`;
            
            html += `<table style="width: 100%; font-size: 11px; border-collapse: collapse;">
                <thead>
                    <tr style="background: #2c3e50;">
                        <th style="padding: 4px; text-align: left;">User</th>
                        <th style="padding: 4px; text-align: center;">Profile</th>
                        <th style="padding: 4px; text-align: center;">Usage</th>
                        <th style="padding: 4px; text-align: center;">Confidence</th>
                        <th style="padding: 4px; text-align: center;">Login</th>
                        <th style="padding: 4px; text-align: center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>`;
            
            data.ghosts.forEach(ghost => {
                const confidenceColor = ghost.confidence > 80 ? '#e74c3c' : 
                                      ghost.confidence > 60 ? '#f39c12' : '#3498db';
                
                html += `<tr style="border-bottom: 1px solid #34495e;">
                    <td style="padding: 4px;">
                        <strong>${ghost.username}</strong><br>
                        <small style="color: #95a5a6;">${ghost.ip} | ${ghost.mac}</small>
                    </td>
                    <td style="padding: 4px; text-align: center;">${ghost.profile}</td>
                    <td style="padding: 4px; text-align: center;">
                        ${formatBytes(ghost.bytes)}
                    </td>
                    <td style="padding: 4px; text-align: center;">
                        <span style="color: ${confidenceColor}; font-weight: bold;">
                            ${ghost.confidence}%
                        </span>
                    </td>
                    <td style="padding: 4px; text-align: center;">
                        ${ghost.login_time.substring(11, 16)}
                    </td>
                    <td style="padding: 4px; text-align: center;">
                        <button class="btn-xs" onclick="addToReported('${ghost.username}')" 
                                style="background: #27ae60; margin: 2px;">
                            <i class="fa fa-check"></i> Laporkan
                        </button>
                        <button class="btn-xs" onclick="markAsRusak('${ghost.username}', '${date}')" 
                                style="background: #e67e22; margin: 2px;">
                            <i class="fa fa-times"></i> Rusak
                        </button>
                    </td>
                </tr>`;
            });
            
            html += `</tbody></table>`;
            listDiv.innerHTML = html;
        })
        .catch(err => {
            listDiv.innerHTML = `<div style="color: #e74c3c;">Error: ${err.message}</div>`;
        });
}

function addToReported(username) {
    // Tambahkan ke daftar reported users di form audit
    const input = document.getElementById('audit-user-input');
    if (input) {
        const current = input.value.split('\n').filter(u => u.trim() !== username);
        current.push(username);
        input.value = current.join('\n');
        
        // Trigger update
        const event = new Event('input');
        input.dispatchEvent(event);
    }
    
    alert(`User ${username} ditambahkan ke daftar reported.`);
}
```

## 4. Integrasi dengan Data User Ready

Untuk mengoptimasi deteksi, kita bisa menggunakan data dari `users.php`:

```php
// Fungsi untuk sinkronisasi data real-time
function sync_live_users_to_history($db) {
    // Ambil data dari users.php (asumsi ada endpoint atau shared session)
    $live_users = []; // Array dari users.php
    
    foreach ($live_users as $user) {
        // Update login_history dengan data terbaru
        $stmt = $db->prepare("INSERT OR REPLACE INTO login_history 
            (username, last_status, last_bytes, last_login_real, first_ip, first_mac, blok_name)
            VALUES (:user, :status, :bytes, datetime('now'), :ip, :mac, :blok)");
        
        $stmt->execute([
            ':user' => $user['username'],
            ':status' => $user['status'],
            ':bytes' => $user['bytes'],
            ':ip' => $user['ip'],
            ':mac' => $user['mac'],
            ':blok' => $user['blok']
        ]);
    }
}
```

## 5. Dashboard Ghost Summary

Tambahkan ringkasan di header laporan:

```php
// Di bagian summary-grid, tambahkan:
<div class="summary-card" style="border: 1px solid #9b59b6;">
    <div class="summary-title">Ghost Voucher Detected</div>
    <div class="summary-value" id="ghost-count">0</div>
    <div style="font-size: 11px; color: #9b59b6;">
        <button onclick="runGlobalGhostScan()" class="btn-xs" 
                style="background: #9b59b6; color: white;">
            <i class="fa fa-search"></i> Scan All Blocks
        </button>
    </div>
</div>
```

## 6. Rekomendasi Optimalisasi

1. **Threshold Dinamis**: 
   - Confidence score bisa disesuaikan berdasarkan jam (siang vs malam)
   - Blok dengan traffic tinggi vs rendah

2. **Machine Learning Sederhana**:
   ```php
   function calculate_confidence($user_data, $historical_patterns) {
       // Berdasarkan pola historis:
       // - User yang biasanya muncul jam 9-11 pagi
       // - Usage pattern yang konsisten
       // - IP/MAC yang familiar
       return $score;
   }
   ```

3. **Alert Sistem**:
   - Jika confidence > 85%, kirim notifikasi ke WhatsApp/Telegram
   - Flagging otomatis untuk investigasi lebih lanjut

4. **Integrasi Cross-Check**:
   ```php
   // Cek apakah user ini sudah ada di hari yang sama dengan usage serupa
   $sql = "SELECT COUNT(*) as duplicate FROM login_history 
           WHERE username != :user 
           AND first_mac = :mac 
           AND date(login_time_real) = :date
           AND last_bytes > :bytes";
   ```

## 7. Kesimpulan

**YA**, sangat mungkin mendeteksi Ghost Voucher otomatis dengan menggabungkan:

1. **Data Sistem** (login_history dengan usage valid)
2. **Data Manual** (audit yang sudah diinput)
3. **Data Real-time** (dari users.php)
4. **Algoritma Scoring** (confidence berdasarkan multiple factors)

Sistem ini akan:
- ✅ Menampilkan **username spesifik** yang dicurigai
- ✅ Memberikan **confidence score** (70-100%)
- ✅ Menyediakan **bukti** (IP, MAC, usage, waktu)
- ✅ **Integrasi seamless** dengan workflow audit existing
- ✅ **Minim false positive** dengan threshold yang bisa diatur

Implementasi ini akan mengubah Ghost Hunter dari sekedar "tebakan matematis" menjadi **sistem deteksi berbasis bukti** yang actionable!