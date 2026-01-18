<?php
/*
 * WARTELPAS USER MANAGEMENT (V-FINAL OPTIMIZED & FIXED)
 * Update: 2026-01-20 - Fix Identitas Online & History IP
 * Fitur: 
 * 1. Identitas (Blok) tetap muncul meski User Online.
 * 2. History IP & MAC tersimpan otomatis dan muncul saat Logout.
 * 3. Logika Fallback Database diprioritaskan untuk data yang kosong.
 */

session_start();
// KONEKSI & SESSION CHECK
if (!isset($_SESSION["mikhmon"]) || !isset($_GET['session'])) {
    header("Location:../admin.php?id=login");
    exit();
}

// Include RouterOS API Library
include_once('../lib/routeros_api.class.php');

$session = $_GET['session'];
global $API; 
global $iphost, $userhost, $passwdhost;

// Helper: Format Bytes untuk display
if (!function_exists('formatBytes')) {
    function formatBytes($size, $precision = 2) {
        if ($size <= 0) return '0 B';
        $base = log($size, 1024);
        $suffixes = array('B', 'KB', 'MB', 'GB', 'TB');
        return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
    }
}

// Helper: Decrypt password (dari routeros_api.class.php)
if (!function_exists('decrypt')) {
    function decrypt($string, $key=128) {
        $result = '';
        $string = base64_decode($string);
        for($i=0, $k=strlen($string); $i< $k ; $i++) {
            $char = substr($string, $i, 1);
            $keychar = substr($key, ($i % strlen($key))-1, 1);
            $char = chr(ord($char)-ord($keychar));
            $result .= $char;
        }
        return $result;
    }
}

// --- [BAGIAN 1] DATABASE ENGINE (SQLITE) ---
$dbDir = dirname(__DIR__) . '/db_data';
if (!is_dir($dbDir)) mkdir($dbDir, 0755, true);
$dbFile = $dbDir . '/mikhmon_stats.db';

// Init Database Connection
$db = null;
try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $db->exec("CREATE TABLE IF NOT EXISTS security_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT, 
        log_date DATETIME, 
        username TEXT, 
        mac_address TEXT, 
        ip_address TEXT, 
        reason TEXT, 
        comment TEXT
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS login_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT,
        login_date TEXT,
        login_time TEXT,
        price TEXT,
        ip_address TEXT,
        mac_address TEXT,
        validity TEXT,
        blok_name TEXT,
        raw_comment TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(username)
    )"); 
} catch(Exception $e){ }

