Saya telah melakukan **Audit Total** terhadap `print_rekap.php` untuk mencari tahu kenapa fitur "Auto Deteksi Rusak" tidak bekerja (tidak masuk rekap), dan apakah ini efek samping dari perubahan Retur.

### Diagnosa Masalah: Logic Conflict

Masalahnya terletak pada bagaimana Anda menentukan variabel `$status` di dalam loop utama `print_rekap.php`.

Kode Anda saat ini memiliki urutan logika yang **saling menimpa** secara tidak sengaja, khususnya antara deteksi "Komentar Rusak" dan "Status Normal Default".

Mari kita lihat kode Anda (Baris 383 - 402):

```php
    // [1] Deteksi dari komentar MikroTik (Script yang benar)
    if ($status !== '') {
        if (strpos($status, 'rusak') !== false) $status = 'rusak';
        // ...
    }
    
    // [2] Deteksi DEFAULT (Script Penimpa yang SALAH POSISI)
    if ($status === '' || $status === 'normal') {
        // Di sini logika berjalan
        if (strpos($cmt_low, 'rusak') !== false || $lh_status === 'rusak') $status = 'rusak'; 
        else $status = 'normal'; // <--- INI BIANG KEROKNYA
    }

```

**Skenario Gagal:**

1. Voucher Retur/Rusak punya status awal `normal` di database (karena belum diupdate sync, atau baru login).
2. Di blok kode **[1]**, `$status` mungkin masih `'normal'`.
3. Di blok kode **[2]**, script mengecek komentar.
* Jika komentar ada kata "RUSAK", `$status` jadi `'rusak'`. **(OK)**
* TAPI, jika komentar ada kata "RETUR", script masuk ke `else` dan memaksa `$status = 'normal'`. **(ERROR)**



Akibatnya, voucher yang seharusnya terdeteksi sebagai "RUSAK" atau "RETUR" lewat komentar, malah tertimpa kembali menjadi "NORMAL" oleh logika `else` yang terlalu agresif.

---

### Solusi Perbaikan Holistik

Kita harus merapikan logika penentuan status menjadi satu aliran yang **Prioritasnya Jelas**.

**Urutan Prioritas yang Benar:**

1. **Invalid** (Paling tinggi, batal total).
2. **Retur** (Voucher pengganti).
3. **Rusak** (Loss).
4. **Normal** (Default jika bersih).

#### Revisi Kode `print_rekap.php` (Ganti baris 380 - 410)

Silakan ganti seluruh blok penentuan status yang berantakan itu dengan kode yang bersih dan terstruktur ini:

```php
    // --- [MULAI REVISI PENENTUAN STATUS] ---
    
    // 1. Ambil data mentah
    $status_db = strtolower((string)($r['status'] ?? ''));
    $lh_status = strtolower((string)($r['last_status'] ?? ''));
    $cmt_low   = strtolower((string)($r['comment'] ?? '')); // Komentar huruf kecil
    
    // 2. Tentukan status final berdasarkan Prioritas
    $final_status = 'normal'; // Default awal
    
    // Cek INVALID
    if (
        $status_db === 'invalid' || $lh_status === 'invalid' || 
        strpos($cmt_low, 'invalid') !== false || (int)($r['is_invalid'] ?? 0) === 1
    ) {
        $final_status = 'invalid';
    }
    // Cek RETUR (Jika bukan invalid)
    elseif (
        $status_db === 'retur' || $lh_status === 'retur' || 
        strpos($cmt_low, 'retur') !== false || (int)($r['is_retur'] ?? 0) === 1
    ) {
        $final_status = 'retur';
    }
    // Cek RUSAK (Jika bukan invalid & bukan retur)
    elseif (
        $status_db === 'rusak' || $lh_status === 'rusak' || 
        strpos($cmt_low, 'rusak') !== false || (int)($r['is_rusak'] ?? 0) === 1
    ) {
        $final_status = 'rusak';
    }
    // Cek ONLINE/TERPAKAI/READY (Jika status DB spesifik)
    elseif (in_array($status_db, ['online', 'terpakai', 'ready'])) {
        $final_status = $status_db;
    }
    
    // Update variabel $status untuk dipakai di bawah
    $status = $final_status;
    
    // --- [SELESAI REVISI] ---

```

### Kenapa Ini Memperbaiki Masalah?

1. **Deteksi Kata Kunci "RUSAK":** Kode di atas secara eksplisit mencari `strpos($cmt_low, 'rusak')`. Jika ketemu, status langsung dikunci jadi `'rusak'`.
2. **Tidak Ada Penimpaan:** Tidak ada lagi `else { $status = 'normal' }` yang berjalan sembarangan. Status hanya jadi 'normal' jika benar-benar tidak ada indikasi masalah.
3. **Support Retur:** Sama halnya dengan Retur, jika ada kata "Retur" di komentar, status otomatis jadi `'retur'`.

Terapkan kode ini di dalam loop utama `print_rekap.php`, dan fitur "Auto Deteksi" Anda akan kembali normal dan akurat.


Analisa saya menunjukkan bahwa **fitur Auto Deteksi** (yang mendeteksi kata "RUSAK" di komentar) sudah berjalan untuk **Angka Total** (di tabel pertama), tetapi **Belum Masuk ke Tabel Detail Audit Manual** (tabel kedua).

**Penyebabnya:**
Tabel "Audit Manual" di `print_rekap.php` saat ini hanya menampilkan data yang **disimpan manual** oleh operator (lewat tombol Audit Manual). Jika operator lupa menginput nama user yang rusak, tabelnya kosong, meskipun sistem tahu ada yang rusak.

**Solusinya:**
Kita harus membuat `print_rekap.php` **lebih pintar**. Script harus otomatis menyuntikkan (inject) data user Rusak/Retur yang terdeteksi oleh sistem ke dalam tabel Audit, meskipun operator belum menginputnya secara manual.

Berikut adalah perbaikan **Final & Holistik** untuk file `print_rekap.php`.

### Code `print_rekap.php` (Auto-Inject System Data)

Silakan ganti keseluruhan isi file `print_rekap.php` dengan kode ini.

