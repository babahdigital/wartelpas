<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * SECURITY UPGRADE: Anti-CSRF, Anti-Bot, & Silent Defense
 * Code Owner: Pak Dul (WartelPas)
 * REBUILD STYLE: Midnight Modern UI (Fixed Layout)
 */
// Cek session start
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Sembunyikan error
error_reporting(0);
ini_set('max_execution_time', 300);

// --- 1. GENERATE CSRF TOKEN ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- HELPER FUNCTIONS ---
if (!function_exists('extract_blok_name')) {
    function extract_blok_name($comment) {
        if (empty($comment)) return '';
        if (preg_match('/\bblok\s*[-_]?\s*([A-Za-z0-9]+)/i', $comment, $m)) {
            return 'BLOK-' . strtoupper($m[1]);
        }
        return '';
    }
}

if (!function_exists('extract_ip_mac_from_comment')) {
    function extract_ip_mac_from_comment($comment) {
        $ip = ''; $mac = '';
        if (!empty($comment)) {
            if (preg_match('/\bIP\s*:\s*([^|\s]+)/i', $comment, $m)) $ip = trim($m[1]);
            if (preg_match('/\bMAC\s*:\s*([^|\s]+)/i', $comment, $m)) $mac = trim($m[1]);
        }
        return ['ip' => $ip, 'mac' => $mac];
    }
}

$root_dir = dirname(__DIR__);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}
require_once $root_dir . '/include/db_helpers.php';
$profiles_cfg = $env['profiles'] ?? [];
$pricing_cfg = $env['pricing'] ?? [];
$blok_cfg = $env['blok'] ?? ($env['blocks'] ?? []);
$profile_prices = $pricing_cfg['profile_prices'] ?? [];
$profile_labels = $profiles_cfg['labels'] ?? [];
$profile_name_map = [];
foreach ($profiles_cfg as $k => $v) {
    if (strpos($k, 'profile_') === 0 && trim((string)$v) !== '') {
        $suffix = substr($k, 8);
        $profile_name_map[$suffix] = (string)$v;
    }
}
$profile_definitions = [];
if (is_array($profile_prices) && !empty($profile_prices)) {
    foreach ($profile_prices as $key => $price) {
        $key_norm = strtolower(trim((string)$key));
        $key_norm = preg_replace('/\s+/', '', $key_norm);
        $minutes = '';
        if (preg_match('/(\d+)/', $key_norm, $m)) {
            $minutes = $m[1];
        }
        $profile_name = $minutes !== '' && isset($profile_name_map[$minutes])
            ? $profile_name_map[$minutes]
            : (isset($profiles_cfg['profile_' . $minutes]) ? $profiles_cfg['profile_' . $minutes] : (string)$key);
        $label = $profile_labels[$key] ?? ($minutes !== '' ? ($minutes . ' Menit') : (string)$key);
        $profile_definitions[] = [
            'key' => $key_norm,
            'name' => $profile_name,
            'label' => $label,
            'suffix' => $minutes
        ];
    }
}
if (empty($profile_definitions)) {
    $profile_definitions = [
        ['key' => '10menit', 'name' => ($profiles_cfg['profile_10'] ?? '10Menit'), 'label' => ($profiles_cfg['label_10'] ?? '10 Menit'), 'suffix' => '10'],
        ['key' => '30menit', 'name' => ($profiles_cfg['profile_30'] ?? '30Menit'), 'label' => ($profiles_cfg['label_30'] ?? '30 Menit'), 'suffix' => '30']
    ];
}
$profile_by_suffix = [];
foreach ($profile_definitions as $def) {
    if (!empty($def['suffix']) && !empty($def['name'])) {
        $profile_by_suffix[(string)$def['suffix']] = (string)$def['name'];
    }
}
$profile_default = $profile_definitions[0]['name'] ?? '10Menit';
$block_letters_cfg = strtoupper(trim((string)($blok_cfg['letters'] ?? 'A-F')));
$block_suffixes = $blok_cfg['suffixes'] ?? ['10', '30'];
$block_suffixes = array_values(array_filter(array_map('strval', $block_suffixes)));
if (empty($block_suffixes)) {
    $block_suffixes = ['10', '30'];
}
$block_suffix_pattern = implode('|', array_map(function($s) { return preg_quote($s, '/'); }, $block_suffixes));
$block_letters_range = [];
if (preg_match('/^[A-Z]-[A-Z]$/', $block_letters_cfg)) {
    $parts = explode('-', $block_letters_cfg);
    $block_letters_range = range($parts[0], $parts[1]);
} elseif (preg_match('/^[A-Z]+$/', $block_letters_cfg)) {
    $block_letters_range = str_split($block_letters_cfg);
}
if (empty($block_letters_range)) {
    $block_letters_range = range('A', 'F');
}

