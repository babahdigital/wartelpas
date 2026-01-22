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
                                raw_time TEXT,
                                sale_date TEXT,
                                sale_time TEXT,
                                sale_datetime TEXT,
                                username TEXT,
                                profile TEXT,
                                profile_snapshot TEXT,
                                price INTEGER,
                                price_snapshot INTEGER,
                                sprice_snapshot INTEGER,
                                validity TEXT,
                                comment TEXT,
                                blok_name TEXT,
                                status TEXT,
                                is_rusak INTEGER,
                                is_retur INTEGER,
                                is_invalid INTEGER,
                                qty INTEGER,
                                full_raw_data TEXT UNIQUE,
                                sync_date DATETIME DEFAULT CURRENT_TIMESTAMP
                            )");
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN raw_time TEXT"); } catch(Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN sale_date TEXT"); } catch(Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN sale_time TEXT"); } catch(Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN sale_datetime TEXT"); } catch(Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN profile_snapshot TEXT"); } catch(Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN price_snapshot INTEGER"); } catch(Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN sprice_snapshot INTEGER"); } catch(Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN validity TEXT"); } catch(Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN status TEXT"); } catch(Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN is_rusak INTEGER"); } catch(Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN is_retur INTEGER"); } catch(Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN is_invalid INTEGER"); } catch(Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN qty INTEGER"); } catch(Exception $e) {}
        try { $db->exec("ALTER TABLE sales_history ADD COLUMN blok_name TEXT"); } catch(Exception $e) {}

        // Helper rekap otomatis
        $summaryHelper = $root_dir . '/report/sales_summary_helper.php';
        if (file_exists($summaryHelper)) {
            require_once($summaryHelper);
        }

        // Tabel live_sales untuk realtime
        $db->exec("CREATE TABLE IF NOT EXISTS live_sales (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            raw_date TEXT,
            raw_time TEXT,
            sale_date TEXT,
            sale_time TEXT,
            sale_datetime TEXT,
            username TEXT,
            profile TEXT,
            profile_snapshot TEXT,
            price INTEGER,
            price_snapshot INTEGER,
            sprice_snapshot INTEGER,
            validity TEXT,
            comment TEXT,
            blok_name TEXT,
            status TEXT,
            is_rusak INTEGER,
            is_retur INTEGER,
            is_invalid INTEGER,
            qty INTEGER,
            full_raw_data TEXT UNIQUE,
            sync_status TEXT DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            synced_at DATETIME
        )");
        try { $db->exec("ALTER TABLE live_sales ADD COLUMN sprice_snapshot INTEGER"); } catch (Exception $e) {}
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
    $stmt = $db->prepare("INSERT OR IGNORE INTO sales_history (
        raw_date, raw_time, sale_date, sale_time, sale_datetime,
        username, profile, profile_snapshot,
        price, price_snapshot, sprice_snapshot, validity,
        comment, blok_name, status, is_rusak, is_retur, is_invalid, qty,
        full_raw_data
    ) VALUES (
        :rd, :rt, :sd, :st, :sdt,
        :usr, :prof, :prof_snap,
        :prc, :prc_snap, :sprc_snap, :valid,
        :cmt, :blok, :status, :is_rusak, :is_retur, :is_invalid, :qty,
        :raw
    )");

    foreach ($scripts as $s) {
        $rawData = $s['name']; // Format: jan/11/2026-|-09:00:00-|-user-|-price...
        
        // Pecah Data (Explode)
        $d = explode("-|-", $rawData);
        
        // Pastikan formatnya benar (Minimal ada 4 elemen)
        if (count($d) >= 4) {
            $raw_date = $d[0]; // Tanggal
            $raw_time = $d[1] ?? ''; // Jam
            $username = $d[2]; // User
            $price    = (int)$d[3]; // Harga
            $profile  = isset($d[7]) ? $d[7] : ''; // Profil
            $validity = $d[6] ?? '';
            $comment  = isset($d[8]) ? $d[8] : ''; // Komentar
            $blok_name = '';
            if ($comment && preg_match('/\bblok\s*[-_]?\s*([A-Za-z0-9]+)/i', $comment, $m)) {
                $blok_name = 'BLOK-' . strtoupper($m[1]);
            }
            if ($blok_name === '') {
                $API->write('/system/script/remove', false);
                $API->write('=.id=' . $s['.id']);
                $API->read();
                continue;
            }

            $sale_date = '';
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw_date)) {
                $sale_date = $raw_date;
            }
            if ($sale_date === '' && preg_match('/^[a-zA-Z]{3}\/\d{2}\/\d{4}$/', $raw_date)) {
                $mon = strtolower(substr($raw_date, 0, 3));
                $map = [
                    'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04', 'may' => '05', 'jun' => '06',
                    'jul' => '07', 'aug' => '08', 'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12'
                ];
                $mm = $map[$mon] ?? '';
                if ($mm !== '') {
                    $parts = explode('/', $raw_date);
                    $sale_date = $parts[2] . '-' . $mm . '-' . $parts[1];
                }
            }
            if ($sale_date === '' && preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $raw_date)) {
                $parts = explode('/', $raw_date);
                $sale_date = $parts[2] . '-' . $parts[0] . '-' . $parts[1];
            }
            $sale_time = $raw_time ?: '';
            $sale_datetime = ($sale_date && $sale_time) ? ($sale_date . ' ' . $sale_time) : '';

            $cmt_low = strtolower($comment);
            $status = 'normal';
            if (strpos($cmt_low, 'invalid') !== false) $status = 'invalid';
            elseif (strpos($cmt_low, 'rusak') !== false) $status = 'rusak';
            elseif (strpos($cmt_low, 'retur') !== false) $status = 'retur';

            if ($username !== '' && $sale_date !== '') {
                $dupStmt = $db->prepare("SELECT 1 FROM sales_history WHERE username = :u AND sale_date = :d LIMIT 1");
                $dupStmt->execute([':u' => $username, ':d' => $sale_date]);
                if ($dupStmt->fetchColumn()) {
                    $API->write('/system/script/remove', false);
                    $API->write('=.id=' . $s['.id']);
                    $API->read();
                    continue;
                }
            }
            
            // Simpan ke DB
            $stmt->bindValue(':rd', $raw_date);
            $stmt->bindValue(':rt', $raw_time);
            $stmt->bindValue(':sd', $sale_date);
            $stmt->bindValue(':st', $sale_time);
            $stmt->bindValue(':sdt', $sale_datetime);
            $stmt->bindValue(':usr', $username);
            $stmt->bindValue(':prof', $profile);
            $stmt->bindValue(':prof_snap', $profile ?: '');
            $stmt->bindValue(':prc', $price);
            $stmt->bindValue(':prc_snap', $price);
            $stmt->bindValue(':sprc_snap', 0);
            $stmt->bindValue(':valid', $validity);
            $stmt->bindValue(':cmt', $comment);
            $stmt->bindValue(':blok', $blok_name);
            $stmt->bindValue(':status', $status);
            $stmt->bindValue(':is_rusak', $status === 'rusak' ? 1 : 0);
            $stmt->bindValue(':is_retur', $status === 'retur' ? 1 : 0);
            $stmt->bindValue(':is_invalid', $status === 'invalid' ? 1 : 0);
            $stmt->bindValue(':qty', 1);
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

    // Pindahkan data live (pending) ke sales_history saat settlement
    try {
        $liveRows = $db->query("SELECT * FROM live_sales WHERE sync_status = 'pending'");
        $liveRows = $liveRows ? $liveRows->fetchAll(PDO::FETCH_ASSOC) : [];
        if (!empty($liveRows)) {
            $ins = $db->prepare("INSERT OR IGNORE INTO sales_history (
                raw_date, raw_time, sale_date, sale_time, sale_datetime,
                username, profile, profile_snapshot,
                price, price_snapshot, sprice_snapshot, validity,
                comment, blok_name, status, is_rusak, is_retur, is_invalid, qty,
                full_raw_data
            ) VALUES (
                :rd, :rt, :sd, :st, :sdt,
                :usr, :prof, :prof_snap,
                :prc, :prc_snap, :sprc_snap, :valid,
                :cmt, :blok, :status, :is_rusak, :is_retur, :is_invalid, :qty,
                :raw
            )");

            foreach ($liveRows as $r) {
                $ins->execute([
                    ':rd' => $r['raw_date'] ?? '',
                    ':rt' => $r['raw_time'] ?? '',
                    ':sd' => $r['sale_date'] ?? '',
                    ':st' => $r['sale_time'] ?? '',
                    ':sdt' => $r['sale_datetime'] ?? '',
                    ':usr' => $r['username'] ?? '',
                    ':prof' => $r['profile'] ?? '',
                    ':prof_snap' => $r['profile_snapshot'] ?? '',
                    ':prc' => (int)($r['price'] ?? 0),
                    ':prc_snap' => (int)($r['price_snapshot'] ?? ($r['price'] ?? 0)),
                    ':sprc_snap' => 0,
                    ':valid' => $r['validity'] ?? '',
                    ':cmt' => $r['comment'] ?? '',
                    ':blok' => $r['blok_name'] ?? '',
                    ':status' => $r['status'] ?? 'normal',
                    ':is_rusak' => (int)($r['is_rusak'] ?? 0),
                    ':is_retur' => (int)($r['is_retur'] ?? 0),
                    ':is_invalid' => (int)($r['is_invalid'] ?? 0),
                    ':qty' => (int)($r['qty'] ?? 1),
                    ':raw' => $r['full_raw_data'] ?? ''
                ]);
            }

            $db->exec("UPDATE live_sales SET sync_status='final', synced_at=CURRENT_TIMESTAMP WHERE sync_status='pending'");
        }
    } catch (Exception $e) {
        // Abaikan error live agar sync tetap sukses
    }

    // Rebuild rekap otomatis agar laporan cepat
    if (function_exists('rebuild_sales_summary')) {
        try {
            rebuild_sales_summary($db);
        } catch (Exception $e) {
            // Abaikan error rekap agar sync tetap sukses
        }
    }

    echo "Sukses: $count laporan penjualan dipindahkan ke Database & dihapus dari MikroTik.";
    
} else {
    echo "Gagal Login MikroTik.";
}
?>