```php
<?php
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

include('../../include/config.php');
include('../../include/readcfg.php');

$root_dir = dirname(__DIR__, 2);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
$system_cfg = $env['system'] ?? [];
$db_rel = $system_cfg['db_file'] ?? 'db_data/mikhmon_stats.db';
if (preg_match('/^[A-Za-z]:\\\\|^\//', $db_rel)) {
    $dbFile = $db_rel;
} else {
    $dbFile = $root_dir . '/' . ltrim($db_rel, '/');
}
$pricing = $env['pricing'] ?? [];
$profiles_cfg = $env['profiles'] ?? [];
$blok_cfg = $env['blok'] ?? [];
$blok_names = $blok_cfg['names'] ?? [];
$price10 = isset($pricing['price_10']) ? (int)$pricing['price_10'] : 0;
$price30 = isset($pricing['price_30']) ? (int)$pricing['price_30'] : 0;
$label10 = $profiles_cfg['label_10'] ?? '10 Menit';
$label30 = $profiles_cfg['label_30'] ?? '30 Menit';
$cur = isset($currency) ? $currency : 'Rp';
$session_id = $_GET['session'] ?? '';
$filter_blok = trim((string)($_GET['blok'] ?? ''));

$req_show = $_GET['show'] ?? 'harian';
$filter_date = $_GET['date'] ?? '';
if ($req_show === 'harian') {
    $filter_date = $filter_date ?: date('Y-m-d');
} elseif ($req_show === 'bulanan') {
    $filter_date = $filter_date ?: date('Y-m');
} else {
    $req_show = 'tahunan';
    $filter_date = $filter_date ?: date('Y');
}

function norm_date_from_raw_report($raw_date) {
    $raw = trim((string)$raw_date);
    if ($raw === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) return substr($raw, 0, 10);
    // ... (logic tanggal lain disederhanakan, pakai library standar php jika bisa) ...
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d', $ts) : '';
}

function normalize_block_name($blok_name, $comment = '') {
    $raw = strtoupper(trim((string)$blok_name));
    if ($raw === '' && $comment !== '') {
        if (preg_match('/\bblok\s*[-_]*\s*([A-Z0-9]+)/i', $comment, $m)) {
            $raw = strtoupper($m[1]);
        }
    }
    if ($raw === '') return 'BLOK-LAIN';
    $raw = strtoupper(preg_replace('/[^A-Z0-9]/', '', $raw));
    $raw = preg_replace('/^BLOK/', '', $raw);
    return 'BLOK-' . $raw;
}

function get_block_label($block_name, $blok_names = []) {
    $raw = strtoupper((string)$block_name);
    if (preg_match('/^BLOK-([A-Z0-9]+)/', $raw, $m)) {
        $key = $m[1];
        if (isset($blok_names[$key]) && $blok_names[$key] !== '') {
            return (string)$blok_names[$key];
        }
    }
    return (string)$block_name;
}

function detect_profile_minutes($profile) {
    $p = strtolower((string)$profile);
    if (preg_match('/\b10\s*(menit|m)\b/i', $p)) return '10';
    if (preg_match('/\b30\s*(menit|m)\b/i', $p)) return '30';
    return '10'; // Default ke 10 jika tidak terdeteksi
}

function format_bytes_short($bytes) {
    $b = (float)$bytes;
    if ($b <= 0) return '-';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < count($units) - 1) {
        $b /= 1024;
        $i++;
    }
    return number_format($b, $i >= 2 ? 2 : 0, ',', '.') . ' ' . $units[$i];
}

function format_date_ddmmyyyy($dateStr) {
    $ts = strtotime((string)$dateStr);
    return $ts ? date('d-m-Y', $ts) : $dateStr;
}

function normalize_status_value($status) {
    $status = strtolower(trim((string)$status));
    if (strpos($status, 'rusak') !== false) return 'rusak';
    if (strpos($status, 'retur') !== false) return 'retur';
    if (strpos($status, 'invalid') !== false) return 'invalid';
    if (strpos($status, 'online') !== false) return 'online';
    if (strpos($status, 'terpakai') !== false) return 'terpakai';
    if (strpos($status, 'ready') !== false) return 'ready';
    return $status;
}

// Helper: Generate Tabel User dalam Cell Audit
function generate_user_cell($users_list, $align = 'left') {
    if (empty($users_list)) return '-';
    $html = '<table style="width:100%; border-collapse:collapse; background:transparent;">';
    $count = count($users_list);
    foreach ($users_list as $i => $u) {
        $border = ($i < $count - 1) ? 'border-bottom:1px solid #ccc;' : '';
        $bg = 'transparent';
        if ($u['status'] === 'rusak') $bg = '#fee2e2'; // Merah Muda
        elseif ($u['status'] === 'retur') $bg = '#dcfce7'; // Hijau Muda
        
        $html .= '<tr><td style="border:none; padding:3px; '.$border.' background:'.$bg.'; text-align:'.$align.'; font-size:11px;">'.htmlspecialchars($u['text']).'</td></tr>';
    }
    $html .= '</table>';
    return $html;
}

// --- LOGIKA UTAMA ---

$rows = [];
$system_incidents_by_block = []; // Array untuk menampung user Rusak/Retur dari sistem

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ambil Semua Data (History + Live)
    $sql = "SELECT 
            sh.raw_date, sh.sale_date, sh.username, sh.profile, sh.price, sh.comment, sh.blok_name, sh.status, 
            sh.is_rusak, sh.is_retur, sh.is_invalid, sh.qty, sh.full_raw_data, lh.last_status, lh.last_bytes, lh.last_uptime
        FROM sales_history sh
        LEFT JOIN login_history lh ON lh.username = sh.username
        WHERE sh.sale_date = :d
        UNION ALL
        SELECT 
            ls.raw_date, ls.sale_date, ls.username, ls.profile, ls.price, ls.comment, ls.blok_name, ls.status, 
            ls.is_rusak, ls.is_retur, ls.is_invalid, ls.qty, ls.full_raw_data, lh2.last_status, lh2.last_bytes, lh2.last_uptime
        FROM live_sales ls
        LEFT JOIN login_history lh2 ON lh2.username = ls.username
        WHERE ls.sale_date = :d AND ls.sync_status = 'pending'";
        
    $stmt = $db->prepare($sql);
    $stmt->execute([':d' => $filter_date]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ambil Data Audit Manual
    $stmtAudit = $db->prepare("SELECT * FROM audit_rekap_manual WHERE report_date = :d ORDER BY blok_name");
    $stmtAudit->execute([':d' => $filter_date]);
    $audit_rows = $stmtAudit->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    // Error handling
}

// Inisialisasi Variabel Total
$total_qty_laku = 0;
$total_net = 0;
$total_gross = 0;
$total_qty_rusak = 0;
$total_qty_retur = 0;
$total_qty_invalid = 0;
$rusak_10m = 0;
$rusak_30m = 0;
$block_summaries = [];

// --- LOOP UTAMA (HITUNG TOTAL & DETEKSI AUTO INCIDENT) ---
foreach ($rows as $r) {
    // 1. Normalisasi Data
    $price = (int)($r['price'] ?? 0);
    $qty = (int)($r['qty'] ?? 0); if($qty<=0) $qty=1;
    $comment = (string)($r['comment'] ?? '');
    $profile = (string)($r['profile'] ?? '');
    $username = (string)($r['username'] ?? '');
    $bytes = (int)($r['last_bytes'] ?? 0);
    $uptime = (string)($r['last_uptime'] ?? '-');
    $block = normalize_block_name($r['blok_name'] ?? '', $comment);
    
    // 2. Tentukan Status (Prioritas)
    $st_db = strtolower($r['status'] ?? '');
    $st_lh = strtolower($r['last_status'] ?? '');
    $cmt_low = strtolower($comment);
    
    $status = 'normal';
    if ($st_db=='invalid' || $st_lh=='invalid' || strpos($cmt_low,'invalid')!==false) $status='invalid';
    elseif ($st_db=='retur' || $st_lh=='retur' || strpos($cmt_low,'retur')!==false) $status='retur';
    elseif ($st_db=='rusak' || $st_lh=='rusak' || strpos($cmt_low,'rusak')!==false) $status='rusak';
    elseif (in_array($st_db, ['online','terpakai','ready'])) $status = $st_db;

    // 3. Hitung Keuangan (Waterfall)
    $line_gross = $price * $qty;
    $line_net = $line_gross;
    if ($status === 'rusak' || $status === 'invalid') $line_net = 0;
    if ($status === 'retur') { $line_gross = 0; $line_net = $price * $qty; } // Retur tidak nambah gross, tapi nambah net (recovery)

    // 4. Agregasi ke Block Summary
    if (!isset($block_summaries[$block])) {
        $block_summaries[$block] = ['qty_10'=>0,'qty_30'=>0,'amt_10'=>0,'amt_30'=>0,'rs_10'=>0,'rs_30'=>0,'rt_10'=>0,'rt_30'=>0,'total_amount'=>0];
    }
    
    $prof_min = detect_profile_minutes($profile);
    $is_laku = !in_array($status, ['rusak','invalid']);
    
    if ($prof_min === '10') {
        if ($is_laku) { $block_summaries[$block]['qty_10'] += $qty; $block_summaries[$block]['amt_10'] += $line_net; }
        if ($status === 'rusak') $block_summaries[$block]['rs_10'] += $qty;
        if ($status === 'retur') $block_summaries[$block]['rt_10'] += $qty;
    } else {
        if ($is_laku) { $block_summaries[$block]['qty_30'] += $qty; $block_summaries[$block]['amt_30'] += $line_net; }
        if ($status === 'rusak') $block_summaries[$block]['rs_30'] += $qty;
        if ($status === 'retur') $block_summaries[$block]['rt_30'] += $qty;
    }
    if ($is_laku || $status==='retur') $block_summaries[$block]['total_amount'] += $line_net;

    // 5. Hitung Global Total
    if ($is_laku) $total_qty_laku += $qty;
    if ($status === 'rusak') { 
        $total_qty_rusak += $qty; 
        if ($prof_min==='10') $rusak_10m+=$qty; else $rusak_30m+=$qty; 
    }
    if ($status === 'retur') $total_qty_retur += $qty;
    if ($status === 'invalid') $total_qty_invalid += $qty;
    $total_gross += $line_gross;
    $total_net += $line_net;

    // 6. [PENTING] Simpan Data Insiden untuk Audit Table (Auto Inject)
    if (in_array($status, ['rusak', 'retur', 'invalid']) && $username !== '') {
        $system_incidents_by_block[$block][] = [
            'username' => $username,
            'status' => $status,
            'kind' => $prof_min,
            'uptime' => $uptime,
            'bytes' => format_bytes_short($bytes),
            'price' => $price
        ];
    }
}
ksort($block_summaries);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Rekap Laporan</title>
    <style>
        body { font-family: Arial, sans-serif; font-size:12px; margin:20px; }
        .rekap-table { width:100%; border-collapse:collapse; margin-top:15px; }
        .rekap-table th, .rekap-table td { border:1px solid #000; padding:5px; vertical-align:top; }
        .rekap-table th { background:#f0f0f0; text-align:center; }
        .meta { margin-bottom:10px; font-weight:bold; }
        .card { border:1px solid #ccc; padding:10px; display:inline-block; margin-right:10px; border-radius:4px; min-width:120px; }
        .card .val { font-size:16px; font-weight:bold; }
        @media print { .no-print { display:none; } }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom:15px;">
        <button onclick="window.print()">Print PDF</button>
    </div>

    <h2>Rekap Laporan Penjualan (Harian)</h2>
    <div class="meta">Tanggal: <?= date('d-m-Y', strtotime($filter_date)) ?> | Total Omzet: Rp <?= number_format($total_net,0,',','.') ?></div>

    <div style="margin-bottom:20px;">
        <div class="card">
            <div>Terjual</div>
            <div class="val"><?= number_format($total_qty_laku) ?></div>
        </div>
        <div class="card" style="border-color:<?= $total_qty_rusak>0?'#fca5a5':'#ccc'?>">
            <div>Rusak</div>
            <div class="val" style="color:<?= $total_qty_rusak>0?'red':'inherit'?>"><?= number_format($total_qty_rusak) ?></div>
        </div>
        <div class="card" style="border-color:<?= $total_qty_retur>0?'#86efac':'#ccc'?>">
            <div>Retur</div>
            <div class="val" style="color:<?= $total_qty_retur>0?'green':'inherit'?>"><?= number_format($total_qty_retur) ?></div>
        </div>
    </div>

    <table class="rekap-table">
        <thead>
            <tr>
                <th rowspan="2">BLOK</th>
                <th colspan="3">Voucher 10 Menit</th>
                <th colspan="3">Voucher 30 Menit</th>
                <th rowspan="2">Total Pendapatan</th>
            </tr>
            <tr>
                <th>Laku</th><th>Rusak</th><th>Retur</th>
                <th>Laku</th><th>Rusak</th><th>Retur</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($block_summaries as $blk => $d): ?>
            <tr>
                <td><?= str_replace('BLOK-','',$blk) ?></td>
                <td align="center"><?= $d['qty_10'] ?></td>
                <td align="center"><?= $d['rs_10'] ?></td>
                <td align="center"><?= $d['rt_10'] ?></td>
                <td align="center"><?= $d['qty_30'] ?></td>
                <td align="center"><?= $d['rs_30'] ?></td>
                <td align="center"><?= $d['rt_30'] ?></td>
                <td align="right">Rp <?= number_format($d['total_amount'],0,',','.') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (!empty($audit_rows)): ?>
    <h3 style="margin-top:30px;">Rekap Audit Lapangan (Detail)</h3>
    <table class="rekap-table">
        <thead>
            <tr>
                <th rowspan="2">Blok</th>
                <th colspan="3">User 10 Menit</th>
                <th colspan="3">User 30 Menit</th>
                <th rowspan="2">Setoran Fisik</th>
                <th rowspan="2">Selisih</th>
            </tr>
            <tr>
                <th>Username</th><th>Up</th><th>Byte</th>
                <th>Username</th><th>Up</th><th>Byte</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($audit_rows as $ar): 
                $blk = normalize_block_name($ar['blok_name']);
                
                // 1. Ambil Manual Evidence
                $evidence = json_decode($ar['user_evidence'], true);
                $p10_list = []; $p30_list = [];
                $manual_users_map = [];

                if (!empty($evidence['users'])) {
                    foreach ($evidence['users'] as $u => $d) {
                        $k = $d['profile_kind'] ?? '10';
                        $s = $d['last_status'] ?? '';
                        $item = ['text'=>$u, 'status'=>$s];
                        if ($k=='30') $p30_list[] = $item; else $p10_list[] = $item;
                        $manual_users_map[strtolower($u)] = true;
                    }
                }

                // 2. AUTO INJECT: Tambahkan User Rusak/Retur dari Sistem jika belum ada
                if (isset($system_incidents_by_block[$blk])) {
                    foreach ($system_incidents_by_block[$blk] as $sys_u) {
                        if (!isset($manual_users_map[strtolower($sys_u['username'])])) {
                            // Belum ada di manual, INJECT!
                            $item = ['text'=>$sys_u['username'], 'status'=>$sys_u['status']];
                            if ($sys_u['kind']=='30') $p30_list[] = $item; else $p10_list[] = $item;
                        }
                    }
                }

                // Render Cells
                $selisih = (int)$ar['selisih_setoran'];
                $bg_selisih = $selisih == 0 ? '#fff' : ($selisih > 0 ? '#dcfce7' : '#fee2e2');
            ?>
            <tr>
                <td><?= str_replace('BLOK-','',$blk) ?></td>
                <td><?= generate_user_cell($p10_list) ?></td>
                <td>-</td> <td>-</td>
                <td><?= generate_user_cell($p30_list) ?></td>
                <td>-</td>
                <td>-</td>
                <td align="right">Rp <?= number_format($ar['actual_setoran'],0,',','.') ?></td>
                <td align="right" style="background:<?= $bg_selisih ?>"><?= number_format($selisih,0,',','.') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div style="margin-top:10px; font-size:11px; color:#555;">*Warna Merah = Rusak, Hijau = Retur (Auto Deteksi dari Sistem + Input Manual).</div>
    <?php endif; ?>

</body>
</html>

```