// Fungsi: Simpan Data Terbaru ke DB
function save_user_history($name, $data) {
    global $db;
    if(!$db) return;
    
    // Jangan simpan jika data kosong semua
    if(empty($data['blok']) && (empty($data['ip']) || $data['ip'] == '-')) return;

    try {
        // Query Upsert (Insert or Update)
        $stmt = $db->prepare("INSERT INTO login_history (username, login_date, login_time, price, ip_address, mac_address, validity, blok_name, raw_comment, updated_at) VALUES (:u, :ld, :lt, :p, :ip, :mac, :val, :bl, :raw, :upd) ON CONFLICT(username) DO UPDATE SET 
            blok_name = COALESCE(NULLIF(excluded.blok_name, ''), login_history.blok_name),
            ip_address = COALESCE(NULLIF(excluded.ip_address, '-'), login_history.ip_address),
            mac_address = COALESCE(NULLIF(excluded.mac_address, '-'), login_history.mac_address),
            raw_comment = excluded.raw_comment,
            updated_at = excluded.updated_at");
            
        $stmt->execute([
            ':u' => $name,
            ':ld' => $data['date'],
            ':lt' => $data['time'],
            ':p'  => $data['price'],
            ':ip' => $data['ip'],
            ':mac'=> $data['mac'],
            ':val'=> $data['validity'],
            ':bl' => $data['blok'],
            ':raw'=> $data['raw'],
            ':upd'=> date("Y-m-d H:i:s")
        ]);
    } catch (Exception $e) {
        // Fallback SQLite lama
        try {
            $stmt = $db->prepare("INSERT OR REPLACE INTO login_history (username, login_date, login_time, price, ip_address, mac_address, validity, blok_name, raw_comment, updated_at) VALUES (:u, :ld, :lt, :p, :ip, :mac, :val, :bl, :raw, :upd)");
            $stmt->execute([':u'=>$name, ':ld'=>$data['date'], ':lt'=>$data['time'], ':p'=>$data['price'], ':ip'=>$data['ip'], ':mac'=>$data['mac'], ':val'=>$data['validity'], ':bl'=>$data['blok'], ':raw'=>$data['raw'], ':upd'=>date("Y-m-d H:i:s")]);
        } catch(Exception $ex) {}
    }
}

// Fungsi: Ambil Data History
function get_user_history($name) {
    global $db;
    if(!$db) return null;
    try {
        $stmt = $db->prepare("SELECT * FROM login_history WHERE username = :u LIMIT 1");
        $stmt->execute([':u' => $name]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch(Exception $e) { return null; }
}

// Helper: Tulis Log Admin
function write_log($user, $mac, $ip, $reason, $comment) {
    global $db;
    if (!$db) return;
    try {
        $stmt = $db->prepare("INSERT INTO security_log (log_date, username, mac_address, ip_address, reason, comment) VALUES (:d, :u, :m, :i, :r, :c)");
        $stmt->execute([':d'=>date("Y-m-d H:i:s"), ':u'=>$user, ':m'=>$mac, ':i'=>$ip, ':r'=>$reason, ':c'=>$comment]);
    } catch(Exception $e){}
}

// Helper: Generator User Baru
function gen_user($profile, $comment_ref) {
    $blok = "";
    if (preg_match('/(Blok-[A-Za-z0-9]+)/i', $comment_ref, $m)) $blok = $m[1];
    $char = "23456789ABCDEFGHJKLMNPQRSTUVWXYZ"; 
    $user = "R-" . substr(str_shuffle($char), 0, 4); 
    $pass = substr(str_shuffle($char), 0, 4);
    $new_comm = trim("$blok (Retur) Valid: Retur Ref:$comment_ref");
    return ['u'=>$user, 'p'=>$pass, 'c'=>$new_comm];
}

// --- [BAGIAN 2] ACTION HANDLER ---
if (isset($_GET['action']) || isset($_POST['action'])) {
    $API_ACT = new RouterosAPI();
    $API_ACT->debug = false;
    $h_ip = $iphost ?: $_SESSION['iphost'];
    $h_user = $userhost ?: $_SESSION['userhost'];
    $h_pass = $passwdhost ?: $_SESSION['passwdhost'];

    if ($API_ACT->connect($h_ip, $h_user, decrypt($h_pass))) {
        $act = $_POST['action'] ?? $_GET['action'];
        
        // BATCH PROCESS
        if ($act == 'batch_process') {
            $batch_type = $_POST['batch_type'];
            $targets = $_POST['users'] ?? [];
            if(!empty($targets)) {
                $API_ACT->write('/ip/hotspot/user/print');
                $all_u = $API_ACT->read();
                $target_map = [];
                foreach($all_u as $au) { if(in_array($au['name'], $targets)) $target_map[$au['name']] = $au; }
                
                $count = 0;
                foreach($targets as $uname) {
                    if(!isset($target_map[$uname])) continue;
                    $u_data = $target_map[$uname];
                    $uid = $u_data['.id'];
                    $comm = $u_data['comment'] ?? '';
                    $prof = $u_data['profile'] ?? 'default';

                    if($batch_type == 'delete') {
                        $API_ACT->write('/ip/hotspot/user/remove', false);
                        $API_ACT->write('=.id=' . $uid);
                        $API_ACT->read();
                        write_log($uname, "-", "-", "BATCH DELETE", "User dihapus via Batch");
                        if($db) $db->exec("DELETE FROM login_history WHERE username = '$uname'");
                        $count++;
                    }
                    elseif($batch_type == 'invalid') {
                         $new_c = "Audit: INVALID " . date("d/m/y") . " " . $comm;
                         $API_ACT->write('/ip/hotspot/user/set', false);
                         $API_ACT->write('=.id='.$uid, false);
                         $API_ACT->write('=disabled=yes', false);
                         $API_ACT->write('=comment='.$new_c);
                         $API_ACT->read();
                         write_log($uname, "-", "-", "INVALID", $comm);
                         $count++;
                    }
                    elseif($batch_type == 'retur') {
                        $new_c = "Audit: RUSAK " . date("d/m/y") . " " . $comm;
                        $API_ACT->write('/ip/hotspot/user/set', false);
                        $API_ACT->write('=.id='.$uid, false);
                        $API_ACT->write('=disabled=yes', false);
                        $API_ACT->write('=comment='.$new_c);
                        $API_ACT->read();
                        
                        $gen = gen_user($prof, $uname);
                        $API_ACT->write('/ip/hotspot/user/add', false);
                        $API_ACT->write('=name='.$gen['u'], false);
                        $API_ACT->write('=password='.$gen['p'], false);
                        $API_ACT->write('=profile='.$prof, false);
                        $API_ACT->write('=comment='.$gen['c']);
                        $API_ACT->read();
                        write_log($uname, "-", "-", "RETUR", "Diganti oleh " . $gen['u']);
                        $count++;
                    }
                }
                if($batch_type == 'retur') $_SESSION['batch_msg'] = "Batch Retur $count User Berhasil.";
                if($batch_type == 'delete') $_SESSION['batch_msg'] = "Batch Delete $count User Berhasil.";
            }
        }
        // SINGLE ACTIONS (GET)
        else {
            $uid = $_GET['uid'] ?? '';
            $name = $_GET['name'] ?? '';
            $comm = $_GET['c'] ?? '';
            $prof = $_GET['p'] ?? '';

            if ($act == 'invalid') {
                $new_c = "Audit: INVALID " . date("d/m/y") . " " . $comm;
                $API_ACT->write('/ip/hotspot/user/set', false);
                $API_ACT->write('=.id='.$uid, false);
                $API_ACT->write('=disabled=yes', false);
                $API_ACT->write('=comment='.$new_c);
                $API_ACT->read();
                write_log($name, "-", "-", "INVALID", $comm);
            }
            elseif ($act == 'retur') {
                $new_c = "Audit: RUSAK " . date("d/m/y") . " " . $comm;
                $API_ACT->write('/ip/hotspot/user/set', false);
                $API_ACT->write('=.id='.$uid, false);
                $API_ACT->write('=disabled=yes', false);
                $API_ACT->write('=comment='.$new_c);
                $API_ACT->read();
                write_log($name, "-", "-", "RETUR", $comm);

                $gen = gen_user($prof, $name);
                $API_ACT->write('/ip/hotspot/user/add', false);
                $API_ACT->write('=name='.$gen['u'], false);
                $API_ACT->write('=password='.$gen['p'], false);
                $API_ACT->write('=profile='.$prof, false);
                $API_ACT->write('=comment='.$gen['c']);
                $res = $API_ACT->read();
                
                $new_uid = isset($res[0]['ret']) ? $res[0]['ret'] : '';
                $_SESSION['new_retur'] = ['name'=>$gen['u'], 'pass'=>$gen['p'], 'old'=>$name, 'uid'=>$new_uid];
            }
            elseif ($act == 'cancel_retur') {
                $target_uid = $_GET['target_uid'];
                $API_ACT->write('/ip/hotspot/user/remove', false);
                $API_ACT->write('=.id='.$target_uid);
                $API_ACT->read();
                unset($_SESSION['new_retur']);
            }
        }
        $API_ACT->disconnect();
    }
    echo "<script>window.location.href='./?hotspot=users&session=$session';</script>";
    exit();
}

// --- [BAGIAN 3] DATA PROCESSING & FILTER LOGIC ---
$req_prof = isset($_GET['profile']) ? $_GET['profile'] : 'all';
$req_comm = isset($_GET['comment']) ? urldecode($_GET['comment']) : ''; 
$req_status = isset($_GET['status']) ? $_GET['status'] : 'all'; 
$req_search = isset($_GET['q']) ? $_GET['q'] : ''; 

$all_users = $API->comm("/ip/hotspot/user/print", array("?server" => "wartel"));
$active = $API->comm("/ip/hotspot/active/print");

$activeMap = []; 
foreach($active as $a) {
    if(isset($a['user'])) $activeMap[$a['user']] = $a;
}

$filtered_data = [];
$list_blok = []; // Akan diisi dari SEMUA user, bukan hanya yang di-filter
$search_terms = array_filter(array_map('trim', preg_split('/[,\s]+/', $req_search)));

// PERBAIKAN 1: Loop pertama untuk mengumpulkan SEMUA blok (tanpa filter)
foreach($all_users as $u) {
    $c = $u['comment'] ?? '';
    $n = $u['name'] ?? '';
    
    // Ambil blok dari comment
    $blok_temp = '';
    if (preg_match('/(Blok-[A-Za-z0-9]+)/i', $c, $b)) {
        $blok_temp = strtoupper($b[1]);
    }
    
    // Jika tidak ada di comment, cek database
    if (empty($blok_temp)) {
        $hist_temp = get_user_history($n);
        if ($hist_temp && !empty($hist_temp['blok_name'])) {
            $blok_temp = $hist_temp['blok_name'];
        }
    }
    
    // Tambahkan ke list dropdown (untuk semua user, tidak peduli status)
    if (!empty($blok_temp)) {
        $list_blok[] = $blok_temp;
    }
}

// PERBAIKAN 2: Loop kedua untuk filter dan display
foreach($all_users as $u) {
    $c = $u['comment'] ?? '';
    $n = $u['name'] ?? '';
    $disabled = $u['disabled'] ?? 'false';
    $is_active = isset($activeMap[$n]);
    
    // 1. Inisialisasi Variable
    $f_blok = '';
    $f_ip = '-';
    $f_mac = '-';
    $f_date = '';
    $f_time = '';
    $f_price = '';
    $f_val = '';
    $live_data_found = false;

    // 2. Cek Komentar Mikhmon (Live Data)
    // Format: Tanggal-|-Jam-|-...
    if (preg_match('/(\d{4}-\d{2}-\d{2})-\|-(\d{2}:\d{2}:\d{2})-\|-(.*?)-\|-(\d+)-\|-([\d\.]+)-\|-([A-Fa-f0-9:]+)-\|-(.*?)-\|-(.*?)-\|-(.*)/', $c, $m)) {
        $f_date = $m[1]; $f_time = $m[2]; $f_price = $m[4];
        $f_ip = $m[5]; $f_mac = $m[6]; $f_val = $m[7];
        $live_data_found = true;
    }

    // 3. Extraksi Blok (Regex dari comment)
    if (preg_match('/(Blok-[A-Za-z0-9]+)/i', $c, $b)) {
        $f_blok = strtoupper($b[1]);
    }

    // 4. SYNC DATABASE (PERBAIKAN UTAMA DISINI)
    // Selalu ambil history terlebih dahulu
    $hist = get_user_history($n);

    // PERBAIKAN 3: PRIORITAS DATABASE untuk Blok
    // Jika blok tidak ada di comment ATAU comment sudah dimodifikasi (ada kata RUSAK/INVALID/RETUR)
    // maka ambil dari database
    $comment_modified = (stripos($c, 'RUSAK') !== false || 
                         stripos($c, 'INVALID') !== false || 
                         stripos($c, 'Audit:') !== false ||
                         stripos($c, '(Retur)') !== false);
    
    if ((empty($f_blok) || $comment_modified) && $hist && !empty($hist['blok_name'])) {
        $f_blok = $hist['blok_name'];
    }

    // LOGIKA PERBAIKAN IP & MAC:
    if ($is_active) {
        // Jika ONLINE, paksa ambil IP/MAC Realtime dari Active List
        $f_ip = $activeMap[$n]['address'] ?? '-';
        $f_mac = $activeMap[$n]['mac-address'] ?? '-';
        
        // Update database dengan data online terbaru
        if (!empty($f_blok) || $f_ip != '-') {
            save_user_history($n, [
                'date' => $f_date, 'time' => $f_time, 'price' => $f_price,
                'ip' => $f_ip, 'mac' => $f_mac, 'validity' => $f_val,
                'blok' => $f_blok, 'raw' => $c
            ]);
        }
    } elseif ($hist) {
        // Jika OFFLINE, ambil data terakhir dari database
        if ($f_ip == '-' || $f_ip == '') $f_ip = $hist['ip_address'] ?? '-';
        if ($f_mac == '-' || $f_mac == '') $f_mac = $hist['mac_address'] ?? '-';
        if (empty($f_blok)) $f_blok = $hist['blok_name'] ?? '';
    }

    // --- FILTERING ---
    if ($req_prof != 'all' && ($u['profile']??'') != $req_prof) continue;
    
    // PERBAIKAN 4: Filter Blok yang lebih ketat
    if ($req_comm != '') { 
        if (strcasecmp($f_blok, $req_comm) != 0) continue; 
    }

    // Status Calculations
    $bytes = ($u['bytes-in']??0) + ($u['bytes-out']??0);
    $uptime = $u['uptime']??'0s';
    $is_rusak = stripos($c, 'RUSAK') !== false;
    $is_invalid = stripos($c, 'INVALID') !== false;
    $is_retur = stripos($c, '(Retur)') !== false || stripos($c, 'Retur Ref:') !== false;
    
    // Is Used logic (History / Active / Traffic)
    $has_history = ($f_ip != '-' && $f_ip != '' && !$live_data_found); 
    $is_used = ($is_active || $bytes > 50 || $uptime != '0s' || $has_history);

    // PERBAIKAN 5: Filter Status yang diperbaiki
    if ($req_status == 'ready') {
        // Ready = belum pernah dipakai, tidak rusak, tidak invalid, tidak disabled
        if ($is_used || $is_rusak || $is_invalid || $disabled == 'true' || $is_retur) continue;
    }
    if ($req_status == 'online' && !$is_active) continue;
    if ($req_status == 'used') {
        // Used = pernah dipakai tapi sekarang offline
        if (!$is_used || $is_active || $is_rusak || $is_invalid) continue;
    }
    if ($req_status == 'rusak' && !$is_rusak) continue;
    if ($req_status == 'retur' && !$is_retur) continue;
    if ($req_status == 'invalid') {
        // Invalid = ada kata INVALID atau disabled
        if (!$is_invalid && $disabled != 'true') continue;
    }
    
    // Search
    if (!empty($search_terms)) {
        $found = false;
        foreach($search_terms as $term) {
            if (stripos($n, $term) !== false || 
                stripos($c, $term) !== false || 
                stripos($f_ip, $term) !== false || 
                stripos($f_blok, $term) !== false) {
                $found = true; break;
            }
        }
        if (!$found) continue;
    }
    
    // Set Display Data
    $u['display_ip'] = $f_ip;
    $u['display_mac'] = $f_mac;
    $u['display_blok'] = $f_blok; // Ini sekarang pasti terisi jika ada di DB
    
    $filtered_data[] = $u;
}

// Urutkan & Pagination
$list_blok = array_unique($list_blok); 
sort($list_blok);

$limit = 20; 
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$total_items = count($filtered_data);
$total_pages = ceil($total_items / $limit);
$offset = ($page - 1) * $limit;
$display_data = array_slice($filtered_data, $offset, $limit);

function get_page_url($p) {
    global $session, $req_prof, $req_comm, $req_search, $req_status;
    return "./?hotspot=users&session=$session&profile=$req_prof&comment=" . urlencode($req_comm) . "&status=$req_status&q=" . urlencode($req_search) . "&page=$p";
}
?>

<style>
    /* STYLE ORIGINAL */
    :root { --dark-bg: #1e2226; --dark-card: #2a3036; --input-bg: #343a40; --border-col: #495057; --txt-main: #ecf0f1; --txt-muted: #adb5bd; --c-blue: #3498db; --c-green: #2ecc71; --c-orange: #f39c12; --c-red: #e74c3c; --el-height: 42px; }
    .card-solid { background: var(--dark-card); color: var(--txt-main); border: none; box-shadow: 0 4px 10px rgba(0,0,0,0.3); border-radius: 8px; }
    .card-header-solid { background: #23272b; padding: 12px 20px; border-bottom: 2px solid var(--border-col); display: flex; justify-content: space-between; align-items: center; border-radius: 8px 8px 0 0; }
    .toolbar-container { padding: 15px; background: rgba(0,0,0,0.15); border-bottom: 1px solid var(--border-col); }
    .toolbar-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; }
    .input-group-solid { display: flex; flex-grow: 1; max-width: 700px; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
    .input-group-solid .form-control, .input-group-solid .custom-select-solid { height: var(--el-height); background: var(--input-bg); border: 1px solid var(--border-col); color: white; padding: 0 15px; font-size: 0.95rem; border-radius: 0; line-height: normal; }
    .input-group-solid .form-control:focus, .input-group-solid .custom-select-solid:focus { background: #3e444a; border-color: var(--c-blue); outline: none; }
    .input-group-solid .first-el { border-top-left-radius: 6px; border-bottom-left-radius: 6px; }
    .input-group-solid .last-el { border-top-right-radius: 6px; border-bottom-right-radius: 6px; border-left: none; }
    .input-group-solid .mid-el { border-left: none; border-right: none; }
    .btn-toolbar-main { height: var(--el-height); padding: 0 18px; border-radius: 6px; font-weight: 600; text-transform: uppercase; font-size: 0.85rem; border: none; display: inline-flex; align-items: center; justify-content: center; gap: 8px; cursor: pointer; transition: all 0.2s; box-shadow: 0 2px 5px rgba(0,0,0,0.3); }
    .btn-t-refresh { background: var(--c-orange); color: #fff; } .btn-t-print { background: var(--c-blue); color: #fff; } .btn-t-delete { background: var(--c-red); color: #fff; }
    .btn-toolbar-main:hover { filter: brightness(110%); transform: translateY(-1px); }
    .table-dark-solid { width: 100%; border-collapse: separate; border-spacing: 0; }
    .table-dark-solid th { background: #1b1e21; padding: 12px; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; color: var(--txt-muted); border-bottom: 2px solid var(--border-col); }
    .table-dark-solid td { padding: 12px; border-bottom: 1px solid #3a4046; vertical-align: middle; font-size: 0.9rem; }
    .table-dark-solid tr:hover td { background: #32383e; }
    .btn-act { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 4px; border: none; color: white; transition: all 0.2s; margin: 0 2px; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
    .btn-act-print { background: var(--c-blue); } .btn-act-retur { background: var(--c-orange); } .btn-act-invalid { background: var(--c-red); }
    .btn-act:hover { transform: scale(1.1); z-index: 5; }
    .status-badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
    .st-new { background: var(--c-green); color: #000; animation: pulse 1.5s infinite; } .st-rusak { background: var(--c-orange); color: #fff; } .st-invalid { background: var(--c-red); color: #fff; } .st-online { background: #3498db; color: white; } .st-ready { background: #4b545c; color: #ccc; border: 1px solid #6c757d; }
    .id-badge { font-family: 'Courier New', monospace; background: #3d454d; color: #fff; padding: 3px 6px; border-radius: 4px; font-weight: bold; border: 1px solid #56606a; }
    .pagination-wrapper { padding: 15px 20px; border-top: 1px solid var(--border-col); display: flex; justify-content: space-between; align-items: center; background: #23272b; border-radius: 0 0 8px 8px; }
    .page-info { font-size: 0.9rem; color: var(--txt-muted); }
    .pagination { display: inline-flex; gap: 5px; }
    .page-link { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; height: 36px; padding: 0 10px; border-radius: 4px; background: #3a4046; color: var(--txt-main); text-decoration: none; font-weight: 600; border: 1px solid var(--border-col); transition: all 0.2s; }
    .page-link:hover { background: #495057; text-decoration: none; color: white; }
    .page-link.active { background: var(--c-blue); border-color: var(--c-blue); color: white; cursor: default; }
    .page-link.disabled { opacity: 0.5; pointer-events: none; }
    @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.8; } 100% { opacity: 1; } }
</style>

<?php if(isset($_SESSION['new_retur'])): $nr = $_SESSION['new_retur']; ?>
<div class="row mb-3">
    <div class="col-12">
        <div class="card-solid" style="border-left: 5px solid var(--c-green); background: #203025;">
            <div class="card-body p-3 d-flex flex-wrap justify-content-between align-items: center">
                <div class="text-white">
                    <h4 class="m-0" style="color:#2ecc71"><i class="fa fa-check-circle"></i> Retur Berhasil!</h4>
                    <p class="m-0 mt-1">
                        <span class="text-muted">User Lama:</span> <s style="color:#e74c3c"><?=$nr['old']?></s> 
                        <i class="fa fa-arrow-right mx-2 text-muted"></i> 
                        <span class="text-muted">Baru:</span> <b style="font-size:1.2rem; color:#fff"><?=$nr['name']?></b> 
                        <span class="badge badge-light ml-2">Pass: <?=$nr['pass']?></span>
                    </p>
                </div>
                <div class="mt-2 mt-md-0">
                    <button class="btn btn-light font-weight-bold shadow mr-2" onclick="window.open('./voucher/print.php?user=up-<?=$nr['name']?>&qr=no&session=<?=$session?>','_blank').print()"><i class="fa fa-print"></i> Print Voucher</button>
                    <a href="./?hotspot=users&action=cancel_retur&target_uid=<?=$nr['uid']?>&session=<?=$session?>" class="btn btn-danger shadow" onclick="return confirm('Batalkan User Baru ini?')"><i class="fa fa-times"></i> Batal</a>
                    <a href="./?hotspot=users&session=<?=$session?>&clear_session=1" class="btn btn-outline-light ml-1"><i class="fa fa-check"></i> Selesai</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php if(isset($_GET['clear_session'])) unset($_SESSION['new_retur']); endif; ?>
<?php if(isset($_SESSION['batch_msg'])): ?>
    <div class="alert alert-success" style="background:var(--c-green); color:#000; font-weight:bold; margin-bottom:10px;"><?= $_SESSION['batch_msg'] ?></div>
    <?php unset($_SESSION['batch_msg']); ?>
<?php endif; ?>

<div class="row">
    <div class="col-12">
        <div class="card card-solid">
            <div class="card-header-solid">
                <h3 class="card-title m-0"><i class="fa fa-users mr-2"></i> Manajemen Voucher</h3>
                <span class="badge badge-secondary p-2" style="font-size:14px">Total: <?= $total_items ?> Items</span>
            </div>

            <div class="toolbar-container">
                <form action="?" method="GET" class="toolbar-row m-0">
                    <input type="hidden" name="hotspot" value="users">
                    <input type="hidden" name="session" value="<?=$session?>">
                    
                    <div class="input-group-solid">
                        <input type="text" name="q" value="<?= htmlspecialchars($req_search) ?>" class="form-control first-el" placeholder="Cari User..." autocomplete="off">
                        
                        <select name="status" class="custom-select-solid mid-el" onchange="this.form.submit()" style="flex-basis: 30%;">
                            <option value="all" <?=($req_status=='all'?'selected':'')?>>Status: Semua</option>
                            <option value="ready" <?=($req_status=='ready'?'selected':'')?>>🟢 Hanya Ready</option>
                            <option value="online" <?=($req_status=='online'?'selected':'')?>>🔵 Sedang Online</option>
                            <option value="used" <?=($req_status=='used'?'selected':'')?>>⚪ Sudah Terpakai</option>
                            <option value="rusak" <?=($req_status=='rusak'?'selected':'')?>>🟠 Rusak / Error</option>
                            <option value="retur" <?=($req_status=='retur'?'selected':'')?>>🟣 Hasil Retur</option>
                            <option value="invalid" <?=($req_status=='invalid'?'selected':'')?>>🔴 Invalid / Disabled</option>
                        </select>
                        
                        <select name="comment" class="custom-select-solid last-el" onchange="this.form.submit()" style="flex-basis: 30%;">
                            <option value="">Semua Blok</option>
                            <?php foreach($list_blok as $b) {
                                $label = str_replace('Blok-', 'BLOK ', $b); 
                                $sel = (strcasecmp($req_comm, $b) == 0) ? 'selected' : '';
                                echo "<option value='$b' $sel>$label</option>"; 
                            } ?>
                        </select>
                    </div>
                    
                    <div style="display:flex; gap:8px;">
                        <button type="button" class="btn-toolbar-main btn-t-delete" onclick="batchAction('delete')" title="Hapus yang dicentang">
                            <i class="fa fa-trash"></i> Hapus
                        </button>
                        <button type="button" class="btn-toolbar-main btn-t-print" onclick="batchAction('print')" title="Print Checklist">
                            <i class="fa fa-print"></i> Print
                        </button>
                        <button type="button" class="btn-toolbar-main btn-t-refresh" onclick="batchAction('retur')" title="Retur Checklist" style="background:#8e44ad;">
                            <i class="fa fa-exchange"></i> Retur
                        </button>
                        <button type="button" class="btn-toolbar-main btn-t-refresh" onclick="location.href='./?hotspot=users&session=<?=$session?>'" title="Reset Filter">
                            <i class="fa fa-refresh"></i> Reset
                        </button>
                    </div>
                </form>
            </div>

            <form id="batchForm" method="POST" action="./?hotspot=users&action=batch_process&session=<?=$session?>">
                <input type="hidden" name="batch_type" id="batchType">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark-solid table-hover text-nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center" width="40"><input type="checkbox" id="checkAll" style="transform: scale(1.2);"></th>
                                    <th>Username <span class="text-muted">/ Ket</span></th>
                                    <th>Profile</th>
                                    <th>Identitas</th>
                                    <th>Koneksi (MAC/IP)</th>
                                    <th class="text-right">Usage</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center" width="140">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="tblBody">
                            <?php if(count($display_data) > 0): ?>
                                <?php foreach($display_data as $u): 
                                    $uid = $u['.id']; 
                                    $name = $u['name']; 
                                    $comm = $u['comment']??''; 
                                    
                                    // Data Olahan 
                                    $f_ip = $u['display_ip'];
                                    $f_mac = $u['display_mac'];
                                    $f_blok = $u['display_blok'];

                                    $bytes = ($u['bytes-in']??0) + ($u['bytes-out']??0);
                                    $uptime = $u['uptime']??'0s';
                                    
                                    $is_active = isset($activeMap[$name]);
                                    
                                    $is_rusak = stripos($comm, 'RUSAK')!==false;
                                    $is_invalid = stripos($comm, 'INVALID')!==false;
                                    $is_used = ($is_active || $bytes > 50 || $uptime != '0s' || ($f_ip != '-' && stripos($comm, '-|-') === false));
                                    $is_new = (isset($_SESSION['new_retur']) && $_SESSION['new_retur']['name'] == $name);
                                    
                                    $row_style = $is_new ? "style='background:rgba(46, 204, 113, 0.15); border-left:4px solid #2ecc71'" : "";
                                ?>
                                <tr <?= $row_style ?>>
                                    <td class="text-center"><input type="checkbox" name="users[]" class="chk" value="<?= $name ?>" style="transform: scale(1.2);"></td>
                                    <td>
                                        <div style="font-size:15px; font-weight:bold; color:var(--txt-main)"><?= $name ?></div>
                                        <div style="font-size:11px; color:var(--txt-muted); max-width:200px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                            <?= $comm ?>
                                        </div>
                                    </td>
                                    <td><span class="badge badge-dark border border-secondary p-1"><?= $u['profile']??'' ?></span></td>
                                    <td><span class="id-badge"><?= $f_blok ?: '-' ?></span></td>
                                    <td>
                                        <div style="font-family:monospace; font-size:12px; color:#aeb6bf"><?= $f_mac ?></div>
                                        <div style="font-family:monospace; font-size:11px; color:#85929e"><?= $f_ip ?></div>
                                    </td>
                                    <td class="text-right">
                                        <span style="font-size:13px; font-weight:600"><?= $uptime ?></span><br>
                                        <span style="font-size:11px; color:var(--txt-muted)"><?= formatBytes($bytes,2) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <?php if($is_new): ?><span class="status-badge st-new">BARU</span>
                                        <?php elseif($is_rusak): ?><span class="status-badge st-rusak">RUSAK</span>
                                        <?php elseif($is_invalid): ?><span class="status-badge st-invalid">INVALID</span>
                                        <?php elseif($is_active): ?><span class="status-badge st-online">ONLINE</span>
                                        <?php elseif($is_used): ?><span class="status-badge st-ready" style="background:#17a2b8; color:white">TERPAKAI</span>
                                        <?php else: ?><span class="status-badge st-ready">READY</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if(!$is_rusak && !$is_invalid && !$is_new): ?>
                                            <button type="button" class="btn-act btn-act-print" onclick="window.open('./voucher/print.php?user=up-<?=$name?>&qr=no&session=<?=$session?>','_blank').print()" title="Print Voucher"><i class="fa fa-print"></i></button>
                                            <button type="button" class="btn-act btn-act-retur" onclick="if(confirm('RETUR Voucher <?=$name?>?\n\nUser lama akan dimatikan.\nUser pengganti akan dibuat otomatis.')) location.href='./?hotspot=users&action=retur&uid=<?=$uid?>&name=<?=$name?>&p=<?=$u['profile']?>&c=<?=urlencode($comm)?>&mac=<?=$f_mac?>&ip=<?=$f_ip?>&session=<?=$session?>'" title="Retur"><i class="fa fa-exchange"></i></button>
                                            <button type="button" class="btn-act btn-act-invalid" onclick="if(confirm('SET INVALID <?=$name?>?\n\nMasuk ke laporan kerugian.')) location.href='./?hotspot=users&action=invalid&uid=<?=$uid?>&name=<?=$name?>&c=<?=urlencode($comm)?>&mac=<?=$f_mac?>&ip=<?=$f_ip?>&session=<?=$session?>'" title="Invalid"><i class="fa fa-ban"></i></button>
                                        <?php else: ?>
                                            <i class="fa fa-lock text-dark" style="opacity:0.2; font-size:18px;"></i>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">Tidak ada data ditemukan. Coba reset filter.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>

            <?php if($total_pages > 1): ?>
            <div class="pagination-wrapper">
                <div class="page-info">
                    Menampilkan <b><?= $offset + 1 ?></b> - <b><?= min($offset + $limit, $total_items) ?></b> dari <b><?= $total_items ?></b> data
                </div>
                <div class="pagination">
                    <a href="<?= ($page > 1) ? get_page_url($page - 1) : '#' ?>" class="page-link <?= ($page <= 1) ? 'disabled' : '' ?>"><i class="fa fa-chevron-left"></i></a>
                    <?php
                    $range = 2; 
                    $start = max(1, $page - $range);
                    $end = min($total_pages, $page + $range);
                    if($start > 1) echo '<a href="'.get_page_url(1).'" class="page-link">1</a>';
                    if($start > 2) echo '<span class="page-link disabled">...</span>';
                    for($i = $start; $i <= $end; $i++){
                        $active = ($i == $page) ? 'active' : '';
                        echo '<a href="'.get_page_url($i).'" class="page-link '.$active.'">'.$i.'</a>';
                    }
                    if($end < $total_pages - 1) echo '<span class="page-link disabled">...</span>';
                    if($end < $total_pages) echo '<a href="'.get_page_url($total_pages).'" class="page-link">'.$total_pages.'</a>';
                    ?>
                    <a href="<?= ($page < $total_pages) ? get_page_url($page + 1) : '#' ?>" class="page-link <?= ($page >= $total_pages) ? 'disabled' : '' ?>"><i class="fa fa-chevron-right"></i></a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    document.getElementById('checkAll').onclick = function() { document.querySelectorAll('.chk').forEach(c => c.checked = this.checked); };
    function batchAction(type) {
        let sel = document.querySelectorAll('.chk:checked');
        if(sel.length === 0) return alert('Pilih user dulu!');
        if(type === 'print') {
            let names = []; sel.forEach(c => names.push(c.value));
            window.open('./voucher/print.php?user=up-'+names.join('-')+'&qr=no&session=<?=$session?>', '_blank');
            return;
        }
        let msg = "";
        if(type === 'retur') msg = "RETUR MASSAL " + sel.length + " User?";
        if(type === 'invalid') msg = "Set INVALID " + sel.length + " User?";
        if(type === 'delete') msg = "Yakin HAPUS " + sel.length + " User secara PERMANEN?";
        if(confirm(msg)) { document.getElementById('batchType').value = type; document.getElementById('batchForm').submit(); }
    }
</script>