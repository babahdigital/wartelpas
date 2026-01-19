<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * SECURITY UPGRADE: Anti-CSRF, Anti-Bot, & Silent Defense
 * Code Owner: Pak Dul (WartelPas)
 * REBUILD STYLE: Modern Midnight UI & Clean Layout (2026)
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

$session = isset($_GET['session']) ? $_GET['session'] : '';

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit();
} else {
    date_default_timezone_set($_SESSION['timezone']);
    $genprof = isset($_GET['genprof']) ? $_GET['genprof'] : "";

    // --- LOGIC DETAIL PROFIL ---
    if ($genprof != "") {
        $getprofile = $API->comm("/ip/hotspot/user/profile/print", array("?name" => "$genprof"));
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
            $ValidPrice = "<div class='info-valid-box'>" .
                          "<div class='v-item'><i class='fa fa-clock-o'></i> Masa Aktif: <b>" . $getvalid . "</b></div>" .
                          "<div class='v-item'><i class='fa fa-tag'></i> Harga: <b>" . $getprice . "</b></div>" .
                          "<div class='v-item'><i class='fa fa-lock'></i> Lock: <b>" . $getlocku . "</b></div></div>";
        }
    }

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
        $profile = ($_POST['profile']);
        $userl = ($_POST['userl']);
        $prefix = isset($_POST['prefix']) ? $_POST['prefix'] : ""; 

        // Security Checkpoint
        $violation = false;
        if ($qty < 50) { $violation = true; }
        if (substr($adcomment, 0, 5) !== 'Blok-') { $violation = true; }
        $allowed_profiles = ['10Menit', '30Menit'];
        if (!in_array($profile, $allowed_profiles)) { $violation = true; }
        if ($violation) { echo "<script>window.location.href='./error.php';</script>"; exit(); }

        $timelimit = ($profile == '10Menit') ? "10m" : (($profile == '30Menit') ? "30m" : "0");
        
        // Prepare Data
        $getprofile = $API->comm("/ip/hotspot/user/profile/print", array("?name" => "$profile"));
        $ponlogin = $getprofile[0]['on-login'];
        $getvalid = explode(",", $ponlogin)[3];
        $getprice = explode(",", $ponlogin)[2];
        $getsprice = explode(",", $ponlogin)[4];
        $getlock = explode(",", $ponlogin)[6];
        
        $_SESSION['ubp'] = $profile;
        $server = "wartel"; $user = "vc"; $datalimit = 0;
        
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

    $active_list = $API->comm('/ip/hotspot/active/print', ['?server' => 'wartel', '.proplist' => 'user']);
    $activeMap = [];
    foreach ($active_list as $a) { if (isset($a['user'])) $activeMap[$a['user']] = true; }

    $all_users = $API->comm('/ip/hotspot/user/print', ['?server' => 'wartel', '.proplist' => 'name,comment,disabled,bytes-in,bytes-out,uptime']);

    foreach ($all_users as $u) {
        $name = $u['name'] ?? '';
        $comment = $u['comment'] ?? '';
        $disabled = $u['disabled'] ?? 'false';
        $is_active = isset($activeMap[$name]);
        $bytes = (int)($u['bytes-in'] ?? 0) + (int)($u['bytes-out'] ?? 0);
        $uptime = $u['uptime'] ?? '';
        $cm = extract_ip_mac_from_comment($comment);

        $is_rusak = (stripos($comment, 'RUSAK') !== false) || ($disabled === 'true');
        $is_retur = (stripos($comment, '(Retur)') !== false);
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
}
?>