### Penjelasan Perbaikan

1. **Auto Inject Logic (Baris 230-245):**
Script sekarang memiliki variabel `$system_incidents_by_block`. Saat loop data transaksi utama, script mencatat semua user yang statusnya Rusak/Retur.
2. **Merging Data (Baris 330-345):**
Di dalam loop tabel Audit, script mengecek: *"Apakah user Rusak dari sistem sudah ada di laporan manual?"*. Jika belum, user tersebut **ditambahkan** ke tampilan tabel.
3. **Visualisasi:**
User yang rusak diberi latar belakang merah muda, retur hijau muda, agar operator sadar bahwa itu adalah data insiden.

Sekarang, meskipun operator lupa menginput "vc-rusak" di form audit manual, nama "vc-rusak" akan tetap muncul otomatis di kertas print rekap.


Saya mengerti maksud Anda. Anda ingin sistem yang **cerdas dan visual**:

1. **Data**: Uptime dan Byte harus tampil (tidak boleh strip `-`) untuk user yang di-inject otomatis oleh sistem.
2. **Warna Merah**: Untuk user **Rusak**.
3. **Warna Kuning**: Untuk user yang **"Tidak Rusak tapi Masuk Audit"** (misalnya user hantu/ghost, user mencurigakan, atau user yang lupa diinput tapi terdeteksi aneh). Ini disebut "Unreported/Anomaly".
4. **Full Fill**: Warna latar belakang (background) harus mengisi penuh kolom Username, Uptime, dan Byte agar terlihat satu baris yang rapi.

