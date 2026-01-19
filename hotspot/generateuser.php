<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * SECURITY UPGRADE: Anti-CSRF, Anti-Bot, & Silent Defense
 * Code Owner: Pak Dul (WartelPas)
 * REBUILD STYLE: Full Width Responsive Grid & Dark Theme (2026)
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
                          "<div class='v-item'><i class='fa fa-clock-o'></i> Valid: <b>" . $getvalid . "</b></div>" .
                          "<div class='v-item'><i class='fa fa-tag'></i> Price: <b>" . $getprice . "</b></div>" .
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
    /* VARIABLES */
    :root {
        --dark-bg: #121212;
        --dark-card: #1e1e1e;
        --input-bg: #2d2d2d;
        --border-col: #333333;
        --txt-main: #e0e0e0;
        --txt-muted: #a0a0a0;
        --accent-blue: #3a86ff;
        --accent-green: #00b894;
        --accent-red: #ff7675;
        --accent-yellow: #fdcb6e;
    }

    /* CARD STYLES */
    .gen-page .card-modern {
        background: var(--dark-card);
        color: var(--txt-main);
        border: 1px solid var(--border-col);
        box-shadow: 0 4px 10px rgba(0,0,0,0.3);
        border-radius: 8px;
        margin-bottom: 20px;
    }

    .gen-page .card-header-modern {
        background: rgba(255,255,255,0.03);
        padding: 15px 20px;
        border-bottom: 1px solid var(--border-col);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .gen-page .card-title { margin: 0; font-weight: 600; font-size: 1.2rem; }

    .gen-page .card-body-modern {
        padding: 20px;
    }

    /* FORM STYLES */
    .gen-page .form-label {
        font-weight: 500;
        color: var(--txt-muted);
        margin-bottom: 8px;
        display: block;
    }
    .gen-page .form-control {
        background: var(--input-bg);
        border: 1px solid var(--border-col);
        color: var(--txt-main);
        height: 45px; /* Lebih tinggi agar mudah diklik */
        padding: 10px 15px;
        border-radius: 6px;
    }
    .gen-page .form-control:focus {
        background: #363636;
        border-color: var(--accent-blue);
        color: #fff;
        box-shadow: none;
    }
    .gen-page .locked-field {
        background-color: #252525 !important;
        color: #666 !important;
        cursor: not-allowed;
    }

    /* INFO BOX */
    .info-valid-box {
        background: rgba(58, 134, 255, 0.1);
        border-left: 4px solid var(--accent-blue);
        padding: 15px;
        margin-top: 15px;
        border-radius: 4px;
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
    }
    .v-item { color: var(--txt-main); font-size: 0.95rem; }
    .v-item i { color: var(--accent-blue); margin-right: 5px; }

    /* SUMMARY GRID (DYNAMIC LAYOUT) */
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); /* Dinamis mengisi lebar */
        gap: 15px;
    }
    
    .blok-card {
        background: #252525;
        border: 1px solid var(--border-col);
        border-radius: 8px;
        padding: 15px;
        text-align: center;
        transition: transform 0.2s;
        position: relative;
        overflow: hidden;
    }
    .blok-card:hover {
        transform: translateY(-3px);
        border-color: var(--accent-green);
    }
    .blok-card::before {
        content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 3px;
        background: var(--accent-green);
    }
    .blok-name { display: block; font-size: 0.9rem; color: var(--txt-muted); margin-bottom: 5px; text-transform: uppercase; }
    .blok-count { display: block; font-size: 1.8rem; font-weight: 700; color: var(--accent-green); }

    /* BUTTONS */
    .btn-action {
        width: 100%;
        padding: 12px;
        font-size: 1.1rem;
        font-weight: bold;
        text-transform: uppercase;
        border: none;
        border-radius: 6px;
        background: var(--accent-blue);
        color: #fff;
        cursor: pointer;
        transition: background 0.3s;
        margin-top: 20px;
    }
    .btn-action:hover { background: #217dbb; }

    /* FOOTER STATS */
    .footer-stats {
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px solid var(--border-col);
        display: flex;
        justify-content: space-around;
        text-align: center;
    }
    .stat-val { font-size: 1.5rem; font-weight: bold; display: block; }
    .stat-lbl { font-size: 0.85rem; color: var(--txt-muted); }
    .c-red { color: var(--accent-red); }
    .c-yellow { color: var(--accent-yellow); }
</style>

<div class="gen-page container-fluid p-0">
    <div class="row">
        
        <div class="col-12">
            <div class="card card-modern">
                <div class="card-header-modern">
                    <h3 class="card-title"><i class="fa fa-cogs mr-2"></i> <?= $_generate_user ?></h3>
                    <small id="loader" style="display: none;" class="text-info"><i class='fa fa-circle-o-notch fa-spin'></i> Memproses...</small>
                </div>
                
                <div class="card-body-modern">
                    <form autocomplete="off" method="post" action="./?hotspot-user=generate&session=<?= $session; ?>">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="session" value="<?= $session; ?>">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?= $_qty ?> (Jumlah)</label>
                                <input class="form-control" type="number" id="qtyInput" name="qty" min="50" max="500" value="50" required placeholder="Min 50">
                                <small class="text-danger">*Minimal 50 User</small>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Server / Mode</label>
                                <div class="d-flex gap-2">
                                    <input type="text" class="form-control locked-field mr-2" value="wartel" readonly style="flex:1;">
                                    <input type="text" class="form-control locked-field" value="User=Pass" readonly style="flex:1;">
                                    <input type="hidden" name="user" value="vc">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?= $_user_length ?></label>
                                <select class="form-control" id="userl" name="userl" required>
                                    <option value="6">6 Digit</option>
                                    <option value="7">7 Digit</option>
                                    <option value="8">8 Digit</option>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?= $_profile ?></label>
                                <select class="form-control" onchange="GetVP(); updateTimeLimit();" id="uprof" name="profile" required>
                                    <?php 
                                    $allowedProfiles = ['10Menit', '30Menit'];
                                    if ($genprof != "" && in_array($genprof, $allowedProfiles)) echo "<option selected>" . $genprof . "</option>";
                                    foreach ($getprofile as $p) {
                                        $pName = $p['name'];
                                        if(in_array($pName, $allowedProfiles) && $pName != $genprof) echo "<option>" . $pName . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?= $_time_limit ?></label>
                                <input class="form-control locked-field" type="text" name="timelimit_display" id="timelimit" readonly>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?= $_comment ?> (Kode Blok)</label>
                                <select class="form-control" name="adcomment" id="comment" required style="font-family: monospace; font-size: 1.1rem;">
                                    <?php
                                    foreach(range('A', 'F') as $blk) {
                                        foreach(['10', '30'] as $suf) echo "<option value='Blok-$blk$suf'>Blok-$blk$suf</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <div id="GetValidPrice">
                            <?php if ($genprof != "" && isset($ValidPrice)) echo $ValidPrice; ?>
                        </div>

                        <button type="submit" name="save" onclick="return validateForm()" class="btn-action">
                            <i class="fa fa-save mr-2"></i> GENERATE VOUCHER
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card card-modern">
                <div class="card-header-modern">
                    <h3 class="card-title"><i class="fa fa-th-large mr-2"></i> Ringkasan Voucher (READY)</h3>
                </div>
                
                <div class="card-body-modern">
                    <?php if (!empty($blockSummary)): ?>
                        <div class="summary-grid">
                            <?php foreach ($blockSummary as $blok => $count): ?>
                                <div class="blok-card">
                                    <span class="blok-name"><?= htmlspecialchars($blok) ?></span>
                                    <span class="blok-count"><?= (int)$count ?></span>
                                    <small class="text-muted">pcs</small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center p-5 text-muted" style="background: rgba(0,0,0,0.1); border-radius: 8px;">
                            <i class="fa fa-inbox fa-3x mb-3" style="opacity: 0.3;"></i><br>
                            <h4>Stok Kosong</h4>
                            <small>Tidak ada voucher dengan status READY.</small>
                        </div>
                    <?php endif; ?>

                    <div class="footer-stats">
                        <div>
                            <span class="stat-val c-red"><?= (int)$totalRusak ?></span>
                            <span class="stat-lbl">Total Rusak</span>
                        </div>
                        <div>
                            <span class="stat-val c-yellow"><?= (int)$totalRetur ?></span>
                            <span class="stat-lbl">Total Retur</span>
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
  // Memuat data validitas harga
  $("#GetValidPrice").load("./process/getvalidprice.php?name="+prof+"&session=<?= $session; ?> #getdata", function(res, status) {
      if (status == "success") {
          // Optional: manipulasi DOM jika perlu merapikan hasil dari process/getvalidprice.php
      }
  });
} 

function updateTimeLimit() {
    var prof = document.getElementById('uprof').value;
    var timeField = document.getElementById('timelimit');
    // Logika display timelimit client-side
    if (prof === '10Menit') {
        timeField.value = '10m';
    } else if (prof === '30Menit') {
        timeField.value = '30m';
    } else {
        timeField.value = '-';
    }
}

function validateForm() {
    var qty = document.getElementById('qtyInput').value;
    if (qty < 50) { 
        alert("Minimal generate harus 50 user!"); 
        document.getElementById('qtyInput').focus();
        return false; 
    }
    document.getElementById('loader').style.display = 'inline-block';
    return true;
}

$(document).ready(function() {
    updateTimeLimit();
    GetVP();
});
</script>