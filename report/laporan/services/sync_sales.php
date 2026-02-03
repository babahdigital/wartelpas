<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../include/acl.php';
if (isset($_SESSION['mikhmon']) && isOperator()) {
    requireSuperAdmin('../../../admin.php?id=sessions');
}
// FILE: report/laporan/services/sync_sales.php
// Created by Gemini AI for Pak Dul
// FUNGSI: Memindahkan Log Penjualan dari MikroTik ke SQLite & Membersihkan MikroTik

ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain');

// 1. TOKEN PENGAMAN
$root_dir = dirname(__DIR__, 3);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
$system_cfg = $env['system'] ?? [];
$db_rel = $system_cfg['db_file'] ?? 'db_data/babahdigital_main.db';
if (preg_match('/^[A-Za-z]:\\\\|^\//', $db_rel)) {
    $dbFile = $db_rel;
} else {
    $dbFile = $root_dir . '/' . ltrim($db_rel, '/');
}
$expected_hotspot = $system_cfg['hotspot_server'] ?? 'wartel';
require_once($root_dir . '/include/config.php');

// Optional IP allowlist (comma-separated), e.g. "127.0.0.1,192.168.1.10"
$allowlist_raw = getenv('WARTELPAS_SYNC_ALLOWLIST');
$cfg_sync_sales = $env['security']['sync_sales'] ?? [];
$cfg_allowlist = $cfg_sync_sales['allowlist'] ?? [];
if (!empty($cfg_allowlist)) {
    $allowed = array_filter(array_map('trim', (array)$cfg_allowlist));
} elseif ($allowlist_raw !== false && trim((string)$allowlist_raw) !== '') {
    $allowed = array_filter(array_map('trim', explode(',', $allowlist_raw)));
} else {
    $allowed = [];
}
if (!empty($allowed)) {
    $remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($remote_ip === '' || !in_array($remote_ip, $allowed, true)) {
        http_response_code(403);
        die("Error: IP tidak diizinkan.");
    }
}

$secret_token = $cfg_sync_sales['token'] ?? '';
if ($secret_token === '') {
    $secret_token = getenv('WARTELPAS_SYNC_TOKEN');
    if ($secret_token === false || trim((string)$secret_token) === '') {
        if (defined('WARTELPAS_SYNC_TOKEN')) {
            $secret_token = WARTELPAS_SYNC_TOKEN;
        } else {
            $secret_token = $env['backup']['secret'] ?? '';
        }
    }
}
if (!isset($_GET['key']) || $_GET['key'] !== $secret_token) {
    http_response_code(403);
    die("Error: Token Salah.");
}

$session = isset($_GET['session']) ? $_GET['session'] : '';
if ($session === '' || !isset($data[$session])) {
    http_response_code(403);
    die("Error: Session tidak valid.");
}

// 2. LIBRARY API
require_once($root_dir . '/lib/routeros_api.class.php');
require_once($root_dir . '/include/readcfg.php');
if (file_exists($root_dir . '/report/laporan/helpers.php')) {
    require_once($root_dir . '/report/laporan/helpers.php');
}

if (!isset($hotspot_server) || $hotspot_server !== $expected_hotspot) {
    die("Error: Hanya untuk server wartel.");
}

// 3. SETTING LOGIN DARI KONFIG
$use_ip   = $iphost;       
$use_user = $userhost;         
$use_pass = decrypt($passwdhost); 