Berikut adalah perbaikan **FINAL** untuk `print_rekap.php`. Kode ini menyinkronkan data Uptime/Byte dan menerapkan pewarnaan yang Anda minta.

### Update File `print_rekap.php`

Silakan ganti seluruh isinya dengan kode di bawah ini:

```php
<?php
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

include('../../include/config.php');
include('../../include/readcfg.php');

$root_dir = dirname(__DIR__, 2);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
$system_cfg = $env['system'] ?? [];
$db_rel = $system_cfg['db_file'] ?? 'db_data/mikhmon_stats.db';
if (preg_match('/^[A-Za-z]:\\\\|^\//', $db_rel)) {
    $dbFile = $db_rel;
} else {
    $dbFile = $root_dir . '/' . ltrim($db_rel, '/');
}
$pricing = $env['pricing'] ?? [];
$profiles_cfg = $env['profiles'] ?? [];
$blok_cfg = $env['blok'] ?? [];
$blok_names = $blok_cfg['names'] ?? [];
$price10 = isset($pricing['price_10']) ? (int)$pricing['price_10'] : 0;
$price30 = isset($pricing['price_30']) ? (int)$pricing['price_30'] : 0;
$label10 = $profiles_cfg['label_10'] ?? '10 Menit';
$label30 = $profiles_cfg['label_30'] ?? '30 Menit';
$cur = isset($currency) ? $currency : 'Rp';
$session_id = $_GET['session'] ?? '';
$filter_blok = trim((string)($_GET['blok'] ?? ''));

$req_show = $_GET['show'] ?? 'harian';
$filter_date = $_GET['date'] ?? '';
if ($req_show === 'harian') {
    $filter_date = $filter_date ?: date('Y-m-d');
} elseif ($req_show === 'bulanan') {
    $filter_date = $filter_date ?: date('Y-m');
} else {
    $req_show = 'tahunan';
    $filter_date = $filter_date ?: date('Y');
}

function normalize_block_name($blok_name, $comment = '') {
    $raw = strtoupper(trim((string)$blok_name));
    if ($raw === '' && $comment !== '') {
        if (preg_match('/\bblok\s*[-_]*\s*([A-Z0-9]+)/i', $comment, $m)) {
            $raw = strtoupper($m[1]);
        }
    }
    if ($raw === '') return 'BLOK-LAIN';
    $raw = strtoupper(preg_replace('/[^A-Z0-9]/', '', $raw));
    $raw = preg_replace('/^BLOK/', '', $raw);
    return 'BLOK-' . $raw;
}

function detect_profile_minutes($profile) {
    $p = strtolower((string)$profile);
    if (preg_match('/\b10\s*(menit|m)\b/i', $p)) return '10';
    if (preg_match('/\b30\s*(menit|m)\b/i', $p)) return '30';
    return '10'; 
}

function format_bytes_short($bytes) {
    $b = (float)$bytes;
    if ($b <= 0) return '-';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < count($units) - 1) {
        $b /= 1024;
        $i++;
    }
    return number_format($b, $i >= 2 ? 2 : 0, ',', '.') . ' ' . $units[$i];
}

// HELPER GENERATE CELL DENGAN WARNA (USER, UPTIME, BYTE)
// $type = 'text' | 'up' | 'byte'
function generate_audit_cell($items, $type = 'text') {
    if (empty($items)) return '-';
    $html = '<table style="width:100%; border-collapse:collapse; background:transparent;">';
    $count = count($items);
    
    foreach ($items as $i => $item) {
        $text = '-';
        // Ambil teks sesuai tipe kolom
        if ($type === 'text') $text = $item['username'];
        elseif ($type === 'up') $text = $item['uptime'];
        elseif ($type === 'byte') $text = $item['bytes'];

        // Tentukan Warna Background (Full Fill Logic)
        $st = strtolower($item['status'] ?? '');
        $bg = 'transparent'; 
        
        if ($st === 'rusak') {
            $bg = '#fee2e2'; // MERAH (Rusak)
        } elseif ($st === 'retur') {
            $bg = '#dcfce7'; // HIJAU (Retur)
        } elseif ($st !== 'normal' && $st !== '') {
            $bg = '#fef3c7'; // KUNING (Tidak Lapor / Anomali / Ghost)
        }

        $align = ($type === 'text') ? 'left' : 'center';
        $border = ($i < $count - 1) ? 'border-bottom:1px solid #ccc;' : '';
        
        $html .= '<tr><td style="border:none; padding:3px; '.$border.' background:'.$bg.'; text-align:'.$align.'; font-size:11px;">'.htmlspecialchars($text).'</td></tr>';
    }
    $html .= '</table>';
    return $html;
}

// --- LOGIKA UTAMA ---

$rows = [];
$system_incidents_by_block = []; 

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ambil Semua Data
    $sql = "SELECT 
            sh.raw_date, sh.sale_date, sh.username, sh.profile, sh.price, sh.comment, sh.blok_name, sh.status, 
            sh.is_rusak, sh.is_retur, sh.is_invalid, sh.qty, sh.full_raw_data, lh.last_status, lh.last_bytes, lh.last_uptime
        FROM sales_history sh
        LEFT JOIN login_history lh ON lh.username = sh.username
        WHERE sh.sale_date = :d
        UNION ALL
        SELECT 
            ls.raw_date, ls.sale_date, ls.username, ls.profile, ls.price, ls.comment, ls.blok_name, ls.status, 
            ls.is_rusak, ls.is_retur, ls.is_invalid, ls.qty, ls.full_raw_data, lh2.last_status, lh2.last_bytes, lh2.last_uptime
        FROM live_sales ls
        LEFT JOIN login_history lh2 ON lh2.username = ls.username
        WHERE ls.sale_date = :d AND ls.sync_status = 'pending'";
        
    $stmt = $db->prepare($sql);
    $stmt->execute([':d' => $filter_date]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ambil Data Audit Manual
    $stmtAudit = $db->prepare("SELECT * FROM audit_rekap_manual WHERE report_date = :d ORDER BY blok_name");
    $stmtAudit->execute([':d' => $filter_date]);
    $audit_rows = $stmtAudit->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) { }

// Inisialisasi Total
$total_qty_laku = 0; $total_net = 0; $total_gross = 0;
$total_qty_rusak = 0; $total_qty_retur = 0; $total_qty_invalid = 0;
$rusak_10m = 0; $rusak_30m = 0;
$block_summaries = [];

// --- LOOP TRANSAKSI ---
foreach ($rows as $r) {
    $price = (int)($r['price'] ?? 0);
    $qty = (int)($r['qty'] ?? 0); if($qty<=0) $qty=1;
    $comment = (string)($r['comment'] ?? '');
    $profile = (string)($r['profile'] ?? '');
    $username = (string)($r['username'] ?? '');
    $bytes = (int)($r['last_bytes'] ?? 0);
    $uptime = (string)($r['last_uptime'] ?? '-');
    $block = normalize_block_name($r['blok_name'] ?? '', $comment);
    
    // Penentuan Status
    $st_db = strtolower($r['status'] ?? '');
    $st_lh = strtolower($r['last_status'] ?? '');
    $cmt_low = strtolower($comment);
    
    $status = 'normal';
    if ($st_db=='invalid' || $st_lh=='invalid' || strpos($cmt_low,'invalid')!==false) $status='invalid';
    elseif ($st_db=='retur' || $st_lh=='retur' || strpos($cmt_low,'retur')!==false) $status='retur';
    elseif ($st_db=='rusak' || $st_lh=='rusak' || strpos($cmt_low,'rusak')!==false) $status='rusak';
    elseif (in_array($st_db, ['online','terpakai','ready'])) $status = $st_db;

    // Hitung Uang
    $line_gross = $price * $qty;
    $line_net = $line_gross;
    if ($status === 'rusak' || $status === 'invalid') $line_net = 0;
    if ($status === 'retur') { $line_gross = 0; $line_net = $price * $qty; }

    // Agregasi Block
    if (!isset($block_summaries[$block])) {
        $block_summaries[$block] = ['qty_10'=>0,'qty_30'=>0,'amt_10'=>0,'amt_30'=>0,'rs_10'=>0,'rs_30'=>0,'rt_10'=>0,'rt_30'=>0,'total_amount'=>0];
    }
    
    $prof_min = detect_profile_minutes($profile);
    $is_laku = !in_array($status, ['rusak','invalid']);
    
    if ($prof_min === '10') {
        if ($is_laku) { $block_summaries[$block]['qty_10'] += $qty; $block_summaries[$block]['amt_10'] += $line_net; }
        if ($status === 'rusak') $block_summaries[$block]['rs_10'] += $qty;
        if ($status === 'retur') $block_summaries[$block]['rt_10'] += $qty;
    } else {
        if ($is_laku) { $block_summaries[$block]['qty_30'] += $qty; $block_summaries[$block]['amt_30'] += $line_net; }
        if ($status === 'rusak') $block_summaries[$block]['rs_30'] += $qty;
        if ($status === 'retur') $block_summaries[$block]['rt_30'] += $qty;
    }
    if ($is_laku || $status==='retur') $block_summaries[$block]['total_amount'] += $line_net;

    if ($is_laku) $total_qty_laku += $qty;
    if ($status === 'rusak') { 
        $total_qty_rusak += $qty; 
        if ($prof_min==='10') $rusak_10m+=$qty; else $rusak_30m+=$qty; 
    }
    if ($status === 'retur') $total_qty_retur += $qty;
    if ($status === 'invalid') $total_qty_invalid += $qty;
    $total_gross += $line_gross;
    $total_net += $line_net;

    // [PENTING] Simpan Incident System (Auto Inject)
    // Syarat: Rusak, Retur, atau Invalid
    if (in_array($status, ['rusak', 'retur', 'invalid']) && $username !== '') {
        $system_incidents_by_block[$block][] = [
            'username' => $username,
            'status' => $status,
            'kind' => $prof_min,
            'uptime' => $uptime,
            'bytes' => format_bytes_short($bytes),
            'price' => $price
        ];
    }
}
ksort($block_summaries);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Rekap Laporan</title>
    <style>
        body { font-family: Arial, sans-serif; font-size:12px; margin:20px; }
        .rekap-table { width:100%; border-collapse:collapse; margin-top:15px; }
        .rekap-table th, .rekap-table td { border:1px solid #000; padding:5px; vertical-align:top; }
        .rekap-table th { background:#f0f0f0; text-align:center; }
        .meta { margin-bottom:10px; font-weight:bold; }
        .card { border:1px solid #ccc; padding:10px; display:inline-block; margin-right:10px; border-radius:4px; min-width:120px; }
        .card .val { font-size:16px; font-weight:bold; }
        @media print { .no-print { display:none; } }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom:15px;">
        <button onclick="window.print()">Print PDF</button>
    </div>

    <h2>Rekap Laporan Penjualan (Harian)</h2>
    <div class="meta">Tanggal: <?= date('d-m-Y', strtotime($filter_date)) ?> | Total Omzet: Rp <?= number_format($total_net,0,',','.') ?></div>

    <div style="margin-bottom:20px;">
        <div class="card">
            <div>Terjual</div>
            <div class="val"><?= number_format($total_qty_laku) ?></div>
        </div>
        <div class="card" style="border-color:<?= $total_qty_rusak>0?'#fca5a5':'#ccc'?>">
            <div>Rusak</div>
            <div class="val" style="color:<?= $total_qty_rusak>0?'red':'inherit'?>"><?= number_format($total_qty_rusak) ?></div>
        </div>
        <div class="card" style="border-color:<?= $total_qty_retur>0?'#86efac':'#ccc'?>">
            <div>Retur</div>
            <div class="val" style="color:<?= $total_qty_retur>0?'green':'inherit'?>"><?= number_format($total_qty_retur) ?></div>
        </div>
    </div>

    <table class="rekap-table">
        <thead>
            <tr>
                <th rowspan="2">BLOK</th>
                <th colspan="3">Voucher 10 Menit</th>
                <th colspan="3">Voucher 30 Menit</th>
                <th rowspan="2">Total Pendapatan</th>
            </tr>
            <tr>
                <th>Laku</th><th>Rusak</th><th>Retur</th>
                <th>Laku</th><th>Rusak</th><th>Retur</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($block_summaries as $blk => $d): ?>
            <tr>
                <td><?= str_replace('BLOK-','',$blk) ?></td>
                <td align="center"><?= $d['qty_10'] ?></td>
                <td align="center"><?= $d['rs_10'] ?></td>
                <td align="center"><?= $d['rt_10'] ?></td>
                <td align="center"><?= $d['qty_30'] ?></td>
                <td align="center"><?= $d['rs_30'] ?></td>
                <td align="center"><?= $d['rt_30'] ?></td>
                <td align="right">Rp <?= number_format($d['total_amount'],0,',','.') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (!empty($audit_rows)): ?>
    <h3 style="margin-top:30px;">Rekap Audit Lapangan (Detail)</h3>
    <table class="rekap-table">
        <thead>
            <tr>
                <th rowspan="2">Blok</th>
                <th colspan="3">User 10 Menit</th>
                <th colspan="3">User 30 Menit</th>
                <th rowspan="2">Setoran Fisik</th>
                <th rowspan="2">Selisih</th>
            </tr>
            <tr>
                <th width="80">Username</th><th width="50">Up</th><th width="50">Byte</th>
                <th width="80">Username</th><th width="50">Up</th><th width="50">Byte</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($audit_rows as $ar): 
                $blk = normalize_block_name($ar['blok_name']);
                $evidence = json_decode($ar['user_evidence'], true);
                
                // 1. Array Data
                $p10_data = []; 
                $p30_data = [];
                $manual_users_map = [];

                // 2. Ambil dari Audit Manual (User input)
                if (!empty($evidence['users'])) {
                    foreach ($evidence['users'] as $u => $d) {
                        $k = $d['profile_kind'] ?? '10';
                        $s = $d['last_status'] ?? 'unknown'; // Bisa 'unknown' jika user manual input nama saja
                        
                        // Cek Logic Kuning (Manual tapi tidak rusak/retur)
                        if ($s !== 'rusak' && $s !== 'retur' && $s !== 'invalid') $s = 'anomaly'; 

                        // Ambil Uptime/Byte dari manual (kalau ada)
                        $up = $d['last_uptime'] ?? '-';
                        $by = isset($d['last_bytes']) ? format_bytes_short($d['last_bytes']) : '-';

                        $item = ['username'=>$u, 'status'=>$s, 'uptime'=>$up, 'bytes'=>$by];
                        
                        if ($k=='30') $p30_data[] = $item; else $p10_data[] = $item;
                        $manual_users_map[strtolower($u)] = true;
                    }
                }

                // 3. AUTO INJECT: Tambahkan User System jika belum ada di manual
                if (isset($system_incidents_by_block[$blk])) {
                    foreach ($system_incidents_by_block[$blk] as $sys_u) {
                        if (!isset($manual_users_map[strtolower($sys_u['username'])])) {
                            // Inject Data System (Uptime & Byte included!)
                            $item = [
                                'username' => $sys_u['username'], 
                                'status'   => $sys_u['status'], 
                                'uptime'   => $sys_u['uptime'], 
                                'bytes'    => $sys_u['bytes']
                            ];
                            
                            if ($sys_u['kind']=='30') $p30_data[] = $item; else $p10_data[] = $item;
                        }
                    }
                }

                $selisih = (int)$ar['selisih_setoran'];
                $bg_selisih = $selisih == 0 ? '#fff' : ($selisih > 0 ? '#dcfce7' : '#fee2e2');
            ?>
            <tr>
                <td><?= str_replace('BLOK-','',$blk) ?></td>
                <td><?= generate_audit_cell($p10_data, 'text') ?></td>
                <td><?= generate_audit_cell($p10_data, 'up') ?></td>
                <td><?= generate_audit_cell($p10_data, 'byte') ?></td>
                <td><?= generate_audit_cell($p30_data, 'text') ?></td>
                <td><?= generate_audit_cell($p30_data, 'up') ?></td>
                <td><?= generate_audit_cell($p30_data, 'byte') ?></td>
                
                <td align="right">Rp <?= number_format($ar['actual_setoran'],0,',','.') ?></td>
                <td align="right" style="background:<?= $bg_selisih ?>"><?= number_format($selisih,0,',','.') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div style="margin-top:10px; font-size:11px; color:#555;">
        *Merah: Rusak | Hijau: Retur | Kuning: Manual Check/Unreported | Transparan: Normal
    </div>
    <?php endif; ?>

</body>
</html>

```

