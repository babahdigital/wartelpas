<?php
// FILE: report/sync_stats.php
// Modified by Pak Dul & Gemini AI (2026)
// UPDATE: AUTO VALIDATION & AUDIT INJECTION

ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain');

// TOKEN PENGAMAN
$secret_token = "WartelpasSecureKey"; 
if (!isset($_GET['key']) || $_GET['key'] !== $secret_token) {
    http_response_code(403); die("Error: Akses Ditolak. Token salah.");
}

// LIBRARY
$root_dir = dirname(__DIR__); 
$apiFile = $root_dir . '/lib/routeros_api.class.php';
if (file_exists($apiFile)) { require_once($apiFile); } 
else { die("CRITICAL ERROR: File library routeros_api.class.php tidak ditemukan."); }

// SETTING MIKROTIK
$use_ip   = "10.10.83.1";       
$use_user = "abdullah";         
$use_pass = "alhabsyi"; 

// DATABASE SETUP
$dbDir = $root_dir . '/db_data';
$dbFile = $dbDir . '/mikhmon_stats.db';
if (!is_dir($dbDir)) { mkdir($dbDir, 0777, true); }

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Tabel Statistik User
    $db->exec("CREATE TABLE IF NOT EXISTS user_stats (
                username TEXT PRIMARY KEY,
                uptime TEXT,
                bytes_total INTEGER,
                last_updated DATETIME DEFAULT CURRENT_TIMESTAMP
              )");

    // Tabel Audit (Untuk menampung user tidak valid)
    $db->exec("CREATE TABLE IF NOT EXISTS security_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        log_date DATETIME,
        username TEXT,
        ip_address TEXT, 
        mac_address TEXT,
        reason TEXT,
        comment TEXT,
        action_taken TEXT
    )");
    
} catch (PDOException $e) { die("Error DB: " . $e->getMessage()); }

// EKSEKUSI UTAMA
$API = new RouterosAPI();
if ($API->connect($use_ip, $use_user, $use_pass)) {
    
    // Ambil User yang aktif/ada traffic
    $API->write('/ip/hotspot/user/print', false);
    $API->write('=.proplist=.id,name,uptime,bytes-in,bytes-out,comment,mac-address,profile'); 
    $users = $API->read();
    
    $count_valid = 0;
    $count_audit = 0;
    
    // Prepare Statement
    $stmtStats = $db->prepare("INSERT OR REPLACE INTO user_stats (username, uptime, bytes_total, last_updated) VALUES (:user, :upt, :bytes, datetime('now','localtime'))");
    $stmtAudit = $db->prepare("INSERT INTO security_log (log_date, username, mac_address, reason, comment, action_taken) VALUES (datetime('now','localtime'), :user, :mac, :reason, :comm, 'TAGGED_INVALID')");

    foreach ($users as $u) {
        $uid = $u['.id'];
        $uName = $u['name'];
        $uComm = isset($u['comment']) ? $u['comment'] : '';
        $uMac  = isset($u['mac-address']) ? $u['mac-address'] : '';
        $uProf = isset($u['profile']) ? $u['profile'] : '';
        
        $bytesIn  = isset($u['bytes-in']) ? intval($u['bytes-in']) : 0;
        $bytesOut = isset($u['bytes-out']) ? intval($u['bytes-out']) : 0;
        $totalBytes = $bytesIn + $bytesOut;
        $uptimeStr = isset($u['uptime']) ? $u['uptime'] : '0s';

        // 1. SIMPAN STATISTIK (Hanya jika ada pemakaian)
        if ($totalBytes > 1024) { // Minimal 1KB baru dianggap aktif
            $stmtStats->bindValue(':user', $uName);
            $stmtStats->bindValue(':upt', $uptimeStr);
            $stmtStats->bindValue(':bytes', $totalBytes);
            $stmtStats->execute();

            // 2. LOGIKA VALIDASI & AUDIT
            // Cek apakah user ini sudah ditandai?
            $is_already_valid = (stripos($uComm, 'Valid:') !== false);
            $is_already_audit = (stripos($uComm, 'Audit:') !== false);
            
            // Cek apakah format sesuai aturan penjualan (Harus ada 'Blok-')
            $is_legit_format = (stripos($uComm, 'Blok-') !== false);

            if (!$is_already_valid && !$is_already_audit) {
                
                if ($is_legit_format) {
                    // KASUS A: Format Benar (Ada Blok-) & Ada Traffic -> TANDAI VALID
                    $newComm = "Valid: " . $uComm;
                    $API->write('/ip/hotspot/user/set', false);
                    $API->write('=.id=' . $uid, false);
                    $API->write('=comment=' . $newComm);
                    $API->read();
                    $count_valid++;
                    echo "VALIDATED: $uName ($newComm)\n";
                    
                } else {
                    // KASUS B: Format Salah (Gak ada Blok-) TAPI Ada Traffic -> MASUK AUDIT
                    // Kecuali profile tertentu (misal default-trial), sesuaikan jika perlu
                    if (stripos($uName, 'default') === false) { 
                        $reason = "Usage without Blok- Tag";
                        
                        // Masukkan ke DB Audit
                        $stmtAudit->bindValue(':user', $uName);
                        $stmtAudit->bindValue(':mac', $uMac);
                        $stmtAudit->bindValue(':reason', $reason);
                        $stmtAudit->bindValue(':comm', $uComm);
                        $stmtAudit->execute();
                        
                        // Tandai di Mikrotik biar gak masuk lagi
                        $newComm = "Audit: " . $uComm;
                        $API->write('/ip/hotspot/user/set', false);
                        $API->write('=.id=' . $uid, false);
                        $API->write('=comment=' . $newComm);
                        $API->read();
                        
                        $count_audit++;
                        echo "AUDITED: $uName (Alasan: $reason)\n";
                    }
                }
            }
        }
    }
    
    $API->disconnect();
    echo "\n------------------------\n";
    echo "PROSES SELESAI.\n";
    echo "User divalidasi (Sales): $count_valid\n";
    echo "User diaudit (Invalid): $count_audit\n";
    
} else {
    echo "Error: Gagal Login ke MikroTik.";
}
?>