// 4. KONEKSI DATABASE
$dbDir = dirname($dbFile);
if (!is_dir($dbDir)) mkdir($dbDir, 0777, true);

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
                        try { $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_sales_user_date ON sales_history(username, sale_date)"); } catch(Exception $e) {}
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
        $summaryHelper = $root_dir . '/report/laporan/sales_summary_helper.php';
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
        try { $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_live_user_date ON live_sales(username, sale_date)"); } catch(Exception $e) {}
        try { $db->exec("ALTER TABLE live_sales ADD COLUMN sprice_snapshot INTEGER"); } catch (Exception $e) {}
} catch (PDOException $e) {
    die("Error DB: " . $e->getMessage());
}

// 5. EKSEKUSI
$API = new RouterosAPI();

if ($API->connect($use_ip, $use_user, $use_pass)) {
    
    $commentFilter = isset($_GET['comment']) ? trim((string)$_GET['comment']) : 'mikhmon';
    // Ambil Script yang komentarnya "mikhmon" (Ciri khas script penjualan)
    $API->write('/system/script/print', false);
    if ($commentFilter !== '' && strtolower($commentFilter) !== 'any') {
        $API->write('?comment=' . $commentFilter, false);
    }
    $API->write('=.proplist=.id,name,comment');
    $scripts = $API->read();
    if (empty($scripts) && ($commentFilter === '' || strtolower($commentFilter) !== 'any')) {
        // Fallback: ambil semua script dan filter manual berdasarkan format data penjualan
        $API->write('/system/script/print', false);
        $API->write('=.proplist=.id,name,comment');
        $allScripts = $API->read();
        $scripts = [];
        foreach ($allScripts as $s) {
            $nm = $s['name'] ?? '';
            if ($nm === '') continue;
            if (strpos($nm, '-|-') === false && strpos($nm, '-|') === false) continue;
            if (preg_match('/^[A-Za-z]{3}\/[0-9]{2}\/[0-9]{4}-\|-?/', $nm) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}-\|-?/', $nm) || preg_match('/^[0-9]{2}\/[0-9]{2}\/[0-9]{4}-\|-?/', $nm)) {
                $scripts[] = $s;
            }
        }
    }
    echo "Script ditemukan: " . count($scripts) . "\n";
    
    $count = 0;
    $debug = isset($_GET['debug']) && $_GET['debug'] === '1';
    $skip_invalid_format = 0;
    $skip_blok = 0;
    $skip_duplicate = 0;
    $sample_names = [];
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
        if ($debug && count($sample_names) < 5) {
            $sample_names[] = $rawData;
        }
        
        // Pecah Data (Explode)
        $d = function_exists('split_sales_raw') ? split_sales_raw($rawData) : explode('-|-', $rawData);
        
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
                $skip_blok++;
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
            if (strpos($cmt_low, 'retur') !== false) $status = 'retur';
            elseif (strpos($cmt_low, 'rusak') !== false) $status = 'rusak';
            elseif (strpos($cmt_low, 'invalid') !== false) $status = 'invalid';

            if ($username !== '' && $sale_date !== '') {
                $dupStmt = $db->prepare("SELECT 1 FROM sales_history WHERE username = :u AND sale_date = :d LIMIT 1");
                $dupStmt->execute([':u' => $username, ':d' => $sale_date]);
                if ($dupStmt->fetchColumn()) {
                    $skip_duplicate++;
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
        } else {
            $skip_invalid_format++;
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
                $final_comment = $r['comment'] ?? '';
                $final_status = $r['status'] ?? 'normal';
                $is_r = (int)($r['is_rusak'] ?? 0);
                $is_rt = (int)($r['is_retur'] ?? 0);
                $is_inv = (int)($r['is_invalid'] ?? 0);

                if ($final_status === 'normal') {
                    if (stripos($final_comment, 'retur') !== false) { $final_status = 'retur'; $is_rt = 1; }
                    elseif (stripos($final_comment, 'rusak') !== false) { $final_status = 'rusak'; $is_r = 1; }
                    elseif (stripos($final_comment, 'invalid') !== false) { $final_status = 'invalid'; $is_inv = 1; }
                }

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
                    ':cmt' => $final_comment,
                    ':blok' => $r['blok_name'] ?? '',
                    ':status' => $final_status,
                    ':is_rusak' => $is_r,
                    ':is_retur' => $is_rt,
                    ':is_invalid' => $is_inv,
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

    if (function_exists('rebuild_audit_expected_for_date')) {
        try {
            $rebuild_date = date('Y-m-d');
            $updated = rebuild_audit_expected_for_date($db, $rebuild_date);
            if (function_exists('log_audit_warning')) {
                log_audit_warning($db, $rebuild_date, 'sync_status', 'Sistem sudah disinkronisasi.');
            }
        } catch (Exception $e) {}
    }

    if (function_exists('log_audit_warning')) {
        $log_date = date('Y-m-d');
        if ($skip_invalid_format > 0) {
            log_audit_warning($db, $log_date, 'sync_sales', 'Format raw tidak terbaca: ' . $skip_invalid_format . ' item.');
        }
        if ($skip_blok > 0) {
            log_audit_warning($db, $log_date, 'sync_sales', 'Transaksi tanpa BLOK di-skip: ' . $skip_blok . ' item.');
        }
        if ($skip_duplicate > 0) {
            log_audit_warning($db, $log_date, 'sync_sales', 'Duplikat di-skip: ' . $skip_duplicate . ' item.');
        }
    }

    echo "Sukses: $count laporan penjualan dipindahkan ke Database & dihapus dari MikroTik.\n";
    if ($debug) {
        echo "Debug: invalid_format={$skip_invalid_format}, blok_kosong={$skip_blok}, duplikat={$skip_duplicate}\n";
        if (!empty($sample_names)) {
            echo "Sample script name:\n" . implode("\n", $sample_names) . "\n";
        }
    }
    if ($count === 0) {
        echo "Catatan: tidak ada script penjualan baru atau comment tidak cocok.\n";
        echo "Hint: tambahkan ?comment=any untuk memindai semua script.\n";
    }
    
} else {
    echo "Gagal Login MikroTik.";
}
?>
