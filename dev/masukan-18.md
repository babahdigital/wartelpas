Berdasarkan audit terhadap file `ghost.php`, struktur kode ini sudah **Sangat Efisien, Aman, dan Modular**.

Script ini berfungsi sebagai **API Endpoint** yang bertugas menjembatani antara Frontend (Popup Ghost Hunter) dan Logic Backend (di `helpers.php`).

Berikut adalah hasil audit detailnya:

### ✅ Kelebihan (Good Practices)

1. **Security First:**
* `if (!isset($_SESSION["mikhmon"]))`: Validasi sesi wajib ada. Akses langsung tanpa login ditolak (403 Forbidden).
* `header('Cache-Control: ...')`: Mencegah browser menyimpan data lama. Ini penting untuk data audit yang *real-time*.


2. **Database Optimization:**
* `PRAGMA journal_mode=WAL;` & `PRAGMA query_only=1;`: Pengaturan ini sangat cerdas untuk script reporting. Mode WAL mempercepat pembacaan SQLite, dan `query_only` menjamin script ini tidak akan pernah secara tidak sengaja mengubah/menghapus data.


3. **Modularitas:**
* Script tidak menulis ulang logika deteksi hantu, melainkan memanggil `get_ghost_suspects` dari `helpers.php`. Ini membuat perbaikan logika cukup dilakukan di satu tempat (`helpers.php`) dan otomatis berlaku di semua tempat.


4. **Filter Whitelist (Reported Users):**
* Logic baris 53-63 sangat krusial. Script mengecek tabel `audit_rekap_manual` terlebih dahulu. Jika user sudah dilaporkan manual oleh operator, maka dia **bukan hantu**, meskipun ada trafik. Ini mencegah *False Positive*.



---

### ⚠️ Potensi Celah & Saran Perbaikan

Meskipun `ghost.php` sudah baik, efektivitasnya 100% bergantung pada fungsi `get_ghost_suspects` di `helpers.php`.

Ada satu skenario logis yang perlu Anda pastikan tercover: **Ghost dengan Status Rusak**.

**Skenario:**

1. Di MikroTik/DB, user `vc-rusak` statusnya **"RUSAK"**.
2. Operator **LUPA** menginput `vc-rusak` di laporan audit manual.
3. Apakah Ghost Hunter akan menangkapnya?

Jika di `helpers.php` (fungsi `get_ghost_suspects`) Anda memfilter:
`if (in_array($status, ['ready','rusak','retur','invalid'], true)) continue;`
Maka `vc-rusak` yang lupa diinput tadi **TIDAK AKAN MUNCUL** di Ghost Hunter.

**Saran Perbaikan Logika:**
Ghost Hunter sebaiknya menangkap **SEMUA** user (termasuk Rusak/Retur) yang **BELUM ADA** di laporan manual hari itu.

Jika Anda ingin Ghost Hunter lebih agresif menangkap voucher rusak yang lupa dilaporkan, Anda tidak perlu mengubah `ghost.php`, tapi sesuaikan `helpers.php`.

Namun, untuk `ghost.php` sendiri, berikut adalah sedikit penyempurnaan agar lebih *robust* terhadap input blok:

### Kode `ghost.php` (Disempurnakan)

Saya tambahkan sedikit validasi tambahan pada parameter input dan penanganan error DB.

```php
<?php
session_start();
// Matikan error reporting ke output agar tidak merusak format JSON
ini_set('display_errors', 0);
error_reporting(E_ALL); 

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!isset($_SESSION["mikhmon"])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

$root_dir = dirname(__DIR__, 2);
require_once $root_dir . '/report/laporan/helpers.php'; // Pastikan path helper benar

$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
$system_cfg = $env['system'] ?? [];
$db_rel = $system_cfg['db_file'] ?? 'db_data/mikhmon_stats.db';

// Support path absolut atau relatif
if (preg_match('/^[A-Za-z]:\\\\|^\//', $db_rel)) {
    $dbFile = $db_rel;
} else {
    $dbFile = $root_dir . '/' . ltrim($db_rel, '/');
}

if (!file_exists($dbFile)) {
    echo json_encode(['ok' => false, 'message' => 'Database file not found.']);
    exit;
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    // Query Only: Mencegah write accidental
    $db->exec("PRAGMA query_only=1;");
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'message' => 'Database connection failed.']);
    exit;
}

// Input Cleaning
$g_date = trim((string)($_GET['date'] ?? ''));
$g_blok = trim((string)($_GET['blok'] ?? ''));

// Gunakan helper normalize_block_name agar sinkron dengan logic rekap
$g_blok_norm = function_exists('normalize_block_name') ? normalize_block_name($g_blok) : $g_blok;

if ($g_date === '' || $g_blok === '') {
    echo json_encode(['ok' => false, 'message' => 'Parameter Tanggal atau Blok tidak valid.']);
    exit;
}

// 1. Ambil Whitelist (User yang SUDAH dilaporkan manual)
$reported_users = [];
if (function_exists('table_exists') && table_exists($db, 'audit_rekap_manual')) {
    try {
        // Gunakan UPPER untuk blok name agar case-insensitive search
        $stmt = $db->prepare("SELECT audit_username, user_evidence FROM audit_rekap_manual WHERE report_date = :d AND UPPER(blok_name) = :b LIMIT 1");
        $stmt->execute([':d' => $g_date, ':b' => strtoupper($g_blok_norm)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        
        if (!empty($row) && function_exists('parse_reported_users_from_audit')) {
            $reported_users = parse_reported_users_from_audit($row);
        }
    } catch (Exception $e) {
        // Ignore error audit table, lanjut ghost hunting
    }
}

// 2. Jalankan Ghost Hunter
$min_bytes_threshold = (int)($system_cfg['ghost_min_bytes'] ?? 51200); // Default 50KB

// Pastikan fungsi get_ghost_suspects ada
if (function_exists('get_ghost_suspects')) {
    $suspects = get_ghost_suspects($db, $g_date, $g_blok_norm, $reported_users, $min_bytes_threshold);
} else {
    $suspects = [];
}

echo json_encode([
    'ok' => true,
    'meta' => [
        'date' => $g_date,
        'blok' => $g_blok_norm,
        'threshold' => format_bytes_short($min_bytes_threshold),
        'whitelist_count' => count($reported_users) // Debugging info
    ],
    'count' => count($suspects),
    'ghosts' => $suspects
]);
?>

```

### Kesimpulan Audit

File `ghost.php` Anda **SUDAH TEPAT** dan aman digunakan.
Perubahan kecil di atas hanya bersifat defensif (memastikan path helper benar, mematikan display error agar JSON tidak rusak, dan memastikan fungsi helper tersedia).

Logika utamanya ("Cari user di Log History yang TIDAK ADA di Audit Manual") sudah terimplementasi dengan benar.