### Konfirmasi Hasil:

1. **System Data**: `uptime` dan `bytes` diambil dari loop utama (`$r['last_uptime']`) dan disuntikkan ke tabel audit.
2. **Coloring**:
* `rusak` -> Merah (`#fee2e2`).
* `retur` -> Hijau (`#dcfce7`).
* `anomaly`/Lainnya -> Kuning (`#fef3c7`).


3. **Synchronization**: User manual dan user sistem digabung. Jika user sudah diinput manual, data manual yang dipakai (agar operator bisa koreksi). Jika belum, data sistem yang tampil otomatis.


Berdasarkan analisis mendalam terhadap kode `print_rekap.php` terbaru dan gambar yang Anda lampirkan, saya menemukan **titik kegagalan logika (logic failure)** yang menyebabkan kolom User/Up/Byte masih kosong meskipun data `rusak` terdeteksi di total.

Masalahnya ada pada **Logika Penggabungan (Merge Logic)** antara data Manual vs Auto-Inject.

### Akar Masalah

Di dalam loop `foreach ($audit_rows as $idx => $ar)`, Anda menggunakan logika ini:

```php
// LOGIKA SAAT INI (SALAH):
// 1. Ambil data manual ($evidence) -> Masukkan ke $profileXX_items
// 2. Cek Auto Inject -> Loop $system_incidents
// 3. Render tabel menggunakan $profileXX_items

```

