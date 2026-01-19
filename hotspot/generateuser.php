<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * SECURITY UPGRADE: Anti-CSRF, Anti-Bot, & Silent Defense
 * Code Owner: Pak Dul (WartelPas)
 * REBUILD STYLE: Responsive Fix for Laptop & Mobile (2026)
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
            $ValidPrice = "<div class='d-flex flex-wrap gap-2 align-items-center justify-content-between text-small'>" .
                          "<span><i class='fa fa-clock-o text-muted'></i> <b>Valid:</b> " . $getvalid . "</span>" .
                          "<span><i class='fa fa-tag text-muted'></i> <b>Price:</b> " . $getprice . "</span>" .
                          "<span><i class='fa fa-lock text-muted'></i> <b>Lock:</b> " . $getlocku . "</span></div>";
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
    $totalReady = 0;

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
        --dark-bg: #1e2226;
        --dark-card: #2a3036;
        --input-bg: #343a40;
        --border-col: #495057;
        --txt-main: #ecf0f1;
        --txt-muted: #adb5bd;
        --c-blue: #3498db;
        --c-green: #2ecc71;
        --c-orange: #f39c12;
        --c-red: #e74c3c;
    }

    /* CARD STYLES */
    .gen-page .card-solid {
        background: var(--dark-card);
        color: var(--txt-main);
        border: none;
        box-shadow: 0 4px 10px rgba(0,0,0,0.3);
        border-radius: 8px;
        display: flex;
        flex-direction: column;
        height: 100%; /* Agar tinggi card sama */
        overflow: hidden;
    }

    .gen-page .card-header-solid {
        background: #23272b;
        padding: 12px 20px;
        border-bottom: 2px solid var(--border-col);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-shrink: 0;
    }
    .gen-page .card-title { margin: 0; font-weight: 600; font-size: 1.1rem; }

    /* CARD BODY & SCROLLING */
    .gen-page .card-body {
        padding: 0;
        flex-grow: 1;
        display: flex;
        flex-direction: column;
        min-height: 0; /* Penting untuk nested scroll flexbox */
    }
    
    .gen-page .scrollable-content {
        flex-grow: 1;
        overflow-y: auto;
        padding: 0;
    }
    
    /* FORM STYLES */
    .gen-page .form-control {
        background: var(--input-bg);
        border: 1px solid var(--border-col);
        color: var(--txt-main);
        height: calc(2.25rem + 2px);
    }
    .gen-page .form-control:focus {
        background: #3b444b;
        border-color: var(--c-blue);
        color: var(--txt-main);
        box-shadow: none;
    }
    .gen-page .locked-field {
        background-color: #2c3138 !important;
        color: var(--txt-muted) !important;
        cursor: not-allowed;
        border: 1px solid rgba(73, 80, 87, 0.5);
    }

    /* TABLE STYLES */
    .table-dark-solid { width: 100%; margin: 0; }
    .table-dark-solid td, .table-dark-solid th {
        padding: 12px 15px;
        border-bottom: 1px solid #3a4046;
        vertical-align: middle;
        color: var(--txt-main);
    }
    .table-dark-solid tr:last-child td { border-bottom: none; }
    .form-label-td { width: 35%; font-weight: 600; white-space: nowrap; color: var(--txt-muted); font-size: 0.9rem; }
    
    /* BUTTONS */
    .btn-custom { border-radius: 4px; font-weight: 600; padding: 6px 14px; border: none; font-size: 0.9rem; }
    .btn-blue { background: var(--c-blue); color: #fff; }
    .btn-blue:hover { background: #2980b9; }
    .btn-warn { background: var(--c-orange); color: #fff; }
    .btn-warn:hover { background: #e67e22; }
    .btn-pink { background: #e84393; color: #fff; }
    .btn-pink:hover { background: #d63031; }

    /* FOOTER SUMMARY */
    .summary-footer {
        background: rgba(0,0,0,0.2);
        border-top: 1px solid var(--border-col);
        padding: 15px;
        flex-shrink: 0;
    }
    
    /* SCROLLBAR CUSTOM */
    .scrollable-content::-webkit-scrollbar { width: 8px; }
    .scrollable-content::-webkit-scrollbar-track { background: #2a3036; }
    .scrollable-content::-webkit-scrollbar-thumb { background: #495057; border-radius: 4px; }
    .scrollable-content::-webkit-scrollbar-thumb:hover { background: #6c757d; }
    
    .valid-price-container {
        background: rgba(52, 152, 219, 0.1);
        border-left: 3px solid var(--c-blue);
        padding: 10px 15px;
        margin-top: auto; /* Push to bottom of form card if needed */
    }
</style>

<div class="gen-page container-fluid p-0"> <div class="row row-eq-height"> <div class="col-xl-8 col-lg-7 col-md-12 mb-3">
            <div class="card card-solid">
                <div class="card-header-solid">
                    <h3 class="card-title"><i class="fa fa-user-plus mr-2"></i> <?= $_generate_user ?></h3>
                    <small id="loader" style="display: none;" class="text-info"><i class='fa fa-circle-o-notch fa-spin'></i> Processing...</small>
                </div>
                
                <div class="card-body">
                    <form autocomplete="off" method="post" action="./?hotspot-user=generate&session=<?= $session; ?>" class="d-flex flex-column h-100 m-0">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="session" value="<?= $session; ?>">

                        <div class="p-3 border-bottom border-secondary d-flex flex-wrap gap-2 justify-content-between align-items-center bg-dark-soft">
                            <div>
                                <?php $back_link = "./?hotspot=users&profile=" . (isset($_SESSION['ubp']) && $_SESSION['ubp'] != "" ? $_SESSION['ubp'] : "all") . "&session=" . $session; ?>
                                <a class="btn btn-custom btn-warn mr-1" href="<?= $back_link ?>"><i class="fa fa-times"></i> <?= $_close ?></a>
                                <a class="btn btn-custom btn-pink" href="./?hotspot=users&profile=<?php echo isset($uprofile) ? $uprofile : "all"; ?>&session=<?= $session; ?>"><i class="fa fa-users"></i> List</a>
                            </div>
                            <button type="submit" name="save" onclick="return validateForm()" class="btn btn-custom btn-blue"><i class="fa fa-save"></i> <?= $_generate ?></button>
                        </div>

                        <div class="scrollable-content">
                            <table class="table table-dark-solid">
                                <tr>
                                    <td class="form-label-td"><?= $_qty ?></td>
                                    <td>
                                        <input class="form-control" type="number" id="qtyInput" name="qty" min="50" max="500" value="50" required>
                                        <small class="text-danger mt-1 d-block" style="font-size: 11px;">*Minimal 50 User</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="form-label-td">Server / Mode</td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <input type="text" class="form-control locked-field mr-1" value="wartel" readonly>
                                            <input type="text" class="form-control locked-field" value="User=Pass" readonly>
                                            <input type="hidden" name="user" value="vc">
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="form-label-td"><?= $_user_length ?></td>
                                    <td>
                                        <select class="form-control" id="userl" name="userl" required>
                                            <option value="6">6 Digit</option>
                                            <option value="7">7 Digit</option>
                                            <option value="8">8 Digit</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="form-label-td"><?= $_profile ?></td>
                                    <td>
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
                                    </td>
                                </tr>
                                <tr>
                                    <td class="form-label-td"><?= $_time_limit ?></td>
                                    <td><input class="form-control locked-field" type="text" name="timelimit_display" id="timelimit" readonly></td>
                                </tr>
                                <tr>
                                    <td class="form-label-td"><?= $_comment ?> (Blok)</td>
                                    <td>
                                        <select class="form-control" name="adcomment" id="comment" required>
                                            <?php
                                            foreach(range('A', 'F') as $blk) {
                                                foreach(['10', '30'] as $suf) echo "<option value='Blok-$blk$suf'>Blok-$blk$suf</option>";
                                            }
                                            ?>
                                        </select>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div id="GetValidPrice" class="valid-price-container">
                            <?php if ($genprof != "" && isset($ValidPrice)) echo $ValidPrice; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-lg-5 col-md-12 mb-3">
            <div class="card card-solid">
                <div class="card-header-solid">
                    <h3 class="card-title"><i class="fa fa-ticket mr-2"></i> Ringkasan Voucher</h3>
                </div>
                
                <div class="card-body">
                    <div style="padding: 10px 15px; border-bottom: 1px solid #3a4046; background: rgba(0,0,0,0.1); font-weight: bold; font-size: 0.85rem; color: var(--txt-muted);">
                        <div class="d-flex justify-content-between">
                            <span>BLOK (READY)</span>
                            <span>JUMLAH</span>
                        </div>
                    </div>

                    <div class="scrollable-content">
                        <?php if (!empty($blockSummary)): ?>
                            <table class="table table-dark-solid table-sm table-hover m-0">
                                <tbody>
                                    <?php foreach ($blockSummary as $blok => $count): ?>
                                        <tr>
                                            <td style="padding-left: 20px;"><span class="badge badge-dark border border-secondary"><?= htmlspecialchars($blok) ?></span></td>
                                            <td class="text-right font-weight-bold" style="color: var(--c-green); padding-right: 20px;"><?= (int)$count ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="text-center p-5 text-muted">
                                <i class="fa fa-inbox fa-3x mb-3" style="opacity: 0.3;"></i><br>
                                <small>Tidak ada voucher READY.</small>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="summary-footer">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted text-sm"><i class="fa fa-ban text-danger"></i> Total Rusak</span>
                            <span class="badge badge-danger"><?= (int)$totalRusak ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted text-sm"><i class="fa fa-refresh text-warning"></i> Total Retur</span>
                            <span class="badge badge-warning text-white"><?= (int)$totalRetur ?></span>
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
  $("#GetValidPrice").load("./process/getvalidprice.php?name="+prof+"&session=<?= $session; ?> #getdata", function(res, status) {
      if (status == "success") {
          var content = $(this).html();
          // Formatting ulang hasil load agar sesuai style baru
          if(content.trim() !== ""){
             // Kita biarkan structure PHP process merender text, container CSS yang mengatur padding
          }
      }
  });
} 

function updateTimeLimit() {
    var prof = document.getElementById('uprof').value;
    var timeField = document.getElementById('timelimit');
    timeField.value = (prof === '10Menit') ? '10m' : ((prof === '30Menit') ? '30m' : '-');
}

function validateForm() {
    var qty = document.getElementById('qtyInput').value;
    if (qty < 50) { alert("Minimal generate harus 50 user!"); return false; }
    document.getElementById('loader').style.display = 'inline-block';
    return true;
}

$(document).ready(function() {
    updateTimeLimit();
    GetVP();
});
</script>