<style>
    /* UI VARIABLES: Midnight Blue Theme */
    :root {
        --bg-body: #1a1c23;
        --bg-card: #242630;
        --bg-input: #2f3240;
        --border-color: #383b4a;
        --text-primary: #e2e8f0;
        --text-secondary: #94a3b8;
        --primary-accent: #3b82f6; /* Blue */
        --success-accent: #10b981; /* Green */
        --warning-accent: #f59e0b; /* Orange */
        --danger-accent: #ef4444; /* Red */
        --glass-header: rgba(36, 38, 48, 0.95);
    }

    /* CARD STYLING */
    .gen-page .card-modern {
        background: var(--bg-card);
        color: var(--text-primary);
        border: 1px solid var(--border-color);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3);
        border-radius: 12px;
        margin-bottom: 25px;
        overflow: hidden;
    }

    .gen-page .card-header-modern {
        background: var(--glass-header);
        padding: 18px 25px;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .gen-page .card-title {
        margin: 0;
        font-weight: 600;
        font-size: 1.15rem;
        letter-spacing: 0.5px;
        color: var(--text-primary);
    }

    .gen-page .card-body-modern {
        padding: 25px;
    }

    /* FORM ELEMENTS */
    .gen-page .form-group {
        margin-bottom: 20px;
    }

    .gen-page .form-label {
        font-size: 0.9rem;
        font-weight: 500;
        color: var(--text-secondary);
        margin-bottom: 8px;
        display: block;
    }

    .gen-page .form-control {
        background-color: var(--bg-input);
        border: 1px solid var(--border-color);
        color: var(--text-primary);
        border-radius: 8px;
        height: 48px;
        padding: 10px 15px;
        font-size: 0.95rem;
        transition: all 0.2s ease;
    }

    .gen-page .form-control:focus {
        background-color: #353846;
        border-color: var(--primary-accent);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        color: #fff;
    }

    /* Readonly / Locked Fields Styling */
    .gen-page .locked-field {
        background-color: #1f2129 !important;
        border-color: transparent !important;
        color: #718096 !important;
        cursor: not-allowed;
        font-family: monospace;
    }

    /* INFO BOX STYLING */
    .info-valid-box {
        background: rgba(59, 130, 246, 0.08);
        border: 1px solid rgba(59, 130, 246, 0.2);
        border-radius: 8px;
        padding: 15px 20px;
        margin-top: 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .v-item {
        color: var(--text-primary);
        font-size: 0.9rem;
        display: flex;
        align-items: center;
    }
    
    .v-item i {
        color: var(--primary-accent);
        margin-right: 8px;
        font-size: 1.1rem;
    }

    /* SUMMARY GRID */
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 20px;
    }
    
    .blok-card {
        background: linear-gradient(145deg, #2a2d38, #242630);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 20px;
        text-align: center;
        position: relative;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    
    .blok-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.4);
        border-color: var(--success-accent);
    }
    
    .blok-card::after {
        content: '';
        position: absolute;
        bottom: 0; left: 0;
        width: 100%; height: 3px;
        background: var(--success-accent);
        opacity: 0.7;
    }

    .blok-name {
        display: block;
        font-size: 0.85rem;
        color: var(--text-secondary);
        text-transform: uppercase;
        margin-bottom: 5px;
        letter-spacing: 1px;
    }
    
    .blok-count {
        display: block;
        font-size: 2rem;
        font-weight: 700;
        color: var(--success-accent);
        line-height: 1.2;
    }

    /* ACTION BUTTON */
    .btn-action {
        width: 100%;
        padding: 14px;
        font-size: 1rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1px;
        border: none;
        border-radius: 8px;
        background: linear-gradient(to right, var(--primary-accent), #2563eb);
        color: #fff;
        cursor: pointer;
        transition: all 0.3s;
        margin-top: 10px;
        box-shadow: 0 4px 6px rgba(59, 130, 246, 0.3);
    }
    
    .btn-action:hover {
        background: linear-gradient(to right, #2563eb, #1d4ed8);
        box-shadow: 0 6px 12px rgba(59, 130, 246, 0.4);
    }

    /* FOOTER STATS */
    .footer-stats {
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px solid var(--border-color);
        display: flex;
        justify-content: center;
        gap: 50px;
    }
    
    .stat-box {
        text-align: center;
    }
    
    .stat-val {
        font-size: 1.8rem;
        font-weight: bold;
        display: block;
    }
    
    .stat-lbl {
        font-size: 0.85rem;
        color: var(--text-secondary);
        text-transform: uppercase;
    }
    
    .c-red { color: var(--danger-accent); }
    .c-yellow { color: var(--warning-accent); }

    /* HELPER CLASSES */
    .text-muted-sm { font-size: 0.8rem; color: #64748b; margin-top: 4px; display: block; }
</style>

<div class="gen-page container-fluid p-0">
    <div class="row">
        
        <div class="col-12">
            <div class="card card-modern">
                <div class="card-header-modern">
                    <h3 class="card-title"><i class="fa fa-ticket mr-2"></i> Generate Voucher Baru</h3>
                    <small id="loader" style="display: none;" class="text-info">
                        <i class='fa fa-circle-o-notch fa-spin'></i> Memproses data...
                    </small>
                </div>
                
                <div class="card-body-modern">
                    <form autocomplete="off" method="post" action="./?hotspot-user=generate&session=<?= $session; ?>">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="session" value="<?= $session; ?>">

                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label class="form-label">Jumlah Voucher (Pcs)</label>
                                <input class="form-control" type="number" id="qtyInput" name="qty" min="50" max="500" value="50" required placeholder="Contoh: 50">
                                <span class="text-muted-sm text-danger">*Minimal pembuatan 50 voucher sekali proses.</span>
                            </div>

                            <div class="col-md-6 form-group">
                                <label class="form-label">Panjang Karakter (Username/Pass)</label>
                                <select class="form-control" id="userl" name="userl" required>
                                    <option value="6">6 Digit (Standar)</option>
                                    <option value="7">7 Digit</option>
                                    <option value="8">8 Digit (Kuat)</option>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label class="form-label">Pilih Paket Profil</label>
                                <select class="form-control" onchange="GetVP(); updateTimeLimit();" id="uprof" name="profile" required>
                                    <?php 
                                    $allowedProfiles = ['10Menit', '30Menit'];
                                    // Tampilkan profil terpilih dari URL jika ada
                                    if ($genprof != "" && in_array($genprof, $allowedProfiles)) {
                                        echo "<option selected value='" . $genprof . "'>" . $genprof . "</option>";
                                    }
                                    // Loop profil lain
                                    foreach ($getprofile as $p) {
                                        $pName = $p['name'];
                                        if(in_array($pName, $allowedProfiles) && $pName != $genprof) {
                                            echo "<option value='" . $pName . "'>" . $pName . "</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>

                            <div class="col-md-6 form-group">
                                <label class="form-label">Batas Waktu (Sistem)</label>
                                <input class="form-control locked-field" type="text" name="timelimit_display" id="timelimit" readonly value="-">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label class="form-label">Kode Blok (Komentar)</label>
                                <select class="form-control" name="adcomment" id="comment" required style="font-family: monospace; font-size: 1.05rem; letter-spacing: 1px;">
                                    <?php
                                    foreach(range('A', 'F') as $blk) {
                                        foreach(['10', '30'] as $suf) echo "<option value='Blok-$blk$suf'>Blok-$blk$suf</option>";
                                    }
                                    ?>
                                </select>
                                <span class="text-muted-sm">Digunakan untuk pengelompokan stok fisik.</span>
                            </div>

                            <div class="col-md-6 form-group">
                                <label class="form-label">Info Server & Mode</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-dark border-dark text-white"><i class="fa fa-server"></i></span>
                                    </div>
                                    <input type="text" class="form-control locked-field" value="Server: Wartel | Mode: VC" readonly>
                                    <input type="hidden" name="user" value="vc">
                                </div>
                            </div>
                        </div>

                        <div id="GetValidPrice">
                            <?php if ($genprof != "" && isset($ValidPrice)) echo $ValidPrice; ?>
                        </div>

                        <button type="submit" name="save" onclick="return validateForm()" class="btn-action mt-3">
                            <i class="fa fa-print mr-2"></i> GENERATE SEKARANG
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card card-modern">
                <div class="card-header-modern">
                    <h3 class="card-title"><i class="fa fa-cubes mr-2"></i> Monitor Stok (Status: READY)</h3>
                </div>
                
                <div class="card-body-modern">
                    <?php if (!empty($blockSummary)): ?>
                        <div class="summary-grid">
                            <?php foreach ($blockSummary as $blok => $count): ?>
                                <div class="blok-card">
                                    <span class="blok-name"><?= htmlspecialchars($blok) ?></span>
                                    <span class="blok-count"><?= (int)$count ?></span>
                                    <small style="color: #64748b;">lembar</small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center p-5" style="background: rgba(255,255,255,0.02); border-radius: 8px; border: 1px dashed #444;">
                            <i class="fa fa-dropbox fa-3x mb-3 text-muted"></i><br>
                            <h5 class="text-muted">Stok Kosong</h5>
                            <small class="text-muted">Belum ada voucher dengan status READY di database.</small>
                        </div>
                    <?php endif; ?>

                    <div class="footer-stats">
                        <div class="stat-box">
                            <span class="stat-val c-red"><?= (int)$totalRusak ?></span>
                            <span class="stat-lbl">Voucher Rusak</span>
                        </div>
                        <div class="stat-box">
                            <span class="stat-val c-yellow"><?= (int)$totalRetur ?></span>
                            <span class="stat-lbl">Voucher Retur</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
function GetVP(){
  var prof = document.getElementById('uprof').value;
  // Memuat data validitas harga via AJAX
  $("#GetValidPrice").load("./process/getvalidprice.php?name="+prof+"&session=<?= $session; ?> #getdata", function(res, status) {
      if (status == "success") {
         // Callback sukses jika diperlukan
      }
  });
} 

function updateTimeLimit() {
    var prof = document.getElementById('uprof').value;
    var timeField = document.getElementById('timelimit');
    
    // Set text display field
    if (prof === '10Menit') {
        timeField.value = '10 Menit';
    } else if (prof === '30Menit') {
        timeField.value = '30 Menit';
    } else {
        timeField.value = '-';
    }
}

function validateForm() {
    var qty = document.getElementById('qtyInput').value;
    if (qty < 50) { 
        alert("PERHATIAN: Minimal generate harus 50 user sesuai aturan sistem!"); 
        document.getElementById('qtyInput').focus();
        return false; 
    }
    // Tampilkan loader saat submit
    document.getElementById('loader').style.display = 'inline-block';
    return true;
}

// Inisialisasi saat load
$(document).ready(function() {
    updateTimeLimit();
    GetVP();
});
</script>