<?php
// FILE: report/sync_sales.php
// Created by Gemini AI for Pak Dul
// FUNGSI: Memindahkan Log Penjualan dari MikroTik ke SQLite & Membersihkan MikroTik

ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain');

// 1. TOKEN PENGAMAN
$secret_token = "WartelpasSecureKey"; 
if (!isset($_GET['key']) || $_GET['key'] !== $secret_token) {
    die("Error: Token Salah.");
}

$session = isset($_GET['session']) ? $_GET['session'] : '';
if ($session === '') {
    die("Error: Session tidak valid.");
}

// 2. LIBRARY API
$root_dir = dirname(__DIR__); 
require_once($root_dir . '/lib/routeros_api.class.php');
require_once($root_dir . '/include/config.php');
require_once($root_dir . '/include/readcfg.php');

if (!isset($hotspot_server) || $hotspot_server !== 'wartel') {
    die("Error: Hanya untuk server wartel.");
}

// 3. SETTING LOGIN DARI KONFIG
$use_ip   = $iphost;       
$use_user = $userhost;         
$use_pass = decrypt($passwdhost); 

// 4. KONEKSI DATABASE
$dbFile = $root_dir . '/db_data/mikhmon_stats.db';
if (!is_dir($root_dir . '/db_data')) mkdir($root_dir . '/db_data', 0777, true);

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("PRAGMA synchronous=NORMAL;");
    $db->exec("PRAGMA busy_timeout=2000;");

    // Buat Tabel Riwayat Penjualan (sales_history)
    // Struktur disesuaikan dengan format data script Mikhmon
    $db->exec("CREATE TABLE IF NOT EXISTS sales_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                raw_date TEXT,
                username TEXT,
                profile TEXT,
                price INTEGER,
                comment TEXT,
                blok_name TEXT,
                full_raw_data TEXT UNIQUE,
                sync_date DATETIME DEFAULT CURRENT_TIMESTAMP
              )");
    try { $db->exec("ALTER TABLE sales_history ADD COLUMN blok_name TEXT"); } catch(Exception $e) {}
} catch (PDOException $e) {
    die("Error DB: " . $e->getMessage());
}

// 5. EKSEKUSI
$API = new RouterosAPI();

if ($API->connect($use_ip, $use_user, $use_pass)) {
    
    // Ambil Script yang komentarnya "mikhmon" (Ciri khas script penjualan)
    $API->write('/system/script/print', false);
    $API->write('?comment=mikhmon', false);
    $API->write('=.proplist=.id,name');
    $scripts = $API->read();
    
    $count = 0;
    $stmt = $db->prepare("INSERT OR IGNORE INTO sales_history (raw_date, username, profile, price, comment, blok_name, full_raw_data) VALUES (:rd, :usr, :prof, :prc, :cmt, :blok, :raw)");

    foreach ($scripts as $s) {
        $rawData = $s['name']; // Format: jan/11/2026-|-09:00:00-|-user-|-price...
        
        // Pecah Data (Explode)
        $d = explode("-|-", $rawData);
        
        // Pastikan formatnya benar (Minimal ada 4 elemen)
        if (count($d) >= 4) {
            $raw_date = $d[0]; // Tanggal
            // $d[1] adalah jam
            $username = $d[2]; // User
            $price    = (int)$d[3]; // Harga
            $profile  = isset($d[7]) ? $d[7] : ''; // Profil
            $comment  = isset($d[8]) ? $d[8] : ''; // Komentar
            $blok_name = '';
            if ($comment && preg_match('/\bblok\s*[-_]?\s*([A-Za-z0-9]+)/i', $comment, $m)) {
                $blok_name = 'BLOK-' . strtoupper($m[1]);
            }
            
            // Simpan ke DB
            $stmt->bindValue(':rd', $raw_date);
            $stmt->bindValue(':usr', $username);
            $stmt->bindValue(':prof', $profile);
            $stmt->bindValue(':prc', $price);
            $stmt->bindValue(':cmt', $comment);
            $stmt->bindValue(':blok', $blok_name);
            $stmt->bindValue(':raw', $rawData); // Simpan mentahannya juga buat jaga2
            
            if ($stmt->execute()) {
                // JIKA BERHASIL SIMPAN -> HAPUS DARI MIKROTIK
                $API->write('/system/script/remove', false);
                $API->write('=.id=' . $s['.id']);
                $API->read();
                $count++;
            }
        }
    }
    
    $API->disconnect();
    echo "Sukses: $count laporan penjualan dipindahkan ke Database & dihapus dari MikroTik.";
    
} else {
    echo "Gagal Login MikroTik.";
}
?>