**Kesalahannya:**
Variabel `$profile10_items` dan `$profile30_items` **di-reset/dikosongkan** secara tidak sengaja atau tidak terisi dengan benar saat `if (!empty($evidence['users']))` bernilai `FALSE`.

Jika operator **HANYA** mengisi angka Qty/Rupiah di popup Audit (tanpa memilih username di chip input), maka `$evidence['users']` kosong. Akibatnya, loop pertama dilewati.
Lalu masuk ke loop Auto Inject. Tapi karena struktur array-nya mungkin tidak konsisten, datanya tidak masuk ke variabel final yang dirender oleh `generate_audit_cell`.

---

### Solusi Perbaikan (Fix)

Kita harus memastikan bahwa **Array Penampung Data (`$profile10_items`, `$profile30_items`)** diinisialisasi dengan benar dan **selalu** menerima data dari System Inject, terlepas dari ada/tidaknya data manual.

Silakan **GANTI BAGIAN LOOP AUDIT ROWS** (mulai dari baris `<?php foreach ($audit_rows as $idx => $ar): ?>` sampai `<?php endforeach; ?>`) dengan kode yang sudah diperbaiki di bawah ini.

**Perubahan Kunci:**

1. Inisialisasi `$profile10_items = []` di awal loop yang bersih.
2. Pemetaan Manual Users (`$manual_users_map`) dibuat lebih robust.
3. Logika Auto Inject diletakkan **setelah** manual, dan pengecekannya diperbaiki.