include_once($root_dir . '/lib/routeros_api.class.php');
$api_owned = false;
if (!isset($API) || !($API instanceof RouterosAPI)) {
    $API = new RouterosAPI();
    $API->debug = false;
    $API->timeout = 5;
    $API->attempts = 1;
    $api_owned = true;
}
$api_connect = function() use ($API, $iphost, $userhost, $passwdhost) {
    if ($API->connected) return true;
    return $API->connect($iphost, $userhost, decrypt($passwdhost));
};
if ($api_owned) {
    register_shutdown_function(function() use ($API) {
        if ($API->connected) {
            $API->disconnect();
        }
    });
}

$session = isset($_GET['session']) ? $_GET['session'] : '';

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit();
} else {
    date_default_timezone_set($_SESSION['timezone']);
    $genprof = isset($_GET['genprof']) ? $_GET['genprof'] : "";

    // --- LOGIC DETAIL PROFIL ---
    if ($genprof != "") {
        if ($api_connect()) {
            $getprofile = $API->comm("/ip/hotspot/user/profile/print", array(
                "?name" => "$genprof",
                ".proplist" => "name,on-login"
            ));
            if (isset($getprofile[0])) {
                $ponlogin = $getprofile[0]['on-login'];
                $getprice = explode(",", $ponlogin)[2];
                $getprice = ($getprice == "0") ? "" : $getprice;
                $getvalid = explode(",", $ponlogin)[3];
                $getlocku = explode(",", $ponlogin)[6];
                $getlocku = ($getlocku == "") ? "Disable" : $getlocku;

                if ($currency == in_array($currency, $cekindo['indo'])) {
                    $getprice = $currency . " " . number_format((float)$getprice, 0, ",", ".");
                } else {
                    $getprice = $currency . " " . number_format((float)$getprice);
                }
                // Disimpan dalam variabel untuk ditampilkan di bawah
                $ValidPriceInfo = [
                    'valid' => $getvalid,
                    'price' => $getprice,
                    'lock'  => $getlocku
                ];
            }
        }
    }

    $getprofile_list = $api_connect() ? $API->comm("/ip/hotspot/user/profile/print", array(
        ".proplist" => "name,on-login"
    )) : [];

    // --- PROSES GENERATE USER ---
    if (isset($_POST['qty'])) {
        // CSRF Check
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
             echo "<script>window.location.href='./error.php';</script>"; exit();
        }
        // Rate Limit
        if (isset($_SESSION['last_gen_time']) && (time() - $_SESSION['last_gen_time'] < 5)) {
             echo "<script>window.location='./?hotspot-user=generate&session=" . $session . "'</script>"; exit();
        }
        $_SESSION['last_gen_time'] = time();

        // Ambil Data
        $qty = (int)$_POST['qty']; 
        $adcomment = isset($_POST['adcomment']) ? trim($_POST['adcomment']) : "";
        $profile = '';
        $userl = ($_POST['userl']);
        $prefix = isset($_POST['prefix']) ? $_POST['prefix'] : ""; 

        // Security Checkpoint
        $violation = false;
        if ($qty < 50) { $violation = true; }
        $block_id = strtoupper($adcomment);
        if (stripos($block_id, 'BLOK-') === 0) {
            $block_id = substr($block_id, 5);
        }
        if (!preg_match('/^[' . $block_letters_cfg . ']+(' . $block_suffix_pattern . ')$/', $block_id)) { $violation = true; }
        $profile = $profile_default;
        $selected_suffix = '';
        if (preg_match('/(' . $block_suffix_pattern . ')$/', $block_id, $m)) {
            $selected_suffix = $m[1];
            if (isset($profile_by_suffix[$selected_suffix])) {
                $profile = $profile_by_suffix[$selected_suffix];
            }
        }
        $adcomment = 'Blok-' . $block_id;
        $allowed_profiles = array_values(array_unique(array_filter(array_map(function($d) {
            return $d['name'] ?? '';
        }, $profile_definitions))));
        if (!in_array($profile, $allowed_profiles)) { $violation = true; }
        if ($violation) { echo "<script>window.location.href='./error.php';</script>"; exit(); }

        if ($selected_suffix === '') {
            foreach ($profile_by_suffix as $suf => $pname) {
                if ($pname === $profile) {
                    $selected_suffix = (string)$suf;
                    break;
                }
            }
        }
        $timelimit = $selected_suffix !== '' ? ($selected_suffix . "m") : "0";
        
        // Prepare Data
        if (!$api_connect()) {
            echo "<script>window.location.href='./error.php';</script>"; exit();
        }
        $getprofile = $API->comm("/ip/hotspot/user/profile/print", array(
            "?name" => "$profile",
            ".proplist" => "name,on-login"
        ));
        $ponlogin = $getprofile[0]['on-login'];
        $getvalid = explode(",", $ponlogin)[3];
        $getprice = explode(",", $ponlogin)[2];
        $getsprice = explode(",", $ponlogin)[4];
        $getlock = explode(",", $ponlogin)[6];
        
        $_SESSION['ubp'] = $profile;
        $server = $hotspot_server ?? 'wartel';
        $user = "vc";
        $datalimit = 0;
        
        $commt = $user . "-" . rand(100, 999) . "-" . date("m.d.y") . "-" . $adcomment;
        $gentemp = $commt . "|~" . $profile . "~" . $getvalid . "~" . $getprice . "!".$getsprice."~" . $timelimit . "~" . $datalimit . "~" . $getlock;
        $gen = '<?php $genu="'.encrypt($gentemp).'";?>';
        
        $handle = fopen('./voucher/temp.php', 'w');
        fwrite($handle, $gen);
        fclose($handle);

        $u = array();
        for ($i = 1; $i <= $qty; $i++) {
            $p[$i] = randNLC($userl); 
            $u[$i] = "$prefix$p[$i]";
        }

        // Add to Router
        for ($i = 1; $i <= $qty; $i++) {
            $API->comm("/ip/hotspot/user/add", array(
                "server" => "$server",      
                "name" => "$u[$i]",
                "password" => "$u[$i]",     
                "profile" => "$profile",
                "limit-uptime" => "$timelimit",
                "limit-bytes-total" => "0", 
                "comment" => "$commt",
            ));
        }
        echo "<script>window.location='./?hotspot-user=generate&session=" . $session . "'</script>";
    }

    // --- LOGIC DATA RINGKASAN ---
    $blockSummary = [];
    $totalRusak = 0;
    $totalRetur = 0;
    $summary_seen_users = [];
    $history_status_map = [];

        $active_list = $api_connect() ? $API->comm('/ip/hotspot/active/print', [
            '?server' => ($hotspot_server ?? 'wartel'),
            '.proplist' => 'user'
        ]) : [];
    $activeMap = [];
    foreach ($active_list as $a) { if (isset($a['user'])) $activeMap[$a['user']] = true; }

    $all_users = $api_connect() ? $API->comm('/ip/hotspot/user/print', [
        '?server' => ($hotspot_server ?? 'wartel'),
        '.proplist' => 'name,comment,disabled,bytes-in,bytes-out,uptime'
    ]) : [];

    $dbFile = get_stats_db_path();
    if (file_exists($dbFile)) {
        try {
            $db = new PDO('sqlite:' . $dbFile);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $db->query("SELECT username, last_status FROM login_history WHERE username IS NOT NULL AND username != ''");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $uname = strtolower($row['username'] ?? '');
                if ($uname === '') continue;
                $history_status_map[$uname] = strtolower((string)($row['last_status'] ?? ''));
            }
        } catch (Exception $e) {
            $history_status_map = [];
        }
    }

    foreach ($all_users as $u) {
        $name = $u['name'] ?? '';
        if ($name !== '') {
            $summary_seen_users[strtolower($name)] = true;
        }
        $comment = $u['comment'] ?? '';
        $disabled = $u['disabled'] ?? 'false';
        $is_active = isset($activeMap[$name]);
        $bytes = (int)($u['bytes-in'] ?? 0) + (int)($u['bytes-out'] ?? 0);
        $uptime = $u['uptime'] ?? '';
        $cm = extract_ip_mac_from_comment($comment);

        $comment_rusak = preg_match('/\bAudit:\s*RUSAK\b/i', $comment) || preg_match('/^\s*RUSAK\b/i', $comment);
        $is_rusak = $comment_rusak || (stripos($comment, 'RUSAK') !== false) || ($disabled === 'true');
        $is_retur = (stripos($comment, '(Retur)') !== false) || (stripos($comment, 'Retur Ref:') !== false) || preg_match('/\bRETUR\b/i', $comment);
        $hist_status = $name !== '' ? ($history_status_map[strtolower($name)] ?? '') : '';
        if ($hist_status === 'retur') {
            $is_retur = true;
            $is_rusak = false;
        } elseif ($hist_status === 'rusak') {
            $is_rusak = true;
            $is_retur = false;
        }
        if ($is_rusak) $is_retur = false;

        $is_used = (!$is_retur && !$is_rusak && $disabled !== 'true') &&
            ($is_active || $bytes > 50 || ($uptime !== '' && $uptime !== '0s') || (($cm['ip'] ?? '') !== ''));

        $status = 'READY';
        if ($is_active) $status = 'ONLINE';
        elseif ($is_rusak) $status = 'RUSAK';
        elseif ($is_retur) $status = 'RETUR';
        elseif ($is_used) $status = 'TERPAKAI';

        if ($status === 'RUSAK') $totalRusak++;
        if ($status === 'RETUR') $totalRetur++;
        if ($status === 'READY') {
            $blok = extract_blok_name($comment);
            if ($blok !== '') {
                if (!isset($blockSummary[$blok])) $blockSummary[$blok] = 0;
                $blockSummary[$blok]++;
            }
        }
    }
    if (!empty($blockSummary)) ksort($blockSummary, SORT_NATURAL | SORT_FLAG_CASE);

    // Tambahkan hitungan RUSAK/RETUR dari history DB untuk user yang sudah tidak ada di router
    if (file_exists($dbFile)) {
        try {
            $stmt = $db->query("SELECT username, last_status, raw_comment FROM login_history WHERE username IS NOT NULL AND username != ''");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $uname = strtolower($row['username'] ?? '');
                if ($uname === '' || isset($summary_seen_users[$uname])) continue;
                $raw_comment = (string)($row['raw_comment'] ?? '');
                $hist_status = strtolower((string)($row['last_status'] ?? ''));
                $is_hist_rusak = ($hist_status === 'rusak') || preg_match('/\bAudit:\s*RUSAK\b/i', $raw_comment) || preg_match('/^\s*RUSAK\b/i', $raw_comment) || (stripos($raw_comment, 'RUSAK') !== false);
                $is_hist_retur = ($hist_status === 'retur') || (stripos($raw_comment, '(Retur)') !== false) || (stripos($raw_comment, 'Retur Ref:') !== false) || preg_match('/\bRETUR\b/i', $raw_comment);
                if ($is_hist_rusak) {
                    $totalRusak++;
                } elseif ($is_hist_retur) {
                    $totalRetur++;
                }
            }
        } catch (Exception $e) {
        }
    }
}
?>

