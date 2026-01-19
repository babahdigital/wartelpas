<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * SECURITY UPGRADE: Anti-CSRF, Anti-Bot, & Silent Defense
 * Code Owner: Pak Dul (WartelPas)
 * REBUILD STYLE: Matching user.php dark theme (2026)
 */
// Cek session start, jika belum aktif (misal akses langsung), start session.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Sembunyikan error
error_reporting(0);
ini_set('max_execution_time', 300);

// --- 1. GENERATE CSRF TOKEN (PENGAMAN FORMULIR) ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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
        $ip = '';
        $mac = '';
        if (!empty($comment)) {
            if (preg_match('/\bIP\s*:\s*([^|\s]+)/i', $comment, $m)) {
                $ip = trim($m[1]);
            }
            if (preg_match('/\bMAC\s*:\s*([^|\s]+)/i', $comment, $m)) {
                $mac = trim($m[1]);
            }
        }
        return ['ip' => $ip, 'mac' => $mac];
    }
}

$session = isset($_GET['session']) ? $_GET['session'] : '';

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit();
} else {
    // Set Timezone
    date_default_timezone_set($_SESSION['timezone']);
    $genprof = isset($_GET['genprof']) ? $_GET['genprof'] : "";

    // --- LOGIC DETAIL PROFIL (Visual Only) ---
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
            $ValidPrice = "<span><b>Validity :</b> " . $getvalid . "</span> | <span><b>Price :</b> " . $getprice . "</span> | <span><b>Lock User :</b> " . $getlocku . "</span>";
        }
    }

    // --- PROSES GENERATE USER (DENGAN SECURITY LAYER) ---
    if (isset($_POST['qty'])) {
        
        // --- SECURITY CHECK 1: CSRF TOKEN VALIDATION ---
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
             echo "<script>window.location.href='./error.php';</script>";
             exit();
        }

        // --- SECURITY CHECK 2: RATE LIMITING (ANTI-BOT) ---
        if (isset($_SESSION['last_gen_time']) && (time() - $_SESSION['last_gen_time'] < 5)) {
             echo "<script>window.location='./?hotspot-user=generate&session=" . $session . "'</script>";
             exit();
        }
        $_SESSION['last_gen_time'] = time();

        // AMBIL VARIABEL
        $qty = (int)$_POST['qty']; 
        $adcomment = isset($_POST['adcomment']) ? trim($_POST['adcomment']) : "";
        $profile = ($_POST['profile']);
        $userl = ($_POST['userl']);
        $prefix = isset($_POST['prefix']) ? $_POST['prefix'] : ""; 

        // =======================================================================
        // SECURITY CHECKPOINT (SILENT MODE)
        // =======================================================================
        $server = "wartel";       
        $user   = "vc";          
        $char   = "mix";          
        $mbgb   = 1048576;        
        $datalimit = 0;           

        $violation = false;
        if ($qty < 50) { $violation = true; }
        if (substr($adcomment, 0, 5) !== 'Blok-') { $violation = true; }
        $allowed_profiles = ['10Menit', '30Menit'];
        if (!in_array($profile, $allowed_profiles)) { $violation = true; }

        if ($violation) {
             echo "<script>window.location.href='./error.php';</script>";
             exit();
        }

        if ($profile == '10Menit') {
            $timelimit = "10m";
        } elseif ($profile == '30Menit') {
            $timelimit = "30m";
        } else {
            $timelimit = "0"; 
        }

        // --- END SECURITY CHECKPOINT ---
        
        $getprofile = $API->comm("/ip/hotspot/user/profile/print", array("?name" => "$profile"));
        $ponlogin = $getprofile[0]['on-login'];
        $getvalid = explode(",", $ponlogin)[3];
        $getprice = explode(",", $ponlogin)[2];
        $getsprice = explode(",", $ponlogin)[4];
        $getlock = explode(",", $ponlogin)[6];
        
        $_SESSION['ubp'] = $profile;
        
        $commt = $user . "-" . rand(100, 999) . "-" . date("m.d.y") . "-" . $adcomment;
        
        $gentemp = $commt . "|~" . $profile . "~" . $getvalid . "~" . $getprice . "!".$getsprice."~" . $timelimit . "~" . $datalimit . "~" . $getlock;
        $gen = '<?php $genu="'.encrypt($gentemp).'";?>';
        $temp = './voucher/temp.php';
        $handle = fopen($temp, 'w') or die('Cannot open file:  ' . $temp);
        fwrite($handle, $gen);
        fclose($handle);

        $u = array();
        for ($i = 1; $i <= $qty; $i++) {
            $p[$i] = randNLC($userl); 
            $u[$i] = "$prefix$p[$i]";
        }

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

    // --- VISUALISASI HASIL ---
    $getprofile = $API->comm("/ip/hotspot/user/profile/print");
    
    if(file_exists('./voucher/temp.php')){
        include_once('./voucher/temp.php');
        if(isset($genu)){
            $decrypted_genu = decrypt($genu);
            $genuser = explode("-", $decrypted_genu);
            $genuser1 = explode("~", $decrypted_genu);
            
            if(count($genuser) > 1 && count($genuser1) > 1){
                $ucode = $genuser[1];
                $udate = $genuser[2];
                $uprofile = $genuser1[1];
                $uvalid = $genuser1[2];
                $uprice = explode("!",$genuser1[3])[0];
                $suprice = isset(explode("!",$genuser1[3])[1]) ? explode("!",$genuser1[3])[1] : "-";
                $utlimit = $genuser1[4];
                $udlimit = $genuser1[5];
                $ulock = isset($genuser1[6]) ? $genuser1[6] : "-";
                
                $urlprint = explode("|", $decrypted_genu)[0];

                if ($currency == in_array($currency, $cekindo['indo'])) {
                    $uprice = (is_numeric($uprice)) ? $currency . " " . number_format((float)$uprice, 0, ",", ".") : $uprice;
                    $suprice = (is_numeric($suprice)) ? $currency . " " . number_format((float)$suprice, 0, ",", ".") : $suprice;
                } else {
                    $uprice = (is_numeric($uprice)) ? $currency . " " . number_format((float)$uprice) : $uprice;
                    $suprice = (is_numeric($suprice)) ? $currency . " " . number_format((float)$suprice) : $suprice;
                }
            }
        }
    }

    // --- RINGKASAN SISA VOUCHER PER BLOK + TOTAL RETUR/RUSAK ---
    $blockSummary = [];
    $totalRusak = 0;
    $totalRetur = 0;
    $totalReady = 0;

    $active_list = $API->comm('/ip/hotspot/active/print', [
        '?server' => 'wartel',
        '.proplist' => 'user,uptime,bytes-in,bytes-out,address,mac-address'
    ]);
    $activeMap = [];
    foreach ($active_list as $a) {
        if (isset($a['user'])) $activeMap[$a['user']] = $a;
    }

    $all_users = $API->comm('/ip/hotspot/user/print', [
        '?server' => 'wartel',
        '.proplist' => '.id,name,comment,disabled,bytes-in,bytes-out,uptime'
    ]);

    foreach ($all_users as $u) {
        $name = $u['name'] ?? '';
        $comment = $u['comment'] ?? '';
        $disabled = $u['disabled'] ?? 'false';
        $is_active = $name !== '' && isset($activeMap[$name]);

        $bytes_total = (int)($u['bytes-in'] ?? 0) + (int)($u['bytes-out'] ?? 0);
        $bytes_active = 0;
        if ($is_active) {
            $bytes_active = (int)($activeMap[$name]['bytes-in'] ?? 0) + (int)($activeMap[$name]['bytes-out'] ?? 0);
        }
        $bytes = max($bytes_total, $bytes_active);

        $uptime_user = $u['uptime'] ?? '';
        $uptime_active = $is_active ? ($activeMap[$name]['uptime'] ?? '') : '';
        $uptime = $uptime_user !== '' ? $uptime_user : $uptime_active;

        $cm = extract_ip_mac_from_comment($comment);
        $is_rusak = (stripos($comment, 'RUSAK') !== false) || ($disabled === 'true');
        $is_retur = (stripos($comment, '(Retur)') !== false) || (stripos($comment, 'Retur Ref:') !== false);
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
                $totalReady++;
            }
        }
    }

    if (!empty($blockSummary)) {
        ksort($blockSummary, SORT_NATURAL | SORT_FLAG_CASE);
    }
}
?>