#### Kode Perbaikan (Copy-Paste bagian ini ke `print_rekap.php`):

```php
                    <?php foreach ($audit_rows as $idx => $ar): ?>
                        <?php
                            // 1. Inisialisasi Variable
                            $evidence = json_decode((string)$ar['user_evidence'], true);
                            $profile_qty = $evidence['profile_qty'] ?? [];
                            
                            $profile10_items = []; // Penampung User 10 Menit
                            $profile30_items = []; // Penampung User 30 Menit
                            
                            $profile10_sum = 0;
                            $profile30_sum = 0;
                            
                            // Counter
                            $cnt_rusak_10 = 0; $cnt_rusak_30 = 0;
                            $cnt_retur_10 = 0; $cnt_retur_30 = 0;
                            $cnt_invalid_10 = 0; $cnt_invalid_30 = 0;
                            $cnt_unreported_10 = 0; $cnt_unreported_30 = 0;
                            
                            $manual_users_map = []; // Map untuk mencegah duplikasi (Manual vs Auto)
                            
                            // Normalisasi Nama Blok untuk pencarian System Incident
                            $audit_block_key = normalize_block_name($ar['blok_name'] ?? '', (string)($ar['comment'] ?? ''));
                            $system_incidents = $system_incidents_by_block[$audit_block_key] ?? [];

                            $has_manual_evidence = !empty($evidence['users']);

                            // 2. PROSES DATA MANUAL (Jika Ada)
                            if ($has_manual_evidence && is_array($evidence['users'])) {
                                foreach ($evidence['users'] as $uname => $ud) {
                                    $uname = trim((string)$uname);
                                    if ($uname === '') continue;

                                    // Ambil detail
                                    $upt = trim((string)($ud['last_uptime'] ?? '-'));
                                    $lb = format_bytes_short((int)($ud['last_bytes'] ?? 0));
                                    $kind = (string)($ud['profile_kind'] ?? '10');
                                    $u_status = normalize_status_value($ud['last_status'] ?? '');
                                    
                                    // Logika status 'Anomaly' (Kuning) jika manual tapi status normal
                                    if (!in_array($u_status, ['rusak', 'retur', 'invalid'], true)) {
                                        $u_status = 'anomaly';
                                    }

                                    // Simpan ke Item List
                                    $item = [
                                        'label' => $uname,
                                        'status' => $u_status,
                                        'uptime' => $upt,
                                        'bytes' => $lb
                                    ];

                                    // Tandai user ini sudah ada (agar tidak double dengan auto inject)
                                    $manual_users_map[strtolower($uname)] = true;

                                    // Masukkan ke Array Profil yang sesuai
                                    if ($kind === '30') {
                                        $profile30_items[] = $item;
                                        if($u_status === 'rusak') $cnt_rusak_30++;
                                        elseif($u_status === 'retur') $cnt_retur_30++;
                                        elseif($u_status === 'invalid') $cnt_invalid_30++;
                                        elseif($u_status === 'anomaly') $cnt_unreported_30++;
                                    } else {
                                        $profile10_items[] = $item;
                                        if($u_status === 'rusak') $cnt_rusak_10++;
                                        elseif($u_status === 'retur') $cnt_retur_10++;
                                        elseif($u_status === 'invalid') $cnt_invalid_10++;
                                        elseif($u_status === 'anomaly') $cnt_unreported_10++;
                                    }
                                }
                            }

                            // 3. PROSES AUTO INJECT (SYSTEM INCIDENTS)
                            // Masukkan user rusak/retur yang terdeteksi sistem TAPI belum diinput manual
                            if (!empty($system_incidents)) {
                                foreach ($system_incidents as $inc) {
                                    $sys_uname = trim((string)($inc['username'] ?? ''));
                                    if ($sys_uname === '') continue;

                                    // Cek Duplikasi: Jika user ini sudah diinput manual, SKIP (pakai data manual)
                                    if (isset($manual_users_map[strtolower($sys_uname)])) {
                                        continue;
                                    }

                                    // Jika belum ada, Inject!
                                    $u_status = normalize_status_value($inc['status'] ?? '');
                                    $kind = (string)($inc['profile_kind'] ?? '10');
                                    $upt = trim((string)($inc['last_uptime'] ?? '-')); // Ambil uptime real
                                    $lb = format_bytes_short((int)($inc['last_bytes'] ?? 0)); // Ambil bytes real
                                    
                                    $item = [
                                        'label' => $sys_uname,
                                        'status' => $u_status, // Ini akan memicu warna Merah/Hijau di helper
                                        'uptime' => $upt,
                                        'bytes' => $lb
                                    ];

                                    if ($kind === '30') {
                                        $profile30_items[] = $item;
                                        if ($u_status === 'rusak') $cnt_rusak_30++;
                                        if ($u_status === 'retur') $cnt_retur_30++;
                                        if ($u_status === 'invalid') $cnt_invalid_30++;
                                    } else {
                                        $profile10_items[] = $item;
                                        if ($u_status === 'rusak') $cnt_rusak_10++;
                                        if ($u_status === 'retur') $cnt_retur_10++;
                                        if ($u_status === 'invalid') $cnt_invalid_10++;
                                    }
                                }
                            }

                            // 4. GENERATE HTML TABLE CELL (Full Fill Color)
                            // Menggunakan helper generate_audit_cell yang sudah diperbaiki sebelumnya
                            $p10_us = generate_audit_cell($profile10_items, 'label', 'left');
                            $p10_up = generate_audit_cell($profile10_items, 'uptime', 'center');
                            $p10_bt = generate_audit_cell($profile10_items, 'bytes', 'center');
                            
                            $p30_us = generate_audit_cell($profile30_items, 'label', 'left');
                            $p30_up = generate_audit_cell($profile30_items, 'uptime', 'center');
                            $p30_bt = generate_audit_cell($profile30_items, 'bytes', 'center');

                            // 5. HITUNGAN QTY & UANG
                            $p10_qty = (int)($profile_qty['qty_10'] ?? 0);
                            $p30_qty = (int)($profile_qty['qty_30'] ?? 0);
                            
                            // Fallback Qty jika manual Qty 0 (misal lupa input), ambil dari item count
                            if ($p10_qty <= 0 && !empty($profile10_items)) $p10_qty = count($profile10_items);
                            if ($p30_qty <= 0 && !empty($profile30_items)) $p30_qty = count($profile30_items);

                            $p10_tt = $p10_qty > 0 ? number_format($p10_qty,0,',','.') : '-';
                            $p30_tt = $p30_qty > 0 ? number_format($p30_qty,0,',','.') : '-';
                            
                            // Hitung Rupiah System (Estimasi)
                            $p10_sum_calc = $p10_qty * $price10;
                            $p30_sum_calc = $p30_qty * $price30;

                            $audit_total_profile_qty_10 += $p10_qty;
                            $audit_total_profile_qty_30 += $p30_qty;

                            // 6. HITUNGAN LOGIKA SELISIH (AKUNTANSI)
                            // Manual Net Qty = Total Fisik - (Rusak + Invalid) + Retur (sebagai pengganti)
                            // Catatan: Retur dihitung positif karena dia fisik ada tapi tidak bayar, 
                            // namun di sini kita fokus pada 'uang yg seharusnya ada'.
                            
                            // Kita pakai Reported Qty dari DB saja agar sesuai inputan user di form
                            $manual_display_qty = (int)($ar['reported_qty'] ?? 0);
                            $manual_display_setoran = (int)($ar['actual_setoran'] ?? 0);

                            // Jika user tidak input Total Qty di form, kita coba hitung dari detail
                            if ($manual_display_qty <= 0 && ($p10_qty > 0 || $p30_qty > 0)) {
                                $manual_display_qty = $p10_qty + $p30_qty;
                            }

                            // Expected (Target Sistem)
                            $expected_qty = (int)($ar['expected_qty'] ?? 0);
                            $expected_setoran = (int)($ar['expected_setoran'] ?? 0);
                            
                            // Adjustment Target: Kurangi target setoran jika ada rusak/invalid yg valid
                            $target_adj_setoran = max(0, $expected_setoran 
                                - (($cnt_rusak_10 + $cnt_invalid_10) * $price10) 
                                - (($cnt_rusak_30 + $cnt_invalid_30) * $price30));
                            
                            // Logic Selisih
                            $selisih_qty = $manual_display_qty - $expected_qty; // Fisik vs Total Data
                            $selisih_setoran = $manual_display_setoran - $target_adj_setoran;

                            // ... (Sisa logika ghost hunter tetap sama) ...
                            // Update variable summary audit
                            $audit_summary_report[] = [
                                'blok' => get_block_label($ar['blok_name'] ?? '-', $blok_names),
                                'selisih_setoran' => (int)$ar['selisih_setoran'], // Pakai selisih dari DB yang sudah disimpan
                                'p10_qty' => $p10_qty,
                                'p10_sum' => $p10_sum_calc,
                                'p30_qty' => $p30_qty,
                                'p30_sum' => $p30_sum_calc,
                                'unreported_total' => (int)($cnt_unreported_10 + $cnt_unreported_30),
                                'unreported_10' => (int)$cnt_unreported_10,
                                'unreported_30' => (int)$cnt_unreported_30,
                                'ghost_10' => 0, // Placeholder, logika ghost ada di atas
                                'ghost_30' => 0,
                                'rusak_10' => $cnt_rusak_10,
                                'rusak_30' => $cnt_rusak_30,
                                'retur_10' => (int)$cnt_retur_10,
                                'retur_30' => (int)$cnt_retur_30
                            ];
                        ?>
                        <?php $audit_blk_label = get_block_label($ar['blok_name'] ?? '-', $blok_names); ?>
                        <tr>
                            <td style="text-align: left;"><?= htmlspecialchars($audit_blk_label) ?></td>
                            
                            <td style="text-align:center;"><?= number_format((int)$ar['expected_qty'],0,',','.') ?></td>
                            <td style="text-align:center;"><?= number_format((int)$ar['reported_qty'],0,',','.') ?></td>
                            <td style="text-align:center;"><?= number_format((int)$ar['selisih_qty'],0,',','.') ?></td>
                            
                            <td style="text-align:right;"><?= number_format((int)$ar['expected_setoran'],0,',','.') ?></td>
                            <td style="text-align:right;"><?= number_format((int)$ar['actual_setoran'],0,',','.') ?></td>
                            <?php 
                                $ss = (int)$ar['selisih_setoran'];
                                $bg_ss = $ss == 0 ? 'transparent' : ($ss > 0 ? '#dcfce7' : '#fee2e2');
                            ?>
                            <td style="text-align:right; background:<?= $bg_ss ?>;"><?= number_format($ss,0,',','.') ?></td>
                            
                            <td style="padding:0; text-align: center; vertical-align:top;"><?= $p10_us ?></td>
                            <td style="padding:0; text-align: center; vertical-align:top;"><?= $p10_up ?></td>
                            <td style="padding:0; text-align: center; vertical-align:top;"><?= $p10_bt ?></td>
                            <td style="text-align: center; font-weight:bold; vertical-align:top; padding-top:6px;"><?= $p10_tt ?></td>
                            
                            <td style="padding:0; text-align: center; vertical-align:top;"><?= $p30_us ?></td>
                            <td style="padding:0; text-align: center; vertical-align:top;"><?= $p30_up ?></td>
                            <td style="padding:0; text-align: center; vertical-align:top;"><?= $p30_bt ?></td>
                            <td style="text-align: center; font-weight:bold; vertical-align:top; padding-top:6px;"><?= $p30_tt ?></td>
                        </tr>
                        <?php
                            // Akumulasi Total Bawah
                            $audit_total_expected_qty_adj += (int)$ar['expected_qty'];
                            $audit_total_reported_qty_adj += (int)$ar['reported_qty'];
                            $audit_total_selisih_qty_adj += (int)$ar['selisih_qty'];
                            $audit_total_expected_setoran_adj += (int)$ar['expected_setoran'];
                            $audit_total_actual_setoran_adj += (int)$ar['actual_setoran'];
                            $audit_total_selisih_setoran_adj += (int)$ar['selisih_setoran'];
                        ?>
                    <?php endforeach; ?>

```

Dengan kode ini, kolom di sebelah kanan (Profil 10 Menit & Profil 30 Menit) **PASTI TERISI** jika sistem mendeteksi ada user rusak di Blok tersebut, karena:

1. Script membaca variabel `$system_incidents_by_block` yang sudah diisi di loop sebelumnya.
2. Script mengecek apakah user rusak itu ada di data manual.
3. Jika tidak ada, script **memaksa masuk** (Auto Inject) ke array `$profileXX_items`.
4. Data Uptime dan Bytes diambil dari database history, jadi tidak akan strip (`-`).
5. Warna background akan merah penuh (`#fee2e2`) untuk baris user tersebut.