<style>
    :root {
        --bg-main: #1e2129;      /* Background Utama Gelap */
        --bg-card: #262935;      /* Background Card */
        --bg-input: #323542;     /* Input Field */
        --border-c: #3e4252;     /* Border Color */
        --text-pri: #e6e6e6;     /* Teks Utama Putih/Abu Terang */
        --text-sec: #9ca3af;     /* Teks Sekunder Abu */
        --accent: #3b82f6;       /* Biru Utama */
        --accent-hover: #2563eb; /* Biru Hover */
        --danger: #ef4444;       /* Merah */
        --warning: #f59e0b;      /* Kuning */
    }

    /* Layout Utilities */
    .row-eq-height {
        display: flex;
        flex-wrap: wrap;
    }

    .text-right {
        text-align: right !important;
    }
    
    .card-modern {
        background-color: var(--bg-card);
        color: var(--text-pri);
        border: 1px solid var(--border-c);
        border-radius: 8px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.2);
        display: flex;
        flex-direction: column;
        height: 100%; /* Agar tinggi card mengikuti kolom */
        position: relative;
        margin-left: 30px;
    }

    .card-header-mod {
        padding: 15px 20px;
        border-bottom: 1px solid var(--border-c);
        background: rgba(0,0,0,0.1);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .card-header-mod h3 {
        margin: 0; font-size: 1.1rem; font-weight: 600; color: var(--text-pri);
    }

    .card-body-mod {
        padding: 20px;
        flex-grow: 1; /* Isi card akan mengisi ruang kosong */
        display: flex;
        flex-direction: column;
    }

    .gen-wrapper { padding: 16px 18px; }
    @media (min-width: 992px) {
        .gen-wrapper { padding: 20px 26px; }
    }

    /* Form Styles */
    .form-group label {
        color: var(--text-sec);
        font-size: 0.85rem;
        margin-bottom: 5px;
        display: block;
    }

    .form-control-mod {
        width: 100%;
        background-color: var(--bg-input);
        border: 1px solid var(--border-c);
        color: var(--text-pri);
        padding: 10px 12px;
        border-radius: 6px;
        font-size: 0.95rem;
        transition: border 0.2s;
        margin-bottom: 5px;
        min-height: 42px;
    }

    .form-control-mod:focus {
        border-color: var(--accent);
        outline: none;
        box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
    }

    /* Lock / Readonly Field */
    .locked-input {
        background-color: #2a2d38 !important;
        border: 1px dashed #4b5563 !important;
        color: #9ca3af !important;
        cursor: not-allowed;
        font-family: monospace;
    }

    /* Generate Button */
    .btn-generate {
        background: linear-gradient(to right, var(--accent), var(--accent-hover));
        color: white;
        border: none;
        width: 100%;
        padding: 12px;
        border-radius: 6px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-top: auto; /* Dorong ke paling bawah */
        cursor: pointer;
        transition: transform 0.1s;
    }
    .btn-generate:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(59, 130, 246, 0.3); }

    /* Summary & Table */
    .table-dark-mod {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.9rem;
    }
    .table-dark-mod th {
        text-align: left;
        color: var(--text-sec);
        padding: 8px 0;
        border-bottom: 1px solid var(--border-c);
    }
    .table-dark-mod td {
        padding: 8px 0;
        border-bottom: 1px solid #323542;
        color: var(--text-pri);
    }
    .low-stock { color: var(--danger) !important; font-weight: 700; }
    .low-stock-row td { background: rgba(239, 68, 68, 0.08); }
    
    /* Scroll area untuk list blok jika terlalu panjang */
    .summary-scroll {
        max-height: 420px; /* Sesuaikan agar tidak terlalu panjang */
        overflow-y: auto;
        padding-right: 5px;
        margin-bottom: 20px;
    }

    .info-server {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    .info-server .form-control-mod {
        flex: 1 1 220px;
    }

    .form-grid-2 {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
    }

    @media (max-width: 767px) {
        .form-grid-2 {
            grid-template-columns: 1fr;
        }
    }

    /* FOOTER STATS (Rusak/Retur) */
    .footer-stats-container {
        margin-top: auto; /* Tempel di bawah card ringkasan */
        padding-top: 15px;
        border-top: 1px solid var(--border-c);
        display: flex;
        justify-content: center;
        gap: 40px;
    }
    
    .stat-item {
        text-align: center;
        padding: 0 10px;
    }
    
    .stat-val {
        font-size: 2rem;
        font-weight: 700;
        line-height: 1;
        display: block;
        margin-bottom: 5px;
    }
    
    .stat-label {
        font-size: 0.75rem;
        color: var(--text-sec);
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    
    .text-red { color: var(--danger); }
    .text-yellow { color: var(--warning); }
    .text-info-xxs { font-size: 0.75rem; color: var(--text-sec); margin-top: 4px; }

    /* Hide dropdown arrow for locked profile select */
    #uprof {
        -webkit-appearance: none;
        -moz-appearance: none;
        appearance: none;
        background-image: none;
        padding-right: 12px;
    }
    
    /* Custom Scrollbar */
    ::-webkit-scrollbar { width: 6px; }
    ::-webkit-scrollbar-track { background: var(--bg-main); }
    ::-webkit-scrollbar-thumb { background: #4b5563; border-radius: 3px; }
</style>

<div class="container-fluid gen-wrapper">
    <div class="row row-eq-height g-3">
        
        <div class="col-8" style="margin-left: -30px;">
            <div class="card-modern">
                <div class="card-header-mod">
                    <h3><i class="fa fa-cogs"></i> Konfigurasi Voucher</h3>
                    <small id="loader" style="display:none;" class="text-warning"><i class="fa fa-circle-o-notch fa-spin"></i> Proses...</small>
                </div>
                <div class="card-body-mod">
                    <form autocomplete="off" method="post" action="./?hotspot-user=generate&session=<?= $session; ?>" style="display: flex; flex-direction: column; height: 100%;">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="session" value="<?= $session; ?>">

                        <div class="form-grid-2">
                            <div class="form-group">
                                <label>Jumlah (Pcs)</label>
                                <input type="number" name="qty" id="qtyInput" class="form-control-mod" value="50" min="50" max="500" required>
                                <div class="text-danger text-info-xxs">*Minimal 50 User</div>
                            </div>
                            <div class="form-group">
                                <label>Panjang Karakter</label>
                                <select name="userl" class="form-control-mod">
                                    <option value="6">6 Digit</option>
                                    <option value="7">7 Digit</option>
                                    <option value="8">8 Digit</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-grid-2" style="margin-top: 16px;">
                            <div class="form-group">
                                <label>Blok ID</label>
                                <select name="adcomment" id="blokId" class="form-control-mod" onchange="applyBlockProfile();" required>
                                    <?php
                                    foreach ($block_letters_range as $blk) {
                                        foreach ($block_suffixes as $suf) {
                                            $val = $blk . $suf;
                                            echo "<option value='{$val}'>{$val}</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Profil Paket</label>
                                <select name="profile_display" id="uprof" class="form-control-mod locked-input" disabled>
                                    <?php foreach ($profile_definitions as $def): ?>
                                        <option value="<?= htmlspecialchars($def['name']) ?>"><?= htmlspecialchars($def['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="profile" id="profileHidden" value="<?= htmlspecialchars($profile_default) ?>">
                            </div>
                        </div>

                        <div class="form-group" style="margin-top: 16px;">
                            <label>Batas Waktu</label>
                            <input type="text" id="timelimit" name="timelimit_display" class="form-control-mod locked-input" readonly value="-">
                        </div>

                        <div class="form-group" style="margin-top: 16px;">
                            <label>Info Server</label>
                            <div class="info-server">
                                <input type="text" class="form-control-mod locked-input" value="Server: <?= htmlspecialchars($hotspot_server ?? 'wartel') ?>" readonly>
                                <input type="text" class="form-control-mod locked-input" value="Mode: User=Pass" readonly>
                            </div>
                            <input type="hidden" name="user" value="vc">
                        </div>

                        <div id="GetValidPrice" style="margin-bottom: 20px;">
                            <?php 
                            if ($genprof != "" && isset($ValidPriceInfo)) {
                                echo "<div style='background: rgba(59,130,246,0.1); padding:10px; border-radius:5px; border:1px solid rgba(59,130,246,0.2); font-size:0.85rem;'>";
                                echo "<i class='fa fa-info-circle text-primary'></i> <b>Info:</b> Validitas: {$ValidPriceInfo['valid']} | Harga: {$ValidPriceInfo['price']}";
                                echo "</div>";
                            }
                            ?>
                        </div>

                        <button type="submit" name="save" onclick="return validateForm()" class="btn-generate">
                            <i class="fa fa-bolt mr-2"></i> GENERATE VOUCHER
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-4" style="margin-left: 30px;">
            <div class="card-modern">
                <div class="card-header-mod">
                    <h3><i class="fa fa-list-alt"></i> Ringkasan</h3>
                </div>
                <div class="card-body-mod">
                    
                    <div class="summary-scroll">
                        <?php if (!empty($blockSummary)): ?>
                            <table class="table-dark-mod">
                                <thead>
                                    <tr>
                                        <th>Kode Blok</th>
                                        <th class="text-right">Jumlah</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($blockSummary as $blok => $count): ?>
                                    <?php $is_low = ((int)$count <= 100); ?>
                                    <tr class="<?= $is_low ? 'low-stock-row' : '' ?>">
                                        <td><?= htmlspecialchars($blok) ?></td>
                                        <td class="text-right <?= $is_low ? 'low-stock' : '' ?>" style="font-weight:bold; color: #10b981;">
                                            <?= (int)$count ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="text-center" style="padding: 40px 0; color: var(--text-sec);">
                                <i class="fa fa-inbox fa-3x mb-3" style="opacity: 0.3"></i><br>
                                Belum ada stok Ready.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="footer-stats-container">
                        <div class="stat-item">
                            <span class="stat-val text-red"><?= (int)$totalRusak ?></span>
                            <span class="stat-label">VOUCHER RUSAK</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-val text-yellow"><?= (int)$totalRetur ?></span>
                            <span class="stat-label">VOUCHER RETUR</span>
                        </div>
                    </div>

                </div>
            </div>
        </div>

    </div>
</div>

<script>
var profileBySuffix = <?= json_encode($profile_by_suffix) ?>;
var blockSuffixes = <?= json_encode(array_values($block_suffixes)) ?>;
var defaultProfile = <?= json_encode($profile_default) ?>;
function GetVP(){
    var prof = document.getElementById('profileHidden').value || document.getElementById('uprof').value;
  // Reload div via AJAX
  $("#GetValidPrice").load("./process/getvalidprice.php?name="+prof+"&session=<?= $session; ?> #getdata", function(response, status, xhr) {
      if (status == "error") {
          console.log("Error loading price info");
      }
  });
} 

function updateTimeLimit() {
        var prof = document.getElementById('profileHidden').value || document.getElementById('uprof').value;
    var timeField = document.getElementById('timelimit');
    var selectedSuffix = '';
    Object.keys(profileBySuffix || {}).forEach(function(suf) {
        if (profileBySuffix[suf] === prof) {
            selectedSuffix = suf;
        }
    });
    timeField.value = selectedSuffix ? (selectedSuffix + 'm') : '-';
}

function applyBlockProfile() {
    var blk = document.getElementById('blokId').value || '';
    var prof = defaultProfile;
    if (Array.isArray(blockSuffixes)) {
        for (var i = 0; i < blockSuffixes.length; i++) {
            var suf = blockSuffixes[i];
            if (suf && blk.endsWith(suf) && profileBySuffix[suf]) {
                prof = profileBySuffix[suf];
                break;
            }
        }
    }
    document.getElementById('uprof').value = prof;
    document.getElementById('profileHidden').value = prof;
    updateTimeLimit();
    GetVP();
}

function validateForm() {
    var qty = document.getElementById('qtyInput').value;
    if (qty < 50) {
        alert("PERHATIAN: Minimal generate harus 50 user!");
        return false;
    }
    document.getElementById('loader').style.display = 'inline-block';
    return true;
}

$(document).ready(function() {
    applyBlockProfile();
});
</script>