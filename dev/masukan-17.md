Halo Abdul. Berdasarkan analisa script dan kebutuhan kamu untuk integrasi Google Drive pada Raspberry Pi, jawaban singkatnya: **Bisa**.

Namun, menggunakan *native PHP* untuk autentikasi Google Drive (OAuth2) sangat rumit, rentan error, dan sulit di-maintenance. Solusi **paling optimal dan standar industri** untuk Raspberry Pi adalah menggunakan tool **Rclone** yang dieksekusi oleh PHP.

Berikut adalah solusi siap pakai.

### Prasyarat (Wajib dilakukan di Terminal Raspberry Pi sekali saja)

Sebelum menggunakan script di bawah, install dan config Rclone di Raspberry Pi kamu agar terkoneksi ke Google Drive.

1. **Install:** `sudo apt install rclone`
2. **Config:** `rclone config` (Ikuti langkah untuk connect ke Google Drive, beri nama remote misal: `gdrive`).
3. **Pastikan:** Coba run `rclone ls gdrive:` di terminal. Jika muncul list file, berarti siap.

---

### 1. Update: `backup_db.php`

**Logika Tambahan:** Script ini akan melakukan backup lokal terlebih dahulu. Jika sukses dan valid, script akan memerintahkan Rclone untuk mengupload file spesifik tersebut ke Google Drive.