<style>
    /* Mengadopsi variabel warna dari user.php untuk konsistensi */
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

    /* Base Styles for this page */
    .gen-page { color: var(--txt-main); }
    .gen-page .text-muted { color: var(--txt-muted) !important; }
    .gen-page .text-danger { color: var(--c-red) !important; }

    /* Card Styles - Solid Dark Theme */
    .gen-page .card-solid {
        background: var(--dark-card);
        color: var(--txt-main);
        border: none;
        box-shadow: 0 4px 10px rgba(0,0,0,0.3);
        border-radius: 8px;
        margin-bottom: 20px;
    }
    .gen-page .card-header-solid {
        background: #23272b;
        padding: 15px 20px;
        border-bottom: 2px solid var(--border-col);
        border-radius: 8px 8px 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .gen-page .card-title { margin: 0; font-weight: 600; font-size: 1.25rem; }

    /* Form Controls */
    .gen-page .form-control, .gen-page select.form-control {
        background: var(--input-bg);
        border: 1px solid var(--border-col);
        color: var(--txt-main);
        border-radius: 4px;
        padding: 8px 12px;
        height: calc(2.25rem + 2px); /* Standar tinggi bootstrap */
    }
    .gen-page .form-control:focus {
        background: #3b444b;
        border-color: var(--c-blue);
        box-shadow: none;
        color: var(--txt-main);
    }
    .gen-page .form-control::placeholder { color: var(--txt-muted); }

    /* Locked/Readonly Fields - Tampil elegan, bukan dashed */
    .gen-page .locked-field {
        background-color: #2c3138 !important; /* Sedikit lebih gelap dari input biasa */
        color: var(--txt-muted) !important; /* Teks agak redup */
        cursor: not-allowed;
        font-weight: 600;
        opacity: 1; /* Override bootstrap disabled opacity */
    }

    /* Table Styles - Meniru gaya user.php */
    .table-dark-solid { width: 100%; border-collapse: separate; border-spacing: 0; }
    .table-dark-solid th, .table-dark-solid td {
        padding: 12px 15px; /* Padding lebih lega */
        vertical-align: middle;
        border-bottom: 1px solid #3a4046;
        color: var(--txt-main);
    }
    .table-dark-solid thead th {
        background: #1b1e21;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: var(--txt-muted);
        border-bottom: 2px solid var(--border-col);
        font-weight: 600;
    }
    .table-hover tbody tr:hover { background-color: #32383e; }

    /* Label kolom kiri di form */
    .form-label-td {
        font-weight: 600;
        width: 30%;
        white-space: nowrap;
    }

    /* Buttons */
    .gen-page .btn { border-radius: 4px; font-weight: 600; padding: 8px 16px; transition: all 0.2s; border: none;}
    .gen-page .btn-primary-dark { background: var(--c-blue); color: white; }
    .gen-page .btn-primary-dark:hover { background: #2980b9; }
    .gen-page .btn-warning-dark { background: var(--c-orange); color: white; }
    .gen-page .btn-warning-dark:hover { background: #e67e22; }
    .gen-page .btn-pink-dark { background: #e84393; color: white; } /* Warna pink custom */
    .gen-page .btn-pink-dark:hover { background: #d63031; }

    /* Misc */
    .valid-price-info { padding: 15px; background: rgba(52, 152, 219, 0.1); border-radius: 4px; border-left: 4px solid var(--c-blue); }
    #loader { margin-left: 10px; color: var(--c-blue); }
</style>

<div class="gen-page">
    <div class="row">
        <div class="col-lg-8 col-md-12 mb-4">
            <div class="card card-solid">
                <div class="card-header-solid">
                    <h3 class="card-title"><i class="fa fa-user-plus mr-2"></i> <?= $_generate_user ?></h3>
                    <small id="loader" style="display: none;"><i><i class='fa fa-circle-o-notch fa-spin'></i> <?= $_processing ?> </i></small>
                </div>
                <div class="card-body p-0"> <form autocomplete="off" method="post" action="./?hotspot-user=generate&session=<?= $session; ?>" class="m-0">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="session" value="<?= $session; ?>">

                        <div class="p-3" style="border-bottom: 1px solid var(--border-col); background: rgba(0,0,0,0.1);">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <?php if (isset($_SESSION['ubp']) && $_SESSION['ubp'] != "") {
                                        echo "<a class='btn btn-warning-dark mr-2' href='./?hotspot=users&profile=" . $_SESSION['ubp'] . "&session=" . $session . "'> <i class='fa fa-times'></i> ".$_close."</a>";
                                    } else {
                                        echo "<a class='btn btn-warning-dark mr-2' href='./?hotspot=users&profile=all&session=" . $session . "'> <i class='fa fa-times'></i> ".$_close."</a>";
                                    }
                                    ?>
                                    <a class="btn btn-pink-dark" title="Lihat User per Profile" href="./?hotspot=users&profile=<?php echo isset($uprofile) ? $uprofile : "all"; ?>&session=<?= $session; ?>"> <i class="fa fa-users"></i> <?= $_user_list ?></a>
                                </div>
                                <div>
                                     <button type="submit" name="save" onclick="return validateForm()" class="btn btn-primary-dark" title="Generate User"> <i class="fa fa-save mr-1"></i> <?= $_generate ?></button>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-dark-solid table-hover m-0">
                                <tbody>
                                    <tr>
                                        <td class="form-label-td"><?= $_qty ?></td>
                                        <td>
                                            <div class="input-group">
                                                <input class="form-control" type="number" id="qtyInput" name="qty" min="50" max="500" value="50" required="1" title="Minimal 50 User">
                                            </div>
                                            <small class="text-danger mt-1 d-block"><i class="fa fa-info-circle"></i> Minimal 50 User</small>
                                        </td>
                                    </tr>
                                    
                                    <tr>
                                        <td class="form-label-td">Server</td>
                                        <td>
                                            <input type="text" class="form-control locked-field" value="wartel" readonly>
                                        </td>
                                    </tr>

                                    <tr>
                                        <td class="form-label-td"><?= $_user_mode ?></td>
                                        <td>
                                            <input type="text" class="form-control locked-field" value="Username = Password" readonly>
                                            <input type="hidden" name="user" value="vc">
                                        </td>
                                    </tr>

                                    <tr>
                                        <td class="form-label-td"><?= $_user_length ?></td>
                                        <td>
                                            <select class="form-control" id="userl" name="userl" required="1">
                                                <option value="6">6 Digit</option>
                                                <option value="7">7 Digit</option>
                                                <option value="8">8 Digit</option>
                                            </select>
                                        </td>
                                    </tr>
                                    
                                    <tr>
                                        <td class="form-label-td"><?= $_profile ?></td>
                                        <td>
                                            <select class="form-control" onchange="GetVP(); updateTimeLimit();" id="uprof" name="profile" required="1">
                                                <?php 
                                                $allowedProfiles = ['10Menit', '30Menit'];
                                                if ($genprof != "" && in_array($genprof, $allowedProfiles)) {
                                                    echo "<option>" . $genprof . "</option>";
                                                }
                                                $TotalReg = count($getprofile);
                                                for ($i = 0; $i < $TotalReg; $i++) {
                                                    $pName = $getprofile[$i]['name'];
                                                    if(in_array($pName, $allowedProfiles) && $pName != $genprof){
                                                        echo "<option>" . $pName . "</option>";
                                                    }
                                                }
                                                ?>
                                            </select>
                                        </td>
                                    </tr>

                                    <tr>
                                        <td class="form-label-td"><?= $_time_limit ?></td>
                                        <td>
                                            <input class="form-control locked-field" type="text" autocomplete="off" name="timelimit_display" id="timelimit" value="" readonly>
                                        </td>
                                    </tr>

                                    <tr>
                                        <td class="form-label-td"><?= $_comment ?> (Blok)</td>
                                        <td>
                                            <select class="form-control" name="adcomment" id="comment" required="1">
                                                <?php
                                                $blocks = range('A', 'F');
                                                $suffixes = ['10', '30'];
                                                foreach($blocks as $blk) {
                                                    foreach($suffixes as $suf) {
                                                        echo "<option value='Blok-$blk$suf'>Blok-$blk$suf</option>";
                                                    }
                                                }
                                                ?>
                                            </select>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                         <div id="GetValidPrice" class="p-3">
                            <?php if ($genprof != "" && isset($ValidPrice)) { ?>
                                <div class="valid-price-info">
                                    <?= $ValidPrice ?>
                                </div>
                            <?php } ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-md-12">
            <div class="card card-solid">
                <div class="card-header-solid">
                    <h3 class="card-title"><i class="fa fa-ticket mr-2"></i> Ringkasan Voucher</h3>
                </div>
                <div class="card-body p-0">
                    <?php if (!empty($blockSummary)): ?>
                        <div class="p-3 font-weight-bold" style="border-bottom: 1px solid var(--border-col); background: rgba(0,0,0,0.1);">
                            Sisa Voucher per Blok (READY)
                        </div>
                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-dark-solid table-hover m-0 table-sm">
                                <thead>
                                    <tr>
                                        <th style="position: sticky; top: 0; z-index: 1;">Blok</th>
                                        <th style="position: sticky; top: 0; z-index: 1;" class="text-right">Jumlah</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($blockSummary as $blok => $count): ?>
                                        <tr>
                                            <td><span class="badge badge-dark border border-secondary p-1"><?= htmlspecialchars($blok) ?></span></td>
                                            <td class="text-right font-weight-bold" style="color: var(--c-green);"><?= (int)$count ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center p-4 text-muted">
                            <i class="fa fa-inbox fa-3x mb-3" style="opacity: 0.5;"></i><br>
                            Tidak ada voucher READY.
                        </div>
                    <?php endif; ?>

                    <div class="p-3" style="border-top: 2px solid var(--border-col); background: rgba(0,0,0,0.15);">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Total Rusak:</span>
                            <span class="badge badge-danger p-2" style="font-size: 1rem; background: var(--c-red);"><?= (int)$totalRusak ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Total Retur:</span>
                            <span class="badge badge-warning p-2" style="font-size: 1rem; background: var(--c-orange); color: white;"><?= (int)$totalRetur ?></span>
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
  // Menggunakan container baru untuk load konten
  $("#GetValidPrice").load("./process/getvalidprice.php?name="+prof+"&session=<?= $session; ?> #getdata", function(response, status, xhr) {
      if (status == "success") {
          // Membungkus hasil load dengan style info jika berhasil
          var content = $(this).html();
          if(content.trim() !== ""){
               $(this).html('<div class="valid-price-info">' + content + '</div>');
          }
      }
  });
} 

function updateTimeLimit() {
    var prof = document.getElementById('uprof').value;
    var timeField = document.getElementById('timelimit');
    
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
        return false;
    }
    // Tampilkan loader hanya jika validasi lolos
    document.getElementById('loader').style.display = 'inline-block';
    return true;
}

$(document).ready(function() {
    updateTimeLimit();
    GetVP();
});
</script>