```php
<?php
// Simple DB backup endpoint with Google Drive Sync (protected)
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: text/plain; charset=utf-8');

// KONFIGURASI RCLONE (Sesuaikan dengan nama remote di rclone config)
$rcloneBin = '/usr/bin/rclone'; // Lokasi binary rclone
$rcloneRemote = 'gdrive:backup_wartelpas'; // Format: nama_remote:nama_folder

$secret = 'WartelpasSecureKey';
$key = $_GET['key'] ?? '';
if ($key === '' && isset($_POST['key'])) {
    $key = (string)$_POST['key'];
}
if ($key === '' && isset($_SERVER['HTTP_X_BACKUP_KEY'])) {
    $key = (string)$_SERVER['HTTP_X_BACKUP_KEY'];
}
if (!hash_equals($secret, (string)$key)) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

$allowedIpList = ['127.0.0.1', '::1', '10.10.83.1', '172.19.0.1'];
if (!empty($_SERVER['REMOTE_ADDR']) && !empty($allowedIpList)) {
    $clientIp = (string)$_SERVER['REMOTE_ADDR'];
    if (!in_array($clientIp, $allowedIpList, true)) {
        http_response_code(403);
        echo "IP not allowed";
        exit;
    }
}

$rateFile = sys_get_temp_dir() . '/backup_db.rate';
$rateWindow = 300;
$rateLimit = 1;
$now = time();
$hits = [];
if (is_file($rateFile)) {
    $raw = @file_get_contents($rateFile);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $hits = $decoded;
    }
}
$hits = array_values(array_filter($hits, function($t) use ($now, $rateWindow) {
    return is_int($t) && ($now - $t) <= $rateWindow;
}));
if (count($hits) >= $rateLimit) {
    http_response_code(429);
    echo "Rate limited";
    exit;
}
$hits[] = $now;
@file_put_contents($rateFile, json_encode($hits));

$root = dirname(__DIR__);
$dbFile = $root . '/db_data/mikhmon_stats.db';
if (!file_exists($dbFile)) {
    http_response_code(404);
    echo "DB not found";
    exit;
}

$backupDir = $root . '/db_data/backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0777, true);
}
if (!is_dir($backupDir) || !is_writable($backupDir)) {
    http_response_code(500);
    echo "Backup dir not writable";
    exit;
}

$keepDays = isset($_GET['keep_days']) ? (int)$_GET['keep_days'] : 14;
$keepCount = isset($_GET['keep_count']) ? (int)$_GET['keep_count'] : 30;
if ($keepDays <= 0) $keepDays = 14;
if ($keepCount <= 0) $keepCount = 30;

$stamp = date('Ymd_His');
$backupFile = $backupDir . '/mikhmon_stats_' . $stamp . '.db';
$tempFile = $backupFile . '.tmp';

$srcSize = @filesize($dbFile);
if (!$srcSize || $srcSize < 1024 * 64) {
    http_response_code(500);
    echo "Source DB too small or unreadable";
    exit;
}

$ok = false;
$message = '';
try {
    if (class_exists('SQLite3')) {
        $src = new SQLite3($dbFile, SQLITE3_OPEN_READONLY);
        $dest = new SQLite3($tempFile, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $src->backup($dest);
        $dest->close();
        $src->close();
        $ok = true;
    } else {
        $ok = @copy($dbFile, $tempFile);
    }
} catch (Exception $e) {
    $message = 'Backup failed.';
}

if (!$ok || !file_exists($tempFile)) {
    http_response_code(500);
    echo $message ?: 'Backup failed';
    exit;
}

$tmpSize = @filesize($tempFile);
if (!$tmpSize || $tmpSize < ($srcSize * 0.8)) {
    @unlink($tempFile);
    http_response_code(500);
    echo "Backup size invalid";
    exit;
}

try {
    if (class_exists('SQLite3')) {
        $chk = new SQLite3($tempFile, SQLITE3_OPEN_READONLY);
        $res = $chk->querySingle('PRAGMA quick_check;');
        $chk->close();
        if (strtolower((string)$res) !== 'ok') {
            throw new Exception('quick_check failed');
        }
    }
} catch (Exception $e) {
    @unlink($tempFile);
    http_response_code(500);
    echo "Backup integrity failed";
    exit;
}

if (!@rename($tempFile, $backupFile)) {
    @unlink($tempFile);
    http_response_code(500);
    echo "Failed to finalize backup";
    exit;
}

// START GOOGLE DRIVE UPLOAD LOGIC
$cloudStatus = "Skipped";
if (file_exists($rcloneBin) && !empty($rcloneRemote)) {
    // Copy only the new file to save bandwidth
    $cmd = sprintf('%s copyto "%s" "%s/%s" 2>&1', $rcloneBin, $backupFile, $rcloneRemote, basename($backupFile));
    exec($cmd, $output, $returnVar);
    $cloudStatus = ($returnVar === 0) ? "Uploaded to Drive" : "Upload Failed";
}
// END GOOGLE DRIVE UPLOAD LOGIC

$logFile = $root . '/logs/backup_db.log';
$logLine = date('Y-m-d H:i:s') . "\t" . basename($backupFile) . "\t" . ($tmpSize ?? 0) . "\t" . $cloudStatus . "\n";
@file_put_contents($logFile, $logLine, FILE_APPEND);

// Cleanup old backups by days
$files = glob($backupDir . '/mikhmon_stats_*.db') ?: [];
$now = time();
$deleted = 0;
foreach ($files as $f) {
    $mtime = @filemtime($f);
    if ($mtime && $now - $mtime > ($keepDays * 86400)) {
        if (@unlink($f)) $deleted++;
    }
}

// Cleanup old backups by count (keep newest)
$files = glob($backupDir . '/mikhmon_stats_*.db') ?: [];
usort($files, function($a, $b) {
    return filemtime($b) <=> filemtime($a);
});
if (count($files) > $keepCount) {
    $toDelete = array_slice($files, $keepCount);
    foreach ($toDelete as $f) {
        if (@unlink($f)) $deleted++;
    }
}

// Cleanup WAL/SHM/temp artifacts in backup folder
$sidecars = array_merge(
    glob($backupDir . '/mikhmon_stats_*.db-wal') ?: [],
    glob($backupDir . '/mikhmon_stats_*.db-shm') ?: [],
    glob($backupDir . '/mikhmon_stats_*.db.tmp-wal') ?: [],
    glob($backupDir . '/mikhmon_stats_*.db.tmp-shm') ?: [],
    glob($backupDir . '/mikhmon_stats_*.db.tmp') ?: []
);
foreach ($sidecars as $f) {
    if (@unlink($f)) $deleted++;
}

echo "OK\n";
echo "Backup: " . basename($backupFile) . "\n";
echo "Cloud: " . $cloudStatus . "\n";
echo "Deleted: " . $deleted . "\n";

```

---

### 2. Update: `restore_db.php`

**Logika Tambahan:** Script akan mengecek folder `backups` lokal. Jika **kosong** (tidak ada file .db), script akan otomatis menjalankan Rclone untuk mendownload file dari Google Drive ke folder backups, baru kemudian melakukan proses restore seperti biasa.

```php
<?php
// Restore SQLite DB from backup with Google Drive Fallback (protected)
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: text/html; charset=utf-8');

// KONFIGURASI RCLONE
$rcloneBin = '/usr/bin/rclone'; 
$rcloneRemote = 'gdrive:backup_wartelpas'; 

$secret = 'WartelpasSecureKey';
$key = $_GET['key'] ?? '';
if ($key === '' && isset($_POST['key'])) {
    $key = (string)$_POST['key'];
}
if ($key === '' && isset($_SERVER['HTTP_X_BACKUP_KEY'])) {
    $key = (string)$_SERVER['HTTP_X_BACKUP_KEY'];
}
if (!hash_equals($secret, (string)$key)) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

$allowedIpList = ['127.0.0.1', '::1', '10.10.83.1', '172.19.0.1'];
if (!empty($_SERVER['REMOTE_ADDR']) && !empty($allowedIpList)) {
    $clientIp = (string)$_SERVER['REMOTE_ADDR'];
    if (!in_array($clientIp, $allowedIpList, true)) {
        http_response_code(403);
        echo "IP not allowed";
        exit;
    }
}

$rateFile = sys_get_temp_dir() . '/restore_db.rate';
$rateWindow = 300;
$rateLimit = 1;
$now = time();
$hits = [];
if (is_file($rateFile)) {
    $raw = @file_get_contents($rateFile);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $hits = $decoded;
    }
}
$hits = array_values(array_filter($hits, function($t) use ($now, $rateWindow) {
    return is_int($t) && ($now - $t) <= $rateWindow;
}));
if (count($hits) >= $rateLimit) {
    http_response_code(429);
    echo "Rate limited";
    exit;
}
$hits[] = $now;
@file_put_contents($rateFile, json_encode($hits));

$backupDir = dirname(__DIR__) . '/db_data/backups';
$dbFile = dirname(__DIR__) . '/db_data/mikhmon_stats.db';

if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0777, true); // Create if missing
}

// Initial Scan
$files = array_values(array_filter(scandir($backupDir), function ($f) use ($backupDir) {
    return is_file($backupDir . '/' . $f) && preg_match('/\.db$/i', $f);
}));

// START GOOGLE DRIVE FALLBACK LOGIC
$downloaded = false;
// Jika tidak ada file backup lokal, coba tarik dari Google Drive
if (empty($files)) {
    if (file_exists($rcloneBin) && !empty($rcloneRemote)) {
        // Sync one way: Drive -> Local. Hanya ambil file .db
        $cmd = sprintf('%s copy "%s" "%s" --include "*.db" 2>&1', $rcloneBin, $rcloneRemote, $backupDir);
        exec($cmd, $output, $returnVar);
        
        if ($returnVar === 0) {
            $downloaded = true;
            // Scan ulang folder setelah download
            $files = array_values(array_filter(scandir($backupDir), function ($f) use ($backupDir) {
                return is_file($backupDir . '/' . $f) && preg_match('/\.db$/i', $f);
            }));
        }
    }
}
// END GOOGLE DRIVE FALLBACK LOGIC

if (empty($files)) {
    echo "No backup files (Local or Cloud)";
    exit;
}

rsort($files);
$target = $_GET['file'] ?? $files[0];
$target = basename((string)$target);
$src = $backupDir . '/' . $target;

if (!file_exists($src)) {
    echo "Backup not found";
    exit;
}

if (!is_writable(dirname($dbFile))) {
    echo "DB folder not writable";
    exit;
}

if (!is_readable($src)) {
    echo "Backup not readable";
    exit;
}

$tmpRestore = $dbFile . '.restore-tmp';
if (!copy($src, $tmpRestore)) {
    echo "Restore failed";
    exit;
}

try {
    if (!class_exists('PDO') || !extension_loaded('pdo_sqlite')) {
        throw new Exception('PDO SQLite not available');
    }
    $db = new PDO('sqlite:' . $tmpRestore);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA quick_check;');
    $db->exec('VACUUM;');
} catch (Exception $e) {
    @unlink($tmpRestore);
    echo "Restore integrity failed: " . htmlspecialchars($e->getMessage());
    exit;
}

if (!@rename($tmpRestore, $dbFile)) {
    @unlink($tmpRestore);
    echo "Failed to finalize restore";
    exit;
}

$logFile = dirname(__DIR__) . '/logs/restore_db.log';
$logLine = date('Y-m-d H:i:s') . "\t" . $target . "\t" . ($downloaded ? "From Cloud" : "Local") . "\n";
@file_put_contents($logFile, $logLine, FILE_APPEND);

echo "Restore OK: " . htmlspecialchars($target);
if ($downloaded) {
    echo " (Restored from Google Drive)";
}

```